<?php
/**
 * wishlist.php — saved items (AJAX toggle endpoint + list view)
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();
$myId = (int) $me['user_id'];

// AJAX toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    $itemId = (int) ($_POST['item_id'] ?? 0);
    if ($itemId <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Invalid item']);
        exit;
    }
    $stmt = db()->prepare('SELECT 1 FROM wishlist WHERE user_id = ? AND item_id = ?');
    $stmt->execute([$myId, $itemId]);
    if ($stmt->fetchColumn()) {
        db()->prepare('DELETE FROM wishlist WHERE user_id = ? AND item_id = ?')->execute([$myId, $itemId]);
        echo json_encode(['ok' => true, 'saved' => false]);
    } else {
        db()->prepare('INSERT IGNORE INTO wishlist (user_id, item_id) VALUES (?,?)')->execute([$myId, $itemId]);
        echo json_encode(['ok' => true, 'saved' => true]);
    }
    exit;
}

// Remove from the list view
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    db()->prepare('DELETE FROM wishlist WHERE user_id = ? AND item_id = ?')
        ->execute([$myId, (int) post('item_id')]);
    flash('success', 'Removed from your wishlist.');
    redirect(APP_URL . '/wishlist.php');
}

$stmt = db()->prepare(
    "SELECT w.created_at AS saved_at, i.*, c.name AS category_name,
            p.full_name, p.area AS owner_area, p.avg_rating,
            (SELECT file_path FROM item_photos WHERE item_id = i.item_id ORDER BY is_primary DESC LIMIT 1) AS photo
       FROM wishlist w
       JOIN items i ON i.item_id = w.item_id
       JOIN categories c ON c.category_id = i.category_id
       JOIN profiles p ON p.user_id = i.owner_id
      WHERE w.user_id = ?
      ORDER BY w.created_at DESC"
);
$stmt->execute([$myId]);
$saved = $stmt->fetchAll();

$pageTitle = 'Wishlist — BIB';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="app-main">
    <div class="app-head">
      <div><h1>Wishlist</h1><p><?= count($saved) ?> saved item<?= count($saved) === 1 ? '' : 's' ?></p></div>
      <a class="btn btn-outline" href="<?= APP_URL ?>/search.php">Browse more</a>
    </div>

    <?php if (!$saved): ?>
      <div class="panel">
        <div class="empty">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/></svg>
          <h3>Nothing saved yet</h3>
          <p>Tap the heart on any listing to keep it here for later.</p>
          <a class="btn btn-primary btn-sm" href="<?= APP_URL ?>/search.php">Browse items</a>
        </div>
      </div>
    <?php else: ?>
      <div class="grid grid-3">
        <?php foreach ($saved as $it): ?>
          <article class="item-card">
            <div class="item-media">
              <a href="<?= APP_URL ?>/item_detail.php?id=<?= (int) $it['item_id'] ?>">
                <img src="<?= $it['photo'] ? UPLOAD_URL . '/' . e($it['photo']) : APP_URL . '/assets/img/placeholder.svg' ?>" alt="<?= e($it['title']) ?>" loading="lazy">
              </a>
              <div class="item-badges">
                <span class="badge <?= $it['status'] === 'active' ? 'badge-green' : 'badge-warn' ?>"><?= $it['status'] === 'active' ? 'Available' : 'Unavailable' ?></span>
              </div>
            </div>
            <div class="item-body">
              <span class="item-cat"><?= e($it['category_name']) ?></span>
              <h3 class="item-title"><a href="<?= APP_URL ?>/item_detail.php?id=<?= (int) $it['item_id'] ?>"><?= e($it['title']) ?></a></h3>
              <div class="item-meta">
                <span class="rating"><svg viewBox="0 0 24 24"><path d="M12 2l3 6.5 7 .9-5 4.9 1.2 7L12 18l-6.2 3.3L7 14.3 2 9.4l7-.9z"/></svg> <?= number_format((float) $it['avg_rating'], 1) ?></span>
                <span>·</span><span><?= e($it['owner_area']) ?></span>
              </div>
              <div class="item-foot">
                <span class="price"><?= (float) $it['daily_fee'] > 0 ? money($it['daily_fee']) . ' <small>/ day</small>' : 'Free' ?></span>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="item_id" value="<?= (int) $it['item_id'] ?>">
                  <button class="mini-btn danger" type="submit">Remove</button>
                </form>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
