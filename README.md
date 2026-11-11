# GHOST NODE – persönliches Dashboard

PHP-Dashboard ohne Datenbank (Daten liegen als JSON-Dateien neben den Seiten).

| Datei | Inhalt |
|---|---|
| `index.php` | Startseite: listet alle Seiten automatisch, Websuche + lokale Suche über **alle** Hub-Links |
| `1linkseite.php`, `2VRchat.php`, `3hueter-Zentrale.php`, `furry.php` | Link-Hubs mit Live-Suche |
| `budget.php` | Monatsbudget mit Fixkosten-/Abo-Vorlagen, CSV-Import (z. B. Revolut) und Archiv |
| `con.php` | Con-Budget & Dokumentation mit Archiv |
| `feed.php` | RSS/Atom-Feed-Sammler mit Cache (15 Min.) und Countdown |
| `countdown.php` | Beliebig viele Countdowns (auch jährlich wiederkehrend), Haupt-Countdown für Feed & Startseite, Kalender-Export (.ics) |
| `linkcheck.php` | Prüft alle Hub-Links auf Erreichbarkeit; kaputte Links bekommen in den Hubs einen ⚠-Hinweis |
| `backup.php` | Lädt alle persönlichen Daten (Budget, Con, Countdowns, Archive) als ZIP herunter |
| `inc/common.php` | Gemeinsame Hilfsfunktionen (JSON laden/speichern, Beträge, Escaping, Zeitzone) |
| `inc/countdown.php`, `inc/linkcheck.php` | Logik für Countdowns und Link-Check |
| `assets/hub-search.js` | Gemeinsame Suche für die Link-Hubs |
| `assets/hud.css` | Gemeinsamer HUD-Look für die neuen Seiten |

## Installation

Alle Dateien und Ordner (inkl. `inc/`, `assets/` und `.htaccess`) auf einen Webserver mit PHP ≥ 7.4 kopieren.
Für Feed und Link-Check wird die PHP-Erweiterung cURL benötigt (bei fast allen Hostern aktiv).
Der Webserver braucht Schreibrechte im Ordner, damit die JSON-Dateien und Archive angelegt werden können.

Die Datendateien (`*_data.json`, `feed_cache.json`, `linkcheck_cache.json`, `archive*/`) sind per `.gitignore` vom Repository ausgeschlossen
und werden beim ersten Aufruf automatisch erzeugt.

## Neuen Link-Hub anlegen

Eine bestehende Hub-Datei kopieren (z. B. `furry.php` → `4neuer-hub.php`) und das `$categories`-Array anpassen.
Die Zeile `if (defined('LINKS_ONLY')) return $categories;` muss erhalten bleiben – dann erscheint der Hub
automatisch auf der Startseite und alle seine Links sind über die Startseiten-Suche auffindbar.
Die Zahl am Dateianfang bestimmt die Reihenfolge auf der Startseite.

## Hinweis zur Sicherheit

Die Seiten haben **keinen Login**. Wenn das Dashboard öffentlich im Internet erreichbar ist, kann jeder die
Budget-/Con-Daten sehen und ändern. Am einfachsten per `.htaccess`/`.htpasswd` (Apache) oder `auth_basic` (nginx) schützen.
