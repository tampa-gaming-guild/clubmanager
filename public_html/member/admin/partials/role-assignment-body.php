<?php
/**
 * User Role Assignment fragment -- rendered both as part of the full roles.php
 * page and, standalone, as the AJAX response body when roles.php is requested
 * with X-Requested-With: XMLHttpRequest (see the early-exit near the top of
 * roles.php). Relies entirely on variables already in scope from roles.php:
 * $search, $roleFilter, $rolesList, $membersList, $page, $totalPages, $appDb.
 */
?>
<!-- Search and Filters -->
<div class="search-control-row">
    <form method="GET" action="" id="filters-form" style="display: flex; gap: 10px; align-items: center; flex: 1; flex-wrap: wrap;">
        <div class="search-field">
            <input type="text" name="search" placeholder="Search by name, email, or contact ID..." value="<?php echo e($search); ?>">
        </div>
        <select name="role_filter" class="role-select">
            <option value="">All Roles</option>
            <?php foreach ($rolesList as $roleOption): ?>
                <option value="<?php echo e($roleOption['name']); ?>" <?php echo $roleFilter === $roleOption['name'] ? 'selected' : ''; ?>>
                    <?php echo e(ucfirst($roleOption['name'])); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    </form>
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

                                    $viewerIsSuperadmin     = has_role('superadmin');
                                    $viewerCanManageRoles   = has_permission('manage roles');
                                    $viewerCanManageHosting = has_permission('manage hosting');
                                    foreach ($rolesList as $roleOption):
                                        $isSuperadminRole = ($roleOption['name'] === 'superadmin');
                                        $targetHasSuperadmin = in_array('superadmin', $memberRoles, true);

                                        $disabled = '';
                                        if ($isSuperadminRole && !$viewerIsSuperadmin) {
                                            $disabled = 'disabled';
                                        } elseif ($targetHasSuperadmin && !$viewerIsSuperadmin) {
                                            $disabled = 'disabled';
                                        } elseif (!$viewerIsSuperadmin) {
                                            $rn = $roleOption['name'];
                                            if (in_array($rn, ['admin', 'majordomo', 'member'], true) && !$viewerCanManageRoles) {
                                                $disabled = 'disabled';
                                            } elseif ($rn === 'host' && !$viewerCanManageRoles && !$viewerCanManageHosting) {
                                                $disabled = 'disabled';
                                            }
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
        <a href="?search=<?php echo urlencode($search); ?>&amp;role_filter=<?php echo urlencode($roleFilter); ?>&amp;page=<?php echo $page - 1; ?>" class="pagination-btn <?php echo ($page <= 1) ? 'disabled' : ''; ?>">&larr; Prev</a>
        <span class="pagination-info">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
        <a href="?search=<?php echo urlencode($search); ?>&amp;role_filter=<?php echo urlencode($roleFilter); ?>&amp;page=<?php echo $page + 1; ?>" class="pagination-btn <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">Next &rarr;</a>
    </div>
<?php endif; ?>
