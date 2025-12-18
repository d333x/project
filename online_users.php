<?php
session_start();
require __DIR__ . '/config.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Онлайн пользователи - D3x Messenger</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <style>
        .online-users-container {
            max-width: 800px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .online-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .online-count {
            background: #28a745;
            color: white;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 14px;
            font-weight: bold;
        }
        
        .user-list {
            display: grid;
            gap: 15px;
        }
        
        .user-item {
            display: flex;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #28a745;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .user-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        
        .user-item.away {
            border-left-color: #ffc107;
        }
        
        .user-item.offline {
            border-left-color: #6c757d;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            margin-right: 15px;
            object-fit: cover;
            border: 3px solid #28a745;
        }
        
        .user-avatar.away {
            border-color: #ffc107;
        }
        
        .user-avatar.offline {
            border-color: #6c757d;
        }
        
        .user-info {
            flex: 1;
        }
        
        .user-name {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 5px;
            color: #333;
        }
        
        .user-status {
            font-size: 12px;
            color: #666;
            display: flex;
            align-items: center;
        }
        
        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 5px;
            background: #28a745;
        }
        
        .status-indicator.away {
            background: #ffc107;
        }
        
        .status-indicator.offline {
            background: #6c757d;
        }
        
        .user-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-message {
            background: #007bff;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.3s;
        }
        
        .btn-message:hover {
            background: #0056b3;
        }
        
        .role-badge {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 3px;
            margin-left: 5px;
        }
        
        .role-admin {
            background: #dc3545;
            color: white;
        }
        
        .role-moder {
            background: #fd7e14;
            color: white;
        }
        
        .role-user {
            background: #6c757d;
            color: white;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .refresh-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .refresh-btn:hover {
            background: #218838;
        }
        
        @media (max-width: 768px) {
            .online-users-container {
                margin: 10px;
                padding: 15px;
            }
            
            .user-item {
                padding: 10px;
            }
            
            .user-avatar {
                width: 40px;
                height: 40px;
            }
            
            .online-header {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="online-users-container">
        <div class="online-header">
            <h2>Онлайн пользователи</h2>
            <div>
                <span class="online-count" id="onlineCount">Загрузка...</span>
                <button class="refresh-btn" onclick="loadOnlineUsers()">Обновить</button>
            </div>
        </div>
        
        <div id="errorMessage" class="error" style="display: none;"></div>
        
        <div id="userList" class="user-list">
            <div class="loading">Загрузка пользователей...</div>
        </div>
    </div>

    <script>
        let refreshInterval;
        
        function loadOnlineUsers() {
            fetch('api/get_online.php')
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        displayUsers(data.users);
                        updateOnlineCount(data.total_online);
                        hideError();
                    } else {
                        showError(data.message || 'Ошибка загрузки пользователей');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showError('Ошибка соединения с сервером');
                });
        }
        
        function displayUsers(users) {
            const userList = document.getElementById('userList');
            
            if (users.length === 0) {
                userList.innerHTML = '<div class="loading">Нет онлайн пользователей</div>';
                return;
            }
            
            const userItems = users.map(user => {
                const roleText = getRoleText(user.role);
                const roleClass = getRoleClass(user.role);
                const statusText = getStatusText(user.status);
                const avatarSrc = user.avatar || 'assets/images/default-avatar.png';
                
                return `
                    <div class="user-item ${user.status}" onclick="openChat(${user.id})">
                        <img src="${avatarSrc}" alt="${user.username}" class="user-avatar ${user.status}" 
                             onerror="this.src='assets/images/default-avatar.png'">
                        <div class="user-info">
                            <div class="user-name">
                                ${user.username}
                                <span class="role-badge ${roleClass}">${roleText}</span>
                            </div>
                            <div class="user-status">
                                <span class="status-indicator ${user.status}"></span>
                                ${statusText}
                            </div>
                        </div>
                        <div class="user-actions">
                            <button class="btn-message" onclick="event.stopPropagation(); openChat(${user.id})">
                                Написать
                            </button>
                        </div>
                    </div>
                `;
            }).join('');
            
            userList.innerHTML = userItems;
        }
        
        function updateOnlineCount(count) {
            document.getElementById('onlineCount').textContent = `Онлайн: ${count}`;
        }
        
        function getRoleText(role) {
            switch(role) {
                case 3: return 'Админ';
                case 2: return 'Модер';
                default: return 'Пользователь';
            }
        }
        
        function getRoleClass(role) {
            switch(role) {
                case 3: return 'role-admin';
                case 2: return 'role-moder';
                default: return 'role-user';
            }
        }
        
        function getStatusText(status) {
            switch(status) {
                case 'online': return 'В сети';
                case 'away': return 'Отошел';
                default: return 'Не в сети';
            }
        }
        
        function openChat(userId) {
            window.location.href = `chat.php?user=${userId}`;
        }
        
        function showError(message) {
            const errorDiv = document.getElementById('errorMessage');
            errorDiv.textContent = message;
            errorDiv.style.display = 'block';
        }
        
        function hideError() {
            document.getElementById('errorMessage').style.display = 'none';
        }
        
        // Загружаем пользователей при загрузке страницы
        document.addEventListener('DOMContentLoaded', function() {
            loadOnlineUsers();
            
            // Автообновление каждые 30 секунд
            refreshInterval = setInterval(loadOnlineUsers, 30000);
        });
        
        // Очищаем интервал при закрытии страницы
        window.addEventListener('beforeunload', function() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        });
    </script>
</body>
</html>