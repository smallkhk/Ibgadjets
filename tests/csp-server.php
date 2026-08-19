<?php
/**
 * Serve public_html with the SAME Content-Security-Policy that .htaccess
 * sends in production, so the pages get tested the way Apache actually
 * delivers them.
 *
 *   php -S 127.0.0.1:8840 -t public_html tests/csp-server.php
 *   node tests/csp-check.js
 *
 * This exists because php -S ignores .htaccess. Every page was verified
 * against a server that sent no CSP at all, so an entire class of
 * breakage — inline scripts and onclick handlers being refused by the
 * browser — was invisible until it reached the live site.
 */
// php -S drops router headers when it serves a static file itself, so
// serve the bytes here and the CSP sticks.
// Must stay byte-for-byte identical to the header in
// public_html/.htaccess. It drifted once — this still allowed Google
// Fonts long after production had stopped, so every run reported font
// refusals that would never happen live, and a real refusal would have
// been lost in the noise. If you change one, change the other.
$csp = "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
     . "font-src 'self'; script-src 'self'; "
     . "connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Run the REAL api, not a mock. This used to require a mock-router.php
// that is not in the repository, so every /api/ call fatalled and every
// page rendered empty — the checks failed for a reason that had nothing
// to do with the CSP they exist to test.
//
// Pointing at the real endpoints also means this harness catches a page
// that breaks because the API's shape changed, which a mock by
// definition never would.
if (str_starts_with($path, '/api/')) {
    header("Content-Security-Policy: $csp");
    $api = realpath(dirname(__DIR__) . '/public_html' . $path);
    if ($api && str_starts_with($api, dirname(__DIR__) . '/public_html/api/') && is_file($api)) {
        require $api;
    } else {
        http_response_code(404);
        echo '{"ok":false,"error":"no such endpoint"}';
    }
    return;
}
$root = dirname(__DIR__) . '/public_html';
$file = realpath($root . ($path === '/' ? '/index.html' : $path));
if (!$file || !str_starts_with($file, $root) || !is_file($file)) { http_response_code(404); echo 'nope'; return; }
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$types = ['html'=>'text/html','css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml','json'=>'application/json','md'=>'text/plain'];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header("Content-Security-Policy: $csp");
readfile($file);
