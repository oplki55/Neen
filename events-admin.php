<?php
declare(strict_types=1);

require_once __DIR__ . '/api/bootstrap.php';
require_once __DIR__ . '/api/src/Database.php';
require_once __DIR__ . '/api/src/AdminPage.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; script-src 'self'; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

try {
    $admin = AdminPage::authorize(Database::connect());
} catch (AdminPageAccessException $exception) {
    if ($exception->httpStatus === 401) {
        header('Location: /account.html?auth=required', true, 302);
        exit;
    }

    http_response_code($exception->httpStatus);
    header('Content-Type: text/html; charset=utf-8');
    $message = $exception->httpStatus === 403
        ? 'Your account does not have permission to access the administration dashboard.'
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
    error_log('NEEN admin page unavailable: ' . get_class($exception));
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="en">
      <head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><title>Service unavailable — NEEN</title></head>
      <body><main><h1>Administration is unavailable</h1><p>The secure administration service could not be reached.</p><a href="/events.html">Return to NEEN</a></main></body>
    </html>
    <?php
    exit;
}

$permissions = $admin['permissions'];
$hasPermission = static fn (string $permission): bool => in_array($permission, $permissions, true);
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$sections = [
    'dashboard' => 'Dashboard',
    'users' => 'Users',
    'wallet' => 'Coins &amp; Wallet',
    'orders' => 'Coin Orders',
    'games' => 'Games',
    'attendance' => 'Attendance &amp; Rewards',
    'admins' => 'Admin Users &amp; Permissions',
    'audit' => 'Audit Log',
];
if ($hasPermission('manage_homepage_rewards')) {
    $sections['homepage-rewards'] = 'Homepage Rewards';
}
if ($hasPermission('manage_community_events')) {
    $sections['community-events'] = 'Community Events';
    $sections['event-results'] = 'Event Voting &amp; Results';
}
if ($hasPermission('review_event_suggestions')) {
    $sections['event-suggestions'] = 'Event Suggestions';
}
if ($hasPermission('manage_diamond_packages')) {
    $sections['diamond-packages'] = 'Diamond Packages';
}
if ($hasPermission('manage_diamond_orders')) {
    $sections['diamond-orders'] = 'Diamond Recharge Orders';
}
if ($hasPermission('manage_coin_packages') || $hasPermission('manage_coin_payment_methods')) {
    $sections['coin-config'] = 'Coins setup';
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="theme-color" content="#FFF2F7" />
    <meta name="robots" content="noindex, nofollow" />
    <title>NEEN Admin Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@400;500;600;700;800&family=Noto+Sans+Arabic:wght@400;500;600;700&family=Oxanium:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="styles.css" />
    <link rel="stylesheet" href="events-admin.css" />
    <script src="events-admin.js" defer></script>
  </head>
  <body class="admin-body">
    <div class="admin-shell" id="admin-app" data-permissions="<?= $escape(implode(' ', $permissions)) ?>">
      <aside class="admin-sidebar" aria-label="Administration navigation">
        <a class="admin-brand" href="events.html" aria-label="NEEN Events Hub">
          <span class="admin-brand-wordmark">NEEN<span>.</span></span>
          <span class="admin-brand-label">CONTROL ROOM</span>
        </a>
        <nav class="admin-nav" aria-label="Dashboard sections">
          <?php foreach ($sections as $section => $label): ?>
            <?php
            $requiredPermission = match ($section) {
                'orders' => 'review_coin_orders',
                'audit' => 'view_audit_logs',
                'users', 'admins' => 'view_admin_users',
                'wallet' => 'view_all_wallets',
                'homepage-rewards' => 'manage_homepage_rewards',
                'community-events', 'event-results' => 'manage_community_events',
                'event-suggestions' => 'review_event_suggestions',
                'diamond-packages' => 'manage_diamond_packages',
                'diamond-orders' => 'manage_diamond_orders',
                default => null,
            };
            $available = $requiredPermission === null || $hasPermission($requiredPermission);
            ?>
            <button type="button" class="admin-nav-item<?= $section === 'dashboard' ? ' is-active' : '' ?>" data-section="<?= $escape($section) ?>"<?= $section === 'dashboard' ? ' aria-current="page"' : '' ?><?= !$available ? ' disabled aria-disabled="true"' : '' ?>>
              <span class="admin-nav-index"><?= $escape(str_pad((string) (array_search($section, array_keys($sections), true) + 1), 2, '0', STR_PAD_LEFT)) ?></span>
              <span><?= $label ?></span>
              <?php if (!$available): ?><span class="admin-nav-lock" aria-label="Permission required">LOCKED</span><?php endif; ?>
            </button>
          <?php endforeach; ?>
        </nav>
        <div class="admin-sidebar-footer">
          <span class="admin-live-dot" aria-hidden="true"></span>
          <span>SERVER-VERIFIED SESSION</span>
        </div>
      </aside>

      <main class="admin-main">
        <header class="admin-topbar">
          <div class="admin-topbar-title">
            <p class="admin-eyebrow">NEEN / EVENTS &amp; COMMERCE OPS</p>
            <h1>Events &amp; Commerce Admin</h1>
          </div>
          <div class="admin-topbar-actions">
            <button class="admin-language-toggle" type="button" data-language-toggle aria-label="Switch dashboard language">عربي</button>
            <?php if (in_array('owner', $admin['roles'], true)): ?>
              <a class="admin-owner-link" href="owner-dashboard.php">Owner Command Center ↗</a>
            <?php endif; ?>
            <div class="admin-identity">
              <span class="admin-avatar" aria-hidden="true"><?= $escape(strtoupper(substr($admin['username'], 0, 1))) ?></span>
              <span class="admin-identity-copy"><strong><?= $escape($admin['username']) ?></strong><small><?= $escape(implode(', ', $admin['roles'])) ?></small></span>
              <button class="admin-logout" type="button" data-logout>Sign out</button>
            </div>
          </div>
        </header>

        <div class="admin-content">
          <section class="admin-section is-active" data-panel="dashboard" aria-labelledby="dashboard-title">
            <div class="admin-section-heading">
              <div><p class="admin-eyebrow">OVERVIEW / 01</p><h2 id="dashboard-title">Dashboard</h2></div>
              <p>Live information from permission-checked NEEN services. Unsupported modules are clearly identified.</p>
            </div>
            <div class="admin-stat-grid">
              <article class="admin-stat-card"><span>Pending orders (latest 100)</span><strong data-dashboard-orders>—</strong><small data-dashboard-orders-status><?= $hasPermission('review_coin_orders') ? 'Loading authorized order queue' : 'Requires review_coin_orders permission' ?></small></article>
              <article class="admin-stat-card"><span>Community events</span><strong data-dashboard-events>—</strong><small data-dashboard-events-status><?= $hasPermission('manage_community_events') ? 'Loading event records' : 'Requires manage_community_events permission' ?></small></article>
              <article class="admin-stat-card"><span>Pending event suggestions</span><strong data-dashboard-suggestions>—</strong><small data-dashboard-suggestions-status><?= $hasPermission('review_event_suggestions') ? 'Loading review queue' : 'Requires review_event_suggestions permission' ?></small></article>
              <article class="admin-stat-card"><span>Pending diamond orders</span><strong data-dashboard-diamonds>—</strong><small data-dashboard-diamonds-status><?= $hasPermission('manage_diamond_orders') ? 'Loading recharge queue' : 'Requires manage_diamond_orders permission' ?></small></article>
              <article class="admin-stat-card"><span>Available game catalogs</span><strong data-dashboard-games>—</strong><small data-dashboard-games-status>Loading public game catalogs</small></article>
              <article class="admin-stat-card"><span>Recent admin activity</span><strong data-dashboard-audit>—</strong><small data-dashboard-audit-status><?= $hasPermission('view_audit_logs') ? 'Loading the latest authorized audit entries' : 'Requires view_audit_logs permission' ?></small></article>
            </div>
            <div class="admin-panel admin-activity-panel">
              <div class="admin-panel-heading"><div><p class="admin-eyebrow">LATEST OPERATIONS</p><h3>Recent activity</h3></div><button type="button" class="admin-text-button" data-open-section="audit"<?= $hasPermission('view_audit_logs') ? '' : ' disabled' ?>>Open audit log <span aria-hidden="true">↗</span></button></div>
              <div data-dashboard-activity class="admin-activity-list" aria-live="polite"><p class="admin-state"><?= $hasPermission('view_audit_logs') ? 'Loading activity…' : 'Audit activity is unavailable for this account.' ?></p></div>
            </div>
          </section>

          <section class="admin-section" data-panel="users" aria-labelledby="users-title" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow">ACCOUNTS / 02</p><h2 id="users-title">Users</h2></div><p>Read-only account search. Account state changes are not exposed in this console.</p></div>
            <?php if ($hasPermission('view_admin_users')): ?>
            <div class="admin-panel">
              <form class="admin-order-search" data-admin-user-search><label>Search username or user ID<input name="q" maxlength="100" /></label><button class="admin-secondary-button" type="submit">Search</button><button class="admin-secondary-button" type="button" data-refresh-users>Show latest</button></form>
              <div data-admin-users aria-live="polite"><p class="admin-state">Search for a user to view account status and effective permissions.</p></div>
            </div>
            <?php else: ?>
            <div class="admin-empty-state"><span class="admin-empty-mark" aria-hidden="true">○</span><h3>User directory permission required</h3><p>This account does not have view_admin_users access. No account details are exposed.</p></div>
            <?php endif; ?>
          </section>

          <section class="admin-section" data-panel="wallet" aria-labelledby="wallet-title" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow">FINANCE / 03</p><h2 id="wallet-title">Coins &amp; Wallet</h2></div><p>Read-only ledger reporting; no direct balance editing is available.</p></div>
            <?php if ($hasPermission('view_all_wallets')): ?>
            <div class="admin-panel">
              <form class="admin-order-search" data-wallet-search><label>Search username or user ID<input name="q" maxlength="100" /></label><button class="admin-secondary-button" type="submit">Search</button><button class="admin-secondary-button" type="button" data-refresh-wallets>Show latest</button></form>
              <div data-admin-wallets aria-live="polite"><p class="admin-state">Search for an account to view its wallet balances.</p></div>
              <div data-wallet-transactions aria-live="polite"><p class="admin-state">Select a wallet to view its recent ledger entries.</p></div>
            </div>
            <?php else: ?>
            <div class="admin-empty-state"><span class="admin-empty-mark" aria-hidden="true">◇</span><h3>Wallet reporting permission required</h3><p>This account does not have view_all_wallets access. Direct balance editing remains unavailable.</p></div>
            <?php endif; ?>
          </section>

          <section class="admin-section" data-panel="orders" aria-labelledby="orders-title" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow">PURCHASE REVIEW / 04</p><h2 id="orders-title">Coin Orders</h2></div><p>Approvals use the existing audited order-review service and may credit Purchased Coins. Verify the external payment before approving.</p></div>
            <div class="admin-panel">
              <div class="admin-panel-heading"><div><p class="admin-eyebrow">SERVER QUEUE</p><h3>Awaiting review</h3></div><button type="button" class="admin-secondary-button" data-refresh-orders>Refresh</button></div>
              <div data-order-list aria-live="polite"><p class="admin-state">Select Coin Orders to load the permission-checked queue.</p></div>
            </div>
          </section>

          <section class="admin-section" data-panel="games" aria-labelledby="games-title" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow">NEEN ORIGINALS / 05</p><h2 id="games-title">Games</h2></div><p>Read-only catalogs from the existing game services. This dashboard does not change game rules, attempts, or rewards.</p></div>
            <div class="admin-game-grid" data-game-list aria-live="polite"><p class="admin-state">Loading game catalogs…</p></div>
          </section>

          <section class="admin-section" data-panel="attendance" aria-labelledby="attendance-title" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow">RETENTION / 06</p><h2 id="attendance-title">Attendance &amp; Rewards</h2></div></div>
            <div class="admin-empty-state"><span class="admin-empty-mark" aria-hidden="true">✦</span><h3>Administrative attendance reporting is not connected</h3><p>The available attendance endpoint shows only the current account's own cycle. Daily rewards and game rewards remain server-controlled; this console does not alter them.</p></div>
          </section>

          <?php if ($hasPermission('manage_homepage_rewards')): ?>
          <section class="admin-section" id="homepage-reward-config" data-panel="homepage-rewards" aria-labelledby="homepage-rewards-title" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow">REWARD RULES / 09</p><h2 id="homepage-rewards-title">Homepage Rewards</h2></div><p>Claims are disabled until an authorized administrator configures the reward amount and claim window.</p></div>
            <div class="admin-panel">
              <div data-homepage-reward-config><p class="admin-state">Loading homepage reward settings…</p></div>
              <form class="admin-form-grid" data-homepage-reward-form>
                <label>Earned Coins per claim<input name="reward_amount" type="number" min="1" step="1" required /></label>
                <label>Claim window in seconds<input name="window_seconds" type="number" min="1" step="1" required /></label>
                <label class="admin-check"><input name="is_active" type="checkbox" /> Enable reward claims</label>
                <button class="admin-primary-button" type="submit">Save reward settings</button>
              </form>
            </div>
          </section>
          <?php endif; ?>

          <?php if ($hasPermission('manage_community_events')): ?>
          <section class="admin-section" data-panel="community-events" aria-labelledby="community-events-title" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow">COMMUNITY / EVENTS</p><h2 id="community-events-title">Community Events</h2></div><p>Create, publish, pause, or cancel polls. Voting windows and results are enforced by the API.</p></div>
            <div class="admin-panel">
              <div class="admin-panel-heading"><div><p class="admin-eyebrow">EVENT CATALOG</p><h3>Managed events</h3></div><button type="button" class="admin-secondary-button" data-refresh-events>Refresh</button></div>
              <div data-community-events-list aria-live="polite"><p class="admin-state">Loading events…</p></div>
            </div>
            <div class="admin-panel">
              <div class="admin-panel-heading"><div><p class="admin-eyebrow">NEW EVENT</p><h3>Create a draft poll</h3></div></div>
              <form class="admin-form-grid" data-community-event-form>
                <label>Event title<input name="title" maxlength="150" required /></label>
                <label>Starts at (local time)<input name="starts_at" type="datetime-local" required /></label>
                <label>Ends at (local time)<input name="ends_at" type="datetime-local" required /></label>
                <label class="admin-form-wide">Description<textarea name="description" maxlength="4000" rows="3" required></textarea></label>
                <label class="admin-form-wide">Poll options (one per line)<textarea name="options" maxlength="1500" rows="4" required></textarea></label>
                <button class="admin-primary-button" type="submit">Create draft event</button>
              </form>
            </div>
          </section>

          <section class="admin-section" data-panel="event-results" aria-labelledby="event-results-title" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow">VOTING / RESULTS</p><h2 id="event-results-title">Event Voting &amp; Results</h2></div><p>Voting is controlled by published event dates. Final results are server-calculated and require admin approval.</p></div>
            <div class="admin-panel"><div data-event-results-list aria-live="polite"><p class="admin-state">Loading event results…</p></div></div>
          </section>
          <?php endif; ?>

          <?php if ($hasPermission('review_event_suggestions')): ?>
          <section class="admin-section" data-panel="event-suggestions" aria-labelledby="event-suggestions-title" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow">COMMUNITY INPUT</p><h2 id="event-suggestions-title">Event Suggestions</h2></div><p>Review and record a reason for accepting or rejecting each user suggestion.</p></div>
            <div class="admin-panel"><div data-event-suggestions-list aria-live="polite"><p class="admin-state">Loading pending suggestions…</p></div></div>
          </section>
          <?php endif; ?>

          <?php if ($hasPermission('manage_diamond_packages')): ?>
          <section class="admin-section" data-panel="diamond-packages" aria-labelledby="diamond-packages-title" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow">TOP-UP CATALOG</p><h2 id="diamond-packages-title">Diamond Packages</h2></div><p>No package terms are preconfigured. New packages remain inactive until explicitly enabled.</p></div>
            <div class="admin-panel"><div data-diamond-package-list><p class="admin-state">Loading diamond packages…</p></div></div>
            <div class="admin-panel"><form class="admin-form-grid" data-diamond-package-form>
              <label>Package name<input name="name" maxlength="120" required /></label>
              <label>Diamond quantity<input name="diamond_amount" type="number" min="1" step="1" required /></label>
              <label>Price<input name="price" type="number" min="0.01" step="0.01" required /></label>
              <label>Currency code<input name="currency_code" minlength="3" maxlength="3" required /></label>
              <button class="admin-primary-button" type="submit">Create inactive package</button>
            </form></div>
          </section>
          <?php endif; ?>

          <?php if ($hasPermission('manage_diamond_orders')): ?>
          <section class="admin-section" id="diamond-orders-queue" data-panel="diamond-orders" aria-labelledby="diamond-orders-title" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow">RECHARGE QUEUE</p><h2 id="diamond-orders-title">Diamond Recharge Orders</h2></div><p>Payment and delivery remain manual until approved providers are configured. Every transition records its administrator and note.</p></div>
            <div class="admin-panel">
              <form class="admin-order-search" data-diamond-order-search><label>Search order number or Player ID<input name="q" maxlength="150" /></label><button class="admin-secondary-button" type="submit">Search</button><button class="admin-secondary-button" type="button" data-refresh-diamond-orders>Show all</button></form>
              <div data-diamond-admin-orders aria-live="polite"><p class="admin-state">Loading recharge orders…</p></div>
            </div>
            <div class="admin-panel"><div data-diamond-order-history aria-live="polite"><p class="admin-state">Select an order to view its audit history.</p></div></div>
          </section>
          <?php endif; ?>

          <section class="admin-section" data-panel="admins" aria-labelledby="admins-title" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow">ACCESS CONTROL / 07</p><h2 id="admins-title">Admins &amp; Permissions</h2></div></div>
            <?php if ($hasPermission('view_admin_users')): ?>
            <div class="admin-panel"><div data-admin-accounts aria-live="polite"><p class="admin-state">Loading administrative accounts…</p></div></div>
            <?php else: ?>
            <div class="admin-empty-state"><span class="admin-empty-mark" aria-hidden="true">⌑</span><h3>Admin directory permission required</h3><p>This account does not have view_admin_users access. Role and permission assignment remains restricted to an authorized trusted workflow.</p></div>
            <?php endif; ?>
          </section>

          <section class="admin-section" data-panel="audit" aria-labelledby="audit-title" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow">IMMUTABLE HISTORY / 08</p><h2 id="audit-title">Audit Log</h2></div><p>Read-only latest 50 operations. Access requires the existing view_audit_logs permission.</p></div>
            <div class="admin-panel"><div class="admin-panel-heading"><div><p class="admin-eyebrow">SERVER RECORDS</p><h3>Administrative activity</h3></div><button type="button" class="admin-secondary-button" data-refresh-audit>Refresh</button></div><div data-audit-list aria-live="polite"><p class="admin-state">Select Audit Log to load records.</p></div></div>
          </section>

          <?php if ($hasPermission('manage_coin_packages') || $hasPermission('manage_coin_payment_methods')): ?>
          <section class="admin-section" data-panel="coin-config" aria-labelledby="coin-config-title" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow">CONFIGURATION / 09</p><h2 id="coin-config-title">Coins setup</h2></div><p>Server-backed package and payment settings. New entries remain inactive unless you explicitly enable them.</p></div>
            <?php if ($hasPermission('manage_coin_packages')): ?>
            <div class="admin-panel"><div class="admin-panel-heading"><div><p class="admin-eyebrow">PACKAGE CATALOG</p><h3>Coin packages</h3></div></div><div data-package-list><p class="admin-state">Loading configured packages…</p></div><form class="admin-form-grid" data-package-form><label>Package name<input name="name" maxlength="120" required /></label><label>Coins<input name="coin_amount" type="number" min="1" step="1" required /></label><label>Price<input name="price" type="number" min="0.01" step="0.01" required /></label><label>Currency code<input name="currency_code" minlength="3" maxlength="3" value="EGP" required /></label><label class="admin-check"><input name="is_active" type="checkbox" /> Activate after saving</label><button class="admin-primary-button" type="submit">Create package</button></form></div>
            <?php endif; ?>
            <?php if ($hasPermission('manage_coin_payment_methods')): ?>
            <div class="admin-panel"><div class="admin-panel-heading"><div><p class="admin-eyebrow">PAYMENT CATALOG</p><h3>Payment methods</h3></div></div><div data-method-list><p class="admin-state">Loading configured payment methods…</p></div><form class="admin-form-grid" data-method-form><label>Method code<input name="code" maxlength="60" required /></label><label>Display name<input name="display_name" maxlength="120" required /></label><label class="admin-form-wide">Instructions<textarea name="instructions" maxlength="4000" rows="3"></textarea></label><label class="admin-check"><input name="proof_required" type="checkbox" checked /> Require payment proof</label><label class="admin-check"><input name="is_active" type="checkbox" /> Activate after saving</label><button class="admin-primary-button" type="submit">Create payment method</button></form></div>
            <?php endif; ?>
          </section>
          <?php endif; ?>
        </div>
      </main>
    </div>
    <div class="admin-toast" role="status" aria-live="polite" data-toast></div>
  </body>
</html>
