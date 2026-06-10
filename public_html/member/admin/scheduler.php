<?php
/**
 * Admin Session Scheduler
 * Allows administrators to schedule new sessions/events and manage existing ones.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Event;
use App\Database;

Auth::requireAdmin();

$errorMsg = null;
$successMsg = null;

// Helper to determine if a date matches monthly occurrence rules (e.g. 1st, 3rd, last)
function matches_monthly_rule($date, $selectedWeeks, $dayOfWeekIndex) {
    $w = (int)date('w', $date);
    if ($w !== (int)$dayOfWeekIndex) {
        return false;
    }
    
    $dayOfMonth = (int)date('j', $date);
    $nth = (int)ceil($dayOfMonth / 7);
    
    $daysInMonth = (int)date('t', $date);
    $isLast = ($dayOfMonth + 7 > $daysInMonth);
    
    foreach ($selectedWeeks as $week) {
        if ($week === '1st' && $nth === 1) return true;
        if ($week === '2nd' && $nth === 2) return true;
        if ($week === '3rd' && $nth === 3) return true;
        if ($week === '4th' && $nth === 4) return true;
        if ($week === 'last' && $isLast) return true;
    }
    
    return false;
}

// Handle Delete Event Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token.";
    } else {
        $eventId = (int)($_POST['event_id'] ?? 0);
        try {
            $appDb = Database::getAppConnection();
            $stmt = $appDb->prepare("DELETE FROM tgg_events WHERE id = :id");
            $stmt->execute(['id' => $eventId]);
            $successMsg = "Event deleted successfully.";
        } catch (Exception $e) {
            $errorMsg = "Failed to delete event: " . $e->getMessage();
        }
    }
}

// Handle Add Event Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token.";
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');
        $maxVolunteers = (int)($_POST['max_volunteers'] ?? 0);

        if (empty($title) || empty($startTime) || empty($endTime)) {
            $errorMsg = "Event Title, Start Time, and End Time are required.";
        } else {
            try {
                // Reformat datetime-local (YYYY-MM-DDTHH:MM) to MySQL format (YYYY-MM-DD HH:MM:SS)
                $mysqlStart = date('Y-m-d H:i:s', strtotime($startTime));
                $mysqlEnd = date('Y-m-d H:i:s', strtotime($endTime));

                Event::createEvent($title, $description, $mysqlStart, $mysqlEnd, $maxVolunteers);
                $successMsg = "New session scheduled successfully!";
                
                // Clear post inputs on success
                $_POST = [];
            } catch (Exception $e) {
                $errorMsg = "Scheduling failed: " . $e->getMessage();
            }
        }
    }
}

// Handle Add Recurring Events Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_recurring'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token.";
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $startDateInput = trim($_POST['start_date'] ?? '');
        $startTimeInput = trim($_POST['start_time'] ?? '');
        $endTimeInput = trim($_POST['end_time'] ?? '');
        $maxVolunteers = (int)($_POST['max_volunteers'] ?? 0);
        $recurrenceType = $_POST['recurrence_type'] ?? 'weekly';
        $dayOfWeek = (int)($_POST['day_of_week'] ?? 0);
        $monthlyWeeks = $_POST['monthly_weeks'] ?? [];
        
        if (empty($title) || empty($startDateInput) || empty($startTimeInput) || empty($endTimeInput)) {
            $errorMsg = "Event Title, Start Date, Start Time, and End Time are required.";
        } elseif ($recurrenceType === 'monthly' && empty($monthlyWeeks)) {
            $errorMsg = "Please select at least one week of the month for monthly recurrence.";
        } else {
            try {
                $startDate = strtotime($startDateInput);
                $endDate = strtotime("+4 months", $startDate);
                
                $currentDate = $startDate;
                $insertedCount = 0;
                
                while ($currentDate <= $endDate) {
                    $shouldInsert = false;
                    
                    if ($recurrenceType === 'weekly') {
                        $w = (int)date('w', $currentDate);
                        if ($w === $dayOfWeek) {
                            $shouldInsert = true;
                        }
                    } else if ($recurrenceType === 'monthly') {
                        if (matches_monthly_rule($currentDate, $monthlyWeeks, $dayOfWeek)) {
                            $shouldInsert = true;
                        }
                    }
                    
                    if ($shouldInsert) {
                        $dateStr = date('Y-m-d', $currentDate);
                        $mysqlStart = "{$dateStr} {$startTimeInput}:00";
                        $mysqlEnd = "{$dateStr} {$endTimeInput}:00";
                        
                        // Handle overnight events (if end time is less than start time)
                        if (strtotime($mysqlEnd) < strtotime($mysqlStart)) {
                            $mysqlEnd = date('Y-m-d H:i:s', strtotime("+1 day", strtotime($mysqlEnd)));
                        }
                        
                        Event::createEvent($title, $description, $mysqlStart, $mysqlEnd, $maxVolunteers);
                        $insertedCount++;
                    }
                    
                    $currentDate = strtotime("+1 day", $currentDate);
                }
                
                $successMsg = "Successfully scheduled {$insertedCount} recurring events populated 4 months in advance!";
                $_POST = [];
            } catch (Exception $e) {
                $errorMsg = "Failed to schedule recurring events: " . $e->getMessage();
            }
        }
    }
}

// Fetch all events for display
try {
    $events = Event::getEvents();
} catch (Exception $e) {
    $events = [];
    $errorMsg = "Unable to load events: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event & Session Scheduler - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .main-content {
            max-width: 1600px;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <header class="navbar">
            <div class="logo">TGG Members</div>
            <nav class="nav-links">
                <a href="../index.php">Dashboard</a>
                <a href="../calendar.php">Calendar</a>
                <a href="../volunteers.php">Volunteers</a>
                <a href="../checkin.php">Check-In</a>
                <a href="dashboard.php" class="active">Admin</a>
                <a href="../index.php?action=logout" class="btn-logout">Logout</a>
            </nav>
        </header>

        <main class="main-content">
            <div class="admin-grid">
                
                <!-- Sidebar Admin Navigation -->
                <aside class="admin-sidebar glass-panel">
                    <h3>Admin Controls</h3>
                    <ul class="admin-menu">
                        <li><a href="dashboard.php">Control Hub</a></li>
                        <li><a href="scheduler.php" class="active">Event Scheduler</a></li>
                        <li><a href="import.php">CiviCRM Importer</a></li>
                        <li><a href="reports.php">Reports & Analytics</a></li>
                    </ul>
                </aside>

                <!-- Work Area: Scheduler -->
                <section class="admin-workspace glass-panel">
                    <h2>Session & Event Scheduler</h2>
                    
                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <?php if ($successMsg): ?>
                        <div class="alert alert-success"><?php echo e($successMsg); ?></div>
                    <?php endif; ?>

                    <div class="scheduler-split">
                        
                        <div class="scheduler-forms-container" style="display: flex; flex-direction: column; gap: 25px;">
                            <!-- Form: Create New Event -->
                            <div class="scheduler-form-card">
                                <h3>Schedule a New Event</h3>
                                <form action="scheduler.php" method="POST" class="standard-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                    
                                    <div class="form-group">
                                        <label for="title">Event Title</label>
                                        <input type="text" id="title" name="title" required value="<?php echo e($_POST['title'] ?? ''); ?>" placeholder="e.g. Saturday Open Lab">
                                    </div>

                                    <div class="form-group">
                                        <label for="description">Description (Optional)</label>
                                        <textarea id="description" name="description" placeholder="Provide event details..."><?php echo e($_POST['description'] ?? ''); ?></textarea>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="start_time">Start Date & Time</label>
                                            <input type="datetime-local" id="start_time" name="start_time" required value="<?php echo e($_POST['start_time'] ?? ''); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="end_time">End Date & Time</label>
                                            <input type="datetime-local" id="end_time" name="end_time" required value="<?php echo e($_POST['end_time'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="max_volunteers">Max Volunteers Needed (0 for unlimited)</label>
                                        <input type="number" id="max_volunteers" name="max_volunteers" min="0" value="<?php echo isset($_POST['max_volunteers']) ? (int)$_POST['max_volunteers'] : 0; ?>">
                                    </div>

                                    <button type="submit" name="action_create" class="btn btn-primary btn-block">Add Event to Calendar</button>
                                </form>
                            </div>

                            <!-- Form: Create Recurring Events -->
                            <div class="scheduler-form-card">
                                <h3>Schedule Recurring Events</h3>
                                <p style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 15px;">Automatically populates events for 4 months starting from the selected date.</p>
                                <form action="scheduler.php" method="POST" class="standard-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                    
                                    <div class="form-group">
                                        <label for="rec_title">Event Title</label>
                                        <input type="text" id="rec_title" name="title" required placeholder="e.g. Wednesday Night Club Session">
                                    </div>

                                    <div class="form-group">
                                        <label for="rec_description">Description (Optional)</label>
                                        <textarea id="rec_description" name="description" placeholder="Provide event details..."></textarea>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="rec_start_date">Recurrence Start Date</label>
                                            <input type="date" id="rec_start_date" name="start_date" required value="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="rec_max_volunteers">Max Volunteers</label>
                                            <input type="number" id="rec_max_volunteers" name="max_volunteers" min="0" value="0">
                                        </div>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="rec_start_time">Start Time</label>
                                            <input type="time" id="rec_start_time" name="start_time" required value="18:00">
                                        </div>
                                        <div class="form-group">
                                            <label for="rec_end_time">End Time</label>
                                            <input type="time" id="rec_end_time" name="end_time" required value="21:00">
                                        </div>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="recurrence_type">Recurrence Type</label>
                                            <select id="recurrence_type" name="recurrence_type" onchange="toggleRecurrenceFields()" required>
                                                <option value="weekly">Weekly (Every week)</option>
                                                <option value="monthly">Monthly (Specific weeks)</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="day_of_week">Day of the Week</label>
                                            <select id="day_of_week" name="day_of_week" required>
                                                <option value="0">Sunday</option>
                                                <option value="1">Monday</option>
                                                <option value="2">Tuesday</option>
                                                <option value="3" selected>Wednesday</option>
                                                <option value="4">Thursday</option>
                                                <option value="5">Friday</option>
                                                <option value="6">Saturday</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div id="monthly-weeks-container" class="form-group" style="display: none; background: rgba(0, 0, 0, 0.15); padding: 12px; border-radius: 8px; border: 1px solid var(--border-glass);">
                                        <label style="margin-bottom: 8px; display: block; font-size: 0.85rem; font-weight: bold; color: var(--color-text-secondary);">Select Weeks of the Month:</label>
                                        <div style="display: flex; flex-direction: column; gap: 8px;">
                                            <div class="checkbox-group" style="display: flex; align-items: center; gap: 10px;">
                                                <input type="checkbox" id="week_1st" name="monthly_weeks[]" value="1st" checked>
                                                <label for="week_1st" style="text-transform: none; font-weight: normal; margin-bottom: 0;">First week of the month</label>
                                            </div>
                                            <div class="checkbox-group" style="display: flex; align-items: center; gap: 10px;">
                                                <input type="checkbox" id="week_2nd" name="monthly_weeks[]" value="2nd">
                                                <label for="week_2nd" style="text-transform: none; font-weight: normal; margin-bottom: 0;">Second week of the month</label>
                                            </div>
                                            <div class="checkbox-group" style="display: flex; align-items: center; gap: 10px;">
                                                <input type="checkbox" id="week_3rd" name="monthly_weeks[]" value="3rd" checked>
                                                <label for="week_3rd" style="text-transform: none; font-weight: normal; margin-bottom: 0;">Third week of the month</label>
                                            </div>
                                            <div class="checkbox-group" style="display: flex; align-items: center; gap: 10px;">
                                                <input type="checkbox" id="week_4th" name="monthly_weeks[]" value="4th">
                                                <label for="week_4th" style="text-transform: none; font-weight: normal; margin-bottom: 0;">Fourth week of the month</label>
                                            </div>
                                            <div class="checkbox-group" style="display: flex; align-items: center; gap: 10px;">
                                                <input type="checkbox" id="week_last" name="monthly_weeks[]" value="last">
                                                <label for="week_last" style="text-transform: none; font-weight: normal; margin-bottom: 0;">Last week of the month</label>
                                            </div>
                                        </div>
                                    </div>

                                    <button type="submit" name="action_create_recurring" class="btn btn-primary btn-block mt-10">Populate Recurring Events</button>
                                </form>
                            </div>
                        </div>

                        <script>
                            function toggleRecurrenceFields() {
                                const type = document.getElementById('recurrence_type').value;
                                const monthlyOpts = document.getElementById('monthly-weeks-container');
                                if (type === 'monthly') {
                                    monthlyOpts.style.display = 'block';
                                } else {
                                    monthlyOpts.style.display = 'none';
                                }
                            }
                            document.addEventListener('DOMContentLoaded', toggleRecurrenceFields);
                        </script>

                        <!-- Table: Existing Scheduled Events -->
                        <div class="scheduler-list-card">
                            <h3>Upcoming Events</h3>
                            
                            <?php if (empty($events)): ?>
                                <p class="empty-text">No events scheduled yet. Add one using the form.</p>
                            <?php else: ?>
                                <div class="admin-table-container">
                                    <table class="admin-table">
                                        <thead>
                                            <tr>
                                                <th>Title</th>
                                                <th>Date & Time</th>
                                                <th>Vols</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($events as $evt): ?>
                                                <?php 
                                                    $eid = (int)$evt['id'];
                                                    $volCount = (int)Database::getAppConnection()->query("SELECT COUNT(*) FROM tgg_volunteer_signups WHERE event_id = {$eid}")->fetchColumn();
                                                ?>
                                                <tr>
                                                    <td><strong><?php echo e($evt['title']); ?></strong></td>
                                                    <td>
                                                        <span class="table-datetime">
                                                            <?php echo date('m/d/y', strtotime($evt['start_time'])); ?><br>
                                                            <?php echo date('g:i A', strtotime($evt['start_time'])); ?> - <?php echo date('g:i A', strtotime($evt['end_time'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php echo $volCount; ?> / <?php echo $evt['max_volunteers'] > 0 ? (int)$evt['max_volunteers'] : '∞'; ?>
                                                    </td>
                                                    <td>
                                                        <form action="scheduler.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this event?');" class="inline-form">
                                                            <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                                            <input type="hidden" name="event_id" value="<?php echo $eid; ?>">
                                                            <button type="submit" name="action_delete" class="btn btn-danger btn-small">Delete</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
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
