<?php
declare(strict_types=1);

require_once __DIR__ . '/api/bootstrap.php';
require_once __DIR__ . '/api/src/Database.php';
require_once __DIR__ . '/api/src/AdminPage.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; script-src 'self'; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

try {
    $owner = AdminPage::authorize(Database::connect(), true);
} catch (AdminPageAccessException $exception) {
    if ($exception->httpStatus === 401) {
        header('Location: /account.html?auth=required', true, 302);
        exit;
    }

    http_response_code($exception->httpStatus);
    header('Content-Type: text/html; charset=utf-8');
    $message = $exception->httpStatus === 403
        ? 'This control room is restricted to the separately provisioned site owner.'
        : 'Secure HTTPS access is required.';
    ?>
    <!doctype html>
    <html lang="en">
      <head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><title>Access denied — NEEN</title></head>
      <body><main><h1>Access denied</h1><p><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><a href="/events.html">Return to NEEN</a></main></body>
    </html>
    <?php
    exit;
} catch (Throwable $exception) {
    error_log('NEEN owner dashboard unavailable: ' . get_class($exception));
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="en">
      <head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><title>Service unavailable — NEEN</title></head>
      <body><main><h1>Owner dashboard is unavailable</h1><p>The secure administration service could not be reached.</p><a href="/events.html">Return to NEEN</a></main></body>
    </html>
    <?php
    exit;
}

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en" dir="ltr">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="theme-color" content="#FFF2F7" />
    <meta name="robots" content="noindex, nofollow" />
    <title data-i18n="documentTitle">Owner Dashboard — NEEN</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@400;500;600;700;800&family=Noto+Sans+Arabic:wght@400;500;600;700&family=Oxanium:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="styles.css" />
    <link rel="stylesheet" href="events-admin.css" />
    <link rel="stylesheet" href="owner-dashboard.css" />
    <script src="owner-dashboard.js" defer></script>
  </head>
  <body class="admin-body owner-body">
    <div class="admin-shell owner-shell" id="owner-app" data-current-user="<?= (int) $owner['user_id'] ?>">
      <aside class="admin-sidebar owner-sidebar" aria-label="Owner navigation" data-i18n-aria-label="ownerNavLabel">
        <a class="admin-brand" href="index.html" aria-label="NEEN Home" data-i18n-aria-label="homeLabel">
          <span class="admin-brand-wordmark">NEEN<span>.</span></span>
          <span class="admin-brand-label" data-i18n="ownerControlRoom">OWNER CONTROL ROOM</span>
        </a>
        <nav class="admin-nav" aria-label="Dashboard sections" data-i18n-aria-label="sectionsLabel">
          <button type="button" class="admin-nav-item is-active" data-section="overview" aria-current="page"><span class="admin-nav-index">01</span><span data-i18n="dailyOperations">Daily Operations</span></button>
          <button type="button" class="admin-nav-item" data-section="users"><span class="admin-nav-index">02</span><span data-i18n="playersWallet">Players &amp; Wallet</span></button>
          <button type="button" class="admin-nav-item" data-section="wallet"><span class="admin-nav-index">00</span><span data-i18n="allSections">All Sections</span></button>
          <button type="button" class="admin-nav-item" data-section="modules"><span class="admin-nav-index">03</span><span data-i18n="gamesRewardsNav">Games &amp; Rewards</span></button>
          <button type="button" class="admin-nav-item" data-section="news"><span class="admin-nav-index">04</span><span data-i18n="newsTickerNav">News Ticker</span></button>
          <button type="button" class="admin-nav-item" data-section="activity"><span class="admin-nav-index">05</span><span data-i18n="siteAudit">Site Settings &amp; Audit Log</span></button>
        </nav>
        <div class="admin-sidebar-footer"><span class="admin-live-dot" aria-hidden="true"></span><span data-i18n="ownerVerified">OWNER ROLE SERVER-VERIFIED</span></div>
      </aside>

      <main class="admin-main">
        <header class="admin-topbar">
          <div class="admin-topbar-title">
            <p class="admin-eyebrow" data-i18n="ownerOperations">NEEN / OWNER COMMAND CENTER</p>
            <h1 data-i18n="ownerDashboard">NEEN Owner Command Center</h1>
            <span class="owner-live-status"><span class="admin-live-dot" aria-hidden="true"></span><span data-i18n="ownerVerified">OWNER ROLE SERVER-VERIFIED</span></span>
          </div>
          <div class="owner-header-actions">
            <div class="owner-command-links">
              <a class="owner-command-link" href="events-admin.php?section=diamond-orders" data-i18n="eventsOrdersAdmin">Events &amp; Diamond Orders Admin</a>
              <a class="owner-command-link is-secondary" href="index.html" data-i18n="backToSite">Back to Live Site</a>
            </div>
            <button class="owner-language-toggle" type="button" data-language-toggle data-i18n-aria-label="switchLanguage">عربي</button>
            <div class="admin-identity">
              <span class="admin-avatar" aria-hidden="true"><?php
                  $initial = function_exists('mb_substr') && function_exists('mb_strtoupper')
                      ? mb_strtoupper(mb_substr($owner['username'], 0, 1, 'UTF-8'), 'UTF-8')
                      : strtoupper(substr($owner['username'], 0, 1));
                  echo $escape($initial);
              ?></span>
              <span class="admin-identity-copy"><strong dir="ltr"><?= $escape($owner['username']) ?></strong><small data-i18n="ownerRole">Owner</small></span>
              <button class="admin-logout" type="button" data-logout data-i18n="signOut">Sign out</button>
            </div>
          </div>
        </header>

        <div class="admin-content owner-content">
          <section class="admin-section is-active" data-panel="overview" aria-labelledby="overview-title">
            <div class="admin-section-heading"><div><p class="admin-eyebrow"><span data-i18n="liveDatabase">LIVE DATABASE</span> / 01</p><h2 id="overview-title" data-i18n="overview">Daily Operations</h2></div><p data-i18n="overviewDesc">A live snapshot of users, open diamond orders, Coins in circulation, and active games.</p></div>
            <div class="owner-stat-grid" data-owner-stats aria-live="polite"><p class="admin-state" data-i18n="loadingStats">Loading live statistics…</p></div>
            <div class="owner-quick-action-grid">
              <article class="owner-quick-action-card" data-owner-order-queue>
                <div class="owner-quick-action-heading"><p class="admin-eyebrow" data-i18n="diamondQueue">DIAMOND ORDER QUEUE</p><strong data-owner-pending-count aria-live="polite">—</strong></div>
                <h3 data-i18n="reviewDiamondOrders">Review diamond orders</h3>
                <p data-i18n="diamondQueueDesc">Check JOD packages, Free Fire Player IDs, and UWallet / CliQ transfer references before approving or fulfilling.</p>
                <a class="owner-quick-link" href="events-admin.php?section=diamond-orders" data-i18n="openDiamondQueue">Open order queue ↗</a>
              </article>
              <article class="owner-quick-action-card owner-reward-shortcut">
                <div class="owner-quick-action-heading"><p class="admin-eyebrow" data-i18n="homepageReward">HOMEPAGE REWARD</p><span aria-hidden="true">✦</span></div>
                <h3 data-i18n="rewardQuickConfig">Configure daily reward</h3>
                <p data-i18n="rewardQuickConfigDesc">Update the server-backed claim amount and eligibility window without searching the admin menus.</p>
                <a class="owner-quick-link" href="events-admin.php?section=homepage-rewards" data-i18n="configureReward">Configure homepage reward ↗</a>
              </article>
            </div>
            <div class="owner-chart-grid">
              <article class="admin-panel"><div class="admin-panel-heading"><div><p class="admin-eyebrow" data-i18n="thirtyDays">30 DAYS</p><h3 data-i18n="newAccounts">New accounts</h3></div></div><div class="owner-chart" data-chart="users"></div></article>
              <article class="admin-panel"><div class="admin-panel-heading"><div><p class="admin-eyebrow" data-i18n="thirtyDays">30 DAYS</p><h3 data-i18n="coinActivity">Coins activity</h3></div></div><div class="owner-chart" data-chart="wallet_transactions"></div></article>
              <article class="admin-panel"><div class="admin-panel-heading"><div><p class="admin-eyebrow" data-i18n="thirtyDays">30 DAYS</p><h3 data-i18n="rechargeOrders">Recharge orders</h3></div></div><div class="owner-chart" data-chart="diamond_orders"></div></article>
              <article class="admin-panel"><div class="admin-panel-heading"><div><p class="admin-eyebrow" data-i18n="thirtyDays">30 DAYS</p><h3 data-i18n="eventVotes">Event votes</h3></div></div><div class="owner-chart" data-chart="event_votes"></div></article>
            </div>
            <div class="owner-overview-grid">
              <article class="admin-panel"><div class="admin-panel-heading"><div><p class="admin-eyebrow" data-i18n="latest">LATEST</p><h3 data-i18n="recentUsers">Recent registrations</h3></div><button type="button" class="admin-text-button" data-open-section="users" data-i18n="viewUsers">View users ↗</button></div><div data-recent-users><p class="admin-state" data-i18n="loadingUsers">Loading registrations…</p></div></article>
              <article class="admin-panel"><div class="admin-panel-heading"><div><p class="admin-eyebrow" data-i18n="latest">LATEST</p><h3 data-i18n="recentOrders">Recent diamond orders</h3></div><a class="admin-text-button" href="events-admin.php?section=diamond-orders" data-i18n="openDiamondQueue">Open order queue ↗</a></div><div data-recent-orders><p class="admin-state" data-i18n="loadingOrders">Loading orders…</p></div></article>
              <article class="admin-panel"><div class="admin-panel-heading"><div><p class="admin-eyebrow" data-i18n="latest">LATEST</p><h3 data-i18n="recentActivity">Recent admin activity</h3></div><button type="button" class="admin-text-button" data-open-section="activity" data-i18n="viewActivity">View activity ↗</button></div><div data-recent-activity><p class="admin-state" data-i18n="loadingActivity">Loading audit records…</p></div></article>
            </div>
          </section>

          <section class="admin-section" data-panel="users" aria-labelledby="users-title" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow"><span data-i18n="accountManagement">ACCOUNT MANAGEMENT</span> / 02</p><h2 id="users-title" data-i18n="users">Users</h2></div><p data-i18n="usersDesc">Search, inspect, edit profile details, manage non-owner roles, and suspend accounts. Owner accounts and your own roles are protected.</p></div>
            <div class="admin-panel">
              <form class="admin-order-search" data-owner-user-search>
                <label><span data-i18n="searchUsers">Search username, email, or ID</span><input name="q" maxlength="100" dir="ltr" data-i18n-placeholder="searchPlaceholder" placeholder="Search..." /></label>
                <label><span data-i18n="status">Status</span><select name="status"><option value="" data-i18n="allStatuses">All statuses</option><option value="active" data-i18n="active">Active</option><option value="suspended" data-i18n="suspended">Suspended</option><option value="deleted" data-i18n="deleted">Deleted</option><option value="pending_verification" data-i18n="pendingVerification">Pending verification</option></select></label>
                <label><span data-i18n="sort">Sort</span><select name="sort"><option value="newest" data-i18n="newest">Newest</option><option value="username" data-i18n="username">Username</option><option value="last_login" data-i18n="lastActive">Last active</option><option value="coins" data-i18n="coins">Coins</option></select></label>
                <button class="admin-secondary-button" type="submit" data-i18n="search">Search</button>
                <button class="admin-secondary-button" type="button" data-owner-users-refresh data-i18n="refresh">Refresh</button>
              </form>
              <div data-owner-users aria-live="polite"><p class="admin-state" data-i18n="loadingUsers">Loading user directory…</p></div>
              <div data-owner-pagination class="owner-pagination"></div>
            </div>
            <div class="admin-panel owner-user-detail" data-owner-user-detail aria-live="polite"><p class="admin-state" data-i18n="selectUser">Select a user to review account and wallet details.</p></div>
          </section>

          <section class="admin-section" data-panel="wallet" aria-labelledby="wallet-title" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow"><span data-i18n="immutableLedger">IMMUTABLE COINS LEDGER</span> / 03</p><h2 id="wallet-title" data-i18n="wallet">Wallet &amp; Transactions</h2></div><p data-i18n="walletDesc">Wallet changes use the existing transactional ledger, require a reason, prevent negative balances, and record an idempotency key.</p></div>
            <div class="admin-panel"><div class="admin-panel-heading"><div><p class="admin-eyebrow" data-i18n="selectedAccount">SELECTED ACCOUNT</p><h3 data-i18n="walletAccount">Wallet account</h3></div></div>
              <form class="owner-wallet-search" data-owner-wallet-search>
                <label>
                  <span data-i18n="searchUsers">Search username, email, or ID</span>
                  <input name="q" type="text" maxlength="100" dir="ltr" autocomplete="off" data-i18n-placeholder="searchPlaceholder" placeholder="Search..." />
                </label>
                <button class="admin-secondary-button" type="submit" data-i18n="search">Search</button>
              </form>
              <label class="owner-wallet-picker">
                <span data-i18n="selectWalletAccount">Select account</span>
                <select name="wallet_user" data-owner-wallet-picker>
                  <option value="" data-i18n="chooseWalletAccount">Choose an account</option>
                </select>
              </label>
              <div data-owner-wallet-account><p class="admin-state" data-i18n="selectWalletUser">Search or select an account to view its ledger and make a documented adjustment.</p></div>
            </div>
            <div class="admin-panel"><div class="admin-panel-heading"><div><p class="admin-eyebrow" data-i18n="recentEntries">RECENT ENTRIES</p><h3 data-i18n="ledgerHistory">Ledger history</h3></div></div><div data-owner-ledger><p class="admin-state" data-i18n="selectWalletUser">Select an account from Users to view its ledger and make a documented adjustment.</p></div></div>
            <div class="admin-panel"><div class="admin-panel-heading"><div><p class="admin-eyebrow" data-i18n="auditedOperation">AUDITED OPERATION</p><h3 data-i18n="adjustCoins">Adjust Coins</h3></div></div>
              <form class="admin-form-grid owner-adjustment-form" data-owner-adjustment>
                <label><span data-i18n="direction">Direction</span><select name="direction" required><option value="credit" data-i18n="credit">Add</option><option value="debit" data-i18n="debit">Deduct</option></select></label>
                <label><span data-i18n="balanceType">Balance type</span><select name="balance_type" required><option value="earned" data-i18n="earned">Earned</option><option value="purchased" data-i18n="purchased">Purchased</option></select></label>
                <label><span data-i18n="amount">Amount</span><input name="amount" type="number" min="1" max="100000000" step="1" required dir="ltr" /></label>
                <label class="admin-form-wide"><span data-i18n="reason">Reason (required)</span><input name="reason" minlength="3" maxlength="1000" required data-i18n-placeholder="reasonPlaceholder" placeholder="Explain this adjustment" /></label>
                <button class="admin-primary-button" type="submit" data-i18n="submitAdjustment">Submit adjustment</button>
              </form>
              <p class="owner-warning" data-i18n="adjustmentWarning">Every adjustment is permanent in the ledger. Verify the account, balance type, amount, and reason before submitting.</p>
            </div>
          </section>

          <section class="admin-section" data-panel="modules" aria-labelledby="modules-title" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow"><span data-i18n="runtimeSettings">RUNTIME-BACKED SETTINGS</span> / 03</p><h2 id="modules-title" data-i18n="gamesRewardsNav">Games &amp; Rewards</h2></div><p data-i18n="modulesDesc">Tune daily starts and verified-win rewards for every live game. Open the linked console for other service-backed modules.</p></div>
            <article class="admin-panel">
              <div class="admin-panel-heading"><div><p class="admin-eyebrow" data-i18n="runtimeSettings">RUNTIME-BACKED SETTINGS</p><h3 data-i18n="gamesRewards">Games &amp; Rewards</h3></div></div>
              <p class="admin-module-description" data-i18n="gameSettingsNote">The daily free-attempt limit and verified-win Coins reward are connected to the game runtime. Board size, paid attempts, and other hard-coded rules are not editable here.</p>
              <div data-owner-games aria-live="polite"><p class="admin-state" data-i18n="loadingGameSettings">Loading game settings…</p></div>
            </article>
            <div class="owner-module-grid">
              <a class="owner-module-card" href="events-admin.php"><span>01</span><strong data-i18n="existingAdmin">Existing Admin Dashboard</strong><small data-i18n="existingAdminDesc">Games, rewards, attendance, community events, diamond packages and orders, Coins packages and orders.</small><b data-i18n="openConsole">Open console ↗</b></a>
              <button type="button" class="owner-module-card owner-module-action" data-open-section="activity"><span>02</span><strong data-i18n="contentManagement">Site Images &amp; Media</strong><small data-i18n="mediaCardDesc">Upload and replace hero banners, character gallery frames, game artwork, and icons across the live site.</small><b data-i18n="openMediaManager">Open media manager ↗</b></button>
              <article class="owner-module-card owner-module-unavailable"><span>03</span><strong data-i18n="charts">Extended reports</strong><small data-i18n="reportsScope">The Overview charts are real 30-day database series. Additional reporting dimensions are not yet implemented.</small><b data-i18n="notAvailable">Not available</b></article>
            </div>
          </section>

          <section class="admin-section" data-panel="news" aria-labelledby="news-title" hidden>
            <div class="admin-section-heading">
              <div><p class="admin-eyebrow">PUBLIC CONTENT / NEWS</p><h2 id="news-title" data-i18n="newsTickerNav">News Ticker Management</h2></div>
              <p>Review visitor messages, control publication, themes, movement, and live preview.</p>
            </div>

            <div class="owner-news-grid">
              <article class="admin-panel">
                <div class="admin-panel-heading"><div><p class="admin-eyebrow">LIVE SETTINGS</p><h3>Ticker controls</h3></div></div>
                <form class="owner-news-settings" data-owner-news-settings>
                  <label class="owner-news-toggle"><input name="enabled" type="checkbox" /><span>News ticker enabled</span></label>
                  <label class="owner-news-range">
                    <span>Speed <strong data-news-speed-label>55 seconds</strong></span>
                    <input name="speed_seconds" type="range" min="20" max="120" step="5" value="55" />
                    <small>Faster: 20 seconds · Easier reading: 120 seconds</small>
                  </label>
                  <fieldset class="owner-news-themes">
                    <legend>Color system</legend>
                    <div data-owner-news-themes></div>
                  </fieldset>
                  <button class="admin-primary-button" type="submit">Save ticker settings</button>
                </form>
              </article>

              <article class="admin-panel">
                <div class="admin-panel-heading"><div><p class="admin-eyebrow">LIVE PREVIEW</p><h3>Home ticker preview</h3></div></div>
                <div class="owner-news-preview" data-owner-news-preview>
                  <div class="owner-news-preview-label">NEEN NEWS</div>
                  <div class="owner-news-preview-track"><span><strong>SNOOP:</strong> Welcome to NEEN Gaming Arena!</span></div>
                </div>
              </article>
            </div>

            <article class="admin-panel">
              <div class="admin-panel-heading"><div><p class="admin-eyebrow">PUBLISH OWNER MESSAGE</p><h3>Publish Owner Message</h3></div></div>
              <form class="owner-news-publish" data-owner-news-publish>
                <label><span>Sender name</span><input name="sender_name" type="text" minlength="2" maxlength="40" required value="NEEN" /></label>
                <label><span>Message</span><textarea name="message" minlength="3" maxlength="280" rows="2" required></textarea></label>
                <div class="owner-news-publish-preview" data-owner-news-publish-preview><strong>NEEN:</strong><span>Write a message to preview it.</span></div>
                <button class="admin-primary-button" type="submit">Publish directly</button>
              </form>
            </article>

            <article class="admin-panel">
              <div class="admin-panel-heading"><div><p class="admin-eyebrow">MESSAGE QUEUE</p><h3>Visitor and owner messages</h3></div><button type="button" class="admin-secondary-button" data-owner-news-refresh>Refresh</button></div>
              <div class="owner-news-messages" data-owner-news-messages aria-live="polite"><p class="admin-state">Loading news messages…</p></div>
            </article>
          </section>

          <section class="admin-section" data-panel="activity" aria-labelledby="activity-title" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow"><span data-i18n="siteAudit">SITE SETTINGS &amp; AUDIT LOG</span> / 04</p><h2 id="activity-title" data-i18n="siteAudit">Site Settings &amp; Audit Log</h2></div><p data-i18n="activityDesc">Update public site details and review a chronological, read-only record of administrative actions.</p></div>
            <article class="admin-panel">
              <div class="admin-panel-heading"><div><p class="admin-eyebrow" data-i18n="publicConfiguration">PUBLIC SITE CONFIGURATION</p><h3 data-i18n="siteSettings">Site Settings</h3></div></div>
              <form class="admin-form-grid owner-settings-form" data-owner-site-settings>
                <label class="admin-form-wide"><span data-i18n="tiktokProfile">TikTok profile URL</span><input name="tiktok_url" type="url" maxlength="80" required dir="ltr" /></label>
                <label class="admin-form-wide"><span data-i18n="siteDescription">Site description</span><textarea name="site_description" maxlength="300" required></textarea></label>
                <button class="admin-primary-button" type="submit" data-i18n="saveSettings">Save site settings</button>
              </form>
              <p class="admin-state" data-owner-settings-updated></p>
            </article>
            <article class="admin-panel" id="site-media-panel">
              <div class="admin-panel-heading">
                <div>
                  <p class="admin-eyebrow" data-i18n="mediaManagerEyebrow">VISUAL ASSETS &amp; MEDIA</p>
                  <h3 data-i18n="siteMediaTitle">Site Images &amp; Media Manager</h3>
                </div>
                <select class="owner-media-filter" data-owner-media-filter aria-label="Filter media category">
                  <option value="all" data-i18n="mediaCatAll">All Sections (43)</option>
                  <option value="hero" data-i18n="mediaCatHero">Hero &amp; Pages</option>
                  <option value="games" data-i18n="mediaCatGames">Games Artwork</option>
                  <option value="videos" data-i18n="mediaCatVideos">Video Posters</option>
                  <option value="gallery" data-i18n="mediaCatGallery">Character Gallery (18)</option>
                </select>
              </div>
              <p class="admin-module-description" data-i18n="siteMediaDesc">Upload a new image (WebP, PNG, JPG up to 5 MB) to replace any visual across the live site immediately, or reset it to the original artwork.</p>
              <div class="owner-media-grid" data-owner-media-grid aria-live="polite"></div>
            </article>
            <div class="admin-panel">
              <form class="admin-order-search" data-owner-activity-search><label><span data-i18n="searchActivity">Search actor or action</span><input name="q" maxlength="100" dir="ltr" data-i18n-placeholder="searchPlaceholder" placeholder="Search..." /></label><button class="admin-secondary-button" type="submit" data-i18n="search">Search</button><button class="admin-secondary-button" type="button" data-owner-activity-refresh data-i18n="refresh">Refresh</button></form>
              <div data-owner-activity aria-live="polite"><p class="admin-state" data-i18n="loadingActivity">Loading audit records…</p></div>
              <div data-owner-activity-pagination class="owner-pagination"></div>
            </div>
          </section>
        </div>
      </main>
    </div>
    <div class="admin-toast" data-owner-toast role="status" aria-live="polite"></div>
  </body>
</html>
