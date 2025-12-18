document.addEventListener('DOMContentLoaded', () => {
    const forms = document.querySelectorAll('.auth-form');

    forms.forEach(form => {
        const inputs = form.querySelectorAll('input:not([type="hidden"])');
        inputs.forEach(input => {
            // Add focus/blur effects for input fields if needed, complementing existing CSS
            input.addEventListener('focus', () => {
                input.style.borderColor = 'var(--accent-cyan)';
                input.style.boxShadow = '0 0 0 3px rgba(0, 217, 255, 0.1)';
            });
            input.addEventListener('blur', () => {
                input.style.borderColor = 'var(--border-color)';
                input.style.boxShadow = 'inset 0 2px 4px rgba(0, 0, 0, 0.1)';
            });

            // Enhance key input for registration (if an invite_code input exists)
            if (input.id === 'invite_code' || input.name === 'invite_code') {
                input.style.letterSpacing = '3px';
                input.style.textTransform = 'uppercase';
                input.setAttribute('placeholder', 'Введите ИНВАЙТ-КОД');
            }
        });

        // Add subtle click animation to submit buttons
        const submitButton = form.querySelector('.btn-submit');
        if (submitButton) {
            submitButton.addEventListener('mousedown', () => {
                submitButton.style.transform = 'translateY(1px)';
                submitButton.style.boxShadow = '0 4px 15px var(--shadow-color)';
            });
            submitButton.addEventListener('mouseup', () => {
                submitButton.style.transform = 'translateY(-2px)';
                submitButton.style.boxShadow = '0 12px 35px var(--shadow-strong)';
            });
            submitButton.addEventListener('mouseleave', () => {
                if (!submitButton.disabled) {
                    submitButton.style.transform = 'translateY(0)';
                    submitButton.style.boxShadow = '0 8px 25px rgba(0, 217, 255, 0.3)';
                }
            });
        }

        // Improve message container visibility on new messages (if messageContainer exists)
        const messageContainer = document.getElementById('message-container');
        if (messageContainer) {
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    if (mutation.addedNodes.length > 0) {
                        // A new message has been added, ensure it's visible and maybe animate it
                        messageContainer.style.opacity = '0';
                        messageContainer.style.transform = 'translateY(-10px)';
                        setTimeout(() => {
                            messageContainer.style.transition = 'opacity 0.3s ease-out, transform 0.3s ease-out';
                            messageContainer.style.opacity = '1';
                            messageContainer.style.transform = 'translateY(0)';
                        }, 50);
                    }
                });
            });
            observer.observe(messageContainer, { childList: true });
        }
    });
});

// Sidebar swipe functionality for mobile
document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.querySelector('.sidebar');
    const chatLayout = document.querySelector('.chat-layout');
    const mobileHeader = document.querySelector('.mobile-header');

    let touchStartX = 0;
    let touchStartY = 0;
    let touchEndX = 0;

    if (!sidebar || !chatLayout || !mobileHeader) return; // Exit if elements not found

    const toggleSidebar = () => {
        sidebar.classList.toggle('active');
    };

    mobileHeader.querySelector('.menu-toggle').addEventListener('click', toggleSidebar);

    // Swipe to open sidebar
    chatLayout.addEventListener('touchstart', (e) => {
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
    });

    chatLayout.addEventListener('touchmove', (e) => {
        // Only track horizontal movement for swipe
        touchEndX = e.touches[0].clientX;
    });

    chatLayout.addEventListener('touchend', () => {
        const touchDiffX = touchEndX - touchStartX;
        const touchDiffY = Math.abs(e.changedTouches[0].clientY - touchStartY);

        // Consider it a horizontal swipe if horizontal movement is significantly greater than vertical
        if (Math.abs(touchDiffX) > 50 && touchDiffY < 50) {
            if (touchDiffX > 0 && touchStartX < 50 && !sidebar.classList.contains('active')) {
                // Swipe right from left edge to open sidebar
                toggleSidebar();
            } else if (touchDiffX < 0 && sidebar.classList.contains('active')) {
                // Swipe left on sidebar to close it
                toggleSidebar();
            }
        }
        // Reset touch coordinates
        touchStartX = 0;
        touchEndX = 0;
    });

    // Dynamic textarea height adjustment
    const messageInput = document.getElementById('message-input');
    if (messageInput) {
        messageInput.addEventListener('input', () => {
            messageInput.style.height = 'auto';
            messageInput.style.height = messageInput.scrollHeight + 'px';
            // Also ensure scroll to bottom when input height changes
            scrollToBottom(); 
        });

        // Scroll to bottom on focus (keyboard appearance)
        messageInput.addEventListener('focus', () => {
            scrollToBottom();
        });
    }

    // Helper function to scroll chat messages to bottom (assuming chatMessagesDiv is global or passed)
    function scrollToBottom() {
        const chatMessagesDiv = document.getElementById('chat-messages');
        if (chatMessagesDiv) {
            chatMessagesDiv.scrollTop = chatMessagesDiv.scrollHeight;
        }
    }
});
