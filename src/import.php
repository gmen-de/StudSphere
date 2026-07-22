<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

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

function readCsvRows(string $filePath): array
{
    $rows = [];
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
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count($row) === 1 && $row[0] === null) {
            continue;
        }
        $rows[] = array_combine($headers, $row) ?: [];
    }

    fclose($handle);
    return $rows;
}

function importCsv(string $tmpFile, string $type): array
{
    $rows = readCsvRows($tmpFile);
    if (empty($rows)) {
        throw new RuntimeException('Keine Daten in der CSV-Datei gefunden.');
    }

    switch ($type) {
        case 'parts':
            return importPartsCsv($rows);
        case 'sets':
            return importSetsCsv($rows);
        case 'set_parts':
            return importSetPartsCsv($rows);
        default:
            throw new InvalidArgumentException('Unbekannter Importtyp: ' . $type);
    }
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
            trim((string) ($row['set_img_url'] ?? $row['image_url'] ?? '')),
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
