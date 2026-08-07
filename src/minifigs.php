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
 * Shared corner-badge markup for a grouped-by-model card — three ampel dots
 * (green/yellow/red) with each bucket's own instance count. Used by both
 * renderOwnedMinifigGroupCard() (below) and renderOwnedSetGroupCard()
 * (src/owned_sets.php, which already requires this file) — see
 * getOwnedMinifigInstanceStatus()/getOwnedSetInstanceStatus() for how an
 * individual instance is bucketed into one of the three counts.
 */
function renderOwnedStatusBadges(int $completeCount, int $damagedCount, int $missingCount): string
{
    $html = '<span class="owned-status-badges">';
    $html .= '<span class="owned-status-dot owned-status-dot-complete" title="' . htmlspecialchars(t('owned_status_complete')) . '">' . $completeCount . '</span>';
    $html .= '<span class="owned-status-dot owned-status-dot-damaged" title="' . htmlspecialchars(t('owned_status_damaged')) . '">' . $damagedCount . '</span>';
    $html .= '<span class="owned-status-dot owned-status-dot-missing" title="' . htmlspecialchars(t('owned_status_missing')) . '">' . $missingCount . '</span>';
    $html .= '</span>';
    return $html;
}

/**
 * Shared "Exemplar wählen" modal for owned_set_detail/owned_minifig_detail
 * (src/routes/pages.php) — a scrollable radio-row list plus a confirm
 * button, replacing a plain <select> whose <option> text (location path +
 * condition + status all crammed in) made the dropdown uncomfortably wide;
 * a modal row can lay that out properly and show each copy's own ampel
 * status dot (renderOwnedStatusBadges()'s single-dot building block)
 * besides. Fixed element ids either way — only one of the two detail pages
 * is ever rendered at a time, so there's no risk of two such modals
 * colliding on the same page. Returns '' when there's nothing to pick
 * between (single-instance case), same as the withheld trigger button at
 * both call sites.
 *
 * @param array<int, array{id:int, label:string, meta:string, status:string}> $instances
 */
function renderOwnedInstancePickerModal(array $instances, int $currentId, string $targetPage): string
{
    if (count($instances) <= 1) {
        return '';
    }

    $html = '<div class="modal-overlay" id="owned-instance-picker-modal" style="display:none;">';
    $html .= '<div class="modal-box">';
    $html .= '<button type="button" class="modal-close" id="owned-instance-picker-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '<h2>' . htmlspecialchars(t('owned_instance_picker_heading')) . '</h2>';
    $html .= '<div class="owned-instance-picker-list">';
    foreach ($instances as $instance) {
        $checkedAttr = $instance['id'] === $currentId ? ' checked' : '';
        $html .= '<label class="owned-instance-picker-row">';
        $html .= '<input type="radio" name="owned-instance-picker-choice" value="?page=' . htmlspecialchars($targetPage) . '&id=' . (int) $instance['id'] . '"' . $checkedAttr . '>';
        $html .= '<span class="owned-status-dot owned-status-dot-' . htmlspecialchars($instance['status']) . '" aria-hidden="true"></span>';
        $html .= '<span class="owned-instance-picker-row-main">';
        $html .= '<span class="owned-instance-picker-row-title">' . htmlspecialchars($instance['label']) . '</span>';
        $html .= '<span class="owned-instance-picker-row-meta">' . htmlspecialchars($instance['meta']) . '</span>';
        $html .= '</span>';
        $html .= '</label>';
    }
    $html .= '</div>';
    $html .= '<button type="submit" id="owned-instance-picker-confirm">' . htmlspecialchars(t('owned_instance_picker_confirm_button')) . '</button>';
    $html .= '</div></div>';

    $html .= <<<SCRIPT
<script>
(function(){
  var openBtn = document.getElementById('owned-instance-picker-open');
  var modal = document.getElementById('owned-instance-picker-modal');
  var closeBtn = document.getElementById('owned-instance-picker-modal-close');
  var confirmBtn = document.getElementById('owned-instance-picker-confirm');
  if (!openBtn || !modal || !closeBtn || !confirmBtn) {
    return;
  }

  function openModal() {
    modal.style.display = 'flex';
  }
  function closeModal() {
    modal.style.display = 'none';
  }
  openBtn.addEventListener('click', function(e) {
    e.preventDefault();
    openModal();
  });
  closeBtn.addEventListener('click', closeModal);
  confirmBtn.addEventListener('click', function() {
    var checked = modal.querySelector('input[name="owned-instance-picker-choice"]:checked');
    if (checked) {
      window.location.href = checked.value;
    }
  });
})();
</script>
SCRIPT;

    return $html;
}

/**
 * One card per distinct minifig model for "Meine Minifiguren" (my_minifigs*)
 * — mirrors renderOwnedSetGroupCard() (src/owned_sets.php): a real link
 * straight to a detail page (?page=owned_minifig_detail), not the generic
 * click-delegated modal every other minifig card on the site opens,
 * representing every owned copy of this model at once
 * (groupLooseMinifigsByModel()) rather than one physical instance — the
 * location/condition meta line an ungrouped card would show doesn't
 * generalize across copies, so the corner badges (each copy's own
 * complete/damaged/missing status, getOwnedMinifigInstanceStatus()) take its
 * place instead. The link lands on the group's representative (oldest)
 * instance; that page's own dropdown (getOwnedMinifigInstancesForModel(),
 * src/owned_minifigs.php) is how the other copies are reached from there.
 */
function renderOwnedMinifigGroupCard(array $group): string
{
    $name = (string) ($group['name'] ?? $group['fig_num']);

    $html = '<a class="minifig-card owned-group-card" href="?page=owned_minifig_detail&id=' . (int) $group['representative_id'] . '">';
    $html .= renderOwnedStatusBadges($group['complete_count'], $group['damaged_count'], $group['missing_count']);
    $html .= '<span class="minifig-card-image">' . ($group['thumbnail'] !== null ? '<img src="' . htmlspecialchars($group['thumbnail']) . '" alt="">' : getNavIcon('minifigs')) . '</span>';
    $html .= '<span class="minifig-card-num">' . htmlspecialchars($group['fig_num']) . '</span>';
    $html .= '<span class="minifig-card-name" title="' . htmlspecialchars($name) . '">' . htmlspecialchars($name) . '</span>';
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
 * Same tree shape as getSetThemeTree() (src/sets.php) — full parent/child
 * hierarchy via buildThemeTree(), just counting distinct catalog minifigs
 * instead of sets. Minifigs have no category field of their own — the only
 * place Rebrickable groups them is via the sets they appear in, so "theme"
 * here is derived by walking
 * minifigs -> inventory_minifigs -> rebrickable_inventories -> sets ->
 * themes (see sets.php's getSetThemes() for why that last join, through
 * sets.theme, is needed for a display name). A minifig that appears in sets
 * from more than one theme is counted under each — expected, not a bug.
 * Powers the minifigs_search catalog browse; getOwnedMinifigThemeTree()
 * below is the ownership-scoped counterpart for "Meine Minifiguren".
 */
function getMinifigThemeTree(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT th.theme_id, th.name, th.parent_theme_id, COUNT(DISTINCT m.id) AS direct_count
         FROM themes th
         LEFT JOIN sets s ON s.theme = CAST(th.theme_id AS CHAR)
         LEFT JOIN rebrickable_inventories ri ON ri.set_num = s.rebrickable_set_num
         LEFT JOIN inventory_minifigs im ON im.inventory_id = ri.inventory_id
         LEFT JOIN minifigs m ON m.id = im.minifig_id
         GROUP BY th.theme_id, th.name, th.parent_theme_id'
    )->fetchAll();
    return buildThemeTree($rows);
}

/**
 * Same tree shape as getOwnedSetThemeTree() (src/sets.php) — full parent/
 * child hierarchy via buildThemeTree(), just counting loose minifig
 * instances (minifig_storage_items) instead of owned_sets rows. A minifig's
 * theme is always derived through the sets it appears in (see
 * getMinifigThemeTree()'s doc comment above), hence the extra two joins
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
 * descendant (a parent tile can have zero minifigs tagged with it directly
 * while its subthemes have plenty). Despite the shape matching an "owned"
 * query, this never actually filters by ownership — it searches all
 * catalog minifigs regardless of who owns what, so it powers both
 * "Meine Minifiguren" (my_minifigs_themes) and the minifigs_search catalog
 * browse's own theme tiles.
 *
 * @param array<int, int[]> $themeIdGroups keyed by the tile's own theme_id
 * @return array<string, string>
 */
function getMinifigThemeTileImages(PDO $pdo, array $themeIdGroups): array
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
 * Classifies one owned minifig instance's overall condition from its own
 * parts (getMinifigStorageItemPartsWithStatus()) — same missing > damaged >
 * complete priority as ownedSetInventoryTileStatusClass()
 * (src/owned_sets.php), just rolled up across all of the instance's parts
 * instead of one tile. Powers the "Meine Minifiguren" grouped-by-model
 * ampel counts and the detail page's instance picker.
 *
 * @return 'complete'|'damaged'|'missing'
 */
function getOwnedMinifigInstanceStatus(PDO $pdo, int $instanceId, string $figNum, string $locale = 'en'): string
{
    $hasMissing = false;
    $hasDamaged = false;
    foreach (getMinifigStorageItemPartsWithStatus($pdo, $instanceId, $figNum, $locale) as $part) {
        if ($part['nominal_quantity'] - $part['actual_quantity'] > 0) {
            $hasMissing = true;
        }
        if ($part['damaged_quantity'] > 0) {
            $hasDamaged = true;
        }
    }
    if ($hasMissing) {
        return 'missing';
    }
    if ($hasDamaged) {
        return 'damaged';
    }
    return 'complete';
}

/**
 * Groups a flat instance list (getAllLooseMinifigs()/
 * getLooseMinifigsForThemes()) by minifig type, one entry per distinct
 * model — "Meine Minifiguren" shows one card per model instead of one per
 * physical copy, with a complete/damaged/missing instance count
 * (getOwnedMinifigInstanceStatus()) replacing the per-copy location/
 * condition detail that no longer applies once several copies (possibly in
 * different locations/conditions) are collapsed into one card. The
 * representative instance (lowest id, i.e. first added — guaranteed by the
 * id-ascending order both source queries already sort by) is what the card
 * links to; getOwnedMinifigInstancesForModel() (src/owned_minifigs.php) is
 * how the detail page's own dropdown reaches the others afterwards.
 *
 * @param array<int, array{id:int, minifig_id:int, fig_num:string, name:?string, thumbnail:?string}> $instances
 * @return array<int, array{minifig_id:int, fig_num:string, name:?string, thumbnail:?string, representative_id:int, complete_count:int, damaged_count:int, missing_count:int}>
 */
function groupLooseMinifigsByModel(PDO $pdo, array $instances, string $locale = 'en'): array
{
    $groups = [];
    foreach ($instances as $instance) {
        $minifigId = $instance['minifig_id'];
        if (!isset($groups[$minifigId])) {
            $groups[$minifigId] = [
                'minifig_id' => $minifigId,
                'fig_num' => $instance['fig_num'],
                'name' => $instance['name'],
                'thumbnail' => $instance['thumbnail'],
                'representative_id' => $instance['id'],
                'complete_count' => 0,
                'damaged_count' => 0,
                'missing_count' => 0,
            ];
        }
        $status = getOwnedMinifigInstanceStatus($pdo, $instance['id'], $instance['fig_num'], $locale);
        $groups[$minifigId][$status . '_count']++;
    }
    return array_values($groups);
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
    // Minifigs have no year field of their own (see getMinifigThemeTree()'s
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
