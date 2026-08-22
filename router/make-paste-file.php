<?php
/**
 * Generate router/ibg-sync-source.txt from router-sync.rsc.
 *
 *   php router/make-paste-file.php
 *
 * The .rsc wraps the script in `/system script add ... source={ ... }`.
 * Only the inside of that block may be pasted into Winbox — ship the
 * wrapper by mistake and the script creates ANOTHER script every time it
 * runs, instead of syncing.
 *
 * That is not hypothetical: an earlier version of this extractor split
 * on the first occurrence of the text "source={", which matched a
 * COMMENT in the header that happened to mention it. The generated file
 * carried the wrapper, was pasted into a live router, and did nothing
 * useful. Hence: anchor on the real command line, cut at the last lone
 * closing brace, and verify the result before writing.
 */

declare(strict_types=1);

$src = __DIR__ . '/router-sync.rsc';
$out = __DIR__ . '/ibg-sync-source.txt';
$lines = explode("\n", file_get_contents($src));

$start = null;
foreach ($lines as $i => $l) {
    if (preg_match('/^\s*policy=.*\bsource=\{\s*$/', $l)) { $start = $i; break; }
}
if ($start === null) { fwrite(STDERR, "no source={ command line found\n"); exit(1); }

$end = null;
foreach ($lines as $i => $l) {
    if (trim($l) === '}') { $end = $i; }          // last lone brace wins
}
if ($end === null || $end <= $start) { fwrite(STDERR, "no closing brace found\n"); exit(1); }

$body = trim(implode("\n", array_slice($lines, $start + 1, $end - $start - 1)), "\n");

$header = <<<TXT
# =====================================================================
# IB Gadgets Telecom — ibg-sync
#
# HOW TO INSTALL
#   Winbox > System > Scripts > Add (+)
#     Name:   ibg-sync
#     Policy: tick read, write, policy, test, sensitive
#     Source: paste EVERYTHING below the line of equals signs
#     OK
#
# EDIT ONE LINE first: ibgKey, from the server:
#   php ~/private/init-config.php --show-key
# It is 64 hex characters. Shorter means it got clipped in the copy.
#
# BEFORE THE FIRST RUN, stop the scheduler so a fault cannot loop:
#   Winbox > System > Scheduler, untick ibg-sync
#   then Scripts > ibg-sync > Run Script, and check the Log
# Only after you see "Done sync." should you tick the scheduler back on.
# =====================================================================

TXT;

// Brace counting is useless — the script builds JSON containing literal
// braces inside strings. Check structure instead.
$bodyLines = explode("\n", $body);
foreach ($bodyLines as $i => $l) {
    $t = trim($l);
    foreach (['/system script', 'add name="ibg-sync"'] as $bad) {
        if (str_starts_with($t, $bad)) { fwrite(STDERR, "wrapper leaked at line {$i}: {$t}\n"); exit(1); }
    }
    if (str_contains($t, 'source={')) { fwrite(STDERR, "wrapper leaked at line {$i}\n"); exit(1); }
}
$nonblank = array_values(array_filter($bodyLines, fn($l) => trim($l) !== ''));
if (!str_starts_with(trim($nonblank[0]), ':local ibgUrl')) { fwrite(STDERR, "body does not start with ibgUrl\n"); exit(1); }
// The body must end by releasing the run lock. If the extractor ever cuts
// short of this line the script still looks fine, installs fine, and runs
// exactly once — then every later cycle sees a lock nobody will ever drop
// and skips itself forever, silently.
if (trim(end($nonblank)) !== ':set ibgBusy false') {
    fwrite(STDERR, "body does not end by releasing the run lock: " . trim(end($nonblank)) . "\n");
    exit(1);
}
$takes    = substr_count($body, ':set ibgBusy true');
$releases = substr_count($body, ':set ibgBusy false');
if ($takes !== 1 || $releases !== 1) {
    fwrite(STDERR, "run lock must be taken once and released once, got {$takes}/{$releases}\n");
    exit(1);
}

file_put_contents($out, $header . $body . "\n");
echo "Wrote router/ibg-sync-source.txt (", count($bodyLines), " lines of script)\n";
echo "  first: ", trim($nonblank[0]), "\n";
echo "  last:  ", trim(end($nonblank)), "\n";
