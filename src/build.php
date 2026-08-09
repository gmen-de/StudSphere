<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/sets.php';
require_once __DIR__ . '/minifigs.php';

/**
 * Catalog minifigs the user could assemble from loose parts stock
 * (storage_items — ordinary bricks and loose minifig parts alike, both live
 * in the same table, see storage_items' own doc comment) — powers the
 * "Bauen" nav dropdown's "Baubare Minifiguren" entry.
 *
 * Two-phase to stay fast against the whole catalog (~80k minifig-inventory
 * rows as of this writing, confirmed via a live timed test — a single
 * candidate query plus a handful of getSetPartsList() calls finishes in
 * ~1s, well inside shared-hosting request timeouts):
 *  1. One SQL query finds candidates whose every non-spare required part+
 *     color has *some* stock (not necessarily enough) — a cheap necessary
 *     condition for buildable > 0 that prunes the ~80k rows down to a
 *     handful of hundred real candidates before any per-figure work starts.
 *  2. Only for those candidates: getSetPartsList() (src/sets.php, same
 *     function action=minifig_detail already uses for a minifig's own BOM)
 *     gives the exact per-part quantities, compared against a stock map
 *     built once upfront — no per-candidate stock query.
 *
 * Stock counts storage_items.quantity minus damaged_quantity (only intact
 * stock is usable) and deliberately excludes spare_quantity (spares aren't
 * "free" stock, same convention getOwnedSetCompleteness() already uses).
 *
 * $buildable = how many complete copies are assembleable right now (the
 * bottleneck part's floor(stock / needed-per-copy) across every required
 * part). $missing_for_next = total individual pieces still needed to push
 * that up by one more copy — more useful than "missing to build one from
 * zero", since $buildable is already folded in.
 *
 * Sorted by BrickLink price (bricklink_price_used — a hand-assembled figure
 * realistically compares to the used market, not factory-sealed "new")
 * descending, unpriced ones last, missing_for_next ascending as tiebreak.
 * $buildable is returned but deliberately not part of the sort — the user
 * wants value first, buildability is just supporting info per row.
 *
 * @return array<int, array{minifig_id:int, fig_num:string, name:?string, thumbnail:?string, buildable:int, missing_for_next:int, bricklink_price_used:?float, bricklink_price_currency:?string, bricklink_price_checked_at:?string}>
 */
function getBuildableMinifigs(PDO $pdo, string $locale = 'en'): array
{
    $stock = [];
    $stockStmt = $pdo->query(
        'SELECT part_id, color_id, SUM(quantity) - SUM(damaged_quantity) AS stock
         FROM storage_items
         GROUP BY part_id, color_id
         HAVING stock > 0'
    );
    foreach ($stockStmt->fetchAll() as $row) {
        $stock[$row['part_id'] . ':' . $row['color_id']] = (int) $row['stock'];
    }
    if (empty($stock)) {
        return [];
    }

    // fig_num = the minifig's own pseudo "set number" for its constituent-
    // parts inventory (see getMinifigInventoryId()'s doc comment) — the
    // INNER JOIN against minifigs.fig_num already scopes this to minifig
    // inventories only, a real Rebrickable set number can never coincide
    // with a "fig-NNNNNN" string.
    $candidateStmt = $pdo->query(
        "SELECT ri.set_num AS fig_num, m.id AS minifig_id, m.name, m.local_image_path AS thumbnail,
                m.bricklink_price_used, m.bricklink_price_currency, m.bricklink_price_checked_at,
                COUNT(DISTINCT CONCAT(ip.part_id,':',c.id)) AS total_pairs,
                COUNT(DISTINCT CASE WHEN si.part_id IS NOT NULL THEN CONCAT(ip.part_id,':',c.id) END) AS matched_pairs
         FROM inventory_parts ip
         INNER JOIN rebrickable_inventories ri ON ri.inventory_id = ip.inventory_id
         INNER JOIN minifigs m ON m.fig_num = ri.set_num
         LEFT JOIN colors c ON c.color_id = ip.color_id
         LEFT JOIN (SELECT DISTINCT part_id, color_id FROM storage_items WHERE quantity > 0) si
                ON si.part_id = ip.part_id AND si.color_id = c.id
         WHERE ip.is_spare = 0
         GROUP BY ri.set_num, m.id, m.name, m.local_image_path,
                  m.bricklink_price_used, m.bricklink_price_currency, m.bricklink_price_checked_at
         HAVING total_pairs = matched_pairs"
    );

    $results = [];
    foreach ($candidateStmt->fetchAll() as $candidate) {
        $inventoryId = getMinifigInventoryId($pdo, $candidate['fig_num']);
        if ($inventoryId === null) {
            continue;
        }
        $parts = getSetPartsList($pdo, $inventoryId, false, $locale);

        $buildable = null;
        foreach ($parts as $part) {
            if ($part['quantity'] <= 0) {
                continue;
            }
            $have = $stock[$part['part_id'] . ':' . $part['color_id']] ?? 0;
            $ratio = intdiv($have, $part['quantity']);
            $buildable = $buildable === null ? $ratio : min($buildable, $ratio);
        }
        $buildable ??= 0;

        $missingForNext = 0;
        foreach ($parts as $part) {
            $have = $stock[$part['part_id'] . ':' . $part['color_id']] ?? 0;
            $missingForNext += max(0, $part['quantity'] * ($buildable + 1) - $have);
        }

        $results[] = [
            'minifig_id' => (int) $candidate['minifig_id'],
            'fig_num' => $candidate['fig_num'],
            'name' => $candidate['name'],
            'thumbnail' => $candidate['thumbnail'],
            'buildable' => $buildable,
            'missing_for_next' => $missingForNext,
            'bricklink_price_used' => $candidate['bricklink_price_used'] !== null ? (float) $candidate['bricklink_price_used'] : null,
            'bricklink_price_currency' => $candidate['bricklink_price_currency'],
            'bricklink_price_checked_at' => $candidate['bricklink_price_checked_at'],
        ];
    }

    usort($results, function (array $a, array $b): int {
        // Unpriced rows (bricklink_price_used === null) always sort last,
        // regardless of which side of the comparison they're on — treated
        // as -INF rather than 0, so a real (if small) price still outranks
        // "unknown".
        $priceA = $a['bricklink_price_used'] ?? -INF;
        $priceB = $b['bricklink_price_used'] ?? -INF;
        return $priceB <=> $priceA ?: $a['missing_for_next'] <=> $b['missing_for_next'];
    });

    return $results;
}
