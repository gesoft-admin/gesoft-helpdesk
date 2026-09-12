<?php
/**
 * What a customer may see of their own conversations, and what it is called,
 * checked without booting Laravel.
 *
 * Run it directly:  php Modules/GesoftLiveChat/Tests/history.php
 *
 * These are the rules behind a screen a customer reads. A mistake in the
 * visibility ones shows somebody an internal note; a mistake in the arithmetic
 * is a badge that says 3 when there is 1, which is a support call of its own.
 */

require __DIR__.'/../Support/History.php';

use Modules\GesoftLiveChat\Support\History;

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok    %-66s %s\n", $label, json_encode($got)); }
    else { $fail++; printf("  FAIL  %-66s got: %s  wanted: %s\n", $label, json_encode($got), json_encode($want)); }
}

echo "GesoftLiveChat — what a customer sees of their history\n\n";

// FreeScout's own numbers, restated here so a change to either side shows up.
$ACTIVE = 1; $PENDING = 2; $CLOSED = 3; $SPAM = 4;
$PUBLISHED = 2; $DELETED = 3;
$CUSTOMER_THREAD = 1; $AGENT_THREAD = 2; $NOTE = 3; $LINEITEM = 4;

// ------------------------------------------------------------ what is shown

check('a customer message is shown', in_array($CUSTOMER_THREAD, History::TYPES, true), true);
check('an agent message is shown', in_array($AGENT_THREAD, History::TYPES, true), true);
check('an internal note is NOT', in_array($NOTE, History::TYPES, true), false);
check('a line item is NOT', in_array($LINEITEM, History::TYPES, true), false);
check('only published threads are shown', History::STATE, $PUBLISHED);
check('unread counts agent messages', History::AGENT_TYPE, $AGENT_THREAD);

// ------------------------------------------------------- custom statuses

// FreeScout lets an installation add statuses above the standard ones and
// folds them onto a standard one by their first digit. Reading `status`
// directly would show a customer a state nothing has a name for.
check('a standard status is itself', History::mainStatus($PENDING), 2);
check('a custom status folds onto its main one', History::mainStatus(21), 2);
check('  and so does a three-digit one', History::mainStatus(311), 3);

// ----------------------------------------------------------- what is listed

check('an open conversation is listed', History::isVisible($ACTIVE, $PUBLISHED), true);
check('a closed one is listed too', History::isVisible($CLOSED, $PUBLISHED), true);
check('spam is not', History::isVisible($SPAM, $PUBLISHED), false);
check('  nor a custom status folding onto spam', History::isVisible(41, $PUBLISHED), false);
check('a deleted conversation is not', History::isVisible($ACTIVE, $DELETED), false);

// ------------------------------------------------------------ what is writable

check('a closed conversation can still be replied to', History::canReply($CLOSED, $PUBLISHED), true);
check('  which is the whole point of a history', History::canReply($PENDING, $PUBLISHED), true);
check('spam cannot be replied into', History::canReply($SPAM, $PUBLISHED), false);
check('a deleted conversation cannot', History::canReply($ACTIVE, $DELETED), false);

// ---------------------------------------------------------------- the words

check('active reads as open', History::statusKey($ACTIVE), 'active');
check('pending reads as waiting on the customer', History::statusKey($PENDING), 'pending');
check('closed reads as resolved', History::statusKey($CLOSED), 'closed');
check('a custom status borrows its main one\'s word', History::statusKey(21), 'pending');
check('something unrecognisable reads as open rather than as nothing', History::statusKey(0), 'active');

// ------------------------------------------------------------- the badge

check('nothing unread is nothing', History::totals([]), ['conversations' => 0, 'messages' => 0]);
check('zeroes do not count as conversations', History::totals([5 => 0, 6 => 0]), ['conversations' => 0, 'messages' => 0]);
check('one conversation with three answers', History::totals([5 => 3]), ['conversations' => 1, 'messages' => 3]);
check('two conversations, four answers', History::totals([5 => 3, 6 => 1, 7 => 0]), ['conversations' => 2, 'messages' => 4]);

// --------------------------------------------------------- the seen pointer

check('a report moves the pointer forward', History::advance(10, 20, 30), 20);
check('a report that is behind moves nothing', History::advance(20, 10, 30), null);
check('the same place again moves nothing', History::advance(20, 20, 30), null);
check('a report past the end is capped at the end', History::advance(10, 999, 30), 30);
check('  and capping cannot move it backwards', History::advance(30, 999, 30), null);
check('nothing to see leaves it alone', History::advance(0, 0, 0), null);

printf("\n%d passed, %d failed\n\n", $pass, $fail);
exit($fail ? 1 : 0);
