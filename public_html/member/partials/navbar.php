<?php
/**
 * Shared top navbar.
 * Caller sets these variables before including this file:
 *   $navActive       string  Key of the link to mark active: dashboard|calendar|volunteers|checkin|admin|login (default '')
 *   $navAdminArea    bool    True when included from a page inside admin/ (default false)
 *   $navKiosk        bool    True for the unauthenticated check-in kiosk nav used by checkin.php (default false)
 *   $navGuestCheckin bool    Whether the logged-out nav includes a Check-In link (default true)
 */
$navActive = $navActive ?? '';
$navAdminArea = $navAdminArea ?? false;
$navKiosk = $navKiosk ?? false;
$navGuestCheckin = $navGuestCheckin ?? true;
$navPrefix = $navAdminArea ? '../' : '';
$navAuthed = $navAdminArea || \App\Auth::check();
?>
<header class="navbar">
    <div class="logo">TGG Members</div>
    <button type="button" class="navbar-toggle" id="navbarToggle" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="navLinks">
        <span class="navbar-toggle-bar"></span>
        <span class="navbar-toggle-bar"></span>
        <span class="navbar-toggle-bar"></span>
    </button>
    <?php if (!empty($_SESSION['user']['permissions'] ?? [])): ?>
        <form action="<?php echo rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/admin/dashboard.php'; ?>" method="GET" class="navbar-search-form" style="margin: 0 20px; flex-grow: 1; max-width: 380px; position: relative;">
            <input type="text" id="navbar-search-input" name="search" placeholder="Search members by name..."
                value="<?php echo isset($_GET['search']) ? e($_GET['search']) : ''; ?>"
                autocomplete="off"
                style="width: 100%; padding: 8px 15px 8px 35px; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 20px; color: #fff; font-size: 0.85rem; outline: none; transition: all 0.2s ease;">
            <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: rgba(255, 255, 255, 0.4); font-size: 0.9rem;">🔍</span>
            <div id="navbar-search-dropdown" class="navbar-search-dropdown" style="display: none;"></div>
        </form>
        <script>
        (function () {
            const input = document.getElementById('navbar-search-input');
            const dropdown = document.getElementById('navbar-search-dropdown');
            const searchUrl = <?php echo json_encode(($navAdminArea ? '' : 'admin/') . 'member-search.php'); ?>;
            const profileUrl = <?php echo json_encode(($navAdminArea ? '../' : '') . 'profile.php'); ?>;
            let timer = null;
            let results = [];
            let activeIndex = -1;

            function escHtml(s) {
                return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            function close() {
                dropdown.style.display = 'none';
                dropdown.innerHTML = '';
                results = [];
                activeIndex = -1;
            }

            function setActive(index) {
                const items = dropdown.querySelectorAll('.navbar-search-result-item');
                items.forEach(function (el, i) {
                    el.classList.toggle('navbar-search-result-active', i === index);
                });
                activeIndex = index;
            }

            function open(members) {
                results = members;
                activeIndex = -1;
                dropdown.innerHTML = '';
                members.forEach(function (m, i) {
                    const item = document.createElement('div');
                    item.className = 'navbar-search-result-item';
                    item.innerHTML = '<span class="navbar-search-result-name">' + escHtml(m.display_name) + '</span>'
                        + '<span class="navbar-search-result-email">' + escHtml(m.email) + '</span>';
                    item.addEventListener('mousemove', function () { setActive(i); });
                    item.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        window.location.href = profileUrl + '?id=' + encodeURIComponent(m.id);
                    });
                    dropdown.appendChild(item);
                });
                dropdown.style.display = members.length ? 'block' : 'none';
            }

            input.addEventListener('input', function () {
                clearTimeout(timer);
                const q = input.value.trim();
                if (q.length < 3) { close(); return; }
                timer = setTimeout(function () {
                    fetch(searchUrl + '?q=' + encodeURIComponent(q))
                        .then(function (r) { return r.json(); })
                        .then(open)
                        .catch(close);
                }, 300);
            });

            input.addEventListener('keydown', function (e) {
                if (!results.length) return;
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    setActive(activeIndex < results.length - 1 ? activeIndex + 1 : 0);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    setActive(activeIndex > 0 ? activeIndex - 1 : results.length - 1);
                } else if (e.key === 'Enter' && activeIndex >= 0) {
                    e.preventDefault();
                    window.location.href = profileUrl + '?id=' + encodeURIComponent(results[activeIndex].id);
                }
            });

            input.addEventListener('blur', function () { close(); input.value = ''; });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { close(); }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === '/' && document.activeElement !== input &&
                    !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) {
                    e.preventDefault();
                    input.focus();
                    input.select();
                }
            });
        })();
        </script>
    <?php endif; ?>
    <nav class="nav-links" id="navLinks">
        <?php if ($navKiosk): ?>
            <?php if ($navAuthed): ?>
                <a href="index.php" class="<?php echo $navActive === 'dashboard' ? 'active' : ''; ?>">Dashboard</a>
            <?php else: ?>
                <a href="index.php" class="<?php echo $navActive === 'login' ? 'active' : ''; ?>">Login</a>
                <a href="join.php" class="<?php echo $navActive === 'join' ? 'active' : ''; ?>">Join / Renew</a>
            <?php endif; ?>
            <a href="calendar.php" class="<?php echo $navActive === 'calendar' ? 'active' : ''; ?>">Calendar</a>
            <a href="checkin.php" class="<?php echo $navActive === 'checkin' ? 'active' : ''; ?>">Check-In</a>
        <?php elseif ($navAuthed): ?>
            <a href="<?php echo $navPrefix; ?>index.php" class="<?php echo $navActive === 'dashboard' ? 'active' : ''; ?>">Dashboard</a>
            <a href="<?php echo $navPrefix; ?>calendar.php" class="<?php echo $navActive === 'calendar' ? 'active' : ''; ?>">Calendar</a>
            <a href="<?php echo $navPrefix; ?>volunteers.php" class="<?php echo $navActive === 'volunteers' ? 'active' : ''; ?>">Volunteers</a>
            <a href="<?php echo $navPrefix; ?>checkin.php" class="<?php echo $navActive === 'checkin' ? 'active' : ''; ?>">Check-In</a>
            <?php if ($navAdminArea || !empty($_SESSION['user']['permissions'] ?? [])): ?>
                <a href="<?php echo $navAdminArea ? 'dashboard.php' : 'admin/dashboard.php'; ?>" class="<?php echo $navActive === 'admin' ? 'active' : ''; ?>">Admin</a>
            <?php endif; ?>
            <a href="<?php echo $navPrefix; ?>index.php?action=logout&amp;csrf_token=<?php echo e(get_csrf_token()); ?>" class="btn-logout">Logout</a>
        <?php else: ?>
            <a href="index.php" class="<?php echo $navActive === 'login' ? 'active' : ''; ?>">Login</a>
            <a href="join.php" class="<?php echo $navActive === 'join' ? 'active' : ''; ?>">Join / Renew</a>
            <a href="calendar.php" class="<?php echo $navActive === 'calendar' ? 'active' : ''; ?>">Calendar</a>
            <?php if ($navGuestCheckin): ?>
                <a href="checkin.php">Check-In</a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>
</header>
