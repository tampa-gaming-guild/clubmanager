<?php
/**
 * Admin Check-In List Page
 * Displays a tabular report of check-ins for a selected date, ordered by first name.
 * Allows deleting check-ins.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Database;

Auth::requirePermission('edit checkins');

$errorMsg = null;
$successMsg = null;
$checkinsList = [];
$eventDates = [];

// Determine selected date (defaults to current local date)
$selectedDate = $_GET['date'] ?? '';
if (empty($selectedDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

// Check for success parameter in GET (Post-Redirect-Get pattern feedback)
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $successMsg = "Check-in deleted successfully.";
}

try {
    $appDb = Database::getAppConnection();

    // Handle Check-In Deletion
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_checkin'])) {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            $errorMsg = "Invalid security token.";
        } else {
            $checkinId = (int)($_POST['checkin_id'] ?? 0);
            if ($checkinId > 0) {
                $deleteStmt = $appDb->prepare("DELETE FROM tgg_checkins WHERE id = :id");
                $deleteStmt->execute(['id' => $checkinId]);
                
                // Redirect back to same page with date parameter to prevent form resubmission
                redirect("admin/checkins.php?date=" . urlencode($selectedDate) . "&success=1");
            } else {
                $errorMsg = "Invalid check-in ID.";
            }
        }
    }

    // Fetch Check-Ins for the selected date, ordered by first name
    // Falls back to display_name if first_name is empty/null.
    $stmt = $appDb->prepare("
        SELECT
            c.id AS checkin_id,
            c.checked_in_at,
            c.notes,
            c.guest_name,
            con.display_name,
            con.first_name,
            con.last_name,
            con.id AS contact_id
        FROM tgg_checkins c
        JOIN tgg_contacts con ON con.id = c.contact_id
        WHERE DATE(c.checked_in_at) = :date
        ORDER BY COALESCE(NULLIF(con.first_name, ''), con.display_name) ASC, con.last_name ASC
    ");
    $stmt->execute(['date' => $selectedDate]);
    $checkinsList = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Fetch unique dates with scheduled events
    $eventDates = $appDb->query("SELECT DISTINCT DATE(start_time) AS event_date FROM tgg_events")->fetchAll(PDO::FETCH_COLUMN) ?: [];

} catch (Exception $e) {
    $errorMsg = safe_err("Error compiling check-in report: ", $e);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check-In List - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <!-- Flatpickr Datepicker (Served locally to satisfy Content Security Policy) -->
    <link rel="stylesheet" href="../assets/css/flatpickr.min.css">
    <link rel="stylesheet" href="../assets/css/flatpickr-dark.min.css">
    <style>
        /* Custom styled Flatpickr for dark glassmorphism */
        .flatpickr-calendar {
            background: var(--color-surface-glass-solid) !important;
            border: 1px solid var(--border-glass) !important;
            box-shadow: var(--shadow-glass) !important;
            backdrop-filter: blur(12px) !important;
            -webkit-backdrop-filter: blur(12px) !important;
        }
        .flatpickr-months .flatpickr-month,
        .flatpickr-weekdays,
        span.flatpickr-weekday {
            background: transparent !important;
            color: var(--color-text-primary) !important;
        }
        .flatpickr-day {
            color: var(--color-text-secondary) !important;
            border-radius: 6px !important;
            margin: 2px 0 !important;
        }
        .flatpickr-day:hover,
        .flatpickr-day:focus {
            background: rgba(255, 255, 255, 0.08) !important;
            color: #fff !important;
            border-color: rgba(255, 255, 255, 0.15) !important;
        }
        .flatpickr-day.selected,
        .flatpickr-day.selected:hover {
            background: var(--color-primary) !important;
            color: #fff !important;
            border-color: var(--color-primary) !important;
        }
        .flatpickr-day.today {
            border-color: rgba(255, 255, 255, 0.3) !important;
        }
        .flatpickr-day.prevMonthDay,
        .flatpickr-day.nextMonthDay {
            color: var(--color-text-muted) !important;
        }
        
        /* Highlighted days with events */
        .flatpickr-day.has-event-day {
            border: 1px dashed var(--color-primary) !important;
            position: relative;
            font-weight: 600;
        }
        .flatpickr-day.has-event-day::after {
            content: '';
            position: absolute;
            bottom: 4px;
            left: 50%;
            transform: translateX(-50%);
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background-color: var(--color-primary);
        }
        .flatpickr-day.has-event-day.selected::after {
            background-color: #fff;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Navigation Bar -->
        <?php $navAdminArea = true; $navActive = 'admin'; include __DIR__ . '/../partials/navbar.php'; ?>

        <main class="main-content">
            <div class="admin-grid">
                
                <?php include 'sidebar.php'; ?>

                <!-- Work Area: Check-In List -->
                <section class="admin-workspace">
                    
                    <div style="margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; gap: 15px; flex-wrap: wrap;">
                        <div>
                            <h2 style="margin: 0;">Check-In List</h2>
                            <p class="description-text" style="margin: 5px 0 0 0;">Manage and verify members currently checked in at the club.</p>
                        </div>
                        <form method="GET" action="checkins.php" style="display: inline-flex; align-items: center; gap: 10px; background: rgba(255,255,255,0.05); padding: 8px 15px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
                            <label for="date-filter" style="color: var(--color-text-secondary); font-size: 0.85rem; font-weight: 500;">Choose Date:</label>
                            <input type="text" id="date-filter" name="date" value="<?php echo e($selectedDate); ?>" onchange="this.form.submit()" style="background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; color: #fff; padding: 5px 10px; font-size: 0.85rem; outline: none; cursor: pointer; width: 120px; text-align: center;">
                        </form>
                    </div>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <?php if ($successMsg): ?>
                        <div class="alert alert-success"><?php echo e($successMsg); ?></div>
                    <?php endif; ?>

                    <div class="table-card glass-panel span-full-row">
                        <?php
                        $checkinDeleteFormAction = "checkins.php?date=" . urlencode($selectedDate);
                        $checkinEmptyMessage = "No check-in records found for " . date('M d, Y', strtotime($selectedDate)) . ".";
                        include __DIR__ . '/../partials/checkin_list_table.php';
                        ?>
                    </div>
                </section>
            </div>
        </main>
        <?php $footerText = 'TGG Club Membership System. Secure Admin Portal.'; include __DIR__ . '/../partials/footer.php'; ?>
    <!-- Flatpickr JS (Served locally to satisfy Content Security Policy) -->
    <script src="../assets/js/flatpickr.min.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const eventDates = <?php echo json_encode($eventDates); ?>;
            
            flatpickr("#date-filter", {
                dateFormat: "Y-m-d",
                defaultDate: <?php echo json_encode($selectedDate); ?>,
                disableMobile: true,
                onDayCreate: function(dObj, dStr, fp, dayElem) {
                    const date = dayElem.dateObj;
                    const y = date.getFullYear();
                    const m = String(date.getMonth() + 1).padStart(2, '0');
                    const d = String(date.getDate()).padStart(2, '0');
                    const dateString = `${y}-${m}-${d}`;
                    
                    if (eventDates.includes(dateString)) {
                        dayElem.classList.add("has-event-day");
                        dayElem.setAttribute("title", "Scheduled Event(s) on this day");
                    }
                },
                onChange: function(selectedDates, dateStr, instance) {
                    instance.element.form.submit();
                }
            });
        });
    </script>
</body>
</html>
