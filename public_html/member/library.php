<?php
/**
 * Public Library page — static marketing content, no login required.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library - Tampa Gaming Guild</title>
    <meta name="description" content="Browse Tampa Gaming Guild's library of 400+ board games, available to play at the club. Full collection listed on BoardGameGeek.">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="favicon.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="assets/css/marketing.css<?php echo asset_version('assets/css/marketing.css'); ?>">
</head>
<body>
    <div class="app-container">
        <?php $navActive = 'library'; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content">
            <section class="hero-banner" style="background-image: url('assets/images/hero-shelf.jpg');">
                <div class="hero-banner-content">
                    <h1>Library</h1>
                </div>
            </section>

            <section class="marketing-section">
                <p class="section-intro">You can find our full collection of board games listed out on <a href="https://boardgamegeek.com/collection/user/TampaGamingGuild" target="_blank" rel="noopener">Board Game Geek</a>.</p>
            </section>
        </main>

        <?php include __DIR__ . '/partials/footer.php'; ?>
    </div>
</body>
</html>
