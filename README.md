# StudSphere

Lokale Datenbank für Klemmbausteine und Sets mit PHP und MariaDB 10.

## Einrichtung

1. Kopieren Sie die Projektdateien in Ihren Webserver-Ordner und verwenden Sie den Root-Ordner als Webroot.
2. Passen Sie `src/config.php` an Ihre MariaDB-Verbindung an.
3. Öffnen Sie im Browser `index.php`.
4. Beim ersten Aufruf richtet das System die Datenbanktabellen ein.

## Funktionen

- Benutzerregistrierung und Login
- Datenbank-Setup bei erster Verwendung
- Grundstruktur für Sets, Teile und Inventar
- Vorbereitung für Rebrickable-Import

## Weiteres

- API-Key in `src/config.php` eintragen
- Rebrickable-Import und UI können später ergänzt werden
