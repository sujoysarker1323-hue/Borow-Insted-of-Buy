<?php
/** includes/footer.php — closes <main> and renders the site footer */
?>
</main>

<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">
      <div class="footer-brand">
        <a class="brand" href="<?= APP_URL ?>/index.php">
          <span class="brand-mark">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H11v14H6.5A2.5 2.5 0 0 1 4 16.5z"/><path d="M20 7.5A2.5 2.5 0 0 0 17.5 5H13v14h4.5A2.5 2.5 0 0 0 20 16.5z"/></svg>
          </span>
          <span>BIB<small>Borrow Instead of Buy</small></span>
        </a>
        <p><?= e(APP_TAGLINE) ?> A neighbourhood library of things, built for people who would rather lend than landfill.</p>
      </div>
      <div>
        <h5>Explore</h5>
        <ul>
          <li><a href="<?= APP_URL ?>/search.php">Browse items</a></li>
          <li><a href="<?= APP_URL ?>/search.php">Categories</a></li>
          <li><a href="<?= APP_URL ?>/community.php">Community</a></li>
          <li><a href="<?= APP_URL ?>/impact.php">Sustainability</a></li>
        </ul>
      </div>
      <div>
        <h5>Account</h5>
        <ul>
          <li><a href="<?= APP_URL ?>/login.php">Log in</a></li>
          <li><a href="<?= APP_URL ?>/register.php">Create account</a></li>
          <li><a href="<?= APP_URL ?>/dashboard.php">My dashboard</a></li>
          <li><a href="<?= APP_URL ?>/my_listings.php">My listings</a></li>
          <li><a href="<?= APP_URL ?>/wishlist.php">Wishlist</a></li>
        </ul>
      </div>
      <div>
        <h5>Project</h5>
        <ul>
          <li><a href="<?= APP_URL ?>/admin/index.php">Admin panel</a></li>
          <li><a href="<?= APP_URL ?>/index.php#how">How it works</a></li>
          <li><a href="<?= APP_URL ?>/README.md">README / setup</a></li>
          <li><a href="<?= APP_URL ?>/report.php">Report an issue</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span>&copy; <?= date('Y') ?> BIB — Borrow Instead of Buy. Software Project Design &amp; Development Lab.</span>
      <span>Built with HTML, CSS, JavaScript, PHP &amp; MySQL.</span>
    </div>
  </div>
</footer>

<div class="toast-wrap"></div>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
