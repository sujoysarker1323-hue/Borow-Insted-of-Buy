<?php
/**
 * community.php — discussions, posts, comments and likes
 * Tables: community_posts, community_comments (see sql/bib_schema.sql)
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $me = require_login();
    verify_csrf();
    $action = post('action');

    if ($action === 'post') {
        $title = post('title');
        $body  = post('body');
        $cat   = post('category', 'general');
        if (!in_array($cat, ['general', 'repair', 'sustainability', 'request', 'tip'], true)) {
            $cat = 'general';
        }
        if (str_len($title) < 5 || str_len($body) < 10) {
            flash('error', 'Give your post a title and a bit more detail.');
        } else {
            db()->prepare('INSERT INTO community_posts (author_id, category, title, body) VALUES (?,?,?,?)')
                ->execute([$me['user_id'], $cat, $title, $body]);
            flash('success', 'Posted to the community.');
        }
    }

    if ($action === 'comment') {
        $postId = (int) post('post_id');
        $body   = post('body');
        if ($body !== '') {
            db()->prepare('INSERT INTO community_comments (post_id, author_id, body) VALUES (?,?,?)')
                ->execute([$postId, $me['user_id'], $body]);
            db()->prepare('UPDATE community_posts SET reply_count = reply_count + 1 WHERE post_id = ?')->execute([$postId]);

            $stmt = db()->prepare('SELECT author_id, title FROM community_posts WHERE post_id = ?');
            $stmt->execute([$postId]);
            $p = $stmt->fetch();
            if ($p && (int) $p['author_id'] !== (int) $me['user_id']) {
                notify((int) $p['author_id'], 'system', 'New reply on your post',
                    $me['full_name'] . ' replied to “' . $p['title'] . '”', 'community.php#post-' . $postId);
            }
        }
    }

    if ($action === 'like') {
        $postId = (int) post('post_id');
        db()->prepare('UPDATE community_posts SET like_count = like_count + 1 WHERE post_id = ?')->execute([$postId]);
    }

    redirect(APP_URL . '/community.php' . (post('anchor') ? '#' . post('anchor') : ''));
}

$cat = get('cat', 'all');
$where = $cat === 'all' ? "cp.status = 'published'" : "cp.status = 'published' AND cp.category = ?";
$params = $cat === 'all' ? [] : [$cat];

$stmt = db()->prepare(
    "SELECT cp.*, p.full_name, p.username, p.avatar_path, p.reputation_score
       FROM community_posts cp
       JOIN profiles p ON p.user_id = cp.author_id
      WHERE $where
      ORDER BY cp.is_pinned DESC, cp.created_at DESC
      LIMIT 30"
);
$stmt->execute($params);
$posts = $stmt->fetchAll();

$commentStmt = db()->prepare(
    'SELECT cc.*, p.full_name, p.avatar_path FROM community_comments cc
       JOIN profiles p ON p.user_id = cc.author_id
      WHERE cc.post_id = ? ORDER BY cc.created_at ASC LIMIT 5'
);

$pageTitle = 'Community — BIB';
$activeNav = 'community';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div class="container">
    <div class="breadcrumb"><a href="<?= APP_URL ?>/index.php">Home</a> <span>/</span> <span>Community</span></div>
    <h1 style="font-size:2rem;margin-bottom:6px">The BIB community</h1>
    <p style="margin:0">Repair tips, borrowing etiquette, and the occasional very good story about a ladder.</p>
  </div>
</div>

<div class="container" style="padding:28px 24px 80px">
  <div class="two-col">
    <div>
      <div class="pill-row" style="margin-bottom:18px">
        <?php foreach (['all' => 'All posts', 'general' => 'General', 'repair' => 'Repair & care', 'tip' => 'Tips', 'sustainability' => 'Sustainability', 'request' => 'Requests'] as $k => $l): ?>
          <a class="chip<?= $cat === $k ? ' active' : '' ?>" href="<?= APP_URL ?>/community.php?cat=<?= $k ?>"><?= $l ?></a>
        <?php endforeach; ?>
      </div>

      <?php if (!$posts): ?>
        <div class="panel"><div class="empty"><p>No posts in this category yet.</p></div></div>
      <?php endif; ?>

      <?php foreach ($posts as $post): ?>
        <article class="panel post" id="post-<?= (int) $post['post_id'] ?>">
          <div class="panel-body">
            <div class="flex center gap-12" style="margin-bottom:12px">
              <?php if ($post['avatar_path']): ?>
                <img class="avatar" src="<?= UPLOAD_URL . '/' . e($post['avatar_path']) ?>" alt="">
              <?php else: ?>
                <span class="avatar" style="background:var(--green-700);color:#fff"><?= e(strtoupper(substr($post['full_name'], 0, 2))) ?></span>
              <?php endif; ?>
              <div>
                <b class="small"><?= e($post['full_name']) ?></b>
                <div class="small muted">@<?= e($post['username']) ?> · <?= time_ago($post['created_at']) ?></div>
              </div>
              <span class="badge badge" style="margin-left:auto"><?= ucfirst($post['category']) ?></span>
            </div>

            <h3 style="font-size:1.15rem;margin-bottom:8px"><?= e($post['title']) ?></h3>
            <p class="small" style="white-space:pre-line;color:var(--ink-2)"><?= e($post['body']) ?></p>

            <div class="flex gap-12 center" style="margin-top:14px;padding-top:12px;border-top:1px solid var(--line)">
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="like">
                <input type="hidden" name="post_id" value="<?= (int) $post['post_id'] ?>">
                <input type="hidden" name="anchor" value="post-<?= (int) $post['post_id'] ?>">
                <button class="mini-btn" type="submit">♥ <?= (int) $post['like_count'] ?></button>
              </form>
              <span class="small muted"><?= (int) $post['reply_count'] ?> replies</span>
            </div>

            <?php
            $commentStmt->execute([(int) $post['post_id']]);
            $comments = $commentStmt->fetchAll();
            ?>
            <?php if ($comments): ?>
              <div class="comments">
                <?php foreach ($comments as $c): ?>
                  <div class="comment">
                    <b class="small"><?= e($c['full_name']) ?></b>
                    <span class="small muted"><?= time_ago($c['created_at']) ?></span>
                    <p class="small" style="margin:2px 0 0"><?= nl2br(e($c['body'])) ?></p>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if ($me): ?>
              <form method="post" class="flex gap-8" style="margin-top:10px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="comment">
                <input type="hidden" name="post_id" value="<?= (int) $post['post_id'] ?>">
                <input type="hidden" name="anchor" value="post-<?= (int) $post['post_id'] ?>">
                <input class="input" name="body" placeholder="Add a reply…" required>
                <button class="btn btn-outline btn-sm" type="submit">Reply</button>
              </form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <aside>
      <?php if ($me): ?>
        <div class="panel">
          <div class="panel-head"><h3>Start a discussion</h3></div>
          <div class="panel-body">
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="post">
              <div class="field"><label for="title">Title</label><input class="input" id="title" name="title" required></div>
              <div class="field">
                <label for="category">Category</label>
                <select class="select" id="category" name="category">
                  <option value="general">General</option>
                  <option value="repair">Repair &amp; care</option>
                  <option value="tip">Tips</option>
                  <option value="sustainability">Sustainability</option>
                  <option value="request">Requests</option>
                </select>
              </div>
              <div class="field"><label for="body">Your post</label><textarea class="textarea" id="body" name="body" style="min-height:100px" required></textarea></div>
              <button class="btn btn-primary btn-block" type="submit">Post</button>
            </form>
          </div>
        </div>
      <?php else: ?>
        <div class="panel">
          <div class="panel-body" style="text-align:center">
            <p class="small">Join BIB to post and reply in the community.</p>
            <a class="btn btn-primary btn-block" href="<?= APP_URL ?>/register.php">Create an account</a>
          </div>
        </div>
      <?php endif; ?>

      <div class="panel">
        <div class="panel-head"><h3>Community guidelines</h3></div>
        <div class="panel-body">
          <ul class="small" style="margin:0;padding-left:18px;color:var(--ink-2);line-height:1.8">
            <li>Be kind — everyone here is lending something they own.</li>
            <li>No selling. BIB is for borrowing, not commerce.</li>
            <li>Report problems instead of escalating them.</li>
            <li>Never share someone's address publicly.</li>
          </ul>
        </div>
      </div>
    </aside>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
