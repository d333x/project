<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$db = getDB();
$current_user_id = $_SESSION['user_id'];

// Получаем параметры из GET
$last_update = isset($_GET['last_update']) ? (int)$_GET['last_update'] : 0;
$selected_user_id = isset($_GET['receiver_id']) ? (int)$_GET['receiver_id'] : 0;

// Время порога для онлайн и печатает
$online_threshold = ONLINE_THRESHOLD_MINUTES;
$typing_threshold = TYPING_THRESHOLD_SECONDS;

try {
    // 1. Получаем новые сообщения с момента last_update, где текущий пользователь — отправитель или получатель
    $stmt = $db->prepare("
        SELECT id, sender_id, receiver_id, message, FLOOR(UNIX_TIMESTAMP(created_at) * 1000) AS created_at
        FROM messages
        WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
          AND UNIX_TIMESTAMP(created_at) > ?
        ORDER BY created_at ASC
    ");
    $stmt->bind_param("iiiii", $current_user_id, $selected_user_id, $selected_user_id, $current_user_id, $last_update);
    $stmt->execute();
    $messages_result = $stmt->get_result();
    $messages = $messages_result->fetch_all(MYSQLI_ASSOC);

    // 2. Получаем список всех пользователей (кроме текущего) с их онлайн статусом
    $stmt = $db->prepare("
        SELECT id, username, avatar,
        CASE WHEN last_activity > NOW() - INTERVAL ? MINUTE THEN 1 ELSE 0 END AS is_online
        FROM users
        WHERE id != ? AND banned = 0
        ORDER BY username ASC
    ");
    $stmt->bind_param("ii", $online_threshold, $current_user_id);
    $stmt->execute();
    $users_result = $stmt->get_result();
    $users = $users_result->fetch_all(MYSQLI_ASSOC);

    // 3. Получаем статус "печатает" выбранного пользователя (если выбран)
    $is_typing = 0;
    if ($selected_user_id > 0) {
        $stmt = $db->prepare("
            SELECT CASE WHEN last_typing > NOW() - INTERVAL ? SECOND THEN 1 ELSE 0 END AS is_typing
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $typing_threshold, $selected_user_id);
        $stmt->execute();
        $typing_result = $stmt->get_result();
        if ($typing_row = $typing_result->fetch_assoc()) {
            $is_typing = (int)$typing_row['is_typing'];
        }
    }

    // 4. Получаем количество пользователей онлайн
    $stmt = $db->prepare("
        SELECT COUNT(*) AS online_count
        FROM users
        WHERE last_activity > NOW() - INTERVAL ? MINUTE AND banned = 0
    ");
    $stmt->bind_param("i", $online_threshold);
    $stmt->execute();
    $count_result = $stmt->get_result();
    $online_count = 0;
    if ($count_row = $count_result->fetch_assoc()) {
        $online_count = (int)$count_row['online_count'];
    }

    // 5. Возвращаем текущий timestamp сервера
    $timestamp = time();

    // Формируем ответ
    echo json_encode([
        'status' => 'success',
        'timestamp' => $timestamp,
        'messages' => $messages,
        'users' => $users,
        'is_typing' => $is_typing,
        'online_count' => $online_count,
    ]);

} catch (Exception $e) {
    error_log("Error in get_updates.php: " . $e->getMessage());
    echo json_encode(['error' => 'Server error']);
}
