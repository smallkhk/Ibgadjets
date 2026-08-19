<?php
/**
 * Render the captive portal page the way the router would.
 *
 *   php router/preview-login.php
 *   -> router/preview/login-normal.html   what a customer normally sees
 *   -> router/preview/login-error.html    after a wrong password
 *   -> router/preview/login-nochap.html   with CHAP off, to check the fallback
 *
 * WHY THIS EXISTS
 *
 * hotspot/login.html is a RouterOS template, not a finished web page. It
 * holds conditionals and variables the Mikrotik resolves at the moment it
 * serves the page:
 *
 *   $(if chap-id) … $(endif)   kept only when the hotspot offers CHAP
 *   $(if error)   … $(endif)   kept only when a login was just rejected
 *   $(username)                what they typed on the failed attempt
 *   $(link-login-only)         where the form posts
 *
 * Open the raw file in a browser and those print as literal text. That is
 * correct, not a fault — a browser is not a Mikrotik. This script does the
 * same substitution so the design can be checked without one.
 *
 * The output is for looking at ONLY. Never upload it: its conditionals are
 * already resolved, so the error box could never appear and the form would
 * post nowhere.
 */

declare(strict_types=1);

$src = __DIR__ . '/hotspot/login.html';
$dir = __DIR__ . '/preview';

if (!is_file($src)) {
    fwrite(STDERR, "Cannot find {$src}\n");
    exit(1);
}
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create {$dir}\n");
    exit(1);
}

$template = file_get_contents($src);

/**
 * Resolve $(if …) … $(endif) blocks innermost-first, looping until the
 * page stops changing. RouterOS conditionals nest, so a single pass over
 * the whole file would mismatch an outer $(if with an inner $(endif).
 *
 * @param array<string,bool> $truth
 */
function resolve_conditionals(string $html, array $truth): string
{
    // Matches an $(if …) block whose body contains no further $(if —
    // i.e. always the innermost one.
    $pattern = '/\$\(if\s+([^)]+?)\)((?:(?!\$\(if\s)[\s\S])*?)\$\(endif\)/';

    for ($pass = 0; $pass < 20; $pass++) {
        $next = preg_replace_callback(
            $pattern,
            static function (array $m) use ($truth): string {
                $cond = trim($m[1]);
                $body = $m[2];

                // Support the "not set" form the stock template uses:
                //   $(if error == "")  ->  true when there is no error
                if (preg_match('/^(\S+)\s*==\s*[\'"]{2}$/', $cond, $eq)) {
                    return empty($truth[$eq[1]] ?? false) ? $body : '';
                }
                // and equality against a literal, e.g. trial == 'yes'
                if (preg_match('/^(\S+)\s*==\s*[\'"](.+)[\'"]$/', $cond, $eq)) {
                    return (($truth[$eq[1]] ?? null) === $eq[2]) ? $body : '';
                }
                return !empty($truth[$cond] ?? false) ? $body : '';
            },
            $html
        );

        if ($next === null || $next === $html) {
            return $next ?? $html;
        }
        $html = $next;
    }
    return $html;
}

/**
 * @param string|null $error null renders the normal page; a string renders
 *                           the failure state shown after a rejected login.
 * @param bool $chap         whether the hotspot profile offers http-chap.
 */
function render(string $template, ?string $error, bool $chap = true): string
{
    $html = resolve_conditionals($template, [
        'error'   => $error !== null,
        'chap-id' => $chap,
        'trial'   => 'no',
    ]);

    $html = strtr($html, [
        '$(error)'           => $error ?? '',
        '$(username)'        => $error === null ? '' : '08031112222',
        '$(link-login-only)' => '#',
        '$(link-orig)'       => 'http://example.com',
        '$(link-orig-esc)'   => 'http%3A%2F%2Fexample.com',
        '$(chap-id)'         => "\x01",
        '$(chap-challenge)'  => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
        '$(hostname)'        => 'wifi.ibgadgets.ng',
        '$(mac)'             => 'AA:BB:CC:DD:EE:01',
        '$(mac-esc)'         => 'AA%3ABB%3ACC%3ADD%3AEE%3A01',
        '$(ip)'              => '10.5.50.14',
    ]);

    // md5.js lives on the router, not here. Drop the tag and stub the one
    // function it provides, so the preview does not throw on submit.
    $html = preg_replace('#<script[^>]*src="/md5\.js"[^>]*>\s*</script>#', '', $html) ?? $html;
    $html = str_replace('</head>',
        "<script>function hexMD5(){return 'preview-not-a-real-hash';}</script>\n</head>",
        $html);

    // Visible marker, so a preview can never be mistaken for the template.
    $banner = '<div style="position:fixed;left:0;right:0;top:0;z-index:9999;'
        . 'background:#FFB03D;color:#0A1430;font:600 12px/1.4 system-ui,sans-serif;'
        . 'padding:7px 12px;text-align:center">'
        . 'PREVIEW ONLY — router variables already resolved. Upload '
        . '<strong>hotspot/login.html</strong> to the Mikrotik, not this file.'
        . '</div><div style="height:30px"></div>';

    return preg_replace('/<body([^>]*)>/i', '<body$1>' . $banner, $html, 1) ?? $html;
}

$files = [
    'login-normal.html' => render($template, null),
    'login-error.html'  => render($template, 'invalid username or password'),
    'login-nochap.html' => render($template, null, false),
];

foreach ($files as $name => $html) {
    file_put_contents($dir . '/' . $name, $html);
}

// Anything left behind means a variable this script does not know about.
$leftovers = [];
foreach ($files as $name => $html) {
    if (preg_match_all('/\$\([^)]*\)/', $html, $m)) {
        $leftovers[$name] = array_unique($m[0]);
    }
}

echo "Wrote:\n";
echo "  router/preview/login-normal.html   normal state\n";
echo "  router/preview/login-error.html    after a wrong password\n";
echo "  router/preview/login-nochap.html   CHAP disabled, plain-post fallback\n";

if ($leftovers) {
    echo "\nUnresolved router variables — add them to this script:\n";
    foreach ($leftovers as $name => $vars) {
        echo "  {$name}: " . implode(' ', $vars) . "\n";
    }
    exit(1);
}

echo "\nAll router variables resolved.\n";
echo "Open any of them in a browser. Upload hotspot/login.html to the router.\n";
