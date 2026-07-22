<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/settings.php';

function getRebrickableDownloadPageUrl(): string
{
    $config = require __DIR__ . '/config.php';
    return $config['rebrickable']['download_page'] ?? 'https://rebrickable.com/downloads/';
}

function fetchRebrickableDownloadUrls(): array
{
    $url = getRebrickableDownloadPageUrl();
    $context = stream_context_create(['http' => ['timeout' => 20]]);
    $html = @file_get_contents($url, false, $context);
    if ($html === false) {
        throw new RuntimeException('Konnte die Rebrickable-Downloadseite nicht laden.');
    }

    $urls = [];
    if (preg_match_all('/href=["\']([^"\']+\.(?:csv|csv\.gz))["\']/i', $html, $matches)) {
        foreach ($matches[1] as $href) {
            $urls[] = resolveUrl($href, $url);
        }
    }

    return array_values(array_unique($urls));
}

function resolveUrl(string $href, string $base): string
{
    if (parse_url($href, PHP_URL_SCHEME) !== null) {
        return $href;
    }

    if (strpos($href, '//') === 0) {
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $href;
    }

    $baseParts = parse_url($base);
    $scheme = $baseParts['scheme'] ?? 'https';
    $host = $baseParts['host'] ?? '';
    $path = $baseParts['path'] ?? '/';
    $dir = substr($path, 0, strrpos($path, '/') + 1);

    if (strpos($href, '/') === 0) {
        return sprintf('%s://%s%s', $scheme, $host, $href);
    }

    return sprintf('%s://%s%s%s', $scheme, $host, $dir, $href);
}

function findDownloadTypes(array $urls): array
{
    $known = ['parts', 'sets', 'set_parts'];
    $found = [];

    foreach ($urls as $url) {
        $name = strtolower(basename(parse_url($url, PHP_URL_PATH)));
        foreach ($known as $type) {
            if (str_contains($name, $type)) {
                $found[$type] = $url;
            }
        }
    }

    return $found;
}

function downloadFile(string $url, string $targetPath): void
{
    $context = stream_context_create(['http' => ['timeout' => 60]]);
    $content = @file_get_contents($url, false, $context);
    if ($content === false) {
        throw new RuntimeException('Konnte Datei nicht herunterladen: ' . $url);
    }
    file_put_contents($targetPath, $content);
}

function decompressIfNeeded(string $sourcePath, string $targetPath): string
{
    if (str_ends_with(strtolower($sourcePath), '.gz')) {
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
    return $sourcePath;
}

function downloadAndImportRebrickableData(): array
{
    $urls = fetchRebrickableDownloadUrls();
    $found = findDownloadTypes($urls);
    if (empty($found)) {
        throw new RuntimeException('Keine Rebrickable-Download-Dateien gefunden.');
    }

    $tmpDir = sys_get_temp_dir() . '/studsphere_rebrickable';
    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
        throw new RuntimeException('Konnte temporäres Verzeichnis nicht erstellen: ' . $tmpDir);
    }

    $summary = [];
    foreach (['parts', 'sets', 'set_parts'] as $type) {
        if (!isset($found[$type])) {
            continue;
        }
        $source = $tmpDir . '/' . basename(parse_url($found[$type], PHP_URL_PATH));
        downloadFile($found[$type], $source);

        $csvFile = decompressIfNeeded($source, $source . '.csv');
        if ($csvFile !== $source && !file_exists($csvFile)) {
            throw new RuntimeException('Konnte entpackte CSV-Datei nicht erstellen: ' . $csvFile);
        }

        $importFile = $csvFile;
        $result = importCsv($importFile, $type);
        $summary[$type] = $result['rows'] ?? 0;
        setAppSetting('last_update_' . $type, date('c'));
    }

    setAppSetting('last_update_all', date('c'));
    return $summary;
}
