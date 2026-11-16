<?php
/**
 * my_listings.php — the owner's inventory with quick status toggles
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $itemId = (int) post('item_id');
    $to     = post('status');

    $stmt = db()->prepare('SELECT owner_id FROM items WHERE item_id = ?');
    $stmt->execute([$itemId]);
    $owner = $stmt->fetchColumn();

    if ($owner && ((int) $owner === (int) $me['user_id'] || is_admin())
        && in_array($to, ['active', 'unavailable'], true)) {
        db()->prepare('UPDATE items SET status = ? WHERE item_id = ?')->execute([$to, $itemId]);
        flash('success', $to === 'active' ? 'Listing is live again.' : 'Listing marked unavailable.');
    }
    redirect(APP_URL . '/my_listings.php');
}

$stmt = db()->prepare(
    "SELECT i.*, c.name AS category_name,
            (SELECT file_path FROM item_photos WHERE item_id = i.item_id ORDER BY is_primary DESC, sort_order LIMIT 1) AS photo,
            (SELECT COUNT(*) FROM borrow_requests r WHERE r.item_id = i.item_id AND r.status = 'pending') AS pending_requests
       FROM items i JOIN categories c ON c.category_id = i.category_id
      WHERE i.owner_id = ? AND i.status <> 'removed'
      ORDER BY i.created_at DESC"
);
$stmt->execute([$me['user_id']]);
$items = $stmt->fetchAll();

$pageTitle = 'My listings — BIB';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="app-main">
    <div class="app-head">
      <div>
        <h1>My listings</h1>
        <p><?= count($items) ?> items · <?= count(array_filter($items, fn($i) => $i['status'] === 'active')) ?> live</p>
      </div>
      <a class="btn btn-primary" href="<?= APP_URL ?>/list_item.php">+ List an item</a>
    </div>

    <div class="panel">
      <div class="panel-body flush">
        <?php if (!$items): ?>
          <div class="empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
            <p>You have not listed anything yet.</p>
            <a class="btn btn-primary btn-sm" href="<?= APP_URL ?>/list_item.php">List your first item</a>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead><tr><th>Item</th><th>Category</th><th>Deposit</th><th>Borrows</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody>
                <?php foreach ($items as $it): ?>
                  <tr>
                    <td>
                      <div class="cell-user">
                        <img src="<?= $it['photo'] ? UPLOAD_URL . '/' . e($it['photo']) : APP_URL . '/assets/img/placeholder.svg' ?>" alt="" style="width:38px;height:38px;border-radius:8px;object-fit:cover">
                        <div>
                          <b><?= e($it['title']) ?></b>
                          <span><?= e($it['area']) ?> · <?= nice_date($it['created_at']) ?></span>
                        </div>
                      </div>
                    </td>
                    <td><?= e($it['category_name']) ?></td>
                    <td><?= (float) $it['deposit_amount'] > 0 ? money($it['deposit_amount']) : '—' ?></td>
                    <td><?= (int) $it['borrow_count'] ?></td>
                    <td>
                      <?php
                      $cls = match ($it['status']) {
                          'active' => 'badge-ok',
                          'pending' => 'badge-warn',
                          'rejected' => 'badge-danger',
                          default => 'badge',
                      };
                      ?>
                      <span class="badge <?= $cls ?>"><?= ucfirst($it['status']) ?></span>
                      <?php if ((int) $it['pending_requests'] > 0): ?>
                        <span class="badge badge-amber"><?= (int) $it['pending_requests'] ?> request<?= (int) $it['pending_requests'] > 1 ? 's' : '' ?></span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="row-actions">
                        <a class="mini-btn" href="<?= APP_URL ?>/item_detail.php?id=<?= (int) $it['item_id'] ?>">View</a>
                        <a class="mini-btn" href="<?= APP_URL ?>/edit_item.php?id=<?= (int) $it['item_id'] ?>">Edit</a>
                        <form method="post" style="display:inline">
                          <?= csrf_field() ?>
                          <input type="hidden" name="item_id" value="<?= (int) $it['item_id'] ?>">
                          <input type="hidden" name="status" value="<?= $it['status'] === 'active' ? 'unavailable' : 'active' ?>">
                          <button class="mini-btn" type="submit"><?= $it['status'] === 'active' ? 'Mark unavailable' : 'Mark available' ?></button>
                        </form>
                        <a class="mini-btn danger" href="<?= APP_URL ?>/delete_item.php?id=<?= (int) $it['item_id'] ?>">Delete</a>
                      </div>
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
