<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

/**
 * German part-name translations are bulk-loaded from a git-tracked PHP array
 * file (lang/parts_translated_de.php, generated once from a spreadsheet —
 * see the file header) instead of shipping in the small UI-string lang
 * files. That file is only ~60k entries, several MB — fine to `require`
 * during the one-time sync, but never on a normal page load, so it isn't
 * loaded here at all outside of syncing.
 */
const PART_TRANSLATIONS_SYNC_CHUNK_SIZE = 2000;
const PART_TRANSLATIONS_LOCALE = 'de';

function getPartTranslationsFilePath(): string
{
    return dirname(__DIR__) . '/lang/parts_translated_de.php';
}

function getPartTranslationsFileChecksum(): ?string
{
    $path = getPartTranslationsFilePath();
    if (!is_file($path)) {
        return null;
    }
    $checksum = md5_file($path);
    return $checksum !== false ? $checksum : null;
}

function partTranslationsSyncPending(): bool
{
    $fileChecksum = getPartTranslationsFileChecksum();
    if ($fileChecksum === null) {
        return false;
    }
    return getAppSetting('part_translations_de_checksum') !== $fileChecksum;
}

/**
 * Imports one bounded chunk of the translations file into part_translations,
 * tracking progress via an offset in app_settings — a 60k+-row file needs
 * many page loads to fully sync (tick-based, same reasoning as the rest of
 * the app's shared-hosting-safe import flows: never do unbounded work in a
 * single request). Once the whole file has been processed, stores its
 * checksum so every subsequent request is a single cheap comparison instead
 * of re-importing.
 *
 * @return array{done: bool, processed: int, total: int}
 */
function stepPartTranslationsSync(): array
{
    $fileChecksum = getPartTranslationsFileChecksum();
    if ($fileChecksum === null) {
        return ['done' => true, 'processed' => 0, 'total' => 0];
    }

    // A checksum change mid-sync (file replaced while a previous sync was
    // still catching up) restarts from the beginning — the stored offset is
    // only meaningful relative to the checksum it was saved alongside.
    $trackedChecksum = getAppSetting('part_translations_de_sync_checksum');
    $offset = $trackedChecksum === $fileChecksum
        ? (int) getAppSetting('part_translations_de_sync_offset', '0')
        : 0;

    $translations = require getPartTranslationsFilePath();
    $total = count($translations);
    $partNums = array_keys($translations);
    $chunk = array_slice($partNums, $offset, PART_TRANSLATIONS_SYNC_CHUNK_SIZE);

    if (!empty($chunk)) {
        importPartTranslationsChunk($chunk, $translations);
    }

    $newOffset = $offset + count($chunk);
    $done = $newOffset >= $total;

    if ($done) {
        setAppSetting('part_translations_de_checksum', $fileChecksum);
        setAppSetting('part_translations_de_sync_offset', '0');
    } else {
        setAppSetting('part_translations_de_sync_checksum', $fileChecksum);
        setAppSetting('part_translations_de_sync_offset', (string) $newOffset);
    }

    return ['done' => $done, 'processed' => $newOffset, 'total' => $total];
}

function importPartTranslationsChunk(array $partNums, array $translations): void
{
    $pdo = getPDO();
    $placeholders = implode(',', array_fill(0, count($partNums), '?'));
    $stmt = $pdo->prepare("SELECT id, part_num FROM parts WHERE part_num IN ($placeholders)");
    $stmt->execute($partNums);
    $idsByPartNum = [];
    foreach ($stmt->fetchAll() as $row) {
        $idsByPartNum[$row['part_num']] = (int) $row['id'];
    }

    if (empty($idsByPartNum)) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $insertStmt = $pdo->prepare(
            'INSERT INTO part_translations (part_id, locale, name)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );
        foreach ($partNums as $partNum) {
            if (!isset($idsByPartNum[$partNum])) {
                continue; // part not in this installation's catalog (yet)
            }
            $insertStmt->execute([$idsByPartNum[$partNum], PART_TRANSLATIONS_LOCALE, $translations[$partNum]]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
