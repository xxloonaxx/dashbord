<?php
/**
 * Widget-System (Plug & Play).
 *
 * Jedes Widget ist ein eigener Ordner unter widgets/<name>/ mit einer Datei widget.php, die ein Array zurückgibt
 * (Name, Icon, Einstellungen, render-Funktion …). Optional: widget.css und widget.js im selben Ordner – sie werden
 * automatisch geladen. Ordner, die mit „_“ beginnen (z. B. widgets/_vorlage), werden ignoriert.
 * Neues Widget: widgets/_vorlage kopieren, umbenennen, anpassen – es erscheint sofort unter „＋ Widget“.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/format.php';

const WIDGETS_DIR = __DIR__ . '/../widgets';
const WIDGET_SIZES = ['s' => 'klein', 'm' => 'mittel', 'l' => 'breit', 'xl' => 'ganze Zeile'];

/** Kontext, den jedes Widget beim Rendern bekommt */
final class WidgetCtx {
    public string $uid;
    public string $type;
    public array $cfg;
    public array $manifest;
    public string $url;   // Web-Pfad des Widget-Ordners (für Bilder o. Ä.)

    public function __construct(string $uid, string $type, array $cfg, array $manifest) {
        $this->uid = $uid;
        $this->type = $type;
        $this->manifest = $manifest;
        $this->url = 'widgets/' . rawurlencode($type) . '/';
        $defaults = [];
        foreach ((array)($manifest['settings'] ?? []) as $k => $s) $defaults[$k] = $s['default'] ?? '';
        $this->cfg = array_intersect_key($cfg, $defaults) + $defaults;
    }

    /** Gespeicherter Zustand dieser Widget-Instanz (z. B. Aufgaben, Notiztext, Cache) */
    public function state(): array {
        $v = json_decode(setting('widget_state_' . $this->uid, '{}'), true);
        return is_array($v) ? $v : [];
    }

    public function save_state(array $state): void {
        set_setting('widget_state_' . $this->uid, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Darf der aktuelle Benutzer Inhalte dieses Widgets ändern? (Standard: Recht „pages.edit“) */
    public function can_edit(): bool {
        return can($this->manifest['edit_permission'] ?? 'pages.edit');
    }

    /** Daten von einer Adresse holen und für $ttl Sekunden zwischenspeichern */
    public function cached_json(string $url, int $ttl = 1800, array $headers = []) {
        $key = 'widget_cache_' . md5($url);
        $c = json_decode(setting($key, ''), true);
        if (is_array($c) && ($c['t'] ?? 0) > time() - $ttl) return $c['d'];
        if (!function_exists('curl_init')) throw new RuntimeException('cURL fehlt auf dem Server.');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => true,
                                CURLOPT_USERAGENT => 'GhostNodeDashboard/1.0 (widget)', CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers)]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $d = $raw !== false && $code < 400 ? json_decode((string)$raw, true) : null;
        if ($d === null) {
            if (is_array($c)) return $c['d'];   // alte Daten sind besser als keine
            throw new RuntimeException('Dienst nicht erreichbar (HTTP ' . $code . ').');
        }
        set_setting($key, json_encode(['t' => time(), 'd' => $d], JSON_UNESCAPED_UNICODE));
        return $d;
    }
}

/** Alle installierten Widgets: Typ => Manifest (mit 'error', falls die Datei kaputt ist) */
function widgets_available(): array {
    static $list = null;
    if ($list !== null) return $list;
    $list = [];
    foreach (glob(WIDGETS_DIR . '/*/widget.php') ?: [] as $file) {
        $type = basename(dirname($file));
        if ($type[0] === '_' || $type[0] === '.' || !preg_match('/^[a-z0-9_-]{1,40}$/', $type)) continue;
        try {
            $m = (function () use ($file) { return include $file; })();
            if (!is_array($m) || !is_callable($m['render'] ?? null)) throw new RuntimeException('widget.php muss ein Array mit einer render-Funktion zurückgeben.');
        } catch (Throwable $e) {
            $m = ['name' => $type, 'icon' => '⚠️', 'description' => 'Fehler: ' . $e->getMessage(), 'error' => $e->getMessage(), 'render' => null];
        }
        $dir = dirname($file);
        $m += ['name' => $type, 'icon' => '🧩', 'description' => '', 'version' => '1.0', 'author' => '', 'size' => 's', 'permission' => null, 'login' => false,
               'refresh' => 0, 'settings' => [], 'action' => null];
        $m['has_css'] = is_file($dir . '/widget.css');
        $m['has_js'] = is_file($dir . '/widget.js');
        $m['mtime'] = max(filemtime($file), $m['has_css'] ? filemtime($dir . '/widget.css') : 0, $m['has_js'] ? filemtime($dir . '/widget.js') : 0);
        $list[$type] = $m;
    }
    uasort($list, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
    return $list;
}

/** Darf der aktuelle Besucher dieses Widget sehen? */
function widget_allowed(array $m): bool {
    if (!empty($m['error'])) return can('pages.manage');
    if (!empty($m['login']) && !is_logged_in()) return false;
    if (!empty($m['permission']) && !can($m['permission'])) return false;
    return true;
}

/** Anordnung auf der Startseite (für alle gleich, bearbeitbar mit „pages.manage“) */
function widgets_layout(): array {
    $raw = setting('widgets_layout', '');
    if ($raw === '') {
        // Erster Start: alle mitgelieferten Widgets in sinnvoller Reihenfolge
        $order = ['clock', 'weather', 'countdowns', 'calendar', 'todo', 'notes', 'budget', 'vrchat', 'feed', 'quicklinks'];
        $avail = widgets_available();
        $layout = [];
        foreach (array_merge($order, array_keys($avail)) as $t) {
            if (!isset($avail[$t]) || !empty($avail[$t]['error']) || in_array($t, array_column($layout, 'type'), true)) continue;
            $layout[] = ['uid' => substr(md5($t . random_bytes(4)), 0, 10), 'type' => $t, 'size' => $avail[$t]['size'], 'cfg' => []];
        }
        widgets_layout_save($layout);
        return $layout;
    }
    $l = json_decode($raw, true);
    return is_array($l) ? array_values(array_filter($l, function ($w) { return isset($w['uid'], $w['type']); })) : [];
}

function widgets_layout_save(array $layout): void {
    set_setting('widgets_layout', json_encode(array_values($layout), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function widget_find(string $uid): ?array {
    foreach (widgets_layout() as $w) if ($w['uid'] === $uid) return $w;
    return null;
}

function widget_ctx(array $inst): ?WidgetCtx {
    $m = widgets_available()[$inst['type']] ?? null;
    return $m ? new WidgetCtx($inst['uid'], $inst['type'], (array)($inst['cfg'] ?? []), $m) : null;
}

/** Inhalt eines Widgets (Fehler werden im Widget angezeigt, nie die ganze Seite) */
function widget_body(array $inst): string {
    $m = widgets_available()[$inst['type']] ?? null;
    if (!$m) return '<div class="wg-error">Widget „' . e($inst['type']) . '“ ist nicht (mehr) installiert. Ordner <code>widgets/' . e($inst['type']) . '</code> fehlt.</div>';
    if (!empty($m['error'])) return '<div class="wg-error">⚠ ' . e($m['error']) . '</div>';
    try {
        ob_start();
        $html = ($m['render'])(widget_ctx($inst));
        $echoed = ob_get_clean();
        return $echoed . (string)$html;
    } catch (Throwable $e) {
        if (ob_get_level()) ob_end_clean();
        return '<div class="wg-error">⚠ ' . e($e->getMessage()) . '</div>';
    }
}

/** Komplette Widget-Karte */
function widget_card(array $inst, bool $edit): string {
    $m = widgets_available()[$inst['type']] ?? ['name' => $inst['type'], 'icon' => '❔', 'refresh' => 0, 'settings' => []];
    $title = trim((string)($inst['cfg']['_title'] ?? '')) ?: $m['name'];
    $size = isset(WIDGET_SIZES[$inst['size'] ?? '']) ? $inst['size'] : ($m['size'] ?? 's');
    $h = '<section class="wg wg-' . e($size) . ' wg-type-' . e($inst['type']) . '" data-uid="' . e($inst['uid']) . '" data-type="' . e($inst['type']) . '" data-refresh="' . (int)($m['refresh'] ?? 0) . '"'
        . ($edit ? ' draggable="true"' : '') . '>';
    $h .= '<header class="wg-head"><span class="wg-icon">' . e($m['icon']) . '</span><h3>' . e($title) . '</h3>';
    if ($edit) {
        $h .= '<span class="wg-tools"><span class="wg-drag" title="Ziehen zum Sortieren">⠿</span>'
            . '<button type="button" class="ic" data-wg-size title="Größe ändern (' . e(WIDGET_SIZES[$size]) . ')">⇔</button>'
            . '<button type="button" class="ic" data-wg-settings title="Einstellungen">⚙</button>'
            . '<button type="button" class="ic danger" data-wg-remove title="Entfernen">✕</button></span>';
    } else {
        $h .= '<button type="button" class="wg-min" title="Einklappen" aria-label="Einklappen">–</button>';
    }
    $h .= '</header><div class="wg-body">' . widget_body($inst) . '</div></section>';
    return $h;
}

/** Einstellungs-Schema für den Dialog im Bearbeiten-Modus */
function widget_settings_schema(array $m): array {
    $s = ['_title' => ['label' => 'Eigener Titel (leer = Standard)', 'type' => 'text', 'default' => '']];
    foreach ((array)($m['settings'] ?? []) as $k => $d) $s[$k] = $d + ['type' => 'text', 'label' => $k, 'default' => ''];
    return $s;
}

/** Eingaben eines Einstellungs-Dialogs prüfen */
function widget_clean_cfg(array $m, array $in): array {
    $out = [];
    foreach (widget_settings_schema($m) as $k => $d) {
        $v = $in[$k] ?? null;
        switch ($d['type']) {
            case 'checkbox': $out[$k] = !empty($v); break;
            case 'number': $out[$k] = max($d['min'] ?? -1e9, min($d['max'] ?? 1e9, (float)($v ?? $d['default']))); break;
            case 'select': $out[$k] = array_key_exists((string)$v, (array)$d['options']) ? (string)$v : $d['default']; break;
            case 'textarea': $out[$k] = mb_substr((string)$v, 0, 5000); break;
            default: $out[$k] = mb_substr(trim((string)$v), 0, 300);
        }
    }
    return $out;
}
