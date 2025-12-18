<?php
require 'config.php';

$avatar_path = $_SESSION['avatar'] ?? DEFAULT_AVATAR;

// Уничтожаем все данные сессии
$_SESSION = [];

// Удаляем куки сессии
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Уничтожаем сессию
session_destroy();

// Перенаправляем на вход
header("Location: login.php");
exit;
