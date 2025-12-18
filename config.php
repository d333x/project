<?php
// Защита от прямого доступа (если требуется, раскомментируйте)
// define('PROTECTED_ACCESS', true);
// if (!defined('PROTECTED_ACCESS')) {
//     die("Прямой доступ запрещен");
// }

// Запуск сессии должен быть самым первым действием, если нет других выводов
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Настройка отчетов об ошибках
error_reporting(E_ALL);
ini_set('display_errors', 1); // Отключаем вывод ошибок на экран для продакшена
ini_set('log_errors', 1);    // Включаем логирование ошибок
ini_set('error_log', __DIR__ . '/../php_errors.log'); // Путь к файлу логов ошибок

// Константы
define('DB_HOST', 'localhost');
define('DB_USER', '');
define('DB_PASS', '');
define('DB_NAME', '');
define('SITE_NAME', 'D3X Project');
define('ROLE_USER', 1);
define('ROLE_MODER', 2);
define('ROLE_ADMIN', 3);
define('DEFAULT_AVATAR', 'assets/default.png'); // Исправлен путь к аватару по умолчанию
define('ONLINE_THRESHOLD_MINUTES', 5); // Порог активности для "онлайн" в минутах
define('TYPING_THRESHOLD_SECONDS', 5); // Порог для статуса "печатает" в секундах

// Подключение к БД
function getDB() {
    static $db = null;
    
    if ($db === null) {
        try {
            $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            
            if ($db->connect_error) {
                // Логируем ошибку подключения к БД
                error_log("DB connection failed: " . $db->connect_error);
                // Прекращаем выполнение скрипта с сообщением об ошибке
                http_response_code(500);
                die("Database connection error. Please try again later.");
            }
            
            // Устанавливаем кодировку для корректной работы с UTF-8
            $db->set_charset("utf8mb4");
            // Устанавливаем collation для совместимости
            $db->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Exception $e) {
            // Логируем исключение при подключении к БД
            error_log("DB connection exception: " . $e->getMessage());
            http_response_code(500);
            die("Database error. Please try again later.");
        }
    }
    
    return $db;
}

// Функция проверки режима обслуживания
function isMaintenanceMode() {
    try {
        $db = getDB();
        // Проверяем существование таблицы site_settings
        $tableExists = $db->query("SHOW TABLES LIKE 'site_settings'")->num_rows > 0;
        if (!$tableExists) {
            error_log("Table 'site_settings' does not exist. Maintenance mode check skipped.");
            return false;
        }

        $result = $db->query("SELECT maintenance_mode FROM site_settings WHERE id = 1 LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            return (bool)$row['maintenance_mode'];
        }
        return false;
    } catch (Exception $e) {
        error_log("Maintenance check error: " . $e->getMessage());
        return false;
    }
}

// Функция проверки включен ли форум
function isForumEnabled() {
    try {
        $db = getDB();
        $tableExists = $db->query("SHOW TABLES LIKE 'site_settings'")->num_rows > 0;
        if (!$tableExists) {
            return true; // По умолчанию включен
        }

        $result = $db->query("SELECT enable_forum FROM site_settings WHERE id = 1 LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            return (bool)$row['enable_forum'];
        }
        return true; // По умолчанию включен
    } catch (Exception $e) {
        error_log("Forum check error: " . $e->getMessage());
        return true; // По умолчанию включен
    }
}

// Функция проверки включен ли магазин
function isShopEnabled() {
    try {
        $db = getDB();
        $tableExists = $db->query("SHOW TABLES LIKE 'site_settings'")->num_rows > 0;
        if (!$tableExists) {
            return true; // По умолчанию включен
        }

        $result = $db->query("SELECT enable_shop FROM site_settings WHERE id = 1 LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            return (bool)$row['enable_shop'];
        }
        return true; // По умолчанию включен
    } catch (Exception $e) {
        error_log("Shop check error: " . $e->getMessage());
        return true; // По умолчанию включен
    }
}

// Функция получения IP адреса пользователя
function getUserIP() {
    $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Функция инициализации таблицы для rate limiting
function initLoginAttemptsTable() {
    try {
        $db = getDB();
        $tableExists = $db->query("SHOW TABLES LIKE 'login_attempts'")->num_rows > 0;
        if (!$tableExists) {
            $db->query("CREATE TABLE IF NOT EXISTS login_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                username VARCHAR(255) DEFAULT NULL,
                success TINYINT(1) DEFAULT 0,
                INDEX idx_ip_time (ip_address, attempt_time),
                INDEX idx_ip (ip_address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    } catch (Exception $e) {
        error_log("Error initializing login_attempts table: " . $e->getMessage());
    }
}

// Функция проверки rate limit для логина
// Возвращает: ['allowed' => bool, 'remaining_attempts' => int, 'lockout_time' => int (seconds)]
function checkLoginRateLimit($ip_address = null, $max_attempts = 5, $time_window = 900, $lockout_duration = 1800) {
    try {
        $db = getDB();
        initLoginAttemptsTable();
        
        if ($ip_address === null) {
            $ip_address = getUserIP();
        }
        
        // Очищаем старые записи (старше времени блокировки)
        $db->query("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL $lockout_duration SECOND)");
        
        // Проверяем количество неудачных попыток за последний период
        $stmt = $db->prepare("
            SELECT COUNT(*) as failed_count, 
                   MAX(attempt_time) as last_attempt
            FROM login_attempts 
            WHERE ip_address = ? 
            AND success = 0 
            AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->bind_param("si", $ip_address, $time_window);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        
        $failed_count = (int)($data['failed_count'] ?? 0);
        $last_attempt = $data['last_attempt'] ?? null;
        
        // Если превышен лимит попыток
        if ($failed_count >= $max_attempts) {
            if ($last_attempt) {
                $last_attempt_time = strtotime($last_attempt);
                $elapsed = time() - $last_attempt_time;
                $remaining_lockout = $lockout_duration - $elapsed;
                
                if ($remaining_lockout > 0) {
                    return [
                        'allowed' => false,
                        'remaining_attempts' => 0,
                        'lockout_time' => $remaining_lockout,
                        'message' => "Слишком много неудачных попыток входа. Попробуйте снова через " . ceil($remaining_lockout / 60) . " минут."
                    ];
                } else {
                    // Время блокировки истекло, сбрасываем счетчик
                    $stmt_delete = $db->prepare("DELETE FROM login_attempts WHERE ip_address = ? AND success = 0");
                    $stmt_delete->bind_param("s", $ip_address);
                    $stmt_delete->execute();
                    return [
                        'allowed' => true,
                        'remaining_attempts' => $max_attempts,
                        'lockout_time' => 0
                    ];
                }
            }
        }
        
        return [
            'allowed' => true,
            'remaining_attempts' => max(0, $max_attempts - $failed_count),
            'lockout_time' => 0
        ];
    } catch (Exception $e) {
        error_log("Error checking login rate limit: " . $e->getMessage());
        // В случае ошибки разрешаем попытку входа
        return [
            'allowed' => true,
            'remaining_attempts' => $max_attempts,
            'lockout_time' => 0
        ];
    }
}

// Функция записи попытки входа
function recordLoginAttempt($ip_address = null, $username = null, $success = false) {
    try {
        $db = getDB();
        initLoginAttemptsTable();
        
        if ($ip_address === null) {
            $ip_address = getUserIP();
        }
        
        $stmt = $db->prepare("INSERT INTO login_attempts (ip_address, username, success) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $ip_address, $username, $success);
        $stmt->execute();
        
        // Если вход успешен, очищаем неудачные попытки для этого IP
        if ($success) {
            $stmt_delete = $db->prepare("DELETE FROM login_attempts WHERE ip_address = ? AND success = 0");
            $stmt_delete->bind_param("s", $ip_address);
            $stmt_delete->execute();
        }
    } catch (Exception $e) {
        error_log("Error recording login attempt: " . $e->getMessage());
    }
}

// Проверка режима обслуживания
// Если режим обслуживания включен и пользователь не является администратором, перенаправляем на страницу обслуживания
if (isMaintenanceMode() && !(isset($_SESSION['role']) && $_SESSION['role'] >= ROLE_ADMIN)) {
    // Проверяем, не является ли текущий запрос уже страницей обслуживания, чтобы избежать бесконечного цикла
    if (basename($_SERVER['PHP_SELF']) !== 'maintenance.php') {
        include __DIR__ . '/maintenance.php';
        exit;
    }
}

// Получение количества онлайн-пользователей
function getOnlineUsersCount() {
    try {
        $db = getDB();
        if (!$db || $db->connect_error) {
            error_log("Database connection error in getOnlineUsersCount");
            return "N/A";
        }
        
        // Проверяем существование столбца last_activity в таблице users
        $checkColumn = $db->query("SHOW COLUMNS FROM users LIKE 'last_activity'");
        if ($checkColumn->num_rows === 0) {
            error_log("Column 'last_activity' doesn't exist in 'users' table. Please run database migrations.");
            return "N/A";
        }
        
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE last_activity > NOW() - INTERVAL ? MINUTE AND banned = 0");
        $threshold = ONLINE_THRESHOLD_MINUTES;
        $stmt->bind_param("i", $threshold);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result) {
            error_log("Query error in getOnlineUsersCount: " . $db->error);
            return "N/A";
        }
        
        return $result->fetch_assoc()['count'];
    } catch (Exception $e) {
        error_log("getOnlineUsersCount error: " . $e->getMessage());
        return "N/A";
    }
}

// Проверка авторизации с проверкой бана
function isLoggedIn() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start(); // Убедимся, что сессия запущена
    }

    if (empty($_SESSION['user_id'])) {
        return false;
    }
    
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT banned, role, username, avatar FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            // Обновляем данные сессии на случай, если они изменились в БД
            $_SESSION['role'] = $user['role'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['avatar'] = $user['avatar'] ?? DEFAULT_AVATAR;
            return ($user['banned'] == 0); // Возвращаем true, если пользователь не забанен
        } else {
            // Если пользователь не найден или забанен, очищаем сессию
            session_unset();
            session_destroy();
            return false;
        }
    } catch (Exception $e) {
        error_log("isLoggedIn check error: " . $e->getMessage());
        return false;
    }
}

// Проверка админских прав
function checkAdminPrivileges() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
    
    if ($_SESSION['role'] < ROLE_ADMIN) {
        $_SESSION['error'] = "У вас недостаточно прав для доступа к этой странице.";
        header("Location: chat.php"); // Перенаправляем на чат, если нет прав
        exit;
    }
}

// Проверка прав модератора
function checkModerPrivileges() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
    
    if ($_SESSION['role'] < ROLE_MODER && $_SESSION['role'] < ROLE_ADMIN) {
        $_SESSION['error'] = "У вас недостаточно прав для доступа к этой странице.";
        header("Location: chat.php"); // Перенаправляем на чат, если нет прав
        exit;
    }
}

// Проверка на мобильное устройство (для адаптивного дизайна)
function isMobileDevice() {
    return preg_match("/(android|iphone|ipod|ipad|mobile)/i", $_SERVER['HTTP_USER_AGENT']);
}

/**
 * Создать уведомление для модераторов о новой жалобе
 * @param int $report_id ID созданной жалобы
 */
function createModeratorNotification($report_id) {
    $db = getDB();
    
    // Получаем всех активных модераторов
    $stmt = $db->prepare("SELECT id FROM users WHERE role >= ? AND banned = 0");
    $moderRole = ROLE_MODER;
    $stmt->bind_param("i", $moderRole);
    $stmt->execute();
    $moderators = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($moderators as $moder) {
        $stmt_insert = $db->prepare("INSERT INTO moderator_notifications (moderator_id, report_id) VALUES (?, ?)");
        $stmt_insert->bind_param("ii", $moder['id'], $report_id);
        $stmt_insert->execute();
    }
}

/**
 * Получить непрочитанные уведомления для текущего модератора
 * @param int $moderator_id ID модератора
 * @return array Массив непрочитанных уведомлений
 */
function getUnreadModeratorNotifications($moderator_id) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT mn.*, r.reason, u.username as reporter_name, u2.username as reported_name
        FROM moderator_notifications mn
        JOIN reports r ON mn.report_id = r.id
        JOIN users u ON r.reporter_id = u.id
        JOIN users u2 ON r.reported_user_id = u2.id
        WHERE mn.moderator_id = ? AND mn.is_read = FALSE
        ORDER BY mn.created_at DESC
    ");
    $stmt->bind_param("i", $moderator_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Пометить уведомления как прочитанные
 * @param int $moderator_id ID модератора
 * @param int|null $report_id ID конкретной жалобы (если null, то все уведомления)
 */
function markNotificationsAsRead($moderator_id, $report_id = null) {
    $db = getDB();
    if ($report_id) {
        $stmt = $db->prepare("UPDATE moderator_notifications SET is_read = TRUE WHERE moderator_id = ? AND report_id = ?");
        $stmt->bind_param("ii", $moderator_id, $report_id);
    } else {
        $stmt = $db->prepare("UPDATE moderator_notifications SET is_read = TRUE WHERE moderator_id = ?");
        $stmt->bind_param("i", $moderator_id);
    }
    $stmt->execute();
}

/**
 * Получить количество непрочитанных сообщений для пользователя
 * @param int $user_id ID пользователя
 * @return int Количество непрочитанных сообщений
 */
function getUnreadMessagesCount($user_id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM messages WHERE receiver_id = ? AND is_read = FALSE");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc()['count'];
}

// Санитизация входных данных
function sanitize($input) {
    // Используем ENT_QUOTES для преобразования как одинарных, так и двойных кавычек
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Создание папки avatars, если не существует, и копирование аватара по умолчанию
if (!file_exists('avatars')) {
    @mkdir('avatars', 0755, true);
}
// Проверяем, существует ли файл аватара по умолчанию в папке assets
if (!file_exists(DEFAULT_AVATAR)) {
    // Если нет, пытаемся скопировать его из assets/default-avatar.png
    // Убедитесь, что assets/default-avatar.png существует
    if (file_exists(__DIR__ . '/../assets/default-avatar.png')) {
        @copy(__DIR__ . '/../assets/default-avatar.png', DEFAULT_AVATAR);
    } else {
        error_log("Default avatar source file not found: " . __DIR__ . '/../assets/default-avatar.png');
    }
}

// Проверяем права на запись для папки avatars
if (!is_writable('avatars')) {
    error_log("Avatars directory is not writable: " . __DIR__ . '/avatars');
}

/**
 * Создать новую жалобу
 * @param int $reporter_id ID пользователя, который жалуется
 * @param int $reported_user_id ID пользователя, на которого жалуются
 * @param string $reason Причина жалобы
 * @param int|null $message_id ID сообщения, если жалоба на сообщение
 * @return bool True в случае успеха, false в противном случае
 */
function createReport($reporter_id, $reported_user_id, $reason, $message_id = null) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO reports (reporter_id, reported_user_id, message_id, reason) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiis", $reporter_id, $reported_user_id, $message_id, $reason);
    if ($stmt->execute()) {
        createModeratorNotification($db->insert_id); // Создаем уведомления для модераторов
        return true;
    }
    error_log("Failed to create report: " . $db->error);
    return false;
}

/**
 * Получить жалобы для модерации
 * @param string $status Статус жалобы ('pending', 'approved', 'rejected', 'forwarded', 'all')
 * @param int $limit Максимальное количество жалоб
 * @return array Массив жалоб
 */
function getReports($status = 'pending', $limit = 100) {
    $db = getDB();
    $query = "
        SELECT r.*, u1.username as reporter_name, u2.username as reported_name 
        FROM reports r
        JOIN users u1 ON r.reporter_id = u1.id
        JOIN users u2 ON r.reported_user_id = u2.id
    ";
    $params = [];
    $types = "";

    if ($status !== 'all') {
        $query .= " WHERE r.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    $query .= " ORDER BY r.created_at DESC LIMIT ?";
    $params[] = $limit;
    $types .= "i";

    $stmt = $db->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Получить имя пользователя по ID
 * @param int $user_id ID пользователя
 * @return string Имя пользователя или 'Unknown'
 */
function getUsername($user_id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0 ? $result->fetch_assoc()['username'] : 'Unknown';
}

/**
 * Получить топ пользователей по жалобам
 * @param int $limit Количество пользователей в топе
 * @return array Массив пользователей
 */
function getMostReportedUsers($limit = 10) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT u.id, u.username, COUNT(r.id) as reports_count
        FROM users u
        JOIN reports r ON u.id = r.reported_user_id
        WHERE r.status = 'pending'
        GROUP BY u.id
        ORDER BY reports_count DESC
        LIMIT ?
    ");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Обработка жалобы модератором
 * @param int $report_id ID жалобы
 * @param int $moderator_id ID модератора
 * @param string $action Тип действия ('warn', 'freeze', 'ban', 'reject', 'delete_message')
 * @param string $comment Дополнительный комментарий
 * @return bool True в случае успеха, false в противном случае
 */
function processReport($report_id, $moderator_id, $action, $comment = '') {
    $db = getDB();
    
    $db->begin_transaction(); // Начинаем транзакцию
    
    try {
        // 1. Получаем данные о жалобе
        $stmt = $db->prepare("SELECT reported_user_id, message_id FROM reports WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $report_id);
        $stmt->execute();
        $report = $stmt->get_result()->fetch_assoc();
        
        if (!$report) {
            throw new Exception("Report not found.");
        }
        
        $reported_user_id = $report['reported_user_id'];
        $message_id = $report['message_id'];

        // 2. Определяем статус жалобы и тип действия для лога
        $status = 'pending'; // По умолчанию
        $action_type_log = '';
        $action_description = '';

        switch ($action) {
            case 'warn':
                $status = 'approved';
                $action_type_log = 'warning';
                $action_description = "Выдано предупреждение пользователю ID: $reported_user_id по жалобе ID: $report_id";
                break;
            case 'freeze':
                $status = 'approved';
                $action_type_log = 'freeze';
                $action_description = "Аккаунт пользователя ID: $reported_user_id заморожен по жалобе ID: $report_id";
                $db->query("UPDATE users SET banned = 1 WHERE id = $reported_user_id"); // Замораживаем аккаунт
                break;
            case 'ban':
                $status = 'forwarded'; // Передано администратору
                $action_type_log = 'ban_proposal';
                $action_description = "Предложен бан пользователя ID: $reported_user_id по жалобе ID: $report_id";
                break;
            case 'reject':
                $status = 'rejected';
                $action_type_log = 'report_rejected';
                $action_description = "Жалоба ID: $report_id отклонена";
                break;
            case 'delete_message':
                if ($message_id) {
                    $message_data = $db->query("SELECT sender_id, receiver_id, message FROM messages WHERE id = $message_id LIMIT 1")->fetch_assoc();
                    if ($message_data) {
                        logChatMessage($message_data['sender_id'], $message_data['receiver_id'], "[УДАЛЕНО МОДЕРАТОРОМ] " . $message_data['message']);
                        $db->query("DELETE FROM messages WHERE id = $message_id");
                        $status = 'approved'; // Жалоба считается обработанной
                        $action_type_log = 'message_deleted';
                        $action_description = "Сообщение ID: $message_id удалено по жалобе ID: $report_id";
                    } else {
                        throw new Exception("Message not found for deletion.");
                    }
                } else {
                    throw new Exception("Message ID not provided for delete_message action.");
                }
                break;
            default:
                throw new Exception("Invalid moderation action.");
        }

        // 3. Обновляем статус жалобы в таблице reports
        $stmt_update_report = $db->prepare("UPDATE reports SET status = ?, moderator_id = ?, action_taken = ? WHERE id = ?");
        $full_action_taken = $action_description . ($comment ? " (Комментарий: $comment)" : "");
        $stmt_update_report->bind_param("sisi", $status, $moderator_id, $full_action_taken, $report_id);
        $stmt_update_report->execute();

        // 4. Записываем действие модерации в action_logs
        logAction($moderator_id, $action_type_log, $action_description);

        $db->commit(); // Подтверждаем транзакцию
        return true;
    } catch (Exception $e) {
        $db->rollback(); // Откатываем транзакцию в случае ошибки
        error_log("Moderation processReport error: " . $e->getMessage());
        return false;
    }
}

/**
 * Обновляет последнюю активность пользователя и логирует IP-адрес
 * @param int $user_id ID пользователя
 */
function updateUserActivity($user_id) {
    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR']; // Получаем IP-адрес пользователя
    
    // Обновляем last_activity пользователя
    $stmt_activity = $db->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
    $stmt_activity->bind_param("i", $user_id);
    $stmt_activity->execute();

    // Вставляем или обновляем IP-адрес пользователя
    $stmt_ip = $db->prepare("INSERT INTO user_ips (user_id, ip_address, last_seen) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE last_seen = NOW()");
    $stmt_ip->bind_param("is", $user_id, $ip);
    $stmt_ip->execute();
}

// Вызываем при каждом запросе для авторизованных пользователей
if (isLoggedIn()) {
    updateUserActivity($_SESSION['user_id']);
}

/**
 * Логирование действий пользователей и системы
 * @param int $user_id ID пользователя, совершившего действие
 * @param string $action_type Тип действия (например, 'login', 'send_message', 'ban_user')
 * @param string $description Подробное описание действия
 */
function logAction($user_id, $action_type, $description) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO action_logs (user_id, action_type, description) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $user_id, $action_type, $description);
    $stmt->execute();
}

/**
 * Логирование сообщений чата (для модерации и аудита)
 * @param int $sender_id ID отправителя
 * @param int $receiver_id ID получателя
 * @param string $message_content Содержание сообщения
 */
function logChatMessage($sender_id, $receiver_id, $message_content) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO chat_logs (sender_id, receiver_id, message_content) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $sender_id, $receiver_id, $message_content);
    $stmt->execute();
}
?>
