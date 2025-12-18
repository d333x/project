<?php
// invite_icon.php - Dynamic invite code icon component
require_once 'config.php';

function getInviteIconStatus() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT registration_keys_required, isRegistrationEnabled FROM settings LIMIT 1");
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'keys_required' => $settings['registration_keys_required'] ?? 0,
            'registration_enabled' => $settings['isRegistrationEnabled'] ?? 1
        ];
    } catch (PDOException $e) {
        error_log("Database error in getInviteIconStatus: " . $e->getMessage());
        return ['keys_required' => 0, 'registration_enabled' => 1];
    }
}

function renderInviteIcon($settings = null) {
    if ($settings === null) {
        $settings = getInviteIconStatus();
    }
    
    $keysRequired = $settings['keys_required'];
    $registrationEnabled = $settings['registration_enabled'];
    
    // Determine icon state
    $iconClass = 'invite-icon';
    $iconTitle = '';
    $iconColor = '#6c757d'; // default gray
    
    if (!$registrationEnabled) {
        $iconClass .= ' disabled';
        $iconTitle = 'Регистрация отключена';
        $iconColor = '#ec4899'; // pink
    } elseif ($keysRequired) {
        $iconClass .= ' active';
        $iconTitle = 'Требуется инвайт-код';
        $iconColor = '#28a745'; // green
    } else {
        $iconClass .= ' inactive';
        $iconTitle = 'Свободная регистрация';
        $iconColor = '#ffc107'; // yellow
    }
    
    return [
        'class' => $iconClass,
        'title' => $iconTitle,
        'color' => $iconColor,
        'keys_required' => $keysRequired,
        'registration_enabled' => $registrationEnabled
    ];
}

// AJAX endpoint for getting current status
if (isset($_GET['ajax']) && $_GET['ajax'] === 'status') {
    header('Content-Type: application/json');
    echo json_encode(renderInviteIcon());
    exit;
}
?>

<style>
.invite-icon-container {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 8px;
    background: #f8f9fa;
    border: 2px solid transparent;
    transition: all 0.3s ease;
    cursor: pointer;
    user-select: none;
}

.invite-icon {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: bold;
    color: white;
    transition: all 0.3s ease;
    position: relative;
}

.invite-icon::before {
    content: "🔑";
    font-size: 14px;
}

.invite-icon.active {
    background: #28a745;
    box-shadow: 0 0 10px rgba(40, 167, 69, 0.3);
    animation: pulse-green 2s infinite;
}

.invite-icon.inactive {
    background: #ffc107;
    box-shadow: 0 0 10px rgba(255, 193, 7, 0.3);
}

.invite-icon.inactive::before {
    content: "🔓";
}

.invite-icon.disabled {
    background: #ec4899;
    box-shadow: 0 0 10px rgba(236, 72, 153, 0.3);
}

.invite-icon.disabled::before {
    content: "🚫";
}

.invite-status-text {
    font-size: 14px;
    font-weight: 500;
    color: #495057;
}

.invite-icon-container:hover {
    background: #e9ecef;
    border-color: #dee2e6;
    transform: translateY(-1px);
}

@keyframes pulse-green {
    0% { box-shadow: 0 0 10px rgba(40, 167, 69, 0.3); }
    50% { box-shadow: 0 0 20px rgba(40, 167, 69, 0.6); }
    100% { box-shadow: 0 0 10px rgba(40, 167, 69, 0.3); }
}

.admin-toggle-container {
    display: flex;
    gap: 15px;
    align-items: center;
    margin: 20px 0;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 10px;
    border: 1px solid #dee2e6;
}

.toggle-switch {
    position: relative;
    display: inline-block;
    width: 60px;
    height: 34px;
}

.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 34px;
}

.slider:before {
    position: absolute;
    content: "";
    height: 26px;
    width: 26px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

input:checked + .slider {
    background-color: #28a745;
}

input:checked + .slider:before {
    transform: translateX(26px);
}

.toggle-label {
    font-weight: 500;
    color: #495057;
    margin-left: 10px;
}
</style>

<script>
function updateInviteIcon() {
    fetch('invite_icon.php?ajax=status')
        .then(response => response.json())
        .then(data => {
            const iconElement = document.querySelector('.invite-icon');
            const textElement = document.querySelector('.invite-status-text');
            
            if (iconElement && textElement) {
                iconElement.className = 'invite-icon ' + data.class.split(' ').slice(1).join(' ');
                iconElement.style.backgroundColor = data.color;
                iconElement.title = data.title;
                textElement.textContent = data.title;
            }
        })
        .catch(error => console.error('Error updating invite icon:', error));
}

// Update icon every 30 seconds
setInterval(updateInviteIcon, 30000);

// Update icon when page loads
document.addEventListener('DOMContentLoaded', updateInviteIcon);
</script>