using System.Globalization;
using System.Net.Http.Headers;
using System.Security.Claims;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using Microsoft.AspNetCore.Antiforgery;
using MySqlConnector;

internal static partial class PaymentEndpoints
{
    private const string Currency = "php";
    private const int MaximumWebhookBytes = 1_048_576;
    private static readonly HttpClient StripeClient = new()
    {
        BaseAddress = new Uri("https://api.stripe.com/"),
        Timeout = TimeSpan.FromSeconds(15)
    };

    public static void MapPaymentEndpoints(this WebApplication app)
    {
        app.MapGet("/api/public/payments/csrf", (HttpContext context, IAntiforgery antiforgery) =>
        {
            var tokens = antiforgery.GetAndStoreTokens(context);
            return Results.Ok(new { token = tokens.RequestToken });
        })
            .WithName("GetPaymentCsrfToken")
            .WithSummary("Issue a request-verification token for public payment forms");

        app.MapPost("/api/public/payments/{receiptToken}/sms-code", SendPaymentVerificationCodeAsync)
            .WithName("SendPaymentVerificationCode")
            .WithSummary("Send an SMS verification code to the booking contact before checkout")
            .Produces(StatusCodes.Status202Accepted)
            .Produces(StatusCodes.Status400BadRequest)
            .Produces(StatusCodes.Status404NotFound)
            .Produces(StatusCodes.Status409Conflict)
            .Produces(StatusCodes.Status503ServiceUnavailable);

        app.MapPost("/api/public/payments/{receiptToken}/sms-verify", VerifyPaymentCodeAsync)
            .WithName("VerifyPaymentCode")
            .WithSummary("Verify the SMS code for a booking")
            .Produces(StatusCodes.Status200OK)
            .Produces(StatusCodes.Status400BadRequest)
            .Produces(StatusCodes.Status404NotFound)
            .Produces(StatusCodes.Status409Conflict);

        app.MapPost("/api/public/payments/{receiptToken}/checkout", CreateCheckoutAsync)
            .WithName("CreateStripeCheckout")
            .WithSummary("Create or reuse a test-mode Stripe Checkout session for the full booking total")
            .Produces(StatusCodes.Status200OK)
            .Produces(StatusCodes.Status400BadRequest)
            .Produces(StatusCodes.Status404NotFound)
            .Produces(StatusCodes.Status409Conflict)
            .Produces(StatusCodes.Status503ServiceUnavailable);

        app.MapPost("/api/payments/stripe/webhook", HandleStripeWebhookAsync)
            .WithName("HandleStripeWebhook")
            .WithSummary("Verify and process Stripe test-mode payment notifications")
            .Produces(StatusCodes.Status200OK)
            .Produces(StatusCodes.Status400BadRequest)
            .Produces(StatusCodes.Status413PayloadTooLarge)
            .Produces(StatusCodes.Status503ServiceUnavailable);

        app.MapGet("/api/public/receipts/{receiptToken}/{kind}.pdf", GetPublicReceiptAsync)
            .WithName("GetTokenReceiptPdf")
            .WithSummary("Download a booking confirmation or verified payment receipt using its receipt token")
            .Produces(StatusCodes.Status200OK, contentType: "application/pdf")
            .Produces(StatusCodes.Status404NotFound)
            .Produces(StatusCodes.Status409Conflict);

        var admin = app.MapGroup("/api/admin").RequireAuthorization();
        admin.MapPost("/payments/{bookingId:int}/cash", RecordCashPaymentAsync)
            .WithName("RecordCashPayment")
            .WithSummary("Record a full cash payment made at the shop")
            .Produces(StatusCodes.Status204NoContent)
            .Produces(StatusCodes.Status400BadRequest)
            .Produces(StatusCodes.Status404NotFound)
            .Produces(StatusCodes.Status409Conflict);

        admin.MapGet("/receipts/{bookingId:int}/cash.pdf", GetCashReceiptAsync)
            .WithName("GetCashReceiptPdf")
            .WithSummary("Download a cash receipt for a paid booking")
            .Produces(StatusCodes.Status200OK, contentType: "application/pdf")
            .Produces(StatusCodes.Status404NotFound);
    }

    private static async Task<IResult> SendPaymentVerificationCodeAsync(
        string receiptToken,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!await IsValidCsrfAsync(context, antiforgery))
        {
            return Results.BadRequest(new { message = "Refresh the page and try again." });
        }

        if (!IsReceiptToken(receiptToken))
        {
            return Results.BadRequest(new { message = "Invalid booking verification request." });
        }

        var apiKey = Environment.GetEnvironmentVariable("SEMAPHORE_API_KEY")?.Trim();
        if (string.IsNullOrEmpty(apiKey))
        {
            logger.LogWarning("Payment SMS verification is unavailable because its provider is not configured.");
            return Results.Problem(
                title: "SMS verification is not configured.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }

        int bookingId;
        string contact;
        long otpId;
        var code = RandomNumberGenerator.GetInt32(100_000, 1_000_000).ToString("D6", CultureInfo.InvariantCulture);
        var codeHash = BCrypt.Net.BCrypt.HashPassword(code, 12);
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
            await using (var booking = new MySqlCommand("""
                SELECT id, contact, payment_status, status, is_archived
                FROM booking_list
                WHERE receipt_pdf_token=@token
                LIMIT 1
                FOR UPDATE
                """, connection, transaction))
            {
                booking.Parameters.AddWithValue("@token", receiptToken);
                await using var reader = await booking.ExecuteReaderAsync(cancellationToken);
                if (!await reader.ReadAsync(cancellationToken))
                {
                    return Results.NotFound();
                }

                bookingId = reader.GetInt32("id");
                contact = reader.GetString("contact");
                var paymentStatus = reader.GetString("payment_status");
                var status = reader.GetInt32("status");
                var isArchived = reader.GetBoolean("is_archived");
                await reader.DisposeAsync();
                if (status == 5 || isArchived || !string.Equals(paymentStatus, "unpaid", StringComparison.OrdinalIgnoreCase))
                {
                    return Results.Conflict(new { message = "This booking is unavailable for payment verification." });
                }
            }

            if (!IsPhilippineMobileNumber(contact))
            {
                return Results.BadRequest(new
                {
                    message = "The booking does not have a valid Philippine mobile number. Please contact the shop."
                });
            }

            int sentToday;
            bool sentWithinMinute;
            await using (var rate = new MySqlCommand("""
                SELECT COUNT(*) AS sent_today,
                       MAX(date_created >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)) AS sent_within_minute
                FROM booking_payment_otps
                WHERE booking_id=@bookingId AND date_created >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                """, connection, transaction))
            {
                rate.Parameters.AddWithValue("@bookingId", bookingId);
                await using var reader = await rate.ExecuteReaderAsync(cancellationToken);
                await reader.ReadAsync(cancellationToken);
                sentToday = reader.GetInt32("sent_today");
                sentWithinMinute = !reader.IsDBNull(reader.GetOrdinal("sent_within_minute")) &&
                    reader.GetBoolean("sent_within_minute");
            }

            if (sentToday >= 5 || sentWithinMinute)
            {
                await transaction.RollbackAsync(cancellationToken);
                return Results.StatusCode(StatusCodes.Status429TooManyRequests);
            }

            await using (var expirePrevious = new MySqlCommand("""
                UPDATE booking_payment_otps
                SET status='failed'
                WHERE booking_id=@bookingId AND status IN ('sending', 'sent', 'verified')
                """, connection, transaction))
            {
                expirePrevious.Parameters.AddWithValue("@bookingId", bookingId);
                await expirePrevious.ExecuteNonQueryAsync(cancellationToken);
            }

            await using (var insert = new MySqlCommand("""
                INSERT INTO booking_payment_otps (booking_id, otp_hash, status, expires_at)
                VALUES (@bookingId, @otpHash, 'sending', DATE_ADD(NOW(), INTERVAL 10 MINUTE))
                """, connection, transaction))
            {
                insert.Parameters.AddWithValue("@bookingId", bookingId);
                insert.Parameters.AddWithValue("@otpHash", codeHash);
                await insert.ExecuteNonQueryAsync(cancellationToken);
                otpId = insert.LastInsertedId;
            }

            await transaction.CommitAsync(cancellationToken);
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not prepare payment verification for receipt token.");
            return Results.Problem(
                title: "SMS verification is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }

        var phoneNumber = "63" + contact[1..];
        var message = $"Your OCWBS payment verification code is {code}. It expires in 10 minutes. Do not share this code.";
        if (!await CustomerEndpoints.SendSmsAsync(apiKey, phoneNumber, message, logger, cancellationToken))
        {
            await UpdatePaymentOtpStatusAsync(dataSource, otpId, bookingId, "failed", logger);
            return Results.Problem(
                title: "The SMS verification code could not be sent.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }

        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var command = new MySqlCommand("""
                UPDATE booking_payment_otps
                SET status='sent'
                WHERE id=@otpId AND booking_id=@bookingId AND status='sending'
                """, connection);
            command.Parameters.AddWithValue("@otpId", otpId);
            command.Parameters.AddWithValue("@bookingId", bookingId);
            if (await command.ExecuteNonQueryAsync(cancellationToken) != 1)
            {
                logger.LogError("SMS provider accepted payment verification for OTP {OtpId}, but it could not be marked sent.", otpId);
                return Results.Problem(
                    title: "The verification code was sent but could not be confirmed. Contact the shop before retrying.",
                    statusCode: StatusCodes.Status503ServiceUnavailable);
            }
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not mark payment verification OTP {OtpId} as sent.", otpId);
            return Results.Problem(
                title: "The verification code was sent but could not be confirmed. Contact the shop before retrying.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }

        var maskedContact = contact[..2] + "******" + contact[^3..];
        return Results.Accepted(value: new
        {
            phone = maskedContact,
            message = $"A verification code was sent by SMS to {maskedContact}."
        });
    }

    private static async Task<IResult> VerifyPaymentCodeAsync(
        string receiptToken,
        PaymentVerificationRequest request,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!await IsValidCsrfAsync(context, antiforgery))
        {
            return Results.BadRequest(new { message = "Refresh the page and try again." });
        }

        var code = request.Code?.Trim() ?? string.Empty;
        if (!IsReceiptToken(receiptToken) || code.Length != 6 || !code.All(char.IsAsciiDigit))
        {
            return Results.BadRequest(new { message = "Enter the six-digit SMS verification code." });
        }

        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
            int bookingId;
            string paymentStatus;
            int bookingStatus;
            bool isArchived;
            await using (var booking = new MySqlCommand("""
                SELECT id, payment_status, status, is_archived
                FROM booking_list
                WHERE receipt_pdf_token=@token
                LIMIT 1
                FOR UPDATE
                """, connection, transaction))
            {
                booking.Parameters.AddWithValue("@token", receiptToken);
                await using var reader = await booking.ExecuteReaderAsync(cancellationToken);
                if (!await reader.ReadAsync(cancellationToken))
                {
                    return Results.NotFound();
                }

                bookingId = reader.GetInt32("id");
                paymentStatus = reader.GetString("payment_status");
                bookingStatus = reader.GetInt32("status");
                isArchived = reader.GetBoolean("is_archived");
            }

            if (bookingStatus == 5 || isArchived ||
                !string.Equals(paymentStatus, "unpaid", StringComparison.OrdinalIgnoreCase))
            {
                return Results.Conflict(new { message = "This booking is unavailable for payment verification." });
            }

            long otpId;
            string otpHash;
            string otpStatus;
            int attempts;
            bool otpNotExpired;
            await using (var otp = new MySqlCommand("""
                SELECT id, otp_hash, status, attempts, expires_at > NOW() AS otp_not_expired
                FROM booking_payment_otps
                WHERE booking_id=@bookingId
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
                """, connection, transaction))
            {
                otp.Parameters.AddWithValue("@bookingId", bookingId);
                await using var reader = await otp.ExecuteReaderAsync(cancellationToken);
                if (!await reader.ReadAsync(cancellationToken))
                {
                    return Results.Conflict(new { message = "Request a new SMS verification code." });
                }

                otpId = reader.GetInt64("id");
                otpHash = reader.GetString("otp_hash");
                otpStatus = reader.GetString("status");
                attempts = reader.GetByte("attempts");
                otpNotExpired = reader.GetBoolean("otp_not_expired");
            }

            if (otpStatus == "locked" || attempts >= 5 || !otpNotExpired ||
                otpStatus is not ("sent" or "verified"))
            {
                return Results.Conflict(new { message = "This SMS code is unavailable or expired. Request a new code." });
            }

            if (!BCrypt.Net.BCrypt.Verify(code, otpHash))
            {
                var nextAttempts = attempts + 1;
                await using var updateAttempts = new MySqlCommand("""
                    UPDATE booking_payment_otps
                    SET attempts=@attempts, status=@status
                    WHERE id=@otpId AND status='sent'
                    """, connection, transaction);
                updateAttempts.Parameters.AddWithValue("@attempts", nextAttempts);
                updateAttempts.Parameters.AddWithValue("@status", nextAttempts >= 5 ? "locked" : "sent");
                updateAttempts.Parameters.AddWithValue("@otpId", otpId);
                if (await updateAttempts.ExecuteNonQueryAsync(cancellationToken) != 1)
                {
                    await transaction.RollbackAsync(cancellationToken);
                    return Results.Conflict(new { message = "The verification code is no longer available. Request a new code." });
                }

                await transaction.CommitAsync(cancellationToken);
                return Results.BadRequest(new
                {
                    message = nextAttempts >= 5
                        ? "Too many incorrect codes. Request a new SMS code."
                        : "The verification code is incorrect."
                });
            }

            if (otpStatus == "sent")
            {
                await using var markVerified = new MySqlCommand("""
                    UPDATE booking_payment_otps
                    SET status='verified', verified_at=NOW()
                    WHERE id=@otpId AND status='sent' AND expires_at > NOW()
                    """, connection, transaction);
                markVerified.Parameters.AddWithValue("@otpId", otpId);
                if (await markVerified.ExecuteNonQueryAsync(cancellationToken) != 1)
                {
                    await transaction.RollbackAsync(cancellationToken);
                    return Results.Conflict(new { message = "This SMS code expired. Request a new code." });
                }
            }

            await transaction.CommitAsync(cancellationToken);
            return Results.Ok(new { message = "Phone number verified." });
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not verify the payment SMS code.");
            return Results.Problem(
                title: "Payment verification is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
        catch (ArgumentException exception)
        {
            logger.LogWarning(exception, "A stored payment SMS hash could not be verified.");
            return Results.Problem(
                title: "The verification code is unavailable. Request a new code.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task UpdatePaymentOtpStatusAsync(
        MySqlDataSource dataSource,
        long otpId,
        int bookingId,
        string status,
        ILogger<Program> logger)
    {
        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(CancellationToken.None);
            await using var command = new MySqlCommand("""
                UPDATE booking_payment_otps SET status=@status
                WHERE id=@otpId AND booking_id=@bookingId AND status='sending'
                """, connection);
            command.Parameters.AddWithValue("@status", status);
            command.Parameters.AddWithValue("@otpId", otpId);
            command.Parameters.AddWithValue("@bookingId", bookingId);
            await command.ExecuteNonQueryAsync(CancellationToken.None);
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not mark payment SMS OTP {OtpId} as {Status}.", otpId, status);
        }
    }

    private static async Task<bool> IsValidCsrfAsync(HttpContext context, IAntiforgery antiforgery)
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

    private static bool IsPhilippineMobileNumber(string contact) =>
        contact.Length == 11 &&
        contact.StartsWith("09", StringComparison.Ordinal) &&
        contact.All(char.IsAsciiDigit);

    private static async Task<IResult> CreateCheckoutAsync(
        string receiptToken,
        HttpContext context,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsReceiptToken(receiptToken))
        {
            return Results.BadRequest(new { message = "Enter a valid booking receipt token." });
        }

        var configuration = GetStripeConfiguration();
        if (configuration is null)
        {
            return StripeUnavailable();
        }

        if (!TryGetPublicBaseUri(out var publicBaseUri))
        {
            return Results.Problem(
                title: "Payment checkout is not configured.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }

        long paymentId;
        long bookingId;
        long amountMinor;
        try
        {
            await using (var connection = await dataSource.OpenConnectionAsync(cancellationToken))
            await using (var transaction = await connection.BeginTransactionAsync(cancellationToken))
            {
                await using var bookingCommand = new MySqlCommand("""
                    SELECT id, total_amount, reservation_fee, reservation_fee_payment_status,
                           payment_status, status, is_archived
                    FROM booking_list
                    WHERE receipt_pdf_token = @token
                    LIMIT 1
                    FOR UPDATE
                    """, connection, transaction);
                bookingCommand.Parameters.AddWithValue("@token", receiptToken);
                await using var reader = await bookingCommand.ExecuteReaderAsync(cancellationToken);
                if (!await reader.ReadAsync(cancellationToken))
                {
                    return Results.NotFound();
                }

                bookingId = Convert.ToInt64(reader.GetValue(reader.GetOrdinal("id")), CultureInfo.InvariantCulture);
                var total = Convert.ToDecimal(reader.GetValue(reader.GetOrdinal("total_amount")), CultureInfo.InvariantCulture);
                var reservationFee = Convert.ToDecimal(reader.GetValue(reader.GetOrdinal("reservation_fee")), CultureInfo.InvariantCulture);
                var reservationFeeStatus = reader.GetString("reservation_fee_payment_status");
                var paymentStatus = reader.IsDBNull(reader.GetOrdinal("payment_status"))
                    ? "unpaid"
                    : reader.GetString("payment_status");
                var bookingStatus = reader.GetInt32("status");
                var isArchived = reader.GetBoolean("is_archived");
                await reader.DisposeAsync();

                if (bookingStatus == 5 || isArchived)
                {
                    return Results.Conflict(new { message = "Cancelled or archived bookings cannot be paid online." });
                }

                if (!string.Equals(paymentStatus, "unpaid", StringComparison.OrdinalIgnoreCase))
                {
                    return Results.Conflict(new { message = "This booking is not currently payable." });
                }

                await using (var otpCommand = new MySqlCommand("""
                    SELECT status, expires_at > NOW() AS otp_not_expired
                    FROM booking_payment_otps
                    WHERE booking_id=@bookingId
                    ORDER BY id DESC
                    LIMIT 1
                    FOR UPDATE
                    """, connection, transaction))
                {
                    otpCommand.Parameters.AddWithValue("@bookingId", bookingId);
                    await using var otpReader = await otpCommand.ExecuteReaderAsync(cancellationToken);
                    if (!await otpReader.ReadAsync(cancellationToken) ||
                        !string.Equals(otpReader.GetString("status"), "verified", StringComparison.Ordinal) ||
                        !otpReader.GetBoolean("otp_not_expired"))
                    {
                        return Results.Conflict(new { message = "Verify the booking contact by SMS before starting checkout." });
                    }
                }

                var amountDue = Math.Max(0m, total - (string.Equals(
                    reservationFeeStatus, "paid", StringComparison.OrdinalIgnoreCase) ? reservationFee : 0m));
                amountMinor = ToMinorUnits(amountDue);
                if (amountMinor < 1)
                {
                    return Results.Conflict(new { message = "The booking total is not payable." });
                }

                await using var attemptCommand = new MySqlCommand("""
                    SELECT id, provider, status, checkout_url
                    FROM csharp_payment_attempts
                    WHERE booking_id = @bookingId
                    ORDER BY id DESC
                    LIMIT 1
                    FOR UPDATE
                    """, connection, transaction);
                attemptCommand.Parameters.AddWithValue("@bookingId", bookingId);
                await using var attemptReader = await attemptCommand.ExecuteReaderAsync(cancellationToken);
                if (await attemptReader.ReadAsync(cancellationToken))
                {
                    paymentId = attemptReader.GetInt64("id");
                    var provider = attemptReader.GetString("provider");
                    var status = attemptReader.GetString("status");
                    var checkoutUrl = attemptReader.IsDBNull(attemptReader.GetOrdinal("checkout_url"))
                        ? null
                        : attemptReader.GetString("checkout_url");
                    await attemptReader.DisposeAsync();

                    if (!string.Equals(provider, "stripe", StringComparison.Ordinal) ||
                        string.Equals(status, "paid", StringComparison.Ordinal))
                    {
                        return Results.Conflict(new { message = "A payment has already been recorded for this booking." });
                    }

                    if (string.Equals(status, "open", StringComparison.Ordinal) && checkoutUrl is not null)
                    {
                        await transaction.CommitAsync(cancellationToken);
                        return Results.Ok(new { checkoutUrl });
                    }

                    await using var retryCommand = new MySqlCommand("""
                        UPDATE csharp_payment_attempts
                        SET status = 'creating', checkout_url = NULL
                        WHERE id = @id
                        """, connection, transaction);
                    retryCommand.Parameters.AddWithValue("@id", paymentId);
                    await retryCommand.ExecuteNonQueryAsync(cancellationToken);
                }
                else
                {
                    await using var insertCommand = new MySqlCommand("""
                        INSERT INTO csharp_payment_attempts
                            (booking_id, provider, amount_minor, currency, status)
                        VALUES (@bookingId, 'stripe', @amountMinor, @currency, 'creating')
                        """, connection, transaction);
                    insertCommand.Parameters.AddWithValue("@bookingId", bookingId);
                    insertCommand.Parameters.AddWithValue("@amountMinor", amountMinor);
                    insertCommand.Parameters.AddWithValue("@currency", Currency);
                    await insertCommand.ExecuteNonQueryAsync(cancellationToken);
                    paymentId = insertCommand.LastInsertedId;
                }

                await transaction.CommitAsync(cancellationToken);
            }
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not prepare payment for the supplied booking token.");
            return Results.Problem(
                title: "Online payment is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }

        try
        {
            var successUrl = new Uri(publicBaseUri!, $"/payment.html?token={Uri.EscapeDataString(receiptToken)}&result=success&session_id={{CHECKOUT_SESSION_ID}}");
            var cancelUrl = new Uri(publicBaseUri!, $"/payment.html?token={Uri.EscapeDataString(receiptToken)}&result=cancelled");
            var values = new Dictionary<string, string>
            {
                ["mode"] = "payment",
                ["success_url"] = successUrl.ToString(),
                ["cancel_url"] = cancelUrl.ToString(),
                ["client_reference_id"] = bookingId.ToString(CultureInfo.InvariantCulture),
                ["line_items[0][price_data][currency]"] = Currency,
                ["line_items[0][price_data][unit_amount]"] = amountMinor.ToString(CultureInfo.InvariantCulture),
                ["line_items[0][price_data][product_data][name]"] = $"OCWBS booking {bookingId}",
                ["line_items[0][quantity]"] = "1",
                ["metadata[booking_id]"] = bookingId.ToString(CultureInfo.InvariantCulture),
                ["metadata[payment_id]"] = paymentId.ToString(CultureInfo.InvariantCulture),
                ["metadata[amount_minor]"] = amountMinor.ToString(CultureInfo.InvariantCulture),
                ["metadata[currency]"] = Currency,
                ["metadata[mode]"] = "test"
            };
            using var request = new HttpRequestMessage(HttpMethod.Post, "v1/checkout/sessions")
            {
                Content = new FormUrlEncodedContent(values)
            };
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", configuration.SecretKey);
            request.Headers.Add("Idempotency-Key", $"ocwbs-payment-{paymentId}");
            using var response = await StripeClient.SendAsync(request, cancellationToken);
            var responseBody = await response.Content.ReadAsStringAsync(cancellationToken);
            if (!response.IsSuccessStatusCode)
            {
                logger.LogWarning("Stripe test checkout creation failed with HTTP {StatusCode}.", (int)response.StatusCode);
                await SetAttemptStatusAsync(dataSource, paymentId, "failed", cancellationToken);
                return Results.Problem(
                    title: "Online payment is temporarily unavailable.",
                    statusCode: StatusCodes.Status503ServiceUnavailable);
            }

            using var document = JsonDocument.Parse(responseBody);
            var session = document.RootElement;
            var sessionId = GetString(session, "id");
            var checkoutUrlText = GetString(session, "url");
            if (sessionId is null ||
                checkoutUrlText is null ||
                !Uri.TryCreate(checkoutUrlText, UriKind.Absolute, out var checkoutUri) ||
                checkoutUri.Scheme != Uri.UriSchemeHttps ||
                !string.Equals(checkoutUri.Host, "checkout.stripe.com", StringComparison.OrdinalIgnoreCase) ||
                !IsStripeTestSession(session, bookingId, paymentId, amountMinor))
            {
                logger.LogError("Stripe returned a checkout session that failed test-mode or amount verification.");
                await SetAttemptStatusAsync(dataSource, paymentId, "failed", cancellationToken);
                return Results.Problem(
                    title: "Online payment is temporarily unavailable.",
                    statusCode: StatusCodes.Status503ServiceUnavailable);
            }

            await using var saveConnection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var saveCommand = new MySqlCommand("""
                UPDATE csharp_payment_attempts
                SET stripe_session_id = @sessionId, checkout_url = @checkoutUrl, status = 'open'
                WHERE id = @paymentId AND provider = 'stripe' AND status = 'creating'
                """, saveConnection);
            saveCommand.Parameters.AddWithValue("@sessionId", sessionId);
            saveCommand.Parameters.AddWithValue("@checkoutUrl", checkoutUrlText);
            saveCommand.Parameters.AddWithValue("@paymentId", paymentId);
            await saveCommand.ExecuteNonQueryAsync(cancellationToken);
            return Results.Ok(new { checkoutUrl = checkoutUrlText });
        }
        catch (HttpRequestException exception)
        {
            logger.LogWarning(exception, "Could not reach Stripe test checkout.");
            return Results.Problem(
                title: "Online payment is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
        catch (TaskCanceledException exception) when (!cancellationToken.IsCancellationRequested)
        {
            logger.LogWarning(exception, "Stripe test checkout request timed out.");
            return Results.Problem(
                title: "Online payment is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
        catch (JsonException exception)
        {
            logger.LogError(exception, "Stripe returned an invalid checkout response.");
            return Results.Problem(
                title: "Online payment is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not persist Stripe checkout details for payment {PaymentId}.", paymentId);
            return Results.Problem(
                title: "Online payment is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> HandleStripeWebhookAsync(
        HttpRequest request,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        var configuration = GetStripeConfiguration();
        if (configuration is null || string.IsNullOrWhiteSpace(configuration.WebhookSecret))
        {
            return StripeUnavailable();
        }

        if (request.ContentLength is > MaximumWebhookBytes)
        {
            return Results.StatusCode(StatusCodes.Status413PayloadTooLarge);
        }

        var bodyBytes = await ReadLimitedBodyAsync(request, cancellationToken);
        if (bodyBytes is null)
        {
            return Results.StatusCode(StatusCodes.Status413PayloadTooLarge);
        }

        var body = Encoding.UTF8.GetString(bodyBytes);
        if (!VerifyStripeSignature(request.Headers["Stripe-Signature"], bodyBytes, configuration.WebhookSecret))
        {
            return Results.BadRequest(new { message = "Invalid webhook signature." });
        }

        JsonDocument document;
        try
        {
            document = JsonDocument.Parse(body);
        }
        catch (JsonException)
        {
            return Results.BadRequest(new { message = "Invalid webhook payload." });
        }

        using (document)
        {
            var root = document.RootElement;
            var eventId = GetString(root, "id");
            var eventType = GetString(root, "type");
            if (eventId is null || eventId.Length > 255 || eventType is null)
            {
                return Results.BadRequest(new { message = "Invalid webhook event." });
            }

            if (!string.Equals(eventType, "checkout.session.completed", StringComparison.Ordinal))
            {
                return Results.Ok();
            }

            if (!root.TryGetProperty("data", out var data) ||
                !data.TryGetProperty("object", out var session) ||
                !IsPaidTestSession(session, out var sessionId, out var bookingId, out var paymentId, out var amountMinor))
            {
                return Results.BadRequest(new { message = "The completed test checkout could not be verified." });
            }

            try
            {
                await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
                await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
                await using var eventCommand = new MySqlCommand("""
                    INSERT IGNORE INTO csharp_processed_stripe_events (event_id)
                    VALUES (@eventId)
                    """, connection, transaction);
                eventCommand.Parameters.AddWithValue("@eventId", eventId);
                var inserted = await eventCommand.ExecuteNonQueryAsync(cancellationToken);
                if (inserted == 0)
                {
                    await transaction.CommitAsync(cancellationToken);
                    return Results.Ok();
                }

                await using var paymentCommand = new MySqlCommand("""
                    SELECT booking_id, amount_minor, currency, stripe_session_id, provider, status
                    FROM csharp_payment_attempts
                    WHERE id = @paymentId
                    FOR UPDATE
                    """, connection, transaction);
                paymentCommand.Parameters.AddWithValue("@paymentId", paymentId);
                await using var paymentReader = await paymentCommand.ExecuteReaderAsync(cancellationToken);
                if (!await paymentReader.ReadAsync(cancellationToken))
                {
                    return Results.BadRequest(new { message = "The payment reference was not found." });
                }

                var storedBookingId = paymentReader.GetInt64("booking_id");
                var storedAmount = paymentReader.GetInt64("amount_minor");
                var storedCurrency = paymentReader.GetString("currency");
                var storedSessionId = paymentReader.IsDBNull(paymentReader.GetOrdinal("stripe_session_id"))
                    ? null
                    : paymentReader.GetString("stripe_session_id");
                var storedProvider = paymentReader.GetString("provider");
                var storedStatus = paymentReader.GetString("status");
                await paymentReader.DisposeAsync();

                if (storedBookingId != bookingId ||
                    storedAmount != amountMinor ||
                    !string.Equals(storedCurrency, Currency, StringComparison.OrdinalIgnoreCase) ||
                    !string.Equals(storedSessionId, sessionId, StringComparison.Ordinal) ||
                    !string.Equals(storedProvider, "stripe", StringComparison.Ordinal))
                {
                    return Results.BadRequest(new { message = "The paid session does not match the stored payment." });
                }

                if (string.Equals(storedStatus, "paid", StringComparison.Ordinal))
                {
                    await transaction.CommitAsync(cancellationToken);
                    return Results.Ok();
                }

                if (!string.Equals(storedStatus, "open", StringComparison.Ordinal))
                {
                    return Results.BadRequest(new { message = "The Stripe checkout is not in a payable state." });
                }

                await using var bookingCommand = new MySqlCommand("""
                    SELECT payment_status, status, is_archived
                    FROM booking_list
                    WHERE id = @bookingId
                    FOR UPDATE
                    """, connection, transaction);
                bookingCommand.Parameters.AddWithValue("@bookingId", bookingId);
                await using var bookingReader = await bookingCommand.ExecuteReaderAsync(cancellationToken);
                if (!await bookingReader.ReadAsync(cancellationToken))
                {
                    return Results.BadRequest(new { message = "The booking was not found." });
                }

                var bookingPaymentStatus = bookingReader.GetString("payment_status");
                var bookingStatus = bookingReader.GetInt32("status");
                var isArchived = bookingReader.GetBoolean("is_archived");
                await bookingReader.DisposeAsync();
                if (bookingStatus == 5 || isArchived)
                {
                    return Results.Conflict(new { message = "Cancelled or archived bookings cannot be finalized." });
                }

                if (!string.Equals(bookingPaymentStatus, "unpaid", StringComparison.OrdinalIgnoreCase))
                {
                    return Results.Conflict(new { message = "The booking already has a recorded payment." });
                }

                await using var markPaidCommand = new MySqlCommand("""
                    UPDATE csharp_payment_attempts
                    SET status = 'paid', paid_at = UTC_TIMESTAMP()
                    WHERE id = @paymentId AND status = 'open'
                    """, connection, transaction);
                markPaidCommand.Parameters.AddWithValue("@paymentId", paymentId);
                if (await markPaidCommand.ExecuteNonQueryAsync(cancellationToken) != 1)
                {
                    return Results.Conflict(new { message = "The Stripe payment has already been processed." });
                }

                await using var updateBookingCommand = new MySqlCommand("""
                    UPDATE booking_list
                    SET payment_method = 'stripe',
                        payment_paid_at = NOW(),
                        payment_received_by = NULL,
                        cash_balance_amount = 0.00,
                        status = IF(status = 0, 1, status),
                        payment_status = 'paid'
                    WHERE id = @bookingId AND payment_status = 'unpaid'
                      AND status <> 5 AND is_archived = 0
                    """, connection, transaction);
                updateBookingCommand.Parameters.AddWithValue("@bookingId", bookingId);
                if (await updateBookingCommand.ExecuteNonQueryAsync(cancellationToken) != 1)
                {
                    return Results.Conflict(new { message = "The booking changed before payment could be finalized." });
                }
                await using var markEventCommand = new MySqlCommand("""
                    UPDATE csharp_processed_stripe_events SET processed_at = UTC_TIMESTAMP()
                    WHERE event_id = @eventId
                    """, connection, transaction);
                markEventCommand.Parameters.AddWithValue("@eventId", eventId);
                await markEventCommand.ExecuteNonQueryAsync(cancellationToken);
                await transaction.CommitAsync(cancellationToken);
                logger.LogInformation(
                    "Verified Stripe payment {PaymentId} for booking {BookingId} was recorded.",
                    paymentId,
                    bookingId);
                return Results.Ok();
            }
            catch (MySqlException exception)
            {
                logger.LogError(exception, "Could not process verified Stripe event {EventId}.", eventId);
                return Results.Problem(
                    title: "Payment notification could not be processed.",
                    statusCode: StatusCodes.Status503ServiceUnavailable);
            }
        }
    }

    private static async Task<IResult> RecordCashPaymentAsync(
        int bookingId,
        HttpContext context,
        IAntiforgery antiforgery,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        var actorId = GetActorId(context.User);
        if (actorId is null ||
            context.User.FindFirstValue(ClaimTypes.Role) is not ("admin" or "staff"))
        {
            return Results.Forbid();
        }

        if (!await antiforgery.IsRequestValidAsync(context))
        {
            return Results.BadRequest(new { message = "Refresh the page and try again." });
        }

        if (bookingId < 1)
        {
            return Results.BadRequest(new { message = "Choose a valid booking." });
        }

        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
            await using var bookingCommand = new MySqlCommand("""
                SELECT total_amount, reservation_fee, reservation_fee_payment_status,
                       payment_status, status, is_archived
                FROM booking_list
                WHERE id = @bookingId
                FOR UPDATE
                """, connection, transaction);
            bookingCommand.Parameters.AddWithValue("@bookingId", bookingId);
            await using var reader = await bookingCommand.ExecuteReaderAsync(cancellationToken);
            if (!await reader.ReadAsync(cancellationToken))
            {
                return Results.NotFound();
            }

            var amount = Convert.ToDecimal(reader.GetValue(reader.GetOrdinal("total_amount")), CultureInfo.InvariantCulture);
            var reservationFee = Convert.ToDecimal(reader.GetValue(reader.GetOrdinal("reservation_fee")), CultureInfo.InvariantCulture);
            var reservationFeeStatus = reader.GetString("reservation_fee_payment_status");
            var paymentStatus = reader.IsDBNull(reader.GetOrdinal("payment_status"))
                ? "unpaid"
                : reader.GetString("payment_status");
            var bookingStatus = reader.GetInt32("status");
            var isArchived = reader.GetBoolean("is_archived");
            await reader.DisposeAsync();
            if (bookingStatus == 5 || isArchived)
            {
                return Results.Conflict(new { message = "Cancelled or archived bookings cannot be paid." });
            }

            if (!string.Equals(paymentStatus, "unpaid", StringComparison.OrdinalIgnoreCase))
            {
                return Results.Conflict(new { message = "This booking is not currently payable." });
            }

            var cashAmount = Math.Max(0m, amount - (string.Equals(
                reservationFeeStatus, "paid", StringComparison.OrdinalIgnoreCase) ? reservationFee : 0m));
            if (cashAmount <= 0m)
            {
                return Results.Conflict(new { message = "There is no cash balance to collect for this booking." });
            }

            await using var existingPaymentCommand = new MySqlCommand("""
                SELECT 1
                FROM csharp_payment_attempts
                WHERE booking_id = @bookingId
                  AND provider = 'stripe'
                  AND status IN ('creating', 'open')
                LIMIT 1
                FOR UPDATE
                """, connection, transaction);
            existingPaymentCommand.Parameters.AddWithValue("@bookingId", bookingId);
            if (await existingPaymentCommand.ExecuteScalarAsync(cancellationToken) is not null)
            {
                return Results.Conflict(new { message = "An online payment is already in progress for this booking." });
            }

            await using var insertCommand = new MySqlCommand("""
                INSERT INTO csharp_payment_attempts
                    (booking_id, provider, amount_minor, currency, status, paid_at)
                VALUES (@bookingId, 'cash', @amountMinor, @currency, 'paid', UTC_TIMESTAMP())
                """, connection, transaction);
            insertCommand.Parameters.AddWithValue("@bookingId", bookingId);
            insertCommand.Parameters.AddWithValue("@amountMinor", ToMinorUnits(cashAmount));
            insertCommand.Parameters.AddWithValue("@currency", Currency);
            await insertCommand.ExecuteNonQueryAsync(cancellationToken);
            var paymentId = insertCommand.LastInsertedId;
            await using var updateCommand = new MySqlCommand("""
                UPDATE booking_list
                SET payment_method = 'cash',
                    payment_paid_at = NOW(),
                    payment_received_by = @actorId,
                    cash_balance_amount = @cashAmount,
                    status = IF(status = 0, 1, status),
                    payment_status = 'paid'
                WHERE id = @bookingId AND payment_status = 'unpaid' AND status <> 5 AND is_archived = 0
                """, connection, transaction);
            updateCommand.Parameters.AddWithValue("@actorId", actorId.Value);
            updateCommand.Parameters.AddWithValue("@cashAmount", cashAmount);
            updateCommand.Parameters.AddWithValue("@bookingId", bookingId);
            if (await updateCommand.ExecuteNonQueryAsync(cancellationToken) != 1)
            {
                return Results.Conflict(new { message = "The booking could not be marked paid." });
            }

            await transaction.CommitAsync(cancellationToken);
            logger.LogInformation(
                "Cash payment recorded for booking {BookingId} by {ActorId}.",
                bookingId,
                actorId.Value);
            return Results.NoContent();
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not record cash payment for booking {BookingId}.", bookingId);
            return Results.Problem(
                title: "The cash payment could not be recorded.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static int? GetActorId(ClaimsPrincipal principal) =>
        int.TryParse(
            principal.FindFirstValue(ClaimTypes.NameIdentifier),
            NumberStyles.None,
            CultureInfo.InvariantCulture,
            out var actorId) && actorId > 0
                ? actorId
                : null;

    private static async Task<IResult> GetPublicReceiptAsync(
        string receiptToken,
        string kind,
        HttpContext context,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (!IsReceiptToken(receiptToken) ||
            (kind is not "confirmation" and not "reservation"))
        {
            return Results.NotFound();
        }

        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            var receipt = await LoadReceiptAsync(connection, "b.receipt_pdf_token = @lookup", receiptToken, cancellationToken);
            if (receipt is null)
            {
                return Results.NotFound();
            }

            if (kind == "reservation" &&
                !string.Equals(receipt.PaymentStatus, "paid", StringComparison.OrdinalIgnoreCase) &&
                !string.Equals(receipt.ReservationPaymentStatus, "paid", StringComparison.OrdinalIgnoreCase))
            {
                return Results.Conflict(new { message = "A payment receipt is available after payment is verified." });
            }

            var bytes = ReceiptPdf.Create(
                receipt,
                kind == "confirmation" ? "BOOKING CONFIRMATION" : "PAYMENT RECEIPT",
                kind == "reservation"
                    ? receipt.PaymentProvider ?? "Payment"
                    : string.Equals(receipt.PaymentStatus, "paid", StringComparison.OrdinalIgnoreCase)
                        ? receipt.PaymentProvider ?? "Paid"
                        : "Pay at the shop",
                string.Equals(receipt.PaymentStatus, "paid", StringComparison.OrdinalIgnoreCase)
                    ? receipt.TotalAmount
                    : string.Equals(receipt.ReservationPaymentStatus, "paid", StringComparison.OrdinalIgnoreCase)
                        ? receipt.ReservationFee
                        : 0m,
                DateTimeOffset.UtcNow);
            context.Response.Headers.CacheControl = "private, no-store";
            return Results.File(bytes, "application/pdf", $"OCWBS-{receipt.Code}-{kind}.pdf");
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not load receipt.");
            return Results.Problem(
                title: "The receipt is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<IResult> GetCashReceiptAsync(
        int bookingId,
        HttpContext context,
        MySqlDataSource dataSource,
        ILogger<Program> logger,
        CancellationToken cancellationToken)
    {
        if (bookingId < 1)
        {
            return Results.NotFound();
        }

        try
        {
            await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
            var receipt = await LoadReceiptAsync(connection, "b.id = @lookup", bookingId, cancellationToken);
            if (receipt is null ||
                !string.Equals(receipt.PaymentStatus, "paid", StringComparison.OrdinalIgnoreCase) ||
                !string.Equals(receipt.PaymentProvider, "cash", StringComparison.OrdinalIgnoreCase))
            {
                return Results.NotFound();
            }

            var bytes = ReceiptPdf.Create(
                receipt,
                "CASH PAYMENT RECEIPT",
                "Cash at shop",
                receipt.TotalAmount,
                receipt.PaidAt ?? DateTimeOffset.UtcNow,
                receipt.RecordedAmount ?? receipt.CashBalanceAmount);
            context.Response.Headers.CacheControl = "private, no-store";
            return Results.File(bytes, "application/pdf", $"OCWBS-{receipt.Code}-cash.pdf");
        }
        catch (MySqlException exception)
        {
            logger.LogError(exception, "Could not load cash receipt for booking {BookingId}.", bookingId);
            return Results.Problem(
                title: "The receipt is temporarily unavailable.",
                statusCode: StatusCodes.Status503ServiceUnavailable);
        }
    }

    private static async Task<ReceiptData?> LoadReceiptAsync(
        MySqlConnection connection,
        string lookupSql,
        object lookupValue,
        CancellationToken cancellationToken)
    {
        var sql = $"""
            SELECT b.id, b.code, b.client_name, b.contact, b.email, b.address,
                   b.plate_number, v.name AS vehicle_name, b.schedule,
                   b.total_amount, b.reservation_fee, b.status,
                   b.cash_balance_amount, b.reservation_fee_payment_method,
                   b.reservation_fee_transaction_ref,
                   COALESCE(b.payment_status, 'unpaid') AS payment_status,
                   COALESCE(b.reservation_fee_payment_status, 'not_required') AS reservation_payment_status,
                   COALESCE(p.provider, b.payment_method) AS provider,
                   p.stripe_session_id, COALESCE(p.paid_at, b.payment_paid_at) AS paid_at,
                   p.amount_minor
            FROM booking_list b
            INNER JOIN vehicle_list v ON v.id = b.vehicle_id
            LEFT JOIN csharp_payment_attempts p ON p.id = (
                SELECT latest.id
                FROM csharp_payment_attempts latest
                WHERE latest.booking_id = b.id
                ORDER BY latest.id DESC
                LIMIT 1
            )
            WHERE {lookupSql}
            LIMIT 1
            """;
        await using var command = new MySqlCommand(sql, connection);
        command.Parameters.AddWithValue("@lookup", lookupValue);
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        if (!await reader.ReadAsync(cancellationToken))
        {
            return null;
        }

        var receipt = new ReceiptData(
            Convert.ToInt64(reader.GetValue(reader.GetOrdinal("id")), CultureInfo.InvariantCulture),
            reader.GetString("code"),
            reader.GetString("client_name"),
            reader.GetString("contact"),
            reader.GetString("email"),
            reader.GetString("address"),
            reader.GetString("plate_number"),
            reader.GetString("vehicle_name"),
            reader.GetDateTime("schedule"),
            Convert.ToDecimal(reader.GetValue(reader.GetOrdinal("total_amount")), CultureInfo.InvariantCulture),
            Convert.ToDecimal(reader.GetValue(reader.GetOrdinal("reservation_fee")), CultureInfo.InvariantCulture),
            reader.GetInt32("status"),
            reader.GetDecimal("cash_balance_amount"),
            reader.IsDBNull(reader.GetOrdinal("amount_minor"))
                ? null
                : reader.GetInt64("amount_minor") / 100m,
            reader.GetString("payment_status"),
            reader.GetString("reservation_payment_status"),
            reader.IsDBNull(reader.GetOrdinal("reservation_fee_payment_method"))
                ? null
                : reader.GetString("reservation_fee_payment_method"),
            reader.IsDBNull(reader.GetOrdinal("reservation_fee_transaction_ref"))
                ? null
                : reader.GetString("reservation_fee_transaction_ref"),
            reader.IsDBNull(reader.GetOrdinal("provider")) ? null : reader.GetString("provider"),
            reader.IsDBNull(reader.GetOrdinal("stripe_session_id")) ? null : reader.GetString("stripe_session_id"),
            reader.IsDBNull(reader.GetOrdinal("paid_at")) ? null : new DateTimeOffset(reader.GetDateTime("paid_at"), TimeSpan.Zero),
            []);
        await reader.DisposeAsync();

        await using var servicesCommand = new MySqlCommand("""
            SELECT s.name, bs.price
            FROM booking_services bs
            INNER JOIN service_list s ON s.id = bs.service_id
            WHERE bs.booking_id = @bookingId
            ORDER BY s.name
            """, connection);
        servicesCommand.Parameters.AddWithValue("@bookingId", receipt.BookingId);
        await using var servicesReader = await servicesCommand.ExecuteReaderAsync(cancellationToken);
        var services = new List<ReceiptService>();
        while (await servicesReader.ReadAsync(cancellationToken))
        {
            services.Add(new ReceiptService(
                servicesReader.GetString("name"),
                Convert.ToDecimal(servicesReader.GetValue(servicesReader.GetOrdinal("price")), CultureInfo.InvariantCulture)));
        }
        await servicesReader.DisposeAsync();

        await using var businessCommand = new MySqlCommand("""
            SELECT COALESCE(MAX(CASE WHEN meta_field = 'name' THEN meta_value END), 'OCWBS') AS business_name,
                   COALESCE(MAX(CASE WHEN meta_field = 'short_name' THEN meta_value END), 'OCWBS') AS business_short_name
            FROM system_info
            WHERE meta_field IN ('name', 'short_name')
            """, connection);
        await using var businessReader = await businessCommand.ExecuteReaderAsync(cancellationToken);
        var businessName = "OCWBS";
        var businessShortName = "OCWBS";
        if (await businessReader.ReadAsync(cancellationToken))
        {
            businessName = businessReader.GetString("business_name");
            businessShortName = businessReader.GetString("business_short_name");
        }

        return receipt with
        {
            Services = services,
            BusinessName = businessName,
            BusinessShortName = businessShortName
        };
    }

    private static async Task SetAttemptStatusAsync(
        MySqlDataSource dataSource,
        long paymentId,
        string status,
        CancellationToken cancellationToken)
    {
        await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
        await using var command = new MySqlCommand("""
            UPDATE csharp_payment_attempts
            SET status = @status
            WHERE id = @paymentId AND status = 'creating'
            """, connection);
        command.Parameters.AddWithValue("@status", status);
        command.Parameters.AddWithValue("@paymentId", paymentId);
        await command.ExecuteNonQueryAsync(cancellationToken);
    }

    private static async Task<byte[]?> ReadLimitedBodyAsync(HttpRequest request, CancellationToken cancellationToken)
    {
        await using var buffer = new MemoryStream();
        var chunk = new byte[16 * 1024];
        while (true)
        {
            var read = await request.Body.ReadAsync(chunk, cancellationToken);
            if (read == 0)
            {
                return buffer.ToArray();
            }

            if (buffer.Length + read > MaximumWebhookBytes)
            {
                return null;
            }

            await buffer.WriteAsync(chunk.AsMemory(0, read), cancellationToken);
        }
    }

    private static bool VerifyStripeSignature(string? signatureHeader, byte[] body, string secret)
    {
        if (string.IsNullOrWhiteSpace(signatureHeader))
        {
            return false;
        }

        long timestamp = 0;
        var signatures = new List<string>();
        foreach (var part in signatureHeader.Split(','))
        {
            var pair = part.Split('=', 2);
            if (pair.Length != 2)
            {
                continue;
            }

            if (pair[0] == "t")
            {
                long.TryParse(pair[1], NumberStyles.None, CultureInfo.InvariantCulture, out timestamp);
            }
            else if (pair[0] == "v1")
            {
                signatures.Add(pair[1]);
            }
        }

        var now = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
        if (timestamp <= 0 || timestamp < now - 300 || timestamp > now + 300 || signatures.Count == 0)
        {
            return false;
        }

        var signedPayload = Encoding.UTF8.GetBytes(
            timestamp.ToString(CultureInfo.InvariantCulture) + "." + Encoding.UTF8.GetString(body));
        var expected = HMACSHA256.HashData(Encoding.UTF8.GetBytes(secret), signedPayload);
        foreach (var signature in signatures)
        {
            try
            {
                var supplied = Convert.FromHexString(signature);
                if (CryptographicOperations.FixedTimeEquals(expected, supplied))
                {
                    return true;
                }
            }
            catch (FormatException)
            {
                // Ignore malformed signatures; another v1 signature may still match.
            }
        }

        return false;
    }

    private static bool IsPaidTestSession(
        JsonElement session,
        out string sessionId,
        out long bookingId,
        out long paymentId,
        out long amountMinor)
    {
        sessionId = GetString(session, "id") ?? string.Empty;
        bookingId = 0;
        paymentId = 0;
        amountMinor = 0;
        if (GetString(session, "payment_status") != "paid" ||
            !session.TryGetProperty("livemode", out var liveMode) ||
            liveMode.ValueKind != JsonValueKind.False ||
            !session.TryGetProperty("amount_total", out var amount) ||
            !amount.TryGetInt64(out amountMinor) ||
            !string.Equals(GetString(session, "currency"), Currency, StringComparison.OrdinalIgnoreCase) ||
            !string.Equals(GetString(session, "mode"), "payment", StringComparison.Ordinal) ||
            !session.TryGetProperty("metadata", out var metadata) ||
            GetString(metadata, "mode") != "test" ||
            !long.TryParse(GetString(metadata, "booking_id"), NumberStyles.None, CultureInfo.InvariantCulture, out bookingId) ||
            !long.TryParse(GetString(metadata, "payment_id"), NumberStyles.None, CultureInfo.InvariantCulture, out paymentId) ||
            !long.TryParse(GetString(metadata, "amount_minor"), NumberStyles.None, CultureInfo.InvariantCulture, out var metadataAmount) ||
            metadataAmount != amountMinor ||
            !string.Equals(GetString(metadata, "currency"), Currency, StringComparison.OrdinalIgnoreCase))
        {
            return false;
        }

        return sessionId.StartsWith("cs_test_", StringComparison.Ordinal) &&
               bookingId > 0 &&
               paymentId > 0 &&
               amountMinor > 0;
    }

    private static bool IsStripeTestSession(JsonElement session, long bookingId, long paymentId, long amountMinor) =>
        GetString(session, "mode") == "payment" &&
        session.TryGetProperty("livemode", out var liveMode) &&
        liveMode.ValueKind == JsonValueKind.False &&
        session.TryGetProperty("amount_total", out var amount) &&
        amount.TryGetInt64(out var actualAmount) &&
        actualAmount == amountMinor &&
        string.Equals(GetString(session, "currency"), Currency, StringComparison.OrdinalIgnoreCase) &&
        session.TryGetProperty("metadata", out var metadata) &&
        GetString(metadata, "mode") == "test" &&
        GetString(metadata, "booking_id") == bookingId.ToString(CultureInfo.InvariantCulture) &&
        GetString(metadata, "payment_id") == paymentId.ToString(CultureInfo.InvariantCulture) &&
        GetString(metadata, "amount_minor") == amountMinor.ToString(CultureInfo.InvariantCulture) &&
        string.Equals(GetString(metadata, "currency"), Currency, StringComparison.OrdinalIgnoreCase);

    private static StripeConfiguration? GetStripeConfiguration()
    {
        var testMode = Environment.GetEnvironmentVariable("STRIPE_TEST_MODE");
        var secretKey = Environment.GetEnvironmentVariable("STRIPE_SECRET_KEY");
        var webhookSecret = Environment.GetEnvironmentVariable("STRIPE_WEBHOOK_SECRET");
        if (!string.Equals(testMode, "true", StringComparison.OrdinalIgnoreCase) ||
            string.IsNullOrWhiteSpace(secretKey) ||
            !secretKey.StartsWith("sk_test_", StringComparison.Ordinal) ||
            string.IsNullOrWhiteSpace(webhookSecret) ||
            !webhookSecret.StartsWith("whsec_", StringComparison.Ordinal))
        {
            return null;
        }

        return new StripeConfiguration(secretKey, webhookSecret);
    }

    private static bool TryGetPublicBaseUri(out Uri? uri)
    {
        var configured = Environment.GetEnvironmentVariable("PUBLIC_BASE_URL");
        if (!Uri.TryCreate(configured, UriKind.Absolute, out uri) ||
            uri is null ||
            (uri.Scheme != Uri.UriSchemeHttps &&
             !(uri.Scheme == Uri.UriSchemeHttp && uri.IsLoopback)) ||
            !string.IsNullOrEmpty(uri.UserInfo))
        {
            uri = null;
            return false;
        }

        return true;
    }

    private static IResult StripeUnavailable() =>
        Results.Problem(
            title: "Stripe test-mode payments are not configured.",
            statusCode: StatusCodes.Status503ServiceUnavailable);

    private static bool IsReceiptToken(string token) =>
        ReceiptTokenRegex().IsMatch(token);

    private static long ToMinorUnits(decimal amount) =>
        decimal.ToInt64(decimal.Round(amount * 100m, 0, MidpointRounding.AwayFromZero));

    private static string? GetString(JsonElement element, string propertyName) =>
        element.ValueKind == JsonValueKind.Object &&
        element.TryGetProperty(propertyName, out var property) &&
        property.ValueKind == JsonValueKind.String
            ? property.GetString()
            : null;

    [GeneratedRegex("^[a-f0-9]{64}$", RegexOptions.CultureInvariant)]
    private static partial Regex ReceiptTokenRegex();

    private sealed record StripeConfiguration(string SecretKey, string? WebhookSecret);
    private sealed record PaymentVerificationRequest(string? Code);

    private sealed record ReceiptData(
        long BookingId,
        string Code,
        string CustomerName,
        string Contact,
        string Email,
        string Address,
        string PlateNumber,
        string VehicleName,
        DateTime Schedule,
        decimal TotalAmount,
        decimal ReservationFee,
        int BookingStatus,
        decimal CashBalanceAmount,
        decimal? RecordedAmount,
        string PaymentStatus,
        string ReservationPaymentStatus,
        string? ReservationFeePaymentMethod,
        string? ReservationFeeTransactionReference,
        string? PaymentProvider,
        string? StripeSessionId,
        DateTimeOffset? PaidAt,
        IReadOnlyList<ReceiptService> Services)
    {
        public string BusinessName { get; init; } = "OCWBS";
        public string BusinessShortName { get; init; } = "OCWBS";
    }

    private sealed record ReceiptService(string Name, decimal Price);

    private static class ReceiptPdf
    {
        public static byte[] Create(
            ReceiptData receipt,
            string title,
            string paymentMethod,
            decimal amountPaid,
            DateTimeOffset issuedAt,
            decimal? amountReceived = null)
        {
            var lines = new List<string>
            {
                receipt.BusinessName,
                $"Short name: {receipt.BusinessShortName}",
                title,
                "",
                $"Booking reference: {receipt.Code}",
                $"Issued: {issuedAt.ToOffset(TimeSpan.FromHours(8)):MMM dd, yyyy h:mm tt} (Manila time)",
                $"Customer: {receipt.CustomerName}",
                $"Contact: {receipt.Contact}",
                $"Email: {receipt.Email}",
                $"Address: {receipt.Address}",
                $"Vehicle: {receipt.VehicleName} - {receipt.PlateNumber}",
                $"Appointment: {receipt.Schedule:MMM dd, yyyy h:mm tt}",
                $"Booking status: {BookingStatusText(receipt.BookingStatus)}",
                "",
                "Services:"
            };

            lines.AddRange(receipt.Services.Select(service =>
                $"{service.Name} - PHP {service.Price.ToString("N2", CultureInfo.InvariantCulture)}"));
            lines.Add("");
            lines.Add($"Booking total: PHP {receipt.TotalAmount.ToString("N2", CultureInfo.InvariantCulture)}");
            lines.Add($"Reservation fee: PHP {receipt.ReservationFee.ToString("N2", CultureInfo.InvariantCulture)}");
            lines.Add($"Amount paid: PHP {amountPaid.ToString("N2", CultureInfo.InvariantCulture)}");
            if (amountReceived is not null)
            {
                lines.Add($"Amount received this transaction: PHP {amountReceived.Value.ToString("N2", CultureInfo.InvariantCulture)}");
            }
            lines.Add($"Reservation payment status: {receipt.ReservationPaymentStatus}");
            lines.Add($"Balance: PHP {Math.Max(0m, receipt.TotalAmount - amountPaid).ToString("N2", CultureInfo.InvariantCulture)}");
            lines.Add($"Payment method: {paymentMethod}");
            lines.Add($"Payment status: {receipt.PaymentStatus}");
            if (receipt.ReservationFeePaymentMethod is not null)
            {
                lines.Add($"Reservation fee method: {receipt.ReservationFeePaymentMethod}");
            }

            if (receipt.ReservationFeeTransactionReference is not null)
            {
                lines.Add($"Reservation fee reference: {receipt.ReservationFeeTransactionReference}");
            }
            if (receipt.StripeSessionId is not null)
            {
                lines.Add($"Stripe test session: {receipt.StripeSessionId}");
            }

            lines.Add("");
            lines.Add("Thank you. Keep this receipt with your booking reference.");
            return Build(lines);
        }

        private static string BookingStatusText(int status) => status switch
        {
            0 => "Pending",
            1 => "Confirmed",
            2 => "Arrived",
            3 => "In progress",
            4 => "Completed",
            5 => "Cancelled",
            _ => status.ToString(CultureInfo.InvariantCulture)
        };

        private static byte[] Build(IReadOnlyList<string> sourceLines)
        {
            const int linesPerPage = 40;
            var lines = sourceLines
                .SelectMany(WrapLine)
                .ToArray();
            var pageCount = Math.Max(1, (lines.Length + linesPerPage - 1) / linesPerPage);
            var objects = new List<byte[]>();

            objects.Add(Ascii("<< /Type /Catalog /Pages 2 0 R >>"));
            var pageRefs = string.Join(" ", Enumerable.Range(0, pageCount).Select(index => $"{4 + (index * 2)} 0 R"));
            objects.Add(Ascii($"<< /Type /Pages /Kids [{pageRefs}] /Count {pageCount} >>"));
            objects.Add(Ascii("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>"));

            for (var pageIndex = 0; pageIndex < pageCount; pageIndex++)
            {
                var contentObject = 4 + (pageIndex * 2);
                var pageObject = contentObject + 1;
                var pageLines = lines.Skip(pageIndex * linesPerPage).Take(linesPerPage);
                var content = new StringBuilder("BT\n/F1 10 Tf\n54 744 Td\n14 TL\n");
                foreach (var line in pageLines)
                {
                    content.Append('(').Append(PdfText(line)).Append(") Tj\nT*\n");
                }

                content.Append("ET");
                var contentBytes = Encoding.Latin1.GetBytes(content.ToString());
                objects.Add(Join(
                    Ascii($"<< /Length {contentBytes.Length} >>\nstream\n"),
                    contentBytes,
                    Ascii("\nendstream")));
                objects.Add(Ascii(
                    $"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents {contentObject} 0 R >>"));
            }

            using var output = new MemoryStream();
            Write(output, Ascii("%PDF-1.4\n"));
            var offsets = new List<long> { 0 };
            for (var index = 0; index < objects.Count; index++)
            {
                offsets.Add(output.Position);
                Write(output, Ascii($"{index + 1} 0 obj\n"));
                Write(output, objects[index]);
                Write(output, Ascii("\nendobj\n"));
            }

            var xrefOffset = output.Position;
            Write(output, Ascii($"xref\n0 {objects.Count + 1}\n0000000000 65535 f \n"));
            foreach (var offset in offsets.Skip(1))
            {
                Write(output, Ascii($"{offset:D10} 00000 n \n"));
            }

            Write(output, Ascii(
                $"trailer\n<< /Size {objects.Count + 1} /Root 1 0 R >>\nstartxref\n{xrefOffset}\n%%EOF\n"));
            return output.ToArray();
        }

        private static IEnumerable<string> WrapLine(string line)
        {
            const int maximumLength = 88;
            var safe = line.Select(character =>
                    character is '\r' or '\n' or '\t' || char.IsControl(character)
                        ? ' '
                        : character <= 255 ? character : '?')
                .ToArray();
            var remaining = new string(safe);
            if (remaining.Length == 0)
            {
                yield return string.Empty;
            }

            while (remaining.Length > maximumLength)
            {
                var breakAt = remaining.LastIndexOf(' ', maximumLength);
                if (breakAt < 1)
                {
                    breakAt = maximumLength;
                }

                yield return remaining[..breakAt];
                remaining = remaining[breakAt..].TrimStart();
            }

            if (remaining.Length > 0)
            {
                yield return remaining;
            }
        }

        private static string PdfText(string text) =>
            text.Replace("\\", "\\\\", StringComparison.Ordinal)
                .Replace("(", "\\(", StringComparison.Ordinal)
                .Replace(")", "\\)", StringComparison.Ordinal);

        private static byte[] Ascii(string value) => Encoding.ASCII.GetBytes(value);

        private static byte[] Join(params byte[][] arrays)
        {
            var length = arrays.Sum(array => array.Length);
            var joined = new byte[length];
            var offset = 0;
            foreach (var array in arrays)
            {
                Buffer.BlockCopy(array, 0, joined, offset, array.Length);
                offset += array.Length;
            }

            return joined;
        }

        private static void Write(Stream stream, byte[] bytes) => stream.Write(bytes, 0, bytes.Length);
    }
}
