<?php
require_once __DIR__ . '/config.php';
checkModerPrivileges();

$db = getDB();
$current_user_id = $_SESSION['user_id'];

// Фильтры
$status_filter = $_GET['status'] ?? 'pending';
$search_query = $_GET['search'] ?? '';

$where_clause = "WHERE 1=1";
if ($status_filter !== 'all') {
    $where_clause .= " AND r.status = '" . $db->real_escape_string($status_filter) . "'";
}
if (!empty($search_query)) {
    $search_query_esc = $db->real_escape_string($search_query);
    $where_clause .= " AND (u1.username LIKE '%$search_query_esc%' OR u2.username LIKE '%$search_query_esc%' OR r.reason LIKE '%$search_query_esc%')";
}

// Пагинация
$per_page = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

$reports = $db->query("
    SELECT r.*, u1.username as reporter_name, u2.username as reported_name, u3.username as moderator_name
    FROM reports r
    JOIN users u1 ON r.reporter_id = u1.id
    JOIN users u2 ON r.reported_user_id = u2.id
    LEFT JOIN users u3 ON r.moderator_id = u3.id
    $where_clause
    ORDER BY r.created_at DESC
    LIMIT $per_page OFFSET $offset
")->fetch_all(MYSQLI_ASSOC);

$total_reports = $db->query("SELECT COUNT(*) as count FROM reports r JOIN users u1 ON r.reporter_id = u1.id JOIN users u2 ON r.reported_user_id = u2.id $where_clause")->fetch_assoc()['count'];
$total_pages = ceil($total_reports / $per_page);

// Обработка действий модератора (дублируется из moder.php для автономности)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_report'])) {
    $report_id = (int)$_POST['report_id'];
    $action = $_POST['action'];
    $comment = trim($_POST['comment'] ?? '');
    
    $allowed_actions = ['warn', 'freeze', 'ban', 'reject', 'delete_message'];
    if (!in_array($action, $allowed_actions)) {
        $_SESSION['error'] = "Недопустимое действие";
    } else {
        if (processReport($report_id, $_SESSION['user_id'], $action, $comment)) {
            $_SESSION['success'] = "Действие успешно применено";
            if ($action === 'ban') {
                $db->query("INSERT INTO admin_notifications 
                           (user_id, message, type) 
                           VALUES ({$_SESSION['user_id']}, 'Предложен бан пользователя по жалобе #$report_id', 'ban_proposal')");
            }
        } else {
            $_SESSION['error'] = "Ошибка при обработке жалобы";
        }
    }
    header("Location: moder_reports.php?status=$status_filter&search=" . urlencode($search_query) . "&page=$page");
    exit;
}

// Обработка удаления сообщения
if (isset($_GET['delete_msg'])) {
    $message_id = (int)$_GET['delete_msg'];
    $db->query("DELETE FROM messages WHERE id = $message_id");
    $_SESSION['success'] = "Сообщение успешно удалено";
    header("Location: moder_reports.php?status=$status_filter&search=" . urlencode($search_query) . "&page=$page");
    exit;
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
    <title>Жалобы | <?= SITE_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="moderation-page-body">
    <div class="admin-panel-container glass-effect">
        <div class="moder-sidebar glass-effect">
            <div class="moder-sidebar-header">
                <img src="<?= htmlspecialchars($_SESSION['avatar'] ?? DEFAULT_AVATAR) ?>" 
                     class="user-avatar" onerror="this.src='<?= DEFAULT_AVATAR ?>'">
                <h2><?= htmlspecialchars($_SESSION['username']) ?></h2>
                <p class="neon-text">Модератор</p>
            </div>
            
            <ul class="moder-nav">
                <li class="moder-nav-item"><a href="moder.php?tab=overview" class="moder-nav-link"><i class="fas fa-tachometer-alt"></i> Обзор</a></li>
                <li class="moder-nav-item"><a href="moder_reports.php" class="moder-nav-link active"><i class="fas fa-flag"></i> Жалобы</a></li>
                <li class="moder-nav-item"><a href="chat.php" class="moder-nav-link"><i class="fas fa-comments"></i> Чат</a></li>
                <li class="moder-nav-item"><a href="logout.php" class="btn btn-danger"><i class="fas fa-sign-out-alt"></i> Выход</a></li>
            </ul>
        </div>
        
        <div class="moder-content">
            <h1 class="section-title">
                <i class="fas fa-flag"></i>
                <span>Управление жалобами</span>
            </h1>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <div class="moder-section">
                <form method="GET" class="filters-bar" style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
                    <input type="hidden" name="tab" value="reports">
                    <select name="status" class="form-control" onchange="this.form.submit()" style="flex: 1; min-width: 150px;">
                        <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Ожидают</option>
                        <option value="approved" <?= $status_filter == 'approved' ? 'selected' : '' ?>>Одобрены</option>
                        <option value="rejected" <?= $status_filter == 'rejected' ? 'selected' : '' ?>>Отклонены</option>
                        <option value="forwarded" <?= $status_filter == 'forwarded' ? 'selected' : '' ?>>Переданы админу</option>
                        <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>Все</option>
                    </select>
                    <input type="text" name="search" placeholder="Поиск по пользователю или причине..." value="<?= htmlspecialchars($search_query) ?>" class="form-control" style="flex: 1; min-width: 150px;">
                    <button type="submit" class="btn btn-secondary"><i class="fas fa-filter"></i> Применить</button>
                    <?php if (!empty($search_query) || $status_filter !== 'pending'): ?>
                        <a href="moder_reports.php" class="btn btn-danger"><i class="fas fa-times"></i> Сбросить</a>
                    <?php endif; ?>
                </form>
            
                <div class="reports-list">
                    <?php if (!empty($reports)): ?>
                        <?php foreach ($reports as $report): ?>
                            <div class="report-card glass-effect">
                                <p><strong>От:</strong> <?= htmlspecialchars($report['reporter_name']) ?></p>
                                <p><strong>На пользователя:</strong> <?= htmlspecialchars($report['reported_name']) ?></p>
                                <p><strong>Причина:</strong> <?= htmlspecialchars($report['reason']) ?></p>
                                
                                <?php if ($report['message_id']): ?>
                                    <p><strong>Сообщение:</strong> 
                                        <?php 
                                            $message_content = $db->query("SELECT message FROM messages WHERE id = {$report['message_id']}")->fetch_assoc();
                                            echo htmlspecialchars($message_content['message'] ?? 'сообщение удалено');
                                        ?>
                                    </p>
                                <?php endif; ?>
                                <div class="report-meta">
                                    Дата: <?= date('d.m.Y H:i', strtotime($report['created_at'])) ?>
                                    <span class="badge <?= match($report['status']) {
                                        'pending' => 'badge-pending',
                                        'approved' => 'badge-approved',
                                        'rejected' => 'badge-rejected',
                                        'forwarded' => 'badge-pending' // Still pending for admin
                                    } ?>">
                                        <?= match($report['status']) {
                                            'pending' => 'Ожидает',
                                            'approved' => 'Одобрена',
                                            'rejected' => 'Отклонена',
                                            'forwarded' => 'Передана админу'
                                        } ?>
                                    </span>
                                    <?php if ($report['moderator_name']): ?>
                                        <span style="font-size: 0.9em; color: var(--text-secondary); margin-left: 10px;">
                                            Модератор: <?= htmlspecialchars($report['moderator_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($report['status'] === 'pending'): ?>
                                    <div class="action-buttons">
                                        <form method="POST" style="display:inline-block;">
                                            <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                            <input type="hidden" name="action" value="warn">
                                            <button type="submit" class="btn btn-secondary btn-sm">
                                                <i class="fas fa-exclamation-circle"></i> Предупредить
                                            </button>
                                        </form>
                                        
                                        <form method="POST" style="display:inline-block;">
                                            <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                            <input type="hidden" name="action" value="freeze">
                                            <button type="submit" class="btn btn-danger btn-sm">
                                                <i class="fas fa-snowflake"></i> Заморозить
                                            </button>
                                        </form>
                                        
                                        <form method="POST" style="display:inline-block;">
                                            <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                            <input type="hidden" name="action" value="ban">
                                            <button type="submit" class="btn btn-danger btn-sm">
                                                <i class="fas fa-ban"></i> Предложить бан
                                            </button>
                                        </form>
                                        
                                        <?php if ($report['message_id']): ?>
                                        <form method="POST" style="display:inline-block;">
                                            <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                            <input type="hidden" name="message_id" value="<?= $report['message_id'] ?>">
                                            <input type="hidden" name="action" value="delete_message">
                                            <button type="submit" class="btn btn-secondary btn-sm">
                                                <i class="fas fa-trash"></i> Удалить сообщение
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline-block;">
                                            <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" class="btn btn-secondary btn-sm">
                                                <i class="fas fa-times"></i> Отклонить
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="info-message">Нет жалоб с выбранным статусом или по вашему запросу.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($total_pages > 1): ?>
                <div class="pagination-container">
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page-1 ?>&status=<?= $status_filter ?>&search=<?= urlencode($search_query) ?>" class="btn btn-secondary">
                                <i class="fas fa-chevron-left"></i> Назад
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?= $i ?>&status=<?= $status_filter ?>&search=<?= urlencode($search_query) ?>" 
                               class="btn btn-secondary <?= $i == $page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?= $page+1 ?>&status=<?= $status_filter ?>&search=<?= urlencode($search_query) ?>" class="btn btn-secondary">
                                Вперед <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
