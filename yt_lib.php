<?php
require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/google_config.php')) {
    require_once __DIR__ . '/google_config.php';
}

function yt_configured(): bool {
    return defined('GOOGLE_CLIENT_ID') && defined('GOOGLE_CLIENT_SECRET')
        && strpos(GOOGLE_CLIENT_ID, 'YAHAN') === false
        && strpos(GOOGLE_CLIENT_SECRET, 'YAHAN') === false
        && GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_SECRET !== '';
}

function yt_connected(int $uid): bool {
    try {
        $q = db()->prepare('SELECT 1 FROM youtube_tokens WHERE user_id=?');
        $q->execute([$uid]);
        return (bool)$q->fetchColumn();
    } catch (Exception $e) {
        return false; // table abhi nahi bani (yt_schema.sql nahi chala)
    }
}

// XAMPP (Windows) par SSL certificate bundle dhoondhta hai, warna "SSL certificate problem" aata hai
function yt_cainfo(): ?string {
    $cands = [ini_get('curl.cainfo'), 'C:/xampp/php/extras/ssl/cacert.pem', 'C:/xampp/apache/bin/curl-ca-bundle.crt'];
    foreach ($cands as $p) {
        if ($p && is_file($p)) return $p;
    }
    return null;
}

function yt_apply_ssl($ch): void {
    $ca = yt_cainfo();
    if ($ca) curl_setopt($ch, CURLOPT_CAINFO, $ca);
}

/** Form POST (token exchange / refresh / revoke). Return: [http_code, array] */
function yt_post_form(string $url, array $fields): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    yt_apply_ssl($ch);
    $body = curl_exec($ch);
    if ($body === false) {
        $e = curl_error($ch);
        curl_close($ch);
        throw new Exception('Network error: ' . $e);
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($body, true);
    return [$code, is_array($data) ? $data : []];
}

/** Refresh token se naya access token leta hai */
function yt_access_token(int $uid): string {
    $q = db()->prepare('SELECT refresh_token FROM youtube_tokens WHERE user_id=?');
    $q->execute([$uid]);
    $rt = $q->fetchColumn();
    if (!$rt) throw new Exception('YouTube connected nahi hai. Pehle "Connect YouTube" dabaiye.');

    [$code, $d] = yt_post_form('https://oauth2.googleapis.com/token', [
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'refresh_token' => $rt,
        'grant_type' => 'refresh_token',
    ]);
    if ($code === 200 && !empty($d['access_token'])) return $d['access_token'];

    if (($d['error'] ?? '') === 'invalid_grant') {
        db()->prepare('DELETE FROM youtube_tokens WHERE user_id=?')->execute([$uid]);
        throw new Exception('YouTube connection expire ho gaya. Dobara "Connect YouTube" kijiye.');
    }
    throw new Exception('Access token nahi mila: ' . ($d['error_description'] ?? $d['error'] ?? "HTTP $code"));
}

/** Clip ke liye YouTube-thumbnail file (16:9 *_yt.jpg), na ho to normal thumbnail. */
function yt_thumb_file(array $clip): ?string {
    if (empty($clip['thumb_path'])) return null;
    $storage = realpath(__DIR__ . '/storage');
    $yt = preg_replace('/\.jpg$/i', '_yt.jpg', $clip['thumb_path']);
    foreach ([$yt, $clip['thumb_path']] as $rel) {
        $p = realpath(__DIR__ . '/' . $rel);
        if ($p && $storage && strpos($p, $storage) === 0 && is_file($p)) return $p;
    }
    return null;
}

function yt_set_thumbnail(string $token, string $videoId, string $file): string {
    $ch = curl_init('https://www.googleapis.com/upload/youtube/v3/thumbnails/set?videoId=' . urlencode($videoId) . '&uploadType=media');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => file_get_contents($file),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: image/jpeg'],
    ]);
    yt_apply_ssl($ch);
    $body = curl_exec($ch);
    if ($body === false) { $e = curl_error($ch); curl_close($ch); return 'FAIL: thumbnail set nahi hua (' . $e . ')'; }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200) return 'OK: thumbnail laga diya';
    $d = json_decode($body, true);
    $reason = $d['error']['errors'][0]['reason'] ?? '';
    $msg = $d['error']['message'] ?? "HTTP $code";
    if ($code === 403 || $reason === 'forbidden') {
        return 'FAIL: YouTube ne custom thumbnail mana kiya. Channel ko phone se verify kijiye (youtube.com/verify). Shorts ke liye thumbnail sirf YouTube Studio (computer) se lagta hai. (' . $msg . ')';
    }
    if ($reason === 'uploadRateLimitExceeded' || $code === 429) {
        return 'FAIL: aaj ki custom-thumbnail limit poori ho gayi, 24 ghante baad try kijiye.';
    }
    return 'FAIL: thumbnail set nahi hua: ' . $msg;
}
