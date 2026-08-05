<?php

declare(strict_types=1);

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/icons.php';

/**
 * The minifig-detail overlay (image, constituent parts, "add as loose
 * minifig to storage") is opened by clicking any
 * ".minifig-card[data-minifig-id]" element anywhere on the page — same
 * document-level click/keydown delegation as renderPartDetailModal()
 * (src/part_modal.php), so it works unchanged wherever minifig cards show
 * up. Deliberately a single panel, not tabbed like the part modal: there's
 * only one action here (add to storage), not three.
 */
function renderMinifigDetailModal(): string
{
    $main = '';
    $main .= '<div class="modal-overlay" id="minifig-detail-modal" style="display:none;">';
    $main .= '<div class="modal-box"><button type="button" class="modal-close" id="minifig-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $main .= '<div id="minifig-modal-content"></div>';
    $main .= '</div></div>';

    $modalLabelsJson = json_encode([
        'notFound' => t('minifig_not_found'),
        'errorRetry' => t('import_error_retry'),
        'minifigIcon' => getNavIcon('minifigs'),
        'brickIcon' => getNavIcon('bricks'),
        'componentsTitle' => t('minifig_modal_components_title'),
        'componentsEmpty' => t('minifig_modal_components_empty'),
        'addToInventoryTitle' => t('add_to_inventory_title'),
        'quantityLabel' => t('add_stock_quantity_label'),
        'conditionLabel' => t('add_stock_condition_label'),
        'conditionNew' => t('condition_new'),
        'conditionUsed' => t('condition_used'),
        'levelLabel' => t('location_picker_level_label'),
        'rootLabel' => t('location_picker_root_label'),
        'selectPlaceholder' => t('add_stock_select_placeholder'),
        'noChildren' => t('add_stock_no_children'),
        'addButton' => t('add_stock_button'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $main .= <<<SCRIPT
<script>
(function(){
  var texts = $modalLabelsJson;
  var LAST_ADD_LOCATION_STORAGE_KEY = 'studsphere_last_add_location';
  var modal = document.getElementById('minifig-detail-modal');
  var content = document.getElementById('minifig-modal-content');
  var closeBtn = document.getElementById('minifig-modal-close');
  if (!modal || !content || !closeBtn) {
    return;
  }

  function closeModal() {
    modal.style.display = 'none';
    content.innerHTML = '';
  }

  closeBtn.addEventListener('click', closeModal);

  function openMinifigModal(minifigId) {
    content.innerHTML = '';
    modal.style.display = 'flex';

    fetch('?action=minifig_detail&minifig_id=' + encodeURIComponent(minifigId), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.error) {
          content.innerHTML = '<p>' + texts.notFound + '</p>';
          return;
        }
        renderMinifigModal(data);
      })
      .catch(function() {
        content.innerHTML = '<p>' + texts.errorRetry + '</p>';
      });
  }

  function renderMinifigModal(data) {
    var fig = data.minifig;
    content.innerHTML = '';

    var header = document.createElement('div');
    header.className = 'part-modal-header';

    var img = document.createElement('div');
    img.className = 'part-modal-image';
    img.innerHTML = fig.thumbnail
      ? '<img src="' + fig.thumbnail + '" alt="">'
      : texts.minifigIcon;
    header.appendChild(img);

    var info = document.createElement('div');
    info.className = 'part-modal-info';
    var title = document.createElement('h2');
    title.textContent = fig.name || fig.fig_num;
    var meta = document.createElement('p');
    meta.className = 'part-modal-meta';
    meta.textContent = fig.fig_num;
    info.appendChild(title);
    info.appendChild(meta);
    header.appendChild(info);
    content.appendChild(header);

    var componentsTitle = document.createElement('h3');
    componentsTitle.textContent = texts.componentsTitle;
    content.appendChild(componentsTitle);

    var parts = data.parts || [];
    if (parts.length === 0) {
      var empty = document.createElement('p');
      empty.className = 'hint';
      empty.textContent = texts.componentsEmpty;
      content.appendChild(empty);
    } else {
      var grid = document.createElement('div');
      grid.className = 'minifig-modal-parts-grid';
      parts.forEach(function(part) {
        var tile = document.createElement('div');
        tile.className = 'minifig-modal-part-tile';

        var thumb = document.createElement('span');
        thumb.className = 'minifig-modal-part-tile-image';
        var partThumb = part.ldraw_thumbnail || part.thumbnail || part.remote_thumbnail;
        thumb.innerHTML = partThumb ? '<img src="' + partThumb + '" alt="">' : texts.brickIcon;
        tile.appendChild(thumb);

        var num = document.createElement('span');
        num.className = 'minifig-modal-part-tile-num';
        num.textContent = part.part_num;
        tile.appendChild(num);

        var name = document.createElement('span');
        name.className = 'minifig-modal-part-tile-name';
        name.title = part.name;
        name.textContent = part.name;
        tile.appendChild(name);

        var partMeta = document.createElement('span');
        partMeta.className = 'minifig-modal-part-tile-meta';
        var swatch = document.createElement('span');
        swatch.className = 'minifig-modal-part-tile-swatch';
        swatch.style.backgroundColor = part.color_rgb ? '#' + part.color_rgb.replace('#', '') : '#cccccc';
        partMeta.appendChild(swatch);
        partMeta.appendChild(document.createTextNode((part.color_name || '') + ' \\u00b7 ' + part.quantity + 'x'));
        tile.appendChild(partMeta);

        grid.appendChild(tile);
      });
      content.appendChild(grid);
    }

    var addTitle = document.createElement('h3');
    addTitle.textContent = texts.addToInventoryTitle;
    content.appendChild(addTitle);

    var msgBox = document.createElement('div');
    msgBox.className = 'add-stock-message';
    content.appendChild(msgBox);

    var form = document.createElement('form');
    form.className = 'add-stock-form';

    var qtyLabel = document.createElement('label');
    qtyLabel.textContent = texts.quantityLabel;
    var qtyInput = document.createElement('input');
    qtyInput.type = 'number';
    qtyInput.name = 'quantity';
    qtyInput.min = '1';
    qtyInput.value = '1';
    qtyLabel.appendChild(qtyInput);
    form.appendChild(qtyLabel);

    var condLabel = document.createElement('label');
    condLabel.textContent = texts.conditionLabel;
    var condSelect = document.createElement('select');
    condSelect.name = 'condition_type';
    var optUsed = document.createElement('option');
    optUsed.value = 'used';
    optUsed.textContent = texts.conditionUsed;
    optUsed.selected = true;
    var optNew = document.createElement('option');
    optNew.value = 'new';
    optNew.textContent = texts.conditionNew;
    condSelect.appendChild(optUsed);
    condSelect.appendChild(optNew);
    condLabel.appendChild(condSelect);
    form.appendChild(condLabel);

    var locationContainer = document.createElement('div');
    locationContainer.className = 'location-picker';
    form.appendChild(locationContainer);
    var selectedLocationId = null;
    var lastAddLocationId = null;
    try {
      lastAddLocationId = window.localStorage.getItem(LAST_ADD_LOCATION_STORAGE_KEY);
    } catch (e) {
      // Private browsing / storage disabled — picker just starts empty.
    }
    window.createLocationPicker(locationContainer, texts, function(value) {
      selectedLocationId = value;
    }, lastAddLocationId);

    var submitBtn = document.createElement('button');
    submitBtn.type = 'submit';
    submitBtn.textContent = texts.addButton;
    form.appendChild(submitBtn);

    form.addEventListener('submit', function(e) {
      e.preventDefault();
      msgBox.textContent = '';
      msgBox.className = 'add-stock-message';
      submitBtn.disabled = true;

      var formData = new FormData();
      formData.set('action', 'add_minifig_stock');
      formData.set('minifig_id', fig.id);
      formData.set('quantity', qtyInput.value);
      formData.set('condition_type', condSelect.value);
      formData.set('location_id', selectedLocationId || '');

      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          submitBtn.disabled = false;
          msgBox.textContent = res.message;
          msgBox.className = 'add-stock-message ' + (res.success ? 'success' : 'error');
          if (res.success) {
            window.applyStatusStats(res.stats);
            try {
              window.localStorage.setItem(LAST_ADD_LOCATION_STORAGE_KEY, selectedLocationId);
            } catch (ex) {
              // ignore
            }
          }
        })
        .catch(function() {
          submitBtn.disabled = false;
          msgBox.textContent = texts.errorRetry;
          msgBox.className = 'add-stock-message error';
        });
    });

    content.appendChild(form);
  }

  document.addEventListener('click', function(e) {
    var card = e.target.closest('.minifig-card');
    if (card) {
      openMinifigModal(card.dataset.minifigId);
    }
  });
  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter' && e.key !== ' ') {
      return;
    }
    var card = e.target.closest('.minifig-card');
    if (card) {
      e.preventDefault();
      openMinifigModal(card.dataset.minifigId);
    }
  });
})();
</script>
SCRIPT;

    return $main;
}
