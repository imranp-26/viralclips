<?php
require_once __DIR__ . '/_common.php';
$u = api_user();
$b = body();
$url = trim($b['url'] ?? '');
if (!preg_match('~^https?://(www\.|m\.)?(youtube\.com/(watch\?v=|shorts/|live/)|youtu\.be/)[A-Za-z0-9_\-]{11}[A-Za-z0-9_\-&=%.?]*$~', $url))
    out(['error' => 'Valid YouTube link daaliye'], 400);
$max = max(1, min(MAX_CLIPS_LIMIT, (int)($b['max_clips'] ?? 5)));
$aspect = in_array($b['aspect'] ?? '', ['9:16', '1:1', '16:9'], true) ? $b['aspect'] : (!empty($b['vertical']) ? '9:16' : '16:9');
$fit = in_array($b['fit_mode'] ?? '', ['crop', 'blur', 'bars'], true) ? $b['fit_mode'] : 'bars';
$captions = array_key_exists('captions', $b) ? (!empty($b['captions']) ? 1 : 0) : 1;
$creditLine = array_key_exists('credit_line', $b) ? (!empty($b['credit_line']) ? 1 : 0) : 1;
$smartFrame = array_key_exists('smart_frame', $b) ? (!empty($b['smart_frame']) ? 1 : 0) : 1;
$titleHook = array_key_exists('title_hook', $b) ? (!empty($b['title_hook']) ? 1 : 0) : 1;
$vertical = $aspect === '9:16' ? 1 : 0;
$language = in_array($b['language'] ?? '', ['hindi', 'hinglish', 'english', 'same'], true) ? $b['language'] : 'hindi';
$audioLang = ($b['audio_lang'] ?? 'hi') === 'original' ? 'original' : 'hi';
db()->prepare('INSERT INTO jobs(user_id,youtube_url,max_clips,vertical,aspect,fit_mode,captions,credit_line,smart_frame,title_hook,language,audio_lang) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$u['id'], $url, $max, $vertical, $aspect, $fit, $captions, $creditLine, $smartFrame, $titleHook, $language, $audioLang]);
$jobId = (int)db()->lastInsertId();
launch_worker($jobId);
out(['job_id' => $jobId]);
