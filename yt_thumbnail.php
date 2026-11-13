<?php
require_once __DIR__ . '/yt_lib.php';
header('Content-Type: application/json; charset=utf-8');
function jout2(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (empty($_SESSION['uid'])) jout2(['error' => 'Login zaroori hai'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jout2(['error' => 'POST request chahiye'], 405);
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) jout2(['error' => 'Invalid request, page refresh kijiye'], 403);
if (!function_exists('curl_init')) jout2(['error' => 'PHP curl extension band hai'], 500);

try {
    $q = db()->prepare('SELECT c.* FROM clips c JOIN jobs j ON j.id=c.job_id WHERE c.id=? AND j.user_id=?');
    $q->execute([(int)($_POST['clip_id'] ?? 0), (int)$_SESSION['uid']]);
    $clip = $q->fetch();
    if (!$clip || empty($clip['youtube_video_id'])) jout2(['error' => 'Pehle clip YouTube par upload kijiye'], 404);
    $tp = yt_thumb_file($clip);
    if (!$tp) jout2(['error' => 'Is clip ka thumbnail bana hi nahi'], 404);
    $msg = yt_set_thumbnail(yt_access_token((int)$_SESSION['uid']), $clip['youtube_video_id'], $tp);
    jout2(['ok' => strpos($msg, 'OK:') === 0, 'message' => $msg]);
} catch (Exception $e) {
    jout2(['error' => $e->getMessage()], 500);
}
