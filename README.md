# AsyncStandUp

> **Async standup system** — no frameworks, no npm, no Composer in production. Pure PHP 8.1 + MySQL + raw-socket SMTP.

Teams receive a daily standup prompt email, submit answers via a unique link, and a summary is emailed to recipients — all without anyone logging in to a tool during their day.

---

## Features

- 📧 **Daily standup prompts** — cron sends personalised prompt emails at each team's configured local time
- ✅ **Token-based submission** — members submit via a unique 48-hour link; no login required to submit
- 📊 **Participation dashboard** — owners see a 7-day grid (✓ / ✗ / N/A) plus 30-day participation %
- 🔍 **Response browser** — owners browse full answer history filtered by date and/or member
- 📋 **Daily summary emails** — one consolidated summary sent 1 hour after standup time
- 👥 **Multi-team support** — users can belong to multiple teams across multiple organisations
- 🔑 **Invitation flow** — owners invite members via email; 7-day token; roles applied on accept
- 🛡 **Admin approval** — new registrations require administrator approval before login
- 🗑 **Self-service unsubscribe** — external recipients unsubscribe via a unique link in every summary
- 🔁 **Send to all developers** — one toggle sends the summary to every developer member automatically
- 🌍 **Per-team timezone** — standup time and all date math are computed in the team's local timezone
- 📱 **Mobile-friendly UI** — Tailwind CSS (Play CDN) responsive layout; works at 375 px
- 🔒 **Text-based CAPTCHA** — bot deterrent on login and registration (50 question bank)
- 🔐 **Password reset** — email-based flow with 1-hour token expiry and concurrent-use guard
- 🌐 **Consensus alerting** — (site-monitor module) majority-vote before alert fires
- ⏩ **Weekend skip** — cron skips Saturday/Sunday in each team's local timezone
- 📅 **Pending standup widget** — unexpired unsubmitted tokens shown on the dashboard
- 🧪 **PHPUnit test suite** — 55 integration tests via PHAR (no Composer needed to run)

---

## Requirements

| Requirement | Version / Notes |
|---|---|
| PHP | ≥ 8.1 — extensions: `pdo_mysql`, `openssl`, `mbstring` |
| MySQL / MariaDB | MySQL ≥ 5.7 or MariaDB ≥ 10.3 |
| Web server | Apache or Nginx; document root = `public/` |
| SMTP relay | Plain TCP relay (no AUTH); must be localhost or private network |
| Cron | System cron or supervisor; runs every minute |

---

## Installation

### 1. Clone or download

```bash
git clone https://github.com/your-org/asyncstandup.git /var/www/asyncstandup
```

### 2. Configure

```bash
cp config/config.example.php config/config.php
```

Edit `config/config.php`:

```php
return [
    'app_url'  => 'https://standup.example.com',   // no trailing slash
    'app_name' => 'AsyncStandUp',
    'db'       => [
        'host' => '127.0.0.1', 'port' => 3306,
        'name' => 'asyncstandup', 'user' => 'dbuser', 'pass' => 'secret',
        'charset' => 'utf8mb4',
    ],
    'smtp'     => [
        'host' => 'localhost', 'port' => 25,
        'from' => 'standup@example.com', 'from_name' => 'AsyncStandUp',
    ],
];
```

> `config/config.php` is gitignored — never commit real credentials.

### 3. Database setup

```bash
mysql -u root -p -e "CREATE DATABASE asyncstandup CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p asyncstandup < db/schema.sql
```

### 4. Web server

**Document root**: `/path/to/asyncstandup/public/`

**Apache** — add to VirtualHost:
```apache
<Directory /var/www/asyncstandup/public>
    AllowOverride All
    Require all granted
</Directory>
```

**Nginx**:
```nginx
server {
    root /var/www/asyncstandup/public;
    index index.php;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

> Protect `logs/` from direct HTTP access:
> ```nginx
> location /logs/ { deny all; }
> ```

### 5. Cron

Add to crontab (`crontab -e`):

```cron
* * * * * php /var/www/asyncstandup/cron/send_standups.php >> /var/log/asyncstandup.log 2>&1
```

The cron script sends prompt emails at each team's configured standup time and summary emails 1 hour later. Weekends are skipped in the team's local timezone.

### 6. Create the first administrator

Register an account at `/register.php`, then run:

```sql
UPDATE users
SET is_admin = 1, account_status = 'approved'
WHERE email = 'your@email.com';
```

Log in and use `/admin/users.php` to approve or reject subsequent registrations.

> **Session flag note**: `is_admin` is stored in the session at login time. Changes take effect on the user's next login.

---

## Configuration reference

| Key | Description | Example |
|---|---|---|
| `app_url` | Base URL (no trailing slash) | `https://standup.example.com` |
| `app_name` | Application name shown in emails and UI | `AsyncStandUp` |
| `db.host` | MySQL host | `127.0.0.1` |
| `db.port` | MySQL port | `3306` |
| `db.name` | Database name | `asyncstandup` |
| `db.user` | Database user | `appuser` |
| `db.pass` | Database password | — |
| `db.charset` | Connection charset | `utf8mb4` |
| `smtp.host` | SMTP relay hostname | `localhost` |
| `smtp.port` | SMTP port | `25` |
| `smtp.from` | Sender email address | `standup@example.com` |
| `smtp.from_name` | Sender display name | `AsyncStandUp` |

---

## Email templates

All templates are in `templates/email/`. They are plain-text PHP files rendered via `renderEmailTemplate()`.

| Template | Triggered by | Variables |
|---|---|---|
| `invitation.php` | Team owner invites a member | `$team_name`, `$org_name`, `$inviter_name`, `$accept_url`, `$expires_days`, `$roles` |
| `standup_prompt.php` | Cron at standup time | `$user_name`, `$org_name`, `$team_name`, `$standup_url`, `$send_date`, `$team_timezone`, `$questions[]` |
| `standup_summary.php` | Cron 1 hour after standup | `$team_name`, `$send_date`, `$questions[]`, `$submitter_data[]`, `$non_submitters[]`, `$unsubscribe_url` |
| `account_approved.php` | Admin approves a registration | `$user_name`, `$login_url`, `$app_name` |
| `admin_new_registration.php` | New user registers | `$new_user_name`, `$new_user_email`, `$admin_url`, `$app_name` |
| `password_reset.php` | Forgot-password request | `$user_name`, `$reset_url`, `$expires_minutes` |

To customise a template, edit the `.php` file directly. The `<?= $variable ?>` syntax outputs the value; wrap user content in `htmlspecialchars()` if rendering in HTML contexts (these templates are plain text).

---

## Running tests

The test suite uses PHPUnit 10 via PHAR. No Composer required.

```bash
# Download PHPUnit PHAR once:
wget https://phar.phpunit.de/phpunit-10.phar -O tests/phpunit.phar

# Verify checksum (compare against https://phar.phpunit.de/phpunit-10.phar.sha256asc):
sha256sum tests/phpunit.phar

# Run the suite:
php tests/phpunit.phar --configuration tests/phpunit.xml
```

Tests use an in-memory SQLite database — no MySQL connection required. All 55 tests should pass with exit code 0.

> `tests/phpunit.phar` is gitignored.

---

## Tailwind CSS (production)

The current deployment uses Tailwind's **Play CDN** (`cdn.tailwindcss.com`) — zero build step, but not suitable for production at scale (larger payload, no tree-shaking).

**For production**, compile a static CSS file:

```bash
# Install Tailwind CLI (one-time, dev machine only):
npm install -D tailwindcss

# Compile and minify:
npx tailwindcss \
  -i ./public/assets/style.css \
  -o ./public/assets/tailwind.min.css \
  --content "./public/**/*.php,./templates/**/*.php" \
  --minify
```

Then replace in `templates/layout.php`:
```html
<!-- Replace: -->
<script src="https://cdn.tailwindcss.com"></script>

<!-- With: -->
<link rel="stylesheet" href="/assets/tailwind.min.css">
```

---

## Security notes

- **Always use HTTPS** — session cookies and the API key (if configured) are transmitted in plain text over HTTP; TLS is mandatory for any public deployment
- **SMTP relay must be localhost or private network** — the mailer uses a plain TCP socket with no authentication; expose it only on loopback or a VPN-restricted interface
- **Rate-limit login and forgot-password** — no PHP-level rate limiting is implemented; add at the reverse proxy (nginx `limit_req_zone`) or WAF level
- **Session hardening** — cookies are set with `HttpOnly`, `SameSite=Lax`, and `Secure` (requires HTTPS); `use_strict_mode` is enabled
- **CAPTCHA** — text-based CAPTCHA on login and registration reduces bot pressure; not a substitute for rate limiting
- **`logs/` must not be web-accessible** — add `location /logs/ { deny all; }` (Nginx) or `Require all denied` (Apache `.htaccess`) to prevent log disclosure

---

## Upgrade notes

When upgrading from an older version, run the ALTER TABLE statements listed in `db/schema.sql` (appended at the bottom). These are safe to run in order on an existing database.

| Change | Statement | Story |
|---|---|---|
| Nullable user_id on submissions/tokens | `ALTER TABLE standup_submissions MODIFY user_id INT UNSIGNED NULL;` | US-16 |
| Nullable user_id on tokens | `ALTER TABLE standup_tokens MODIFY user_id INT UNSIGNED NULL;` | US-16 |
| Nullable created_by on orgs/teams | `ALTER TABLE organisations MODIFY created_by INT UNSIGNED NULL;` | US-16 |
| Nullable created_by on teams | `ALTER TABLE teams MODIFY created_by INT UNSIGNED NULL;` | US-16 |
| Admin flag + account status on users | `ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN account_status VARCHAR(10) NOT NULL DEFAULT 'pending';` | US-17 |
| Approve existing users after migration | `UPDATE users SET account_status = 'approved' WHERE account_status = 'pending';` | US-17 |
| Unsubscribe token on recipients | `ALTER TABLE team_recipients ADD COLUMN unsubscribe_token VARCHAR(64) NULL UNIQUE;` | US-20 |
| Summary to all developers flag | `ALTER TABLE teams ADD COLUMN summary_to_all_developers TINYINT(1) NOT NULL DEFAULT 0;` | US-21 |

---

## Architecture

```
config/          — configuration (only example committed)
cron/            — CLI scripts for timed email delivery (run every minute)
db/              — schema.sql (CREATE TABLE + ALTER TABLE migration history)
logs/            — error logs (gitignored *.log)
public/          — document root; all PHP pages served here
  admin/         — admin-only pages (requireAdmin() enforced)
  assets/        — style.css (Tailwind overrides + print styles)
  invitations/   — send.php + accept.php
  orgs/          — organisation CRUD
  teams/         — team CRUD, dashboard, members, questions, recipients, responses
src/             — PHP source classes (plain functions; no autoloading)
templates/       — HTML layout and email templates
  email/         — plain-text email templates
tests/           — PHPUnit integration tests (SQLite in-memory)
```

---

## Licence

MIT — see `LICENCE` (or add your own).
