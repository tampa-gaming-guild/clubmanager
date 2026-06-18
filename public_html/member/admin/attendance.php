<?php
/**
 * Admin Attendance Log Page
 * Displays a sortable tabular report of all recent check-ins.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Database;

Auth::requireAdmin();
Auth::requirePermission('edit checkins');

$errorMsg = null;
$checkinsList = [];

try {
    $appDb = Database::getAppConnection();

    // Fetch Recent Attendance Log (Attendance Table Report)
    $chkLogStmt = $appDb->query("
        SELECT contact_id, checked_in_at, notes 
        FROM tgg_checkins 
        ORDER BY checked_in_at DESC 
        LIMIT 100
    ");
    $checkinsRaw = $chkLogStmt->fetchAll();
    if (!empty($checkinsRaw)) {
        $contactIds = array_unique(array_column($checkinsRaw, 'contact_id'));
        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        
        $civiContactStmt = $appDb->prepare("SELECT id, display_name FROM tgg_contacts WHERE id IN ({$placeholders})");
        $civiContactStmt->execute(array_values($contactIds));
        $contactsMap = $civiContactStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        foreach ($checkinsRaw as $row) {
            $cid = (int)$row['contact_id'];
            $checkinsList[] = [
                'display_name' => $contactsMap[$cid] ?? "Member #{$cid}",
                'checked_in_at' => $row['checked_in_at'],
                'notes' => $row['notes']
            ];
        }
    }
} catch (Exception $e) {
    $errorMsg = safe_err("Failed to compile attendance log: ", $e);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Log - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
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
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Navigation Bar -->
        <?php $navAdminArea = true; $navActive = 'admin'; include __DIR__ . '/../partials/navbar.php'; ?>

        <main class="main-content">
            <div class="admin-grid">
                
                <?php include 'sidebar.php'; ?>

                <!-- Work Area: Attendance Log -->
                <section class="admin-workspace">
                    <h2>Recent Attendance Log</h2>
                    <p class="description-text" style="margin-bottom: 25px;">Complete check-in history logged by members at the portal kiosk.</p>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <div class="table-card glass-panel span-full-row">
                        <p class="settings-instruction mb-10" style="padding: 10px 16px 0 16px; margin: 0; font-size: 0.8rem; color: var(--color-text-muted);">Click any column header to sort.</p>
                        <div class="admin-table-container">
                            <table class="admin-table" id="attendance-report-table" data-sort-dir="">
                                <thead>
                                    <tr>
                                        <th class="sortable-header" onclick="sortTable('attendance-report-table', 0, false)">Check-In Time</th>
                                        <th class="sortable-header" onclick="sortTable('attendance-report-table', 1, false)">Member Name</th>
                                        <th class="sortable-header" onclick="sortTable('attendance-report-table', 2, false)">Check-In Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($checkinsList)): ?>
                                        <tr>
                                            <td colspan="3" class="text-center">No check-in logs found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($checkinsList as $chk): ?>
                                            <tr>
                                                <td><span class="table-datetime"><?php echo date('Y-m-d H:i:s', strtotime($chk['checked_in_at'])); ?></span></td>
                                                <td><strong><?php echo e($chk['display_name']); ?></strong></td>
                                                <td><?php echo e($chk['notes'] ?: 'Regular Visit'); ?></td>
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

        <footer class="app-footer">
            <p>&copy; <?php echo date('Y'); ?> TGG Club Membership System. Secure Admin Portal.</p>
        </footer>
    </div>

    <!-- Table Sorting Configuration Script -->
    <script>
        function sortTable(tableId, colIndex, isNumeric) {
            const table = document.getElementById(tableId);
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            if (rows.length === 1 && rows[0].cells.length === 1) return;

            const currentDir = table.getAttribute('data-sort-dir') === 'asc' ? 'desc' : 'asc';
            table.setAttribute('data-sort-dir', currentDir);

            const headers = table.querySelectorAll('th');
            headers.forEach(h => h.className = 'sortable-header');
            headers[colIndex].classList.add(currentDir === 'asc' ? 'sort-asc' : 'sort-desc');

            rows.sort((a, b) => {
                let cellA = a.cells[colIndex].innerText || a.cells[colIndex].textContent;
                let cellB = b.cells[colIndex].innerText || b.cells[colIndex].textContent;

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

            tbody.innerHTML = '';
            rows.forEach(row => tbody.appendChild(row));
        }
    </script>
</body>
</html>

