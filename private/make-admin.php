<?php
/**
 * One-off: create the first admin account.
 *
 *   php private/make-admin.php you@example.com 'a-good-password' owner
 *
 * Run it from SSH or cPanel's Terminal, then delete it if you like —
 * further admins can be added from the panel.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/bootstrap.php';

$email = strtolower(trim($argv[1] ?? ''));
$pass  = $argv[2] ?? '';
$role  = ($argv[3] ?? 'owner') === 'staff' ? 'staff' : 'owner';

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
    fwrite(STDERR, "Usage: php make-admin.php <email> <password (8+ chars)> [owner|staff]\n");
    exit(1);
}

q('INSERT INTO admins (email, password_hash, role) VALUES (?,?,?)
   ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role)',
    [$email, hash_password($pass), $role]);

echo "Admin ready: {$email} ({$role})\n";
echo "Log in at /admin.html\n";
