using System.Globalization;
using System.Security.Claims;
using System.Security.Cryptography;
using System.Text;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Antiforgery;
using MySqlConnector;

internal static class AdminEndpoints
{
    public static void MapAdminEndpoints(this WebApplication app)
    {
        app.MapGet("/api/admin/csrf", (HttpContext context, IAntiforgery antiforgery) =>
        {
            var tokens = antiforgery.GetAndStoreTokens(context);
            return Results.Ok(new { token = tokens.RequestToken });
        })
            .WithName("GetAdminCsrfToken")
            .WithSummary("Issue a request-verification token for admin forms");

        app.MapGet("/api/admin/session", (HttpContext context) =>
        {
            if (context.User.Identity?.IsAuthenticated != true)
            {
                return Results.Ok(new { authenticated = false });
            }

            return Results.Ok(new
            {
                authenticated = true,
                name = context.User.Identity.Name,
                role = context.User.FindFirstValue(ClaimTypes.Role)
            });
        })
            .WithName("GetAdminSession")
            .WithSummary("Return the current admin session");

        app.MapPost("/api/admin/login", LoginAsync)
            .WithName("AdminLogin")
            .WithSummary("Sign in an administrator or staff member");

        var admin = app.MapGroup("/api/admin").RequireAuthorization();

        admin.MapPost("/logout", async (HttpContext context) =>
        {
            await context.SignOutAsync(CookieAuthenticationDefaults.AuthenticationScheme);
            return Results.NoContent();
        })
            .WithName("AdminLogout")
            .WithSummary("Sign out the current administrator");

        admin.MapGet("/summary", GetSummaryAsync)
            .WithName("GetAdminSummary")
            .WithSummary("Get booking and payment overview counts");

        admin.MapGet("/bookings", GetBookingsAsync)
            .WithName("GetAdminBookings")
            .WithSummary("List recent and archived bookings");

        admin.MapPost("/bookings/advance-due", AdvanceDueBookingsAsync)
            .WithName("AdvanceDueBookings")
            .WithSummary("Automatically progress paid bookings that are due");

        admin.MapPost("/bookings/{bookingId:int}/status", UpdateBookingStatusAsync)
            .WithName("UpdateAdminBookingStatus")
            .WithSummary("Confirm or cancel a booking");

        admin.MapPost("/bookings/{bookingId:int}/archive", ArchiveBookingAsync)
            .WithName("ArchiveAdminBooking")
            .WithSummary("Move a booking to the archive");

        admin.MapPost("/bookings/{bookingId:int}/restore", RestoreBookingAsync)
            .WithName("RestoreAdminBooking")
            .WithSummary("Restore an archived booking if capacity permits");

        admin.MapGet("/catalog", GetCatalogAsync)
            .WithName("GetAdminCatalog")
            .WithSummary("List service, vehicle, and price records");

        admin.MapPost("/services", SaveServiceAsync)
            .WithName("SaveAdminService")
            .WithSummary("Create or update a service");

        admin.MapPost("/services/{serviceId:int}/archive", ArchiveServiceAsync)
            .WithName("ArchiveAdminService")
            .WithSummary("Archive a service without removing booking history");

        admin.MapPost("/vehicles", SaveVehicleAsync)
            .WithName("SaveAdminVehicle")
            .WithSummary("Create or update a vehicle type");

        admin.MapPost("/vehicles/{vehicleId:int}/archive", ArchiveVehicleAsync)
            .WithName("ArchiveAdminVehicle")
            .WithSummary("Archive a vehicle type");

        admin.MapPost("/prices", SavePriceAsync)
            .WithName("SaveAdminPrice")
            .WithSummary("Set the price for a vehicle and service");
    }

    private static async Task<IResult> LoginAsync(
        AdminLoginRequest request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!await antiforgery.IsRequestValidAsync(context))
        {
            return Results.BadRequest(new { message = "Refresh the page and try again." });
        }

        var username = request.Username?.Trim() ?? string.Empty;
        var password = request.Password ?? string.Empty;
        if (username.Length is 0 or > 250 || password.Length is 0 or > 1024)
        {
            return Results.BadRequest(new { message = "Enter your username and password." });
        }

        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var command = new MySqlCommand(
                "SELECT id, firstname, lastname, password, type FROM users WHERE username = @username LIMIT 1",
                connection);
            command.Parameters.AddWithValue("@username", username);
            await using var reader = await command.ExecuteReaderAsync(cancellationToken);
            if (!await reader.ReadAsync(cancellationToken))
            {
                return Results.Unauthorized();
            }

            var id = reader.GetInt32("id");
            var firstName = reader.GetString("firstname");
            var lastName = reader.GetString("lastname");
            var storedPassword = reader.GetString("password");
            var roleId = reader.GetInt32("type");
            if (roleId is not (1 or 2) || !VerifyPassword(password, storedPassword))
            {
                return Results.Unauthorized();
            }

            var displayName = $"{firstName} {lastName}".Trim();
            var role = roleId == 1 ? "admin" : "staff";
            var claims = new[]
            {
                new Claim(ClaimTypes.NameIdentifier, id.ToString(CultureInfo.InvariantCulture)),
                new Claim(ClaimTypes.Name, displayName),
                new Claim(ClaimTypes.Role, role)
            };
            await context.SignInAsync(
                CookieAuthenticationDefaults.AuthenticationScheme,
                new ClaimsPrincipal(new ClaimsIdentity(claims, CookieAuthenticationDefaults.AuthenticationScheme)),
                new AuthenticationProperties { IsPersistent = false, AllowRefresh = true });
            return Results.Ok(new { authenticated = true, name = displayName, role });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Administrator login database query failed.");
            return Results.Problem(
                title: "Sign-in is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static bool VerifyPassword(string password, string storedPassword)
    {
        if (storedPassword.Length == 32 && storedPassword.All(Uri.IsHexDigit))
        {
            var actual = Convert.ToHexStringLower(
                System.Security.Cryptography.MD5.HashData(System.Text.Encoding.UTF8.GetBytes(password)));
            return System.Security.Cryptography.CryptographicOperations.FixedTimeEquals(
                System.Text.Encoding.ASCII.GetBytes(actual),
                System.Text.Encoding.ASCII.GetBytes(storedPassword.ToLowerInvariant()));
        }

        if (System.Text.RegularExpressions.Regex.IsMatch(
            storedPassword,
            @"^\$2[aby]\$\d{2}\$[./A-Za-z0-9]{53}$",
            System.Text.RegularExpressions.RegexOptions.CultureInvariant))
        {
            var compatibleHash = storedPassword.StartsWith("$2y$", StringComparison.Ordinal)
                ? "$2a$" + storedPassword[4..]
                : storedPassword;
            return BCrypt.Net.BCrypt.Verify(password, compatibleHash);
        }

        return false;
    }

    private static async Task<IResult> GetSummaryAsync(
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var command = new MySqlCommand("""
                SELECT
                    COUNT(*) AS total,
                    COALESCE(SUM(status = 0 AND is_archived = 0), 0) AS pending,
                    COALESCE(SUM(status IN (1, 2, 3) AND is_archived = 0), 0) AS active,
                    COALESCE(SUM(status = 4 AND is_archived = 0), 0) AS completed,
                    COALESCE(SUM(payment_status = 'unpaid' AND is_archived = 0), 0) AS unpaid
                FROM booking_list
                """, connection);
            await using var reader = await command.ExecuteReaderAsync(cancellationToken);
            await reader.ReadAsync(cancellationToken);
            return Results.Ok(new AdminSummaryResponse(
                reader.GetInt32("total"),
                reader.GetInt32("pending"),
                reader.GetInt32("active"),
                reader.GetInt32("completed"),
                reader.GetInt32("unpaid")));
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not load the admin dashboard summary.");
            return Results.Problem(
                title: "Dashboard data is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> GetBookingsAsync(
        bool archived,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var command = new MySqlCommand("""
                SELECT b.id, b.code, b.client_name, b.contact, b.email, b.plate_number,
                       v.name AS vehicle, b.schedule, b.total_amount, b.status,
                       b.payment_status, b.reservation_fee_payment_status, b.is_archived
                FROM booking_list b
                INNER JOIN vehicle_list v ON v.id = b.vehicle_id
                WHERE b.is_archived = @archived
                ORDER BY b.schedule DESC, b.id DESC
                LIMIT 250
                """, connection);
            command.Parameters.AddWithValue("@archived", archived ? 1 : 0);
            await using var reader = await command.ExecuteReaderAsync(cancellationToken);
            var bookings = new List<AdminBookingResponse>();
            while (await reader.ReadAsync(cancellationToken))
            {
                var schedule = DateTime.SpecifyKind(reader.GetDateTime("schedule"), DateTimeKind.Unspecified);
                bookings.Add(new AdminBookingResponse(
                    reader.GetInt32("id"),
                    reader.GetString("code"),
                    reader.GetString("client_name"),
                    reader.GetString("contact"),
                    reader.GetString("email"),
                    reader.GetString("plate_number"),
                    reader.GetString("vehicle"),
                    new DateTimeOffset(schedule, TimeSpan.FromHours(8)),
                    reader.GetDecimal("total_amount"),
                    reader.GetInt32("status"),
                    reader.GetString("payment_status"),
                    reader.GetString("reservation_fee_payment_status"),
                    reader.GetBoolean("is_archived")));
            }

            return Results.Ok(bookings);
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not load admin bookings.");
            return Results.Problem(
                title: "Bookings are temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> AdvanceDueBookingsAsync(
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        var role = context.User.FindFirstValue(ClaimTypes.Role);
        if (role is not ("admin" or "staff"))
        {
            return Results.Forbid();
        }

        if (!await antiforgery.IsRequestValidAsync(context))
        {
            return Results.BadRequest(new { message = "Refresh the page and try again." });
        }

        MySqlConnection? connection = null;
        var lockAcquired = false;
        try
        {
            connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using (var lockCommand = new MySqlCommand(
                "SELECT GET_LOCK('ocwbs:booking-progression', 5)",
                connection))
            {
                lockAcquired = Convert.ToInt32(
                    await lockCommand.ExecuteScalarAsync(cancellationToken),
                    CultureInfo.InvariantCulture) == 1;
            }

            if (!lockAcquired)
            {
                return Results.Conflict(new { message = "Booking progress is being updated. Try again." });
            }

            await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
            var updated = 0;
            updated += await ExecuteProgressionUpdateAsync(
                connection,
                transaction,
                """
                UPDATE booking_list
                SET status = 1
                WHERE status = 0 AND payment_status = 'paid' AND is_archived = 0
                """,
                cancellationToken);
            updated += await ExecuteProgressionUpdateAsync(
                connection,
                transaction,
                """
                UPDATE booking_list
                SET status = 3, service_started_at = COALESCE(service_started_at, NOW())
                WHERE status = 2 AND payment_status = 'paid' AND is_archived = 0
                """,
                cancellationToken);
            updated += await ExecuteProgressionUpdateAsync(
                connection,
                transaction,
                """
                UPDATE booking_list
                SET service_started_at = NOW()
                WHERE status = 3 AND service_started_at IS NULL AND is_archived = 0
                """,
                cancellationToken);
            updated += await ExecuteProgressionUpdateAsync(
                connection,
                transaction,
                """
                UPDATE booking_list b
                INNER JOIN (
                    SELECT booking_id, SUM(duration_minutes) AS total_minutes
                    FROM booking_services
                    GROUP BY booking_id
                ) durations ON durations.booking_id = b.id
                SET b.status = 4
                WHERE b.status = 3
                  AND b.payment_status = 'paid'
                  AND b.is_archived = 0
                  AND b.service_started_at IS NOT NULL
                  AND TIMESTAMPADD(MINUTE, durations.total_minutes, b.service_started_at) <= NOW()
                """,
                cancellationToken);

            await using (var activeCommand = new MySqlCommand(
                "SELECT id FROM booking_list WHERE status IN (2, 3) AND is_archived = 0 LIMIT 1 FOR UPDATE",
                connection,
                transaction))
            {
                var activeBookingId = await activeCommand.ExecuteScalarAsync(cancellationToken);
                if (activeBookingId is null or DBNull)
                {
                    await using var nextCommand = new MySqlCommand(
                        """
                        SELECT id FROM booking_list
                        WHERE status = 1 AND payment_status = 'paid' AND schedule <= NOW() AND is_archived = 0
                        ORDER BY schedule ASC, id ASC
                        LIMIT 1 FOR UPDATE
                        """,
                        connection,
                        transaction);
                    var nextBookingId = await nextCommand.ExecuteScalarAsync(cancellationToken);
                    if (nextBookingId is not null and not DBNull)
                    {
                        await using var startCommand = new MySqlCommand(
                            """
                            UPDATE booking_list
                            SET status = 3, service_started_at = NOW()
                            WHERE id = @id AND status = 1 AND payment_status = 'paid' AND is_archived = 0
                            """,
                            connection,
                            transaction);
                        startCommand.Parameters.AddWithValue("@id", nextBookingId);
                        updated += await startCommand.ExecuteNonQueryAsync(cancellationToken);
                    }
                }
            }

            await transaction.CommitAsync(cancellationToken);
            return Results.Ok(new { updated });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not automatically progress due bookings.");
            return Results.Problem(
                title: "Due bookings could not be updated.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
        finally
        {
            if (connection is not null)
            {
                if (lockAcquired)
                {
                    await using var releaseLock = new MySqlCommand(
                        "SELECT RELEASE_LOCK('ocwbs:booking-progression')",
                        connection);
                    try
                    {
                        await releaseLock.ExecuteScalarAsync(CancellationToken.None);
                    }
                    catch (MySqlException exception)
                    {
                        logger.LogError(exception, "Could not release the booking progression lock.");
                    }
                }

                await connection.DisposeAsync();
            }
        }
    }

    private static async Task<int> ExecuteProgressionUpdateAsync(
        MySqlConnection connection,
        MySqlTransaction transaction,
        string sql,
        CancellationToken cancellationToken)
    {
        await using var command = new MySqlCommand(sql, connection, transaction);
        return await command.ExecuteNonQueryAsync(cancellationToken);
    }

    private static async Task<IResult> UpdateBookingStatusAsync(
        int bookingId,
        BookingStatusRequest request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!await antiforgery.IsRequestValidAsync(context))
        {
            return Results.BadRequest(new { message = "Refresh the page and try again." });
        }

        if (bookingId < 1 || request.Status is not (1 or 5))
        {
            return Results.BadRequest(new { message = "Choose a valid booking action." });
        }

        string? lockName = null;
        MySqlConnection? connection = null;
        try
        {
            connection = await dataSource.OpenConnectionAsync(cancellationToken);
            DateTime scheduleForLock;
            await using (var scheduleCommand = new MySqlCommand(
                "SELECT schedule FROM booking_list WHERE id = @id",
                connection))
            {
                scheduleCommand.Parameters.AddWithValue("@id", bookingId);
                var scheduleValue = await scheduleCommand.ExecuteScalarAsync(cancellationToken);
                if (scheduleValue is null)
                {
                    return Results.NotFound(new { message = "Booking not found." });
                }

                scheduleForLock = Convert.ToDateTime(scheduleValue, CultureInfo.InvariantCulture);
            }

            lockName = GetScheduleLockName(scheduleForLock);
            await using (var lockCommand = new MySqlCommand("SELECT GET_LOCK(@lockName, 5)", connection))
            {
                lockCommand.Parameters.AddWithValue("@lockName", lockName);
                if (Convert.ToInt32(await lockCommand.ExecuteScalarAsync(cancellationToken), CultureInfo.InvariantCulture) != 1)
                {
                    return Results.Conflict(new { message = "This appointment slot is being updated. Try again." });
                }
            }

            await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
            await using var bookingCommand = new MySqlCommand(
                "SELECT schedule, is_archived FROM booking_list WHERE id = @id FOR UPDATE",
                connection,
                transaction);
            bookingCommand.Parameters.AddWithValue("@id", bookingId);
            await using var reader = await bookingCommand.ExecuteReaderAsync(cancellationToken);
            if (!await reader.ReadAsync(cancellationToken))
            {
                return Results.NotFound(new { message = "Booking not found." });
            }

            var schedule = reader.GetDateTime("schedule");
            var isArchived = reader.GetBoolean("is_archived");
            await reader.CloseAsync();
            if (isArchived)
            {
                return Results.Conflict(new { message = "Restore this booking before changing its status." });
            }

            if (request.Status == 1)
            {
                await using var capacityCommand = new MySqlCommand(
                    "SELECT COUNT(*) FROM booking_list WHERE schedule = @schedule AND status <> 5 AND is_archived = 0 AND id <> @id",
                    connection,
                    transaction);
                capacityCommand.Parameters.AddWithValue("@schedule", schedule);
                capacityCommand.Parameters.AddWithValue("@id", bookingId);
                var activeBookings = Convert.ToInt32(
                    await capacityCommand.ExecuteScalarAsync(cancellationToken),
                    CultureInfo.InvariantCulture);
                if (activeBookings >= 2)
                {
                    return Results.Conflict(new { message = "The appointment slot is already at capacity." });
                }
            }

            await using var update = new MySqlCommand(
                "UPDATE booking_list SET status = @status WHERE id = @id",
                connection,
                transaction);
            update.Parameters.AddWithValue("@status", request.Status);
            update.Parameters.AddWithValue("@id", bookingId);
            await update.ExecuteNonQueryAsync(cancellationToken);
            await transaction.CommitAsync(cancellationToken);
            return Results.Ok(new { message = request.Status == 1 ? "Booking confirmed." : "Booking cancelled." });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not update status for booking {BookingId}.", bookingId);
            return Results.Problem(
                title: "Booking status could not be updated.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
        finally
        {
            if (connection is not null)
            {
                if (lockName is not null)
                {
                    try
                    {
                        await using var releaseLock = new MySqlCommand("SELECT RELEASE_LOCK(@lockName)", connection);
                        releaseLock.Parameters.AddWithValue("@lockName", lockName);
                        await releaseLock.ExecuteScalarAsync(CancellationToken.None);
                    }
                    catch (MySqlException exception)
                    {
                        logger.LogError(exception, "Could not release booking status lock {LockName}.", lockName);
                    }
                }

                await connection.DisposeAsync();
            }
        }
    }

    private static async Task<IResult> ArchiveBookingAsync(
        int bookingId,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (bookingId < 1)
        {
            return Results.BadRequest(new { message = "Choose a valid booking." });
        }

        if (!await antiforgery.IsRequestValidAsync(context))
        {
            return Results.BadRequest(new { message = "Refresh the page and try again." });
        }

        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var command = new MySqlCommand(
                "UPDATE booking_list SET is_archived = 1 WHERE id = @id AND is_archived = 0",
                connection);
            command.Parameters.AddWithValue("@id", bookingId);
            if (await command.ExecuteNonQueryAsync(cancellationToken) == 0)
            {
                return Results.NotFound(new { message = "Active booking not found." });
            }

            return Results.Ok(new { message = "Booking moved to the archive." });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not archive booking {BookingId}.", bookingId);
            return Results.Problem(
                title: "Booking could not be archived.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> RestoreBookingAsync(
        int bookingId,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (bookingId < 1)
        {
            return Results.BadRequest(new { message = "Choose a valid booking." });
        }

        if (!await antiforgery.IsRequestValidAsync(context))
        {
            return Results.BadRequest(new { message = "Refresh the page and try again." });
        }

        MySqlConnection? connection = null;
        string? lockName = null;
        try
        {
            connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
            DateTime schedule;
            int status;
            await using (var lookup = new MySqlCommand(
                "SELECT schedule, status FROM booking_list WHERE id = @id AND is_archived = 1 FOR UPDATE",
                connection,
                transaction))
            {
                lookup.Parameters.AddWithValue("@id", bookingId);
                await using var reader = await lookup.ExecuteReaderAsync(cancellationToken);
                if (!await reader.ReadAsync(cancellationToken))
                {
                    return Results.NotFound(new { message = "Archived booking not found." });
                }

                schedule = reader.GetDateTime("schedule");
                status = reader.GetInt32("status");
            }

            lockName = GetScheduleLockName(schedule);
            await using (var lockCommand = new MySqlCommand("SELECT GET_LOCK(@lockName, 5)", connection))
            {
                lockCommand.Parameters.AddWithValue("@lockName", lockName);
                if (Convert.ToInt32(await lockCommand.ExecuteScalarAsync(cancellationToken), CultureInfo.InvariantCulture) != 1)
                {
                    return Results.Conflict(new { message = "This appointment slot is being updated. Try again." });
                }
            }

            if (status != 5)
            {
                await using var capacityCommand = new MySqlCommand(
                    "SELECT COUNT(*) FROM booking_list WHERE schedule = @schedule AND status <> 5 AND is_archived = 0",
                    connection,
                    transaction);
                capacityCommand.Parameters.AddWithValue("@schedule", schedule);
                var activeBookings = Convert.ToInt32(
                    await capacityCommand.ExecuteScalarAsync(cancellationToken),
                    CultureInfo.InvariantCulture);
                if (activeBookings >= 2)
                {
                    return Results.Conflict(new { message = "The appointment slot is already at capacity." });
                }
            }

            if (status is 2 or 3)
            {
                await using var activeCommand = new MySqlCommand(
                    "SELECT COUNT(*) FROM booking_list WHERE status IN (2, 3) AND is_archived = 0",
                    connection,
                    transaction);
                var activeServices = Convert.ToInt32(
                    await activeCommand.ExecuteScalarAsync(cancellationToken),
                    CultureInfo.InvariantCulture);
                if (activeServices >= 1)
                {
                    return Results.Conflict(new { message = "An in-progress booking cannot be restored while another booking is in service." });
                }
            }

            await using var update = new MySqlCommand(
                "UPDATE booking_list SET is_archived = 0 WHERE id = @id AND is_archived = 1",
                connection,
                transaction);
            update.Parameters.AddWithValue("@id", bookingId);
            await update.ExecuteNonQueryAsync(cancellationToken);
            await transaction.CommitAsync(cancellationToken);
            return Results.Ok(new { message = "Booking restored." });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not restore booking {BookingId}.", bookingId);
            return Results.Problem(
                title: "Booking could not be restored.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
        finally
        {
            if (connection is not null)
            {
                if (lockName is not null)
                {
                    try
                    {
                        await using var releaseLock = new MySqlCommand("SELECT RELEASE_LOCK(@lockName)", connection);
                        releaseLock.Parameters.AddWithValue("@lockName", lockName);
                        await releaseLock.ExecuteScalarAsync(CancellationToken.None);
                    }
                    catch (MySqlException exception)
                    {
                        logger.LogError(exception, "Could not release booking restore lock {LockName}.", lockName);
                    }
                }

                await connection.DisposeAsync();
            }
        }
    }

    private static string GetScheduleLockName(DateTime schedule)
    {
        var scheduleText = schedule.ToString("yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture);
        var scheduleHash = Convert.ToHexStringLower(SHA256.HashData(Encoding.UTF8.GetBytes(scheduleText)))[..32];
        return $"ocwbs_{scheduleHash}";
    }

    private static async Task<IResult> GetCatalogAsync(
        HttpContext context,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsAdministrator(context))
        {
            return Results.Forbid();
        }

        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            var services = new List<AdminServiceResponse>();
            await using (var command = new MySqlCommand("""
                SELECT id, name, description, duration_minutes, status
                FROM service_list WHERE delete_flag = 0 ORDER BY name
                """, connection))
            await using (var reader = await command.ExecuteReaderAsync(cancellationToken))
            {
                while (await reader.ReadAsync(cancellationToken))
                {
                    services.Add(new AdminServiceResponse(
                        reader.GetInt32("id"),
                        reader.GetString("name"),
                        reader.GetString("description"),
                        reader.GetInt32("duration_minutes"),
                        reader.GetBoolean("status")));
                }
            }

            var vehicles = new List<AdminVehicleResponse>();
            await using (var command = new MySqlCommand("""
                SELECT id, name, status FROM vehicle_list WHERE delete_flag = 0 ORDER BY name
                """, connection))
            await using (var reader = await command.ExecuteReaderAsync(cancellationToken))
            {
                while (await reader.ReadAsync(cancellationToken))
                {
                    vehicles.Add(new AdminVehicleResponse(
                        reader.GetInt32("id"),
                        reader.GetString("name"),
                        reader.GetBoolean("status")));
                }
            }

            var prices = new List<AdminPriceResponse>();
            await using (var command = new MySqlCommand("""
                SELECT p.service_id, p.vehicle_id, p.price
                FROM price_list p
                INNER JOIN service_list s ON s.id = p.service_id AND s.delete_flag = 0
                INNER JOIN vehicle_list v ON v.id = p.vehicle_id AND v.delete_flag = 0
                ORDER BY p.service_id, p.vehicle_id
                """, connection))
            await using (var reader = await command.ExecuteReaderAsync(cancellationToken))
            {
                while (await reader.ReadAsync(cancellationToken))
                {
                    prices.Add(new AdminPriceResponse(
                        reader.GetInt32("service_id"),
                        reader.GetInt32("vehicle_id"),
                        reader.GetDecimal("price")));
                }
            }

            return Results.Ok(new AdminCatalogResponse(services, vehicles, prices));
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not load the admin catalog.");
            return Results.Problem(
                title: "Catalog data is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> SaveServiceAsync(
        AdminServiceRequest request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsAdministrator(context))
        {
            return Results.Forbid();
        }

        if (!await antiforgery.IsRequestValidAsync(context))
        {
            return Results.BadRequest(new { message = "Refresh the page and try again." });
        }

        var name = request.Name?.Trim() ?? string.Empty;
        var description = request.Description?.Trim() ?? string.Empty;
        if (request.Id < 0 || name.Length is 0 or > 250 ||
            description.Length > 4000 || request.DurationMinutes is < 1 or > 4320)
        {
            return Results.BadRequest(new { message = "Check the service name, description, and duration." });
        }

        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var duplicate = new MySqlCommand(
                "SELECT id FROM service_list WHERE name = @name AND id <> @id AND delete_flag = 0 LIMIT 1",
                connection);
            duplicate.Parameters.AddWithValue("@name", name);
            duplicate.Parameters.AddWithValue("@id", request.Id);
            if (await duplicate.ExecuteScalarAsync(cancellationToken) is not null)
            {
                return Results.Conflict(new { message = "A service with that name already exists." });
            }

            if (request.Id == 0)
            {
                await using var insert = new MySqlCommand("""
                    INSERT INTO service_list (name, description, duration_minutes, status, delete_flag)
                    VALUES (@name, @description, @duration, @status, 0)
                    """, connection);
                AddServiceParameters(insert, name, description, request);
                await insert.ExecuteNonQueryAsync(cancellationToken);
            }
            else
            {
                await using var update = new MySqlCommand("""
                    UPDATE service_list
                    SET name = @name, description = @description, duration_minutes = @duration, status = @status
                    WHERE id = @id AND delete_flag = 0
                    """, connection);
                AddServiceParameters(update, name, description, request);
                update.Parameters.AddWithValue("@id", request.Id);
                if (await update.ExecuteNonQueryAsync(cancellationToken) == 0)
                {
                    return Results.NotFound(new { message = "Service not found." });
                }
            }

            return Results.Ok(new { message = "Service saved." });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not save service {ServiceId}.", request.Id);
            return Results.Problem(
                title: "Service could not be saved.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static void AddServiceParameters(
        MySqlCommand command,
        string name,
        string description,
        AdminServiceRequest request)
    {
        command.Parameters.AddWithValue("@name", name);
        command.Parameters.AddWithValue("@description", description);
        command.Parameters.AddWithValue("@duration", request.DurationMinutes);
        command.Parameters.AddWithValue("@status", request.IsActive ? 1 : 0);
    }

    private static async Task<IResult> ArchiveServiceAsync(
        int serviceId,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsAdministrator(context))
        {
            return Results.Forbid();
        }

        if (!await antiforgery.IsRequestValidAsync(context))
        {
            return Results.BadRequest(new { message = "Refresh the page and try again." });
        }

        return await SetArchivedAsync("service_list", serviceId, dataSource, logger, cancellationToken);
    }

    private static async Task<IResult> SaveVehicleAsync(
        AdminVehicleRequest request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsAdministrator(context))
        {
            return Results.Forbid();
        }

        if (!await antiforgery.IsRequestValidAsync(context))
        {
            return Results.BadRequest(new { message = "Refresh the page and try again." });
        }

        var name = request.Name?.Trim() ?? string.Empty;
        if (request.Id < 0 || name.Length is 0 or > 250)
        {
            return Results.BadRequest(new { message = "Enter a valid vehicle type name." });
        }

        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var duplicate = new MySqlCommand(
                "SELECT id FROM vehicle_list WHERE name = @name AND id <> @id AND delete_flag = 0 LIMIT 1",
                connection);
            duplicate.Parameters.AddWithValue("@name", name);
            duplicate.Parameters.AddWithValue("@id", request.Id);
            if (await duplicate.ExecuteScalarAsync(cancellationToken) is not null)
            {
                return Results.Conflict(new { message = "A vehicle type with that name already exists." });
            }

            if (request.Id == 0)
            {
                await using var insert = new MySqlCommand(
                    "INSERT INTO vehicle_list (name, status, delete_flag) VALUES (@name, @status, 0)",
                    connection);
                insert.Parameters.AddWithValue("@name", name);
                insert.Parameters.AddWithValue("@status", request.IsActive ? 1 : 0);
                await insert.ExecuteNonQueryAsync(cancellationToken);
            }
            else
            {
                await using var update = new MySqlCommand("""
                    UPDATE vehicle_list SET name = @name, status = @status
                    WHERE id = @id AND delete_flag = 0
                    """, connection);
                update.Parameters.AddWithValue("@name", name);
                update.Parameters.AddWithValue("@status", request.IsActive ? 1 : 0);
                update.Parameters.AddWithValue("@id", request.Id);
                if (await update.ExecuteNonQueryAsync(cancellationToken) == 0)
                {
                    return Results.NotFound(new { message = "Vehicle type not found." });
                }
            }

            return Results.Ok(new { message = "Vehicle type saved." });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not save vehicle type {VehicleId}.", request.Id);
            return Results.Problem(
                title: "Vehicle type could not be saved.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> ArchiveVehicleAsync(
        int vehicleId,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsAdministrator(context))
        {
            return Results.Forbid();
        }

        if (!await antiforgery.IsRequestValidAsync(context))
        {
            return Results.BadRequest(new { message = "Refresh the page and try again." });
        }

        return await SetArchivedAsync("vehicle_list", vehicleId, dataSource, logger, cancellationToken);
    }

    private static async Task<IResult> SetArchivedAsync(
        string table,
        int id,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (id < 1)
        {
            return Results.BadRequest(new { message = "Choose a valid catalog item." });
        }

        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var command = new MySqlCommand(
                $"UPDATE {table} SET delete_flag = 1, status = 0 WHERE id = @id AND delete_flag = 0",
                connection);
            command.Parameters.AddWithValue("@id", id);
            if (await command.ExecuteNonQueryAsync(cancellationToken) == 0)
            {
                return Results.NotFound(new { message = "Catalog item not found." });
            }

            return Results.Ok(new { message = "Catalog item archived." });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not archive catalog item {CatalogId}.", id);
            return Results.Problem(
                title: "Catalog item could not be archived.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> SavePriceAsync(
        AdminPriceRequest request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsAdministrator(context))
        {
            return Results.Forbid();
        }

        if (!await antiforgery.IsRequestValidAsync(context))
        {
            return Results.BadRequest(new { message = "Refresh the page and try again." });
        }

        if (request.ServiceId < 1 || request.VehicleId < 1 ||
            request.Price <= 0 || request.Price > 9_999_999.99m ||
            decimal.Round(request.Price, 2) != request.Price)
        {
            return Results.BadRequest(new { message = "Enter a valid price with at most two decimal places." });
        }

        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using (var exists = new MySqlCommand("""
                SELECT 1 FROM service_list WHERE id = @serviceId AND delete_flag = 0
                """, connection))
            {
                exists.Parameters.AddWithValue("@serviceId", request.ServiceId);
                if (await exists.ExecuteScalarAsync(cancellationToken) is null)
                {
                    return Results.NotFound(new { message = "Service not found." });
                }
            }

            await using (var exists = new MySqlCommand("""
                SELECT 1 FROM vehicle_list WHERE id = @vehicleId AND delete_flag = 0
                """, connection))
            {
                exists.Parameters.AddWithValue("@vehicleId", request.VehicleId);
                if (await exists.ExecuteScalarAsync(cancellationToken) is null)
                {
                    return Results.NotFound(new { message = "Vehicle type not found." });
                }
            }

            await using var command = new MySqlCommand("""
                INSERT INTO price_list (service_id, vehicle_id, price)
                VALUES (@serviceId, @vehicleId, @price)
                ON DUPLICATE KEY UPDATE price = VALUES(price)
                """, connection);
            command.Parameters.AddWithValue("@serviceId", request.ServiceId);
            command.Parameters.AddWithValue("@vehicleId", request.VehicleId);
            command.Parameters.AddWithValue("@price", request.Price);
            await command.ExecuteNonQueryAsync(cancellationToken);
            return Results.Ok(new { message = "Price saved." });
        }
        catch (MySqlException exception)
        {
            logger.LogError(
                exception,
                "Could not save price for service {ServiceId} and vehicle {VehicleId}.",
                request.ServiceId,
                request.VehicleId);
            return Results.Problem(
                title: "Price could not be saved.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static bool IsAdministrator(HttpContext context) =>
        string.Equals(context.User.FindFirstValue(ClaimTypes.Role), "admin", StringComparison.Ordinal);
}

/// <summary>Credentials used to sign in to the administration portal.</summary>
internal sealed record AdminLoginRequest(string? Username, string? Password);

/// <summary>Summary counts displayed on the administration dashboard.</summary>
internal sealed record AdminSummaryResponse(int Total, int Pending, int Active, int Completed, int Unpaid);

/// <summary>Booking details shown to administration staff.</summary>
internal sealed record AdminBookingResponse(
    int Id,
    string Code,
    string Customer,
    string Contact,
    string Email,
    string PlateNumber,
    string Vehicle,
    DateTimeOffset Schedule,
    decimal Total,
    int Status,
    string PaymentStatus,
    string ReservationFeeStatus,
    bool IsArchived);

/// <summary>Requested supported booking status change.</summary>
internal sealed record BookingStatusRequest(int Status);

/// <summary>Service information shown in catalog management.</summary>
internal sealed record AdminServiceResponse(int Id, string Name, string Description, int DurationMinutes, bool IsActive);

/// <summary>Vehicle type shown in catalog management.</summary>
internal sealed record AdminVehicleResponse(int Id, string Name, bool IsActive);

/// <summary>Service price for a vehicle type.</summary>
internal sealed record AdminPriceResponse(int ServiceId, int VehicleId, decimal Price);

/// <summary>Catalog data required by administration screens.</summary>
internal sealed record AdminCatalogResponse(
    IReadOnlyList<AdminServiceResponse> Services,
    IReadOnlyList<AdminVehicleResponse> Vehicles,
    IReadOnlyList<AdminPriceResponse> Prices);

/// <summary>Service values submitted by an administrator.</summary>
internal sealed record AdminServiceRequest(
    int Id,
    string? Name,
    string? Description,
    int DurationMinutes,
    bool IsActive);

/// <summary>Vehicle type values submitted by an administrator.</summary>
internal sealed record AdminVehicleRequest(int Id, string? Name, bool IsActive);

/// <summary>Price value submitted for a service and vehicle type.</summary>
internal sealed record AdminPriceRequest(int ServiceId, int VehicleId, decimal Price);
