<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/sets.php';

/**
 * "Bauanleitungen" domain logic — a dedicated storage subtree
 * (location_type='instructions_root', migration 43) for cataloging physical
 * LEGO instruction booklets, separate from loose-parts/minifig storage.
 * Every location inside the subtree is dedicated exclusively to instruction
 * manuals (enforced app-side via isLocationInInstructionsSubtree(), never
 * mixed with storage_items/minifig_storage_items).
 *
 * Unlike the feature's first iteration, the root's children are no longer
 * freely user-created — per explicit follow-up request, they're fully
 * auto-managed "virtual" locations, one per distinct set theme
 * (location_type='instructions_theme', migration 45), auto-created on first
 * use (getOrCreateInstructionsThemeLocation()) and auto-deleted once empty
 * (pruneEmptyInstructionsThemeLocation()). A manual's location is always
 * derived from its own set's theme — there is deliberately no "move to a
 * different location" operation anymore (a manual can only ever "move" by
 * being deleted and re-added under a different set).
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

// Sentinel theme_id (real Rebrickable theme ids start at 1) for sets with no
// theme at all (s.theme IS NULL) — grouped into one fallback location rather
// than one-off per set. Name is a hardcoded German literal, not i18n'd, same
// convention as 'Bauanleitungen'/'Pick Lager' themselves — unlike real theme
// names (sourced from Rebrickable, always English), this one's app-authored.
const INSTRUCTIONS_THEME_FALLBACK_ID = 0;
const INSTRUCTIONS_THEME_FALLBACK_NAME = 'Ohne Thema';

/**
 * $themeId's ancestor chain, top-level first down to (but not including)
 * $themeId's own immediate parent — e.g. for "9V" (a child of "Train", a
 * top-level theme) this returns [{id: Train's id, name: 'Train'}]. Empty for
 * a theme that's already top-level itself. Walks themes.parent_theme_id one
 * row at a time (not a recursive CTE) for the same shared-hosting MariaDB-
 * version reason as getStorageLocationPath() (src/storage.php).
 *
 * @return array<int, array{theme_id:int, name:string}>
 */
function getThemeAncestorChain(PDO $pdo, int $themeId): array
{
    $stmt = $pdo->prepare('SELECT parent_theme_id, name FROM themes WHERE theme_id = ?');
    $chain = [];
    $current = $themeId;
    $guard = 0;
    while ($guard++ < 20) {
        $stmt->execute([$current]);
        $row = $stmt->fetch();
        if ($row === false || $row['parent_theme_id'] === null) {
            break;
        }
        $parentId = (int) $row['parent_theme_id'];
        $parentStmt = $pdo->prepare('SELECT name FROM themes WHERE theme_id = ?');
        $parentStmt->execute([$parentId]);
        $parentName = $parentStmt->fetchColumn();
        if ($parentName === false) {
            break;
        }
        array_unshift($chain, ['theme_id' => $parentId, 'name' => $parentName]);
        $current = $parentId;
    }
    return $chain;
}

/**
 * Finds (or creates, on first use) one specific theme location directly
 * under $parentLocationId — the single-level primitive
 * getOrCreateInstructionsThemeLocation() below chains once per level of a
 * theme's ancestor path. find-by-marker on (location_type, theme_id) alone
 * (not also parent_id): theme_id is globally unique across the whole table
 * (storage_locations.theme_id's UNIQUE index), so once a theme's location
 * exists anywhere it's simply reused as-is, regardless of which caller
 * (a manual filed directly under it, or a deeper theme needing it as an
 * ancestor) is asking — this is what lets the same "Train" folder serve
 * both roles without extra bookkeeping.
 *
 * Two concurrent requests racing to create the very first location for the
 * same theme both pass the SELECT below before either INSERTs (confirmed
 * live — migration 46's own doc comment). The UNIQUE index turns the loser's
 * INSERT into a catchable integrity-constraint violation rather than a
 * silent duplicate: it just re-queries and gets the winner's row instead.
 */
function findOrCreateInstructionsLocationAtParent(PDO $pdo, int $parentLocationId, int $themeId, string $themeName): int
{
    $stmt = $pdo->prepare("SELECT id FROM storage_locations WHERE location_type = 'instructions_theme' AND theme_id = ? LIMIT 1");
    $stmt->execute([$themeId]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    try {
        $insert = $pdo->prepare(
            "INSERT INTO storage_locations (parent_id, name, location_type, theme_id) VALUES (?, ?, 'instructions_theme', ?)"
        );
        $insert->execute([$parentLocationId, $themeName, $themeId]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        if ((int) $e->getCode() !== 23000) {
            throw $e;
        }
        $stmt->execute([$themeId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw $e;
        }
        return (int) $id;
    }
}

/**
 * Finds (or creates, on first use) the "virtual" per-theme location a manual
 * with the given theme belongs in — nested onto the theme's FULL Rebrickable
 * ancestor path (e.g. "Bauanleitungen > Train > 9V", not a flat "9V"
 * directly under the root), since the leaf theme name alone can be
 * ambiguous out of context (confirmed with the user, using exactly this
 * "9V"/"Train" example). $themeId/$themeName should be
 * INSTRUCTIONS_THEME_FALLBACK_ID/_NAME for a set with no theme at all — that
 * one's always a flat child of the root, it has no ancestor chain to walk.
 */
function getOrCreateInstructionsThemeLocation(PDO $pdo, int $themeId, string $themeName): int
{
    $rootId = getInstructionsRootId($pdo);
    if ($rootId === null) {
        throw new RuntimeException('Instructions root location is missing — was migration 43 applied?');
    }

    $parentLocationId = $rootId;
    if ($themeId !== INSTRUCTIONS_THEME_FALLBACK_ID) {
        foreach (getThemeAncestorChain($pdo, $themeId) as $ancestor) {
            $parentLocationId = findOrCreateInstructionsLocationAtParent($pdo, $parentLocationId, $ancestor['theme_id'], $ancestor['name']);
        }
    }
    return findOrCreateInstructionsLocationAtParent($pdo, $parentLocationId, $themeId, $themeName);
}

/**
 * Deletes $locationId if (and only if) it's an instructions_theme location
 * that no longer holds any manuals AND has no child locations of its own
 * (the latter matters now that a theme folder can double as an ancestor for
 * a deeper theme — e.g. "Train" must survive emptying out even while "9V"
 * still lives inside it) — then repeats one level up, since removing a leaf
 * can leave its own parent newly empty-and-childless too. Called after every
 * delete (and, in migration 45, every reassignment) so the tree only ever
 * shows themes that currently have something in them, directly or via a
 * descendant. Safe to call on any location id: the location_type/NOT EXISTS
 * guards mean each step is a silent no-op (and the walk simply stops) for
 * anything not eligible — a still-occupied theme, one that still has
 * children, the root itself, or a non-instructions location.
 */
function pruneEmptyInstructionsThemeLocation(PDO $pdo, int $locationId): void
{
    $stmt = $pdo->prepare(
        "SELECT sl.parent_id FROM storage_locations sl
         WHERE sl.id = ? AND sl.location_type = 'instructions_theme'
           AND NOT EXISTS (SELECT 1 FROM instruction_manuals im WHERE im.location_id = sl.id)
           AND NOT EXISTS (SELECT 1 FROM storage_locations child WHERE child.parent_id = sl.id)"
    );
    $current = $locationId;
    $guard = 0;
    while ($guard++ < 20) {
        $stmt->execute([$current]);
        $parentId = $stmt->fetchColumn();
        if ($parentId === false) {
            break;
        }
        $pdo->prepare('DELETE FROM storage_locations WHERE id = ?')->execute([$current]);
        if ($parentId === null) {
            break;
        }
        $current = (int) $parentId;
    }
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
 * Adds one physical booklet copy, filed automatically into its set's own
 * theme location (auto-created on first use — see
 * getOrCreateInstructionsThemeLocation()). $set is the getSetById() row for
 * $setId's catalog set — the caller already has it (to validate the set
 * exists before calling this), so it's passed in rather than re-fetched.
 * No storage_movements audit row — same reasoning as addMinifigStock():
 * that log's schema is part-specific (part_id/color_id), and instance-based
 * storage doesn't write there either.
 */
function addInstructionManual(array $set, bool $isNew, array $criteria, ?string $notes): int
{
    $pdo = getPDO();
    $themeId = $set['theme_id'] ?? INSTRUCTIONS_THEME_FALLBACK_ID;
    $themeName = $set['theme_id'] !== null ? $set['theme_name'] : INSTRUCTIONS_THEME_FALLBACK_NAME;
    $locationId = getOrCreateInstructionsThemeLocation($pdo, $themeId, $themeName);

    $c = normalizeInstructionManualCriteria($isNew, $criteria);
    $stmt = $pdo->prepare(
        'INSERT INTO instruction_manuals (location_id, set_id, is_new, is_holed, has_tears, is_painted, has_stickers, is_glued, binding_broken, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $locationId, $set['id'], $isNew ? 1 : 0,
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
 * Removes one manual instance and, if that was the last one filed under its
 * theme location, that now-empty virtual location too (see
 * pruneEmptyInstructionsThemeLocation()).
 */
function deleteInstructionManual(int $id): void
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT location_id FROM instruction_manuals WHERE id = ?');
    $stmt->execute([$id]);
    $locationId = $stmt->fetchColumn();

    $pdo->prepare('DELETE FROM instruction_manuals WHERE id = ?')->execute([$id]);

    if ($locationId !== false) {
        pruneEmptyInstructionsThemeLocation($pdo, (int) $locationId);
    }
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
 * @return array<int, array{id:int, location_id:int, set_id:int, is_new:bool, is_holed:bool, has_tears:bool, is_painted:bool, has_stickers:bool, is_glued:bool, binding_broken:bool, grade:int, notes:?string, set_num:string, set_name:string, thumbnail:?string, bricklink_instructions_price_new:?float, bricklink_instructions_price_used:?float, bricklink_instructions_price_currency:?string, set_bricklink_price_new:?float, set_bricklink_price_used:?float, set_bricklink_price_currency:?string}>
 */
function getInstructionManualsForLocation(PDO $pdo, int $locationId): array
{
    $ids = getLocationSubtreeIds($locationId);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT im.id, im.location_id, im.set_id, im.is_new, im.is_holed, im.has_tears, im.is_painted, im.has_stickers, im.is_glued, im.binding_broken, im.notes,
                s.rebrickable_set_num AS set_num, s.name AS set_name, s.local_image_path AS thumbnail,
                s.bricklink_instructions_price_new, s.bricklink_instructions_price_used, s.bricklink_instructions_price_currency,
                s.bricklink_price_new AS set_bricklink_price_new, s.bricklink_price_used AS set_bricklink_price_used, s.bricklink_price_currency AS set_bricklink_price_currency
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
        $row['set_bricklink_price_new'] = $row['set_bricklink_price_new'] !== null ? (float) $row['set_bricklink_price_new'] : null;
        $row['set_bricklink_price_used'] = $row['set_bricklink_price_used'] !== null ? (float) $row['set_bricklink_price_used'] : null;
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

