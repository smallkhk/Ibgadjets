<?php
/**
 * Single entry point for every PHP file in public_html.
 * Loads config, opens the database, starts the session, and pulls in
 * the helper libraries.
 */

declare(strict_types=1);

if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(500);
    exit('Configuration missing. Copy private/config.example.php to private/config.php.');
}

$CONFIG = require __DIR__ . '/config.php';

date_default_timezone_set($CONFIG['timezone'] ?? 'Africa/Lagos');

if (!empty($CONFIG['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/security.php';
require __DIR__ . '/lib/settings.php';
require __DIR__ . '/lib/billing.php';
require __DIR__ . '/lib/opay.php';

db_init($CONFIG['db']);

// The router polls once a minute and carries no cookie. Starting a
// session for it would litter the session store with 1,440 dead files a
// day, so endpoints that authenticate by secret opt out.
if (!defined('IBG_NO_SESSION')) {
    session_boot($CONFIG);
}
