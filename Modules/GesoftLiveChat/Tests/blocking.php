<?php
/**
 * The rules for blocking a visitor, checked without booting Laravel.
 *
 * Run it directly:  php Modules/GesoftLiveChat/Tests/blocking.php
 */

require __DIR__.'/../Support/Blocking.php';

use Modules\GesoftLiveChat\Support\Blocking;

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok    %-60s %s\n", $label, var_export($got, true)); }
    else { $fail++; printf("  FAIL  %-60s got: %s  wanted: %s\n", $label, var_export($got, true), var_export($want, true)); }
}

echo "GesoftLiveChat — blocking rules\n\n";

// An email block has to catch the same address however it is typed.
check('email is lowercased', Blocking::normalizeEmail('  Ana@Example.COM '), 'ana@example.com');
check('not an email is refused', Blocking::normalizeEmail('ana at example'), null);
check('empty is refused', Blocking::normalizeEmail(''), null);

// And an address block the same address however it is written.
check('IPv4 kept as is', Blocking::normalizeIp('82.79.150.197'), '82.79.150.197');
check('IPv6 written long becomes canonical', Blocking::normalizeIp('2001:0db8:0000:0000:0000:0000:0000:0001'), '2001:db8::1');
check('IPv6 written short stays canonical', Blocking::normalizeIp('2001:db8::1'), '2001:db8::1');
check('not an address is refused', Blocking::normalizeIp('82.79.150'), null);

// Durations: only the ones on offer.
$now = 1000000;
check('one day', Blocking::expiresAt(1, $now), $now + 86400);
check('thirty days', Blocking::expiresAt('30', $now), $now + 30 * 86400);
check('permanently means no end', Blocking::expiresAt(0, $now), null);
check('a duration not on offer is refused', Blocking::expiresAt(365, $now), false);

// Whether a block still applies.
check('a permanent block applies', Blocking::isActive(null, $now), true);
check('a block ending later applies', Blocking::isActive($now + 1, $now), true);
check('a block that ended does not', Blocking::isActive($now, $now), false);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
