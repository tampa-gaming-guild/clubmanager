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
    <?php if (has_role('admin')): ?>
        <form action="<?php echo rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/admin/dashboard.php'; ?>" method="GET" class="navbar-search-form" style="margin: 0 20px; flex-grow: 1; max-width: 380px; position: relative;">
            <input type="text" name="search" placeholder="Search members by name..."
                value="<?php echo isset($_GET['search']) ? e($_GET['search']) : ''; ?>"
                style="width: 100%; padding: 8px 15px 8px 35px; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 20px; color: #fff; font-size: 0.85rem; outline: none; transition: all 0.2s ease;">
            <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: rgba(255, 255, 255, 0.4); font-size: 0.9rem;">🔍</span>
        </form>
    <?php endif; ?>
    <nav class="nav-links">
        <?php if ($navKiosk): ?>
            <a href="<?php echo $navPrefix; ?>index.php">Portal Hub</a>
            <a href="<?php echo $navPrefix; ?>calendar.php">Calendar</a>
            <a href="<?php echo $navPrefix; ?>volunteers.php">Volunteers</a>
            <a href="<?php echo $navPrefix; ?>checkin.php" class="active">Check-In Portal</a>
        <?php elseif ($navAuthed): ?>
            <a href="<?php echo $navPrefix; ?>index.php" class="<?php echo $navActive === 'dashboard' ? 'active' : ''; ?>">Dashboard</a>
            <a href="<?php echo $navPrefix; ?>calendar.php" class="<?php echo $navActive === 'calendar' ? 'active' : ''; ?>">Calendar</a>
            <a href="<?php echo $navPrefix; ?>volunteers.php" class="<?php echo $navActive === 'volunteers' ? 'active' : ''; ?>">Volunteers</a>
            <a href="<?php echo $navPrefix; ?>checkin.php" class="<?php echo $navActive === 'checkin' ? 'active' : ''; ?>">Check-In</a>
            <?php if ($navAdminArea || has_role('admin')): ?>
                <a href="<?php echo $navAdminArea ? 'dashboard.php' : 'admin/dashboard.php'; ?>" class="<?php echo $navActive === 'admin' ? 'active' : ''; ?>">Admin</a>
            <?php endif; ?>
            <a href="<?php echo $navPrefix; ?>index.php?action=logout&amp;csrf_token=<?php echo e(get_csrf_token()); ?>" class="btn-logout">Logout</a>
        <?php else: ?>
            <a href="index.php" class="<?php echo $navActive === 'login' ? 'active' : ''; ?>">Login</a>
            <a href="join.php">Join Us</a>
            <a href="calendar.php" class="<?php echo $navActive === 'calendar' ? 'active' : ''; ?>">Calendar</a>
            <a href="volunteers.php" class="<?php echo $navActive === 'volunteers' ? 'active' : ''; ?>">Volunteers</a>
            <?php if ($navGuestCheckin): ?>
                <a href="checkin.php">Check-In</a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>
</header>
