<?php
session_start();
require __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    die(json_encode(['status' => 'error', 'message' => 'Not authenticated']));
}

$user_id = (int)($_GET['user_id'] ?? 0);
$current_user_id = $_SESSION['user_id'];

if ($user_id <= 0) {
    die(json_encode(['is_typing' => false]));
}

try {
    $db = getDB();
    
    // Check if the user is typing to current user
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM typing_indicators 
                         WHERE user_id = ? AND receiver_id = ? AND last_typed > NOW() - INTERVAL ? SECOND");
    $typing_threshold = TYPING_THRESHOLD_SECONDS;
    $stmt->bind_param("iii", $user_id, $current_user_id, $typing_threshold);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    
    echo json_encode(['is_typing' => $count > 0]);
} catch (Exception $e) {
    error_log("Error in get_typing_status.php: " . $e->getMessage());
    echo json_encode(['is_typing' => false]);
}
?>