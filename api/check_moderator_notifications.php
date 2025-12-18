<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['role'] < ROLE_MODER) {
    echo json_encode(['count' => 0]);
    exit;
}

$moderator_id = $_SESSION['user_id'];
$notifications = getUnreadModeratorNotifications($moderator_id);

echo json_encode(['count' => count($notifications)]);
?>
