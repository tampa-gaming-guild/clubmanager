<?php
/**
 * Admin Roles & Permissions Editor
 * Allows superadmins and admins to edit role-permission mappings and assign roles to members.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Database;

Auth::requireAdmin();

// Only allow superadmin and admin to manage roles and permissions
if (!has_role('superadmin') && !has_role('admin')) {
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
            try {
                $viewerIsSuperadmin = has_role('superadmin');
                
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

                // 3. Verify all selected roles exist in tgg_roles
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

// Fetch all roles & permissions
$rolesList = $appDb->query("SELECT * FROM `tgg_roles` ORDER BY id ASC")->fetchAll();
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
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Fetch total members with settings count
$countQuery = "
    SELECT COUNT(*) 
    FROM `tgg_member_settings` s
    JOIN `tgg_contacts` c ON c.id = s.contact_id
    WHERE c.is_deleted = 0
";
$params = [];
if (!empty($search)) {
    $countQuery .= " AND (c.display_name LIKE :search_name OR c.email LIKE :search_email OR c.id = :exactId)";
    $params['search_name'] = "%{$search}%";
    $params['search_email'] = "%{$search}%";
    $params['exactId'] = is_numeric($search) ? (int)$search : -1;
}
$stmtCount = $appDb->prepare($countQuery);
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Fetch members
$memberQuery = "
    SELECT c.id, c.display_name, c.email, s.role
    FROM `tgg_member_settings` s
    JOIN `tgg_contacts` c ON c.id = s.contact_id
    WHERE c.is_deleted = 0
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
$stmtMembers->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmtMembers->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtMembers->execute();
$membersList = $stmtMembers->fetchAll();
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

                    <!-- Section 2: User Role Assignment -->
                    <div class="table-card glass-panel assignment-grid" style="padding: 25px;">
                        <span style="font-size: 0.9rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-secondary); display: block; margin-bottom: 20px;">User Role Assignments</span>

                        <!-- Search and Filters -->
                        <div class="search-control-row">
                            <form method="GET" action="" class="search-field">
                                <input type="text" name="search" placeholder="Search by name, email, or contact ID..." value="<?php echo e($search); ?>">
                            </form>
                            <?php if (!empty($search)): ?>
                                <a href="roles.php" class="btn btn-outline-secondary btn-sm" style="height: fit-content; text-decoration: none; padding: 8px 15px; border-radius: 6px;">Clear Filter</a>
                            <?php endif; ?>
                        </div>

                        <!-- User List Table -->
                        <div class="admin-table-container">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th style="width: 100px;">Contact ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Assigned Roles</th>
                                        <th style="width: 120px; text-align: center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($membersList)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center">No matching portal users found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($membersList as $member): ?>
                                            <tr>
                                                <td><code><?php echo $member['id']; ?></code></td>
                                                <td><strong><?php echo e($member['display_name']); ?></strong></td>
                                                <td><?php echo e($member['email']); ?></td>
                                                <form method="POST" action="">
                                                    <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                                                    <input type="hidden" name="contact_id" value="<?php echo e($member['id']); ?>">
                                                    <td>
                                                        <div style="display: flex; flex-wrap: wrap; gap: 8px; font-size: 0.8rem; align-items: center;">
                                                            <?php 
                                                            // Fetch roles for this member
                                                            $memberRolesStmt = $appDb->prepare("SELECT role_name FROM tgg_member_roles WHERE contact_id = :id");
                                                            $memberRolesStmt->execute(['id' => $member['id']]);
                                                            $memberRoles = $memberRolesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
                                                            if (empty($memberRoles) && isset($member['role'])) {
                                                                $memberRoles = [$member['role']];
                                                            }
                                                            if (empty($memberRoles)) {
                                                                $memberRoles = ['member'];
                                                            }

                                                            foreach ($rolesList as $roleOption): 
                                                                $isSuperadminRole = ($roleOption['name'] === 'superadmin');
                                                                $targetHasSuperadmin = in_array('superadmin', $memberRoles, true);
                                                                $viewerIsSuperadmin = has_role('superadmin');
                                                                
                                                                $disabled = '';
                                                                if ($isSuperadminRole && !$viewerIsSuperadmin) {
                                                                    $disabled = 'disabled';
                                                                } elseif ($targetHasSuperadmin && !$viewerIsSuperadmin) {
                                                                    $disabled = 'disabled';
                                                                }
                                                                
                                                                $checked = in_array($roleOption['name'], $memberRoles, true) ? 'checked' : '';
                                                            ?>
                                                                <label style="display: inline-flex; align-items: center; gap: 4px; color: #fff; margin-right: 10px; cursor: <?php echo $disabled ? 'not-allowed' : 'pointer'; ?>; opacity: <?php echo $disabled ? '0.5' : '1'; ?>;">
                                                                    <input type="checkbox" name="roles[]" value="<?php echo e($roleOption['name']); ?>" <?php echo $checked; ?> <?php echo $disabled; ?> style="width: auto; transform: scale(1.0); margin: 0;">
                                                                    <?php echo e(ucfirst($roleOption['name'])); ?>
                                                                </label>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </td>
                                                    <td style="text-align: center;">
                                                        <div style="display: flex; flex-direction: column; gap: 5px; align-items: center;">
                                                            <button type="submit" name="update_user_role" class="btn btn-primary btn-sm" style="padding: 6px 12px; font-size: 0.75rem; border-radius: 4px; width: 100%;">Update</button>
                                                            <?php 
                                                            $originalRoles = $_SESSION['impersonator']['roles'] ?? $_SESSION['user']['roles'] ?? [];
                                                            $originalRole = $_SESSION['impersonator']['role'] ?? $_SESSION['user']['role'] ?? '';
                                                            $isOriginalSuperadmin = in_array('superadmin', $originalRoles, true) || $originalRole === 'superadmin';
                                                            if ($isOriginalSuperadmin && (int)$member['id'] !== (int)$_SESSION['user']['contact_id']): 
                                                            ?>
                                                                <button type="submit" name="impersonate_user" class="btn btn-warning btn-sm" style="padding: 6px 12px; font-size: 0.75rem; border-radius: 4px; width: 100%; border: none;">Login As</button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </form>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <div class="pagination-row">
                                <a href="?search=<?php echo urlencode($search); ?>&amp;page=<?php echo $page - 1; ?>" class="pagination-btn <?php echo ($page <= 1) ? 'disabled' : ''; ?>">&larr; Prev</a>
                                <span class="pagination-info">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                                <a href="?search=<?php echo urlencode($search); ?>&amp;page=<?php echo $page + 1; ?>" class="pagination-btn <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">Next &rarr;</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </main>

        <?php include __DIR__ . '/../partials/footer.php'; ?>
</body>
</html>
