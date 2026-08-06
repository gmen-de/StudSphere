<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/sets.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/i18n.php';

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
 * One loose minifig instance tile for "Meine Minifiguren" (my_minifigs*) —
 * mirrors renderOwnedSetCard() (src/owned_sets.php): a real link straight to
 * this instance's own detail page (?page=owned_minifig_detail), not the
 * generic click-delegated modal every other minifig card on the site opens
 * — each tile here already IS one specific physical instance, same as an
 * owned-set card links directly instead of opening a modal.
 */
function renderOwnedMinifigCard(array $instance): string
{
    $locationLabel = implode(' -> ', array_column(getStorageLocationAncestors((int) $instance['location_id']), 'name'));
    $condLabel = $instance['condition_type'] === 'new' ? t('condition_new') : t('condition_used');
    $meta = $locationLabel !== '' ? $locationLabel . ' · ' . $condLabel : $condLabel;
    $name = (string) ($instance['name'] ?? $instance['fig_num']);

    $html = '<a class="minifig-card" href="?page=owned_minifig_detail&id=' . (int) $instance['id'] . '">';
    $html .= '<span class="minifig-card-image">' . ($instance['thumbnail'] !== null ? '<img src="' . htmlspecialchars($instance['thumbnail']) . '" alt="">' : getNavIcon('minifigs')) . '</span>';
    $html .= '<span class="minifig-card-num">' . htmlspecialchars($instance['fig_num']) . '</span>';
    $html .= '<span class="minifig-card-name" title="' . htmlspecialchars($name) . '">' . htmlspecialchars($name) . '</span>';
    $html .= '<span class="minifig-card-meta">' . htmlspecialchars($meta) . '</span>';
    $html .= '</a>';
    return $html;
}

/**
 * @return array{id:int, fig_num:string, name:?string, thumbnail:?string, bricklink_id:?string, bricklink_price_item_id:?int, bricklink_price_new:?float, bricklink_price_used:?float, bricklink_price_currency:?string, bricklink_price_checked_at:?string}|null
 */
function getMinifigById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, fig_num, name, local_image_path AS thumbnail, bricklink_id,
                bricklink_price_item_id, bricklink_price_new, bricklink_price_used,
                bricklink_price_currency, bricklink_price_checked_at
         FROM minifigs WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['bricklink_price_item_id'] = $row['bricklink_price_item_id'] !== null ? (int) $row['bricklink_price_item_id'] : null;
    $row['bricklink_price_new'] = $row['bricklink_price_new'] !== null ? (float) $row['bricklink_price_new'] : null;
    $row['bricklink_price_used'] = $row['bricklink_price_used'] !== null ? (float) $row['bricklink_price_used'] : null;
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
 * Same tree shape as getOwnedSetThemeTree() (src/sets.php) — full parent/
 * child hierarchy via buildThemeTree(), just counting loose minifig
 * instances (minifig_storage_items) instead of owned_sets rows. A minifig's
 * theme is always derived through the sets it appears in (see
 * getMinifigThemes()'s doc comment above), hence the extra two joins
 * compared to getOwnedSetThemeTree(). Powers "Meine Minifiguren"'s theme
 * menu (nav flyout + my_minifigs_themes).
 */
function getOwnedMinifigThemeTree(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT th.theme_id, th.name, th.parent_theme_id, COUNT(DISTINCT msi.id) AS direct_count
         FROM themes th
         LEFT JOIN sets s ON s.theme = CAST(th.theme_id AS CHAR)
         LEFT JOIN rebrickable_inventories ri ON ri.set_num = s.rebrickable_set_num
         LEFT JOIN inventory_minifigs im ON im.inventory_id = ri.inventory_id
         LEFT JOIN minifig_storage_items msi ON msi.minifig_id = im.minifig_id
         GROUP BY th.theme_id, th.name, th.parent_theme_id'
    )->fetchAll();
    return buildThemeTree($rows);
}

/**
 * Mirrors sets.php's getThemeTileImages() — one representative minifig
 * image per theme tile, searched across the tile's own theme plus every
 * descendant (a parent tile can have zero loose minifigs tagged with it
 * directly while its subthemes have plenty). Deliberately not
 * getMinifigThemeTileImages() above: that one only searches a theme's own
 * literal id (fine for the flat catalog-wide minifig browse it serves), not
 * a group of descendant ids.
 *
 * @param array<int, int[]> $themeIdGroups keyed by the tile's own theme_id
 * @return array<string, string>
 */
function getOwnedMinifigThemeTileImages(PDO $pdo, array $themeIdGroups): array
{
    $result = [];
    foreach ($themeIdGroups as $tileThemeId => $searchIds) {
        if (empty($searchIds)) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($searchIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT m.local_image_path
             FROM minifigs m
             INNER JOIN inventory_minifigs im ON im.minifig_id = m.id
             INNER JOIN rebrickable_inventories ri ON ri.inventory_id = im.inventory_id
             INNER JOIN sets s ON s.rebrickable_set_num = ri.set_num
             WHERE s.theme IN ($placeholders) AND m.local_image_path IS NOT NULL AND m.local_image_path != ''
             LIMIT 1"
        );
        $stmt->execute($searchIds);
        $path = $stmt->fetchColumn();
        if ($path !== false) {
            $result[(string) $tileThemeId] = (string) $path;
        }
    }
    return $result;
}

/**
 * Every loose minifig instance (one row per physical figure, see
 * minifig_storage_items' own doc comment in src/setup.php), no theme filter
 * — mirrors getAllOwnedSets() (src/owned_sets.php).
 *
 * @return array<int, array{id:int, minifig_id:int, fig_num:string, name:?string, thumbnail:?string, location_id:int, condition_type:string}>
 */
function getAllLooseMinifigs(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT msi.id, msi.minifig_id, m.fig_num, m.name, m.local_image_path AS thumbnail,
                msi.location_id, msi.condition_type
         FROM minifig_storage_items msi
         INNER JOIN minifigs m ON m.id = msi.minifig_id
         ORDER BY m.name ASC, msi.id ASC'
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['minifig_id'] = (int) $row['minifig_id'];
        $row['location_id'] = (int) $row['location_id'];
    }
    unset($row);
    return $rows;
}

/**
 * Loose minifig instances whose minifig type appears in a set from one of
 * the given themes — the loose-minifig equivalent of getOwnedSetsForThemes()
 * (src/owned_sets.php).
 *
 * @param int[] $themeIds
 * @return array<int, array{id:int, minifig_id:int, fig_num:string, name:?string, thumbnail:?string, location_id:int, condition_type:string}>
 */
function getLooseMinifigsForThemes(PDO $pdo, array $themeIds): array
{
    if (empty($themeIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($themeIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT msi.id, msi.minifig_id, m.fig_num, m.name, m.local_image_path AS thumbnail,
                msi.location_id, msi.condition_type
         FROM minifig_storage_items msi
         INNER JOIN minifigs m ON m.id = msi.minifig_id
         WHERE msi.minifig_id IN (
             SELECT DISTINCT im.minifig_id
             FROM inventory_minifigs im
             INNER JOIN rebrickable_inventories ri ON ri.inventory_id = im.inventory_id
             INNER JOIN sets s ON s.rebrickable_set_num = ri.set_num
             WHERE s.theme IN ($placeholders)
         )
         ORDER BY m.name ASC, msi.id ASC"
    );
    $stmt->execute($themeIds);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['minifig_id'] = (int) $row['minifig_id'];
        $row['location_id'] = (int) $row['location_id'];
    }
    unset($row);
    return $rows;
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
 * Every minifig_storage_items row (i.e. every distinct physical instance)
 * for one minifig — the same minifig can be stored more than once (split
 * across locations, some new/some used, or simply several identical
 * copies), and each instance has its own independent per-part completeness,
 * same reasoning as an owned set being ownable more than once. Powers the
 * minifig-detail modal's storage-instance picker (src/minifig_modal.php)
 * for the defekt/fehlt status feature.
 *
 * @return array<int, array{id:int, location_id:int, location_name:string, condition_type:string}>
 */
function getMinifigStorageItemsForMinifig(PDO $pdo, int $minifigId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, location_id, condition_type FROM minifig_storage_items WHERE minifig_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$minifigId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['location_id'] = (int) $row['location_id'];
        $row['location_name'] = implode(' -> ', array_column(getStorageLocationAncestors($row['location_id']), 'name'));
    }
    unset($row);
    return $rows;
}

function getMinifigStorageItemById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, location_id, minifig_id, condition_type FROM minifig_storage_items WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['location_id'] = (int) $row['location_id'];
    $row['minifig_id'] = (int) $row['minifig_id'];
    return $row;
}

/**
 * One stored minifig instance's own constituent parts (head/torso/legs/
 * accessories) — same nominal/actual/damaged shape and same "missing row =
 * fully present, until corrected" convention as
 * getOwnedSetMinifigPartsWithStatus() (see src/owned_sets.php), read from
 * minifig_storage_item_parts (migration 32). No quantity scaling: unlike an
 * owned set's minifig (which can nominally need more than one identical
 * copy), one minifig_storage_items row is always exactly one physical
 * minifig, so nominal is simply the catalog's own per-part quantity.
 *
 * @return array<int, array{part_id:int, part_num:string, name:string, color_id:int, rebrickable_color_id:?int, color_name:?string, color_rgb:?string, thumbnail:?string, nominal_quantity:int, actual_quantity:int, damaged_quantity:int}>
 */
function getMinifigStorageItemPartsWithStatus(PDO $pdo, int $minifigStorageItemId, string $figNum, string $locale = 'en'): array
{
    $inventoryId = getMinifigInventoryId($pdo, $figNum);
    if ($inventoryId === null) {
        return [];
    }
    $nominalItems = getSetPartsList($pdo, $inventoryId, false, $locale);

    $actualStmt = $pdo->prepare('SELECT part_id, color_id, quantity, damaged_quantity FROM minifig_storage_item_parts WHERE minifig_storage_item_id = ?');
    $actualStmt->execute([$minifigStorageItemId]);
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
            'rebrickable_color_id' => $item['rebrickable_color_id'],
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
 * Records one part's owned/damaged counts for one stored minifig instance —
 * mirrors applyOwnedSetMinifigPartInventory() (src/owned_sets.php), just
 * against minifig_storage_item_parts. $ownedInput/$damagedInput are
 * "part_id:color_id" => value maps, same shape as that function even though
 * the minifig-detail modal only ever submits one key per save (a single
 * part tile at a time, not a combined form) — accepting the same shape
 * keeps this a straight mirror and costs nothing extra.
 */
function applyMinifigStorageItemPartInventory(PDO $pdo, int $minifigStorageItemId, string $figNum, array $ownedInput, array $damagedInput): void
{
    $nominalByKey = [];
    foreach (getMinifigStorageItemPartsWithStatus($pdo, $minifigStorageItemId, $figNum) as $part) {
        $nominalByKey[$part['part_id'] . ':' . $part['color_id']] = $part['nominal_quantity'];
    }
    $stmt = $pdo->prepare(
        'INSERT INTO minifig_storage_item_parts (minifig_storage_item_id, part_id, color_id, quantity, damaged_quantity)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), damaged_quantity = VALUES(damaged_quantity)'
    );
    foreach ($ownedInput as $key => $rawOwned) {
        if (!isset($nominalByKey[$key])) {
            continue;
        }
        [$partId, $colorId] = array_map('intval', explode(':', (string) $key, 2));
        $ownedQuantity = max(0, min((int) $rawOwned, $nominalByKey[$key]));
        $damagedQuantity = max(0, min((int) ($damagedInput[$key] ?? 0), $ownedQuantity));
        $stmt->execute([$minifigStorageItemId, $partId, $colorId, $ownedQuantity, $damagedQuantity]);
    }
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
