# Porting React-branch UI polish back onto the PHP UI (`main`)

Context: `feature/react-api-first` is being abandoned (too many migration bugs — stale
links, template-key typos, broken checkout redirects). This checklist salvages the
genuine visual/UX polish from that branch and re-applies it directly to the PHP +
`assets/js/main.js` UI on `main`. Every item below was verified against the actual
current state of `main` before being listed — items that turned out to already work
on `main` are called out explicitly so they don't get redone.

React source of truth for styling values: `frontend/src/pages/CalendarPage.tsx`,
`frontend/src/pages/admin/AdminLayout.tsx`, `frontend/src/components/Navbar.tsx`.

---

## 1. Volunteers list (`public_html/member/volunteers.php`) — highest value, cheapest

**Correction (my earlier audit fork was wrong here — it only grepped `style.css` and
missed the page's own inline `<style>` block at lines 181-247, which already defines
all of these classes):**

- [x] `.filter-toggle-container` / `.filter-toggle` / `.filter-toggle a.active` —
  **already fully styled** (rounded pill buttons, active state filled with
  `var(--color-primary)`). No action needed.
- [x] `.volunteer-status-needed` — **already fully styled** (amber "👋 Volunteer
  Needed" text). No action needed.
- [x] **DONE 2026-07-10** `.bullet-open` / `.bullet-close` swapped from
  blue/purple to `var(--color-success)` / `var(--color-danger)`.
- [x] **DONE 2026-07-10** Assigned-column badge now role-colored with an
  `$isMe` intensity/emoji distinction (🙋 stronger tint + border vs 👤 lighter
  tint), matching commit `a3d7cec`'s logic. Branch: `feature/php-ui-polish`.
- [ ] *Optional, low priority:* shorten "Volunteer Role" → "Role" and "Volunteer
  Needed" → "Needed" on narrow screens. In React this was a plain JS breakpoint check;
  in PHP/CSS it needs two `<span>`s toggled by a media query (no JS breakpoint hook
  exists server-side). Skip unless doing a full responsive pass on this page.

## 2. Calendar grid mobile density (`public_html/member/calendar.php`)

Note: main renders the day grid as an HTML `<table>`, not a CSS Grid — so **the
`min-width: 0` grid-overflow bug that motivated part of the React fix does not apply
here** (that's a CSS-Grid-specific default; table cells don't have it). No action
needed for that specific gotcha.

- [x] **Correction, then DONE 2026-07-10**: main's color logic was actually
  fill-status-based (`green if filled, red if unfilled`, applied identically to
  both roles) — a different meaning than React's role-based scheme, not the same
  thing as originally logged here. Changed `$openColor`/`$closeColor` in
  `calendar.php` to always be green (Open) / red (Close) regardless of fill
  status; fill status is still visible via the name's presence next to the label
  (desktop) — nothing lost by the change. **Unconfirmed with user** — they didn't
  respond when asked whether to keep the old fill-based scheme instead; this went
  with role-based since that's what was explicitly asked to be ported. Revert if
  that guess was wrong.
- [x] **DONE 2026-07-10**: added a `@media (max-width: 640px)` compact mode —
  hides the `O:`/`C:` text label and name, shows a small dot instead (hollow via
  `border`, solid via `background-color` when filled), colored by role. Weekday
  header abbreviation (`Sun`→`S`) was left alone — skipped as truly optional.
- [ ] *Skip:* removing the permanent blue "today" highlight. React removed this
  because it conflicted with a separate "selected day" border used for in-grid
  highlighting — **main's grid has no such competing state** (clicking a day
  navigates to `volunteers.php?highlight=...` instead of selecting in place), so
  there's nothing for the permanent today-highlight to conflict with. Leave as-is.

## 3. Calendar grid desktop — optional, low priority

React's desktop pill swapped a literal "O"/"C" letter for a small dot glyph inside a
name pill. Main doesn't use pills at all here — it already shows `O: Name` / `C: Name`
as colored text labels, which communicates the same information just as clearly. Purely
cosmetic to change further; skip unless there's spare time after items 1–2.

## 4. Already present on `main` — verified, no action needed

- **Navbar mobile hamburger drawer** — `partials/navbar.php` already has the
  `#navbarToggle` button + `#navLinks` nav, and `assets/js/main.js` (section "4. Mobile
  Navbar Toggle") already wires up open/close-on-outside-click/Escape/link-click. Fully
  functional today.
- **Navbar active-link highlighting** — already done server-side via the `$navActive`
  variable passed into `navbar.php`, and Calendar vs. Volunteers are genuinely separate
  PHP files on `main` (not one SPA route disambiguated by query param), so there's no
  version of the ambiguity problem React had to solve.
- **Logout button styling** — `navbar.php` renders logout as an `<a class="btn-logout">`
  (not a `<button>`), and `style.css`'s `.nav-links a.btn-logout` selector matches that
  element type correctly. The mismatch bug React had (button vs. anchor selector) does
  not exist on `main`.
- **Admin sidebar mobile drawer (swipe + toggle + backdrop)** — `admin/sidebar.php`
  already has `#adminSidebarToggle` / `#adminSidebarBackdrop` / `#adminSidebarPanel`,
  and `main.js` (section "5. Admin Sidebar Drawer Toggle") already implements the full
  edge-swipe gesture with the same constants React re-derived (`EDGE_ZONE = 24`,
  `OPEN_THRESHOLD = 0.35`), tap-to-toggle, backdrop click-to-close, and Escape-to-close.
  Fully functional today — React was independently re-implementing something that
  already worked on `main`.
- **Admin layout width parity** — `admin/dashboard.php` (and siblings) already use
  `<main class="main-content"><div class="admin-grid">`, the same centered
  1280px-max container every other page uses. No full-bleed regression exists on
  `main`.
- **Volunteer-credit summary on profile page** — `profile.php`'s "Volunteering &
  Credits" tab (~line 1011) already shows lifetime earned/applied/outstanding/expired
  credits, next-expiration date, and a completed-shifts table — functionally
  equivalent to what React built (React just laid the four numbers out as individual
  cards instead of a 2×2 grid). No porting needed.
