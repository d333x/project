<?php
require_once __DIR__.'/../config.php';
session_start();
if (empty($_SESSION['user_id'])) die();

$db = getDB();
$user_id = $_SESSION['user_id'];
$db->query("UPDATE users SET last_activity = NOW() WHERE id = $user_id");
echo json_encode(['status' => 'ok']);