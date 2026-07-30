<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

function callRebrickableApi(string $path): array
{
    $apiKey = trim((string) getAppSetting('rebrickable_api_key'));
    if ($apiKey === '') {
        throw new RuntimeException('Rebrickable-API-Key ist nicht konfiguriert. Legen Sie ihn in den Anwendungseinstellungen fest.');
    }

    $apiUrl = trim((string) getAppSetting('rebrickable_api_url'));
    if ($apiUrl === '') {
        $apiUrl = 'https://rebrickable.com/api/v3/';
    }
    $url = rtrim($apiUrl, '/') . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Rebrickable-API konnte nicht initialisiert werden.');
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: key ' . $apiKey,
        'Accept: application/json',
    ]);
    // Was 20s — confirmed on the test server that a single stalled request
    // (0 bytes received, Rebrickable just never responding) can eat the
    // whole thing. Every caller here either retries on the next call (the
    // LDraw render tick's per-part lookups, see LDRAW_RENDER_TIME_BUDGET_SECONDS's
    // doc comment) or is a one-off admin action where failing fast and
    // letting the user retry beats a long silent hang either way.
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status >= 400) {
        throw new RuntimeException('Rebrickable-API-Fehler: ' . ($error ?: $status));
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Rebrickable-API-Antwort konnte nicht verarbeitet werden.');
    }

    return $data;
}

function importPartByPartNum(string $partNum): int
{
    $partNum = trim($partNum);
    if ($partNum === '') {
        throw new InvalidArgumentException('Teilnummer darf nicht leer sein.');
    }

    $partData = callRebrickableApi('lego/parts/' . urlencode($partNum) . '/');
    return upsertPart($partData);
}

function upsertPart(array $partData): int
{
    $pdo = getPDO();
    $partNum = $partData['part_num'] ?? '';
    $name = $partData['name'] ?? 'Unbenannt';
    $partId = $partData['part_id'] ?? null;
    $category = $partData['part_cat_id'] ?? null;
    $partUrl = $partData['part_url'] ?? null;

    $stmt = $pdo->prepare(
        'INSERT INTO parts (rebrickable_part_id, part_num, name, part_category, part_url)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            part_category = VALUES(part_category),
            part_url = VALUES(part_url)'
    );
    $stmt->execute([$partId, $partNum, $name, $category, $partUrl]);

    $stmt = $pdo->prepare('SELECT id FROM parts WHERE part_num = ?');
    $stmt->execute([$partNum]);
    $result = $stmt->fetchColumn();
    return $result === false ? 0 : (int) $result;
}

function importSetBySetNum(string $setNum): array
{
    $setNum = trim($setNum);
    if ($setNum === '') {
        throw new InvalidArgumentException('Set-Nummer darf nicht leer sein.');
    }

    $setData = callRebrickableApi('lego/sets/' . urlencode($setNum) . '/');
    $pdo = getPDO();

    $stmt = $pdo->prepare(
        'INSERT INTO sets (rebrickable_set_num, rebrickable_set_id, name, year, theme, num_parts, image_url)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            rebrickable_set_id = VALUES(rebrickable_set_id),
            name = VALUES(name),
            year = VALUES(year),
            theme = VALUES(theme),
            num_parts = VALUES(num_parts),
            image_url = VALUES(image_url)'
    );
    $stmt->execute([
        $setNum,
        $setData['set_id'] ?? null,
        $setData['name'] ?? 'Unbenannt',
        $setData['year'] ?? null,
        $setData['theme'] ?? null,
        $setData['num_parts'] ?? null,
        $setData['set_img_url'] ?? null,
    ]);

    $stmt = $pdo->prepare('SELECT id FROM sets WHERE rebrickable_set_num = ?');
    $stmt->execute([$setNum]);
    $setId = (int) $stmt->fetchColumn();

    importSetParts($setId, $setNum);

    return [
        'id' => $setId,
        'rebrickable_set_num' => $setNum,
        'name' => $setData['name'] ?? 'Unbenannt',
    ];
}

function importSetParts(int $setId, string $setNum): void
{
    $page = 1;
    do {
        $response = callRebrickableApi('lego/sets/' . urlencode($setNum) . '/parts/?page=' . $page);
        $results = $response['results'] ?? [];

        foreach ($results as $item) {
            $partInfo = $item['part'] ?? $item;
            $partId = upsertPart($partInfo);
            $quantity = (int) ($item['quantity'] ?? $item['qty'] ?? 1);

            $pdo = getPDO();
            $stmt = $pdo->prepare(
                'INSERT INTO set_parts (set_id, part_id, quantity)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
            );
            $stmt->execute([$setId, $partId, $quantity]);
        }

        $page++;
    } while (!empty($response['next']));
}
