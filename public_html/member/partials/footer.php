<?php
/**
 * Shared page footer.
 * Caller sets these variables before including this file:
 *   $navPrefix   string  Path prefix to assets, matches the value used for navbar.php (default '')
 *   $footerText  string  Copyright line text (default 'TGG Club Membership System. Secure Public Portal.')
 */
$navPrefix = $navPrefix ?? '';
$footerText = $footerText ?? 'TGG Club Membership System. Secure Public Portal.';
?>
        <footer class="app-footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo e($footerText); ?></p>
        </footer>
    </div>

    <script src="<?php echo $navPrefix; ?>assets/js/main.js<?php echo asset_version('assets/js/main.js'); ?>"></script>
