<?php

declare(strict_types=1);

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/icons.php';

/**
 * The part-detail overlay — image (color-correct when a color is known),
 * name/translation, catalog links, BrickLink/BrickOwl ids, appears-in-sets
 * (grouped by theme), and its own "Bestand bearbeiten / Zum Lager
 * hinzufuegen / Informationen" tabs — is opened by clicking any
 * ".part-card[data-part-id]" element anywhere on the page (click/keydown
 * delegation is on document, not a specific grid) OR by the location
 * Explorer's own part cards calling window.openPartModal() directly with
 * full context (color/location/condition), which is what makes "Bestand
 * bearbeiten" show that exact stock row instead of a generic add-form.
 */
function renderPartDetailModal(): string
{
    $main = '';
        $main .= '<div class="modal-overlay" id="part-detail-modal" style="display:none;">';
        $main .= '<div class="modal-box"><button type="button" class="modal-close" id="part-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
        $main .= '<div id="part-modal-content"></div>';
        $main .= '</div></div>';

        $modalLabelsJson = json_encode([
            'notFound' => t('part_not_found'),
            'appearsInSets' => t('part_appears_in_sets'),
            'appearsInNoSets' => t('part_appears_in_no_sets'),
            'addToInventoryTitle' => t('add_to_inventory_title'),
            'colorLabel' => t('filter_color_title'),
            'knownColorsTitle' => t('add_stock_known_colors_title'),
            'quantityLabel' => t('add_stock_quantity_label'),
            'conditionLabel' => t('add_stock_condition_label'),
            'conditionNew' => t('condition_new'),
            'conditionUsed' => t('condition_used'),
            'levelLabel' => t('location_picker_level_label'),
            'rootLabel' => t('location_picker_root_label'),
            'selectPlaceholder' => t('add_stock_select_placeholder'),
            'noChildren' => t('add_stock_no_children'),
            'printOfLabel' => t('print_of_label'),
            'colorOtherGroupLabel' => t('color_other_group_label'),
            'partSetsTitle' => t('part_sets_title'),
            'backToPart' => t('back_to_part'),
            'translationAddButton' => t('translation_add_button'),
            'translationEditButton' => t('translation_edit_button'),
            'translationSaveButton' => t('translation_save_button'),
            'translationCancelButton' => t('translation_cancel_button'),
            'translationOriginalLabel' => t('translation_original_name_label'),
            'translationPlaceholder' => t('translation_placeholder'),
            'addButton' => t('add_stock_button'),
            'errorRetry' => t('import_error_retry'),
            'tabEditStock' => t('part_tab_edit_stock'),
            'tabInfo' => t('part_tab_info'),
            'editStockDamagedQuantityLabel' => t('part_edit_stock_damaged_quantity_label'),
            'editStockSaveButton' => t('location_save_button'),
            'editStockSaveSuccess' => t('part_edit_stock_save_success'),
            'editStockSaveFailed' => t('part_edit_stock_save_failed'),
            'editStockEmptyHint' => t('part_edit_stock_empty_hint'),
            'editStockPickRowHint' => t('part_edit_stock_pick_row_hint'),
            'bricklinkIdLabel' => t('part_bricklink_id_label'),
            'brickowlIdLabel' => t('part_brickowl_id_label'),
            'externalIdsSaveButton' => t('translation_save_button'),
            'externalIdsSaveSuccess' => t('part_external_ids_save_success'),
            'externalIdsSaveFailed' => t('part_external_ids_save_failed'),
            'bricklinkPriceLine' => t('part_bricklink_price_line'),
            'bricklinkPriceNeverLabel' => t('owned_set_bricklink_price_never'),
            'bricklinkRefreshLabel' => t('owned_set_bricklink_price_refresh_label'),
            'bricklinkRefreshFailed' => t('owned_set_bricklink_price_refresh_failed'),
            'refreshIcon' => getActionIcon('refresh'),
            'editIcon' => getActionIcon('edit'),
            'ldrawIdLabel' => t('part_ldraw_id_label'),
            'rebrickableLinkLabel' => t('rebrickable_link'),
            'setsNoThemeLabel' => t('part_sets_no_theme_label'),
            'weightLabel' => t('part_modal_weight_label'),
            'weightInheritedHint' => t('part_modal_weight_inherited_hint'),
            'weightUnknown' => t('part_modal_weight_unknown'),
            'weightSaveFailed' => t('part_external_ids_save_failed'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $main .= <<<SCRIPT
<script>
(function(){
  var texts = $modalLabelsJson;
  // Remembers the last location a "Zum Lager hinzufügen" submit actually
  // used, so the picker starts pre-filled there next time instead of empty
  // at the root — most adds go to the same shelf/bin in a row. Per-browser
  // (localStorage), not per-user server-side: simplest fit for "just save
  // me the repeat clicks," no schema/account plumbing needed for it.
  var LAST_ADD_LOCATION_STORAGE_KEY = 'studsphere_last_add_location';
  var modal = document.getElementById('part-detail-modal');
  var content = document.getElementById('part-modal-content');
  var closeBtn = document.getElementById('part-modal-close');
  if (!modal || !content || !closeBtn) {
    return;
  }

  function closeModal() {
    modal.style.display = 'none';
    content.innerHTML = '';
  }

  closeBtn.addEventListener('click', closeModal);

  // Set once per openPartModal() call, read by renderPartModal() to decide
  // the default active tab and to know what "the clicked row" actually was
  // (colors.id — the surrogate PK — not Rebrickable's own numbering, see
  // that distinction's own note further down at the .part-card delegate).
  var openContext = { colorId: null, locationId: null, conditionType: null };

  function openPartModal(partId, colorId, locationId, conditionType) {
    openContext = { colorId: colorId || null, locationId: locationId || null, conditionType: conditionType || null };
    content.innerHTML = '';
    // Moved to the end of <body> on every open: .modal-overlay elements all
    // share the same z-index, so among overlapping fixed-position siblings
    // stacking falls back to DOM order — reparenting here guarantees
    // whichever modal was opened most recently (e.g. this one, opened from
    // inside an already-open minifig modal) renders on top, regardless of
    // where its markup originally sat on the page.
    document.body.appendChild(modal);
    modal.style.display = 'flex';

    var params = new URLSearchParams();
    params.set('action', 'part_detail');
    params.set('part_id', partId);
    if (openContext.colorId) { params.set('color_id', openContext.colorId); }
    if (openContext.locationId) { params.set('location_id', openContext.locationId); }
    if (openContext.conditionType) { params.set('condition_type', openContext.conditionType); }

    fetch('?' + params.toString(), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.error) {
          content.innerHTML = '<p>' + texts.notFound + '</p>';
          return;
        }
        renderPartModal(data);
      })
      .catch(function() {
        content.innerHTML = '<p>' + texts.errorRetry + '</p>';
      });
  }
  window.openPartModal = openPartModal;

  function colorSwatchStyle(rgb) {
    return rgb ? '#' + String(rgb).replace('#', '') : '#cccccc';
  }

  function renderPartModal(data) {
    var part = data.part;
    var context = openContext;
    content.innerHTML = '';

    var tabBar = document.createElement('div');
    tabBar.className = 'part-modal-tabs';
    var tabPanels = document.createElement('div');
    tabPanels.className = 'part-modal-tab-panels';

    var panelEditStock = document.createElement('div');
    panelEditStock.className = 'part-modal-tab-panel';
    var panelAddStock = document.createElement('div');
    panelAddStock.className = 'part-modal-tab-panel';
    var panelInfo = document.createElement('div');
    panelInfo.className = 'part-modal-tab-panel';

    var tabs = [
      { label: texts.tabEditStock, panel: panelEditStock },
      { label: texts.addToInventoryTitle, panel: panelAddStock },
      { label: texts.tabInfo, panel: panelInfo }
    ];
    var tabButtons = [];

    function activateTab(index) {
      tabs.forEach(function(tab, i) {
        tab.panel.classList.toggle('active', i === index);
        tabButtons[i].classList.toggle('active', i === index);
      });
    }

    tabs.forEach(function(tab, i) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'part-modal-tab-btn';
      btn.textContent = tab.label;
      btn.addEventListener('click', function() { activateTab(i); });
      tabBar.appendChild(btn);
      tabButtons.push(btn);
      tabPanels.appendChild(tab.panel);
    });

    buildEditStockPanel(panelEditStock, part, context);
    buildAddStockPanel(panelAddStock, part, data, context);
    buildInfoPanel(panelInfo, part, data);

    // Opened with full context (from the location Explorer, where there's a
    // specific existing row to look at) -> "Bestand bearbeiten" first.
    // Opened without it (bricks_search, a set's inventory tab, etc. — every
    // call site before this modal supported color/location context at all)
    // -> "Zum Lager hinzufügen" first, matching that unchanged behavior.
    var defaultTab = (context.colorId && context.locationId && context.conditionType) ? 0 : 1;
    activateTab(defaultTab);

    content.appendChild(tabBar);
    content.appendChild(tabPanels);
  }

  // ---- "Bestand bearbeiten" -------------------------------------------

  function buildEditStockPanel(panel, part, context) {
    panel.innerHTML = '';

    if (part.stockRow) {
      renderEditStockForm(panel, part, context.locationId, context.colorId, context.conditionType, part.stockRow);
      return;
    }

    var candidates = part.stockCandidates || [];
    if (candidates.length === 0) {
      var empty = document.createElement('p');
      empty.className = 'hint';
      empty.textContent = texts.editStockEmptyHint;
      panel.appendChild(empty);
      return;
    }
    if (candidates.length === 1) {
      var only = candidates[0];
      renderEditStockForm(panel, part, only.location_id, only.color_id, only.condition_type, { quantity: only.quantity, damaged_quantity: only.damaged_quantity });
      return;
    }

    var pickHint = document.createElement('p');
    pickHint.className = 'hint';
    pickHint.textContent = texts.editStockPickRowHint;
    panel.appendChild(pickHint);

    var list = document.createElement('div');
    list.className = 'part-edit-stock-picker-list';
    candidates.forEach(function(row) {
      var rowEl = document.createElement('button');
      rowEl.type = 'button';
      rowEl.className = 'part-edit-stock-picker-row';
      var swatch = document.createElement('span');
      swatch.className = 'location-detail-card-swatch';
      swatch.style.backgroundColor = colorSwatchStyle(row.color_rgb);
      rowEl.appendChild(swatch);
      var condText = row.condition_type === 'new' ? texts.conditionNew : texts.conditionUsed;
      var label = document.createElement('span');
      label.textContent = (row.color_name || '') + ' \\u00b7 ' + condText + ' \\u00b7 ' + row.location_path + ' \\u00b7 ' + row.quantity + 'x';
      rowEl.appendChild(label);
      rowEl.addEventListener('click', function() {
        panel.innerHTML = '';
        renderEditStockForm(panel, part, row.location_id, row.color_id, row.condition_type, { quantity: row.quantity, damaged_quantity: row.damaged_quantity });
      });
      list.appendChild(rowEl);
    });
    panel.appendChild(list);
  }

  function renderEditStockForm(panel, part, locationId, colorId, conditionType, stockRow) {
    var wrap = document.createElement('div');
    wrap.className = 'part-modal-edit-stock';

    var img = document.createElement('div');
    img.className = 'part-modal-image';
    img.innerHTML = part.thumbnail ? '<img src="' + part.thumbnail + '" alt="">' : (content.dataset.fallbackIcon || '');
    wrap.appendChild(img);

    var formWrap = document.createElement('div');
    formWrap.className = 'part-modal-edit-stock-form-wrap';

    var msgBox = document.createElement('div');
    msgBox.className = 'add-stock-message';
    formWrap.appendChild(msgBox);

    var form = document.createElement('form');

    var qtyLabel = document.createElement('label');
    qtyLabel.textContent = texts.quantityLabel;
    var qtyInput = document.createElement('input');
    qtyInput.type = 'number';
    qtyInput.min = '0';
    qtyInput.value = String(stockRow.quantity);
    qtyLabel.appendChild(qtyInput);
    form.appendChild(qtyLabel);

    var damagedLabel = document.createElement('label');
    damagedLabel.textContent = texts.editStockDamagedQuantityLabel;
    var damagedInput = document.createElement('input');
    damagedInput.type = 'number';
    damagedInput.min = '0';
    damagedInput.value = String(stockRow.damaged_quantity || 0);
    damagedLabel.appendChild(damagedInput);
    form.appendChild(damagedLabel);

    var condLabel = document.createElement('label');
    condLabel.textContent = texts.conditionLabel;
    var condSelect = document.createElement('select');
    var optUsed = document.createElement('option');
    optUsed.value = 'used';
    optUsed.textContent = texts.conditionUsed;
    var optNew = document.createElement('option');
    optNew.value = 'new';
    optNew.textContent = texts.conditionNew;
    condSelect.appendChild(optUsed);
    condSelect.appendChild(optNew);
    condSelect.value = conditionType;
    condLabel.appendChild(condSelect);
    form.appendChild(condLabel);

    var submitBtn = document.createElement('button');
    submitBtn.type = 'submit';
    submitBtn.textContent = texts.editStockSaveButton;
    form.appendChild(submitBtn);

    form.addEventListener('submit', function(e) {
      e.preventDefault();
      msgBox.textContent = '';
      msgBox.className = 'add-stock-message';
      submitBtn.disabled = true;

      var formData = new FormData();
      formData.set('action', 'update_storage_item');
      formData.set('location_id', locationId);
      formData.set('part_id', part.id);
      formData.set('color_id', colorId);
      formData.set('condition_type', conditionType);
      formData.set('quantity', qtyInput.value);
      formData.set('damaged_quantity', damagedInput.value);
      if (condSelect.value !== conditionType) {
        formData.set('new_condition_type', condSelect.value);
      }

      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          submitBtn.disabled = false;
          msgBox.textContent = res.success ? texts.editStockSaveSuccess : (texts.editStockSaveFailed + ' ' + (res.message || ''));
          msgBox.className = 'add-stock-message ' + (res.success ? 'success' : 'error');
          if (res.success) {
            window.applyStatusStats(res.stats);
          }
        })
        .catch(function() {
          submitBtn.disabled = false;
          msgBox.textContent = texts.errorRetry;
          msgBox.className = 'add-stock-message error';
        });
    });

    formWrap.appendChild(form);
    wrap.appendChild(formWrap);
    panel.appendChild(wrap);
  }

  // ---- "Zum Lager hinzufügen" (pixel-identical to before, only the
  // location picker's starting point changes when opened with context) ----

  function buildAddStockPanel(panel, part, data, context) {
    panel.innerHTML = '';

    var msgBox = document.createElement('div');
    msgBox.className = 'add-stock-message';
    panel.appendChild(msgBox);

    var form = document.createElement('form');
    form.className = 'add-stock-form';

    var knownColors = data.knownColors || [];
    var otherColors = data.otherColors || [];
    var allPickerColors = knownColors.concat(otherColors);

    var colorHiddenInput = document.createElement('input');
    colorHiddenInput.type = 'hidden';
    colorHiddenInput.name = 'color_id';

    var colorLabel = document.createElement('label');
    colorLabel.textContent = texts.colorLabel;

    var combo = document.createElement('div');
    combo.className = 'color-combo';

    var comboToggle = document.createElement('button');
    comboToggle.type = 'button';
    comboToggle.className = 'color-combo-toggle';
    var comboToggleSwatch = document.createElement('span');
    comboToggleSwatch.className = 'color-combo-toggle-swatch';
    var comboToggleName = document.createElement('span');
    comboToggleName.className = 'color-combo-toggle-name';
    comboToggle.appendChild(comboToggleSwatch);
    comboToggle.appendChild(comboToggleName);

    var comboPanel = document.createElement('div');
    comboPanel.className = 'color-combo-panel';
    comboPanel.style.display = 'none';

    var swatchGrid = null;

    function updateActiveSwatch() {
      if (!swatchGrid) {
        return;
      }
      Array.prototype.forEach.call(swatchGrid.children, function(btn) {
        btn.classList.toggle('active', btn.dataset.colorId === colorHiddenInput.value);
      });
    }

    function updateComboOptionActive() {
      Array.prototype.forEach.call(comboPanel.querySelectorAll('.color-combo-option'), function(btn) {
        btn.classList.toggle('active', btn.dataset.colorId === colorHiddenInput.value);
      });
    }

    function setColorValue(c) {
      colorHiddenInput.value = String(c.id);
      comboToggleSwatch.style.backgroundColor = colorSwatchStyle(c.rgb);
      comboToggleName.textContent = c.name;
      updateActiveSwatch();
      updateComboOptionActive();
    }

    function closeCombo() {
      comboPanel.style.display = 'none';
    }

    function buildComboOption(c) {
      var opt = document.createElement('button');
      opt.type = 'button';
      opt.className = 'color-combo-option';
      opt.dataset.colorId = String(c.id);
      var optName = document.createElement('span');
      optName.className = 'color-combo-option-name';
      optName.textContent = c.name;
      var optSwatch = document.createElement('span');
      optSwatch.className = 'color-combo-option-swatch';
      optSwatch.style.backgroundColor = colorSwatchStyle(c.rgb);
      opt.appendChild(optName);
      opt.appendChild(optSwatch);
      opt.addEventListener('click', function() {
        setColorValue(c);
        closeCombo();
      });
      return opt;
    }

    knownColors.forEach(function(c) {
      comboPanel.appendChild(buildComboOption(c));
    });
    if (otherColors.length > 0) {
      var groupLabel = document.createElement('div');
      groupLabel.className = 'color-combo-group-label';
      groupLabel.textContent = texts.colorOtherGroupLabel;
      comboPanel.appendChild(groupLabel);
      otherColors.forEach(function(c) {
        comboPanel.appendChild(buildComboOption(c));
      });
    }

    comboToggle.addEventListener('click', function() {
      comboPanel.style.display = comboPanel.style.display === 'none' ? 'block' : 'none';
    });
    document.addEventListener('click', function(e) {
      if (!combo.contains(e.target)) {
        closeCombo();
      }
    });

    combo.appendChild(comboToggle);
    combo.appendChild(comboPanel);
    colorLabel.appendChild(combo);
    colorLabel.appendChild(colorHiddenInput);
    form.appendChild(colorLabel);

    if (knownColors.length > 0) {
      var swatchSection = document.createElement('div');
      swatchSection.className = 'color-swatch-section';
      var swatchTitle = document.createElement('p');
      swatchTitle.className = 'color-swatch-title';
      swatchTitle.textContent = texts.knownColorsTitle;
      swatchGrid = document.createElement('div');
      swatchGrid.className = 'color-swatch-grid';
      knownColors.forEach(function(c) {
        var swatch = document.createElement('button');
        swatch.type = 'button';
        swatch.className = 'color-swatch-btn';
        swatch.title = c.name;
        swatch.style.backgroundColor = colorSwatchStyle(c.rgb);
        swatch.dataset.colorId = String(c.id);
        swatch.addEventListener('click', function() {
          setColorValue(c);
        });
        swatchGrid.appendChild(swatch);
      });
      swatchSection.appendChild(swatchTitle);
      swatchSection.appendChild(swatchGrid);
      form.appendChild(swatchSection);
    }

    var initialColor = allPickerColors[0];
    if (context.colorId) {
      var matchingColor = allPickerColors.filter(function(c) { return String(c.id) === String(context.colorId); })[0];
      if (matchingColor) {
        initialColor = matchingColor;
      }
    }
    if (initialColor) {
      setColorValue(initialColor);
    }

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
    if (context.conditionType) {
      condSelect.value = context.conditionType;
    }
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
    // Named (not a one-shot inline call) so the quick-pick row below can
    // re-invoke it with a different initialLocationId — createLocationPicker()
    // has no separate "jump to location X" setter, re-creating it into the
    // same (cleared) container is the documented way to change its starting
    // point after construction.
    function renderLocationPicker(initialLocationId) {
      locationContainer.innerHTML = '';
      window.createLocationPicker(locationContainer, texts, function(value) {
        selectedLocationId = value;
      }, initialLocationId || undefined);
    }
    renderLocationPicker(context.locationId || lastAddLocationId);

    var submitBtn = document.createElement('button');
    submitBtn.type = 'submit';
    submitBtn.textContent = texts.addButton;
    form.appendChild(submitBtn);

    // Quick-pick row, below the Hinzufügen button per explicit request:
    // every location that already holds this part (any color/condition —
    // deliberately color-agnostic), short-labeled server-side
    // (getShortLocationLabel(), src/storage.php). Clicking one sets
    // selectedLocationId directly AND re-points the cascading picker above
    // at it, so the breadcrumb visibly confirms the choice instead of
    // silently disagreeing with what's about to submit.
    var knownStockLocations = data.knownStockLocations || [];
    if (knownStockLocations.length > 0) {
      var quickLocations = document.createElement('div');
      quickLocations.className = 'part-modal-quick-locations';
      knownStockLocations.forEach(function(loc) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'part-modal-quick-location-btn';
        btn.textContent = loc.label;
        btn.title = loc.fullPath;
        btn.addEventListener('click', function() {
          selectedLocationId = String(loc.locationId);
          renderLocationPicker(loc.locationId);
        });
        quickLocations.appendChild(btn);
      });
      form.appendChild(quickLocations);
    }

    form.addEventListener('submit', function(e) {
      e.preventDefault();
      msgBox.textContent = '';
      msgBox.className = 'add-stock-message';
      submitBtn.disabled = true;

      var formData = new FormData();
      formData.set('action', 'add_stock');
      formData.set('part_id', part.id);
      formData.set('color_id', colorHiddenInput.value);
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

    panel.appendChild(form);
  }

  // ---- "Informationen" --------------------------------------------------

  function buildInfoPanel(panel, part, data) {
    panel.innerHTML = '';

    var headerRow = document.createElement('div');
    headerRow.className = 'part-modal-header';

    var img = document.createElement('div');
    img.className = 'part-modal-image';
    img.innerHTML = part.thumbnail ? '<img src="' + part.thumbnail + '" alt="">' : (content.dataset.fallbackIcon || '');
    headerRow.appendChild(img);

    var info = document.createElement('div');
    info.className = 'part-modal-info';
    headerRow.appendChild(info);
    panel.appendChild(headerRow);

    var title = document.createElement('h2');
    title.textContent = part.translated_name || part.name;
    info.appendChild(title);

    var originalName = null;
    if (part.translated_name) {
      originalName = document.createElement('p');
      originalName.className = 'part-modal-original-name';
      originalName.textContent = texts.translationOriginalLabel + ': ' + part.name;
      info.appendChild(originalName);
    }

    var meta = document.createElement('p');
    meta.className = 'part-modal-meta';
    meta.textContent = part.part_num + (part.category_name ? ' \\u00b7 ' + part.category_name : '');
    info.appendChild(meta);

    if (part.translation_locale !== 'en') {
      var translationSection = document.createElement('div');
      translationSection.className = 'part-modal-translation';

      var translationToggle = document.createElement('a');
      translationToggle.href = '#';
      translationToggle.textContent = part.translated_name ? texts.translationEditButton : texts.translationAddButton;

      var translationForm = document.createElement('form');
      translationForm.className = 'part-modal-translation-form';
      translationForm.style.display = 'none';

      var translationInput = document.createElement('input');
      translationInput.type = 'text';
      translationInput.value = part.translated_name || '';
      translationInput.placeholder = texts.translationPlaceholder;

      var translationSaveBtn = document.createElement('button');
      translationSaveBtn.type = 'submit';
      translationSaveBtn.textContent = texts.translationSaveButton;

      var translationCancelBtn = document.createElement('button');
      translationCancelBtn.type = 'button';
      translationCancelBtn.textContent = texts.translationCancelButton;

      var translationMsg = document.createElement('span');
      translationMsg.className = 'part-modal-translation-message';

      translationForm.appendChild(translationInput);
      translationForm.appendChild(translationSaveBtn);
      translationForm.appendChild(translationCancelBtn);
      translationForm.appendChild(translationMsg);

      translationToggle.addEventListener('click', function(e) {
        e.preventDefault();
        translationToggle.style.display = 'none';
        translationForm.style.display = 'flex';
        translationInput.focus();
      });
      translationCancelBtn.addEventListener('click', function() {
        translationForm.style.display = 'none';
        translationToggle.style.display = 'inline-block';
      });
      translationForm.addEventListener('submit', function(e) {
        e.preventDefault();
        translationMsg.textContent = '';
        var formData = new FormData();
        formData.set('action', 'save_part_translation');
        formData.set('part_id', part.id);
        formData.set('name', translationInput.value);
        fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            if (res.success) {
              title.textContent = res.name;
              part.translated_name = res.name;
              translationToggle.textContent = texts.translationEditButton;
              translationForm.style.display = 'none';
              translationToggle.style.display = 'inline-block';
              if (!originalName) {
                originalName = document.createElement('p');
                originalName.className = 'part-modal-original-name';
                originalName.textContent = texts.translationOriginalLabel + ': ' + part.name;
                info.insertBefore(originalName, meta);
              }
            } else {
              translationMsg.textContent = res.message;
            }
          })
          .catch(function() {
            translationMsg.textContent = texts.errorRetry;
          });
      });

      translationSection.appendChild(translationToggle);
      translationSection.appendChild(translationForm);
      info.appendChild(translationSection);
    }

    // BrickLink price — only when a specific color is in context; a
    // catalog-level part has no single price otherwise (price is per
    // part+color, see part_bricklink_prices).
    if (openContext.colorId) {
      var priceLine = document.createElement('p');
      priceLine.className = 'bricklink-price-line';
      var priceTextEl = document.createElement('span');
      var newText = part.bricklinkPriceNewText || texts.bricklinkPriceNeverLabel;
      var usedText = part.bricklinkPriceUsedText || texts.bricklinkPriceNeverLabel;
      priceTextEl.textContent = texts.bricklinkPriceLine.replace('{newText}', newText).replace('{usedText}', usedText);
      priceLine.title = part.bricklinkPriceTitle || '';
      priceLine.appendChild(priceTextEl);

      var priceRefreshBtn = document.createElement('button');
      priceRefreshBtn.type = 'button';
      priceRefreshBtn.className = 'owned-set-bricklink-refresh-btn';
      priceRefreshBtn.setAttribute('aria-label', texts.bricklinkRefreshLabel);
      priceRefreshBtn.title = texts.bricklinkRefreshLabel;
      priceRefreshBtn.innerHTML = texts.refreshIcon;
      priceRefreshBtn.addEventListener('click', function() {
        priceRefreshBtn.disabled = true;
        priceRefreshBtn.classList.add('owned-set-bricklink-refresh-spinning');
        var priceFormData = new FormData();
        priceFormData.set('action', 'refresh_part_bricklink_price');
        priceFormData.set('part_id', part.id);
        priceFormData.set('color_id', openContext.colorId);
        fetch('?', { method: 'POST', body: priceFormData, credentials: 'same-origin' })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            priceRefreshBtn.disabled = false;
            priceRefreshBtn.classList.remove('owned-set-bricklink-refresh-spinning');
            if (res.success) {
              part.bricklinkPriceNewText = res.newPriceText;
              part.bricklinkPriceUsedText = res.usedPriceText;
              part.bricklinkPriceTitle = res.priceTitle;
              priceTextEl.textContent = texts.bricklinkPriceLine.replace('{newText}', res.newPriceText).replace('{usedText}', res.usedPriceText);
              priceLine.title = res.priceTitle || '';
            } else {
              window.alert(texts.bricklinkRefreshFailed + ' ' + (res.message || ''));
            }
          })
          .catch(function() {
            priceRefreshBtn.disabled = false;
            priceRefreshBtn.classList.remove('owned-set-bricklink-refresh-spinning');
            window.alert(texts.bricklinkRefreshFailed);
          });
      });
      priceLine.appendChild(priceRefreshBtn);
      panel.appendChild(priceLine);
    }

    // BrickLink id / BrickOwl id — editable, same toggle-to-edit pattern as
    // the translation field above.
    [
      { key: 'bricklink_part_id', label: texts.bricklinkIdLabel, field: 'bricklink_part_id' },
      { key: 'brickowl_id', label: texts.brickowlIdLabel, field: 'brickowl_id' }
    ].forEach(function(idField) {
      var row = document.createElement('div');
      row.className = 'part-modal-id-row';

      // Initial href comes from the server (action=part_detail already
      // built the real-catalog-link-if-known-else-search-link URL). After a
      // manual id edit there's no fresh payload without another round trip,
      // so this rebuilds the same URL pattern client-side just for that
      // case.
      function externalUrl() {
        var id = part[idField.key];
        if (idField.field === 'bricklink_part_id') {
          return id
            ? 'https://www.bricklink.com/v2/catalog/catalogitem.page?P=' + encodeURIComponent(id)
            : 'https://www.bricklink.com/v2/search.page?q=' + encodeURIComponent(part.part_num);
        }
        return id
          ? 'https://www.brickowl.com/catalog/' + encodeURIComponent(id)
          : 'https://www.brickowl.com/search/catalog?query=' + encodeURIComponent(part.part_num);
      }

      var idText = document.createElement('a');
      idText.target = '_blank';
      idText.rel = 'noopener';
      idText.href = idField.field === 'bricklink_part_id' ? part.bricklink_url : part.brickowl_url;
      idText.textContent = idField.label + ': ' + (part[idField.key] || '\\u2013');
      row.appendChild(idText);

      var editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'part-modal-id-edit-btn';
      editBtn.innerHTML = texts.editIcon;
      row.appendChild(editBtn);

      var idForm = document.createElement('form');
      idForm.className = 'part-modal-id-form';
      idForm.style.display = 'none';
      var idInput = document.createElement('input');
      idInput.type = 'text';
      idInput.value = part[idField.key] || '';
      idForm.appendChild(idInput);
      var idSaveBtn = document.createElement('button');
      idSaveBtn.type = 'submit';
      idSaveBtn.textContent = texts.externalIdsSaveButton;
      idForm.appendChild(idSaveBtn);
      var idMsg = document.createElement('span');
      idForm.appendChild(idMsg);
      row.appendChild(idForm);

      editBtn.addEventListener('click', function() {
        idText.style.display = 'none';
        editBtn.style.display = 'none';
        idForm.style.display = 'flex';
        idInput.focus();
      });

      idForm.addEventListener('submit', function(e) {
        e.preventDefault();
        idMsg.textContent = '';
        var idFormData = new FormData();
        idFormData.set('action', 'update_part_external_ids');
        idFormData.set('part_id', part.id);
        idFormData.set('bricklink_part_id', idField.field === 'bricklink_part_id' ? idInput.value : (part.bricklink_part_id || ''));
        idFormData.set('brickowl_id', idField.field === 'brickowl_id' ? idInput.value : (part.brickowl_id || ''));
        fetch('?', { method: 'POST', body: idFormData, credentials: 'same-origin' })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            if (res.success) {
              part.bricklink_part_id = res.bricklinkPartId;
              part.brickowl_id = res.brickowlId;
              idText.href = externalUrl();
              idText.textContent = idField.label + ': ' + (part[idField.key] || '\\u2013');
              idText.style.display = '';
              editBtn.style.display = '';
              idForm.style.display = 'none';
            } else {
              idMsg.textContent = texts.externalIdsSaveFailed + ' ' + (res.message || '');
            }
          })
          .catch(function() {
            idMsg.textContent = texts.errorRetry;
          });
      });

      panel.appendChild(row);
    });

    // Weight — same toggle-to-edit pattern as the ids above, but a single
    // numeric field (grams) rather than a text id, and the display text
    // distinguishes an own value from one inherited from the print family's
    // root part (part.weight_source, set by action=part_detail).
    (function() {
      var row = document.createElement('div');
      row.className = 'part-modal-id-row';

      function weightDisplayText() {
        if (part.weight_display === null || part.weight_display === undefined) {
          return texts.weightLabel + ': ' + texts.weightUnknown;
        }
        if (part.weight_source === 'print_parent') {
          return texts.weightLabel + ': ' + texts.weightInheritedHint.replace('{weight}', part.weight_display);
        }
        return texts.weightLabel + ': ' + part.weight_display;
      }

      var weightText = document.createElement('span');
      weightText.textContent = weightDisplayText();
      row.appendChild(weightText);

      var editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'part-modal-id-edit-btn';
      editBtn.innerHTML = texts.editIcon;
      row.appendChild(editBtn);

      var weightForm = document.createElement('form');
      weightForm.className = 'part-modal-id-form';
      weightForm.style.display = 'none';
      var weightInput = document.createElement('input');
      weightInput.type = 'number';
      weightInput.step = '0.001';
      weightInput.min = '0';
      weightInput.value = part.weight_grams !== null && part.weight_grams !== undefined ? part.weight_grams : '';
      weightForm.appendChild(weightInput);
      var weightSaveBtn = document.createElement('button');
      weightSaveBtn.type = 'submit';
      weightSaveBtn.textContent = texts.externalIdsSaveButton;
      weightForm.appendChild(weightSaveBtn);
      var weightMsg = document.createElement('span');
      weightForm.appendChild(weightMsg);
      row.appendChild(weightForm);

      editBtn.addEventListener('click', function() {
        weightText.style.display = 'none';
        editBtn.style.display = 'none';
        weightForm.style.display = 'flex';
        weightInput.focus();
      });

      weightForm.addEventListener('submit', function(e) {
        e.preventDefault();
        weightMsg.textContent = '';
        var weightFormData = new FormData();
        weightFormData.set('action', 'update_part_weight');
        weightFormData.set('part_id', part.id);
        weightFormData.set('weight_grams', weightInput.value);
        fetch('?', { method: 'POST', body: weightFormData, credentials: 'same-origin' })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            if (res.success) {
              part.weight_grams = res.weightGrams;
              part.effective_weight_grams = res.weightGrams;
              part.weight_source = res.weightGrams !== null ? 'own' : null;
              part.weight_display = res.weightGrams !== null ? res.weightDisplay : null;
              weightText.textContent = weightDisplayText();
              weightText.style.display = '';
              editBtn.style.display = '';
              weightForm.style.display = 'none';
            } else {
              weightMsg.textContent = texts.weightSaveFailed + ' ' + (res.message || '');
            }
          })
          .catch(function() {
            weightMsg.textContent = texts.errorRetry;
          });
      });

      panel.appendChild(row);
    })();

    // "Erscheint in N Sets" — grouped by theme once expanded.
    var sets = document.createElement('p');
    sets.className = 'part-modal-sets';
    var setsText = texts.appearsInSets
      .replace('{total}', part.total_appearances)
      .replace('{count}', part.sets_count)
      .replace('{minYear}', part.min_year)
      .replace('{maxYear}', part.max_year);
    if (part.sets_count > 0) {
      var setsLink = document.createElement('a');
      setsLink.href = '#';
      setsLink.textContent = setsText;
      setsLink.addEventListener('click', function(e) {
        e.preventDefault();
        openPartSetsList(panel, part, data);
      });
      sets.appendChild(setsLink);
    } else {
      sets.textContent = texts.appearsInNoSets;
    }
    panel.appendChild(sets);

    if (part.ldraw_id) {
      var ldrawLine = document.createElement('p');
      ldrawLine.className = 'hint';
      ldrawLine.textContent = texts.ldrawIdLabel + ': ' + part.ldraw_id;
      panel.appendChild(ldrawLine);
    }

    if (part.part_url) {
      var rebrickableLine = document.createElement('p');
      var rebrickableLink = document.createElement('a');
      rebrickableLink.href = part.part_url;
      rebrickableLink.target = '_blank';
      rebrickableLink.rel = 'noopener';
      rebrickableLink.textContent = texts.rebrickableLinkLabel;
      rebrickableLine.appendChild(rebrickableLink);
      panel.appendChild(rebrickableLine);
    }

    if (data.printParent) {
      var printOf = document.createElement('p');
      printOf.className = 'part-modal-print-of';
      var printLink = document.createElement('a');
      printLink.href = '#';
      printLink.textContent = texts.printOfLabel + ': ' + data.printParent.name + ' (' + data.printParent.part_num + ')';
      printLink.addEventListener('click', function(e) {
        e.preventDefault();
        openPartModal(data.printParent.id, null, null, null);
      });
      printOf.appendChild(printLink);
      panel.appendChild(printOf);
    }
  }

  function openPartSetsList(panel, part, data) {
    panel.innerHTML = '';

    var backLink = document.createElement('a');
    backLink.href = '#';
    backLink.className = 'part-sets-back';
    backLink.textContent = texts.backToPart;
    backLink.addEventListener('click', function(e) {
      e.preventDefault();
      buildInfoPanel(panel, part, data);
    });
    panel.appendChild(backLink);

    var title = document.createElement('h3');
    title.textContent = texts.partSetsTitle;
    panel.appendChild(title);

    var list = document.createElement('div');
    list.className = 'part-sets-list';
    panel.appendChild(list);

    fetch('?action=part_sets&part_id=' + encodeURIComponent(part.id), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data2) {
        var lastTheme;
        var first = true;
        (data2.sets || []).forEach(function(set) {
          if (first || set.theme !== lastTheme) {
            var heading = document.createElement('div');
            heading.className = 'part-sets-theme-heading';
            heading.textContent = set.theme || texts.setsNoThemeLabel;
            list.appendChild(heading);
            lastTheme = set.theme;
            first = false;
          }

          var row = document.createElement('div');
          row.className = 'part-sets-row';

          var thumb = document.createElement('span');
          thumb.className = 'part-sets-thumb';
          thumb.innerHTML = set.thumbnail
            ? '<img src="' + set.thumbnail + '" alt="">'
            : content.dataset.fallbackIcon || '';
          row.appendChild(thumb);

          var setInfo = document.createElement('span');
          setInfo.className = 'part-sets-info';
          var setName = document.createElement('span');
          setName.className = 'part-sets-name';
          setName.textContent = (set.name || set.set_num) + (set.year ? ' (' + set.year + ')' : '');
          var setMeta = document.createElement('span');
          setMeta.className = 'part-sets-meta';
          setMeta.textContent = set.set_num + ' \\u00b7 ' + set.quantity + 'x';
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
    var card = e.target.closest('.part-card');
    if (card) {
      // data-color-id (if present) is Rebrickable's own numbering, used
      // exclusively by the "fetch missing images" bulk scanner
      // (src/part_images.php) — NOT colors.id, the surrogate PK this modal
      // (and storage_items/part_bricklink_prices) needs. Reusing it here
      // would silently look up the wrong color, so this reads distinctly-
      // named attributes instead, only ever set by callers that genuinely
      // have colors.id/location/condition context (currently just the
      // location Explorer, which calls window.openPartModal() directly
      // rather than relying on this generic delegate at all).
      openPartModal(card.dataset.partId, card.dataset.modalColorId || null, card.dataset.locationId || null, card.dataset.conditionType || null);
    }
  });
  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter' && e.key !== ' ') {
      return;
    }
    var card = e.target.closest('.part-card');
    if (card) {
      e.preventDefault();
      openPartModal(card.dataset.partId, card.dataset.modalColorId || null, card.dataset.locationId || null, card.dataset.conditionType || null);
    }
  });
})();
</script>
SCRIPT;

    return $main;
}
