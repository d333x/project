<?php
// Запуск сессии должен быть самым первым действием
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . '/config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Пожалуйста, заполните все поля.';
    } else {
        // Проверка rate limit перед попыткой входа
        $rateLimit = checkLoginRateLimit();
        if (!$rateLimit['allowed']) {
            $error = $rateLimit['message'] ?? 'Слишком много неудачных попыток входа. Попробуйте позже.';
        } else {
            try {
                $db = getDB();
            
            // Используем подготовленные выражения для предотвращения SQL-инъекций
            $stmt = $db->prepare("SELECT id, username, password, role, avatar, banned FROM users WHERE username = ? LIMIT 1");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows === 1) {
                $user = $result->fetch_assoc();
                // Проверяем хеш пароля
                if (password_verify($password, $user['password'])) {
                    // Проверяем статус бана
                    if ($user['banned'] ?? 0) {
                        recordLoginAttempt(null, $username, false);
                        $error = 'Ваш аккаунт заблокирован.';
                        if (function_exists('logAction')) {
                            logAction($user['id'], 'login_failed_banned', "Попытка входа забаненного пользователя: $username");
                        }
                    } else {
                        // Устанавливаем данные сессии
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['avatar'] = $user['avatar'] ?: DEFAULT_AVATAR;
                        
                        // Записываем успешную попытку входа
                        recordLoginAttempt(null, $username, true);
                        
                        if (function_exists('logAction')) {
                            logAction($user['id'], 'login_success', "Успешный вход пользователя: $username");
                        }
                        header("Location: chat.php");
                        exit;
                    }
                } else {
                    // Записываем неудачную попытку входа
                    recordLoginAttempt(null, $username, false);
                    
                    // Проверяем, не превышен ли лимит после этой попытки
                    $rateLimit = checkLoginRateLimit();
                    $error = 'Неверный логин или пароль.';
                    if (!$rateLimit['allowed']) {
                        $error = $rateLimit['message'] ?? $error;
                    } else if ($rateLimit['remaining_attempts'] <= 2) {
                        $error .= ' Осталось попыток: ' . $rateLimit['remaining_attempts'];
                    }
                    
                    if (function_exists('logAction')) {
                        logAction(0, 'login_failed_credentials', "Неудачная попытка входа для пользователя: $username");
                    }
                }
            } else {
                // Записываем неудачную попытку входа
                recordLoginAttempt(null, $username, false);
                
                // Проверяем, не превышен ли лимит после этой попытки
                $rateLimit = checkLoginRateLimit();
                $error = 'Неверный логин или пароль.';
                if (!$rateLimit['allowed']) {
                    $error = $rateLimit['message'] ?? $error;
                } else if ($rateLimit['remaining_attempts'] <= 2) {
                    $error .= ' Осталось попыток: ' . $rateLimit['remaining_attempts'];
                }
                
                if (function_exists('logAction')) {
                    logAction(0, 'login_failed_not_found', "Неудачная попытка входа (пользователь не найден): $username");
                }
            }
            } catch (Exception $e) {
                $error = 'Произошла ошибка при входе. Попробуйте позже.';
                error_log("Login error: " . $e->getMessage());
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход | <?= SITE_NAME ?></title>
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
            --glow-cyan: rgba(0, 217, 255, 0.4);
            --glow-purple: rgba(139, 92, 246, 0.4);
        }

        body.login-page {
            background: var(--bg-dark);
            color: var(--text-primary);
        }

        .auth-container {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .section-title {
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple));
            box-shadow: 0 10px 30px rgba(0, 217, 255, 0.3);
        }

        .btn-primary:hover {
            box-shadow: 0 15px 40px rgba(0, 217, 255, 0.5);
        }

        .form-group input:focus {
            border-color: var(--accent-cyan);
            box-shadow: 0 0 20px rgba(0, 217, 255, 0.2);
        }

        .auth-link a {
            color: var(--accent-cyan);
        }

        .auth-link a:hover {
            color: var(--accent-purple);
        }

        .alert-danger {
            background: rgba(236, 72, 153, 0.1);
            border-color: var(--accent-pink);
        }

        .alert-success {
            background: rgba(0, 217, 255, 0.1);
            border-color: var(--accent-cyan);
        }
    </style>
</head>
<body class="login-page">
    <div class="cyber-grid"></div>
    <div class="animated-lines"></div>
    <div class="particles"></div>
    <div class="auth-container">
        <div class="auth-header">
            <h1 class="section-title">
                <i class="fas fa-sign-in-alt"></i>
                Вход
            </h1>
            <p class="auth-subtitle">Добро пожаловать в D3X Project</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['registration_success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($_SESSION['registration_success']); unset($_SESSION['registration_success']); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="auth-form">
            <div class="form-group">
                <input 
                    id="username" 
                    name="username" 
                    type="text" 
                    required 
                    autocomplete="username" 
                    maxlength="30" 
                    placeholder=" "
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                />
                <i class="fas fa-user"></i>
                <label for="username">Имя пользователя</label>
            </div>
            
            <div class="form-group">
                <input 
                    id="password" 
                    name="password" 
                    type="password" 
                    required 
                    autocomplete="current-password" 
                    placeholder=" "
                />
                <i class="fas fa-lock"></i>
                <label for="password">Пароль</label>
            </div>
            
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-rocket"></i>
                Войти в систему
            </button>
        </form>

        <div class="auth-link">
            <p>Нет аккаунта?</p>
            <a href="register.php">
                <i class="fas fa-user-plus"></i>
                Создать аккаунт
            </a>
        </div>
    </div>

    <script>
        // Particle animation script
        const particlesContainer = document.querySelector('.particles');
        const numParticles = 50;

        for (let i = 0; i < numParticles; i++) {
            const particle = document.createElement('div');
            particle.classList.add('particle');
            particle.style.left = Math.random() * 100 + 'vw';
            particle.style.animationDuration = (Math.random() * 10 + 10) + 's'; // 10-20s
            particle.style.animationDelay = (Math.random() * -15) + 's'; // -15s to 0s
            particlesContainer.appendChild(particle);
        }

        // Animated lines script
        const linesContainer = document.querySelector('.animated-lines');
        const numLines = 20;

        for (let i = 0; i < numLines; i++) {
            const line = document.createElement('div');
            line.classList.add('line');
            line.style.left = Math.random() * 100 + 'vw';
            line.style.animationDuration = (Math.random() * 10 + 10) + 's'; // 10-20s
            line.style.animationDelay = (Math.random() * -20) + 's'; // -20s to 0s
            linesContainer.appendChild(line);
        }

        // Enhanced form submission
        const form = document.querySelector('.auth-form');
        form.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('.btn-primary');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Вход в систему...';
        });

        // Auto-focus on first empty field
        document.addEventListener('DOMContentLoaded', function() {
            const usernameInput = document.getElementById('username');
            const passwordInput = document.getElementById('password');
            
            if (!usernameInput.value) {
                usernameInput.focus();
            } else {
                passwordInput.focus();
            }
        });
    </script>
</body>
</html>