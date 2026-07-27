<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/sets.php';
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
 * @return array{id:int, set_id:int, location_id:int, condition_type:string, has_instructions:bool, has_box:bool, box_complete:bool, notes:?string, created_at:string, rebrickable_set_num:string, name:string, thumbnail:?string}|null
 */
function getOwnedSetById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT os.id, os.set_id, os.location_id, os.condition_type, os.has_instructions, os.has_box, os.box_complete, os.notes, os.created_at,
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
    $row['location_id'] = (int) $row['location_id'];
    $row['has_instructions'] = (bool) $row['has_instructions'];
    $row['has_box'] = (bool) $row['has_box'];
    $row['box_complete'] = (bool) $row['box_complete'];
    return $row;
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
    $inventoryId = getSetInventoryId($pdo, $ownedSet['rebrickable_set_num']);
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
 * Per-part-line nominal vs. actual quantities, for the instance detail
 * page's "fehlende Teile" editor. Parts with no resolvable color are left
 * out — storage_items (like the rest of the storage module) can't
 * represent a colorless part.
 *
 * @return array<int, array{part_id:int, part_num:string, name:string, color_id:int, color_name:?string, color_rgb:?string, thumbnail:?string, nominal_quantity:int, actual_quantity:int}>
 */
function getOwnedSetPartsWithStatus(PDO $pdo, array $ownedSet, string $locale = 'en'): array
{
    $inventoryId = getSetInventoryId($pdo, $ownedSet['rebrickable_set_num']);
    if ($inventoryId === null) {
        return [];
    }
    $nominalItems = getSetPartsList($pdo, $inventoryId, false, $locale);

    $actualStmt = $pdo->prepare('SELECT part_id, color_id, quantity FROM storage_items WHERE location_id = ?');
    $actualStmt->execute([$ownedSet['location_id']]);
    $actualByKey = [];
    foreach ($actualStmt->fetchAll() as $row) {
        $actualByKey[$row['part_id'] . ':' . $row['color_id']] = (int) $row['quantity'];
    }

    $result = [];
    foreach ($nominalItems as $item) {
        if ($item['color_id'] === null) {
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
            'actual_quantity' => $actualByKey[$key] ?? $item['quantity'],
        ];
    }
    return $result;
}

/**
 * Copies a set's current (non-spare) inventory into storage_items at
 * $locationId, one addStorageStock() call per distinct part+color — the
 * normal audit-logged path, just run in a loop, so a newly added set's
 * parts show up in the loose-parts stock system exactly like any other
 * stock addition would. Colorless parts are skipped (see
 * getOwnedSetPartsWithStatus()'s doc comment — not a storage_items
 * limitation specific to this feature).
 */
function materializeOwnedSetStock(PDO $pdo, string $rebrickableSetNum, int $locationId, string $conditionType, ?int $userId): void
{
    $inventoryId = getSetInventoryId($pdo, $rebrickableSetNum);
    if ($inventoryId === null) {
        return;
    }
    $items = getSetPartsList($pdo, $inventoryId, false, 'en');
    foreach ($items as $item) {
        if ($item['color_id'] === null || $item['quantity'] <= 0) {
            continue;
        }
        addStorageStock($locationId, $item['part_id'], $item['color_id'], $conditionType, $item['quantity'], $userId);
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
    ?int $userId
): int {
    $set = getSetById($pdo, $setId);
    if ($set === null) {
        throw new RuntimeException('Set nicht gefunden.');
    }

    $instanceNumber = getNextOwnedSetInstanceNumber($pdo, $setId);
    $locationName = $set['name'] . ' (' . $set['rebrickable_set_num'] . ') #' . $instanceNumber;
    $locationId = createStorageLocation($parentLocationId, $locationName, 'owned_set');

    $stmt = $pdo->prepare(
        'INSERT INTO owned_sets (set_id, location_id, condition_type, has_instructions, has_box, box_complete, notes, added_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $setId,
        $locationId,
        $conditionType,
        $hasInstructions ? 1 : 0,
        $hasBox ? 1 : 0,
        $boxComplete ? 1 : 0,
        $notes,
        $userId,
    ]);
    $ownedSetId = (int) $pdo->lastInsertId();

    materializeOwnedSetStock($pdo, $set['rebrickable_set_num'], $locationId, $conditionType, $userId);

    return $ownedSetId;
}

function updateOwnedSet(PDO $pdo, int $ownedSetId, string $conditionType, bool $hasInstructions, bool $hasBox, bool $boxComplete, ?string $notes): void
{
    $stmt = $pdo->prepare(
        'UPDATE owned_sets SET condition_type = ?, has_instructions = ?, has_box = ?, box_complete = ?, notes = ? WHERE id = ?'
    );
    $stmt->execute([$conditionType, $hasInstructions ? 1 : 0, $hasBox ? 1 : 0, $boxComplete ? 1 : 0, $notes, $ownedSetId]);
}

/**
 * Records that $missingQuantity of $partId/$colorId is missing from this
 * instance — i.e. sets the actual stock to (nominal - missingQuantity).
 * Needs the nominal quantity as input (the caller already has it from
 * getOwnedSetPartsWithStatus(), no need to re-derive it here).
 */
function setOwnedSetPartMissing(PDO $pdo, array $ownedSet, int $partId, int $colorId, int $nominalQuantity, int $missingQuantity, ?int $userId): void
{
    $missingQuantity = max(0, min($missingQuantity, $nominalQuantity));
    $actualQuantity = $nominalQuantity - $missingQuantity;
    setStorageItemQuantity($ownedSet['location_id'], $partId, $colorId, $ownedSet['condition_type'], $actualQuantity, $userId);
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
