<?php
require_once __DIR__ . '/../config.php';

$db = getDB();
$user_id = (int)$_GET['id'];

$user = $db->query("SELECT * FROM users WHERE id = $user_id")->fetch_assoc();

header('Content-Type: application/json');
echo json_encode([
    'username' => $user['username'],
    'role' => match($user['role']) {
        ROLE_ADMIN => 'Админ',
        ROLE_MODER => 'Модератор',
        default => 'Пользователь'
    },
    'created_at' => date('d.m.Y H:i', strtotime($user['created_at']))
]);
