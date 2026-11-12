<?php
/**
 * JSON-Schnittstelle der Startseiten-Widgets:
 *   render  – Widget neu zeichnen (Auto-Aktualisierung)
 *   action  – Aktion eines Widgets ausführen (z. B. Aufgabe abhaken), danach neu zeichnen
 *   add / remove / size / settings / reorder – Anordnung ändern (Recht „pages.manage“)
 */
require_once __DIR__ . '/inc/common.php';
require_once __DIR__ . '/inc/widgets.php';

auth_require_setup();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$in = $_POST + $_GET;
$act = (string)($in['act'] ?? '');
$out = ['ok' => true];
try {
    $manage = ['add', 'remove', 'size', 'settings', 'reorder'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') guard_post(in_array($act, $manage, true) ? 'pages.manage' : null);
    if (in_array($act, $manage, true) && !can('pages.manage')) throw new RuntimeException('Dafür fehlt das Recht „Seiten verwalten“.');
    $uid = preg_replace('/\W/', '', (string)($in['uid'] ?? ''));
    $layout = widgets_layout();
    $avail = widgets_available();

    switch ($act) {
        case 'render':
        case 'action':
            $inst = widget_find($uid);
            if (!$inst) throw new RuntimeException('Widget nicht gefunden.');
            $m = $avail[$inst['type']] ?? null;
            if (!$m || !widget_allowed($m)) throw new RuntimeException('Kein Zugriff.');
            if ($act === 'action') {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Nur per POST.');
                if (!is_callable($m['action'] ?? null)) throw new RuntimeException('Dieses Widget hat keine Aktionen.');
                $out['msg'] = ($m['action'])(widget_ctx($inst), (string)($in['op'] ?? ''), $in);
            }
            $out['html'] = widget_body($inst);
            break;
        case 'add':
            $type = (string)($in['type'] ?? '');
            if (!isset($avail[$type]) || !empty($avail[$type]['error'])) throw new RuntimeException('Unbekanntes Widget.');
            if (count($layout) >= 40) throw new RuntimeException('Maximal 40 Widgets.');
            $inst = ['uid' => substr(bin2hex(random_bytes(6)), 0, 10), 'type' => $type, 'size' => $avail[$type]['size'] ?? 's', 'cfg' => []];
            $layout[] = $inst;
            widgets_layout_save($layout);
            audit('Widget hinzugefügt', $avail[$type]['name']);
            $out['html'] = widget_card($inst, true);
            $m = $avail[$type];
            $out['assets'] = ['css' => $m['has_css'] ? 'widgets/' . $type . '/widget.css?v=' . $m['mtime'] : '', 'js' => $m['has_js'] ? 'widgets/' . $type . '/widget.js?v=' . $m['mtime'] : ''];
            break;
        case 'remove':
            $layout = array_values(array_filter($layout, function ($w) use ($uid) { return $w['uid'] !== $uid; }));
            widgets_layout_save($layout);
            set_setting('widget_state_' . $uid, '');
            break;
        case 'size':
            $keys = array_keys(WIDGET_SIZES);
            foreach ($layout as &$w) if ($w['uid'] === $uid) {
                $w['size'] = isset(WIDGET_SIZES[$in['size'] ?? '']) ? $in['size'] : $keys[(array_search($w['size'] ?? 's', $keys, true) + 1) % count($keys)];
                $out['size'] = $w['size'];
                $out['label'] = WIDGET_SIZES[$w['size']];
            }
            unset($w);
            widgets_layout_save($layout);
            break;
        case 'settings':
            foreach ($layout as &$w) if ($w['uid'] === $uid) {
                $m = $avail[$w['type']] ?? null;
                if (!$m) throw new RuntimeException('Widget nicht installiert.');
                if (isset($in['cfg'])) {
                    $w['cfg'] = widget_clean_cfg($m, (array)json_decode((string)$in['cfg'], true));
                    $out['card'] = widget_card($w, true);
                } else {
                    $out['schema'] = widget_settings_schema($m);
                    $out['values'] = widget_ctx($w)->cfg + ['_title' => $w['cfg']['_title'] ?? ''];
                    $out['name'] = $m['name'];
                }
            }
            unset($w);
            widgets_layout_save($layout);
            break;
        case 'reorder':
            $order = array_flip(array_map('strval', (array)json_decode((string)($in['order'] ?? '[]'), true)));
            usort($layout, function ($a, $b) use ($order) { return ($order[$a['uid']] ?? 999) <=> ($order[$b['uid']] ?? 999); });
            widgets_layout_save($layout);
            break;
        case 'catalog':
            if (!can('pages.manage')) throw new RuntimeException('Kein Zugriff.');
            $used = array_count_values(array_column($layout, 'type'));
            $out['widgets'] = array_values(array_map(function ($t, $m) use ($used) {
                return ['type' => $t, 'name' => $m['name'], 'icon' => $m['icon'], 'description' => $m['description'], 'version' => $m['version'], 'author' => $m['author'],
                        'error' => $m['error'] ?? '', 'used' => $used[$t] ?? 0, 'permission' => $m['permission'] ? (PERMISSIONS[$m['permission']][0] ?? $m['permission']) : '', 'login' => !empty($m['login'])];
            }, array_keys($avail), $avail));
            break;
        default:
            throw new RuntimeException('Unbekannte Aktion.');
    }
} catch (Throwable $e) {
    http_response_code(400);
    $out = ['error' => $e->getMessage()];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
