<?php

declare(strict_types=1);

// =============================================================================
// AsyncStandUp — Configuration Example
// =============================================================================
// Copy this file to config/config.php and fill in your values.
// config.php is gitignored — never commit real credentials.
// =============================================================================

return [

    // ── Application ──────────────────────────────────────────────────────────
    'app_url'  => 'http://localhost',    // Base URL (no trailing slash)
    'app_name' => 'AsyncStandUp',

    // ── Database ─────────────────────────────────────────────────────────────
    // Set 'driver' to 'mysql', 'pgsql', or 'sqlite'.
    //
    // MySQL example (default):
    //   'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
    //   'name' => 'asyncstandup', 'user' => 'root', 'pass' => '', 'charset' => 'utf8mb4'
    //
    // PostgreSQL example:
    //   'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432,
    //   'name' => 'asyncstandup', 'user' => 'postgres', 'pass' => ''
    //
    // SQLite example (development / testing):
    //   'driver' => 'sqlite', 'path' => '/var/data/asyncstandup.sqlite'
    //   (host, port, name, user, pass, charset are ignored for sqlite)
    //
    'db' => [
        'driver'  => 'mysql',
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'asyncstandup',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',   // MySQL only; ignored for pgsql/sqlite
        'path'    => '',          // SQLite only; path to .sqlite file
    ],

    // ── SMTP (plain relay — no AUTH) ─────────────────────────────────────────
    'smtp' => [
        'host'      => 'localhost',
        'port'      => 25,
        'from'      => 'standup@example.com',
        'from_name' => 'AsyncStandUp',
    ],

    // ── MS Teams Bot (optional — required for notification_channel = 'teams') ─
    // Generate credentials in Azure Portal → App registrations → your bot app.
    'teams_bot' => [
        'app_id'           => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx', // Azure Bot AppId
        'app_secret'       => 'your-bot-app-secret',
        'service_url'      => 'https://smba.trafficmanager.net/emea/', // varies by region
        'bot_webhook_path' => '/bot/webhook', // HTTPS path Teams calls back for card submissions
    ],

];
