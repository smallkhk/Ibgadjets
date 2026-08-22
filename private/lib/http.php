<?php
/**
 * JSON request/response plumbing shared by every endpoint in api/.
 */

declare(strict_types=1);

function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ok($data = [], int $code = 200): never
{
    json_out(['ok' => true] + (array) $data, $code);
}

function fail(string $message, int $code = 400, array $extra = []): never
{
    json_out(['ok' => false, 'error' => $message] + $extra, $code);
}

/** Decoded JSON body, falling back to form-encoded POST. */
function body(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $raw = file_get_contents('php://input') ?: '';
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $cache = $decoded;
        }
    }
    return $cache = $_POST;
}

function input(string $key, $default = null)
{
    $b = body();
    if (array_key_exists($key, $b)) {
        return $b[$key];
    }
    return $_GET[$key] ?? $default;
}

function want(string $key): string
{
    $v = trim((string) input($key, ''));
    if ($v === '') {
        fail("Missing field: {$key}", 422);
    }
    return $v;
}

function method(): string
{
    return $_SERVER['REQUEST_METHOD'] ?? 'GET';
}

function require_method(string ...$allowed): void
{
    if (!in_array(method(), $allowed, true)) {
        fail('Method not allowed', 405);
    }
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
