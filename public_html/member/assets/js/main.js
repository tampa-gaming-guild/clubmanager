/**
 * TGG Club Membership System - Global Javascript
 * Provides general micro-interactions, form validation helpers, and client-side notifications.
 */

// Flash messages arrive via PRG redirects' query strings (?success=/&error=);
// scrub them from the URL after render so a refresh doesn't re-show them.
if (window.location.search.includes('success=') || window.location.search.includes('error=')) {
    const cleanUrl = new URL(window.location);
    cleanUrl.searchParams.delete('success');
    cleanUrl.searchParams.delete('error');
    history.replaceState(null, '', cleanUrl);
}

document.addEventListener('DOMContentLoaded', () => {
    // 1. Alert Auto-Disposal — move page-level alerts into a fixed toast container
    const alerts = document.querySelectorAll('.alert:not(.terminal-alert)');
    if (alerts.length) {
        let toastContainer = document.getElementById('toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toast-container';
            document.body.appendChild(toastContainer);
        }

        function alignToast() {
            const main = document.querySelector('.main-content');
            if (!main) return;
            const rect = main.getBoundingClientRect();
            toastContainer.style.left = rect.left + 'px';
            toastContainer.style.width = rect.width + 'px';
        }
        alignToast();
        window.addEventListener('resize', alignToast);

        alerts.forEach(alert => {
            toastContainer.appendChild(alert);

            const closeBtn = document.createElement('span');
            closeBtn.innerHTML = '&times;';
            closeBtn.className = 'toast-close-btn';
            const dismiss = () => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-8px)';
                alert.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                setTimeout(() => alert.remove(), 300);
            };
            closeBtn.onclick = dismiss;
            alert.prepend(closeBtn);

            // Auto fade out after 8 seconds (except error messages)
            if (!alert.classList.contains('alert-danger')) {
                setTimeout(dismiss, 8000);
            }
        });
    }

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
 * 6. Themed confirmation modal — replacement for native confirm(), which the
 * browser may suppress ("prevent this page from creating additional dialogs"),
 * silently blocking the confirmed action.
 * @param {string} message - Confirmation text; newlines render as line breaks.
 * @param {{confirmText?: string, cancelText?: string, alertOnly?: boolean}} [options]
 *        alertOnly renders a single OK button (an alert() replacement).
 * @returns {Promise<boolean>} true on Confirm, false on Cancel/Escape/overlay click.
 */
function confirmDialog(message, options = {}) {
    const { alertOnly = false, confirmText = alertOnly ? 'OK' : 'Confirm', cancelText = 'Cancel' } = options;

    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'confirm-modal-overlay';
        overlay.innerHTML = `
            <div class="confirm-modal" role="dialog" aria-modal="true" aria-label="Confirmation">
                <p class="confirm-modal-message"></p>
                <div class="confirm-modal-actions">
                    <button type="button" class="btn btn-secondary confirm-modal-cancel"></button>
                    <button type="button" class="btn btn-danger confirm-modal-confirm"></button>
                </div>
            </div>`;
        const cancelBtn = overlay.querySelector('.confirm-modal-cancel');
        const confirmBtn = overlay.querySelector('.confirm-modal-confirm');
        overlay.querySelector('.confirm-modal-message').textContent = message;
        cancelBtn.textContent = cancelText;
        confirmBtn.textContent = confirmText;
        if (alertOnly) {
            cancelBtn.remove();
            confirmBtn.classList.replace('btn-danger', 'btn-primary');
        }

        const close = (result) => {
            document.removeEventListener('keydown', onKeydown);
            overlay.remove();
            resolve(result);
        };
        const onKeydown = (e) => {
            if (e.key === 'Escape') close(false);
        };

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) close(false);
        });
        cancelBtn.addEventListener('click', () => close(false));
        confirmBtn.addEventListener('click', () => close(true));
        document.addEventListener('keydown', onKeydown);

        document.body.appendChild(overlay);
        // Cancel is the safe default focus; in alert mode there's only OK.
        (alertOnly ? confirmBtn : cancelBtn).focus();
    });
}

// Any form with data-confirm="message" gets the modal before submitting.
// requestSubmit(submitter) keeps the clicked button's name/value in the POST
// (many forms carry their action in the submit button's name).
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || form.dataset.confirmed === '1') {
        return;
    }
    e.preventDefault();
    const submitter = e.submitter;
    confirmDialog(form.dataset.confirm).then((ok) => {
        if (!ok) return;
        form.dataset.confirmed = '1';
        form.requestSubmit(submitter);
    });
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
