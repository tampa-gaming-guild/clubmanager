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
    $errorMsg = safe_err("Unable to load events: ", $e);
}

// Events-to-days mapping and volunteer pre-fetching are handled below the POST action block

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
                $errorMsg = safe_err("Volunteer signup failed: ", $e);
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
                $errorMsg = safe_err("Failed to cancel volunteer registration: ", $e);
            }
        }
    }
}

// Map events to days for quick grid rendering (run after potential POST updates)
$eventsByDay = [];
foreach ($events as $evt) {
    $day = (int)date('d', strtotime($evt['start_time']));
    $eventsByDay[$day][] = $evt;
}

// Pre-fetch volunteer signups and display names for all events in this month
$signupsByEvent = [];
if (!empty($events)) {
    try {
        $appDb = App\Database::getAppConnection();
        $eventIds = array_column($events, 'id');
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        
        $stmt = $appDb->prepare("SELECT event_id, contact_id, role FROM tgg_volunteer_signups WHERE event_id IN ($placeholders)");
        $stmt->execute($eventIds);
        $signupsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($signupsRaw)) {
            $contactIds = array_unique(array_column($signupsRaw, 'contact_id'));
            $formattedNames = \App\CiviCRMImporter::getFormattedNames($contactIds);
            
            foreach ($signupsRaw as $row) {
                $evtId = (int)$row['event_id'];
                $cid = (int)$row['contact_id'];
                $role = $row['role'];
                $dispName = $formattedNames[$cid] ?? "Member #{$cid}";
                $signupsByEvent[$evtId][$role] = $dispName;
            }
        }
    } catch (Exception $e) {
        // Fallback to empty
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
    <style>
        .calendar-layout {
            grid-template-columns: 1fr !important;
        }
        .calendar-day {
            height: 100px !important;
            padding: 8px !important;
        }
        .day-events-dots {
            bottom: 6px !important;
        }
    </style>
</head>
<body>
    <div class="app-container">
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
                <?php if (Auth::check()): ?>
                    <a href="index.php">Dashboard</a>
                    <a href="calendar.php" class="active">Calendar</a>
                    <a href="volunteers.php">Volunteers</a>
                    <a href="checkin.php">Check-In</a>
                    <?php if (has_role('admin')): ?>
                        <a href="admin/dashboard.php">Admin</a>
                    <?php endif; ?>
                    <a href="index.php?action=logout&amp;csrf_token=<?php echo e(get_csrf_token()); ?>" class="btn-logout">Logout</a>
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

                                    $padMonth = str_pad($month, 2, '0', STR_PAD_LEFT);
                                    $padDay = str_pad($day, 2, '0', STR_PAD_LEFT);
                                    $dateStr = "{$year}-{$padMonth}-{$padDay}";
                                    
                                    echo "<td class='calendar-day {$dayClass}' onclick=\"window.location.href='volunteers.php?highlight={$dateStr}'\" style='cursor: pointer;'>";
                                    echo "<span class='day-num'>{$day}</span>";
                                    
                                    if ($hasEvents) {
                                        echo '<div class="day-events-dots">';
                                        foreach ($eventsByDay[$day] as $evt) {
                                            echo '<span class="event-dot" title="' . e($evt['title']) . '"></span>';
                                        }
                                        echo '</div>';

                                        // Determine if Open and Close slots are filled
                                        $openName = '';
                                        $closeName = '';
                                        foreach ($eventsByDay[$day] as $evt) {
                                            $evtId = (int)$evt['id'];
                                            if (isset($signupsByEvent[$evtId]['Open'])) {
                                                $openName = $signupsByEvent[$evtId]['Open'];
                                            }
                                            if (isset($signupsByEvent[$evtId]['Close'])) {
                                                $closeName = $signupsByEvent[$evtId]['Close'];
                                            }
                                        }

                                        $openColor = !empty($openName) ? 'var(--color-success, #22c55e)' : 'var(--color-danger, #ef4444)';
                                        $closeColor = !empty($closeName) ? 'var(--color-success, #22c55e)' : 'var(--color-danger, #ef4444)';

                                        echo "<div class='day-slots' style='margin-top: 8px; display: flex; flex-direction: column; align-items: flex-start; gap: 4px; font-size: 0.75rem; font-weight: 500; font-family: var(--font-body); line-height: 1.2;'>";
                                        echo "<div style='white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 100%; text-align: left;'>";
                                        echo "<span style='color: {$openColor}; font-weight: 700;'>O:</span> ";
                                        if (!empty($openName)) {
                                            echo "<span style='color: var(--color-text-primary);' title='" . e($openName) . "'>" . e($openName) . "</span>";
                                        }
                                        echo "</div>";
                                        echo "<div style='white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 100%; text-align: left;'>";
                                        echo "<span style='color: {$closeColor}; font-weight: 700;'>C:</span> ";
                                        if (!empty($closeName)) {
                                            echo "<span style='color: var(--color-text-primary);' title='" . e($closeName) . "'>" . e($closeName) . "</span>";
                                        }
                                        echo "</div>";
                                        echo "</div>";
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


            </div>
        </main>

        <footer class="app-footer">
            <p>&copy; <?php echo date('Y'); ?> TGG Club Membership System. Secure Public Portal.</p>
        </footer>
    </div>
</body>
</html>
