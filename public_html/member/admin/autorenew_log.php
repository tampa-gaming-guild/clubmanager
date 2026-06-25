<?php
/**
 * Admin - Autorenew Log Viewer
 * Displays, parses, and formats the contents of the autorenew.log file.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Database;

Auth::requireAdmin();
Auth::requirePermission('all');

$errorMsg = null;
$logEntries = [];
$namesMap = [];
$totalRows = 0;
$totalPages = 0;

$logPath = $_ENV['AUTORENEW_LOG_PATH'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;

try {
    $appDb = Database::getAppConnection();

    if (empty($logPath)) {
        $errorMsg = "Autorenew log file path is not configured. Please define AUTORENEW_LOG_PATH in your .env file.";
    } elseif (!file_exists($logPath) || !is_readable($logPath)) {
        $errorMsg = "Autorenew log file is not readable or does not exist at: " . e($logPath);
    } else {
        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            $errorMsg = "Failed to read the log file.";
        } else {
            foreach ($lines as $line) {
                // Match pattern: [YYYY-MM-DD HH:MM:SS] [autorenew] ...
                if (preg_match('/^\[([\d\-:\s]+)\]\s+\[autorenew\]\s+(.*)$/', $line, $matches)) {
                    $timestamp = $matches[1];
                    $message = $matches[2];
                    
                    $contactId = null;
                    $action = 'info';
                    $details = $message;
                    
                    if (preg_match('/^contact_id=(\d+):\s+([^-]+)\s*-\s*(.*)$/', $message, $msgMatches)) {
                        $contactId = (int)$msgMatches[1];
                        $action = trim($msgMatches[2]);
                        $details = trim($msgMatches[3]);
                    } elseif (preg_match('/^contact_id=(\d+):\s+(ERROR\s*-\s*.*)$/', $message, $msgMatches)) {
                        $contactId = (int)$msgMatches[1];
                        $action = 'error';
                        $details = trim($msgMatches[2]);
                    } elseif (stripos($message, 'Summary:') === 0) {
                        $action = 'summary';
                    } elseif (stripos($message, 'Sent') === 0) {
                        $action = 'reminder';
                    }
                    
                    // Filter by search query if set
                    if ($search !== '' && stripos($line, $search) === false) {
                        continue;
                    }
                    
                    $logEntries[] = [
                        'timestamp' => $timestamp,
                        'contact_id' => $contactId,
                        'action' => $action,
                        'message' => $details,
                        'raw' => $line
                    ];
                } else {
                    // Filter raw line by search if set
                    if ($search !== '' && stripos($line, $search) === false) {
                        continue;
                    }
                    $logEntries[] = [
                        'timestamp' => '',
                        'contact_id' => null,
                        'action' => 'raw',
                        'message' => $line,
                        'raw' => $line
                    ];
                }
            }

            // Default order is most recent dates first (reverse chronological)
            $logEntries = array_reverse($logEntries);
            $totalRows = count($logEntries);
            $totalPages = (int)ceil($totalRows / $limit);
            $offset = ($page - 1) * $limit;
            $logEntries = array_slice($logEntries, $offset, $limit);

            // Fetch display names for contact IDs
            $contactIds = [];
            foreach ($logEntries as $entry) {
                if (!empty($entry['contact_id'])) {
                    $contactIds[] = $entry['contact_id'];
                }
            }
            $contactIds = array_unique($contactIds);

            if (!empty($contactIds)) {
                try {
                    $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
                    $civiStmt = $appDb->prepare("SELECT id, display_name FROM tgg_contacts WHERE id IN ($placeholders)");
                    $civiStmt->execute(array_values($contactIds));
                    $namesMap = $civiStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                } catch (Exception $civiEx) {
                    error_log("Failed to load contact names for autorenew log: " . $civiEx->getMessage());
                }
            }
        }
    }
} catch (Exception $e) {
    $errorMsg = safe_err("System Error: ", $e);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Autorenew Log - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <style>
        .search-bar-container {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            max-width: 500px;
        }
        .search-bar-container input {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 10px 15px;
            color: #fff;
            flex-grow: 1;
            font-size: 0.9rem;
            outline: none;
            transition: all 0.2s ease;
        }
        .search-bar-container input:focus {
            border-color: var(--color-primary);
            background: rgba(255, 255, 255, 0.1);
        }
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-charged {
            background: rgba(46, 204, 113, 0.15);
            border: 1px solid rgba(46, 204, 113, 0.3);
            color: #2ecc71;
        }
        .badge-declined {
            background: rgba(231, 76, 60, 0.15);
            border: 1px solid rgba(231, 76, 60, 0.3);
            color: #e74c3c;
        }
        .badge-expired {
            background: rgba(241, 196, 15, 0.15);
            border: 1px solid rgba(241, 196, 15, 0.3);
            color: #f1c40f;
        }
        .badge-error {
            background: rgba(231, 76, 60, 0.25);
            border: 1px solid #e74c3c;
            color: #ff7675;
        }
        .badge-summary {
            background: rgba(155, 89, 182, 0.15);
            border: 1px solid rgba(155, 89, 182, 0.3);
            color: #9b59b6;
        }
        .badge-reminder {
            background: rgba(52, 152, 219, 0.15);
            border: 1px solid rgba(52, 152, 219, 0.3);
            color: #3498db;
        }
        .badge-info {
            background: rgba(149, 165, 166, 0.15);
            border: 1px solid rgba(149, 165, 166, 0.3);
            color: #bdc3c7;
        }
        .badge-raw {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        th.sortable {
            cursor: pointer;
            position: relative;
            user-select: none;
        }
        th.sortable:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        th.sortable::after {
            content: ' ↕';
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.3);
        }
        th.sortable.asc::after {
            content: ' ▲';
            color: var(--color-primary);
        }
        th.sortable.desc::after {
            content: ' ▼';
            color: var(--color-primary);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php $navAdminArea = true; $navActive = 'admin'; include __DIR__ . '/../partials/navbar.php'; ?>

        <main class="main-content">
            <div class="admin-grid">
                
                <?php include 'sidebar.php'; ?>

                <!-- Work Area: Autorenew Log -->
                <section class="admin-workspace">
                    <h2>Autorenew Cron Log</h2>
                    <p class="description-text" style="margin-bottom: 25px;">Execution logs of the daily automated billing and membership renewal process.</p>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo $errorMsg; ?></div>
                    <?php endif; ?>

                    <!-- Search Form -->
                    <form action="autorenew_log.php" method="GET" class="search-bar-container">
                        <input type="text" name="search" placeholder="Search logs..." value="<?php echo e($search); ?>">
                        <button type="submit" class="btn btn-primary" style="padding: 10px 20px;">Search</button>
                        <?php if ($search !== ""): ?>
                            <a href="autorenew_log.php" class="btn btn-secondary" style="padding: 10px 15px; display: flex; align-items: center; justify-content: center;">Clear</a>
                        <?php endif; ?>
                    </form>

                    <div class="table-card glass-panel span-full-row">
                        <div class="admin-table-container">
                            <table class="admin-table" id="logTable">
                                <thead>
                                    <tr>
                                        <th class="sortable desc" data-column="0">Timestamp</th>
                                        <th class="sortable" data-column="1">Member</th>
                                        <th class="sortable" data-column="2">Action / Status</th>
                                        <th>Message</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($logEntries)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center" style="padding: 20px;">No autorenew logs found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($logEntries as $entry): ?>
                                            <?php 
                                                $memberText = "N/A";
                                                if (!empty($entry['contact_id'])) {
                                                    $cId = $entry['contact_id'];
                                                    $name = $namesMap[$cId] ?? "Member #$cId";
                                                    $memberText = "<strong>" . e($name) . "</strong><br><span style='font-size:0.75rem; color:rgba(255,255,255,0.4);'>ID: $cId</span>";
                                                }
                                            ?>
                                            <tr>
                                                <td data-sort="<?php echo e($entry['timestamp']); ?>">
                                                    <span class="table-datetime"><?php echo e($entry['timestamp'] ?: 'N/A'); ?></span>
                                                </td>
                                                <td><?php echo $memberText; ?></td>
                                                <td data-sort="<?php echo e($entry['action']); ?>">
                                                    <span class="badge badge-<?php echo e($entry['action']); ?>"><?php echo e($entry['action']); ?></span>
                                                </td>
                                                <td><?php echo e($entry['message']); ?></td>
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
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 0.85rem;">&laquo; Previous</a>
                            <?php endif; ?>
                            <span style="font-size: 0.85rem; color: var(--color-text-muted);">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 0.85rem;">Next &raquo;</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                </section>
            </div>
        </main>

        <?php $footerText = 'TGG Club Membership System. Secure Admin Portal.'; include __DIR__ . '/../partials/footer.php'; ?>
    </div>

    <!-- Client-side Sort Script -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const getCellValue = (tr, idx) => {
                const cell = tr.children[idx];
                return cell.getAttribute('data-sort') || cell.innerText || cell.textContent;
            };

            const comparer = (idx, asc) => (a, b) => ((v1, v2) => 
                v1 !== '' && v2 !== '' && !isNaN(v1) && !isNaN(v2) ? v1 - v2 : v1.toString().localeCompare(v2)
            )(getCellValue(asc ? a : b, idx), getCellValue(asc ? b : a, idx));

            document.querySelectorAll('th.sortable').forEach(th => th.addEventListener('click', (() => {
                const table = th.closest('table');
                const tbody = table.querySelector('tbody');
                const rows = Array.from(tbody.querySelectorAll('tr'));
                const isAsc = th.classList.contains('asc');
                
                // Reset other headers
                th.closest('tr').querySelectorAll('th').forEach(header => {
                    if (header !== th) header.classList.remove('asc', 'desc');
                });

                // Toggle class
                th.classList.toggle('asc', !isAsc);
                th.classList.toggle('desc', isAsc);

                rows.sort(comparer(Array.from(th.parentNode.children).indexOf(th), !isAsc))
                    .forEach(tr => tbody.appendChild(tr));
            })));
        });
    </script>
</body>
</html>
