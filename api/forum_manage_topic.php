<?php
require __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация']);
    exit;
}

// Только модераторы и админы могут управлять темами
if ($_SESSION['role'] < ROLE_MODER) {
    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
    exit;
}

$db = getDB();
$action = $_POST['action'] ?? '';
$topic_id = (int)($_POST['topic_id'] ?? 0);

if ($topic_id === 0) {
    echo json_encode(['success' => false, 'message' => 'ID темы не указан']);
    exit;
}

// Проверяем существование темы
$topic_check = $db->query("SELECT id FROM forum_topics WHERE id = $topic_id");
if ($topic_check->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Тема не найдена']);
    exit;
}

try {
    switch ($action) {
        case 'pin':
            // Закрепление темы
            $db->query("UPDATE forum_topics SET is_pinned = 1 WHERE id = $topic_id");
            logAction($_SESSION['user_id'], 'forum_pin_topic', "Закреплена тема ID: $topic_id");
            echo json_encode(['success' => true, 'message' => 'Тема закреплена']);
            break;
            
        case 'unpin':
            // Открепление темы
            $db->query("UPDATE forum_topics SET is_pinned = 0 WHERE id = $topic_id");
            logAction($_SESSION['user_id'], 'forum_unpin_topic', "Откреплена тема ID: $topic_id");
            echo json_encode(['success' => true, 'message' => 'Тема откреплена']);
            break;
            
        case 'close':
            // Закрытие темы
            $db->query("UPDATE forum_topics SET is_closed = 1 WHERE id = $topic_id");
            logAction($_SESSION['user_id'], 'forum_close_topic', "Закрыта тема ID: $topic_id");
            echo json_encode(['success' => true, 'message' => 'Тема закрыта для комментариев']);
            break;
            
        case 'open':
            // Открытие темы
            $db->query("UPDATE forum_topics SET is_closed = 0 WHERE id = $topic_id");
            logAction($_SESSION['user_id'], 'forum_open_topic', "Открыта тема ID: $topic_id");
            echo json_encode(['success' => true, 'message' => 'Тема открыта для комментариев']);
            break;
            
        case 'set_category':
            // Установка категории
            $category = $_POST['category'] ?? 'general';
            $allowed_categories = ['general', 'offtop', 'question', 'leak', 'crack', 'vulnerability'];
            
            if (!in_array($category, $allowed_categories)) {
                echo json_encode(['success' => false, 'message' => 'Недопустимая категория']);
                exit;
            }
            
            $stmt = $db->prepare("UPDATE forum_topics SET category = ? WHERE id = ?");
            $stmt->bind_param("si", $category, $topic_id);
            $stmt->execute();
            
            logAction($_SESSION['user_id'], 'forum_set_category', "Установлена категория '$category' для темы ID: $topic_id");
            echo json_encode(['success' => true, 'message' => 'Категория установлена']);
            break;
            
        case 'delete':
            // Удаление темы (только для админов)
            if ($_SESSION['role'] < ROLE_ADMIN) {
                echo json_encode(['success' => false, 'message' => 'Только админы могут удалять темы']);
                exit;
            }
            
            // Удаляем сначала комментарии
            $db->query("DELETE FROM forum_comments WHERE topic_id = $topic_id");
            // Затем саму тему
            $db->query("DELETE FROM forum_topics WHERE id = $topic_id");
            
            logAction($_SESSION['user_id'], 'forum_delete_topic', "Удалена тема ID: $topic_id");
            echo json_encode(['success' => true, 'message' => 'Тема удалена', 'redirect' => 'forum.php']);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Неизвестное действие']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
}
?>
