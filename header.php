<?php
/**
 * includes/header.php — shared page shell
 * Expects (optional): $pageTitle, $pageDescription, $activeNav, $bodyClass
 */
require_once __DIR__ . '/functions.php';
boot_session();

$me        = current_user();
$pageTitle = $pageTitle ?? APP_NAME . ' — ' . APP_TAGLINE;
$activeNav = $activeNav ?? '';
$navItems  = [
    'home'    => ['index.php', 'Home'],
    'browse'  => ['search.php', 'Browse'],
    'community' => ['community.php', 'Community'],
    'impact'  => ['impact.php', 'Impact'],
];
$notifCount = $me ? unread_notifications((int) $me['user_id']) : 0;
$msgCount   = $me ? unread_messages((int) $me['user_id']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($pageDescription ?? APP_TAGLINE) ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=20261111-2">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=20261111-2">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect width='24' height='24' rx='6' fill='%2316543d'/%3E%3Cpath d='M6 8.5A1.5 1.5 0 0 1 7.5 7H11v10H7.5A1.5 1.5 0 0 1 6 15.5zM18 8.5A1.5 1.5 0 0 0 16.5 7H13v10h3.5A1.5 1.5 0 0 0 18 15.5z' fill='none' stroke='%23fff' stroke-width='1.6'/%3E%3C/svg%3E">
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="<?= APP_URL ?>/index.php">
      <span class="brand-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H11v14H6.5A2.5 2.5 0 0 1 4 16.5z"/><path d="M20 7.5A2.5 2.5 0 0 0 17.5 5H13v14h4.5A2.5 2.5 0 0 0 20 16.5z"/></svg>
      </span>
      <span>BIB<small>Borrow Instead of Buy</small></span>
    </a>

    <nav class="nav" aria-label="Main">
      <?php foreach ($navItems as $key => [$href, $label]): ?>
        <a href="<?= APP_URL ?>/<?= $href ?>"<?= $activeNav === $key ? ' class="active"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if ($me): ?>
        <a href="<?= APP_URL ?>/dashboard.php"<?= $activeNav === 'dashboard' ? ' class="active"' : '' ?>>Dashboard</a>
      <?php endif; ?>
      <?php if (is_admin()): ?>
        <a href="<?= APP_URL ?>/admin/index.php"<?= $activeNav === 'admin' ? ' class="active"' : '' ?>>Admin</a>
      <?php endif; ?>
    </nav>

    <div class="header-actions">
      <button class="icon-btn theme-toggle" data-theme-toggle aria-label="Toggle theme">
        <svg class="sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
        <svg class="moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
      </button>

      <?php if ($me): ?>
        <a class="icon-btn" href="<?= APP_URL ?>/notifications.php" aria-label="Notifications" style="position:relative">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
          <?php if ($notifCount): ?><span style="position:absolute;top:4px;right:4px;min-width:16px;height:16px;border-radius:99px;background:var(--amber-500);color:#2a1c05;font-size:.6rem;font-weight:700;display:grid;place-items:center;padding:0 3px"><?= $notifCount > 9 ? '9+' : $notifCount ?></span><?php endif; ?>
        </a>
        <a class="icon-btn" href="<?= APP_URL ?>/messages.php" aria-label="Messages" style="position:relative">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          <?php if ($msgCount): ?><span style="position:absolute;top:4px;right:4px;min-width:16px;height:16px;border-radius:99px;background:var(--green-600);color:#fff;font-size:.6rem;font-weight:700;display:grid;place-items:center;padding:0 3px"><?= $msgCount > 9 ? '9+' : $msgCount ?></span><?php endif; ?>
        </a>
        <a class="btn btn-outline" href="<?= APP_URL ?>/profile.php?u=<?= (int) $me['user_id'] ?>"><?= e($me['full_name']) ?></a>
        <a class="btn btn-primary" href="<?= APP_URL ?>/list_item.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
          List an item
        </a>
      <?php else: ?>
        <a class="btn btn-outline" href="<?= APP_URL ?>/login.php">Log in</a>
        <a class="btn btn-primary" href="<?= APP_URL ?>/register.php">Join BIB</a>
      <?php endif; ?>

      <button class="icon-btn menu-toggle" data-menu-toggle aria-label="Menu" aria-expanded="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
      </button>
    </div>
  </div>
</header>

<div class="mobile-nav">
  <?php foreach ($navItems as $key => [$href, $label]): ?>
    <a href="<?= APP_URL ?>/<?= $href ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
  <?php if ($me): ?>
    <a href="<?= APP_URL ?>/dashboard.php">Dashboard</a>
    <a href="<?= APP_URL ?>/my_listings.php">My listings</a>
    <a href="<?= APP_URL ?>/messages.php">Messages</a>
    <a href="<?= APP_URL ?>/notifications.php">Notifications</a>
    <a href="<?= APP_URL ?>/logout.php">Log out</a>
  <?php else: ?>
    <a href="<?= APP_URL ?>/login.php">Log in</a>
    <a class="btn btn-primary btn-block" href="<?= APP_URL ?>/register.php">Join BIB</a>
  <?php endif; ?>
</div>

<?php $flashes = take_flashes(); if ($flashes): ?>
  <div class="container" style="padding-top:16px">
    <?php foreach ($flashes as $f): ?>
      <div class="panel" style="margin-bottom:10px;border-color:<?= $f['type'] === 'error' ? 'var(--danger)' : 'var(--green-600)' ?>">
        <div class="panel-body" style="padding:12px 16px;font-size:.9rem;color:<?= $f['type'] === 'error' ? 'var(--danger)' : 'var(--green-700)' ?>">
          <?= e($f['message']) ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<main>
