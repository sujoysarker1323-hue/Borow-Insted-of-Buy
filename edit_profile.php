<?php
/**
 * edit_profile.php — update photo, name, contact details, location and bio
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();

$errors = [];
$stmt = db()->prepare('SELECT * FROM profiles WHERE user_id = ?');
$stmt->execute([$me['user_id']]);
$profile = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $fullName = post('full_name');
    $username = strtolower(post('username'));
    $phone    = post('phone');
    $city     = post('city');
    $area     = post('area');
    $bio      = post('bio');

    if (str_len($fullName) < 3) {
        $errors[] = 'Please enter your full name.';
    }
    if (!valid_username($username)) {
        $errors[] = 'Username must be 3–30 characters: lowercase letters, numbers and underscores.';
    }
    if (str_len($bio) > 1000) {
        $errors[] = 'Bio must be under 1000 characters.';
    }

    if (!$errors) {
        $stmt = db()->prepare('SELECT user_id FROM profiles WHERE username = ? AND user_id <> ?');
        $stmt->execute([$username, $me['user_id']]);
        if ($stmt->fetchColumn()) {
            $errors[] = 'That username is already taken.';
        }
    }

    $avatar = null;
    if (!$errors && !empty($_FILES['avatar']['name'])) {
        try {
            $avatar = save_upload($_FILES['avatar'], 'avatars');
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }

    if (!$errors) {
        $sql = 'UPDATE profiles SET full_name = ?, username = ?, phone = ?, city = ?, area = ?, bio = ?';
        $args = [$fullName, $username, $phone, $city, $area, $bio];
        if ($avatar) {
            $sql .= ', avatar_path = ?';
            $args[] = $avatar;
        }
        $sql .= ' WHERE user_id = ?';
        $args[] = $me['user_id'];
        db()->prepare($sql)->execute($args);

        flash('success', 'Profile updated.');
        redirect(APP_URL . '/profile.php?u=' . (int) $me['user_id']);
    }

    $profile = array_merge($profile, [
        'full_name' => $fullName, 'username' => $username, 'phone' => $phone,
        'city' => $city, 'area' => $area, 'bio' => $bio,
    ]);
}

$pageTitle = 'Edit profile — BIB';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="container narrow" style="padding:36px 24px 80px">
  <div class="breadcrumb"><a href="<?= APP_URL ?>/dashboard.php">Dashboard</a> <span>/</span> <span>Edit profile</span></div>
  <h1 style="font-size:1.9rem">Edit profile</h1>
  <p class="muted">Your profile is what lenders and borrowers see before they trust you with their things.</p>

  <?php if ($errors): ?>
    <div class="panel" style="border-color:var(--danger)">
      <div class="panel-body" style="padding:14px 16px">
        <?php foreach ($errors as $err): ?><div class="small" style="color:var(--danger)">• <?= e($err) ?></div><?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="panel">
    <?= csrf_field() ?>
    <div class="panel-body">
      <div class="flex center gap-16" style="margin-bottom:22px">
        <?php if ($profile['avatar_path']): ?>
          <img class="avatar avatar-xl" src="<?= UPLOAD_URL . '/' . e($profile['avatar_path']) ?>" alt="">
        <?php else: ?>
          <span class="avatar avatar-xl" style="background:var(--green-700);color:#fff"><?= e(strtoupper(substr($profile['full_name'], 0, 2))) ?></span>
        <?php endif; ?>
        <div>
          <label class="btn btn-outline btn-sm" for="avatar" style="cursor:pointer">Change photo</label>
          <input id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp" style="display:none">
          <div class="small muted" style="margin-top:6px">JPG, PNG or WebP · max 4 MB</div>
        </div>
      </div>

      <div class="grid grid-2" style="gap:14px">
        <div class="field"><label for="full_name">Full name</label><input class="input" id="full_name" name="full_name" value="<?= e($profile['full_name']) ?>" required></div>
        <div class="field"><label for="username">Username</label><input class="input" id="username" name="username" value="<?= e($profile['username']) ?>" required></div>
      </div>
      <div class="field"><label>Email address</label><input class="input" value="<?= e($me['email']) ?>" disabled><div class="small muted" style="margin-top:4px">Contact an admin to change your email.</div></div>
      <div class="field"><label for="phone">Phone</label><input class="input" id="phone" name="phone" value="<?= e($profile['phone']) ?>"></div>
      <div class="grid grid-2" style="gap:14px">
        <div class="field"><label for="city">City</label><input class="input" id="city" name="city" value="<?= e($profile['city']) ?>"></div>
        <div class="field"><label for="area">Area</label><input class="input" id="area" name="area" value="<?= e($profile['area']) ?>"></div>
      </div>
      <div class="field"><label for="bio">Bio</label><textarea class="textarea" id="bio" name="bio" maxlength="1000"><?= e($profile['bio']) ?></textarea></div>

      <div class="flex gap-8">
        <button class="btn btn-primary" type="submit">Save changes</button>
        <a class="btn btn-ghost" href="<?= APP_URL ?>/profile.php?u=<?= (int) $me['user_id'] ?>">Cancel</a>
      </div>
    </div>
  </form>

  <div class="panel">
    <div class="panel-head"><h3>Account security</h3></div>
    <div class="panel-body" style="display:grid;gap:8px">
      <a class="btn btn-outline btn-block" href="<?= APP_URL ?>/change_password.php">Change password</a>
      <a class="btn btn-ghost btn-block" href="<?= APP_URL ?>/delete_account.php" style="color:var(--danger)">Delete my account</a>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
