/**
 * Clicking a calendar day AJAX-swaps a target panel's content instead of a
 * full page reload -- shared by the public calendar.php day panel and
 * volunteers.php's Combo view list panel (see partials/calendar_grid.php's
 * $cgAjaxTarget). An empty response hides the panel (used when deselecting
 * a day on calendar.php); a non-empty response shows it.
 */
document.addEventListener('click', (e) => {
    const cell = e.target.closest('.calendar-day[data-ajax-target]');
    if (!cell) return;

    const container = document.getElementById(cell.dataset.ajaxTarget);
    if (!container) return;

    e.preventDefault();

    // Decide select-vs-deselect from the cell's current class, not a
    // server-rendered snapshot -- the grid itself is never re-rendered by
    // this handler, only the target panel, so this is the only state that
    // stays accurate across repeated clicks.
    const wasSelected = cell.classList.contains('selected-day');
    const ajaxHref = wasSelected ? cell.dataset.deselectAjaxHref : cell.dataset.selectAjaxHref;
    const pushUrl = wasSelected ? cell.dataset.deselectPushUrl : cell.dataset.selectPushUrl;

    container.style.opacity = '0.5';

    fetch(ajaxHref)
        .then((r) => {
            if (!r.ok) throw new Error('Request failed');
            return r.text();
        })
        .then((html) => {
            const trimmed = html.trim();
            if (trimmed === '') {
                container.style.display = 'none';
                container.innerHTML = '';
            } else {
                container.innerHTML = html;
                container.style.display = '';
            }
            container.style.opacity = '1';

            document.querySelectorAll('.calendar-day.selected-day').forEach((td) => {
                td.classList.remove('selected-day');
            });
            if (!wasSelected) {
                cell.classList.add('selected-day');
            }

            history.pushState({}, '', pushUrl);
        })
        .catch(() => {
            // Fall back to a normal navigation if the AJAX request fails.
            window.location.href = pushUrl;
        });
});
