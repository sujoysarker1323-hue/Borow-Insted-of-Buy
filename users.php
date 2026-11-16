<?php
/**
 * admin/users.php — verify, suspend, ban, reactivate
 */
require_once __DIR__ . '/../includes/functions.php';
boot_session();
$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $userId = (int) post('user_id');
    $action = post('action');

    if ($userId === (int) $me['user_id'] && in_array($action, ['suspend', 'ban'], true)) {
        flash('error', 'You cannot suspend or ban your own account.');
        redirect(APP_URL . '/admin/users.php');
    }

    switch ($action) {
        case 'verify':
            db()->prepare('UPDATE profiles SET is_id_verified = 1 WHERE user_id = ?')->execute([$userId]);
            db()->prepare("UPDATE users SET status = 'active', email_verified_at = COALESCE(email_verified_at, NOW()) WHERE user_id = ?")->execute([$userId]);
            notify($userId, 'system', 'Identity verified', 'Your identity has been verified by the BIB team. A verified badge now appears on your profile.', 'profile.php?u=' . $userId);
            flash('success', 'User verified.');
            break;
        case 'suspend':
            db()->prepare("UPDATE users SET status = 'suspended' WHERE user_id = ?")->execute([$userId]);
            notify($userId, 'system', 'Account suspended', 'Your account has been suspended. Contact support for details.', 'index.php');
            flash('success', 'User suspended.');
            break;
        case 'ban':
            db()->prepare("UPDATE users SET status = 'banned' WHERE user_id = ?")->execute([$userId]);
            db()->prepare("UPDATE items SET status = 'removed' WHERE owner_id = ?")->execute([$userId]);
            flash('success', 'User banned and listings removed.');
            break;
        case 'reactivate':
            db()->prepare("UPDATE users SET status = 'active' WHERE user_id = ?")->execute([$userId]);
            notify($userId, 'system', 'Account reactivated', 'Welcome back — your account is active again.', 'dashboard.php');
            flash('success', 'User reactivated.');
            break;
        case 'make_admin':
            db()->prepare("UPDATE users SET role = 'admin' WHERE user_id = ?")->execute([$userId]);
            flash('success', 'User promoted to admin.');
            break;
    }
    redirect(APP_URL . '/admin/users.php' . (get('q') ? '?q=' . urlencode(get('q')) : ''));
}

$q = get('q');
$status = get('status', 'all');

$where = '1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (p.full_name LIKE ? OR p.username LIKE ? OR u.email LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($status !== 'all') {
    $where .= ' AND u.status = ?';
    $params[] = $status;
}

$stmt = db()->prepare(
    "SELECT u.*, p.full_name, p.username, p.area, p.reputation_score, p.is_id_verified,
            (SELECT COUNT(*) FROM items i WHERE i.owner_id = u.user_id AND i.status = 'active') AS item_count,
            (SELECT COUNT(*) FROM transactions t WHERE t.lender_id = u.user_id OR t.borrower_id = u.user_id) AS tx_count
       FROM users u JOIN profiles p ON p.user_id = u.user_id
      WHERE $where ORDER BY u.created_at DESC LIMIT 100"
);
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageTitle = 'User management — BIB';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-main">
    <div class="app-head">
      <div><h1>User management</h1><p><?= count($users) ?> accounts</p></div>
      <a class="btn btn-outline" href="<?= APP_URL ?>/admin/index.php">Back to overview</a>
    </div>

    <form method="get" class="flex gap-8 wrap" style="margin-bottom:16px">
      <input class="input" name="q" value="<?= e($q) ?>" placeholder="Search name, username or email" style="max-width:320px">
      <select class="select" name="status" style="max-width:180px">
        <?php foreach (['all' => 'All statuses', 'active' => 'Active', 'unverified' => 'Unverified', 'suspended' => 'Suspended', 'banned' => 'Banned'] as $k => $l): ?>
          <option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-primary" type="submit">Filter</button>
    </form>

    <div class="panel">
      <div class="panel-body flush">
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Rep</th><th>Items</th><th>Tx</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($users as $u): ?>
                <tr>
                  <td>
                    <div class="cell-user">
                      <span class="avatar" style="width:32px;height:32px;font-size:.7rem"><?= e(strtoupper(substr($u['full_name'], 0, 2))) ?></span>
                      <div>
                        <b><?= e($u['full_name']) ?> <?php if ($u['is_id_verified']): ?><span class="badge badge-ok" style="font-size:.55rem">✓</span><?php endif; ?></b>
                        <span>@<?= e($u['username']) ?> · <?= e($u['email']) ?></span>
                      </div>
                    </div>
                  </td>
                  <td><span class="badge <?= $u['role'] === 'admin' ? 'badge-amber' : 'badge' ?>"><?= e($u['role']) ?></span></td>
                  <td>
                    <?php
                    $cls = match ($u['status']) {
                        'active' => 'badge-ok',
                        'suspended', 'banned' => 'badge-danger',
                        default => 'badge-warn',
                    };
                    ?>
                    <span class="badge <?= $cls ?>"><?= e($u['status']) ?></span>
                  </td>
                  <td><?= (int) round((float) $u['reputation_score']) ?></td>
                  <td><?= (int) $u['item_count'] ?></td>
                  <td><?= (int) $u['tx_count'] ?></td>
                  <td>
                    <div class="row-actions">
                      <?php if (!$u['is_id_verified']): ?>
                        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>"><input type="hidden" name="action" value="verify"><button class="mini-btn" type="submit">Verify</button></form>
                      <?php endif; ?>
                      <?php if ($u['status'] === 'active'): ?>
                        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>"><input type="hidden" name="action" value="suspend"><button class="mini-btn" type="submit">Suspend</button></form>
                        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>"><input type="hidden" name="action" value="ban"><button class="mini-btn danger" type="submit">Ban</button></form>
                      <?php else: ?>
                        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>"><input type="hidden" name="action" value="reactivate"><button class="mini-btn" type="submit">Reactivate</button></form>
                      <?php endif; ?>
                      <a class="mini-btn" href="<?= APP_URL ?>/profile.php?u=<?= (int) $u['user_id'] ?>">Profile</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
