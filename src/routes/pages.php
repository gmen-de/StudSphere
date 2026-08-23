<?php

declare(strict_types=1);

/**
 * Full-page renderers for every `?page=...` route — required by index.php
 * last, after both action-handler stages and the current-user fetch, since
 * several pages read a $xMessage variable a same-request action handler in
 * src/routes/actions.php or src/routes/pre_auth.php may have set (e.g. the
 * settings page's $importMessage/$adminUserMessage, the locations page's
 * $locationMessage). Each block still ends its own request via
 * renderApp()+exit, matching how these worked inline in index.php before.
 */

if (isset($_GET['page']) && $_GET['page'] === 'settings') {
    $content = '<h1>' . htmlspecialchars(t('settings_title')) . '</h1>';
    $content .= '<p><a href="' . $_SERVER['PHP_SELF'] . '">' . htmlspecialchars(t('settings_back')) . '</a></p>';
    if ($importMessage !== '') {
        $content .= '<p><strong>' . htmlspecialchars($importMessage) . '</strong></p>';
    }

    $content .= '<h2>' . htmlspecialchars(t('settings_collection_title')) . '</h2>';
    $content .= '<p>' . htmlspecialchars(t('settings_collection_help')) . '</p>';
    if ($collectionMessage !== '') {
        $content .= '<p><strong>' . htmlspecialchars($collectionMessage) . '</strong></p>';
    }
    $content .= '<form method="post">';
    $content .= '<input type="hidden" name="action" value="update_collection_settings">';
    $content .= '<label>' . htmlspecialchars(t('collection_name_label'))
        . '<input name="collection_name" value="' . htmlspecialchars((string) getAppSetting('collection_name', '')) . '" required></label>';
    $content .= '<button type="submit">' . htmlspecialchars(t('settings_collection_save_button')) . '</button>';
    $content .= '</form>';

    $content .= '<button type="button" id="rebrickable-update-open">' . htmlspecialchars(t('settings_update_button')) . '</button>';
    $content .= '<p>' . htmlspecialchars(t('settings_update_help')) . '</p>';
    $content .= renderRebrickableUpdateModal();
    $content .= '<h2>' . htmlspecialchars(t('settings_recent_updates')) . '</h2>';
    $formatLastUpdate = fn (string $key): string => ($value = getAppSetting($key)) !== null ? formatDate($value, true) : t('not_available');
    $content .= '<ul>';
    $content .= '<li>' . htmlspecialchars(t('settings_last_update_all', ['value' => $formatLastUpdate('last_update_all')])) . '</li>';
    $content .= '<li>' . htmlspecialchars(t('settings_last_update_parts', ['value' => $formatLastUpdate('last_update_parts')])) . '</li>';
    $content .= '<li>' . htmlspecialchars(t('settings_last_update_sets', ['value' => $formatLastUpdate('last_update_sets')])) . '</li>';
    $content .= '</ul>';

    $content .= '<h2>' . htmlspecialchars(t('settings_rebrickable_title')) . '</h2>';
    $content .= '<p>' . htmlspecialchars(t('settings_rebrickable_help')) . '</p>';
    $content .= '<form method="post">';
    $content .= '<input type="hidden" name="action" value="update_rebrickable_settings">';
    $content .= '<label>' . htmlspecialchars(t('settings_download_base_url_label'))
        . '<input name="download_base_url" value="' . htmlspecialchars((string) getAppSetting('rebrickable_download_base_url', '')) . '" placeholder="https://cdn.rebrickable.com/media/downloads/"></label>';
    $content .= '<small>' . htmlspecialchars(t('settings_download_base_url_help')) . '</small>';
    $content .= '<label>' . htmlspecialchars(t('settings_api_url_label'))
        . '<input name="api_url" value="' . htmlspecialchars((string) getAppSetting('rebrickable_api_url', '')) . '" placeholder="https://rebrickable.com/api/v3/"></label>';
    $content .= '<small>' . htmlspecialchars(t('settings_api_url_help')) . '</small>';
    $content .= '<label>' . htmlspecialchars(t('settings_api_key_label'))
        . '<input name="api_key" value="' . htmlspecialchars((string) getAppSetting('rebrickable_api_key', '')) . '"></label>';
    $content .= '<button type="submit">' . htmlspecialchars(t('settings_rebrickable_save_button')) . '</button>';
    $content .= '</form>';

    $content .= '<h2>' . htmlspecialchars(t('image_download')) . '</h2>';
    $content .= '<p>' . htmlspecialchars(t('image_download_help')) . '</p>';
    $content .= '<form method="post" id="image-form">';
    $content .= '<label class="checkbox-label"><input type="checkbox" name="force_refresh" value="1"> ' . htmlspecialchars(t('image_force_refresh_label')) . '</label>';
    $content .= '<p class="hint">' . htmlspecialchars(t('image_force_refresh_help')) . '</p>';
    $content .= '<div class="import-status" id="imageStatus">';
    $content .= '<div class="progress-message idle" id="imageMessage">' . htmlspecialchars(t('import_not_started')) . '</div>';
    $content .= '<div class="progress-track" id="imageProgress"><div class="progress-fill"></div></div>';
    $content .= '<ul class="import-file-list" id="imageTableList">';
    $imageTables = [
        'sets' => t('image_table_sets'),
        'minifigs' => t('image_table_minifigs'),
        'inventory_parts' => t('image_table_inventory_parts'),
    ];
    foreach ($imageTables as $tableKey => $tableLabel) {
        $content .= '<li class="import-file import-file-pending" data-table="' . htmlspecialchars($tableKey) . '"><span class="import-file-name">' . htmlspecialchars($tableLabel) . '</span><span class="import-file-status">' . htmlspecialchars(t('import_stage_pending')) . '</span></li>';
    }
    $content .= '</ul>';
    $content .= '</div>';
    $content .= '<button type="submit">' . htmlspecialchars(t('image_download_button')) . '</button>';
    $content .= '</form>';
    $imageLabelsJson = json_encode([
        'running' => t('image_download_running'),
        'button' => t('image_download_button'),
        'resume_button' => t('import_resume_button'),
        'error_retry' => t('import_error_retry'),
        'started' => t('import_started'),
        'stage_pending' => t('import_stage_pending'),
        'stage_running' => t('image_stage_running'),
        'stage_done' => t('import_stage_done'),
        'processed_of_total' => t('image_processed_of_total'),
        'downloaded_label' => t('image_downloaded_label'),
        'skipped_label' => t('image_skipped_label'),
        'errors_label' => t('image_errors_label'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $imageLocaleJson = json_encode(getLocale(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $content .= <<<SCRIPT
<script>
(function(){
  var texts = $imageLabelsJson;
  var appLocale = $imageLocaleJson;
  var stageLabels = {
    pending: texts.stage_pending,
    running: texts.stage_running,
    done: texts.stage_done
  };
  var form = document.getElementById("image-form");
  var track = document.getElementById("imageProgress");
  var fill = track ? track.querySelector(".progress-fill") : null;
  var msg = document.getElementById("imageMessage");
  var tableList = document.getElementById("imageTableList");
  if (!form || !track || !fill || !msg || !tableList) {
    return;
  }

  function formatNumber(n) {
    if (n === null || n === undefined) {
      return "0";
    }
    var sep = appLocale === "de" ? "." : ",";
    return String(n).replace(/\\B(?=(\\d{3})+(?!\\d))/g, sep);
  }

  function renderTables(tables) {
    tableList.innerHTML = "";
    Object.keys(tables).forEach(function(key) {
      var table = tables[key];
      var li = document.createElement("li");
      li.className = "import-file import-file-" + (table.stage === "done" ? "done" : (table.stage === "running" ? "importing" : "pending"));

      var name = document.createElement("span");
      name.className = "import-file-name";
      name.textContent = key;

      var status = document.createElement("span");
      status.className = "import-file-status";
      var text = stageLabels[table.stage] || table.stage;
      if (table.stage !== "pending") {
        text += " — " + texts.processed_of_total
          .replace("{processed}", formatNumber(table.processed))
          .replace("{total}", formatNumber(table.total));
        text += " (" + texts.downloaded_label + ": " + formatNumber(table.downloaded)
          + ", " + texts.skipped_label + ": " + formatNumber(table.skipped)
          + ", " + texts.errors_label + ": " + formatNumber(table.errors) + ")";
      }
      status.textContent = text;

      li.appendChild(name);
      li.appendChild(status);
      tableList.appendChild(li);
    });
  }

  function updateStatus(data) {
    msg.classList.remove("idle");
    msg.textContent = data.message || texts.running;
    fill.style.width = (data.percent || 0) + "%";
    if (data.tables) {
      renderTables(data.tables);
    }
  }

  async function tick(formData) {
    var response = await fetch('?page=settings', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    });
    if (!response.ok && response.status !== 500) {
      throw new Error('tick failed with status ' + response.status);
    }
    return await response.json();
  }

  var hasStarted = false;
  var consecutiveFailures = 0;
  var maxAutoRetries = 4;

  form.addEventListener("submit", function(event) {
    event.preventDefault();
    var submitButton = form.querySelector("button[type=submit]");
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = texts.running;
    }

    if (!hasStarted) {
      updateStatus({percent: 0, message: texts.started, tables: {}});
      hasStarted = true;
    } else if (msg) {
      msg.textContent = texts.running;
    }

    var baseFormData = new FormData(form);
    baseFormData.set('action', 'image_tick');
    if (!form.querySelector('input[name=force_refresh]').checked) {
      baseFormData.delete('force_refresh');
    }

    function pauseWithMessage(message) {
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.textContent = texts.resume_button;
      }
      if (msg) {
        msg.textContent = message || texts.error_retry;
      }
    }

    function loop() {
      tick(baseFormData).then(function(data) {
        consecutiveFailures = 0;
        updateStatus(data);
        if (data.status === 'done') {
          if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = texts.button;
          }
          return;
        }
        if (data.status === 'error') {
          pauseWithMessage(data.message);
          return;
        }
        setTimeout(loop, 50);
      }).catch(function() {
        consecutiveFailures++;
        if (consecutiveFailures <= maxAutoRetries) {
          if (msg) {
            msg.textContent = texts.error_retry + ' (' + consecutiveFailures + '/' + maxAutoRetries + ')';
          }
          setTimeout(loop, 1000 * consecutiveFailures);
          return;
        }
        pauseWithMessage(texts.error_retry);
      });
    }

    loop();
  });
})();
</script>
SCRIPT;

    $content .= '<h2>' . htmlspecialchars(t('ldraw_title')) . '</h2>';
    $content .= '<p>' . htmlspecialchars(t('ldraw_help')) . '</p>';
    $ldrawTools = ldrawToolsAvailable();
    if (!$ldrawTools['available']) {
        $content .= '<section class="card alert"><p>' . htmlspecialchars(t('ldraw_tools_unavailable', ['missing' => implode(', ', $ldrawTools['missing'])])) . '</p></section>';
    } else {
        $ldrawEnabled = isLdrawRenderingEnabled();
        $ldrawLibraryReady = isLdrawLibraryReady();

        $content .= '<form method="post" id="ldraw-form">';
        $content .= '<input type="hidden" name="action" value="update_ldraw_settings">';
        $content .= '<label class="checkbox-label"><input type="checkbox" id="ldraw-enabled-input" name="ldraw_enabled" value="1"' . ($ldrawEnabled ? ' checked' : '') . '> ' . htmlspecialchars(t('ldraw_enable_label')) . '</label>';
        $content .= '<p class="hint">' . htmlspecialchars(t('ldraw_enable_help')) . '</p>';
        $content .= '<button type="submit">' . htmlspecialchars(t('settings_rebrickable_save_button')) . '</button>';
        $content .= '</form>';

        $content .= '<div class="import-status" id="ldrawLibraryStatus" style="' . ($ldrawEnabled && !$ldrawLibraryReady ? '' : 'display:none;') . '">';
        $content .= '<div class="progress-message idle" id="ldrawLibraryMessage">' . htmlspecialchars(t('import_not_started')) . '</div>';
        $content .= '<div class="progress-track" id="ldrawLibraryProgress"><div class="progress-fill"></div></div>';
        $content .= '</div>';
        $content .= '<p class="hint" id="ldrawLibraryReadyNotice" style="' . ($ldrawEnabled && $ldrawLibraryReady ? '' : 'display:none;') . '">' . htmlspecialchars(t('ldraw_library_ready')) . '</p>';

        $ldrawLabelsJson = json_encode([
            'errorRetry' => t('import_error_retry'),
            'stagePrefix' => '',
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $ldrawAutoStart = $ldrawEnabled && !$ldrawLibraryReady ? 'true' : 'false';
        $content .= <<<SCRIPT
<script>
(function(){
  var texts = $ldrawLabelsJson;
  var statusWrap = document.getElementById("ldrawLibraryStatus");
  var readyNotice = document.getElementById("ldrawLibraryReadyNotice");
  var track = document.getElementById("ldrawLibraryProgress");
  var fill = track ? track.querySelector(".progress-fill") : null;
  var msg = document.getElementById("ldrawLibraryMessage");
  if (!statusWrap || !track || !fill || !msg) {
    return;
  }

  var consecutiveFailures = 0;
  var maxAutoRetries = 4;

  async function tick() {
    var formData = new FormData();
    formData.set('action', 'ldraw_library_tick');
    var response = await fetch('?page=settings', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    });
    if (!response.ok && response.status !== 500) {
      throw new Error('tick failed with status ' + response.status);
    }
    return await response.json();
  }

  function loop() {
    tick().then(function(data) {
      consecutiveFailures = 0;
      msg.classList.remove("idle");
      msg.textContent = data.message || '';
      fill.style.width = (data.percent || 0) + "%";
      if (data.status === 'done') {
        statusWrap.style.display = 'none';
        if (readyNotice) {
          readyNotice.style.display = '';
        }
        return;
      }
      if (data.status === 'error') {
        msg.textContent = data.message || texts.errorRetry;
        return;
      }
      setTimeout(loop, 50);
    }).catch(function() {
      consecutiveFailures++;
      if (consecutiveFailures <= maxAutoRetries) {
        setTimeout(loop, 1000 * consecutiveFailures);
        return;
      }
      msg.textContent = texts.errorRetry;
    });
  }

  if ($ldrawAutoStart) {
    statusWrap.style.display = '';
    loop();
  }
})();
</script>
SCRIPT;
    }

    $bricklinkPartSyncEnabled = getAppSetting('bricklink_part_sync_enabled', '0') === '1';
    $content .= '<h2>' . htmlspecialchars(t('settings_bricklink_part_sync_title')) . '</h2>';
    $content .= '<form method="post">';
    $content .= '<input type="hidden" name="action" value="update_bricklink_part_sync_settings">';
    $content .= '<label class="checkbox-label"><input type="checkbox" name="bricklink_part_sync_enabled" value="1"' . ($bricklinkPartSyncEnabled ? ' checked' : '') . '> ' . htmlspecialchars(t('settings_bricklink_part_sync_enable_label')) . '</label>';
    $content .= '<p class="hint">' . htmlspecialchars(t('settings_bricklink_part_sync_enable_help')) . '</p>';
    $content .= '<button type="submit">' . htmlspecialchars(t('settings_rebrickable_save_button')) . '</button>';
    $content .= '</form>';

    $content .= '<h2>' . htmlspecialchars(t('update_title')) . '</h2>';
    $content .= '<p>' . htmlspecialchars(t('update_current_version', ['version' => getCurrentVersion()])) . '</p>';
    $content .= '<p class="hint">' . htmlspecialchars(t('update_warning')) . '</p>';
    $content .= '<button type="button" id="update-check-button">' . htmlspecialchars(t('update_check_button')) . '</button>';
    $content .= '<div id="update-info" style="display:none; margin-top: 1rem;"></div>';
    $content .= '<div class="import-status" id="updateStatus" style="display:none;">';
    $content .= '<div class="progress-message" id="updateMessage"></div>';
    $content .= '<div class="progress-track" id="updateProgress"><div class="progress-fill"></div></div>';
    $content .= '</div>';
    $content .= '<button type="button" id="update-apply-button" style="display:none; margin-top: 1rem;">' . htmlspecialchars(t('update_apply_button')) . '</button>';
    $updateLabelsJson = json_encode([
        'checking' => t('update_checking'),
        'checkButton' => t('update_check_button'),
        'upToDate' => t('update_up_to_date'),
        'checkFailed' => t('update_check_failed'),
        'available' => t('update_available'),
        'applyButton' => t('update_apply_button'),
        'running' => t('update_running'),
        'errorRetry' => t('import_error_retry'),
        'resumeButton' => t('import_resume_button'),
        'stageDownloading' => t('update_stage_downloading'),
        'stageExtracting' => t('update_stage_extracting'),
        'stageDiffing' => t('update_stage_diffing'),
        'stageCopying' => t('update_stage_copying'),
        'stageDeleting' => t('update_stage_deleting'),
        'stageMigrating' => t('update_stage_migrating'),
        'stageDone' => t('update_stage_done'),
        'reloadNotice' => t('update_reload_notice'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $content .= <<<SCRIPT
<script>
(function(){
  var texts = $updateLabelsJson;
  var checkButton = document.getElementById("update-check-button");
  var infoBox = document.getElementById("update-info");
  var applyButton = document.getElementById("update-apply-button");
  var statusWrap = document.getElementById("updateStatus");
  var msg = document.getElementById("updateMessage");
  var track = document.getElementById("updateProgress");
  var fill = track ? track.querySelector(".progress-fill") : null;
  if (!checkButton || !infoBox || !applyButton || !statusWrap || !msg || !fill) {
    return;
  }

  function stageText(data) {
    switch (data.stage) {
      case "downloading":
        return texts.stageDownloading;
      case "extracting":
        return texts.stageExtracting;
      case "diffing":
        return texts.stageDiffing;
      case "copying":
        return texts.stageCopying + " (" + data.filesToCopyDone + "/" + data.filesToCopyTotal + ")";
      case "deleting":
        return texts.stageDeleting + " (" + data.filesToDeleteDone + "/" + data.filesToDeleteTotal + ")";
      case "migrating":
        return texts.stageMigrating;
      case "done":
        return texts.stageDone;
      default:
        return texts.running;
    }
  }

  checkButton.addEventListener("click", function() {
    checkButton.disabled = true;
    checkButton.textContent = texts.checking;
    infoBox.style.display = "none";

    var formData = new FormData();
    formData.set("action", "check_update");

    fetch("?page=settings", { method: "POST", body: formData, credentials: "same-origin" })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        checkButton.disabled = false;
        checkButton.textContent = texts.checkButton;
        infoBox.style.display = "block";
        if (data.available && data.release) {
          infoBox.textContent = texts.available.replace("{version}", data.release.version);
          applyButton.style.display = "inline-block";
        } else if (data.error) {
          infoBox.textContent = texts.checkFailed;
          applyButton.style.display = "none";
        } else {
          infoBox.textContent = texts.upToDate;
          applyButton.style.display = "none";
        }
      })
      .catch(function() {
        checkButton.disabled = false;
        checkButton.textContent = texts.checkButton;
        infoBox.style.display = "block";
        infoBox.textContent = texts.checkFailed;
      });
  });

  var consecutiveFailures = 0;
  var maxAutoRetries = 4;

  applyButton.addEventListener("click", function() {
    applyButton.disabled = true;
    applyButton.textContent = texts.running;
    statusWrap.style.display = "block";
    msg.textContent = texts.running;
    fill.style.width = "0%";

    var formData = new FormData();
    formData.set("action", "update_tick");

    function pauseWithMessage(message) {
      applyButton.disabled = false;
      applyButton.textContent = texts.resumeButton;
      msg.textContent = message || texts.errorRetry;
    }

    function loop() {
      fetch("?page=settings", { method: "POST", body: formData, credentials: "same-origin" })
        .then(function(r) {
          if (!r.ok && r.status !== 500) {
            throw new Error("tick failed with status " + r.status);
          }
          return r.json();
        })
        .then(function(data) {
          consecutiveFailures = 0;
          if (data.status === "error") {
            pauseWithMessage(data.message);
            return;
          }
          fill.style.width = (data.percent || 0) + "%";
          msg.textContent = stageText(data);
          if (data.status === "done") {
            msg.textContent = texts.reloadNotice;
            setTimeout(function() { window.location.reload(); }, 800);
            return;
          }
          setTimeout(loop, 50);
        })
        .catch(function() {
          consecutiveFailures++;
          if (consecutiveFailures <= maxAutoRetries) {
            msg.textContent = texts.errorRetry + " (" + consecutiveFailures + "/" + maxAutoRetries + ")";
            setTimeout(loop, 1000 * consecutiveFailures);
            return;
          }
          pauseWithMessage(texts.errorRetry);
        });
    }

    loop();
  });
})();
</script>
SCRIPT;

    // "Bauanleitungen" condition criteria (src/instruction_manuals.php) —
    // fully user-manageable, not admin-gated (the criteria catalog is
    // personal-collection data, same footing as the other settings above,
    // not a multi-user admin concern like the user list below).
    $editCriterionId = isset($_GET['edit_criterion']) ? (int) $_GET['edit_criterion'] : null;
    $editCriterion = null;
    $allInstructionCriteria = getInstructionManualCriteria($pdo);
    if ($editCriterionId !== null) {
        foreach ($allInstructionCriteria as $criterionRow) {
            if ($criterionRow['id'] === $editCriterionId) {
                $editCriterion = $criterionRow;
                break;
            }
        }
    }

    $content .= '<h2>' . htmlspecialchars(t('instruction_manual_criteria_settings_title')) . '</h2>';
    $content .= '<p>' . htmlspecialchars(t('instruction_manual_criteria_settings_help')) . '</p>';
    if ($instructionCriteriaMessage !== '') {
        $content .= '<p><strong>' . htmlspecialchars($instructionCriteriaMessage) . '</strong></p>';
    }

    $content .= '<ul class="admin-user-list">';
    foreach ($allInstructionCriteria as $criterionRow) {
        $usageCount = getInstructionManualCriterionUsageCount($pdo, $criterionRow['id']);
        $deleteConfirmText = $usageCount > 0
            ? t('instruction_manual_criterion_delete_confirm_in_use', ['count' => (string) $usageCount])
            : t('instruction_manual_criterion_delete_confirm');
        $content .= '<li>';
        $content .= htmlspecialchars($criterionRow['label']);
        $content .= ' <span class="hint">(' . htmlspecialchars(t('instruction_manual_criterion_usage_count', ['count' => (string) $usageCount])) . ')</span>';
        $content .= ' <a href="?page=settings&edit_criterion=' . $criterionRow['id'] . '">' . htmlspecialchars(t('location_edit_link')) . '</a>';
        $content .= ' <form method="post" style="display:inline;" onsubmit="return confirm(' . htmlspecialchars(json_encode($deleteConfirmText), ENT_QUOTES) . ');">';
        $content .= '<input type="hidden" name="action" value="delete_instruction_manual_criterion">';
        $content .= '<input type="hidden" name="id" value="' . $criterionRow['id'] . '">';
        $content .= '<button type="submit">' . htmlspecialchars(t('location_delete_link')) . '</button>';
        $content .= '</form>';
        $content .= '</li>';
    }
    $content .= '</ul>';

    if ($editCriterion !== null) {
        $content .= '<h3>' . htmlspecialchars(t('instruction_manual_criterion_edit_heading')) . '</h3>';
        $content .= '<form method="post">';
        $content .= '<input type="hidden" name="action" value="update_instruction_manual_criterion">';
        $content .= '<input type="hidden" name="id" value="' . $editCriterion['id'] . '">';
        $content .= '<label>' . htmlspecialchars(t('instruction_manual_criterion_label_label')) . '<input name="label" value="' . htmlspecialchars($editCriterion['label']) . '" required></label>';
        $content .= '<button type="submit">' . htmlspecialchars(t('location_save_button')) . '</button>';
        $content .= ' <a href="?page=settings">' . htmlspecialchars(t('location_cancel_edit')) . '</a>';
        $content .= '</form>';
    } else {
        $content .= '<h3>' . htmlspecialchars(t('instruction_manual_criterion_add_heading')) . '</h3>';
        $content .= '<form method="post">';
        $content .= '<input type="hidden" name="action" value="add_instruction_manual_criterion">';
        $content .= '<label>' . htmlspecialchars(t('instruction_manual_criterion_label_label')) . '<input name="label" required></label>';
        $content .= '<button type="submit">' . htmlspecialchars(t('instruction_manual_criterion_add_button')) . '</button>';
        $content .= '</form>';
    }

    if ($user['is_admin']) {
        $content .= '<h2>' . htmlspecialchars(t('admin_users_title')) . '</h2>';
        if ($adminUserMessage !== '') {
            $content .= '<p><strong>' . htmlspecialchars($adminUserMessage) . '</strong></p>';
        }

        $allUsers = $pdo->query('SELECT username, email, full_name, is_admin FROM users ORDER BY username ASC')->fetchAll();
        $content .= '<ul class="admin-user-list">';
        foreach ($allUsers as $listedUser) {
            $content .= '<li>' . htmlspecialchars($listedUser['username']);
            if ($listedUser['full_name'] !== null && $listedUser['full_name'] !== '') {
                $content .= ' – ' . htmlspecialchars($listedUser['full_name']);
            }
            if ($listedUser['email'] !== null && $listedUser['email'] !== '') {
                $content .= ' (' . htmlspecialchars($listedUser['email']) . ')';
            }
            if ((bool) $listedUser['is_admin']) {
                $content .= ' <span class="admin-badge">' . htmlspecialchars(t('admin_badge')) . '</span>';
            }
            $content .= '</li>';
        }
        $content .= '</ul>';

        $content .= '<h3>' . htmlspecialchars(t('admin_user_add_heading')) . '</h3>';
        $content .= '<form method="post">';
        $content .= '<input type="hidden" name="action" value="admin_create_user">';
        $content .= '<label>' . htmlspecialchars(t('admin_user_full_name_label')) . '<input name="full_name" autocomplete="off" required></label>';
        $content .= '<label>' . htmlspecialchars(t('admin_user_username_label')) . '<input name="username" autocomplete="off" required></label>';
        $content .= '<label>' . htmlspecialchars(t('admin_user_email_label')) . '<input type="email" name="email" autocomplete="off"></label>';
        $content .= '<label>' . htmlspecialchars(t('admin_user_password_label')) . '<input type="password" name="password" autocomplete="new-password" required></label>';
        $content .= '<label>' . htmlspecialchars(t('admin_user_password_confirm_label')) . '<input type="password" name="password_confirm" autocomplete="new-password" required></label>';
        $content .= '<label class="checkbox-label"><input type="checkbox" name="is_admin" value="1"> ' . htmlspecialchars(t('admin_user_is_admin_label')) . '</label>';
        $content .= '<button type="submit">' . htmlspecialchars(t('admin_user_add_button')) . '</button>';
        $content .= '</form>';
    }

    renderApp(t('settings_title'), $content, $user, computeAppStats($pdo), [homeBreadcrumb(), ['label' => t('settings_title'), 'url' => null]]);
    exit;
}

if (isset($_GET['page']) && $_GET['page'] === 'locations') {
    $editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
    $editLocation = $editId !== null ? getStorageLocation($editId) : null;
    $isEdit = $editLocation !== null;

    // No <h1> here (removed per explicit follow-up request, to save
    // vertical space for the tree/content split view below) — the page
    // title/breadcrumb (both still "Mein Lager", via t('locations_title'))
    // already say where you are.
    $content = '';
    if ($locationMessage !== '') {
        $content .= '<p><strong>' . htmlspecialchars($locationMessage) . '</strong></p>';
    }

    // Edit form only — the "add a new top-level location" toggle/form was
    // removed per feedback. action=add_location (src/routes/actions.php)
    // stays as a valid, working backend action even with no UI entry point
    // here right now, in case a different one (e.g. a "+" in the tree)
    // replaces it later.
    if ($isEdit) {
        $editParentIdValue = $editLocation['parent_id'] !== null ? (int) $editLocation['parent_id'] : '';
        $content .= '<details class="location-add-form-details" open>';
        $content .= '<summary>' . htmlspecialchars(t('location_edit_title')) . '</summary>';
        $content .= '<form method="post" id="location-form">';
        $content .= '<input type="hidden" name="action" value="rename_location">';
        $content .= '<input type="hidden" name="location_id" value="' . (int) $editLocation['id'] . '">';
        $content .= '<label>' . htmlspecialchars(t('location_name_label')) . '<input name="name" value="' . htmlspecialchars($editLocation['name']) . '" required></label>';
        $content .= '<label>' . htmlspecialchars(t('location_move_parent_label')) . '</label>';
        $content .= '<div id="location-edit-move-picker" class="location-picker"></div>';
        $content .= '<input type="hidden" name="parent_id" id="location-edit-move-parent-id" value="' . $editParentIdValue . '">';
        $content .= '<button type="submit">' . htmlspecialchars(t('location_save_button')) . '</button>';
        $content .= ' <a href="?page=locations">' . htmlspecialchars(t('location_cancel_edit')) . '</a>';
        $content .= '</form></details>';

        // Own small script/texts payload rather than reusing the bigger
        // explorer script further down — that one's built around the tree
        // JSON and only runs once the explorer's own DOM exists; this picker
        // needs to work standalone the moment the edit form renders, and
        // only needs the four generic picker labels.
        $editMovePickerLabelsJson = json_encode([
            'levelLabel' => t('location_picker_level_label'),
            'rootLabel' => t('location_picker_root_label'),
            'selectPlaceholder' => t('add_stock_select_placeholder'),
            'noChildren' => t('add_stock_no_children'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $content .= <<<SCRIPT
<script>
(function(){
  var texts = {$editMovePickerLabelsJson};
  var pickerEl = document.getElementById('location-edit-move-picker');
  var parentIdField = document.getElementById('location-edit-move-parent-id');
  if (!pickerEl || !parentIdField || !window.createLocationPicker) {
    return;
  }
  window.createLocationPicker(pickerEl, texts, function(value) {
    parentIdField.value = value === null ? '' : value;
  }, parentIdField.value || undefined);
})();
</script>
SCRIPT;
    }

    // Explorer split view: left pane is a client-built tree (from the JSON
    // below, see getStorageLocationTree() — already excludes owned-set
    // instance locations, same as before this redesign) with expand/collapse
    // entirely in the browser, no round trip; right pane is loaded on click
    // via action=location_content, since a location's full recursive content
    // (see getLocationContentRecursive()) isn't worth shipping for every
    // location up front. Every real top-level location nests under one
    // static, non-clickable "Lager" root (id null — not a real
    // storage_locations row, purely a grouping label the client special-cases)
    // so the tree always has a single, always-expanded starting point.
    $tree = getStorageLocationTree();
    $treeRoot = [
        'id' => null,
        'name' => t('location_tree_root_label'),
        'location_type' => null,
        'children' => $tree,
    ];
    $treeJson = json_encode($treeRoot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $content .= '<div class="location-explorer" id="location-explorer">';
    $content .= '<div class="location-explorer-tree-pane" id="location-explorer-tree-pane">';
    $content .= '<div class="location-tree-explorer" id="location-tree-explorer"></div>';
    $content .= '<form method="post" id="location-delete-form" style="display:none;">';
    $content .= '<input type="hidden" name="action" value="delete_location">';
    $content .= '<input type="hidden" name="location_id" id="location-delete-form-id" value="">';
    $content .= '</form>';
    $content .= '</div>';

    $content .= '<div class="location-explorer-resize-handle" id="location-explorer-resize-handle" role="separator" aria-orientation="vertical" tabindex="0">' . getActionIcon('resize_handle') . '</div>';

    $content .= '<div class="location-explorer-content-pane" id="location-explorer-content-pane">';
    $content .= '<div id="location-explorer-content"><p class="hint">' . htmlspecialchars(t('location_explorer_select_hint')) . '</p></div>';
    $content .= '</div>';
    $content .= '</div>';

    // Reached via the "(Neu)" row every tree node gets (see buildRow() below)
    // — one shared modal, not one per node; opening it just points the
    // hidden parent_id field at whichever node was clicked. A plain form
    // POST (action=add_location, unchanged since before this page's
    // redesign), not fetch — the simplest way to have the tree reflect the
    // new location afterward is the page reloading normally.
    $content .= '<div class="modal-overlay" id="location-add-modal" style="display:none;">';
    $content .= '<div class="modal-box"><button type="button" class="modal-close" id="location-add-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $content .= '<h2 id="location-add-modal-heading"></h2>';
    $content .= '<form method="post" id="location-add-form">';
    $content .= '<input type="hidden" name="action" value="add_location">';
    $content .= '<input type="hidden" name="parent_id" id="location-add-parent-id" value="">';
    $content .= '<label>' . htmlspecialchars(t('location_name_label')) . '<input name="name" id="location-add-name" required></label>';
    $content .= '<p class="hint" id="location-add-name-hint"></p>';
    $content .= '<label class="checkbox-label"><input type="checkbox" id="location-add-bulk-toggle" name="bulk_enabled" value="1"> ' . htmlspecialchars(t('location_bulk_toggle_label')) . '</label>';
    $content .= '<div id="location-add-bulk-fields" style="display:none;">';
    $content .= '<label>' . htmlspecialchars(t('location_bulk_count_label')) . '<input type="number" name="child_count" min="1" value="1" id="location-add-bulk-count"></label>';
    $content .= '</div>';
    $content .= '<button type="submit">' . htmlspecialchars(t('location_add_button')) . '</button>';
    $content .= '</form></div></div>';

    // Per-card "edit" modal (quantity + optional new location) — one shared
    // instance, populated from whichever card was clicked via JS, same
    // pattern as location-add-modal above. Submitted via fetch (not a plain
    // POST) so the content pane can refresh in place instead of a full page
    // reload losing the tree's expand state and scroll position.
    $content .= '<div class="modal-overlay" id="location-item-edit-modal" style="display:none;">';
    $content .= '<div class="modal-box"><button type="button" class="modal-close" id="location-item-edit-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $content .= '<h2 id="location-item-edit-heading">' . htmlspecialchars(t('location_item_edit_title')) . '</h2>';
    $content .= '<p id="location-item-edit-subtitle" class="hint"></p>';
    $content .= '<p class="location-item-edit-current"><strong>' . htmlspecialchars(t('location_item_current_location_label')) . ':</strong> <span id="location-item-edit-current-path"></span></p>';
    $content .= '<form id="location-item-edit-form">';
    $content .= '<div id="location-item-edit-message" class="add-stock-message"></div>';
    $content .= '<label id="location-item-edit-quantity-row">' . htmlspecialchars(t('add_stock_quantity_label')) . '<input type="number" name="quantity" id="location-item-edit-quantity" min="0" required></label>';
    $content .= '<label>' . htmlspecialchars(t('location_item_new_location_label')) . '</label>';
    $content .= '<div id="location-item-edit-picker" class="location-picker"></div>';
    $content .= '<button type="submit">' . htmlspecialchars(t('location_save_button')) . '</button>';
    // Minifig instances only (see openItemEditModal()) — no quantity concept
    // to "set to 0" as an implicit delete, so removal needs its own explicit
    // control.
    $content .= '<button type="button" id="location-item-edit-delete" style="display:none;">' . htmlspecialchars(t('location_detail_minifig_delete_button')) . '</button>';
    $content .= '</form></div></div>';

    // Multi-select bulk relocate modal — the floating selection bar's
    // "Umlagern" button opens this, target picked once for every currently
    // selected card at once.
    $content .= '<div class="modal-overlay" id="location-bulk-relocate-modal" style="display:none;">';
    $content .= '<div class="modal-box"><button type="button" class="modal-close" id="location-bulk-relocate-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $content .= '<h2>' . htmlspecialchars(t('location_bulk_relocate_title')) . '</h2>';
    $content .= '<form id="location-bulk-relocate-form">';
    $content .= '<div id="location-bulk-relocate-message" class="add-stock-message"></div>';
    $content .= '<label>' . htmlspecialchars(t('location_bulk_relocate_target_label')) . '</label>';
    $content .= '<div id="location-bulk-relocate-picker" class="location-picker"></div>';
    $content .= '<button type="submit">' . htmlspecialchars(t('location_bulk_relocate_confirm_button')) . '</button>';
    $content .= '</form></div></div>';

    // "Bauanleitungen" (src/instruction_manuals.php) — the add-tile's mini
    // form. One shared instance, populated with whichever location's add
    // tile was clicked; set selection is a small live search (no existing
    // AJAX set-search endpoint to reuse, see action=search_sets_for_instructions,
    // src/routes/actions.php) rather than a full picker page, since this is
    // meant to stay a quick popover.
    $content .= '<div class="modal-overlay" id="location-instruction-add-modal" style="display:none;">';
    $content .= '<div class="modal-box"><button type="button" class="modal-close" id="location-instruction-add-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $content .= '<h2>' . htmlspecialchars(t('instruction_manual_add_heading')) . '</h2>';
    $content .= '<form id="location-instruction-add-form">';
    $content .= '<div id="location-instruction-add-message" class="add-stock-message"></div>';
    $content .= '<label>' . htmlspecialchars(t('instruction_manual_add_set_search_label')) . '<input type="text" id="location-instruction-add-set-search" autocomplete="off" placeholder="' . htmlspecialchars(t('instruction_manual_add_set_search_placeholder')) . '"></label>';
    $content .= '<div id="location-instruction-add-set-results" class="instruction-manual-set-results"></div>';
    $content .= '<div id="location-instruction-add-selected-set" class="instruction-manual-selected-set" style="display:none;"></div>';
    $content .= '<div class="hint">' . htmlspecialchars(t('instruction_manual_field_condition')) . '</div>';
    $content .= '<div id="location-instruction-add-criteria"></div>';
    $content .= '<label>' . htmlspecialchars(t('instruction_manual_field_notes')) . '<textarea id="location-instruction-add-notes" rows="2"></textarea></label>';
    $content .= '<button type="submit" id="location-instruction-add-submit" disabled>' . htmlspecialchars(t('instruction_manual_add_button')) . '</button>';
    $content .= '</form></div></div>';

    // "Bauanleitungen" detail modal — implemented inline here (not via
    // part_modal.php's shared instance) since manuals only ever surface from
    // this one screen. No "move" affordance: a manual's location is always
    // auto-derived from its set's theme (addInstructionManual()).
    $content .= '<div class="modal-overlay" id="location-instruction-detail-modal" style="display:none;">';
    $content .= '<div class="modal-box"><button type="button" class="modal-close" id="location-instruction-detail-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
    $content .= '<div id="location-instruction-detail-content"></div>';
    $content .= '</div></div>';

    // Floating bar shown whenever the loaded location has any selectable
    // card (not just once something's actually selected — "Alle auswählen"
    // needs to be reachable before any single item is picked) — count plus
    // "Alle auswählen"/"Umlagern"/"Auswahl aufheben". Fixed-position, built
    // once here rather than per-selection to avoid rebuilding it on every
    // checkbox click; "Umlagern" itself is disabled (not hidden) at 0
    // selected, since relocating nothing doesn't make sense.
    $content .= '<div class="location-bulk-bar" id="location-bulk-bar" hidden>';
    $content .= '<span id="location-bulk-bar-count"></span>';
    $content .= '<button type="button" id="location-bulk-bar-select-all">' . htmlspecialchars(t('location_select_all_button')) . '</button>';
    $content .= '<button type="button" id="location-bulk-bar-relocate">' . htmlspecialchars(t('location_bulk_relocate_button')) . '</button>';
    $content .= '<button type="button" id="location-bulk-bar-clear">' . htmlspecialchars(t('location_bulk_clear_selection')) . '</button>';
    $content .= '</div>';

    $explorerLabelsJson = json_encode([
        'chevronIcon' => getActionIcon('chevron_right'),
        'editIcon' => getActionIcon('edit'),
        'deleteIcon' => getActionIcon('delete'),
        'brickIcon' => getNavIcon('bricks'),
        'minifigIcon' => getNavIcon('minifigs'),
        'setIcon' => getNavIcon('sets'),
        'pickListIcon' => getActionIcon('pick_list'),
        'instructionsIcon' => getActionIcon('instructions'),
        'expandLabel' => t('locations_tree_expand_label'),
        'editLabel' => t('location_edit_link'),
        'deleteLabel' => t('location_delete_link'),
        'deleteConfirm' => t('location_delete_confirm'),
        'loading' => t('location_explorer_loading'),
        'errorRetry' => t('import_error_retry'),
        'contentEmpty' => t('location_detail_empty'),
        'groupMinifigs' => t('location_content_group_minifigs'),
        'minifigsEmpty' => t('location_content_minifigs_empty'),
        'conditionNew' => t('condition_new'),
        'conditionUsed' => t('condition_used'),
        // Reuses the set-detail page's own LDraw render-progress wording
        // (see renderLdrawRenderOverlay() in src/ldraw.php) — same
        // {part}/{count} template, left unsubstituted here for the same
        // reason: filled in client-side on every poll.
        'ldrawCurrent' => t('ldraw_set_render_current'),
        'ldrawWaiting' => t('ldraw_set_render_waiting'),
        'addIcon' => getActionIcon('add'),
        'newChildLabel' => t('locations_tree_new_child_label'),
        'addModalHeading' => t('location_add_modal_heading'),
        'bulkNameHint' => t('location_bulk_name_hint'),
        'bulkNamingDefault' => t('location_bulk_naming_default'),
        'selectLabel' => t('location_item_select_label'),
        'updateFailed' => t('location_item_update_failed'),
        'minifigDeleteConfirm' => t('location_detail_minifig_delete_confirm'),
        'bulkBarCount' => t('location_bulk_bar_count'),
        'bulkRelocateFailed' => t('location_bulk_relocate_failed'),
        'levelLabel' => t('location_picker_level_label'),
        'rootLabel' => t('location_picker_root_label'),
        'selectPlaceholder' => t('add_stock_select_placeholder'),
        'noChildren' => t('add_stock_no_children'),
        'thumbnailUnverifiedTitle' => t('location_content_thumbnail_unverified'),
        'hereLabel' => t('location_content_here_label'),
        'setReadOnlyNote' => t('location_content_set_readonly'),
        'recursiveToggleLabel' => t('location_content_recursive_toggle'),
        'stocktakeFlagLabel' => t('stocktake_flag_label'),
        'openSetDetailsLink' => t('location_content_open_set_details'),
        'instructionsAddTileLabel' => t('instruction_manual_add_tile_label'),
        'instructionPercentTooltip' => t('instruction_manual_percent_tooltip'),
        'instructionAddHeading' => t('instruction_manual_add_heading'),
        'instructionSetSearchNoResults' => t('instruction_manual_add_set_search_no_results'),
        'instructionAddSubmitFailed' => t('instruction_manual_add_failed'),
        'instructionFieldCondition' => t('instruction_manual_field_condition'),
        'instructionIsNewLabel' => t('instruction_manual_criterion_is_new'),
        'instructionGradeTooltip' => t('instruction_manual_grade_tooltip'),
        'instructionGradeNewTooltip' => t('instruction_manual_grade_new_tooltip'),
        'instructionDetailTabDetails' => t('instruction_manual_tab_details'),
        'instructionDetailTabParts' => t('instruction_manual_tab_parts'),
        'instructionDetailTabPrices' => t('instruction_manual_tab_prices'),
        'instructionOpenSetLink' => t('instruction_manual_open_set_link'),
        'instructionSaveButton' => t('location_save_button'),
        'instructionDeleteButton' => t('instruction_manual_delete_button'),
        'instructionDeleteConfirm' => t('instruction_manual_delete_confirm'),
        'instructionFieldTotal' => t('instruction_manual_field_total'),
        'instructionFieldExclusive' => t('set_detail_field_exclusive'),
        'instructionFieldRare' => t('set_detail_field_rare'),
        'instructionFieldMinifigs' => t('instruction_manual_field_minifigs'),
        'instructionPriceSetLabel' => t('instruction_manual_price_set_label'),
        'instructionPriceInstructionsLabel' => t('instruction_manual_price_instructions_label'),
        'instructionPriceNewLabel' => t('instruction_manual_price_new_label'),
        'instructionPriceUsedLabel' => t('instruction_manual_price_used_label'),
        'instructionTileSetPriceTooltip' => t('instruction_manual_tile_set_price_tooltip'),
        'bricklinkPriceNever' => t('owned_set_bricklink_price_never'),
        'bricklinkPriceUpdatedTitle' => t('owned_set_bricklink_price_updated_title'),
        'bricklinkPriceRefreshLabel' => t('owned_set_bricklink_price_refresh_label'),
        'bricklinkPriceRefreshFailed' => t('owned_set_bricklink_price_refresh_failed'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $content .= <<<SCRIPT
<script>
(function(){
  var treeRoot = $treeJson;
  var texts = $explorerLabelsJson;
  var treeContainer = document.getElementById('location-tree-explorer');
  var contentEl = document.getElementById('location-explorer-content');
  var deleteForm = document.getElementById('location-delete-form');
  var deleteFormId = document.getElementById('location-delete-form-id');
  var explorer = document.getElementById('location-explorer');
  var treePane = document.getElementById('location-explorer-tree-pane');
  var resizeHandle = document.getElementById('location-explorer-resize-handle');
  var addModal = document.getElementById('location-add-modal');
  var addModalClose = document.getElementById('location-add-modal-close');
  var addModalHeading = document.getElementById('location-add-modal-heading');
  var addParentIdField = document.getElementById('location-add-parent-id');
  var itemEditModal = document.getElementById('location-item-edit-modal');
  var itemEditModalClose = document.getElementById('location-item-edit-modal-close');
  var itemEditSubtitle = document.getElementById('location-item-edit-subtitle');
  var itemEditCurrentPath = document.getElementById('location-item-edit-current-path');
  var itemEditForm = document.getElementById('location-item-edit-form');
  var itemEditMessage = document.getElementById('location-item-edit-message');
  var itemEditQuantity = document.getElementById('location-item-edit-quantity');
  var itemEditQuantityRow = document.getElementById('location-item-edit-quantity-row');
  var itemEditDeleteBtn = document.getElementById('location-item-edit-delete');
  var itemEditPicker = document.getElementById('location-item-edit-picker');
  var bulkRelocateModal = document.getElementById('location-bulk-relocate-modal');
  var bulkRelocateModalClose = document.getElementById('location-bulk-relocate-modal-close');
  var bulkRelocateForm = document.getElementById('location-bulk-relocate-form');
  var bulkRelocateMessage = document.getElementById('location-bulk-relocate-message');
  var bulkRelocatePicker = document.getElementById('location-bulk-relocate-picker');
  var bulkBar = document.getElementById('location-bulk-bar');
  var bulkBarCount = document.getElementById('location-bulk-bar-count');
  var bulkBarSelectAllBtn = document.getElementById('location-bulk-bar-select-all');
  var bulkBarRelocateBtn = document.getElementById('location-bulk-bar-relocate');
  var bulkBarClearBtn = document.getElementById('location-bulk-bar-clear');
  var instructionAddModal = document.getElementById('location-instruction-add-modal');
  var instructionAddModalClose = document.getElementById('location-instruction-add-modal-close');
  var instructionAddForm = document.getElementById('location-instruction-add-form');
  var instructionAddMessage = document.getElementById('location-instruction-add-message');
  var instructionAddSetSearch = document.getElementById('location-instruction-add-set-search');
  var instructionAddSetResults = document.getElementById('location-instruction-add-set-results');
  var instructionAddSelectedSet = document.getElementById('location-instruction-add-selected-set');
  var instructionAddCriteriaContainer = document.getElementById('location-instruction-add-criteria');
  var instructionAddNotes = document.getElementById('location-instruction-add-notes');
  var instructionAddSubmit = document.getElementById('location-instruction-add-submit');
  var instructionDetailModal = document.getElementById('location-instruction-detail-modal');
  var instructionDetailModalClose = document.getElementById('location-instruction-detail-modal-close');
  var instructionDetailContent = document.getElementById('location-instruction-detail-content');
  if (!contentEl) {
    return;
  }

  // Declared here (not inside the if below) so buildNewChildRow() can call
  // it regardless of scope order — stays a no-op if the modal's own markup
  // is somehow missing from the page.
  var openAddModal = function() {};

  if (addModal && addModalClose && addModalHeading && addParentIdField) {
    var closeAddModal = function() {
      addModal.style.display = 'none';
    };
    openAddModal = function(parentId, parentName) {
      addParentIdField.value = parentId === null ? '' : parentId;
      addModalHeading.textContent = texts.addModalHeading.replace('{parent}', parentName);
      if (addBulkToggle && addBulkFields && addNameInput && addNameHint) {
        addBulkToggle.checked = false;
        addBulkFields.style.display = 'none';
        addNameInput.value = '';
        addNameHint.textContent = '';
      }
      addModal.style.display = 'flex';
    };
    addModalClose.addEventListener('click', closeAddModal);
  }

  var addBulkToggle = document.getElementById('location-add-bulk-toggle');
  var addBulkFields = document.getElementById('location-add-bulk-fields');
  var addNameInput = document.getElementById('location-add-name');
  var addNameHint = document.getElementById('location-add-name-hint');
  if (addBulkToggle && addBulkFields && addNameInput && addNameHint) {
    addBulkToggle.addEventListener('change', function() {
      addBulkFields.style.display = addBulkToggle.checked ? 'block' : 'none';
      addNameHint.textContent = addBulkToggle.checked ? texts.bulkNameHint : '';
      // No separate "naming pattern" field — bulk mode uses the name field
      // itself as the pattern (e.g. "Fach {n}"), so pre-fill a sensible
      // default when there's nothing there yet rather than leaving it blank;
      // never overwrites something the user already typed.
      if (addBulkToggle.checked && addNameInput.value === '') {
        addNameInput.value = texts.bulkNamingDefault;
      }
    });
  }

  // Which nodes were expanded, so add/delete/edit — all plain form POSTs
  // that reload the whole page — don't collapse the tree back to its
  // just-loaded default and force re-opening the same path again. Cleared
  // when the tab closes (sessionStorage), not meant to persist forever.
  var EXPANDED_STORAGE_KEY = 'studsphere_location_tree_expanded';
  var expandedIds = (function() {
    try {
      var raw = window.sessionStorage.getItem(EXPANDED_STORAGE_KEY);
      return raw ? new Set(JSON.parse(raw)) : new Set();
    } catch (e) {
      return new Set();
    }
  })();
  function saveExpandedIds() {
    try {
      window.sessionStorage.setItem(EXPANDED_STORAGE_KEY, JSON.stringify(Array.from(expandedIds)));
    } catch (e) {
      // Private browsing / storage disabled — expand state just won't
      // survive a reload, nothing else depends on this succeeding.
    }
  }

  // "Auch untergeordnete Lagerorte anzeigen" — persists across page loads
  // (localStorage, not sessionStorage like expandedIds above: this is a
  // genuine display preference, not transient per-tab UI state), per
  // explicit follow-up request. Defaults to true (the app's original
  // always-recursive behavior) so nothing changes for anyone who's never
  // touched the toggle.
  var RECURSIVE_STORAGE_KEY = 'studsphere_location_content_recursive';
  var recursiveEnabled = (function() {
    try {
      var raw = window.localStorage.getItem(RECURSIVE_STORAGE_KEY);
      return raw === null ? true : raw === '1';
    } catch (e) {
      return true;
    }
  })();
  function setRecursiveEnabled(value) {
    recursiveEnabled = value;
    try {
      window.localStorage.setItem(RECURSIVE_STORAGE_KEY, value ? '1' : '0');
    } catch (e) {
      // Private browsing / storage disabled — just won't persist.
    }
  }

  var selectedRow = null;

  function selectLocation(id, name, row) {
    if (selectedRow) {
      selectedRow.classList.remove('location-tree-row-selected');
    }
    row.classList.add('location-tree-row-selected');
    selectedRow = row;
    loadContent(id, name);
  }

  // Walks the already-loaded tree (same data the left pane is built from)
  // to find a card's full breadcrumb path — cheaper and simpler than a
  // dedicated round trip, since the whole (non-owned-set) location tree is
  // already sitting in memory as treeRoot.
  function findLocationPath(id) {
    var target = String(id);
    function walk(node, trail) {
      var nextTrail = node.id === null ? trail : trail.concat([node.name]);
      if (node.id !== null && String(node.id) === target) {
        return nextTrail;
      }
      for (var i = 0; i < (node.children || []).length; i++) {
        var found = walk(node.children[i], nextTrail);
        if (found) {
          return found;
        }
      }
      return null;
    }
    var trail = walk(treeRoot, []);
    return trail ? trail.join(' \\u203a ') : '';
  }

  // Multi-select state for the "Umlagern" bulk bar — keyed so the same
  // card toggles the same entry regardless of which grid rebuild it came
  // from (categories reload wholesale on every loadContent()).
  var selectedItems = {};
  // Every currently-rendered selectable card (key/descriptor/checkbox),
  // rebuilt alongside selectedItems on every renderContent() — "Alle
  // auswählen" walks this rather than the DOM, since a checkbox alone
  // doesn't carry the descriptor addCardSelectAndActivate() built it from.
  var allSelectableItems = [];
  // Set by renderContent() from data.readOnly — true while viewing a boxed
  // set's own auto-generated node (see action=location_content's own doc
  // comment, src/routes/actions.php): cards render without the
  // select/click-to-edit affordance, since moving stock out through the
  // generic actions would desync that set's own completeness tracking.
  var currentReadOnly = false;

  function itemKey(item) {
    return item.kind === 'minifig'
      ? 'minifig:' + item.instanceId
      : 'part:' + item.locationId + ':' + item.partId + ':' + item.colorId + ':' + item.conditionType;
  }

  function updateBulkBar() {
    var count = Object.keys(selectedItems).length;
    if (!bulkBar) {
      return;
    }
    // Visible whenever there's anything selectable at all — not just once
    // something's actually picked — so "Alle auswählen" stays reachable
    // before the first individual selection.
    bulkBar.hidden = allSelectableItems.length === 0;
    if (bulkBarCount) {
      bulkBarCount.textContent = texts.bulkBarCount.replace('{count}', String(count));
    }
    if (bulkBarRelocateBtn) {
      bulkBarRelocateBtn.disabled = count === 0;
    }
  }

  function clearSelection() {
    selectedItems = {};
    Array.prototype.forEach.call(contentEl.querySelectorAll('.location-detail-card-select'), function(cb) {
      cb.checked = false;
    });
    updateBulkBar();
  }

  if (bulkBarSelectAllBtn) {
    bulkBarSelectAllBtn.addEventListener('click', function() {
      allSelectableItems.forEach(function(entry) {
        selectedItems[entry.key] = entry.descriptor;
        entry.checkbox.checked = true;
      });
      updateBulkBar();
    });
  }

  if (bulkBarClearBtn) {
    bulkBarClearBtn.addEventListener('click', clearSelection);
  }

  var currentLocationId = null;
  var currentLocationName = null;

  function refreshContent() {
    if (currentLocationId !== null) {
      loadContent(currentLocationId, currentLocationName);
    }
  }

  function buildGroup(title, bodyEl) {
    var section = document.createElement('section');
    section.className = 'location-content-group';
    var header = document.createElement('div');
    header.className = 'location-content-group-header';
    var h = document.createElement('h3');
    h.textContent = title;
    header.appendChild(h);
    section.appendChild(header);
    section.appendChild(bodyEl);
    return section;
  }

  function addCardSelectAndActivate(card, descriptor, activate) {
    var checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.className = 'location-detail-card-select';
    checkbox.setAttribute('aria-label', texts.selectLabel);
    var key = itemKey(descriptor);
    checkbox.checked = !!selectedItems[key];
    allSelectableItems.push({ key: key, descriptor: descriptor, checkbox: checkbox });
    checkbox.addEventListener('click', function(e) {
      e.stopPropagation();
    });
    checkbox.addEventListener('keydown', function(e) {
      e.stopPropagation();
    });
    checkbox.addEventListener('change', function() {
      if (checkbox.checked) {
        selectedItems[key] = descriptor;
      } else {
        delete selectedItems[key];
      }
      updateBulkBar();
    });
    card.appendChild(checkbox);

    card.tabIndex = 0;
    card.setAttribute('role', 'button');
    card.addEventListener('click', activate);
    card.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        activate();
      }
    });
  }

  function buildOnePartCard(item) {
    var card = document.createElement('div');
    card.className = 'location-detail-card';
    // Marks a card whose item is inside a nested set (not the whole
    // response, which currentReadOnly already covers) — a mixed grid can
    // otherwise show some cards with a select checkbox and others without
    // for no visually obvious reason.
    if (!currentReadOnly && item.read_only) {
      card.className += ' location-detail-card-set-owned';
      card.title = texts.setReadOnlyNote;
    }

    var qtyBadge = document.createElement('span');
    qtyBadge.className = 'location-detail-card-qty';
    qtyBadge.textContent = item.quantity + 'x';
    card.appendChild(qtyBadge);

    var thumb = document.createElement('span');
    thumb.className = 'location-detail-card-thumb';
    if (item.thumbnail_unverified) {
      thumb.className += ' location-detail-card-thumb-unverified';
      thumb.title = texts.thumbnailUnverifiedTitle;
    }
    thumb.innerHTML = item.thumbnail ? ('<img src="' + item.thumbnail + '" alt="">') : texts.brickIcon;
    card.appendChild(thumb);

    var swatch = document.createElement('span');
    swatch.className = 'location-detail-card-swatch';
    swatch.style.backgroundColor = '#' + (item.color_rgb || 'cccccc');
    card.appendChild(swatch);

    var num = document.createElement('span');
    num.className = 'location-detail-card-num';
    num.textContent = item.part_num;
    card.appendChild(num);

    var name = document.createElement('span');
    name.className = 'location-detail-card-name';
    name.title = item.part_name;
    name.textContent = item.part_name;
    card.appendChild(name);

    var meta = document.createElement('span');
    meta.className = 'location-detail-card-meta';
    var condText = item.condition_type === 'new' ? texts.conditionNew : texts.conditionUsed;
    meta.textContent = (item.color_name || '') + ' \\u00b7 ' + condText;
    card.appendChild(meta);

    if (item.bricklink_unit_price) {
      var price = document.createElement('span');
      price.className = 'location-detail-card-price';
      price.textContent = item.bricklink_unit_price;
      card.appendChild(price);
    }

    if (!currentReadOnly && !item.read_only) {
      var descriptor = {
        kind: 'part',
        locationId: item.location_id,
        partId: item.part_id,
        colorId: item.color_id,
        conditionType: item.condition_type
      };
      addCardSelectAndActivate(card, descriptor, function() {
        window.openPartModal(item.part_id, item.color_id, item.location_id, item.condition_type);
      });
    }

    return card;
  }

  function buildOneMinifigCard(fig) {
    var card = document.createElement('div');
    card.className = 'location-detail-card';
    if (!currentReadOnly && fig.read_only) {
      card.className += ' location-detail-card-set-owned';
      card.title = texts.setReadOnlyNote;
    }

    var thumb = document.createElement('span');
    thumb.className = 'location-detail-card-thumb';
    thumb.innerHTML = fig.thumbnail ? ('<img src="' + fig.thumbnail + '" alt="">') : texts.minifigIcon;
    card.appendChild(thumb);

    var num = document.createElement('span');
    num.className = 'location-detail-card-num';
    num.textContent = fig.fig_num;
    card.appendChild(num);

    var name = document.createElement('span');
    name.className = 'location-detail-card-name';
    var figName = fig.minifig_name || fig.fig_num;
    name.title = figName;
    name.textContent = figName;
    card.appendChild(name);

    var meta = document.createElement('span');
    meta.className = 'location-detail-card-meta';
    var condText = fig.condition_type === 'new' ? texts.conditionNew : texts.conditionUsed;
    meta.textContent = condText;
    card.appendChild(meta);

    if (!currentReadOnly && !fig.read_only) {
      var descriptor = {
        kind: 'minifig',
        instanceId: fig.instance_id,
        locationId: fig.location_id
      };
      addCardSelectAndActivate(card, descriptor, function() {
        openItemEditModal(descriptor, {
          title: figName,
          meta: condText
        });
      });
    }

    return card;
  }

  // Sub-groups a category's (or the minifig list's) items by where they're
  // actually stored — items[i].location_label is null for "directly at the
  // location currently being viewed", otherwise the path from there down to
  // wherever the item really sits (see the location_content action's own
  // doc comment in src/routes/actions.php). Only useful once a recursive
  // view (a parent location with sub-locations) actually spans more than
  // one distinct spot — with everything in one place, the flat grid this
  // used to always be is still exactly right, so the header row is skipped
  // entirely in that (most common) case.
  function groupByLocationLabel(items) {
    var groups = {};
    var order = [];
    items.forEach(function(item) {
      var key = item.location_label === null || item.location_label === undefined ? '' : item.location_label;
      if (!groups[key]) {
        groups[key] = [];
        order.push(key);
      }
      groups[key].push(item);
    });
    order.sort(function(a, b) {
      if (a === b) {
        return 0;
      }
      if (a === '') {
        return -1;
      }
      if (b === '') {
        return 1;
      }
      return a < b ? -1 : 1;
    });
    return order.map(function(key) {
      return { label: key === '' ? texts.hereLabel : key, items: groups[key] };
    });
  }

  // $showGroups is decided once for the whole location view (see
  // renderContent() below), not per call — deciding it locally per category
  // used to mean a category whose own items all happened to sit in a single
  // sub-location rendered flat (no header) even though a DIFFERENT category
  // on the very same page spanned several sub-locations, so two categories
  // on one location's page disagreed about whether sub-location info existed
  // at all. Once the location as a whole genuinely spans more than one spot,
  // every category shows its own header(s) consistently, even a category
  // that (for now) happens to sit entirely in just one of them.
  function buildLocationGroupedGrid(items, buildCard, showGroups) {
    if (!showGroups) {
      var grid = document.createElement('div');
      grid.className = 'location-detail-grid';
      items.forEach(function(item) {
        grid.appendChild(buildCard(item));
      });
      return grid;
    }

    var groups = groupByLocationLabel(items);
    var wrap = document.createElement('div');
    wrap.className = 'location-content-subgroups';
    groups.forEach(function(group) {
      var section = document.createElement('div');
      section.className = 'location-content-subgroup';
      var heading = document.createElement('h4');
      heading.className = 'location-content-subgroup-heading';
      heading.textContent = group.label;
      section.appendChild(heading);
      var grid = document.createElement('div');
      grid.className = 'location-detail-grid';
      group.items.forEach(function(item) {
        grid.appendChild(buildCard(item));
      });
      section.appendChild(grid);
      wrap.appendChild(section);
    });
    return wrap;
  }

  function buildPartsGrid(parts, showGroups) {
    return buildLocationGroupedGrid(parts, buildOnePartCard, showGroups);
  }

  function buildMinifigsGrid(minifigs, showGroups) {
    return buildLocationGroupedGrid(minifigs, buildOneMinifigCard, showGroups);
  }

  // ---- "Bauanleitungen" (src/instruction_manuals.php) --------------------

  // Same traffic-light thresholds as ownedSetCompletenessRingClass()
  // (src/owned_sets.php) — mirrored client-side, same as owned_sets.php's
  // own ringColorClass() JS copies, since there's no live PHP render for
  // this badge (it's built fresh from the location_content JSON response).
  function instructionRingColorClass(percent) {
    if (percent >= 100) {
      return 'owned-set-total-ring-fg-complete';
    }
    if (percent >= 75) {
      return 'owned-set-total-ring-fg-partial';
    }
    return 'owned-set-total-ring-fg-low';
  }

  function buildPercentBadge(percent) {
    var wrap = document.createElement('span');
    wrap.className = 'instruction-manual-badge';
    var clamped = Math.min(100, Math.max(0, percent));
    var circumference = 2 * Math.PI * 42;
    var offset = circumference * (1 - clamped / 100);
    var ringClass = instructionRingColorClass(clamped);
    wrap.innerHTML =
      '<svg class="instruction-manual-badge-ring" viewBox="0 0 100 100" aria-hidden="true">' +
      '<circle class="instruction-manual-badge-bg" cx="50" cy="50" r="42"></circle>' +
      '<circle class="instruction-manual-badge-fg ' + ringClass + '" cx="50" cy="50" r="42" style="stroke-dasharray: ' + circumference.toFixed(2) + '; stroke-dashoffset: ' + offset.toFixed(2) + ';"></circle>' +
      '</svg>' +
      '<span class="instruction-manual-badge-label">' + Math.round(percent) + '%</span>';
    wrap.title = texts.instructionPercentTooltip.replace('{percent}', String(Math.round(percent)));
    return wrap;
  }

  // Solid-fill circle (not the ring style buildPercentBadge() uses) showing
  // the school-grade-style condition (1 green/best .. 6 red/worst, "N"or
  // green for is_new) — used both as the tile's own badge and as the live
  // preview while checking criteria in the add/edit forms.
  function buildInstructionGradeBadge(grade, isNew) {
    var span = document.createElement('span');
    span.className = 'instruction-manual-grade-circle instruction-manual-grade-' + grade;
    span.textContent = isNew ? 'N' : String(grade);
    span.title = isNew ? texts.instructionGradeNewTooltip : texts.instructionGradeTooltip.replace('{grade}', String(grade));
    return span;
  }

  // Shared by the add-manual mini-form and the detail modal's edit form —
  // one "Neu" checkbox (disables + visually overrides the other 6 when
  // checked) plus checkboxes for the currently-defined condition criteria —
  // fetched fresh via action=instruction_manual_criteria_list every time
  // this opens rather than embedded once at page load, since the catalog is
  // user-manageable via ?page=settings at any time (see that action's own
  // doc comment, src/routes/actions.php). Live grade badge updates on every
  // change, mirroring computeInstructionManualGrade()
  // (src/instruction_manuals.php) exactly: 0 checked -> grade 1, all
  // checked -> grade 6, everything in between spread evenly across 2-5.
  //
  // @param initial {is_new?: boolean, selected_criterion_ids?: number[]}
  // @param callback function({el: Element, getState: function(): {is_new: boolean, criterionIds: number[]}})
  function buildInstructionCriteriaFieldset(initial, callback) {
    var wrap = document.createElement('div');
    wrap.className = 'instruction-manual-criteria-fieldset';
    wrap.innerHTML = '<p class="hint">' + texts.loading + '</p>';
    var initialSelectedIds = (initial.selected_criterion_ids || []).map(Number);

    fetch('?action=instruction_manual_criteria_list', { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        wrap.innerHTML = '';
        var criteria = data.criteria || [];
        var totalCriteria = criteria.length;

        var newLabel = document.createElement('label');
        newLabel.className = 'instruction-manual-criteria-item instruction-manual-criteria-item-new';
        var newCheckbox = document.createElement('input');
        newCheckbox.type = 'checkbox';
        newCheckbox.checked = !!initial.is_new;
        newLabel.appendChild(newCheckbox);
        newLabel.appendChild(document.createTextNode(' ' + texts.instructionIsNewLabel));
        wrap.appendChild(newLabel);

        var list = document.createElement('div');
        list.className = 'instruction-manual-criteria-list';
        var criterionCheckboxes = {};
        criteria.forEach(function(c) {
          var label = document.createElement('label');
          label.className = 'instruction-manual-criteria-item';
          var cb = document.createElement('input');
          cb.type = 'checkbox';
          cb.checked = initialSelectedIds.indexOf(c.id) !== -1;
          criterionCheckboxes[c.id] = cb;
          label.appendChild(cb);
          label.appendChild(document.createTextNode(' ' + c.label));
          list.appendChild(label);
          cb.addEventListener('change', update);
        });
        wrap.appendChild(list);

        var preview = document.createElement('div');
        preview.className = 'instruction-manual-grade-preview';
        wrap.appendChild(preview);

        // Mirrors computeInstructionManualGrade() (src/instruction_manuals.php).
        function computeGrade() {
          if (newCheckbox.checked) {
            return { isNew: true, grade: 1 };
          }
          var count = 0;
          Object.keys(criterionCheckboxes).forEach(function(id) {
            if (criterionCheckboxes[id].checked) {
              count++;
            }
          });
          if (totalCriteria <= 0 || count <= 0) {
            return { isNew: false, grade: 1 };
          }
          if (count >= totalCriteria) {
            return { isNew: false, grade: 6 };
          }
          var span = Math.max(1, totalCriteria - 2);
          var grade = 2 + Math.round((count - 1) / span * 3);
          return { isNew: false, grade: Math.min(5, Math.max(2, grade)) };
        }

        function update() {
          var disabled = newCheckbox.checked;
          Object.keys(criterionCheckboxes).forEach(function(id) {
            criterionCheckboxes[id].disabled = disabled;
          });
          preview.innerHTML = '';
          var g = computeGrade();
          preview.appendChild(buildInstructionGradeBadge(g.grade, g.isNew));
        }

        newCheckbox.addEventListener('change', update);
        update();

        callback({
          el: wrap,
          getState: function() {
            var criterionIds = [];
            Object.keys(criterionCheckboxes).forEach(function(id) {
              if (criterionCheckboxes[id].checked) {
                criterionIds.push(Number(id));
              }
            });
            return { is_new: newCheckbox.checked, criterionIds: criterionIds };
          }
        });
      })
      .catch(function() {
        wrap.innerHTML = '<p class="hint">' + texts.errorRetry + '</p>';
      });
  }

  function buildInstructionManualTile(manual) {
    var card = document.createElement('div');
    card.className = 'location-detail-card instruction-manual-tile';

    if (manual.percent_complete !== null && manual.percent_complete !== undefined) {
      card.appendChild(buildPercentBadge(manual.percent_complete));
    }

    var gradeBadgeWrap = document.createElement('span');
    gradeBadgeWrap.className = 'instruction-manual-grade-badge';
    gradeBadgeWrap.appendChild(buildInstructionGradeBadge(manual.grade, manual.is_new));
    card.appendChild(gradeBadgeWrap);

    var thumb = document.createElement('span');
    thumb.className = 'location-detail-card-thumb instruction-manual-tile-thumb';
    thumb.innerHTML = manual.thumbnail ? ('<img src="' + manual.thumbnail + '" alt="">') : texts.instructionsIcon;
    card.appendChild(thumb);

    var num = document.createElement('span');
    num.className = 'location-detail-card-num';
    num.textContent = manual.set_num;
    card.appendChild(num);

    var name = document.createElement('span');
    name.className = 'location-detail-card-name';
    name.title = manual.set_name;
    name.textContent = manual.set_name;
    card.appendChild(name);

    if (manual.manual_price_text || manual.set_price_text) {
      var price = document.createElement('span');
      price.className = 'location-detail-card-price';
      if (manual.manual_price_text) {
        price.appendChild(document.createTextNode(manual.manual_price_text));
      }
      if (manual.set_price_text) {
        if (manual.manual_price_text) {
          price.appendChild(document.createTextNode(' '));
        }
        var setPrice = document.createElement('span');
        setPrice.className = 'instruction-manual-tile-set-price';
        setPrice.title = texts.instructionTileSetPriceTooltip;
        setPrice.textContent = '(' + manual.set_price_text + ')';
        price.appendChild(setPrice);
      }
      card.appendChild(price);
    }

    card.tabIndex = 0;
    card.setAttribute('role', 'button');
    var open = function() { openInstructionManualDetailModal(manual.id); };
    card.addEventListener('click', open);
    card.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        open();
      }
    });

    return card;
  }

  function buildInstructionManualAddTile() {
    var card = document.createElement('div');
    card.className = 'location-detail-card instruction-manual-tile-add';
    card.tabIndex = 0;
    card.setAttribute('role', 'button');
    var icon = document.createElement('span');
    icon.className = 'instruction-manual-tile-add-icon';
    icon.innerHTML = texts.addIcon;
    card.appendChild(icon);
    var label = document.createElement('span');
    label.className = 'instruction-manual-tile-add-label';
    label.textContent = texts.instructionsAddTileLabel;
    card.appendChild(label);
    card.addEventListener('click', openInstructionManualAddModal);
    card.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        openInstructionManualAddModal();
      }
    });
    return card;
  }

  // Same grouping-by-actual-location idea as buildLocationGroupedGrid(), but
  // the add tile only ever goes into the group that IS the currently viewed
  // location (location_label null/empty) — where exactly it's clicked from
  // no longer matters for filing purposes (a manual's location is always
  // auto-derived from its set's theme, see addInstructionManual()) — always
  // rendered exactly once, regardless of grouping. Theme folders now nest
  // onto their full Rebrickable ancestor path (e.g. "Bauanleitungen > Train
  // > 9V"), so a manual is essentially never directly AT whatever location
  // is currently being viewed (only ever a few levels further down) — tying
  // the add-tile to a "here" group like earlier versions did would mean it
  // almost never shows up at all.
  function buildInstructionManualsGrid(manuals) {
    var groups = groupByLocationLabel(manuals);

    var addGrid = document.createElement('div');
    addGrid.className = 'location-detail-grid instruction-manual-grid';
    addGrid.appendChild(buildInstructionManualAddTile());

    if (groups.length <= 1) {
      manuals.forEach(function(manual) {
        addGrid.appendChild(buildInstructionManualTile(manual));
      });
      return addGrid;
    }

    var wrap = document.createElement('div');
    wrap.className = 'location-content-subgroups';
    wrap.appendChild(addGrid);
    groups.forEach(function(group) {
      var section = document.createElement('div');
      section.className = 'location-content-subgroup';
      var heading = document.createElement('h4');
      heading.className = 'location-content-subgroup-heading';
      heading.textContent = group.label;
      section.appendChild(heading);
      var grid = document.createElement('div');
      grid.className = 'location-detail-grid instruction-manual-grid';
      group.items.forEach(function(manual) {
        grid.appendChild(buildInstructionManualTile(manual));
      });
      section.appendChild(grid);
      wrap.appendChild(section);
    });
    return wrap;
  }

  // ---- Add-manual mini-form ----

  var instructionAddSelectedSetId = null;
  var instructionSetSearchToken = 0;

  function closeInstructionAddModal() {
    if (instructionAddModal) {
      instructionAddModal.style.display = 'none';
    }
  }

  function renderInstructionAddSelectedSet(set) {
    instructionAddSelectedSetId = set ? set.id : null;
    if (instructionAddSubmit) {
      instructionAddSubmit.disabled = !instructionAddSelectedSetId;
    }
    if (!instructionAddSelectedSet) {
      return;
    }
    if (!set) {
      instructionAddSelectedSet.style.display = 'none';
      instructionAddSelectedSet.innerHTML = '';
      return;
    }
    instructionAddSelectedSet.style.display = '';
    instructionAddSelectedSet.innerHTML = '';
    var thumb = document.createElement('span');
    thumb.className = 'instruction-manual-selected-set-thumb';
    thumb.innerHTML = set.thumbnail ? ('<img src="' + set.thumbnail + '" alt="">') : texts.instructionsIcon;
    instructionAddSelectedSet.appendChild(thumb);
    var label = document.createElement('span');
    label.textContent = set.rebrickable_set_num + ' \\u00b7 ' + set.name;
    instructionAddSelectedSet.appendChild(label);
  }

  if (instructionAddSetSearch && instructionAddSetResults) {
    instructionAddSetSearch.addEventListener('input', function() {
      var query = instructionAddSetSearch.value.trim();
      instructionSetSearchToken++;
      var myToken = instructionSetSearchToken;
      if (query.length < 2) {
        instructionAddSetResults.innerHTML = '';
        return;
      }
      fetch('?action=search_sets_for_instructions&q=' + encodeURIComponent(query), { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (myToken !== instructionSetSearchToken) {
            return;
          }
          instructionAddSetResults.innerHTML = '';
          var items = data.items || [];
          if (items.length === 0) {
            var empty = document.createElement('p');
            empty.className = 'hint';
            empty.textContent = texts.instructionSetSearchNoResults;
            instructionAddSetResults.appendChild(empty);
            return;
          }
          items.forEach(function(set) {
            var row = document.createElement('button');
            row.type = 'button';
            row.className = 'instruction-manual-set-result-row';
            var thumb = document.createElement('span');
            thumb.className = 'instruction-manual-set-result-thumb';
            thumb.innerHTML = set.thumbnail ? ('<img src="' + set.thumbnail + '" alt="">') : texts.instructionsIcon;
            row.appendChild(thumb);
            var label = document.createElement('span');
            label.textContent = set.rebrickable_set_num + ' \\u00b7 ' + set.name;
            row.appendChild(label);
            row.addEventListener('click', function() {
              renderInstructionAddSelectedSet(set);
              instructionAddSetResults.innerHTML = '';
              instructionAddSetSearch.value = '';
            });
            instructionAddSetResults.appendChild(row);
          });
        })
        .catch(function() {});
    });
  }

  function openInstructionManualAddModal() {
    if (!instructionAddModal || !instructionAddForm) {
      return;
    }
    instructionAddMessage.textContent = '';
    instructionAddMessage.className = 'add-stock-message';
    if (instructionAddSetSearch) {
      instructionAddSetSearch.value = '';
    }
    if (instructionAddSetResults) {
      instructionAddSetResults.innerHTML = '';
    }
    if (instructionAddNotes) {
      instructionAddNotes.value = '';
    }
    renderInstructionAddSelectedSet(null);

    var instructionAddCriteriaFieldset = null;
    if (instructionAddCriteriaContainer) {
      instructionAddCriteriaContainer.innerHTML = '';
      buildInstructionCriteriaFieldset({}, function(fieldset) {
        instructionAddCriteriaFieldset = fieldset;
        instructionAddCriteriaContainer.innerHTML = '';
        instructionAddCriteriaContainer.appendChild(fieldset.el);
      });
    }

    instructionAddForm.onsubmit = function(e) {
      e.preventDefault();
      if (!instructionAddSelectedSetId || !instructionAddCriteriaFieldset) {
        return;
      }
      var criteriaState = instructionAddCriteriaFieldset.getState();
      var formData = new FormData();
      formData.set('action', 'add_instruction_manual');
      formData.set('set_id', instructionAddSelectedSetId);
      formData.set('is_new', criteriaState.is_new ? '1' : '0');
      criteriaState.criterionIds.forEach(function(id) {
        formData.append('criteria[]', id);
      });
      formData.set('notes', instructionAddNotes ? instructionAddNotes.value : '');
      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (res.success) {
            closeInstructionAddModal();
            refreshContent();
          } else {
            instructionAddMessage.textContent = res.message || texts.instructionAddSubmitFailed;
            instructionAddMessage.className = 'add-stock-message error';
          }
        })
        .catch(function() {
          instructionAddMessage.textContent = texts.errorRetry;
          instructionAddMessage.className = 'add-stock-message error';
        });
    };

    instructionAddModal.style.display = 'flex';
  }

  if (instructionAddModalClose) {
    instructionAddModalClose.addEventListener('click', closeInstructionAddModal);
  }

  // ---- Detail modal ----

  function formatBricklinkPriceBlock(priceNew, priceUsed, currency, checkedAt) {
    if (!checkedAt) {
      return texts.bricklinkPriceNever;
    }
    var symbol = currency === 'EUR' ? '\\u20ac' : (currency === 'USD' ? '$' : (currency === 'GBP' ? '\\u00a3' : (currency || '')));
    var newText = priceNew !== null && priceNew !== undefined ? Number(priceNew).toFixed(2) + ' ' + symbol : '\\u2013';
    var usedText = priceUsed !== null && priceUsed !== undefined ? Number(priceUsed).toFixed(2) + ' ' + symbol : '\\u2013';
    return texts.instructionPriceNewLabel + ': ' + newText + '  \\u00b7  ' + texts.instructionPriceUsedLabel + ': ' + usedText;
  }

  function buildInstructionDetailTabs(data) {
    var manual = data.manual;
    var wrap = document.createElement('div');

    var tabBar = document.createElement('div');
    tabBar.className = 'part-modal-tabs';
    var tabPanels = document.createElement('div');
    tabPanels.className = 'part-modal-tab-panels';

    var panelDetails = document.createElement('div');
    panelDetails.className = 'part-modal-tab-panel';
    var panelParts = document.createElement('div');
    panelParts.className = 'part-modal-tab-panel';
    var panelPrices = document.createElement('div');
    panelPrices.className = 'part-modal-tab-panel';

    var tabs = [
      { label: texts.instructionDetailTabDetails, panel: panelDetails },
      { label: texts.instructionDetailTabParts, panel: panelParts },
      { label: texts.instructionDetailTabPrices, panel: panelPrices }
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

    // ---- Details tab ----
    var detailsImg = document.createElement('div');
    detailsImg.className = 'part-modal-image';
    detailsImg.innerHTML = manual.thumbnail ? ('<img src="' + manual.thumbnail + '" alt="">') : texts.instructionsIcon;
    panelDetails.appendChild(detailsImg);

    var setLink = document.createElement('a');
    setLink.href = '?page=set_detail&id=' + manual.set_id;
    setLink.className = 'instruction-manual-detail-set-link';
    setLink.textContent = manual.set_num + ' \\u00b7 ' + manual.set_name;
    panelDetails.appendChild(setLink);

    var editForm = document.createElement('form');
    editForm.className = 'instruction-manual-detail-edit-form';
    var editMessage = document.createElement('div');
    editMessage.className = 'add-stock-message';
    editForm.appendChild(editMessage);

    var conditionHint = document.createElement('div');
    conditionHint.className = 'hint';
    conditionHint.textContent = texts.instructionFieldCondition;
    editForm.appendChild(conditionHint);
    var criteriaFieldsetContainer = document.createElement('div');
    editForm.appendChild(criteriaFieldsetContainer);
    var criteriaFieldset = null;
    buildInstructionCriteriaFieldset(manual, function(fieldset) {
      criteriaFieldset = fieldset;
      criteriaFieldsetContainer.innerHTML = '';
      criteriaFieldsetContainer.appendChild(fieldset.el);
    });

    var notesLabel = document.createElement('label');
    var notesTextarea = document.createElement('textarea');
    notesTextarea.rows = 2;
    notesTextarea.value = manual.notes || '';
    notesLabel.appendChild(notesTextarea);
    editForm.appendChild(notesLabel);

    var saveBtn = document.createElement('button');
    saveBtn.type = 'submit';
    saveBtn.textContent = texts.instructionSaveButton;
    editForm.appendChild(saveBtn);

    editForm.addEventListener('submit', function(e) {
      e.preventDefault();
      if (!criteriaFieldset) {
        return;
      }
      var criteriaState = criteriaFieldset.getState();
      var formData = new FormData();
      formData.set('action', 'update_instruction_manual');
      formData.set('instance_id', manual.id);
      formData.set('is_new', criteriaState.is_new ? '1' : '0');
      criteriaState.criterionIds.forEach(function(id) {
        formData.append('criteria[]', id);
      });
      formData.set('notes', notesTextarea.value);
      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (res.success) {
            editMessage.textContent = '';
            editMessage.className = 'add-stock-message';
            refreshContent();
          } else {
            editMessage.textContent = res.message || texts.errorRetry;
            editMessage.className = 'add-stock-message error';
          }
        })
        .catch(function() {
          editMessage.textContent = texts.errorRetry;
          editMessage.className = 'add-stock-message error';
        });
    });
    panelDetails.appendChild(editForm);

    var actionsRow = document.createElement('div');
    actionsRow.className = 'instruction-manual-detail-actions';

    var deleteBtn = document.createElement('button');
    deleteBtn.type = 'button';
    deleteBtn.className = 'instruction-manual-delete-btn';
    deleteBtn.textContent = texts.instructionDeleteButton;
    deleteBtn.addEventListener('click', function() {
      if (!window.confirm(texts.instructionDeleteConfirm)) {
        return;
      }
      var formData = new FormData();
      formData.set('action', 'delete_instruction_manual');
      formData.set('instance_id', manual.id);
      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (res.success) {
            if (instructionDetailModal) {
              instructionDetailModal.style.display = 'none';
            }
            refreshContent();
          }
        })
        .catch(function() {});
    });
    actionsRow.appendChild(deleteBtn);

    panelDetails.appendChild(actionsRow);

    // ---- Bauteile im Lager tab ----
    // Per explicit follow-up request: just the 4 summary counts (no more
    // ring, no more full part-by-part table) — same "actual / nominal" shape
    // set_detail's own inventory table uses for these exact same fields
    // (src/routes/pages.php's renderSetGeneralInfoTable() surroundings).
    var summaryRows = [
      { label: texts.instructionFieldTotal, key: 'total' },
      { label: texts.instructionFieldExclusive, key: 'exclusive' },
      { label: texts.instructionFieldRare, key: 'rare' },
      { label: texts.instructionFieldMinifigs, key: 'minifig' }
    ];
    if (data.summary) {
      var summaryList = document.createElement('div');
      summaryList.className = 'instruction-manual-summary-list';
      summaryRows.forEach(function(row) {
        var nominal = data.summary[row.key + '_nominal'] || 0;
        var actual = data.summary[row.key + '_actual'] || 0;
        var line = document.createElement('div');
        line.className = 'instruction-manual-summary-row';
        var label = document.createElement('span');
        label.textContent = row.label;
        line.appendChild(label);
        var value = document.createElement('span');
        value.textContent = actual + ' / ' + nominal;
        line.appendChild(value);
        summaryList.appendChild(line);
      });
      panelParts.appendChild(summaryList);
    } else {
      var summaryEmpty = document.createElement('p');
      summaryEmpty.className = 'hint';
      summaryEmpty.textContent = texts.contentEmpty;
      panelParts.appendChild(summaryEmpty);
    }

    // ---- Preise tab ----
    [
      { label: texts.instructionPriceSetLabel, priceNew: data.set.bricklink_price_new, priceUsed: data.set.bricklink_price_used, currency: data.set.bricklink_price_currency, checkedAt: data.set.bricklink_price_checked_at, url: data.bricklinkSetUrl, action: 'refresh_bricklink_price' },
      { label: texts.instructionPriceInstructionsLabel, priceNew: data.set.bricklink_instructions_price_new, priceUsed: data.set.bricklink_instructions_price_used, currency: data.set.bricklink_instructions_price_currency, checkedAt: data.set.bricklink_instructions_price_checked_at, url: data.bricklinkInstructionsUrl, action: 'refresh_bricklink_instructions_price' }
    ].forEach(function(row) {
      var rowEl = document.createElement('div');
      rowEl.className = 'instruction-manual-price-row';
      var heading = document.createElement('h4');
      heading.textContent = row.label;
      rowEl.appendChild(heading);
      var priceLine = document.createElement('p');
      priceLine.textContent = formatBricklinkPriceBlock(row.priceNew, row.priceUsed, row.currency, row.checkedAt);
      if (row.checkedAt) {
        priceLine.title = texts.bricklinkPriceUpdatedTitle.replace('{date}', row.checkedAt);
      }
      rowEl.appendChild(priceLine);
      var link = document.createElement('a');
      link.href = row.url;
      link.target = '_blank';
      link.rel = 'noopener';
      link.textContent = 'BrickLink';
      rowEl.appendChild(link);
      var refreshBtn = document.createElement('button');
      refreshBtn.type = 'button';
      refreshBtn.textContent = texts.bricklinkPriceRefreshLabel;
      refreshBtn.addEventListener('click', function() {
        refreshBtn.disabled = true;
        var formData = new FormData();
        formData.set('action', row.action);
        formData.set('set_id', manual.set_id);
        fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            refreshBtn.disabled = false;
            if (res.success) {
              priceLine.textContent = formatBricklinkPriceBlock(res.priceNew, res.priceUsed, res.currency, res.checkedAt);
              priceLine.title = texts.bricklinkPriceUpdatedTitle.replace('{date}', res.checkedAt || '');
            } else {
              priceLine.textContent = texts.bricklinkPriceRefreshFailed.replace('{message}', res.message || '');
            }
          })
          .catch(function() {
            refreshBtn.disabled = false;
          });
      });
      rowEl.appendChild(refreshBtn);
      panelPrices.appendChild(rowEl);
    });

    activateTab(0);
    wrap.appendChild(tabBar);
    wrap.appendChild(tabPanels);
    return wrap;
  }

  function openInstructionManualDetailModal(instanceId) {
    if (!instructionDetailModal || !instructionDetailContent) {
      return;
    }
    instructionDetailContent.innerHTML = '<p class="hint">' + texts.loading + '</p>';
    instructionDetailModal.style.display = 'flex';
    fetch('?action=instruction_manual_detail&instance_id=' + instanceId, { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data.success) {
          instructionDetailContent.innerHTML = '<p class="hint">' + texts.errorRetry + '</p>';
          return;
        }
        instructionDetailContent.innerHTML = '';
        instructionDetailContent.appendChild(buildInstructionDetailTabs(data));
      })
      .catch(function() {
        instructionDetailContent.innerHTML = '<p class="hint">' + texts.errorRetry + '</p>';
      });
  }

  if (instructionDetailModalClose) {
    instructionDetailModalClose.addEventListener('click', function() {
      instructionDetailModal.style.display = 'none';
    });
  }

  // Shared by every submit path openItemEditModal() can trigger (move a
  // part, delete a minifig instance, move a minifig instance) — same
  // success/failure handling each time, just a different FormData.
  function submitItemEdit(formData) {
    fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success) {
          itemEditModal.style.display = 'none';
          window.applyStatusStats(res.stats);
          refreshContent();
        } else {
          itemEditMessage.textContent = texts.updateFailed.replace('{message}', res.message || '');
          itemEditMessage.className = 'add-stock-message error';
        }
      })
      .catch(function() {
        itemEditMessage.textContent = texts.errorRetry;
        itemEditMessage.className = 'add-stock-message error';
      });
  }

  function openItemEditModal(descriptor, display) {
    if (!itemEditModal || !itemEditForm) {
      return;
    }
    itemEditSubtitle.textContent = display.title + (display.meta ? ' \\u00b7 ' + display.meta : '');
    itemEditCurrentPath.textContent = findLocationPath(descriptor.locationId);
    itemEditMessage.textContent = '';
    itemEditMessage.className = 'add-stock-message';

    // A minifig instance is exactly one physical figure — no quantity field,
    // and "remove" needs its own explicit control instead of "set to 0".
    var isMinifig = descriptor.kind === 'minifig';
    if (itemEditQuantityRow) {
      itemEditQuantityRow.style.display = isMinifig ? 'none' : '';
    }
    itemEditQuantity.required = !isMinifig;
    if (!isMinifig) {
      itemEditQuantity.value = display.quantity;
    }
    if (itemEditDeleteBtn) {
      itemEditDeleteBtn.style.display = isMinifig ? '' : 'none';
      itemEditDeleteBtn.onclick = !isMinifig ? null : function() {
        if (!window.confirm(texts.minifigDeleteConfirm)) {
          return;
        }
        var deleteFormData = new FormData();
        deleteFormData.set('action', 'delete_minifig_storage_item');
        deleteFormData.set('instance_id', descriptor.instanceId);
        submitItemEdit(deleteFormData);
      };
    }

    itemEditPicker.innerHTML = '';
    var newLocationId = null;
    window.createLocationPicker(itemEditPicker, texts, function(value) {
      newLocationId = value;
    });

    itemEditForm.onsubmit = function(e) {
      e.preventDefault();
      if (isMinifig) {
        if (!newLocationId) {
          itemEditMessage.textContent = texts.updateFailed.replace('{message}', '');
          itemEditMessage.className = 'add-stock-message error';
          return;
        }
        var moveFormData = new FormData();
        moveFormData.set('action', 'move_minifig_storage_item');
        moveFormData.set('instance_id', descriptor.instanceId);
        moveFormData.set('new_location_id', newLocationId);
        submitItemEdit(moveFormData);
        return;
      }

      var formData = new FormData();
      formData.set('action', 'update_storage_item');
      formData.set('location_id', descriptor.locationId);
      formData.set('condition_type', descriptor.conditionType);
      formData.set('quantity', itemEditQuantity.value);
      if (newLocationId) {
        formData.set('new_location_id', newLocationId);
      }
      formData.set('part_id', descriptor.partId);
      formData.set('color_id', descriptor.colorId);
      submitItemEdit(formData);
    };

    itemEditModal.style.display = 'flex';
  }

  if (itemEditModalClose) {
    itemEditModalClose.addEventListener('click', function() {
      itemEditModal.style.display = 'none';
    });
  }

  if (bulkBarRelocateBtn && bulkRelocateModal) {
    bulkBarRelocateBtn.addEventListener('click', function() {
      if (Object.keys(selectedItems).length === 0) {
        return;
      }
      bulkRelocateMessage.textContent = '';
      bulkRelocateMessage.className = 'add-stock-message';
      bulkRelocatePicker.innerHTML = '';
      var targetLocationId = null;
      // Pre-selects the currently viewed location — most bulk relocates
      // consolidate items from elsewhere into (or near) whatever's already
      // open, so this usually gets there faster than starting at the root.
      window.createLocationPicker(bulkRelocatePicker, texts, function(value) {
        targetLocationId = value;
      }, currentLocationId || undefined);

      bulkRelocateForm.onsubmit = function(e) {
        e.preventDefault();
        if (!targetLocationId) {
          return;
        }
        var formData = new FormData();
        formData.set('action', 'bulk_move_storage_items');
        formData.set('target_location_id', targetLocationId);
        formData.set('items', JSON.stringify(Object.keys(selectedItems).map(function(k) { return selectedItems[k]; })));
        fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            if (res.success) {
              bulkRelocateModal.style.display = 'none';
              window.applyStatusStats(res.stats);
              clearSelection();
              refreshContent();
            } else {
              bulkRelocateMessage.textContent = texts.bulkRelocateFailed.replace('{message}', res.message || '');
              bulkRelocateMessage.className = 'add-stock-message error';
            }
          })
          .catch(function() {
            bulkRelocateMessage.textContent = texts.errorRetry;
            bulkRelocateMessage.className = 'add-stock-message error';
          });
      };

      bulkRelocateModal.style.display = 'flex';
    });
  }

  if (bulkRelocateModalClose) {
    bulkRelocateModalClose.addEventListener('click', function() {
      bulkRelocateModal.style.display = 'none';
    });
  }

  // "Zur Inventur vormerken" checkbox — only ever shown for a genuine,
  // directly-editable location (the caller already gates this on
  // !currentReadOnly), never for an owned_set/pick_list node or the
  // Bauanleitungen root. Rebuilt fresh on every renderContent() call so it
  // always reflects that specific location's own flag state, exactly like
  // the recursive toggle right above it. Per explicit request, actually
  // WORKING a flagged location's Inventur only ever happens in the /pick/
  // app (see src/stocktake_pages.php) — no "Inventur starten" button here
  // anymore, this checkbox only ever sets/clears the flag itself.
  function buildStocktakeControls(locationId, data) {
    var flagLabel = document.createElement('label');
    flagLabel.className = 'location-recursive-toggle';
    var flagInput = document.createElement('input');
    flagInput.type = 'checkbox';
    flagInput.checked = !!data.stocktakeFlagged;
    flagInput.addEventListener('change', function() {
      var formData = new FormData();
      formData.set('action', 'toggle_location_stocktake_flag');
      formData.set('location_id', String(locationId));
      formData.set('flagged', flagInput.checked ? '1' : '0');
      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' }).catch(function() {});
    });
    flagLabel.appendChild(flagInput);
    flagLabel.appendChild(document.createTextNode(' ' + texts.stocktakeFlagLabel));
    return flagLabel;
  }

  function renderContent(id, name, data) {
    contentEl.innerHTML = '';
    allSelectableItems = [];
    currentReadOnly = !!data.readOnly;
    var heading = document.createElement('h2');
    heading.textContent = name;
    contentEl.appendChild(heading);

    if (data.isInstructionsLocation) {
      contentEl.appendChild(buildInstructionManualsGrid(data.instructionManuals || []));
      updateBulkBar();
      return;
    }

    var recursiveToggleLabel = document.createElement('label');
    recursiveToggleLabel.className = 'location-recursive-toggle';
    var recursiveToggleInput = document.createElement('input');
    recursiveToggleInput.type = 'checkbox';
    recursiveToggleInput.checked = recursiveEnabled;
    recursiveToggleInput.addEventListener('change', function() {
      setRecursiveEnabled(recursiveToggleInput.checked);
      refreshContent();
    });
    recursiveToggleLabel.appendChild(recursiveToggleInput);
    recursiveToggleLabel.appendChild(document.createTextNode(' ' + texts.recursiveToggleLabel));
    contentEl.appendChild(recursiveToggleLabel);

    if (!currentReadOnly) {
      contentEl.appendChild(buildStocktakeControls(id, data));
    }

    if (currentReadOnly) {
      var readOnlyNote = document.createElement('p');
      readOnlyNote.className = 'hint location-content-readonly-note';
      readOnlyNote.textContent = texts.setReadOnlyNote;
      if (data.ownedSetId) {
        var detailLink = document.createElement('a');
        detailLink.href = '?page=owned_set_detail&id=' + data.ownedSetId;
        detailLink.textContent = texts.openSetDetailsLink;
        readOnlyNote.appendChild(document.createTextNode(' '));
        readOnlyNote.appendChild(detailLink);
      }
      contentEl.appendChild(readOnlyNote);
    }

    if (data.ldraw && data.ldraw.missingCount > 0) {
      var status = document.createElement('p');
      status.className = 'hint location-ldraw-status';
      if (data.ldraw.currentPart) {
        status.textContent = texts.ldrawCurrent
          .replace('{part}', data.ldraw.currentPart)
          .replace('{count}', data.ldraw.queueDepth);
      } else {
        status.textContent = texts.ldrawWaiting;
      }
      contentEl.appendChild(status);
      // Missing color-correct images (see getMissingLdrawRenderPairs() in
      // src/ldraw.php) were already enqueued server-side by this same
      // request — just keep polling the same content until none are left,
      // same ~1s pacing the set-detail page's render overlay uses. Guarded
      // by loadToken so a poll for a location the user has since navigated
      // away from never overwrites what's currently shown.
      var myToken = loadToken;
      window.setTimeout(function() {
        if (myToken === loadToken) {
          loadContent(id, name);
        }
      }, 1000);
    }

    // Decided once for the whole location view (not per category — see
    // buildLocationGroupedGrid()'s own doc comment for why that used to be
    // inconsistent): true once ANY two items on this page — a part in one
    // category, a minifig, anything — actually sit in different physical
    // sub-locations.
    var allLocationLabels = {};
    data.categories.forEach(function(cat) {
      cat.parts.forEach(function(part) {
        allLocationLabels[part.location_label === null || part.location_label === undefined ? '' : part.location_label] = true;
      });
    });
    data.minifigs.forEach(function(fig) {
      allLocationLabels[fig.location_label === null || fig.location_label === undefined ? '' : fig.location_label] = true;
    });
    var showLocationGroups = Object.keys(allLocationLabels).length > 1;

    data.categories.forEach(function(cat) {
      contentEl.appendChild(buildGroup(cat.name, buildPartsGrid(cat.parts, showLocationGroups)));
    });

    var minifigsBody;
    if (data.minifigs.length > 0) {
      minifigsBody = buildMinifigsGrid(data.minifigs, showLocationGroups);
    } else {
      minifigsBody = document.createElement('p');
      minifigsBody.className = 'hint';
      minifigsBody.textContent = texts.minifigsEmpty;
    }
    contentEl.appendChild(buildGroup(texts.groupMinifigs, minifigsBody));

    updateBulkBar();
  }

  var loadToken = 0;

  function loadContent(id, name) {
    loadToken++;
    var myToken = loadToken;
    currentLocationId = id;
    currentLocationName = name;
    contentEl.innerHTML = '<p class="hint">' + texts.loading + '</p>';
    fetch('?action=location_content&location_id=' + id + '&recursive=' + (recursiveEnabled ? '1' : '0'), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (myToken === loadToken) {
          renderContent(id, name, data);
        }
      })
      .catch(function() {
        if (myToken === loadToken) {
          contentEl.innerHTML = '<p class="hint">' + texts.errorRetry + '</p>';
        }
      });
  }

  function buildNewChildRow(parentId, parentName, depth) {
    var row = document.createElement('div');
    row.className = 'location-tree-row location-tree-row-new';
    row.style.paddingLeft = (depth * 1.25 + 0.25) + 'rem';

    var spacer = document.createElement('span');
    spacer.className = 'location-tree-arrow location-tree-arrow-empty';
    row.appendChild(spacer);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'location-tree-name location-tree-name-new';
    btn.innerHTML = texts.addIcon;
    var label = document.createElement('span');
    label.textContent = texts.newChildLabel;
    btn.appendChild(label);
    btn.addEventListener('click', function() {
      openAddModal(parentId, parentName);
    });
    row.appendChild(btn);

    return row;
  }

  function buildRow(node, depth) {
    var wrap = document.createElement('div');
    wrap.className = 'location-tree-node';

    var row = document.createElement('div');
    row.className = 'location-tree-row';
    row.style.paddingLeft = (depth * 1.25 + 0.25) + 'rem';

    // The synthetic "Lager" root (id null, not a real storage_locations
    // row — see its construction in src/routes/pages.php) is a pure grouping
    // label: no edit/delete (nothing to edit), and its name toggles expand
    // instead of loading content, since there's no location_content to load
    // for it. Every node — root included — always has at least one child now:
    // the synthetic "(Neu)" row appended below, so the arrow is always live.
    var isRoot = node.id === null;
    // A boxed set's own auto-generated storage node (see
    // getStorageLocationTree()'s doc comment, src/storage.php) — shown so
    // its contents can be viewed exactly like any other location, but it's
    // never a real organizational location: no rename/delete/add-child (the
    // set's own removal flow owns that), and it never has children of its
    // own, so no expand arrow either. A pick list (src/pick_lists.php) gets
    // the exact same leaf-only treatment for the same reason — managed only
    // through /pick/'s own UI, never nested/reorganized here. "Pick Lager"
    // itself (the single root every pick list nests under) is a third,
    // distinct case: also no rename/delete, but it DOES have real children
    // (the individual pick lists), so it keeps its expand arrow.
    var isOwnedSet = node.location_type === 'owned_set';
    var isPickList = node.location_type === 'pick_list';
    var isPickLagerRoot = node.location_type === 'pick_lager_root';
    // "Bauanleitungen" (src/instruction_manuals.php) — same no-rename/no-
    // delete treatment as Pick Lager (a singleton root); it DOES keep its
    // expand arrow (to browse the auto-created theme folders inside), but —
    // unlike the feature's first iteration — no longer lets the user create
    // children of their own (see the buildNewChildRow() skip below).
    var isInstructionsRoot = node.location_type === 'instructions_root';
    // The auto-managed, per-theme "virtual" folders themselves
    // (getOrCreateInstructionsThemeLocation()) — fully leaf, same no-rename/
    // no-delete/no-further-nesting treatment as owned_set/pick_list, since
    // they're created and pruned entirely by the app, never by the user.
    var isInstructionsTheme = node.location_type === 'instructions_theme';
    var isLeafOnly = isOwnedSet || isPickList || isInstructionsTheme;
    var isSpecialIcon = isOwnedSet || isPickList || isPickLagerRoot || isInstructionsRoot || isInstructionsTheme;

    if (isLeafOnly) {
      var spacer = document.createElement('span');
      spacer.className = 'location-tree-arrow location-tree-arrow-empty';
      row.appendChild(spacer);
    } else {
      var arrow = document.createElement('button');
      arrow.type = 'button';
      arrow.className = 'location-tree-arrow';
      arrow.innerHTML = texts.chevronIcon;
      arrow.setAttribute('aria-label', texts.expandLabel);
      row.appendChild(arrow);
    }

    var nameBtn = document.createElement('button');
    nameBtn.type = 'button';
    nameBtn.className = 'location-tree-name' + (isRoot ? ' location-tree-name-root' : '') + (isSpecialIcon ? ' location-tree-name-set' : '');
    if (isSpecialIcon) {
      var setIconEl = document.createElement('span');
      setIconEl.className = 'location-tree-set-icon';
      setIconEl.innerHTML = isOwnedSet ? texts.setIcon : ((isInstructionsRoot || isInstructionsTheme) ? texts.instructionsIcon : texts.pickListIcon);
      nameBtn.appendChild(setIconEl);
    }
    nameBtn.appendChild(document.createTextNode(node.name));
    row.appendChild(nameBtn);

    if (!isRoot && !isOwnedSet && !isPickList && !isPickLagerRoot && !isInstructionsRoot && !isInstructionsTheme) {
      var actions = document.createElement('span');
      actions.className = 'location-tree-row-actions';

      var editLink = document.createElement('a');
      editLink.href = '?page=locations&edit=' + node.id;
      editLink.className = 'location-tree-edit';
      editLink.title = texts.editLabel;
      editLink.setAttribute('aria-label', texts.editLabel);
      editLink.innerHTML = texts.editIcon;
      editLink.addEventListener('click', function(e) { e.stopPropagation(); });
      actions.appendChild(editLink);

      var deleteBtn = document.createElement('button');
      deleteBtn.type = 'button';
      deleteBtn.className = 'location-tree-delete';
      deleteBtn.title = texts.deleteLabel;
      deleteBtn.setAttribute('aria-label', texts.deleteLabel);
      deleteBtn.innerHTML = texts.deleteIcon;
      deleteBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        if (deleteForm && deleteFormId && window.confirm(texts.deleteConfirm.replace('{name}', node.name))) {
          deleteFormId.value = node.id;
          deleteForm.submit();
        }
      });
      actions.appendChild(deleteBtn);
      row.appendChild(actions);
    }

    wrap.appendChild(row);

    if (isLeafOnly) {
      // No children, no "(Neu)" row — nothing to expand.
      nameBtn.addEventListener('click', function() {
        selectLocation(node.id, node.name, row);
      });
      return wrap;
    }

    var childrenWrap = document.createElement('div');
    childrenWrap.className = 'location-tree-children';
    var startExpanded = isRoot || expandedIds.has(String(node.id));
    childrenWrap.hidden = !startExpanded;
    if (startExpanded) {
      arrow.classList.add('location-tree-arrow-open');
    }
    (node.children || []).forEach(function(child) {
      childrenWrap.appendChild(buildRow(child, depth + 1));
    });
    // "Bauanleitungen" no longer takes manually-created children (its theme
    // folders are entirely auto-managed) — no "(Neu)" row for it, unlike
    // every other expandable node.
    if (!isInstructionsRoot) {
      childrenWrap.appendChild(buildNewChildRow(node.id, node.name, depth));
    }
    wrap.appendChild(childrenWrap);

    function toggleExpand() {
      childrenWrap.hidden = !childrenWrap.hidden;
      arrow.classList.toggle('location-tree-arrow-open', !childrenWrap.hidden);
      if (!isRoot) {
        if (childrenWrap.hidden) {
          expandedIds.delete(String(node.id));
        } else {
          expandedIds.add(String(node.id));
        }
        saveExpandedIds();
      }
    }

    arrow.addEventListener('click', function(e) {
      e.stopPropagation();
      toggleExpand();
    });
    nameBtn.addEventListener('click', function() {
      if (isRoot) {
        toggleExpand();
      } else {
        selectLocation(node.id, node.name, row);
      }
    });
    nameBtn.addEventListener('dblclick', function() {
      toggleExpand();
    });

    return wrap;
  }

  if (treeContainer && treeRoot) {
    treeContainer.appendChild(buildRow(treeRoot, 0));
  }

  if (resizeHandle && treePane && explorer) {
    var dragging = false;
    resizeHandle.addEventListener('mousedown', function(e) {
      dragging = true;
      e.preventDefault();
    });
    document.addEventListener('mousemove', function(e) {
      if (!dragging) {
        return;
      }
      var rect = explorer.getBoundingClientRect();
      var percent = ((e.clientX - rect.left) / rect.width) * 100;
      percent = Math.max(15, Math.min(60, percent));
      treePane.style.width = percent + '%';
    });
    document.addEventListener('mouseup', function() {
      dragging = false;
    });
  }

  // Fills whatever's actually left of the viewport below the explorer,
  // rather than a fixed guess at how tall the nav/breadcrumbs/heading above
  // it happen to be (which varies with wrapping, locale, etc.) — matches
  // .location-explorer's own min-height fallback in style.css for before
  // this runs. The 800px check mirrors that stylesheet's own mobile
  // breakpoint, where the panes stack instead of sitting side by side and
  // should size to their content (height:auto), not a computed pixel value.
  //
  // Also reserves space for <footer> (rendered right after .container's
  // <main> in renderApp()) — without this the explorer filled the entire
  // remaining viewport on its own, pushing the footer below the fold and
  // forcing a page-level scroll just to reach it, even though the pane
  // itself already used all visible space.
  function sizeExplorer() {
    if (!explorer) {
      return;
    }
    if (window.innerWidth <= 800) {
      explorer.style.height = '';
      return;
    }
    var top = explorer.getBoundingClientRect().top;
    var reserved = 34;
    var footerEl = document.querySelector('footer');
    if (footerEl) {
      var footerStyle = window.getComputedStyle(footerEl);
      reserved += footerEl.offsetHeight + parseFloat(footerStyle.marginTop || '0');
    }
    var height = window.innerHeight - top - reserved;
    explorer.style.height = Math.max(320, height) + 'px';
  }
  sizeExplorer();
  // This inline script runs synchronously while the parser is still inside
  // <main> — <footer> is later in the document and doesn't exist yet, so the
  // immediate call above always runs without it (querySelector('footer')
  // returns null, reserved stays at the fallback). Re-run once the whole
  // document (footer included) has been parsed so the real footer height
  // gets reserved too.
  document.addEventListener('DOMContentLoaded', sizeExplorer);
  window.addEventListener('resize', sizeExplorer);
})();
</script>
SCRIPT;

    // Part cards here call window.openPartModal() directly (see
    // buildOnePartCard() above) rather than relying on the generic
    // .part-card document-click delegate, but the modal's own markup/script
    // still needs to be present on the page for that to exist at all.
    $content .= renderPartDetailModal();

    renderApp(t('locations_title'), $content, $user, computeAppStats($pdo), [homeBreadcrumb(), ['label' => t('locations_title'), 'url' => null]]);
    exit;
}

// storage_movements has been written on every stock change since it was
// introduced but never had a real listing anywhere — the dashboard's own
// recent-activity widget (getRecentActivity(), src/dashboard.php) only ever
// shows the last few rows as a short feed. This is the first full,
// filterable log of it, reachable as a "Lagerprotokoll" sub-item under
// "Mein Lager" (see index.php's getNavMenu()).
if (isset($_GET['page']) && $_GET['page'] === 'storage_movements_log') {
    $logUserId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int) $_GET['user_id'] : null;
    $logPartQuery = trim((string) ($_GET['part'] ?? ''));
    $logPage = max(1, (int) ($_GET['p'] ?? 1));
    $logPerPage = STORAGE_MOVEMENTS_LOG_PAGE_SIZE;

    $renderMovementRows = function (array $movements) use ($pdo): string {
        $rowsHtml = '';
        foreach ($movements as $m) {
            $user = $m['full_name'] !== null && $m['full_name'] !== '' ? $m['full_name'] : ($m['username'] ?? t('dashboard_activity_unknown_user'));
            $partLabel = $m['part_num'] !== null ? $m['part_num'] . ' ' . $m['part_name'] : '—';
            $qty = (int) $m['quantity_change'];
            $qtyClass = $qty > 0 ? 'storage-log-qty-in' : ($qty < 0 ? 'storage-log-qty-out' : 'storage-log-qty-zero');
            $qtyText = ($qty > 0 ? '+' : '') . $qty;
            $typeKey = 'storage_movement_type_' . $m['movement_type'];

            $rowsHtml .= '<tr>';
            $rowsHtml .= '<td>' . htmlspecialchars(formatDate($m['created_at'], true)) . '</td>';
            $rowsHtml .= '<td>' . htmlspecialchars($user) . '</td>';
            $rowsHtml .= '<td>' . htmlspecialchars($partLabel) . '</td>';
            $rowsHtml .= '<td>' . htmlspecialchars($m['color_name'] ?? '—') . '</td>';
            $rowsHtml .= '<td>' . htmlspecialchars($m['location_name'] ?? t('storage_movements_log_unknown_location')) . '</td>';
            $rowsHtml .= '<td>' . htmlspecialchars(t($typeKey)) . '</td>';
            $rowsHtml .= '<td class="' . $qtyClass . '">' . htmlspecialchars($qtyText) . '</td>';
            $rowsHtml .= '<td>' . ($m['resulting_quantity'] !== null ? (int) $m['resulting_quantity'] : '—') . '</td>';
            $rowsHtml .= '<td>' . htmlspecialchars($m['note'] ?? '') . '</td>';
            $rowsHtml .= '</tr>';
        }
        return $rowsHtml;
    };

    // Infinite-scroll continuation request: same sentinel-driven pattern as
    // sets_search/bricks_search, just appending <tr> rows into the existing
    // <tbody> instead of grid cards.
    if (($_GET['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        $results = searchStorageMovements($pdo, $logUserId, $logPartQuery, $logPage, $logPerPage);
        $hasMore = ($logPage * $logPerPage) < $results['total'];
        echo json_encode(['html' => $renderMovementRows($results['items']), 'hasMore' => $hasMore], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $results = searchStorageMovements($pdo, $logUserId, $logPartQuery, $logPage, $logPerPage);
    $hasMore = ($logPage * $logPerPage) < $results['total'];

    $content = '<h1>' . htmlspecialchars(t('storage_movements_log_title')) . '</h1>';

    $content .= '<form method="get" class="storage-log-filter-form">';
    $content .= '<input type="hidden" name="page" value="storage_movements_log">';
    $content .= '<label>' . htmlspecialchars(t('storage_movements_log_filter_user'));
    $content .= '<select name="user_id"><option value="">' . htmlspecialchars(t('storage_movements_log_filter_user_all')) . '</option>';
    foreach (getUsersForFilter($pdo) as $filterUser) {
        $selected = $logUserId === $filterUser['id'] ? ' selected' : '';
        $content .= '<option value="' . $filterUser['id'] . '"' . $selected . '>' . htmlspecialchars($filterUser['display_name']) . '</option>';
    }
    $content .= '</select></label>';
    $content .= '<label>' . htmlspecialchars(t('storage_movements_log_filter_part'));
    $content .= '<input type="text" name="part" value="' . htmlspecialchars($logPartQuery) . '" placeholder="' . htmlspecialchars(t('storage_movements_log_filter_part_placeholder')) . '"></label>';
    $content .= '<button type="submit">' . htmlspecialchars(t('search_button')) . '</button>';
    if ($logUserId !== null || $logPartQuery !== '') {
        $content .= '<a class="filter-reset-link" href="?page=storage_movements_log">' . htmlspecialchars(t('storage_movements_log_filter_reset')) . '</a>';
    }
    $content .= '</form>';

    $content .= '<span class="results-summary">' . htmlspecialchars(t('storage_movements_log_count', ['count' => formatNumber($results['total'])])) . '</span>';

    if (empty($results['items'])) {
        $content .= '<section class="card"><p>' . htmlspecialchars(t('storage_movements_log_empty')) . '</p></section>';
    } else {
        $content .= '<div class="storage-log-table-wrap"><table class="storage-log-table">';
        $content .= '<thead><tr>';
        foreach (['storage_movements_log_col_time', 'storage_movements_log_col_user', 'storage_movements_log_col_part', 'storage_movements_log_col_color', 'storage_movements_log_col_location', 'storage_movements_log_col_type', 'storage_movements_log_col_quantity', 'storage_movements_log_col_resulting', 'storage_movements_log_col_note'] as $colKey) {
            $content .= '<th>' . htmlspecialchars(t($colKey)) . '</th>';
        }
        $content .= '</tr></thead>';
        $content .= '<tbody id="storage-log-body">' . $renderMovementRows($results['items']) . '</tbody>';
        $content .= '</table></div>';
        $content .= '<div id="storage-log-sentinel" class="parts-load-sentinel" data-has-more="' . ($hasMore ? '1' : '0') . '" data-next-page="' . ($logPage + 1) . '">';
        $content .= '<span class="parts-load-status" data-loading-text="' . htmlspecialchars(t('parts_loading_more')) . '" data-end-text="' . htmlspecialchars(t('parts_no_more')) . '">' . ($hasMore ? '' : htmlspecialchars(t('parts_no_more'))) . '</span>';
        $content .= '</div>';
        $content .= <<<SCRIPT
<script>
(function(){
  var sentinel = document.getElementById('storage-log-sentinel');
  var body = document.getElementById('storage-log-body');
  var status = sentinel ? sentinel.querySelector('.parts-load-status') : null;
  if (!sentinel || !body || !status) { return; }
  var loading = false;

  function loadMore() {
    if (loading || sentinel.dataset.hasMore !== '1') { return; }
    loading = true;
    status.textContent = status.dataset.loadingText;

    var params = new URLSearchParams(window.location.search);
    params.set('ajax', '1');
    params.set('p', sentinel.dataset.nextPage);

    fetch('?' + params.toString(), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        body.insertAdjacentHTML('beforeend', data.html);
        sentinel.dataset.hasMore = data.hasMore ? '1' : '0';
        sentinel.dataset.nextPage = String(parseInt(sentinel.dataset.nextPage, 10) + 1);
        status.textContent = data.hasMore ? '' : status.dataset.endText;
        loading = false;
        if (data.hasMore) { checkAndLoad(); }
      })
      .catch(function() { loading = false; });
  }

  function checkAndLoad() {
    var rect = sentinel.getBoundingClientRect();
    if (rect.top < window.innerHeight + 400) { loadMore(); }
  }

  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) { if (entry.isIntersecting) { loadMore(); } });
    }, { rootMargin: '400px' });
    observer.observe(sentinel);
  } else {
    window.addEventListener('scroll', checkAndLoad);
    checkAndLoad();
  }
})();
</script>
SCRIPT;
    }

    renderApp(t('storage_movements_log_title'), $content, $user, computeAppStats($pdo), [homeBreadcrumb(), ['label' => t('locations_title'), 'url' => '?page=locations'], ['label' => t('storage_movements_log_title'), 'url' => null]]);
    exit;
}

if (isset($_GET['page']) && $_GET['page'] === 'location_detail') {
    $locationId = (int) ($_GET['id'] ?? 0);
    $location = getStorageLocation($locationId);

    if ($location === null) {
        $content = '<h1>' . htmlspecialchars(t('location_detail_not_found_title')) . '</h1>';
        $content .= '<section class="card alert"><p>' . htmlspecialchars(t('location_detail_not_found')) . '</p></section>';
        renderApp(t('location_detail_not_found_title'), $content, $user, computeAppStats($pdo), [homeBreadcrumb(), ['label' => t('locations_title'), 'url' => '?page=locations']]);
        exit;
    }

    $locationBreadcrumbs = [homeBreadcrumb(), ['label' => t('locations_title'), 'url' => '?page=locations']];
    $ancestors = getStorageLocationAncestors($locationId);
    foreach ($ancestors as $i => $ancestor) {
        $isLast = $i === count($ancestors) - 1;
        $locationBreadcrumbs[] = [
            'label' => $ancestor['name'],
            'url' => $isLast ? null : '?page=location_detail&id=' . $ancestor['id'],
        ];
    }

    $content = '<h1>' . htmlspecialchars($location['name']) . '</h1>';
    $content .= '<p class="location-detail-path">' . htmlspecialchars(getStorageLocationPath($locationId)) . '</p>';
    $content .= '<p><a href="?page=locations">' . htmlspecialchars(t('settings_back')) . '</a></p>';

    $items = getLocationStock($locationId);
    if (empty($items)) {
        $content .= '<section class="card"><p>' . htmlspecialchars(t('location_detail_empty')) . '</p></section>';
    } else {
        $thumbnails = getPartThumbnails($pdo, array_values(array_unique(array_column($items, 'part_id'))));
        $content .= '<div class="location-detail-grid">';
        foreach ($items as $item) {
            $thumbnail = $thumbnails[$item['part_id']] ?? null;
            $condLabel = $item['condition_type'] === 'new' ? t('condition_new') : t('condition_used');
            $content .= '<div class="location-detail-card">';
            $content .= '<span class="location-detail-card-qty">' . (int) $item['quantity'] . 'x</span>';
            $content .= '<span class="location-detail-card-thumb">' . ($thumbnail !== null ? '<img src="' . htmlspecialchars($thumbnail) . '" alt="">' : getNavIcon('bricks')) . '</span>';
            $content .= '<span class="location-detail-card-swatch" style="background-color:#' . htmlspecialchars($item['color_rgb'] ?? 'cccccc') . ';"></span>';
            $content .= '<span class="location-detail-card-num">' . htmlspecialchars($item['part_num']) . '</span>';
            $content .= '<span class="location-detail-card-name" title="' . htmlspecialchars($item['part_name']) . '">' . htmlspecialchars($item['part_name']) . '</span>';
            $content .= '<span class="location-detail-card-meta">' . htmlspecialchars(($item['color_name'] ?? '') . ' · ' . $condLabel) . '</span>';
            $content .= '</div>';
        }
        $content .= '</div>';
    }

    renderApp($location['name'], $content, $user, computeAppStats($pdo), $locationBreadcrumbs);
    exit;
}

if (isset($_GET['page']) && $_GET['page'] === 'sets_search') {
    $searchQuery = trim((string) ($_GET['q'] ?? ''));
    $themeParam = isset($_GET['theme']) && $_GET['theme'] !== '' ? (int) $_GET['theme'] : null;
    $pageNum = max(1, (int) ($_GET['p'] ?? 1));
    $perPage = SETS_SEARCH_PAGE_SIZE;
    $isTextSearch = $searchQuery !== '';
    // A text search ignores the theme hierarchy entirely (search across the
    // whole catalog); otherwise, browsing into a theme shows only sets
    // tagged with that exact theme_id — subthemes are reached by drilling
    // further into their own tile, not folded into this level's results.
    $hasResultsGrid = $isTextSearch || $themeParam !== null;

    $renderSetsResultsGrid = function (array $results, int $pageNum, int $perPage) use ($pdo): string {
        $html = '<span class="results-summary">' . htmlspecialchars(t('sets_found_count', ['count' => formatNumber($results['total'])])) . '</span>';
        if (empty($results['items'])) {
            $html .= '<section class="card"><p>' . htmlspecialchars(t('sets_categories_empty')) . '</p></section>';
            return $html;
        }
        $hasMore = $perPage < $results['total'];
        $results['items'] = attachOwnedCounts($pdo, $results['items']);
        $grouped = renderYearGroupedCards($results['items'], null, false, 'renderSetCard');
        $lastYearAttr = $grouped['lastYearKnown'] ? ($grouped['lastYear'] ?? 'unknown') : 'unknown';
        $html .= '<div class="sets-grid" id="sets-grid">' . $grouped['html'] . '</div>';
        $html .= '<div id="sets-load-sentinel" class="parts-load-sentinel" data-has-more="' . ($hasMore ? '1' : '0') . '" data-next-page="2" data-last-year="' . htmlspecialchars((string) $lastYearAttr) . '">';
        $html .= '<span class="parts-load-status" data-loading-text="' . htmlspecialchars(t('parts_loading_more')) . '" data-end-text="' . htmlspecialchars(t('parts_no_more')) . '">' . ($hasMore ? '' : htmlspecialchars(t('parts_no_more'))) . '</span>';
        $html .= '</div>';
        $html .= <<<SCRIPT
<script>
(function(){
  var sentinel = document.getElementById('sets-load-sentinel');
  var grid = document.getElementById('sets-grid');
  var status = sentinel ? sentinel.querySelector('.parts-load-status') : null;
  if (!sentinel || !grid || !status) {
    return;
  }
  var loading = false;

  function loadMore() {
    if (loading || sentinel.dataset.hasMore !== '1') {
      return;
    }
    loading = true;
    status.textContent = status.dataset.loadingText;

    var params = new URLSearchParams(window.location.search);
    params.set('ajax', '1');
    params.set('p', sentinel.dataset.nextPage);
    params.set('lastYear', sentinel.dataset.lastYear);

    fetch('?' + params.toString(), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        grid.insertAdjacentHTML('beforeend', data.html);
        sentinel.dataset.hasMore = data.hasMore ? '1' : '0';
        sentinel.dataset.nextPage = String(parseInt(sentinel.dataset.nextPage, 10) + 1);
        if (data.lastYear !== null) {
          sentinel.dataset.lastYear = data.lastYear;
        }
        status.textContent = data.hasMore ? '' : status.dataset.endText;
        loading = false;
        if (data.hasMore) {
          checkAndLoad();
        }
      })
      .catch(function() {
        loading = false;
      });
  }

  function checkAndLoad() {
    var rect = sentinel.getBoundingClientRect();
    if (rect.top < window.innerHeight + 400) {
      loadMore();
    }
  }

  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (entry.isIntersecting) {
          loadMore();
        }
      });
    }, { rootMargin: '400px' });
    observer.observe(sentinel);
  } else {
    window.addEventListener('scroll', checkAndLoad);
    checkAndLoad();
  }
})();
</script>
SCRIPT;
        return $html;
    };

    // Infinite-scroll continuation request: return just the next batch of
    // cards as JSON instead of a full page render (mirrors bricks_search).
    if ($hasResultsGrid && ($_GET['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        if ($isTextSearch) {
            $selectedThemeIds = [];
        } else {
            $selectedThemeIds = [(string) $themeParam];
        }
        $results = searchSets($pdo, $searchQuery, $selectedThemeIds, $pageNum, $perPage);
        $results['items'] = attachOwnedCounts($pdo, $results['items']);
        $lastYearParam = $_GET['lastYear'] ?? null;
        $startYearKnown = $lastYearParam !== null;
        $startYear = ($lastYearParam !== null && $lastYearParam !== 'unknown') ? (int) $lastYearParam : null;
        $grouped = renderYearGroupedCards($results['items'], $startYear, $startYearKnown, 'renderSetCard');
        $hasMore = ($pageNum * $perPage) < $results['total'];
        echo json_encode([
            'html' => $grouped['html'],
            'hasMore' => $hasMore,
            'lastYear' => $grouped['lastYearKnown'] ? ($grouped['lastYear'] ?? 'unknown') : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $content = '<h1>' . htmlspecialchars(t('nav_sets_search')) . '</h1>';
    $content .= '<form method="get" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '" class="parts-search-form">';
    $content .= '<input type="hidden" name="page" value="sets_search">';
    $content .= '<input type="text" name="q" value="' . htmlspecialchars($searchQuery) . '" placeholder="' . htmlspecialchars(t('sets_search_placeholder')) . '">';
    $content .= '<button type="submit">' . htmlspecialchars(t('search_button')) . '</button>';
    $content .= '</form>';

    $setsBreadcrumbs = [homeBreadcrumb(), ['label' => t('nav_sets_search'), 'url' => $hasResultsGrid ? '?page=sets_search' : null]];

    if ($isTextSearch) {
        $setsBreadcrumbs[] = ['label' => t('search_results_for', ['query' => $searchQuery]), 'url' => null];
        $results = searchSets($pdo, $searchQuery, [], $pageNum, $perPage);
        $content .= $renderSetsResultsGrid($results, $pageNum, $perPage);
    } else {
        $tree = getSetThemeTree($pdo);

        if ($themeParam !== null) {
            $ancestors = getThemeAncestors($tree, $themeParam);
            foreach ($ancestors as $i => $ancestor) {
                $isLast = $i === count($ancestors) - 1;
                $setsBreadcrumbs[] = [
                    'label' => $ancestor['name'],
                    'url' => $isLast ? null : '?page=sets_search&theme=' . $ancestor['theme_id'],
                ];
            }
        }

        $children = getSetThemeChildren($tree, $themeParam);
        if (!empty($children)) {
            $tileImageGroups = [];
            foreach ($children as $child) {
                $tileImageGroups[$child['theme_id']] = getThemeAndDescendantIds($tree, $child['theme_id']);
            }
            $tileImages = getThemeTileImages($pdo, $tileImageGroups);
            $content .= '<div class="category-tile-grid sets-theme-grid">';
            foreach ($children as $child) {
                $img = $tileImages[(string) $child['theme_id']] ?? null;
                $content .= '<a class="category-tile sets-theme-tile" href="?page=sets_search&theme=' . $child['theme_id'] . '">';
                $content .= '<span class="category-tile-image sets-theme-tile-image">' . ($img !== null ? '<img src="' . htmlspecialchars($img) . '" alt="">' : getNavIcon('sets')) . '</span>';
                $content .= '<span class="category-tile-label sets-theme-tile-label">' . htmlspecialchars($child['name']) . ' (' . $child['recursive_count'] . ')</span>';
                $content .= '</a>';
            }
            $content .= '</div>';
        } elseif ($themeParam === null) {
            $content .= '<section class="card"><p>' . htmlspecialchars(t('sets_categories_empty')) . '</p></section>';
        }

        if ($themeParam !== null) {
            $results = searchSets($pdo, '', [(string) $themeParam], $pageNum, $perPage);
            $content .= $renderSetsResultsGrid($results, $pageNum, $perPage);
        }
    }

    renderApp(t('nav_sets_search'), $content, $user, computeAppStats($pdo), $setsBreadcrumbs);
    exit;
}

if (isset($_GET['page']) && $_GET['page'] === 'minifigs_search') {
    $searchQuery = trim((string) ($_GET['q'] ?? ''));
    $themeParam = isset($_GET['theme']) && $_GET['theme'] !== '' ? (int) $_GET['theme'] : null;
    $pageNum = max(1, (int) ($_GET['p'] ?? 1));
    $perPage = MINIFIGS_SEARCH_PAGE_SIZE;
    $isTextSearch = $searchQuery !== '';
    // Same "text search ignores the theme hierarchy entirely" rule as
    // sets_search (see that block above) — browsing into a theme shows only
    // minifigs tagged with that exact theme_id, subthemes are reached by
    // drilling further into their own tile, not folded into this level.
    $hasResultsGrid = $isTextSearch || $themeParam !== null;

    // Mirrors sets_search's own $renderSetsResultsGrid closure — shared by
    // the text-search branch and the theme-drill-down branch below so the
    // card grid + infinite-scroll markup isn't duplicated between them.
    $renderMinifigsResultsGrid = function (array $results, int $pageNum, int $perPage) use ($pdo): string {
        $html = '<span class="results-summary">' . htmlspecialchars(t('minifigs_found_count', ['count' => formatNumber($results['total'])])) . '</span>';
        if (empty($results['items'])) {
            $html .= '<section class="card"><p>' . htmlspecialchars(t('minifigs_categories_empty')) . '</p></section>';
            return $html;
        }
        // Included so a component-part tile inside the minifig modal
        // (built as a plain .part-card, see renderMinifigDetailModal()'s
        // script) is picked up by this modal's own document-level click
        // delegation — same "works unchanged wherever its cards show up"
        // reasoning as everywhere else renderPartDetailModal() is used.
        $html .= renderPartDetailModal();
        $html .= renderMinifigDetailModal();
        $hasMore = $perPage < $results['total'];
        $grouped = renderYearGroupedCards($results['items'], null, false, 'renderMinifigCard');
        $lastYearAttr = $grouped['lastYearKnown'] ? ($grouped['lastYear'] ?? 'unknown') : 'unknown';
        $html .= '<div class="minifigs-grid" id="minifigs-grid">' . $grouped['html'] . '</div>';
        $html .= '<div id="minifigs-load-sentinel" class="parts-load-sentinel" data-has-more="' . ($hasMore ? '1' : '0') . '" data-next-page="2" data-last-year="' . htmlspecialchars((string) $lastYearAttr) . '">';
        $html .= '<span class="parts-load-status" data-loading-text="' . htmlspecialchars(t('parts_loading_more')) . '" data-end-text="' . htmlspecialchars(t('parts_no_more')) . '">' . ($hasMore ? '' : htmlspecialchars(t('parts_no_more'))) . '</span>';
        $html .= '</div>';
        $html .= <<<SCRIPT
<script>
(function(){
  var sentinel = document.getElementById('minifigs-load-sentinel');
  var grid = document.getElementById('minifigs-grid');
  var status = sentinel ? sentinel.querySelector('.parts-load-status') : null;
  if (!sentinel || !grid || !status) {
    return;
  }
  var loading = false;

  function loadMore() {
    if (loading || sentinel.dataset.hasMore !== '1') {
      return;
    }
    loading = true;
    status.textContent = status.dataset.loadingText;

    var params = new URLSearchParams(window.location.search);
    params.set('ajax', '1');
    params.set('p', sentinel.dataset.nextPage);
    params.set('lastYear', sentinel.dataset.lastYear);

    fetch('?' + params.toString(), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        grid.insertAdjacentHTML('beforeend', data.html);
        sentinel.dataset.hasMore = data.hasMore ? '1' : '0';
        sentinel.dataset.nextPage = String(parseInt(sentinel.dataset.nextPage, 10) + 1);
        if (data.lastYear !== null) {
          sentinel.dataset.lastYear = data.lastYear;
        }
        status.textContent = data.hasMore ? '' : status.dataset.endText;
        loading = false;
        if (data.hasMore) {
          checkAndLoad();
        }
      })
      .catch(function() {
        loading = false;
      });
  }

  function checkAndLoad() {
    var rect = sentinel.getBoundingClientRect();
    if (rect.top < window.innerHeight + 400) {
      loadMore();
    }
  }

  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (entry.isIntersecting) {
          loadMore();
        }
      });
    }, { rootMargin: '400px' });
    observer.observe(sentinel);
  } else {
    window.addEventListener('scroll', checkAndLoad);
    checkAndLoad();
  }
})();
</script>
SCRIPT;
        return $html;
    };

    // Infinite-scroll continuation request: return just the next batch of
    // cards as JSON instead of a full page render (mirrors bricks_search).
    if ($hasResultsGrid && ($_GET['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        if ($isTextSearch) {
            $selectedThemeIds = [];
        } else {
            $selectedThemeIds = [(string) $themeParam];
        }
        $results = searchMinifigs($pdo, $searchQuery, $selectedThemeIds, $pageNum, $perPage);
        $lastYearParam = $_GET['lastYear'] ?? null;
        $startYearKnown = $lastYearParam !== null;
        $startYear = ($lastYearParam !== null && $lastYearParam !== 'unknown') ? (int) $lastYearParam : null;
        $grouped = renderYearGroupedCards($results['items'], $startYear, $startYearKnown, 'renderMinifigCard');
        $hasMore = ($pageNum * $perPage) < $results['total'];
        echo json_encode([
            'html' => $grouped['html'],
            'hasMore' => $hasMore,
            'lastYear' => $grouped['lastYearKnown'] ? ($grouped['lastYear'] ?? 'unknown') : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $content = '<h1>' . htmlspecialchars(t('nav_minifigs_search')) . '</h1>';
    $content .= '<form method="get" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '" class="parts-search-form">';
    $content .= '<input type="hidden" name="page" value="minifigs_search">';
    $content .= '<input type="text" name="q" value="' . htmlspecialchars($searchQuery) . '" placeholder="' . htmlspecialchars(t('minifigs_search_placeholder')) . '">';
    $content .= '<button type="submit">' . htmlspecialchars(t('search_button')) . '</button>';
    $content .= '</form>';

    $minifigsBreadcrumbs = [homeBreadcrumb(), ['label' => t('nav_minifigs_search'), 'url' => $hasResultsGrid ? '?page=minifigs_search' : null]];

    if ($isTextSearch) {
        $minifigsBreadcrumbs[] = ['label' => t('search_results_for', ['query' => $searchQuery]), 'url' => null];
        $results = searchMinifigs($pdo, $searchQuery, [], $pageNum, $perPage);
        $content .= $renderMinifigsResultsGrid($results, $pageNum, $perPage);
    } else {
        $tree = getMinifigThemeTree($pdo);

        if ($themeParam !== null) {
            $ancestors = getThemeAncestors($tree, $themeParam);
            foreach ($ancestors as $i => $ancestor) {
                $isLast = $i === count($ancestors) - 1;
                $minifigsBreadcrumbs[] = [
                    'label' => $ancestor['name'],
                    'url' => $isLast ? null : '?page=minifigs_search&theme=' . $ancestor['theme_id'],
                ];
            }
        }

        $children = getSetThemeChildren($tree, $themeParam);
        if (!empty($children)) {
            $tileImageGroups = [];
            foreach ($children as $child) {
                $tileImageGroups[$child['theme_id']] = getThemeAndDescendantIds($tree, $child['theme_id']);
            }
            $tileImages = getMinifigThemeTileImages($pdo, $tileImageGroups);
            $content .= '<div class="category-tile-grid sets-theme-grid">';
            foreach ($children as $child) {
                $img = $tileImages[(string) $child['theme_id']] ?? null;
                $content .= '<a class="category-tile sets-theme-tile" href="?page=minifigs_search&theme=' . $child['theme_id'] . '">';
                $content .= '<span class="category-tile-image sets-theme-tile-image">' . ($img !== null ? '<img src="' . htmlspecialchars($img) . '" alt="">' : getNavIcon('minifigs')) . '</span>';
                $content .= '<span class="category-tile-label sets-theme-tile-label">' . htmlspecialchars($child['name']) . ' (' . $child['recursive_count'] . ')</span>';
                $content .= '</a>';
            }
            $content .= '</div>';
        } elseif ($themeParam === null) {
            $content .= '<section class="card"><p>' . htmlspecialchars(t('minifigs_categories_empty')) . '</p></section>';
        }

        if ($themeParam !== null) {
            $results = searchMinifigs($pdo, '', [(string) $themeParam], $pageNum, $perPage);
            $content .= $renderMinifigsResultsGrid($results, $pageNum, $perPage);
        }
    }

    renderApp(t('nav_minifigs_search'), $content, $user, computeAppStats($pdo), $minifigsBreadcrumbs);
    exit;
}

// "Bauen" nav dropdown entry — getBuildableMinifigsResults() (src/build.php)
// reads a tick-scanned cache (buildable_minifigs_cache), this just renders
// it as a ranked table. Each row opens the dedicated "Bauen" modal
// (renderBuildMinifigModal(), .build-minifig-row click delegation defined
// in that same function) — not the generic catalog minifig-detail modal
// these cards would otherwise open elsewhere, since this page's whole point
// is a build action, not just viewing.
if (isset($_GET['page']) && $_GET['page'] === 'build_minifigs') {
    $buildMinifigsMeta = getBuildableMinifigsCacheMeta();
    $buildMinifigsBreadcrumbs = [homeBreadcrumb(), ['label' => t('nav_build_minifigs'), 'url' => null]];

    // No configure step (unlike build_sets — this scan has no theme/year
    // scope to pick, it's always the whole catalog): a fresh install or an
    // explicit refresh click goes straight into the scan overlay.
    if (isset($_GET['scan']) || $buildMinifigsMeta['computedAt'] === null) {
        $content = '<h1>' . htmlspecialchars(t('nav_build_minifigs')) . '</h1>';
        $content .= renderBuildMinifigsScanOverlay();
        renderApp(t('nav_build_minifigs'), $content, $user, computeAppStats($pdo), $buildMinifigsBreadcrumbs);
        exit;
    }

    $buildableMinifigs = getBuildableMinifigsResults($pdo);

    $content = '<h1>' . htmlspecialchars(t('nav_build_minifigs')) . '</h1>';
    $content .= '<p class="hint">' . htmlspecialchars(t('build_sets_last_updated', ['date' => formatDate($buildMinifigsMeta['computedAt'], true)])) . '</p>';
    if ($buildMinifigsMeta['stale']) {
        $content .= '<section class="card build-sets-stale-banner">';
        $content .= '<p>' . htmlspecialchars(t('build_sets_stale_banner')) . '</p>';
        $content .= '<a class="filter-apply-button" href="?page=build_minifigs&scan=1">' . htmlspecialchars(t('build_sets_refresh_button')) . '</a>';
        $content .= '</section>';
    }
    if (empty($buildableMinifigs)) {
        $content .= '<section class="card"><p>' . htmlspecialchars(t('build_minifigs_empty')) . '</p></section>';
    } else {
        $content .= renderBuildMinifigModal();

        // Filter sidebar, mirroring bricks_search's own filter-facet
        // pattern (src/parts.php's getPartCategoriesWithCounts()/
        // getColorFacet()): facet options/counts are computed from the
        // full, unfiltered candidate list, same convention as that page —
        // not re-scoped as other filters get applied. The whole list is
        // already fully materialized in PHP (no pagination here), so
        // filtering happens in-memory after getBuildableMinifigsResults()
        // rather than pushing it into a query — that function is already
        // just a plain cache read, no benefit to complicating it further.
        $buildSearchQuery = trim((string) ($_GET['q'] ?? ''));
        $selectedThemeId = isset($_GET['theme']) && $_GET['theme'] !== '' ? (int) $_GET['theme'] : null;
        $priceFrom = isset($_GET['price_from']) && $_GET['price_from'] !== '' ? (float) $_GET['price_from'] : null;
        $priceTo = isset($_GET['price_to']) && $_GET['price_to'] !== '' ? (float) $_GET['price_to'] : null;
        $selectedYear = isset($_GET['year']) && $_GET['year'] !== '' ? (int) $_GET['year'] : null;
        $pricedOnly = ($_GET['priced_only'] ?? '') === '1';
        $minBuildable = isset($_GET['min_buildable']) && $_GET['min_buildable'] !== '' ? max(0, (int) $_GET['min_buildable']) : null;

        // Theme facet as a real parent/child tree — mirrors sets_search's/
        // minifigs_search's own theme drill-down (buildThemeTree(),
        // getSetThemeChildren(), getThemeAncestors(), all src/sets.php):
        // every catalog theme is loaded (regardless of whether any candidate
        // is tagged with it, so parent-child edges are always complete),
        // direct_count is how many *unfiltered* candidates carry that exact
        // theme_id, and buildThemeTree() rolls that up into recursive_count
        // per ancestor automatically. Selecting a theme filters to it plus
        // every descendant (getThemeAndDescendantIds()) — same "browsing a
        // parent shows its whole subtree" behavior as those other pages —
        // via a real link to click, not a checkbox, per explicit request.
        $themeDirectCounts = [];
        $yearFacetCounts = [];
        foreach ($buildableMinifigs as $facetRow) {
            foreach ($facetRow['theme_ids'] as $themeId) {
                $themeDirectCounts[$themeId] = ($themeDirectCounts[$themeId] ?? 0) + 1;
            }
            if ($facetRow['year'] !== null) {
                $yearFacetCounts[$facetRow['year']] = ($yearFacetCounts[$facetRow['year']] ?? 0) + 1;
            }
        }
        krsort($yearFacetCounts);

        $allThemeRows = $pdo->query('SELECT theme_id, name, parent_theme_id FROM themes')->fetchAll();
        foreach ($allThemeRows as &$themeRow) {
            $themeRow['direct_count'] = $themeDirectCounts[(int) $themeRow['theme_id']] ?? 0;
        }
        unset($themeRow);
        $buildThemeTree = buildThemeTree($allThemeRows);
        $selectedThemeDescendantIds = $selectedThemeId !== null ? getThemeAndDescendantIds($buildThemeTree, $selectedThemeId) : [];

        $buildableMinifigs = array_values(array_filter($buildableMinifigs, function (array $row) use ($buildSearchQuery, $selectedThemeDescendantIds, $priceFrom, $priceTo, $selectedYear, $pricedOnly, $minBuildable): bool {
            if ($buildSearchQuery !== '') {
                $haystack = ($row['name'] ?? '') . ' ' . $row['fig_num'];
                if (stripos($haystack, $buildSearchQuery) === false) {
                    return false;
                }
            }
            if (!empty($selectedThemeDescendantIds) && empty(array_intersect($selectedThemeDescendantIds, $row['theme_ids']))) {
                return false;
            }
            if ($pricedOnly && $row['bricklink_price_used'] === null) {
                return false;
            }
            if ($priceFrom !== null || $priceTo !== null) {
                if ($row['bricklink_price_used'] === null) {
                    return false;
                }
                if ($priceFrom !== null && $row['bricklink_price_used'] < $priceFrom) {
                    return false;
                }
                if ($priceTo !== null && $row['bricklink_price_used'] > $priceTo) {
                    return false;
                }
            }
            if ($selectedYear !== null && $row['year'] !== $selectedYear) {
                return false;
            }
            if ($minBuildable !== null && $row['buildable'] < $minBuildable) {
                return false;
            }
            return true;
        }));

        // Two separate <form>s (search bar + sidebar), each carrying the
        // other's current state as hidden fields, so submitting either one
        // preserves both — the same pattern bricks_search's own text search
        // + filter sidebar already use. The theme tree isn't part of either
        // form (it's plain links, not inputs) but still rides along as a
        // hidden field on both so it survives a search or a "Filter
        // anwenden" submit.
        $buildFilterParams = ['page' => 'build_minifigs'];
        if ($buildSearchQuery !== '') {
            $buildFilterParams['q'] = $buildSearchQuery;
        }
        if ($selectedThemeId !== null) {
            $buildFilterParams['theme'] = $selectedThemeId;
        }
        if ($priceFrom !== null) {
            $buildFilterParams['price_from'] = $priceFrom;
        }
        if ($priceTo !== null) {
            $buildFilterParams['price_to'] = $priceTo;
        }
        if ($selectedYear !== null) {
            $buildFilterParams['year'] = $selectedYear;
        }
        if ($pricedOnly) {
            $buildFilterParams['priced_only'] = '1';
        }
        if ($minBuildable !== null) {
            $buildFilterParams['min_buildable'] = $minBuildable;
        }

        $renderBuildHiddenFields = function (array $params, array $exclude) {
            $html = '';
            foreach ($params as $key => $value) {
                if (in_array($key, $exclude, true)) {
                    continue;
                }
                foreach ((array) $value as $singleValue) {
                    $name = is_array($value) ? $key . '[]' : $key;
                    $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars((string) $singleValue) . '">';
                }
            }
            return $html;
        };

        // Builds a full "?..." link for the theme tree's own navigation,
        // starting from every currently active filter and overriding just
        // the theme (null removes it) — so clicking a theme (or "Alle
        // Themen") never drops the other active filters.
        $buildMinifigsUrl = function (array $overrides) use ($buildFilterParams): string {
            $params = $overrides + $buildFilterParams;
            foreach ($overrides as $key => $value) {
                if ($value === null) {
                    unset($params[$key]);
                }
            }
            $query = [];
            foreach ($params as $key => $value) {
                foreach ((array) $value as $singleValue) {
                    $name = is_array($value) ? $key . '[]' : $key;
                    $query[] = urlencode($name) . '=' . urlencode((string) $singleValue);
                }
            }
            return '?' . implode('&', $query);
        };

        $hasActiveFilter = $buildSearchQuery !== '' || $selectedThemeId !== null || $priceFrom !== null || $priceTo !== null || $selectedYear !== null || $pricedOnly || $minBuildable !== null;

        $content .= '<form method="get" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '" class="parts-search-form">';
        $content .= '<input type="hidden" name="page" value="build_minifigs">';
        $content .= $renderBuildHiddenFields($buildFilterParams, ['page', 'q']);
        $content .= '<input type="text" name="q" value="' . htmlspecialchars($buildSearchQuery) . '" placeholder="' . htmlspecialchars(t('build_minifigs_search_placeholder')) . '">';
        $content .= '<button type="submit">' . htmlspecialchars(t('search_button')) . '</button>';
        $content .= '</form>';

        $sidebar = '<form method="get" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '" class="parts-filter-sidebar">';
        $sidebar .= '<input type="hidden" name="page" value="build_minifigs">';
        $sidebar .= $renderBuildHiddenFields($buildFilterParams, ['page', 'price_from', 'price_to', 'year', 'priced_only', 'min_buildable']);

        $sidebar .= '<div class="filter-group"><h3>' . htmlspecialchars(t('build_minifigs_filter_theme')) . '</h3>';
        $themeAncestors = $selectedThemeId !== null ? getThemeAncestors($buildThemeTree, $selectedThemeId) : [];
        $sidebar .= '<p class="filter-theme-breadcrumb">';
        if (empty($themeAncestors)) {
            $sidebar .= '<strong>' . htmlspecialchars(t('build_minifigs_filter_theme_all')) . '</strong>';
        } else {
            $crumbParts = ['<a href="' . htmlspecialchars($buildMinifigsUrl(['theme' => null])) . '">' . htmlspecialchars(t('build_minifigs_filter_theme_all')) . '</a>'];
            $lastIndex = count($themeAncestors) - 1;
            foreach ($themeAncestors as $i => $ancestor) {
                $crumbParts[] = $i === $lastIndex
                    ? '<strong>' . htmlspecialchars($ancestor['name']) . '</strong>'
                    : '<a href="' . htmlspecialchars($buildMinifigsUrl(['theme' => $ancestor['theme_id']])) . '">' . htmlspecialchars($ancestor['name']) . '</a>';
            }
            $sidebar .= implode(' » ', $crumbParts);
        }
        $sidebar .= '</p>';
        $themeChildren = getSetThemeChildren($buildThemeTree, $selectedThemeId);
        if (!empty($themeChildren)) {
            $sidebar .= '<div class="filter-options">';
            foreach ($themeChildren as $child) {
                $sidebar .= '<a class="filter-theme-link" href="' . htmlspecialchars($buildMinifigsUrl(['theme' => $child['theme_id']])) . '">' . htmlspecialchars($child['name']) . ' <span class="filter-count">(' . $child['recursive_count'] . ')</span></a>';
            }
            $sidebar .= '</div>';
        }
        $sidebar .= '</div>';

        $sidebar .= '<div class="filter-group"><h3>' . htmlspecialchars(t('build_minifigs_filter_price')) . '</h3><div class="filter-range-inputs">';
        $sidebar .= '<input type="number" step="0.01" min="0" name="price_from" placeholder="' . htmlspecialchars(t('build_minifigs_filter_price_from')) . '" value="' . ($priceFrom !== null ? htmlspecialchars((string) $priceFrom) : '') . '">';
        $sidebar .= '<span>&ndash;</span>';
        $sidebar .= '<input type="number" step="0.01" min="0" name="price_to" placeholder="' . htmlspecialchars(t('build_minifigs_filter_price_to')) . '" value="' . ($priceTo !== null ? htmlspecialchars((string) $priceTo) : '') . '">';
        $sidebar .= '</div>';
        $sidebar .= '<label class="filter-checkbox"><input type="checkbox" name="priced_only" value="1"' . ($pricedOnly ? ' checked' : '') . '> ' . htmlspecialchars(t('build_minifigs_filter_priced_only')) . '</label>';
        $sidebar .= '</div>';

        $sidebar .= '<div class="filter-group"><h3>' . htmlspecialchars(t('build_minifigs_filter_year')) . '</h3>';
        $sidebar .= '<select name="year"><option value="">' . htmlspecialchars(t('build_minifigs_filter_year_all')) . '</option>';
        foreach ($yearFacetCounts as $yearOption => $yearCount) {
            $selectedAttr = $selectedYear === $yearOption ? ' selected' : '';
            $sidebar .= '<option value="' . $yearOption . '"' . $selectedAttr . '>' . $yearOption . ' (' . $yearCount . ')</option>';
        }
        $sidebar .= '</select></div>';

        $sidebar .= '<div class="filter-group"><h3>' . htmlspecialchars(t('build_minifigs_filter_min_buildable')) . '</h3>';
        $sidebar .= '<input type="number" min="1" step="1" name="min_buildable" placeholder="' . htmlspecialchars(t('build_minifigs_filter_min_buildable_placeholder')) . '" value="' . ($minBuildable !== null ? (string) $minBuildable : '') . '"></div>';

        $sidebar .= '<button type="submit" class="filter-apply-button">' . htmlspecialchars(t('filter_apply_button')) . '</button>';
        if ($hasActiveFilter) {
            $sidebar .= '<a href="?page=build_minifigs" class="filter-reset-link">' . htmlspecialchars(t('filter_reset_button')) . '</a>';
        }
        $sidebar .= '</form>';

        $content .= '<div class="parts-search-layout">' . $sidebar . '<div class="parts-search-main">';
        $content .= '<span class="results-summary">' . htmlspecialchars(t('build_minifigs_results_count', ['count' => formatNumber(count($buildableMinifigs))])) . '</span>';

        // Minifig IDs still needing a BrickLink price — never checked, or
        // checked more than 3 months ago (deliberately longer than the
        // passive per-owned-minifig background sync's own 30-day interval,
        // BRICKLINK_SYNC_INTERVAL_DAYS in src/bricklink_prices.php — that
        // one trickles through page loads a few at a time, this is a much
        // bigger, user-triggered batch, so a longer "still fresh enough"
        // window keeps it from re-running unnecessarily often). A fetch is
        // 2-3 sequential external HTTP calls each (see
        // refreshBricklinkPriceForMinifig()'s doc comment,
        // src/bricklink_prices.php), too slow to do inline for potentially
        // hundreds of rows in one request, so it's an opt-in tick loop
        // instead (same pacing as the BrickLink XML part-id sync,
        // renderOwnedSetBricklinkModal(), src/owned_sets.php), driven by the
        // existing single-minifig action=refresh_minifig_bricklink_price —
        // no new backend endpoint needed.
        $bricklinkPriceStaleBefore = date('Y-m-d H:i:s', strtotime('-3 months'));
        $unpricedMinifigIds = [];
        foreach ($buildableMinifigs as $row) {
            if ($row['bricklink_price_checked_at'] === null || $row['bricklink_price_checked_at'] < $bricklinkPriceStaleBefore) {
                $unpricedMinifigIds[] = $row['minifig_id'];
            }
        }

        if (!empty($unpricedMinifigIds)) {
            $fetchPricesLabel = t('build_minifigs_fetch_prices_button', ['count' => (string) count($unpricedMinifigIds)]);
            $content .= '<button type="button" class="fetch-missing-images-btn" id="build-minifigs-fetch-prices-open">' . htmlspecialchars($fetchPricesLabel) . '</button>';

            $content .= '<div class="modal-overlay" id="build-minifigs-price-modal" style="display:none;">';
            $content .= '<div class="modal-box"><button type="button" class="modal-close" id="build-minifigs-price-modal-close" aria-label="' . htmlspecialchars(t('close_button')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>';
            $content .= '<h2>' . htmlspecialchars(t('build_minifigs_fetch_prices_heading')) . '</h2>';
            $content .= '<p class="hint" id="build-minifigs-price-status">0 / ' . count($unpricedMinifigIds) . '</p>';
            $content .= '</div></div>';

            $unpricedIdsJson = json_encode($unpricedMinifigIds, JSON_HEX_TAG | JSON_HEX_AMP);
            $doneLabelJson = json_encode(t('build_minifigs_fetch_prices_done'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $content .= <<<SCRIPT
<script>
(function(){
  var ids = {$unpricedIdsJson};
  var openBtn = document.getElementById('build-minifigs-fetch-prices-open');
  var modal = document.getElementById('build-minifigs-price-modal');
  var closeBtn = document.getElementById('build-minifigs-price-modal-close');
  var status = document.getElementById('build-minifigs-price-status');
  if (!openBtn || !modal || !closeBtn || !status) {
    return;
  }
  openBtn.addEventListener('click', function() {
    modal.style.display = 'flex';
    openBtn.disabled = true;
    var total = ids.length;
    var done = 0;
    function nextTick(index) {
      if (index >= ids.length) {
        status.textContent = {$doneLabelJson};
        window.location.reload();
        return;
      }
      var startedAt = Date.now();
      var formData = new FormData();
      formData.set('action', 'refresh_minifig_bricklink_price');
      formData.set('minifig_id', ids[index]);
      formData.set('condition_type', 'used');
      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .catch(function() { return { success: false }; })
        .then(function() {
          done++;
          status.textContent = done + ' / ' + total;
          // BrickLink's own pages, same ~1 req/sec courtesy pacing as the
          // part-id sync — timed from when this tick started, not when it
          // finished, so a slow response doesn't get "made up for".
          var elapsed = Date.now() - startedAt;
          var wait = Math.max(0, 1000 - elapsed);
          setTimeout(function() { nextTick(index + 1); }, wait);
        });
    }
    nextTick(0);
  });
  closeBtn.addEventListener('click', function() {
    modal.style.display = 'none';
  });
})();
</script>
SCRIPT;
        }

        if (empty($buildableMinifigs)) {
            $content .= '<section class="card"><p>' . htmlspecialchars(t('build_minifigs_filtered_empty')) . '</p></section>';
        } else {
            $content .= '<div class="set-detail-table-wrap">';
            $content .= '<table class="set-detail-table build-minifigs-table">';
            $content .= '<thead><tr>';
            $content .= '<th></th>';
            $content .= '<th>' . htmlspecialchars(t('pdf_report_col_name')) . '</th>';
            $content .= '<th>' . htmlspecialchars(t('build_minifigs_col_theme')) . '</th>';
            $content .= '<th>' . htmlspecialchars(t('my_minifigs_top100_price_column')) . '</th>';
            $content .= '<th>' . htmlspecialchars(t('build_minifigs_col_buildable')) . '</th>';
            $content .= '</tr></thead><tbody>';
            foreach ($buildableMinifigs as $row) {
                $name = $row['name'] ?? $row['fig_num'];
                $priceText = $row['bricklink_price_used'] !== null
                    ? formatNumber($row['bricklink_price_used'], 2) . ' ' . bricklinkCurrencySymbol($row['bricklink_price_currency'])
                    : t('build_minifigs_price_unknown');
                $content .= '<tr class="build-minifig-row" data-minifig-id="' . $row['minifig_id'] . '" role="button" tabindex="0">';
                $content .= '<td class="build-minifigs-thumb-cell">' . ($row['thumbnail'] !== null ? '<img src="' . htmlspecialchars($row['thumbnail']) . '" alt="">' : getNavIcon('minifigs')) . '</td>';
                $content .= '<td>' . htmlspecialchars($name) . ' <span class="hint">' . htmlspecialchars($row['fig_num']) . '</span></td>';
                $content .= '<td class="hint">' . htmlspecialchars($row['theme_path']) . '</td>';
                $content .= '<td>' . htmlspecialchars($priceText) . '</td>';
                $content .= '<td>' . formatNumber($row['buildable']) . '</td>';
                $content .= '</tr>';
            }
            $content .= '</tbody></table></div>';
        }
        $content .= '</div></div>';
    }

    renderApp(t('nav_build_minifigs'), $content, $user, computeAppStats($pdo), [homeBreadcrumb(), ['label' => t('nav_build_minifigs'), 'url' => null]]);
    exit;
}

/**
 * One "Baubare Sets" result tile — shared by the initial page render and the
 * ajax=1 infinite-scroll continuation (both below), so the two can never
 * drift apart in markup.
 */
function renderBuildableSetTile(array $row): string
{
    $html = '<a class="buildable-set-tile" href="?page=set_detail&id=' . $row['set_id'] . '">';
    $html .= '<span class="buildable-set-tile-image">' . ($row['thumbnail'] !== null ? '<img src="' . htmlspecialchars($row['thumbnail']) . '" alt="">' : getNavIcon('sets')) . '</span>';
    $html .= '<span class="buildable-set-tile-name">' . htmlspecialchars($row['name']) . ' <span class="hint">' . htmlspecialchars($row['rebrickable_set_num']) . '</span></span>';

    $metrics = [
        ['build_sets_tile_total', $row['total_percent'], $row['total_actual'], $row['total_nominal']],
        ['build_sets_tile_exclusive', $row['exclusive_percent'], $row['exclusive_actual'], $row['exclusive_nominal']],
        ['build_sets_tile_rare', $row['rare_percent'], $row['rare_actual'], $row['rare_nominal']],
        ['build_sets_tile_minifigs', $row['minifig_percent'], $row['minifig_actual'], $row['minifig_nominal']],
    ];
    $html .= '<div class="buildable-set-tile-metrics">';
    foreach ($metrics as [$labelKey, $percent, $actual, $nominal]) {
        $html .= '<div class="buildable-set-tile-metric">';
        $html .= '<span class="buildable-set-tile-metric-label">' . htmlspecialchars(t($labelKey)) . '</span>';
        $html .= '<div class="progress-track buildable-set-tile-bar"><div class="progress-fill" style="width:' . $percent . '%"></div></div>';
        $html .= '<span class="buildable-set-tile-metric-value">' . formatNumber($percent, 1) . ' % (' . formatNumber($actual) . '/' . formatNumber($nominal) . ')</span>';
        $html .= '</div>';
    }
    $html .= '</div>';
    $html .= '</a>';
    return $html;
}

// "Baubare Sets" — three states driven by GET params, see src/build_sets.php's
// own doc comment for the overall design: ?scan=1[&theme=&year_from=&year_to=]
// shows only the dark progress overlay and kicks off the tick loop;
// ?configure=1 (or no cache at all yet) shows the theme/year/completeness
// config form; otherwise the cached results are shown directly (one page at
// a time, see BUILD_SETS_RESULTS_PAGE_SIZE), with a staleness banner if the
// loose stock changed since the last scan.
if (isset($_GET['page']) && $_GET['page'] === 'build_sets') {
    $buildSetsMeta = getBuildableSetsCacheMeta($pdo);
    $buildSetsExclusiveOnly = ($_GET['exclusive_only'] ?? '') === '1';
    $buildSetsExclusiveRareOnly = ($_GET['exclusive_rare_only'] ?? '') === '1';
    $buildSetsBreadcrumbs = [homeBreadcrumb(), ['label' => t('nav_build_sets'), 'url' => null]];

    if (isset($_GET['scan'])) {
        $scanThemeId = isset($_GET['theme']) && $_GET['theme'] !== '' ? (int) $_GET['theme'] : null;
        $scanYearFrom = isset($_GET['year_from']) && $_GET['year_from'] !== '' ? (int) $_GET['year_from'] : null;
        $scanYearTo = isset($_GET['year_to']) && $_GET['year_to'] !== '' ? (int) $_GET['year_to'] : null;

        $content = '<h1>' . htmlspecialchars(t('nav_build_sets')) . '</h1>';
        $content .= renderBuildSetsScanOverlay($scanThemeId, $scanYearFrom, $scanYearTo, $buildSetsExclusiveOnly, $buildSetsExclusiveRareOnly);
        renderApp(t('nav_build_sets'), $content, $user, computeAppStats($pdo), $buildSetsBreadcrumbs);
        exit;
    }

    if (isset($_GET['configure']) || $buildSetsMeta['computedAt'] === null) {
        $configThemeId = isset($_GET['theme']) && $_GET['theme'] !== '' ? (int) $_GET['theme'] : null;
        $configYearFrom = isset($_GET['year_from']) && $_GET['year_from'] !== '' ? (int) $_GET['year_from'] : null;
        $configYearTo = isset($_GET['year_to']) && $_GET['year_to'] !== '' ? (int) $_GET['year_to'] : null;

        $buildSetsThemeTree = getSetThemeTree($pdo);

        // Always emits every field explicitly (theme='' rather than
        // omitting the key when cleared) so the config page's own state
        // never has to fall back to a different source once the user has
        // started navigating it — every link here is fully self-describing.
        $configUrl = function (array $overrides) use ($configThemeId, $configYearFrom, $configYearTo, $buildSetsExclusiveOnly, $buildSetsExclusiveRareOnly): string {
            $params = [
                'page' => 'build_sets',
                'configure' => '1',
                'theme' => $configThemeId !== null ? (string) $configThemeId : '',
                'year_from' => $configYearFrom !== null ? (string) $configYearFrom : '',
                'year_to' => $configYearTo !== null ? (string) $configYearTo : '',
                'exclusive_only' => $buildSetsExclusiveOnly ? '1' : '',
                'exclusive_rare_only' => $buildSetsExclusiveRareOnly ? '1' : '',
            ];
            foreach ($overrides as $key => $value) {
                $params[$key] = $value !== null ? (string) $value : '';
            }
            $query = [];
            foreach ($params as $key => $value) {
                if ($value === '') {
                    continue;
                }
                $query[] = urlencode($key) . '=' . urlencode($value);
            }
            return '?' . implode('&', $query);
        };

        $content = '<h1>' . htmlspecialchars(t('nav_build_sets')) . '</h1>';
        $content .= '<p class="hint">' . htmlspecialchars(t('build_sets_config_intro')) . '</p>';

        $content .= '<section class="card"><h3>' . htmlspecialchars(t('build_sets_filter_theme')) . '</h3>';
        $themeAncestors = $configThemeId !== null ? getThemeAncestors($buildSetsThemeTree, $configThemeId) : [];
        $content .= '<p class="filter-theme-breadcrumb">';
        if (empty($themeAncestors)) {
            $content .= '<strong>' . htmlspecialchars(t('build_sets_filter_theme_all')) . '</strong>';
        } else {
            $crumbParts = ['<a href="' . htmlspecialchars($configUrl(['theme' => null])) . '">' . htmlspecialchars(t('build_sets_filter_theme_all')) . '</a>'];
            $lastIndex = count($themeAncestors) - 1;
            foreach ($themeAncestors as $i => $ancestor) {
                $crumbParts[] = $i === $lastIndex
                    ? '<strong>' . htmlspecialchars($ancestor['name']) . '</strong>'
                    : '<a href="' . htmlspecialchars($configUrl(['theme' => $ancestor['theme_id']])) . '">' . htmlspecialchars($ancestor['name']) . '</a>';
            }
            $content .= implode(' » ', $crumbParts);
        }
        $content .= '</p>';
        $themeChildren = getSetThemeChildren($buildSetsThemeTree, $configThemeId);
        if (!empty($themeChildren)) {
            $content .= '<div class="filter-options">';
            foreach ($themeChildren as $child) {
                $content .= '<a class="filter-theme-link" href="' . htmlspecialchars($configUrl(['theme' => $child['theme_id']])) . '">' . htmlspecialchars($child['name']) . ' <span class="filter-count">(' . formatNumber($child['recursive_count']) . ')</span></a>';
            }
            $content .= '</div>';
        }
        $content .= '</section>';

        $content .= '<section class="card">';
        $content .= '<form method="get" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '">';
        $content .= '<input type="hidden" name="page" value="build_sets">';
        $content .= '<input type="hidden" name="scan" value="1">';
        $content .= '<input type="hidden" name="theme" value="' . ($configThemeId !== null ? (int) $configThemeId : '') . '">';

        $content .= '<h3>' . htmlspecialchars(t('build_sets_filter_year')) . '</h3>';
        $content .= '<div class="filter-range-inputs">';
        $content .= '<input type="number" name="year_from" placeholder="' . htmlspecialchars(t('build_sets_filter_year_from')) . '" value="' . ($configYearFrom !== null ? (int) $configYearFrom : '') . '">';
        $content .= '<span>&ndash;</span>';
        $content .= '<input type="number" name="year_to" placeholder="' . htmlspecialchars(t('build_sets_filter_year_to')) . '" value="' . ($configYearTo !== null ? (int) $configYearTo : '') . '">';
        $content .= '</div>';

        $content .= '<h3>' . htmlspecialchars(t('build_sets_filter_completeness')) . '</h3>';
        $content .= '<label class="filter-checkbox"><input type="checkbox" name="exclusive_only" value="1"' . ($buildSetsExclusiveOnly ? ' checked' : '') . '> ' . htmlspecialchars(t('build_sets_filter_exclusive_only')) . '</label>';
        $content .= '<label class="filter-checkbox"><input type="checkbox" name="exclusive_rare_only" value="1"' . ($buildSetsExclusiveRareOnly ? ' checked' : '') . '> ' . htmlspecialchars(t('build_sets_filter_exclusive_rare_only')) . '</label>';

        $content .= '<button type="submit" class="filter-apply-button">' . htmlspecialchars(t('build_sets_start_scan_button')) . '</button>';
        $content .= '</form>';
        $content .= '</section>';

        renderApp(t('nav_build_sets'), $content, $user, computeAppStats($pdo), $buildSetsBreadcrumbs);
        exit;
    }

    $buildSetsPage = max(1, (int) ($_GET['p'] ?? 1));
    $buildSetsResults = getBuildableSetsResults($pdo, $buildSetsExclusiveOnly, $buildSetsExclusiveRareOnly, $buildSetsPage, BUILD_SETS_RESULTS_PAGE_SIZE);
    $buildSetsHasMore = ($buildSetsPage * BUILD_SETS_RESULTS_PAGE_SIZE) < $buildSetsResults['total'];

    // Infinite-scroll continuation (same sentinel+IntersectionObserver
    // pattern as sets_search/minifigs_search/bricks_search) — checked here,
    // before any of the full page's own header/nav markup gets built, since
    // an ajax=1 request only ever wants the next batch of tiles as JSON.
    // exclusive_only/exclusive_rare_only ride along automatically: the
    // client builds this request from the current page's own query string,
    // which already carries them if set.
    if (($_GET['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        $ajaxHtml = '';
        foreach ($buildSetsResults['items'] as $row) {
            $ajaxHtml .= renderBuildableSetTile($row);
        }
        echo json_encode(['html' => $ajaxHtml, 'hasMore' => $buildSetsHasMore], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $scope = $buildSetsMeta['scope'];

    $content = '<h1>' . htmlspecialchars(t('nav_build_sets')) . '</h1>';

    $scopeParts = [];
    if ($scope !== null) {
        if ($scope['theme_name'] !== null) {
            $scopeParts[] = htmlspecialchars($scope['theme_name']);
        }
        if ($scope['year_from'] !== null || $scope['year_to'] !== null) {
            $scopeParts[] = htmlspecialchars(($scope['year_from'] ?? '…') . '–' . ($scope['year_to'] ?? '…'));
        }
    }
    $scopeText = !empty($scopeParts) ? implode(' · ', $scopeParts) : htmlspecialchars(t('build_sets_filter_theme_all'));
    $content .= '<p class="hint">' . htmlspecialchars(t('build_sets_last_updated', ['date' => formatDate($buildSetsMeta['computedAt'], true)])) . ' — ' . $scopeText . '</p>';

    $scopeQuery = ($scope['theme_id'] ?? null) !== null ? '&theme=' . $scope['theme_id'] : '';
    $scopeQuery .= ($scope['year_from'] ?? null) !== null ? '&year_from=' . $scope['year_from'] : '';
    $scopeQuery .= ($scope['year_to'] ?? null) !== null ? '&year_to=' . $scope['year_to'] : '';
    $filterQuery = ($buildSetsExclusiveOnly ? '&exclusive_only=1' : '') . ($buildSetsExclusiveRareOnly ? '&exclusive_rare_only=1' : '');

    if ($buildSetsMeta['stale']) {
        $content .= '<section class="card build-sets-stale-banner">';
        $content .= '<p>' . htmlspecialchars(t('build_sets_stale_banner')) . '</p>';
        $content .= '<a class="filter-apply-button" href="?page=build_sets&scan=1' . $scopeQuery . $filterQuery . '">' . htmlspecialchars(t('build_sets_refresh_button')) . '</a>';
        $content .= '</section>';
    }

    $content .= '<form method="get" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '" class="build-sets-toolbar">';
    $content .= '<input type="hidden" name="page" value="build_sets">';
    $content .= '<label class="filter-checkbox"><input type="checkbox" name="exclusive_only" value="1"' . ($buildSetsExclusiveOnly ? ' checked' : '') . '> ' . htmlspecialchars(t('build_sets_filter_exclusive_only')) . '</label>';
    $content .= '<label class="filter-checkbox"><input type="checkbox" name="exclusive_rare_only" value="1"' . ($buildSetsExclusiveRareOnly ? ' checked' : '') . '> ' . htmlspecialchars(t('build_sets_filter_exclusive_rare_only')) . '</label>';
    $content .= '<button type="submit" class="filter-apply-button">' . htmlspecialchars(t('filter_apply_button')) . '</button>';
    $content .= '<a href="?page=build_sets&configure=1' . $scopeQuery . $filterQuery . '">' . htmlspecialchars(t('build_sets_change_filter_link')) . '</a>';
    $content .= '</form>';

    $content .= '<span class="results-summary">' . htmlspecialchars(t('build_sets_results_count', ['count' => formatNumber($buildSetsResults['total'])])) . '</span>';

    if (empty($buildSetsResults['items'])) {
        $content .= '<section class="card"><p>' . htmlspecialchars(t('build_sets_empty')) . '</p></section>';
    } else {
        $content .= '<div class="buildable-sets-grid" id="build-sets-grid">';
        foreach ($buildSetsResults['items'] as $row) {
            $content .= renderBuildableSetTile($row);
        }
        $content .= '</div>';

        // Same sentinel+IntersectionObserver infinite-scroll pattern as
        // sets_search/minifigs_search/bricks_search — see the ajax=1 branch
        // above for the continuation endpoint this fetches from. This is
        // what replaced rendering all ~19,600 unfiltered-catalog tiles in
        // one page load (confirmed live to make the whole server
        // unresponsive — see BUILD_SETS_RESULTS_PAGE_SIZE's own doc comment,
        // src/build_sets.php).
        $content .= '<div id="build-sets-load-sentinel" class="parts-load-sentinel" data-has-more="' . ($buildSetsHasMore ? '1' : '0') . '" data-next-page="' . ($buildSetsPage + 1) . '">';
        $content .= '<span class="parts-load-status" data-loading-text="' . htmlspecialchars(t('parts_loading_more')) . '" data-end-text="' . htmlspecialchars(t('parts_no_more')) . '">' . ($buildSetsHasMore ? '' : htmlspecialchars(t('parts_no_more'))) . '</span>';
        $content .= '</div>';
        $content .= <<<SCRIPT
<script>
(function(){
  var sentinel = document.getElementById('build-sets-load-sentinel');
  var grid = document.getElementById('build-sets-grid');
  var status = sentinel ? sentinel.querySelector('.parts-load-status') : null;
  if (!sentinel || !grid || !status) return;
  var loading = false;
  function loadMore() {
    if (loading || sentinel.dataset.hasMore !== '1') return;
    loading = true;
    status.textContent = status.dataset.loadingText;
    var params = new URLSearchParams(window.location.search);
    params.set('ajax', '1');
    params.set('p', sentinel.dataset.nextPage);
    fetch('?' + params.toString(), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        grid.insertAdjacentHTML('beforeend', data.html);
        sentinel.dataset.hasMore = data.hasMore ? '1' : '0';
        sentinel.dataset.nextPage = String(parseInt(sentinel.dataset.nextPage, 10) + 1);
        status.textContent = data.hasMore ? '' : status.dataset.endText;
        loading = false;
        if (data.hasMore) checkAndLoad();
      })
      .catch(function() { loading = false; });
  }
  function checkAndLoad() {
    var rect = sentinel.getBoundingClientRect();
    if (rect.top < window.innerHeight + 400) loadMore();
  }
  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) { if (entry.isIntersecting) loadMore(); });
    }, { rootMargin: '400px' });
    observer.observe(sentinel);
  } else {
    window.addEventListener('scroll', checkAndLoad);
    checkAndLoad();
  }
})();
</script>
SCRIPT;
    }

    renderApp(t('nav_build_sets'), $content, $user, computeAppStats($pdo), $buildSetsBreadcrumbs);
    exit;
}

if (isset($_GET['page']) && $_GET['page'] === 'set_detail') {
    $setId = (int) ($_GET['id'] ?? 0);
    $set = getSetById($pdo, $setId);

    if ($set === null) {
        $content = '<h1>' . htmlspecialchars(t('set_detail_not_found_title')) . '</h1>';
        $content .= '<section class="card alert"><p>' . htmlspecialchars(t('set_detail_not_found')) . '</p></section>';
        renderApp(t('set_detail_not_found_title'), $content, $user, computeAppStats($pdo), [homeBreadcrumb(), ['label' => t('nav_sets_search'), 'url' => '?page=sets_search']]);
        exit;
    }

    // A set can have more than one Rebrickable inventory revision (e.g. LEGO
    // 4563 has a v1 and a v2 — the contents changed mid-production). With
    // just one revision the tab bar shows a plain "Inventar" tab as before;
    // with more than one, each revision gets its own "Inventar V{n}" tab
    // plus a "Vergleich" tab right after them, showing only the parts whose
    // quantity actually differs between revisions.
    $inventoryVersions = getSetInventoryVersions($pdo, $set['rebrickable_set_num']);

    $setTabs = [];
    if (count($inventoryVersions) <= 1) {
        $setTabs['inventory'] = t('set_detail_tab_inventory');
    } else {
        foreach ($inventoryVersions as $v) {
            $setTabs['inventory_v' . $v['version']] = t('set_detail_tab_inventory_version', ['version' => (string) $v['version']]);
        }
        $setTabs['compare'] = t('set_detail_tab_compare');
    }
    $setTabs['spares'] = t('set_detail_tab_spares');
    $setTabs['minifigs'] = t('set_detail_tab_minifigs');
    $setTabs['instructions'] = t('set_detail_tab_instructions');
    $setTabs['gallery'] = t('set_detail_tab_gallery');
    $setTabs['stocktake'] = t('set_detail_tab_stocktake');
    $setTabs['purchase'] = t('set_detail_tab_purchase');

    $activeTab = (string) ($_GET['tab'] ?? '');
    if (!isset($setTabs[$activeTab])) {
        $activeTab = array_key_first($setTabs);
    }

    $setDetailBreadcrumbs = [homeBreadcrumb(), ['label' => t('nav_sets_search'), 'url' => '?page=sets_search']];
    if ($set['theme_id'] !== null) {
        $themeTree = getSetThemeTree($pdo);
        foreach (getThemeAncestors($themeTree, $set['theme_id']) as $ancestor) {
            $setDetailBreadcrumbs[] = ['label' => $ancestor['name'], 'url' => '?page=sets_search&theme=' . $ancestor['theme_id']];
        }
    }
    $setDetailBreadcrumbs[] = ['label' => $set['name'], 'url' => '?page=set_detail&id=' . $setId];
    $setDetailBreadcrumbs[] = ['label' => $setTabs[$activeTab], 'url' => null];

    // Spares and minifigs aren't split per revision — only the regular
    // inventory differs meaningfully enough between versions to be worth
    // the extra tabs, so those two always show the latest revision. The
    // header's Inventar summary (below) always reflects this latest
    // revision too, regardless of which tab is active.
    $latestInventoryId = getSetInventoryId($pdo, $set['rebrickable_set_num']);

    $adjacentSets = getAdjacentSets($pdo, $set['rebrickable_set_num']);
    $inventorySummary = $latestInventoryId !== null
        ? getSetInventorySummary($pdo, $latestInventoryId, getLocale())
        : [
            'total_nominal' => 0, 'total_actual' => 0,
            'exclusive_nominal' => 0, 'exclusive_actual' => 0,
            'rare_nominal' => 0, 'rare_actual' => 0,
            'stickers_nominal' => 0, 'stickers_actual' => 0,
        ];
    $inventoryTotalPercent = $inventorySummary['total_nominal'] > 0
        ? ($inventorySummary['total_actual'] / $inventorySummary['total_nominal'] * 100)
        : 0.0;

    $content = '<div class="set-detail-header">';
    $content .= '<span class="set-detail-image">' . ($set['thumbnail'] !== null ? '<img src="' . htmlspecialchars($set['thumbnail']) . '" alt="">' : getNavIcon('sets')) . '</span>';
    $content .= '<div class="set-detail-info">';
    $content .= '<div class="set-detail-panel">';

    $content .= '<h1 class="set-detail-title">' . htmlspecialchars($set['rebrickable_set_num']) . '</h1>';

    $content .= '<div class="set-detail-setnav">';
    $content .= $adjacentSets['prev'] !== null
        ? '<a href="?page=set_detail&id=' . $adjacentSets['prev']['id'] . '">&lsaquo; ' . htmlspecialchars($adjacentSets['prev']['rebrickable_set_num']) . '</a>'
        : '<span></span>';
    $content .= $adjacentSets['next'] !== null
        ? '<a href="?page=set_detail&id=' . $adjacentSets['next']['id'] . '">' . htmlspecialchars($adjacentSets['next']['rebrickable_set_num']) . ' &rsaquo;</a>'
        : '<span></span>';
    $content .= '</div>';

    $content .= renderSetGeneralInfoTable($pdo, $set, $themeTree ?? null);

    $totalOwnedTooltip = t('set_detail_owned_tooltip_total', ['actual' => (string) $inventorySummary['total_actual'], 'nominal' => (string) $inventorySummary['total_nominal']]);
    $exclusiveOwnedTooltip = t('set_detail_owned_tooltip_exclusive', ['actual' => (string) $inventorySummary['exclusive_actual'], 'nominal' => (string) $inventorySummary['exclusive_nominal']]);
    $rareOwnedTooltip = t('set_detail_owned_tooltip_rare', ['actual' => (string) $inventorySummary['rare_actual'], 'nominal' => (string) $inventorySummary['rare_nominal']]);
    $stickersOwnedTooltip = t('set_detail_owned_tooltip_stickers', ['actual' => (string) $inventorySummary['stickers_actual'], 'nominal' => (string) $inventorySummary['stickers_nominal']]);

    $content .= '<div class="set-detail-table-wrap">';
    $content .= '<span class="set-detail-table-heading">' . htmlspecialchars(t('set_detail_inventory_heading')) . '</span>';
    $content .= '<table class="set-detail-table">';
    $content .= '<tr class="owned-set-total-row"><td colspan="2">' . renderOwnedSetTotalRing($inventoryTotalPercent, $inventorySummary['total_actual'], $inventorySummary['total_nominal'], true, true, $totalOwnedTooltip) . '</td></tr>';
    $content .= '<tr><th>' . htmlspecialchars(t('set_detail_field_exclusive')) . '</th><td>' . (int) $inventorySummary['exclusive_nominal'] . ' (<span title="' . htmlspecialchars($exclusiveOwnedTooltip) . '">' . (int) $inventorySummary['exclusive_actual'] . '</span>)</td></tr>';
    $content .= '<tr><th>' . htmlspecialchars(t('set_detail_field_rare')) . '</th><td>' . (int) $inventorySummary['rare_nominal'] . ' (<span title="' . htmlspecialchars($rareOwnedTooltip) . '">' . (int) $inventorySummary['rare_actual'] . '</span>)</td></tr>';
    $content .= '<tr><th>' . htmlspecialchars(t('set_detail_field_stickers')) . '</th><td>' . (int) $inventorySummary['stickers_nominal'] . ' (<span title="' . htmlspecialchars($stickersOwnedTooltip) . '">' . (int) $inventorySummary['stickers_actual'] . '</span>)</td></tr>';
    $content .= '</table>';
    $content .= '</div>';

    $content .= '<div class="set-detail-table-wrap">';
    $content .= '<span class="set-detail-table-heading">' . htmlspecialchars(t('set_detail_actions_heading')) . '</span>';

    $ownedInstances = getOwnedSetsForSet($pdo, $setId);
    if (!empty($ownedInstances)) {
        $content .= '<table class="set-detail-table">';
        $content .= '<tr><th>' . htmlspecialchars(t('set_detail_owned_label')) . '</th><td>';
        $ownedLinks = [];
        foreach ($ownedInstances as $i => $inst) {
            $ownedLinks[] = '<a href="?page=owned_set_detail&id=' . $inst['id'] . '">#' . ($i + 1) . '</a>';
        }
        $content .= htmlspecialchars(t('set_detail_owned_count', ['count' => (string) count($ownedInstances)])) . ' (' . implode(', ', $ownedLinks) . ')';
        $content .= '</td></tr>';
        $content .= '</table>';
    }

    $content .= '<div class="owned-set-actionbar">';
    $content .= '<a class="owned-set-action-pill" href="#" id="add-owned-set-open" title="' . htmlspecialchars(t('set_detail_add_to_collection_button')) . '" aria-label="' . htmlspecialchars(t('set_detail_add_to_collection_button')) . '">' . getActionIcon('add_to_collection') . '</a>';
    $content .= '<a class="owned-set-action-pill" href="#" id="set-pick-list-open" title="' . htmlspecialchars(t('set_detail_pick_list_button')) . '" aria-label="' . htmlspecialchars(t('set_detail_pick_list_button')) . '">' . getActionIcon('add_to_pick_list') . '</a>';
    $content .= '</div>';
    $content .= renderAddOwnedSetWizardModal($pdo, $setId);
    $content .= renderCreatePickListFromSetModal($setId);

    $content .= '</div>';

    $content .= '</div></div></div>';

    $content .= '<nav class="set-detail-tabs">';
    foreach ($setTabs as $tabKey => $tabLabel) {
        $activeAttr = $tabKey === $activeTab ? ' class="active"' : '';
        $content .= '<a' . $activeAttr . ' href="?page=set_detail&id=' . $setId . '&tab=' . $tabKey . '">' . htmlspecialchars($tabLabel) . '</a>';
    }
    $content .= '</nav>';

    // $groupByRarity splits the inventory into "Exklusive" (this is the
    // only set the part+color appears in), "Seltene" (2-3 sets total) and
    // "Normale" (everything else) — exclusive/rare status is scoped to the
    // exact part+color, not just the part, per user request ("beachte auch
    // Farbe und Print"): a part_id already implies "print" (a printed
    // variant is its own part_num/part_id, e.g. "3001pr0001" vs "3001"), so
    // scoping getPartSetCounts() by (part_id, color_id) covers both. Only
    // meaningful for the regular inventory tab, not spares — callers pass
    // false there.
    // $looseStock (getLooseStockMap(), src/storage.php — same "exclude
    // owned_set locations" stock map "Baubare Minifiguren"/"Baubare Sets"
    // already use), when given, adds a per-card border marking whether the
    // needed quantity+color is on hand right now: 'complete' (green, full
    // amount available), 'partial' (green-to-red gradient, some but not
    // enough), 'missing' (red, none at all). Null for callers that don't
    // want this (currently: the spares tab, where it's less meaningful).
    $renderSetPartsGrid = function (array $items, bool $groupByRarity = false, ?int $inventoryId = null, ?array $looseStock = null) use ($pdo): string {
        $missingCount = 0;
        $renderCard = function (array $item) use (&$missingCount, $looseStock): string {
            $part = [
                'id' => $item['part_id'],
                'part_num' => $item['part_num'],
                'name' => $item['name'],
                // Fallback chain: cached color-correct image, then the
                // bulk-downloaded (possibly wrong-color) image, then a live
                // hotlink to Rebrickable's own CDN, then the icon fallback
                // renderPartCard() already applies when thumbnail is null.
                'thumbnail' => $item['ldraw_thumbnail'] ?? $item['thumbnail'] ?? $item['remote_thumbnail'] ?? null,
            ];
            $meta = ($item['color_name'] ?? '') . ' · ' . $item['quantity'] . 'x';
            // Only mark the card as needing a fetch when we don't already
            // have the color-correct cached image and there's an actual
            // color to ask Rebrickable for.
            $fetchColorId = $item['ldraw_thumbnail'] === null ? $item['rebrickable_color_id'] : null;
            if ($fetchColorId !== null) {
                $missingCount++;
            }
            $stockStatus = null;
            if ($looseStock !== null) {
                $need = (int) $item['quantity'];
                $have = $looseStock[$item['part_id'] . ':' . $item['color_id']] ?? 0;
                $stockStatus = $have <= 0 ? 'missing' : ($have >= $need ? 'complete' : 'partial');
            }
            return renderPartCard($part, $meta, $fetchColorId, $stockStatus);
        };

        $cardsHtml = '';
        if ($groupByRarity) {
            $stickerPartIds = $inventoryId !== null ? getStickerPartIds($pdo, $inventoryId) : [];

            $pairs = [];
            foreach ($items as $item) {
                if ($item['rebrickable_color_id'] !== null && !isset($stickerPartIds[$item['part_id']])) {
                    $pairs[] = ['part_id' => $item['part_id'], 'color_id' => $item['rebrickable_color_id']];
                }
            }
            $setCounts = getPartSetCounts($pdo, $pairs);

            $buckets = ['exclusive' => [], 'rare' => [], 'normal' => [], 'stickers' => []];
            foreach ($items as $item) {
                if (isset($stickerPartIds[$item['part_id']])) {
                    $buckets['stickers'][] = $item;
                    continue;
                }
                $count = $item['rebrickable_color_id'] !== null
                    ? ($setCounts[$item['part_id'] . ':' . $item['rebrickable_color_id']] ?? 0)
                    : 0;
                if ($count === 1) {
                    $buckets['exclusive'][] = $item;
                } elseif ($count >= 2 && $count <= 3) {
                    $buckets['rare'][] = $item;
                } else {
                    $buckets['normal'][] = $item;
                }
            }

            $groupLabels = [
                'exclusive' => t('set_detail_group_exclusive'),
                'rare' => t('set_detail_group_rare'),
                'normal' => t('set_detail_group_normal'),
                'stickers' => t('set_detail_group_stickers'),
            ];
            foreach ($groupLabels as $bucketKey => $label) {
                if (empty($buckets[$bucketKey])) {
                    continue;
                }
                $cardsHtml .= '<div class="group-header"><span class="group-header-label">' . htmlspecialchars($label) . '</span><hr class="group-header-rule"></div>';
                foreach ($buckets[$bucketKey] as $item) {
                    $cardsHtml .= $renderCard($item);
                }
            }
        } else {
            foreach ($items as $item) {
                $cardsHtml .= $renderCard($item);
            }
        }

        $html = renderPartDetailModal();
        $html .= renderFetchMissingImagesButton('set-parts-grid', $missingCount);
        $html .= '<div class="parts-grid" id="set-parts-grid">' . $cardsHtml . '</div>';
        $html .= renderFetchMissingImagesScript();
        return $html;
    };

    if ($activeTab === 'inventory' || strpos($activeTab, 'inventory_v') === 0) {
        if ($activeTab === 'inventory') {
            $targetInventoryId = $latestInventoryId;
        } else {
            $targetVersion = (int) substr($activeTab, strlen('inventory_v'));
            $targetInventoryId = null;
            foreach ($inventoryVersions as $v) {
                if ($v['version'] === $targetVersion) {
                    $targetInventoryId = $v['inventory_id'];
                    break;
                }
            }
        }
        $items = $targetInventoryId !== null ? getSetPartsList($pdo, $targetInventoryId, false, getLocale()) : [];
        $content .= empty($items)
            ? '<section class="card"><p>' . htmlspecialchars(t('set_detail_inventory_empty')) . '</p></section>'
            : $renderSetPartsGrid($items, true, $targetInventoryId, getLooseStockMap($pdo));

        if ($targetInventoryId !== null && ldrawContextualRenderingReady()) {
            $missingLdrawPairs = getMissingLdrawRenderPairs($pdo, $items);
            if (!empty($missingLdrawPairs)) {
                $content .= renderLdrawRenderOverlay($targetInventoryId);
            }
        }
    } elseif ($activeTab === 'compare') {
        $comparisonRows = getSetInventoryComparison($pdo, $inventoryVersions, getLocale());
        if (empty($comparisonRows)) {
            $content .= '<section class="card"><p>' . htmlspecialchars(t('set_detail_compare_empty')) . '</p></section>';
        } else {
            $versionNumbers = array_map(function (array $v): int {
                return $v['version'];
            }, $inventoryVersions);

            $tableHtml = '<table id="set-compare-table" class="set-compare-table"><thead><tr><th colspan="2">' . htmlspecialchars(t('set_detail_tab_inventory')) . '</th>';
            foreach ($versionNumbers as $vn) {
                $tableHtml .= '<th>' . htmlspecialchars(t('set_detail_compare_version_col', ['version' => (string) $vn])) . '</th>';
            }
            $tableHtml .= '</tr></thead><tbody>';
            $missingCount = 0;
            foreach ($comparisonRows as $row) {
                $thumbnail = $row['ldraw_thumbnail'] ?? $row['thumbnail'] ?? $row['remote_thumbnail'] ?? null;
                $fetchColorId = $row['ldraw_thumbnail'] === null ? $row['rebrickable_color_id'] : null;
                $dataAttrs = $fetchColorId !== null ? ' data-part-id="' . (int) $row['part_id'] . '" data-color-id="' . $fetchColorId . '"' : '';
                if ($fetchColorId !== null) {
                    $missingCount++;
                }
                $tableHtml .= '<tr>';
                $tableHtml .= '<td class="set-compare-thumb"' . $dataAttrs . '>';
                $tableHtml .= '<span class="part-card-image">' . ($thumbnail !== null ? '<img src="' . htmlspecialchars($thumbnail) . '" alt="">' : getNavIcon('bricks')) . '</span></td>';
                $tableHtml .= '<td class="set-compare-name"><strong>' . htmlspecialchars($row['part_num']) . '</strong> ' . htmlspecialchars($row['name']);
                if ($row['color_name'] !== null) {
                    $tableHtml .= '<br><span class="set-compare-color"><span class="set-compare-swatch" style="background-color:#' . htmlspecialchars($row['color_rgb'] ?? 'cccccc') . ';"></span>' . htmlspecialchars($row['color_name']) . '</span>';
                }
                $tableHtml .= '</td>';
                foreach ($versionNumbers as $vn) {
                    $tableHtml .= '<td class="set-compare-qty">' . (int) $row['quantities'][$vn] . '</td>';
                }
                $tableHtml .= '</tr>';
            }
            $tableHtml .= '</tbody></table>';

            $content .= renderFetchMissingImagesButton('set-compare-table', $missingCount);
            $content .= '<div class="table-scroll">' . $tableHtml . '</div>';
            $content .= renderFetchMissingImagesScript();
        }
    } elseif ($activeTab === 'spares') {
        $items = $latestInventoryId !== null ? getSetPartsList($pdo, $latestInventoryId, true, getLocale()) : [];
        $content .= empty($items)
            ? '<section class="card"><p>' . htmlspecialchars(t('set_detail_spares_empty')) . '</p></section>'
            : $renderSetPartsGrid($items);
    } elseif ($activeTab === 'minifigs') {
        $figs = $latestInventoryId !== null ? getSetMinifigsList($pdo, $latestInventoryId) : [];
        if (empty($figs)) {
            $content .= '<section class="card"><p>' . htmlspecialchars(t('set_detail_minifigs_empty')) . '</p></section>';
        } else {
            $content .= '<div class="minifigs-grid">';
            foreach ($figs as $fig) {
                $content .= renderMinifigCard($fig, $fig['quantity'] . 'x');
            }
            $content .= '</div>';
        }
    } elseif ($activeTab === 'instructions') {
        $content .= renderSetInstructionsTab($setId);
    } else {
        $content .= '<section class="card"><p>' . htmlspecialchars(t('part_purchase_placeholder')) . '</p></section>';
    }

    $setPageTitle = t('set_detail_page_title', ['set_num' => $set['rebrickable_set_num'], 'name' => $set['name']]);
    renderApp($setPageTitle, $content, $user, computeAppStats($pdo), $setDetailBreadcrumbs);
    exit;
}

if (isset($_GET['page']) && $_GET['page'] === 'owned_set_detail') {
    $ownedSetId = (int) ($_GET['id'] ?? 0);
    $ownedSet = getOwnedSetById($pdo, $ownedSetId);

    if ($ownedSet === null) {
        $content = '<h1>' . htmlspecialchars(t('owned_set_not_found_title')) . '</h1>';
        $content .= '<section class="card alert"><p>' . htmlspecialchars(t('owned_set_not_found')) . '</p></section>';
        renderApp(t('owned_set_not_found_title'), $content, $user, computeAppStats($pdo), [homeBreadcrumb(), ['label' => t('nav_my_sets_all'), 'url' => '?page=my_sets_all']]);
        exit;
    }

    // Renders one tab's content — shared by the full page render below and
    // the ajax=1 branch right after it, so a tab switch only ever needs to
    // run this one tab's queries instead of the whole page's.
    $renderOwnedTabContent = function (string $tabKey) use ($pdo, $ownedSet): string {
        if ($tabKey === 'inventory') {
            $parts = getOwnedSetPartsWithStatus($pdo, $ownedSet, getLocale());
            return renderOwnedSetInventoryGrid($pdo, $ownedSet, $parts, 'owned', 'damaged', true);
        }
        if ($tabKey === 'spares') {
            $spareParts = getOwnedSetSparePartsWithStatus($pdo, $ownedSet, getLocale());
            return renderOwnedSetInventoryGrid($pdo, $ownedSet, $spareParts, 'spare_owned', 'spare_damaged');
        }
        if ($tabKey === 'stickers') {
            $stickerParts = getOwnedSetStickerPartsWithStatus($pdo, $ownedSet, getLocale());
            return renderOwnedSetInventoryGrid($pdo, $ownedSet, $stickerParts, 'sticker_owned', 'sticker_damaged');
        }
        if ($tabKey === 'minifigs') {
            $ownedFigs = getOwnedSetMinifigsWithStatus($pdo, $ownedSet);
            return renderOwnedSetMinifigInventoryGrid($pdo, $ownedSet, $ownedFigs);
        }
        if ($tabKey === 'damaged_missing') {
            return renderOwnedSetDamagedMissingSection($pdo, $ownedSet);
        }
        if ($tabKey === 'instructions') {
            // Keyed by the catalog set, not this owned instance — every
            // physical copy of the same set shares the same uploaded PDFs
            // (see renderSetInstructionsTab()'s doc comment).
            return renderSetInstructionsTab($ownedSet['set_id']);
        }
        return renderOwnedSetPhotoGallery($pdo, $ownedSet);
    };
    $ownedSetTabKeys = ['inventory', 'spares', 'stickers', 'minifigs', 'damaged_missing', 'instructions', 'gallery'];

    // AJAX tab-content request (see the tab-loading script further down) —
    // only meaningful once the instance is opened (sealed sets have no
    // tabs at all). Returns just this one tab's HTML plus fresh app-wide
    // stats, bypassing the rest of the page (image, sidebar tables, etc.)
    // entirely, so a tab switch doesn't re-run those unrelated queries.
    if ($ownedSet['condition_type'] !== 'new' && ($_GET['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        $ajaxTabKey = (string) ($_GET['tab'] ?? '');
        if (!in_array($ajaxTabKey, $ownedSetTabKeys, true)) {
            $ajaxTabKey = $ownedSetTabKeys[0];
        }
        echo json_encode([
            'success' => true,
            'html' => $renderOwnedTabContent($ajaxTabKey),
            'stats' => computeAppStats($pdo),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ownedSetBreadcrumbs = [
        homeBreadcrumb(),
        ['label' => t('nav_my_sets_all'), 'url' => '?page=my_sets_all'],
    ];
    if ($ownedSet['theme_id'] !== null) {
        $ownedSetThemeTree = getOwnedSetThemeTree($pdo);
        foreach (getThemeAncestors($ownedSetThemeTree, $ownedSet['theme_id']) as $ownedSetThemeAncestor) {
            $ownedSetBreadcrumbs[] = [
                'label' => $ownedSetThemeAncestor['name'],
                'url' => '?page=my_sets_themes&theme=' . $ownedSetThemeAncestor['theme_id'],
            ];
        }
    }
    $ownedSetBreadcrumbs[] = ['label' => $ownedSet['name'], 'url' => '?page=set_detail&id=' . $ownedSet['set_id']];
    // The instance number isn't stored on owned_sets itself (only baked into
    // its auto-generated storage location's name, see addOwnedSet()) — same
    // 1-based "position among this set's owned instances, oldest first"
    // getOwnedSetsForSet() already establishes for the catalog set_detail
    // page's "#1, #2, ..." links. Reused below for the instance picker
    // dropdown too, rather than querying the same sibling list twice.
    $ownedSetSiblings = getOwnedSetsForSet($pdo, $ownedSet['set_id']);
    $ownedSetInstanceNumber = 1;
    foreach ($ownedSetSiblings as $ownedSetInstanceIndex => $ownedSetInstance) {
        if ($ownedSetInstance['id'] === $ownedSet['id']) {
            $ownedSetInstanceNumber = $ownedSetInstanceIndex + 1;
            break;
        }
    }
    $ownedSetBreadcrumbs[] = ['label' => t('owned_set_instance_label', ['n' => (string) $ownedSetInstanceNumber]), 'url' => null];

    $completeness = getOwnedSetCompleteness($pdo, $ownedSet);
    // Ancestors only, not the set's own auto-generated leaf location itself
    // (always the last entry — see location_type 'owned_set' elsewhere in this
    // codebase) — showing "... » Cargo Station (4555-1) #1" on the Cargo
    // Station (4555-1) #1 page itself is redundant, the "Lagerort" row is
    // about where the set physically sits, not the set's own name again.
    $locationPath = getStorageLocationAncestors($ownedSet['location_id']);
    array_pop($locationPath);
    $adjacentOwnedSets = getAdjacentOwnedSets($pdo, $ownedSet);

    // Layout: image (+ room for a future set description, not built yet) top
    // left, the info sidebar spanning the full height to its right, and the
    // tabs/tab-content below the image — per user sketch, a deliberate
    // departure from the catalog set-detail page's stacked layout, so this
    // uses its own class names rather than reusing .set-detail-header/-info/
    // -panel (those stay untouched for the catalog page).
    $content = '<div class="owned-set-layout">';

    $content .= '<div class="owned-set-image-row">';
    $content .= '<span class="set-detail-image">' . ($ownedSet['thumbnail'] !== null ? '<img src="' . htmlspecialchars($ownedSet['thumbnail']) . '" alt="">' : getNavIcon('sets')) . '</span>';
    $content .= '</div>';

    $content .= '<div class="owned-set-sidebar" id="owned-set-sidebar">';
    // Heading + prev/next merged into one row ("< 1111-1  1112-1  1113-1 >")
    // instead of two stacked ones — same prev/next nav as the catalog
    // set-detail page, just walking this user's own owned-set instances
    // (getAdjacentOwnedSets()) instead of the whole catalog, and linking to
    // owned_set_detail instead of set_detail.
    $content .= '<div class="set-detail-title-row">';
    $content .= $adjacentOwnedSets['prev'] !== null
        ? '<a class="set-detail-setnav-link" href="?page=owned_set_detail&id=' . $adjacentOwnedSets['prev']['id'] . '">&lsaquo; ' . htmlspecialchars($adjacentOwnedSets['prev']['rebrickable_set_num']) . '</a>'
        : '<span class="set-detail-setnav-link"></span>';
    $content .= '<h1 class="set-detail-title">' . htmlspecialchars($ownedSet['rebrickable_set_num']) . '</h1>';
    $content .= $adjacentOwnedSets['next'] !== null
        ? '<a class="set-detail-setnav-link" href="?page=owned_set_detail&id=' . $adjacentOwnedSets['next']['id'] . '">' . htmlspecialchars($adjacentOwnedSets['next']['rebrickable_set_num']) . ' &rsaquo;</a>'
        : '<span class="set-detail-setnav-link"></span>';
    $content .= '</div>';

    if ($ownedSetDetailMessage !== '') {
        $content .= '<p class="owned-set-message">' . htmlspecialchars($ownedSetDetailMessage) . '</p>';
    }

    // Instance picker — jumps straight to any other owned copy of this same
    // set (getOwnedSetsForSet(), already fetched above as $ownedSetSiblings
    // for the "#n" numbering), the set-detail-page counterpart to "Meine
    // Sets"' grouped card (renderOwnedSetGroupCard()): that card links to
    // just one representative copy, this is how the rest are reached. A
    // modal with a scrollable row list (renderOwnedInstancePickerModal(),
    // src/minifigs.php, shared with owned_minifig_detail above) rather than
    // a plain <select> — a location path in an <option> made the dropdown
    // uncomfortably wide, and a modal row can show each copy's own ampel
    // status dot besides. Deliberately separate from the prev/next nav
    // above, which walks the whole catalog (getAdjacentOwnedSets()) rather
    // than staying within this one set's own copies.
    if (count($ownedSetSiblings) > 1) {
        $ownedSetPickerRows = [];
        foreach ($ownedSetSiblings as $i => $sibling) {
            $sibling['rebrickable_set_num'] = $ownedSet['rebrickable_set_num'];
            $siblingLocationPath = getStorageLocationAncestors($sibling['location_id']);
            array_pop($siblingLocationPath);
            $optLocation = implode(' -> ', array_column($siblingLocationPath, 'name'));
            $optCond = $sibling['condition_type'] === 'new' ? t('owned_set_condition_new') : t('owned_set_condition_used');
            $ownedSetPickerRows[] = [
                'id' => $sibling['id'],
                'label' => t('owned_set_instance_label', ['n' => (string) ($i + 1)]),
                'meta' => implode(' · ', array_filter([$optLocation, $optCond], fn (string $v): bool => $v !== '')),
                'status' => getOwnedSetInstanceStatus($pdo, $sibling),
            ];
        }
        $content .= '<button type="button" class="owned-instance-picker-trigger" id="owned-instance-picker-open">' . htmlspecialchars(t('owned_instance_picker_label')) . '</button>';
        $content .= renderOwnedInstancePickerModal($ownedSetPickerRows, $ownedSet['id'], 'owned_set_detail');
    }

    // Same general info table as the catalog set-detail page (Name/
    // Erschienen/Rücknahmejahr/Thema) — this is catalog-level set metadata,
    // not owned-instance data, so it's fetched via getSetById() same as the
    // catalog page and rendered through the same shared function.
    $catalogSet = getSetById($pdo, $ownedSet['set_id']);
    if ($catalogSet !== null) {
        $content .= renderSetGeneralInfoTable($pdo, $catalogSet);
    }

    // One combined "Inventar" table: Gesamt as a progress ring right at the
    // top, then Lagerort/Zustand (instance placement), Exklusive/Seltene/
    // Stickerbögen/Minifiguren (all "{actual} / {nominal}", see
    // getOwnedSetInventorySummary()'s doc comment), then OVP/Anleitung
    // status. Each summary row's value cell gets an id
    // (owned-set-summary-{key}) so a quantity-modal save can patch just
    // that text afterwards without a reload (see
    // renderOwnedSetQuantityModalScript()'s applySummaryUpdate()).
    $ownedInventorySummary = getOwnedSetInventorySummary($pdo, $ownedSet, getLocale());
    $renderActualNominalRow = function (string $labelKey, string $idKey, array $counts, bool $collapsible = false) use (&$content): void {
        $rowClass = $collapsible ? ' class="owned-set-collapsible-row"' : '';
        $content .= '<tr' . $rowClass . '><th>' . htmlspecialchars(t($labelKey)) . '</th><td id="owned-set-summary-' . $idKey . '">' . htmlspecialchars(t('owned_set_num_parts_actual', ['actual' => formatNumber($counts['actual']), 'nominal' => formatNumber($counts['nominal'])])) . '</td></tr>';
    };
    $renderBoxInfoRow = function (string $labelKey, bool $value, ?string $notesLabelKey, ?string $notes, bool $collapsible = false) use (&$content): void {
        $rowClass = $collapsible ? ' class="owned-set-collapsible-row"' : '';
        $content .= '<tr' . $rowClass . '><th>' . htmlspecialchars(t($labelKey)) . '</th><td>' . htmlspecialchars($value ? t('owned_set_wizard_yes') : t('owned_set_wizard_no'));
        if ($notes !== null && $notes !== '' && $notesLabelKey !== null) {
            $content .= '<br><span class="owned-set-box-info-note">' . htmlspecialchars(t($notesLabelKey)) . ': ' . htmlspecialchars($notes) . '</span>';
        }
        $content .= '</td></tr>';
    };

    $content .= '<div class="set-detail-table-wrap">';
    $content .= '<table class="set-detail-table">';
    $content .= '<tr class="owned-set-total-row"><td colspan="2">' . renderOwnedSetTotalRing($completeness['percent'], $completeness['actual'], $completeness['nominal']) . '</td></tr>';
    $content .= '<tr><th>' . htmlspecialchars(t('owned_set_field_location')) . '</th><td>';
    $locationLinks = [];
    foreach ($locationPath as $ancestor) {
        $locationLinks[] = '<a href="?page=location_detail&id=' . $ancestor['id'] . '">' . htmlspecialchars($ancestor['name']) . '</a>';
    }
    $content .= implode(' » ', $locationLinks);
    $content .= '</td></tr>';
    $content .= '<tr><th>' . htmlspecialchars(t('owned_set_field_condition')) . '</th><td>' . htmlspecialchars($ownedSet['condition_type'] === 'new' ? t('owned_set_condition_new') : t('owned_set_condition_used')) . '</td></tr>';
    if ($catalogSet !== null) {
        $bricklinkPriceDisplay = formatBricklinkPriceSummary(
            $catalogSet['bricklink_price_new'],
            $catalogSet['bricklink_price_used'],
            $catalogSet['bricklink_price_currency'],
            $catalogSet['bricklink_price_checked_at'],
            $ownedSet['condition_type']
        );
        $content .= '<tr><th>' . htmlspecialchars(t('owned_set_bricklink_price_label')) . '</th><td>';
        $content .= '<span id="owned-set-bricklink-price-text"' . ($bricklinkPriceDisplay['title'] !== null ? ' title="' . htmlspecialchars($bricklinkPriceDisplay['title']) . '"' : '') . '>' . htmlspecialchars($bricklinkPriceDisplay['text']) . '</span> ';
        $content .= '<button type="button" class="owned-set-bricklink-refresh-btn" id="owned-set-bricklink-refresh" data-set-id="' . (int) $catalogSet['id'] . '" title="' . htmlspecialchars(t('owned_set_bricklink_price_refresh_label')) . '" aria-label="' . htmlspecialchars(t('owned_set_bricklink_price_refresh_label')) . '">' . getActionIcon('refresh') . '</button>';
        $content .= '</td></tr>';
    }
    $renderActualNominalRow('set_detail_field_exclusive', 'exclusive', $ownedInventorySummary['exclusive']);
    $renderActualNominalRow('set_detail_field_rare', 'rare', $ownedInventorySummary['rare']);
    $renderActualNominalRow('owned_set_tab_minifigs', 'minifigs', $ownedInventorySummary['minifigs']);
    // Everything from here down starts collapsed (see .owned-set-collapsible-row
    // in style.css) — grouped contiguously at the end of the table so the
    // toggle affects one visually connected block, not scattered rows.
    // Also lets .owned-set-sidebar's own position:sticky (see the same CSS)
    // actually fit within the viewport without being cut off, on the sets
    // whose full table would otherwise run taller than the screen.
    $content .= '<tr class="owned-set-table-toggle-row"><td colspan="2">';
    $content .= '<button type="button" class="owned-set-table-toggle-btn" id="owned-set-table-toggle" aria-expanded="false">' . htmlspecialchars(t('owned_set_table_show_more')) . '</button>';
    $content .= '</td></tr>';
    $renderActualNominalRow('set_detail_field_stickers', 'stickers', $ownedInventorySummary['stickers'], true);
    $renderBoxInfoRow('owned_set_has_instructions', (bool) $ownedSet['has_instructions'], 'owned_set_instructions_notes_label', $ownedSet['instructions_notes'], true);
    $renderBoxInfoRow('owned_set_has_box', (bool) $ownedSet['has_box'], 'owned_set_box_notes_label', $ownedSet['box_notes'], true);
    $renderBoxInfoRow('owned_set_box_complete', (bool) $ownedSet['box_complete'], 'owned_set_box_complete_notes_label', $ownedSet['box_complete_notes'], true);
    $renderBoxInfoRow('owned_set_stickers_applied', (bool) $ownedSet['stickers_applied'], 'owned_set_stickers_notes_label', $ownedSet['stickers_notes'], true);
    if ($ownedSet['notes'] !== null && $ownedSet['notes'] !== '') {
        $content .= '<tr><th>' . htmlspecialchars(t('owned_set_notes_label')) . '</th><td>' . htmlspecialchars($ownedSet['notes']) . '</td></tr>';
    }
    $content .= '</table>';
    $content .= '</div>';

    $tableToggleLabelsJson = json_encode([
        'showMore' => t('owned_set_table_show_more'),
        'showLess' => t('owned_set_table_show_less'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $content .= <<<SCRIPT
<script>
(function(){
  var texts = {$tableToggleLabelsJson};
  var toggleBtn = document.getElementById("owned-set-table-toggle");
  var table = toggleBtn ? toggleBtn.closest("table") : null;
  var sidebar = document.getElementById("owned-set-sidebar");
  if (!toggleBtn || !table) {
    return;
  }
  toggleBtn.addEventListener("click", function() {
    var expanded = table.classList.toggle("owned-set-table-expanded");
    toggleBtn.setAttribute("aria-expanded", expanded ? "true" : "false");
    toggleBtn.textContent = expanded ? texts.showLess : texts.showMore;
    // Sticky positioning only makes sense while the (now short) collapsed
    // table actually fits the viewport — expanding it can make the sidebar
    // taller again, so it drops back into normal flow until collapsed again.
    if (sidebar) {
      sidebar.classList.toggle("owned-set-sidebar-sticky-disabled", expanded);
    }
  });
})();
</script>
SCRIPT;

    $content .= '<div class="set-detail-table-wrap owned-set-actionbar">';
    $content .= '<button type="button" class="owned-set-action-pill" id="owned-set-edit-open" title="' . htmlspecialchars(t('owned_set_edit_heading')) . '" aria-label="' . htmlspecialchars(t('owned_set_edit_heading')) . '">' . getActionIcon('edit') . '</button>';
    $content .= '<button type="button" class="owned-set-action-pill" id="owned-set-move-open" title="' . htmlspecialchars(t('owned_set_move_heading')) . '" aria-label="' . htmlspecialchars(t('owned_set_move_heading')) . '">' . getActionIcon('move') . '</button>';

    $content .= '<form method="post" id="remove-owned-set-form" class="owned-set-action-pill-form">';
    $content .= '<input type="hidden" name="action" value="remove_owned_set">';
    $content .= '<input type="hidden" name="owned_set_id" value="' . $ownedSet['id'] . '">';
    $content .= '<input type="hidden" name="set_id" value="' . $ownedSet['set_id'] . '">';
    $content .= '<button type="submit" class="owned-set-action-pill owned-set-action-pill-danger" title="' . htmlspecialchars(t('owned_set_remove_button')) . '" aria-label="' . htmlspecialchars(t('owned_set_remove_button')) . '">' . getActionIcon('delete') . '</button>';
    $content .= '</form>';

    $content .= '<button type="button" class="owned-set-action-pill" id="owned-set-bricklink-open" title="' . htmlspecialchars(t('owned_set_bricklink_xml_label')) . '" aria-label="' . htmlspecialchars(t('owned_set_bricklink_xml_label')) . '">' . getActionIcon('bricklink_xml') . '</button>';
    $content .= renderOwnedSetBricklinkModal($ownedSet);
    // Plain GET link — the browser's own download handling for the
    // Content-Disposition response is all that's needed (see
    // action=owned_set_pdf_report, src/routes/actions.php), no modal/JS.
    $content .= '<a class="owned-set-action-pill" href="?action=owned_set_pdf_report&owned_set_id=' . $ownedSet['id'] . '" target="_blank" rel="noopener" title="' . htmlspecialchars(t('owned_set_pdf_report_button')) . '" aria-label="' . htmlspecialchars(t('owned_set_pdf_report_button')) . '">' . getActionIcon('pdf') . '</a>';
    // Creates a pick list scoped to this instance's missing/damaged parts
    // (action=create_pick_list_from_owned_set, src/routes/actions.php) and
    // redirects straight into /pick/ to start picking — the description
    // prompt is the same lightweight window.prompt() pattern the /pick/ PWA
    // itself uses for its stocktake-flag note, not a full modal, since this
    // is the one piece of input this button actually needs.
    $content .= '<form method="post" id="owned-set-pick-list-form" class="owned-set-action-pill-form">';
    $content .= '<input type="hidden" name="action" value="create_pick_list_from_owned_set">';
    $content .= '<input type="hidden" name="owned_set_id" value="' . $ownedSet['id'] . '">';
    $content .= '<input type="hidden" name="name" id="owned-set-pick-list-name">';
    $content .= '<input type="hidden" name="description" id="owned-set-pick-list-description">';
    $content .= '<button type="button" class="owned-set-action-pill" id="owned-set-pick-list-open" title="' . htmlspecialchars(t('owned_set_pick_list_label')) . '" aria-label="' . htmlspecialchars(t('owned_set_pick_list_label')) . '">' . getActionIcon('pick_list') . '</button>';
    $content .= '</form>';
    $content .= '<button type="button" class="owned-set-action-pill" id="owned-set-sell-open" title="' . htmlspecialchars(t('owned_set_sell_heading')) . '" aria-label="' . htmlspecialchars(t('owned_set_sell_heading')) . '">' . getActionIcon('sell') . '</button>';
    // A still-sealed instance has no meaningful Inventur (see
    // owned_set_sealed_note elsewhere on this page) — same one-way
    // new->used rule setOwnedSetPartInventory()'s docs already describe, so
    // the button simply doesn't exist until the set is opened, rather than
    // existing disabled with an explanation.
    if ($ownedSet['condition_type'] !== 'new') {
        $content .= '<button type="button" class="owned-set-action-pill" id="owned-set-stocktake-open" title="' . htmlspecialchars(t('stocktake_start_button')) . '" aria-label="' . htmlspecialchars(t('stocktake_start_button')) . '">' . getActionIcon('stocktake') . '</button>';
    }
    $removeConfirmJson = json_encode(t('owned_set_remove_confirm'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $content .= <<<SCRIPT
<script>
(function(){
  var form = document.getElementById("remove-owned-set-form");
  if (!form) { return; }
  form.addEventListener("submit", function(e) {
    if (!window.confirm($removeConfirmJson)) {
      e.preventDefault();
    }
  });
})();
</script>
SCRIPT;

    // Two lightweight sequential window.prompt() calls rather than a full
    // modal (same deliberate choice as before, see the comment above this
    // button) — one for the pick list's own display name (defaults to this
    // set's own label, editable), one for the physical container it's being
    // collected into (blank; a container name must never silently default to
    // the set name, same reasoning as the catalog set_detail dialog).
    $pickListNamePromptJson = json_encode(t('owned_set_pick_list_name_prompt'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $pickListNameDefaultJson = json_encode($ownedSet['rebrickable_set_num'] . ' - ' . $ownedSet['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $pickListContainerPromptJson = json_encode(t('owned_set_pick_list_container_prompt'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $content .= <<<SCRIPT
<script>
(function(){
  var openBtn = document.getElementById("owned-set-pick-list-open");
  var form = document.getElementById("owned-set-pick-list-form");
  var nameField = document.getElementById("owned-set-pick-list-name");
  var descField = document.getElementById("owned-set-pick-list-description");
  if (!openBtn || !form || !nameField || !descField) { return; }
  openBtn.addEventListener("click", function() {
    var name = window.prompt($pickListNamePromptJson, $pickListNameDefaultJson);
    if (!name || !name.trim()) { return; }
    var description = window.prompt($pickListContainerPromptJson);
    if (!description || !description.trim()) { return; }
    nameField.value = name.trim();
    descField.value = description.trim();
    form.submit();
  });
})();
</script>
SCRIPT;

    $bricklinkRefreshFailedJson = json_encode(t('owned_set_bricklink_price_refresh_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $content .= <<<SCRIPT
<script>
(function(){
  var btn = document.getElementById("owned-set-bricklink-refresh");
  if (!btn) { return; }
  btn.addEventListener("click", function() {
    btn.disabled = true;
    btn.classList.add("owned-set-bricklink-refresh-spinning");
    var formData = new FormData();
    formData.set("action", "refresh_bricklink_price");
    formData.set("set_id", btn.dataset.setId);
    fetch("?", { method: "POST", body: formData, credentials: "same-origin" })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success) {
          // Simplest correct way to show the freshly formatted text
          // (currency symbol, locale number/date formatting) without
          // duplicating formatBricklinkPriceSummary()'s logic in JS — a
          // manual click is rare enough that a reload is no real cost.
          window.location.reload();
          return;
        }
        btn.disabled = false;
        btn.classList.remove("owned-set-bricklink-refresh-spinning");
        window.alert($bricklinkRefreshFailedJson + " " + res.message);
      })
      .catch(function() {
        btn.disabled = false;
        btn.classList.remove("owned-set-bricklink-refresh-spinning");
        window.alert($bricklinkRefreshFailedJson);
      });
  });
})();
</script>
SCRIPT;

    $content .= '</div>';

    $content .= renderOwnedSetEditModal($ownedSet);
    $content .= renderOwnedSetMoveModal($ownedSet);
    $content .= renderOwnedSetSellModal($ownedSet);

    $content .= '</div>'; // .owned-set-sidebar

    $content .= '<div class="owned-set-tabs-row">';

    if ($ownedSet['condition_type'] === 'new') {
        // Still sealed: nothing can be verified without opening it, which *is*
        // the transition to "used" (see openOwnedSet()'s doc comment) — so no
        // inventory tabs are offered until that's confirmed.
        $content .= '<h2>' . htmlspecialchars(t('owned_set_missing_parts_heading')) . '</h2>';
        $content .= '<section class="card">';
        $content .= '<p>' . htmlspecialchars(t('owned_set_sealed_note')) . '</p>';
        $content .= '<button type="button" id="owned-set-open-button">' . htmlspecialchars(t('owned_set_open_button')) . '</button>';
        $content .= '<span class="owned-set-message" id="owned-set-open-message"></span>';
        $content .= '</section>';

        $openConfirmJson = json_encode(t('owned_set_open_confirm'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $openErrorJson = json_encode(t('import_error_retry'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $content .= <<<SCRIPT
<script>
(function(){
  var btn = document.getElementById("owned-set-open-button");
  var msg = document.getElementById("owned-set-open-message");
  if (!btn || !msg) { return; }
  btn.addEventListener("click", function() {
    if (!window.confirm($openConfirmJson)) {
      return;
    }
    var formData = new FormData();
    formData.set("action", "open_owned_set");
    formData.set("owned_set_id", "{$ownedSet['id']}");
    fetch("?", { method: "POST", body: formData, credentials: "same-origin" })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success) {
          window.location.reload();
        } else {
          msg.textContent = res.message || $openErrorJson;
        }
      })
      .catch(function() {
        msg.textContent = $openErrorJson;
      });
  });
})();
</script>
SCRIPT;

        $content .= '<h2>' . htmlspecialchars(t('owned_set_photos_heading')) . '</h2>';
        $content .= renderOwnedSetPhotoGallery($pdo, $ownedSet);
    } else {
        // Tabs mirror the catalog set-detail page's own tab bar (.set-detail-tabs,
        // see page=set_detail) — same visual language for "here's the different
        // views of this set's contents", just scoped to one owned instance.
        // Content itself is never rendered server-side here — the container
        // starts as a loading spinner and the script below fetches the active
        // tab's HTML via the ajax=1 branch above (and again on every tab
        // click), so both the very first paint and every switch go through
        // the same AJAX path instead of a full page navigation.
        $ownedSetTabs = [
            'inventory' => t('owned_set_tab_inventory'),
            'spares' => t('owned_set_tab_spares'),
            'stickers' => t('owned_set_tab_stickers'),
            'minifigs' => t('owned_set_tab_minifigs'),
            'damaged_missing' => t('owned_set_tab_damaged_missing'),
            'instructions' => t('owned_set_tab_instructions'),
            'gallery' => t('owned_set_tab_gallery'),
        ];
        $activeOwnedTab = (string) ($_GET['tab'] ?? '');
        if (!isset($ownedSetTabs[$activeOwnedTab])) {
            $activeOwnedTab = array_key_first($ownedSetTabs);
        }

        $content .= '<nav class="set-detail-tabs" id="owned-set-tabs-nav">';
        foreach ($ownedSetTabs as $tabKey => $tabLabel) {
            $activeAttr = $tabKey === $activeOwnedTab ? ' class="active"' : '';
            $content .= '<a' . $activeAttr . ' data-tab="' . $tabKey . '" href="?page=owned_set_detail&id=' . $ownedSetId . '&tab=' . $tabKey . '">' . htmlspecialchars($tabLabel) . '</a>';
        }
        $content .= '</nav>';

        $loadingHtml = '<div class="owned-set-tab-loading"><span class="owned-set-tab-spinner"></span><span>' . htmlspecialchars(t('owned_set_tab_loading')) . '</span></div>';
        $content .= '<div id="owned-set-tab-content" data-owned-set-id="' . $ownedSetId . '" data-active-tab="' . htmlspecialchars($activeOwnedTab) . '">' . $loadingHtml . '</div>';

        $tabLoadingLabelsJson = json_encode([
            'loading' => t('owned_set_tab_loading'),
            'errorRetry' => t('import_error_retry'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $loadingHtmlJson = json_encode($loadingHtml, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $ownedSetTabLocaleJson = json_encode(getLocale(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $content .= <<<SCRIPT
<script>
(function(){
  var texts = $tabLoadingLabelsJson;
  var loadingHtml = $loadingHtmlJson;
  var appLocale = $ownedSetTabLocaleJson;
  var container = document.getElementById('owned-set-tab-content');
  var nav = document.getElementById('owned-set-tabs-nav');
  if (!container || !nav) {
    return;
  }
  var ownedSetId = container.dataset.ownedSetId;

  function runScripts(root) {
    var scripts = root.querySelectorAll('script');
    for (var i = 0; i < scripts.length; i++) {
      var oldScript = scripts[i];
      var freshScript = document.createElement('script');
      freshScript.textContent = oldScript.textContent;
      oldScript.parentNode.replaceChild(freshScript, oldScript);
    }
  }

  function formatNumber(n) {
    var sep = appLocale === 'de' ? '.' : ',';
    return String(n).replace(/\\B(?=(\\d{3})+(?!\\d))/g, sep);
  }

  function applyStats(stats) {
    if (!stats) {
      return;
    }
    Object.keys(stats).forEach(function(key) {
      var el = document.getElementById('status-stat-' + key);
      var strong = el ? el.querySelector('strong') : null;
      if (strong) {
        strong.textContent = formatNumber(stats[key]);
      }
    });
  }

  function loadTab(tabKey, pushState) {
    container.innerHTML = loadingHtml;
    var params = new URLSearchParams(window.location.search);
    params.set('page', 'owned_set_detail');
    params.set('id', ownedSetId);
    params.set('tab', tabKey);
    params.set('ajax', '1');
    fetch('?' + params.toString(), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (!res.success) {
          container.textContent = res.message || texts.errorRetry;
          return;
        }
        container.innerHTML = res.html;
        runScripts(container);
        applyStats(res.stats);
        var links = nav.querySelectorAll('a');
        for (var i = 0; i < links.length; i++) {
          links[i].classList.toggle('active', links[i].dataset.tab === tabKey);
        }
        container.dataset.activeTab = tabKey;
        if (pushState) {
          var urlParams = new URLSearchParams(window.location.search);
          urlParams.set('tab', tabKey);
          history.pushState({ tab: tabKey }, '', '?' + urlParams.toString());
        }
      })
      .catch(function() {
        container.textContent = texts.errorRetry;
      });
  }

  var navLinks = nav.querySelectorAll('a');
  for (var i = 0; i < navLinks.length; i++) {
    navLinks[i].addEventListener('click', function(e) {
      e.preventDefault();
      loadTab(this.dataset.tab, true);
    });
  }

  window.addEventListener('popstate', function() {
    var params = new URLSearchParams(window.location.search);
    loadTab(params.get('tab') || container.dataset.activeTab, false);
  });

  loadTab(container.dataset.activeTab, false);
})();
</script>
SCRIPT;
    }

    $content .= '</div>'; // .owned-set-tabs-row
    $content .= '</div>'; // .owned-set-layout

    if ($ownedSet['condition_type'] !== 'new') {
        $content .= renderStocktakeChoiceModal();
        $content .= renderStocktakeModal();
        $stocktakeOwnedSetIdJson = json_encode($ownedSet['id']);
        $stocktakeResumeLabelJson = json_encode(t('stocktake_resume_button'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $stocktakeListedHintJson = json_encode(t('stocktake_flagged_hint'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $content .= <<<SCRIPT
<script>
(function(){
  var ownedSetId = $stocktakeOwnedSetIdJson;
  var openBtn = document.getElementById('owned-set-stocktake-open');
  if (!openBtn) { return; }

  var currentFlagged = false;

  function applyStatus(res) {
    if (res && res.active) {
      openBtn.dataset.resumeId = res.stocktakeId;
      var label = $stocktakeResumeLabelJson + ' (' + res.confirmed + '/' + res.total + ')';
      openBtn.title = label;
      openBtn.setAttribute('aria-label', label);
    } else {
      delete openBtn.dataset.resumeId;
      currentFlagged = !!(res && res.flagged);
      var baseLabel = openBtn.dataset.baseLabel;
      openBtn.title = currentFlagged ? baseLabel + $stocktakeListedHintJson : baseLabel;
      openBtn.setAttribute('aria-label', openBtn.title);
    }
  }

  function fetchStatus() {
    return fetch('?action=stocktake_status&owned_set_id=' + ownedSetId, { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(applyStatus)
      .catch(function() {});
  }

  openBtn.dataset.baseLabel = openBtn.title;
  fetchStatus();

  function openCountingModal(resumeId) {
    window.openStocktakeModal('start_stocktake_for_owned_set', { owned_set_id: ownedSetId }, resumeId, function(changed) {
      if (changed) {
        window.location.reload();
      } else {
        fetchStatus();
      }
    });
  }

  openBtn.addEventListener('click', function() {
    var resumeId = openBtn.dataset.resumeId ? parseInt(openBtn.dataset.resumeId, 10) : null;
    if (resumeId) {
      openCountingModal(resumeId);
      return;
    }
    window.openStocktakeChoiceModal(ownedSetId, currentFlagged, function() {
      openCountingModal(null);
    }, function(newFlagged) {
      currentFlagged = newFlagged;
      applyStatus({ active: false, flagged: newFlagged });
    });
  });
})();
</script>
SCRIPT;
    }

    $ownedSetPageTitle = t('owned_set_detail_page_title', ['set_num' => $ownedSet['rebrickable_set_num'], 'name' => $ownedSet['name']]);
    renderApp($ownedSetPageTitle, $content, $user, computeAppStats($pdo), $ownedSetBreadcrumbs);
    exit;
}

if (isset($_GET['page']) && $_GET['page'] === 'bricks_search') {
    $searchQuery = trim((string) ($_GET['q'] ?? ''));
    $selectedCategories = array_values(array_filter(array_map('strval', (array) ($_GET['category'] ?? []))));
    $selectedColors = array_values(array_filter(array_map('strval', (array) ($_GET['color'] ?? []))));
    $hidePrinted = ($_GET['hide_printed'] ?? '') === '1';
    $pageNum = max(1, (int) ($_GET['p'] ?? 1));
    $perPage = PARTS_SEARCH_PAGE_SIZE;
    $isBrowsing = $searchQuery === '' && empty($selectedCategories) && empty($selectedColors);

    // Infinite-scroll continuation request: return just the next batch of
    // cards as JSON instead of a full page render.
    if (!$isBrowsing && ($_GET['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        $results = searchParts($pdo, $searchQuery, $selectedCategories, $selectedColors, $hidePrinted, $pageNum, $perPage);
        $results['items'] = applyPartTranslations($pdo, $results['items'], getLocale());
        $html = '';
        foreach ($results['items'] as $part) {
            $html .= renderPartCard($part);
        }
        $hasMore = ($pageNum * $perPage) < $results['total'];
        echo json_encode(['html' => $html, 'hasMore' => $hasMore], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $filterParams = ['page' => 'bricks_search'];
    if ($searchQuery !== '') {
        $filterParams['q'] = $searchQuery;
    }
    if (!empty($selectedCategories)) {
        $filterParams['category'] = $selectedCategories;
    }
    if (!empty($selectedColors)) {
        $filterParams['color'] = $selectedColors;
    }
    if ($hidePrinted) {
        $filterParams['hide_printed'] = '1';
    }

    $renderHiddenFields = function (array $params, array $exclude) {
        $html = '';
        foreach ($params as $key => $value) {
            if (in_array($key, $exclude, true)) {
                continue;
            }
            foreach ((array) $value as $singleValue) {
                $name = is_array($value) ? $key . '[]' : $key;
                $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars((string) $singleValue) . '">';
            }
        }
        return $html;
    };

    $bricksBreadcrumbs = [homeBreadcrumb(), ['label' => t('nav_bricks_search'), 'url' => $isBrowsing ? null : '?page=bricks_search']];
    if (!$isBrowsing) {
        if ($searchQuery !== '') {
            $bricksBreadcrumbs[] = ['label' => t('search_results_for', ['query' => $searchQuery]), 'url' => null];
        } elseif (count($selectedCategories) === 1) {
            $categoryName = getPartCategoryName($pdo, $selectedCategories[0]);
            if ($categoryName !== null) {
                $bricksBreadcrumbs[] = ['label' => $categoryName, 'url' => null];
            }
        }
    }

    $content = '<h1>' . htmlspecialchars(t('nav_bricks_search')) . '</h1>';

    $content .= '<form method="get" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '" class="parts-search-form">';
    $content .= '<input type="hidden" name="page" value="bricks_search">';
    $content .= $renderHiddenFields($filterParams, ['page', 'q']);
    $content .= '<input type="text" name="q" value="' . htmlspecialchars($searchQuery) . '" placeholder="' . htmlspecialchars(t('parts_search_placeholder')) . '">';
    $content .= '<button type="submit">' . htmlspecialchars(t('search_button')) . '</button>';
    $content .= '</form>';

    if ($isBrowsing) {
        $tileFilter = trim((string) ($_GET['tilefilter'] ?? 'all'));
        if ($tileFilter === 'popular') {
            $categories = getPopularPartCategories($pdo, PARTS_POPULAR_CATEGORY_LIMIT);
        } elseif ($tileFilter === 'minifigs') {
            $categories = array_values(array_filter(getPartCategories($pdo), function ($c) {
                return stripos($c['name'], 'minifig') !== false;
            }));
        } elseif ($tileFilter === 'technic') {
            $categories = array_values(array_filter(getPartCategories($pdo), function ($c) {
                return stripos($c['name'], 'technic') !== false;
            }));
        } else {
            $categories = getPartCategories($pdo);
        }

        $content .= '<p class="tile-filter-row">' . htmlspecialchars(t('tile_filter_label')) . ': ';
        $tileFilters = ['popular' => 'tile_filter_popular', 'minifigs' => 'tile_filter_minifigs', 'technic' => 'tile_filter_technic', 'old' => 'tile_filter_old', 'all' => 'tile_filter_all'];
        $filterLinks = [];
        foreach ($tileFilters as $filterKey => $filterLabelKey) {
            $activeClass = $filterKey === $tileFilter ? ' class="active"' : '';
            $filterLinks[] = '<a' . $activeClass . ' href="?page=bricks_search&tilefilter=' . $filterKey . '">' . htmlspecialchars(t($filterLabelKey)) . '</a>';
        }
        $content .= implode(' | ', $filterLinks);
        $content .= '</p>';

        if (empty($categories)) {
            $content .= '<section class="card"><p>' . htmlspecialchars(t('parts_categories_empty')) . '</p></section>';
        } else {
            $tileImages = getCategoryTileImages($pdo, array_map(function ($c) {
                return (string) $c['part_cat_id'];
            }, $categories));
            $content .= '<div class="category-tile-grid">';
            foreach ($categories as $cat) {
                $img = $tileImages[(string) $cat['part_cat_id']] ?? null;
                $content .= '<a class="category-tile" href="?page=bricks_search&category%5B%5D=' . urlencode((string) $cat['part_cat_id']) . '">';
                $content .= '<span class="category-tile-image">' . ($img !== null ? '<img src="' . htmlspecialchars($img) . '" alt="">' : getNavIcon('bricks')) . '</span>';
                $content .= '<span class="category-tile-label">' . htmlspecialchars($cat['name']) . '</span>';
                $content .= '</a>';
            }
            $content .= '</div>';
        }
    } else {
        $results = searchParts($pdo, $searchQuery, $selectedCategories, $selectedColors, $hidePrinted, 1, $perPage);
        $results['items'] = applyPartTranslations($pdo, $results['items'], getLocale());

        $sidebar = '<form method="get" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '" class="parts-filter-sidebar">';
        $sidebar .= '<input type="hidden" name="page" value="bricks_search">';
        $sidebar .= $renderHiddenFields($filterParams, ['page', 'category', 'color', 'hide_printed']);

        $sidebar .= '<div class="filter-group"><h3>' . htmlspecialchars(t('filter_category_title')) . '</h3><div class="filter-options">';
        foreach (getPartCategoriesWithCounts($pdo) as $cat) {
            $catIdStr = (string) $cat['part_cat_id'];
            $checked = in_array($catIdStr, $selectedCategories, true) ? ' checked' : '';
            $sidebar .= '<label class="filter-checkbox"><input type="checkbox" name="category[]" value="' . htmlspecialchars($catIdStr) . '"' . $checked . '> ' . htmlspecialchars($cat['name']) . ' <span class="filter-count">(' . (int) $cat['cnt'] . ')</span></label>';
        }
        $sidebar .= '</div></div>';

        $sidebar .= '<div class="filter-group"><h3>' . htmlspecialchars(t('filter_color_title')) . '</h3><div class="filter-options">';
        foreach (getColorFacet($pdo) as $color) {
            $colorIdStr = (string) $color['color_id'];
            $checked = in_array($colorIdStr, $selectedColors, true) ? ' checked' : '';
            $rgb = (string) ($color['rgb'] ?? '');
            $swatch = $rgb !== '' ? '#' . ltrim($rgb, '#') : '#cccccc';
            $sidebar .= '<label class="filter-checkbox"><input type="checkbox" name="color[]" value="' . htmlspecialchars($colorIdStr) . '"' . $checked . '> <span class="color-swatch" style="background-color:' . htmlspecialchars($swatch) . '"></span>' . htmlspecialchars($color['name']) . ' <span class="filter-count">(' . (int) $color['cnt'] . ')</span></label>';
        }
        $sidebar .= '</div></div>';

        $sidebar .= '<div class="filter-group"><label class="filter-checkbox"><input type="checkbox" name="hide_printed" value="1"' . ($hidePrinted ? ' checked' : '') . '> ' . htmlspecialchars(t('filter_hide_printed')) . '</label></div>';

        $sidebar .= '<button type="submit" class="filter-apply-button">' . htmlspecialchars(t('filter_apply_button')) . '</button>';
        $sidebar .= '</form>';

        $main = '<p><a href="?page=bricks_search">&larr; ' . htmlspecialchars(t('back_to_categories')) . '</a></p>';
        $main .= '<span class="results-summary">' . htmlspecialchars(t('parts_found_count', ['count' => formatNumber($results['total'])])) . '</span>';

        if (empty($results['items'])) {
            $main .= '<section class="card"><p>' . htmlspecialchars(t('parts_categories_empty')) . '</p></section>';
        } else {
            $hasMore = $perPage < $results['total'];
            $main .= '<div class="parts-grid" id="parts-grid">';
            foreach ($results['items'] as $part) {
                $main .= renderPartCard($part);
            }
            $main .= '</div>';
            $main .= '<div id="parts-load-sentinel" class="parts-load-sentinel" data-has-more="' . ($hasMore ? '1' : '0') . '" data-next-page="2">';
            $main .= '<span class="parts-load-status" data-loading-text="' . htmlspecialchars(t('parts_loading_more')) . '" data-end-text="' . htmlspecialchars(t('parts_no_more')) . '">' . ($hasMore ? '' : htmlspecialchars(t('parts_no_more'))) . '</span>';
            $main .= '</div>';
            $main .= <<<SCRIPT
<script>
(function(){
  var sentinel = document.getElementById('parts-load-sentinel');
  var grid = document.getElementById('parts-grid');
  var status = sentinel ? sentinel.querySelector('.parts-load-status') : null;
  if (!sentinel || !grid || !status) {
    return;
  }
  var loading = false;

  function loadMore() {
    if (loading || sentinel.dataset.hasMore !== '1') {
      return;
    }
    loading = true;
    status.textContent = status.dataset.loadingText;

    var params = new URLSearchParams(window.location.search);
    params.set('ajax', '1');
    params.set('p', sentinel.dataset.nextPage);

    fetch('?' + params.toString(), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        grid.insertAdjacentHTML('beforeend', data.html);
        sentinel.dataset.hasMore = data.hasMore ? '1' : '0';
        sentinel.dataset.nextPage = String(parseInt(sentinel.dataset.nextPage, 10) + 1);
        status.textContent = data.hasMore ? '' : status.dataset.endText;
        loading = false;
        if (data.hasMore) {
          checkAndLoad();
        }
      })
      .catch(function() {
        loading = false;
      });
  }

  function checkAndLoad() {
    var rect = sentinel.getBoundingClientRect();
    if (rect.top < window.innerHeight + 400) {
      loadMore();
    }
  }

  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (entry.isIntersecting) {
          loadMore();
        }
      });
    }, { rootMargin: '400px' });
    observer.observe(sentinel);
  } else {
    window.addEventListener('scroll', checkAndLoad);
    checkAndLoad();
  }
})();
</script>
SCRIPT;
        }

        $main .= renderPartDetailModal();

        $content .= '<div class="parts-search-layout">' . $sidebar . '<div class="parts-search-main">' . $main . '</div></div>';
    }

    renderApp(t('nav_bricks_search'), $content, $user, computeAppStats($pdo), $bricksBreadcrumbs);
    exit;
}

if (isset($_GET['page']) && $_GET['page'] === 'my_sets') {
    header('Location: ?page=my_sets_all');
    exit;
}

if (isset($_GET['page']) && $_GET['page'] === 'my_sets_all') {
    $ownedSetGroups = groupOwnedSetsByModel($pdo, getAllOwnedSets($pdo));

    $content = '<h1>' . htmlspecialchars(t('nav_my_sets_all')) . '</h1>';
    if (empty($ownedSetGroups)) {
        $content .= '<section class="card"><p>' . htmlspecialchars(t('my_sets_empty')) . '</p></section>';
    } else {
        $content .= '<div class="sets-grid">';
        foreach ($ownedSetGroups as $group) {
            $content .= renderOwnedSetGroupCard($group);
        }
        $content .= '</div>';
    }

    renderApp(t('nav_my_sets_all'), $content, $user, computeAppStats($pdo), [homeBreadcrumb(), ['label' => t('nav_my_sets_all'), 'url' => null]]);
    exit;
}

if (isset($_GET['page']) && $_GET['page'] === 'my_sets_themes') {
    $themeParam = isset($_GET['theme']) && $_GET['theme'] !== '' ? (int) $_GET['theme'] : null;

    $content = '<h1>' . htmlspecialchars(t('nav_my_sets_themes')) . '</h1>';
    $myThemesBreadcrumbs = [homeBreadcrumb(), ['label' => t('nav_my_sets_themes'), 'url' => $themeParam !== null ? '?page=my_sets_themes' : null]];

    $tree = getOwnedSetThemeTree($pdo);

    if ($themeParam !== null) {
        $ancestors = getThemeAncestors($tree, $themeParam);
        foreach ($ancestors as $i => $ancestor) {
            $isLast = $i === count($ancestors) - 1;
            $myThemesBreadcrumbs[] = [
                'label' => $ancestor['name'],
                'url' => $isLast ? null : '?page=my_sets_themes&theme=' . $ancestor['theme_id'],
            ];
        }
    }

    $children = getSetThemeChildren($tree, $themeParam);
    if (!empty($children)) {
        $tileImageGroups = [];
        foreach ($children as $child) {
            $tileImageGroups[$child['theme_id']] = getThemeAndDescendantIds($tree, $child['theme_id']);
        }
        $tileImages = getThemeTileImages($pdo, $tileImageGroups);
        $content .= '<div class="category-tile-grid sets-theme-grid">';
        foreach ($children as $child) {
            $img = $tileImages[(string) $child['theme_id']] ?? null;
            $content .= '<a class="category-tile sets-theme-tile" href="?page=my_sets_themes&theme=' . $child['theme_id'] . '">';
            $content .= '<span class="category-tile-image sets-theme-tile-image">' . ($img !== null ? '<img src="' . htmlspecialchars($img) . '" alt="">' : getNavIcon('sets')) . '</span>';
            $content .= '<span class="category-tile-label sets-theme-tile-label">' . htmlspecialchars($child['name']) . ' (' . $child['recursive_count'] . ')</span>';
            $content .= '</a>';
        }
        $content .= '</div>';
    } elseif ($themeParam === null) {
        $content .= '<section class="card"><p>' . htmlspecialchars(t('my_sets_empty')) . '</p></section>';
    }

    if ($themeParam !== null) {
        $ownedSetGroups = groupOwnedSetsByModel($pdo, getOwnedSetsForThemes($pdo, [$themeParam]));
        if (!empty($ownedSetGroups)) {
            $content .= '<div class="sets-grid">';
            foreach ($ownedSetGroups as $group) {
                $content .= renderOwnedSetGroupCard($group);
            }
            $content .= '</div>';
        }
    }

    renderApp(t('nav_my_sets_themes'), $content, $user, computeAppStats($pdo), $myThemesBreadcrumbs);
    exit;
}

if (isset($_GET['page']) && $_GET['page'] === 'my_minifigs') {
    header('Location: ?page=my_minifigs_all');
    exit;
}

if (isset($_GET['page']) && $_GET['page'] === 'my_minifigs_all') {
    $minifigGroups = groupLooseMinifigsByModel($pdo, getAllLooseMinifigs($pdo), getLocale());

    $content = '<h1>' . htmlspecialchars(t('nav_my_minifigs_all')) . '</h1>';
    if (empty($minifigGroups)) {
        $content .= '<section class="card"><p>' . htmlspecialchars(t('my_minifigs_empty')) . '</p></section>';
    } else {
        // renderOwnedMinifigGroupCard() links straight to
        // owned_minifig_detail — no modal-click delegation involved here
        // (unlike a plain .minifig-card), so no renderPartDetailModal()/
        // renderMinifigDetailModal() markup is needed on this page.
        $content .= '<div class="minifigs-grid">';
        foreach ($minifigGroups as $group) {
            $content .= renderOwnedMinifigGroupCard($group);
        }
        $content .= '</div>';
    }

    renderApp(t('nav_my_minifigs_all'), $content, $user, computeAppStats($pdo), [homeBreadcrumb(), ['label' => t('nav_my_minifigs_all'), 'url' => null]]);
    exit;
}

if (isset($_GET['page']) && $_GET['page'] === 'my_minifigs_themes') {
    $themeParam = isset($_GET['theme']) && $_GET['theme'] !== '' ? (int) $_GET['theme'] : null;

    $content = '<h1>' . htmlspecialchars(t('nav_my_minifigs_themes')) . '</h1>';
    $myMinifigThemesBreadcrumbs = [homeBreadcrumb(), ['label' => t('nav_my_minifigs_themes'), 'url' => $themeParam !== null ? '?page=my_minifigs_themes' : null]];

    $tree = getOwnedMinifigThemeTree($pdo);

    if ($themeParam !== null) {
        $ancestors = getThemeAncestors($tree, $themeParam);
        foreach ($ancestors as $i => $ancestor) {
            $isLast = $i === count($ancestors) - 1;
            $myMinifigThemesBreadcrumbs[] = [
                'label' => $ancestor['name'],
                'url' => $isLast ? null : '?page=my_minifigs_themes&theme=' . $ancestor['theme_id'],
            ];
        }
    }

    $children = getSetThemeChildren($tree, $themeParam);
    if (!empty($children)) {
        $tileImageGroups = [];
        foreach ($children as $child) {
            $tileImageGroups[$child['theme_id']] = getThemeAndDescendantIds($tree, $child['theme_id']);
        }
        $tileImages = getMinifigThemeTileImages($pdo, $tileImageGroups);
        $content .= '<div class="category-tile-grid sets-theme-grid">';
        foreach ($children as $child) {
            $img = $tileImages[(string) $child['theme_id']] ?? null;
            $content .= '<a class="category-tile sets-theme-tile" href="?page=my_minifigs_themes&theme=' . $child['theme_id'] . '">';
            $content .= '<span class="category-tile-image sets-theme-tile-image">' . ($img !== null ? '<img src="' . htmlspecialchars($img) . '" alt="">' : getNavIcon('minifigs')) . '</span>';
            $content .= '<span class="category-tile-label sets-theme-tile-label">' . htmlspecialchars($child['name']) . ' (' . $child['recursive_count'] . ')</span>';
            $content .= '</a>';
        }
        $content .= '</div>';
    } elseif ($themeParam === null) {
        $content .= '<section class="card"><p>' . htmlspecialchars(t('my_minifigs_empty')) . '</p></section>';
    }

    if ($themeParam !== null) {
        // Exact theme_id match only, same as getOwnedSetsForThemes()'s own
        // call in my_sets_themes above — a set (and by extension the
        // minifigs derived through it) is tagged with one specific theme_id,
        // not every ancestor simultaneously, so drilling into an empty
        // parent still correctly shows nothing here while its subtheme
        // tiles (which DO use the recursive id group, for the tile grid
        // above) lead somewhere that does.
        $minifigGroups = groupLooseMinifigsByModel($pdo, getLooseMinifigsForThemes($pdo, [$themeParam]), getLocale());
        if (!empty($minifigGroups)) {
            $content .= '<div class="minifigs-grid">';
            foreach ($minifigGroups as $group) {
                $content .= renderOwnedMinifigGroupCard($group);
            }
            $content .= '</div>';
        }
    }

    renderApp(t('nav_my_minifigs_themes'), $content, $user, computeAppStats($pdo), $myMinifigThemesBreadcrumbs);
    exit;
}

// getTopValuedOwnedParts() (src/bricklink_prices.php) does the ranking, this
// just renders it as a table. Coverage line is independent of whether the
// list itself is empty — even "nothing priced yet" is worth showing as
// "0 of N", so it's computed before that branch.
if (isset($_GET['page']) && $_GET['page'] === 'my_bricks_top100') {
    $topParts = getTopValuedOwnedParts($pdo, 100);
    $coverage = getBricklinkPartPriceCoverage($pdo);

    $content = '<h1>' . htmlspecialchars(t('nav_my_bricks_top100')) . '</h1>';
    if ($coverage['total'] > 0) {
        $content .= '<p class="hint">' . htmlspecialchars(t('my_bricks_top100_coverage', [
            'priced' => (string) $coverage['priced'],
            'total' => (string) $coverage['total'],
            'missing' => (string) ($coverage['total'] - $coverage['priced']),
        ])) . '</p>';
    }
    if (empty($topParts)) {
        $content .= '<section class="card"><p>' . htmlspecialchars(t('my_bricks_top100_empty')) . '</p></section>';
    } else {
        // Same color-correct-image fallback chain as getLocationContentRecursive()/
        // getSetPartsList()'s callers: the cached LDraw/BrickLink-CDN render if one
        // exists, otherwise a generic (color-agnostic) catalog thumbnail — never a
        // separate color swatch box, the image itself is the color indicator.
        $genericThumbnails = getPartThumbnails($pdo, array_values(array_unique(array_column($topParts, 'part_id'))));

        $missingCount = 0;
        $rowsHtml = '';
        foreach ($topParts as $i => $row) {
            $thumbnail = $row['ldraw_thumbnail'] ?? $genericThumbnails[$row['part_id']] ?? null;
            $fetchColorId = $row['ldraw_thumbnail'] === null ? $row['rebrickable_color_id'] : null;
            if ($fetchColorId !== null) {
                $missingCount++;
            }
            $dataAttrs = $fetchColorId !== null ? ' data-part-id="' . $row['part_id'] . '" data-color-id="' . $fetchColorId . '"' : '';
            $currencySymbol = bricklinkCurrencySymbol($row['currency']);
            $rowsHtml .= '<tr>';
            $rowsHtml .= '<td>' . ($i + 1) . '</td>';
            $rowsHtml .= '<td class="my-minifigs-top100-thumb-cell"' . $dataAttrs . '><span class="part-card-image">' . ($thumbnail !== null ? '<img src="' . htmlspecialchars($thumbnail) . '" alt="">' : getNavIcon('bricks')) . '</span></td>';
            $rowsHtml .= '<td><span class="part-card my-bricks-top100-part-link" data-part-id="' . $row['part_id'] . '">'
                . htmlspecialchars($row['part_name']) . ' <span class="hint">' . htmlspecialchars($row['part_num']) . ' · ' . htmlspecialchars($row['color_name'] ?? '') . '</span></span></td>';
            $rowsHtml .= '<td>' . htmlspecialchars($row['condition_type'] === 'new' ? t('condition_new') : t('condition_used')) . '</td>';
            $rowsHtml .= '<td>' . formatNumber($row['quantity']) . '</td>';
            $rowsHtml .= '<td>' . formatNumber($row['unit_price'], 2) . ' ' . htmlspecialchars($currencySymbol) . '</td>';
            $rowsHtml .= '<td>' . formatNumber($row['total_value'], 2) . ' ' . htmlspecialchars($currencySymbol) . '</td>';
            $rowsHtml .= '</tr>';
        }
        $totalQuantity = array_sum(array_column($topParts, 'quantity'));
        $totalValue = array_sum(array_column($topParts, 'total_value'));
        $grandTotalCurrencySymbol = bricklinkCurrencySymbol($topParts[0]['currency']);

        $content .= renderPartDetailModal();
        $content .= renderFetchMissingImagesButton('my-bricks-top100-table', $missingCount);
        $content .= '<div class="set-detail-table-wrap" id="my-bricks-top100-table">';
        $content .= '<table class="set-detail-table my-minifigs-top100-table">';
        $content .= '<thead><tr>';
        $content .= '<th>#</th><th></th>';
        $content .= '<th>' . htmlspecialchars(t('pdf_report_col_name')) . '</th>';
        $content .= '<th>' . htmlspecialchars(t('owned_set_field_condition')) . '</th>';
        $content .= '<th>' . htmlspecialchars(t('pdf_report_col_quantity')) . '</th>';
        $content .= '<th>' . htmlspecialchars(t('my_bricks_top100_price_column')) . '</th>';
        $content .= '<th>' . htmlspecialchars(t('my_bricks_top100_total_column')) . '</th>';
        $content .= '</tr></thead><tbody>';
        $content .= $rowsHtml;
        $content .= '</tbody>';
        $content .= '<tfoot><tr class="my-minifigs-top100-grand-total">';
        $content .= '<td colspan="4">' . htmlspecialchars(t('my_bricks_top100_grand_total_label')) . '</td>';
        $content .= '<td>' . formatNumber($totalQuantity) . '</td>';
        $content .= '<td></td>';
        $content .= '<td>' . formatNumber($totalValue, 2) . ' ' . htmlspecialchars($grandTotalCurrencySymbol) . '</td>';
        $content .= '</tr></tfoot>';
        $content .= '</table></div>';
        $content .= renderFetchMissingImagesScript();
    }

    renderApp(t('nav_my_bricks_top100'), $content, $user, computeAppStats($pdo), [homeBreadcrumb(), ['label' => t('nav_my_bricks_top100'), 'url' => null]]);
    exit;
}

// Static nav entry above the theme dropdown (getNavMenu(), index.php) —
// getTopValuedOwnedMinifigs() (src/owned_minifigs.php) does the ranking,
// this just renders it as a table.
if (isset($_GET['page']) && $_GET['page'] === 'my_minifigs_top100') {
    $topMinifigs = getTopValuedOwnedMinifigs($pdo, 100);

    $content = '<h1>' . htmlspecialchars(t('nav_my_minifigs_top100')) . '</h1>';
    if (empty($topMinifigs)) {
        $content .= '<section class="card"><p>' . htmlspecialchars(t('my_minifigs_top100_empty')) . '</p></section>';
    } else {
        $content .= '<div class="set-detail-table-wrap">';
        $content .= '<table class="set-detail-table my-minifigs-top100-table">';
        $content .= '<thead><tr>';
        $content .= '<th>#</th><th></th>';
        $content .= '<th>' . htmlspecialchars(t('pdf_report_col_name')) . '</th>';
        $content .= '<th>' . htmlspecialchars(t('owned_set_field_condition')) . '</th>';
        $content .= '<th>' . htmlspecialchars(t('pdf_report_col_quantity')) . '</th>';
        $content .= '<th>' . htmlspecialchars(t('my_minifigs_top100_price_column')) . '</th>';
        $content .= '<th>' . htmlspecialchars(t('my_minifigs_top100_total_column')) . '</th>';
        $content .= '</tr></thead><tbody>';
        foreach ($topMinifigs as $i => $row) {
            $name = $row['name'] ?? $row['fig_num'];
            $currencySymbol = bricklinkCurrencySymbol($row['currency']);
            $content .= '<tr>';
            $content .= '<td>' . ($i + 1) . '</td>';
            $content .= '<td class="my-minifigs-top100-thumb-cell">' . ($row['thumbnail'] !== null ? '<img src="' . htmlspecialchars($row['thumbnail']) . '" alt="">' : getNavIcon('minifigs')) . '</td>';
            $content .= '<td><a href="?page=owned_minifig_detail&id=' . $row['representative_instance_id'] . '">' . htmlspecialchars($name) . '</a> <span class="hint">' . htmlspecialchars($row['fig_num']) . '</span></td>';
            $content .= '<td>' . htmlspecialchars($row['condition_type'] === 'new' ? t('condition_new') : t('condition_used')) . '</td>';
            $content .= '<td>' . formatNumber($row['quantity']) . '</td>';
            $content .= '<td>' . formatNumber($row['unit_price'], 2) . ' ' . htmlspecialchars($currencySymbol) . '</td>';
            $content .= '<td>' . formatNumber($row['total_value'], 2) . ' ' . htmlspecialchars($currencySymbol) . '</td>';
            $content .= '</tr>';
        }
        $totalQuantity = array_sum(array_column($topMinifigs, 'quantity'));
        $totalValue = array_sum(array_column($topMinifigs, 'total_value'));
        $grandTotalCurrencySymbol = bricklinkCurrencySymbol($topMinifigs[0]['currency']);
        $content .= '</tbody>';
        $content .= '<tfoot><tr class="my-minifigs-top100-grand-total">';
        $content .= '<td colspan="4">' . htmlspecialchars(t('my_minifigs_top100_grand_total_label')) . '</td>';
        $content .= '<td>' . formatNumber($totalQuantity) . '</td>';
        $content .= '<td></td>';
        $content .= '<td>' . formatNumber($totalValue, 2) . ' ' . htmlspecialchars($grandTotalCurrencySymbol) . '</td>';
        $content .= '</tr></tfoot>';
        $content .= '</table></div>';
    }

    renderApp(t('nav_my_minifigs_top100'), $content, $user, computeAppStats($pdo), [homeBreadcrumb(), ['label' => t('nav_my_minifigs_top100'), 'url' => null]]);
    exit;
}

// Mirrors page=owned_set_detail (see that block above for the full
// reasoning behind the tab-AJAX pattern) — three tabs instead of seven
// (Bauteile/Beschädigt-Fehlend/Fotos only, no Ersatzteile/Sticker/
// Minifiguren/Bauanleitung — none apply to a single loose minifig, see
// src/owned_minifigs.php's own doc comment) and no sealed-box special case
// (a loose minifig's parts status is already captured at add time, there's
// no "still sealed" state to gate tabs behind).
if (isset($_GET['page']) && $_GET['page'] === 'owned_minifig_detail') {
    $ownedMinifigInstanceId = (int) ($_GET['id'] ?? 0);
    $ownedMinifigInstance = getOwnedMinifigInstanceById($pdo, $ownedMinifigInstanceId);

    if ($ownedMinifigInstance === null) {
        $content = '<h1>' . htmlspecialchars(t('owned_minifig_not_found_title')) . '</h1>';
        $content .= '<section class="card alert"><p>' . htmlspecialchars(t('owned_minifig_not_found')) . '</p></section>';
        renderApp(t('owned_minifig_not_found_title'), $content, $user, computeAppStats($pdo), [homeBreadcrumb(), ['label' => t('nav_my_minifigs_all'), 'url' => '?page=my_minifigs_all']]);
        exit;
    }

    $renderOwnedMinifigTabContent = function (string $tabKey) use ($pdo, $ownedMinifigInstance): string {
        if ($tabKey === 'parts') {
            $parts = getMinifigStorageItemPartsWithStatus($pdo, $ownedMinifigInstance['id'], $ownedMinifigInstance['fig_num'], getLocale());
            return renderOwnedMinifigInventoryGrid($ownedMinifigInstance, $parts);
        }
        if ($tabKey === 'damaged_missing') {
            return renderOwnedMinifigDamagedMissingSection($pdo, $ownedMinifigInstance);
        }
        return renderOwnedMinifigPhotoGallery($pdo, $ownedMinifigInstance);
    };
    $ownedMinifigTabKeys = ['parts', 'damaged_missing', 'gallery'];

    if (($_GET['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        $ajaxMinifigTabKey = (string) ($_GET['tab'] ?? '');
        if (!in_array($ajaxMinifigTabKey, $ownedMinifigTabKeys, true)) {
            $ajaxMinifigTabKey = $ownedMinifigTabKeys[0];
        }
        echo json_encode([
            'success' => true,
            'html' => $renderOwnedMinifigTabContent($ajaxMinifigTabKey),
            'stats' => computeAppStats($pdo),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ownedMinifigName = $ownedMinifigInstance['name'] ?? $ownedMinifigInstance['fig_num'];
    $ownedMinifigBreadcrumbs = [
        homeBreadcrumb(),
        ['label' => t('nav_my_minifigs_all'), 'url' => '?page=my_minifigs_all'],
    ];
    $ownedMinifigInstanceNumber = getOwnedMinifigInstanceNumber($pdo, $ownedMinifigInstance['minifig_id'], $ownedMinifigInstance['id']);
    $ownedMinifigBreadcrumbs[] = ['label' => $ownedMinifigName, 'url' => null];
    $ownedMinifigBreadcrumbs[] = ['label' => t('owned_set_instance_label', ['n' => (string) $ownedMinifigInstanceNumber]), 'url' => null];

    $adjacentOwnedMinifigs = getAdjacentOwnedMinifigInstances($pdo, $ownedMinifigInstance);

    $content = '<div class="owned-set-layout">';

    $content .= '<div class="owned-set-image-row">';
    $content .= '<span class="set-detail-image">' . ($ownedMinifigInstance['thumbnail'] !== null ? '<img src="' . htmlspecialchars($ownedMinifigInstance['thumbnail']) . '" alt="">' : getNavIcon('minifigs')) . '</span>';
    $content .= '</div>';

    $content .= '<div class="owned-set-sidebar" id="owned-set-sidebar">';
    // Heading + prev/next merged into one row, same as owned_set_detail above.
    $content .= '<div class="set-detail-title-row">';
    $content .= $adjacentOwnedMinifigs['prev'] !== null
        ? '<a class="set-detail-setnav-link" href="?page=owned_minifig_detail&id=' . $adjacentOwnedMinifigs['prev'] . '">&lsaquo; ' . htmlspecialchars(t('owned_set_instance_label', ['n' => (string) ($ownedMinifigInstanceNumber - 1)])) . '</a>'
        : '<span class="set-detail-setnav-link"></span>';
    $content .= '<h1 class="set-detail-title">' . htmlspecialchars($ownedMinifigName) . '</h1>';
    $content .= $adjacentOwnedMinifigs['next'] !== null
        ? '<a class="set-detail-setnav-link" href="?page=owned_minifig_detail&id=' . $adjacentOwnedMinifigs['next'] . '">' . htmlspecialchars(t('owned_set_instance_label', ['n' => (string) ($ownedMinifigInstanceNumber + 1)])) . ' &rsaquo;</a>'
        : '<span class="set-detail-setnav-link"></span>';
    $content .= '</div>';
    $content .= '<p class="hint">' . htmlspecialchars($ownedMinifigInstance['fig_num']) . '</p>';

    if ($ownedMinifigDetailMessage !== '') {
        $content .= '<p class="owned-set-message">' . htmlspecialchars($ownedMinifigDetailMessage) . '</p>';
    }

    // Instance picker — jumps straight to any other owned copy of this same
    // model (getOwnedMinifigInstancesForModel()), the detail-page counterpart
    // to "Meine Minifiguren"'s grouped card (renderOwnedMinifigGroupCard()):
    // that card links to just one representative copy, this is how the rest
    // are reached. A modal with a scrollable row list (renderOwnedInstancePickerModal(),
    // src/minifigs.php, shared with owned_set_detail below) rather than a
    // plain <select> — a location path in an <option> made the dropdown
    // uncomfortably wide, and a modal row can show each copy's own ampel
    // status dot besides. Plain navigation on confirm (not a live DOM swap,
    // unlike the compact modal's own instance picker) since the whole page -
    // tabs, sidebar table, action bar - differs per instance.
    $ownedMinifigAllInstances = getOwnedMinifigInstancesForModel($pdo, $ownedMinifigInstance['minifig_id'], $ownedMinifigInstance['fig_num'], getLocale());
    if (count($ownedMinifigAllInstances) > 1) {
        $ownedMinifigPickerRows = [];
        foreach ($ownedMinifigAllInstances as $i => $inst) {
            $optLocation = implode(' -> ', array_column(getStorageLocationAncestors($inst['location_id']), 'name'));
            $optCond = $inst['condition_type'] === 'new' ? t('condition_new') : t('condition_used');
            $ownedMinifigPickerRows[] = [
                'id' => $inst['id'],
                'label' => t('owned_set_instance_label', ['n' => (string) ($i + 1)]),
                'meta' => implode(' · ', array_filter([$optLocation, $optCond], fn (string $v): bool => $v !== '')),
                'status' => $inst['status'],
            ];
        }
        $content .= '<button type="button" class="owned-instance-picker-trigger" id="owned-instance-picker-open">' . htmlspecialchars(t('owned_instance_picker_label')) . '</button>';
        $content .= renderOwnedInstancePickerModal($ownedMinifigPickerRows, $ownedMinifigInstance['id'], 'owned_minifig_detail');
    }

    // Links row — same BrickLink/Rebrickable pair the compact minifig modal
    // shows (getOrFetchBricklinkMinifigId()-backed search/catalog links, see
    // action=minifig_detail, src/routes/actions.php); resolved fresh here
    // rather than reusing that JSON endpoint, since this is a plain page
    // render, not a fetch.
    $ownedMinifigBricklinkId = getOrFetchBricklinkMinifigId($pdo, $ownedMinifigInstance['minifig_id'], $ownedMinifigInstance['fig_num']);
    $ownedMinifigBricklinkUrl = $ownedMinifigBricklinkId !== null
        ? 'https://www.bricklink.com/v2/catalog/catalogitem.page?M=' . urlencode($ownedMinifigBricklinkId)
        : 'https://www.bricklink.com/v2/search.page?q=' . urlencode($ownedMinifigInstance['fig_num']);
    $ownedMinifigRebrickableUrl = 'https://rebrickable.com/minifigs/' . urlencode($ownedMinifigInstance['fig_num']) . '/';
    $content .= '<p class="part-modal-links">';
    $content .= '<a href="' . htmlspecialchars($ownedMinifigBricklinkUrl) . '" target="_blank" rel="noopener">' . htmlspecialchars(t('bricklink_link')) . '</a> · ';
    $content .= '<a href="' . htmlspecialchars($ownedMinifigRebrickableUrl) . '" target="_blank" rel="noopener">' . htmlspecialchars(t('rebrickable_link')) . '</a>';
    $content .= '</p>';

    $minifigParts = getMinifigStorageItemPartsWithStatus($pdo, $ownedMinifigInstance['id'], $ownedMinifigInstance['fig_num'], getLocale());
    $minifigNominalTotal = 0;
    $minifigActualTotal = 0;
    foreach ($minifigParts as $minifigPart) {
        $minifigNominalTotal += $minifigPart['nominal_quantity'];
        $minifigActualTotal += $minifigPart['actual_quantity'];
    }
    $minifigCompletenessPercent = $minifigNominalTotal > 0 ? round(min(100.0, ($minifigActualTotal / $minifigNominalTotal) * 100), 1) : 100.0;

    $content .= '<div class="set-detail-table-wrap">';
    $content .= '<table class="set-detail-table">';
    $content .= '<tr class="owned-set-total-row"><td colspan="2">' . renderOwnedSetTotalRing($minifigCompletenessPercent, $minifigActualTotal, $minifigNominalTotal) . '</td></tr>';
    $content .= '<tr><th>' . htmlspecialchars(t('owned_set_field_location')) . '</th><td>';
    $ownedMinifigLocationLinks = [];
    foreach (getStorageLocationAncestors($ownedMinifigInstance['location_id']) as $ownedMinifigLocationAncestor) {
        $ownedMinifigLocationLinks[] = '<a href="?page=location_detail&id=' . $ownedMinifigLocationAncestor['id'] . '">' . htmlspecialchars($ownedMinifigLocationAncestor['name']) . '</a>';
    }
    $content .= implode(' » ', $ownedMinifigLocationLinks);
    $content .= '</td></tr>';
    $content .= '<tr><th>' . htmlspecialchars(t('owned_set_field_condition')) . '</th><td>' . htmlspecialchars($ownedMinifigInstance['condition_type'] === 'new' ? t('owned_set_condition_new') : t('owned_set_condition_used')) . '</td></tr>';

    $ownedMinifigPriceDisplay = formatBricklinkPriceSummary(
        $ownedMinifigInstance['bricklink_price_new'],
        $ownedMinifigInstance['bricklink_price_used'],
        $ownedMinifigInstance['bricklink_price_currency'],
        $ownedMinifigInstance['bricklink_price_checked_at'],
        $ownedMinifigInstance['condition_type']
    );
    $content .= '<tr><th>' . htmlspecialchars(t('owned_set_bricklink_price_label')) . '</th><td>';
    $content .= '<span id="minifig-bricklink-price-text"' . ($ownedMinifigPriceDisplay['title'] !== null ? ' title="' . htmlspecialchars($ownedMinifigPriceDisplay['title']) . '"' : '') . '>' . htmlspecialchars($ownedMinifigPriceDisplay['text']) . '</span> ';
    $content .= '<button type="button" class="owned-set-bricklink-refresh-btn" id="minifig-bricklink-refresh" data-minifig-id="' . $ownedMinifigInstance['minifig_id'] . '" data-condition-type="' . htmlspecialchars($ownedMinifigInstance['condition_type']) . '" title="' . htmlspecialchars(t('owned_set_bricklink_price_refresh_label')) . '" aria-label="' . htmlspecialchars(t('owned_set_bricklink_price_refresh_label')) . '">' . getActionIcon('refresh') . '</button>';
    $content .= '</td></tr>';

    if ($ownedMinifigInstance['notes'] !== null && $ownedMinifigInstance['notes'] !== '') {
        $content .= '<tr><th>' . htmlspecialchars(t('owned_set_notes_label')) . '</th><td>' . htmlspecialchars($ownedMinifigInstance['notes']) . '</td></tr>';
    }
    $content .= '</table>';
    $content .= '</div>';

    $content .= '<div class="set-detail-table-wrap owned-set-actionbar">';
    $content .= '<button type="button" class="owned-set-action-pill" id="minifig-edit-open" title="' . htmlspecialchars(t('owned_set_edit_heading')) . '" aria-label="' . htmlspecialchars(t('owned_set_edit_heading')) . '">' . getActionIcon('edit') . '</button>';
    $content .= '<button type="button" class="owned-set-action-pill" id="minifig-move-open" title="' . htmlspecialchars(t('owned_set_move_heading')) . '" aria-label="' . htmlspecialchars(t('owned_set_move_heading')) . '">' . getActionIcon('move') . '</button>';

    $content .= '<form method="post" id="remove-minifig-form" class="owned-set-action-pill-form">';
    $content .= '<input type="hidden" name="action" value="remove_minifig_storage_item">';
    $content .= '<input type="hidden" name="instance_id" value="' . $ownedMinifigInstance['id'] . '">';
    $content .= '<button type="submit" class="owned-set-action-pill owned-set-action-pill-danger" title="' . htmlspecialchars(t('owned_minifig_remove_button')) . '" aria-label="' . htmlspecialchars(t('owned_minifig_remove_button')) . '">' . getActionIcon('delete') . '</button>';
    $content .= '</form>';

    $content .= '<button type="button" class="owned-set-action-pill" id="minifig-bricklink-open" title="' . htmlspecialchars(t('owned_minifig_bricklink_xml_label')) . '" aria-label="' . htmlspecialchars(t('owned_minifig_bricklink_xml_label')) . '">' . getActionIcon('bricklink_xml') . '</button>';
    $content .= renderOwnedMinifigBricklinkModal($ownedMinifigInstance);
    $content .= '<button type="button" class="owned-set-action-pill" id="minifig-sell-open" title="' . htmlspecialchars(t('owned_set_sell_heading')) . '" aria-label="' . htmlspecialchars(t('owned_set_sell_heading')) . '">' . getActionIcon('sell') . '</button>';

    $removeMinifigConfirmJson = json_encode(t('owned_minifig_remove_confirm'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $content .= <<<SCRIPT
<script>
(function(){
  var form = document.getElementById("remove-minifig-form");
  if (!form) { return; }
  form.addEventListener("submit", function(e) {
    if (!window.confirm($removeMinifigConfirmJson)) {
      e.preventDefault();
    }
  });
})();
</script>
SCRIPT;

    $minifigBricklinkRefreshFailedJson = json_encode(t('owned_set_bricklink_price_refresh_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $content .= <<<SCRIPT
<script>
(function(){
  var btn = document.getElementById("minifig-bricklink-refresh");
  if (!btn) { return; }
  btn.addEventListener("click", function() {
    btn.disabled = true;
    btn.classList.add("owned-set-bricklink-refresh-spinning");
    var formData = new FormData();
    formData.set("action", "refresh_minifig_bricklink_price");
    formData.set("minifig_id", btn.dataset.minifigId);
    formData.set("condition_type", btn.dataset.conditionType);
    fetch("?", { method: "POST", body: formData, credentials: "same-origin" })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success) {
          window.location.reload();
          return;
        }
        btn.disabled = false;
        btn.classList.remove("owned-set-bricklink-refresh-spinning");
        window.alert($minifigBricklinkRefreshFailedJson + " " + res.message);
      })
      .catch(function() {
        btn.disabled = false;
        btn.classList.remove("owned-set-bricklink-refresh-spinning");
        window.alert($minifigBricklinkRefreshFailedJson);
      });
  });
})();
</script>
SCRIPT;

    $content .= '</div>';

    $content .= renderOwnedMinifigEditModal($ownedMinifigInstance);
    $content .= renderOwnedMinifigMoveModal($ownedMinifigInstance);
    $content .= renderOwnedMinifigSellModal($ownedMinifigInstance);

    $content .= '</div>'; // .owned-set-sidebar

    $content .= '<div class="owned-set-tabs-row">';

    $ownedMinifigTabs = [
        'parts' => t('owned_minifig_tab_parts'),
        'damaged_missing' => t('owned_set_tab_damaged_missing'),
        'gallery' => t('owned_set_tab_gallery'),
    ];
    $activeMinifigTab = (string) ($_GET['tab'] ?? '');
    if (!isset($ownedMinifigTabs[$activeMinifigTab])) {
        $activeMinifigTab = array_key_first($ownedMinifigTabs);
    }

    $content .= '<nav class="set-detail-tabs" id="minifig-tabs-nav">';
    foreach ($ownedMinifigTabs as $tabKey => $tabLabel) {
        $activeAttr = $tabKey === $activeMinifigTab ? ' class="active"' : '';
        $content .= '<a' . $activeAttr . ' data-tab="' . $tabKey . '" href="?page=owned_minifig_detail&id=' . $ownedMinifigInstanceId . '&tab=' . $tabKey . '">' . htmlspecialchars($tabLabel) . '</a>';
    }
    $content .= '</nav>';

    $minifigLoadingHtml = '<div class="owned-set-tab-loading"><span class="owned-set-tab-spinner"></span><span>' . htmlspecialchars(t('owned_set_tab_loading')) . '</span></div>';
    $content .= '<div id="minifig-tab-content" data-instance-id="' . $ownedMinifigInstanceId . '" data-active-tab="' . htmlspecialchars($activeMinifigTab) . '">' . $minifigLoadingHtml . '</div>';

    $minifigTabLoadingLabelsJson = json_encode([
        'loading' => t('owned_set_tab_loading'),
        'errorRetry' => t('import_error_retry'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $minifigLoadingHtmlJson = json_encode($minifigLoadingHtml, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $minifigTabLocaleJson = json_encode(getLocale(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $content .= <<<SCRIPT
<script>
(function(){
  var texts = $minifigTabLoadingLabelsJson;
  var loadingHtml = $minifigLoadingHtmlJson;
  var appLocale = $minifigTabLocaleJson;
  var container = document.getElementById('minifig-tab-content');
  var nav = document.getElementById('minifig-tabs-nav');
  if (!container || !nav) {
    return;
  }
  var instanceId = container.dataset.instanceId;

  function runScripts(root) {
    var scripts = root.querySelectorAll('script');
    for (var i = 0; i < scripts.length; i++) {
      var oldScript = scripts[i];
      var freshScript = document.createElement('script');
      freshScript.textContent = oldScript.textContent;
      oldScript.parentNode.replaceChild(freshScript, oldScript);
    }
  }

  function formatNumber(n) {
    var sep = appLocale === 'de' ? '.' : ',';
    return String(n).replace(/\\B(?=(\\d{3})+(?!\\d))/g, sep);
  }

  function applyStats(stats) {
    if (!stats) {
      return;
    }
    Object.keys(stats).forEach(function(key) {
      var el = document.getElementById('status-stat-' + key);
      var strong = el ? el.querySelector('strong') : null;
      if (strong) {
        strong.textContent = formatNumber(stats[key]);
      }
    });
  }

  function loadTab(tabKey, pushState) {
    container.innerHTML = loadingHtml;
    var params = new URLSearchParams(window.location.search);
    params.set('page', 'owned_minifig_detail');
    params.set('id', instanceId);
    params.set('tab', tabKey);
    params.set('ajax', '1');
    fetch('?' + params.toString(), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (!res.success) {
          container.textContent = res.message || texts.errorRetry;
          return;
        }
        container.innerHTML = res.html;
        runScripts(container);
        applyStats(res.stats);
        var links = nav.querySelectorAll('a');
        for (var i = 0; i < links.length; i++) {
          links[i].classList.toggle('active', links[i].dataset.tab === tabKey);
        }
        container.dataset.activeTab = tabKey;
        if (pushState) {
          var urlParams = new URLSearchParams(window.location.search);
          urlParams.set('tab', tabKey);
          history.pushState({ tab: tabKey }, '', '?' + urlParams.toString());
        }
      })
      .catch(function() {
        container.textContent = texts.errorRetry;
      });
  }

  var navLinks = nav.querySelectorAll('a');
  for (var i = 0; i < navLinks.length; i++) {
    navLinks[i].addEventListener('click', function(e) {
      e.preventDefault();
      loadTab(this.dataset.tab, true);
    });
  }

  window.addEventListener('popstate', function() {
    var params = new URLSearchParams(window.location.search);
    loadTab(params.get('tab') || container.dataset.activeTab, false);
  });

  loadTab(container.dataset.activeTab, false);
})();
</script>
SCRIPT;

    $content .= '</div>'; // .owned-set-tabs-row
    $content .= '</div>'; // .owned-set-layout

    $ownedMinifigPageTitle = t('owned_minifig_detail_page_title', ['fig_num' => $ownedMinifigInstance['fig_num'], 'name' => $ownedMinifigName]);
    renderApp($ownedMinifigPageTitle, $content, $user, computeAppStats($pdo), $ownedMinifigBreadcrumbs);
    exit;
}
