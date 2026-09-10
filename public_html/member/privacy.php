<?php
/**
 * Public Privacy Policy page — static content, no login required.
 * Referenced from the App Store / Play Store listings for the TGG mobile app,
 * as well as linked from the site footer.
 */
require_once (function() {
    $dir = dirname(dirname(__DIR__));
    if (file_exists($dir . '/.env') && $lines = @file($dir . '/.env')) {
        foreach ($lines as $line) {
            if (preg_match('/^\s*BOOTSTRAP_PATH\s*=\s*["\']?(.*?)["\']?\s*$/', $line, $m)) {
                return $m[1];
            }
        }
    }
    return $dir . '/config/bootstrap.php';
})();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/partials/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - Tampa Gaming Guild</title>
    <meta name="description" content="Privacy policy for Tampa Gaming Guild's club membership system and mobile app: what information we collect, how it's used, and who it's shared with.">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="favicon.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="assets/css/marketing.css<?php echo asset_version('assets/css/marketing.css'); ?>">
</head>
<body>
    <div class="app-container">
        <?php $navActive = 'privacy'; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content">
            <section class="hero-banner" style="background-image: url('assets/images/hero-shelf.jpg');">
                <div class="hero-banner-content">
                    <h1>Privacy Policy</h1>
                </div>
            </section>

            <section class="marketing-section">
                <div class="glass-panel">
                    <span class="eyebrow">Last updated</span>
                    <h2 style="text-align: left;"><?php echo date('F j, Y'); ?></h2>
                    <p style="color: var(--color-text-secondary);">This policy covers the Tampa Gaming Guild club membership system, including this website and the Tampa Gaming Guild mobile app ("the app"). Tampa Gaming Guild is a non-profit club based in Tampa, FL, and this system exists solely to manage our own club's membership, attendance, and events &mdash; we do not sell or rent your information to anyone.</p>
                </div>
            </section>

            <section class="marketing-section">
                <h2>Information we collect</h2>
                <div class="glass-panel">
                    <p style="color: var(--color-text-secondary);"><strong>Account and membership information.</strong> When you join or maintain a membership, we collect the information you provide: your name, contact details (email, phone), membership status, and attendance history at club events.</p>
                    <p style="color: var(--color-text-secondary);"><strong>Location and Bluetooth (mobile app only).</strong> The app can use Bluetooth and location to automatically detect when you're at the club, as a convenience for checking in to attendance &mdash; you don't have to do this manually. This is optional: the app works without it, and you can check in manually instead. We only use this to record attendance for club events; we do not track or store your location history beyond that.</p>
                    <p style="color: var(--color-text-secondary);"><strong>Payment information.</strong> When you pay dues or fees through the app or website, your payment is handled directly by our payment processor, Stripe. We do not receive or store your full card number &mdash; only a record that a payment was made, for our own accounting.</p>
                    <p style="color: var(--color-text-secondary);"><strong>Device notifications.</strong> If you enable notifications, the app can show you local reminders (for example, about upcoming events). These are generated on your device and are not sent anywhere else.</p>
                </div>
            </section>

            <section class="marketing-section">
                <h2>How we use your information</h2>
                <div class="glass-panel">
                    <p style="color: var(--color-text-secondary);">We use your information to run the club: managing your membership and dues, recording attendance, communicating with you about club events and account matters, and maintaining the security of your account and our systems.</p>
                </div>
            </section>

            <section class="marketing-section">
                <h2>Who we share it with</h2>
                <div class="glass-panel">
                    <p style="color: var(--color-text-secondary);">We share information only where necessary to run the club: with Stripe, to process payments, and with club officers/staff who need it to manage membership and events. We do not sell your information, and we do not share it with advertisers or data brokers.</p>
                </div>
            </section>

            <section class="marketing-section">
                <h2>How long we keep it</h2>
                <div class="glass-panel">
                    <p style="color: var(--color-text-secondary);">We keep membership and attendance records for as long as your membership is active, and for a reasonable period afterward for our own club records. You can ask us to delete your account information at any time, subject to any records we're required to keep for accounting purposes.</p>
                </div>
            </section>

            <section class="marketing-section">
                <h2>Your choices</h2>
                <div class="glass-panel">
                    <p style="color: var(--color-text-secondary);">You can review and update most of your account information from your profile. Location/Bluetooth access and notifications are both optional and can be turned off at any time in your device's settings. You can also ask us to correct or delete your information by contacting us below.</p>
                </div>
            </section>

            <section class="marketing-section">
                <h2>Contact us</h2>
                <div class="glass-panel">
                    <p style="color: var(--color-text-secondary);">Questions about this policy or your information? Contact us at <a href="mailto:privacy@tampagamingguild.org">privacy@tampagamingguild.org</a>, or write to us at Tampa Gaming Guild, 114 W 109th Ave, Tampa, FL 33612.</p>
                </div>
            </section>
        </main>

        <?php include __DIR__ . '/partials/footer.php'; ?>
    </div>
</body>
</html>
