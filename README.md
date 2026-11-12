# GHOST NODE – persönliches Dashboard

PHP-Dashboard: Daten als JSON-Dateien neben den Seiten, Benutzer/Seiten/Kalender/VRChat in SQLite (`data/`).

| Datei | Inhalt |
|---|---|
| `index.php` | Startseite: listet alle Seiten automatisch, Websuche + lokale Suche über **alle** Hub-Links |
| `1linkseite.php`, `2VRchat.php`, `3hueter-Zentrale.php`, `furry.php` | Link-Hubs mit Live-Suche |
| `budget.php` | Monatsbudget mit Fixkosten-/Abo-Vorlagen, CSV-Import (z. B. Revolut) und Archiv |
| `con.php` | Con-Rechner: mehrere Cons, Ausgaben, Abrechnung unter Mitreisenden, Rechner, Zeitplan, Packliste, Wunschliste, Vergleich |
| `feed.php` | RSS/Atom-Feed-Sammler mit Cache (15 Min.) und Countdown |
| `countdown.php` | Beliebig viele Countdowns (auch jährlich wiederkehrend), Haupt-Countdown für Feed & Startseite, Kalender-Export (.ics) |
| `linkcheck.php` | Prüft alle Hub-Links auf Erreichbarkeit (in kleinen Paketen mit Live-Fortschritt, damit kein Hoster-Zeitlimit greift); kaputte Links bekommen in den Hubs einen ⚠-Hinweis |
| `backup.php` | Lädt alle persönlichen Daten (Budget, Con, Countdowns, Archive, Datenbanken, hochgeladene Bilder) als ZIP herunter |
| `nexus.php` | **VRC Nexus**: VRChat-Freunde, Verlauf, Statistik, Welten & Instanzen, Avatare, Moderation, Gruppen, Direkt-Zugriff – Login mit 2FA (`vrcx.php` leitet weiter) |
| `discord.php` | **Discord-Zentrale**: Server über den eigenen Bot – Slash-Befehle, Rollen-Knöpfe, Umfragen, Begrüßung, Auto-Posts, Nachrichten & Embeds, Mitglieder, Rollen, Kanäle, Emojis, AutoMod, Events, geplante Nachrichten |
| `furhub.php` | **Furry Hub**: Tamagotchi mit Shop & eigenen Bildern, Fursona-Baukasten (gezeichnet oder eigene Bilder mit 6 Emotionen), Karten-Editor, Minispiele, Community, Con-Bingo, Quiz, Soundboard, OwO-Übersetzer, Glossar, Pfotenabdrücke, Erfolge |
| `nsfw.php` | **NSFW-Galerie** (18+): Bilder-APIs e621/e926, Danbooru, Gelbooru, Rule34, Yande.re, Konachan, waifu.im, waifu.pics – Suche, Favoriten, Posten in NSFW-Kanäle |
| `page.php` | Eigene Seiten aus Vorlagen (Logik in `inc/tpl/*.php`) |
| `inc/format.php`, `assets/editor.js`, `preview.php` | Gemeinsame Formatierung (Markdown + BBCode + HTML), Werkzeugleiste & Live-Vorschau |
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
  (mit Generator), **Ablaufdatum**, **Passwortwechsel beim nächsten Login erzwingen**, Sitzungen beenden, löschen.
  Suche & Filter (Rolle, online, ohne 2FA, abgelaufen …), **Sammelaktionen** für mehrere Benutzer (aktivieren,
  deaktivieren, abmelden, Rolle ändern, Ablaufdatum, Passwortwechsel, löschen) und eine **Detailansicht** je Benutzer
  (Geräte & Sitzungen, Login-Adressen, Verlauf). Der letzte aktive Administrator kann nicht entmachtet werden.
- **Rollen & Rechte**: Administrator (immer alles), Redakteur, Mitglied – eigene Rollen beliebig anlegen, **kopieren**,
  alle Rollen in einer **Rechte-Matrix** bearbeiten und mit **👁 Ansicht als Rolle** die Webseite genau so sehen wie diese Rolle.
  29 Einzelrechte in 6 Gruppen, u. a. `users.view` (nur ansehen), `users.manage`, `roles.manage`, `invites.manage`,
  `sessions.manage`, `security.manage`, `audit.view`, `audit.export`, `announce.manage`, `widgets.manage`,
  `pages.manage`, `system.manage`, `vrcx.access`, `discord.manage`, `nsfw.browse`, `budget.access`, `calendar.edit` …
  Bestehende Rollen bekommen die neuen, feineren Rechte automatisch passend zu ihren bisherigen.
- **Schutz vor Rechte-Ausweitung**: Wer kein Administrator ist, kann nur Rechte vergeben, die er selbst hat,
  niemanden zum Administrator machen (auch nicht per Einladung) und keine Administratoren bearbeiten.
- **Einladungen** (🎟️): Link mit Code erzeugen (Rolle, wie oft nutzbar, Gültigkeit) – Eingeladene legen ihr Konto
  unter `admin.php?invite=CODE` selbst an. Auf der Anmeldeseite gibt es dafür „Konto erstellen“.
- **Zwei-Faktor-Anmeldung** (Mein Konto → 🔐): QR-Code mit jeder Authenticator-App scannen (Aegis, 2FAS, Google/Microsoft
  Authenticator, Bitwarden …), dazu 8 einmalige **Wiederherstellungscodes**. Der Schlüssel liegt verschlüsselt in der Datenbank.
  Admins mit dem Recht `security.manage` können 2FA eines Benutzers zurücksetzen.
- **Sitzungen & Geräte** (🖥️): jede Anmeldung einzeln sichtbar (Browser, System, IP, zuletzt aktiv) und einzeln beendbar –
  für alle Benutzer im Reiter „Sitzungen“, für sich selbst unter „Mein Konto → Meine Geräte“.
- **Sicherheit** (🔐): Passwort-Richtlinie (Mindestlänge, Zeichenarten, Höchstalter in Tagen, verbreitete Passwörter
  ablehnen, 2FA-Pflicht für Administratoren), **IP-Sperrliste** (einzelne Adressen oder Bereiche wie `203.0.113.0/24`,
  befristet oder dauerhaft – die eigene Adresse lässt sich nicht aussperren), Fehlversuche der letzten 7 Tage mit
  „sperren“-Knopf, Login-Sperren einzeln aufheben, 2FA-Status aller Benutzer.
- Rollenwechsel, Deaktivierung und Passwortänderung melden den Benutzer überall ab.
- Nach 5 falschen Passwörtern bzw. 2FA-Codes wird die IP für 10 Minuten gesperrt (in „Sicherheit“/„System“ aufhebbar).
- Jeder Benutzer hat unter „Mein Konto“ Profil, Passwort, 2FA, Geräte, seine Rechte (gruppiert) und seine letzten Aktivitäten.

**Passwort vergessen?** Ein anderer Administrator setzt es unter „Benutzer“ neu. Gibt es keinen:
per FTP die Datei `data/site_….sqlite` umbenennen → beim nächsten Aufruf erscheint die Einrichtung
(Seiten-Einstellungen, Benutzer und Protokoll beginnen dann neu; alle anderen Daten bleiben).

## Startseite: Kategorien, Sortierung & eigene Seiten

- Seiten erscheinen nach **Kategorien** mit Icon, Titel, **Beschreibung** und optionalen Live-Infos.
- **✎ Bearbeiten** (Recht `pages.manage`, Taste `E`): Kacheln per Drag & Drop sortieren und zwischen
  Kategorien verschieben, Kategorien anlegen/umbenennen/verschieben/löschen, jede Seite bearbeiten
  (Titel, Beschreibung, Icon, Kategorie, Zugriff, verstecken).
- **＋ Neue Seite** aus 10 Vorlagen (eigene Seiten liegen unter `page.php?p=<name>`):
  - **Link-Hub** – wie die mitgelieferten Hubs
  - **Notizen** – beliebig viele Notizen als Karten: ✎ bearbeiten, 📌 anheften, ⧉ kopieren, 🗑 löschen,
    Farben, Tags (Filter-Chips), Suche, Sortierung. Alte Einzel-Notizseiten werden automatisch zur ersten Notiz.
  - **Checkliste** – Gruppen mit Fortschritt, Punkte bearbeiten, Gruppen verschieben
  - **Kanban-Board** – Spalten & Karten (Drag & Drop oder ◀ ▶), Fälligkeitsdatum, Labels, Farben
  - **Tabelle** – eigene Spalten (Text, Zahl, Betrag €, Datum, Haken, Link, Bewertung ★), Sortieren per Klick,
    Summen/Durchschnitt, CSV-Export für Excel
  - **Tagebuch / Blog** – datierte Einträge mit Stimmung, Tags und Monatsarchiv
  - **Galerie** – Bilder hochladen (JPG/PNG/GIF/WebP, landen in `uploads/`) oder verlinken, Großansicht mit Pfeiltasten/Wischen
  - **Zähler & Tracker** – + / − ohne Neuladen, Ziel mit Fortschritt, „heute“-Wert, Verlauf
  - **Einbettungen** – YouTube, Twitch, Vimeo, Spotify, SoundCloud werden erkannt, sonst beliebige Webseite
  - **Externer Link** – Kachel, die direkt woandershin führt
- **Zugriff pro Seite**: 🌍 öffentlich · 🔑 nur angemeldet · 🛡️ nur bestimmte Rollen.
  Gäste sehen geschützte Seiten mit Schloss und werden zur Anmeldung geleitet.

## Widgets auf der Startseite (Plug & Play)

Über den Kacheln liegt ein **Widget-Bereich**. Mitgeliefert werden 10 Widgets: 🕒 Uhr & Datum (mit zweiter Zeitzone, KW),
🌦️ Wetter (Open-Meteo, ohne Schlüssel), ⏳ Countdowns (live), 📅 Mini-Kalender, ✅ Aufgaben, 📝 Schnellnotiz (Markdown/BBCode),
💶 Budget (Recht `budget.access`), 🥽 VRChat-Freunde online (Recht `vrcx.access`), 📡 Neuigkeiten aus dem Feed und ⚡ Schnellzugriff.

- **Anordnen** im ✎ Bearbeiten-Modus der Startseite: „＋ Widget“, per Drag & Drop sortieren, ⇔ Größe (klein/mittel/breit/ganze Zeile),
  ⚙ Einstellungen (pro Widget eigene, plus eigener Titel), ✕ entfernen. Dasselbe Widget kann mehrfach vorkommen (z. B. zwei Uhren).
- **Weitere 10 Widgets** liegen bereit und können über „＋ Widget“ hinzugefügt werden (sind nicht automatisch auf der Startseite):
  💬 Zitat des Tages, 🎲 Zufall (Würfel W4–W100, Münze, Zahl, eigene Auswahlliste), 🍅 Fokus-Timer (Pomodoro mit Ton, Benachrichtigung, Tageszähler),
  🖥️ Systemstatus (Recht `system.manage`), 📰 RSS-Quelle (eine Quelle/Kategorie aus dem Reader mit Bildern), 📊 Zeit-Fortschritt (Tag/Arbeitstag,
  Woche, Monat, Jahr, eigene Zeitspanne), 💱 Währungsrechner (EZB-Kurse), 🎪 Nächste Con (Recht `con.access`), 🤖 Discord-Server (Recht `discord.manage`)
  und 🖼️ Bilderrahmen (Diashow aus eigenen Bild-Adressen und `uploads/`).
- Besucher sehen nur Widgets, für die sie das Recht haben; jeder kann Widgets für sich **einklappen** oder den ganzen Bereich ausblenden.
- **Eigene Widgets:** Jedes Widget ist ein Ordner unter `widgets/` (`widget.php` + optional `widget.css`/`widget.js`). `widgets/_vorlage`
  kopieren, umbenennen, anpassen – es erscheint automatisch unter „＋ Widget“. Alle Möglichkeiten (Einstellungen, gespeicherter Zustand,
  Aktionen per Klick/Formular, automatisches Neuladen, Caching von Web-Abfragen) sind in der Vorlage kommentiert, siehe auch `widgets/README.md`.
  Ein Fehler in einem Widget wird nur in diesem Widget angezeigt.
- Außerdem auf der Startseite: **★ Favoriten** (Stern an jeder Kachel) und **🕘 zuletzt besucht** als Schnellleiste (pro Browser).

## Gender-Modus

Über den Knopf **⚧** neben deinem Namen oben rechts (oder in VRC Nexus → Konto) lassen sich die Texte des Dashboards gendern:
Sternchen (Freund\*innen), Doppelpunkt (Freund:innen), Unterstrich (Freund_innen), Mediopunkt (Freund·innen) oder Binnen-I
(FreundInnen). Die Einstellung gilt pro Browser; eigene Inhalte wie Notizen oder Bios bleiben unverändert.

## Formatierung (überall gleich)

Notizen, Karten, Tagebuch, Tabellen-Texte, Checklisten-Punkte, Beschreibungen im Index und in den Link-Hubs,
Kalender-Notizen und Countdown-Notizen verstehen **gleichzeitig**:

- **Markdown**: `**fett**`, `*kursiv*`, `~~durch~~`, `==markiert==`, `# Überschrift`, `- Liste`, `1. Liste`, `- [ ] Aufgabe`,
  `> Zitat`, Tabellen mit `|`, `` `Code` ``, ```` ``` ````-Blöcke, `[Text](https://…)`, `![Bild](https://…)`, `||Spoiler||`, `:heart:`
- **BBCode**: `[b] [i] [u] [s]`, `[color=red]`, `[size=5]`, `[url=…]`, `[img]`, `[quote=Name]`, `[spoiler=Titel]`,
  `[list][*]…[/list]`, `[center]`, `[code]`, `[youtube]…[/youtube]`, `[table][tr][td]`
- **HTML** (gefiltert): `<b> <i> <u> <mark> <span style="…"> <a> <img> <table> <details> <kbd> <sup>` …

Alles wird serverseitig bereinigt: `<script>`, `on…`-Attribute, `javascript:`-Links, gefährliches CSS und
fremde iframes werden entfernt, offene Tags automatisch geschlossen. Jedes Textfeld bekommt eine **Werkzeugleiste**
(Fett, Farbe, Liste, Link, Tabelle …), eine **👁 Live-Vorschau** und eine **?**-Kurzhilfe; `Strg+B/I/U/K` funktionieren.

## Live-Feed / RSS-Reader (`feed.php`)

Ein vollwertiger Feed-Reader mit drei Spalten (Quellen · Artikel · Lesebereich), am Handy mit ausklappbarer Seitenleiste.

- **Quellen nach Kategorien** mit Favicon bzw. eigenem Icon, Ungelesen-Zählern, Fehleranzeige (⚠) und einklappbaren Kategorien;
  Ansichten **Alle · Ungelesen · Heute · ⭐ Gemerkt**.
- **Quelle hinzufügen** (Recht `feed.edit`): einfach die Adresse einer Webseite eingeben – der Feed wird automatisch gefunden (auch mehrere zur Auswahl).
  Abkürzungen: YouTube-Kanäle/-Playlists, Subreddits, Bluesky- und Mastodon-Profile, GitHub-Projekte (Releases). RSS 2.0, RSS 1.0, Atom und JSON Feed.
- **Quellen verwalten**: bearbeiten (Name, Icon, Adresse, Kategorie), pausieren, einzeln neu laden, entfernen, Kategorien umbenennen,
  Status je Quelle (letzter Abruf, Fehler), **OPML-Import/-Export** (z. B. von Feedly, Inoreader, Thunderbird).
- **Lesen**: Artikel mit Bild, Autor, Lesezeit und bereinigtem Inhalt (Skripte, Tracking und gefährliche Links werden entfernt), Schriftgröße,
  Link kopieren, Original öffnen. **Gelesen/Gemerkt pro Benutzer**, „alle als gelesen“, nur Ungelesene, Suche, Sortierung.
- **Vier Ansichten**: Liste, Kacheln, Magazin, Kompakt. **Tastenkürzel**: J/K, O, S, M, R, V, U, Shift+A, /, G, Esc, ? (Übersicht).
- Aktualisiert sich selbst (höchstens alle 15 Min., mit ETag/If-Modified-Since); per Cron pünktlich über `feed.php?cron=…` (Adresse unter „⚙ Quellen“).
  Artikel älter als 90 Tage werden aufgeräumt, gemerkte bleiben. Daten in `data/feed_….sqlite`; bisherige Quellen werden übernommen.

## VRC Nexus (`nexus.php`)

Die VRChat-Zentrale des Dashboards (früher „VRCX“ – umbenannt, weil VRCX ein eigenständiges Projekt ist; `vrcx.php` leitet weiter). Recht: `vrcx.access` (Administratoren haben es automatisch,
anderen Rollen in der Admin-Zentrale geben). Jeder Dashboard-Benutzer verbindet **sein eigenes** VRChat-Konto.

- **Anmeldung** mit VRChat-Benutzername/E-Mail + Passwort, danach **2FA**: Authenticator-App, E-Mail-Code oder Wiederherstellungscode.
  Das Passwort wird **nicht gespeichert** – nur das VRChat-Sitzungs-Cookie, AES-256-GCM-verschlüsselt (`data/vrcx_….sqlite`,
  Schlüssel in `data/.vrc_key_….php`). Nach einem Backup-Wiederherstellen muss man sich deshalb neu anmelden.
- **Freunde**: Im Spiel / Website & App / Offline mit Welt, Instanz-Typ, Region und „seit“-Zeit; Filter nach Plattform,
  Vertrauensstufe, VRChat-Favoritengruppen, „nur mit Notiz“; Sortierung nach Status, Name, Welt, Vertrauen, neueste Freunde;
  Kompaktansicht; „Zusammen unterwegs“; ▶ direkt beitreten; CSV-Export. Oben: wo du selbst gerade bist und mit wem.
- **Profil-Dialog**: Bio, Links, Gruppen, Online-Zeit (7/30 Tage), Sitzungen, zuletzt gesehen (+ Welt), Freund seit;
  ▶ Beitreten, Selbst-Einladung, Einladung anfragen, **zu mir einladen**, Instanz-Info, eigene Notiz (optional auch als
  VRChat-Notiz), ⭐ Favorit, stummschalten, Avatar ausblenden, blockieren, Freundschaft beenden/anfragen.
- **Verlauf**: online/offline, Ortswechsel, Status, Avatar, Name, Bio, Vertrauensstufe, Freunde +/−, **deine eigenen Orte** –
  nach Tagen gruppiert, mit Filter, Suche und CSV-Export.
- **Statistik**: Freunde online (7-Tage-Kurve), meiste Spielzeit, beliebteste Welten deiner Freunde, deine meistbesuchten
  Welten, Aktivitäts-Heatmap (Wochentag × Uhrzeit), Vertrauensstufen, Plattformen, Kennzahlen.
- **Welten**: Favoriten, zuletzt besucht, gerade beliebt, eigene Welten, „wo Freunde sind“, Suche; Welt-Dialog mit Infos,
  offenen Instanzen, Freunden dort, Favorisieren und **neue Instanz erstellen** (Friends+, Friends, Invite(+), Public, Gruppe)
  inkl. automatischer Selbst-Einladung. Instanz-Info mit Belegung und PC/Quest-Verteilung.
- **Avatare**: eigene und favorisierte Avatare, Filter, **Avatar wechseln** mit einem Klick.
- **Gruppen**: Info/Regeln, **Beiträge** (lesen, veröffentlichen, löschen), Mitglieder (filtern, Rollen vergeben/entziehen,
  entfernen, bannen), Beitrittsanfragen, Freunde einladen, Banns, Instanzen, Gruppen-Protokoll, repräsentieren,
  beitreten/verlassen, Gruppensuche – je nach deinen Rechten in der Gruppe.
- **Anfragen** (Freundschaftsanfragen annehmen, Einladungswünsche direkt beantworten), **Moderation** (Blockierte,
  Stummgeschaltete usw. ansehen und aufheben), **Suche** nach Benutzern, Welten und Gruppen.
- **Konto**: Status + Status-Vorlagen, Profil (Bio, Pronomen, Links), **Browser-Benachrichtigungen** (wenn Favoriten /
  VRChat-Favoriten / alle ins Spiel kommen, optional offline & Ortswechsel, mit Ton), Aktualisierungs-Intervall, Exporte.
- **📋 Direkt-Zugriff**: Eine VRChat-ID oder ein Link in der Zwischenablage? Einfach auf der Seite **Strg+V** drücken (oder
  „📋 Direkt-Zugriff“). Erkannt werden `usr_…`, `wrld_…`, `avtr_…`, `grp_…`, Gruppen-Kürzel (`FURRY.1234`, `vrc.group/…`),
  Instanzen (`wrld_…:12345~…`), vrchat.com-Links (Profil, Welt, Avatar, Gruppe, Einladungslink) und `vrchat://launch`.
  Optional prüft die Seite beim Zurückkehren die Zwischenablage und bietet erkannte IDs an.
- **⌘ Befehlspalette** (`Strg+K`): IDs/Links einfügen, Freunde finden, zuletzt Angesehenes, Status setzen, Reiter wechseln,
  Benutzer/Welten/Gruppen suchen, Welt-Roulette, Party-Einladung.
- **Avatar-Dialog** (aus ID, Link oder Liste): Infos, Plattformen, Performance, anziehen, favorisieren.
- **Eigene Listen** für Freunde (z. B. „Stammtisch“, „EF-Gruppe“) als Filter-Chips, **🔔 Beobachten** (Benachrichtigung, sobald
  jemand online kommt – auch wenn Benachrichtigungen sonst aus sind).
- **👥 Party-Einladung**: mehrere Freunde auf einmal in die eigene Instanz einladen.
- **Instanzen**: 🔖 merken, 🔗 Einladungslink kopieren, 📣 direkt in Discord teilen (mit der Discord-Zentrale),
  „Meine letzten Instanzen“ mit Wieder-Beitreten, 🎲 Welt-Roulette.
- **💤 Lange nicht online**: Freunde, die über 30 Tage nicht da waren (in der Statistik).
- **Avatare**: Favoriten nach VRChat-Favoritengruppen gruppiert (mit deren Namen), in andere Gruppe verschieben; sortieren nach
  Gruppe, zuletzt aktualisiert, neueste, Name, Autor, Plattform (PC + Quest zuerst) oder VRChat-Reihenfolge; Plattform-Filter.
- **Gruppen**: Gruppen, in denen du Moderationsrechte hast, stehen **ganz oben** (🛡️), danach alle weiteren.
- **Gruppen-Moderation im Profil**: Im Profil-Dialog siehst du für jede Gruppe, die du moderierst, ob die Person Mitglied ist
  (seit wann, Rollen) und kannst je nach Recht einladen, Rollen vergeben/entziehen, entfernen, bannen oder entbannen.
  Gemeinsame Gruppen werden markiert (🤝).
- **📅 Kalender**: Events deiner VRChat-Gruppen (und solche, für die du dich interessierst) für die nächsten Monate – mit Filter
  nach Gruppe/Kategorie, ⭐ „Interessiert“, neuen Events anlegen und löschen (mit dem Gruppenrecht „Kalender verwalten“).
  **Synchronisiert mit dem Dashboard-Kalender** (privates Abo „🥽 VRChat-Events“, aktualisiert sich automatisch) und als
  **Abo-Link** (`nexus.php?ics=…`) für Handy, Outlook oder Google-Kalender.
- **🖼️ Galerie** wie im VRChat-Menü: Fotos, Icons, Emojis, Sticker (mit Anzahl/Limit), Prints und Inventar – ansehen, hochladen
  (Emojis mit Animation; VRChat verlangt dafür meist VRC+), löschen, Icon als Profilbild setzen. Bilder laufen über den Server,
  weil VRChat sie nur angemeldet ausliefert.
- **📰 Info-Push**: Hilfe-Artikel, Quick-Menü-Banner und Ladebildschirme von VRChat mit Artikelansicht.
- **Pronomen** der Freunde werden in der Liste angezeigt.
- **⭐ Favoriten** wie in VRCX – für Freunde, Welten und Avatare: links die **VRChat-Favoritengruppen** mit Zähler/Limit (z. B. 27/150) und
  Sichtbarkeit (⋯ umbenennen, privat/Freunde/öffentlich), darunter **lokale Favoritengruppen** (unbegrenzt, nur im Dashboard, „＋ Neue Gruppe“)
  und der **lokale Verlauf** angesehener Welten/Avatare. Rechts ein Raster (Spaltenzahl einstellbar) mit ⋯-Menü je Eintrag (öffnen, ID kopieren,
  in andere VRChat- oder lokale Gruppe verschieben, entfernen) und **Bearbeiten-Modus** für Mehrfachauswahl.
- In jedem **Welt-, Avatar- und Profil-Dialog**: „♥ VRChat-Favorit“ (Gruppe wählen/wechseln/entfernen) und „📌 Lokal“ (in eine oder mehrere lokale Gruppen merken).
- **Gruppen** wahlweise als **Raster** (mit Banner, Spaltenzahl einstellbar) oder als Liste; gestapelte, einklappbare Abschnitte (👑 eigene, 🛡️ moderierte, 👥 weitere) mit Filter und Sortierung; Details öffnen sich im **Popup**
  – mit ⧉ Link kopieren, 📣 in Discord teilen und ⬇ Mitgliederliste als CSV.
- Tastenkürzel: `Strg+K` Palette, `Strg+V` ID öffnen, `/` Suche, `R` aktualisieren, `1`–`0` Reiter, `Esc` schließen.
- Moderationsaktionen (Kick, Bann, Blockieren, Freundschaft beenden …) landen im Protokoll der Admin-Zentrale.

Die VRChat-API ist **inoffiziell**. VRC Nexus fragt bewusst sparsam ab (Freunde höchstens alle 45 s, offline Freunde und
Favoriten alle 15 min, Weltnamen werden gespeichert) und führt nichts automatisch aus. Verlauf und Statistik entstehen nur,
solange die Seite offen ist. VRChat bittet darum, dass sich Programme per User-Agent mit einer Kontaktmöglichkeit ausweisen –
das lässt sich in VRC Nexus → Konto einstellen (Recht `system.manage`). Voraussetzung: cURL und ausgehende Verbindungen zu `api.vrchat.cloud`.

## Discord-Zentrale (`discord.php`)

Den eigenen Discord-Server über einen **eigenen Bot** verwalten. Recht: `discord.manage` (Administratoren automatisch).

1. Auf discord.com/developers/applications eine Application anlegen → **Bot** → **Reset Token** → Token in der Zentrale eintragen
   (wird AES-256-GCM-verschlüsselt gespeichert, nie wieder angezeigt; Schlüssel in `data/.discord_key_….php`).
2. Im Developer-Portal **Server Members Intent** und **Message Content Intent** einschalten.
3. Mit dem angezeigten **Einladungslink** den Bot auf den Server holen und seine Rolle über die zu verwaltenden Rollen schieben.

Funktionen:
- **Übersicht**: Mitglieder, online, Boosts, Kanäle, Rollen, Emojis, Menschen/Bots, neue Mitglieder pro Monat, zuletzt beigetreten, größte Rollen.
- **Nachrichten**: Kanal wählen, Text mit Discord-Markdown, **Embed-Baukasten** (Titel, Link, Farbe, Bild, Vorschaubild, Autor,
  Fußzeile, Zeitstempel, Felder) mit Live-Vorschau im Discord-Look, Antworten, Vorlagen (im Browser), Countdown einfügen,
  @everyone nur auf Wunsch; letzte Nachrichten lesen, anpinnen, löschen.
- **Mitglieder**: Suche, Filter nach Rolle/Bots/Timeouts; Spitzname, Rollen vergeben/entziehen, Timeout, Kick, Bann (mit Grund fürs Audit-Log).
- **Rollen** (Farben, Rechte, Mitgliederzahl), **Kanäle** (Baum, neue Kanäle, Einladungen erstellen/löschen).
- **Events**: Server-Events ansehen, anlegen, löschen; **Kalender-Termine als Discord-Event** übernehmen und Discord-Events **in den Kalender** holen.
- **Moderation**: Banns (aufheben) und Audit-Log.
- **Geplant**: Nachrichten einmalig, täglich, wöchentlich oder monatlich senden. Sie gehen raus, wenn jemand die Zentrale öffnet –
  pünktlich, wenn die angezeigte **Cron-Adresse** (`discord.php?cron=…`) alle 5 Minuten aufgerufen wird (Hoster-Cronjob oder cron-job.org).
- **VRC Nexus → „📣 In Discord teilen“** schickt eine Instanz mit Beitreten-Link in den Standard-Kanal.
- Alle Moderations- und Sendeaktionen landen im Protokoll der Admin-Zentrale.

### Slash-Befehle & Knöpfe (ohne Dauerbetrieb)

Ein PHP-Webspace kann keinen Bot rund um die Uhr laufen lassen. Discord bietet dafür den **Interactions Endpoint**:
Discord schickt jeden Slash-Befehl und jeden Knopfdruck signiert (Ed25519) an `discord.php?interactions`, die Zentrale antwortet sofort.
Voraussetzungen: die Seite ist per **HTTPS** erreichbar, und die PHP-Erweiterung **sodium** ist aktiv (Standard ab PHP 7.2).

Einrichtung im Reiter **⚡ Befehle** → „🚀 Automatisch einrichten“: Die Zentrale holt den öffentlichen Schlüssel der Anwendung,
trägt die Endpunkt-Adresse bei Discord ein (Discord prüft sie dabei sofort) und registriert die Befehle auf dem Server.
Klappt das automatische Eintragen nicht, die angezeigte Adresse im Developer-Portal unter *General Information → Interactions Endpoint URL*
eintragen und „Adresse selbst eingetragen“ klicken. Bots, die vor diesem Update eingeladen wurden, einmal mit dem neuen Einladungslink
(Einstellungen) erneut einladen – er enthält jetzt `applications.commands` und die Rechte für Webhooks, Emojis, Threads und Umfragen.

- **Eingebaute Befehle** (einzeln abschaltbar): `/hilfe`, `/countdown`, `/termine`, `/würfel`, `/münze`, `/wähle`, `/8ball`, `/umfrage` (echte
  Discord-Umfrage), `/erinnerung` (z. B. `30m`, `2h`, `18:30`, `24.12. 18:00` – wird als geplante Nachricht gespeichert), `/info`, `/server`,
  `/avatar`, `/rechne` (Taschenrechner), `/zufallszahl`, `/wetter`, `/währung` (EZB-Kurse), `/bild` (jugendfreie Bilder) und `/nsfw` (nur in altersbeschränkten Kanälen, mit Tag-Vorschlägen und „🔁 Noch eins“).
- **Eigene Befehle**: Name, Antwort mit Platzhaltern (`{user}`, `{name}`, `{text}`, `{channel}`, `{date}`, `{time}`, `{random:A|B|C}`),
  wahlweise als Infobox mit Bild/GIF, nur für die aufrufende Person sichtbar oder nur in NSFW-Kanälen. Nutzung wird gezählt.
- **🧪 Ausprobieren**: jeden Befehl im Dashboard testen, ohne Discord und ohne Seiteneffekte – mit Vorschau im Discord-Look.
- **Letzte Benutzungen**: wer welchen Befehl wann genutzt hat (inkl. Fehlern).

### Rollen-Knöpfe, Automatik & mehr

- **🎛 Rollen-Knöpfe**: Nachricht mit bis zu 25 Knöpfen, über die sich Mitglieder Rollen selbst nehmen (Spezies, Pronomen, Ping-Rollen,
  „Regeln akzeptiert“). Modi: an/aus, nur eine aus der Gruppe, nur hinzufügen. Bearbeiten aktualisiert die Nachricht in Discord.
- **🤖 Automatik**: **Begrüßung** neuer Mitglieder (Text mit `{user}`, `{server}`, `{count}`, als Infobox mit Profilbild), **Auto-Rolle**,
  **Abschiedsmeldung**; **Auto-Posts** aus den Bilder-APIs (z. B. alle 4 Stunden ein e926-Bild mit bestimmten Tags) oder aus **RSS-/Atom-Feeds**.
  NSFW-Auto-Posts gehen nur in altersbeschränkte Kanäle und pausieren, falls der Kanal die Einstellung verliert.
  Das alles läuft über die **Cron-Adresse** (alle 5 Minuten aufrufen lassen) bzw. beim Öffnen der Zentrale.
- **Nachrichten**: 📊 **Umfragen** anhängen (bis 10 Antworten, Emoji am Anfang = Antwort-Emoji, Laufzeit, Mehrfachauswahl; Ergebnisse
  und „Beenden“ in der Nachrichtenliste), 🎭 **als Persona senden** (eigener Name und Profilbild über einen Webhook, Personas merkbar),
  eigene Bot-Nachrichten ✎ **bearbeiten**, 😀 Reaktionen, 🧵 Threads, 🧹 **Aufräumen** (bis 100 Nachrichten der letzten 14 Tage –
  alle, nur Bots, eine Person, mit Text, Links oder Dateien).
- **Kanäle**: ⚙ bearbeiten (Name, Thema, Slowmode, 🔞 altersbeschränkt) und löschen, aktive **Threads** (sperren, archivieren),
  **Webhooks** ansehen, anlegen (Adresse für GitHub, Twitch-Bots usw.) und löschen.
- **Rollen** anlegen, umbenennen, Farbe und Anzeige ändern, löschen. **😀 Emojis**: hochladen (max. 256 KB), umbenennen, löschen,
  Code kopieren; Sticker ansehen/löschen; Limit je Boost-Stufe.
- **Moderation → AutoMod**: Regeln für bestimmte Wörter (mit `*`-Platzhaltern), Discords vorgefertigte Listen, zu viele Erwähnungen
  oder Spam – blockieren, im Kanal melden und Timeout; pausieren und löschen.

## Furry Hub (`furhub.php`)

Spielwiese für die Community – öffentlich, vieles geht auch ohne Konto:

- **🥚 Tamagotchi** (früher Boop + Begleiter): Ein eigenes Haustier in einem Tamagotchi-Gehäuse.
  - Es schlüpft aus einem **Ei** (Boops wärmen es) und wächst über Baby, Kind und Teenie zu Erwachsen.
  - Die Werte Satt, Spaß, Energie, Sauber, Gesundheit und Zuneigung sinken auch, während du weg bist.
  - Es macht **Häufchen** 💩, kann **krank** werden (Medizin hilft), schläft bei Licht aus und zeigt seine Stimmung über **6 Emotionen**.
  - Interaktionen: Boop, Kraulen, Umarmen und Awoo (alles zählt in die gemeinsamen Zähler und die Bestenliste), Füttern mit Futter-Menü, Spielen, Waschen, Aufräumen.
  - **Münzen 🪙** gibt es für Stufen, jeden 25. Boop, den täglichen Besuch und die Minispiele.
  - **Shop**: Essen, Medizin, Schaumbad, dauerhafte Extras (Ball, Kuscheltier, Kuschelbett) und **Accessoires** (Schleife, Krone, Sonnenbrille …).
  - **Anpassbar**: Name, Persönlichkeit (verspielt, verfressen, verschlafen, kuschelig, frech – beeinflusst die Werte), Lieblingsessen und Stimme.
  - Aussehen wahlweise wie deine Fursona, als Zufallstier oder mit **eigenen Bildern für 6 Emotionen**; dazu Hintergrund und Gehäusefarbe.
  - Wer angemeldet ist, bekommt den Stand auf dem Server gespeichert (geräteübergreifend). Die Hardware-Knöpfe A/B/C funktionieren auch.
  - **💾 Spielstände**: bis zu 6 Haustiere gleichzeitig.
    - Wechseln, duplizieren und als Datei exportieren oder importieren (einzeln oder alle).
    - Alle Haustiere leben weiter, auch wenn sie gerade nicht aktiv sind.
  - **Modi**: 🌸 Entspannt (½ Tempo), 🙂 Normal, 🔥 Schwer (1,5×) und **💀 Hardcore**.
    - Hardcore: doppeltes Tempo, Echtzeit, kein Urlaub, keine Cheats, 1,5× Münzen.
    - Bei zu langer Vernachlässigung geht das Haustier über die Regenbogenbrücke 🌈 und bleibt als Erinnerung im Spielstand.
    - In allen anderen Modi stirbt niemand.
  - **⚙️ Einstellungen** je Spielstand:
    - Zeit: Echtzeit oder „pausiert, solange die Seite zu ist“.
    - Wachstum: normal (7 Tage) oder schnell (~1 Tag).
    - 🏖️ Urlaubsmodus, Häufchen an/aus, Krankheiten an/aus, 🌙 Auto-Schlaf (23–7 Uhr).
    - Töne, Sprechblasen (das Haustier meldet sich, wenn es etwas braucht) und 🔔 Browser-Benachrichtigungen.
  - **🃏 Cheats** (nicht im Hardcore-Modus): Codes im Einstellungs-Reiter, z. B. `MOREFLUFF`, `BELLYFULL`, `HATCHNOW`, `TIMEWARP`, `FASHION`, `SPARKLE`, `RAINBOW`, `DISCO`, `BIGFLUFF`, `IMMORTAL` … (Liste dort als Spoiler). Benutzte Cheats markieren den Spielstand mit 🃏.
  - **Tastenkürzel**: Leertaste Boop, F Füttern, P Spielen, W Waschen, C Aufräumen, M Medizin, K Kraulen, H Umarmen, L Licht.
  - Ein roter Punkt am Reiter zeigt, wenn das Haustier etwas braucht. Auf der Startseite gibt es eine Status-Karte.
- **🎨 Fursona**: deine eigene Sona mit **deinen eigenen Bildern**.
  - Name, Art, **Geschlecht**, Pronomen (Vorschlag passend zum Geschlecht), Persönlichkeit, Bio und Ref-Link.
  - **⭐ Hauptbild / Ref-Sheet** (bis 1600 px).
  - **6 Emotionsbilder**: Normal, Fröhlich, Traurig, Wütend, Überrascht, Müde – oder eins für alle.
  - **Galerie mit bis zu 12 Bildern**: Art (Ref-Sheet, Ganzkörper, Headshot, Artwork …), Beschreibung bzw. Credits, Reihenfolge, als Hauptbild setzen; Upload mehrerer Bilder oder per Drag & Drop.
  - Bildbetrachter mit Pfeiltasten.
  - Umschalter „So wird deine Sona gezeigt“: 🖼️ Meine Bilder oder 🧑‍🎨 Mein Avatar.
  - Deine Bilder erscheinen in Community, Karten, Tamagotchi und Minispielen.
- **🧑‍🎨 Avatar**: der gezeichnete Baukasten, jetzt dein **Profilbild**.
  - Zu sehen in der Community-Ecke und bei Pfotenabdrücken; er springt überall ein, wo kein eigenes Bild da ist.
  - 19 Tierarten, 10 Farben, Ohren, Schnauze, Zeichnung, Extras und Accessoires.
  - **Geschlecht**: Weiblich (Wimpern, schmalere Schultern), Männlich (kräftige Brauen, breite Schultern), Nicht-binär (dezente Wimpern) oder keine Angabe.
  - **Frisuren**: Pony, lange Haare, Stachelig, Dutt, Locken, Irokese – mit eigener Haarfarbe.
  - **Pride-Anstecker**: Regenbogen, Trans, Nicht-binär, Bi, Pan, Lesbisch, Ace, Gay.
  - 6 Emotionen, PNG/SVG-Download.
- **🃏 Karten-Editor**: **Sammelkarte** (Flausch-Punkte, 2 Fähigkeiten, Werte), **Con-Badge** oder **Steckbrief**.
  - 9 Farbthemen, Seltenheit, ✨ Holo-Glanz (folgt der Maus) und Typ-Symbol.
  - Bild aus der Fursona (mit Emotion) oder hochgeladen. Verschieben mit der Maus, zoomen mit dem Mausrad.
  - Würfel für Fähigkeiten und Werte, **PNG-Export in 900×1260**.
  - Bis zu 40 Karten speichern und optional **in der Community teilen**.
- **🕹️ Minispiele** (mit Münzen und Rekorden):
  - Leckerli fangen: Fang Futter, meide Zwiebeln & Schoki; dein Tamagotchi ist der Fänger.
  - Nasen-Boopen (Whack-a-Mole mit den Community-Sonas).
  - Pfoten-Memory.
  - Tierlaute raten.
  - Glücksrad mit eigenen Einträgen.
- **🐾 Community**: gespeicherte Sonas (Suche, Likes, Ref-Link, direkt boopen) und geteilte Karten (mit PNG-Download).
- Außerdem:
  - Con-Bingo
  - Tier-Quiz
  - Soundboard (12 Tierlaute aus dem Browser)
  - OwO-Übersetzer
  - Glossar
  - Pfotenabdrücke (Gästebuch)
  - Tierfakt und Zeichen-Idee des Tages
  - **35 Erfolge**
  - Easter Eggs
  - 🐾-Spur am Mauszeiger
- Widget **„Boop!“** für die Startseite (unter „＋ Widget“).
- Moderation mit dem Recht `furhub.moderate`: Pfotenabdrücke, Sonas und geteilte Karten entfernen.

**Bilder-Upload**: PNG, JPG, WebP oder GIF bis 2,5 MB.
- Der Browser verkleinert die Bilder vor dem Hochladen.
- Der Server prüft, ob es wirklich ein Bild ist, und kodiert es (wenn GD vorhanden ist) neu. Dabei werden Metadaten entfernt. Animierte GIFs bleiben unverändert.
- Ausgeliefert werden die Bilder über `furhub.php?img=…`, mit `nosniff` und strenger CSP.
- Nicht mehr benutzte Bilder werden nach einem Tag automatisch gelöscht. Pro Konto sind max. 120 Bilder erlaubt.
- Ohne Anmeldung bleiben Bilder als verkleinerte Kopie nur im Browser.

Daten: `data/furhub_<zufall>.sqlite` (Zähler, Sonas, Likes, Tamagotchi, Karten, Pfotenabdrücke) und `data/furhub_img/` (Bilder, im Backup enthalten).

## NSFW-Galerie (`nsfw.php`, 18+)

Recht `nsfw.browse` (Administratoren automatisch). Beim ersten Öffnen fragt die Seite einmal nach dem Alter (18+); die Bestätigung
wird pro Benutzer im Protokoll vermerkt. Suchmaschinen werden per `noindex` ausgesperrt.

- **Quellen**: e621 (Furry), e926 (jugendfrei), Danbooru, Gelbooru, Rule34, Yande.re, Konachan, waifu.im und waifu.pics (Kategorien).
  Gelbooru und Rule34 verlangen inzwischen einen kostenlosen API-Schlüssel, bei e621 und Danbooru ist er optional –
  unter ⚙ Einstellungen eintragen (verschlüsselt gespeichert).
- **Suche** mit Tag-Vorschlägen (e621/e926/Danbooru), Inhalt (sicher, fragwürdig, explizit, NSFW, alles), Sortierung (neu, Bewertung,
  Favoriten, zufällig), Endlos-Scrollen. Vorschaubilder auf Wunsch **unscharf** bis zum Drüberfahren.
- **Lightbox**: groß ansehen (auch Videos/GIFs), ← → blättern, Künstler und Tags anklicken (⇧-Klick = ausschließen),
  ⭐ Favorit (Taste `F`), Original, Quelle, 📨 **in einen NSFW-Kanal posten** (mit Kommentar).
- **Diashow** (Taste `S`, Tempo per Rechtsklick; Videos laufen bis zum Ende) in der Suche und für Favoriten.
- **Favoriten** (filterbar, ⬇ Export als Linkliste oder JSON) und **gespeicherte Suchen** – die auch als Auto-Post in der Discord-Zentrale nutzbar sind.
- **Blacklist**: eigene gesperrte Tags. Zusätzlich ist eine **feste Sperrliste** eingebaut, die sich nicht abschalten lässt:
  Darstellungen Minderjähriger (`young`, `cub`, `loli`, `shota`, `child` …) werden nie gesucht, angezeigt oder gepostet –
  auch nicht über `/nsfw` oder Auto-Posts.
- **Privatsphäre**: Bilder optional über den eigenen Server laden (signierter Proxy, nur bekannte Bilder-Hosts).
  **Panik-Taste** `⇧ + P` springt sofort zur Startseite.

## Con-Rechner (`con.php`)

- **Mehrere Cons** gleichzeitig (Auswahl oben), mit Icon, Datum, Ort, Webseite, Hotel, Status (in Planung / gebucht / läuft / vorbei),
  eigenem Budget und **Fremdwährung** samt Kurs (z. B. Anthrocon in USD). Die zuletzt geöffnete Con erscheint in der Index-Kachel.
- **Übersicht**: Budget, meine Kosten, bezahlt, offen (mit Fälligkeit), geplant, Rest, Rest pro Con-Tag, **Sparziel** pro Monat/Woche,
  Budget-Balken, Donut nach Kategorie, **Budget je Kategorie**, offene Zahlungen, Ausgaben pro Tag, Schnellerfassung.
- **Ausgaben** mit Status *geplant / gebucht-offen / bezahlt*, Fälligkeit, Zahlart, Währung, Notiz (mit Formatierung); Filter, Sortierung, Summe, CSV.
- **Gruppe & Abrechnung**: Mitreisende anlegen, bei jeder Ausgabe „bezahlt von“ und „geteilt mit“ – daraus berechnet die Seite
  Anteile, Salden und die **Ausgleichszahlungen „wer schuldet wem“** (mit möglichst wenigen Überweisungen).
- **Rechner** (live, Ergebnis mit einem Klick als Ausgabe übernehmen): Zimmer teilen (inkl. Kurtaxe), Auto/E-Auto,
  Bahn/Flug, Tagesbudget vor Ort, Sparplan, Währungsrechner (mit Kartengebühr), Rechnung teilen mit Trinkgeld, Dealers-Den-Limit.
- **Zeitplan** nach Tagen (Con-Tag 1, 2 …), ★ Favoriten, Export als `.ics` fürs Handy.
- **Packliste** mit Vorlagen (Dokumente, Technik, Kleidung, Fursuit, Hygiene, Con-Kram), abhaken ohne Neuladen, für die Rückreise zurücksetzen.
- **Wunschliste** für den Dealers Den mit Priorität und Preis; „✓ Gekauft“ trägt sie automatisch als Ausgabe ein.
- **Notizen** mit Formatierung, Hotel mit Kartenlink.
- **Vergleich** aller Cons: Kosten je Con nach Kategorien, Budget-Linie, Ø pro Con und pro Tag, Tabelle.
- **⧉ Als Vorlage**: Con fürs nächste Jahr kopieren (Ausgaben als „geplant“, Daten +1 Jahr, Packliste zurückgesetzt).
- Verbunden mit **Countdown** (⏳) und **Kalender** (📅, privater Termin) – mit den jeweiligen Rechten.
- Alte Daten werden automatisch übernommen: die bisherige Con und alle Archive (`archive/con_*.json`) als „vorbei“.
  Eine Sicherung des alten Formats bleibt als `con_data.json.v1-backup` liegen.

## Admin-Zentrale

Übersicht (Kennzahlen inkl. „gerade online“ und 2FA-Quote, Aktivitäts-Diagramm 14 Tage, letzte Ereignisse, Team,
**📢 Ankündigungs-Banner** für alle / nur Angemeldete / nur Besucher mit Stil, Ablaufzeit und „wegklickbar“),
Benutzer, Rollen & Rechte (Matrix), Einladungen, Sitzungen, Sicherheit, Seiten, Protokoll (Suche, Stufe, **Benutzer,
Zeitraum**, CSV-Export des gefilterten Ergebnisses, IP direkt sperren), System (Seitentitel, **Wartungsmodus – auch
geplant mit Start/Ende**, Systemstatus, Speicherbelegung, Werkzeuge inkl. Feeds neu laden und Datenbank optimieren), Mein Konto.
Wer nur das Recht `widgets.manage` hat, bekommt auf der Startseite den Knopf „🧩 Widgets“ zum Anordnen. **Befehlspalette** mit `Strg+K`. Auf dem Handy wird die Seitenleiste zur Reiterleiste.

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

## Budget (`budget.php`)

- **📊 Übersicht**: Kennzahlen mit Vergleich zum Vormonat (Einnahmen, Ausgaben, Netto mit Sparquote, offen, **pro Tag übrig**),
  gestapelte Ausgaben-Quote (Fixkosten / Abos / Sonstige / übrig), **Donut-Diagramm nach Kategorie** (Klick filtert die Liste),
  Kategorie-Limits, „Demnächst fällig“ (inkl. Kündigungsfristen) und Sparziele auf einen Blick.
- **Buchungen**: Schnell-Eingabe mit Datum und Kategorie-Vorschlägen (Taste `N`), Suche (Taste `/`), Filter nach Kategorie und Status,
  Sortierung, einklappbare Bereiche, Icons je Kategorie, Herkunft (📄 CSV / 🏦 Bank / ↻ Vorlage), ✓ abhaken, „alle bezahlt“,
  duplizieren, bearbeiten (auch Datum), ⚙ **Regel für ähnliche Buchungen** direkt aus einer Buchung.
- **🪄 Kategorien neu erkennen**: ordnet importierte Buchungen mit der verbesserten Erkennung (Supermärkte, Tankstellen, Streaming,
  Gaming, Lieferdienste, Mobilität, Online-Shopping, Software …) und deinen Regeln neu ein.
- **👁 Privat-Modus** (Taste `P`): verwischt alle Beträge – z. B. beim Bildschirm teilen.
- **📈 Statistik**: Balkendiagramm Einnahmen/Ausgaben/Netto pro Monat (Archiv + laufender Monat), Durchschnitte, bester Monat,
  Kategorien der letzten 6 Monate als Tabelle, „Wo geht das Geld hin?“ (Top-Händler), alle Monate mit Sparquote.
- **🎯 Planung**: **Limits pro Kategorie** (mit Ampel), **Ziel-Sparquote**, **Sparziele** (Fortschrittsring, Einzahlen/Entnehmen mit
  Verlauf, optional als Ausgabe „Sparen“ buchen, nötige Rate pro Monat bis zum Termin) und **Regeln** („Text enthält … → Kategorie,
  Bereich, umbenennen oder beim Import ignorieren“) – gelten für CSV-Import und Bank-Sync.
- **🔁 Fix & Abos**: Rhythmus **monatlich, vierteljährlich, halbjährlich oder jährlich** (wird nur in den fälligen Monaten eingeplant),
  **Fälligkeitstag** (bis dahin „offen“), **Kündbar bis**-Datum mit Warnung, Kosten pro Monat/Jahr und Anteil am Einkommen sowie
  **💡 erkannte wiederkehrende Zahlungen** als Abo-Vorschlag.
- Einstellungen liegen in `budget_settings.json` (vom Backup erfasst, nicht im Repository).

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
