<?php
require __DIR__ . '/config.php';

$error = '';
$success = '';

// Check if registration is enabled
function isRegistrationEnabled() {
    try {
        $db = getDB();
        $result = $db->query("SELECT registration_enabled FROM site_settings WHERE id = 1 LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            return (bool)$row['registration_enabled'];
        }
        return true; // Default to enabled if setting doesn't exist
    } catch (Exception $e) {
        error_log("Registration check error: " . $e->getMessage());
        return true; // Default to enabled on error
    }
}

// Check if invite keys are required
function areInviteKeysRequired() {
    try {
        $db = getDB();
        $result = $db->query("SELECT registration_keys_required FROM site_settings WHERE id = 1 LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            return (bool)$row['registration_keys_required'];
        }
        return true; // Default to required if setting doesn't exist
    } catch (Exception $e) {
        error_log("Invite keys check error: " . $e->getMessage());
        return true; // Default to required on error
    }
}

$registration_enabled = isRegistrationEnabled();
$invite_keys_required = areInviteKeysRequired();

if (!$registration_enabled) {
    $error = 'Регистрация временно отключена администратором.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $registration_enabled) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $invite_code = trim($_POST['invite_code'] ?? '');

    if (empty($username) || empty($password) || empty($password_confirm)) {
        $error = 'Пожалуйста, заполните все обязательные поля.';
    } elseif ($invite_keys_required && empty($invite_code)) {
        $error = 'Инвайт-код обязателен для регистрации.';
    } elseif ($password !== $password_confirm) {
        $error = 'Пароли не совпадают.';
    } elseif (strlen($password) < 6) {
        $error = 'Пароль должен быть не менее 6 символов.';
    } elseif (!preg_match("/^[a-zA-Z0-9_]+$/", $username)) {
        $error = 'Имя пользователя может содержать только латинские буквы, цифры и подчеркивания.';
    } elseif (strlen($username) < 3 || strlen($username) > 30) {
        $error = 'Имя пользователя должно быть от 3 до 30 символов.';
    } else {
        $db = getDB();
        $username = $db->real_escape_string($username);

        // Check invite code only if required
        if ($invite_keys_required) {
            $stmt_invite = $db->prepare("SELECT id, used_by FROM access_keys WHERE key_value = ? AND used_at IS NULL");
            $stmt_invite->bind_param("s", $invite_code);
            $stmt_invite->execute();
            $result_invite = $stmt_invite->get_result();
            $invite_data = $result_invite->fetch_assoc();

            if (!$invite_data) {
                $error = 'Неверный или уже использованный инвайт-код.';
            }
        }

        if (!$error) {
            // Check if username already exists
            $exists = $db->query("SELECT id FROM users WHERE username = '$username'")->num_rows;
            if ($exists) {
                $error = 'Пользователь с таким именем уже существует.';
            } else {
                // Register user
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $avatar = DEFAULT_AVATAR;
                $role = ROLE_USER;
                $created_at = date('Y-m-d H:i:s');

                $db->begin_transaction();
                try {
                    $stmt = $db->prepare("INSERT INTO users (username, password, avatar, role, created_at) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssds", $username, $password_hash, $avatar, $role, $created_at);
                    $stmt->execute();
                    $new_user_id = $db->insert_id;

                    // Mark invite code as used only if required
                    if ($invite_keys_required && isset($invite_data)) {
                        $stmt_update_invite = $db->prepare("UPDATE access_keys SET used_by = ?, used_at = NOW() WHERE id = ?");
                        $stmt_update_invite->bind_param("ii", $new_user_id, $invite_data['id']);
                        $stmt_update_invite->execute();
                    }

                    $db->commit();
                    $_SESSION['registration_success'] = 'Регистрация прошла успешно! Теперь вы можете войти.';
                    header("Location: login.php");
                    exit;
                } catch (Exception $e) {
                    $db->rollback();
                    $error = 'Ошибка при регистрации: ' . $e->getMessage();
                    error_log("Registration error: " . $e->getMessage());
                }
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
    <title>Регистрация | <?= SITE_NAME ?></title>
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

        body.register-page {
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

        .password-strength-bar.weak {
            background: var(--accent-pink);
        }

        .password-strength-bar.medium {
            background: var(--accent-purple);
        }

        .password-strength-bar.strong {
            background: var(--accent-cyan);
        }
    </style>
</head>
<body class="register-page">
    <div class="cyber-grid"></div>
    <div class="animated-lines"></div>
    <div class="particles"></div>
    <div class="auth-container">
        <div class="auth-header">
            <h1 class="section-title">
                <i class="fas fa-user-plus"></i>
                Регистрация
            </h1>
            <p class="auth-subtitle">Создайте аккаунт для доступа к D3X Project</p>
        </div>

        <?php if (!$registration_enabled): ?>
            <div class="registration-status">
                <i class="fas fa-lock"></i>
                Регистрация временно отключена администратором
            </div>
        <?php endif; ?>

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

        <form method="POST" class="auth-form" <?= !$registration_enabled ? 'style="display:none;"' : '' ?>>
            <div class="form-group">
                <input 
                    id="username" 
                    name="username" 
                    type="text" 
                    required 
                    autocomplete="username" 
                    maxlength="30" 
                    minlength="3"
                    pattern="^[a-zA-Z0-9_]+$" 
                    title="Только латинские буквы, цифры и подчеркивания" 
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
                    autocomplete="new-password" 
                    minlength="6" 
                    placeholder=" "
                />
                <i class="fas fa-lock"></i>
                <label for="password">Пароль</label>
                <div class="password-strength">
                    <div class="password-strength-bar"></div>
                </div>
            </div>
            
            <div class="form-group">
                <input 
                    id="password_confirm" 
                    name="password_confirm" 
                    type="password" 
                    required 
                    autocomplete="new-password" 
                    minlength="6" 
                    placeholder=" "
                />
                <i class="fas fa-lock"></i>
                <label for="password_confirm">Подтвердите пароль</label>
            </div>

            <?php if ($invite_keys_required): ?>
            <div class="form-group invite-code-group" id="invite-code-group">
                <input 
                    id="invite_code" 
                    name="invite_code" 
                    type="text" 
                    required
                    placeholder=" "
                    value="<?= htmlspecialchars($_POST['invite_code'] ?? '') ?>"
                />
                <i class="fas fa-key"></i>
                <label for="invite_code">Инвайт-код</label>
            </div>
            <?php endif; ?>
            
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-rocket"></i>
                Создать аккаунт
            </button>
        </form>

        <div class="auth-link">
            <p>Уже есть аккаунт?</p>
            <a href="login.php">
                <i class="fas fa-sign-in-alt"></i>
                Войти в систему
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

        // Check if invite keys are required from server
        const inviteKeysRequired = <?= json_encode($invite_keys_required) ?>;
        
        // Password strength checker
        const passwordInput = document.getElementById('password');
        const strengthIndicator = document.querySelector('.password-strength');
        const strengthBar = document.querySelector('.password-strength-bar');

        passwordInput.addEventListener('input', function() {
            const password = this.value;
            const strength = calculatePasswordStrength(password);
            
            if (password.length > 0) {
                strengthIndicator.classList.add('active');
                
                strengthBar.className = 'password-strength-bar';
                if (strength < 3) {
                    strengthBar.classList.add('weak');
                } else if (strength < 5) {
                    strengthBar.classList.add('medium');
                } else {
                    strengthBar.classList.add('strong');
                }
            } else {
                strengthIndicator.classList.remove('active');
            }
        });

        function calculatePasswordStrength(password) {
            let strength = 0;
            
            // Length check
            if (password.length >= 8) strength++;
            if (password.length >= 12) strength++;
            
            // Character variety checks
            if (/[a-z]/.test(password)) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            return strength;
        }

        // Form validation
        const form = document.querySelector('.auth-form');
        const confirmPasswordInput = document.getElementById('password_confirm');

        confirmPasswordInput.addEventListener('input', function() {
            const password = passwordInput.value;
            const confirmPassword = this.value;
            
            if (confirmPassword && password !== confirmPassword) {
                this.setCustomValidity('Пароли не совпадают');
            } else {
                this.setCustomValidity('');
            }
        });

        // Username validation
        const usernameInput = document.getElementById('username');
        usernameInput.addEventListener('input', function() {
            const username = this.value;
            const isValid = /^[a-zA-Z0-9_]+$/.test(username);
            
            if (username && !isValid) {
                this.setCustomValidity('Только латинские буквы, цифры и подчеркивания');
            } else if (username.length < 3) {
                this.setCustomValidity('Минимум 3 символа');
            } else {
                this.setCustomValidity('');
            }
        });

        // Enhanced form submission
        form.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('.btn-submit');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Создание аккаунта...';
        });
    </script>
</body>
</html>