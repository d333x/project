<?php
session_start();
require __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    die(json_encode(['status' => 'error', 'message' => 'Not authenticated']));
}

try {
    $db = getDB();
    
    // Ensure proper charset
    $db->set_charset("utf8mb4");
    $db->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // Update current user's last activity
    $current_user_id = $_SESSION['user_id'];
    $stmt = $db->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    
    // Get online users (active within last 5 minutes)
    $online_threshold = date('Y-m-d H:i:s', strtotime('-5 minutes'));
    
    $stmt = $db->prepare("
        SELECT 
            u.id,
            u.username,
            u.avatar,
            u.role,
            u.last_activity,
            CASE 
                WHEN u.last_activity >= ? THEN 'online'
                WHEN u.last_activity >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN 'away'
                ELSE 'offline'
            END as status
        FROM users u 
        WHERE u.banned = 0 
            AND u.id != ? 
            AND u.last_activity >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ORDER BY 
            CASE 
                WHEN u.last_activity >= ? THEN 1
                WHEN u.last_activity >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN 2
                ELSE 3
            END,
            u.last_activity DESC
        LIMIT 50
    ");
    
    $stmt->bind_param("sis", $online_threshold, $current_user_id, $online_threshold);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $online_users = [];
    while ($row = $result->fetch_assoc()) {
        $online_users[] = [
            'id' => (int)$row['id'],
            'username' => htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'),
            'avatar' => $row['avatar'] ? htmlspecialchars($row['avatar'], ENT_QUOTES, 'UTF-8') : null,
            'role' => (int)$row['role'],
            'status' => $row['status'],
            'last_activity' => $row['last_activity']
        ];
    }
    
    // Get total online count
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE banned = 0 AND last_activity >= ?");
    $stmt->bind_param("s", $online_threshold);
    $stmt->execute();
    $count_result = $stmt->get_result();
    $total_online = $count_result->fetch_assoc()['count'];
    
    echo json_encode([
        'status' => 'success',
        'users' => $online_users,
        'total_online' => (int)$total_online,
        'current_time' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_online.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error occurred']);
}
?>