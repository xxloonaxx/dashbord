# VRC Nexus – eigenständige Windows-App

Der komplette **VRC Nexus** aus dem Dashboard als eigenes Programm für Windows – **ohne Server, ohne Webspace**.
Alle Daten liegen nur auf deinem PC.

## Was drin ist

Alles aus dem Nexus des Dashboards:

- 👥 **Freunde** mit Status, Ort, Notizen, Listen, Beobachten (Windows-Benachrichtigung, wenn jemand online kommt)
- ⭐ **Favoriten** wie in VRCX (VRChat-Gruppen + eigene lokale Listen + Verlauf)
- 📜 **Verlauf** (online/offline, Ortswechsel, Status, Avatar, Name, Bio, Vertrauen) und 📈 **Statistik** (Spielzeit, Zeitleiste, Begegnungen, Heatmaps)
- 🌐 **Welten & Instanzen** (erstellen, einladen, beitreten), 🎭 **Avatare** inkl. 🔎 **Avatar-Suche** (avtrDB, PAW)
- 🛡️ **Gruppen** mit Moderation, Beiträgen, Rollen, 📅 **Gruppen-Kalender** (Export als .ics)
- 🖼️ **Galerie** (Fotos, Icons, Emojis, Sticker, Prints, Inventar – inkl. Hochladen)
- 🔔 **Benachrichtigungen** (alt + V2, mit Verlauf und Aktionen), 🛒 **Shop**, 🌍 **VRChat weltweit** (Spielerzahl-Kurve)
- 🚫 Moderation, 📰 Info-Push, 🔍 Suche, Profil & Status bearbeiten
- 🎮 **Spiel & OSC** – direkt eingebaut (vorher separater Client):
  - liest die VRChat-Logs mit (Welten, wer kam/ging, Avatare, Videos, Screenshots, Einladungen) → Spiel-Log, „Getroffen“, Besuche
  - OSC-Fernbedienung: Chatbox, Tipp-Anzeige, Avatar-Parameter, Eingaben, Schnellknöpfe, **Chatbox-Rotator**
- Läuft im **Infobereich** weiter (Fenster schließen = minimieren), auf Wunsch **Start mit Windows**

### Neu in 1.3.6
- **📈 Instanz-Verlauf** (Diagramme → Rückblick): Spielerzahl einer Instanz über die Zeit als Diagramm, Höchststand, Schnitt, verschiedene Leute, wer wann kam und ging – für jeden Weltbesuch der letzten 30 Tage.
- **🌍 Meine Welten:** Besuche, Favoriten, Spieler gerade, Heat/Beliebtheit deiner hochgeladenen Welten – mit „seit gestern“, „7 Tage“ und Verlaufslinie (täglich mitgeschrieben, sobald du die Seite öffnest).

### Neu in 1.3.5
- **Update mit Fortschrittsbalken:** Prozent, MB, Geschwindigkeit und Restzeit im Banner oben und unter Einstellungen → System; danach „wird installiert“. Unter Windows zusätzlich am Taskleisten-Symbol.
- **Android lädt Updates selbst** (mit Balken) und öffnet danach direkt die Installation. Beim ersten Mal fragt Android, ob VRC Nexus „unbekannte Apps installieren“ darf. Klappt das nicht, öffnet sich wie bisher der Browser-Download.

### Neu in 1.3.4
- **📜 OSC-Log** (Spiel & OSC → OSC-Log): schreibt **alles** mit, was über OSC von VRChat kommt und an VRChat geht – jede Änderung mit Zeit, Avatar, altem → neuem Wert.
  Kategorien zum An-/Abwählen: Avatarwechsel, Schalter, Zahlen, Regler, Gesten & Stimme, Bewegung & Haltung, Status (AFK, Stumm …), Kamera, Chatbox, Eingaben, Tracking, Sonstiges.
  Live-Ansicht, Zeitraum (Heute / 7 / 30 Tage / alles), Richtung, Avatar, Suche, „Am häufigsten“, CSV-Export. Gespeichert als Tagesdateien im Datenordner (`osc-log`), Aufbewahrung und Drosselung für Dauer-Werte unter Einstellungen → VR → OSC-Log.
- Android-APK mit festem Signierschlüssel: Updates lassen sich über die installierte App installieren (einmalig alte Version deinstallieren).

### Neu in 1.3.2
- **📸 Foto-Verlauf** (Diagramme → Rückblick → Foto-Verlauf): deine Screenshots nach Weltbesuch sortiert, mit wem du dort warst, Vorschaubilder, Klick öffnet das Foto.
- **Beobachtete Freunde:** zusätzlich Meldung, wenn sie „Join Me“ setzen oder in eine öffentliche Instanz gehen (Einstellungen → Benachrichtigungen).

### Neu in 1.3.1
- **🛡 Mod-Zentrale** (Sozial → Mod-Zentrale, für Gruppen mit Kick-Rechten): Beitrittsanfragen-Assistent mit Vertrauensstufe, Kontoalter, 18+, Freund?, schon getroffen?, Warnliste und Vorschlag (annehmen/ablehnen/ansehen); Warnliste mit Notizen; geplante Gruppen-Beiträge; Export von Gruppen-Protokoll + Warnliste als CSV.
- **📚 Sammlungen** (Favoriten → Sammlungen): Welten und Avatare ohne VRChat-Begrenzung, mit Tags, Notiz, Suche und 🎲 Zufall aus einem Tag; Knopf „📚 Sammlung“ in jedem Welt-/Avatar-Fenster; Warnung bei „Very Poor“ auf Quest/Android.
- Datumsanzeige im Rückblick korrigiert.

### Neu in 1.3
- **🏠 Startseite mit Widgets** (Windows + Android): Status, Freunde im Spiel, Instanz, Chatbox, Makros, Benachrichtigungen, Heute, Spielt gerade, Kamera, Lieblings-Parameter, Uhr & Countdown, Notizzettel, Sprache, Overlay, Version – mit „✏️ Anpassen“ ein-/ausblenden, verschieben, breit/schmal.
- **📱 Android-Homescreen-Widget:** Freunde im Spiel/online, ungelesene Benachrichtigungen, die ersten Freunde mit Welt; Tippen öffnet die App. (Aktualisiert sich, solange die App läuft bzw. beim Verlassen der App.)
- **🎚 Parameter-Erkennung:** liest die OSC-Konfiguration jedes Avatars (`LocalLow\VRChat\VRChat\OSC\usr_…\Avatars\avtr_….json`) – Schalter, Regler, Zahlen, Suche, ★ Favoriten, VRChat-eigene ausblendbar.
- **📷 Kamera-Werkzeuge** (VRChat-Kamera per OSC): Modi, Foto, Selbstauslöser, Serienbild, Zoom/Belichtung/Fokus/Blende/Farben, alle Schalter, Pose, Vorlagen.
- **⚡ Makros:** Abläufe aus `chatbox`, `param`, `toggle`, `wait`, `input`, `cam`, `status`, `avatar`, `rotator`, `typing`, `speak`, `notify`, `webhook`.
- **🤖 Automatik / Chat-Widgets:** „Wenn … dann Makro“ – Spieler kommt/geht, Welt, Avatar, AFK an/aus, Parameter = Wert, Uhrzeit, alle N Minuten, VRChat Start/Ende, Screenshot. Vorlagen: Begrüßung, AFK, Spielt gerade, Trinkpause, Gute-Nacht … Neue Platzhalter `{musik}` `{afk}` `{afk_zeit}` `{spieler_neu}`.
- **🎙 Sprache → Chatbox mit Whisper:** lokal mit whisper.cpp (Programm + Modell Tiny/Base/Small/Medium/Large-v3-Turbo werden auf Knopfdruck geladen, läuft offline) oder OpenAI-Whisper mit eigenem Schlüssel (verschlüsselt). Tastenkürzel (Standard Alt+V), Sprechpausen-Erkennung, „tippt …“, Übersetzung.
- **🥽 SteamVR-Overlay:** eigener Reiter im SteamVR-Dashboard und Fenster am Handgelenk oder frei im Raum, Bedienung mit dem Laser: Freunde (beitreten/einladen), Instanz, Hinweise, Chat (Sätze, Emojis, Sprache), Status, Makros, Parameter, Kamera. Einstellungen → VR.
- **🎛 Stream Deck 1.3:** neue Tasten Kamera, Makro, Avatar wechseln, Freund (beitreten/einladen, leuchtet wenn im Spiel), Benachrichtigungs-Zähler, Overlay, Spracheingabe, Drehregler fürs Stream Deck + (Parameter, Zoom, Belichtung).
- **🎁 Rückblick & Freunde-Pflege:** Woche/Monat/Jahr („VRChat Wrapped“), Top-Welten, mit wem am meisten Zeit, Avatare, wann du spielst, AFK-Verdacht; Freundschafts-Jahrestage, Geburtstage (aus der Notiz), lange nicht online, Namensverlauf.
- **📅 Event-Erinnerungen** vor Gruppen-Events, **💛 Jahrestags-Meldungen**, **📺 OBS-Stream-Overlay** (`http://127.0.0.1:17865/obs?token=…`), **🏠 Webhooks** für Home Assistant & Co.

### Neu in 1.2.2 – Auto-Update

- Sucht beim Start und alle 6 Stunden auf GitHub-Releases nach einer neuen Version (Einstellungen → System → Updates; Tray: „Nach Updates suchen“)
- Windows-Installer-Version: lädt das Update, installiert still und startet neu · portable EXE: ersetzt sich selbst und startet neu
- Wahlweise nur Bescheid geben (Banner oben) oder automatisch installieren (nur wenn VRChat gerade nicht läuft)
- Android: Banner „Update verfügbar“ öffnet den Download der neuen APK (installieren muss man selbst bestätigen)

### Neu in 1.2

- 🌐 **Übersetzen mit DeepL oder Google** (eigener API-Schlüssel, verschlüsselt gespeichert): Chatbox-Nachrichten übersetzt senden
  (nur Übersetzung, „Original / Übersetzung“ oder zweizeilig), Text irgendwo markieren → 🌐 bzw. <kbd>Alt</kbd>+<kbd>T</kbd>, auch per Stream-Deck-Taste
- 💬 **Chatbox**: `/n` = neue Zeile, Platzhalter auch in normalen Nachrichten, lange Nachrichten automatisch aufteilen (1/3 …),
  Tipp-Anzeige beim Schreiben, Emoji-/Kaomoji-Leiste, zeitversetzt senden, Chatbox leeren, Verlauf der letzten Nachrichten
- 🔄 **Chatbox-Rotator**: mehrere Texte im Wechsel (der Reihe nach oder zufällig), nur wenn VRChat läuft, Vorschau, neue Platzhalter
  `{freunde}` `{freunde_online}` `{status}` `{statustext}` `{zugang}` `{region}` `{uhr}` `{wochentag}` `{countdown:2026-12-24 18:00}` `{zufall:a|b|c}`
- 🔗 **Verbindung zum Dashboard** (Einstellungen → Dashboard, Geräte-Token aus Nexus → Client): Spiel-Log und Live-Status hochladen,
  Chatbox-/OSC-Befehle aus dem Dashboard ausführen, Memos in beide Richtungen abgleichen
- 📱 **Android → PC**: Die Android-App kann über das Dashboard die Chatbox, Eingaben und Avatar-Parameter am PC steuern („PC-Fernbedienung“)

### Neu in 1.1

- **Menü oben** im Aufbau wie VRCX: Verlauf · Freunde-Orte · Spiel-Log · Spielerliste · Suche · Favoriten ▾ · Sozial ▾ · Benachrichtigungen · Meine Avatare · Welten · Diagramme ▾ · Werkzeuge · Spiel & OSC · Mehr ▾ · Direkt-Zugriff (<kbd>Strg</kbd>+<kbd>D</kbd>)
- 👥 **Spielerliste**: wer gerade in deiner Instanz ist – Zeit, Name, Rang, Status, Symbole (VRChat+, altersverifiziert 18+, Freund, Favorit, Instanz-Besitzer, Early Adopter, VRChat-Team …), Plattform, Sprachen, Bio-Links, VRChat-Notiz und eigenes Memo; dazu, wer gegangen ist
- 📍 **Freunde-Orte**: Freunde nach Instanz gruppiert
- 📊 **Diagramme**: Instanz-Aktivität (Zeitleiste pro Tag), Gemeinsame Freunde (Netz-Grafik), Beliebte Welten, Statistik
- 🧰 **Werkzeuge**: Screenshot-Manager (Welt & Mitspieler im Bild), Ordner-Verknüpfungen (VRChat-Fotos, Steam-Screenshots, Daten, Logs, Absturzberichte),
  Gruppen-Einladungen, blockierte Gruppen, VRChat-`config.json`, Startoptionen (Desktop-Modus, Profil, FPS …), VRChat-Registry-Sicherung, Auto-Status & Einladungen,
  Store & Kaufverlauf, Einladungsnachrichten, eigene Meldungen, Info-Push, Exporte (Discord-Namen, Memos → VRChat-Notizen, Freunde, eigene Avatare)
- ⚙ **Einstellungen** (über 70): System, Oberfläche (Akzentfarbe, Zoom, Schrift, Menü …), Sozial, Benachrichtigungen (Windows, Vorlesen, Nicht-stören),
  VR (XSOverlay, Chatbox), Medien (Screenshot-Infos, Kopien), Integrationen (Stream Deck, Discord-Status, Discord-Webhook), Erweitert
- 🎛 **Stream-Deck-Plugin** (`streamdeck/`): Status setzen, Chatbox-Nachricht/Vorlage, Avatar-Parameter (umschalten/halten/setzen), OSC-Eingaben,
  Chatbox-Rotator, Freunde online & Spieler in der Instanz als Live-Anzeige, VRChat starten, Instanz neu betreten, VRC Nexus öffnen.
  Installation: Werkzeuge → Stream Deck → „Plugin installieren“ (oder `VRC-Nexus.streamDeckPlugin` doppelklicken); dort auch Port und Zugangsschlüssel.

Nicht dabei (braucht das Dashboard): Discord-Teilen und die Kalender-Verbindung zum Dashboard-Kalender.

## Installation

- **VRC-Nexus-…-Setup.exe** – normaler Installer (Startmenü, Deinstallieren), oder
- **VRC-Nexus-…-portable.exe** – ohne Installation, einfach starten.

Beide gibt es unter GitHub → **Releases → „VRC Nexus …“** (neueste Version), zusammen mit der Android-APK und `VRC-Nexus.streamDeckPlugin`.
Jeder Build aktualisiert das Release seiner Version automatisch (zusätzlich liegen die Dateien als Artifacts beim jeweiligen Actions-Lauf).
Windows SmartScreen fragt beim ersten Start nach (die EXE ist nicht signiert): „Weitere Informationen“ → „Trotzdem ausführen“.

## Sicherheit & Daten

- Dein **VRChat-Passwort wird nicht gespeichert** – es geht nur einmal direkt an VRChat (2FA wird unterstützt).
  Gespeichert wird nur das Sitzungs-Cookie, verschlüsselt mit dem Windows-Schlüsselspeicher (DPAPI).
- Datenbank: `%APPDATA%\VRC Nexus\nexus.sqlite` (Tray-Menü → „Datenordner öffnen“). Zum Sichern einfach diese Datei kopieren.
- VRChat wird sparsam abgefragt (Freunde höchstens alle 45 s), die VRChat-API ist inoffiziell.

## Entwickeln

```
npm install          # Abhängigkeiten (Electron, sql.js)
npm start            # App starten
npm test             # Tests (ohne Netzwerk)
npm run dist         # Windows-EXE bauen (portable + Installer) – auf Windows
```

Aufbau: `main.js` (Electron: Fenster, Infobereich, Protokoll `app://`), `backend/` (VRChat-API, Datenbank, Spiel-Log & OSC –
Portierung der PHP-Module des Dashboards nach JavaScript), `renderer/` (Oberfläche, identisch zur Dashboard-Version).
Die Oberfläche spricht weiter „nexus.php“ an – in der App beantwortet das das eingebaute Backend.
`tests/server.js` stellt dieselbe App zum Testen im Browser bereit.
`backend/tools.js` enthält Spielerliste, Werkzeuge, Benachrichtigungen und die lokale Schnittstelle (`backend/localapi.js`, nur 127.0.0.1, mit Zugangsschlüssel),
`backend/settings.js` das Einstellungs-Schema. Das Stream-Deck-Plugin liegt in `streamdeck/` (`npm run streamdeck` packt es, `node tests/streamdeck.test.js <port> <schlüssel>` testet es
gegen eine nachgebaute Stream-Deck-App).
