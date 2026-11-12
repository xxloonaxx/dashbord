<?php
// Server- & Dashboard-Status für Admins
return [
    'name' => 'Systemstatus',
    'icon' => '🖥️',
    'description' => 'PHP-Version, Speicherplatz, Datenbank-Größen, Benutzer, letzte Anmeldungen und fehlende Erweiterungen.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'permission' => 'system.manage',
    'refresh' => 300,
    'render' => function (WidgetCtx $w): string {
        $root = dirname(__DIR__, 2);
        $size = function (float $b) { foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) { if ($b < 1024) return round($b, $b < 10 ? 1 : 0) . ' ' . $u; $b /= 1024; } return round($b) . ' PB'; };
        $free = @disk_free_space($root); $total = @disk_total_space($root);
        $db = 0; foreach (glob(DATA_DIR . '/db/*.sqlite') ?: [] as $f) $db += filesize($f);
        $json = 0; foreach (glob(DATA_DIR . '/json/*.json') ?: [] as $f) $json += filesize($f);
        $up = 0; foreach (glob($root . '/uploads/*') ?: [] as $f) $up += filesize($f);
        $users = (int)site_db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $last = site_db()->query("SELECT username, ts FROM audit_log WHERE action = 'Login' ORDER BY id DESC LIMIT 1")->fetch() ?: null;
        $missing = array_values(array_filter(['curl', 'pdo_sqlite', 'openssl', 'sodium', 'mbstring', 'dom', 'fileinfo', 'gd'], function ($x) { return !extension_loaded($x); }));
        $used = $total ? 100 - $free / $total * 100 : 0;
        $h = '<div class="sy"><div class="sy-row"><span>🐘 PHP</span><b>' . e(PHP_VERSION) . '</b></div>'
           . ($total ? '<div class="sy-row"><span>💽 Speicher</span><b>' . $size($free) . ' frei</b></div><div class="sy-bar"><i style="width:' . round($used) . '%" class="' . ($used > 90 ? 'bad' : ($used > 75 ? 'warn' : '')) . '"></i></div>' : '')
           . '<div class="sy-row"><span>🗄 Datenbanken</span><b>' . $size($db) . '</b></div><div class="sy-row"><span>📄 JSON-Daten</span><b>' . $size($json) . '</b></div>'
           . ($up ? '<div class="sy-row"><span>🖼 Uploads</span><b>' . $size($up) . '</b></div>' : '')
           . '<div class="sy-row"><span>👥 Benutzer</span><b>' . $users . '</b></div>'
           . ($last ? '<div class="sy-row"><span>🔑 Letzte Anmeldung</span><b>' . e($last['username']) . ' · ' . e(date('d.m. H:i', strtotime($last['ts']))) . '</b></div>' : '')
           . '<div class="sy-row"><span>🛠 Wartungsmodus</span><b>' . (site_settings()['maintenance'] ? '⚠ an' : 'aus') . '</b></div>'
           . ($missing ? '<div class="sy-warn">⚠ Fehlende PHP-Erweiterungen: ' . e(implode(', ', $missing)) . '</div>' : '<div class="sy-ok">✓ Alle wichtigen PHP-Erweiterungen vorhanden</div>');
        return $h . '<a href="admin.php" class="wg-more">Admin-Zentrale →</a></div>';
    },
];
