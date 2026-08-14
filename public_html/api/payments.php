<?php
/**
 * api/payments.php?action=proof|usdt|status|opay_callback
 *
 * Proof of payment goes in here; approval happens in admin.php. The
 * customer can never move their own transaction to success.
 */

declare(strict_types=1);
require __DIR__ . '/../../private/bootstrap.php';

$action = (string) input('action', 'status');

switch ($action) {

    // -----------------------------------------------------------------
    // Bank transfer receipt upload.
    // -----------------------------------------------------------------
    case 'proof':
        require_method('POST');
        csrf_check();
        $customer = require_customer();
        rate_limit('proof:' . $customer['id'], 10, 600);

        $reference = (string) want('reference');
        $tx = one("SELECT * FROM transactions WHERE reference = ? AND customer_id = ?",
            [$reference, $customer['id']]);

        if (!$tx) {
            fail('Unknown reference', 404);
        }
        if ($tx['status'] === 'success') {
            fail('That payment is already confirmed', 409);
        }
        if (!isset($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
            fail('Attach the receipt screenshot', 422);
        }

        $stored = store_proof($_FILES['proof'], $GLOBALS['CONFIG']['upload_dir']);

        q("UPDATE transactions SET proof_path = ?, status = 'pending' WHERE id = ?",
            [$stored, $tx['id']]);

        ok(['message' => 'Receipt received. We confirm it and your plan goes live — usually within a few minutes.']);

    // -----------------------------------------------------------------
    // USDT — customer pastes the hash, an admin (or the verifier cron)
    // confirms it on-chain before anything activates.
    // -----------------------------------------------------------------
    case 'usdt':
        require_method('POST');
        csrf_check();
        $customer = require_customer();
        rate_limit('usdt:' . $customer['id'], 10, 600);

        $reference = (string) want('reference');
        $hash      = trim((string) want('tx_hash'));

        if (!preg_match('/^(0x)?[0-9a-fA-F]{64}$/', $hash)) {
            fail('That does not look like a transaction hash', 422);
        }

        $tx = one('SELECT * FROM transactions WHERE reference = ? AND customer_id = ?',
            [$reference, $customer['id']]);
        if (!$tx) {
            fail('Unknown reference', 404);
        }

        // uq_tx_hash makes this a race-free claim: the same hash cannot
        // be credited to two people, however fast they paste it.
        try {
            q('UPDATE transactions SET tx_hash = ? WHERE id = ?', [$hash, $tx['id']]);
        } catch (PDOException $ex) {
            fail('That transaction hash has already been submitted', 409);
        }

        ok(['message' => 'Hash received. We verify on-chain and activate once it has confirmations.']);

    // -----------------------------------------------------------------
    case 'status':
        require_method('GET');
        $customer = require_customer();
        $reference = (string) want('reference');

        $tx = one('SELECT reference, amount_naira, method, status, created_at, approved_at
                     FROM transactions WHERE reference = ? AND customer_id = ?',
            [$reference, $customer['id']]);
        if (!$tx) {
            fail('Unknown reference', 404);
        }
        ok(['transaction' => $tx]);

    // -----------------------------------------------------------------
    // OPay callback. Stubbed until the merchant account is approved —
    // the signature check goes here and nothing activates without it.
    // -----------------------------------------------------------------
    case 'opay_callback':
        require_method('POST');
        q('INSERT INTO sync_log (action, payload, result) VALUES (?,?,?)',
            ['opay_callback', substr(file_get_contents('php://input') ?: '', 0, 2000), 'received, not yet enabled']);

        if (setting_int('opay_enabled', 0) !== 1) {
            json_out(['ok' => false, 'error' => 'OPay not enabled'], 503);
        }
        // TODO on merchant approval:
        //   1. verify the HMAC signature against the OPay secret
        //   2. match reference -> transactions.reference
        //   3. confirm amount matches to the kobo
        //   4. activate_subscription()
        json_out(['ok' => false, 'error' => 'Not implemented'], 501);

    default:
        fail('Unknown action', 404);
}


/**
 * Write an uploaded receipt to disk, outside the document root.
 *
 * Receipt upload is the single most dangerous feature on this site, so:
 * type is decided by magic bytes and never by the filename, the stored
 * name is random, the extension is one we chose, and the directory is
 * not web reachable at all. api/proof.php is the only way back out and
 * it demands an admin session.
 */
function store_proof(array $file, string $dir): string
{
    if ($file['size'] > 5 * 1024 * 1024) {
        fail('Receipt must be under 5 MB', 413);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($file['tmp_name']);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        fail('Upload a screenshot of the receipt (JPG, PNG or WebP)', 415);
    }
    // Second opinion: a real raster image, not something wearing the header.
    if (@getimagesize($file['tmp_name']) === false) {
        fail('That image could not be read', 415);
    }

    $monthDir = rtrim($dir, '/') . '/' . date('Y-m');
    if (!is_dir($monthDir) && !mkdir($monthDir, 0750, true) && !is_dir($monthDir)) {
        fail('Upload directory is not writable', 500);
    }

    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $dest = $monthDir . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        fail('Could not save that file', 500);
    }
    @chmod($dest, 0640);

    return date('Y-m') . '/' . $name;
}
