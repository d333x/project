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
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'];

// Handle new topic creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_topic'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $category = $_POST['category'] ?? 'general';
    $image_path = null;

    if (!empty($title) && !empty($content)) {
        // Handle image upload
        if (isset($_FILES['topic_image']) && $_FILES['topic_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'assets/forum_images/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_info = pathinfo($_FILES['topic_image']['name']);
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $file_extension = strtolower($file_info['extension'] ?? '');
            
            // Validate file extension
            if (empty($file_extension) || !in_array($file_extension, $allowed_extensions)) {
                $_SESSION['error'] = "Допустимые форматы изображений: JPG, JPEG, PNG, GIF, WEBP.";
            } else {
                $max_file_size = 5 * 1024 * 1024; // 5MB
                if ($_FILES['topic_image']['size'] <= $max_file_size) {
                    // Generate secure filename
                    $unique_filename = 'forum_' . uniqid() . '_' . time() . '.' . $file_extension;
                    $upload_path = $upload_dir . $unique_filename;
                    
                    // Additional security: check image type
                    $image_info = getimagesize($_FILES['topic_image']['tmp_name']);
                    if ($image_info !== false) {
                        if (move_uploaded_file($_FILES['topic_image']['tmp_name'], $upload_path)) {
                            $image_path = $upload_path;
                        } else {
                            $_SESSION['error'] = "Ошибка при загрузке изображения. Проверьте права доступа к папке.";
                        }
                    } else {
                        $_SESSION['error'] = "Загруженный файл не является изображением.";
                    }
                } else {
                    $_SESSION['error'] = "Размер изображения не должен превышать 5MB.";
                }
            }
        }
        
        if (!isset($_SESSION['error'])) {
            try {
                // Use transaction for data integrity
                $db->begin_transaction();
                
                // Insert with category
                $stmt = $db->prepare("INSERT INTO forum_topics (user_id, title, content, category, image_path) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("issss", $current_user_id, $title, $content, $category, $image_path);
                
                if ($stmt->execute()) {
                    $db->commit();
                    $_SESSION['success'] = "Тема успешно создана!";
                } else {
                    $db->rollback();
                    $_SESSION['error'] = "Ошибка при создании темы: " . $db->error;
                }
            } catch (Exception $e) {
                $db->rollback();
                $_SESSION['error'] = "Ошибка при создании темы: " . $e->getMessage();
            }
        }
    } else {
        $_SESSION['error'] = "Заголовок и содержание темы не могут быть пустыми.";
    }
    header("Location: forum.php");
    exit;
}

// Fetch topics list - сначала закрепленные, затем остальные по дате
$topics = $db->query("
    SELECT ft.*, u.username, u.avatar, COUNT(fc.id) as comment_count
    FROM forum_topics ft
    JOIN users u ON ft.user_id = u.id
    LEFT JOIN forum_comments fc ON ft.id = fc.topic_id
    GROUP BY ft.id
    ORDER BY ft.is_pinned DESC, ft.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Check if viewing a specific topic
$current_topic = null;
$comments = []; // Comments are now fetched in topic.php
if (isset($_GET['view_topic'])) {
    $topic_id = (int)$_GET['view_topic'];
    // Redirect to topic.php for viewing a specific topic
    header("Location: topic.php?topic_id=" . $topic_id);
    exit;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Форум | <?= SITE_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" href="assets/favicon.ico" type="image/x-icon">
    <style>
        :root {
            --bg-dark: #0a0a0f;
            --bg-darker: #050508;
            --text-primary: #ffffff;
            --text-secondary: #b0b0b0;
            --accent-cyan: #00d9ff;
            --accent-purple: #8b5cf6;
            --accent-pink: #ec4899;
            --accent-gold: #fbbf24;
            --glow-cyan: rgba(0, 217, 255, 0.4);
            --glow-purple: rgba(139, 92, 246, 0.4);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg-dark);
            color: var(--text-primary);
            min-height: 100vh;
            padding: 2rem;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated Background with Particles */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 30%, var(--accent-cyan) 0%, transparent 25%),
                radial-gradient(circle at 80% 70%, var(--accent-purple) 0%, transparent 25%),
                radial-gradient(circle at 50% 50%, var(--accent-pink) 0%, transparent 30%);
            background-size: 100% 100%;
            animation: moveBackground 25s infinite alternate ease-in-out;
            opacity: 0.08;
            z-index: -1;
            filter: blur(60px);
        }

        @keyframes moveBackground {
            0% { 
                background-position: 0% 0%, 100% 100%, 50% 50%; 
                transform: scale(1);
            }
            50% {
                transform: scale(1.1);
            }
            100% { 
                background-position: 100% 100%, 0% 0%, 30% 70%; 
                transform: scale(1);
            }
        }

        /* Particle Effect */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            pointer-events: none;
        }

        .particle {
            position: absolute;
            width: 3px;
            height: 3px;
            background: var(--accent-cyan);
            border-radius: 50%;
            opacity: 0.3;
            animation: float 20s infinite ease-in-out;
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0) translateX(0);
                opacity: 0;
            }
            10% {
                opacity: 0.3;
            }
            90% {
                opacity: 0.3;
            }
            100% {
                transform: translateY(-100vh) translateX(50px);
                opacity: 0;
            }
        }

        .forum-container {
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .forum-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 3rem;
            flex-wrap: wrap;
            gap: 1.5rem;
            animation: fadeInDown 0.8s ease-out;
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .forum-header h1 {
            font-size: clamp(2rem, 5vw, 3.5rem);
            font-weight: 900;
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple), var(--accent-pink));
            background-size: 200% 200%;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: gradientShift 5s ease infinite;
            filter: drop-shadow(0 0 20px rgba(0, 217, 255, 0.3));
        }

        @keyframes gradientShift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        .forum-header h1 i {
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 2rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .btn-back::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(0, 217, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .btn-back:hover::before {
            left: 100%;
        }

        .btn-back:hover {
            background: rgba(0, 217, 255, 0.1);
            border-color: var(--accent-cyan);
            transform: translateX(-5px);
            box-shadow: 0 10px 30px rgba(0, 217, 255, 0.3);
        }

        .forum-section {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 3rem;
            margin-bottom: 2.5rem;
            backdrop-filter: blur(20px);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
            transition: all 0.4s ease;
            animation: fadeInUp 0.8s ease-out;
            position: relative;
            overflow: hidden;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .forum-section::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(45deg, var(--accent-cyan), var(--accent-purple), var(--accent-pink));
            border-radius: 24px;
            opacity: 0;
            transition: opacity 0.4s;
            z-index: -1;
        }

        .forum-section:hover::before {
            opacity: 0.1;
        }

        .forum-section:hover {
            border-color: rgba(0, 217, 255, 0.3);
            box-shadow: 0 25px 70px rgba(0, 217, 255, 0.2);
            transform: translateY(-5px);
        }

        .section-title {
            font-size: 1.75rem;
            font-weight: 800;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            color: var(--text-primary);
            position: relative;
            padding-bottom: 1rem;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 80px;
            height: 3px;
            background: linear-gradient(90deg, var(--accent-cyan), var(--accent-purple));
            border-radius: 2px;
        }

        .section-title i {
            color: var(--accent-cyan);
            filter: drop-shadow(0 0 10px var(--glow-cyan));
        }

        .form-group {
            position: relative;
            margin-bottom: 2rem;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 1.25rem 1.25rem 1.25rem 3.5rem;
            background: rgba(255, 255, 255, 0.04);
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            color: var(--text-primary);
            font-size: 1rem;
            font-family: inherit;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent-cyan);
            background: rgba(255, 255, 255, 0.06);
            box-shadow: 0 0 30px rgba(0, 217, 255, 0.3), inset 0 0 20px rgba(0, 217, 255, 0.05);
            transform: translateY(-2px);
        }

        .form-group i {
            position: absolute;
            left: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            transition: all 0.3s ease;
            font-size: 1.1rem;
        }

        .form-group input:focus ~ i,
        .form-group textarea:focus ~ i {
            color: var(--accent-cyan);
            filter: drop-shadow(0 0 8px var(--glow-cyan));
        }

        .form-group label {
            position: absolute;
            left: 3.5rem;
            top: 1.25rem;
            color: var(--text-secondary);
            font-size: 0.95rem;
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .form-group input:focus ~ label,
        .form-group input:not(:placeholder-shown) ~ label,
        .form-group textarea:focus ~ label,
        .form-group textarea:not(:placeholder-shown) ~ label {
            top: -0.75rem;
            left: 1rem;
            font-size: 0.8rem;
            color: var(--accent-cyan);
            background: var(--bg-dark);
            padding: 0 0.75rem;
            font-weight: 600;
        }

        .form-group textarea {
            min-height: 180px;
            resize: vertical;
        }

        .char-counter {
            position: absolute;
            bottom: 1rem;
            right: 1.25rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .file-upload-group {
            margin-bottom: 2rem;
        }

        .file-input {
            display: none;
        }

        .file-label {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.5rem 2rem;
            border: 2px dashed rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.03);
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .file-label::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: radial-gradient(circle, rgba(0, 217, 255, 0.1), transparent);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
            border-radius: 50%;
        }

        .file-label:hover::before {
            width: 300px;
            height: 300px;
        }

        .file-label:hover {
            border-color: var(--accent-cyan);
            color: var(--accent-cyan);
            background: rgba(0, 217, 255, 0.08);
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 217, 255, 0.2);
        }

        .file-label.has-file {
            border-color: var(--accent-purple);
            color: var(--accent-purple);
            background: rgba(139, 92, 246, 0.12);
        }

        .file-label i {
            font-size: 1.5rem;
            z-index: 1;
        }

        .file-text {
            z-index: 1;
        }

        .image-preview {
            margin-top: 1.5rem;
            display: none;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .image-preview img {
            max-width: 350px;
            max-height: 350px;
            border-radius: 16px;
            border: 2px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.4);
            transition: transform 0.3s ease;
        }

        .image-preview img:hover {
            transform: scale(1.02);
        }

        .btn {
            padding: 1.25rem 2.5rem;
            border-radius: 16px;
            border: none;
            font-weight: 700;
            font-size: 1.05rem;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
            border-radius: 50%;
        }

        .btn:hover::before {
            width: 400px;
            height: 400px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple));
            color: var(--text-primary);
            box-shadow: 0 15px 40px rgba(0, 217, 255, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 50px rgba(0, 217, 255, 0.6);
        }

        .btn-primary i,
        .btn-primary span {
            z-index: 1;
        }

        .topic-list {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .topic-item {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 2rem;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            animation: fadeInUp 0.6s ease-out;
        }

        .topic-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(180deg, var(--accent-cyan), var(--accent-purple));
            transform: scaleY(0);
            transition: transform 0.4s ease;
        }

        .topic-item::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at var(--mouse-x, 50%) var(--mouse-y, 50%), rgba(0, 217, 255, 0.1), transparent 50%);
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }

        .topic-item:hover::after {
            opacity: 1;
        }

        .topic-item:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(0, 217, 255, 0.3);
            transform: translateX(8px);
            box-shadow: 0 15px 50px rgba(0, 217, 255, 0.2);
        }

        .topic-item:hover::before {
            transform: scaleY(1);
        }

        .topic-link-wrapper {
            display: flex;
            align-items: flex-start;
            gap: 1.5rem;
            text-decoration: none;
            color: inherit;
        }

        .user-avatar-small {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--accent-cyan);
            box-shadow: 0 0 20px rgba(0, 217, 255, 0.4);
            flex-shrink: 0;
            transition: all 0.3s ease;
        }

        .topic-link-wrapper:hover .user-avatar-small {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 0 30px rgba(0, 217, 255, 0.6);
        }

        .topic-info {
            flex: 1;
            min-width: 0;
        }

        .topic-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.75rem;
            transition: all 0.3s ease;
            line-height: 1.4;
        }

        .topic-link-wrapper:hover .topic-title {
            color: var(--accent-cyan);
            text-shadow: 0 0 20px rgba(0, 217, 255, 0.5);
        }

        .topic-meta {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .topic-meta span {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .topic-preview {
            font-size: 0.95rem;
            color: var(--text-secondary);
            line-height: 1.7;
            margin-top: 0.75rem;
        }

        .topic-image-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: var(--accent-purple);
            padding: 0.25rem 0.75rem;
            background: rgba(139, 92, 246, 0.1);
            border-radius: 12px;
        }

        .comment-count-badge {
            position: absolute;
            top: 2rem;
            right: 2rem;
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple));
            color: var(--text-primary);
            padding: 0.75rem 1.25rem;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 800;
            box-shadow: 0 8px 25px rgba(0, 217, 255, 0.4);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .topic-item:hover .comment-count-badge {
            transform: scale(1.1);
            box-shadow: 0 10px 30px rgba(0, 217, 255, 0.6);
        }

        .alert {
            padding: 1.25rem 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: slideInRight 0.5s ease-out;
            font-weight: 500;
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .alert-success {
            background: rgba(0, 217, 255, 0.15);
            border: 2px solid var(--accent-cyan);
            color: var(--accent-cyan);
            box-shadow: 0 10px 30px rgba(0, 217, 255, 0.2);
        }

        .alert-danger {
            background: rgba(236, 72, 153, 0.15);
            border: 2px solid var(--accent-pink);
            color: var(--accent-pink);
            box-shadow: 0 10px 30px rgba(236, 72, 153, 0.2);
        }

        .empty-state {
            text-align: center;
            padding: 4rem 1rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 5rem;
            margin-bottom: 1.5rem;
            opacity: 0.2;
            color: var(--accent-cyan);
            animation: pulse 2s ease-in-out infinite;
        }

        .empty-state p {
            font-size: 1.1rem;
        }

        /* Topic Badges */
        .topic-badge {
            position: absolute;
            top: 1rem;
            left: 1rem;
            padding: 0.5rem 1rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            z-index: 10;
            animation: fadeIn 0.5s ease;
        }

        .pinned-badge {
            background: linear-gradient(135deg, var(--accent-gold), #ff9800);
            color: #000;
            box-shadow: 0 4px 15px rgba(251, 191, 36, 0.5);
        }

        .closed-badge {
            background: rgba(236, 72, 153, 0.2);
            border: 2px solid var(--accent-pink);
            color: var(--accent-pink);
            left: auto;
            right: 1rem;
        }

        .topic-item.pinned {
            border-color: rgba(251, 191, 36, 0.3);
            background: rgba(251, 191, 36, 0.03);
        }

        .topic-item.closed {
            opacity: 0.7;
        }

        .category-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: rgba(139, 92, 246, 0.15);
            border: 1px solid rgba(139, 92, 246, 0.3);
            border-radius: 8px;
            font-size: 0.85rem;
            margin-right: 0.75rem;
            font-weight: 600;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 10px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.02);
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--accent-cyan), var(--accent-purple));
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, var(--accent-purple), var(--accent-pink));
        }

        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .forum-section {
                padding: 2rem 1.5rem;
            }

            .topic-item {
                padding: 1.5rem;
            }

            .comment-count-badge {
                position: static;
                margin-top: 1rem;
                display: inline-flex;
            }

            .topic-link-wrapper {
                flex-direction: column;
            }

            .user-avatar-small {
                width: 50px;
                height: 50px;
            }
        }
    </style>
</head>
<body>
    <!-- Particle Effect -->
    <div class="particles" id="particles"></div>

    <div class="forum-container">
        <div class="forum-header">
            <h1><i class="fas fa-comments"></i> Форум</h1>
            <a href="chat.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Назад в чат
            </a>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= $_SESSION['success'] ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <?= $_SESSION['error'] ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Create New Topic Section -->
        <div class="forum-section">
            <h3 class="section-title">
                <i class="fas fa-plus-circle"></i>
                Создать новую тему
            </h3>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <input type="text" name="title" id="topic_title" placeholder=" " required maxlength="255">
                    <i class="fas fa-heading"></i>
                    <label for="topic_title">Заголовок темы</label>
                </div>
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <select name="category" id="topic_category" style="width: 100%; padding: 1rem 1.25rem; background: rgba(255, 255, 255, 0.04); border: 2px solid rgba(255, 255, 255, 0.1); border-radius: 16px; color: var(--text-primary); font-size: 1rem; cursor: pointer; transition: all 0.3s;">
                        <option value="general">📌 Общее</option>
                        <option value="offtop">💬 Оффтоп</option>
                        <option value="question">❓ Вопрос</option>
                        <option value="leak">💾 Leak</option>
                        <option value="crack">🔓 Crack</option>
                        <option value="vulnerability">🔐 Уязвимость</option>
                    </select>
                </div>
                <div class="form-group">
                    <textarea name="content" id="topic_content" placeholder=" " rows="8" required maxlength="10000"></textarea>
                    <i class="fas fa-paragraph"></i>
                    <label for="topic_content">Содержание темы</label>
                    <div class="char-counter">
                        <span id="char-count">0</span>/10000 символов
                    </div>
                </div>
                <div class="form-group file-upload-group">
                    <input type="file" name="topic_image" id="topic_image" accept="image/*" class="file-input">
                    <label for="topic_image" class="file-label">
                        <i class="fas fa-image"></i>
                        <span class="file-text">Добавить изображение (макс. 5MB)</span>
                    </label>
                    <div id="image-preview" class="image-preview"></div>
                </div>
                <button type="submit" name="create_topic" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i> <span>Создать тему</span>
                </button>
            </form>
        </div>

        <!-- Active Topics List -->
        <div class="forum-section">
            <h3 class="section-title">
                <i class="fas fa-list-alt"></i>
                Активные темы
            </h3>
            <div class="topic-list">
                <?php if (!empty($topics)): ?>
                    <?php foreach ($topics as $topic): ?>
                        <div class="topic-item <?= $topic['is_pinned'] ? 'pinned' : '' ?> <?= $topic['is_closed'] ? 'closed' : '' ?>">
                            <?php if ($topic['is_pinned']): ?>
                                <span class="topic-badge pinned-badge"><i class="fas fa-thumbtack"></i> Закреплено</span>
                            <?php endif; ?>
                            <?php if ($topic['is_closed']): ?>
                                <span class="topic-badge closed-badge"><i class="fas fa-lock"></i> Закрыто</span>
                            <?php endif; ?>
                            <?php
                            $category_icons = [
                                'general' => '📌',
                                'offtop' => '💬',
                                'question' => '❓',
                                'leak' => '💾',
                                'crack' => '🔓',
                                'vulnerability' => '🔐'
                            ];
                            $category_names = [
                                'general' => 'Общее',
                                'offtop' => 'Оффтоп',
                                'question' => 'Вопрос',
                                'leak' => 'Leak',
                                'crack' => 'Crack',
                                'vulnerability' => 'Уязвимость'
                            ];
                            $cat = $topic['category'] ?? 'general';
                            ?>
                            <a href="topic.php?topic_id=<?= $topic['id'] ?>" class="topic-link-wrapper">
                                <img src="<?= htmlspecialchars($topic['avatar'] ?? DEFAULT_AVATAR) ?>" 
                                     alt="Avatar" 
                                     class="user-avatar-small"
                                     onerror="this.src='<?= DEFAULT_AVATAR ?>'">
                                <div class="topic-info">
                                    <div class="topic-title">
                                        <span class="category-badge"><?= $category_icons[$cat] ?> <?= $category_names[$cat] ?></span>
                                        <?= htmlspecialchars($topic['title']) ?>
                                    </div>
                                    <div class="topic-meta">
                                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($topic['username']) ?></span>
                                        <span><i class="fas fa-calendar"></i> <?= date('d.m.Y H:i', strtotime($topic['created_at'])) ?></span>
                                        <?php if (!empty($topic['image_path'])): ?>
                                            <span class="topic-image-indicator">
                                                <i class="fas fa-image"></i> С изображением
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="topic-preview">
                                        <?= htmlspecialchars(mb_substr(strip_tags($topic['content']), 0, 150)) ?>
                                        <?= mb_strlen($topic['content']) > 150 ? '...' : '' ?>
                                    </div>
                                </div>
                            </a>
                            <span class="comment-count-badge">
                                <i class="fas fa-comments"></i> <?= $topic['comment_count'] ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-comments"></i>
                        <p>На форуме пока нет тем. Будьте первым, кто создаст!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Create particles
        const particlesContainer = document.getElementById('particles');
        const particleCount = 30;
        
        for (let i = 0; i < particleCount; i++) {
            const particle = document.createElement('div');
            particle.className = 'particle';
            particle.style.left = Math.random() * 100 + '%';
            particle.style.animationDelay = Math.random() * 20 + 's';
            particle.style.animationDuration = (15 + Math.random() * 10) + 's';
            particlesContainer.appendChild(particle);
        }

        // Mouse tracking for topic items
        document.querySelectorAll('.topic-item').forEach(item => {
            item.addEventListener('mousemove', (e) => {
                const rect = item.getBoundingClientRect();
                const x = ((e.clientX - rect.left) / rect.width) * 100;
                const y = ((e.clientY - rect.top) / rect.height) * 100;
                item.style.setProperty('--mouse-x', x + '%');
                item.style.setProperty('--mouse-y', y + '%');
            });
        });

        // Character counter
        const textarea = document.getElementById('topic_content');
        const charCount = document.getElementById('char-count');
        
        if (textarea && charCount) {
            const updateCounter = () => {
                const count = textarea.value.length;
                charCount.textContent = count;
                
                if (count > 9000) {
                    charCount.style.color = 'var(--accent-pink)';
                } else if (count > 7000) {
                    charCount.style.color = 'var(--accent-purple)';
                } else {
                    charCount.style.color = 'var(--text-secondary)';
                }
            };
            
            textarea.addEventListener('input', updateCounter);
            updateCounter();
        }
        
        // Image upload preview
        const fileInput = document.getElementById('topic_image');
        const fileLabel = document.querySelector('.file-label');
        const fileText = document.querySelector('.file-text');
        const imagePreview = document.getElementById('image-preview');
        
        if (fileInput && fileLabel && fileText && imagePreview) {
            fileInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                
                if (file) {
                    if (file.size > 5 * 1024 * 1024) {
                        alert('Размер файла не должен превышать 5MB');
                        fileInput.value = '';
                        return;
                    }
                    
                    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                    if (!allowedTypes.includes(file.type)) {
                        alert('Допустимые форматы: JPG, JPEG, PNG, GIF, WEBP');
                        fileInput.value = '';
                        return;
                    }
                    
                    fileLabel.classList.add('has-file');
                    fileText.textContent = file.name;
                    
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        imagePreview.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
                        imagePreview.style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                } else {
                    fileLabel.classList.remove('has-file');
                    fileText.textContent = 'Добавить изображение (макс. 5MB)';
                    imagePreview.style.display = 'none';
                    imagePreview.innerHTML = '';
                }
            });
        }
        
        // Auto-resize textarea
        if (textarea) {
            const autoResize = () => {
                textarea.style.height = 'auto';
                textarea.style.height = textarea.scrollHeight + 'px';
            };
            
            textarea.addEventListener('input', autoResize);
            autoResize();
        }
    </script>
</body>
</html>
