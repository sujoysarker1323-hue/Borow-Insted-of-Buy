<?php
/**
 * admin/items.php — approve, reject and remove listings
 */
require_once __DIR__ . '/../includes/functions.php';
boot_session();
$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $itemId = (int) post('item_id');
    $action = post('action');
    $note   = post('note');

    $stmt = db()->prepare('SELECT owner_id, title FROM items WHERE item_id = ?');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) {
        flash('error', 'Item not found.');
        redirect(APP_URL . '/admin/items.php');
    }

    switch ($action) {
        case 'approve':
            db()->prepare("UPDATE items SET status = 'active', moderation_note = NULL WHERE item_id = ?")->execute([$itemId]);
            notify((int) $item['owner_id'], 'listing', 'Listing approved', '“' . $item['title'] . '” is now live and searchable.', 'item_detail.php?id=' . $itemId);
            flash('success', 'Listing approved and published.');
            break;
        case 'reject':
            db()->prepare("UPDATE items SET status = 'rejected', moderation_note = ? WHERE item_id = ?")->execute([$note, $itemId]);
            notify((int) $item['owner_id'], 'listing', 'Listing rejected', '“' . $item['title'] . '” was not approved. ' . $note, 'my_listings.php');
            flash('success', 'Listing rejected.');
            break;
        case 'remove':
            db()->prepare("UPDATE items SET status = 'removed', moderation_note = ? WHERE item_id = ?")->execute([$note, $itemId]);
            notify((int) $item['owner_id'], 'listing', 'Listing removed', '“' . $item['title'] . '” was removed by a moderator. ' . $note, 'my_listings.php');
            flash('success', 'Listing removed.');
            break;
        case 'feature':
            db()->prepare('UPDATE items SET is_featured = 1 - is_featured WHERE item_id = ?')->execute([$itemId]);
            flash('success', 'Featured flag toggled.');
            break;
    }
    redirect(APP_URL . '/admin/items.php' . (get('status') ? '?status=' . urlencode(get('status')) : ''));
}

$status = get('status', 'pending');
$where = $status === 'all' ? '1=1' : 'i.status = ?';
$params = $status === 'all' ? [] : [$status];

$stmt = db()->prepare(
    "SELECT i.*, c.name AS category_name, p.full_name AS owner_name, p.username,
            (SELECT file_path FROM item_photos WHERE item_id = i.item_id ORDER BY is_primary DESC LIMIT 1) AS photo
       FROM items i JOIN categories c ON c.category_id = i.category_id
       JOIN profiles p ON p.user_id = i.owner_id
      WHERE $where ORDER BY i.created_at DESC LIMIT 100"
);
$stmt->execute($params);
$items = $stmt->fetchAll();

$pageTitle = 'Item moderation — BIB';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-main">
    <div class="app-head">
      <div><h1>Item moderation</h1><p><?= count($items) ?> listings in this view</p></div>
      <a class="btn btn-outline" href="<?= APP_URL ?>/admin/index.php">Back to overview</a>
    </div>

    <div class="pill-row" style="margin-bottom:16px">
      <?php foreach (['pending' => 'Pending', 'active' => 'Active', 'rejected' => 'Rejected', 'removed' => 'Removed', 'all' => 'All'] as $k => $l): ?>
        <a class="chip<?= $status === $k ? ' active' : '' ?>" href="<?= APP_URL ?>/admin/items.php?status=<?= $k ?>"><?= $l ?></a>
      <?php endforeach; ?>
    </div>

    <div class="panel">
      <div class="panel-body flush">
        <?php if (!$items): ?>
          <div class="empty"><p>Nothing in this queue.</p></div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead><tr><th>Item</th><th>Owner</th><th>Category</th><th>Deposit</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody>
                <?php foreach ($items as $it): ?>
                  <tr>
                    <td>
                      <div class="cell-user">
                        <img src="<?= $it['photo'] ? UPLOAD_URL . '/' . e($it['photo']) : APP_URL . '/assets/img/placeholder.svg' ?>" alt="" style="width:38px;height:38px;border-radius:8px;object-fit:cover">
                        <div><b><?= e($it['title']) ?></b><span><?= time_ago($it['created_at']) ?></span></div>
                      </div>
                    </td>
                    <td class="small"><?= e($it['owner_name']) ?><br><span class="muted">@<?= e($it['username']) ?></span></td>
                    <td class="small"><?= e($it['category_name']) ?></td>
                    <td class="small"><?= (float) $it['deposit_amount'] > 0 ? money($it['deposit_amount']) : '—' ?></td>
                    <td>
                      <?php
                      $cls = match ($it['status']) {
                          'active' => 'badge-ok',
                          'pending' => 'badge-warn',
                          'rejected', 'removed' => 'badge-danger',
                          default => 'badge',
                      };
                      ?>
                      <span class="badge <?= $cls ?>"><?= e($it['status']) ?></span>
                      <?php if ($it['is_featured']): ?><span class="badge badge-amber">Featured</span><?php endif; ?>
                    </td>
                    <td>
                      <div class="row-actions">
                        <a class="mini-btn" href="<?= APP_URL ?>/item_detail.php?id=<?= (int) $it['item_id'] ?>">View</a>
                        <?php if ($it['status'] === 'pending'): ?>
                          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="item_id" value="<?= (int) $it['item_id'] ?>"><input type="hidden" name="action" value="approve"><button class="mini-btn" type="submit">Approve</button></form>
                          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="item_id" value="<?= (int) $it['item_id'] ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="note" value="Does not meet listing guidelines."><button class="mini-btn danger" type="submit">Reject</button></form>
                        <?php elseif ($it['status'] === 'active'): ?>
                          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="item_id" value="<?= (int) $it['item_id'] ?>"><input type="hidden" name="action" value="feature"><button class="mini-btn" type="submit"><?= $it['is_featured'] ? 'Unfeature' : 'Feature' ?></button></form>
                          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="item_id" value="<?= (int) $it['item_id'] ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="note" value="Removed by moderator."><button class="mini-btn danger" type="submit">Remove</button></form>
                        <?php endif; ?>
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
<?php require __DIR__ . '/../includes/footer.php'; ?>
