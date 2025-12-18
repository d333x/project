<?php
session_start();
require __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    die(json_encode(['status' => 'error', 'message' => 'Not authenticated']));
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    die(json_encode(['status' => 'error', 'message' => 'Invalid data format']));
}

$message_id = (int)($input['message_id'] ?? 0);
$emoji = trim($input['emoji'] ?? '');
$user_id = $_SESSION['user_id'];

if ($message_id <= 0 || empty($emoji)) {
    die(json_encode(['status' => 'error', 'message' => 'Invalid parameters']));
}

try {
    $db = getDB();
    
    // Check if reaction exists
    $stmt = $db->prepare("SELECT id FROM message_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?");
    $stmt->bind_param("iis", $message_id, $user_id, $emoji);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Remove reaction
        $stmt = $db->prepare("DELETE FROM message_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?");
        $stmt->bind_param("iis", $message_id, $user_id, $emoji);
        $stmt->execute();
    } else {
        // Add reaction
        $stmt = $db->prepare("INSERT INTO message_reactions (message_id, user_id, emoji) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $message_id, $user_id, $emoji);
        $stmt->execute();
    }
    
    // Get all reactions for this message
    $stmt = $db->prepare("SELECT emoji, COUNT(*) as count FROM message_reactions WHERE message_id = ? GROUP BY emoji");
    $stmt->bind_param("i", $message_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $reactions = [];
    while ($row = $result->fetch_assoc()) {
        $reactions[$row['emoji']] = (int)$row['count'];
    }
    
    echo json_encode(['status' => 'success', 'reactions' => $reactions]);
} catch (Exception $e) {
    error_log("Error in react_message.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}
?>