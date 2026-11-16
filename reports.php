<?php
/**
 * admin/reports.php — triage reports and open disputes
 */
require_once __DIR__ . '/../includes/functions.php';
boot_session();
$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $reportId = (int) post('report_id');
    $action   = post('action');
    $note     = post('resolution_note');

    $stmt = db()->prepare('SELECT * FROM reports WHERE report_id = ?');
    $stmt->execute([$reportId]);
    $report = $stmt->fetch();
    if (!$report) {
        flash('error', 'Report not found.');
        redirect(APP_URL . '/admin/reports.php');
    }

    if ($action === 'resolve' || $action === 'dismiss') {
        $status = $action === 'resolve' ? 'resolved' : 'dismissed';
        db()->prepare('UPDATE reports SET status = ?, resolution_note = ?, resolved_by = ?, resolved_at = NOW() WHERE report_id = ?')
            ->execute([$status, $note, $me['user_id'], $reportId]);
        notify((int) $report['reporter_id'], 'report', 'Report ' . $status,
            'Your report has been reviewed. ' . $note, 'notifications.php');
        flash('success', 'Report ' . $status . '.');
    }

    if ($action === 'escalate') {
        db()->prepare("UPDATE reports SET status = 'reviewing', resolved_by = ? WHERE report_id = ?")
            ->execute([$me['user_id'], $reportId]);
        db()->prepare(
            'INSERT INTO disputes (transaction_id, opened_by, against_id, reason, description, status)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            $report['transaction_id'], $report['reporter_id'], $report['reported_user_id'],
            $report['reason'], $report['details'], 'open',
        ]);
        flash('success', 'Escalated to a dispute.');
    }

    redirect(APP_URL . '/admin/reports.php');
}

$status = get('status', 'open');
$where = $status === 'all' ? '1=1' : 'r.status = ?';
$params = $status === 'all' ? [] : [$status];

$stmt = db()->prepare(
    "SELECT r.*, p.full_name AS reporter_name, p.username AS reporter_username,
            rp.full_name AS reported_name, i.title AS item_title, t.reference
       FROM reports r
       JOIN profiles p ON p.user_id = r.reporter_id
       LEFT JOIN profiles rp ON rp.user_id = r.reported_user_id
       LEFT JOIN items i ON i.item_id = r.item_id
       LEFT JOIN transactions t ON t.transaction_id = r.transaction_id
      WHERE $where ORDER BY r.created_at DESC LIMIT 100"
);
$stmt->execute($params);
$reports = $stmt->fetchAll();

$pageTitle = 'Reports — BIB';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-main">
    <div class="app-head">
      <div><h1>Reports</h1><p><?= count($reports) ?> in this view</p></div>
      <a class="btn btn-outline" href="<?= APP_URL ?>/admin/disputes.php">Disputes →</a>
    </div>

    <div class="pill-row" style="margin-bottom:16px">
      <?php foreach (['open' => 'Open', 'reviewing' => 'Reviewing', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed', 'all' => 'All'] as $k => $l): ?>
        <a class="chip<?= $status === $k ? ' active' : '' ?>" href="<?= APP_URL ?>/admin/reports.php?status=<?= $k ?>"><?= $l ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (!$reports): ?>
      <div class="panel"><div class="empty"><p>No reports in this view.</p></div></div>
    <?php endif; ?>

    <?php foreach ($reports as $r): ?>
      <div class="panel">
        <div class="panel-head">
          <h3><?= e($r['reason']) ?></h3>
          <span class="badge <?= $r['status'] === 'open' ? 'badge-warn' : ($r['status'] === 'resolved' ? 'badge-ok' : 'badge') ?>"><?= e($r['status']) ?></span>
        </div>
        <div class="panel-body">
          <div class="grid grid-2" style="gap:16px;margin-bottom:14px">
            <div>
              <div class="small muted">Reported by</div>
              <b class="small"><?= e($r['reporter_name']) ?> <span class="muted">@<?= e($r['reporter_username']) ?></span></b>
            </div>
            <div>
              <div class="small muted">Against</div>
              <b class="small"><?= $r['reported_name'] ? e($r['reported_name']) : '—' ?></b>
            </div>
            <div>
              <div class="small muted">Item / transaction</div>
              <b class="small"><?= $r['item_title'] ? e($r['item_title']) : '—' ?><?= $r['reference'] ? ' · ' . e($r['reference']) : '' ?></b>
            </div>
            <div>
              <div class="small muted">Filed</div>
              <b class="small"><?= time_ago($r['created_at']) ?></b>
            </div>
          </div>

          <p class="small" style="white-space:pre-line;background:var(--surface-2);padding:12px 14px;border-radius:8px"><?= e($r['details']) ?></p>

          <?php if ($r['resolution_note']): ?>
            <p class="small muted" style="margin-top:10px"><b>Resolution:</b> <?= e($r['resolution_note']) ?></p>
          <?php endif; ?>

          <?php if (in_array($r['status'], ['open', 'reviewing'], true)): ?>
            <form method="post" style="margin-top:14px">
              <?= csrf_field() ?>
              <input type="hidden" name="report_id" value="<?= (int) $r['report_id'] ?>">
              <div class="field"><label for="note-<?= (int) $r['report_id'] ?>">Resolution note</label><input class="input" id="note-<?= (int) $r['report_id'] ?>" name="resolution_note" placeholder="What did you decide?"></div>
              <div class="flex gap-8 wrap">
                <button class="btn btn-primary btn-sm" name="action" value="resolve" type="submit">Resolve</button>
                <button class="btn btn-outline btn-sm" name="action" value="escalate" type="submit">Escalate to dispute</button>
                <button class="btn btn-ghost btn-sm" name="action" value="dismiss" type="submit">Dismiss</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </main>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
