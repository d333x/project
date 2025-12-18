<?php
// Запуск сессии должен быть самым первым действием
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$db = getDB();
$current_user_id = $_SESSION['user_id'];
$current_username = htmlspecialchars($_SESSION['username']);
$current_avatar = htmlspecialchars($_SESSION['avatar'] ?? DEFAULT_AVATAR);

$selected_user_id = isset($_GET['user']) ? (int)$_GET['user'] : 0;
$selected_username = '';
$selected_user_avatar = DEFAULT_AVATAR;

// Fetch selected user details if any
if ($selected_user_id) {
    $stmt = $db->prepare("SELECT username, avatar FROM users WHERE id = ? AND banned = 0 LIMIT 1");
    $stmt->bind_param("i", $selected_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $user_data = $result->fetch_assoc();
        $selected_username = htmlspecialchars($user_data['username']);
        $selected_user_avatar = htmlspecialchars($user_data['avatar'] ?? DEFAULT_AVATAR);
    } else {
        $selected_user_id = 0;
        $_SESSION['error'] = "Выбранный пользователь не найден или забанен.";
        header("Location: chat.php");
        exit;
    }
}

// Fetch recent conversations with optimized query
$conversations = [];
try {
    $stmt = $db->prepare("
        SELECT 
            u.id as chat_partner_id,
            u.username,
            u.avatar,
            u.last_activity,
            (SELECT message FROM messages WHERE (sender_id = u.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.id) ORDER BY created_at DESC LIMIT 1) as last_message_content,
            (SELECT created_at FROM messages WHERE (sender_id = u.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.id) ORDER BY created_at DESC LIMIT 1) as last_message_time,
            (SELECT COUNT(id) FROM messages WHERE receiver_id = ? AND sender_id = u.id AND is_read = 0) as unread_count
        FROM users u
        WHERE u.id IN (
            SELECT DISTINCT CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END
            FROM messages
            WHERE sender_id = ? OR receiver_id = ?
        ) AND u.banned = 0 AND u.id != ?
        ORDER BY last_message_time DESC
        LIMIT 50
    ");
    $stmt->bind_param("iiiiiiiii", 
        $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id,
        $current_user_id, $current_user_id, $current_user_id, $current_user_id
    );
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $conversations[] = $row;
    }
} catch (Exception $e) {
    error_log("Error fetching conversations: " . $e->getMessage());
    $_SESSION['error'] = "Ошибка при загрузке списка диалогов.";
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0a0a0f">
    <title> Чат | <?= SITE_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
            --glow-cyan: rgba(0, 217, 255, 0.4);
            --glow-purple: rgba(139, 92, 246, 0.4);
            --bg-medium: rgba(255, 255, 255, 0.02);
            --bg-light: rgba(255, 255, 255, 0.05);
            --bg-card: rgba(255, 255, 255, 0.03);
            --border-color: rgba(255, 255, 255, 0.1);
        }

        body {
            background: var(--bg-dark);
            color: var(--text-primary);
        }

        /* Enhanced Chat Styles */
        .chat-layout {
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* Message Actions */
        .message-actions {
            position: absolute;
            top: -30px;
            right: 10px;
            background: var(--bg-medium);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 5px;
            display: none;
            gap: 5px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 10;
        }

        .message-bubble:hover .message-actions {
            display: flex;
        }

        .message-action-btn {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            cursor: pointer;
            padding: 6px 10px;
            border-radius: var(--radius-md);
            transition: all var(--transition-fast);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
        }

        .message-action-btn:hover {
            background: var(--bg-medium);
            color: var(--accent-cyan);
            border-color: var(--accent-cyan);
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(0, 217, 255, 0.3);
        }

        /* Reply Preview */
        .reply-preview {
            background: var(--bg-medium);
            border-left: 3px solid var(--accent-cyan);
            padding: 10px;
            margin-bottom: 10px;
            border-radius: var(--radius-sm);
            display: none;
            position: relative;
        }

        .reply-preview.active {
            display: block;
        }

        .reply-preview-close {
            position: absolute;
            top: 8px;
            right: 8px;
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 1.2rem;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-fast);
        }

        .reply-preview-close:hover {
            background: var(--accent-pink);
            color: white;
            border-color: var(--accent-pink);
            transform: rotate(90deg);
        }

        .reply-preview-text {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-top: 5px;
        }

        /* Message Reply Indicator */
        .message-reply-to {
            background: rgba(0, 217, 255, 0.1);
            border-left: 3px solid var(--accent-cyan);
            padding: 8px;
            margin-bottom: 8px;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        /* Emoji Picker */
        .emoji-picker {
            position: absolute;
            bottom: 70px;
            right: 20px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 15px;
            display: none;
            max-width: 350px;
            max-height: 300px;
            overflow-y: auto;
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
            z-index: 100;
        }

        .emoji-picker.active {
            display: block;
        }

        .emoji-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 5px;
        }

        .emoji-item {
            font-size: 1.5rem;
            cursor: pointer;
            padding: 5px;
            border-radius: var(--radius-sm);
            text-align: center;
            transition: all var(--transition-fast);
        }

        .emoji-item:hover {
            background: var(--bg-light);
            transform: scale(1.2);
        }

        /* File Upload Area */
        .file-upload-area {
            display: none;
            padding: 15px;
            background: var(--bg-medium);
            border-top: 1px solid var(--border-color);
        }

        .file-upload-area.active {
            display: block;
        }

        .file-preview {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: var(--bg-light);
            border-radius: var(--radius-md);
            margin-bottom: 10px;
        }

        .file-preview img {
            max-width: 100px;
            max-height: 100px;
            border-radius: var(--radius-sm);
        }

        /* Message Reactions */
        .message-reactions {
            display: flex;
            gap: 5px;
            margin-top: 5px;
            flex-wrap: wrap;
        }

        .reaction-item {
            background: var(--bg-medium);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2px 8px;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 3px;
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .reaction-item:hover {
            background: var(--bg-light);
            transform: scale(1.05);
        }

        .reaction-item.active {
            background: var(--accent-cyan);
            color: var(--text-inverse);
        }

        /* Search Messages */
        .search-messages {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-medium);
            display: none;
        }

        .search-messages.active {
            display: block;
        }

        .search-messages input {
            width: 100%;
            padding: 10px 15px;
            border-radius: 20px;
            border: 1px solid var(--border-color);
            background: var(--bg-light);
            color: var(--text-primary);
        }

        /* Message Status */
        .message-status {
            font-size: 0.7rem;
            color: var(--text-secondary);
            margin-left: 5px;
        }

        .message-status.read {
            color: var(--accent-cyan);
        }

        /* Enhanced Message Styles */
        .message-container {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            margin-bottom: 8px;
            animation: messageSlideIn 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            opacity: 0;
        }

        @keyframes messageSlideIn {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .message-container.incoming {
            flex-direction: row;
        }

        .message-container.outgoing {
            flex-direction: row-reverse;
        }

        .message-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--accent-cyan);
            box-shadow: 0 2px 10px rgba(0, 217, 255, 0.4);
            flex-shrink: 0;
            transition: all var(--transition-fast);
            cursor: pointer;
        }

        .message-avatar-link {
            text-decoration: none;
            display: flex;
            align-items: center;
        }

        /* Conversations List - Beautiful Design */
        .conversations-list {
            display: flex;
            flex-direction: column;
            gap: 0;
            padding: 0;
            margin: 0;
            width: 100%;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .conversations-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
            text-align: center;
            color: var(--text-secondary);
        }

        .conversations-empty i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.3;
            color: var(--accent-cyan);
        }

        .conversations-empty p {
            font-size: 1rem;
            margin: 0;
        }

        /* Conversation Item - Clean Design */
        .conversation-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            cursor: pointer;
            transition: all 0.25s ease;
            text-decoration: none;
            color: inherit;
            position: relative;
            width: 100%;
            box-sizing: border-box;
            background: transparent;
            user-select: none;
        }

        .conversation-item:hover {
            background: rgba(0, 217, 255, 0.08);
        }

        .conversation-item.active {
            background: rgba(0, 217, 255, 0.12);
            border-left: 3px solid var(--accent-cyan);
        }

        /* Avatar */
        .conversation-avatar {
            position: relative;
            flex-shrink: 0;
            width: 52px;
            height: 52px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .conversation-avatar .avatar {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            object-fit: cover;
            display: block;
            border: 2px solid transparent;
            transition: all 0.25s ease;
            cursor: pointer;
        }

        .conversation-item:hover .conversation-avatar .avatar {
            border-color: var(--accent-cyan);
            box-shadow: 0 0 15px rgba(0, 217, 255, 0.4);
            transform: scale(1.05);
        }

        .conversation-item.active .conversation-avatar .avatar {
            border-color: var(--accent-cyan);
            box-shadow: 0 0 20px rgba(0, 217, 255, 0.6);
        }

        .conversation-avatar .online-indicator,
        .conversation-avatar .offline-indicator {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid var(--bg-card);
            z-index: 10;
        }

        .conversation-avatar .online-indicator {
            background: #2ecc71;
            box-shadow: 0 0 10px rgba(46, 204, 113, 0.8);
        }

        .conversation-avatar .offline-indicator {
            background: #7f8c8d;
        }

        /* Content */
        .conversation-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
            overflow: hidden;
        }

        .conversation-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            width: 100%;
        }

        .conversation-name {
            flex: 1;
            min-width: 0;
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-decoration: none;
            transition: color 0.25s ease;
            cursor: pointer;
        }

        .conversation-name:hover {
            color: var(--accent-cyan);
        }

        .conversation-item:hover .conversation-name,
        .conversation-item.active .conversation-name {
            color: var(--accent-cyan);
        }

        .conversation-time {
            font-size: 0.75rem;
            color: var(--text-secondary);
            opacity: 0.6;
            flex-shrink: 0;
            font-weight: 500;
            transition: all 0.25s ease;
        }

        .conversation-item:hover .conversation-time,
        .conversation-item.active .conversation-time {
            opacity: 1;
            color: var(--accent-cyan);
        }

        .conversation-preview {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            width: 100%;
        }

        .preview-text {
            flex: 1;
            min-width: 0;
            font-size: 0.85rem;
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            opacity: 0.7;
            transition: all 0.25s ease;
        }

        .preview-empty {
            flex: 1;
            font-size: 0.85rem;
            color: var(--text-secondary);
            font-style: italic;
            opacity: 0.5;
        }

        .conversation-item:hover .preview-text,
        .conversation-item.active .preview-text {
            opacity: 1;
            color: var(--text-primary);
        }

        .unread-count {
            background: var(--accent-pink);
            color: white;
            border-radius: 12px;
            padding: 3px 9px;
            font-size: 0.7rem;
            font-weight: 700;
            min-width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .message-container.outgoing .message-avatar {
            border-color: var(--accent-purple);
            box-shadow: 0 2px 10px rgba(200, 0, 255, 0.4);
        }

        /* Search Results Styles */
        .search-result-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 15px;
            gap: 12px;
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            margin-bottom: 8px;
            transition: all var(--transition-fast);
        }

        .search-result-item:hover {
            background: var(--bg-medium);
            border-color: var(--accent-cyan);
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0, 217, 255, 0.2);
        }

        .search-result-profile-link {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            text-decoration: none;
            color: var(--text-primary);
            transition: color var(--transition-fast);
        }

        .search-result-profile-link:hover {
            color: var(--accent-cyan);
            text-decoration: none;
        }

        .search-result-profile-link img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--accent-cyan);
            box-shadow: 0 2px 8px rgba(0, 217, 255, 0.3);
            transition: all var(--transition-fast);
        }

        .search-result-profile-link:hover img {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0, 217, 255, 0.5);
        }

        .search-result-profile-link span {
            font-weight: 600;
            font-size: 1rem;
        }

        .search-result-chat-link {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            background: var(--bg-medium);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            color: var(--text-secondary);
            text-decoration: none;
            transition: all var(--transition-fast);
            font-size: 1.1rem;
        }

        .search-result-chat-link:hover {
            background: var(--accent-cyan);
            color: white;
            border-color: var(--accent-cyan);
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0, 217, 255, 0.3);
        }

        .message-avatar:hover {
            transform: scale(1.15);
            box-shadow: 0 4px 15px rgba(0, 217, 255, 0.6);
        }

        .message-bubble {
            max-width: 70%;
            padding: 14px 18px;
            border-radius: 18px;
            word-wrap: break-word;
            line-height: 1.6;
            font-size: 0.95rem;
            position: relative;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            transition: all var(--transition-normal);
        }

        .message-bubble:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }

        .message-bubble.incoming {
            background: linear-gradient(135deg, var(--bg-light), var(--bg-medium));
            color: var(--text-primary);
            border-bottom-left-radius: 4px;
            border: 1px solid var(--border-color);
        }

        .message-bubble.incoming:hover {
            border-color: var(--accent-cyan);
            box-shadow: 0 4px 18px rgba(0, 217, 255, 0.25);
        }

        .message-bubble.outgoing {
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple));
            color: white;
            border-bottom-right-radius: 4px;
            box-shadow: 0 4px 18px rgba(0, 217, 255, 0.35);
        }

        .message-bubble.outgoing:hover {
            box-shadow: 0 6px 25px rgba(0, 217, 255, 0.45);
        }

        .message-content {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .message-text {
            word-break: break-word;
            white-space: pre-wrap;
        }

        .message-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            margin-top: 4px;
            font-size: 0.7rem;
        }

        .message-time {
            color: rgba(255, 255, 255, 0.75);
            font-weight: 500;
            opacity: 0.8;
        }

        .message-bubble.incoming .message-time {
            color: var(--text-secondary);
        }

        .message-status {
            display: inline-flex;
            align-items: center;
            color: rgba(255, 255, 255, 0.7);
            transition: all var(--transition-fast);
        }

        .message-status.read {
            color: rgba(255, 255, 255, 0.95);
        }

        .message-status.sending {
            color: rgba(255, 255, 255, 0.6);
            animation: pulse 1.5s ease-in-out infinite;
        }

        .message-status.error {
            color: var(--accent-pink);
        }

        .message-status i {
            font-size: 0.7rem;
        }

        @keyframes pulse {
            0%, 100% { opacity: 0.6; }
            50% { opacity: 1; }
        }

        /* Chat Messages Area Enhancement */
        .chat-messages {
            gap: 4px !important;
            background: linear-gradient(to bottom, var(--bg-dark), var(--bg-medium-dark)) !important;
            position: relative;
        }

        .chat-messages::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 60px;
            background: linear-gradient(to bottom, var(--bg-dark), transparent);
            pointer-events: none;
            z-index: 1;
        }

        /* Chat Input Area Enhancement */
        .chat-input-area {
            background: linear-gradient(to top, var(--bg-medium), var(--bg-dark)) !important;
            box-shadow: 0 -4px 25px rgba(0, 0, 0, 0.4) !important;
        }

        .chat-input-area textarea {
            border-radius: 24px !important;
            border: 2px solid var(--border-color) !important;
            padding: 14px 20px !important;
            min-height: 50px !important;
            transition: all var(--transition-normal) !important;
        }

        .chat-input-area textarea:focus {
            border-color: var(--accent-cyan) !important;
            box-shadow: 0 0 0 3px rgba(0, 217, 255, 0.1), 0 4px 18px rgba(0, 217, 255, 0.25) !important;
            background: var(--bg-medium) !important;
        }

        /* Audio Message */
        .audio-message {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: var(--bg-light);
            border-radius: var(--radius-md);
        }

        .audio-play-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--accent-cyan);
            border: 2px solid var(--accent-cyan);
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-fast);
            box-shadow: 0 4px 12px rgba(0, 217, 255, 0.4);
        }

        .audio-play-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 18px rgba(0, 217, 255, 0.6);
        }

        .audio-waveform {
            flex: 1;
            height: 30px;
            background: var(--bg-medium);
            border-radius: var(--radius-sm);
        }

        /* Pinned Messages */
        .pinned-messages {
            background: var(--bg-medium);
            border-bottom: 1px solid var(--border-color);
            padding: 10px 20px;
            display: none;
        }

        .pinned-messages.active {
            display: block;
        }

        .pinned-message-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px;
            background: var(--bg-light);
            border-radius: var(--radius-sm);
            margin-bottom: 5px;
        }

        /* Typing Indicator Enhanced */
        .typing-indicator {
            display: none;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            color: var(--accent-green);
            font-size: 0.9rem;
            background: var(--bg-light);
            margin: 0 20px 10px;
            border-radius: 18px;
            border: 1px solid var(--border-color);
            animation: typingPulse 1.5s ease-in-out infinite;
        }

        .typing-indicator.active {
            display: flex;
        }

        @keyframes typingPulse {
            0%, 100% {
                opacity: 0.7;
                transform: translateY(0);
            }
            50% {
                opacity: 1;
                transform: translateY(-2px);
            }
        }

        .typing-dots {
            display: flex;
            gap: 3px;
        }

        .typing-dot {
            width: 6px;
            height: 6px;
            background: var(--accent-green);
            border-radius: 50%;
            animation: typingBounce 1.4s infinite;
        }

        .typing-dot:nth-child(2) {
            animation-delay: 0.2s;
        }

        .typing-dot:nth-child(3) {
            animation-delay: 0.4s;
        }

        @keyframes typingBounce {
            0%, 60%, 100% {
                transform: translateY(0);
            }
            30% {
                transform: translateY(-10px);
            }
        }

        /* Image Lightbox */
        .image-lightbox {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.95);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }

        .image-lightbox.active {
            display: flex;
        }

        .image-lightbox img {
            max-width: 90%;
            max-height: 90%;
            border-radius: var(--radius-md);
        }

        .image-lightbox-close {
            position: absolute;
            top: 20px;
            right: 20px;
            background: var(--bg-medium);
            border: 2px solid var(--border-color);
            color: white;
            font-size: 2rem;
            cursor: pointer;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-fast);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
        }

        .image-lightbox-close:hover {
            background: var(--accent-pink);
            border-color: var(--accent-pink);
            transform: rotate(90deg) scale(1.1);
            box-shadow: 0 6px 18px rgba(236, 72, 153, 0.6);
        }

        /* Message Edit Mode */
        .message-bubble.editing {
            border: 2px solid var(--accent-cyan);
            animation: pulse 1s infinite;
        }

        @keyframes pulse {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(0, 217, 255, 0.7);
            }
            50% {
                box-shadow: 0 0 0 10px rgba(0, 217, 255, 0);
            }
        }

        /* Scroll to Bottom Button */
        .scroll-to-bottom {
            position: absolute;
            bottom: 80px;
            right: 20px;
            width: 48px;
            height: 48px;
            background: var(--accent-cyan);
            border: 2px solid var(--accent-cyan);
            border-radius: 50%;
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 217, 255, 0.4);
            display: none;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-fast);
            z-index: 10;
        }

        .scroll-to-bottom.visible {
            display: flex;
        }

        .scroll-to-bottom:hover {
            transform: scale(1.1) translateY(-2px);
            box-shadow: 0 6px 18px rgba(0, 217, 255, 0.6);
        }

        .scroll-to-bottom .unread-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--accent-pink);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Message Image/File */
        .message-image {
            max-width: 300px;
            border-radius: var(--radius-md);
            margin-top: 8px;
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .message-image:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }

        .message-file {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: var(--bg-light);
            border-radius: var(--radius-md);
            margin-top: 8px;
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .message-file:hover {
            background: var(--bg-medium);
        }

        .message-file i {
            font-size: 1.5rem;
            color: var(--accent-cyan);
        }

        /* Chat Header User Link */
        .chat-header-user-link {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
            text-decoration: none;
            color: inherit;
            transition: all var(--transition-fast);
            padding: 5px;
            border-radius: var(--radius-md);
        }

        .chat-header-user-link:hover {
            background: rgba(0, 217, 255, 0.1);
        }

        .chat-header-user-link .user-name {
            transition: color var(--transition-fast);
        }

        .chat-header-user-link:hover .user-name {
            color: var(--accent-cyan);
        }

        .chat-header-user-link .user-avatar {
            transition: all var(--transition-fast);
        }

        .chat-header-user-link:hover .user-avatar {
            transform: scale(1.05);
            box-shadow: 0 0 20px rgba(0, 217, 255, 0.5);
        }

        /* Chat Header Actions */
        .chat-header-actions {
            display: flex;
            gap: 8px;
            margin-left: auto;
            align-items: center;
        }

        .chat-header-btn {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 10px 14px;
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all var(--transition-fast);
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            min-width: 40px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .chat-header-btn:hover {
            background: var(--bg-medium);
            color: var(--accent-cyan);
            border-color: var(--accent-cyan);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 217, 255, 0.3);
        }

        .chat-header-btn.active {
            background: var(--accent-cyan);
            color: white;
            border-color: var(--accent-cyan);
            box-shadow: 0 0 15px rgba(0, 217, 255, 0.5);
        }

        /* Input Toolbar */
        .input-toolbar {
            display: flex;
            gap: 8px;
            padding: 12px 20px;
            border-top: 1px solid var(--border-color);
            background: var(--bg-medium);
            align-items: center;
        }

        .toolbar-btn {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 1.1rem;
            padding: 0;
            border-radius: var(--radius-md);
            transition: all var(--transition-fast);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            min-width: 40px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .toolbar-btn:hover {
            color: var(--accent-cyan);
            background: var(--bg-medium);
            border-color: var(--accent-cyan);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 217, 255, 0.3);
        }

        .toolbar-btn.active {
            background: var(--accent-cyan);
            color: white;
            border-color: var(--accent-cyan);
            box-shadow: 0 0 15px rgba(0, 217, 255, 0.5);
        }

        /* Chat Input Area Button */
        .chat-input-area .btn {
            padding: 12px 24px;
            border-radius: var(--radius-md);
            font-size: 1rem;
            height: 45px;
            min-width: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            font-weight: 600;
        }

        .chat-input-area .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(0, 217, 255, 0.4);
        }

        /* Message Date Separator */
        .date-separator {
            text-align: center;
            margin: 20px 0;
            position: relative;
        }

        .date-separator::before,
        .date-separator::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 40%;
            height: 1px;
            background: var(--border-color);
        }

        .date-separator::before {
            left: 0;
        }

        .date-separator::after {
            right: 0;
        }

        .date-separator span {
            background: var(--bg-dark);
            padding: 5px 15px;
            border-radius: 15px;
            font-size: 0.85rem;
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }

        /* Sidebar Footer Menu */
        .sidebar-footer {
            padding: 15px 20px;
            border-top: 1px solid var(--border-color);
            background: var(--bg-medium);
            position: relative;
        }

        .menu-trigger-btn {
            width: 100%;
            background: var(--gradient-primary);
            border: none;
            color: white;
            padding: 12px 20px;
            border-radius: var(--radius-md);
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all var(--transition-normal);
            box-shadow: 0 4px 12px rgba(236, 72, 153, 0.3);
        }

        .menu-trigger-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(236, 72, 153, 0.5);
        }

        .menu-trigger-btn i {
            font-size: 1.1rem;
        }

        /* Quick Menu Overlay */
        .quick-menu-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 9998;
            opacity: 0;
            visibility: hidden;
            transition: all var(--transition-normal);
        }

        .quick-menu-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* Quick Menu Panel */
        .quick-menu {
            position: fixed;
            top: 0;
            right: -400px;
            width: 380px;
            height: 100vh;
            background: var(--bg-medium);
            border-left: 1px solid var(--border-color);
            box-shadow: -4px 0 20px rgba(0, 0, 0, 0.5);
            z-index: 9999;
            display: flex;
            flex-direction: column;
            transition: right var(--transition-slow) cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }

        .quick-menu.active {
            right: 0;
        }

        .quick-menu-header {
            padding: 25px 20px;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-dark);
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .quick-menu-header h3 {
            margin: 0;
            color: var(--accent-cyan);
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            text-shadow: 0 0 10px rgba(0, 217, 255, 0.5);
        }

        .quick-menu-header h3 i {
            color: var(--accent-pink);
        }

        .quick-menu-close {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            width: 40px;
            height: 40px;
            border-radius: var(--radius-md);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-fast);
            font-size: 1.2rem;
        }

        .quick-menu-close:hover {
            background: var(--accent-pink);
            color: white;
            border-color: var(--accent-pink);
            transform: rotate(90deg);
        }

        .quick-menu-content {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .quick-menu-item {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            text-decoration: none;
            color: var(--text-primary);
            transition: all var(--transition-normal);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .quick-menu-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(0, 217, 255, 0.1), transparent);
            transition: left var(--transition-slow);
        }

        .quick-menu-item:hover::before {
            left: 100%;
        }

        .quick-menu-item:hover {
            background: var(--bg-medium);
            border-color: var(--accent-cyan);
            transform: translateX(-5px);
            box-shadow: 0 4px 15px rgba(0, 217, 255, 0.2);
        }

        .quick-menu-icon {
            width: 50px;
            height: 50px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            transition: all var(--transition-normal);
        }

        .quick-menu-item:hover .quick-menu-icon {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 6px 18px rgba(0, 217, 255, 0.4);
        }

        .forum-icon {
            background: linear-gradient(135deg, var(--accent-purple), #9b59b6);
            color: white;
        }

        .shop-icon {
            background: linear-gradient(135deg, var(--accent-cyan), #3498db);
            color: white;
        }

        .moder-icon {
            background: linear-gradient(135deg, var(--accent-orange), #e67e22);
            color: white;
        }

        .admin-icon {
            background: linear-gradient(135deg, var(--accent-pink), var(--accent-purple));
            color: white;
        }

        .quick-menu-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .quick-menu-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .quick-menu-desc {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .quick-menu-item i.fa-chevron-right {
            color: var(--text-secondary);
            font-size: 0.9rem;
            transition: all var(--transition-fast);
        }

        .quick-menu-item:hover i.fa-chevron-right {
            color: var(--accent-cyan);
            transform: translateX(5px);
        }

        /* Scrollbar for Quick Menu */
        .quick-menu-content::-webkit-scrollbar {
            width: 6px;
        }

        .quick-menu-content::-webkit-scrollbar-track {
            background: var(--bg-dark);
        }

        .quick-menu-content::-webkit-scrollbar-thumb {
            background: var(--accent-cyan);
            border-radius: 3px;
        }

        .quick-menu-content::-webkit-scrollbar-thumb:hover {
            background: var(--accent-purple);
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .quick-menu {
                width: 100%;
                right: -100%;
                max-width: 320px;
            }

            .quick-menu-header {
                padding: 20px 15px;
            }

            .quick-menu-header h3 {
                font-size: 1.3rem;
            }

            .quick-menu-content {
                padding: 15px;
            }

            .quick-menu-item {
                padding: 15px;
            }

            .quick-menu-icon {
                width: 45px;
                height: 45px;
                font-size: 1.3rem;
            }

            .menu-trigger-btn span {
                display: none;
            }

            .menu-trigger-btn {
                padding: 12px;
            }
        }
    </style>
</head>
<body class="chat-page">
    <div class="cyber-grid"></div>
    <div class="animated-lines"></div>
    <div class="particles"></div>
    
    <!-- Mobile Header -->
    <div class="mobile-header">
        <button class="menu-toggle" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <?php if ($selected_user_id): ?>
            <a href="profile.php?id=<?= $selected_user_id ?>" class="current-chat-name" style="text-decoration: none; color: inherit; transition: color var(--transition-fast);">
                <?= $selected_username ?>
            </a>
        <?php else: ?>
            <span class="current-chat-name">Выберите чат</span>
        <?php endif; ?>
        <?php if ($selected_user_id): ?>
            <button class="btn btn-sm btn-secondary" onclick="showReportModal(<?= $selected_user_id ?>, '<?= $selected_username ?>')">
                <i class="fas fa-flag"></i>
            </button>
        <?php else: ?>
            <span style="width: 30px;"></span>
        <?php endif; ?>
    </div>

    <div class="chat-layout">
        <!-- Sidebar -->
        <div class="sidebar glass-effect">
            <div class="sidebar-header">
                <a href="profile.php" style="text-decoration: none;">
                    <img src="<?= $current_avatar ?>" alt="My Avatar" class="user-avatar" onerror="this.src='<?= DEFAULT_AVATAR ?>'" style="cursor: pointer;" title="Мой профиль">
                </a>
                <a href="profile.php" style="text-decoration: none; flex: 1;">
                    <div class="user-info" style="cursor: pointer;">
                        <h3 style="color: var(--text-primary); transition: color var(--transition-fast);"><?= $current_username ?></h3>
                    <p>Онлайн: <span id="online-count"><?= getOnlineUsersCount() ?></span></p>
                </div>
                </a>
                <a href="profile.php" class="btn btn-sm btn-primary" title="Мой профиль">
                    <i class="fas fa-user-circle"></i>
                </a>
                <a href="logout.php" class="btn btn-sm btn-danger" title="Выход">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>

            <div class="sidebar-search">
                <div class="form-group" style="margin-bottom: 0;">
                    <input type="text" id="user-search-input" placeholder=" " class="form-control">
                    <i class="fas fa-search"></i>
                    <label for="user-search-input">Найти пользователя</label>
                </div>
                <div id="search-results" class="search-results" style="display: none;"></div>
            </div>

            <div class="conversations-list">
                <?php if (!empty($conversations)): ?>
                    <?php foreach ($conversations as $conv): ?>
                        <div class="conversation-item <?= $conv['chat_partner_id'] == $selected_user_id ? 'active' : '' ?>"
                             data-user-id="<?= $conv['chat_partner_id'] ?>"
                             onclick="window.location.href='?user=<?= $conv['chat_partner_id'] ?>'">
                            <div class="conversation-avatar">
                                <img src="<?= htmlspecialchars($conv['avatar'] ?? DEFAULT_AVATAR) ?>" 
                                     alt="<?= htmlspecialchars($conv['username']) ?>" 
                                     class="avatar"
                                     onerror="this.src='<?= DEFAULT_AVATAR ?>'"
                                     onclick="event.stopPropagation(); window.location.href='profile.php?id=<?= $conv['chat_partner_id'] ?>'">
                                <span class="<?= (strtotime($conv['last_activity']) > time() - ONLINE_THRESHOLD_MINUTES * 60) ? 'online-indicator' : 'offline-indicator' ?>"></span>
                            </div>
                            <div class="conversation-content">
                                <div class="conversation-header">
                                    <span class="conversation-name" 
                                          onclick="event.stopPropagation(); window.location.href='profile.php?id=<?= $conv['chat_partner_id'] ?>'">
                                        <?= htmlspecialchars($conv['username']) ?>
                                    </span>
                                    <?php if (!empty($conv['last_message_time'])): ?>
                                        <span class="conversation-time"><?= date('H:i', strtotime($conv['last_message_time'])) ?></span>
                                    <?php endif; ?>
                            </div>
                                <div class="conversation-preview">
                                    <?php if (!empty($conv['last_message_content'])): ?>
                                        <span class="preview-text"><?= htmlspecialchars(mb_substr(strip_tags($conv['last_message_content']), 0, 50)) . (mb_strlen(strip_tags($conv['last_message_content'])) > 50 ? '...' : '') ?></span>
                                    <?php else: ?>
                                        <span class="preview-empty">Нет сообщений</span>
                                    <?php endif; ?>
                            <?php if ($conv['unread_count'] > 0): ?>
                                        <span class="unread-count"><?= $conv['unread_count'] ?></span>
                            <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="conversations-empty">
                        <i class="fas fa-comments"></i>
                        <p>Начните новую переписку, используя поиск.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Sidebar Footer with Menu Toggle -->
            <div class="sidebar-footer">
                <button class="menu-trigger-btn" onclick="toggleQuickMenu()" title="Быстрое меню">
                    <i class="fas fa-bars"></i>
                    <span>Меню</span>
                </button>
            </div>
        </div>

        <!-- Quick Menu Overlay -->
        <div class="quick-menu-overlay" id="quick-menu-overlay" onclick="closeQuickMenu()"></div>
        <div class="quick-menu" id="quick-menu">
            <div class="quick-menu-header">
                <h3><i class="fas fa-rocket"></i> Быстрое меню</h3>
                <button class="quick-menu-close" onclick="closeQuickMenu()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="quick-menu-content">
                <?php if (isForumEnabled()): ?>
                <a href="forum.php" class="quick-menu-item" onclick="closeQuickMenu()">
                    <div class="quick-menu-icon forum-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="quick-menu-info">
                        <span class="quick-menu-title">Форум</span>
                        <span class="quick-menu-desc">Обсуждения и темы</span>
                    </div>
                    <i class="fas fa-chevron-right"></i>
                </a>
                <?php endif; ?>
                <?php if (isShopEnabled()): ?>
                <a href="shop.php" class="quick-menu-item" onclick="closeQuickMenu()">
                    <div class="quick-menu-icon shop-icon">
                        <i class="fas fa-store"></i>
                    </div>
                    <div class="quick-menu-info">
                        <span class="quick-menu-title">D3X Shop</span>
                        <span class="quick-menu-desc">Магазин товаров</span>
                    </div>
                    <i class="fas fa-chevron-right"></i>
                </a>
                <?php endif; ?>
                    <?php if ($_SESSION['role'] >= ROLE_MODER): ?>
                <a href="moder.php" class="quick-menu-item" onclick="closeQuickMenu()">
                    <div class="quick-menu-icon moder-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="quick-menu-info">
                        <span class="quick-menu-title">Модерация</span>
                        <span class="quick-menu-desc">Панель модератора</span>
                    </div>
                    <i class="fas fa-chevron-right"></i>
                </a>
                    <?php endif; ?>
                    <?php if ($_SESSION['role'] >= ROLE_ADMIN): ?>
                <a href="admin.php" class="quick-menu-item" onclick="closeQuickMenu()">
                    <div class="quick-menu-icon admin-icon">
                        <i class="fas fa-cog"></i>
                </div>
                    <div class="quick-menu-info">
                        <span class="quick-menu-title">Админ-панель</span>
                        <span class="quick-menu-desc">Управление системой</span>
                    </div>
                    <i class="fas fa-chevron-right"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Main Chat Area -->
        <div class="chat-main">
            <?php if ($selected_user_id): ?>
                <!-- Chat Header -->
                <div class="chat-header glass-effect">
                    <a href="profile.php?id=<?= $selected_user_id ?>" class="chat-header-user-link">
                    <img src="<?= $selected_user_avatar ?>" alt="User Avatar" class="user-avatar" onerror="this.src='<?= DEFAULT_AVATAR ?>'">
                    <div class="user-details">
                        <span class="user-name"><?= $selected_username ?></span>
                        <span class="status-text" id="typing-status"></span>
                    </div>
                    </a>
                    <div class="chat-header-actions">
                        <button class="chat-header-btn" onclick="toggleSearch()" title="Поиск по сообщениям">
                            <i class="fas fa-search"></i>
                        </button>
                        <button class="chat-header-btn" onclick="showPinnedMessages()" title="Закрепленные сообщения">
                            <i class="fas fa-thumbtack"></i>
                        </button>
                        <button class="chat-header-btn" onclick="showReportModal(<?= $selected_user_id ?>, '<?= $selected_username ?>')">
                            <i class="fas fa-flag"></i>
                        </button>
                    </div>
                </div>

                <!-- Search Messages -->
                <div class="search-messages" id="search-messages">
                    <input type="text" id="search-input" placeholder="Поиск по сообщениям..." onkeyup="searchMessages(this.value)">
                </div>

                <!-- Pinned Messages -->
                <div class="pinned-messages" id="pinned-messages">
                    <!-- Pinned messages will be loaded here -->
                </div>

                <!-- Typing Indicator -->
                <div class="typing-indicator" id="typing-indicator">
                    <span><?= $selected_username ?> печатает</span>
                    <div class="typing-dots">
                        <div class="typing-dot"></div>
                        <div class="typing-dot"></div>
                        <div class="typing-dot"></div>
                    </div>
                </div>

                <!-- Chat Messages -->
                <div class="chat-messages" id="chat-messages">
                    <p style="text-align: center; color: var(--text-secondary);">Загрузка сообщений...</p>
                </div>

                <!-- Scroll to Bottom Button -->
                <button class="scroll-to-bottom" id="scroll-to-bottom" onclick="scrollToBottom()">
                    <i class="fas fa-arrow-down"></i>
                    <span class="unread-count" id="unread-count-badge" style="display: none;">0</span>
                </button>

                <!-- Reply Preview -->
                <div class="reply-preview" id="reply-preview">
                    <button class="reply-preview-close" onclick="cancelReply()">×</button>
                    <div><strong>Ответ на:</strong></div>
                    <div class="reply-preview-text" id="reply-preview-text"></div>
                </div>

                <!-- File Upload Area -->
                <div class="file-upload-area" id="file-upload-area">
                    <div class="file-preview" id="file-preview"></div>
                    <button class="btn btn-secondary btn-sm" onclick="cancelFileUpload()">Отменить</button>
                </div>

                <!-- Input Toolbar -->
                <div class="input-toolbar">
                    <button class="toolbar-btn" onclick="toggleEmojiPicker()" title="Эмодзи">
                        <i class="fas fa-smile"></i>
                    </button>
                    <button class="toolbar-btn" onclick="document.getElementById('file-input').click()" title="Прикрепить файл">
                        <i class="fas fa-paperclip"></i>
                    </button>
                    <button class="toolbar-btn" onclick="startVoiceRecording()" title="Голосовое сообщение">
                        <i class="fas fa-microphone"></i>
                    </button>
                    <input type="file" id="file-input" style="display: none;" onchange="handleFileSelect(event)" accept="image/*,video/*,.pdf,.doc,.docx">
                </div>

                <!-- Chat Input Area -->
                <div class="chat-input-area glass-effect">
                    <textarea id="message-input" placeholder="Введите сообщение..." rows="1"></textarea>
                    <button class="btn btn-primary" onclick="sendMessage()">
                        <i class="fas fa-paper-plane"></i> Отправить
                    </button>
                </div>

                <!-- Emoji Picker -->
                <div class="emoji-picker" id="emoji-picker">
                    <div class="emoji-grid" id="emoji-grid"></div>
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100%; text-align: center; color: var(--text-secondary);">
                    <i class="fas fa-comments" style="font-size: 5rem; margin-bottom: 20px; color: var(--accent-purple);"></i>
                    <h2 style="color: var(--accent-cyan);">Добро пожаловать в чат!</h2>
                    <p>Выберите собеседника из списка слева или найдите нового пользователя.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Image Lightbox -->
    <div class="image-lightbox" id="image-lightbox" onclick="closeLightbox()">
        <button class="image-lightbox-close">×</button>
        <img id="lightbox-image" src="" alt="Full size image">
    </div>

    <!-- Report Modal -->
    <div id="reportModal" class="modal" style="display: none;">
        <div class="modal-content glass-effect">
            <span class="close-button" onclick="closeReportModal()">&times;</span>
            <h2 class="section-title"><i class="fas fa-flag"></i> Пожаловаться на пользователя</h2>
            <form id="reportForm" method="POST" action="report.php">
                <input type="hidden" name="reported_user_id" id="modal-reported-user-id">
                <div class="form-group">
                    <label for="modal-reported-username">Пользователь:</label>
                    <input type="text" id="modal-reported-username" readonly style="background-color: var(--bg-medium); border-color: var(--border-color);">
                </div>
                <div class="form-group">
                    <label for="report-reason">Причина жалобы:</label>
                    <select name="reason" id="report-reason" required>
                        <option value="">Выберите причину</option>
                        <option value="Спам">Спам</option>
                        <option value="Оскорбления">Оскорбления</option>
                        <option value="Неприемлемый контент">Неприемлемый контент</option>
                        <option value="Мошенничество">Мошенничество</option>
                        <option value="Другое">Другое</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="report-details">Подробности (необязательно):</label>
                    <textarea name="details" id="report-details" rows="3" placeholder="Опишите ситуацию подробнее..."></textarea>
                </div>
                <button type="submit" name="report_user" class="btn">Отправить жалобу</button>
            </form>
        </div>
    </div>

    <script>
    // Global variables
    const currentUserId = <?= $current_user_id ?>;
    const selectedUserId = <?= $selected_user_id ?>;
    const chatMessagesDiv = document.getElementById('chat-messages');
    const messageInput = document.getElementById('message-input');
    const typingStatusElement = document.getElementById('typing-status');
    const typingIndicator = document.getElementById('typing-indicator');
    const scrollToBottomBtn = document.getElementById('scroll-to-bottom');
    
    let lastMessageTimestamp = 0;
    let typingTimeout;
    let isTyping = false;
    let replyToMessageId = null;
    let editingMessageId = null;
    let selectedFile = null;
    let isRecording = false;
    let mediaRecorder = null;
    let audioChunks = [];

    // Emoji list
    const emojis = ['😀','😃','😄','😁','😆','😅','🤣','😂','🙂','🙃','😉','😊','😇','🥰','😍','🤩','😘','😗','😚','😙','🥲','😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔','🤐','🤨','😐','😑','😶','😏','😒','🙄','😬','🤥','😌','😔','😪','🤤','😴','😷','🤒','🤕','🤢','🤮','🤧','🥵','🥶','🥴','😵','🤯','🤠','🥳','🥸','😎','🤓','🧐','😕','😟','🙁','☹️','😮','😯','😲','😳','🥺','😦','😧','😨','😰','😥','😢','😭','😱','😖','😣','😞','😓','😩','😫','🥱','😤','😡','😠','🤬','😈','👿','💀','☠️','💩','🤡','👹','👺','👻','👽','👾','🤖','😺','😸','😹','😻','😼','😽','🙀','😿','😾','❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','👍','👎','👌','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','👇','☝️','✋','🤚','🖐️','🖖','👋','🤙','💪','🦾','🖕','✍️','🙏','🦶','🦵','🦿','💄','💋','👄','🦷','👅','👂','🦻','👃','👣','👁️','👀','🧠','🫀','🫁','🗣️','👤','👥','🫂','👶','👧','🧒','👦','👩','🧑','👨','👩‍🦱','🧑‍🦱','👨‍🦱','👩‍🦰','🧑‍🦰','👨‍🦰','👱‍♀️','👱','👱‍♂️','👩‍🦳','🧑‍🦳','👨‍🦳','👩‍🦲','🧑‍🦲','👨‍🦲','🧔','👵','🧓','👴','👲','👳‍♀️','👳','👳‍♂️','🧕','👮‍♀️','👮','👮‍♂️','👷‍♀️','👷','👷‍♂️','💂‍♀️','💂','💂‍♂️','🕵️‍♀️','🕵️','🕵️‍♂️','👩‍⚕️','🧑‍⚕️','👨‍⚕️','👩‍🌾','🧑‍🌾','👨‍🌾','👩‍🍳','🧑‍🍳','👨‍🍳','👩‍🎓','🧑‍🎓','👨‍🎓','👩‍🎤','🧑‍🎤','👨‍🎤','👩‍🏫','🧑‍🏫','👨‍🏫','👩‍🏭','🧑‍🏭','👨‍🏭','👩‍💻','🧑‍💻','👨‍💻','👩‍💼','🧑‍💼','👨‍💼','👩‍🔧','🧑‍🔧','👨‍🔧','👩‍🔬','🧑‍🔬','👨‍🔬','👩‍🎨','🧑‍🎨','👨‍🎨','👩‍🚒','🧑‍🚒','👨‍🚒','👩‍✈️','🧑‍✈️','👨‍✈️','👩‍🚀','🧑‍🚀','👨‍🚀','👩‍⚖️','🧑‍⚖️','👨‍⚖️','👰‍♀️','👰','👰‍♂️','🤵‍♀️','🤵','🤵‍♂️','👸','🤴','🥷','🦸‍♀️','🦸','🦸‍♂️','🦹‍♀️','🦹','🦹‍♂️','🤶','🧑‍🎄','🎅','🧙‍♀️','🧙','🧙‍♂️','🧝‍♀️','🧝','🧝‍♂️','🧛‍♀️','🧛','🧛‍♂️','🧟‍♀️','🧟','🧟‍♂️','🧞‍♀️','🧞','🧞‍♂️','🧜‍♀️','🧜','🧜‍♂️','🧚‍♀️','🧚','🧚‍♂️','👼','🤰','🤱','👩‍🍼','🧑‍🍼','👨‍🍼','🙇‍♀️','🙇','🙇‍♂️','💁‍♀️','💁','💁‍♂️','🙅‍♀️','🙅','🙅‍♂️','🙆‍♀️','🙆','🙆‍♂️','🙋‍♀️','🙋','🙋‍♂️','🧏‍♀️','🧏','🧏‍♂️','🤦‍♀️','🤦','🤦‍♂️','🤷‍♀️','🤷','🤷‍♂️','🙎‍♀️','🙎','🙎‍♂️','🙍‍♀️','🙍','🙍‍♂️','💇‍♀️','💇','💇‍♂️','💆‍♀️','💆','💆‍♂️','🧖‍♀️','🧖','🧖‍♂️','💅','🤳','💃','🕺','👯‍♀️','👯','👯‍♂️','🕴️','👩‍🦽','🧑‍🦽','👨‍🦽','👩‍🦼','🧑‍🦼','👨‍🦼','🚶‍♀️','🚶','🚶‍♂️','👩‍🦯','🧑‍🦯','👨‍🦯','🧎‍♀️','🧎','🧎‍♂️','🏃‍♀️','🏃','🏃‍♂️','🧍‍♀️','🧍','🧍‍♂️','👫','👭','👬','👩‍❤️‍👨','👩‍❤️‍👩','💑','👨‍❤️‍👨','👩‍❤️‍💋‍👨','👩‍❤️‍💋‍👩','💏','👨‍❤️‍💋‍👨','👪','👨‍👩‍👦','👨‍👩‍👧','👨‍👩‍👧‍👦','👨‍👩‍👦‍👦','👨‍👩‍👧‍👧','👨‍👨‍👦','👨‍👨‍👧','👨‍👨‍👧‍👦','👨‍👨‍👦‍👦','👨‍👨‍👧‍👧','👩‍👩‍👦','👩‍👩‍👧','👩‍👩‍👧‍👦','👩‍👩‍👦‍👦','👩‍👩‍👧‍👧','👨‍👦','👨‍👦‍👦','👨‍👧','👨‍👧‍👦','👨‍👧‍👧','👩‍👦','👩‍👦‍👦','👩‍👧','👩‍👧‍👦','👩‍👧‍👧','🗣️','👤','👥','🫂','👣','🐵','🐒','🦍','🦧','🐶','🐕','🦮','🐕‍🦺','🐩','🐺','🦊','🦝','🐱','🐈','🐈‍⬛','🦁','🐯','🐅','🐆','🐴','🐎','🦄','🦓','🦌','🦬','🐮','🐂','🐃','🐄','🐷','🐖','🐗','🐽','🐏','🐑','🐐','🐪','🐫','🦙','🦒','🐘','🦣','🦏','🦛','🐭','🐁','🐀','🐹','🐰','🐇','🐿️','🦫','🦔','🦇','🐻','🐻‍❄️','🐨','🐼','🦥','🦦','🦨','🦘','🦡','🐾','🦃','🐔','🐓','🐣','🐤','🐥','🐦','🐧','🕊️','🦅','🦆','🦢','🦉','🦤','🪶','🦩','🦚','🦜','🐸','🐊','🐢','🦎','🐍','🐲','🐉','🦕','🦖','🐳','🐋','🐬','🦭','🐟','🐠','🐡','🦈','🐙','🐚','🐌','🦋','🐛','🐜','🐝','🪲','🐞','🦗','🪳','🕷️','🕸️','🦂','🦟','🪰','🪱','🦠','💐','🌸','💮','🏵️','🌹','🥀','🌺','🌻','🌼','🌷','🌱','🪴','🌲','🌳','🌴','🌵','🌾','🌿','☘️','🍀','🍁','🍂','🍃','🍇','🍈','🍉','🍊','🍋','🍌','🍍','🥭','🍎','🍏','🍐','🍑','🍒','🍓','🫐','🥝','🍅','🫒','🥥','🥑','🍆','🥔','🥕','🌽','🌶️','🫑','🥒','🥬','🥦','🧄','🧅','🍄','🥜','🌰','🍞','🥐','🥖','🫓','🥨','🥯','🥞','🧇','🧀','🍖','🍗','🥩','🥓','🍔','🍟','🍕','🌭','🥪','🌮','🌯','🫔','🥙','🧆','🥚','🍳','🥘','🍲','🫕','🥣','🥗','🍿','🧈','🧂','🥫','🍱','🍘','🍙','🍚','🍛','🍜','🍝','🍠','🍢','🍣','🍤','🍥','🥮','🍡','🥟','🥠','🥡','🦀','🦞','🦐','🦑','🦪','🍦','🍧','🍨','🍩','🍪','🎂','🍰','🧁','🥧','🍫','🍬','🍭','🍮','🍯','🍼','🥛','☕','🫖','🍵','🍶','🍾','🍷','🍸','🍹','🍺','🍻','🥂','🥃','🥤','🧋','🧃','🧉','🧊','🥢','🍽️','🍴','🥄','🔪','🏺'];

    // Initialize emoji picker
    function initEmojiPicker() {
        const emojiGrid = document.getElementById('emoji-grid');
        emojis.forEach(emoji => {
            const emojiItem = document.createElement('div');
            emojiItem.className = 'emoji-item';
            emojiItem.textContent = emoji;
            emojiItem.onclick = () => insertEmoji(emoji);
            emojiGrid.appendChild(emojiItem);
        });
    }

    // Toggle emoji picker
    function toggleEmojiPicker() {
        const picker = document.getElementById('emoji-picker');
        picker.classList.toggle('active');
    }

    // Insert emoji
    function insertEmoji(emoji) {
        const cursorPos = messageInput.selectionStart;
        const textBefore = messageInput.value.substring(0, cursorPos);
        const textAfter = messageInput.value.substring(cursorPos);
        messageInput.value = textBefore + emoji + textAfter;
        messageInput.focus();
        messageInput.selectionStart = messageInput.selectionEnd = cursorPos + emoji.length;
        document.getElementById('emoji-picker').classList.remove('active');
    }

    // Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Format time
    function formatTime(timestamp) {
        const date = new Date(timestamp);
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    // Format date
    function formatDate(timestamp) {
        const date = new Date(timestamp);
        const today = new Date();
        const yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);

        if (date.toDateString() === today.toDateString()) {
            return 'Сегодня';
        } else if (date.toDateString() === yesterday.toDateString()) {
            return 'Вчера';
        } else {
            return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });
        }
    }

    // Scroll to bottom
    function scrollToBottom() {
        if (chatMessagesDiv) {
            chatMessagesDiv.scrollTop = chatMessagesDiv.scrollHeight;
            scrollToBottomBtn.classList.remove('visible');
            document.getElementById('unread-count-badge').style.display = 'none';
        }
    }

    // Check scroll position
    function checkScrollPosition() {
        if (chatMessagesDiv) {
            const isAtBottom = chatMessagesDiv.scrollHeight - chatMessagesDiv.scrollTop <= chatMessagesDiv.clientHeight + 100;
            if (!isAtBottom) {
                scrollToBottomBtn.classList.add('visible');
            } else {
                scrollToBottomBtn.classList.remove('visible');
                document.getElementById('unread-count-badge').style.display = 'none';
            }
        }
    }

    // Send message
    async function sendMessage() {
        const input = document.getElementById('message-input');
        const message = input.value.trim();

        if (!message || !selectedUserId) return;

        const msgData = {
            receiver_id: selectedUserId,
            message: message,
            reply_to: replyToMessageId,
            file: selectedFile
        };

        // Add message to DOM immediately with new structure
        const messageContainer = document.createElement('div');
        messageContainer.className = 'message-container outgoing';
        messageContainer.setAttribute('data-sending', 'true'); // Mark as being sent
        
        // Add avatar
        const avatarLink = document.createElement('a');
        avatarLink.href = `profile.php?id=${currentUserId}`;
        avatarLink.className = 'message-avatar-link';
        avatarLink.title = 'Ваш профиль';
        
        const avatar = document.createElement('img');
        avatar.className = 'message-avatar';
        avatar.src = '<?= $current_avatar ?>';
        avatar.alt = 'Avatar';
        avatar.onerror = "this.src='<?= DEFAULT_AVATAR ?>'";
        
        avatarLink.appendChild(avatar);
        messageContainer.appendChild(avatarLink);
        
        // Create message bubble
        const messageBubble = document.createElement('div');
        messageBubble.className = 'message-bubble outgoing';
        
        const messageContent = document.createElement('div');
        messageContent.className = 'message-content';
        
        if (replyToMessageId) {
            const replyTo = document.createElement('div');
            replyTo.className = 'message-reply-to';
            replyTo.textContent = 'Ответ на сообщение';
            messageContent.appendChild(replyTo);
        }
        
        const messageText = document.createElement('div');
        messageText.className = 'message-text';
        messageText.textContent = message;
        messageContent.appendChild(messageText);
        
        const messageFooter = document.createElement('div');
        messageFooter.className = 'message-footer';
        
        const messageTime = document.createElement('span');
        messageTime.className = 'message-time';
        messageTime.textContent = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        messageFooter.appendChild(messageTime);
        
        const messageStatus = document.createElement('span');
        messageStatus.className = 'message-status sending';
        messageStatus.innerHTML = '<i class="fas fa-clock"></i>'; // Sending indicator
        messageStatus.title = 'Отправка...';
        messageFooter.appendChild(messageStatus);
        
        messageContent.appendChild(messageFooter);
        messageBubble.appendChild(messageContent);
        messageContainer.appendChild(messageBubble);
        chatMessagesDiv.appendChild(messageContainer);
        
        input.value = '';
        cancelReply();
        scrollToBottom();

        try {
            const response = await fetch('api/send_message.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(msgData)
            });

            const data = await response.json();
            if (!response.ok || data.status !== 'success') {
                throw new Error(data.message || 'Ошибка отправки');
            }
            
            // Update message container with server data IMMEDIATELY
            if (data.message_id) {
                messageContainer.setAttribute('data-message-id', data.message_id);
                messageContainer.removeAttribute('data-sending');
                messageContainer.setAttribute('data-sent', 'true');
                
                // Update status icon to "sent"
                const statusIcon = messageContainer.querySelector('.message-status');
                if (statusIcon) {
                    statusIcon.classList.remove('sending');
                    statusIcon.innerHTML = '<i class="fas fa-check-double"></i>';
                    statusIcon.title = 'Отправлено';
                }
            }
            
            // Update conversation list item with new last message
            updateConversationListItem(selectedUserId, message);
            
            // Update lastMessageTimestamp to prevent fetching this message again
            lastMessageTimestamp = Math.floor(Date.now() / 1000);
            playNotificationSound();

        } catch (error) {
            console.error('Send error:', error);
            
            // Update status to show error
            const statusIcon = messageContainer.querySelector('.message-status');
            if (statusIcon) {
                statusIcon.classList.add('error');
                statusIcon.classList.remove('sending');
                statusIcon.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
                statusIcon.title = 'Ошибка отправки';
            }
            
            // Show error notification
            alert('Ошибка отправки сообщения: ' + error.message);
            
            // Remove failed message after 3 seconds
            setTimeout(() => {
                messageContainer.remove();
            }, 3000);
        }
    }

    // Handle file select
    function handleFileSelect(event) {
        const file = event.target.files[0];
        if (!file) return;

        selectedFile = file;
        const fileUploadArea = document.getElementById('file-upload-area');
        const filePreview = document.getElementById('file-preview');
        
        fileUploadArea.classList.add('active');
        
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (e) => {
                filePreview.innerHTML = `
                    <img src="${e.target.result}" alt="Preview">
                    <div>
                        <strong>${file.name}</strong>
                        <p>${(file.size / 1024).toFixed(2)} KB</p>
                    </div>
                `;
            };
            reader.readAsDataURL(file);
        } else {
            filePreview.innerHTML = `
                <i class="fas fa-file"></i>
                <div>
                    <strong>${file.name}</strong>
                    <p>${(file.size / 1024).toFixed(2)} KB</p>
                </div>
            `;
        }
    }

    // Cancel file upload
    function cancelFileUpload() {
        selectedFile = null;
        document.getElementById('file-upload-area').classList.remove('active');
        document.getElementById('file-input').value = '';
    }

    // Reply to message
    function replyToMessage(messageId, messageText) {
        replyToMessageId = messageId;
        const replyPreview = document.getElementById('reply-preview');
        const replyPreviewText = document.getElementById('reply-preview-text');
        
        replyPreview.classList.add('active');
        replyPreviewText.textContent = messageText;
        messageInput.focus();
    }

    // Cancel reply
    function cancelReply() {
        replyToMessageId = null;
        document.getElementById('reply-preview').classList.remove('active');
    }

    // Edit message
    function editMessage(messageId, currentText) {
        editingMessageId = messageId;
        messageInput.value = currentText;
        messageInput.focus();
        
        // Highlight the message being edited
        const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
        if (messageElement) {
            const messageBubble = messageElement.querySelector('.message-bubble');
            if (messageBubble) {
                messageBubble.classList.add('editing');
            }
        }
    }

    // Delete message
    async function deleteMessage(messageId) {
        if (!confirm('Вы уверены, что хотите удалить это сообщение?')) return;

        try {
            const response = await fetch('api/delete_message.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ message_id: messageId })
            });

            const data = await response.json();
            if (data.status === 'success') {
                const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
                if (messageElement) {
                    messageElement.remove();
                }
            } else {
                alert('Ошибка удаления сообщения');
            }
        } catch (error) {
            console.error('Delete error:', error);
            alert('Ошибка удаления сообщения');
        }
    }

    // React to message
    async function reactToMessage(messageId, emoji) {
        try {
            const response = await fetch('api/react_message.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ message_id: messageId, emoji: emoji })
            });

            const data = await response.json();
            if (data.status === 'success') {
                // Update reaction display
                updateMessageReactions(messageId, data.reactions);
            }
        } catch (error) {
            console.error('React error:', error);
        }
    }

    // Update message reactions
    function updateMessageReactions(messageId, reactions) {
        const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
        if (!messageElement) return;

        const messageBubble = messageElement.querySelector('.message-bubble');
        if (!messageBubble) return;

        let reactionsDiv = messageBubble.querySelector('.message-reactions');
        if (!reactionsDiv) {
            reactionsDiv = document.createElement('div');
            reactionsDiv.className = 'message-reactions';
            const messageContent = messageBubble.querySelector('.message-content');
            if (messageContent) {
                messageContent.appendChild(reactionsDiv);
            } else {
                messageBubble.appendChild(reactionsDiv);
            }
        }

        reactionsDiv.innerHTML = '';
        Object.entries(reactions).forEach(([emoji, count]) => {
            const reactionItem = document.createElement('div');
            reactionItem.className = 'reaction-item';
            reactionItem.innerHTML = `${emoji} ${count}`;
            reactionItem.onclick = () => reactToMessage(messageId, emoji);
            reactionsDiv.appendChild(reactionItem);
        });
    }

    // Start voice recording
    async function startVoiceRecording() {
        if (isRecording) {
            stopVoiceRecording();
            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            mediaRecorder = new MediaRecorder(stream);
            audioChunks = [];

            mediaRecorder.ondataavailable = (event) => {
                audioChunks.push(event.data);
            };

            mediaRecorder.onstop = async () => {
                const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                await sendAudioMessage(audioBlob);
                stream.getTracks().forEach(track => track.stop());
            };

            mediaRecorder.start();
            isRecording = true;
            
            // Update UI to show recording
            const recordBtn = event.target.closest('.toolbar-btn');
            recordBtn.classList.add('active');
            recordBtn.innerHTML = '<i class="fas fa-stop"></i>';
            
        } catch (error) {
            console.error('Recording error:', error);
            alert('Не удалось получить доступ к микрофону');
        }
    }

    // Stop voice recording
    function stopVoiceRecording() {
        if (mediaRecorder && isRecording) {
            mediaRecorder.stop();
            isRecording = false;
            
            const recordBtn = document.querySelector('.toolbar-btn.active');
            if (recordBtn) {
                recordBtn.classList.remove('active');
                recordBtn.innerHTML = '<i class="fas fa-microphone"></i>';
            }
        }
    }

    // Send audio message
    async function sendAudioMessage(audioBlob) {
        const formData = new FormData();
        formData.append('audio', audioBlob, 'voice-message.webm');
        formData.append('receiver_id', selectedUserId);

        try {
            const response = await fetch('api/send_audio_message.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();
            if (data.status === 'success') {
                playNotificationSound();
            } else {
                alert('Ошибка отправки голосового сообщения');
            }
        } catch (error) {
            console.error('Audio send error:', error);
            alert('Ошибка отправки голосового сообщения');
        }
    }

    // Toggle search
    function toggleSearch() {
        const searchDiv = document.getElementById('search-messages');
        searchDiv.classList.toggle('active');
        if (searchDiv.classList.contains('active')) {
            document.getElementById('search-input').focus();
        }
    }

    // Search messages
    function searchMessages(query) {
        if (query.length < 2) return;

        const messages = chatMessagesDiv.querySelectorAll('.message-bubble');
        messages.forEach(msg => {
            const text = msg.textContent.toLowerCase();
            if (text.includes(query.toLowerCase())) {
                msg.style.backgroundColor = 'rgba(0, 217, 255, 0.2)';
            } else {
                msg.style.backgroundColor = '';
            }
        });
    }

    // Show pinned messages
    function showPinnedMessages() {
        const pinnedDiv = document.getElementById('pinned-messages');
        pinnedDiv.classList.toggle('active');
        // Load pinned messages via API
    }

    // Open image in lightbox
    function openLightbox(imageSrc) {
        const lightbox = document.getElementById('image-lightbox');
        const lightboxImage = document.getElementById('lightbox-image');
        lightboxImage.src = imageSrc;
        lightbox.classList.add('active');
    }

    // Close lightbox
    function closeLightbox() {
        document.getElementById('image-lightbox').classList.remove('active');
    }

    // Play notification sound
    function playNotificationSound() {
        const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBSuBzvLZiTYIGGS57OihUBELTKXh8bllHAU2jdXzzn0pBSd+zPDckUAKFF+16OqnVRQKRp/g8r5sIQUrgs7y2Yk2CBhkuezooVARC0yl4fG5ZRwFNo3V8859KQUnfsz');
        audio.volume = 0.3;
        audio.play().catch(e => console.log('Sound play failed:', e));
    }

    // Fetch messages
    async function fetchMessages() {
        if (!selectedUserId) return;

        try {
            const response = await fetch(`api/get_messages.php?user_id=${selectedUserId}`);
            const data = await response.json();

            if (data.status === 'success' && data.messages) {
                renderMessages(data.messages);
                if (data.messages.length > 0) {
                    lastMessageTimestamp = Math.max(...data.messages.map(msg => new Date(msg.created_at).getTime() / 1000));
                }
            }
        } catch (error) {
            console.error('Fetch messages error:', error);
        }
    }

    // Render messages
    function renderMessages(messages) {
        if (!chatMessagesDiv) return;

        chatMessagesDiv.innerHTML = '';
        let lastDate = '';
        let lastSenderId = null;

        messages.forEach((msg, index) => {
            const messageDate = formatDate(msg.created_at);
            if (messageDate !== lastDate) {
                const dateSeparator = document.createElement('div');
                dateSeparator.className = 'date-separator';
                dateSeparator.innerHTML = `<span>${messageDate}</span>`;
                chatMessagesDiv.appendChild(dateSeparator);
                lastDate = messageDate;
            }

            const isOutgoing = msg.sender_id == currentUserId;
            const showAvatar = lastSenderId !== msg.sender_id || index === 0;
            lastSenderId = msg.sender_id;

            // Create message container
            const messageContainer = document.createElement('div');
            messageContainer.className = `message-container ${isOutgoing ? 'outgoing' : 'incoming'}`;
            messageContainer.setAttribute('data-message-id', msg.id);
            
            // Add avatar
            if (showAvatar) {
                const avatarLink = document.createElement('a');
                avatarLink.href = `profile.php?id=${isOutgoing ? currentUserId : msg.sender_id}`;
                avatarLink.className = 'message-avatar-link';
                avatarLink.title = isOutgoing ? 'Ваш профиль' : 'Профиль пользователя';
                
                const avatar = document.createElement('img');
                avatar.className = 'message-avatar';
                avatar.src = isOutgoing ? '<?= $current_avatar ?>' : (msg.sender_avatar || '<?= $selected_user_avatar ?>');
                avatar.alt = 'Avatar';
                avatar.onerror = "this.src='<?= DEFAULT_AVATAR ?>'";
                
                avatarLink.appendChild(avatar);
                messageContainer.appendChild(avatarLink);
            } else {
                const avatarSpacer = document.createElement('div');
                avatarSpacer.style.width = '38px';
                avatarSpacer.style.flexShrink = '0';
                messageContainer.appendChild(avatarSpacer);
            }

            // Create message bubble
            const messageBubble = document.createElement('div');
            messageBubble.className = `message-bubble ${isOutgoing ? 'outgoing' : 'incoming'}`;
            
            const messageContent = document.createElement('div');
            messageContent.className = 'message-content';
            
            if (msg.reply_to) {
                const replyTo = document.createElement('div');
                replyTo.className = 'message-reply-to';
                replyTo.textContent = 'Ответ на сообщение';
                messageContent.appendChild(replyTo);
            }
            
            const messageText = document.createElement('div');
            messageText.className = 'message-text';
            messageText.textContent = msg.message;
            messageContent.appendChild(messageText);
            
            const messageFooter = document.createElement('div');
            messageFooter.className = 'message-footer';
            
            const messageTime = document.createElement('span');
            messageTime.className = 'message-time';
            messageTime.textContent = formatTime(msg.created_at);
            messageFooter.appendChild(messageTime);
            
            if (isOutgoing) {
                const messageStatus = document.createElement('span');
                messageStatus.className = `message-status ${msg.is_read ? 'read' : ''}`;
                messageStatus.innerHTML = '<i class="fas fa-check-double"></i>';
                messageFooter.appendChild(messageStatus);
            }
            
            messageContent.appendChild(messageFooter);
            messageBubble.appendChild(messageContent);
            
            // Add actions
            const messageActions = document.createElement('div');
            messageActions.className = 'message-actions';
            
            if (isOutgoing) {
                messageActions.innerHTML = `
                        <button class="message-action-btn" onclick="replyToMessage(${msg.id}, '${escapeHtml(msg.message)}')" title="Ответить">
                            <i class="fas fa-reply"></i>
                        </button>
                        <button class="message-action-btn" onclick="editMessage(${msg.id}, '${escapeHtml(msg.message)}')" title="Редактировать">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="message-action-btn" onclick="deleteMessage(${msg.id})" title="Удалить">
                            <i class="fas fa-trash"></i>
                        </button>
                `;
            } else {
                messageActions.innerHTML = `
                        <button class="message-action-btn" onclick="replyToMessage(${msg.id}, '${escapeHtml(msg.message)}')" title="Ответить">
                            <i class="fas fa-reply"></i>
                        </button>
                        <button class="message-action-btn" onclick="reactToMessage(${msg.id}, '❤️')" title="Реакция">
                            <i class="fas fa-heart"></i>
                        </button>
                `;
            }

            messageBubble.appendChild(messageActions);
            messageContainer.appendChild(messageBubble);
            chatMessagesDiv.appendChild(messageContainer);
        });

        setTimeout(scrollToBottom, 100);
    }

    // Fetch updates
    async function fetchUpdates() {
        try {
            const response = await fetch(`api/get_updates.php?last_update=${lastMessageTimestamp}&receiver_id=${selectedUserId}&_=${Date.now()}`);
            const data = await response.json();

            if (data.messages && chatMessagesDiv && data.messages.length > 0) {
                data.messages.forEach(msg => {
                    if ((msg.sender_id == selectedUserId && msg.receiver_id == currentUserId) ||
                        (msg.sender_id == currentUserId && msg.receiver_id == selectedUserId)) {
                        
                        // Check if message already exists by ID
                        const existingMessage = chatMessagesDiv.querySelector(`[data-message-id="${msg.id}"]`);
                        if (!existingMessage) {
                            const isOutgoing = msg.sender_id == currentUserId;
                            
                            // Skip if this is our own message that was just sent
                            // (it's already in the DOM with data-sent="true")
                            if (isOutgoing) {
                                const sentMessage = chatMessagesDiv.querySelector(`[data-sent="true"]`);
                                if (sentMessage && !sentMessage.hasAttribute('data-message-id')) {
                                    // Our message is still pending server confirmation, skip this update
                                    return;
                                }
                            }
                            
                            // Create message container
                            const messageContainer = document.createElement('div');
                            messageContainer.className = `message-container ${isOutgoing ? 'outgoing' : 'incoming'}`;
                            messageContainer.setAttribute('data-message-id', msg.id);
                            
                            // Add avatar
                            const avatarLink = document.createElement('a');
                            avatarLink.href = `profile.php?id=${isOutgoing ? currentUserId : msg.sender_id}`;
                            avatarLink.className = 'message-avatar-link';
                            avatarLink.title = isOutgoing ? 'Ваш профиль' : 'Профиль пользователя';
                            
                            const avatar = document.createElement('img');
                            avatar.className = 'message-avatar';
                            avatar.src = isOutgoing ? '<?= $current_avatar ?>' : (msg.sender_avatar || '<?= $selected_user_avatar ?>');
                            avatar.alt = 'Avatar';
                            avatar.onerror = "this.src='<?= DEFAULT_AVATAR ?>'";
                            
                            avatarLink.appendChild(avatar);
                            messageContainer.appendChild(avatarLink);
                            
                            // Create message bubble
                            const messageBubble = document.createElement('div');
                            messageBubble.className = `message-bubble ${isOutgoing ? 'outgoing' : 'incoming'}`;
                            
                            const messageContent = document.createElement('div');
                            messageContent.className = 'message-content';
                            
                            const messageText = document.createElement('div');
                            messageText.className = 'message-text';
                            messageText.textContent = msg.message;
                            messageContent.appendChild(messageText);
                            
                            const messageFooter = document.createElement('div');
                            messageFooter.className = 'message-footer';
                            
                            const messageTime = document.createElement('span');
                            messageTime.className = 'message-time';
                            messageTime.textContent = formatTime(msg.created_at);
                            messageFooter.appendChild(messageTime);
                            
                            if (isOutgoing) {
                                const messageStatus = document.createElement('span');
                                messageStatus.className = 'message-status';
                                messageStatus.innerHTML = '<i class="fas fa-check-double"></i>';
                                messageFooter.appendChild(messageStatus);
                            }
                            
                            messageContent.appendChild(messageFooter);
                            messageBubble.appendChild(messageContent);
                            messageContainer.appendChild(messageBubble);
                            chatMessagesDiv.appendChild(messageContainer);
                            
                            if (msg.sender_id != currentUserId) {
                                playNotificationSound();
                                checkScrollPosition();
                            }
                        }
                    }
                });

                if (data.messages.length > 0) {
                    lastMessageTimestamp = Math.max(...data.messages.map(msg => new Date(msg.created_at).getTime() / 1000));
                }
            }

            if (document.getElementById('online-count') && data.online_count !== undefined) {
                document.getElementById('online-count').textContent = data.online_count;
            }

            // Update typing indicator
            if (data.is_typing) {
                typingIndicator.classList.add('active');
            } else {
                typingIndicator.classList.remove('active');
            }

        } catch (error) {
            console.error('Fetch updates error:', error);
        }
    }

    // Update conversation list item with new last message
    function updateConversationListItem(userId, messageText) {
        if (!userId) return;
        
        // Find the conversation item for this user
        const conversationItems = document.querySelectorAll('.conversation-item');
        conversationItems.forEach(item => {
            // Get user ID from onclick attribute or data attribute
            let linkUserId = null;
            
            // Check onclick attribute
            const onclickAttr = item.getAttribute('onclick');
            if (onclickAttr) {
                const match = onclickAttr.match(/user=(\d+)/);
                if (match) {
                    linkUserId = match[1];
                }
            }
            
            // Check data attribute if exists
            if (!linkUserId && item.dataset.userId) {
                linkUserId = item.dataset.userId;
            }
            
            // If still no user ID, try to get from window.location or skip
            if (!linkUserId) {
                return;
            }
            
            if (linkUserId == userId) {
                const previewText = item.querySelector('.preview-text');
                if (previewText) {
                    // Truncate message to 55 characters and escape HTML
                    const escaped = messageText.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    const truncated = escaped.length > 55 ? escaped.substring(0, 55) + '...' : escaped;
                    previewText.textContent = truncated;
                }
                
                // Update time
                const timeSpan = item.querySelector('.conversation-time');
                if (timeSpan) {
                    const now = new Date();
                    const hours = String(now.getHours()).padStart(2, '0');
                    const minutes = String(now.getMinutes()).padStart(2, '0');
                    timeSpan.textContent = `${hours}:${minutes}`;
                }
            }
        });
    }

    // Set typing status
    function setTypingStatus(typing) {
        if (selectedUserId === 0) return;
        fetch('api/set_typing.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ typing: typing, receiver_id: selectedUserId })
        }).catch(error => console.error('Error setting typing status:', error));
    }

    // Handle Enter key
    if (messageInput) {
        messageInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        messageInput.addEventListener('input', () => {
            if (selectedUserId === 0) return;

            if (!isTyping) {
                isTyping = true;
                setTypingStatus(true);
            }
            clearTimeout(typingTimeout);
            typingTimeout = setTimeout(() => {
                isTyping = false;
                setTypingStatus(false);
            }, 3000);
        });
    }

    // Scroll event listener
    if (chatMessagesDiv) {
        chatMessagesDiv.addEventListener('scroll', checkScrollPosition);
    }

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        initEmojiPicker();
        if (selectedUserId > 0) {
            fetchMessages();
        }
        scrollToBottom();
    });

    // Update intervals
    setInterval(fetchUpdates, 2000);

    // Sidebar toggle
    function toggleSidebar() {
        document.querySelector('.sidebar').classList.toggle('active');
    }

    // Quick Menu Toggle
    function toggleQuickMenu() {
        const quickMenu = document.getElementById('quick-menu');
        const overlay = document.getElementById('quick-menu-overlay');
        
        quickMenu.classList.toggle('active');
        overlay.classList.toggle('active');
        
        // Prevent body scroll when menu is open
        if (quickMenu.classList.contains('active')) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    }

    // Close Quick Menu
    function closeQuickMenu() {
        const quickMenu = document.getElementById('quick-menu');
        const overlay = document.getElementById('quick-menu-overlay');
        
        quickMenu.classList.remove('active');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Close menu on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeQuickMenu();
        }
    });

    // Search users
    const searchInput = document.getElementById('user-search-input');
    const searchResults = document.getElementById('search-results');

    if (searchInput && searchResults) {
        let searchTimeout;
        
        searchInput.addEventListener('input', function() {
            const query = this.value.trim();
            clearTimeout(searchTimeout);
            
            if (query.length < 2) {
                searchResults.style.display = 'none';
                searchResults.innerHTML = '';
                return;
            }

            searchTimeout = setTimeout(() => {
                fetch(`api/search_users.php?query=${encodeURIComponent(query)}`)
                    .then(response => response.text())
                    .then(data => {
                        if (query === searchInput.value.trim()) {
                            searchResults.innerHTML = data;
                            searchResults.style.display = 'block';
                        }
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                        searchResults.innerHTML = `<div class="no-results">Ошибка поиска</div>`;
                        searchResults.style.display = 'block';
                    });
            }, 300);
        });

        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.style.display = 'none';
            }
        });
    }

    // Report modal
    const reportModal = document.getElementById('reportModal');
    const modalReportedUserId = document.getElementById('modal-reported-user-id');
    const modalReportedUsername = document.getElementById('modal-reported-username');

    function showReportModal(userId, username) {
        modalReportedUserId.value = userId;
        modalReportedUsername.value = username;
        reportModal.style.display = 'flex';
    }

    function closeReportModal() {
        reportModal.style.display = 'none';
    }

    window.onclick = function(event) {
        if (event.target == reportModal) {
            closeReportModal();
        }
    }

    // Close emoji picker when clicking outside
    document.addEventListener('click', (e) => {
        const emojiPicker = document.getElementById('emoji-picker');
        const emojiBtn = document.querySelector('.toolbar-btn');
        if (!emojiPicker.contains(e.target) && !e.target.closest('.toolbar-btn')) {
            emojiPicker.classList.remove('active');
        }
    });
    </script>
</body>
</html>