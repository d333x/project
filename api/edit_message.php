<?php
session_start();
require __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация']);
    exit;
}

$db = getDB();
$message_id = (int)($_POST['message_id'] ?? 0);
$new_content = trim($_POST['content'] ?? '');

if ($message_id === 0) {
    echo json_encode(['success' => false, 'message' => 'Некорректный ID сообщения']);
    exit;
}

if (empty($new_content)) {
    echo json_encode(['success' => false, 'message' => 'Содержимое сообщения не может быть пустым']);
    exit;
}

if (strlen($new_content) > 1000) {
    echo json_encode(['success' => false, 'message' => 'Сообщение слишком длинное (максимум 1000 символов)']);
    exit;
}

try {
    // Ensure proper charset
    $db->set_charset("utf8mb4");
    $db->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // Проверяем, что сообщение существует и получаем его данные
    $stmt = $db->prepare("SELECT sender_id, message, created_at, audio_url FROM messages WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $message_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Сообщение не найдено']);
        exit;
    }

    $message = $result->fetch_assoc();

    // Проверяем права на редактирование (только свои сообщения или модераторы/админы)
    $user_role = $_SESSION['role'] ?? 0;
    if ($message['sender_id'] != $_SESSION['user_id'] && $user_role < ROLE_MODER) {
        echo json_encode(['success' => false, 'message' => 'Недостаточно прав для редактирования']);
        exit;
    }

    // Проверяем, не является ли это голосовым сообщением
    if (!empty($message['audio_url']) || strpos($message['message'], '[Голосовое сообщение]') !== false) {
        echo json_encode(['success' => false, 'message' => 'Голосовые сообщения нельзя редактировать']);
        exit;
    }

    // Проверяем временные ограничения (можно редактировать только в течение 24 часов)
    $created_time = strtotime($message['created_at']);
    $current_time = time();
    $time_limit = 24 * 60 * 60; // 24 hours in seconds
    
    if (($current_time - $created_time) > $time_limit && $user_role < ROLE_MODER) {
        echo json_encode(['success' => false, 'message' => 'Время редактирования истекло (максимум 24 часа)']);
        exit;
    }

    // Проверяем, есть ли поля для отслеживания редактирования
    $columns_result = $db->query("SHOW COLUMNS FROM messages LIKE 'is_edited'");
    $has_edit_fields = $columns_result->num_rows > 0;
    
    if ($has_edit_fields) {
        // Обновляем сообщение с отметкой о редактировании
        $stmt = $db->prepare("UPDATE messages SET message = ?, is_edited = 1, edited_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $new_content, $message_id);
    } else {
        // Просто обновляем содержимое
        $stmt = $db->prepare("UPDATE messages SET message = ? WHERE id = ?");
        $stmt->bind_param("si", $new_content, $message_id);
    }

    if ($stmt->execute()) {
        // Логируем изменение если функция существует
        if (function_exists('logAction')) {
            logAction($_SESSION['user_id'], 'edit_message', "Изменено сообщение ID: $message_id. Старое: '{$message['message']}', Новое: '$new_content'");
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Сообщение успешно обновлено',
            'edited_content' => htmlspecialchars($new_content, ENT_QUOTES, 'UTF-8'),
            'is_edited' => true
        ]);
    } else {
        throw new Exception('Ошибка при обновлении: ' . $db->error);
    }
    
} catch (Exception $e) {
    error_log("Error in edit_message.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Произошла ошибка сервера']);
}
?>