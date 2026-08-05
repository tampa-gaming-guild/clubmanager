<?php
/**
 * Public Marketing Homepage
 * URL: https://tgg.test/member/index.php
 * Renders the clean frameless marketing homepage.
 */
require_once (function() {
    $dir = dirname(__DIR__);
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tampa Gaming Guild - Board Gaming &amp; RPG Club</title>
    <meta name="description" content="Tampa Gaming Guild is a non-profit board gaming and RPG club in Tampa, FL. Free Sundays, weekly meetups, and a library of 400+ board games. Join today.">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="favicon.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/css/style.css<?php echo asset_version('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="assets/css/marketing.css<?php echo asset_version('assets/css/marketing.css'); ?>">
    <link rel="stylesheet" href="assets/css/marketing_clean.css<?php echo asset_version('assets/css/marketing_clean.css'); ?>">
</head>
<body class="frameless-page">
    <div class="frameless-wrapper">
        <?php include __DIR__ . '/partials/marketing_clean.php'; ?>
    </div>
</body>
</html>
