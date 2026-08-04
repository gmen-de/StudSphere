<?php

declare(strict_types=1);

require_once __DIR__ . '/i18n.php';

/**
 * The part-detail overlay (image, links, sets/appearances, translation
 * add/edit, and its own "Zum Lager hinzufuegen / Lager / Einkauf" tabs) is
 * opened by clicking any ".part-card[data-part-id]" element anywhere on the
 * page -- click/keydown delegation is on document, not a specific grid, so
 * this same markup+script works unchanged on bricks_search and on any other
 * page that renders part cards (e.g. a set's inventory tab).
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
            'bricklinkLink' => t('bricklink_link'),
            'brickowlLink' => t('brickowl_link'),
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
            'tabStorage' => t('part_tab_storage'),
            'tabCombinedStorage' => t('part_tab_combined_storage'),
            'tabPurchase' => t('part_tab_purchase'),
            'stockEmpty' => t('part_stock_empty'),
            'stockLocationLabel' => t('part_stock_location_label'),
            'stockColorLabel' => t('part_stock_color_label'),
            'stockConditionLabel' => t('part_stock_condition_label'),
            'stockQuantityLabel' => t('part_stock_quantity_label'),
            'stockInSetLabel' => t('part_stock_in_set_label'),
            'backToSummary' => t('part_stock_back_to_summary'),
            'purchasePlaceholder' => t('part_purchase_placeholder'),
            'locationOpenNewTab' => t('location_detail_open_new_tab'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $main .= <<<SCRIPT
<script>
(function(){
  var texts = $modalLabelsJson;
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

  function openPartModal(partId) {
    content.innerHTML = '';
    modal.style.display = 'flex';

    fetch('?action=part_detail&part_id=' + encodeURIComponent(partId), { credentials: 'same-origin' })
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

  function openPartSetsList(part) {
    content.innerHTML = '';

    var backLink = document.createElement('a');
    backLink.href = '#';
    backLink.className = 'part-sets-back';
    backLink.textContent = texts.backToPart;
    backLink.addEventListener('click', function(e) {
      e.preventDefault();
      openPartModal(part.id);
    });
    content.appendChild(backLink);

    var title = document.createElement('h3');
    title.textContent = texts.partSetsTitle;
    content.appendChild(title);

    var list = document.createElement('div');
    list.className = 'part-sets-list';
    content.appendChild(list);

    fetch('?action=part_sets&part_id=' + encodeURIComponent(part.id), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        (data.sets || []).forEach(function(set) {
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

  function renderPartModal(data) {
    var part = data.part;
    content.innerHTML = '';

    var header = document.createElement('div');
    header.className = 'part-modal-header';

    var img = document.createElement('div');
    img.className = 'part-modal-image';
    img.innerHTML = part.thumbnail
      ? '<img src="' + part.thumbnail + '" alt="">'
      : content.dataset.fallbackIcon || '';
    header.appendChild(img);

    var info = document.createElement('div');
    info.className = 'part-modal-info';
    var title = document.createElement('h2');
    title.textContent = part.translated_name || part.name;

    var originalName = null;
    if (part.translated_name) {
      originalName = document.createElement('p');
      originalName.className = 'part-modal-original-name';
      originalName.textContent = texts.translationOriginalLabel + ': ' + part.name;
    }

    var meta = document.createElement('p');
    meta.className = 'part-modal-meta';
    meta.textContent = part.part_num + (part.category_name ? ' · ' + part.category_name : '');

    var links = document.createElement('p');
    links.className = 'part-modal-links';
    var blLink = document.createElement('a');
    blLink.href = part.bricklink_url;
    blLink.target = '_blank';
    blLink.rel = 'noopener';
    blLink.textContent = texts.bricklinkLink;
    var boLink = document.createElement('a');
    boLink.href = part.brickowl_url;
    boLink.target = '_blank';
    boLink.rel = 'noopener';
    boLink.textContent = texts.brickowlLink;
    links.appendChild(blLink);
    links.appendChild(document.createTextNode(' · '));
    links.appendChild(boLink);

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
        openPartSetsList(part);
      });
      sets.appendChild(setsLink);
    } else {
      sets.textContent = texts.appearsInNoSets;
    }

    info.appendChild(title);
    if (originalName) {
      info.appendChild(originalName);
    }
    info.appendChild(meta);
    info.appendChild(links);
    info.appendChild(sets);

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

    if (data.printParent) {
      var printOf = document.createElement('p');
      printOf.className = 'part-modal-print-of';
      var printLink = document.createElement('a');
      printLink.href = '#';
      printLink.textContent = texts.printOfLabel + ': ' + data.printParent.name + ' (' + data.printParent.part_num + ')';
      printLink.addEventListener('click', function(e) {
        e.preventDefault();
        openPartModal(data.printParent.id);
      });
      printOf.appendChild(printLink);
      info.appendChild(printOf);
    }

    header.appendChild(info);
    content.appendChild(header);

    var tabBar = document.createElement('div');
    tabBar.className = 'part-modal-tabs';
    var tabPanels = document.createElement('div');
    tabPanels.className = 'part-modal-tab-panels';

    var panelAddStock = document.createElement('div');
    panelAddStock.className = 'part-modal-tab-panel';
    var panelStorage = document.createElement('div');
    panelStorage.className = 'part-modal-tab-panel';
    var panelCombinedStorage = document.createElement('div');
    panelCombinedStorage.className = 'part-modal-tab-panel';
    var panelPurchase = document.createElement('div');
    panelPurchase.className = 'part-modal-tab-panel';

    var tabs = [
      { label: texts.addToInventoryTitle, panel: panelAddStock },
      { label: texts.tabStorage, panel: panelStorage },
      { label: texts.tabCombinedStorage, panel: panelCombinedStorage },
      { label: texts.tabPurchase, panel: panelPurchase }
    ];
    var tabButtons = [];
    var stockLoadedForPart = null;
    var combinedStockLoadedForPart = null;

    function buildStockCard(row, onActivate) {
      var card = document.createElement('div');
      card.className = 'part-stock-card';
      card.tabIndex = 0;
      card.setAttribute('role', onActivate.isLink ? 'link' : 'button');
      card.addEventListener('click', onActivate);
      card.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          onActivate();
        }
      });

      var swatch = document.createElement('span');
      swatch.className = 'part-stock-card-swatch';
      swatch.style.backgroundColor = row.color_rgb ? '#' + row.color_rgb.replace('#', '') : '#cccccc';
      card.appendChild(swatch);

      var qty = document.createElement('span');
      qty.className = 'part-stock-card-qty';
      qty.textContent = row.quantity + 'x';
      card.appendChild(qty);

      return card;
    }

    function loadPartStockSummary() {
      panelCombinedStorage.innerHTML = '';
      var grid = document.createElement('div');
      grid.className = 'part-stock-grid';
      panelCombinedStorage.appendChild(grid);
      fetch('?action=part_stock_summary&part_id=' + encodeURIComponent(part.id), { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          var rows = data.summary || [];
          if (rows.length === 0) {
            grid.textContent = texts.stockEmpty;
            return;
          }
          rows.forEach(function(row) {
            var card = buildStockCard(row, function() {
              openPartStockDetail(row);
            });

            var meta = document.createElement('span');
            meta.className = 'part-stock-card-meta';
            meta.textContent = row.color_name || '';
            card.appendChild(meta);

            grid.appendChild(card);
          });
        })
        .catch(function() {
          grid.textContent = texts.errorRetry;
        });
    }

    function openPartStockDetail(colorRow) {
      panelCombinedStorage.innerHTML = '';

      var backLink = document.createElement('a');
      backLink.href = '#';
      backLink.className = 'part-sets-back';
      backLink.textContent = texts.backToSummary;
      backLink.addEventListener('click', function(e) {
        e.preventDefault();
        loadPartStockSummary();
      });
      panelCombinedStorage.appendChild(backLink);

      var grid = document.createElement('div');
      grid.className = 'part-stock-grid';
      panelCombinedStorage.appendChild(grid);

      var params = new URLSearchParams();
      params.set('action', 'part_stock_detail');
      params.set('part_id', part.id);
      if (colorRow.color_id !== null && colorRow.color_id !== undefined) {
        params.set('color_id', colorRow.color_id);
      }
      fetch('?' + params.toString(), { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          var rows = data.detail || [];
          if (rows.length === 0) {
            grid.textContent = texts.stockEmpty;
            return;
          }
          rows.forEach(function(row) {
            var isSet = row.owned_set_id !== null && row.owned_set_id !== undefined;
            var url = isSet
              ? '?page=owned_set_detail&id=' + encodeURIComponent(row.owned_set_id)
              : '?page=location_detail&id=' + encodeURIComponent(row.location_id);

            var activate = function() { window.location.href = url; };
            activate.isLink = true;
            var card = buildStockCard(row, activate);

            var newTabLink = document.createElement('a');
            newTabLink.className = 'part-stock-card-newtab';
            newTabLink.href = url;
            newTabLink.target = '_blank';
            newTabLink.rel = 'noopener';
            newTabLink.title = texts.locationOpenNewTab;
            newTabLink.setAttribute('aria-label', texts.locationOpenNewTab);
            newTabLink.addEventListener('click', function(e) {
              e.stopPropagation();
            });
            newTabLink.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14L21 3"/></svg>';
            card.appendChild(newTabLink);

            var meta = document.createElement('span');
            meta.className = 'part-stock-card-meta';
            var condText = row.condition_type === 'new' ? texts.conditionNew : texts.conditionUsed;
            var placeText = isSet
              ? texts.stockInSetLabel + ': ' + row.set_name + ' (' + row.set_num + ')'
              : row.location_path;
            meta.textContent = condText + ' · ' + placeText;
            card.appendChild(meta);

            grid.appendChild(card);
          });
        })
        .catch(function() {
          grid.textContent = texts.errorRetry;
        });
    }

    function loadPartStock() {
      panelStorage.innerHTML = '';
      var grid = document.createElement('div');
      grid.className = 'part-stock-grid';
      panelStorage.appendChild(grid);
      fetch('?action=part_stock&part_id=' + encodeURIComponent(part.id), { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          var rows = data.stock || [];
          if (rows.length === 0) {
            grid.textContent = texts.stockEmpty;
            return;
          }
          rows.forEach(function(row) {
            var url = '?page=location_detail&id=' + encodeURIComponent(row.location_id);

            var activate = function() { window.location.href = url; };
            activate.isLink = true;
            var card = buildStockCard(row, activate);

            var newTabLink = document.createElement('a');
            newTabLink.className = 'part-stock-card-newtab';
            newTabLink.href = url;
            newTabLink.target = '_blank';
            newTabLink.rel = 'noopener';
            newTabLink.title = texts.locationOpenNewTab;
            newTabLink.setAttribute('aria-label', texts.locationOpenNewTab);
            newTabLink.addEventListener('click', function(e) {
              e.stopPropagation();
            });
            newTabLink.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14L21 3"/></svg>';
            card.appendChild(newTabLink);

            var meta = document.createElement('span');
            meta.className = 'part-stock-card-meta';
            var condText = row.condition_type === 'new' ? texts.conditionNew : texts.conditionUsed;
            meta.textContent = (row.color_name || '') + ' · ' + condText + ' · ' + row.location_path;
            card.appendChild(meta);

            grid.appendChild(card);
          });
        })
        .catch(function() {
          grid.textContent = texts.errorRetry;
        });
    }

    function activateTab(index) {
      tabs.forEach(function(tab, i) {
        tab.panel.classList.toggle('active', i === index);
        tabButtons[i].classList.toggle('active', i === index);
      });
      if (tabs[index].panel === panelStorage && stockLoadedForPart !== part.id) {
        stockLoadedForPart = part.id;
        loadPartStock();
      }
      if (tabs[index].panel === panelCombinedStorage && combinedStockLoadedForPart !== part.id) {
        combinedStockLoadedForPart = part.id;
        loadPartStockSummary();
      }
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

    var purchaseText = document.createElement('p');
    purchaseText.className = 'part-purchase-placeholder';
    purchaseText.textContent = texts.purchasePlaceholder;
    panelPurchase.appendChild(purchaseText);

    activateTab(0);
    content.appendChild(tabBar);
    content.appendChild(tabPanels);

    var msgBox = document.createElement('div');
    msgBox.className = 'add-stock-message';
    panelAddStock.appendChild(msgBox);

    var form = document.createElement('form');
    form.className = 'add-stock-form';

    var knownColors = data.knownColors || [];
    var otherColors = data.otherColors || [];
    var allPickerColors = knownColors.concat(otherColors);

    function colorSwatchStyle(c) {
      return c.rgb ? '#' + c.rgb.replace('#', '') : '#cccccc';
    }

    // Native <select>/<option> elements can't reliably show a swatch per row
    // (no HTML inside an <option> — support for even a CSS background on one
    // is inconsistent across browsers, several ignore it outright). A small
    // custom combobox gives full control over each row's layout instead.
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
      comboToggleSwatch.style.backgroundColor = colorSwatchStyle(c);
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
      optSwatch.style.backgroundColor = colorSwatchStyle(c);
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

    var swatchGrid = null;
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
        swatch.style.backgroundColor = colorSwatchStyle(c);
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

    if (allPickerColors.length > 0) {
      setColorValue(allPickerColors[0]);
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
    condLabel.appendChild(condSelect);
    form.appendChild(condLabel);

    var locationContainer = document.createElement('div');
    locationContainer.className = 'location-picker';
    form.appendChild(locationContainer);
    var selectedLocationId = null;
    window.createLocationPicker(locationContainer, texts, function(value) {
      selectedLocationId = value;
    });

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
            stockLoadedForPart = null;
          }
        })
        .catch(function() {
          submitBtn.disabled = false;
          msgBox.textContent = texts.errorRetry;
          msgBox.className = 'add-stock-message error';
        });
    });

    panelAddStock.appendChild(form);
  }

  document.addEventListener('click', function(e) {
    var card = e.target.closest('.part-card');
    if (card) {
      openPartModal(card.dataset.partId);
    }
  });
  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter' && e.key !== ' ') {
      return;
    }
    var card = e.target.closest('.part-card');
    if (card) {
      e.preventDefault();
      openPartModal(card.dataset.partId);
    }
  });
})();
</script>
SCRIPT;

    return $main;
}
