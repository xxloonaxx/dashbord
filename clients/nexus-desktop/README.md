# GHOST NODE · VRC Nexus Desktop-Client (Windows)

Kleines Programm für den VRChat-PC. Es verbindet VRChat mit dem Dashboard:

| Funktion | Was passiert |
|---|---|
| 📜 **Spiel-Log** | Liest die VRChat-Logdateien mit: betretene Welten (inkl. Instanz-Typ), wer kam/ging (auch Nicht-Freunde), eigener Avatar-Wechsel, Videos, Screenshots, Einladungen/Freundschaftsanfragen, Start/Ende. Alles landet im Nexus unter **🖥️ Client** (Verlauf, „Getroffen“, Weltbesuche mit Dauer, CSV-Export). |
| 📡 **Live-Status** | Aktuelle Welt, Spieler in der Instanz, eigener Avatar und Avatar-Parameter sind im Dashboard sichtbar – auch vom Handy. |
| 🎛️ **OSC-Fernbedienung** | Im Dashboard Chatbox-Text, Tipp-Anzeige, Avatar-Parameter (An/Aus, Zahl, Regler) oder Eingaben (Springen, Mikro …) senden – der Client führt sie in VRChat aus. Schnellknöpfe speicherbar. |
| 💬 **Chatbox-Rotator** | Automatischer Chatbox-Status, z. B. `🕒 {zeit} · 🌐 {welt} · 👥 {spieler}`. |
| 🔒 **Sicher** | Dein VRChat-Passwort wird nicht gebraucht. Der Client meldet sich mit einem **Geräte-Token** an, das du im Dashboard jederzeit entfernen kannst. Ohne Internet sammelt er Ereignisse und lädt sie später hoch. |

## Installation

1. Im Dashboard: **VRC Nexus → 🖥️ Client → 🔑 Geräte & Download** → „Client herunterladen“ und ein **Gerät hinzufügen** (Token kopieren – es wird nur einmal angezeigt).
2. Starten:
   - **EXE:** `GhostNodeNexus.exe` (aus GitHub Actions → „Nexus Desktop-Client“ → Artefakt, oder selbst bauen, siehe unten), **oder**
   - **ohne EXE:** [Python 3.9+](https://www.python.org/downloads/) installieren (Haken bei „Add to PATH“) und `start.bat` doppelklicken.
3. Im Client unter **⚙ Einstellungen** die **Server-Adresse** (z. B. `https://meine-seite.de/`) und das **Token** eintragen → „Speichern & verbinden“.
4. In VRChat: Action-Menü → Optionen → **OSC → Aktiviert**.

## EXE selbst bauen

`build.bat` doppelklicken (installiert PyInstaller, führt die Tests aus und erzeugt `dist\GhostNodeNexus.exe`).

## Kommandozeile

```
GhostNodeNexus.exe --minimized                      minimiert starten (so trägt sich auch der Autostart ein)
python ghostnode_nexus.py --headless                ohne Fenster, Ereignisse in der Konsole
python ghostnode_nexus.py --headless --import-all   alle alten Logs einlesen und hochladen
python ghostnode_nexus.py --headless --once --server https://… --token gnx_… --logdir C:\Pfad
```

## Technik

- Nur Python-Standardbibliothek (tkinter, urllib, socket) – keine Zusatzpakete.
- Logs: `%LOCALAPPDATA%Low\VRChat\VRChat\output_log_*.txt`, die neueste Datei wird wie `tail -f` mitgelesen.
- OSC: senden an `127.0.0.1:9000`, empfangen auf `9001` (Ports einstellbar; läuft schon ein anderes OSC-Programm auf 9001, einfach „Avatar-Parameter mitlesen“ ausschalten).
- Einstellungen & Warteschlange: `%APPDATA%\GhostNodeNexus\`.
- Schnittstelle zum Dashboard: `client.php` (JSON, `Authorization: Bearer gnx_…`).
- Tests: `python -m unittest discover -s tests`
