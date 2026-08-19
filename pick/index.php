<?php

declare(strict_types=1);

/**
 * The Pickliste PWA's entry point — a genuinely separate URL path
 * (studsphere.grell.network/pick/) from the main app's single ?page=/
 * ?action= index.php, by deliberate choice: this app has no rewrite/
 * .htaccess layer anywhere (confirmed during planning — only
 * storage/.htaccess exists, and it just denies access), so a plain second
 * directory with its own index.php is the natural fit, and a service
 * worker's scope defaults to its own script's directory anyway, so /pick/'s
 * own sw.js needs to live under /pick/ regardless.
 *
 * Shares the main app's DB/session/auth by requiring the same underlying
 * src/*.php files and running the same session bootstrap (DB-backed
 * sessions specifically exist to survive shared-hosting session GC, so nothing
 * about that needs to change for a second entry point) — see index.php's own
 * session_set_cookie_params() call, which now sets an explicit path => '/'
 * for exactly this reason. Not calling requireLogin() (src/auth.php)
 * directly: that redirects to $_SERVER['PHP_SELF'], which for this file
 * would just reload /pick/index.php in an infinite loop, since there is no
 * login form here — unauthenticated visitors go to the main app's login
 * page instead, with a redirect back to /pick/ built in below.
 */

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/session_handler.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/i18n.php';
require_once __DIR__ . '/../src/icons.php';
require_once __DIR__ . '/../src/storage.php';
require_once __DIR__ . '/../src/sets.php';
require_once __DIR__ . '/../src/minifigs.php';
require_once __DIR__ . '/../src/parts.php';
require_once __DIR__ . '/../src/part_images.php';
require_once __DIR__ . '/../src/ldraw.php';
require_once __DIR__ . '/../src/pick_lists.php';
require_once __DIR__ . '/../src/owned_sets.php';
require_once __DIR__ . '/../src/updater.php';
require_once __DIR__ . '/../src/pick_pages.php';

$configPath = __DIR__ . '/../src/config.php';
if (!is_file($configPath)) {
    header('Location: ../setup.php');
    exit;
}
$config = require $configPath;
if (empty($config['db']['dbname']) || empty($config['db']['user']) || !canConnectToServer()) {
    header('Location: ../setup.php');
    exit;
}

const PICK_LOGIN_SESSION_LIFETIME_SECONDS = 60 * 60 * 24 * 365;
session_set_cookie_params(['lifetime' => PICK_LOGIN_SESSION_LIFETIME_SECONDS, 'path' => '/']);
registerDatabaseSessionHandler();
session_start();

$currentUser = getCurrentUser();
if ($currentUser === null) {
    // The main app's login form captures its OWN request's REQUEST_URI as
    // the post-login redirect target (index.php:459-460) — there's no query
    // param it reads instead, so a visitor lands on the main dashboard after
    // logging in here, not back in /pick/. A one-time extra tap, not a bug;
    // revisiting /pick/ afterward works normally since the session is shared.
    header('Location: ../index.php');
    exit;
}
$pdo = getPDO();

require_once __DIR__ . '/../src/routes/pick_actions.php';

$screen = $_GET['screen'] ?? 'list';
$content = '';
// Set whenever a specific pick list is currently open (screen=pick or
// screen=putaway) — the hamburger menu (below) uses this to offer a direct
// "Zurücklegen" shortcut for THIS list without requiring every item to be
// picked first, so a session can be stopped/consolidated early rather than
// only at full completion.
$currentPickListId = null;
// Mirrors each screen's own in-flow <h1> — echoed server-side into the nav
// bar's small collapsed title (see .pick-navbar-title, pick/style.css)
// instead of reading it via JS, since PHP already knows it here and this
// app has no client-side routing to keep the two in sync otherwise.
$screenTitle = t('pick_app_title');
switch ($screen) {
    case 'create':
        $sourceType = (string) ($_GET['source_type'] ?? 'set');
        $query = trim((string) ($_GET['q'] ?? ''));
        $ownedSetIdRaw = trim((string) ($_GET['owned_set_id'] ?? ''));
        $content = renderPickListCreate($pdo, $sourceType, $query, $ownedSetIdRaw !== '' ? (int) $ownedSetIdRaw : null);
        $screenTitle = t('pick_create_heading');
        break;
    case 'putaway':
        $pickList = getPickList($pdo, (int) ($_GET['id'] ?? 0));
        if ($pickList === null || (int) $pickList['user_id'] !== (int) $currentUser['id']) {
            header('Location: ?screen=list');
            exit;
        }
        $currentPickListId = (int) $pickList['id'];
        $content = renderPickListPutAway($pdo, $pickList);
        $screenTitle = t('pick_putaway_heading');
        break;
    case 'pick':
        $pickList = getPickList($pdo, (int) ($_GET['id'] ?? 0));
        if ($pickList === null || (int) $pickList['user_id'] !== (int) $currentUser['id']) {
            header('Location: ?screen=list');
            exit;
        }
        $currentPickListId = (int) $pickList['id'];
        $content = renderPickListActive($pdo, $pickList, (int) $currentUser['id']);
        $screenTitle = $pickList['name'] !== '' ? $pickList['name'] : t('pick_app_title');
        break;
    case 'list':
    default:
        $content = renderPickListOverview($pdo, (int) $currentUser['id']);
        $screenTitle = t('pick_overview_heading');
        break;
}

$swVersion = htmlspecialchars(getCurrentVersion(), ENT_QUOTES);
echo '<!DOCTYPE html><html lang="' . htmlspecialchars(getLocale()) . '"><head><meta charset="UTF-8">';
echo '<meta name="viewport" content="width=device-width,initial-scale=1.0,viewport-fit=cover">';
echo '<title>' . htmlspecialchars(t('pick_app_title')) . '</title>';
echo '<link rel="icon" type="image/svg+xml" href="../favicon.svg">';
echo '<link rel="manifest" href="manifest.json">';
echo '<link rel="apple-touch-icon" href="../apple-touch-icon.png">';
echo '<meta name="apple-mobile-web-app-capable" content="yes">';
echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">';
echo '<meta name="theme-color" content="#2563eb">';
echo '<script>if ("serviceWorker" in navigator) { window.addEventListener("load", function () { navigator.serviceWorker.register("sw.js?v=' . $swVersion . '"); }); }</script>';
echo '<link rel="stylesheet" href="style.css?v=' . $swVersion . '">';
echo '</head><body>';

// Cold-launch splash only — a full-screen navigation inside /pick/ (every
// link here is a plain server round-trip, see this file's own doc comment)
// would otherwise replay it on every single tap, which reads as broken
// rather than native. The inline script right after the div runs
// synchronously before anything else paints, so a repeat visit within the
// same tab session never even flashes it; sessionStorage (not localStorage)
// deliberately re-arms it on the next genuinely fresh launch.
echo '<div class="pick-splash" id="pick-splash"><span class="pick-splash-mark">' . file_get_contents(__DIR__ . '/../logo.svg') . '</span></div>';
echo '<script>(function(){var s=document.getElementById("pick-splash");if(sessionStorage.getItem("pickSplashShown")){s.style.display="none";}else{sessionStorage.setItem("pickSplashShown","1");}})();</script>';

// Same brand mark as the main app's header (renderApp()/render(),
// index.php) — the sole persistent identity anchor across every screen. The
// large in-flow <h1> each screen renders IS the primary title (iOS
// large-title pattern); this bar starts transparent/title-less and only
// picks up a blurred background + the small centered title (echoed
// server-side above as $screenTitle) once you scroll the large title out of
// view, via the scroll listener below. The trailing "…" button is the only
// persistent navigation /pick/ has (every screen otherwise only links
// forward) — needed specifically so a pick session can be paused/switched
// mid-way: "Zurücklegen" reaches the put-away flow for whatever's ALREADY
// been picked without requiring the rest of the list to be finished first
// (getPutAwaySuggestions()/putAwayItem() already support a partial list,
// this just exposes that entry point directly instead of only after full
// completion), and "Übersicht" is how you switch to a different pick list.
echo '<header class="pick-navbar" id="pick-navbar">';
echo '<span class="pick-navbar-leading">' . file_get_contents(__DIR__ . '/../logo.svg') . '</span>';
echo '<span class="pick-navbar-title" id="pick-navbar-title">' . htmlspecialchars($screenTitle) . '</span>';
echo '<button type="button" class="pick-navbar-menu-btn" id="pick-menu-btn" aria-label="' . htmlspecialchars(t('pick_menu_label')) . '"><svg viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg></button>';
echo '</header>';
echo '<div class="pick-menu-panel" id="pick-menu-panel" hidden>';
echo '<a href="?screen=list">' . htmlspecialchars(t('pick_menu_overview')) . '</a>';
if ($currentPickListId !== null) {
    echo '<a href="?screen=putaway&id=' . $currentPickListId . '">' . htmlspecialchars(t('pick_menu_putaway')) . '</a>';
}
echo '</div>';

$pullToRefreshEnabled = json_encode($screen === 'list');
echo '<div class="pick-refresh-indicator" id="pick-refresh-indicator"><span class="pick-refresh-spinner"></span></div>';
echo '<main id="pick-main">';
echo $content;
echo '</main>';
echo <<<SCRIPT
<script>
(function(){
  var btn = document.getElementById('pick-menu-btn');
  var panel = document.getElementById('pick-menu-panel');
  if (btn && panel) {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      panel.hidden = !panel.hidden;
    });
    document.addEventListener('click', function(e) {
      if (!panel.hidden && !panel.contains(e.target) && e.target !== btn) {
        panel.hidden = true;
      }
    });
  }

  // The nav bar's small title (echoed server-side as $screenTitle) is
  // always visible now — no more scroll-triggered collapse, per explicit
  // feedback that the compact bar alone reads better and saves a lot of
  // vertical space on a phone screen. Auto-shrink still applies to IT
  // instead of the (now hidden) large <h1>: pick list names/set labels vary
  // a lot in length and the bar has no layout budget to reserve for a worst
  // case. Mirrors iOS's own "adjustsFontSizeToFitWidth" nav-bar title
  // behavior: reset to the CSS baseline, then step the font-size down in
  // whole pixels until scrollWidth (the text's real width) fits within
  // clientWidth (the available box), down to a hard floor where the CSS
  // ellipsis (see .pick-navbar-title, pick/style.css) takes over as a last
  // resort.
  var navTitle = document.getElementById('pick-navbar-title');
  if (navTitle) {
    var MIN_TITLE_PX = 13;
    var fitTitle = function() {
      navTitle.style.fontSize = '';
      var size = parseFloat(window.getComputedStyle(navTitle).fontSize);
      while (navTitle.scrollWidth > navTitle.clientWidth && size > MIN_TITLE_PX) {
        size -= 1;
        navTitle.style.fontSize = size + 'px';
      }
    };
    fitTitle();
    window.addEventListener('resize', fitTitle);
  }

  // Pull-to-refresh, overview screen only (pickPullToRefreshEnabled) — a
  // plain window.location.reload() rather than a fetch-and-patch refresh,
  // matching every other action in this app (see src/pick_pages.php's own
  // doc comment: "a plain, reliable server render... no client-side DOM
  // patching").
  if ($pullToRefreshEnabled) {
    var indicator = document.getElementById('pick-refresh-indicator');
    var startY = null;
    var pulling = false;
    var triggered = false;
    var THRESHOLD = 64;
    document.addEventListener('touchstart', function(e) {
      if (window.scrollY <= 0) {
        startY = e.touches[0].clientY;
        pulling = true;
        triggered = false;
      } else {
        startY = null;
        pulling = false;
      }
    }, { passive: true });
    document.addEventListener('touchmove', function(e) {
      if (!pulling || startY === null || triggered) { return; }
      var dy = e.touches[0].clientY - startY;
      if (dy > 0 && window.scrollY <= 0) {
        var height = Math.min(dy * 0.5, 56);
        indicator.style.height = height + 'px';
      }
    }, { passive: true });
    document.addEventListener('touchend', function(e) {
      if (!pulling) { return; }
      pulling = false;
      var height = parseFloat(indicator.style.height) || 0;
      if (height >= THRESHOLD * 0.5 && !triggered) {
        triggered = true;
        indicator.style.height = '44px';
        indicator.classList.add('pick-refresh-loading');
        window.location.reload();
      } else {
        indicator.style.height = '0px';
      }
    });
  }
})();
</script>
SCRIPT;
echo '</body></html>';
