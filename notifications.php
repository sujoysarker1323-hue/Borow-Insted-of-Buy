<?php
/**
 * notifications.php — in-app notification list with mark-as-read
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();
$myId = (int) $me['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (post('action') === 'read_all') {
        db()->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ?')->execute([$myId]);
        flash('success', 'All notifications marked as read.');
    } elseif (post('action') === 'read_one') {
        db()->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND user_id = ?')
            ->execute([(int) post('id'), $myId]);
    }
    redirect(APP_URL . '/notifications.php');
}

$filter = get('filter', 'all');
$where = 'user_id = ?';
$params = [$myId];
if ($filter === 'unread') {
    $where .= ' AND is_read = 0';
} elseif ($filter !== 'all') {
    $where .= ' AND type = ?';
    $params[] = $filter;
}

$stmt = db()->prepare("SELECT * FROM notifications WHERE $where ORDER BY created_at DESC LIMIT 100");
$stmt->execute($params);
$notifications = $stmt->fetchAll();

$icons = [
    'request'  => 'M20 6L9 17l-5-5',
    'approval' => 'M20 6L9 17l-5-5',
    'message'  => 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z',
    'reminder' => 'M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9',
    'review'   => 'M12 2l3 6.5 7 .9-5 4.9 1.2 7L12 18l-6.2 3.3L7 14.3 2 9.4l7-.9z',
    'listing'  => 'M3 9h18M9 21V9M3 3h18v18H3z',
    'report'   => 'M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
    'dispute'  => 'M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z',
    'system'   => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM12 8v4M12 16h.01',
];

$pageTitle = 'Notifications — BIB';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="app-main">
    <div class="app-head">
      <div><h1>Notifications</h1><p><?= unread_notifications($myId) ?> unread</p></div>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="read_all">
        <button class="btn btn-outline btn-sm" type="submit">Mark all as read</button>
      </form>
    </div>

    <div class="pill-row" style="margin-bottom:16px">
      <?php foreach (['all' => 'All', 'unread' => 'Unread', 'request' => 'Requests', 'approval' => 'Approvals', 'message' => 'Messages', 'reminder' => 'Reminders', 'review' => 'Reviews'] as $k => $l): ?>
        <a class="chip<?= $filter === $k ? ' active' : '' ?>" href="<?= APP_URL ?>/notifications.php?filter=<?= $k ?>"><?= $l ?></a>
      <?php endforeach; ?>
    </div>

    <div class="panel">
      <div class="panel-body flush">
        <?php if (!$notifications): ?>
          <div class="empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/></svg>
            <p>Nothing here yet.</p>
          </div>
        <?php else: ?>
          <?php foreach ($notifications as $n): ?>
            <div class="notif<?= (int) $n['is_read'] ? '' : ' unread' ?>">
              <span class="notif-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $icons[$n['type']] ?? $icons['system'] ?>"/></svg>
              </span>
              <div style="flex:1">
                <b class="small"><?= e($n['title']) ?></b>
                <?php if ($n['body']): ?><div class="small muted"><?= e($n['body']) ?></div><?php endif; ?>
                <div class="small muted" style="font-size:.72rem;margin-top:2px"><?= time_ago($n['created_at']) ?></div>
              </div>
              <div class="flex gap-8">
                <?php if ($n['link_url']): ?><a class="mini-btn" href="<?= APP_URL ?>/<?= e($n['link_url']) ?>">Open</a><?php endif; ?>
                <?php if (!(int) $n['is_read']): ?>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="read_one">
                    <input type="hidden" name="id" value="<?= (int) $n['notification_id'] ?>">
                    <button class="mini-btn" type="submit">Mark read</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
