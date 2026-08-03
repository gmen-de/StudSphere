<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Location types are a small fixed vocabulary in code, not a DB-enforced ENUM —
 * new types can be added here later without a schema migration. A non-null
 * 'bulkChildKey' means the "add location" form offers to auto-create N numbered
 * child locations right away, using that translation key as the default naming
 * pattern (contains a literal "{n}" placeholder, replaced per child).
 */
function getLocationTypes(): array
{
    return [
        'room' => ['labelKey' => 'location_type_room', 'bulkChildKey' => null],
        'shelf' => ['labelKey' => 'location_type_shelf', 'bulkChildKey' => 'location_child_pattern_shelf_board'],
        'small_parts_organizer' => ['labelKey' => 'location_type_organizer', 'bulkChildKey' => 'location_child_pattern_drawer'],
        'drawer' => ['labelKey' => 'location_type_drawer', 'bulkChildKey' => 'location_child_pattern_compartment'],
        'box' => ['labelKey' => 'location_type_box', 'bulkChildKey' => null],
        'bag' => ['labelKey' => 'location_type_bag', 'bulkChildKey' => null],
        'other' => ['labelKey' => 'location_type_other', 'bulkChildKey' => 'location_child_pattern_generic'],
    ];
}

function isValidLocationType(?string $type): bool
{
    return $type !== null && array_key_exists($type, getLocationTypes());
}

function createStorageLocation(?int $parentId, string $name, ?string $type): int
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO storage_locations (parent_id, name, location_type) VALUES (?, ?, ?)');
    $stmt->execute([$parentId, $name, $type]);
    return (int) $pdo->lastInsertId();
}

/**
 * Creates a location and, if $childCount > 0, immediately populates it with
 * that many numbered children (e.g. "Schublade 1".."Schublade 8" from the
 * pattern "Schublade {n}"). Runs in a transaction so a failure partway through
 * can't leave a half-created set of children behind.
 */
function createStorageLocationWithChildren(?int $parentId, string $name, ?string $type, int $childCount, string $namingPattern): int
{
    $pdo = getPDO();
    $pdo->beginTransaction();
    try {
        $id = createStorageLocation($parentId, $name, $type);
        for ($i = 1; $i <= $childCount; $i++) {
            $childName = str_replace('{n}', (string) $i, $namingPattern);
            createStorageLocation($id, $childName, null);
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function renameStorageLocation(int $id, string $name, ?string $type): void
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('UPDATE storage_locations SET name = ?, location_type = ? WHERE id = ?');
    $stmt->execute([$name, $type, $id]);
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
 * locations, so they don't belong in the general "Lagerort-Übersicht" tree
 * or the "add location" parent picker (getStorageLocationOptions(), built
 * from this).
 *
 * @return array<int, array{id:int, parent_id:?int, name:string, location_type:?string, children: array}>
 */
function getStorageLocationTree(): array
{
    $pdo = getPDO();
    $rows = $pdo->query("SELECT id, parent_id, name, location_type FROM storage_locations WHERE location_type IS NULL OR location_type != 'owned_set' ORDER BY name")->fetchAll();

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
        $stmt = $pdo->query("SELECT id, name, location_type FROM storage_locations WHERE parent_id IS NULL AND (location_type IS NULL OR location_type != 'owned_set') ORDER BY name");
    } else {
        $stmt = $pdo->prepare("SELECT id, name, location_type FROM storage_locations WHERE parent_id = ? AND (location_type IS NULL OR location_type != 'owned_set') ORDER BY name");
        $stmt->execute([$parentId]);
    }
    return $stmt->fetchAll();
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
 * Current stock of one part across all storage locations, for the part
 * detail modal's "Lager" tab — one row per location/color/condition combo
 * that actually holds stock.
 *
 * @return array<int, array{location_id:int, location_path:string, color_id:?int, color_name:?string, color_rgb:?string, condition_type:string, quantity:int}>
 */
function getPartStock(int $partId): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT si.location_id, si.condition_type, si.quantity,
                c.id AS color_id, c.name AS color_name, c.rgb AS color_rgb
         FROM storage_items si
         LEFT JOIN colors c ON c.id = si.color_id
         WHERE si.part_id = ? AND si.quantity > 0
         ORDER BY si.location_id'
    );
    $stmt->execute([$partId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['location_id'] = (int) $row['location_id'];
        $row['location_path'] = getStorageLocationPath($row['location_id']);
        $row['color_id'] = $row['color_id'] !== null ? (int) $row['color_id'] : null;
        $row['quantity'] = (int) $row['quantity'];
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
 * All descendant location ids of $locationId, including itself. An owned
 * set's own auto-generated location (location_type 'owned_set') is a real
 * node in this tree (createStorageLocation() nests it under wherever the
 * set was placed) — $includeOwnedSetLocations controls whether the walk
 * includes those nodes (and descends into them) at all:
 * - true: needed to find owned_sets rows at all (owned_sets.location_id
 *   points at that auto-generated node itself, not its parent) — see
 *   getLocationContentRecursive()'s "sets" query.
 * - false: for "what loose stock lives under here" — a set's own spare/
 *   damaged parts (materializeOwnedSetStock() et al., src/owned_sets.php)
 *   live at that same auto-generated node, and must NOT be counted as loose
 *   stock of the location the set happens to sit in. The set itself already
 *   surfaces in the "Sets" group; its contents only show once someone
 *   actually opens that set's own inventory page.
 *
 * A per-level walk, not a recursive CTE, for the same shared-hosting
 * MariaDB-version reason as getStorageLocationPath().
 *
 * @return int[]
 */
function getLocationSubtreeIds(int $locationId, bool $includeOwnedSetLocations = true): array
{
    $pdo = getPDO();
    $ids = [$locationId];
    $frontier = [$locationId];
    $guard = 0;
    while (!empty($frontier) && $guard++ < 50) {
        $placeholders = implode(',', array_fill(0, count($frontier), '?'));
        $sql = "SELECT id FROM storage_locations WHERE parent_id IN ($placeholders)";
        if (!$includeOwnedSetLocations) {
            $sql .= " AND (location_type IS NULL OR location_type != 'owned_set')";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($frontier);
        $frontier = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        $ids = array_merge($ids, $frontier);
    }
    return $ids;
}

/**
 * Everything stored anywhere under $locationId — itself plus every
 * descendant location, recursively — grouped into the three buckets the
 * location Explorer's right pane renders as separate sections. The
 * Explorer is meant purely for managing LOOSE stock: parts and minifigs
 * belonging to a set (its constituent inventory, its own spares) never
 * appear here regardless of nesting, only the set itself does, in the Sets
 * group — see getLocationSubtreeIds()'s doc comment for why sets need the
 * full subtree (including owned-set instance nodes) while parts/minifigs
 * deliberately don't. Parts are further grouped by their top-level category
 * (category_name is null for a part with no category at all; the caller
 * decides the fallback label for that group).
 *
 * @return array{sets: array, partsByCategory: array<string, array>, minifigs: array}
 */
function getLocationContentRecursive(PDO $pdo, int $locationId): array
{
    $allIds = getLocationSubtreeIds($locationId, true);
    $allPlaceholders = implode(',', array_fill(0, count($allIds), '?'));

    $looseIds = getLocationSubtreeIds($locationId, false);
    $loosePlaceholders = implode(',', array_fill(0, count($looseIds), '?'));

    $setsStmt = $pdo->prepare(
        "SELECT os.id, s.rebrickable_set_num, s.name, s.local_image_path AS thumbnail
         FROM owned_sets os
         INNER JOIN sets s ON s.id = os.set_id
         WHERE os.location_id IN ($allPlaceholders)
         ORDER BY s.name"
    );
    $setsStmt->execute($allIds);
    $sets = $setsStmt->fetchAll();
    foreach ($sets as &$set) {
        $set['id'] = (int) $set['id'];
    }
    unset($set);

    $partsStmt = $pdo->prepare(
        "SELECT si.part_id, p.part_num, p.name AS part_name, pc.name AS category_name,
                c.id AS color_id, c.name AS color_name, c.rgb AS color_rgb,
                si.condition_type, si.quantity
         FROM storage_items si
         INNER JOIN parts p ON p.id = si.part_id
         LEFT JOIN part_categories pc ON pc.part_cat_id = p.part_category
         LEFT JOIN colors c ON c.id = si.color_id
         WHERE si.location_id IN ($loosePlaceholders) AND si.quantity > 0
         ORDER BY pc.name IS NULL, pc.name, p.part_num"
    );
    $partsStmt->execute($looseIds);
    $partsByCategory = [];
    foreach ($partsStmt->fetchAll() as $row) {
        $row['part_id'] = (int) $row['part_id'];
        $row['color_id'] = $row['color_id'] !== null ? (int) $row['color_id'] : null;
        $row['quantity'] = (int) $row['quantity'];
        $category = $row['category_name']; // null stays null; caller supplies the fallback label
        $partsByCategory[$category ?? ''][] = $row;
    }

    $minifigsStmt = $pdo->prepare(
        "SELECT msi.minifig_id, m.fig_num, m.name AS minifig_name, m.local_image_path AS thumbnail,
                msi.condition_type, msi.quantity
         FROM minifig_storage_items msi
         INNER JOIN minifigs m ON m.id = msi.minifig_id
         WHERE msi.location_id IN ($loosePlaceholders) AND msi.quantity > 0
         ORDER BY m.name"
    );
    $minifigsStmt->execute($looseIds);
    $minifigs = $minifigsStmt->fetchAll();
    foreach ($minifigs as &$fig) {
        $fig['minifig_id'] = (int) $fig['minifig_id'];
        $fig['quantity'] = (int) $fig['quantity'];
    }
    unset($fig);

    return ['sets' => $sets, 'partsByCategory' => $partsByCategory, 'minifigs' => $minifigs];
}

/**
 * Adds quantity to a specific location/minifig/condition combination
 * (upsert on the existing UNIQUE KEY) — the loose-minifig counterpart to
 * addStorageStock(). No storage_movements audit row: that log's schema is
 * part-specific (part_id/color_id, see its CONSTRAINT fk_movement_part), and
 * loose-minifig storage is new enough that extending it isn't warranted yet.
 */
function addMinifigStock(int $locationId, int $minifigId, string $conditionType, int $quantity): int
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'INSERT INTO minifig_storage_items (location_id, minifig_id, condition_type, quantity)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
    );
    $stmt->execute([$locationId, $minifigId, $conditionType, $quantity]);

    $resultStmt = $pdo->prepare(
        'SELECT quantity FROM minifig_storage_items WHERE location_id = ? AND minifig_id = ? AND condition_type = ?'
    );
    $resultStmt->execute([$locationId, $minifigId, $conditionType]);
    return (int) $resultStmt->fetchColumn();
}

/**
 * Flat (id => indented label) list for populating a parent-location <select>.
 */
function getStorageLocationOptions(): array
{
    $options = [];
    $walk = function (array $nodes, int $depth) use (&$walk, &$options): void {
        foreach ($nodes as $node) {
            $options[(int) $node['id']] = str_repeat('— ', $depth) . $node['name'];
            $walk($node['children'], $depth + 1);
        }
    };
    $walk(getStorageLocationTree(), 0);
    return $options;
}
