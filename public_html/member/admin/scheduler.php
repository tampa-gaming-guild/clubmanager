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

// Handle Delete Event Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token.";
    } else {
        $eventId = (int)($_POST['event_id'] ?? 0);
        try {
            Event::deleteEvent($eventId);
            $successMsg = "Event deleted successfully.";
        } catch (Exception $e) {
            $errorMsg = safe_err("Failed to delete event: ", $e);
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
        $eventDate = trim($_POST['event_date'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');

        if (empty($title) || empty($eventDate) || empty($startTime) || empty($endTime)) {
            $errorMsg = "Event Title, Date, Start Time, and End Time are required.";
        } else {
            try {
                [$mysqlStart, $mysqlEnd] = build_event_times($eventDate, $startTime, $endTime);

                Event::createEvent($title, $description, $mysqlStart, $mysqlEnd, parse_slots_input());
                $successMsg = "New session scheduled successfully!";

                // Clear post inputs on success
                $_POST = [];
            } catch (Exception $e) {
                $errorMsg = safe_err("Scheduling failed: ", $e);
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
                $successMsg = "Event updated successfully!";
            } catch (Exception $e) {
                $errorMsg = safe_err("Update failed: ", $e);
                // Re-open the edit card so the admin can correct and retry
                $_GET['edit'] = (string)$eventId;
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
                        [$mysqlStart, $mysqlEnd] = build_event_times(date('Y-m-d', $currentDate), $startTimeInput, $endTimeInput);

                        // Recurring events always get the standard Open/Close slots
                        Event::createEvent($title, $description, $mysqlStart, $mysqlEnd);
                        $insertedCount++;
                    }

                    $currentDate = strtotime("+1 day", $currentDate);
                }

                $successMsg = "Successfully scheduled {$insertedCount} recurring events populated 4 months in advance!";
                $_POST = [];
            } catch (Exception $e) {
                $errorMsg = safe_err("Failed to schedule recurring events: ", $e);
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

// Fetch all events for display, with filled/total slot counts batched up front
$slotTotals = [];
$slotFilled = [];
try {
    $events = Event::getEvents();
    $appDb = Database::getAppConnection();
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
        $slotId = $slot['id'] ?? null;
        $volName = $slotId !== null ? ($filledBySlotId[$slotId] ?? null) : null;
        ?>
        <div class="slot-row" style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px;">
            <input type="hidden" name="slots[<?php echo $i; ?>][id]" value="<?php echo $slotId !== null ? (int)$slotId : ''; ?>">
            <input type="text" name="slots[<?php echo $i; ?>][label]" required placeholder="Slot name" value="<?php echo e($slot['label']); ?>" style="flex: 1; min-width: 0;">
            <select name="slots[<?php echo $i; ?>][type]" style="width: 110px; flex-shrink: 0;">
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
                            <form action="scheduler.php" method="POST" class="standard-form">
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
                                    <a href="scheduler.php" class="btn btn-secondary" style="flex-shrink: 0;">Cancel</a>
                                </div>
                            </form>
                        </div>
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

                                    <div class="form-group">
                                        <label for="event_date">Event Date</label>
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

                                    <div class="form-group">
                                        <label>Volunteer Slots</label>
                                        <p style="font-size: 0.75rem; color: var(--color-text-secondary); margin: 0 0 8px;">The type determines which credit weight the volunteer earns.</p>
                                        <div class="slot-rows" id="create-slot-rows">
                                            <?php render_slot_rows($createFormSlots); ?>
                                        </div>
                                        <button type="button" class="btn btn-secondary btn-small" onclick="addSlotRow('create-slot-rows')">+ Add Slot</button>
                                    </div>

                                    <button type="submit" name="action_create" class="btn btn-primary btn-block">Add Event to Calendar</button>
                                </form>
                            </div>

                            <!-- Form: Create Recurring Events -->
                            <div class="scheduler-form-card">
                                <h3>Schedule Recurring Events</h3>
                                <p style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 15px;">Automatically populates events for 4 months starting from the selected date. Each event is created with the standard Open + Close volunteer slots.</p>
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

                                    <div class="form-group">
                                        <label for="rec_start_date">Recurrence Start Date</label>
                                        <input type="date" id="rec_start_date" name="start_date" required value="<?php echo date('Y-m-d'); ?>">
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="rec_start_time">Start Time</label>
                                            <input type="time" id="rec_start_time" name="start_time" required value="17:30">
                                        </div>
                                        <div class="form-group">
                                            <label for="rec_end_time">End Time</label>
                                            <input type="time" id="rec_end_time" name="end_time" required value="23:00">
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
                                                <?php $eid = (int)$evt['id']; ?>
                                                <tr>
                                                    <td><strong><?php echo e($evt['title']); ?></strong></td>
                                                    <td>
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
                                                            <a href="scheduler.php?edit=<?php echo $eid; ?>" class="btn btn-secondary btn-small">Edit</a>
                                                            <form action="scheduler.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this event?');" class="inline-form">
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
