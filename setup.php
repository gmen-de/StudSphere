<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/src/config_writer.php';

function render(string $title, string $content): void
{
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">';
    echo '<title>' . htmlspecialchars($title) . '</title>';
    echo '<link rel="stylesheet" href="style.css">';
    echo '</head><body><div class="container">';
    echo '<header><div class="brand"><div><h1>StudSphere Setup</h1><small>Bitte folgen Sie den Einrichtungsschritten.</small></div></div></header>';
    echo $content;
    echo '<footer>StudSphere &copy; ' . date('Y') . '</footer>';
    echo '</div></body></html>';
}

function renderStepProgress(string $currentStep): string
{
    $steps = [
        '1' => 'DB-Zugang',
        '2' => 'Datenbankstruktur',
        '3' => 'Admin-Konto',
        '4' => 'Normaler Benutzer',
        '5' => 'CSV-Import',
        '6' => 'Setup entfernen',
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

function validateDbCredentials(string $host, string $user, string $pass, string $dbname): ?string
{
    if ($host === '' || $user === '' || $dbname === '') {
        return 'Host, Datenbankname und Benutzer müssen ausgefüllt sein.';
    }

    try {
        $pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        return 'Verbindung zum Datenbankserver fehlgeschlagen: ' . $e->getMessage();
    }

    try {
        $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->execute([$dbname]);
        $exists = (bool) $stmt->fetchColumn();
        if (!$exists) {
            $pdo->exec('CREATE DATABASE `' . str_replace('`', '``', $dbname) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
    } catch (Throwable $e) {
        return 'Datenbankprüfung/Erstellung fehlgeschlagen: ' . $e->getMessage();
    }

    return null;
}

function writeSetupConfig(array $dbConfig, string $apiKey = ''): void
{
    writeConfig([
        'db' => $dbConfig,
        'base_url' => '/',
        'rebrickable' => [
            'api_key' => $apiKey,
            'api_url' => 'https://rebrickable.com/api/v3/',
            'download_page' => 'https://rebrickable.com/downloads/',
        ],
    ]);
}

function getSessionDbConfig(): array
{
    return $_SESSION['setup_db'] ?? [];
}

$step = $_GET['step'] ?? '1';
$error = '';
$success = '';
$importResults = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

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
            $_SESSION['setup_structure'] = true;
            header('Location: setup.php?step=3');
            exit;
        } catch (Throwable $e) {
            $error = 'Die Datenbankstruktur konnte nicht angelegt werden: ' . $e->getMessage();
        }
    }

    if ($action === 'create_admin') {
        $adminUser = trim($_POST['admin_user'] ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $adminPass = $_POST['admin_pass'] ?? '';
        $adminPassConfirm = $_POST['admin_pass_confirm'] ?? '';

        if ($adminUser === '' || $adminPass === '') {
            $error = 'Admin-Benutzername und Passwort sind erforderlich.';
        } elseif ($adminPass !== $adminPassConfirm) {
            $error = 'Die Admin-Passwörter stimmen nicht überein.';
        } else {
            try {
                require_once __DIR__ . '/src/db.php';
                $pdo = getPDO();
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, email) VALUES (?, ?, ?)');
                $stmt->execute([$adminUser, $hash, $adminEmail]);
                $_SESSION['setup_admin'] = true;
                header('Location: setup.php?step=4');
                exit;
            } catch (Throwable $e) {
                $error = 'Admin-Konto konnte nicht angelegt werden: ' . $e->getMessage();
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
            $error = 'Benutzername und Passwort sind erforderlich.';
        } elseif ($userPass !== $userPassConfirm) {
            $error = 'Die Passwörter stimmen nicht überein.';
        } else {
            try {
                require_once __DIR__ . '/src/db.php';
                $pdo = getPDO();
                $hash = password_hash($userPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, email) VALUES (?, ?, ?)');
                $stmt->execute([$userName, $hash, $userEmail]);
                header('Location: setup.php?step=5');
                exit;
            } catch (Throwable $e) {
                $error = 'Benutzer konnte nicht angelegt werden: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'import_files') {
        try {
            require_once __DIR__ . '/src/config.php';
            require_once __DIR__ . '/src/db.php';
            require_once __DIR__ . '/src/import.php';
            if (!empty($_POST['api_key'])) {
                $dbConfig = getSessionDbConfig();
                writeSetupConfig($dbConfig, trim($_POST['api_key']));
            }

            foreach (['parts', 'sets', 'set_parts'] as $type) {
                if (!empty($_FILES[$type]['tmp_name']) && $_FILES[$type]['error'] === UPLOAD_ERR_OK) {
                    $result = importCsv($_FILES[$type]['tmp_name'], $type);
                    $importResults[$type] = $result['rows'] ?? 0;
                }
            }
            $_SESSION['setup_import'] = $importResults;
            header('Location: setup.php?step=6');
            exit;
        } catch (Throwable $e) {
            $error = 'Import fehlgeschlagen: ' . $e->getMessage();
        }
    }

    if ($action === 'delete_setup') {
        try {
            if (unlink(__FILE__)) {
                $success = 'Die Setup-Datei wurde entfernt. Ihre Anwendung ist jetzt sicherer.';
            } else {
                $error = 'Die Setup-Datei konnte nicht entfernt werden.';
            }
        } catch (Throwable $e) {
            $error = 'Die Setup-Datei konnte nicht entfernt werden: ' . $e->getMessage();
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

if (in_array($step, ['4', '5', '6'], true) && !isset($_SESSION['setup_admin'])) {
    header('Location: setup.php?step=3');
    exit;
}

$content = '';
if ($error !== '') {
    $content .= '<section class="card alert"><h2>Fehler</h2><p>' . htmlspecialchars($error) . '</p></section>';
}
if ($success !== '') {
    $content .= '<section class="card notice"><h2>Erfolgreich</h2><p>' . htmlspecialchars($success) . '</p></section>';
}
$content = renderStepProgress($step) . $content;

switch ($step) {
    case '1':
        $content .= '<section class="card"><h2>Schritt 1: Datenbank-Zugangsdaten</h2><p>Geben Sie Ihre MariaDB-Zugangsdaten ein. Die Verbindung wird geprüft und die Datenbank bei Bedarf angelegt.</p>';
        $content .= '<form method="post">';
        $content .= '<input type="hidden" name="action" value="validate_db">';
        $content .= '<label>DB Host<input name="db_host" value="127.0.0.1"></label>';
        $content .= '<label>DB Name<input name="db_name" value="studsphere"></label>';
        $content .= '<label>DB Benutzer<input name="db_user" value="root"></label>';
        $content .= '<label>DB Passwort<input type="password" name="db_pass" value=""></label>';
        $content .= '<button type="submit">Zugangsdaten prüfen</button>';
        $content .= '</form></section>';
        break;

    case '2':
        $content .= '<section class="card"><h2>Schritt 2: Einrichtung der Datenbankstruktur</h2><p>Die Tabellen werden jetzt in der konfigurierten Datenbank angelegt.</p>';
        $content .= '<form method="post"><input type="hidden" name="action" value="install_structure"><button type="submit">Datenbankstruktur anlegen</button></form></section>';
        break;

    case '3':
        $content .= '<section class="card"><h2>Schritt 3: Administrator einrichten</h2><p>Legen Sie hier den ersten Administrator für die Anwendung an.</p>';
        $content .= '<form method="post">';
        $content .= '<input type="hidden" name="action" value="create_admin">';
        $content .= '<label>Admin-Benutzername<input name="admin_user"></label>';
        $content .= '<label>Admin-E-Mail<input type="email" name="admin_email"></label>';
        $content .= '<label>Admin-Passwort<input type="password" name="admin_pass"></label>';
        $content .= '<label>Admin-Passwort wiederholen<input type="password" name="admin_pass_confirm"></label>';
        $content .= '<button type="submit">Admin anlegen</button>';
        $content .= '</form></section>';
        break;

    case '4':
        $content .= '<section class="card"><h2>Schritt 4: Normaler Benutzer</h2><p>Sie können optional einen normalen Benutzer anlegen oder diesen Schritt überspringen.</p>';
        $content .= '<form method="post">';
        $content .= '<input type="hidden" name="action" value="create_user">';
        $content .= '<label>Benutzername<input name="user_name"></label>';
        $content .= '<label>Benutzer-E-Mail<input type="email" name="user_email"></label>';
        $content .= '<label>Passwort<input type="password" name="user_pass"></label>';
        $content .= '<label>Passwort wiederholen<input type="password" name="user_pass_confirm"></label>';
        $content .= '<button type="submit">Benutzer anlegen</button>';
        $content .= '<button type="submit" name="skip_user" value="1" style="margin-top:1rem;">Überspringen</button>';
        $content .= '</form></section>';
        break;

    case '5':
        $content .= '<section class="card"><h2>Schritt 5: Bricklink CSV-Dateien importieren</h2><p>Laden Sie die Dateien für Teile, Sets und Set-Teile hoch. Jede Datei wird einzeln verarbeitet.</p>';
        $content .= '<form method="post" enctype="multipart/form-data">';
        $content .= '<input type="hidden" name="action" value="import_files">';
        $content .= '<label>Teile (parts.csv)<input type="file" name="parts" accept=".csv"></label>';
        $content .= '<label>Sets (sets.csv)<input type="file" name="sets" accept=".csv"></label>';
        $content .= '<label>Set-Teile (set_parts.csv)<input type="file" name="set_parts" accept=".csv"></label>';
        $content .= '<label>Rebrickable API-Key (optional)<input name="api_key" value=""></label>';
        $content .= '<button type="submit">Import starten</button>';
        $content .= '</form></section>';
        break;

    case '6':
        $content .= '<section class="card"><h2>Schritt 6: Setup-Datei entfernen</h2><p>Zum Abschluss entfernen wir die Setup-Datei aus Sicherheitsgründen.</p>';
        $content .= '<form method="post"><input type="hidden" name="action" value="delete_setup"><button type="submit">Setup-Datei entfernen</button></form></section>';
        if (!empty($_SESSION['setup_import'])) {
            $content .= '<section class="card"><h3>Import-Ergebnisse</h3><ul>';
            foreach ($_SESSION['setup_import'] as $type => $rows) {
                $content .= '<li>' . htmlspecialchars($type) . ': ' . (int) $rows . ' Zeilen importiert</li>';
            }
            $content .= '</ul></section>';
        }
        break;

    default:
        $content .= '<section class="card"><h2>Setup starten</h2><p>Unbekannter Setup-Schritt.</p></section>';
}

render('Setup', $content);
