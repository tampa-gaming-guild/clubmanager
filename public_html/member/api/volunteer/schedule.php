<?php
/**
 * Mobile API: upcoming volunteer schedule, mirroring volunteers.php's default
 * "Upcoming" list view. Every logged-in member can see who's volunteering --
 * same visibility as the web list, no extra permission gate -- plus whether
 * each filled slot is their own signup.
 */
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/config/bootstrap.php';

use App\ApiAuth;
use App\Event;
use App\EventSlot;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = ApiAuth::requireAuth();
$contactId = (int)$user['contact_id'];

$today = date('Y-m-d 00:00:00');
$events = array_values(array_filter(Event::getEvents(), fn($e) => $e['start_time'] >= $today));

$slotsByEvent = EventSlot::getSlotsForEvents(array_column($events, 'id'));

$result = [];
foreach ($events as $evt) {
    $evtId = (int)$evt['id'];

    $volunteersBySlot = [];
    foreach (Event::getVolunteers($evtId) as $vol) {
        $volunteersBySlot[(int)$vol['slot_id']] = $vol;
    }

    $slots = [];
    foreach ($slotsByEvent[$evtId] ?? [] as $slot) {
        $vol = $volunteersBySlot[(int)$slot['id']] ?? null;
        $slots[] = [
            'id' => (int)$slot['id'],
            'slot_label' => $slot['slot_label'],
            'slot_type' => $slot['slot_type'],
            'filled' => $vol !== null,
            'is_self' => $vol !== null && (int)$vol['contact_id'] === $contactId,
            'volunteer_name' => $vol['display_name'] ?? null,
            'status' => $vol['status'] ?? null,
        ];
    }

    $result[] = [
        'id' => $evtId,
        'title' => $evt['title'],
        'start_time' => $evt['start_time'],
        'end_time' => $evt['end_time'],
        'slots' => $slots,
    ];
}

json_response(['events' => $result]);
