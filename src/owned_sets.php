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
 * @return array{id:int, set_id:int, inventory_id:?int, location_id:int, condition_type:string, has_instructions:bool, has_box:bool, box_complete:bool, notes:?string, instructions_notes:?string, box_notes:?string, box_complete_notes:?string, created_at:string, rebrickable_set_num:string, name:string, thumbnail:?string}|null
 */
function getOwnedSetById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT os.id, os.set_id, os.inventory_id, os.location_id, os.condition_type, os.has_instructions, os.has_box, os.box_complete,
                os.notes, os.instructions_notes, os.box_notes, os.box_complete_notes, os.created_at,
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
 * Nominal (from the set's own Rebrickable inventory, non-spare) vs. actual
 * (storage_items at the instance's location) piece counts — the single
 * source of truth for "how complete is this set", computed on the fly
 * rather than stored, since actual quantity already lives in storage_items.
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

    $percent = $nominal > 0 ? round(min(100.0, ($actual / $nominal) * 100), 1) : 100.0;

    return ['nominal' => $nominal, 'actual' => $actual, 'percent' => $percent];
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
 * @return array<int, array{part_id:int, part_num:string, name:string, color_id:int, color_name:?string, color_rgb:?string, thumbnail:?string, nominal_quantity:int, actual_quantity:int, damaged_quantity:int}>
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
    ?int $inventoryId = null
): int {
    $set = getSetById($pdo, $setId);
    if ($set === null) {
        throw new RuntimeException('Set nicht gefunden.');
    }

    // A still-sealed set trivially has its instructions, box, and a complete
    // box — enforced server-side too, not just via the wizard's disabled
    // checkboxes, since those wouldn't stop a direct POST.
    if ($conditionType === 'new') {
        $hasInstructions = true;
        $hasBox = true;
        $boxComplete = true;
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
        'INSERT INTO owned_sets (set_id, inventory_id, location_id, condition_type, has_instructions, has_box, box_complete, notes, instructions_notes, box_notes, box_complete_notes, added_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
 * condition_type is deliberately not editable here — see openOwnedSet()'s
 * doc comment for why it can only ever move new -> used via that dedicated
 * path, never as a free-standing field on this general edit form.
 */
function updateOwnedSet(
    PDO $pdo,
    int $ownedSetId,
    bool $hasInstructions,
    bool $hasBox,
    bool $boxComplete,
    ?string $notes,
    ?string $instructionsNotes = null,
    ?string $boxNotes = null,
    ?string $boxCompleteNotes = null
): void {
    $stmt = $pdo->prepare(
        'UPDATE owned_sets SET has_instructions = ?, has_box = ?, box_complete = ?, notes = ?, instructions_notes = ?, box_notes = ?, box_complete_notes = ? WHERE id = ?'
    );
    $stmt->execute([
        $hasInstructions ? 1 : 0,
        $hasBox ? 1 : 0,
        $boxComplete ? 1 : 0,
        $notes,
        $instructionsNotes,
        $boxNotes,
        $boxCompleteNotes,
        $ownedSetId,
    ]);
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
 * The "Set zur Sammlung hinzufügen" assistant: version (if the set has more
 * than one Rebrickable inventory revision — see getSetInventoryVersions()
 * in src/sets.php), location, condition/box details, general notes,
 * inventory prompt, and — on "Ja" — an inline inventory step covering all
 * four trackable categories in sequence (Bauteile, Ersatzteile,
 * Stickerbögen, Minifiguren; any category the set doesn't have is skipped
 * automatically) right in the same modal instead of redirecting first.
 * Self-contained (own markup + own <script>, reuses the generic
 * .modal-overlay/.modal-box shell already used by renderPartDetailModal())
 * so the caller just embeds the returned HTML — same pattern as
 * renderLdrawRenderOverlay() in src/ldraw.php.
 *
 * Step numbers are computed once in PHP ($stepNames) since whether the
 * version step exists at all depends on the set (single-revision sets skip
 * straight to location) — baked into the emitted markup's data-step/
 * data-next/data-back attributes and a few JS constants, so the script
 * itself never has to special-case "does this set have versions".
 *
 * Nothing is persisted until the inventory question is answered (Ja or
 * Nein): both answers trigger the same add_owned_set AJAX call, "Nein"
 * then redirects immediately, "Ja" stays in the modal for the inventory
 * step. Closing the modal before that point discards everything
 * client-side — no draft state on the server.
 */
function renderAddOwnedSetWizardModal(PDO $pdo, int $setId): string
{
    $set = getSetById($pdo, $setId);
    $versions = $set !== null ? getSetInventoryVersions($pdo, $set['rebrickable_set_num']) : [];
    $hasVersionStep = count($versions) > 1;

    $stepNames = $hasVersionStep
        ? ['version' => 1, 'location' => 2, 'details' => 3, 'notes' => 4, 'question' => 5, 'inventory' => 6]
        : ['location' => 1, 'details' => 2, 'notes' => 3, 'question' => 4, 'inventory' => 5];

    $html = '<div class="modal-overlay" id="add-owned-set-modal" style="display:none;">';
    $html .= '<div class="modal-box owned-set-wizard-box">';
    $html .= '<button type="button" class="modal-close" id="add-owned-set-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<h2>' . htmlspecialchars(t('owned_set_wizard_title')) . '</h2>';
    $html .= '<p class="owned-set-wizard-progress" id="owned-set-wizard-progress"></p>';

    if ($hasVersionStep) {
        $html .= '<div class="owned-set-wizard-step" id="owned-set-wizard-step-' . $stepNames['version'] . '" data-step="' . $stepNames['version'] . '">';
        $html .= '<h3>' . htmlspecialchars(t('owned_set_wizard_version_heading')) . '</h3>';
        foreach ($versions as $i => $v) {
            $checkedAttr = $i === 0 ? ' checked' : '';
            $html .= '<label class="checkbox-label"><input type="radio" name="owned-set-wizard-version" value="' . $v['inventory_id'] . '"' . $checkedAttr . '> ' . htmlspecialchars(t('owned_set_wizard_version_label', ['version' => (string) $v['version']])) . '</label>';
        }
        $html .= '<div class="owned-set-wizard-nav"><button type="button" class="owned-set-wizard-next" data-next="' . $stepNames['location'] . '">' . htmlspecialchars(t('owned_set_wizard_next')) . '</button></div>';
        $html .= '</div>';
    }

    $html .= '<div class="owned-set-wizard-step" id="owned-set-wizard-step-' . $stepNames['location'] . '" data-step="' . $stepNames['location'] . '"' . ($hasVersionStep ? ' style="display:none;"' : '') . '>';
    $html .= '<h3>' . htmlspecialchars(t('owned_set_wizard_step1_heading')) . '</h3>';
    $locationLevels = [
        [1, 'add_stock_level1_label'],
        [2, 'add_stock_level2_label'],
        [3, 'add_stock_level3_label'],
    ];
    foreach ($locationLevels as [$level, $labelKey]) {
        $html .= '<div class="location-level">';
        $html .= '<span class="location-level-label">' . htmlspecialchars(t($labelKey)) . '</span>';
        $html .= '<select id="owned-set-wizard-location-' . $level . '"' . ($level > 1 ? ' disabled' : '') . '>';
        $html .= '<option value="">' . htmlspecialchars(t('add_stock_select_placeholder')) . '</option>';
        if ($level === 1) {
            foreach (getChildLocations(null) as $loc) {
                $html .= '<option value="' . (int) $loc['id'] . '">' . htmlspecialchars($loc['name']) . '</option>';
            }
        }
        $html .= '</select>';
        $html .= '<span class="location-hint" id="owned-set-wizard-location-' . $level . '-hint"></span>';
        $html .= '</div>';
    }
    $html .= '<p class="owned-set-wizard-error" id="owned-set-wizard-step1-error"></p>';
    $backBtn = $hasVersionStep ? '<button type="button" class="owned-set-wizard-back" data-back="' . $stepNames['version'] . '">' . htmlspecialchars(t('owned_set_wizard_back')) . '</button>' : '';
    $html .= '<div class="owned-set-wizard-nav">' . $backBtn . '<button type="button" class="owned-set-wizard-next" data-next="' . $stepNames['details'] . '">' . htmlspecialchars(t('owned_set_wizard_next')) . '</button></div>';
    $html .= '</div>';

    $html .= '<div class="owned-set-wizard-step" id="owned-set-wizard-step-' . $stepNames['details'] . '" data-step="' . $stepNames['details'] . '" style="display:none;">';
    $html .= '<h3>' . htmlspecialchars(t('owned_set_wizard_step2_heading')) . '</h3>';
    $html .= '<label class="checkbox-label"><input type="radio" name="owned-set-wizard-condition" value="new"> ' . htmlspecialchars(t('owned_set_condition_new')) . '</label>';
    $html .= '<label class="checkbox-label"><input type="radio" name="owned-set-wizard-condition" value="used" checked> ' . htmlspecialchars(t('owned_set_condition_used')) . '</label>';

    $detailFields = [
        ['has-instructions', 'owned_set_has_instructions', 'instructions-notes', 'owned_set_instructions_notes_label'],
        ['has-box', 'owned_set_has_box', 'box-notes', 'owned_set_box_notes_label'],
        ['has-box-complete', 'owned_set_box_complete', 'box-complete-notes', 'owned_set_box_complete_notes_label'],
    ];
    foreach ($detailFields as [$checkboxId, $checkboxLabelKey, $notesId, $notesLabelKey]) {
        $html .= '<div class="owned-set-wizard-detail-group">';
        $html .= '<label class="checkbox-label"><input type="checkbox" id="owned-set-wizard-' . $checkboxId . '" value="1"> ' . htmlspecialchars(t($checkboxLabelKey)) . '</label>';
        $html .= '<textarea class="owned-set-wizard-subnote" id="owned-set-wizard-' . $notesId . '" rows="2" placeholder="' . htmlspecialchars(t($notesLabelKey)) . '" style="display:none;"></textarea>';
        $html .= '</div>';
    }

    $html .= '<div class="owned-set-wizard-nav"><button type="button" class="owned-set-wizard-back" data-back="' . $stepNames['location'] . '">' . htmlspecialchars(t('owned_set_wizard_back')) . '</button><button type="button" class="owned-set-wizard-next" data-next="' . $stepNames['notes'] . '">' . htmlspecialchars(t('owned_set_wizard_next')) . '</button></div>';
    $html .= '</div>';

    $html .= '<div class="owned-set-wizard-step" id="owned-set-wizard-step-' . $stepNames['notes'] . '" data-step="' . $stepNames['notes'] . '" style="display:none;">';
    $html .= '<h3>' . htmlspecialchars(t('owned_set_wizard_step3_heading')) . '</h3>';
    $html .= '<label>' . htmlspecialchars(t('owned_set_notes_label')) . '<textarea id="owned-set-wizard-notes" rows="4"></textarea></label>';
    $html .= '<p class="owned-set-wizard-error" id="owned-set-wizard-step3-error"></p>';
    $html .= '<div class="owned-set-wizard-nav"><button type="button" class="owned-set-wizard-back" data-back="' . $stepNames['details'] . '">' . htmlspecialchars(t('owned_set_wizard_back')) . '</button><button type="button" class="owned-set-wizard-next" data-next="' . $stepNames['question'] . '">' . htmlspecialchars(t('owned_set_wizard_next')) . '</button></div>';
    $html .= '</div>';

    $html .= '<div class="owned-set-wizard-step" id="owned-set-wizard-step-' . $stepNames['question'] . '" data-step="' . $stepNames['question'] . '" style="display:none;">';
    $html .= '<h3>' . htmlspecialchars(t('owned_set_wizard_step4_heading')) . '</h3>';
    $html .= '<p>' . htmlspecialchars(t('owned_set_wizard_inventory_question')) . '</p>';
    $html .= '<p class="owned-set-wizard-error" id="owned-set-wizard-step4-error"></p>';
    $html .= '<div class="owned-set-wizard-nav"><button type="button" class="owned-set-wizard-back" data-back="' . $stepNames['notes'] . '">' . htmlspecialchars(t('owned_set_wizard_back')) . '</button><button type="button" id="owned-set-wizard-inventory-no">' . htmlspecialchars(t('owned_set_wizard_no')) . '</button><button type="button" id="owned-set-wizard-inventory-yes">' . htmlspecialchars(t('owned_set_wizard_yes')) . '</button></div>';
    $html .= '</div>';

    $html .= '<div class="owned-set-wizard-step" id="owned-set-wizard-step-' . $stepNames['inventory'] . '" data-step="' . $stepNames['inventory'] . '" style="display:none;">';
    $html .= '<h3>' . htmlspecialchars(t('owned_set_wizard_step5_heading')) . '</h3>';
    $html .= '<p class="owned-set-inventory-progress" id="owned-set-wizard-inventory-progress"></p>';
    $html .= '<div class="owned-set-inventory-tiles" id="owned-set-wizard-parts-list"></div>';
    $html .= '<div class="owned-set-inventory-nav">';
    $html .= '<button type="button" id="owned-set-wizard-inventory-back">' . htmlspecialchars(t('owned_set_wizard_back')) . '</button>';
    $html .= '<button type="button" id="owned-set-wizard-inventory-next">' . htmlspecialchars(t('owned_set_wizard_next')) . '</button>';
    $html .= '</div>';
    $html .= '<p class="owned-set-wizard-error" id="owned-set-wizard-step5-error"></p>';
    $html .= '<div class="owned-set-wizard-nav"><a href="#" id="owned-set-wizard-skip">' . htmlspecialchars(t('owned_set_wizard_skip')) . '</a><button type="button" id="owned-set-wizard-finish">' . htmlspecialchars(t('owned_set_wizard_finish')) . '</button></div>';
    $html .= '</div>';

    $html .= '</div></div>';

    $labelsJson = json_encode([
        'stepLabel' => t('owned_set_wizard_step_label'),
        'locationRequired' => t('owned_set_wizard_location_required'),
        'errorRetry' => t('import_error_retry'),
        'selectPlaceholder' => t('add_stock_select_placeholder'),
        'noChildren' => t('add_stock_no_children'),
        'ownedLabel' => t('owned_set_inventory_owned_label'),
        'damagedLabel' => t('owned_set_inventory_damaged_label'),
        'inventorySummary' => t('owned_set_inventory_summary'),
        'partProgress' => t('owned_set_inventory_part_progress'),
        'categoryParts' => t('owned_set_wizard_category_parts'),
        'categorySpares' => t('owned_set_wizard_category_spares'),
        'categoryStickers' => t('owned_set_wizard_category_stickers'),
        'categoryMinifigs' => t('owned_set_wizard_category_minifigs'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $detailsStep = $stepNames['details'];
    $questionStep = $stepNames['question'];
    $inventoryStep = $stepNames['inventory'];

    $html .= <<<SCRIPT
<script>
(function(){
  var texts = $labelsJson;
  var setId = $setId;
  var DETAILS_STEP = $detailsStep;
  var QUESTION_STEP = $questionStep;
  var INVENTORY_STEP = $inventoryStep;
  var openBtn = document.getElementById('add-owned-set-open');
  var modal = document.getElementById('add-owned-set-modal');
  var closeBtn = document.getElementById('add-owned-set-modal-close');
  var progress = document.getElementById('owned-set-wizard-progress');
  if (!modal || !closeBtn || !progress) {
    return;
  }

  var steps = Array.prototype.slice.call(modal.querySelectorAll('.owned-set-wizard-step'));
  var totalSteps = QUESTION_STEP;
  var createdOwnedSetId = null;

  var loc1 = document.getElementById('owned-set-wizard-location-1');
  var loc2 = document.getElementById('owned-set-wizard-location-2');
  var loc3 = document.getElementById('owned-set-wizard-location-3');
  var loc2Hint = document.getElementById('owned-set-wizard-location-2-hint');
  var loc3Hint = document.getElementById('owned-set-wizard-location-3-hint');

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

  // The deepest level the user actually picked becomes the owned-set's
  // parent location — drilling all 3 levels isn't required (unlike the
  // part-detail "add stock" picker, which always needs an exact slot; here
  // any node in the tree is a valid place to put a whole set).
  function getSelectedLocationId() {
    return loc3.value || loc2.value || loc1.value;
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

  function showStep(n) {
    steps.forEach(function(step) {
      step.style.display = (parseInt(step.dataset.step, 10) === n) ? 'block' : 'none';
    });
    progress.textContent = texts.stepLabel.replace('{current}', n).replace('{total}', totalSteps);
  }

  function resetWizard() {
    createdOwnedSetId = null;
    totalSteps = QUESTION_STEP;
    var firstVersionRadio = modal.querySelector('input[name="owned-set-wizard-version"]');
    if (firstVersionRadio) { firstVersionRadio.checked = true; }
    loc1.value = '';
    loc2.innerHTML = '<option value="">' + texts.selectPlaceholder + '</option>';
    loc3.innerHTML = '<option value="">' + texts.selectPlaceholder + '</option>';
    loc2.disabled = true;
    loc3.disabled = true;
    loc2Hint.textContent = '';
    loc3Hint.textContent = '';
    document.getElementById('owned-set-wizard-step1-error').textContent = '';
    document.getElementById('owned-set-wizard-step3-error').textContent = '';
    document.getElementById('owned-set-wizard-step4-error').textContent = '';
    document.getElementById('owned-set-wizard-step5-error').textContent = '';
    document.getElementById('owned-set-wizard-notes').value = '';
    ['instructions', 'box', 'box-complete'].forEach(function(key) {
      var checkbox = document.getElementById('owned-set-wizard-has-' + (key === 'box-complete' ? 'box-complete' : key));
      var notes = document.getElementById('owned-set-wizard-' + key + '-notes');
      if (checkbox) { checkbox.checked = false; checkbox.disabled = false; }
      if (notes) { notes.value = ''; notes.style.display = 'none'; }
    });
    var usedRadio = modal.querySelector('input[name="owned-set-wizard-condition"][value="used"]');
    if (usedRadio) { usedRadio.checked = true; }
    pages = [];
    pageIndex = 0;
    state = { parts: {}, spares: {}, stickers: {}, minifigs: {} };
    document.getElementById('owned-set-wizard-parts-list').innerHTML = '';
    document.getElementById('owned-set-wizard-inventory-progress').textContent = '';
    showStep(steps[0] ? parseInt(steps[0].dataset.step, 10) : 1);
  }

  function openModal() {
    resetWizard();
    modal.style.display = 'flex';
  }

  function closeModal() {
    modal.style.display = 'none';
  }

  if (openBtn) {
    openBtn.addEventListener('click', function(e) {
      e.preventDefault();
      openModal();
    });
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

  modal.querySelectorAll('.owned-set-wizard-next').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var next = parseInt(btn.dataset.next, 10);
      if (next === DETAILS_STEP) {
        var location = getSelectedLocationId();
        var errorEl = document.getElementById('owned-set-wizard-step1-error');
        if (!location) {
          errorEl.textContent = texts.locationRequired;
          return;
        }
        errorEl.textContent = '';
      }
      if (next === QUESTION_STEP) {
        // A still-sealed set can't be inventoried (nothing can be verified
        // without opening it, which is itself the new -> used transition —
        // see openOwnedSet() in src/owned_sets.php), so the question step is
        // skipped entirely and the wizard finishes right here.
        var conditionRadio = modal.querySelector('input[name="owned-set-wizard-condition"]:checked');
        if (conditionRadio && conditionRadio.value === 'new') {
          finishWithoutInventory(document.getElementById('owned-set-wizard-step3-error'));
          return;
        }
      }
      showStep(next);
    });
  });
  modal.querySelectorAll('.owned-set-wizard-back').forEach(function(btn) {
    btn.addEventListener('click', function() {
      showStep(parseInt(btn.dataset.back, 10));
    });
  });

  var detailPairs = [
    ['instructions', 'owned-set-wizard-has-instructions'],
    ['box', 'owned-set-wizard-has-box'],
    ['box-complete', 'owned-set-wizard-has-box-complete']
  ];

  detailPairs.forEach(function(pair) {
    var checkbox = document.getElementById(pair[1]);
    var notes = document.getElementById('owned-set-wizard-' + pair[0] + '-notes');
    if (checkbox && notes) {
      checkbox.addEventListener('change', function() {
        notes.style.display = checkbox.checked ? 'block' : 'none';
      });
    }
  });

  // A still-sealed ("new") set trivially has its instructions, box, and a
  // complete box — nothing can be missing from something nobody has opened
  // yet. So those 3 checkboxes get force-checked and locked while "Neu" is
  // selected; only their notes stay editable. Picking "Gebraucht" just
  // unlocks them again (their current checked state is left as-is).
  modal.querySelectorAll('input[name="owned-set-wizard-condition"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
      if (!radio.checked) {
        return;
      }
      var isNew = radio.value === 'new';
      detailPairs.forEach(function(pair) {
        var checkbox = document.getElementById(pair[1]);
        var notes = document.getElementById('owned-set-wizard-' + pair[0] + '-notes');
        checkbox.disabled = isNew;
        if (isNew) {
          checkbox.checked = true;
          notes.style.display = 'block';
        }
      });
    });
  });

  function submitAddOwnedSet() {
    var formData = new FormData();
    formData.set('action', 'add_owned_set');
    formData.set('set_id', String(setId));
    formData.set('parent_location_id', getSelectedLocationId());
    var conditionRadio = modal.querySelector('input[name="owned-set-wizard-condition"]:checked');
    formData.set('condition_type', conditionRadio ? conditionRadio.value : 'used');
    formData.set('has_instructions', document.getElementById('owned-set-wizard-has-instructions').checked ? '1' : '');
    formData.set('has_box', document.getElementById('owned-set-wizard-has-box').checked ? '1' : '');
    formData.set('box_complete', document.getElementById('owned-set-wizard-has-box-complete').checked ? '1' : '');
    formData.set('notes', document.getElementById('owned-set-wizard-notes').value);
    formData.set('instructions_notes', document.getElementById('owned-set-wizard-instructions-notes').value);
    formData.set('box_notes', document.getElementById('owned-set-wizard-box-notes').value);
    formData.set('box_complete_notes', document.getElementById('owned-set-wizard-box-complete-notes').value);
    var versionRadio = modal.querySelector('input[name="owned-set-wizard-version"]:checked');
    if (versionRadio) {
      formData.set('inventory_id', versionRadio.value);
    }

    return fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function(r) { return r.json(); });
  }

  function finishWithoutInventory(errorEl) {
    errorEl.textContent = '';
    submitAddOwnedSet().then(function(res) {
      if (res.success) {
        window.location.href = '?page=owned_set_detail&id=' + res.ownedSetId;
      } else {
        errorEl.textContent = res.message || texts.errorRetry;
      }
    }).catch(function() {
      errorEl.textContent = texts.errorRetry;
    });
  }

  document.getElementById('owned-set-wizard-inventory-no').addEventListener('click', function() {
    finishWithoutInventory(document.getElementById('owned-set-wizard-step4-error'));
  });

  document.getElementById('owned-set-wizard-inventory-yes').addEventListener('click', function() {
    var errorEl = document.getElementById('owned-set-wizard-step4-error');
    errorEl.textContent = '';
    submitAddOwnedSet().then(function(res) {
      if (!res.success) {
        errorEl.textContent = res.message || texts.errorRetry;
        return;
      }
      createdOwnedSetId = res.ownedSetId;
      return fetch('?action=owned_set_missing_parts&owned_set_id=' + createdOwnedSetId, { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          initInventoryPages(data);
          totalSteps = INVENTORY_STEP;
          showStep(INVENTORY_STEP);
        });
    }).catch(function() {
      errorEl.textContent = texts.errorRetry;
    });
  });

  // The inventory step covers 4 categories in sequence (Bauteile,
  // Ersatzteile, Stickerbögen, Minifiguren) — each split further into one
  // page per distinct part number (parts/spares/stickers) or a single page
  // holding all of them (minifigs, which have no color variants to group
  // by). Flattened up front into one linear "pages" list so paging is just
  // a single index, no separate category/group nesting to juggle. Any
  // category the set doesn't have (e.g. no spares) contributes zero pages
  // and is silently skipped. state is keyed by category so Speichern can
  // route each entry to the right POST field names/backend columns; it
  // survives page changes (not just the currently-visible page), so paging
  // back and forth never loses an already-entered value.
  var pages = [];
  var state = { parts: {}, spares: {}, stickers: {}, minifigs: {} };
  var pageIndex = 0;
  var invList = document.getElementById('owned-set-wizard-parts-list');
  var invProgress = document.getElementById('owned-set-wizard-inventory-progress');
  var invBackBtn = document.getElementById('owned-set-wizard-inventory-back');
  var invNextBtn = document.getElementById('owned-set-wizard-inventory-next');

  function initInventoryPages(data) {
    pages = [];
    state = { parts: {}, spares: {}, stickers: {}, minifigs: {} };
    pageIndex = 0;

    var defs = [
      { category: 'parts', items: data.parts || [], label: texts.categoryParts, kind: 'part' },
      { category: 'spares', items: data.spares || [], label: texts.categorySpares, kind: 'part' },
      { category: 'stickers', items: data.stickers || [], label: texts.categoryStickers, kind: 'part' },
      { category: 'minifigs', items: data.minifigs || [], label: texts.categoryMinifigs, kind: 'minifig' }
    ];

    defs.forEach(function(def) {
      if (!def.items.length) {
        return;
      }
      var categoryPages;
      if (def.kind === 'part') {
        var indexByPartNum = {};
        categoryPages = [];
        def.items.forEach(function(item) {
          if (!(item.part_num in indexByPartNum)) {
            indexByPartNum[item.part_num] = categoryPages.length;
            categoryPages.push([]);
          }
          categoryPages[indexByPartNum[item.part_num]].push(item);
        });
      } else {
        categoryPages = [def.items];
      }
      categoryPages.forEach(function(items, i) {
        pages.push({ category: def.category, kind: def.kind, label: def.label, items: items, categoryIndex: i + 1, categoryTotal: categoryPages.length });
      });
    });

    renderPage();
  }

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

  function renderTile(item, category, kind) {
    var key = kind === 'minifig' ? String(item.minifig_id) : (item.part_id + ':' + item.color_id);
    if (!state[category][key]) {
      state[category][key] = { owned: item.actual_quantity, damaged: item.damaged_quantity };
    }
    var s = state[category][key];

    var tile = document.createElement('div');
    tile.className = 'owned-set-inventory-tile';

    var img = document.createElement('span');
    img.className = kind === 'minifig' ? 'minifig-card-image' : 'part-card-image';
    if (item.thumbnail) {
      img.innerHTML = '<img src="' + item.thumbnail + '" alt="">';
    }
    tile.appendChild(img);

    var num = document.createElement('span');
    num.className = kind === 'minifig' ? 'minifig-card-num' : 'part-card-num';
    num.textContent = kind === 'minifig' ? item.fig_num : item.part_num;
    tile.appendChild(num);

    var name = document.createElement('span');
    name.className = kind === 'minifig' ? 'minifig-card-name' : 'part-card-name';
    name.textContent = item.name + (item.color_name ? ' \\u00b7 ' + item.color_name : '');
    tile.appendChild(name);

    var inputsWrap = document.createElement('div');
    inputsWrap.className = 'owned-set-inventory-tile-inputs';

    var ownedLabel = document.createElement('label');
    ownedLabel.appendChild(document.createTextNode(texts.ownedLabel));
    var ownedStepper = buildStepper(0, item.nominal_quantity, s.owned);
    var ownedInput = ownedStepper.input;
    ownedLabel.appendChild(ownedStepper.wrap);
    inputsWrap.appendChild(ownedLabel);

    var damagedLabel = document.createElement('label');
    damagedLabel.appendChild(document.createTextNode(texts.damagedLabel));
    var damagedStepper = buildStepper(0, s.owned, s.damaged);
    var damagedInput = damagedStepper.input;
    damagedLabel.appendChild(damagedStepper.wrap);
    inputsWrap.appendChild(damagedLabel);

    tile.appendChild(inputsWrap);

    var summary = document.createElement('p');
    summary.className = 'owned-set-inventory-summary';
    tile.appendChild(summary);

    function updateSummary() {
      var owned = Math.max(0, Math.min(parseInt(ownedInput.value, 10) || 0, item.nominal_quantity));
      damagedInput.max = String(owned);
      var damaged = Math.max(0, Math.min(parseInt(damagedInput.value, 10) || 0, owned));
      var intact = owned - damaged;
      var missing = item.nominal_quantity - owned;
      summary.textContent = texts.inventorySummary
        .replace('{intact}', intact)
        .replace('{damaged}', damaged)
        .replace('{missing}', missing);
      state[category][key].owned = owned;
      state[category][key].damaged = damaged;
    }
    ownedInput.addEventListener('input', updateSummary);
    damagedInput.addEventListener('input', updateSummary);
    updateSummary();

    return tile;
  }

  function renderPage() {
    invList.innerHTML = '';
    if (pages.length === 0) {
      invProgress.textContent = '';
      invBackBtn.disabled = true;
      invNextBtn.disabled = true;
      return;
    }
    var page = pages[pageIndex];
    invProgress.textContent = page.categoryTotal > 1
      ? page.label + ' \\u2014 ' + texts.partProgress.replace('{current}', page.categoryIndex).replace('{total}', page.categoryTotal)
      : page.label;
    invBackBtn.disabled = pageIndex === 0;
    invNextBtn.disabled = pageIndex >= pages.length - 1;

    page.items.forEach(function(item) {
      invList.appendChild(renderTile(item, page.category, page.kind));
    });
  }

  invBackBtn.addEventListener('click', function() {
    if (pageIndex > 0) {
      pageIndex--;
      renderPage();
    }
  });
  invNextBtn.addEventListener('click', function() {
    if (pageIndex < pages.length - 1) {
      pageIndex++;
      renderPage();
    }
  });

  var inventoryFieldNames = {
    parts: ['owned', 'damaged'],
    spares: ['spare_owned', 'spare_damaged'],
    stickers: ['sticker_owned', 'sticker_damaged'],
    minifigs: ['minifig_owned', 'minifig_damaged']
  };

  document.getElementById('owned-set-wizard-finish').addEventListener('click', function() {
    var errorEl = document.getElementById('owned-set-wizard-step5-error');
    errorEl.textContent = '';
    var formData = new FormData();
    formData.set('action', 'save_owned_set_inventory');
    formData.set('owned_set_id', String(createdOwnedSetId));
    Object.keys(state).forEach(function(category) {
      var fieldNames = inventoryFieldNames[category];
      Object.keys(state[category]).forEach(function(key) {
        formData.set(fieldNames[0] + '[' + key + ']', String(state[category][key].owned));
        formData.set(fieldNames[1] + '[' + key + ']', String(state[category][key].damaged));
      });
    });
    fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success) {
          window.location.href = '?page=owned_set_detail&id=' + createdOwnedSetId;
        } else {
          errorEl.textContent = res.message || texts.errorRetry;
        }
      })
      .catch(function() {
        errorEl.textContent = texts.errorRetry;
      });
  });

  document.getElementById('owned-set-wizard-skip').addEventListener('click', function(e) {
    e.preventDefault();
    window.location.href = '?page=owned_set_detail&id=' + createdOwnedSetId;
  });
})();
</script>
SCRIPT;

    return $html;
}

/**
 * owned_set_detail's persistent inventory editor — one per tab (Inventar/
 * Ersatzteile/Stickerbögen), same grouped/paginated tile design as the
 * add-to-collection wizard's inventory step (see
 * renderAddOwnedSetWizardModal()'s doc comment for why the list is
 * paginated one part number at a time), just with the parts data already
 * known server-side (embedded as JSON) instead of fetched, and saving
 * reloads the page instead of redirecting, so the completeness table
 * further up the page reflects the new numbers immediately. $ownedField/
 * $damagedField select which pair of POST field names (and therefore which
 * storage_items columns, via applyOwnedSetInventory()/
 * applyOwnedSetSpareInventory()/applyOwnedSetStickerInventory() in
 * index.php's save_owned_set_inventory handler) this instance targets.
 *
 * The wizard's inventory step and this section are deliberately independent
 * (not a shared JS function) — same convention as every other
 * self-contained overlay in this codebase (renderLdrawRenderOverlay(),
 * renderPartDetailModal()), since these two contexts never render on the
 * same page.
 */
function renderOwnedSetInventorySection(array $ownedSet, array $parts, string $ownedField = 'owned', string $damagedField = 'damaged'): string
{
    if (empty($parts)) {
        return '<section class="card"><p>' . htmlspecialchars(t('set_detail_inventory_empty')) . '</p></section>';
    }
    $html = '';

    $html .= '<p class="owned-set-inventory-progress" id="owned-set-inventory-progress"></p>';
    $html .= '<div class="owned-set-inventory-tiles" id="owned-set-inventory-tiles"></div>';
    $html .= '<div class="owned-set-inventory-nav">';
    $html .= '<button type="button" id="owned-set-inventory-back">' . htmlspecialchars(t('owned_set_wizard_back')) . '</button>';
    $html .= '<button type="button" id="owned-set-inventory-next">' . htmlspecialchars(t('owned_set_wizard_next')) . '</button>';
    $html .= '<button type="button" id="owned-set-inventory-save">' . htmlspecialchars(t('owned_set_save_button')) . '</button>';
    $html .= '</div>';
    $html .= '<p class="owned-set-message" id="owned-set-inventory-message"></p>';

    $partsJson = json_encode($parts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    $labelsJson = json_encode([
        'errorRetry' => t('import_error_retry'),
        'ownedLabel' => t('owned_set_inventory_owned_label'),
        'damagedLabel' => t('owned_set_inventory_damaged_label'),
        'inventorySummary' => t('owned_set_inventory_summary'),
        'partProgress' => t('owned_set_inventory_part_progress'),
        'saved' => t('owned_set_updated_message'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $ownedFieldJson = json_encode($ownedField);
    $damagedFieldJson = json_encode($damagedField);

    $html .= <<<SCRIPT
<script>
(function(){
  var texts = $labelsJson;
  var ownedSetId = {$ownedSet['id']};
  var parts = $partsJson;
  var ownedField = $ownedFieldJson;
  var damagedField = $damagedFieldJson;

  var list = document.getElementById('owned-set-inventory-tiles');
  var progress = document.getElementById('owned-set-inventory-progress');
  var backBtn = document.getElementById('owned-set-inventory-back');
  var nextBtn = document.getElementById('owned-set-inventory-next');
  var saveBtn = document.getElementById('owned-set-inventory-save');
  var messageEl = document.getElementById('owned-set-inventory-message');
  if (!list || !progress || !backBtn || !nextBtn || !saveBtn || !messageEl) {
    return;
  }

  var groups = [];
  var state = {};
  var groupIndex = 0;
  var indexByPartNum = {};
  parts.forEach(function(part) {
    var key = part.part_id + ':' + part.color_id;
    state[key] = { owned: part.actual_quantity, damaged: part.damaged_quantity, nominal: part.nominal_quantity };
    if (!(part.part_num in indexByPartNum)) {
      indexByPartNum[part.part_num] = groups.length;
      groups.push([]);
    }
    groups[indexByPartNum[part.part_num]].push(part);
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

  function renderGroup() {
    list.innerHTML = '';
    if (groups.length === 0) {
      return;
    }
    progress.textContent = texts.partProgress.replace('{current}', groupIndex + 1).replace('{total}', groups.length);
    backBtn.disabled = groupIndex === 0;
    nextBtn.disabled = groupIndex >= groups.length - 1;

    groups[groupIndex].forEach(function(part) {
      var key = part.part_id + ':' + part.color_id;
      var s = state[key];

      var tile = document.createElement('div');
      tile.className = 'owned-set-inventory-tile';

      var img = document.createElement('span');
      img.className = 'part-card-image';
      if (part.thumbnail) {
        img.innerHTML = '<img src="' + part.thumbnail + '" alt="">';
      }
      tile.appendChild(img);

      var num = document.createElement('span');
      num.className = 'part-card-num';
      num.textContent = part.part_num;
      tile.appendChild(num);

      var name = document.createElement('span');
      name.className = 'part-card-name';
      name.textContent = part.name + (part.color_name ? ' \\u00b7 ' + part.color_name : '');
      tile.appendChild(name);

      var inputsWrap = document.createElement('div');
      inputsWrap.className = 'owned-set-inventory-tile-inputs';

      var ownedLabel = document.createElement('label');
      ownedLabel.appendChild(document.createTextNode(texts.ownedLabel));
      var ownedStepper = buildStepper(0, part.nominal_quantity, s.owned);
      var ownedInput = ownedStepper.input;
      ownedLabel.appendChild(ownedStepper.wrap);
      inputsWrap.appendChild(ownedLabel);

      var damagedLabel = document.createElement('label');
      damagedLabel.appendChild(document.createTextNode(texts.damagedLabel));
      var damagedStepper = buildStepper(0, s.owned, s.damaged);
      var damagedInput = damagedStepper.input;
      damagedLabel.appendChild(damagedStepper.wrap);
      inputsWrap.appendChild(damagedLabel);

      tile.appendChild(inputsWrap);

      var summary = document.createElement('p');
      summary.className = 'owned-set-inventory-summary';
      tile.appendChild(summary);

      function updateSummary() {
        var owned = Math.max(0, Math.min(parseInt(ownedInput.value, 10) || 0, part.nominal_quantity));
        damagedInput.max = String(owned);
        var damaged = Math.max(0, Math.min(parseInt(damagedInput.value, 10) || 0, owned));
        var intact = owned - damaged;
        var missing = part.nominal_quantity - owned;
        summary.textContent = texts.inventorySummary
          .replace('{intact}', intact)
          .replace('{damaged}', damaged)
          .replace('{missing}', missing);
        state[key].owned = owned;
        state[key].damaged = damaged;
      }
      ownedInput.addEventListener('input', updateSummary);
      damagedInput.addEventListener('input', updateSummary);
      updateSummary();

      list.appendChild(tile);
    });
  }

  backBtn.addEventListener('click', function() {
    if (groupIndex > 0) {
      groupIndex--;
      renderGroup();
    }
  });
  nextBtn.addEventListener('click', function() {
    if (groupIndex < groups.length - 1) {
      groupIndex++;
      renderGroup();
    }
  });

  saveBtn.addEventListener('click', function() {
    messageEl.textContent = '';
    var formData = new FormData();
    formData.set('action', 'save_owned_set_inventory');
    formData.set('owned_set_id', String(ownedSetId));
    Object.keys(state).forEach(function(key) {
      formData.set(ownedField + '[' + key + ']', String(state[key].owned));
      formData.set(damagedField + '[' + key + ']', String(state[key].damaged));
    });
    fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success) {
          window.location.reload();
        } else {
          messageEl.textContent = res.message || texts.errorRetry;
        }
      })
      .catch(function() {
        messageEl.textContent = texts.errorRetry;
      });
  });

  renderGroup();
})();
</script>
SCRIPT;

    return $html;
}

/**
 * owned_set_detail's Minifiguren tab — same owned/damaged tile editing as
 * renderOwnedSetInventorySection(), but minifigs have no color variants to
 * group/paginate by (unlike parts, one minifig type is already as granular
 * as it gets), so this is just one flat grid of tiles, no Zurück/Weiter.
 */
function renderOwnedSetMinifigInventorySection(array $ownedSet, array $minifigs): string
{
    if (empty($minifigs)) {
        return '<section class="card"><p>' . htmlspecialchars(t('set_detail_minifigs_empty')) . '</p></section>';
    }

    $html = '<div class="owned-set-inventory-tiles" id="owned-set-minifig-tiles"></div>';
    $html .= '<div class="owned-set-inventory-nav"><button type="button" id="owned-set-minifig-save">' . htmlspecialchars(t('owned_set_save_button')) . '</button></div>';
    $html .= '<p class="owned-set-message" id="owned-set-minifig-message"></p>';

    $figsJson = json_encode($minifigs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    $labelsJson = json_encode([
        'errorRetry' => t('import_error_retry'),
        'ownedLabel' => t('owned_set_inventory_owned_label'),
        'damagedLabel' => t('owned_set_inventory_damaged_label'),
        'inventorySummary' => t('owned_set_inventory_summary'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html .= <<<SCRIPT
<script>
(function(){
  var texts = $labelsJson;
  var ownedSetId = {$ownedSet['id']};
  var figs = $figsJson;

  var list = document.getElementById('owned-set-minifig-tiles');
  var saveBtn = document.getElementById('owned-set-minifig-save');
  var messageEl = document.getElementById('owned-set-minifig-message');
  if (!list || !saveBtn || !messageEl) {
    return;
  }

  var state = {};

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

  figs.forEach(function(fig) {
    var key = String(fig.minifig_id);
    state[key] = { owned: fig.actual_quantity, damaged: fig.damaged_quantity };

    var tile = document.createElement('div');
    tile.className = 'owned-set-inventory-tile';

    var img = document.createElement('span');
    img.className = 'minifig-card-image';
    if (fig.thumbnail) {
      img.innerHTML = '<img src="' + fig.thumbnail + '" alt="">';
    }
    tile.appendChild(img);

    var num = document.createElement('span');
    num.className = 'minifig-card-num';
    num.textContent = fig.fig_num;
    tile.appendChild(num);

    var name = document.createElement('span');
    name.className = 'minifig-card-name';
    name.textContent = fig.name;
    tile.appendChild(name);

    var inputsWrap = document.createElement('div');
    inputsWrap.className = 'owned-set-inventory-tile-inputs';

    var ownedLabel = document.createElement('label');
    ownedLabel.appendChild(document.createTextNode(texts.ownedLabel));
    var ownedStepper = buildStepper(0, fig.nominal_quantity, state[key].owned);
    var ownedInput = ownedStepper.input;
    ownedLabel.appendChild(ownedStepper.wrap);
    inputsWrap.appendChild(ownedLabel);

    var damagedLabel = document.createElement('label');
    damagedLabel.appendChild(document.createTextNode(texts.damagedLabel));
    var damagedStepper = buildStepper(0, state[key].owned, state[key].damaged);
    var damagedInput = damagedStepper.input;
    damagedLabel.appendChild(damagedStepper.wrap);
    inputsWrap.appendChild(damagedLabel);

    tile.appendChild(inputsWrap);

    var summary = document.createElement('p');
    summary.className = 'owned-set-inventory-summary';
    tile.appendChild(summary);

    function updateSummary() {
      var owned = Math.max(0, Math.min(parseInt(ownedInput.value, 10) || 0, fig.nominal_quantity));
      damagedInput.max = String(owned);
      var damaged = Math.max(0, Math.min(parseInt(damagedInput.value, 10) || 0, owned));
      var intact = owned - damaged;
      var missing = fig.nominal_quantity - owned;
      summary.textContent = texts.inventorySummary
        .replace('{intact}', intact)
        .replace('{damaged}', damaged)
        .replace('{missing}', missing);
      state[key].owned = owned;
      state[key].damaged = damaged;
    }
    ownedInput.addEventListener('input', updateSummary);
    damagedInput.addEventListener('input', updateSummary);
    updateSummary();

    list.appendChild(tile);
  });

  saveBtn.addEventListener('click', function() {
    messageEl.textContent = '';
    var formData = new FormData();
    formData.set('action', 'save_owned_set_inventory');
    formData.set('owned_set_id', String(ownedSetId));
    Object.keys(state).forEach(function(key) {
      formData.set('minifig_owned[' + key + ']', String(state[key].owned));
      formData.set('minifig_damaged[' + key + ']', String(state[key].damaged));
    });
    fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success) {
          window.location.reload();
        } else {
          messageEl.textContent = res.message || texts.errorRetry;
        }
      })
      .catch(function() {
        messageEl.textContent = texts.errorRetry;
      });
  });
})();
</script>
SCRIPT;

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
