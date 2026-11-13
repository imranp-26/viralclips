<?php
require_once __DIR__ . '/yt_lib.php';
require_login();
$back = $_SESSION['yt_back'] ?? 'index.php';
$sep = strpos($back, '?') !== false ? '&' : '?';

if (isset($_GET['error'])) {
    header('Location: ' . $back . $sep . 'yt=denied'); exit;
}
if (!hash_equals($_SESSION['yt_state'] ?? '', $_GET['state'] ?? '') || empty($_GET['code'])) {
    exit('Invalid request (state mismatch). Dobara Connect YouTube dabaiye.');
}
unset($_SESSION['yt_state']);

try {
    [$code, $d] = yt_post_form('https://oauth2.googleapis.com/token', [
        'code' => $_GET['code'],
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'grant_type' => 'authorization_code',
    ]);
} catch (Exception $e) {
    exit(h($e->getMessage()));
}

if ($code !== 200 || empty($d['refresh_token'])) {
    exit('Token nahi mila: ' . h($d['error_description'] ?? $d['error'] ?? "HTTP $code")
        . '<br>Agar refresh_token missing hai to https://myaccount.google.com/permissions se app ka access hata kar dobara Connect kijiye.');
}

db()->prepare('REPLACE INTO youtube_tokens(user_id, refresh_token) VALUES(?, ?)')
    ->execute([$_SESSION['uid'], $d['refresh_token']]);
header('Location: ' . $back . $sep . 'yt=ok');
