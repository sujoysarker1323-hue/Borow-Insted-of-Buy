<?php
/**
 * transaction.php — the borrow lifecycle with two-party pickup/return confirmation
 * Requested → Accepted → Pickup → Borrowed → Return requested → Returned → Completed
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();

$txId = (int) (get('id') ?: post('id'));
$stmt = db()->prepare(
    'SELECT t.*, i.title, i.item_id, i.pickup_instructions, i.rules,
            lp.full_name AS lender_name, lp.username AS lender_username, lp.avatar_path AS lender_avatar,
            bp.full_name AS borrower_name, bp.username AS borrower_username, bp.avatar_path AS borrower_avatar,
            d.amount AS deposit_amount_held, d.status AS deposit_status
       FROM transactions t
       JOIN items i ON i.item_id = t.item_id
       JOIN profiles lp ON lp.user_id = t.lender_id
       JOIN profiles bp ON bp.user_id = t.borrower_id
       LEFT JOIN deposits d ON d.transaction_id = t.transaction_id
      WHERE t.transaction_id = ?'
);
$stmt->execute([$txId]);
$tx = $stmt->fetch();

if (!$tx) {
    http_response_code(404);
    exit('Transaction not found.');
}
$isLender   = (int) $tx['lender_id'] === (int) $me['user_id'];
$isBorrower = (int) $tx['borrower_id'] === (int) $me['user_id'];
if (!$isLender && !$isBorrower && !is_admin()) {
    http_response_code(403);
    exit('You are not part of this transaction.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    [$ok, $msg] = advance_transaction($txId, post('action'), (int) $me['user_id']);
    flash($ok ? 'success' : 'error', $msg);
    redirect(APP_URL . '/transaction.php?id=' . $txId);
}

$statuses = transaction_statuses();
$flow = ['requested', 'accepted', 'pickup', 'borrowed', 'return_requested', 'returned', 'completed'];
$currentIndex = array_search($tx['status'], $flow, true);
if ($currentIndex === false) {
    $currentIndex = -1;
}

$stmt = db()->prepare('SELECT * FROM returns WHERE transaction_id = ? ORDER BY return_id DESC LIMIT 1');
$stmt->execute([$txId]);
$returnRow = $stmt->fetch();

$stmt = db()->prepare('SELECT * FROM reviews WHERE transaction_id = ?');
$stmt->execute([$txId]);
$reviews = $stmt->fetchAll();
$myReview = null;
foreach ($reviews as $r) {
    if ((int) $r['reviewer_id'] === (int) $me['user_id']) {
        $myReview = $r;
    }
}

$overdue = $tx['due_date'] < date('Y-m-d') && in_array($tx['status'], ['borrowed', 'return_requested'], true);

$pageTitle = 'Transaction ' . $tx['reference'] . ' — BIB';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="app-main">
    <div class="breadcrumb"><a href="<?= APP_URL ?>/transactions.php">Transactions</a> <span>/</span> <span><?= e($tx['reference']) ?></span></div>

    <div class="app-head">
      <div>
        <h1><?= e($tx['title']) ?></h1>
        <p><?= e($tx['reference']) ?> · <?= nice_date($tx['start_date']) ?> → <?= nice_date($tx['due_date']) ?> · <?= (int) $tx['days'] ?> days</p>
      </div>
      <div class="flex gap-8">
        <span class="badge <?= status_badge_class($tx['status']) ?>"><?= e($statuses[$tx['status']] ?? $tx['status']) ?></span>
        <a class="btn btn-outline btn-sm" href="<?= APP_URL ?>/receipt.php?id=<?= $txId ?>">Receipt</a>
      </div>
    </div>

    <?php if ($overdue): ?>
      <div class="panel" style="border-color:var(--danger);background:var(--danger-bg)">
        <div class="panel-body" style="padding:14px 18px">
          <b class="small" style="color:var(--danger)">This borrow is overdue by <?= days_between($tx['due_date'], date('Y-m-d')) ?> day(s).</b>
          <p class="small" style="margin:4px 0 0;color:var(--danger)">A late fee of <?= money(LATE_PENALTY_PER_DAY) ?> per day applies. Please arrange the return.</p>
        </div>
      </div>
    <?php endif; ?>

    <!-- Status timeline -->
    <div class="panel">
      <div class="panel-head"><h3>Progress</h3><span class="small muted">Both parties confirm pickup and return</span></div>
      <div class="panel-body">
        <ol class="timeline">
          <?php foreach ($flow as $i => $step): ?>
            <li class="<?= $i < $currentIndex ? 'done' : ($i === $currentIndex ? 'current' : '') ?>">
              <span class="dot"></span>
              <div>
                <b><?= e($statuses[$step]) ?></b>
                <?php if ($step === 'pickup'): ?>
                  <span class="small muted">Lender <?= $tx['pickup_confirmed_by_lender'] ? '✓' : '—' ?> · Borrower <?= $tx['pickup_confirmed_by_borrower'] ? '✓' : '—' ?></span>
                <?php elseif ($step === 'returned'): ?>
                  <span class="small muted">Lender <?= $tx['return_confirmed_by_lender'] ? '✓' : '—' ?> · Borrower <?= $tx['return_confirmed_by_borrower'] ? '✓' : '—' ?></span>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>
    </div>

    <div class="two-col">
      <div>
        <div class="panel">
          <div class="panel-head"><h3>Actions</h3></div>
          <div class="panel-body">
            <form method="post" style="display:grid;gap:8px">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $txId ?>">

              <?php if (in_array($tx['status'], ['accepted', 'pickup'], true)): ?>
                <?php
                $mine = $isLender ? (int) $tx['pickup_confirmed_by_lender'] : (int) $tx['pickup_confirmed_by_borrower'];
                ?>
                <?php if (!$mine): ?>
                  <button class="btn btn-primary btn-block" name="action" value="confirm_pickup" type="submit">Confirm pickup</button>
                  <p class="small muted" style="margin:0">Confirm once the item has changed hands. The borrow starts when both sides confirm.</p>
                <?php else: ?>
                  <button class="btn btn-outline btn-block" disabled>You confirmed pickup — waiting for the other party</button>
                <?php endif; ?>
              <?php endif; ?>

              <?php if ($tx['status'] === 'borrowed'): ?>
                <div class="field" style="margin:0">
                  <label for="condition_note">Condition note (optional)</label>
                  <textarea class="textarea" id="condition_note" name="condition_note" style="min-height:60px" placeholder="Item returned in the same condition…"></textarea>
                </div>
                <button class="btn btn-primary btn-block" name="action" value="request_return" type="submit">Request return</button>
              <?php endif; ?>

              <?php if (in_array($tx['status'], ['return_requested', 'borrowed'], true)): ?>
                <?php
                $mineReturn = $isLender ? (int) $tx['return_confirmed_by_lender'] : (int) $tx['return_confirmed_by_borrower'];
                ?>
                <?php if (!$mineReturn): ?>
                  <button class="btn btn-outline btn-block" name="action" value="confirm_return" type="submit">Confirm return received</button>
                <?php else: ?>
                  <button class="btn btn-outline btn-block" disabled>You confirmed the return — waiting for the other party</button>
                <?php endif; ?>
              <?php endif; ?>

              <?php if (in_array($tx['status'], ['requested', 'accepted'], true)): ?>
                <button class="btn btn-ghost btn-block" name="action" value="cancel" type="submit" style="color:var(--danger)">Cancel transaction</button>
              <?php endif; ?>

              <?php if ($tx['status'] === 'completed' && !$myReview): ?>
                <a class="btn btn-primary btn-block" href="<?= APP_URL ?>/submit_review.php?tx=<?= $txId ?>">Leave a review</a>
              <?php endif; ?>
            </form>

            <div class="flex gap-8" style="margin-top:12px">
              <a class="btn btn-outline btn-sm" style="flex:1" href="<?= APP_URL ?>/messages.php?to=<?= $isLender ? (int) $tx['borrower_id'] : (int) $tx['lender_id'] ?>&item=<?= (int) $tx['item_id'] ?>">Message</a>
              <a class="btn btn-ghost btn-sm" style="flex:1" href="<?= APP_URL ?>/report.php?tx=<?= $txId ?>">Report a problem</a>
            </div>
          </div>
        </div>

        <?php if ($tx['pickup_instructions']): ?>
          <div class="panel">
            <div class="panel-head"><h3>Pickup &amp; return instructions</h3></div>
            <div class="panel-body"><p class="small" style="white-space:pre-line;margin:0"><?= e($tx['pickup_instructions']) ?></p></div>
          </div>
        <?php endif; ?>

        <?php if ($returnRow): ?>
          <div class="panel">
            <div class="panel-head"><h3>Return record</h3></div>
            <div class="panel-body">
              <div class="kv"><span>Requested</span><b><?= time_ago($returnRow['created_at']) ?></b></div>
              <div class="kv"><span>Status</span><b><?= ucfirst($returnRow['status']) ?></b></div>
              <?php if ($returnRow['condition_note']): ?><div class="kv"><span>Condition note</span><b><?= e($returnRow['condition_note']) ?></b></div><?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div>
        <div class="panel">
          <div class="panel-head"><h3>Summary</h3></div>
          <div class="panel-body">
            <div class="kv"><span>Item</span><b><a href="<?= APP_URL ?>/item_detail.php?id=<?= (int) $tx['item_id'] ?>"><?= e($tx['title']) ?></a></b></div>
            <div class="kv"><span>Lender</span><b><?= e($tx['lender_name']) ?></b></div>
            <div class="kv"><span>Borrower</span><b><?= e($tx['borrower_name']) ?></b></div>
            <div class="kv"><span>Daily fee</span><b><?= money($tx['daily_fee']) ?></b></div>
            <div class="kv"><span>Fee total</span><b><?= money($tx['fee_amount']) ?></b></div>
            <div class="kv"><span>Deposit</span><b><?= money($tx['deposit_amount']) ?></b></div>
            <?php if ($tx['penalty_amount'] > 0): ?>
              <div class="kv"><span>Late penalty</span><b style="color:var(--danger)"><?= money($tx['penalty_amount']) ?></b></div>
            <?php endif; ?>
            <div class="kv total"><span>Total</span><b><?= money((float) $tx['fee_amount'] + (float) $tx['penalty_amount']) ?></b></div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head"><h3>Deposit</h3></div>
          <div class="panel-body">
            <?php
            $depStatus = $tx['deposit_status'] ?: 'none';
            $depClass = match ($depStatus) {
                'held' => 'badge-info',
                'returned' => 'badge-ok',
                'deducted' => 'badge-danger',
                default => 'badge-warn',
            };
            ?>
            <div class="flex between center" style="margin-bottom:10px">
              <span class="small muted">Status</span>
              <span class="badge <?= $depClass ?>"><?= ucfirst($depStatus) ?></span>
            </div>
            <div class="kv"><span>Amount</span><b><?= money($tx['deposit_amount']) ?></b></div>
            <p class="small muted" style="margin:10px 0 0">Deposits are held while the item is out and released automatically when both parties confirm the return.</p>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head"><h3>Reviews</h3></div>
          <div class="panel-body">
            <?php if (!$reviews): ?>
              <p class="small muted" style="margin:0">Reviews open once the transaction is completed.</p>
            <?php else: ?>
              <?php foreach ($reviews as $r): ?>
                <div class="flex between center" style="padding:6px 0;border-bottom:1px solid var(--line)">
                  <span class="small"><?= (int) $r['reviewer_id'] === (int) $me['user_id'] ? 'You' : 'Other party' ?></span>
                  <span class="rating"><svg viewBox="0 0 24 24"><path d="M12 2l3 6.5 7 .9-5 4.9 1.2 7L12 18l-6.2 3.3L7 14.3 2 9.4l7-.9z"/></svg> <?= number_format((float) $r['overall'], 1) ?></span>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
