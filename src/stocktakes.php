<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/owned_sets.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/stats.php';

/**
 * Inventur (re-count) domain logic for owned sets and flagged storage
 * locations — deliberately its own tables (stocktakes/stocktake_items,
 * migration 50) rather than reusing pick_lists/pick_list_items: a stocktake
 * never physically relocates stock (no move_out/move_in pair via
 * setStorageItemQuantity()/addStorageStock()), it only corrects the quantity
 * already sitting at the same location. What IS reused from the Pickliste
 * design is the pattern: a snapshot taken once at start, a persisted
 * per-item confirmed flag so a session survives being resumed later/on
 * another device, and a single atomic "confirm" write path
 * (confirmStocktakeItem(), mirrors pickItem() in src/pick_lists.php).
 *
 * Starting a stocktake immediately zeroes the real quantity for every item
 * in scope (per explicit requirement) — anything never re-confirmed during
 * the session genuinely reads as missing everywhere else in the app while
 * the session is active, not just inside this feature. cancelStocktake()
 * exists as the safety net: it restores every still-unconfirmed item back to
 * its previous_actual_quantity (not to nominal_quantity — a set that already
 * had parts missing before the stocktake must cancel back to that same
 * partial state, not silently top back up to "complete").
 *
 * v1 scope, deliberately: owned-set stocktakes cover regular parts, sticker
 * parts, and minifigs (all mengenbasiert, owned_set_minifigs has no
 * per-instance rows) but not spares (never counted toward completeness
 * anywhere else in the app either). Location stocktakes cover parts only —
 * loose minifigs at a location are individual minifig_storage_items
 * instances, not a "Soll/Ist" quantity, and don't fit this model.
 */

function getStocktake(PDO $pdo, int $stocktakeId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM stocktakes WHERE id = ?');
    $stmt->execute([$stocktakeId]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

function getActiveStocktakeForOwnedSet(PDO $pdo, int $ownedSetId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM stocktakes WHERE owned_set_id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$ownedSetId]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

function getActiveStocktakeForLocation(PDO $pdo, int $locationId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM stocktakes WHERE location_id = ? AND source_type = 'location' AND status = 'active' LIMIT 1");
    $stmt->execute([$locationId]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

/** @return array<int, array<string,mixed>> */
function getStocktakesForUser(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT * FROM stocktakes WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Display label for a stocktake row — the owned set's own catalog label, or
 * the flagged location's full path. Powers the /pick/ app's overview list
 * (src/stocktake_pages.php).
 */
function getStocktakeLabel(PDO $pdo, array $stocktake): string
{
    if ($stocktake['source_type'] === 'owned_set') {
        $stmt = $pdo->prepare(
            'SELECT s.rebrickable_set_num, s.name FROM owned_sets os INNER JOIN sets s ON s.id = os.set_id WHERE os.id = ?'
        );
        $stmt->execute([$stocktake['owned_set_id']]);
        $row = $stmt->fetch();
        return $row !== false ? $row['rebrickable_set_num'] . ' — ' . $row['name'] : '?';
    }
    return getStorageLocationPath((int) $stocktake['location_id']);
}

/**
 * @return array{total:int, confirmed:int}
 */
function getStocktakeProgress(PDO $pdo, int $stocktakeId): array
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total, SUM(CASE WHEN confirmed_at IS NOT NULL THEN 1 ELSE 0 END) AS confirmed
         FROM stocktake_items WHERE stocktake_id = ?'
    );
    $stmt->execute([$stocktakeId]);
    $row = $stmt->fetch();
    return [
        'total' => $row !== false ? (int) $row['total'] : 0,
        'confirmed' => $row !== false ? (int) $row['confirmed'] : 0,
    ];
}

/**
 * Joins one stocktake_items row to its catalog display info (part/color or
 * minifig name, plus the physical location path for a location-scoped item —
 * a recursive stocktake can span several sub-locations, so this is what
 * tells the user where to actually go count). Deliberately no photo
 * thumbnail here: resolving a color-correct image needs the
 * colors.id -> Rebrickable color_id -> part_color_images lookup chain
 * (see getCachedPartColorImage(), src/part_images.php) for comparatively
 * little benefit during a quick count — part number + name + a colour swatch
 * (color_rgb) already identifies a part unambiguously while walking a shelf.
 */
function hydrateStocktakeItemDisplay(PDO $pdo, array $item): array
{
    $display = [
        'id' => (int) $item['id'],
        'itemType' => $item['item_type'],
        'nominalQuantity' => (int) $item['nominal_quantity'],
        'confirmedQuantity' => $item['confirmed_quantity'] !== null ? (int) $item['confirmed_quantity'] : null,
        'confirmed' => $item['confirmed_at'] !== null,
        'label' => '',
        'colorName' => null,
        'colorRgb' => null,
        'locationPath' => $item['location_id'] !== null ? getStorageLocationPath((int) $item['location_id']) : null,
    ];

    if ($item['item_type'] === 'minifig') {
        $stmt = $pdo->prepare('SELECT fig_num, name FROM minifigs WHERE id = ?');
        $stmt->execute([$item['minifig_id']]);
        $row = $stmt->fetch();
        if ($row !== false) {
            $display['label'] = $row['fig_num'] . ' ' . $row['name'];
        }
        return $display;
    }

    $stmt = $pdo->prepare(
        'SELECT p.part_num, p.name, c.name AS color_name, c.rgb AS color_rgb
         FROM parts p LEFT JOIN colors c ON c.id = ?
         WHERE p.id = ?'
    );
    $stmt->execute([$item['color_id'], $item['part_id']]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $display['label'] = $row['part_num'] . ' ' . $row['name'];
        $display['colorName'] = $row['color_name'];
        $display['colorRgb'] = $row['color_rgb'];
    }
    return $display;
}

function getNextStocktakeItem(PDO $pdo, int $stocktakeId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM stocktake_items WHERE stocktake_id = ? AND confirmed_at IS NULL ORDER BY id LIMIT 1');
    $stmt->execute([$stocktakeId]);
    $row = $stmt->fetch();
    return $row !== false ? hydrateStocktakeItemDisplay($pdo, $row) : null;
}

function getStocktakeItemById(PDO $pdo, int $stocktakeItemId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM stocktake_items WHERE id = ?');
    $stmt->execute([$stocktakeItemId]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

/**
 * Re-fetches one specific item for display regardless of its confirmed
 * state — unlike getNextStocktakeItem(), which only ever surfaces
 * unconfirmed ones. Powers the guided modal's "Zurück" button: stepping back
 * to an already-confirmed item to correct it.
 */
function getStocktakeItemDisplayById(PDO $pdo, int $stocktakeId, int $stocktakeItemId): ?array
{
    $item = getStocktakeItemById($pdo, $stocktakeItemId);
    if ($item === null || (int) $item['stocktake_id'] !== $stocktakeId) {
        return null;
    }
    return hydrateStocktakeItemDisplay($pdo, $item);
}

/**
 * Every position of a session, hydrated for display, confirmed and
 * unconfirmed alike — unlike getNextStocktakeItem(), which only ever
 * surfaces the next unconfirmed one. Powers the /pick/ app's swipe deck
 * (src/stocktake_pages.php), where — unlike the desktop modal's strictly
 * linear walkthrough — every card is shown at once so the user can swipe
 * freely while counting a shelf, with already-confirmed cards just marked
 * done rather than removed.
 */
function getStocktakeItemsWithDisplay(PDO $pdo, int $stocktakeId): array
{
    $stmt = $pdo->prepare('SELECT * FROM stocktake_items WHERE stocktake_id = ? ORDER BY id');
    $stmt->execute([$stocktakeId]);
    $result = [];
    foreach ($stmt->fetchAll() as $item) {
        $result[] = hydrateStocktakeItemDisplay($pdo, $item);
    }
    return $result;
}

/**
 * Locations currently "zur Inventur vorgemerkt" (storage_locations.
 * flagged_for_stocktake_at IS NOT NULL), with their full path and whether a
 * session is already running for them — feeds the /pick/ app's "Inventur
 * starten" location tab (src/stocktake_pages.php), which deliberately shows
 * only these, not a full location tree/search (vormerken itself stays a
 * location-Explorer-only action).
 *
 * @return array<int, array{id:int, path:string, activeStocktakeId:?int}>
 */
function getFlaggedStocktakeLocations(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id FROM storage_locations WHERE flagged_for_stocktake_at IS NOT NULL ORDER BY flagged_for_stocktake_at');
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $locationId) {
        $locationId = (int) $locationId;
        $active = getActiveStocktakeForLocation($pdo, $locationId);
        $result[] = [
            'id' => $locationId,
            'path' => getStorageLocationPath($locationId),
            'activeStocktakeId' => $active !== null ? (int) $active['id'] : null,
        ];
    }
    return $result;
}

/**
 * Owned sets currently "zur Inventurliste hinzugefügt" (owned_sets.
 * flagged_for_stocktake_at IS NOT NULL) — feeds the /pick/ app's "Inventur
 * starten" set tab, mirroring getFlaggedStocktakeLocations() exactly. Per
 * explicit follow-up request, the set-detail "Inventur starten" button now
 * offers a choice between doing it right there on the PC or adding it to
 * this list instead — worked off entirely from /pick/, same as a flagged
 * location. Excludes still-sealed instances defensively (the flag toggle
 * action already refuses to set it on one, but a set can't un-seal itself
 * either way once flagged, so this is belt-and-suspenders, not the only
 * guard).
 *
 * @return array<int, array{ownedSetId:int, label:string, thumbnail:?string, activeStocktakeId:?int}>
 */
function getFlaggedStocktakeOwnedSets(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT os.id AS owned_set_id, s.rebrickable_set_num, s.name, s.local_image_path AS thumbnail
         FROM owned_sets os
         INNER JOIN sets s ON s.id = os.set_id
         WHERE os.flagged_for_stocktake_at IS NOT NULL AND os.condition_type != 'new'
         ORDER BY os.flagged_for_stocktake_at"
    );

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $ownedSetId = (int) $row['owned_set_id'];
        $active = getActiveStocktakeForOwnedSet($pdo, $ownedSetId);
        $result[] = [
            'ownedSetId' => $ownedSetId,
            'label' => $row['rebrickable_set_num'] . ' — ' . $row['name'],
            'thumbnail' => $row['thumbnail'],
            'activeStocktakeId' => $active !== null ? (int) $active['id'] : null,
        ];
    }
    return $result;
}

/**
 * Snapshots every regular/sticker part and minifig of this owned instance
 * into stocktake_items (nominal from the set's own BOM, previous_actual from
 * whatever storage_items/owned_set_minifigs currently says), then
 * immediately zeroes all of it for real. Refuses a still-sealed ("new")
 * instance — same rule the existing owned_set_sealed_note already states —
 * and refuses a second concurrent session for the same set.
 */
function startStocktakeForOwnedSet(PDO $pdo, int $userId, array $ownedSet): int
{
    if ($ownedSet['condition_type'] === 'new') {
        throw new RuntimeException(t('stocktake_sealed_error'));
    }
    if (getActiveStocktakeForOwnedSet($pdo, (int) $ownedSet['id']) !== null) {
        throw new RuntimeException(t('stocktake_already_active_error'));
    }

    $parts = getOwnedSetPartsWithStatus($pdo, $ownedSet);
    $stickers = getOwnedSetStickerPartsWithStatus($pdo, $ownedSet);
    $minifigs = getOwnedSetMinifigsWithStatus($pdo, $ownedSet);

    $insertStocktakeStmt = $pdo->prepare(
        "INSERT INTO stocktakes (user_id, source_type, owned_set_id, location_id) VALUES (?, 'owned_set', ?, ?)"
    );
    $insertStocktakeStmt->execute([$userId, $ownedSet['id'], $ownedSet['location_id']]);
    $stocktakeId = (int) $pdo->lastInsertId();

    $insertItemStmt = $pdo->prepare(
        'INSERT INTO stocktake_items (stocktake_id, item_type, location_id, part_id, color_id, condition_type, minifig_id, nominal_quantity, previous_actual_quantity)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ([$parts, $stickers] as $group) {
        foreach ($group as $part) {
            $insertItemStmt->execute([
                $stocktakeId, 'part', $ownedSet['location_id'], $part['part_id'], $part['color_id'],
                $ownedSet['condition_type'], null, $part['nominal_quantity'], $part['actual_quantity'],
            ]);
        }
    }
    foreach ($minifigs as $fig) {
        $insertItemStmt->execute([
            $stocktakeId, 'minifig', null, null, null,
            null, $fig['minifig_id'], $fig['nominal_quantity'], $fig['actual_quantity'],
        ]);
    }

    foreach ([$parts, $stickers] as $group) {
        foreach ($group as $part) {
            setOwnedSetPartInventory($pdo, $ownedSet, $part['part_id'], $part['color_id'], $part['nominal_quantity'], 0, 0, $userId);
        }
    }
    if (!empty($minifigs)) {
        $zeroInput = [];
        foreach ($minifigs as $fig) {
            $zeroInput[$fig['minifig_id']] = 0;
        }
        applyOwnedSetMinifigInventory($pdo, $ownedSet, $zeroInput, []);
    }

    refreshAppStatsCache($pdo);
    return $stocktakeId;
}

/**
 * Same idea as startStocktakeForOwnedSet(), scoped to one storage location
 * (recursively across its subtree when $recursive) instead of a set's BOM —
 * "nominal" here just means "whatever the system said was there before",
 * there's no separate BOM concept for a free-standing location. Parts only
 * (see this file's own doc comment for why minifigs are out of scope here).
 */
function startStocktakeForLocation(PDO $pdo, int $userId, int $locationId, bool $recursive): int
{
    if (getActiveStocktakeForLocation($pdo, $locationId) !== null) {
        throw new RuntimeException(t('stocktake_already_active_error'));
    }

    $locationIds = $recursive ? getLocationSubtreeIds($locationId) : [$locationId];
    $rows = [];
    foreach ($locationIds as $id) {
        foreach (getLocationStock($id) as $row) {
            if ($row['color_id'] === null) {
                continue;
            }
            $row['location_id'] = $id;
            $rows[] = $row;
        }
    }

    $insertStocktakeStmt = $pdo->prepare(
        "INSERT INTO stocktakes (user_id, source_type, location_id, recursive_scope) VALUES (?, 'location', ?, ?)"
    );
    $insertStocktakeStmt->execute([$userId, $locationId, $recursive ? 1 : 0]);
    $stocktakeId = (int) $pdo->lastInsertId();

    if (!empty($rows)) {
        $insertItemStmt = $pdo->prepare(
            "INSERT INTO stocktake_items (stocktake_id, item_type, location_id, part_id, color_id, condition_type, nominal_quantity, previous_actual_quantity)
             VALUES (?, 'part', ?, ?, ?, ?, ?, ?)"
        );
        foreach ($rows as $row) {
            $insertItemStmt->execute([
                $stocktakeId, $row['location_id'], $row['part_id'], $row['color_id'],
                $row['condition_type'], $row['quantity'], $row['quantity'],
            ]);
        }
        foreach ($rows as $row) {
            setStorageItemQuantity($row['location_id'], $row['part_id'], $row['color_id'], $row['condition_type'], 0, $userId, 0, 'correction');
        }
    }

    refreshAppStatsCache($pdo);
    return $stocktakeId;
}

/**
 * The single atomic "user confirmed a count" write path (mirrors pickItem()
 * in src/pick_lists.php): writes the real quantity back via the same
 * functions the existing inventory-tab editor uses (setOwnedSetPartInventory()/
 * applyOwnedSetMinifigInventory() for an owned_set item, setStorageItemQuantity()
 * for a location item), then marks the row confirmed. Every write already
 * refreshes the app stats cache, per this project's standing convention.
 */
function confirmStocktakeItem(PDO $pdo, int $stocktakeId, int $stocktakeItemId, int $quantity, ?int $userId): array
{
    $stocktake = getStocktake($pdo, $stocktakeId);
    if ($stocktake === null || $stocktake['status'] !== 'active') {
        throw new RuntimeException(t('stocktake_not_active_error'));
    }
    $item = getStocktakeItemById($pdo, $stocktakeItemId);
    if ($item === null || (int) $item['stocktake_id'] !== $stocktakeId) {
        throw new RuntimeException(t('stocktake_item_not_found_error'));
    }

    $quantity = max(0, $quantity);

    if ($item['item_type'] === 'minifig') {
        $ownedSet = getOwnedSetById($pdo, (int) $stocktake['owned_set_id']);
        if ($ownedSet === null) {
            throw new RuntimeException(t('stocktake_not_active_error'));
        }
        applyOwnedSetMinifigInventory($pdo, $ownedSet, [(int) $item['minifig_id'] => $quantity], []);
    } elseif ($stocktake['source_type'] === 'owned_set') {
        $ownedSet = getOwnedSetById($pdo, (int) $stocktake['owned_set_id']);
        if ($ownedSet === null) {
            throw new RuntimeException(t('stocktake_not_active_error'));
        }
        setOwnedSetPartInventory($pdo, $ownedSet, (int) $item['part_id'], (int) $item['color_id'], (int) $item['nominal_quantity'], $quantity, 0, $userId);
    } else {
        setStorageItemQuantity((int) $item['location_id'], (int) $item['part_id'], (int) $item['color_id'], $item['condition_type'], $quantity, $userId, 0);
    }

    $pdo->prepare('UPDATE stocktake_items SET confirmed_quantity = ?, confirmed_at = NOW() WHERE id = ?')
        ->execute([$quantity, $stocktakeItemId]);

    refreshAppStatsCache($pdo);
    return ['confirmedQuantity' => $quantity] + getStocktakeProgress($pdo, $stocktakeId);
}

function completeStocktake(PDO $pdo, int $stocktakeId): void
{
    $stocktake = getStocktake($pdo, $stocktakeId);
    if ($stocktake === null) {
        throw new RuntimeException(t('stocktake_not_active_error'));
    }
    $pdo->prepare("UPDATE stocktakes SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$stocktakeId]);
    if ($stocktake['source_type'] === 'location') {
        $pdo->prepare('UPDATE storage_locations SET flagged_for_stocktake_at = NULL WHERE id = ?')->execute([$stocktake['location_id']]);
    } elseif ($stocktake['owned_set_id'] !== null) {
        // Clears the "auf der Inventurliste" flag too, whether this session
        // came from that list or was started straight on the PC despite
        // already being flagged — either way it's no longer outstanding.
        // cancelStocktake() deliberately does NOT do this (an abandoned
        // session should stay on the list, not silently drop off it).
        $pdo->prepare('UPDATE owned_sets SET flagged_for_stocktake_at = NULL WHERE id = ?')->execute([$stocktake['owned_set_id']]);
    }
}

/**
 * Abandons a session the user changed their mind about: every item never
 * re-confirmed this session is written back to its previous_actual_quantity
 * (not nominal — see this file's own doc comment), whatever WAS already
 * confirmed is left exactly as counted, then the session itself is deleted
 * (stocktake_items cascades away with it).
 */
function cancelStocktake(PDO $pdo, int $stocktakeId, ?int $userId): void
{
    $stocktake = getStocktake($pdo, $stocktakeId);
    if ($stocktake === null) {
        throw new RuntimeException(t('stocktake_not_active_error'));
    }

    $stmt = $pdo->prepare('SELECT * FROM stocktake_items WHERE stocktake_id = ? AND confirmed_at IS NULL');
    $stmt->execute([$stocktakeId]);
    $unconfirmed = $stmt->fetchAll();

    $ownedSet = $stocktake['source_type'] === 'owned_set' ? getOwnedSetById($pdo, (int) $stocktake['owned_set_id']) : null;
    foreach ($unconfirmed as $item) {
        $restoreQuantity = (int) $item['previous_actual_quantity'];
        if ($item['item_type'] === 'minifig' && $ownedSet !== null) {
            applyOwnedSetMinifigInventory($pdo, $ownedSet, [(int) $item['minifig_id'] => $restoreQuantity], []);
        } elseif ($ownedSet !== null) {
            setOwnedSetPartInventory($pdo, $ownedSet, (int) $item['part_id'], (int) $item['color_id'], (int) $item['nominal_quantity'], $restoreQuantity, 0, $userId);
        } else {
            setStorageItemQuantity((int) $item['location_id'], (int) $item['part_id'], (int) $item['color_id'], $item['condition_type'], $restoreQuantity, $userId, 0);
        }
    }

    $pdo->prepare('DELETE FROM stocktakes WHERE id = ?')->execute([$stocktakeId]);
    refreshAppStatsCache($pdo);
}

/**
 * "Am PC durchführen" vs. "Zur Inventurliste hinzufügen/entfernen" choice,
 * shown when owned_set_detail's "Inventur starten" button is clicked and no
 * session is already active for that set — per explicit request, doing an
 * owned-set Inventur is now a real fork rather than always opening the modal
 * directly: the PC path is unchanged (window.openStocktakeModal() below),
 * the list path just toggles owned_sets.flagged_for_stocktake_at (action
 * toggle_owned_set_stocktake_flag) and closes again, no counting UI opens at
 * all. Exposed as window.openStocktakeChoiceModal(ownedSetId, flagged,
 * onPcChosen, onListToggled) — the second button's label/action flips
 * between add/remove based on $flagged, which the caller already knows from
 * its own stocktake_status check. Only ever embedded on owned_set_detail (a
 * flagged *location*, by contrast, is worked exclusively from /pick/ per the
 * same request — the location Explorer's "Zur Inventur vormerken" checkbox
 * has no matching "start on PC" option, so it needs no choice step).
 */
function renderStocktakeChoiceModal(): string
{
    $html = '<div class="modal-overlay" id="stocktake-choice-modal" style="display:none;">';
    $html .= '<div class="modal-box">';
    $html .= '<div class="owned-set-wizard-header">';
    $html .= '<h2>' . htmlspecialchars(t('stocktake_choice_heading')) . '</h2>';
    $html .= '<button type="button" class="modal-close" id="stocktake-choice-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '</div>';
    $html .= '<p>' . htmlspecialchars(t('stocktake_choice_hint')) . '</p>';
    $html .= '<p class="owned-set-wizard-error" id="stocktake-choice-error"></p>';
    $html .= '<div class="owned-set-wizard-nav">';
    $html .= '<button type="button" class="owned-set-wizard-back" id="stocktake-choice-list-btn"></button>';
    $html .= '<button type="button" id="stocktake-choice-pc-btn">' . htmlspecialchars(t('stocktake_choice_pc_button')) . '</button>';
    $html .= '</div>';
    $html .= '</div></div>';

    $addLabelJson = json_encode(t('stocktake_choice_add_to_list_button'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $removeLabelJson = json_encode(t('stocktake_choice_remove_from_list_button'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $errorGenericJson = json_encode(t('pick_error_generic'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $html .= <<<SCRIPT
<script>
(function(){
  var modal = document.getElementById('stocktake-choice-modal');
  var closeBtn = document.getElementById('stocktake-choice-modal-close');
  var listBtn = document.getElementById('stocktake-choice-list-btn');
  var pcBtn = document.getElementById('stocktake-choice-pc-btn');
  var errorEl = document.getElementById('stocktake-choice-error');
  if (!modal) { return; }

  function close() { modal.style.display = 'none'; }
  if (closeBtn) { closeBtn.addEventListener('click', close); }

  window.openStocktakeChoiceModal = function(ownedSetId, flagged, onPcChosen, onListToggled) {
    errorEl.textContent = '';
    listBtn.textContent = flagged ? $removeLabelJson : $addLabelJson;
    listBtn.disabled = false;
    modal.style.display = 'flex';

    pcBtn.onclick = function() {
      close();
      onPcChosen();
    };

    listBtn.onclick = function() {
      listBtn.disabled = true;
      var formData = new FormData();
      formData.set('action', 'toggle_owned_set_stocktake_flag');
      formData.set('owned_set_id', String(ownedSetId));
      formData.set('flagged', flagged ? '0' : '1');
      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (!res.success) {
            errorEl.textContent = res.message || $errorGenericJson;
            listBtn.disabled = false;
            return;
          }
          close();
          onListToggled(res.flagged);
        })
        .catch(function() {
          errorEl.textContent = $errorGenericJson;
          listBtn.disabled = false;
        });
    };
  };
})();
</script>
SCRIPT;

    return $html;
}

/**
 * Shared modal markup + inline script for the guided per-item counting step
 * (opened either directly, when resuming an active session, or after the
 * "Am PC durchführen" choice above) — embedded once in renderApp() (like
 * renderPartDetailModal()) and driven entirely via window.openStocktakeModal(),
 * which each entry point calls with its own start action/params. Fetch-based
 * throughout, no page reload — same self-contained .modal-overlay/.modal-box
 * shell as renderCreatePickListFromSetModal() (src/pick_lists.php).
 */
function renderStocktakeModal(): string
{
    $html = '<div class="modal-overlay" id="stocktake-modal" style="display:none;">';
    $html .= '<div class="modal-box">';
    $html .= '<div class="owned-set-wizard-header">';
    $html .= '<h2 id="stocktake-modal-heading">' . htmlspecialchars(t('stocktake_modal_heading')) . '</h2>';
    $html .= '<button type="button" class="modal-close" id="stocktake-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '</div>';

    $html .= '<div id="stocktake-progress-wrap" class="stocktake-progress-wrap" style="display:none;">';
    $html .= '<div class="stocktake-progress-bar"><div class="stocktake-progress-bar-fill" id="stocktake-progress-fill"></div></div>';
    $html .= '<p class="stocktake-progress-label" id="stocktake-progress-label"></p>';
    $html .= '</div>';

    $html .= '<div id="stocktake-item-card" class="stocktake-item-card" style="display:none;">';
    $html .= '<p class="stocktake-item-location" id="stocktake-item-location"></p>';
    $html .= '<p class="stocktake-item-swatch" id="stocktake-item-swatch"></p>';
    $html .= '<p class="stocktake-item-label" id="stocktake-item-label"></p>';
    $html .= '<p class="stocktake-item-color" id="stocktake-item-color"></p>';
    $html .= '<p class="stocktake-item-nominal" id="stocktake-item-nominal"></p>';
    $html .= '<label class="stocktake-item-quantity-label">' . htmlspecialchars(t('stocktake_quantity_label'));
    $html .= '<input type="number" min="0" id="stocktake-item-quantity"></label>';
    $html .= '<div class="owned-set-wizard-nav">';
    $html .= '<button type="button" class="stocktake-secondary-button" id="stocktake-back-button">' . htmlspecialchars(t('stocktake_back_button')) . '</button>';
    $html .= '<button type="button" id="stocktake-next-button">' . htmlspecialchars(t('stocktake_next_button')) . '</button>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<div id="stocktake-empty-note" class="hint" style="display:none;">' . htmlspecialchars(t('stocktake_empty_note')) . '</div>';

    $html .= '<div id="stocktake-done-card" style="display:none;">';
    $html .= '<p>' . htmlspecialchars(t('stocktake_completed_message')) . '</p>';
    $html .= '<div class="owned-set-wizard-nav">';
    $html .= '<button type="button" id="stocktake-finish-button">' . htmlspecialchars(t('stocktake_finish_button')) . '</button>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<p class="owned-set-wizard-error" id="stocktake-error"></p>';

    $html .= '<div class="owned-set-wizard-nav stocktake-footer-nav">';
    $html .= '<button type="button" class="stocktake-danger-button" id="stocktake-cancel-button">' . htmlspecialchars(t('stocktake_cancel_button')) . '</button>';
    $html .= '<button type="button" class="stocktake-secondary-button" id="stocktake-pause-button">' . htmlspecialchars(t('stocktake_pause_button')) . '</button>';
    $html .= '</div>';

    $html .= '</div></div>';

    $cancelConfirmJson = json_encode(t('stocktake_cancel_confirm'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $progressLabelTemplateJson = json_encode(t('stocktake_progress'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $expectedLabelTemplateJson = json_encode(t('stocktake_expected_label'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $errorGenericJson = json_encode(t('pick_error_generic'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html .= <<<SCRIPT
<script>
(function(){
  var modal = document.getElementById('stocktake-modal');
  var closeBtn = document.getElementById('stocktake-modal-close');
  var progressWrap = document.getElementById('stocktake-progress-wrap');
  var progressFill = document.getElementById('stocktake-progress-fill');
  var progressLabel = document.getElementById('stocktake-progress-label');
  var itemCard = document.getElementById('stocktake-item-card');
  var itemLocation = document.getElementById('stocktake-item-location');
  var itemSwatch = document.getElementById('stocktake-item-swatch');
  var itemLabel = document.getElementById('stocktake-item-label');
  var itemColor = document.getElementById('stocktake-item-color');
  var itemNominal = document.getElementById('stocktake-item-nominal');
  var quantityInput = document.getElementById('stocktake-item-quantity');
  var backBtn = document.getElementById('stocktake-back-button');
  var nextBtn = document.getElementById('stocktake-next-button');
  var emptyNote = document.getElementById('stocktake-empty-note');
  var doneCard = document.getElementById('stocktake-done-card');
  var finishBtn = document.getElementById('stocktake-finish-button');
  var errorEl = document.getElementById('stocktake-error');
  var cancelBtn = document.getElementById('stocktake-cancel-button');
  var pauseBtn = document.getElementById('stocktake-pause-button');
  if (!modal) { return; }

  var stocktakeId = null;
  var currentItem = null;
  var history = [];
  var onClosed = null;

  function post(action, params) {
    var formData = new FormData();
    formData.set('action', action);
    Object.keys(params || {}).forEach(function(key) {
      formData.set(key, String(params[key]));
    });
    return fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' }).then(function(r) { return r.json(); });
  }

  function reset() {
    stocktakeId = null;
    currentItem = null;
    history = [];
    errorEl.textContent = '';
    progressWrap.style.display = 'none';
    itemCard.style.display = 'none';
    emptyNote.style.display = 'none';
    doneCard.style.display = 'none';
  }

  function renderProgress(progress) {
    if (!progress || !progress.total) {
      progressWrap.style.display = 'none';
      return;
    }
    progressWrap.style.display = 'block';
    var percent = progress.total > 0 ? Math.round((progress.confirmed / progress.total) * 100) : 0;
    progressFill.style.width = percent + '%';
    progressLabel.textContent = $progressLabelTemplateJson
      .replace('{done}', progress.confirmed)
      .replace('{total}', progress.total);
  }

  function showItem(item) {
    currentItem = item;
    itemCard.style.display = 'block';
    emptyNote.style.display = 'none';
    doneCard.style.display = 'none';
    itemLocation.textContent = item.locationPath || '';
    itemLocation.style.display = item.locationPath ? 'block' : 'none';
    itemLabel.textContent = item.label;
    if (item.colorName) {
      itemColor.textContent = item.colorName;
      itemColor.style.display = 'block';
    } else {
      itemColor.style.display = 'none';
    }
    if (item.colorRgb) {
      itemSwatch.style.display = 'inline-block';
      itemSwatch.style.background = '#' + item.colorRgb;
    } else {
      itemSwatch.style.display = 'none';
    }
    itemNominal.textContent = $expectedLabelTemplateJson.replace('{nominal}', item.nominalQuantity);
    quantityInput.value = String(item.confirmedQuantity !== null ? item.confirmedQuantity : 0);
    backBtn.disabled = history.length === 0;
  }

  function showDone() {
    currentItem = null;
    itemCard.style.display = 'none';
    emptyNote.style.display = 'none';
    doneCard.style.display = 'block';
  }

  function loadNext() {
    errorEl.textContent = '';
    post('stocktake_next_item', { stocktake_id: stocktakeId }).then(function(res) {
      if (!res.success) {
        errorEl.textContent = res.message || $errorGenericJson;
        return;
      }
      renderProgress(res.progress);
      if (res.item) {
        showItem(res.item);
      } else if (res.progress && res.progress.total === 0) {
        itemCard.style.display = 'none';
        doneCard.style.display = 'none';
        emptyNote.style.display = 'block';
      } else {
        showDone();
      }
    }).catch(function() {
      errorEl.textContent = $errorGenericJson;
    });
  }

  nextBtn.addEventListener('click', function() {
    if (!currentItem) { return; }
    var quantity = Math.max(0, parseInt(quantityInput.value, 10) || 0);
    errorEl.textContent = '';
    nextBtn.disabled = true;
    post('stocktake_item_confirm', { stocktake_id: stocktakeId, stocktake_item_id: currentItem.id, quantity: quantity }).then(function(res) {
      nextBtn.disabled = false;
      if (!res.success) {
        errorEl.textContent = res.message || $errorGenericJson;
        return;
      }
      history.push(currentItem.id);
      loadNext();
    }).catch(function() {
      nextBtn.disabled = false;
      errorEl.textContent = $errorGenericJson;
    });
  });

  backBtn.addEventListener('click', function() {
    var previousId = history.pop();
    if (previousId === undefined) { return; }
    errorEl.textContent = '';
    post('stocktake_item_get', { stocktake_id: stocktakeId, stocktake_item_id: previousId }).then(function(res) {
      if (!res.success || !res.item) {
        errorEl.textContent = res.message || $errorGenericJson;
        return;
      }
      renderProgress(res.progress);
      showItem(res.item);
    }).catch(function() {
      errorEl.textContent = $errorGenericJson;
    });
  });

  finishBtn.addEventListener('click', function() {
    errorEl.textContent = '';
    post('stocktake_complete', { stocktake_id: stocktakeId }).then(function(res) {
      if (!res.success) {
        errorEl.textContent = res.message || $errorGenericJson;
        return;
      }
      modal.style.display = 'none';
      if (onClosed) { onClosed(true); }
    }).catch(function() {
      errorEl.textContent = $errorGenericJson;
    });
  });

  pauseBtn.addEventListener('click', function() {
    modal.style.display = 'none';
    if (onClosed) { onClosed(false); }
  });

  if (closeBtn) {
    closeBtn.addEventListener('click', function() {
      modal.style.display = 'none';
      if (onClosed) { onClosed(false); }
    });
  }

  cancelBtn.addEventListener('click', function() {
    if (!stocktakeId || !window.confirm($cancelConfirmJson)) { return; }
    errorEl.textContent = '';
    post('stocktake_cancel', { stocktake_id: stocktakeId }).then(function(res) {
      if (!res.success) {
        errorEl.textContent = res.message || $errorGenericJson;
        return;
      }
      modal.style.display = 'none';
      if (onClosed) { onClosed(true); }
    }).catch(function() {
      errorEl.textContent = $errorGenericJson;
    });
  });

  // startAction/startParams: 'start_stocktake_for_owned_set'/{owned_set_id}
  // or 'start_stocktake_for_location'/{location_id, recursive}.
  // resumeStocktakeId: pass an already-active session's id to skip creation
  // and jump straight to its first unconfirmed item. onDone(changed) fires
  // once the modal closes for any reason — the caller uses it to refresh its
  // own resume banner/pill.
  window.openStocktakeModal = function(startAction, startParams, resumeStocktakeId, onDone) {
    reset();
    onClosed = onDone || null;
    modal.style.display = 'flex';
    itemCard.style.display = 'none';

    if (resumeStocktakeId) {
      stocktakeId = resumeStocktakeId;
      loadNext();
      return;
    }

    post(startAction, startParams).then(function(res) {
      if (!res.success) {
        errorEl.textContent = res.message || $errorGenericJson;
        return;
      }
      stocktakeId = res.stocktakeId;
      loadNext();
    }).catch(function() {
      errorEl.textContent = $errorGenericJson;
    });
  };
})();
</script>
SCRIPT;

    return $html;
}
