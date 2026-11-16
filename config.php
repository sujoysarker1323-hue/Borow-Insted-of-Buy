<?php
/**
 * BIB — Borrow Instead of Buy
 * includes/config.php — database credentials and application constants
 *
 * XAMPP defaults: host 127.0.0.1, user root, empty password.
 * Change these four values if your MySQL setup differs.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'bib_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------------------
// Application
// ---------------------------------------------------------------------------
define('APP_NAME', 'BIB');
define('APP_TAGLINE', 'Borrow what you need. Share what you have. Waste less.');
define('APP_URL', 'http://localhost/bib_db');          // no trailing slash
define('APP_TIMEZONE', 'Asia/Dhaka');
define('APP_CURRENCY', '৳');

// Uploads
define('UPLOAD_DIR', __DIR__ . '/../uploads');
define('UPLOAD_URL', APP_URL . '/uploads');
define('MAX_PHOTO_BYTES', 4 * 1024 * 1024);          // 4 MB
define('ALLOWED_PHOTO_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

// Business rules
define('REPUTATION_MAX', 100);
define('LATE_PENALTY_PER_DAY', 50);                  // ৳ per day overdue
define('MAX_ITEMS_PER_PAGE', 12);

// Email verification: set to false to skip the mail step in local demos
define('REQUIRE_EMAIL_VERIFICATION', true);
// When true, the verification link is shown on screen instead of being emailed
// (useful on XAMPP where mail() is not configured).
define('SHOW_VERIFICATION_LINK', true);

// ---------------------------------------------------------------------------
// Error reporting — turn display_errors off in production
// ---------------------------------------------------------------------------
define('APP_DEBUG', true);
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

date_default_timezone_set(APP_TIMEZONE);

// ---------------------------------------------------------------------------
// PDO connection (singleton)
// ---------------------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        $msg = APP_DEBUG ? htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') : 'Database connection failed.';
        exit('<h1>BIB — database error</h1><p>' . $msg . '</p>'
            . '<p>Check the credentials in <code>includes/config.php</code> and make sure '
            . '<code>sql/bib_schema.sql</code> has been imported.</p>');
    }

    return $pdo;
}
