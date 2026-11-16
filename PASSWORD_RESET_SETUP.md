# Administrator password reset setup

The self-service reset sends a one-time link to the recovery email saved on each administrator or staff account. Links expire after one hour, can be used once, and requests are limited to three per account per hour.

## 1. Update the database

Back up `ocwbs_db` first. In phpMyAdmin, select that database and import [`database/add_admin_password_reset.sql`](./database/add_admin_password_reset.sql) once. This adds the recovery-email field and the token table; existing accounts keep an empty recovery email until it is configured.

Then sign in, open **User Management**, and save a unique, working recovery email for each account that needs password recovery. A reset cannot be delivered to an account with no email.

## 2. Configure outgoing email

Install the mail dependency from the project root:

```powershell
composer install --no-dev --optimize-autoloader
```

For Gmail, first enable 2-Step Verification on the sender Google account and create a Google App Password. A normal Gmail password is not an SMTP app password. Then set these environment variables in the Apache/PHP server environment:

```text
OCWBS_SMTP_HOST=smtp.gmail.com
OCWBS_SMTP_PORT=587
OCWBS_SMTP_ENCRYPTION=tls
OCWBS_SMTP_USERNAME=your-sender@gmail.com
OCWBS_SMTP_PASSWORD=your-google-app-password
OCWBS_MAIL_FROM=your-sender@gmail.com
OCWBS_BASE_URL=http://localhost/ocwbs/
```

`OCWBS_SMTP_USERNAME` and `OCWBS_MAIL_FROM` are the sender Gmail account. The recovery email saved on a user account is the recipient and can be a different address. Keep the app password in the server environment only, never in the repository or chat, and restart Apache after changing environment variables. For a public deployment, set `OCWBS_BASE_URL` to the HTTPS public URL instead of localhost.

## 3. Recovery behavior

The request page intentionally gives the same confirmation whether or not an account exists. Reset tokens are stored as SHA-256 hashes, new passwords are stored with PHP's password hashing, and a successful reset invalidates outstanding reset links and signs out the current session. New passwords must contain at least 12 characters.

Existing MD5 account passwords continue to work and are upgraded to PHP password hashes after a successful sign-in.
