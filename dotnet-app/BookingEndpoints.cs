using System.Globalization;
using System.Net.Mail;
using System.Security.Cryptography;
using System.Text.RegularExpressions;
using Microsoft.AspNetCore.Authentication;
using MySqlConnector;

internal static partial class BookingEndpoints
{
    private const decimal ReservationFee = 250m;
    private const int SlotMinutes = 60;
    private const int SlotCapacity = 2;

    public static void MapBookingEndpoints(this WebApplication app)
    {
        app.MapGet("/api/public/booking/services", GetServicesAsync);
        app.MapGet("/api/public/booking/slots", GetSlotsAsync);
        app.MapPost("/api/public/bookings", CreateBookingAsync);
    }

    private static async Task<IResult> GetServicesAsync(
        int vehicleId,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (vehicleId < 1)
        {
            return Results.BadRequest(new { status = "failed", msg = "Select a valid vehicle type." });
        }

        try
        {
            await using var connection = await OpenManilaConnectionAsync(dataSource, cancellationToken);
            await using (var vehicleCommand = new MySqlCommand(
                "SELECT 1 FROM vehicle_list WHERE id = @vehicleId AND status = 1 AND delete_flag = 0 LIMIT 1",
                connection))
            {
                vehicleCommand.Parameters.AddWithValue("@vehicleId", vehicleId);
                if (await vehicleCommand.ExecuteScalarAsync(cancellationToken) is null)
                {
                    return Results.BadRequest(new { status = "failed", msg = "The selected vehicle type is unavailable." });
                }
            }

            const string sql = """
                SELECT s.id, s.name, p.price, s.duration_minutes
                FROM service_list s
                LEFT JOIN price_list p ON p.service_id = s.id AND p.vehicle_id = @vehicleId
                WHERE s.status = 1 AND s.delete_flag = 0
                ORDER BY CASE s.name WHEN 'Car Wash' THEN 0 WHEN 'Car Repaint' THEN 1 ELSE 2 END, s.name ASC
                """;
            await using var command = new MySqlCommand(sql, connection);
            command.Parameters.AddWithValue("@vehicleId", vehicleId);
            await using var reader = await command.ExecuteReaderAsync(cancellationToken);
            var services = new List<BookingServiceResponse>();
            while (await reader.ReadAsync(cancellationToken))
            {
                decimal? price = reader.IsDBNull(reader.GetOrdinal("price"))
                    ? null
                    : Convert.ToDecimal(reader.GetValue(reader.GetOrdinal("price")), CultureInfo.InvariantCulture);
                var name = reader.GetString("name");
                var duration = reader.GetInt32("duration_minutes");
                services.Add(new BookingServiceResponse(
                    reader.GetInt32("id"),
                    name,
                    price,
                    price?.ToString("N2", CultureInfo.InvariantCulture),
                    GetEstimatedDuration(name, duration),
                    duration));
            }

            return Results.Ok(new { status = "success", data = services });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not load booking services for vehicle {VehicleId}.", vehicleId);
            return Results.Problem(
                title: "Vehicle prices are temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> GetSlotsAsync(
        string? date,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!DateOnly.TryParseExact(date, "yyyy-MM-dd", CultureInfo.InvariantCulture, DateTimeStyles.None, out var selectedDate))
        {
            return Results.Ok(new { status = "success", data = Array.Empty<SlotResponse>() });
        }

        var now = ManilaNow();
        if (selectedDate < DateOnly.FromDateTime(now))
        {
            return Results.Ok(new { status = "success", data = Array.Empty<SlotResponse>() });
        }

        var opening = selectedDate.ToDateTime(new TimeOnly(7, 0));
        var closing = selectedDate.DayOfWeek is DayOfWeek.Saturday or DayOfWeek.Sunday
            ? selectedDate.AddDays(1).ToDateTime(TimeOnly.MinValue)
            : selectedDate.ToDateTime(new TimeOnly(23, 0));
        if (selectedDate == DateOnly.FromDateTime(now) && now >= closing)
        {
            return Results.Ok(new { status = "success", data = Array.Empty<SlotResponse>() });
        }

        try
        {
            await using var connection = await OpenManilaConnectionAsync(dataSource, cancellationToken);
            var dayStart = DateTime.SpecifyKind(selectedDate.ToDateTime(TimeOnly.MinValue), DateTimeKind.Unspecified);
            await using var command = new MySqlCommand("""
                SELECT schedule, COUNT(*) AS total
                FROM booking_list
                WHERE schedule >= @dayStart AND schedule < DATE_ADD(@dayStart, INTERVAL 1 DAY)
                  AND status <> 5 AND is_archived = 0
                GROUP BY schedule
                """, connection);
            command.Parameters.AddWithValue("@dayStart", dayStart);
            await using var reader = await command.ExecuteReaderAsync(cancellationToken);
            var booked = new Dictionary<DateTime, int>();
            while (await reader.ReadAsync(cancellationToken))
            {
                booked[reader.GetDateTime("schedule")] = reader.GetInt32("total");
            }

            var slots = new List<SlotResponse>();
            for (var slot = opening; slot < closing; slot = slot.AddMinutes(SlotMinutes))
            {
                booked.TryGetValue(slot, out var occupied);
                slots.Add(new SlotResponse(
                    slot.ToString("HH:mm", CultureInfo.InvariantCulture),
                    Math.Max(0, SlotCapacity - occupied),
                    selectedDate == DateOnly.FromDateTime(now) && slot < now));
            }

            return Results.Ok(new { status = "success", data = slots });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not load booking availability for {Date}.", date);
            return Results.Problem(
                title: "Appointment availability is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> CreateBookingAsync(
        CreateBookingRequest? request,
        HttpContext context,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        var customerAuthentication = await context.AuthenticateAsync(CustomerEndpoints.AuthenticationScheme);
        int? customerId = null;
        if (customerAuthentication.Succeeded &&
            int.TryParse(
                customerAuthentication.Principal?.FindFirst(System.Security.Claims.ClaimTypes.NameIdentifier)?.Value,
                NumberStyles.None,
                CultureInfo.InvariantCulture,
                out var authenticatedCustomerId))
        {
            customerId = authenticatedCustomerId;
        }

        if (request is null)
        {
            return BookingError("Please enter valid booking details.");
        }

        var plateNumber = Regex.Replace(request.PlateNumber?.Trim().ToUpperInvariant() ?? string.Empty, @"\s+", " ");
        if (!PlateNumberRegex().IsMatch(plateNumber))
        {
            return BookingError("Enter a vehicle plate like AAA 0123, a motorcycle plate like AA 12345, a protocol number, or a 6-digit diplomatic plate.");
        }

        var clientName = request.ClientName?.Trim() ?? string.Empty;
        var contact = request.Contact?.Trim() ?? string.Empty;
        var email = request.Email?.Trim() ?? string.Empty;
        var address = request.Address?.Trim() ?? string.Empty;
        if (clientName.Length is 0 or > 250 ||
            address.Length is 0 or > 2000 ||
            !PhilippineContactRegex().IsMatch(contact) ||
            email.Length > 254 ||
            !MailAddress.TryCreate(email, out var parsedEmail) ||
            !string.Equals(parsedEmail.Address, email, StringComparison.OrdinalIgnoreCase))
        {
            return BookingError("Please enter valid booking and contact details.");
        }

        if (!DateOnly.TryParseExact(request.Schedule, "yyyy-MM-dd", CultureInfo.InvariantCulture, DateTimeStyles.None, out var date) ||
            !TimeOnly.TryParseExact(request.ScheduleTime, "HH:mm", CultureInfo.InvariantCulture, DateTimeStyles.None, out var time) ||
            date < DateOnly.FromDateTime(ManilaNow()))
        {
            return BookingError("Please choose a valid appointment date and a 1-hour appointment slot.");
        }

        var appointment = date.ToDateTime(time);
        var opening = date.ToDateTime(new TimeOnly(7, 0));
        var closing = date.DayOfWeek is DayOfWeek.Saturday or DayOfWeek.Sunday
            ? date.AddDays(1).ToDateTime(TimeOnly.MinValue)
            : date.ToDateTime(new TimeOnly(23, 0));
        var now = ManilaNow();
        if (appointment < opening || appointment >= closing ||
            (appointment - opening).TotalMinutes % SlotMinutes != 0 ||
            (date == DateOnly.FromDateTime(now) && appointment < now))
        {
            return BookingError("Please choose an available slot within the shop hours: Monday-Friday 7:00 AM-11:00 PM, Saturday-Sunday 7:00 AM-12:00 AM.");
        }

        if (request.VehicleId is null or < 1 || request.ServiceIds is not { Length: > 0 } ||
            request.ServiceIds.Length > 100 || request.ServiceIds.Any(id => id < 1))
        {
            return BookingError("Please select a vehicle type and at least one service.");
        }

        var serviceIds = request.ServiceIds.Distinct().Order().ToArray();
        var schedule = DateTime.SpecifyKind(appointment, DateTimeKind.Unspecified);
        var monthlyLock = $"ocwbs_code_{date:yyyyMM}";
        var slotLock = $"ocwbs_{Convert.ToHexString(SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(schedule.ToString("yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture))))[..32].ToLowerInvariant()}";
        var locksAcquired = new List<string>(2);
        MySqlConnection? bookingConnection = null;

        try
        {
            bookingConnection = await OpenManilaConnectionAsync(dataSource, cancellationToken);
            var connection = bookingConnection;
            if (!await AcquireLockAsync(connection, monthlyLock, cancellationToken))
            {
                return BookingError("Booking is busy. Please try again.");
            }

            locksAcquired.Add(monthlyLock);
            if (!await AcquireLockAsync(connection, slotLock, cancellationToken))
            {
                return BookingError("This appointment slot is being booked. Please try again.");
            }

            locksAcquired.Add(slotLock);
            await using var transaction = await connection.BeginTransactionAsync(cancellationToken);

            string vehicleName;
            await using (var vehicleCommand = new MySqlCommand(
                "SELECT name FROM vehicle_list WHERE id = @vehicleId AND status = 1 AND delete_flag = 0 LIMIT 1",
                connection, transaction))
            {
                vehicleCommand.Parameters.AddWithValue("@vehicleId", request.VehicleId.Value);
                var result = await vehicleCommand.ExecuteScalarAsync(cancellationToken);
                if (result is null)
                {
                    await transaction.RollbackAsync(cancellationToken);
                    return BookingError("The selected vehicle type is not available.");
                }

                vehicleName = Convert.ToString(result, CultureInfo.InvariantCulture)!;
            }

            var placeholders = serviceIds.Select((_, index) => $"@service{index}").ToArray();
            var priceSql = $"""
                SELECT s.id, s.name, p.price, s.duration_minutes
                FROM price_list p
                INNER JOIN service_list s ON s.id = p.service_id
                WHERE p.vehicle_id = @vehicleId AND s.status = 1 AND s.delete_flag = 0
                  AND s.id IN ({string.Join(", ", placeholders)})
                """;
            var selectedServices = new List<SelectedBookingService>();
            await using (var priceCommand = new MySqlCommand(priceSql, connection, transaction))
            {
                priceCommand.Parameters.AddWithValue("@vehicleId", request.VehicleId.Value);
                for (var index = 0; index < serviceIds.Length; index++)
                {
                    priceCommand.Parameters.AddWithValue(placeholders[index], serviceIds[index]);
                }

                await using var reader = await priceCommand.ExecuteReaderAsync(cancellationToken);
                while (await reader.ReadAsync(cancellationToken))
                {
                    var servicePrice = Convert.ToDecimal(reader.GetValue(reader.GetOrdinal("price")), CultureInfo.InvariantCulture);
                    selectedServices.Add(new SelectedBookingService(
                        reader.GetInt32("id"),
                        reader.GetString("name"),
                        decimal.Round(servicePrice, 2, MidpointRounding.AwayFromZero),
                        reader.GetInt32("duration_minutes")));
                }
            }

            if (selectedServices.Count != serviceIds.Length)
            {
                await transaction.RollbackAsync(cancellationToken);
                return BookingError("One or more selected services are not available for this vehicle.");
            }

            var subtotal = selectedServices.Sum(service => service.Price);
            if (subtotal <= 0)
            {
                await transaction.RollbackAsync(cancellationToken);
                return BookingError("The booking total must be greater than zero.");
            }

            await using (var capacityCommand = new MySqlCommand(
                "SELECT COUNT(*) FROM booking_list WHERE schedule = @schedule AND status <> 5 AND is_archived = 0",
                connection, transaction))
            {
                capacityCommand.Parameters.AddWithValue("@schedule", schedule);
                var count = Convert.ToInt32(await capacityCommand.ExecuteScalarAsync(cancellationToken), CultureInfo.InvariantCulture);
                if (count >= SlotCapacity)
                {
                    await transaction.RollbackAsync(cancellationToken);
                    return BookingError("That appointment slot is full. Please select another available time.");
                }
            }

            var code = await NextBookingCodeAsync(connection, transaction, date, cancellationToken);
            var receiptToken = Convert.ToHexString(RandomNumberGenerator.GetBytes(32)).ToLowerInvariant();
            var total = subtotal + ReservationFee;
            long bookingId;
            const string insertSql = """
                INSERT INTO booking_list
                    (code, client_name, contact, plate_number, email, address, vehicle_id, schedule,
                     total_amount, reservation_fee, reservation_fee_payment_status, status, payment_status, receipt_pdf_token,
                     customer_id)
                VALUES
                    (@code, @clientName, @contact, @plateNumber, @email, @address, @vehicleId, @schedule,
                     @total, @reservationFee, 'not_required', 0, 'unpaid', @receiptToken, @customerId)
                """;
            await using (var insert = new MySqlCommand(insertSql, connection, transaction))
            {
                insert.Parameters.AddWithValue("@code", code);
                insert.Parameters.AddWithValue("@clientName", clientName);
                insert.Parameters.AddWithValue("@contact", contact);
                insert.Parameters.AddWithValue("@plateNumber", plateNumber);
                insert.Parameters.AddWithValue("@email", email);
                insert.Parameters.AddWithValue("@address", address);
                insert.Parameters.AddWithValue("@vehicleId", request.VehicleId.Value);
                insert.Parameters.AddWithValue("@schedule", schedule);
                insert.Parameters.AddWithValue("@total", total);
                insert.Parameters.AddWithValue("@reservationFee", ReservationFee);
                insert.Parameters.AddWithValue("@receiptToken", receiptToken);
                insert.Parameters.Add("@customerId", MySqlDbType.Int32).Value =
                    customerId.HasValue ? customerId.Value : DBNull.Value;
                await insert.ExecuteNonQueryAsync(cancellationToken);
                bookingId = insert.LastInsertedId;
            }

            const string serviceInsertSql = """
                INSERT INTO booking_services (booking_id, service_id, price, duration_minutes)
                VALUES (@bookingId, @serviceId, @price, @duration)
                """;
            foreach (var service in selectedServices)
            {
                await using var serviceInsert = new MySqlCommand(serviceInsertSql, connection, transaction);
                serviceInsert.Parameters.AddWithValue("@bookingId", bookingId);
                serviceInsert.Parameters.AddWithValue("@serviceId", service.Id);
                serviceInsert.Parameters.AddWithValue("@price", service.Price);
                serviceInsert.Parameters.AddWithValue("@duration", service.DurationMinutes);
                await serviceInsert.ExecuteNonQueryAsync(cancellationToken);
            }

            await transaction.CommitAsync(cancellationToken);
            var receipt = new BookingReceipt(
                code,
                clientName,
                contact,
                plateNumber,
                vehicleName,
                appointment.ToString("MMM dd, yyyy h:mm tt", CultureInfo.InvariantCulture),
                selectedServices.Select(service => new BookingReceiptService(
                    service.Name,
                    service.Price.ToString("N2", CultureInfo.InvariantCulture))).ToArray(),
                subtotal.ToString("N2", CultureInfo.InvariantCulture),
                ReservationFee.ToString("N2", CultureInfo.InvariantCulture),
                total.ToString("N2", CultureInfo.InvariantCulture),
                receiptToken);
            return Results.Ok(new
            {
                status = "success",
                bid = bookingId,
                code,
                msg = $"Booking submitted successfully. Booking reference: {code}. Please pay at the shop cashier.",
                receipt
            });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Booking submission failed for schedule {Schedule}.", schedule);
            return Results.Problem(
                title: "Your booking could not be saved. Please try again.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
        finally
        {
            if (bookingConnection is not null)
            {
                foreach (var lockName in locksAcquired.AsEnumerable().Reverse())
                {
                    try
                    {
                        await ReleaseLockAsync(bookingConnection, lockName, CancellationToken.None);
                    }
                    catch (MySqlException exception)
                    {
                        logger.LogError(exception, "Could not release booking lock {LockName}.", lockName);
                    }
                }

                await bookingConnection.DisposeAsync();
            }
        }
    }

    private static IResult BookingError(string message) =>
        Results.BadRequest(new { status = "failed", msg = message });

    private static async Task<MySqlConnection> OpenManilaConnectionAsync(
        MySqlDataSource dataSource,
        CancellationToken cancellationToken)
    {
        var connection = await dataSource.OpenConnectionAsync(cancellationToken);
        await using var command = new MySqlCommand("SET time_zone = '+08:00'", connection);
        await command.ExecuteNonQueryAsync(cancellationToken);
        return connection;
    }

    private static async Task<bool> AcquireLockAsync(
        MySqlConnection connection,
        string lockName,
        CancellationToken cancellationToken)
    {
        await using var command = new MySqlCommand("SELECT GET_LOCK(@lockName, 5)", connection);
        command.Parameters.AddWithValue("@lockName", lockName);
        return Convert.ToInt32(await command.ExecuteScalarAsync(cancellationToken), CultureInfo.InvariantCulture) == 1;
    }

    private static async Task ReleaseLockAsync(
        MySqlConnection connection,
        string lockName,
        CancellationToken cancellationToken)
    {
        await using var command = new MySqlCommand("SELECT RELEASE_LOCK(@lockName)", connection);
        command.Parameters.AddWithValue("@lockName", lockName);
        await command.ExecuteScalarAsync(cancellationToken);
    }

    private static async Task<string> NextBookingCodeAsync(
        MySqlConnection connection,
        MySqlTransaction transaction,
        DateOnly date,
        CancellationToken cancellationToken)
    {
        var prefix = date.ToString("yyyyMM", CultureInfo.InvariantCulture);
        await using var command = new MySqlCommand(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(code, 7) AS UNSIGNED)), 0) + 1 FROM booking_list WHERE code LIKE @prefix",
            connection,
            transaction);
        command.Parameters.AddWithValue("@prefix", prefix + "%");
        var next = Convert.ToInt32(await command.ExecuteScalarAsync(cancellationToken), CultureInfo.InvariantCulture);
        if (next is < 1 or > 99999)
        {
            throw new InvalidOperationException($"Booking code sequence is exhausted for {prefix}.");
        }

        return prefix + next.ToString("D5", CultureInfo.InvariantCulture);
    }

    private static DateTime ManilaNow() => DateTime.UtcNow.AddHours(8);

    private static string GetEstimatedDuration(string name, int minutes)
    {
        var normalizedName = name.Trim().ToLowerInvariant();
        return normalizedName switch
        {
            "car wash" => "30-45 minutes",
            "engine wash" => "30-60 minutes",
            "under wash" or "underwash" => "30-45 minutes",
            "undercoat" => "1-3 hours",
            "detailing" or "car detailing" => "2-5 hours",
            "ceramic" or "ceramic coating" => "3-8 hours",
            "painting" or "car painting" => "1-3 days",
            _ => FormatDuration(minutes)
        };
    }

    private static string FormatDuration(int minutes)
    {
        if (minutes >= 1440 && minutes % 1440 == 0)
        {
            var days = minutes / 1440;
            return $"{days} {(days == 1 ? "day" : "days")}";
        }

        if (minutes >= 60 && minutes % 60 == 0)
        {
            var hours = minutes / 60;
            return $"{hours} {(hours == 1 ? "hour" : "hours")}";
        }

        return minutes >= 60
            ? $"{minutes / 60} hours {minutes % 60} minutes"
            : $"{minutes} minutes";
    }

    [GeneratedRegex(@"^(?:[A-Z]{3} ?[0-9]{4}|[A-Z]{2} ?[0-9]{5}|[0-9]{1,6})$", RegexOptions.CultureInvariant)]
    private static partial Regex PlateNumberRegex();

    [GeneratedRegex(@"^0[0-9]{10}$", RegexOptions.CultureInvariant)]
    private static partial Regex PhilippineContactRegex();
}

internal sealed record CreateBookingRequest(
    string? ClientName,
    string? Contact,
    string? PlateNumber,
    string? Email,
    string? Address,
    int? VehicleId,
    string? Schedule,
    string? ScheduleTime,
    int[]? ServiceIds);

internal sealed record BookingServiceResponse(
    int Id,
    string Name,
    decimal? Price,
    string? FormattedPrice,
    string EstimatedDuration,
    int DurationMinutes);

internal sealed record SlotResponse(string Time, int Available, bool Past);
internal sealed record SelectedBookingService(int Id, string Name, decimal Price, int DurationMinutes);

internal sealed record BookingReceipt(
    string Code,
    string ClientName,
    string Contact,
    string PlateNumber,
    string Vehicle,
    string Schedule,
    IReadOnlyList<BookingReceiptService> Services,
    string FormattedSubtotal,
    string FormattedReservationFee,
    string FormattedTotal,
    string PdfToken);

internal sealed record BookingReceiptService(string Name, string FormattedPrice);
