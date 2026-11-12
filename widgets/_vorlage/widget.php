<?php
/**
 * ============================================================================
 *  VORLAGE FÜR EIN NEUES WIDGET
 * ============================================================================
 *  So legst du ein eigenes Widget an:
 *   1. Diesen Ordner kopieren und umbenennen, z. B. „widgets/mein-widget“
 *      (nur Kleinbuchstaben, Zahlen, - und _; Ordner mit „_“ am Anfang werden ignoriert).
 *   2. Unten Name, Icon, Beschreibung und die render-Funktion anpassen.
 *   3. Startseite → ✎ Bearbeiten → „＋ Widget“ → dein Widget erscheint automatisch in der Liste.
 *
 *  Optional im selben Ordner:
 *   - widget.css  → wird automatisch geladen (Klassen am besten mit .wg-type-<ordnername> beginnen)
 *   - widget.js   → wird automatisch geladen, siehe Beispiel in widget.js
 *   - Bilder usw. → im HTML über $w->url . 'bild.png' einbinden
 *
 *  Was die render-Funktion bekommt ($w):
 *   $w->cfg           Einstellungen dieser Instanz (siehe 'settings' unten, mit Standardwerten)
 *   $w->state()       gespeicherter Zustand (Array) – z. B. Listen, Zähler, Cache
 *   $w->save_state()  Zustand speichern
 *   $w->can_edit()    darf der Besucher Inhalte ändern? (Standard: Recht „pages.edit“)
 *   $w->cached_json($url, $sekunden)  JSON von einer Adresse laden und zwischenspeichern
 *   $w->uid / $w->url eindeutige ID der Instanz / Web-Pfad dieses Ordners
 *
 *  Hilfsfunktionen des Dashboards: e() zum Maskieren, format_text() für Markdown/BBCode,
 *  can('recht') für Rechte, eur() für Beträge, setting()/set_setting() für eigene Einstellungen.
 *  WICHTIG: Alles, was aus Eingaben oder fremden Quellen kommt, immer mit e() ausgeben!
 * ============================================================================
 */
return [
    'name'        => 'Mein Widget',            // Anzeigename in der Widget-Liste
    'icon'        => '🧩',                      // ein Emoji
    'description' => 'Kurze Beschreibung, was das Widget zeigt.',
    'version'     => '1.0',
    'author'      => 'Dein Name',
    'size'        => 's',                       // Standardgröße: s (klein), m (mittel), l (breit), xl (ganze Zeile)
    'permission'  => null,                      // z. B. 'budget.access' – ohne dieses Recht bleibt das Widget unsichtbar
    'login'       => false,                     // true = nur für angemeldete Benutzer
    'refresh'     => 0,                         // automatisch neu laden alle X Sekunden (0 = nie)

    // Einstellungen – erscheinen im ⚙-Dialog. Typen: text, textarea, number, checkbox, select
    'settings'    => [
        'greeting' => ['label' => 'Begrüßungstext', 'type' => 'text', 'default' => 'Hallo Welt!'],
        'count'    => ['label' => 'Wie oft?', 'type' => 'number', 'default' => 1, 'min' => 1, 'max' => 5],
        'style'    => ['label' => 'Stil', 'type' => 'select', 'default' => 'gold', 'options' => ['gold' => 'Gold', 'cyan' => 'Cyan']],
    ],

    // Muss HTML zurückgeben. Fehler (Exceptions) werden im Widget angezeigt, nicht auf der ganzen Seite.
    'render' => function (WidgetCtx $w): string {
        $state = $w->state();
        $clicks = (int)($state['clicks'] ?? 0);
        $h = '';
        for ($i = 0; $i < (int)$w->cfg['count']; $i++) $h .= '<p class="vorlage-' . e($w->cfg['style']) . '">' . e($w->cfg['greeting']) . '</p>';
        $h .= '<p class="wg-muted">Schon ' . $clicks . '× geklickt.</p>';
        // Knöpfe mit data-wg-action lösen die 'action'-Funktion unten aus (data-* Werte werden mitgeschickt)
        if ($w->can_edit()) $h .= '<button type="button" class="btn btn-sm" data-wg-action="click">+1</button>';
        return $h;
    },

    // Optional: wird bei Klick auf [data-wg-action="…"] oder bei Formularen mit data-wg-action aufgerufen.
    // $op = Name der Aktion, $in = gesendete Daten. Rückgabe: Meldung (Text) oder null. Danach wird neu gezeichnet.
    'action' => function (WidgetCtx $w, string $op, array $in): ?string {
        if (!$w->can_edit()) throw new RuntimeException('Keine Berechtigung.');
        $s = $w->state();
        if ($op === 'click') $s['clicks'] = (int)($s['clicks'] ?? 0) + 1;
        $w->save_state($s);
        return null;
    },
];
