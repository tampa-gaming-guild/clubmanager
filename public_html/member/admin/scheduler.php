<?php
/**
 * Admin Session Scheduler
 * Allows administrators to schedule new sessions/events and manage existing ones.
 * Each event carries its own named volunteer slots; a slot's type (open/close/greeter)
 * determines which credit weight the volunteer earns.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Event;
use App\EventSlot;
use App\Database;

Auth::requirePermission('schedule events');

$errorMsg = null;
$successMsg = null;

// PRG: successful actions redirect back here with the flash message in GET,
// so refreshing the page never re-submits a create/update/delete.
if (isset($_GET['success'])) $successMsg = trim($_GET['success']);
if (isset($_GET['error']))   $errorMsg   = trim($_GET['error']);

// List view/pagination state. Read up front (not just where the list is rendered)
// so create/update/delete redirects below can carry it back to the same tab/page
// -- important for editing a past event, which would otherwise vanish from view
// when the redirect lands back on the "upcoming" default.
$validViews = ['upcoming', 'past', 'all'];
$view = in_array($_GET['view'] ?? '', $validViews, true) ? $_GET['view'] : 'upcoming';
$page = max(1, (int)($_GET['page'] ?? 1));
$eventsPerPage = 25;
$listStateQuery = http_build_query(array_filter([
    'view' => $view !== 'upcoming' ? $view : null,
    'page' => $page > 1 ? $page : null,
]));

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

// Events are single-day: forms submit one date plus start/end times. An end
// time at or before the start time is treated as running past midnight.
function build_event_times(string $date, string $startTime, string $endTime): array {
    $mysqlStart = "{$date} {$startTime}:00";
    $mysqlEnd = "{$date} {$endTime}:00";
    if (strtotime($mysqlEnd) <= strtotime($mysqlStart)) {
        $mysqlEnd = date('Y-m-d H:i:s', strtotime("+1 day", strtotime($mysqlEnd)));
    }
    return [$mysqlStart, $mysqlEnd];
}

// Slot definitions arrive as slots[n][id|label|type] parallel to the form rows.
function parse_slots_input(): array {
    $slots = [];
    foreach ($_POST['slots'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $slots[] = [
            'id' => isset($row['id']) && $row['id'] !== '' ? (int)$row['id'] : null,
            'label' => trim((string)($row['label'] ?? '')),
            'type' => (string)($row['type'] ?? 'open'),
        ];
    }
    return $slots;
}

// Handle Delete Event Action. The page's JS deletes via fetch (JSON response,
// row removed in place); the plain form POST path remains as the no-JS fallback.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete'])) {
    $isAjax = isset($_POST['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        if ($isAjax) {
            json_response(['success' => false, 'error' => 'Invalid security token.'], 403);
        }
        $errorMsg = "Invalid security token.";
    } else {
        $eventId = (int)($_POST['event_id'] ?? 0);
        if ($eventId <= 0 || !Event::getEvent($eventId)) {
            if ($isAjax) {
                json_response(['success' => false, 'error' => 'Event not found.'], 400);
            }
            $errorMsg = "Event not found.";
        } else {
            try {
                Event::deleteEvent($eventId);
                if ($isAjax) {
                    json_response(['success' => true]);
                }
                redirect('admin/scheduler.php?' . http_build_query(array_filter([
                    'success' => 'Event deleted successfully.',
                    'view' => $view !== 'upcoming' ? $view : null,
                    'page' => $page > 1 ? $page : null,
                ])));
            } catch (Exception $e) {
                if ($isAjax) {
                    json_response(['success' => false, 'error' => safe_err('Failed to delete event: ', $e)], 400);
                }
                $errorMsg = safe_err("Failed to delete event: ", $e);
            }
        }
    }
}

// Handle Add Event Action (single or recurring, per the Make Recurring checkbox).
// Success redirects (PRG); validation errors re-render so the form keeps its input.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token.";
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $eventDate = trim($_POST['event_date'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');
        $isRecurring = isset($_POST['recurring']);

        if (empty($title) || empty($eventDate) || empty($startTime) || empty($endTime)) {
            $errorMsg = "Event Title, Date, Start Time, and End Time are required.";
        } elseif (!$isRecurring) {
            try {
                [$mysqlStart, $mysqlEnd] = build_event_times($eventDate, $startTime, $endTime);

                Event::createEvent($title, $description, $mysqlStart, $mysqlEnd, parse_slots_input());
                redirect('admin/scheduler.php?' . http_build_query(['success' => 'New session scheduled successfully!']));
            } catch (Exception $e) {
                $errorMsg = safe_err("Scheduling failed: ", $e);
            }
        } else {
            $recurrenceEndInput = trim($_POST['recurrence_end_date'] ?? '');
            $recurrenceType = $_POST['recurrence_type'] ?? 'weekly';
            $dayOfWeek = (int)($_POST['day_of_week'] ?? 0);
            $monthlyWeeks = $_POST['monthly_weeks'] ?? [];

            $startDate = strtotime($eventDate);
            $maxEnd = strtotime('+4 months', $startDate);
            // End date is optional and defaults to the maximum window (4 months out)
            $recurrenceEnd = $recurrenceEndInput !== '' ? strtotime($recurrenceEndInput) : $maxEnd;

            if ($recurrenceEnd < $startDate) {
                $errorMsg = "End Date must be on or after the Start Date.";
            } elseif ($recurrenceEnd > $maxEnd) {
                $errorMsg = "End Date cannot be more than 4 months after the Start Date.";
            } elseif ($recurrenceType === 'monthly' && empty($monthlyWeeks)) {
                $errorMsg = "Please select at least one week of the month for monthly recurrence.";
            } else {
                try {
                    $slots = parse_slots_input();
                    $currentDate = $startDate;
                    $insertedCount = 0;

                    while ($currentDate <= $recurrenceEnd) {
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
                            [$mysqlStart, $mysqlEnd] = build_event_times(date('Y-m-d', $currentDate), $startTime, $endTime);
                            Event::createEvent($title, $description, $mysqlStart, $mysqlEnd, $slots);
                            $insertedCount++;
                        }

                        $currentDate = strtotime("+1 day", $currentDate);
                    }

                    redirect('admin/scheduler.php?' . http_build_query(['success' => "Successfully scheduled {$insertedCount} recurring events!"]));
                } catch (Exception $e) {
                    $errorMsg = safe_err("Failed to schedule recurring events: ", $e);
                }
            }
        }
    }
}

// Handle Edit Event Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token.";
    } else {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $eventDate = trim($_POST['event_date'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');

        if (empty($title) || empty($eventDate) || empty($startTime) || empty($endTime)) {
            $errorMsg = "Event Title, Date, Start Time, and End Time are required.";
        } else {
            try {
                [$mysqlStart, $mysqlEnd] = build_event_times($eventDate, $startTime, $endTime);

                Event::updateEvent($eventId, $title, $description, $mysqlStart, $mysqlEnd, parse_slots_input());
                redirect('admin/scheduler.php?' . http_build_query(array_filter([
                    'success' => 'Event updated successfully!',
                    'view' => $view !== 'upcoming' ? $view : null,
                    'page' => $page > 1 ? $page : null,
                ])));
            } catch (Exception $e) {
                $errorMsg = safe_err("Update failed: ", $e);
                // Re-open the edit card so the admin can correct and retry
                $_GET['edit'] = (string)$eventId;
            }
        }
    }
}

// Edit mode: load the event being edited (?edit=<id>)
$editEvent = null;
$editSlots = [];
$editFilledBySlotId = [];
if (isset($_GET['edit'])) {
    $editEvent = Event::getEvent((int)$_GET['edit']);
    if ($editEvent) {
        $editId = (int)$editEvent['id'];
        $editSlots = EventSlot::getSlotsForEvent($editId);
        foreach (Event::getVolunteers($editId) as $vol) {
            $editFilledBySlotId[(int)$vol['slot_id']] = $vol['display_name'];
        }
    } else {
        $errorMsg = $errorMsg ?? "Event not found.";
    }
}

// Fetch events for the current view/page, with filled/total slot counts batched up front.
// Upcoming shows soonest-first; Past and All show most-recent-first.
$slotTotals = [];
$slotFilled = [];
$totalEvents = 0;
$totalPages = 0;
try {
    $appDb = Database::getAppConnection();
    $today = date('Y-m-d 00:00:00');

    if ($view === 'upcoming') {
        $where = 'WHERE start_time >= :today';
        $order = 'start_time ASC';
        $params = ['today' => $today];
    } elseif ($view === 'past') {
        $where = 'WHERE start_time < :today';
        $order = 'start_time DESC';
        $params = ['today' => $today];
    } else {
        $where = '';
        $order = 'start_time DESC';
        $params = [];
    }

    $countStmt = $appDb->prepare("SELECT COUNT(*) FROM tgg_events $where");
    $countStmt->execute($params);
    $totalEvents = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($totalEvents / $eventsPerPage);
    $offset = ($page - 1) * $eventsPerPage;

    $eventsStmt = $appDb->prepare("
        SELECT id, title, description, start_time, end_time FROM tgg_events
        $where
        ORDER BY $order
        LIMIT $eventsPerPage OFFSET $offset
    ");
    $eventsStmt->execute($params);
    $events = $eventsStmt->fetchAll();

    $slotTotals = $appDb->query("
        SELECT event_id, COUNT(*) FROM tgg_event_slots GROUP BY event_id
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    $slotFilled = $appDb->query("
        SELECT sl.event_id, COUNT(s.id)
        FROM tgg_event_slots sl
        JOIN tgg_volunteer_signups s ON s.slot_id = sl.id
        GROUP BY sl.event_id
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $events = [];
    $errorMsg = safe_err("Unable to load events: ", $e);
}

/**
 * Render the editable slot rows for a create/edit form.
 * $slots rows: ['id' => int|null, 'label' => string, 'type' => string]
 * $filledBySlotId maps slot id => volunteer display name (edit form only).
 */
function render_slot_rows(array $slots, array $filledBySlotId = []): void {
    // Explicit row indexes keep the three fields of one row under the same key
    // (a bare slots[] would give each input its own auto-index).
    foreach (array_values($slots) as $i => $slot) {
        $i = (int)$i;
        $slotId = $slot['id'] ?? null;
        $volName = $slotId !== null ? ($filledBySlotId[$slotId] ?? null) : null;
        ?>
        <div class="slot-row" style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px;">
            <input type="hidden" name="slots[<?php echo (int)$i; ?>][id]" value="<?php echo $slotId !== null ? (int)$slotId : ''; ?>">
            <input type="text" name="slots[<?php echo (int)$i; ?>][label]" required placeholder="Slot name" value="<?php echo e($slot['label']); ?>" style="flex: 1; min-width: 0;">
            <select name="slots[<?php echo (int)$i; ?>][type]" style="width: 110px; flex-shrink: 0;">
                <?php foreach (EventSlot::TYPES as $type): ?>
                    <option value="<?php echo $type; ?>" <?php echo $slot['type'] === $type ? 'selected' : ''; ?>><?php echo ucfirst($type); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($volName !== null): ?>
                <span class="slot-filled-hint" title="Filled by <?php echo e($volName); ?>" style="font-size: 0.75rem; color: var(--color-text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 120px;">👤 <?php echo e($volName); ?></span>
                <button type="button" class="btn btn-secondary btn-small slot-remove" disabled title="A volunteer is signed up for this slot. Cancel the signup on the Volunteers page first." style="flex-shrink: 0;">×</button>
            <?php else: ?>
                <button type="button" class="btn btn-danger btn-small slot-remove" title="Remove slot" style="flex-shrink: 0;">×</button>
            <?php endif; ?>
        </div>
        <?php
    }
}

// Slot rows to show on the create form: repost after a failed create, else defaults
$createFormSlots = [];
if (isset($_POST['action_create'])) {
    foreach ($_POST['slots'] ?? [] as $row) {
        if (is_array($row)) {
            $createFormSlots[] = ['id' => null, 'label' => (string)($row['label'] ?? ''), 'type' => (string)($row['type'] ?? 'open')];
        }
    }
}
if (empty($createFormSlots)) {
    foreach (EventSlot::DEFAULT_SLOTS as $slot) {
        $createFormSlots[] = ['id' => null, 'label' => $slot['label'], 'type' => $slot['type']];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event & Session Scheduler - Admin</title>
    <link rel="shortcut icon" href="../favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="../favicon.png">
    <link rel="apple-touch-icon" href="../favicon.png">
    <link rel="manifest" href="../manifest.json">
    <link rel="stylesheet" href="../assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <style>
        .main-content {
            max-width: 1600px;
        }
        th.sortable {
            cursor: pointer;
            position: relative;
            user-select: none;
        }
        th.sortable:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        th.sortable::after {
            content: ' ↕';
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.3);
        }
        th.sortable.asc::after {
            content: ' ▲';
            color: var(--color-primary);
        }
        th.sortable.desc::after {
            content: ' ▼';
            color: var(--color-primary);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $navAdminArea = true; $navActive = 'admin'; include __DIR__ . '/../partials/navbar.php'; ?>

        <main class="main-content">
            <div class="admin-grid">

                <?php include 'sidebar.php'; ?>

                <!-- Work Area: Scheduler -->
                <section class="admin-workspace glass-panel">
                    <h2>Session & Event Scheduler</h2>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <?php if ($successMsg): ?>
                        <div class="alert alert-success"><?php echo e($successMsg); ?></div>
                    <?php endif; ?>

                    <?php if ($editEvent): ?>
                        <!-- Edit Event Card -->
                        <div class="scheduler-form-card" style="margin-bottom: 25px; border: 1px solid var(--color-primary, #6366f1);">
                            <h3>Edit Event — <?php echo e($editEvent['title']); ?></h3>
                            <form action="scheduler.php<?php echo $listStateQuery !== '' ? '?' . e($listStateQuery) : ''; ?>" method="POST" class="standard-form">
                                <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                <input type="hidden" name="event_id" value="<?php echo (int)$editEvent['id']; ?>">

                                <div class="form-group">
                                    <label for="edit_title">Event Title</label>
                                    <input type="text" id="edit_title" name="title" required value="<?php echo e($editEvent['title']); ?>">
                                </div>

                                <div class="form-group">
                                    <label for="edit_description">Description (Optional)</label>
                                    <textarea id="edit_description" name="description"><?php echo e($editEvent['description'] ?? ''); ?></textarea>
                                </div>

                                <div class="form-group">
                                    <label for="edit_event_date">Event Date</label>
                                    <input type="date" id="edit_event_date" name="event_date" required value="<?php echo date('Y-m-d', strtotime($editEvent['start_time'])); ?>">
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="edit_start_time">Start Time</label>
                                        <input type="time" id="edit_start_time" name="start_time" required value="<?php echo date('H:i', strtotime($editEvent['start_time'])); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="edit_end_time">End Time</label>
                                        <input type="time" id="edit_end_time" name="end_time" required value="<?php echo date('H:i', strtotime($editEvent['end_time'])); ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Volunteer Slots</label>
                                    <p style="font-size: 0.75rem; color: var(--color-text-secondary); margin: 0 0 8px;">The type determines which credit weight the volunteer earns.</p>
                                    <div class="slot-rows" id="edit-slot-rows">
                                        <?php
                                            $editSlotRows = array_map(fn($s) => ['id' => (int)$s['id'], 'label' => $s['slot_label'], 'type' => $s['slot_type']], $editSlots);
                                            render_slot_rows($editSlotRows, $editFilledBySlotId);
                                        ?>
                                    </div>
                                    <button type="button" class="btn btn-secondary btn-small" onclick="addSlotRow('edit-slot-rows')">+ Add Slot</button>
                                </div>

                                <div style="display: flex; gap: 10px;">
                                    <button type="submit" name="action_update" class="btn btn-primary" style="flex: 1;">Save Changes</button>
                                    <a href="scheduler.php<?php echo $listStateQuery !== '' ? '?' . e($listStateQuery) : ''; ?>" class="btn btn-secondary" style="flex-shrink: 0;">Cancel</a>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>

                    <div class="scheduler-split">

                        <div class="scheduler-forms-container" style="display: flex; flex-direction: column; gap: 25px;">
                            <!-- Form: Create New Event (single or recurring) -->
                            <div class="scheduler-form-card">
                                <h3>Schedule a New Event</h3>
                                <form action="scheduler.php" method="POST" class="standard-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">

                                    <div class="form-group">
                                        <label for="title">Event Title</label>
                                        <input type="text" id="title" name="title" required value="<?php echo e($_POST['title'] ?? ''); ?>" placeholder="e.g. Wednesday Gaming">
                                    </div>

                                    <div class="form-group">
                                        <label for="description">Description (Optional)</label>
                                        <textarea id="description" name="description" placeholder="Provide event details..."><?php echo e($_POST['description'] ?? ''); ?></textarea>
                                    </div>

                                    <div class="form-group">
                                        <label for="event_date" id="event-date-label">Event Date</label>
                                        <input type="date" id="event_date" name="event_date" required value="<?php echo e($_POST['event_date'] ?? date('Y-m-d')); ?>">
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="start_time">Start Time</label>
                                            <input type="time" id="start_time" name="start_time" required value="<?php echo e($_POST['start_time'] ?? '17:30'); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="end_time">End Time</label>
                                            <input type="time" id="end_time" name="end_time" required value="<?php echo e($_POST['end_time'] ?? '23:00'); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group checkbox-group" style="display: flex; align-items: center; gap: 10px;">
                                        <input type="checkbox" id="recurring" name="recurring" onchange="toggleRecurring()" <?php echo isset($_POST['recurring']) ? 'checked' : ''; ?>>
                                        <label for="recurring" style="text-transform: none; font-weight: normal; margin-bottom: 0; cursor: pointer;">Make Recurring</label>
                                    </div>

                                    <div id="recurrence-options" style="display: none; background: rgba(0, 0, 0, 0.15); padding: 12px; border-radius: 8px; border: 1px solid var(--border-glass); margin-bottom: 15px;">
                                        <div class="form-group">
                                            <label for="recurrence_end_date">Recurrence End Date</label>
                                            <input type="date" id="recurrence_end_date" name="recurrence_end_date" value="<?php echo e($_POST['recurrence_end_date'] ?? ''); ?>">
                                            <p style="font-size: 0.75rem; color: var(--color-text-secondary); margin: 4px 0 0;">Events are generated from the Start Date through this date. Defaults to 4 months out (the maximum).</p>
                                        </div>

                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="recurrence_type">Recurrence Type</label>
                                                <select id="recurrence_type" name="recurrence_type" onchange="toggleRecurrenceFields()">
                                                    <option value="weekly" <?php echo ($_POST['recurrence_type'] ?? '') !== 'monthly' ? 'selected' : ''; ?>>Weekly (Every week)</option>
                                                    <option value="monthly" <?php echo ($_POST['recurrence_type'] ?? '') === 'monthly' ? 'selected' : ''; ?>>Monthly (Specific weeks)</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label for="day_of_week">Day of the Week</label>
                                                <select id="day_of_week" name="day_of_week">
                                                    <?php
                                                        $selectedDow = (string)($_POST['day_of_week'] ?? '3');
                                                        foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $dowIdx => $dowName):
                                                    ?>
                                                        <option value="<?php echo $dowIdx; ?>" <?php echo (string)$dowIdx === $selectedDow ? 'selected' : ''; ?>><?php echo $dowName; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div id="monthly-weeks-container" class="form-group" style="display: none; margin-bottom: 0;">
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
                                    </div>

                                    <div class="form-group">
                                        <label>Volunteer Slots</label>
                                        <p style="font-size: 0.75rem; color: var(--color-text-secondary); margin: 0 0 8px;">The type determines which credit weight the volunteer earns. Recurring events all get these slots.</p>
                                        <div class="slot-rows" id="create-slot-rows">
                                            <?php render_slot_rows($createFormSlots); ?>
                                        </div>
                                        <button type="button" class="btn btn-secondary btn-small" onclick="addSlotRow('create-slot-rows')">+ Add Slot</button>
                                    </div>

                                    <button type="submit" name="action_create" id="create-submit" class="btn btn-primary btn-block">Add Event to Calendar</button>
                                </form>
                            </div>
                        </div>

                        <script>
                            // Make Recurring checkbox: swaps the form between single-event and
                            // recurring-series mode.
                            function toggleRecurring() {
                                const recurring = document.getElementById('recurring').checked;
                                document.getElementById('recurrence-options').style.display = recurring ? 'block' : 'none';
                                document.getElementById('event-date-label').textContent = recurring ? 'Start Date' : 'Event Date';
                                document.getElementById('create-submit').textContent = recurring ? 'Add Recurring Events to Calendar' : 'Add Event to Calendar';
                                if (recurring) {
                                    updateRecurrenceEndLimit();
                                    toggleRecurrenceFields();
                                }
                            }

                            function toggleRecurrenceFields() {
                                const type = document.getElementById('recurrence_type').value;
                                const monthlyOpts = document.getElementById('monthly-weeks-container');
                                if (type === 'monthly') {
                                    monthlyOpts.style.display = 'block';
                                } else {
                                    monthlyOpts.style.display = 'none';
                                }
                            }

                            // The recurrence window is capped at 4 months from the start date;
                            // mirror the server-side limit in the date picker's min/max and
                            // default the end date to that maximum when it's empty.
                            function updateRecurrenceEndLimit() {
                                const start = document.getElementById('event_date').value;
                                const endInput = document.getElementById('recurrence_end_date');
                                if (!start) return;
                                const max = new Date(start + 'T00:00:00');
                                max.setMonth(max.getMonth() + 4);
                                endInput.min = start;
                                endInput.max = max.toISOString().slice(0, 10);
                                if (!endInput.value || endInput.value > endInput.max) {
                                    endInput.value = endInput.max;
                                }
                            }

                            document.getElementById('event_date').addEventListener('change', updateRecurrenceEndLimit);
                            document.addEventListener('DOMContentLoaded', toggleRecurring);

                            // Indexes only need to be unique per form; start well above
                            // anything the server rendered.
                            let slotRowIdx = 1000;
                            function addSlotRow(containerId) {
                                const container = document.getElementById(containerId);
                                const i = slotRowIdx++;
                                const row = document.createElement('div');
                                row.className = 'slot-row';
                                row.style.cssText = 'display: flex; gap: 8px; align-items: center; margin-bottom: 8px;';
                                row.innerHTML = `
                                    <input type="hidden" name="slots[${i}][id]" value="">
                                    <input type="text" name="slots[${i}][label]" required placeholder="Slot name" style="flex: 1; min-width: 0;">
                                    <select name="slots[${i}][type]" style="width: 110px; flex-shrink: 0;">
                                        <option value="open">Open</option>
                                        <option value="close">Close</option>
                                        <option value="greeter">Greeter</option>
                                    </select>
                                    <button type="button" class="btn btn-danger btn-small slot-remove" title="Remove slot" style="flex-shrink: 0;">×</button>`;
                                container.appendChild(row);
                                row.querySelector('input[type="text"]').focus();
                            }

                            document.addEventListener('click', function(e) {
                                if (e.target.classList.contains('slot-remove') && !e.target.disabled) {
                                    e.target.closest('.slot-row').remove();
                                }
                            });

                            // --- Sortable Title / Date columns (client-side) ---
                            document.addEventListener('DOMContentLoaded', function() {
                                const getCellValue = (tr, idx) => {
                                    const cell = tr.children[idx];
                                    return cell.getAttribute('data-sort') || cell.innerText || cell.textContent;
                                };

                                const comparer = (idx, asc) => (a, b) => ((v1, v2) =>
                                    v1 !== '' && v2 !== '' && !isNaN(v1) && !isNaN(v2) ? v1 - v2 : v1.toString().localeCompare(v2)
                                )(getCellValue(asc ? a : b, idx), getCellValue(asc ? b : a, idx));

                                document.querySelectorAll('#events-table th.sortable').forEach(th => th.addEventListener('click', (() => {
                                    const tbody = th.closest('table').querySelector('tbody');
                                    const rows = Array.from(tbody.querySelectorAll('tr'));
                                    const isAsc = th.classList.contains('asc');

                                    th.closest('tr').querySelectorAll('th').forEach(header => {
                                        if (header !== th) header.classList.remove('asc', 'desc');
                                    });
                                    th.classList.toggle('asc', !isAsc);
                                    th.classList.toggle('desc', isAsc);

                                    rows.sort(comparer(Array.from(th.parentNode.children).indexOf(th), !isAsc))
                                        .forEach(tr => tbody.appendChild(tr));
                                })));
                            });

                            // --- AJAX delete: remove the row in place instead of reloading ---
                            const CSRF_TOKEN = <?php echo json_encode(get_csrf_token()); ?>;

                            function showListError(message) {
                                const card = document.querySelector('.scheduler-list-card');
                                const existing = card.querySelector('.alert');
                                if (existing) existing.remove();
                                const alert = document.createElement('div');
                                alert.className = 'alert alert-danger';
                                alert.textContent = message;
                                card.insertBefore(alert, card.querySelector('.admin-table-container'));
                            }

                            document.addEventListener('submit', async function(e) {
                                const form = e.target.closest('.delete-event-form');
                                if (!form) return;
                                e.preventDefault();

                                if (!(await confirmDialog('Are you sure you want to delete this event?', { confirmText: 'Delete' }))) return;

                                const row = form.closest('tr');
                                try {
                                    const resp = await fetch('scheduler.php', {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/x-www-form-urlencoded',
                                            'X-Requested-With': 'XMLHttpRequest'
                                        },
                                        body: new URLSearchParams({
                                            action_delete: '1',
                                            ajax: '1',
                                            event_id: form.querySelector('input[name="event_id"]').value,
                                            csrf_token: CSRF_TOKEN
                                        })
                                    });
                                    const data = await resp.json();
                                    if (data.success) {
                                        const tbody = row.closest('tbody');
                                        row.remove();
                                        if (!tbody.querySelector('tr')) {
                                            tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: var(--color-text-secondary); padding: 20px;">No events scheduled yet. Add one using the form.</td></tr>';
                                        }
                                    } else {
                                        showListError(data.error || 'Failed to delete event.');
                                    }
                                } catch (err) {
                                    showListError('Failed to delete event: network error.');
                                }
                            });
                        </script>

                        <!-- Table: Existing Scheduled Events -->
                        <div class="scheduler-list-card">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
                                <h3 style="margin: 0;"><?php echo $view === 'past' ? 'Past Events' : ($view === 'all' ? 'All Events' : 'Upcoming Events'); ?></h3>
                                <div style="display: flex; gap: 6px;">
                                    <a href="scheduler.php?view=upcoming" class="btn btn-small <?php echo $view === 'upcoming' ? 'btn-primary' : 'btn-secondary'; ?>">Upcoming</a>
                                    <a href="scheduler.php?view=past" class="btn btn-small <?php echo $view === 'past' ? 'btn-primary' : 'btn-secondary'; ?>">Past</a>
                                    <a href="scheduler.php?view=all" class="btn btn-small <?php echo $view === 'all' ? 'btn-primary' : 'btn-secondary'; ?>">All</a>
                                </div>
                            </div>

                            <?php if (empty($events)): ?>
                                <p class="empty-text"><?php echo $view === 'upcoming' ? 'No events scheduled yet. Add one using the form.' : 'No events found.'; ?></p>
                            <?php else: ?>
                                <div class="admin-table-container">
                                    <table class="admin-table" id="events-table">
                                        <thead>
                                            <tr>
                                                <th class="sortable">Title</th>
                                                <th class="sortable <?php echo $view === 'upcoming' ? 'asc' : 'desc'; ?>">Date & Time</th>
                                                <th>Vols</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($events as $evt): ?>
                                                <?php $eid = (int)$evt['id']; ?>
                                                <tr>
                                                    <td><strong><?php echo e($evt['title']); ?></strong></td>
                                                    <td data-sort="<?php echo e($evt['start_time']); ?>">
                                                        <span class="table-datetime">
                                                            <?php echo date('m/d/y', strtotime($evt['start_time'])); ?><br>
                                                            <?php echo date('g:i A', strtotime($evt['start_time'])); ?> - <?php echo date('g:i A', strtotime($evt['end_time'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php echo (int)($slotFilled[$eid] ?? 0); ?> / <?php echo (int)($slotTotals[$eid] ?? 0); ?>
                                                    </td>
                                                    <td>
                                                        <div style="display: flex; gap: 6px;">
                                                            <a href="scheduler.php?edit=<?php echo $eid; ?><?php echo $listStateQuery !== '' ? '&' . e($listStateQuery) : ''; ?>" class="btn btn-secondary btn-small">Edit</a>
                                                            <form action="scheduler.php<?php echo $listStateQuery !== '' ? '?' . e($listStateQuery) : ''; ?>" method="POST" class="inline-form delete-event-form">
                                                                <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                                                <input type="hidden" name="event_id" value="<?php echo $eid; ?>">
                                                                <button type="submit" name="action_delete" class="btn btn-danger btn-small">Delete</button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <?php if ($totalPages > 1): ?>
                                    <div class="pagination" style="display: flex; gap: 10px; justify-content: center; align-items: center; margin-top: 16px;">
                                        <?php $pageBase = 'scheduler.php?' . ($view !== 'upcoming' ? 'view=' . urlencode($view) . '&' : ''); ?>
                                        <?php if ($page > 1): ?>
                                            <a href="<?php echo e($pageBase . 'page=' . ($page - 1)); ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 0.85rem;">&laquo; Previous</a>
                                        <?php endif; ?>
                                        <span style="font-size: 0.85rem; color: var(--color-text-secondary);">Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $totalEvents; ?> events)</span>
                                        <?php if ($page < $totalPages): ?>
                                            <a href="<?php echo e($pageBase . 'page=' . ($page + 1)); ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 0.85rem;">Next &raquo;</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>
        </main>

        <?php include __DIR__ . '/../partials/footer.php'; ?>

    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('../sw.js')
                .then(reg => console.log('Service Worker registered'))
                .catch(err => console.error('Service Worker registration failed', err));
        });
    }
    </script>
</body>
</html>
