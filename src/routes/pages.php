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
    $locationOptions = getStorageLocationOptions();
    $types = getLocationTypes();

    $content = '<h1>' . htmlspecialchars(t('locations_title')) . '</h1>';
    if ($locationMessage !== '') {
        $content .= '<p><strong>' . htmlspecialchars($locationMessage) . '</strong></p>';
    }

    // Add/edit form: unchanged submit targets (rename_location/add_location,
    // same as before this page's redesign) — just repositioned into the tree
    // pane, and collapsed by default via <details> so it doesn't dominate a
    // pane that's 40% width by default. Forced open while editing.
    $content .= '<details class="location-add-form-details"' . ($isEdit ? ' open' : '') . '>';
    $content .= '<summary>' . htmlspecialchars($isEdit ? t('location_edit_title') : t('locations_add_toggle')) . '</summary>';
    $content .= '<form method="post" id="location-form">';
    if ($isEdit) {
        $content .= '<input type="hidden" name="action" value="rename_location">';
        $content .= '<input type="hidden" name="location_id" value="' . (int) $editLocation['id'] . '">';
    } else {
        $content .= '<input type="hidden" name="action" value="add_location">';
        $content .= '<label>' . htmlspecialchars(t('location_parent_label')) . '<select name="parent_id">';
        $content .= '<option value="">' . htmlspecialchars(t('location_parent_none')) . '</option>';
        foreach ($locationOptions as $optId => $optLabel) {
            $content .= '<option value="' . $optId . '">' . htmlspecialchars($optLabel) . '</option>';
        }
        $content .= '</select></label>';
    }
    $content .= '<label>' . htmlspecialchars(t('location_name_label')) . '<input name="name" value="' . htmlspecialchars($isEdit ? $editLocation['name'] : '') . '" required></label>';
    $content .= '<label>' . htmlspecialchars(t('location_type_label')) . '<select name="type" id="location-type-select">';
    $content .= '<option value="">' . htmlspecialchars(t('location_type_none')) . '</option>';
    $currentType = $isEdit ? $editLocation['location_type'] : null;
    foreach ($types as $typeKey => $typeConfig) {
        $selected = $typeKey === $currentType ? ' selected' : '';
        $content .= '<option value="' . htmlspecialchars($typeKey) . '"' . $selected . '>' . htmlspecialchars(t($typeConfig['labelKey'])) . '</option>';
    }
    $content .= '</select></label>';

    if (!$isEdit) {
        $content .= '<div id="location-bulk-fields" style="display:none;">';
        $content .= '<label>' . htmlspecialchars(t('location_bulk_count_label')) . '<input type="number" name="child_count" min="0" value="0"></label>';
        $content .= '<label>' . htmlspecialchars(t('location_bulk_naming_label')) . '<input type="text" name="naming_pattern" id="location-naming-pattern" value=""></label>';
        $content .= '</div>';
    }

    $content .= '<button type="submit">' . htmlspecialchars($isEdit ? t('location_save_button') : t('location_add_button')) . '</button>';
    if ($isEdit) {
        $content .= ' <a href="?page=locations">' . htmlspecialchars(t('location_cancel_edit')) . '</a>';
    }
    $content .= '</form></details>';

    if (!$isEdit) {
        $bulkPatterns = [];
        foreach ($types as $typeKey => $typeConfig) {
            $bulkPatterns[$typeKey] = $typeConfig['bulkChildKey'] !== null ? t($typeConfig['bulkChildKey']) : null;
        }
        $bulkPatternsJson = json_encode($bulkPatterns, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $content .= <<<SCRIPT
<script>
(function(){
  var patterns = $bulkPatternsJson;
  var typeSelect = document.getElementById("location-type-select");
  var bulkFields = document.getElementById("location-bulk-fields");
  var namingInput = document.getElementById("location-naming-pattern");
  if (!typeSelect || !bulkFields || !namingInput) {
    return;
  }
  function update() {
    var pattern = patterns[typeSelect.value];
    if (pattern) {
      bulkFields.style.display = "block";
      namingInput.value = pattern;
    } else {
      bulkFields.style.display = "none";
      namingInput.value = "";
    }
  }
  typeSelect.addEventListener("change", update);
  update();
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
    // location up front.
    $tree = getStorageLocationTree();
    $treeJson = json_encode($tree, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $typeLabels = [];
    foreach ($types as $typeKey => $typeConfig) {
        $typeLabels[$typeKey] = t($typeConfig['labelKey']);
    }

    $content .= '<div class="location-explorer" id="location-explorer">';
    $content .= '<div class="location-explorer-tree-pane" id="location-explorer-tree-pane">';
    if (empty($tree)) {
        $content .= '<p class="hint">' . htmlspecialchars(t('locations_tree_empty')) . '</p>';
    } else {
        $content .= '<div class="location-tree-explorer" id="location-tree-explorer"></div>';
    }
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

    $explorerLabelsJson = json_encode([
        'chevronIcon' => getActionIcon('chevron_right'),
        'editIcon' => getActionIcon('edit'),
        'deleteIcon' => getActionIcon('delete'),
        'addIcon' => getActionIcon('add'),
        'brickIcon' => getNavIcon('bricks'),
        'minifigIcon' => getNavIcon('minifigs'),
        'expandLabel' => t('locations_tree_expand_label'),
        'editLabel' => t('location_edit_link'),
        'deleteLabel' => t('location_delete_link'),
        'deleteConfirm' => t('location_delete_confirm'),
        'loading' => t('location_explorer_loading'),
        'errorRetry' => t('import_error_retry'),
        'contentEmpty' => t('location_detail_empty'),
        'groupSets' => t('location_content_group_sets'),
        'groupMinifigs' => t('location_content_group_minifigs'),
        'minifigsEmpty' => t('location_content_minifigs_empty'),
        'addMinifigLabel' => t('location_add_minifig_label'),
        'minifigSearchPlaceholder' => t('location_add_minifig_search_placeholder'),
        'quantityLabel' => t('add_stock_quantity_label'),
        'conditionLabel' => t('add_stock_condition_label'),
        'conditionNew' => t('condition_new'),
        'conditionUsed' => t('condition_used'),
        'addButton' => t('add_stock_button'),
        'typeLabels' => $typeLabels,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $content .= <<<SCRIPT
<script>
(function(){
  var tree = $treeJson;
  var texts = $explorerLabelsJson;
  var treeContainer = document.getElementById('location-tree-explorer');
  var contentEl = document.getElementById('location-explorer-content');
  var deleteForm = document.getElementById('location-delete-form');
  var deleteFormId = document.getElementById('location-delete-form-id');
  var explorer = document.getElementById('location-explorer');
  var treePane = document.getElementById('location-explorer-tree-pane');
  var resizeHandle = document.getElementById('location-explorer-resize-handle');
  if (!contentEl) {
    return;
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

  function buildGroup(title, bodyEl, extraHeaderEl) {
    var section = document.createElement('section');
    section.className = 'location-content-group';
    var header = document.createElement('div');
    header.className = 'location-content-group-header';
    var h = document.createElement('h3');
    h.textContent = title;
    header.appendChild(h);
    if (extraHeaderEl) {
      header.appendChild(extraHeaderEl);
    }
    section.appendChild(header);
    section.appendChild(bodyEl);
    return section;
  }

  function buildSetsList(sets) {
    var list = document.createElement('ul');
    list.className = 'dashboard-set-list';
    sets.forEach(function(set) {
      var li = document.createElement('li');
      var a = document.createElement('a');
      a.href = '?page=owned_set_detail&id=' + set.id;
      if (set.thumbnail) {
        var img = document.createElement('img');
        img.src = set.thumbnail;
        img.alt = '';
        img.className = 'dashboard-set-thumb';
        a.appendChild(img);
      }
      var nameSpan = document.createElement('span');
      nameSpan.className = 'dashboard-set-name';
      nameSpan.textContent = set.name;
      a.appendChild(nameSpan);
      var small = document.createElement('small');
      small.textContent = set.rebrickable_set_num;
      a.appendChild(small);
      li.appendChild(a);
      list.appendChild(li);
    });
    return list;
  }

  function buildPartsGrid(parts) {
    var grid = document.createElement('div');
    grid.className = 'location-detail-grid';
    parts.forEach(function(item) {
      var card = document.createElement('div');
      card.className = 'location-detail-card';

      var thumb = document.createElement('span');
      thumb.className = 'location-detail-card-thumb';
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
      meta.textContent = (item.color_name || '') + ' \\u00b7 ' + condText + ' \\u00b7 ' + item.quantity + 'x';
      card.appendChild(meta);

      grid.appendChild(card);
    });
    return grid;
  }

  function buildMinifigsGrid(minifigs) {
    var grid = document.createElement('div');
    grid.className = 'location-detail-grid';
    minifigs.forEach(function(fig) {
      var card = document.createElement('div');
      card.className = 'location-detail-card';

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
      meta.textContent = condText + ' \\u00b7 ' + fig.quantity + 'x';
      card.appendChild(meta);

      grid.appendChild(card);
    });
    return grid;
  }

  function buildAddMinifigControl(locationId, onAdded) {
    var wrap = document.createElement('span');
    wrap.className = 'location-add-minifig';

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'location-add-minifig-toggle';
    toggle.innerHTML = texts.addIcon;
    toggle.title = texts.addMinifigLabel;
    toggle.setAttribute('aria-label', texts.addMinifigLabel);
    wrap.appendChild(toggle);

    var panel = document.createElement('div');
    panel.className = 'location-add-minifig-panel';
    panel.hidden = true;

    var searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.placeholder = texts.minifigSearchPlaceholder;
    panel.appendChild(searchInput);

    var results = document.createElement('div');
    results.className = 'location-add-minifig-results';
    panel.appendChild(results);

    var selectedId = null;
    var selectedLabel = document.createElement('div');
    selectedLabel.className = 'location-add-minifig-selected';
    panel.appendChild(selectedLabel);

    var qtyLabel = document.createElement('label');
    qtyLabel.textContent = texts.quantityLabel;
    var qtyInput = document.createElement('input');
    qtyInput.type = 'number';
    qtyInput.min = '1';
    qtyInput.value = '1';
    qtyLabel.appendChild(qtyInput);
    panel.appendChild(qtyLabel);

    var condLabel = document.createElement('label');
    condLabel.textContent = texts.conditionLabel;
    var condSelect = document.createElement('select');
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
    panel.appendChild(condLabel);

    var submitBtn = document.createElement('button');
    submitBtn.type = 'button';
    submitBtn.textContent = texts.addButton;
    submitBtn.disabled = true;
    panel.appendChild(submitBtn);

    var msg = document.createElement('div');
    msg.className = 'location-add-minifig-message';
    panel.appendChild(msg);

    var searchTimer = null;
    searchInput.addEventListener('input', function() {
      selectedId = null;
      submitBtn.disabled = true;
      selectedLabel.textContent = '';
      window.clearTimeout(searchTimer);
      var q = searchInput.value.trim();
      results.innerHTML = '';
      if (q === '') {
        return;
      }
      searchTimer = window.setTimeout(function() {
        fetch('?action=minifig_search&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
          .then(function(r) { return r.json(); })
          .then(function(data) {
            results.innerHTML = '';
            (data.items || []).forEach(function(fig) {
              var item = document.createElement('button');
              item.type = 'button';
              item.className = 'location-add-minifig-result';
              item.textContent = (fig.name || fig.fig_num) + ' (' + fig.fig_num + ')';
              item.addEventListener('click', function() {
                selectedId = fig.id;
                selectedLabel.textContent = item.textContent;
                results.innerHTML = '';
                searchInput.value = '';
                submitBtn.disabled = false;
              });
              results.appendChild(item);
            });
          });
      }, 300);
    });

    submitBtn.addEventListener('click', function() {
      if (!selectedId) {
        return;
      }
      submitBtn.disabled = true;
      msg.textContent = '';
      var formData = new FormData();
      formData.set('action', 'add_minifig_stock');
      formData.set('location_id', locationId);
      formData.set('minifig_id', selectedId);
      formData.set('quantity', qtyInput.value);
      formData.set('condition_type', condSelect.value);
      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (res.success) {
            panel.hidden = true;
            onAdded();
          } else {
            msg.textContent = res.message || texts.errorRetry;
            submitBtn.disabled = false;
          }
        })
        .catch(function() {
          msg.textContent = texts.errorRetry;
          submitBtn.disabled = false;
        });
    });

    toggle.addEventListener('click', function(e) {
      e.stopPropagation();
      panel.hidden = !panel.hidden;
    });
    panel.addEventListener('click', function(e) {
      e.stopPropagation();
    });

    wrap.appendChild(panel);
    return wrap;
  }

  function renderContent(locationId, name, data) {
    contentEl.innerHTML = '';
    var heading = document.createElement('h2');
    heading.textContent = name;
    contentEl.appendChild(heading);

    if (data.sets.length > 0) {
      contentEl.appendChild(buildGroup(texts.groupSets, buildSetsList(data.sets)));
    }

    data.categories.forEach(function(cat) {
      contentEl.appendChild(buildGroup(cat.name, buildPartsGrid(cat.parts)));
    });

    var addMinifigControl = buildAddMinifigControl(locationId, function() {
      loadContent(locationId, name);
    });
    var minifigsBody;
    if (data.minifigs.length > 0) {
      minifigsBody = buildMinifigsGrid(data.minifigs);
    } else {
      minifigsBody = document.createElement('p');
      minifigsBody.className = 'hint';
      minifigsBody.textContent = texts.minifigsEmpty;
    }
    contentEl.appendChild(buildGroup(texts.groupMinifigs, minifigsBody, addMinifigControl));
  }

  function loadContent(id, name) {
    contentEl.innerHTML = '<p class="hint">' + texts.loading + '</p>';
    fetch('?action=location_content&location_id=' + id, { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) { renderContent(id, name, data); })
      .catch(function() {
        contentEl.innerHTML = '<p class="hint">' + texts.errorRetry + '</p>';
      });
  }

  function buildRow(node, depth) {
    var wrap = document.createElement('div');
    wrap.className = 'location-tree-node';

    var row = document.createElement('div');
    row.className = 'location-tree-row';
    row.style.paddingLeft = (depth * 1.25 + 0.25) + 'rem';

    var hasChildren = node.children && node.children.length > 0;

    var arrow = document.createElement('button');
    arrow.type = 'button';
    arrow.className = 'location-tree-arrow' + (hasChildren ? '' : ' location-tree-arrow-empty');
    if (hasChildren) {
      arrow.innerHTML = texts.chevronIcon;
      arrow.setAttribute('aria-label', texts.expandLabel);
    } else {
      arrow.disabled = true;
      arrow.tabIndex = -1;
    }
    row.appendChild(arrow);

    var nameBtn = document.createElement('button');
    nameBtn.type = 'button';
    nameBtn.className = 'location-tree-name';
    nameBtn.textContent = node.name;
    row.appendChild(nameBtn);

    if (node.location_type && texts.typeLabels[node.location_type]) {
      var badge = document.createElement('span');
      badge.className = 'location-type-badge';
      badge.textContent = texts.typeLabels[node.location_type];
      row.appendChild(badge);
    }

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

    wrap.appendChild(row);

    var childrenWrap = null;
    if (hasChildren) {
      childrenWrap = document.createElement('div');
      childrenWrap.className = 'location-tree-children';
      childrenWrap.hidden = true;
      node.children.forEach(function(child) {
        childrenWrap.appendChild(buildRow(child, depth + 1));
      });
      wrap.appendChild(childrenWrap);
    }

    function toggleExpand() {
      if (!childrenWrap) {
        return;
      }
      childrenWrap.hidden = !childrenWrap.hidden;
      arrow.classList.toggle('location-tree-arrow-open', !childrenWrap.hidden);
    }

    arrow.addEventListener('click', function(e) {
      e.stopPropagation();
      toggleExpand();
    });
    nameBtn.addEventListener('click', function() {
      selectLocation(node.id, node.name, row);
    });
    nameBtn.addEventListener('dblclick', function() {
      toggleExpand();
    });

    return wrap;
  }

  if (treeContainer) {
    tree.forEach(function(node) {
      treeContainer.appendChild(buildRow(node, 0));
    });
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
  function sizeExplorer() {
    if (!explorer) {
      return;
    }
    if (window.innerWidth <= 800) {
      explorer.style.height = '';
      return;
    }
    var top = explorer.getBoundingClientRect().top;
    var height = window.innerHeight - top - 24;
    explorer.style.height = Math.max(320, height) + 'px';
  }
  sizeExplorer();
  window.addEventListener('resize', sizeExplorer);
})();
</script>
SCRIPT;

    renderApp(t('locations_title'), $content, $user, computeAppStats($pdo), [homeBreadcrumb(), ['label' => t('locations_title'), 'url' => null]]);
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
            $content .= '<span class="location-detail-card-thumb">' . ($thumbnail !== null ? '<img src="' . htmlspecialchars($thumbnail) . '" alt="">' : getNavIcon('bricks')) . '</span>';
            $content .= '<span class="location-detail-card-swatch" style="background-color:#' . htmlspecialchars($item['color_rgb'] ?? 'cccccc') . ';"></span>';
            $content .= '<span class="location-detail-card-num">' . htmlspecialchars($item['part_num']) . '</span>';
            $content .= '<span class="location-detail-card-name" title="' . htmlspecialchars($item['part_name']) . '">' . htmlspecialchars($item['part_name']) . '</span>';
            $content .= '<span class="location-detail-card-meta">' . htmlspecialchars(($item['color_name'] ?? '') . ' · ' . $condLabel . ' · ' . $item['quantity'] . 'x') . '</span>';
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
    $selectedThemes = array_values(array_filter(array_map('strval', (array) ($_GET['theme'] ?? []))));
    $pageNum = max(1, (int) ($_GET['p'] ?? 1));
    $perPage = MINIFIGS_SEARCH_PAGE_SIZE;
    $isBrowsing = $searchQuery === '' && empty($selectedThemes);

    // Infinite-scroll continuation request: return just the next batch of
    // cards as JSON instead of a full page render (mirrors bricks_search).
    if (!$isBrowsing && ($_GET['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        $results = searchMinifigs($pdo, $searchQuery, $selectedThemes, $pageNum, $perPage);
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

    $minifigsBreadcrumbs = [homeBreadcrumb(), ['label' => t('nav_minifigs_search'), 'url' => $isBrowsing ? null : '?page=minifigs_search']];

    if ($isBrowsing) {
        $themes = getMinifigThemes($pdo);
        if (empty($themes)) {
            $content .= '<section class="card"><p>' . htmlspecialchars(t('minifigs_categories_empty')) . '</p></section>';
        } else {
            $tileImages = getMinifigThemeTileImages($pdo, array_map(function ($theme) {
                return $theme['theme_id'];
            }, $themes));
            $content .= '<div class="category-tile-grid minifig-theme-grid">';
            foreach ($themes as $theme) {
                $img = $tileImages[(string) $theme['theme_id']] ?? null;
                $content .= '<a class="category-tile minifig-theme-tile" href="?page=minifigs_search&theme%5B%5D=' . urlencode((string) $theme['theme_id']) . '">';
                $content .= '<span class="category-tile-image minifig-theme-tile-image">' . ($img !== null ? '<img src="' . htmlspecialchars($img) . '" alt="">' : getNavIcon('minifigs')) . '</span>';
                $content .= '<span class="category-tile-label minifig-theme-tile-label">' . htmlspecialchars($theme['name']) . '</span>';
                $content .= '</a>';
            }
            $content .= '</div>';
        }
    } else {
        $results = searchMinifigs($pdo, $searchQuery, $selectedThemes, $pageNum, $perPage);
        $allThemes = getMinifigThemes($pdo);

        if ($searchQuery !== '') {
            $minifigsBreadcrumbs[] = ['label' => t('search_results_for', ['query' => $searchQuery]), 'url' => null];
        } elseif (count($selectedThemes) === 1) {
            foreach ($allThemes as $theme) {
                if ((string) $theme['theme_id'] === $selectedThemes[0]) {
                    $minifigsBreadcrumbs[] = ['label' => $theme['name'], 'url' => null];
                    break;
                }
            }
        }

        $sidebar = '<form method="get" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '" class="parts-filter-sidebar">';
        $sidebar .= '<input type="hidden" name="page" value="minifigs_search">';
        if ($searchQuery !== '') {
            $sidebar .= '<input type="hidden" name="q" value="' . htmlspecialchars($searchQuery) . '">';
        }
        $sidebar .= '<div class="filter-group"><h3>' . htmlspecialchars(t('filter_theme_title')) . '</h3><div class="filter-options">';
        foreach ($allThemes as $theme) {
            $themeIdStr = (string) $theme['theme_id'];
            $checked = in_array($themeIdStr, $selectedThemes, true) ? ' checked' : '';
            $sidebar .= '<label class="filter-checkbox"><input type="checkbox" name="theme[]" value="' . htmlspecialchars($themeIdStr) . '"' . $checked . '> ' . htmlspecialchars($theme['name']) . ' <span class="filter-count">(' . (int) $theme['cnt'] . ')</span></label>';
        }
        $sidebar .= '</div></div>';
        $sidebar .= '<button type="submit" class="filter-apply-button">' . htmlspecialchars(t('filter_apply_button')) . '</button>';
        $sidebar .= '</form>';

        $main = '<p><a href="?page=minifigs_search">&larr; ' . htmlspecialchars(t('back_to_categories')) . '</a></p>';
        $main .= '<span class="results-summary">' . htmlspecialchars(t('minifigs_found_count', ['count' => formatNumber($results['total'])])) . '</span>';

        if (empty($results['items'])) {
            $main .= '<section class="card"><p>' . htmlspecialchars(t('minifigs_categories_empty')) . '</p></section>';
        } else {
            $hasMore = $perPage < $results['total'];
            $grouped = renderYearGroupedCards($results['items'], null, false, 'renderMinifigCard');
            $lastYearAttr = $grouped['lastYearKnown'] ? ($grouped['lastYear'] ?? 'unknown') : 'unknown';
            $main .= '<div class="minifigs-grid" id="minifigs-grid">' . $grouped['html'] . '</div>';
            $main .= '<div id="minifigs-load-sentinel" class="parts-load-sentinel" data-has-more="' . ($hasMore ? '1' : '0') . '" data-next-page="2" data-last-year="' . htmlspecialchars((string) $lastYearAttr) . '">';
            $main .= '<span class="parts-load-status" data-loading-text="' . htmlspecialchars(t('parts_loading_more')) . '" data-end-text="' . htmlspecialchars(t('parts_no_more')) . '">' . ($hasMore ? '' : htmlspecialchars(t('parts_no_more'))) . '</span>';
            $main .= '</div>';
            $main .= <<<SCRIPT
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
        }

        $content .= '<div class="parts-search-layout">' . $sidebar . '<div class="parts-search-main">' . $main . '</div></div>';
    }

    renderApp(t('nav_minifigs_search'), $content, $user, computeAppStats($pdo), $minifigsBreadcrumbs);
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
        : ['exclusive' => 0, 'rare' => 0, 'stickers' => 0];

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

    $content .= '<div class="set-detail-table-wrap">';
    $content .= '<span class="set-detail-table-heading">' . htmlspecialchars(t('set_detail_inventory_heading')) . '</span>';
    $content .= '<table class="set-detail-table">';
    if ($set['num_parts'] !== null) {
        $content .= '<tr><th>' . htmlspecialchars(t('set_detail_field_total')) . '</th><td>' . htmlspecialchars(t('set_detail_num_parts', ['count' => formatNumber((int) $set['num_parts'])])) . '</td></tr>';
    }
    $content .= '<tr><th>' . htmlspecialchars(t('set_detail_field_exclusive')) . '</th><td>' . (int) $inventorySummary['exclusive'] . '</td></tr>';
    $content .= '<tr><th>' . htmlspecialchars(t('set_detail_field_rare')) . '</th><td>' . (int) $inventorySummary['rare'] . '</td></tr>';
    $content .= '<tr><th>' . htmlspecialchars(t('set_detail_field_stickers')) . '</th><td>' . (int) $inventorySummary['stickers'] . '</td></tr>';
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

    $content .= '<a href="#" id="add-owned-set-open">' . htmlspecialchars(t('set_detail_add_to_collection_button')) . '</a>';
    $content .= renderAddOwnedSetWizardModal($pdo, $setId);

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
    $renderSetPartsGrid = function (array $items, bool $groupByRarity = false, ?int $inventoryId = null) use ($pdo): string {
        $missingCount = 0;
        $renderCard = function (array $item) use (&$missingCount): string {
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
            return renderPartCard($part, $meta, $fetchColorId);
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
            : $renderSetPartsGrid($items, true, $targetInventoryId);

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
        $instructions = getSetInstructions($pdo, $setId);

        $content .= '<form id="instruction-upload-form" class="instruction-upload-form">';
        $content .= '<input type="text" id="instruction-label-input" placeholder="' . htmlspecialchars(t('set_detail_instructions_label_placeholder')) . '" maxlength="255">';
        $content .= '<input type="file" id="instruction-file-input" accept="application/pdf">';
        $content .= '<button type="submit">' . htmlspecialchars(t('set_detail_instructions_upload_button')) . '</button>';
        $content .= '<span class="instruction-upload-message" id="instruction-upload-message"></span>';
        $content .= '</form>';

        if (empty($instructions)) {
            $content .= '<p class="instructions-empty">' . htmlspecialchars(t('set_detail_instructions_empty')) . '</p>';
        } else {
            $content .= '<ul class="instructions-list">';
            foreach ($instructions as $instruction) {
                $uploadedAt = formatDate($instruction['uploaded_at']);
                $label = $instruction['label'] !== null ? $instruction['label'] : $instruction['original_filename'];
                $content .= '<li class="instruction-item" data-id="' . $instruction['id'] . '">';
                $content .= '<a href="' . htmlspecialchars($instruction['stored_path']) . '" target="_blank" rel="noopener">' . htmlspecialchars($label) . '</a>';
                $content .= '<span class="instruction-meta">' . htmlspecialchars(formatFileSize($instruction['file_size'])) . ' · ' . htmlspecialchars($uploadedAt) . '</span>';
                $content .= '<button type="button" class="instruction-delete-btn" data-id="' . $instruction['id'] . '">' . htmlspecialchars(t('set_detail_instructions_delete_button')) . '</button>';
                $content .= '</li>';
            }
            $content .= '</ul>';
        }

        $instructionLabelsJson = json_encode([
            'uploading' => t('set_detail_instructions_uploading'),
            'deleteConfirm' => t('set_detail_instructions_delete_confirm'),
            'errorRetry' => t('import_error_retry'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $content .= <<<SCRIPT
<script>
(function(){
  var texts = $instructionLabelsJson;
  var form = document.getElementById('instruction-upload-form');
  var labelInput = document.getElementById('instruction-label-input');
  var fileInput = document.getElementById('instruction-file-input');
  var msg = document.getElementById('instruction-upload-message');
  if (form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      if (!fileInput.files || !fileInput.files[0]) {
        return;
      }
      msg.textContent = texts.uploading;
      var formData = new FormData();
      formData.set('action', 'upload_set_instruction');
      formData.set('set_id', '$setId');
      formData.set('label', labelInput.value);
      formData.set('instruction_file', fileInput.files[0]);

      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (res.success) {
            window.location.reload();
          } else {
            msg.textContent = res.message;
          }
        })
        .catch(function() {
          msg.textContent = texts.errorRetry;
        });
    });
  }

  document.querySelectorAll('.instruction-delete-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      if (!window.confirm(texts.deleteConfirm)) {
        return;
      }
      var formData = new FormData();
      formData.set('action', 'delete_set_instruction');
      formData.set('instruction_id', btn.dataset.id);

      fetch('?', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (res.success) {
            window.location.reload();
          }
        });
    });
  });
})();
</script>
SCRIPT;
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
        return renderOwnedSetPhotoGallery($pdo, $ownedSet);
    };
    $ownedSetTabKeys = ['inventory', 'spares', 'stickers', 'minifigs', 'damaged_missing', 'gallery'];

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
        ['label' => $ownedSet['name'], 'url' => '?page=set_detail&id=' . $ownedSet['set_id']],
        ['label' => t('owned_set_instance_label'), 'url' => null],
    ];

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

    $content .= '<div class="owned-set-sidebar">';
    $content .= '<h1 class="set-detail-title">' . htmlspecialchars($ownedSet['rebrickable_set_num']) . '</h1>';

    if ($ownedSetDetailMessage !== '') {
        $content .= '<p class="owned-set-message">' . htmlspecialchars($ownedSetDetailMessage) . '</p>';
    }

    // Same prev/next nav as the catalog set-detail page, just walking this
    // user's own owned-set instances (getAdjacentOwnedSets()) instead of the
    // whole catalog, and linking to owned_set_detail instead of set_detail.
    $content .= '<div class="set-detail-setnav">';
    $content .= $adjacentOwnedSets['prev'] !== null
        ? '<a href="?page=owned_set_detail&id=' . $adjacentOwnedSets['prev']['id'] . '">&lsaquo; ' . htmlspecialchars($adjacentOwnedSets['prev']['rebrickable_set_num']) . '</a>'
        : '<span></span>';
    $content .= $adjacentOwnedSets['next'] !== null
        ? '<a href="?page=owned_set_detail&id=' . $adjacentOwnedSets['next']['id'] . '">' . htmlspecialchars($adjacentOwnedSets['next']['rebrickable_set_num']) . ' &rsaquo;</a>'
        : '<span></span>';
    $content .= '</div>';

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
    $renderActualNominalRow = function (string $labelKey, string $idKey, array $counts) use (&$content): void {
        $content .= '<tr><th>' . htmlspecialchars(t($labelKey)) . '</th><td id="owned-set-summary-' . $idKey . '">' . htmlspecialchars(t('owned_set_num_parts_actual', ['actual' => formatNumber($counts['actual']), 'nominal' => formatNumber($counts['nominal'])])) . '</td></tr>';
    };
    $renderBoxInfoRow = function (string $labelKey, bool $value, ?string $notesLabelKey, ?string $notes) use (&$content): void {
        $content .= '<tr><th>' . htmlspecialchars(t($labelKey)) . '</th><td>' . htmlspecialchars($value ? t('owned_set_wizard_yes') : t('owned_set_wizard_no'));
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
    $renderActualNominalRow('set_detail_field_exclusive', 'exclusive', $ownedInventorySummary['exclusive']);
    $renderActualNominalRow('set_detail_field_rare', 'rare', $ownedInventorySummary['rare']);
    $renderActualNominalRow('set_detail_field_stickers', 'stickers', $ownedInventorySummary['stickers']);
    $renderActualNominalRow('owned_set_tab_minifigs', 'minifigs', $ownedInventorySummary['minifigs']);
    $renderBoxInfoRow('owned_set_has_instructions', (bool) $ownedSet['has_instructions'], 'owned_set_instructions_notes_label', $ownedSet['instructions_notes']);
    $renderBoxInfoRow('owned_set_has_box', (bool) $ownedSet['has_box'], 'owned_set_box_notes_label', $ownedSet['box_notes']);
    $renderBoxInfoRow('owned_set_box_complete', (bool) $ownedSet['box_complete'], 'owned_set_box_complete_notes_label', $ownedSet['box_complete_notes']);
    $renderBoxInfoRow('owned_set_stickers_applied', (bool) $ownedSet['stickers_applied'], 'owned_set_stickers_notes_label', $ownedSet['stickers_notes']);
    if ($ownedSet['notes'] !== null && $ownedSet['notes'] !== '') {
        $content .= '<tr><th>' . htmlspecialchars(t('owned_set_notes_label')) . '</th><td>' . htmlspecialchars($ownedSet['notes']) . '</td></tr>';
    }
    $content .= '</table>';
    $content .= '</div>';

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
    // Placeholder: matches missing/damaged parts against loose stock elsewhere
    // and lets the user queue them onto a pick list, eventually worked through
    // via a future mobile PWA that adjusts stock as items get picked — none of
    // that backend exists yet, this button is just the reserved spot + icon.
    $content .= '<button type="button" class="owned-set-action-pill" onclick="alert(' . json_encode(t('owned_set_pick_list_coming_soon'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ')" title="' . htmlspecialchars(t('owned_set_pick_list_label')) . '" aria-label="' . htmlspecialchars(t('owned_set_pick_list_label')) . '">' . getActionIcon('pick_list') . '</button>';
    $content .= '<button type="button" class="owned-set-action-pill" id="owned-set-sell-open" title="' . htmlspecialchars(t('owned_set_sell_heading')) . '" aria-label="' . htmlspecialchars(t('owned_set_sell_heading')) . '">' . getActionIcon('sell') . '</button>';
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
    $ownedSets = getAllOwnedSets($pdo);

    $content = '<h1>' . htmlspecialchars(t('nav_my_sets_all')) . '</h1>';
    if (empty($ownedSets)) {
        $content .= '<section class="card"><p>' . htmlspecialchars(t('my_sets_empty')) . '</p></section>';
    } else {
        $content .= '<div class="sets-grid">';
        foreach ($ownedSets as $owned) {
            $completeness = getOwnedSetCompleteness($pdo, $owned);
            $content .= renderOwnedSetCard($owned, $completeness['percent']);
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
        $owned = getOwnedSetsForThemes($pdo, [$themeParam]);
        if (!empty($owned)) {
            $content .= '<div class="sets-grid">';
            foreach ($owned as $inst) {
                $completeness = getOwnedSetCompleteness($pdo, $inst);
                $content .= renderOwnedSetCard($inst, $completeness['percent']);
            }
            $content .= '</div>';
        }
    }

    renderApp(t('nav_my_sets_themes'), $content, $user, computeAppStats($pdo), $myThemesBreadcrumbs);
    exit;
}
