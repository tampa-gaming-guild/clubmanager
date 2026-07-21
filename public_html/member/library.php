<?php
/**
 * Board Game Library — public filterable/sortable gallery served entirely
 * from the local tgg_games cache (no live BGG calls on page load), with
 * inline add/edit/remove/loan controls for members holding the 'manage
 * library' permission. Loan (member-lent-to-club) and BGG-sync-status data
 * is librarian-only and never rendered here for any other visitor, logged
 * in or not.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Auth;
use App\Database;
use App\GameLibraryService;

$canManageLibrary = has_permission('manage library');
$errorMsg = null;
$successMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requirePermission('manage library');
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please try again.";
    } else {
        $actorId = (int)$_SESSION['user']['contact_id'];

        // Post/Redirect/Get: a successful mutation redirects (preserving the
        // active filters) so refreshing the resulting page re-GETs it instead
        // of re-submitting the form -- matches admin/memberships.php's
        // ?success= convention. Errors intentionally do NOT redirect, so the
        // just-submitted form state stays visible for the user to fix.
        try {
            if (isset($_POST['add_game'])) {
                GameLibraryService::addGame([
                    'bgg_id' => (int)($_POST['bgg_id'] ?? 0),
                    'name' => trim($_POST['name'] ?? ''),
                    'year_published' => $_POST['year_published'] !== '' ? (int)$_POST['year_published'] : null,
                    'thumbnail_url' => trim($_POST['thumbnail_url'] ?? '') ?: null,
                    'image_url' => trim($_POST['image_url'] ?? '') ?: null,
                    'description' => trim($_POST['description'] ?? '') ?: null,
                    'min_players' => $_POST['min_players'] !== '' ? (int)$_POST['min_players'] : null,
                    'max_players' => $_POST['max_players'] !== '' ? (int)$_POST['max_players'] : null,
                    'min_playtime' => $_POST['min_playtime'] !== '' ? (int)$_POST['min_playtime'] : null,
                    'max_playtime' => $_POST['max_playtime'] !== '' ? (int)$_POST['max_playtime'] : null,
                    'min_age' => $_POST['min_age'] !== '' ? (int)$_POST['min_age'] : null,
                    'bgg_rating_bayes' => $_POST['bgg_rating_bayes'] !== '' ? (float)$_POST['bgg_rating_bayes'] : null,
                    'bgg_weight' => $_POST['bgg_weight'] !== '' ? (float)$_POST['bgg_weight'] : null,
                    'mechanisms' => array_filter(array_map('trim', explode(',', $_POST['mechanisms'] ?? ''))),
                    'categories' => array_filter(array_map('trim', explode(',', $_POST['categories'] ?? ''))),
                    'notes' => trim($_POST['notes'] ?? '') ?: null,
                    'owner_contact_id' => $_POST['owner_contact_id'] !== '' ? (int)$_POST['owner_contact_id'] : null,
                ], $actorId);
                $successMsg = "Game added to the library.";
            } elseif (isset($_POST['update_game'])) {
                GameLibraryService::updateGame((int)$_POST['game_id'], [
                    'name' => trim($_POST['name'] ?? ''),
                    'year_published' => $_POST['year_published'] !== '' ? (int)$_POST['year_published'] : null,
                    'description' => trim($_POST['description'] ?? '') ?: null,
                    'min_players' => $_POST['min_players'] !== '' ? (int)$_POST['min_players'] : null,
                    'max_players' => $_POST['max_players'] !== '' ? (int)$_POST['max_players'] : null,
                    'min_playtime' => $_POST['min_playtime'] !== '' ? (int)$_POST['min_playtime'] : null,
                    'max_playtime' => $_POST['max_playtime'] !== '' ? (int)$_POST['max_playtime'] : null,
                    'min_age' => $_POST['min_age'] !== '' ? (int)$_POST['min_age'] : null,
                    'mechanisms' => array_filter(array_map('trim', explode(',', $_POST['mechanisms'] ?? ''))),
                    'categories' => array_filter(array_map('trim', explode(',', $_POST['categories'] ?? ''))),
                    'notes' => trim($_POST['notes'] ?? '') ?: null,
                    'owner_contact_id' => $_POST['owner_contact_id'] !== '' ? (int)$_POST['owner_contact_id'] : null,
                ], $actorId);
                $successMsg = "Game updated.";
            } elseif (isset($_POST['remove_game'])) {
                GameLibraryService::removeGame((int)$_POST['game_id'], $actorId);
                $successMsg = "Game removed from the library.";
            } elseif (isset($_POST['retry_sync'])) {
                $result = GameLibraryService::retryBggSync((int)$_POST['game_id']);
                $successMsg = $result['success'] ? "BGG sync succeeded." : "BGG sync still failing: " . ($result['message'] ?? 'Unknown error');
            }

            if ($successMsg !== null) {
                $redirectParams = array_filter($_GET, fn($k) => $k !== 'success', ARRAY_FILTER_USE_KEY);
                $redirectParams['success'] = $successMsg;
                header('Location: library.php?' . http_build_query($redirectParams));
                exit;
            }
        } catch (Exception $e) {
            $errorMsg = safe_err("Failed to save: ", $e);
        }
    }
}

if (isset($_GET['success'])) {
    $successMsg = $_GET['success'];
}

$appDb = Database::getAppConnection();
$rows = $appDb->query("
    SELECT id, bgg_id, name, year_published, thumbnail_url, image_url, description,
           min_players, max_players, min_playtime, max_playtime, min_age,
           bgg_rating_bayes, bgg_weight, mechanisms, categories, notes,
           owner_contact_id, bgg_sync_status, bgg_last_sync_error
    FROM tgg_games
    WHERE is_deleted = 0
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$allMechanisms = [];
$allCategories = [];
foreach ($rows as &$row) {
    $row['mechanisms'] = json_decode($row['mechanisms'] ?? '[]', true) ?: [];
    $row['categories'] = json_decode($row['categories'] ?? '[]', true) ?: [];
    foreach ($row['mechanisms'] as $m) {
        $allMechanisms[$m] = true;
    }
    foreach ($row['categories'] as $c) {
        $allCategories[$c] = true;
    }
}
unset($row);
$allMechanisms = array_keys($allMechanisms);
sort($allMechanisms);
$allCategories = array_keys($allCategories);
sort($allCategories);

// Owner (lending-member) display names -- only ever resolved/rendered for
// librarians; never exposed to the public or to logged-in non-librarians.
$ownerNames = [];
if ($canManageLibrary) {
    $ownerIds = array_values(array_unique(array_filter(array_map(fn($g) => $g['owner_contact_id'] !== null ? (int)$g['owner_contact_id'] : null, $rows))));
    if (!empty($ownerIds)) {
        $placeholders = implode(',', array_fill(0, count($ownerIds), '?'));
        $ownerStmt = $appDb->prepare("SELECT id, display_name FROM tgg_contacts WHERE id IN ($placeholders)");
        $ownerStmt->execute($ownerIds);
        $ownerNames = $ownerStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}

// Filters (GET, server-rendered -- the collection is small enough that a full
// page reload per filter change is simpler than a JS filtering layer).
$playerCount = ($_GET['players'] ?? '') !== '' ? max(1, (int)$_GET['players']) : null;
$maxPlaytime = ($_GET['playtime'] ?? '') !== '' ? max(1, (int)$_GET['playtime']) : null;
$mechanism = trim($_GET['mechanism'] ?? '');
$category = trim($_GET['category'] ?? '');
$sort = $_GET['sort'] ?? 'name';

$games = array_values(array_filter($rows, function ($g) use ($playerCount, $maxPlaytime, $mechanism, $category) {
    if ($playerCount !== null) {
        $min = $g['min_players'] !== null ? (int)$g['min_players'] : null;
        $max = $g['max_players'] !== null ? (int)$g['max_players'] : null;
        if ($min === null && $max === null) {
            return false;
        }
        if ($min !== null && $playerCount < $min) {
            return false;
        }
        if ($max !== null && $playerCount > $max) {
            return false;
        }
    }
    if ($maxPlaytime !== null) {
        $playtime = $g['max_playtime'] ?? $g['min_playtime'];
        if ($playtime === null || (int)$playtime > $maxPlaytime) {
            return false;
        }
    }
    if ($mechanism !== '' && !in_array($mechanism, $g['mechanisms'], true)) {
        return false;
    }
    if ($category !== '' && !in_array($category, $g['categories'], true)) {
        return false;
    }
    return true;
}));

usort($games, function ($a, $b) use ($sort) {
    switch ($sort) {
        case 'rating':
            return ($b['bgg_rating_bayes'] ?? 0) <=> ($a['bgg_rating_bayes'] ?? 0);
        case 'weight':
            return ($a['bgg_weight'] ?? 999) <=> ($b['bgg_weight'] ?? 999);
        case 'playtime':
            return ($a['max_playtime'] ?? $a['min_playtime'] ?? 999999) <=> ($b['max_playtime'] ?? $b['min_playtime'] ?? 999999);
        case 'name':
        default:
            return strcasecmp($a['name'], $b['name']);
    }
});

/** Format a game's player-count range, e.g. "2-4" or "3+" or "Unknown". */
function formatPlayerRange(?int $min, ?int $max): string {
    if ($min === null && $max === null) {
        return 'Unknown';
    }
    if ($min !== null && $max !== null) {
        return $min === $max ? (string)$min : "{$min}-{$max}";
    }
    return $min !== null ? "{$min}+" : "Up to {$max}";
}

/** Format a game's playtime range in minutes, e.g. "30-60 min". */
function formatPlaytimeRange(?int $min, ?int $max): string {
    if ($min === null && $max === null) {
        return 'Unknown';
    }
    if ($min !== null && $max !== null && $min !== $max) {
        return "{$min}-{$max} min";
    }
    return ($min ?? $max) . ' min';
}

function syncBadgeClass(string $status): string {
    switch ($status) {
        case 'synced': return 'badge-active';
        case 'failed': return 'badge-expired';
        default: return 'badge-free';
    }
}

$hasFilters = $playerCount !== null || $maxPlaytime !== null || $mechanism !== '' || $category !== '' || $sort !== 'name';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library - Tampa Gaming Guild</title>
    <meta name="description" content="Browse Tampa Gaming Guild's library of <?php echo count($rows); ?>+ board games, available to play at the club. Filter by player count, playing time, and mechanisms.">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="favicon.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="assets/css/marketing.css<?php echo asset_version('assets/css/marketing.css'); ?>">
    <link rel="stylesheet" href="assets/css/library.css<?php echo asset_version('assets/css/library.css'); ?>">
</head>
<body>
    <div class="app-container">
        <?php $navActive = 'library'; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content">
            <section class="marketing-section" style="max-width: 1300px;">
                <h1 class="library-page-title">Library</h1>
                <p class="section-intro">Browse the <?php echo count($rows); ?> board games in our collection, available to play at the club. Full collection also listed on <a href="https://boardgamegeek.com/collection/user/TampaGamingGuild" target="_blank" rel="noopener">BoardGameGeek</a>.</p>

                <?php if ($errorMsg): ?>
                    <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                <?php endif; ?>
                <?php if ($successMsg): ?>
                    <div class="alert alert-success"><?php echo e($successMsg); ?></div>
                <?php endif; ?>

                <form method="GET" action="library.php" class="library-filter-bar">
                    <div class="filter-field">
                        <label for="filter-players">Players</label>
                        <select id="filter-players" name="players">
                            <option value="">Any</option>
                            <?php for ($i = 1; $i <= 8; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $playerCount === $i ? 'selected' : ''; ?>><?php echo $i; ?><?php echo $i === 8 ? '+' : ''; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="filter-field">
                        <label for="filter-playtime">Max Playtime</label>
                        <select id="filter-playtime" name="playtime">
                            <option value="">Any</option>
                            <?php foreach ([30 => 'Under 30 min', 60 => 'Under 1 hr', 90 => 'Under 1.5 hrs', 120 => 'Under 2 hrs', 180 => 'Under 3 hrs'] as $mins => $label): ?>
                                <option value="<?php echo $mins; ?>" <?php echo $maxPlaytime === $mins ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-field">
                        <label for="filter-mechanism">Mechanism</label>
                        <select id="filter-mechanism" name="mechanism">
                            <option value="">Any</option>
                            <?php foreach ($allMechanisms as $m): ?>
                                <option value="<?php echo e($m); ?>" <?php echo $mechanism === $m ? 'selected' : ''; ?>><?php echo e($m); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-field">
                        <label for="filter-category">Category</label>
                        <select id="filter-category" name="category">
                            <option value="">Any</option>
                            <?php foreach ($allCategories as $c): ?>
                                <option value="<?php echo e($c); ?>" <?php echo $category === $c ? 'selected' : ''; ?>><?php echo e($c); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-field">
                        <label for="filter-sort">Sort By</label>
                        <select id="filter-sort" name="sort">
                            <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Name</option>
                            <option value="rating" <?php echo $sort === 'rating' ? 'selected' : ''; ?>>BGG Rating</option>
                            <option value="weight" <?php echo $sort === 'weight' ? 'selected' : ''; ?>>Complexity (Light First)</option>
                            <option value="playtime" <?php echo $sort === 'playtime' ? 'selected' : ''; ?>>Playtime (Short First)</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="padding: 9px 20px;">Filter</button>
                    <?php if ($hasFilters): ?>
                        <a href="library.php" class="btn btn-secondary" style="padding: 9px 15px; display: flex; align-items: center; justify-content: center;">Clear</a>
                    <?php endif; ?>
                </form>

                <div class="library-toolbar">
                    <p class="library-result-count"><?php echo count($games); ?> game<?php echo count($games) === 1 ? '' : 's'; ?><?php echo $hasFilters ? ' match these filters' : ' in our collection'; ?>.</p>
                    <div class="library-toolbar-actions">
                        <div class="view-toggle" id="view-toggle" role="group" aria-label="Gallery or list view">
                            <button type="button" class="view-toggle-btn active" data-view="grid" title="Gallery view">⊞ Gallery</button>
                            <button type="button" class="view-toggle-btn" data-view="list" title="List view">☰ List</button>
                        </div>
                        <?php if ($canManageLibrary): ?>
                            <button type="button" class="btn btn-primary" id="add-game-btn">+ Add Game</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="game-grid" id="game-container">
                    <?php foreach ($games as $game): ?>
                        <?php
                        $ownerName = $game['owner_contact_id'] !== null ? ($ownerNames[$game['owner_contact_id']] ?? "Member #{$game['owner_contact_id']}") : null;
                        $cardData = $canManageLibrary ? json_encode([
                            'id' => $game['id'],
                            'name' => $game['name'],
                            'year_published' => $game['year_published'],
                            'description' => $game['description'],
                            'min_players' => $game['min_players'],
                            'max_players' => $game['max_players'],
                            'min_playtime' => $game['min_playtime'],
                            'max_playtime' => $game['max_playtime'],
                            'min_age' => $game['min_age'],
                            'mechanisms' => implode(', ', $game['mechanisms']),
                            'categories' => implode(', ', $game['categories']),
                            'notes' => $game['notes'],
                            'owner_contact_id' => $game['owner_contact_id'],
                            'owner_name' => $ownerName,
                        ], JSON_HEX_APOS | JSON_HEX_QUOT) : null;
                        ?>
                        <div class="game-card">
                            <a class="game-card-link" href="https://boardgamegeek.com/boardgame/<?php echo (int)$game['bgg_id']; ?>" target="_blank" rel="noopener">
                                <?php if ($game['thumbnail_url']): ?>
                                    <img class="game-card-thumb" src="<?php echo e($game['thumbnail_url']); ?>" alt="<?php echo e($game['name']); ?>" loading="lazy">
                                <?php else: ?>
                                    <div class="game-card-thumb game-card-thumb-placeholder">🎲</div>
                                <?php endif; ?>
                            </a>
                            <div class="game-card-body">
                                <h3 class="game-card-title">
                                    <a class="game-card-link" href="https://boardgamegeek.com/boardgame/<?php echo (int)$game['bgg_id']; ?>" target="_blank" rel="noopener"><?php echo e($game['name']); ?></a>
                                    <?php echo $game['year_published'] ? ' <span class="game-card-year">(' . (int)$game['year_published'] . ')</span>' : ''; ?>
                                </h3>
                                <div class="game-card-meta">
                                    <span title="Players">👥 <?php echo e(formatPlayerRange($game['min_players'] !== null ? (int)$game['min_players'] : null, $game['max_players'] !== null ? (int)$game['max_players'] : null)); ?></span>
                                    <span title="Playtime">⏱ <?php echo e(formatPlaytimeRange($game['min_playtime'] !== null ? (int)$game['min_playtime'] : null, $game['max_playtime'] !== null ? (int)$game['max_playtime'] : null)); ?></span>
                                    <?php if ($game['bgg_rating_bayes']): ?>
                                        <span title="BGG Rating">⭐ <?php echo number_format((float)$game['bgg_rating_bayes'], 1); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($game['mechanisms'])): ?>
                                    <div class="game-card-chips">
                                        <?php foreach (array_slice($game['mechanisms'], 0, 3) as $m): ?>
                                            <span class="badge badge-free"><?php echo e($m); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($canManageLibrary): ?>
                                    <div class="game-card-librarian">
                                        <div class="game-card-librarian-status">
                                            <span class="badge <?php echo syncBadgeClass($game['bgg_sync_status']); ?>" title="<?php echo e($game['bgg_last_sync_error'] ?? ''); ?>">BGG: <?php echo e(ucfirst($game['bgg_sync_status'])); ?></span>
                                            <?php if ($ownerName): ?>
                                                <span class="badge badge-volunteer">On loan from <?php echo e($ownerName); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="game-card-librarian-actions">
                                            <button type="button" class="btn btn-secondary btn-sm edit-game-btn" data-game='<?php echo $cardData; ?>'>Edit</button>
                                            <?php if ($game['bgg_sync_status'] === 'failed'): ?>
                                                <form method="POST" action="library.php" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                                    <input type="hidden" name="game_id" value="<?php echo (int)$game['id']; ?>">
                                                    <button type="submit" name="retry_sync" class="btn btn-secondary btn-sm">Retry Sync</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" action="library.php" class="inline-form" onsubmit="return confirm('Remove &quot;<?php echo e(addslashes($game['name'])); ?>&quot; from the library? This also removes it from the BGG collection.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                                <input type="hidden" name="game_id" value="<?php echo (int)$game['id']; ?>">
                                                <button type="submit" name="remove_game" class="btn btn-danger btn-sm">Remove</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="library-bgg-attribution">
                    <a href="https://boardgamegeek.com" target="_blank" rel="noopener">
                        <img src="https://cf.geekdo-images.com/HZy35cmzmmyV9BarSuk6ug__small/img/gbE7sulIurZE_Tx8EQJXnZSKI6w=/fit-in/200x150/filters:strip_icc()/pic7779581.png" alt="Powered by BGG" width="200" height="59" loading="lazy">
                    </a>
                </div>
            </section>
        </main>

        <?php include __DIR__ . '/partials/footer.php'; ?>
    </div>

    <?php if ($canManageLibrary): ?>
    <!-- Add/Edit Game Modal -->
    <div id="game-modal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(5px);">
        <div class="modal-content glass-panel" style="background: rgba(30, 30, 40, 0.97); margin: 3% auto; padding: 25px; border: 1px solid rgba(255, 255, 255, 0.1); width: 90%; max-width: 600px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); max-height: 90vh; overflow-y: auto;">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 15px; margin-bottom: 20px;">
                <h3 id="game-modal-title" style="margin: 0; color: #fff;">Add Game</h3>
                <span class="close" onclick="closeGameModal()" style="color: rgba(255,255,255,0.6); font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>
            </div>

            <div id="bgg-search-section">
                <div class="form-group">
                    <label for="bgg-search-input">Search BoardGameGeek</label>
                    <input type="text" id="bgg-search-input" placeholder="Start typing a game name..." autocomplete="off">
                </div>
                <div id="bgg-search-results" class="bgg-search-results"></div>
            </div>

            <form method="POST" action="library.php" id="game-form" style="display: none;">
                <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                <input type="hidden" name="bgg_id" id="field-bgg_id">
                <input type="hidden" name="game_id" id="field-game_id">
                <input type="hidden" name="thumbnail_url" id="field-thumbnail_url">
                <input type="hidden" name="image_url" id="field-image_url">
                <input type="hidden" name="bgg_rating_bayes" id="field-bgg_rating_bayes">
                <input type="hidden" name="bgg_weight" id="field-bgg_weight">

                <div id="bgg-selected-summary" class="bgg-selected-summary" style="display: none;"></div>

                <div class="form-group">
                    <label for="field-name">Name</label>
                    <input type="text" name="name" id="field-name" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="field-year_published">Year Published</label>
                        <input type="number" name="year_published" id="field-year_published">
                    </div>
                    <div class="form-group">
                        <label for="field-min_age">Min Age</label>
                        <input type="number" name="min_age" id="field-min_age">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="field-min_players">Min Players</label>
                        <input type="number" name="min_players" id="field-min_players">
                    </div>
                    <div class="form-group">
                        <label for="field-max_players">Max Players</label>
                        <input type="number" name="max_players" id="field-max_players">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="field-min_playtime">Min Playtime (min)</label>
                        <input type="number" name="min_playtime" id="field-min_playtime">
                    </div>
                    <div class="form-group">
                        <label for="field-max_playtime">Max Playtime (min)</label>
                        <input type="number" name="max_playtime" id="field-max_playtime">
                    </div>
                </div>
                <div class="form-group">
                    <label for="field-mechanisms">Mechanisms (comma-separated)</label>
                    <input type="text" name="mechanisms" id="field-mechanisms">
                </div>
                <div class="form-group">
                    <label for="field-categories">Categories (comma-separated)</label>
                    <input type="text" name="categories" id="field-categories">
                </div>
                <div class="form-group">
                    <label for="field-description">Description</label>
                    <textarea name="description" id="field-description" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label for="field-notes">Librarian Notes</label>
                    <textarea name="notes" id="field-notes" rows="2" placeholder="Storage location, condition, etc."></textarea>
                </div>

                <div class="form-group">
                    <label for="loan-search-input">On Loan From Member</label>
                    <input type="hidden" name="owner_contact_id" id="field-owner_contact_id">
                    <input type="text" id="loan-search-input" placeholder="Search members by name or email..." autocomplete="off">
                    <div id="loan-search-results" class="bgg-search-results"></div>
                    <div id="loan-selected" class="loan-selected" style="display: none;"></div>
                    <p class="description-text" style="font-size: 0.75rem; margin-top: 4px;">Leave blank if this is a club-owned copy. Setting this sends the member a thank-you email.</p>
                </div>

                <div style="margin-top: 20px; text-align: right; display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeGameModal()">Cancel</button>
                    <button type="submit" name="add_game" id="submit-add" class="btn btn-primary">Add Game</button>
                    <button type="submit" name="update_game" id="submit-update" class="btn btn-primary" style="display: none;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Gallery/List view toggle, persisted per-browser.
        (function () {
            var container = document.getElementById('game-container');
            var toggle = document.getElementById('view-toggle');
            if (!container || !toggle) return;

            function setView(view) {
                container.classList.toggle('game-list', view === 'list');
                toggle.querySelectorAll('.view-toggle-btn').forEach(function (btn) {
                    btn.classList.toggle('active', btn.dataset.view === view);
                });
                localStorage.setItem('tgg_library_view', view);
            }

            toggle.addEventListener('click', function (e) {
                var btn = e.target.closest('.view-toggle-btn');
                if (btn) setView(btn.dataset.view);
            });

            setView(localStorage.getItem('tgg_library_view') || 'grid');
        })();

        <?php if ($canManageLibrary): ?>
        (function () {
            var modal = document.getElementById('game-modal');
            var form = document.getElementById('game-form');
            var searchSection = document.getElementById('bgg-search-section');
            var searchInput = document.getElementById('bgg-search-input');
            var searchResults = document.getElementById('bgg-search-results');
            var selectedSummary = document.getElementById('bgg-selected-summary');

            window.closeGameModal = function () {
                modal.style.display = 'none';
            };

            function escHtml(s) {
                return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            function resetForm() {
                form.reset();
                form.style.display = 'none';
                searchSection.style.display = 'block';
                searchInput.value = '';
                searchResults.innerHTML = '';
                selectedSummary.style.display = 'none';
                document.getElementById('field-game_id').value = '';
                document.getElementById('field-owner_contact_id').value = '';
                document.getElementById('loan-search-input').value = '';
                document.getElementById('loan-selected').style.display = 'none';
                document.getElementById('loan-selected').innerHTML = '';
            }

            function openAddModal() {
                resetForm();
                document.getElementById('game-modal-title').textContent = 'Add Game';
                document.getElementById('submit-add').style.display = '';
                document.getElementById('submit-update').style.display = 'none';
                modal.style.display = 'block';
            }

            function openEditModal(game) {
                resetForm();
                searchSection.style.display = 'none';
                form.style.display = 'block';
                document.getElementById('game-modal-title').textContent = 'Edit ' + game.name;
                document.getElementById('submit-add').style.display = 'none';
                document.getElementById('submit-update').style.display = '';
                document.getElementById('field-game_id').value = game.id;
                document.getElementById('field-name').value = game.name || '';
                document.getElementById('field-year_published').value = game.year_published || '';
                document.getElementById('field-min_age').value = game.min_age || '';
                document.getElementById('field-min_players').value = game.min_players || '';
                document.getElementById('field-max_players').value = game.max_players || '';
                document.getElementById('field-min_playtime').value = game.min_playtime || '';
                document.getElementById('field-max_playtime').value = game.max_playtime || '';
                document.getElementById('field-mechanisms').value = game.mechanisms || '';
                document.getElementById('field-categories').value = game.categories || '';
                document.getElementById('field-description').value = game.description || '';
                document.getElementById('field-notes').value = game.notes || '';
                if (game.owner_contact_id) {
                    document.getElementById('field-owner_contact_id').value = game.owner_contact_id;
                    var loanSel = document.getElementById('loan-selected');
                    loanSel.innerHTML = escHtml(game.owner_name) + ' <button type="button" class="btn-clear-loan">Clear</button>';
                    loanSel.style.display = 'block';
                }
                modal.style.display = 'block';
            }

            document.getElementById('add-game-btn').addEventListener('click', openAddModal);
            document.querySelectorAll('.edit-game-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    openEditModal(JSON.parse(btn.dataset.game));
                });
            });

            window.addEventListener('click', function (e) {
                if (e.target === modal) closeGameModal();
            });

            // BGG search (add flow)
            var searchTimer;
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimer);
                var q = searchInput.value.trim();
                if (q.length < 2) { searchResults.innerHTML = ''; return; }
                searchTimer = setTimeout(function () {
                    fetch('library-bgg-search.php?q=' + encodeURIComponent(q))
                        .then(function (r) { return r.json(); })
                        .then(function (results) {
                            searchResults.innerHTML = '';
                            (results || []).slice(0, 15).forEach(function (r) {
                                var item = document.createElement('div');
                                item.className = 'bgg-search-result-item';
                                item.textContent = r.name + (r.year_published ? ' (' + r.year_published + ')' : '');
                                item.addEventListener('click', function () { selectBggGame(r.bgg_id, r.name); });
                                searchResults.appendChild(item);
                            });
                        });
                }, 300);
            });

            function selectBggGame(bggId, name) {
                searchResults.innerHTML = '';
                selectedSummary.style.display = 'block';
                selectedSummary.textContent = 'Loading details for "' + name + '"...';
                fetch('library-bgg-search.php?bgg_id=' + encodeURIComponent(bggId))
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d) { selectedSummary.textContent = 'Could not load details for that game.'; return; }
                        document.getElementById('field-bgg_id').value = bggId;
                        document.getElementById('field-thumbnail_url').value = d.thumbnail_url || '';
                        document.getElementById('field-image_url').value = d.image_url || '';
                        document.getElementById('field-bgg_rating_bayes').value = d.bgg_rating_bayes || '';
                        document.getElementById('field-bgg_weight').value = d.bgg_weight || '';
                        document.getElementById('field-name').value = d.name || name;
                        document.getElementById('field-year_published').value = d.year_published || '';
                        document.getElementById('field-min_age').value = d.min_age || '';
                        document.getElementById('field-min_players').value = d.min_players || '';
                        document.getElementById('field-max_players').value = d.max_players || '';
                        document.getElementById('field-min_playtime').value = d.min_playtime || '';
                        document.getElementById('field-max_playtime').value = d.max_playtime || '';
                        document.getElementById('field-mechanisms').value = (d.mechanisms || []).join(', ');
                        document.getElementById('field-categories').value = (d.categories || []).join(', ');
                        document.getElementById('field-description').value = d.description || '';
                        selectedSummary.textContent = 'Selected: ' + (d.name || name) + (d.year_published ? ' (' + d.year_published + ')' : '');
                        searchSection.querySelector('#bgg-search-input').style.display = 'none';
                        form.style.display = 'block';
                    });
            }

            // Member search (loan field, both add and edit flows)
            var loanInput = document.getElementById('loan-search-input');
            var loanResults = document.getElementById('loan-search-results');
            var loanTimer;
            loanInput.addEventListener('input', function () {
                clearTimeout(loanTimer);
                var q = loanInput.value.trim();
                if (q.length < 3) { loanResults.innerHTML = ''; return; }
                loanTimer = setTimeout(function () {
                    fetch('admin/member-search.php?q=' + encodeURIComponent(q))
                        .then(function (r) { return r.json(); })
                        .then(function (members) {
                            loanResults.innerHTML = '';
                            (members || []).forEach(function (m) {
                                var item = document.createElement('div');
                                item.className = 'bgg-search-result-item';
                                item.textContent = m.display_name + ' (' + m.email + ')';
                                item.addEventListener('click', function () {
                                    document.getElementById('field-owner_contact_id').value = m.id;
                                    var loanSel = document.getElementById('loan-selected');
                                    loanSel.innerHTML = escHtml(m.display_name) + ' <button type="button" class="btn-clear-loan">Clear</button>';
                                    loanSel.style.display = 'block';
                                    loanResults.innerHTML = '';
                                    loanInput.value = '';
                                });
                                loanResults.appendChild(item);
                            });
                        });
                }, 300);
            });

            document.getElementById('loan-selected').addEventListener('click', function (e) {
                if (!e.target.classList.contains('btn-clear-loan')) return;
                document.getElementById('field-owner_contact_id').value = '';
                this.style.display = 'none';
                this.innerHTML = '';
            });
        })();
        <?php endif; ?>
    </script>
</body>
</html>
