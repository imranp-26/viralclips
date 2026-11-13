<?php
require_once 'config.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? ''); $pw = $_POST['password'] ?? '';
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pw) < 6) {
        $err = 'Sahi naam, email aur kam se kam 6 character ka password daaliye.';
    } else {
        $q = db()->prepare('SELECT id FROM users WHERE email=?'); $q->execute([$email]);
        if ($q->fetch()) { $err = 'Ye email already registered hai.'; }
        else {
            db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')
                ->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT)]);
            header('Location: login.php?registered=1'); exit;
        }
    }
}
include 'header.php'; ?>
<div class="card narrow"><h2>Register</h2>
<?php if ($err): ?><p class="error"><?= h($err) ?></p><?php endif; ?>
<form method="post">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <label>Naam</label><input name="name" required>
  <label>Email</label><input type="email" name="email" required>
  <label>Password</label><input type="password" name="password" required minlength="6">
  <button>Register</button>
</form></div>
<?php include 'footer.php'; ?>
