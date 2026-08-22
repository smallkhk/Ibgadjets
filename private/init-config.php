<?php
/**
 * Write private/config.php, generating the sync key in place.
 *
 *   php private/init-config.php --db-name=u123_ibg --db-user=u123_ibg \
 *       --db-pass='the password' --site-url=https://ibphone.eclipselivecam.online
 *
 * Later, to read the key back out for the router:
 *
 *   php private/init-config.php --show-key
 *
 * WHY THIS EXISTS
 *
 * The sync key is a shared secret between exactly two files:
 * private/config.php here, and router-sync.rsc on the Mikrotik. It is
 * generated, not chosen, and every run of a generator produces a
 * different value — so there is no "right" one to match, only the one
 * that ends up in both places.
 *
 * Copying it by hand invites two mistakes that look identical from the
 * outside: editing config.example.php instead of config.php (the site
 * then has no configuration at all), and pasting a key that no longer
 * matches whatever was generated last (the router gets 403 forever).
 * This writes the file and the key together so neither can happen.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$dir    = __DIR__;
$target = $dir . '/config.php';

// ---------------------------------------------------------------- args
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $a, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

// ------------------------------------------------------------ show-key
if (isset($args['show-key'])) {
    if (!is_file($target)) {
        fwrite(STDERR, "No config.php yet. Run this without --show-key first.\n");
        exit(1);
    }
    $cfg = require $target;
    $key = (string) ($cfg['sync_key'] ?? '');
    if ($key === '' || str_starts_with($key, 'CHANGE-ME')) {
        fwrite(STDERR, "config.php has no real sync key in it.\n");
        exit(1);
    }
    echo $key, PHP_EOL;
    echo PHP_EOL;
    echo "Paste that exact string into router/router-sync.rsc:", PHP_EOL;
    echo "  :local ibgKey \"{$key}\"", PHP_EOL;
    exit(0);
}

// -------------------------------------------------------------- create
if (is_file($target) && !isset($args['force'])) {
    fwrite(STDERR, "config.php already exists. Nothing changed.\n");
    fwrite(STDERR, "  php private/init-config.php --show-key   to read the sync key\n");
    fwrite(STDERR, "  php private/init-config.php --force ...  to rewrite it\n");
    exit(1);
}

// Keep the existing key on --force. Changing it silently would leave the
// router holding a secret the server no longer accepts, and the only
// symptom is provisioning quietly stopping.
$syncKey = null;
if (is_file($target)) {
    $old = require $target;
    $existing = (string) ($old['sync_key'] ?? '');
    if ($existing !== '' && !str_starts_with($existing, 'CHANGE-ME')) {
        $syncKey = $existing;
    }
}
$reused  = $syncKey !== null;
$syncKey = $syncKey ?? bin2hex(random_bytes(32));

$cfg = [
    'db_host'  => (string) ($args['db-host'] ?? 'localhost'),
    'db_name'  => (string) ($args['db-name'] ?? ''),
    'db_user'  => (string) ($args['db-user'] ?? ''),
    'db_pass'  => (string) ($args['db-pass'] ?? ''),
    'site_url' => rtrim((string) ($args['site-url'] ?? 'https://ibphone.eclipselivecam.online'), '/'),
];

foreach (['db_name', 'db_user'] as $required) {
    if ($cfg[$required] === '') {
        fwrite(STDERR, "Missing --" . str_replace('_', '-', $required) . "\n\n");
        fwrite(STDERR, "Example:\n  php private/init-config.php \\\n");
        fwrite(STDERR, "    --db-name=u123_ibg --db-user=u123_ibg --db-pass='secret' \\\n");
        fwrite(STDERR, "    --site-url=https://ibphone.eclipselivecam.online\n");
        exit(1);
    }
}

$v = static fn($s): string => var_export($s, true);

$php = <<<PHP
<?php
/**
 * IB Gadgets Telecom — configuration.
 *
 * Written by private/init-config.php. Not in git, and it must never sit
 * inside the document root.
 *
 * sync_key is shared with the router and nothing else. To read it back:
 *   php private/init-config.php --show-key
 */

return [

    'db' => [
        'host'    => {$v($cfg['db_host'])},
        'name'    => {$v($cfg['db_name'])},
        'user'    => {$v($cfg['db_user'])},
        'pass'    => {$v($cfg['db_pass'])},
        'charset' => 'utf8mb4',
    ],

    'site_url' => {$v($cfg['site_url'])},
    'timezone' => 'Africa/Lagos',
    'debug'    => false,

    // The same string must appear in router/router-sync.rsc.
    'sync_key' => {$v($syncKey)},

    // Uploaded receipts. Outside the document root on purpose.
    'upload_dir' => __DIR__ . '/uploads',

    'session_name'   => 'ibg_sess',
    'session_secure' => true,
];

PHP;

if (file_put_contents($target, $php) === false) {
    fwrite(STDERR, "Could not write {$target}\n");
    exit(1);
}
@chmod($target, 0600);

if (!is_dir($dir . '/uploads')) {
    @mkdir($dir . '/uploads', 0750, true);
}

echo "Wrote {$target}\n\n";
echo "  database   {$cfg['db_user']}@{$cfg['db_host']} / {$cfg['db_name']}\n";
echo "  site_url   {$cfg['site_url']}\n";
echo '  sync key   ', $reused ? "kept the existing one\n" : "generated\n";
echo "\n";
echo "SYNC KEY — this exact string also goes in router/router-sync.rsc:\n\n";
echo "  {$syncKey}\n\n";
echo "You do not need to memorise it. Read it back any time with:\n";
echo "  php private/init-config.php --show-key\n";
