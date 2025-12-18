<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$db = getDB();
$current_user_id = $_SESSION['user_id'];

$statuses = [];

try {
    // Получаем ID всех пользователей, с которыми текущий пользователь ведет диалог
    $dialog_users_query = $db->query("
        SELECT DISTINCT 
            CASE 
                WHEN sender_id = $current_user_id THEN receiver_id 
                ELSE sender_id 
            END as user_id
        FROM messages
        WHERE sender_id = $current_user_id OR receiver_id = $current_user_id
    ");

    $dialog_user_ids = [];
    while ($row = $dialog_users_query->fetch_assoc()) {
        $dialog_user_ids[] = $row['user_id'];
    }

    if (!empty($dialog_user_ids)) {
        $ids_string = implode(',', $dialog_user_ids);

        // Получаем статусы онлайн и набора текста для этих пользователей
        $stmt = $db->prepare("
            SELECT 
                u.id, 
                u.username, 
                (u.last_activity > NOW() - INTERVAL 5 MINUTE) as is_online,
                (ti.user_id IS NOT NULL AND ti.last_typed > NOW() - INTERVAL 5 SECOND) as is_typing
            FROM users u
            LEFT JOIN typing_indicators ti ON u.id = ti.user_id
            WHERE u.id IN ($ids_string) AND u.id != ?
        ");
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $statuses[] = [
                'id' => (int)$row['id'],
                'username' => $row['username'],
                'is_online' => (bool)$row['is_online'],
                'is_typing' => (bool)$row['is_typing']
            ];
        }
    }
    echo json_encode($statuses);

} catch (Exception $e) {
    error_log("Error getting statuses: " . $e->getMessage());
    echo json_encode(['error' => 'Failed to retrieve statuses', 'details' => $e->getMessage()]);
}
?>
