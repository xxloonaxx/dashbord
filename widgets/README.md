# Widgets (Plug & Play)

Jeder Ordner hier ist ein Widget für die Startseite. Das Dashboard findet sie automatisch –
einfach einen Ordner hineinkopieren, und das Widget erscheint unter **Startseite → ✎ Bearbeiten → ＋ Widget**.

```
widgets/
├── _vorlage/        ← Vorlage für eigene Widgets (wird ignoriert, weil sie mit _ beginnt)
│   ├── widget.php   ← Pflicht: Name, Icon, Einstellungen, render()
│   ├── widget.css   ← optional, wird automatisch geladen
│   └── widget.js    ← optional, wird automatisch geladen
├── clock/           ← Uhr & Datum
├── weather/         ← Wetter (Open-Meteo, ohne API-Schlüssel)
├── notes/           ← Schnellnotiz
├── todo/            ← Aufgaben
├── countdowns/      ← Countdowns
├── calendar/        ← Mini-Kalender & nächste Termine
├── budget/          ← Budget-Kurzübersicht (Recht budget.access)
├── vrchat/          ← VRChat-Freunde online (Recht vrcx.access)
├── feed/            ← Neueste Feed-Meldungen
└── quicklinks/      ← Schnellzugriff / Lesezeichen
```

**Eigenes Widget:** `_vorlage` kopieren, Ordner umbenennen (z. B. `mein-widget`), `widget.php` anpassen.
Alle Möglichkeiten sind in `_vorlage/widget.php` kommentiert. Ein Widget entfernen = Ordner löschen.

Ein Fehler in einem Widget legt nie die Startseite lahm – er wird nur im betroffenen Widget angezeigt.
