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
 * @return array<int, array{id:int, parent_id:?int, name:string, location_type:?string, children: array}>
 */
function getStorageLocationTree(): array
{
    $pdo = getPDO();
    $rows = $pdo->query('SELECT id, parent_id, name, location_type FROM storage_locations ORDER BY name')->fetchAll();

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
 * client.
 */
function getChildLocations(?int $parentId): array
{
    $pdo = getPDO();
    if ($parentId === null) {
        $stmt = $pdo->query('SELECT id, name, location_type FROM storage_locations WHERE parent_id IS NULL ORDER BY name');
    } else {
        $stmt = $pdo->prepare('SELECT id, name, location_type FROM storage_locations WHERE parent_id = ? ORDER BY name');
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
