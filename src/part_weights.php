<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/parts.php';
require_once __DIR__ . '/bricklink_prices.php';
require_once __DIR__ . '/rebrickable.php';

/**
 * Physical part weight (parts.weight_grams, migration 52) — a manual,
 * user-triggered BrickLink scrape (?page=weight_scan, src/routes/pages.php),
 * not an automatic background sync like the price fetch in
 * src/bricklink_prices.php: per explicit request, only ever fetched for
 * parts that don't have a weight yet, with no recheck-interval throttle —
 * re-running the scan simply retries everything still NULL, including
 * previous failures.
 *
 * Weight is stored once per print family's root (unprinted) part
 * (getPrintRootPartId(), src/parts.php) rather than per exact part_id — live
 * confirmed against the real BrickLink catalog that a print variant's own
 * catalog page (catalogitem.page?P=<print's own bricklink id>) frequently
 * doesn't even resolve (0-byte response for "3024pr0005"), while the base
 * part's page always carries the weight. Every consumer (the scan, the
 * status-bar total, the part-detail display) therefore falls back to the
 * print parent's weight via a LEFT JOIN on part_relationships, same pattern
 * already used for the BrickLink price fallback.
 */

const WEIGHT_SCAN_BATCH_SIZE = 3;
// Real HTTP fetches, not DB queries — a short pause between them within one
// tick is a cheap politeness measure against BrickLink; the per-tick batch
// size (not this delay) is what actually bounds a single request's runtime
// well under any shared-hosting timeout.
const WEIGHT_SCAN_INTER_FETCH_DELAY_MICROSECONDS = 400000;

/**
 * One BrickLink catalog-page fetch, parsing out the "Weight: X g" field
 * (id="item-weight-info", confirmed live against real BrickLink pages —
 * always a plain number with a '.' decimal separator followed by "g", e.g.
 * "13.3g"). Opportunistically also caches parts.bricklink_item_id from the
 * same page's "idItem: <n>" JS assignment when it isn't already known — the
 * exact same page resolveBricklinkPartItemId() (src/bricklink_prices.php)
 * would otherwise need a separate request for later, during the ordinary
 * price sync.
 */
function fetchBricklinkPartWeightGrams(PDO $pdo, int $partId, string $bricklinkPartId): ?float
{
    $url = 'https://www.bricklink.com/v2/catalog/catalogitem.page?P=' . urlencode($bricklinkPartId);
    $html = fetchBricklinkPage($url);
    if ($html === null || $html === '') {
        return null;
    }

    if (preg_match('/idItem:\s*(\d+)/', $html, $itemMatch) === 1) {
        $pdo->prepare('UPDATE parts SET bricklink_item_id = ? WHERE id = ? AND bricklink_item_id IS NULL')
            ->execute([(int) $itemMatch[1], $partId]);
    }

    if (preg_match('/item-weight-info">([\d.]+)g/', $html, $weightMatch) === 1) {
        return (float) $weightMatch[1];
    }
    return null;
}

/**
 * Candidate parts for a weight scan: print-family roots that currently have
 * loose stock (same "loose" scope as computeLoosePartsBricklinkValueTotal()
 * in src/bricklink_prices.php — excludes owned_set locations only) and still
 * have no weight of their own.
 *
 * @return array<int, array{part_id:int, part_num:string, name:string, bricklink_part_id:?string}>
 */
function getPartsNeedingWeightScan(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT DISTINCT rootp.id AS part_id, rootp.part_num, rootp.name, rootp.bricklink_part_id
         FROM storage_items si
         INNER JOIN storage_locations sl ON sl.id = si.location_id
         LEFT JOIN part_relationships pr ON pr.child_part_id = si.part_id AND pr.relationship_type = 'P'
         INNER JOIN parts rootp ON rootp.id = COALESCE(pr.parent_part_id, si.part_id)
         WHERE si.quantity > 0
           AND (sl.location_type IS NULL OR sl.location_type != 'owned_set')
           AND rootp.weight_grams IS NULL
         ORDER BY rootp.part_num"
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['part_id'] = (int) $row['part_id'];
    }
    unset($row);
    return $rows;
}

/**
 * @return array{parts: array, cursor: int, total: int, failures: array}
 */
function initWeightScanState(PDO $pdo): array
{
    $parts = getPartsNeedingWeightScan($pdo);
    return [
        'parts' => $parts,
        'cursor' => 0,
        'total' => count($parts),
        'failures' => [],
    ];
}

function weightScanLogLine(string $message): string
{
    return '[' . formatDate(date('Y-m-d H:i:s'), true) . '] ' . $message;
}

/**
 * One bounded tick — same $_SESSION-state/one-batch-per-request shape as
 * stepBuildSetsScan() (src/build_sets.php), just a much smaller batch since
 * each part here costs a real HTTP round trip to BrickLink instead of a DB
 * query. Every processed part appends 3 log lines on success (fetch
 * started/weight read/saved, matching the exact wording the user specified)
 * or 2 on failure (fetch started/failed) — $state['failures'] accumulates
 * across ticks and is only returned once the whole scan is done, for the
 * results page's separate "couldn't be found" list.
 *
 * @return array{logLines: array<int, string>, processed: int, total: int, done: bool, failures: array}
 */
function stepWeightScan(PDO $pdo, array &$state): array
{
    $cursor = $state['cursor'];
    $total = $state['total'];
    $batchEnd = min($total, $cursor + WEIGHT_SCAN_BATCH_SIZE);

    $logLines = [];
    for ($i = $cursor; $i < $batchEnd; $i++) {
        if ($i > $cursor) {
            usleep(WEIGHT_SCAN_INTER_FETCH_DELAY_MICROSECONDS);
        }
        $part = $state['parts'][$i];
        $logLines[] = weightScanLogLine(t('weight_scan_log_fetching', ['part_num' => $part['part_num'], 'name' => $part['name']]));

        $bricklinkPartId = $part['bricklink_part_id'];
        if ($bricklinkPartId === null || $bricklinkPartId === '') {
            applyBricklinkPartIdBatch($pdo, [$part['part_num']]);
            $refetchStmt = $pdo->prepare('SELECT bricklink_part_id FROM parts WHERE id = ?');
            $refetchStmt->execute([$part['part_id']]);
            $refetched = $refetchStmt->fetchColumn();
            $bricklinkPartId = $refetched !== false ? $refetched : null;
        }

        $weightGrams = $bricklinkPartId !== null
            ? fetchBricklinkPartWeightGrams($pdo, $part['part_id'], (string) $bricklinkPartId)
            : null;

        if ($weightGrams === null) {
            $logLines[] = weightScanLogLine(t('weight_scan_log_failed', ['part_num' => $part['part_num']]));
            $state['failures'][] = ['part_num' => $part['part_num'], 'name' => $part['name']];
            continue;
        }

        $logLines[] = weightScanLogLine(t('weight_scan_log_fetched', ['part_num' => $part['part_num'], 'weight' => formatWeightGrams($weightGrams)]));
        $pdo->prepare('UPDATE parts SET weight_grams = ? WHERE id = ?')->execute([$weightGrams, $part['part_id']]);
        $logLines[] = weightScanLogLine(t('weight_scan_log_saved', ['part_num' => $part['part_num']]));
    }

    $state['cursor'] = $batchEnd;
    $done = $batchEnd >= $total;

    return [
        'logLines' => $logLines,
        'processed' => $batchEnd,
        'total' => $total,
        'done' => $done,
        'failures' => $done ? $state['failures'] : [],
    ];
}

/**
 * Mengengewichtete Gesamtmasse aller losen Bauteile (Statuszeile, index.php)
 * — same "loose" scope as computeLoosePartsBricklinkValueTotal(), and the
 * same print-parent fallback the scan/display use everywhere else. Unlike
 * the BrickLink value sum, damaged stock still counts its full weight here
 * (damaged means "present but imperfect", not "missing" — it's still
 * physically sitting on the shelf).
 */
function computeLooseStockTotalWeightGrams(PDO $pdo): float
{
    $stmt = $pdo->query(
        "SELECT SUM(si.quantity * COALESCE(p.weight_grams, pw.weight_grams)) AS total
         FROM storage_items si
         INNER JOIN storage_locations sl ON sl.id = si.location_id
         INNER JOIN parts p ON p.id = si.part_id
         LEFT JOIN part_relationships pr ON pr.child_part_id = si.part_id AND pr.relationship_type = 'P'
         LEFT JOIN parts pw ON pw.id = pr.parent_part_id
         WHERE (sl.location_type IS NULL OR sl.location_type != 'owned_set')"
    );
    $total = $stmt->fetchColumn();
    return $total !== null ? (float) $total : 0.0;
}

/**
 * Grams displayed in the largest sensible unit — kilograms once >= 1000g —
 * with at most 2 decimal places and no trailing zeros (200g -> "200 g",
 * 13.3g -> "13,3 g", 1234g -> "1,23 kg"), per explicit request.
 */
function formatWeightGrams(float $grams): string
{
    $isKg = $grams >= 1000;
    $value = $isKg ? $grams / 1000 : $grams;
    $unit = $isKg ? 'kg' : 'g';

    $formatted = formatNumber($value, 2);
    $decimalSeparator = getLocale() === 'de' ? ',' : '.';
    if (str_contains($formatted, $decimalSeparator)) {
        $formatted = rtrim(rtrim($formatted, '0'), $decimalSeparator);
    }

    return $formatted . ' ' . $unit;
}
