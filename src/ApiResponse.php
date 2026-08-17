<?php

declare(strict_types=1);

/**
 * Emit a successful JSON response with a single data payload.
 *
 * @param array<mixed>|object $data
 */
function apiOk(array|object $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

/**
 * Emit a paginated JSON list response.
 *
 * @param array<mixed> $items
 */
function apiList(array $items, int $page, int $perPage, int $total): never
{
    http_response_code(200);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'data' => $items,
        'meta' => [
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

/**
 * Emit a JSON error response.
 */
function apiError(string $message, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
