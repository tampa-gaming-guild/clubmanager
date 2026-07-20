<?php
/**
 * Public "How to Find Us" page — static marketing content, no login required.
 */
require_once dirname(dirname(__DIR__)) . '/config/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>How to Find Us - Tampa Gaming Guild</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="favicon.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="assets/css/marketing.css<?php echo asset_version('assets/css/marketing.css'); ?>">
</head>
<body>
    <div class="app-container">
        <?php $navActive = 'findus'; include __DIR__ . '/partials/navbar.php'; ?>

        <main class="main-content">
            <section class="hero-banner" style="background-image: url('assets/images/hero-shelf.jpg');">
                <div class="hero-banner-content">
                    <h1>How to Find Us</h1>
                </div>
            </section>

            <section class="marketing-section">
                <p class="section-intro">Our current location is the Tampa Bay Bridge Center in Temple Terrace.</p>

                <div class="map-embed">
                    <iframe
                        src="https://www.google.com/maps?q=Tampa+Bay+Bridge+Center,+114+W+109th+Ave,+Tampa,+FL+33612&output=embed"
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                        title="Interactive map to Tampa Bay Bridge Center, 114 W 109th Ave, Tampa, FL 33612"
                        allowfullscreen></iframe>
                </div>
            </section>
        </main>

        <?php include __DIR__ . '/partials/footer.php'; ?>
    </div>
</body>
</html>
