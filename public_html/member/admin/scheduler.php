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
</head>
<body>
    <div class="app-container">
        <header class="navbar">
            <div class="logo">TGG Members</div>
            <nav class="nav-links">
                <a href="../index.php">Dashboard</a>
                <a href="../calendar.php">Calendar</a>
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
                                                            <?php echo date('M d, Y', strtotime($evt['start_time'])); ?><br>
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
