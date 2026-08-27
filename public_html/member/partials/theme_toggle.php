<?php
/**
 * Shared Light/Dark/System theme icon switcher (a 3-button segmented group,
 * modeled on bulma.io's navbar theme switcher). Included from
 * partials/navbar.php (inside .nav-links, so it rides along with that menu's
 * existing desktop-inline / mobile-collapsed behavior) and from
 * partials/marketing_clean.php (inside .clean-menu-bar). Wired up by
 * assets/js/theme.js, which must be loaded on the same page for these
 * buttons to do anything.
 */
?>
<div class="theme-toggle-group" id="themeToggleGroup" role="group" aria-label="Theme">
    <button type="button" class="theme-toggle-btn" data-theme-choice="light" aria-label="Light theme" title="Light">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="4"/>
            <line x1="12" y1="2" x2="12" y2="4"/>
            <line x1="12" y1="20" x2="12" y2="22"/>
            <line x1="4.93" y1="4.93" x2="6.34" y2="6.34"/>
            <line x1="17.66" y1="17.66" x2="19.07" y2="19.07"/>
            <line x1="2" y1="12" x2="4" y2="12"/>
            <line x1="20" y1="12" x2="22" y2="12"/>
            <line x1="4.93" y1="19.07" x2="6.34" y2="17.66"/>
            <line x1="17.66" y1="6.34" x2="19.07" y2="4.93"/>
        </svg>
    </button>
    <button type="button" class="theme-toggle-btn" data-theme-choice="dark" aria-label="Dark theme" title="Dark">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>
        </svg>
    </button>
    <button type="button" class="theme-toggle-btn" data-theme-choice="system" aria-label="Match system theme" title="System">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="2" y="4" width="20" height="14" rx="2"/>
            <line x1="8" y1="21" x2="16" y2="21"/>
            <line x1="12" y1="18" x2="12" y2="21"/>
        </svg>
    </button>
</div>
