<?php
// Instagram Reels upload (Instagram API with Instagram Login). Token Meta dashboard se banta hai.
require_once __DIR__ . '/yt_lib.php';
if (!defined('IG_GRAPH_BASE')) define('IG_GRAPH_BASE', 'https://graph.instagram.com');
if (!defined('IG_API_VERSION')) define('IG_API_VERSION', 'v23.0');

/** Aapki site ka PUBLIC https link (ngrok / cloudflare tunnel). Instagram is link se video khud download karta hai. */
function ig_public_base(): string {
    return defined('IG_PUBLIC_BASE_URL') ? rtrim(trim((string)IG_PUBLIC_BASE_URL), '/') : '';
}
function ig_v(string $path): string { return IG_API_VERSION . '/' . ltrim($path, '/'); }

/** [http_code, array] */
function ig_call(string $method, string $path, array $params = [], int $timeout = 60): array {
    $url = rtrim(IG_GRAPH_BASE, '/') . '/' . ltrim($path, '/');
    $ch = curl_init();
    if ($method === 'GET') {
        $url .= '?' . http_build_query($params);
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout]);
    yt_apply_ssl($ch);
    $body = curl_exec($ch);
    if ($body === false) throw new Exception('Network error: ' . curl_error($ch));
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $d = json_decode($body, true);
    return [$code, is_array($d) ? $d : []];
}

function ig_error_message(int $code, array $d): string {
    $e = $d['error'] ?? [];
    $msg = (string)($e['message'] ?? "HTTP $code");
    $c = (int)($e['code'] ?? 0);
    $sub = (int)($e['error_subcode'] ?? 0);
    if ($c === 190 || stripos($msg, 'access token') !== false)
        return 'Instagram token galat ya expire ho gaya. Meta dashboard se naya token banakar Instagram settings mein save kijiye.';
    if (in_array($c, [4, 9, 17, 32, 613], true) || stripos($msg, 'limit') !== false)
        return 'Instagram ki posting limit poori ho gayi (24 ghante mein kam posts allowed). Baad mein try kijiye.';
    if ($c === 10 || $c === 200 || stripos($msg, 'permission') !== false)
        return 'Permission nahi mili. Instagram account Business/Creator hona chahiye aur token mein "instagram_business_content_publish" permission honi chahiye. (' . $msg . ')';
    if (in_array($sub, [2207003, 2207052, 2207082], true) || stripos($msg, 'media uri') !== false || stripos($msg, 'download') !== false)
        return 'Instagram aapki public link se video download nahi kar paya. Tunnel (ngrok/cloudflare) chalu hai aur link khulta hai? (' . $msg . ')';
    return 'Instagram error: ' . $msg . ' (code ' . $c . ($sub ? '/' . $sub : '') . ')';
}

/** Token valid hai ya nahi + account ki id aur username */
function ig_me(string $token): array {
    [$c, $d] = ig_call('GET', ig_v('me'), ['fields' => 'user_id,username', 'access_token' => $token], 30);
    if ($c !== 200) throw new Exception(ig_error_message($c, $d));
    $id = (string)($d['user_id'] ?? $d['id'] ?? '');
    if ($id === '') throw new Exception('Instagram ne account ki id nahi di. Token sahi hai?');
    return ['user_id' => $id, 'username' => (string)($d['username'] ?? '')];
}

/** Long-lived token ko 60 din aur badhata hai (token 24 ghante se purana hona chahiye) */
function ig_refresh(string $token): string {
    [$c, $d] = ig_call('GET', 'refresh_access_token', ['grant_type' => 'ig_refresh_token', 'access_token' => $token], 30);
    if ($c !== 200 || empty($d['access_token'])) throw new Exception(ig_error_message($c, $d));
    return (string)$d['access_token'];
}

/** Public URL se video khulti hai? (chhota GET, sirf pehla byte). Instagram redirect nahi follow karta, isliye hum bhi nahi. [ok, message] */
function ig_url_reachable(string $url): array {
    $status = 0;
    $type = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Range: bytes=0-0'],
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$status, &$type) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
            if (stripos($h, 'content-type:') === 0) $type = trim(substr($h, 13));
            return strlen($h);
        },
        CURLOPT_WRITEFUNCTION => function ($c, $data) { return -1; }, // pehla chunk aate hi band
    ]);
    yt_apply_ssl($ch);
    $res = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    if ($status === 0) return [false, 'Link khul nahi raha: ' . ($err ?: 'koi jawab nahi aaya')];
    if ($res === false && $errno !== 23) return [false, 'Link khul nahi raha: ' . $err]; // 23 = humne khud body band ki
    if (!in_array($status, [200, 206], true))
        return [false, "Link par HTTP $status aaya (200 chahiye). Tunnel chalu hai? Redirect/login page to nahi aa raha?"];
    if ($type !== '' && stripos($type, 'video') === false && stripos($type, 'octet-stream') === false)
        return [false, "Link par video ki jagah '$type' aa raha hai. Tunnel ka warning page to nahi aa raha?"];
    return [true, 'ok'];
}

function ig_caption(array $clip): string {
    $cap = trim((string)($clip['caption_instagram'] ?? ''));
    $tags = [];
    foreach (preg_split('/\s+/', trim((string)($clip['hashtags'] ?? ''))) as $t) {
        if ($t !== '' && $t[0] === '#') $tags[] = $t;
    }
    $tags = array_slice(array_unique($tags), 0, 25);
    $full = $cap . ($tags ? "\n\n" . implode(' ', $tags) : '');
    return mb_substr($full, 0, 2150);
}

/**
 * Reel publish: container banao -> Instagram video process kare (FINISHED) -> publish.
 * Return: ['media_id' => .., 'permalink' => ..]
 */
function ig_publish_reel(string $igId, string $token, string $videoUrl, string $caption, ?string $coverUrl = null,
                         bool $shareToFeed = true, int $maxWaitSec = 420, int $pollSec = 5): array {
    $params = ['media_type' => 'REELS', 'video_url' => $videoUrl, 'caption' => $caption,
               'share_to_feed' => $shareToFeed ? 'true' : 'false', 'access_token' => $token];
    if ($coverUrl) $params['cover_url'] = $coverUrl;
    [$c, $d] = ig_call('POST', ig_v("$igId/media"), $params);
    if ($c !== 200 || empty($d['id'])) throw new Exception(ig_error_message($c, $d));
    $container = (string)$d['id'];

    $waited = 0;
    $status = '';
    while (true) {
        [$c, $d] = ig_call('GET', ig_v($container), ['fields' => 'status_code,status', 'access_token' => $token], 30);
        if ($c !== 200) throw new Exception(ig_error_message($c, $d));
        $status = (string)($d['status_code'] ?? '');
        if ($status === 'FINISHED') break;
        if ($status === 'ERROR' || $status === 'EXPIRED') {
            throw new Exception('Instagram ne video reject ki: ' . (string)($d['status'] ?? $status)
                . ' (Reel ke liye 9:16, H.264 video + AAC audio, 3 sec se zyada chahiye)');
        }
        if ($waited >= $maxWaitSec) throw new Exception('Instagram video process karne mein bahut time le raha hai. Thodi der baad Instagram app mein check kijiye.');
        if ($pollSec > 0) sleep($pollSec);
        $waited += max(1, $pollSec);
    }

    [$c, $d] = ig_call('POST', ig_v("$igId/media_publish"), ['creation_id' => $container, 'access_token' => $token]);
    if ($c !== 200 || empty($d['id'])) throw new Exception(ig_error_message($c, $d));
    $mediaId = (string)$d['id'];
    $permalink = '';
    try {
        [$c2, $d2] = ig_call('GET', ig_v($mediaId), ['fields' => 'permalink', 'access_token' => $token], 30);
        if ($c2 === 200) $permalink = (string)($d2['permalink'] ?? '');
    } catch (Exception $e) {}
    return ['media_id' => $mediaId, 'permalink' => $permalink];
}

// ---------------- database helpers ----------------
function ig_account(int $uid): ?array {
    try {
        $q = db()->prepare('SELECT * FROM instagram_accounts WHERE user_id=?');
        $q->execute([$uid]);
        return $q->fetch() ?: null;
    } catch (Exception $e) {
        return null; // table abhi nahi bani (database_all.sql nahi chala)
    }
}
