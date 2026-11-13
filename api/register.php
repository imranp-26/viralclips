<?php
require_once __DIR__ . '/_common.php';
$b = body();
$name = trim($b['name'] ?? ''); $email = trim($b['email'] ?? ''); $pw = $b['password'] ?? '';
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pw) < 6)
    out(['error' => 'Sahi naam, email aur kam se kam 6 character ka password daaliye'], 400);
$q = db()->prepare('SELECT id FROM users WHERE email=?'); $q->execute([$email]);
if ($q->fetch()) out(['error' => 'Ye email already registered hai'], 409);
db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')
    ->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT)]);
$uid = (int)db()->lastInsertId();
out(['token' => new_token($uid), 'name' => $name]);
