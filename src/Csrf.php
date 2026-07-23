<?php

declare(strict_types=1);

/**
 * Generate (or retrieve) the CSRF token for the current session.
 *
 * Stable per session — not rotated on each validation call.
 * Session must be started before calling this function.
 */
function generateCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

/**
 * Validate the submitted CSRF token against the session token.
 *
 * Sends HTTP 403 and exits immediately on failure.
 * Uses hash_equals() to prevent timing attacks.
 *
 * @param string $submitted Token value from $_POST['csrf_token'].
 */
function validateCsrfToken(string $submitted): void
{
    $expected = (string) ($_SESSION['csrf_token'] ?? '');

    if ($expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        echo 'Forbidden — invalid CSRF token.';
        exit;
    }
}
