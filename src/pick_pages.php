<?php

declare(strict_types=1);

/**
 * The Pickliste PWA's (/pick/) screen renderers — mirrors the role
 * src/routes/pages.php plays for the main app's ?page= routes, just scoped
 * to /pick/'s own ?screen= routes and its own mobile-first markup (no shared
 * chrome with the desktop app — no sidebar, no desktop tables). Required by
 * /pick/index.php after $pdo/session/auth are already set up.
 *
 * Kept deliberately simple for this first pass: every action (pick, put
 * away, flag) is a small inline-script fetch() call to
 * src/routes/pick_actions.php followed by a full reload of the current
 * screen — no client-side DOM patching. That keeps every screen a plain,
 * reliable server render (correct after every action, including on a fresh
 * device mid-pick-list, which is the actual resume requirement) rather than
 * a state-synchronization problem; smoother in-place updates are a natural
 * later refinement once the basic flow is proven out.
 */

require_once __DIR__ . '/pick_lists.php';
require_once __DIR__ . '/parts.php';
require_once __DIR__ . '/part_images.php';
require_once __DIR__ . '/sets.php';
require_once __DIR__ . '/minifigs.php';

/**
 * Every image path this app stores (local_image_path columns, thumbnails,
 * LDraw renders, ...) is webroot-relative ("public/images/..."), correct as
 * a plain <img src> from any page served at the webroot — but /pick/'s own
 * pages are one directory down from there, so the exact same relative path
 * resolves to the wrong place (/pick/public/images/...) unless prefixed.
 * Root-absolute (a leading "/") isn't used instead because this app makes
 * no assumption about being deployed at a domain root (see index.php's own
 * doc comments on manifest.json/sw.js paths) — "../" stays correct at any
 * install depth, matching how the rest of the app only ever uses relative
 * paths too.
 */
function pickAssetUrl(?string $path): ?string
{
    return $path !== null && $path !== '' ? '../' . $path : null;
}

/**
 * Display info (name/color/thumbnail) for one pick_list_items row — resolves
 * the surrogate colors.id it's keyed on to Rebrickable's own color_id only
 * where getCachedPartColorImage() actually needs that numbering, same
 * pattern getPartDetail() already uses (src/parts.php).
 */
function getPickItemDisplayInfo(PDO $pdo, array $item): array
{
    if ($item['item_type'] === 'minifig') {
        $stmt = $pdo->prepare('SELECT fig_num, name, local_image_path AS thumbnail FROM minifigs WHERE id = ?');
        $stmt->execute([$item['minifig_id']]);
        $fig = $stmt->fetch();
        return [
            'label' => $fig !== false ? $fig['fig_num'] . ' ' . $fig['name'] : '?',
            'thumbnail' => $fig !== false ? $fig['thumbnail'] : null,
            'color_name' => null,
            'part_id' => null,
            'rebrickable_color_id' => null,
        ];
    }

    $stmt = $pdo->prepare('SELECT part_num, name FROM parts WHERE id = ?');
    $stmt->execute([$item['part_id']]);
    $part = $stmt->fetch();
    if ($part !== false && getLocale() !== 'en') {
        $translated = getPartTranslation($pdo, (int) $item['part_id'], getLocale());
        if ($translated !== null) {
            $part['name'] = $translated;
        }
    }
    $thumbnails = getPartThumbnails($pdo, [(int) $item['part_id']]);
    $thumbnail = $thumbnails[(int) $item['part_id']] ?? null;

    $colorStmt = $pdo->prepare('SELECT color_id, name FROM colors WHERE id = ?');
    $colorStmt->execute([$item['color_id']]);
    $color = $colorStmt->fetch();
    $rebrickableColorId = $color !== false ? (int) $color['color_id'] : null;
    if ($rebrickableColorId !== null) {
        $thumbnail = getCachedPartColorImage($pdo, (int) $item['part_id'], $rebrickableColorId) ?? $thumbnail;
    }

    return [
        'label' => $part !== false ? $part['part_num'] . ' ' . $part['name'] : '?',
        'thumbnail' => $thumbnail,
        'color_name' => $color !== false ? $color['name'] : null,
        'part_id' => (int) $item['part_id'],
        'rebrickable_color_id' => $rebrickableColorId,
    ];
}

function renderPickProgressBadge(int $needed, int $picked): string
{
    $percent = $needed > 0 ? (int) round(min(1.0, $picked / $needed) * 100) : 100;
    return '<span class="pick-progress-badge">' . $picked . ' / ' . $needed . ' (' . $percent . '%)</span>';
}

function renderPickListOverview(PDO $pdo, int $userId): string
{
    $lists = getPickListsForUser($pdo, $userId);
    $groups = ['active' => [], 'completed' => [], 'closed' => []];
    foreach ($lists as $list) {
        $groups[$list['status']][] = $list;
    }

    $html = '<div class="pick-screen">';
    $html .= '<h1>' . htmlspecialchars(t('pick_overview_heading')) . '</h1>';
    $html .= '<a class="pick-btn pick-btn-primary pick-create-fab" href="?screen=create">' . htmlspecialchars(t('pick_overview_create_button')) . '</a>';

    foreach (['active' => 'pick_overview_group_active', 'completed' => 'pick_overview_group_completed', 'closed' => 'pick_overview_group_closed'] as $status => $labelKey) {
        if (empty($groups[$status])) {
            continue;
        }
        $html .= '<h2 class="pick-group-heading">' . htmlspecialchars(t($labelKey)) . '</h2>';
        $html .= '<div class="pick-list-cards">';
        foreach ($groups[$status] as $list) {
            $items = getPickListItems($pdo, (int) $list['id']);
            $needed = array_sum(array_column($items, 'needed_quantity'));
            $picked = array_sum(array_column($items, 'picked_quantity'));
            $locationStmt = $pdo->prepare('SELECT name FROM storage_locations WHERE id = ?');
            $locationStmt->execute([$list['location_id']]);
            $containerName = (string) $locationStmt->fetchColumn();
            // Legacy rows created before pick_lists.name existed have '' —
            // fall back to the container name so nothing renders blank.
            $displayName = $list['name'] !== '' ? $list['name'] : $containerName;

            $html .= '<div class="pick-list-card-wrap" data-pick-list-id="' . (int) $list['id'] . '" data-pick-list-name="' . htmlspecialchars($displayName) . '">';
            $html .= '<div class="pick-list-card-delete-bg"><button type="button" class="pick-list-card-delete-btn">' . htmlspecialchars(t('pick_overview_delete_button')) . '</button></div>';
            $html .= '<a class="pick-list-card" href="?screen=pick&id=' . (int) $list['id'] . '">';
            $html .= '<span class="pick-list-card-text">';
            $html .= '<span class="pick-list-card-name">' . htmlspecialchars($displayName) . '</span>';
            if ($containerName !== '' && $containerName !== $displayName) {
                $html .= '<span class="pick-list-card-container">' . htmlspecialchars($containerName) . '</span>';
            }
            $html .= '</span>';
            $html .= renderPickProgressBadge((int) $needed, (int) $picked);
            $html .= '</a>';
            $html .= '</div>';
        }
        $html .= '</div>';
    }

    if (empty($lists)) {
        $html .= '<p class="pick-empty-hint">' . htmlspecialchars(t('pick_overview_empty')) . '</p>';
    }

    $html .= '</div>';
    $html .= renderPickListOverviewSwipeScript();
    return $html;
}

/**
 * Swipe-left-to-reveal-delete for the overview's pick-list cards (right to
 * left, matching the iOS Mail/native-app convention the user asked for) —
 * plain touch-event tracking, not a library: each card's foreground layer
 * (.pick-list-card, the actual link) slides via a CSS transform while a
 * fixed-width delete button sits underneath (.pick-list-card-delete-bg),
 * revealed as the foreground slides away. A horizontal swipe is
 * distinguished from a vertical scroll by comparing |dx| vs |dy| on the
 * first few pixels of movement — only once a gesture is confirmed
 * horizontal does it preventDefault() the touchmove (so an accidental
 * horizontal wobble during normal vertical scrolling never hijacks the
 * page). Deletion itself goes through action=delete_pick_list
 * (src/routes/pick_actions.php), which refuses (with a translated message)
 * if anything in the list has already been picked.
 */
function renderPickListOverviewSwipeScript(): string
{
    $confirmTemplateJson = json_encode(t('pick_overview_delete_confirm'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $errorGenericJson = json_encode(t('pick_error_generic'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return <<<SCRIPT
<script>
(function(){
  var REVEAL_WIDTH = 84;
  document.querySelectorAll('.pick-list-card-wrap').forEach(function(wrap) {
    var card = wrap.querySelector('.pick-list-card');
    var deleteBtn = wrap.querySelector('.pick-list-card-delete-btn');
    var startX = 0, startY = 0, currentX = 0, dragging = false, horizontal = null, open = false;

    function setOffset(px, animate) {
      card.style.transition = animate ? 'transform 0.2s ease' : 'none';
      card.style.transform = 'translateX(' + px + 'px)';
    }

    card.addEventListener('touchstart', function(e) {
      startX = e.touches[0].clientX;
      startY = e.touches[0].clientY;
      currentX = open ? -REVEAL_WIDTH : 0;
      dragging = true;
      horizontal = null;
    }, { passive: true });

    card.addEventListener('touchmove', function(e) {
      if (!dragging) { return; }
      var dx = e.touches[0].clientX - startX;
      var dy = e.touches[0].clientY - startY;
      if (horizontal === null && (Math.abs(dx) > 8 || Math.abs(dy) > 8)) {
        horizontal = Math.abs(dx) > Math.abs(dy);
      }
      if (horizontal === false) { return; }
      if (horizontal === true) { e.preventDefault(); }
      var next = Math.max(-REVEAL_WIDTH, Math.min(0, currentX + dx));
      setOffset(next, false);
    }, { passive: false });

    card.addEventListener('touchend', function(e) {
      if (!dragging) { return; }
      dragging = false;
      if (horizontal !== true) { return; }
      var dx = (e.changedTouches[0].clientX - startX);
      var next = currentX + dx;
      open = next < -REVEAL_WIDTH / 2;
      setOffset(open ? -REVEAL_WIDTH : 0, true);
    });

    card.addEventListener('click', function(e) {
      if (open) {
        e.preventDefault();
        open = false;
        setOffset(0, true);
      }
    });

    deleteBtn.addEventListener('click', function() {
      var name = wrap.dataset.pickListName;
      var confirmText = $confirmTemplateJson.replace('{name}', name);
      if (!window.confirm(confirmText)) { return; }
      var formData = new FormData();
      formData.set('action', 'delete_pick_list');
      formData.set('pick_list_id', wrap.dataset.pickListId);
      fetch('?action=delete_pick_list', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (!res.success) {
            alert(res.message || $errorGenericJson);
            open = false;
            setOffset(0, true);
            return;
          }
          wrap.remove();
        })
        .catch(function() {
          alert($errorGenericJson);
        });
    });
  });
})();
</script>
SCRIPT;
}

function renderPickListCreate(PDO $pdo, string $sourceType, string $query, ?int $ownedSetId): string
{
    $sourceType = $sourceType === 'minifig' ? 'minifig' : 'set';

    $html = '<div class="pick-screen">';
    $html .= '<h1>' . htmlspecialchars(t('pick_create_heading')) . '</h1>';

    $html .= '<div class="pick-tabs">';
    $html .= '<a class="pick-tab' . ($sourceType === 'set' ? ' active' : '') . '" href="?screen=create&source_type=set">' . htmlspecialchars(t('pick_create_tab_set')) . '</a>';
    $html .= '<a class="pick-tab' . ($sourceType === 'minifig' ? ' active' : '') . '" href="?screen=create&source_type=minifig">' . htmlspecialchars(t('pick_create_tab_minifig')) . '</a>';
    $html .= '</div>';

    $html .= '<form method="get" class="pick-search-form">';
    $html .= '<input type="hidden" name="screen" value="create">';
    $html .= '<input type="hidden" name="source_type" value="' . htmlspecialchars($sourceType) . '">';
    $html .= '<input type="search" name="q" value="' . htmlspecialchars($query) . '" placeholder="' . htmlspecialchars(t('pick_create_search_placeholder')) . '">';
    $html .= '<button type="submit" class="pick-btn">' . htmlspecialchars(t('pick_create_search_button')) . '</button>';
    $html .= '</form>';

    if ($query !== '') {
        $results = $sourceType === 'set'
            ? searchSets($pdo, $query, [], 1, 20)['items']
            : searchMinifigs($pdo, $query, [], 1, 20)['items'];

        $html .= '<div class="pick-search-results">';
        foreach ($results as $result) {
            $catalogId = (int) $result['id'];
            $label = $sourceType === 'set'
                ? htmlspecialchars($result['rebrickable_set_num'] . ' — ' . $result['name'])
                : htmlspecialchars($result['fig_num'] . ' — ' . $result['name']);
            $html .= '<button type="button" class="pick-search-result" data-catalog-id="' . $catalogId . '" data-label="' . $label . '">';
            if (!empty($result['thumbnail'])) {
                $html .= '<img src="' . htmlspecialchars(pickAssetUrl($result['thumbnail'])) . '" alt="">';
            }
            $html .= '<span>' . $label . '</span>';
            $html .= '</button>';
        }
        if (empty($results)) {
            $html .= '<p class="pick-empty-hint">' . htmlspecialchars(t('pick_create_no_results')) . '</p>';
        }
        $html .= '</div>';
    }

    $html .= '<div class="pick-create-confirm" id="pick-create-confirm" style="display:none;">';
    $html .= '<p id="pick-create-selected-label"></p>';
    $html .= '<label>' . htmlspecialchars(t('pick_create_name_label'));
    $html .= '<input type="text" id="pick-create-name"></label>';
    $html .= '<label>' . htmlspecialchars(t('pick_create_description_label'));
    $html .= '<input type="text" id="pick-create-description" placeholder="' . htmlspecialchars(t('pick_create_description_placeholder')) . '"></label>';
    $html .= '<p class="pick-error" id="pick-create-error"></p>';
    $html .= '<button type="button" class="pick-btn pick-btn-primary" id="pick-create-submit">' . htmlspecialchars(t('pick_create_submit_button')) . '</button>';
    $html .= '</div>';

    $errorRetryJson = json_encode(t('pick_error_generic'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $sourceTypeJson = json_encode($sourceType);
    $ownedSetIdJson = json_encode($ownedSetId);

    $html .= <<<SCRIPT
<script>
(function(){
  var selectedCatalogId = null;
  var confirmBox = document.getElementById('pick-create-confirm');
  var selectedLabel = document.getElementById('pick-create-selected-label');
  var nameInput = document.getElementById('pick-create-name');
  var descInput = document.getElementById('pick-create-description');
  var errorEl = document.getElementById('pick-create-error');
  var submitBtn = document.getElementById('pick-create-submit');

  document.querySelectorAll('.pick-search-result').forEach(function(btn) {
    btn.addEventListener('click', function() {
      selectedCatalogId = parseInt(btn.dataset.catalogId, 10);
      selectedLabel.textContent = btn.dataset.label;
      nameInput.value = btn.dataset.label;
      confirmBox.style.display = 'block';
      confirmBox.scrollIntoView({ behavior: 'smooth' });
    });
  });

  submitBtn.addEventListener('click', function() {
    errorEl.textContent = '';
    if (!selectedCatalogId || !nameInput.value.trim() || !descInput.value.trim()) {
      errorEl.textContent = $errorRetryJson;
      return;
    }
    submitBtn.disabled = true;
    var formData = new FormData();
    formData.set('action', 'create_pick_list');
    formData.set('source_type', $sourceTypeJson);
    formData.set('catalog_id', String(selectedCatalogId));
    formData.set('name', nameInput.value.trim());
    formData.set('description', descInput.value.trim());
    var ownedSetId = $ownedSetIdJson;
    if (ownedSetId) { formData.set('owned_set_id', String(ownedSetId)); }
    fetch('?action=create_pick_list', { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (!res.success) {
          submitBtn.disabled = false;
          errorEl.textContent = res.message || $errorRetryJson;
          return;
        }
        window.location.href = '?screen=pick&id=' + res.pickListId;
      })
      .catch(function() {
        submitBtn.disabled = false;
        errorEl.textContent = $errorRetryJson;
      });
  });
})();
</script>
SCRIPT;

    $html .= '</div>';
    return $html;
}

function renderPickListActive(PDO $pdo, array $pickList, int $userId): string
{
    $items = getPickListItems($pdo, (int) $pickList['id']);
    $needed = array_sum(array_column($items, 'needed_quantity'));
    $picked = array_sum(array_column($items, 'picked_quantity'));

    $html = '<div class="pick-screen">';
    $locationStmt = $pdo->prepare('SELECT name FROM storage_locations WHERE id = ?');
    $locationStmt->execute([$pickList['location_id']]);
    $containerName = (string) $locationStmt->fetchColumn();
    $displayName = $pickList['name'] !== '' ? $pickList['name'] : $containerName;
    $html .= '<h1>' . htmlspecialchars($displayName) . '</h1>';
    if ($containerName !== '' && $containerName !== $displayName) {
        $html .= '<p class="pick-container-hint">' . htmlspecialchars(t('pick_active_container_hint', ['container' => $containerName])) . '</p>';
    }
    $html .= renderPickProgressBadge((int) $needed, (int) $picked);

    if ($pickList['status'] === 'closed') {
        $html .= '<p class="pick-empty-hint">' . htmlspecialchars(t('pick_list_closed_hint')) . '</p>';
        $html .= '</div>';
        return $html;
    }

    // Skips items with a genuine shortfall (getPickStepsForItem() found no
    // location at all) rather than stopping there — entering quantity 0 for
    // a shortfall item (§ pickItem()) doesn't change picked_quantity, so
    // without this skip the SAME item would keep coming back forever and
    // the user could never reach any of the list's other, still-pickable
    // items. $openItems (still-needed rows) is walked in full so
    // $shortfallCount reflects every item currently stuck, not just the
    // ones scanned before the first pickable one was found.
    $openItems = array_values(array_filter($items, fn (array $item): bool => (int) $item['picked_quantity'] < (int) $item['needed_quantity']));
    $pickableItems = []; // [['item' => ..., 'steps' => ...], ...]
    $shortfallCount = 0;
    foreach ($openItems as $item) {
        $itemSteps = getPickStepsForItem($pdo, $item);
        if (!empty($itemSteps['steps'])) {
            $pickableItems[] = ['item' => $item, 'steps' => $itemSteps];
        } else {
            $shortfallCount++;
        }
    }

    if (empty($openItems)) {
        $html .= '<div class="pick-complete-box">';
        $html .= '<p>' . htmlspecialchars(t('pick_complete_heading')) . '</p>';
        $html .= '<a class="pick-btn pick-btn-primary" href="?screen=putaway&id=' . (int) $pickList['id'] . '">' . htmlspecialchars(t('pick_complete_putaway_button')) . '</a>';
        if ($pickList['source_type'] === 'set' && $pickList['set_id'] !== null) {
            $html .= '<a class="pick-btn" href="' . htmlspecialchars('../?page=set_detail&id=' . (int) $pickList['set_id']) . '">' . htmlspecialchars(t('pick_complete_use_for_set_button')) . '</a>';
        }
        $html .= '<a class="pick-btn" href="?screen=list">' . htmlspecialchars(t('pick_complete_leave_button')) . '</a>';
        $html .= '</div>';
        $html .= '</div>';
        return $html;
    }

    if (empty($pickableItems)) {
        // Every still-needed item is a genuine shortfall — nothing left to
        // physically pick right now, but (unlike full completion above)
        // some items are still short rather than done, so this gets its own
        // wording instead of reusing pick_complete_heading.
        $html .= '<div class="pick-complete-box">';
        $html .= '<p class="pick-shortfall">' . htmlspecialchars(t('pick_all_shortfall_heading', ['count' => (string) $shortfallCount])) . '</p>';
        $html .= '<a class="pick-btn pick-btn-primary" href="?screen=putaway&id=' . (int) $pickList['id'] . '">' . htmlspecialchars(t('pick_complete_putaway_button')) . '</a>';
        $html .= '<a class="pick-btn" href="?screen=list">' . htmlspecialchars(t('pick_complete_leave_button')) . '</a>';
        $html .= '</div>';
        $html .= '</div>';
        return $html;
    }

    // Sorted by each item's own first pick location — so items whose
    // location happens to match sit next to each other in the swipe deck
    // below, letting a user work through one shelf at a time instead of
    // bouncing between locations on every swipe. Only a same-location
    // grouping, not a real shortest-path route (this app has no notion of
    // physical distance between locations to route against), but the common
    // case — several needed parts sharing a bin — already gets meaningfully
    // better with just this.
    usort($pickableItems, function (array $a, array $b): int {
        $pathCompare = strcmp($a['steps']['steps'][0]['location_path'], $b['steps']['steps'][0]['location_path']);
        return $pathCompare !== 0 ? $pathCompare : ((int) $a['item']['id'] <=> (int) $b['item']['id']);
    });

    $html .= '<div class="pick-active-deck" id="pick-active-deck">';
    foreach ($pickableItems as $entry) {
        $item = $entry['item'];
        $steps = $entry['steps'];
        $display = getPickItemDisplayInfo($pdo, $item);

        // Whatever LDraw angles are already cached for this part+color, in a
        // fixed order — rendering was queued in full back when this pick
        // list was created (enqueueLdrawAnglesForPickListItems(),
        // src/pick_lists.php), not on demand while picking, so by the time
        // the user actually reaches this item most/all of them are
        // typically already done; no polling needed here, just show
        // whatever's ready right now. Falls back to the one generic
        // thumbnail if LDraw rendering isn't enabled or nothing has
        // rendered yet at all.
        $galleryImages = [];
        if ($display['part_id'] !== null && $display['rebrickable_color_id'] !== null) {
            $angleImages = getLdrawFourAngleImages($pdo, $display['part_id'], $display['rebrickable_color_id']);
            foreach (LDRAW_PICK_DETAIL_ANGLES as $angle) {
                if (!empty($angleImages[$angle])) {
                    $galleryImages[] = $angleImages[$angle];
                }
            }
        }
        if (empty($galleryImages) && !empty($display['thumbnail'])) {
            $galleryImages[] = $display['thumbnail'];
        }

        $html .= '<div class="pick-item-card">';
        if (!empty($galleryImages)) {
            $html .= '<div class="pick-item-gallery">';
            foreach ($galleryImages as $galleryImage) {
                $html .= '<img src="' . htmlspecialchars(pickAssetUrl($galleryImage)) . '" alt="">';
            }
            $html .= '</div>';
        }
        $html .= '<h2>' . htmlspecialchars($display['label']) . '</h2>';
        if ($display['color_name'] !== null) {
            $html .= '<p class="pick-item-color">' . htmlspecialchars($display['color_name']) . '</p>';
        }
        $html .= '<p>' . htmlspecialchars(t('pick_item_needed_label', ['needed' => (string) $item['needed_quantity'], 'picked' => (string) $item['picked_quantity']])) . '</p>';

        $step = $steps['steps'][0];
        $html .= '<div class="pick-step-box">';
        $html .= '<p class="pick-step-location">' . htmlspecialchars($step['location_path']) . '</p>';
        $html .= '<p>' . htmlspecialchars(t('pick_item_available_label', ['count' => (string) $step['available']])) . '</p>';
        if ($item['item_type'] === 'minifig') {
            $html .= '<button type="button" class="pick-btn pick-btn-primary pick-confirm-btn" data-pick-list-item-id="' . (int) $item['id'] . '">' . htmlspecialchars(t('pick_item_confirm_button')) . '</button>';
        } else {
            $html .= '<input type="number" class="pick-quantity-input" min="0" max="' . $step['suggested_pick'] . '" value="' . $step['suggested_pick'] . '">';
            $html .= '<p class="pick-quantity-hint">' . htmlspecialchars(t('pick_item_quantity_zero_hint')) . '</p>';
            $html .= '<button type="button" class="pick-btn pick-btn-primary pick-confirm-btn" data-pick-list-item-id="' . (int) $item['id'] . '" data-source-location-id="' . $step['location_id'] . '">' . htmlspecialchars(t('pick_item_confirm_button')) . '</button>';
        }
        $html .= '<button type="button" class="pick-btn pick-flag-btn" data-pick-list-item-id="' . (int) $item['id'] . '" data-location-id="' . $step['location_id'] . '" data-part-id="' . ($display['part_id'] ?? '') . '">' . htmlspecialchars(t('pick_item_flag_stocktake_button')) . '</button>';
        $html .= '</div>';
        $html .= '</div>';
    }
    $html .= '</div>';

    if (count($pickableItems) > 1) {
        $html .= '<div class="pick-active-dots" id="pick-active-dots">';
        for ($i = 0, $n = count($pickableItems); $i < $n; $i++) {
            $html .= '<span class="pick-active-dot' . ($i === 0 ? ' active' : '') . '"></span>';
        }
        $html .= '</div>';
    }
    if ($shortfallCount > 0) {
        $html .= '<p class="pick-empty-hint pick-active-shortfall-hint">' . htmlspecialchars(t('pick_active_shortfall_hint', ['count' => (string) $shortfallCount])) . '</p>';
    }

    $pickListIdJson = json_encode((int) $pickList['id']);
    $errorGenericJson = json_encode(t('pick_error_generic'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $html .= <<<SCRIPT
<script>
(function(){
  var pickListId = $pickListIdJson;

  document.querySelectorAll('.pick-confirm-btn').forEach(function(confirmBtn) {
    confirmBtn.addEventListener('click', function() {
      confirmBtn.disabled = true;
      var formData = new FormData();
      formData.set('action', 'pick_item');
      formData.set('pick_list_id', String(pickListId));
      formData.set('pick_list_item_id', confirmBtn.dataset.pickListItemId);
      if (confirmBtn.dataset.sourceLocationId) {
        formData.set('source_location_id', confirmBtn.dataset.sourceLocationId);
        var qtyInput = confirmBtn.closest('.pick-item-card').querySelector('.pick-quantity-input');
        formData.set('quantity', qtyInput ? qtyInput.value : '1');
      } else {
        formData.set('quantity', '1');
      }
      fetch('?action=pick_item', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (!res.success) {
            alert(res.message || $errorGenericJson);
            confirmBtn.disabled = false;
            return;
          }
          window.location.reload();
        })
        .catch(function() {
          alert($errorGenericJson);
          confirmBtn.disabled = false;
        });
    });
  });

  document.querySelectorAll('.pick-flag-btn').forEach(function(flagBtn) {
    flagBtn.addEventListener('click', function() {
      var note = window.prompt('');
      if (note === null) { return; }
      var formData = new FormData();
      formData.set('action', 'flag_stocktake');
      formData.set('pick_list_id', String(pickListId));
      formData.set('pick_list_item_id', flagBtn.dataset.pickListItemId);
      formData.set('location_id', flagBtn.dataset.locationId);
      if (flagBtn.dataset.partId) { formData.set('part_id', flagBtn.dataset.partId); }
      formData.set('note', note);
      fetch('?action=flag_stocktake', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (!res.success) { alert(res.message || $errorGenericJson); }
        });
    });
  });

  // Swipe left/right through the deck (left = older browsers' native touch
  // scrolling already gives this for free via scroll-snap; this listener
  // just keeps the page-dot indicator in sync with whichever card is
  // currently centered, same idea as iOS's own page control).
  var deck = document.getElementById('pick-active-deck');
  var dots = document.getElementById('pick-active-dots');
  if (deck && dots) {
    var dotEls = dots.querySelectorAll('.pick-active-dot');
    var updateDots = function() {
      var index = Math.round(deck.scrollLeft / deck.clientWidth);
      dotEls.forEach(function(dot, i) { dot.classList.toggle('active', i === index); });
    };
    deck.addEventListener('scroll', updateDots, { passive: true });
  }
})();
</script>
SCRIPT;

    $html .= '</div>';
    return $html;
}

function renderPickListPutAway(PDO $pdo, array $pickList): string
{
    $suggestions = getPutAwaySuggestions($pdo, (int) $pickList['id']);

    $html = '<div class="pick-screen">';
    $html .= '<h1>' . htmlspecialchars(t('pick_putaway_heading')) . '</h1>';

    if (empty($suggestions)) {
        $html .= '<p class="pick-empty-hint">' . htmlspecialchars(t('pick_putaway_empty')) . '</p>';
        $html .= '</div>';
        return $html;
    }

    $html .= '<div class="pick-putaway-rows">';
    foreach ($suggestions as $i => $s) {
        if ($s['item_type'] === 'minifig') {
            $figStmt = $pdo->prepare('SELECT fig_num, name FROM minifigs WHERE id = ?');
            $figStmt->execute([$s['minifig_id']]);
            $fig = $figStmt->fetch();
            $label = $fig !== false ? $fig['fig_num'] . ' ' . $fig['name'] : ('#' . $s['minifig_id']);
        } else {
            $partStmt = $pdo->prepare('SELECT part_num, name FROM parts WHERE id = ?');
            $partStmt->execute([$s['part_id']]);
            $part = $partStmt->fetch();
            $label = $part !== false ? $part['part_num'] . ' ' . $part['name'] : ('#' . $s['part_id']);
        }
        $quantityLabel = $s['item_type'] === 'minifig' ? '' : (' &times; ' . $s['quantity']);

        $html .= '<div class="pick-putaway-row" data-index="' . $i . '" data-item-type="' . htmlspecialchars($s['item_type']) . '"'
            . ' data-part-id="' . $s['part_id'] . '" data-color-id="' . ($s['color_id'] ?? '') . '" data-condition-type="' . htmlspecialchars($s['condition_type']) . '" data-quantity="' . $s['quantity'] . '"'
            . ' data-minifig-storage-item-id="' . ($s['minifig_storage_item_id'] ?? '') . '"'
            . ' data-suggested-location-id="' . ($s['suggested_location_id'] ?? '') . '">';
        $html .= '<span>' . htmlspecialchars($label) . $quantityLabel . '</span>';
        if ($s['suggested_location_id'] !== null) {
            $html .= '<span class="pick-putaway-suggestion">' . htmlspecialchars($s['suggested_location_path']) . '</span>';
            $html .= '<button type="button" class="pick-btn pick-putaway-confirm-btn">' . htmlspecialchars(t('pick_putaway_confirm_button')) . '</button>';
        } else {
            $html .= '<input type="number" class="pick-putaway-manual-location" placeholder="' . htmlspecialchars(t('pick_putaway_manual_location_placeholder')) . '">';
            $html .= '<button type="button" class="pick-btn pick-putaway-confirm-btn">' . htmlspecialchars(t('pick_putaway_confirm_button')) . '</button>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';

    $pickListIdJson = json_encode((int) $pickList['id']);
    $errorGenericJson = json_encode(t('pick_error_generic'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $html .= <<<SCRIPT
<script>
(function(){
  var pickListId = $pickListIdJson;
  document.querySelectorAll('.pick-putaway-confirm-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var row = btn.closest('.pick-putaway-row');
      var manualInput = row.querySelector('.pick-putaway-manual-location');
      var destinationId = row.dataset.suggestedLocationId || (manualInput ? manualInput.value : '');
      if (!destinationId) {
        alert($errorGenericJson);
        return;
      }
      btn.disabled = true;
      var formData = new FormData();
      formData.set('action', 'put_away_item');
      formData.set('pick_list_id', String(pickListId));
      formData.set('item_type', row.dataset.itemType);
      if (row.dataset.itemType === 'minifig') {
        formData.set('minifig_storage_item_id', row.dataset.minifigStorageItemId);
      } else {
        formData.set('part_id', row.dataset.partId);
        formData.set('color_id', row.dataset.colorId);
        formData.set('condition_type', row.dataset.conditionType);
        formData.set('quantity', row.dataset.quantity);
      }
      formData.set('destination_location_id', destinationId);
      fetch('?action=put_away_item', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (!res.success) {
            alert(res.message || $errorGenericJson);
            btn.disabled = false;
            return;
          }
          window.location.reload();
        })
        .catch(function() {
          alert($errorGenericJson);
          btn.disabled = false;
        });
    });
  });
})();
</script>
SCRIPT;

    $html .= '</div>';
    return $html;
}
