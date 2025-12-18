<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$online_count = getOnlineUsersCount();

echo json_encode(['count' => $online_count]);
?>
