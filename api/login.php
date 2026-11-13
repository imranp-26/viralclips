<?php
require_once __DIR__ . '/_common.php';
$b = body();
$q = db()->prepare('SELECT * FROM users WHERE email=?'); $q->execute([trim($b['email'] ?? '')]);
$u = $q->fetch();
if (!$u || !password_verify($b['password'] ?? '', $u['password_hash'])) out(['error' => 'Email ya password galat hai'], 401);
out(['token' => new_token((int)$u['id']), 'name' => $u['name']]);
