<?php
/**
 * Admin Roles & Permissions Editor
 * Allows superadmins and admins to edit role-permission mappings and assign roles to members.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\AuditLog;
use App\Database;

Auth::requireAuth();
if (!has_permission('manage roles') && !has_permission('manage hosting')) {
    redirect('index.php?error=unauthorized');
}

$errorMsg = null;
$successMsg = null;
$appDb = Database::getAppConnection();

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please try again.";
    } else {
        // 1. Save Role-Permission Matrix
        if (isset($_POST['save_matrix'])) {
            if (!has_permission('manage roles') && !has_role('superadmin')) {
                $errorMsg = "You do not have permission to edit the role-permission matrix.";
            } else
            try {
                $viewerIsSuperadmin = has_role('superadmin');

                // Full before-snapshot of the matrix for the audit event
                $matrixSnapshot = function () use ($appDb): array {
                    $rows = $appDb->query("
                        SELECT r.name AS role_name, p.name AS perm_name
                        FROM `tgg_role_permissions` rp
                        JOIN `tgg_roles` r ON r.id = rp.role_id
                        JOIN `tgg_permissions` p ON p.id = rp.permission_id
                        ORDER BY r.name, p.name
                    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $map = [];
                    foreach ($rows as $row) {
                        $map[$row['role_name']][] = $row['perm_name'];
                    }
                    return $map;
                };
                $matrixBefore = $matrixSnapshot();

                // Fetch current 'all' mappings to preserve them if the user is a standard admin
                $currentAllMappings = [];
                if (!$viewerIsSuperadmin) {
                    $allRows = $appDb->query("
                        SELECT r.name as role_name 
                        FROM `tgg_role_permissions` rp
                        JOIN `tgg_roles` r ON r.id = rp.role_id
                        JOIN `tgg_permissions` p ON p.id = rp.permission_id
                        WHERE p.name = 'all'
                    ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    foreach ($allRows as $rName) {
                        $currentAllMappings[$rName] = true;
                    }
                }

                $appDb->beginTransaction();

                // Clear existing mappings
                $appDb->exec("DELETE FROM `tgg_role_permissions`");

                // Fetch roles and permissions to map ID relationships
                $roleIds = $appDb->query("SELECT name, id FROM `tgg_roles`")->fetchAll(PDO::FETCH_KEY_PAIR);
                $permIds = $appDb->query("SELECT name, id FROM `tgg_permissions`")->fetchAll(PDO::FETCH_KEY_PAIR);

                $matrix = $_POST['matrix'] ?? []; // format: [role_name => [perm_name => 1]]

                // If standard admin, override/ignore 'all' updates and restore original 'all' mappings
                if (!$viewerIsSuperadmin) {
                    foreach ($matrix as $roleName => $perms) {
                        unset($matrix[$roleName]['all']);
                    }
                    foreach ($currentAllMappings as $rName => $val) {
                        if (!isset($matrix[$rName])) {
                            $matrix[$rName] = [];
                        }
                        $matrix[$rName]['all'] = 1;
                    }
                }

                $stmtInsert = $appDb->prepare("INSERT INTO `tgg_role_permissions` (role_id, permission_id) VALUES (?, ?)");

                foreach ($matrix as $roleName => $perms) {
                    $roleId = $roleIds[$roleName] ?? null;
                    if ($roleId) {
                        foreach (array_keys($perms) as $permName) {
                            $permId = $permIds[$permName] ?? null;
                            if ($permId) {
                                $stmtInsert->execute([$roleId, $permId]);
                            }
                        }
                    }
                }

                $appDb->commit();

                $matrixAfter = $matrixSnapshot();
                if ($matrixBefore !== $matrixAfter) {
                    AuditLog::log('roles', 'role_matrix_updated', [
                        'before' => $matrixBefore,
                        'after' => $matrixAfter
                    ]);
                }

                // Refresh permissions of the current logged-in user dynamically
                Auth::refreshPermissions();

                $successMsg = "Role-Permission matrix updated successfully.";
            } catch (Exception $e) {
                if ($appDb->inTransaction()) {
                    $appDb->rollBack();
                }
                $errorMsg = safe_err("Failed to update matrix: ", $e);
            }
        }

        // 2. Update Member Role Assignment
        if (isset($_POST['update_user_role'])) {
            $targetContactId = (int)($_POST['contact_id'] ?? 0);
            $newRoles = $_POST['roles'] ?? [];
            if (!is_array($newRoles)) {
                $newRoles = [];
            }
            $newRoles = array_map('trim', $newRoles);

            try {
                $viewerIsSuperadmin = has_role('superadmin');

                // Retrieve target member's current roles first to check permissions
                $currentRolesStmt = $appDb->prepare("SELECT role_name FROM tgg_member_roles WHERE contact_id = :id");
                $currentRolesStmt->execute(['id' => $targetContactId]);
                $targetCurrentRoles = $currentRolesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
                if (empty($targetCurrentRoles)) {
                    // Fallback to settings table single role
                    $fallbackStmt = $appDb->prepare("SELECT role FROM tgg_member_settings WHERE contact_id = :id");
                    $fallbackStmt->execute(['id' => $targetContactId]);
                    $fallbackRole = $fallbackStmt->fetchColumn();
                    $targetCurrentRoles = $fallbackRole ? [$fallbackRole] : ['member'];
                }

                $targetHasSuperadmin = in_array('superadmin', $targetCurrentRoles, true);

                // 1. Standard admin cannot modify roles for a superadmin user
                if ($targetHasSuperadmin && !$viewerIsSuperadmin) {
                    throw new Exception("Standard admins cannot modify roles for a superadmin user.");
                }

                // 2. Only superadmins can assign or remove the superadmin role
                $newHasSuperadmin = in_array('superadmin', $newRoles, true);
                if ($newHasSuperadmin !== $targetHasSuperadmin && !$viewerIsSuperadmin) {
                    throw new Exception("Only superadmins can add or delete the superadmin role.");
                }

                // 2b. The system must always retain at least one superadmin
                if ($targetHasSuperadmin && !$newHasSuperadmin) {
                    if (Auth::countSuperadmins($appDb, $targetContactId) < 1) {
                        throw new Exception("Cannot remove the superadmin role: at least one superadmin must remain. Grant another user the superadmin role first.");
                    }
                }

                // 3. Permission-based role assignment guards
                if (!$viewerIsSuperadmin) {
                    $canManageRoles   = has_permission('manage roles');
                    $canManageHosting = has_permission('manage hosting');
                    $canManageLibrary = has_permission('manage library');
                    $rolesAdded   = array_diff($newRoles, $targetCurrentRoles);
                    $rolesRemoved = array_diff($targetCurrentRoles, $newRoles);
                    foreach (array_merge($rolesAdded, $rolesRemoved) as $changedRole) {
                        if (in_array($changedRole, ['admin', 'majordomo', 'member'], true) && !$canManageRoles) {
                            throw new Exception("You do not have permission to modify the '{$changedRole}' role.");
                        }
                        if ($changedRole === 'host' && !$canManageRoles && !$canManageHosting) {
                            throw new Exception("You do not have permission to modify the 'host' role.");
                        }
                        if ($changedRole === 'librarian' && !$canManageRoles && !$canManageLibrary) {
                            throw new Exception("You do not have permission to modify the 'librarian' role.");
                        }
                    }
                }

                // 4. Verify all selected roles exist in tgg_roles
                if (!empty($newRoles)) {
                    $placeholders = implode(',', array_fill(0, count($newRoles), '?'));
                    $checkStmt = $appDb->prepare("SELECT COUNT(*) FROM `tgg_roles` WHERE name IN ($placeholders)");
                    $checkStmt->execute($newRoles);
                    $foundCount = (int)$checkStmt->fetchColumn();
                    if ($foundCount !== count(array_unique($newRoles))) {
                        throw new Exception("One or more selected roles do not exist.");
                    }
                } else {
                    $newRoles = ['member']; // default role
                }

                // 4. Update the database
                $appDb->beginTransaction();

                // Delete existing roles
                $deleteStmt = $appDb->prepare("DELETE FROM tgg_member_roles WHERE contact_id = :id");
                $deleteStmt->execute(['id' => $targetContactId]);

                // Insert new roles
                $insertStmt = $appDb->prepare("INSERT INTO tgg_member_roles (contact_id, role_name) VALUES (:id, :role)");
                foreach ($newRoles as $roleName) {
                    $insertStmt->execute(['id' => $targetContactId, 'role' => $roleName]);
                }

                // Update legacy column in tgg_member_settings
                $primaryRole = $newRoles[0] ?? 'member';
                $updateSettingsStmt = $appDb->prepare("UPDATE tgg_member_settings SET role = :role WHERE contact_id = :id");
                $updateSettingsStmt->execute(['role' => $primaryRole, 'id' => $targetContactId]);

                $appDb->commit();

                AuditLog::log('roles', 'member_roles_updated', [
                    'before' => array_values($targetCurrentRoles),
                    'after' => array_values($newRoles)
                ], $targetContactId);

                // If updating themselves, refresh current session permissions immediately
                if ($targetContactId === (int)$_SESSION['user']['contact_id']) {
                    Auth::refreshPermissions();
                }

                $successMsg = "Roles for member ID {$targetContactId} updated successfully.";
            } catch (Exception $e) {
                if ($appDb->inTransaction()) {
                    $appDb->rollBack();
                }
                $errorMsg = safe_err("Failed to update member roles: ", $e);
            }
        }

        // 3. Start Impersonating User
        if (isset($_POST['impersonate_user'])) {
            $targetContactId = (int)($_POST['contact_id'] ?? 0);
            try {
                Auth::impersonate($targetContactId);
                redirect('index.php');
            } catch (Exception $e) {
                $errorMsg = safe_err("Impersonation failed: ", $e);
            }
        }
    }
}

if (isset($_GET['success']) && $_GET['success'] === 'impersonation_stopped') {
    $successMsg = "Returned to admin session successfully.";
}

// Total superadmin count, computed once: any row whose member currently holds
// superadmin is "the last one" precisely when this total is 1.
$totalSuperadmins = Auth::countSuperadmins($appDb);

// Fetch all roles & permissions
$rolesList = $appDb->query("SELECT * FROM `tgg_roles` ORDER BY sort_order ASC, id ASC")->fetchAll();
$permsList = $appDb->query("SELECT * FROM `tgg_permissions` ORDER BY id ASC")->fetchAll();

// Fetch mappings: [role_name => [perm_name => true]]
$mappings = [];
$mapRows = $appDb->query("
    SELECT r.name as role_name, p.name as perm_name 
    FROM `tgg_role_permissions` rp
    JOIN `tgg_roles` r ON r.id = rp.role_id
    JOIN `tgg_permissions` p ON p.id = rp.permission_id
")->fetchAll();

foreach ($mapRows as $row) {
    $mappings[$row['role_name']][$row['perm_name']] = true;
}

// Search & Pagination settings for User Role Assignment
$search = trim($_GET['search'] ?? '');
$roleFilter = trim($_GET['role_filter'] ?? '');
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Role filter must match the same fallback chain used when rendering each row's
// checkboxes below: an explicit tgg_member_roles row for that role, OR (if the
// member has no tgg_member_roles rows at all) the legacy tgg_member_settings.role
// column, which itself defaults to 'member' when empty.
$roleFilterJoin = "";
$roleFilterWhere = "";
if (!empty($roleFilter)) {
    $roleFilterJoin = "LEFT JOIN `tgg_member_roles` mr ON mr.contact_id = c.id AND mr.role_name = :role_filter";
    $roleFilterWhere = " AND (
        mr.contact_id IS NOT NULL
        OR (
            NOT EXISTS (SELECT 1 FROM `tgg_member_roles` mr2 WHERE mr2.contact_id = c.id)
            AND (s.role = :role_filter2 OR (COALESCE(s.role, '') = '' AND :role_filter3 = 'member'))
        )
    )";
}

// Fetch total members with settings count
$countQuery = "
    SELECT COUNT(DISTINCT c.id)
    FROM `tgg_member_settings` s
    JOIN `tgg_contacts` c ON c.id = s.contact_id
    {$roleFilterJoin}
    WHERE c.is_deleted = 0
    {$roleFilterWhere}
";
$params = [];
if (!empty($search)) {
    $countQuery .= " AND (c.display_name LIKE :search_name OR c.email LIKE :search_email OR c.id = :exactId)";
    $params['search_name'] = "%{$search}%";
    $params['search_email'] = "%{$search}%";
    $params['exactId'] = is_numeric($search) ? (int)$search : -1;
}
if (!empty($roleFilter)) {
    $params['role_filter'] = $roleFilter;
    $params['role_filter2'] = $roleFilter;
    $params['role_filter3'] = $roleFilter;
}
$stmtCount = $appDb->prepare($countQuery);
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Fetch members
$memberQuery = "
    SELECT DISTINCT c.id, c.display_name, c.email, s.role
    FROM `tgg_member_settings` s
    JOIN `tgg_contacts` c ON c.id = s.contact_id
    {$roleFilterJoin}
    WHERE c.is_deleted = 0
    {$roleFilterWhere}
";
if (!empty($search)) {
    $memberQuery .= " AND (c.display_name LIKE :search_name OR c.email LIKE :search_email OR c.id = :exactId)";
}
$memberQuery .= " ORDER BY c.display_name ASC LIMIT :limit OFFSET :offset";

$stmtMembers = $appDb->prepare($memberQuery);
if (!empty($search)) {
    $stmtMembers->bindValue(':search_name', "%{$search}%", PDO::PARAM_STR);
    $stmtMembers->bindValue(':search_email', "%{$search}%", PDO::PARAM_STR);
    $stmtMembers->bindValue(':exactId', is_numeric($search) ? (int)$search : -1, PDO::PARAM_INT);
}
if (!empty($roleFilter)) {
    $stmtMembers->bindValue(':role_filter', $roleFilter, PDO::PARAM_STR);
    $stmtMembers->bindValue(':role_filter2', $roleFilter, PDO::PARAM_STR);
    $stmtMembers->bindValue(':role_filter3', $roleFilter, PDO::PARAM_STR);
}
$stmtMembers->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmtMembers->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtMembers->execute();
$membersList = $stmtMembers->fetchAll();

// AJAX partial refresh: search/filter/pagination on this list fetch just this
// fragment instead of reloading the whole page. Mutating actions (Update,
// Login As) are unaffected -- those are normal POSTs handled above.
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
    include __DIR__ . '/partials/role-assignment-body.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roles & Permissions Editor - Club Management</title>
    <link rel="stylesheet" href="../assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <style>
        .roles-section {
            margin-top: 30px;
        }
        .matrix-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 0.85rem;
        }
        .matrix-table th, .matrix-table td {
            padding: 12px 15px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .matrix-table th {
            font-weight: 600;
            color: var(--color-text-secondary);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }
        .matrix-table th:first-child, .matrix-table td:first-child {
            text-align: left;
            font-weight: bold;
        }
        .matrix-table td input[type="checkbox"] {
            transform: scale(1.2);
            cursor: pointer;
        }
        .role-desc {
            font-size: 0.75rem;
            color: var(--color-text-muted);
            font-weight: normal;
            display: block;
            margin-top: 4px;
        }
        .assignment-grid {
            margin-top: 40px;
        }
        .search-control-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            gap: 20px;
        }
        .search-field {
            flex-grow: 1;
            max-width: 400px;
            position: relative;
        }
        .search-field input {
            width: 100%;
            padding: 10px 15px 10px 40px;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border-glass);
            border-radius: 8px;
            color: #fff;
            font-size: 0.85rem;
        }
        .search-field::before {
            content: "🔍";
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.4);
        }
        .role-select {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-glass);
            border-radius: 6px;
            color: #fff;
            padding: 6px 12px;
            font-size: 0.85rem;
            cursor: pointer;
        }
        .role-select option {
            background: #202030;
            color: #fff;
        }
        .pagination-row {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 25px;
        }
        .pagination-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-glass);
            color: #fff;
            padding: 6px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.8rem;
            transition: all 0.2s;
        }
        .pagination-btn:hover:not(.disabled) {
            background: var(--color-primary);
            border-color: var(--color-primary);
        }
        .pagination-btn.disabled {
            opacity: 0.3;
            cursor: not-allowed;
            pointer-events: none;
        }
        .pagination-info {
            font-size: 0.8rem;
            color: var(--color-text-muted);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $navAdminArea = true; $navActive = 'admin'; include __DIR__ . '/../partials/navbar.php'; ?>

        <main class="main-content">
            <div class="admin-grid">
                <?php include 'sidebar.php'; ?>

                <!-- Work Area: Roles & Permissions Editor -->
                <section class="admin-workspace">
                    <h2>Roles & Permissions Manager</h2>
                    <p class="description-text" style="margin-bottom: 25px;">
                        Manage security authorization matrices and assign member roles within the portal.
                    </p>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <?php if ($successMsg): ?>
                        <div class="alert alert-success"><?php echo e($successMsg); ?></div>
                    <?php endif; ?>

                    <!-- Section 1: Role-Permission Matrix -->
                    <?php if (has_permission('manage roles') || has_role('superadmin')): ?>
                    <div class="table-card glass-panel" style="padding: 25px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <span style="font-size: 0.9rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-secondary);">Role-Permission Mapping Matrix</span>
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                            <div style="overflow-x: auto;">
                                <table class="matrix-table">
                                    <thead>
                                        <tr>
                                            <th>Role</th>
                                            <?php foreach ($permsList as $perm): ?>
                                                <th title="<?php echo e($perm['description']); ?>"><?php echo e($perm['name']); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rolesList as $role): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo e(ucfirst($role['name'])); ?></strong>
                                                    <span class="role-desc"><?php echo e($role['description']); ?></span>
                                                </td>
                                                <?php foreach ($permsList as $perm): 
                                                    $isAllPerm = ($perm['name'] === 'all');
                                                    $viewerIsSuperadmin = has_role('superadmin');
                                                    $disabled = ($isAllPerm && !$viewerIsSuperadmin) ? 'disabled' : '';
                                                ?>
                                                    <td>
                                                        <input type="checkbox" 
                                                               name="matrix[<?php echo e($role['name']); ?>][<?php echo e($perm['name']); ?>]" 
                                                               value="1"
                                                               <?php echo isset($mappings[$role['name']][$perm['name']]) ? 'checked' : ''; ?>
                                                               <?php echo $disabled; ?>>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div style="margin-top: 20px; text-align: right;">
                                <button type="submit" name="save_matrix" class="btn btn-primary">Save Authorization Matrix</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- Section 2: User Role Assignment -->
                    <div class="table-card glass-panel assignment-grid" style="padding: 25px;">
                        <span style="font-size: 0.9rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-secondary); display: block; margin-bottom: 20px;">User Role Assignments</span>

                        <div id="role-assignment-fragment">
                            <?php include __DIR__ . '/partials/role-assignment-body.php'; ?>
                        </div>
                    </div>
                </section>
            </div>
        </main>

        <?php include __DIR__ . '/../partials/footer.php'; ?>

    <script>
        // AJAX partial refresh for the User Role Assignment list's search/filter/
        // pagination -- avoids a full page reload (and the flash/scroll-jump/
        // select-dropdown quirks that come with it) for just this list. Listeners
        // are attached to the stable #role-assignment-fragment container, not its
        // children, so they keep working after each innerHTML swap (event bubbling
        // still reaches the container). Mutating actions (Update, Login As) are
        // untouched -- those stay normal full-page POSTs.
        (function() {
            const fragment = document.getElementById('role-assignment-fragment');
            if (!fragment) return;

            function buildUrl(overrides) {
                const params = new URLSearchParams(window.location.search);
                Object.keys(overrides).forEach(function(key) {
                    const val = overrides[key];
                    if (val === '' || val === null || val === undefined) {
                        params.delete(key);
                    } else {
                        params.set(key, val);
                    }
                });
                const qs = params.toString();
                return window.location.pathname + (qs ? '?' + qs : '');
            }

            function loadFragment(url, pushState) {
                // Swapping in shorter content (fewer rows, pagination disappearing) can
                // shrink the page below the current scroll position, and the browser
                // clamps scrollY to fit -- which looks like an unwanted jump. Restore
                // the exact pre-swap position immediately after so nothing above the
                // fragment appears to move.
                const scrollBefore = window.scrollY;
                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(res) {
                        if (!res.ok) throw new Error('Request failed');
                        return res.text();
                    })
                    .then(function(html) {
                        fragment.innerHTML = html;
                        if (pushState) history.pushState({}, '', url);
                        window.scrollTo(0, scrollBefore);
                    })
                    .catch(function() {
                        // Fall back to a real navigation if the fetch fails for any reason.
                        window.location.href = url;
                    });
            }

            fragment.addEventListener('submit', function(e) {
                const form = e.target.closest('#filters-form');
                if (!form) return; // per-row Update/Login As forms fall through to a normal POST
                e.preventDefault();
                const data = new FormData(form);
                loadFragment(buildUrl({
                    search: data.get('search') || '',
                    role_filter: data.get('role_filter') || '',
                    page: 1,
                }), true);
            });

            fragment.addEventListener('change', function(e) {
                if (!e.target.matches('.role-select')) return;
                const form = e.target.closest('#filters-form');
                const data = new FormData(form);
                loadFragment(buildUrl({
                    search: data.get('search') || '',
                    role_filter: e.target.value,
                    page: 1,
                }), true);
            });

            let searchTimer;
            fragment.addEventListener('input', function(e) {
                if (e.target.name !== 'search') return;
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function() {
                    loadFragment(buildUrl({ search: e.target.value.trim(), page: 1 }), true);
                }, 300);
            });

            fragment.addEventListener('click', function(e) {
                const link = e.target.closest('.pagination-btn');
                if (!link || link.classList.contains('disabled')) return;
                e.preventDefault();
                loadFragment(link.getAttribute('href'), true);
            });

            window.addEventListener('popstate', function() {
                loadFragment(window.location.pathname + window.location.search, false);
            });
        })();
    </script>
</body>
</html>
