<?php
/**
 * borrow_requests.php — incoming and outgoing requests
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();
$myId = (int) $me['user_id'];
$tab = get('tab', 'incoming');

$sql = $tab === 'outgoing'
    ? "SELECT r.*, i.title, p.full_name AS other_name, p.avatar_path, p.reputation_score
         FROM borrow_requests r
         JOIN items i ON i.item_id = r.item_id
         JOIN profiles p ON p.user_id = r.owner_id
        WHERE r.borrower_id = ? ORDER BY r.created_at DESC"
    : "SELECT r.*, i.title, p.full_name AS other_name, p.avatar_path, p.reputation_score
         FROM borrow_requests r
         JOIN items i ON i.item_id = r.item_id
         JOIN profiles p ON p.user_id = r.borrower_id
        WHERE r.owner_id = ? ORDER BY r.created_at DESC";

$stmt = db()->prepare($sql);
$stmt->execute([$myId]);
$requests = $stmt->fetchAll();

$pageTitle = 'Borrow requests — BIB';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="app-main">
    <div class="app-head">
      <div><h1>Borrow requests</h1><p><?= count($requests) ?> <?= $tab === 'outgoing' ? 'sent' : 'received' ?></p></div>
    </div>

    <div class="pill-row" style="margin-bottom:16px">
      <a class="chip<?= $tab === 'incoming' ? ' active' : '' ?>" href="<?= APP_URL ?>/borrow_requests.php?tab=incoming">Incoming</a>
      <a class="chip<?= $tab === 'outgoing' ? ' active' : '' ?>" href="<?= APP_URL ?>/borrow_requests.php?tab=outgoing">Sent</a>
    </div>

    <div class="panel">
      <div class="panel-body flush">
        <?php if (!$requests): ?>
          <div class="empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M20 6L9 17l-5-5"/></svg>
            <p>No <?= $tab === 'outgoing' ? 'sent' : 'incoming' ?> requests.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead><tr><th><?= $tab === 'outgoing' ? 'Owner' : 'Borrower' ?></th><th>Item</th><th>Dates</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($requests as $r): ?>
                  <tr>
                    <td>
                      <div class="cell-user">
                        <?php if ($r['avatar_path']): ?>
                          <img src="<?= UPLOAD_URL . '/' . e($r['avatar_path']) ?>" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover">
                        <?php else: ?>
                          <span class="avatar" style="width:32px;height:32px;font-size:.7rem"><?= e(strtoupper(substr($r['other_name'], 0, 2))) ?></span>
                        <?php endif; ?>
                        <div><b><?= e($r['other_name']) ?></b><span>rep <?= (int) round((float) $r['reputation_score']) ?></span></div>
                      </div>
                    </td>
                    <td><?= e($r['title']) ?></td>
                    <td class="small muted"><?= nice_date($r['start_date']) ?> → <?= nice_date($r['end_date']) ?><br><?= (int) $r['days'] ?> days</td>
                    <td>
                      <?php
                      $cls = match ($r['status']) {
                          'pending' => 'badge-warn',
                          'accepted' => 'badge-ok',
                          'rejected' => 'badge-danger',
                          default => 'badge',
                      };
                      ?>
                      <span class="badge <?= $cls ?>"><?= ucfirst($r['status']) ?></span>
                    </td>
                    <td>
                      <?php if ($tab === 'incoming' && in_array($r['status'], ['pending', 'question'], true)): ?>
                        <a class="mini-btn" href="<?= APP_URL ?>/manage_request.php?id=<?= (int) $r['request_id'] ?>">Review</a>
                      <?php else: ?>
                        <a class="mini-btn" href="<?= APP_URL ?>/item_detail.php?id=<?= (int) $r['item_id'] ?>">View item</a>
                      <?php endif; ?>
                    </td>
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
