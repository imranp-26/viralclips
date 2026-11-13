<?php
require_once __DIR__ . '/ig_lib.php';
header('Content-Type: application/json; charset=utf-8');
function iout(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (empty($_SESSION['uid'])) iout(['error' => 'Login zaroori hai'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') iout(['error' => 'POST request chahiye'], 405);
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) iout(['error' => 'Invalid request, page refresh kijiye'], 403);
if (!function_exists('curl_init')) iout(['error' => 'PHP curl extension band hai'], 500);
set_time_limit(0);
ignore_user_abort(true);

try {
    $uid = (int)$_SESSION['uid'];
    $acc = ig_account($uid);
    if (!$acc) iout(['error' => 'Pehle Instagram connect kijiye (Instagram settings).'], 400);
    $base = ig_public_base();
    if ($base === '') iout(['error' => 'IG_PUBLIC_BASE_URL set nahi hai. Instagram ko video ka public https link chahiye (INSTAGRAM_SETUP.md dekhiye).'], 400);

    $q = db()->prepare('SELECT c.* FROM clips c JOIN jobs j ON j.id=c.job_id WHERE c.id=? AND j.user_id=?');
    $q->execute([(int)($_POST['clip_id'] ?? 0), $uid]);
    $clip = $q->fetch();
    if (!$clip) iout(['error' => 'Clip nahi mila'], 404);
    if (!empty($clip['instagram_media_id'])) {
        iout(['ok' => true, 'already' => true, 'url' => $clip['instagram_url'] ?? '']);
    }
    $file = realpath(__DIR__ . '/' . $clip['file_path']);
    $storage = realpath(__DIR__ . '/storage');
    if (!$file || !$storage || strpos($file, $storage) !== 0 || !is_file($file)) iout(['error' => 'Clip ki video file nahi mili'], 404);

    // Token purana ho gaya ho to badhane ki koshish (fail ho to bhi aage badho)
    $token = $acc['access_token'];
    $last = strtotime($acc['refreshed_at'] ?: $acc['saved_at']);
    if ($last && time() - $last > 30 * 86400) {
        try {
            $token = ig_refresh($token);
            db()->prepare('UPDATE instagram_accounts SET access_token=?, refreshed_at=NOW() WHERE user_id=?')->execute([$token, $uid]);
        } catch (Exception $e) {}
    }

    $videoUrl = $base . '/' . $clip['file_path'];
    [$ok, $why] = ig_url_reachable($videoUrl);
    if (!$ok) iout(['error' => 'Public link check fail: ' . $why . ' (' . $videoUrl . ')'], 400);

    $cover = null;
    if (!empty($clip['thumb_path']) && is_file(__DIR__ . '/' . $clip['thumb_path'])) $cover = $base . '/' . $clip['thumb_path'];

    $res = ig_publish_reel($acc['ig_user_id'], $token, $videoUrl, ig_caption($clip), $cover, true);
    db()->prepare('UPDATE clips SET instagram_media_id=?, instagram_url=? WHERE id=?')
        ->execute([$res['media_id'], $res['permalink'], (int)$clip['id']]);
    iout(['ok' => true, 'url' => $res['permalink'], 'media_id' => $res['media_id']]);
} catch (Exception $e) {
    iout(['error' => $e->getMessage()], 500);
}
