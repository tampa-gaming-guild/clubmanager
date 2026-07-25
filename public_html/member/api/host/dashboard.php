<?php
/**
 * Mobile API (host tools): the Hosting Dashboard shown as a host's primary
 * view. Deliberately looser than index.php's web rule: the web only shows
 * this automatically to someone signed up as a volunteer/host for TODAY'S
 * specific event (Event::getMemberRolesForEvent()), falling back to a
 * manual "view as hosting anyway" link otherwise. On mobile, by request,
 * *any* holder of 'edit checkins' lands here automatically whenever a
 * session is active -- no separate opt-in tap. is_hosting_now is still
 * computed and returned so the client can choose "Hosting: X" vs "Today's
 * Session: X" wording, but it no longer gates whether the dashboard data is
 * returned at all.
 */
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/config/bootstrap.php';

use App\ApiAuth;
use App\Database;
use App\Event;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = ApiAuth::requirePermission('edit checkins');
$contactId = (int)$user['contact_id'];

$activeSession = Event::getActiveSession();
if ($activeSession === null) {
    json_response(['is_hosting_now' => false, 'active_session' => null]);
}

$rolesToday = Event::getMemberRolesForEvent((int)$activeSession['id'], $contactId);
$isHostingNow = !empty($rolesToday);

$appDb = Database::getAppConnection();

$checkinsToday = (int)$appDb->query("SELECT COUNT(*) FROM tgg_checkins WHERE DATE(checked_in_at) = CURDATE()")->fetchColumn();

$pendingStmt = $appDb->query("
    SELECT pp.id, pp.contact_id, pp.type, pp.amount, pp.requested_at, c.display_name
    FROM tgg_pending_payments pp
    LEFT JOIN tgg_contacts c ON c.id = pp.contact_id
    WHERE pp.status = 'pending'
    ORDER BY pp.requested_at ASC
");
$pendingPayments = $pendingStmt->fetchAll();
foreach ($pendingPayments as &$row) {
    if (empty($row['display_name'])) {
        $row['display_name'] = "Member #{$row['contact_id']}";
    }
}
unset($row);

json_response([
    'is_hosting_now' => $isHostingNow,
    'active_session' => [
        'title' => $activeSession['title'],
        'start_time' => $activeSession['start_time'],
        'end_time' => $activeSession['end_time'],
    ],
    'checkins_today' => $checkinsToday,
    'pending_payments' => $pendingPayments,
    'checkins_log' => Event::getTodaysCheckins(),
]);
