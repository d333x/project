<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];
$unread_count = getUnreadMessagesCount($user_id);

echo json_encode(['unread_count' => $unread_count]);
?>
