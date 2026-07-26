<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/icons.php';

const SETS_SEARCH_PAGE_SIZE = 100;

/**
 * One set card, linking to the set's detail page.
 */
function renderSetCard(array $set): string
{
    $html = '<a class="set-card" href="?page=set_detail&id=' . (int) $set['id'] . '">';
    $html .= '<span class="set-card-image">' . ($set['thumbnail'] !== null ? '<img src="' . htmlspecialchars($set['thumbnail']) . '" alt="">' : getNavIcon('sets')) . '</span>';
    $html .= '<span class="set-card-num">' . htmlspecialchars($set['rebrickable_set_num']) . ($set['year'] !== null ? ' · ' . (int) $set['year'] : '') . '</span>';
    $html .= '<span class="set-card-name" title="' . htmlspecialchars($set['name']) . '">' . htmlspecialchars($set['name']) . '</span>';
    $html .= '</a>';
    return $html;
}

/**
 * Set "categories" are Rebrickable themes. sets.theme stores the raw
 * theme_id as a string — the real sets.csv only ships theme_id, no readable
 * name column (see the CSV-column-mismatch audit) — so getting a display
 * name always requires this join through themes.theme_id.
 *
 * @return array<int, array{theme_id:int, name:string, cnt:int}>
 */
function getSetThemes(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT th.theme_id, th.name, COUNT(s.id) AS cnt
         FROM themes th
         INNER JOIN sets s ON s.theme = th.theme_id
         GROUP BY th.theme_id, th.name
         HAVING cnt > 0
         ORDER BY th.name ASC'
    );
    return $stmt->fetchAll();
}

/**
 * One representative set image per theme, for the theme tile grid — mirrors
 * parts.php's getCategoryTileImages().
 *
 * @param int[] $themeIds
 * @return array<string, string>
 */
function getThemeTileImages(PDO $pdo, array $themeIds): array
{
    $stmt = $pdo->prepare(
        "SELECT local_image_path FROM sets
         WHERE theme = ? AND local_image_path IS NOT NULL AND local_image_path != ''
         LIMIT 1"
    );

    $result = [];
    foreach ($themeIds as $themeId) {
        $stmt->execute([$themeId]);
        $path = $stmt->fetchColumn();
        if ($path !== false) {
            $result[(string) $themeId] = (string) $path;
        }
    }
    return $result;
}

/**
 * @return array{id:int, rebrickable_set_num:string, name:string, year:?int, year_retired:?int, num_parts:?int, thumbnail:?string, theme_name:?string}|null
 */
function getSetById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.rebrickable_set_num, s.name, s.year, s.year_retired, s.num_parts, s.local_image_path AS thumbnail, th.name AS theme_name
         FROM sets s
         LEFT JOIN themes th ON th.theme_id = s.theme
         WHERE s.id = ?'
    );
    $stmt->execute([$id]);
    $set = $stmt->fetch();
    if ($set === false) {
        return null;
    }
    $set['year'] = $set['year'] !== null ? (int) $set['year'] : null;
    $set['year_retired'] = $set['year_retired'] !== null ? (int) $set['year_retired'] : null;
    return $set;
}

/**
 * The set's retirement/end-of-life year is never in Rebrickable's own data
 * (sets.csv ships only an introduction year) — this is a manually-edited
 * field, same crowdsourced reasoning as part_translations. $year === null
 * clears it back to unset.
 */
function updateSetRetiredYear(PDO $pdo, int $setId, ?int $year): void
{
    $stmt = $pdo->prepare('UPDATE sets SET year_retired = ? WHERE id = ?');
    $stmt->execute([$year, $setId]);
}

/**
 * A set can have more than one Rebrickable inventory revision — the parts
 * list always comes from the latest (highest version) one, matching what
 * Rebrickable itself shows as "current" for a set. Used by the spares and
 * minifigs tabs, which (unlike the inventory tab) don't offer a per-version
 * view.
 */
function getSetInventoryId(PDO $pdo, string $setNum): ?int
{
    $stmt = $pdo->prepare('SELECT inventory_id FROM rebrickable_inventories WHERE set_num = ? ORDER BY version DESC LIMIT 1');
    $stmt->execute([$setNum]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int) $id : null;
}

/**
 * All inventory revisions for a set (e.g. LEGO 4563 has a v1 and a v2 —
 * Rebrickable ships a new inventory revision when a set's contents changed
 * mid-production), oldest first. The inventory tab shows one sub-tab per
 * entry when there's more than one; a single-revision set just gets the
 * plain "Inventar" tab as before.
 *
 * @return array<int, array{inventory_id:int, version:int}>
 */
function getSetInventoryVersions(PDO $pdo, string $setNum): array
{
    $stmt = $pdo->prepare('SELECT inventory_id, version FROM rebrickable_inventories WHERE set_num = ? ORDER BY version ASC');
    $stmt->execute([$setNum]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['inventory_id'] = (int) $row['inventory_id'];
        $row['version'] = (int) $row['version'];
    }
    unset($row);
    return $rows;
}

/**
 * Parts (or, with $spares true, spare parts) needed for one set's inventory,
 * one row per part+color combination — the same part can need several
 * different colors. Three image fields, in the order the UI falls back
 * through them:
 *  - ldraw_thumbnail: a color-correct image previously fetched on demand via
 *    fetchPartColorImage() (part_color_images) — only present once a user
 *    has actually requested it for this exact part+color.
 *  - thumbnail: this same inventory's own inventory_parts.local_image_path
 *    (populated by the bulk image-download step). NOT from parts.php's
 *    getPartThumbnails() — that one searches for *any* row across the whole
 *    catalog with this part_id, which for a generic part used in thousands
 *    of sets means scanning tens of thousands of rows just to find one
 *    image (measured ~7s for one 23-part set). Since inventory_parts
 *    already carries the image for this exact set's own rows, no such scan
 *    is needed.
 *  - remote_thumbnail: the original (un-downloaded) inventory_parts.img_url,
 *    as a last-resort hotlink when nothing has been cached locally yet.
 *
 * @return array<int, array{part_id:int, part_num:string, name:string, color_id:?int, rebrickable_color_id:?int, color_name:?string, color_rgb:?string, quantity:int, thumbnail:?string, remote_thumbnail:?string, ldraw_thumbnail:?string}>
 */
function getSetPartsList(PDO $pdo, int $inventoryId, bool $spares): array
{
    // inventory_parts.color_id stores Rebrickable's own color_id (the
    // catalog/search side's numbering), NOT colors.id (the surrogate PK
    // storage_items.color_id uses) — same distinction as
    // parts.php's getColorsForPartPicker()/getColorFacet(). Joining against
    // colors.id here (as an earlier version of this query did) silently
    // paired parts with the wrong color whenever the two id sequences
    // happened to diverge. part_color_images is keyed the same way
    // (Rebrickable's color_id), so it joins directly on ip.color_id too.
    $stmt = $pdo->prepare(
        'SELECT p.id AS part_id, p.part_num, p.name,
                c.id AS color_id, ip.color_id AS rebrickable_color_id, c.name AS color_name, c.rgb AS color_rgb,
                SUM(ip.quantity) AS quantity,
                MIN(NULLIF(ip.local_image_path, \'\')) AS thumbnail,
                MIN(NULLIF(ip.img_url, \'\')) AS remote_thumbnail,
                MAX(pci.local_image_path) AS ldraw_thumbnail
         FROM inventory_parts ip
         INNER JOIN parts p ON p.id = ip.part_id
         LEFT JOIN colors c ON c.color_id = ip.color_id
         LEFT JOIN part_color_images pci ON pci.part_id = p.id AND pci.color_id = ip.color_id
         WHERE ip.inventory_id = ? AND ip.is_spare = ?
         GROUP BY p.id, p.part_num, p.name, c.id, ip.color_id, c.name, c.rgb
         ORDER BY p.name ASC'
    );
    $stmt->execute([$inventoryId, $spares ? 1 : 0]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['part_id'] = (int) $row['part_id'];
        $row['color_id'] = $row['color_id'] !== null ? (int) $row['color_id'] : null;
        $row['rebrickable_color_id'] = $row['rebrickable_color_id'] !== null ? (int) $row['rebrickable_color_id'] : null;
        $row['quantity'] = (int) $row['quantity'];
    }
    unset($row);
    return $rows;
}

/**
 * Only the part+color rows whose quantity actually differs across a set's
 * inventory revisions (a part/color missing from one revision counts as
 * quantity 0 there) — everything unchanged between versions is left out, so
 * only what actually moved between production revisions shows up. Spares
 * aren't included, matching the plain inventory tab's scope.
 *
 * @param array<int, array{inventory_id:int, version:int}> $versions
 * @return array<int, array{part_id:int, part_num:string, name:string, color_id:?int, rebrickable_color_id:?int, color_name:?string, color_rgb:?string, thumbnail:?string, remote_thumbnail:?string, ldraw_thumbnail:?string, quantities: array<int,int>}>
 */
function getSetInventoryComparison(PDO $pdo, array $versions): array
{
    $rows = [];
    foreach ($versions as $v) {
        $items = getSetPartsList($pdo, $v['inventory_id'], false);
        foreach ($items as $item) {
            $key = $item['part_id'] . ':' . ($item['color_id'] ?? 'null');
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'part_id' => $item['part_id'],
                    'part_num' => $item['part_num'],
                    'name' => $item['name'],
                    'color_id' => $item['color_id'],
                    'rebrickable_color_id' => $item['rebrickable_color_id'],
                    'color_name' => $item['color_name'],
                    'color_rgb' => $item['color_rgb'],
                    'thumbnail' => $item['thumbnail'],
                    'remote_thumbnail' => $item['remote_thumbnail'],
                    'ldraw_thumbnail' => $item['ldraw_thumbnail'],
                    'quantities' => [],
                ];
            } else {
                foreach (['ldraw_thumbnail', 'thumbnail', 'remote_thumbnail'] as $field) {
                    if ($rows[$key][$field] === null && $item[$field] !== null) {
                        $rows[$key][$field] = $item[$field];
                    }
                }
            }
            $rows[$key]['quantities'][$v['version']] = $item['quantity'];
        }
    }

    $versionNumbers = array_map(function (array $v): int {
        return $v['version'];
    }, $versions);

    $result = [];
    foreach ($rows as $row) {
        $normalized = [];
        foreach ($versionNumbers as $vn) {
            $normalized[$vn] = $row['quantities'][$vn] ?? 0;
        }
        if (count(array_unique($normalized)) > 1) {
            $row['quantities'] = $normalized;
            $result[] = $row;
        }
    }

    usort($result, function (array $a, array $b): int {
        return strcmp($a['name'], $b['name']);
    });

    return $result;
}

/**
 * @param string[] $selectedThemes
 * @return array{items: array, total: int, page: int, perPage: int}
 */
function searchSets(PDO $pdo, string $query, array $selectedThemes, int $page, int $perPage): array
{
    $where = [];
    $params = [];

    if ($query !== '') {
        $where[] = '(s.name LIKE ? OR s.rebrickable_set_num LIKE ?)';
        $params[] = '%' . $query . '%';
        $params[] = '%' . $query . '%';
    }
    if (!empty($selectedThemes)) {
        $placeholders = implode(',', array_fill(0, count($selectedThemes), '?'));
        $where[] = "s.theme IN ($placeholders)";
        foreach ($selectedThemes as $themeId) {
            $params[] = $themeId;
        }
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM sets s $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $perPage = max(1, $perPage);
    $offset = (max(1, $page) - 1) * $perPage;
    $stmt = $pdo->prepare(
        "SELECT s.id, s.rebrickable_set_num, s.name, s.year, s.num_parts, s.local_image_path AS thumbnail
         FROM sets s
         $whereSql
         ORDER BY s.name ASC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);

    return [
        'items' => $stmt->fetchAll(),
        'total' => $total,
        'page' => $page,
        'perPage' => $perPage,
    ];
}
