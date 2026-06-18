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

// 4. Mobile Navbar Toggle
document.addEventListener('DOMContentLoaded', () => {
    const navToggle = document.getElementById('navbarToggle');
    const navLinks = document.getElementById('navLinks');
    if (!navToggle || !navLinks) return;

    const closeNav = () => {
        navLinks.classList.remove('open');
        navToggle.setAttribute('aria-expanded', 'false');
    };

    navToggle.addEventListener('click', () => {
        const isOpen = navLinks.classList.toggle('open');
        navToggle.setAttribute('aria-expanded', String(isOpen));
    });

    navLinks.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', closeNav);
    });

    document.addEventListener('click', (e) => {
        if (!navLinks.contains(e.target) && !navToggle.contains(e.target)) {
            closeNav();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeNav();
    });
});

// 5. Admin Sidebar Drawer Toggle (tap + swipe)
document.addEventListener('DOMContentLoaded', () => {
    const sidebarToggle = document.getElementById('adminSidebarToggle');
    const sidebarPanel = document.getElementById('adminSidebarPanel');
    const sidebarBackdrop = document.getElementById('adminSidebarBackdrop');
    if (!sidebarToggle || !sidebarPanel) return;

    const EDGE_ZONE = 24; // px from the left edge that can start an "open" swipe
    const OPEN_THRESHOLD = 0.35; // fraction of drawer width needed to flip state on release

    const isOpen = () => sidebarPanel.classList.contains('open');

    const setOpen = (open) => {
        sidebarPanel.classList.toggle('open', open);
        sidebarBackdrop?.classList.toggle('open', open);
        sidebarToggle.classList.toggle('open', open);
        sidebarToggle.setAttribute('aria-expanded', String(open));
    };

    const closeSidebar = () => setOpen(false);

    sidebarToggle.addEventListener('click', () => setOpen(!isOpen()));

    sidebarPanel.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', closeSidebar);
    });

    sidebarBackdrop?.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeSidebar();
    });

    // --- Swipe-to-open/close, dragging the drawer along with the finger ---
    let dragging = false;
    let committed = false; // true once a touch has been confirmed as a horizontal drawer drag
    let startX = 0;
    let startY = 0;
    let width = 0;
    let lastTranslate = 0;

    const dragActive = () => getComputedStyle(sidebarToggle).display !== 'none';

    const onTouchStart = (e) => {
        if (!dragActive()) return;
        const open = isOpen();
        const x = e.touches[0].clientX;
        if (!open && x > EDGE_ZONE) return;
        dragging = true;
        committed = false;
        startX = x;
        startY = e.touches[0].clientY;
        width = sidebarPanel.getBoundingClientRect().width;
        lastTranslate = open ? 0 : -width;
    };

    const onTouchMove = (e) => {
        if (!dragging) return;
        const x = e.touches[0].clientX;
        const y = e.touches[0].clientY;
        const dx = x - startX;
        const dy = y - startY;

        if (!committed) {
            if (Math.abs(dx) < 10 && Math.abs(dy) < 10) return;
            if (Math.abs(dy) > Math.abs(dx)) {
                dragging = false; // vertical scroll intent, let it through
                return;
            }
            committed = true;
            sidebarPanel.style.transition = 'none';
            if (sidebarBackdrop) sidebarBackdrop.style.transition = 'none';
        }

        e.preventDefault();
        const base = isOpen() ? 0 : -width;
        lastTranslate = Math.max(-width, Math.min(0, base + dx));
        sidebarPanel.style.transform = `translateX(${lastTranslate}px)`;
        if (sidebarBackdrop) sidebarBackdrop.style.opacity = String(((width + lastTranslate) / width) * 0.55);
    };

    const endDrag = () => {
        if (!dragging) return;
        dragging = false;
        if (committed) {
            const wasOpen = isOpen();
            const openFraction = (width + lastTranslate) / width;
            const newOpen = wasOpen ? openFraction > (1 - OPEN_THRESHOLD) : openFraction > OPEN_THRESHOLD;
            sidebarPanel.style.transition = '';
            sidebarPanel.style.transform = '';
            if (sidebarBackdrop) {
                sidebarBackdrop.style.transition = '';
                sidebarBackdrop.style.opacity = '';
            }
            setOpen(newOpen);
        }
    };

    document.addEventListener('touchstart', onTouchStart, { passive: true });
    document.addEventListener('touchmove', onTouchMove, { passive: false });
    document.addEventListener('touchend', endDrag);
    document.addEventListener('touchcancel', endDrag);
});

/**
 * Toggle visibility of password input fields
 * @param {string} fieldId - ID of the password input element
 */
function togglePasswordVisibility(fieldId) {
    const field = document.getElementById(fieldId);
    if (!field) return;

    const wrapper = field.closest('.password-toggle-wrapper');
    const toggleIcon = wrapper ? wrapper.querySelector('.password-toggle-icon') : null;

    if (field.type === 'password') {
        field.type = 'text';
        if (toggleIcon) toggleIcon.textContent = '🙈';
    } else {
        field.type = 'password';
        if (toggleIcon) toggleIcon.textContent = '👁️';
    }
}
