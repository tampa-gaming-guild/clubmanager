<?php
/**
 * Admin Dashboard - Control Hub
 * Provides summary metrics and a searchable, sortable, and filterable members list for desk administrators.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Database;
use App\CiviCRMImporter;

Auth::requireAdmin();

$errorMsg = null;
$members = [];
$tiers = [];
$statuses = [];

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
    $statuses = $appDb->query("
        SELECT id, name, label 
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
                                                $rowTotal += $count;
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
                            <h3>Registered Members Directory</h3>
                            
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
                                        ?>
                                        <option value="" <?php echo $allStatusesSelected ? 'selected' : ''; ?>>All Statuses</option>
                                        <?php foreach ($statuses as $stat): 
                                            $selected = '';
                                            if (isset($_GET['status'])) {
                                                if ($_GET['status'] === $stat['label']) {
                                                    $selected = 'selected';
                                                }
                                            } elseif (!isset($_GET['search']) && !isset($_GET['level']) && $stat['label'] === 'Current') {
                                                $selected = 'selected';
                                            }
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

    <!-- Client-side filter and sort script -->
    <script>
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
                    const matchesStatus = statusFilter === "" || statusTxt === statusFilter;
                    
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
    </script>
</body>
</html>
