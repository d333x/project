<?php
// Запуск сессии должен быть самым первым действием
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config.php'; // Исправлен путь к config.php

header('Content-Type: application/json');

if (!isLoggedIn()) { // Используем функцию isLoggedIn() из config.php
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$message_id = (int)($data['message_id'] ?? 0);

if ($message_id === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid message ID']);
    exit;
}

$db = getDB();

try {
    // Verify that the user is the sender of the message
    $stmt_check = $db->prepare("SELECT sender_id FROM messages WHERE id = ? LIMIT 1");
    $stmt_check->bind_param("i", $message_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows > 0) {
        $message_data = $result_check->fetch_assoc();
        if ($message_data['sender_id'] == $user_id) {
            $stmt_delete = $db->prepare("DELETE FROM messages WHERE id = ?");
            $stmt_delete->bind_param("i", $message_id);
            if ($stmt_delete->execute()) {
                logAction($user_id, 'delete_own_message', "Удалено собственное сообщение ID: $message_id");
                echo json_encode(['status' => 'success', 'message' => 'Message deleted successfully.']);
            } else {
                error_log("Database error deleting message: " . $db->error);
                echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'You can only delete your own messages.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Message not found.']);
    }
} catch (Exception $e) {
    error_log("Error deleting message: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
