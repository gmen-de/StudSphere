<?php

declare(strict_types=1);

require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/i18n.php';

// Infinite-scroll batch size: first batch loaded on page render, then one
// more batch of the same size per scroll-triggered continuation request.
const PARTS_SEARCH_PAGE_SIZE = 500;
const PARTS_POPULAR_CATEGORY_LIMIT = 12;

/**
 * One part card, used everywhere a part appears in a grid — the bricks
 * search results, and (via renderPartDetailModal(), which delegates its
 * click-to-open handler on `document` rather than a specific grid id) any
 * other page that lists parts, e.g. a set's inventory tab. $meta, when
 * given, is rendered as an extra line under the name (e.g. "Rot · 4x" for a
 * set's per-color quantity) — the bricks search grid doesn't need it.
 *
 * $fetchColorId, when given (Rebrickable's own color_id — see
 * part_images.php), marks the card as still needing its color-correct image
 * (data-color-id attribute, no visible per-card control) — only meaningful
 * where a card already represents one specific color (a set's inventory
 * list), not the catalog-wide bricks search grid. A single bulk "load
 * missing images" button elsewhere on the page (see
 * renderFetchMissingImagesButton() in part_images.php) scans for these.
 */
function renderPartCard(array $part, ?string $meta = null, ?int $fetchColorId = null): string
{
    $dataColorAttr = $fetchColorId !== null ? ' data-color-id="' . $fetchColorId . '"' : '';
    $html = '<div class="part-card" data-part-id="' . (int) $part['id'] . '"' . $dataColorAttr . ' role="button" tabindex="0">';
    $html .= '<span class="part-card-image">' . ($part['thumbnail'] !== null ? '<img src="' . htmlspecialchars($part['thumbnail']) . '" alt="">' : getNavIcon('bricks')) . '</span>';
    $html .= '<span class="part-card-num">' . htmlspecialchars($part['part_num']) . '</span>';
    $html .= '<span class="part-card-name" title="' . htmlspecialchars($part['name']) . '">' . htmlspecialchars($part['name']) . '</span>';
    if ($meta !== null) {
        $html .= '<span class="part-card-meta">' . htmlspecialchars($meta) . '</span>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Canonical category list (id + name), matching Rebrickable's part_categories
 * catalog — independent of whether any parts.part_category value currently
 * references them, so the tile browser and the plain search dropdown reflect
 * the full known category set.
 */
function getPartCategories(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT part_cat_id, name FROM part_categories ORDER BY name ASC');
    return $stmt->fetchAll();
}

/**
 * Category facet for the results sidebar: only categories with at least one
 * imported part, with a live count — mirrors Rebrickable's "Category" drill
 * down. Counts are against the whole catalog, not cross-filtered by other
 * active facets (kept simple/fast rather than a full faceted-search engine).
 */
function getPartCategoriesWithCounts(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT pc.part_cat_id, pc.name, COUNT(p.id) AS cnt
         FROM part_categories pc
         INNER JOIN parts p ON p.part_category = pc.part_cat_id
         GROUP BY pc.part_cat_id, pc.name
         HAVING cnt > 0
         ORDER BY pc.name ASC'
    );
    return $stmt->fetchAll();
}

/**
 * Color facet for the results sidebar, mirroring Rebrickable's "Has Appeared
 * in Color" drill down. Rebrickable's "Material"/"Tags" facets aren't
 * derivable from the public CSV downloads we import, so they're not offered.
 */
function getColorFacet(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT c.color_id, c.name, c.rgb, COUNT(DISTINCT ip.part_id) AS cnt
         FROM colors c
         INNER JOIN inventory_parts ip ON ip.color_id = c.color_id
         GROUP BY c.color_id, c.name, c.rgb
         HAVING cnt > 0
         ORDER BY c.name ASC'
    );
    return $stmt->fetchAll();
}

/**
 * Categories ranked by how many imported parts reference them — used as a
 * stand-in for Rebrickable's curated "Popular" tile filter, which isn't
 * derivable from the public CSV downloads.
 */
function getPopularPartCategories(PDO $pdo, int $limit): array
{
    $stmt = $pdo->prepare(
        'SELECT pc.part_cat_id, pc.name
         FROM part_categories pc
         INNER JOIN parts p ON p.part_category = pc.part_cat_id
         GROUP BY pc.part_cat_id, pc.name
         ORDER BY COUNT(*) DESC
         LIMIT ' . (int) $limit
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

function getPartCategoryName(PDO $pdo, string $categoryId): ?string
{
    $stmt = $pdo->prepare('SELECT name FROM part_categories WHERE part_cat_id = ?');
    $stmt->execute([$categoryId]);
    $name = $stmt->fetchColumn();
    return $name !== false ? (string) $name : null;
}

/**
 * Everything the part-detail overlay needs in one call: catalog fields, a
 * thumbnail, and set-appearance stats (count, total quantity, year range).
 * Rebrickable's own popup additionally shows BrickLink/BrickOwl/LDraw ids and
 * MOC counts — we don't import those external-id mappings or any MOC data,
 * so they're left out rather than faked (BrickLink/BrickOwl instead get a
 * best-effort catalog *search* link built from part_num, added by the
 * caller — see index.php's part_detail action).
 *
 * Set-appearance stats are computed from inventory_parts joined to
 * rebrickable_inventories (which carries set_num), not the separate
 * `set_parts` table — set_parts isn't populated by the main Rebrickable
 * sync, while inventory_parts is the table that actually has real data.
 * Spares are excluded to match Rebrickable's own "appears in" counts,
 * which count what's actually built into the model.
 *
 * The year range comes from `sets.year` via set_num, not
 * rebrickable_inventories.year — the real inventories.csv has no year
 * column at all, so that field is always NULL; year is a sets.csv field.
 */
function getPartDetail(PDO $pdo, int $partId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT p.id, p.part_num, p.name, p.part_category, pc.name AS category_name
         FROM parts p
         LEFT JOIN part_categories pc ON pc.part_cat_id = p.part_category
         WHERE p.id = ?'
    );
    $stmt->execute([$partId]);
    $part = $stmt->fetch();
    if ($part === false) {
        return null;
    }

    $thumbnails = getPartThumbnails($pdo, [$partId]);
    $part['thumbnail'] = $thumbnails[$partId] ?? null;

    $setsStmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT ri.set_num) AS set_count,
                COALESCE(SUM(ip.quantity), 0) AS total_appearances,
                MIN(s.year) AS min_year,
                MAX(s.year) AS max_year
         FROM inventory_parts ip
         INNER JOIN rebrickable_inventories ri ON ri.inventory_id = ip.inventory_id
         LEFT JOIN sets s ON s.rebrickable_set_num = ri.set_num
         WHERE ip.part_id = ? AND ip.is_spare = 0'
    );
    $setsStmt->execute([$partId]);
    $setsRow = $setsStmt->fetch();
    $part['sets_count'] = (int) ($setsRow['set_count'] ?? 0);
    $part['total_appearances'] = (int) ($setsRow['total_appearances'] ?? 0);
    $part['min_year'] = $setsRow['min_year'] !== null ? (int) $setsRow['min_year'] : null;
    $part['max_year'] = $setsRow['max_year'] !== null ? (int) $setsRow['max_year'] : null;

    return $part;
}

/**
 * For the "add to inventory" color picker. Returns `id` (the surrogate
 * primary key) as the selectable value — storage_items.color_id references
 * colors.id, whereas the catalog/search side of the app (inventory_parts,
 * getColorFacet()) keys off colors.color_id (Rebrickable's own numbering).
 * Mixing the two up trips the fk_storageitem_color foreign key.
 *
 * @return array<int, array{id:int, name:string, rgb:?string}>
 */
function getAllColors(PDO $pdo): array
{
    return $pdo->query('SELECT id, name, rgb FROM colors ORDER BY name ASC')->fetchAll();
}

/**
 * Colors a specific part is actually known to exist in (via inventory_parts),
 * split from the rest of the catalog — most parts were never molded in most
 * colors, so offering the full ~200-color list undifferentiated is
 * misleading. The picker shows "known" first, then the remainder grouped
 * under a divider, so an uncommon color is still reachable when needed.
 *
 * @return array{known: array<int, array{id:int, name:string, rgb:?string}>, other: array<int, array{id:int, name:string, rgb:?string}>}
 */
function getColorsForPartPicker(PDO $pdo, int $partId): array
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT c.id, c.name, c.rgb
         FROM inventory_parts ip
         INNER JOIN colors c ON c.color_id = ip.color_id
         WHERE ip.part_id = ?
         ORDER BY c.name ASC'
    );
    $stmt->execute([$partId]);
    $known = $stmt->fetchAll();

    $knownIds = array_map('intval', array_column($known, 'id'));
    $other = array_values(array_filter(getAllColors($pdo), function (array $c) use ($knownIds) {
        return !in_array((int) $c['id'], $knownIds, true);
    }));

    return ['known' => $known, 'other' => $other];
}

/**
 * If this part is a printed variant (part_relationships.relationship_type =
 * 'P'), returns the original, unprinted part it's a print of — mirrors
 * Rebrickable's "Print of: ..." reference.
 */
function getPrintParent(PDO $pdo, int $partId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT p.id, p.part_num, p.name
         FROM part_relationships pr
         INNER JOIN parts p ON p.id = pr.parent_part_id
         WHERE pr.child_part_id = ? AND pr.relationship_type = \'P\'
         LIMIT 1'
    );
    $stmt->execute([$partId]);
    $parent = $stmt->fetch();
    if ($parent === false) {
        return null;
    }

    $thumbnails = getPartThumbnails($pdo, [(int) $parent['id']]);
    $parent['thumbnail'] = $thumbnails[(int) $parent['id']] ?? null;

    return $parent;
}

/**
 * The actual sets a part appears in — backs the "N Sets" link in the
 * part-detail overlay. Same inventory_parts/rebrickable_inventories/sets
 * join as the summary stats in getPartDetail(), just returned as rows
 * instead of aggregated down to counts.
 *
 * @return array<int, array{set_num:string, name:?string, year:?int, quantity:int, thumbnail:?string}>
 */
function getPartSets(PDO $pdo, int $partId): array
{
    $stmt = $pdo->prepare(
        'SELECT ri.set_num, s.name, s.year, s.local_image_path AS thumbnail, SUM(ip.quantity) AS quantity
         FROM inventory_parts ip
         INNER JOIN rebrickable_inventories ri ON ri.inventory_id = ip.inventory_id
         LEFT JOIN sets s ON s.rebrickable_set_num = ri.set_num
         WHERE ip.part_id = ? AND ip.is_spare = 0
         GROUP BY ri.set_num, s.name, s.year, s.local_image_path
         ORDER BY s.year DESC, s.name ASC'
    );
    $stmt->execute([$partId]);
    $sets = $stmt->fetchAll();
    foreach ($sets as &$set) {
        $set['quantity'] = (int) $set['quantity'];
        $set['year'] = $set['year'] !== null ? (int) $set['year'] : null;
    }
    unset($set);
    return $sets;
}

/**
 * Representative thumbnails for a set of category tiles. Individual `parts`
 * rows have no image of their own (Rebrickable associates images with a
 * part+color combination), so this picks any locally-downloaded image per
 * category via inventory_parts.
 *
 * One indexed `... LIMIT 1` query per category, driven from `parts` (cheap:
 * indexed by category) joined out to `inventory_parts` (indexed by part_id).
 * This was previously a single GROUP BY query instead, on the theory that 60+
 * separate scans would cost more than one combined pass — true when few
 * images exist yet, but it backfires once most rows have one: with ~100%
 * image coverage (the steady state after a completed download) LIMIT 1 finds
 * a match on the very first row it checks per category, while GROUP BY still
 * has to aggregate over the whole table regardless. Measured on ~1.5M real
 * inventory_parts rows at ~99.5% coverage: GROUP BY 5.4s, per-category 0.4s.
 *
 * @param string[] $categoryIds
 * @return array<string, string> part_cat_id => local_image_path
 */
function getCategoryTileImages(PDO $pdo, array $categoryIds): array
{
    $stmt = $pdo->prepare(
        'SELECT ip.local_image_path
         FROM parts p
         INNER JOIN inventory_parts ip ON ip.part_id = p.id
         WHERE p.part_category = ? AND ip.local_image_path IS NOT NULL AND ip.local_image_path != \'\'
         LIMIT 1'
    );

    $result = [];
    foreach ($categoryIds as $categoryId) {
        $stmt->execute([$categoryId]);
        $path = $stmt->fetchColumn();
        if ($path !== false) {
            $result[(string) $categoryId] = (string) $path;
        }
    }
    return $result;
}

/**
 * Batched thumbnail lookup for a page of search results — a single query
 * regardless of page size, since page sizes here can go up to 5000 and an
 * N+1-per-row lookup at that scale would risk a shared-hosting timeout.
 * MIN() (rather than an unaggregated column) keeps this valid under
 * ONLY_FULL_GROUP_BY, which we can't assume is off on an unknown MariaDB.
 *
 * @param int[] $partIds
 * @return array<int, string> part_id => local_image_path
 */
function getPartThumbnails(PDO $pdo, array $partIds): array
{
    if (empty($partIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($partIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT part_id, MIN(local_image_path) AS local_image_path
         FROM inventory_parts
         WHERE part_id IN ($placeholders) AND local_image_path IS NOT NULL AND local_image_path != ''
         GROUP BY part_id"
    );
    $stmt->execute($partIds);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[(int) $row['part_id']] = (string) $row['local_image_path'];
    }
    return $result;
}

/**
 * @param string[] $categoryIds
 * @param string[] $colorIds
 * @return array{items: array, total: int, page: int, perPage: int}
 */
function searchParts(PDO $pdo, string $query, array $categoryIds, array $colorIds, bool $hidePrinted, int $page, int $perPage): array
{
    $conditions = [];
    $params = [];

    if ($query !== '') {
        $conditions[] = '(part_num LIKE ? OR name LIKE ?)';
        $params[] = '%' . $query . '%';
        $params[] = '%' . $query . '%';
    }
    if (!empty($categoryIds)) {
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $conditions[] = "part_category IN ($placeholders)";
        array_push($params, ...$categoryIds);
    }
    if (!empty($colorIds)) {
        $placeholders = implode(',', array_fill(0, count($colorIds), '?'));
        $conditions[] = "id IN (SELECT part_id FROM inventory_parts WHERE color_id IN ($placeholders))";
        array_push($params, ...$colorIds);
    }
    if ($hidePrinted) {
        $conditions[] = "id NOT IN (SELECT child_part_id FROM part_relationships WHERE relationship_type = 'P')";
    }

    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM parts $where");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $page = max(1, $page);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("SELECT id, part_num, name, part_category FROM parts $where ORDER BY part_num ASC LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    $thumbnails = getPartThumbnails($pdo, array_map('intval', array_column($items, 'id')));
    foreach ($items as &$item) {
        $item['thumbnail'] = $thumbnails[(int) $item['id']] ?? null;
    }
    unset($item);

    return ['items' => $items, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
}

/**
 * Overlays user-contributed translations onto a batch of search-result
 * parts (as from searchParts()), replacing `name` where a translation for
 * $locale exists. No-op for the source locale ('en') — there's nothing to
 * translate the Rebrickable names into.
 */
function applyPartTranslations(PDO $pdo, array $items, string $locale): array
{
    if ($locale === 'en' || empty($items)) {
        return $items;
    }
    $translations = getPartTranslations($pdo, array_map('intval', array_column($items, 'id')), $locale);
    foreach ($items as &$item) {
        $translated = $translations[(int) $item['id']] ?? null;
        if ($translated !== null) {
            $item['name'] = $translated;
        }
    }
    unset($item);
    return $items;
}

/**
 * Batched lookup for a page of search results, mirroring
 * getPartThumbnails() — one query for the whole page instead of one per
 * row, since a page can be up to 500 parts.
 *
 * @param int[] $partIds
 * @return array<int, string> part_id => translated name
 */
function getPartTranslations(PDO $pdo, array $partIds, string $locale): array
{
    if (empty($partIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($partIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT part_id, name FROM part_translations WHERE locale = ? AND part_id IN ($placeholders)"
    );
    $stmt->execute(array_merge([$locale], $partIds));

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[(int) $row['part_id']] = (string) $row['name'];
    }
    return $result;
}

function getPartTranslation(PDO $pdo, int $partId, string $locale): ?string
{
    $stmt = $pdo->prepare('SELECT name FROM part_translations WHERE part_id = ? AND locale = ?');
    $stmt->execute([$partId, $locale]);
    $name = $stmt->fetchColumn();
    return $name !== false ? (string) $name : null;
}

/**
 * User-contributed translations are shared/global across everyone on this
 * instance (they're objective catalog data, not personal opinion) — upsert
 * on the (part_id, locale) unique key so a second submission edits the
 * existing one instead of erroring or duplicating.
 */
function savePartTranslation(PDO $pdo, int $partId, string $locale, string $name, ?int $userId): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO part_translations (part_id, locale, name, user_id)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), user_id = VALUES(user_id)'
    );
    $stmt->execute([$partId, $locale, $name, $userId]);
}
