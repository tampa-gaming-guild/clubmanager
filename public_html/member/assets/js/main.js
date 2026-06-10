/**
 * TGG Club Membership System - Global Javascript
 * Provides general micro-interactions, form validation helpers, and client-side notifications.
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Alert Auto-Disposal
    const alerts = document.querySelectorAll('.alert:not(.terminal-alert)');
    alerts.forEach(alert => {
        // Add close button dynamically
        const closeBtn = document.createElement('span');
        closeBtn.innerHTML = ' &times;';
        closeBtn.style.cursor = 'pointer';
        closeBtn.style.float = 'right';
        closeBtn.style.fontWeight = 'bold';
        closeBtn.style.fontSize = '1.2rem';
        closeBtn.style.lineHeight = '1';
        closeBtn.onclick = () => {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.3s ease';
            setTimeout(() => alert.remove(), 300);
        };
        alert.prepend(closeBtn);

        // Auto fade out after 8 seconds (except error messages)
        if (!alert.classList.contains('alert-danger')) {
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s ease';
                setTimeout(() => alert.remove(), 500);
            }, 8000);
        }
    });

    // 2. Button Press Micro-Animations
    const buttons = document.querySelectorAll('.btn');
    buttons.forEach(btn => {
        btn.addEventListener('mousedown', () => {
            btn.style.transform = 'scale(0.96)';
        });
        btn.addEventListener('mouseup', () => {
            btn.style.transform = '';
        });
    });

    // 3. Client-Side Password Strength Check (for Join form)
    const passwordInput = document.getElementById('password');
    if (passwordInput) {
        passwordInput.addEventListener('input', () => {
            const password = passwordInput.value;
            if (password.length > 0 && password.length < 8) {
                passwordInput.style.borderColor = 'hsl(350, 89%, 60%)';
            } else if (password.length >= 8) {
                passwordInput.style.borderColor = 'hsl(142, 72%, 45%)';
            } else {
                passwordInput.style.borderColor = '';
            }
        });
    }
});
