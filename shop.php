<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . '/config.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

// Проверка включен ли магазин
if (!isShopEnabled()) {
    $_SESSION['error'] = 'Магазин временно отключен администратором по техническим причинам.';
    header("Location: chat.php");
    exit;
}

$db = getDB();
$current_user_id = $_SESSION['user_id'];
$current_username = htmlspecialchars($_SESSION['username']);
$current_user_role = $_SESSION['role'];
$current_avatar = htmlspecialchars($_SESSION['avatar'] ?? DEFAULT_AVATAR);
$error = '';
$success = '';

// Получаем баланс D3X Coin пользователя
function getUserD3XBalance($user_id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT amount FROM premium_currency WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['amount'];
    }
    return 0.00;
}

// Покупка товара
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy_item'])) {
    $item_id = (int)$_POST['item_id'];
    
    // Получаем информацию о товаре
    $stmt = $db->prepare("SELECT * FROM premium_items WHERE id = ? AND is_active = 1");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    
    if (!$item) {
        $error = "Товар не найден или недоступен для покупки.";
    } else {
        $user_balance = getUserD3XBalance($current_user_id);
        
        if ($user_balance < $item['price']) {
            $error = "Недостаточно D3X Coin для покупки. Требуется: " . $item['price'] . ", у вас: " . $user_balance;
        } else {
            // Проверяем, не покупал ли пользователь уже этот товар
            $stmt_check = $db->prepare("SELECT id FROM user_purchases WHERE user_id = ? AND item_id = ?");
            $stmt_check->bind_param("ii", $current_user_id, $item_id);
            $stmt_check->execute();
            
            if ($stmt_check->get_result()->num_rows > 0) {
                $error = "Вы уже приобрели этот товар.";
            } else {
                $db->begin_transaction();
                try {
                    // Списываем D3X Coin
                    $new_balance = $user_balance - $item['price'];
                    $stmt_balance = $db->prepare("UPDATE premium_currency SET amount = ? WHERE user_id = ?");
                    $stmt_balance->bind_param("di", $new_balance, $current_user_id);
                    $stmt_balance->execute();
                    
                    // Добавляем запись о покупке
                    $stmt_purchase = $db->prepare("INSERT INTO user_purchases (user_id, item_id, purchase_price) VALUES (?, ?, ?)");
                    $stmt_purchase->bind_param("iid", $current_user_id, $item_id, $item['price']);
                    $stmt_purchase->execute();
                    
                    // Записываем транзакцию
                    $stmt_trans = $db->prepare("INSERT INTO currency_transactions (user_id, amount, transaction_type, description) VALUES (?, ?, 'spent', ?)");
                    $negative_amount = -$item['price'];
                    $description = "Покупка: " . $item['name'];
                    $stmt_trans->bind_param("ids", $current_user_id, $negative_amount, $description);
                    $stmt_trans->execute();
                    
                    $db->commit();
                    $success = "Товар '" . htmlspecialchars($item['name']) . "' успешно приобретен!";
                    logAction($current_user_id, 'purchase_item', "Приобретен товар: " . $item['name'] . " за " . $item['price'] . " D3X Coin");
                } catch (Exception $e) {
                    $db->rollback();
                    $error = "Ошибка при покупке товара. Попробуйте позже.";
                    error_log("Purchase error: " . $e->getMessage());
                }
            }
        }
    }
}

// Получаем все активные товары
$items_stmt = $db->prepare("SELECT * FROM premium_items WHERE is_active = 1 ORDER BY price ASC");
$items_stmt->execute();
$items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Получаем покупки пользователя
$purchases_stmt = $db->prepare("
    SELECT pi.*, up.purchased_at 
    FROM user_purchases up 
    JOIN premium_items pi ON up.item_id = pi.id 
    WHERE up.user_id = ? 
    ORDER BY up.purchased_at DESC
");
$purchases_stmt->bind_param("i", $current_user_id);
$purchases_stmt->execute();
$user_purchases = $purchases_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$user_balance = getUserD3XBalance($current_user_id);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>D3X Shop | <?= SITE_NAME ?></title>
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
                radial-gradient(circle at 50% 50%, var(--accent-gold) 0%, transparent 30%);
            background-size: 100% 100%;
            animation: moveBackground 25s infinite alternate ease-in-out;
            opacity: 0.08;
            z-index: -1;
            filter: blur(60px);
        }

        @keyframes moveBackground {
            0% { 
                background-position: 0% 0%, 100% 100%, 50% 50%; 
                transform: scale(1) rotate(0deg);
            }
            50% {
                transform: scale(1.1) rotate(5deg);
            }
            100% { 
                background-position: 100% 100%, 0% 0%, 30% 70%; 
                transform: scale(1) rotate(0deg);
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
            width: 4px;
            height: 4px;
            background: var(--accent-gold);
            border-radius: 50%;
            opacity: 0.4;
            animation: float 20s infinite ease-in-out;
            box-shadow: 0 0 10px var(--accent-gold);
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0) translateX(0) scale(1);
                opacity: 0;
            }
            10% {
                opacity: 0.4;
            }
            90% {
                opacity: 0.4;
            }
            100% {
                transform: translateY(-100vh) translateX(100px) scale(0.5);
                opacity: 0;
            }
        }

        .shop-container {
            max-width: 1400px;
            width: 100%;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .shop-header {
            text-align: center;
            margin-bottom: 3rem;
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

        .shop-header h1 {
            font-size: clamp(2.5rem, 6vw, 4.5rem);
            font-weight: 900;
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple), var(--accent-gold));
            background-size: 200% 200%;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            animation: gradientShift 5s ease infinite;
            filter: drop-shadow(0 0 30px rgba(251, 191, 36, 0.4));
        }

        @keyframes gradientShift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        .shop-header h1 i {
            animation: bounce 2s ease-in-out infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .shop-header p {
            color: var(--text-secondary);
            font-size: 1.2rem;
            margin-bottom: 2rem;
            font-weight: 500;
        }

        .balance-display {
            display: inline-flex;
            align-items: center;
            gap: 1rem;
            padding: 1.25rem 2.5rem;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(251, 191, 36, 0.3);
            border-radius: 20px;
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--accent-gold);
            box-shadow: 0 15px 40px rgba(251, 191, 36, 0.3);
            position: relative;
            overflow: hidden;
            animation: pulse 2s ease-in-out infinite;
        }

        .balance-display::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(251, 191, 36, 0.1), transparent 70%);
            animation: rotate 10s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .balance-display i {
            color: var(--accent-gold);
            font-size: 1.75rem;
            animation: spin 3s linear infinite;
            z-index: 1;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .balance-display span {
            z-index: 1;
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
            margin-bottom: 2rem;
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

        .shop-tabs {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 3rem;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 0.75rem;
            animation: fadeInUp 0.8s ease-out;
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

        .shop-tab {
            flex: 1;
            padding: 1.25rem 2rem;
            text-align: center;
            border-radius: 16px;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            font-weight: 700;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
        }

        .shop-tab::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple));
            opacity: 0;
            transition: opacity 0.4s;
        }

        .shop-tab:hover {
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.05);
            transform: translateY(-2px);
        }

        .shop-tab.active {
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple));
            color: var(--text-primary);
            box-shadow: 0 10px 30px rgba(0, 217, 255, 0.4);
        }

        .shop-tab i,
        .shop-tab span {
            z-index: 1;
        }

        .shop-section {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 3rem;
            backdrop-filter: blur(20px);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
            animation: fadeInUp 0.8s ease-out;
        }

        .section-title {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 2.5rem;
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
            width: 100px;
            height: 3px;
            background: linear-gradient(90deg, var(--accent-cyan), var(--accent-purple));
            border-radius: 2px;
        }

        .section-title i {
            color: var(--accent-cyan);
            filter: drop-shadow(0 0 15px var(--glow-cyan));
        }

        .items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 2.5rem;
        }

        .item-card {
            background: rgba(255, 255, 255, 0.04);
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 2.5rem;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transform-style: preserve-3d;
        }

        .item-card::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple), var(--accent-gold));
            border-radius: 24px;
            opacity: 0;
            transition: opacity 0.5s;
            z-index: -1;
        }

        .item-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at var(--mouse-x, 50%) var(--mouse-y, 50%), rgba(251, 191, 36, 0.1), transparent 50%);
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }

        .item-card:hover::after {
            opacity: 1;
        }

        .item-card:hover {
            transform: translateY(-15px) rotateX(5deg);
            border-color: var(--accent-cyan);
            box-shadow: 0 30px 80px rgba(0, 217, 255, 0.3);
        }

        .item-card:hover::before {
            opacity: 0.15;
        }

        .item-type-badge {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            padding: 0.75rem 1.25rem;
            border-radius: 25px;
            font-size: 0.8rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
            z-index: 1;
        }

        .item-type-sticker {
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple));
            color: var(--text-primary);
        }

        .item-type-avatar_frame {
            background: linear-gradient(135deg, var(--accent-purple), var(--accent-pink));
            color: var(--text-primary);
        }

        .item-type-theme {
            background: linear-gradient(135deg, var(--accent-pink), var(--accent-gold));
            color: var(--text-primary);
        }

        .item-type-badge {
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-gold));
            color: var(--text-primary);
        }

        .item-name {
            font-size: 1.75rem;
            font-weight: 800;
            margin-bottom: 1rem;
            color: var(--text-primary);
            line-height: 1.3;
        }

        .item-description {
            color: var(--text-secondary);
            line-height: 1.7;
            margin-bottom: 2rem;
            flex: 1;
            font-size: 0.95rem;
        }

        .item-price {
            margin-bottom: 2rem;
        }

        .price-value {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 2rem;
            font-weight: 900;
            color: var(--accent-gold);
            text-shadow: 0 0 20px rgba(251, 191, 36, 0.5);
        }

        .price-value i {
            color: var(--accent-gold);
            animation: pulse 2s ease-in-out infinite;
        }

        .btn {
            padding: 1.25rem 2rem;
            border-radius: 16px;
            border: none;
            font-weight: 700;
            font-size: 1.05rem;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            width: 100%;
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

        .btn-success {
            background: rgba(0, 217, 255, 0.15);
            border: 2px solid var(--accent-cyan);
            color: var(--accent-cyan);
            cursor: not-allowed;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(255, 255, 255, 0.1);
            color: var(--text-secondary);
            cursor: not-allowed;
        }

        .purchases-list {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .purchase-item {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
        }

        .purchase-item::before {
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

        .purchase-item:hover {
            background: rgba(255, 255, 255, 0.06);
            border-color: var(--accent-cyan);
            transform: translateX(8px);
            box-shadow: 0 10px 40px rgba(0, 217, 255, 0.2);
        }

        .purchase-item:hover::before {
            transform: scaleY(1);
        }

        .purchase-info h4 {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .purchase-info p {
            color: var(--text-secondary);
            line-height: 1.6;
        }

        .purchase-date {
            color: var(--text-secondary);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert {
            padding: 1.5rem 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: slideInRight 0.5s ease-out;
            font-weight: 600;
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

        .no-items {
            text-align: center;
            padding: 4rem 1rem;
            color: var(--text-secondary);
        }

        .no-items i {
            font-size: 5rem;
            margin-bottom: 1.5rem;
            opacity: 0.2;
            color: var(--accent-cyan);
            animation: pulse 2s ease-in-out infinite;
        }

        .no-items p {
            font-size: 1.1rem;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 12px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.02);
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--accent-cyan), var(--accent-purple));
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, var(--accent-purple), var(--accent-gold));
        }

        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .items-grid {
                grid-template-columns: 1fr;
            }

            .shop-tabs {
                flex-direction: column;
            }

            .purchase-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .shop-section {
                padding: 2rem 1.5rem;
            }
        }
    </style>
</head>
<body class="shop-page">
    <!-- Particle Effect -->
    <div class="particles" id="particles"></div>

    <div class="shop-container">
        <a href="chat.php" class="btn-back">
            <i class="fas fa-arrow-left"></i> Вернуться в чат
        </a>

        <div class="shop-header">
            <h1><i class="fas fa-store"></i> D3X Shop</h1>
            <p>Магазин премиум товаров для D3X Messenger</p>
            <div class="balance-display">
                <i class="fas fa-coins"></i>
                <span><?= number_format($user_balance, 2) ?> D3X Coin</span>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <div class="shop-tabs">
            <div class="shop-tab active" onclick="showTab('shop')">
                <i class="fas fa-shopping-cart"></i> <span>Магазин</span>
            </div>
            <div class="shop-tab" onclick="showTab('purchases')">
                <i class="fas fa-receipt"></i> <span>Мои покупки</span>
            </div>
        </div>

        <!-- Магазин товаров -->
        <div id="shop-section" class="shop-section">
            <h3 class="section-title">
                <i class="fas fa-shopping-cart"></i> Доступные товары
            </h3>
            
            <?php if (empty($items)): ?>
                <div class="no-items">
                    <i class="fas fa-box-open"></i>
                    <p>В магазине пока нет товаров</p>
                    <?php if ($current_user_role === 'admin'): ?>
                        <p style="margin-top: 1rem; font-size: 0.9rem;">
                            <a href="currency_admin.php" style="color: var(--accent-cyan);">Добавить товары в админ-панели</a>
                        </p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="items-grid">
                    <?php foreach ($items as $item): ?>
                        <div class="item-card">
                            <div class="item-type-badge item-type-<?= $item['item_type'] ?>">
                                <?= match($item['item_type']) {
                                    'sticker' => 'Стикер',
                                    'avatar_frame' => 'Рамка',
                                    'theme' => 'Тема',
                                    'badge' => 'Значок',
                                    default => 'Товар'
                                } ?>
                            </div>
                            
                            <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                            <div class="item-description"><?= htmlspecialchars($item['description']) ?></div>
                            
                            <div class="item-price">
                                <div class="price-value">
                                    <i class="fas fa-coins"></i>
                                    <?= number_format($item['price'], 2) ?>
                                </div>
                            </div>

                            <?php
                            // Проверяем, купил ли пользователь уже этот товар
                            $is_purchased = false;
                            foreach ($user_purchases as $purchase) {
                                if ($purchase['id'] == $item['id']) {
                                    $is_purchased = true;
                                    break;
                                }
                            }
                            ?>

                            <?php if ($is_purchased): ?>
                                <button class="btn btn-success" disabled>
                                    <i class="fas fa-check"></i> Приобретено
                                </button>
                            <?php elseif ($user_balance < $item['price']): ?>
                                <button class="btn btn-secondary" disabled>
                                    <i class="fas fa-coins"></i> Недостаточно средств
                                </button>
                            <?php else: ?>
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                    <button type="submit" name="buy_item" class="btn btn-primary" 
                                            onclick="return confirm('Купить <?= htmlspecialchars($item['name']) ?> за <?= $item['price'] ?> D3X Coin?')">
                                        <i class="fas fa-shopping-cart"></i> <span>Купить</span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Мои покупки -->
        <div id="purchases-section" class="shop-section" style="display: none;">
            <h3 class="section-title">
                <i class="fas fa-receipt"></i> История покупок
            </h3>
            
            <?php if (empty($user_purchases)): ?>
                <div class="no-items">
                    <i class="fas fa-receipt"></i>
                    <p>У вас пока нет покупок</p>
                </div>
            <?php else: ?>
                <div class="purchases-list">
                    <?php foreach ($user_purchases as $purchase): ?>
                        <div class="purchase-item">
                            <div class="purchase-info">
                                <h4><?= htmlspecialchars($purchase['name']) ?></h4>
                                <p><?= htmlspecialchars($purchase['description']) ?></p>
                            </div>
                            <div class="purchase-date">
                                <i class="fas fa-calendar"></i> <?= date('d.m.Y H:i', strtotime($purchase['purchased_at'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Create particles
        const particlesContainer = document.getElementById('particles');
        const particleCount = 40;
        
        for (let i = 0; i < particleCount; i++) {
            const particle = document.createElement('div');
            particle.className = 'particle';
            particle.style.left = Math.random() * 100 + '%';
            particle.style.animationDelay = Math.random() * 20 + 's';
            particle.style.animationDuration = (15 + Math.random() * 10) + 's';
            
            // Random colors for particles
            const colors = ['var(--accent-cyan)', 'var(--accent-purple)', 'var(--accent-gold)'];
            particle.style.background = colors[Math.floor(Math.random() * colors.length)];
            
            particlesContainer.appendChild(particle);
        }

        // Mouse tracking for item cards
        document.querySelectorAll('.item-card').forEach(card => {
            card.addEventListener('mousemove', (e) => {
                const rect = card.getBoundingClientRect();
                const x = ((e.clientX - rect.left) / rect.width) * 100;
                const y = ((e.clientY - rect.top) / rect.height) * 100;
                card.style.setProperty('--mouse-x', x + '%');
                card.style.setProperty('--mouse-y', y + '%');
            });
        });

        function showTab(tabName) {
            // Скрываем все секции
            document.querySelectorAll('.shop-section').forEach(section => {
                section.style.display = 'none';
            });
            
            // Убираем активный класс со всех табов
            document.querySelectorAll('.shop-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Показываем нужную секцию и активируем таб
            if (tabName === 'shop') {
                document.getElementById('shop-section').style.display = 'block';
                document.querySelector('[onclick="showTab(\'shop\')"]').classList.add('active');
            } else if (tabName === 'purchases') {
                document.getElementById('purchases-section').style.display = 'block';
                document.querySelector('[onclick="showTab(\'purchases\')"]').classList.add('active');
            }
        }
    </script>
</body>
</html>