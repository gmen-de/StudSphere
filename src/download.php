<?php

declare(strict_types=1);

require_once __DIR__ . '/import.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/rebrickable.php';
require_once __DIR__ . '/i18n.php';

// set_parts.csv used to be here too, but Rebrickable's CDN now 404s on it
// (confirmed by the user, not just this one server) — the set_parts table
// itself was already unused by the app's own read paths even before that
// (see getPartDetail()'s doc comment in src/parts.php: set-appearance
// stats come from inventory_parts, not set_parts), so dropping it from the
// bulk sync costs nothing. Manually uploading a set_parts CSV from
// elsewhere via the "Datei importieren" form still works (action=import,
// import_type=set_parts) — only the automatic bulk download is gone.
const REBRICKABLE_DOWNLOAD_ORDER = [
    'themes',
    'colors',
    'part_categories',
    'parts',
    'part_relationships',
    'elements',
    'sets',
    'minifigs',
    'inventories',
    'inventory_parts',
    'inventory_sets',
    'inventory_minifigs',
];

// Rebrickable's own API has no minifig -> BrickLink ID mapping (confirmed via their
// forum as an admitted omission), so this is generated offline (import a Rebrickable
// list of every fig_num, export it as a BrickLink Wanted List XML, pull the IDs back
// out) and shipped in git as db/seed_bricklink_minifig_ids.csv instead. Runs through
// the exact same file/tick machinery as a real Rebrickable download (see
// stepRebrickableImport()'s special case for this type below), just skipping the
// download stage — so it applies on every fresh install and every "Update jetzt",
// and is a cheap no-op afterwards since the import only touches rows where
// bricklink_id is still NULL (never overwrites a live moykubik.ru lookup or a
// manually-entered ID).
const BRICKLINK_MINIFIG_SEED_TYPE = 'bricklink_minifig_ids';

/**
 * REBRICKABLE_DOWNLOAD_ORDER plus the locally-bundled BrickLink seed, for the two
 * call sites (this file's init + setup.php's step 5) that build a file list to
 * iterate/display — kept as one function so they can't drift apart.
 */
function getRebrickableImportTypes(): array
{
    return array_merge(REBRICKABLE_DOWNLOAD_ORDER, [BRICKLINK_MINIFIG_SEED_TYPE]);
}

function getBricklinkMinifigSeedPath(): string
{
    return dirname(__DIR__) . '/db/seed_bricklink_minifig_ids.csv';
}

// Bounded work per tick, so a single HTTP request never runs long enough to hit
// shared-hosting timeouts (Apache/PHP-FPM) that can't be raised via php.ini.
const REBRICKABLE_DOWNLOAD_CHUNK_SIZE = 1_000_000; // ~1 MB per download tick
const REBRICKABLE_IMPORT_MAX_ROWS_PER_TICK = 5000;
const REBRICKABLE_IMPORT_TIME_BUDGET_SECONDS = 4.0;

/**
 * Storage for in-progress downloads and the import log. Deliberately NOT
 * sys_get_temp_dir(): on some hosts the system temp directory is cleared between
 * requests (different worker/container) or swept by a cleanup cron, which would
 * silently corrupt a resumable download that spans many ticks. A directory inside
 * the app itself is guaranteed to persist for as long as the app is deployed.
 * Protected from direct web access via storage/.htaccess.
 */
function getRebrickableStorageDir(): string
{
    $dir = dirname(__DIR__) . '/storage/rebrickable';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Konnte Speicherverzeichnis nicht erstellen: ' . $dir);
    }
    return $dir;
}

function getRebrickableLogPath(): string
{
    return getRebrickableStorageDir() . '/import.log';
}

function logRebrickableImport(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents(getRebrickableLogPath(), $line, FILE_APPEND | LOCK_EX);
}

/**
 * Base URL for Rebrickable's static CSV downloads. Historically these were discovered
 * by scraping the href links on https://rebrickable.com/downloads/, but that page is
 * now behind a Cloudflare JS challenge that a server-side HTTP request can never pass.
 * The files themselves are still served, unprotected, from Rebrickable's CDN at a
 * stable, predictable path per file type — so we build the URL directly instead.
 */
function getRebrickableDownloadBaseUrl(): string
{
    $url = getAppSetting('rebrickable_download_base_url');
    if (!is_string($url) || $url === '') {
        return 'https://cdn.rebrickable.com/media/downloads/';
    }
    return rtrim($url, '/') . '/';
}

function buildRebrickableDownloadUrl(string $type): string
{
    return getRebrickableDownloadBaseUrl() . $type . '.csv.gz';
}

/**
 * HEAD-probes a remote file for its size and whether it supports HTTP Range requests
 * (needed to download it across multiple bounded ticks).
 * @return array{size: ?int, acceptsRanges: bool}
 */
function probeRemoteFile(string $url): array
{
    $result = ['size' => null, 'acceptsRanges' => false, 'status' => null];

    $context = stream_context_create([
        'http' => [
            'method' => 'HEAD',
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $headers = @get_headers($url, true, $context);
    if (is_array($headers)) {
        if (isset($headers[0]) && is_string($headers[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $headers[0], $m)) {
            $result['status'] = (int) $m[1];
        }
        foreach ($headers as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $flat = is_array($value) ? end($value) : $value;
            if (strcasecmp($key, 'Content-Length') === 0) {
                $result['size'] = (int) $flat;
            }
            if (strcasecmp($key, 'Accept-Ranges') === 0) {
                $result['acceptsRanges'] = stripos((string) $flat, 'bytes') !== false;
            }
        }
        return $result;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $response = curl_exec($ch);
        $result['status'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response !== false) {
            $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            if ($size !== false && $size >= 0) {
                $result['size'] = (int) $size;
            }
            if (preg_match('/Accept-Ranges:\s*bytes/i', (string) $response)) {
                $result['acceptsRanges'] = true;
            }
        }
        curl_close($ch);
    }

    return $result;
}

/**
 * Downloads one bounded byte range of $url and appends it to $targetPath.
 * @return array{bytesRead: int}
 */
function downloadFileRangeChunk(string $url, string $targetPath, int $offset, int $chunkSize): array
{
    $rangeHeader = 'Range: bytes=' . $offset . '-' . ($offset + $chunkSize - 1);

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 30,
            'ignore_errors' => true,
            'header' => $rangeHeader,
        ],
    ]);

    $chunk = @file_get_contents($url, false, $context);
    if ($chunk !== false) {
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        if ($status >= 400) {
            throw new RuntimeException('Download-Chunk fehlgeschlagen (' . $status . '): ' . $url);
        }

        $out = fopen($targetPath, $offset > 0 ? 'ab' : 'wb');
        if ($out === false) {
            throw new RuntimeException('Konnte Zieldatei nicht schreiben: ' . $targetPath);
        }
        fwrite($out, $chunk);
        fclose($out);
        return ['bytesRead' => strlen($chunk)];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RANGE, $offset . '-' . ($offset + $chunkSize - 1));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $data = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($data === false || $status >= 400) {
            throw new RuntimeException('Download-Chunk fehlgeschlagen: ' . $url . ' (' . ($error ?: $status) . ')');
        }

        $out = fopen($targetPath, $offset > 0 ? 'ab' : 'wb');
        if ($out === false) {
            throw new RuntimeException('Konnte Zieldatei nicht schreiben: ' . $targetPath);
        }
        fwrite($out, $data);
        fclose($out);
        return ['bytesRead' => strlen($data)];
    }

    throw new RuntimeException('Konnte Datei-Chunk nicht herunterladen: ' . $url);
}

/**
 * Whole-file streamed download (not resumable across requests). Used as a fallback
 * when the remote server does not support HTTP Range requests.
 */
function downloadFile(string $url, string $targetPath): void
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'ignore_errors' => true,
        ],
    ]);

    $in = @fopen($url, 'rb', false, $context);
    if ($in !== false) {
        $out = fopen($targetPath, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Konnte Zieldatei nicht schreiben: ' . $targetPath);
        }

        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
    } elseif (function_exists('curl_init')) {
        $out = fopen($targetPath, 'wb');
        if ($out === false) {
            throw new RuntimeException('Konnte Zieldatei nicht schreiben: ' . $targetPath);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $out);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $success = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($out);

        if ($success === false) {
            throw new RuntimeException('Konnte Datei nicht herunterladen: ' . $url . ' (' . $error . ')');
        }
    } else {
        throw new RuntimeException('Konnte Datei nicht herunterladen: ' . $url);
    }

    if (!is_file($targetPath) || filesize($targetPath) === 0) {
        throw new RuntimeException('Konnte Datei nicht herunterladen oder die Datei ist leer: ' . $url);
    }
}

function decompressIfNeeded(string $sourcePath, string $targetPath): string
{
    $lower = strtolower($sourcePath);
    if (str_ends_with($lower, '.gz')) {
        $in = gzopen($sourcePath, 'rb');
        if ($in === false) {
            throw new RuntimeException('Konnte gzip-Datei nicht öffnen: ' . $sourcePath);
        }
        $out = fopen($targetPath, 'wb');
        if ($out === false) {
            gzclose($in);
            throw new RuntimeException('Konnte Zieldatei nicht schreiben: ' . $targetPath);
        }
        while (!gzeof($in)) {
            fwrite($out, gzread($in, 8192));
        }
        gzclose($in);
        fclose($out);
        return $targetPath;
    }

    if (str_ends_with($lower, '.zip')) {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Zip-Unterstützung nicht verfügbar. Bitte installieren Sie PHP mit ZipArchive.');
        }
        $zip = new ZipArchive();
        if ($zip->open($sourcePath) !== true) {
            throw new RuntimeException('Konnte ZIP-Datei nicht öffnen: ' . $sourcePath);
        }

        $extracted = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_ends_with(strtolower($name), '.csv')) {
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    $zip->close();
                    throw new RuntimeException('Konnte ZIP-Eintrag nicht lesen: ' . $name);
                }
                $out = fopen($targetPath, 'wb');
                if ($out === false) {
                    fclose($stream);
                    $zip->close();
                    throw new RuntimeException('Konnte Zieldatei nicht schreiben: ' . $targetPath);
                }
                while (!feof($stream)) {
                    fwrite($out, fread($stream, 8192));
                }
                fclose($stream);
                fclose($out);
                $extracted = true;
                break;
            }
        }
        $zip->close();
        if (!$extracted) {
            throw new RuntimeException('Keine CSV-Datei in der ZIP-Datei gefunden: ' . $sourcePath);
        }
        return $targetPath;
    }

    return $sourcePath;
}

/**
 * Builds the initial state for a chunked, resumable Rebrickable import.
 * Does one (fast) network call to list available downloads; no file transfer happens yet.
 */
function initRebrickableImportState(): array
{
    $tmpDir = getRebrickableStorageDir();
    @file_put_contents(getRebrickableLogPath(), '');
    $types = getRebrickableImportTypes();
    logRebrickableImport('Import gestartet, geplante Dateitypen: ' . implode(', ', $types));

    $files = [];
    foreach ($types as $type) {
        $files[$type] = [
            'label' => $type . '.csv',
            'url' => $type === BRICKLINK_MINIFIG_SEED_TYPE ? null : buildRebrickableDownloadUrl($type),
            'stage' => 'pending',
            'message' => null,
            'sourcePath' => $tmpDir . '/' . $type . '.csv.gz',
            'csvPath' => null,
            'acceptsRanges' => null,
            'bytes' => 0,
            'totalBytes' => null,
            'csvHeaders' => null,
            'csvDelimiter' => null,
            'importOffset' => 0,
            'rows' => 0,
        ];
    }

    return [
        'types' => $types,
        'currentIndex' => 0,
        'files' => $files,
    ];
}

/**
 * Performs exactly one bounded unit of work on the current file in $state (a probe,
 * one download chunk, the extraction, or one import batch) and mutates $state in place.
 * Safe to call repeatedly from separate HTTP requests until it reports 'done' => true.
 * @return array{done: bool, type: ?string, stage: ?string}
 */
function stepRebrickableImport(array &$state): array
{
    $types = $state['types'];
    $index = $state['currentIndex'];

    while ($index < count($types) && in_array($state['files'][$types[$index]]['stage'], ['done', 'error'], true)) {
        $index++;
    }
    $state['currentIndex'] = $index;

    if ($index >= count($types)) {
        return ['done' => true, 'type' => null, 'stage' => null];
    }

    $type = $types[$index];
    $file = &$state['files'][$type];
    $startedInStage = $file['stage'];

    try {
        switch ($file['stage']) {
            case 'pending':
                if ($type === BRICKLINK_MINIFIG_SEED_TYPE) {
                    $seedPath = getBricklinkMinifigSeedPath();
                    if (!is_file($seedPath)) {
                        throw new RuntimeException('Seed-Datei nicht gefunden: ' . $seedPath);
                    }
                    $headerInfo = readCsvHeaderInfo($seedPath);
                    $file['csvPath'] = $seedPath;
                    $file['csvHeaders'] = $headerInfo['headers'];
                    $file['csvDelimiter'] = $headerInfo['delimiter'];
                    $file['importOffset'] = $headerInfo['dataOffset'];
                    $file['totalBytes'] = filesize($seedPath) ?: null;
                    $file['bytes'] = $file['totalBytes'] ?? 0;
                    $file['stage'] = 'importing';
                    logRebrickableImport(sprintf('%s: pending -> importing (lokale Seed-Datei, %s)', $type, $seedPath));
                    break;
                }

                $probe = probeRemoteFile($file['url']);
                if ($probe['status'] !== null && $probe['status'] >= 400) {
                    throw new RuntimeException('Datei nicht verfügbar (HTTP ' . $probe['status'] . '): ' . $file['url']);
                }
                $file['totalBytes'] = $probe['size'];
                $file['acceptsRanges'] = $probe['acceptsRanges'];
                $file['stage'] = 'downloading';
                logRebrickableImport(sprintf(
                    '%s: pending -> downloading (url=%s, size=%s, ranges=%s)',
                    $type,
                    $file['url'],
                    $file['totalBytes'] ?? 'unbekannt',
                    $file['acceptsRanges'] ? 'ja' : 'nein'
                ));
                break;

            case 'downloading':
                if ($file['bytes'] > 0) {
                    // Resuming: make sure the partially downloaded file from a
                    // previous tick is still there and matches what we recorded.
                    $actualSize = is_file($file['sourcePath']) ? filesize($file['sourcePath']) : false;
                    if ($actualSize === false || $actualSize < $file['bytes']) {
                        throw new RuntimeException(sprintf(
                            'Zwischengespeicherte Download-Datei fehlt oder ist kleiner als erwartet (%s, erwartet mind. %d Bytes, gefunden: %s). Eventuell wurde sie zwischen zwei Anfragen gelöscht.',
                            $file['sourcePath'],
                            $file['bytes'],
                            $actualSize === false ? 'nicht vorhanden' : $actualSize . ' Bytes'
                        ));
                    }
                }

                if ($file['acceptsRanges']) {
                    $result = downloadFileRangeChunk($file['url'], $file['sourcePath'], $file['bytes'], REBRICKABLE_DOWNLOAD_CHUNK_SIZE);
                    $bytesRead = $result['bytesRead'];

                    if ($bytesRead > REBRICKABLE_DOWNLOAD_CHUNK_SIZE) {
                        // Server ignored the Range header and returned the whole file.
                        $file['bytes'] = $bytesRead;
                        $atEnd = true;
                    } else {
                        $file['bytes'] += $bytesRead;
                        $atEnd = $file['totalBytes'] !== null
                            ? $file['bytes'] >= $file['totalBytes']
                            : $bytesRead < REBRICKABLE_DOWNLOAD_CHUNK_SIZE;
                    }

                    logRebrickableImport(sprintf('%s: downloaded chunk (+%d bytes, total %d%s)', $type, $bytesRead, $file['bytes'], $file['totalBytes'] ? '/' . $file['totalBytes'] : ''));

                    if ($atEnd) {
                        if (!is_file($file['sourcePath']) || filesize($file['sourcePath']) === 0) {
                            throw new RuntimeException('Download ergab eine leere Datei.');
                        }
                        $file['stage'] = 'extracting';
                        logRebrickableImport(sprintf('%s: downloading -> extracting (%d bytes gesamt)', $type, $file['bytes']));
                    }
                } else {
                    downloadFile($file['url'], $file['sourcePath']);
                    $file['bytes'] = filesize($file['sourcePath']) ?: 0;
                    $file['stage'] = 'extracting';
                    logRebrickableImport(sprintf('%s: downloading -> extracting (Server unterstützt keine Range-Requests, %d bytes gesamt)', $type, $file['bytes']));
                }
                break;

            case 'extracting':
                $csvPath = decompressIfNeeded($file['sourcePath'], $file['sourcePath'] . '.csv');
                if ($csvPath !== $file['sourcePath'] && !file_exists($csvPath)) {
                    throw new RuntimeException('Konnte entpackte CSV-Datei nicht erstellen: ' . $csvPath);
                }
                $file['csvPath'] = $csvPath;
                $headerInfo = readCsvHeaderInfo($csvPath);
                $file['csvHeaders'] = $headerInfo['headers'];
                $file['csvDelimiter'] = $headerInfo['delimiter'];
                $file['importOffset'] = $headerInfo['dataOffset'];
                $file['stage'] = 'importing';
                logRebrickableImport(sprintf('%s: extracting -> importing (csvPath=%s, headers=%s)', $type, $csvPath, implode(',', $headerInfo['headers'])));
                break;

            case 'importing':
                $actualSize = is_file($file['csvPath']) ? filesize($file['csvPath']) : false;
                if ($actualSize === false || $actualSize < $file['importOffset']) {
                    throw new RuntimeException(sprintf(
                        'Entpackte CSV-Datei fehlt oder ist kleiner als erwartet (%s, erwartete Position %d, Dateigröße: %s). Eventuell wurde sie zwischen zwei Anfragen gelöscht.',
                        $file['csvPath'],
                        $file['importOffset'],
                        $actualSize === false ? 'nicht vorhanden' : $actualSize . ' Bytes'
                    ));
                }

                $chunk = importCsvChunk(
                    $file['csvPath'],
                    $type,
                    $file['csvHeaders'],
                    $file['csvDelimiter'],
                    $file['importOffset'],
                    REBRICKABLE_IMPORT_MAX_ROWS_PER_TICK,
                    REBRICKABLE_IMPORT_TIME_BUDGET_SECONDS
                );
                $file['rows'] += $chunk['imported'];
                $file['importOffset'] = $chunk['nextOffset'];
                logRebrickableImport(sprintf('%s: importierte %d Zeilen (gesamt %d, offset %d)', $type, $chunk['imported'], $file['rows'], $file['importOffset']));
                if ($chunk['done']) {
                    setAppSetting('last_update_' . $type, date('c'));
                    $file['stage'] = 'done';
                    logRebrickableImport(sprintf('%s: importing -> done (%d Zeilen gesamt)', $type, $file['rows']));
                }
                break;
        }
    } catch (Throwable $e) {
        $file['stage'] = 'error';
        $file['message'] = $e->getMessage();
        logRebrickableImport(sprintf(
            '%s: FEHLER in Stage "%s": [%s] %s (%s:%d)',
            $type,
            $startedInStage,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }

    unset($file);

    $allDone = true;
    $hasSuccess = false;
    foreach ($state['files'] as $f) {
        if (!in_array($f['stage'], ['done', 'error'], true)) {
            $allDone = false;
            break;
        }
        if ($f['stage'] === 'done') {
            $hasSuccess = true;
        }
    }

    if ($allDone && $hasSuccess) {
        setAppSetting('last_update_all', date('c'));
    }

    return ['done' => $allDone, 'type' => $type, 'stage' => $state['files'][$type]['stage']];
}

/**
 * Weight (0..1) representing how far a single file has progressed, used to compute
 * the overall progress bar across all files. Shared by setup.php's first-run install
 * wizard and the settings page's "Update jetzt" modal (src/routes/actions.php) — both
 * drive the same stepRebrickableImport() tick machine and want the same progress feel.
 */
function calculateFileProgressFraction(array $file): float
{
    switch ($file['stage']) {
        case 'downloading':
            if (!empty($file['totalBytes'])) {
                return 0.45 * min(1.0, $file['bytes'] / $file['totalBytes']);
            }
            return $file['bytes'] > 0 ? 0.2 : 0.05;
        case 'extracting':
            return 0.5;
        case 'importing':
            // No reliable total row count without an extra full-file pass, so importing
            // is shown as a fixed midpoint; the row counter itself still updates live.
            return 0.75;
        case 'done':
        case 'error':
            return 1.0;
        default:
            return 0.0;
    }
}

/**
 * JSON progress payload for a rebrickable-import tick action — see
 * calculateFileProgressFraction()'s doc comment for who shares this.
 */
function buildImportProgressPayload(array $state, bool $done): array
{
    $files = [];
    $sum = 0.0;
    $hasErrors = false;

    foreach ($state['files'] as $type => $file) {
        $sum += calculateFileProgressFraction($file);
        if ($file['stage'] === 'error') {
            $hasErrors = true;
        }
        $files[$type] = [
            'label' => $file['label'],
            'stage' => $file['stage'],
            'message' => $file['message'],
            'bytes' => $file['bytes'],
            'totalBytes' => $file['totalBytes'],
            'rows' => $file['rows'],
        ];
    }

    $total = count($state['files']);
    $percent = $total > 0 ? (int) round(($sum / $total) * 100) : 0;

    return [
        'status' => $done ? 'done' : 'running',
        'percent' => $percent,
        'message' => $done
            ? ($hasErrors ? t('import_completed_with_errors') : t('import_completed'))
            : t('import_running'),
        'files' => $files,
    ];
}

/**
 * Synchronous convenience wrapper around the chunked step machine, for callers that
 * don't drive it via repeated AJAX requests (e.g. the first-run install and the
 * dashboard's manual "update now" button). Runs every tick in a loop within a single
 * request/response cycle, so it does not by itself solve the shared-hosting timeout
 * problem for those two call sites — only the setup wizard drives this incrementally.
 *
 * @param null|callable(string $type, string $stage, array $extra): void $progressCallback
 *   Stages: 'init' (extra: ['types' => string[]]), 'downloading', 'extracting',
 *   'importing', 'done' (extra: ['rows' => int]), 'error' (extra: ['message' => string]).
 * @return array{summary: array<string,int>, errors: array<string,string>}
 */
function downloadAndImportRebrickableData(?callable $progressCallback = null): array
{
    $state = initRebrickableImportState();

    if ($progressCallback) {
        $progressCallback('', 'init', ['types' => $state['types']]);
    }

    do {
        $result = stepRebrickableImport($state);

        if ($progressCallback && $result['type'] !== null) {
            $file = $state['files'][$result['type']];
            $extra = [];
            if ($file['stage'] === 'error') {
                $extra['message'] = $file['message'];
            }
            if ($file['stage'] === 'done') {
                $extra['rows'] = $file['rows'];
            }
            $progressCallback($result['type'], $file['stage'], $extra);
        }
    } while (!$result['done']);

    $summary = [];
    $errors = [];
    foreach ($state['files'] as $type => $file) {
        if ($file['stage'] === 'done') {
            $summary[$type] = $file['rows'];
        } elseif ($file['stage'] === 'error') {
            $errors[$type] = $file['message'] ?? 'Unbekannter Fehler';
        }
    }

    // part_set_counts (sets.php's getPartSetCounts()) is a lazily-computed
    // cache of "how many sets does this part+color appear in" — correct
    // only as of the data at the time each entry was computed. A full
    // resync can change those counts (new sets add new appearances), so
    // clear it here; it repopulates itself on demand, same as before.
    try {
        getPDO()->exec('TRUNCATE TABLE part_set_counts');
    } catch (Throwable $e) {
        // Table may not exist yet on a pre-migration install — the next
        // migration run creates it and there's nothing to invalidate anyway.
    }

    // Best-effort (see its own doc comment) — an update with no API key
    // configured, or a failed API call, must not turn into a failed
    // Rebrickable update overall, so any error is already swallowed inside.
    syncExternalColorIds();

    return ['summary' => $summary, 'errors' => $errors];
}

/**
 * The settings page's "Update jetzt" modal — same tick machine
 * (stepRebrickableImport(), via action=rebrickable_update_tick in
 * src/routes/actions.php) and the same progress-bar/file-list look as
 * setup.php's first-run install wizard, just in a modal instead of a full
 * page, since the settings page already has the surrounding UI a fresh
 * install doesn't. Self-contained (own markup + own <script>, reuses the
 * generic .modal-overlay/.modal-box shell) — same pattern as
 * renderPartDetailModal() in src/part_modal.php.
 *
 * Closing the modal only hides it; the tick loop keeps running (same
 * session-stored state setup.php's version relies on for resumability), so
 * reopening it — or just waiting — doesn't lose progress. Only a page
 * reload actually stops the client-side polling, and even then the next
 * "Update jetzt" click resumes from whatever file was in progress.
 */
function renderRebrickableUpdateModal(): string
{
    $html = '<div class="modal-overlay" id="rebrickable-update-modal" style="display:none;">';
    $html .= '<div class="modal-box">';
    $html .= '<button type="button" class="modal-close" id="rebrickable-update-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<h2>' . htmlspecialchars(t('settings_update_button')) . '</h2>';
    $html .= '<div class="import-status" id="rebrickableUpdateStatus">';
    $html .= '<div class="progress-message idle" id="rebrickableUpdateMessage">' . htmlspecialchars(t('import_not_started')) . '</div>';
    $html .= '<div class="progress-track" id="rebrickableUpdateProgress"><div class="progress-fill"></div></div>';
    $html .= '<ul class="import-file-list" id="rebrickableUpdateFileList">';
    foreach (getRebrickableImportTypes() as $type) {
        $html .= '<li class="import-file import-file-pending"><span class="import-file-name">' . htmlspecialchars($type . '.csv') . '</span><span class="import-file-status">' . htmlspecialchars(t('import_stage_pending')) . '</span></li>';
    }
    $html .= '</ul>';
    $html .= '</div>';
    $html .= '<button type="button" id="rebrickable-update-start">' . htmlspecialchars(t('settings_update_button')) . '</button>';
    $html .= '</div></div>';

    $labelsJson = json_encode([
        'running' => t('import_running'),
        'button' => t('settings_update_button'),
        'resume_button' => t('import_resume_button'),
        'error_retry' => t('import_error_retry'),
        'started' => t('import_started'),
        'stage_pending' => t('import_stage_pending'),
        'stage_downloading' => t('import_stage_downloading'),
        'stage_extracting' => t('import_stage_extracting'),
        'stage_importing' => t('import_stage_importing'),
        'stage_done' => t('import_stage_done'),
        'stage_error' => t('import_stage_error_label'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html .= <<<SCRIPT
<script>
(function(){
  var texts = $labelsJson;
  var stageLabels = {
    pending: texts.stage_pending,
    downloading: texts.stage_downloading,
    extracting: texts.stage_extracting,
    importing: texts.stage_importing,
    done: texts.stage_done,
    error: texts.stage_error
  };
  var openBtn = document.getElementById('rebrickable-update-open');
  var modal = document.getElementById('rebrickable-update-modal');
  var closeBtn = document.getElementById('rebrickable-update-modal-close');
  var startBtn = document.getElementById('rebrickable-update-start');
  var track = document.getElementById('rebrickableUpdateProgress');
  var fill = track ? track.querySelector('.progress-fill') : null;
  var msg = document.getElementById('rebrickableUpdateMessage');
  var fileList = document.getElementById('rebrickableUpdateFileList');
  if (!openBtn || !modal || !closeBtn || !startBtn || !track || !fill || !msg || !fileList) {
    return;
  }

  function formatBytes(bytes) {
    if (bytes === null || bytes === undefined) {
      return null;
    }
    var units = ['B', 'KB', 'MB', 'GB'];
    var value = bytes;
    var unitIndex = 0;
    while (value >= 1024 && unitIndex < units.length - 1) {
      value /= 1024;
      unitIndex++;
    }
    return value.toFixed(unitIndex === 0 ? 0 : 1) + ' ' + units[unitIndex];
  }

  function formatNumber(n) {
    if (n === null || n === undefined) {
      return null;
    }
    return String(n).replace(/\\B(?=(\\d{3})+(?!\\d))/g, '.');
  }

  function renderFiles(files) {
    fileList.innerHTML = '';
    Object.keys(files).forEach(function(type) {
      var file = files[type];
      var li = document.createElement('li');
      li.className = 'import-file import-file-' + file.stage;

      var name = document.createElement('span');
      name.className = 'import-file-name';
      name.textContent = file.label;

      var status = document.createElement('span');
      status.className = 'import-file-status';
      var text = stageLabels[file.stage] || file.stage;
      if (file.stage === 'downloading') {
        if (file.totalBytes) {
          var pct = Math.round((file.bytes / file.totalBytes) * 100);
          text += ' ' + pct + '% (' + formatBytes(file.bytes) + ' / ' + formatBytes(file.totalBytes) + ')';
        } else if (file.bytes) {
          text += ' (' + formatBytes(file.bytes) + ')';
        }
      }
      if (file.stage === 'importing' && (file.rows || file.rows === 0)) {
        text += ' (' + formatNumber(file.rows) + ')';
      }
      if (file.stage === 'error' && file.message) {
        text += ': ' + file.message;
      }
      if (file.stage === 'done' && (file.rows || file.rows === 0)) {
        text += ' (' + formatNumber(file.rows) + ')';
      }
      status.textContent = text;

      li.appendChild(name);
      li.appendChild(status);
      fileList.appendChild(li);
    });
  }

  function updateStatus(data) {
    msg.classList.remove('idle');
    msg.textContent = data.message || texts.running;
    fill.style.width = (data.percent || 0) + '%';
    renderFiles(data.files || {});
  }

  async function tick() {
    var formData = new FormData();
    formData.set('action', 'rebrickable_update_tick');
    var response = await fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' });
    if (!response.ok && response.status !== 500) {
      throw new Error('tick failed with status ' + response.status);
    }
    return await response.json();
  }

  var hasStarted = false;
  var isRunning = false;
  var consecutiveFailures = 0;
  var maxAutoRetries = 4;

  function openModal() {
    modal.style.display = 'flex';
  }
  function closeModal() {
    modal.style.display = 'none';
  }
  openBtn.addEventListener('click', function(e) {
    e.preventDefault();
    openModal();
  });
  closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function(e) {
    if (e.target === modal) {
      closeModal();
    }
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && modal.style.display !== 'none') {
      closeModal();
    }
  });

  function pauseWithMessage(message) {
    isRunning = false;
    startBtn.disabled = false;
    startBtn.textContent = texts.resume_button;
    msg.textContent = message || texts.error_retry;
  }

  function loop() {
    tick().then(function(data) {
      consecutiveFailures = 0;
      updateStatus(data);
      if (data.status === 'done') {
        isRunning = false;
        startBtn.disabled = false;
        startBtn.textContent = texts.button;
        return;
      }
      if (data.status === 'error') {
        pauseWithMessage(data.message);
        return;
      }
      setTimeout(loop, 50);
    }).catch(function() {
      consecutiveFailures++;
      if (consecutiveFailures <= maxAutoRetries) {
        msg.textContent = texts.error_retry + ' (' + consecutiveFailures + '/' + maxAutoRetries + ')';
        setTimeout(loop, 1000 * consecutiveFailures);
        return;
      }
      pauseWithMessage(texts.error_retry);
    });
  }

  startBtn.addEventListener('click', function() {
    if (isRunning) {
      return;
    }
    isRunning = true;
    startBtn.disabled = true;
    startBtn.textContent = texts.running;
    if (!hasStarted) {
      updateStatus({ percent: 0, message: texts.started, files: {} });
      hasStarted = true;
    } else {
      msg.textContent = texts.running;
    }
    loop();
  });
})();
</script>
SCRIPT;

    return $html;
}
