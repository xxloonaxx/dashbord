# GHOST NODE – persönliches Dashboard

PHP-Dashboard ohne Datenbank (Daten liegen als JSON-Dateien neben den Seiten).

| Datei | Inhalt |
|---|---|
| `index.php` | Startseite: listet alle Seiten automatisch, Websuche + lokale Suche über **alle** Hub-Links |
| `1linkseite.php`, `2VRchat.php`, `3hueter-Zentrale.php`, `furry.php` | Link-Hubs mit Live-Suche |
| `budget.php` | Monatsbudget mit Fixkosten-/Abo-Vorlagen, CSV-Import (z. B. Revolut) und Archiv |
| `con.php` | Con-Budget & Dokumentation mit Archiv |
| `feed.php` | RSS/Atom-Feed-Sammler mit Cache (15 Min.) und Countdown |
| `inc/common.php` | Gemeinsame Hilfsfunktionen (JSON laden/speichern, Beträge, Escaping) |
| `assets/hub-search.js` | Gemeinsame Suche für die Link-Hubs |

## Installation

Alle Dateien und Ordner (inkl. `inc/` und `assets/`) auf einen Webserver mit PHP ≥ 7.4 kopieren.
Der Webserver braucht Schreibrechte im Ordner, damit die JSON-Dateien und Archive angelegt werden können.

Die Datendateien (`*_data.json`, `feed_cache.json`, `archive*/`) sind per `.gitignore` vom Repository ausgeschlossen
und werden beim ersten Aufruf automatisch erzeugt.

## Neuen Link-Hub anlegen

Eine bestehende Hub-Datei kopieren (z. B. `furry.php` → `4neuer-hub.php`) und das `$categories`-Array anpassen.
Die Zeile `if (defined('LINKS_ONLY')) return $categories;` muss erhalten bleiben – dann erscheint der Hub
automatisch auf der Startseite und alle seine Links sind über die Startseiten-Suche auffindbar.
Die Zahl am Dateianfang bestimmt die Reihenfolge auf der Startseite.

## Hinweis zur Sicherheit

Die Seiten haben **keinen Login**. Wenn das Dashboard öffentlich im Internet erreichbar ist, kann jeder die
Budget-/Con-Daten sehen und ändern. Am einfachsten per `.htaccess`/`.htpasswd` (Apache) oder `auth_basic` (nginx) schützen.
