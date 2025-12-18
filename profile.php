<?php
// Запуск сессии должен быть самым первым действием
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . '/config.php'; // Исправлен путь к config.php

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$db = getDB();
$current_user_id = $_SESSION['user_id'];
$current_username = htmlspecialchars($_SESSION['username']);
$current_user_role = $_SESSION['role'];
$current_avatar = htmlspecialchars($_SESSION['avatar'] ?? DEFAULT_AVATAR);
$error = '';
$success = '';

// Get messages from session (for redirects)
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

// Determine which user's profile to display
$profile_user_id = isset($_GET['id']) ? (int)$_GET['id'] : $current_user_id;

// Handle POST requests first (before loading profile data)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle follow/unfollow actions (works for any profile)
    if (isset($_POST['follow_action'])) {
        // Get profile_user_id from POST or GET
        $target_user_id = isset($_POST['profile_user_id']) ? (int)$_POST['profile_user_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
        
        if ($target_user_id <= 0 || $target_user_id == $current_user_id) {
            $_SESSION['error'] = "Некорректный ID пользователя.";
            header("Location: profile.php");
            exit;
        }
        
        $action = $_POST['follow_action'];
        if ($action === 'follow') {
            $stmt = $db->prepare("INSERT IGNORE INTO user_relationships (follower_id, following_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $current_user_id, $target_user_id);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Вы подписались на пользователя!";
                logAction($current_user_id, 'follow', "Подписался на пользователя ID: $target_user_id.");
            } else {
                $_SESSION['error'] = "Ошибка при попытке подписаться: " . $db->error;
            }
        } elseif ($action === 'unfollow') {
            $stmt = $db->prepare("DELETE FROM user_relationships WHERE follower_id = ? AND following_id = ?");
            $stmt->bind_param("ii", $current_user_id, $target_user_id);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Вы отписались от пользователя.";
                logAction($current_user_id, 'unfollow', "Отписался от пользователя ID: $target_user_id.");
            } else {
                $_SESSION['error'] = "Ошибка при попытке отписаться: " . $db->error;
            }
        }
        
        header("Location: profile.php?id=" . $target_user_id . "&tab=my_profile");
        exit;
    }
    
    // Handle comment addition (works for other users' profiles only)
    if (isset($_POST['add_comment'])) {
        $target_user_id = isset($_POST['profile_owner_id']) ? (int)$_POST['profile_owner_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
        
        if ($target_user_id <= 0 || $target_user_id == $current_user_id) {
            $_SESSION['error'] = "Некорректный ID пользователя.";
            header("Location: profile.php");
            exit;
        }
        
        $comment_content = trim($_POST['comment_content'] ?? '');
        if (!empty($comment_content)) {
            // Check if table exists, create if not
            $table_exists = $db->query("SHOW TABLES LIKE 'profile_comments'")->num_rows > 0;
            if (!$table_exists) {
                $db->query("CREATE TABLE IF NOT EXISTS profile_comments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    profile_owner_id INT NOT NULL,
                    commenter_id INT NOT NULL,
                    content TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_profile_owner (profile_owner_id),
                    INDEX idx_commenter (commenter_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
            
            // Check column names and determine the correct one
            $columns = $db->query("SHOW COLUMNS FROM profile_comments")->fetch_all(MYSQLI_ASSOC);
            $column_names = array_column($columns, 'Field');
            
            // Determine the correct column name for comment text
            $comment_column = 'content';
            if (in_array('comment_content', $column_names)) {
                $comment_column = 'comment_content';
            } elseif (in_array('content', $column_names)) {
                $comment_column = 'content';
            } elseif (in_array('message', $column_names)) {
                $comment_column = 'message';
            } elseif (in_array('text', $column_names)) {
                $comment_column = 'text';
            } else {
                // Add content column if it doesn't exist
                try {
                    $db->query("ALTER TABLE profile_comments ADD COLUMN content TEXT NOT NULL AFTER commenter_id");
                } catch (Exception $e) {
                    error_log("Error adding content column: " . $e->getMessage());
                }
                $comment_column = 'content';
            }
            
            $stmt = $db->prepare("INSERT INTO profile_comments (profile_owner_id, commenter_id, " . $comment_column . ") VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $target_user_id, $current_user_id, $comment_content);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Ваш комментарий успешно добавлен!";
                logAction($current_user_id, 'add_profile_comment', "Оставил комментарий на профиле ID: $target_user_id.");
            } else {
                $_SESSION['error'] = "Ошибка при добавлении комментария: " . $db->error;
                error_log("Profile comment error: " . $db->error);
            }
        } else {
            $_SESSION['error'] = "Комментарий не может быть пустым.";
        }
        header("Location: profile.php?id=" . $target_user_id . "&tab=my_posts");
        exit;
    }
}

$is_own_profile = ($profile_user_id == $current_user_id);

// Fetch profile user details
$profile_user_data = null;
try {
    $stmt_profile_user = $db->prepare("SELECT id, username, avatar, role, created_at FROM users WHERE id = ? LIMIT 1");
    $stmt_profile_user->bind_param("i", $profile_user_id);
    $stmt_profile_user->execute();
    $result_profile_user = $stmt_profile_user->get_result();
    if ($result_profile_user->num_rows > 0) {
        $profile_user_data = $result_profile_user->fetch_assoc();
    } else {
        // If profile user not found, redirect to own profile (avoid infinite redirect)
        if ($profile_user_id != $current_user_id) {
            $_SESSION['error'] = "Профиль пользователя не найден.";
            header("Location: profile.php"); // Redirect to own profile
            exit;
        } else {
            // If own profile not found, this is a critical error
            error_log("Critical error: Own profile (ID: $current_user_id) not found in database!");
            die("Критическая ошибка: ваш профиль не найден в базе данных. Обратитесь к администратору.");
        }
    }
} catch (Exception $e) {
    error_log("Error fetching profile user data: " . $e->getMessage());
    $_SESSION['error'] = "Ошибка при загрузке профиля пользователя.";
    header("Location: profile.php"); // Redirect to own profile
    exit;
}

$profile_username = htmlspecialchars($profile_user_data['username']);
$profile_avatar = htmlspecialchars($profile_user_data['avatar'] ?? DEFAULT_AVATAR);
$profile_role = $profile_user_data['role'];
$profile_created_at = $profile_user_data['created_at'];

// --- Fetch additional profile data (followers, following, comments) ---
$followers_count = 0;
$following_count = 0;
$is_following = false;
$followers_list = [];
$following_list = [];
$profile_comments = [];

try {
    // Get followers count
    $stmt_followers_count = $db->prepare("SELECT COUNT(*) FROM user_relationships WHERE following_id = ?");
    $stmt_followers_count->bind_param("i", $profile_user_id);
    $stmt_followers_count->execute();
    $followers_count = $stmt_followers_count->get_result()->fetch_row()[0];

    // Get following count
    $stmt_following_count = $db->prepare("SELECT COUNT(*) FROM user_relationships WHERE follower_id = ?");
    $stmt_following_count->bind_param("i", $profile_user_id);
    $stmt_following_count->execute();
    $following_count = $stmt_following_count->get_result()->fetch_row()[0];

    // Check if current user is following this profile (if not own profile)
    if (!$is_own_profile) {
        $stmt_is_following = $db->prepare("SELECT COUNT(*) FROM user_relationships WHERE follower_id = ? AND following_id = ?");
        $stmt_is_following->bind_param("ii", $current_user_id, $profile_user_id);
        $stmt_is_following->execute();
        $is_following = $stmt_is_following->get_result()->fetch_row()[0] > 0;
    }

    // Get followers list
    $stmt_followers_list = $db->prepare("SELECT u.id, u.username, u.avatar FROM user_relationships ur JOIN users u ON ur.follower_id = u.id WHERE ur.following_id = ? ORDER BY u.username ASC");
    $stmt_followers_list->bind_param("i", $profile_user_id);
    $stmt_followers_list->execute();
    $followers_list = $stmt_followers_list->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get following list
    $stmt_following_list = $db->prepare("SELECT u.id, u.username, u.avatar FROM user_relationships ur JOIN users u ON ur.following_id = u.id WHERE ur.follower_id = ? ORDER BY u.username ASC");
    $stmt_following_list->bind_param("i", $profile_user_id);
    $stmt_following_list->execute();
    $following_list = $stmt_following_list->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get profile comments
    // First, determine the correct column name
    $table_exists = $db->query("SHOW TABLES LIKE 'profile_comments'")->num_rows > 0;
    $comment_column = 'content';
    if ($table_exists) {
        $columns = $db->query("SHOW COLUMNS FROM profile_comments")->fetch_all(MYSQLI_ASSOC);
        $column_names = array_column($columns, 'Field');
        if (in_array('comment_content', $column_names)) {
            $comment_column = 'comment_content';
        } elseif (in_array('content', $column_names)) {
            $comment_column = 'content';
        } elseif (in_array('message', $column_names)) {
            $comment_column = 'message';
        } elseif (in_array('text', $column_names)) {
            $comment_column = 'text';
        }
    }
    
    $stmt_profile_comments = $db->prepare("
        SELECT pc.id, pc." . $comment_column . " as comment_content, pc.created_at, u.id as commenter_id, u.username as commenter_username, u.avatar as commenter_avatar
        FROM profile_comments pc
        JOIN users u ON pc.commenter_id = u.id
        WHERE pc.profile_owner_id = ?
        ORDER BY pc.created_at DESC
    ");
    $stmt_profile_comments->bind_param("i", $profile_user_id);
    $stmt_profile_comments->execute();
    $profile_comments = $stmt_profile_comments->get_result()->fetch_all(MYSQLI_ASSOC);

} catch (Exception $e) {
    error_log("Error fetching additional profile data: " . $e->getMessage());
    $_SESSION['error'] = "Ошибка при загрузке дополнительных данных профиля.";
}

// --- Handle POST requests for own profile actions (only for own profile) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_own_profile) {
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = [
            'image/jpeg' => 'jpg', 
            'image/png' => 'png', 
            'image/gif' => 'gif',
            'image/webp' => 'webp' // Добавлен WebP
        ];
        $max_size = 2 * 1024 * 1024; // 2MB

        // Проверка MIME-типа файла
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($_FILES['avatar']['tmp_name']);

        if (array_key_exists($mime_type, $allowed_types) && $_FILES['avatar']['size'] <= $max_size) {
            $avatar_dir = 'avatars/';
            if (!file_exists($avatar_dir)) {
                mkdir($avatar_dir, 0755, true);
            }

            $extension = $allowed_types[$mime_type];
            $avatar_name = 'avatar_' . $current_user_id . '_' . time() . '.' . $extension;
            $avatar_path = $avatar_dir . $avatar_name;

            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $avatar_path)) {
                $old_avatar = $db->query("SELECT avatar FROM users WHERE id = $current_user_id")->fetch_assoc()['avatar'];
                
                $stmt_update_avatar = $db->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                $stmt_update_avatar->bind_param("si", $avatar_path, $current_user_id);
                
                if ($stmt_update_avatar->execute()) {
                    // Удаляем старый аватар, если он не является аватаром по умолчанию
                    if ($old_avatar && $old_avatar !== DEFAULT_AVATAR && file_exists($old_avatar)) {
                        unlink($old_avatar);
                    }
                    $_SESSION['avatar'] = $avatar_path;
                    $success = "Аватар успешно обновлен!";
                    logAction($current_user_id, 'update_avatar', "Обновлен аватар пользователя.");
                } else {
                    // Если обновление БД не удалось, удаляем загруженный файл
                    unlink($avatar_path);
                    $error = "Ошибка при обновлении аватара в базе данных.";
                }
            } else {
                $error = "Ошибка при загрузке файла на сервер.";
            }
        } else {
            $error = "Недопустимый формат файла (разрешены JPG, PNG, GIF, WebP) или размер превышает 2MB.";
        }
    } elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = "Пожалуйста, заполните все поля.";
        } elseif ($new_password !== $confirm_password) {
            $error = "Новые пароли не совпадают!";
        } elseif (strlen($new_password) < 6) {
            $error = "Новый пароль должен быть не менее 6 символов.";
        } else {
            $stmt = $db->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $current_user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            if ($user && password_verify($current_password, $user['password'])) {
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $update_stmt->bind_param("si", $new_password_hash, $current_user_id);
                if ($update_stmt->execute()) {
                    $success = "Пароль успешно изменен!";
                    logAction($current_user_id, 'change_password', "Пароль пользователя изменен.");
                } else {
                    $error = "Ошибка при обновлении пароля в базе данных.";
                }
            } else {
                $error = "Неверный текущий пароль!";
            }
        }
    } elseif (isset($_POST['delete_account_confirm'])) {
        $password = $_POST['password'];

        $stmt = $db->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            $db->begin_transaction();
            try {
                // Получаем путь к аватару пользователя для удаления
                $stmt_avatar = $db->prepare("SELECT avatar FROM users WHERE id = ? LIMIT 1");
                $stmt_avatar->bind_param("i", $current_user_id);
                $stmt_avatar->execute();
                $user_avatar = $stmt_avatar->get_result()->fetch_assoc()['avatar'] ?? null;

                // Удаляем все связанные данные пользователя
                $db->query("DELETE FROM messages WHERE sender_id = $current_user_id OR receiver_id = $current_user_id");
                $db->query("DELETE FROM reports WHERE reporter_id = $current_user_id OR reported_user_id = $current_user_id");
                $db->query("DELETE FROM access_keys WHERE creator_id = $current_user_id OR used_by = $current_user_id OR assigned_to = $current_user_id");
                $db->query("DELETE FROM moderation_actions WHERE user_id = $current_user_id OR moderator_id = $current_user_id");
                $db->query("DELETE FROM user_ips WHERE user_id = $current_user_id");
                $db->query("DELETE FROM typing_indicators WHERE user_id = $current_user_id");
                $db->query("DELETE FROM forum_topics WHERE user_id = $current_user_id");
                $db->query("DELETE FROM forum_comments WHERE user_id = $current_user_id");
                $db->query("DELETE FROM action_logs WHERE user_id = $current_user_id");
                $db->query("DELETE FROM chat_logs WHERE sender_id = $current_user_id OR receiver_id = $current_user_id");
                $db->query("DELETE FROM admin_notifications WHERE user_id = $current_user_id");
                $db->query("DELETE FROM moderator_notifications WHERE moderator_id = $current_user_id");
                
                // Удаляем самого пользователя
                $db->query("DELETE FROM users WHERE id = $current_user_id");
                
                $db->commit();

                // Удаляем файл аватара, если он не является аватаром по умолчанию
                if ($user_avatar && $user_avatar !== DEFAULT_AVATAR && file_exists($user_avatar)) {
                    unlink($user_avatar);
                }

                logAction($current_user_id, 'delete_account', "Аккаунт пользователя ID: $current_user_id удален.");
                session_destroy(); // Уничтожаем сессию после удаления аккаунта
                header("Location: index.php");
                exit;
            } catch (Exception $e) {
                $db->rollback();
                $error = "Ошибка при удалении аккаунта: " . $e->getMessage();
                error_log("Delete account error: " . $e->getMessage());
            }
        } else {
            $error = "Неверный пароль!";
        }
    }
    // Redirect to current tab to clear POST data (only if we have a valid profile_user_id)
    if ($profile_user_id > 0) {
        header("Location: profile.php?id=" . $profile_user_id . (isset($_GET['tab']) ? "&tab=" . $_GET['tab'] : ""));
    } else {
        header("Location: profile.php");
    }
    exit;
}

// --- Handle GET requests for actions ---
if (isset($_GET['clear_chat']) && isset($_GET['user_id'])) {
    // Ensure only own profile can clear chat history
    if (!$is_own_profile) {
        $_SESSION['error'] = "Вы не можете очистить историю чатов другого пользователя.";
        header("Location: profile.php?id=" . $profile_user_id . "&tab=chat_history");
        exit;
    }

    $user_id_to_clear = (int)$_GET['user_id'];
    try {
        $stmt = $db->prepare("DELETE FROM messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)");
        $stmt->bind_param("iiii", $current_user_id, $user_id_to_clear, $user_id_to_clear, $current_user_id);
        if ($stmt->execute()) {
            $success = "Переписка очищена!";
            logAction($current_user_id, 'clear_chat_history', "Очищена переписка с пользователем ID: $user_id_to_clear.");
        } else {
            $error = "Ошибка при очистке переписки: " . $db->error;
        }
    } catch (Exception $e) {
        $error = "Ошибка при очистке переписки: " . $e->getMessage();
        error_log("Clear chat error: " . $e->getMessage());
    }
    header("Location: profile.php?id=" . $profile_user_id . "&tab=chat_history");
    exit;
}

// Fetch chat partners for clearing history (only for own profile)
$chat_partners = [];
if ($is_own_profile) {
    $chat_partners = $db->query("
        SELECT DISTINCT u.id, u.username, u.avatar
        FROM users u
        JOIN messages m ON (u.id = m.sender_id OR u.id = m.receiver_id)
        WHERE (m.sender_id = $current_user_id OR m.receiver_id = $current_user_id) AND u.id != $current_user_id
        ORDER BY u.username ASC
    ")->fetch_all(MYSQLI_ASSOC);
}

// Current active tab
$current_tab = $_GET['tab'] ?? 'my_profile';

// If viewing another user's profile, default tab should be 'my_profile' (their profile)
if (!$is_own_profile && !in_array($current_tab, ['my_profile', 'followers', 'following', 'my_posts'])) {
    $current_tab = 'my_profile';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0a192f">
    <title>Профиль | <?= SITE_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css"> <!-- Новый style.css -->
    <link rel="icon" href="assets/favicon.ico" type="image/x-icon">
    <style>
        /* Profile Page Styles */
        .profile-page {
            min-height: 100vh;
            background: linear-gradient(135deg, var(--bg-dark), var(--bg-medium-dark));
        }

        .main-layout-container {
            display: flex;
            min-height: 100vh;
            max-width: 1400px;
            margin: 0 auto;
            gap: 20px;
            padding: 20px;
        }

        /* Profile Sidebar */
        .profile-sidebar {
            width: 320px;
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

        .profile-sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .profile-sidebar::-webkit-scrollbar-thumb {
            background: var(--accent-cyan);
            border-radius: 3px;
        }

        .profile-sidebar-header {
            text-align: center;
            padding-bottom: 25px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 20px;
        }

        .profile-sidebar-header .user-avatar {
            width: 120px;
            height: 120px;
            margin: 0 auto 20px;
            border: 3px solid var(--accent-cyan);
            box-shadow: 0 0 20px rgba(0, 217, 255, 0.4);
            transition: all var(--transition-normal);
            cursor: pointer;
        }

        .profile-sidebar-header .user-avatar:hover {
            transform: scale(1.05);
            box-shadow: 0 0 30px rgba(0, 217, 255, 0.6);
        }

        .profile-sidebar-header h2 {
            color: var(--accent-cyan);
            font-size: 1.8rem;
            margin-bottom: 10px;
            text-shadow: 0 0 10px rgba(0, 217, 255, 0.5);
            transition: all var(--transition-fast);
            cursor: default;
        }

        .profile-sidebar-header .follow-btn-container {
            margin-top: 15px;
        }

        .profile-sidebar-header .text-secondary {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        /* Profile Navigation */
        .profile-nav {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .profile-nav-item {
            margin: 0;
        }

        .profile-nav-item a {
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

        .profile-nav-item a:hover {
            background: var(--bg-medium);
            border-color: var(--accent-cyan);
            color: var(--accent-cyan);
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0, 217, 255, 0.2);
        }

        .profile-nav-item a.active {
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple));
            color: white;
            border-color: var(--accent-cyan);
            box-shadow: 0 4px 15px rgba(0, 217, 255, 0.4);
        }

        .profile-nav-item a i {
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
        }

        /* Profile Content */
        .profile-content {
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

        .profile-tab-content {
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

        .profile-section {
            margin-top: 25px;
        }

        .profile-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 25px;
        }

        .profile-info-item {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            transition: all var(--transition-normal);
        }

        .profile-info-item:hover {
            border-color: var(--accent-cyan);
            box-shadow: 0 4px 15px rgba(0, 217, 255, 0.2);
            transform: translateY(-2px);
        }

        .profile-info-item span:first-child {
            color: var(--text-secondary);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .profile-info-item span:last-child {
            color: var(--text-primary);
            font-size: 1.1rem;
            font-weight: 600;
        }

        /* Profile Stats Cards */
        .profile-stat-card {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            text-decoration: none;
            color: var(--text-primary);
            transition: all var(--transition-normal);
            cursor: pointer;
        }

        .profile-stat-card:hover {
            background: var(--bg-medium);
            border-color: var(--accent-cyan);
            transform: translateY(-5px);
            box-shadow: 0 6px 20px rgba(0, 217, 255, 0.25);
            text-decoration: none;
            color: var(--text-primary);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            transition: all var(--transition-normal);
        }

        .profile-stat-card:hover .stat-icon {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.4);
        }

        .stat-info {
            flex: 1;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent-cyan);
            line-height: 1;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* User List Styles */
        .user-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .user-list-item {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            transition: all var(--transition-normal);
            cursor: pointer;
            text-decoration: none;
            color: var(--text-primary);
            position: relative;
            overflow: hidden;
        }

        .user-list-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(0, 217, 255, 0.1), transparent);
            transition: left var(--transition-slow);
        }

        .user-list-item:hover::before {
            left: 100%;
        }

        .user-list-item:hover {
            background: var(--bg-medium);
            border-color: var(--accent-cyan);
            transform: translateY(-3px) translateX(5px);
            box-shadow: 0 6px 20px rgba(0, 217, 255, 0.25);
            text-decoration: none;
            color: var(--text-primary);
        }

        .user-list-item:hover .fas-chevron-right {
            transform: translateX(5px);
            color: var(--accent-cyan);
        }

        .user-list-item .fas-chevron-right {
            transition: all var(--transition-fast);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            text-decoration: none;
            color: var(--text-primary);
        }

        .user-info span {
            font-weight: 600;
            font-size: 1.05rem;
            color: var(--text-primary);
            transition: color var(--transition-fast);
        }

        .user-list-item:hover .user-info span {
            color: var(--accent-cyan);
        }

        .user-avatar-small {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--accent-cyan);
            box-shadow: 0 2px 8px rgba(0, 217, 255, 0.3);
            transition: all var(--transition-fast);
            cursor: pointer;
        }

        .user-avatar-small:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0, 217, 255, 0.5);
        }

        /* Comments List */
        .comments-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-top: 20px;
        }

        .comment-item {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--accent-cyan);
            padding: 20px;
            border-radius: var(--radius-md);
            transition: all var(--transition-normal);
        }

        .comment-item:hover {
            border-color: var(--accent-cyan);
            box-shadow: 0 4px 15px rgba(0, 217, 255, 0.2);
            transform: translateX(5px);
        }

        .comment-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .comment-user-link {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--text-primary);
            transition: all var(--transition-fast);
            padding: 4px 8px;
            border-radius: var(--radius-sm);
        }

        .comment-user-link:hover {
            background: var(--bg-medium);
            color: var(--accent-cyan);
            text-decoration: none;
        }

        .comment-user-link span {
            font-weight: 600;
            color: var(--accent-cyan);
            transition: color var(--transition-fast);
        }

        .comment-user-link:hover span {
            color: var(--accent-purple);
        }

        .comment-time {
            margin-left: auto;
            color: var(--text-secondary);
            font-size: 0.85rem;
        }

        .comment-content {
            color: var(--text-primary);
            line-height: 1.6;
            margin: 0;
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

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .main-layout-container {
                flex-direction: column;
                padding: 10px;
            }

            .mobile-sidebar-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .profile-sidebar {
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

            .profile-sidebar.active {
                transform: translateX(0);
            }

            .profile-content {
                padding: 20px;
                min-height: auto;
            }

            .user-list {
                grid-template-columns: 1fr;
            }

            .profile-info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="profile-page">
    <div class="cyber-grid"></div>
    <div class="animated-lines"></div>
    <div class="particles"></div>
    <button class="mobile-sidebar-toggle" onclick="toggleProfileSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="main-layout-container">
        <div class="profile-sidebar glass-effect">
            <div class="profile-sidebar-header">
                <img src="<?= $profile_avatar ?>" 
                     class="user-avatar avatar-lg"
                     onerror="this.src='<?= DEFAULT_AVATAR ?>'" style="margin-bottom: 15px;">
                <h2><?= $profile_username ?></h2>
                <p class="text-secondary">Роль: <?= match($profile_role) {
                    ROLE_ADMIN => 'Администратор',
                    ROLE_MODER => 'Модератор',
                    default => 'Пользователь'
                } ?></p>
                <?php if (!$is_own_profile): ?>
                    <div class="follow-btn-container">
                        <form method="POST" style="margin: 0;">
                            <input type="hidden" name="profile_user_id" value="<?= $profile_user_id ?>">
                            <?php if ($is_following): ?>
                                <button type="submit" name="follow_action" value="unfollow" class="btn btn-danger" style="width: 100%;">
                                    <i class="fas fa-user-minus"></i> Отписаться
                                </button>
                            <?php else: ?>
                                <button type="submit" name="follow_action" value="follow" class="btn btn-primary" style="width: 100%;">
                                    <i class="fas fa-user-plus"></i> Подписаться
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <ul class="profile-nav">
                <li class="profile-nav-item"><a href="?id=<?= $profile_user_id ?>&tab=my_profile" class="<?= $current_tab == 'my_profile' ? 'active' : '' ?>"><i class="fas fa-user-circle"></i> Профиль</a></li>
                <li class="profile-nav-item"><a href="?id=<?= $profile_user_id ?>&tab=followers" class="<?= $current_tab == 'followers' ? 'active' : '' ?>"><i class="fas fa-users"></i> Подписчики (<?= $followers_count ?>)</a></li>
                <li class="profile-nav-item"><a href="?id=<?= $profile_user_id ?>&tab=following" class="<?= $current_tab == 'following' ? 'active' : '' ?>"><i class="fas fa-user-friends"></i> Подписки (<?= $following_count ?>)</a></li>
                <li class="profile-nav-item"><a href="?id=<?= $profile_user_id ?>&tab=my_posts" class="<?= $current_tab == 'my_posts' ? 'active' : '' ?>"><i class="fas fa-comment-dots"></i> Посты (<?= count($profile_comments) ?>)</a></li>
                <?php if ($is_own_profile): ?>
                    <li class="profile-nav-item"><a href="?tab=change_avatar" class="<?= $current_tab == 'change_avatar' ? 'active' : '' ?>"><i class="fas fa-image"></i> Смена аватара</a></li>
                    <li class="profile-nav-item"><a href="?tab=change_password" class="<?= $current_tab == 'change_password' ? 'active' : '' ?>"><i class="fas fa-key"></i> Смена пароля</a></li>
                    <li class="profile-nav-item"><a href="my_invites.php" class="<?= $current_tab == 'my_invites' ? 'active' : '' ?>"><i class="fas fa-ticket-alt"></i> Мои инвайты</a></li>
                    <li class="profile-nav-item"><a href="?tab=chat_history" class="<?= $current_tab == 'chat_history' ? 'active' : '' ?>"><i class="fas fa-history"></i> История чатов</a></li>
                    <li class="profile-nav-item"><a href="?tab=delete_account" class="<?= $current_tab == 'delete_account' ? 'active' : '' ?>"><i class="fas fa-user-slash"></i> Удаление аккаунта</a></li>
                <?php endif; ?>
                <li class="profile-nav-item"><a href="chat.php"><i class="fas fa-arrow-left"></i> Вернуться в чат</a></li>
                <li class="profile-nav-item"><a href="logout.php" class="btn btn-danger"><i class="fas fa-sign-out-alt"></i> Выход</a></li>
            </ul>
        </div>

        <div class="profile-content">
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <!-- My Profile Tab -->
            <div id="my_profile-tab" class="profile-tab-content" style="display: <?= $current_tab == 'my_profile' ? 'block' : 'none' ?>;">
                <h3 class="section-title"><i class="fas fa-user-circle"></i> Профиль пользователя</h3>
                <div class="profile-section">
                    <div style="text-align: center; margin-bottom: 30px;">
                        <img src="<?= $profile_avatar ?>" 
                             alt="Аватар пользователя" 
                             class="user-avatar avatar-lg"
                             onerror="this.src='<?= DEFAULT_AVATAR ?>'"
                             style="width: 150px; height: 150px; border: 3px solid var(--accent-cyan); box-shadow: 0 0 25px rgba(0, 217, 255, 0.4);">
                    </div>
                    
                    <!-- Stats Cards -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px;">
                        <a href="?id=<?= $profile_user_id ?>&tab=followers" class="profile-stat-card">
                            <div class="stat-icon" style="background: linear-gradient(135deg, var(--accent-purple), #9b59b6);">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-number"><?= $followers_count ?></div>
                                <div class="stat-label">Подписчиков</div>
                            </div>
                        </a>
                        <a href="?id=<?= $profile_user_id ?>&tab=following" class="profile-stat-card">
                            <div class="stat-icon" style="background: linear-gradient(135deg, var(--accent-cyan), #3498db);">
                                <i class="fas fa-user-friends"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-number"><?= $following_count ?></div>
                                <div class="stat-label">Подписок</div>
                            </div>
                        </a>
                        <a href="?id=<?= $profile_user_id ?>&tab=my_posts" class="profile-stat-card">
                            <div class="stat-icon" style="background: linear-gradient(135deg, var(--accent-green), #2ecc71);">
                                <i class="fas fa-comment-dots"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-number"><?= count($profile_comments) ?></div>
                                <div class="stat-label">Комментариев</div>
                            </div>
                        </a>
                    </div>
                    
                    <div class="profile-info-grid">
                        <div class="profile-info-item">
                            <span>Имя пользователя:</span>
                            <span><?= $profile_username ?></span>
                        </div>
                        <div class="profile-info-item">
                            <span>ID пользователя:</span>
                            <span><?= $profile_user_id ?></span>
                        </div>
                        <div class="profile-info-item">
                            <span>Роль:</span>
                            <span class="badge <?= match($profile_role) {
                                ROLE_ADMIN => 'badge-active',
                                ROLE_MODER => 'badge-approved',
                                default => 'badge-pending'
                            } ?>">
                                <?= match($profile_role) {
                                    ROLE_ADMIN => 'Администратор',
                                    ROLE_MODER => 'Модератор',
                                    default => 'Пользователь'
                                } ?>
                            </span>
                        </div>
                        <div class="profile-info-item">
                            <span>Дата регистрации:</span>
                            <span><?= date('d.m.Y H:i', strtotime($profile_created_at)); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Followers Tab -->
            <div id="followers-tab" class="profile-tab-content" style="display: <?= $current_tab == 'followers' ? 'block' : 'none' ?>;">
                <h3 class="section-title"><i class="fas fa-users"></i> Подписчики <span style="color: var(--text-secondary); font-size: 1rem;">(<?= $followers_count ?>)</span></h3>
                <div class="profile-section">
                    <?php if (!empty($followers_list)): ?>
                        <div class="user-list">
                            <?php foreach ($followers_list as $user): ?>
                                <a href="profile.php?id=<?= $user['id'] ?>" class="user-list-item">
                                    <div class="user-info">
                                        <img src="<?= htmlspecialchars($user['avatar'] ?? DEFAULT_AVATAR) ?>" alt="Avatar" class="user-avatar-small" onerror="this.src='<?= DEFAULT_AVATAR ?>'">
                                        <span><?= htmlspecialchars($user['username']) ?></span>
                                    </div>
                                    <i class="fas fa-chevron-right" style="color: var(--text-secondary);"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 60px 20px; color: var(--text-secondary);">
                            <i class="fas fa-user-friends" style="font-size: 4rem; margin-bottom: 20px; opacity: 0.3;"></i>
                            <p style="font-size: 1.1rem;">У пользователя <?= $profile_username ?> пока нет подписчиков.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Following Tab -->
            <div id="following-tab" class="profile-tab-content" style="display: <?= $current_tab == 'following' ? 'block' : 'none' ?>;">
                <h3 class="section-title"><i class="fas fa-user-friends"></i> Подписки <span style="color: var(--text-secondary); font-size: 1rem;">(<?= $following_count ?>)</span></h3>
                <div class="profile-section">
                    <?php if (!empty($following_list)): ?>
                        <div class="user-list">
                            <?php foreach ($following_list as $user): ?>
                                <a href="profile.php?id=<?= $user['id'] ?>" class="user-list-item">
                                    <div class="user-info">
                                        <img src="<?= htmlspecialchars($user['avatar'] ?? DEFAULT_AVATAR) ?>" alt="Avatar" class="user-avatar-small" onerror="this.src='<?= DEFAULT_AVATAR ?>'">
                                        <span><?= htmlspecialchars($user['username']) ?></span>
                                    </div>
                                    <i class="fas fa-chevron-right" style="color: var(--text-secondary);"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 60px 20px; color: var(--text-secondary);">
                            <i class="fas fa-user-plus" style="font-size: 4rem; margin-bottom: 20px; opacity: 0.3;"></i>
                            <p style="font-size: 1.1rem;">Пользователь <?= $profile_username ?> пока ни на кого не подписан.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- My Posts Tab -->
            <div id="my_posts-tab" class="profile-tab-content" style="display: <?= $current_tab == 'my_posts' ? 'block' : 'none' ?>;">
                <h3 class="section-title"><i class="fas fa-comment-dots"></i> Посты (комментарии)</h3>
                <div class="profile-section">
                    <?php if (!$is_own_profile): // Allow commenting only on other users' profiles ?>
                        <form method="POST" style="margin-bottom: 30px;">
                            <input type="hidden" name="profile_owner_id" value="<?= $profile_user_id ?>">
                            <div class="form-group">
                                <textarea name="comment_content" id="comment_content" rows="3" placeholder="Оставьте комментарий..." required></textarea>
                                <label for="comment_content">Ваш комментарий</label>
                            </div>
                            <button type="submit" name="add_comment" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Оставить комментарий
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if (!empty($profile_comments)): ?>
                        <div class="comments-list">
                            <?php foreach ($profile_comments as $comment): ?>
                                <div class="comment-item">
                                    <div class="comment-header">
                                        <a href="profile.php?id=<?= $comment['commenter_id'] ?>" class="comment-user-link">
                                            <img src="<?= htmlspecialchars($comment['commenter_avatar'] ?? DEFAULT_AVATAR) ?>" alt="Avatar" class="user-avatar-small" onerror="this.src='<?= DEFAULT_AVATAR ?>'">
                                            <span><?= htmlspecialchars($comment['commenter_username']) ?></span>
                                        </a>
                                        <span class="comment-time"><?= date('d.m.Y H:i', strtotime($comment['created_at'])) ?></span>
                                    </div>
                                    <p class="comment-content"><?= htmlspecialchars($comment['comment_content']) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 60px 20px; color: var(--text-secondary);">
                            <i class="fas fa-comments" style="font-size: 4rem; margin-bottom: 20px; opacity: 0.3;"></i>
                            <p style="font-size: 1.1rem;">Пока нет комментариев для профиля <?= $profile_username ?>.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($is_own_profile): ?>
            <!-- Change Avatar Tab -->
            <div id="change_avatar-tab" class="profile-tab-content" style="display: <?= $current_tab == 'change_avatar' ? 'block' : 'none' ?>;">
                <h3 class="section-title"><i class="fas fa-image"></i> Смена аватара</h3>
                <div class="profile-section">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group" style="text-align: center;">
                            <label style="position: static; transform: none; padding: 0; background: none; color: var(--text-secondary);">Текущий аватар</label>
                            <img src="<?= $current_avatar ?>" 
                            alt="Ваш аватар" 
                            class="user-avatar avatar-lg"
                            onerror="this.src='<?= DEFAULT_AVATAR ?>'">
                        </div>
                        <div class="form-group">
                            <input type="file" name="avatar" id="avatar_upload" accept="image/jpeg,image/png,image/gif,image/webp" required placeholder=" ">
                            <i class="fas fa-upload"></i>
                            <label for="avatar_upload">Выберите новый аватар (JPG, PNG, GIF, WebP до 2MB)</label>
                        </div>
                        <button type="submit" class="btn btn-primary" name="update_avatar">
                            <i class="fas fa-upload"></i> Обновить аватар
                        </button>
                    </form>
                </div>
            </div>

            <!-- Change Password Tab -->
            <div id="change_password-tab" class="profile-tab-content" style="display: <?= $current_tab == 'change_password' ? 'block' : 'none' ?>;">
                <h3 class="section-title"><i class="fas fa-key"></i> Смена пароля</h3>
                <div class="profile-section">
                    <form method="POST">
                        <div class="form-group">
                            <input type="password" name="current_password" id="current_password" required placeholder=" ">
                            <i class="fas fa-lock"></i>
                            <label for="current_password">Текущий пароль</label>
                        </div>
                        <div class="form-group">
                            <input type="password" name="new_password" id="new_password" required placeholder=" ">
                            <i class="fas fa-lock"></i>
                            <label for="new_password">Новый пароль</label>
                        </div>
                        <div class="form-group">
                            <input type="password" name="confirm_password" id="confirm_password" required placeholder=" ">
                            <i class="fas fa-lock"></i>
                            <label for="confirm_password">Подтвердите пароль</label>
                        </div>
                        <button type="submit" name="change_password" class="btn btn-primary">
                            <i class="fas fa-exchange-alt"></i> Изменить пароль
                        </button>
                    </form>
                </div>
            </div>

            <!-- Chat History Tab -->
            <div id="chat_history-tab" class="profile-tab-content" style="display: <?= $current_tab == 'chat_history' ? 'block' : 'none' ?>;">
                <h3 class="section-title"><i class="fas fa-history"></i> Очистка переписки</h3>
                <div class="profile-section">
                    <p style="color: var(--text-secondary); margin-bottom: 20px;">Выберите пользователя, чтобы очистить всю переписку с ним. Это действие необратимо.</p>
                    <?php if (!empty($chat_partners)): ?>
                        <div class="user-list-clear-chat">
                            <?php foreach ($chat_partners as $user): ?>
                                <div class="user-list-item">
                                    <div class="user-info">
                                        <img src="<?= htmlspecialchars($user['avatar'] ?? DEFAULT_AVATAR) ?>" 
                                             alt="Avatar" 
                                             class="user-avatar-small"
                                             onerror="this.src='<?= DEFAULT_AVATAR ?>'">
                                        <span><?= htmlspecialchars($user['username']) ?></span>
                                    </div>
                                    <a href="?tab=chat_history&clear_chat=1&user_id=<?= $user['id'] ?>" class="btn btn-sm btn-danger" 
                                       onclick="return confirm('Очистить переписку с <?= htmlspecialchars($user['username']) ?>? Это действие необратимо!')">
                                        <i class="fas fa-trash-alt"></i> Очистить
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: var(--text-secondary);">У вас пока нет переписок.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Delete Account Tab -->
            <div id="delete_account-tab" class="profile-tab-content" style="display: <?= $current_tab == 'delete_account' ? 'block' : 'none' ?>;">
                <h3 class="section-title"><i class="fas fa-user-slash"></i> Удаление аккаунта</h3>
                <div class="profile-section">
                    <p style="color: var(--accent-pink); margin-bottom: 20px;">
                        Внимание! Это действие нельзя отменить. Все ваши данные, сообщения и связанные записи будут безвозвратно удалены.
                    </p>
                    <form method="POST" onsubmit="return confirm('Вы уверены? Это удалит ваш аккаунт и все данные!')">
                        <div class="form-group">
                            <input type="password" name="password" id="delete_password" required placeholder=" ">
                            <i class="fas fa-lock"></i>
                            <label for="delete_password">Введите пароль для подтверждения</label>
                        </div>
                        <button type="submit" name="delete_account_confirm" class="btn btn-danger">
                            <i class="fas fa-user-times"></i> Удалить аккаунт
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

<script>
    // Function to switch tabs
    function switchTab(tabId) {
        document.querySelectorAll('.profile-tab-content').forEach(tab => {
            tab.style.display = 'none';
        });
        document.getElementById(tabId + '-tab').style.display = 'block';

        document.querySelectorAll('.profile-nav-item a').forEach(link => {
            link.classList.remove('active');
        });
        // Find the correct link based on the tabId and current profile ID
        const profileUserId = <?= $profile_user_id ?>;
        let targetHref;
        if (['my_profile', 'followers', 'following', 'my_posts'].includes(tabId)) {
            targetHref = `?id=${profileUserId}&tab=${tabId}`;
        } else if (['change_avatar', 'change_password', 'delete_account', 'chat_history'].includes(tabId)) {
            // For tabs only accessible by the owner, pass the ID if present, otherwise just tab
            targetHref = profileUserId === <?= $current_user_id ?> ? `?tab=${tabId}` : `?id=${profileUserId}&tab=${tabId}`; 
        } else if (tabId === 'my_invites') {
            targetHref = 'my_invites.php';
        } else {
            targetHref = `?id=${profileUserId}&tab=${tabId}`;
        }
        const activeLink = document.querySelector(`.profile-nav-item a[href^="${targetHref}"]`);
        if (activeLink) {
            activeLink.classList.add('active');
        }

        // Close sidebar on mobile after tab selection
        if (window.innerWidth <= 768) {
            document.querySelector('.profile-sidebar').classList.remove('active');
        }
    }

    // Mobile sidebar toggle
    function toggleProfileSidebar() {
        document.querySelector('.profile-sidebar').classList.toggle('active');
    }

    // Set initial tab on page load
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const initialTab = urlParams.get('tab') || 'my_profile';
        switchTab(initialTab);
    });
</script>
</body>
</html>
