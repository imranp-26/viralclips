<?php
require_once 'config.php';
require_login();
$q = db()->prepare('SELECT status,progress,message,error FROM jobs WHERE id=? AND user_id=?');
$q->execute([(int)($_GET['id'] ?? 0), $_SESSION['uid']]);
header('Content-Type: application/json');
echo json_encode($q->fetch() ?: ['status' => 'failed', 'error' => 'Job nahi mila']);
