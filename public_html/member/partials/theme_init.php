<?php
/**
 * FOUC-safe theme bootstrap. Must be included as the FIRST line inside <head>,
 * before any <link rel="stylesheet">, so `data-theme` is set on <html> before
 * the browser paints. Mirrors the resolution logic in assets/js/theme.js --
 * keep the two in sync if the storage key or default ever changes.
 */
?>
<script>
(function () {
    try {
        var pref = localStorage.getItem('tgg-theme') || 'light';
        var resolved = pref === 'system'
            ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
            : pref;
        if (resolved === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    } catch (e) {}
})();
</script>
