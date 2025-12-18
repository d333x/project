<?php
session_start();
require __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    die(json_encode(['status' => 'error', 'message' => 'Not authenticated']));
}

$receiver_id = (int)($_POST['receiver_id'] ?? 0);
$sender_id = $_SESSION['user_id'];

if ($receiver_id <= 0) {
    die(json_encode(['status' => 'error', 'message' => 'Invalid recipient ID']));
}

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    die(json_encode(['status' => 'error', 'message' => 'No audio file uploaded']));
}

// Validate file size (max 10MB)
if ($_FILES['audio']['size'] > 10 * 1024 * 1024) {
    die(json_encode(['status' => 'error', 'message' => 'Audio file too large (max 10MB)']));
}

// Validate file type
$allowed_types = ['audio/webm', 'audio/ogg', 'audio/wav', 'audio/mp3'];
$file_type = $_FILES['audio']['type'];
if (!in_array($file_type, $allowed_types)) {
    die(json_encode(['status' => 'error', 'message' => 'Invalid audio format']));
}

try {
    $db = getDB();
    
    // Ensure proper charset for this connection
    $db->set_charset("utf8mb4");
    $db->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // Check if receiver exists and is not banned
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND banned = 0 LIMIT 1");
    $stmt->bind_param("i", $receiver_id);
    $stmt->execute();
    
    if (!$stmt->get_result()->num_rows) {
        die(json_encode(['status' => 'error', 'message' => 'Recipient not found or banned']));
    }
    
    // Create audio directory if not exists
    $audio_dir = __DIR__ . '/../audio_messages';
    if (!file_exists($audio_dir)) {
        if (!mkdir($audio_dir, 0755, true)) {
            die(json_encode(['status' => 'error', 'message' => 'Failed to create audio directory']));
        }
    }
    
    // Generate unique filename
    $file_extension = pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION) ?: 'webm';
    $file_name = 'audio_' . $sender_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
    $file_path = $audio_dir . '/' . $file_name;
    
    // Move uploaded file
    if (!move_uploaded_file($_FILES['audio']['tmp_name'], $file_path)) {
        die(json_encode(['status' => 'error', 'message' => 'Failed to save audio file']));
    }
    
    // Save message with audio reference
    $message = '[Голосовое сообщение]';
    $audio_url = 'audio_messages/' . $file_name;
    
    // Check if audio_url column exists, if not use message field
    $columns_result = $db->query("SHOW COLUMNS FROM messages LIKE 'audio_url'");
    $has_audio_column = $columns_result->num_rows > 0;
    
    if ($has_audio_column) {
        $stmt = $db->prepare("INSERT INTO messages (sender_id, receiver_id, message, audio_url, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("iiss", $sender_id, $receiver_id, $message, $audio_url);
    } else {
        // Store audio URL in message field with special format
        $message_with_audio = $message . '|AUDIO:' . $audio_url;
        $stmt = $db->prepare("INSERT INTO messages (sender_id, receiver_id, message, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iis", $sender_id, $receiver_id, $message_with_audio);
    }
    
    if (!$stmt->execute()) {
        unlink($file_path); // Delete file if database insert fails
        throw new Exception("Failed to save message: " . $db->error);
    }
    
    $message_id = $db->insert_id;
    
    // Log the action if function exists
    if (function_exists('logChatMessage')) {
        logChatMessage($sender_id, $receiver_id, $message);
    }
    if (function_exists('logAction')) {
        logAction($sender_id, 'send_audio_message', "Sent audio message to ID: $receiver_id");
    }
    
    echo json_encode([
        'status' => 'success',
        'message_id' => $message_id,
        'audio_url' => $audio_url,
        'message' => 'Audio message sent successfully'
    ]);
    
} catch (Exception $e) {
    // Clean up file if it was created
    if (isset($file_path) && file_exists($file_path)) {
        unlink($file_path);
    }
    
    error_log("Error in send_audio_message.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error occurred']);
}
?>