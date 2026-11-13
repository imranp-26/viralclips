<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ViralClips AI</title>
<link rel="stylesheet" href="assets/style.css">
</head><body>
<nav>
  <a class="brand" href="index.php">🎬 ViralClips AI</a>
  <div>
  <?php if (!empty($_SESSION['uid'])): ?>
    <a href="index.php">New Video</a><a href="history.php">History</a><a href="instagram.php">Instagram</a>
    <a href="logout.php">Logout (<?= h($_SESSION['name']) ?>)</a>
  <?php else: ?>
    <a href="login.php">Login</a><a href="register.php">Register</a>
  <?php endif; ?>
  </div>
</nav>
<main>
