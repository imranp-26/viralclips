<?php
// Trending / latest videos: PDF mein diye gaye YouTubers ke videos (YouTube Data API v3, API key se)
require_once __DIR__ . '/yt_lib.php';
if (!defined('YT_API_BASE')) define('YT_API_BASE', 'https://www.googleapis.com/youtube/v3');
if (!defined('TR_CACHE_DIR')) define('TR_CACHE_DIR', __DIR__ . '/storage/cache');
if (!defined('TR_CACHE_TTL')) define('TR_CACHE_TTL', 7200); // 2 ghante: quota bachta hai

/** Handle galat nikle to automatic name se search hota hai. Aap yahan naye creators jod/badal sakte hain. */
function tr_creators(): array {
    return [
        ['name' => 'PewDiePie',          'group' => 'Global', 'handle' => 'PewDiePie'],
        ['name' => 'IShowSpeed',         'group' => 'Global', 'handle' => 'IShowSpeed'],
        ['name' => 'Kai Cenat',          'group' => 'Global', 'handle' => 'KaiCenat'],
        ['name' => 'Dude Perfect',       'group' => 'Global', 'handle' => 'DudePerfect'],
        ['name' => 'Logan Paul',         'group' => 'Global', 'handle' => 'LoganPaulVlogs'],
        ['name' => 'KSI',                'group' => 'Global', 'handle' => 'KSI'],
        ['name' => 'ZHC',                'group' => 'Global', 'handle' => 'ZHC'],
        ['name' => 'CarryMinati',        'group' => 'India',  'handle' => 'CarryMinati'],
        ['name' => 'Fukra Insaan',       'group' => 'India',  'handle' => 'FukraInsaan'],
        ['name' => 'BB Ki Vines',        'group' => 'India',  'handle' => 'BBKiVines'],
        ['name' => 'Ashish Chanchlani',  'group' => 'India',  'handle' => 'ashishchanchlani'],
        ['name' => 'Sourav Joshi Vlogs', 'group' => 'India',  'handle' => 'souravjoshivlogs7028'],
        ['name' => 'Triggered Insaan',   'group' => 'India',  'handle' => 'TriggeredInsaan'],
    ];
}

function tr_key(): string { return defined('YOUTUBE_API_KEY') ? trim((string)YOUTUBE_API_KEY) : ''; }

function tr_cache_dir(): string {
    if (!is_dir(TR_CACHE_DIR)) @mkdir(TR_CACHE_DIR, 0775, true);
    return TR_CACHE_DIR;
}
function tr_cache_read(string $name): ?array {
    $f = tr_cache_dir() . '/' . $name;
    if (!is_file($f)) return null;
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : null;
}
function tr_cache_write(string $name, array $data): void {
    @file_put_contents(tr_cache_dir() . '/' . $name, json_encode($data, JSON_UNESCAPED_UNICODE));
}

function tr_error_hint(int $code, string $reason, string $msg): string {
    if (in_array($reason, ['keyInvalid', 'API_KEY_INVALID'], true) || stripos($msg, 'API key not valid') !== false)
        return 'YouTube API key galat hai. google_config.php mein YOUTUBE_API_KEY check kijiye.';
    if (in_array($reason, ['accessNotConfigured', 'SERVICE_DISABLED'], true) || stripos($msg, 'has not been used') !== false)
        return 'Google Cloud mein "YouTube Data API v3" Enable nahi hai (usi project mein jisme key banayi).';
    if ($reason === 'quotaExceeded') return 'Aaj ka YouTube API quota khatam ho gaya, kal try kijiye.';
    if ($code === 403 && stripos($msg, 'referer') !== false)
        return 'API key par website restriction hai. Key ko "None" ya "API restriction: YouTube Data API v3" par rakhiye.';
    return 'YouTube API error: ' . $msg;
}

function tr_api(string $endpoint, array $params): array {
    $params['key'] = tr_key();
    $ch = curl_init(YT_API_BASE . '/' . $endpoint . '?' . http_build_query($params));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25]);
    yt_apply_ssl($ch);
    $body = curl_exec($ch);
    if ($body === false) { $e = curl_error($ch); throw new Exception('Network error: ' . $e); }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $d = json_decode($body, true);
    if ($code !== 200) {
        throw new Exception(tr_error_hint($code, (string)($d['error']['errors'][0]['reason'] ?? ($d['error']['status'] ?? '')),
            (string)($d['error']['message'] ?? "HTTP $code")));
    }
    return is_array($d) ? $d : [];
}

function tr_resolve_channel(array $cr): ?string {
    if (!empty($cr['handle'])) {
        $d = tr_api('channels', ['part' => 'id', 'forHandle' => '@' . ltrim($cr['handle'], '@')]);
        if (!empty($d['items'][0]['id'])) return $d['items'][0]['id'];
    }
    $d = tr_api('search', ['part' => 'snippet', 'type' => 'channel', 'q' => $cr['name'], 'maxResults' => 1]);
    return $d['items'][0]['snippet']['channelId'] ?? ($d['items'][0]['id']['channelId'] ?? null);
}

/** name => channelId (ek baar nikal kar cache hota hai) */
function tr_channel_ids(array $creators, array &$warnings): array {
    $ids = tr_cache_read('channels.json') ?? [];
    $changed = false;
    foreach ($creators as $cr) {
        if (!empty($ids[$cr['name']])) continue;
        try {
            $cid = tr_resolve_channel($cr);
            if ($cid) { $ids[$cr['name']] = $cid; $changed = true; }
            else $warnings[] = $cr['name'] . ': channel nahi mila';
        } catch (Exception $e) {
            $warnings[] = $cr['name'] . ': ' . $e->getMessage();
            if (stripos($e->getMessage(), 'key') !== false || stripos($e->getMessage(), 'quota') !== false) throw $e;
        }
    }
    if ($changed) tr_cache_write('channels.json', $ids);
    return $ids;
}

function tr_duration_seconds(string $iso): int {
    if (!preg_match('/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $m)) return 0;
    return (int)($m[1] ?? 0) * 86400 + (int)($m[2] ?? 0) * 3600 + (int)($m[3] ?? 0) * 60 + (int)($m[4] ?? 0);
}

function tr_fetch_all(): array {
    $warnings = [];
    $creators = tr_creators();
    $ids = tr_channel_ids($creators, $warnings);
    $owner = []; // videoId => creator
    foreach ($creators as $cr) {
        $cid = $ids[$cr['name']] ?? null;
        if (!$cid) continue;
        try {
            $d = tr_api('playlistItems', ['part' => 'contentDetails', 'playlistId' => 'UU' . substr($cid, 2), 'maxResults' => 12]);
        } catch (Exception $e) {
            $warnings[] = $cr['name'] . ': ' . $e->getMessage();
            if (stripos($e->getMessage(), 'quota') !== false) break;
            continue;
        }
        foreach ($d['items'] ?? [] as $it) {
            $v = $it['contentDetails']['videoId'] ?? null;
            if ($v) $owner[$v] = $cr;
        }
    }
    if (!$owner) throw new Exception($warnings ? $warnings[0] : 'Koi video nahi mila');

    $videos = [];
    foreach (array_chunk(array_keys($owner), 50) as $chunk) {
        $d = tr_api('videos', ['part' => 'snippet,contentDetails,statistics', 'id' => implode(',', $chunk), 'maxResults' => 50]);
        foreach ($d['items'] ?? [] as $it) {
            $sn = $it['snippet'] ?? [];
            if (in_array($sn['liveBroadcastContent'] ?? 'none', ['live', 'upcoming'], true)) continue;
            $cr = $owner[$it['id']] ?? ['name' => $sn['channelTitle'] ?? '', 'group' => ''];
            $thumbs = $sn['thumbnails'] ?? [];
            $thumb = $thumbs['medium']['url'] ?? ($thumbs['high']['url'] ?? ($thumbs['default']['url'] ?? ''));
            $videos[] = [
                'id' => $it['id'],
                'title' => $sn['title'] ?? '',
                'channel' => $sn['channelTitle'] ?? '',
                'creator' => $cr['name'],
                'group' => $cr['group'] ?? '',
                'published' => $sn['publishedAt'] ?? '',
                'views' => (int)($it['statistics']['viewCount'] ?? 0),
                'likes' => (int)($it['statistics']['likeCount'] ?? 0),
                'duration' => tr_duration_seconds((string)($it['contentDetails']['duration'] ?? '')),
                'thumb' => $thumb,
            ];
        }
    }
    return ['fetched_at' => time(), 'videos' => $videos, 'warnings' => $warnings];
}

function tr_load(bool $force = false): array {
    $cache = tr_cache_read('trending.json');
    $age = $cache ? time() - (int)($cache['fetched_at'] ?? 0) : PHP_INT_MAX;
    if ($cache && $age < TR_CACHE_TTL && !($force && $age > 600)) return $cache;
    try {
        $data = tr_fetch_all();
        tr_cache_write('trending.json', $data);
        return $data;
    } catch (Exception $e) {
        if ($cache) { $cache['warnings'][] = 'Naya data nahi aaya, purana dikha rahe hain: ' . $e->getMessage(); return $cache; }
        throw $e;
    }
}

/** sort: trending (views per hour) | latest | views. Return: dikhane layak list (limit ke saath) */
function tr_query(array $data, string $sort, string $creator, bool $withShorts, int $limit = 36): array {
    $now = time();
    $out = [];
    foreach ($data['videos'] ?? [] as $v) {
        if ($creator !== '' && $creator !== 'all' && $v['creator'] !== $creator) continue;
        if (!$withShorts && $v['duration'] > 0 && $v['duration'] <= 62) continue;
        $pub = strtotime($v['published']) ?: $now;
        $ageH = max(0.0, ($now - $pub) / 3600);
        $v['age_hours'] = round($ageH, 1);
        $v['vph'] = (int)round($v['views'] / max($ageH, 12.0)); // views per hour (bahut nayi video ko 12h maan kar)
        $v['pub_ts'] = $pub;
        $out[] = $v;
    }
    $cmp = [
        'latest' => fn($a, $b) => $b['pub_ts'] <=> $a['pub_ts'],
        'views' => fn($a, $b) => $b['views'] <=> $a['views'],
        'trending' => fn($a, $b) => $b['vph'] <=> $a['vph'],
    ][$sort] ?? null;
    usort($out, $cmp ?? fn($a, $b) => $b['vph'] <=> $a['vph']);
    return array_slice($out, 0, $limit);
}
