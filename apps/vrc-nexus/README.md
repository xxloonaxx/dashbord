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

Beide gibt es unter GitHub → **Actions → „VRC Nexus App (Windows)“ → neuester Lauf → Artifacts → VRC-Nexus-Windows** (dort liegt auch `VRC-Nexus.streamDeckPlugin`).
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
