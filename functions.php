<?php
/**
 * includes/functions.php — shared helpers
 * Sessions, auth, CSRF, validation, uploads, reputation, notifications.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------
function boot_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_name('BIBSESSID');
    session_start();

    // Rotate the session id periodically to limit fixation windows.
    if (!isset($_SESSION['created_at'])) {
        $_SESSION['created_at'] = time();
    } elseif (time() - $_SESSION['created_at'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['created_at'] = time();
    }
}

// ---------------------------------------------------------------------------
// Output escaping
// ---------------------------------------------------------------------------
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * UTF-8 aware character count. Falls back to a regex count when the
 * mbstring extension is disabled (some XAMPP builds ship without it).
 */
function str_len(?string $value): int
{
    $value = (string) $value;
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    return $chars === false ? strlen($value) : count($chars);
}

/**
 * Truncate a UTF-8 string. Falls back to a regex implementation when the
 * mbstring extension is disabled (some XAMPP builds ship without it).
 */
function str_shorten(?string $value, int $limit = 90, string $suffix = '…'): string
{
    $value = (string) $value;
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($value, 0, $limit, $suffix, 'UTF-8');
    }
    $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false || count($chars) <= $limit) {
        return $value;
    }
    return implode('', array_slice($chars, 0, max(0, $limit - 1))) . $suffix;
}

function money(float|int|string|null $amount): string
{
    return APP_CURRENCY . number_format((float) $amount, 0);
}

function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    $ts   = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('j M Y', $ts);
}

function nice_date(?string $date): string
{
    return $date ? date('j M Y', strtotime($date)) : '—';
}

function days_between(string $from, string $to): int
{
    $a = new DateTimeImmutable($from);
    $b = new DateTimeImmutable($to);
    return max(0, (int) $a->diff($b)->format('%a'));
}

// ---------------------------------------------------------------------------
// Redirect / flash
// ---------------------------------------------------------------------------
function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $out = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $out;
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Session expired. Please go back and try again.');
    }
}

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------
function current_user(): ?array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache ?: null;
    }
    if (empty($_SESSION['user_id'])) {
        $cache = false;
        return null;
    }
    $stmt = db()->prepare(
        'SELECT u.*, p.username, p.full_name, p.avatar_path, p.city, p.area,
                p.reputation_score, p.avg_rating, p.is_id_verified,
                p.total_lends, p.total_borrows
           FROM users u
           JOIN profiles p ON p.user_id = u.user_id
          WHERE u.user_id = ? AND u.status IN ("active","unverified")'
    );
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    $cache = $user ?: false;
    return $user ?: null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? '/';
        flash('error', 'Please log in to continue.');
        redirect(APP_URL . '/login.php');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('403 — administrator access required.');
    }
    return $u;
}

function login_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']    = $userId;
    $_SESSION['created_at'] = time();
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE user_id = ?')->execute([$userId]);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------
function post(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function get(string $key, string $default = ''): string
{
    return trim((string) ($_GET[$key] ?? $default));
}

function valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function valid_username(string $username): bool
{
    return (bool) preg_match('/^[a-z0-9_]{3,30}$/', $username);
}

function password_problem(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain at least one letter and one number.';
    }
    return null;
}

// ---------------------------------------------------------------------------
// File upload
// ---------------------------------------------------------------------------
function save_upload(array $file, string $subdir): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . $file['error'] . ').');
    }
    if ($file['size'] > MAX_PHOTO_BYTES) {
        throw new RuntimeException('File is larger than 4 MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ALLOWED_PHOTO_TYPES, true)) {
        throw new RuntimeException('Only JPG, PNG and WebP images are allowed.');
    }

    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'jpg',
    };

    $dir = UPLOAD_DIR . '/' . trim($subdir, '/');
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the upload directory.');
    }

    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not save the uploaded file.');
    }

    return trim($subdir, '/') . '/' . $name;
}

// ---------------------------------------------------------------------------
// Notifications
// ---------------------------------------------------------------------------
function notify(int $userId, string $type, string $title, string $body = '', string $link = ''): void
{
    $allowed = ['request','approval','message','reminder','review','listing','report','dispute','system'];
    if (!in_array($type, $allowed, true)) {
        $type = 'system';
    }
    db()->prepare(
        'INSERT INTO notifications (user_id, type, title, body, link_url) VALUES (?,?,?,?,?)'
    )->execute([$userId, $type, $title, $body, $link]);
}

function unread_notifications(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function unread_messages(int $userId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*)
           FROM messages m
           JOIN conversations c ON c.conversation_id = m.conversation_id
          WHERE m.is_read = 0 AND m.sender_id <> ?
            AND (c.user_a_id = ? OR c.user_b_id = ?)'
    );
    $stmt->execute([$userId, $userId, $userId]);
    return (int) $stmt->fetchColumn();
}

// ---------------------------------------------------------------------------
// Reputation — computed BIB score out of 100
//   50% average rating, 25% completion rate, 15% on-time rate, 10% tenure
// ---------------------------------------------------------------------------
function compute_reputation(int $userId): float
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT AVG(overall) AS avg_rating, COUNT(*) AS n FROM reviews WHERE reviewee_id = ? AND is_visible = 1');
    $stmt->execute([$userId]);
    $rev = $stmt->fetch();
    $avgRating = (float) ($rev['avg_rating'] ?? 0);
    $reviewCount = (int) ($rev['n'] ?? 0);

    $stmt = $pdo->prepare(
        "SELECT
            SUM(status = 'completed') AS completed,
            SUM(status IN ('completed','cancelled','disputed')) AS finished,
            SUM(is_overdue = 1) AS overdue,
            COUNT(*) AS total
         FROM transactions
         WHERE lender_id = ? OR borrower_id = ?"
    );
    $stmt->execute([$userId, $userId]);
    $tx = $stmt->fetch();

    $completed = (int) ($tx['completed'] ?? 0);
    $finished  = (int) ($tx['finished'] ?? 0);
    $overdue   = (int) ($tx['overdue'] ?? 0);
    $total     = (int) ($tx['total'] ?? 0);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM disputes WHERE (opened_by = ? OR against_id = ?) AND status IN ("open","reviewing","awaiting_evidence")');
    $stmt->execute([$userId, $userId]);
    $openDisputes = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT created_at FROM users WHERE user_id = ?');
    $stmt->execute([$userId]);
    $joined = $stmt->fetchColumn();
    $months = $joined ? max(0, (int) floor((time() - strtotime((string) $joined)) / 2592000)) : 0;

    if ($reviewCount === 0 && $total === 0) {
        return 0.0;
    }

    $ratingPart     = ($avgRating / 5) * 50;                                  // 0–50
    $completionPart = $finished > 0 ? ($completed / $finished) * 25 : 12.5;   // 0–25
    $onTimePart     = $total > 0 ? max(0, 1 - ($overdue / $total)) * 15 : 7.5; // 0–15
    $tenurePart     = min(1, $months / 24) * 10;                              // 0–10

    $score = $ratingPart + $completionPart + $onTimePart + $tenurePart;
    $score -= $openDisputes * 5;

    return round(max(0, min(REPUTATION_MAX, $score)), 2);
}

function refresh_reputation(int $userId): void
{
    $pdo = db();
    $score = compute_reputation($userId);

    $stmt = $pdo->prepare('SELECT AVG(overall) AS a, COUNT(*) AS n FROM reviews WHERE reviewee_id = ? AND is_visible = 1');
    $stmt->execute([$userId]);
    $rev = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE lender_id = ? AND status = 'completed'");
    $stmt->execute([$userId]);
    $lends = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE borrower_id = ? AND status = 'completed'");
    $stmt->execute([$userId]);
    $borrows = (int) $stmt->fetchColumn();

    $pdo->prepare(
        'UPDATE profiles SET reputation_score = ?, avg_rating = ?, total_lends = ?, total_borrows = ? WHERE user_id = ?'
    )->execute([$score, round((float) ($rev['a'] ?? 0), 2), $lends, $borrows, $userId]);
}

// ---------------------------------------------------------------------------
// Badges
// ---------------------------------------------------------------------------
function award_badge(int $userId, string $code): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT badge_id FROM badges WHERE code = ?');
    $stmt->execute([$code]);
    $badgeId = $stmt->fetchColumn();
    if (!$badgeId) {
        return;
    }
    $pdo->prepare('INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?,?)')
        ->execute([$userId, $badgeId]);
}

function evaluate_badges(int $userId): void
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE lender_id = ? AND status = 'completed'");
    $stmt->execute([$userId]);
    $lends = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE borrower_id = ? AND status = 'completed'");
    $stmt->execute([$userId]);
    $borrows = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT reputation_score FROM profiles WHERE user_id = ?');
    $stmt->execute([$userId]);
    $rep = (float) $stmt->fetchColumn();

    if ($lends >= 1)   award_badge($userId, 'first_lend');
    if ($lends >= 10)  award_badge($userId, 'ten_lends');
    if ($lends >= 100) award_badge($userId, 'hundred_lends');
    if ($lends >= 200) award_badge($userId, 'community_pillar');
    if ($rep >= 95)    award_badge($userId, 'super_lender');
    if ($borrows >= 25) award_badge($userId, 'great_borrower');
}

// ---------------------------------------------------------------------------
// Transactions
// ---------------------------------------------------------------------------
function next_reference(): string
{
    $year = date('Y');
    $stmt = db()->prepare('SELECT COUNT(*) FROM transactions WHERE reference LIKE ?');
    $stmt->execute(['BIB-' . $year . '-%']);
    $n = (int) $stmt->fetchColumn() + 1;
    return sprintf('BIB-%s-%04d', $year, $n);
}

function transaction_statuses(): array
{
    return [
        'requested'        => 'Requested',
        'accepted'         => 'Accepted',
        'pickup'           => 'Pickup',
        'borrowed'         => 'Borrowed',
        'return_requested' => 'Return requested',
        'returned'         => 'Returned',
        'completed'        => 'Completed',
        'cancelled'        => 'Cancelled',
        'disputed'         => 'Disputed',
    ];
}

function status_badge_class(string $status): string
{
    return match ($status) {
        'completed', 'returned'          => 'badge-ok',
        'borrowed', 'pickup', 'accepted' => 'badge-info',
        'return_requested'               => 'badge-warn',
        'disputed', 'cancelled'          => 'badge-danger',
        default                          => 'badge',
    };
}

/**
 * Advance a transaction, enforcing the allowed transitions and the
 * two-party confirmation rules for pickup and return.
 */
function advance_transaction(int $txId, string $action, int $actorId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE transaction_id = ?');
    $stmt->execute([$txId]);
    $tx = $stmt->fetch();
    if (!$tx) {
        return [false, 'Transaction not found.'];
    }
    if ((int) $tx['lender_id'] !== $actorId && (int) $tx['borrower_id'] !== $actorId) {
        return [false, 'You are not part of this transaction.'];
    }

    $isLender = (int) $tx['lender_id'] === $actorId;
    $status   = $tx['status'];

    switch ($action) {
        case 'confirm_pickup':
            if (!in_array($status, ['accepted', 'pickup'], true)) {
                return [false, 'Pickup can only be confirmed after the request is accepted.'];
            }
            $col = $isLender ? 'pickup_confirmed_by_lender' : 'pickup_confirmed_by_borrower';
            $pdo->prepare("UPDATE transactions SET $col = 1, status = 'pickup' WHERE transaction_id = ?")->execute([$txId]);

            $stmt = $pdo->prepare('SELECT pickup_confirmed_by_lender, pickup_confirmed_by_borrower FROM transactions WHERE transaction_id = ?');
            $stmt->execute([$txId]);
            $c = $stmt->fetch();
            if ((int) $c['pickup_confirmed_by_lender'] === 1 && (int) $c['pickup_confirmed_by_borrower'] === 1) {
                $pdo->prepare("UPDATE transactions SET status = 'borrowed' WHERE transaction_id = ?")->execute([$txId]);
                $pdo->prepare("UPDATE deposits SET status = 'held', held_at = NOW() WHERE transaction_id = ? AND status = 'pending'")->execute([$txId]);
                $pdo->prepare('UPDATE items SET borrow_count = borrow_count + 1 WHERE item_id = ?')->execute([$tx['item_id']]);
                notify((int) $tx['lender_id'], 'approval', 'Pickup confirmed', 'Both parties confirmed pickup. The borrow is now active.', 'transaction.php?id=' . $txId);
                notify((int) $tx['borrower_id'], 'approval', 'Pickup confirmed', 'Both parties confirmed pickup. The borrow is now active.', 'transaction.php?id=' . $txId);
                return [true, 'Pickup confirmed by both parties — the borrow is now active.'];
            }
            return [true, 'Pickup confirmed. Waiting for the other party.'];

        case 'request_return':
            if ($status !== 'borrowed') {
                return [false, 'A return can only be requested while the item is borrowed.'];
            }
            $pdo->prepare("UPDATE transactions SET status = 'return_requested' WHERE transaction_id = ?")->execute([$txId]);
            $pdo->prepare('INSERT INTO returns (transaction_id, requested_by, condition_note) VALUES (?,?,?)')
                ->execute([$txId, $actorId, post('condition_note')]);
            $other = $isLender ? (int) $tx['borrower_id'] : (int) $tx['lender_id'];
            notify($other, 'reminder', 'Return requested', 'The other party has requested the return. Please confirm once you have the item.', 'transaction.php?id=' . $txId);
            return [true, 'Return requested. Waiting for the other party to confirm.'];

        case 'confirm_return':
            if (!in_array($status, ['return_requested', 'borrowed'], true)) {
                return [false, 'There is no active return to confirm.'];
            }
            $col = $isLender ? 'return_confirmed_by_lender' : 'return_confirmed_by_borrower';
            $pdo->prepare("UPDATE transactions SET $col = 1 WHERE transaction_id = ?")->execute([$txId]);

            $stmt = $pdo->prepare('SELECT return_confirmed_by_lender, return_confirmed_by_borrower FROM transactions WHERE transaction_id = ?');
            $stmt->execute([$txId]);
            $c = $stmt->fetch();
            if ((int) $c['return_confirmed_by_lender'] === 1 && (int) $c['return_confirmed_by_borrower'] === 1) {
                $pdo->prepare("UPDATE transactions SET status = 'returned', returned_at = NOW() WHERE transaction_id = ?")->execute([$txId]);
                $pdo->prepare("UPDATE returns SET confirmed_by_lender = 1, confirmed_by_borrower = 1, confirmed_at = NOW(), status = 'confirmed' WHERE transaction_id = ?")->execute([$txId]);
                $pdo->prepare("UPDATE deposits SET status = 'returned', released_at = NOW() WHERE transaction_id = ? AND status = 'held'")->execute([$txId]);
                $pdo->prepare("UPDATE transactions SET status = 'completed' WHERE transaction_id = ?")->execute([$txId]);
                refresh_reputation((int) $tx['lender_id']);
                refresh_reputation((int) $tx['borrower_id']);
                evaluate_badges((int) $tx['lender_id']);
                evaluate_badges((int) $tx['borrower_id']);
                notify((int) $tx['lender_id'], 'review', 'Borrow completed', 'Leave a review to build community trust.', 'submit_review.php?tx=' . $txId);
                notify((int) $tx['borrower_id'], 'review', 'Borrow completed', 'Leave a review to build community trust.', 'submit_review.php?tx=' . $txId);
                return [true, 'Return confirmed by both parties — the transaction is complete and the deposit released.'];
            }
            return [true, 'Return confirmed. Waiting for the other party.'];

        case 'cancel':
            if (!in_array($status, ['requested', 'accepted'], true)) {
                return [false, 'This transaction can no longer be cancelled.'];
            }
            $pdo->prepare("UPDATE transactions SET status = 'cancelled' WHERE transaction_id = ?")->execute([$txId]);
            $pdo->prepare("UPDATE deposits SET status = 'returned', released_at = NOW() WHERE transaction_id = ?")->execute([$txId]);
            return [true, 'Transaction cancelled.'];
    }

    return [false, 'Unknown action.'];
}

/**
 * Flag overdue transactions and apply the daily penalty.
 * Call this from the dashboard / cron.
 */
function sweep_overdue(): void
{
    $pdo = db();
    $rows = $pdo->query(
        "SELECT transaction_id, due_date, penalty_amount
           FROM transactions
          WHERE status IN ('borrowed','return_requested') AND due_date < CURDATE()"
    )->fetchAll();

    foreach ($rows as $row) {
        $days = days_between($row['due_date'], date('Y-m-d'));
        $penalty = $days * LATE_PENALTY_PER_DAY;
        $pdo->prepare('UPDATE transactions SET is_overdue = 1, overdue_days = ?, penalty_amount = ? WHERE transaction_id = ?')
            ->execute([$days, $penalty, $row['transaction_id']]);
    }
}

// ---------------------------------------------------------------------------
// Conversations
// ---------------------------------------------------------------------------
function find_or_create_conversation(int $userA, int $userB, ?int $itemId = null, ?int $requestId = null, ?int $txId = null, string $subject = ''): int
{
    $pdo = db();
    [$a, $b] = $userA < $userB ? [$userA, $userB] : [$userB, $userA];

    $stmt = $pdo->prepare(
        'SELECT conversation_id FROM conversations
          WHERE user_a_id = ? AND user_b_id = ?
            AND (item_id <=> ?)'
    );
    $stmt->execute([$a, $b, $itemId]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    $pdo->prepare(
        'INSERT INTO conversations (item_id, request_id, transaction_id, user_a_id, user_b_id, subject, last_message_at)
         VALUES (?,?,?,?,?,?,NOW())'
    )->execute([$itemId, $requestId, $txId, $a, $b, $subject]);

    return (int) $pdo->lastInsertId();
}

// ---------------------------------------------------------------------------
// Pagination helper
// ---------------------------------------------------------------------------
function paginate(int $total, int $perPage, int $current): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $current = max(1, min($pages, $current));
    return [
        'total'   => $total,
        'pages'   => $pages,
        'current' => $current,
        'offset'  => ($current - 1) * $perPage,
        'per'     => $perPage,
    ];
}
