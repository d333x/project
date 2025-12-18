<?php
require __DIR__ . '/config.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

// Проверка включен ли форум
if (!isForumEnabled()) {
    $_SESSION['error'] = 'Форум временно отключен администратором по техническим причинам.';
    header("Location: chat.php");
    exit;
}

$db = getDB();
$topic_id = isset($_GET['topic_id']) ? (int)$_GET['topic_id'] : 0;
$current_user_id = $_SESSION['user_id'];

if ($topic_id === 0) {
    header("Location: forum.php");
    exit;
}

// Create likes tables if not exist
$db->query("CREATE TABLE IF NOT EXISTS topic_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    topic_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_like (topic_id, user_id),
    INDEX idx_topic (topic_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS forum_comment_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    comment_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_like (comment_id, user_id),
    INDEX idx_comment (comment_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Fetch topic information with like count and user's like status
$topic_query = "
    SELECT ft.*, u.username as author_name, u.avatar as author_avatar,
           (SELECT COUNT(*) FROM topic_likes WHERE topic_id = ft.id) as like_count,
           (SELECT COUNT(*) FROM topic_likes WHERE topic_id = ft.id AND user_id = $current_user_id) as user_liked
    FROM forum_topics ft
    JOIN users u ON ft.user_id = u.id
    WHERE ft.id = $topic_id
";
$topic = $db->query($topic_query)->fetch_assoc();

if (!$topic) {
    $_SESSION['error'] = "Тема не найдена.";
    header("Location: forum.php");
    exit;
}

// Handle adding new comment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    // Проверяем, закрыта ли тема
    if ($topic['is_closed'] && $_SESSION['role'] < ROLE_MODER) {
        $_SESSION['error'] = "Эта тема закрыта для комментариев.";
        header("Location: topic.php?topic_id=$topic_id");
        exit;
    }

    $comment_content = $db->real_escape_string(trim($_POST['comment_content']));
    $user_id = $_SESSION['user_id'];

    if (empty($comment_content)) {
        $_SESSION['error'] = "Комментарий не может быть пустым.";
    } else {
        try {
            $stmt = $db->prepare("INSERT INTO forum_comments (topic_id, user_id, comment) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $topic_id, $user_id, $comment_content);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Комментарий успешно добавлен!";
            } else {
                $_SESSION['error'] = "Ошибка при добавлении комментария: " . $db->error;
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Ошибка при добавлении комментария: " . $e->getMessage();
        }
    }
    header("Location: topic.php?topic_id=$topic_id");
    exit;
}

// Увеличиваем счетчик просмотров
$db->query("UPDATE forum_topics SET views_count = views_count + 1 WHERE id = $topic_id");

// Fetch comments with like counts and user's like status
try {
    $comments_query = "
        SELECT fc.id, fc.comment as content, fc.created_at, u.username as author_name, u.avatar,
               (SELECT COUNT(*) FROM forum_comment_likes WHERE comment_id = fc.id) as like_count,
               (SELECT COUNT(*) FROM forum_comment_likes WHERE comment_id = fc.id AND user_id = $current_user_id) as user_liked
        FROM forum_comments fc
        JOIN users u ON fc.user_id = u.id
        WHERE fc.topic_id = $topic_id
        ORDER BY fc.created_at ASC
    ";
    
    $comments = $db->query($comments_query);
    
    if ($comments) {
        $comments = $comments->fetch_all(MYSQLI_ASSOC);
    } else {
        $comments = [];
        error_log("Database error fetching comments: " . $db->error);
    }
} catch (Exception $e) {
    $comments = [];
    error_log("Exception fetching comments: " . $e->getMessage());
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
    <title><?= htmlspecialchars($topic['title']) ?> | Форум | <?= SITE_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="topic-page">
<style>
    /* Like Button Styles */
    .like-button {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background: rgba(255, 255, 255, 0.05);
        border: 2px solid rgba(255, 255, 255, 0.1);
        border-radius: 12px;
        color: var(--text-secondary);
        cursor: pointer;
        transition: all 0.3s ease;
        font-size: 0.95rem;
        font-weight: 600;
    }
    
    .like-button:hover {
        background: rgba(236, 72, 153, 0.1);
        border-color: var(--accent-pink);
        color: var(--accent-pink);
        transform: translateY(-2px);
    }
    
    .like-button.liked {
        background: rgba(236, 72, 153, 0.2);
        border-color: var(--accent-pink);
        color: var(--accent-pink);
    }
    
    .like-button.liked i {
        animation: heartBeat 0.3s ease;
    }
    
    @keyframes heartBeat {
        0%, 100% { transform: scale(1); }
        25% { transform: scale(1.3); }
        50% { transform: scale(1.1); }
    }
    
    .topic-actions {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-top: 1.5rem;
        padding-top: 1.5rem;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .comment-actions {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-top: 0.75rem;
    }
    
    /* Image Modal Styles */
    .image-modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.9);
        backdrop-filter: blur(10px);
        justify-content: center;
        align-items: center;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .image-modal[style*="display: flex"] {
        opacity: 1;
    }

    .image-modal-content {
        max-width: 90%;
        max-height: 90%;
        position: relative;
    }

    .image-modal-content img {
        max-width: 100%;
        max-height: 90vh;
        border-radius: 12px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        border: 2px solid rgba(255, 255, 255, 0.1);
    }

    .image-modal-close {
        position: absolute;
        top: -40px;
        right: -40px;
        color: white;
        font-size: 35px;
        font-weight: bold;
        cursor: pointer;
        z-index: 1001;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
        transition: all 0.3s ease;
    }

    .image-modal-close:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: scale(1.1);
    }

    /* Fix for comment text wrapping */
    .comment-text {
        word-wrap: break-word;
        overflow-wrap: break-word;
        word-break: break-word;
        white-space: pre-wrap;
        width: 100%;
        overflow: hidden;
    }

    .topic-content-text {
        word-wrap: break-word;
        overflow-wrap: break-word;
        word-break: break-word;
        white-space: pre-wrap;
        width: 100%;
    }

    @media (max-width: 768px) {
        .image-modal-close {
            top: -50px;
            right: 10px;
            font-size: 30px;
        }
        
        .comment-text,
        .topic-content-text {
            word-break: break-all;
        }
        
        .topic-actions,
        .comment-actions {
            flex-wrap: wrap;
        }
    }
</style>
    <div class="topic-view-container glass-effect">
        <div class="card topic-header-card">
            <h2 class="section-title">
                <?php if ($topic['is_pinned']): ?>
                    <span class="topic-status-badge pinned"><i class="fas fa-thumbtack"></i> Закреплено</span>
                <?php endif; ?>
                <?php if ($topic['is_closed']): ?>
                    <span class="topic-status-badge closed"><i class="fas fa-lock"></i> Закрыто</span>
                <?php endif; ?>
                <?= htmlspecialchars($topic['title']) ?>
            </h2>
            
            <?php if ($_SESSION['role'] >= ROLE_MODER): ?>
            <div class="moderator-controls">
                <h4><i class="fas fa-shield-alt"></i> Управление темой</h4>
                <div class="mod-buttons">
                    <?php if ($topic['is_pinned']): ?>
                        <button onclick="manageTopic('unpin', <?= $topic_id ?>)" class="mod-btn unpin-btn">
                            <i class="fas fa-times"></i> Открепить
                        </button>
                    <?php else: ?>
                        <button onclick="manageTopic('pin', <?= $topic_id ?>)" class="mod-btn pin-btn">
                            <i class="fas fa-thumbtack"></i> Закрепить
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($topic['is_closed']): ?>
                        <button onclick="manageTopic('open', <?= $topic_id ?>)" class="mod-btn open-btn">
                            <i class="fas fa-unlock"></i> Открыть
                        </button>
                    <?php else: ?>
                        <button onclick="manageTopic('close', <?= $topic_id ?>)" class="mod-btn close-btn">
                            <i class="fas fa-lock"></i> Закрыть
                        </button>
                    <?php endif; ?>
                    
                    <button onclick="showCategoryModal()" class="mod-btn category-btn">
                        <i class="fas fa-tag"></i> Изменить категорию
                    </button>
                    
                    <?php if ($_SESSION['role'] >= ROLE_ADMIN): ?>
                        <button onclick="manageTopic('delete', <?= $topic_id ?>)" class="mod-btn delete-btn">
                            <i class="fas fa-trash"></i> Удалить тему
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="topic-meta-info">
                <img src="<?= htmlspecialchars($topic['author_avatar'] ?? DEFAULT_AVATAR) ?>" 
                     alt="Author Avatar" 
                     class="user-avatar avatar-sm"
                     onerror="this.src='<?= DEFAULT_AVATAR ?>'">
                <span>Автор: <?= htmlspecialchars($topic['author_name']) ?></span>
                <span><i class="fas fa-calendar-alt"></i> Создано: <?= date('d.m.Y H:i', strtotime($topic['created_at'])) ?></span>
                <span><i class="fas fa-eye"></i> Просмотров: <?= $topic['views_count'] ?? 0 ?></span>
            </div>
            <div class="topic-content-text">
                <?= nl2br(htmlspecialchars($topic['content'])) ?>
            </div>
            <?php if (!empty($topic['image_path']) && file_exists($topic['image_path'])): ?>
                <div class="topic-image">
                    <img src="<?= htmlspecialchars($topic['image_path']) ?>" 
                         alt="Topic Image" 
                         class="topic-image-full"
                         onclick="openImageModal(this.src)">
                </div>
            <?php endif; ?>
            
            <div class="topic-actions">
                <button class="like-button <?= $topic['user_liked'] ? 'liked' : '' ?>" 
                        onclick="likeTopic(<?= $topic_id ?>)" 
                        id="topic-like-btn">
                    <i class="<?= $topic['user_liked'] ? 'fas' : 'far' ?> fa-heart"></i>
                    <span id="topic-like-count"><?= $topic['like_count'] ?></span>
                </button>
                <a href="forum.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> К списку тем
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="comments-list">
            <h3 class="section-title"><i class="fas fa-comments"></i> Комментарии</h3>
            <?php if (!empty($comments)): ?>
                <?php foreach ($comments as $comment): ?>
                    <div class="comment-card glass-effect">
                        <img src="<?= htmlspecialchars($comment['avatar'] ?? DEFAULT_AVATAR) ?>" 
                             alt="Avatar" 
                             class="user-avatar avatar-sm"
                             onerror="this.src='<?= DEFAULT_AVATAR ?>'">
                        <div class="comment-content-wrapper">
                            <div class="comment-author"><?= htmlspecialchars($comment['author_name']) ?></div>
                            <div class="comment-text"><?= nl2br(htmlspecialchars($comment['content'])) ?></div>
                            <div class="comment-meta">
                                <?= date('d.m.Y H:i', strtotime($comment['created_at'])) ?>
                            </div>
                            <div class="comment-actions">
                                <button class="like-button <?= $comment['user_liked'] ? 'liked' : '' ?>" 
                                        onclick="likeComment(<?= $comment['id'] ?>, 'forum')" 
                                        id="comment-like-btn-<?= $comment['id'] ?>">
                                    <i class="<?= $comment['user_liked'] ? 'fas' : 'far' ?> fa-heart"></i>
                                    <span id="comment-like-count-<?= $comment['id'] ?>"><?= $comment['like_count'] ?></span>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="text-align: center; color: var(--text-secondary);">
                    В этой теме пока нет комментариев. Будьте первым!
                </p>
            <?php endif; ?>
        </div>

        <?php if (!$topic['is_closed'] || $_SESSION['role'] >= ROLE_MODER): ?>
        <div class="card comment-form-card">
            <h3 class="section-title"><i class="fas fa-reply"></i> Добавить комментарий</h3>
            <?php if ($topic['is_closed'] && $_SESSION['role'] >= ROLE_MODER): ?>
                <p style="color: var(--accent-pink); margin-bottom: 1rem;">
                    <i class="fas fa-info-circle"></i> Тема закрыта, но вы можете комментировать как модератор
                </p>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <textarea name="comment_content" id="comment_content" rows="5" placeholder=" " required></textarea>
                    <i class="fas fa-comment-dots"></i>
                    <label for="comment_content">Ваш комментарий</label>
                </div>
                <button type="submit" name="add_comment" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i> Отправить комментарий
                </button>
            </form>
        </div>
        <?php else: ?>
        <div class="card comment-form-card" style="text-align: center;">
            <i class="fas fa-lock" style="font-size: 3rem; color: var(--accent-pink); margin-bottom: 1rem;"></i>
            <p style="color: var(--text-secondary); font-size: 1.1rem;">Эта тема закрыта для комментариев</p>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Category Modal -->
    <div id="categoryModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeCategoryModal()">&times;</span>
            <h3><i class="fas fa-tag"></i> Выберите категорию</h3>
            <div class="category-options">
                <button onclick="setCategory('general')" class="category-option">📌 Общее</button>
                <button onclick="setCategory('offtop')" class="category-option">💬 Оффтоп</button>
                <button onclick="setCategory('question')" class="category-option">❓ Вопрос</button>
                <button onclick="setCategory('leak')" class="category-option">💾 Leak</button>
                <button onclick="setCategory('crack')" class="category-option">🔓 Crack</button>
                <button onclick="setCategory('vulnerability')" class="category-option">🔐 Уязвимость</button>
            </div>
        </div>
    </div>
    
    <!-- Image Modal -->
    <div id="imageModal" class="image-modal" onclick="closeImageModal()">
        <span class="image-modal-close" onclick="closeImageModal()">&times;</span>
        <div class="image-modal-content">
            <img id="modalImage" src="" alt="Full size image">
        </div>
    </div>
    
    <style>
        .moderator-controls {
            background: rgba(139, 92, 246, 0.1);
            border: 2px solid rgba(139, 92, 246, 0.3);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .moderator-controls h4 {
            color: var(--accent-purple);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .mod-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        
        .mod-btn {
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .pin-btn {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #000;
        }
        
        .unpin-btn {
            background: rgba(251, 191, 36, 0.2);
            border: 2px solid #fbbf24;
            color: #fbbf24;
        }
        
        .close-btn {
            background: rgba(236, 72, 153, 0.2);
            border: 2px solid var(--accent-pink);
            color: var(--accent-pink);
        }
        
        .open-btn {
            background: rgba(0, 217, 255, 0.2);
            border: 2px solid var(--accent-cyan);
            color: var(--accent-cyan);
        }
        
        .category-btn {
            background: rgba(139, 92, 246, 0.2);
            border: 2px solid var(--accent-purple);
            color: var(--accent-purple);
        }
        
        .delete-btn {
            background: rgba(239, 68, 68, 0.2);
            border: 2px solid #ef4444;
            color: #ef4444;
        }
        
        .mod-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }
        
        .topic-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 700;
            margin-right: 1rem;
        }
        
        .topic-status-badge.pinned {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #000;
        }
        
        .topic-status-badge.closed {
            background: rgba(236, 72, 153, 0.2);
            border: 2px solid var(--accent-pink);
            color: var(--accent-pink);
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(10px);
        }
        
        .modal-content {
            background: var(--bg-dark);
            margin: 10% auto;
            padding: 2rem;
            border: 2px solid var(--accent-purple);
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 60px rgba(139, 92, 246, 0.4);
        }
        
        .modal-content h3 {
            color: var(--accent-purple);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .modal-close {
            color: var(--text-secondary);
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .modal-close:hover {
            color: var(--accent-cyan);
        }
        
        .category-options {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        
        .category-option {
            padding: 1rem;
            background: rgba(139, 92, 246, 0.1);
            border: 2px solid rgba(139, 92, 246, 0.3);
            border-radius: 12px;
            color: var(--text-primary);
            cursor: pointer;
            transition: all 0.3s;
            font-size: 1rem;
        }
        
        .category-option:hover {
            background: rgba(139, 92, 246, 0.3);
            border-color: var(--accent-purple);
            transform: scale(1.05);
        }
    </style>
    
    <script>
        // Like topic function
        function likeTopic(topicId) {
            fetch('api/like_topic.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ topic_id: topicId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const btn = document.getElementById('topic-like-btn');
                    const count = document.getElementById('topic-like-count');
                    const icon = btn.querySelector('i');
                    
                    count.textContent = data.like_count;
                    
                    if (data.action === 'liked') {
                        btn.classList.add('liked');
                        icon.classList.remove('far');
                        icon.classList.add('fas');
                    } else {
                        btn.classList.remove('liked');
                        icon.classList.remove('fas');
                        icon.classList.add('far');
                    }
                }
            })
            .catch(error => console.error('Error:', error));
        }
        
        // Like comment function
        function likeComment(commentId, commentType) {
            fetch('api/like_comment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ comment_id: commentId, comment_type: commentType })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const btn = document.getElementById(`comment-like-btn-${commentId}`);
                    const count = document.getElementById(`comment-like-count-${commentId}`);
                    const icon = btn.querySelector('i');
                    
                    count.textContent = data.like_count;
                    
                    if (data.action === 'liked') {
                        btn.classList.add('liked');
                        icon.classList.remove('far');
                        icon.classList.add('fas');
                    } else {
                        btn.classList.remove('liked');
                        icon.classList.remove('fas');
                        icon.classList.add('far');
                    }
                }
            })
            .catch(error => console.error('Error:', error));
        }
    
        function manageTopic(action, topicId) {
            if (action === 'delete' && !confirm('Вы уверены, что хотите удалить эту тему? Это действие необратимо!')) {
                return;
            }
            
            fetch('api/forum_manage_topic.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=${action}&topic_id=${topicId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.redirect) {
                        window.location.href = data.redirect;
                    } else {
                        location.reload();
                    }
                } else {
                    alert('Ошибка: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Произошла ошибка при выполнении действия');
            });
        }
        
        function showCategoryModal() {
            document.getElementById('categoryModal').style.display = 'block';
        }
        
        function closeCategoryModal() {
            document.getElementById('categoryModal').style.display = 'none';
        }
        
        function setCategory(category) {
            const topicId = <?= $topic_id ?>;
            fetch('api/forum_manage_topic.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=set_category&topic_id=${topicId}&category=${category}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Ошибка: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Произошла ошибка');
            });
        }
        
        function openImageModal(imageSrc) {
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('modalImage');
            modal.style.display = 'flex';
            modalImg.src = imageSrc;
        }
        
        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            modal.style.display = 'none';
        }
        
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeImageModal();
            }
        });
    </script>
</body>
</html>