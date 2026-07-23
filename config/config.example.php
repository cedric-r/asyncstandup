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
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'asyncstandup',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // ── SMTP (plain relay — no AUTH) ─────────────────────────────────────────
    'smtp' => [
        'host'      => 'localhost',
        'port'      => 25,
        'from'      => 'standup@example.com',
        'from_name' => 'AsyncStandUp',
    ],

];
