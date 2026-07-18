<?php
/**
 * Public calendar day panel -- read-only event details (name, time, description,
 * who's hosting). Used only by calendar.php; the volunteer signup UI itself
 * lives entirely in volunteer_signup_table.php now.
 *
 * Caller sets these variables before including this file:
 *   $cdEvents                  array     Event rows for the selected day (id, title, description, start_time, end_time)
 *   $cdVolunteerNamesByEvent   array     event_id => [display_name, ...] of everyone currently signed up
 *   $cdEmptyMessage            string    Message shown when $cdEvents is empty
 */
?>
<?php if (empty($cdEvents)): ?>
    <p style="color: var(--color-text-secondary); text-align: center; padding: 20px;"><?php echo e($cdEmptyMessage); ?></p>
<?php else: ?>
    <?php foreach ($cdEvents as $evt): ?>
        <?php
            $eventDate = date('F d, Y (l)', strtotime($evt['start_time']));
            $eventTime = date('g:i A', strtotime($evt['start_time'])) . ' - ' . date('g:i A', strtotime($evt['end_time']));
            $description = trim((string)($evt['description'] ?? ''));
            $isPast = strtotime($evt['start_time']) < strtotime('today');
        ?>
        <div class="calendar-day-event-card<?php echo $isPast ? ' is-past-event' : ''; ?>">
            <h4 style="margin-bottom: 4px;"><?php echo e($evt['title']); ?></h4>
            <p style="color: var(--color-text-secondary); font-size: 0.85rem; margin-bottom: 12px;">📅 <?php echo e($eventDate); ?> — ⏰ <?php echo $eventTime; ?></p>
            <?php if ($description !== ''): ?>
                <p style="margin-bottom: 16px; white-space: pre-line;"><?php echo e($description); ?></p>
            <?php else: ?>
                <p style="color: var(--color-text-muted); font-style: italic; margin-bottom: 16px;">No description provided.</p>
            <?php endif; ?>
            <?php $volunteerNames = $cdVolunteerNamesByEvent[(int)$evt['id']] ?? []; ?>
            <?php if (!empty($volunteerNames)): ?>
                <p style="color: var(--color-text-secondary); font-size: 0.85rem;">🙋 Hosted by: <?php echo e(implode(', ', $volunteerNames)); ?></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
