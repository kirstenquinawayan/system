using System.Globalization;
using System.Security.Claims;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.StaticFiles;
using MySqlConnector;

var builder = WebApplication.CreateBuilder(args);
var database = DatabaseConfiguration.CreateConnectionString();
builder.Services.AddSingleton(database);
builder.Services.AddAuthentication(CookieAuthenticationDefaults.AuthenticationScheme)
    .AddCookie(options =>
    {
        options.Cookie.Name = ".Ocwbs.Admin";
        options.Cookie.HttpOnly = true;
        options.Cookie.SameSite = SameSiteMode.Strict;
        options.Cookie.SecurePolicy = CookieSecurePolicy.SameAsRequest;
        options.ExpireTimeSpan = TimeSpan.FromHours(8);
        options.SlidingExpiration = true;
        options.Events.OnRedirectToLogin = context =>
        {
            context.Response.StatusCode = StatusCodes.Status401Unauthorized;
            return Task.CompletedTask;
        };
        options.Events.OnRedirectToAccessDenied = context =>
        {
            context.Response.StatusCode = StatusCodes.Status403Forbidden;
            return Task.CompletedTask;
        };
    })
    .AddCookie(CustomerEndpoints.AuthenticationScheme, options =>
    {
        options.Cookie.Name = ".Ocwbs.Customer";
        options.Cookie.HttpOnly = true;
        options.Cookie.SameSite = SameSiteMode.Strict;
        options.Cookie.SecurePolicy = CookieSecurePolicy.SameAsRequest;
        options.ExpireTimeSpan = TimeSpan.FromHours(8);
        options.SlidingExpiration = true;
        options.Events.OnRedirectToLogin = context =>
        {
            context.Response.StatusCode = StatusCodes.Status401Unauthorized;
            return Task.CompletedTask;
        };
        options.Events.OnRedirectToAccessDenied = context =>
        {
            context.Response.StatusCode = StatusCodes.Status403Forbidden;
            return Task.CompletedTask;
        };
    });
builder.Services.AddAuthorization(options =>
{
    options.AddPolicy(CustomerEndpoints.AuthorizationPolicy, policy =>
    {
        policy.AddAuthenticationSchemes(CustomerEndpoints.AuthenticationScheme);
        policy.RequireAuthenticatedUser();
        policy.RequireClaim(ClaimTypes.NameIdentifier);
    });
});
builder.Services.AddOpenApi();
builder.Services.AddAntiforgery(options =>
{
    options.HeaderName = "X-CSRF-TOKEN";
    options.Cookie.Name = ".Ocwbs.AntiForgery";
    options.Cookie.HttpOnly = true;
    options.Cookie.SameSite = SameSiteMode.Strict;
    options.Cookie.SecurePolicy = CookieSecurePolicy.SameAsRequest;
});
var app = builder.Build();

app.UseExceptionHandler(errorApp => errorApp.Run(async context =>
{
    var error = context.Features.Get<Microsoft.AspNetCore.Diagnostics.IExceptionHandlerFeature>()?.Error;
    if (error is not null)
    {
        app.Logger.LogError(error, "Unhandled application error.");
    }

    context.Response.StatusCode = StatusCodes.Status500InternalServerError;
    await context.Response.WriteAsJsonAsync(new { error = "An unexpected error occurred." });
}));

app.UseAuthentication();
app.UseAuthorization();

if (app.Environment.IsDevelopment())
{
    app.MapOpenApi();
}

var workspaceRoot = Path.GetFullPath("..", app.Environment.ContentRootPath);
var bundledStaticRoot = Path.Combine(app.Environment.ContentRootPath, "legacy-static");
foreach (var directory in new[] { "assets", "dist", "plugins", "uploads" })
{
    var workspacePath = Path.Combine(workspaceRoot, directory);
    var physicalPath = Directory.Exists(workspacePath)
        ? workspacePath
        : Path.Combine(bundledStaticRoot, directory);
    if (Directory.Exists(physicalPath))
    {
        app.UseStaticFiles(new StaticFileOptions
        {
            FileProvider = new Microsoft.Extensions.FileProviders.PhysicalFileProvider(physicalPath),
            RequestPath = $"/{directory}"
        });
    }
}

app.UseDefaultFiles();
app.UseStaticFiles();

app.MapGet("/health/ready", async (MySqlDataSource dataSource, CancellationToken cancellationToken) =>
{
    try
    {
        await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
        await using var command = new MySqlCommand("SELECT 1", connection);
        await command.ExecuteScalarAsync(cancellationToken);
        return Results.Ok(new { status = "ready" });
    }
    catch (MySqlException exception)
    {
        app.Logger.LogError(exception, "Database readiness check failed.");
        return Results.Problem(
            title: "Database unavailable",
            statusCode: StatusCodes.Status503ServiceUnavailable);
    }
});

app.MapGet("/api/public/services", async (
    MySqlDataSource dataSource,
    ILogger<Program> logger,
    CancellationToken cancellationToken) =>
{
    try
    {
        await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
        await SetManilaTimeZoneAsync(connection, cancellationToken);
        const string sql = """
            SELECT id, name, description, duration_minutes
            FROM service_list
            WHERE delete_flag = 0 AND status = 1
            ORDER BY CASE name WHEN 'Car Wash' THEN 0 WHEN 'Car Repaint' THEN 1 ELSE 2 END, name ASC
            """;
        await using var command = new MySqlCommand(sql, connection);
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        var services = new List<ServiceResponse>();
        while (await reader.ReadAsync(cancellationToken))
        {
            services.Add(new ServiceResponse(
                reader.GetInt32("id"),
                reader.GetString("name"),
                reader.GetString("description"),
                reader.GetInt32("duration_minutes"),
                FormatDuration(reader.GetInt32("duration_minutes"))));
        }

        return Results.Ok(services);
    }
    catch (MySqlException exception)
    {
        logger.LogError(exception, "Could not load public services.");
        return Results.Problem(
            title: "Services are temporarily unavailable.",
            statusCode: StatusCodes.Status503ServiceUnavailable);
    }
});

app.MapGet("/api/public/services/{serviceId:int}/prices", async (
    int serviceId,
    MySqlDataSource dataSource,
    ILogger<Program> logger,
    CancellationToken cancellationToken) =>
{
    if (serviceId < 1)
    {
        return Results.BadRequest(new { error = "Choose a valid service." });
    }

    try
    {
        await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
        await SetManilaTimeZoneAsync(connection, cancellationToken);
        const string sql = """
            SELECT s.id, s.name, v.name AS vehicle_name, p.price
            FROM service_list s
            LEFT JOIN vehicle_list v ON v.delete_flag = 0 AND v.status = 1
            LEFT JOIN price_list p ON p.service_id = s.id AND p.vehicle_id = v.id
            WHERE s.id = @serviceId AND s.delete_flag = 0 AND s.status = 1
            ORDER BY CASE v.name
                WHEN 'Sedan' THEN 1 WHEN 'AUV' THEN 2 WHEN 'MPV' THEN 3
                WHEN 'Pick Up' THEN 4 WHEN 'Ordinary Van' THEN 5 WHEN 'Premium Van' THEN 6
                ELSE 99 END, v.name ASC
            """;
        await using var command = new MySqlCommand(sql, connection);
        command.Parameters.AddWithValue("@serviceId", serviceId);
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        var prices = new List<VehiclePriceResponse>();
        string? serviceName = null;
        while (await reader.ReadAsync(cancellationToken))
        {
            serviceName ??= reader.GetString("name");
            if (!reader.IsDBNull(reader.GetOrdinal("vehicle_name")))
            {
                decimal? price = reader.IsDBNull(reader.GetOrdinal("price"))
                    ? null
                    : Convert.ToDecimal(reader.GetValue(reader.GetOrdinal("price")), CultureInfo.InvariantCulture);
                prices.Add(new VehiclePriceResponse(reader.GetString("vehicle_name"), price));
            }
        }

        return serviceName is null
            ? Results.NotFound(new { error = "Service prices are unavailable." })
            : Results.Ok(new ServicePricesResponse(serviceId, serviceName, prices));
    }
    catch (MySqlException exception)
    {
        logger.LogError(exception, "Could not load prices for service {ServiceId}.", serviceId);
        return Results.Problem(
            title: "Service prices are temporarily unavailable.",
            statusCode: StatusCodes.Status503ServiceUnavailable);
    }
});

app.MapGet("/api/public/vehicles", async (
    MySqlDataSource dataSource,
    ILogger<Program> logger,
    CancellationToken cancellationToken) =>
{
    try
    {
        await using var connection = await dataSource.OpenConnectionAsync(cancellationToken);
        const string sql = """
            SELECT id, name FROM vehicle_list
            WHERE status = 1 AND delete_flag = 0
            ORDER BY CASE name
                WHEN 'Sedan' THEN 1 WHEN 'AUV' THEN 2 WHEN 'MPV' THEN 3
                WHEN 'Pick Up' THEN 4 WHEN 'Ordinary Van' THEN 5 WHEN 'Premium Van' THEN 6
                ELSE 99 END, name ASC
            """;
        await using var command = new MySqlCommand(sql, connection);
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        var vehicles = new List<VehicleResponse>();
        while (await reader.ReadAsync(cancellationToken))
        {
            vehicles.Add(new VehicleResponse(reader.GetInt32("id"), reader.GetString("name")));
        }

        return Results.Ok(vehicles);
    }
    catch (MySqlException exception)
    {
        logger.LogError(exception, "Could not load public vehicles.");
        return Results.Problem(
            title: "Vehicles are temporarily unavailable.",
            statusCode: StatusCodes.Status503ServiceUnavailable);
    }
});

app.MapBookingEndpoints();
app.MapAdminEndpoints();
app.MapCustomerEndpoints();
app.MapPaymentEndpoints();
app.MapAdminOperationsEndpoints();

app.Run();

static async Task SetManilaTimeZoneAsync(MySqlConnection connection, CancellationToken cancellationToken)
{
    await using var command = new MySqlCommand("SET time_zone = '+08:00'", connection);
    await command.ExecuteNonQueryAsync(cancellationToken);
}

static string FormatDuration(int minutes)
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

    if (minutes >= 60)
    {
        return $"{minutes / 60} hours {minutes % 60} minutes";
    }

    return $"{minutes} minutes";
}

internal sealed record ServiceResponse(
    int Id,
    string Name,
    string Description,
    int DurationMinutes,
    string Duration);

internal sealed record VehiclePriceResponse(string Vehicle, decimal? Price);
internal sealed record ServicePricesResponse(int ServiceId, string Service, IReadOnlyList<VehiclePriceResponse> Prices);
internal sealed record VehicleResponse(int Id, string Name);

internal static class DatabaseConfiguration
{
    public static MySqlDataSource CreateConnectionString()
    {
        var builder = new MySqlConnectionStringBuilder
        {
            Server = Environment.GetEnvironmentVariable("DB_SERVER") ?? "localhost",
            Port = ParsePort(Environment.GetEnvironmentVariable("DB_PORT")),
            UserID = Environment.GetEnvironmentVariable("DB_USERNAME") ?? "root",
            Password = Environment.GetEnvironmentVariable("DB_PASSWORD") ?? string.Empty,
            Database = Environment.GetEnvironmentVariable("DB_NAME") ?? "ocwbs_db",
            CharacterSet = "utf8mb4",
            SslMode = MySqlSslMode.None
        };

        if (string.Equals(Environment.GetEnvironmentVariable("DB_SSL"), "true", StringComparison.OrdinalIgnoreCase))
        {
            builder.SslMode = MySqlSslMode.VerifyFull;
            var caFile = Environment.GetEnvironmentVariable("DB_SSL_CA");
            if (!string.IsNullOrWhiteSpace(caFile))
            {
                if (!File.Exists(caFile))
                {
                    throw new InvalidOperationException("The configured DB_SSL_CA file does not exist.");
                }

                builder.SslCa = caFile;
            }
        }

        return new MySqlDataSource(builder.ConnectionString);
    }

    private static uint ParsePort(string? configuredPort)
    {
        return uint.TryParse(configuredPort, NumberStyles.None, CultureInfo.InvariantCulture, out var port)
            ? port is > 0 and <= 65535 ? port : 3306
            : 3306;
    }
}
