<?php
/**
 * Admin - Email Templates Manager
 * Allows administrators to edit transactional email templates (subject, HTML body)
 * and view documentation for dynamic placeholders.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config/bootstrap.php';

use App\Auth;
use App\Database;

Auth::requireAdmin();
Auth::requirePermission('all');

$errorMsg = null;
$successMsg = null;

// Template placeholder documentation definitions
$placeholdersDoc = [
    'signup' => [
        '{display_name}' => 'The display name of the member (e.g. Jane Doe)',
        '{email}' => 'The registered email address of the member',
        '{login_url}' => 'The absolute URL to the member portal login page'
    ],
    'payment_received' => [
        '{display_name}' => 'The display name of the member (e.g. Jane Doe)',
        '{tier_name}' => 'The name of the subscription plan purchased (e.g. Annual Standard)',
        '{amount}' => 'The transaction charge amount in USD (e.g. 120.00)',
        '{start_date}' => 'The subscription start date (YYYY-MM-DD)',
        '{end_date}' => 'The subscription expiration date (YYYY-MM-DD)',
        '{login_url}' => 'The absolute URL to the member portal login page'
    ],
    'credits_converted' => [
        '{display_name}' => 'The display name of the member (e.g. Jane Doe)',
        '{credits_used}' => 'The amount of volunteer credits applied (e.g. 4.0)',
        '{months_extended}' => 'The number of months the membership was extended (e.g. 1)',
        '{new_end_date}' => 'The updated membership expiration date (YYYY-MM-DD)'
    ],
    'password_reset_link' => [
        '{display_name}' => 'The display name of the member (e.g. Jane Doe)',
        '{reset_link}' => 'The absolute password reset link URL with verification token',
        '{expires_in}' => 'The time until the token expires (e.g. 1 hour)'
    ],
    'password_reset_completed' => [
        '{display_name}' => 'The display name of the member (e.g. Jane Doe)',
        '{login_url}' => 'The absolute URL to the member portal login page'
    ]
];

try {
    $appDb = Database::getAppConnection();

    // 1. Fetch all templates for list selection
    $stmt = $appDb->query("SELECT template_key, subject, description FROM tgg_email_templates ORDER BY template_key ASC");
    $templates = $stmt->fetchAll();

    // Determine current active template key
    $activeKey = $_GET['key'] ?? 'signup';
    if (!in_array($activeKey, array_column($templates, 'template_key'))) {
        $activeKey = 'signup';
    }

    // 2. Fetch active template details
    $activeStmt = $appDb->prepare("SELECT * FROM tgg_email_templates WHERE template_key = :key LIMIT 1");
    $activeStmt->execute(['key' => $activeKey]);
    $activeTemplate = $activeStmt->fetch();

    if (!$activeTemplate) {
        throw new Exception("Template '{$activeKey}' not found.");
    }

    // 3. Handle Form Submission to save template changes
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            $errorMsg = "Invalid security token. Please reload the page.";
        } else {
            $subject = trim($_POST['subject'] ?? '');
            $body = trim($_POST['body'] ?? '');

            // Sanitize template body to remove HTML injection / XSS tags (OWASP A03)
            $body = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $body);
            $body = preg_replace('/\bon[a-z]+\s*=\s*["\'][^"\']*["\']/is', '', $body);
            $body = preg_replace('/\bon[a-z]+\s*=\s*[^>\s]+/is', '', $body);
            $body = preg_replace('/href\s*=\s*["\']\s*javascript:[^"\']*["\']/is', 'href="#"', $body);

            if (empty($subject) || empty($body)) {
                $errorMsg = "Subject and Body content cannot be empty.";
            } else {
                $updateStmt = $appDb->prepare("UPDATE tgg_email_templates SET subject = :subject, body = :body WHERE template_key = :key");
                $updateStmt->execute([
                    'subject' => $subject,
                    'body' => $body,
                    'key' => $activeKey
                ]);
                $successMsg = "Email template updated successfully.";
                
                // Refresh active template data
                $activeTemplate['subject'] = $subject;
                $activeTemplate['body'] = $body;
            }
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
    <title>Email Templates Manager - Admin Controls</title>
    <link rel="stylesheet" href="../assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <style>
        .template-layout {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 25px;
            align-items: start;
        }
        .template-list-panel {
            padding: 20px;
        }
        .template-links {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 15px;
        }
        .template-link-btn {
            display: flex;
            flex-direction: column;
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            color: #fff;
            text-decoration: none;
            transition: all 0.2s ease;
            text-align: left;
        }
        .template-link-btn:hover {
            background: rgba(255, 255, 255, 0.06);
            border-color: var(--color-primary);
        }
        .template-link-btn.active {
            background: rgba(var(--color-primary-rgb, 9, 132, 227), 0.15);
            border-color: var(--color-primary);
            box-shadow: 0 0 10px rgba(var(--color-primary-rgb, 9, 132, 227), 0.1);
        }
        .template-link-btn .title {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 4px;
        }
        .template-link-btn .key {
            font-family: monospace;
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.4);
        }
        .editor-container {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .placeholder-box {
            background: rgba(255, 255, 255, 0.02);
            border: 1px dashed rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 15px;
            font-size: 0.85rem;
        }
        .placeholder-box h4 {
            margin-bottom: 8px;
            color: var(--color-primary);
        }
        .placeholder-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 10px;
        }
        .placeholder-item {
            display: flex;
            flex-direction: column;
            background: rgba(0, 0, 0, 0.2);
            padding: 8px 10px;
            border-radius: 4px;
            border: 1px solid rgba(255, 255, 255, 0.03);
        }
        .placeholder-tag {
            font-family: monospace;
            color: #ff7675;
            font-weight: bold;
            margin-bottom: 2px;
        }
        .placeholder-desc {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.8rem;
        }
        .template-form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .template-form-group label {
            font-weight: 600;
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.95);
        }
        .template-form-group input, .template-form-group textarea {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-glass);
            border-radius: 8px;
            color: #fff;
            padding: 12px 16px;
            font-size: 0.9rem;
            font-family: var(--font-body);
            transition: all 0.2s;
        }
        .template-form-group input:focus, .template-form-group textarea:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 8px rgba(var(--color-primary-rgb, 9, 132, 227), 0.2);
        }
        .template-form-group textarea {
            min-height: 350px;
            resize: vertical;
            font-family: monospace;
        }
        @media(max-width: 900px) {
            .template-layout {
                grid-template-columns: 1fr;
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

                <!-- Email Template Editor Work Area -->
                <section class="admin-main-panel">
                    <div class="panel-header">
                        <h2>Email Template Manager</h2>
                        <p class="subtitle">Customize notifications and transactional emails dispatched by the application.</p>
                    </div>

                    <?php if ($successMsg): ?>
                        <div class="alert alert-success"><?php echo e($successMsg); ?></div>
                    <?php endif; ?>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                    <?php endif; ?>

                    <div class="template-layout">
                        
                        <!-- Left Sub-panel: Select Template -->
                        <div class="glass-panel template-list-panel">
                            <h3>Select Template</h3>
                            <div class="template-links">
                                <?php foreach ($templates as $t): ?>
                                    <a href="email_templates.php?key=<?php echo e($t['template_key']); ?>" 
                                       class="template-link-btn <?php echo ($activeKey === $t['template_key']) ? 'active' : ''; ?>">
                                        <span class="title"><?php echo e($t['subject']); ?></span>
                                        <span class="key"><?php echo e($t['template_key']); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Right Sub-panel: Editor Form -->
                        <div class="glass-panel editor-container">
                            <h3>Edit Template: <span style="color: var(--color-primary); font-family: monospace;"><?php echo e($activeKey); ?></span></h3>
                            <p style="font-size: 0.85rem; color: rgba(255, 255, 255, 0.6);"><?php echo e($activeTemplate['description']); ?></p>

                            <!-- Placeholder Info -->
                            <div class="placeholder-box">
                                <h4>Available Placeholders</h4>
                                <p style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.5); margin-bottom: 10px;">These tags will be dynamically replaced with user data at runtime:</p>
                                <div class="placeholder-list">
                                    <?php if (isset($placeholdersDoc[$activeKey])): ?>
                                        <?php foreach ($placeholdersDoc[$activeKey] as $tag => $desc): ?>
                                            <div class="placeholder-item">
                                                <span class="placeholder-tag"><?php echo e($tag); ?></span>
                                                <span class="placeholder-desc"><?php echo e($desc); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="placeholder-desc">No dynamic placeholders defined.</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Editor Form -->
                            <form action="email_templates.php?key=<?php echo e($activeKey); ?>" method="POST" style="display: flex; flex-direction: column; gap: 20px;">
                                <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">

                                <div class="template-form-group">
                                    <label for="subject">Email Subject</label>
                                    <input type="text" id="subject" name="subject" required value="<?php echo e($activeTemplate['subject']); ?>">
                                </div>

                                <div class="template-form-group">
                                    <label for="body">HTML Body Content</label>
                                    <textarea id="body" name="body" required><?php echo e($activeTemplate['body']); ?></textarea>
                                </div>

                                <div class="template-form-group">
                                    <label>Live Preview</label>
                                    <div class="preview-container" style="border: 1px solid var(--border-glass); border-radius: 8px; background: white; padding: 0; min-height: 250px; overflow: hidden; display: flex; flex-direction: column;">
                                        <div class="preview-header" style="background: #f1f2f6; border-bottom: 1px solid #dfe4ea; padding: 6px 12px; font-family: sans-serif; font-size: 0.8rem; color: #57606f; display: flex; justify-content: space-between; align-items: center;">
                                            <span>Email Body Visual Preview (HTML)</span>
                                            <span style="background: #2ed573; color: white; border-radius: 12px; padding: 2px 8px; font-size: 0.7rem; font-weight: bold;">Live</span>
                                        </div>
                                        <iframe id="preview-frame" style="border: none; width: 100%; height: 300px; background: white;"></iframe>
                                    </div>
                                </div>

                                <div style="display: flex; justify-content: flex-end;">
                                    <button type="submit" class="btn btn-primary" style="padding: 10px 25px;">Save Changes</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </section>
            </div>
        </main>

        <?php $footerText = 'TGG Club Membership System. Secure Portal.'; include __DIR__ . '/../partials/footer.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const bodyTextarea = document.getElementById('body');
            const previewFrame = document.getElementById('preview-frame');

            function updatePreview() {
                if (!previewFrame || !bodyTextarea) return;
                const doc = previewFrame.contentDocument || previewFrame.contentWindow.document;
                
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
                        ${bodyTextarea.value}
                    </body>
                    </html>
                `;
                
                doc.open();
                doc.write(htmlContent);
                doc.close();
            }

            if (bodyTextarea) {
                bodyTextarea.addEventListener('input', updatePreview);
                // Initial update
                updatePreview();
            }
        });
    </script>
</body>
</html>
