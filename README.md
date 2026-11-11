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

Die Datendateien (`*_data.json`, `links_*.json`, `feed_sources.json`, `feed_cache.json`, `linkcheck_cache.json`, `archive*/`) sind per `.gitignore` vom Repository ausgeschlossen
und werden beim ersten Aufruf automatisch erzeugt.

## Admin-Login & Schutz

Beim **allerersten Aufruf** der Seite erscheint die Einrichtung: Benutzername und Passwort festlegen.
Wer das zuerst ausfüllt, wird Admin – also direkt nach dem Hochladen erledigen.

- **Budget, Con-Rechner und Backup** sind nur angemeldet sichtbar.
- **Alle Änderungen** (Links, Kategorien, Countdowns, Feed-Quellen, Link-Check starten …) gehen nur angemeldet.
  Besucher sehen keine Bearbeiten-Knöpfe; abgeschickte Formulare ohne Login werden abgelehnt.
- **NSFW-Schalter**: auf der Startseite (Admin-Leiste) und in `admin.php`. Ist er aus, sehen Besucher keine
  NSFW-Links (markiert per Haken „NSFW / 18+“ oder in Kategorien mit „NSFW“/„Adult“ im Namen). Admins sehen sie immer mit 18+-Markierung.
- **Admin-Zentrale** (`admin.php`): Übersicht, NSFW-Schalter, Passwort/Benutzername ändern, alle anderen Geräte abmelden,
  Hub zurücksetzen, Feed neu laden, Systemstatus und Zugriffsprotokoll.
- Nach 5 falschen Passwörtern wird die IP für 10 Minuten gesperrt.
- Die Zugangsdaten liegen als Hash (bcrypt) in `.admin_auth.php` – die Datei gibt bei direktem Aufruf nichts preis.

**Passwort vergessen?** Per FTP die Datei `.admin_auth.php` löschen – beim nächsten Aufruf erscheint wieder die Einrichtung.

Unbedingt **HTTPS** beim Hoster aktivieren, sonst wird das Passwort unverschlüsselt übertragen (die Admin-Zentrale warnt dann).

## Bank-Sync (Revolut über Open Banking)

Im Budget unter **🏦 Bank-Sync**. Revolut gibt seine Open-Banking-Schnittstelle nur an lizenzierte Zahlungsdienstleister
heraus, für Privatkonten gibt es keine eigene Revolut-API. Die Anbindung läuft daher über **Enable Banking**, einen
lizenzierten Kontoinformationsdienst, der für die private Nutzung mit den eigenen Konten kostenlos ist (nur Lesezugriff).

1. Auf enablebanking.com anmelden, unter *API applications* eine **Production**-App anlegen.
2. Als Redirect-URL die Adresse eintragen, die der Bank-Sync-Reiter anzeigt (`https://…/budget.php`).
3. Die heruntergeladene `.pem`-Datei und die Application-ID im Bank-Sync hinterlegen.
4. In Enable Banking „Activate by linking accounts“ mit dem eigenen Revolut-Konto ausführen.
5. Im Bank-Sync Land + „Revolut“ wählen und verbinden – Freigabe in der Revolut-App bestätigen.

Danach werden die gebuchten Umsätze des Budget-Monats übernommen: ohne Duplikate (auch gegen CSV-Importe),
ohne interne Umbuchungen (Pockets, Tresore, Währungstausch), Fixkosten/Abos werden mit den Vorlagen abgeglichen.
Automatischer Abgleich beim Öffnen des Budgets höchstens alle 6 Std. Die Freigabe muss laut PSD2 spätestens nach
180 Tagen erneuert werden. Zugangsdaten liegen geschützt in `.openbanking.php`.

## Links & Kategorien im Browser bearbeiten

Jeder Link-Hub hat oben die Knöpfe **＋ Link hinzufügen** und **＋ Kategorie** (Tastenkürzel: `n` = neuer Link).
Beim Überfahren einer Karte bzw. Kategorie erscheinen außerdem ✎ (bearbeiten), ✕ (löschen) und ▲▼ (Kategorie verschieben).

Die Links im `$categories`-Array der Hub-Datei sind nur der **Startbestand**. Sobald im Browser etwas geändert wird,
speichert der Hub seinen kompletten Stand in `links_<hubname>.json` – diese Datei hat danach Vorrang.
Beim Hochladen einer neuen Version der Webseite bleiben die eigenen Änderungen dadurch erhalten.
Um einen Hub auf den Startbestand zurückzusetzen, einfach die passende `links_….json` löschen.

Im Feed gibt es entsprechend **＋ Feed-Quelle** (wird beim Speichern auf einen gültigen RSS-/Atom-Feed geprüft)
und unter „Feed-Quellen verwalten" ein ✕ zum Entfernen. Gespeichert wird in `feed_sources.json`.

## Neuen Link-Hub anlegen

Eine bestehende Hub-Datei kopieren (z. B. `furry.php` → `4neuer-hub.php`) und das `$categories`-Array anpassen.
Die Zeile `if (defined('LINKS_ONLY')) return $categories;` muss erhalten bleiben – dann erscheint der Hub
automatisch auf der Startseite und alle seine Links sind über die Startseiten-Suche auffindbar.
Die Zahl am Dateianfang bestimmt die Reihenfolge auf der Startseite.

## Hinweis zur Sicherheit

Auf **nginx**-Servern wirkt die `.htaccess` nicht – dann sind die JSON-Datendateien (z. B. `budget_data.json`) direkt abrufbar.
In dem Fall in der nginx-Konfiguration `location ~ \.json$ { deny all; }` ergänzen.
