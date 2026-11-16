<?php
/**
 * request_borrow.php — borrower submits a dated request; creates the
 * borrow_request, the transaction shell, the deposit record and a conversation.
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/search.php');
}
verify_csrf();

$itemId = (int) post('item_id');
$start  = post('start_date');
$end    = post('end_date');
$reason = post('reason');
$message = post('message');

$stmt = db()->prepare(
    "SELECT i.*, p.full_name AS owner_name FROM items i JOIN profiles p ON p.user_id = i.owner_id
      WHERE i.item_id = ? AND i.status = 'active'"
);
$stmt->execute([$itemId]);
$item = $stmt->fetch();

if (!$item) {
    flash('error', 'That item is no longer available.');
    redirect(APP_URL . '/search.php');
}
if ((int) $item['owner_id'] === (int) $me['user_id']) {
    flash('error', 'You cannot borrow your own item.');
    redirect(APP_URL . '/item_detail.php?id=' . $itemId);
}

$errors = [];
if ($start === '' || $end === '') {
    $errors[] = 'Choose both a start and an end date.';
} elseif (strtotime($start) < strtotime(date('Y-m-d'))) {
    $errors[] = 'The start date cannot be in the past.';
} elseif (strtotime($end) < strtotime($start)) {
    $errors[] = 'The end date must be after the start date.';
} else {
    $days = days_between($start, $end) + 1;
    if ($days > (int) $item['max_borrow_days']) {
        $errors[] = 'This item can be borrowed for at most ' . (int) $item['max_borrow_days'] . ' days.';
    }
}
if (str_len($reason) < 10) {
    $errors[] = 'Tell the owner what you need it for (at least 10 characters).';
}

// Overlap check against active borrows
if (!$errors) {
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM transactions
          WHERE item_id = ? AND status IN ('accepted','pickup','borrowed','return_requested')
            AND NOT (due_date < ? OR start_date > ?)"
    );
    $stmt->execute([$itemId, $start, $end]);
    if ((int) $stmt->fetchColumn() > 0) {
        $errors[] = 'Those dates overlap an existing borrow. Pick another window.';
    }
}

// Blocked availability windows
if (!$errors) {
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM availability
          WHERE item_id = ? AND is_blocked = 1 AND NOT (end_date < ? OR start_date > ?)'
    );
    $stmt->execute([$itemId, $start, $end]);
    if ((int) $stmt->fetchColumn() > 0) {
        $errors[] = 'The owner has blocked those dates.';
    }
}

if ($errors) {
    foreach ($errors as $err) {
        flash('error', $err);
    }
    redirect(APP_URL . '/item_detail.php?id=' . $itemId);
}

$days = days_between($start, $end) + 1;
$fee  = (float) $item['daily_fee'] * $days;

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare(
        'INSERT INTO borrow_requests (item_id, borrower_id, owner_id, start_date, end_date, days, reason, message, status)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([$itemId, $me['user_id'], $item['owner_id'], $start, $end, $days, $reason, $message, 'pending']);
    $requestId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO transactions (reference, item_id, request_id, lender_id, borrower_id, start_date, due_date,
                                   days, daily_fee, fee_amount, deposit_amount, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        next_reference(), $itemId, $requestId, $item['owner_id'], $me['user_id'],
        $start, $end, $days, $item['daily_fee'], $fee, $item['deposit_amount'], 'requested',
    ]);
    $txId = (int) $pdo->lastInsertId();

    if ((float) $item['deposit_amount'] > 0) {
        $pdo->prepare('INSERT INTO deposits (transaction_id, payer_id, payee_id, amount, status) VALUES (?,?,?,?,?)')
            ->execute([$txId, $me['user_id'], $item['owner_id'], $item['deposit_amount'], 'pending']);
    }

    $convId = find_or_create_conversation(
        (int) $me['user_id'], (int) $item['owner_id'], $itemId, $requestId, $txId,
        'Borrow request — ' . $item['title']
    );
    $pdo->prepare('INSERT INTO messages (conversation_id, sender_id, body) VALUES (?,?,?)')
        ->execute([$convId, $me['user_id'], $message !== '' ? $message : $reason]);

    $pdo->commit();
} catch (Throwable $ex) {
    $pdo->rollBack();
    flash('error', 'Could not send the request. Please try again.');
    redirect(APP_URL . '/item_detail.php?id=' . $itemId);
}

notify((int) $item['owner_id'], 'request', 'New borrow request',
    $me['full_name'] . ' wants to borrow “' . $item['title'] . '” from ' . nice_date($start) . ' to ' . nice_date($end) . '.',
    'manage_request.php?id=' . $requestId);

flash('success', 'Request sent. ' . $item['owner_name'] . ' usually replies within a few hours.');
redirect(APP_URL . '/transaction.php?id=' . $txId);
