<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

/**
 * Best-effort lookup of a minifig's BrickLink item number from moykubik.ru
 * (a Russian LEGO parts shop whose own SKUs happen to follow BrickLink's
 * numbering, shown on each minifig's page as "Артикул: {id}") —
 * Rebrickable's API has no BrickLink minifig mapping at all, a confirmed,
 * admitted omission (see
 * https://forum.rebrickable.com/t/how-do-i-map-bricklink-id-to-rebrickable-id-for-minifigs-via-api-and-in-bulk-operations/172669),
 * and BrickLink's own API doesn't accept a Rebrickable figure number as a
 * lookup key either.
 *
 * Deliberately NOT called for every minifig up front, or on a schedule —
 * only ever from getOrFetchBricklinkMinifigId(), which checks
 * minifigs.bricklink_id first and only reaches this when that column is
 * still NULL. A self-hosted install of this app therefore makes at most
 * one request per minifig it actually ever needs (i.e. one that shows up
 * fully missing in someone's own collection), never a bulk scrape of the
 * whole catalog — moykubik.ru is a small shop, not a dedicated API, and
 * that distinction matters.
 *
 * Returns null on any failure (network, not found, unexpected page shape)
 * — the caller falls back to asking the user to paste the id in manually.
 */
function fetchBricklinkMinifigIdFromMoykubik(string $figNum): ?string
{
    $ch = curl_init('https://moykubik.ru/minifigs/' . urlencode($figNum));
    if ($ch === false) {
        return null;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_ENCODING, ''); // accept + auto-decode gzip/deflate
    $html = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($html) || $status >= 400) {
        return null;
    }
    if (preg_match('/Артикул:\s*([A-Za-z0-9]+)/u', $html, $matches)) {
        return $matches[1];
    }
    return null;
}

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

/**
 * Fills colors.bricklink_color_id/brickowl_color_id/ldraw_color_id from
 * Rebrickable's own external_ids mapping — not available in the bulk CSV
 * downloads the main import uses (downloadAndImportRebrickableData()), only
 * via this REST endpoint. Called once at the end of every Rebrickable data
 * update rather than driven by its own tick: all ~275 colors fit in a
 * single API page and the whole call completes in well under a second
 * (measured). ldraw_color_id in particular replaces
 * matchLdrawColorCode()'s RGB-nearest-neighbor guess (src/ldraw.php) with
 * an authoritative mapping wherever Rebrickable has one — see that
 * function's doc comment for why it still has to stay as a fallback.
 *
 * A color can have at most one BrickLink id and, among real (non-sentinel)
 * colors, at most one BrickOwl id (verified against a full color dump
 * before building this). LDraw is the one exception: a handful of colors
 * list a second, material-variant id after the real one (e.g. Black's
 * ext_ids are [0, 256], where 256 is "Rubber_Black") — the first id is
 * consistently the actual color, which is why only ext_ids[0] is ever
 * taken for any of the three, not just LDraw. Many niche colors (Duplo/
 * Fabuland/HO-scale/...) legitimately have no BrickLink/BrickOwl/LDraw
 * counterpart at all; those stay NULL, which is correct, not a failure.
 *
 * Best-effort: no API key configured, or the API call itself fails, just
 * means this optional enrichment is skipped — it must never fail the
 * surrounding Rebrickable CSV update, which doesn't need an API key at all.
 *
 * @return array{updated: int, skipped: bool}
 */
function syncExternalColorIds(): array
{
    if (trim((string) getAppSetting('rebrickable_api_key')) === '') {
        return ['updated' => 0, 'skipped' => true];
    }

    try {
        $response = callRebrickableApi('lego/colors/?page_size=1000');
    } catch (Throwable $e) {
        return ['updated' => 0, 'skipped' => true];
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('UPDATE colors SET bricklink_color_id = ?, brickowl_color_id = ?, ldraw_color_id = ? WHERE color_id = ?');

    $updated = 0;
    foreach ($response['results'] ?? [] as $color) {
        $externalIds = $color['external_ids'] ?? [];
        $brickLinkIds = $externalIds['BrickLink']['ext_ids'] ?? [];
        $brickOwlIds = $externalIds['BrickOwl']['ext_ids'] ?? [];
        $ldrawIds = $externalIds['LDraw']['ext_ids'] ?? [];
        $brickLinkId = !empty($brickLinkIds) ? (int) $brickLinkIds[0] : null;
        $brickOwlId = !empty($brickOwlIds) ? (int) $brickOwlIds[0] : null;
        $ldrawColorId = !empty($ldrawIds) ? (int) $ldrawIds[0] : null;
        if ($brickLinkId === null && $brickOwlId === null && $ldrawColorId === null) {
            continue;
        }
        $stmt->execute([$brickLinkId, $brickOwlId, $ldrawColorId, $color['id']]);
        if ($stmt->rowCount() > 0) {
            $updated++;
        }
    }

    return ['updated' => $updated, 'skipped' => false];
}

// Client-enforced, not server-enforced: the browser paces one batch per tick
// (see renderOwnedSetBricklinkModal()'s sync progress modal in
// src/owned_sets.php), waiting at least 1s between requests itself, so the
// server side here never needs to sleep() or budget its own time — each tick
// is just one bounded API call plus a handful of indexed UPDATEs.
const REBRICKABLE_PART_BATCH_SIZE = 50;

/**
 * The part_nums (deduped, already-missing-only) a "BrickLink XML" export still
 * needs to resolve — the browser uses this to build its own batch plan and
 * drive applyBricklinkPartIdBatch() one tick at a time. Cheap: only a SELECT,
 * no API calls.
 */
function getPartNumsMissingBricklinkId(PDO $pdo, array $partNums): array
{
    $partNums = array_values(array_unique(array_filter($partNums, fn ($p) => $p !== null && $p !== '')));
    if (empty($partNums)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($partNums), '?'));
    $stmt = $pdo->prepare("SELECT part_num FROM parts WHERE part_num IN ($placeholders) AND bricklink_part_id IS NULL");
    $stmt->execute($partNums);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Unlike minifigs, Rebrickable's own part API does map to BrickLink IDs
 * (external_ids.BrickLink) — official, and per the API docs' own "Performance
 * Tips" meant to be queried in batches (part_nums=a,b,c) rather than one request
 * per part. One call to this function is exactly one Rebrickable API request —
 * the caller (action=owned_set_bricklink_part_sync_tick) is responsible for
 * capping $batch at REBRICKABLE_PART_BATCH_SIZE and for pacing calls roughly
 * 1/sec, since that's a browser-side loop, not something this function can
 * enforce on its own. Only ever fills parts.bricklink_part_id where still
 * NULL — a part already resolved by an earlier export is never re-queried.
 *
 * Best-effort like syncExternalColorIds(): a failed batch just leaves those
 * parts to fall back to their Rebrickable part_num in the XML (correct for
 * most parts anyway — only a minority actually differ between the two
 * catalogs, e.g. Rebrickable's "3070b" is BrickLink's "3070").
 *
 * @return int Number of parts updated by this one batch.
 */
function applyBricklinkPartIdBatch(PDO $pdo, array $batch): int
{
    $batch = array_values(array_unique(array_filter($batch, fn ($p) => $p !== null && $p !== '')));
    if (empty($batch) || trim((string) getAppSetting('rebrickable_api_key')) === '') {
        return 0;
    }

    try {
        $response = callRebrickableApi('lego/parts/?part_nums=' . urlencode(implode(',', $batch)) . '&inc_part_details=1&page_size=' . count($batch));
    } catch (Throwable $e) {
        return 0;
    }

    $updateStmt = $pdo->prepare('UPDATE parts SET bricklink_part_id = ? WHERE part_num = ? AND bricklink_part_id IS NULL');
    $updated = 0;
    foreach ($response['results'] ?? [] as $part) {
        $blId = $part['external_ids']['BrickLink'][0] ?? null;
        $partNum = $part['part_num'] ?? null;
        if ($blId === null || $partNum === null) {
            continue;
        }
        $updateStmt->execute([(string) $blId, $partNum]);
        if ($updateStmt->rowCount() > 0) {
            $updated++;
        }
    }
    return $updated;
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
