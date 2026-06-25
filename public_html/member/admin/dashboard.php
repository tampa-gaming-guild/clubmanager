<?php
/**
 * Admin Dashboard - Control Hub
 * Provides summary metrics and a searchable, sortable, and filterable members list for desk administrators.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Database;
use App\CiviCRMImporter;
use App\BillingHelper;

Auth::requireStaff();

$errorMsg = null;
$successMsg = null;
$members = [];
$tiers = [];
$statuses = [];
$addMemberPlans = [];

if (isset($_GET['success'])) {
    $successMsg = $_GET['success'];
}

// Handle Add Member submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_member'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please try again.";
    } else {
        try {
            $result = BillingHelper::addMember(
                $_POST['first_name'] ?? '',
                $_POST['last_name'] ?? '',
                $_POST['email'] ?? '',
                $_POST['phone'] ?? '',
                (int)($_POST['plan_id'] ?? 0),
                $_POST['payment_method'] ?? '',
                has_permission('admin panel'),
                $_SESSION['user']['contact_id'] ?? null
            );
            $successMsg = "{$result['display_name']} was added successfully!";
            header("Location: dashboard.php?success=" . urlencode($successMsg));
            exit;
        } catch (Exception $e) {
            $errorMsg = safe_err("Failed to add member: ", $e);
        }
    }
}

// Fetch statistics
$totalMembers = 0;
$activeMembers = 0;
$expiredMembers = 0;
$checkinsToday = 0;
$monthRevenue = 0.00;

try {
    $members = CiviCRMImporter::getMembersList();
    $totalMembers = count($members);

    $appDb = Database::getAppConnection();

    // 1. Fetch Tiers & Statuses first for pivot matrix layout & dropdowns
    $tiers = CiviCRMImporter::getMembershipTiers();

    // Add Member's plan dropdown needs the *local* tgg_subscription_plans.id (what
    // BillingHelper::processOfflineRenewal/activateTrial expect), unlike $tiers above
    // whose 'id' is actually the CiviCRM membership_type_id used for the pivot table
    // and level filter. Trial is included -- unlike renew.php's tiers list -- since this
    // is for brand-new joins, not renewals, and Trial is a valid one-time join option.
    $addMemberPlans = BillingHelper::getSubscriptionPlans(true);

    $statuses = $appDb->query("
        SELECT id, name, label, is_active
        FROM tgg_membership_statuses
        WHERE label NOT IN ('Deceased', 'Current Renewed', 'Future Start')
          AND name NOT IN ('Deceased', 'Current Renewed', 'Future Start')
        ORDER BY id ASC
    ")->fetchAll();

    // 2. Initialize matrix with all membership levels and statuses
    $matrix = [];
    foreach ($tiers as $tier) {
        $matrix[$tier['name']] = [];
        foreach ($statuses as $stat) {
            $matrix[$tier['name']][$stat['label']] = 0;
        }
    }

    // 3. Calculate Active/Expired counts & populate matrix
    foreach ($members as $m) {
        if ($m['is_active']) {
            $activeMembers++;
        } else if ($m['status_label'] === 'Expired') {
            $expiredMembers++;
        }
        
        $lvl = $m['membership_name'];
        $stat = $m['status_label'];
        
        if ($lvl && $stat) {
            if (!isset($matrix[$lvl])) {
                $matrix[$lvl] = [];
                foreach ($statuses as $s) {
                    $matrix[$lvl][$s['label']] = 0;
                }
            }
            if (!isset($matrix[$lvl][$stat])) {
                $matrix[$lvl][$stat] = 0;
            }
            $matrix[$lvl][$stat]++;
        }
    }
    ksort($matrix);

    // 4. Fetch Check-ins logged today
    $checkinsToday = (int)$appDb->query("
        SELECT COUNT(*) FROM tgg_checkins 
        WHERE DATE(checked_in_at) = CURRENT_DATE()
    ")->fetchColumn();

    // 5. Fetch Stripe transaction revenue this month from local ledger
    $monthRevenue = (float)$appDb->query("
        SELECT SUM(amount) FROM tgg_billing_ledger 
        WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
          AND YEAR(created_at) = YEAR(CURRENT_DATE())
          AND payment_status = 'paid'
    ")->fetchColumn();

} catch (Exception $e) {
    $errorMsg = safe_err("Unable to fetch summary metrics: ", $e);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Club Management</title>
    <link rel="stylesheet" href="../assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <style>
        .sortable-header {
            cursor: pointer;
            user-select: none;
            position: relative;
            transition: background 0.2s ease;
        }
        .sortable-header:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        .sortable-header::after {
            content: " ⇅";
            font-size: 0.8rem;
            color: var(--color-text-muted);
            margin-left: 5px;
        }
        .sortable-header.sort-asc::after {
            content: " ▲";
            color: var(--color-primary);
        }
        .sortable-header.sort-desc::after {
            content: " ▼";
            color: var(--color-primary);
        }
        .filter-controls {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .filter-select select {
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border-glass);
            border-radius: 6px;
            color: #fff;
            padding: 8px 14px;
            font-size: 0.85rem;
            cursor: pointer;
            font-family: var(--font-body);
            transition: border-color 0.2s;
        }
        .filter-select select:focus {
            outline: none;
            border-color: var(--color-primary);
        }
        @media (max-width: 900px) {
            .table-card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            .filter-controls {
                flex-direction: column;
                width: 100%;
                align-items: stretch;
            }
            .search-bar input, .filter-select select {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $navAdminArea = true; $navActive = 'admin'; include __DIR__ . '/../partials/navbar.php'; ?>

        <main class="main-content">
            <div class="admin-grid">
                
                <?php include 'sidebar.php'; ?>

                <!-- Work Area: Control Hub -->
                <section class="admin-workspace">
                    <h2>Admin Dashboard</h2>
                    <p class="description-text" style="margin-bottom: 25px;">Real-time overview of members, check-ins, and payments.</p>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <?php if ($successMsg): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>

                    <!-- Stat Cards Panel -->
                    <div class="stats-panel-grid">
                        <?php if (has_permission('edit checkins')): ?>
                        <div class="stat-card glass-panel border-left-orange">
                            <span class="stat-icon">🎟️</span>
                            <div class="stat-vals">
                                <strong><?php echo $checkinsToday; ?></strong>
                                <span>Check-Ins Today</span>
                                <a href="checkins.php" class="card-link" style="font-size: 0.7rem; color: var(--color-primary); text-decoration: none; margin-top: 5px; display: inline-block;">View Check-In Log &rarr;</a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="stat-card glass-panel border-left-blue">
                            <span class="stat-icon">👥</span>
                            <div class="stat-vals">
                                <strong><?php echo $totalMembers; ?></strong>
                                <span>Total Contacts</span>
                            </div>
                        </div>

                        <?php if (has_permission('process payments')): ?>
                        <div class="stat-card glass-panel border-left-yellow">
                            <span class="stat-icon">💲</span>
                            <div class="stat-vals">
                                <strong>$<?php echo number_format($monthRevenue, 2); ?></strong>
                                <span>Revenue (Month)</span>
                                <a href="reports.php#payments-report-table" class="card-link" style="font-size: 0.7rem; color: var(--color-primary); text-decoration: none; margin-top: 5px; display: inline-block;">View Payments Log &rarr;</a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Level & Status Pivot Table (Full Width) -->
                    <div class="table-card glass-panel mt-20" style="padding: 20px;">
                        <span style="font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-secondary); margin-bottom: 15px; display: block;">Members by Level & Status</span>
                        <div style="overflow-x: auto; font-size: 0.8rem;">
                            <table style="width: 100%; border-collapse: collapse; text-align: left; min-width: 600px;">
                                <thead>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.08); color: var(--color-text-secondary);">
                                        <th style="padding: 6px 8px;">Membership Level</th>
                                        <?php foreach ($statuses as $stat): ?>
                                            <th style="padding: 6px 8px; text-align: right; white-space: nowrap;"><?php echo e($stat['label']); ?></th>
                                        <?php endforeach; ?>
                                        <th style="padding: 6px 8px; text-align: right; white-space: nowrap;">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $colTotals = [];
                                    $grandTotal = 0;
                                    foreach ($statuses as $stat) {
                                        $colTotals[$stat['label']] = 0;
                                    }
                                    
                                    foreach ($matrix as $lvl => $stats): 
                                        $rowTotal = 0;
                                    ?>
                                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                            <td style="padding: 6px 8px; font-weight: 500; color: #fff; white-space: nowrap;"><a href="dashboard.php?level=<?php echo urlencode($lvl); ?>" style="color: var(--color-primary); text-decoration: none; font-weight: 600;"><?php echo e($lvl); ?></a></td>
                                            <?php foreach ($statuses as $stat):
                                                $count = $stats[$stat['label']] ?? 0;
                                                if ($stat['is_active']) {
                                                    $rowTotal += $count;
                                                }
                                                $colTotals[$stat['label']] += $count;
                                                $color = $count > 0 ? ($stat['label'] === 'Current' || $stat['label'] === 'New' ? 'var(--color-success)' : ($stat['label'] === 'Expired' ? 'var(--color-danger)' : '#fff')) : 'rgba(255,255,255,0.15)';
                                                $weight = $count > 0 ? '700' : '400';
                                            ?>
                                                <td style="padding: 6px 8px; text-align: right; font-weight: <?php echo $weight; ?>; color: <?php echo $color; ?>;">
                                                    <?php if ($count > 0): ?>
                                                        <a href="dashboard.php?level=<?php echo urlencode($lvl); ?>&status=<?php echo urlencode($stat['label']); ?>" style="color: inherit; text-decoration: none;"><?php echo $count; ?></a>
                                                    <?php else: ?>
                                                        <?php echo $count; ?>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <td style="padding: 6px 8px; text-align: right; font-weight: 700; color: #fff;">
                                                <?php if ($rowTotal > 0): ?>
                                                    <a href="dashboard.php?level=<?php echo urlencode($lvl); ?>&status=" style="color: inherit; text-decoration: none;"><?php echo $rowTotal; ?></a>
                                                <?php else: ?>
                                                    <?php echo $rowTotal; ?>
                                                <?php endif; ?>
                                                <?php $grandTotal += $rowTotal; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr style="border-top: 2px solid rgba(255,255,255,0.15); font-weight: 700; color: #fff;">
                                        <td style="padding: 8px; font-weight: 700;">Total</td>
                                        <?php foreach ($statuses as $stat): 
                                            $colVal = $colTotals[$stat['label']];
                                        ?>
                                            <td style="padding: 8px; text-align: right; font-weight: 700;">
                                                <?php if ($colVal > 0): ?>
                                                    <a href="dashboard.php?level=&status=<?php echo urlencode($stat['label']); ?>" style="color: inherit; text-decoration: none;"><?php echo $colVal; ?></a>
                                                <?php else: ?>
                                                    <?php echo $colVal; ?>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td style="padding: 8px; text-align: right; font-weight: 700;">
                                            <?php if ($grandTotal > 0): ?>
                                                <a href="dashboard.php?level=&status=" style="color: inherit; text-decoration: none;"><?php echo $grandTotal; ?></a>
                                            <?php else: ?>
                                                <?php echo $grandTotal; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Members List Table -->
                    <div class="table-card glass-panel mt-20">
                        <div class="table-card-header">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <h3>Registered Members Directory</h3>
                                <button type="button" class="btn btn-primary btn-small" onclick="openAddMemberModal()">+ Add Member</button>
                            </div>

                            <!-- Search & Filter Controls -->
                            <div class="filter-controls">
                                <div class="search-bar">
                                    <input type="text" id="member-search" placeholder="Search by name/email..." onkeyup="filterMembersTable()" value="<?php echo isset($_GET['search']) ? e($_GET['search']) : ''; ?>">
                                </div>
                                <div class="filter-select">
                                    <select id="filter-level" onchange="filterMembersTable()">
                                        <option value="" <?php echo (!isset($_GET['level']) || $_GET['level'] === '') ? 'selected' : ''; ?>>All Levels</option>
                                        <?php foreach ($tiers as $tier): 
                                            $selected = (isset($_GET['level']) && $_GET['level'] === $tier['name']) ? 'selected' : '';
                                        ?>
                                            <option value="<?php echo e($tier['name']); ?>" <?php echo $selected; ?>><?php echo e($tier['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="filter-select">
                                    <select id="filter-status" onchange="filterMembersTable()">
                                        <?php
                                        $allStatusesSelected = false;
                                        if (isset($_GET['status']) && $_GET['status'] === '') {
                                            $allStatusesSelected = true;
                                        } elseif (!isset($_GET['status']) && (isset($_GET['level']) || isset($_GET['search']))) {
                                            $allStatusesSelected = true;
                                        }

                                        $activeSelected = false;
                                        if (isset($_GET['status'])) {
                                            if ($_GET['status'] === 'Active') {
                                                $activeSelected = true;
                                            }
                                        } elseif (!isset($_GET['search']) && !isset($_GET['level'])) {
                                            $activeSelected = true;
                                        }
                                        ?>
                                        <option value="" <?php echo $allStatusesSelected ? 'selected' : ''; ?>>All Statuses</option>
                                        <option value="Active" <?php echo $activeSelected ? 'selected' : ''; ?>>Active</option>
                                        <?php foreach ($statuses as $stat):
                                            $selected = (isset($_GET['status']) && $_GET['status'] === $stat['label']) ? 'selected' : '';
                                        ?>
                                            <option value="<?php echo e($stat['label']); ?>" <?php echo $selected; ?>><?php echo e($stat['label']); ?></option>
                                        <?php endforeach; ?>
                                       </select>
                                   </div>
                            </div>
                        </div>

                        <div class="admin-table-container">
                            <table class="admin-table" id="members-table" data-sort-dir="">
                                <thead>
                                    <tr>
                                        <th class="sortable-header" onclick="sortTable('members-table', 0, false)">Name</th>
                                        <th class="sortable-header" onclick="sortTable('members-table', 1, false)">Contact Email</th>
                                        <th class="sortable-header" onclick="sortTable('members-table', 2, false)">Membership Level</th>
                                        <th class="sortable-header" onclick="sortTable('members-table', 3, false)">Status</th>
                                        <th class="sortable-header" onclick="sortTable('members-table', 4, false)">Expires</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($members)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center">No members found. Use the CiviCRM Importer to sync records.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($members as $m): ?>
                                            <tr>
                                                <td><strong><?php echo e($m['display_name']); ?></strong></td>
                                                <td><?php echo e($m['email']); ?></td>
                                                <td><?php echo e($m['membership_name'] ?: 'None'); ?></td>
                                                <td>
                                                    <span class="badge badge-status <?php echo $m['is_active'] ? 'badge-active' : 'badge-expired'; ?>">
                                                        <?php echo e($m['status_label'] ?: 'None'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php echo $m['end_date'] ? date('M d, Y', strtotime($m['end_date'])) : 'N/A'; ?>
                                                </td>
                                                <td>
                                                    <a href="../profile.php?id=<?php echo (int)$m['id']; ?>" class="btn btn-secondary btn-small">Manage</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>
        </main>

        <?php include __DIR__ . '/../partials/footer.php'; ?>

    <!-- Add Member Modal -->
    <div id="add-member-modal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(5px);">
        <div class="modal-content glass-panel" style="background: rgba(30, 30, 40, 0.95); margin: 5% auto; padding: 25px; border: 1px solid rgba(255, 255, 255, 0.1); width: 90%; max-width: 480px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 15px; margin-bottom: 20px;">
                <h3 style="margin: 0; color: #fff; font-size: 1.2rem;">Add Member</h3>
                <span class="close" onclick="closeAddMemberModal()" style="color: rgba(255,255,255,0.6); font-size: 28px; font-weight: bold; cursor: pointer; transition: color 0.2s;">&times;</span>
            </div>
            <form action="dashboard.php" method="POST" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label for="add_member_first_name">First Name</label>
                        <input type="text" id="add_member_first_name" name="first_name" required>
                    </div>
                    <div class="form-group">
                        <label for="add_member_last_name">Last Name</label>
                        <input type="text" id="add_member_last_name" name="last_name" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="add_member_email">Email Address</label>
                    <input type="email" id="add_member_email" name="email" required autocomplete="email">
                </div>

                <div class="form-group">
                    <label for="add_member_phone">Phone Number (Optional)</label>
                    <input type="tel" id="add_member_phone" name="phone">
                </div>

                <?php if (has_permission('admin panel')): ?>
                    <p class="field-hint" style="margin-bottom: 10px;">Optionally activate a membership now -- Trial is free and one-time per email, other levels need an offline payment method. Leave blank to add the member without an active membership.</p>

                    <div class="form-group">
                        <label for="add_member_plan_id">Membership Level</label>
                        <select id="add_member_plan_id" name="plan_id" onchange="updateAddMemberPaymentVisibility()">
                            <option value="">-- None --</option>
                            <?php foreach ($addMemberPlans as $plan): ?>
                                <option value="<?php echo (int)$plan['id']; ?>" data-trial="<?php echo BillingHelper::isTrialPlan($plan) ? '1' : '0'; ?>"><?php echo e($plan['name']); ?> - $<?php echo number_format($plan['minimum_fee'], 2); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" id="add_member_payment_method_group">
                        <label for="add_member_payment_method">Payment Method</label>
                        <select id="add_member_payment_method" name="payment_method">
                            <option value="">-- None --</option>
                            <option value="cash">Cash</option>
                            <option value="check">Check</option>
                            <option value="complimentary">Complimentary</option>
                            <option value="volunteer credit">Volunteer Credit</option>
                        </select>
                    </div>
                <?php endif; ?>

                <button type="submit" name="add_member" value="1" class="btn btn-primary btn-block">Add Member</button>
            </form>
        </div>
    </div>

    <!-- Client-side filter and sort script -->
    <script>
        const activeStatusLabels = <?php
            $activeStatusLabels = [];
            foreach ($statuses as $s) {
                if ($s['is_active']) {
                    $activeStatusLabels[] = strtolower($s['label']);
                }
            }
            echo json_encode($activeStatusLabels);
        ?>;

        function filterMembersTable() {
            const searchInput = document.getElementById('member-search');
            const searchFilter = searchInput.value.toLowerCase();
            
            const levelSelect = document.getElementById('filter-level');
            const levelFilter = levelSelect.value.toLowerCase();
            
            const statusSelect = document.getElementById('filter-status');
            const statusFilter = statusSelect.value.toLowerCase();
            
            const table = document.getElementById('members-table');
            const trs = table.getElementsByTagName('tr');

            for (let i = 1; i < trs.length; i++) {
                let nameTd = trs[i].getElementsByTagName('td')[0];
                let emailTd = trs[i].getElementsByTagName('td')[1];
                let levelTd = trs[i].getElementsByTagName('td')[2];
                let statusTd = trs[i].getElementsByTagName('td')[3];
                
                if (nameTd && emailTd && levelTd && statusTd) {
                    let nameTxt = (nameTd.textContent || nameTd.innerText).trim().toLowerCase();
                    let emailTxt = (emailTd.textContent || emailTd.innerText).trim().toLowerCase();
                    let levelTxt = (levelTd.textContent || levelTd.innerText).trim().toLowerCase();
                    let statusTxt = (statusTd.textContent || statusTd.innerText).trim().toLowerCase();
                    
                    // Match Search Text
                    const matchesSearch = nameTxt.indexOf(searchFilter) > -1 || emailTxt.indexOf(searchFilter) > -1;
                    
                    // Match Level
                    const matchesLevel = levelFilter === "" || levelTxt === levelFilter;
                    
                    // Match Status
                    const matchesStatus = statusFilter === "" || (statusFilter === "active" ? activeStatusLabels.includes(statusTxt) : statusTxt === statusFilter);
                    
                    if (matchesSearch && matchesLevel && matchesStatus) {
                        trs[i].style.display = "";
                    } else {
                        trs[i].style.display = "none";
                    }
                }
            }
        }

        // Table Sorter
        function sortTable(tableId, colIndex, isNumeric) {
            const table = document.getElementById(tableId);
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            // If empty row exists, ignore
            if (rows.length === 1 && rows[0].cells.length === 1) return;

            // Toggle Sort Direction
            const currentDir = table.getAttribute('data-sort-dir') === 'asc' ? 'desc' : 'asc';
            table.setAttribute('data-sort-dir', currentDir);

            // Toggle visual class in headers
            const headers = table.querySelectorAll('th');
            headers.forEach((h, index) => {
                if (index < 5) h.className = 'sortable-header'; // Exclude Actions
            });
            headers[colIndex].classList.add(currentDir === 'asc' ? 'sort-asc' : 'sort-desc');

            rows.sort((a, b) => {
                let cellA = a.cells[colIndex].innerText || a.cells[colIndex].textContent;
                let cellB = b.cells[colIndex].innerText || b.cells[colIndex].textContent;

                // Handle dates or custom N/A strings
                if (colIndex === 4) { // Expiry date column
                    let timeA = cellA.trim() === 'N/A' ? 0 : new Date(cellA).getTime();
                    let timeB = cellB.trim() === 'N/A' ? 0 : new Date(cellB).getTime();
                    return currentDir === 'asc' ? timeA - timeB : timeB - timeA;
                }

                if (isNumeric) {
                    let numA = parseFloat(cellA.replace(/[^\d.-]/g, '')) || 0;
                    let numB = parseFloat(cellB.replace(/[^\d.-]/g, '')) || 0;
                    return currentDir === 'asc' ? numA - numB : numB - numA;
                } else {
                    return currentDir === 'asc' 
                        ? cellA.trim().localeCompare(cellB.trim()) 
                        : cellB.trim().localeCompare(cellA.trim());
                }
            });

            // Re-render
            tbody.innerHTML = '';
            rows.forEach(row => tbody.appendChild(row));
        }

        // Apply initial filter on page load
        document.addEventListener('DOMContentLoaded', filterMembersTable);

        // Add Member Modal
        function openAddMemberModal() {
            document.getElementById('add-member-modal').style.display = 'block';
        }
        function closeAddMemberModal() {
            document.getElementById('add-member-modal').style.display = 'none';
        }
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('add-member-modal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });

        // Trial is free and one-time, so the payment method field doesn't apply to it.
        function updateAddMemberPaymentVisibility() {
            const planSelect = document.getElementById('add_member_plan_id');
            const paymentGroup = document.getElementById('add_member_payment_method_group');
            if (!planSelect || !paymentGroup) return;

            const selectedOpt = planSelect.options[planSelect.selectedIndex];
            const isTrial = selectedOpt && selectedOpt.getAttribute('data-trial') === '1';
            paymentGroup.style.display = isTrial ? 'none' : '';
            if (isTrial) {
                document.getElementById('add_member_payment_method').value = '';
            }
        }
        document.addEventListener('DOMContentLoaded', updateAddMemberPaymentVisibility);

        <?php if ($errorMsg && isset($_POST['add_member'])): ?>
        document.addEventListener('DOMContentLoaded', openAddMemberModal);
        <?php endif; ?>
    </script>
</body>
</html>
