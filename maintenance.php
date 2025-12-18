<?php
http_response_code(503);
if (!defined('SITE_NAME')) {
    define('SITE_NAME', 'D3X Project');
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Технические работы | <?= SITE_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css"> <!-- Новый style.css -->
    <style>
        .maintenance-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
            background-color: var(--bg-dark);
            color: var(--text-primary);
            text-align: center;
            padding: 20px;
        }
        
        .maintenance-code {
            font-size: 120px;
            color: var(--accent-pink);
            margin-bottom: 20px;
            text-shadow: 0 0 10px rgba(255, 107, 107, 0.5);
        }
        
        .maintenance-message {
            font-size: 24px;
            margin-bottom: 30px;
            max-width: 600px;
        }
        
        .maintenance-icon {
            font-size: 80px;
            color: var(--accent-cyan);
            margin-bottom: 30px;
            animation: pulse 2s infinite;
        }
        
        .btn-home {
            display: inline-block;
            padding: 12px 30px;
            background: rgba(100, 255, 218, 0.1);
            color: var(--accent-cyan);
            border: 1px solid var(--accent-cyan);
            border-radius: 4px;
            text-decoration: none;
            font-size: 18px;
            transition: all 0.3s ease;
        }
        
        .btn-home:hover {
            background: rgba(100, 255, 218, 0.2);
            transform: translateY(-3px);
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <div class="maintenance-container glass-effect">
        <div class="maintenance-icon">
            <i class="fas fa-tools"></i>
        </div>
        <div class="maintenance-code">503</div>
        <div class="maintenance-message">
            В данный момент проводятся технические работы. Ожидайте новостей в тгк. Пожалуйста, вернитесь позже.
        </div>
        <a href="/" class="btn-home">
            <i class="fas fa-home"></i> Вернуться на главную
        </a>
    </div>
</body>
</html>
