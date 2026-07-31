<?php

declare(strict_types=1);

/**
 * The only two actions that legitimately have to work without a session
 * (switching language or logging out obviously can't require being logged
 * in already) — required by index.php right after the "action=login"
 * handler and BEFORE the "not logged in -> show login form" gate.
 * import/update_data/update_rebrickable_settings/update_ldraw_settings
 * used to sit here too and were reachable by an unauthenticated POST as a
 * result; moved behind the gate into src/routes/actions.php, see its doc
 * comment. Page renderers live in src/routes/pages.php.
 */

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'set_locale') {
    setSessionLocale((string) ($_GET['locale'] ?? ''));
    $redirectPage = isset($_GET['page']) ? '?page=' . urlencode((string) $_GET['page']) : '';
    header('Location: ' . $_SERVER['PHP_SELF'] . $redirectPage);
    exit;
}
