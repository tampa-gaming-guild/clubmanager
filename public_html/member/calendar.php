<?php
/**
 * Interactive Club Calendar & Volunteer Signup
 * Renders month-grid navigation and list of upcoming events with volunteer enrollment buttons.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Event;
use App\Auth;

$errorMsg = null;
$successMsg = null;

// Determine Selected Month & Year (for Grid Calendar)
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Adjust underflow/overflow of months
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

$monthStart = "{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01 00:00:00";
$monthEnd = date('Y-m-t 23:59:59', strtotime($monthStart));

// Fetch Events for the month
try {
    $events = Event::getEvents($monthStart, $monthEnd);
} catch (Exception $e) {
    $events = [];
    $errorMsg = "Unable to load events: " . $e->getMessage();
}

// Map events to days for quick grid rendering
$eventsByDay = [];
foreach ($events as $evt) {
    $day = (int)date('d', strtotime($evt['start_time']));
    $eventsByDay[$day][] = $evt;
}

// Handle Volunteer Actions (Sign Up / Cancel)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::check()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token.";
    } else {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $contactId = $_SESSION['user']['contact_id'];
        
        // A. Sign Up Action
        if (isset($_POST['action_signup'])) {
            $role = trim($_POST['role'] ?? 'General Volunteer');
            try {
                if (empty($role)) { $role = 'General Volunteer'; }
                Event::signupVolunteer($eventId, $contactId, $role);
                $successMsg = "Thank you! You have signed up successfully as a volunteer.";
                // Refresh events
                $events = Event::getEvents($monthStart, $monthEnd);
            } catch (Exception $e) {
                $errorMsg = "Volunteer signup failed: " . $e->getMessage();
            }
        }
        
        // B. Cancel Action
        if (isset($_POST['action_cancel'])) {
            $role = $_POST['role'] ?? null;
            try {
                Event::cancelVolunteer($eventId, $contactId, $role);
                if ($role) {
                    $successMsg = "Your signup for the role '{$role}' has been cancelled.";
                } else {
                    $successMsg = "Your volunteer registration has been cancelled.";
                }
                // Refresh events
                $events = Event::getEvents($monthStart, $monthEnd);
            } catch (Exception $e) {
                $errorMsg = "Failed to cancel volunteer registration: " . $e->getMessage();
            }
        }
    }
}

// Calendar Month Grid Parameters
$firstDayOfWeek = (int)date('w', strtotime($monthStart)); // 0 = Sunday, 6 = Saturday
$daysInMonth = (int)date('t', strtotime($monthStart));
$monthLabel = date('F Y', strtotime($monthStart));

// Calculate previous/next links
$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Club Calendar - Events & Volunteering</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="app-container">
        <header class="navbar">
            <div class="logo">TGG Members</div>
            <nav class="nav-links">
                <?php if (Auth::check()): ?>
                    <a href="index.php">Dashboard</a>
                    <a href="calendar.php" class="active">Calendar</a>
                    <a href="volunteers.php">Volunteers</a>
                    <a href="checkin.php">Check-In</a>
                    <?php if (has_role('admin')): ?>
                        <a href="admin/dashboard.php">Admin</a>
                    <?php endif; ?>
                    <a href="index.php?action=logout" class="btn-logout">Logout</a>
                <?php else: ?>
                    <a href="index.php">Login</a>
                    <a href="join.php">Join Us</a>
                    <a href="calendar.php" class="active">Calendar</a>
                    <a href="volunteers.php">Volunteers</a>
                    <a href="checkin.php">Check-In</a>
                <?php endif; ?>
            </nav>
        </header>

        <main class="main-content">
            <?php if ($errorMsg): ?>
                <div class="alert alert-danger" style="margin-bottom: 20px;"><?php echo e($errorMsg); ?></div>
            <?php endif; ?>

            <?php if ($successMsg): ?>
                <div class="alert alert-success" style="margin-bottom: 20px;"><?php echo e($successMsg); ?></div>
            <?php endif; ?>

            <div class="calendar-layout">
                
                <!-- Left: Grid Calendar -->
                <section class="calendar-grid-section glass-panel">
                    <div class="calendar-controls">
                        <a href="calendar.php?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="btn btn-secondary">&larr; Prev</a>
                        <h2><?php echo e($monthLabel); ?></h2>
                        <a href="calendar.php?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="btn btn-secondary">Next &rarr;</a>
                    </div>

                    <table class="calendar-table">
                        <thead>
                            <tr>
                                <th>Sun</th>
                                <th>Mon</th>
                                <th>Tue</th>
                                <th>Wed</th>
                                <th>Thu</th>
                                <th>Fri</th>
                                <th>Sat</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <?php
                                // Render blank slots for preceding month days
                                for ($i = 0; $i < $firstDayOfWeek; $i++) {
                                    echo '<td class="calendar-day empty-day"></td>';
                                }

                                // Render days of the month
                                $currentDayOfWeek = $firstDayOfWeek;
                                for ($day = 1; $day <= $daysInMonth; $day++) {
                                    if ($currentDayOfWeek == 0 && $day > 1) {
                                        echo '</tr><tr>';
                                    }
                                    
                                    $hasEvents = isset($eventsByDay[$day]);
                                    $dayClass = $hasEvents ? 'has-events-day' : '';
                                    
                                    // Highlight today
                                    if ($day == (int)date('d') && $month == (int)date('m') && $year == (int)date('Y')) {
                                        $dayClass .= ' today-day';
                                    }

                                    echo "<td class='calendar-day {$dayClass}'>";
                                    echo "<span class='day-num'>{$day}</span>";
                                    
                                    if ($hasEvents) {
                                        echo '<div class="day-events-dots">';
                                        foreach ($eventsByDay[$day] as $evt) {
                                            echo '<span class="event-dot" title="' . e($evt['title']) . '"></span>';
                                        }
                                        echo '</div>';
                                    }
                                    
                                    echo '</td>';

                                    $currentDayOfWeek = ($currentDayOfWeek + 1) % 7;
                                }

                                // Render blank slots for trailing days
                                if ($currentDayOfWeek > 0) {
                                    for ($i = $currentDayOfWeek; $i < 7; $i++) {
                                        echo '<td class="calendar-day empty-day"></td>';
                                    }
                                }
                                ?>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <!-- Right: Events & Volunteer Signups list -->
                <section class="calendar-list-section glass-panel">
                    <h3>Events & Session Schedule</h3>
                    <p class="description-text">Select a month to see sessions. Support the club by volunteering!</p>

                    <div class="events-list">
                        <?php if (empty($events)): ?>
                            <div class="empty-state">
                                <p>No events scheduled for <?php echo e($monthLabel); ?>.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($events as $evt): ?>
                                <?php 
                                    $evtId = (int)$evt['id'];
                                    $vols = Event::getVolunteers($evtId);
                                    $userRoles = Auth::check() ? Event::getMemberRolesForEvent($evtId, $_SESSION['user']['contact_id']) : [];
                                    $slotsFilled = count($vols);
                                    $maxSlots = (int)$evt['max_volunteers'];
                                ?>
                                <div class="event-card" id="event-<?php echo $evtId; ?>">
                                    <div class="event-card-header">
                                        <h4><?php echo e($evt['title']); ?></h4>
                                        <span class="event-time">
                                            ⏰ <?php echo date('M d, g:i A', strtotime($evt['start_time'])); ?> - <?php echo date('g:i A', strtotime($evt['end_time'])); ?>
                                        </span>
                                    </div>
                                    <p class="event-desc"><?php echo e($evt['description']); ?></p>

                                    <!-- Volunteer Slot Meter -->
                                    <div class="volunteer-status">
                                        <strong>Volunteers:</strong> 
                                        <span><?php echo $slotsFilled; ?> of <?php echo $maxSlots > 0 ? $maxSlots : 'Unlimited'; ?> registered</span>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $maxSlots > 0 ? min(100, ($slotsFilled / $maxSlots) * 100) : 50; ?>%"></div>
                                        </div>
                                    </div>

                                    <!-- Volunteer Roster (Admins can see full list, members can see count) -->
                                    <?php if (!empty($vols)): ?>
                                        <div class="volunteer-roster">
                                            <h5>Roster:</h5>
                                            <ul>
                                                <?php foreach ($vols as $vol): ?>
                                                    <li><?php echo e($vol['display_name']); ?> (<?php echo e($vol['role']); ?>)</li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Volunteer Action forms -->
                                    <div class="volunteer-actions-area">
                                        <?php if (Auth::check()): ?>
                                            <!-- Display active signups for user with single-role cancel buttons -->
                                            <?php if (!empty($userRoles)): ?>
                                                <div class="user-volunteer-status" style="margin-bottom: 15px;">
                                                    <strong style="font-size: 0.9rem; color: var(--color-text-secondary); display: block; margin-bottom: 5px;">Your Signed Up Roles:</strong>
                                                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                                        <?php foreach ($userRoles as $r): ?>
                                                            <span class="badge badge-active" style="display: inline-flex; align-items: center; gap: 8px; font-size: 0.8rem; padding: 4px 10px;">
                                                                <?php echo e($r); ?>
                                                                <form action="calendar.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" method="POST" style="display: inline;">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                                                    <input type="hidden" name="event_id" value="<?php echo $evtId; ?>">
                                                                    <input type="hidden" name="role" value="<?php echo e($r); ?>">
                                                                    <button type="submit" name="action_cancel" style="background: none; border: none; color: hsl(350, 89%, 65%); cursor: pointer; padding: 0; font-size: 0.95rem; line-height: 1; font-weight: bold; margin-left: 2px;" title="Cancel role">✕</button>
                                                                </form>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php 
                                                $allRoles = ['Open', 'Close', 'Greeter'];
                                                $availableRoles = array_diff($allRoles, $userRoles);
                                            ?>

                                            <?php if (!empty($availableRoles)): ?>
                                                <?php if ($maxSlots == 0 || $slotsFilled < $maxSlots): ?>
                                                    <form action="calendar.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" method="POST" class="volunteer-signup-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                                        <input type="hidden" name="event_id" value="<?php echo $evtId; ?>">
                                                        <div class="form-row-inline">
                                                            <select name="role" required>
                                                                <option value="" disabled selected><?php echo empty($userRoles) ? '-- Choose Role --' : '-- Sign up for another role --'; ?></option>
                                                                <?php foreach ($availableRoles as $r): ?>
                                                                    <option value="<?php echo e($r); ?>"><?php echo e($r); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <button type="submit" name="action_signup" class="btn btn-success">Volunteer</button>
                                                        </div>
                                                    </form>
                                                <?php else: ?>
                                                    <button class="btn btn-secondary btn-block" disabled>Volunteer Slots Full</button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <p class="info-block" style="margin-bottom: 0; text-align: center; background: rgba(34, 197, 94, 0.08); border: 1px solid rgba(34, 197, 94, 0.2); color: var(--color-success);">You have volunteered for all available roles!</p>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <p class="login-prompt-text"><a href="index.php?action=login">Log in</a> to sign up as a volunteer for this session.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </main>

        <footer class="app-footer">
            <p>&copy; <?php echo date('Y'); ?> TGG Club Membership System. Secure Public Portal.</p>
        </footer>
    </div>
</body>
</html>
