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
