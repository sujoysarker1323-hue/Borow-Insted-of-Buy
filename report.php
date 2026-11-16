<?php
/**
 * report.php — report a listing, a user or a transaction; optionally block the user
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();
$myId = (int) $me['user_id'];

$itemId = (int) get('item') ?: null;
$userId = (int) get('user') ?: null;
$txId   = (int) get('tx') ?: null;

$targetLabel = 'this content';
if ($itemId) {
    $stmt = db()->prepare('SELECT title FROM items WHERE item_id = ?');
    $stmt->execute([$itemId]);
    $targetLabel = 'listing “' . ($stmt->fetchColumn() ?: '') . '”';
} elseif ($userId) {
    $stmt = db()->prepare('SELECT full_name FROM profiles WHERE user_id = ?');
    $stmt->execute([$userId]);
    $targetLabel = 'user ' . ($stmt->fetchColumn() ?: '');
} elseif ($txId) {
    $stmt = db()->prepare('SELECT reference FROM transactions WHERE transaction_id = ?');
    $stmt->execute([$txId]);
    $targetLabel = 'transaction ' . ($stmt->fetchColumn() ?: '');
}

$errors = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $reason  = post('reason');
    $details = post('details');
    $block   = post('block') === '1';

    if ($reason === '') {
        $errors[] = 'Choose a reason.';
    }
    if (str_len($details) < 15) {
        $errors[] = 'Please describe what happened (at least 15 characters).';
    }

    if (!$errors) {
        db()->prepare(
            'INSERT INTO reports (reporter_id, reported_user_id, item_id, transaction_id, reason, details, status)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$myId, $userId, $itemId, $txId, $reason, $details, 'open']);

        if ($block && $userId && $userId !== $myId) {
            db()->prepare('INSERT IGNORE INTO blocks (blocker_id, blocked_id, reason) VALUES (?,?,?)')
                ->execute([$myId, $userId, $reason]);
        }

        // Tell the admins
        $admins = db()->query("SELECT user_id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            notify((int) $adminId, 'report', 'New report filed',
                $me['full_name'] . ' reported ' . $targetLabel . ' — ' . $reason, 'admin/reports.php');
        }

        $done = true;
    }
}

$pageTitle = 'Report a problem — BIB';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="container narrow" style="padding:36px 24px 80px">
  <div class="breadcrumb"><a href="<?= APP_URL ?>/dashboard.php">Dashboard</a> <span>/</span> <span>Report</span></div>

  <?php if ($done): ?>
    <div class="panel">
      <div class="panel-body" style="padding:36px;text-align:center">
        <div style="width:56px;height:56px;border-radius:50%;background:var(--ok-bg);color:var(--ok);display:grid;place-items:center;margin:0 auto 18px">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="26" height="26"><path d="M20 6L9 17l-5-5"/></svg>
        </div>
        <h1 style="font-size:1.7rem">Report received</h1>
        <p>Our moderators review every report. You'll get a notification when there's an update.</p>
        <a class="btn btn-primary" href="<?= APP_URL ?>/dashboard.php">Back to dashboard</a>
      </div>
    </div>
  <?php else: ?>
    <h1 style="font-size:1.9rem">Report a problem</h1>
    <p class="muted">You are reporting <?= e($targetLabel) ?>. Reports are private — the other party is not told who filed them.</p>

    <?php if ($errors): ?>
      <div class="panel" style="border-color:var(--danger)">
        <div class="panel-body" style="padding:14px 16px">
          <?php foreach ($errors as $err): ?><div class="small" style="color:var(--danger)">• <?= e($err) ?></div><?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <form method="post" class="panel">
      <?= csrf_field() ?>
      <div class="panel-body">
        <div class="field">
          <label for="reason">Reason</label>
          <select class="select" id="reason" name="reason" required>
            <option value="">Choose a reason…</option>
            <option>Item not as described</option>
            <option>Item never handed over</option>
            <option>Item returned damaged</option>
            <option>Late return</option>
            <option>Inappropriate or unsafe listing</option>
            <option>Harassment or abusive messages</option>
            <option>Spam or scam</option>
            <option>Other</option>
          </select>
        </div>
        <div class="field">
          <label for="details">What happened?</label>
          <textarea class="textarea" id="details" name="details" style="min-height:120px" placeholder="Dates, what was agreed, what went wrong…" required></textarea>
        </div>
        <?php if ($userId && $userId !== $myId): ?>
          <label class="check" style="margin-bottom:16px">
            <input type="checkbox" name="block" value="1"> Also block this user — they won't be able to message you or request your items.
          </label>
        <?php endif; ?>
        <div class="flex gap-8">
          <button class="btn btn-primary" type="submit">Submit report</button>
          <a class="btn btn-ghost" href="<?= APP_URL ?>/dashboard.php">Cancel</a>
        </div>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
