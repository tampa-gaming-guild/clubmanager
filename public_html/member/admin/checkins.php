<?php
/**
 * Admin Check-In List Page
 * Displays a tabular report of check-ins for a selected date, ordered by first name.
 * Allows deleting check-ins.
 */
require_once (function() {
    $dir = dirname(dirname(dirname(__DIR__)));
    if (file_exists($dir . '/.env') && $lines = @file($dir . '/.env')) {
        foreach ($lines as $line) {
            if (preg_match('/^\s*BOOTSTRAP_PATH\s*=\s*["\']?(.*?)["\']?\s*$/', $line, $m)) {
                return $m[1];
            }
        }
    }
    return $dir . '/config/bootstrap.php';
})();

use App\Auth;
use App\CheckinService;
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

// "Add Missed Check-In" only makes sense for a date that's already happened --
// today's/future's check-ins should go through the normal live check-in flow.
$isPastDate = $selectedDate < date('Y-m-d');

// Check for success parameter in GET (Post-Redirect-Get pattern feedback)
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $successMsg = "Saved successfully.";
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
                $result = CheckinService::deleteCheckin($checkinId);
                if ($result['ok']) {
                    redirect("admin/checkins.php?date=" . urlencode($selectedDate) . "&success=1");
                } else {
                    $errorMsg = $result['error'];
                }
            } else {
                $errorMsg = "Invalid check-in ID.";
            }
        }
    }

    // Handle Check-In Edit (time/notes/guest name correction)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_checkin'])) {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            $errorMsg = "Invalid security token.";
        } else {
            $checkinId = (int)($_POST['checkin_id'] ?? 0);
            $newCheckedInAt = trim($_POST['checked_in_at'] ?? '');
            $newNotes = trim($_POST['notes'] ?? '') ?: null;
            $newGuestName = trim($_POST['guest_name'] ?? '') ?: null;
            if ($checkinId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $newCheckedInAt)) {
                $errorMsg = "Invalid check-in edit submission.";
            } else {
                $newCheckedInAt = date('Y-m-d H:i:s', strtotime($newCheckedInAt));
                $result = CheckinService::updateCheckin($checkinId, $newCheckedInAt, $newNotes, $newGuestName);
                if ($result['ok']) {
                    redirect("admin/checkins.php?date=" . urlencode($selectedDate) . "&success=1");
                } else {
                    $errorMsg = $result['error'];
                }
            }
        }
    }

    // Handle Add Missed Check-In (backdated, for a host who forgot to log one live)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_checkin'])) {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            $errorMsg = "Invalid security token.";
        } else {
            $memberContactId = (int)($_POST['member_contact_id'] ?? 0);
            $checkinTime = trim($_POST['checkin_time'] ?? '');
            $checkinNotes = trim($_POST['checkin_notes'] ?? '');

            if (!$isPastDate) {
                $errorMsg = "Missed check-ins can only be added for a past date.";
            } elseif ($memberContactId <= 0) {
                $errorMsg = "Select a member from the list.";
            } elseif (!preg_match('/^\d{2}:\d{2}$/', $checkinTime)) {
                $errorMsg = "Enter a valid check-in time.";
            } else {
                $checkedInAt = "{$selectedDate} {$checkinTime}:00";
                $result = CheckinService::checkIn($memberContactId, $checkinNotes, [], false, $checkedInAt);
                if ($result['ok']) {
                    redirect("admin/checkins.php?date=" . urlencode($selectedDate) . "&success=1");
                } else {
                    $errorMsg = $result['error'] ?? "Could not add check-in (member may need to renew or pay an entrance fee first).";
                }
            }
        }
    }

    // Earliest scheduled session's start time on the selected date, to default
    // the "Add Missed Check-In" time field to.
    $sessionStartStmt = $appDb->prepare("SELECT start_time FROM tgg_events WHERE DATE(start_time) = :date ORDER BY start_time ASC LIMIT 1");
    $sessionStartStmt->execute(['date' => $selectedDate]);
    $sessionStartTime = $sessionStartStmt->fetchColumn() ?: null;

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
    <?php include __DIR__ . '/../partials/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check-In List - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <!-- Flatpickr Datepicker (Served locally to satisfy Content Security Policy) -->
    <link rel="stylesheet" id="flatpickr-css" href="../assets/css/flatpickr.min.css" data-dark-href="../assets/css/flatpickr-dark.min.css">
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
            background: rgba(var(--overlay-rgb), 0.08) !important;
            color: var(--color-text-primary) !important;
            border-color: rgba(var(--overlay-rgb), 0.15) !important;
        }
        .flatpickr-day.selected,
        .flatpickr-day.selected:hover {
            background: var(--color-primary) !important;
            color: #fff !important;
            border-color: var(--color-primary) !important;
        }
        .flatpickr-day.today {
            border-color: rgba(var(--overlay-rgb), 0.3) !important;
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
                        <form method="GET" action="checkins.php" style="display: inline-flex; align-items: center; gap: 10px; background: rgba(var(--overlay-rgb),0.05); padding: 8px 15px; border-radius: 8px; border: 1px solid rgba(var(--overlay-rgb),0.1);">
                            <label for="date-filter" style="color: var(--color-text-secondary); font-size: 0.85rem; font-weight: 500;">Choose Date:</label>
                            <input type="text" id="date-filter" name="date" value="<?php echo e($selectedDate); ?>" onchange="this.form.submit()" style="background: rgba(0,0,0,0.2); border: 1px solid rgba(var(--overlay-rgb),0.2); border-radius: 4px; color: var(--color-text-primary); padding: 5px 10px; font-size: 0.85rem; outline: none; cursor: pointer; width: 120px; text-align: center;">
                        </form>
                    </div>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <?php if ($successMsg): ?>
                        <div class="alert alert-success"><?php echo e($successMsg); ?></div>
                    <?php endif; ?>

                    <?php if ($isPastDate): ?>
                    <div class="table-card glass-panel" style="margin-bottom: 20px;">
                        <h4 style="margin-top: 0;">Add Missed Check-In</h4>
                        <p class="description-text" style="margin-bottom: 15px;">For a host who forgot to check someone in during the visit. Adds a check-in for the date selected above (<?php echo e(date('M j, Y', strtotime($selectedDate))); ?>).</p>
                        <form method="POST" action="checkins.php?date=<?php echo urlencode($selectedDate); ?>" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
                            <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                            <div class="form-group" style="flex: 2; min-width: 220px; margin-bottom: 0; position: relative;">
                                <label style="display: block; font-size: 0.85rem; margin-bottom: 5px;">Member</label>
                                <input type="text" id="checkin-member-search" placeholder="Start typing a name..." autocomplete="off" required style="width: 100%;">
                                <input type="hidden" name="member_contact_id" id="checkin-member-id" value="">
                                <div id="checkin-member-dropdown" class="navbar-search-dropdown" style="display: none;"></div>
                            </div>
                            <div class="form-group" style="min-width: 130px; margin-bottom: 0;">
                                <label style="display: block; font-size: 0.85rem; margin-bottom: 5px;">Time</label>
                                <input type="time" name="checkin_time" required style="width: 100%;" value="<?php echo $sessionStartTime ? e(date('H:i', strtotime($sessionStartTime))) : ''; ?>">
                            </div>
                            <div class="form-group" style="flex: 2; min-width: 200px; margin-bottom: 0;">
                                <label style="display: block; font-size: 0.85rem; margin-bottom: 5px;">Notes (optional)</label>
                                <input type="text" name="checkin_notes" style="width: 100%;">
                            </div>
                            <button type="submit" name="add_checkin" class="btn btn-primary">Add Check-In</button>
                        </form>
                    </div>
                    <script>
                    (function () {
                        const input = document.getElementById('checkin-member-search');
                        const hiddenId = document.getElementById('checkin-member-id');
                        const dropdown = document.getElementById('checkin-member-dropdown');
                        let timer = null;
                        let results = [];
                        let activeIndex = -1;

                        // The form sits inside a .glass-panel card, whose backdrop-filter
                        // creates a new stacking context -- that traps the dropdown's z-index
                        // below the next .glass-panel card in the DOM regardless of z-index.
                        // Re-parenting to <body> and switching to position:fixed (positioned
                        // from the input's own coordinates) escapes it.
                        document.body.appendChild(dropdown);
                        dropdown.style.position = 'fixed';

                        function positionDropdown() {
                            const rect = input.getBoundingClientRect();
                            dropdown.style.top = (rect.bottom + 6) + 'px';
                            dropdown.style.left = rect.left + 'px';
                            dropdown.style.width = rect.width + 'px';
                        }

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

                        function select(member) {
                            input.value = member.display_name;
                            hiddenId.value = member.id;
                            close();
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
                                    select(m);
                                });
                                dropdown.appendChild(item);
                            });
                            if (members.length) positionDropdown();
                            dropdown.style.display = members.length ? 'block' : 'none';
                        }

                        window.addEventListener('scroll', function () {
                            if (dropdown.style.display === 'block') positionDropdown();
                        }, true);
                        window.addEventListener('resize', function () {
                            if (dropdown.style.display === 'block') positionDropdown();
                        });

                        input.addEventListener('input', function () {
                            hiddenId.value = ''; // any manual edit invalidates a prior selection
                            clearTimeout(timer);
                            const q = input.value.trim();
                            if (q.length < 3) { close(); return; }
                            timer = setTimeout(function () {
                                fetch('member-search.php?q=' + encodeURIComponent(q))
                                    .then(function (r) { return r.json(); })
                                    .then(open)
                                    .catch(close);
                            }, 300);
                        });

                        input.addEventListener('keydown', function (e) {
                            if (e.key === 'Escape') { close(); return; }
                            if (!results.length) return;
                            if (e.key === 'ArrowDown') {
                                e.preventDefault();
                                setActive(activeIndex < results.length - 1 ? activeIndex + 1 : 0);
                            } else if (e.key === 'ArrowUp') {
                                e.preventDefault();
                                setActive(activeIndex > 0 ? activeIndex - 1 : results.length - 1);
                            } else if (e.key === 'Enter' && activeIndex >= 0) {
                                e.preventDefault();
                                select(results[activeIndex]);
                            }
                        });

                        input.addEventListener('blur', function () {
                            // Delay so a mousedown selection (which also blurs the input) still fires first.
                            setTimeout(close, 150);
                        });
                    })();
                    </script>
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
