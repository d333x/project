<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . '/config.php';

// Проверяем права администратора
checkAdminPrivileges();

$db = getDB();
$current_user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Добавление D3X Coin пользователю
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_coins'])) {
    $user_id = (int)$_POST['user_id'];
    $amount = (float)$_POST['amount'];
    $description = sanitize($_POST['description']);
    
    if ($user_id > 0 && $amount > 0) {
        // Проверяем, существует ли пользователь
        $stmt_check = $db->prepare("SELECT username FROM users WHERE id = ? AND banned = 0");
        $stmt_check->bind_param("i", $user_id);
        $stmt_check->execute();
        $user_result = $stmt_check->get_result();
        
        if ($user_result->num_rows > 0) {
            $user_info = $user_result->fetch_assoc();
            
            $db->begin_transaction();
            try {
                // Проверяем, есть ли запись о валюте пользователя
                $stmt_balance = $db->prepare("SELECT amount FROM premium_currency WHERE user_id = ?");
                $stmt_balance->bind_param("i", $user_id);
                $stmt_balance->execute();
                $balance_result = $stmt_balance->get_result();
                
                if ($balance_result->num_rows > 0) {
                    // Обновляем баланс
                    $current_balance = $balance_result->fetch_assoc()['amount'];
                    $new_balance = $current_balance + $amount;
                    $stmt_update = $db->prepare("UPDATE premium_currency SET amount = ? WHERE user_id = ?");
                    $stmt_update->bind_param("di", $new_balance, $user_id);
                    $stmt_update->execute();
                } else {
                    // Создаем новую запись
                    $stmt_insert = $db->prepare("INSERT INTO premium_currency (user_id, amount) VALUES (?, ?)");
                    $stmt_insert->bind_param("id", $user_id, $amount);
                    $stmt_insert->execute();
                }
                
                // Записываем транзакцию
                $stmt_trans = $db->prepare("INSERT INTO currency_transactions (user_id, amount, transaction_type, description, admin_id) VALUES (?, ?, 'admin_add', ?, ?)");
                $stmt_trans->bind_param("idsi", $user_id, $amount, $description, $current_user_id);
                $stmt_trans->execute();
                
                $db->commit();
                $success = "Успешно добавлено {$amount} D3X Coin пользователю {$user_info['username']}";
                logAction($current_user_id, 'admin_add_coins', "Добавлено {$amount} D3X Coin пользователю ID: {$user_id}. Описание: {$description}");
            } catch (Exception $e) {
                $db->rollback();
                $error = "Ошибка при добавлении монет: " . $e->getMessage();
                error_log("Currency add error: " . $e->getMessage());
            }
        } else {
            $error = "Пользователь не найден или заблокирован.";
        }
    } else {
        $error = "Некорректные данные. ID пользователя и сумма должны быть положительными числами.";
    }
}

// Создание нового товара
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_item'])) {
    $name = sanitize($_POST['name']);
    $description = sanitize($_POST['description']);
    $price = (float)$_POST['price'];
    $item_type = $_POST['item_type'];
    $item_data = sanitize($_POST['item_data']);
    
    if (!empty($name) && $price > 0) {
        $stmt_item = $db->prepare("INSERT INTO premium_items (name, description, price, item_type, item_data, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_item->bind_param("ssdssi", $name, $description, $price, $item_type, $item_data, $current_user_id);
        
        if ($stmt_item->execute()) {
            $success = "Товар '{$name}' успешно создан!";
            logAction($current_user_id, 'admin_create_item', "Создан товар: {$name}, цена: {$price} D3X Coin");
        } else {
            $error = "Ошибка при создании товара.";
        }
    } else {
        $error = "Название товара и цена обязательны.";
    }
}

// Удаление товара
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    $item_id = (int)$_POST['item_id'];
    
    $stmt_delete = $db->prepare("UPDATE premium_items SET is_active = 0 WHERE id = ?");
    $stmt_delete->bind_param("i", $item_id);
    
    if ($stmt_delete->execute()) {
        $success = "Товар успешно удален!";
        logAction($current_user_id, 'admin_delete_item', "Удален товар ID: {$item_id}");
    } else {
        $error = "Ошибка при удалении товара.";
    }
}

// Получаем статистику
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM users WHERE banned = 0) as total_users,
        (SELECT COUNT(*) FROM premium_items WHERE is_active = 1) as total_items,
        (SELECT SUM(amount) FROM premium_currency) as total_currency,
        (SELECT COUNT(*) FROM currency_transactions WHERE transaction_type = 'spent') as total_purchases
";
$stats = $db->query($stats_query)->fetch_assoc();

// Получаем топ пользователей по балансу
$top_users = $db->query("
    SELECT u.id, u.username, pc.amount 
    FROM users u 
    JOIN premium_currency pc ON u.id = pc.user_id 
    WHERE u.banned = 0 
    ORDER BY pc.amount DESC 
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// Получаем все товары
$items = $db->query("
    SELECT pi.*, u.username as creator_name,
           (SELECT COUNT(*) FROM user_purchases WHERE item_id = pi.id) as purchase_count
    FROM premium_items pi 
    JOIN users u ON pi.created_by = u.id 
    WHERE pi.is_active = 1
    ORDER BY pi.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Получаем последние транзакции
$recent_transactions = $db->query("
    SELECT ct.*, u.username, ua.username as admin_name
    FROM currency_transactions ct
    JOIN users u ON ct.user_id = u.id
    LEFT JOIN users ua ON ct.admin_id = ua.id
    ORDER BY ct.created_at DESC
    LIMIT 20
")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление валютой | <?= SITE_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
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
        }

        body {
            background: var(--bg-dark);
            padding: 30px;
            min-height: 100vh;
        }

        .admin-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .admin-header {
            background: var(--bg-medium);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
            box-shadow: 0 0 25px var(--shadow-color);
            text-align: center;
        }

        .admin-header h1 {
            font-family: var(--font-mono);
            color: var(--accent-cyan);
            font-size: 2.5rem;
            margin-bottom: 10px;
            text-shadow: 0 0 15px var(--accent-cyan);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }

        .stat-card {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(100, 255, 218, 0.2);
        }

        .stat-card i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: var(--accent-purple);
        }

        .stat-card h3 {
            font-size: 2rem;
            color: var(--accent-cyan);
            margin-bottom: 5px;
            font-family: var(--font-mono);
        }

        .stat-card p {
            color: var(--text-secondary);
            margin: 0;
        }

        .admin-tabs {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .admin-tab {
            flex: 1;
            min-width: 200px;
            padding: 15px;
            background: var(--bg-medium);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            color: var(--text-secondary);
        }

        .admin-tab.active {
            background: rgba(100, 255, 218, 0.2);
            color: var(--accent-cyan);
            border-color: var(--accent-cyan);
            box-shadow: 0 0 15px rgba(100, 255, 218, 0.3);
        }

        .admin-tab:hover:not(.active) {
            background: var(--bg-light);
            color: var(--text-primary);
        }

        .admin-section {
            background: var(--bg-medium);
            border-radius: 15px;
            padding: 30px;
            border: 1px solid var(--border-color);
            box-shadow: 0 0 25px var(--shadow-color);
            margin-bottom: 30px;
        }

        .admin-section h3 {
            font-family: var(--font-mono);
            color: var(--accent-cyan);
            font-size: 1.8rem;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .item-admin-card {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: 15px;
            padding: 20px;
            transition: all 0.3s ease;
        }

        .item-admin-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(100, 255, 218, 0.15);
        }

        .item-admin-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .item-admin-name {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-primary);
            font-family: var(--font-mono);
        }

        .item-admin-price {
            background: rgba(255, 193, 7, 0.2);
            color: var(--accent-gold);
            padding: 5px 12px;
            border-radius: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .transaction-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .transaction-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            margin-bottom: 10px;
        }

        .transaction-info h4 {
            margin: 0 0 5px 0;
            color: var(--text-primary);
        }

        .transaction-info p {
            margin: 0;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .transaction-amount {
            font-weight: 700;
            font-size: 1.1rem;
        }

        .transaction-amount.positive {
            color: var(--accent-green);
        }

        .transaction-amount.negative {
            color: var(--accent-pink);
        }

        .users-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .user-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 8px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-balance {
            font-weight: 700;
            color: var(--accent-gold);
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--accent-cyan);
            text-decoration: none;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .back-link:hover {
            color: var(--accent-blue);
            transform: translateX(-5px);
        }

        @media (max-width: 768px) {
            body {
                padding: 20px;
            }
            
            .admin-header h1 {
                font-size: 2rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 15px;
            }
            
            .admin-tabs {
                flex-direction: column;
                gap: 10px;
            }
            
            .admin-tab {
                min-width: unset;
            }
            
            .form-grid, .items-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <a href="admin.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Вернуться в админ-панель
        </a>

        <div class="admin-header">
            <h1><i class="fas fa-coins"></i> Управление D3X Coin</h1>
            <p style="color: var(--text-secondary);">
                Административная панель для управления внутренней валютой и товарами
            </p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- Статистика -->
        <div class="stats-grid">
            <div class="stat-card">
                <i class="fas fa-users"></i>
                <h3><?= number_format($stats['total_users']) ?></h3>
                <p>Всего пользователей</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-box"></i>
                <h3><?= number_format($stats['total_items']) ?></h3>
                <p>Товаров в магазине</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-coins"></i>
                <h3><?= number_format($stats['total_currency'] ?? 0, 2) ?></h3>
                <p>D3X Coin в обороте</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-shopping-cart"></i>
                <h3><?= number_format($stats['total_purchases']) ?></h3>
                <p>Всего покупок</p>
            </div>
        </div>

        <!-- Табы -->
        <div class="admin-tabs">
            <div class="admin-tab active" onclick="showTab('coins')">
                <i class="fas fa-plus-circle"></i> Выдать монеты
            </div>
            <div class="admin-tab" onclick="showTab('items')">
                <i class="fas fa-box"></i> Управление товарами
            </div>
            <div class="admin-tab" onclick="showTab('transactions')">
                <i class="fas fa-history"></i> Транзакции
            </div>
            <div class="admin-tab" onclick="showTab('users')">
                <i class="fas fa-users"></i> Топ пользователей
            </div>
        </div>

        <!-- Выдача монет -->
        <div id="coins-section" class="admin-section">
            <h3><i class="fas fa-plus-circle"></i> Выдать D3X Coin пользователю</h3>
            
            <form method="POST" class="form-grid">
                <div class="form-group">
                    <input type="number" name="user_id" id="user_id" required placeholder=" " min="1">
                    <i class="fas fa-user"></i>
                    <label for="user_id">ID пользователя</label>
                </div>
                
                <div class="form-group">
                    <input type="number" name="amount" id="amount" required placeholder=" " min="0.01" step="0.01">
                    <i class="fas fa-coins"></i>
                    <label for="amount">Количество D3X Coin</label>
                </div>
                
                <div class="form-group" style="grid-column: 1 / -1;">
                    <input type="text" name="description" id="description" required placeholder=" ">
                    <i class="fas fa-comment"></i>
                    <label for="description">Описание (причина выдачи)</label>
                </div>
                
                <div style="grid-column: 1 / -1;">
                    <button type="submit" name="add_coins" class="btn">
                        <i class="fas fa-plus"></i> Выдать монеты
                    </button>
                </div>
            </form>
        </div>

        <!-- Управление товарами -->
        <div id="items-section" class="admin-section" style="display: none;">
            <h3><i class="fas fa-box"></i> Создать новый товар</h3>
            
            <form method="POST" class="form-grid">
                <div class="form-group">
                    <input type="text" name="name" id="item_name" required placeholder=" ">
                    <i class="fas fa-tag"></i>
                    <label for="item_name">Название товара</label>
                </div>
                
                <div class="form-group">
                    <input type="number" name="price" id="item_price" required placeholder=" " min="0.01" step="0.01">
                    <i class="fas fa-coins"></i>
                    <label for="item_price">Цена в D3X Coin</label>
                </div>
                
                <div class="form-group">
                    <select name="item_type" id="item_type" required>
                        <option value="">Выберите тип</option>
                        <option value="sticker">Стикер</option>
                        <option value="avatar_frame">Рамка для аватара</option>
                        <option value="theme">Тема оформления</option>
                        <option value="badge">Значок</option>
                    </select>
                    <i class="fas fa-list"></i>
                    <label for="item_type">Тип товара</label>
                </div>
                
                <div class="form-group" style="grid-column: 1 / -1;">
                    <textarea name="description" id="item_description" required placeholder=" " rows="3"></textarea>
                    <i class="fas fa-align-left"></i>
                    <label for="item_description">Описание товара</label>
                </div>
                
                <div class="form-group" style="grid-column: 1 / -1;">
                    <input type="text" name="item_data" id="item_data" placeholder=" ">
                    <i class="fas fa-code"></i>
                    <label for="item_data">Дополнительные данные (JSON, ссылки и т.д.)</label>
                </div>
                
                <div style="grid-column: 1 / -1;">
                    <button type="submit" name="create_item" class="btn">
                        <i class="fas fa-plus"></i> Создать товар
                    </button>
                </div>
            </form>

            <h3 style="margin-top: 40px;"><i class="fas fa-list"></i> Существующие товары</h3>
            
            <?php if (empty($items)): ?>
                <div class="no-items" style="text-align: center; color: var(--text-secondary); padding: 40px 0;">
                    <i class="fas fa-box-open" style="font-size: 3rem; margin-bottom: 15px;"></i>
                    <p>Товаров пока нет</p>
                </div>
            <?php else: ?>
                <div class="items-grid">
                    <?php foreach ($items as $item): ?>
                        <div class="item-admin-card">
                            <div class="item-admin-header">
                                <div class="item-admin-name"><?= htmlspecialchars($item['name']) ?></div>
                                <div class="item-admin-price">
                                    <i class="fas fa-coins"></i>
                                    <?= number_format($item['price'], 2) ?>
                                </div>
                            </div>
                            
                            <p style="color: var(--text-secondary); margin-bottom: 15px;">
                                <?= htmlspecialchars($item['description']) ?>
                            </p>
                            
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                <span class="badge badge-<?= match($item['item_type']) {
                                    'sticker' => 'pending',
                                    'avatar_frame' => 'approved',
                                    'theme' => 'active',
                                    'badge' => 'warning',
                                    default => 'secondary'
                                } ?>">
                                    <?= match($item['item_type']) {
                                        'sticker' => 'Стикер',
                                        'avatar_frame' => 'Рамка',
                                        'theme' => 'Тема',
                                        'badge' => 'Значок',
                                        default => 'Товар'
                                    } ?>
                                </span>
                                <span style="color: var(--text-secondary); font-size: 0.9rem;">
                                    Покупок: <?= $item['purchase_count'] ?>
                                </span>
                            </div>
                            
                            <div style="display: flex; gap: 10px;">
                                <form method="POST" style="flex: 1;">
                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                    <button type="submit" name="delete_item" class="btn btn-danger btn-sm"
                                            onclick="return confirm('Удалить товар <?= htmlspecialchars($item['name']) ?>?')">
                                        <i class="fas fa-trash"></i> Удалить
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- История транзакций -->
        <div id="transactions-section" class="admin-section" style="display: none;">
            <h3><i class="fas fa-history"></i> Последние транзакции</h3>
            
            <?php if (empty($recent_transactions)): ?>
                <div style="text-align: center; color: var(--text-secondary); padding: 40px 0;">
                    <i class="fas fa-receipt" style="font-size: 3rem; margin-bottom: 15px;"></i>
                    <p>Транзакций пока нет</p>
                </div>
            <?php else: ?>
                <div class="transaction-list">
                    <?php foreach ($recent_transactions as $transaction): ?>
                        <div class="transaction-item">
                            <div class="transaction-info">
                                <h4><?= htmlspecialchars($transaction['username']) ?></h4>
                                <p>
                                    <?= htmlspecialchars($transaction['description']) ?>
                                    <?php if ($transaction['admin_name']): ?>
                                        <br><small>Администратор: <?= htmlspecialchars($transaction['admin_name']) ?></small>
                                    <?php endif; ?>
                                </p>
                                <p><?= date('d.m.Y H:i', strtotime($transaction['created_at'])) ?></p>
                            </div>
                            <div class="transaction-amount <?= $transaction['amount'] >= 0 ? 'positive' : 'negative' ?>">
                                <?= $transaction['amount'] >= 0 ? '+' : '' ?><?= number_format($transaction['amount'], 2) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Топ пользователей -->
        <div id="users-section" class="admin-section" style="display: none;">
            <h3><i class="fas fa-users"></i> Топ пользователей по балансу D3X Coin</h3>
            
            <?php if (empty($top_users)): ?>
                <div style="text-align: center; color: var(--text-secondary); padding: 40px 0;">
                    <i class="fas fa-users" style="font-size: 3rem; margin-bottom: 15px;"></i>
                    <p>Пользователей с балансом пока нет</p>
                </div>
            <?php else: ?>
                <div class="users-list">
                    <?php foreach ($top_users as $index => $user): ?>
                        <div class="user-item">
                            <div class="user-info">
                                <span style="background: var(--accent-gold); color: var(--bg-dark); width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                                    <?= $index + 1 ?>
                                </span>
                                <div>
                                    <strong><?= htmlspecialchars($user['username']) ?></strong>
                                    <small style="color: var(--text-secondary); display: block;">ID: <?= $user['id'] ?></small>
                                </div>
                            </div>
                            <div class="user-balance">
                                <?= number_format($user['amount'], 2) ?> D3X Coin
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // Скрываем все секции
            document.querySelectorAll('.admin-section').forEach(section => {
                section.style.display = 'none';
            });
            
            // Убираем активный класс со всех табов
            document.querySelectorAll('.admin-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Показываем нужную секцию и активируем таб
            document.getElementById(tabName + '-section').style.display = 'block';
            document.querySelector(`[onclick="showTab('${tabName}')"]`).classList.add('active');
        }
    </script>
</body>
</html>