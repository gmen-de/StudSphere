<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/sets.php';
require_once __DIR__ . '/minifigs.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/i18n.php';

/**
 * Pickliste domain logic (/pick/, see the project plan) — a pick list walks
 * a set's or minifig's needed parts against current loose stock, and lets a
 * user physically pull parts from storage against that list, one location at
 * a time when a single location doesn't have enough. Every pick list is
 * itself a storage_locations row (location_type='pick_list', always a child
 * of the single location_type='pick_lager_root' "Pick Lager" location) so
 * its contents stay visible as loose stock everywhere else in the app —
 * getLooseStockMap() (src/storage.php) only ever excludes 'owned_set', never
 * 'pick_list', by design.
 *
 * Nothing here stores WHERE to pick a part from — getPickStepsForItem()
 * recomputes that live from getPartStock() on every call. This is what makes
 * both requirements fall out for free: picking can always continue at a
 * second/third location once the first runs short, and a pick list can be
 * resumed from a completely different device/session without any
 * client-side state, since every read is fresh from the DB.
 */

/**
 * Finds the single "Pick Lager" root (location_type='pick_lager_root',
 * created once by migration 40 / installDatabase()) — same
 * find-by-marker idiom as the existing 'owned_set' location_type, so no
 * app_settings singleton key is needed and the row can never drift out of
 * sync with a stored id.
 */
function getPickLagerRootId(PDO $pdo): ?int
{
    $stmt = $pdo->query("SELECT id FROM storage_locations WHERE location_type = 'pick_lager_root' LIMIT 1");
    $id = $stmt->fetchColumn();
    return $id !== false ? (int) $id : null;
}

/**
 * Needed parts/minifigs for one set or minifig inventory, before matching
 * against stock — the "what do I need" half of a pick list, computed once at
 * creation time (createPickList()) and frozen into pick_list_items.
 *
 * Minifigs needed by a SET are resolved per the project's clarified
 * decision: if an assembled instance already sits in loose storage (not
 * inside an 'owned_set' or 'pick_list' location — not "available" there),
 * pick that ONE instance as its own item; only the shortfall beyond however
 * many assembled instances exist falls back to that minifig's own
 * constituent parts (getSetPartsList() on the minifig's own inventory),
 * scaled by the shortfall only, merged into the same part-needs map as the
 * set's own bricks. A bare MINIFIG pick list has no "assembled instance of
 * itself" concept and is always flattened straight to parts.
 *
 * $missingOnly, when given, clamps each part/minifig need down to its
 * shortfall (nominal - actual, floored at 0) using the same
 * "part_id:color_id" / minifig_id keys — used by the owned_set_detail
 * "missing parts" pick-list entry point, so the resulting pick list only
 * asks for what's actually still missing from that particular owned
 * instance, not the set's full parts list from scratch.
 *
 * @param array{parts?: array<string,int>, minifigs?: array<int,int>} $missingOnly
 * @return array<int, array{item_type:string, part_id:?int, color_id:?int, minifig_id:?int, source_minifig_storage_item_id:?int, needed_quantity:int}>
 */
function computePickListNeededItems(PDO $pdo, string $sourceType, int $inventoryId, array $missingOnly = []): array
{
    $partNeeds = []; // "part_id:color_id" => quantity
    $minifigItems = []; // list of ['minifig_id', 'source_minifig_storage_item_id'|null, 'needed_quantity']

    $addPartNeed = function (?int $partId, ?int $colorId, int $quantity) use (&$partNeeds): void {
        if ($partId === null || $colorId === null || $quantity <= 0) {
            return;
        }
        $key = $partId . ':' . $colorId;
        $partNeeds[$key] = ($partNeeds[$key] ?? ['part_id' => $partId, 'color_id' => $colorId, 'quantity' => 0]);
        $partNeeds[$key]['quantity'] += $quantity;
    };

    if ($sourceType === 'set') {
        foreach (getSetPartsList($pdo, $inventoryId, false, 'en') as $part) {
            $addPartNeed($part['part_id'], $part['color_id'], $part['quantity']);
        }

        foreach (getSetMinifigsList($pdo, $inventoryId) as $fig) {
            $figNeeded = $fig['quantity'];
            if (isset($missingOnly['minifigs'])) {
                $figNeeded = min($figNeeded, max(0, $missingOnly['minifigs'][$fig['minifig_id']] ?? 0));
            }
            if ($figNeeded <= 0) {
                continue;
            }

            $remaining = $figNeeded;
            $availableStmt = $pdo->prepare(
                "SELECT msi.id FROM minifig_storage_items msi
                 INNER JOIN storage_locations sl ON sl.id = msi.location_id
                 WHERE msi.minifig_id = ?
                   AND (sl.location_type IS NULL OR sl.location_type NOT IN ('owned_set', 'pick_list'))
                 ORDER BY msi.id"
            );
            $availableStmt->execute([$fig['minifig_id']]);
            $availableInstanceIds = array_map('intval', $availableStmt->fetchAll(PDO::FETCH_COLUMN));

            foreach (array_slice($availableInstanceIds, 0, $remaining) as $instanceId) {
                $minifigItems[] = [
                    'minifig_id' => $fig['minifig_id'],
                    'source_minifig_storage_item_id' => $instanceId,
                    'needed_quantity' => 1,
                ];
                $remaining--;
            }

            if ($remaining > 0) {
                $figInventoryId = getMinifigInventoryId($pdo, $fig['fig_num']);
                if ($figInventoryId !== null) {
                    foreach (getSetPartsList($pdo, $figInventoryId, false, 'en') as $part) {
                        $addPartNeed($part['part_id'], $part['color_id'], $part['quantity'] * $remaining);
                    }
                }
            }
        }
    } else {
        foreach (getSetPartsList($pdo, $inventoryId, false, 'en') as $part) {
            $addPartNeed($part['part_id'], $part['color_id'], $part['quantity']);
        }
    }

    $items = [];
    foreach ($partNeeds as $key => $need) {
        $quantity = $need['quantity'];
        if (isset($missingOnly['parts'])) {
            $quantity = min($quantity, max(0, $missingOnly['parts'][$key] ?? 0));
        }
        if ($quantity <= 0) {
            continue;
        }
        $items[] = [
            'item_type' => 'part', 'part_id' => $need['part_id'], 'color_id' => $need['color_id'],
            'minifig_id' => null, 'source_minifig_storage_item_id' => null, 'needed_quantity' => $quantity,
        ];
    }
    foreach ($minifigItems as $fig) {
        $items[] = [
            'item_type' => 'minifig', 'part_id' => null, 'color_id' => null,
            'minifig_id' => $fig['minifig_id'], 'source_minifig_storage_item_id' => $fig['source_minifig_storage_item_id'],
            'needed_quantity' => $fig['needed_quantity'],
        ];
    }
    return $items;
}

/**
 * Creates a new pick list: its own storage_locations row (named
 * $description, nested under the Pick Lager root), the pick_lists row, and
 * one pick_list_items row per needed part/minifig (computePickListNeededItems()).
 * $inventoryId is snapshotted onto the pick_lists row so a later Rebrickable
 * re-import can't retroactively change an already-in-progress list.
 */
function createPickList(PDO $pdo, int $userId, string $sourceType, int $catalogId, string $description, ?int $ownedSetId = null, array $missingOnly = []): ?int
{
    $inventoryId = $sourceType === 'set'
        ? getSetInventoryId($pdo, getCatalogSetNum($pdo, $catalogId) ?? '')
        : getMinifigInventoryId($pdo, getCatalogMinifigNum($pdo, $catalogId) ?? '');
    if ($inventoryId === null) {
        return null;
    }

    $pickLagerRootId = getPickLagerRootId($pdo);
    if ($pickLagerRootId === null) {
        throw new RuntimeException('Pick Lager root location is missing — was migration 40 applied?');
    }

    $locationId = createStorageLocation($pickLagerRootId, $description, 'pick_list');

    $insertListStmt = $pdo->prepare(
        'INSERT INTO pick_lists (user_id, location_id, source_type, set_id, minifig_id, inventory_id, owned_set_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insertListStmt->execute([
        $userId, $locationId, $sourceType,
        $sourceType === 'set' ? $catalogId : null,
        $sourceType === 'minifig' ? $catalogId : null,
        $inventoryId, $ownedSetId,
    ]);
    $pickListId = (int) $pdo->lastInsertId();

    $items = computePickListNeededItems($pdo, $sourceType, $inventoryId, $missingOnly);
    if (!empty($items)) {
        $insertItemStmt = $pdo->prepare(
            'INSERT INTO pick_list_items (pick_list_id, item_type, part_id, color_id, minifig_id, source_minifig_storage_item_id, needed_quantity)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $item) {
            $insertItemStmt->execute([
                $pickListId, $item['item_type'], $item['part_id'], $item['color_id'],
                $item['minifig_id'], $item['source_minifig_storage_item_id'], $item['needed_quantity'],
            ]);
        }
    }

    return $pickListId;
}

/**
 * The catalog set_detail page's "Bauteile auf Pickliste setzen" dialog
 * (src/routes/pages.php) — unlike computePickListNeededItems(), which lists
 * EVERY needed part+color (including ones with zero stock, surfaced as a
 * shortfall once picking starts), this lists only the ones that actually
 * have loose stock available right now, since the whole point of this entry
 * point is "quickly grab what I already have for this set." Deliberately
 * parts-only (no minifigs) — matches the user's own framing of this
 * specific dialog. needed_quantity is capped at whatever's actually
 * available (min(bom quantity, loose stock)), so a pick list built from this
 * dialog never itself shows a shortfall for something that was already
 * short before picking even started.
 *
 * @return array<int, array{part_id:int, color_id:int, needed_quantity:int, available_quantity:int, part_num:string, name:string, color_name:?string, thumbnail:?string}>
 */
function getSetAvailablePartsForPickList(PDO $pdo, int $inventoryId, string $locale = 'en'): array
{
    $looseStock = getLooseStockMap($pdo);
    $result = [];
    foreach (getSetPartsList($pdo, $inventoryId, false, $locale) as $part) {
        if ($part['color_id'] === null || $part['quantity'] <= 0) {
            continue;
        }
        $available = $looseStock[$part['part_id'] . ':' . $part['color_id']] ?? 0;
        if ($available <= 0) {
            continue;
        }
        $result[] = [
            'part_id' => $part['part_id'],
            'color_id' => $part['color_id'],
            'needed_quantity' => min($part['quantity'], $available),
            'available_quantity' => $available,
            'part_num' => $part['part_num'],
            'name' => $part['name'],
            'color_name' => $part['color_name'],
            'thumbnail' => $part['ldraw_thumbnail'] ?? $part['thumbnail'] ?? $part['remote_thumbnail'] ?? null,
        ];
    }
    return $result;
}

/**
 * Creates a pick list from exactly the rows the user left checked in the
 * "Bauteile auf Pickliste setzen" dialog, at whatever quantity they left in
 * each row's own input — $requestedQuantities is "part_id:color_id" =>
 * requested quantity from the client (an absent/zero key means "excluded",
 * same as unchecking it). The client's requested quantity is only ever
 * allowed to lower a row's default, never raise it: each is clamped to
 * getSetAvailablePartsForPickList()'s freshly-recomputed needed_quantity
 * (itself already min(bom quantity, current loose stock)) at submit time —
 * a stale dialog snapshot (stock moved between opening the dialog and
 * submitting, or a client trying to request more than was ever offered)
 * can't be used to end up with more than is genuinely available right now.
 *
 * @return ?array{pickListId:int, totalQuantity:int}
 */
function createPickListFromAvailableParts(PDO $pdo, int $userId, int $setId, string $description, array $requestedQuantities): ?array
{
    $setNum = getCatalogSetNum($pdo, $setId);
    $inventoryId = $setNum !== null ? getSetInventoryId($pdo, $setNum) : null;
    if ($inventoryId === null) {
        return null;
    }

    $available = getSetAvailablePartsForPickList($pdo, $inventoryId);
    $itemsToInsert = [];
    foreach ($available as $part) {
        $key = $part['part_id'] . ':' . $part['color_id'];
        if (!isset($requestedQuantities[$key])) {
            continue;
        }
        $quantity = min((int) $requestedQuantities[$key], $part['needed_quantity']);
        if ($quantity <= 0) {
            continue;
        }
        $part['needed_quantity'] = $quantity;
        $itemsToInsert[] = $part;
    }
    if (empty($itemsToInsert)) {
        return null;
    }

    $pickLagerRootId = getPickLagerRootId($pdo);
    if ($pickLagerRootId === null) {
        throw new RuntimeException('Pick Lager root location is missing — was migration 40 applied?');
    }
    $locationId = createStorageLocation($pickLagerRootId, $description, 'pick_list');

    $insertListStmt = $pdo->prepare(
        'INSERT INTO pick_lists (user_id, location_id, source_type, set_id, inventory_id) VALUES (?, ?, ?, ?, ?)'
    );
    $insertListStmt->execute([$userId, $locationId, 'set', $setId, $inventoryId]);
    $pickListId = (int) $pdo->lastInsertId();

    $insertItemStmt = $pdo->prepare(
        'INSERT INTO pick_list_items (pick_list_id, item_type, part_id, color_id, needed_quantity) VALUES (?, \'part\', ?, ?, ?)'
    );
    $totalQuantity = 0;
    foreach ($itemsToInsert as $part) {
        $insertItemStmt->execute([$pickListId, $part['part_id'], $part['color_id'], $part['needed_quantity']]);
        $totalQuantity += $part['needed_quantity'];
    }

    return ['pickListId' => $pickListId, 'totalQuantity' => $totalQuantity];
}

function getCatalogSetNum(PDO $pdo, int $setId): ?string
{
    $stmt = $pdo->prepare('SELECT rebrickable_set_num FROM sets WHERE id = ?');
    $stmt->execute([$setId]);
    $num = $stmt->fetchColumn();
    return $num !== false ? (string) $num : null;
}

function getCatalogMinifigNum(PDO $pdo, int $minifigId): ?string
{
    $stmt = $pdo->prepare('SELECT fig_num FROM minifigs WHERE id = ?');
    $stmt->execute([$minifigId]);
    $num = $stmt->fetchColumn();
    return $num !== false ? (string) $num : null;
}

function getPickList(PDO $pdo, int $pickListId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM pick_lists WHERE id = ?');
    $stmt->execute([$pickListId]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

/** @return array<int, array<string,mixed>> */
function getPickListsForUser(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT * FROM pick_lists WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * A user's still-usable (active/completed, not yet closed) pick lists for
 * one specific catalog set, with the pick list's own storage_locations name
 * joined in for display — powers the "Set zur Sammlung hinzufügen" wizard's
 * optional pick-list-as-source selector (src/owned_set_wizard.php).
 *
 * @return array<int, array{id:int, name:string, created_at:string}>
 */
function getPickListsForSet(PDO $pdo, int $userId, int $setId): array
{
    $stmt = $pdo->prepare(
        "SELECT pl.id, sl.name, pl.created_at
         FROM pick_lists pl
         INNER JOIN storage_locations sl ON sl.id = pl.location_id
         WHERE pl.user_id = ? AND pl.set_id = ? AND pl.source_type = 'set' AND pl.status IN ('active', 'completed')
         ORDER BY pl.created_at DESC"
    );
    $stmt->execute([$userId, $setId]);
    return $stmt->fetchAll();
}

/** @return array<int, array<string,mixed>> */
function getPickListItems(PDO $pdo, int $pickListId): array
{
    $stmt = $pdo->prepare('SELECT * FROM pick_list_items WHERE pick_list_id = ? ORDER BY id');
    $stmt->execute([$pickListId]);
    return $stmt->fetchAll();
}

function getPickListItem(PDO $pdo, int $pickListItemId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM pick_list_items WHERE id = ?');
    $stmt->execute([$pickListItemId]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

/**
 * Concrete pick steps for one still-open pick_list_items row, recomputed
 * live on every call (never cached/stored) — this is what lets picking
 * continue at a second, third, ... location once an earlier one runs short,
 * and what lets a pick list survive being resumed on another device: there
 * is no client-trusted state, only what getPartStock() reports right now.
 *
 * @return array{done:bool, steps:array<int, array{location_id:int, location_path:string, available:int, suggested_pick:int}>, shortfall:int}
 */
function getPickStepsForItem(PDO $pdo, array $pickListItem): array
{
    $remaining = (int) $pickListItem['needed_quantity'] - (int) $pickListItem['picked_quantity'];
    if ($remaining <= 0) {
        return ['done' => true, 'steps' => [], 'shortfall' => 0];
    }

    if ($pickListItem['item_type'] === 'minifig') {
        $instanceId = $pickListItem['source_minifig_storage_item_id'];
        if ($instanceId === null) {
            return ['done' => false, 'steps' => [], 'shortfall' => $remaining];
        }
        $stmt = $pdo->prepare(
            "SELECT msi.location_id FROM minifig_storage_items msi
             INNER JOIN storage_locations sl ON sl.id = msi.location_id
             WHERE msi.id = ? AND (sl.location_type IS NULL OR sl.location_type NOT IN ('owned_set', 'pick_list'))"
        );
        $stmt->execute([$instanceId]);
        $locationId = $stmt->fetchColumn();
        if ($locationId === false) {
            // The assembled instance is gone/moved elsewhere since this pick
            // list was created — surface as a shortfall rather than fail;
            // the UI offers a "fall back to parts" action for this case.
            return ['done' => false, 'steps' => [], 'shortfall' => $remaining];
        }
        return ['done' => false, 'steps' => [[
            'location_id' => (int) $locationId,
            'location_path' => getStorageLocationPath((int) $locationId),
            'available' => 1,
            'suggested_pick' => 1,
        ]], 'shortfall' => 0];
    }

    $rows = getPartStock((int) $pickListItem['part_id'], (int) $pickListItem['color_id']);
    usort($rows, fn (array $a, array $b): int => $a['location_id'] <=> $b['location_id']);

    $steps = [];
    foreach ($rows as $row) {
        if ($remaining <= 0) {
            break;
        }
        $usable = $row['quantity'] - $row['damaged_quantity'];
        if ($usable <= 0) {
            continue;
        }
        $pick = min($usable, $remaining);
        $steps[] = [
            'location_id' => $row['location_id'],
            'location_path' => $row['location_path'],
            'available' => $usable,
            'suggested_pick' => $pick,
        ];
        $remaining -= $pick;
    }

    return ['done' => false, 'steps' => $steps, 'shortfall' => $remaining];
}

/**
 * The single atomic "user tapped Picked" write path — always re-reads fresh
 * state (never trusts what the picking screen showed a moment ago), commits
 * everything in one local transaction, and marks the pick list 'completed'
 * once every item is fully picked. Every pick action fully committing on its
 * own (no client/session state involved) is the entire mechanism behind
 * "interrupt and resume later, possibly on another device".
 *
 * @return array{pickedQuantity:int, remaining:int}
 */
function pickItem(PDO $pdo, int $pickListId, int $pickListItemId, ?int $sourceLocationId, int $quantity, ?int $userId): array
{
    $pickList = getPickList($pdo, $pickListId);
    $item = getPickListItem($pdo, $pickListItemId);
    if ($pickList === null || $item === null || (int) $item['pick_list_id'] !== $pickListId) {
        throw new RuntimeException('Pick list item not found.');
    }

    if ($item['item_type'] === 'minifig') {
        $instanceId = $item['source_minifig_storage_item_id'];
        $stmt = $pdo->prepare(
            "SELECT msi.location_id FROM minifig_storage_items msi
             INNER JOIN storage_locations sl ON sl.id = msi.location_id
             WHERE msi.id = ? AND (sl.location_type IS NULL OR sl.location_type NOT IN ('owned_set', 'pick_list'))"
        );
        $stmt->execute([$instanceId]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException('This assembled minifig is no longer available to pick.');
        }
        moveMinifigStorageItemInstance((int) $instanceId, (int) $pickList['location_id']);
        $pdo->prepare('UPDATE pick_list_items SET picked_quantity = needed_quantity WHERE id = ?')->execute([$pickListItemId]);
        maybeCompletePickList($pdo, $pickListId);
        return ['pickedQuantity' => (int) $item['needed_quantity'], 'remaining' => 0];
    }

    if ($sourceLocationId === null || $quantity < 0) {
        throw new RuntimeException('Invalid pick request.');
    }

    $freshRows = getPartStock((int) $item['part_id'], (int) $item['color_id']);
    $freshRow = null;
    foreach ($freshRows as $row) {
        if ($row['location_id'] === $sourceLocationId) {
            $freshRow = $row;
            break;
        }
    }
    if ($freshRow === null) {
        throw new RuntimeException('That location no longer has this part in stock.');
    }

    if ($quantity === 0) {
        // The record says stock is here, but it physically isn't (a real
        // discrepancy — see the "Inventur vorschlagen" flag for the same
        // situation) — correct this location's record to empty rather than
        // erroring out. Logged as the setStorageItemQuantity() default
        // 'correction' movement (not a move_out/move_in pair — nothing is
        // relocating, this is fixing a wrong number), and nothing here
        // touches pick_list_items.picked_quantity since nothing was picked.
        // getPartStock() only ever returns quantity>0 rows, so this location
        // simply stops being offered for this part+color afterward —
        // getPickStepsForItem()'s next call naturally moves on to whichever
        // location comes next, or a shortfall if none remain.
        setStorageItemQuantity($sourceLocationId, (int) $item['part_id'], (int) $item['color_id'], $freshRow['condition_type'], 0, $userId, 0);
        $remaining = (int) $item['needed_quantity'] - (int) $item['picked_quantity'];
        return ['pickedQuantity' => (int) $item['picked_quantity'], 'remaining' => $remaining];
    }

    $usable = $freshRow['quantity'] - $freshRow['damaged_quantity'];
    $remaining = (int) $item['needed_quantity'] - (int) $item['picked_quantity'];
    $consume = min($quantity, $usable, max(0, $remaining));
    if ($consume <= 0) {
        throw new RuntimeException('Nothing left to pick here.');
    }

    // No outer transaction here: setStorageItemQuantity()/addStorageStock()
    // each already wrap their own body in a beginTransaction()/commit() pair
    // (src/storage.php) — PDO doesn't support nesting those, a second
    // beginTransaction() while one is active throws "There is already an
    // active transaction". Each call is already its own atomically-committed
    // step, matching this app's existing convention for multi-step stock
    // operations (see buildMinifigFromStock() in src/build.php, which has no
    // enclosing transaction either, for the same reason).
    $movementId = setStorageItemQuantity(
        $sourceLocationId, (int) $item['part_id'], (int) $item['color_id'], $freshRow['condition_type'],
        $freshRow['quantity'] - $consume, $userId, $freshRow['damaged_quantity'], 'move_out'
    );
    addStorageStock(
        (int) $pickList['location_id'], (int) $item['part_id'], (int) $item['color_id'], $freshRow['condition_type'],
        $consume, $userId, 'move_in', $movementId
    );
    $pdo->prepare('UPDATE pick_list_items SET picked_quantity = picked_quantity + ? WHERE id = ?')
        ->execute([$consume, $pickListItemId]);

    maybeCompletePickList($pdo, $pickListId);
    return ['pickedQuantity' => (int) $item['picked_quantity'] + $consume, 'remaining' => max(0, $remaining - $consume)];
}

function maybeCompletePickList(PDO $pdo, int $pickListId): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pick_list_items WHERE pick_list_id = ? AND picked_quantity < needed_quantity');
    $stmt->execute([$pickListId]);
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->prepare("UPDATE pick_lists SET status = 'completed', completed_at = NOW() WHERE id = ? AND status = 'active'")
            ->execute([$pickListId]);
    }
}

/**
 * One suggested put-away destination per part+color still sitting at a pick
 * list's own location: wherever that exact part+color already lives
 * elsewhere in storage (getPartStock()) — "put it back where its siblings
 * already are" needs no typing in the common case. Falls back to null
 * (pick manually) the first time a part+color has never been stored
 * anywhere else.
 *
 * @return array<int, array{part_id:int, color_id:?int, condition_type:string, quantity:int, suggested_location_id:?int, suggested_location_path:?string}>
 */
function getPutAwaySuggestions(PDO $pdo, int $pickListId): array
{
    $pickList = getPickList($pdo, $pickListId);
    if ($pickList === null) {
        return [];
    }
    $stock = getLocationStock((int) $pickList['location_id']);
    $suggestions = [];
    foreach ($stock as $row) {
        $existing = $row['color_id'] !== null ? getPartStock($row['part_id'], $row['color_id']) : [];
        $existing = array_values(array_filter($existing, fn (array $r): bool => $r['location_id'] !== (int) $pickList['location_id']));
        $suggestions[] = [
            'part_id' => $row['part_id'],
            'color_id' => $row['color_id'],
            'condition_type' => $row['condition_type'],
            'quantity' => $row['quantity'],
            'suggested_location_id' => $existing[0]['location_id'] ?? null,
            'suggested_location_path' => $existing[0]['location_path'] ?? null,
        ];
    }
    return $suggestions;
}

function putAwayItem(PDO $pdo, int $pickListId, int $partId, int $colorId, string $conditionType, int $quantity, int $destinationLocationId, ?int $userId): void
{
    $pickList = getPickList($pdo, $pickListId);
    if ($pickList === null) {
        throw new RuntimeException('Pick list not found.');
    }
    if ($quantity <= 0) {
        throw new RuntimeException('Invalid quantity.');
    }

    $rows = getLocationStock((int) $pickList['location_id']);
    $current = null;
    foreach ($rows as $row) {
        if ($row['part_id'] === $partId && $row['color_id'] === $colorId && $row['condition_type'] === $conditionType) {
            $current = $row;
            break;
        }
    }
    if ($current === null || $current['quantity'] < $quantity) {
        throw new RuntimeException('Not enough stock at the pick list to put away.');
    }

    // No outer transaction — same reasoning as pickItem() above.
    $movementId = setStorageItemQuantity(
        (int) $pickList['location_id'], $partId, $colorId, $conditionType,
        $current['quantity'] - $quantity, $userId, null, 'move_out'
    );
    addStorageStock($destinationLocationId, $partId, $colorId, $conditionType, $quantity, $userId, 'move_in', $movementId);

    closePickListIfEmpty($pdo, $pickListId);
}

/**
 * Closes a pick list once its own location genuinely holds nothing anymore
 * (neither part stock nor an assembled minifig instance) — the
 * storage_locations row itself is left in place afterwards as a historical
 * record rather than deleted, since deleting it would orphan any
 * storage_movements rows still pointing at it (location_id is
 * ON DELETE SET NULL there, which would silently corrupt the audit trail's
 * readability). A 'closed' list just stops showing up as active by default.
 * Called from both consumption paths a pick list can end at: putAwayItem()
 * and fulfillOwnedSetFromPickList().
 */
function closePickListIfEmpty(PDO $pdo, int $pickListId): void
{
    $pickList = getPickList($pdo, $pickListId);
    if ($pickList === null || $pickList['status'] === 'closed') {
        return;
    }
    $hasParts = !empty(getLocationStock((int) $pickList['location_id']));
    $hasMinifigs = (int) $pdo->query(
        'SELECT COUNT(*) FROM minifig_storage_items WHERE location_id = ' . (int) $pickList['location_id']
    )->fetchColumn() > 0;
    if (!$hasParts && !$hasMinifigs) {
        $pdo->prepare("UPDATE pick_lists SET status = 'closed', closed_at = NOW() WHERE id = ?")->execute([$pickListId]);
    }
}

/**
 * Consumes as much of a set's needed parts/minifigs as a given pick list
 * currently holds, moving them into the new owned_set instance's own
 * location instead of conjuring fresh stock for that portion — called from
 * addOwnedSet() (src/owned_sets.php) when the add-to-collection wizard's
 * user picked an existing pick list as a fulfillment source. Any shortfall
 * beyond what the pick list holds is left for the caller to materialize
 * fresh exactly as it already does by default — partial pick-list coverage
 * plus a fresh top-up is the expected, normal case, not an error.
 *
 * owned_set_minifigs itself needs no special handling here (it's a purely
 * aggregate table, no per-instance rows to "move") — the only real
 * correctness requirement is not double-counting a physical minifig, so an
 * assembled instance credited here is deleted from minifig_storage_items,
 * not left behind as if it were still separately-owned loose stock.
 *
 * @return array<string,int> "part_id:color_id" => quantity consumed from the pick list
 */
function fulfillOwnedSetFromPickList(PDO $pdo, int $pickListId, int $inventoryId, int $newOwnedSetLocationId, string $conditionType, ?int $userId): array
{
    $pickList = getPickList($pdo, $pickListId);
    if ($pickList === null) {
        return [];
    }
    $fulfilled = [];

    foreach (getSetPartsList($pdo, $inventoryId, false, 'en') as $need) {
        if ($need['color_id'] === null || $need['quantity'] <= 0) {
            continue;
        }
        $atPickList = getLocationStock((int) $pickList['location_id']);
        $atPickList = array_filter($atPickList, fn (array $r): bool => $r['part_id'] === $need['part_id'] && $r['color_id'] === $need['color_id']);
        $available = array_sum(array_column($atPickList, 'quantity'));
        $consume = min($available, $need['quantity']);
        if ($consume <= 0) {
            continue;
        }
        // A part+color can sit at the pick list under more than one
        // condition_type — walk each row so the correct condition ends up
        // at the new owned_set location too, rather than assuming one.
        $remainingToConsume = $consume;
        foreach ($atPickList as $row) {
            if ($remainingToConsume <= 0) {
                break;
            }
            $take = min($row['quantity'], $remainingToConsume);
            $movementId = setStorageItemQuantity(
                (int) $pickList['location_id'], $need['part_id'], $need['color_id'], $row['condition_type'],
                $row['quantity'] - $take, $userId, null, 'move_out'
            );
            addStorageStock($newOwnedSetLocationId, $need['part_id'], $need['color_id'], $row['condition_type'], $take, $userId, 'move_in', $movementId);
            $remainingToConsume -= $take;
        }
        $fulfilled[$need['part_id'] . ':' . $need['color_id']] = $consume;
    }

    foreach (getSetMinifigsList($pdo, $inventoryId) as $fig) {
        $stmt = $pdo->prepare('SELECT id FROM minifig_storage_items WHERE location_id = ? AND minifig_id = ? LIMIT ' . (int) $fig['quantity']);
        $stmt->execute([(int) $pickList['location_id'], $fig['minifig_id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $instanceId) {
            deleteMinifigStorageItemInstance((int) $instanceId);
        }
    }

    closePickListIfEmpty($pdo, $pickListId);
    return $fulfilled;
}

/**
 * Catalog set_detail's "Bauteile auf Pickliste setzen" dialog
 * (?page=set_detail, src/routes/pages.php) — a single-step modal (own
 * fetch()-driven checklist, not a page reload) that loads
 * getSetAvailablePartsForPickList()'s result on open, pre-checks every row,
 * and lets the user uncheck specific parts before submitting to
 * action=create_pick_list_from_set_available (src/routes/actions.php),
 * which re-validates quantities server-side rather than trusting this
 * dialog's snapshot. Same .modal-overlay/.modal-box shell as
 * renderAddOwnedSetWizardModal()/renderPartDetailModal() — the caller just
 * embeds the returned HTML.
 */
function renderCreatePickListFromSetModal(int $setId): string
{
    $html = '<div class="modal-overlay" id="set-pick-list-modal" style="display:none;">';
    $html .= '<div class="modal-box">';
    $html .= '<div class="owned-set-wizard-header">';
    $html .= '<h2>' . htmlspecialchars(t('set_pick_list_modal_heading')) . '</h2>';
    $html .= '<button type="button" class="modal-close" id="set-pick-list-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '</div>';
    $html .= '<div id="set-pick-list-form">';
    $html .= '<label class="set-pick-list-name-label">' . htmlspecialchars(t('set_pick_list_name_label'));
    $html .= '<input type="text" id="set-pick-list-name-input"></label>';
    $html .= '<div id="set-pick-list-parts" class="set-pick-list-parts">' . htmlspecialchars(t('set_pick_list_loading')) . '</div>';
    $html .= '<p class="owned-set-wizard-error" id="set-pick-list-error"></p>';
    $html .= '<div class="owned-set-wizard-nav">';
    $html .= '<button type="button" id="set-pick-list-submit">' . htmlspecialchars(t('set_pick_list_submit_button')) . '</button>';
    $html .= '</div>';
    $html .= '</div>';
    // Shown in place of the form after a successful create — no more
    // automatic navigation into /pick/ (per explicit feedback: the user
    // stays on set_detail and decides for themselves whether/when to go
    // start picking).
    $html .= '<div id="set-pick-list-success" style="display:none;">';
    $html .= '<p id="set-pick-list-success-message"></p>';
    $html .= '<div class="owned-set-wizard-nav">';
    $html .= '<a class="owned-set-action-pill" id="set-pick-list-success-open" href="#">' . htmlspecialchars(t('set_pick_list_go_to_button')) . '</a>';
    $html .= '<button type="button" id="set-pick-list-success-close">' . htmlspecialchars(t('close_button')) . '</button>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div></div>';

    $setIdJson = json_encode($setId);
    $errorGenericJson = json_encode(t('pick_error_generic'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $emptyJson = json_encode(t('set_pick_list_empty'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $html .= <<<SCRIPT
<script>
(function(){
  var setId = $setIdJson;
  var openBtn = document.getElementById('set-pick-list-open');
  var modal = document.getElementById('set-pick-list-modal');
  var closeBtn = document.getElementById('set-pick-list-modal-close');
  var nameInput = document.getElementById('set-pick-list-name-input');
  var partsBox = document.getElementById('set-pick-list-parts');
  var errorEl = document.getElementById('set-pick-list-error');
  var submitBtn = document.getElementById('set-pick-list-submit');
  var formBox = document.getElementById('set-pick-list-form');
  var successBox = document.getElementById('set-pick-list-success');
  var successMessage = document.getElementById('set-pick-list-success-message');
  var successOpenLink = document.getElementById('set-pick-list-success-open');
  var successCloseBtn = document.getElementById('set-pick-list-success-close');
  if (!openBtn || !modal) { return; }

  function showForm() {
    formBox.style.display = 'block';
    successBox.style.display = 'none';
    submitBtn.disabled = false;
  }

  function loadParts() {
    partsBox.textContent = $emptyJson;
    var params = new URLSearchParams();
    params.set('action', 'set_available_parts_for_pick_list');
    params.set('set_id', String(setId));
    fetch('?' + params.toString(), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (!res.success) {
          partsBox.textContent = res.message || $errorGenericJson;
          return;
        }
        nameInput.value = res.defaultDescription || '';
        if (!res.parts.length) {
          partsBox.textContent = $emptyJson;
          return;
        }
        partsBox.innerHTML = '';
        res.parts.forEach(function(part) {
          var row = document.createElement('div');
          row.className = 'set-pick-list-part-row';
          var checkbox = document.createElement('input');
          checkbox.type = 'checkbox';
          checkbox.checked = true;
          checkbox.dataset.key = part.part_id + ':' + part.color_id;
          row.appendChild(checkbox);
          if (part.thumbnail) {
            var img = document.createElement('img');
            img.src = part.thumbnail;
            row.appendChild(img);
          }
          var text = document.createElement('span');
          text.className = 'set-pick-list-part-name';
          text.textContent = part.part_num + ' ' + part.name + (part.color_name ? ' \\u00b7 ' + part.color_name : '');
          row.appendChild(text);
          var qtyInput = document.createElement('input');
          qtyInput.type = 'number';
          qtyInput.className = 'set-pick-list-part-qty';
          qtyInput.min = '1';
          qtyInput.max = String(part.needed_quantity);
          qtyInput.value = String(part.needed_quantity);
          qtyInput.dataset.key = part.part_id + ':' + part.color_id;
          row.appendChild(qtyInput);
          checkbox.addEventListener('change', function() { qtyInput.disabled = !checkbox.checked; });
          partsBox.appendChild(row);
        });
      })
      .catch(function() {
        partsBox.textContent = $errorGenericJson;
      });
  }

  openBtn.addEventListener('click', function(e) {
    e.preventDefault();
    errorEl.textContent = '';
    showForm();
    modal.style.display = 'flex';
    loadParts();
  });
  if (closeBtn) {
    closeBtn.addEventListener('click', function() { modal.style.display = 'none'; });
  }
  if (successCloseBtn) {
    successCloseBtn.addEventListener('click', function() { modal.style.display = 'none'; });
  }

  submitBtn.addEventListener('click', function() {
    errorEl.textContent = '';
    var description = nameInput.value.trim();
    var checkedBoxes = Array.prototype.slice.call(partsBox.querySelectorAll('input[type=checkbox]:checked'));
    if (!description || !checkedBoxes.length) {
      errorEl.textContent = $errorGenericJson;
      return;
    }
    submitBtn.disabled = true;
    var formData = new FormData();
    formData.set('action', 'create_pick_list_from_set_available');
    formData.set('set_id', String(setId));
    formData.set('description', description);
    checkedBoxes.forEach(function(cb) {
      var qtyInput = partsBox.querySelector('input.set-pick-list-part-qty[data-key="' + cb.dataset.key + '"]');
      var qty = qtyInput ? (parseInt(qtyInput.value, 10) || 0) : 0;
      if (qty > 0) {
        formData.set('quantities[' + cb.dataset.key + ']', String(qty));
      }
    });
    fetch('?action=create_pick_list_from_set_available', { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (!res.success) {
          submitBtn.disabled = false;
          errorEl.textContent = res.message || $errorGenericJson;
          return;
        }
        successMessage.textContent = res.message;
        successOpenLink.href = 'pick/index.php?screen=pick&id=' + res.pickListId;
        formBox.style.display = 'none';
        successBox.style.display = 'block';
      })
      .catch(function() {
        submitBtn.disabled = false;
        errorEl.textContent = $errorGenericJson;
      });
  });
})();
</script>
SCRIPT;

    return $html;
}
