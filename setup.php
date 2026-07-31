<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/src/config_writer.php';
require_once __DIR__ . '/src/i18n.php';
require_once __DIR__ . '/src/download.php';

// calculateFileProgressFraction()/buildImportProgressPayload() now live in
// src/download.php (required inside the import_tick handler below) — the
// settings page's "Update jetzt" modal (src/routes/actions.php) drives the
// same stepRebrickableImport() tick machine and shares them, instead of a
// third near-duplicate copy.

function render(string $title, string $content): void
{
    echo '<!DOCTYPE html><html lang="' . htmlspecialchars(getLocale()) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">';
    echo '<title>' . htmlspecialchars($title) . '</title>';
    echo '<link rel="icon" type="image/svg+xml" href="favicon.svg">';
    echo '<link rel="stylesheet" href="style.css">';
    echo '</head><body><div class="container">';
    echo '<header><div class="brand"><span class="brand-mark">' . file_get_contents(__DIR__ . '/logo.svg') . '</span><div><h1>' . htmlspecialchars(t('setup_title')) . '</h1><small>' . htmlspecialchars(t('setup_follow_steps')) . '</small></div></div></header>';
    echo $content;
    echo '<footer>StudSphere &copy; ' . date('Y') . '</footer>';
    echo '</div></body></html>';
}

function renderStepProgress(string $currentStep): string
{
    $steps = [
        '1' => t('database_access'),
        '2' => t('database_structure'),
        '3' => t('admin_account'),
        '4' => t('normal_user'),
        '5' => t('csv_import'),
        '6' => t('image_download'),
        '7' => t('remove_setup'),
    ];

    $current = (int) $currentStep;
    $html = '<section class="card stepper"><ol>';
    foreach ($steps as $key => $label) {
        $stepNumber = (int) $key;
        $class = 'step';
        if ($stepNumber === $current) {
            $class .= ' current';
        } elseif ($stepNumber < $current) {
            $class .= ' completed';
        }
        $html .= '<li class="' . $class . '"><span class="step-number">' . $key . '</span><strong>' . htmlspecialchars($label) . '</strong></li>';
    }
    $html .= '</ol></section>';
    return $html;
}

function renderLanguageSelector(string $selectedLocale): string
{
    $html = '<section class="card"><h2>' . htmlspecialchars(t('lang_title')) . '</h2><form method="post" class="language-select"><label>' . htmlspecialchars(t('lang_select')) . '<select name="locale">';
    foreach (getAvailableLocales() as $locale => $label) {
        $html .= '<option value="' . htmlspecialchars($locale) . '"' . ($locale === $selectedLocale ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
    }
    $html .= '</select></label><button type="submit">' . htmlspecialchars(t('lang_submit')) . '</button></form></section>';
    return $html;
}

function validateDbCredentials(string $host, string $user, string $pass, string $dbname): ?string
{
    if ($host === '' || $user === '' || $dbname === '') {
        return t('error_db_fields_required');
    }

    try {
        $pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        return t('error_db_connection_failed', ['message' => $e->getMessage()]);
    }

    try {
        $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->execute([$dbname]);
        $exists = (bool) $stmt->fetchColumn();
        if (!$exists) {
            $pdo->exec('CREATE DATABASE `' . str_replace('`', '``', $dbname) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
    } catch (Throwable $e) {
        return t('error_db_validation_failed', ['message' => $e->getMessage()]);
    }

    return null;
}

function writeSetupConfig(array $dbConfig): void
{
    writeConfig([
        'db' => $dbConfig,
        'base_url' => '/',
    ]);
}

function getSessionDbConfig(): array
{
    return $_SESSION['setup_db'] ?? [];
}

$setupLocale = $_SESSION['setup_locale'] ?? getLocale();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedLocale = trim($_POST['locale'] ?? '');
    if ($postedLocale !== '' && isLocaleAvailable($postedLocale)) {
        $setupLocale = $postedLocale;
        $_SESSION['setup_locale'] = $setupLocale;
        setSessionLocale($setupLocale);
    }
}

$step = $_GET['step'] ?? '1';
$error = '';
$success = '';
$importResults = [];

$sessionDb = getSessionDbConfig();
$dbHost = $sessionDb['host'] ?? '127.0.0.1';
$dbName = $sessionDb['dbname'] ?? 'studsphere';
$dbUser = $sessionDb['user'] ?? 'root';
$dbPass = '';
$adminUser = '';
$adminEmail = '';
$userName = '';
$userEmail = '';
$apiKey = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_tick') {
    header('Content-Type: application/json');
    $state = null;
    try {
        require_once __DIR__ . '/src/db.php';
        require_once __DIR__ . '/src/download.php';
        require_once __DIR__ . '/src/settings.php';

        $apiKeyPosted = trim($_POST['api_key'] ?? '');
        if ($apiKeyPosted !== '') {
            setAppSetting('rebrickable_api_key', $apiKeyPosted);
        }

        $state = $_SESSION['rebrickable_import_state'] ?? null;
        if (!is_array($state)) {
            $state = initRebrickableImportState();
        }

        $result = stepRebrickableImport($state);
        $_SESSION['rebrickable_import_state'] = $state;

        $payload = buildImportProgressPayload($state, $result['done']);

        if ($result['done']) {
            $summary = [];
            $errors = [];
            foreach ($state['files'] as $type => $file) {
                if ($file['stage'] === 'done') {
                    $summary[$type] = $file['rows'];
                } elseif ($file['stage'] === 'error') {
                    $errors[$type] = $file['message'] ?? 'Unbekannter Fehler';
                }
            }
            $_SESSION['setup_import'] = $summary;
            $_SESSION['setup_import_errors'] = $errors;
            unset($_SESSION['rebrickable_import_state']);
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if (function_exists('logRebrickableImport')) {
            logRebrickableImport(sprintf(
                'TICK-FEHLER (außerhalb des Datei-Handlings): [%s] %s (%s:%d)',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        // Keep whatever state exists so the next tick resumes instead of restarting
        // from file #1 — do NOT clear $_SESSION['rebrickable_import_state'] here.
        $payload = [
            'status' => 'error',
            'percent' => 0,
            'message' => t('import_error', ['message' => $e->getMessage()]),
            'files' => [],
        ];
        if (is_array($state) && function_exists('buildImportProgressPayload')) {
            $payload = buildImportProgressPayload($state, false);
            $payload['status'] = 'error';
            $payload['message'] = t('import_error', ['message' => $e->getMessage()]);
        }

        http_response_code(500);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'image_tick') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/src/db.php';
        require_once __DIR__ . '/src/images.php';

        $state = $_SESSION['image_download_state'] ?? null;
        if (!is_array($state)) {
            $forceRefresh = ($_POST['force_refresh'] ?? '') === '1';
            $state = initImageDownloadState($forceRefresh);
        }

        $result = stepImageDownload($state);
        $_SESSION['image_download_state'] = $state;

        $payload = buildImageProgressPayload($state, $result['done']);

        if ($result['done']) {
            unset($_SESSION['image_download_state']);
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'percent' => 0,
            'message' => t('import_error', ['message' => $e->getMessage()]),
            'tables' => [],
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $dbHost = trim($_POST['db_host'] ?? $dbHost);
    $dbName = trim($_POST['db_name'] ?? $dbName);
    $dbUser = trim($_POST['db_user'] ?? $dbUser);
    $dbPass = $_POST['db_pass'] ?? '';
    $adminUser = trim($_POST['admin_user'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $userName = trim($_POST['user_name'] ?? '');
    $userEmail = trim($_POST['user_email'] ?? '');
    $apiKey = trim($_POST['api_key'] ?? '');

    if ($action === 'validate_db') {
        $dbHost = trim($_POST['db_host'] ?? '127.0.0.1');
        $dbName = trim($_POST['db_name'] ?? 'studsphere');
        $dbUser = trim($_POST['db_user'] ?? 'root');
        $dbPass = trim($_POST['db_pass'] ?? '');

        $error = validateDbCredentials($dbHost, $dbUser, $dbPass, $dbName);
        if ($error === null) {
            $_SESSION['setup_db'] = [
                'host' => $dbHost,
                'dbname' => $dbName,
                'user' => $dbUser,
                'pass' => $dbPass,
                'charset' => 'utf8mb4',
            ];
            writeSetupConfig($_SESSION['setup_db']);
            header('Location: setup.php?step=2');
            exit;
        }
    }

    if ($action === 'install_structure') {
        try {
            require_once __DIR__ . '/src/db.php';
            require_once __DIR__ . '/src/setup.php';
            installDatabase();
            try {
                require_once __DIR__ . '/src/settings.php';
                if (isLocaleAvailable($setupLocale)) {
                    setAppSetting('locale', $setupLocale);
                }
            } catch (Throwable $ignored) {
                // ignore locale persistence if app_settings is not available
            }
            $_SESSION['setup_structure'] = true;
            header('Location: setup.php?step=3');
            exit;
        } catch (Throwable $e) {
            $error = t('error_structure_failed', ['message' => $e->getMessage()]);
        }
    }

    if ($action === 'create_admin') {
        $adminUser = trim($_POST['admin_user'] ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $adminPass = $_POST['admin_pass'] ?? '';
        $adminPassConfirm = $_POST['admin_pass_confirm'] ?? '';

        if ($adminUser === '' || $adminPass === '') {
            $error = t('error_admin_required');
        } elseif ($adminPass !== $adminPassConfirm) {
            $error = t('error_admin_password_mismatch');
        } else {
            try {
                require_once __DIR__ . '/src/db.php';
                $pdo = getPDO();
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, email, is_admin) VALUES (?, ?, ?, 1)');
                $stmt->execute([$adminUser, $hash, $adminEmail]);
                $_SESSION['setup_admin'] = true;
                header('Location: setup.php?step=4');
                exit;
            } catch (Throwable $e) {
                $error = t('error_admin_create_failed', ['message' => $e->getMessage()]);
            }
        }
    }

    if ($action === 'create_user') {
        if (isset($_POST['skip_user'])) {
            header('Location: setup.php?step=5');
            exit;
        }
        $userName = trim($_POST['user_name'] ?? '');
        $userEmail = trim($_POST['user_email'] ?? '');
        $userPass = $_POST['user_pass'] ?? '';
        $userPassConfirm = $_POST['user_pass_confirm'] ?? '';

        if ($userName === '' || $userPass === '') {
            $error = t('error_user_required');
        } elseif ($userPass !== $userPassConfirm) {
            $error = t('error_user_password_mismatch');
        } else {
            try {
                require_once __DIR__ . '/src/db.php';
                $pdo = getPDO();
                $hash = password_hash($userPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, email, is_admin) VALUES (?, ?, ?, 0)');
                $stmt->execute([$userName, $hash, $userEmail]);
                header('Location: setup.php?step=5');
                exit;
            } catch (Throwable $e) {
                $error = t('error_user_create_failed', ['message' => $e->getMessage()]);
            }
        }
    }

    if ($action === 'delete_setup') {
        try {
            if (unlink(__FILE__)) {
                $success = t('success_setup_removed');
            } else {
                $error = t('error_could_not_remove');
            }
        } catch (Throwable $e) {
            $error = t('error_delete_failed', ['message' => $e->getMessage()]);
        }
    }
}

if ($step !== '1' && !isset($_SESSION['setup_db'])) {
    header('Location: setup.php?step=1');
    exit;
}

if ($step === '3' && !isset($_SESSION['setup_structure'])) {
    header('Location: setup.php?step=2');
    exit;
}

if (in_array($step, ['4', '5', '6', '7'], true) && !isset($_SESSION['setup_admin'])) {
    header('Location: setup.php?step=3');
    exit;
}

$content = '';
if ($error !== '') {
    $content .= '<section class="card alert"><h2>' . htmlspecialchars(t('error_header')) . '</h2><p>' . htmlspecialchars($error) . '</p></section>';
}
if ($success !== '') {
    $content .= '<section class="card notice"><h2>' . htmlspecialchars(t('success_header')) . '</h2><p>' . htmlspecialchars($success) . '</p></section>';
}
$content = renderLanguageSelector($setupLocale) . renderStepProgress($step) . $content;

switch ($step) {
    case '1':
        $content .= '<section class="card"><h2>' . htmlspecialchars(t('database_access')) . '</h2><p>' . htmlspecialchars(t('db_help')) . '</p>';
        $content .= '<form method="post">';
        $content .= '<input type="hidden" name="action" value="validate_db">';
        $content .= '<label>' . htmlspecialchars(t('db_label_host')) . '<input name="db_host" value="' . htmlspecialchars($dbHost) . '"></label>';
        $content .= '<label>' . htmlspecialchars(t('db_label_name')) . '<input name="db_name" value="' . htmlspecialchars($dbName) . '"></label>';
        $content .= '<label>' . htmlspecialchars(t('db_label_user')) . '<input name="db_user" value="' . htmlspecialchars($dbUser) . '"></label>';
        $content .= '<label>' . htmlspecialchars(t('db_label_pass')) . '<input type="password" name="db_pass" value=""></label>';
        $content .= '<button type="submit">' . htmlspecialchars(t('db_button_validate')) . '</button>';
        $content .= '</form></section>';
        break;

    case '2':
        $content .= '<section class="card"><h2>' . htmlspecialchars(t('database_structure')) . '</h2><p>' . htmlspecialchars(t('structure_help')) . '</p>';
        $content .= '<form method="post"><input type="hidden" name="action" value="install_structure"><button type="submit">' . htmlspecialchars(t('structure_button_create')) . '</button></form></section>';
        break;

    case '3':
        $content .= '<section class="card"><h2>' . htmlspecialchars(t('admin_account')) . '</h2><p>' . htmlspecialchars(t('admin_help')) . '</p>';
        $content .= '<form method="post">';
        $content .= '<input type="hidden" name="action" value="create_admin">';
        $content .= '<label>' . htmlspecialchars(t('admin_username')) . '<input name="admin_user" value="' . htmlspecialchars($adminUser) . '"></label>';
        $content .= '<label>' . htmlspecialchars(t('admin_email')) . '<input type="email" name="admin_email" value="' . htmlspecialchars($adminEmail) . '"></label>';
        $content .= '<label>' . htmlspecialchars(t('admin_password')) . '<input type="password" name="admin_pass"></label>';
        $content .= '<label>' . htmlspecialchars(t('admin_password_confirm')) . '<input type="password" name="admin_pass_confirm"></label>';
        $content .= '<button type="submit">' . htmlspecialchars(t('admin_button_create')) . '</button>';
        $content .= '</form></section>';
        break;

    case '4':
        $content .= '<section class="card"><h2>' . htmlspecialchars(t('normal_user')) . '</h2><p>' . htmlspecialchars(t('user_help')) . '</p>';
        $content .= '<form method="post">';
        $content .= '<input type="hidden" name="action" value="create_user">';
        $content .= '<label>' . htmlspecialchars(t('user_username')) . '<input name="user_name" value="' . htmlspecialchars($userName) . '"></label>';
        $content .= '<label>' . htmlspecialchars(t('user_email')) . '<input type="email" name="user_email" value="' . htmlspecialchars($userEmail) . '"></label>';
        $content .= '<label>' . htmlspecialchars(t('user_password')) . '<input type="password" name="user_pass"></label>';
        $content .= '<label>' . htmlspecialchars(t('user_password_confirm')) . '<input type="password" name="user_pass_confirm"></label>';
        $content .= '<button type="submit">' . htmlspecialchars(t('user_button_create')) . '</button>';
        $content .= '<button type="submit" name="skip_user" value="1" style="margin-top:1rem;">' . htmlspecialchars(t('user_button_skip')) . '</button>';
        $content .= '</form></section>';
        break;

    case '5':
        $content .= '<section class="card"><h2>' . htmlspecialchars(t('csv_import')) . '</h2><p>' . htmlspecialchars(t('import_help')) . '</p>';
        $content .= '<form method="post" id="import-form">';
        $content .= '<input type="hidden" name="action" value="import_tick">';
        $content .= '<label>' . htmlspecialchars(t('import_api_key')) . '<input name="api_key" value="' . htmlspecialchars($apiKey) . '"></label>';
        // Derived from REBRICKABLE_DOWNLOAD_ORDER (src/download.php) instead
        // of a separately hardcoded list, so the two can't drift apart again
        // (that's exactly how set_parts.csv — since removed, Rebrickable's
        // CDN 404s on it — ended up listed here after it was already gone).
        $downloadFileNames = array_map(fn (string $type): string => $type . '.csv', REBRICKABLE_DOWNLOAD_ORDER);
        $content .= '<p>' . htmlspecialchars(t('import_files_list', ['files' => implode(', ', $downloadFileNames)])) . '</p>';
        $content .= '<p>' . htmlspecialchars(t('import_support')) . '</p>';
        $content .= '<div class="import-status" id="importStatus">';
        $content .= '<div class="progress-message idle" id="importMessage">' . htmlspecialchars(t('import_not_started')) . '</div>';
        $content .= '<div class="progress-track" id="importProgress"><div class="progress-fill"></div></div>';
        $content .= '<ul class="import-file-list" id="importFileList">';
        foreach ($downloadFileNames as $fileName) {
            $content .= '<li class="import-file import-file-pending"><span class="import-file-name">' . htmlspecialchars($fileName) . '</span><span class="import-file-status">' . htmlspecialchars(t('import_stage_pending')) . '</span></li>';
        }
        $content .= '</ul>';
        $content .= '</div>';
        $content .= '<button type="submit">' . htmlspecialchars(t('import_button')) . '</button>';
        $content .= '</form>';
        $labelsJson = json_encode([
            'import_running' => t('import_running'),
            'import_button' => t('import_button'),
            'import_resume_button' => t('import_resume_button'),
            'import_error_retry' => t('import_error_retry'),
            'import_started' => t('import_started'),
            'stage_pending' => t('import_stage_pending'),
            'stage_downloading' => t('import_stage_downloading'),
            'stage_extracting' => t('import_stage_extracting'),
            'stage_importing' => t('import_stage_importing'),
            'stage_done' => t('import_stage_done'),
            'stage_error' => t('import_stage_error_label'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $content .= <<<SCRIPT
<script>
(function(){
  var texts = $labelsJson;
  var stageLabels = {
    pending: texts.stage_pending,
    downloading: texts.stage_downloading,
    extracting: texts.stage_extracting,
    importing: texts.stage_importing,
    done: texts.stage_done,
    error: texts.stage_error
  };
  var form = document.getElementById("import-form");
  var track = document.getElementById("importProgress");
  var fill = track ? track.querySelector(".progress-fill") : null;
  var msg = document.getElementById("importMessage");
  var fileList = document.getElementById("importFileList");
  if (!form || !track || !fill || !msg || !fileList) {
    return;
  }

  function formatBytes(bytes) {
    if (bytes === null || bytes === undefined) {
      return null;
    }
    var units = ["B", "KB", "MB", "GB"];
    var value = bytes;
    var unitIndex = 0;
    while (value >= 1024 && unitIndex < units.length - 1) {
      value /= 1024;
      unitIndex++;
    }
    return value.toFixed(unitIndex === 0 ? 0 : 1) + " " + units[unitIndex];
  }

  function formatNumber(n) {
    if (n === null || n === undefined) {
      return null;
    }
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ".");
  }

  function renderFiles(files) {
    fileList.innerHTML = "";
    Object.keys(files).forEach(function(type) {
      var file = files[type];
      var li = document.createElement("li");
      li.className = "import-file import-file-" + file.stage;

      var name = document.createElement("span");
      name.className = "import-file-name";
      name.textContent = file.label;

      var status = document.createElement("span");
      status.className = "import-file-status";
      var text = stageLabels[file.stage] || file.stage;
      if (file.stage === "downloading") {
        if (file.totalBytes) {
          var pct = Math.round((file.bytes / file.totalBytes) * 100);
          text += " " + pct + "% (" + formatBytes(file.bytes) + " / " + formatBytes(file.totalBytes) + ")";
        } else if (file.bytes) {
          text += " (" + formatBytes(file.bytes) + ")";
        }
      }
      if (file.stage === "importing" && (file.rows || file.rows === 0)) {
        text += " (" + formatNumber(file.rows) + ")";
      }
      if (file.stage === "error" && file.message) {
        text += ": " + file.message;
      }
      if (file.stage === "done" && (file.rows || file.rows === 0)) {
        text += " (" + formatNumber(file.rows) + ")";
      }
      status.textContent = text;

      li.appendChild(name);
      li.appendChild(status);
      fileList.appendChild(li);
    });
  }

  function updateStatus(data) {
    msg.classList.remove("idle");
    msg.textContent = data.message || texts.import_running;
    fill.style.width = (data.percent || 0) + "%";
    renderFiles(data.files || {});
  }

  async function tick(formData) {
    var response = await fetch('setup.php?step=5', {
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
      submitButton.textContent = texts.import_running;
    }

    if (!hasStarted) {
      updateStatus({percent: 0, message: texts.import_started, files: {}});
      hasStarted = true;
    } else if (msg) {
      // Resuming after a pause: keep the existing file list visible, just
      // update the status text instead of wiping everything back to empty.
      msg.textContent = texts.import_running;
    }

    var baseFormData = new FormData(form);
    baseFormData.set('action', 'import_tick');

    function pauseWithMessage(message) {
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.textContent = texts.import_resume_button;
      }
      if (msg) {
        msg.textContent = message || texts.import_error_retry;
      }
    }

    function loop() {
      tick(baseFormData).then(function(data) {
        consecutiveFailures = 0;
        updateStatus(data);
        if (data.status === 'done') {
          window.location.href = 'setup.php?step=6';
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
            msg.textContent = texts.import_error_retry + ' (' + consecutiveFailures + '/' + maxAutoRetries + ')';
          }
          setTimeout(loop, 1000 * consecutiveFailures);
          return;
        }
        pauseWithMessage(texts.import_error_retry);
      });
    }

    loop();
  });
})();
</script>
SCRIPT;
        $content .= '</section>';
        break;

    case '6':
        $content .= '<section class="card"><h2>' . htmlspecialchars(t('image_download')) . '</h2><p>' . htmlspecialchars(t('image_download_help')) . '</p>';
        $content .= '<form method="post" id="image-form">';
        $content .= '<input type="hidden" name="action" value="image_tick">';
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
        $content .= '<p><a href="setup.php?step=7">' . htmlspecialchars(t('image_download_skip')) . '</a></p>';
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
        $content .= <<<SCRIPT
<script>
(function(){
  var texts = $imageLabelsJson;
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
    return String(n).replace(/\\B(?=(\\d{3})+(?!\\d))/g, ".");
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
    var response = await fetch('setup.php?step=6', {
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
          window.location.href = 'setup.php?step=7';
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
        $content .= '</section>';
        break;

    case '7':
        $content .= '<section class="card"><h2>' . htmlspecialchars(t('remove_setup')) . '</h2><p>' . htmlspecialchars(t('remove_help')) . '</p>';
        $content .= '<form method="post"><input type="hidden" name="action" value="delete_setup"><button type="submit">' . htmlspecialchars(t('remove_button')) . '</button></form></section>';
        if (!empty($_SESSION['setup_import'])) {
            $content .= '<section class="card"><h3>' . htmlspecialchars(t('import_results_title')) . '</h3><ul>';
            foreach ($_SESSION['setup_import'] as $type => $rows) {
                $content .= '<li>' . htmlspecialchars($type) . ': ' . htmlspecialchars(t('rows_imported', ['count' => (int) $rows])) . '</li>';
            }
            $content .= '</ul></section>';
        }
        if (!empty($_SESSION['setup_import_errors'])) {
            $content .= '<section class="card alert"><h3>' . htmlspecialchars(t('import_errors_title')) . '</h3><ul>';
            foreach ($_SESSION['setup_import_errors'] as $type => $errorMessage) {
                $content .= '<li>' . htmlspecialchars($type) . ': ' . htmlspecialchars($errorMessage) . '</li>';
            }
            $content .= '</ul></section>';
        }
        break;

    default:
        $content .= '<section class="card"><h2>' . htmlspecialchars(t('setup_title')) . '</h2><p>' . htmlspecialchars(t('error_unknown_step')) . '</p></section>';
}

render(t('setup_title'), $content);
