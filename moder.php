<?php
// Запуск сессии должен быть самым первым действием
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config.php'; // Исправлен путь к config.php
checkModerPrivileges(); // Проверка прав модератора

$db = getDB();
$current_user_id = $_SESSION['user_id'];
$current_username = htmlspecialchars($_SESSION['username']);
$current_avatar = htmlspecialchars($_SESSION['avatar'] ?? DEFAULT_AVATAR);

// Handle focus_report from notification click
if (isset($_GET['focus_report'])) {
    $report_id_to_focus = (int)$_GET['focus_report'];
    markNotificationsAsRead($current_user_id, $report_id_to_focus); // Помечаем конкретное уведомление как прочитанное
    // JavaScript для прокрутки и подсветки
    echo '<script>document.addEventListener("DOMContentLoaded", function() {
        const element = document.querySelector(\'.report-card[data-report-id="'.$report_id_to_focus.'"]\');
        if (element) {
            element.scrollIntoView({behavior: "smooth", block: "center"});
            element.style.animation = "highlight 2s ease-out";
        }
    });</script>';
    // CSS для подсветки
    echo '<style>
        @keyframes highlight {
            0% { background: rgba(255,255,0,0.3); border-color: yellow; }
            100% { background: rgba(255,255,0,0); border-color: var(--accent-pink); }
        }
    </style>';
} else {
    // Если нет конкретного фокуса, помечаем все уведомления как прочитанные при загрузке страницы модерации
    markNotificationsAsRead($current_user_id);
}

// --- Handle POST requests for moderation actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_report'])) {
    $report_id = (int)$_POST['report_id'];
    $action = $_POST['action'];
    $comment = trim($_POST['comment'] ?? '');
    $message_id = (int)($_POST['message_id'] ?? 0); // Получаем message_id, если он передан

    $allowed_actions = ['warn', 'freeze', 'ban', 'reject', 'delete_message'];
    if (!in_array($action, $allowed_actions)) {
        $_SESSION['error'] = "Недопустимое действие.";
    } else {
        // Используем централизованную функцию processReport из config.php
        if (processReport($report_id, $current_user_id, $action, $comment)) {
            $_SESSION['success'] = "Действие успешно применено.";
            // Дополнительное логирование уже внутри processReport
            // Для предложения бана, уведомление админу создается внутри processReport
        } else {
            $_SESSION['error'] = "Ошибка при обработке жалобы.";
        }
    }
    // Redirect to current tab to clear POST data
    header("Location: moder.php" . (isset($_GET['tab']) ? "?tab=" . $_GET['tab'] : "") . 
           (isset($_GET['status']) ? "&status=" . $_GET['status'] : "") . 
           (isset($_GET['search_reports']) ? "&search_reports=" . urlencode($_GET['search_reports']) : "") .
           (isset($_GET['page_reports']) ? "&page_reports=" . $_GET['page_reports'] : "")
    );
    exit;
}

// --- Data Fetching ---
$notifications = getUnreadModeratorNotifications($current_user_id);
$unread_count = count($notifications);

// Reports (with filters and pagination)
$status_filter = $_GET['status'] ?? 'pending';
$search_reports = isset($_GET['search_reports']) ? sanitize($_GET['search_reports']) : '';

$report_where_clause = "WHERE 1=1";
if ($status_filter !== 'all') {
    $report_where_clause .= " AND r.status = '" . $db->real_escape_string($status_filter) . "'";
}
if (!empty($search_reports)) {
    $search_reports_esc = $db->real_escape_string($search_reports);
    $report_where_clause .= " AND (u1.username LIKE '%$search_reports_esc%' OR u2.username LIKE '%$search_reports_esc%' OR r.reason LIKE '%$search_reports_esc%' OR r.action_taken LIKE '%$search_reports_esc%')";
}

$per_page_reports = isset($_GET['per_page_reports']) ? max(1, (int)$_GET['per_page_reports']) : 10;
$page_reports = isset($_GET['page_reports']) ? max(1, (int)$_GET['page_reports']) : 1;
$offset_reports = ($page_reports - 1) * $per_page_reports;

$reports = $db->query("
    SELECT r.*, u1.username as reporter_name, u2.username as reported_name, u3.username as moderator_name
    FROM reports r
    JOIN users u1 ON r.reporter_id = u1.id
    JOIN users u2 ON r.reported_user_id = u2.id
    LEFT JOIN users u3 ON r.moderator_id = u3.id
    $report_where_clause
    ORDER BY r.created_at DESC
    LIMIT $per_page_reports OFFSET $offset_reports
")->fetch_all(MYSQLI_ASSOC);

$total_reports_query = $db->query("SELECT COUNT(*) as count FROM reports r JOIN users u1 ON r.reporter_id = u1.id JOIN users u2 ON r.reported_user_id = u2.id $report_where_clause");
$total_reports = $total_reports_query ? $total_reports_query->fetch_assoc()['count'] : 0;
$total_pages_reports = ceil($total_reports / $per_page_reports);

// Top Reported Users
$top_reported = getMostReportedUsers(5); // Limit to 5 for dashboard view

// Suspicious Messages
// Check if messages table has created_at column, fallback to id ordering
$column_check = $db->query("SHOW COLUMNS FROM messages LIKE 'created_at'");
if ($column_check && $column_check->num_rows > 0) {
    $suspicious_messages = $db->query("
        SELECT m.id, m.sender_id, m.message, m.created_at, u.username 
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.message REGEXP 'нарко|drug|купи|прода|дети|оружие|weapon|порно|porn|sex|kill|убить|бомба|bomb'
        ORDER BY m.created_at DESC
        LIMIT 20
    ")->fetch_all(MYSQLI_ASSOC);
} else {
    $suspicious_messages = $db->query("
        SELECT m.id, m.sender_id, m.message, NOW() as created_at, u.username 
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.message REGEXP 'нарко|drug|купи|прода|дети|оружие|weapon|порно|porn|sex|kill|убить|бомба|bomb'
        ORDER BY m.id DESC
        LIMIT 20
    ")->fetch_all(MYSQLI_ASSOC);
}


// Moderation Stats
$stats = $db->query("
    SELECT 
        COUNT(*) as total_reports,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_reports,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_reports,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_reports,
        SUM(CASE WHEN status = 'forwarded' THEN 1 ELSE 0 END) as forwarded_reports
    FROM reports
")->fetch_assoc();

// Check if moderation_actions table exists, if not use action_logs
$table_check = $db->query("SHOW TABLES LIKE 'moderation_actions'");
if ($table_check && $table_check->num_rows > 0) {
    $actions_stats = $db->query("
        SELECT action_type, COUNT(*) as count 
        FROM moderation_actions 
        GROUP BY action_type
    ")->fetch_all(MYSQLI_ASSOC);
} else {
    // Fallback to action_logs table
    $actions_stats = $db->query("
        SELECT action_type, COUNT(*) as count 
        FROM action_logs 
        WHERE action_type IN ('warning', 'freeze', 'ban_proposal', 'message_deleted', 'report_rejected')
        GROUP BY action_type
    ")->fetch_all(MYSQLI_ASSOC);
}

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
    <title>Панель модератора | <?= SITE_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" href="assets/favicon.ico" type="image/x-icon">
    <style>
        /* Moder Page Styles */
        .moder-page {
            min-height: 100vh;
            background: linear-gradient(135deg, var(--bg-dark), var(--bg-medium-dark));
        }

        .moder-main-layout-container {
            display: flex;
            min-height: 100vh;
            gap: 20px;
            padding: 20px;
            max-width: 1600px;
            margin: 0 auto;
        }

        /* Moder Sidebar */
        .moder-sidebar {
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

        .moder-sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .moder-sidebar::-webkit-scrollbar-thumb {
            background: var(--accent-cyan);
            border-radius: 3px;
        }

        .moder-sidebar-header {
            text-align: center;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 20px;
        }

        .moder-sidebar-header .user-avatar {
            width: 100px;
            height: 100px;
            margin: 0 auto 15px;
            border: 3px solid var(--accent-purple);
            box-shadow: 0 0 20px rgba(200, 0, 255, 0.4);
            transition: all var(--transition-normal);
        }

        .moder-sidebar-header .user-avatar:hover {
            transform: scale(1.05);
            box-shadow: 0 0 30px rgba(200, 0, 255, 0.6);
        }

        .moder-sidebar-header h2 {
            color: var(--accent-purple);
            font-size: 1.5rem;
            margin-bottom: 8px;
            text-shadow: 0 0 10px rgba(200, 0, 255, 0.5);
            text-align: center;
            justify-content: center;
            width: 100%;
        }

        .moder-sidebar-header .neon-text {
            color: var(--accent-cyan);
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .notification-badge-sidebar {
            display: inline-block;
            background: linear-gradient(135deg, var(--accent-pink), var(--accent-purple));
            color: white;
            padding: 8px 16px;
            border-radius: var(--radius-md);
            font-weight: 600;
            font-size: 0.9rem;
            box-shadow: 0 4px 12px rgba(236, 72, 153, 0.4);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        /* Moder Navigation */
        .moder-nav {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .moder-nav-item {
            margin: 0;
        }

        .moder-nav-item a {
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

        .moder-nav-item a:hover {
            background: var(--bg-medium);
            border-color: var(--accent-purple);
            color: var(--accent-purple);
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(200, 0, 255, 0.2);
        }

        .moder-nav-item a.active {
            background: linear-gradient(135deg, var(--accent-purple), var(--accent-cyan));
            color: white;
            border-color: var(--accent-purple);
            box-shadow: 0 4px 15px rgba(200, 0, 255, 0.4);
        }

        .moder-nav-item a i {
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
        }

        .moder-nav-item a.btn-danger {
            background: linear-gradient(135deg, var(--accent-pink), var(--accent-purple));
            color: white;
        }

        .moder-nav-item a.btn-danger:hover {
            background: linear-gradient(135deg, var(--accent-purple), var(--accent-pink));
            transform: translateX(5px);
        }

        /* Moder Content */
        .moder-content {
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

        .moder-tab-content {
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
            background: linear-gradient(180deg, var(--accent-purple), var(--accent-cyan));
            transition: width var(--transition-normal);
        }

        .stat-card:hover {
            background: var(--bg-medium);
            border-color: var(--accent-purple);
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(200, 0, 255, 0.25);
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
            color: var(--accent-purple);
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0;
            text-shadow: 0 0 10px rgba(200, 0, 255, 0.5);
        }

        /* Notifications */
        .notifications-container {
            margin-top: 30px;
        }

        .notification-card {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--accent-pink);
            padding: 20px;
            margin-bottom: 15px;
            border-radius: var(--radius-md);
            transition: all var(--transition-normal);
        }

        .notification-card:hover {
            background: var(--bg-medium);
            border-color: var(--accent-pink);
            transform: translateX(5px);
            box-shadow: 0 4px 15px rgba(236, 72, 153, 0.2);
        }

        .notification-card p {
            margin: 0 0 15px 0;
            color: var(--text-primary);
            line-height: 1.6;
        }

        /* Reports List */
        .reports-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .reports-list .card {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 25px;
            transition: all var(--transition-normal);
            position: relative;
            overflow: hidden;
        }

        .reports-list .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--accent-cyan);
            transition: width var(--transition-normal);
        }

        .reports-list .card:hover {
            background: var(--bg-medium);
            border-color: var(--accent-cyan);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0, 217, 255, 0.25);
        }

        .reports-list .card:hover::before {
            width: 100%;
            opacity: 0.1;
        }

        .reports-list .card p {
            margin: 8px 0;
            color: var(--text-primary);
            line-height: 1.6;
        }

        .reports-list .card strong {
            color: var(--accent-cyan);
        }

        .report-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
            flex-wrap: wrap;
        }

        .report-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .report-actions form {
            margin: 0;
        }

        /* Report Card Structure */
        .report-card {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .report-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .report-users {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
        }

        .report-user-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: var(--bg-dark);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
        }

        .report-user-item i {
            font-size: 1rem;
        }

        .report-body {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .report-reason {
            padding: 15px;
            background: var(--bg-dark);
            border-left: 4px solid var(--accent-yellow);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            line-height: 1.6;
        }

        .report-message {
            padding: 15px;
            background: var(--bg-dark);
            border-left: 4px solid var(--accent-cyan);
            border-radius: var(--radius-sm);
        }

        .message-content-box {
            margin-top: 10px;
            padding: 12px;
            background: var(--bg-medium);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-style: italic;
            border: 1px solid var(--border-color);
        }

        .report-meta {
            display: flex;
            align-items: center;
            gap: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
            flex-wrap: wrap;
        }

        .report-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .report-meta-item i {
            font-size: 0.9rem;
        }

        /* Top Reported Users */
        .top-reported-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 20px;
        }

        .top-reported-user {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            transition: all var(--transition-fast);
        }

        .top-reported-user:hover {
            background: var(--bg-medium);
            border-color: var(--accent-pink);
            transform: translateX(5px);
        }

        .top-reported-user span:first-child {
            font-weight: 600;
            color: var(--text-primary);
        }

        /* Filters Bar */
        .filters-bar {
            background: var(--bg-light);
            padding: 20px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            margin-bottom: 25px;
        }

        /* Message Meta */
        .message-meta {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .message-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Badges */
        .badge-pending {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white;
            padding: 4px 12px;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(243, 156, 18, 0.3);
        }

        .badge-approved {
            background: linear-gradient(135deg, var(--accent-green), #2ecc71);
            color: white;
            padding: 4px 12px;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(46, 204, 113, 0.3);
        }

        .badge-rejected {
            background: linear-gradient(135deg, var(--accent-pink), var(--accent-purple));
            color: white;
            padding: 4px 12px;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(236, 72, 153, 0.3);
        }

        /* Mobile Sidebar Toggle */
        .mobile-sidebar-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: var(--accent-purple);
            border: none;
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            font-size: 1.3rem;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(200, 0, 255, 0.4);
            transition: all var(--transition-fast);
            position: relative;
        }

        .mobile-sidebar-toggle:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(200, 0, 255, 0.6);
        }

        .mobile-sidebar-toggle .notification-badge-sidebar {
            position: absolute;
            top: -5px;
            right: -5px;
            width: 20px;
            height: 20px;
            padding: 0;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .moder-main-layout-container {
                flex-direction: column;
                padding: 10px;
            }

            .mobile-sidebar-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .moder-sidebar {
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

            .moder-sidebar.active {
                transform: translateX(0);
            }

            .moder-content {
                padding: 20px;
                min-height: auto;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .report-actions {
                flex-direction: column;
            }

            .report-actions form {
                width: 100%;
            }

            .report-actions button {
                width: 100%;
            }
        }
    </style>
</head>
<body class="moder-page">
    <div class="cyber-grid"></div>
    <div class="animated-lines"></div>
    <div class="particles"></div>
    <button class="mobile-sidebar-toggle" onclick="toggleModerSidebar()">
        <i class="fas fa-bars"></i>
        <?php if ($unread_count > 0): ?>
            <span class="notification-badge-sidebar"><?= $unread_count ?></span>
        <?php endif; ?>
    </button>

    <div class="moder-main-layout-container">
    <div class="moder-sidebar glass-effect">
        <div class="moder-sidebar-header">
            <img src="<?= $current_avatar ?>" alt="My Avatar" class="user-avatar" onerror="this.src='<?= DEFAULT_AVATAR ?>'">
            <h2 class="section-title"><?= $current_username ?></h2>
            <p class="neon-text">Модератор</p>
            <?php if ($unread_count > 0): ?>
                <div class="notification-badge-sidebar" style="margin-top: 15px;">
                    <?= $unread_count ?> новых жалоб
                </div>
            <?php endif; ?>
        </div>
        <ul class="moder-nav">
            <li class="moder-nav-item"><a href="?tab=overview" class="<?= $current_tab == 'overview' ? 'active' : '' ?>"><i class="fas fa-tachometer-alt"></i> Обзор</a></li>
            <li class="moder-nav-item"><a href="?tab=reports" class="<?= $current_tab == 'reports' ? 'active' : '' ?>"><i class="fas fa-flag"></i> Жалобы</a></li>
            <li class="moder-nav-item"><a href="?tab=suspicious" class="<?= $current_tab == 'suspicious' ? 'active' : '' ?>"><i class="fas fa-exclamation-triangle"></i> Подозрительные сообщения</a></li>
            <li class="moder-nav-item"><a href="?tab=stats" class="<?= $current_tab == 'stats' ? 'active' : '' ?>"><i class="fas fa-chart-bar"></i> Статистика</a></li>
            <li class="moder-nav-item"><a href="chat.php"><i class="fas fa-arrow-left"></i> Вернуться в чат</a></li>
            <li class="moder-nav-item"><a href="logout.php" class="btn-danger"><i class="fas fa-sign-out-alt"></i> Выход</a></li>
        </ul>
    </div>

    <div class="moder-content">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Overview Tab -->
        <div id="overview-tab" class="moder-tab-content" style="display: <?= $current_tab == 'overview' ? 'block' : 'none' ?>;">
            <h2 class="section-title"><i class="fas fa-tachometer-alt"></i> Обзор модерации</h2>
            <div class="stats-grid">
                <div class="card stat-card">
                    <h4>Жалоб в ожидании</h4>
                    <p><?= $stats['pending_reports'] ?></p>
                </div>
                <div class="card stat-card">
                    <h4>Всего обработано</h4>
                    <p><?= $stats['approved_reports'] + $stats['rejected_reports'] + $stats['forwarded_reports'] ?></p>
                </div>
                <div class="card stat-card">
                    <h4>Предложено банов</h4>
                    <p><?= $stats['forwarded_reports'] ?></p>
                </div>
                <div class="card stat-card">
                    <h4>Подозрительных сообщений</h4>
                    <p><?= count($suspicious_messages) ?></p>
                </div>
            </div>

            <?php if (!empty($notifications)): ?>
                <div class="card notifications-container">
                    <h3 class="section-title"><i class="fas fa-bell"></i> Новые жалобы</h3>
                    <?php foreach ($notifications as $notification): ?>
                        <div class="card notification-card" data-report-id="<?= $notification['report_id'] ?>">
                            <p>
                                <strong>Жалоба #<?= $notification['report_id'] ?></strong><br>
                                От: <?= htmlspecialchars($notification['reporter_name']) ?><br>
                                На: <?= htmlspecialchars($notification['reported_name']) ?><br>
                                Причина: <?= htmlspecialchars($notification['reason']) ?>
                            </p>
                            <a href="?tab=reports&focus_report=<?= $notification['report_id'] ?>" class="btn btn-secondary btn-sm">
                                Перейти к жалобе
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <h3 class="section-title"><i class="fas fa-exclamation-triangle"></i> Топ пользователей по жалобам</h3>
                <?php if (!empty($top_reported)): ?>
                    <div class="top-reported-list">
                        <?php foreach ($top_reported as $user): ?>
                            <div class="top-reported-user">
                                <span><?= htmlspecialchars($user['username']) ?></span>
                                <span class="badge badge-pending"><?= $user['reports_count'] ?> жалоб</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color: var(--text-secondary);">Нет пользователей с активными жалобами.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Reports Tab -->
        <div id="reports-tab" class="moder-tab-content" style="display: <?= $current_tab == 'reports' ? 'block' : 'none' ?>;">
            <h2 class="section-title"><i class="fas fa-flag"></i> Управление жалобами</h2>
            <form method="GET" class="filters-bar" style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
                <input type="hidden" name="tab" value="reports">
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <select id="report_status_filter" name="status" onchange="this.form.submit()" required placeholder=" ">
                        <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Ожидают</option>
                        <option value="approved" <?= $status_filter == 'approved' ? 'selected' : '' ?>>Одобрены</option>
                        <option value="rejected" <?= $status_filter == 'rejected' ? 'selected' : '' ?>>Отклонены</option>
                        <option value="forwarded" <?= $status_filter == 'forwarded' ? 'selected' : '' ?>>Переданы админу</option>
                        <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>Все</option>
                    </select>
                    <i class="fas fa-filter"></i>
                    <label for="report_status_filter">Статус</label>
                </div>
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <input type="text" id="search_reports" name="search_reports" placeholder=" " value="<?= htmlspecialchars($search_reports) ?>" />
                    <i class="fas fa-search"></i>
                    <label for="search_reports">Поиск</label>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Применить</button>
                <?php if (!empty($search_reports) || $status_filter !== 'pending'): ?>
                    <a href="moder.php?tab=reports" class="btn btn-secondary"><i class="fas fa-times"></i> Сбросить</a>
                <?php endif; ?>
            </form>
            
            <div class="reports-list">
                <?php if (!empty($reports)): ?>
                    <?php foreach ($reports as $report): ?>
                        <div class="card report-card" data-report-id="<?= $report['id'] ?>">
                            <div class="report-header">
                                <div class="report-users">
                                    <div class="report-user-item">
                                        <i class="fas fa-user-shield" style="color: var(--accent-green);"></i>
                                        <span><strong>От:</strong> <?= htmlspecialchars($report['reporter_name']) ?></span>
                                    </div>
                                    <i class="fas fa-arrow-right" style="color: var(--text-secondary); margin: 0 10px;"></i>
                                    <div class="report-user-item">
                                        <i class="fas fa-user-times" style="color: var(--accent-pink);"></i>
                                        <span><strong>На:</strong> <?= htmlspecialchars($report['reported_name']) ?></span>
                                    </div>
                                </div>
                                <span class="badge <?= match($report['status']) {
                                    'pending' => 'badge-pending',
                                    'approved' => 'badge-approved',
                                    'rejected' => 'badge-rejected',
                                    'forwarded' => 'badge-pending'
                                } ?>">
                                    <?= match($report['status']) {
                                        'pending' => 'Ожидает',
                                        'approved' => 'Одобрена',
                                        'rejected' => 'Отклонена',
                                        'forwarded' => 'Передана админу'
                                    } ?>
                                </span>
                            </div>
                            
                            <div class="report-body">
                                <div class="report-reason">
                                    <i class="fas fa-exclamation-circle" style="color: var(--accent-yellow); margin-right: 8px;"></i>
                                    <strong>Причина:</strong> <?= htmlspecialchars($report['reason']) ?>
                                </div>
                            
                                <?php if ($report['message_id']): ?>
                                    <div class="report-message">
                                        <i class="fas fa-comment" style="color: var(--accent-cyan); margin-right: 8px;"></i>
                                        <strong>Сообщение:</strong>
                                        <div class="message-content-box">
                                            <?php 
                                                $message_stmt = $db->prepare("SELECT message FROM messages WHERE id = ? LIMIT 1");
                                                $message_stmt->bind_param("i", $report['message_id']);
                                                $message_stmt->execute();
                                                $message = $message_stmt->get_result()->fetch_assoc();
                                                echo htmlspecialchars($message['message'] ?? 'сообщение удалено');
                                            ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="report-meta">
                                <div class="report-meta-item">
                                    <i class="fas fa-calendar-alt" style="color: var(--text-secondary);"></i>
                                    <span><?= date('d.m.Y H:i', strtotime($report['created_at'])) ?></span>
                                </div>
                                <?php if ($report['moderator_name']): ?>
                                    <div class="report-meta-item">
                                        <i class="fas fa-user-shield" style="color: var(--accent-purple);"></i>
                                        <span>Модератор: <?= htmlspecialchars($report['moderator_name']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($report['status'] === 'pending'): ?>
                                <div class="report-actions">
                                    <form method="POST" style="display:inline-block;">
                                        <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                        <input type="hidden" name="action" value="warn">
                                        <button type="submit" name="process_report" class="btn btn-sm btn-secondary">
                                            <i class="fas fa-exclamation-circle"></i> Предупреждение
                                        </button>
                                    </form>
                                    
                                    <form method="POST" style="display:inline-block;">
                                        <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                        <input type="hidden" name="action" value="freeze">
                                        <button type="submit" name="process_report" class="btn btn-sm btn-danger">
                                            <i class="fas fa-snowflake"></i> Заморозить
                                        </button>
                                    </form>
                                    
                                    <form method="POST" style="display:inline-block;">
                                        <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                        <input type="hidden" name="action" value="ban">
                                        <button type="submit" name="process_report" class="btn btn-sm btn-secondary">
                                            <i class="fas fa-ban"></i> Предложить бан
                                        </button>
                                    </form>
                                    
                                    <?php if ($report['message_id']): ?>
                                    <form method="POST" style="display:inline-block;">
                                        <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                        <input type="hidden" name="message_id" value="<?= $report['message_id'] ?>">
                                        <input type="hidden" name="action" value="delete_message">
                                        <button type="submit" name="process_report" class="btn btn-sm btn-danger">
                                            <i class="fas fa-trash"></i> Удалить сообщение
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <form method="POST" style="display:inline-block;">
                                        <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" name="process_report" class="btn btn-sm btn-secondary">
                                            <i class="fas fa-times"></i> Отклонить
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="text-align: center; color: var(--text-secondary);">Нет жалоб с выбранным статусом или по вашему запросу.</p>
                <?php endif; ?>
                <?php if ($total_pages_reports > 1): ?>
                    <div class="pagination">
                        <?php if ($page_reports > 1): ?>
                            <a href="?tab=reports&page_reports=<?= $page_reports-1 ?>&status=<?= $status_filter ?>&search_reports=<?= urlencode($search_reports) ?>">
                                &laquo; Назад
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages_reports; $i++): ?>
                            <a href="?tab=reports&page_reports=<?= $i ?>&status=<?= $status_filter ?>&search_reports=<?= urlencode($search_reports) ?>" 
                               class="<?= $i == $page_reports ? 'current' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page_reports < $total_pages_reports): ?>
                            <a href="?tab=reports&page_reports=<?= $page_reports+1 ?>&status=<?= $status_filter ?>&search_reports=<?= urlencode($search_reports) ?>">
                                Вперед &raquo;
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="per-page-selector">
                    <form method="GET" style="display:inline;">
                        <input type="hidden" name="tab" value="reports">
                        <input type="hidden" name="page_reports" value="<?= $page_reports ?>">
                        <div class="form-group" style="margin-bottom: 0;">
                            <select id="per_page_reports_select" name="per_page_reports" onchange="this.form.submit()" required placeholder=" ">
                                <option value="5" <?= $per_page_reports == 5 ? 'selected' : '' ?>>5</option>
                                <option value="10" <?= $per_page_reports == 10 ? 'selected' : '' ?>>10</option>
                                <option value="20" <?= $per_page_reports == 20 ? 'selected' : '' ?>>20</option>
                                <option value="50" <?= $per_page_reports == 50 ? 'selected' : '' ?>>50</option>
                            </select>
                            <i class="fas fa-list-alt"></i>
                            <label for="per_page_reports_select">Показывать по</label>
                        </div>
                    </form>
                </div>
            </div>
        </div>
            
        <!-- Suspicious Messages Tab -->
        <div id="suspicious-tab" class="moder-tab-content" style="display: <?= $current_tab == 'suspicious' ? 'block' : 'none' ?>;">
            <h2 class="section-title"><i class="fas fa-exclamation-triangle"></i> Подозрительные сообщения</h2>
            <?php if (!empty($suspicious_messages)): ?>
                <?php foreach ($suspicious_messages as $msg): ?>
                    <div class="card">
                        <p><strong><?= htmlspecialchars($msg['username']) ?>:</strong> <?= htmlspecialchars($msg['message']) ?></p>
                        <div class="message-meta">
                            Дата: <?= date('d.m.Y H:i', strtotime($msg['created_at'])) ?>
                            <div class="message-actions" style="margin-top: 10px;">
                                <form method="POST" style="display:inline-block;">
                                    <input type="hidden" name="report_id" value="0"> <!-- Dummy report_id for direct message action -->
                                    <input type="hidden" name="message_id" value="<?= $msg['id'] ?>">
                                    <input type="hidden" name="action" value="delete_message">
                                    <button type="submit" name="process_report" class="btn btn-sm btn-danger" onclick="return confirm('Удалить это сообщение?')">
                                        <i class="fas fa-trash"></i> Удалить
                                    </button>
                                </form>
                                <form method="POST" style="display:inline-block;">
                                    <input type="hidden" name="report_id" value="0"> <!-- Dummy report_id -->
                                    <input type="hidden" name="reported_user_id" value="<?= $msg['sender_id'] ?>">
                                    <input type="hidden" name="action" value="ban">
                                    <button type="submit" name="process_report" class="btn btn-sm btn-secondary" onclick="return confirm('Предложить бан пользователя <?= htmlspecialchars($msg['username']) ?>?')">
                                        <i class="fas fa-ban"></i> Предложить бан
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="text-align: center; color: var(--text-secondary);">Нет подозрительных сообщений.</p>
            <?php endif; ?>
        </div>
            
        <!-- Statistics Tab -->
        <div id="stats-tab" class="moder-tab-content" style="display: <?= $current_tab == 'stats' ? 'block' : 'none' ?>;">
            <h2 class="section-title"><i class="fas fa-chart-bar"></i> Статистика модерации</h2>
            <div class="stats-grid">
                <div class="card stat-card">
                    <h4>Всего жалоб</h4>
                    <p><?= $stats['total_reports'] ?></p>
                </div>
                <div class="card stat-card">
                    <h4>Ожидают рассмотрения</h4>
                    <p><?= $stats['pending_reports'] ?></p>
                </div>
                <div class="card stat-card">
                    <h4>Одобрено</h4>
                    <p><?= $stats['approved_reports'] ?></p>
                </div>
                <div class="card stat-card">
                    <h4>Отклонено</h4>
                    <p><?= $stats['rejected_reports'] ?></p>
                </div>
                <div class="card stat-card">
                    <h4>Передано админу</h4>
                    <p><?= $stats['forwarded_reports'] ?></p>
                </div>
            </div>
            
            <h3 class="section-title"><i class="fas fa-chart-pie"></i> Действия модераторов</h3>
            <div class="stats-grid">
                <?php foreach ($actions_stats as $stat): ?>
                    <div class="card stat-card">
                        <h4><?= match($stat['action_type']) {
                            'warning' => 'Предупреждения',
                            'temp_ban' => 'Временные баны',
                            'perma_ban' => 'Перманентные баны',
                            'freeze' => 'Заморозки',
                            'ban_proposal' => 'Предложения бана',
                            'message_deleted' => 'Удалено сообщений', // Добавлено для удаленных сообщений
                            'report_rejected' => 'Отклонено жалоб', // Добавлено для отклоненных жалоб
                            default => $stat['action_type']
                        } ?></h4>
                        <p style="font-size: 2rem;"><?= $stat['count'] ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <script>
    // Function to switch tabs
    function switchTab(tabId) {
        document.querySelectorAll('.moder-tab-content').forEach(tab => {
            tab.style.display = 'none';
        });
        document.getElementById(tabId + '-tab').style.display = 'block';

        document.querySelectorAll('.moder-nav-item a').forEach(link => {
            link.classList.remove('active');
        });
        document.querySelector(`.moder-nav-item a[href="?tab=${tabId}"]`).classList.add('active');

        // Close sidebar on mobile after tab selection
        if (window.innerWidth <= 768) {
            document.querySelector('.moder-sidebar').classList.remove('active');
        }
    }

    // Mobile sidebar toggle
    function toggleModerSidebar() {
        document.querySelector('.moder-sidebar').classList.toggle('active');
    }

    // Set initial tab on page load
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const initialTab = urlParams.get('tab') || 'overview';
        switchTab(initialTab);
    });
</script>
</body>
</html>
