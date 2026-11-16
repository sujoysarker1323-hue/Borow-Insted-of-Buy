<?php
/**
 * admin/disputes.php — resolve disputes and adjust deposits
 */
require_once __DIR__ . '/../includes/functions.php';
boot_session();
$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $disputeId = (int) post('dispute_id');
    $action    = post('action');
    $note      = post('resolution');
    $deduct    = (float) post('deduct_amount');

    $stmt = db()->prepare('SELECT * FROM disputes WHERE dispute_id = ?');
    $stmt->execute([$disputeId]);
    $dispute = $stmt->fetch();
    if (!$dispute) {
        flash('error', 'Dispute not found.');
        redirect(APP_URL . '/admin/disputes.php');
    }

    if ($action === 'resolve') {
        db()->prepare("UPDATE disputes SET status = 'resolved', resolution = ?, resolved_by = ?, resolved_at = NOW() WHERE dispute_id = ?")
            ->execute([$note, $me['user_id'], $disputeId]);

        if ($dispute['transaction_id']) {
            if ($deduct > 0) {
                db()->prepare("UPDATE deposits SET status = 'deducted', deducted_amount = ?, released_at = NOW() WHERE transaction_id = ?")
                    ->execute([$deduct, $dispute['transaction_id']]);
            } else {
                db()->prepare("UPDATE deposits SET status = 'returned', released_at = NOW() WHERE transaction_id = ? AND status = 'held'")
                    ->execute([$dispute['transaction_id']]);
            }
            db()->prepare("UPDATE transactions SET status = 'completed' WHERE transaction_id = ?")
                ->execute([$dispute['transaction_id']]);
        }

        notify((int) $dispute['opened_by'], 'dispute', 'Dispute resolved', $note, 'notifications.php');
        if ($dispute['against_id']) {
            notify((int) $dispute['against_id'], 'dispute', 'Dispute resolved', $note, 'notifications.php');
        }
        refresh_reputation((int) $dispute['opened_by']);
        if ($dispute['against_id']) {
            refresh_reputation((int) $dispute['against_id']);
        }
        flash('success', 'Dispute resolved.');
    }

    if ($action === 'reviewing') {
        db()->prepare("UPDATE disputes SET status = 'reviewing' WHERE dispute_id = ?")->execute([$disputeId]);
        flash('success', 'Marked as under review.');
    }

    redirect(APP_URL . '/admin/disputes.php');
}

$status = get('status', 'all');
$where = $status === 'all' ? '1=1' : 'd.status = ?';
$params = $status === 'all' ? [] : [$status];

$stmt = db()->prepare(
    "SELECT d.*, op.full_name AS opener_name, ag.full_name AS against_name,
            t.reference, t.deposit_amount, i.title AS item_title
       FROM disputes d
       JOIN profiles op ON op.user_id = d.opened_by
       LEFT JOIN profiles ag ON ag.user_id = d.against_id
       LEFT JOIN transactions t ON t.transaction_id = d.transaction_id
       LEFT JOIN items i ON i.item_id = t.item_id
      WHERE $where ORDER BY d.created_at DESC LIMIT 100"
);
$stmt->execute($params);
$disputes = $stmt->fetchAll();

$pageTitle = 'Disputes — BIB';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-main">
    <div class="app-head">
      <div><h1>Disputes</h1><p><?= count($disputes) ?> in this view</p></div>
      <a class="btn btn-outline" href="<?= APP_URL ?>/admin/reports.php">← Reports</a>
    </div>

    <div class="pill-row" style="margin-bottom:16px">
      <?php foreach (['all' => 'All', 'open' => 'Open', 'reviewing' => 'Reviewing', 'awaiting_evidence' => 'Awaiting evidence', 'resolved' => 'Resolved'] as $k => $l): ?>
        <a class="chip<?= $status === $k ? ' active' : '' ?>" href="<?= APP_URL ?>/admin/disputes.php?status=<?= $k ?>"><?= $l ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (!$disputes): ?>
      <div class="panel"><div class="empty"><p>No disputes in this view.</p></div></div>
    <?php endif; ?>

    <?php foreach ($disputes as $d): ?>
      <div class="panel">
        <div class="panel-head">
          <h3><?= e($d['reason']) ?></h3>
          <span class="badge <?= $d['status'] === 'resolved' ? 'badge-ok' : 'badge-warn' ?>"><?= e($d['status']) ?></span>
        </div>
        <div class="panel-body">
          <div class="grid grid-3" style="gap:16px;margin-bottom:14px">
            <div><div class="small muted">Opened by</div><b class="small"><?= e($d['opener_name']) ?></b></div>
            <div><div class="small muted">Against</div><b class="small"><?= $d['against_name'] ? e($d['against_name']) : '—' ?></b></div>
            <div><div class="small muted">Transaction</div><b class="small"><?= $d['reference'] ? e($d['reference']) : '—' ?></b></div>
            <div><div class="small muted">Item</div><b class="small"><?= $d['item_title'] ? e($d['item_title']) : '—' ?></b></div>
            <div><div class="small muted">Deposit at stake</div><b class="small"><?= money($d['deposit_amount'] ?? 0) ?></b></div>
            <div><div class="small muted">Filed</div><b class="small"><?= time_ago($d['created_at']) ?></b></div>
          </div>

          <p class="small" style="white-space:pre-line;background:var(--surface-2);padding:12px 14px;border-radius:8px"><?= e($d['description']) ?></p>

          <?php if ($d['resolution']): ?>
            <p class="small muted" style="margin-top:10px"><b>Resolution:</b> <?= e($d['resolution']) ?></p>
          <?php endif; ?>

          <?php if ($d['status'] !== 'resolved'): ?>
            <form method="post" style="margin-top:14px">
              <?= csrf_field() ?>
              <input type="hidden" name="dispute_id" value="<?= (int) $d['dispute_id'] ?>">
              <div class="grid grid-2" style="gap:12px">
                <div class="field"><label for="res-<?= (int) $d['dispute_id'] ?>">Resolution</label><input class="input" id="res-<?= (int) $d['dispute_id'] ?>" name="resolution" placeholder="e.g. Deposit returned in full; item damage not proven."></div>
                <div class="field"><label for="ded-<?= (int) $d['dispute_id'] ?>">Deduct from deposit (<?= APP_CURRENCY ?>)</label><input class="input" id="ded-<?= (int) $d['dispute_id'] ?>" name="deduct_amount" type="number" min="0" step="50" value="0"></div>
              </div>
              <div class="flex gap-8">
                <button class="btn btn-primary btn-sm" name="action" value="resolve" type="submit">Resolve dispute</button>
                <button class="btn btn-outline btn-sm" name="action" value="reviewing" type="submit">Mark under review</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </main>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
