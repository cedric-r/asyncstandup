# AsyncStandUp

Async standup system — vanilla PHP 8.1, MySQL, raw-socket SMTP. No framework, no Composer.

## Requirements

- PHP ≥ 8.1 (`pdo_mysql`, `openssl` extensions)
- MySQL ≥ 5.7 / MariaDB ≥ 10.3
- SMTP relay reachable from the web server

## Setup

### 1. Database

```bash
mysql -u root -p < db/schema.sql
```

### 2. Configuration

```bash
cp config/config.example.php config/config.php
# Edit config/config.php with your DB credentials, SMTP host, and app URL
```

### 3. Web server

Point document root to `public/`. Rewrite all requests to `public/index.php` (Apache) or use `try_files` (Nginx).

Apache `.htaccess`:
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

### 4. Cron

Run every minute on the application server:

```cron
* * * * * php /path/to/standup/cron/send_standups.php >> /path/to/standup/logs/cron.log 2>&1
```

## Security Notes

- `config/config.php` is gitignored — never commit real credentials
- All HTML output is `htmlspecialchars()`'d — no raw interpolation
- CSRF tokens validated on every POST (`hash_equals`)
- Passwords stored as bcrypt — never plaintext
- Standup submission uses a token URL — no session required for that page
- `display_errors` suppressed at runtime; uncaught exceptions logged via `error_log`
- Session cookies: `HttpOnly`, `SameSite=Lax`, `Secure`, `use_strict_mode` — requires HTTPS in production
- Logs directory: ensure `logs/` is not web-accessible; add `Deny from all` / `return 403` in web server config

### SMTP cleartext warning (L-2)

The built-in mailer uses a plain TCP socket (`tcp://host:port`). Email content — including standup answers and token URLs — is transmitted **in cleartext** unless the relay enforces TLS internally.

**Minimum required**: configure the SMTP relay (`config['smtp']['host']`) to be:
- `localhost` (loopback, not exposed to the network), or
- a private-network relay that enforces TLS on the path to the internet.

**Optional hardening**: switch to `ssl://` on port 465 in `src/Mailer.php` if your relay supports SMTPS:
```php
$socket = stream_socket_client("ssl://{$host}:{$port}", ...);
```
This requires `extension=openssl` in `php.ini`.

### Login rate limiting (L-3)

No rate limiting is implemented in PHP. bcrypt slows individual attempts but does not prevent automation.

**Recommended mitigation** (choose one based on your infrastructure):
- **Reverse proxy** (preferred): use `nginx` `limit_req_zone` or equivalent on the `/login.php` route — this stops attempts before they reach PHP.
- **CDN / WAF**: most WAF products have brute-force protection rules for login endpoints.
- **DB-backed counter**: not implemented to keep this project dependency-free; can be added as a separate story if proxy-level protection is unavailable.

## Running Tests (US-9)

### 1. Download PHPUnit PHAR

```bash
wget https://phar.phpunit.de/phpunit-10.phar -O tests/phpunit.phar
```

Verify integrity against the published checksum at `https://phar.phpunit.de/phpunit-10.phar.sha256asc`:

```bash
sha256sum tests/phpunit.phar  # compare against published SHA-256
```

The PHAR is gitignored — run this once per developer machine.

### 2. Run the suite

```bash
php tests/phpunit.phar --configuration tests/phpunit.xml
```

Tests use an in-memory SQLite database — no MySQL connection required. All 21 tests should pass with exit code 0.

---

## Architecture

```
config/          — configuration (only example committed)
cron/            — CLI scripts for timed email delivery
db/              — schema.sql
logs/            — error logs (gitignored *.log)
public/          — document root; PHP pages
src/             — plain PHP classes (no autoloading)
templates/       — HTML layout and email templates
```
