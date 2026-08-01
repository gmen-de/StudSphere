<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/sets.php';
require_once __DIR__ . '/minifigs.php';
require_once __DIR__ . '/i18n.php';

/**
 * Distinct on-disk root for owned-set photos — separate from
 * public/instructions/ (which is keyed by catalog set_id and shared across
 * every owned copy) since photos are per physical instance: two copies of
 * the same set have different scuffs, different boxes, different photos.
 */
function getOwnedSetPhotosStorageDir(int $ownedSetId): string
{
    $dir = dirname(__DIR__) . '/public/owned_set_photos/' . $ownedSetId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Konnte Verzeichnis für Set-Fotos nicht erstellen: ' . $dir);
    }
    return $dir;
}

function getOwnedSetPhotoRelativePath(int $ownedSetId, string $filename): string
{
    return 'public/owned_set_photos/' . $ownedSetId . '/' . $filename;
}

function generateOwnedSetPhotoFilename(string $originalFilename): string
{
    $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
    $ext = preg_match('/^[a-z0-9]{1,5}$/', $ext) ? $ext : 'jpg';
    return bin2hex(random_bytes(16)) . '.' . $ext;
}

/**
 * How many copies of $setId the household owns — batched over many set ids
 * at once (e.g. for badging a whole grid of set cards) to avoid N+1.
 *
 * @param int[] $setIds
 * @return array<int, int> setId => count, only for ids with at least 1
 */
function getOwnedSetCountsForSets(PDO $pdo, array $setIds): array
{
    $setIds = array_values(array_unique(array_map('intval', $setIds)));
    if (empty($setIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($setIds), '?'));
    $stmt = $pdo->prepare("SELECT set_id, COUNT(*) AS cnt FROM owned_sets WHERE set_id IN ($placeholders) GROUP BY set_id");
    $stmt->execute($setIds);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[(int) $row['set_id']] = (int) $row['cnt'];
    }
    return $result;
}

function getNextOwnedSetInstanceNumber(PDO $pdo, int $setId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM owned_sets WHERE set_id = ?');
    $stmt->execute([$setId]);
    return (int) $stmt->fetchColumn() + 1;
}

/**
 * @return array{id:int, set_id:int, inventory_id:?int, location_id:int, condition_type:string, has_instructions:bool, has_box:bool, box_complete:bool, notes:?string, instructions_notes:?string, box_notes:?string, box_complete_notes:?string, stickers_applied:bool, stickers_notes:?string, damaged_missing_show_spares:bool, damaged_missing_show_stickers:bool, created_at:string, rebrickable_set_num:string, name:string, thumbnail:?string}|null
 */
function getOwnedSetById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT os.id, os.set_id, os.inventory_id, os.location_id, os.condition_type, os.has_instructions, os.has_box, os.box_complete,
                os.notes, os.instructions_notes, os.box_notes, os.box_complete_notes, os.stickers_applied, os.stickers_notes,
                os.damaged_missing_show_spares, os.damaged_missing_show_stickers, os.created_at,
                s.rebrickable_set_num, s.name, s.local_image_path AS thumbnail
         FROM owned_sets os
         INNER JOIN sets s ON s.id = os.set_id
         WHERE os.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['set_id'] = (int) $row['set_id'];
    $row['inventory_id'] = $row['inventory_id'] !== null ? (int) $row['inventory_id'] : null;
    $row['location_id'] = (int) $row['location_id'];
    $row['has_instructions'] = (bool) $row['has_instructions'];
    $row['has_box'] = (bool) $row['has_box'];
    $row['box_complete'] = (bool) $row['box_complete'];
    $row['stickers_applied'] = (bool) $row['stickers_applied'];
    $row['damaged_missing_show_spares'] = (bool) $row['damaged_missing_show_spares'];
    $row['damaged_missing_show_stickers'] = (bool) $row['damaged_missing_show_stickers'];
    return $row;
}

/**
 * Which Rebrickable inventory revision an instance actually is — the
 * explicitly chosen one if set, otherwise falls back to "newest" (rows
 * created before version selection existed, or the rare case a set only
 * has one revision to begin with and inventory_id was never actually
 * resolved). See getSetInventoryVersions() in src/sets.php for what a
 * "revision" is.
 */
function resolveOwnedSetInventoryId(PDO $pdo, array $ownedSet): ?int
{
    if (!empty($ownedSet['inventory_id'])) {
        return (int) $ownedSet['inventory_id'];
    }
    return getSetInventoryId($pdo, $ownedSet['rebrickable_set_num']);
}

/**
 * @return array<int, array{id:int, condition_type:string, has_instructions:bool, has_box:bool, box_complete:bool, notes:?string, created_at:string, location_id:int}>
 */
function getOwnedSetsForSet(PDO $pdo, int $setId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, location_id, condition_type, has_instructions, has_box, box_complete, notes, created_at
         FROM owned_sets WHERE set_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$setId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['location_id'] = (int) $row['location_id'];
        $row['has_instructions'] = (bool) $row['has_instructions'];
        $row['has_box'] = (bool) $row['has_box'];
        $row['box_complete'] = (bool) $row['box_complete'];
    }
    unset($row);
    return $rows;
}

/**
 * @return array<int, array{id:int, set_id:int, rebrickable_set_num:string, name:string, thumbnail:?string, location_id:int, condition_type:string, created_at:string}>
 */
function getAllOwnedSets(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT os.id, os.set_id, s.rebrickable_set_num, s.name, s.local_image_path AS thumbnail,
                os.location_id, os.condition_type, os.created_at
         FROM owned_sets os
         INNER JOIN sets s ON s.id = os.set_id
         ORDER BY os.created_at DESC'
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['set_id'] = (int) $row['set_id'];
        $row['location_id'] = (int) $row['location_id'];
    }
    unset($row);
    return $rows;
}

/**
 * Aggregate nominal/actual across every minifig's constituent parts for this
 * owned instance — each minifig type's own part list
 * (getSetMinifigsList() + getOwnedSetMinifigPartsWithStatus(), the latter
 * already scaled by how many of that minifig the SET nominally has) summed
 * up. Folded into getOwnedSetCompleteness()'s and
 * getOwnedSetInventorySummary()'s "Gesamt" totals so those no longer
 * diverge from Rebrickable's own catalog num_parts by roughly one minifig's
 * worth of parts per minifig in the set (head/torso/legs/etc. were
 * previously untracked, see migration 18).
 *
 * @return array{actual:int, nominal:int}
 */
function getOwnedSetMinifigPartsTotal(PDO $pdo, array $ownedSet): array
{
    $inventoryId = resolveOwnedSetInventoryId($pdo, $ownedSet);
    if ($inventoryId === null) {
        return ['actual' => 0, 'nominal' => 0];
    }
    $total = ['actual' => 0, 'nominal' => 0];
    foreach (getSetMinifigsList($pdo, $inventoryId) as $fig) {
        $figCount = $fig['quantity'];
        if ($figCount <= 0) {
            continue;
        }
        foreach (getOwnedSetMinifigPartsWithStatus($pdo, $ownedSet, $fig['minifig_id'], $fig['fig_num'], $figCount) as $part) {
            $total['nominal'] += $part['nominal_quantity'];
            $total['actual'] += $part['actual_quantity'];
        }
    }
    return $total;
}

/**
 * Nominal (from the set's own Rebrickable inventory, non-spare, plus every
 * minifig's constituent parts via getOwnedSetMinifigPartsTotal()) vs. actual
 * (storage_items at the instance's location, plus the same minifig-parts
 * total) piece counts — the single source of truth for "how complete is
 * this set", computed on the fly rather than stored, since actual quantity
 * already lives in storage_items/owned_set_minifig_parts.
 *
 * Sticker sheets are excluded from both sides of the ratio (same part_ids
 * as getStickerPartIds(), reused by every other sticker-aware view in this
 * file) — they're still tracked and shown in their own bucket (see
 * getOwnedSetInventorySummary()), just don't affect the completeness
 * percent/ring anymore, since a set with all its bricks but an unapplied
 * sticker sheet used to read as incomplete.
 *
 * @return array{nominal:int, actual:int, percent:float}
 */
function getOwnedSetCompleteness(PDO $pdo, array $ownedSet): array
{
    $inventoryId = resolveOwnedSetInventoryId($pdo, $ownedSet);
    if ($inventoryId === null) {
        return ['nominal' => 0, 'actual' => 0, 'percent' => 100.0];
    }

    $stickerPartIds = array_keys(getStickerPartIds($pdo, $inventoryId));
    $stickerExclusion = '';
    if (!empty($stickerPartIds)) {
        $stickerExclusion = ' AND part_id NOT IN (' . implode(',', array_fill(0, count($stickerPartIds), '?')) . ')';
    }

    $nominalStmt = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM inventory_parts WHERE inventory_id = ? AND is_spare = 0' . $stickerExclusion);
    $nominalStmt->execute(array_merge([$inventoryId], $stickerPartIds));
    $nominal = (int) $nominalStmt->fetchColumn();

    $actualStmt = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM storage_items WHERE location_id = ?' . $stickerExclusion);
    $actualStmt->execute(array_merge([$ownedSet['location_id']], $stickerPartIds));
    $actual = (int) $actualStmt->fetchColumn();

    $minifigParts = getOwnedSetMinifigPartsTotal($pdo, $ownedSet);
    $nominal += $minifigParts['nominal'];
    $actual += $minifigParts['actual'];

    $percent = $nominal > 0 ? round(min(100.0, ($actual / $nominal) * 100), 1) : 100.0;

    return ['nominal' => $nominal, 'actual' => $actual, 'percent' => $percent];
}

/**
 * Same exclusive/rare/sticker bucketing as sets.php's getSetInventorySummary(),
 * but paired with this owned instance's actual stock (storage_items at its
 * location) so each bucket — including stickers, unlike the catalog table's
 * plain sheet-row count — can show "{actual} / {nominal}" instead of just
 * the catalog's nominal count. Mirrors getOwnedSetCompleteness()'s
 * nominal-vs-actual pairing, split per rarity/category bucket. Also folds in
 * the instance's minifig actual/nominal totals, since owned_set_detail's own
 * "Inventar" table shows all of these together.
 *
 * @return array{exclusive: array{actual:int, nominal:int}, rare: array{actual:int, nominal:int}, stickers: array{actual:int, nominal:int}, minifigs: array{actual:int, nominal:int}}
 */
function getOwnedSetInventorySummary(PDO $pdo, array $ownedSet, string $locale): array
{
    $empty = ['actual' => 0, 'nominal' => 0];
    $inventoryId = resolveOwnedSetInventoryId($pdo, $ownedSet);
    if ($inventoryId === null) {
        return ['exclusive' => $empty, 'rare' => $empty, 'stickers' => $empty, 'minifigs' => $empty];
    }

    $items = getSetPartsList($pdo, $inventoryId, false, $locale);
    $stickerPartIds = getStickerPartIds($pdo, $inventoryId);

    $actualStmt = $pdo->prepare('SELECT part_id, color_id, quantity FROM storage_items WHERE location_id = ?');
    $actualStmt->execute([$ownedSet['location_id']]);
    $actualByKey = [];
    foreach ($actualStmt->fetchAll() as $row) {
        $actualByKey[$row['part_id'] . ':' . $row['color_id']] = (int) $row['quantity'];
    }

    $pairs = [];
    foreach ($items as $item) {
        if ($item['rebrickable_color_id'] !== null && !isset($stickerPartIds[$item['part_id']])) {
            $pairs[] = ['part_id' => $item['part_id'], 'color_id' => $item['rebrickable_color_id']];
        }
    }
    $setCounts = getPartSetCounts($pdo, $pairs);

    $exclusive = ['actual' => 0, 'nominal' => 0];
    $rare = ['actual' => 0, 'nominal' => 0];
    $stickers = ['actual' => 0, 'nominal' => 0];
    foreach ($items as $item) {
        $key = $item['part_id'] . ':' . $item['color_id'];
        $actual = $actualByKey[$key] ?? $item['quantity'];

        if (isset($stickerPartIds[$item['part_id']])) {
            $stickers['nominal'] += $item['quantity'];
            $stickers['actual'] += $actual;
            continue;
        }
        $count = $item['rebrickable_color_id'] !== null
            ? ($setCounts[$item['part_id'] . ':' . $item['rebrickable_color_id']] ?? 0)
            : 0;
        if ($count < 1 || $count > 3) {
            continue;
        }
        $bucket = $count === 1 ? 'exclusive' : 'rare';
        if ($bucket === 'exclusive') {
            $exclusive['nominal'] += $item['quantity'];
            $exclusive['actual'] += $actual;
        } else {
            $rare['nominal'] += $item['quantity'];
            $rare['actual'] += $actual;
        }
    }

    $minifigs = ['actual' => 0, 'nominal' => 0];
    foreach (getOwnedSetMinifigsWithStatus($pdo, $ownedSet) as $fig) {
        $minifigs['nominal'] += $fig['nominal_quantity'];
        $minifigs['actual'] += $fig['actual_quantity'];
    }

    return ['exclusive' => $exclusive, 'rare' => $rare, 'stickers' => $stickers, 'minifigs' => $minifigs];
}

/**
 * Prev/next navigation between owned-set instances (not catalog sets) —
 * owned_set_detail's own equivalent of sets.php's getAdjacentSets(), same
 * numeric set-number comparison, with owned_sets.created_at then id as the
 * tie-break so multiple instances of the same set sit next to each other in
 * a stable order instead of comparing equal.
 *
 * @return array{prev: ?array{id:int, rebrickable_set_num:string}, next: ?array{id:int, rebrickable_set_num:string}}
 */
function getAdjacentOwnedSets(PDO $pdo, array $ownedSet): array
{
    $base = "CAST(SUBSTRING_INDEX(s.rebrickable_set_num, '-', 1) AS UNSIGNED)";
    $variant = "CAST(SUBSTRING_INDEX(s.rebrickable_set_num, '-', -1) AS UNSIGNED)";
    $currentBase = "CAST(SUBSTRING_INDEX(?, '-', 1) AS UNSIGNED)";
    $currentVariant = "CAST(SUBSTRING_INDEX(?, '-', -1) AS UNSIGNED)";

    $prevStmt = $pdo->prepare(
        "SELECT os.id, s.rebrickable_set_num FROM owned_sets os
         INNER JOIN sets s ON s.id = os.set_id
         WHERE ($base, $variant, os.created_at, os.id) < ($currentBase, $currentVariant, ?, ?)
         ORDER BY $base DESC, $variant DESC, os.created_at DESC, os.id DESC
         LIMIT 1"
    );
    $prevStmt->execute([$ownedSet['rebrickable_set_num'], $ownedSet['rebrickable_set_num'], $ownedSet['created_at'], $ownedSet['id']]);
    $prev = $prevStmt->fetch();

    $nextStmt = $pdo->prepare(
        "SELECT os.id, s.rebrickable_set_num FROM owned_sets os
         INNER JOIN sets s ON s.id = os.set_id
         WHERE ($base, $variant, os.created_at, os.id) > ($currentBase, $currentVariant, ?, ?)
         ORDER BY $base ASC, $variant ASC, os.created_at ASC, os.id ASC
         LIMIT 1"
    );
    $nextStmt->execute([$ownedSet['rebrickable_set_num'], $ownedSet['rebrickable_set_num'], $ownedSet['created_at'], $ownedSet['id']]);
    $next = $nextStmt->fetch();

    return [
        'prev' => $prev !== false ? ['id' => (int) $prev['id'], 'rebrickable_set_num' => $prev['rebrickable_set_num']] : null,
        'next' => $next !== false ? ['id' => (int) $next['id'], 'rebrickable_set_num' => $next['rebrickable_set_num']] : null,
    ];
}

/**
 * Shared by getOwnedSetPartsWithStatus()/getOwnedSetStickerPartsWithStatus()/
 * getOwnedSetSparePartsWithStatus() — fetches one category's nominal list
 * (getSetPartsList()) plus the matching actual/damaged (or spare/
 * spare-damaged) columns from storage_items, and zips them together.
 * Colorless parts are left out — storage_items (like the rest of the
 * storage module) can't represent a colorless part. $partIdFilter, when
 * given, keeps only those part ids (used to split stickers out of/into the
 * regular list — see getStickerPartIds() in src/sets.php); $invert flips
 * that to "everything except these ids".
 *
 * @param int[]|null $partIdFilter
 * @return array<int, array{part_id:int, part_num:string, name:string, color_id:int, color_name:?string, color_rgb:?string, rebrickable_color_id:?int, thumbnail:?string, nominal_quantity:int, actual_quantity:int, damaged_quantity:int}>
 */
function getOwnedSetItemsWithStatus(PDO $pdo, array $ownedSet, string $locale, bool $spares, ?array $partIdFilter = null, bool $invert = false): array
{
    $inventoryId = resolveOwnedSetInventoryId($pdo, $ownedSet);
    if ($inventoryId === null) {
        return [];
    }
    $nominalItems = getSetPartsList($pdo, $inventoryId, $spares, $locale);

    $quantityCol = $spares ? 'spare_quantity' : 'quantity';
    $damagedCol = $spares ? 'spare_damaged_quantity' : 'damaged_quantity';
    $actualStmt = $pdo->prepare("SELECT part_id, color_id, $quantityCol AS actual, $damagedCol AS damaged FROM storage_items WHERE location_id = ?");
    $actualStmt->execute([$ownedSet['location_id']]);
    $actualByKey = [];
    $damagedByKey = [];
    foreach ($actualStmt->fetchAll() as $row) {
        $key = $row['part_id'] . ':' . $row['color_id'];
        $actualByKey[$key] = (int) $row['actual'];
        $damagedByKey[$key] = (int) $row['damaged'];
    }

    $filterSet = $partIdFilter !== null ? array_flip($partIdFilter) : null;

    $result = [];
    foreach ($nominalItems as $item) {
        if ($item['color_id'] === null) {
            continue;
        }
        if ($filterSet !== null && (isset($filterSet[$item['part_id']]) === $invert)) {
            continue;
        }
        $key = $item['part_id'] . ':' . $item['color_id'];
        $result[] = [
            'part_id' => $item['part_id'],
            'part_num' => $item['part_num'],
            'name' => $item['name'],
            'color_id' => $item['color_id'],
            'color_name' => $item['color_name'],
            'color_rgb' => $item['color_rgb'],
            'rebrickable_color_id' => $item['rebrickable_color_id'],
            'thumbnail' => $item['ldraw_thumbnail'] ?? $item['thumbnail'] ?? $item['remote_thumbnail'] ?? null,
            'nominal_quantity' => $item['quantity'],
            'actual_quantity' => $actualByKey[$key] ?? ($spares ? 0 : $item['quantity']),
            'damaged_quantity' => $damagedByKey[$key] ?? 0,
        ];
    }
    return $result;
}

/**
 * Per-part-line nominal vs. actual quantities for the instance's regular
 * (non-spare, non-sticker) inventory. Stickers get their own tab/step (see
 * getOwnedSetStickerPartsWithStatus()) — same underlying storage_items
 * columns, just grouped separately since a sticker sheet isn't really a
 * "brick" the same way the rest of the inventory is.
 *
 * damaged_quantity is a subset of actual_quantity (still "owned", not
 * "missing" — see setOwnedSetPartInventory()'s doc comment).
 */
function getOwnedSetPartsWithStatus(PDO $pdo, array $ownedSet, string $locale = 'en'): array
{
    $inventoryId = resolveOwnedSetInventoryId($pdo, $ownedSet);
    $stickerPartIds = $inventoryId !== null ? array_keys(getStickerPartIds($pdo, $inventoryId)) : [];
    return getOwnedSetItemsWithStatus($pdo, $ownedSet, $locale, false, $stickerPartIds, true);
}

/**
 * Only the sticker sheets among the regular (non-spare) inventory — split
 * out of getOwnedSetPartsWithStatus() into their own category (see that
 * function's doc comment).
 */
function getOwnedSetStickerPartsWithStatus(PDO $pdo, array $ownedSet, string $locale = 'en'): array
{
    $inventoryId = resolveOwnedSetInventoryId($pdo, $ownedSet);
    $stickerPartIds = $inventoryId !== null ? array_keys(getStickerPartIds($pdo, $inventoryId)) : [];
    if (empty($stickerPartIds)) {
        return [];
    }
    return getOwnedSetItemsWithStatus($pdo, $ownedSet, $locale, false, $stickerPartIds, false);
}

/**
 * Spare parts (is_spare=1 in the set's own inventory) — tracked via
 * storage_items' separate spare_quantity/spare_damaged_quantity columns,
 * never mixed into the regular quantity (a spare and a regular part can be
 * the exact same part+color) and never counted toward completeness (see
 * getOwnedSetCompleteness(), which only ever sums the regular column).
 */
function getOwnedSetSparePartsWithStatus(PDO $pdo, array $ownedSet, string $locale = 'en'): array
{
    return getOwnedSetItemsWithStatus($pdo, $ownedSet, $locale, true);
}

/**
 * Per-minifig nominal vs. actual+damaged, for the instance's minifig
 * inventory step/tab. Unlike parts, minifigs aren't tracked via
 * storage_items at all (they're not a part+color combination) — a
 * dedicated owned_set_minifigs table instead (see migration 16 in
 * src/migrations.php), one row per minifig type once materialized/edited.
 *
 * @return array<int, array{minifig_id:int, fig_num:string, name:string, thumbnail:?string, nominal_quantity:int, actual_quantity:int, damaged_quantity:int}>
 */
function getOwnedSetMinifigsWithStatus(PDO $pdo, array $ownedSet): array
{
    $inventoryId = resolveOwnedSetInventoryId($pdo, $ownedSet);
    if ($inventoryId === null) {
        return [];
    }
    $nominalItems = getSetMinifigsList($pdo, $inventoryId);

    $actualStmt = $pdo->prepare('SELECT minifig_id, quantity, damaged_quantity FROM owned_set_minifigs WHERE owned_set_id = ?');
    $actualStmt->execute([$ownedSet['id']]);
    $actualByKey = [];
    $damagedByKey = [];
    foreach ($actualStmt->fetchAll() as $row) {
        $actualByKey[(int) $row['minifig_id']] = (int) $row['quantity'];
        $damagedByKey[(int) $row['minifig_id']] = (int) $row['damaged_quantity'];
    }

    $result = [];
    foreach ($nominalItems as $item) {
        $result[] = [
            'minifig_id' => $item['minifig_id'],
            'fig_num' => $item['fig_num'],
            'name' => $item['name'],
            'thumbnail' => $item['thumbnail'],
            'nominal_quantity' => $item['quantity'],
            'actual_quantity' => $actualByKey[$item['minifig_id']] ?? $item['quantity'],
            'damaged_quantity' => $damagedByKey[$item['minifig_id']] ?? 0,
        ];
    }
    return $result;
}

/**
 * One minifig's own constituent parts (head/torso/legs/accessories) for
 * this owned instance — nominal/actual/damaged pairing, same shape and same
 * "missing row = fully present, until corrected" convention as
 * getOwnedSetItemsWithStatus() uses for regular parts. $minifigNominalCount
 * is how many of this minifig the set nominally has (getSetMinifigsList()'s
 * quantity, e.g. 2 for a set with two identical train workers) — nominal
 * here is scaled by it (Rebrickable's own per-minifig inventory, via
 * getMinifigInventoryId() + getSetPartsList(), only ever gives "how many of
 * this part one single minifig needs"), since the parts checklist is shared
 * across all copies of this minifig type rather than tracked per physical
 * copy — see ownedSetMinifigBottleneckStatus()'s doc comment for how the
 * derived per-copy owned/damaged counts are recovered from these totals.
 * actual/damaged come from the dedicated owned_set_minifig_parts table (see
 * migration 18).
 *
 * @return array<int, array{part_id:int, part_num:string, name:string, color_id:int, rebrickable_color_id:?int, color_name:?string, color_rgb:?string, thumbnail:?string, nominal_quantity:int, actual_quantity:int, damaged_quantity:int}>
 */
function getOwnedSetMinifigPartsWithStatus(PDO $pdo, array $ownedSet, int $minifigId, string $figNum, int $minifigNominalCount, string $locale = 'en'): array
{
    $inventoryId = getMinifigInventoryId($pdo, $figNum);
    if ($inventoryId === null) {
        return [];
    }
    $nominalItems = getSetPartsList($pdo, $inventoryId, false, $locale);

    $actualStmt = $pdo->prepare('SELECT part_id, color_id, quantity, damaged_quantity FROM owned_set_minifig_parts WHERE owned_set_id = ? AND minifig_id = ?');
    $actualStmt->execute([$ownedSet['id'], $minifigId]);
    $actualByKey = [];
    $damagedByKey = [];
    foreach ($actualStmt->fetchAll() as $row) {
        $key = $row['part_id'] . ':' . $row['color_id'];
        $actualByKey[$key] = (int) $row['quantity'];
        $damagedByKey[$key] = (int) $row['damaged_quantity'];
    }

    $result = [];
    foreach ($nominalItems as $item) {
        if ($item['color_id'] === null) {
            continue;
        }
        $key = $item['part_id'] . ':' . $item['color_id'];
        $totalNominal = $item['quantity'] * $minifigNominalCount;
        $result[] = [
            'part_id' => $item['part_id'],
            'part_num' => $item['part_num'],
            'name' => $item['name'],
            'color_id' => $item['color_id'],
            // getSetPartsList() already fetches both — colors.id (the surrogate
            // PK, what 'color_id' above is) and Rebrickable's own numeric
            // color_id (what the colors.bricklink_color_id lookup in
            // buildOwnedSetBricklinkXml() actually keys on) are NOT the same
            // value, see that function's own doc comment. Exposing both here
            // instead of just 'color_id' avoids silently looking up the wrong
            // color's BrickLink id downstream.
            'rebrickable_color_id' => $item['rebrickable_color_id'],
            'color_name' => $item['color_name'],
            'color_rgb' => $item['color_rgb'],
            'thumbnail' => $item['ldraw_thumbnail'] ?? $item['thumbnail'] ?? $item['remote_thumbnail'] ?? null,
            'nominal_quantity' => $totalNominal,
            'actual_quantity' => $actualByKey[$key] ?? $totalNominal,
            'damaged_quantity' => $damagedByKey[$key] ?? 0,
        ];
    }
    return $result;
}

/**
 * Catalog-only nominal preview for the add-to-collection wizard, before any
 * owned_sets row exists yet — the wizard now defers add_owned_set all the
 * way to its final "Speichern" step, so the earlier inventory-review step
 * needs nominal quantities without an owned_set_id to look actuals up
 * against. Mirrors getOwnedSetItemsWithStatus()'s shape and defaults
 * exactly (same sticker-filtering via getStickerPartIds(), same "spares
 * default to 0, everything else defaults to nominal" asymmetry — see that
 * function's own field-level comments), just skipping the storage_items
 * lookup entirely: nothing has been corrected yet, so actual_quantity IS
 * nominal_quantity (or 0 for spares) and damaged_quantity is always 0.
 */
function getSetItemsPreview(PDO $pdo, int $inventoryId, string $locale, bool $spares, ?array $partIdFilter = null, bool $invert = false): array
{
    $nominalItems = getSetPartsList($pdo, $inventoryId, $spares, $locale);
    $filterSet = $partIdFilter !== null ? array_flip($partIdFilter) : null;

    $result = [];
    foreach ($nominalItems as $item) {
        if ($item['color_id'] === null) {
            continue;
        }
        if ($filterSet !== null && (isset($filterSet[$item['part_id']]) === $invert)) {
            continue;
        }
        $result[] = [
            'part_id' => $item['part_id'],
            'part_num' => $item['part_num'],
            'name' => $item['name'],
            'color_id' => $item['color_id'],
            'color_name' => $item['color_name'],
            'color_rgb' => $item['color_rgb'],
            'rebrickable_color_id' => $item['rebrickable_color_id'],
            'thumbnail' => $item['ldraw_thumbnail'] ?? $item['thumbnail'] ?? $item['remote_thumbnail'] ?? null,
            'nominal_quantity' => $item['quantity'],
            'actual_quantity' => $spares ? 0 : $item['quantity'],
            'damaged_quantity' => 0,
        ];
    }
    return $result;
}

/** @see getSetItemsPreview() — regular-parts slice, mirrors getOwnedSetPartsWithStatus(). */
function getSetPartsPreview(PDO $pdo, int $inventoryId, string $locale = 'en'): array
{
    $stickerPartIds = array_keys(getStickerPartIds($pdo, $inventoryId));
    return getSetItemsPreview($pdo, $inventoryId, $locale, false, $stickerPartIds, true);
}

/** @see getSetItemsPreview() — sticker-sheets slice, mirrors getOwnedSetStickerPartsWithStatus(). */
function getSetStickerPartsPreview(PDO $pdo, int $inventoryId, string $locale = 'en'): array
{
    $stickerPartIds = array_keys(getStickerPartIds($pdo, $inventoryId));
    if (empty($stickerPartIds)) {
        return [];
    }
    return getSetItemsPreview($pdo, $inventoryId, $locale, false, $stickerPartIds, false);
}

/** @see getSetItemsPreview() — spares, mirrors getOwnedSetSparePartsWithStatus(). */
function getSetSparePartsPreview(PDO $pdo, int $inventoryId, string $locale = 'en'): array
{
    return getSetItemsPreview($pdo, $inventoryId, $locale, true);
}

/** @see getSetItemsPreview()'s doc comment — minifig-list version, mirrors getOwnedSetMinifigsWithStatus(). */
function getSetMinifigsPreview(PDO $pdo, int $inventoryId): array
{
    $result = [];
    foreach (getSetMinifigsList($pdo, $inventoryId) as $item) {
        $result[] = [
            'minifig_id' => $item['minifig_id'],
            'fig_num' => $item['fig_num'],
            'name' => $item['name'],
            'thumbnail' => $item['thumbnail'],
            'nominal_quantity' => $item['quantity'],
            'actual_quantity' => $item['quantity'],
            'damaged_quantity' => 0,
        ];
    }
    return $result;
}

/** @see getSetItemsPreview()'s doc comment — one minifig's constituent parts, mirrors getOwnedSetMinifigPartsWithStatus(). */
function getMinifigPartsPreview(PDO $pdo, string $figNum, int $minifigNominalCount, string $locale = 'en'): array
{
    $inventoryId = getMinifigInventoryId($pdo, $figNum);
    if ($inventoryId === null) {
        return [];
    }
    $nominalItems = getSetPartsList($pdo, $inventoryId, false, $locale);

    $result = [];
    foreach ($nominalItems as $item) {
        if ($item['color_id'] === null) {
            continue;
        }
        $totalNominal = $item['quantity'] * $minifigNominalCount;
        $result[] = [
            'part_id' => $item['part_id'],
            'part_num' => $item['part_num'],
            'name' => $item['name'],
            'color_id' => $item['color_id'],
            'color_name' => $item['color_name'],
            'color_rgb' => $item['color_rgb'],
            'thumbnail' => $item['ldraw_thumbnail'] ?? $item['thumbnail'] ?? $item['remote_thumbnail'] ?? null,
            'nominal_quantity' => $totalNominal,
            'actual_quantity' => $totalNominal,
            'damaged_quantity' => 0,
        ];
    }
    return $result;
}

/**
 * Copies a set's current regular (non-spare, includes stickers) inventory
 * into storage_items at $locationId, one addStorageStock() call per
 * distinct part+color — the normal audit-logged path, just run in a loop,
 * so a newly added set's parts show up in the loose-parts stock system
 * exactly like any other stock addition would. Colorless parts are skipped
 * (see getOwnedSetItemsWithStatus()'s doc comment — not a storage_items
 * limitation specific to this feature). $inventoryId is the specific
 * revision the user picked when adding the set (see resolveOwnedSetInventoryId()),
 * not necessarily the newest.
 */
function materializeOwnedSetStock(PDO $pdo, int $inventoryId, int $locationId, string $conditionType, ?int $userId): void
{
    $items = getSetPartsList($pdo, $inventoryId, false, 'en');
    foreach ($items as $item) {
        if ($item['color_id'] === null || $item['quantity'] <= 0) {
            continue;
        }
        addStorageStock($locationId, $item['part_id'], $item['color_id'], $conditionType, $item['quantity'], $userId);
    }
}

/**
 * Same as materializeOwnedSetStock(), but for spares — writes to
 * spare_quantity via setStorageItemSpareQuantity() instead of addStorageStock(),
 * since a spare can be the same part+color as a regular row already
 * materialized above (that call already created the row if needed).
 */
function materializeOwnedSetSpares(PDO $pdo, int $inventoryId, int $locationId, string $conditionType): void
{
    $items = getSetPartsList($pdo, $inventoryId, true, 'en');
    foreach ($items as $item) {
        if ($item['color_id'] === null || $item['quantity'] <= 0) {
            continue;
        }
        setStorageItemSpareQuantity($locationId, $item['part_id'], $item['color_id'], $conditionType, $item['quantity']);
    }
}

/**
 * Seeds owned_set_minifigs with the set's full nominal minifig quantities
 * (0 damaged) — same "starts complete, corrected during inventory" pattern
 * as the regular parts.
 */
function materializeOwnedSetMinifigs(PDO $pdo, int $ownedSetId, int $inventoryId): void
{
    $figs = getSetMinifigsList($pdo, $inventoryId);
    if (empty($figs)) {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO owned_set_minifigs (owned_set_id, minifig_id, quantity, damaged_quantity) VALUES (?, ?, ?, 0)');
    foreach ($figs as $fig) {
        if ($fig['quantity'] <= 0) {
            continue;
        }
        $stmt->execute([$ownedSetId, $fig['minifig_id'], $fig['quantity']]);
    }
}

/**
 * Creates a new owned-set instance: a dedicated storage_locations node
 * (location_type 'owned_set', never offered in the manual "add location"
 * form — see getLocationTypes()) as a child of $parentLocationId, the
 * owned_sets metadata row, and materializes the set's parts into that new
 * location's stock.
 */
function addOwnedSet(
    PDO $pdo,
    int $setId,
    ?int $parentLocationId,
    string $conditionType,
    bool $hasInstructions,
    bool $hasBox,
    bool $boxComplete,
    ?string $notes,
    ?int $userId,
    ?string $instructionsNotes = null,
    ?string $boxNotes = null,
    ?string $boxCompleteNotes = null,
    ?int $inventoryId = null,
    bool $stickersApplied = false,
    ?string $stickersNotes = null
): int {
    $set = getSetById($pdo, $setId);
    if ($set === null) {
        throw new RuntimeException('Set nicht gefunden.');
    }

    // A still-sealed set trivially has its instructions, box, and a complete
    // box — enforced server-side too, not just via the wizard's disabled
    // checkboxes, since those wouldn't stop a direct POST. Stickers are the
    // opposite: a sealed set trivially has NOT had its stickers applied yet.
    if ($conditionType === 'new') {
        $hasInstructions = true;
        $hasBox = true;
        $boxComplete = true;
        $stickersApplied = false;
    }

    // No explicit choice (set only has one revision, or an older caller
    // that predates version selection) — same "newest" resolution used
    // everywhere else before this feature existed.
    if ($inventoryId === null) {
        $inventoryId = getSetInventoryId($pdo, $set['rebrickable_set_num']);
    }

    $instanceNumber = getNextOwnedSetInstanceNumber($pdo, $setId);
    $locationName = $set['name'] . ' (' . $set['rebrickable_set_num'] . ') #' . $instanceNumber;
    $locationId = createStorageLocation($parentLocationId, $locationName, 'owned_set');

    $stmt = $pdo->prepare(
        'INSERT INTO owned_sets (set_id, inventory_id, location_id, condition_type, has_instructions, has_box, box_complete, notes, instructions_notes, box_notes, box_complete_notes, stickers_applied, stickers_notes, added_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $setId,
        $inventoryId,
        $locationId,
        $conditionType,
        $hasInstructions ? 1 : 0,
        $hasBox ? 1 : 0,
        $boxComplete ? 1 : 0,
        $notes,
        $instructionsNotes,
        $boxNotes,
        $boxCompleteNotes,
        $stickersApplied ? 1 : 0,
        $stickersNotes,
        $userId,
    ]);
    $ownedSetId = (int) $pdo->lastInsertId();

    if ($inventoryId !== null) {
        materializeOwnedSetStock($pdo, $inventoryId, $locationId, $conditionType, $userId);
        materializeOwnedSetSpares($pdo, $inventoryId, $locationId, $conditionType);
        materializeOwnedSetMinifigs($pdo, $ownedSetId, $inventoryId);
    }

    return $ownedSetId;
}

/**
 * Shared by the owned_set_detail page's inventory editor and the
 * add-to-collection wizard's inline inventory step — both post the same
 * parallel "owned[part:color]=qty" / "damaged[part:color]=qty" shape, one
 * via a page reload and the other via fetch().
 *
 * @param array<string, mixed> $ownedInput
 * @param array<string, mixed> $damagedInput
 */
function applyOwnedSetInventory(PDO $pdo, array $ownedSet, array $ownedInput, array $damagedInput, ?int $userId): void
{
    $nominalByKey = [];
    foreach (getOwnedSetPartsWithStatus($pdo, $ownedSet, 'en') as $part) {
        $nominalByKey[$part['part_id'] . ':' . $part['color_id']] = $part['nominal_quantity'];
    }
    foreach ($ownedInput as $key => $rawOwned) {
        if (!isset($nominalByKey[$key])) {
            continue;
        }
        [$partId, $colorId] = array_map('intval', explode(':', (string) $key, 2));
        $ownedQuantity = max(0, (int) $rawOwned);
        $damagedQuantity = max(0, (int) ($damagedInput[$key] ?? 0));
        setOwnedSetPartInventory($pdo, $ownedSet, $partId, $colorId, $nominalByKey[$key], $ownedQuantity, $damagedQuantity, $userId);
    }
}

/**
 * Same shape as applyOwnedSetInventory(), for the sticker-sheet category
 * (see getOwnedSetStickerPartsWithStatus()) — same storage_items columns
 * as the regular parts, just its own step/tab.
 */
function applyOwnedSetStickerInventory(PDO $pdo, array $ownedSet, array $ownedInput, array $damagedInput, ?int $userId): void
{
    $nominalByKey = [];
    foreach (getOwnedSetStickerPartsWithStatus($pdo, $ownedSet, 'en') as $part) {
        $nominalByKey[$part['part_id'] . ':' . $part['color_id']] = $part['nominal_quantity'];
    }
    foreach ($ownedInput as $key => $rawOwned) {
        if (!isset($nominalByKey[$key])) {
            continue;
        }
        [$partId, $colorId] = array_map('intval', explode(':', (string) $key, 2));
        $ownedQuantity = max(0, (int) $rawOwned);
        $damagedQuantity = max(0, (int) ($damagedInput[$key] ?? 0));
        setOwnedSetPartInventory($pdo, $ownedSet, $partId, $colorId, $nominalByKey[$key], $ownedQuantity, $damagedQuantity, $userId);
    }
}

/**
 * Same shape again, for spares — writes via setOwnedSetPartSpareInventory()
 * (spare_quantity/spare_damaged_quantity) instead.
 */
function applyOwnedSetSpareInventory(PDO $pdo, array $ownedSet, array $ownedInput, array $damagedInput): void
{
    $nominalByKey = [];
    foreach (getOwnedSetSparePartsWithStatus($pdo, $ownedSet, 'en') as $part) {
        $nominalByKey[$part['part_id'] . ':' . $part['color_id']] = $part['nominal_quantity'];
    }
    foreach ($ownedInput as $key => $rawOwned) {
        if (!isset($nominalByKey[$key])) {
            continue;
        }
        [$partId, $colorId] = array_map('intval', explode(':', (string) $key, 2));
        $ownedQuantity = max(0, (int) $rawOwned);
        $damagedQuantity = max(0, (int) ($damagedInput[$key] ?? 0));
        setOwnedSetPartSpareInventory($pdo, $ownedSet, $partId, $colorId, $nominalByKey[$key], $ownedQuantity, $damagedQuantity);
    }
}

/**
 * Upserts owned_set_minifigs for every minifig key present in $ownedInput —
 * same "owned" (clamped to nominal) + "damaged" (clamped to owned, still
 * present not missing) shape as the part-based categories.
 */
function applyOwnedSetMinifigInventory(PDO $pdo, array $ownedSet, array $ownedInput, array $damagedInput): void
{
    $nominalByKey = [];
    foreach (getOwnedSetMinifigsWithStatus($pdo, $ownedSet) as $fig) {
        $nominalByKey[$fig['minifig_id']] = $fig['nominal_quantity'];
    }
    $stmt = $pdo->prepare(
        'INSERT INTO owned_set_minifigs (owned_set_id, minifig_id, quantity, damaged_quantity)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), damaged_quantity = VALUES(damaged_quantity)'
    );
    foreach ($ownedInput as $key => $rawOwned) {
        $minifigId = (int) $key;
        if (!isset($nominalByKey[$minifigId])) {
            continue;
        }
        $ownedQuantity = max(0, min((int) $rawOwned, $nominalByKey[$minifigId]));
        $damagedQuantity = max(0, min((int) ($damagedInput[$key] ?? 0), $ownedQuantity));
        $stmt->execute([$ownedSet['id'], $minifigId, $ownedQuantity, $damagedQuantity]);
    }
}

/**
 * Upserts owned_set_minifig_parts for one minifig's constituent parts —
 * same clamp-to-nominal / clamp-damaged-to-owned shape as
 * applyOwnedSetInventory(), scoped to a single $minifigId within this
 * instance instead of the instance's own regular parts.
 */
function applyOwnedSetMinifigPartInventory(PDO $pdo, array $ownedSet, int $minifigId, string $figNum, int $minifigNominalCount, array $ownedInput, array $damagedInput): void
{
    $nominalByKey = [];
    foreach (getOwnedSetMinifigPartsWithStatus($pdo, $ownedSet, $minifigId, $figNum, $minifigNominalCount) as $part) {
        $nominalByKey[$part['part_id'] . ':' . $part['color_id']] = $part['nominal_quantity'];
    }
    $stmt = $pdo->prepare(
        'INSERT INTO owned_set_minifig_parts (owned_set_id, minifig_id, part_id, color_id, quantity, damaged_quantity)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), damaged_quantity = VALUES(damaged_quantity)'
    );
    foreach ($ownedInput as $key => $rawOwned) {
        if (!isset($nominalByKey[$key])) {
            continue;
        }
        [$partId, $colorId] = array_map('intval', explode(':', (string) $key, 2));
        $ownedQuantity = max(0, min((int) $rawOwned, $nominalByKey[$key]));
        $damagedQuantity = max(0, min((int) ($damagedInput[$key] ?? 0), $ownedQuantity));
        $stmt->execute([$ownedSet['id'], $minifigId, $partId, $colorId, $ownedQuantity, $damagedQuantity]);
    }
}

/**
 * Records how many of $partId/$colorId this instance actually has
 * ($ownedQuantity, clamped to the nominal count — the rest is "missing",
 * computed as nominal - owned, never stored) and how many of those are
 * damaged-but-present ($damagedQuantity, clamped to $ownedQuantity — damaged
 * parts are still "owned", not "missing"). Needs the nominal quantity as
 * input (the caller already has it from getOwnedSetPartsWithStatus(), no
 * need to re-derive it here).
 */
function setOwnedSetPartInventory(PDO $pdo, array $ownedSet, int $partId, int $colorId, int $nominalQuantity, int $ownedQuantity, int $damagedQuantity, ?int $userId): void
{
    $ownedQuantity = max(0, min($ownedQuantity, $nominalQuantity));
    $damagedQuantity = max(0, min($damagedQuantity, $ownedQuantity));
    setStorageItemQuantity($ownedSet['location_id'], $partId, $colorId, $ownedSet['condition_type'], $ownedQuantity, $userId, $damagedQuantity);
}

/**
 * Same as setOwnedSetPartInventory(), for the spare_quantity/
 * spare_damaged_quantity columns (see setStorageItemSpareQuantity()'s doc
 * comment for why spares can't share the regular columns).
 */
function setOwnedSetPartSpareInventory(PDO $pdo, array $ownedSet, int $partId, int $colorId, int $nominalQuantity, int $ownedQuantity, int $damagedQuantity): void
{
    $ownedQuantity = max(0, min($ownedQuantity, $nominalQuantity));
    $damagedQuantity = max(0, min($damagedQuantity, $ownedQuantity));
    setStorageItemSpareQuantity($ownedSet['location_id'], $partId, $colorId, $ownedSet['condition_type'], $ownedQuantity, $damagedQuantity);
}

/**
 * A still-sealed ("new") set can't meaningfully have missing parts — its
 * bags are unopened, so nothing can be verified without opening it, which
 * *is* the transition to "used". This is therefore the only way a
 * condition_type ever changes after creation: one-way (new -> used, never
 * back), and never a free-standing form field. Migrates the instance's
 * already-materialized storage_items rows from condition_type 'new' to
 * 'used' in place (a plain UPDATE, not a new INSERT) so a later
 * setOwnedSetPartInventory() call — which writes under the *current*
 * condition_type — adjusts those same rows instead of creating duplicates
 * alongside them.
 */
function openOwnedSet(PDO $pdo, array $ownedSet): void
{
    if ($ownedSet['condition_type'] !== 'new') {
        return;
    }
    $pdo->prepare("UPDATE storage_items SET condition_type = 'used' WHERE location_id = ? AND condition_type = 'new'")
        ->execute([$ownedSet['location_id']]);
    $pdo->prepare("UPDATE owned_sets SET condition_type = 'used' WHERE id = ?")->execute([$ownedSet['id']]);
}

/**
 * Removes an owned-set instance entirely: clears its stock (storage_items'
 * FK to storage_locations is ON DELETE RESTRICT, so this has to happen
 * before the location itself can go), unlinks its photo files, then
 * deletes the DB rows (owned_set_photos cascades from owned_sets, but the
 * location doesn't cascade from it — deleted explicitly, last).
 */
function removeOwnedSet(PDO $pdo, int $ownedSetId): void
{
    $ownedSet = getOwnedSetById($pdo, $ownedSetId);
    if ($ownedSet === null) {
        return;
    }

    foreach (getOwnedSetPhotos($pdo, $ownedSetId) as $photo) {
        @unlink(dirname(__DIR__) . '/' . $photo['stored_path']);
    }

    $pdo->prepare('DELETE FROM storage_items WHERE location_id = ?')->execute([$ownedSet['location_id']]);
    $pdo->prepare('DELETE FROM owned_sets WHERE id = ?')->execute([$ownedSetId]);
    deleteStorageLocation($ownedSet['location_id']);
}

/**
 * "Verkaufen": records a sale (owned_set_sales — the only place this
 * survives, since the instance itself is gone right after) and then does
 * exactly what removeOwnedSet() does. Just a thin wrapper, not a variant of
 * removal — the actual per-listing-template generation (Kleinanzeigen,
 * eBay, ...) the user described is a separate, not-yet-built feature; this
 * only captures the basic sale facts (price/date/platform/notes) that
 * feature will eventually need.
 */
function sellOwnedSet(PDO $pdo, int $ownedSetId, ?float $price, ?string $soldAt, ?string $platform, ?string $notes, ?int $userId): void
{
    $ownedSet = getOwnedSetById($pdo, $ownedSetId);
    if ($ownedSet === null) {
        throw new RuntimeException('Set-Exemplar nicht gefunden.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO owned_set_sales (set_id, rebrickable_set_num, set_name, price, sold_at, platform, notes, sold_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $ownedSet['set_id'],
        $ownedSet['rebrickable_set_num'],
        $ownedSet['name'],
        $price,
        $soldAt,
        $platform,
        $notes,
        $userId,
    ]);

    removeOwnedSet($pdo, $ownedSetId);
}

/**
 * "Bearbeiten": updates the same fields the add-wizard's step 2 captures at
 * creation time, after the fact. $markAsUsed can only ever move condition
 * new -> used here, never back — same one-way rule openOwnedSet() already
 * enforces via the dedicated "Set öffnen" button; this just gives that
 * same transition a second entry point, going through openOwnedSet() so
 * storage_items' own condition_type stays in sync too, not just this row.
 * The same "a sealed set trivially has everything, no stickers yet"
 * server-side forcing addOwnedSet() applies at creation applies again here
 * (still evaluated against the row's condition *after* any transition
 * above), for the same reason — a direct POST could otherwise bypass the
 * edit modal's own disabled checkboxes.
 */
function updateOwnedSetDetails(
    PDO $pdo,
    int $ownedSetId,
    bool $markAsUsed,
    bool $hasInstructions,
    bool $hasBox,
    bool $boxComplete,
    bool $stickersApplied,
    ?string $notes,
    ?string $instructionsNotes,
    ?string $boxNotes,
    ?string $boxCompleteNotes,
    ?string $stickersNotes
): void {
    $ownedSet = getOwnedSetById($pdo, $ownedSetId);
    if ($ownedSet === null) {
        throw new RuntimeException('Set-Exemplar nicht gefunden.');
    }

    if ($markAsUsed && $ownedSet['condition_type'] === 'new') {
        openOwnedSet($pdo, $ownedSet);
        $ownedSet['condition_type'] = 'used';
    }

    if ($ownedSet['condition_type'] === 'new') {
        $hasInstructions = true;
        $hasBox = true;
        $boxComplete = true;
        $stickersApplied = false;
    }

    $stmt = $pdo->prepare(
        'UPDATE owned_sets SET has_instructions = ?, has_box = ?, box_complete = ?, stickers_applied = ?,
                notes = ?, instructions_notes = ?, box_notes = ?, box_complete_notes = ?, stickers_notes = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $hasInstructions ? 1 : 0,
        $hasBox ? 1 : 0,
        $boxComplete ? 1 : 0,
        $stickersApplied ? 1 : 0,
        $notes,
        $instructionsNotes,
        $boxNotes,
        $boxCompleteNotes,
        $stickersNotes,
        $ownedSetId,
    ]);
}

/**
 * "Bearbeiten" modal — same fields as the add-wizard's step 2, prefilled
 * with the instance's current values, but no version/location/inventory
 * steps (those are separate actions — "Verschieben" for location, the
 * inventory tabs themselves for stock). Reuses the wizard's own
 * .owned-set-wizard-detail-group markup/JS forcing behavior (see
 * renderAddOwnedSetWizardModal() in src/owned_set_wizard.php) so a still-
 * sealed instance's fields behave identically here — force-checked/
 * disabled while "Neu" is selected, same reasoning as there. Only shown as
 * editable at all when the instance is still 'new'; once opened, "Zustand"
 * is a fixed label — condition_type has no supported used -> new path
 * anywhere else in the app, so this modal doesn't invent one either.
 */
function renderOwnedSetEditModal(array $ownedSet): string
{
    $isNew = $ownedSet['condition_type'] === 'new';

    $html = '<div class="modal-overlay" id="owned-set-edit-modal" style="display:none;">';
    $html .= '<div class="modal-box">';
    $html .= '<button type="button" class="modal-close" id="owned-set-edit-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<h2>' . htmlspecialchars(t('owned_set_edit_heading')) . '</h2>';
    $html .= '<form method="post" id="owned-set-edit-form">';
    $html .= '<input type="hidden" name="action" value="save_owned_set_details">';
    $html .= '<input type="hidden" name="owned_set_id" value="' . (int) $ownedSet['id'] . '">';

    if ($isNew) {
        $html .= '<label class="checkbox-label"><input type="radio" name="owned-set-edit-condition" value="new" checked> ' . htmlspecialchars(t('owned_set_condition_new')) . '</label>';
        $html .= '<label class="checkbox-label"><input type="radio" name="owned-set-edit-condition" value="used"> ' . htmlspecialchars(t('owned_set_condition_used')) . '</label>';
    } else {
        $html .= '<p>' . htmlspecialchars(t('owned_set_field_condition')) . ': <strong>' . htmlspecialchars(t('owned_set_condition_used')) . '</strong></p>';
    }

    $detailFields = [
        ['has-instructions', 'owned_set_has_instructions', 'instructions-notes', 'owned_set_instructions_notes_label', $ownedSet['has_instructions'], $ownedSet['instructions_notes']],
        ['has-box', 'owned_set_has_box', 'box-notes', 'owned_set_box_notes_label', $ownedSet['has_box'], $ownedSet['box_notes']],
        ['has-box-complete', 'owned_set_box_complete', 'box-complete-notes', 'owned_set_box_complete_notes_label', $ownedSet['box_complete'], $ownedSet['box_complete_notes']],
        ['stickers-applied', 'owned_set_stickers_applied', 'stickers-notes', 'owned_set_stickers_notes_label', $ownedSet['stickers_applied'], $ownedSet['stickers_notes']],
    ];
    foreach ($detailFields as [$checkboxId, $checkboxLabelKey, $notesId, $notesLabelKey, $checked, $noteValue]) {
        $html .= '<div class="owned-set-wizard-detail-group">';
        $html .= '<label class="checkbox-label"><input type="checkbox" id="owned-set-edit-' . $checkboxId . '" name="' . str_replace('-', '_', $checkboxId) . '" value="1"' . ($checked ? ' checked' : '') . '' . ($isNew ? ' disabled' : '') . '> ' . htmlspecialchars(t($checkboxLabelKey)) . '</label>';
        $html .= '<textarea class="owned-set-wizard-subnote" id="owned-set-edit-' . $notesId . '" name="' . str_replace('-', '_', $notesId) . '" rows="2" placeholder="' . htmlspecialchars(t($notesLabelKey)) . '" style="' . ($checked ? '' : 'display:none;') . '">' . htmlspecialchars((string) $noteValue) . '</textarea>';
        $html .= '</div>';
    }

    $html .= '<label>' . htmlspecialchars(t('owned_set_notes_label')) . '<textarea name="notes" rows="4">' . htmlspecialchars((string) $ownedSet['notes']) . '</textarea></label>';
    $html .= '<p class="owned-set-wizard-error" id="owned-set-edit-error"></p>';
    $html .= '<button type="submit">' . htmlspecialchars(t('owned_set_save_button')) . '</button>';
    $html .= '</form>';
    $html .= '</div></div>';

    $html .= <<<SCRIPT
<script>
(function(){
  var openBtn = document.getElementById('owned-set-edit-open');
  var modal = document.getElementById('owned-set-edit-modal');
  var closeBtn = document.getElementById('owned-set-edit-modal-close');
  if (!openBtn || !modal || !closeBtn) {
    return;
  }

  function openModal() {
    modal.style.display = 'flex';
  }
  function closeModal() {
    modal.style.display = 'none';
  }
  openBtn.addEventListener('click', function(e) {
    e.preventDefault();
    openModal();
  });
  closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function(e) {
    if (e.target === modal) {
      closeModal();
    }
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && modal.style.display !== 'none') {
      closeModal();
    }
  });

  var detailPairs = [
    ['instructions', 'owned-set-edit-has-instructions', true],
    ['box', 'owned-set-edit-has-box', true],
    ['box-complete', 'owned-set-edit-has-box-complete', true],
    ['stickers', 'owned-set-edit-stickers-applied', false]
  ];
  detailPairs.forEach(function(pair) {
    var checkbox = document.getElementById(pair[1]);
    var notes = document.getElementById('owned-set-edit-' + pair[0] + '-notes');
    if (checkbox && notes) {
      checkbox.addEventListener('change', function() {
        notes.style.display = checkbox.checked ? 'block' : 'none';
      });
    }
  });

  modal.querySelectorAll('input[name="owned-set-edit-condition"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
      if (!radio.checked) {
        return;
      }
      var isNew = radio.value === 'new';
      detailPairs.forEach(function(pair) {
        var checkbox = document.getElementById(pair[1]);
        var notes = document.getElementById('owned-set-edit-' + pair[0] + '-notes');
        checkbox.disabled = isNew;
        if (isNew) {
          checkbox.checked = pair[2];
          notes.style.display = checkbox.checked ? 'block' : 'none';
        }
      });
    });
  });
})();
</script>
SCRIPT;

    return $html;
}

/**
 * "Verschieben" modal — the same 3-level cascading location picker as the
 * add-wizard's own location step (renderAddOwnedSetWizardModal() in
 * src/owned_set_wizard.php), duplicated rather than shared (self-contained
 * per-modal script, same convention as everywhere else in this file), but
 * re-parenting the instance's *existing* storage node (moveStorageLocation()
 * in src/storage.php) instead of picking a parent for a brand-new one.
 */
function renderOwnedSetMoveModal(array $ownedSet): string
{
    $currentPath = implode(' » ', array_column(getStorageLocationAncestors($ownedSet['location_id']), 'name'));

    $html = '<div class="modal-overlay" id="owned-set-move-modal" style="display:none;">';
    $html .= '<div class="modal-box">';
    $html .= '<button type="button" class="modal-close" id="owned-set-move-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<h2>' . htmlspecialchars(t('owned_set_move_heading')) . '</h2>';
    $html .= '<p class="hint">' . htmlspecialchars(t('owned_set_move_current', ['path' => $currentPath])) . '</p>';
    $html .= '<form method="post" id="owned-set-move-form">';
    $html .= '<input type="hidden" name="action" value="move_owned_set">';
    $html .= '<input type="hidden" name="owned_set_id" value="' . (int) $ownedSet['id'] . '">';
    $html .= '<input type="hidden" name="parent_location_id" id="owned-set-move-parent-id">';

    $locationLevels = [
        [1, 'add_stock_level1_label'],
        [2, 'add_stock_level2_label'],
        [3, 'add_stock_level3_label'],
    ];
    foreach ($locationLevels as [$level, $labelKey]) {
        $html .= '<div class="location-level">';
        $html .= '<span class="location-level-label">' . htmlspecialchars(t($labelKey)) . '</span>';
        $html .= '<select id="owned-set-move-location-' . $level . '"' . ($level > 1 ? ' disabled' : '') . '>';
        $html .= '<option value="">' . htmlspecialchars(t('add_stock_select_placeholder')) . '</option>';
        if ($level === 1) {
            foreach (getChildLocations(null) as $loc) {
                $html .= '<option value="' . (int) $loc['id'] . '">' . htmlspecialchars($loc['name']) . '</option>';
            }
        }
        $html .= '</select>';
        $html .= '<span class="location-hint" id="owned-set-move-location-' . $level . '-hint"></span>';
        $html .= '</div>';
    }

    $html .= '<p class="owned-set-wizard-error" id="owned-set-move-error"></p>';
    $html .= '<button type="submit">' . htmlspecialchars(t('owned_set_move_button')) . '</button>';
    $html .= '</form>';
    $html .= '</div></div>';

    $labelsJson = json_encode([
        'selectPlaceholder' => t('add_stock_select_placeholder'),
        'noChildren' => t('add_stock_no_children'),
        'locationRequired' => t('owned_set_wizard_location_required'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html .= <<<SCRIPT
<script>
(function(){
  var texts = $labelsJson;
  var openBtn = document.getElementById('owned-set-move-open');
  var modal = document.getElementById('owned-set-move-modal');
  var closeBtn = document.getElementById('owned-set-move-modal-close');
  var form = document.getElementById('owned-set-move-form');
  var loc1 = document.getElementById('owned-set-move-location-1');
  var loc2 = document.getElementById('owned-set-move-location-2');
  var loc3 = document.getElementById('owned-set-move-location-3');
  var loc2Hint = document.getElementById('owned-set-move-location-2-hint');
  var loc3Hint = document.getElementById('owned-set-move-location-3-hint');
  var parentIdField = document.getElementById('owned-set-move-parent-id');
  var errorEl = document.getElementById('owned-set-move-error');
  if (!openBtn || !modal || !closeBtn || !form || !loc1 || !loc2 || !loc3) {
    return;
  }

  function fillLocationSelect(select, hint, parentId) {
    hint.textContent = '';
    var params = new URLSearchParams();
    params.set('action', 'location_children');
    params.set('parent_id', parentId);
    return fetch('?' + params.toString(), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        select.innerHTML = '<option value="">' + texts.selectPlaceholder + '</option>';
        (data.children || []).forEach(function(loc) {
          var opt = document.createElement('option');
          opt.value = loc.id;
          opt.textContent = loc.name;
          select.appendChild(opt);
        });
        var hasChildren = (data.children || []).length > 0;
        select.disabled = !hasChildren;
        if (!hasChildren) {
          hint.textContent = texts.noChildren;
        }
      });
  }

  loc1.addEventListener('change', function() {
    loc2.innerHTML = '<option value="">' + texts.selectPlaceholder + '</option>';
    loc3.innerHTML = '<option value="">' + texts.selectPlaceholder + '</option>';
    loc2.disabled = true;
    loc3.disabled = true;
    loc2Hint.textContent = '';
    loc3Hint.textContent = '';
    if (loc1.value) {
      fillLocationSelect(loc2, loc2Hint, loc1.value);
    }
  });
  loc2.addEventListener('change', function() {
    loc3.innerHTML = '<option value="">' + texts.selectPlaceholder + '</option>';
    loc3.disabled = true;
    loc3Hint.textContent = '';
    if (loc2.value) {
      fillLocationSelect(loc3, loc3Hint, loc2.value);
    }
  });

  function openModal() {
    modal.style.display = 'flex';
  }
  function closeModal() {
    modal.style.display = 'none';
  }
  openBtn.addEventListener('click', function(e) {
    e.preventDefault();
    openModal();
  });
  closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function(e) {
    if (e.target === modal) {
      closeModal();
    }
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && modal.style.display !== 'none') {
      closeModal();
    }
  });

  form.addEventListener('submit', function(e) {
    var selected = loc3.value || loc2.value || loc1.value;
    if (!selected) {
      e.preventDefault();
      errorEl.textContent = texts.locationRequired;
      return;
    }
    parentIdField.value = selected;
  });
})();
</script>
SCRIPT;

    return $html;
}

/**
 * "Verkaufen" modal — captures the basic facts a future listing-template
 * feature (Kleinanzeigen/eBay/... — not built yet, per the user) will
 * eventually need, then removes the instance exactly like "Löschen" does
 * (sellOwnedSet() records the sale first, then calls removeOwnedSet()).
 * A plain form submit + confirm(), same as the existing remove-owned-set
 * form, not AJAX — the page navigates away either way once it succeeds.
 */
function renderOwnedSetSellModal(array $ownedSet): string
{
    $today = date('Y-m-d');

    $html = '<div class="modal-overlay" id="owned-set-sell-modal" style="display:none;">';
    $html .= '<div class="modal-box">';
    $html .= '<button type="button" class="modal-close" id="owned-set-sell-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<h2>' . htmlspecialchars(t('owned_set_sell_heading')) . '</h2>';
    $html .= '<form method="post" id="owned-set-sell-form">';
    $html .= '<input type="hidden" name="action" value="sell_owned_set">';
    $html .= '<input type="hidden" name="owned_set_id" value="' . (int) $ownedSet['id'] . '">';
    $html .= '<input type="hidden" name="set_id" value="' . (int) $ownedSet['set_id'] . '">';
    $html .= '<label>' . htmlspecialchars(t('owned_set_sell_price_label')) . '<input type="number" name="price" step="0.01" min="0"></label>';
    $html .= '<label>' . htmlspecialchars(t('owned_set_sell_date_label')) . '<input type="date" name="sold_at" value="' . htmlspecialchars($today) . '"></label>';
    $html .= '<label>' . htmlspecialchars(t('owned_set_sell_platform_label')) . '<input type="text" name="platform" placeholder="' . htmlspecialchars(t('owned_set_sell_platform_placeholder')) . '"></label>';
    $html .= '<label>' . htmlspecialchars(t('owned_set_notes_label')) . '<textarea name="notes" rows="3"></textarea></label>';
    $html .= '<button type="submit" class="owned-set-remove-button">' . htmlspecialchars(t('owned_set_sell_button')) . '</button>';
    $html .= '</form>';
    $html .= '</div></div>';

    $confirmJson = json_encode(t('owned_set_sell_confirm'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html .= <<<SCRIPT
<script>
(function(){
  var openBtn = document.getElementById('owned-set-sell-open');
  var modal = document.getElementById('owned-set-sell-modal');
  var closeBtn = document.getElementById('owned-set-sell-modal-close');
  var form = document.getElementById('owned-set-sell-form');
  if (!openBtn || !modal || !closeBtn || !form) {
    return;
  }

  function openModal() {
    modal.style.display = 'flex';
  }
  function closeModal() {
    modal.style.display = 'none';
  }
  openBtn.addEventListener('click', function(e) {
    e.preventDefault();
    openModal();
  });
  closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function(e) {
    if (e.target === modal) {
      closeModal();
    }
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && modal.style.display !== 'none') {
      closeModal();
    }
  });

  form.addEventListener('submit', function(e) {
    if (!window.confirm($confirmJson)) {
      e.preventDefault();
    }
  });
})();
</script>
SCRIPT;

    return $html;
}

/**
 * "Bricklink XML" trigger + three modals — the export is shown for copying
 * rather than downloaded straight away, since BrickLink's own "Wanted List
 * XML Upload" also just accepts pasted text.
 *
 * Clicking the button first fetches action=owned_set_bricklink_parts_missing
 * (cheap, DB-only) for the part_nums still missing a BrickLink id. If any
 * exist, the sync-progress modal opens (ring indicator) and runPartSync()
 * ticks through them client-side, one batch of up to REBRICKABLE_PART_BATCH_SIZE
 * per action=owned_set_bricklink_part_sync_tick request, pacing itself to
 * roughly 1 request/sec to respect Rebrickable's API limit — this is a
 * browser-driven loop, not a server-side wait, specifically so a set with
 * many missing parts can't tie up a single shared-hosting request for longer
 * than its own timeout allows.
 *
 * Once part syncing is done (or was never needed), checkAndProceed() calls
 * action=owned_set_bricklink_xml_check to see whether every whole-missing
 * minifig has a resolvable BrickLink id (getOrFetchBricklinkMinifigId() in
 * this file, unrelated to the part sync above — minifigs have no API mapping
 * at all): if so, the result modal opens straight away with the XML text;
 * otherwise the manual-entry modal opens first, one row per minifig still
 * missing an id, each with a Rebrickable link (a human has to find the
 * BrickLink link on that page themselves — see the session's own research on
 * why neither Rebrickable's nor BrickLink's API exposes this mapping) and a
 * free-text input that accepts either a bare id or a pasted catalog URL
 * (parseBricklinkMinifigIdInput() extracts the id either way). Saving caches
 * whatever was entered (action=save_minifig_bricklink_id) so this never asks
 * about the same minifig twice, then opens the result modal with a freshly
 * rebuilt XML; "ohne diese Angaben anzeigen" opens it right away instead,
 * just skipping whatever's still unknown (same best-effort convention as the
 * color-mapping gaps in buildOwnedSetBricklinkXml()).
 *
 * The result modal's Download button builds the file client-side from the
 * same text shown in the textarea (Blob + temporary <a download>) instead of
 * hitting the server again, so copy and download can never drift apart.
 */
function renderOwnedSetBricklinkModal(array $ownedSet): string
{
    $html = '<div class="modal-overlay" id="owned-set-bricklink-modal" style="display:none;">';
    $html .= '<div class="modal-box">';
    $html .= '<button type="button" class="modal-close" id="owned-set-bricklink-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<h2>' . htmlspecialchars(t('owned_set_bricklink_manual_heading')) . '</h2>';
    $html .= '<p class="hint">' . htmlspecialchars(t('owned_set_bricklink_manual_intro')) . '</p>';
    $html .= '<form id="owned-set-bricklink-form">';
    $html .= '<div id="owned-set-bricklink-list"></div>';
    $html .= '<p class="owned-set-wizard-error" id="owned-set-bricklink-error"></p>';
    $html .= '<div class="owned-set-bricklink-modal-actions">';
    $html .= '<button type="button" id="owned-set-bricklink-skip">' . htmlspecialchars(t('owned_set_bricklink_manual_skip_button')) . '</button>';
    $html .= '<button type="submit">' . htmlspecialchars(t('owned_set_bricklink_manual_save_button')) . '</button>';
    $html .= '</div>';
    $html .= '</form>';
    $html .= '</div></div>';

    $html .= '<div class="modal-overlay" id="owned-set-bricklink-result-modal" style="display:none;">';
    $html .= '<div class="modal-box">';
    $html .= '<button type="button" class="modal-close" id="owned-set-bricklink-result-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<h2>' . htmlspecialchars(t('owned_set_bricklink_result_heading')) . '</h2>';
    $html .= '<p class="hint">' . htmlspecialchars(t('owned_set_bricklink_result_intro')) . '</p>';
    $html .= '<textarea id="owned-set-bricklink-xml-content" class="owned-set-bricklink-xml-textarea" rows="14" readonly></textarea>';
    $html .= '<div class="owned-set-bricklink-modal-actions">';
    $html .= '<button type="button" class="owned-set-bricklink-result-btn" id="owned-set-bricklink-copy">' . getActionIcon('copy') . '<span>' . htmlspecialchars(t('owned_set_bricklink_copy_button')) . '</span></button>';
    $html .= '<button type="button" class="owned-set-bricklink-result-btn owned-set-bricklink-result-btn-primary" id="owned-set-bricklink-download">' . getActionIcon('download') . '<span>' . htmlspecialchars(t('owned_set_bricklink_download_button')) . '</span></button>';
    $html .= '</div>';
    $html .= '</div></div>';

    $html .= '<div class="modal-overlay" id="owned-set-bricklink-sync-modal" style="display:none;">';
    $html .= '<div class="modal-box">';
    $html .= '<button type="button" class="modal-close" id="owned-set-bricklink-sync-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<h2>' . htmlspecialchars(t('owned_set_bricklink_sync_heading')) . '</h2>';
    $html .= '<p class="hint">' . htmlspecialchars(t('owned_set_bricklink_sync_intro')) . '</p>';
    $html .= '<div class="owned-set-bricklink-sync-ring-wrap">';
    $html .= '<svg class="owned-set-bricklink-sync-ring" viewBox="0 0 100 100">';
    $html .= '<circle class="owned-set-bricklink-sync-ring-track" cx="50" cy="50" r="42"/>';
    $html .= '<circle class="owned-set-bricklink-sync-ring-fill" id="owned-set-bricklink-sync-ring-fill" cx="50" cy="50" r="42"/>';
    $html .= '</svg>';
    $html .= '<span class="owned-set-bricklink-sync-ring-label" id="owned-set-bricklink-sync-percent">0%</span>';
    $html .= '</div>';
    $html .= '<p class="hint owned-set-bricklink-sync-status" id="owned-set-bricklink-sync-status"></p>';
    $html .= '</div></div>';

    $ownedSetId = (int) $ownedSet['id'];
    $xmlFilename = 'bricklink-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $ownedSet['rebrickable_set_num']) . '.xml';
    $labelsJson = json_encode([
        'errorRetry' => t('import_error_retry'),
        'rebrickableLinkLabel' => t('owned_set_bricklink_manual_rebrickable_link'),
        'inputPlaceholder' => t('owned_set_bricklink_manual_input_placeholder'),
        'copyLabel' => t('owned_set_bricklink_copy_button'),
        'copySuccess' => t('owned_set_bricklink_copy_success'),
        'xmlFilename' => $xmlFilename,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html .= <<<SCRIPT
<script>
(function(){
  var texts = $labelsJson;
  var ownedSetId = $ownedSetId;
  var openBtn = document.getElementById('owned-set-bricklink-open');
  var modal = document.getElementById('owned-set-bricklink-modal');
  var closeBtn = document.getElementById('owned-set-bricklink-modal-close');
  var listEl = document.getElementById('owned-set-bricklink-list');
  var form = document.getElementById('owned-set-bricklink-form');
  var errorEl = document.getElementById('owned-set-bricklink-error');
  var skipBtn = document.getElementById('owned-set-bricklink-skip');
  var resultModal = document.getElementById('owned-set-bricklink-result-modal');
  var resultCloseBtn = document.getElementById('owned-set-bricklink-result-modal-close');
  var xmlTextarea = document.getElementById('owned-set-bricklink-xml-content');
  var copyBtn = document.getElementById('owned-set-bricklink-copy');
  var downloadBtn = document.getElementById('owned-set-bricklink-download');
  var syncModal = document.getElementById('owned-set-bricklink-sync-modal');
  var syncCloseBtn = document.getElementById('owned-set-bricklink-sync-modal-close');
  var syncRingFill = document.getElementById('owned-set-bricklink-sync-ring-fill');
  var syncPercentEl = document.getElementById('owned-set-bricklink-sync-percent');
  var syncStatusEl = document.getElementById('owned-set-bricklink-sync-status');
  if (!openBtn || !modal || !closeBtn || !listEl || !form || !errorEl || !skipBtn
      || !resultModal || !resultCloseBtn || !xmlTextarea || !copyBtn || !downloadBtn
      || !syncModal || !syncCloseBtn || !syncRingFill || !syncPercentEl || !syncStatusEl) {
    return;
  }

  function openModal() {
    modal.style.display = 'flex';
  }
  function closeModal() {
    modal.style.display = 'none';
  }
  closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function(e) {
    if (e.target === modal) {
      closeModal();
    }
  });

  function showResultModal(xmlText) {
    xmlTextarea.value = xmlText || '';
    resultModal.style.display = 'flex';
  }
  function closeResultModal() {
    resultModal.style.display = 'none';
  }
  resultCloseBtn.addEventListener('click', closeResultModal);
  resultModal.addEventListener('click', function(e) {
    if (e.target === resultModal) {
      closeResultModal();
    }
  });

  var ringCircumference = 2 * Math.PI * 42;
  syncRingFill.style.strokeDasharray = String(ringCircumference);
  syncRingFill.style.strokeDashoffset = String(ringCircumference);

  function openSyncModal() {
    syncRingFill.style.strokeDashoffset = String(ringCircumference);
    syncPercentEl.textContent = '0%';
    syncStatusEl.textContent = '';
    syncModal.style.display = 'flex';
  }
  function closeSyncModal() {
    // Only hides it — the batch loop that opened it keeps running in the
    // background either way, same as the Rebrickable update modal's own
    // "closing just hides it" behavior.
    syncModal.style.display = 'none';
  }
  function updateSyncProgress(done, total) {
    var percent = total > 0 ? Math.round((done / total) * 100) : 100;
    syncRingFill.style.strokeDashoffset = String(ringCircumference * (1 - percent / 100));
    syncPercentEl.textContent = percent + '%';
    syncStatusEl.textContent = done + ' / ' + total;
  }
  syncCloseBtn.addEventListener('click', closeSyncModal);
  syncModal.addEventListener('click', function(e) {
    if (e.target === syncModal) {
      closeSyncModal();
    }
  });

  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') {
      return;
    }
    if (syncModal.style.display !== 'none') {
      closeSyncModal();
    } else if (resultModal.style.display !== 'none') {
      closeResultModal();
    } else if (modal.style.display !== 'none') {
      closeModal();
    }
  });

  function runPartSync(partNums, batchSize) {
    var total = partNums.length;
    var batches = [];
    for (var i = 0; i < partNums.length; i += batchSize) {
      batches.push(partNums.slice(i, i + batchSize));
    }
    var done = 0;
    openSyncModal();
    updateSyncProgress(0, total);

    function nextBatch(index) {
      if (index >= batches.length) {
        closeSyncModal();
        checkAndProceed();
        return;
      }
      var batch = batches[index];
      var startedAt = Date.now();
      var formData = new FormData();
      formData.set('action', 'owned_set_bricklink_part_sync_tick');
      formData.set('part_nums', batch.join(','));
      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .catch(function() { return { success: false }; })
        .then(function() {
          done += batch.length;
          updateSyncProgress(done, total);
          // API allows ~1 req/sec on average — pace the next tick from when
          // this one started, not from when it finished, so a slow response
          // doesn't just get "made up for" by firing the next one instantly.
          var elapsed = Date.now() - startedAt;
          var wait = Math.max(0, 1000 - elapsed);
          setTimeout(function() { nextBatch(index + 1); }, wait);
        });
    }
    nextBatch(0);
  }

  var copyResetTimer = null;
  copyBtn.addEventListener('click', function() {
    var label = copyBtn.querySelector('span');
    function showCopied() {
      if (copyResetTimer) {
        clearTimeout(copyResetTimer);
      }
      label.textContent = texts.copySuccess;
      copyResetTimer = setTimeout(function() { label.textContent = texts.copyLabel; }, 1500);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(xmlTextarea.value).then(showCopied).catch(function() {
        xmlTextarea.select();
        document.execCommand('copy');
        showCopied();
      });
    } else {
      xmlTextarea.select();
      document.execCommand('copy');
      showCopied();
    }
  });

  downloadBtn.addEventListener('click', function() {
    var blob = new Blob([xmlTextarea.value], { type: 'application/xml' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = texts.xmlFilename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  });

  function renderManualList(items) {
    listEl.innerHTML = '';
    items.forEach(function(item) {
      var row = document.createElement('div');
      row.className = 'owned-set-bricklink-manual-row';

      var img = document.createElement('span');
      img.className = 'part-card-image';
      if (item.thumbnail) {
        img.innerHTML = '<img src="' + item.thumbnail + '" alt="">';
      }
      row.appendChild(img);

      var info = document.createElement('div');
      info.className = 'owned-set-bricklink-manual-info';
      var name = document.createElement('p');
      name.textContent = item.name;
      info.appendChild(name);
      var link = document.createElement('a');
      link.href = 'https://rebrickable.com/minifigs/' + encodeURIComponent(item.fig_num) + '/';
      link.target = '_blank';
      link.rel = 'noopener';
      link.textContent = texts.rebrickableLinkLabel;
      info.appendChild(link);
      var input = document.createElement('input');
      input.type = 'text';
      input.placeholder = texts.inputPlaceholder;
      input.dataset.minifigId = item.minifig_id;
      info.appendChild(input);
      row.appendChild(info);

      listEl.appendChild(row);
    });
  }

  var lastCheckXml = '';

  function checkAndProceed() {
    var params = new URLSearchParams();
    params.set('action', 'owned_set_bricklink_xml_check');
    params.set('owned_set_id', String(ownedSetId));
    fetch('?' + params.toString(), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (!res.success) {
          throw new Error(res.message || texts.errorRetry);
        }
        lastCheckXml = res.xml;
        if (res.ready) {
          showResultModal(res.xml);
          return;
        }
        renderManualList(res.needsManualId);
        errorEl.textContent = '';
        openModal();
      })
      .catch(function() {
        // Best-effort check — if it fails outright, open the manual modal
        // just to surface the error (no rows, save/skip both make no sense
        // here), instead of the button silently doing nothing.
        renderManualList([]);
        errorEl.textContent = texts.errorRetry;
        openModal();
      });
  }

  openBtn.addEventListener('click', function(e) {
    e.preventDefault();
    var params = new URLSearchParams();
    params.set('action', 'owned_set_bricklink_parts_missing');
    params.set('owned_set_id', String(ownedSetId));
    fetch('?' + params.toString(), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success && res.partNums && res.partNums.length > 0) {
          runPartSync(res.partNums, res.batchSize || 50);
        } else {
          checkAndProceed();
        }
      })
      .catch(function() {
        // Best-effort — if even this cheap DB-only check fails, skip
        // straight to the real check/build, which degrades gracefully on
        // its own (falls back to raw part_nums in the XML).
        checkAndProceed();
      });
  });

  skipBtn.addEventListener('click', function() {
    closeModal();
    showResultModal(lastCheckXml);
  });

  form.addEventListener('submit', function(e) {
    e.preventDefault();
    errorEl.textContent = '';
    var inputs = listEl.querySelectorAll('input[data-minifig-id]');
    var saves = [];
    inputs.forEach(function(input) {
      var value = input.value.trim();
      if (!value) {
        return;
      }
      var formData = new FormData();
      formData.set('action', 'save_minifig_bricklink_id');
      formData.set('minifig_id', input.dataset.minifigId);
      formData.set('bricklink_id_input', value);
      saves.push(fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' }).then(function(r) { return r.json(); }));
    });
    Promise.all(saves).then(function(results) {
      var failed = results.find(function(r) { return !r.success; });
      if (failed) {
        errorEl.textContent = failed.message || texts.errorRetry;
        return;
      }
      closeModal();
      // Re-check instead of trusting the pre-save xml: still-blank inputs
      // mean some minifigs may remain unresolved, which should reopen this
      // same modal showing only those, not silently drop them from the XML.
      checkAndProceed();
    }).catch(function() {
      errorEl.textContent = texts.errorRetry;
    });
  });
})();
</script>
SCRIPT;

    return $html;
}

/**
 * @return array<int, array{id:int, caption:?string, original_filename:string, stored_path:string, file_size:int, uploaded_at:string}>
 */
function getOwnedSetPhotos(PDO $pdo, int $ownedSetId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, caption, original_filename, stored_path, file_size, uploaded_at
         FROM owned_set_photos WHERE owned_set_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$ownedSetId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['file_size'] = (int) $row['file_size'];
    }
    unset($row);
    return $rows;
}

function addOwnedSetPhoto(PDO $pdo, int $ownedSetId, ?string $caption, string $originalFilename, string $storedPath, int $fileSize, ?int $userId): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO owned_set_photos (owned_set_id, caption, original_filename, stored_path, file_size, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$ownedSetId, $caption, $originalFilename, $storedPath, $fileSize, $userId]);
    return (int) $pdo->lastInsertId();
}

/**
 * Returns the deleted row (so the caller can unlink the file) — null if no
 * such photo exists.
 */
function deleteOwnedSetPhoto(PDO $pdo, int $photoId): ?array
{
    $stmt = $pdo->prepare('SELECT id, stored_path FROM owned_set_photos WHERE id = ?');
    $stmt->execute([$photoId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $pdo->prepare('DELETE FROM owned_set_photos WHERE id = ?')->execute([$photoId]);
    return $row;
}

/**
 * Owned instances whose catalog set belongs to one of $themeIds (typically
 * a theme plus every descendant, from getThemeAndDescendantIds()) — powers
 * my_sets_themes' drill-down results grid, the owned-instance equivalent
 * of searchSets().
 *
 * @param int[] $themeIds
 * @return array<int, array{id:int, set_id:int, rebrickable_set_num:string, name:string, thumbnail:?string, location_id:int, condition_type:string, created_at:string}>
 */
function getOwnedSetsForThemes(PDO $pdo, array $themeIds): array
{
    if (empty($themeIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($themeIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT os.id, os.set_id, s.rebrickable_set_num, s.name, s.local_image_path AS thumbnail,
                os.location_id, os.condition_type, os.created_at
         FROM owned_sets os
         INNER JOIN sets s ON s.id = os.set_id
         WHERE s.theme IN ($placeholders)
         ORDER BY os.created_at DESC"
    );
    $stmt->execute($themeIds);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['set_id'] = (int) $row['set_id'];
        $row['location_id'] = (int) $row['location_id'];
    }
    unset($row);
    return $rows;
}

/**
 * Card for one owned instance (links to its own detail page, not the
 * catalog set) — visually matches renderSetCard() (same CSS classes) with
 * an optional completeness-percent meta line underneath the name.
 */
function renderOwnedSetCard(array $ownedSet, ?float $completenessPercent = null): string
{
    $html = '<a class="set-card" href="?page=owned_set_detail&id=' . (int) $ownedSet['id'] . '">';
    $html .= '<span class="set-card-image">' . ($ownedSet['thumbnail'] !== null ? '<img src="' . htmlspecialchars($ownedSet['thumbnail']) . '" alt="">' : getNavIcon('sets')) . '</span>';
    $html .= '<span class="set-card-num">' . htmlspecialchars($ownedSet['rebrickable_set_num']) . '</span>';
    $html .= '<span class="set-card-name" title="' . htmlspecialchars($ownedSet['name']) . '">' . htmlspecialchars($ownedSet['name']) . '</span>';
    if ($completenessPercent !== null) {
        $html .= '<span class="set-card-meta">' . htmlspecialchars(number_format($completenessPercent, 1)) . '%</span>';
    }
    $html .= '</a>';
    return $html;
}

/**
 * Border-color status for one owned-set inventory tile, shared by
 * renderOwnedSetInventoryGrid() and renderOwnedSetMinifigInventoryGrid()
 * (both use the same nominal/actual/damaged shape). Priority when more than
 * one condition applies: missing beats damaged beats complete, since
 * missing is the more severe of the two defects.
 */
function ownedSetInventoryTileStatusClass(int $nominal, int $actual, int $damaged): string
{
    if ($nominal - $actual > 0) {
        return 'owned-set-inventory-tile-missing';
    }
    if ($damaged > 0) {
        return 'owned-set-inventory-tile-damaged';
    }
    return 'owned-set-inventory-tile-complete';
}

/**
 * Derives a minifig type's "how many present / how many fully intact"
 * counts from its constituent parts (getOwnedSetMinifigPartsWithStatus()) —
 * these aren't tracked per physical copy, only in aggregate across all
 * $minifigNominalCount copies of this minifig type, so a straight sum can't
 * tell "1 copy missing its head" apart from "half a head missing from each
 * of 2 copies". Read it like a crafting recipe instead: per part, actual/
 * nominal is the fraction of copies that part alone could outfit. A copy
 * counts as "present" once *any* part has stock for it (max() across parts
 * — a single missing accessory doesn't erase an otherwise-present
 * minifig); it counts as "fully intact" only once *every* part has
 * undamaged stock for it (min() across parts — the scarcest/most-damaged
 * part is what limits how many complete copies exist). Since intact ≤
 * actual for every part, complete ≤ present always holds, so "damaged"
 * (present - complete) never exceeds "present" — same "damaged is a subset
 * of owned" invariant used everywhere else in this file. This is also
 * where a fully missing part (not just a damaged one) shows up: it lowers
 * "complete" exactly like damage would, which is what folds "unvollständig"
 * into the "beschädigt" count for the modal's read-only summary fields.
 *
 * @param array<int, array{nominal_quantity:int, actual_quantity:int, damaged_quantity:int}> $parts
 * @return array{present:int, complete:int, damaged:int}
 */
function ownedSetMinifigBottleneckStatus(array $parts, int $minifigNominalCount): array
{
    if ($minifigNominalCount <= 0) {
        return ['present' => 0, 'complete' => 0, 'damaged' => 0];
    }
    if (empty($parts)) {
        return ['present' => $minifigNominalCount, 'complete' => $minifigNominalCount, 'damaged' => 0];
    }

    $maxPresentRatio = 0.0;
    $minIntactRatio = 1.0;
    foreach ($parts as $part) {
        $nominal = $part['nominal_quantity'];
        if ($nominal <= 0) {
            continue;
        }
        $actualRatio = min(1.0, $part['actual_quantity'] / $nominal);
        $intactRatio = min(1.0, max(0, $part['actual_quantity'] - $part['damaged_quantity']) / $nominal);
        $maxPresentRatio = max($maxPresentRatio, $actualRatio);
        $minIntactRatio = min($minIntactRatio, $intactRatio);
    }

    $present = (int) min($minifigNominalCount, floor($maxPresentRatio * $minifigNominalCount + 1e-9));
    $complete = (int) min($present, floor($minIntactRatio * $minifigNominalCount + 1e-9));
    $damaged = max(0, $present - $complete);

    return ['present' => $present, 'complete' => $complete, 'damaged' => $damaged];
}

/**
 * Traffic-light class for the completeness ring's stroke color — red below
 * 75%, yellow/orange from 75% up to (not including) 100%, green only at a
 * full 100%. Mirrored in JS by ringColorClass() in both
 * renderOwnedSetQuantityModalScript() and
 * renderOwnedSetMinifigQuantityModalScript() (percent updates after a save
 * without a full page reload, so the ring's color has to be re-derived
 * there too instead of just here).
 */
function ownedSetCompletenessRingClass(float $percent): string
{
    if ($percent >= 100.0) {
        return 'owned-set-total-ring-fg-complete';
    }
    if ($percent >= 75.0) {
        return 'owned-set-total-ring-fg-partial';
    }
    return 'owned-set-total-ring-fg-low';
}

/**
 * owned_set_detail sidebar's "Gesamt" row — a compact version of
 * renderLdrawRenderOverlay()'s SVG progress ring (src/ldraw.php): same
 * stroke-dasharray/stroke-dashoffset technique, but sized for a table row
 * rather than a full-screen overlay, styled for a light background instead
 * of the LDraw overlay's dark one, and rendered once with the correct
 * offset already baked in (no tick loop — there's nothing asynchronous
 * here, just a snapshot of the current actual/nominal split). The ring's id
 * (owned-set-total-ring-fg/-label) lets a save handler update it in place
 * afterwards without touching the rest of the row.
 */
function renderOwnedSetTotalRing(float $percent, int $actual, int $nominal): string
{
    $circumference = 2 * M_PI * 45;
    $offset = $circumference * (1 - min(100.0, $percent) / 100);
    $label = number_format($actual) . ' / ' . number_format($nominal);
    $ringClass = ownedSetCompletenessRingClass($percent);

    $html = '<div class="owned-set-total-ring-wrap">';
    $html .= '<svg class="owned-set-total-ring" viewBox="0 0 100 100" aria-hidden="true">';
    $html .= '<circle class="owned-set-total-ring-bg" cx="50" cy="50" r="45"></circle>';
    $html .= '<circle class="owned-set-total-ring-fg ' . $ringClass . '" id="owned-set-total-ring-fg" cx="50" cy="50" r="45" style="stroke-dasharray: ' . sprintf('%.2f', $circumference) . '; stroke-dashoffset: ' . sprintf('%.2f', $offset) . ';"></circle>';
    $html .= '</svg>';
    $html .= '<span class="owned-set-total-ring-label" id="owned-set-total-ring-label">' . htmlspecialchars($label) . '</span>';
    $html .= '</div>';

    return $html;
}

/**
 * One tile in an owned-set inventory grid — visually matches renderPartCard()
 * (image/number/name) plus a status border and a permanently-visible
 * owned/damaged/missing summary line. Deliberately NOT given the .part-card
 * class: that class is part_modal.php's document-wide click-delegation hook
 * for the read-only part-detail overlay, and reusing it here caused the
 * "Bauteil nicht gefunden" bug earlier in this project (clicking into the
 * tile bubbled up, matched .part-card, opened the wrong modal with no
 * part id). The current values are carried as data-* attributes so the
 * quantity-edit modal (see renderOwnedSetInventoryGrid()'s script) can read
 * them straight from the clicked element without an extra fetch.
 */
function renderOwnedSetInventoryTile(string $key, string $numberLabel, string $name, ?string $thumbnail, int $nominal, int $actual, int $damaged): string
{
    $statusClass = ownedSetInventoryTileStatusClass($nominal, $actual, $damaged);
    $missing = max(0, $nominal - $actual);
    $intact = $actual - $damaged;
    $summary = t('owned_set_inventory_summary', [
        'intact' => (string) $intact,
        'damaged' => (string) $damaged,
        'missing' => (string) $missing,
    ]);

    $html = '<div class="owned-set-inventory-tile ' . $statusClass . '" role="button" tabindex="0"';
    $html .= ' data-key="' . htmlspecialchars($key) . '"';
    $html .= ' data-number="' . htmlspecialchars($numberLabel) . '"';
    $html .= ' data-name="' . htmlspecialchars($name) . '"';
    $html .= ' data-thumbnail="' . htmlspecialchars((string) $thumbnail) . '"';
    $html .= ' data-nominal="' . $nominal . '" data-actual="' . $actual . '" data-damaged="' . $damaged . '">';
    $html .= '<span class="part-card-image">' . ($thumbnail !== null ? '<img src="' . htmlspecialchars($thumbnail) . '" alt="">' : getNavIcon('bricks')) . '</span>';
    $html .= '<span class="part-card-num">' . htmlspecialchars($numberLabel) . '</span>';
    $html .= '<span class="part-card-name">' . htmlspecialchars($name) . '</span>';
    $html .= '<p class="owned-set-inventory-summary">' . htmlspecialchars($summary) . '</p>';
    $html .= '</div>';

    return $html;
}

/**
 * The quantity-edit modal shared (as markup+script, embedded once per grid —
 * only one of the four category tabs is ever active/rendered per page load,
 * so no id collisions) by renderOwnedSetInventoryGrid() and
 * renderOwnedSetMinifigInventoryGrid(). Opens on a tile click, lets the user
 * correct that one item's owned/damaged counts via the same buildStepper()
 * control the old inline editor used, and saves via a single-item POST to
 * the existing save_owned_set_inventory action (it already just iterates
 * whatever keys are posted, so one key works unchanged) — then updates the
 * tile in place instead of reloading the page.
 */
function renderOwnedSetQuantityModalScript(array $ownedSet, string $ownedField, string $damagedField, string $gridId): string
{
    $labelsJson = json_encode([
        'errorRetry' => t('import_error_retry'),
        'ownedLabel' => t('owned_set_inventory_owned_label'),
        'damagedLabel' => t('owned_set_inventory_damaged_label'),
        'inventorySummary' => t('owned_set_inventory_summary'),
        'saveButton' => t('owned_set_save_button'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $ownedFieldJson = json_encode($ownedField);
    $damagedFieldJson = json_encode($damagedField);
    $gridIdJson = json_encode($gridId);

    return <<<SCRIPT
<script>
(function(){
  var texts = $labelsJson;
  var ownedSetId = {$ownedSet['id']};
  var ownedField = $ownedFieldJson;
  var damagedField = $damagedFieldJson;

  var grid = document.getElementById($gridIdJson);
  var modal = document.getElementById('owned-set-qty-modal');
  var modalContent = document.getElementById('owned-set-qty-modal-content');
  var closeBtn = document.getElementById('owned-set-qty-modal-close');
  if (!grid || !modal || !modalContent || !closeBtn) {
    return;
  }

  function closeModal() {
    modal.style.display = 'none';
    modalContent.innerHTML = '';
  }
  closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function(e) {
    if (e.target === modal) {
      closeModal();
    }
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && modal.style.display !== 'none') {
      closeModal();
    }
  });

  function buildStepper(minVal, maxVal, value) {
    var wrap = document.createElement('div');
    wrap.className = 'owned-set-inventory-stepper';
    var minusBtn = document.createElement('button');
    minusBtn.type = 'button';
    minusBtn.className = 'owned-set-inventory-stepper-btn';
    minusBtn.textContent = '\\u2212';
    var input = document.createElement('input');
    input.type = 'number';
    input.min = String(minVal);
    input.max = String(maxVal);
    input.value = String(value);
    var plusBtn = document.createElement('button');
    plusBtn.type = 'button';
    plusBtn.className = 'owned-set-inventory-stepper-btn';
    plusBtn.textContent = '+';

    function step(delta) {
      var v = (parseInt(input.value, 10) || 0) + delta;
      v = Math.max(parseInt(input.min, 10), Math.min(v, parseInt(input.max, 10)));
      input.value = String(v);
      input.dispatchEvent(new Event('input'));
    }
    minusBtn.addEventListener('click', function() { step(-1); });
    plusBtn.addEventListener('click', function() { step(1); });

    wrap.appendChild(minusBtn);
    wrap.appendChild(input);
    wrap.appendChild(plusBtn);
    return { wrap: wrap, input: input };
  }

  function updateTile(tile, actual, damaged) {
    var nominal = parseInt(tile.dataset.nominal, 10);
    var missing = Math.max(0, nominal - actual);
    var intact = actual - damaged;
    tile.dataset.actual = String(actual);
    tile.dataset.damaged = String(damaged);
    tile.classList.remove('owned-set-inventory-tile-complete', 'owned-set-inventory-tile-damaged', 'owned-set-inventory-tile-missing');
    if (missing > 0) {
      tile.classList.add('owned-set-inventory-tile-missing');
    } else if (damaged > 0) {
      tile.classList.add('owned-set-inventory-tile-damaged');
    } else {
      tile.classList.add('owned-set-inventory-tile-complete');
    }
    var summary = tile.querySelector('.owned-set-inventory-summary');
    if (summary) {
      summary.textContent = texts.inventorySummary
        .replace('{intact}', intact)
        .replace('{damaged}', damaged)
        .replace('{missing}', missing);
    }
  }

  function formatNumber(n) {
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  // Mirrors ownedSetCompletenessRingClass() in src/owned_sets.php (PHP) —
  // the initial page render uses that one, this re-derives the same class
  // after a live save patches the ring in place.
  function ringColorClass(percent) {
    if (percent >= 100) {
      return 'owned-set-total-ring-fg-complete';
    }
    if (percent >= 75) {
      return 'owned-set-total-ring-fg-partial';
    }
    return 'owned-set-total-ring-fg-low';
  }

  // Patches the top status bar in place (see renderApp()'s
  // id="status-stat-{key}" spans) — shared by every AJAX action on this
  // page that returns fresh stats, duplicated per script per this
  // project's self-contained-overlay convention.
  function applyStats(stats) {
    if (!stats) {
      return;
    }
    Object.keys(stats).forEach(function(key) {
      var el = document.getElementById('status-stat-' + key);
      var strong = el ? el.querySelector('strong') : null;
      if (strong) {
        strong.textContent = formatNumber(stats[key]);
      }
    });
  }

  // Patches the sidebar "Inventar" table (owned_set_detail's own summary
  // rows/ring) after a save — the tile itself is already updated by
  // updateTile(), this covers the aggregate numbers next to it.
  function applySummary(summary) {
    if (!summary) {
      return;
    }
    ['exclusive', 'rare', 'stickers', 'minifigs'].forEach(function(key) {
      var counts = summary[key];
      var cell = document.getElementById('owned-set-summary-' + key);
      if (counts && cell) {
        cell.textContent = formatNumber(counts.actual) + ' / ' + formatNumber(counts.nominal);
      }
    });
    var total = summary.total;
    var ringFg = document.getElementById('owned-set-total-ring-fg');
    var ringLabel = document.getElementById('owned-set-total-ring-label');
    if (total && ringFg && ringLabel) {
      var circumference = 2 * Math.PI * 45;
      var percent = Math.min(100, total.percent);
      ringFg.style.strokeDashoffset = (circumference * (1 - percent / 100)).toFixed(2);
      ringFg.classList.remove('owned-set-total-ring-fg-complete', 'owned-set-total-ring-fg-partial', 'owned-set-total-ring-fg-low');
      ringFg.classList.add(ringColorClass(percent));
      ringLabel.textContent = formatNumber(total.actual) + ' / ' + formatNumber(total.nominal);
    }
  }

  function openModal(tile) {
    modalContent.innerHTML = '';
    modal.style.display = 'flex';

    var nominal = parseInt(tile.dataset.nominal, 10);
    var actual = parseInt(tile.dataset.actual, 10);
    var damaged = parseInt(tile.dataset.damaged, 10);

    var header = document.createElement('div');
    header.className = 'owned-set-qty-modal-header';
    var img = document.createElement('span');
    img.className = 'owned-set-qty-modal-image';
    if (tile.dataset.thumbnail) {
      img.innerHTML = '<img src="' + tile.dataset.thumbnail + '" alt="">';
    }
    header.appendChild(img);
    var info = document.createElement('div');
    var title = document.createElement('h3');
    title.textContent = tile.dataset.number;
    var name = document.createElement('p');
    name.textContent = tile.dataset.name;
    info.appendChild(title);
    info.appendChild(name);
    header.appendChild(info);
    modalContent.appendChild(header);

    var ownedLabel = document.createElement('label');
    ownedLabel.appendChild(document.createTextNode(texts.ownedLabel));
    var ownedStepper = buildStepper(0, nominal, actual);
    ownedLabel.appendChild(ownedStepper.wrap);
    modalContent.appendChild(ownedLabel);

    var damagedLabel = document.createElement('label');
    damagedLabel.appendChild(document.createTextNode(texts.damagedLabel));
    var damagedStepper = buildStepper(0, actual, damaged);
    damagedLabel.appendChild(damagedStepper.wrap);
    modalContent.appendChild(damagedLabel);

    ownedStepper.input.addEventListener('input', function() {
      var v = parseInt(ownedStepper.input.value, 10) || 0;
      damagedStepper.input.max = String(v);
      if ((parseInt(damagedStepper.input.value, 10) || 0) > v) {
        damagedStepper.input.value = String(v);
      }
    });

    var msg = document.createElement('p');
    msg.className = 'owned-set-message';
    modalContent.appendChild(msg);

    var saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.textContent = texts.saveButton;
    saveBtn.addEventListener('click', function() {
      msg.textContent = '';
      var newOwned = Math.max(0, Math.min(parseInt(ownedStepper.input.value, 10) || 0, nominal));
      var newDamaged = Math.max(0, Math.min(parseInt(damagedStepper.input.value, 10) || 0, newOwned));

      var formData = new FormData();
      formData.set('action', 'save_owned_set_inventory');
      formData.set('owned_set_id', String(ownedSetId));
      formData.set(ownedField + '[' + tile.dataset.key + ']', String(newOwned));
      formData.set(damagedField + '[' + tile.dataset.key + ']', String(newDamaged));

      saveBtn.disabled = true;
      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          saveBtn.disabled = false;
          if (res.success) {
            updateTile(tile, newOwned, newDamaged);
            applyStats(res.stats);
            applySummary(res.summary);
            closeModal();
          } else {
            msg.textContent = res.message || texts.errorRetry;
          }
        })
        .catch(function() {
          saveBtn.disabled = false;
          msg.textContent = texts.errorRetry;
        });
    });
    modalContent.appendChild(saveBtn);
  }

  grid.addEventListener('click', function(e) {
    var tile = e.target.closest('.owned-set-inventory-tile');
    if (tile) {
      openModal(tile);
    }
  });
  grid.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter' && e.key !== ' ') {
      return;
    }
    var tile = e.target.closest('.owned-set-inventory-tile');
    if (tile) {
      e.preventDefault();
      openModal(tile);
    }
  });
})();
</script>
SCRIPT;
}

function renderOwnedSetQuantityModalMarkup(): string
{
    $html = '<div class="modal-overlay" id="owned-set-qty-modal" style="display:none;">';
    $html .= '<div class="modal-box"><button type="button" class="modal-close" id="owned-set-qty-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<div id="owned-set-qty-modal-content"></div>';
    $html .= '</div></div>';
    return $html;
}

/**
 * The Minifiguren tab's own quantity-modal script — same modal DOM
 * (renderOwnedSetQuantityModalMarkup()) as the generic one, but a minifig's
 * own "Vorhanden"/"Beschädigt" aren't independently editable here: they're
 * read-only, live-derived from the constituent-parts checklist below via
 * the same bottleneck recipe as ownedSetMinifigBottleneckStatus() in PHP
 * (mirrored in JS as bottleneckStatus() so it can recompute on every
 * keystroke without a round-trip). Saving fires two existing endpoints in
 * sequence — save_owned_set_inventory for the minifig-level pair (using
 * the freshly-computed present/damaged, not raw input) then
 * save_owned_set_minifig_parts for the constituent parts — then updates the
 * tile and the sidebar/status-bar (applyStats()/applySummary(), same shape
 * as the generic modal's).
 */
function renderOwnedSetMinifigQuantityModalScript(array $ownedSet): string
{
    $labelsJson = json_encode([
        'errorRetry' => t('import_error_retry'),
        'ownedLabel' => t('owned_set_inventory_owned_label'),
        'damagedLabel' => t('owned_set_inventory_damaged_label'),
        'inventorySummary' => t('owned_set_inventory_summary'),
        'saveButton' => t('owned_set_save_button'),
        'partsHeading' => t('owned_set_minifig_parts_heading'),
        'nominalLabel' => t('owned_set_minifig_nominal_label'),
        'loading' => t('owned_set_tab_loading'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $gridIdJson = json_encode('owned-set-minifig-grid');

    return <<<SCRIPT
<script>
(function(){
  var texts = $labelsJson;
  var ownedSetId = {$ownedSet['id']};

  var grid = document.getElementById($gridIdJson);
  var modal = document.getElementById('owned-set-qty-modal');
  var modalContent = document.getElementById('owned-set-qty-modal-content');
  var closeBtn = document.getElementById('owned-set-qty-modal-close');
  if (!grid || !modal || !modalContent || !closeBtn) {
    return;
  }

  function closeModal() {
    modal.style.display = 'none';
    modalContent.innerHTML = '';
  }
  closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function(e) {
    if (e.target === modal) {
      closeModal();
    }
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && modal.style.display !== 'none') {
      closeModal();
    }
  });

  function buildStepper(minVal, maxVal, value) {
    var wrap = document.createElement('div');
    wrap.className = 'owned-set-inventory-stepper';
    var minusBtn = document.createElement('button');
    minusBtn.type = 'button';
    minusBtn.className = 'owned-set-inventory-stepper-btn';
    minusBtn.textContent = '\\u2212';
    var input = document.createElement('input');
    input.type = 'number';
    input.min = String(minVal);
    input.max = String(maxVal);
    input.value = String(value);
    var plusBtn = document.createElement('button');
    plusBtn.type = 'button';
    plusBtn.className = 'owned-set-inventory-stepper-btn';
    plusBtn.textContent = '+';

    function step(delta) {
      var v = (parseInt(input.value, 10) || 0) + delta;
      v = Math.max(parseInt(input.min, 10), Math.min(v, parseInt(input.max, 10)));
      input.value = String(v);
      input.dispatchEvent(new Event('input'));
    }
    minusBtn.addEventListener('click', function() { step(-1); });
    plusBtn.addEventListener('click', function() { step(1); });

    wrap.appendChild(minusBtn);
    wrap.appendChild(input);
    wrap.appendChild(plusBtn);
    return { wrap: wrap, input: input };
  }

  function formatNumber(n) {
    return String(n).replace(/\\B(?=(\\d{3})+(?!\\d))/g, ',');
  }

  // Mirrors ownedSetCompletenessRingClass() in src/owned_sets.php (PHP) —
  // the initial page render uses that one, this re-derives the same class
  // after a live save patches the ring in place.
  function ringColorClass(percent) {
    if (percent >= 100) {
      return 'owned-set-total-ring-fg-complete';
    }
    if (percent >= 75) {
      return 'owned-set-total-ring-fg-partial';
    }
    return 'owned-set-total-ring-fg-low';
  }

  function applyStats(stats) {
    if (!stats) {
      return;
    }
    Object.keys(stats).forEach(function(key) {
      var el = document.getElementById('status-stat-' + key);
      var strong = el ? el.querySelector('strong') : null;
      if (strong) {
        strong.textContent = formatNumber(stats[key]);
      }
    });
  }

  function applySummary(summary) {
    if (!summary) {
      return;
    }
    ['exclusive', 'rare', 'stickers', 'minifigs'].forEach(function(key) {
      var counts = summary[key];
      var cell = document.getElementById('owned-set-summary-' + key);
      if (counts && cell) {
        cell.textContent = formatNumber(counts.actual) + ' / ' + formatNumber(counts.nominal);
      }
    });
    var total = summary.total;
    var ringFg = document.getElementById('owned-set-total-ring-fg');
    var ringLabel = document.getElementById('owned-set-total-ring-label');
    if (total && ringFg && ringLabel) {
      var circumference = 2 * Math.PI * 45;
      var percent = Math.min(100, total.percent);
      ringFg.style.strokeDashoffset = (circumference * (1 - percent / 100)).toFixed(2);
      ringFg.classList.remove('owned-set-total-ring-fg-complete', 'owned-set-total-ring-fg-partial', 'owned-set-total-ring-fg-low');
      ringFg.classList.add(ringColorClass(percent));
      ringLabel.textContent = formatNumber(total.actual) + ' / ' + formatNumber(total.nominal);
    }
  }

  // Mirrors ownedSetMinifigBottleneckStatus() in src/owned_sets.php — see
  // its doc comment for why "present" uses max() across parts (one missing
  // accessory doesn't erase an otherwise-present minifig) while "complete"
  // uses min() (the scarcest/most-damaged part bottlenecks how many
  // complete copies exist), and why complete <= present always holds.
  function bottleneckStatus(parts, n) {
    if (n <= 0) {
      return { present: 0, complete: 0, damaged: 0 };
    }
    if (parts.length === 0) {
      return { present: n, complete: n, damaged: 0 };
    }
    var maxPresentRatio = 0;
    var minIntactRatio = 1;
    parts.forEach(function(p) {
      if (p.nominal <= 0) {
        return;
      }
      var actualRatio = Math.min(1, p.actual / p.nominal);
      var intactRatio = Math.min(1, Math.max(0, p.actual - p.damaged) / p.nominal);
      maxPresentRatio = Math.max(maxPresentRatio, actualRatio);
      minIntactRatio = Math.min(minIntactRatio, intactRatio);
    });
    var present = Math.min(n, Math.floor(maxPresentRatio * n + 1e-9));
    var complete = Math.min(present, Math.floor(minIntactRatio * n + 1e-9));
    return { present: present, complete: complete, damaged: Math.max(0, present - complete) };
  }

  function updateTile(tile, actual, damaged) {
    var nominal = parseInt(tile.dataset.nominal, 10);
    var missing = Math.max(0, nominal - actual);
    var intact = actual - damaged;
    tile.dataset.actual = String(actual);
    tile.dataset.damaged = String(damaged);

    tile.classList.remove('owned-set-inventory-tile-complete', 'owned-set-inventory-tile-damaged', 'owned-set-inventory-tile-missing');
    if (missing > 0) {
      tile.classList.add('owned-set-inventory-tile-missing');
    } else if (damaged > 0) {
      tile.classList.add('owned-set-inventory-tile-damaged');
    } else {
      tile.classList.add('owned-set-inventory-tile-complete');
    }
    var summary = tile.querySelector('.owned-set-inventory-summary');
    if (summary) {
      summary.textContent = texts.inventorySummary
        .replace('{intact}', intact)
        .replace('{damaged}', damaged)
        .replace('{missing}', missing);
    }
  }

  function openModal(tile) {
    modalContent.innerHTML = '';
    modal.style.display = 'flex';

    var minifigId = tile.dataset.key;
    var nominal = parseInt(tile.dataset.nominal, 10);

    var header = document.createElement('div');
    header.className = 'owned-set-qty-modal-header';
    var img = document.createElement('span');
    img.className = 'owned-set-qty-modal-image';
    if (tile.dataset.thumbnail) {
      img.innerHTML = '<img src="' + tile.dataset.thumbnail + '" alt="">';
    }
    header.appendChild(img);
    var info = document.createElement('div');
    var title = document.createElement('h3');
    title.textContent = tile.dataset.number;
    var name = document.createElement('p');
    name.textContent = tile.dataset.name;
    var nominalLine = document.createElement('p');
    nominalLine.className = 'owned-set-minifig-nominal-line';
    nominalLine.textContent = texts.nominalLabel.replace('{count}', nominal);
    info.appendChild(title);
    info.appendChild(name);
    info.appendChild(nominalLine);
    header.appendChild(info);
    modalContent.appendChild(header);

    var ownedLabel = document.createElement('label');
    ownedLabel.appendChild(document.createTextNode(texts.ownedLabel));
    var ownedValue = document.createElement('span');
    ownedValue.className = 'owned-set-minifig-readonly-value';
    ownedLabel.appendChild(ownedValue);
    modalContent.appendChild(ownedLabel);

    var damagedLabel = document.createElement('label');
    damagedLabel.appendChild(document.createTextNode(texts.damagedLabel));
    var damagedValue = document.createElement('span');
    damagedValue.className = 'owned-set-minifig-readonly-value';
    damagedLabel.appendChild(damagedValue);
    modalContent.appendChild(damagedLabel);

    var partsHeading = document.createElement('h4');
    partsHeading.className = 'owned-set-minifig-parts-heading';
    partsHeading.textContent = texts.partsHeading;
    modalContent.appendChild(partsHeading);

    var columnHeader = document.createElement('div');
    columnHeader.className = 'owned-set-minifig-part-row owned-set-minifig-part-header';
    var columnHeaderSpacer = document.createElement('span');
    columnHeaderSpacer.className = 'part-card-image';
    var columnHeaderName = document.createElement('span');
    columnHeaderName.className = 'owned-set-minifig-part-name';
    var columnHeaderOwned = document.createElement('span');
    columnHeaderOwned.className = 'owned-set-minifig-part-col-label';
    columnHeaderOwned.textContent = texts.ownedLabel;
    var columnHeaderDamaged = document.createElement('span');
    columnHeaderDamaged.className = 'owned-set-minifig-part-col-label';
    columnHeaderDamaged.textContent = texts.damagedLabel;
    columnHeader.appendChild(columnHeaderSpacer);
    columnHeader.appendChild(columnHeaderName);
    columnHeader.appendChild(columnHeaderOwned);
    columnHeader.appendChild(columnHeaderDamaged);
    modalContent.appendChild(columnHeader);

    var partsList = document.createElement('div');
    partsList.className = 'owned-set-minifig-parts-list';
    partsList.textContent = texts.loading;
    modalContent.appendChild(partsList);

    var partState = {};

    function recompute() {
      var parts = Object.keys(partState).map(function(key) {
        var st = partState[key];
        return {
          nominal: st.nominal,
          actual: parseInt(st.ownedInput.value, 10) || 0,
          damaged: parseInt(st.damagedInput.value, 10) || 0
        };
      });
      var status = bottleneckStatus(parts, nominal);
      ownedValue.textContent = String(status.present);
      damagedValue.textContent = String(status.damaged);
      return status;
    }

    fetch('?action=owned_set_minifig_parts&owned_set_id=' + ownedSetId + '&minifig_id=' + minifigId, { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        partsList.innerHTML = '';
        if (!res.success) {
          partsList.textContent = res.message || texts.errorRetry;
          return;
        }
        res.parts.forEach(function(part) {
          var key = part.part_id + ':' + part.color_id;
          partState[key] = { nominal: part.nominal_quantity };

          var row = document.createElement('div');
          row.className = 'owned-set-minifig-part-row';

          var partImg = document.createElement('span');
          partImg.className = 'part-card-image';
          if (part.thumbnail) {
            partImg.innerHTML = '<img src="' + part.thumbnail + '" alt="">';
          }
          row.appendChild(partImg);

          var partName = document.createElement('span');
          partName.className = 'owned-set-minifig-part-name';
          partName.textContent = part.name + (part.color_name ? ' \\u00b7 ' + part.color_name : '');
          row.appendChild(partName);

          var partOwnedStepper = buildStepper(0, part.nominal_quantity, part.actual_quantity);
          var partDamagedStepper = buildStepper(0, part.actual_quantity, part.damaged_quantity);
          partOwnedStepper.input.addEventListener('input', function() {
            var v = parseInt(partOwnedStepper.input.value, 10) || 0;
            partDamagedStepper.input.max = String(v);
            if ((parseInt(partDamagedStepper.input.value, 10) || 0) > v) {
              partDamagedStepper.input.value = String(v);
            }
            recompute();
          });
          partDamagedStepper.input.addEventListener('input', recompute);
          row.appendChild(partOwnedStepper.wrap);
          row.appendChild(partDamagedStepper.wrap);
          partsList.appendChild(row);

          partState[key].ownedInput = partOwnedStepper.input;
          partState[key].damagedInput = partDamagedStepper.input;
        });
        recompute();
      })
      .catch(function() {
        partsList.textContent = texts.errorRetry;
      });

    var msg = document.createElement('p');
    msg.className = 'owned-set-message';
    modalContent.appendChild(msg);

    var saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.textContent = texts.saveButton;
    saveBtn.addEventListener('click', function() {
      msg.textContent = '';
      var status = recompute();

      var minifigFormData = new FormData();
      minifigFormData.set('action', 'save_owned_set_inventory');
      minifigFormData.set('owned_set_id', String(ownedSetId));
      minifigFormData.set('minifig_owned[' + minifigId + ']', String(status.present));
      minifigFormData.set('minifig_damaged[' + minifigId + ']', String(status.damaged));

      var partsFormData = new FormData();
      partsFormData.set('action', 'save_owned_set_minifig_parts');
      partsFormData.set('owned_set_id', String(ownedSetId));
      partsFormData.set('minifig_id', minifigId);
      partsFormData.set('minifig_nominal_count', String(nominal));
      Object.keys(partState).forEach(function(key) {
        var st = partState[key];
        var partOwned = Math.max(0, Math.min(parseInt(st.ownedInput.value, 10) || 0, st.nominal));
        var partDamaged = Math.max(0, Math.min(parseInt(st.damagedInput.value, 10) || 0, partOwned));
        partsFormData.set('part_owned[' + key + ']', String(partOwned));
        partsFormData.set('part_damaged[' + key + ']', String(partDamaged));
      });

      saveBtn.disabled = true;
      fetch('?', { method: 'POST', body: minifigFormData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res1) {
          if (!res1.success) {
            throw new Error(res1.message || texts.errorRetry);
          }
          return fetch('?', { method: 'POST', body: partsFormData, credentials: 'same-origin' });
        })
        .then(function(r) { return r.json(); })
        .then(function(res2) {
          saveBtn.disabled = false;
          if (res2.success) {
            updateTile(tile, status.present, status.damaged);
            applyStats(res2.stats);
            applySummary(res2.summary);
            closeModal();
          } else {
            msg.textContent = res2.message || texts.errorRetry;
          }
        })
        .catch(function(err) {
          saveBtn.disabled = false;
          msg.textContent = (err && err.message) || texts.errorRetry;
        });
    });
    modalContent.appendChild(saveBtn);
  }

  grid.addEventListener('click', function(e) {
    var tile = e.target.closest('.owned-set-inventory-tile');
    if (tile) {
      openModal(tile);
    }
  });
  grid.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter' && e.key !== ' ') {
      return;
    }
    var tile = e.target.closest('.owned-set-inventory-tile');
    if (tile) {
      e.preventDefault();
      openModal(tile);
    }
  });
})();
</script>
SCRIPT;
}

/**
 * owned_set_detail's inventory grid — one per tab (Inventar/Ersatzteile/
 * Stickerbögen). Unlike the previous grouped/paginated wizard-style editor,
 * this is a static grid showing every part+color at once (same visual
 * language as the catalog set-detail page's own parts-grid), with status
 * visible at a glance via each tile's border color; correcting a quantity
 * happens through renderOwnedSetQuantityModalScript()'s modal rather than
 * inline. $ownedField/$damagedField select which pair of POST field names
 * (and therefore which storage_items columns, via applyOwnedSetInventory()/
 * applyOwnedSetSpareInventory()/applyOwnedSetStickerInventory() in
 * index.php's save_owned_set_inventory handler) this instance targets.
 *
 * $groupByRarity splits the grid into Exklusive/Seltene/Normale sub-groups
 * with a header+rule between them, same as the catalog set-detail page's own
 * inventory tab (see index.php's $renderSetPartsGrid) — only meaningful for
 * the main Inventar tab (spares/stickers callers leave it false, matching
 * the catalog's own choice there). A "Stickerbögen" bucket isn't needed
 * here unlike the catalog: this grid's $parts never contains sticker items
 * to begin with (those already live in owned_set_detail's own Stickerbögen
 * tab, see getOwnedSetPartsWithStatus()'s doc comment).
 */
function renderOwnedSetInventoryGrid(PDO $pdo, array $ownedSet, array $parts, string $ownedField = 'owned', string $damagedField = 'damaged', bool $groupByRarity = false): string
{
    if (empty($parts)) {
        return '<section class="card"><p>' . htmlspecialchars(t('set_detail_inventory_empty')) . '</p></section>';
    }

    $renderTile = function (array $part): string {
        $name = $part['name'] . ($part['color_name'] !== null ? ' · ' . $part['color_name'] : '');
        return renderOwnedSetInventoryTile(
            $part['part_id'] . ':' . $part['color_id'],
            $part['part_num'],
            $name,
            $part['thumbnail'],
            (int) $part['nominal_quantity'],
            (int) $part['actual_quantity'],
            (int) $part['damaged_quantity']
        );
    };

    $tilesHtml = '';
    if ($groupByRarity) {
        $pairs = [];
        foreach ($parts as $part) {
            if ($part['rebrickable_color_id'] !== null) {
                $pairs[] = ['part_id' => $part['part_id'], 'color_id' => $part['rebrickable_color_id']];
            }
        }
        $setCounts = getPartSetCounts($pdo, $pairs);

        $buckets = ['exclusive' => [], 'rare' => [], 'normal' => []];
        foreach ($parts as $part) {
            $count = $part['rebrickable_color_id'] !== null
                ? ($setCounts[$part['part_id'] . ':' . $part['rebrickable_color_id']] ?? 0)
                : 0;
            if ($count === 1) {
                $buckets['exclusive'][] = $part;
            } elseif ($count >= 2 && $count <= 3) {
                $buckets['rare'][] = $part;
            } else {
                $buckets['normal'][] = $part;
            }
        }

        $groupLabels = [
            'exclusive' => t('set_detail_group_exclusive'),
            'rare' => t('set_detail_group_rare'),
            'normal' => t('set_detail_group_normal'),
        ];
        foreach ($groupLabels as $bucketKey => $label) {
            if (empty($buckets[$bucketKey])) {
                continue;
            }
            $tilesHtml .= '<div class="group-header"><span class="group-header-label">' . htmlspecialchars($label) . '</span><hr class="group-header-rule"></div>';
            foreach ($buckets[$bucketKey] as $part) {
                $tilesHtml .= $renderTile($part);
            }
        }
    } else {
        foreach ($parts as $part) {
            $tilesHtml .= $renderTile($part);
        }
    }

    $html = '<div class="parts-grid owned-set-inventory-grid" id="owned-set-inventory-grid">' . $tilesHtml . '</div>';
    $html .= renderOwnedSetQuantityModalMarkup();
    $html .= renderOwnedSetQuantityModalScript($ownedSet, $ownedField, $damagedField, 'owned-set-inventory-grid');

    return $html;
}

/**
 * owned_set_detail's Minifiguren tab — same status-tile/quantity-modal
 * treatment as renderOwnedSetInventoryGrid(), just keyed by minifig_id
 * instead of part_id+color_id (minifigs have no color variants). Unlike a
 * regular part, a minifig's own owned/damaged counts aren't independently
 * editable — they're derived live from its constituent parts (head/torso/
 * legs/etc., see migration 18) via ownedSetMinifigBottleneckStatus(), so
 * both the tile and the modal (renderOwnedSetMinifigQuantityModalScript())
 * always show the same parts-derived numbers.
 */
function renderOwnedSetMinifigInventoryGrid(PDO $pdo, array $ownedSet, array $minifigs): string
{
    if (empty($minifigs)) {
        return '<section class="card"><p>' . htmlspecialchars(t('set_detail_minifigs_empty')) . '</p></section>';
    }

    $html = '<div class="parts-grid owned-set-inventory-grid" id="owned-set-minifig-grid">';
    foreach ($minifigs as $fig) {
        $nominal = (int) $fig['nominal_quantity'];
        $parts = getOwnedSetMinifigPartsWithStatus($pdo, $ownedSet, $fig['minifig_id'], $fig['fig_num'], $nominal);
        $bottleneck = ownedSetMinifigBottleneckStatus($parts, $nominal);
        $actual = $bottleneck['present'];
        $damaged = $bottleneck['damaged'];
        $statusClass = ownedSetInventoryTileStatusClass($nominal, $actual, $damaged);

        $missing = max(0, $nominal - $actual);
        $intact = $actual - $damaged;
        $summary = t('owned_set_inventory_summary', ['intact' => (string) $intact, 'damaged' => (string) $damaged, 'missing' => (string) $missing]);

        $html .= '<div class="owned-set-inventory-tile ' . $statusClass . '" role="button" tabindex="0"';
        $html .= ' data-key="' . (int) $fig['minifig_id'] . '"';
        $html .= ' data-fig-num="' . htmlspecialchars($fig['fig_num']) . '"';
        $html .= ' data-number="' . htmlspecialchars($fig['fig_num']) . '"';
        $html .= ' data-name="' . htmlspecialchars($fig['name']) . '"';
        $html .= ' data-thumbnail="' . htmlspecialchars((string) $fig['thumbnail']) . '"';
        $html .= ' data-nominal="' . $nominal . '" data-actual="' . $actual . '" data-damaged="' . $damaged . '">';
        $html .= '<span class="part-card-image">' . ($fig['thumbnail'] !== null ? '<img src="' . htmlspecialchars($fig['thumbnail']) . '" alt="">' : getNavIcon('minifigs')) . '</span>';
        $html .= '<span class="part-card-num">' . htmlspecialchars($fig['fig_num']) . '</span>';
        $html .= '<span class="part-card-name">' . htmlspecialchars($fig['name']) . '</span>';
        $html .= '<p class="owned-set-inventory-summary">' . htmlspecialchars($summary) . '</p>';
        $html .= '</div>';
    }
    $html .= '</div>';

    $html .= renderOwnedSetQuantityModalMarkup();
    $html .= renderOwnedSetMinifigQuantityModalScript($ownedSet);

    return $html;
}

/**
 * Accepts either a bare BrickLink item id (e.g. "trn045") or a full
 * catalog URL copy-pasted from BrickLink/Rebrickable (e.g.
 * ".../catalogitem.page?M=trn045") and extracts just the id — the manual-
 * entry fallback modal tells the user to paste whatever they found rather
 * than asking them to identify the bare id themselves. Returns null for
 * anything that's neither (empty, or doesn't look like either shape).
 */
function parseBricklinkMinifigIdInput(string $input): ?string
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }
    if (preg_match('/[?&]M=([A-Za-z0-9]+)/', $input, $matches)) {
        return $matches[1];
    }
    if (preg_match('/^[A-Za-z0-9]+$/', $input)) {
        return $input;
    }
    return null;
}

/**
 * minifigs.bricklink_id, populated at most once per minifig — checks the
 * stored column first and only ever calls out to
 * fetchBricklinkMinifigIdFromMoykubik() (src/rebrickable.php) when it's
 * still NULL, caching whatever comes back (including a manually-entered
 * id, via action=save_minifig_bricklink_id) so no minifig is ever looked
 * up more than once across the lifetime of this install.
 */
function getOrFetchBricklinkMinifigId(PDO $pdo, int $minifigId, string $figNum): ?string
{
    $stmt = $pdo->prepare('SELECT bricklink_id FROM minifigs WHERE id = ?');
    $stmt->execute([$minifigId]);
    $existing = $stmt->fetchColumn();
    if (is_string($existing) && $existing !== '') {
        return $existing;
    }

    $found = fetchBricklinkMinifigIdFromMoykubik($figNum);
    if ($found !== null) {
        $pdo->prepare('UPDATE minifigs SET bricklink_id = ? WHERE id = ?')->execute([$found, $minifigId]);
    }
    return $found;
}

/**
 * BrickLink Wanted-List XML for exactly the rows the "Beschädigt/Fehlend"
 * tab currently shows — same category filtering via
 * damaged_missing_show_spares/_stickers (see
 * renderOwnedSetDamagedMissingSection()'s doc comment). Missing + damaged
 * quantity are combined into one wanted count per line, since both need a
 * replacement part either way. A part/sticker line with no
 * bricklink_color_id mapping (see syncExternalColorIds()) is skipped —
 * listed in a trailing XML comment instead of just silently vanishing.
 *
 * A minifig that's entirely missing (not just some of its own parts) gets
 * its own ITEMTYPE M line via getOrFetchBricklinkMinifigId() — a minifig
 * with only some damaged/missing constituent parts (present, but broken)
 * still only contributes those parts as ITEMTYPE P lines, same as before
 * (see renderOwnedSetDamagedMissingSection()'s doc comment for why that
 * split lines up cleanly with the existing bottleneck/present/damaged
 * model). If a whole-minifig lookup fails, that minifig is reported back
 * via 'needsManualId' instead of silently being left out — the caller
 * (action=owned_set_bricklink_xml_check) uses that to prompt for a manually
 * entered id rather than serving an incomplete file.
 *
 * @return array{xml: string, skipped: array<int, string>, needsManualId: array<int, array{minifig_id:int, fig_num:string, name:string, thumbnail:?string}>}
 */
/**
 * The same three categories (parts, spares if shown, stickers if shown) the
 * "Beschädigt/Fehlend" tab and the BrickLink XML export both work from —
 * shared so getOwnedSetBricklinkPartNums() (drives the sync-progress modal's
 * batch plan) and buildOwnedSetBricklinkXml() (builds the actual export)
 * can't drift apart on which items are in scope.
 */
function getOwnedSetBricklinkCategories(PDO $pdo, array $ownedSet): array
{
    $categories = [getOwnedSetPartsWithStatus($pdo, $ownedSet, getLocale())];
    if ($ownedSet['damaged_missing_show_spares']) {
        $categories[] = getOwnedSetSparePartsWithStatus($pdo, $ownedSet, getLocale());
    }
    if ($ownedSet['damaged_missing_show_stickers']) {
        $categories[] = getOwnedSetStickerPartsWithStatus($pdo, $ownedSet, getLocale());
    }

    // A present minifig with an individually damaged/missing constituent part
    // only ever shows up as $fig['damaged_quantity'] (never as its own whole-
    // minifig $figMissing further down in buildOwnedSetBricklinkXml() — see
    // renderOwnedSetDamagedMissingSection()'s doc comment for why those two
    // never overlap), so it needs the PART ordered, not a minifig line. This
    // was previously only surfaced in the "Beschädigt/Fehlend" tab's display
    // and never actually reached the export at all.
    $minifigPartItems = [];
    foreach (getOwnedSetMinifigsWithStatus($pdo, $ownedSet) as $fig) {
        if ($fig['damaged_quantity'] <= 0) {
            continue;
        }
        foreach (getOwnedSetMinifigPartsWithStatus($pdo, $ownedSet, $fig['minifig_id'], $fig['fig_num'], $fig['nominal_quantity'], getLocale()) as $part) {
            $minifigPartItems[] = [
                'part_id' => $part['part_id'],
                'part_num' => $part['part_num'],
                'name' => $fig['name'] . ' · ' . $part['name'],
                'rebrickable_color_id' => $part['rebrickable_color_id'],
                'color_name' => $part['color_name'],
                'thumbnail' => $part['thumbnail'],
                'nominal_quantity' => $part['nominal_quantity'],
                'actual_quantity' => $part['actual_quantity'],
                'damaged_quantity' => $part['damaged_quantity'],
            ];
        }
    }
    if (!empty($minifigPartItems)) {
        $categories[] = $minifigPartItems;
    }

    return $categories;
}

/**
 * part_nums (deduped) still missing a BrickLink id for this set's export —
 * the browser fetches this first (action=owned_set_bricklink_parts_missing)
 * to build its own batch plan for the sync-progress modal, before ever
 * calling buildOwnedSetBricklinkXml(), which itself no longer syncs anything
 * (see that function's own doc comment).
 */
function getOwnedSetBricklinkPartNums(PDO $pdo, array $ownedSet): array
{
    $partNums = [];
    foreach (getOwnedSetBricklinkCategories($pdo, $ownedSet) as $items) {
        foreach ($items as $item) {
            $partNums[$item['part_num']] = true;
        }
    }
    return getPartNumsMissingBricklinkId($pdo, array_keys($partNums));
}

/**
 * Assumes any resolvable parts.bricklink_part_id has already been synced —
 * the browser drives that separately, one batch per tick, via the
 * sync-progress modal (renderOwnedSetBricklinkModal()) BEFORE ever calling
 * the check/build endpoints this function backs, so there's nothing left to
 * sync synchronously here.
 */
function buildOwnedSetBricklinkXml(PDO $pdo, array $ownedSet): array
{
    // The set's own condition_type, not the individual item's — BrickLink's
    // wanted-list CONDITION means "what condition will I accept", and this
    // export always wants a genuine replacement (new for a new set, used is
    // acceptable for a used one), regardless of whether a given line is
    // itself damaged or fully missing.
    $bricklinkCondition = $ownedSet['condition_type'] === 'new' ? 'N' : 'U';
    // Lets a Wanted List that combines exports from several sets still show,
    // per line, which set it's for — same set_num/name pairing shown
    // everywhere else in the app.
    $bricklinkRemarks = htmlspecialchars($ownedSet['rebrickable_set_num'] . ' - ' . $ownedSet['name'], ENT_XML1);
    $categories = getOwnedSetBricklinkCategories($pdo, $ownedSet);

    $colorIds = [];
    $partNums = [];
    foreach ($categories as $items) {
        foreach ($items as $item) {
            if ($item['rebrickable_color_id'] !== null) {
                $colorIds[$item['rebrickable_color_id']] = true;
            }
            $partNums[$item['part_num']] = true;
        }
    }
    $bricklinkColorByRebrickableId = [];
    if (!empty($colorIds)) {
        $placeholders = implode(',', array_fill(0, count($colorIds), '?'));
        $stmt = $pdo->prepare("SELECT color_id, bricklink_color_id FROM colors WHERE color_id IN ($placeholders)");
        $stmt->execute(array_keys($colorIds));
        foreach ($stmt->fetchAll() as $row) {
            $bricklinkColorByRebrickableId[(int) $row['color_id']] = $row['bricklink_color_id'] !== null ? (int) $row['bricklink_color_id'] : null;
        }
    }

    $bricklinkPartIdByPartNum = [];
    if (!empty($partNums)) {
        $placeholders = implode(',', array_fill(0, count($partNums), '?'));
        $stmt = $pdo->prepare("SELECT part_num, bricklink_part_id FROM parts WHERE part_num IN ($placeholders)");
        $stmt->execute(array_keys($partNums));
        foreach ($stmt->fetchAll() as $row) {
            $bricklinkPartIdByPartNum[$row['part_num']] = $row['bricklink_part_id'];
        }
    }

    $lines = [];
    $skipped = [];
    foreach ($categories as $items) {
        foreach ($items as $item) {
            $wantedQty = max(0, $item['nominal_quantity'] - $item['actual_quantity']) + $item['damaged_quantity'];
            if ($wantedQty <= 0) {
                continue;
            }
            $bricklinkColorId = $item['rebrickable_color_id'] !== null ? ($bricklinkColorByRebrickableId[$item['rebrickable_color_id']] ?? null) : null;
            if ($bricklinkColorId === null) {
                $skipped[] = $item['part_num'] . ' (' . $item['name'] . ($item['color_name'] !== null ? ' · ' . $item['color_name'] : '') . ')';
                continue;
            }
            $bricklinkPartId = $bricklinkPartIdByPartNum[$item['part_num']] ?? $item['part_num'];
            $lines[] = '  <ITEM><ITEMTYPE>P</ITEMTYPE><ITEMID>' . htmlspecialchars($bricklinkPartId, ENT_XML1)
                . '</ITEMID><COLOR>' . $bricklinkColorId . '</COLOR><MINQTY>' . $wantedQty
                . '</MINQTY><CONDITION>' . $bricklinkCondition . '</CONDITION><REMARKS>' . $bricklinkRemarks . '</REMARKS></ITEM>';
        }
    }

    $needsManualId = [];
    foreach (getOwnedSetMinifigsWithStatus($pdo, $ownedSet) as $fig) {
        $figMissing = $fig['nominal_quantity'] - $fig['actual_quantity'];
        if ($figMissing <= 0) {
            continue;
        }
        $bricklinkId = getOrFetchBricklinkMinifigId($pdo, $fig['minifig_id'], $fig['fig_num']);
        if ($bricklinkId === null) {
            $needsManualId[] = [
                'minifig_id' => $fig['minifig_id'],
                'fig_num' => $fig['fig_num'],
                'name' => $fig['name'],
                'thumbnail' => $fig['thumbnail'],
            ];
            continue;
        }
        $lines[] = '  <ITEM><ITEMTYPE>M</ITEMTYPE><ITEMID>' . htmlspecialchars($bricklinkId, ENT_XML1)
            . '</ITEMID><MINQTY>' . $figMissing . '</MINQTY><CONDITION>' . $bricklinkCondition . '</CONDITION><REMARKS>' . $bricklinkRemarks . '</REMARKS></ITEM>';
    }

    $xml = '<INVENTORY>' . "\n" . implode("\n", $lines) . "\n" . '</INVENTORY>';
    if (!empty($skipped)) {
        $xml .= "\n" . '<!-- Ohne BrickLink-Farbzuordnung ausgelassen: ' . htmlspecialchars(implode(', ', $skipped), ENT_XML1) . ' -->';
    }

    return ['xml' => $xml, 'skipped' => $skipped, 'needsManualId' => $needsManualId];
}

/**
 * owned_set_detail's "Beschädigt/Fehlend" tab — a read-only overview across
 * all four categories (no editing here; that happens on each category's own
 * tab). Deliberately just a list for now — the user's eventual plan is to
 * let a missing/damaged row here be filled in directly from loose stock
 * (with a stock transfer into this instance's location), but that's a
 * separate, not-yet-built feature; this only ever displays.
 *
 * Spares and stickers are excluded by default (most sets don't track
 * either closely, and both categories tend to be the noisiest — a
 * near-complete spares/sticker count reads as "damaged/missing" here just
 * as loudly as an actually-incomplete model) — two checkboxes let the
 * owner opt either back in, per instance (owned_sets.damaged_missing_show_*,
 * migration 22), not globally. Regular inventory and minifigs always show;
 * only these two are ever gated.
 */
function renderOwnedSetDamagedMissingSection(PDO $pdo, array $ownedSet): string
{
    $showSpares = $ownedSet['damaged_missing_show_spares'];
    $showStickers = $ownedSet['damaged_missing_show_stickers'];

    $categories = [
        'owned_set_tab_inventory' => getOwnedSetPartsWithStatus($pdo, $ownedSet, getLocale()),
    ];
    if ($showSpares) {
        $categories['owned_set_tab_spares'] = getOwnedSetSparePartsWithStatus($pdo, $ownedSet, getLocale());
    }
    if ($showStickers) {
        $categories['owned_set_tab_stickers'] = getOwnedSetStickerPartsWithStatus($pdo, $ownedSet, getLocale());
    }

    $rows = [];
    foreach ($categories as $labelKey => $items) {
        foreach ($items as $item) {
            $missing = $item['nominal_quantity'] - $item['actual_quantity'];
            if ($item['damaged_quantity'] <= 0 && $missing <= 0) {
                continue;
            }
            $rows[] = [
                'category' => t($labelKey),
                'thumbnail' => $item['thumbnail'],
                'name' => $item['name'] . ($item['color_name'] !== null ? ' · ' . $item['color_name'] : ''),
                'damaged' => $item['damaged_quantity'],
                'missing' => max(0, $missing),
            ];
        }
    }
    foreach (getOwnedSetMinifigsWithStatus($pdo, $ownedSet) as $fig) {
        $figMissing = $fig['nominal_quantity'] - $fig['actual_quantity'];
        if ($fig['damaged_quantity'] <= 0 && $figMissing <= 0) {
            continue;
        }

        // A whole copy simply not being there ("present" via
        // getOwnedSetMinifigsWithStatus()'s bottleneck, which is generous —
        // see ownedSetMinifigBottleneckStatus()'s doc comment) gets its own
        // row for the whole figure — that's what a BrickLink order for it
        // needs (a minifig line, not its parts). Doesn't overlap with the
        // per-part breakdown below: an individually missing/damaged part on
        // an otherwise-present copy only ever reduces "complete", i.e. only
        // ever shows up as $fig['damaged_quantity'], never as $figMissing.
        if ($figMissing > 0) {
            $rows[] = [
                'category' => t('owned_set_tab_minifigs'),
                'thumbnail' => $fig['thumbnail'],
                'name' => $fig['name'],
                'damaged' => 0,
                'missing' => $figMissing,
            ];
        }

        // Present copies with a damaged/incomplete constituent part — break
        // that down to the part level instead of one aggregate "Minifigur
        // X: 1 beschädigt" row, since that alone doesn't say whether it's a
        // missing head or a damaged hip piece.
        if ($fig['damaged_quantity'] > 0) {
            foreach (getOwnedSetMinifigPartsWithStatus($pdo, $ownedSet, $fig['minifig_id'], $fig['fig_num'], $fig['nominal_quantity'], getLocale()) as $part) {
                $partMissing = $part['nominal_quantity'] - $part['actual_quantity'];
                if ($part['damaged_quantity'] <= 0 && $partMissing <= 0) {
                    continue;
                }
                $rows[] = [
                    'category' => t('owned_set_tab_minifigs'),
                    'thumbnail' => $part['thumbnail'],
                    'name' => $fig['name'] . ' · ' . $part['name'] . ($part['color_name'] !== null ? ' · ' . $part['color_name'] : ''),
                    'damaged' => $part['damaged_quantity'],
                    'missing' => max(0, $partMissing),
                ];
            }
        }
    }

    $html = '<div class="owned-set-damaged-missing-filters">';
    $html .= '<label class="checkbox-label"><input type="checkbox" id="owned-set-damaged-missing-spares"' . ($showSpares ? ' checked' : '') . '> ' . htmlspecialchars(t('owned_set_damaged_missing_show_spares')) . '</label>';
    $html .= '<label class="checkbox-label"><input type="checkbox" id="owned-set-damaged-missing-stickers"' . ($showStickers ? ' checked' : '') . '> ' . htmlspecialchars(t('owned_set_damaged_missing_show_stickers')) . '</label>';
    $html .= '</div>';

    if (empty($rows)) {
        $html .= '<section class="card"><p>' . htmlspecialchars(t('owned_set_damaged_missing_empty')) . '</p></section>';
    } else {
        $html .= '<div class="owned-set-inventory-tiles">';
        foreach ($rows as $row) {
            $html .= '<div class="owned-set-inventory-tile owned-set-inventory-tile-readonly">';
            $html .= '<span class="part-card-image">' . ($row['thumbnail'] !== null ? '<img src="' . htmlspecialchars($row['thumbnail']) . '" alt="">' : getNavIcon('bricks')) . '</span>';
            $html .= '<span class="part-card-num">' . htmlspecialchars($row['category']) . '</span>';
            $html .= '<span class="part-card-name">' . htmlspecialchars($row['name']) . '</span>';
            $html .= '<p class="owned-set-inventory-summary">' . htmlspecialchars(t('owned_set_damaged_missing_row', ['damaged' => (string) $row['damaged'], 'missing' => (string) $row['missing']])) . '</p>';
            $html .= '</div>';
        }
        $html .= '</div>';
    }

    $ownedSetId = (int) $ownedSet['id'];
    $filterLabelsJson = json_encode([
        'errorRetry' => t('import_error_retry'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html .= <<<SCRIPT
<script>
(function(){
  var texts = $filterLabelsJson;
  var ownedSetId = $ownedSetId;
  var sparesBox = document.getElementById('owned-set-damaged-missing-spares');
  var stickersBox = document.getElementById('owned-set-damaged-missing-stickers');
  var container = document.getElementById('owned-set-tab-content');
  if (!sparesBox || !stickersBox || !container) {
    return;
  }

  function reloadTab() {
    container.innerHTML = '';
    var params = new URLSearchParams(window.location.search);
    params.set('page', 'owned_set_detail');
    params.set('id', String(ownedSetId));
    params.set('tab', 'damaged_missing');
    params.set('ajax', '1');
    fetch('?' + params.toString(), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (!res.success) {
          container.textContent = res.message || texts.errorRetry;
          return;
        }
        container.innerHTML = res.html;
        var scripts = container.querySelectorAll('script');
        for (var i = 0; i < scripts.length; i++) {
          var oldScript = scripts[i];
          var freshScript = document.createElement('script');
          freshScript.textContent = oldScript.textContent;
          oldScript.parentNode.replaceChild(freshScript, oldScript);
        }
      })
      .catch(function() {
        container.textContent = texts.errorRetry;
      });
  }

  function saveAndReload() {
    var formData = new FormData();
    formData.set('action', 'save_owned_set_damaged_missing_settings');
    formData.set('owned_set_id', String(ownedSetId));
    formData.set('show_spares', sparesBox.checked ? '1' : '');
    formData.set('show_stickers', stickersBox.checked ? '1' : '');
    fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function() { reloadTab(); })
      .catch(function() { reloadTab(); });
  }

  sparesBox.addEventListener('change', saveAndReload);
  stickersBox.addEventListener('change', saveAndReload);
})();
</script>
SCRIPT;

    return $html;
}

/**
 * The per-instance photo gallery (upload form + grid with delete) — used
 * both on the sealed-set branch of owned_set_detail (photos are worth
 * capturing even for a still-boxed set) and as the "Fotos" tab once the
 * instance is opened. Self-contained, own script, same convention as the
 * other owned_set_detail sections.
 */
function renderOwnedSetPhotoGallery(PDO $pdo, array $ownedSet): string
{
    $html = '<form id="owned-set-photo-form" class="instruction-upload-form">';
    $html .= '<input type="text" id="owned-set-photo-caption-input" placeholder="' . htmlspecialchars(t('owned_set_photo_caption_placeholder')) . '" maxlength="255">';
    $html .= '<input type="file" id="owned-set-photo-file-input" accept="image/*">';
    $html .= '<button type="submit">' . htmlspecialchars(t('set_detail_instructions_upload_button')) . '</button>';
    $html .= '<span class="instruction-upload-message" id="owned-set-photo-message"></span>';
    $html .= '</form>';

    $photos = getOwnedSetPhotos($pdo, $ownedSet['id']);
    $html .= '<div class="owned-set-photo-grid" id="owned-set-photo-grid">';
    foreach ($photos as $photo) {
        $html .= '<div class="owned-set-photo" data-id="' . $photo['id'] . '">';
        $html .= '<img src="' . htmlspecialchars($photo['stored_path']) . '" alt="' . htmlspecialchars((string) $photo['caption']) . '">';
        if ($photo['caption'] !== null) {
            $html .= '<span class="owned-set-photo-caption">' . htmlspecialchars($photo['caption']) . '</span>';
        }
        $html .= '<button type="button" class="owned-set-photo-delete" data-id="' . $photo['id'] . '">' . htmlspecialchars(t('set_detail_instructions_delete_button')) . '</button>';
        $html .= '</div>';
    }
    $html .= '</div>';

    $photoLabelsJson = json_encode([
        'uploading' => t('set_detail_instructions_uploading'),
        'deleteConfirm' => t('owned_set_photo_delete_confirm'),
        'errorRetry' => t('import_error_retry'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $html .= <<<SCRIPT
<script>
(function(){
  var texts = $photoLabelsJson;
  var form = document.getElementById("owned-set-photo-form");
  var captionInput = document.getElementById("owned-set-photo-caption-input");
  var fileInput = document.getElementById("owned-set-photo-file-input");
  var msg = document.getElementById("owned-set-photo-message");
  var grid = document.getElementById("owned-set-photo-grid");
  if (!form || !fileInput || !msg || !grid) {
    return;
  }

  function bindDelete(btn) {
    btn.addEventListener("click", function() {
      if (!window.confirm(texts.deleteConfirm)) {
        return;
      }
      var formData = new FormData();
      formData.set("action", "delete_owned_set_photo");
      formData.set("photo_id", btn.dataset.id);
      fetch("?", { method: "POST", body: formData, credentials: "same-origin" })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (res.success) {
            btn.closest(".owned-set-photo").remove();
          }
        });
    });
  }
  grid.querySelectorAll(".owned-set-photo-delete").forEach(bindDelete);

  form.addEventListener("submit", function(e) {
    e.preventDefault();
    if (!fileInput.files || !fileInput.files[0]) {
      return;
    }
    msg.textContent = texts.uploading;
    var formData = new FormData();
    formData.set("action", "upload_owned_set_photo");
    formData.set("owned_set_id", "{$ownedSet['id']}");
    formData.set("caption", captionInput.value);
    formData.set("photo_file", fileInput.files[0]);

    fetch("?", { method: "POST", body: formData, credentials: "same-origin" })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success) {
          window.location.reload();
        } else {
          msg.textContent = res.message;
        }
      })
      .catch(function() {
        msg.textContent = texts.errorRetry;
      });
  });
})();
</script>
SCRIPT;

    return $html;
}
