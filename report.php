<?php
require_once __DIR__ . '/config.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_user'])) {
    $reporter_id = $_SESSION['user_id'] ?? 0; // Получаем ID текущего пользователя
    $reported_user_id = (int)$_POST['reported_user_id'];
    $reason = trim($_POST['reason']);
    $message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : null;
    $details = trim($_POST['details'] ?? '');
    
    $db = getDB();

    // Проверяем, что ID отправителя жалобы существует
    $stmt_check_reporter = $db->prepare("SELECT id FROM users WHERE id = ?");
    $stmt_check_reporter->bind_param("i", $reporter_id);
    $stmt_check_reporter->execute();
    if ($stmt_check_reporter->get_result()->num_rows === 0) {
        $_SESSION['error'] = "Ошибка: Отправитель жалобы недействителен. Пожалуйста, войдите снова.";
        error_log("Report error: Reporter ID {$reporter_id} does not exist. Session ID: " . session_id());
        header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'chat.php'));
        exit;
    }

    // Проверяем, существует ли пользователь, на которого жалуются
    $stmt_check_user = $db->prepare("SELECT id FROM users WHERE id = ?");
    $stmt_check_user->bind_param("i", $reported_user_id);
    $stmt_check_user->execute();
    if ($stmt_check_user->get_result()->num_rows === 0) {
        $_SESSION['error'] = "Пользователь, на которого отправлена жалоба, не существует.";
        error_log("Report error: Reported user ID {$reported_user_id} does not exist.");
        header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'chat.php'));
        exit;
    }
    
    // Полное описание причины
    $full_reason = $reason . ($details ? ": $details" : "");
    
    $stmt = $db->prepare("INSERT INTO reports (reporter_id, reported_user_id, message_id, reason) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiis", $reporter_id, $reported_user_id, $message_id, $full_reason);
    
    if ($stmt->execute()) {
        createModeratorNotification($db->insert_id); // Создаем уведомления для модераторов
        $_SESSION['success'] = "Жалоба успешно отправлена на рассмотрение";
    } else {
        $_SESSION['error'] = "Ошибка при отправке жалобы: " . $db->error;
        error_log("Report submission error: " . $db->error . " | Reporter ID: {$reporter_id}, Reported User ID: {$reported_user_id}, Message ID: {$message_id}");
    }
    
    header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'chat.php'));
    exit;
}
