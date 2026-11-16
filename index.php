<?php
/**
 * admin/index.php — platform metrics and moderation overview
 */
require_once __DIR__ . '/../includes/functions.php';
boot_session();
$me = require_admin();

$stats = db()->query('SELECT * FROM v_platform_stats')->fetch();

$pendingItems = (int) db()->query("SELECT COUNT(*) FROM items WHERE status = 'pending'")->fetchColumn();
$openReports  = (int) db()->query("SELECT COUNT(*) FROM reports WHERE status IN ('open','reviewing')")->fetchColumn();
$openDisputes = (int) db()->query("SELECT COUNT(*) FROM disputes WHERE status IN ('open','reviewing','awaiting_evidence')")->fetchColumn();
$suspended    = (int) db()->query("SELECT COUNT(*) FROM users WHERE status = 'suspended'")->fetchColumn();

$recentUsers = db()->query(
    "SELECT u.*, p.full_name, p.username, p.area, p.reputation_score
       FROM users u JOIN profiles p ON p.user_id = u.user_id
      ORDER BY u.created_at DESC LIMIT 6"
)->fetchAll();

$queue = db()->query(
    "SELECT i.*, c.name AS category_name, p.full_name AS owner_name,
            (SELECT file_path FROM item_photos WHERE item_id = i.item_id ORDER BY is_primary DESC LIMIT 1) AS photo
       FROM items i JOIN categories c ON c.category_id = i.category_id
       JOIN profiles p ON p.user_id = i.owner_id
      WHERE i.status = 'pending' ORDER BY i.created_at ASC LIMIT 6"
)->fetchAll();

$recentReports = db()->query(
    "SELECT r.*, p.full_name AS reporter_name FROM reports r
       JOIN profiles p ON p.user_id = r.reporter_id
      WHERE r.status IN ('open','reviewing') ORDER BY r.created_at DESC LIMIT 5"
)->fetchAll();

$monthly = db()->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COUNT(*) n FROM transactions
      GROUP BY ym ORDER BY ym DESC LIMIT 6"
)->fetchAll();
$monthly = array_reverse($monthly);
$maxM = max(1, ...array_map(fn($m) => (int) $m['n'], $monthly ?: [['n' => 1]]));

$pageTitle = 'Admin — BIB';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-main">
    <div class="app-head">
      <div><h1>Admin overview</h1><p>Platform health, moderation queue and recent activity</p></div>
      <div class="flex gap-8">
        <a class="btn btn-outline" href="<?= APP_URL ?>/admin/users.php">Users</a>
        <a class="btn btn-primary" href="<?= APP_URL ?>/admin/items.php">Moderation (<?= $pendingItems ?>)</a>
      </div>
    </div>

    <div class="kpi-grid">
      <div class="kpi"><span class="kpi-label">Members</span><b class="kpi-value"><?= number_format((int) $stats['total_members']) ?></b><span class="kpi-foot"><?= $suspended ?> suspended</span></div>
      <div class="kpi"><span class="kpi-label">Active listings</span><b class="kpi-value"><?= number_format((int) $stats['total_items']) ?></b><span class="kpi-foot"><?= $pendingItems ?> awaiting review</span></div>
      <div class="kpi"><span class="kpi-label">Transactions</span><b class="kpi-value"><?= number_format((int) $stats['total_transactions']) ?></b><span class="kpi-foot"><?= number_format((int) $stats['total_borrows']) ?> completed</span></div>
      <div class="kpi"><span class="kpi-label">Open issues</span><b class="kpi-value"><?= $openReports + $openDisputes ?></b><span class="kpi-foot"><?= $openReports ?> reports · <?= $openDisputes ?> disputes</span></div>
    </div>

    <div class="two-col">
      <div>
        <div class="panel">
          <div class="panel-head"><h3>Listing moderation queue</h3><a href="<?= APP_URL ?>/admin/items.php">View all</a></div>
          <div class="panel-body flush">
            <?php if (!$queue): ?>
              <div class="empty" style="padding:32px 20px"><p>Queue is clear.</p></div>
            <?php else: ?>
              <?php foreach ($queue as $q): ?>
                <div class="request-row">
                  <img src="<?= $q['photo'] ? UPLOAD_URL . '/' . e($q['photo']) : APP_URL . '/assets/img/placeholder.svg' ?>" alt="" style="width:44px;height:44px;border-radius:8px;object-fit:cover">
                  <div style="flex:1;min-width:0">
                    <b class="small"><?= e($q['title']) ?></b>
                    <div class="small muted"><?= e($q['owner_name']) ?> · <?= e($q['category_name']) ?> · <?= time_ago($q['created_at']) ?></div>
                  </div>
                  <div class="flex gap-8">
                    <form method="post" action="<?= APP_URL ?>/admin/items.php">
                      <?= csrf_field() ?>
                      <input type="hidden" name="item_id" value="<?= (int) $q['item_id'] ?>">
                      <input type="hidden" name="action" value="approve">
                      <button class="mini-btn" type="submit">Approve</button>
                    </form>
                    <form method="post" action="<?= APP_URL ?>/admin/items.php">
                      <?= csrf_field() ?>
                      <input type="hidden" name="item_id" value="<?= (int) $q['item_id'] ?>">
                      <input type="hidden" name="action" value="reject">
                      <button class="mini-btn danger" type="submit">Reject</button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head"><h3>Recent reports</h3><a href="<?= APP_URL ?>/admin/reports.php">All reports</a></div>
          <div class="panel-body flush">
            <?php if (!$recentReports): ?>
              <div class="empty" style="padding:32px 20px"><p>No open reports.</p></div>
            <?php else: ?>
              <?php foreach ($recentReports as $r): ?>
                <div class="request-row">
                  <span class="notif-icon" style="background:var(--danger-bg);color:var(--danger)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                  </span>
                  <div style="flex:1;min-width:0">
                    <b class="small"><?= e($r['reason']) ?></b>
                    <div class="small muted">by <?= e($r['reporter_name']) ?> · <?= time_ago($r['created_at']) ?></div>
                  </div>
                  <a class="mini-btn" href="<?= APP_URL ?>/admin/reports.php?id=<?= (int) $r['report_id'] ?>">Review</a>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div>
        <div class="panel">
          <div class="panel-head"><h3>Transactions per month</h3></div>
          <div class="panel-body">
            <div class="chart" style="height:180px">
              <?php foreach ($monthly as $m): ?>
                <div class="col">
                  <div class="bars"><i style="height:<?= max(6, (int) round((int) $m['n'] / $maxM * 120)) ?>px"></i></div>
                  <span><?= date('M', strtotime($m['ym'] . '-01')) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head"><h3>Newest members</h3><a href="<?= APP_URL ?>/admin/users.php">Manage</a></div>
          <div class="panel-body flush">
            <?php foreach ($recentUsers as $u): ?>
              <div class="request-row">
                <span class="avatar" style="background:var(--green-700);color:#fff"><?= e(strtoupper(substr($u['full_name'], 0, 2))) ?></span>
                <div style="flex:1;min-width:0">
                  <b class="small"><?= e($u['full_name']) ?></b>
                  <div class="small muted">@<?= e($u['username']) ?> · <?= e($u['role']) ?> · <?= time_ago($u['created_at']) ?></div>
                </div>
                <span class="badge <?= $u['status'] === 'active' ? 'badge-ok' : 'badge-warn' ?>"><?= e($u['status']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head"><h3>Moderation shortcuts</h3></div>
          <div class="panel-body" style="display:grid;gap:8px">
            <a class="btn btn-outline btn-block" href="<?= APP_URL ?>/admin/items.php">Item moderation</a>
            <a class="btn btn-outline btn-block" href="<?= APP_URL ?>/admin/users.php">User management</a>
            <a class="btn btn-outline btn-block" href="<?= APP_URL ?>/admin/reports.php">Reports</a>
            <a class="btn btn-outline btn-block" href="<?= APP_URL ?>/admin/disputes.php">Disputes</a>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
