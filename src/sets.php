<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/parts.php';

const SETS_SEARCH_PAGE_SIZE = 100;

/**
 * One set card, linking to the set's detail page. No year on the card
 * itself — results are grouped under a year heading (see
 * renderYearGroupedCards() in index.php), so it'd be redundant here.
 */
/**
 * $set['owned_count'] is optional — callers that want the "already in my
 * collection" green border + Nx badge attach it themselves (batched via
 * getOwnedSetCountsForSets(), not a per-card query) before rendering;
 * callers that never touch ownership (most of them) just omit it and get
 * the plain card exactly as before.
 */
function renderSetCard(array $set): string
{
    $ownedCount = $set['owned_count'] ?? 0;
    $html = '<a class="set-card' . ($ownedCount > 0 ? ' set-card-owned' : '') . '" href="?page=set_detail&id=' . (int) $set['id'] . '">';
    $html .= '<span class="set-card-image">' . ($set['thumbnail'] !== null ? '<img src="' . htmlspecialchars($set['thumbnail']) . '" alt="">' : getNavIcon('sets')) . '</span>';
    if ($ownedCount > 1) {
        $html .= '<span class="set-card-owned-badge">' . (int) $ownedCount . 'x</span>';
    }
    $html .= '<span class="set-card-num">' . htmlspecialchars($set['rebrickable_set_num']) . '</span>';
    $html .= '<span class="set-card-name" title="' . htmlspecialchars($set['name']) . '">' . htmlspecialchars($set['name']) . '</span>';
    $html .= '</a>';
    return $html;
}

/**
 * Attaches 'owned_count' to each $items row in place (matched by 'id',
 * batched in one query via getOwnedSetCountsForSets()) so renderSetCard()
 * can show the ownership badge — call before rendering, not per-card.
 *
 * @param array<int, array<string, mixed>> $items rows with an 'id' key (sets.id)
 * @return array<int, array<string, mixed>>
 */
function attachOwnedCounts(PDO $pdo, array $items): array
{
    if (empty($items)) {
        return $items;
    }
    $counts = getOwnedSetCountsForSets($pdo, array_column($items, 'id'));
    foreach ($items as &$item) {
        $item['owned_count'] = $counts[(int) $item['id']] ?? 0;
    }
    unset($item);
    return $items;
}

/**
 * Set "categories" are Rebrickable themes, which form a real hierarchy
 * (themes.parent_theme_id — e.g. "Star Wars" has subthemes like "Star Wars
 * Episode 1"). sets.theme stores the raw theme_id as a string — the real
 * sets.csv only ships theme_id, no readable name column (see the
 * CSV-column-mismatch audit) — so getting a display name always requires
 * this join through themes.theme_id.
 *
 * Loads the whole tree in one query and builds parent/child links in PHP —
 * not a recursive SQL CTE (same reasoning as storage.php's
 * getStorageLocationTree(): can't assume MariaDB WITH RECURSIVE support on
 * shared hosting). Only ~500 rows total, cheap to hold in memory for the
 * one request that needs it. Each node's 'recursive_count' (its own direct
 * set count plus every descendant's) is computed bottom-up afterwards —
 * used to decide which tiles are worth showing (a theme with 0 sets
 * anywhere in its subtree isn't) and to label tiles with a total.
 *
 * The CAST(th.theme_id AS CHAR) is load-bearing, not decoration: sets.theme
 * is VARCHAR while themes.theme_id is INT, and comparing them directly
 * silently defeats idx_sets_theme (migration 20) — MariaDB can't use a
 * VARCHAR index for an implicit INT->VARCHAR comparison per outer row, so it
 * falls back to "Range checked for each record", which is actually *worse*
 * than the pre-index full scan (measured ~13.7M rows examined vs. ~27k).
 * Casting the INT side to match the indexed column's type is what lets
 * MariaDB do a real per-row index lookup (measured 3.1s -> 0.03s).
 *
 * @return array{byId: array<int, array{theme_id:int, name:string, parent_theme_id:?int, direct_count:int, recursive_count:int, children: array}>, roots: array}
 */
function getSetThemeTree(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT th.theme_id, th.name, th.parent_theme_id, COUNT(s.id) AS direct_count
         FROM themes th
         LEFT JOIN sets s ON s.theme = CAST(th.theme_id AS CHAR)
         GROUP BY th.theme_id, th.name, th.parent_theme_id'
    )->fetchAll();
    return buildThemeTree($rows);
}

/**
 * Same tree shape as getSetThemeTree(), but counting owned_sets instead of
 * the catalog-wide sets table — powers "themes I own sets from" (my_sets_themes).
 */
function getOwnedSetThemeTree(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT th.theme_id, th.name, th.parent_theme_id, COUNT(os.id) AS direct_count
         FROM themes th
         LEFT JOIN sets s ON s.theme = CAST(th.theme_id AS CHAR)
         LEFT JOIN owned_sets os ON os.set_id = s.id
         GROUP BY th.theme_id, th.name, th.parent_theme_id'
    )->fetchAll();
    return buildThemeTree($rows);
}

/**
 * The hierarchy-building/count-bubbling logic shared by getSetThemeTree()
 * and getOwnedSetThemeTree() — which theme is a child of which never
 * changes, only what's being counted per theme does.
 *
 * @param array<int, array{theme_id:mixed, name:string, parent_theme_id:mixed, direct_count:mixed}> $rows every theme, regardless of whether direct_count is 0
 * @return array{byId: array<int, array{theme_id:int, name:string, parent_theme_id:?int, direct_count:int, recursive_count:int, children: array}>, roots: array}
 */
function buildThemeTree(array $rows): array
{
    $byId = [];
    foreach ($rows as $row) {
        $themeId = (int) $row['theme_id'];
        $byId[$themeId] = [
            'theme_id' => $themeId,
            'name' => $row['name'],
            'parent_theme_id' => $row['parent_theme_id'] !== null ? (int) $row['parent_theme_id'] : null,
            'direct_count' => (int) $row['direct_count'],
            'recursive_count' => 0,
            'children' => [],
        ];
    }

    $roots = [];
    foreach ($byId as $themeId => &$node) {
        $parentId = $node['parent_theme_id'];
        if ($parentId !== null && isset($byId[$parentId])) {
            $byId[$parentId]['children'][] = &$node;
        } else {
            $roots[] = &$node;
        }
    }
    unset($node);

    $computeRecursive = function (array &$node) use (&$computeRecursive): int {
        $total = $node['direct_count'];
        foreach ($node['children'] as &$child) {
            $total += $computeRecursive($child);
        }
        unset($child);
        $node['recursive_count'] = $total;
        return $total;
    };
    foreach ($roots as &$root) {
        $computeRecursive($root);
    }
    unset($root);

    return ['byId' => $byId, 'roots' => $roots];
}

/**
 * Immediate children of a theme (or top-level themes when $parentThemeId is
 * null) that have at least one set anywhere in their subtree — mirrors
 * storage.php's getChildLocations(), one level of the tree at a time.
 * Operates on an already-loaded getSetThemeTree() result, not $pdo, since a
 * page typically needs this alongside getThemeAndDescendantIds()/
 * getThemeAncestors() and the tree is cheap enough to load once and reuse.
 *
 * @return array<int, array{theme_id:int, name:string, recursive_count:int}>
 */
function getSetThemeChildren(array $tree, ?int $parentThemeId): array
{
    $nodes = $parentThemeId === null ? $tree['roots'] : ($tree['byId'][$parentThemeId]['children'] ?? []);
    $result = array_values(array_filter($nodes, function (array $node): bool {
        return $node['recursive_count'] > 0;
    }));
    usort($result, function (array $a, array $b): int {
        return strcmp($a['name'], $b['name']);
    });
    return $result;
}

/**
 * A theme's own theme_id plus every descendant's, flattened — for a
 * "show all sets in this theme and its subthemes" content query (chosen
 * behavior: browsing into a parent theme shows the combined recursive set
 * list, not just sets tagged with that exact theme_id).
 *
 * @return int[]
 */
function getThemeAndDescendantIds(array $tree, int $themeId): array
{
    $node = $tree['byId'][$themeId] ?? null;
    if ($node === null) {
        return [$themeId];
    }
    $ids = [];
    $collect = function (array $n) use (&$ids, &$collect): void {
        $ids[] = $n['theme_id'];
        foreach ($n['children'] as $child) {
            $collect($child);
        }
    };
    $collect($node);
    return $ids;
}

/**
 * Ancestor chain root-first, ending with the theme itself — for breadcrumbs.
 *
 * @return array<int, array{theme_id:int, name:string}>
 */
function getThemeAncestors(array $tree, int $themeId): array
{
    $chain = [];
    $current = $themeId;
    $guard = 0;
    while ($current !== null && isset($tree['byId'][$current]) && $guard++ < 20) {
        $node = $tree['byId'][$current];
        array_unshift($chain, ['theme_id' => $node['theme_id'], 'name' => $node['name']]);
        $current = $node['parent_theme_id'];
    }
    return $chain;
}

/**
 * Where to send the user after removing a set from their collection — back
 * into "Meine Sets nach Themen" at the theme the set was filed under, if
 * that theme (or one of its subthemes) still has an owned set; otherwise
 * one level up, and so on up the ancestor chain; and if nothing up the
 * whole chain does either (e.g. the collection is now empty), the flat
 * "Meine Sets" list (same destination the nav's own ?page=my_sets link
 * redirects to). $tree must be built (getOwnedSetThemeTree()) *after* the
 * removal so recursive_count already reflects it.
 */
function resolveOwnedSetRemovalRedirect(array $tree, ?int $themeId): string
{
    $current = $themeId;
    $guard = 0;
    while ($current !== null && isset($tree['byId'][$current]) && $guard++ < 20) {
        if ($tree['byId'][$current]['recursive_count'] > 0) {
            return '?page=my_sets_themes&theme=' . $current;
        }
        $current = $tree['byId'][$current]['parent_theme_id'];
    }
    return '?page=my_sets_all';
}

/**
 * One representative set image per tile, for the theme tile grid — mirrors
 * parts.php's getCategoryTileImages(). $themeIdGroups maps each tile's own
 * theme_id to the full list of ids to search an image among (itself + all
 * descendants, typically from getThemeAndDescendantIds()) — a parent theme
 * can have zero sets tagged with it directly while its subthemes have
 * plenty, and the tile should still get a representative image from one of
 * those rather than showing the fallback icon.
 *
 * @param array<int, int[]> $themeIdGroups keyed by the tile's own theme_id
 * @return array<string, string>
 */
function getThemeTileImages(PDO $pdo, array $themeIdGroups): array
{
    $result = [];
    foreach ($themeIdGroups as $tileThemeId => $searchIds) {
        if (empty($searchIds)) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($searchIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT local_image_path FROM sets
             WHERE theme IN ($placeholders) AND local_image_path IS NOT NULL AND local_image_path != ''
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
 * @return array{id:int, rebrickable_set_num:string, name:string, year:?int, year_retired:?int, num_parts:?int, thumbnail:?string, theme_id:?int, theme_name:?string, bricklink_item_id:?int, bricklink_price_new:?float, bricklink_price_used:?float, bricklink_price_currency:?string, bricklink_price_checked_at:?string}|null
 */
function getSetById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.rebrickable_set_num, s.name, s.year, s.year_retired, s.num_parts, s.local_image_path AS thumbnail, th.theme_id, th.name AS theme_name,
                s.bricklink_item_id, s.bricklink_price_new, s.bricklink_price_used, s.bricklink_price_currency, s.bricklink_price_checked_at
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
    $set['theme_id'] = $set['theme_id'] !== null ? (int) $set['theme_id'] : null;
    $set['bricklink_item_id'] = $set['bricklink_item_id'] !== null ? (int) $set['bricklink_item_id'] : null;
    $set['bricklink_price_new'] = $set['bricklink_price_new'] !== null ? (float) $set['bricklink_price_new'] : null;
    $set['bricklink_price_used'] = $set['bricklink_price_used'] !== null ? (float) $set['bricklink_price_used'] : null;
    return $set;
}

/**
 * The set-detail header's general info table (Name/Erschienen/
 * Rücknahmejahr/Thema) plus the retired-year inline-edit toggle+script —
 * shared by the catalog set-detail page and owned_set_detail's own header,
 * since both show the same catalog-level set metadata. The retired year
 * genuinely belongs to the catalog set (not any one owned instance), so
 * editing it from either page updates the same row via the same
 * save_set_retired_year action. $set is a getSetById() result.
 *
 * $themeTree lets a caller that already built one (set_detail's own
 * breadcrumbs, via getSetThemeTree()) pass it in instead of this function
 * loading its own — getSetThemeTree() isn't free (a full-table join across
 * every set for every theme), so building it twice on the same page render
 * was measurably wasteful. Callers without one already (owned_set_detail)
 * just leave it null and this loads it as before.
 */
function renderSetGeneralInfoTable(PDO $pdo, array $set, ?array $themeTree = null): string
{
    $content = '<div class="set-detail-table-wrap">';
    $content .= '<table class="set-detail-table">';
    $content .= '<tr><th>' . htmlspecialchars(t('set_detail_field_name')) . '</th><td>' . htmlspecialchars($set['name']) . '</td></tr>';
    if ($set['year'] !== null) {
        $content .= '<tr><th>' . htmlspecialchars(t('set_detail_field_released')) . '</th><td id="set-detail-year-text">' . htmlspecialchars((string) $set['year']) . '</td></tr>';
    }
    $content .= '<tr><th>' . htmlspecialchars(t('set_detail_field_eol')) . '</th><td class="set-detail-retired-year">';
    $content .= '<a href="#" id="set-retired-year-toggle">' . htmlspecialchars($set['year_retired'] !== null ? (string) $set['year_retired'] . ' · ' . t('set_detail_edit_retired_year_button') : t('set_detail_add_retired_year_button')) . '</a>';
    $content .= '<form id="set-retired-year-form" class="set-retired-year-form" style="display:none;">';
    $content .= '<input type="number" id="set-retired-year-input" min="1900" max="2100" placeholder="' . htmlspecialchars(t('set_detail_retired_year_placeholder')) . '" value="' . ($set['year_retired'] !== null ? (int) $set['year_retired'] : '') . '">';
    $content .= '<button type="submit">' . htmlspecialchars(t('set_detail_retired_year_save_button')) . '</button>';
    $content .= '<button type="button" id="set-retired-year-cancel">' . htmlspecialchars(t('set_detail_retired_year_cancel_button')) . '</button>';
    $content .= '<span class="set-retired-year-message" id="set-retired-year-message"></span>';
    $content .= '</form>';
    $content .= '</td></tr>';
    if ($set['theme_id'] !== null) {
        $themeTree ??= getSetThemeTree($pdo);
        $themePath = implode(' » ', array_column(getThemeAncestors($themeTree, $set['theme_id']), 'name'));
        $content .= '<tr><th>' . htmlspecialchars(t('set_detail_field_theme')) . '</th><td>' . htmlspecialchars($themePath) . '</td></tr>';
    }
    $content .= '</table>';
    $content .= '</div>';

    $retiredYearLabelsJson = json_encode([
        'addButton' => t('set_detail_add_retired_year_button'),
        'editButton' => t('set_detail_edit_retired_year_button'),
        'errorRetry' => t('import_error_retry'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $setId = (int) $set['id'];

    $content .= <<<SCRIPT
<script>
(function(){
  var texts = $retiredYearLabelsJson;
  var toggle = document.getElementById('set-retired-year-toggle');
  var form = document.getElementById('set-retired-year-form');
  var input = document.getElementById('set-retired-year-input');
  var cancelBtn = document.getElementById('set-retired-year-cancel');
  var msg = document.getElementById('set-retired-year-message');
  if (!toggle || !form || !input || !cancelBtn || !msg) {
    return;
  }

  toggle.addEventListener('click', function(e) {
    e.preventDefault();
    toggle.style.display = 'none';
    form.style.display = 'inline-flex';
    input.focus();
  });
  cancelBtn.addEventListener('click', function() {
    form.style.display = 'none';
    toggle.style.display = 'inline-block';
    msg.textContent = '';
  });

  form.addEventListener('submit', function(e) {
    e.preventDefault();
    msg.textContent = '';
    var formData = new FormData();
    formData.set('action', 'save_set_retired_year');
    formData.set('set_id', '$setId');
    formData.set('year_retired', input.value);

    fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success) {
          form.style.display = 'none';
          toggle.style.display = 'inline-block';
          toggle.textContent = res.yearRetired ? (res.yearRetired + ' · ' + texts.editButton) : texts.addButton;
        } else {
          msg.textContent = res.message;
        }
      })
      .catch(function() {
        msg.textContent = texts.errorRetry;
      });
  });
})();
</script>
SCRIPT;

    return $content;
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
 * The set immediately before/after $setNum in catalog order, for the
 * set_detail header's prev/next navigation. Rebrickable set numbers are
 * "<base>-<variant>" (e.g. "4558-1") — comparing the raw string sorts
 * wrong once base numbers have different digit counts ("952-1" would land
 * after "10220-1"), so both parts are split out and compared numerically
 * via a SQL row-value comparison.
 *
 * @return array{prev: ?array{id:int, rebrickable_set_num:string}, next: ?array{id:int, rebrickable_set_num:string}}
 */
function getAdjacentSets(PDO $pdo, string $setNum): array
{
    $base = "CAST(SUBSTRING_INDEX(rebrickable_set_num, '-', 1) AS UNSIGNED)";
    $variant = "CAST(SUBSTRING_INDEX(rebrickable_set_num, '-', -1) AS UNSIGNED)";
    $currentBase = "CAST(SUBSTRING_INDEX(?, '-', 1) AS UNSIGNED)";
    $currentVariant = "CAST(SUBSTRING_INDEX(?, '-', -1) AS UNSIGNED)";

    $prevStmt = $pdo->prepare(
        "SELECT id, rebrickable_set_num FROM sets
         WHERE ($base, $variant) < ($currentBase, $currentVariant)
         ORDER BY $base DESC, $variant DESC
         LIMIT 1"
    );
    $prevStmt->execute([$setNum, $setNum]);
    $prev = $prevStmt->fetch();

    $nextStmt = $pdo->prepare(
        "SELECT id, rebrickable_set_num FROM sets
         WHERE ($base, $variant) > ($currentBase, $currentVariant)
         ORDER BY $base ASC, $variant ASC
         LIMIT 1"
    );
    $nextStmt->execute([$setNum, $setNum]);
    $next = $nextStmt->fetch();

    return [
        'prev' => $prev !== false ? ['id' => (int) $prev['id'], 'rebrickable_set_num' => $prev['rebrickable_set_num']] : null,
        'next' => $next !== false ? ['id' => (int) $next['id'], 'rebrickable_set_num' => $next['rebrickable_set_num']] : null,
    ];
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
 * $locale, when not 'en', overlays any crowdsourced translation
 * (part_translations) onto each row's name — mirrors bricks_search's
 * applyPartTranslations(), reimplemented here rather than reused because
 * that helper keys results by an 'id' field these rows don't have
 * (they're keyed by 'part_id', since a row is really a part+color pair,
 * not a bare part).
 *
 * @return array<int, array{part_id:int, part_num:string, name:string, color_id:?int, rebrickable_color_id:?int, color_name:?string, color_rgb:?string, quantity:int, thumbnail:?string, remote_thumbnail:?string, ldraw_thumbnail:?string}>
 */
function getSetPartsList(PDO $pdo, int $inventoryId, bool $spares, string $locale = 'en'): array
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

    if ($locale !== 'en' && !empty($rows)) {
        $translations = getPartTranslations($pdo, array_values(array_unique(array_column($rows, 'part_id'))), $locale);
        foreach ($rows as &$row) {
            $translated = $translations[$row['part_id']] ?? null;
            if ($translated !== null) {
                $row['name'] = $translated;
            }
        }
        unset($row);
    }

    return $rows;
}

/**
 * How many distinct sets each part+color combination (Rebrickable's own
 * color_id, matching inventory_parts.color_id — same reasoning as
 * getSetPartsList()) appears in across the whole catalog, non-spare —
 * powers the inventory tab's exclusive/rare/normal grouping.
 *
 * Computing this live doesn't scale: even scoped to one part_id+color_id
 * pair (via idx_inventory_parts_color_part) a single common combination
 * measured ~0.15s, and a realistic set's worth of ~150 pairs measured
 * 3.6s — far too slow for a page load. Results are instead cached
 * per-part+color in part_set_counts (first computed here on a cache miss,
 * reused by every later view of any set sharing that part+color — most
 * parts, once seen once, are then fast forever) and invalidated wholesale
 * after a Rebrickable resync (src/download.php), since new sets can change
 * the counts.
 *
 * @param array<int, array{part_id:int, color_id:int}> $partColorPairs
 * @return array<string, int> keyed by "{part_id}:{color_id}"
 */
function getPartSetCounts(PDO $pdo, array $partColorPairs): array
{
    if (empty($partColorPairs)) {
        return [];
    }

    $uniquePairs = [];
    foreach ($partColorPairs as $pair) {
        $uniquePairs[$pair['part_id'] . ':' . $pair['color_id']] = $pair;
    }

    $placeholders = implode(',', array_fill(0, count($uniquePairs), '(?,?)'));
    $params = [];
    foreach ($uniquePairs as $pair) {
        $params[] = $pair['part_id'];
        $params[] = $pair['color_id'];
    }

    $result = [];
    $stmt = $pdo->prepare("SELECT part_id, color_id, set_count FROM part_set_counts WHERE (part_id, color_id) IN ($placeholders)");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $result[$row['part_id'] . ':' . $row['color_id']] = (int) $row['set_count'];
    }

    $missing = array_filter($uniquePairs, function (array $pair) use ($result): bool {
        return !isset($result[$pair['part_id'] . ':' . $pair['color_id']]);
    });
    if (empty($missing)) {
        return $result;
    }

    $missingPlaceholders = implode(',', array_fill(0, count($missing), '(?,?)'));
    $missingParams = [];
    foreach ($missing as $pair) {
        $missingParams[] = $pair['part_id'];
        $missingParams[] = $pair['color_id'];
    }
    $computeStmt = $pdo->prepare(
        "SELECT ip.part_id, ip.color_id, COUNT(DISTINCT ri.set_num) AS set_count
         FROM inventory_parts ip
         INNER JOIN rebrickable_inventories ri ON ri.inventory_id = ip.inventory_id
         WHERE (ip.part_id, ip.color_id) IN ($missingPlaceholders) AND ip.is_spare = 0
         GROUP BY ip.part_id, ip.color_id"
    );
    $computeStmt->execute($missingParams);

    $insertStmt = $pdo->prepare(
        'INSERT INTO part_set_counts (part_id, color_id, set_count) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE set_count = VALUES(set_count), computed_at = CURRENT_TIMESTAMP'
    );
    foreach ($computeStmt->fetchAll() as $row) {
        $partId = (int) $row['part_id'];
        $colorId = (int) $row['color_id'];
        $setCount = (int) $row['set_count'];
        $result[$partId . ':' . $colorId] = $setCount;
        $insertStmt->execute([$partId, $colorId, $setCount]);
    }

    // A pair with zero sets found shouldn't normally happen (it came from
    // an actual inventory row, so it's in at least this one set) — cached
    // as 0 defensively so a future call doesn't keep re-querying it.
    foreach ($missing as $pair) {
        $key = $pair['part_id'] . ':' . $pair['color_id'];
        if (!isset($result[$key])) {
            $result[$key] = 0;
            $insertStmt->execute([$pair['part_id'], $pair['color_id'], 0]);
        }
    }

    return $result;
}

/**
 * Distinct part_ids in an inventory (non-spare) belonging to Rebrickable's
 * "Stickers" part category — sticker sheets get their own group instead of
 * being bucketed as exclusive/rare/normal like regular parts, in both the
 * set_detail header summary and the inventory tab's card grouping.
 *
 * @return array<int, bool> part_id => true, for fast isset() lookups
 */
function getStickerPartIds(PDO $pdo, int $inventoryId): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT ip.part_id FROM inventory_parts ip
         INNER JOIN parts p ON p.id = ip.part_id
         INNER JOIN part_categories pc ON pc.part_cat_id = p.part_category
         WHERE ip.inventory_id = ? AND ip.is_spare = 0 AND pc.name = 'Stickers'"
    );
    $stmt->execute([$inventoryId]);
    return array_flip(array_map('intval', array_column($stmt->fetchAll(), 'part_id')));
}

/**
 * Header-level rarity/sticker counts for a set's current inventory — mirrors
 * the exclusive (appears in exactly 1 set) / rare (2-3 sets) bucketing used
 * by index.php's inventory-tab card grouping, but only needs the counts, not
 * rendered cards, since this feeds the set_detail header shown on every tab
 * regardless of which inventory-version tab is active. Exclusive/rare are
 * piece counts (SUM of quantity), not distinct-part counts — a part used 4x
 * in this set and nowhere else contributes 4 to "Exklusive", not 1. Sticker
 * sheets are their own group and don't count toward exclusive/rare at all.
 *
 * @return array{exclusive:int, rare:int, stickers:int}
 */
function getSetInventorySummary(PDO $pdo, int $inventoryId, string $locale): array
{
    $items = getSetPartsList($pdo, $inventoryId, false, $locale);
    $stickerPartIds = getStickerPartIds($pdo, $inventoryId);

    $pairs = [];
    foreach ($items as $item) {
        if ($item['rebrickable_color_id'] !== null && !isset($stickerPartIds[$item['part_id']])) {
            $pairs[] = ['part_id' => $item['part_id'], 'color_id' => $item['rebrickable_color_id']];
        }
    }
    $setCounts = getPartSetCounts($pdo, $pairs);

    $exclusive = 0;
    $rare = 0;
    $stickers = 0;
    foreach ($items as $item) {
        if (isset($stickerPartIds[$item['part_id']])) {
            $stickers++;
            continue;
        }
        $count = $item['rebrickable_color_id'] !== null
            ? ($setCounts[$item['part_id'] . ':' . $item['rebrickable_color_id']] ?? 0)
            : 0;
        if ($count === 1) {
            $exclusive += $item['quantity'];
        } elseif ($count >= 2 && $count <= 3) {
            $rare += $item['quantity'];
        }
    }

    return ['exclusive' => $exclusive, 'rare' => $rare, 'stickers' => $stickers];
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
function getSetInventoryComparison(PDO $pdo, array $versions, string $locale = 'en'): array
{
    $rows = [];
    foreach ($versions as $v) {
        $items = getSetPartsList($pdo, $v['inventory_id'], false, $locale);
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
         ORDER BY s.year ASC, s.rebrickable_set_num ASC
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
