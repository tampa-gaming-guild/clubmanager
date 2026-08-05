<?php
/**
 * Shared month-grid calendar table, used by the public calendar.php and by
 * volunteers.php's calendar/combo views. Handles date arithmetic, blank-cell
 * padding, week wrapping, and today/selected highlighting; each caller
 * supplies its own day-cell content and link target via callbacks.
 *
 * Caller sets these variables before including this file:
 *   $cgMonth        int      Visible month (1-12)
 *   $cgYear         int      Visible year
 *   $cgMonthLabel   string   Pre-formatted "F Y" label
 *   $cgPrevHref     string   Prev-month link
 *   $cgNextHref     string   Next-month link
 *   $cgEventsByDay  array    day-of-month => [event rows]
 *   $cgSelectedDay  ?string  Y-m-d currently selected, or null
 *   $cgDayHref      callable(string $dateStr, bool $isSelected): ?string
 *                            Href for a day cell with events, or null for no link
 *   $cgDayContent   callable(int $day, array $eventsForDay): void
 *                            Echoes the day cell's content below the day number;
 *                            called only when $eventsForDay is non-empty
 *   $cgAjaxTarget   ?string  Element id to AJAX-swap on day click instead of a
 *                            full page navigation (see assets/js/calendar-day-ajax.js);
 *                            null/unset for a normal full-page link (default).
 */
$cgAjaxTarget = $cgAjaxTarget ?? null;

$cgMonthStart = "{$cgYear}-" . str_pad($cgMonth, 2, '0', STR_PAD_LEFT) . "-01";
$cgFirstDayOfWeek = (int)date('w', strtotime($cgMonthStart));
$cgDaysInMonth = (int)date('t', strtotime($cgMonthStart));
?>
<section class="calendar-grid-section glass-panel">
    <div class="calendar-controls">
        <a href="<?php echo e($cgPrevHref); ?>" class="btn btn-secondary">&larr; Prev</a>
        <h2><?php echo e($cgMonthLabel); ?></h2>
        <a href="<?php echo e($cgNextHref); ?>" class="btn btn-secondary">Next &rarr;</a>
    </div>

    <div class="table-scroll-wrapper">
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
                for ($i = 0; $i < $cgFirstDayOfWeek; $i++) {
                    echo '<td class="calendar-day empty-day"></td>';
                }

                // Render days of the month
                $currentDayOfWeek = $cgFirstDayOfWeek;
                for ($day = 1; $day <= $cgDaysInMonth; $day++) {
                    if ($currentDayOfWeek == 0 && $day > 1) {
                        echo '</tr><tr>';
                    }

                    $eventsForDay = $cgEventsByDay[$day] ?? [];
                    $hasEvents = !empty($eventsForDay);
                    $dayClass = $hasEvents ? 'has-events-day' : '';

                    // Highlight today
                    if ($day == (int)date('d') && $cgMonth == (int)date('m') && $cgYear == (int)date('Y')) {
                        $dayClass .= ' today-day';
                    }

                    $padMonth = str_pad($cgMonth, 2, '0', STR_PAD_LEFT);
                    $padDay = str_pad($day, 2, '0', STR_PAD_LEFT);
                    $dateStr = "{$cgYear}-{$padMonth}-{$padDay}";

                    $isSelected = ($dateStr === $cgSelectedDay);
                    if ($isSelected) {
                        $dayClass .= ' selected-day';
                    }

                    if ($hasEvents && $cgAjaxTarget) {
                        // Both variants are emitted so the client-side handler can pick
                        // one from the live DOM state (.selected-day class) rather than a
                        // stale isSelected snapshot from this page load -- otherwise a
                        // second click on the same cell after an earlier AJAX swap would
                        // always "select" again instead of toggling off.
                        $selectHref = $cgDayHref($dateStr, false);
                        $deselectHref = $cgDayHref($dateStr, true);
                        $selectAjaxHref = $selectHref . (str_contains($selectHref, '?') ? '&' : '?') . 'ajax=1';
                        $deselectAjaxHref = $deselectHref . (str_contains($deselectHref, '?') ? '&' : '?') . 'ajax=1';
                        echo "<td class='calendar-day {$dayClass}' data-ajax-target='" . e($cgAjaxTarget) . "'"
                            . " data-select-ajax-href='" . e($selectAjaxHref) . "' data-select-push-url='" . e($selectHref) . "'"
                            . " data-deselect-ajax-href='" . e($deselectAjaxHref) . "' data-deselect-push-url='" . e($deselectHref) . "'"
                            . " style='cursor: pointer;'>";
                    } elseif ($hasEvents) {
                        $dayHref = $cgDayHref($dateStr, $isSelected);
                        echo "<td class='calendar-day {$dayClass}' onclick=\"window.location.href='" . e($dayHref) . "'\" style='cursor: pointer;'>";
                    } else {
                        echo "<td class='calendar-day {$dayClass}'>";
                    }
                    echo "<span class='day-num'>{$day}</span>";

                    if ($hasEvents) {
                        $cgDayContent($day, $eventsForDay);
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
    </div>
</section>
