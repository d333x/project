<?php
session_start();
require __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die(json_encode(['status' => 'error', 'message' => 'Method not allowed']));
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    die(json_encode(['status' => 'error', 'message' => 'Invalid data format']));
}

$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if (empty($username) || empty($password)) {
    die(json_encode(['status' => 'error', 'message' => 'Пожалуйста, заполните все поля.']));
}

// Проверка rate limit перед попыткой входа
$rateLimit = checkLoginRateLimit();
if (!$rateLimit['allowed']) {
    die(json_encode([
        'status' => 'error', 
        'message' => $rateLimit['message'] ?? 'Слишком много неудачных попыток входа. Попробуйте позже.',
        'lockout_time' => $rateLimit['lockout_time']
    ]));
}

try {
    $db = getDB();

    // Используем подготовленные выражения для предотвращения SQL-инъекций
    $stmt = $db->prepare("SELECT id, username, password, role, avatar, banned FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        // Проверяем хеш пароля
        if (password_verify($password, $user['password'])) {
            // Проверяем статус бана
            if ($user['banned'] ?? 0) {
                recordLoginAttempt(null, $username, false);
                die(json_encode(['status' => 'error', 'message' => 'Ваш аккаунт заблокирован.']));
            } else {
                // Устанавливаем данные сессии
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['avatar'] = $user['avatar'] ?: DEFAULT_AVATAR;

                // Записываем успешную попытку входа
                recordLoginAttempt(null, $username, true);

                if (function_exists('logAction')) {
                    logAction($user['id'], 'login_success', "Успешный вход пользователя: $username");
                }

                echo json_encode([
                    'status' => 'success',
                    'message' => 'Вход выполнен успешно',
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'avatar' => $user['avatar'] ?: DEFAULT_AVATAR,
                        'role' => $user['role']
                    ]
                ]);
                exit;
            }
        } else {
            // Записываем неудачную попытку входа
            recordLoginAttempt(null, $username, false);
            
            if (function_exists('logAction')) {
                logAction(0, 'login_failed_credentials', "Неудачная попытка входа для пользователя: $username");
            }
            
            // Проверяем, не превышен ли лимит после этой попытки
            $rateLimit = checkLoginRateLimit();
            $message = 'Неверный логин или пароль.';
            if (!$rateLimit['allowed']) {
                $message = $rateLimit['message'] ?? $message;
            } else if ($rateLimit['remaining_attempts'] <= 2) {
                $message .= ' Осталось попыток: ' . $rateLimit['remaining_attempts'];
            }
            
            die(json_encode(['status' => 'error', 'message' => $message]));
        }
    } else {
        // Записываем неудачную попытку входа
        recordLoginAttempt(null, $username, false);
        
        if (function_exists('logAction')) {
            logAction(0, 'login_failed_not_found', "Неудачная попытка входа (пользователь не найден): $username");
        }
        
        // Проверяем, не превышен ли лимит после этой попытки
        $rateLimit = checkLoginRateLimit();
        $message = 'Неверный логин или пароль.';
        if (!$rateLimit['allowed']) {
            $message = $rateLimit['message'] ?? $message;
        } else if ($rateLimit['remaining_attempts'] <= 2) {
            $message .= ' Осталось попыток: ' . $rateLimit['remaining_attempts'];
        }
        
        die(json_encode(['status' => 'error', 'message' => $message]));
    }
} catch (Exception $e) {
    error_log("Login API error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Произошла ошибка при входе. Попробуйте позже.']);
}
?>