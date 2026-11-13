<?php
require_once __DIR__ . '/yt_lib.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrf_check();

$q = db()->prepare('SELECT refresh_token FROM youtube_tokens WHERE user_id=?');
$q->execute([$_SESSION['uid']]);
$rt = $q->fetchColumn();
if ($rt) {
    try { yt_post_form('https://oauth2.googleapis.com/revoke', ['token' => $rt]); } catch (Exception $e) {}
    db()->prepare('DELETE FROM youtube_tokens WHERE user_id=?')->execute([$_SESSION['uid']]);
}
$back = $_POST['back'] ?? '';
if (!preg_match('/^job\.php\?id=\d+$/', $back)) $back = 'index.php';
header('Location: ' . $back);
