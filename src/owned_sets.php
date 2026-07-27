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
 * @return array{id:int, set_id:int, location_id:int, condition_type:string, has_instructions:bool, has_box:bool, box_complete:bool, notes:?string, instructions_notes:?string, box_notes:?string, box_complete_notes:?string, created_at:string, rebrickable_set_num:string, name:string, thumbnail:?string}|null
 */
function getOwnedSetById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT os.id, os.set_id, os.location_id, os.condition_type, os.has_instructions, os.has_box, os.box_complete,
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
 * Per-part-line nominal vs. actual quantities, for the instance's inventory
 * editor. Parts with no resolvable color are left out — storage_items (like
 * the rest of the storage module) can't represent a colorless part.
 *
 * damaged_quantity is a subset of actual_quantity (still "owned", not
 * "missing" — see setOwnedSetPartInventory()'s doc comment).
 *
 * @return array<int, array{part_id:int, part_num:string, name:string, color_id:int, color_name:?string, color_rgb:?string, thumbnail:?string, nominal_quantity:int, actual_quantity:int, damaged_quantity:int}>
 */
function getOwnedSetPartsWithStatus(PDO $pdo, array $ownedSet, string $locale = 'en'): array
{
    $inventoryId = getSetInventoryId($pdo, $ownedSet['rebrickable_set_num']);
    if ($inventoryId === null) {
        return [];
    }
    $nominalItems = getSetPartsList($pdo, $inventoryId, false, $locale);

    $actualStmt = $pdo->prepare('SELECT part_id, color_id, quantity, damaged_quantity FROM storage_items WHERE location_id = ?');
    $actualStmt->execute([$ownedSet['location_id']]);
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
            'damaged_quantity' => $damagedByKey[$key] ?? 0,
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
    ?int $userId,
    ?string $instructionsNotes = null,
    ?string $boxNotes = null,
    ?string $boxCompleteNotes = null
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

    $instanceNumber = getNextOwnedSetInstanceNumber($pdo, $setId);
    $locationName = $set['name'] . ' (' . $set['rebrickable_set_num'] . ') #' . $instanceNumber;
    $locationId = createStorageLocation($parentLocationId, $locationName, 'owned_set');

    $stmt = $pdo->prepare(
        'INSERT INTO owned_sets (set_id, location_id, condition_type, has_instructions, has_box, box_complete, notes, instructions_notes, box_notes, box_complete_notes, added_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $setId,
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

    materializeOwnedSetStock($pdo, $set['rebrickable_set_num'], $locationId, $conditionType, $userId);

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
 * The "Set zur Sammlung hinzufügen" assistant: a 4-step wizard (location,
 * condition/box details, general notes, inventory prompt) that, on "Ja" at
 * step 4, extends to a 5th step showing the newly created instance's
 * missing-parts checklist right in the same modal instead of redirecting
 * first. Self-contained (own markup + own <script>, reuses the generic
 * .modal-overlay/.modal-box shell already used by renderPartDetailModal())
 * so the caller just embeds the returned HTML — same pattern as
 * renderLdrawRenderOverlay() in src/ldraw.php.
 *
 * Nothing is persisted until step 4 is answered (Ja or Nein): both answers
 * trigger the same add_owned_set AJAX call, "Nein" then redirects
 * immediately, "Ja" stays in the modal for the inventory step. Closing the
 * modal before that point discards everything client-side — no draft state
 * on the server.
 */
function renderAddOwnedSetWizardModal(int $setId): string
{
    $html = '<div class="modal-overlay" id="add-owned-set-modal" style="display:none;">';
    $html .= '<div class="modal-box owned-set-wizard-box">';
    $html .= '<button type="button" class="modal-close" id="add-owned-set-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<h2>' . htmlspecialchars(t('owned_set_wizard_title')) . '</h2>';
    $html .= '<p class="owned-set-wizard-progress" id="owned-set-wizard-progress"></p>';

    $html .= '<div class="owned-set-wizard-step" id="owned-set-wizard-step-1" data-step="1">';
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
    $html .= '<div class="owned-set-wizard-nav"><button type="button" class="owned-set-wizard-next" data-next="2">' . htmlspecialchars(t('owned_set_wizard_next')) . '</button></div>';
    $html .= '</div>';

    $html .= '<div class="owned-set-wizard-step" id="owned-set-wizard-step-2" data-step="2" style="display:none;">';
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

    $html .= '<div class="owned-set-wizard-nav"><button type="button" class="owned-set-wizard-back" data-back="1">' . htmlspecialchars(t('owned_set_wizard_back')) . '</button><button type="button" class="owned-set-wizard-next" data-next="3">' . htmlspecialchars(t('owned_set_wizard_next')) . '</button></div>';
    $html .= '</div>';

    $html .= '<div class="owned-set-wizard-step" id="owned-set-wizard-step-3" data-step="3" style="display:none;">';
    $html .= '<h3>' . htmlspecialchars(t('owned_set_wizard_step3_heading')) . '</h3>';
    $html .= '<label>' . htmlspecialchars(t('owned_set_notes_label')) . '<textarea id="owned-set-wizard-notes" rows="4"></textarea></label>';
    $html .= '<p class="owned-set-wizard-error" id="owned-set-wizard-step3-error"></p>';
    $html .= '<div class="owned-set-wizard-nav"><button type="button" class="owned-set-wizard-back" data-back="2">' . htmlspecialchars(t('owned_set_wizard_back')) . '</button><button type="button" class="owned-set-wizard-next" data-next="4">' . htmlspecialchars(t('owned_set_wizard_next')) . '</button></div>';
    $html .= '</div>';

    $html .= '<div class="owned-set-wizard-step" id="owned-set-wizard-step-4" data-step="4" style="display:none;">';
    $html .= '<h3>' . htmlspecialchars(t('owned_set_wizard_step4_heading')) . '</h3>';
    $html .= '<p>' . htmlspecialchars(t('owned_set_wizard_inventory_question')) . '</p>';
    $html .= '<p class="owned-set-wizard-error" id="owned-set-wizard-step4-error"></p>';
    $html .= '<div class="owned-set-wizard-nav"><button type="button" class="owned-set-wizard-back" data-back="3">' . htmlspecialchars(t('owned_set_wizard_back')) . '</button><button type="button" id="owned-set-wizard-inventory-no">' . htmlspecialchars(t('owned_set_wizard_no')) . '</button><button type="button" id="owned-set-wizard-inventory-yes">' . htmlspecialchars(t('owned_set_wizard_yes')) . '</button></div>';
    $html .= '</div>';

    $html .= '<div class="owned-set-wizard-step" id="owned-set-wizard-step-5" data-step="5" style="display:none;">';
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
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html .= <<<SCRIPT
<script>
(function(){
  var texts = $labelsJson;
  var setId = $setId;
  var openBtn = document.getElementById('add-owned-set-open');
  var modal = document.getElementById('add-owned-set-modal');
  var closeBtn = document.getElementById('add-owned-set-modal-close');
  var progress = document.getElementById('owned-set-wizard-progress');
  if (!modal || !closeBtn || !progress) {
    return;
  }

  var steps = Array.prototype.slice.call(modal.querySelectorAll('.owned-set-wizard-step'));
  var totalSteps = 4;
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
    totalSteps = 4;
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
    inventoryGroups = [];
    inventoryState = {};
    inventoryGroupIndex = 0;
    document.getElementById('owned-set-wizard-parts-list').innerHTML = '';
    document.getElementById('owned-set-wizard-inventory-progress').textContent = '';
    showStep(1);
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
      if (next === 2) {
        var location = getSelectedLocationId();
        var errorEl = document.getElementById('owned-set-wizard-step1-error');
        if (!location) {
          errorEl.textContent = texts.locationRequired;
          return;
        }
        errorEl.textContent = '';
      }
      if (next === 4) {
        // A still-sealed set can't be inventoried (nothing can be verified
        // without opening it, which is itself the new -> used transition —
        // see openOwnedSet() in src/owned_sets.php), so step 4 is skipped
        // entirely and the wizard finishes right here.
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
          initInventoryTiles(data.parts || []);
          totalSteps = 5;
          showStep(5);
        });
    }).catch(function() {
      errorEl.textContent = texts.errorRetry;
    });
  });

  // Groups by part_num — one part number's color variants shown per page
  // (a long flat list of every part+color combo doesn't fit on screen for
  // sets with many distinct parts). inventoryState survives page changes
  // (and is what Speichern submits, not just the currently-visible page),
  // so paging back and forth never loses an already-entered value.
  var inventoryGroups = [];
  var inventoryState = {};
  var inventoryGroupIndex = 0;
  var invList = document.getElementById('owned-set-wizard-parts-list');
  var invProgress = document.getElementById('owned-set-wizard-inventory-progress');
  var invBackBtn = document.getElementById('owned-set-wizard-inventory-back');
  var invNextBtn = document.getElementById('owned-set-wizard-inventory-next');

  function initInventoryTiles(parts) {
    inventoryGroups = [];
    inventoryState = {};
    inventoryGroupIndex = 0;
    var indexByPartNum = {};
    parts.forEach(function(part) {
      var key = part.part_id + ':' + part.color_id;
      inventoryState[key] = { owned: part.actual_quantity, damaged: part.damaged_quantity, nominal: part.nominal_quantity };
      if (!(part.part_num in indexByPartNum)) {
        indexByPartNum[part.part_num] = inventoryGroups.length;
        inventoryGroups.push([]);
      }
      inventoryGroups[indexByPartNum[part.part_num]].push(part);
    });
    renderInventoryGroup();
  }

  function renderInventoryGroup() {
    invList.innerHTML = '';
    if (inventoryGroups.length === 0) {
      invProgress.textContent = '';
      invBackBtn.disabled = true;
      invNextBtn.disabled = true;
      return;
    }
    invProgress.textContent = texts.partProgress.replace('{current}', inventoryGroupIndex + 1).replace('{total}', inventoryGroups.length);
    invBackBtn.disabled = inventoryGroupIndex === 0;
    invNextBtn.disabled = inventoryGroupIndex >= inventoryGroups.length - 1;

    inventoryGroups[inventoryGroupIndex].forEach(function(part) {
      var key = part.part_id + ':' + part.color_id;
      var s = inventoryState[key];

      var tile = document.createElement('div');
      tile.className = 'part-card owned-set-inventory-tile';

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
      var ownedInput = document.createElement('input');
      ownedInput.type = 'number';
      ownedInput.min = '0';
      ownedInput.max = String(part.nominal_quantity);
      ownedInput.value = String(s.owned);
      ownedLabel.appendChild(ownedInput);
      inputsWrap.appendChild(ownedLabel);

      var damagedLabel = document.createElement('label');
      damagedLabel.appendChild(document.createTextNode(texts.damagedLabel));
      var damagedInput = document.createElement('input');
      damagedInput.type = 'number';
      damagedInput.min = '0';
      damagedInput.max = String(s.owned);
      damagedInput.value = String(s.damaged);
      damagedLabel.appendChild(damagedInput);
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
        inventoryState[key].owned = owned;
        inventoryState[key].damaged = damaged;
      }
      ownedInput.addEventListener('input', updateSummary);
      damagedInput.addEventListener('input', updateSummary);
      updateSummary();

      invList.appendChild(tile);
    });
  }

  invBackBtn.addEventListener('click', function() {
    if (inventoryGroupIndex > 0) {
      inventoryGroupIndex--;
      renderInventoryGroup();
    }
  });
  invNextBtn.addEventListener('click', function() {
    if (inventoryGroupIndex < inventoryGroups.length - 1) {
      inventoryGroupIndex++;
      renderInventoryGroup();
    }
  });

  document.getElementById('owned-set-wizard-finish').addEventListener('click', function() {
    var errorEl = document.getElementById('owned-set-wizard-step5-error');
    errorEl.textContent = '';
    var formData = new FormData();
    formData.set('action', 'save_owned_set_inventory');
    formData.set('owned_set_id', String(createdOwnedSetId));
    Object.keys(inventoryState).forEach(function(key) {
      formData.set('owned[' + key + ']', String(inventoryState[key].owned));
      formData.set('damaged[' + key + ']', String(inventoryState[key].damaged));
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
 * owned_set_detail's persistent inventory editor — same grouped/paginated
 * tile design as the add-to-collection wizard's step 5 (see
 * renderAddOwnedSetWizardModal()'s doc comment for why the list is
 * paginated one part number at a time), just with the parts data already
 * known server-side (embedded as JSON) instead of fetched, and saving
 * reloads the page instead of redirecting, so the completeness/location
 * table further up the page reflects the new numbers immediately.
 *
 * The two implementations are deliberately independent (not a shared JS
 * function) — same convention as every other self-contained overlay in
 * this codebase (renderLdrawRenderOverlay(), renderPartDetailModal(), the
 * wizard itself), since these two contexts never render on the same page.
 */
function renderOwnedSetInventorySection(array $ownedSet, array $parts): string
{
    $html = '<h2>' . htmlspecialchars(t('owned_set_missing_parts_heading')) . '</h2>';

    if (empty($parts)) {
        $html .= '<section class="card"><p>' . htmlspecialchars(t('set_detail_inventory_empty')) . '</p></section>';
        return $html;
    }

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

    $html .= <<<SCRIPT
<script>
(function(){
  var texts = $labelsJson;
  var ownedSetId = {$ownedSet['id']};
  var parts = $partsJson;

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
      tile.className = 'part-card owned-set-inventory-tile';

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
      var ownedInput = document.createElement('input');
      ownedInput.type = 'number';
      ownedInput.min = '0';
      ownedInput.max = String(part.nominal_quantity);
      ownedInput.value = String(s.owned);
      ownedLabel.appendChild(ownedInput);
      inputsWrap.appendChild(ownedLabel);

      var damagedLabel = document.createElement('label');
      damagedLabel.appendChild(document.createTextNode(texts.damagedLabel));
      var damagedInput = document.createElement('input');
      damagedInput.type = 'number';
      damagedInput.min = '0';
      damagedInput.max = String(s.owned);
      damagedInput.value = String(s.damaged);
      damagedLabel.appendChild(damagedInput);
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
      formData.set('owned[' + key + ']', String(state[key].owned));
      formData.set('damaged[' + key + ']', String(state[key].damaged));
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
