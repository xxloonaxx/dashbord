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
| `linkcheck.php` | Prüft alle Hub-Links auf Erreichbarkeit (in kleinen Paketen mit Live-Fortschritt, damit kein Hoster-Zeitlimit greift); kaputte Links bekommen in den Hubs einen ⚠-Hinweis |
| `backup.php` | Lädt alle persönlichen Daten (Budget, Con, Countdowns, Archive) als ZIP herunter |
| `inc/common.php` | Gemeinsame Hilfsfunktionen (JSON laden/speichern, Beträge, Escaping, Zeitzone) |
| `inc/countdown.php`, `inc/linkcheck.php` | Logik für Countdowns und Link-Check |
| `assets/hub-search.js` | Gemeinsame Suche für die Link-Hubs |
| `assets/hud.css` | Gemeinsamer HUD-Look für die neuen Seiten |

## Installation

Alle Dateien und Ordner (inkl. `inc/`, `assets/` und `.htaccess`) auf einen Webserver mit PHP ≥ 7.4 kopieren.
Für Feed und Link-Check wird die PHP-Erweiterung cURL benötigt (bei fast allen Hostern aktiv). Ohne cURL weicht der Link-Check auf `allow_url_fopen` aus. Meldet er „kein einziger Link erreichbar“, blockiert der Hoster vermutlich ausgehende Verbindungen.
Der Webserver braucht Schreibrechte im Ordner, damit die JSON-Dateien und Archive angelegt werden können.

Die Datendateien (`*_data.json`, `links_*.json`, `feed_sources.json`, `feed_cache.json`, `linkcheck_cache.json`, `archive*/`) sind per `.gitignore` vom Repository ausgeschlossen
und werden beim ersten Aufruf automatisch erzeugt.

## Benutzer, Rollen & Rechte

Beim **allerersten Aufruf** legt man das erste Konto an – es wird Administrator. Wer von einer Vorversion
aktualisiert, wird automatisch übernommen (gleicher Benutzername, gleiches Passwort, Protokoll und NSFW-Einstellung).

Alles liegt in der SQLite-Datenbank `data/site_<zufall>.sqlite`; Passwörter nur als bcrypt-Hash.

- **Benutzer** (Admin-Zentrale → Benutzer): anlegen, Rolle zuweisen, deaktivieren, Passwort neu setzen
  (mit Generator), Sitzungen beenden, löschen. Der letzte aktive Administrator kann nicht entmachtet werden.
- **Rollen & Rechte**: Administrator (immer alles), Redakteur, Mitglied – eigene Rollen beliebig anlegen.
  18 Einzelrechte, z. B. `budget.access`, `con.access`, `calendar.edit`, `calendar.private`, `links.edit`,
  `pages.manage`, `users.manage`, `nsfw.view`, `settings.nsfw`, `system.manage`, `audit.view`.
- Rollenwechsel, Deaktivierung und Passwortänderung melden den Benutzer überall ab.
- Nach 5 falschen Passwörtern wird die IP für 10 Minuten gesperrt (in „System“ aufhebbar).
- Jeder Benutzer hat unter „Mein Konto“ Profil, Passwort, seine Rechte und seine letzten Aktivitäten.

**Passwort vergessen?** Ein anderer Administrator setzt es unter „Benutzer“ neu. Gibt es keinen:
per FTP die Datei `data/site_….sqlite` umbenennen → beim nächsten Aufruf erscheint die Einrichtung
(Seiten-Einstellungen, Benutzer und Protokoll beginnen dann neu; alle anderen Daten bleiben).

## Startseite: Kategorien, Sortierung & eigene Seiten

- Seiten erscheinen nach **Kategorien** mit Icon, Titel, **Beschreibung** und optionalen Live-Infos.
- **✎ Bearbeiten** (Recht `pages.manage`, Taste `E`): Kacheln per Drag & Drop sortieren und zwischen
  Kategorien verschieben, Kategorien anlegen/umbenennen/verschieben/löschen, jede Seite bearbeiten
  (Titel, Beschreibung, Icon, Kategorie, Zugriff, verstecken).
- **＋ Neue Seite** aus Vorlagen: **Link-Hub**, **Notizen** (Formatierung wie Markdown), **Checkliste**,
  **Externer Link**. Eigene Seiten liegen unter `page.php?p=<name>`.
- **Zugriff pro Seite**: 🌍 öffentlich · 🔑 nur angemeldet · 🛡️ nur bestimmte Rollen.
  Gäste sehen geschützte Seiten mit Schloss und werden zur Anmeldung geleitet.

## Admin-Zentrale

Übersicht (Kennzahlen, Aktivitäts-Diagramm 14 Tage, letzte Ereignisse, Team), Benutzer, Rollen & Rechte (Matrix),
Seiten, Protokoll (Suche, Filter, CSV-Export), System (Seitentitel, **Wartungsmodus**, Systemstatus, Speicherbelegung,
Werkzeuge), Mein Konto. **Befehlspalette** mit `Strg+K`. Auf dem Handy wird die Seitenleiste zur Reiterleiste.

**HTTPS** beim Hoster aktivieren, sonst werden Passwörter unverschlüsselt übertragen (die Admin-Zentrale warnt dann).

## Kalender (`calendar.php`, SQLite)

- Ansichten **Monat** (mit Kalenderwochen), **Woche** (Zeitleiste mit Jetzt-Linie, überlappende Termine nebeneinander),
  **Agenda** (45 Tage) und **Jahr** (12 Mini-Monate mit Punkten) sowie **Suche**
- Termine: ganztägig oder mit Uhrzeit, mehrtägig, Ort (mit Kartenlink), Link, Notizen, farbige Kategorien,
  Wiederholung (täglich/wöchentlich/monatlich/jährlich, „alle N …“, bis Datum), einzelne Termine einer Serie löschbar
- Privat-Markierung: private Termine sehen nur angemeldete Admins (Besucher sehen nur öffentliche)
- Gesetzliche **Feiertage** (bundesweit + wählbares Bundesland), **Countdowns** als eigene Ebene – nur lesend:
  der Kalender zeigt die Countdowns an, verändert sie aber nie
- Drag & Drop (Monatsansicht), Klick in einen Tag/eine Uhrzeit legt dort einen Termin an, Duplizieren
- Import/Export als `.ics` (Google, Apple, Outlook, Thunderbird), Ebenen-/Kategorie-Filter, Tastenkürzel, Druckansicht
- **Kalender-Abos per URL** (iCal/.ics/webcal, z. B. WoltLab-Forenkalender, Google, Outlook, Nextcloud): eigene Farbe,
  automatische Aktualisierung (15 Min. bis 1 Woche, mit ETag), neue/geänderte/gelöschte/abgesagte Termine werden
  übernommen, verschobene Einzeltermine von Serien ebenso; Abo-Termine sind schreibgeschützt (können aber als eigener
  Termin kopiert werden). Abo-Links enthalten oft persönliche Zugangsschlüssel – Abos sind daher standardmäßig privat.
- **Wiederholungen wie im Profi-Kalender**: wöchentlich an mehreren Tagen, monatlich am n-ten/letzten Wochentag
  („jeden 2. Samstag“), Ende nach Datum oder nach Anzahl
- **Schnell-Eingabe** (Taste `Q`): „Morgen 19 Uhr Stammtisch @Stuttgart #Con“, „jeden 2. Samstag 15 Uhr Walk“, „24.12. Heiligabend“
- **Erinnerungen** (5 Min. bis 1 Woche vorher) als Hinweis + Browser-Benachrichtigung auf Startseite und Kalender,
  „Nächste 24 Std.“-Leiste auf der Startseite
- **Kalender aufs Handy**: geheimer Abo-Link (nur öffentliche oder alle Termine) für Google/Apple/Outlook, jederzeit erneuerbar
- **Jahrestage/Alter** bei jährlichen Terminen („Luna (30)“), **Überschneidungs-Warnung**, einzelne Termine als `.ics`
- Datenbank: `data/calendar_<zufall>.sqlite` (Ordner per `.htaccess` gesperrt, Dateiname nicht erratbar),
  wird beim ersten Aufruf automatisch angelegt und ist im Backup enthalten. Benötigt die PHP-Erweiterung `pdo_sqlite`.

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

Jeder Link-Hub hat (mit Recht `links.edit`) oben die Knöpfe **＋ Link hinzufügen** und **＋ Kategorie** (Tastenkürzel: `n` = neuer Link).
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
In dem Fall in der nginx-Konfiguration `location ~ \.json$ { deny all; }` und `location /data/ { deny all; }` ergänzen.
