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

$typing = isset($input['typing']) ? (bool)$input['typing'] : false;
$receiver_id = (int)($input['receiver_id'] ?? 0);
$user_id = $_SESSION['user_id'];

if ($receiver_id <= 0) {
    die(json_encode(['status' => 'error', 'message' => 'Invalid receiver ID']));
}

try {
    $db = getDB();
    
    // Create typing_indicators table if it doesn't exist
    $db->query("CREATE TABLE IF NOT EXISTS typing_indicators (
        user_id INT PRIMARY KEY,
        receiver_id INT NOT NULL,
        last_typed TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    if ($typing) {
        // Insert or update typing status
        $stmt = $db->prepare("INSERT INTO typing_indicators (user_id, receiver_id, last_typed) VALUES (?, ?, NOW()) 
                             ON DUPLICATE KEY UPDATE receiver_id = VALUES(receiver_id), last_typed = NOW()");
        $stmt->bind_param("ii", $user_id, $receiver_id);
        $stmt->execute();
    } else {
        // Remove typing status
        $stmt = $db->prepare("DELETE FROM typing_indicators WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
    }
    
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    error_log("Error in set_typing.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}
?>