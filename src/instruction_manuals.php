<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/sets.php';

/**
 * "Bauanleitungen" domain logic — a dedicated storage subtree
 * (location_type='instructions_root', migration 43) for cataloging physical
 * LEGO instruction booklets, separate from loose-parts/minifig storage.
 * Unlike Pick Lager (location_type='pick_lager_root'), whose children are
 * only ever created programmatically, this root's children are freely
 * created by the user through the normal Location Explorer UI and behave as
 * fully ordinary locations — every location inside the subtree is dedicated
 * exclusively to instruction manuals (enforced app-side via
 * isLocationInInstructionsSubtree(), never mixed with storage_items/
 * minifig_storage_items).
 *
 * instruction_manuals mirrors minifig_storage_items' shape: one row per
 * physical booklet copy (no quantity column to upsert onto), since two
 * copies of the same set's manual can be in different condition.
 */

const INSTRUCTION_MANUAL_CONDITION_GRADES = ['mint', 'near_mint', 'good', 'fair', 'poor'];

/**
 * Finds the single "Bauanleitungen" root (location_type='instructions_root',
 * created once by migration 43 / installDatabase()) — same find-by-marker
 * idiom as getPickLagerRootId() (src/pick_lists.php).
 */
function getInstructionsRootId(PDO $pdo): ?int
{
    $stmt = $pdo->query("SELECT id FROM storage_locations WHERE location_type = 'instructions_root' LIMIT 1");
    $id = $stmt->fetchColumn();
    return $id !== false ? (int) $id : null;
}

/**
 * True if $locationId is the instructions root itself or anywhere beneath
 * it. Built on getStorageLocationAncestors() (src/storage.php), which
 * returns the ancestor chain with the location itself as the last entry —
 * so a plain scan for 'instructions_root' anywhere in that chain covers
 * both cases in one pass. This is the one primitive every other
 * add/move/content-routing decision in this file is built on.
 */
function isLocationInInstructionsSubtree(PDO $pdo, int $locationId): bool
{
    foreach (getStorageLocationAncestors($locationId) as $ancestor) {
        if ($ancestor['location_type'] === 'instructions_root') {
            return true;
        }
    }
    return false;
}

/**
 * Adds one physical booklet copy. No storage_movements audit row — same
 * reasoning as addMinifigStock(): that log's schema is part-specific
 * (part_id/color_id), and instance-based storage doesn't write there either.
 */
function addInstructionManual(int $locationId, int $setId, string $conditionGrade, ?string $notes): int
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'INSERT INTO instruction_manuals (location_id, set_id, condition_grade, notes) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$locationId, $setId, $conditionGrade, $notes]);
    return (int) $pdo->lastInsertId();
}

function updateInstructionManual(int $id, string $conditionGrade, ?string $notes): void
{
    $pdo = getPDO();
    $pdo->prepare('UPDATE instruction_manuals SET condition_grade = ?, notes = ? WHERE id = ?')
        ->execute([$conditionGrade, $notes, $id]);
}

/**
 * Moves one specific manual instance to a different location — plain
 * UPDATE by id, mirrors moveMinifigStorageItemInstance(). Callers are
 * responsible for validating the target is inside the instructions
 * subtree first (see action=move_instruction_manual, src/routes/actions.php)
 * — a manual filed outside it would become invisible, since the
 * location_content response branches on isLocationInInstructionsSubtree().
 */
function moveInstructionManual(int $id, int $toLocationId): void
{
    $pdo = getPDO();
    $pdo->prepare('UPDATE instruction_manuals SET location_id = ? WHERE id = ?')->execute([$toLocationId, $id]);
}

function deleteInstructionManual(int $id): void
{
    $pdo = getPDO();
    $pdo->prepare('DELETE FROM instruction_manuals WHERE id = ?')->execute([$id]);
}

/**
 * @return ?array{id:int, location_id:int, set_id:int, condition_grade:string, notes:?string, set_num:string, set_name:string, thumbnail:?string}
 */
function getInstructionManualById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT im.id, im.location_id, im.set_id, im.condition_grade, im.notes,
                s.rebrickable_set_num AS set_num, s.name AS set_name, s.local_image_path AS thumbnail
         FROM instruction_manuals im
         INNER JOIN sets s ON s.id = im.set_id
         WHERE im.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['location_id'] = (int) $row['location_id'];
    $row['set_id'] = (int) $row['set_id'];
    return $row;
}

/**
 * Every manual instance stored anywhere under $locationId — itself plus
 * every descendant location, recursively (mirrors the minifig half of
 * getLocationContentRecursive()). Raw rows, no percent_complete yet — see
 * getInstructionManualTilesForLocation() for that.
 *
 * @return array<int, array{id:int, location_id:int, set_id:int, condition_grade:string, notes:?string, set_num:string, set_name:string, thumbnail:?string}>
 */
function getInstructionManualsForLocation(PDO $pdo, int $locationId): array
{
    $ids = getLocationSubtreeIds($locationId);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT im.id, im.location_id, im.set_id, im.condition_grade, im.notes,
                s.rebrickable_set_num AS set_num, s.name AS set_name, s.local_image_path AS thumbnail
         FROM instruction_manuals im
         INNER JOIN sets s ON s.id = im.set_id
         WHERE im.location_id IN ($placeholders)
         ORDER BY s.name, im.id"
    );
    $stmt->execute($ids);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['location_id'] = (int) $row['location_id'];
        $row['set_id'] = (int) $row['set_id'];
    }
    unset($row);
    return $rows;
}

/**
 * Wraps getInstructionManualsForLocation() with a live percent_complete per
 * row — "how much of this set's BOM do I currently have in loose stock",
 * via getSetInventorySummary() (src/sets.php). Resolved once per DISTINCT
 * set (not per instance): two manual copies of the same set share one
 * lookup, since the percentage only depends on the set, not the specific
 * booklet. percent_complete is null when the set can't be resolved to a
 * catalog inventory (no rebrickable_inventories row) — callers render no
 * badge in that case rather than a misleading 0%.
 *
 * @return array<int, array{id:int, location_id:int, set_id:int, condition_grade:string, notes:?string, set_num:string, set_name:string, thumbnail:?string, percent_complete:?int}>
 */
function getInstructionManualTilesForLocation(PDO $pdo, int $locationId, string $locale): array
{
    $rows = getInstructionManualsForLocation($pdo, $locationId);

    $percentBySetId = [];
    foreach ($rows as $row) {
        $setId = $row['set_id'];
        if (array_key_exists($setId, $percentBySetId)) {
            continue;
        }
        $inventoryId = getSetInventoryId($pdo, $row['set_num']);
        if ($inventoryId === null) {
            $percentBySetId[$setId] = null;
            continue;
        }
        $summary = getSetInventorySummary($pdo, $inventoryId, $locale);
        $percentBySetId[$setId] = $summary['total_nominal'] > 0
            ? (int) round($summary['total_actual'] / $summary['total_nominal'] * 100)
            : null;
    }

    foreach ($rows as &$row) {
        $row['percent_complete'] = $percentBySetId[$row['set_id']];
    }
    unset($row);

    return $rows;
}

/**
 * Full needed-vs-available parts breakdown for one set's inventory — same
 * getSetPartsList()+getLooseStockMap() composition as
 * getSetAvailablePartsForPickList() (src/pick_lists.php), but deliberately
 * does NOT discard zero-availability rows: the manual detail modal's
 * "Bauteile im Lager" tab needs to show what's missing, not just what's
 * pickable.
 *
 * @return array<int, array{part_id:int, color_id:?int, nominal_quantity:int, available_quantity:int, part_num:string, name:string, color_name:?string, thumbnail:?string}>
 */
function getInstructionManualPartsBreakdown(PDO $pdo, int $inventoryId, string $locale): array
{
    $looseStock = getLooseStockMap($pdo);
    $result = [];
    foreach (getSetPartsList($pdo, $inventoryId, false, $locale) as $part) {
        if ($part['color_id'] === null || $part['quantity'] <= 0) {
            continue;
        }
        $available = $looseStock[$part['part_id'] . ':' . $part['color_id']] ?? 0;
        $result[] = [
            'part_id' => $part['part_id'],
            'color_id' => $part['color_id'],
            'nominal_quantity' => $part['quantity'],
            'available_quantity' => $available,
            'part_num' => $part['part_num'],
            'name' => $part['name'],
            'color_name' => $part['color_name'],
            'thumbnail' => $part['ldraw_thumbnail'] ?? $part['thumbnail'] ?? $part['remote_thumbnail'] ?? null,
        ];
    }
    return $result;
}
