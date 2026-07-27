<?php

declare(strict_types=1);

require_once __DIR__ . '/download.php';
require_once __DIR__ . '/migrations.php';

const UPDATE_REPO = 'gmen-de/StudSphere';
const UPDATE_DOWNLOAD_CHUNK_SIZE = 1_000_000; // ~1 MB per download tick, same reasoning as Rebrickable
const UPDATE_TIME_BUDGET_SECONDS = 4.0;
const UPDATE_FILE_BATCH_SIZE = 50; // files copied/deleted per tick

function getCurrentVersion(): string
{
    $path = dirname(__DIR__) . '/VERSION';
    return is_file($path) ? trim((string) file_get_contents($path)) : '0.0.0';
}

/**
 * No separate release-date tracking exists — the VERSION file's own mtime
 * is a reasonable stand-in, since it's rewritten every time the version
 * bumps (either by hand or by applyUpdate() after a self-update).
 */
function getVersionDate(): string
{
    $path = dirname(__DIR__) . '/VERSION';
    $mtime = is_file($path) ? filemtime($path) : false;
    return $mtime !== false ? date('d.m.Y', $mtime) : '';
}

/**
 * Paths (relative to project root) an update must never touch: local config,
 * user data, and generated caches. None of these ship in the git repo /
 * release zip in the first place (see .gitignore), so this list is really
 * just a defensive safety net, not the primary protection mechanism.
 */
function getUpdateExcludedPaths(): array
{
    return [
        'src/config.php',
        'storage',
        'public/images',
        '.git',
        '.gitignore',
        '.gitattributes',
    ];
}

function isPathExcluded(string $relative, array $excluded): bool
{
    foreach ($excluded as $ex) {
        if ($relative === $ex || str_starts_with($relative, $ex . '/')) {
            return true;
        }
    }
    return false;
}

function fetchLatestRelease(): ?array
{
    $url = 'https://api.github.com/repos/' . UPDATE_REPO . '/releases/latest';
    $headers = "User-Agent: StudSphere-Updater\r\nAccept: application/vnd.github+json\r\n";

    $context = stream_context_create([
        'http' => ['header' => $headers, 'timeout' => 20, 'ignore_errors' => true],
    ]);
    $response = @file_get_contents($url, false, $context);

    if ($response === false && function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: StudSphere-Updater', 'Accept: application/vnd.github+json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $status >= 400) {
            return null;
        }
    } elseif ($response === false) {
        return null;
    }

    $data = json_decode((string) $response, true);
    if (!is_array($data) || !isset($data['tag_name'])) {
        return null;
    }

    $tag = (string) $data['tag_name'];
    return [
        'tag' => $tag,
        'version' => ltrim($tag, 'vV'),
        'name' => (string) ($data['name'] ?? $tag),
        'zipUrl' => 'https://github.com/' . UPDATE_REPO . '/archive/refs/tags/' . $tag . '.zip',
        'publishedAt' => $data['published_at'] ?? null,
        'notes' => (string) ($data['body'] ?? ''),
    ];
}

/**
 * @return array{tag:string,version:string,name:string,zipUrl:string,publishedAt:?string,notes:string}|null
 */
function isUpdateAvailable(): ?array
{
    $latest = fetchLatestRelease();
    if ($latest === null) {
        return null;
    }
    if (version_compare($latest['version'], getCurrentVersion(), '>')) {
        return $latest;
    }
    return null;
}

function getUpdateWorkDir(): string
{
    $dir = dirname(__DIR__) . '/storage/update';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Konnte Arbeitsverzeichnis für das Update nicht erstellen: ' . $dir);
    }
    return $dir;
}

/**
 * @return string[] sorted file paths relative to $baseDir (files only, not directories)
 */
function listFilesRelative(string $baseDir, array $excluded = []): array
{
    $baseDir = rtrim($baseDir, '/');
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($baseDir) + 1));
        if (isPathExcluded($relative, $excluded)) {
            continue;
        }
        if ($item->isFile()) {
            $files[] = $relative;
        }
    }
    sort($files);
    return $files;
}

function removeDirectoryRecursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

function removeEmptyDirectoriesRecursive(string $baseDir, array $excluded = []): void
{
    $baseDir = rtrim($baseDir, '/');
    if (!is_dir($baseDir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if (!$item->isDir()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($baseDir) + 1));
        if (isPathExcluded($relative, $excluded)) {
            continue;
        }
        @rmdir($item->getPathname()); // silently no-ops if not actually empty
    }
}

function initUpdateState(array $release): array
{
    $workDir = getUpdateWorkDir();
    return [
        'stage' => 'pending',
        'release' => $release,
        'zipPath' => $workDir . '/release.zip',
        'extractRoot' => $workDir . '/extracted',
        'sourceRoot' => null,
        'bytes' => 0,
        'totalBytes' => null,
        'acceptsRanges' => null,
        'filesToCopy' => [],
        'filesToDelete' => [],
        'copyIndex' => 0,
        'deleteIndex' => 0,
        'migrationsRun' => 0,
    ];
}

/**
 * Performs one bounded step of the update process and mutates $state in
 * place. Safe to call repeatedly from separate HTTP requests, same tick
 * pattern as the Rebrickable import / image download.
 * @return array{done: bool}
 */
function stepUpdate(array &$state): array
{
    switch ($state['stage']) {
        case 'pending':
            $probe = probeRemoteFile($state['release']['zipUrl']);
            if ($probe['status'] !== null && $probe['status'] >= 400) {
                throw new RuntimeException('Release-ZIP nicht erreichbar (HTTP ' . $probe['status'] . ').');
            }
            $state['totalBytes'] = $probe['size'];
            $state['acceptsRanges'] = $probe['acceptsRanges'];
            if (is_file($state['zipPath'])) {
                unlink($state['zipPath']);
            }
            $state['stage'] = 'downloading';
            return ['done' => false];

        case 'downloading':
            if ($state['acceptsRanges']) {
                $result = downloadFileRangeChunk($state['release']['zipUrl'], $state['zipPath'], $state['bytes'], UPDATE_DOWNLOAD_CHUNK_SIZE);
                $bytesRead = $result['bytesRead'];
                if ($bytesRead > UPDATE_DOWNLOAD_CHUNK_SIZE) {
                    $state['bytes'] = $bytesRead;
                    $atEnd = true;
                } else {
                    $state['bytes'] += $bytesRead;
                    $atEnd = $state['totalBytes'] !== null
                        ? $state['bytes'] >= $state['totalBytes']
                        : $bytesRead < UPDATE_DOWNLOAD_CHUNK_SIZE;
                }
                if ($atEnd) {
                    if (!is_file($state['zipPath']) || filesize($state['zipPath']) === 0) {
                        throw new RuntimeException('Download ergab eine leere Datei.');
                    }
                    $state['stage'] = 'extracting';
                }
            } else {
                downloadFile($state['release']['zipUrl'], $state['zipPath']);
                $state['bytes'] = filesize($state['zipPath']) ?: 0;
                $state['stage'] = 'extracting';
            }
            return ['done' => false];

        case 'extracting':
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException('Zip-Unterstützung nicht verfügbar (ZipArchive fehlt).');
            }
            if (is_dir($state['extractRoot'])) {
                removeDirectoryRecursive($state['extractRoot']);
            }
            mkdir($state['extractRoot'], 0755, true);

            $zip = new ZipArchive();
            if ($zip->open($state['zipPath']) !== true) {
                throw new RuntimeException('Konnte Release-ZIP nicht öffnen.');
            }
            $zip->extractTo($state['extractRoot']);
            $zip->close();

            // GitHub's codeload archive zips always have exactly one top-level folder.
            $entries = array_values(array_diff(scandir($state['extractRoot']) ?: [], ['.', '..']));
            if (count($entries) !== 1 || !is_dir($state['extractRoot'] . '/' . $entries[0])) {
                throw new RuntimeException('Unerwarteter Aufbau des Release-ZIP.');
            }
            $state['sourceRoot'] = $state['extractRoot'] . '/' . $entries[0];
            $state['stage'] = 'diffing';
            return ['done' => false];

        case 'diffing':
            $projectRoot = dirname(__DIR__);
            $excluded = getUpdateExcludedPaths();
            $newFiles = listFilesRelative($state['sourceRoot'], $excluded);
            $currentFiles = listFilesRelative($projectRoot, $excluded);

            $state['filesToCopy'] = $newFiles;
            $state['filesToDelete'] = array_values(array_diff($currentFiles, $newFiles));
            $state['copyIndex'] = 0;
            $state['deleteIndex'] = 0;
            $state['stage'] = 'copying';
            return ['done' => false];

        case 'copying':
            $projectRoot = dirname(__DIR__);
            $start = microtime(true);
            $count = 0;
            $total = count($state['filesToCopy']);
            while ($state['copyIndex'] < $total && $count < UPDATE_FILE_BATCH_SIZE) {
                $relative = $state['filesToCopy'][$state['copyIndex']];
                $source = $state['sourceRoot'] . '/' . $relative;
                $target = $projectRoot . '/' . $relative;
                $targetDir = dirname($target);
                if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                    throw new RuntimeException('Konnte Zielverzeichnis nicht erstellen: ' . $targetDir);
                }
                if (!copy($source, $target)) {
                    throw new RuntimeException('Konnte Datei nicht kopieren: ' . $relative);
                }
                $state['copyIndex']++;
                $count++;
                if ((microtime(true) - $start) >= UPDATE_TIME_BUDGET_SECONDS) {
                    break;
                }
            }
            if ($state['copyIndex'] >= $total) {
                $state['stage'] = 'deleting';
            }
            return ['done' => false];

        case 'deleting':
            $projectRoot = dirname(__DIR__);
            $start = microtime(true);
            $count = 0;
            $total = count($state['filesToDelete']);
            while ($state['deleteIndex'] < $total && $count < UPDATE_FILE_BATCH_SIZE) {
                $relative = $state['filesToDelete'][$state['deleteIndex']];
                $target = $projectRoot . '/' . $relative;
                if (is_file($target)) {
                    @unlink($target);
                }
                $state['deleteIndex']++;
                $count++;
                if ((microtime(true) - $start) >= UPDATE_TIME_BUDGET_SECONDS) {
                    break;
                }
            }
            if ($state['deleteIndex'] >= $total) {
                removeEmptyDirectoriesRecursive($projectRoot, getUpdateExcludedPaths());
                $state['stage'] = 'migrating';
            }
            return ['done' => false];

        case 'migrating':
            $result = stepSchemaMigration();
            if ($result['ranVersion'] !== null) {
                $state['migrationsRun']++;
            }
            if ($result['done']) {
                file_put_contents(dirname(__DIR__) . '/VERSION', $state['release']['version'] . "\n");
                removeDirectoryRecursive($state['extractRoot']);
                @unlink($state['zipPath']);
                $state['stage'] = 'done';
                return ['done' => true];
            }
            return ['done' => false];

        case 'done':
            return ['done' => true];
    }

    throw new RuntimeException('Unbekannte Update-Stufe: ' . $state['stage']);
}

function buildUpdateProgressPayload(array $state, bool $done): array
{
    $percent = 0.0;
    switch ($state['stage']) {
        case 'pending':
            $percent = 0.0;
            break;
        case 'downloading':
            $percent = $state['totalBytes']
                ? 0.05 + 0.30 * min(1.0, $state['bytes'] / $state['totalBytes'])
                : 0.1;
            break;
        case 'extracting':
            $percent = 0.35;
            break;
        case 'diffing':
            $percent = 0.4;
            break;
        case 'copying':
            $percent = 0.45 + 0.40 * (empty($state['filesToCopy']) ? 1.0 : min(1.0, $state['copyIndex'] / count($state['filesToCopy'])));
            break;
        case 'deleting':
            $percent = 0.85 + 0.10 * (empty($state['filesToDelete']) ? 1.0 : min(1.0, $state['deleteIndex'] / count($state['filesToDelete'])));
            break;
        case 'migrating':
            $percent = 0.95;
            break;
        case 'done':
            $percent = 1.0;
            break;
    }

    return [
        'status' => $done ? 'done' : 'running',
        'percent' => (int) round($percent * 100),
        'stage' => $state['stage'],
        'bytes' => $state['bytes'],
        'totalBytes' => $state['totalBytes'],
        'filesToCopyTotal' => count($state['filesToCopy']),
        'filesToCopyDone' => $state['copyIndex'],
        'filesToDeleteTotal' => count($state['filesToDelete']),
        'filesToDeleteDone' => $state['deleteIndex'],
    ];
}
