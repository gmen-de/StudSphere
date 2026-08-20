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

// The 6 checkable defect criteria a manual's condition is derived from (see
// computeInstructionManualGrade()) — column names on instruction_manuals,
// each a plain 0/1. 'is_new' is handled separately: checking it overrides
// all 6 of these to false rather than being one more entry in this list.
const INSTRUCTION_MANUAL_CRITERIA = ['is_holed', 'has_tears', 'is_painted', 'has_stickers', 'is_glued', 'binding_broken'];

/**
 * Derives a school-grade-style condition (1 = best/"sehr gut", 6 = worst/
 * "sehr schlecht") from the 6 checkable defect criteria — confirmed with the
 * user: each checked criterion worsens the grade by exactly one step, 0
 * checked -> 1, floored at 6 once 5 or more are checked. 'is_new' overrides
 * everything to a fixed best grade (1) regardless of the 6 criteria's actual
 * values — addInstructionManual()/updateInstructionManual() also force those
 * 6 to false when is_new is true, so this never actually needs to reconcile
 * a contradictory is_new+criteria combination, but doesn't rely on that.
 *
 * @param array{is_new:bool|int, is_holed?:bool|int, has_tears?:bool|int, is_painted?:bool|int, has_stickers?:bool|int, is_glued?:bool|int, binding_broken?:bool|int} $manual
 * @return array{isNew:bool, grade:int}
 */
function computeInstructionManualGrade(array $manual): array
{
    if (!empty($manual['is_new'])) {
        return ['isNew' => true, 'grade' => 1];
    }
    $count = 0;
    foreach (INSTRUCTION_MANUAL_CRITERIA as $criterion) {
        if (!empty($manual[$criterion])) {
            $count++;
        }
    }
    return ['isNew' => false, 'grade' => min(6, $count + 1)];
}

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
 * $isNew, when true, forces all 6 criteria to false regardless of what's
 * passed in $criteria — server-side enforcement (not just the add/edit
 * form's UI disabling them) so a crafted request can't store a
 * contradictory is_new+defects combination.
 *
 * @param array<string, bool> $criteria keyed by INSTRUCTION_MANUAL_CRITERIA
 */
function normalizeInstructionManualCriteria(bool $isNew, array $criteria): array
{
    $normalized = [];
    foreach (INSTRUCTION_MANUAL_CRITERIA as $criterion) {
        $normalized[$criterion] = $isNew ? false : !empty($criteria[$criterion]);
    }
    return $normalized;
}

/**
 * Adds one physical booklet copy. No storage_movements audit row — same
 * reasoning as addMinifigStock(): that log's schema is part-specific
 * (part_id/color_id), and instance-based storage doesn't write there either.
 */
function addInstructionManual(int $locationId, int $setId, bool $isNew, array $criteria, ?string $notes): int
{
    $pdo = getPDO();
    $c = normalizeInstructionManualCriteria($isNew, $criteria);
    $stmt = $pdo->prepare(
        'INSERT INTO instruction_manuals (location_id, set_id, is_new, is_holed, has_tears, is_painted, has_stickers, is_glued, binding_broken, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $locationId, $setId, $isNew ? 1 : 0,
        $c['is_holed'] ? 1 : 0, $c['has_tears'] ? 1 : 0, $c['is_painted'] ? 1 : 0,
        $c['has_stickers'] ? 1 : 0, $c['is_glued'] ? 1 : 0, $c['binding_broken'] ? 1 : 0,
        $notes,
    ]);
    return (int) $pdo->lastInsertId();
}

function updateInstructionManual(int $id, bool $isNew, array $criteria, ?string $notes): void
{
    $pdo = getPDO();
    $c = normalizeInstructionManualCriteria($isNew, $criteria);
    $pdo->prepare(
        'UPDATE instruction_manuals SET is_new = ?, is_holed = ?, has_tears = ?, is_painted = ?, has_stickers = ?, is_glued = ?, binding_broken = ?, notes = ? WHERE id = ?'
    )->execute([
        $isNew ? 1 : 0,
        $c['is_holed'] ? 1 : 0, $c['has_tears'] ? 1 : 0, $c['is_painted'] ? 1 : 0,
        $c['has_stickers'] ? 1 : 0, $c['is_glued'] ? 1 : 0, $c['binding_broken'] ? 1 : 0,
        $notes, $id,
    ]);
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
 * Casts the raw DB row's int flags (0/1) to bool and attaches the derived
 * isNew/grade pair (computeInstructionManualGrade()) — shared by every
 * reader below so a row always carries both the raw criteria (for
 * pre-filling the edit form's checkboxes) and the already-computed grade
 * (for the tile badge / detail view), without every caller re-deriving it.
 */
function hydrateInstructionManualRow(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['location_id'] = (int) $row['location_id'];
    $row['set_id'] = (int) $row['set_id'];
    $row['is_new'] = (bool) $row['is_new'];
    foreach (INSTRUCTION_MANUAL_CRITERIA as $criterion) {
        $row[$criterion] = (bool) $row[$criterion];
    }
    $graded = computeInstructionManualGrade($row);
    $row['grade'] = $graded['grade'];
    return $row;
}

/**
 * @return ?array{id:int, location_id:int, set_id:int, is_new:bool, is_holed:bool, has_tears:bool, is_painted:bool, has_stickers:bool, is_glued:bool, binding_broken:bool, grade:int, notes:?string, set_num:string, set_name:string, thumbnail:?string}
 */
function getInstructionManualById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT im.id, im.location_id, im.set_id, im.is_new, im.is_holed, im.has_tears, im.is_painted, im.has_stickers, im.is_glued, im.binding_broken, im.notes,
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
    return hydrateInstructionManualRow($row);
}

/**
 * Every manual instance stored anywhere under $locationId — itself plus
 * every descendant location, recursively (mirrors the minifig half of
 * getLocationContentRecursive()). Raw rows, no percent_complete yet — see
 * getInstructionManualTilesForLocation() for that.
 *
 * @return array<int, array{id:int, location_id:int, set_id:int, is_new:bool, is_holed:bool, has_tears:bool, is_painted:bool, has_stickers:bool, is_glued:bool, binding_broken:bool, grade:int, notes:?string, set_num:string, set_name:string, thumbnail:?string}>
 */
function getInstructionManualsForLocation(PDO $pdo, int $locationId): array
{
    $ids = getLocationSubtreeIds($locationId);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT im.id, im.location_id, im.set_id, im.is_new, im.is_holed, im.has_tears, im.is_painted, im.has_stickers, im.is_glued, im.binding_broken, im.notes,
                s.rebrickable_set_num AS set_num, s.name AS set_name, s.local_image_path AS thumbnail,
                s.bricklink_instructions_price_new, s.bricklink_instructions_price_used, s.bricklink_instructions_price_currency
         FROM instruction_manuals im
         INNER JOIN sets s ON s.id = im.set_id
         WHERE im.location_id IN ($placeholders)
         ORDER BY s.name, im.id"
    );
    $stmt->execute($ids);
    $rows = array_map('hydrateInstructionManualRow', $stmt->fetchAll());
    foreach ($rows as &$row) {
        $row['bricklink_instructions_price_new'] = $row['bricklink_instructions_price_new'] !== null ? (float) $row['bricklink_instructions_price_new'] : null;
        $row['bricklink_instructions_price_used'] = $row['bricklink_instructions_price_used'] !== null ? (float) $row['bricklink_instructions_price_used'] : null;
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
 * @return array<int, array{id:int, location_id:int, set_id:int, is_new:bool, grade:int, notes:?string, set_num:string, set_name:string, thumbnail:?string, percent_complete:?int, bricklink_instructions_price_new:?float, bricklink_instructions_price_used:?float, bricklink_instructions_price_currency:?string}>
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

