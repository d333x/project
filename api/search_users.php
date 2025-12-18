<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . '/../config.php';

if (!isLoggedIn()) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<div class="no-results">Не авторизован</div>';
    exit;
}

$db = getDB();
$query = trim($_GET['query'] ?? '');
$current_user_id = $_SESSION['user_id'];

if (empty($query) || strlen($query) < 2) {
    echo '<div class="no-results">Введите не менее 2 символов для поиска.</div>';
    exit;
}

try {
    $stmt = $db->prepare("
        SELECT id, username, avatar 
        FROM users 
        WHERE username LIKE ? 
        AND id != ? 
        AND banned = 0
        ORDER BY username ASC
        LIMIT 10
    ");
    $search_param = '%' . $query . '%';
    $stmt->bind_param("si", $search_param, $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = $result->fetch_all(MYSQLI_ASSOC);

    if (empty($users)) {
        echo '<div class="no-results">Пользователи не найдены</div>';
    } else {
        foreach ($users as $user) {
            $avatar_src = htmlspecialchars($user['avatar'] ?? DEFAULT_AVATAR);
            $user_id = (int)$user['id'];
            $username = htmlspecialchars($user['username']);
            echo '<div class="search-result-item">' .
                 '<a href="profile.php?id=' . $user_id . '" class="search-result-profile-link">' .
                 '<img src="' . $avatar_src . '" class="user-avatar-small" onerror="this.src=\'' . DEFAULT_AVATAR . '\'" title="Профиль">' .
                 '<span>' . $username . '</span>' .
                 '</a>' .
                 '<a href="chat.php?user=' . $user_id . '" class="search-result-chat-link" title="Написать сообщение">' .
                 '<i class="fas fa-comment"></i>' .
                 '</a>' .
                 '</div>';
        }
    }
} catch (Exception $e) {
    error_log("Error searching users: " . $e->getMessage());
    echo '<div class="no-results">Ошибка поиска. Попробуйте снова.</div>';
}
?>