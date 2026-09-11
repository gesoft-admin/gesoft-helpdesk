<?php
/**
 * The rules of delivery receipts, checked without booting Laravel.
 *
 * Run it directly:  php Modules/GesoftLiveChat/Tests/receipts.php
 *
 * A report of what was seen comes from a browser, the visitor's included, so
 * what it may move is tested as plain functions with plain numbers.
 */

require __DIR__.'/../Support/Receipts.php';

use Modules\GesoftLiveChat\Support\Receipts;

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok    %-62s %s\n", $label, var_export($got, true)); }
    else { $fail++; printf("  FAIL  %-62s got: %s  wanted: %s\n", $label, var_export($got, true), var_export($want, true)); }
}

echo "GesoftLiveChat — delivery receipts\n\n";

// A report moves a pointer forward, and nothing else does.
check('a report past the pointer moves it there', Receipts::advance(10, '12'), 12);
check('  from a number as well as from a query string', Receipts::advance(10, 12), 12);
check('the same message again moves nothing', Receipts::advance(12, '12'), null);
check('an older message moves nothing: nothing is un-seen', Receipts::advance(12, '9'), null);
check('zero moves nothing', Receipts::advance(0, '0'), null);
check('a negative number is ignored', Receipts::advance(0, '-5'), null);
check('a fraction is ignored', Receipts::advance(0, '5.5'), null);
check('words are ignored', Receipts::advance(0, 'abc'), null);
check('an array is ignored', Receipts::advance(0, ['7']), null);
check('a missing report is ignored', Receipts::advance(0, null), null);
check('an empty report is ignored', Receipts::advance(0, ''), null);
check('a padded number is ignored', Receipts::advance(0, ' 7'), null);
check('a number longer than any message id is ignored', Receipts::advance(0, '99999999999'), null);

// What a message shows, from the other side's two pointers.
check('past both pointers: sent', Receipts::state(15, 10, 5), 'sent');
check('at the delivered pointer: delivered', Receipts::state(10, 10, 5), 'delivered');
check('  and before it', Receipts::state(8, 10, 5), 'delivered');
check('at the seen pointer: seen', Receipts::state(5, 10, 5), 'seen');
check('  and before it', Receipts::state(3, 10, 5), 'seen');
check('seen counts as delivered even where delivered lags behind', Receipts::state(12, 10, 12), 'seen');
check('a message without an id yet is no more than sent', Receipts::state(0, 10, 10), 'sent');

// The newest seen message carries the time.
check('of several messages, the newest seen', Receipts::newestSeen([3, 5, 8, 11], 9), 8);
check('  whatever order the page lists them in', Receipts::newestSeen([11, 3, 8, 5], 9), 8);
check('none seen yet', Receipts::newestSeen([11, 12], 9), 0);
check('no messages at all', Receipts::newestSeen([], 9), 0);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
