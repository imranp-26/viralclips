<?php
require_once __DIR__ . '/_common.php';
$u = api_user();
$q = db()->prepare('SELECT j.id,j.title,j.youtube_url,j.status,j.created_at,(SELECT COUNT(*) FROM clips c WHERE c.job_id=j.id) AS clips FROM jobs j WHERE user_id=? ORDER BY id DESC LIMIT 100');
$q->execute([$u['id']]);
out(['jobs' => $q->fetchAll()]);
