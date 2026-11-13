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

Nicht dabei (braucht das Dashboard): Discord-Teilen und die Kalender-Verbindung zum Dashboard-Kalender.

## Installation

- **VRC-Nexus-…-Setup.exe** – normaler Installer (Startmenü, Deinstallieren), oder
- **VRC-Nexus-…-portable.exe** – ohne Installation, einfach starten.

Beide gibt es unter GitHub → **Actions → „VRC Nexus App (Windows)“ → neuester Lauf → Artifacts → VRC-Nexus-Windows**.
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
