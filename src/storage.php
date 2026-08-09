<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * $type is only ever a real user-facing classification for backward
 * compatibility with rows created before that concept was removed — the
 * only value anything in this codebase still sets deliberately is the
 * internal 'owned_set' marker (see addOwnedSet() in src/owned_sets.php),
 * never exposed to or chosen by a user. Kept as a parameter (not just
 * hardcoded to null here) purely so that one caller can still pass it.
 */
function createStorageLocation(?int $parentId, string $name, ?string $type = null): int
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO storage_locations (parent_id, name, location_type) VALUES (?, ?, ?)');
    $stmt->execute([$parentId, $name, $type]);
    return (int) $pdo->lastInsertId();
}

function renameStorageLocation(int $id, string $name): void
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('UPDATE storage_locations SET name = ? WHERE id = ?');
    $stmt->execute([$name, $id]);
}

/**
 * Creates $childCount numbered locations (e.g. "Fach 1".."Fach 8" from the
 * pattern "Fach {n}") as direct children of $parentId — the add-location
 * modal's bulk checkbox uses the name field itself as $namingPattern (no
 * separate "naming pattern" field). Runs in a transaction so a failure
 * partway through can't leave a half-created set behind.
 */
function createBulkStorageLocations(?int $parentId, int $childCount, string $namingPattern): void
{
    $pdo = getPDO();
    $pdo->beginTransaction();
    try {
        for ($i = 1; $i <= $childCount; $i++) {
            $childName = str_replace('{n}', (string) $i, $namingPattern);
            createStorageLocation($parentId, $childName);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Re-parents a location node — used by owned_set_detail's "Verschieben"
 * action to move a set instance's storage node to a different place in the
 * tree (the node itself, its stock, and its parts stay exactly as they are;
 * only where it hangs in the hierarchy changes). Refuses a move into the
 * node itself or into one of its own descendants (walks $newParentId's
 * ancestors via getStorageLocationAncestors() — a cycle would make the
 * tree unwalkable everywhere else that assumes it's acyclic).
 */
function moveStorageLocation(int $id, ?int $newParentId): void
{
    if ($newParentId === $id) {
        throw new RuntimeException('Ein Lagerort kann nicht in sich selbst verschoben werden.');
    }
    if ($newParentId !== null) {
        foreach (getStorageLocationAncestors($newParentId) as $ancestor) {
            if ($ancestor['id'] === $id) {
                throw new RuntimeException('Ein Lagerort kann nicht in einen seiner eigenen Unterordner verschoben werden.');
            }
        }
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare('UPDATE storage_locations SET parent_id = ? WHERE id = ?');
    $stmt->execute([$newParentId, $id]);
}

function deleteStorageLocation(int $id): void
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('DELETE FROM storage_locations WHERE id = ?');
    $stmt->execute([$id]);
}

function getStorageLocation(int $id): ?array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, parent_id, name, location_type FROM storage_locations WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function locationHasChildren(int $id): bool
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM storage_locations WHERE parent_id = ?');
    $stmt->execute([$id]);
    return ((int) $stmt->fetchColumn()) > 0;
}

/**
 * Same as locationHasChildren(), but ignores owned-set instance nodes
 * (location_type 'owned_set', auto-created by addOwnedSet() in
 * src/owned_sets.php whenever a set is added to the collection under this
 * location). Those aren't a real organizational sub-area the user created —
 * a location that happens to have a boxed set sitting in it should still be
 * usable as a genuine spot to add loose parts alongside that box, so the
 * "add stock" flow's leaf check uses this instead of locationHasChildren().
 * locationHasChildren() itself stays as-is for e.g. the "delete location"
 * guard, where an owned set living there absolutely should block deletion.
 */
function locationHasNonOwnedSetChildren(int $id): bool
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM storage_locations WHERE parent_id = ? AND (location_type IS NULL OR location_type != 'owned_set')"
    );
    $stmt->execute([$id]);
    return ((int) $stmt->fetchColumn()) > 0;
}

function locationHasStock(int $id): bool
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM storage_items WHERE location_id = ?');
    $stmt->execute([$id]);
    return ((int) $stmt->fetchColumn()) > 0;
}

/**
 * Reconstructs "Büro -> Kleinteilesortiment #1 -> Schublade 5" by walking
 * parent_id in PHP rather than a recursive CTE — MariaDB versions on shared
 * hosting can't be assumed to support WITH RECURSIVE (10.2+ only).
 */
function getStorageLocationPath(int $id): string
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT name, parent_id FROM storage_locations WHERE id = ?');
    $parts = [];
    $current = $id;
    $guard = 0;
    while ($current !== null && $guard++ < 50) {
        $stmt->execute([$current]);
        $row = $stmt->fetch();
        if (!$row) {
            break;
        }
        array_unshift($parts, $row['name']);
        $current = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
    }
    return implode(' -> ', $parts);
}

/**
 * Same walk as getStorageLocationPath(), but returns each ancestor's id
 * alongside its name (root first, the location itself last) — for building
 * clickable breadcrumb links to each ancestor's own location_detail page,
 * where the plain concatenated-string path isn't enough.
 *
 * @return array<int, array{id:int, name:string}>
 */
function getStorageLocationAncestors(int $id): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, name, parent_id FROM storage_locations WHERE id = ?');
    $ancestors = [];
    $current = $id;
    $guard = 0;
    while ($current !== null && $guard++ < 50) {
        $stmt->execute([$current]);
        $row = $stmt->fetch();
        if (!$row) {
            break;
        }
        array_unshift($ancestors, ['id' => (int) $row['id'], 'name' => $row['name']]);
        $current = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
    }
    return $ancestors;
}

/**
 * Excludes owned-set instance nodes (location_type 'owned_set') — see
 * getChildLocations()'s doc comment for why: they're not real organizational
 * locations, so they don't belong in the general "Lagerort-Übersicht" tree.
 *
 * @return array<int, array{id:int, parent_id:?int, name:string, location_type:?string, children: array}>
 */
function getStorageLocationTree(): array
{
    $pdo = getPDO();
    $rows = $pdo->query("SELECT id, parent_id, name, location_type FROM storage_locations WHERE location_type IS NULL OR location_type != 'owned_set'")->fetchAll();
    // Natural sort ("Fach 2" before "Fach 10"), not SQL's plain lexicographic
    // ORDER BY — confirmed on real data (a shelf cabinet's numbered
    // compartments showed up as Fach 1, Fach 10, Fach 11, ..., Fach 2).
    // Sorting the flat list before the tree below is built (rather than each
    // node's 'children' array after) is enough: children are appended in
    // the order this loop encounters them, which follows $rows' order.
    usort($rows, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

    $byId = [];
    foreach ($rows as $row) {
        $row['children'] = [];
        $byId[(int) $row['id']] = $row;
    }

    $tree = [];
    foreach ($byId as &$node) {
        $parentId = $node['parent_id'] !== null ? (int) $node['parent_id'] : null;
        if ($parentId !== null && isset($byId[$parentId])) {
            $byId[$parentId]['children'][] = &$node;
        } else {
            $tree[] = &$node;
        }
    }
    unset($node);

    return $tree;
}

/**
 * Immediate children of a location (or the top-level rooms when $parentId is
 * null) — used to populate one level of the "add to inventory" cascading
 * location picker at a time, rather than shipping the whole tree to the
 * client. Owned-set instance nodes (location_type 'owned_set') are excluded:
 * they're auto-generated bookkeeping for a physically boxed set, not a real
 * pickable storage spot — showing up here would let someone nest a new
 * location, or a new owned set, "inside" an existing one, which never made
 * sense. They're meant to resurface later in a dedicated "what can I build"
 * view, not in general location browsing/picking.
 */
function getChildLocations(?int $parentId): array
{
    $pdo = getPDO();
    if ($parentId === null) {
        $stmt = $pdo->query("SELECT id, name, location_type FROM storage_locations WHERE parent_id IS NULL AND (location_type IS NULL OR location_type != 'owned_set')");
    } else {
        $stmt = $pdo->prepare("SELECT id, name, location_type FROM storage_locations WHERE parent_id = ? AND (location_type IS NULL OR location_type != 'owned_set')");
        $stmt->execute([$parentId]);
    }
    $rows = $stmt->fetchAll();
    // Natural sort, not SQL's plain lexicographic ORDER BY — same reasoning
    // as getStorageLocationTree()'s.
    usort($rows, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    return $rows;
}

/**
 * Adds quantity to a specific location/part/color/condition combination
 * (upsert on the existing UNIQUE KEY) and writes a matching audit row to
 * storage_movements. Returns the resulting total quantity at that spot.
 */
function addStorageStock(int $locationId, int $partId, int $colorId, string $conditionType, int $quantity, ?int $userId): int
{
    $pdo = getPDO();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO storage_items (location_id, part_id, color_id, condition_type, quantity)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
        );
        $stmt->execute([$locationId, $partId, $colorId, $conditionType, $quantity]);

        $resultStmt = $pdo->prepare(
            'SELECT quantity FROM storage_items WHERE location_id = ? AND part_id = ? AND color_id = ? AND condition_type = ?'
        );
        $resultStmt->execute([$locationId, $partId, $colorId, $conditionType]);
        $resultingQuantity = (int) $resultStmt->fetchColumn();

        $moveStmt = $pdo->prepare(
            'INSERT INTO storage_movements (user_id, location_id, part_id, color_id, condition_type, movement_type, quantity_change, resulting_quantity)
             VALUES (?, ?, ?, ?, ?, \'in\', ?, ?)'
        );
        $moveStmt->execute([$userId, $locationId, $partId, $colorId, $conditionType, $quantity, $resultingQuantity]);

        $pdo->commit();
        return $resultingQuantity;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Sets the exact quantity for one part+color+condition at a location
 * (unlike addStorageStock(), which is additive) — used for corrections,
 * e.g. recording that some of a part is missing from an owned set.
 * Inserts the row if it doesn't exist yet; a resulting quantity of 0 still
 * leaves the row in place rather than deleting it (getLocationStock() and
 * getPartStock() already filter zero-quantity rows out of their results).
 */
function setStorageItemQuantity(int $locationId, int $partId, int $colorId, string $conditionType, int $newQuantity, ?int $userId, ?int $damagedQuantity = null): void
{
    $pdo = getPDO();
    $pdo->beginTransaction();
    try {
        $currentStmt = $pdo->prepare(
            'SELECT quantity FROM storage_items WHERE location_id = ? AND part_id = ? AND color_id = ? AND condition_type = ?'
        );
        $currentStmt->execute([$locationId, $partId, $colorId, $conditionType]);
        $current = $currentStmt->fetchColumn();
        $currentQuantity = $current !== false ? (int) $current : 0;

        if ($current === false) {
            $insertStmt = $pdo->prepare(
                'INSERT INTO storage_items (location_id, part_id, color_id, condition_type, quantity, damaged_quantity) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insertStmt->execute([$locationId, $partId, $colorId, $conditionType, $newQuantity, $damagedQuantity ?? 0]);
        } elseif ($damagedQuantity !== null) {
            $updateStmt = $pdo->prepare(
                'UPDATE storage_items SET quantity = ?, damaged_quantity = ? WHERE location_id = ? AND part_id = ? AND color_id = ? AND condition_type = ?'
            );
            $updateStmt->execute([$newQuantity, $damagedQuantity, $locationId, $partId, $colorId, $conditionType]);
        } else {
            $updateStmt = $pdo->prepare(
                'UPDATE storage_items SET quantity = ? WHERE location_id = ? AND part_id = ? AND color_id = ? AND condition_type = ?'
            );
            $updateStmt->execute([$newQuantity, $locationId, $partId, $colorId, $conditionType]);
        }

        $moveStmt = $pdo->prepare(
            'INSERT INTO storage_movements (user_id, location_id, part_id, color_id, condition_type, movement_type, quantity_change, resulting_quantity)
             VALUES (?, ?, ?, ?, ?, \'correction\', ?, ?)'
        );
        $moveStmt->execute([$userId, $locationId, $partId, $colorId, $conditionType, $newQuantity - $currentQuantity, $newQuantity]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Moves an entire storage_items row (quantity, damaged_quantity,
 * spare_quantity, spare_damaged_quantity — everything on it, not just the
 * regular quantity) from one location to another, merging into whatever's
 * already at the destination for the same part+color+condition. A no-op if
 * the row doesn't exist at $fromLocationId, or if source and destination are
 * the same location. Writes a linked move_out/move_in pair to
 * storage_movements (related_movement_id ties them together), unlike
 * setStorageItemQuantity()'s single 'correction' row.
 */
function moveStorageItem(int $fromLocationId, int $toLocationId, int $partId, int $colorId, string $conditionType, ?int $userId): void
{
    if ($fromLocationId === $toLocationId) {
        return;
    }
    $pdo = getPDO();
    $pdo->beginTransaction();
    try {
        $sourceStmt = $pdo->prepare(
            'SELECT quantity, damaged_quantity, spare_quantity, spare_damaged_quantity
             FROM storage_items WHERE location_id = ? AND part_id = ? AND color_id = ? AND condition_type = ?'
        );
        $sourceStmt->execute([$fromLocationId, $partId, $colorId, $conditionType]);
        $source = $sourceStmt->fetch();
        if ($source === false) {
            $pdo->rollBack();
            return;
        }

        $pdo->prepare(
            'DELETE FROM storage_items WHERE location_id = ? AND part_id = ? AND color_id = ? AND condition_type = ?'
        )->execute([$fromLocationId, $partId, $colorId, $conditionType]);

        $upsertStmt = $pdo->prepare(
            'INSERT INTO storage_items (location_id, part_id, color_id, condition_type, quantity, damaged_quantity, spare_quantity, spare_damaged_quantity)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                quantity = quantity + VALUES(quantity),
                damaged_quantity = damaged_quantity + VALUES(damaged_quantity),
                spare_quantity = spare_quantity + VALUES(spare_quantity),
                spare_damaged_quantity = spare_damaged_quantity + VALUES(spare_damaged_quantity)'
        );
        $upsertStmt->execute([
            $toLocationId, $partId, $colorId, $conditionType,
            $source['quantity'], $source['damaged_quantity'], $source['spare_quantity'], $source['spare_damaged_quantity'],
        ]);

        $resultStmt = $pdo->prepare(
            'SELECT quantity FROM storage_items WHERE location_id = ? AND part_id = ? AND color_id = ? AND condition_type = ?'
        );
        $resultStmt->execute([$toLocationId, $partId, $colorId, $conditionType]);
        $resultingQuantity = (int) $resultStmt->fetchColumn();

        $outStmt = $pdo->prepare(
            'INSERT INTO storage_movements (user_id, location_id, part_id, color_id, condition_type, movement_type, quantity_change, resulting_quantity)
             VALUES (?, ?, ?, ?, ?, \'move_out\', ?, 0)'
        );
        $outStmt->execute([$userId, $fromLocationId, $partId, $colorId, $conditionType, -(int) $source['quantity']]);
        $outId = (int) $pdo->lastInsertId();

        $inStmt = $pdo->prepare(
            'INSERT INTO storage_movements (user_id, location_id, part_id, color_id, condition_type, movement_type, quantity_change, resulting_quantity, related_movement_id)
             VALUES (?, ?, ?, ?, ?, \'move_in\', ?, ?, ?)'
        );
        $inStmt->execute([$userId, $toLocationId, $partId, $colorId, $conditionType, (int) $source['quantity'], $resultingQuantity, $outId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Single entry point for the location Explorer's "edit" card: applies a
 * quantity correction (if changed) at the item's current location, then —
 * only if a different location was actually chosen — moves the (now
 * corrected) row there in one coherent step, rather than treating quantity
 * and location as two separately-reasoned edits.
 */
function updateStorageItem(int $locationId, int $partId, int $colorId, string $conditionType, int $newQuantity, ?int $newLocationId, ?int $userId): void
{
    setStorageItemQuantity($locationId, $partId, $colorId, $conditionType, $newQuantity, $userId);
    if ($newLocationId !== null && $newLocationId !== $locationId) {
        moveStorageItem($locationId, $newLocationId, $partId, $colorId, $conditionType, $userId);
    }
}

/**
 * Same shape as setStorageItemQuantity(), but for the spare_quantity/
 * spare_damaged_quantity columns — a set's spare and regular pool can be
 * the exact same part+color, so spares get their own pair of columns on
 * the same row rather than colliding with the regular quantity. Doesn't
 * write to storage_movements: that log's quantity_change/resulting_quantity
 * are specifically about the regular-stock dimension, and spares are a
 * separate one — mixing them in would make the log misleading rather than
 * more complete.
 */
function setStorageItemSpareQuantity(int $locationId, int $partId, int $colorId, string $conditionType, int $newSpareQuantity, ?int $spareDamagedQuantity = null): void
{
    $pdo = getPDO();
    $currentStmt = $pdo->prepare(
        'SELECT id FROM storage_items WHERE location_id = ? AND part_id = ? AND color_id = ? AND condition_type = ?'
    );
    $currentStmt->execute([$locationId, $partId, $colorId, $conditionType]);
    $exists = $currentStmt->fetchColumn() !== false;

    if (!$exists) {
        $insertStmt = $pdo->prepare(
            'INSERT INTO storage_items (location_id, part_id, color_id, condition_type, spare_quantity, spare_damaged_quantity) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insertStmt->execute([$locationId, $partId, $colorId, $conditionType, $newSpareQuantity, $spareDamagedQuantity ?? 0]);
    } elseif ($spareDamagedQuantity !== null) {
        $updateStmt = $pdo->prepare(
            'UPDATE storage_items SET spare_quantity = ?, spare_damaged_quantity = ? WHERE location_id = ? AND part_id = ? AND color_id = ? AND condition_type = ?'
        );
        $updateStmt->execute([$newSpareQuantity, $spareDamagedQuantity, $locationId, $partId, $colorId, $conditionType]);
    } else {
        $updateStmt = $pdo->prepare(
            'UPDATE storage_items SET spare_quantity = ? WHERE location_id = ? AND part_id = ? AND color_id = ? AND condition_type = ?'
        );
        $updateStmt->execute([$newSpareQuantity, $locationId, $partId, $colorId, $conditionType]);
    }
}

/**
 * Whole-catalog loose stock, summed per part+color (surrogate colors.id,
 * matching storage_items.color_id) — how many of a given part+color are
 * free to grab for a build right now, ignoring condition_type and location.
 * Excludes owned-set instance locations (location_type 'owned_set') the same
 * way getPartStock() below does, since those pieces are "owned" but not
 * really "available" until the set is taken apart. Shared by every feature
 * that needs this same stock map: getBuildableMinifigs() (src/build.php),
 * the "Baubare Sets" scan (initBuildSetsScanState(), src/build_sets.php),
 * and the catalog set_detail inventory tab's stock-availability borders
 * (src/routes/pages.php).
 *
 * @return array<string, int> keyed by "{part_id}:{color_id}", only pairs with stock > 0 are present
 */
function getLooseStockMap(PDO $pdo): array
{
    $stock = [];
    $stmt = $pdo->query(
        "SELECT si.part_id, si.color_id, SUM(si.quantity) - SUM(si.damaged_quantity) AS stock
         FROM storage_items si
         INNER JOIN storage_locations sl ON sl.id = si.location_id
         WHERE sl.location_type IS NULL OR sl.location_type != 'owned_set'
         GROUP BY si.part_id, si.color_id
         HAVING stock > 0"
    );
    foreach ($stmt->fetchAll() as $row) {
        $stock[$row['part_id'] . ':' . $row['color_id']] = (int) $row['stock'];
    }
    return $stock;
}

/**
 * Current LOOSE stock of one part across all storage locations, for the
 * part detail modal's "Teilelager" tab — one row per location/color/
 * condition combo that actually holds stock. Excludes owned-set instance
 * locations (location_type 'owned_set') the same way getLocationSubtreeIds()
 * does, so a part materialized into a set's own inventory doesn't show up
 * here as if it were separately-stored loose stock.
 *
 * @return array<int, array{location_id:int, location_path:string, color_id:?int, color_name:?string, color_rgb:?string, condition_type:string, quantity:int, damaged_quantity:int}>
 */
function getPartStock(int $partId): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        "SELECT si.location_id, si.condition_type, si.quantity, si.damaged_quantity,
                c.id AS color_id, c.name AS color_name, c.rgb AS color_rgb
         FROM storage_items si
         INNER JOIN storage_locations sl ON sl.id = si.location_id
         LEFT JOIN colors c ON c.id = si.color_id
         WHERE si.part_id = ? AND si.quantity > 0
           AND (sl.location_type IS NULL OR sl.location_type != 'owned_set')
         ORDER BY si.location_id"
    );
    $stmt->execute([$partId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['location_id'] = (int) $row['location_id'];
        $row['location_path'] = getStorageLocationPath($row['location_id']);
        $row['color_id'] = $row['color_id'] !== null ? (int) $row['color_id'] : null;
        $row['quantity'] = (int) $row['quantity'];
        $row['damaged_quantity'] = (int) $row['damaged_quantity'];
    }
    unset($row);
    return $rows;
}

/**
 * Per-color totals of one part's stock across BOTH loose locations and
 * owned sets' materialized inventories, for the part detail modal's
 * "Set- und Teilelager" tab overview — one summary card per color, with the
 * per-location/per-set breakdown left to getPartStockDetail() once a card
 * is clicked.
 *
 * @return array<int, array{color_id:?int, color_name:?string, color_rgb:?string, quantity:int}>
 */
function getPartStockSummary(int $partId): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT c.id AS color_id, c.name AS color_name, c.rgb AS color_rgb, SUM(si.quantity) AS quantity
         FROM storage_items si
         LEFT JOIN colors c ON c.id = si.color_id
         WHERE si.part_id = ? AND si.quantity > 0
         GROUP BY c.id, c.name, c.rgb
         ORDER BY c.name IS NULL, c.name'
    );
    $stmt->execute([$partId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['color_id'] = $row['color_id'] !== null ? (int) $row['color_id'] : null;
        $row['quantity'] = (int) $row['quantity'];
    }
    unset($row);
    return $rows;
}

/**
 * Same shape as getPartStock(), but without the loose-only filter, and with
 * owned-set instance rows carrying the owned_sets row they belong to
 * (ownedSetId/setName/setNum) so the "Set- und Teilelager" tab's drill-down
 * can link straight to the set's own detail page instead of location_detail
 * (which owned-set locations were never meant to be reached through — see
 * addOwnedSet()'s doc comment in owned_sets.php). Optionally scoped to one
 * color, for the drill-down from a single summary card.
 *
 * @return array<int, array{location_id:int, location_path:string, color_id:?int, color_name:?string, color_rgb:?string, condition_type:string, quantity:int, ownedSetId:?int, setName:?string, setNum:?string}>
 */
function getPartStockDetail(int $partId, ?int $colorId): array
{
    $pdo = getPDO();
    $sql = 'SELECT si.location_id, si.condition_type, si.quantity,
                   c.id AS color_id, c.name AS color_name, c.rgb AS color_rgb,
                   os.id AS owned_set_id, s.name AS set_name, s.rebrickable_set_num AS set_num
            FROM storage_items si
            LEFT JOIN colors c ON c.id = si.color_id
            LEFT JOIN owned_sets os ON os.location_id = si.location_id
            LEFT JOIN sets s ON s.id = os.set_id
            WHERE si.part_id = ? AND si.quantity > 0';
    $params = [$partId];
    if ($colorId !== null) {
        $sql .= ' AND si.color_id = ?';
        $params[] = $colorId;
    } else {
        $sql .= ' AND si.color_id IS NULL';
    }
    $sql .= ' ORDER BY si.location_id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['location_id'] = (int) $row['location_id'];
        $row['location_path'] = getStorageLocationPath($row['location_id']);
        $row['color_id'] = $row['color_id'] !== null ? (int) $row['color_id'] : null;
        $row['quantity'] = (int) $row['quantity'];
        $row['owned_set_id'] = $row['owned_set_id'] !== null ? (int) $row['owned_set_id'] : null;
    }
    unset($row);
    return $rows;
}

/**
 * All parts/colors currently stored directly at one location — powers the
 * location detail page reached by clicking a location card in a part's
 * "Lager" tab. Thumbnails aren't included here (they need parts.php's
 * batched lookup, a different module) — callers merge those in themselves.
 *
 * @return array<int, array{part_id:int, part_num:string, part_name:string, color_id:?int, color_name:?string, color_rgb:?string, condition_type:string, quantity:int}>
 */
function getLocationStock(int $locationId): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT si.part_id, p.part_num, p.name AS part_name,
                c.id AS color_id, c.name AS color_name, c.rgb AS color_rgb,
                si.condition_type, si.quantity
         FROM storage_items si
         INNER JOIN parts p ON p.id = si.part_id
         LEFT JOIN colors c ON c.id = si.color_id
         WHERE si.location_id = ? AND si.quantity > 0
         ORDER BY p.part_num'
    );
    $stmt->execute([$locationId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['part_id'] = (int) $row['part_id'];
        $row['color_id'] = $row['color_id'] !== null ? (int) $row['color_id'] : null;
        $row['quantity'] = (int) $row['quantity'];
    }
    unset($row);
    return $rows;
}

/**
 * All descendant location ids of $locationId, including itself — excludes
 * owned-set instance locations (location_type 'owned_set', auto-created by
 * addOwnedSet() wherever a set was placed) and anything nested inside them:
 * a set's own spare/damaged parts (materializeOwnedSetStock() et al., src/
 * owned_sets.php) live at that auto-generated node, and the location
 * Explorer is purely for LOOSE stock — a set's own contents (and the set
 * itself) are only ever seen via its own inventory page, never here.
 *
 * A per-level walk, not a recursive CTE, for the same shared-hosting
 * MariaDB-version reason as getStorageLocationPath().
 *
 * @return int[]
 */
function getLocationSubtreeIds(int $locationId): array
{
    $pdo = getPDO();
    $ids = [$locationId];
    $frontier = [$locationId];
    $guard = 0;
    while (!empty($frontier) && $guard++ < 50) {
        $placeholders = implode(',', array_fill(0, count($frontier), '?'));
        $stmt = $pdo->prepare(
            "SELECT id FROM storage_locations WHERE parent_id IN ($placeholders) AND (location_type IS NULL OR location_type != 'owned_set')"
        );
        $stmt->execute($frontier);
        $frontier = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        $ids = array_merge($ids, $frontier);
    }
    return $ids;
}

/**
 * Everything stored anywhere under $locationId — itself plus every
 * descendant location, recursively — grouped into the two buckets the
 * location Explorer's right pane renders as separate sections. Parts are
 * further grouped by their top-level category (category_name is null for a
 * part with no category at all; the caller decides the fallback label for
 * that group).
 *
 * @return array{partsByCategory: array<string, array>, minifigs: array}
 */
function getLocationContentRecursive(PDO $pdo, int $locationId): array
{
    $looseIds = getLocationSubtreeIds($locationId);
    $loosePlaceholders = implode(',', array_fill(0, count($looseIds), '?'));

    // pci (part_color_images) joins on c.color_id — Rebrickable's own
    // numbering — not si.color_id/c.id (the colors.id surrogate PK
    // storage_items uses); same distinction getSetPartsList() documents.
    // ldraw_thumbnail is the color-correct image where one's been cached;
    // callers fall back to a generic (not color-specific) thumbnail when
    // it's null, same priority order getSetPartsList() uses.
    $partsStmt = $pdo->prepare(
        "SELECT si.location_id, si.part_id, p.part_num, p.name AS part_name, pc.name AS category_name,
                c.id AS color_id, c.color_id AS rebrickable_color_id, c.name AS color_name, c.rgb AS color_rgb,
                si.condition_type, si.quantity, pci.local_image_path AS ldraw_thumbnail
         FROM storage_items si
         INNER JOIN parts p ON p.id = si.part_id
         LEFT JOIN part_categories pc ON pc.part_cat_id = p.part_category
         LEFT JOIN colors c ON c.id = si.color_id
         LEFT JOIN part_color_images pci ON pci.part_id = si.part_id AND pci.color_id = c.color_id
         WHERE si.location_id IN ($loosePlaceholders) AND si.quantity > 0
         ORDER BY pc.name IS NULL, pc.name, p.part_num"
    );
    $partsStmt->execute($looseIds);
    $partsByCategory = [];
    foreach ($partsStmt->fetchAll() as $row) {
        $row['location_id'] = (int) $row['location_id'];
        $row['part_id'] = (int) $row['part_id'];
        $row['color_id'] = $row['color_id'] !== null ? (int) $row['color_id'] : null;
        $row['rebrickable_color_id'] = $row['rebrickable_color_id'] !== null ? (int) $row['rebrickable_color_id'] : null;
        $row['quantity'] = (int) $row['quantity'];
        $category = $row['category_name']; // null stays null; caller supplies the fallback label
        $partsByCategory[$category ?? ''][] = $row;
    }

    $minifigsStmt = $pdo->prepare(
        "SELECT msi.id AS instance_id, msi.location_id, msi.minifig_id, m.fig_num, m.name AS minifig_name, m.local_image_path AS thumbnail,
                msi.condition_type
         FROM minifig_storage_items msi
         INNER JOIN minifigs m ON m.id = msi.minifig_id
         WHERE msi.location_id IN ($loosePlaceholders)
         ORDER BY m.name"
    );
    $minifigsStmt->execute($looseIds);
    $minifigs = $minifigsStmt->fetchAll();
    foreach ($minifigs as &$fig) {
        $fig['instance_id'] = (int) $fig['instance_id'];
        $fig['location_id'] = (int) $fig['location_id'];
        $fig['minifig_id'] = (int) $fig['minifig_id'];
    }
    unset($fig);

    return ['partsByCategory' => $partsByCategory, 'minifigs' => $minifigs];
}

/**
 * Adds $count new, individual minifig instances (one row each — see
 * minifig_storage_items' own doc comment in src/setup.php for why there's
 * no quantity column to upsert onto) and returns their new ids, so a caller
 * that also received a per-part defekt/fehlt breakdown
 * (action=add_minifig_stock, src/routes/actions.php) can apply it to each
 * one. No storage_movements audit row: that log's schema is part-specific
 * (part_id/color_id, see its CONSTRAINT fk_movement_part), and loose-minifig
 * storage is new enough that extending it isn't warranted yet.
 *
 * @return int[]
 */
function addMinifigStock(int $locationId, int $minifigId, string $conditionType, int $count): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'INSERT INTO minifig_storage_items (location_id, minifig_id, condition_type) VALUES (?, ?, ?)'
    );
    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $stmt->execute([$locationId, $minifigId, $conditionType]);
        $ids[] = (int) $pdo->lastInsertId();
    }
    return $ids;
}

/**
 * Moves one specific minifig instance to a different location — plain
 * UPDATE by id, no merging needed (unlike moveStorageItem()/parts, there's
 * no unique key on location+minifig+condition to collide with anymore).
 */
function moveMinifigStorageItemInstance(int $instanceId, int $toLocationId): void
{
    $pdo = getPDO();
    $pdo->prepare('UPDATE minifig_storage_items SET location_id = ? WHERE id = ?')->execute([$toLocationId, $instanceId]);
}

/**
 * Removes one specific minifig instance entirely — the loose-minifig
 * counterpart to removeOwnedSet() (src/owned_sets.php) for the "I no longer
 * have this one" case. Its minifig_storage_item_parts rows cascade via FK,
 * same as owned_set_minifig_parts does when an owned_sets row is deleted.
 */
function deleteMinifigStorageItemInstance(int $instanceId): void
{
    $pdo = getPDO();
    $pdo->prepare('DELETE FROM minifig_storage_items WHERE id = ?')->execute([$instanceId]);
}
