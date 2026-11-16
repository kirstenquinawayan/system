using System.Diagnostics;
using System.Globalization;
using System.Net;
using System.Net.Mail;
using System.Security.Claims;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Antiforgery;
using MySqlConnector;

internal static class CustomerEndpoints
{
    public const string AuthenticationScheme = "CustomerCookie";
    public const string AuthorizationPolicy = "Customer";
    private const int BcryptWorkFactor = 12;
    private const int MinimumPasswordLength = 12;
    private const int MaximumPasswordLength = 1024;
    private static readonly string FakePasswordHash = BCrypt.Net.BCrypt.HashPassword(
        "invalid-customer-login-placeholder",
        BcryptWorkFactor);
    private static readonly object RateLimitLock = new();
    private static readonly Dictionary<string, RateWindow> RateLimits = new(StringComparer.Ordinal);
    private static readonly HttpClient SemaphoreClient = new()
    {
        BaseAddress = new Uri("https://api.semaphore.co/api/v4/"),
        Timeout = TimeSpan.FromSeconds(15)
    };

    public static void MapCustomerEndpoints(this WebApplication app)
    {
        app.MapGet("/api/customer/csrf", (HttpContext context, IAntiforgery antiforgery) =>
        {
            var tokens = antiforgery.GetAndStoreTokens(context);
            return Results.Ok(new { token = tokens.RequestToken });
        })
            .WithName("GetCustomerCsrfToken")
            .WithSummary("Issue a request-verification token for customer forms");

        app.MapGet("/api/customer/session", (Delegate)GetSessionAsync)
            .WithName("GetCustomerSession")
            .WithSummary("Return the current customer session");

        app.MapPost("/api/customer/register", RegisterAsync)
            .WithName("RegisterCustomer")
            .WithSummary("Create a customer account and sign in");

        app.MapPost("/api/customer/login", LoginAsync)
            .WithName("LoginCustomer")
            .WithSummary("Sign in a customer");

        app.MapPost("/api/customer/recovery", RequestRecoveryAsync)
            .WithName("RequestCustomerRecovery")
            .WithSummary("Send a one-use password recovery link");

        app.MapPost("/api/customer/recovery/sms", RequestSmsRecoveryAsync)
            .WithName("RequestCustomerSmsRecovery")
            .WithSummary("Send a one-time password recovery code by SMS");

        app.MapPost("/api/customer/recovery/sms/complete", CompleteSmsRecoveryAsync)
            .WithName("CompleteCustomerSmsRecovery")
            .WithSummary("Reset a customer password with a one-time SMS code");

        app.MapPost("/api/customer/recovery/complete", CompleteRecoveryAsync)
            .WithName("CompleteCustomerRecovery")
            .WithSummary("Set a new password with a one-use recovery token");

        var customer = app.MapGroup("/api/customer")
            .RequireAuthorization(AuthorizationPolicy);

        customer.MapPost("/logout", LogoutAsync)
            .WithName("LogoutCustomer")
            .WithSummary("Sign out the current customer");

        customer.MapGet("/bookings", GetBookingsAsync)
            .WithName("GetCustomerBookings")
            .WithSummary("List bookings linked to the signed-in customer");

        customer.MapGet("/bookings/{bookingId:int}", GetBookingAsync)
            .WithName("GetCustomerBooking")
            .WithSummary("Get details for a booking linked to the signed-in customer");

        customer.MapPost("/bookings/{bookingId:int}/dismiss", DismissBookingAsync)
            .WithName("DismissCustomerBooking")
            .WithSummary("Hide a booking from the signed-in customer's booking history");

        customer.MapGet("/notifications", GetNotificationsAsync)
            .WithName("GetCustomerNotifications")
            .WithSummary("List the signed-in customer's booking notifications");

        customer.MapPost("/notifications/{notificationId:long}/read", MarkNotificationReadAsync)
            .WithName("MarkCustomerNotificationRead")
            .WithSummary("Mark an owned notification as read");

        customer.MapPost("/notifications/{notificationId:long}/dismiss", DismissNotificationAsync)
            .WithName("DismissCustomerNotification")
            .WithSummary("Dismiss an owned notification");

        customer.MapPost("/password", ChangePasswordAsync)
            .WithName("ChangeCustomerPassword")
            .WithSummary("Change the signed-in customer's password");

        customer.MapGet("/recovery/admin-status", GetAdminRecoveryStatusAsync)
            .WithName("GetCustomerAdminRecoveryStatus")
            .WithSummary("Return the customer's latest admin password-reset approval status");

        customer.MapPost("/recovery/admin-request", RequestAdminRecoveryApprovalAsync)
            .WithName("RequestCustomerAdminRecovery")
            .WithSummary("Request administrator approval for a customer password reset");

        customer.MapPost("/recovery/admin-complete", CompleteAdminRecoveryAsync)
            .WithName("CompleteCustomerAdminRecovery")
            .WithSummary("Reset the customer's password during an approved recovery window");
    }

    private static async Task<IResult> GetSessionAsync(HttpContext context)
    {
        var authentication = await context.AuthenticateAsync(AuthenticationScheme);
        if (!authentication.Succeeded || authentication.Principal is null)
        {
            return Results.Ok(new { authenticated = false });
        }

        return Results.Ok(new
        {
            authenticated = true,
            name = authentication.Principal.FindFirstValue(ClaimTypes.Name),
            email = authentication.Principal.FindFirstValue(ClaimTypes.Email)
        });
    }

    private static async Task<IResult> RegisterAsync(
        CustomerRegistrationRequest request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        CancellationToken cancellationToken)
    {
        if (!await HasValidCsrfAsync(context, antiforgery))
        {
            return CsrfError();
        }

        if (!ConsumeRateLimit(context, "register", 8, TimeSpan.FromHours(1)))
        {
            return Results.StatusCode(StatusCodes.Status429TooManyRequests);
        }

        var fullName = request.FullName?.Trim() ?? string.Empty;
        var email = NormalizeEmail(request.Email);
        var contact = request.Contact?.Trim() ?? string.Empty;
        var password = request.Password ?? string.Empty;
        if (fullName.Length is 0 or > 250 ||
            !IsValidEmail(email) ||
            contact.Length > 32 ||
            !IsValidPassword(password))
        {
            return Results.BadRequest(new { message = "Enter a valid name, email, contact number, and a password of 12 or more characters (maximum 72 UTF-8 bytes)." });
        }

        var passwordHash = BCrypt.Net.BCrypt.HashPassword(password, BcryptWorkFactor);
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var command = new MySqlCommand("""
                INSERT INTO customer_accounts (full_name, email, contact, password_hash)
                VALUES (@fullName, @email, @contact, @passwordHash)
                """, connection);
            command.Parameters.AddWithValue("@fullName", fullName);
            command.Parameters.AddWithValue("@email", email);
            command.Parameters.AddWithValue("@contact", contact);
            command.Parameters.AddWithValue("@passwordHash", passwordHash);
            await command.ExecuteNonQueryAsync(cancellationToken);
            var customerId = checked((int)command.LastInsertedId);

            await SignInAsync(context, customerId, fullName, email);
            return Results.Created("/api/customer/session", new { authenticated = true, name = fullName, email });
        }
        catch (MySqlException exception) when (exception.Number == 1062)
        {
            return Results.Conflict(new { message = "An account with this email address already exists." });
        }
        catch (MySqlException)
        {
            return Results.Problem(
                title: "Account registration is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> LoginAsync(
        CustomerLoginRequest request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        CancellationToken cancellationToken)
    {
        var started = Stopwatch.GetTimestamp();
        if (!await HasValidCsrfAsync(context, antiforgery))
        {
            return CsrfError();
        }

        var email = NormalizeEmail(request.Email);
        var password = request.Password ?? string.Empty;
        var withinLimit = ConsumeRateLimit(context, "login", 20, TimeSpan.FromMinutes(15)) &&
            ConsumeIdentityRateLimit("login", email, 10, TimeSpan.FromMinutes(15));
        if (!IsValidEmail(email) || !IsValidPassword(password) || !withinLimit)
        {
            _ = BCrypt.Net.BCrypt.Verify(password.Length > MaximumPasswordLength ? string.Empty : password, FakePasswordHash);
            await PadResponseTimeAsync(started, cancellationToken);
            return LoginFailed();
        }

        try
        {
            string? storedHash = null;
            string? fullName = null;
            var customerId = 0;
            await using (var connection = await dataSource.OpenConnectionAsync(cancellationToken))
            await using (var command = new MySqlCommand("""
                SELECT id, full_name, password_hash
                FROM customer_accounts WHERE email = @email LIMIT 1
                """, connection))
            {
                command.Parameters.AddWithValue("@email", email);
                await using var reader = await command.ExecuteReaderAsync(cancellationToken);
                if (await reader.ReadAsync(cancellationToken))
                {
                    customerId = reader.GetInt32("id");
                    fullName = reader.GetString("full_name");
                    storedHash = reader.GetString("password_hash");
                }
            }

            var passwordMatches = VerifyPassword(password, storedHash ?? FakePasswordHash);
            await PadResponseTimeAsync(started, cancellationToken);
            if (!passwordMatches || storedHash is null || fullName is null)
            {
                return LoginFailed();
            }

            await SignInAsync(context, customerId, fullName, email);
            return Results.Ok(new { authenticated = true, name = fullName, email });
        }
        catch (MySqlException)
        {
            return Results.Problem(
                title: "Sign-in is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> RequestRecoveryAsync(
        CustomerRecoveryRequest request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        var started = Stopwatch.GetTimestamp();
        if (!await HasValidCsrfAsync(context, antiforgery))
        {
            return CsrfError();
        }

        var email = NormalizeEmail(request.Email);
        var withinLimit = ConsumeRateLimit(context, "recovery", 5, TimeSpan.FromHours(1)) &&
            ConsumeIdentityRateLimit("recovery", email, 3, TimeSpan.FromHours(1));
        if (withinLimit && IsValidEmail(email) && CanSendRecoveryEmail())
        {
            try
            {
                string? fullName = null;
                var customerId = 0;
                await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
                await using (var lookup = new MySqlCommand(
                    "SELECT id, full_name FROM customer_accounts WHERE email=@email LIMIT 1",
                    connection))
                {
                    lookup.Parameters.AddWithValue("@email", email);
                    await using var reader = await lookup.ExecuteReaderAsync(cancellationToken);
                    if (await reader.ReadAsync(cancellationToken))
                    {
                        customerId = reader.GetInt32("id");
                        fullName = reader.GetString("full_name");
                    }
                }

                if (customerId > 0 && fullName is not null)
                {
                    var token = Convert.ToHexString(RandomNumberGenerator.GetBytes(32)).ToLowerInvariant();
                    var tokenHash = HashToken(token);
                    await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
                    await using (var revoke = new MySqlCommand("""
                        UPDATE customer_password_resets
                        SET used_at=UTC_TIMESTAMP()
                        WHERE customer_id=@customerId AND used_at IS NULL
                        """, connection, transaction))
                    {
                        revoke.Parameters.AddWithValue("@customerId", customerId);
                        await revoke.ExecuteNonQueryAsync(cancellationToken);
                    }

                    await using (var insert = new MySqlCommand("""
                        INSERT INTO customer_password_resets
                            (customer_id, method, token_hash, expires_at)
                        VALUES (@customerId, 'email', @tokenHash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))
                        """, connection, transaction))
                    {
                        insert.Parameters.AddWithValue("@customerId", customerId);
                        insert.Parameters.AddWithValue("@tokenHash", tokenHash);
                        await insert.ExecuteNonQueryAsync(cancellationToken);
                    }

                    await transaction.CommitAsync(cancellationToken);
                    var resetUrl = BuildRecoveryUrl(token);
                    context.Response.OnCompleted(() =>
                        SendRecoveryEmailAsync(email, fullName, resetUrl, logger));
                }
            }
            catch (MySqlException)
            {
                logger.LogWarning("A customer recovery request could not be prepared.");
            }
        }
        else if (!CanSendRecoveryEmail())
        {
            logger.LogWarning("Customer recovery email is unavailable because its environment configuration is incomplete.");
        }

        await PadResponseTimeAsync(started, cancellationToken);
        return Results.Accepted(value: new { message = RecoveryMessage });
    }

    private static async Task<IResult> RequestSmsRecoveryAsync(
        CustomerSmsRecoveryRequest request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        var started = Stopwatch.GetTimestamp();
        if (!await HasValidCsrfAsync(context, antiforgery))
        {
            return CsrfError();
        }

        var contact = request.Contact?.Trim() ?? string.Empty;
        var apiKey = Environment.GetEnvironmentVariable("SEMAPHORE_API_KEY")?.Trim();
        if (string.IsNullOrEmpty(apiKey))
        {
            logger.LogWarning("Customer SMS recovery is unavailable because its provider is not configured.");
            return Results.Problem(
                title: "SMS password recovery is not configured.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }

        var withinLimit = ConsumeRateLimit(context, "recovery-sms", 5, TimeSpan.FromHours(1)) &&
            ConsumeIdentityRateLimit("recovery-sms", contact, 3, TimeSpan.FromHours(1));
        if (!withinLimit || !IsValidMobileNumber(contact))
        {
            await PadResponseTimeAsync(started, cancellationToken);
            return Results.Accepted(value: new { message = SmsRecoveryMessage });
        }

        int customerId;
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var lookup = new MySqlCommand("""
                SELECT id
                FROM customer_accounts
                WHERE contact=@contact
                ORDER BY id
                LIMIT 2
                """, connection);
            lookup.Parameters.AddWithValue("@contact", contact);
            await using var reader = await lookup.ExecuteReaderAsync(cancellationToken);
            if (!await reader.ReadAsync(cancellationToken))
            {
                await PadResponseTimeAsync(started, cancellationToken);
                return Results.Accepted(value: new { message = SmsRecoveryMessage });
            }

            customerId = reader.GetInt32("id");
            if (await reader.ReadAsync(cancellationToken))
            {
                await PadResponseTimeAsync(started, cancellationToken);
                return Results.Accepted(value: new { message = SmsRecoveryMessage });
            }
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not look up a customer SMS recovery request.");
            return Results.Problem(
                title: "SMS password recovery is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }

        if (!ConsumeIdentityRateLimit("recovery-sms-customer", customerId.ToString(CultureInfo.InvariantCulture), 3, TimeSpan.FromHours(1)))
        {
            await PadResponseTimeAsync(started, cancellationToken);
            return Results.Accepted(value: new { message = SmsRecoveryMessage });
        }

        var code = RandomNumberGenerator.GetInt32(100_000, 1_000_000).ToString("D6", CultureInfo.InvariantCulture);
        var otpHash = BCrypt.Net.BCrypt.HashPassword(code, BcryptWorkFactor);
        long resetId;
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
            await using (var revoke = new MySqlCommand("""
                UPDATE customer_password_resets
                SET used_at=UTC_TIMESTAMP()
                WHERE customer_id=@customerId AND used_at IS NULL
                """, connection, transaction))
            {
                revoke.Parameters.AddWithValue("@customerId", customerId);
                await revoke.ExecuteNonQueryAsync(cancellationToken);
            }

            await using (var insert = new MySqlCommand("""
                INSERT INTO customer_password_resets
                    (customer_id, method, otp_hash, expires_at)
                VALUES (@customerId, 'sms', @otpHash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))
                """, connection, transaction))
            {
                insert.Parameters.AddWithValue("@customerId", customerId);
                insert.Parameters.AddWithValue("@otpHash", otpHash);
                await insert.ExecuteNonQueryAsync(cancellationToken);
                resetId = insert.LastInsertedId;
            }

            await transaction.CommitAsync(cancellationToken);
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not create an SMS password recovery record for customer {CustomerId}.", customerId);
            return Results.Problem(
                title: "SMS password recovery is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }

        var phoneNumber = "63" + contact[1..];
        var message = $"Your Kwacky-Kleen password reset code is {code}. It expires in 10 minutes. Do not share this code.";
        if (!await SendSmsAsync(apiKey, phoneNumber, message, logger, cancellationToken))
        {
            await InvalidateCustomerResetAsync(dataSource, resetId, customerId, logger);
            return Results.Problem(
                title: "The SMS recovery code could not be sent.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }

        await PadResponseTimeAsync(started, cancellationToken);
        return Results.Accepted(value: new { message = SmsRecoveryMessage });
    }

    private static async Task<IResult> CompleteSmsRecoveryAsync(
        CustomerSmsCompleteRecoveryRequest request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!await HasValidCsrfAsync(context, antiforgery))
        {
            return CsrfError();
        }

        var contact = request.Contact?.Trim() ?? string.Empty;
        var code = request.Code?.Trim() ?? string.Empty;
        var password = request.Password ?? string.Empty;
        var confirmation = request.ConfirmPassword ?? string.Empty;
        if (!IsValidMobileNumber(contact) ||
            code.Length != 6 ||
            !code.All(char.IsAsciiDigit) ||
            !IsValidPassword(password) ||
            !string.Equals(password, confirmation, StringComparison.Ordinal))
        {
            return Results.BadRequest(new { message = "Enter the mobile number, 6-digit code, and matching new passwords." });
        }

        if (!ConsumeRateLimit(context, "recovery-sms-complete", 10, TimeSpan.FromMinutes(15)) ||
            !ConsumeIdentityRateLimit("recovery-sms-complete", contact, 10, TimeSpan.FromMinutes(15)))
        {
            return Results.StatusCode(StatusCodes.Status429TooManyRequests);
        }

        var passwordHash = BCrypt.Net.BCrypt.HashPassword(password, BcryptWorkFactor);
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
            int customerId;
            await using (var accountLookup = new MySqlCommand("""
                SELECT id
                FROM customer_accounts
                WHERE contact=@contact
                ORDER BY id
                LIMIT 2
                """, connection, transaction))
            {
                accountLookup.Parameters.AddWithValue("@contact", contact);
                await using var reader = await accountLookup.ExecuteReaderAsync(cancellationToken);
                if (!await reader.ReadAsync(cancellationToken))
                {
                    await transaction.RollbackAsync(cancellationToken);
                    return SmsCodeInvalid();
                }

                customerId = reader.GetInt32("id");
                if (await reader.ReadAsync(cancellationToken))
                {
                    await transaction.RollbackAsync(cancellationToken);
                    return SmsCodeInvalid();
                }
            }

            long resetId;
            string? otpHash;
            int attempts;
            await using (var lookup = new MySqlCommand("""
                SELECT id, otp_hash, attempts
                FROM customer_password_resets
                WHERE customer_id=@customerId AND method='sms'
                  AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
                """, connection, transaction))
            {
                lookup.Parameters.AddWithValue("@customerId", customerId);
                await using var reader = await lookup.ExecuteReaderAsync(cancellationToken);
                if (!await reader.ReadAsync(cancellationToken))
                {
                    await transaction.RollbackAsync(cancellationToken);
                    return SmsCodeInvalid();
                }

                resetId = reader.GetInt64("id");
                otpHash = reader.IsDBNull(reader.GetOrdinal("otp_hash")) ? null : reader.GetString("otp_hash");
                attempts = reader.GetByte("attempts");
            }

            if (attempts >= 5 || otpHash is null)
            {
                await transaction.RollbackAsync(cancellationToken);
                return SmsCodeInvalid();
            }

            if (!VerifyPassword(code, otpHash))
            {
                await using var increment = new MySqlCommand("""
                    UPDATE customer_password_resets
                    SET attempts=attempts + 1
                    WHERE id=@resetId AND used_at IS NULL
                    """, connection, transaction);
                increment.Parameters.AddWithValue("@resetId", resetId);
                await increment.ExecuteNonQueryAsync(cancellationToken);
                await transaction.CommitAsync(cancellationToken);
                return Results.BadRequest(new
                {
                    message = $"The code is incorrect. You have {4 - attempts} attempt(s) remaining."
                });
            }

            await using (var update = new MySqlCommand("""
                UPDATE customer_accounts
                SET password_hash=@passwordHash, updated_at=UTC_TIMESTAMP()
                WHERE id=@customerId
                """, connection, transaction))
            {
                update.Parameters.AddWithValue("@passwordHash", passwordHash);
                update.Parameters.AddWithValue("@customerId", customerId);
                if (await update.ExecuteNonQueryAsync(cancellationToken) != 1)
                {
                    await transaction.RollbackAsync(cancellationToken);
                    return SmsCodeInvalid();
                }
            }

            await using (var consume = new MySqlCommand("""
                UPDATE customer_password_resets
                SET used_at=UTC_TIMESTAMP()
                WHERE customer_id=@customerId AND used_at IS NULL
                """, connection, transaction))
            {
                consume.Parameters.AddWithValue("@customerId", customerId);
                await consume.ExecuteNonQueryAsync(cancellationToken);
            }

            await transaction.CommitAsync(cancellationToken);
            if (GetCustomerId(context) == customerId)
            {
                await context.SignOutAsync(AuthenticationScheme);
            }

            return Results.Ok(new { message = "Your password has been reset. Sign in with your new password." });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not complete an SMS password recovery request.");
            return Results.Problem(
                title: "SMS password recovery is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> CompleteRecoveryAsync(
        CustomerCompleteRecoveryRequest request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        CancellationToken cancellationToken)
    {
        if (!await HasValidCsrfAsync(context, antiforgery))
        {
            return CsrfError();
        }

        if (!ConsumeRateLimit(context, "recovery-complete", 10, TimeSpan.FromMinutes(15)))
        {
            return Results.StatusCode(StatusCodes.Status429TooManyRequests);
        }

        var token = request.Token ?? string.Empty;
        var password = request.Password ?? string.Empty;
        if (token.Length != 64 ||
            !token.All(Uri.IsHexDigit) ||
            !IsValidPassword(password))
        {
            return Results.BadRequest(new { message = "The recovery link is invalid or expired, or the new password does not meet the requirements." });
        }

        var passwordHash = BCrypt.Net.BCrypt.HashPassword(password, BcryptWorkFactor);
        var tokenHash = HashToken(token);
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
            int customerId;
            await using (var lookup = new MySqlCommand("""
                SELECT id, customer_id
                FROM customer_password_resets
                WHERE token_hash=@tokenHash AND method='email'
                  AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
                FOR UPDATE
                """, connection, transaction))
            {
                lookup.Parameters.AddWithValue("@tokenHash", tokenHash);
                await using var reader = await lookup.ExecuteReaderAsync(cancellationToken);
                if (!await reader.ReadAsync(cancellationToken))
                {
                    await reader.DisposeAsync();
                    await transaction.RollbackAsync(cancellationToken);
                    return Results.BadRequest(new { message = "The recovery link is invalid or expired. Request a new link." });
                }

                customerId = reader.GetInt32("customer_id");
            }

            await using (var update = new MySqlCommand("""
                UPDATE customer_accounts
                SET password_hash=@passwordHash, updated_at=UTC_TIMESTAMP()
                WHERE id=@customerId
                """, connection, transaction))
            {
                update.Parameters.AddWithValue("@passwordHash", passwordHash);
                update.Parameters.AddWithValue("@customerId", customerId);
                if (await update.ExecuteNonQueryAsync(cancellationToken) != 1)
                {
                    await transaction.RollbackAsync(cancellationToken);
                    return Results.BadRequest(new { message = "The recovery link is invalid or expired. Request a new link." });
                }
            }

            await using (var consume = new MySqlCommand("""
                UPDATE customer_password_resets SET used_at=UTC_TIMESTAMP()
                WHERE customer_id=@customerId AND used_at IS NULL
                """, connection, transaction))
            {
                consume.Parameters.AddWithValue("@customerId", customerId);
                await consume.ExecuteNonQueryAsync(cancellationToken);
            }

            await transaction.CommitAsync(cancellationToken);
            await context.SignOutAsync(AuthenticationScheme);
            return Results.Ok(new { message = "Your password has been updated. Sign in with your new password." });
        }
        catch (MySqlException)
        {
            return Results.Problem(
                title: "Password recovery is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> GetAdminRecoveryStatusAsync(
        HttpContext context,
        MySqlDataSource dataSource,
        CancellationToken cancellationToken)
    {
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var command = new MySqlCommand("""
                SELECT id, status, requested_at, expires_at,
                       (status='approved' AND expires_at > UTC_TIMESTAMP()) AS approval_active
                FROM customer_password_recovery_requests
                WHERE customer_id=@customerId
                  AND requested_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)
                ORDER BY id DESC
                LIMIT 1
                """, connection);
            command.Parameters.AddWithValue("@customerId", GetCustomerId(context));
            await using var reader = await command.ExecuteReaderAsync(cancellationToken);
            if (!await reader.ReadAsync(cancellationToken))
            {
                return Results.Ok(new { request = (object?)null });
            }

            var request = new
            {
                id = reader.GetInt64("id"),
                status = reader.GetString("status"),
                requestedAt = ToManilaOffset(reader.GetDateTime("requested_at")),
                expiresAt = reader.IsDBNull(reader.GetOrdinal("expires_at"))
                    ? (DateTimeOffset?)null
                    : ToManilaOffset(reader.GetDateTime("expires_at")),
                approvalActive = reader.GetBoolean("approval_active")
            };
            return Results.Ok(new { request });
        }
        catch (MySqlException exception)
        {
            context.RequestServices.GetRequiredService<ILogger<Program>>()
                .LogError(exception, "Could not load customer password-recovery status.");
            return Results.Problem(
                title: "Password-recovery status is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> RequestAdminRecoveryApprovalAsync(
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!await HasValidCsrfAsync(context, antiforgery))
        {
            return CsrfError();
        }

        var customerId = GetCustomerId(context);
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var transaction = await connection.BeginTransactionAsync(cancellationToken);

            int requestCount;
            bool requestedWithinMinute;
            await using (var rate = new MySqlCommand("""
                SELECT COUNT(*) AS request_count,
                       MAX(requested_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)) AS requested_within_minute
                FROM customer_password_recovery_requests
                WHERE customer_id=@customerId
                  AND requested_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)
                """, connection, transaction))
            {
                rate.Parameters.AddWithValue("@customerId", customerId);
                await using var reader = await rate.ExecuteReaderAsync(cancellationToken);
                await reader.ReadAsync(cancellationToken);
                requestCount = reader.GetInt32("request_count");
                requestedWithinMinute = !reader.IsDBNull(reader.GetOrdinal("requested_within_minute")) &&
                    reader.GetBoolean("requested_within_minute");
            }

            if (requestCount >= 5 || requestedWithinMinute)
            {
                await transaction.RollbackAsync(cancellationToken);
                return Results.Ok(new { message = "Please wait before submitting another password reset request." });
            }

            await using (var active = new MySqlCommand("""
                SELECT id
                FROM customer_password_recovery_requests
                WHERE customer_id=@customerId
                  AND (status='pending' OR (status='approved' AND expires_at > UTC_TIMESTAMP()))
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
                """, connection, transaction))
            {
                active.Parameters.AddWithValue("@customerId", customerId);
                if (await active.ExecuteScalarAsync(cancellationToken) is not null)
                {
                    await transaction.CommitAsync(cancellationToken);
                    return Results.Ok(new
                    {
                        message = "Your password reset request is already being reviewed or approved."
                    });
                }
            }

            await using (var insert = new MySqlCommand("""
                INSERT INTO customer_password_recovery_requests (customer_id)
                VALUES (@customerId)
                """, connection, transaction))
            {
                insert.Parameters.AddWithValue("@customerId", customerId);
                await insert.ExecuteNonQueryAsync(cancellationToken);
            }

            await transaction.CommitAsync(cancellationToken);
            logger.LogInformation("Customer {CustomerId} requested password-reset approval.", customerId);
            return Results.Accepted(value: new
            {
                message = "Your request was sent to the admin. Check your account for an update."
            });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not create a customer password-recovery approval request.");
            return Results.Problem(
                title: "Password recovery is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> CompleteAdminRecoveryAsync(
        CustomerAdminRecoveryCompleteRequest request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!await HasValidCsrfAsync(context, antiforgery))
        {
            return CsrfError();
        }

        var password = request.Password ?? string.Empty;
        if (!IsValidPassword(password) ||
            !string.Equals(password, request.ConfirmPassword, StringComparison.Ordinal))
        {
            return Results.BadRequest(new { message = "Enter matching passwords of at least 12 characters (maximum 72 UTF-8 bytes)." });
        }

        if (!ConsumeRateLimit(context, "admin-recovery-complete", 5, TimeSpan.FromMinutes(30)))
        {
            return Results.StatusCode(StatusCodes.Status429TooManyRequests);
        }

        var customerId = GetCustomerId(context);
        var passwordHash = BCrypt.Net.BCrypt.HashPassword(password, BcryptWorkFactor);
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
            long requestId;
            await using (var lookup = new MySqlCommand("""
                SELECT id
                FROM customer_password_recovery_requests
                WHERE customer_id=@customerId AND status='approved'
                  AND expires_at > UTC_TIMESTAMP()
                ORDER BY approved_at DESC, id DESC
                LIMIT 1
                FOR UPDATE
                """, connection, transaction))
            {
                lookup.Parameters.AddWithValue("@customerId", customerId);
                var result = await lookup.ExecuteScalarAsync(cancellationToken);
                if (result is null)
                {
                    await transaction.RollbackAsync(cancellationToken);
                    return Results.Conflict(new { message = "There is no active admin approval for a password reset." });
                }

                requestId = Convert.ToInt64(result, CultureInfo.InvariantCulture);
            }

            await using (var update = new MySqlCommand("""
                UPDATE customer_accounts
                SET password_hash=@passwordHash, updated_at=UTC_TIMESTAMP()
                WHERE id=@customerId
                """, connection, transaction))
            {
                update.Parameters.AddWithValue("@passwordHash", passwordHash);
                update.Parameters.AddWithValue("@customerId", customerId);
                if (await update.ExecuteNonQueryAsync(cancellationToken) != 1)
                {
                    await transaction.RollbackAsync(cancellationToken);
                    return Results.Conflict(new { message = "The customer account could not be found." });
                }
            }

            await using (var complete = new MySqlCommand("""
                UPDATE customer_password_recovery_requests
                SET status='completed', completed_at=UTC_TIMESTAMP()
                WHERE id=@requestId AND status='approved'
                """, connection, transaction))
            {
                complete.Parameters.AddWithValue("@requestId", requestId);
                if (await complete.ExecuteNonQueryAsync(cancellationToken) != 1)
                {
                    await transaction.RollbackAsync(cancellationToken);
                    return Results.Conflict(new { message = "This password-reset approval has already been used." });
                }
            }

            await transaction.CommitAsync(cancellationToken);
            await context.SignOutAsync(AuthenticationScheme);
            logger.LogInformation("Customer {CustomerId} completed an approved password recovery.", customerId);
            return Results.Ok(new { message = "Your password has been changed. Sign in with your new password." });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not complete approved customer password recovery.");
            return Results.Problem(
                title: "Password recovery is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> LogoutAsync(
        HttpContext context,
        IAntiforgery antiforgery)
    {
        if (!await HasValidCsrfAsync(context, antiforgery))
        {
            return CsrfError();
        }

        await context.SignOutAsync(AuthenticationScheme);
        return Results.NoContent();
    }

    private static async Task<IResult> GetBookingsAsync(
        HttpContext context,
        MySqlDataSource dataSource,
        CancellationToken cancellationToken)
    {
        var customerId = GetCustomerId(context);
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var command = new MySqlCommand("""
                SELECT b.id, b.code, b.schedule, b.status, b.total_amount, b.payment_status,
                       b.is_archived, b.service_started_at, b.receipt_pdf_token, v.name AS vehicle_name,
                       COALESCE(SUM(bs.duration_minutes), 0) AS total_duration_minutes
                FROM booking_list b
                LEFT JOIN vehicle_list v ON v.id=b.vehicle_id
                LEFT JOIN booking_services bs ON bs.booking_id=b.id
                WHERE b.customer_id=@customerId
                  AND NOT EXISTS (
                      SELECT 1
                      FROM customer_booking_dismissals cbd
                      WHERE cbd.booking_id=b.id AND cbd.customer_id=b.customer_id
                  )
                GROUP BY b.id, b.code, b.schedule, b.status, b.total_amount, b.payment_status,
                         b.is_archived, b.service_started_at, b.receipt_pdf_token, v.name
                ORDER BY b.schedule DESC, b.id DESC
                LIMIT 200
                """, connection);
            command.Parameters.AddWithValue("@customerId", customerId);
            await using var reader = await command.ExecuteReaderAsync(cancellationToken);
            var bookings = new List<object>();
            while (await reader.ReadAsync(cancellationToken))
            {
                var status = reader.GetInt32("status");
                bookings.Add(new
                {
                    id = reader.GetInt32("id"),
                    code = reader.GetString("code"),
                    schedule = ToManilaOffset(reader.GetDateTime("schedule")),
                    status,
                    statusLabel = BookingStatusLabel(status),
                    total = reader.GetDecimal("total_amount"),
                    paymentStatus = reader.GetString("payment_status"),
                    archived = reader.GetBoolean("is_archived"),
                    vehicle = reader.IsDBNull(reader.GetOrdinal("vehicle_name")) ? "" : reader.GetString("vehicle_name"),
                    receiptToken = reader.IsDBNull(reader.GetOrdinal("receipt_pdf_token"))
                        ? null
                        : reader.GetString("receipt_pdf_token"),
                    serviceStartedAt = reader.IsDBNull(reader.GetOrdinal("service_started_at"))
                        ? (DateTimeOffset?)null
                        : ToManilaOffset(reader.GetDateTime("service_started_at")),
                    totalDurationMinutes = reader.GetInt32("total_duration_minutes")
                });
            }

            return Results.Ok(bookings);
        }
        catch (MySqlException)
        {
            return Results.Problem(
                title: "Your bookings are temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> DismissBookingAsync(
        int bookingId,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        CancellationToken cancellationToken)
    {
        if (bookingId < 1)
        {
            return Results.NotFound();
        }

        if (!await HasValidCsrfAsync(context, antiforgery))
        {
            return CsrfError();
        }

        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var command = new MySqlCommand("""
                INSERT INTO customer_booking_dismissals (customer_id, booking_id)
                SELECT @customerId, b.id
                FROM booking_list b
                WHERE b.id=@bookingId AND b.customer_id=@customerId
                ON DUPLICATE KEY UPDATE dismissed_at=UTC_TIMESTAMP()
                """, connection);
            command.Parameters.AddWithValue("@customerId", GetCustomerId(context));
            command.Parameters.AddWithValue("@bookingId", bookingId);
            return await command.ExecuteNonQueryAsync(cancellationToken) == 1
                ? Results.NoContent()
                : Results.NotFound();
        }
        catch (MySqlException exception)
        {
            context.RequestServices.GetRequiredService<ILogger<Program>>()
                .LogError(exception, "Could not dismiss customer booking {BookingId}.", bookingId);
            return Results.Problem(
                title: "The booking could not be removed from your history.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> GetBookingAsync(
        int bookingId,
        HttpContext context,
        MySqlDataSource dataSource,
        CancellationToken cancellationToken)
    {
        if (bookingId < 1)
        {
            return Results.NotFound();
        }

        var customerId = GetCustomerId(context);
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            object? booking;
            await using (var command = new MySqlCommand("""
                SELECT b.id, b.code, b.client_name, b.contact, b.plate_number, b.email, b.address,
                       b.schedule, b.status, b.total_amount, b.reservation_fee, b.receipt_pdf_token,
                       b.payment_status, b.reservation_fee_payment_status, b.is_archived,
                       v.name AS vehicle_name
                FROM booking_list b
                LEFT JOIN vehicle_list v ON v.id=b.vehicle_id
                WHERE b.id=@bookingId AND b.customer_id=@customerId
                LIMIT 1
                """, connection))
            {
                command.Parameters.AddWithValue("@bookingId", bookingId);
                command.Parameters.AddWithValue("@customerId", customerId);
                await using var reader = await command.ExecuteReaderAsync(cancellationToken);
                if (!await reader.ReadAsync(cancellationToken))
                {
                    return Results.NotFound();
                }

                var status = reader.GetInt32("status");
                booking = new
                {
                    id = reader.GetInt32("id"),
                    code = reader.GetString("code"),
                    name = reader.GetString("client_name"),
                    contact = reader.GetString("contact"),
                    plateNumber = reader.GetString("plate_number"),
                    email = reader.GetString("email"),
                    address = reader.GetString("address"),
                    schedule = ToManilaOffset(reader.GetDateTime("schedule")),
                    status,
                    statusLabel = BookingStatusLabel(status),
                    total = reader.GetDecimal("total_amount"),
                    reservationFee = reader.GetDecimal("reservation_fee"),
                    receiptToken = reader.IsDBNull(reader.GetOrdinal("receipt_pdf_token"))
                        ? null
                        : reader.GetString("receipt_pdf_token"),
                    paymentStatus = reader.GetString("payment_status"),
                    reservationFeePaymentStatus = reader.GetString("reservation_fee_payment_status"),
                    archived = reader.GetBoolean("is_archived"),
                    vehicle = reader.IsDBNull(reader.GetOrdinal("vehicle_name")) ? "" : reader.GetString("vehicle_name")
                };
            }

            var services = new List<object>();
            await using (var serviceCommand = new MySqlCommand("""
                SELECT s.name, bs.price, bs.duration_minutes
                FROM booking_services bs
                INNER JOIN service_list s ON s.id=bs.service_id
                WHERE bs.booking_id=@bookingId
                ORDER BY s.name
                """, connection))
            {
                serviceCommand.Parameters.AddWithValue("@bookingId", bookingId);
                await using var reader = await serviceCommand.ExecuteReaderAsync(cancellationToken);
                while (await reader.ReadAsync(cancellationToken))
                {
                    services.Add(new
                    {
                        name = reader.GetString("name"),
                        price = reader.GetDecimal("price"),
                        durationMinutes = reader.GetInt32("duration_minutes")
                    });
                }
            }

            return Results.Ok(new { booking, services });
        }
        catch (MySqlException)
        {
            return Results.Problem(
                title: "Booking details are temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> GetNotificationsAsync(
        HttpContext context,
        MySqlDataSource dataSource,
        CancellationToken cancellationToken)
    {
        var customerId = GetCustomerId(context);
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var command = new MySqlCommand("""
                SELECT n.id, n.booking_id, n.status, n.message, n.appointment_at, n.date_created, n.read_at
                FROM customer_booking_notifications n
                INNER JOIN booking_list b ON b.id=n.booking_id
                WHERE n.customer_id=@customerId AND b.customer_id=@customerId
                  AND n.dismissed_at IS NULL
                ORDER BY n.date_created DESC, n.id DESC
                LIMIT 200
                """, connection);
            command.Parameters.AddWithValue("@customerId", customerId);
            await using var reader = await command.ExecuteReaderAsync(cancellationToken);
            var notifications = new List<object>();
            while (await reader.ReadAsync(cancellationToken))
            {
                notifications.Add(new
                {
                    id = reader.GetInt64("id"),
                    bookingId = reader.GetInt32("booking_id"),
                    status = reader.GetByte("status"),
                    message = reader.GetString("message"),
                    appointmentAt = ToManilaOffset(reader.GetDateTime("appointment_at")),
                    createdAt = ToManilaOffset(reader.GetDateTime("date_created")),
                    read = !reader.IsDBNull(reader.GetOrdinal("read_at"))
                });
            }

            return Results.Ok(notifications);
        }
        catch (MySqlException)
        {
            return Results.Problem(
                title: "Notifications are temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static Task<IResult> MarkNotificationReadAsync(
        long notificationId,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        CancellationToken cancellationToken) =>
        UpdateNotificationAsync(notificationId, dismiss: false, context, antiforgery, dataSource, cancellationToken);

    private static Task<IResult> DismissNotificationAsync(
        long notificationId,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        CancellationToken cancellationToken) =>
        UpdateNotificationAsync(notificationId, dismiss: true, context, antiforgery, dataSource, cancellationToken);

    private static async Task<IResult> UpdateNotificationAsync(
        long notificationId,
        bool dismiss,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        CancellationToken cancellationToken)
    {
        if (!await HasValidCsrfAsync(context, antiforgery))
        {
            return CsrfError();
        }

        if (notificationId < 1)
        {
            return Results.NotFound();
        }

        var customerId = GetCustomerId(context);
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            var updateSql = dismiss
                ? """
                  UPDATE customer_booking_notifications n
                  INNER JOIN booking_list b ON b.id=n.booking_id
                  SET n.dismissed_at=COALESCE(n.dismissed_at, UTC_TIMESTAMP())
                  WHERE n.id=@id AND n.customer_id=@customerId AND b.customer_id=@customerId
                  """
                : """
                  UPDATE customer_booking_notifications n
                  INNER JOIN booking_list b ON b.id=n.booking_id
                  SET n.read_at=COALESCE(n.read_at, UTC_TIMESTAMP())
                  WHERE n.id=@id AND n.customer_id=@customerId AND b.customer_id=@customerId
                  """;
            await using var update = new MySqlCommand(updateSql, connection);
            update.Parameters.AddWithValue("@id", notificationId);
            update.Parameters.AddWithValue("@customerId", customerId);
            if (await update.ExecuteNonQueryAsync(cancellationToken) > 0)
            {
                return Results.NoContent();
            }

            await using var check = new MySqlCommand("""
                SELECT 1
                FROM customer_booking_notifications n
                INNER JOIN booking_list b ON b.id=n.booking_id
                WHERE n.id=@id AND n.customer_id=@customerId AND b.customer_id=@customerId
                LIMIT 1
                """, connection);
            check.Parameters.AddWithValue("@id", notificationId);
            check.Parameters.AddWithValue("@customerId", customerId);
            return await check.ExecuteScalarAsync(cancellationToken) is null
                ? Results.NotFound()
                : Results.NoContent();
        }
        catch (MySqlException)
        {
            return Results.Problem(
                title: "The notification could not be updated.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> ChangePasswordAsync(
        CustomerChangePasswordRequest request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        CancellationToken cancellationToken)
    {
        if (!await HasValidCsrfAsync(context, antiforgery))
        {
            return CsrfError();
        }

        var currentPassword = request.CurrentPassword ?? string.Empty;
        var newPassword = request.NewPassword ?? string.Empty;
        if (currentPassword.Length is 0 or > MaximumPasswordLength ||
            !IsValidPassword(newPassword))
        {
            return Results.BadRequest(new { message = "Enter your current password and a new password of 12 or more characters (maximum 72 UTF-8 bytes)." });
        }

        var customerId = GetCustomerId(context);
        var newHash = BCrypt.Net.BCrypt.HashPassword(newPassword, BcryptWorkFactor);
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
            string? currentHash;
            await using (var lookup = new MySqlCommand(
                "SELECT password_hash FROM customer_accounts WHERE id=@customerId FOR UPDATE",
                connection, transaction))
            {
                lookup.Parameters.AddWithValue("@customerId", customerId);
                currentHash = Convert.ToString(await lookup.ExecuteScalarAsync(cancellationToken), CultureInfo.InvariantCulture);
            }

            if (currentHash is null || !VerifyPassword(currentPassword, currentHash))
            {
                await transaction.RollbackAsync(cancellationToken);
                return Results.BadRequest(new { message = "The current password is incorrect." });
            }

            await using (var update = new MySqlCommand("""
                UPDATE customer_accounts
                SET password_hash=@passwordHash, updated_at=UTC_TIMESTAMP()
                WHERE id=@customerId
                """, connection, transaction))
            {
                update.Parameters.AddWithValue("@passwordHash", newHash);
                update.Parameters.AddWithValue("@customerId", customerId);
                await update.ExecuteNonQueryAsync(cancellationToken);
            }

            await using (var revoke = new MySqlCommand("""
                UPDATE customer_password_resets SET used_at=UTC_TIMESTAMP()
                WHERE customer_id=@customerId AND used_at IS NULL
                """, connection, transaction))
            {
                revoke.Parameters.AddWithValue("@customerId", customerId);
                await revoke.ExecuteNonQueryAsync(cancellationToken);
            }

            await transaction.CommitAsync(cancellationToken);
            return Results.Ok(new { message = "Your password has been changed." });
        }
        catch (MySqlException)
        {
            return Results.Problem(
                title: "Your password could not be changed.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task SignInAsync(HttpContext context, int customerId, string fullName, string email)
    {
        var claims = new[]
        {
            new Claim(ClaimTypes.NameIdentifier, customerId.ToString(CultureInfo.InvariantCulture)),
            new Claim(ClaimTypes.Name, fullName),
            new Claim(ClaimTypes.Email, email),
            new Claim(ClaimTypes.Role, "customer")
        };
        var principal = new ClaimsPrincipal(new ClaimsIdentity(claims, AuthenticationScheme));
        await context.SignInAsync(
            AuthenticationScheme,
            principal,
            new AuthenticationProperties { IsPersistent = false, AllowRefresh = true });
    }

    private static async Task<bool> HasValidCsrfAsync(HttpContext context, IAntiforgery antiforgery)
    {
        try
        {
            return await antiforgery.IsRequestValidAsync(context);
        }
        catch (AntiforgeryValidationException)
        {
            return false;
        }
    }

    private static IResult CsrfError() =>
        Results.BadRequest(new { message = "Refresh the page and try again." });

    private static IResult LoginFailed() =>
        Results.Json(new { message = "The email or password is incorrect." }, statusCode: StatusCodes.Status401Unauthorized);

    private static string NormalizeEmail(string? email) => email?.Trim().ToLowerInvariant() ?? string.Empty;

    private static bool IsValidEmail(string email) =>
        email.Length is > 0 and <= 254 &&
        MailAddress.TryCreate(email, out var address) &&
        string.Equals(address.Address, email, StringComparison.OrdinalIgnoreCase);

    private static bool IsValidMobileNumber(string contact) =>
        contact.Length == 11 &&
        contact.StartsWith("09", StringComparison.Ordinal) &&
        contact.All(char.IsAsciiDigit);

    private static bool IsValidPassword(string password) =>
        password.Length is >= MinimumPasswordLength and <= MaximumPasswordLength &&
        Encoding.UTF8.GetByteCount(password) <= 72;

    private static bool VerifyPassword(string password, string hash)
    {
        try
        {
            return BCrypt.Net.BCrypt.Verify(password, hash);
        }
        catch (ArgumentException)
        {
            return false;
        }
    }

    private static string HashToken(string token) =>
        Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(token))).ToLowerInvariant();

    private static int GetCustomerId(HttpContext context) =>
        int.TryParse(
            context.User.FindFirstValue(ClaimTypes.NameIdentifier),
            NumberStyles.None,
            CultureInfo.InvariantCulture,
            out var customerId)
            ? customerId
            : 0;

    private static bool ConsumeRateLimit(HttpContext context, string purpose, int maximum, TimeSpan window)
    {
        var ip = context.Connection.RemoteIpAddress?.ToString() ?? "unknown";
        return ConsumeRateLimitKey($"{purpose}:ip:{ip}", maximum, window);
    }

    private static bool ConsumeIdentityRateLimit(string purpose, string identity, int maximum, TimeSpan window)
    {
        var identityHash = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(identity))).ToLowerInvariant();
        return ConsumeRateLimitKey($"{purpose}:identity:{identityHash}", maximum, window);
    }

    private static bool ConsumeRateLimitKey(string key, int maximum, TimeSpan window)
    {
        var now = DateTimeOffset.UtcNow;
        lock (RateLimitLock)
        {
            if (RateLimits.TryGetValue(key, out var current) && current.ExpiresAt > now)
            {
                if (current.Count >= maximum)
                {
                    return false;
                }

                RateLimits[key] = current with { Count = current.Count + 1 };
                return true;
            }

            if (RateLimits.Count >= 10_000)
            {
                foreach (var expired in RateLimits.Where(pair => pair.Value.ExpiresAt <= now).Select(pair => pair.Key).ToArray())
                {
                    RateLimits.Remove(expired);
                }
            }

            if (RateLimits.Count >= 10_000)
            {
                return false;
            }

            RateLimits[key] = new RateWindow(now.Add(window), 1);
            return true;
        }
    }

    private static async Task PadResponseTimeAsync(long started, CancellationToken cancellationToken)
    {
        const int minimumMilliseconds = 400;
        var elapsed = Stopwatch.GetElapsedTime(started);
        var remaining = TimeSpan.FromMilliseconds(minimumMilliseconds) - elapsed;
        if (remaining > TimeSpan.Zero)
        {
            await Task.Delay(remaining, cancellationToken);
        }
    }

    private static string RecoveryMessage =>
        "If an account matches that email address, a password recovery link will be sent shortly.";

    private static string SmsRecoveryMessage =>
        "If an account matches that mobile number, a password reset code will be sent shortly.";

    private static IResult SmsCodeInvalid() =>
        Results.BadRequest(new { message = "The code is invalid, expired, or locked. Request a new code." });

    private static bool CanSendRecoveryEmail() =>
        GetRecoveryBaseUri() is not null &&
        !string.IsNullOrWhiteSpace(Environment.GetEnvironmentVariable("CUSTOMER_SMTP_HOST")) &&
        !string.IsNullOrWhiteSpace(Environment.GetEnvironmentVariable("CUSTOMER_SMTP_FROM"));

    private static Uri? GetRecoveryBaseUri()
    {
        var value = Environment.GetEnvironmentVariable("CUSTOMER_PORTAL_BASE_URL");
        if (!Uri.TryCreate(value, UriKind.Absolute, out var uri) ||
            !string.IsNullOrEmpty(uri.UserInfo) ||
            !(uri.Scheme == Uri.UriSchemeHttps ||
              (uri.Scheme == Uri.UriSchemeHttp && uri.IsLoopback)))
        {
            return null;
        }

        return uri;
    }

    private static string BuildRecoveryUrl(string token)
    {
        var origin = GetRecoveryBaseUri()!.GetLeftPart(UriPartial.Authority).TrimEnd('/');
        return $"{origin}/customer-account.html#reset={Uri.EscapeDataString(token)}";
    }

    internal static async Task<bool> SendSmsAsync(
        string apiKey,
        string phoneNumber,
        string message,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        var values = new Dictionary<string, string>
        {
            ["apikey"] = apiKey,
            ["number"] = phoneNumber,
            ["message"] = message
        };
        var senderName = Environment.GetEnvironmentVariable("SEMAPHORE_SENDER_NAME")?.Trim();
        if (!string.IsNullOrEmpty(senderName))
        {
            if (senderName.Length > 11 || senderName.Any(char.IsControl))
            {
                logger.LogWarning("Customer SMS recovery has an invalid sender name configuration.");
                return false;
            }

            values["sendername"] = senderName;
        }

        try
        {
            using var response = await SemaphoreClient.PostAsync(
                "messages",
                new FormUrlEncodedContent(values),
                cancellationToken);
            if (!response.IsSuccessStatusCode)
            {
                logger.LogWarning(
                    "The SMS provider rejected a message with HTTP {StatusCode}.",
                    (int)response.StatusCode);
                return false;
            }

            var responseBody = await response.Content.ReadAsStringAsync(cancellationToken);
            using var document = JsonDocument.Parse(responseBody);
            if (document.RootElement.ValueKind == JsonValueKind.Array &&
                document.RootElement.GetArrayLength() > 0 &&
                document.RootElement[0].TryGetProperty("message_id", out var messageId) &&
                messageId.ValueKind is JsonValueKind.String or JsonValueKind.Number &&
                !string.IsNullOrWhiteSpace(messageId.ToString()))
            {
                return true;
            }

            logger.LogWarning("The SMS provider returned a response without a message ID.");
            return false;
        }
        catch (Exception exception) when (
            exception is HttpRequestException or JsonException or TaskCanceledException &&
            !cancellationToken.IsCancellationRequested)
        {
            logger.LogWarning(exception, "Could not deliver an SMS message.");
            return false;
        }
    }

    private static async Task InvalidateCustomerResetAsync(
        MySqlDataSource dataSource,
        long resetId,
        int customerId,
        ILogger<Program> logger)
    {
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(CancellationToken.None);
            await using var command = new MySqlCommand("""
                UPDATE customer_password_resets
                SET used_at=UTC_TIMESTAMP()
                WHERE id=@resetId AND customer_id=@customerId AND used_at IS NULL
                """, connection);
            command.Parameters.AddWithValue("@resetId", resetId);
            command.Parameters.AddWithValue("@customerId", customerId);
            await command.ExecuteNonQueryAsync(CancellationToken.None);
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not invalidate an undelivered SMS recovery code for customer {CustomerId}.", customerId);
        }
    }

    private static async Task SendRecoveryEmailAsync(
        string recipient,
        string fullName,
        string resetUrl,
        ILogger<Program> logger)
    {
        try
        {
            var host = Environment.GetEnvironmentVariable("CUSTOMER_SMTP_HOST")!;
            var from = Environment.GetEnvironmentVariable("CUSTOMER_SMTP_FROM")!;
            var portValue = Environment.GetEnvironmentVariable("CUSTOMER_SMTP_PORT");
            var port = int.TryParse(portValue, NumberStyles.None, CultureInfo.InvariantCulture, out var parsedPort)
                ? Math.Clamp(parsedPort, 1, 65535)
                : 587;
            using var client = new SmtpClient(host, port)
            {
                EnableSsl = true,
                DeliveryMethod = SmtpDeliveryMethod.Network,
                Timeout = 10_000
            };
            var username = Environment.GetEnvironmentVariable("CUSTOMER_SMTP_USERNAME");
            var password = Environment.GetEnvironmentVariable("CUSTOMER_SMTP_PASSWORD");
            if (!string.IsNullOrEmpty(username))
            {
                client.Credentials = new NetworkCredential(username, password ?? string.Empty);
            }

            using var message = new MailMessage(
                new MailAddress(from),
                new MailAddress(recipient))
            {
                Subject = "Reset your Kwacky-kleen customer account password",
                IsBodyHtml = true,
                Body = $"""
                    <p>Hello {WebUtility.HtmlEncode(fullName)},</p>
                    <p>Use this link within 30 minutes to set a new password:</p>
                    <p><a href="{WebUtility.HtmlEncode(resetUrl)}">Reset your password</a></p>
                    <p>If you did not request this, you can ignore this message.</p>
                    """
            };
            await client.SendMailAsync(message);
        }
        catch (Exception exception) when (exception is SmtpException or InvalidOperationException or FormatException or ArgumentException)
        {
            logger.LogWarning("A customer recovery email could not be delivered.");
        }
    }

    private static DateTimeOffset ToManilaOffset(DateTime value) =>
        new(DateTime.SpecifyKind(value, DateTimeKind.Unspecified), TimeSpan.FromHours(8));

    private static string BookingStatusLabel(int status) => status switch
    {
        0 => "Pending",
        1 => "Confirmed",
        2 => "Arrived",
        3 => "In progress",
        4 => "Completed",
        5 => "Cancelled",
        _ => "Updated"
    };

    private sealed record RateWindow(DateTimeOffset ExpiresAt, int Count);
}

internal sealed record CustomerRegistrationRequest(string? FullName, string? Email, string? Contact, string? Password);
internal sealed record CustomerLoginRequest(string? Email, string? Password);
internal sealed record CustomerRecoveryRequest(string? Email);
internal sealed record CustomerSmsRecoveryRequest(string? Contact);
internal sealed record CustomerSmsCompleteRecoveryRequest(string? Contact, string? Code, string? Password, string? ConfirmPassword);
internal sealed record CustomerCompleteRecoveryRequest(string? Token, string? Password);
internal sealed record CustomerAdminRecoveryCompleteRequest(string? Password, string? ConfirmPassword);
internal sealed record CustomerChangePasswordRequest(string? CurrentPassword, string? NewPassword);
