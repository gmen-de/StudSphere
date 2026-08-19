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
switch ($screen) {
    case 'create':
        $sourceType = (string) ($_GET['source_type'] ?? 'set');
        $query = trim((string) ($_GET['q'] ?? ''));
        $ownedSetIdRaw = trim((string) ($_GET['owned_set_id'] ?? ''));
        $content = renderPickListCreate($pdo, $sourceType, $query, $ownedSetIdRaw !== '' ? (int) $ownedSetIdRaw : null);
        break;
    case 'putaway':
        $pickList = getPickList($pdo, (int) ($_GET['id'] ?? 0));
        if ($pickList === null || (int) $pickList['user_id'] !== (int) $currentUser['id']) {
            header('Location: ?screen=list');
            exit;
        }
        $content = renderPickListPutAway($pdo, $pickList);
        break;
    case 'pick':
        $pickList = getPickList($pdo, (int) ($_GET['id'] ?? 0));
        if ($pickList === null || (int) $pickList['user_id'] !== (int) $currentUser['id']) {
            header('Location: ?screen=list');
            exit;
        }
        $content = renderPickListActive($pdo, $pickList, (int) $currentUser['id']);
        break;
    case 'list':
    default:
        $content = renderPickListOverview($pdo, (int) $currentUser['id']);
        break;
}

$swVersion = htmlspecialchars(getCurrentVersion(), ENT_QUOTES);
echo '<!DOCTYPE html><html lang="' . htmlspecialchars(getLocale()) . '"><head><meta charset="UTF-8">';
echo '<meta name="viewport" content="width=device-width,initial-scale=1.0,viewport-fit=cover">';
echo '<title>' . htmlspecialchars(t('pick_app_title')) . '</title>';
echo '<link rel="icon" type="image/svg+xml" href="../favicon.svg">';
echo '<link rel="manifest" href="manifest.json">';
echo '<link rel="apple-touch-icon" href="../apple-touch-icon.png">';
echo '<meta name="theme-color" content="#2563eb">';
echo '<script>if ("serviceWorker" in navigator) { window.addEventListener("load", function () { navigator.serviceWorker.register("sw.js?v=' . $swVersion . '"); }); }</script>';
echo '<link rel="stylesheet" href="style.css?v=' . $swVersion . '">';
echo '</head><body>';
echo $content;
echo '</body></html>';
