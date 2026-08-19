<?php
/**
 * api/auth.php?action=signup|login|logout|me
 *
 * The login identifier is the Nigerian phone number. No email anywhere
 * in this flow.
 */

declare(strict_types=1);
require __DIR__ . '/../../private/bootstrap.php';

$action = (string) input('action', 'me');

switch ($action) {

    // -----------------------------------------------------------------
    case 'signup':
        require_method('POST');
        csrf_check();
        rate_limit('signup:' . client_ip(), 5, 600);

        $phone = normalize_phone((string) want('phone'));
        if (!$phone) {
            fail('That does not look like a Nigerian phone number', 422);
        }

        $password = (string) want('password');
        if (strlen($password) < 6) {
            fail('Password must be at least 6 characters', 422);
        }

        $name = trim((string) input('full_name', ''));
        $type = input('type') === 'visitor' ? 'visitor' : 'compound';
        $flat = $type === 'compound' ? trim((string) input('flat_no', '')) : null;

        if ($type === 'compound' && $flat === '') {
            fail('Flat or house number is required for compound accounts', 422);
        }

        if (scalar('SELECT 1 FROM customers WHERE phone = ?', [$phone])) {
            fail('That number already has an account. Log in instead.', 409);
        }

        // Password recovery has no email and no SMS to lean on, so the
        // question is the only route back into an account. Required at
        // signup: made optional, nobody sets it, and every forgotten
        // password becomes a phone call to the operator.
        [$question, $answerHash] = security_answer_from_input();

        q('INSERT INTO customers
             (phone, full_name, password_hash, security_question, security_answer_hash,
              type, flat_no, router_username, router_password)
           VALUES (?,?,?,?,?,?,?,?,?)',
            [$phone, $name ?: null, hash_password($password), $question, $answerHash,
             $type, $flat ?: null, $phone, random_code(6)]);

        $_SESSION['customer_id'] = last_id();
        session_regenerate_id(true);

        ok(['customer' => customer_public(current_customer())]);

    // -----------------------------------------------------------------
    case 'login':
        require_method('POST');
        csrf_check();

        $phone = normalize_phone((string) want('phone'));
        if (!$phone) {
            fail('Check the phone number', 422);
        }

        // Two buckets: one slows down a single targeted account, the
        // other slows down someone spraying many numbers from one IP.
        rate_limit('login:' . $phone, 6, 900);
        rate_limit('loginip:' . client_ip(), 25, 900);

        $c = one('SELECT * FROM customers WHERE phone = ?', [$phone]);

        // Same message either way — never confirm which numbers exist.
        if (!$c || !check_password((string) want('password'), $c['password_hash'])) {
            fail('Wrong phone number or password', 401);
        }
        if ($c['status'] === 'suspended') {
            fail('This account is suspended. Contact support.', 403);
        }

        session_regenerate_id(true);
        $_SESSION['customer_id'] = (int) $c['id'];

        ok(['customer' => customer_public($c)]);

    // -----------------------------------------------------------------
    case 'logout':
        require_method('POST');
        csrf_check();
        unset($_SESSION['customer_id']);
        session_regenerate_id(true);
        // Hand back the token for the new session. A page that logs out
        // and then tries to log back in without reloading would otherwise
        // be holding a token the server no longer accepts.
        ok(['csrf' => csrf_token()]);

    // -----------------------------------------------------------------
    case 'me':
        $c = current_customer();
        ok([
            'authenticated' => (bool) $c,
            'customer'      => $c ? customer_public($c) : null,
            'csrf'          => csrf_token(),
        ]);

    // -----------------------------------------------------------------
    // Step one of recovery: which question does this account carry?
    //
    // This does confirm that a number has an account, which login
    // deliberately does not. That is unavoidable — you cannot ask
    // someone a question without telling them there is one — so it is
    // rate limited hard instead, and the question text is chosen from a
    // fixed list that reveals nothing personal.
    case 'reset_question':
        require_method('POST');
        csrf_check();
        rate_limit('resetq:' . client_ip(), 10, 900);

        $phone = normalize_phone((string) want('phone'));
        if (!$phone) {
            fail('Check the phone number', 422);
        }

        $c = one('SELECT security_question FROM customers WHERE phone = ?', [$phone]);
        if (!$c || $c['security_question'] === null) {
            // Same message for "no account" and "account without a
            // question". Both are dead ends for the customer, and
            // separating them would leak which numbers are registered.
            fail('No security question is set for that number. Ask the admin to reset it for you.', 404);
        }

        ok(['question' => $c['security_question']]);

    // -----------------------------------------------------------------
    // Step two: answer it and set a new password.
    case 'reset_password':
        require_method('POST');
        csrf_check();

        $phone = normalize_phone((string) want('phone'));
        if (!$phone) {
            fail('Check the phone number', 422);
        }

        // Tighter than login. A security answer is guessable in a way a
        // password is not — a determined neighbour knows the street you
        // grew up on — so the budget is small and the window is long.
        rate_limit('reseta:' . $phone, 4, 1800);
        rate_limit('resetaip:' . client_ip(), 12, 1800);

        $password = (string) want('password');
        if (strlen($password) < 6) {
            fail('New password must be at least 6 characters', 422);
        }

        $c = one('SELECT * FROM customers WHERE phone = ?', [$phone]);
        if (!$c || $c['security_answer_hash'] === null
            || !check_password(normalize_answer((string) want('answer')), $c['security_answer_hash'])) {
            fail('That answer does not match', 401);
        }
        if ($c['status'] === 'suspended') {
            fail('This account is suspended. Contact support.', 403);
        }

        q('UPDATE customers SET password_hash = ? WHERE id = ?',
            [hash_password($password), (int) $c['id']]);

        // Log them straight in. They have just proved who they are, and
        // making them retype the password they set ten seconds ago on a
        // phone keyboard achieves nothing.
        session_regenerate_id(true);
        $_SESSION['customer_id'] = (int) $c['id'];

        ok(['customer' => customer_public(one('SELECT * FROM customers WHERE id = ?', [(int) $c['id']]))]);

    // -----------------------------------------------------------------
    // Change password while logged in. Requires the current one, so a
    // borrowed unlocked phone cannot lock the owner out of their account.
    case 'change_password':
        require_method('POST');
        csrf_check();
        $c = require_customer();
        rate_limit('chpw:' . $c['id'], 10, 900);

        if (!check_password((string) want('current_password'), $c['password_hash'])) {
            fail('Current password is wrong', 401);
        }

        $password = (string) want('password');
        if (strlen($password) < 6) {
            fail('New password must be at least 6 characters', 422);
        }

        q('UPDATE customers SET password_hash = ? WHERE id = ?',
            [hash_password($password), (int) $c['id']]);

        session_regenerate_id(true);
        ok(['changed' => true]);

    // -----------------------------------------------------------------
    // Set or replace the security question. This is how accounts created
    // before recovery existed get covered, and how anyone who suspects
    // their answer is known changes it.
    case 'set_security':
        require_method('POST');
        csrf_check();
        $c = require_customer();
        rate_limit('setsec:' . $c['id'], 10, 900);

        // Replacing an existing answer needs the password. Otherwise an
        // unlocked phone left on a table is a permanent account takeover:
        // set your own question, walk away, reset it whenever you like.
        if ($c['security_answer_hash'] !== null
            && !check_password((string) want('current_password'), $c['password_hash'])) {
            fail('Enter your password to change the security question', 401);
        }

        [$question, $answerHash] = security_answer_from_input();

        q('UPDATE customers SET security_question = ?, security_answer_hash = ? WHERE id = ?',
            [$question, $answerHash, (int) $c['id']]);

        ok(['question' => $question]);

    // -----------------------------------------------------------------
    // The questions the signup and dashboard forms offer.
    case 'security_questions':
        ok(['questions' => security_questions()]);

    // -----------------------------------------------------------------
    default:
        fail('Unknown action', 404);
}

/**
 * A fixed list, not free text.
 *
 * Free text lets people write "password" as the question, or something
 * that identifies them to anyone reading the database. A short list also
 * means the answers stay short and typeable on a phone.
 */
function security_questions(): array
{
    return [
        'What is your mother\'s maiden name?',
        'What was the name of your first school?',
        'What is the name of your home town?',
        'What was your childhood nickname?',
        'What is the name of your favourite teacher?',
    ];
}

/**
 * Answers are compared after normalising, because nobody retypes
 * "Ibadan" the same way twice: case, spacing and a stray full stop all
 * vary. Hash the normalised form so the comparison is exact but the
 * typing does not have to be.
 */
function normalize_answer(string $raw): string
{
    $s = strtolower(trim($raw));
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim((string) $s);
}

/**
 * Pull question and answer off the request and turn them into what the
 * database stores. Shared by signup and set_security so the two can
 * never validate differently.
 *
 * @return array{0:string,1:string} question text, hashed answer
 */
function security_answer_from_input(): array
{
    $questions = security_questions();
    $question  = (string) want('security_question');

    if (!in_array($question, $questions, true)) {
        fail('Choose one of the security questions', 422);
    }

    $answer = normalize_answer((string) want('security_answer'));
    if (strlen($answer) < 2) {
        fail('Your answer is too short', 422);
    }

    return [$question, hash_password($answer)];
}

/** Everything the browser is allowed to know about the logged-in person. */
function customer_public(array $c): array
{
    return [
        'id'        => (int) $c['id'],
        'phone'     => $c['phone'],
        'full_name' => $c['full_name'],
        'type'      => $c['type'],
        'flat_no'   => $c['flat_no'],
        'wallet'    => (float) $c['wallet_naira'],
        'status'    => $c['status'],
        // Shown on the dashboard so they know what to type at the hotspot.
        'wifi_username' => $c['router_username'],
        'wifi_password' => $c['router_password'],
        // Whether recovery is possible for this account. The question
        // text itself is deliberately not sent — a shoulder-surfer on a
        // logged-in dashboard should not get half the answer for free.
        'has_security_question' => ($c['security_answer_hash'] ?? null) !== null,
    ];
}
