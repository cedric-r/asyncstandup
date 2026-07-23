# US-1: Registration & Profile

**Feature**: asyncstandup-core  
**Story**: US-1

## User Story

**As a** new user  
**I can** register with email and password, then log in, log out, and update my display name and timezone  
**So that** my standup submissions and email scheduling use my correct identity and local time

## Acceptance Criteria

1. **Given** a valid email and password (min 8 chars), **When** registration form submitted, **Then** account created with bcrypt hash; user auto-logged-in; redirected to dashboard
2. **Given** email already registered, **When** registration attempted, **Then** error shown; no duplicate account created
3. **Given** registered user with correct password, **When** login form submitted, **Then** session started; redirected to dashboard
4. **Given** registered user with wrong password, **When** login form submitted, **Then** error shown; no session created
5. **Given** logged-in user, **When** logout triggered, **Then** session destroyed; redirected to login page
6. **Given** logged-in user, **When** profile page visited, **Then** display name and timezone can be updated and saved
7. **Given** any form submission, **When** processed, **Then** CSRF token validated; rejected with 403 if missing or invalid
8. **Given** unauthenticated user, **When** any protected page visited, **Then** redirected to login

## Definition of Done

- [ ] All ACs met
- [ ] Passwords stored as bcrypt via `password_hash()` — never plaintext
- [ ] CSRF token on all POST forms (`$_SESSION['csrf_token']`)
- [ ] Session fixation prevention: `session_regenerate_id(true)` on login
- [ ] SQL injection prevention: PDO parameterised queries only
- [ ] No framework, no Composer

## Files

| Action | File |
|---|---|
| Create | `public/register.php` |
| Create | `public/login.php` |
| Create | `public/logout.php` |
| Create | `public/profile.php` |
| Create | `src/Auth.php` — session helpers (`isLoggedIn`, `requireLogin`, `getCurrentUser`) |
| Create | `src/Db.php` — PDO singleton |
| Create | `src/Csrf.php` — token generate/validate |
| Create | `config/config.php` (from `config.example.php`) |
| Create | `config/config.example.php` |
| Create | `schema.sql` — full DB schema (`CREATE TABLE IF NOT EXISTS` for all 12 tables) |

## Implementation Details

### Session & Auth helpers (`src/Auth.php`)

```php
function isLoggedIn(): bool { return isset($_SESSION['user_id']); }
function requireLogin(): void { if (!isLoggedIn()) { header('Location: /login.php'); exit; } }
function getCurrentUser(PDO $pdo): ?array { ... SELECT * FROM users WHERE id = $_SESSION['user_id'] ... }
```

### Registration flow

1. Show form (GET): email, password, display_name, hidden CSRF token
2. POST: validate CSRF → sanitise inputs → check duplicate email → `password_hash()` → INSERT → set `$_SESSION['user_id']` → `session_regenerate_id(true)` → redirect

### Login flow

1. POST: validate CSRF → SELECT user by email → `password_verify()` → set session → `session_regenerate_id(true)` → redirect

### Timezone list

PHP `DateTimeZone::listIdentifiers()` — render as `<select>` in profile form. Default: `UTC`.

### Schema fragment (users)

```sql
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name  VARCHAR(100),
    timezone      VARCHAR(50) NOT NULL DEFAULT 'UTC',
    created_at    DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Security Notes

- `htmlspecialchars(ENT_QUOTES, 'UTF-8')` on all output
- No `$_GET`/`$_POST` interpolated into SQL — PDO only
- `session.cookie_httponly = 1`, `session.cookie_samesite = Lax` set via `ini_set` before `session_start()`
- Rate limiting: not in scope for initial version; document as known absence in README
