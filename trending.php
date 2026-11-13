<?php
require_once __DIR__ . '/trending_lib.php';
header('Content-Type: application/json; charset=utf-8');
function tout(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (empty($_SESSION['uid'])) tout(['error' => 'Login zaroori hai'], 401);
if (tr_key() === '') {
    tout(['needs_key' => true, 'error' => 'YouTube API key set nahi hai. google_config.php mein YOUTUBE_API_KEY daaliye (neeche steps dekhiye).'], 200);
}
if (!function_exists('curl_init')) tout(['error' => 'PHP curl extension band hai'], 500);

$sort = in_array($_GET['sort'] ?? '', ['trending', 'latest', 'views'], true) ? $_GET['sort'] : 'trending';
$creator = (string)($_GET['creator'] ?? 'all');
$shorts = !empty($_GET['shorts']);
try {
    $data = tr_load(!empty($_GET['refresh']));
} catch (Exception $e) {
    tout(['error' => $e->getMessage()], 500);
}
$names = array_map(fn($c) => ['name' => $c['name'], 'group' => $c['group']], tr_creators());
tout([
    'ok' => true,
    'fetched_at' => (int)($data['fetched_at'] ?? 0),
    'warnings' => array_slice($data['warnings'] ?? [], 0, 5),
    'creators' => $names,
    'videos' => tr_query($data, $sort, $creator, $shorts),
]);
