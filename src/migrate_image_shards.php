<?php

declare(strict_types=1);

/**
 * One-off migration: moves already-downloaded images from the old flat layout
 * (public/images/{table}/{filename}) into the sharded layout
 * (public/images/{table}/{shard}/{filename}) introduced afterwards, and updates
 * local_image_path in the database to match. Safe to run multiple times — rows
 * already pointing at a sharded path are left alone.
 *
 * Run once via CLI: php src/migrate_image_shards.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/images.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Dieses Skript ist nur für die Kommandozeile gedacht (php src/migrate_image_shards.php).');
}

$projectRoot = dirname(__DIR__);
$pdo = getPDO();

foreach (array_keys(IMAGE_DOWNLOAD_TABLES) as $table) {
    $stmt = $pdo->query("SELECT id, local_image_path FROM `$table` WHERE local_image_path IS NOT NULL AND local_image_path != ''");
    $rows = $stmt->fetchAll();

    $moved = 0;
    $alreadySharded = 0;
    $missing = 0;
    $errors = 0;

    $updateStmt = $pdo->prepare("UPDATE `$table` SET local_image_path = ? WHERE id = ?");

    foreach ($rows as $row) {
        $oldRelative = $row['local_image_path'];
        $prefix = 'public/images/' . $table . '/';
        if (strpos($oldRelative, $prefix) !== 0) {
            continue; // not our layout, leave untouched
        }

        $rest = substr($oldRelative, strlen($prefix));
        // Already sharded paths look like "xx/filename.ext" (shard/filename).
        if (preg_match('#^[0-9a-f]{2}/[^/]+$#', $rest) === 1) {
            $alreadySharded++;
            continue;
        }

        $filename = $rest; // old flat layout: just the filename
        $oldAbsolute = $projectRoot . '/' . $oldRelative;
        if (!is_file($oldAbsolute)) {
            $missing++;
            continue;
        }

        $shard = getImageShard($filename);
        $newDir = getImageStorageDir($table, $shard);
        $newAbsolute = $newDir . '/' . $filename;
        $newRelative = getImageRelativePath($table, $shard, $filename);

        if (@rename($oldAbsolute, $newAbsolute)) {
            $updateStmt->execute([$newRelative, $row['id']]);
            $moved++;
        } else {
            $errors++;
        }
    }

    echo "$table: verschoben=$moved bereits-sortiert=$alreadySharded fehlend=$missing fehler=$errors\n";
}

echo "Fertig.\n";
