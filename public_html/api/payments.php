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

        // post_max_size is checked BEFORE anything else, because when a
        // request exceeds it PHP silently discards the entire body —
        // $_POST and $_FILES both come through empty. The customer then
        // gets "Missing field: reference" for having attached a photo
        // that was slightly too big, which is impossible to act on.
        //
        // The tell is a request that announced a body and arrived with
        // nothing in it.
        if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            fail('That image is too large for this server to accept. Send a screenshot rather than a photo of the screen.', 413);
        }

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
            fail(upload_error_message($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE), 422);
        }

        $stored = store_proof($_FILES['proof'], $GLOBALS['CONFIG']['upload_dir']);

        q("UPDATE transactions SET proof_path = ?, status = 'pending' WHERE id = ?",
            [$stored, $tx['id']]);

        ok(['message' => 'Receipt received. We confirm it and your plan goes live — usually within a few minutes.']);

    // -----------------------------------------------------------------
    // "I have sent the money, but I cannot attach a receipt."
    //
    // The order is already pending the moment it is created, so this
    // changes nothing about whether the admin sees it — it flags WHICH
    // pending rows the customer has actually acted on. Without it every
    // abandoned checkout looks identical to a real payment waiting for
    // approval.
    //
    // It exists because the receipt was never truly required, but the
    // checkout screen looked like it was: customers inside the hotspot's
    // captive-portal browser have no working file picker, hit what looked
    // like a wall, and stopped.
    case 'sent':
        require_method('POST');
        csrf_check();
        $customer = require_customer();
        rate_limit('sent:' . $customer['id'], 20, 600);

        $tx = one("SELECT * FROM transactions WHERE reference = ? AND customer_id = ?",
            [(string) want('reference'), $customer['id']]);

        if (!$tx) {
            fail('Unknown reference', 404);
        }
        if ($tx['status'] === 'success') {
            fail('That payment is already confirmed', 409);
        }

        q("UPDATE transactions SET note = ?, status = 'pending' WHERE id = ?",
            ['Customer confirmed sending — no receipt attached', $tx['id']]);

        ok(['message' => 'Noted. We will check the account and turn your plan on — usually within a few minutes.']);

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
    // OPay callback — money landed in a generated account.
    //
    // Nothing here trusts the caller. The signature has to verify against
    // our secret, the reference has to be one of ours, and the amount has
    // to match to the kobo before a single byte of internet is handed out.
    // -----------------------------------------------------------------
    case 'opay_callback':
        require_method('POST');
        opay_handle_callback();

    default:
        fail('Unknown action', 404);
}


function opay_handle_callback(): never
{
    $raw = file_get_contents('php://input') ?: '';
    $in  = json_decode($raw, true);

    q('INSERT INTO sync_log (action, payload, result) VALUES (?,?,?)',
        ['opay_callback', substr($raw, 0, 1500), 'received']);

    if (!is_array($in)) {
        json_out(['ok' => false, 'error' => 'Bad payload'], 400);
    }
    if (!opay_enabled()) {
        json_out(['ok' => false, 'error' => 'OPay not enabled'], 503);
    }

    // OPay wraps the transaction in "payload" and puts the signature
    // beside it in a field called "sha512" — which is HMAC-SHA3-512, not
    // SHA-512. See private/lib/opay.php.
    $payload   = is_array($in['payload'] ?? null) ? $in['payload'] : $in;
    $signature = (string) ($in['sha512'] ?? $in['sha512Value'] ?? '');

    if (!opay_verify_callback($payload, $signature)) {
        q('INSERT INTO sync_log (action, payload, result) VALUES (?,?,?)',
            ['opay_callback', substr($raw, 0, 500), 'SIGNATURE MISMATCH — rejected']);
        json_out(['ok' => false, 'error' => 'Bad signature'], 401);
    }

    $reference = opay_field($payload, 'Reference');
    $status    = strtoupper(opay_field($payload, 'Status'));
    $koboRaw   = opay_field($payload, 'Amount');

    if ($reference === '') {
        json_out(['ok' => false, 'error' => 'No reference'], 400);
    }

    $tx = one('SELECT * FROM transactions WHERE reference = ?', [$reference]);
    if (!$tx) {
        opay_log('callback', $reference, 'unknown reference');
        json_out(['ok' => false, 'error' => 'Unknown reference'], 404);
    }

    // Already done. OPay retries, so saying yes again must be harmless.
    if ($tx['status'] === 'success') {
        json_out(['ok' => true, 'message' => 'Already processed']);
    }

    if ($status !== 'SUCCESS') {
        if (in_array($status, ['FAIL', 'CLOSE'], true)) {
            q("UPDATE transactions SET status = 'failed', note = ? WHERE id = ?",
                ['OPay reported ' . $status, $tx['id']]);
        }
        json_out(['ok' => true, 'message' => 'Recorded ' . $status]);
    }

    // Amount arrives in kobo. Compare in kobo so nothing rounds away.
    $expectedKobo = (int) round(((float) $tx['amount_naira']) * 100);
    $paidKobo     = (int) round((float) $koboRaw);

    if ($paidKobo < $expectedKobo) {
        q("UPDATE transactions SET note = ? WHERE id = ?",
            [sprintf('Underpaid: got %d kobo, expected %d', $paidKobo, $expectedKobo), $tx['id']]);
        opay_log('callback', $reference, 'underpaid, left for an admin');
        json_out(['ok' => true, 'message' => 'Amount mismatch, held for review']);
    }

    $planId = (int) $tx['plan_id'];
    if (!$planId) {
        opay_log('callback', $reference, 'no plan on transaction');
        json_out(['ok' => true, 'message' => 'No plan, held for review']);
    }

    tx_begin();
    try {
        q("UPDATE transactions
              SET status = 'success', approved_at = NOW(), note = 'auto-confirmed by OPay'
            WHERE id = ?", [$tx['id']]);
        $subId = activate_subscription((int) $tx['customer_id'], $planId, (int) $tx['id']);
        tx_commit();
    } catch (Throwable $ex) {
        tx_rollback();
        opay_log('callback', $reference, 'activation failed: ' . $ex->getMessage());
        json_out(['ok' => false, 'error' => 'Activation failed'], 500);
    }

    opay_log('callback', $reference, "activated sub {$subId}");
    json_out(['ok' => true, 'message' => 'Activated']);
}


/**
 * Say what actually went wrong with an upload.
 *
 * Every one of these used to surface as "Attach the receipt screenshot",
 * which is wrong and unhelpful in most cases: the customer DID attach
 * one, and the server threw it away for a reason only the server knew.
 * They then retry the same file forever and eventually give up — and a
 * customer who cannot deliver a receipt is a customer who cannot pay.
 *
 * The size and tmp-dir cases in particular are server configuration, not
 * anything the customer can fix, so the message says so rather than
 * blaming them.
 */
function upload_error_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            // php.ini upload_max_filesize. Phone photos are routinely
            // 3-8MB and shared hosting often ships a 2MB cap.
            return 'That image is bigger than this server accepts. Send a screenshot rather than a photo, or ask us to raise the limit.';
        case UPLOAD_ERR_PARTIAL:
            return 'The upload was cut off. Try again — a stronger signal helps.';
        case UPLOAD_ERR_NO_FILE:
            return 'Attach the receipt screenshot';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return 'The server could not save that file. This is our fault, not yours — please tell us.';
        case UPLOAD_ERR_EXTENSION:
            return 'The server refused that upload. Please tell us so we can fix it.';
        default:
            return 'That upload did not go through. Please try again.';
    }
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
        // HEIC is what an iPhone camera produces by default, so anyone
        // photographing a receipt on another screen hits this. Naming it
        // is the difference between a customer who takes a screenshot
        // instead and one who retries the same file until they give up.
        if ($mime === 'image/heic' || $mime === 'image/heif') {
            fail('iPhone photos (HEIC) are not supported. Take a screenshot of the transfer instead, or set Camera > Formats to "Most Compatible".', 415);
        }
        if (str_starts_with($mime, 'video/')) {
            fail('That is a video. Send a screenshot of the transfer.', 415);
        }
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
