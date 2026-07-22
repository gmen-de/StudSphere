<?php

declare(strict_types=1);

require_once __DIR__ . '/src/config.php';
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/setup.php';
require_once __DIR__ . '/src/import.php';
require_once __DIR__ . '/src/download.php';
require_once __DIR__ . '/src/settings.php';

$config = require __DIR__ . '/src/config.php';
if (empty($config['db']['dbname']) || empty($config['db']['user']) || !canConnectToServer()) {
    header('Location: setup.php');
    exit;
}

session_start();

try {
    if (!isInstalled()) {
        installDatabase();
        try {
            $summary = downloadAndImportRebrickableData();
            $message = 'Setup abgeschlossen. Daten wurden importiert: ' . implode(', ', array_map(function ($type, $rows) { return "$type=$rows"; }, array_keys($summary), $summary));
        } catch (Throwable $e) {
            $message = 'Setup abgeschlossen. Import fehlgeschlagen: ' . $e->getMessage();
        }
        render('Setup abgeschlossen', '<section class="card"><h2>Setup abgeschlossen</h2><p>' . htmlspecialchars($message) . '</p><p>Bitte laden Sie die Seite neu.</p></section>');
        exit;
    }
} catch (Throwable $e) {
    render('Setup-Fehler', '<section class="card alert"><h2>Setup-Fehler</h2><p>Fehler beim Erstellen der Datenbankstruktur: ' . htmlspecialchars($e->getMessage()) . '</p></section>');
    exit;
}

$pdo = getPDO();

function render(string $title, string $content): void
{
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">';
    echo '<title>' . htmlspecialchars($title) . '</title>';
    echo '<link rel="stylesheet" href="style.css">';
    echo '</head><body>';
    echo '<div class="container">';
    echo '<header><div class="brand"><div><h1>StudSphere</h1><small>Lokale Klemmbaustein-Verwaltung</small></div></div></header>';
    echo $content;
    echo '<footer>StudSphere &copy; ' . date('Y') . '</footer>';
    echo '</div></body></html>';
}

if (isset($_POST['action']) && $_POST['action'] === 'register') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = trim($_POST['email'] ?? '');

    if ($username !== '' && $password !== '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, email) VALUES (?, ?, ?)');
        $stmt->execute([$username, $hash, $email]);
        render('Registrierung erfolgreich', '<section class="card notice"><h2>Registrierung erfolgreich</h2><p>Bitte melden Sie sich jetzt an.</p></section>');
        exit;
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    render('Login fehlgeschlagen', '<section class="card alert"><h2>Login fehlgeschlagen</h2><p>Überprüfen Sie Benutzername und Passwort und versuchen Sie es erneut.</p></section>');
    exit;
}

$importMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    try {
        $type = $_POST['import_type'] ?? '';
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Bitte wählen Sie eine gültige Datei zum Importieren aus.');
        }
        $result = importCsv($_FILES['import_file']['tmp_name'], $type);
        $importMessage = sprintf('Import abgeschlossen: %d Zeilen importiert.', $result['rows'] ?? 0);
    } catch (Throwable $e) {
        $importMessage = 'Importfehler: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_data') {
    try {
        $summary = downloadAndImportRebrickableData();
        $importMessage = 'Aktualisierung abgeschlossen: ' . implode(', ', array_map(function ($type, $rows) { return "$type=$rows"; }, array_keys($summary), $summary));
    } catch (Throwable $e) {
        $importMessage = 'Aktualisierungsfehler: ' . $e->getMessage();
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    $content = '<section class="card"><h2>Anmeldung</h2><form method="post"><input type="hidden" name="action" value="login"><label>Benutzername<input name="username" autocomplete="username"></label><label>Passwort<input type="password" name="password" autocomplete="current-password"></label><button type="submit">Login</button></form></section>';
    $content .= '<section class="card"><h2>Registrierung</h2><form method="post"><input type="hidden" name="action" value="register"><label>Benutzername<input name="username" autocomplete="username"></label><label>E-Mail<input type="email" name="email" autocomplete="email"></label><label>Passwort<input type="password" name="password" autocomplete="new-password"></label><button type="submit">Registrieren</button></form></section>';
    render('Login / Registrierung', $content);
    exit;
}

$stmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (isset($_GET['page']) && $_GET['page'] === 'settings') {
    $content = '<h1>Einstellungen</h1>';
    $content .= '<p><a href="' . $_SERVER['PHP_SELF'] . '">Zurück zur Übersicht</a></p>';
    if ($importMessage !== '') {
        $content .= '<p><strong>' . htmlspecialchars($importMessage) . '</strong></p>';
    }
    $content .= '<form method="post"><input type="hidden" name="action" value="update_data"><button type="submit">Rebrickable-Daten automatisch aktualisieren</button></form>';
    $content .= '<p>Diese Aktion lädt die aktuellen Rebrickable-Download-Dateien herunter und importiert sie automatisch.</p>';
    $content .= '<h2>Letzte Aktualisierungen</h2>';
    $content .= '<ul>';
    $content .= '<li>Letzte Gesamt-Aktualisierung: ' . htmlspecialchars(getAppSetting('last_update_all', 'nicht vorhanden')) . '</li>';
    $content .= '<li>Letzte Teile-Aktualisierung: ' . htmlspecialchars(getAppSetting('last_update_parts', 'nicht vorhanden')) . '</li>';
    $content .= '<li>Letzte Sets-Aktualisierung: ' . htmlspecialchars(getAppSetting('last_update_sets', 'nicht vorhanden')) . '</li>';
    $content .= '<li>Letzte Set-Teile-Aktualisierung: ' . htmlspecialchars(getAppSetting('last_update_set_parts', 'nicht vorhanden')) . '</li>';
    $content .= '</ul>';
    render('Einstellungen', $content);
    exit;
}

$content = '<h1>Willkommen, ' . htmlspecialchars($user['username']) . '</h1>';
$content .= '<p><a href="?action=logout">Abmelden</a> | <a href="?page=settings">Einstellungen</a></p>';
$content .= '<p>Hier können Sie jetzt Rebrickable-Download-Dateien importieren oder im Bereich Einstellungen eine automatische Datenaktualisierung starten.</p>';
if ($importMessage !== '') {
    $content .= '<p><strong>' . htmlspecialchars($importMessage) . '</strong></p>';
}
$content .= '<form method="post" enctype="multipart/form-data">'
    . '<input type="hidden" name="action" value="import">'
    . '<label>Datei auswählen: <input type="file" name="import_file" accept=".csv"></label>'
    . '<label>Importtyp: <select name="import_type">'
    . '<option value="parts">Teile</option>'
    . '<option value="sets">Sets</option>'
    . '<option value="set_parts">Set-Teile</option>'
    . '</select></label>'
    . '<button type="submit">Import starten</button>'
    . '</form>';
$content .= '<p>Verwenden Sie die CSV-Dateien von <a href="https://rebrickable.com/downloads/" target="_blank">Rebrickable Downloads</a>.</p>';
    render('Dashboard', $content);
