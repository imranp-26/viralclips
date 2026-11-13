<?php
require_once __DIR__ . '/yt_lib.php';
header('Content-Type: application/json; charset=utf-8');

function jout(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function clean_text(string $s): string { return trim(str_replace(['<', '>'], '', $s)); } // YouTube < > allow nahi karta

if (empty($_SESSION['uid'])) jout(['error' => 'Login zaroori hai'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jout(['error' => 'POST request chahiye'], 405);
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) jout(['error' => 'Invalid request, page refresh kijiye'], 403);
if (!function_exists('curl_init')) jout(['error' => 'PHP ka curl extension band hai (php.ini mein extension=curl on kijiye)'], 500);
if (!yt_configured()) jout(['error' => 'google_config.php mein Client ID/Secret nahi bhare hain'], 400);

set_time_limit(0);
ignore_user_abort(true);

try {
    $uid = (int)$_SESSION['uid'];
    $clipId = (int)($_POST['clip_id'] ?? 0);
    $privacy = $_POST['privacy'] ?? 'private';
    if (!in_array($privacy, ['private', 'unlisted', 'public', 'schedule'], true)) $privacy = 'private';

    $q = db()->prepare('SELECT c.* FROM clips c JOIN jobs j ON j.id=c.job_id WHERE c.id=? AND j.user_id=?');
    $q->execute([$clipId, $uid]);
    $clip = $q->fetch();
    if (!$clip) jout(['error' => 'Clip nahi mila'], 404);

    // Dobara upload se bachao (quota bachta hai)
    if (!empty($clip['youtube_video_id'])) {
        jout(['ok' => true, 'video_id' => $clip['youtube_video_id'], 'url' => 'https://youtu.be/' . $clip['youtube_video_id'], 'privacy' => 'pehle se upload hai', 'already' => true]);
    }

    $path = realpath(__DIR__ . '/' . $clip['file_path']);
    $storage = realpath(__DIR__ . '/storage');
    if (!$path || !$storage || strpos($path, $storage) !== 0 || !is_file($path)) {
        jout(['error' => 'Clip ki video file nahi mili'], 404);
    }
    $size = filesize($path);

    // ----- Title / description / tags -----
    $lines = preg_split('/\R/u', trim((string)$clip['caption_youtube']));
    $title = clean_text((string)array_shift($lines));
    if ($title === '') $title = clean_text((string)$clip['title']);
    if ($title === '') $title = 'Viral Clip';
    $title = mb_substr($title, 0, 95);

    $tagsStr = (string)$clip['hashtags'];
    $desc = clean_text(trim(implode("\n", $lines)) . "\n\n" . $tagsStr);
    if (stripos($desc, '#shorts') === false) $desc .= "\n#Shorts";
    $desc = mb_substr($desc, 0, 4900);

    preg_match_all('/#([\p{L}\p{N}_]+)/u', $tagsStr, $m);
    $tags = array_slice(array_values(array_unique($m[1])), 0, 15);

    $snippet = ['title' => $title, 'description' => $desc, 'categoryId' => '22'];
    if ($tags) $snippet['tags'] = $tags;
    $status = ['privacyStatus' => $privacy, 'selfDeclaredMadeForKids' => false];
    $scheduledFor = '';
    if ($privacy === 'schedule') {
        $ts = strtotime((string)($_POST['publish_at'] ?? ''));
        if (!$ts || $ts < time() + 600) jout(['error' => 'Schedule ka time kam se kam 10 minute aage ka hona chahiye'], 400);
        if ($ts > time() + 86400 * 365) jout(['error' => 'Schedule 1 saal ke andar ka hona chahiye'], 400);
        $status['privacyStatus'] = 'private';          // YouTube scheduled video pehle private hoti hai
        $status['publishAt'] = gmdate('Y-m-d\TH:i:s', $ts) . '.000Z';
        $scheduledFor = gmdate('Y-m-d H:i', $ts) . ' UTC';
    }
    $meta = json_encode(['snippet' => $snippet, 'status' => $status], JSON_UNESCAPED_UNICODE);

    $token = yt_access_token($uid);

    // ----- Step 1: resumable upload session shuru karo -----
    $location = '';
    $ch = curl_init('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $meta,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Length: ' . $size,
            'X-Upload-Content-Type: video/mp4',
        ],
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$location) {
            if (stripos($h, 'location:') === 0) $location = trim(substr($h, 9));
            return strlen($h);
        },
    ]);
    yt_apply_ssl($ch);
    $body = curl_exec($ch);
    if ($body === false) { $e = curl_error($ch); curl_close($ch); throw new Exception('Network error: ' . $e); }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || $location === '') {
        throw new Exception(yt_error_message($code, json_decode($body, true)));
    }

    // ----- Step 2: video file upload karo -----
    $fh = fopen($path, 'rb');
    $ch = curl_init($location);
    curl_setopt_array($ch, [
        CURLOPT_PUT => true,
        CURLOPT_INFILE => $fh,
        CURLOPT_INFILESIZE => $size,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_HTTPHEADER => ['Content-Type: video/mp4', 'Expect:'],
    ]);
    yt_apply_ssl($ch);
    $body = curl_exec($ch);
    if ($body === false) { $e = curl_error($ch); curl_close($ch); fclose($fh); throw new Exception('Upload network error: ' . $e); }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    $res = json_decode($body, true);
    if (!in_array($code, [200, 201], true) || empty($res['id'])) {
        throw new Exception(yt_error_message($code, $res));
    }

    db()->prepare('UPDATE clips SET youtube_video_id=? WHERE id=?')->execute([$res['id'], $clipId]);
    $final = $scheduledFor !== '' ? 'scheduled: ' . $scheduledFor : ($res['status']['privacyStatus'] ?? $privacy);

    // Thumbnail laga do (best effort: fail ho to bhi upload safe rehta hai)
    $thumbMsg = '';
    $tp = yt_thumb_file($clip);
    if ($tp) $thumbMsg = yt_set_thumbnail($token, $res['id'], $tp);
    jout(['ok' => true, 'video_id' => $res['id'], 'url' => 'https://youtu.be/' . $res['id'], 'privacy' => $final, 'thumbnail' => $thumbMsg]);

} catch (Exception $e) {
    jout(['error' => $e->getMessage()], 500);
}

function yt_error_message(int $code, $d): string {
    $reason = $d['error']['errors'][0]['reason'] ?? '';
    $msg = $d['error']['message'] ?? ("HTTP $code");
    switch ($reason) {
        case 'quotaExceeded':
            return 'Aaj ka YouTube API quota khatam ho gaya. Kal dobara try kijiye.';
        case 'uploadLimitExceeded':
            return 'Is YouTube channel ki daily upload limit poori ho gayi.';
        case 'youtubeSignupRequired':
            return 'Is Google account par YouTube channel nahi hai. Pehle YouTube par channel banaiye.';
        case 'forbidden':
        case 'insufficientPermissions':
            return 'Permission nahi mili. "Disconnect" karke dobara Connect YouTube kijiye. (' . $msg . ')';
    }
    if ($code === 401) return 'YouTube login expire ho gaya, dobara Connect YouTube kijiye.';
    return 'YouTube error: ' . $msg;
}
