<?php
require __DIR__ . '/config.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// Fetch user's invites
$invites = [];
try {
    $invites = $db->query("
        SELECT k.*, u.username as creator_name, u2.username as used_by_name
        FROM access_keys k
        LEFT JOIN users u ON k.creator_id = u.id
        LEFT JOIN users u2 ON k.used_by = u2.id
        WHERE (k.assigned_to = $user_id OR k.creator_id = $user_id)
        ORDER BY k.created_at DESC
    ")->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    error_log("Error getting invites: " . $e->getMessage());
    $_SESSION['error'] = "Ошибка при получении списка инвайтов";
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
    <title>Мои инвайты | <?= SITE_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" href="assets/favicon.ico" type="image/x-icon">
    <style>
        /* Invites Page Styles */
        .my-invites-page {
            min-height: 100vh;
            background: linear-gradient(135deg, var(--bg-dark), var(--bg-medium-dark));
            padding: 20px;
            display: flex;
            align-items: flex-start;
            justify-content: center;
        }

        .invites-container {
            max-width: 900px;
            width: 100%;
            background: var(--bg-card);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 40px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4);
        }

        .invites-container h1 {
            color: var(--accent-cyan);
            font-size: 2.5rem;
            margin-bottom: 30px;
            text-align: center;
            text-shadow: 0 0 15px rgba(0, 217, 255, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .invites-container h1 i {
            color: var(--accent-purple);
            font-size: 2.2rem;
        }

        .back-button-container {
            text-align: center;
            margin-bottom: 30px;
        }

        .back-button-container .btn {
            padding: 12px 24px;
            font-size: 1rem;
            border-radius: var(--radius-md);
            transition: all var(--transition-normal);
        }

        .back-button-container .btn:hover {
            transform: translateX(-5px);
            box-shadow: 0 4px 15px rgba(0, 217, 255, 0.3);
        }

        /* Invites List */
        .invites-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-top: 30px;
        }

        .invite-card {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 25px;
            transition: all var(--transition-normal);
            position: relative;
            overflow: hidden;
        }

        .invite-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(180deg, var(--accent-cyan), var(--accent-purple));
            transition: width var(--transition-normal);
        }

        .invite-card:hover {
            background: var(--bg-medium);
            border-color: var(--accent-cyan);
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 217, 255, 0.25);
        }

        .invite-card:hover::before {
            width: 100%;
            opacity: 0.1;
        }

        /* Invite Code Display */
        .invite-code-display {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background: var(--bg-dark);
            border: 2px solid var(--accent-cyan);
            border-radius: var(--radius-md);
            position: relative;
            overflow: hidden;
        }

        .invite-code-display::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(0, 217, 255, 0.1), transparent);
            animation: shimmer 3s infinite;
        }

        @keyframes shimmer {
            0% { left: -100%; }
            100% { left: 100%; }
        }

        .invite-code-display span {
            font-family: 'Share Tech Mono', monospace;
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--accent-cyan);
            letter-spacing: 2px;
            text-shadow: 0 0 10px rgba(0, 217, 255, 0.5);
            flex: 1;
            word-break: break-all;
        }

        .copy-btn {
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple));
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: var(--radius-md);
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all var(--transition-fast);
            box-shadow: 0 4px 12px rgba(0, 217, 255, 0.3);
            white-space: nowrap;
        }

        .copy-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(0, 217, 255, 0.5);
        }

        .copy-btn:active {
            transform: scale(0.98);
        }

        .copy-btn i {
            font-size: 1rem;
        }

        /* Invite Meta */
        .invite-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .invite-meta span {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-primary);
            font-size: 0.95rem;
            padding: 10px;
            background: var(--bg-dark);
            border-radius: var(--radius-sm);
            transition: all var(--transition-fast);
        }

        .invite-meta span:hover {
            background: var(--bg-medium);
            transform: translateX(5px);
        }

        .invite-meta i {
            color: var(--accent-cyan);
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
        }

        .invite-meta .badge {
            padding: 4px 12px;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-active {
            background: linear-gradient(135deg, var(--accent-green), #2ecc71);
            color: white;
            box-shadow: 0 2px 8px rgba(46, 204, 113, 0.3);
        }

        .badge-banned {
            background: linear-gradient(135deg, var(--accent-pink), var(--accent-purple));
            color: white;
            box-shadow: 0 2px 8px rgba(236, 72, 153, 0.3);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 5rem;
            color: var(--text-secondary);
            opacity: 0.3;
            margin-bottom: 20px;
            display: block;
        }

        .empty-state p {
            font-size: 1.2rem;
            margin: 0;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .my-invites-page {
                padding: 10px;
            }

            .invites-container {
                padding: 25px 20px;
            }

            .invites-container h1 {
                font-size: 1.8rem;
                flex-direction: column;
                gap: 10px;
            }

            .invite-code-display {
                flex-direction: column;
                align-items: stretch;
            }

            .invite-code-display span {
                font-size: 1.1rem;
                text-align: center;
            }

            .copy-btn {
                width: 100%;
                justify-content: center;
            }

            .invite-meta {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="my-invites-page">
    <div class="cyber-grid"></div>
    <div class="animated-lines"></div>
    <div class="particles"></div>
    
    <div class="invites-container glass-effect">
        <h1><i class="fas fa-ticket-alt"></i> Мои инвайт-коды</h1>
        <div class="back-button-container">
            <a href="profile.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Назад в профиль
            </a>
        </div>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']) ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <?php if (!empty($invites)): ?>
            <div class="invites-list">
                <?php foreach ($invites as $invite): ?>
                    <div class="invite-card">
                        <div class="invite-code-display">
                            <span><?= htmlspecialchars($invite['key_value']) ?></span>
                            <button class="copy-btn" onclick="copyToClipboard('<?= htmlspecialchars($invite['key_value']) ?>', this)">
                                <i class="fas fa-copy"></i> <span class="copy-text">Копировать</span>
                            </button>
                        </div>
                        <div class="invite-meta">
                            <span>
                                <i class="fas fa-user-tie"></i> 
                                <strong>Создан:</strong> 
                                <?php if ($invite['creator_id'] == $user_id): ?>
                                    <span style="color: var(--accent-cyan);">Вы</span>
                                <?php else: ?>
                                    <?= htmlspecialchars($invite['creator_name'] ?? 'Система') ?>
                                <?php endif; ?>
                            </span>
                            <span>
                                <i class="fas fa-calendar-alt"></i> 
                                <strong>Дата создания:</strong> 
                                <?= date('d.m.Y H:i', strtotime($invite['created_at'])) ?>
                            </span>
                            <span>
                                <i class="fas fa-info-circle"></i> 
                                <strong>Статус:</strong> 
                                <span class="badge <?= $invite['used_at'] ? 'badge-banned' : 'badge-active' ?>">
                                    <?= $invite['used_at'] ? 'Использован' : 'Активен' ?>
                                </span>
                            </span>
                            <?php if ($invite['used_at']): ?>
                                <span>
                                    <i class="fas fa-user-check"></i> 
                                    <strong>Использован:</strong> 
                                    <?= htmlspecialchars($invite['used_by_name'] ?? 'Неизвестно') ?>
                                </span>
                                <span>
                                    <i class="fas fa-clock"></i> 
                                    <strong>Дата использования:</strong> 
                                    <?= date('d.m.Y H:i', strtotime($invite['used_at'])) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-ticket-alt"></i>
                <p>У вас пока нет инвайт-кодов.</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function copyToClipboard(text, button) {
            navigator.clipboard.writeText(text).then(() => {
                const copyText = button.querySelector('.copy-text');
                const originalText = copyText ? copyText.textContent : 'Копировать';
                const icon = button.querySelector('i');
                
                if (icon) {
                    icon.className = 'fas fa-check';
                }
                if (copyText) {
                    copyText.textContent = 'Скопировано!';
                } else {
                    button.innerHTML = '<i class="fas fa-check"></i> Скопировано!';
                }
                
                button.style.background = 'linear-gradient(135deg, var(--accent-green), #2ecc71)';
                
                setTimeout(() => {
                    if (icon) {
                        icon.className = 'fas fa-copy';
                    }
                    if (copyText) {
                        copyText.textContent = originalText;
                    } else {
                        button.innerHTML = '<i class="fas fa-copy"></i> Копировать';
                    }
                    button.style.background = 'linear-gradient(135deg, var(--accent-cyan), var(--accent-purple))';
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy: ', err);
                const copyText = button.querySelector('.copy-text');
                if (copyText) {
                    copyText.textContent = 'Ошибка!';
                }
                setTimeout(() => {
                    const copyText = button.querySelector('.copy-text');
                    if (copyText) {
                        copyText.textContent = 'Копировать';
                    }
                }, 2000);
            });
        }
    </script>
</body>
</html>
