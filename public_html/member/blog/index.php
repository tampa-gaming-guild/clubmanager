<?php
/**
 * 301 redirect from the old WordPress site's /blog/ to its new
 * equivalent -- preserves SEO ranking/backlinks for anyone landing here
 * from an old bookmark or a search result indexed before the migration.
 */
header('Location: ../index.php', true, 301);
exit;
