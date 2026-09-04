<?php
/**
 * Shared check-in list table.
 * Caller sets these variables before including this file:
 *   $checkinsList            array   Rows with: checkin_id, checked_in_at, notes, guest_name, display_name, first_name, last_name
 *   $checkinDeleteFormAction string  Form action URL for the per-row Delete and Edit buttons
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
                        <td style="text-align: center; white-space: nowrap;">
                            <button type="button" class="btn btn-secondary btn-icon" aria-label="Edit check-in" title="Edit check-in"
                                onclick="openEditCheckinModal(<?php echo (int)$chk['checkin_id']; ?>, '<?php echo e(date('Y-m-d\TH:i', strtotime($chk['checked_in_at']))); ?>', <?php echo e(json_encode($chk['notes'])); ?>, <?php echo e(json_encode($chk['guest_name'])); ?>, <?php echo $isGuestRow ? 'true' : 'false'; ?>)">✏️</button>
                            <form action="<?php echo e($checkinDeleteFormAction); ?>" method="POST" data-confirm="Are you sure you want to delete this check-in record?" style="margin: 0; display: inline;">
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

<!-- Edit Check-In Modal (shared by every page that includes this partial) -->
<div id="edit-checkin-modal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(5px);">
    <div class="modal-content glass-panel" style="background: var(--color-surface-glass-solid); margin: 5% auto; padding: 25px; border: 1px solid rgba(var(--overlay-rgb), 0.1); width: 90%; max-width: 420px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
        <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(var(--overlay-rgb), 0.1); padding-bottom: 15px; margin-bottom: 20px;">
            <h3 style="margin: 0; color: var(--color-text-primary); font-size: 1.2rem;">Edit Check-In</h3>
            <span class="close" onclick="closeEditCheckinModal()" style="color: rgba(var(--overlay-rgb),0.6); font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>
        </div>
        <form action="<?php echo e($checkinDeleteFormAction); ?>" method="POST" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
            <input type="hidden" name="checkin_id" id="edit-checkin-id" value="">
            <div class="form-group">
                <label for="edit-checkin-time">Check-In Date/Time</label>
                <input type="datetime-local" id="edit-checkin-time" name="checked_in_at" required>
            </div>
            <div class="form-group" id="edit-checkin-notes-group">
                <label for="edit-checkin-notes">Notes</label>
                <input type="text" id="edit-checkin-notes" name="notes">
            </div>
            <div class="form-group" id="edit-checkin-guest-group" style="display: none;">
                <label for="edit-checkin-guest">Guest Name</label>
                <input type="text" id="edit-checkin-guest" name="guest_name">
            </div>
            <div class="form-actions">
                <button type="submit" name="edit_checkin" class="btn btn-primary">Save Changes</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditCheckinModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    // This partial is always included inside a .glass-panel container, whose
    // backdrop-filter creates a containing block for position:fixed children --
    // that traps the modal inside the panel's box instead of the viewport.
    // Re-parenting to <body> escapes it regardless of the including page.
    document.body.appendChild(document.getElementById('edit-checkin-modal'));

    function openEditCheckinModal(checkinId, checkedInAt, notes, guestName, isGuest) {
        document.getElementById('edit-checkin-id').value = checkinId;
        document.getElementById('edit-checkin-time').value = checkedInAt;
        document.getElementById('edit-checkin-notes').value = notes || '';
        document.getElementById('edit-checkin-guest').value = guestName || '';
        document.getElementById('edit-checkin-notes-group').style.display = isGuest ? 'none' : 'block';
        document.getElementById('edit-checkin-guest-group').style.display = isGuest ? 'block' : 'none';
        document.getElementById('edit-checkin-modal').style.display = 'block';
    }
    function closeEditCheckinModal() {
        document.getElementById('edit-checkin-modal').style.display = 'none';
    }
    window.addEventListener('click', function (event) {
        const modal = document.getElementById('edit-checkin-modal');
        if (event.target === modal) closeEditCheckinModal();
    });
</script>

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
