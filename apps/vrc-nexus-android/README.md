# VRC Nexus für Android

Der VRC Nexus als Android-App – eigenständig, **ohne Server** und **ohne die PC-Funktionen**.

## Was drin ist

Freunde (Liste, Freunde-Orte, Verlauf, Statistik), Favoriten, Suche, Welten, Meine Avatare inkl. Avatar-Suche, Gruppen mit Moderation
und Kalender, Benachrichtigungen, Shop & Käufe, VRChat weltweit, Gemeinsame Freunde (Netz), Galerie & Inventar, Info-Push,
Profil-Dialog mit Reitern (Gemeinsame Freunde, Gruppen, Welten, Lieblingswelten, Avatare, Aktivität, JSON),
Werkzeuge (Gruppen-Einladungen, blockierte Gruppen, Einladungsnachrichten, Meldungen, Exporte), Einstellungen.
Benachrichtigungen kommen als Android-Benachrichtigung, Exporte (CSV/ICS/JSON) über „Teilen“.

**Neu:** 🎮 **PC-Fernbedienung** – über das Dashboard die Chatbox (auch übersetzt), Eingaben und Avatar-Parameter am PC steuern
(Einstellungen → 🔗 Dashboard: Adresse + Geräte-Token). 🌐 Übersetzen mit DeepL/Google (Text markieren → 🌐), Memo-Abgleich mit dem Dashboard.

**Nicht dabei** (braucht den PC mit VRChat): Spiel-Log, Spielerliste, Instanz-Aktivität, OSC & Chatbox, Stream Deck,
Screenshot-Manager, Ordner, VRChat-`config.json`, Startoptionen, Registry-Sicherung, Auto-Status, Discord-Status, XSOverlay.

## Installation

GitHub → **Releases → „VRC Nexus …“** (neueste Version) → `VRC-Nexus-…-android.apk`
aufs Handy kopieren und öffnen. Android fragt einmal nach „Installation aus unbekannten Quellen“ (die APK ist nicht im Play Store).

## Daten & Sicherheit

- Alles bleibt auf dem Handy (privater Speicher der App). Das VRChat-Passwort wird nicht gespeichert.
- Das Sitzungs-Cookie verwaltet Android selbst (natives HTTP); die App liegt in der Android-Sandbox, andere Apps kommen nicht heran.
- Aktualisiert wird, solange die App offen ist (Android beendet Apps im Hintergrund).

## Technik

Capacitor 7: `src/boot.js` startet dasselbe Backend wie die Windows-App (`apps/vrc-nexus/backend`) im WebView;
Node-Module werden durch kleine Ersatzmodule (`src/shims/`) ersetzt, die Datenbank (sql.js) liegt in IndexedDB.
Anfragen an VRChat gehen über das native HTTP von Android (CapacitorHttp) – mit Cookies und ohne CORS-Probleme.

```
npm install
npm run build                                   # www/ bauen
node tests/server.js 8120 <API-Adresse>         # im Desktop-Browser testen (mit nachgebautem nativen HTTP)
npx cap add android && npm run apk              # APK bauen (Android SDK + Java 21 nötig)
```
