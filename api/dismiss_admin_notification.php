<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['role'] < ROLE_ADMIN) {
    echo json_encode(['status' => 'error', 'message' => 'Not authorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$notification_id = (int)($data['notification_id'] ?? 0);

if ($notification_id === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid notification ID']);
    exit;
}

$db = getDB();

try {
    $stmt = $db->prepare("UPDATE admin_notifications SET is_read = 1 WHERE id = ?");
    $stmt->bind_param("i", $notification_id);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $db->error]);
    }
} catch (Exception $e) {
    error_log("Error dismissing admin notification: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
