<?php

declare(strict_types=1);

/**
 * Escape a string for safe HTML output.
 *
 * Shorthand for htmlspecialchars($s, ENT_QUOTES, 'UTF-8').
 * Use in templates wherever user-supplied or DB-sourced strings are echoed.
 */
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
