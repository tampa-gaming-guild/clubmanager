<?php
/**
 * Public About page — static marketing content, no login required.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About - Tampa Gaming Guild</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="favicon.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="assets/css/marketing.css<?php echo asset_version('assets/css/marketing.css'); ?>">
</head>
<body>
    <div class="app-container">
        <?php $navActive = 'about'; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content">
            <section class="hero-banner" style="background-image: url('assets/images/hero-shelf.jpg');">
                <div class="hero-banner-content">
                    <h1>About</h1>
                </div>
            </section>

            <section class="marketing-section">
                <div class="glass-panel">
                    <span class="eyebrow">About us</span>
                    <h2 style="text-align: left;">Enjoy the best place to game</h2>
                    <p style="color: var(--color-text-secondary);">We are a non-profit club formed to provide a place to play board games, role playing games, and other tabletop games. We meet twice a week on Wednesday and Friday evenings, and twice a month on the 1st and 3rd Sunday. Our current location is the Tampa Bay Bridge Center.</p>
                </div>
            </section>

            <section class="marketing-section">
                <h2>Club Rules</h2>
                <p class="section-intro" style="color: var(--color-primary); font-weight: 600;">Tampa Gaming Guild is a Private Membership Club and our rules are designed to provide a comfortable atmosphere for everyone, as well as adhering to state and local rules regarding clubs.</p>

                <div class="glass-panel">
                    <ol class="rules-list">
                        <li>Management can refuse access to or ask anyone to leave the facility at their discretion.</li>
                        <li>This is a drug free facility. Possession and/or use of illegal substances will not be tolerated.</li>
                        <li>No smoking or vaping in the club.</li>
                        <li>We do not have licenses for alcohol or gambling. Such activities are prohibited in the club or on the property.</li>
                        <li>No one under 18 years of age is allowed in the club unless accompanied by a responsible adult who has an active club membership.</li>
                        <li>Games from our library are for use in the club only. Please do not remove them from the facility.</li>
                        <li>Ask before moving, opening or using another member&rsquo;s games or materials.</li>
                        <li>Clean up after yourself. Help us keep the club clean, especially in the kitchen and bathrooms.</li>
                        <li>Bullying, racist language or behavior, and any kind of intimidation of other members will be grounds for termination of your membership without a refund.</li>
                        <li>Please complete activities by closing time so our staff can go home when expected.</li>
                    </ol>
                </div>
            </section>
        </main>

        <?php include __DIR__ . '/partials/footer.php'; ?>
    </div>
</body>
</html>
