<?php
/**
 * Volunteer Schedule (requires login -- signing up already does, so browsing
 * the schedule now does too). Offers three view modes via ?view=:
 *   list     - flat list grouped by event, with Upcoming/All filter
 *   calendar - month-grid + day-panel signup UI (formerly on calendar.php)
 *   combo    - month-grid and list side by side (default)
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Event;
use App\EventSlot;
use App\Auth;
use App\MembershipService;

Auth::requireAuth();

$errorMsg = null;
$successMsg = null;

// PRG: read flash messages passed back via GET after a redirect
if (isset($_GET['success'])) $successMsg = trim($_GET['success']);
if (isset($_GET['error']))   $errorMsg   = trim($_GET['error']);

$view = in_array($_GET['view'] ?? '', ['list', 'calendar', 'combo'], true) ? $_GET['view'] : 'combo';
$filter = ($_GET['filter'] ?? 'upcoming') === 'all' ? 'all' : 'upcoming';

// Build a link back to this page, preserving all current query params except
// flash messages, with the given overrides applied on top.
$baseParams = $_GET;
unset($baseParams['success'], $baseParams['error'], $baseParams['ajax']);
$buildUrl = function (array $overrides) use ($baseParams): string {
    $qp = array_filter(array_merge($baseParams, $overrides), fn($v) => $v !== null && $v !== '');
    return 'volunteers.php?' . http_build_query($qp);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = \App\VolunteerSignupRequest::handle($_POST);

    // PRG: redirect so a page refresh doesn't resubmit the form.
    // The form action already embeds the active view/month/year/selected/filter
    // params (via $buildUrl), so they're already in $_GET here.
    $qp = $_GET;
    unset($qp['success'], $qp['error']);
    if ($result['success']) $qp['success'] = $result['success'];
    if ($result['error'])   $qp['error']   = $result['error'];
    redirect('volunteers.php?' . http_build_query($qp));
}

$allActiveMembers = [];
if (has_permission('manage hosting')) {
    try {
        $allActiveMembers = MembershipService::getMembersList();
    } catch (Exception $e) {
        // Fallback to empty
    }
}

// Month/year (used by calendar & combo views; harmless default for list view)
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

$monthStart = "{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01 00:00:00";
$monthEnd = date('Y-m-t 23:59:59', strtotime($monthStart));
$monthLabel = date('F Y', strtotime($monthStart));

$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

// Raw ?selected= (unconstrained -- used by list view's highlight-a-row logic)
$selectedRaw = null;
if (isset($_GET['selected']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['selected'])) {
    $selectedRaw = $_GET['selected'];
}

// Month-constrained ?selected= -- used by calendar/combo grid selection, so a
// stale ?selected= from a different month (e.g. after Prev/Next) never
// matches a same-day-number event.
$selectedDay = null;
if ($selectedRaw !== null && (int)date('n', strtotime($selectedRaw)) === $month && (int)date('Y', strtotime($selectedRaw)) === $year) {
    $selectedDay = $selectedRaw;
}

// Always fetched -- used by the desktop List view (when active) and by the
// mobile fallback list, which shows the flat list regardless of the active
// desktop $view (see the mobile-list-view markup below).
$listEvents = [];
$listSlotsByEvent = [];
try {
    $allEvents = Event::getEvents();
    $today = date('Y-m-d 00:00:00');

    foreach ($allEvents as $evt) {
        if ($filter === 'upcoming') {
            $evtDate = date('Y-m-d', strtotime($evt['start_time']));
            $isHighlighted = $selectedRaw !== null && $selectedRaw === $evtDate;
            if ($evt['start_time'] >= $today || $isHighlighted) {
                $listEvents[] = $evt;
            }
        } else {
            $listEvents[] = $evt;
        }
    }

    $listSlotsByEvent = EventSlot::getSlotsForEvents(array_column($listEvents, 'id'));
} catch (Exception $e) {
    $listEvents = [];
    $listSlotsByEvent = [];
    $errorMsg = safe_err("Unable to load schedule: ", $e);
}

$monthEvents = [];
$eventsByDay = [];
$selectedEvents = [];
$slotsByEvent = [];
$volunteerBySlot = [];

if ($view !== 'list') {
    // calendar or combo view
    try {
        $monthEvents = Event::getEvents($monthStart, $monthEnd);
    } catch (Exception $e) {
        $monthEvents = [];
        $errorMsg = safe_err("Unable to load events: ", $e);
    }

    foreach ($monthEvents as $evt) {
        $day = (int)date('d', strtotime($evt['start_time']));
        $eventsByDay[$day][] = $evt;
    }

    $selectedEvents = $selectedDay
        ? array_values(array_filter($monthEvents, fn($e) => date('Y-m-d', strtotime($e['start_time'])) === $selectedDay))
        : [];

    if (!empty($monthEvents)) {
        try {
            $appDb = App\Database::getAppConnection();
            $eventIds = array_column($monthEvents, 'id');
            $slotsByEvent = EventSlot::getSlotsForEvents($eventIds);
            $placeholders = implode(',', array_fill(0, count($eventIds), '?'));

            $stmt = $appDb->prepare("
                SELECT s.slot_id, s.contact_id
                FROM tgg_volunteer_signups s
                JOIN tgg_event_slots sl ON sl.id = s.slot_id
                WHERE sl.event_id IN ($placeholders)
            ");
            $stmt->execute($eventIds);
            $signupsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($signupsRaw)) {
                $contactIds = array_unique(array_column($signupsRaw, 'contact_id'));
                $formattedNames = MembershipService::getFormattedNames($contactIds);
                foreach ($signupsRaw as $row) {
                    $cid = (int)$row['contact_id'];
                    $volunteerBySlot[(int)$row['slot_id']] = $formattedNames[$cid] ?? "Member #{$cid}";
                }
            }
        } catch (Exception $e) {
            // Fallback to empty
        }
    }
}

// Shared POST form-action builder: signups from any view redirect back to the
// same view/month/year/filter, with ?selected= updated to the event's date
// (drives both the calendar grid's selection and the list's highlight/scroll).
$vsFormAction = function (string $evtDateStr) use ($buildUrl): string {
    return $buildUrl(['selected' => $evtDateStr]);
};

// Combo view's side list: filtered down to the selected day's events, or the
// full visible month when nothing is selected. Rendered via a closure so the
// AJAX day-selection endpoint below and the normal full-page render share it.
$comboListEvents = $selectedDay ? $selectedEvents : $monthEvents;
$comboHeading = $selectedDay ? date('F d, Y (l)', strtotime($selectedDay)) : "{$monthLabel} Schedule";
$renderComboListPanel = function () use ($comboHeading, $comboListEvents, $slotsByEvent, $buildUrl, $selectedDay, $vsFormAction) {
    ?>
    <h3 style="margin-top: 0;"><?php echo e($comboHeading); ?></h3>
    <?php if ($selectedDay): ?>
        <a href="<?php echo e($buildUrl(['selected' => null])); ?>" class="btn btn-secondary btn-small" style="margin-bottom: 16px; display: inline-block;">&times; Show Full Month</a>
    <?php endif; ?>
    <?php
        $vsEvents = $comboListEvents;
        $vsSlotsByEvent = $slotsByEvent;
        $vsHighlightDate = null;
        $vsEmptyMessage = $selectedDay ? 'No volunteer slots scheduled for this day.' : 'No events scheduled this month.';
        include __DIR__ . '/partials/volunteer_signup_table.php';
    ?>
    <?php
};

// AJAX day-selection endpoint for combo view: returns just the list panel's
// inner HTML so clicking a calendar day updates it without a full page reload.
if ($view === 'combo' && ($_GET['ajax'] ?? '') === '1') {
    $renderComboListPanel();
    exit;
}

// --- calendar_grid.php callbacks (calendar & combo views) ---
$cgMonth = $month;
$cgYear = $year;
$cgMonthLabel = $monthLabel;
$cgPrevHref = $buildUrl(['month' => $prevMonth, 'year' => $prevYear, 'selected' => null]);
$cgNextHref = $buildUrl(['month' => $nextMonth, 'year' => $nextYear, 'selected' => null]);
$cgEventsByDay = $eventsByDay;
$cgSelectedDay = $selectedDay;
$cgDayHref = function (string $dateStr, bool $isSelected) use ($buildUrl) {
    return $buildUrl(['selected' => $isSelected ? null : $dateStr]);
};
$cgDayContent = function (int $day, array $eventsForDay) use ($slotsByEvent, $volunteerBySlot) {
    // One row per volunteer slot per event. Slot type drives the color;
    // fill status is shown via the name's presence on desktop and via
    // hollow-vs-solid dots on narrow screens (see .role-dot-mobile).
    $typeColors = [
        'open'    => 'var(--color-success, #22c55e)',
        'close'   => 'var(--color-danger, #ef4444)',
        'greeter' => 'var(--color-primary, #3b82f6)',
    ];

    echo "<div class='day-slots' style='margin-top: 8px; display: flex; flex-direction: column; align-items: flex-start; gap: 4px; font-size: 0.75rem; font-weight: 500; font-family: var(--font-body); line-height: 1.2;'>";
    foreach ($eventsForDay as $evt) {
        foreach ($slotsByEvent[(int)$evt['id']] ?? [] as $slot) {
            $slotColor = $typeColors[$slot['slot_type']] ?? $typeColors['open'];
            $volName = $volunteerBySlot[(int)$slot['id']] ?? '';
            $filled = ($volName !== '');
            $labelInitial = mb_strtoupper(mb_substr($slot['slot_label'], 0, 1));

            echo "<div class='day-slot-row' style='white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 100%; text-align: left;'>";
            echo "<span class='role-dot-mobile" . ($filled ? ' filled' : '') . "' style='border-color: {$slotColor}; background-color: " . ($filled ? $slotColor : 'transparent') . ";'></span>";
            echo "<span class='day-slot-label' style='color: {$slotColor}; font-weight: 700;' title='" . e($slot['slot_label']) . "'>" . e($labelInitial) . ":</span> ";
            if ($filled) {
                echo "<span class='day-slot-name' style='color: var(--color-text-primary);' title='" . e($volName) . "'>" . e($volName) . "</span>";
            }
            echo "</div>";
        }
    }
    echo "</div>";
};

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Volunteer Schedule - TGG Club</title>
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <style>
        .filter-toggle-container {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 25px;
        }
        .filter-toggle-group {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
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
        .calendar-day {
            height: 100px !important;
            padding: 8px !important;
        }
        .day-events-dots {
            bottom: 6px !important;
        }
        .role-dot-mobile {
            display: none;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            border: 1.5px solid;
            margin-right: 4px;
            flex-shrink: 0;
        }
        @media (max-width: 640px) {
            .day-slot-label,
            .day-slot-name {
                display: none;
            }
            .day-slot-row {
                display: flex;
                align-items: center;
            }
            .role-dot-mobile {
                display: inline-block;
                margin-right: 0;
            }
        }
        .mobile-list-view {
            display: none;
        }
        @media (max-width: 768px) {
            /* The grid-based views (Calendar/Combo) don't work well at this
               width -- always fall back to the flat list on mobile, regardless
               of which desktop view is active. */
            .desktop-view-content {
                display: none;
            }
            .mobile-list-view {
                display: block;
            }
        }
    </style>
</head>
<body data-my-contact-id="<?php echo (int)($_SESSION['user']['contact_id'] ?? 0); ?>">
    <div class="app-container">
        <?php $navActive = 'volunteers'; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content">
            <?php if ($errorMsg): ?>
                <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
            <?php endif; ?>

            <?php if ($successMsg): ?>
                <div class="alert alert-success"><?php echo e($successMsg); ?></div>
            <?php endif; ?>

            <div class="desktop-view-content">
                <div class="glass-panel" style="margin-bottom: 25px;">
                    <div class="filter-toggle-container">
                        <div>
                            <h2 style="margin-bottom: 5px;">Volunteer Schedule</h2>
                            <p style="color: var(--color-text-secondary); font-size: 0.9rem; margin-bottom: 0;">See who is helping out, or select an open slot to sign up.</p>
                        </div>
                        <div class="filter-toggle-group">
                            <div class="filter-toggle">
                                <a href="<?php echo e($buildUrl(['view' => 'list', 'selected' => null])); ?>" class="<?php echo $view === 'list' ? 'active' : ''; ?>">List</a>
                                <a href="<?php echo e($buildUrl(['view' => 'calendar', 'selected' => null])); ?>" class="<?php echo $view === 'calendar' ? 'active' : ''; ?>">Calendar</a>
                                <a href="<?php echo e($buildUrl(['view' => 'combo', 'selected' => null])); ?>" class="<?php echo $view === 'combo' ? 'active' : ''; ?>">Combo</a>
                            </div>
                            <?php if ($view === 'list'): ?>
                            <div class="filter-toggle">
                                <a href="<?php echo e($buildUrl(['filter' => 'upcoming'])); ?>" class="<?php echo $filter === 'upcoming' ? 'active' : ''; ?>">Upcoming Only</a>
                                <a href="<?php echo e($buildUrl(['filter' => 'all'])); ?>" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">All Events</a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($view === 'list'): ?>
                        <?php
                            $vsEvents = $listEvents;
                            $vsSlotsByEvent = $listSlotsByEvent;
                            $vsHighlightDate = $selectedRaw;
                            $vsEmptyMessage = 'No events scheduled.';
                            include __DIR__ . '/partials/volunteer_signup_table.php';
                        ?>
                    <?php endif; ?>
                </div>

                <?php if ($view === 'calendar'): ?>
                    <div class="calendar-page-layout">
                        <?php include __DIR__ . '/partials/calendar_grid.php'; ?>

                        <?php if ($selectedDay): ?>
                        <section class="glass-panel calendar-day-panel">
                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px;">
                                <h3 style="margin: 0;"><?php echo e(date('F d, Y (l)', strtotime($selectedDay))); ?></h3>
                                <a href="<?php echo e($buildUrl(['selected' => null])); ?>" class="btn btn-secondary btn-small">&times; Close</a>
                            </div>
                            <?php
                                $vsEvents = $selectedEvents;
                                $vsSlotsByEvent = $slotsByEvent;
                                $vsHighlightDate = null;
                                $vsEmptyMessage = 'No volunteer slots scheduled for this day.';
                                include __DIR__ . '/partials/volunteer_signup_table.php';
                            ?>
                        </section>
                        <?php endif; ?>
                    </div>
                <?php elseif ($view === 'combo'): ?>
                    <div class="calendar-layout">
                        <?php $cgAjaxTarget = 'combo-list-panel'; include __DIR__ . '/partials/calendar_grid.php'; ?>

                        <section class="glass-panel" id="combo-list-panel">
                            <?php $renderComboListPanel(); ?>
                        </section>
                    </div>
                <?php endif; ?>
            </div>

            <div class="glass-panel mobile-list-view" style="margin-bottom: 25px;">
                <h2 style="margin-bottom: 5px;">Volunteer Schedule</h2>
                <p style="color: var(--color-text-secondary); font-size: 0.9rem; margin-bottom: 20px;">See who is helping out, or select an open slot to sign up.</p>
                <div class="filter-toggle" style="margin-bottom: 20px;">
                    <a href="<?php echo e($buildUrl(['filter' => 'upcoming'])); ?>" class="<?php echo $filter === 'upcoming' ? 'active' : ''; ?>">Upcoming Only</a>
                    <a href="<?php echo e($buildUrl(['filter' => 'all'])); ?>" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">All Events</a>
                </div>
                <?php
                    $vsEvents = $listEvents;
                    $vsSlotsByEvent = $listSlotsByEvent;
                    $vsHighlightDate = $selectedRaw;
                    $vsEmptyMessage = 'No events scheduled.';
                    include __DIR__ . '/partials/volunteer_signup_table.php';
                ?>
            </div>
        </main>

        <?php include __DIR__ . '/partials/footer.php'; ?>

    <?php if (has_permission('manage hosting')): ?>
        <datalist id="members-list">
            <?php foreach ($allActiveMembers as $member): ?>
                <?php
                    $displayText = $member['display_name'] . ' (ID: ' . $member['id'] . ')';
                    if (!empty($member['email'])) {
                        $displayText .= ' - ' . $member['email'];
                    }
                ?>
                <option value="<?php echo e($displayText); ?>"></option>
            <?php endforeach; ?>
        </datalist>
    <?php endif; ?>

    <script src="assets/js/calendar-day-ajax.js<?php echo asset_version('assets/js/calendar-day-ajax.js'); ?>"></script>
    <script src="assets/js/volunteer-signup.js<?php echo asset_version('assets/js/volunteer-signup.js'); ?>"></script>
</body>
</html>
