<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const CSV_IMPORT_BATCH_SIZE = 500;

function normalizeHeader(string $value): string
{
    return strtolower(trim($value));
}

function detectCsvDelimiter(string $headerLine): string
{
    if (substr_count($headerLine, ';') > substr_count($headerLine, ',')) {
        return ';';
    }
    return ',';
}

function parseCsvBool(?string $value): int
{
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 't'], true) ? 1 : 0;
}

function readCsvBatches(string $filePath, int $batchSize = CSV_IMPORT_BATCH_SIZE): iterable
{
    if ($batchSize < 1) {
        throw new InvalidArgumentException('Batchgröße muss größer als 0 sein.');
    }

    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Konnte die CSV-Datei nicht öffnen.');
    }

    $firstLine = fgets($handle);
    if ($firstLine === false) {
        fclose($handle);
        throw new RuntimeException('Die CSV-Datei ist leer.');
    }

    $delimiter = detectCsvDelimiter($firstLine);
    rewind($handle);

    $headers = fgetcsv($handle, 0, $delimiter);
    if ($headers === false) {
        fclose($handle);
        throw new RuntimeException('Konnte die CSV-Header nicht lesen.');
    }

    $headers = array_map('normalizeHeader', $headers);
    $batch = [];
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count($row) === 1 && $row[0] === null) {
            continue;
        }

        $mapped = array_combine($headers, $row);
        if ($mapped === false) {
            continue;
        }

        $batch[] = $mapped;
        if (count($batch) >= $batchSize) {
            yield $batch;
            $batch = [];
        }
    }

    if (!empty($batch)) {
        yield $batch;
    }

    fclose($handle);
}

function importBatchForType(string $type, array $batch): array
{
    return match ($type) {
        'themes' => importThemesCsv($batch),
        'colors' => importColorsCsv($batch),
        'part_categories' => importPartCategoriesCsv($batch),
        'parts' => importPartsCsv($batch),
        'part_relationships' => importPartRelationshipsCsv($batch),
        'elements' => importElementsCsv($batch),
        'sets' => importSetsCsv($batch),
        'minifigs' => importMinifigsCsv($batch),
        'inventories' => importInventoriesCsv($batch),
        'inventory_parts' => importInventoryPartsCsv($batch),
        'inventory_sets' => importInventorySetsCsv($batch),
        'inventory_minifigs' => importInventoryMinifigsCsv($batch),
        'set_parts' => importSetPartsCsv($batch),
        'bricklink_minifig_ids' => importBricklinkMinifigIdsCsv($batch),
        default => throw new InvalidArgumentException('Unbekannter Importtyp: ' . $type),
    };
}

function importCsv(string $tmpFile, string $type): array
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $rowsImported = 0;
    $foundBatch = false;

    foreach (readCsvBatches($tmpFile) as $batch) {
        $foundBatch = true;
        if (empty($batch)) {
            continue;
        }

        $result = importBatchForType($type, $batch);
        $rowsImported += $result['rows'] ?? 0;
    }

    if (!$foundBatch) {
        throw new RuntimeException('Keine Daten in der CSV-Datei gefunden.');
    }

    return ['rows' => $rowsImported];
}

function readCsvHeaderInfo(string $filePath): array
{
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Konnte die CSV-Datei nicht öffnen.');
    }

    $firstLine = fgets($handle);
    if ($firstLine === false) {
        fclose($handle);
        throw new RuntimeException('Die CSV-Datei ist leer.');
    }

    $delimiter = detectCsvDelimiter($firstLine);
    rewind($handle);

    $headers = fgetcsv($handle, 0, $delimiter);
    if ($headers === false) {
        fclose($handle);
        throw new RuntimeException('Konnte die CSV-Header nicht lesen.');
    }
    $dataOffset = ftell($handle);
    fclose($handle);

    return [
        'headers' => array_map('normalizeHeader', $headers),
        'delimiter' => $delimiter,
        'dataOffset' => $dataOffset,
    ];
}

/**
 * Imports a bounded slice of a CSV file, resuming from a byte offset so the work can
 * be split across many short-lived requests instead of one long-running import.
 * @return array{imported: int, nextOffset: int, done: bool}
 */
function importCsvChunk(string $filePath, string $type, array $headers, string $delimiter, int $byteOffset, int $maxRows, float $timeBudgetSeconds): array
{
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Konnte die CSV-Datei nicht öffnen.');
    }
    if (fseek($handle, $byteOffset) !== 0) {
        fclose($handle);
        throw new RuntimeException('Konnte nicht an die gespeicherte Position in der CSV-Datei springen.');
    }

    $start = microtime(true);
    $batch = [];
    $rowsRead = 0;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (!(count($row) === 1 && $row[0] === null)) {
            $mapped = array_combine($headers, $row);
            if ($mapped !== false) {
                $batch[] = $mapped;
                $rowsRead++;
            }
        }

        if ($rowsRead >= $maxRows || (microtime(true) - $start) >= $timeBudgetSeconds) {
            break;
        }
    }

    $done = feof($handle);
    $nextOffset = ftell($handle);
    fclose($handle);

    $imported = 0;
    if (!empty($batch)) {
        $result = importBatchForType($type, $batch);
        $imported = $result['rows'] ?? 0;
    }

    return ['imported' => $imported, 'nextOffset' => $nextOffset, 'done' => $done];
}

function importPartsCsv(array $rows): array
{
    $pdo = getPDO();
    $pdo->beginTransaction();
    $count = 0;

    $stmt = $pdo->prepare(
        'INSERT INTO parts (rebrickable_part_id, part_num, name, part_category, part_url)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), part_category = VALUES(part_category), part_url = VALUES(part_url)'
    );

    foreach ($rows as $row) {
        $partId = isset($row['id']) ? (int) $row['id'] : (isset($row['part_id']) ? (int) $row['part_id'] : null);
        $partNum = trim((string) ($row['part_num'] ?? $row['part_no'] ?? ''));
        if ($partNum === '') {
            continue;
        }

        $stmt->execute([
            $partId,
            $partNum,
            trim((string) ($row['name'] ?? '')),
            trim((string) ($row['part_cat_id'] ?? $row['part_cat'] ?? '')),
            trim((string) ($row['part_url'] ?? '')),
        ]);
        $count++;
    }

    $pdo->commit();
    return ['rows' => $count];
}

function importSetsCsv(array $rows): array
{
    $pdo = getPDO();
    $pdo->beginTransaction();
    $count = 0;

    $stmt = $pdo->prepare(
        'INSERT INTO sets (rebrickable_set_num, rebrickable_set_id, name, year, theme, num_parts, image_url)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE rebrickable_set_id = VALUES(rebrickable_set_id), name = VALUES(name), year = VALUES(year), theme = VALUES(theme), num_parts = VALUES(num_parts), image_url = VALUES(image_url)'
    );

    foreach ($rows as $row) {
        $setNum = trim((string) ($row['set_num'] ?? $row['set_no'] ?? ''));
        if ($setNum === '') {
            continue;
        }

        $setId = isset($row['id']) ? (int) $row['id'] : (isset($row['set_id']) ? (int) $row['set_id'] : null);
        $stmt->execute([
            $setNum,
            $setId,
            trim((string) ($row['name'] ?? '')),
            $row['year'] !== null ? (int) $row['year'] : null,
            trim((string) ($row['theme'] ?? $row['theme_id'] ?? '')),
            $row['num_parts'] !== null ? (int) $row['num_parts'] : null,
            trim((string) ($row['img_url'] ?? $row['set_img_url'] ?? $row['image_url'] ?? '')),
        ]);
        $count++;
    }

    $pdo->commit();
    return ['rows' => $count];
}

function importSetPartsCsv(array $rows): array
{
    $pdo = getPDO();
    $pdo->beginTransaction();
    $count = 0;

    $stmtInsert = $pdo->prepare(
        'INSERT INTO set_parts (set_id, part_id, quantity)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
    );

    foreach ($rows as $row) {
        $setNum = trim((string) ($row['set_num'] ?? $row['set_no'] ?? ''));
        $partNum = trim((string) ($row['part_num'] ?? $row['part_no'] ?? ''));
        if ($setNum === '' || $partNum === '') {
            continue;
        }

        $setId = findSetIdByNum($setNum);
        if ($setId === null) {
            $setId = createStubSet($setNum);
        }

        $partId = findPartIdByNum($partNum);
        if ($partId === null) {
            $partId = createStubPart($partNum);
        }

        $quantity = $row['quantity'] !== null ? (int) $row['quantity'] : (int) ($row['qty'] ?? 1);
        $stmtInsert->execute([$setId, $partId, $quantity]);
        $count++;
    }

    $pdo->commit();
    return ['rows' => $count];
}

function importThemesCsv(array $rows): array
{
    $pdo = getPDO();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO themes (theme_id, name, parent_theme_id)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), parent_theme_id = VALUES(parent_theme_id)'
    );
    $count = 0;

    foreach ($rows as $row) {
        $themeId = isset($row['id']) ? (int) $row['id'] : (isset($row['theme_id']) ? (int) $row['theme_id'] : null);
        if ($themeId === null) {
            continue;
        }
        // An empty CSV cell for a top-level theme's parent_id arrives here as
        // '' (a string), not PHP null — "!== null" alone doesn't catch that,
        // and (int) '' silently becomes 0, which then gets stored as if 0
        // were a real theme_id. Trim first and check for '' explicitly.
        $parentIdRaw = trim((string) ($row['parent_id'] ?? ''));
        $stmt->execute([
            $themeId,
            trim((string) ($row['name'] ?? '')),
            $parentIdRaw !== '' ? (int) $parentIdRaw : null,
        ]);
        $count++;
    }
    $pdo->commit();
    return ['rows' => $count];
}

function importColorsCsv(array $rows): array
{
    $pdo = getPDO();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO colors (color_id, name, rgb, is_trans)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), rgb = VALUES(rgb), is_trans = VALUES(is_trans)'
    );
    $count = 0;

    foreach ($rows as $row) {
        $colorId = isset($row['id']) ? (int) $row['id'] : (isset($row['color_id']) ? (int) $row['color_id'] : null);
        if ($colorId === null) {
            continue;
        }
        $stmt->execute([
            $colorId,
            trim((string) ($row['name'] ?? '')),
            trim((string) ($row['rgb'] ?? '')),
            parseCsvBool($row['is_trans'] ?? null),
        ]);
        $count++;
    }
    $pdo->commit();
    return ['rows' => $count];
}

function importPartCategoriesCsv(array $rows): array
{
    $pdo = getPDO();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO part_categories (part_cat_id, name, parent_id)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), parent_id = VALUES(parent_id)'
    );
    $count = 0;

    foreach ($rows as $row) {
        $catId = isset($row['id']) ? (int) $row['id'] : (isset($row['part_cat_id']) ? (int) $row['part_cat_id'] : null);
        if ($catId === null) {
            continue;
        }
        $stmt->execute([
            $catId,
            trim((string) ($row['name'] ?? '')),
            isset($row['parent_id']) ? (int) $row['parent_id'] : null,
        ]);
        $count++;
    }
    $pdo->commit();
    return ['rows' => $count];
}

function importPartRelationshipsCsv(array $rows): array
{
    $pdo = getPDO();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO part_relationships (parent_part_id, child_part_id, relationship_type)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE relationship_type = VALUES(relationship_type)'
    );
    $count = 0;

    foreach ($rows as $row) {
        $parentPartNum = trim((string) ($row['parent_part_num'] ?? ''));
        $childPartNum = trim((string) ($row['child_part_num'] ?? ''));
        if ($parentPartNum === '' || $childPartNum === '') {
            continue;
        }

        $parentPartId = findPartIdByNum($parentPartNum);
        if ($parentPartId === null) {
            $parentPartId = createStubPart($parentPartNum);
        }
        $childPartId = findPartIdByNum($childPartNum);
        if ($childPartId === null) {
            $childPartId = createStubPart($childPartNum);
        }

        $stmt->execute([
            $parentPartId,
            $childPartId,
            trim((string) ($row['rel_type'] ?? '')),
        ]);
        $count++;
    }
    $pdo->commit();
    return ['rows' => $count];
}

function importElementsCsv(array $rows): array
{
    $pdo = getPDO();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO elements (element_id, part_id, color_id, design_id, is_spare, has_feature_image)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE part_id = VALUES(part_id), color_id = VALUES(color_id), design_id = VALUES(design_id), is_spare = VALUES(is_spare), has_feature_image = VALUES(has_feature_image)'
    );
    $count = 0;

    foreach ($rows as $row) {
        $elementId = trim((string) ($row['id'] ?? $row['element_id'] ?? ''));
        if ($elementId === '') {
            continue;
        }

        $partId = null;
        $partNum = trim((string) ($row['part_num'] ?? ''));
        if ($partNum !== '') {
            $partId = findPartIdByNum($partNum);
            if ($partId === null) {
                $partId = createStubPart($partNum);
            }
        } elseif (isset($row['part_id'])) {
            $partId = (int) $row['part_id'];
        }

        $stmt->execute([
            $elementId,
            $partId,
            isset($row['color_id']) ? (int) $row['color_id'] : null,
            isset($row['design_id']) ? (int) $row['design_id'] : null,
            isset($row['is_spare']) ? parseCsvBool($row['is_spare']) : 0,
            isset($row['has_feature_image']) ? parseCsvBool($row['has_feature_image']) : 0,
        ]);
        $count++;
    }
    $pdo->commit();
    return ['rows' => $count];
}

function importMinifigsCsv(array $rows): array
{
    $pdo = getPDO();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO minifigs (fig_num, name, num_parts, image_url)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), num_parts = VALUES(num_parts), image_url = VALUES(image_url)'
    );
    $count = 0;

    foreach ($rows as $row) {
        $figNum = trim((string) ($row['fig_num'] ?? ''));
        if ($figNum === '') {
            continue;
        }
        $stmt->execute([
            $figNum,
            trim((string) ($row['name'] ?? '')),
            $row['num_parts'] !== null && $row['num_parts'] !== '' ? (int) $row['num_parts'] : null,
            trim((string) ($row['img_url'] ?? $row['image_url'] ?? '')),
        ]);
        $count++;
    }
    $pdo->commit();
    return ['rows' => $count];
}

/**
 * Only ever fills bricklink_id where still NULL — never overwrites a value from a
 * live moykubik.ru lookup or one entered manually via the fallback modal.
 */
function importBricklinkMinifigIdsCsv(array $rows): array
{
    $pdo = getPDO();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'UPDATE minifigs SET bricklink_id = ? WHERE fig_num = ? AND bricklink_id IS NULL'
    );
    $count = 0;

    foreach ($rows as $row) {
        $figNum = trim((string) ($row['fig_num'] ?? ''));
        $bricklinkId = trim((string) ($row['bricklink_id'] ?? ''));
        if ($figNum === '' || $bricklinkId === '') {
            continue;
        }
        $stmt->execute([$bricklinkId, $figNum]);
        $count++;
    }
    $pdo->commit();
    return ['rows' => $count];
}

function importInventoriesCsv(array $rows): array
{
    $pdo = getPDO();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO rebrickable_inventories (inventory_id, version, name, set_num, year, theme, num_parts)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE version = VALUES(version), name = VALUES(name), set_num = VALUES(set_num), year = VALUES(year), theme = VALUES(theme), num_parts = VALUES(num_parts)'
    );
    $count = 0;

    foreach ($rows as $row) {
        $inventoryId = isset($row['id']) ? (int) $row['id'] : (isset($row['inventory_id']) ? (int) $row['inventory_id'] : null);
        if ($inventoryId === null) {
            continue;
        }
        $stmt->execute([
            $inventoryId,
            isset($row['version']) ? (int) $row['version'] : null,
            trim((string) ($row['name'] ?? '')),
            trim((string) ($row['set_num'] ?? '')),
            isset($row['year']) ? (int) $row['year'] : null,
            trim((string) ($row['theme'] ?? '')),
            isset($row['num_parts']) ? (int) $row['num_parts'] : null,
        ]);
        $count++;
    }
    $pdo->commit();
    return ['rows' => $count];
}

function importInventoryPartsCsv(array $rows): array
{
    $pdo = getPDO();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO inventory_parts (inventory_id, part_id, color_id, quantity, is_spare, img_url)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), img_url = VALUES(img_url)'
    );
    $count = 0;

    foreach ($rows as $row) {
        $inventoryId = isset($row['inventory_id']) ? (int) $row['inventory_id'] : null;
        if ($inventoryId === null) {
            continue;
        }

        $partId = null;
        $partNum = trim((string) ($row['part_num'] ?? ''));
        if ($partNum !== '') {
            $partId = findPartIdByNum($partNum);
            if ($partId === null) {
                $partId = createStubPart($partNum);
            }
        } elseif (isset($row['part_id'])) {
            $partId = (int) $row['part_id'];
        }

        $stmt->execute([
            $inventoryId,
            $partId,
            isset($row['color_id']) ? (int) $row['color_id'] : null,
            $row['quantity'] !== null ? (int) $row['quantity'] : (int) ($row['qty'] ?? 0),
            parseCsvBool($row['is_spare'] ?? null),
            trim((string) ($row['img_url'] ?? '')),
        ]);
        $count++;
    }
    $pdo->commit();
    return ['rows' => $count];
}

function importInventorySetsCsv(array $rows): array
{
    $pdo = getPDO();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO inventory_sets (inventory_id, set_num, quantity)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
    );
    $count = 0;

    foreach ($rows as $row) {
        $inventoryId = isset($row['inventory_id']) ? (int) $row['inventory_id'] : null;
        if ($inventoryId === null) {
            continue;
        }
        $stmt->execute([
            $inventoryId,
            trim((string) ($row['set_num'] ?? '')),
            $row['quantity'] !== null ? (int) $row['quantity'] : (int) ($row['qty'] ?? 0),
        ]);
        $count++;
    }
    $pdo->commit();
    return ['rows' => $count];
}

function importInventoryMinifigsCsv(array $rows): array
{
    $pdo = getPDO();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO inventory_minifigs (inventory_id, minifig_id, quantity)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
    );
    $count = 0;

    foreach ($rows as $row) {
        $inventoryId = isset($row['inventory_id']) ? (int) $row['inventory_id'] : null;
        if ($inventoryId === null) {
            continue;
        }

        $minifigId = null;
        $figNum = trim((string) ($row['fig_num'] ?? ''));
        if ($figNum !== '') {
            $minifigId = findMinifigIdByFigNum($figNum);
            if ($minifigId === null) {
                $minifigId = createStubMinifig($figNum);
            }
        } elseif (isset($row['minifig_id'])) {
            $minifigId = (int) $row['minifig_id'];
        }

        $stmt->execute([
            $inventoryId,
            $minifigId,
            $row['quantity'] !== null ? (int) $row['quantity'] : (int) ($row['qty'] ?? 0),
        ]);
        $count++;
    }
    $pdo->commit();
    return ['rows' => $count];
}

function findSetIdByNum(string $setNum): ?int
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id FROM sets WHERE rebrickable_set_num = ?');
    $stmt->execute([$setNum]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (int) $value;
}

function findPartIdByNum(string $partNum): ?int
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id FROM parts WHERE part_num = ?');
    $stmt->execute([$partNum]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (int) $value;
}

function createStubSet(string $setNum): int
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO sets (rebrickable_set_num, name) VALUES (?, ?)');
    $stmt->execute([$setNum, 'Unbekanntes Set']);
    return (int) $pdo->lastInsertId();
}

function createStubPart(string $partNum): int
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO parts (part_num, name) VALUES (?, ?)');
    $stmt->execute([$partNum, 'Unbekanntes Teil']);
    return (int) $pdo->lastInsertId();
}

function findMinifigIdByFigNum(string $figNum): ?int
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id FROM minifigs WHERE fig_num = ?');
    $stmt->execute([$figNum]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (int) $value;
}

function createStubMinifig(string $figNum): int
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO minifigs (fig_num, name) VALUES (?, ?)');
    $stmt->execute([$figNum, 'Unbekannte Minifigur']);
    return (int) $pdo->lastInsertId();
}
