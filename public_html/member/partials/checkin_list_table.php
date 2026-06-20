<?php
/**
 * Shared check-in list table.
 * Caller sets these variables before including this file:
 *   $checkinsList            array   Rows with: checkin_id, checked_in_at, notes, display_name, first_name, last_name
 *   $checkinDeleteFormAction string  Form action URL for the per-row Delete button
 *   $checkinEmptyMessage     string  Message shown when the list is empty
 *
 * On narrow screens, First Name / Last Name / Notes columns hide and the
 * Display Name / Check-In Time headers shorten (see .th-full/.th-compact in style.css).
 */
?>
<div class="admin-table-container">
    <table class="admin-table">
        <thead>
            <tr>
                <th class="col-firstname">First Name</th>
                <th class="col-lastname">Last Name</th>
                <th><span class="th-full">Display Name</span><span class="th-compact">Name</span></th>
                <th><span class="th-full">Check-In Time</span><span class="th-compact">Time</span></th>
                <th class="col-notes">Notes</th>
                <th style="text-align: center;">+1</th>
                <th style="text-align: center; width: 60px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($checkinsList)): ?>
                <tr>
                    <td colspan="7" class="text-center" style="padding: 30px; color: var(--color-text-muted);"><?php echo e($checkinEmptyMessage); ?></td>
                </tr>
            <?php else: ?>
                <?php foreach ($checkinsList as $chk): ?>
                    <tr>
                        <td class="col-firstname"><strong><?php echo e($chk['first_name'] ?: '-'); ?></strong></td>
                        <td class="col-lastname"><strong><?php echo e($chk['last_name'] ?: '-'); ?></strong></td>
                        <td><?php echo e($chk['display_name']); ?></td>
                        <td><span class="table-datetime"><?php echo date('g:i A', strtotime($chk['checked_in_at'])); ?></span></td>
                        <td class="col-notes"><?php echo e($chk['notes'] ?: 'Regular Visit'); ?></td>
                        <td style="text-align: center; color: var(--color-text-muted);">-</td>
                        <td style="text-align: center;">
                            <form action="<?php echo e($checkinDeleteFormAction); ?>" method="POST" onsubmit="return confirm('Are you sure you want to delete this check-in record?');" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                <input type="hidden" name="checkin_id" value="<?php echo e($chk['checkin_id']); ?>">
                                <button type="submit" name="delete_checkin" class="btn btn-danger btn-icon" aria-label="Delete check-in" title="Delete check-in">🗑️</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
