<?php
/**
 * Librarian-only AJAX endpoint backing the "Add Game" BGG search flow on
 * library.php: ?q=... searches BGG's game database, ?bgg_id=... fetches full
 * details for one game to prefill the add form.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Auth;
use App\BggClient;

Auth::requirePermission('manage library');

try {
    if (isset($_GET['bgg_id'])) {
        $bggId = (int)$_GET['bgg_id'];
        $details = BggClient::thing([$bggId]);
        json_response($details[$bggId] ?? null);
    }

    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) {
        json_response([]);
    }
    json_response(BggClient::search($q));
} catch (\Throwable $e) {
    error_log("library-bgg-search failed: " . $e->getMessage());
    json_response(['error' => 'BGG lookup failed'], 502);
}
