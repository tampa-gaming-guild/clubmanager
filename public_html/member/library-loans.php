<?php
/**
 * Librarian-only view of every member currently loaning a physical copy to
 * the club (tgg_games.owner_contact_id/loan_started_at). Reached only via a
 * link on library.php -- deliberately not in the top nav or Admin sidebar,
 * since this isn't an admin function. Separate from profile.php's own
 * "Library Loans" tab because profile.php is gated on admin/billing-staff
 * access, which 'manage library' alone does not grant.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Auth;
use App\Database;
use App\GameLibraryService;

Auth::requirePermission('manage library');

$errorMsg = null;
$successMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please try again.";
    } else {
        try {
            if (isset($_POST['return_loan'])) {
                GameLibraryService::returnLoan((int)$_POST['game_id'], (int)$_SESSION['user']['contact_id']);
                $successMsg = "Game marked as returned to its owner and removed from the library.";
            }

            if ($successMsg !== null) {
                header('Location: library-loans.php?success=' . urlencode($successMsg));
                exit;
            }
        } catch (Exception $e) {
            $errorMsg = safe_err("Failed to update loan: ", $e);
        }
    }
}

if (isset($_GET['success'])) {
    $successMsg = $_GET['success'];
}

$appDb = Database::getAppConnection();
$rows = $appDb->query("
    SELECT g.id, g.name, g.thumbnail_url, g.loan_started_at, g.bgg_sync_status,
           c.id AS owner_id, c.display_name AS owner_name
    FROM tgg_games g
    INNER JOIN tgg_contacts c ON c.id = g.owner_contact_id
    WHERE g.is_deleted = 0 AND g.owner_contact_id IS NOT NULL
    ORDER BY c.display_name ASC, g.loan_started_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$byMember = [];
foreach ($rows as $row) {
    $ownerId = (int)$row['owner_id'];
    if (!isset($byMember[$ownerId])) {
        $byMember[$ownerId] = ['name' => $row['owner_name'], 'games' => []];
    }
    $byMember[$ownerId]['games'][] = $row;
}

function loanSyncBadgeClass(string $status): string {
    switch ($status) {
        case 'synced': return 'badge-active';
        case 'failed': return 'badge-expired';
        default: return 'badge-free';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loaning Members - Library - Tampa Gaming Guild</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="favicon.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="assets/css/marketing.css<?php echo asset_version('assets/css/marketing.css'); ?>">
</head>
<body>
    <div class="app-container">
        <?php $navActive = 'library'; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content">
            <section class="marketing-section" style="max-width: 1100px;">
                <h1 class="library-page-title">Loaning Members</h1>
                <p class="section-intro">Every member currently loaning a physical copy of a game to the club. <a href="library.php">&larr; Back to Library</a></p>

                <?php if ($errorMsg): ?>
                    <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                <?php endif; ?>
                <?php if ($successMsg): ?>
                    <div class="alert alert-success"><?php echo e($successMsg); ?></div>
                <?php endif; ?>

                <?php if (empty($byMember)): ?>
                    <div class="table-card glass-panel mt-20">
                        <p class="description-text">No members currently have games on loan to the club.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($byMember as $ownerId => $member): ?>
                        <div class="table-card glass-panel mt-20" id="member-<?php echo $ownerId; ?>">
                            <h3><a href="profile.php?id=<?php echo $ownerId; ?>"><?php echo e($member['name']); ?></a></h3>
                            <div class="admin-table-container">
                                <table class="admin-table">
                                    <thead>
                                        <tr>
                                            <th>Game</th>
                                            <th>Loaned Since</th>
                                            <th>BGG Sync</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($member['games'] as $game): ?>
                                            <tr>
                                                <td><strong><?php echo e($game['name']); ?></strong></td>
                                                <td><span class="table-datetime"><?php echo $game['loan_started_at'] ? date('Y-m-d', strtotime($game['loan_started_at'])) : '—'; ?></span></td>
                                                <td>
                                                    <span class="badge <?php echo loanSyncBadgeClass($game['bgg_sync_status']); ?>">
                                                        <?php echo e(ucfirst($game['bgg_sync_status'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <form method="POST" action="library-loans.php" class="inline-form" onsubmit="return confirm('Mark &quot;<?php echo e(addslashes($game['name'])); ?>&quot; as returned to <?php echo e(addslashes($member['name'])); ?>? Since it&#039;s their own copy, it will be removed from the library entirely.');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                                        <input type="hidden" name="game_id" value="<?php echo (int)$game['id']; ?>">
                                                        <button type="submit" name="return_loan" class="btn btn-secondary btn-sm">Mark Returned</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </main>

        <?php include __DIR__ . '/partials/footer.php'; ?>
    </div>
</body>
</html>
