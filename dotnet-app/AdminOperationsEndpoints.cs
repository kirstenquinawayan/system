using System.Collections.Concurrent;
using System.Globalization;
using System.Net;
using System.Net.Mail;
using System.Security.Claims;
using System.Security.Cryptography;
using System.Text;
using System.Text.RegularExpressions;
using Microsoft.AspNetCore.Antiforgery;
using MySqlConnector;

internal static class AdminOperationsEndpoints
{
    private const string GenericResetMessage =
        "If an account matches those details, a password reset link will be sent to its recovery email.";
    private static readonly ConcurrentDictionary<string, RateWindow> ResetRate = new(StringComparer.Ordinal);

    public static void MapAdminOperationsEndpoints(this WebApplication app)
    {
        var admin = app.MapGroup("/api/admin-operations").RequireAuthorization();

        admin.MapGet("/users", ListUsersAsync);
        admin.MapPost("/users", SaveUserAsync);
        admin.MapDelete("/users/{id:int}", DeleteUserAsync);

        admin.MapGet("/reports", GetReportAsync);
        admin.MapGet("/reports.csv", ExportReportAsync);

        admin.MapGet("/bookings/{id:int}", GetBookingDetailsAsync);
        admin.MapPost("/bookings/{id:int}/reservation-fee/{decision}", ReviewReservationFeeAsync);
        admin.MapPost("/online-payments/{id:long}/{decision}", ReviewOnlinePaymentAsync);
        admin.MapPost("/bookings/{id:int}/notifications/{decision}", DecideBookingNotificationAsync);
        admin.MapGet("/notifications", GetNotificationQueueAsync);
        admin.MapPost("/notifications/password-recovery/{id:long}/{decision}", DecidePasswordRecoveryAsync);

        admin.MapGet("/settings", GetSettingsAsync);
        admin.MapPut("/settings", SaveSettingsAsync);
        admin.MapPost("/settings/images/{kind}", UploadSettingsImageAsync);
        admin.MapDelete("/settings/images/{kind}/{fileName}", DeleteSettingsImageAsync);

        app.MapGet("/api/public/settings", GetPublicSettingsAsync)
            .WithName("GetPublicBrandingSettings")
            .WithSummary("Get safe public site branding and about content");

        app.MapPost("/api/admin-password-reset/request", RequestPasswordResetAsync)
            .WithName("RequestAdminPasswordReset")
            .WithSummary("Request an administrator password reset link");
        app.MapPost("/api/admin-password-reset/reset", ResetPasswordAsync)
            .WithName("ResetAdminPassword")
            .WithSummary("Use a one-time reset token to set a new administrator password");
    }

    private static async Task<IResult> ListUsersAsync(
        HttpContext context,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsAdmin(context)) return Results.Forbid();
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var command = new MySqlCommand("""
                SELECT id, firstname, lastname, username, email, type, date_added, last_login
                FROM users WHERE type IN (1, 2) ORDER BY firstname, lastname, id
                """, connection);
            await using var reader = await command.ExecuteReaderAsync(cancellationToken);
            var users = new List<object>();
            while (await reader.ReadAsync(cancellationToken))
            {
                users.Add(new
                {
                    id = reader.GetInt32("id"),
                    firstName = reader.GetString("firstname"),
                    lastName = reader.GetString("lastname"),
                    username = reader.GetString("username"),
                    email = reader.IsDBNull(reader.GetOrdinal("email")) ? "" : reader.GetString("email"),
                    role = reader.GetInt32("type") == 1 ? "admin" : "staff",
                    dateAdded = AsManilaOffset(reader.GetDateTime("date_added")),
                    lastLogin = reader.IsDBNull(reader.GetOrdinal("last_login"))
                        ? (DateTimeOffset?)null
                        : AsManilaOffset(reader.GetDateTime("last_login"))
                });
            }

            return Results.Ok(users);
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not load admin user records.");
            return DatabaseUnavailable("User data is temporarily unavailable.");
        }
    }

    private static async Task<IResult> SaveUserAsync(
        UserRequest request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsAdmin(context)) return Results.Forbid();
        if (!await HasValidCsrfAsync(context, antiforgery)) return CsrfError();

        var firstName = request.FirstName?.Trim() ?? "";
        var lastName = request.LastName?.Trim() ?? "";
        var username = request.Username?.Trim() ?? "";
        var email = request.Email?.Trim() ?? "";
        var password = request.Password ?? "";
        var role = string.Equals(request.Role, "admin", StringComparison.Ordinal) ? 1 :
            string.Equals(request.Role, "staff", StringComparison.Ordinal) ? 2 : 0;
        if (request.Id < 0 || firstName.Length is 0 or > 250 ||
            lastName.Length is 0 or > 250 || username.Length is 0 or > 250 ||
            email.Length is 0 or > 254 || !IsValidEmail(email) || role == 0 ||
            (request.Id == 0 && !IsStrongPassword(password)) ||
            (request.Id > 0 && password.Length > 0 && !IsStrongPassword(password)))
        {
            return Results.BadRequest(new { message = "Check the name, username, email, role, and password requirements." });
        }

        var currentUserId = GetUserId(context);
        const string lockName = "ocwbs_admin_user_mgmt";
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            if (!await AcquireNamedLockAsync(connection, lockName, cancellationToken))
                return Results.Conflict(new { message = "This account is being changed. Try again." });
            try
            {
                await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
                var existingRole = 0;
                if (request.Id > 0)
                {
                    await using var find = new MySqlCommand(
                        "SELECT type FROM users WHERE id = @id AND type IN (1, 2) FOR UPDATE",
                        connection, transaction);
                    find.Parameters.AddWithValue("@id", request.Id);
                    var value = await find.ExecuteScalarAsync(cancellationToken);
                    if (value is null) return Results.NotFound(new { message = "User not found." });
                    existingRole = Convert.ToInt32(value, CultureInfo.InvariantCulture);
                }

                await using (var duplicate = new MySqlCommand("""
                    SELECT id FROM users
                    WHERE id <> @id AND (LOWER(username) = LOWER(@username) OR
                        (@email <> '' AND email IS NOT NULL AND LOWER(email) = LOWER(@email)))
                    LIMIT 1
                    """, connection, transaction))
                {
                    duplicate.Parameters.AddWithValue("@id", request.Id);
                    duplicate.Parameters.AddWithValue("@username", username);
                    duplicate.Parameters.AddWithValue("@email", email);
                    if (await duplicate.ExecuteScalarAsync(cancellationToken) is not null)
                        return Results.Conflict(new { message = "Username or email is already in use." });
                }

                if (existingRole == 1 && role != 1)
                {
                    if (await CountAdminsForUpdateAsync(connection, transaction, cancellationToken) <= 1)
                        return Results.Conflict(new { message = "The last administrator cannot be downgraded." });
                }

                if (request.Id == 0)
                {
                    var hash = BCrypt.Net.BCrypt.HashPassword(password);
                    await using var insert = new MySqlCommand("""
                        INSERT INTO users (firstname, lastname, username, email, password, type)
                        VALUES (@first, @last, @username, @email, @password, @role)
                        """, connection, transaction);
                    AddUserParameters(insert, firstName, lastName, username, email, role);
                    insert.Parameters.AddWithValue("@password", hash);
                    await insert.ExecuteNonQueryAsync(cancellationToken);
                }
                else
                {
                    var sql = password.Length > 0
                        ? "UPDATE users SET firstname=@first, lastname=@last, username=@username, email=@email, password=@password, type=@role WHERE id=@id"
                        : "UPDATE users SET firstname=@first, lastname=@last, username=@username, email=@email, type=@role WHERE id=@id";
                    await using var update = new MySqlCommand(sql, connection, transaction);
                    AddUserParameters(update, firstName, lastName, username, email, role);
                    if (password.Length > 0) update.Parameters.AddWithValue("@password", BCrypt.Net.BCrypt.HashPassword(password));
                    update.Parameters.AddWithValue("@id", request.Id);
                    await update.ExecuteNonQueryAsync(cancellationToken);
                }

                await transaction.CommitAsync(cancellationToken);
                logger.LogInformation("Admin user {UserId} was saved by {ActorId}.", request.Id, currentUserId);
                return Results.Ok(new { message = request.Id == 0 ? "User created." : "User updated." });
            }
            finally
            {
                await ReleaseNamedLockAsync(connection, lockName, logger);
            }
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not save admin user {UserId}.", request.Id);
            return DatabaseUnavailable("User could not be saved.");
        }
    }

    private static async Task<IResult> DeleteUserAsync(
        int id,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsAdmin(context)) return Results.Forbid();
        if (!await HasValidCsrfAsync(context, antiforgery)) return CsrfError();
        if (id < 1) return Results.BadRequest(new { message = "Choose a valid user." });
        if (id == GetUserId(context)) return Results.Conflict(new { message = "You cannot delete your own account." });
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            const string lockName = "ocwbs_admin_user_mgmt";
            if (!await AcquireNamedLockAsync(connection, lockName, cancellationToken))
                return Results.Conflict(new { message = "Another user update is in progress. Try again." });
            try
            {
                await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
                await using var find = new MySqlCommand(
                    "SELECT type FROM users WHERE id = @id AND type IN (1, 2) FOR UPDATE",
                    connection, transaction);
                find.Parameters.AddWithValue("@id", id);
                var role = await find.ExecuteScalarAsync(cancellationToken);
                if (role is null) return Results.NotFound(new { message = "User not found." });
                if (Convert.ToInt32(role, CultureInfo.InvariantCulture) == 1)
                {
                    if (await CountAdminsForUpdateAsync(connection, transaction, cancellationToken) <= 1)
                        return Results.Conflict(new { message = "The last administrator cannot be deleted." });
                }

                await using var delete = new MySqlCommand("DELETE FROM users WHERE id = @id", connection, transaction);
                delete.Parameters.AddWithValue("@id", id);
                await delete.ExecuteNonQueryAsync(cancellationToken);
                await transaction.CommitAsync(cancellationToken);
                logger.LogInformation("Admin user {UserId} was deleted by {ActorId}.", id, GetUserId(context));
                return Results.NoContent();
            }
            finally
            {
                await ReleaseNamedLockAsync(connection, lockName, logger);
            }
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not delete admin user {UserId}.", id);
            return DatabaseUnavailable("User could not be deleted.");
        }
    }

    private static void AddUserParameters(MySqlCommand command, string first, string last, string username, string email, int role)
    {
        command.Parameters.AddWithValue("@first", first);
        command.Parameters.AddWithValue("@last", last);
        command.Parameters.AddWithValue("@username", username);
        command.Parameters.AddWithValue("@email", email.Length == 0 ? DBNull.Value : email);
        command.Parameters.AddWithValue("@role", role);
    }

    private static async Task<int> CountAdminsForUpdateAsync(
        MySqlConnection connection,
        MySqlTransaction transaction,
        CancellationToken cancellationToken)
    {
        await using var command = new MySqlCommand(
            "SELECT id FROM users WHERE type=1 ORDER BY id FOR UPDATE", connection, transaction);
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        var count = 0;
        while (await reader.ReadAsync(cancellationToken)) count++;
        return count;
    }

    private static async Task<IResult> GetReportAsync(
        string? startDate,
        string? endDate,
        string? dateType,
        HttpContext context,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsBookingStaff(context)) return Results.Forbid();
        if (!TryReportRange(startDate, endDate, dateType, out var from, out var until, out var column, out var error))
            return Results.BadRequest(new { message = error });

        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            var rows = await ReadReportRowsAsync(connection, from, until, column, cancellationToken);
            var paid = rows.Sum(row => row.AmountPaid);
            return Results.Ok(new
            {
                dateType,
                startDate,
                endDate,
                summary = new { bookings = rows.Count, paidAmount = paid, unpaid = rows.Count(row => row.PaymentStatus == "unpaid") },
                details = rows
            });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not load report rows for {StartDate} to {EndDate}.", startDate, endDate);
            return DatabaseUnavailable("Report data is temporarily unavailable.");
        }
    }

    private static async Task<IResult> ExportReportAsync(
        string? startDate,
        string? endDate,
        string? dateType,
        HttpContext context,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsBookingStaff(context)) return Results.Forbid();
        if (!TryReportRange(startDate, endDate, dateType, out var from, out var until, out var column, out var error))
            return Results.BadRequest(new { message = error });

        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            var rows = await ReadReportRowsAsync(connection, from, until, column, cancellationToken);
            var builder = new StringBuilder();
            AddCsvRow(builder, ["Booking reference", "Customer", "Email", "Contact", "Booking date", "Appointment",
                "Vehicle", "Plate", "Status", "Reservation-fee status", "Reservation-fee method", "Transfer reference",
                "Refund reference", "Payment status", "Payment method", "Amount paid PHP", "Total PHP", "Reservation fee PHP",
                "Paid at", "Recorded by"]);
            foreach (var row in rows)
                AddCsvRow(builder, [
                    row.Code, row.Customer, row.Email, row.Contact, row.BookedAt.ToString("yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture),
                    row.Schedule.ToString("yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture), row.Vehicle, row.Plate,
                    row.StatusLabel, row.ReservationFeeStatus, PublicPaymentMethod(row.ReservationFeeMethod),
                    row.ReservationFeeReference, row.RefundReference, row.PaymentStatus, PublicPaymentMethod(row.PaymentMethod),
                    row.AmountPaid.ToString("0.00", CultureInfo.InvariantCulture), row.Total.ToString("0.00", CultureInfo.InvariantCulture),
                    row.ReservationFee.ToString("0.00", CultureInfo.InvariantCulture),
                    row.PaidAt?.ToString("yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture) ?? "", row.ReceivedBy]);

            var filename = $"booking-report-{dateType}-{startDate}-to-{endDate}.csv";
            return Results.File(Encoding.UTF8.GetPreamble().Concat(Encoding.UTF8.GetBytes(builder.ToString())).ToArray(),
                "text/csv; charset=utf-8", filename);
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not export report rows for {StartDate} to {EndDate}.", startDate, endDate);
            return DatabaseUnavailable("Report export is temporarily unavailable.");
        }
    }

    private static async Task<List<ReportRow>> ReadReportRowsAsync(
        MySqlConnection connection,
        DateTime from,
        DateTime until,
        string column,
        CancellationToken cancellationToken)
    {
        await using var command = new MySqlCommand($"""
            SELECT b.code, b.client_name, b.email, b.contact, b.schedule, b.date_created, v.name AS vehicle,
                   b.plate_number, b.status, b.payment_status, b.payment_method, b.total_amount, b.reservation_fee,
                   b.reservation_fee_payment_status, b.reservation_fee_payment_method,
                   b.reservation_fee_transaction_ref, b.reservation_fee_refund_ref,
                   b.payment_paid_at, CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')) AS received_by
            FROM booking_list b
            INNER JOIN vehicle_list v ON v.id = b.vehicle_id
            LEFT JOIN users u ON u.id = b.payment_received_by
            WHERE b.{column} >= @from AND b.{column} < @until
            ORDER BY b.{column} DESC, b.schedule, b.code
            LIMIT 5000
            """, connection);
        command.Parameters.AddWithValue("@from", from);
        command.Parameters.AddWithValue("@until", until);
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        var rows = new List<ReportRow>();
        while (await reader.ReadAsync(cancellationToken))
        {
            var total = reader.GetDecimal("total_amount");
            var fee = reader.GetDecimal("reservation_fee");
            var feeStatus = reader.GetString("reservation_fee_payment_status");
            var paymentStatus = reader.GetString("payment_status");
            var paidAmount = paymentStatus == "paid"
                ? (feeStatus == "refunded" ? Math.Max(0, total - fee) : total)
                : feeStatus == "paid" ? fee : 0m;
            rows.Add(new ReportRow(
                reader.GetString("code"), reader.GetString("client_name"), reader.GetString("email"),
                reader.GetString("contact"), AsManilaOffset(reader.GetDateTime("date_created")),
                AsManilaOffset(reader.GetDateTime("schedule")), reader.GetString("vehicle"), reader.GetString("plate_number"),
                BookingStatusLabel(reader.GetInt32("status")), paymentStatus, feeStatus,
                reader.IsDBNull(reader.GetOrdinal("reservation_fee_payment_method")) ? "" :
                    PublicPaymentMethod(reader.GetString("reservation_fee_payment_method")),
                reader.IsDBNull(reader.GetOrdinal("reservation_fee_transaction_ref")) ? "" : reader.GetString("reservation_fee_transaction_ref"),
                reader.IsDBNull(reader.GetOrdinal("reservation_fee_refund_ref")) ? "" : reader.GetString("reservation_fee_refund_ref"),
                reader.IsDBNull(reader.GetOrdinal("payment_method")) ? "" :
                    PublicPaymentMethod(reader.GetString("payment_method")),
                total, fee, paidAmount,
                reader.IsDBNull(reader.GetOrdinal("payment_paid_at")) ? null : AsManilaOffset(reader.GetDateTime("payment_paid_at")),
                reader.GetString("received_by").Trim()));
        }

        return rows;
    }

    private static void AddCsvRow(StringBuilder output, IEnumerable<string> values)
    {
        output.AppendJoin(',', values.Select(value =>
        {
            var safe = value ?? "";
            if (Regex.IsMatch(safe, @"^\s*[=+\-@]", RegexOptions.CultureInvariant))
                safe = "'" + safe;
            return "\"" + safe.Replace("\"", "\"\"", StringComparison.Ordinal) + "\"";
        })).Append("\r\n");
    }

    private static bool TryReportRange(
        string? start,
        string? end,
        string? type,
        out DateTime from,
        out DateTime until,
        out string column,
        out string error)
    {
        from = default;
        until = default;
        column = "";
        error = "Provide valid start/end dates and choose booking or appointment date.";
        if (type is not ("booking" or "appointment") ||
            !DateTime.TryParseExact(start, "yyyy-MM-dd", CultureInfo.InvariantCulture, DateTimeStyles.None, out from) ||
            !DateTime.TryParseExact(end, "yyyy-MM-dd", CultureInfo.InvariantCulture, DateTimeStyles.None, out var last) ||
            from > last || last >= new DateTime(9999, 12, 31) || (last - from).TotalDays > 366)
            return false;
        until = last.AddDays(1);
        column = type == "booking" ? "date_created" : "schedule";
        error = "";
        return true;
    }

    private static async Task<IResult> GetBookingDetailsAsync(
        int id,
        HttpContext context,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsBookingStaff(context)) return Results.Forbid();
        if (id < 1) return Results.BadRequest(new { message = "Choose a valid booking." });
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            object? booking;
            await using (var command = new MySqlCommand("""
                SELECT b.id, b.code, b.client_name, b.contact, b.email, b.address, b.plate_number,
                       b.schedule, b.date_created, b.status, b.is_archived, b.total_amount, b.reservation_fee,
                       b.reservation_fee_payment_status, b.reservation_fee_payment_method,
                       b.reservation_fee_transaction_ref, b.reservation_fee_submitted_at,
                       b.reservation_fee_verified_at, b.reservation_fee_rejected_at, b.reservation_fee_refunded_at,
                       b.reservation_fee_verified_by, b.reservation_fee_rejected_by, b.reservation_fee_refunded_by,
                       CONCAT(COALESCE(fv.firstname, ''), ' ', COALESCE(fv.lastname, '')) AS fee_verified_by_name,
                       CONCAT(COALESCE(fr.firstname, ''), ' ', COALESCE(fr.lastname, '')) AS fee_rejected_by_name,
                       CONCAT(COALESCE(ff.firstname, ''), ' ', COALESCE(ff.lastname, '')) AS fee_refunded_by_name,
                       b.reservation_fee_refund_ref, b.cash_balance_amount, b.payment_status, b.payment_method,
                       b.payment_paid_at, b.payment_received_by, v.name AS vehicle,
                       CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')) AS received_by
                FROM booking_list b
                INNER JOIN vehicle_list v ON v.id = b.vehicle_id
                LEFT JOIN users u ON u.id = b.payment_received_by
                LEFT JOIN users fv ON fv.id = b.reservation_fee_verified_by
                LEFT JOIN users fr ON fr.id = b.reservation_fee_rejected_by
                LEFT JOIN users ff ON ff.id = b.reservation_fee_refunded_by
                WHERE b.id = @id
                """, connection))
            {
                command.Parameters.AddWithValue("@id", id);
                await using var reader = await command.ExecuteReaderAsync(cancellationToken);
                if (!await reader.ReadAsync(cancellationToken)) return Results.NotFound(new { message = "Booking not found." });
                booking = new
                {
                    id = reader.GetInt32("id"),
                    code = reader.GetString("code"),
                    customer = reader.GetString("client_name"),
                    contact = reader.GetString("contact"),
                    email = reader.GetString("email"),
                    address = reader.GetString("address"),
                    plateNumber = reader.GetString("plate_number"),
                    vehicle = reader.GetString("vehicle"),
                    schedule = AsManilaOffset(reader.GetDateTime("schedule")),
                    createdAt = AsManilaOffset(reader.GetDateTime("date_created")),
                    status = reader.GetInt32("status"),
                    statusLabel = BookingStatusLabel(reader.GetInt32("status")),
                    isArchived = reader.GetBoolean("is_archived"),
                    total = reader.GetDecimal("total_amount"),
                    reservationFee = reader.GetDecimal("reservation_fee"),
                    reservationFeeStatus = reader.GetString("reservation_fee_payment_status"),
                    reservationFeeMethod = DbString(reader, "reservation_fee_payment_method"),
                    reservationFeeReference = DbString(reader, "reservation_fee_transaction_ref"),
                    reservationFeeSubmittedAt = DbOffset(reader, "reservation_fee_submitted_at"),
                    reservationFeeVerifiedAt = DbOffset(reader, "reservation_fee_verified_at"),
                    reservationFeeVerifiedBy = reader.GetString("fee_verified_by_name").Trim(),
                    reservationFeeRejectedAt = DbOffset(reader, "reservation_fee_rejected_at"),
                    reservationFeeRejectedBy = reader.GetString("fee_rejected_by_name").Trim(),
                    reservationFeeRefundedAt = DbOffset(reader, "reservation_fee_refunded_at"),
                    reservationFeeRefundedBy = reader.GetString("fee_refunded_by_name").Trim(),
                    reservationFeeRefundReference = DbString(reader, "reservation_fee_refund_ref"),
                    cashBalance = reader.GetDecimal("cash_balance_amount"),
                    paymentStatus = reader.GetString("payment_status"),
                    paymentMethod = DbString(reader, "payment_method"),
                    paymentPaidAt = DbOffset(reader, "payment_paid_at"),
                    paymentReceivedBy = reader.IsDBNull(reader.GetOrdinal("payment_received_by"))
                        ? (int?)null
                        : reader.GetInt32("payment_received_by"),
                    paymentReceivedByName = reader.GetString("received_by").Trim()
                };
            }

            var services = new List<object>();
            await using (var command = new MySqlCommand("""
                SELECT s.name, bs.price, bs.duration_minutes
                FROM booking_services bs INNER JOIN service_list s ON s.id = bs.service_id
                WHERE bs.booking_id = @id ORDER BY s.name
                """, connection))
            {
                command.Parameters.AddWithValue("@id", id);
                await using var reader = await command.ExecuteReaderAsync(cancellationToken);
                while (await reader.ReadAsync(cancellationToken))
                    services.Add(new { name = reader.GetString("name"), price = reader.GetDecimal("price"), durationMinutes = reader.GetInt32("duration_minutes") });
            }

            var payments = new List<object>();
            await using (var command = new MySqlCommand("""
                SELECT id, payment_type, payment_provider, amount_cents, status, transaction_ref,
                       verified_at, rejected_at, paid_at, expires_at, date_created
                FROM booking_online_payments WHERE booking_id = @id ORDER BY id DESC LIMIT 50
                """, connection))
            {
                command.Parameters.AddWithValue("@id", id);
                await using var reader = await command.ExecuteReaderAsync(cancellationToken);
                while (await reader.ReadAsync(cancellationToken))
                    payments.Add(new
                    {
                        id = reader.GetInt64("id"),
                        type = reader.GetString("payment_type"),
                        provider = reader.GetString("payment_provider"),
                        amount = reader.GetInt32("amount_cents") / 100m,
                        status = reader.GetString("status"),
                        reference = DbString(reader, "transaction_ref"),
                        verifiedAt = DbOffset(reader, "verified_at"),
                        rejectedAt = DbOffset(reader, "rejected_at"),
                        paidAt = DbOffset(reader, "paid_at"),
                        expiresAt = AsManilaOffset(reader.GetDateTime("expires_at")),
                        createdAt = AsManilaOffset(reader.GetDateTime("date_created"))
                    });
            }

            var otpStates = new List<object>();
            await using (var command = new MySqlCommand("""
                SELECT status, attempts, expires_at, verified_at, date_created
                FROM booking_payment_otps WHERE booking_id = @id ORDER BY id DESC LIMIT 20
                """, connection))
            {
                command.Parameters.AddWithValue("@id", id);
                await using var reader = await command.ExecuteReaderAsync(cancellationToken);
                while (await reader.ReadAsync(cancellationToken))
                    otpStates.Add(new
                    {
                        status = reader.GetString("status"),
                        attempts = reader.GetInt32("attempts"),
                        expiresAt = AsManilaOffset(reader.GetDateTime("expires_at")),
                        verifiedAt = DbOffset(reader, "verified_at"),
                        createdAt = AsManilaOffset(reader.GetDateTime("date_created"))
                    });
            }

            var attempts = new List<object>();
            try
            {
                await using var command = new MySqlCommand("""
                    SELECT provider, amount_minor, currency, status, created_at, paid_at
                    FROM csharp_payment_attempts WHERE booking_id=@id ORDER BY id DESC LIMIT 20
                    """, connection);
                command.Parameters.AddWithValue("@id", id);
                await using var reader = await command.ExecuteReaderAsync(cancellationToken);
                while (await reader.ReadAsync(cancellationToken))
                    attempts.Add(new
                    {
                        provider = reader.GetString("provider"),
                        amount = reader.GetInt64("amount_minor") / 100m,
                        currency = reader.GetString("currency"),
                        status = reader.GetString("status"),
                        createdAt = AsManilaOffset(reader.GetDateTime("created_at")),
                        paidAt = DbUtcOffset(reader, "paid_at")
                    });
            }
            catch (MySqlException exception) when (exception.Number == 1146)
            {
                logger.LogWarning("Payment-attempt storage is not installed; returning available booking details without it.");
            }

            return Results.Ok(new
            {
                booking, services, onlinePayments = payments,
                paymentAttempts = attempts, otpVerification = otpStates
            });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not load booking detail for booking {BookingId}.", id);
            return DatabaseUnavailable("Booking details are temporarily unavailable.");
        }
    }

    private static async Task<IResult> ReviewReservationFeeAsync(
        int id,
        string decision,
        ReservationFeeDecision request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsBookingStaff(context)) return Results.Forbid();
        if (!await HasValidCsrfAsync(context, antiforgery)) return CsrfError();
        if (id < 1 || decision is not ("verify" or "reject" or "refund"))
            return Results.BadRequest(new { message = "Choose a valid reservation-fee action." });
        var reference = request.RefundReference?.Trim() ?? "";
        if (decision == "refund" && !Regex.IsMatch(reference, @"^[A-Za-z0-9][A-Za-z0-9 -]{4,99}$", RegexOptions.CultureInvariant))
            return Results.BadRequest(new { message = "Enter the completed refund transaction reference." });

        var (sql, success) = decision switch
        {
            "verify" => ("""
                UPDATE booking_list SET reservation_fee_payment_status='paid',
                    reservation_fee_verified_at=NOW(), reservation_fee_verified_by=@userId
                WHERE id=@id AND status<>5 AND payment_status='unpaid' AND is_archived=0
                    AND reservation_fee_payment_status='pending_verification'
                    AND reservation_fee_transaction_ref IS NOT NULL
                """, "Reservation fee verified."),
            "reject" => ("""
                UPDATE booking_list SET reservation_fee_payment_status='rejected',
                    reservation_fee_rejected_at=NOW(), reservation_fee_rejected_by=@userId
                WHERE id=@id AND reservation_fee_payment_status='pending_verification' AND is_archived=0
                """, "Reservation fee submission rejected."),
            _ => ("""
                UPDATE booking_list SET reservation_fee_payment_status='refunded',
                    reservation_fee_refunded_at=NOW(), reservation_fee_refund_ref=@reference,
                    reservation_fee_refunded_by=@userId
                WHERE id=@id AND status=5 AND payment_status='unpaid'
                    AND reservation_fee_payment_status='paid' AND is_archived=0
                """, "Reservation-fee refund recorded.")
        };
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var command = new MySqlCommand(sql, connection);
            command.Parameters.AddWithValue("@id", id);
            command.Parameters.AddWithValue("@userId", GetUserId(context));
            if (decision == "refund") command.Parameters.AddWithValue("@reference", reference);
            if (await command.ExecuteNonQueryAsync(cancellationToken) != 1)
                return Results.Conflict(new { message = "The reservation fee is no longer in a state that allows this action." });
            logger.LogInformation("Reservation-fee decision {Decision} recorded for booking {BookingId} by {ActorId}.",
                decision, id, GetUserId(context));
            return Results.Ok(new { message = success });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not apply reservation-fee decision to booking {BookingId}.", id);
            return DatabaseUnavailable("Reservation-fee action could not be saved.");
        }
    }

    private static async Task<IResult> ReviewOnlinePaymentAsync(
        long id,
        string decision,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsBookingStaff(context)) return Results.Forbid();
        if (!await HasValidCsrfAsync(context, antiforgery)) return CsrfError();
        if (id < 1 || decision is not ("verify" or "reject"))
            return Results.BadRequest(new { message = "Choose a valid online-payment action." });
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
            int bookingId, amountCents;
            string provider, paymentState, bookingPaymentState;
            int bookingStatus;
            bool archived;
            await using (var lookup = new MySqlCommand("""
                SELECT p.booking_id, p.amount_cents, p.payment_provider, p.status,
                       b.payment_status, b.status, b.is_archived
                FROM booking_online_payments p INNER JOIN booking_list b ON b.id=p.booking_id
                WHERE p.id=@id FOR UPDATE
                """, connection, transaction))
            {
                lookup.Parameters.AddWithValue("@id", id);
                await using var reader = await lookup.ExecuteReaderAsync(cancellationToken);
                if (!await reader.ReadAsync(cancellationToken)) return Results.NotFound(new { message = "Payment submission not found." });
                bookingId = reader.GetInt32("booking_id");
                amountCents = reader.GetInt32("amount_cents");
                provider = reader.GetString("payment_provider");
                paymentState = reader.GetString("status");
                bookingPaymentState = reader.GetString("payment_status");
                bookingStatus = reader.GetInt32("status");
                archived = reader.GetBoolean("is_archived");
            }
            if (decision == "verify" &&
                (paymentState != "pending_verification" || bookingPaymentState != "unpaid" ||
                 bookingStatus == 5 || archived || provider is not ("gcash" or "maya")))
                return Results.Conflict(new { message = "This transfer is no longer awaiting verification." });
            if (decision == "reject" && paymentState != "pending_verification")
                return Results.Conflict(new { message = "This transfer is no longer awaiting review." });

            var update = decision == "verify"
                ? new MySqlCommand("""
                    UPDATE booking_online_payments SET status='paid', paid_at=NOW(), verified_by=@userId, verified_at=NOW()
                    WHERE id=@id AND status='pending_verification'
                    """, connection, transaction)
                : new MySqlCommand("""
                    UPDATE booking_online_payments SET status='rejected', rejected_by=@userId, rejected_at=NOW()
                    WHERE id=@id AND status='pending_verification'
                    """, connection, transaction);
            await using (update)
            {
                update.Parameters.AddWithValue("@userId", GetUserId(context));
                update.Parameters.AddWithValue("@id", id);
                if (await update.ExecuteNonQueryAsync(cancellationToken) != 1)
                    return Results.Conflict(new { message = "This transfer has already been reviewed." });
            }
            if (decision == "verify")
            {
                await using var bookingUpdate = new MySqlCommand("""
                    UPDATE booking_list SET payment_status='paid', payment_method=@provider,
                        status=IF(status=0,1,status), payment_paid_at=NOW(), payment_received_by=@userId,
                        cash_balance_amount=@amount
                    WHERE id=@bookingId AND payment_status='unpaid' AND status<>5 AND is_archived=0
                    """, connection, transaction);
                bookingUpdate.Parameters.AddWithValue("@provider", provider);
                bookingUpdate.Parameters.AddWithValue("@userId", GetUserId(context));
                bookingUpdate.Parameters.AddWithValue("@amount", amountCents / 100m);
                bookingUpdate.Parameters.AddWithValue("@bookingId", bookingId);
                if (await bookingUpdate.ExecuteNonQueryAsync(cancellationToken) != 1)
                    return Results.Conflict(new { message = "The booking is no longer unpaid." });
            }
            await transaction.CommitAsync(cancellationToken);
            logger.LogInformation("Online payment {PaymentId} was {Decision} by {ActorId}.", id, decision, GetUserId(context));
            return Results.Ok(new { message = decision == "verify" ? "Transfer verified and payment recorded." : "Transfer rejected." });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not review online payment {PaymentId}.", id);
            return DatabaseUnavailable("Online payment could not be reviewed.");
        }
    }

    private static async Task<IResult> GetNotificationQueueAsync(
        HttpContext context,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsBookingStaff(context)) return Results.Forbid();
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            var recovery = new List<object>();
            await using (var command = new MySqlCommand("""
                SELECT r.id, r.requested_at, c.full_name, c.email, c.contact
                FROM customer_password_recovery_requests r
                INNER JOIN customer_accounts c ON c.id=r.customer_id
                WHERE r.status='pending' ORDER BY r.requested_at, r.id LIMIT 500
                """, connection))
            await using (var reader = await command.ExecuteReaderAsync(cancellationToken))
            {
                while (await reader.ReadAsync(cancellationToken))
                    recovery.Add(new
                    {
                        id = reader.GetInt64("id"), customer = reader.GetString("full_name"),
                        email = reader.GetString("email"), contact = reader.GetString("contact"),
                        requestedAt = AsManilaOffset(reader.GetDateTime("requested_at"))
                    });
            }

            var bookings = new List<object>();
            await using (var command = new MySqlCommand("""
                SELECT id, code, client_name, contact, schedule, status
                FROM booking_list WHERE customer_id IS NOT NULL AND is_archived=0 AND status IN (0,5)
                ORDER BY status, schedule, id DESC LIMIT 500
                """, connection))
            await using (var reader = await command.ExecuteReaderAsync(cancellationToken))
            {
                while (await reader.ReadAsync(cancellationToken))
                    bookings.Add(new
                    {
                        id = reader.GetInt32("id"), code = reader.GetString("code"),
                        customer = reader.GetString("client_name"), contact = reader.GetString("contact"),
                        schedule = AsManilaOffset(reader.GetDateTime("schedule")), status = reader.GetInt32("status"),
                        statusLabel = BookingStatusLabel(reader.GetInt32("status"))
                    });
            }

            return Results.Ok(new { passwordRecovery = recovery, customerBookings = bookings });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not load admin customer notification queues.");
            return DatabaseUnavailable("Customer notification queues are temporarily unavailable.");
        }
    }

    private static async Task<IResult> DecidePasswordRecoveryAsync(
        long id,
        string decision,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsBookingStaff(context)) return Results.Forbid();
        if (!await HasValidCsrfAsync(context, antiforgery)) return CsrfError();
        if (id < 1 || decision is not ("approve" or "reject"))
            return Results.BadRequest(new { message = "Choose approve or reject." });
        var sql = decision == "approve"
            ? """
              UPDATE customer_password_recovery_requests
              SET status='approved', approved_at=NOW(), approved_by=@userId, expires_at=DATE_ADD(NOW(), INTERVAL 30 MINUTE)
              WHERE id=@id AND status='pending'
              """
            : """
              UPDATE customer_password_recovery_requests
              SET status='rejected', approved_at=NULL, approved_by=@userId, expires_at=NULL
              WHERE id=@id AND status='pending'
              """;
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var command = new MySqlCommand(sql, connection);
            command.Parameters.AddWithValue("@id", id);
            command.Parameters.AddWithValue("@userId", GetUserId(context));
            if (await command.ExecuteNonQueryAsync(cancellationToken) != 1)
                return Results.Conflict(new { message = "This password-recovery request is no longer pending." });
            logger.LogInformation("Customer password-recovery request {RequestId} was {Decision} by {ActorId}.",
                id, decision, GetUserId(context));
            return Results.Ok(new { message = decision == "approve" ? "Request approved for 30 minutes." : "Request declined." });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not decide customer recovery request {RequestId}.", id);
            return DatabaseUnavailable("Password-recovery decision could not be saved.");
        }
    }

    private static async Task<IResult> DecideBookingNotificationAsync(
        int id,
        string decision,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsBookingStaff(context)) return Results.Forbid();
        if (!await HasValidCsrfAsync(context, antiforgery)) return CsrfError();
        if (id < 1 || decision is not ("approve" or "cancel"))
            return Results.BadRequest(new { message = "Choose approve or cancel." });
        var targetStatus = decision == "approve" ? 1 : 5;
        string? lockName = null;
        MySqlConnection? connection = null;
        try
        {
            connection = await dataSource.OpenConnectionAsync(cancellationToken);
            DateTime schedule;
            await using (var scheduleCommand = new MySqlCommand(
                "SELECT schedule FROM booking_list WHERE id=@id AND customer_id IS NOT NULL AND is_archived=0",
                connection))
            {
                scheduleCommand.Parameters.AddWithValue("@id", id);
                var value = await scheduleCommand.ExecuteScalarAsync(cancellationToken);
                if (value is null) return Results.NotFound(new { message = "Customer booking not found." });
                schedule = Convert.ToDateTime(value, CultureInfo.InvariantCulture);
            }
            lockName = ScheduleLockName(schedule);
            if (!await AcquireNamedLockAsync(connection, lockName, cancellationToken))
                return Results.Conflict(new { message = "This appointment slot is being updated. Try again." });
            await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
            await using (var lookup = new MySqlCommand(
                "SELECT schedule, status, customer_id FROM booking_list WHERE id=@id AND is_archived=0 FOR UPDATE",
                connection, transaction))
            {
                lookup.Parameters.AddWithValue("@id", id);
                await using var reader = await lookup.ExecuteReaderAsync(cancellationToken);
                if (!await reader.ReadAsync(cancellationToken)) return Results.NotFound(new { message = "Customer booking not found." });
                schedule = reader.GetDateTime("schedule");
                var status = reader.GetInt32("status");
                if (status != 0)
                    return Results.Conflict(new { message = "This booking is no longer awaiting an approval decision." });
            }

            if (targetStatus == 1)
            {
                await using var capacity = new MySqlCommand("""
                    SELECT COUNT(*) FROM booking_list
                    WHERE schedule=@schedule AND status<>5 AND is_archived=0 AND id<>@id
                    """, connection, transaction);
                capacity.Parameters.AddWithValue("@schedule", schedule);
                capacity.Parameters.AddWithValue("@id", id);
                if (Convert.ToInt32(await capacity.ExecuteScalarAsync(cancellationToken), CultureInfo.InvariantCulture) >= 2)
                    return Results.Conflict(new { message = "This appointment slot is full." });
            }

            await using (var update = new MySqlCommand(
                "UPDATE booking_list SET status=@status WHERE id=@id AND customer_id IS NOT NULL AND is_archived=0",
                connection, transaction))
            {
                update.Parameters.AddWithValue("@status", targetStatus);
                update.Parameters.AddWithValue("@id", id);
                await update.ExecuteNonQueryAsync(cancellationToken);
            }
            var customerId = 0;
            await using (var customer = new MySqlCommand(
                "SELECT customer_id FROM booking_list WHERE id=@id", connection, transaction))
            {
                customer.Parameters.AddWithValue("@id", id);
                customerId = Convert.ToInt32(await customer.ExecuteScalarAsync(cancellationToken), CultureInfo.InvariantCulture);
            }
            await using (var notification = new MySqlCommand("""
                INSERT INTO customer_booking_notifications (booking_id, customer_id, status, message, appointment_at)
                VALUES (@bookingId, @customerId, @status, @message, @schedule)
                """, connection, transaction))
            {
                notification.Parameters.AddWithValue("@bookingId", id);
                notification.Parameters.AddWithValue("@customerId", customerId);
                notification.Parameters.AddWithValue("@status", targetStatus);
                notification.Parameters.AddWithValue("@message", targetStatus == 1
                    ? "Your booking has been approved."
                    : "Your booking has been cancelled.");
                notification.Parameters.AddWithValue("@schedule", schedule);
                await notification.ExecuteNonQueryAsync(cancellationToken);
            }
            await transaction.CommitAsync(cancellationToken);
            logger.LogInformation("Customer booking {BookingId} was {Decision} by {ActorId}.", id, decision, GetUserId(context));
            return Results.Ok(new { message = decision == "approve" ? "Booking approved and customer notified." : "Booking cancelled and customer notified." });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not decide customer booking {BookingId}.", id);
            return DatabaseUnavailable("Booking decision could not be saved.");
        }
        finally
        {
            if (connection is not null)
            {
                if (lockName is not null) await ReleaseNamedLockAsync(connection, lockName, logger);
                await connection.DisposeAsync();
            }
        }
    }

    private static async Task<IResult> GetSettingsAsync(
        HttpContext context,
        MySqlDataSource dataSource,
        IWebHostEnvironment environment,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsAdmin(context)) return Results.Forbid();
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            var settings = await ReadSettingsAsync(connection, environment, cancellationToken);
            return Results.Ok(new
            {
                settings.Name, settings.ShortName, settings.AboutUs,
                logo = SafeAssetRef(settings.Logo),
                cover = SafeAssetRef(settings.Cover),
                banners = ListBannerNames(environment)
            });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not load system information settings.");
            return DatabaseUnavailable("System settings are temporarily unavailable.");
        }
    }

    private static async Task<IResult> GetPublicSettingsAsync(
        MySqlDataSource dataSource,
        IWebHostEnvironment environment,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            var settings = await ReadSettingsAsync(connection, environment, cancellationToken);
            return Results.Ok(new
            {
                name = settings.Name, shortName = settings.ShortName, aboutUs = settings.AboutUs,
                logo = SafeAssetRef(settings.Logo), cover = SafeAssetRef(settings.Cover),
                banners = ListBannerNames(environment).Select(name => "uploads/banner/" + name).ToArray()
            });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not load safe public branding settings.");
            return DatabaseUnavailable("Public site settings are temporarily unavailable.");
        }
    }

    private static async Task<SiteSettings> ReadSettingsAsync(
        MySqlConnection connection,
        IWebHostEnvironment environment,
        CancellationToken cancellationToken)
    {
        var map = new Dictionary<string, string>(StringComparer.Ordinal);
        await using var command = new MySqlCommand(
            "SELECT meta_field, meta_value FROM system_info WHERE meta_field IN ('name','short_name','about_us','logo','cover')",
            connection);
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        while (await reader.ReadAsync(cancellationToken))
            map.TryAdd(reader.GetString("meta_field"), reader.GetString("meta_value"));
        var about = map.TryGetValue("about_us", out var configuredAbout)
            ? configuredAbout
            : await ReadLegacyAboutAsTextAsync(environment, cancellationToken);
        return new SiteSettings(
            map.GetValueOrDefault("name", ""),
            map.GetValueOrDefault("short_name", ""),
            about,
            map.GetValueOrDefault("logo", ""),
            map.GetValueOrDefault("cover", ""));
    }

    private static async Task<string> ReadLegacyAboutAsTextAsync(
        IWebHostEnvironment environment,
        CancellationToken cancellationToken)
    {
        var workspace = Path.GetFullPath("..", environment.ContentRootPath);
        var path = ContainedPath(workspace, Path.Combine(workspace, "about.html"));
        if (!System.IO.File.Exists(path)) return "";
        var html = await System.IO.File.ReadAllTextAsync(path, cancellationToken);
        html = Regex.Replace(
            html,
            @"<h[1-6]\b[^>]*>.*?</h[1-6]>|<a\b[^>]*>.*?</a>",
            " ",
            RegexOptions.IgnoreCase | RegexOptions.Singleline | RegexOptions.CultureInvariant);
        html = Regex.Replace(html, @"</p\s*>", Environment.NewLine + Environment.NewLine,
            RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);
        var text = Regex.Replace(html, "<[^>]*>", " ", RegexOptions.CultureInvariant);
        return Regex.Replace(WebUtility.HtmlDecode(text), @"[ \t]*\r?\n[ \t]*", Environment.NewLine,
            RegexOptions.CultureInvariant).Trim();
    }

    private static async Task<IResult> SaveSettingsAsync(
        SiteSettingsRequest request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsAdmin(context)) return Results.Forbid();
        if (!await HasValidCsrfAsync(context, antiforgery)) return CsrfError();
        var name = request.Name?.Trim() ?? "";
        var shortName = request.ShortName?.Trim() ?? "";
        var about = request.AboutUs?.Trim() ?? "";
        if (name.Length is 0 or > 250 || shortName.Length is 0 or > 100 || about.Length > 12000 ||
            about.Contains('<') || about.Contains('>'))
            return Results.BadRequest(new { message = "Provide valid names and plain-text about content (up to 12,000 characters)." });

        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
            foreach (var (key, value) in new[] { ("name", name), ("short_name", shortName), ("about_us", about) })
            {
                object? settingId;
                await using (var find = new MySqlCommand(
                    "SELECT id FROM system_info WHERE meta_field=@field ORDER BY id LIMIT 1 FOR UPDATE",
                    connection, transaction))
                {
                    find.Parameters.AddWithValue("@field", key);
                    settingId = await find.ExecuteScalarAsync(cancellationToken);
                }
                if (settingId is null)
                {
                    await using var insert = new MySqlCommand(
                        "INSERT INTO system_info (meta_field, meta_value) VALUES (@field, @value)",
                        connection, transaction);
                    insert.Parameters.AddWithValue("@field", key);
                    insert.Parameters.AddWithValue("@value", value);
                    await insert.ExecuteNonQueryAsync(cancellationToken);
                }
                else
                {
                    await using var update = new MySqlCommand(
                        "UPDATE system_info SET meta_value=@value WHERE id=@id", connection, transaction);
                    update.Parameters.AddWithValue("@value", value);
                    update.Parameters.AddWithValue("@id", settingId);
                    await update.ExecuteNonQueryAsync(cancellationToken);
                }
            }
            await transaction.CommitAsync(cancellationToken);
            logger.LogInformation("Public site information was updated by admin {ActorId}.", GetUserId(context));
            return Results.Ok(new { message = "System settings saved." });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not save system settings.");
            return DatabaseUnavailable("System settings could not be saved.");
        }
    }

    private static async Task<IResult> UploadSettingsImageAsync(
        string kind,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        IWebHostEnvironment environment,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsAdmin(context)) return Results.Forbid();
        if (!await HasValidCsrfAsync(context, antiforgery)) return CsrfError();
        if (kind is not ("logo" or "cover" or "banner")) return Results.BadRequest(new { message = "Choose logo, cover, or banner." });

        IFormCollection form;
        if (context.Request.ContentLength is > 52 * 1024 * 1024)
            return Results.BadRequest(new { message = "The upload request is too large." });
        try { form = await context.Request.ReadFormAsync(cancellationToken); }
        catch (InvalidDataException) { return Results.BadRequest(new { message = "Choose a valid image upload." }); }
        var files = form.Files.GetFiles("files");
        if (files.Count == 0) files = form.Files.GetFiles("file");
        if (files.Count == 0 || files.Count > 10 || (kind != "banner" && files.Count != 1))
            return Results.BadRequest(new { message = "Choose one image for a logo or cover, or up to ten banner images." });
        if (files.Any(file => file.Length is <= 0 or > 5 * 1024 * 1024))
            return Results.BadRequest(new { message = "Each image must be smaller than 5 MB." });

        var saved = new List<string>();
        var uploadRoot = GetUploadRoot(environment);
        try
        {
            var subdirectory = kind == "banner" ? "banner" : "";
            var destination = ContainedPath(uploadRoot, Path.Combine(uploadRoot, subdirectory));
            Directory.CreateDirectory(destination);
            if ((System.IO.File.GetAttributes(destination) & FileAttributes.ReparsePoint) != 0)
                return Results.BadRequest(new { message = "The image storage directory is not available." });
            foreach (var file in files)
            {
                var extension = await DetectImageExtensionAsync(file, cancellationToken);
                if (extension is null)
                {
                    foreach (var cleanupPath in saved) System.IO.File.Delete(cleanupPath);
                    return Results.BadRequest(new { message = "Only valid PNG and JPEG images are accepted." });
                }
                var savedPath = ContainedPath(destination, Path.Combine(destination, Guid.NewGuid().ToString("N") + extension));
                await using var stream = new FileStream(savedPath, FileMode.CreateNew, FileAccess.Write, FileShare.None, 81920, true);
                await file.CopyToAsync(stream, cancellationToken);
                saved.Add(savedPath);
            }

            if (kind is "logo" or "cover")
            {
                var publicPath = "uploads/" + Path.GetFileName(saved[0]);
                await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
                await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
                var oldPath = "";
                await using (var lookup = new MySqlCommand(
                    "SELECT meta_value FROM system_info WHERE meta_field=@field ORDER BY id LIMIT 1 FOR UPDATE", connection, transaction))
                {
                    lookup.Parameters.AddWithValue("@field", kind);
                    oldPath = (string?)await lookup.ExecuteScalarAsync(cancellationToken) ?? "";
                }
                object? settingId;
                await using (var find = new MySqlCommand(
                    "SELECT id FROM system_info WHERE meta_field=@field ORDER BY id LIMIT 1 FOR UPDATE",
                    connection, transaction))
                {
                    find.Parameters.AddWithValue("@field", kind);
                    settingId = await find.ExecuteScalarAsync(cancellationToken);
                }
                if (settingId is null)
                {
                    await using var insert = new MySqlCommand(
                        "INSERT INTO system_info (meta_field, meta_value) VALUES (@field,@value)", connection, transaction);
                    insert.Parameters.AddWithValue("@field", kind);
                    insert.Parameters.AddWithValue("@value", publicPath);
                    await insert.ExecuteNonQueryAsync(cancellationToken);
                }
                else
                {
                    await using var update = new MySqlCommand(
                        "UPDATE system_info SET meta_value=@value WHERE id=@id", connection, transaction);
                    update.Parameters.AddWithValue("@value", publicPath);
                    update.Parameters.AddWithValue("@id", settingId);
                    await update.ExecuteNonQueryAsync(cancellationToken);
                }
                await transaction.CommitAsync(cancellationToken);
                DeleteOwnedFile(oldPath, uploadRoot, logger);
            }

            logger.LogInformation("Admin {ActorId} uploaded {ImageKind} image(s).", GetUserId(context), kind);
            var publicPaths = saved.Select(path => "uploads/" +
                (kind == "banner" ? "banner/" : "") + Path.GetFileName(path)).ToArray();
            return Results.Ok(new { message = "Image upload complete.", paths = publicPaths });
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException or MySqlException or InvalidDataException)
        {
            foreach (var path in saved)
            {
                try { System.IO.File.Delete(path); } catch (IOException) { }
            }
            logger.LogError(exception, "Could not upload {ImageKind} image(s).", kind);
            return exception is MySqlException
                ? DatabaseUnavailable("Image settings could not be updated.")
                : Results.Problem(title: "The image could not be saved.", statusCode: StatusCodes.Status500InternalServerError);
        }
    }

    private static async Task<string?> DetectImageExtensionAsync(IFormFile file, CancellationToken cancellationToken)
    {
        if (file.Length is <= 0 or > 5 * 1024 * 1024) return null;
        var bytes = new byte[8];
        await using var stream = file.OpenReadStream();
        var count = await stream.ReadAsync(bytes, cancellationToken);
        if (count >= 8 && bytes.AsSpan().SequenceEqual(new byte[] { 137, 80, 78, 71, 13, 10, 26, 10 }))
        {
            if (file.Length < 20) return null;
            stream.Position = file.Length - 12;
            var trailer = new byte[12];
            if (await stream.ReadAsync(trailer, cancellationToken) == 12 &&
                trailer.AsSpan().SequenceEqual(new byte[] { 0, 0, 0, 0, 73, 69, 78, 68, 174, 66, 96, 130 }))
                return ".png";
            return null;
        }
        if (count >= 3 && bytes[0] == 0xff && bytes[1] == 0xd8 && bytes[2] == 0xff)
        {
            stream.Position = file.Length - 2;
            var trailer = new byte[2];
            if (file.Length >= 4 && await stream.ReadAsync(trailer, cancellationToken) == 2 &&
                trailer[0] == 0xff && trailer[1] == 0xd9)
                return ".jpg";
        }
        return null;
    }

    private static async Task<IResult> DeleteSettingsImageAsync(
        string kind,
        string fileName,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        IWebHostEnvironment environment,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsAdmin(context)) return Results.Forbid();
        if (!await HasValidCsrfAsync(context, antiforgery)) return CsrfError();
        if (kind is not ("logo" or "cover" or "banner") ||
            fileName.Length is 0 or > 80 || fileName != Path.GetFileName(fileName) ||
            !Regex.IsMatch(fileName, @"\A[A-Za-z0-9][A-Za-z0-9._-]{0,74}\.(png|jpe?g)\z", RegexOptions.CultureInvariant))
            return Results.BadRequest(new { message = "Choose a valid uploaded image." });
        var uploadRoot = GetUploadRoot(environment);
        var imageRoot = ContainedPath(uploadRoot, Path.Combine(uploadRoot, kind == "banner" ? "banner" : ""));
        var path = ContainedPath(imageRoot, Path.Combine(imageRoot, fileName));
        try
        {
            if (kind != "banner")
            {
                await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
                await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
                await using var lookup = new MySqlCommand(
                    "SELECT meta_value FROM system_info WHERE meta_field=@field ORDER BY id LIMIT 1 FOR UPDATE", connection, transaction);
                lookup.Parameters.AddWithValue("@field", kind);
                var current = (string?)await lookup.ExecuteScalarAsync(cancellationToken) ?? "";
                if (!string.Equals(Path.GetFileName(current.Split('?', 2)[0]), fileName, StringComparison.Ordinal))
                    return Results.Conflict(new { message = "This image is not the current site image." });
                await using var clear = new MySqlCommand(
                    "DELETE FROM system_info WHERE meta_field=@field", connection, transaction);
                clear.Parameters.AddWithValue("@field", kind);
                await clear.ExecuteNonQueryAsync(cancellationToken);
                await transaction.CommitAsync(cancellationToken);
            }
                if (System.IO.File.Exists(path))
                {
                    if (ContainsReparsePoint(imageRoot, path))
                        return Results.BadRequest(new { message = "The image path is not a regular file." });
                    System.IO.File.Delete(path);
                }
            logger.LogInformation("Admin {ActorId} deleted a {ImageKind} image.", GetUserId(context), kind);
            return Results.NoContent();
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException or MySqlException)
        {
            logger.LogError(exception, "Could not delete {ImageKind} image.", kind);
            return exception is MySqlException
                ? DatabaseUnavailable("Image could not be removed.")
                : Results.Problem(title: "Image could not be removed.", statusCode: StatusCodes.Status500InternalServerError);
        }
    }

    private static string[] ListBannerNames(IWebHostEnvironment environment)
    {
        var root = GetUploadRoot(environment);
        var directory = ContainedPath(root, Path.Combine(root, "banner"));
        try
        {
            if (!Directory.Exists(directory) ||
                (System.IO.File.GetAttributes(directory) & FileAttributes.ReparsePoint) != 0) return [];
            return Directory.EnumerateFiles(directory)
                .Where(file => !ContainsReparsePoint(directory, file))
                .Select(Path.GetFileName)
                .Where(name => name is not null &&
                    Regex.IsMatch(name, @"\A[A-Za-z0-9][A-Za-z0-9._-]{0,74}\.(png|jpe?g)\z", RegexOptions.CultureInvariant))
                .Cast<string>()
                .Order(StringComparer.Ordinal)
                .Take(100)
                .ToArray();
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            return [];
        }
    }

    private static string GetUploadRoot(IWebHostEnvironment environment)
    {
        var workspace = Path.GetFullPath("..", environment.ContentRootPath);
        var root = ContainedPath(workspace, Path.Combine(workspace, "uploads"));
        if (Directory.Exists(root) && (System.IO.File.GetAttributes(root) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("Image storage path is not a regular directory.");
        return root;
    }

    private static string SafeAssetRef(string value)
    {
        var path = value.Split('?', 2)[0].Replace('\\', '/');
        if (!path.StartsWith("uploads/", StringComparison.Ordinal) ||
            path.Split('/').Any(part => part is "" or "." or "..") ||
            !Regex.IsMatch(path, @"\Auploads/[A-Za-z0-9._/-]+\.(png|jpe?g)\z", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant))
            return "";
        return path;
    }

    private static string ContainedPath(string root, string path)
    {
        var fullRoot = Path.GetFullPath(root);
        var fullPath = Path.GetFullPath(path);
        var relative = Path.GetRelativePath(fullRoot, fullPath);
        if (Path.IsPathRooted(relative) || relative == ".." ||
            relative.StartsWith(".." + Path.DirectorySeparatorChar, StringComparison.Ordinal) ||
            relative.StartsWith(".." + Path.AltDirectorySeparatorChar, StringComparison.Ordinal))
            throw new InvalidDataException("Path is outside its permitted directory.");
        return fullPath;
    }

    private static bool ContainsReparsePoint(string root, string path)
    {
        var current = Path.GetFullPath(root);
        var relative = Path.GetRelativePath(current, Path.GetFullPath(path));
        if (relative == ".")
            return (System.IO.File.GetAttributes(current) & FileAttributes.ReparsePoint) != 0;
        foreach (var part in relative.Split([Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar],
                     StringSplitOptions.RemoveEmptyEntries))
        {
            current = Path.Combine(current, part);
            if ((System.IO.File.Exists(current) || Directory.Exists(current)) &&
                (System.IO.File.GetAttributes(current) & FileAttributes.ReparsePoint) != 0)
                return true;
        }
        return false;
    }

    private static void DeleteOwnedFile(string storedPath, string uploadRoot, ILogger logger)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(storedPath)) return;
            var relative = storedPath.Split('?', 2)[0].Replace('/', Path.DirectorySeparatorChar);
            if (!relative.StartsWith("uploads" + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase)) return;
            var path = ContainedPath(uploadRoot, Path.GetFullPath(Path.Combine(Directory.GetParent(uploadRoot)!.FullName, relative)));
            if (System.IO.File.Exists(path) && !ContainsReparsePoint(uploadRoot, path))
                System.IO.File.Delete(path);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException or InvalidDataException)
        {
            logger.LogWarning(exception, "Could not remove a previous site image after replacing it.");
        }
    }

    private static async Task<IResult> RequestPasswordResetAsync(
        PasswordResetRequest request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        IConfiguration configuration,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!await HasValidCsrfAsync(context, antiforgery))
            return Results.BadRequest(new { message = "Refresh the page and try again." });
        var ipKey = RateKey("ip", context.Connection.RemoteIpAddress?.ToString() ?? "unknown");
        var identifier = request.Identifier?.Trim() ?? "";
        if (!TryConsumeRate(ipKey, 5, TimeSpan.FromMinutes(15)) ||
            (identifier.Length > 0 && identifier.Length <= 254 &&
             !TryConsumeRate(RateKey("id", identifier.ToUpperInvariant()), 3, TimeSpan.FromHours(1))))
            return Results.Ok(new { message = GenericResetMessage });
        if (identifier.Length is 0 or > 254) return Results.Ok(new { message = GenericResetMessage });

        var smtp = SmtpSettings.Read(logger);
        var publicBase = configuration["OCWBS_PUBLIC_BASE_URL"]?.TrimEnd('/');
        if (smtp is null || !IsSafePublicBaseUrl(publicBase))
        {
            logger.LogWarning("Admin password reset is unavailable because SMTP or public URL settings are not configured.");
            return Results.Ok(new { message = GenericResetMessage });
        }

        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using (var cleanup = new MySqlCommand(
                "DELETE FROM admin_password_resets WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)",
                connection))
                await cleanup.ExecuteNonQueryAsync(cancellationToken);
            int userId;
            string email;
            await using (var find = new MySqlCommand("""
                SELECT id, email FROM users WHERE (username=@identifier OR email=@identifier) AND type IN (1,2)
                LIMIT 1
                """, connection))
            {
                find.Parameters.AddWithValue("@identifier", identifier);
                await using var reader = await find.ExecuteReaderAsync(cancellationToken);
                if (!await reader.ReadAsync(cancellationToken)) return Results.Ok(new { message = GenericResetMessage });
                userId = reader.GetInt32("id");
                email = reader.IsDBNull(reader.GetOrdinal("email")) ? "" : reader.GetString("email");
            }
            if (!IsValidEmail(email)) return Results.Ok(new { message = GenericResetMessage });

            await using (var rate = new MySqlCommand("""
                SELECT COUNT(*) FROM admin_password_resets
                WHERE user_id=@id AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                """, connection))
            {
                rate.Parameters.AddWithValue("@id", userId);
                if (Convert.ToInt32(await rate.ExecuteScalarAsync(cancellationToken), CultureInfo.InvariantCulture) >= 3)
                    return Results.Ok(new { message = GenericResetMessage });
            }

            var token = Convert.ToHexStringLower(RandomNumberGenerator.GetBytes(32));
            var tokenHash = Convert.ToHexStringLower(SHA256.HashData(Encoding.UTF8.GetBytes(token)));
            await using (var create = new MySqlCommand("""
                INSERT INTO admin_password_resets (user_id, token_hash, expires_at)
                VALUES (@userId, @tokenHash, DATE_ADD(NOW(), INTERVAL 1 HOUR))
                """, connection))
            {
                create.Parameters.AddWithValue("@userId", userId);
                create.Parameters.AddWithValue("@tokenHash", tokenHash);
                await create.ExecuteNonQueryAsync(cancellationToken);
            }
            await using (var invalidatePrevious = new MySqlCommand("""
                UPDATE admin_password_resets SET expires_at=NOW()
                WHERE user_id=@userId AND token_hash<>@tokenHash AND expires_at>NOW()
                """, connection))
            {
                invalidatePrevious.Parameters.AddWithValue("@userId", userId);
                invalidatePrevious.Parameters.AddWithValue("@tokenHash", tokenHash);
                await invalidatePrevious.ExecuteNonQueryAsync(cancellationToken);
            }

            var link = $"{publicBase}/admin-password-reset.html?token={Uri.EscapeDataString(token)}";
            try
            {
                await smtp.SendAsync(email, link, cancellationToken);
            }
            catch (Exception exception) when (exception is SmtpException or InvalidOperationException or OperationCanceledException)
            {
                logger.LogWarning(exception, "Admin password reset email delivery failed for user {UserId}.", userId);
                await using var invalidate = new MySqlCommand(
                    "UPDATE admin_password_resets SET expires_at=NOW() WHERE token_hash=@hash", connection);
                invalidate.Parameters.AddWithValue("@hash", tokenHash);
                await invalidate.ExecuteNonQueryAsync(CancellationToken.None);
            }
            return Results.Ok(new { message = GenericResetMessage });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Admin password reset request could not be processed.");
            return Results.Ok(new { message = GenericResetMessage });
        }
    }

    private static async Task<IResult> ResetPasswordAsync(
        PasswordResetSubmitRequest request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!await HasValidCsrfAsync(context, antiforgery))
            return Results.BadRequest(new { message = "Refresh the page and try again." });
        var ipKey = RateKey("reset-ip", context.Connection.RemoteIpAddress?.ToString() ?? "unknown");
        if (!TryConsumeRate(ipKey, 10, TimeSpan.FromMinutes(15)))
            return Results.StatusCode(StatusCodes.Status429TooManyRequests);
        var token = request.Token ?? "";
        var password = request.Password ?? "";
        if (!Regex.IsMatch(token, @"\A[a-f0-9]{64}\z", RegexOptions.CultureInvariant) ||
            !IsStrongPassword(password) || !string.Equals(password, request.ConfirmPassword, StringComparison.Ordinal))
            return Results.BadRequest(new { message = "Use a matching password of at least 12 characters (maximum 72 UTF-8 bytes)." });
        var hash = BCrypt.Net.BCrypt.HashPassword(password);
        var tokenHash = Convert.ToHexStringLower(SHA256.HashData(Encoding.UTF8.GetBytes(token)));
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
            int userId;
            await using (var lookup = new MySqlCommand("""
                SELECT r.user_id FROM admin_password_resets r
                INNER JOIN users u ON u.id=r.user_id AND u.type IN (1,2)
                WHERE r.token_hash=@hash AND r.expires_at>NOW()
                ORDER BY r.id DESC LIMIT 1 FOR UPDATE
                """, connection, transaction))
            {
                lookup.Parameters.AddWithValue("@hash", tokenHash);
                var value = await lookup.ExecuteScalarAsync(cancellationToken);
                if (value is null) return Results.BadRequest(new { message = "This reset link is invalid or expired. Request a new one." });
                userId = Convert.ToInt32(value, CultureInfo.InvariantCulture);
            }
            await using (var update = new MySqlCommand(
                "UPDATE users SET password=@password WHERE id=@id AND type IN (1,2)", connection, transaction))
            {
                update.Parameters.AddWithValue("@password", hash);
                update.Parameters.AddWithValue("@id", userId);
                if (await update.ExecuteNonQueryAsync(cancellationToken) != 1)
                    return Results.BadRequest(new { message = "This reset link is invalid or expired. Request a new one." });
            }
            await using (var delete = new MySqlCommand(
                "DELETE FROM admin_password_resets WHERE user_id=@id", connection, transaction))
            {
                delete.Parameters.AddWithValue("@id", userId);
                await delete.ExecuteNonQueryAsync(cancellationToken);
            }
            await transaction.CommitAsync(cancellationToken);
            logger.LogInformation("Admin password reset completed for user {UserId}.", userId);
            return Results.Ok(new { message = "Password changed. Sign in with the new password." });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Admin password reset failed.");
            return DatabaseUnavailable("Password reset is temporarily unavailable.");
        }
    }

    private static bool TryConsumeRate(string key, int limit, TimeSpan period)
    {
        var now = DateTimeOffset.UtcNow;
        var window = ResetRate.AddOrUpdate(key,
            _ => new RateWindow(now, 1),
            (_, old) => now - old.Start >= period ? new RateWindow(now, 1) : old with { Count = old.Count + 1 });
        if (ResetRate.Count > 20_000)
        {
            foreach (var item in ResetRate)
                if (now - item.Value.Start > TimeSpan.FromHours(2)) ResetRate.TryRemove(item.Key, out _);
        }
        return now - window.Start < period && window.Count <= limit;
    }

    private static string RateKey(string scope, string value) =>
        scope + ":" + Convert.ToHexStringLower(SHA256.HashData(Encoding.UTF8.GetBytes(value)));

    private static bool IsSafePublicBaseUrl(string? value)
    {
        if (!Uri.TryCreate(value, UriKind.Absolute, out var uri)) return false;
        return (uri.Scheme == Uri.UriSchemeHttps || (uri.Scheme == Uri.UriSchemeHttp && uri.IsLoopback)) &&
            string.IsNullOrEmpty(uri.UserInfo) && string.IsNullOrEmpty(uri.Query) && string.IsNullOrEmpty(uri.Fragment);
    }

    private static bool IsStrongPassword(string value) =>
        value.Length >= 12 && Encoding.UTF8.GetByteCount(value) <= 72;

    private static bool IsValidEmail(string email) =>
        email.Length == 0 || (email.Length <= 254 && MailAddress.TryCreate(email, out var parsed) &&
            string.Equals(parsed.Address, email, StringComparison.OrdinalIgnoreCase));

    private static bool IsAdmin(HttpContext context) => Role(context) == "admin";
    private static bool IsBookingStaff(HttpContext context) => Role(context) is "admin" or "staff";
    private static string? Role(HttpContext context) => context.User.FindFirstValue(ClaimTypes.Role);

    private static int GetUserId(HttpContext context) =>
        int.TryParse(context.User.FindFirstValue(ClaimTypes.NameIdentifier), NumberStyles.None,
            CultureInfo.InvariantCulture, out var value) ? value : 0;

    private static async Task<bool> HasValidCsrfAsync(HttpContext context, IAntiforgery antiforgery)
    {
        try { return await antiforgery.IsRequestValidAsync(context); }
        catch (AntiforgeryValidationException) { return false; }
    }

    private static IResult CsrfError() =>
        Results.BadRequest(new { message = "Refresh the page and try again." });

    private static IResult DatabaseUnavailable(string title) =>
        Results.Problem(title: title, statusCode: StatusCodes.Status503ServiceUnavailable);

    private static DateTimeOffset AsManilaOffset(DateTime value) =>
        new(DateTime.SpecifyKind(value, DateTimeKind.Unspecified), TimeSpan.FromHours(8));

    private static DateTimeOffset? DbOffset(MySqlDataReader reader, string column) =>
        reader.IsDBNull(reader.GetOrdinal(column)) ? null : AsManilaOffset(reader.GetDateTime(column));

    private static DateTimeOffset? DbUtcOffset(MySqlDataReader reader, string column)
    {
        if (reader.IsDBNull(reader.GetOrdinal(column))) return null;
        var utc = DateTime.SpecifyKind(reader.GetDateTime(column), DateTimeKind.Utc);
        return new DateTimeOffset(utc);
    }

    private static string DbString(MySqlDataReader reader, string column) =>
        reader.IsDBNull(reader.GetOrdinal(column)) ? "" : reader.GetString(column);

    private static string BookingStatusLabel(int status) => status switch
    {
        0 => "Pending",
        1 => "Confirmed",
        2 or 3 => "In progress",
        4 => "Completed",
        5 => "Cancelled",
        _ => "Unknown"
    };

    private static string PublicPaymentMethod(string method) => method switch
    {
        "demo_online" or "paymongo_qrph" => "Demo online (no real payment)",
        "stripe" => "Stripe test (no real payment)",
        "gcash" or "maya" => method.ToUpperInvariant() + " transfer reference submitted",
        "cash" => "Cashier",
        _ => method
    };

    private static async Task<bool> AcquireNamedLockAsync(MySqlConnection connection, string name, CancellationToken cancellationToken)
    {
        await using var command = new MySqlCommand("SELECT GET_LOCK(@name, 5)", connection);
        command.Parameters.AddWithValue("@name", name);
        return Convert.ToInt32(await command.ExecuteScalarAsync(cancellationToken), CultureInfo.InvariantCulture) == 1;
    }

    private static async Task ReleaseNamedLockAsync(MySqlConnection connection, string name, ILogger logger)
    {
        try
        {
            await using var command = new MySqlCommand("SELECT RELEASE_LOCK(@name)", connection);
            command.Parameters.AddWithValue("@name", name);
            await command.ExecuteScalarAsync(CancellationToken.None);
        }
        catch (MySqlException exception) { logger.LogError(exception, "Could not release admin-operation lock."); }
    }

    private static string ScheduleLockName(DateTime schedule)
    {
        var text = schedule.ToString("yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture);
        var hash = Convert.ToHexStringLower(SHA256.HashData(Encoding.UTF8.GetBytes(text)))[..32];
        return "ocwbs_" + hash;
    }

    private sealed record RateWindow(DateTimeOffset Start, int Count);
    private sealed record SiteSettings(string Name, string ShortName, string AboutUs, string Logo, string Cover);
    private sealed record ReportRow(
        string Code, string Customer, string Email, string Contact, DateTimeOffset BookedAt, DateTimeOffset Schedule,
        string Vehicle, string Plate, string StatusLabel, string PaymentStatus, string ReservationFeeStatus,
        string ReservationFeeMethod, string ReservationFeeReference, string RefundReference, string PaymentMethod,
        decimal Total, decimal ReservationFee, decimal AmountPaid, DateTimeOffset? PaidAt, string ReceivedBy);

    private sealed record UserRequest(int Id, string? FirstName, string? LastName, string? Username,
        string? Email, string? Password, string? Role);
    private sealed record SiteSettingsRequest(string? Name, string? ShortName, string? AboutUs);
    private sealed record ReservationFeeDecision(string? RefundReference);
    private sealed record PasswordResetRequest(string? Identifier);
    private sealed record PasswordResetSubmitRequest(string? Token, string? Password, string? ConfirmPassword);

    private sealed class SmtpSettings
    {
        private readonly string _host;
        private readonly int _port;
        private readonly string _username;
        private readonly string _password;
        private readonly bool _enableTls;
        private readonly string _from;

        private SmtpSettings(string host, int port, string username, string password, bool enableTls, string from)
        {
            _host = host;
            _port = port;
            _username = username;
            _password = password;
            _enableTls = enableTls;
            _from = from;
        }

        internal static SmtpSettings? Read(ILogger logger)
        {
            var host = Environment.GetEnvironmentVariable("OCWBS_SMTP_HOST") ?? "";
            var portText = Environment.GetEnvironmentVariable("OCWBS_SMTP_PORT") ?? "";
            var username = Environment.GetEnvironmentVariable("OCWBS_SMTP_USERNAME") ?? "";
            var password = Environment.GetEnvironmentVariable("OCWBS_SMTP_PASSWORD") ?? "";
            var encryption = (Environment.GetEnvironmentVariable("OCWBS_SMTP_ENCRYPTION") ?? "").ToLowerInvariant();
            var from = Environment.GetEnvironmentVariable("OCWBS_MAIL_FROM") ?? "";
            if (host.Length is 0 or > 255 || host.Any(char.IsControl) ||
                !int.TryParse(portText, NumberStyles.None, CultureInfo.InvariantCulture, out var port) ||
                port is < 1 or > 65535 || encryption is not ("tls" or "none") ||
                from.Length == 0 || !IsValidEmail(from) || (username.Length > 0) != (password.Length > 0))
            {
                logger.LogWarning("Admin password reset SMTP settings are incomplete or invalid.");
                return null;
            }
            return new SmtpSettings(host, port, username, password, encryption == "tls", from);
        }

        internal async Task SendAsync(string email, string link, CancellationToken cancellationToken)
        {
            using var message = new MailMessage(_from, email)
            {
                Subject = "Password reset request",
                Body = "We received a request to reset your administrator password.\r\n\r\n" +
                    "Use this one-time link within one hour:\r\n" + link +
                    "\r\n\r\nIf you did not request this change, ignore this email."
            };
            using var client = new SmtpClient(_host, _port)
            {
                EnableSsl = _enableTls,
                DeliveryMethod = SmtpDeliveryMethod.Network,
                Timeout = 15000
            };
            if (_username.Length > 0)
                client.Credentials = new NetworkCredential(_username, _password);
            await client.SendMailAsync(message, cancellationToken);
        }
    }
}
