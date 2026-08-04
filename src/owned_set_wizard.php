<?php

declare(strict_types=1);

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/sets.php';

/**
 * The "Set zur Sammlung hinzufügen" assistant: version (if the set has more
 * than one Rebrickable inventory revision — see getSetInventoryVersions()
 * in src/sets.php), location, condition/box details, general notes,
 * inventory prompt, and — on "Ja" — an inline inventory step covering all
 * four trackable categories in sequence (Bauteile, Ersatzteile,
 * Stickerbögen, Minifiguren; any category the set doesn't have is skipped
 * automatically) right in the same modal instead of redirecting first.
 * Self-contained (own markup + own <script>, reuses the generic
 * .modal-overlay/.modal-box shell already used by renderPartDetailModal())
 * so the caller just embeds the returned HTML — same pattern as
 * renderLdrawRenderOverlay() in src/ldraw.php.
 *
 * Step numbers are computed once in PHP ($stepNames) since whether the
 * version step exists at all depends on the set (single-revision sets skip
 * straight to location) — baked into the emitted markup's data-step/
 * data-next/data-back attributes and a few JS constants, so the script
 * itself never has to special-case "does this set have versions".
 *
 * Nothing is persisted until the inventory question is answered (Ja or
 * Nein): both answers trigger the same add_owned_set AJAX call, "Nein"
 * then redirects immediately, "Ja" stays in the modal for the inventory
 * step. Closing the modal before that point discards everything
 * client-side — no draft state on the server.
 */
function renderAddOwnedSetWizardModal(PDO $pdo, int $setId): string
{
    $set = getSetById($pdo, $setId);
    $versions = $set !== null ? getSetInventoryVersions($pdo, $set['rebrickable_set_num']) : [];
    $hasVersionStep = count($versions) > 1;

    $stepNames = $hasVersionStep
        ? ['version' => 1, 'location' => 2, 'details' => 3, 'notes' => 4, 'question' => 5, 'inventory' => 6, 'overview' => 7]
        : ['location' => 1, 'details' => 2, 'notes' => 3, 'question' => 4, 'inventory' => 5, 'overview' => 6];

    // Each step's content lives in .owned-set-wizard-body (scrollable), its
    // nav buttons (+ error line) live in the always-visible
    // .owned-set-wizard-footer instead — kept as a separate string and
    // spliced in after the body closes below. Splitting header/content/
    // footer like this is what actually makes the buttons land in the same
    // place on every step; putting the nav row inside each step (as before)
    // meant its position depended on that step's own content height, which
    // in practice never lined up consistently between an empty question
    // step and a full tile grid, no matter how the CSS tried to compensate.
    $footerHtml = '<div class="owned-set-wizard-footer">';

    $html = '<div class="modal-overlay" id="add-owned-set-modal" style="display:none;">';
    $html .= '<div class="modal-box owned-set-wizard-box">';
    $html .= '<div class="owned-set-wizard-header">';
    $html .= '<h2>' . htmlspecialchars(t('owned_set_wizard_title', ['setNum' => $set['rebrickable_set_num'] ?? ''])) . '</h2>';
    $html .= '<button type="button" class="modal-close" id="add-owned-set-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $html .= '</div>';
    $html .= '<p class="owned-set-wizard-progress"><span id="owned-set-wizard-progress"></span><span class="owned-set-wizard-progress-sub" id="owned-set-wizard-inventory-progress"></span></p>';
    $html .= '<div class="owned-set-wizard-body">';

    if ($hasVersionStep) {
        $html .= '<div class="owned-set-wizard-step" id="owned-set-wizard-step-' . $stepNames['version'] . '" data-step="' . $stepNames['version'] . '">';
        $html .= '<h3>' . htmlspecialchars(t('owned_set_wizard_version_heading')) . '</h3>';
        foreach ($versions as $i => $v) {
            $checkedAttr = $i === 0 ? ' checked' : '';
            $html .= '<label class="checkbox-label"><input type="radio" name="owned-set-wizard-version" value="' . $v['inventory_id'] . '"' . $checkedAttr . '> ' . htmlspecialchars(t('owned_set_wizard_version_label', ['version' => (string) $v['version']])) . '</label>';
        }
        $html .= '</div>';

        $footerHtml .= '<div class="owned-set-wizard-footer-step" data-step="' . $stepNames['version'] . '" style="display:none;">';
        $footerHtml .= '<div class="owned-set-wizard-nav"><button type="button" class="owned-set-wizard-next" data-next="' . $stepNames['location'] . '">' . htmlspecialchars(t('owned_set_wizard_next')) . '</button></div>';
        $footerHtml .= '</div>';
    }

    $html .= '<div class="owned-set-wizard-step" id="owned-set-wizard-step-' . $stepNames['location'] . '" data-step="' . $stepNames['location'] . '"' . ($hasVersionStep ? ' style="display:none;"' : '') . '>';
    $html .= '<h3>' . htmlspecialchars(t('owned_set_wizard_step1_heading')) . '</h3>';
    $html .= '<div class="location-picker" id="owned-set-wizard-location-picker"></div>';
    $html .= '</div>';

    $footerHtml .= '<div class="owned-set-wizard-footer-step" data-step="' . $stepNames['location'] . '" style="display:none;">';
    $footerHtml .= '<p class="owned-set-wizard-error" id="owned-set-wizard-step1-error"></p>';
    $backBtn = $hasVersionStep ? '<button type="button" class="owned-set-wizard-back" data-back="' . $stepNames['version'] . '">' . htmlspecialchars(t('owned_set_wizard_back')) . '</button>' : '';
    $footerHtml .= '<div class="owned-set-wizard-nav">' . $backBtn . '<button type="button" class="owned-set-wizard-next" data-next="' . $stepNames['details'] . '">' . htmlspecialchars(t('owned_set_wizard_next')) . '</button></div>';
    $footerHtml .= '</div>';

    $html .= '<div class="owned-set-wizard-step" id="owned-set-wizard-step-' . $stepNames['details'] . '" data-step="' . $stepNames['details'] . '" style="display:none;">';
    $html .= '<h3>' . htmlspecialchars(t('owned_set_wizard_step2_heading')) . '</h3>';
    $html .= '<label class="checkbox-label"><input type="radio" name="owned-set-wizard-condition" value="new"> ' . htmlspecialchars(t('owned_set_condition_new')) . '</label>';
    $html .= '<label class="checkbox-label"><input type="radio" name="owned-set-wizard-condition" value="used" checked> ' . htmlspecialchars(t('owned_set_condition_used')) . '</label>';

    $detailFields = [
        ['has-instructions', 'owned_set_has_instructions', 'instructions-notes', 'owned_set_instructions_notes_label'],
        ['has-box', 'owned_set_has_box', 'box-notes', 'owned_set_box_notes_label'],
        ['has-box-complete', 'owned_set_box_complete', 'box-complete-notes', 'owned_set_box_complete_notes_label'],
        ['stickers-applied', 'owned_set_stickers_applied', 'stickers-notes', 'owned_set_stickers_notes_label'],
    ];
    foreach ($detailFields as [$checkboxId, $checkboxLabelKey, $notesId, $notesLabelKey]) {
        $html .= '<div class="owned-set-wizard-detail-group">';
        $html .= '<label class="checkbox-label"><input type="checkbox" id="owned-set-wizard-' . $checkboxId . '" value="1"> ' . htmlspecialchars(t($checkboxLabelKey)) . '</label>';
        $html .= '<textarea class="owned-set-wizard-subnote" id="owned-set-wizard-' . $notesId . '" rows="2" placeholder="' . htmlspecialchars(t($notesLabelKey)) . '" style="display:none;"></textarea>';
        $html .= '</div>';
    }
    $html .= '</div>';

    $footerHtml .= '<div class="owned-set-wizard-footer-step" data-step="' . $stepNames['details'] . '" style="display:none;">';
    $footerHtml .= '<div class="owned-set-wizard-nav"><button type="button" class="owned-set-wizard-back" data-back="' . $stepNames['location'] . '">' . htmlspecialchars(t('owned_set_wizard_back')) . '</button><button type="button" class="owned-set-wizard-next" data-next="' . $stepNames['notes'] . '">' . htmlspecialchars(t('owned_set_wizard_next')) . '</button></div>';
    $footerHtml .= '</div>';

    $html .= '<div class="owned-set-wizard-step" id="owned-set-wizard-step-' . $stepNames['notes'] . '" data-step="' . $stepNames['notes'] . '" style="display:none;">';
    $html .= '<h3>' . htmlspecialchars(t('owned_set_wizard_step3_heading')) . '</h3>';
    $html .= '<label>' . htmlspecialchars(t('owned_set_notes_label')) . '<textarea id="owned-set-wizard-notes" rows="4"></textarea></label>';
    $html .= '</div>';

    $footerHtml .= '<div class="owned-set-wizard-footer-step" data-step="' . $stepNames['notes'] . '" style="display:none;">';
    $footerHtml .= '<p class="owned-set-wizard-error" id="owned-set-wizard-step3-error"></p>';
    $footerHtml .= '<div class="owned-set-wizard-nav"><button type="button" class="owned-set-wizard-back" data-back="' . $stepNames['details'] . '">' . htmlspecialchars(t('owned_set_wizard_back')) . '</button><button type="button" class="owned-set-wizard-next" data-next="' . $stepNames['question'] . '">' . htmlspecialchars(t('owned_set_wizard_next')) . '</button></div>';
    $footerHtml .= '</div>';

    $html .= '<div class="owned-set-wizard-step" id="owned-set-wizard-step-' . $stepNames['question'] . '" data-step="' . $stepNames['question'] . '" style="display:none;">';
    $html .= '<h3>' . htmlspecialchars(t('owned_set_wizard_step4_heading')) . '</h3>';
    $html .= '<p>' . htmlspecialchars(t('owned_set_wizard_inventory_question')) . '</p>';
    $html .= '</div>';

    $footerHtml .= '<div class="owned-set-wizard-footer-step" data-step="' . $stepNames['question'] . '" style="display:none;">';
    $footerHtml .= '<p class="owned-set-wizard-error" id="owned-set-wizard-step4-error"></p>';
    $footerHtml .= '<div class="owned-set-wizard-nav"><button type="button" class="owned-set-wizard-back" data-back="' . $stepNames['notes'] . '">' . htmlspecialchars(t('owned_set_wizard_back')) . '</button><button type="button" id="owned-set-wizard-inventory-no">' . htmlspecialchars(t('owned_set_wizard_no')) . '</button><button type="button" id="owned-set-wizard-inventory-yes">' . htmlspecialchars(t('owned_set_wizard_yes')) . '</button></div>';
    $footerHtml .= '</div>';

    $html .= '<div class="owned-set-wizard-step" id="owned-set-wizard-step-' . $stepNames['inventory'] . '" data-step="' . $stepNames['inventory'] . '" style="display:none;">';
    $html .= '<div class="owned-set-inventory-tiles" id="owned-set-wizard-parts-list"></div>';
    $html .= '</div>';

    $footerHtml .= '<div class="owned-set-wizard-footer-step" data-step="' . $stepNames['inventory'] . '" style="display:none;">';
    $footerHtml .= '<p class="owned-set-wizard-error" id="owned-set-wizard-step5-error"></p>';
    $footerHtml .= '<div class="owned-set-inventory-nav">';
    $footerHtml .= '<button type="button" id="owned-set-wizard-inventory-back">' . htmlspecialchars(t('owned_set_wizard_back')) . '</button>';
    $footerHtml .= '<button type="button" id="owned-set-wizard-inventory-next">' . htmlspecialchars(t('owned_set_wizard_next')) . '</button>';
    $footerHtml .= '</div>';
    $footerHtml .= '</div>';

    $html .= '<div class="owned-set-wizard-step owned-set-wizard-overview-step" id="owned-set-wizard-step-' . $stepNames['overview'] . '" data-step="' . $stepNames['overview'] . '" style="display:none;">';
    $html .= '<h4>' . htmlspecialchars(t('owned_set_wizard_overview_details_heading')) . '</h4>';
    $html .= '<table class="set-detail-table" id="owned-set-wizard-overview-recap"></table>';
    $html .= '<h4>' . htmlspecialchars(t('owned_set_wizard_overview_inventory_heading')) . '</h4>';
    $html .= '<table class="set-detail-table" id="owned-set-wizard-overview-summary"></table>';
    $html .= '</div>';

    $footerHtml .= '<div class="owned-set-wizard-footer-step" data-step="' . $stepNames['overview'] . '" style="display:none;">';
    $footerHtml .= '<p class="owned-set-wizard-error" id="owned-set-wizard-overview-error"></p>';
    $footerHtml .= '<div class="owned-set-wizard-nav"><button type="button" id="owned-set-wizard-overview-back">' . htmlspecialchars(t('owned_set_wizard_back')) . '</button><button type="button" id="owned-set-wizard-save">' . htmlspecialchars(t('owned_set_save_button')) . '</button></div>';
    $footerHtml .= '</div>';

    $footerHtml .= '</div>'; // .owned-set-wizard-footer

    $html .= '</div>'; // .owned-set-wizard-body
    $html .= $footerHtml;
    $html .= '</div></div>';

    $labelsJson = json_encode([
        'stepLabel' => t('owned_set_wizard_step_label'),
        'locationRequired' => t('owned_set_wizard_location_required'),
        'errorRetry' => t('import_error_retry'),
        'selectPlaceholder' => t('add_stock_select_placeholder'),
        'noChildren' => t('add_stock_no_children'),
        'levelLabel' => t('location_picker_level_label'),
        'ownedLabel' => t('owned_set_inventory_owned_label'),
        'damagedLabel' => t('owned_set_wizard_damaged_label'),
        'ownedIcon' => getPartStatusIcon('owned'),
        'damagedIcon' => getPartStatusIcon('damaged'),
        'inventorySummary' => t('owned_set_inventory_summary'),
        'partProgress' => t('owned_set_inventory_part_progress'),
        'minifigProgress' => t('owned_set_wizard_minifig_progress'),
        'categoryParts' => t('owned_set_wizard_category_parts'),
        'categorySpares' => t('owned_set_wizard_category_spares'),
        'categoryStickers' => t('owned_set_wizard_category_stickers'),
        'categoryMinifigs' => t('owned_set_wizard_category_minifigs'),
        'nominalLabel' => t('owned_set_minifig_nominal_label'),
        'loading' => t('owned_set_tab_loading'),
        'minifigSummary' => t('owned_set_wizard_minifig_summary'),
        'unsavedConfirm' => t('owned_set_wizard_unsaved_confirm'),
        'recapLocation' => t('owned_set_field_location'),
        'recapCondition' => t('owned_set_field_condition'),
        'conditionNew' => t('owned_set_condition_new'),
        'conditionUsed' => t('owned_set_condition_used'),
        'recapInstructions' => t('owned_set_has_instructions'),
        'recapBox' => t('owned_set_has_box'),
        'recapBoxComplete' => t('owned_set_box_complete'),
        'recapStickers' => t('owned_set_stickers_applied'),
        'recapNotes' => t('owned_set_notes_label'),
        'yesLabel' => t('owned_set_wizard_yes'),
        'noLabel' => t('owned_set_wizard_no'),
        'yesIcon' => getBooleanStatusIcon(true),
        'noIcon' => getBooleanStatusIcon(false),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $detailsStep = $stepNames['details'];
    $notesStep = $stepNames['notes'];
    $questionStep = $stepNames['question'];
    $inventoryStep = $stepNames['inventory'];
    $overviewStep = $stepNames['overview'];

    $html .= <<<SCRIPT
<script>
(function(){
  var texts = $labelsJson;
  var setId = $setId;
  var DETAILS_STEP = $detailsStep;
  var NOTES_STEP = $notesStep;
  var QUESTION_STEP = $questionStep;
  var INVENTORY_STEP = $inventoryStep;
  var OVERVIEW_STEP = $overviewStep;
  var openBtn = document.getElementById('add-owned-set-open');
  var modal = document.getElementById('add-owned-set-modal');
  var closeBtn = document.getElementById('add-owned-set-modal-close');
  var progress = document.getElementById('owned-set-wizard-progress');
  if (!modal || !closeBtn || !progress) {
    return;
  }

  var steps = Array.prototype.slice.call(modal.querySelectorAll('.owned-set-wizard-step'));
  var footerSteps = Array.prototype.slice.call(modal.querySelectorAll('.owned-set-wizard-footer-step'));
  var totalSteps = QUESTION_STEP;
  var createdOwnedSetId = null;
  // Nothing is persisted until the final "Speichern" click (see the
  // owned-set-wizard-save handler) — this just tracks whether the user has
  // gotten far enough that closing/navigating away without saving would
  // actually lose something worth warning about.
  var hasUnsavedProgress = false;
  // Which step the overview's own "Zurück" button returns to — varies by
  // path (sealed/"new" set skips straight from Notes, "Nein" skips from the
  // Question step, a full inventory run comes from the last inventory page),
  // so it's set right before navigating to the overview rather than baked
  // into the markup like the other steps' static data-back attributes.
  var overviewBackTarget = QUESTION_STEP;

  // The deepest level the user actually picked becomes the owned-set's
  // parent location — drilling to an exact leaf isn't required (unlike the
  // part-detail "add stock" picker; here any node in the tree is a valid
  // place to put a whole set).
  var selectedLocationId = null;
  var locationPicker = window.createLocationPicker(
    document.getElementById('owned-set-wizard-location-picker'),
    texts,
    function(value) { selectedLocationId = value; }
  );
  function getSelectedLocationId() {
    return selectedLocationId;
  }

  function showStep(n) {
    steps.forEach(function(step) {
      step.style.display = (parseInt(step.dataset.step, 10) === n) ? 'flex' : 'none';
    });
    footerSteps.forEach(function(footerStep) {
      footerStep.style.display = (parseInt(footerStep.dataset.step, 10) === n) ? 'flex' : 'none';
    });
    progress.textContent = texts.stepLabel.replace('{current}', n).replace('{total}', totalSteps);
    if (n !== INVENTORY_STEP) {
      // Only the inventory step has a sub-progress ("» Teil 8 / 12") — leaving
      // it must clear that span too, or it'd show stale leftovers from the
      // last time the inventory step was open on every other step's line.
      invProgress.textContent = '';
    }
    if (n > 1) {
      hasUnsavedProgress = true;
    }
  }

  function resetWizard() {
    createdOwnedSetId = null;
    hasUnsavedProgress = false;
    totalSteps = QUESTION_STEP;
    var firstVersionRadio = modal.querySelector('input[name="owned-set-wizard-version"]');
    if (firstVersionRadio) { firstVersionRadio.checked = true; }
    locationPicker.reset();
    document.getElementById('owned-set-wizard-step1-error').textContent = '';
    document.getElementById('owned-set-wizard-step3-error').textContent = '';
    document.getElementById('owned-set-wizard-step4-error').textContent = '';
    document.getElementById('owned-set-wizard-step5-error').textContent = '';
    document.getElementById('owned-set-wizard-notes').value = '';
    detailPairs.forEach(function(pair) {
      var checkbox = document.getElementById(pair[1]);
      var notes = document.getElementById('owned-set-wizard-' + pair[0] + '-notes');
      if (checkbox) { checkbox.checked = false; checkbox.disabled = false; }
      if (notes) { notes.value = ''; notes.style.display = 'none'; }
    });
    var usedRadio = modal.querySelector('input[name="owned-set-wizard-condition"][value="used"]');
    if (usedRadio) { usedRadio.checked = true; }
    pages = [];
    pageIndex = 0;
    state = { parts: {}, spares: {}, stickers: {}, minifigs: {} };
    document.getElementById('owned-set-wizard-parts-list').innerHTML = '';
    document.getElementById('owned-set-wizard-inventory-progress').textContent = '';
    document.getElementById('owned-set-wizard-overview-recap').innerHTML = '';
    document.getElementById('owned-set-wizard-overview-summary').innerHTML = '';
    document.getElementById('owned-set-wizard-overview-error').textContent = '';
    overviewBackTarget = QUESTION_STEP;
    showStep(steps[0] ? parseInt(steps[0].dataset.step, 10) : 1);
  }

  function openModal() {
    resetWizard();
    modal.style.display = 'flex';
  }

  // Nothing is saved until "Speichern" succeeds (hasUnsavedProgress tracks
  // exactly that), so closing early is safe to allow — it just needs a
  // confirmation first, since the user might not realize how much of the
  // wizard is unsaved client-side state.
  function closeModal() {
    if (hasUnsavedProgress && !window.confirm(texts.unsavedConfirm)) {
      return;
    }
    // Once actually closed (confirmed, or there was nothing to confirm),
    // the wizard's unsaved state no longer matters to the page as a whole —
    // without this, beforeunload kept warning on every later page
    // navigation even after the wizard itself was long closed.
    hasUnsavedProgress = false;
    modal.style.display = 'none';
  }

  if (openBtn) {
    openBtn.addEventListener('click', function(e) {
      e.preventDefault();
      openModal();
    });
  }
  closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function(e) {
    if (e.target === modal) {
      closeModal();
    }
  });
  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') {
      return;
    }
    if (modal.style.display !== 'none') {
      closeModal();
    }
  });
  // Native browser prompt for the "leaving/closing the tab" case (the
  // message itself is browser-controlled, can't be customized) — the modal
  // close confirm above handles the "closing the wizard, staying on the
  // page" case, this handles actually navigating away or closing the tab.
  window.addEventListener('beforeunload', function(e) {
    if (hasUnsavedProgress) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  modal.querySelectorAll('.owned-set-wizard-next').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var next = parseInt(btn.dataset.next, 10);
      if (next === DETAILS_STEP) {
        var location = getSelectedLocationId();
        var errorEl = document.getElementById('owned-set-wizard-step1-error');
        if (!location) {
          errorEl.textContent = texts.locationRequired;
          return;
        }
        errorEl.textContent = '';
      }
      if (next === QUESTION_STEP) {
        // A still-sealed set can't be inventoried (nothing can be verified
        // without opening it, which is itself the new -> used transition —
        // see openOwnedSet() in src/owned_sets.php), so the question step is
        // skipped entirely and the wizard goes straight to the overview.
        var conditionRadio = modal.querySelector('input[name="owned-set-wizard-condition"]:checked');
        if (conditionRadio && conditionRadio.value === 'new') {
          overviewBackTarget = NOTES_STEP;
          goToOverview(document.getElementById('owned-set-wizard-step3-error'));
          return;
        }
      }
      showStep(next);
    });
  });
  modal.querySelectorAll('.owned-set-wizard-back').forEach(function(btn) {
    btn.addEventListener('click', function() {
      showStep(parseInt(btn.dataset.back, 10));
    });
  });

  var detailPairs = [
    ['instructions', 'owned-set-wizard-has-instructions', true],
    ['box', 'owned-set-wizard-has-box', true],
    ['box-complete', 'owned-set-wizard-has-box-complete', true],
    ['stickers', 'owned-set-wizard-stickers-applied', false]
  ];

  detailPairs.forEach(function(pair) {
    var checkbox = document.getElementById(pair[1]);
    var notes = document.getElementById('owned-set-wizard-' + pair[0] + '-notes');
    if (checkbox && notes) {
      checkbox.addEventListener('change', function() {
        notes.style.display = checkbox.checked ? 'block' : 'none';
      });
    }
  });

  // A still-sealed ("new") set trivially has its instructions, box, and a
  // complete box — nothing can be missing from something nobody has opened
  // yet. So those 3 checkboxes get force-checked and locked while "Neu" is
  // selected; only their notes stay editable. Stickers are the opposite: a
  // sealed set trivially has NOT had its stickers applied, so that one gets
  // force-UNchecked instead — hence the per-field forced value in
  // detailPairs[2] rather than a hardcoded true. Picking "Gebraucht" just
  // unlocks them again (their current checked state is left as-is).
  modal.querySelectorAll('input[name="owned-set-wizard-condition"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
      if (!radio.checked) {
        return;
      }
      var isNew = radio.value === 'new';
      detailPairs.forEach(function(pair) {
        var checkbox = document.getElementById(pair[1]);
        var notes = document.getElementById('owned-set-wizard-' + pair[0] + '-notes');
        checkbox.disabled = isNew;
        if (isNew) {
          checkbox.checked = pair[2];
          notes.style.display = checkbox.checked ? 'block' : 'none';
        }
      });
    });
  });

  function submitAddOwnedSet() {
    var formData = new FormData();
    formData.set('action', 'add_owned_set');
    formData.set('set_id', String(setId));
    formData.set('parent_location_id', getSelectedLocationId());
    var conditionRadio = modal.querySelector('input[name="owned-set-wizard-condition"]:checked');
    formData.set('condition_type', conditionRadio ? conditionRadio.value : 'used');
    formData.set('has_instructions', document.getElementById('owned-set-wizard-has-instructions').checked ? '1' : '');
    formData.set('has_box', document.getElementById('owned-set-wizard-has-box').checked ? '1' : '');
    formData.set('box_complete', document.getElementById('owned-set-wizard-has-box-complete').checked ? '1' : '');
    formData.set('stickers_applied', document.getElementById('owned-set-wizard-stickers-applied').checked ? '1' : '');
    formData.set('notes', document.getElementById('owned-set-wizard-notes').value);
    formData.set('instructions_notes', document.getElementById('owned-set-wizard-instructions-notes').value);
    formData.set('box_notes', document.getElementById('owned-set-wizard-box-notes').value);
    formData.set('box_complete_notes', document.getElementById('owned-set-wizard-box-complete-notes').value);
    formData.set('stickers_notes', document.getElementById('owned-set-wizard-stickers-notes').value);
    var versionRadio = modal.querySelector('input[name="owned-set-wizard-version"]:checked');
    if (versionRadio) {
      formData.set('inventory_id', versionRadio.value);
    }

    return fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function(r) { return r.json(); });
  }

  // Catalog-only preview (see action=set_inventory_preview in index.php) —
  // no owned_sets row exists yet at this point, add_owned_set only runs
  // once at the final "Speichern" click (see the owned-set-wizard-save
  // handler further down). Shared by the "Ja"/"Nein" answers and the
  // sealed-"new" shortcut, since all three need the same nominal data to
  // show an honest overview, even the two that skip the per-tile review.
  function fetchInventoryPreview() {
    var versionRadio = modal.querySelector('input[name="owned-set-wizard-version"]:checked');
    var params = new URLSearchParams();
    params.set('action', 'set_inventory_preview');
    params.set('set_id', String(setId));
    if (versionRadio) {
      params.set('inventory_id', versionRadio.value);
    }
    return fetch('?' + params.toString(), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data.success) {
          throw new Error(data.message || texts.errorRetry);
        }
        initInventoryPages(data);
      });
  }

  function showOverview() {
    renderOverview();
    totalSteps = OVERVIEW_STEP;
    showStep(OVERVIEW_STEP);
  }

  function goToOverview(errorEl) {
    errorEl.textContent = '';
    fetchInventoryPreview().then(function() {
      showOverview();
    }).catch(function(err) {
      errorEl.textContent = (err && err.message) || texts.errorRetry;
    });
  }

  document.getElementById('owned-set-wizard-inventory-no').addEventListener('click', function() {
    overviewBackTarget = QUESTION_STEP;
    goToOverview(document.getElementById('owned-set-wizard-step4-error'));
  });

  document.getElementById('owned-set-wizard-inventory-yes').addEventListener('click', function() {
    var errorEl = document.getElementById('owned-set-wizard-step4-error');
    errorEl.textContent = '';
    fetchInventoryPreview().then(function() {
      renderPage();
      totalSteps = OVERVIEW_STEP;
      showStep(INVENTORY_STEP);
    }).catch(function(err) {
      errorEl.textContent = (err && err.message) || texts.errorRetry;
    });
  });

  // The inventory step covers 4 categories in sequence (Bauteile,
  // Ersatzteile, Stickerbögen, Minifiguren) — each split further into one
  // page per distinct part number (parts/spares/stickers) or a single page
  // holding all of them (minifigs, which have no color variants to group
  // by). Flattened up front into one linear "pages" list so paging is just
  // a single index, no separate category/group nesting to juggle. Any
  // category the set doesn't have (e.g. no spares) contributes zero pages
  // and is silently skipped. state is keyed by category so Speichern can
  // route each entry to the right POST field names/backend columns; it
  // survives page changes (not just the currently-visible page), so paging
  // back and forth never loses an already-entered value.
  var pages = [];
  var state = { parts: {}, spares: {}, stickers: {}, minifigs: {} };
  var pageIndex = 0;
  var invList = document.getElementById('owned-set-wizard-parts-list');
  var wizardBody = modal.querySelector('.owned-set-wizard-body');
  var invProgress = document.getElementById('owned-set-wizard-inventory-progress');
  var invBackBtn = document.getElementById('owned-set-wizard-inventory-back');
  var invNextBtn = document.getElementById('owned-set-wizard-inventory-next');

  function initInventoryPages(data) {
    pages = [];
    state = { parts: {}, spares: {}, stickers: {}, minifigs: {} };
    pageIndex = 0;

    var defs = [
      { category: 'parts', items: data.parts || [], label: texts.categoryParts, kind: 'part' },
      { category: 'spares', items: data.spares || [], label: texts.categorySpares, kind: 'part' },
      { category: 'stickers', items: data.stickers || [], label: texts.categoryStickers, kind: 'part' },
      { category: 'minifigs', items: data.minifigs || [], label: texts.categoryMinifigs, kind: 'minifig' }
    ];

    defs.forEach(function(def) {
      if (!def.items.length) {
        return;
      }
      // Seeded eagerly for every item, not just the ones the user actually
      // pages to — the overview step (computeCategorySummary()/
      // computeMinifigSummary()) needs to sum over the whole set, including
      // pages nobody visited. nominal wasn't kept in state before (only
      // needed at tile-render time); now it has to survive until the
      // overview computes totals from it.
      if (def.kind === 'part') {
        def.items.forEach(function(item) {
          var key = item.part_id + ':' + item.color_id;
          state[def.category][key] = { owned: item.actual_quantity, damaged: item.damaged_quantity, nominal: item.nominal_quantity };
        });
      } else {
        def.items.forEach(function(item) {
          state.minifigs[item.minifig_id] = { nominal: item.nominal_quantity, parts: {}, loaded: false };
        });
      }

      var categoryPages;
      if (def.kind === 'part') {
        // A part_num's color variants share a page, but more than 4 tiles
        // wraps to a second row (the grid fits ~4 at the wizard's current
        // width) — so once a part_num's current page is full, its
        // remaining variants spill onto a fresh page of their own instead
        // of all piling onto one.
        var indexByPartNum = {};
        categoryPages = [];
        def.items.forEach(function(item) {
          var idx = indexByPartNum[item.part_num];
          if (idx === undefined || categoryPages[idx].length >= 4) {
            idx = categoryPages.length;
            categoryPages.push([]);
            indexByPartNum[item.part_num] = idx;
          }
          categoryPages[idx].push(item);
        });
      } else {
        // One page per minifig (not one page for all of them) — each gets
        // its full constituent-parts checklist rendered inline as soon as
        // its page is reached, no extra click needed.
        categoryPages = def.items.map(function(item) { return [item]; });
      }
      categoryPages.forEach(function(items, i) {
        pages.push({ category: def.category, kind: def.kind, label: def.label, items: items, categoryIndex: i + 1, categoryTotal: categoryPages.length });
      });
    });
  }

  function buildStepper(minVal, maxVal, value) {
    var wrap = document.createElement('div');
    wrap.className = 'owned-set-inventory-stepper owned-set-inventory-stepper-stacked';
    var input = document.createElement('input');
    input.type = 'number';
    input.min = String(minVal);
    input.max = String(maxVal);
    input.value = String(value);

    var arrows = document.createElement('div');
    arrows.className = 'owned-set-inventory-stepper-arrows';
    var plusBtn = document.createElement('button');
    plusBtn.type = 'button';
    plusBtn.className = 'owned-set-inventory-stepper-btn owned-set-inventory-stepper-btn-up';
    plusBtn.textContent = '+';
    var minusBtn = document.createElement('button');
    minusBtn.type = 'button';
    minusBtn.className = 'owned-set-inventory-stepper-btn owned-set-inventory-stepper-btn-down';
    minusBtn.textContent = '\\u2212';

    function step(delta) {
      var v = (parseInt(input.value, 10) || 0) + delta;
      v = Math.max(parseInt(input.min, 10), Math.min(v, parseInt(input.max, 10)));
      input.value = String(v);
      input.dispatchEvent(new Event('input'));
    }
    plusBtn.addEventListener('click', function() { step(1); });
    minusBtn.addEventListener('click', function() { step(-1); });

    arrows.appendChild(plusBtn);
    arrows.appendChild(minusBtn);
    wrap.appendChild(input);
    wrap.appendChild(arrows);
    return { wrap: wrap, input: input };
  }

  // Mirrors ownedSetMinifigBottleneckStatus() in this file's PHP — see its
  // doc comment for why "present" uses max() across parts (one missing
  // accessory doesn't erase an otherwise-present minifig) while "complete"
  // uses min() (the scarcest/most-damaged part bottlenecks how many
  // complete copies exist).
  function bottleneckStatus(parts, n) {
    if (n <= 0) {
      return { present: 0, complete: 0, damaged: 0 };
    }
    if (parts.length === 0) {
      return { present: n, complete: n, damaged: 0 };
    }
    var maxPresentRatio = 0;
    var minIntactRatio = 1;
    parts.forEach(function(p) {
      if (p.nominal <= 0) {
        return;
      }
      var actualRatio = Math.min(1, p.actual / p.nominal);
      var intactRatio = Math.min(1, Math.max(0, p.actual - p.damaged) / p.nominal);
      maxPresentRatio = Math.max(maxPresentRatio, actualRatio);
      minIntactRatio = Math.min(minIntactRatio, intactRatio);
    });
    var present = Math.min(n, Math.floor(maxPresentRatio * n + 1e-9));
    var complete = Math.min(present, Math.floor(minIntactRatio * n + 1e-9));
    return { present: present, complete: complete, damaged: Math.max(0, present - complete) };
  }

  function getMinifigState(item) {
    if (!state.minifigs[item.minifig_id]) {
      state.minifigs[item.minifig_id] = { nominal: item.nominal_quantity, parts: {}, loaded: false };
    }
    return state.minifigs[item.minifig_id];
  }

  // Minifigs get their own page per figure (see initInventoryPages()) and
  // that page renders the full constituent-parts checklist inline as soon
  // as it's reached — no click/modal step, per the user's request that the
  // wizard "direkt die Bauteile der Figur abfragt". Vorhanden/Beschädigt for
  // the whole figure are read-only, derived live from the parts via
  // bottleneckStatus() (mirrors owned_set_detail's minifig modal). Edits
  // are kept in state.minifigs[id] (plain numbers, not DOM refs) so paging
  // away and back doesn't lose them or re-fetch.
  function renderMinifigPanel(item) {
    var mState = getMinifigState(item);
    var nominal = mState.nominal;

    var panel = document.createElement('div');
    panel.className = 'owned-set-wizard-minifig-panel';

    var header = document.createElement('div');
    header.className = 'owned-set-qty-modal-header';
    var img = document.createElement('span');
    img.className = 'owned-set-qty-modal-image';
    if (item.thumbnail) {
      img.innerHTML = '<img src="' + item.thumbnail + '" alt="">';
    }
    header.appendChild(img);
    var info = document.createElement('div');
    var title = document.createElement('h3');
    title.textContent = item.fig_num;
    var name = document.createElement('p');
    name.textContent = item.name;
    var nominalLine = document.createElement('p');
    nominalLine.className = 'owned-set-minifig-nominal-line';
    nominalLine.textContent = texts.nominalLabel.replace('{count}', nominal);
    info.appendChild(title);
    info.appendChild(name);
    info.appendChild(nominalLine);
    header.appendChild(info);
    panel.appendChild(header);

    var partsList = document.createElement('div');
    partsList.className = 'owned-set-minifig-parts-list';
    panel.appendChild(partsList);

    function renderRows() {
      partsList.innerHTML = '';
      Object.keys(mState.parts).forEach(function(key) {
        var p = mState.parts[key];
        var row = document.createElement('div');
        row.className = 'owned-set-minifig-part-row';

        var partNameText = p.name + (p.colorName ? ' \\u00b7 ' + p.colorName : '');
        var partImg = document.createElement('span');
        partImg.className = 'part-card-image';
        partImg.title = partNameText;
        if (p.thumbnail) {
          partImg.innerHTML = '<img src="' + p.thumbnail + '" alt="' + partNameText.replace(/"/g, '&quot;') + '">';
        }
        row.appendChild(partImg);

        var ownedStepper = buildStepper(0, p.nominal, p.owned);
        var ownedIcon = document.createElement('span');
        ownedIcon.className = 'owned-set-stepper-icon owned-set-stepper-icon-owned';
        ownedIcon.innerHTML = texts.ownedIcon;
        ownedIcon.title = texts.ownedLabel;
        ownedStepper.wrap.insertBefore(ownedIcon, ownedStepper.wrap.firstChild);

        var damagedStepper = buildStepper(0, p.owned, p.damaged);
        var damagedIcon = document.createElement('span');
        damagedIcon.className = 'owned-set-stepper-icon owned-set-stepper-icon-damaged';
        damagedIcon.innerHTML = texts.damagedIcon;
        damagedIcon.title = texts.damagedLabel;
        damagedStepper.wrap.insertBefore(damagedIcon, damagedStepper.wrap.firstChild);

        ownedStepper.input.addEventListener('input', function() {
          var v = parseInt(ownedStepper.input.value, 10) || 0;
          p.owned = v;
          damagedStepper.input.max = String(v);
          if (p.damaged > v) {
            p.damaged = v;
            damagedStepper.input.value = String(v);
          }
        });
        damagedStepper.input.addEventListener('input', function() {
          p.damaged = parseInt(damagedStepper.input.value, 10) || 0;
        });
        row.appendChild(ownedStepper.wrap);
        row.appendChild(damagedStepper.wrap);
        partsList.appendChild(row);
      });
    }

    if (mState.loaded) {
      renderRows();
    } else {
      partsList.textContent = texts.loading;
      fetch('?action=minifig_parts_preview&fig_num=' + encodeURIComponent(item.fig_num) + '&nominal_count=' + mState.nominal, { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (!res.success) {
            partsList.textContent = res.message || texts.errorRetry;
            return;
          }
          res.parts.forEach(function(part) {
            var key = part.part_id + ':' + part.color_id;
            mState.parts[key] = {
              nominal: part.nominal_quantity,
              owned: part.actual_quantity,
              damaged: part.damaged_quantity,
              thumbnail: part.thumbnail,
              name: part.name,
              colorName: part.color_name
            };
          });
          mState.loaded = true;
          renderRows();
        })
        .catch(function() {
          partsList.textContent = texts.errorRetry;
        });
    }

    return panel;
  }

  function renderTile(item, category, kind) {
    if (kind === 'minifig') {
      return renderMinifigPanel(item);
    }

    var key = item.part_id + ':' + item.color_id;
    if (!state[category][key]) {
      state[category][key] = { owned: item.actual_quantity, damaged: item.damaged_quantity };
    }
    var s = state[category][key];

    var tile = document.createElement('div');
    tile.className = 'owned-set-inventory-tile';

    var img = document.createElement('span');
    img.className = 'part-card-image';
    if (item.thumbnail) {
      img.innerHTML = '<img src="' + item.thumbnail + '" alt="">';
    }
    tile.appendChild(img);

    var num = document.createElement('span');
    num.className = 'part-card-num';
    num.textContent = item.part_num;
    tile.appendChild(num);

    var name = document.createElement('span');
    name.className = 'part-card-name';
    name.textContent = item.name + (item.color_name ? ' \\u00b7 ' + item.color_name : '');
    tile.appendChild(name);

    var inputsWrap = document.createElement('div');
    inputsWrap.className = 'owned-set-inventory-tile-inputs';

    var ownedLabel = document.createElement('label');
    ownedLabel.setAttribute('aria-label', texts.ownedLabel);
    var ownedStepper = buildStepper(0, item.nominal_quantity, s.owned);
    var ownedInput = ownedStepper.input;
    var ownedIcon = document.createElement('span');
    ownedIcon.className = 'owned-set-stepper-icon owned-set-stepper-icon-owned';
    ownedIcon.innerHTML = texts.ownedIcon;
    ownedStepper.wrap.insertBefore(ownedIcon, ownedStepper.wrap.firstChild);
    ownedLabel.appendChild(ownedStepper.wrap);
    inputsWrap.appendChild(ownedLabel);

    var damagedLabel = document.createElement('label');
    damagedLabel.setAttribute('aria-label', texts.damagedLabel);
    var damagedStepper = buildStepper(0, s.owned, s.damaged);
    var damagedInput = damagedStepper.input;
    var damagedIcon = document.createElement('span');
    damagedIcon.className = 'owned-set-stepper-icon owned-set-stepper-icon-damaged';
    damagedIcon.innerHTML = texts.damagedIcon;
    damagedStepper.wrap.insertBefore(damagedIcon, damagedStepper.wrap.firstChild);
    damagedLabel.appendChild(damagedStepper.wrap);
    inputsWrap.appendChild(damagedLabel);

    tile.appendChild(inputsWrap);

    var summary = document.createElement('p');
    summary.className = 'owned-set-inventory-summary';
    tile.appendChild(summary);

    function updateSummary() {
      var owned = Math.max(0, Math.min(parseInt(ownedInput.value, 10) || 0, item.nominal_quantity));
      damagedInput.max = String(owned);
      var damaged = Math.max(0, Math.min(parseInt(damagedInput.value, 10) || 0, owned));
      var intact = owned - damaged;
      var missing = item.nominal_quantity - owned;
      var summaryText = texts.inventorySummary
        .replace('{intact}', intact)
        .replace('{damaged}', damaged)
        .replace('{missing}', missing);
      // Only the first " · " (between intact and damaged) becomes a line
      // break — the second one (between damaged and missing) stays inline,
      // per the user's request to keep the tile more compact vertically.
      summary.innerHTML = summaryText.replace(' · ', '<br>');
      state[category][key].owned = owned;
      state[category][key].damaged = damaged;
    }
    ownedInput.addEventListener('input', updateSummary);
    damagedInput.addEventListener('input', updateSummary);
    updateSummary();

    return tile;
  }

  function renderPage() {
    invList.innerHTML = '';
    // Measured directly rather than left to CSS percentage-height (see the
    // .owned-set-wizard-footer restructuring's doc comment for why that
    // doesn't reliably propagate through nested containers) — the tiles
    // grid is the step's only content, so it can just take all of
    // .owned-set-wizard-body's available height. Grid's own default
    // align-items:stretch then fills each tile to match, and each tile's
    // own margin-top:auto (on .owned-set-inventory-tile-inputs) pushes its
    // steppers+summary to the tile's bottom — same size every page,
    // regardless of name length or how many tiles share the row.
    invList.style.height = wizardBody.clientHeight + 'px';
    if (pages.length === 0) {
      invProgress.textContent = '';
      invBackBtn.disabled = true;
      invNextBtn.disabled = true;
      return;
    }
    var page = pages[pageIndex];
    var progressTemplate = page.kind === 'minifig' ? texts.minifigProgress : texts.partProgress;
    invProgress.textContent = ' \\u00bb ' + progressTemplate.replace('{current}', page.categoryIndex).replace('{total}', page.categoryTotal);
    invBackBtn.disabled = pageIndex === 0;
    // Never disabled on the last page anymore — "Weiter" there advances to
    // the overview instead of paging further (see the click handler below).
    invNextBtn.disabled = false;

    page.items.forEach(function(item) {
      invList.appendChild(renderTile(item, page.category, page.kind));
    });
  }

  invBackBtn.addEventListener('click', function() {
    if (pageIndex > 0) {
      pageIndex--;
      renderPage();
    }
  });
  invNextBtn.addEventListener('click', function() {
    if (pageIndex < pages.length - 1) {
      pageIndex++;
      renderPage();
    } else {
      overviewBackTarget = INVENTORY_STEP;
      showOverview();
    }
  });

  var inventoryFieldNames = {
    parts: ['owned', 'damaged'],
    spares: ['spare_owned', 'spare_damaged'],
    stickers: ['sticker_owned', 'sticker_damaged']
  };

  // Sums one part-kind category's state into the same
  // intact/damaged/missing shape texts.inventorySummary already renders
  // per-tile elsewhere — here summed across every item, not just one.
  function computeCategorySummary(category) {
    var intact = 0, damaged = 0, missing = 0;
    Object.keys(state[category]).forEach(function(key) {
      var s = state[category][key];
      var owned = Math.max(0, Math.min(s.owned, s.nominal));
      var dmg = Math.max(0, Math.min(s.damaged, owned));
      intact += (owned - dmg);
      damaged += dmg;
      missing += (s.nominal - owned);
    });
    return { intact: intact, damaged: damaged, missing: missing };
  }

  // Mirrors the Fertig/Speichern save logic's own defaulting: a minifig
  // whose page was never opened counts as fully present and undamaged
  // (mState.loaded stays false, matching materializeOwnedSetMinifigs()'s
  // default once the set is actually saved). "unvollständig" is
  // nominal - present — bottleneckStatus() already computes present/
  // complete/damaged, this is just the one derived number it doesn't
  // return itself.
  function computeMinifigSummary() {
    var complete = 0, incomplete = 0, damaged = 0;
    Object.keys(state.minifigs).forEach(function(id) {
      var mState = state.minifigs[id];
      var status;
      if (mState.loaded) {
        var parts = Object.keys(mState.parts).map(function(key) {
          var p = mState.parts[key];
          return { nominal: p.nominal, actual: p.owned, damaged: p.damaged };
        });
        status = bottleneckStatus(parts, mState.nominal);
      } else {
        status = { present: mState.nominal, complete: mState.nominal, damaged: 0 };
      }
      complete += status.complete;
      damaged += status.damaged;
      incomplete += (mState.nominal - status.present);
    });
    return { complete: complete, incomplete: incomplete, damaged: damaged };
  }

  function getSelectedLocationLabel() {
    return locationPicker.getLabel();
  }

  // table is a <table class="set-detail-table"> — th=label, td=value,
  // same compact label:value pattern renderSetGeneralInfoTable() uses
  // elsewhere in the app. fillTd(td) populates the value cell — a plain
  // string assignment for most rows, DOM (icon + optional note text) for
  // the three Ja/Nein rows.
  function addRecapRow(table, label, fillTd) {
    var tr = document.createElement('tr');
    var th = document.createElement('th');
    th.textContent = label;
    var td = document.createElement('td');
    fillTd(td);
    tr.appendChild(th);
    tr.appendChild(td);
    table.appendChild(tr);
  }

  function appendBooleanIcon(td, value, titleText) {
    var icon = document.createElement('span');
    icon.className = 'owned-set-wizard-bool-icon';
    icon.innerHTML = value ? texts.yesIcon : texts.noIcon;
    icon.title = titleText;
    td.appendChild(icon);
  }

  // Reads straight from the still-intact form fields of every earlier step
  // (nothing gets reset until the wizard actually closes/reopens) — nothing
  // here is persisted state, just a read-only recap of what's about to be
  // saved.
  function renderOverview() {
    var recap = document.getElementById('owned-set-wizard-overview-recap');
    recap.innerHTML = '';

    var conditionRadio = modal.querySelector('input[name="owned-set-wizard-condition"]:checked');
    var isNew = conditionRadio && conditionRadio.value === 'new';
    var locationLabel = getSelectedLocationLabel();
    addRecapRow(recap, texts.recapLocation, function(td) { td.textContent = locationLabel; });
    addRecapRow(recap, texts.recapCondition, function(td) { td.textContent = isNew ? texts.conditionNew : texts.conditionUsed; });

    [
      ['has-instructions', 'instructions-notes', texts.recapInstructions],
      ['has-box', 'box-notes', texts.recapBox],
      ['has-box-complete', 'box-complete-notes', texts.recapBoxComplete],
      ['stickers-applied', 'stickers-notes', texts.recapStickers]
    ].forEach(function(triple) {
      var checkbox = document.getElementById('owned-set-wizard-' + triple[0]);
      var notes = document.getElementById('owned-set-wizard-' + triple[1]);
      addRecapRow(recap, triple[2], function(td) {
        appendBooleanIcon(td, checkbox.checked, checkbox.checked ? texts.yesLabel : texts.noLabel);
        if (checkbox.checked && notes.value.trim()) {
          td.appendChild(document.createTextNode(' ' + notes.value.trim()));
        }
      });
    });

    var freeNotes = document.getElementById('owned-set-wizard-notes').value.trim();
    if (freeNotes) {
      addRecapRow(recap, texts.recapNotes, function(td) { td.textContent = freeNotes; });
    }

    var summary = document.getElementById('owned-set-wizard-overview-summary');
    summary.innerHTML = '';
    [
      ['parts', texts.categoryParts],
      ['spares', texts.categorySpares],
      ['stickers', texts.categoryStickers]
    ].forEach(function(pair) {
      if (Object.keys(state[pair[0]]).length === 0) {
        return;
      }
      var s = computeCategorySummary(pair[0]);
      var text = texts.inventorySummary
        .replace('{intact}', s.intact).replace('{damaged}', s.damaged).replace('{missing}', s.missing);
      addRecapRow(summary, pair[1], function(td) { td.textContent = text; });
    });
    if (Object.keys(state.minifigs).length > 0) {
      var ms = computeMinifigSummary();
      var minifigText = texts.minifigSummary
        .replace('{complete}', ms.complete).replace('{incomplete}', ms.incomplete).replace('{damaged}', ms.damaged);
      addRecapRow(summary, texts.categoryMinifigs, function(td) { td.textContent = minifigText; });
    }
  }

  document.getElementById('owned-set-wizard-overview-back').addEventListener('click', function() {
    showStep(overviewBackTarget);
  });

  // The only point that actually persists anything — everything before this
  // was preview/local state. add_owned_set runs first (creates the row +
  // materializes nominal stock, same as it always did, just deferred to
  // here), then the same inventory-save chain the old "Fertig" button ran
  // replays on top of it. If that chain fails partway, the just-created row
  // is rolled back (action=remove_owned_set) so a failed save never leaves
  // an unconfirmed set sitting in the collection.
  document.getElementById('owned-set-wizard-save').addEventListener('click', function() {
    var errorEl = document.getElementById('owned-set-wizard-overview-error');
    errorEl.textContent = '';
    submitAddOwnedSet().then(function(res) {
      if (!res.success) {
        errorEl.textContent = res.message || texts.errorRetry;
        return;
      }
      createdOwnedSetId = res.ownedSetId;

      var formData = new FormData();
      formData.set('action', 'save_owned_set_inventory');
      formData.set('owned_set_id', String(createdOwnedSetId));
      Object.keys(inventoryFieldNames).forEach(function(category) {
        var fieldNames = inventoryFieldNames[category];
        Object.keys(state[category]).forEach(function(key) {
          formData.set(fieldNames[0] + '[' + key + ']', String(state[category][key].owned));
          formData.set(fieldNames[1] + '[' + key + ']', String(state[category][key].damaged));
        });
      });

      var minifigPartSaves = [];
      Object.keys(state.minifigs).forEach(function(minifigId) {
        var mState = state.minifigs[minifigId];
        if (!mState.loaded) {
          return;
        }
        var parts = Object.keys(mState.parts).map(function(key) {
          var p = mState.parts[key];
          return { nominal: p.nominal, actual: p.owned, damaged: p.damaged };
        });
        var status = bottleneckStatus(parts, mState.nominal);
        formData.set('minifig_owned[' + minifigId + ']', String(status.present));
        formData.set('minifig_damaged[' + minifigId + ']', String(status.damaged));

        var partsFormData = new FormData();
        partsFormData.set('action', 'save_owned_set_minifig_parts');
        partsFormData.set('owned_set_id', String(createdOwnedSetId));
        partsFormData.set('minifig_id', minifigId);
        Object.keys(mState.parts).forEach(function(key) {
          var p = mState.parts[key];
          partsFormData.set('part_owned[' + key + ']', String(p.owned));
          partsFormData.set('part_damaged[' + key + ']', String(p.damaged));
        });
        minifigPartSaves.push(partsFormData);
      });

      return fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res2) {
          if (!res2.success) {
            throw new Error(res2.message || texts.errorRetry);
          }
          return Promise.all(minifigPartSaves.map(function(partsFormData) {
            return fetch('?', { method: 'POST', body: partsFormData, credentials: 'same-origin' }).then(function(r) { return r.json(); });
          }));
        })
        .then(function(results) {
          var failed = results.find(function(r) { return !r.success; });
          if (failed) {
            throw new Error(failed.message || texts.errorRetry);
          }
          hasUnsavedProgress = false;
          window.location.href = '?page=owned_set_detail&id=' + createdOwnedSetId;
        })
        .catch(function(err) {
          var rollbackData = new FormData();
          rollbackData.set('action', 'remove_owned_set');
          rollbackData.set('owned_set_id', String(createdOwnedSetId));
          rollbackData.set('set_id', String(setId));
          fetch('?', { method: 'POST', body: rollbackData, credentials: 'same-origin', redirect: 'manual' }).catch(function() {});
          createdOwnedSetId = null;
          errorEl.textContent = (err && err.message) || texts.errorRetry;
        });
    }).catch(function(err) {
      errorEl.textContent = (err && err.message) || texts.errorRetry;
    });
  });
})();
</script>
SCRIPT;

    return $html;
}
