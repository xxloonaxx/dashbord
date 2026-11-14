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

### Neu in 1.10.6 – Merken im Shop
- **☆ Merken** an jeder Artikelkarte (VRChat-Store und Creator-Shops) – ★ zeigt, was gemerkt ist; nochmal klicken = nicht mehr merken.
- **☆ Shop merken** im Shop-Popup.
- Neuer Bereich **★ Gemerkt** oben im Shop: gemerkte Shops als Schnellzugriff (Klick öffnet das Popup, ✕ entfernt), gemerkte Artikel als Karten mit Preis, Restzeit und Kaufen-Link.
- Gespeichert wird nur lokal in der App (bis zu 200 Artikel und 50 Shops) – nichts geht an VRChat.

### Neu in 1.10.5 – Shop ansehen als Popup
- **🔎 Shop ansehen** steht jetzt ganz oben im Shop; das Ergebnis (Creator- oder Store-Shop) öffnet sich als **Popup** im Store-Stil.
- Klick auf einen Artikel zeigt die Details – mit **← Zurück zum Shop** geht es zurück ins Popup.

### Neu in 1.10.4 – Fix: Info-Push und Meine Meldungen leer
- **Meine Meldungen:** VRChat liefert die Liste seitenweise (`results`, `hasNext`) statt als einfache Liste – deshalb war sie leer. Jetzt werden bis zu 500 Meldungen geladen.
- **Info-Push:** VRChat gibt nur Einträge zurück, deren Tags in `include` stehen; leer = nichts. Jetzt wird eine breite Tag-Liste in beiden Formaten abgefragt und zusammengeführt; die Ansicht springt zur ersten Kategorie mit Einträgen. Bleibt es leer, steht klein darunter, was VRChat auf jede Abfrage geantwortet hat.
- Chips (z. B. *Ganzer Verlauf*): Überfahren mit der Maus sieht nicht mehr aus wie „ausgewählt“.

### Neu in 1.10.3 – Fix: „Spielt gerade“ erkennt jetzt wirklich, was läuft
- Fix: Die Windows-Mediensteuerung meldete nie, was gerade spielt (nur ⏮ ⏯ ⏭ gingen). Ursache: Windows PowerShell wartete beim Lesen der Befehle blockierend – jetzt kommen Befehle über eine kleine Datei im Temp-Ordner, die Erkennung läuft durchgehend (z. B. YouTube Music in Brave/Chrome/Edge/Firefox, Spotify …).
- Startet die Mediensteuerung noch oder meldet einen Fehler, zeigt die Kachel das jetzt an.
- Der PowerShell-Helfer beendet sich selbst, wenn die App geschlossen wird.
- Der Windows-Build testet das echte Skript jetzt mit.

### Neu in 1.10.2 – „Spielt gerade“ über die Windows-Mediensteuerung
- **🎵 Spielt gerade** nutzt jetzt die **Windows-Mediensteuerung** – erkannt wird alles, was Windows im Medien-Popup zeigt: Spotify, **Browser (YouTube, SoundCloud …)**, Apple Music, TIDAL, Deezer, VLC, Medienwiedergabe … Ältere Player ohne Mediensteuerung werden weiter über den Fenstertitel erkannt.
- Startseiten-Kachel neu: Titel, Künstler, App, Status, **Fortschrittsbalken** mit Zeit und Knöpfe **⏮ ⏯ ⏭**.
- Neue Platzhalter für Rotator, Chatbox-Modul und Automatik: `{musik_titel}` `{musik_kuenstler}` `{musik_app}` `{musik_zeit}` (z. B. 1:18/3:44). Pausiert = „spielt“ nichts.
- Abschaltbar unter *Einstellungen → Medien → Spielt gerade*.
- **🧭 Direkt-Zugriff** sitzt jetzt oben neben ⚙ Einstellungen statt in der Menüleiste.
- Fix: In den Einstellungen leuchtete „Mehr“ fälschlich mit.

### Neu in 1.10.1 – Store durchsuchen, NEU bei Info-Push
- **🛒 Shop:** Suchfeld und Sortierung im VRChat-Store (*Empfohlen, Endet bald, Preis auf-/absteigend, Name A–Z*) – Regale ohne Treffer werden ausgeblendet.
- **📰 Info-Push:** Einträge, die seit deinem letzten Besuch neu sind, tragen ein **NEU**-Kennzeichen; die Reiter zeigen, wie viele neu sind, und springen automatisch zur Kategorie mit Neuem.

### Neu in 1.10.0 – Info-Push & Meldungen in den Benachrichtigungen, Shop im Store-Stil
- **🔔 Benachrichtigungen** haben jetzt vier Ansichten: *Offen bei VRChat*, *Ganzer Verlauf*, **📰 Info-Push** und **🚩 Meine Meldungen** (Info-Push ist aus „Mehr“ hierher umgezogen; alte Links/Kacheln führen automatisch hin).
- **Info-Push** neu: Reiter *Hilfe-Artikel / Quick-Menü-Banner / Ladebildschirme* mit Anzahl, Zeilen mit 16:9-Vorschaubild, Titel, Untertitel und Datum – mit **›** direkt in der Liste aufklappen (Artikel mit Formatierung: Überschriften, **fett**, Links, Listen).
- **Meine Meldungen** neu: Filter nach Art (Nutzer, Avatar, Welt, Gruppe, Sticker …) mit Anzahl, Vorschaubild, Name + Art-Kennzeichen, *Kategorie · Grund*, deine Beschreibung, Datum, Status und 🗑 Zurückziehen.
- **🛒 Shop** im Stil des VRChat-Stores: große **Banner-Regale** und Artikelkarten mit **Restzeit** („Noch 35 Tage“), Art (Portal, Prop, Bundle, Accessoire, Ladebildschirm …), Preis **mit und ohne VRC+** und **Kaufen ↗** – öffnet die VRChat-Website, gekauft wird bewusst nicht in der App. Klick aufs Bild zeigt Details.
- Der offizielle Store wird automatisch über die VRChat-Konfiguration gefunden; sonst Store-ID unter *Einstellungen → Soziales → Shop* eintragen. Auch „Shop ansehen“ (Creator/Store-ID) nutzt die neue Darstellung.
- Übersetzung: „Nutzer (3)“-artige Texte mit Zahl in Klammern werden jetzt übersetzt; neue Texte in EN/FR/JA.

### Neu in 1.9.2
- Spracheingabe: der **📜 Verlauf** steht jetzt rechts oben neben den anderen Karten (statt unten über die ganze Breite) und nutzt die volle Höhe.

### Neu in 1.9.1 – ℹ️ Über-Seite mit Changelog
- Neu unter **Mehr → ℹ️ Über VRC Nexus**: installierte Version, System, Update-Status (mit „Nach Updates suchen“), **Changelog der letzten 2 Versionen** (ältere auf Knopfdruck, alle Releases auf GitHub), Datenordner und Danksagungen.
- Der Changelog kommt aus dieser README (`node scripts/changelog-build.js` → `backend/changelog.json`); ein Test prüft, dass er zur Version passt.

### Neu in 1.9.0 – 🔊 Sprachausgabe (TTS) – in VRChat sprechen
Unter **Spiel & OSC → 🔊 Sprachausgabe**. Die Stimme wird auf einem wählbaren **Ausgabegerät** abgespielt – mit einem virtuellen Audiokabel (z. B. VB-Audio Virtual Cable: hier „CABLE Input“, in VRChat „CABLE Output“ als Mikrofon) hört VRChat sie wie dein Mikrofon. Optional **mithören**.
- **Anbieter:** 🪟 Windows-Stimmen (offline), 🐦 **Piper** (offline, neuronal, Programm + Stimmen per Knopf: Thorsten, Kerstin, Eva, Amy, Alba, Siwis …, eigene .onnx möglich), 🔵 Google Cloud TTS, 🟦 Microsoft Azure, 🎙 ElevenLabs, ⚪ OpenAI – Schlüssel verschlüsselt; Google/OpenAI nutzen auf Wunsch die schon hinterlegten Schlüssel von Übersetzer bzw. Spracheingabe.
- **Wann:** nach der Spracheingabe (Übersetzung, nur Original oder beides – z. B. Deutsch sprechen → Englisch in VRChat hören lassen), bei getippten Chatbox-Nachrichten, per Makro-Schritt `speak Text`, oder direkt über das Textfeld (optional auch in die Chatbox).
- Stimme, Tempo und Lautstärke einstellbar; Stimmenliste kommt live vom Anbieter.

### Neu in 1.8.1 – Spracheingabe: Sprachaktivierung & Verlauf
- **👂 Sprachaktivierung:** Mikro bleibt offen, sobald du sprichst wird aufgenommen (inkl. 0,4 s davor), nach der Pause erkannt und gesendet. Kurze Geräusche (unter „mind. … s Sprache“) werden ignoriert. Optional **nur wenn du in VRChat stumm bist** (ideal als Mute-Spieler) und nur wenn VRChat läuft; jederzeit pausierbar. Umschalten oben in der Karte: „🎙 Taste / Knopf“ ↔ „👂 Sprachaktivierung“.
- **📜 Verlauf:** jede Erkennung mit Uhrzeit, Original und Übersetzung (+ Zielsprache), Modell und Dauer; durchsuchen, Original oder Übersetzung erneut senden, kopieren, löschen (bis 500 Einträge, abschaltbar).
- Fix (Tests unter Windows): GPU-/Netzwerk-Nachbildung für die Chatbox-Module läuft jetzt auch im Windows-Build.

### Neu in 1.8.0 – 🧩 Chatbox-Module
Unter **Spiel & OSC → 🧩 Chatbox-Module** setzt VRC Nexus die Chatbox aus Bausteinen zusammen (eigene Umsetzung, inspiriert von MagicChatbox):
- **💬 Persönlicher Status** (Rotator-Texte, bei AFK „💤 AFK seit …“), **🪟 Fenster-Aktivität** (Vordergrund-Programm, Programme als privat markierbar, in VR „🥽 In VR“), **🎵 Musik**, **🌐 VRChat-Radar**, **🕒 Uhrzeit + Zone**,
  **⛅ Wetter** (Open-Meteo, Ort suchen, ohne Konto), **🖥 Rechner** (CPU, RAM, GPU/VRAM/Temperatur über nvidia-smi), **📶 Netzwerk** (↓/↑, Spitze, Summe), **🟣 Twitch** (Live, Zuschauer, Spiel), **💓 Puls** (Pulsoid-Token verschlüsselt, Puls auch als Avatar-Parameter).
- Jedes Modul: an/aus, eigene Vorlage mit Platzhalter-Knöpfen (zeigen den aktuellen Wert), **Desktop + VR / nur Desktop / nur VR** (SteamVR wird erkannt), Reihenfolge ▲▼.
- **Live-Vorschau** im Chatbox-Stil mit Zeichenzähler; wird es länger als 144 Zeichen, fallen ganze Module am Ende weg (statt mitten im Text abzuschneiden). Module ohne Wert (keine Musik, Twitch offline, keine NVIDIA-Karte) verschwinden automatisch.
- Senden alle N Sekunden (Trennung: neue Zeile, ·, |), optional nur wenn VRChat läuft; der Rotator pausiert solange. Alle Werte gibt es auch als Platzhalter in Rotator, Makros und Automatik (`{temp}`, `{cpu}`, `{puls}`, `{netz_down}`, `{fenster}` …).
- Fix: Beim Öffnen einer Unterseite ohne eigenen Menüeintrag wird im Menü der richtige Bereich markiert.

### Neu in 1.7.9 – Stream Deck: Freund-Taste
- **Kurz drücken:** beitreten – oder, wenn die Instanz privat ist, automatisch eine Einladung anfragen (einstellbar: beitreten, anfragen, zu mir einladen, Selbst-Einladung, Profil öffnen).
- **Gedrückt halten (½ s):** zu mir einladen (ebenfalls einstellbar).
- **Symbol = Profilbild** des Freundes mit Statusring (grün = im Spiel, blau = Website/App, grau = offline, dann auch Bild in Grau) und Status-Punkt (Join Me / Online / Ask Me / Beschäftigt); Titel wählbar (Name + Welt, nur Name, kein Text).

### Neu in 1.7.8
- **Übersetzung vervollständigt:** Welt-, Avatar-, Instanz- und Gruppen-Fenster, Verlauf, Spielerliste, Hinweise und Leer-Meldungen sind jetzt übersetzt (Wörterbuch fast 2.000 Einträge, alle Texte aus dem Quellcode abgeglichen).
- **Fix Update:** „Im Release fehlt die passende Datei (Setup)“ – die App bietet ein Update erst an, wenn die Datei für dein Gerät wirklich hochgeladen ist (Android und Windows werden nacheinander gebaut).

### Neu in 1.7.7
- ⚙ **Einstellungen** sind jetzt ein eigener Knopf oben rechts neben der Profilkarte (statt im Mehr-Menü); leuchtet, solange die Einstellungen offen sind.

### Neu in 1.7.6
- Spracheingabe robuster: Lehnt das Audiosystem 16 kHz ab, wird mit der Standardrate aufgenommen und für whisper.cpp auf 16 kHz umgerechnet (vorher: Aufnahme startete nicht bzw. verzerrte Audio).

### Neu in 1.7.5
- **Fix Spracheingabe (whisper.cpp):** Neuere whisper.cpp-Pakete enthalten ein veraltetes `main.exe`, das nur eine Warnung ausgibt und mit Fehler endet – VRC Nexus nahm es statt `whisper-cli.exe` („Command failed …“). Jetzt wird immer `whisper-cli.exe` genommen (auch wenn in den Einstellungen noch `main.exe` steht), und Fehlermeldungen nennen die eigentliche Ursache (veraltet, fehlende DLL, zu langsam, …).

### Neu in 1.7.4
- Android-Homescreen-Widget zeigt „im Spiel / online / Stand“ in der gewählten App-Sprache.

### Neu in 1.7.3
- Rückmeldungen und Fehlermeldungen (Toasts wie „Gespeichert.“, „Regel gespeichert.“) sind jetzt ebenfalls übersetzt – Wörterbuch über 1.500 Einträge.

### Neu in 1.7.2
- Startseite: Widget „Freunde im Spiel“ zeigt in Größe L/XL Profilbilder.
- Übersetzer ruht bei Deutsch (weniger Arbeit bei jedem Neuzeichnen).

### Neu in 1.7.1
- 🌗 Theme **Automatisch**: hell/dunkel wie Windows bzw. Android, wechselt live mit.
- Daten und Zahlen im Format der gewählten Sprache; SteamVR-Overlay ist mit übersetzt und folgt dem Hauptfenster.
- Wörterbuch erweitert (über 1.400 Einträge), mehrzeilige und mehrsätzige Texte werden erkannt; Test prüft das Wörterbuch.
- Barrierefreiheit: deutlich sichtbarer Tastatur-Fokus, ARIA-Angaben am Sprach-/Theme-Menü, weniger Bewegung bei „Bewegung reduzieren“.

### Neu in 1.7.0 – Sprachen, Themes, Bilder
- **🌐 Sprachen:** Deutsch, English, Français, 日本語 – umschalten über die **Flagge unten rechts** (oder Einstellungen → Oberfläche). Übersetzt die ganze Oberfläche, auch alles, was später nachgeladen wird; Namen, Chats und Bios bleiben unverändert. Wörterbuch: `renderer/i18n/*.tsv` (eine Zeile pro Text, `#` = Zahl), daraus baut `node scripts/i18n-build.js` die Datei `renderer/js/i18n-dict.js`.
- **🎨 Themes:** 🌃 Neon (Standard), 🌌 Mitternacht (dunkel, violett), ❄️ Schnee (hell), 🌸 Sakura (hell) – über 🎨 unten rechts oder in den Einstellungen. Eigene Akzentfarbe geht weiterhin.
- **🖼 Bilder repariert:** Profil-, Avatar- und Weltbilder von VRChat werden jetzt mit deiner Anmeldung über die App geladen (mit Zwischenspeicher) – vorher erschien oft nur der 🦊-Platzhalter. Gleicher Fix im Dashboard.
- Fehler behoben: Die Seite konnte bei ungewöhnlichen Bildadressen einfrieren (z. B. in der Sammlung).

### Neu in 1.6.0 – Startseite: Widget-Raster wie im Dashboard
- **Raster mit 4 Spalten** (passt sich an: 3 / 2 / 1 Spalte je nach Breite, auch auf Android) im Mauerwerk-Stil: Widgets rutschen lückenlos nach oben, gleichmäßige Abstände.
- **✏️ Anpassen:** sichtbares Raster mit Spalten-Hilfslinien, Widgets **per Ziehen** sortieren (oder ◀ ▶), **Größe S / M / L** und bei passenden Widgets **XL (ganze Breite)**; Widgets einklappen (▾); Hinzufügen als Kacheln mit Beschreibung und möglichen Größen.
- Größere Widgets zeigen mehr (z. B. mehr Freunde, Stunden-Diagramm bei „Letzte 7 Tage“ ab L).
- **8 neue Widgets:** 🚀 Schnellstart (VRChat starten, neu beitreten, Selbst-Einladung), 📈 Letzte 7 Tage (Spielzeit je Tag/Uhrzeit), 📅 Gruppen-Events, 🎂 Jahrestage, 🗂 Sammlung, 📺 Stream-Bot (Status, Chat, Punkte-Bestenliste, Umfrage), 📜 OSC-Live, 🤖 Automatik-Regeln an/aus.
- Alte Anordnungen werden übernommen (breit → M).

### Neu in 1.5.0 – Stream-Bot: Befehls-Baukasten & viele neue Funktionen
- **🧱 Befehls-Baukasten:** jeder Befehl kann eine **VRChat-Aktion** ausführen (Avatar-Parameter an/aus oder auf einen Wert, Chatbox, Kamera, Eingaben, Vorlesen, Webhook) – mit Werten aus dem Chat (`!farbe 3` → `param Color = {1}`), Rechten (alle / Abonnenten & Mitglieder / VIPs / Mods / nur ich), Kosten in Punkten, Pause gesamt und pro Person, Aliasen, Hilfetext, an/aus, Nutzungszähler, Vorlagen und „Ausprobieren“.
- **⭐ Ereignisse:** Abo, Abo-Verlängerung, Geschenk-Abos, Raid, Bits (Twitch), Super Chat & neue Mitglieder (YouTube), „Erster im Chat“ – jeweils mit Chat-Antwort und VRChat-Aktion, Mindestmenge, Test-Knopf.
- **⏰ Timer-Nachrichten** (nur wenn der Chat aktiv ist).
- **🎲 Spiele:** `!gamble`, `!münze`, `!slots`, `!duell`/`!annehmen` – Einsätze und Pausen einstellbar.
- **📊 Umfragen** (`!umfrage`, `!stimme`, auch im OBS-Chat-Overlay), **🎁 Verlosungen** (Stichwort, Kosten, Gewinner ziehen), **📝 Zitate**, **🔢 Zähler** (z. B. `!tode`, Mods zählen hoch).
- `!zuschauzeit`, `!uptime`, `!so` (Shoutout), `!addpunkte alle`, `!tts` (Vorlesen am PC gegen Punkte).

### Neu in 1.4.2
- Fehler behoben: Das OBS-Chat-Overlay (`/chat`) konnte leer bleiben, wenn OBS einen Origin-Kopf mitschickt.
- Neue automatische Tests für Stream-Bot (Twitch-Zeilen, Punkte, Mod-Rechte, Belohnungen), Cloud-Sicherung (Verschlüsselung, falsches Passwort, Manipulation) und OBS-Zugriff.

### Neu in 1.4.1
- **☁ Cloud-Sicherung ins eigene Dashboard** (Einstellungen → Dashboard): App-Daten werden gepackt und mit **AES-256 verschlüsselt** (Schlüssel aus deinem Sicherungs-Passwort) und in Stücken hochgeladen – der Server sieht nur unlesbare Daten. Automatisch täglich möglich, die letzten N Sicherungen bleiben. „Wiederherstellen“ prüft die Sicherung und spielt sie beim nächsten Start ein (die alte Datei bleibt als Reserve).

### Neu in 1.4.0 – Stream-Bot für Twitch & YouTube
- **📺 Stream-Bot** (Menü → Stream-Bot): verbindet Twitch-Chat (lesen + schreiben, OAuth-Token) und YouTube-Livechat (lesen, API-Schlüssel) – Schlüssel verschlüsselt gespeichert.
- **🏆 Punkte** pro Nachricht (mit Abklingzeit) und fürs Zuschauen; `!punkte`, `!top`, `!geben`, Mods: `!addpunkte`, `!setpunkte`; Rangliste mit +/−/setzen.
- **⌨️ Befehle:** `!welt`, `!spieler`, `!avatar`, `!song`, `!befehle`, `!belohnungen` + eigene Befehle mit Platzhaltern, Abklingzeit, „nur Mods“.
- **🎁 Belohnungen:** Zuschauer lösen mit Punkten **VRChat-Aktionen** aus – Avatar-Parameter, Chatbox, Kamera, Eingaben, Webhooks (Makro-Schritte), mit Vorlagen.
- Begrüßung neuer Zuschauer, Stream-Chat optional in die VRChat-Chatbox, **OBS-Chat-Overlay** (`/chat`).
- **OBS-Overlay repariert:** läuft jetzt auch ohne Stream-Deck-Schnittstelle und mit dem OBS-Browser; fertige Adressen mit Kopier-Knopf unter Einstellungen → Integrationen.

### Neu in 1.3.9
- **🎤 Mikrofon-Auswahl für die Spracheingabe** (Spiel & OSC → Sprache): alle Mikrofone zur Auswahl (z. B. das vom VR-Headset), Verstärkung, Empfindlichkeit und „🔊 Mikro testen“ mit Pegelbalken. Ist das gewählte Mikro gerade nicht da, wird es am Namen wiedergefunden – sonst das Windows-Standardmikrofon.

### Neu in 1.3.8
- **SteamVR-Overlay blockiert VRChat nicht mehr:** Das Fenster am Handgelenk/im Raum ist nur noch bedienbar, wenn du es **direkt anschaust** – sonst wird es halb durchsichtig und der Laser geht an VRChat (abschaltbar: Einstellungen → VR).
- **Controller-Toggle:** linke obere Taste (Index: B, Quest/Rift: Y, Vive/WMR: Menü) **doppelt drücken** blendet das Overlay ein/aus. Umbelegen in SteamVR → Einstellungen → Controller → Controller-Belegung verwalten → „VRC Nexus“.
- Das Fenster startet jetzt standardmäßig ausgeblendet.

### Neu in 1.3.7
- **Fehler behoben: SteamVR-Overlay ließ sich nicht mit dem Controller bedienen.** Die Laser-Position kam als 0…1 statt in Pixeln an – jeder Klick landete in der Ecke. Zusätzlich zeigt der Overlay-Status jetzt die letzte Laser-Eingabe (Startseite → Widget „SteamVR-Overlay“), damit man sieht, ob Klicks ankommen.

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

## Windows-Dateien signieren (gegen die SmartScreen-Warnung)
Ohne Signatur zeigt Windows „Der Computer wurde durch Windows geschützt – Unbekannter Herausgeber“. Der Build signiert automatisch, sobald im Repository unter *Settings → Secrets and variables → Actions* eines davon angelegt ist (Werte nie in den Code schreiben):

**A) Azure Trusted Signing** (ca. 10 $/Monat, Identitätsprüfung bei Microsoft)
1. Im Azure-Portal ein *Trusted Signing Account* anlegen, Identität prüfen lassen, ein *Certificate Profile* („Public Trust“) erstellen.
2. Eine App-Registrierung (Entra ID) mit Client-Secret anlegen und ihr auf dem Account die Rolle *Trusted Signing Certificate Profile Signer* geben.
3. Secrets: `AZURE_TENANT_ID`, `AZURE_CLIENT_ID`, `AZURE_CLIENT_SECRET`, `AZURE_SIGN_ENDPOINT` (z. B. `https://weu.codesigning.azure.net/`), `AZURE_SIGN_ACCOUNT` (Account-Name), `AZURE_SIGN_PROFILE` (Profil-Name), `AZURE_SIGN_PUBLISHER` (Name genau wie im Zertifikat).

**B) Code-Signing-Zertifikat** (PFX von einer Zertifizierungsstelle)
- Secrets: `WIN_CSC_LINK` = die PFX-Datei als Base64 (`[Convert]::ToBase64String([IO.File]::ReadAllBytes("cert.pfx"))`), `WIN_CSC_KEY_PASSWORD` = Passwort.

Der Build prüft danach jede EXE mit `Get-AuthenticodeSignature` und bricht bei ungültiger Signatur ab; die Release-Notizen sagen dann „digital signiert“. Hinweis: Auch signiert verschwindet die SmartScreen-Warnung erst, wenn genug Installationen zusammengekommen sind („Reputation“) – der Herausgeber steht aber sofort mit Namen da.

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
