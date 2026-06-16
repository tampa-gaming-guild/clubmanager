<?php
/**
 * Join Membership Page
 * Registration form for new members, dynamic CiviCRM tier selection, and Stripe payment redirect.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';

use App\Database;
use App\CiviCRMImporter;
use App\StripeHelper;
use App\Auth;
use App\BillingHelper;
use App\MailHelper;

$tiers = [];
$errorMsg = null;
$successMsg = null;

try {
    $tiers = BillingHelper::getSubscriptionPlans(true);
} catch (Exception $e) {
    $errorMsg = "System Connection Error: Unable to fetch membership tiers.";
}

// 1. Handle Successful Redirect from Stripe
if (isset($_GET['status']) && $_GET['status'] === 'success' && isset($_GET['session_id'])) {
    $sessionId = $_GET['session_id'];
    try {
        $session = StripeHelper::retrieveCheckoutSession($sessionId);
        if ($session['payment_status'] === 'paid') {
            BillingHelper::processCheckoutSession($session);
            $successMsg = "Thank you! Your payment was successful and your membership is active. You can now login using the credentials you created.";
        } else {
            $errorMsg = "Your payment is pending. Once completed, your membership will be active.";
        }
    } catch (Exception $e) {
        $errorMsg = safe_err("Could not verify payment status: ", $e);
    }
}

// 2. Handle Cancelled Redirect from Stripe
if (isset($_GET['status']) && $_GET['status'] === 'cancelled') {
    $errorMsg = "Payment was cancelled. You have not been charged and your membership registration was not completed.";
}

// 3. Handle Form Submission (Sign Up)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['status'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid security token. Please reload the page.";
    } else {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim(strtolower($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $tierId = (int)($_POST['tier_id'] ?? 0);

        if (empty($firstName) || empty($lastName) || empty($email) || empty($password) || empty($tierId)) {
            $errorMsg = "All fields except phone number are required.";
        } elseif (strlen($password) < 8) {
            $errorMsg = "Password must be at least 8 characters long.";
        } else {
            try {
                $appDb = Database::getAppConnection();

                // Check if email already exists in local contacts
                $checkEmail = $appDb->prepare("SELECT id FROM tgg_contacts WHERE email = :email AND is_deleted = 0 LIMIT 1");
                $checkEmail->execute(['email' => $email]);
                if ($checkEmail->fetch()) {
                    $errorMsg = safe_err("There was a problem with your registration. Please contact support. ", new Exception("Email already exists"));
                } else {
                    // Start transaction to ensure sync integrity
                    $appDb->beginTransaction();

                    try {
                        // A. Create Local Contact
                        $displayName = "{$firstName} {$lastName}";
                        $insertContact = $appDb->prepare("INSERT INTO tgg_contacts (contact_type, display_name, first_name, last_name, email, phone, is_deleted) 
                                                           VALUES ('Individual', :display_name, :first_name, :last_name, :email, :phone, 0)");
                        $insertContact->execute([
                            'display_name' => $displayName,
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'email' => $email,
                            'phone' => !empty($phone) ? $phone : null
                        ]);
                        $contactId = (int)$appDb->lastInsertId();

                        // B. Create Local Member Settings / Credentials
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        $defaultPublicFields = json_encode(['display_name', 'membership_name', 'status_label']);
                        $insertSettings = $appDb->prepare("INSERT INTO tgg_member_settings (contact_id, password_hash, role, is_profile_public, public_fields) 
                                                           VALUES (:contact_id, :password_hash, 'member', 1, :public_fields)");
                        $insertSettings->execute([
                            'contact_id' => $contactId,
                            'password_hash' => $passwordHash,
                            'public_fields' => $defaultPublicFields
                        ]);

                        // C. Find tier fee details
                        $tierIndex = array_search($tierId, array_column($tiers, 'id'));
                        if ($tierIndex === false) {
                            throw new Exception("Invalid membership tier selected.");
                        }
                        $tier = $tiers[$tierIndex];
                        $fee = (float)$tier['price'];
                        $tierName = $tier['name'];
                        $civicrmTypeId = (int)$tier['civicrm_membership_type_id'];

                        // Commit transaction
                        $appDb->commit();

                        // Send welcome email on registration signup
                        try {
                            $loginUrl = rtrim($_ENV['BASE_URL'] ?? 'http://localhost/member', '/') . '/index.php';
                            $placeholders = [
                                'display_name' => $displayName,
                                'email' => $email,
                                'login_url' => $loginUrl
                            ];
                            MailHelper::sendTemplate($email, 'signup', $placeholders, $contactId, null);
                        } catch (Exception $mailEx) {
                            // Log mail error and allow user to continue to Stripe checkout
                            error_log("Failed to send welcome email: " . $mailEx->getMessage());
                        }

                        // D. Create Stripe Session and Redirect
                        $session = StripeHelper::createCheckoutSession($contactId, $tierId, $civicrmTypeId, $tierName, $fee, 'join');
                        
                        header("Location: " . $session['url']);
                        exit;

                    } catch (Exception $txException) {
                        if ($appDb->inTransaction()) $appDb->rollBack();
                        throw $txException;
                    }
                }
            } catch (Exception $e) {
                $errorMsg = safe_err("Registration failed: ", $e);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join Our Club - New Member Registration</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="favicon.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="app-container">
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
                <a href="index.php">Home / Login</a>
                <a href="join.php" class="active">Join Us</a>
                <a href="calendar.php">Calendar</a>
                <a href="volunteers.php">Volunteers</a>
            </nav>
        </header>

        <main class="main-content centered-content">
            <div class="auth-panel glass-panel">
                <h2>Become a Member</h2>
                <p class="subtitle">Complete the form below to register and pay your membership dues securely.</p>

                <?php if ($errorMsg): ?>
                    <div class="alert alert-danger"><?php echo e($errorMsg); ?></div>
                <?php endif; ?>

                <?php if ($successMsg): ?>
                    <div class="alert alert-success">
                        <p><?php echo e($successMsg); ?></p>
                        <br>
                        <a href="index.php?action=login" class="btn btn-primary">Go to Login</a>
                    </div>
                <?php else: ?>
                    <form action="join.php" method="POST" class="auth-form">
                        <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="first_name">First Name</label>
                                <input type="text" id="first_name" name="first_name" required value="<?php echo e($_POST['first_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="last_name">Last Name</label>
                                <input type="text" id="last_name" name="last_name" required value="<?php echo e($_POST['last_name'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" required value="<?php echo e($_POST['email'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="phone">Phone Number (Optional)</label>
                            <input type="tel" id="phone" name="phone" value="<?php echo e($_POST['phone'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="password">Create Password (min. 8 characters)</label>
                            <input type="password" id="password" name="password" required>
                        </div>

                        <div class="form-group">
                            <label for="tier_id">Select Membership Tier</label>
                            <select id="tier_id" name="tier_id" required>
                                <option value="" disabled selected>-- Select a Tier --</option>
                                <?php foreach ($tiers as $tier): ?>
                                    <option value="<?php echo (int)$tier['id']; ?>" <?php echo (isset($_POST['tier_id']) && $_POST['tier_id'] == $tier['id']) ? 'selected' : ''; ?>>
                                        <?php echo e($tier['name']); ?> - $<?php echo number_format($tier['minimum_fee'], 2); ?> / <?php echo e($tier['duration_interval']); ?> <?php echo e($tier['duration_unit']); ?>(s)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="info-block payment-warning">
                            <p><strong>Secure Checkout:</strong> Clicking the button below will redirect you to Stripe to pay your membership dues securely. Once your transaction finishes, you will be redirected back here to log in.</p>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block">Proceed to Payment</button>
                    </form>
                <?php endif; ?>
            </div>
        </main>

        <footer class="app-footer">
            <p>&copy; <?php echo date('Y'); ?> TGG Club Membership System. Secure Public Portal.</p>
        </footer>
    </div>

    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('sw.js')
                .then(reg => console.log('Service Worker registered'))
                .catch(err => console.error('Service Worker registration failed', err));
        });
    }
    </script>
</body>
</html>
