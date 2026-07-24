<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';

// Table => source URL column. Processed in this order (small tables first).
const IMAGE_DOWNLOAD_TABLES = [
    'sets' => 'image_url',
    'minifigs' => 'image_url',
    'inventory_parts' => 'img_url',
];

// Bounded work per tick, same reasoning as the Rebrickable CSV import: a single
// HTTP request must stay short enough for shared-hosting timeouts we can't raise.
const IMAGE_DOWNLOAD_TIME_BUDGET_SECONDS = 4.0;
const IMAGE_DOWNLOAD_BATCH_SIZE = 200;

// How many image downloads to run in flight at once per tick (via curl_multi).
// Images are small and dominated by round-trip/connection time, not transfer
// time, so a handful of concurrent requests speeds things up meaningfully
// without looking like a scraping flood to Rebrickable's CDN.
const IMAGE_DOWNLOAD_CONCURRENCY = 3;

/**
 * Where downloaded images live. Deliberately under the webroot (public/), not
 * storage/ — images need to be reachable by the browser, unlike the Rebrickable
 * CSV cache which is deliberately blocked from direct web access.
 *
 * Files are sharded into 256 two-hex-char subdirectories per table (keyed by a
 * hash of the filename), not stored flat. A table like inventory_parts ends up
 * with tens of thousands of unique images after dedup — dumping all of them into
 * one directory gets slow (and on some shared hosts, hits explicit per-directory
 * file-count limits) for directory listings, backups, FTP clients, and even our
 * own is_file() dedup check. 256 shards keeps each one to a few hundred files.
 */
function getImageShard(string $filename): string
{
    return substr(md5($filename), 0, 2);
}

function getImageStorageDir(string $table, string $shard): string
{
    $dir = dirname(__DIR__) . '/public/images/' . $table . '/' . $shard;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Konnte Bildverzeichnis nicht erstellen: ' . $dir);
    }
    return $dir;
}

function getImageRelativePath(string $table, string $shard, string $filename): string
{
    return 'public/images/' . $table . '/' . $shard . '/' . $filename;
}

/**
 * Derives a stable local filename from the source URL. Rebrickable's photo URLs
 * already embed a unique-ish filename (including a hash for inventory part photos),
 * so reusing it doubles as cross-row deduplication: many inventory_parts rows share
 * the same part+color photo URL, and once one row has downloaded it, every other row
 * referencing that same URL finds the file already on disk and just links to it.
 */
function sanitizeImageFilename(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $name = basename($path);
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? '';
    if ($name === '' || strpos($name, '.') === false) {
        $name = md5($url) . '.jpg';
    }
    return $name;
}

function downloadImageFile(string $url, string $targetPath): bool
{
    $context = stream_context_create([
        'http' => ['timeout' => 20, 'ignore_errors' => true],
    ]);
    $data = @file_get_contents($url, false, $context);
    if ($data !== false && $data !== '') {
        return file_put_contents($targetPath, $data) !== false;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $result = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($result !== false && $result !== '' && $status < 400) {
            return file_put_contents($targetPath, $result) !== false;
        }
    }

    return false;
}

/**
 * Downloads several images at once using curl_multi (concurrent, non-blocking
 * requests within a single PHP process — no threads/subprocesses involved).
 * Falls back to sequential downloadImageFile() calls if curl isn't available.
 *
 * @param array<int|string, array{url: string, path: string}> $jobs
 * @return array<int|string, bool> success per job, same keys as $jobs
 */
function downloadImageFilesConcurrently(array $jobs): array
{
    $results = [];
    if (empty($jobs)) {
        return $results;
    }

    if (!function_exists('curl_multi_init')) {
        foreach ($jobs as $key => $job) {
            $results[$key] = downloadImageFile($job['url'], $job['path']);
        }
        return $results;
    }

    $multiHandle = curl_multi_init();
    $curlHandles = [];
    $fileHandles = [];

    foreach ($jobs as $key => $job) {
        $fh = fopen($job['path'], 'wb');
        if ($fh === false) {
            $results[$key] = false;
            continue;
        }
        $ch = curl_init($job['url']);
        curl_setopt($ch, CURLOPT_FILE, $fh);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_multi_add_handle($multiHandle, $ch);
        $curlHandles[$key] = $ch;
        $fileHandles[$key] = $fh;
    }

    $running = null;
    do {
        $status = curl_multi_exec($multiHandle, $running);
    } while ($status === CURLM_CALL_MULTI_PERFORM);

    while ($running > 0 && $status === CURLM_OK) {
        if (curl_multi_select($multiHandle) === -1) {
            usleep(100000);
        }
        do {
            $status = curl_multi_exec($multiHandle, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
    }

    foreach ($curlHandles as $key => $ch) {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
        fclose($fileHandles[$key]);

        $ok = $error === '' && $httpCode > 0 && $httpCode < 400;
        if (!$ok) {
            @unlink($jobs[$key]['path']);
        }
        $results[$key] = $ok;
    }

    curl_multi_close($multiHandle);
    return $results;
}

/**
 * Builds the initial state for a chunked image download run. Computes a total-rows
 * estimate per table up front (one COUNT query each, cheap relative to the download
 * work itself) purely for progress-percentage display.
 */
function initImageDownloadState(bool $forceRefresh): array
{
    $pdo = getPDO();
    $totals = [];
    foreach (IMAGE_DOWNLOAD_TABLES as $table => $urlColumn) {
        $sql = "SELECT COUNT(*) FROM `$table` WHERE `$urlColumn` IS NOT NULL AND `$urlColumn` != ''";
        if (!$forceRefresh) {
            $sql .= ' AND local_image_path IS NULL';
        }
        $totals[$table] = (int) $pdo->query($sql)->fetchColumn();
    }

    return [
        'tables' => array_keys(IMAGE_DOWNLOAD_TABLES),
        'currentIndex' => 0,
        'forceRefresh' => $forceRefresh,
        'lastId' => array_fill_keys(array_keys(IMAGE_DOWNLOAD_TABLES), 0),
        'totals' => $totals,
        'stats' => array_fill_keys(array_keys(IMAGE_DOWNLOAD_TABLES), [
            'processed' => 0, 'downloaded' => 0, 'skipped' => 0, 'errors' => 0,
        ]),
    ];
}

/**
 * Performs one bounded batch of image downloads (time-budgeted, not just row-count
 * bounded, so throughput adapts to the host's actual network speed) and mutates
 * $state in place. Safe to call repeatedly from separate HTTP requests.
 * @return array{done: bool, table: ?string}
 */
function stepImageDownload(array &$state): array
{
    $tables = $state['tables'];
    $index = $state['currentIndex'];

    if ($index >= count($tables)) {
        return ['done' => true, 'table' => null];
    }

    $table = $tables[$index];
    $urlColumn = IMAGE_DOWNLOAD_TABLES[$table];
    $forceRefresh = $state['forceRefresh'];
    $lastId = $state['lastId'][$table] ?? 0;

    $pdo = getPDO();
    $sql = "SELECT id, `$urlColumn` AS url_value FROM `$table` WHERE id > :lastId AND `$urlColumn` IS NOT NULL AND `$urlColumn` != ''";
    if (!$forceRefresh) {
        $sql .= ' AND local_image_path IS NULL';
    }
    $sql .= ' ORDER BY id ASC LIMIT ' . IMAGE_DOWNLOAD_BATCH_SIZE;

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lastId', $lastId, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        $state['currentIndex']++;
        return ['done' => $state['currentIndex'] >= count($tables), 'table' => $table];
    }

    $updateStmt = $pdo->prepare("UPDATE `$table` SET local_image_path = ? WHERE id = ?");

    $start = microtime(true);
    $lastProcessedId = $lastId;
    $downloaded = 0;
    $skipped = 0;
    $errors = 0;
    $processed = 0;

    // Rows that actually need a network fetch are queued here and flushed in
    // batches of IMAGE_DOWNLOAD_CONCURRENCY via curl_multi; rows already present
    // on disk (the common "skip" case) never need to enter the batch at all.
    $pendingBatch = [];

    $flushBatch = function () use (&$pendingBatch, &$downloaded, &$errors, $updateStmt): void {
        if (empty($pendingBatch)) {
            return;
        }
        $results = downloadImageFilesConcurrently($pendingBatch);
        foreach ($results as $rowId => $ok) {
            if ($ok) {
                $updateStmt->execute([$pendingBatch[$rowId]['relative'], $rowId]);
                $downloaded++;
            } else {
                $errors++;
            }
        }
        $pendingBatch = [];
    };

    foreach ($rows as $row) {
        $lastProcessedId = (int) $row['id'];
        $processed++;

        $filename = sanitizeImageFilename((string) $row['url_value']);
        $shard = getImageShard($filename);
        $dir = getImageStorageDir($table, $shard);
        $absolutePath = $dir . '/' . $filename;
        $relativePath = getImageRelativePath($table, $shard, $filename);

        if (!$forceRefresh && is_file($absolutePath)) {
            $updateStmt->execute([$relativePath, $row['id']]);
            $skipped++;
            if ((microtime(true) - $start) >= IMAGE_DOWNLOAD_TIME_BUDGET_SECONDS) {
                break;
            }
            continue;
        }

        $pendingBatch[(int) $row['id']] = [
            'url' => (string) $row['url_value'],
            'path' => $absolutePath,
            'relative' => $relativePath,
        ];

        if (count($pendingBatch) >= IMAGE_DOWNLOAD_CONCURRENCY) {
            $flushBatch();
            if ((microtime(true) - $start) >= IMAGE_DOWNLOAD_TIME_BUDGET_SECONDS) {
                break;
            }
        }
    }
    $flushBatch();

    $state['lastId'][$table] = $lastProcessedId;
    $state['stats'][$table]['processed'] += $processed;
    $state['stats'][$table]['downloaded'] += $downloaded;
    $state['stats'][$table]['skipped'] += $skipped;
    $state['stats'][$table]['errors'] += $errors;

    return ['done' => false, 'table' => $table];
}

function buildImageProgressPayload(array $state, bool $done): array
{
    $tables = [];
    $totalWeight = 0.0;
    $doneWeight = 0.0;
    $hasErrors = false;

    foreach ($state['tables'] as $position => $table) {
        $stats = $state['stats'][$table];
        $total = $state['totals'][$table] ?? 0;

        if ($position < $state['currentIndex']) {
            $fraction = 1.0;
            $stage = 'done';
        } elseif ($position === $state['currentIndex']) {
            $fraction = $total > 0 ? min(1.0, $stats['processed'] / $total) : 1.0;
            $stage = 'running';
        } else {
            $fraction = 0.0;
            $stage = 'pending';
        }

        if ($stats['errors'] > 0) {
            $hasErrors = true;
        }

        $totalWeight += 1.0;
        $doneWeight += $fraction;

        $tables[$table] = [
            'label' => $table,
            'stage' => $stage,
            'total' => $total,
            'processed' => $stats['processed'],
            'downloaded' => $stats['downloaded'],
            'skipped' => $stats['skipped'],
            'errors' => $stats['errors'],
        ];
    }

    $percent = $totalWeight > 0 ? (int) round(($doneWeight / $totalWeight) * 100) : 0;

    return [
        'status' => $done ? 'done' : 'running',
        'percent' => $percent,
        'message' => $done
            ? ($hasErrors ? t('image_download_completed_with_errors') : t('image_download_completed'))
            : t('image_download_running'),
        'tables' => $tables,
    ];
}
