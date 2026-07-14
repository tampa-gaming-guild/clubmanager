<?php
/**
 * Public Volunteer Schedule
 * Displays upcoming events and the signup status of each event's volunteer slots
 * (defined per event in the admin scheduler; see EventSlot).
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Event;
use App\EventSlot;
use App\Auth;
use App\Database;
use App\MembershipService;

$errorMsg = null;
$successMsg = null;
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'upcoming';

// PRG: read flash messages passed back via GET after a redirect
if (isset($_GET['success'])) $successMsg = trim($_GET['success']);
if (isset($_GET['error']))   $errorMsg   = trim($_GET['error']);
$allActiveMembers = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::check()) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token.";
    } else {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $contactId = (int)($_POST['contact_id'] ?? 0);
        $slotId = (int)($_POST['slot_id'] ?? 0);

        if (isset($_POST['action_signup'])) {
            try {
                if (empty($slotId)) {
                    throw new Exception("Slot is required.");
                }
                if (empty($contactId)) {
                    throw new Exception("Member selection is required.");
                }

                if (!has_permission('volunteer')) {
                    throw new Exception("You do not have permission to sign up as a volunteer.");
                }
                // If not manage hosting, force contactId to the logged-in user
                if (!has_permission('manage hosting')) {
                    $contactId = $_SESSION['user']['contact_id'];
                }

                $slot = Event::signupVolunteer($slotId, $contactId);

                // Fetch member name to display in the success message
                $appDb = Database::getAppConnection();
                $stmtName = $appDb->prepare("SELECT display_name FROM tgg_contacts WHERE id = :id LIMIT 1");
                $stmtName->execute(['id' => $contactId]);
                $displayName = $stmtName->fetchColumn() ?: "Member #{$contactId}";

                $successMsg = "Success! Signed up {$displayName} as {$slot['slot_label']} volunteer.";
            } catch (Exception $e) {
                $errorMsg = safe_err("Volunteer signup failed: ", $e);
            }
        } elseif (isset($_POST['action_signup_all'])) {
            try {
                if (empty($contactId)) {
                    throw new Exception("Member selection is required.");
                }

                if (!has_permission('volunteer')) {
                    throw new Exception("You do not have permission to sign up as a volunteer.");
                }
                // If not manage hosting, force contactId to the logged-in user
                if (!has_permission('manage hosting')) {
                    $contactId = $_SESSION['user']['contact_id'];
                }

                $results = Event::signupVolunteerAllOpenSlots($eventId, $contactId);

                $appDb = Database::getAppConnection();
                $stmtName = $appDb->prepare("SELECT display_name FROM tgg_contacts WHERE id = :id LIMIT 1");
                $stmtName->execute(['id' => $contactId]);
                $displayName = $stmtName->fetchColumn() ?: "Member #{$contactId}";

                $signedUp = array_column(array_filter($results, fn($r) => $r['success']), 'role');
                $failed = array_filter($results, fn($r) => !$r['success']);

                if (empty($results)) {
                    $errorMsg = "All volunteer roles for this event are already filled.";
                } elseif (empty($failed)) {
                    $successMsg = "Success! Signed up {$displayName} as " . implode(', ', $signedUp) . " volunteer.";
                } else {
                    $errorMsg = "Could not fill: " . implode('; ', array_map(fn($r) => "{$r['role']} ({$r['error']})", $failed));
                    if (!empty($signedUp)) {
                        $successMsg = "Signed up {$displayName} as " . implode(', ', $signedUp) . ".";
                    }
                }
            } catch (Exception $e) {
                $errorMsg = safe_err("Bulk volunteer signup failed: ", $e);
            }
        } elseif (isset($_POST['action_delete'])) {
            try {
                if (empty($slotId)) {
                    throw new Exception("Slot is required.");
                }
                if (empty($contactId)) {
                    throw new Exception("Member ID is required.");
                }

                $isAdmin = has_permission('manage hosting');
                $isSelf = ($contactId === (int)$_SESSION['user']['contact_id']);

                if (!$isAdmin && !$isSelf) {
                    throw new Exception("You are not authorized to delete this signup.");
                }

                $slot = EventSlot::getSlot($slotId);
                if (!$slot) {
                    throw new Exception("Slot not found.");
                }

                if (!$isAdmin) {
                    $event = Event::getEvent((int)$slot['event_id']);
                    if (!$event) {
                        throw new Exception("Event not found.");
                    }
                    $eventDate = date('Y-m-d', strtotime($event['start_time']));
                    $today = date('Y-m-d');
                    if ($eventDate < $today) {
                        throw new Exception("Members can only delete signups dated today or later.");
                    }
                }

                Event::cancelVolunteer($slotId, $contactId);

                $appDb = Database::getAppConnection();
                $stmtName = $appDb->prepare("SELECT display_name FROM tgg_contacts WHERE id = :id LIMIT 1");
                $stmtName->execute(['id' => $contactId]);
                $displayName = $stmtName->fetchColumn() ?: "Member #{$contactId}";

                $successMsg = "Success! Removed {$displayName} from {$slot['slot_label']} volunteer slot.";
            } catch (Exception $e) {
                $errorMsg = safe_err("Failed to delete volunteer signup: ", $e);
            }
        }
    }

    // PRG: redirect so a page refresh doesn't resubmit the form.
    // The form action already embeds ?highlight=&filter= so they're in $_GET here.
    $qp = ['filter' => $_GET['filter'] ?? 'upcoming'];
    if (!empty($_GET['highlight'])) $qp['highlight'] = $_GET['highlight'];
    if ($successMsg) $qp['success'] = $successMsg;
    if ($errorMsg)   $qp['error']   = $errorMsg;
    redirect('volunteers.php?' . http_build_query($qp));
}

try {
    $allEvents = Event::getEvents();
    $events = [];
    $today = date('Y-m-d 00:00:00');
    
    foreach ($allEvents as $evt) {
        if ($filter === 'upcoming') {
            $evtDate = date('Y-m-d', strtotime($evt['start_time']));
            $isHighlighted = isset($_GET['highlight']) && $_GET['highlight'] === $evtDate;
            if ($evt['start_time'] >= $today || $isHighlighted) {
                $events[] = $evt;
            }
        } else {
            $events[] = $evt;
        }
    }
    
    $slotsByEvent = EventSlot::getSlotsForEvents(array_column($events, 'id'));

    if (has_permission('manage hosting')) {
        $allActiveMembers = MembershipService::getMembersList();
    }
} catch (Exception $e) {
    $events = [];
    $slotsByEvent = [];
    $errorMsg = safe_err("Unable to load schedule: ", $e);
}

// Slot type drives the accent color (and the credit weight -- see EventSlot::creditKey)
$slotTypeColors = [
    'open'    => ['var' => '--color-success', 'rgb' => '34,197,94'],
    'close'   => ['var' => '--color-danger',  'rgb' => '239,68,68'],
    'greeter' => ['var' => '--color-primary', 'rgb' => '59,130,246'],
];
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
            align-items: center;
            margin-bottom: 25px;
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
        .event-group-header td {
            background: rgba(255, 255, 255, 0.05) !important;
            font-family: var(--font-heading);
            font-size: 1rem;
            font-weight: 600;
            color: #fff !important;
            border-bottom: 1px solid rgba(255,255,255,0.08) !important;
            padding: 14px 16px !important;
        }
        .event-group-header.highlighted {
            scroll-margin-top: 100px;
        }
        .event-group-header.highlighted td {
            background: rgba(34, 197, 94, 0.15) !important;
            border-left: 5px solid var(--color-success, #22c55e) !important;
            border-bottom: 1px solid rgba(34, 197, 94, 0.3) !important;
        }
        .role-subrow td {
            padding: 12px 16px 12px 30px !important;
            font-size: 0.9rem;
        }
        .role-subrow:hover {
            background: rgba(255, 255, 255, 0.01) !important;
        }
        .volunteer-status-needed {
            color: hsl(38, 92%, 55%);
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .role-bullet {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 10px;
        }
        .bullet-open { background-color: var(--color-success); }
        .bullet-close { background-color: var(--color-danger); }
        .bullet-greeter { background-color: var(--color-primary); }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $navActive = 'volunteers'; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content">
            <?php if ($errorMsg): ?>
                <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
            <?php endif; ?>

            <?php if ($successMsg): ?>
                <div class="alert alert-success"><?php echo e($successMsg); ?></div>
            <?php endif; ?>

            <div class="glass-panel">
                <div class="filter-toggle-container">
                    <div>
                        <h2 style="margin-bottom: 5px;">Volunteer Schedule</h2>
                        <p style="color: var(--color-text-secondary); font-size: 0.9rem; margin-bottom: 0;">See who is helping out, or select an open slot to sign up on the calendar.</p>
                    </div>
                    <div class="filter-toggle">
                        <a href="volunteers.php?filter=upcoming" class="<?php echo $filter === 'upcoming' ? 'active' : ''; ?>">Upcoming Only</a>
                        <a href="volunteers.php?filter=all" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">All Events</a>
                    </div>
                </div>

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
                            <?php if (empty($events)): ?>
                                <tr>
                                    <td colspan="3" style="text-align: center; color: var(--color-text-secondary); padding: 40px;">
                                        No events scheduled.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $highlightedAdded = false; ?>
                                <?php foreach ($events as $evt): ?>
                                    <?php 
                                        $evtId = (int)$evt['id'];
                                        $vols = Event::getVolunteers($evtId);

                                        // Map slot id -> volunteer info
                                        $slotVolunteers = [];
                                        foreach ($vols as $vol) {
                                            $slotVolunteers[(int)$vol['slot_id']] = $vol;
                                        }
                                        $eventSlots = $slotsByEvent[$evtId] ?? [];
                                        $openSlotCount = count(array_filter($eventSlots, fn($s) => !isset($slotVolunteers[(int)$s['id']])));

                                        $eventDate = date('F d, Y (l)', strtotime($evt['start_time']));
                                        $eventTime = date('g:i A', strtotime($evt['start_time'])) . ' - ' . date('g:i A', strtotime($evt['end_time']));
                                        
                                        $evtDateStr = date('Y-m-d', strtotime($evt['start_time']));
                                        $isHighlighted = isset($_GET['highlight']) && $_GET['highlight'] === $evtDateStr;
                                        $trClass = 'event-group-header' . ($isHighlighted ? ' highlighted' : '');
                                        $trIdAttr = '';
                                        if ($isHighlighted && !$highlightedAdded) {
                                            $trIdAttr = ' id="highlighted-event"';
                                            $highlightedAdded = true;
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
                                                            <form action="volunteers.php?highlight=<?php echo $evtDateStr; ?><?php echo isset($_GET['filter']) ? '&filter=' . urlencode($_GET['filter']) : ''; ?>" method="POST" style="display: inline;" onsubmit="return validateAdminSignup(this, <?php echo $evtId; ?>, 'ALL')">
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

                                            // Slot type drives the bullet + accent color
                                            $bulletClass = 'bullet-' . $slot['slot_type'];
                                            $isMe = $hasVol && Auth::check() && $volContactId === (int)($_SESSION['user']['contact_id'] ?? 0);
                                            $typeColors = $slotTypeColors[$slot['slot_type']] ?? $slotTypeColors['open'];
                                            $roleColorVar = $typeColors['var'];
                                            $roleRgb = $typeColors['rgb'];

                                            // Link to the specific event on the calendar page
                                            $monthStr = date('m', strtotime($evt['start_time']));
                                            $yearStr = date('Y', strtotime($evt['start_time']));
                                            $signupLink = "calendar.php?month={$monthStr}&year={$yearStr}#event-{$evtId}";
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
                                                        
                                                        $canDelete = Auth::check() && (
                                                            has_permission('manage hosting') || 
                                                            ($volContactId === (int)$_SESSION['user']['contact_id'] && $isTodayOrLater)
                                                        );
                                                    ?>
                                                    <?php if ($canDelete): ?>
                                                        <form action="volunteers.php?highlight=<?php echo $evtDateStr; ?><?php echo isset($_GET['filter']) ? '&filter=' . urlencode($_GET['filter']) : ''; ?>" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this volunteer signup?');">
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
                                                    <?php if (!Auth::check()): ?>
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
                                                            <form action="volunteers.php?highlight=<?php echo $evtDateStr; ?><?php echo isset($_GET['filter']) ? '&filter=' . urlencode($_GET['filter']) : ''; ?>" method="POST" style="display: inline;" onsubmit="return validateAdminSignup(this, <?php echo $evtId; ?>, <?php echo $slotId; ?>)">
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

    <script>
    window.addEventListener('DOMContentLoaded', () => {
        const el = document.getElementById('highlighted-event');
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });

    // slotKey is a slot id, or the literal 'ALL' for the sign-up-for-everything widget
    function showSignupConfirm(evtId, slotKey) {
        document.getElementById('btn-container-' + evtId + '-' + slotKey).style.display = 'none';
        document.getElementById('confirm-container-' + evtId + '-' + slotKey).style.display = 'block';
    }

    function cancelSignup(evtId, slotKey) {
        document.getElementById('confirm-container-' + evtId + '-' + slotKey).style.display = 'none';
        document.getElementById('btn-container-' + evtId + '-' + slotKey).style.display = 'block';

        // Reset admin selection if applicable
        const radioSelf = document.querySelector('input[name="signup_type_' + evtId + '_' + slotKey + '"][value="self"]');
        if (radioSelf) {
            radioSelf.checked = true;
            toggleAdminSignupType(evtId, slotKey, 'self');
        }
        const searchInput = document.querySelector('#admin-search-' + evtId + '-' + slotKey + ' input');
        if (searchInput) {
            searchInput.value = '';
        }
    }

    function toggleAdminSignupType(evtId, slotKey, type) {
        const searchDiv = document.getElementById('admin-search-' + evtId + '-' + slotKey);
        const contactIdInput = document.getElementById('contact-id-' + evtId + '-' + slotKey);
        if (type === 'other') {
            searchDiv.style.display = 'block';
            contactIdInput.value = ''; // Clear so they must select
            const inputField = searchDiv.querySelector('input');
            if (inputField) {
                inputField.focus();
            }
        } else {
            searchDiv.style.display = 'none';
            contactIdInput.value = '<?php echo Auth::check() ? $_SESSION['user']['contact_id'] : 0; ?>';
        }
    }

    function updateMemberId(input, evtId, slotKey) {
        const val = input.value;
        const match = val.match(/\(ID:\s*(\d+)\)/);
        const contactIdInput = document.getElementById('contact-id-' + evtId + '-' + slotKey);
        if (match) {
            contactIdInput.value = match[1];
        } else {
            // Fallback: check if the exact text matches one option's value in the datalist
            const datalist = document.getElementById('members-list');
            if (datalist) {
                let found = false;
                for (let option of datalist.options) {
                    if (option.value === val) {
                        const optMatch = option.value.match(/\(ID:\s*(\d+)\)/);
                        if (optMatch) {
                            contactIdInput.value = optMatch[1];
                            found = true;
                            break;
                        }
                    }
                }
                if (!found) {
                    contactIdInput.value = '';
                }
            } else {
                contactIdInput.value = '';
            }
        }
    }

    function validateAdminSignup(form, evtId, slotKey) {
        const contactIdInput = document.getElementById('contact-id-' + evtId + '-' + slotKey);
        const radioOther = document.querySelector('input[name="signup_type_' + evtId + '_' + slotKey + '"][value="other"]');
        if (radioOther && radioOther.checked) {
            if (!contactIdInput.value || contactIdInput.value === '<?php echo Auth::check() ? $_SESSION['user']['contact_id'] : 0; ?>') {
                alert("Please search and select a valid member from the dropdown list.");
                return false;
            }
        }
        return true;
    }
    </script>
</body>
</html>
