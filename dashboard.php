<?php
/**
 * dashboard.php — user overview: quick actions, stats, upcoming returns
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();
$myId = (int) $me['user_id'];

sweep_overdue();

$stmt = db()->prepare("SELECT COUNT(*) FROM items WHERE owner_id = ? AND status = 'active'");
$stmt->execute([$myId]);
$activeListings = (int) $stmt->fetchColumn();

$stmt = db()->prepare("SELECT COUNT(*) FROM borrow_requests WHERE owner_id = ? AND status = 'pending'");
$stmt->execute([$myId]);
$pendingRequests = (int) $stmt->fetchColumn();

$stmt = db()->prepare("SELECT COUNT(*) FROM transactions WHERE borrower_id = ? AND status IN ('accepted','pickup','borrowed','return_requested')");
$stmt->execute([$myId]);
$activeBorrows = (int) $stmt->fetchColumn();

$stmt = db()->prepare("SELECT COUNT(*) FROM transactions WHERE lender_id = ? AND status IN ('accepted','pickup','borrowed','return_requested')");
$stmt->execute([$myId]);
$activeLends = (int) $stmt->fetchColumn();

$stmt = db()->prepare("SELECT COUNT(*) FROM transactions WHERE (lender_id = ? OR borrower_id = ?) AND status = 'completed'");
$stmt->execute([$myId, $myId]);
$completed = (int) $stmt->fetchColumn();

$stmt = db()->prepare(
    "SELECT t.*, i.title, i.item_id,
            CASE WHEN t.borrower_id = ? THEN 'borrowing' ELSE 'lending' END AS direction,
            CASE WHEN t.borrower_id = ? THEN lp.full_name ELSE bp.full_name END AS other_name
       FROM transactions t
       JOIN items i ON i.item_id = t.item_id
       JOIN profiles lp ON lp.user_id = t.lender_id
       JOIN profiles bp ON bp.user_id = t.borrower_id
      WHERE (t.borrower_id = ? OR t.lender_id = ?)
        AND t.status IN ('accepted','pickup','borrowed','return_requested')
      ORDER BY t.due_date ASC LIMIT 5"
);
$stmt->execute([$myId, $myId, $myId, $myId]);
$upcoming = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT r.*, i.title, p.full_name AS borrower_name, p.avatar_path, p.reputation_score
       FROM borrow_requests r
       JOIN items i ON i.item_id = r.item_id
       JOIN profiles p ON p.user_id = r.borrower_id
      WHERE r.owner_id = ? AND r.status = 'pending'
      ORDER BY r.created_at DESC LIMIT 4"
);
$stmt->execute([$myId]);
$requests = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT t.*, i.title FROM transactions t JOIN items i ON i.item_id = t.item_id
      WHERE (t.borrower_id = ? OR t.lender_id = ?) ORDER BY t.created_at DESC LIMIT 6"
);
$stmt->execute([$myId, $myId]);
$recentTx = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5');
$stmt->execute([$myId]);
$notifs = $stmt->fetchAll();

$stmt = db()->prepare('SELECT b.* FROM user_badges ub JOIN badges b ON b.badge_id = ub.badge_id WHERE ub.user_id = ? LIMIT 5');
$stmt->execute([$myId]);
$badges = $stmt->fetchAll();

$pageTitle = 'Dashboard — BIB';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="app-main">
    <div class="app-head">
      <div>
        <h1>Hello, <?= e(explode(' ', $me['full_name'])[0]) ?></h1>
        <p>Reputation <?= (int) round((float) $me['reputation_score']) ?>/100 · <?= (int) $me['total_lends'] ?> lends · <?= (int) $me['total_borrows'] ?> borrows</p>
      </div>
      <div class="flex gap-8">
        <a class="btn btn-outline" href="<?= APP_URL ?>/search.php">Browse items</a>
        <a class="btn btn-primary" href="<?= APP_URL ?>/list_item.php">+ List an item</a>
      </div>
    </div>

    <div class="kpi-grid">
      <div class="kpi">
        <span class="kpi-label">Active listings</span>
        <b class="kpi-value"><?= $activeListings ?></b>
        <span class="kpi-foot"><?= $pendingRequests ?> pending request<?= $pendingRequests === 1 ? '' : 's' ?></span>
      </div>
      <div class="kpi">
        <span class="kpi-label">Borrowing now</span>
        <b class="kpi-value"><?= $activeBorrows ?></b>
        <span class="kpi-foot">items in your hands</span>
      </div>
      <div class="kpi">
        <span class="kpi-label">Lending now</span>
        <b class="kpi-value"><?= $activeLends ?></b>
        <span class="kpi-foot">items with neighbours</span>
      </div>
      <div class="kpi">
        <span class="kpi-label">Completed</span>
        <b class="kpi-value"><?= $completed ?></b>
        <span class="kpi-foot">all-time transactions</span>
      </div>
    </div>

    <div class="two-col">
      <div>
        <div class="panel">
          <div class="panel-head"><h3>Upcoming returns</h3><a href="<?= APP_URL ?>/transactions.php">All transactions</a></div>
          <div class="panel-body flush">
            <?php if (!$upcoming): ?>
              <div class="empty" style="padding:36px 20px">
                <p>Nothing due right now.</p>
                <a class="btn btn-outline btn-sm" href="<?= APP_URL ?>/search.php">Find something to borrow</a>
              </div>
            <?php else: ?>
              <?php foreach ($upcoming as $u): ?>
                <?php
                $daysLeft = days_between(date('Y-m-d'), $u['due_date']);
                $late = $u['due_date'] < date('Y-m-d');
                ?>
                <a class="return-row" href="<?= APP_URL ?>/transaction.php?id=<?= (int) $u['transaction_id'] ?>">
                  <div class="return-date<?= $late ? ' late' : '' ?>">
                    <b><?= date('j', strtotime($u['due_date'])) ?></b>
                    <span><?= date('M', strtotime($u['due_date'])) ?></span>
                  </div>
                  <div style="flex:1">
                    <b class="small"><?= e($u['title']) ?></b>
                    <div class="small muted"><?= $u['direction'] === 'borrowing' ? 'Return to' : 'Due back from' ?> <?= e($u['other_name']) ?></div>
                  </div>
                  <span class="badge <?= $late ? 'badge-danger' : ($daysLeft <= 2 ? 'badge-warn' : 'badge-ok') ?>">
                    <?= $late ? 'Overdue' : ($daysLeft === 0 ? 'Today' : $daysLeft . 'd left') ?>
                  </span>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head"><h3>Borrow requests to review</h3><a href="<?= APP_URL ?>/borrow_requests.php">See all</a></div>
          <div class="panel-body flush">
            <?php if (!$requests): ?>
              <div class="empty" style="padding:32px 20px"><p>No pending requests.</p></div>
            <?php else: ?>
              <?php foreach ($requests as $r): ?>
                <div class="request-row">
                  <?php if ($r['avatar_path']): ?>
                    <img class="avatar" src="<?= UPLOAD_URL . '/' . e($r['avatar_path']) ?>" alt="">
                  <?php else: ?>
                    <span class="avatar" style="background:var(--green-700);color:#fff"><?= e(strtoupper(substr($r['borrower_name'], 0, 2))) ?></span>
                  <?php endif; ?>
                  <div style="flex:1;min-width:0">
                    <b class="small"><?= e($r['borrower_name']) ?></b>
                    <div class="small muted">wants “<?= e($r['title']) ?>” · <?= nice_date($r['start_date']) ?> → <?= nice_date($r['end_date']) ?></div>
                  </div>
                  <a class="btn btn-primary btn-sm" href="<?= APP_URL ?>/manage_request.php?id=<?= (int) $r['request_id'] ?>">Review</a>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head"><h3>Recent activity</h3></div>
          <div class="panel-body flush">
            <div class="table-wrap">
              <table class="data">
                <thead><tr><th>Reference</th><th>Item</th><th>Dates</th><th>Status</th></tr></thead>
                <tbody>
                  <?php foreach ($recentTx as $t): ?>
                    <tr>
                      <td><a href="<?= APP_URL ?>/transaction.php?id=<?= (int) $t['transaction_id'] ?>"><?= e($t['reference']) ?></a></td>
                      <td><?= e($t['title']) ?></td>
                      <td class="small muted"><?= nice_date($t['start_date']) ?> → <?= nice_date($t['due_date']) ?></td>
                      <td><span class="badge <?= status_badge_class($t['status']) ?>"><?= e(transaction_statuses()[$t['status']] ?? $t['status']) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div>
        <div class="panel">
          <div class="panel-head"><h3>Quick actions</h3></div>
          <div class="panel-body" style="display:grid;gap:8px">
            <a class="btn btn-primary btn-block" href="<?= APP_URL ?>/list_item.php">List an item</a>
            <a class="btn btn-outline btn-block" href="<?= APP_URL ?>/search.php">Browse the library</a>
            <a class="btn btn-outline btn-block" href="<?= APP_URL ?>/borrow_requests.php">Borrow requests<?= $pendingRequests ? ' (' . $pendingRequests . ')' : '' ?></a>
            <a class="btn btn-outline btn-block" href="<?= APP_URL ?>/messages.php">Messages</a>
            <a class="btn btn-ghost btn-block" href="<?= APP_URL ?>/edit_profile.php">Edit profile</a>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head"><h3>Your reputation</h3></div>
          <div class="panel-body">
            <div class="flex center gap-16">
              <div class="rep-ring" style="--pct:<?= (int) round((float) $me['reputation_score']) ?>">
                <span><?= (int) round((float) $me['reputation_score']) ?></span>
              </div>
              <div>
                <b class="small"><?= number_format((float) $me['avg_rating'], 1) ?> / 5</b>
                <div class="small muted">average rating</div>
                <div class="small muted" style="margin-top:4px"><?= (int) $me['total_lends'] ?> successful lends</div>
              </div>
            </div>
            <?php if ($badges): ?>
              <div class="pill-row" style="margin-top:14px">
                <?php foreach ($badges as $b): ?>
                  <span class="badge badge-amber" style="font-size:.62rem"><?= e($b['icon']) ?> <?= e($b['name']) ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head"><h3>Notifications</h3><a href="<?= APP_URL ?>/notifications.php">All</a></div>
          <div class="panel-body flush">
            <?php if (!$notifs): ?>
              <div class="empty" style="padding:28px 20px"><p class="small">Nothing new.</p></div>
            <?php else: ?>
              <?php foreach ($notifs as $n): ?>
                <div class="notif<?= (int) $n['is_read'] ? '' : ' unread' ?>" style="padding:12px 16px">
                  <div style="flex:1">
                    <b class="small"><?= e($n['title']) ?></b>
                    <div class="small muted" style="font-size:.75rem"><?= time_ago($n['created_at']) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
