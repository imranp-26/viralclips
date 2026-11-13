<?php
// ===== Yahan apni settings daaliye =====
define('DB_HOST', 'localhost');
define('DB_NAME', 'viralclips');
define('DB_USER', 'root');
define('DB_PASS', '');
// Python ka path (Windows: 'python' ya 'C:\\Python312\\python.exe', Linux/Mac: 'python3')
define('PYTHON_BIN', PHP_OS_FAMILY === 'Windows' ? 'C:/Users/Imran Patel/AppData/Local/Programs/Python/Python314/python.exe' : 'python3');
define('MAX_CLIPS_LIMIT', 8);

session_start();

function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function require_login(): void {
    if (empty($_SESSION['uid'])) { header('Location: login.php'); exit; }
}
function fmt_time(int $s): string { return sprintf('%02d:%02d', intdiv($s, 60), $s % 60); }
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(403); exit('Invalid request'); }
}
