<?php
require_once 'config.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $q = db()->prepare('SELECT * FROM users WHERE email=?'); $q->execute([trim($_POST['email'] ?? '')]);
    $u = $q->fetch();
    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id']; $_SESSION['name'] = $u['name'];
        header('Location: index.php'); exit;
    }
    $err = 'Email ya password galat hai.';
}
include 'header.php'; ?>
<div class="card narrow"><h2>Login</h2>
<?php if (isset($_GET['registered'])): ?><p class="ok">Registration ho gaya, ab login kijiye.</p><?php endif; ?>
<?php if ($err): ?><p class="error"><?= h($err) ?></p><?php endif; ?>
<form method="post">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <label>Email</label><input type="email" name="email" required>
  <label>Password</label><input type="password" name="password" required>
  <button>Login</button>
</form></div>
<?php include 'footer.php'; ?>
