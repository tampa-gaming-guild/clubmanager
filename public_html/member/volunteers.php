<?php
/**
 * Public Volunteer Schedule
 * Displays upcoming events and the signup status of the three roles: Open, Close, Greeter.
 */
require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Event;
use App\Auth;

$errorMsg = null;
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'upcoming';

try {
    $allEvents = Event::getEvents();
    $events = [];
    $today = date('Y-m-d 00:00:00');
    
    foreach ($allEvents as $evt) {
        if ($filter === 'upcoming') {
            if ($evt['start_time'] >= $today) {
                $events[] = $evt;
            }
        } else {
            $events[] = $evt;
        }
    }
} catch (Exception $e) {
    $events = [];
    $errorMsg = "Unable to load schedule: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Volunteer Schedule - TGG Club</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .filter-toggle-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        .filter-toggle {
            display: flex;
            gap: 10px;
        }
        .filter-toggle a {
            padding: 6px 14px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-glass);
            border-radius: 20px;
            font-size: 0.85rem;
            color: var(--color-text-secondary);
            font-weight: 500;
            transition: all 0.2s;
        }
        .filter-toggle a.active {
            background: var(--color-primary);
            color: #fff;
            border-color: var(--color-primary);
        }
        .event-group-header td {
            background: rgba(255, 255, 255, 0.05) !important;
            font-family: var(--font-heading);
            font-size: 1rem;
            font-weight: 600;
            color: #fff !important;
            border-bottom: 1px solid rgba(255,255,255,0.08) !important;
            padding: 14px 16px !important;
        }
        .role-subrow td {
            padding: 12px 16px 12px 30px !important;
            font-size: 0.9rem;
        }
        .role-subrow:hover {
            background: rgba(255, 255, 255, 0.01) !important;
        }
        .volunteer-status-needed {
            color: hsl(38, 92%, 55%);
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .role-bullet {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 10px;
        }
        .bullet-open { background-color: var(--color-primary); }
        .bullet-close { background-color: #a855f7; }
        .bullet-greeter { background-color: var(--color-success); }
    </style>
</head>
<body>
    <div class="app-container">
        <header class="navbar">
            <div class="logo">TGG Members</div>
            <nav class="nav-links">
                <?php if (Auth::check()): ?>
                    <a href="index.php">Dashboard</a>
                    <a href="calendar.php">Calendar</a>
                    <a href="volunteers.php" class="active">Volunteers</a>
                    <a href="checkin.php">Check-In</a>
                    <?php if (has_role('admin')): ?>
                        <a href="admin/dashboard.php">Admin</a>
                    <?php endif; ?>
                    <a href="index.php?action=logout" class="btn-logout">Logout</a>
                <?php else: ?>
                    <a href="index.php">Login</a>
                    <a href="join.php">Join Us</a>
                    <a href="calendar.php">Calendar</a>
                    <a href="volunteers.php" class="active">Volunteers</a>
                    <a href="checkin.php">Check-In</a>
                <?php endif; ?>
            </nav>
        </header>

        <main class="main-content">
            <?php if ($errorMsg): ?>
                <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
            <?php endif; ?>

            <div class="glass-panel">
                <div class="filter-toggle-container">
                    <div>
                        <h2 style="margin-bottom: 5px;">Volunteer Schedule</h2>
                        <p style="color: var(--color-text-secondary); font-size: 0.9rem; margin-bottom: 0;">See who is helping out, or select an open slot to sign up on the calendar.</p>
                    </div>
                    <div class="filter-toggle">
                        <a href="volunteers.php?filter=upcoming" class="<?php echo $filter === 'upcoming' ? 'active' : ''; ?>">Upcoming Only</a>
                        <a href="volunteers.php?filter=all" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">All Events</a>
                    </div>
                </div>

                <div class="admin-table-container">
                    <table class="admin-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th style="width: 30%;">Volunteer Role</th>
                                <th style="width: 40%;">Volunteer Assigned</th>
                                <th style="width: 30%;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($events)): ?>
                                <tr>
                                    <td colspan="3" style="text-align: center; color: var(--color-text-secondary); padding: 40px;">
                                        No events scheduled.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($events as $evt): ?>
                                    <?php 
                                        $evtId = (int)$evt['id'];
                                        $vols = Event::getVolunteers($evtId);
                                        
                                        // Map role -> volunteer name
                                        $roleVolunteers = [];
                                        foreach ($vols as $vol) {
                                            $roleVolunteers[$vol['role']] = $vol['display_name'];
                                        }
                                        
                                        $eventDate = date('F d, Y (l)', strtotime($evt['start_time']));
                                        $eventTime = date('g:i A', strtotime($evt['start_time'])) . ' - ' . date('g:i A', strtotime($evt['end_time']));
                                    ?>
                                    <!-- Event Header Row -->
                                    <tr class="event-group-header">
                                        <td colspan="3">
                                            📅 <?php echo $eventDate; ?> — <?php echo e($evt['title']); ?>
                                            <span style="font-size: 0.8rem; font-weight: normal; color: var(--color-text-secondary); margin-left: 10px;">
                                                (⏰ <?php echo $eventTime; ?>)
                                            </span>
                                        </td>
                                    </tr>
                                    
                                    <?php 
                                        $roles = ['Open', 'Close', 'Greeter'];
                                        foreach ($roles as $role):
                                            $hasVol = isset($roleVolunteers[$role]);
                                            $volName = $hasVol ? $roleVolunteers[$role] : null;
                                            
                                            // Format the bullet class
                                            $bulletClass = 'bullet-' . strtolower($role);
                                            
                                            // Link to the specific event on the calendar page
                                            $monthStr = date('m', strtotime($evt['start_time']));
                                            $yearStr = date('Y', strtotime($evt['start_time']));
                                            $signupLink = "calendar.php?month={$monthStr}&year={$yearStr}#event-{$evtId}";
                                    ?>
                                        <tr class="role-subrow">
                                            <td>
                                                <span class="role-bullet <?php echo $bulletClass; ?>"></span>
                                                <strong><?php echo $role; ?></strong>
                                            </td>
                                            <td>
                                                <?php if ($hasVol): ?>
                                                    <span class="badge badge-active" style="display: inline-flex; align-items: center; gap: 8px;">
                                                        👤 <?php echo e($volName); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="volunteer-status-needed">
                                                        👋 Volunteer Needed
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($hasVol): ?>
                                                    <span style="color: var(--color-text-muted); font-size: 0.85rem;">Filled</span>
                                                <?php else: ?>
                                                    <a href="<?php echo $signupLink; ?>" class="btn btn-success btn-small" style="padding: 6px 12px; font-size: 0.8rem;">
                                                        Sign Up &rarr;
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>

        <footer class="app-footer">
            <p>&copy; <?php echo date('Y'); ?> TGG Club Membership System. Secure Public Portal.</p>
        </footer>
    </div>
</body>
</html>
