<?php
/**
 * transactions.php — full borrowing history with overdue detection
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();
$myId = (int) $me['user_id'];

sweep_overdue();

$filter = get('filter', 'all');
$where = '(t.borrower_id = ? OR t.lender_id = ?)';
$params = [$myId, $myId];

if ($filter === 'active') {
    $where .= " AND t.status IN ('accepted','pickup','borrowed','return_requested')";
} elseif ($filter === 'completed') {
    $where .= " AND t.status = 'completed'";
} elseif ($filter === 'overdue') {
    $where .= ' AND t.is_overdue = 1';
}

$stmt = db()->prepare(
    "SELECT t.*, i.title,
            CASE WHEN t.borrower_id = ? THEN 'Borrowing' ELSE 'Lending' END AS direction,
            CASE WHEN t.borrower_id = ? THEN lp.full_name ELSE bp.full_name END AS other_name
       FROM transactions t
       JOIN items i ON i.item_id = t.item_id
       JOIN profiles lp ON lp.user_id = t.lender_id
       JOIN profiles bp ON bp.user_id = t.borrower_id
      WHERE $where
      ORDER BY t.created_at DESC"
);
$stmt->execute(array_merge([$myId, $myId], $params));
$rows = $stmt->fetchAll();

$pageTitle = 'Transactions — BIB';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="app-main">
    <div class="app-head">
      <div><h1>Transactions</h1><p>Your complete borrowing history</p></div>
    </div>

    <div class="pill-row" style="margin-bottom:16px">
      <?php foreach (['all' => 'All', 'active' => 'Active', 'completed' => 'Completed', 'overdue' => 'Overdue'] as $k => $l): ?>
        <a class="chip<?= $filter === $k ? ' active' : '' ?>" href="<?= APP_URL ?>/transactions.php?filter=<?= $k ?>"><?= $l ?></a>
      <?php endforeach; ?>
    </div>

    <div class="panel">
      <div class="panel-body flush">
        <?php if (!$rows): ?>
          <div class="empty"><p>No transactions in this view.</p></div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead><tr><th>Reference</th><th>Item</th><th>Direction</th><th>Dates</th><th>Total</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($rows as $t): ?>
                  <tr>
                    <td class="small"><?= e($t['reference']) ?></td>
                    <td><b><?= e($t['title']) ?></b><div class="small muted"><?= e($t['other_name']) ?></div></td>
                    <td><span class="badge <?= $t['direction'] === 'Borrowing' ? 'badge-info' : 'badge-green' ?>"><?= $t['direction'] ?></span></td>
                    <td class="small muted"><?= nice_date($t['start_date']) ?> → <?= nice_date($t['due_date']) ?></td>
                    <td><?= money((float) $t['fee_amount'] + (float) $t['penalty_amount']) ?></td>
                    <td>
                      <span class="badge <?= status_badge_class($t['status']) ?>"><?= e(transaction_statuses()[$t['status']] ?? $t['status']) ?></span>
                      <?php if ((int) $t['is_overdue']): ?><span class="badge badge-danger">Overdue</span><?php endif; ?>
                    </td>
                    <td><a class="mini-btn" href="<?= APP_URL ?>/transaction.php?id=<?= (int) $t['transaction_id'] ?>">Open</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
