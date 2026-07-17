<?php
/**
 * Shared volunteer-slot signup table (event header row + per-slot role rows),
 * reused by volunteers.php (full schedule) and calendar.php (selected-day panel).
 *
 * Caller sets these variables before including this file:
 *   $vsEvents        array     Event rows to render (id, title, start_time, end_time)
 *   $vsSlotsByEvent  array     Map event_id => slot rows, EventSlot::getSlotsForEvents() shape
 *   $vsFormAction    callable  function(string $evtDateStr): string -- POST form action URL
 *   $vsHighlightDate ?string   Y-m-d to visually highlight + scroll to on load; null to disable
 *   $vsEmptyMessage  string    Message shown when $vsEvents is empty
 *
 * Fetches each event's volunteer signups (with contact_id) itself via
 * \App\Event::getVolunteers(), same as volunteers.php did inline before extraction.
 */

// Slot type drives the accent color (and the credit weight -- see EventSlot::creditKey)
$vsSlotTypeColors = [
    'open'    => ['var' => '--color-success', 'rgb' => '34,197,94'],
    'close'   => ['var' => '--color-danger',  'rgb' => '239,68,68'],
    'greeter' => ['var' => '--color-primary', 'rgb' => '59,130,246'],
];
?>
<div class="admin-table-container">
    <table class="admin-table" style="width: 100%;">
        <thead>
            <tr>
                <th style="width: 30%;">Volunteer Role</th>
                <th style="width: 40%;">Volunteer Assigned</th>
                <th style="width: 30%;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($vsEvents)): ?>
                <tr>
                    <td colspan="3" style="text-align: center; color: var(--color-text-secondary); padding: 40px;">
                        <?php echo e($vsEmptyMessage); ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php $vsHighlightedAdded = false; ?>
                <?php foreach ($vsEvents as $evt): ?>
                    <?php
                        $evtId = (int)$evt['id'];
                        $vols = \App\Event::getVolunteers($evtId);

                        // Map slot id -> volunteer info
                        $slotVolunteers = [];
                        foreach ($vols as $vol) {
                            $slotVolunteers[(int)$vol['slot_id']] = $vol;
                        }
                        $eventSlots = $vsSlotsByEvent[$evtId] ?? [];
                        $openSlotCount = count(array_filter($eventSlots, fn($s) => !isset($slotVolunteers[(int)$s['id']])));

                        $eventDate = date('F d, Y (l)', strtotime($evt['start_time']));
                        $eventTime = date('g:i A', strtotime($evt['start_time'])) . ' - ' . date('g:i A', strtotime($evt['end_time']));

                        $evtDateStr = date('Y-m-d', strtotime($evt['start_time']));
                        $vsAction = $vsFormAction($evtDateStr);
                        $isHighlighted = $vsHighlightDate !== null && $vsHighlightDate === $evtDateStr;
                        $trClass = 'event-group-header' . ($isHighlighted ? ' highlighted' : '');
                        $trIdAttr = '';
                        if ($isHighlighted && !$vsHighlightedAdded) {
                            $trIdAttr = ' id="highlighted-event"';
                            $vsHighlightedAdded = true;
                        }
                    ?>
                    <!-- Event Header Row -->
                    <tr class="<?php echo $trClass; ?>"<?php echo $trIdAttr; ?>>
                        <td colspan="3">
                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
                                <div style="min-width: 0;">
                                    📅 <?php echo $eventDate; ?> — <?php echo e($evt['title']); ?>
                                    <span style="font-size: 0.8rem; font-weight: normal; color: var(--color-text-secondary); margin-left: 10px;">
                                        (⏰ <?php echo $eventTime; ?>)
                                    </span>
                                </div>
                                <?php if (has_permission('volunteer') && $openSlotCount > 0): ?>
                                    <div style="font-weight: normal; font-family: var(--font-body); flex-shrink: 0;">
                                        <div id="btn-container-<?php echo $evtId; ?>-ALL">
                                            <button class="btn btn-success btn-small" onclick="showSignupConfirm(<?php echo $evtId; ?>, 'ALL')" style="padding: 6px 12px; font-size: 0.8rem; white-space: nowrap;">
                                                Sign Up for All Open Slots &rarr;
                                            </button>
                                        </div>
                                        <div id="confirm-container-<?php echo $evtId; ?>-ALL" style="display: none; background: rgba(255, 255, 255, 0.03); border: 1px solid var(--border-glass); border-radius: 8px; padding: 10px; min-width: 240px; text-align: left;">
                                            <?php if (has_permission('manage hosting')): ?>
                                                <div style="margin-bottom: 8px;">
                                                    <label style="font-size: 0.8rem; margin-right: 12px; cursor: pointer; color: #fff;">
                                                        <input type="radio" name="signup_type_<?php echo $evtId; ?>_ALL" value="self" checked onclick="toggleAdminSignupType(<?php echo $evtId; ?>, 'ALL', 'self')" style="margin-right: 4px;"> Myself
                                                    </label>
                                                    <label style="font-size: 0.8rem; cursor: pointer; color: #fff;">
                                                        <input type="radio" name="signup_type_<?php echo $evtId; ?>_ALL" value="other" onclick="toggleAdminSignupType(<?php echo $evtId; ?>, 'ALL', 'other')" style="margin-right: 4px;"> Other Member
                                                    </label>
                                                </div>
                                                <div id="admin-search-<?php echo $evtId; ?>-ALL" style="display: none; margin-bottom: 8px;">
                                                    <input type="text" list="members-list" placeholder="Type member name..." oninput="updateMemberId(this, <?php echo $evtId; ?>, 'ALL')" style="width: 100%; padding: 6px 10px; font-size: 0.8rem; border-radius: 4px; border: 1px solid var(--border-glass); background: rgba(255, 255, 255, 0.05); color: #fff; outline: none;">
                                                </div>
                                            <?php endif; ?>
                                            <form action="<?php echo e($vsAction); ?>" method="POST" style="display: inline;" onsubmit="return validateAdminSignup(this, <?php echo $evtId; ?>, 'ALL')">
                                                <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                                <input type="hidden" name="event_id" value="<?php echo $evtId; ?>">
                                                <input type="hidden" name="contact_id" id="contact-id-<?php echo $evtId; ?>-ALL" value="<?php echo $_SESSION['user']['contact_id']; ?>">

                                                <?php if (!has_permission('manage hosting')): ?>
                                                    <span style="font-size: 0.8rem; color: var(--color-text-secondary); display: block; margin-bottom: 8px;">Sign up for all open slots?</span>
                                                <?php endif; ?>

                                                <div style="display: flex; gap: 6px;">
                                                    <button type="submit" name="action_signup_all" class="btn btn-success btn-small" style="padding: 4px 8px; font-size: 0.75rem;">Confirm</button>
                                                    <button type="button" class="btn btn-secondary btn-small" onclick="cancelSignup(<?php echo $evtId; ?>, 'ALL')" style="padding: 4px 8px; font-size: 0.75rem; background: rgba(255,255,255,0.05); color: #fff; border: 1px solid var(--border-glass);">Cancel</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>

                    <?php
                        foreach ($eventSlots as $slot):
                            $slotId = (int)$slot['id'];
                            $role = $slot['slot_label'];
                            $hasVol = isset($slotVolunteers[$slotId]);
                            $volName = $hasVol ? $slotVolunteers[$slotId]['display_name'] : null;
                            $volContactId = $hasVol ? (int)$slotVolunteers[$slotId]['contact_id'] : null;

                            // Slot type drives the bullet + accent color; hollow until filled
                            $bulletClass = 'bullet-' . $slot['slot_type'] . ($hasVol ? ' filled' : '');
                            $isMe = $hasVol && \App\Auth::check() && $volContactId === (int)($_SESSION['user']['contact_id'] ?? 0);
                            $typeColors = $vsSlotTypeColors[$slot['slot_type']] ?? $vsSlotTypeColors['open'];
                            $roleColorVar = $typeColors['var'];
                            $roleRgb = $typeColors['rgb'];
                    ?>
                        <tr class="role-subrow">
                            <td>
                                <span class="role-bullet <?php echo $bulletClass; ?>"></span>
                                <strong><?php echo e($role); ?></strong>
                            </td>
                            <td>
                                <?php if ($hasVol): ?>
                                    <span class="badge" style="display: inline-flex; align-items: center; gap: 8px;
                                        color: var(<?php echo $roleColorVar; ?>);
                                        background: rgba(<?php echo $roleRgb; ?>, <?php echo $isMe ? '0.35' : '0.2'; ?>);
                                        <?php echo $isMe ? 'border: 1px solid var(' . $roleColorVar . ');' : ''; ?>">
                                        <?php echo $isMe ? '🙋' : '👤'; ?> <?php echo e($volName); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="volunteer-status-needed">
                                        👋 Volunteer Needed
                                    </span>
                                <?php endif; ?>
                            </td>
                             <td>
                                <?php if ($hasVol): ?>
                                    <?php
                                        $evtDateOnly = date('Y-m-d', strtotime($evt['start_time']));
                                        $todayDateOnly = date('Y-m-d');
                                        $isTodayOrLater = ($evtDateOnly >= $todayDateOnly);

                                        $canDelete = \App\Auth::check() && (
                                            has_permission('manage hosting') ||
                                            ($volContactId === (int)$_SESSION['user']['contact_id'] && $isTodayOrLater)
                                        );
                                    ?>
                                    <?php if ($canDelete): ?>
                                        <form action="<?php echo e($vsAction); ?>" method="POST" style="display: inline;" data-confirm="Are you sure you want to delete this volunteer signup?">
                                            <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                            <input type="hidden" name="slot_id" value="<?php echo $slotId; ?>">
                                            <input type="hidden" name="contact_id" value="<?php echo $volContactId; ?>">
                                            <button type="submit" name="action_delete" class="btn btn-danger btn-small" style="padding: 6px 12px; font-size: 0.8rem;">
                                                Cancel Signup
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: var(--color-text-muted); font-size: 0.85rem;">Filled</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if (!\App\Auth::check()): ?>
                                        <a href="index.php?action=login" class="btn btn-success btn-small" style="padding: 6px 12px; font-size: 0.8rem;">
                                            Log In to Sign Up &rarr;
                                        </a>
                                    <?php elseif (has_permission('volunteer')): ?>
                                        <div id="btn-container-<?php echo $evtId; ?>-<?php echo $slotId; ?>">
                                            <button class="btn btn-success btn-small" onclick="showSignupConfirm(<?php echo $evtId; ?>, <?php echo $slotId; ?>)" style="padding: 6px 12px; font-size: 0.8rem;">
                                                Sign Up &rarr;
                                            </button>
                                        </div>
                                        <div id="confirm-container-<?php echo $evtId; ?>-<?php echo $slotId; ?>" style="display: none; background: rgba(255, 255, 255, 0.03); border: 1px solid var(--border-glass); border-radius: 8px; padding: 10px; min-width: 220px; text-align: left;">
                                            <?php if (has_permission('manage hosting')): ?>
                                                <div style="margin-bottom: 8px;">
                                                    <label style="font-size: 0.8rem; margin-right: 12px; cursor: pointer; color: #fff;">
                                                        <input type="radio" name="signup_type_<?php echo $evtId; ?>_<?php echo $slotId; ?>" value="self" checked onclick="toggleAdminSignupType(<?php echo $evtId; ?>, <?php echo $slotId; ?>, 'self')" style="margin-right: 4px;"> Myself
                                                    </label>
                                                    <label style="font-size: 0.8rem; cursor: pointer; color: #fff;">
                                                        <input type="radio" name="signup_type_<?php echo $evtId; ?>_<?php echo $slotId; ?>" value="other" onclick="toggleAdminSignupType(<?php echo $evtId; ?>, <?php echo $slotId; ?>, 'other')" style="margin-right: 4px;"> Other Member
                                                    </label>
                                                </div>
                                                <div id="admin-search-<?php echo $evtId; ?>-<?php echo $slotId; ?>" style="display: none; margin-bottom: 8px;">
                                                    <input type="text" list="members-list" placeholder="Type member name..." oninput="updateMemberId(this, <?php echo $evtId; ?>, <?php echo $slotId; ?>)" style="width: 100%; padding: 6px 10px; font-size: 0.8rem; border-radius: 4px; border: 1px solid var(--border-glass); background: rgba(255, 255, 255, 0.05); color: #fff; outline: none;">
                                                </div>
                                            <?php endif; ?>
                                            <form action="<?php echo e($vsAction); ?>" method="POST" style="display: inline;" onsubmit="return validateAdminSignup(this, <?php echo $evtId; ?>, <?php echo $slotId; ?>)">
                                                <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                                <input type="hidden" name="slot_id" value="<?php echo $slotId; ?>">
                                                <input type="hidden" name="contact_id" id="contact-id-<?php echo $evtId; ?>-<?php echo $slotId; ?>" value="<?php echo $_SESSION['user']['contact_id']; ?>">

                                                <?php if (!has_permission('manage hosting')): ?>
                                                    <span style="font-size: 0.8rem; color: var(--color-text-secondary); display: block; margin-bottom: 8px;">Confirm volunteering?</span>
                                                <?php endif; ?>

                                                <div style="display: flex; gap: 6px;">
                                                    <button type="submit" name="action_signup" class="btn btn-success btn-small" style="padding: 4px 8px; font-size: 0.75rem;">Confirm</button>
                                                    <button type="button" class="btn btn-secondary btn-small" onclick="cancelSignup(<?php echo $evtId; ?>, <?php echo $slotId; ?>)" style="padding: 4px 8px; font-size: 0.75rem; background: rgba(255,255,255,0.05); color: #fff; border: 1px solid var(--border-glass);">Cancel</button>
                                                </div>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
