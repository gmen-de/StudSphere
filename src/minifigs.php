<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/icons.php';

const MINIFIGS_SEARCH_PAGE_SIZE = 100;

/**
 * One minifig card — mirrors parts.php's renderPartCard(), used by both the
 * minifigs search results and a set's minifig tab. data-minifig-id +
 * role/tabindex mirror renderPartCard()'s data-part-id: renderMinifigDetailModal()
 * (src/minifig_modal.php) listens for clicks on this globally, same pattern
 * as the part-detail modal.
 */
function renderMinifigCard(array $fig, ?string $meta = null): string
{
    $html = '<div class="minifig-card" data-minifig-id="' . (int) $fig['id'] . '" role="button" tabindex="0">';
    $html .= '<span class="minifig-card-image">' . ($fig['thumbnail'] !== null ? '<img src="' . htmlspecialchars($fig['thumbnail']) . '" alt="">' : getNavIcon('minifigs')) . '</span>';
    $html .= '<span class="minifig-card-num">' . htmlspecialchars($fig['fig_num']) . '</span>';
    $name = (string) ($fig['name'] ?? $fig['fig_num']);
    $html .= '<span class="minifig-card-name" title="' . htmlspecialchars($name) . '">' . htmlspecialchars($name) . '</span>';
    if ($meta !== null) {
        $html .= '<span class="minifig-card-meta">' . htmlspecialchars($meta) . '</span>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * @return array{id:int, fig_num:string, name:?string, thumbnail:?string}|null
 */
function getMinifigById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, fig_num, name, local_image_path AS thumbnail FROM minifigs WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    return $row;
}

/**
 * "Appears in N sets, Xx total, from year to year" summary for the minifig
 * detail modal's header — mirrors getPartDetail()'s equivalent block in
 * src/parts.php exactly, just off inventory_minifigs instead of
 * inventory_parts (a minifig has no is_spare distinction, so no such filter
 * here).
 *
 * @return array{sets_count:int, total_appearances:int, min_year:?int, max_year:?int}
 */
function getMinifigSetStats(PDO $pdo, int $minifigId): array
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT ri.set_num) AS set_count,
                COALESCE(SUM(im.quantity), 0) AS total_appearances,
                MIN(s.year) AS min_year,
                MAX(s.year) AS max_year
         FROM inventory_minifigs im
         INNER JOIN rebrickable_inventories ri ON ri.inventory_id = im.inventory_id
         LEFT JOIN sets s ON s.rebrickable_set_num = ri.set_num
         WHERE im.minifig_id = ?'
    );
    $stmt->execute([$minifigId]);
    $row = $stmt->fetch();
    return [
        'sets_count' => (int) ($row['set_count'] ?? 0),
        'total_appearances' => (int) ($row['total_appearances'] ?? 0),
        'min_year' => $row['min_year'] !== null ? (int) $row['min_year'] : null,
        'max_year' => $row['max_year'] !== null ? (int) $row['max_year'] : null,
    ];
}

/**
 * Every set a minifig appears in — mirrors getPartSets() in src/parts.php.
 *
 * @return array<int, array{set_num:string, name:?string, year:?int, thumbnail:?string, quantity:int}>
 */
function getMinifigSets(PDO $pdo, int $minifigId): array
{
    $stmt = $pdo->prepare(
        'SELECT ri.set_num, s.name, s.year, s.local_image_path AS thumbnail, SUM(im.quantity) AS quantity
         FROM inventory_minifigs im
         INNER JOIN rebrickable_inventories ri ON ri.inventory_id = im.inventory_id
         LEFT JOIN sets s ON s.rebrickable_set_num = ri.set_num
         WHERE im.minifig_id = ?
         GROUP BY ri.set_num, s.name, s.year, s.local_image_path
         ORDER BY s.year DESC, s.name ASC'
    );
    $stmt->execute([$minifigId]);
    $sets = $stmt->fetchAll();
    foreach ($sets as &$set) {
        $set['quantity'] = (int) $set['quantity'];
        $set['year'] = $set['year'] !== null ? (int) $set['year'] : null;
    }
    unset($set);
    return $sets;
}

/**
 * Minifigs have no category field of their own — the only place Rebrickable
 * groups them is via the sets they appear in, so "theme" here is derived by
 * walking minifigs -> inventory_minifigs -> rebrickable_inventories -> sets
 * -> themes (see sets.php's getSetThemes() for why that last join, through
 * sets.theme, is needed for a display name). A minifig that appears in sets
 * from more than one theme is counted under each — expected, not a bug.
 *
 * @return array<int, array{theme_id:int, name:string, cnt:int}>
 */
function getMinifigThemes(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT th.theme_id, th.name, COUNT(DISTINCT m.id) AS cnt
         FROM themes th
         INNER JOIN sets s ON s.theme = th.theme_id
         INNER JOIN rebrickable_inventories ri ON ri.set_num = s.rebrickable_set_num
         INNER JOIN inventory_minifigs im ON im.inventory_id = ri.inventory_id
         INNER JOIN minifigs m ON m.id = im.minifig_id
         GROUP BY th.theme_id, th.name
         HAVING cnt > 0
         ORDER BY th.name ASC'
    );
    return $stmt->fetchAll();
}

/**
 * One representative minifig image per theme, for the theme tile grid —
 * mirrors sets.php's getThemeTileImages().
 *
 * @param int[] $themeIds
 * @return array<string, string>
 */
function getMinifigThemeTileImages(PDO $pdo, array $themeIds): array
{
    $stmt = $pdo->prepare(
        "SELECT m.local_image_path
         FROM minifigs m
         INNER JOIN inventory_minifigs im ON im.minifig_id = m.id
         INNER JOIN rebrickable_inventories ri ON ri.inventory_id = im.inventory_id
         INNER JOIN sets s ON s.rebrickable_set_num = ri.set_num
         WHERE s.theme = ? AND m.local_image_path IS NOT NULL AND m.local_image_path != ''
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
 * A minifig's own constituent-parts inventory (head/torso/legs/accessories)
 * — Rebrickable ships this as an "inventory" of its own, exactly like a
 * set's, just keyed by the minifig's fig_num instead of a set_num in the
 * same rebrickable_inventories.set_num column (already imported by the
 * generic CSV import, nothing minifig-specific needed there). Same query
 * shape as sets.php's getSetInventoryId() — the caller then feeds the
 * result into getSetPartsList(), which has no set-specific logic either.
 */
function getMinifigInventoryId(PDO $pdo, string $figNum): ?int
{
    $stmt = $pdo->prepare('SELECT inventory_id FROM rebrickable_inventories WHERE set_num = ? ORDER BY version DESC LIMIT 1');
    $stmt->execute([$figNum]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int) $id : null;
}

/**
 * Minifigs needed for one set's inventory (via rebrickable_inventories.
 * inventory_id, same as sets.php's getSetPartsList() for regular parts).
 *
 * @return array<int, array{minifig_id:int, fig_num:string, name:?string, thumbnail:?string, quantity:int}>
 */
function getSetMinifigsList(PDO $pdo, int $inventoryId): array
{
    $stmt = $pdo->prepare(
        'SELECT m.id AS minifig_id, m.fig_num, m.name, m.local_image_path AS thumbnail, im.quantity
         FROM inventory_minifigs im
         INNER JOIN minifigs m ON m.id = im.minifig_id
         WHERE im.inventory_id = ?
         ORDER BY m.name ASC'
    );
    $stmt->execute([$inventoryId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['minifig_id'] = (int) $row['minifig_id'];
        $row['quantity'] = (int) $row['quantity'];
    }
    unset($row);
    return $rows;
}

/**
 * @param string[] $selectedThemes
 * @return array{items: array, total: int, page: int, perPage: int}
 */
function searchMinifigs(PDO $pdo, string $query, array $selectedThemes, int $page, int $perPage): array
{
    $where = [];
    $params = [];

    // A theme filter is an existence check (not a join used for the main
    // SELECT) — the main query separately LEFT JOINs to sets to compute
    // each minifig's earliest appearance year, and an INNER/EXISTS join
    // there would wrongly drop minifigs whose only set appearances don't
    // match the theme filter... except there are none once EXISTS confirms
    // at least one does. Keeping the two joins independent avoids coupling
    // "does this minifig match the filter" with "what year do we show".
    if (!empty($selectedThemes)) {
        $placeholders = implode(',', array_fill(0, count($selectedThemes), '?'));
        $where[] = "EXISTS (
            SELECT 1 FROM inventory_minifigs im2
            INNER JOIN rebrickable_inventories ri2 ON ri2.inventory_id = im2.inventory_id
            INNER JOIN sets s2 ON s2.rebrickable_set_num = ri2.set_num
            WHERE im2.minifig_id = m.id AND s2.theme IN ($placeholders)
        )";
        foreach ($selectedThemes as $themeId) {
            $params[] = $themeId;
        }
    }

    if ($query !== '') {
        $where[] = '(m.name LIKE ? OR m.fig_num LIKE ?)';
        $params[] = '%' . $query . '%';
        $params[] = '%' . $query . '%';
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM minifigs m $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $perPage = max(1, $perPage);
    $offset = (max(1, $page) - 1) * $perPage;
    // Minifigs have no year field of their own (see getMinifigThemes()'s
    // doc comment) — the earliest year among sets they appear in stands in
    // for it, same derivation, just MIN(year) instead of theme names.
    $stmt = $pdo->prepare(
        "SELECT m.id, m.fig_num, m.name, m.local_image_path AS thumbnail, MIN(s.year) AS year
         FROM minifigs m
         LEFT JOIN inventory_minifigs im ON im.minifig_id = m.id
         LEFT JOIN rebrickable_inventories ri ON ri.inventory_id = im.inventory_id
         LEFT JOIN sets s ON s.rebrickable_set_num = ri.set_num
         $whereSql
         GROUP BY m.id, m.fig_num, m.name, m.local_image_path
         ORDER BY year ASC, m.fig_num ASC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $items = $stmt->fetchAll();
    foreach ($items as &$item) {
        $item['year'] = $item['year'] !== null ? (int) $item['year'] : null;
    }
    unset($item);

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'perPage' => $perPage,
    ];
}
