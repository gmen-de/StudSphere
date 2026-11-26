<?php

declare(strict_types=1);

/**
 * The sorting machine's API (see src/sorter_api.php). JSON in, JSON out,
 * no session: every request carries its sorter's key as
 * "Authorization: Bearer ssk_...". Its own directory like pick/, because
 * this app has no rewrite layer.
 *
 *   GET  ?action=hello  -> which key this is, and whether it's set up
 *   POST ?action=setup  {machine_id, sorter_name, stream_id, bins: [...]}
 *   POST ?action=ops    {stream_id, condition, ops: [{seq, type, ...}]}
 *
 * Some hosts drop the Authorization header before PHP sees it (CGI/FPM
 * without the passthrough). X-Api-Key works as a fallback there.
 */

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/settings.php';
require_once __DIR__ . '/../src/storage.php';
require_once __DIR__ . '/../src/sorter_api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sorterApiRespond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$configPath = __DIR__ . '/../src/config.php';
if (!is_file($configPath)) {
    sorterApiRespond(503, ['ok' => false, 'error' => 'StudSphere is not set up yet.']);
}

try {
    require_once __DIR__ . '/../src/migrations.php';
    if (schemaMigrationPending()) {
        sorterApiRespond(503, ['ok' => false, 'error' => 'StudSphere has a database update pending: open it in a browser once.']);
    }

    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($authorization === '' && isset($_SERVER['HTTP_X_API_KEY'])) {
        $authorization = 'Bearer ' . $_SERVER['HTTP_X_API_KEY'];
    }
    $key = authenticateSorterApiKey((string) $authorization);

    $action = (string) ($_GET['action'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($action === 'hello' && $method === 'GET') {
        sorterApiRespond(200, [
            'ok' => true,
            'key_name' => $key['name'],
            'machine_id' => $key['machine_id'],
            'set_up' => $key['root_location_id'] !== null,
            'location' => $key['root_location_id'] !== null && getStorageLocation((int) $key['root_location_id']) !== null
                ? getStorageLocationPath((int) $key['root_location_id'])
                : null,
        ]);
    }
    if ($method !== 'POST' || !in_array($action, ['setup', 'ops'], true)) {
        sorterApiRespond(404, ['ok' => false, 'error' => 'Unknown action.']);
    }

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        sorterApiRespond(400, ['ok' => false, 'error' => 'The body must be a JSON object.']);
    }
    sorterApiRespond(200, $action === 'setup' ? sorterApiSetup($key, $body) : sorterApiApplyOps($key, $body));
} catch (SorterApiError $e) {
    sorterApiRespond($e->status, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    sorterApiRespond(500, ['ok' => false, 'error' => 'StudSphere error: ' . $e->getMessage()]);
}
