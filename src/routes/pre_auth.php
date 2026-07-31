<?php

declare(strict_types=1);

/**
 * Unauthenticated action handlers (import/update-data/settings + logout/
 * locale-switch) — required by index.php right after the "action=login"
 * handler and BEFORE the "not logged in -> show login form" gate, since
 * these six specifically must stay reachable without a session (switching
 * language or logging out obviously has to work while logged out; import/
 * update-data/rebrickable-settings/ldraw-settings predate a real user
 * system and were never moved behind the gate — left exactly as-is here,
 * not something this split changes). Everything else lives in
 * src/routes/actions.php (behind the gate) or src/routes/pages.php.
 */

$importMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    try {
        $type = $_POST['import_type'] ?? '';
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(t('error_import_file_required'));
        }
        $result = importCsv($_FILES['import_file']['tmp_name'], $type);
        $importMessage = t('import_success_rows', ['count' => $result['rows'] ?? 0]);
    } catch (Throwable $e) {
        $importMessage = t('import_failure_message', ['message' => $e->getMessage()]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_data') {
    try {
        $result = downloadAndImportRebrickableData();
        $summaryText = implode(', ', array_map(function ($type, $rows) { return "$type=$rows"; }, array_keys($result['summary']), $result['summary']));
        if (!empty($result['errors'])) {
            $errorsText = implode(', ', array_map(function ($type, $msg) { return "$type: $msg"; }, array_keys($result['errors']), $result['errors']));
            $importMessage = t('update_partial_message', ['summary' => $summaryText, 'errors' => $errorsText]);
        } else {
            $importMessage = t('update_success_message', ['summary' => $summaryText]);
        }
    } catch (Throwable $e) {
        $importMessage = t('update_failure_message', ['message' => $e->getMessage()]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_rebrickable_settings') {
    setAppSetting('rebrickable_download_base_url', trim($_POST['download_base_url'] ?? ''));
    setAppSetting('rebrickable_api_url', trim($_POST['api_url'] ?? ''));
    setAppSetting('rebrickable_api_key', trim($_POST['api_key'] ?? ''));
    $importMessage = t('settings_rebrickable_saved');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_ldraw_settings') {
    setAppSetting('ldraw_rendering_enabled', ($_POST['ldraw_enabled'] ?? '') === '1' ? '1' : '0');
}

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
