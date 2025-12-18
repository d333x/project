<?php
require __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = getDB();
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $topic_id = (int)($data['topic_id'] ?? 0);
    
    if ($topic_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid topic ID']);
        exit;
    }
    
    try {
        // Create likes table if not exists
        $db->query("CREATE TABLE IF NOT EXISTS topic_likes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            topic_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_like (topic_id, user_id),
            INDEX idx_topic (topic_id),
            INDEX idx_user (user_id),
            FOREIGN KEY (topic_id) REFERENCES forum_topics(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // Check if already liked
        $stmt = $db->prepare("SELECT id FROM topic_likes WHERE topic_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $topic_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Unlike
            $stmt_delete = $db->prepare("DELETE FROM topic_likes WHERE topic_id = ? AND user_id = ?");
            $stmt_delete->bind_param("ii", $topic_id, $user_id);
            $stmt_delete->execute();
            $action = 'unliked';
        } else {
            // Like
            $stmt_insert = $db->prepare("INSERT INTO topic_likes (topic_id, user_id) VALUES (?, ?)");
            $stmt_insert->bind_param("ii", $topic_id, $user_id);
            $stmt_insert->execute();
            $action = 'liked';
        }
        
        // Get updated like count
        $stmt_count = $db->prepare("SELECT COUNT(*) as count FROM topic_likes WHERE topic_id = ?");
        $stmt_count->bind_param("i", $topic_id);
        $stmt_count->execute();
        $like_count = $stmt_count->get_result()->fetch_assoc()['count'];
        
        echo json_encode([
            'success' => true,
            'action' => $action,
            'like_count' => $like_count
        ]);
        
    } catch (Exception $e) {
        error_log("Like topic error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>