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

    // A third, independent overlay for the per-part defekt/fehlt stepper —
    // opened from inside the minifig-detail modal above, so it needs the
    // same "reparent to <body> and show on top" treatment (see
    // openMinifigModal()/openPartModal() in part_modal.php) to render above
    // it instead of underneath.
    $main .= '<div class="modal-overlay" id="minifig-part-qty-modal" style="display:none;">';
    $main .= '<div class="modal-box"><button type="button" class="modal-close" id="minifig-part-qty-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $main .= '<div id="minifig-part-qty-modal-content"></div>';
    $main .= '</div></div>';

    $modalLabelsJson = json_encode([
        'notFound' => t('minifig_not_found'),
        'errorRetry' => t('import_error_retry'),
        'bricklinkLink' => t('bricklink_link'),
        'rebrickableLink' => t('rebrickable_link'),
        'minifigIcon' => getNavIcon('minifigs'),
        'brickIcon' => getNavIcon('bricks'),
        'setsIcon' => getNavIcon('sets'),
        'componentsTitle' => t('minifig_modal_components_title'),
        'componentsEmpty' => t('minifig_modal_components_empty'),
        'storageInstanceLabel' => t('minifig_modal_storage_instance_label'),
        'addPartButton' => t('minifig_modal_add_part_button'),
        'partsStatusHint' => t('minifig_modal_parts_status_hint'),
        'ownedLabel' => t('owned_set_inventory_owned_label'),
        'damagedLabel' => t('owned_set_inventory_damaged_label'),
        'inventorySummary' => t('owned_set_inventory_summary'),
        'saveButton' => t('owned_set_save_button'),
        'appearsInSets' => t('minifig_appears_in_sets'),
        'appearsInNoSets' => t('minifig_appears_in_no_sets'),
        'minifigSetsTitle' => t('minifig_sets_title'),
        'backToMinifig' => t('back_to_minifig'),
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
  var partQtyModal = document.getElementById('minifig-part-qty-modal');
  var partQtyModalContent = document.getElementById('minifig-part-qty-modal-content');
  var partQtyModalCloseBtn = document.getElementById('minifig-part-qty-modal-close');
  if (!modal || !content || !closeBtn || !partQtyModal || !partQtyModalContent || !partQtyModalCloseBtn) {
    return;
  }

  // Which minifig_storage_items batch (of possibly several — the same
  // minifig can be stored in more than one place/condition) the visible
  // part tiles' defekt/fehlt status currently reflects — reassigned by
  // renderMinifigModal() and by the instance picker's change handler, read
  // by applyInstanceToTiles() and openPartQtyModal()'s save handler.
  var currentInstance = null;

  function closePartQtyModal() {
    partQtyModal.style.display = 'none';
    partQtyModalContent.innerHTML = '';
  }

  partQtyModalCloseBtn.addEventListener('click', closePartQtyModal);

  function closeModal() {
    modal.style.display = 'none';
    content.innerHTML = '';
    // Closing the parent while the nested per-part editor is still open
    // would otherwise leave it dangling on screen, referencing tiles that
    // no longer exist (content.innerHTML was just cleared).
    closePartQtyModal();
  }

  closeBtn.addEventListener('click', closeModal);

  function buildStepper(minVal, maxVal, value) {
    var wrap = document.createElement('div');
    wrap.className = 'owned-set-inventory-stepper';
    var minusBtn = document.createElement('button');
    minusBtn.type = 'button';
    minusBtn.className = 'owned-set-inventory-stepper-btn';
    minusBtn.textContent = '−';
    var input = document.createElement('input');
    input.type = 'number';
    input.min = String(minVal);
    input.max = String(maxVal);
    input.value = String(value);
    var plusBtn = document.createElement('button');
    plusBtn.type = 'button';
    plusBtn.className = 'owned-set-inventory-stepper-btn';
    plusBtn.textContent = '+';

    function step(delta) {
      var v = (parseInt(input.value, 10) || 0) + delta;
      v = Math.max(parseInt(input.min, 10), Math.min(v, parseInt(input.max, 10)));
      input.value = String(v);
      input.dispatchEvent(new Event('input'));
    }
    minusBtn.addEventListener('click', function() { step(-1); });
    plusBtn.addEventListener('click', function() { step(1); });

    wrap.appendChild(minusBtn);
    wrap.appendChild(input);
    wrap.appendChild(plusBtn);
    return { wrap: wrap, input: input };
  }

  // Mirrors renderOwnedSetQuantityModalScript()'s updateTile() in
  // src/owned_sets.php — same border-color/summary-line treatment, just
  // reused here for one component-part tile of a loose minifig instead of
  // one owned-set inventory item.
  function updateTile(tile, actual, damaged) {
    var nominal = parseInt(tile.dataset.nominal, 10);
    var missing = Math.max(0, nominal - actual);
    var intact = actual - damaged;
    tile.dataset.actual = String(actual);
    tile.dataset.damaged = String(damaged);
    tile.classList.remove('owned-set-inventory-tile-complete', 'owned-set-inventory-tile-damaged', 'owned-set-inventory-tile-missing');
    if (missing > 0) {
      tile.classList.add('owned-set-inventory-tile-missing');
    } else if (damaged > 0) {
      tile.classList.add('owned-set-inventory-tile-damaged');
    } else {
      tile.classList.add('owned-set-inventory-tile-complete');
    }
    var summary = tile.querySelector('.owned-set-inventory-summary');
    if (summary) {
      summary.textContent = texts.inventorySummary
        .replace('{intact}', intact)
        .replace('{damaged}', damaged)
        .replace('{missing}', missing);
    }
  }

  // (Re)applies one storage instance's per-part status onto the already-
  // rendered tiles — no re-fetch needed, action=minifig_detail already sent
  // every instance's full partsStatus map up front (see
  // renderMinifigModal()). A part with no entry for the current instance
  // (color_id was null, filtered out server-side — see
  // getMinifigStorageItemPartsWithStatus()'s doc comment) or no instance at
  // all just reverts to a plain, non-interactive tile.
  function applyInstanceToTiles(gridEl, instance) {
    currentInstance = instance;
    var tiles = gridEl.querySelectorAll('.minifig-component-tile');
    tiles.forEach(function(tile) {
      var status = instance ? instance.partsStatus[tile.dataset.key] : null;
      if (status) {
        tile.dataset.nominal = String(status.nominal);
        tile.setAttribute('role', 'button');
        tile.tabIndex = 0;
        var summary = tile.querySelector('.owned-set-inventory-summary');
        if (!summary) {
          summary = document.createElement('p');
          summary.className = 'owned-set-inventory-summary';
          tile.appendChild(summary);
        }
        updateTile(tile, status.actual, status.damaged);
      } else {
        delete tile.dataset.nominal;
        delete tile.dataset.actual;
        delete tile.dataset.damaged;
        tile.removeAttribute('role');
        tile.removeAttribute('tabindex');
        tile.classList.remove('owned-set-inventory-tile-complete', 'owned-set-inventory-tile-damaged', 'owned-set-inventory-tile-missing');
        var existingSummary = tile.querySelector('.owned-set-inventory-summary');
        if (existingSummary) {
          existingSummary.remove();
        }
      }
    });
  }

  // Single-part owned/damaged stepper — mirrors
  // renderOwnedSetQuantityModalScript()'s openModal() in src/owned_sets.php,
  // saving via action=save_minifig_storage_item_part instead of
  // save_owned_set_inventory.
  function openPartQtyModal(tile) {
    partQtyModalContent.innerHTML = '';
    document.body.appendChild(partQtyModal);
    partQtyModal.style.display = 'flex';

    var nominal = parseInt(tile.dataset.nominal, 10);
    var actual = parseInt(tile.dataset.actual, 10);
    var damaged = parseInt(tile.dataset.damaged, 10);

    var header = document.createElement('div');
    header.className = 'owned-set-qty-modal-header';
    var img = document.createElement('span');
    img.className = 'owned-set-qty-modal-image';
    if (tile.dataset.thumbnail) {
      img.innerHTML = '<img src="' + tile.dataset.thumbnail + '" alt="">';
    }
    header.appendChild(img);
    var info = document.createElement('div');
    var title = document.createElement('h3');
    title.textContent = tile.dataset.number;
    var name = document.createElement('p');
    name.textContent = tile.dataset.name;
    info.appendChild(title);
    info.appendChild(name);
    header.appendChild(info);
    partQtyModalContent.appendChild(header);

    var ownedLabel = document.createElement('label');
    ownedLabel.appendChild(document.createTextNode(texts.ownedLabel));
    var ownedStepper = buildStepper(0, nominal, actual);
    ownedLabel.appendChild(ownedStepper.wrap);
    partQtyModalContent.appendChild(ownedLabel);

    var damagedLabel = document.createElement('label');
    damagedLabel.appendChild(document.createTextNode(texts.damagedLabel));
    var damagedStepper = buildStepper(0, actual, damaged);
    damagedLabel.appendChild(damagedStepper.wrap);
    partQtyModalContent.appendChild(damagedLabel);

    ownedStepper.input.addEventListener('input', function() {
      var v = parseInt(ownedStepper.input.value, 10) || 0;
      damagedStepper.input.max = String(v);
      if ((parseInt(damagedStepper.input.value, 10) || 0) > v) {
        damagedStepper.input.value = String(v);
      }
    });

    var msg = document.createElement('p');
    msg.className = 'owned-set-message';
    partQtyModalContent.appendChild(msg);

    var saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.textContent = texts.saveButton;
    saveBtn.addEventListener('click', function() {
      msg.textContent = '';
      var newOwned = Math.max(0, Math.min(parseInt(ownedStepper.input.value, 10) || 0, nominal));
      var newDamaged = Math.max(0, Math.min(parseInt(damagedStepper.input.value, 10) || 0, newOwned));

      var formData = new FormData();
      formData.set('action', 'save_minifig_storage_item_part');
      formData.set('minifig_storage_item_id', String(currentInstance.id));
      formData.set('part_id', tile.dataset.partId);
      formData.set('color_id', tile.dataset.colorId);
      formData.set('quantity', String(newOwned));
      formData.set('damaged_quantity', String(newDamaged));

      saveBtn.disabled = true;
      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          saveBtn.disabled = false;
          if (res.success) {
            currentInstance.partsStatus[tile.dataset.key] = { nominal: nominal, actual: newOwned, damaged: newDamaged };
            updateTile(tile, newOwned, newDamaged);
            window.applyStatusStats(res.stats);
            closePartQtyModal();
          } else {
            msg.textContent = res.message || texts.errorRetry;
          }
        })
        .catch(function() {
          saveBtn.disabled = false;
          msg.textContent = texts.errorRetry;
        });
    });
    partQtyModalContent.appendChild(saveBtn);
  }

  function openMinifigModal(minifigId) {
    content.innerHTML = '';
    closePartQtyModal();
    // See the matching comment in part_modal.php's openPartModal(): keeps
    // this modal on top of any other already-open .modal-overlay whenever
    // it's (re)opened, regardless of markup order on the page.
    document.body.appendChild(modal);
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

    var links = document.createElement('p');
    links.className = 'part-modal-links';
    var blLink = document.createElement('a');
    blLink.href = fig.bricklink_url;
    blLink.target = '_blank';
    blLink.rel = 'noopener';
    blLink.textContent = texts.bricklinkLink;
    var rbLink = document.createElement('a');
    rbLink.href = fig.rebrickable_url;
    rbLink.target = '_blank';
    rbLink.rel = 'noopener';
    rbLink.textContent = texts.rebrickableLink;
    links.appendChild(blLink);
    links.appendChild(document.createTextNode(' · '));
    links.appendChild(rbLink);
    info.appendChild(links);

    var sets = document.createElement('p');
    sets.className = 'part-modal-sets';
    var setsText = texts.appearsInSets
      .replace('{total}', fig.total_appearances)
      .replace('{count}', fig.sets_count)
      .replace('{minYear}', fig.min_year)
      .replace('{maxYear}', fig.max_year);
    if (fig.sets_count > 0) {
      var setsLink = document.createElement('a');
      setsLink.href = '#';
      setsLink.textContent = setsText;
      setsLink.addEventListener('click', function(e) {
        e.preventDefault();
        openMinifigSetsList(fig);
      });
      sets.appendChild(setsLink);
    } else {
      sets.textContent = texts.appearsInNoSets;
    }
    info.appendChild(sets);

    header.appendChild(info);
    content.appendChild(header);

    var componentsTitle = document.createElement('h3');
    componentsTitle.textContent = texts.componentsTitle;
    content.appendChild(componentsTitle);

    var parts = data.parts || [];
    var storageInstances = data.storageInstances || [];
    if (parts.length === 0) {
      var empty = document.createElement('p');
      empty.className = 'hint';
      empty.textContent = texts.componentsEmpty;
      content.appendChild(empty);
    } else {
      // Which batch (if more than one) the tiles' status reflects — picked
      // via the instance selector below when there's a choice, otherwise
      // the sole one, otherwise null (minifig not in storage at all yet).
      if (storageInstances.length > 1) {
        var pickerLabel = document.createElement('label');
        pickerLabel.className = 'minifig-modal-instance-picker';
        pickerLabel.appendChild(document.createTextNode(texts.storageInstanceLabel));
        var pickerSelect = document.createElement('select');
        storageInstances.forEach(function(inst, idx) {
          var opt = document.createElement('option');
          opt.value = String(idx);
          var condLabel = inst.conditionType === 'new' ? texts.conditionNew : texts.conditionUsed;
          opt.textContent = inst.locationName + ' · ' + condLabel + ' · ' + inst.quantity + 'x';
          pickerSelect.appendChild(opt);
        });
        pickerSelect.addEventListener('change', function() {
          applyInstanceToTiles(grid, storageInstances[parseInt(pickerSelect.value, 10)]);
        });
        pickerLabel.appendChild(pickerSelect);
        content.appendChild(pickerLabel);
      }

      // .part-card stays the base class (shared box/border styling, plus
      // it's what renderPartDetailModal()'s document-level click delegation
      // matches on — required so the "+" button below can still open that
      // modal, see the grid click handler further down); .minifig-component-tile
      // is purely a marker for applyInstanceToTiles()'s own tile lookup plus
      // the "+" button's absolute positioning.
      var grid = document.createElement('div');
      grid.className = 'parts-grid';
      parts.forEach(function(part) {
        var card = document.createElement('div');
        card.className = 'part-card minifig-component-tile';
        card.dataset.partId = part.part_id;
        card.dataset.colorId = part.color_id;
        card.dataset.key = part.part_id + ':' + part.color_id;
        card.dataset.number = part.part_num;
        card.dataset.name = part.name;
        var partThumb = part.ldraw_thumbnail || part.thumbnail || part.remote_thumbnail;
        card.dataset.thumbnail = partThumb || '';

        var addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'minifig-component-tile-add';
        addBtn.setAttribute('aria-label', texts.addPartButton);
        addBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
        card.appendChild(addBtn);

        var thumb = document.createElement('span');
        thumb.className = 'part-card-image';
        thumb.innerHTML = partThumb ? '<img src="' + partThumb + '" alt="">' : texts.brickIcon;
        card.appendChild(thumb);

        var num = document.createElement('span');
        num.className = 'part-card-num';
        num.textContent = part.part_num;
        card.appendChild(num);

        var name = document.createElement('span');
        name.className = 'part-card-name';
        name.title = part.name;
        name.textContent = part.name;
        card.appendChild(name);

        var partMeta = document.createElement('span');
        partMeta.className = 'part-card-meta';
        partMeta.textContent = (part.color_name || '') + ' \\u00b7 ' + part.quantity + 'x';
        card.appendChild(partMeta);

        grid.appendChild(card);
      });
      content.appendChild(grid);

      applyInstanceToTiles(grid, storageInstances.length > 0 ? storageInstances[0] : null);
      if (storageInstances.length === 0) {
        var hint = document.createElement('p');
        hint.className = 'hint';
        hint.textContent = texts.partsStatusHint;
        content.appendChild(hint);
      }

      // The "+" button is left to bubble up to renderPartDetailModal()'s own
      // document-level listener unchanged (it still matches this tile's
      // .part-card class); clicking anywhere else on the tile is now this
      // feature instead, so that bubble is stopped here.
      grid.addEventListener('click', function(e) {
        if (e.target.closest('.minifig-component-tile-add')) {
          return;
        }
        var tile = e.target.closest('.minifig-component-tile');
        if (!tile) {
          return;
        }
        e.stopPropagation();
        if (tile.dataset.nominal !== undefined) {
          openPartQtyModal(tile);
        }
      });
      grid.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter' && e.key !== ' ') {
          return;
        }
        if (e.target.closest('.minifig-component-tile-add')) {
          return;
        }
        var tile = e.target.closest('.minifig-component-tile');
        if (!tile || tile.dataset.nominal === undefined) {
          return;
        }
        e.preventDefault();
        e.stopPropagation();
        openPartQtyModal(tile);
      });
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

  function openMinifigSetsList(fig) {
    content.innerHTML = '';

    var backLink = document.createElement('a');
    backLink.href = '#';
    backLink.className = 'part-sets-back';
    backLink.textContent = texts.backToMinifig;
    backLink.addEventListener('click', function(e) {
      e.preventDefault();
      openMinifigModal(fig.id);
    });
    content.appendChild(backLink);

    var title = document.createElement('h3');
    title.textContent = texts.minifigSetsTitle;
    content.appendChild(title);

    var list = document.createElement('div');
    list.className = 'part-sets-list';
    content.appendChild(list);

    fetch('?action=minifig_sets&minifig_id=' + encodeURIComponent(fig.id), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        (data.sets || []).forEach(function(set) {
          var row = document.createElement('div');
          row.className = 'part-sets-row';

          var thumb = document.createElement('span');
          thumb.className = 'part-sets-thumb';
          thumb.innerHTML = set.thumbnail
            ? '<img src="' + set.thumbnail + '" alt="">'
            : texts.setsIcon;
          row.appendChild(thumb);

          var setInfo = document.createElement('span');
          setInfo.className = 'part-sets-info';
          var setName = document.createElement('span');
          setName.className = 'part-sets-name';
          setName.textContent = (set.name || set.set_num) + (set.year ? ' (' + set.year + ')' : '');
          var setMeta = document.createElement('span');
          setMeta.className = 'part-sets-meta';
          setMeta.textContent = set.set_num + ' · ' + set.quantity + 'x';
          setInfo.appendChild(setName);
          setInfo.appendChild(setMeta);
          row.appendChild(setInfo);

          list.appendChild(row);
        });
      })
      .catch(function() {
        list.textContent = texts.errorRetry;
      });
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
