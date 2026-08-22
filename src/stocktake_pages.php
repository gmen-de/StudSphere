<?php

declare(strict_types=1);

/**
 * Inventur screens for the Pickliste PWA (/pick/) — second front-end for the
 * same backend the desktop modal (renderStocktakeModal(), src/stocktakes.php)
 * already uses and that was already live-verified against real data. Mirrors
 * src/pick_pages.php's own role/conventions (mobile-first, no shared chrome
 * with the desktop app, every action is a fetch() + window.location.reload(),
 * no client-side DOM patching) but deliberately kept in its own file/routing
 * (src/routes/pick_stocktake_actions.php) rather than folded into the
 * Pickliste screens — same separation the backend already made between
 * stocktakes.php and pick_lists.php: an Inventur never physically relocates
 * stock the way a pick list does, it only corrects the quantity already
 * sitting at the same location.
 *
 * Unlike the desktop modal's strictly linear one-item-at-a-time walkthrough,
 * the active screen here shows every position at once in the same swipe deck
 * the Pickliste feature already uses (.pick-active-deck/.pick-item-card) —
 * already-confirmed cards stay in place with a "gezählt" marker instead of
 * disappearing, so a user can freely swipe back and forth while walking a
 * shelf rather than being forced through a fixed order.
 */

require_once __DIR__ . '/stocktakes.php';
require_once __DIR__ . '/pick_pages.php';

function renderStocktakeOverview(PDO $pdo, int $userId): string
{
    $stocktakes = getStocktakesForUser($pdo, $userId);
    $groups = ['active' => [], 'completed' => []];
    foreach ($stocktakes as $stocktake) {
        $groups[$stocktake['status']][] = $stocktake;
    }

    $html = '<div class="pick-screen">';
    $html .= '<h1>' . htmlspecialchars(t('stocktake_overview_heading')) . '</h1>';
    $html .= '<a class="pick-btn pick-btn-primary pick-create-fab" href="?screen=stocktake_create">' . htmlspecialchars(t('stocktake_start_button')) . '</a>';

    foreach (['active' => 'stocktake_overview_group_active', 'completed' => 'stocktake_overview_group_completed'] as $status => $labelKey) {
        if (empty($groups[$status])) {
            continue;
        }
        $html .= '<h2 class="pick-group-heading">' . htmlspecialchars(t($labelKey)) . '</h2>';
        $html .= '<div class="pick-list-cards">';
        foreach ($groups[$status] as $stocktake) {
            $progress = getStocktakeProgress($pdo, (int) $stocktake['id']);
            $label = getStocktakeLabel($pdo, $stocktake);
            $html .= '<a class="pick-list-card" href="?screen=stocktake&id=' . (int) $stocktake['id'] . '">';
            $html .= '<span class="pick-list-card-text"><span class="pick-list-card-name">' . htmlspecialchars($label) . '</span></span>';
            $html .= renderPickProgressBadge((int) $progress['total'], (int) $progress['confirmed']);
            $html .= '</a>';
        }
        $html .= '</div>';
    }

    if (empty($stocktakes)) {
        $html .= '<p class="pick-empty-hint">' . htmlspecialchars(t('stocktake_overview_empty')) . '</p>';
    }

    $html .= '</div>';
    return $html;
}

function renderStocktakeCreate(PDO $pdo, string $tab): string
{
    $tab = $tab === 'sets' ? 'sets' : 'locations';

    $html = '<div class="pick-screen">';
    $html .= '<h1>' . htmlspecialchars(t('stocktake_start_button')) . '</h1>';

    $html .= '<div class="pick-tabs">';
    $html .= '<a class="pick-tab' . ($tab === 'locations' ? ' active' : '') . '" href="?screen=stocktake_create&tab=locations">' . htmlspecialchars(t('stocktake_create_tab_locations')) . '</a>';
    $html .= '<a class="pick-tab' . ($tab === 'sets' ? ' active' : '') . '" href="?screen=stocktake_create&tab=sets">' . htmlspecialchars(t('stocktake_create_tab_sets')) . '</a>';
    $html .= '</div>';

    if ($tab === 'locations') {
        $locations = getFlaggedStocktakeLocations($pdo);
        if (empty($locations)) {
            $html .= '<p class="pick-empty-hint">' . htmlspecialchars(t('stocktake_create_locations_empty')) . '</p>';
        } else {
            $html .= '<div class="pick-search-results">';
            foreach ($locations as $location) {
                $html .= '<div class="stocktake-location-row">';
                $html .= '<span class="stocktake-location-path">' . htmlspecialchars($location['path']) . '</span>';
                if ($location['activeStocktakeId'] !== null) {
                    $html .= '<a class="pick-btn pick-btn-primary" href="?screen=stocktake&id=' . $location['activeStocktakeId'] . '">' . htmlspecialchars(t('stocktake_resume_button')) . '</a>';
                } else {
                    $html .= '<label class="stocktake-recursive-label"><input type="checkbox" class="stocktake-recursive-checkbox" checked> ' . htmlspecialchars(t('location_content_recursive_toggle')) . '</label>';
                    $html .= '<button type="button" class="pick-btn pick-btn-primary stocktake-start-location-btn" data-location-id="' . $location['id'] . '">' . htmlspecialchars(t('stocktake_start_button')) . '</button>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }
    } else {
        // Deliberately a list of sets already added via owned_set_detail's
        // "Zur Inventurliste hinzufügen" choice, not a live search — per
        // explicit request, mirrors the locations tab exactly: adding a set
        // to this list only happens on the desktop, working through the
        // list only happens here.
        $sets = getFlaggedStocktakeOwnedSets($pdo);
        if (empty($sets)) {
            $html .= '<p class="pick-empty-hint">' . htmlspecialchars(t('stocktake_create_sets_empty')) . '</p>';
        } else {
            $html .= '<div class="pick-search-results">';
            foreach ($sets as $result) {
                if ($result['activeStocktakeId'] !== null) {
                    $html .= '<a class="pick-search-result" href="?screen=stocktake&id=' . $result['activeStocktakeId'] . '">';
                } else {
                    $html .= '<button type="button" class="pick-search-result stocktake-start-set-btn" data-owned-set-id="' . $result['ownedSetId'] . '">';
                }
                if (!empty($result['thumbnail'])) {
                    $html .= '<img src="' . htmlspecialchars(pickAssetUrl($result['thumbnail'])) . '" alt="">';
                }
                $html .= '<span>' . htmlspecialchars($result['label']) . '</span>';
                $html .= $result['activeStocktakeId'] !== null ? '</a>' : '</button>';
            }
            $html .= '</div>';
        }
    }

    $errorGenericJson = json_encode(t('pick_error_generic'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $html .= <<<SCRIPT
<script>
(function(){
  function startStocktake(action, params, btn) {
    btn.disabled = true;
    var formData = new FormData();
    formData.set('action', action);
    Object.keys(params).forEach(function(key) { formData.set(key, String(params[key])); });
    fetch('?action=' + action, { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (!res.success) {
          alert(res.message || $errorGenericJson);
          btn.disabled = false;
          return;
        }
        window.location.href = '?screen=stocktake&id=' + res.stocktakeId;
      })
      .catch(function() {
        alert($errorGenericJson);
        btn.disabled = false;
      });
  }

  document.querySelectorAll('.stocktake-start-location-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var row = btn.closest('.stocktake-location-row');
      var recursive = row.querySelector('.stocktake-recursive-checkbox').checked ? '1' : '0';
      startStocktake('start_stocktake_for_location', { location_id: btn.dataset.locationId, recursive: recursive }, btn);
    });
  });

  document.querySelectorAll('.stocktake-start-set-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      startStocktake('start_stocktake_for_owned_set', { owned_set_id: btn.dataset.ownedSetId }, btn);
    });
  });
})();
</script>
SCRIPT;

    $html .= '</div>';
    return $html;
}

function renderStocktakeActive(PDO $pdo, array $stocktake): string
{
    $items = getStocktakeItemsWithDisplay($pdo, (int) $stocktake['id']);
    $progress = getStocktakeProgress($pdo, (int) $stocktake['id']);
    $label = getStocktakeLabel($pdo, $stocktake);

    $html = '<div class="pick-screen">';
    $html .= '<h1>' . htmlspecialchars($label) . '</h1>';
    $html .= renderPickProgressBadge((int) $progress['total'], (int) $progress['confirmed']);

    if (empty($items)) {
        $html .= '<p class="pick-empty-hint">' . htmlspecialchars(t('stocktake_empty_note')) . '</p>';
    } else {
        $html .= '<div class="pick-active-deck" id="stocktake-active-deck">';
        foreach ($items as $item) {
            $html .= '<div class="pick-item-card' . ($item['confirmed'] ? ' stocktake-item-card-done' : '') . '">';
            if ($item['colorRgb'] !== null) {
                $html .= '<span class="stocktake-color-swatch" style="background:#' . htmlspecialchars($item['colorRgb']) . ';"></span>';
            }
            $html .= '<h2>' . htmlspecialchars($item['label']) . '</h2>';
            if ($item['colorName'] !== null) {
                $html .= '<p class="pick-item-color">' . htmlspecialchars($item['colorName']) . '</p>';
            }
            if ($item['locationPath'] !== null) {
                $html .= '<p class="pick-step-location">' . htmlspecialchars($item['locationPath']) . '</p>';
            }
            $html .= '<p>' . htmlspecialchars(t('stocktake_expected_label', ['nominal' => (string) $item['nominalQuantity']])) . '</p>';
            if ($item['confirmed']) {
                $html .= '<p class="stocktake-confirmed-badge">' . htmlspecialchars(t('stocktake_item_counted_label')) . '</p>';
            }
            $html .= '<div class="pick-step-box">';
            $html .= '<input type="number" class="pick-quantity-input" min="0" value="' . (int) ($item['confirmedQuantity'] ?? 0) . '">';
            $html .= '<button type="button" class="pick-btn pick-btn-primary pick-confirm-btn" data-stocktake-item-id="' . $item['id'] . '">' . htmlspecialchars(t('stocktake_confirm_button')) . '</button>';
            $html .= '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';

        if (count($items) > 1) {
            $html .= '<div class="pick-active-dots" id="stocktake-active-dots">';
            for ($i = 0, $n = count($items); $i < $n; $i++) {
                $html .= '<span class="pick-active-dot' . ($i === 0 ? ' active' : '') . '"></span>';
            }
            $html .= '</div>';
        }
    }

    $html .= '<div class="stocktake-footer-actions">';
    $html .= '<button type="button" class="pick-btn pick-btn-primary" id="stocktake-complete-btn">' . htmlspecialchars(t('stocktake_finish_button')) . '</button>';
    $html .= '<button type="button" class="pick-btn stocktake-danger-button" id="stocktake-cancel-btn">' . htmlspecialchars(t('stocktake_cancel_button')) . '</button>';
    $html .= '</div>';

    $stocktakeIdJson = json_encode((int) $stocktake['id']);
    $errorGenericJson = json_encode(t('pick_error_generic'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $cancelConfirmJson = json_encode(t('stocktake_cancel_confirm'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $html .= <<<SCRIPT
<script>
(function(){
  var stocktakeId = $stocktakeIdJson;

  document.querySelectorAll('.pick-confirm-btn').forEach(function(confirmBtn) {
    confirmBtn.addEventListener('click', function() {
      confirmBtn.disabled = true;
      var qtyInput = confirmBtn.closest('.pick-item-card').querySelector('.pick-quantity-input');
      var formData = new FormData();
      formData.set('action', 'stocktake_item_confirm');
      formData.set('stocktake_id', String(stocktakeId));
      formData.set('stocktake_item_id', confirmBtn.dataset.stocktakeItemId);
      formData.set('quantity', qtyInput ? qtyInput.value : '0');
      fetch('?action=stocktake_item_confirm', { method: 'POST', body: formData, credentials: 'same-origin' })
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

  var completeBtn = document.getElementById('stocktake-complete-btn');
  if (completeBtn) {
    completeBtn.addEventListener('click', function() {
      completeBtn.disabled = true;
      var formData = new FormData();
      formData.set('action', 'stocktake_complete');
      formData.set('stocktake_id', String(stocktakeId));
      fetch('?action=stocktake_complete', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (!res.success) {
            alert(res.message || $errorGenericJson);
            completeBtn.disabled = false;
            return;
          }
          window.location.href = '?screen=stocktake_list';
        })
        .catch(function() {
          alert($errorGenericJson);
          completeBtn.disabled = false;
        });
    });
  }

  var cancelBtn = document.getElementById('stocktake-cancel-btn');
  if (cancelBtn) {
    cancelBtn.addEventListener('click', function() {
      if (!window.confirm($cancelConfirmJson)) { return; }
      cancelBtn.disabled = true;
      var formData = new FormData();
      formData.set('action', 'stocktake_cancel');
      formData.set('stocktake_id', String(stocktakeId));
      fetch('?action=stocktake_cancel', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (!res.success) {
            alert(res.message || $errorGenericJson);
            cancelBtn.disabled = false;
            return;
          }
          window.location.href = '?screen=stocktake_list';
        })
        .catch(function() {
          alert($errorGenericJson);
          cancelBtn.disabled = false;
        });
    });
  }

  var deck = document.getElementById('stocktake-active-deck');
  var dots = document.getElementById('stocktake-active-dots');
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
