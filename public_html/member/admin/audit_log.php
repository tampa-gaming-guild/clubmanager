<?php
/**
 * Admin - Audit Log
 * Searchable, paginated view of tgg_audit_log: security events, role changes,
 * rate/config changes, and membership actions, with actor attribution
 * (including the real admin behind any impersonated action).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Database;

Auth::requirePermission('admin panel');

$errorMsg = null;
$logs = [];
$namesMap = [];
$totalRows = 0;
$totalPages = 0;

$validCategories = ['security', 'roles', 'rates', 'volunteer_config', 'membership', 'import', 'library'];

$category = trim($_GET['category'] ?? '');
if (!in_array($category, $validCategories, true)) {
    $category = '';
}
$actorSearch = trim($_GET['actor'] ?? '');
$targetSearch = trim($_GET['target'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
// Date inputs come from <input type="date">; ignore anything malformed
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

/**
 * Resolve a name/email substring (or numeric id) to a set of contact ids for filtering.
 * @return int[]|null Matching ids, or null when the term is empty (no filter)
 */
function resolveContactFilter(PDO $appDb, string $term): ?array {
    if ($term === '') {
        return null;
    }
    if (ctype_digit($term)) {
        return [(int)$term];
    }
    $stmt = $appDb->prepare("SELECT id FROM tgg_contacts WHERE display_name LIKE :term OR email LIKE :term LIMIT 200");
    $stmt->execute(['term' => "%{$term}%"]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

try {
    $appDb = Database::getAppConnection();

    // All positional placeholders, pushed in clause order.
    $where = [];
    $positional = [];

    if ($category !== '') {
        $where[] = "category = ?";
        $positional[] = $category;
    }
    if ($dateFrom !== '') {
        $where[] = "created_at >= ?";
        $positional[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $where[] = "created_at <= ?";
        $positional[] = $dateTo . ' 23:59:59';
    }

    $actorIds = resolveContactFilter($appDb, $actorSearch);
    if ($actorIds !== null) {
        if (empty($actorIds)) {
            $where[] = "1 = 0"; // search term matched nobody
        } else {
            $ph = implode(',', array_fill(0, count($actorIds), '?'));
            $where[] = "(actor_contact_id IN ($ph) OR impersonator_contact_id IN ($ph))";
            $positional = array_merge($positional, $actorIds, $actorIds);
        }
    }
    $targetIds = resolveContactFilter($appDb, $targetSearch);
    if ($targetIds !== null) {
        if (empty($targetIds)) {
            $where[] = "1 = 0";
        } else {
            $ph = implode(',', array_fill(0, count($targetIds), '?'));
            $where[] = "target_contact_id IN ($ph)";
            $positional = array_merge($positional, $targetIds);
        }
    }

    $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

    $countStmt = $appDb->prepare("SELECT COUNT(*) FROM tgg_audit_log $whereClause");
    $countStmt->execute($positional);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($totalRows / $limit);

    $logStmt = $appDb->prepare("
        SELECT id, category, action, actor_contact_id, impersonator_contact_id, target_contact_id,
               source, details, ip_address, created_at
        FROM tgg_audit_log
        $whereClause
        ORDER BY id DESC
        LIMIT $limit OFFSET $offset
    ");
    $logStmt->execute($positional);
    $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

    // Batch-resolve contact names
    $contactIds = [];
    foreach ($logs as $log) {
        foreach (['actor_contact_id', 'impersonator_contact_id', 'target_contact_id'] as $col) {
            if (!empty($log[$col])) {
                $contactIds[] = (int)$log[$col];
            }
        }
    }
    $contactIds = array_unique($contactIds);
    if (!empty($contactIds)) {
        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $civiStmt = $appDb->prepare("SELECT id, display_name FROM tgg_contacts WHERE id IN ($placeholders)");
        $civiStmt->execute(array_values($contactIds));
        $namesMap = $civiStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
} catch (Exception $e) {
    $errorMsg = safe_err("Database Error: ", $e);
}

/** Category badge color classes reuse the existing badge styles. */
function auditCategoryBadgeClass(string $category): string {
    switch ($category) {
        case 'security': return 'badge-expired';
        case 'roles': return 'badge-volunteer';
        case 'rates': return 'badge-active';
        case 'membership': return 'badge-active';
        case 'import': return 'badge-free';
        case 'library': return 'badge-volunteer';
        default: return 'badge-free';
    }
}

// Preserve filters across pagination links
$filterQuery = http_build_query(array_filter([
    'category' => $category,
    'actor' => $actorSearch,
    'target' => $targetSearch,
    'date_from' => $dateFrom,
    'date_to' => $dateTo
], fn($v) => $v !== ''));
$hasFilters = $filterQuery !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <style>
        .audit-filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
            align-items: flex-end;
        }
        .audit-filter-bar .filter-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .audit-filter-bar label {
            font-size: 0.75rem;
            color: var(--color-text-muted);
        }
        .audit-filter-bar input, .audit-filter-bar select {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 8px 12px;
            color: #fff;
            font-size: 0.85rem;
            outline: none;
            transition: all 0.2s ease;
        }
        .audit-filter-bar input:focus, .audit-filter-bar select:focus {
            border-color: var(--color-primary);
            background: rgba(255, 255, 255, 0.1);
        }
        .audit-filter-bar select option {
            background: #1e1e28;
        }
        .btn-view-details {
            background: rgba(9, 132, 227, 0.15);
            border: 1px solid rgba(9, 132, 227, 0.3);
            color: #74b9ff;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 600;
        }
        .btn-view-details:hover {
            background: rgba(9, 132, 227, 0.3);
            border-color: var(--color-primary);
            color: #fff;
        }
        .close:hover {
            color: #fff !important;
        }
        .audit-action-code {
            font-family: monospace;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $navAdminArea = true; $navActive = 'admin'; include __DIR__ . '/../partials/navbar.php'; ?>

        <main class="main-content">
            <div class="admin-grid">

                <?php include 'sidebar.php'; ?>

                <!-- Work Area: Audit Log -->
                <section class="admin-workspace">
                    <h2>Audit Log</h2>
                    <p class="description-text" style="margin-bottom: 25px;">Security, role, rate, volunteer-credit, and membership events, with the login responsible for each. Payment attribution appears on the Payments Log and member profiles.</p>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <!-- Filters -->
                    <form action="audit_log.php" method="GET" class="audit-filter-bar">
                        <div class="filter-field">
                            <label for="filter-category">Category</label>
                            <select id="filter-category" name="category">
                                <option value="">All categories</option>
                                <?php foreach ($validCategories as $cat): ?>
                                    <option value="<?php echo e($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>><?php echo e(ucwords(str_replace('_', ' ', $cat))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-field">
                            <label for="filter-actor">Actor (name, email, or ID)</label>
                            <input type="text" id="filter-actor" name="actor" value="<?php echo e($actorSearch); ?>" placeholder="Who did it">
                        </div>
                        <div class="filter-field">
                            <label for="filter-target">Member (name, email, or ID)</label>
                            <input type="text" id="filter-target" name="target" value="<?php echo e($targetSearch); ?>" placeholder="Who it was about">
                        </div>
                        <div class="filter-field">
                            <label for="filter-date-from">From</label>
                            <input type="date" id="filter-date-from" name="date_from" value="<?php echo e($dateFrom); ?>">
                        </div>
                        <div class="filter-field">
                            <label for="filter-date-to">To</label>
                            <input type="date" id="filter-date-to" name="date_to" value="<?php echo e($dateTo); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary" style="padding: 9px 20px;">Filter</button>
                        <?php if ($hasFilters): ?>
                            <a href="audit_log.php" class="btn btn-secondary" style="padding: 9px 15px; display: flex; align-items: center; justify-content: center;">Clear</a>
                        <?php endif; ?>
                    </form>

                    <div class="table-card glass-panel span-full-row">
                        <div class="admin-table-container">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Date &amp; Time</th>
                                        <th>Category</th>
                                        <th>Action</th>
                                        <th>Actor</th>
                                        <th>Member</th>
                                        <th style="width: 90px; text-align: center;">Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($logs)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center" style="padding: 20px;">No audit events found<?php echo $hasFilters ? ' for these filters' : ''; ?>.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($logs as $log): ?>
                                            <?php
                                                // Actor label: real admin "(as member)" when impersonating,
                                                // otherwise the acting login; System for cron; Import for imports.
                                                $actorId = $log['actor_contact_id'] !== null ? (int)$log['actor_contact_id'] : null;
                                                $impId = $log['impersonator_contact_id'] !== null ? (int)$log['impersonator_contact_id'] : null;
                                                if ($actorId !== null) {
                                                    $actorName = $namesMap[$actorId] ?? "Member #{$actorId}";
                                                    if ($impId !== null) {
                                                        $impName = $namesMap[$impId] ?? "Member #{$impId}";
                                                        $actorLabel = "{$impName} (as {$actorName})";
                                                    } else {
                                                        $actorLabel = $actorName;
                                                    }
                                                } elseif ($log['source'] === 'cron') {
                                                    $actorLabel = 'System (cron)';
                                                } else {
                                                    $actorLabel = '—';
                                                }

                                                $targetId = $log['target_contact_id'] !== null ? (int)$log['target_contact_id'] : null;
                                                $targetLabel = $targetId !== null ? ($namesMap[$targetId] ?? "Member #{$targetId}") : '—';

                                                $detailsPretty = '';
                                                if (!empty($log['details'])) {
                                                    $decoded = json_decode($log['details'], true);
                                                    $detailsPretty = $decoded !== null
                                                        ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                                                        : $log['details'];
                                                }
                                                $metaLine = "Source: " . ($log['source'] ?? '—')
                                                    . ($log['ip_address'] ? " · IP: {$log['ip_address']}" : '')
                                                    . ($targetId !== null ? " · Member ID: {$targetId}" : '');
                                            ?>
                                            <tr>
                                                <td><span class="table-datetime"><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></span></td>
                                                <td>
                                                    <span class="badge <?php echo auditCategoryBadgeClass($log['category']); ?>" style="font-size: 0.75rem; padding: 2px 6px; display: inline-block;">
                                                        <?php echo e(ucwords(str_replace('_', ' ', $log['category']))); ?>
                                                    </span>
                                                </td>
                                                <td><span class="audit-action-code"><?php echo e($log['action']); ?></span></td>
                                                <td><?php echo e($actorLabel); ?></td>
                                                <td><?php echo e($targetLabel); ?></td>
                                                <td style="text-align: center;">
                                                    <?php if ($detailsPretty !== ''): ?>
                                                        <button type="button" class="btn-view-details"
                                                            onclick="openAuditModal(
                                                                <?php echo e(json_encode($log['action'])); ?>,
                                                                <?php echo e(json_encode(date('Y-m-d H:i:s', strtotime($log['created_at'])))); ?>,
                                                                <?php echo e(json_encode($actorLabel)); ?>,
                                                                <?php echo e(json_encode($metaLine)); ?>,
                                                                <?php echo e(json_encode($detailsPretty)); ?>
                                                            )">
                                                            View
                                                        </button>
                                                    <?php else: ?>
                                                        <span style="color: var(--color-text-muted);">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination" style="display: flex; gap: 10px; justify-content: center; align-items: center; margin-top: 20px;">
                            <?php $pageBase = 'audit_log.php?' . ($filterQuery !== '' ? $filterQuery . '&' : ''); ?>
                            <?php if ($page > 1): ?>
                                <a href="<?php echo e($pageBase . 'page=' . ($page - 1)); ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 0.85rem;">&laquo; Previous</a>
                            <?php endif; ?>
                            <span style="font-size: 0.85rem; color: var(--color-text-muted);">Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $totalRows; ?> events)</span>
                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo e($pageBase . 'page=' . ($page + 1)); ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 0.85rem;">Next &raquo;</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                </section>
            </div>
        </main>

        <?php $footerText = 'TGG Club Membership System. Secure Admin Portal.'; include __DIR__ . '/../partials/footer.php'; ?>

    <!-- Audit Details Modal -->
    <div id="audit-modal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(5px);">
        <div class="modal-content glass-panel" style="background: rgba(30, 30, 40, 0.95); margin: 5% auto; padding: 25px; border: 1px solid rgba(255, 255, 255, 0.1); width: 70%; max-width: 700px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 15px; margin-bottom: 20px;">
                <h3 id="audit-modal-action" style="margin: 0; color: #fff; font-size: 1.2rem; font-family: monospace;">action</h3>
                <span class="close" onclick="closeAuditModal()" style="color: rgba(255,255,255,0.6); font-size: 28px; font-weight: bold; cursor: pointer; transition: color 0.2s;">&times;</span>
            </div>
            <div class="modal-meta" style="margin-bottom: 15px; font-size: 0.85rem; color: rgba(255,255,255,0.7); display: flex; flex-direction: column; gap: 5px; background: rgba(0,0,0,0.2); padding: 12px; border-radius: 8px;">
                <div><strong>When:</strong> <span id="audit-modal-when"></span></div>
                <div><strong>Actor:</strong> <span id="audit-modal-actor"></span></div>
                <div><span id="audit-modal-meta"></span></div>
            </div>
            <pre id="audit-modal-details" style="border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; background: rgba(0,0,0,0.3); color: #a3e4ff; padding: 15px; font-size: 0.8rem; max-height: 400px; overflow: auto; white-space: pre-wrap; word-break: break-word;"></pre>
        </div>
    </div>

    <script>
        function openAuditModal(action, when, actor, meta, details) {
            document.getElementById('audit-modal-action').innerText = action;
            document.getElementById('audit-modal-when').innerText = when;
            document.getElementById('audit-modal-actor').innerText = actor;
            document.getElementById('audit-modal-meta').innerText = meta;
            document.getElementById('audit-modal-details').innerText = details;
            document.getElementById('audit-modal').style.display = 'block';
        }

        function closeAuditModal() {
            document.getElementById('audit-modal').style.display = 'none';
        }

        window.onclick = function(event) {
            const modal = document.getElementById('audit-modal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>
