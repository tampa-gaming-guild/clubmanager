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
use App\MembershipService;

$errorMsg = null;
$successMsg = null;
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'upcoming';

// PRG: read flash messages passed back via GET after a redirect
if (isset($_GET['success'])) $successMsg = trim($_GET['success']);
if (isset($_GET['error']))   $errorMsg   = trim($_GET['error']);
$allActiveMembers = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::check()) {
    $result = \App\VolunteerSignupRequest::handle($_POST);
    $successMsg = $result['success'];
    $errorMsg = $result['error'];

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

                <?php
                    $vsEvents = $events;
                    $vsSlotsByEvent = $slotsByEvent;
                    $vsFormAction = function(string $evtDateStr) {
                        $qp = ['highlight' => $evtDateStr];
                        if (isset($_GET['filter'])) $qp['filter'] = $_GET['filter'];
                        return 'volunteers.php?' . http_build_query($qp);
                    };
                    $vsHighlightDate = $_GET['highlight'] ?? null;
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

    <script src="assets/js/volunteer-signup.js<?php echo asset_version('assets/js/volunteer-signup.js'); ?>"></script>
</body>
</html>
