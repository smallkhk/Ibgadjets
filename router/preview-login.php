<?php
/**
 * Render the captive portal page the way the router would.
 *
 *   php router/preview-login.php
 *   -> router/preview/login-normal.html   (what a customer normally sees)
 *   -> router/preview/login-error.html    (after a wrong password)
 *
 * WHY THIS EXISTS
 *
 * hotspot/login.html is a RouterOS template, not a finished web page. It
 * contains variables like $(if error), $(error), $(link-login-only) and
 * $(chap-challenge) which the Mikrotik substitutes at the moment it
 * serves the page. Open the raw file in a browser and those appear as
 * literal text — that is correct, not a fault. It only looks right when
 * the router serves it.
 *
 * This script does the same substitution so you can eyeball the design
 * without a router in front of you. The output is for looking at ONLY —
 * never upload these files to the Mikrotik, upload hotspot/login.html.
 */

declare(strict_types=1);

$src = __DIR__ . '/hotspot/login.html';
$dir = __DIR__ . '/preview';

if (!is_file($src)) {
    fwrite(STDERR, "Cannot find {$src}\n");
    exit(1);
}
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    fwrite(STDERR, "Cannot create {$dir}\n");
    exit(1);
}

$template = file_get_contents($src);

/**
 * @param string|null $error  null renders the normal page; a string
 *                            renders the failure state the router shows
 *                            after a rejected login.
 */
function render(string $template, ?string $error): string
{
    // $(if error) … $(endif) — the router keeps the block only when there
    // is an error to show, and drops it entirely otherwise.
    $out = preg_replace_callback(
        '/\$\(if error\)(.*?)\$\(endif\)/s',
        static fn(array $m): string => $error === null ? '' : $m[1],
        $template
    ) ?? $template;

    $out = strtr($out, [
        '$(error)'          => $error ?? '',
        '$(link-login-only)' => '#',
        '$(link-orig)'      => 'http://example.com',
        '$(chap-id)'        => '',
        '$(chap-challenge)' => '',
        '$(hostname)'       => 'wifi.ibgadgets.ng',
        '$(mac)'            => 'AA:BB:CC:DD:EE:01',
        '$(ip)'             => '10.5.50.14',
    ]);

    // md5.js lives on the router, not here. Without this the preview
    // throws a 404 in the console and nothing else.
    $out = preg_replace('#<script[^>]*src="/md5\.js"[^>]*></script>#', '', $out) ?? $out;

    // A visible reminder, so a preview file never gets mistaken for the
    // real template and uploaded to the router.
    $banner = '<div style="position:fixed;left:0;right:0;top:0;z-index:9999;'
        . 'background:#FFB03D;color:#0A1430;font:600 12px/1.4 system-ui,sans-serif;'
        . 'padding:7px 12px;text-align:center">'
        . 'PREVIEW ONLY — router variables already substituted. Upload '
        . '<strong>hotspot/login.html</strong> to the Mikrotik, not this file.'
        . '</div><div style="height:30px"></div>';

    return preg_replace('/<body([^>]*)>/i', '<body$1>' . $banner, $out, 1) ?? $out;
}

file_put_contents($dir . '/login-normal.html', render($template, null));
file_put_contents($dir . '/login-error.html',  render($template, 'invalid username or password'));

echo "Wrote:\n";
echo "  router/preview/login-normal.html   normal state\n";
echo "  router/preview/login-error.html    after a wrong password\n";
echo "\nOpen either in a browser. Upload hotspot/login.html to the router.\n";
