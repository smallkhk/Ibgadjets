<?php
/**
 * api/proof.php?id=<transaction id>
 *
 * The only route back out of the upload directory. Admin session
 * required, path taken from the database rather than the query string,
 * and a final realpath() check so a crafted stored value cannot walk out
 * of the uploads folder.
 */

declare(strict_types=1);
require __DIR__ . '/../../private/bootstrap.php';

require_method('GET');
require_admin();

$id  = (int) want('id');
$rel = scalar('SELECT proof_path FROM transactions WHERE id = ?', [$id]);

if (!$rel) {
    fail('No receipt on that transaction', 404);
}

$base = realpath(rtrim((string) $CONFIG['upload_dir'], '/'));
$path = realpath($base . '/' . $rel);

if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
    fail('Not found', 404);
}

$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="receipt-' . $id . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
