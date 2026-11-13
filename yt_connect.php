<?php
require_once __DIR__ . '/yt_lib.php';
require_login();
if (!yt_configured()) {
    exit('google_config.php mein Client ID / Secret abhi nahi bhare hain. YOUTUBE_SETUP.md dekhiye.');
}
$back = $_GET['back'] ?? '';
if (!preg_match('/^job\.php\?id=\d+$/', $back)) $back = 'index.php';
$_SESSION['yt_back'] = $back;
$_SESSION['yt_state'] = bin2hex(random_bytes(16));

$params = http_build_query([
    'client_id' => GOOGLE_CLIENT_ID,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => 'https://www.googleapis.com/auth/youtube.upload',
    'access_type' => 'offline',
    'prompt' => 'consent',
    'state' => $_SESSION['yt_state'],
]);
header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
