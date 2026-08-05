<?php
/**
 * Shared check-in list table.
 * Caller sets these variables before including this file:
 *   $checkinsList            array   Rows with: checkin_id, checked_in_at, notes, guest_name, display_name, first_name, last_name
 *   $checkinDeleteFormAction string  Form action URL for the per-row Delete button
 *   $checkinEmptyMessage     string  Message shown when the list is empty
 *
 * Rows with a non-empty guest_name represent a guest visit (contact_id is the sponsoring
 * member, not the guest -- guests have no contact record). For those rows, the guest's name
 * is shown in place of the member's name, and the +1 column gets a checkmark. The sponsor's
 * name lives in Notes ("Guest of <member>"), which hides on narrow screens along with First/
 * Last Name, so the +1 checkmark is also tappable there to reveal the sponsor inline.
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
                    <?php $isGuestRow = !empty($chk['guest_name']); ?>
                    <tr>
                        <td class="col-firstname"><strong><?php echo $isGuestRow ? '-' : e($chk['first_name'] ?: '-'); ?></strong></td>
                        <td class="col-lastname"><strong><?php echo $isGuestRow ? '-' : e($chk['last_name'] ?: '-'); ?></strong></td>
                        <td><?php echo $isGuestRow ? e($chk['guest_name']) : e($chk['display_name']); ?></td>
                        <td><span class="table-datetime"><?php echo date('g:i A', strtotime($chk['checked_in_at'])); ?></span></td>
                        <td class="col-notes"><?php echo $isGuestRow ? 'Guest of ' . e($chk['display_name']) : e($chk['notes'] ?: 'Regular Visit'); ?></td>
                        <td style="text-align: center;">
                            <?php if ($isGuestRow): ?>
                                <span class="guest-tick" tabindex="0" role="button" title="Guest of <?php echo e($chk['display_name']); ?>" data-sponsor-label="Guest of <?php echo e($chk['display_name']); ?>" aria-label="Guest check-in, tap to see sponsoring member">&check;</span>
                                <div class="guest-tick-detail"></div>
                            <?php else: ?>
                                <span style="color: var(--color-text-muted);">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <form action="<?php echo e($checkinDeleteFormAction); ?>" method="POST" data-confirm="Are you sure you want to delete this check-in record?" style="margin: 0;">
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
<script>
    // Tapping/clicking a guest +1 checkmark reveals "Guest of <member>" inline, since the
    // Notes column (which normally carries that text) is hidden on narrow screens.
    if (!window.__guestTickBound) {
        window.__guestTickBound = true;
        document.addEventListener('click', function (e) {
            var tick = e.target.closest('.guest-tick');
            if (!tick) return;
            var detail = tick.nextElementSibling;
            if (!detail || !detail.classList.contains('guest-tick-detail')) return;
            var isOpen = detail.style.display === 'block';
            document.querySelectorAll('.guest-tick-detail').forEach(function (d) { d.style.display = 'none'; });
            if (!isOpen) {
                detail.textContent = tick.getAttribute('data-sponsor-label');
                detail.style.display = 'block';
            }
        });
        document.addEventListener('keydown', function (e) {
            if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('guest-tick')) {
                e.preventDefault();
                e.target.click();
            }
        });
    }
</script>
