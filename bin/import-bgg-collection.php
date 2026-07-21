#!/usr/bin/env php
<?php
/**
 * One-time import: pull the club's existing BoardGameGeek collection into
 * tgg_games as the seed data for the in-app library gallery/librarian tools.
 *
 * After this import, ClubManager is the source of truth going forward -- new
 * edits happen in the librarian admin UI and get pushed out to BGG via
 * App\BggCollectionSync, not the other way around. This script does not
 * re-run automatically and is not wired into any cron job.
 *
 * Since every row here is, by definition, already correctly represented on
 * BGG at import time, imported rows are marked bgg_sync_status = 'synced'
 * with no push required.
 *
 * Defaults to a dry run (logs what would be written, touches no data).
 * Pass --apply to actually write to tgg_games.
 *
 * Usage: php bin/import-bgg-collection.php [--apply]
 */
require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\BggClient;
use App\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the CLI.\n");
}

$apply = in_array('--apply', $argv, true);
$username = 'TampaGamingGuild';

echo "[" . date('Y-m-d H:i:s') . "] [import-bgg-collection] Starting" . ($apply ? " (APPLY mode -- will write to tgg_games)" : " (dry run -- no writes)") . "\n";

try {
    echo "[" . date('Y-m-d H:i:s') . "] [import-bgg-collection] Fetching collection for '{$username}' (BGG may queue this request; will retry with backoff)...\n";
    $collectionItems = BggClient::collection($username);
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] [import-bgg-collection] Failed to fetch collection: " . $e->getMessage() . "\n";
    exit(1);
}
echo "[" . date('Y-m-d H:i:s') . "] [import-bgg-collection] Found " . count($collectionItems) . " item(s) in the collection.\n";

$appDb = Database::getAppConnection();

$existingStmt = $appDb->query("SELECT bgg_id FROM tgg_games");
$existingIds = array_flip($existingStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

$toImport = array_values(array_filter($collectionItems, function ($item) use ($existingIds) {
    return !isset($existingIds[$item['bgg_id']]);
}));
$skipped = count($collectionItems) - count($toImport);

$imported = 0;
$errors = 0;

$insertStmt = $appDb->prepare("
    INSERT INTO tgg_games (
        bgg_id, name, year_published, thumbnail_url, image_url, description,
        min_players, max_players, min_playtime, max_playtime, min_age,
        bgg_rating_bayes, bgg_weight, mechanisms, categories, notes,
        bgg_sync_status, bgg_last_synced_at
    ) VALUES (
        :bgg_id, :name, :year_published, :thumbnail_url, :image_url, :description,
        :min_players, :max_players, :min_playtime, :max_playtime, :min_age,
        :bgg_rating_bayes, :bgg_weight, :mechanisms, :categories, :notes,
        'synced', NOW()
    )
");

// thing() batches internally in chunks of 20, so it's safe to pass the whole list at once.
$bggIds = array_map(fn($item) => $item['bgg_id'], $toImport);
try {
    $details = !empty($bggIds) ? BggClient::thing($bggIds) : [];
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] [import-bgg-collection] Failed to fetch game details: " . $e->getMessage() . "\n";
    exit(1);
}

foreach ($toImport as $item) {
    $bggId = $item['bgg_id'];
    $detail = $details[$bggId] ?? null;

    if ($detail === null) {
        $errors++;
        echo "[" . date('Y-m-d H:i:s') . "] [import-bgg-collection] bgg_id={$bggId} ({$item['name']}): no details returned from BGG thing API, skipping\n";
        continue;
    }

    try {
        echo "[" . date('Y-m-d H:i:s') . "] [import-bgg-collection] bgg_id={$bggId}: importing \"{$detail['name']}\""
            . ($apply ? "\n" : " (dry run)\n");

        if ($apply) {
            $insertStmt->execute([
                'bgg_id' => $bggId,
                'name' => $detail['name'] !== '' ? $detail['name'] : $item['name'],
                'year_published' => $detail['year_published'] ?? $item['year_published'],
                'thumbnail_url' => $detail['thumbnail_url'] ?? $item['thumbnail_url'],
                'image_url' => $detail['image_url'] ?? $item['image_url'],
                'description' => $detail['description'],
                'min_players' => $detail['min_players'],
                'max_players' => $detail['max_players'],
                'min_playtime' => $detail['min_playtime'],
                'max_playtime' => $detail['max_playtime'],
                'min_age' => $detail['min_age'],
                'bgg_rating_bayes' => $detail['bgg_rating_bayes'],
                'bgg_weight' => $detail['bgg_weight'],
                'mechanisms' => json_encode($detail['mechanisms'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'categories' => json_encode($detail['categories'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                // Seed from BGG's collection comment (this club already uses it informally
                // to note things like "owner: Chris F" or storage location) so that
                // context isn't lost on import; the librarian can edit/clear it later.
                'notes' => $item['comment'],
            ]);
        }
        $imported++;
    } catch (\Throwable $e) {
        $errors++;
        error_log("[" . date('Y-m-d H:i:s') . "] [import-bgg-collection] bgg_id={$bggId}: insert failed - " . $e->getMessage());
        echo "[" . date('Y-m-d H:i:s') . "] [import-bgg-collection] bgg_id={$bggId}: insert failed, see error log for details\n";
    }
}

$summary = "[" . date('Y-m-d H:i:s') . "] [import-bgg-collection] Summary: " . count($collectionItems) . " found, "
    . "{$imported} imported, {$skipped} skipped (already present), {$errors} error(s)."
    . ($apply ? "" : " (dry run -- no data was written; re-run with --apply to write)");
echo $summary . "\n";
