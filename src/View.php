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

/**
 * Render a PHP email template to a string.
 *
 * Runs the template inside a dedicated function scope so that:
 * - Only the explicitly passed $vars are visible inside the template
 * - No file-scope variable pollution affects the template
 * - EXTR_OVERWRITE guarantees all passed variables are set correctly
 *
 * @param string               $path Absolute filesystem path to the template.
 * @param array<string, mixed> $vars Variables to expose inside the template.
 * @return string Rendered template content, or '' on failure.
 */
function renderEmailTemplate(string $path, array $vars): string
{
    if (!file_exists($path)) {
        error_log('[AsyncStandUp] renderEmailTemplate: file not found: ' . $path);
        return '';
    }

    $render = static function (string $__path, array $__vars): string {
        extract($__vars, EXTR_OVERWRITE);
        ob_start();
        include $__path;
        $content = ob_get_clean();
        return $content !== false ? $content : '';
    };

    return $render($path, $vars);
}
