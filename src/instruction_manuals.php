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

/**
 * Derives a school-grade-style condition (1 = best/"sehr gut", 6 = worst/
 * "sehr schlecht") from how many of the currently-defined condition criteria
 * (instruction_manual_criteria, user-manageable via Settings —
 * getInstructionManualCriteria()) are checked on this manual, out of how
 * many exist in total right now. Confirmed with the user via a concrete
 * worked table (9 criteria): 0 checked -> 1, all checked -> 6, and every
 * count in between is spread as evenly as possible across grades 2-5 —
 * checking even just one criterion already means "not perfect" (grade 2),
 * and only checking literally everything means "sehr schlecht" (grade 6).
 * This has to be a live formula parameterized by $totalCriteria (not a fixed
 * table) because the criteria catalog itself can grow or shrink at any time.
 *
 * 'is_new' overrides everything to a fixed best grade (1) regardless of
 * $selectedCount — addInstructionManual()/updateInstructionManual() also
 * clear all criteria selections when is_new is true, so this never actually
 * needs to reconcile a contradictory is_new+criteria combination, but
 * doesn't rely on that.
 *
 * @return array{isNew:bool, grade:int}
 */
function computeInstructionManualGrade(bool $isNew, int $selectedCount, int $totalCriteria): array
{
    if ($isNew) {
        return ['isNew' => true, 'grade' => 1];
    }
    if ($totalCriteria <= 0 || $selectedCount <= 0) {
        return ['isNew' => false, 'grade' => 1];
    }
    if ($selectedCount >= $totalCriteria) {
        return ['isNew' => false, 'grade' => 6];
    }
    // Maps selectedCount 1..(totalCriteria-1) linearly onto grades 2..5 —
    // $span is the number of "steps" that range covers; guarded at 1 so a
    // very small criteria catalog (1 or 2 entries, leaving no room for a
    // middle count) can't divide by zero.
    $span = max(1, $totalCriteria - 2);
    $grade = 2 + (int) round(($selectedCount - 1) / $span * 3);
    return ['isNew' => false, 'grade' => min(5, max(2, $grade))];
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
 * All condition criteria the user has currently defined, in display order —
 * see instruction_manual_criteria (migration 49). Fully user-manageable
 * (add/edit/delete via ?page=settings, src/routes/pages.php), unlike
 * 'is_new', which stays a fixed, non-deletable concept (see
 * computeInstructionManualGrade()).
 *
 * @return array<int, array{id:int, label:string}>
 */
function getInstructionManualCriteria(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, label FROM instruction_manual_criteria ORDER BY sort_order, id')->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
    }
    unset($row);
    return $rows;
}

/**
 * New criteria are appended at the end (highest sort_order + 1) — no
 * reordering UI exists (not requested), so display order is simply
 * creation order.
 */
function addInstructionManualCriterion(PDO $pdo, string $label): int
{
    $nextSortOrder = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM instruction_manual_criteria')->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO instruction_manual_criteria (label, sort_order) VALUES (?, ?)');
    $stmt->execute([$label, $nextSortOrder]);
    return (int) $pdo->lastInsertId();
}

function updateInstructionManualCriterion(PDO $pdo, int $id, string $label): void
{
    $pdo->prepare('UPDATE instruction_manual_criteria SET label = ? WHERE id = ?')->execute([$label, $id]);
}

/**
 * How many manuals currently have $id checked — shown in the Settings list
 * and used to warn before a delete that would silently drop the criterion
 * off however many manuals still have it checked (confirmed with the user:
 * warn-and-confirm, not a silent cascade).
 */
function getInstructionManualCriterionUsageCount(PDO $pdo, int $id): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM instruction_manual_criteria_selections WHERE criterion_id = ?');
    $stmt->execute([$id]);
    return (int) $stmt->fetchColumn();
}

/**
 * Deletes a criterion outright — instruction_manual_criteria_selections'
 * own FK (ON DELETE CASCADE, migration 49) removes every manual's selection
 * of it in the same statement, so a manual that had it checked just quietly
 * ends up with one fewer selection afterward; its grade is computed live
 * from whatever's still selected (computeInstructionManualGrade()), so
 * nothing else needs updating.
 */
function deleteInstructionManualCriterion(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM instruction_manual_criteria WHERE id = ?')->execute([$id]);
}

/**
 * Batch-reads which criteria are currently checked on each of $manualIds —
 * one query for a whole location's worth of tiles instead of one per
 * manual.
 *
 * @param int[] $manualIds
 * @return array<int, int[]> manual_id => [criterion_id, ...]
 */
function getSelectedCriterionIdsForManuals(PDO $pdo, array $manualIds): array
{
    $result = array_fill_keys($manualIds, []);
    if (empty($manualIds)) {
        return $result;
    }
    $placeholders = implode(',', array_fill(0, count($manualIds), '?'));
    $stmt = $pdo->prepare("SELECT manual_id, criterion_id FROM instruction_manual_criteria_selections WHERE manual_id IN ($placeholders)");
    $stmt->execute($manualIds);
    foreach ($stmt->fetchAll() as $row) {
        $result[(int) $row['manual_id']][] = (int) $row['criterion_id'];
    }
    return $result;
}

/**
 * Replaces $manualId's full set of checked criteria with exactly
 * $criterionIds (delete-all-then-insert, not a diff) — shared by
 * addInstructionManual() (where the DELETE is a no-op, nothing exists yet
 * for a brand-new manual) and updateInstructionManual(), so there's exactly
 * one place that knows how a manual's selections are actually stored.
 */
function setInstructionManualCriteriaSelections(PDO $pdo, int $manualId, array $criterionIds): void
{
    $pdo->prepare('DELETE FROM instruction_manual_criteria_selections WHERE manual_id = ?')->execute([$manualId]);
    $criterionIds = array_unique(array_map('intval', $criterionIds));
    if (empty($criterionIds)) {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO instruction_manual_criteria_selections (manual_id, criterion_id) VALUES (?, ?)');
    foreach ($criterionIds as $criterionId) {
        $stmt->execute([$manualId, $criterionId]);
    }
}

/**
 * Adds one physical booklet copy, filed automatically into its set's own
 * theme location (auto-created on first use — see
 * getOrCreateInstructionsThemeLocation()). $set is the getSetById() row for
 * $setId's catalog set — the caller already has it (to validate the set
 * exists before calling this), so it's passed in rather than re-fetched.
 * $isNew, when true, forces the checked-criteria set to empty regardless of
 * what's in $selectedCriterionIds — server-side enforcement (not just the
 * add/edit form's UI disabling the checkboxes) so a crafted request can't
 * store a contradictory is_new+criteria combination. No storage_movements
 * audit row — same reasoning as addMinifigStock(): that log's schema is
 * part-specific (part_id/color_id), and instance-based storage doesn't
 * write there either.
 *
 * @param int[] $selectedCriterionIds
 */
function addInstructionManual(array $set, bool $isNew, array $selectedCriterionIds, ?string $notes): int
{
    $pdo = getPDO();
    $themeId = $set['theme_id'] ?? INSTRUCTIONS_THEME_FALLBACK_ID;
    $themeName = $set['theme_id'] !== null ? $set['theme_name'] : INSTRUCTIONS_THEME_FALLBACK_NAME;
    $locationId = getOrCreateInstructionsThemeLocation($pdo, $themeId, $themeName);

    $stmt = $pdo->prepare('INSERT INTO instruction_manuals (location_id, set_id, is_new, notes) VALUES (?, ?, ?, ?)');
    $stmt->execute([$locationId, $set['id'], $isNew ? 1 : 0, $notes]);
    $manualId = (int) $pdo->lastInsertId();

    setInstructionManualCriteriaSelections($pdo, $manualId, $isNew ? [] : $selectedCriterionIds);
    return $manualId;
}

/**
 * @param int[] $selectedCriterionIds
 */
function updateInstructionManual(int $id, bool $isNew, array $selectedCriterionIds, ?string $notes): void
{
    $pdo = getPDO();
    $pdo->prepare('UPDATE instruction_manuals SET is_new = ?, notes = ? WHERE id = ?')->execute([$isNew ? 1 : 0, $notes, $id]);
    setInstructionManualCriteriaSelections($pdo, $id, $isNew ? [] : $selectedCriterionIds);
}

/**
 * Removes one manual instance and, if that was the last one filed under its
 * theme location, that now-empty virtual location too (see
 * pruneEmptyInstructionsThemeLocation()). Its criteria selections cascade
 * away via instruction_manual_criteria_selections' own FK.
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
 * Casts the raw DB row's flags and attaches the derived isNew/grade pair
 * (computeInstructionManualGrade()) plus the manual's own selected criteria
 * ids (for pre-filling the edit form's checkboxes) — shared by every reader
 * below so a row always carries both, without every caller re-deriving it.
 * $selectedCriterionIds/$totalCriteria come from the batch lookups
 * getSelectedCriterionIdsForManuals()/getInstructionManualCriteria() so a
 * multi-row read only pays for those once, not once per row.
 *
 * @param int[] $selectedCriterionIds
 */
function hydrateInstructionManualRow(array $row, array $selectedCriterionIds, int $totalCriteria): array
{
    $row['id'] = (int) $row['id'];
    $row['location_id'] = (int) $row['location_id'];
    $row['set_id'] = (int) $row['set_id'];
    $row['is_new'] = (bool) $row['is_new'];
    $row['selected_criterion_ids'] = $selectedCriterionIds;
    $graded = computeInstructionManualGrade($row['is_new'], count($selectedCriterionIds), $totalCriteria);
    $row['grade'] = $graded['grade'];
    return $row;
}

/**
 * @return ?array{id:int, location_id:int, set_id:int, is_new:bool, selected_criterion_ids:int[], grade:int, notes:?string, set_num:string, set_name:string, thumbnail:?string}
 */
function getInstructionManualById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT im.id, im.location_id, im.set_id, im.is_new, im.notes,
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
    $manualId = (int) $row['id'];
    $selections = getSelectedCriterionIdsForManuals($pdo, [$manualId]);
    $totalCriteria = count(getInstructionManualCriteria($pdo));
    return hydrateInstructionManualRow($row, $selections[$manualId], $totalCriteria);
}

/**
 * Every manual instance stored anywhere under $locationId — itself plus
 * every descendant location, recursively (mirrors the minifig half of
 * getLocationContentRecursive()). Raw rows, no percent_complete yet — see
 * getInstructionManualTilesForLocation() for that.
 *
 * @return array<int, array{id:int, location_id:int, set_id:int, is_new:bool, selected_criterion_ids:int[], grade:int, notes:?string, set_num:string, set_name:string, thumbnail:?string, bricklink_instructions_price_new:?float, bricklink_instructions_price_used:?float, bricklink_instructions_price_currency:?string, set_bricklink_price_new:?float, set_bricklink_price_used:?float, set_bricklink_price_currency:?string}>
 */
function getInstructionManualsForLocation(PDO $pdo, int $locationId): array
{
    $ids = getLocationSubtreeIds($locationId);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT im.id, im.location_id, im.set_id, im.is_new, im.notes,
                s.rebrickable_set_num AS set_num, s.name AS set_name, s.local_image_path AS thumbnail,
                s.bricklink_instructions_price_new, s.bricklink_instructions_price_used, s.bricklink_instructions_price_currency,
                s.bricklink_price_new AS set_bricklink_price_new, s.bricklink_price_used AS set_bricklink_price_used, s.bricklink_price_currency AS set_bricklink_price_currency
         FROM instruction_manuals im
         INNER JOIN sets s ON s.id = im.set_id
         WHERE im.location_id IN ($placeholders)
         ORDER BY s.name, im.id"
    );
    $stmt->execute($ids);
    $rawRows = $stmt->fetchAll();

    $manualIds = array_map(fn (array $r): int => (int) $r['id'], $rawRows);
    $selectionsByManual = getSelectedCriterionIdsForManuals($pdo, $manualIds);
    $totalCriteria = count(getInstructionManualCriteria($pdo));

    $rows = array_map(
        fn (array $r): array => hydrateInstructionManualRow($r, $selectionsByManual[(int) $r['id']], $totalCriteria),
        $rawRows
    );
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

