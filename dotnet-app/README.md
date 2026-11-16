# OCWBS ASP.NET Core migration

This is the ASP.NET Core replacement being built alongside the current PHP deployment. It keeps the existing MySQL database and the static assets; PHP remains in place until its pages and workflows have C# replacements.

## Run locally

Install the .NET 10 SDK, start the existing MySQL database, then from this folder run:

```powershell
$env:DB_SERVER = "localhost"
$env:DB_PORT = "3306"
$env:DB_NAME = "ocwbs_db"
$env:DB_USERNAME = "root"
$env:DB_PASSWORD = ""
dotnet run
```

`DB_SSL=true` enables full certificate verification. Optionally set `DB_SSL_CA` to a PEM CA certificate path.

The C# app includes public service and vehicle catalogs, service pricing, appointment availability, booking submission, customer accounts, and an administrator portal. The booking API validates customer details, rechecks current prices in MySQL, applies a per-slot capacity lock, stores the booking and its selected services transactionally, and returns a confirmation receipt. Admin/staff sign-in supports existing PHP bcrypt and legacy MD5 hashes. Authorized staff can manage the catalog and bookings, review reservation-fee submissions, record cash payments, and access reports, notifications, settings, and user administration. The admin portal automatically confirms paid bookings, starts the next due service, and completes paid services after their saved duration; this progression runs on dashboard load and every 30 seconds while the page is visible. Customers can register, view linked bookings and notifications, dismiss bookings from their own history, change passwords, and request email, SMS-code, or admin-approved recovery. The payment page verifies the booking's mobile number by SMS before optional Stripe test-mode checkout. The portal also provides PDF confirmations and payment receipts.

The customer, payment-ledger, and admin password-recovery schema changes are **not applied automatically**. Back up the database, verify its current schema, and apply the relevant SQL manually before enabling each feature:

- [`../database/add_customer_accounts.sql`](../database/add_customer_accounts.sql) adds customer accounts, optional booking ownership, notifications, and recovery tables. Historical bookings are deliberately not assigned to new accounts.
- [`../database/add_customer_booking_dismissals.sql`](../database/add_customer_booking_dismissals.sql) adds the customer-owned booking-history dismissal table.
- [`../database/add_booking_payment_sms_verification.sql`](../database/add_booking_payment_sms_verification.sql) adds the SMS verification-code ledger required before online checkout.
- [`payment_module_migration.sql`](payment_module_migration.sql) adds the C# payment-attempt and Stripe webhook ledger tables.
- [`../database/add_admin_password_reset.sql`](../database/add_admin_password_reset.sql) adds admin recovery storage and `users.email`; inspect the schema first because this legacy script is not idempotent.

Other booking/payment schema scripts may also be prerequisites on an older database. Compare the scripts with the target schema before applying them; do not run the entire `database` folder blindly. Keep the PHP deployment available until the C# workflows have been tested against a backed-up test database and the production cutover has been planned.

Customer password recovery email requires `CUSTOMER_PORTAL_BASE_URL`, `CUSTOMER_SMTP_HOST`, and `CUSTOMER_SMTP_FROM`; optional settings are `CUSTOMER_SMTP_PORT`, `CUSTOMER_SMTP_USERNAME`, and `CUSTOMER_SMTP_PASSWORD`. Customer SMS-code recovery and payment phone verification require `SEMAPHORE_API_KEY`; `SEMAPHORE_SENDER_NAME` is optional. Admin recovery uses `OCWBS_PUBLIC_BASE_URL`, `OCWBS_SMTP_HOST`, `OCWBS_MAIL_FROM`, and optional `OCWBS_SMTP_PORT`, `OCWBS_SMTP_USERNAME`, `OCWBS_SMTP_PASSWORD`, and `OCWBS_SMTP_ENCRYPTION`.

Online card checkout is restricted to Stripe test mode. It requires `STRIPE_TEST_MODE=true`, a Stripe test secret key (`sk_test_…`), a webhook signing secret (`whsec_…`), and `PUBLIC_BASE_URL`. Configure Stripe to send `checkout.session.completed` events to `/api/payments/stripe/webhook`. Never use a live key with this implementation; GCash/Maya transfer references remain subject to staff review and are not represented as automated processor payments.

The public pages use the configured site name, short name, logo, cover/banner gallery, and About text from the admin system settings. The booking form uses a two-step flow, groups promo packages, supports service search, displays a review summary, and handles mobile layouts and validation feedback.

Open `/admin-login.html` to sign in as an administrator or staff member. The portal uses the existing `users` table and protects write requests with antiforgery tokens. Catalog editing is administrator-only. Successful sign-in requires a database user with `type` 1 (administrator) or 2 (staff).

Open `/customer-account.html` for the customer portal and `/payment.html` for a booking's payment/receipt links. In Development, the OpenAPI document is available at `/openapi/v1.json`; `/health/ready` checks database connectivity.

## Booking database maintenance

The active database was checked for duplicate booking references, orphaned booking-service rows, and amounts with fractional cents; those checks returned no problem rows. Its currency columns were changed from approximate `FLOAT` to exact `DECIMAL(15,2)`, and `booking_list.code` now has a unique index. See [`../database/harden_booking_integrity.sql`](../database/harden_booking_integrity.sql). Back up the database before applying this one-time migration to another environment.

To build the .NET container from the workspace root, run `docker build -f dotnet-app/Dockerfile -t ocwbs-dotnet .`. Mount persistent uploads at `/app/legacy-static/uploads` when needed.
