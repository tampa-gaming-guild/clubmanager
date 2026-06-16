<?php
/**
 * Admin - Email Audit Log
 * Displays a searchable, paginated log of all outgoing transactional emails,
 * including date sent, sender member ID, and recipient member ID.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Database;

Auth::requireAdmin();

$errorMsg = null;
$logs = [];
$namesMap = [];
$totalRows = 0;
$totalPages = 0;

$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

try {
    $appDb = Database::getAppConnection();

    // Build query filters
    $params = [];
    $whereClause = "";
    if ($search !== "") {
        $whereClause = "WHERE recipient LIKE :search OR subject LIKE :search OR body LIKE :search";
        $params['search'] = "%$search%";
    }

    // Get total rows count
    $countQuery = "SELECT COUNT(*) FROM tgg_email_log $whereClause";
    $countStmt = $appDb->prepare($countQuery);
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($totalRows / $limit);

    // Fetch paginated logs
    $logQuery = "SELECT id, recipient_id, sender_id, recipient, subject, body, sent_at 
                 FROM tgg_email_log 
                 $whereClause 
                 ORDER BY id DESC 
                 LIMIT $limit OFFSET $offset";
    
    $logStmt = $appDb->prepare($logQuery);
    $logStmt->execute($params);
    $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

    // Resolve Sender/Recipient CiviCRM Contact names
    $contactIds = [];
    foreach ($logs as $log) {
        if (!empty($log['recipient_id'])) {
            $contactIds[] = (int)$log['recipient_id'];
        }
        if (!empty($log['sender_id'])) {
            $contactIds[] = (int)$log['sender_id'];
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
            error_log("Failed to load contact names from local contacts for email log: " . $civiEx->getMessage());
        }
    }
} catch (Exception $e) {
    $errorMsg = safe_err("Database Error: ", $e);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Audit Log - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
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
            color: #white;
            flex-grow: 1;
            font-size: 0.9rem;
            outline: none;
            transition: all 0.2s ease;
        }
        .search-bar-container input:focus {
            border-color: var(--color-primary);
            background: rgba(255, 255, 255, 0.1);
        }
        .btn-view-mail {
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
        .btn-view-mail:hover {
            background: rgba(9, 132, 227, 0.3);
            border-color: var(--color-primary);
            color: #fff;
        }
        .close:hover {
            color: #fff !important;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Navigation Bar -->
        <header class="navbar">
            <div class="logo">TGG Members</div>
            <?php if (has_role('admin')): ?>
                <form action="<?php echo rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/admin/dashboard.php'; ?>" method="GET" class="navbar-search-form" style="margin: 0 20px; flex-grow: 1; max-width: 380px; position: relative;">
                    <input type="text" name="search" placeholder="Search members by name..." 
                        value="<?php echo isset($_GET['search']) ? e($_GET['search']) : ''; ?>"
                        style="width: 100%; padding: 8px 15px 8px 35px; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 20px; color: #fff; font-size: 0.85rem; outline: none; transition: all 0.2s ease;">
                    <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: rgba(255, 255, 255, 0.4); font-size: 0.9rem;">🔍</span>
                </form>
            <?php endif; ?>
            <nav class="nav-links">
                <a href="../index.php">Dashboard</a>
                <a href="../calendar.php">Calendar</a>
                <a href="../volunteers.php">Volunteers</a>
                <a href="../checkin.php">Check-In</a>
                <a href="dashboard.php" class="active">Admin</a>
                <a href="../index.php?action=logout&amp;csrf_token=<?php echo e(get_csrf_token()); ?>" class="btn-logout">Logout</a>
            </nav>
        </header>

        <main class="main-content">
            <div class="admin-grid">
                
                <!-- Sidebar Admin Navigation -->
                <aside class="admin-sidebar glass-panel">
                    <h3>Admin Controls</h3>
                    <ul class="admin-menu">
                        <li><a href="dashboard.php">Dashboard</a></li>
                        <li><a href="scheduler.php">Event Scheduler</a></li>
                        <li><a href="volunteer_credits.php">Volunteer Credits</a></li>
                        <li><a href="import.php">CiviCRM Importer</a></li>
                        <li><a href="memberships.php">Memberships</a></li>
                        <li><a href="email_templates.php">Email Templates</a></li>
                        <li>
                            <a href="reports.php" class="active">Reports & Analytics</a>
                            <ul class="admin-submenu" style="list-style-type: none; padding-left: 15px; margin-top: 5px; display: flex; flex-direction: column; gap: 4px;">
                                <li><a href="payments.php" style="padding: 6px 10px; font-size: 0.85rem; border-left: none; border-radius: 4px;">Payments Log</a></li>
                                <li><a href="attendance.php" style="padding: 6px 10px; font-size: 0.85rem; border-left: none; border-radius: 4px;">Attendance Log</a></li>
                                <li><a href="email_log.php" class="active" style="padding: 6px 10px; font-size: 0.85rem; border-left: none; border-radius: 4px;">Email Log</a></li>
                            </ul>
                        </li>
                    </ul>
                </aside>

                <!-- Work Area: Email Log -->
                <section class="admin-workspace">
                    <h2>Outgoing Email Audit Log</h2>
                    <p class="description-text" style="margin-bottom: 25px;">History of all transactional and automated system emails dispatched to members.</p>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <!-- Search Form -->
                    <form action="email_log.php" method="GET" class="search-bar-container">
                        <input type="text" name="search" placeholder="Search by recipient, subject, or content..." value="<?php echo e($search); ?>">
                        <button type="submit" class="btn btn-primary" style="padding: 10px 20px;">Search</button>
                        <?php if ($search !== ""): ?>
                            <a href="email_log.php" class="btn btn-secondary" style="padding: 10px 15px; display: flex; align-items: center; justify-content: center;">Clear</a>
                        <?php endif; ?>
                    </form>

                    <div class="table-card glass-panel span-full-row">
                        <div class="admin-table-container">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Date &amp; Time Sent</th>
                                        <th>Sender</th>
                                        <th>Recipient</th>
                                        <th>Subject</th>
                                        <th style="width: 100px; text-align: center;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($logs)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center" style="padding: 20px;">No outgoing email logs found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($logs as $log): ?>
                                            <?php 
                                                // Format sender info
                                                $senderText = "System";
                                                if (!empty($log['sender_id'])) {
                                                    $sId = (int)$log['sender_id'];
                                                    $sName = $namesMap[$sId] ?? "Member #$sId";
                                                    $senderText = "<strong>" . e($sName) . "</strong><br><span style='font-size:0.75rem; color:rgba(255,255,255,0.4);'>ID: $sId</span>";
                                                }

                                                // Format recipient info
                                                $recipientText = e($log['recipient']);
                                                if (!empty($log['recipient_id'])) {
                                                    $rId = (int)$log['recipient_id'];
                                                    $rName = $namesMap[$rId] ?? "Member #$rId";
                                                    $recipientText = "<strong>" . e($rName) . "</strong><br><span style='font-size:0.75rem; color:rgba(255,255,255,0.5);'>" . e($log['recipient']) . "</span><br><span style='font-size:0.7rem; color:rgba(255,255,255,0.3);'>ID: $rId</span>";
                                                }
                                            ?>
                                            <tr>
                                                <td><span class="table-datetime"><?php echo date('Y-m-d H:i:s', strtotime($log['sent_at'])); ?></span></td>
                                                <td><?php echo $senderText; ?></td>
                                                <td><?php echo $recipientText; ?></td>
                                                <td><strong><?php echo e($log['subject']); ?></strong></td>
                                                <td style="text-align: center;">
                                                    <button type="button" class="btn-view-mail" 
                                                        onclick="openModal(
                                                            <?php echo e(json_encode($log['subject'])); ?>, 
                                                            <?php echo e(json_encode(date('Y-m-d H:i:s', strtotime($log['sent_at'])))); ?>, 
                                                            <?php echo e(json_encode($log['sender_id'] ? ($namesMap[(int)$log['sender_id']] ?? 'Member #'.$log['sender_id']) . ' (ID: '.$log['sender_id'].')' : 'System')); ?>, 
                                                            <?php echo e(json_encode(($namesMap[(int)$log['recipient_id']] ?? 'Member').' <'.$log['recipient'].'>' . ($log['recipient_id'] ? ' (ID: '.$log['recipient_id'].')' : ''))); ?>, 
                                                            <?php echo e(json_encode($log['body'])); ?>

                                                        )">
                                                        View
                                                    </button>
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

        <footer class="app-footer">
            <p>&copy; <?php echo date('Y'); ?> TGG Club Membership System. Secure Admin Portal.</p>
        </footer>
    </div>

    <!-- Email Content Preview Modal -->
    <div id="email-modal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(5px);">
        <div class="modal-content glass-panel" style="background: rgba(30, 30, 40, 0.95); margin: 5% auto; padding: 25px; border: 1px solid rgba(255, 255, 255, 0.1); width: 70%; max-width: 800px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 15px; margin-bottom: 20px;">
                <h3 id="modal-subject" style="margin: 0; color: #fff; font-size: 1.2rem;">Email Subject</h3>
                <span class="close" onclick="closeModal()" style="color: rgba(255,255,255,0.6); font-size: 28px; font-weight: bold; cursor: pointer; transition: color 0.2s;">&times;</span>
            </div>
            <div class="modal-meta" style="margin-bottom: 15px; font-size: 0.85rem; color: rgba(255,255,255,0.7); display: flex; flex-direction: column; gap: 5px; background: rgba(0,0,0,0.2); padding: 12px; border-radius: 8px;">
                <div><strong>Sent At:</strong> <span id="modal-sent-at"></span></div>
                <div><strong>From:</strong> <span id="modal-sender"></span></div>
                <div><strong>To:</strong> <span id="modal-recipient"></span></div>
            </div>
            <div style="border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; background: white; overflow: hidden;">
                <iframe id="modal-body-frame" sandbox="allow-same-origin" style="width: 100%; height: 400px; border: none; background: white;"></iframe>
            </div>
        </div>
    </div>

    <!-- Script for Modal management -->
    <script>
        function openModal(subject, sentAt, sender, recipient, body) {
            document.getElementById('modal-subject').innerText = subject;
            document.getElementById('modal-sent-at').innerText = sentAt;
            document.getElementById('modal-sender').innerText = sender;
            document.getElementById('modal-recipient').innerText = recipient;

            const iframe = document.getElementById('modal-body-frame');
            const doc = iframe.contentDocument || iframe.contentWindow.document;

            const htmlContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="utf-8">
                    <style>
                        body {
                            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                            font-size: 14px;
                            line-height: 1.5;
                            color: #2f3542;
                            margin: 20px;
                            background-color: #ffffff;
                        }
                        a {
                            color: #0984e3;
                            text-decoration: underline;
                        }
                        h2 {
                            color: #2f3542;
                            margin-top: 0;
                        }
                        ul, ol {
                            padding-left: 20px;
                        }
                    </style>
                </head>
                <body>
                    ${body}
                </body>
                </html>
            `;

            doc.open();
            doc.write(htmlContent);
            doc.close();

            document.getElementById('email-modal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('email-modal').style.display = 'none';
        }

        window.onclick = function(event) {
            const modal = document.getElementById('email-modal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>
