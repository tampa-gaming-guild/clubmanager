<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<button type="button" class="admin-sidebar-toggle" id="adminSidebarToggle" aria-label="Toggle admin menu" aria-expanded="false" aria-controls="adminSidebarPanel">
    <span class="admin-sidebar-toggle-arrow">&rsaquo;</span>
</button>
<div class="admin-sidebar-backdrop" id="adminSidebarBackdrop"></div>
<aside class="admin-sidebar glass-panel" id="adminSidebarPanel">
    <h3>Admin Controls</h3>
    <ul class="admin-menu">
        <li><a href="dashboard.php" class="<?php echo ($current_page === 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a></li>
        
        <?php if (has_permission('schedule events')): ?>
            <li><a href="scheduler.php" class="<?php echo ($current_page === 'scheduler.php') ? 'active' : ''; ?>">Event Scheduler</a></li>
        <?php endif; ?>
        
        <?php if (has_permission('manage hosting')): ?>
            <li><a href="volunteer_credits.php" class="<?php echo ($current_page === 'volunteer_credits.php') ? 'active' : ''; ?>">Volunteer Credits</a></li>
        <?php endif; ?>
        
        <?php if (has_permission('all')): ?>
            <li><a href="import.php" class="<?php echo ($current_page === 'import.php') ? 'active' : ''; ?>">CiviCRM Importer</a></li>
        <?php endif; ?>
        
        <?php if (has_permission('manage configuration')): ?>
            <li><a href="memberships.php" class="<?php echo ($current_page === 'memberships.php') ? 'active' : ''; ?>">Memberships</a></li>
        <?php endif; ?>
        
        <?php if (has_permission('all')): ?>
            <li><a href="email_templates.php" class="<?php echo ($current_page === 'email_templates.php') ? 'active' : ''; ?>">Email Templates</a></li>
        <?php endif; ?>
        
        <?php if (has_permission('manage roles') || has_permission('manage hosting')): ?>
            <li><a href="roles.php" class="<?php echo ($current_page === 'roles.php') ? 'active' : ''; ?>">Roles & Permissions</a></li>
        <?php endif; ?>
        
        <?php 
        $reports_active = in_array($current_page, ['reports.php', 'payments.php', 'attendance.php', 'email_log.php', 'autorenew_log.php', 'audit_log.php']);
        $show_payments = has_permission('process payments');
        $show_attendance = has_permission('edit checkins');
        $show_email_log = has_permission('all');
        $show_audit_log = has_permission('admin panel');

        if ($show_payments || $show_attendance || $show_email_log || $show_audit_log):
        ?>
            <li>
                <a href="reports.php" class="<?php echo $reports_active ? 'active' : ''; ?>">Reports & Analytics</a>
                <ul class="admin-submenu" style="list-style-type: none; padding-left: 15px; margin-top: 5px; display: flex; flex-direction: column; gap: 4px;">
                    <?php if ($show_payments): ?>
                        <li><a href="payments.php" class="<?php echo ($current_page === 'payments.php') ? 'active' : ''; ?>" style="padding: 6px 10px; font-size: 0.85rem; border-left: none; border-radius: 4px;">Payments Log</a></li>
                    <?php endif; ?>
                    <?php if ($show_attendance): ?>
                        <li><a href="attendance.php" class="<?php echo ($current_page === 'attendance.php') ? 'active' : ''; ?>" style="padding: 6px 10px; font-size: 0.85rem; border-left: none; border-radius: 4px;">Attendance Log</a></li>
                        <li><a href="checkins.php" class="<?php echo ($current_page === 'checkins.php') ? 'active' : ''; ?>" style="padding: 6px 10px; font-size: 0.85rem; border-left: none; border-radius: 4px;">Check-In List</a></li>
                    <?php endif; ?>
                    <?php if ($show_email_log): ?>
                        <li><a href="email_log.php" class="<?php echo ($current_page === 'email_log.php') ? 'active' : ''; ?>" style="padding: 6px 10px; font-size: 0.85rem; border-left: none; border-radius: 4px;">Email Log</a></li>
                        <li><a href="autorenew_log.php" class="<?php echo ($current_page === 'autorenew_log.php') ? 'active' : ''; ?>" style="padding: 6px 10px; font-size: 0.85rem; border-left: none; border-radius: 4px;">Autorenew Log</a></li>
                    <?php endif; ?>
                    <?php if ($show_audit_log): ?>
                        <li><a href="audit_log.php" class="<?php echo ($current_page === 'audit_log.php') ? 'active' : ''; ?>" style="padding: 6px 10px; font-size: 0.85rem; border-left: none; border-radius: 4px;">Audit Log</a></li>
                    <?php endif; ?>
                </ul>
            </li>
        <?php endif; ?>
    </ul>
</aside>

