<?php

declare(strict_types=1);

require_once __DIR__ . '/setup.php';
require_once __DIR__ . '/db.php';

try {
    ensureDatabaseExists();
    installDatabase();
    echo "Datenbank und Tabellen wurden erstellt.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Fehler beim Erstellen der Datenbank: " . $e->getMessage() . "\n");
    exit(1);
}
