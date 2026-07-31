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
 * @return array{id:int, set_id:int, inventory_id:?int, location_id:int, condition_type:string, has_instructions:bool, has_box:bool, box_complete:bool, notes:?string, instructions_notes:?string, box_notes:?string, box_complete_notes:?string, stickers_applied:bool, stickers_notes:?string, created_at:string, rebrickable_set_num:string, name:string, thumbnail:?string}|null
 */
function getOwnedSetById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT os.id, os.set_id, os.inventory_id, os.location_id, os.condition_type, os.has_instructions, os.has_box, os.box_complete,
                os.notes, os.instructions_notes, os.box_notes, os.box_complete_notes, os.stickers_applied, os.stickers_notes, os.created_at,
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
 * @return array{nominal:int, actual:int, percent:float}
 */
function getOwnedSetCompleteness(PDO $pdo, array $ownedSet): array
{
    $inventoryId = resolveOwnedSetInventoryId($pdo, $ownedSet);
    if ($inventoryId === null) {
        return ['nominal' => 0, 'actual' => 0, 'percent' => 100.0];
    }

    $nominalStmt = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM inventory_parts WHERE inventory_id = ? AND is_spare = 0');
    $nominalStmt->execute([$inventoryId]);
    $nominal = (int) $nominalStmt->fetchColumn();

    $actualStmt = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM storage_items WHERE location_id = ?');
    $actualStmt->execute([$ownedSet['location_id']]);
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
 * @return array<int, array{part_id:int, part_num:string, name:string, color_id:int, color_name:?string, color_rgb:?string, thumbnail:?string, nominal_quantity:int, actual_quantity:int, damaged_quantity:int}>
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

    $html = '<div class="owned-set-total-ring-wrap">';
    $html .= '<svg class="owned-set-total-ring" viewBox="0 0 100 100" aria-hidden="true">';
    $html .= '<circle class="owned-set-total-ring-bg" cx="50" cy="50" r="45"></circle>';
    $html .= '<circle class="owned-set-total-ring-fg" id="owned-set-total-ring-fg" cx="50" cy="50" r="45" style="stroke-dasharray: ' . sprintf('%.2f', $circumference) . '; stroke-dashoffset: ' . sprintf('%.2f', $offset) . ';"></circle>';
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
 * owned_set_detail's "Beschädigt/Fehlend" tab — a read-only overview across
 * all four categories (no editing here; that happens on each category's own
 * tab). Deliberately just a list for now — the user's eventual plan is to
 * let a missing/damaged row here be filled in directly from loose stock
 * (with a stock transfer into this instance's location), but that's a
 * separate, not-yet-built feature; this only ever displays.
 */
function renderOwnedSetDamagedMissingSection(PDO $pdo, array $ownedSet): string
{
    $categories = [
        'owned_set_tab_inventory' => getOwnedSetPartsWithStatus($pdo, $ownedSet, getLocale()),
        'owned_set_tab_spares' => getOwnedSetSparePartsWithStatus($pdo, $ownedSet, getLocale()),
        'owned_set_tab_stickers' => getOwnedSetStickerPartsWithStatus($pdo, $ownedSet, getLocale()),
    ];

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
        $missing = $fig['nominal_quantity'] - $fig['actual_quantity'];
        if ($fig['damaged_quantity'] <= 0 && $missing <= 0) {
            continue;
        }
        $rows[] = [
            'category' => t('owned_set_tab_minifigs'),
            'thumbnail' => $fig['thumbnail'],
            'name' => $fig['name'],
            'damaged' => $fig['damaged_quantity'],
            'missing' => max(0, $missing),
        ];
    }

    if (empty($rows)) {
        return '<section class="card"><p>' . htmlspecialchars(t('owned_set_damaged_missing_empty')) . '</p></section>';
    }

    $html = '<div class="owned-set-inventory-tiles">';
    foreach ($rows as $row) {
        $html .= '<div class="owned-set-inventory-tile owned-set-inventory-tile-readonly">';
        $html .= '<span class="part-card-image">' . ($row['thumbnail'] !== null ? '<img src="' . htmlspecialchars($row['thumbnail']) . '" alt="">' : getNavIcon('bricks')) . '</span>';
        $html .= '<span class="part-card-num">' . htmlspecialchars($row['category']) . '</span>';
        $html .= '<span class="part-card-name">' . htmlspecialchars($row['name']) . '</span>';
        $html .= '<p class="owned-set-inventory-summary">' . htmlspecialchars(t('owned_set_damaged_missing_row', ['damaged' => (string) $row['damaged'], 'missing' => (string) $row['missing']])) . '</p>';
        $html .= '</div>';
    }
    $html .= '</div>';

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
