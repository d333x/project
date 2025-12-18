<?php
session_start();
require __DIR__ . '/../config.php';

if (!isLoggedIn()) {
    header('Content-Type: application/json');
    die(json_encode(['status' => 'error', 'message' => 'Not authenticated']));
}

$current_user_id = $_SESSION['user_id'];
$other_user_id = (int)$_GET['user_id'] ?? 0;

if ($other_user_id <= 0) {
    header('Content-Type: application/json');
    die(json_encode(['status' => 'error', 'message' => 'Invalid user ID']));
}

try {
    $db = getDB();
    
    // First, let's check if messages exist
    $check_stmt = $db->prepare("SELECT COUNT(*) as count FROM messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)");
    $check_stmt->bind_param("iiii", $current_user_id, $other_user_id, $other_user_id, $current_user_id);
    $check_stmt->execute();
    $count_result = $check_stmt->get_result();
    $message_count = $count_result->fetch_assoc()['count'];
    
    error_log("get_messages.php: Looking for messages between users $current_user_id and $other_user_id. Found: $message_count messages");
    
    // Get messages with user info including edit status
    $stmt = $db->prepare("
        SELECT m.id, m.sender_id, m.receiver_id, m.message, m.created_at, m.is_read,
               m.is_edited, m.edited_at,
               u.username as sender_username, u.avatar as sender_avatar
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.created_at ASC
        LIMIT 100
    ");
    $stmt->bind_param("iiii", $current_user_id, $other_user_id, $other_user_id, $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = $result->fetch_all(MYSQLI_ASSOC);
    
    error_log("get_messages.php: Retrieved " . count($messages) . " messages from database");

    // Mark messages as read
    if ($message_count > 0) {
        $stmt_read = $db->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
        $stmt_read->bind_param("ii", $other_user_id, $current_user_id);
        $stmt_read->execute();
    }

    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success', 
        'messages' => $messages,
        'total_count' => $message_count,
        'debug' => [
            'current_user_id' => $current_user_id,
            'other_user_id' => $other_user_id,
            'message_count' => $message_count
        ]
    ]);
} catch (Exception $e) {
    error_log("Error fetching messages: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error', 
        'message' => 'Error fetching messages: ' . $e->getMessage(),
        'debug' => [
            'current_user_id' => $current_user_id ?? 'not set',
            'other_user_id' => $other_user_id ?? 'not set'
        ]
    ]);
}
?>
