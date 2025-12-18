<?php
// Запуск сессии должен быть самым первым действием
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . '/config.php'; // Исправлен путь к config.php
checkAdminPrivileges(); // Проверка прав администратора

$db = getDB();
$current_admin_id = $_SESSION['user_id'];

// Fetch all site settings
$site_settings_result = $db->query("SELECT * FROM site_settings LIMIT 1");
if ($site_settings_result && $site_settings_result->num_rows > 0) {
    $site_settings = $site_settings_result->fetch_assoc();
    // Преобразуем булевы значения из БД (могут быть 0/1 или true/false)
    $site_settings['maintenance_mode'] = (bool)$site_settings['maintenance_mode'];
    $site_settings['registration_keys_required'] = (bool)$site_settings['registration_keys_required'];
    $site_settings['allow_user_registration'] = (bool)$site_settings['allow_user_registration'];
    $site_settings['enable_forum'] = (bool)($site_settings['enable_forum'] ?? true);
    $site_settings['enable_shop'] = (bool)($site_settings['enable_shop'] ?? true);
} else {
    // Если настроек нет, создаем запись с настройками по умолчанию
    $default_settings = [
        'maintenance_mode' => 0,
        'registration_keys_required' => 1,
        'site_name' => 'D3x Messenger',
        'allow_user_registration' => 1,
        'max_message_length' => 500,
        'welcome_message' => 'Добро пожаловать в D3x Messenger!',
        'default_user_role' => 1,
        'max_avatar_size_mb' => 2,
        'max_invites_per_user' => 5,
        'enable_forum' => 1,
        'enable_shop' => 1
    ];
    
    // Создаем запись в БД
    try {
        $stmt = $db->prepare("INSERT INTO site_settings (
            maintenance_mode, registration_keys_required, site_name, allow_user_registration,
            max_message_length, welcome_message, default_user_role, max_avatar_size_mb,
            max_invites_per_user, enable_forum, enable_shop
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if ($stmt) {
            $stmt->bind_param(
                "iisiiisiiii",
                $default_settings['maintenance_mode'],
                $default_settings['registration_keys_required'],
                $default_settings['site_name'],
                $default_settings['allow_user_registration'],
                $default_settings['max_message_length'],
                $default_settings['welcome_message'],
                $default_settings['default_user_role'],
                $default_settings['max_avatar_size_mb'],
                $default_settings['max_invites_per_user'],
                $default_settings['enable_forum'],
                $default_settings['enable_shop']
            );
            $stmt->execute();
        }
    } catch (Exception $e) {
        error_log("Error creating default site settings: " . $e->getMessage());
    }
    
    // Используем настройки по умолчанию
    $site_settings = array_map(function($val) {
        return is_numeric($val) && $val <= 1 ? (bool)$val : $val;
    }, $default_settings);
}

// These settings are now derived from $site_settings
$maintenance_mode = $site_settings['maintenance_mode'];
$registration_keys_required = $site_settings['registration_keys_required'];

// --- Handle POST requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Защита от CSRF (базовая, для полной защиты нужен токен)
    // if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    //     $_SESSION['error'] = "Ошибка безопасности: неверный токен CSRF.";
    //     header("Location: admin.php" . (isset($_GET['tab']) ? "?tab=" . $_GET['tab'] : ""));
    //     exit;
    // }

    if (isset($_POST['update_site_settings'])) {
        try {
            // Получаем все значения из формы
            $site_name = sanitize($_POST['site_name'] ?? '');
            $allow_user_registration = isset($_POST['allow_user_registration']) ? 1 : 0;
            $max_message_length = (int)($_POST['max_message_length'] ?? 500);
            $welcome_message = sanitize($_POST['welcome_message'] ?? '');
            $default_user_role = (int)($_POST['default_user_role'] ?? 1);
            $max_avatar_size_mb = (int)($_POST['max_avatar_size_mb'] ?? 2);
            $max_invites_per_user = (int)($_POST['max_invites_per_user'] ?? 5);
            $enable_forum = isset($_POST['enable_forum']) ? 1 : 0;
            $enable_shop = isset($_POST['enable_shop']) ? 1 : 0;
            $maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;
            $registration_keys_required = isset($_POST['registration_keys_required']) ? 1 : 0;
            
            // Валидация
            if (empty($site_name)) {
                throw new Exception("Имя сайта не может быть пустым.");
            }
            if (empty($welcome_message)) {
                throw new Exception("Приветственное сообщение не может быть пустым.");
            }
            if ($max_message_length < 1 || $max_message_length > 2000) {
                throw new Exception("Максимальная длина сообщения должна быть от 1 до 2000 символов.");
            }
            if ($default_user_role < 1 || $default_user_role > 3) {
                throw new Exception("Недопустимая роль пользователя по умолчанию.");
            }
            if ($max_avatar_size_mb < 1 || $max_avatar_size_mb > 10) {
                throw new Exception("Максимальный размер аватара должен быть от 1 до 10 МБ.");
            }
            if ($max_invites_per_user < 0 || $max_invites_per_user > 50) {
                throw new Exception("Максимальное количество инвайтов на пользователя должно быть от 0 до 50.");
            }
 
            // Обновляем все настройки одним запросом
            $stmt = $db->prepare("UPDATE site_settings SET
                site_name = ?,
                allow_user_registration = ?,
                max_message_length = ?,
                welcome_message = ?,
                default_user_role = ?,
                max_avatar_size_mb = ?,
                max_invites_per_user = ?,
                enable_forum = ?,
                enable_shop = ?,
                maintenance_mode = ?,
                registration_keys_required = ?
                WHERE id = 1");
            
            if (!$stmt) {
                throw new Exception("Ошибка подготовки запроса: " . $db->error);
            }
            
            $stmt->bind_param(
                "siiisiiiiii",
                $site_name,
                $allow_user_registration,
                $max_message_length,
                $welcome_message,
                $default_user_role,
                $max_avatar_size_mb,
                $max_invites_per_user,
                $enable_forum,
                $enable_shop,
                $maintenance_mode,
                $registration_keys_required
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Ошибка выполнения запроса: " . $stmt->error);
            }
            
            // Обновляем локальные переменные для отображения
            $site_settings['site_name'] = $site_name;
            $site_settings['allow_user_registration'] = $allow_user_registration;
            $site_settings['max_message_length'] = $max_message_length;
            $site_settings['welcome_message'] = $welcome_message;
            $site_settings['default_user_role'] = $default_user_role;
            $site_settings['max_avatar_size_mb'] = $max_avatar_size_mb;
            $site_settings['max_invites_per_user'] = $max_invites_per_user;
            $site_settings['enable_forum'] = $enable_forum;
            $site_settings['enable_shop'] = $enable_shop;
            $site_settings['maintenance_mode'] = $maintenance_mode;
            $site_settings['registration_keys_required'] = $registration_keys_required;
            
            $_SESSION['success'] = "Настройки сайта успешно обновлены!";
            logAction($current_admin_id, 'update_site_settings', 'Настройки сайта обновлены');
            
            // Перезагружаем настройки из БД
            $site_settings_result = $db->query("SELECT * FROM site_settings LIMIT 1");
            if ($site_settings_result && $site_settings_result->num_rows > 0) {
                $site_settings = $site_settings_result->fetch_assoc();
                $site_settings['maintenance_mode'] = (bool)$site_settings['maintenance_mode'];
                $site_settings['registration_keys_required'] = (bool)$site_settings['registration_keys_required'];
                $site_settings['allow_user_registration'] = (bool)$site_settings['allow_user_registration'];
                $site_settings['enable_forum'] = (bool)($site_settings['enable_forum'] ?? true);
                $site_settings['enable_shop'] = (bool)($site_settings['enable_shop'] ?? true);
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Ошибка при обновлении настроек сайта: " . $e->getMessage();
            error_log("Site settings update error: " . $e->getMessage());
        }
    } elseif (isset($_POST['change_role'])) {
        $user_id = (int)$_POST['user_id'];
        $new_role = (int)$_POST['new_role'];

        if ($user_id === $current_admin_id && $new_role < ROLE_ADMIN) {
            $_SESSION['error'] = "Вы не можете понизить свою роль!";
        } else {
            try {
                $stmt = $db->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->bind_param("ii", $new_role, $user_id);
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Роль пользователя изменена";
                    logAction($current_admin_id, 'change_user_role', "Изменена роль пользователя ID: $user_id на $new_role");
                } else {
                    $_SESSION['error'] = "Ошибка при изменении роли: " . $db->error;
                }
            } catch (Exception $e) {
                $_SESSION['error'] = "Ошибка при изменении роли: " . $e->getMessage();
                error_log("Change role error: " . $e->getMessage());
            }
        }
    } elseif (isset($_POST['generate_keys'])) {
        $count = (int)$_POST['key_count'];
        $creator_id = $_SESSION['user_id'];
        
        if ($count <= 0 || $count > 50) {
            $_SESSION['error'] = "Количество ключей должно быть от 1 до 50.";
        } else {
            $db->begin_transaction();
            try {
                $stmt = $db->prepare("INSERT INTO access_keys (key_value, creator_id) VALUES (?, ?)");
                for ($i = 0; $i < $count; $i++) {
                    $key = bin2hex(random_bytes(16)); // Генерируем случайный ключ
                    $stmt->bind_param("si", $key, $creator_id);
                    $stmt->execute();
                }
                $db->commit();
                $_SESSION['success'] = "Успешно сгенерировано $count инвайт-кодов";
                logAction($current_admin_id, 'generate_invites', "Сгенерировано $count инвайт-кодов");
            } catch (Exception $e) {
                $db->rollback();
                $_SESSION['error'] = "Ошибка при генерации кодов: " . $e->getMessage();
                error_log("Generate keys error: " . $e->getMessage());
            }
        }
    } elseif (isset($_POST['assign_to_user'])) {
        $user_id = (int)$_POST['user_id'];
        $count = (int)$_POST['key_count'];
        $creator_id = $_SESSION['user_id'];
        
        if ($user_id <= 0 || $count <= 0 || $count > 10) {
            $_SESSION['error'] = "Неверные данные для выдачи ключей.";
        } else {
            $db->begin_transaction();
            try {
                $stmt_check_user = $db->prepare("SELECT id FROM users WHERE id = ? AND banned = 0 LIMIT 1");
                $stmt_check_user->bind_param("i", $user_id);
                $stmt_check_user->execute();
                if ($stmt_check_user->get_result()->num_rows === 0) {
                    throw new Exception("Пользователь не найден или забанен.");
                }

                $stmt = $db->prepare("INSERT INTO access_keys (key_value, creator_id, assigned_to) VALUES (?, ?, ?)");
                for ($i = 0; $i < $count; $i++) {
                    $key = bin2hex(random_bytes(16));
                    $stmt->bind_param("sii", $key, $creator_id, $user_id);
                    $stmt->execute();
                }
                $db->commit();
                $_SESSION['success'] = "Успешно создано $count ключей для пользователя ID: $user_id";
                logAction($current_admin_id, 'assign_invites', "Выдано $count ключей пользователю ID: $user_id");
            } catch (Exception $e) {
                $db->rollback();
                $_SESSION['error'] = "Ошибка: " . $e->getMessage();
                error_log("Assign keys error: " . $e->getMessage());
            }
        }
    } elseif (isset($_POST['generate_for_all'])) {
        $count_per_user = (int)$_POST['keys_per_user'];
        $creator_id = $_SESSION['user_id'];
        
        if ($count_per_user <= 0 || $count_per_user > 5) {
            $_SESSION['error'] = "Количество ключей на пользователя должно быть от 1 до 5.";
        } else {
            $db->begin_transaction();
            try {
                $users_to_assign = $db->query("SELECT id FROM users WHERE banned = 0 AND id != $creator_id")->fetch_all(MYSQLI_ASSOC);
                
                $stmt = $db->prepare("INSERT INTO access_keys (key_value, creator_id, assigned_to) VALUES (?, ?, ?)");
                $total_generated = 0;
                foreach ($users_to_assign as $user) {
                    for ($i = 0; $i < $count_per_user; $i++) {
                        $key = bin2hex(random_bytes(16));
                        $stmt->bind_param("sii", $key, $creator_id, $user['id']);
                        $stmt->execute();
                        $total_generated++;
                    }
                }
                
                $db->commit();
                $_SESSION['success'] = "Успешно создано $total_generated ключей для всех пользователей";
                logAction($current_admin_id, 'generate_invites_all', "Сгенерировано $total_generated ключей для всех пользователей");
            } catch (Exception $e) {
                $db->rollback();
                $_SESSION['error'] = "Ошибка: " . $e->getMessage();
                error_log("Generate for all keys error: " . $e->getMessage());
            }
        }
    } elseif (isset($_POST['send_announcement'])) {
        $message = trim($_POST['announcement_text']);
        $admin_id = $_SESSION['user_id'];
        
        if (empty($message)) {
            $_SESSION['error'] = "Текст анонса не может быть пустым.";
        } else {
            $db->begin_transaction();
            try {
                $users_to_announce = $db->query("SELECT id FROM users WHERE banned = 0 AND id != $admin_id")->fetch_all(MYSQLI_ASSOC);
                
                $stmt = $db->prepare("INSERT INTO messages (sender_id, receiver_id, message, is_announcement, created_at) VALUES (?, ?, ?, 1, NOW())");
                foreach ($users_to_announce as $user) {
                    $stmt->bind_param("iis", $admin_id, $user['id'], $message);
                    $stmt->execute();
                }
                
                $db->commit();
                $_SESSION['success'] = "Анонс отправлен " . count($users_to_announce) . " пользователям";
                logAction($current_admin_id, 'send_announcement', "Отправлен анонс: '$message'");
            } catch (Exception $e) {
                $db->rollback();
                $_SESSION['error'] = "Ошибка при отправке анонса: " . $e->getMessage();
                error_log("Send announcement error: " . $e->getMessage());
            }
        }
    }
    // Redirect to current page to clear POST data
    header("Location: admin.php" . (isset($_GET['tab']) ? "?tab=" . $_GET['tab'] : ""));
    exit;
}

// --- Handle GET requests for actions ---
if (isset($_GET['approve_ban']) && isset($_GET['ban_user'])) {
    $user_id = (int)$_GET['ban_user'];
    $report_id = (int)$_GET['approve_ban'];
    
    $db->begin_transaction();
    try {
        $stmt_ban = $db->prepare("UPDATE users SET banned = 1 WHERE id = ?");
        $stmt_ban->bind_param("i", $user_id);
        $stmt_ban->execute();

        $stmt_report = $db->prepare("UPDATE reports SET status = 'approved', moderator_id = ?, action_taken = 'Бан подтвержден администратором' WHERE id = ?");
        $stmt_report->bind_param("ii", $current_admin_id, $report_id);
        $stmt_report->execute();

        $stmt_action = $db->prepare("INSERT INTO moderation_actions (user_id, moderator_id, action_type, reason) VALUES (?, ?, 'perma_ban', 'Бан подтвержден администратором')");
        $stmt_action->bind_param("ii", $user_id, $current_admin_id);
        $stmt_action->execute();

        logAction($current_admin_id, 'approve_ban', "Подтвержден бан пользователя ID: $user_id по жалобе ID: $report_id");
        
        $db->commit();
        $_SESSION['success'] = "Пользователь успешно забанен";
    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['error'] = "Ошибка при подтверждении бана: " . $e->getMessage();
        error_log("Approve ban error: " . $e->getMessage());
    }
    header("Location: admin.php?tab=moderation");
    exit;
}
if (isset($_GET['reject_ban'])) {
    $report_id = (int)$_GET['reject_ban'];
    try {
        $stmt = $db->prepare("UPDATE reports SET status = 'rejected', moderator_id = ?, action_taken = 'Предложение бана отклонено администратором' WHERE id = ?");
        $stmt->bind_param("ii", $current_admin_id, $report_id);
        $stmt->execute();
        $_SESSION['success'] = "Предложение бана отклонено";
        logAction($current_admin_id, 'reject_ban_proposal', "Отклонено предложение бана по жалобе ID: $report_id");
    } catch (Exception $e) {
        $_SESSION['error'] = "Ошибка при отклонении бана: " . $e->getMessage();
        error_log("Reject ban error: " . $e->getMessage());
    }
    header("Location: admin.php?tab=moderation");
    exit;
}
if (isset($_GET['ban'])) {
    $user_id = (int)$_GET['ban'];
    if ($user_id === $current_admin_id) {
        $_SESSION['error'] = "Вы не можете забанить себя!";
    } else {
        try {
            $stmt = $db->prepare("UPDATE users SET banned = 1 WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $_SESSION['success'] = "Пользователь забанен";
            logAction($current_admin_id, 'ban_user', "Забанен пользователь ID: $user_id");
        } catch (Exception $e) {
            $_SESSION['error'] = "Ошибка при бане пользователя: " . $e->getMessage();
            error_log("Direct ban error: " . $e->getMessage());
        }
    }
    header("Location: admin.php?tab=users");
    exit;
}
if (isset($_GET['unban'])) {
    $user_id = (int)$_GET['unban'];
    try {
        $stmt = $db->prepare("UPDATE users SET banned = 0 WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $_SESSION['success'] = "Пользователь разбанен";
        logAction($current_admin_id, 'unban_user', "Разбанен пользователь ID: $user_id");
    } catch (Exception $e) {
        $_SESSION['error'] = "Ошибка при разбане пользователя: " . $e->getMessage();
        error_log("Unban error: " . $e->getMessage());
    }
    header("Location: admin.php?tab=users");
    exit;
}
if (isset($_GET['delete'])) {
    $user_id = (int)$_GET['delete'];
    if ($user_id === $current_admin_id) {
        $_SESSION['error'] = "Вы не можете удалить себя!";
    } else {
        $db->begin_transaction();
        try {
            // Получаем путь к аватару пользователя для удаления
            $stmt_avatar = $db->prepare("SELECT avatar FROM users WHERE id = ? LIMIT 1");
            $stmt_avatar->bind_param("i", $user_id);
            $stmt_avatar->execute();
            $user_avatar = $stmt_avatar->get_result()->fetch_assoc()['avatar'] ?? null;

            // Удаляем связанные записи
            $db->query("DELETE FROM messages WHERE sender_id = $user_id OR receiver_id = $user_id");
            $db->query("DELETE FROM reports WHERE reporter_id = $user_id OR reported_user_id = $user_id");
            $db->query("DELETE FROM access_keys WHERE creator_id = $user_id OR used_by = $user_id OR assigned_to = $user_id");
            $db->query("DELETE FROM moderation_actions WHERE user_id = $user_id OR moderator_id = $user_id");
            $db->query("DELETE FROM user_ips WHERE user_id = $user_id");
            $db->query("DELETE FROM typing_indicators WHERE user_id = $user_id");
            $db->query("DELETE FROM forum_topics WHERE user_id = $user_id");
            $db->query("DELETE FROM forum_comments WHERE user_id = $user_id");
            $db->query("DELETE FROM action_logs WHERE user_id = $user_id");
            $db->query("DELETE FROM chat_logs WHERE sender_id = $user_id OR receiver_id = $user_id");
            $db->query("DELETE FROM admin_notifications WHERE user_id = $user_id");
            $db->query("DELETE FROM moderator_notifications WHERE moderator_id = $user_id");

            // Удаляем самого пользователя
            $db->query("DELETE FROM users WHERE id = $user_id");
            
            // Удаляем файл аватара, если он не является аватаром по умолчанию
            if ($user_avatar && $user_avatar !== DEFAULT_AVATAR && file_exists($user_avatar)) {
                unlink($user_avatar);
            }

            $db->commit();
            $_SESSION['success'] = "Пользователь успешно удален";
            logAction($current_admin_id, 'delete_user', "Удален пользователь ID: $user_id");
        } catch (Exception $e) {
            $db->rollback();
            $_SESSION['error'] = "Ошибка при удалении пользователя: " . $e->getMessage();
            error_log("Delete user error: " . $e->getMessage());
        }
    }
    header("Location: admin.php?tab=users");
    exit;
}

// --- Data Fetching ---
// These settings are now fetched as part of $site_settings
$maintenance_mode = $site_settings['maintenance_mode'];
$registration_keys_required = $site_settings['registration_keys_required'];

// Users
$search_users = isset($_GET['search_users']) ? sanitize($_GET['search_users']) : '';
$user_search_condition = $search_users ? "WHERE username LIKE '%" . $db->real_escape_string($search_users) . "%'" : '';
$users = $db->query("
    SELECT id, username, role, banned, created_at 
    FROM users 
    $user_search_condition
    ORDER BY role DESC, username ASC
")->fetch_all(MYSQLI_ASSOC);

// Invite Keys
$per_page_keys = isset($_GET['per_page_keys']) ? max(1, (int)$_GET['per_page_keys']) : 10;
$page_keys = isset($_GET['page_keys']) ? max(1, (int)$_GET['page_keys']) : 1;
$offset_keys = ($page_keys - 1) * $per_page_keys;
$keys = $db->query("
    SELECT k.*, u1.username as creator_name, u2.username as used_by_name, u3.username as assigned_to_name
    FROM access_keys k
    LEFT JOIN users u1 ON k.creator_id = u1.id
    LEFT JOIN users u2 ON k.used_by = u2.id
    LEFT JOIN users u3 ON k.assigned_to = u3.id
    ORDER BY k.created_at DESC
    LIMIT $per_page_keys OFFSET $offset_keys
")->fetch_all(MYSQLI_ASSOC);
$total_keys = $db->query("SELECT COUNT(*) as count FROM access_keys")->fetch_assoc()['count'];
$used_keys_count = $db->query("SELECT COUNT(*) as count FROM access_keys WHERE used_at IS NOT NULL")->fetch_assoc()['count'];
$total_pages_keys = ceil($total_keys / $per_page_keys);

// Action Logs
$per_page_actions = isset($_GET['per_page_actions']) ? max(1, (int)$_GET['per_page_actions']) : 10;
$page_actions = isset($_GET['page_actions']) ? max(1, (int)$_GET['page_actions']) : 1;
$offset_actions = ($page_actions - 1) * $per_page_actions;
$action_logs = $db->query("
    SELECT al.*, u.username 
    FROM action_logs al
    JOIN users u ON al.user_id = u.id
    ORDER BY al.created_at DESC
    LIMIT $per_page_actions OFFSET $offset_actions
")->fetch_all(MYSQLI_ASSOC);
$total_actions = $db->query("SELECT COUNT(*) as count FROM action_logs")->fetch_assoc()['count'];
$total_pages_actions = ceil($total_actions / $per_page_actions);

// Chat Logs
$per_page_chat_logs = isset($_GET['per_page_chat_logs']) ? max(1, (int)$_GET['per_page_chat_logs']) : 10;
$page_chat_logs = isset($_GET['page_chat_logs']) ? max(1, (int)$_GET['page_chat_logs']) : 1;
$offset_chat_logs = ($page_chat_logs - 1) * $per_page_chat_logs;
// Check if chat_logs table has created_at column, otherwise use id for ordering
$column_check = $db->query("SHOW COLUMNS FROM chat_logs LIKE 'created_at'");
$has_created_at = $column_check && $column_check->num_rows > 0;
$order_by = $has_created_at ? 'cl.created_at' : 'cl.id';

$chat_logs = $db->query("
    SELECT cl.*, u1.username as sender_username, u2.username as receiver_username
    FROM chat_logs cl
    JOIN users u1 ON cl.sender_id = u1.id
    JOIN users u2 ON cl.receiver_id = u2.id
    ORDER BY $order_by DESC
    LIMIT $per_page_chat_logs OFFSET $offset_chat_logs
")->fetch_all(MYSQLI_ASSOC);
$total_chat_logs = $db->query("SELECT COUNT(*) as count FROM chat_logs")->fetch_assoc()['count'];
$total_pages_chat_logs = ceil($total_chat_logs / $per_page_chat_logs);

// Ban Proposals (from moderators)
$ban_proposals = $db->query("
    SELECT r.*, 
           u1.username as reporter_name, 
           u2.username as reported_name,
           u3.username as moderator_name
    FROM reports r
    JOIN users u1 ON r.reporter_id = u1.id
    JOIN users u2 ON r.reported_user_id = u2.id
    LEFT JOIN users u3 ON r.moderator_id = u3.id
    WHERE r.status = 'forwarded'
    ORDER BY r.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Current active tab
$current_tab = $_GET['tab'] ?? 'overview';
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0a192f">
    <title>Админ-панель | <?= SITE_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" href="assets/favicon.ico" type="image/x-icon">
    <style>
        /* Admin Page Styles */
        .admin-page {
            min-height: 100vh;
            background: linear-gradient(135deg, var(--bg-dark), var(--bg-medium-dark));
        }

        .admin-main-layout-container {
            display: flex;
            min-height: 100vh;
            gap: 20px;
            padding: 20px;
            max-width: 1600px;
            margin: 0 auto;
        }

        /* Admin Sidebar */
        .admin-sidebar {
            width: 280px;
            background: var(--bg-card);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 25px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4);
            position: sticky;
            top: 20px;
            height: fit-content;
            max-height: calc(100vh - 40px);
            overflow-y: auto;
            transition: transform var(--transition-normal);
        }

        .admin-sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .admin-sidebar::-webkit-scrollbar-thumb {
            background: var(--accent-cyan);
            border-radius: 3px;
        }

        .admin-sidebar-header {
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 20px;
        }

        .admin-sidebar-header h2 {
            color: var(--accent-cyan);
            font-size: 1.5rem;
            margin-bottom: 10px;
            text-shadow: 0 0 10px rgba(0, 217, 255, 0.5);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .admin-sidebar-header p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin: 0;
        }

        /* Admin Navigation */
        .admin-nav {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .admin-nav-item {
            margin: 0;
        }

        .admin-nav-item a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            color: var(--text-primary);
            text-decoration: none;
            border-radius: var(--radius-md);
            transition: all var(--transition-normal);
            background: var(--bg-light);
            border: 1px solid transparent;
            font-weight: 500;
        }

        .admin-nav-item a:hover {
            background: var(--bg-medium);
            border-color: var(--accent-cyan);
            color: var(--accent-cyan);
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0, 217, 255, 0.2);
        }

        .admin-nav-item a.active {
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple));
            color: white;
            border-color: var(--accent-cyan);
            box-shadow: 0 4px 15px rgba(0, 217, 255, 0.4);
        }

        .admin-nav-item a i {
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
        }

        .admin-nav-item a.btn-danger {
            background: linear-gradient(135deg, var(--accent-pink), var(--accent-purple));
            color: white;
        }

        .admin-nav-item a.btn-danger:hover {
            background: linear-gradient(135deg, var(--accent-purple), var(--accent-pink));
            transform: translateX(5px);
        }

        /* Admin Content */
        .admin-content {
            flex: 1;
            background: var(--bg-card);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 30px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4);
            min-height: calc(100vh - 40px);
        }

        .admin-tab-content {
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 25px;
            text-align: center;
            transition: all var(--transition-normal);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(180deg, var(--accent-cyan), var(--accent-purple));
            transition: width var(--transition-normal);
        }

        .stat-card:hover {
            background: var(--bg-medium);
            border-color: var(--accent-cyan);
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 217, 255, 0.25);
        }

        .stat-card:hover::before {
            width: 100%;
            opacity: 0.1;
        }

        .stat-card h4 {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin: 0 0 10px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card p {
            color: var(--accent-cyan);
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0;
            text-shadow: 0 0 10px rgba(0, 217, 255, 0.5);
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
            margin-top: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: var(--bg-light);
            border-radius: var(--radius-md);
            overflow: hidden;
        }

        table thead {
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple));
        }

        table thead th {
            padding: 15px;
            text-align: left;
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        table tbody tr {
            border-bottom: 1px solid var(--border-color);
            transition: all var(--transition-fast);
        }

        table tbody tr:hover {
            background: var(--bg-medium);
        }

        table tbody tr:last-child {
            border-bottom: none;
        }

        table tbody td {
            padding: 15px;
            color: var(--text-primary);
        }

        /* Toggle Container */
        .toggle-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .per-page-selector {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .summary {
            padding: 15px;
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            color: var(--text-primary);
            font-weight: 600;
        }

        /* Pagination */
        .pagination {
            display: flex;
            gap: 8px;
            margin-top: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .pagination a {
            padding: 10px 15px;
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            text-decoration: none;
            transition: all var(--transition-fast);
        }

        .pagination a:hover {
            background: var(--bg-medium);
            border-color: var(--accent-cyan);
            color: var(--accent-cyan);
            transform: translateY(-2px);
        }

        .pagination a.current {
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple));
            color: white;
            border-color: var(--accent-cyan);
        }

        /* Ban Actions */
        .ban-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        /* Mobile Sidebar Toggle */
        .mobile-sidebar-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: var(--accent-cyan);
            border: none;
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            font-size: 1.3rem;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(0, 217, 255, 0.4);
            transition: all var(--transition-fast);
        }

        .mobile-sidebar-toggle:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(0, 217, 255, 0.6);
        }

        /* Admin Actions */
        .admin-actions {
            margin-bottom: 30px;
        }

        .admin-actions .btn-large {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 20px;
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple));
            border: none;
            border-radius: var(--radius-md);
            color: white;
            text-decoration: none;
            transition: all var(--transition-normal);
            box-shadow: 0 4px 15px rgba(0, 217, 255, 0.3);
        }

        .admin-actions .btn-large:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(0, 217, 255, 0.5);
        }

        .admin-actions .btn-large i {
            font-size: 2rem;
        }

        .admin-actions .btn-text {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .admin-actions .btn-text strong {
            font-size: 1.2rem;
        }

        .admin-actions .btn-text small {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        /* Compact Code */
        .compact-code {
            font-family: 'Share Tech Mono', monospace;
            color: var(--accent-cyan);
            font-weight: 600;
        }

        .full-code {
            font-family: 'Share Tech Mono', monospace;
            color: var(--accent-cyan);
            font-weight: 600;
            word-break: break-all;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .admin-main-layout-container {
                flex-direction: column;
                padding: 10px;
            }

            .mobile-sidebar-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .admin-sidebar {
                width: 100%;
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                max-height: 100vh;
                z-index: 1000;
                transform: translateX(-100%);
                border-radius: 0;
                transition: transform var(--transition-normal);
            }

            .admin-sidebar.active {
                transform: translateX(0);
            }

            .admin-content {
                padding: 20px;
                min-height: auto;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .toggle-container {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body class="admin-page">
    <div class="cyber-grid"></div>
    <div class="animated-lines"></div>
    <div class="particles"></div>
    <button class="mobile-sidebar-toggle" onclick="toggleAdminSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="admin-main-layout-container">
    <div class="admin-sidebar glass-effect">
        <div class="admin-sidebar-header">
            <h2 class="section-title"><i class="fas fa-user-cog"></i> Админ-панель</h2>
            <p>Добро пожаловать, <?= htmlspecialchars($_SESSION['username']) ?></p>
        </div>
        <ul class="admin-nav">
            <li class="admin-nav-item"><a href="?tab=overview" class="<?= $current_tab == 'overview' ? 'active' : '' ?>"><i class="fas fa-tachometer-alt"></i> Обзор</a></li>
            <li class="admin-nav-item"><a href="?tab=users" class="<?= $current_tab == 'users' ? 'active' : '' ?>"><i class="fas fa-users"></i> Пользователи</a></li>
            <li class="admin-nav-item"><a href="?tab=invites" class="<?= $current_tab == 'invites' ? 'active' : '' ?>"><i class="fas fa-key"></i> Инвайт-коды</a></li>
            <li class="admin-nav-item"><a href="?tab=moderation" class="<?= $current_tab == 'moderation' ? 'active' : '' ?>"><i class="fas fa-gavel"></i> Модерация</a></li>
            <li class="admin-nav-item"><a href="?tab=logs" class="<?= $current_tab == 'logs' ? 'active' : '' ?>"><i class="fas fa-history"></i> Логи</a></li>
            <li class="admin-nav-item"><a href="?tab=settings" class="<?= $current_tab == 'settings' ? 'active' : '' ?>"><i class="fas fa-cogs"></i> Настройки сайта</a></li>
            <li class="admin-nav-item"><a href="currency_admin.php" class="<?= str_contains($_SERVER['REQUEST_URI'], 'currency_admin.php') ? 'active' : '' ?>"><i class="fas fa-coins"></i> Управление валютой</a></li>
            <li class="admin-nav-item"><a href="chat.php"><i class="fas fa-arrow-left"></i> Вернуться в чат</a></li>
            <li class="admin-nav-item"><a href="logout.php" class="btn-danger"><i class="fas fa-sign-out-alt"></i> Выход</a></li>
        </ul>
    </div>

    <div class="admin-content">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Overview Tab -->
        <div id="overview-tab" class="admin-tab-content" style="display: <?= $current_tab == 'overview' ? 'block' : 'none' ?>;">
            <h2 class="section-title"><i class="fas fa-tachometer-alt"></i> Обзор системы</h2>
            <div class="stats-grid">
                <div class="card stat-card">
                    <h4>Всего пользователей</h4>
                    <p><?= $db->query("SELECT COUNT(*) FROM users")->fetch_row()[0] ?></p>
                </div>
                <div class="card stat-card">
                    <h4>Онлайн сейчас</h4>
                    <p><?= getOnlineUsersCount() ?></p>
                </div>
                <div class="card stat-card">
                    <h4>Активных инвайтов</h4>
                    <p><?= $total_keys - $used_keys_count ?></p>
                </div>
                <div class="card stat-card">
                    <h4>Жалоб в ожидании</h4>
                    <p><?= $db->query("SELECT COUNT(*) FROM reports WHERE status = 'pending'")->fetch_row()[0] ?></p>
                </div>
            </div>
            <div class="card">
                <h3 class="section-title"><i class="fas fa-bullhorn"></i> Анонс для всех пользователей</h3>
                <form method="POST">
                    <div class="form-group">
                        <textarea name="announcement_text" id="announcement_text" placeholder=" " rows="4" required></textarea>
                        <i class="fas fa-bullhorn"></i>
                        <label for="announcement_text">Текст анонса</label>
                    </div>
                    <button type="submit" name="send_announcement" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Отправить анонс</button>
                </form>
            </div>
        </div>

        <!-- Users Tab -->
        <div id="users-tab" class="admin-tab-content" style="display: <?= $current_tab == 'users' ? 'block' : 'none' ?>;">
            <h2 class="section-title"><i class="fas fa-coins"></i> D3X Coin & Магазин</h2>
            <div class="admin-actions">
                <a href="currency_admin.php" class="btn btn-large">
                    <i class="fas fa-coins"></i>
                    <span class="btn-text">
                        <strong>Управление валютой</strong>
                        <small>Выдача D3X Coin и управление товарами</small>
                    </span>
                </a>
            </div>

            <h2 class="section-title"><i class="fas fa-users"></i> Управление пользователями</h2>
            <form method="GET" class="search-form" style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
                <input type="hidden" name="tab" value="users">
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <input type="text" id="search_users" name="search_users" placeholder=" " value="<?= htmlspecialchars($search_users) ?>" />
                    <i class="fas fa-search"></i>
                    <label for="search_users">Поиск пользователя</label>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Поиск</button>
                <?php if ($search_users): ?>
                    <a href="admin.php?tab=users" class="btn btn-danger"><i class="fas fa-times"></i> Сброс</a>
                <?php endif; ?>
            </form>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Имя</th>
                            <th>Роль</th>
                            <th>Статус</th>
                            <th>Дата регистрации</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= $user['id'] ?></td>
                                <td><?= htmlspecialchars($user['username']) ?></td>
                                <td>
                                    <form method="POST" style="display:inline-block;">
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>" />
                                        <div class="form-group" style="margin-bottom: 0;">
                                            <select id="user_role_<?= $user['id'] ?>" name="new_role" onchange="this.form.submit()" placeholder=" " required>
                                                <option value="<?= ROLE_USER ?>" <?= $user['role'] == ROLE_USER ? 'selected' : '' ?>>Пользователь</option>
                                                <option value="<?= ROLE_MODER ?>" <?= $user['role'] == ROLE_MODER ? 'selected' : '' ?>>Модератор</option>
                                                <option value="<?= ROLE_ADMIN ?>" <?= $user['role'] == ROLE_ADMIN ? 'selected' : '' ?>>Администратор</option>
                                            </select>
                                            <i class="fas fa-user-tag"></i>
                                            <label for="user_role_<?= $user['id'] ?>">Роль</label>
                                        </div>
                                        <input type="hidden" name="change_role" value="1">
                                    </form>
                                </td>
                                <td>
                                    <span class="badge <?= $user['banned'] ? 'badge-banned' : 'badge-active' ?>">
                                        <?= $user['banned'] ? 'Забанен' : 'Активен' ?>
                                    </span>
                                </td>
                                <td><?= date('d.m.Y H:i', strtotime($user['created_at'])) ?></td>
                                <td>
                                    <?php if ($user['id'] != $current_admin_id): ?>
                                        <?php if ($user['banned']): ?>
                                            <a href="?tab=users&unban=<?= $user['id'] ?>" class="btn btn-sm btn-primary" onclick="return confirm('Разбанить пользователя <?= htmlspecialchars($user['username']) ?>?')">
                                                <i class="fas fa-check-circle"></i> Разбанить
                                            </a>
                                        <?php else: ?>
                                            <a href="?tab=users&ban=<?= $user['id'] ?>" class="btn btn-sm btn-secondary" onclick="return confirm('Забанить пользователя <?= htmlspecialchars($user['username']) ?>?')">
                                                <i class="fas fa-ban"></i> Забанить
                                            </a>
                                        <?php endif; ?>
                                        <a href="?tab=users&delete=<?= $user['id'] ?>" class="btn btn-sm btn-danger"
                                           onclick="return confirm('Удалить пользователя <?= htmlspecialchars($user['username']) ?>? Это действие необратимо!')">
                                            <i class="fas fa-trash-alt"></i> Удалить
                                        </a>
                                    <?php else: ?>
                                        <span style="color:var(--text-secondary);">(Вы)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Invite Keys Tab -->
        <div id="invites-tab" class="admin-tab-content" style="display: <?= $current_tab == 'invites' ? 'block' : 'none' ?>;">
            <h2 class="section-title"><i class="fas fa-key"></i> Управление инвайт-кодами</h2>
            <div style="display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 30px;">
                <div class="card" style="flex: 1; min-width: 300px; margin-bottom: 0;">
                    <h3 class="section-title"><i class="fas fa-plus-circle"></i> Создать новые коды</h3>
                    <form method="POST">
                        <div class="form-group">
                            <input type="number" id="generate_key_count" name="key_count" min="1" max="50" value="5" required placeholder=" ">
                            <i class="fas fa-hashtag"></i>
                            <label for="generate_key_count">Количество кодов</label>
                        </div>
                        <button type="submit" name="generate_keys" class="btn btn-primary"><i class="fas fa-plus"></i> Создать</button>
                    </form>
                </div>
                
                <div class="card" style="flex: 1; min-width: 300px; margin-bottom: 0;">
                    <h3 class="section-title"><i class="fas fa-user-plus"></i> Выдать ключи пользователю</h3>
                    <form method="POST">
                        <div class="form-group">
                            <select id="assign_user_id" name="user_id" required placeholder=" ">
                                <option value="">Выберите пользователя</option>
                                <?php foreach($users as $user): ?>
                                    <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fas fa-user"></i>
                            <label for="assign_user_id">Пользователь</label>
                        </div>
                        <div class="form-group">
                            <input type="number" id="assign_key_count" name="key_count" min="1" max="10" value="1" required placeholder=" ">
                            <i class="fas fa-hashtag"></i>
                            <label for="assign_key_count">Количество ключей</label>
                        </div>
                        <button type="submit" name="assign_to_user" class="btn btn-primary"><i class="fas fa-gift"></i> Выдать</button>
                    </form>
                </div>

                <div class="card" style="flex: 1; min-width: 300px; margin-bottom: 0;">
                    <h3 class="section-title"><i class="fas fa-users"></i> Массовая выдача</h3>
                    <form method="POST">
                        <div class="form-group">
                            <input type="number" id="keys_per_user" name="keys_per_user" min="1" max="5" value="1" required placeholder=" ">
                            <i class="fas fa-hashtag"></i>
                            <label for="keys_per_user">Ключей на пользователя</label>
                        </div>
                        <button type="submit" name="generate_for_all" class="btn btn-primary"><i class="fas fa-users"></i> Создать для всех</button>
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 10px;">Будут созданы новые ключи для каждого активного пользователя.</p>
                    </form>
                </div>
            </div>
            
            <h3 class="section-title"><i class="fas fa-list"></i> Список инвайт-кодов</h3>
            <div class="toggle-container">
                <button class="btn btn-secondary" onclick="toggleTable('keys-table')">
                    <i class="fas fa-eye"></i> Показать/скрыть список кодов
                </button>
                <div class="per-page-selector">
                    <form method="GET" style="display:inline;">
                        <input type="hidden" name="tab" value="invites">
                        <input type="hidden" name="page_keys" value="<?= $page_keys ?>">
                        <div class="form-group" style="margin-bottom: 0;">
                            <select id="per_page_keys_select" name="per_page_keys" onchange="this.form.submit()" required placeholder=" ">
                            <option value="5" <?= $per_page_keys == 5 ? 'selected' : '' ?>>5</option>
                            <option value="10" <?= $per_page_keys == 10 ? 'selected' : '' ?>>10</option>
                            <option value="20" <?= $per_page_keys == 20 ? 'selected' : '' ?>>20</option>
                            <option value="50" <?= $per_page_keys == 50 ? 'selected' : '' ?>>50</option>
                            </select>
                            <i class="fas fa-list-alt"></i>
                            <label for="per_page_keys_select">Показывать по</label>
                        </div>
                    </form>
                </div>
            </div>
            <div class="summary">
                Всего кодов: <?= $total_keys ?> | 
                Использовано: <?= $used_keys_count ?>
            </div>

            <div class="table-responsive">
                <table class="keys-table" style="display: none;">
                    <thead>
                        <tr>
                            <th>Код</th>
                            <th>Создатель</th>
                            <th>Выдан</th>
                            <th>Использован</th>
                            <th>Статус</th>
                            <th>Дата создания</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($keys as $key): ?>
                            <tr>
                                <td>
                                    <span class="compact-code" title="<?= htmlspecialchars($key['key_value']) ?>">
                                        <?= substr(htmlspecialchars($key['key_value']), 0, 8) ?>...
                                    </span>
                                    <button class="btn btn-sm btn-secondary" onclick="showFullCode(this)" 
                                            data-code="<?= htmlspecialchars($key['key_value']) ?>">
                                        <i class="fas fa-expand"></i>
                                    </button>
                                </td>
                                <td><?= htmlspecialchars($key['creator_name'] ?? 'Система') ?></td>
                                <td><?= htmlspecialchars($key['assigned_to_name'] ?? 'Не выдан') ?></td>
                                <td><?= htmlspecialchars($key['used_by_name'] ?? 'Нет') ?></td>
                                <td>
                                    <span class="badge <?= $key['used_at'] ? 'badge-banned' : 'badge-active' ?>">
                                        <?= $key['used_at'] ? 'Использован' : 'Активен' ?>
                                    </span>
                                </td>
                                <td><?= date('d.m.Y H:i', strtotime($key['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($total_pages_keys > 1): ?>
                    <div class="pagination">
                        <?php if ($page_keys > 1): ?>
                            <a href="?tab=invites&page_keys=<?= $page_keys-1 ?>&per_page_keys=<?= $per_page_keys ?>">
                                &laquo; Назад
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages_keys; $i++): ?>
                            <a href="?tab=invites&page_keys=<?= $i ?>&per_page_keys=<?= $per_page_keys ?>" 
                               class="<?= $i == $page_keys ? 'current' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page_keys < $total_pages_keys): ?>
                            <a href="?tab=invites&page_keys=<?= $page_keys+1 ?>&per_page_keys=<?= $per_page_keys ?>">
                                Вперед &raquo;
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Moderation Tab -->
        <div id="moderation-tab" class="admin-tab-content" style="display: <?= $current_tab == 'moderation' ? 'block' : 'none' ?>;">
            <h2 class="section-title"><i class="fas fa-gavel"></i> Предложенные баны (от модераторов)</h2>
            <?php if (!empty($ban_proposals)): ?>
                <?php foreach ($ban_proposals as $proposal): ?>
                    <div class="card">
                        <p><strong>Пользователь:</strong> <?= htmlspecialchars($proposal['reported_name']) ?></p>
                        <p><strong>Причина:</strong> <?= htmlspecialchars($proposal['reason']) ?></p>
                        <p><strong>Жалоба от:</strong> <?= htmlspecialchars($proposal['reporter_name']) ?></p>
                        <?php if ($proposal['moderator_name']): ?>
                            <p><strong>Модератор:</strong> <?= htmlspecialchars($proposal['moderator_name']) ?></p>
                        <?php endif; ?>
                        
                        <div class="ban-actions">
                            <a href="?tab=moderation&ban_user=<?= $proposal['reported_user_id'] ?>&approve_ban=<?= $proposal['id'] ?>" 
                               class="btn btn-primary"
                               onclick="return confirm('Подтвердить бан пользователя <?= htmlspecialchars($proposal['reported_name']) ?>?')">
                                <i class="fas fa-check"></i> Подтвердить бан
                            </a>
                            
                            <a href="?tab=moderation&reject_ban=<?= $proposal['id'] ?>" 
                               class="btn btn-secondary"
                               onclick="return confirm('Отклонить предложение бана для <?= htmlspecialchars($proposal['reported_name']) ?>?')">
                                <i class="fas fa-times"></i> Отклонить
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color: var(--text-secondary);">Нет предложенных банов.</p>
            <?php endif; ?>
        </div>

        <!-- Logs Tab -->
        <div id="logs-tab" class="admin-tab-content" style="display: <?= $current_tab == 'logs' ? 'block' : 'none' ?>;">
            <h2 class="section-title"><i class="fas fa-history"></i> Логи действий</h2>
            <div class="toggle-container">
                <button class="btn btn-secondary" onclick="toggleTable('action-logs-table')">
                    <i class="fas fa-eye"></i> Показать/скрыть логи действий
                </button>
                <div class="per-page-selector">
                    <form method="GET" style="display:inline;">
                        <input type="hidden" name="tab" value="logs">
                        <input type="hidden" name="page_actions" value="<?= $page_actions ?>">
                        <div class="form-group" style="margin-bottom: 0;">
                            <select id="per_page_actions_select" name="per_page_actions" onchange="this.form.submit()" required placeholder=" ">
                            <option value="5" <?= $per_page_actions == 5 ? 'selected' : '' ?>>5</option>
                            <option value="10" <?= $per_page_actions == 10 ? 'selected' : '' ?>>10</option>
                            <option value="20" <?= $per_page_actions == 20 ? 'selected' : '' ?>>20</option>
                            <option value="50" <?= $per_page_actions == 50 ? 'selected' : '' ?>>50</option>
                            </select>
                            <i class="fas fa-list-alt"></i>
                            <label for="per_page_actions_select">Показывать по</label>
                        </div>
                    </form>
                </div>
            </div>
            <div class="table-responsive">
                <table class="action-logs-table" style="display: none;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Пользователь</th>
                            <th>Тип действия</th>
                            <th>Описание</th>
                            <th>Дата</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($action_logs as $log): ?>
                            <tr>
                                <td><?= $log['id'] ?></td>
                                <td><?= htmlspecialchars($log['username']) ?></td>
                                <td><?= htmlspecialchars($log['action_type']) ?></td>
                                <td><?= htmlspecialchars($log['description']) ?></td>
                                <td><?= date('d.m.Y H:i', strtotime($log['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($total_pages_actions > 1): ?>
                    <div class="pagination">
                        <?php if ($page_actions > 1): ?>
                            <a href="?tab=logs&page_actions=<?= $page_actions-1 ?>&per_page_actions=<?= $per_page_actions ?>">
                                &laquo; Назад
                            </a>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $total_pages_actions; $i++): ?>
                            <a href="?tab=logs&page_actions=<?= $i ?>&per_page_actions=<?= $per_page_actions ?>" 
                               class="<?= $i == $page_actions ? 'current' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($page_actions < $total_pages_actions): ?>
                            <a href="?tab=logs&page_actions=<?= $page_actions+1 ?>&per_page_actions=<?= $per_page_actions ?>">
                                Вперед &raquo;
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <h2 class="section-title" style="margin-top: 40px;"><i class="fas fa-comments"></i> Логи чатов</h2>
            <div class="toggle-container">
                <button class="btn btn-secondary" onclick="toggleTable('chat-logs-table')">
                    <i class="fas fa-eye"></i> Показать/скрыть логи чатов
                </button>
                <div class="per-page-selector">
                    <form method="GET" style="display:inline;">
                        <input type="hidden" name="tab" value="logs">
                        <input type="hidden" name="page_chat_logs" value="<?= $page_chat_logs ?>">
                        <div class="form-group" style="margin-bottom: 0;">
                            <select id="per_page_chat_logs_select" name="per_page_chat_logs" onchange="this.form.submit()" required placeholder=" ">
                            <option value="5" <?= $per_page_chat_logs == 5 ? 'selected' : '' ?>>5</option>
                            <option value="10" <?= $per_page_chat_logs == 10 ? 'selected' : '' ?>>10</option>
                            <option value="20" <?= $per_page_chat_logs == 20 ? 'selected' : '' ?>>20</option>
                            <option value="50" <?= $per_page_chat_logs == 50 ? 'selected' : '' ?>>50</option>
                            </select>
                            <i class="fas fa-list-alt"></i>
                            <label for="per_page_chat_logs_select">Показывать по</label>
                        </div>
                    </form>
                </div>
            </div>
            <div class="table-responsive">
                <table class="chat-logs-table" style="display: none;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Отправитель</th>
                            <th>Получатель</th>
                            <th>Сообщение</th>
                            <th>Дата</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($chat_logs as $log): ?>
                            <tr>
                                <td><?= $log['id'] ?></td>
                                <td><?= htmlspecialchars($log['sender_username']) ?></td>
                                <td><?= htmlspecialchars($log['receiver_username']) ?></td>
                                <td><?= htmlspecialchars($log['message_content']) ?></td>
                                <td><?= 
                                    isset($log['created_at']) && !empty($log['created_at']) 
                                        ? date('d.m.Y H:i', strtotime($log['created_at'])) 
                                        : (isset($log['id']) ? 'ID: ' . $log['id'] : '—')
                                ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($total_pages_chat_logs > 1): ?>
                    <div class="pagination">
                        <?php if ($page_chat_logs > 1): ?>
                            <a href="?tab=logs&page_chat_logs=<?= $page_chat_logs-1 ?>&per_page_chat_logs=<?= $per_page_chat_logs ?>">
                                &laquo; Назад
                            </a>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $total_pages_chat_logs; $i++): ?>
                            <a href="?tab=logs&page_chat_logs=<?= $i ?>&per_page_chat_logs=<?= $per_page_chat_logs ?>" 
                               class="<?= $i == $page_chat_logs ? 'current' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($page_chat_logs < $total_pages_chat_logs): ?>
                            <a href="?tab=logs&page_chat_logs=<?= $page_chat_logs+1 ?>&per_page_chat_logs=<?= $per_page_chat_logs ?>">
                                Вперед &raquo;
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Settings Tab -->
        <div id="settings-tab" class="admin-tab-content" style="display: <?= $current_tab == 'settings' ? 'block' : 'none' ?>;">
            <h2 class="section-title"><i class="fas fa-cogs"></i> Настройки сайта</h2>
            
            <form method="POST" id="site-settings-form">
                <!-- Основные настройки -->
                <div class="card" style="margin-bottom: 30px;">
                    <h3 class="section-title" style="font-size: 1.3rem; margin-bottom: 20px;">
                        <i class="fas fa-info-circle"></i> Основные настройки
                    </h3>
                    
                    <div class="form-group">
                        <input type="text" id="site_name" name="site_name" value="<?= htmlspecialchars($site_settings['site_name']) ?>" required placeholder=" ">
                        <i class="fas fa-globe"></i>
                        <label for="site_name">Имя сайта</label>
                    </div>

                    <div class="form-group">
                        <textarea id="welcome_message" name="welcome_message" rows="4" required placeholder=" "><?= htmlspecialchars($site_settings['welcome_message']) ?></textarea>
                        <i class="fas fa-comment-alt"></i>
                        <label for="welcome_message">Приветственное сообщение</label>
                    </div>

                    <div class="form-group">
                        <input type="number" id="max_message_length" name="max_message_length" value="<?= htmlspecialchars($site_settings['max_message_length']) ?>" min="1" max="2000" required placeholder=" ">
                        <i class="fas fa-text-width"></i>
                        <label for="max_message_length">Максимальная длина сообщения</label>
                    </div>

                    <div class="form-group">
                        <input type="number" id="max_avatar_size_mb" name="max_avatar_size_mb" value="<?= htmlspecialchars($site_settings['max_avatar_size_mb']) ?>" min="1" max="10" required placeholder=" ">
                        <i class="fas fa-image"></i>
                        <label for="max_avatar_size_mb">Максимальный размер аватара (МБ)</label>
                    </div>

                    <div class="form-group">
                        <input type="number" id="max_invites_per_user" name="max_invites_per_user" value="<?= htmlspecialchars($site_settings['max_invites_per_user']) ?>" min="0" max="50" required placeholder=" ">
                        <i class="fas fa-ticket-alt"></i>
                        <label for="max_invites_per_user">Максимум инвайтов на пользователя</label>
                    </div>

                    <div class="form-group">
                        <select id="default_user_role" name="default_user_role" required placeholder=" ">
                            <option value="1" <?= $site_settings['default_user_role'] == 1 ? 'selected' : '' ?>>Пользователь</option>
                            <option value="2" <?= $site_settings['default_user_role'] == 2 ? 'selected' : '' ?>>Модератор</option>
                            <option value="3" <?= $site_settings['default_user_role'] == 3 ? 'selected' : '' ?>>Администратор</option>
                        </select>
                        <i class="fas fa-user-tag"></i>
                        <label for="default_user_role">Роль по умолчанию для новых пользователей</label>
                    </div>
                </div>

                <!-- Переключатели функций -->
                <div class="card">
                    <h3 class="section-title" style="font-size: 1.3rem; margin-bottom: 20px;">
                        <i class="fas fa-toggle-on"></i> Переключатели функций
                    </h3>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                        <div class="form-group-checkbox" style="background: var(--bg-light); padding: 20px; border-radius: var(--radius-md); border: 1px solid var(--border-color); transition: all var(--transition-fast);">
                            <input type="checkbox" id="maintenance_mode" name="maintenance_mode" <?= $site_settings['maintenance_mode'] ? 'checked' : '' ?> >
                            <label for="maintenance_mode">
                                <i class="fas fa-tools" style="color: var(--accent-cyan); margin-right: 8px;"></i>
                                <strong>Режим обслуживания</strong>
                                <small style="display: block; color: var(--text-secondary); margin-top: 5px;">
                                    При включении сайт будет недоступен для обычных пользователей
                                </small>
                            </label>
                        </div>

                        <div class="form-group-checkbox" style="background: var(--bg-light); padding: 20px; border-radius: var(--radius-md); border: 1px solid var(--border-color); transition: all var(--transition-fast);">
                            <input type="checkbox" id="registration_keys_required" name="registration_keys_required" <?= $site_settings['registration_keys_required'] ? 'checked' : '' ?> >
                            <label for="registration_keys_required">
                                <i class="fas fa-key" style="color: var(--accent-cyan); margin-right: 8px;"></i>
                                <strong>Требуются ключи для регистрации</strong>
                                <small style="display: block; color: var(--text-secondary); margin-top: 5px;">
                                    Новые пользователи должны иметь инвайт-код для регистрации
                                </small>
                            </label>
                        </div>

                        <div class="form-group-checkbox" style="background: var(--bg-light); padding: 20px; border-radius: var(--radius-md); border: 1px solid var(--border-color); transition: all var(--transition-fast);">
                            <input type="checkbox" id="allow_user_registration" name="allow_user_registration" <?= $site_settings['allow_user_registration'] ? 'checked' : '' ?> >
                            <label for="allow_user_registration">
                                <i class="fas fa-user-plus" style="color: var(--accent-green); margin-right: 8px;"></i>
                                <strong>Разрешить общую регистрацию</strong>
                                <small style="display: block; color: var(--text-secondary); margin-top: 5px;">
                                    Разрешить регистрацию новых пользователей на сайте
                                </small>
                            </label>
                        </div>

                        <div class="form-group-checkbox" style="background: var(--bg-light); padding: 20px; border-radius: var(--radius-md); border: 1px solid var(--border-color); transition: all var(--transition-fast);">
                            <input type="checkbox" id="enable_forum" name="enable_forum" <?= $site_settings['enable_forum'] ? 'checked' : '' ?> >
                            <label for="enable_forum">
                                <i class="fas fa-comments" style="color: var(--accent-purple); margin-right: 8px;"></i>
                                <strong>Включить форум</strong>
                                <small style="display: block; color: var(--text-secondary); margin-top: 5px;">
                                    Активировать функционал форума на сайте
                                </small>
                            </label>
                        </div>

                        <div class="form-group-checkbox" style="background: var(--bg-light); padding: 20px; border-radius: var(--radius-md); border: 1px solid var(--border-color); transition: all var(--transition-fast);">
                            <input type="checkbox" id="enable_shop" name="enable_shop" <?= $site_settings['enable_shop'] ? 'checked' : '' ?> >
                            <label for="enable_shop">
                                <i class="fas fa-store" style="color: var(--accent-yellow); margin-right: 8px;"></i>
                                <strong>Включить магазин</strong>
                                <small style="display: block; color: var(--text-secondary); margin-top: 5px;">
                                    Активировать функционал магазина на сайте
                                </small>
                            </label>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 30px; text-align: center;">
                    <button type="submit" name="update_site_settings" class="btn btn-primary" style="padding: 15px 40px; font-size: 1.1rem;">
                        <i class="fas fa-save"></i> Сохранить все настройки
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Function to switch tabs
    function switchTab(tabId) {
        document.querySelectorAll('.admin-tab-content').forEach(tab => {
            tab.style.display = 'none';
        });
        document.getElementById(tabId + '-tab').style.display = 'block';

        document.querySelectorAll('.admin-nav-item a').forEach(link => {
            link.classList.remove('active');
        });
        document.querySelector(`.admin-nav-item a[href="?tab=${tabId}"]`).classList.add('active');

        // Close sidebar on mobile after tab selection
        if (window.innerWidth <= 768) {
            document.querySelector('.admin-sidebar').classList.remove('active');
        }
    }

    // Function to toggle table visibility
    function toggleTable(tableClass) {
        const table = document.querySelector(`.${tableClass}`);
        if (table.style.display === 'none' || table.style.display === '') {
            table.style.display = 'table';
        } else {
            table.style.display = 'none';
        }
    }
    
    // Function to show full invite code
    function showFullCode(btn) {
        const code = btn.getAttribute('data-code');
        const compact = btn.previousElementSibling;
        
        if (compact.classList.contains('full-code')) {
            compact.textContent = code.substring(0, 8) + '...';
            compact.classList.remove('full-code');
            compact.classList.add('compact-code');
            btn.innerHTML = '<i class="fas fa-expand"></i>';
        } else {
            compact.textContent = code;
            compact.classList.remove('compact-code');
            compact.classList.add('full-code');
            btn.innerHTML = '<i class="fas fa-compress"></i>';
        }
    }

    // Mobile sidebar toggle
    function toggleAdminSidebar() {
        document.querySelector('.admin-sidebar').classList.toggle('active');
    }

    // Set initial tab on page load
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const initialTab = urlParams.get('tab') || 'overview';
        switchTab(initialTab);

        // Automatically show tables if they have few entries (as per original logic)
        // This logic might be better handled by CSS or a user preference
        // For now, keeping original behavior if total entries are small
        if (<?= $total_keys ?> <= 10) {
            document.querySelector('.keys-table').style.display = 'table';
        }
        if (<?= $total_actions ?> <= 10) {
            document.querySelector('.action-logs-table').style.display = 'table';
        }
        if (<?= $total_chat_logs ?> <= 10) {
            document.querySelector('.chat-logs-table').style.display = 'table';
        }
    });
</script>

</body>
</html>
