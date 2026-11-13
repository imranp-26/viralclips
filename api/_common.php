<?php
require_once __DIR__ . '/../config.php';
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

function out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
set_exception_handler(function ($e) { out(['error' => 'Server error: ' . $e->getMessage()], 500); });

function body(): array {
    $j = json_decode(file_get_contents('php://input'), true);
    return is_array($j) ? $j : $_POST;
}
function api_user(): array {
    $t = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if ($t === '') out(['error' => 'Login zaroori hai'], 401);
    $q = db()->prepare('SELECT id,name FROM users WHERE api_token=?');
    $q->execute([$t]);
    $u = $q->fetch();
    if (!$u) out(['error' => 'Session expire ho gaya, dobara login kijiye'], 401);
    return $u;
}
function new_token(int $uid): string {
    $t = bin2hex(random_bytes(24));
    db()->prepare('UPDATE users SET api_token=? WHERE id=?')->execute([$t, $uid]);
    return $t;
}
function launch_worker(int $jobId): void {
    $script = __DIR__ . '/../ai_worker/worker.py';
    $log = __DIR__ . '/../storage/worker_' . $jobId . '.log';
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = 'start /B "" ' . escapeshellarg(PYTHON_BIN) . ' ' . escapeshellarg($script) . ' ' . $jobId . ' > ' . escapeshellarg($log) . ' 2>&1';
        pclose(popen($cmd, 'r'));
    } else {
        $cmd = 'nohup ' . escapeshellarg(PYTHON_BIN) . ' ' . escapeshellarg($script) . ' ' . $jobId . ' > ' . escapeshellarg($log) . ' 2>&1 &';
        exec($cmd);
    }
}
