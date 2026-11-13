<?php
require_once 'config.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrf_check();

$url = trim($_POST['url'] ?? '');
// Sirf YouTube links allow (shell injection se bachne ke liye bhi zaroori)
if (!preg_match('~^https?://(www\.|m\.)?(youtube\.com/(watch\?v=|shorts/|live/)|youtu\.be/)[A-Za-z0-9_\-]{11}[A-Za-z0-9_\-&=%.?]*$~', $url)) {
    header('Location: index.php?err=' . urlencode('Valid YouTube link daaliye.')); exit;
}
$max = max(1, min(MAX_CLIPS_LIMIT, (int)($_POST['max_clips'] ?? 5)));
$aspect = in_array($_POST['aspect'] ?? '', ['9:16', '1:1', '16:9'], true) ? $_POST['aspect'] : '9:16';
$fit = in_array($_POST['fit_mode'] ?? '', ['crop', 'blur', 'bars'], true) ? $_POST['fit_mode'] : 'bars';
$captions = !empty($_POST['captions']) ? 1 : 0;
$creditLine = !empty($_POST['credit_line']) ? 1 : 0;
$smartFrame = !empty($_POST['smart_frame']) ? 1 : 0;
$titleHook = !empty($_POST['title_hook']) ? 1 : 0;
$vertical = $aspect === '9:16' ? 1 : 0;

$language = in_array($_POST['language'] ?? '', ['hindi', 'hinglish', 'english', 'same'], true) ? $_POST['language'] : 'hindi';

$audioLang = ($_POST['audio_lang'] ?? 'hi') === 'original' ? 'original' : 'hi';

db()->prepare('INSERT INTO jobs(user_id,youtube_url,max_clips,vertical,aspect,fit_mode,captions,credit_line,smart_frame,title_hook,language,audio_lang) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$_SESSION['uid'], $url, $max, $vertical, $aspect, $fit, $captions, $creditLine, $smartFrame, $titleHook, $language, $audioLang]);
$jobId = (int)db()->lastInsertId();

// Python worker ko background mein chalao
$script = __DIR__ . '/ai_worker/worker.py';
$log = __DIR__ . '/storage/worker_' . $jobId . '.log';
if (PHP_OS_FAMILY === 'Windows') {
    $cmd = 'start /B "" ' . escapeshellarg(PYTHON_BIN) . ' ' . escapeshellarg($script) . ' ' . $jobId . ' > ' . escapeshellarg($log) . ' 2>&1';
    pclose(popen($cmd, 'r'));
} else {
    $cmd = 'nohup ' . escapeshellarg(PYTHON_BIN) . ' ' . escapeshellarg($script) . ' ' . $jobId . ' > ' . escapeshellarg($log) . ' 2>&1 &';
    exec($cmd);
}
header('Location: job.php?id=' . $jobId);
