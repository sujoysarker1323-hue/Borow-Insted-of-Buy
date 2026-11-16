<?php
/** includes/sidebar.php — dashboard navigation (expects $me) */
$current = basename($_SERVER['PHP_SELF']);
$links = [
    ['dashboard.php', 'Overview', 'M3 12l9-9 9 9M5 10v10h14V10'],
    ['my_listings.php', 'My listings', 'M3 9h18M9 21V9M3 3h18v18H3z'],
    ['borrow_requests.php', 'Borrow requests', 'M20 6L9 17l-5-5'],
    ['transactions.php', 'Transactions', 'M4 7h16M4 12h16M4 17h10'],
    ['messages.php', 'Messages', 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z'],
    ['wishlist.php', 'Wishlist', 'M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z'],
    ['notifications.php', 'Notifications', 'M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9'],
    ['profile.php?u=' . (int) $me['user_id'], 'My profile', 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z'],
];
?>
<aside class="app-side">
  <div class="side-user">
    <?php if ($me['avatar_path']): ?>
      <img class="avatar" src="<?= UPLOAD_URL . '/' . e($me['avatar_path']) ?>" alt="">
    <?php else: ?>
      <span class="avatar" style="background:var(--green-700);color:#fff"><?= e(strtoupper(substr($me['full_name'], 0, 2))) ?></span>
    <?php endif; ?>
    <div>
      <b><?= e($me['full_name']) ?></b>
      <span>@<?= e($me['username']) ?></span>
    </div>
  </div>
  <nav class="side-nav">
    <?php foreach ($links as [$href, $label, $path]): ?>
      <a href="<?= APP_URL ?>/<?= $href ?>"<?= str_starts_with($current, explode('?', $href)[0]) ? ' class="active"' : '' ?>>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $path ?>"/></svg>
        <?= e($label) ?>
      </a>
    <?php endforeach; ?>
    <?php if (is_admin()): ?>
      <a href="<?= APP_URL ?>/admin/index.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/></svg>
        Admin panel
      </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/logout.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
      Log out
    </a>
  </nav>
</aside>
