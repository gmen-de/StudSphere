# StudSphere

Lokale Datenbank für Klemmbausteine und Sets mit PHP und MariaDB 10.

## Einrichtung

1. Kopieren Sie die Projektdateien in Ihren Webserver-Ordner und verwenden Sie den Root-Ordner als Webroot.
2. Öffnen Sie im Browser `index.php`. Ohne gültige DB-Konfiguration leitet die Anwendung automatisch zu `setup.php` weiter.
3. Der Setup-Assistent führt Sie durch 7 Schritte: DB-Zugangsdaten prüfen, Datenbankstruktur anlegen, Admin-Konto erstellen, optional einen normalen Benutzer anlegen, Rebrickable-Daten herunterladen und importieren (Rebrickable-API-Key optional hier eintragen), Bilder für Sets/Minifigs/Teile herunterladen (überspringbar), Setup-Datei entfernen.
4. Danach ist die Anwendung unter `index.php` einsatzbereit.

## Funktionen

- Benutzerregistrierung und Login
- Geführter Setup-Assistent (DB, Admin, Benutzer, Datenimport, Bilder) bei erster Verwendung
- Vollständiges Rebrickable-Datenmodell (Themes, Farben, Teile, Sets, Elemente, Minifigs, Inventare, …)
- Automatischer Download und Import der aktuellen Rebrickable-CSV-Dumps (GZIP/ZIP) mit Fortschrittsanzeige, in kleine Anfragen zerlegt (siehe „Shared Hosting" unten)
- Automatischer Bilder-Download für Sets, Minifigs und Teile nach `public/images/`, mit Deduplizierung über den Dateinamen und Option zum erzwungenen Neu-Download bereits vorhandener Bilder — sowohl im Setup-Assistenten als auch später über die Einstellungen
- Manueller CSV-Import einzelner Dateien über das Dashboard
- Einstellungsseite mit Zeitstempeln der letzten Datenaktualisierung und Rebrickable-Verbindungseinstellungen
- Mehrsprachigkeit (Deutsch/Englisch) über `src/i18n.php` und `lang/*.php`

## Shared Hosting

StudSphere ist so gebaut, dass es auch auf Hostern ohne Zugriff auf die PHP-Konfiguration (z. B. 1&1/IONOS, Strato) läuft:

- Der Rebrickable-Import und der Bilder-Download laufen über eine im Browser laufende Tick-Schleife: jeder einzelne HTTP-Request erledigt nur ein kleines, zeitlich begrenztes Stück Arbeit (ein Downloadblock, eine Batch-Import-Runde, ein paar Bilder), sodass kein einzelner Request an ein serverseitiges Zeitlimit stößt. Der Fortschritt wird serverseitig in der PHP-Session gehalten, sodass ein Neuladen der Seite den Vorgang fortsetzt statt neu zu starten.
- Heruntergeladene CSV-Dateien und das Import-Logfile landen in `storage/rebrickable/` (durch `.htaccess` vor direktem Web-Zugriff geschützt), nicht im System-Temp-Verzeichnis, da dieses auf manchen Hostern zwischen Anfragen nicht persistent ist.
- Bilder landen in `public/images/{tabelle}/{shard}/dateiname.jpg` (bewusst innerhalb des Webroots, damit sie im Browser angezeigt werden können). Der zweistellige `shard`-Ordner (erste 2 Hex-Zeichen aus dem MD5-Hash des Dateinamens, 256 mögliche Ordner) verhindert, dass ein einzelnes Verzeichnis bei Tabellen wie `inventory_parts` auf zehntausende Dateien anwächst — viele Shared-Hosting-Dateisysteme werden bei so großen Einzelverzeichnissen spürbar langsam oder haben harte Limits.
- Wurden vor Einführung des Shardings bereits Bilder flach heruntergeladen, sortiert `php src/migrate_image_shards.php` (einmalig, per CLI) sie verschiebend in die Shard-Struktur um und aktualisiert die Datenbankpfade, ohne alles erneut von Rebrickable laden zu müssen. Idempotent — mehrfaches Ausführen ist unbedenklich.

## Weiteres

- Der Rebrickable-API-Key wird über den Setup-Assistenten oder die Einstellungen gesetzt und in der Tabelle `app_settings` gespeichert (nicht in `src/config.php`)
- `src/config.php` enthält ausschließlich die MariaDB-Verbindungsdaten. Die Datei ist bewusst **nicht** in Git enthalten (siehe `.gitignore`) und wird vom Setup-Assistenten beim ersten Durchlauf erzeugt — `src/config.example.php` ist die getrackte Vorlage. Dadurch überschreibt ein `git pull`/Überkopieren neuer Projektdateien nie die echten Zugangsdaten.

## Datenbankschema

Die Tabellen werden zur Laufzeit von `installDatabase()` in `src/setup.php` angelegt (Single Source of Truth).
`db/schema.sql` dokumentiert denselben Stand zum Nachlesen/Referenzieren außerhalb von PHP — bei Schemaänderungen bitte beide Stellen synchron halten.
