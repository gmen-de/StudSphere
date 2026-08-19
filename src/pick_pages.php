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
            $name = $locationStmt->fetchColumn();

            $targetScreen = $status === 'closed' ? 'pick' : ($status === 'completed' ? 'pick' : 'pick');
            $html .= '<a class="pick-list-card" href="?screen=' . $targetScreen . '&id=' . (int) $list['id'] . '">';
            $html .= '<span class="pick-list-card-name">' . htmlspecialchars((string) $name) . '</span>';
            $html .= renderPickProgressBadge((int) $needed, (int) $picked);
            $html .= '</a>';
        }
        $html .= '</div>';
    }

    if (empty($lists)) {
        $html .= '<p class="pick-empty-hint">' . htmlspecialchars(t('pick_overview_empty')) . '</p>';
    }

    $html .= '</div>';
    return $html;
}

function renderPickListCreate(PDO $pdo, string $sourceType, string $query, ?int $ownedSetId): string
{
    $sourceType = $sourceType === 'minifig' ? 'minifig' : 'set';

    $html = '<div class="pick-screen">';
    $html .= '<a class="pick-back-link" href="?screen=list">&lsaquo; ' . htmlspecialchars(t('pick_back_to_overview')) . '</a>';
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
  var descInput = document.getElementById('pick-create-description');
  var errorEl = document.getElementById('pick-create-error');
  var submitBtn = document.getElementById('pick-create-submit');

  document.querySelectorAll('.pick-search-result').forEach(function(btn) {
    btn.addEventListener('click', function() {
      selectedCatalogId = parseInt(btn.dataset.catalogId, 10);
      selectedLabel.textContent = btn.dataset.label;
      confirmBox.style.display = 'block';
      confirmBox.scrollIntoView({ behavior: 'smooth' });
    });
  });

  submitBtn.addEventListener('click', function() {
    errorEl.textContent = '';
    if (!selectedCatalogId || !descInput.value.trim()) {
      errorEl.textContent = $errorRetryJson;
      return;
    }
    submitBtn.disabled = true;
    var formData = new FormData();
    formData.set('action', 'create_pick_list');
    formData.set('source_type', $sourceTypeJson);
    formData.set('catalog_id', String(selectedCatalogId));
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
    $html .= '<a class="pick-back-link" href="?screen=list">&lsaquo; ' . htmlspecialchars(t('pick_back_to_overview')) . '</a>';
    $locationStmt = $pdo->prepare('SELECT name FROM storage_locations WHERE id = ?');
    $locationStmt->execute([$pickList['location_id']]);
    $html .= '<h1>' . htmlspecialchars((string) $locationStmt->fetchColumn()) . '</h1>';
    $html .= renderPickProgressBadge((int) $needed, (int) $picked);

    if ($pickList['status'] === 'closed') {
        $html .= '<p class="pick-empty-hint">' . htmlspecialchars(t('pick_list_closed_hint')) . '</p>';
        $html .= '</div>';
        return $html;
    }

    $openItem = null;
    foreach ($items as $item) {
        if ((int) $item['picked_quantity'] < (int) $item['needed_quantity']) {
            $openItem = $item;
            break;
        }
    }

    if ($openItem === null) {
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

    $display = getPickItemDisplayInfo($pdo, $openItem);
    $steps = getPickStepsForItem($pdo, $openItem);

    $html .= '<div class="pick-item-card">';
    if (!empty($display['thumbnail'])) {
        $html .= '<img class="pick-item-thumb" src="' . htmlspecialchars(pickAssetUrl($display['thumbnail'])) . '" alt="">';
    }
    $html .= '<h2>' . htmlspecialchars($display['label']) . '</h2>';
    if ($display['color_name'] !== null) {
        $html .= '<p class="pick-item-color">' . htmlspecialchars($display['color_name']) . '</p>';
    }
    $html .= '<p>' . htmlspecialchars(t('pick_item_needed_label', ['needed' => (string) $openItem['needed_quantity'], 'picked' => (string) $openItem['picked_quantity']])) . '</p>';

    if ($display['part_id'] !== null) {
        $html .= '<button type="button" class="pick-btn" id="pick-4views-btn" data-part-id="' . $display['part_id'] . '" data-rebrickable-color-id="' . ($display['rebrickable_color_id'] ?? '') . '">' . htmlspecialchars(t('pick_item_4views_button')) . '</button>';
        $html .= '<div class="pick-4views-modal" id="pick-4views-modal" style="display:none;"><div class="pick-4views-grid" id="pick-4views-grid">' . htmlspecialchars(t('pick_item_4views_loading')) . '</div><button type="button" class="pick-btn" id="pick-4views-close">' . htmlspecialchars(t('close_button')) . '</button></div>';
    }

    if (!empty($steps['steps'])) {
        $step = $steps['steps'][0];
        $html .= '<div class="pick-step-box">';
        $html .= '<p class="pick-step-location">' . htmlspecialchars($step['location_path']) . '</p>';
        $html .= '<p>' . htmlspecialchars(t('pick_item_available_label', ['count' => (string) $step['available']])) . '</p>';
        if ($openItem['item_type'] === 'minifig') {
            $html .= '<button type="button" class="pick-btn pick-btn-primary pick-confirm-btn" data-pick-list-item-id="' . (int) $openItem['id'] . '">' . htmlspecialchars(t('pick_item_confirm_button')) . '</button>';
        } else {
            $html .= '<input type="number" id="pick-quantity-input" min="0" max="' . $step['suggested_pick'] . '" value="' . $step['suggested_pick'] . '">';
            $html .= '<p class="pick-quantity-hint">' . htmlspecialchars(t('pick_item_quantity_zero_hint')) . '</p>';
            $html .= '<button type="button" class="pick-btn pick-btn-primary pick-confirm-btn" data-pick-list-item-id="' . (int) $openItem['id'] . '" data-source-location-id="' . $step['location_id'] . '">' . htmlspecialchars(t('pick_item_confirm_button')) . '</button>';
        }
        $html .= '<button type="button" class="pick-btn pick-flag-btn" data-pick-list-item-id="' . (int) $openItem['id'] . '" data-location-id="' . $step['location_id'] . '" data-part-id="' . ($display['part_id'] ?? '') . '">' . htmlspecialchars(t('pick_item_flag_stocktake_button')) . '</button>';
        $html .= '</div>';
    } else {
        $html .= '<p class="pick-shortfall">' . htmlspecialchars(t('pick_item_shortfall', ['count' => (string) $steps['shortfall']])) . '</p>';
        if ($openItem['item_type'] === 'minifig') {
            $html .= '<p class="pick-empty-hint">' . htmlspecialchars(t('pick_item_minifig_unavailable_hint')) . '</p>';
        }
    }
    $html .= '</div>';

    $pickListIdJson = json_encode((int) $pickList['id']);
    $errorGenericJson = json_encode(t('pick_error_generic'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $html .= <<<SCRIPT
<script>
(function(){
  var pickListId = $pickListIdJson;

  var confirmBtn = document.querySelector('.pick-confirm-btn');
  if (confirmBtn) {
    confirmBtn.addEventListener('click', function() {
      confirmBtn.disabled = true;
      var formData = new FormData();
      formData.set('action', 'pick_item');
      formData.set('pick_list_id', String(pickListId));
      formData.set('pick_list_item_id', confirmBtn.dataset.pickListItemId);
      if (confirmBtn.dataset.sourceLocationId) {
        formData.set('source_location_id', confirmBtn.dataset.sourceLocationId);
        var qtyInput = document.getElementById('pick-quantity-input');
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
  }

  var flagBtn = document.querySelector('.pick-flag-btn');
  if (flagBtn) {
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
  }

  var viewsBtn = document.getElementById('pick-4views-btn');
  var viewsModal = document.getElementById('pick-4views-modal');
  var viewsGrid = document.getElementById('pick-4views-grid');
  var viewsClose = document.getElementById('pick-4views-close');
  if (viewsBtn) {
    var poll = null;
    viewsBtn.addEventListener('click', function() {
      viewsModal.style.display = 'block';
      viewsGrid.textContent = '…';
      function tick() {
        var params = new URLSearchParams();
        params.set('action', 'pick_ldraw_angle_progress');
        params.set('part_id', viewsBtn.dataset.partId);
        params.set('rebrickable_color_id', viewsBtn.dataset.rebrickableColorId || '0');
        fetch('?' + params.toString(), { credentials: 'same-origin' })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            if (!res.success) { return; }
            var html = '';
            Object.keys(res.images || {}).forEach(function(angle) {
              if (res.images[angle]) {
                html += '<img src="' + res.images[angle] + '" alt="' + angle + '">';
              }
            });
            viewsGrid.innerHTML = html || '…';
            if (res.status === 'running') {
              poll = setTimeout(tick, 1500);
            }
          });
      }
      tick();
    });
    viewsClose.addEventListener('click', function() {
      viewsModal.style.display = 'none';
      if (poll) { clearTimeout(poll); }
    });
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
    $html .= '<a class="pick-back-link" href="?screen=list">&lsaquo; ' . htmlspecialchars(t('pick_back_to_overview')) . '</a>';
    $html .= '<h1>' . htmlspecialchars(t('pick_putaway_heading')) . '</h1>';

    if (empty($suggestions)) {
        $html .= '<p class="pick-empty-hint">' . htmlspecialchars(t('pick_putaway_empty')) . '</p>';
        $html .= '</div>';
        return $html;
    }

    $html .= '<div class="pick-putaway-rows">';
    foreach ($suggestions as $i => $s) {
        $partStmt = $pdo->prepare('SELECT part_num, name FROM parts WHERE id = ?');
        $partStmt->execute([$s['part_id']]);
        $part = $partStmt->fetch();
        $label = $part !== false ? $part['part_num'] . ' ' . $part['name'] : ('#' . $s['part_id']);

        $html .= '<div class="pick-putaway-row" data-index="' . $i . '" data-part-id="' . $s['part_id'] . '" data-color-id="' . ($s['color_id'] ?? '') . '" data-condition-type="' . htmlspecialchars($s['condition_type']) . '" data-quantity="' . $s['quantity'] . '" data-suggested-location-id="' . ($s['suggested_location_id'] ?? '') . '">';
        $html .= '<span>' . htmlspecialchars($label) . ' &times; ' . $s['quantity'] . '</span>';
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
      formData.set('part_id', row.dataset.partId);
      formData.set('color_id', row.dataset.colorId);
      formData.set('condition_type', row.dataset.conditionType);
      formData.set('quantity', row.dataset.quantity);
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
