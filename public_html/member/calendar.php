<?php
/**
 * Public Club Calendar
 * Read-only, no login required. Renders month-grid navigation with each day's
 * event names; clicking a day with events opens a same-page panel (below the
 * grid) showing event name/time/description and a CTA into the Volunteer
 * Schedule (volunteers.php) for anyone who wants to sign up.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Event;

// Determine Selected Month & Year (for Grid Calendar)
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Adjust underflow/overflow of months
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

// Selected day panel -- must match the visible month/year, so a stale ?selected=
// from a different month (e.g. after Prev/Next) never matches a same-day-number event.
$selectedDay = null;
if (isset($_GET['selected']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['selected'])) {
    $candidate = $_GET['selected'];
    if ((int)date('n', strtotime($candidate)) === $month && (int)date('Y', strtotime($candidate)) === $year) {
        $selectedDay = $candidate;
    }
}

$monthStart = "{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01 00:00:00";
$monthEnd = date('Y-m-t 23:59:59', strtotime($monthStart));

// Fetch Events for the month
$errorMsg = null;
try {
    $events = Event::getEvents($monthStart, $monthEnd);
} catch (Exception $e) {
    $events = [];
    $errorMsg = safe_err("Unable to load events: ", $e);
}

$selectedEvents = $selectedDay
    ? array_values(array_filter($events, fn($e) => date('Y-m-d', strtotime($e['start_time'])) === $selectedDay))
    : [];

// Volunteer display names per month event, for the "Hosted by" line (names
// only -- no roles/slots/signup actions here). Computed for every event in
// the visible month, not just the selected day, since the mobile fallback
// list (see below) shows the whole month at once.
$volunteerNamesByEvent = [];
foreach ($events as $evt) {
    try {
        $vols = Event::getVolunteers((int)$evt['id']);
        $volunteerNamesByEvent[(int)$evt['id']] = array_values(array_unique(array_column($vols, 'display_name')));
    } catch (Exception $e) {
        $volunteerNamesByEvent[(int)$evt['id']] = [];
    }
}

// Map events to days for quick grid rendering
$eventsByDay = [];
foreach ($events as $evt) {
    $day = (int)date('d', strtotime($evt['start_time']));
    $eventsByDay[$day][] = $evt;
}

$monthLabel = date('F Y', strtotime($monthStart));

// Calculate previous/next links
$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

// --- calendar_grid.php callbacks ---
$cgMonth = $month;
$cgYear = $year;
$cgMonthLabel = $monthLabel;
$cgPrevHref = 'calendar.php?' . http_build_query(['month' => $prevMonth, 'year' => $prevYear]);
$cgNextHref = 'calendar.php?' . http_build_query(['month' => $nextMonth, 'year' => $nextYear]);
$cgEventsByDay = $eventsByDay;
$cgSelectedDay = $selectedDay;
$cgDayHref = function (string $dateStr, bool $isSelected) use ($month, $year) {
    $qp = ['month' => $month, 'year' => $year];
    if (!$isSelected) $qp['selected'] = $dateStr;
    return 'calendar.php?' . http_build_query($qp);
};
$cgDayContent = function (int $day, array $eventsForDay) {
    echo '<div class="day-event-names">';
    $shown = array_slice($eventsForDay, 0, 2);
    foreach ($shown as $evt) {
        $label = date('g:i', strtotime($evt['start_time'])) . ' - ' . $evt['title'];
        echo '<div class="day-event-name-row" title="' . e($label) . '">' . e($label) . '</div>';
    }
    $remaining = count($eventsForDay) - count($shown);
    if ($remaining > 0) {
        echo '<div class="day-event-more">+' . (int)$remaining . ' more</div>';
    }
    echo '</div>';
};
$cgAjaxTarget = 'calendar-day-panel-content';

// Day panel content, shared by the normal full-page render and the AJAX
// day-selection endpoint below. Empty output (no $selectedDay) tells the
// client-side handler to hide the panel instead of showing an empty one.
$renderDayPanelContent = function () use ($month, $year, $selectedDay, $selectedEvents, $volunteerNamesByEvent) {
    if (!$selectedDay) {
        return;
    }
    ?>
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px;">
        <h3 style="margin: 0;"><?php echo e(date('F d, Y (l)', strtotime($selectedDay))); ?></h3>
        <a href="calendar.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn btn-secondary btn-small">&times; Close</a>
    </div>
    <?php
        $cdEvents = $selectedEvents;
        $cdVolunteerNamesByEvent = $volunteerNamesByEvent;
        $cdEmptyMessage = 'No events scheduled for this day.';
        include __DIR__ . '/partials/calendar_day_details.php';
    ?>
    <?php
};

// AJAX day-selection endpoint: returns just the day panel's inner HTML (or
// nothing, when deselecting) so clicking a day updates it without a full
// page reload.
if (($_GET['ajax'] ?? '') === '1') {
    $renderDayPanelContent();
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Club Calendar - Events & Volunteering</title>
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <style>
        .calendar-day {
            height: 100px !important;
            padding: 8px !important;
        }
        .calendar-mobile-list {
            display: none;
        }
        @media (max-width: 768px) {
            /* The grid doesn't work well at this width -- drop it in favor of
               a flat list of the month's events, keeping just the month nav. */
            .calendar-grid-section .table-scroll-wrapper {
                display: none;
            }
            #calendar-day-panel-content {
                display: none !important;
            }
            .calendar-mobile-list {
                display: block;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $navActive = 'calendar'; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content">
            <?php if ($errorMsg): ?>
                <div class="alert alert-danger" style="margin-bottom: 20px;"><?php echo e($errorMsg); ?></div>
            <?php endif; ?>

            <div class="calendar-page-layout">

                <?php include __DIR__ . '/partials/calendar_grid.php'; ?>

                <section class="glass-panel calendar-day-panel" id="calendar-day-panel-content"<?php echo $selectedDay ? '' : ' style="display: none;"'; ?>>
                    <?php $renderDayPanelContent(); ?>
                </section>

                <section class="glass-panel calendar-mobile-list">
                    <?php
                        $cdEvents = $events;
                        $cdVolunteerNamesByEvent = $volunteerNamesByEvent;
                        $cdEmptyMessage = 'No events scheduled this month.';
                        include __DIR__ . '/partials/calendar_day_details.php';
                    ?>
                </section>

            </div>
        </main>

        <?php include __DIR__ . '/partials/footer.php'; ?>
    </div>

    <script src="assets/js/calendar-day-ajax.js<?php echo asset_version('assets/js/calendar-day-ajax.js'); ?>"></script>
</body>
</html>
