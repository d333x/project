<?php
// invite_indicator.php - Invite Code Status Indicator Component

function getInviteCodeStatus($pdo) {
    try {
        // Check if registration_keys_required setting exists in settings table
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE setting_name = 'registration_keys_required' LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return (int)$result['value'];
        }
        
        // Fallback: check if there's a direct column in a config table
        $stmt = $pdo->prepare("SELECT registration_keys_required FROM config LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return (int)$result['registration_keys_required'];
        }
        
        return 0; // Default to not required
    } catch (PDOException $e) {
        error_log("Error getting invite code status: " . $e->getMessage());
        return 0;
    }
}

function renderInviteCodeIndicator($pdo) {
    $isRequired = getInviteCodeStatus($pdo);
    $statusText = $isRequired ? 'Required' : 'Not Required';
    $statusClass = $isRequired ? 'required' : 'not-required';
    $iconClass = $isRequired ? 'fa-key' : 'fa-key-slash';
    
    echo '<div class="invite-code-indicator ' . $statusClass . '" id="inviteCodeIndicator">';
    echo '<i class="fas ' . $iconClass . '" id="inviteIcon"></i>';
    echo '<span class="status-text" id="statusText">' . $statusText . '</span>';
    echo '</div>';
}
?>

<style>
.invite-code-indicator {
    display: inline-flex;
    align-items: center;
    padding: 8px 12px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.3s ease;
    margin: 5px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.invite-code-indicator.required {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
    border: 2px solid #ff5252;
}

.invite-code-indicator.not-required {
    background: linear-gradient(135deg, #51cf66, #40c057);
    color: white;
    border: 2px solid #4caf50;
}

.invite-code-indicator i {
    margin-right: 6px;
    font-size: 16px;
}

.invite-code-indicator:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.status-text {
    font-weight: 600;
}

/* Animation for status change */
.invite-code-indicator.changing {
    animation: statusChange 0.5s ease;
}

@keyframes statusChange {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}
</style>

<script>
function updateInviteCodeIndicator(isRequired) {
    const indicator = document.getElementById('inviteCodeIndicator');
    const icon = document.getElementById('inviteIcon');
    const statusText = document.getElementById('statusText');
    
    // Add animation class
    indicator.classList.add('changing');
    
    setTimeout(() => {
        if (isRequired) {
            indicator.className = 'invite-code-indicator required changing';
            icon.className = 'fas fa-key';
            statusText.textContent = 'Required';
        } else {
            indicator.className = 'invite-code-indicator not-required changing';
            icon.className = 'fas fa-key-slash';
            statusText.textContent = 'Not Required';
        }
        
        // Remove animation class after animation completes
        setTimeout(() => {
            indicator.classList.remove('changing');
        }, 500);
    }, 100);
}
</script>