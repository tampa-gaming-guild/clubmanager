<!-- CLEAN MARKETING HOMEPAGE VARIANT -->
<div class="clean-page-card">
    <section class="hero-banner hero-banner--home" style="background-image: url('assets/images/hero-shelf.jpg'); border-radius: 0; margin-bottom: 0;">
        <div class="hero-banner-content">
            <div class="hero-banner-left">
                <div class="hero-title-group">
                    <img src="assets/images/header-colored-logo.png" alt="Tampa Gaming Guild" class="hero-colored-logo" loading="lazy">
                    <p class="hero-tagline">Board gaming and RPG club</p>
                    <div class="hero-badges">
                        <span class="hero-badge">🎮 400+ Game Library</span>
                        <span class="hero-badge hero-badge--accent">🎉 Donation Sundays</span>
                        <span class="hero-badge">📅 Weekly Sessions</span>
                    </div>
                </div>
            </div>
            <div class="hero-actions">
                <?php if (\App\Auth::check()): ?>
                    <a href="portal.php" class="btn btn-primary hero-btn-primary">Portal &rarr;</a>
                <?php else: ?>
                    <a href="portal.php?action=login" class="btn hero-btn-login">
                        <img src="assets/images/red-meeple.png" alt="" class="meeple-icon-img" loading="lazy">
                        <span>Login</span>
                    </a>
                    <a href="join.php" class="btn btn-primary hero-btn-primary">Join the Club &rarr;</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Fixed Header Menu Bar below Banner -->
    <nav class="clean-menu-bar" aria-label="Page Navigation Menu">
        <a href="#section-about" class="clean-menu-link">About Us</a>
        <a href="#section-schedule" class="clean-menu-link">Meeting Times</a>
        <a href="#section-pricing" class="clean-menu-link">Membership</a>
        <a href="#section-library" class="clean-menu-link">Game Library</a>
        <a href="#section-events" class="clean-menu-link">Events</a>
    </nav>

    <div class="clean-page-body">
        <section class="marketing-section" id="section-announcements" style="margin-top: 24px;">
            <div class="free-sundays-banner">
                <h2>Donation Sundays!</h2>
                <p>Come play and pitch in what you can &mdash; no pressure, just good games. First time? Just <a href="join.php?tier=trial">register with us</a> when you arrive (or online beforehand) to check in.<br><strong>1st &amp; 3rd Sundays, 1:00pm &ndash; 11:00pm</strong>.</p>
            </div>
        </section>

        <section class="marketing-section" id="section-about">
            <div class="bleed-section bleed-right">
                <div class="bleed-text">
                    <span class="eyebrow">About us</span>
                    <h2>A place to play</h2>
                    <p>We are a non-profit club run entirely by volunteers to provide a comfortable, welcoming place to play board games, war games, role playing games, card games, and other tabletop games. We meet every Wednesday and Friday evening and on the first and third Sunday of the month for the afternoon and evening.</p>
                    <p>Our current location is:<br><strong>Tampa Bay Bridge Center</strong><br>114 W 109th Ave<br>Tampa, FL 33612</p>
                    <div class="marketing-links-group" style="margin-top: 14px; display: flex; flex-direction: column; gap: 6px;">
                        <a href="join.php" class="btn-link">Join &rarr;</a>
                        <a href="how-to-find-us.php" class="btn-link">Map and Directions &rarr;</a>
                        <a href="about.php" class="btn-link">Club Rules &rarr;</a>
                    </div>
                </div>
                <div class="bleed-image">
                    <img src="assets/images/come-game.jpg" alt="Members playing games together at Tampa Gaming Guild" loading="lazy">
                </div>
            </div>
        </section>

        <section class="marketing-section" id="section-schedule">
            <div class="bleed-section bleed-left">
                <div class="bleed-image">
                    <img src="assets/images/about-photo.jpg" alt="A board game in progress" loading="lazy">
                </div>
                <div class="bleed-text">
                    <span class="eyebrow">Come game with us!</span>
                    <h2>Meeting Days and Hours</h2>
                    <p>This is our current schedule. We will be expanding it as needed. If you have a preference for a different day please contact us.</p>
                    <div class="schedule-grid">
                        <div class="schedule-item">
                            <span class="schedule-tag">Wednesdays</span>
                            <h5>Every Wednesday Night</h5>
                            <p>5:30 pm &ndash; 11:00 pm</p>
                        </div>
                        <div class="schedule-item">
                            <span class="schedule-tag">Fridays</span>
                            <h5>Every Friday Night</h5>
                            <p>5:30 pm &ndash; 11:00 pm</p>
                        </div>
                        <div class="schedule-item schedule-item--free">
                            <span class="schedule-tag schedule-tag--free">Donation Sundays</span>
                            <h5>1st &amp; 3rd Sunday</h5>
                            <p>1:00 pm &ndash; 11:00 pm</p>
                        </div>
                    </div>
                    <div class="marketing-links-group" style="margin-top: 14px; display: flex; flex-direction: column; gap: 6px;">
                        <a href="calendar.php" class="btn-link">Calendar &rarr;</a>
                        <a href="how-to-find-us.php" class="btn-link">Map and Directions &rarr;</a>
                    </div>
                </div>
            </div>
        </section>

        <section class="marketing-section" id="section-pricing">
            <span class="eyebrow text-center" style="display: block;">Membership Plans</span>
            <h2>Join Us</h2>
            <p class="section-intro">Choose the membership plan that best fits how often you game with us.</p>

            <div class="tier-cards">
                <div class="glass-panel tier-card tier-trial">
                    <div class="tier-header">
                        <h3>Trial Membership</h3>
                        <div class="tier-price">Free <span>/ 30 Days</span></div>
                    </div>
                    <ul class="tier-features">
                        <li>✔ Attend all regular sessions for 30 days</li>
                        <li>✔ Access to 400+ game library</li>
                        <li>✔ Single-use per member</li>
                    </ul>
                    <a href="join.php?tier=trial" class="btn btn-secondary btn-block">Get Started Free</a>
                </div>

                <div class="glass-panel tier-card tier-regular tier-featured">
                    <span class="featured-ribbon">Most Popular</span>
                    <div class="tier-header">
                        <h3>Regular Membership</h3>
                        <div class="tier-price">$30 <span>/ month</span></div>
                        <div class="tier-price-alt">or $200 / year (Save $160!)</div>
                    </div>
                    <ul class="tier-features">
                        <li>✔ Unlimited access to all regular sessions</li>
                        <li>✔ 1 guest day pass per month (2/mo on annual)</li>
                        <li>✔ Full voting rights for board elections</li>
                    </ul>
                    <a href="join.php?tier=regular" class="btn btn-primary btn-block">Join as Regular Member</a>
                </div>

                <div class="glass-panel tier-card tier-associate">
                    <div class="tier-header">
                        <h3>Associate Membership</h3>
                        <div class="tier-price">$10 <span>/ session</span></div>
                    </div>
                    <ul class="tier-features">
                        <li>✔ Pay per session entry fee</li>
                        <li>✔ Access to 400+ game library</li>
                        <li>✔ No monthly recurring fee</li>
                    </ul>
                    <a href="join.php?tier=associate" class="btn btn-secondary btn-block">Join as Associate</a>
                </div>
            </div>
        </section>

        <section class="marketing-section" id="section-library">
            <h2>Game Library</h2>
            <p class="section-intro">We have more than 400 board games in our library which you are welcome to play while you are here. Our volunteers will be glad to teach you a game you don&rsquo;t know. You are also welcome to bring your own games from home. <a href="library.php" class="btn-link">See all of our games here &rarr;</a></p>
            <div class="library-display-grid">
                <div class="library-display-item">
                    <span class="eyebrow">400+ Board Games</span>
                    <img src="assets/images/game-shelf-full.jpg" alt="Tampa Gaming Guild full game library shelf" loading="lazy">
                </div>
                <div class="library-display-item">
                    <span class="eyebrow">Featured Games in Action</span>
                    <img src="assets/images/game-library-collage.jpg" alt="Collage of board games at Tampa Gaming Guild" loading="lazy">
                </div>
            </div>
        </section>

        <section class="marketing-section" id="section-events">
            <h2>Events</h2>
            <div class="bleed-section bleed-left" style="margin-top: 24px;">
                <div class="bleed-image">
                    <img src="assets/images/botc.jpg" alt="Blood on the Clocktower session at Tampa Gaming Guild" loading="lazy">
                </div>
                <div class="bleed-text">
                    <div class="event-header-group">
                        <img src="assets/images/botc-logo.jpg" alt="Blood on the Clocktower Logo" class="event-logo" loading="lazy">
                        <div class="event-meta">
                            <span class="event-badge">📅 Every 3rd Sunday at 1:00 pm</span>
                            <span class="eyebrow">Social Deduction Game</span>
                        </div>
                    </div>
                    <h3>Blood on the Clocktower</h3>
                    <p>Blood on the Clocktower is an afternoon of social deduction and hidden roles, one of the most beloved and acclaimed social deduction games around! Currently meeting here every 3rd Sunday at 1:00 pm.</p>
                </div>
            </div>

            <div class="bleed-section bleed-left" style="margin-top: 40px;">
                <div class="bleed-image">
                    <img src="assets/images/flea-market.jpg" alt="Semi-annual board game flea market at Tampa Gaming Guild" loading="lazy">
                </div>
                <div class="bleed-text">
                    <span class="event-badge">🏷️ Semi-Annual Event</span>
                    <span class="eyebrow">Bargain Game Sales</span>
                    <h3>Flea Market</h3>
                    <p>We hold a semi-annual flea market where you can buy and sell used games. Hundreds of games are for sale at bargain prices!</p>
                    <p style="font-weight: 600; color: #1e293b; margin-top: 8px;">Next flea market date: to be determined.</p>
                </div>
            </div>

            <div class="bleed-section bleed-left" style="margin-top: 40px;">
                <div class="bleed-image">
                    <img src="assets/images/kardboard-for-kids.png" alt="Kardboard for Kids charity fundraiser" loading="lazy">
                </div>
                <div class="bleed-text">
                    <span class="event-badge event-badge--charity">❤️ Charity Fundraiser</span>
                    <span class="eyebrow">1Voice Foundation</span>
                    <h3>Kardboard for Kids</h3>
                    <p>From time to time we do fundraisers for charity, such as the Kardboard for Kids event that sends games to the 1Voice Foundation to share with kids who have cancer.</p>
                    <p style="font-weight: 600; color: #1e293b; margin-top: 8px;">Next fundraiser: to be determined.</p>
                </div>
            </div>
        </section>

        <footer class="marketing-footer" style="text-align: center; margin-top: 40px; padding: 24px 0 16px; border-top: 1px solid rgba(203, 213, 225, 0.6); color: #64748b; font-size: 0.88rem;">
            <p>&copy; <?php echo date('Y'); ?> Tampa Gaming Guild. All rights reserved. &bull; Non-Profit Board Gaming &amp; RPG Club</p>
        </footer>
    </div>
</div>
