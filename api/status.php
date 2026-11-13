<?php
require_once __DIR__ . '/_common.php';
$u = api_user();
$id = (int)($_GET['id'] ?? 0);
$q = db()->prepare('SELECT title,status,progress,message,error FROM jobs WHERE id=? AND user_id=?');
$q->execute([$id, $u['id']]);
$j = $q->fetch();
if (!$j) out(['error' => 'Job nahi mila'], 404);
$j['progress'] = (int)$j['progress'];
if ($j['status'] === 'done') {
    $c = db()->prepare('SELECT id,start_sec,end_sec,title,viral_score,reason,caption_instagram,caption_youtube,hashtags,file_path FROM clips WHERE job_id=? ORDER BY viral_score DESC');
    $c->execute([$id]);
    $j['clips'] = array_map(function ($r) {
        $r['start_sec'] = (int)$r['start_sec']; $r['end_sec'] = (int)$r['end_sec']; $r['viral_score'] = (int)$r['viral_score'];
        return $r;
    }, $c->fetchAll());
}
out($j);
