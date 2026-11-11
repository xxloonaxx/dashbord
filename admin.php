<?php
/**
 * Admin-Zentrale: Ersteinrichtung, Login, Übersicht, Benutzer, Rollen & Rechte, Seiten, Protokoll, System, Mein Konto.
 */
require_once __DIR__ . '/inc/pages.php';
require_once __DIR__ . '/inc/linkcheck.php';
require_once __DIR__ . '/inc/countdown.php';
require_once __DIR__ . '/inc/hub.php';

/** Nur lokale Ziele für die Weiterleitung nach dem Login zulassen */
function safe_next(?string $next): string {
    return ($next && preg_match('/^[A-Za-z0-9_\-]+\.php(\?[A-Za-z0-9_\-=&%.+#]*)?$/', $next)) ? $next : 'admin.php';
}

$db = site_db();
$next = safe_next($_GET['next'] ?? $_POST['next'] ?? null);
$error = '';
$configured = auth_configured();

// =====================================================================
// Ersteinrichtung & Login
// =====================================================================
if (!$configured) {
    $mode = 'setup';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup'])) {
        $u = trim($_POST['username'] ?? '');
        $error = auth_validate($u, $_POST['password'] ?? '', $_POST['password2'] ?? '');
        if (!$error && !same_origin_request()) $error = 'Anfrage abgelehnt.';
        if (!$error) {
            $id = auth_create($u, $_POST['password'], 1, $u);
            $user = user_load($id);
            auth_start_session($user);
            audit('Konto eingerichtet', 'Erster Administrator', 'security', $user);
            header('Location: admin.php?welcome=1');
            exit;
        }
    }
} elseif (!is_logged_in()) {
    $mode = 'login';
    $locked = auth_locked_seconds();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        if ($locked) $error = 'Zu viele Fehlversuche – bitte warten.';
        elseif (!same_origin_request()) $error = 'Anfrage abgelehnt.';
        elseif (auth_login(trim($_POST['username'] ?? ''), $_POST['password'] ?? '', !empty($_POST['remember']))) {
            // Ohne Admin-Zugang direkt zur gewünschten Seite bzw. zum Index
            header('Location: ' . ($next === 'admin.php' && !can('admin.access') ? 'index.php' : $next));
            exit;
        } else {
            $locked = auth_locked_seconds();
            $error = $locked ? 'Zu viele Fehlversuche – Login für ' . ceil($locked / 60) . ' Minuten gesperrt.' : 'Benutzername oder Passwort falsch.';
        }
    }
} else {
    $mode = 'panel';
}

// =====================================================================
// Aktionen (angemeldet)
// =====================================================================
$me = current_user();
if ($mode === 'panel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $perm_for = [
        'toggle_nsfw' => 'settings.nsfw', 'user_save' => 'users.manage', 'user_delete' => 'users.manage', 'user_logout' => 'users.manage',
        'role_save' => 'users.manage', 'role_delete' => 'users.manage', 'settings_save' => 'system.manage', 'clear_feed_cache' => 'system.manage',
        'reset_hub' => 'system.manage', 'logout_all' => 'system.manage', 'audit_prune' => 'system.manage', 'unlock_ips' => 'system.manage',
    ];
    guard_post($perm_for[$act] ?? null);
    $tab = $_POST['tab'] ?? 'overview';
    $msg = '';
    $admins_left = function (?int $except = null) use ($db): int {
        $st = $db->prepare('SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_system = 1 AND u.active = 1' . ($except ? ' AND u.id <> ?' : ''));
        $st->execute($except ? [$except] : []);
        return (int)$st->fetchColumn();
    };

    try {
        switch ($act) {
            case 'logout':
                audit('Logout');
                auth_logout();
                header('Location: index.php');
                exit;

            case 'toggle_nsfw':
                $on = !site_settings()['show_nsfw'];
                save_site_setting('show_nsfw', $on);
                audit($on ? 'NSFW für Besucher eingeblendet' : 'NSFW für Besucher ausgeblendet');
                header('Location: ' . safe_next($_POST['back'] ?? 'admin.php'));
                exit;

            // ----- Mein Konto -----
            case 'account_save':
                $display = mb_substr(trim($_POST['display_name'] ?? ''), 0, 60);
                $db->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute([$display, $me['id']]);
                $msg = 'Profil gespeichert.';
                if (($_POST['password'] ?? '') !== '') {
                    $row = $db->query('SELECT password_hash FROM users WHERE id = ' . (int)$me['id'])->fetchColumn();
                    if (!password_verify((string)($_POST['current'] ?? ''), $row)) throw new InvalidArgumentException('Das aktuelle Passwort ist falsch.');
                    if ($e = auth_validate_password($_POST['password'], $_POST['password2'] ?? '', $me['username'])) throw new InvalidArgumentException($e);
                    $db->prepare("UPDATE users SET password_hash = ?, password_changed = datetime('now','localtime'), session_version = session_version + 1 WHERE id = ?")
                       ->execute([password_hash($_POST['password'], PASSWORD_DEFAULT), $me['id']]);
                    auth_start_session(user_load((int)$me['id']), !empty($_SESSION['auth']['remember']));
                    audit('Eigenes Passwort geändert', '', 'security');
                    $msg = 'Passwort geändert – andere Geräte wurden abgemeldet.';
                }
                break;

            case 'account_logout_everywhere':
                user_bump_sessions((int)$me['id']);
                auth_start_session(user_load((int)$me['id']), !empty($_SESSION['auth']['remember']));
                audit('Andere Geräte abgemeldet', '', 'security');
                $msg = 'Alle anderen Sitzungen wurden beendet.';
                break;

            // ----- Benutzer -----
            case 'user_save':
                $uid = (int)($_POST['id'] ?? 0);
                $username = trim($_POST['username'] ?? '');
                $role = (int)($_POST['role_id'] ?? 0);
                $active = !empty($_POST['active']) ? 1 : 0;
                $display = mb_substr(trim($_POST['display_name'] ?? ''), 0, 60);
                $note = mb_substr(trim($_POST['note'] ?? ''), 0, 200);
                $pw = (string)($_POST['password'] ?? '');
                $rst = $db->prepare('SELECT is_system FROM roles WHERE id = ?');
                $rst->execute([$role]);
                $role_system = $rst->fetchColumn();
                if ($role_system === false) throw new InvalidArgumentException('Bitte eine Rolle wählen.');
                if (!preg_match('/^[\p{L}\p{N}_.\-]{3,32}$/u', $username)) throw new InvalidArgumentException('Benutzername: 3–32 Zeichen (Buchstaben, Zahlen, _ . -).');
                $dupe = $db->prepare('SELECT id FROM users WHERE username = ? COLLATE NOCASE AND id <> ?');
                $dupe->execute([$username, $uid]);
                if ($dupe->fetchColumn()) throw new InvalidArgumentException('Den Benutzernamen gibt es schon.');
                if ($uid) {
                    // Den letzten aktiven Administrator nicht entmachten
                    $was = user_load($uid);
                    if (!$was) throw new InvalidArgumentException('Benutzer nicht gefunden.');
                    if ($was['role_is_system'] && $was['active'] && (!$role_system || !$active) && $admins_left($uid) === 0) throw new InvalidArgumentException('Es muss mindestens ein aktiver Administrator bleiben.');
                    $db->prepare('UPDATE users SET username = ?, display_name = ?, role_id = ?, active = ?, note = ? WHERE id = ?')->execute([$username, $display, $role, $active, $note, $uid]);
                    if ($pw !== '') {
                        if ($e = auth_validate_password($pw, $pw, $username)) throw new InvalidArgumentException($e);
                        $db->prepare("UPDATE users SET password_hash = ?, password_changed = datetime('now','localtime') WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), $uid]);
                    }
                    if ($pw !== '' || !$active || (int)$was['role_id'] !== $role) user_bump_sessions($uid);   // neu anmelden lassen
                    if ($uid === (int)$me['id']) auth_start_session(user_load($uid), !empty($_SESSION['auth']['remember']));
                    audit('Benutzer geändert', $username . ' · Rolle #' . $role . ($active ? '' : ' · deaktiviert') . ($pw !== '' ? ' · Passwort neu gesetzt' : ''), 'security');
                    $msg = 'Benutzer „' . $username . '“ gespeichert.';
                } else {
                    if ($e = auth_validate_password($pw, $pw, $username)) throw new InvalidArgumentException($e);
                    $new = auth_create($username, $pw, $role, $display);
                    $db->prepare('UPDATE users SET active = ?, note = ? WHERE id = ?')->execute([$active, $note, $new]);
                    audit('Benutzer angelegt', $username . ' · Rolle #' . $role, 'security');
                    $msg = 'Benutzer „' . $username . '“ angelegt.';
                }
                break;

            case 'user_delete':
                $uid = (int)($_POST['id'] ?? 0);
                $u = user_load($uid);
                if (!$u) break;
                if ($uid === (int)$me['id']) throw new InvalidArgumentException('Du kannst dich nicht selbst löschen.');
                if ($u['role_is_system'] && $admins_left($uid) === 0) throw new InvalidArgumentException('Es muss mindestens ein aktiver Administrator bleiben.');
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
                audit('Benutzer gelöscht', $u['username'], 'security');
                $msg = 'Benutzer „' . $u['username'] . '“ gelöscht.';
                break;

            case 'user_logout':
                $uid = (int)($_POST['id'] ?? 0);
                user_bump_sessions($uid);
                if ($uid === (int)$me['id']) auth_start_session(user_load($uid), !empty($_SESSION['auth']['remember']));
                audit('Sitzungen eines Benutzers beendet', '#' . $uid, 'security');
                $msg = 'Alle Sitzungen des Benutzers wurden beendet.';
                break;

            // ----- Rollen -----
            case 'role_save':
                $rid = (int)($_POST['id'] ?? 0);
                $name = mb_substr(trim($_POST['name'] ?? ''), 0, 40);
                if ($name === '') throw new InvalidArgumentException('Bitte einen Rollennamen eingeben.');
                $color = preg_match('/^#[0-9a-f]{6}$/i', $_POST['color'] ?? '') ? $_POST['color'] : '#38d5e6';
                $desc = mb_substr(trim($_POST['description'] ?? ''), 0, 200);
                $perms = array_values(array_intersect(array_keys(PERMISSIONS), (array)($_POST['perms'] ?? [])));
                $dupe = $db->prepare('SELECT id FROM roles WHERE name = ? COLLATE NOCASE AND id <> ?');
                $dupe->execute([$name, $rid]);
                if ($dupe->fetchColumn()) throw new InvalidArgumentException('Diese Rolle gibt es schon.');
                $db->beginTransaction();
                if ($rid) {
                    $db->prepare('UPDATE roles SET name = ?, color = ?, description = ? WHERE id = ?')->execute([$name, $color, $desc, $rid]);
                } else {
                    $db->prepare('INSERT INTO roles (name, color, description) VALUES (?, ?, ?)')->execute([$name, $color, $desc]);
                    $rid = (int)$db->lastInsertId();
                }
                $sys = (int)$db->query('SELECT is_system FROM roles WHERE id = ' . $rid)->fetchColumn();
                if (!$sys) {
                    $db->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$rid]);
                    $ins = $db->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)');
                    foreach ($perms as $p) $ins->execute([$rid, $p]);
                }
                $db->commit();
                audit('Rolle gespeichert', $name . ($sys ? '' : ' · ' . count($perms) . ' Rechte'), 'security');
                $msg = 'Rolle „' . $name . '“ gespeichert.';
                break;

            case 'role_delete':
                $rid = (int)($_POST['id'] ?? 0);
                $r = $db->query('SELECT * FROM roles WHERE id = ' . $rid)->fetch();
                if (!$r) break;
                if ($r['is_system']) throw new InvalidArgumentException('Die Administrator-Rolle kann nicht gelöscht werden.');
                if ((int)$db->query('SELECT COUNT(*) FROM users WHERE role_id = ' . $rid)->fetchColumn()) throw new InvalidArgumentException('Der Rolle sind noch Benutzer zugeordnet – bitte erst umstellen.');
                $db->prepare('DELETE FROM roles WHERE id = ?')->execute([$rid]);
                audit('Rolle gelöscht', $r['name'], 'security');
                $msg = 'Rolle „' . $r['name'] . '“ gelöscht.';
                break;

            // ----- System -----
            case 'settings_save':
                save_site_setting('site_title', mb_substr(trim($_POST['site_title'] ?? '') ?: 'Startseite', 0, 60));
                save_site_setting('site_tagline', mb_substr(trim($_POST['site_tagline'] ?? ''), 0, 120));
                $maint = !empty($_POST['maintenance']);
                if ($maint !== site_settings()['maintenance']) audit($maint ? 'Wartungsmodus EIN' : 'Wartungsmodus AUS', '', 'warn');
                save_site_setting('maintenance', $maint);
                save_site_setting('maintenance_message', mb_substr(trim($_POST['maintenance_message'] ?? ''), 0, 300) ?: 'Wir sind gleich wieder da.');
                $msg = 'Einstellungen gespeichert.';
                break;

            case 'clear_feed_cache':
                @unlink(__DIR__ . '/feed_cache.json');
                audit('Feed-Cache geleert');
                $msg = 'Feed-Cache geleert – der Feed lädt beim nächsten Aufruf neu.';
                break;

            case 'reset_hub':
                $hub = basename($_POST['hub'] ?? '');
                if (in_array($hub, find_hub_files(), true)) {
                    $file = hub_data_file($hub);
                    if (is_file($file)) {
                        if (!is_dir(__DIR__ . '/archive')) mkdir(__DIR__ . '/archive', 0755, true);
                        rename($file, __DIR__ . '/archive/' . pathinfo($file, PATHINFO_FILENAME) . '_backup_' . date('Y-m-d_His') . '.json');
                        audit('Hub zurückgesetzt', $hub, 'warn');
                        $msg = '„' . $hub . '“ wurde auf den Startbestand zurückgesetzt (Kopie im Ordner archive/).';
                    } else {
                        $msg = '„' . $hub . '“ nutzt bereits den Startbestand.';
                    }
                }
                break;

            case 'logout_all':
                $db->prepare('UPDATE users SET session_version = session_version + 1 WHERE id <> ?')->execute([$me['id']]);
                audit('Alle anderen Benutzer abgemeldet', '', 'security');
                $msg = 'Alle anderen Benutzer wurden abgemeldet.';
                break;

            case 'unlock_ips':
                $db->exec('DELETE FROM login_attempts');
                audit('Login-Sperren aufgehoben', '', 'security');
                $msg = 'Alle Login-Sperren wurden aufgehoben.';
                break;

            case 'audit_prune':
                $days = max(7, (int)($_POST['days'] ?? 90));
                $n = $db->exec("DELETE FROM audit_log WHERE ts < datetime('now', '-$days days')");
                audit('Protokoll aufgeräumt', "$n Einträge älter als $days Tage gelöscht");
                $msg = "$n alte Einträge gelöscht.";
                break;
        }
    } catch (InvalidArgumentException $e) {
        if ($db->inTransaction()) $db->rollBack();
        header('Location: admin.php?tab=' . urlencode($tab) . '&error=' . urlencode($e->getMessage()));
        exit;
    }
    header('Location: admin.php?tab=' . urlencode($tab) . ($msg ? '&msg=' . urlencode($msg) : ''));
    exit;
}

// =====================================================================
// Daten für die Ansichten
// =====================================================================
if ($mode === 'panel') {
    pages_sync();
    $TABS = [
        'overview' => ['📊', 'Übersicht', 'admin.access'],
        'users'    => ['👥', 'Benutzer', 'users.manage'],
        'roles'    => ['🛡️', 'Rollen & Rechte', 'users.manage'],
        'pages'    => ['🗂️', 'Seiten', 'pages.manage'],
        'audit'    => ['📜', 'Protokoll', 'audit.view'],
        'system'   => ['🛰️', 'System', 'system.manage'],
        'account'  => ['👤', 'Mein Konto', null],
    ];
    $TABS = array_filter($TABS, function ($t) { return $t[2] === null || can($t[2]); });
    $tab = $_GET['tab'] ?? (can('admin.access') ? 'overview' : 'account');
    if (!isset($TABS[$tab])) $tab = 'account';

    // CSV-Export des Protokolls
    if ($tab === 'audit' && isset($_GET['export'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="protokoll_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Zeit', 'Benutzer', 'Aktion', 'Details', 'Stufe', 'IP'], ';');
        foreach ($db->query('SELECT ts, username, action, detail, level, ip FROM audit_log ORDER BY id DESC') as $r) fputcsv($out, array_values($r), ';');
        fclose($out);
        exit;
    }

    $roles = $db->query('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count FROM roles r ORDER BY r.is_system DESC, r.id')->fetchAll();
    $settings = site_settings();
    $flash = $_GET['msg'] ?? '';
    $flash_err = $_GET['error'] ?? '';

    if ($tab === 'overview') {
        $stats = [
            'users' => (int)$db->query('SELECT COUNT(*) FROM users WHERE active = 1')->fetchColumn(),
            'users_all' => (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'pages' => (int)$db->query('SELECT COUNT(*) FROM pages')->fetchColumn(),
            'custom' => (int)$db->query("SELECT COUNT(*) FROM pages WHERE kind = 'custom'")->fetchColumn(),
            'protected' => (int)$db->query("SELECT COUNT(*) FROM pages WHERE access <> 'public'")->fetchColumn(),
            'fails24' => (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE level = 'security' AND action LIKE 'Login fehl%' AND ts > datetime('now','localtime','-1 day')")->fetchColumn(),
            'locked' => (int)$db->query('SELECT COUNT(*) FROM login_attempts WHERE count >= ' . AUTH_MAX_FAILS . ' AND last > ' . (time() - AUTH_LOCK_SECONDS))->fetchColumn(),
        ];
        $links = collect_hub_links();
        $lc = load_linkcheck();
        $lc_bad = count(array_filter($lc['results'], function ($r) { return in_array($r['status'], ['dead', 'broken'], true); }));
        try {
            require_once __DIR__ . '/inc/calendar.php';
            $cal_events = (int)cal_db()->query('SELECT COUNT(*) FROM events')->fetchColumn();
            $cal_subs = (int)cal_db()->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn();
        } catch (Throwable $e) { $cal_events = $cal_subs = 0; }
        [$upcoming] = sorted_countdowns(load_countdowns()['events']);

        // Aktivität der letzten 14 Tage
        $activity = [];
        for ($i = 13; $i >= 0; $i--) $activity[date('Y-m-d', strtotime("-$i days"))] = ['login' => 0, 'other' => 0, 'sec' => 0];
        foreach ($db->query("SELECT date(ts) AS d, action, level FROM audit_log WHERE ts >= date('now','localtime','-13 days')") as $r) {
            if (!isset($activity[$r['d']])) continue;
            if ($r['level'] === 'security' && stripos($r['action'], 'fehl') !== false) $activity[$r['d']]['sec']++;
            elseif ($r['action'] === 'Login') $activity[$r['d']]['login']++;
            else $activity[$r['d']]['other']++;
        }
        $recent = $db->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 8')->fetchAll();
    }
    if ($tab === 'users' || $tab === 'overview') {
        $users = $db->query('SELECT u.*, r.name AS role_name, r.color AS role_color, r.is_system FROM users u JOIN roles r ON r.id = u.role_id ORDER BY r.is_system DESC, u.username')->fetchAll();
    }
    if ($tab === 'roles') {
        $role_perms = [];
        foreach ($db->query('SELECT role_id, permission FROM role_permissions') as $r) $role_perms[(int)$r['role_id']][] = $r['permission'];
        $perm_groups = [];
        foreach (PERMISSIONS as $k => [$label, $group]) $perm_groups[$group][$k] = $label;
    }
    if ($tab === 'pages') {
        $pages = $db->query('SELECT p.*, c.name AS cat_name, c.icon AS cat_icon, u.username AS creator FROM pages p LEFT JOIN page_categories c ON c.id = p.category_id LEFT JOIN users u ON u.id = p.created_by ORDER BY c.sort, p.sort')->fetchAll();
        $role_names = array_column($roles, 'name', 'id');
    }
    if ($tab === 'audit') {
        $where = []; $args = [];
        if (($q = trim($_GET['q'] ?? '')) !== '') { $where[] = '(action LIKE :q OR detail LIKE :q OR username LIKE :q OR ip LIKE :q)'; $args[':q'] = '%' . $q . '%'; }
        if (in_array($_GET['level'] ?? '', ['info', 'warn', 'security'], true)) { $where[] = 'level = :lvl'; $args[':lvl'] = $_GET['level']; }
        $page_no = max(1, (int)($_GET['page'] ?? 1));
        $sqlw = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $cnt = $db->prepare('SELECT COUNT(*) FROM audit_log' . $sqlw); $cnt->execute($args);
        $audit_total = (int)$cnt->fetchColumn();
        $st = $db->prepare('SELECT * FROM audit_log' . $sqlw . ' ORDER BY id DESC LIMIT 50 OFFSET ' . (($page_no - 1) * 50));
        $st->execute($args);
        $audit = $st->fetchAll();
    }
    if ($tab === 'system') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $server_soft = (string)($_SERVER['SERVER_SOFTWARE'] ?? '');
        $sqlite = class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true);
        $checks = [
            ['HTTPS-Verbindung', $https, $https ? 'verschlüsselt' : 'Passwörter werden unverschlüsselt übertragen – HTTPS beim Hoster aktivieren!'],
            ['PHP-Version', version_compare(PHP_VERSION, '7.4', '>='), PHP_VERSION],
            ['SQLite (Benutzer, Seiten, Kalender)', $sqlite, $sqlite ? 'aktiv · Version ' . $db->query('SELECT sqlite_version()')->fetchColumn() : 'fehlt'],
            ['cURL (Feed, Link-Check, Abos, Bank)', function_exists('curl_multi_init'), function_exists('curl_multi_init') ? 'aktiv' : 'fehlt'],
            ['ZIP (Backup)', class_exists('ZipArchive'), class_exists('ZipArchive') ? 'aktiv' : 'fehlt – Backup als JSON'],
            ['Ordner beschreibbar', is_writable(__DIR__) && is_writable(__DIR__ . '/data'), is_writable(__DIR__) ? 'ja' : 'nein – Daten können nicht gespeichert werden'],
            ['.htaccess (Datenschutz)', is_file(__DIR__ . '/.htaccess'), stripos($server_soft, 'nginx') !== false ? 'nginx erkannt – .htaccess wirkt dort nicht!' : (is_file(__DIR__ . '/.htaccess') ? 'vorhanden' : 'fehlt')],
            ['Zeitzone', true, date_default_timezone_get() . ' · ' . date('d.m.Y H:i')],
        ];
        $storage = [];
        foreach (array_merge(glob(__DIR__ . '/data/*.sqlite') ?: [], glob(__DIR__ . '/*.json') ?: []) as $f) $storage[str_replace(__DIR__ . '/', '', preg_replace('/_[0-9a-f]{24}\.sqlite$/', '_….sqlite', $f))] = filesize($f);
        $storage['archive/ (' . count(glob(__DIR__ . '/archive*/*.json') ?: []) . ' Dateien)'] = array_sum(array_map('filesize', glob(__DIR__ . '/archive*/*.json') ?: []));
        arsort($storage);
        $hubs = find_hub_files();
    }
    if ($tab === 'account') {
        $my_log = $db->prepare('SELECT * FROM audit_log WHERE user_id = ? ORDER BY id DESC LIMIT 12');
        $my_log->execute([$me['id']]);
        $my_log = $my_log->fetchAll();
    }

    // Befehlspalette (Strg+K)
    $palette = [];
    foreach ($TABS as $k => [$ic, $label]) $palette[] = ['icon' => $ic, 'label' => $label, 'hint' => 'Bereich', 'href' => 'admin.php?tab=' . $k];
    if (can('users.manage')) $palette[] = ['icon' => '➕', 'label' => 'Benutzer anlegen', 'hint' => 'Aktion', 'href' => 'admin.php?tab=users#new'];
    if (can('pages.manage')) {
        $palette[] = ['icon' => '✨', 'label' => 'Neue Seite anlegen', 'hint' => 'Aktion', 'href' => 'index.php?edit=1#new'];
        $palette[] = ['icon' => '✎', 'label' => 'Index bearbeiten & sortieren', 'hint' => 'Aktion', 'href' => 'index.php?edit=1'];
    }
    foreach ($db->query('SELECT * FROM pages ORDER BY title') as $p) {
        if (page_access_ok($p)) $palette[] = ['icon' => $p['icon'] ?: '📄', 'label' => $p['title'], 'hint' => 'Seite', 'href' => page_href($p)];
    }
}

/** Kleine Helfer für die Ansicht */
function ago(?string $ts): string {
    if (!$ts) return 'nie';
    $d = time() - strtotime($ts);
    if ($d < 60) return 'gerade eben';
    if ($d < 3600) return 'vor ' . floor($d / 60) . ' Min.';
    if ($d < 86400) return 'vor ' . floor($d / 3600) . ' Std.';
    if ($d < 86400 * 30) return 'vor ' . floor($d / 86400) . ' Tagen';
    return date('d.m.Y', strtotime($ts));
}
function avatar(array $u, string $cls = ''): string {
    $name = $u['display_name'] ?: $u['username'];
    return '<span class="av ' . $cls . '" style="--rc: ' . e($u['role_color']) . ';">' . e(mb_strtoupper(mb_substr($name, 0, 1))) . '</span>';
}
function bytes(int $b): string {
    return $b > 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : number_format($b / 1024, 1, ',', '.') . ' KB';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>ADMIN CORE</title>
    <?php echo emoji_favicon('⚙️'); ?>
    <link rel="stylesheet" href="assets/hud.css">
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="<?php echo $mode === 'panel' ? 'panel-mode' : 'gate-mode'; ?>">

<?php if ($mode === 'setup' || $mode === 'login'): ?>
<!-- ======================= Einrichtung / Login ======================= -->
<div class="wrapper">
    <div class="gate">
        <div class="gate-box">
            <div class="gate-orbit"><div class="gate-icon"><?php echo $mode === 'setup' ? '⚡' : '🔒'; ?></div></div>
            <div class="hud-tag" style="justify-content: center;"><?php echo $mode === 'setup' ? 'System // Erstinitialisierung' : 'System // Zugriff erforderlich'; ?></div>
            <h1><?php echo $mode === 'setup' ? 'Admin anlegen' : 'Anmelden'; ?></h1>
            <p class="subtitle"><?php echo $mode === 'setup' ? 'Lege das erste Konto an – es wird Administrator' : 'Melde dich mit deinem Benutzerkonto an'; ?></p>

            <?php if ($error): ?><div class="err" role="alert"><?php echo e($error); ?></div><?php endif; ?>
            <?php if ($mode === 'login' && isset($_GET['denied']) && !$error): ?><div class="info">Für diese Änderung musst du angemeldet sein.</div><?php endif; ?>
            <?php if ($mode === 'login' && !$error && ($locked ?? 0)): ?><div class="err">Zu viele Fehlversuche – Login für <?php echo ceil($locked / 60); ?> Minuten gesperrt.</div><?php endif; ?>
            <?php if ($mode === 'setup'): ?><div class="info">Willkommen! Das Dashboard hat noch kein Konto. Wer dieses Formular als Erstes ausfüllt, wird Administrator – also am besten direkt nach dem Hochladen erledigen.</div><?php endif; ?>

            <form method="POST" autocomplete="on">
                <input type="hidden" name="next" value="<?php echo e($next); ?>">
                <div class="field-wrap">
                    <label class="field" for="u">Benutzername</label>
                    <input type="text" id="u" name="username" required minlength="3" maxlength="32" autocomplete="username" autofocus value="<?php echo e($_POST['username'] ?? ''); ?>">
                </div>
                <div class="field-wrap">
                    <label class="field" for="p"><?php echo $mode === 'setup' ? 'Neues Passwort (mind. 8 Zeichen)' : 'Passwort'; ?></label>
                    <input type="password" id="p" name="password" required <?php echo $mode === 'setup' ? 'minlength="8" autocomplete="new-password" data-meter="1"' : 'autocomplete="current-password"'; ?>>
                    <button type="button" class="eye" data-eye="p" title="Passwort anzeigen">👁</button>
                    <?php if ($mode === 'setup'): ?><div class="meter"><div></div></div><div class="meter-label"></div><?php endif; ?>
                </div>
                <?php if ($mode === 'setup'): ?>
                    <div class="field-wrap">
                        <label class="field" for="p2">Passwort wiederholen</label>
                        <input type="password" id="p2" name="password2" required minlength="8" autocomplete="new-password">
                    </div>
                    <button type="submit" name="setup" class="btn btn-gold" style="margin-top: 8px;">System initialisieren</button>
                <?php else: ?>
                    <label class="check"><input type="checkbox" name="remember" value="1"> Angemeldet bleiben (30 Tage)</label>
                    <button type="submit" name="login" class="btn btn-gold" <?php echo ($locked ?? 0) ? 'disabled' : ''; ?>>Zugriff anfordern</button>
                <?php endif; ?>
            </form>
        </div>
        <div class="gate-foot"><a href="index.php">&larr; Zur Startseite</a></div>
    </div>
</div>

<?php else: ?>
<!-- ======================= Admin-Zentrale ======================= -->
<div class="shell">
    <aside class="side">
        <a href="index.php" class="brand"><span class="brand-logo">◈</span><span>GHOST<b>NODE</b><small>Admin Core</small></span></a>
        <nav>
            <?php foreach ($TABS as $k => [$ic, $label]): ?>
                <a href="admin.php?tab=<?php echo $k; ?>" class="<?php echo $tab === $k ? 'active' : ''; ?>"><span class="ni"><?php echo $ic; ?></span><?php echo e($label); ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="side-foot">
            <a href="index.php" class="side-link">← Zur Startseite</a>
            <button type="button" class="side-link" id="paletteBtn">⌘ Befehle <kbd>Strg K</kbd></button>
        </div>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <div class="hud-tag">Admin Core // <?php echo e($TABS[$tab][1]); ?></div>
                <h1><?php echo $TABS[$tab][0] . ' ' . e($TABS[$tab][1]); ?></h1>
            </div>
            <div class="me">
                <?php echo avatar($me, 'lg'); ?>
                <div><b><?php echo e(user_label($me)); ?></b><small style="color: <?php echo e($me['role_color']); ?>;"><?php echo e($me['role_name']); ?></small></div>
                <form method="POST"><input type="hidden" name="action" value="logout"><button class="btn btn-sm btn-danger" title="Abmelden">⏻</button></form>
            </div>
        </div>

        <?php if (isset($_GET['welcome'])): ?>
            <div class="welcome"><b>✓ SYSTEM INITIALISIERT.</b> Dein Administrator-Konto ist eingerichtet und du bist angemeldet. Unter <a href="admin.php?tab=users">Benutzer</a> kannst du weitere Konten anlegen, unter <a href="admin.php?tab=roles">Rollen &amp; Rechte</a> festlegen, wer was darf.</div>
        <?php endif; ?>
        <?php if ($flash): ?><div class="flash ok"><?php echo e($flash); ?></div><?php endif; ?>
        <?php if ($flash_err): ?><div class="flash err"><?php echo e($flash_err); ?></div><?php endif; ?>
        <?php if ($settings['maintenance']): ?><div class="flash warn">🛠️ Wartungsmodus ist aktiv – Besucher sehen nur den Wartungshinweis. <?php if (can('system.manage')): ?><a href="admin.php?tab=system">Ausschalten</a><?php endif; ?></div><?php endif; ?>

<?php if ($tab === 'overview'): ?>
        <div class="kpis">
            <a class="kpi" href="admin.php?tab=users"><span class="k">Benutzer</span><span class="v" data-count="<?php echo $stats['users']; ?>">0</span><span class="s"><?php echo $stats['users_all'] - $stats['users']; ?> deaktiviert · <?php echo count($roles); ?> Rollen</span><i>👥</i></a>
            <a class="kpi" href="admin.php?tab=pages"><span class="k">Seiten</span><span class="v" data-count="<?php echo $stats['pages']; ?>">0</span><span class="s"><?php echo $stats['custom']; ?> eigene · <?php echo $stats['protected']; ?> geschützt</span><i>🗂️</i></a>
            <a class="kpi" href="linkcheck.php"><span class="k">Links</span><span class="v" data-count="<?php echo count($links); ?>">0</span><span class="s <?php echo $lc_bad ? 'red' : ''; ?>"><?php echo $lc['checked_at'] ? ($lc_bad ? "⚠ $lc_bad kaputt" : '✓ alle erreichbar') : 'noch nicht geprüft'; ?></span><i>🔗</i></a>
            <a class="kpi" href="calendar.php"><span class="k">Termine</span><span class="v" data-count="<?php echo $cal_events; ?>">0</span><span class="s"><?php echo $cal_subs; ?> Abos · <?php echo $upcoming ? e($upcoming[0]['title'] . ' ' . relative_days($upcoming[0]['ts'])) : 'kein Countdown'; ?></span><i>📅</i></a>
            <div class="kpi <?php echo $stats['fails24'] ? 'alert' : ''; ?>"><span class="k">Sicherheit (24 Std.)</span><span class="v" data-count="<?php echo $stats['fails24']; ?>">0</span><span class="s">Fehlversuche · <?php echo $stats['locked']; ?> IP gesperrt</span><i>🛡️</i></div>
            <div class="kpi"><span class="k">Status</span><span class="v small <?php echo $settings['maintenance'] ? 'gold' : 'green'; ?>"><?php echo $settings['maintenance'] ? 'Wartung' : 'Online'; ?></span><span class="s">NSFW für Besucher: <?php echo $settings['show_nsfw'] ? 'sichtbar' : 'versteckt'; ?></span><i>🛰️</i></div>
        </div>

        <div class="grid2">
            <div class="panel">
                <div class="panel-title"><span>// Aktivität – 14 Tage</span><span class="legend"><i class="l1"></i>Logins <i class="l2"></i>Aktionen <i class="l3"></i>Fehlversuche</span></div>
                <?php $max = max(1, max(array_map(function ($a) { return $a['login'] + $a['other'] + $a['sec']; }, $activity))); ?>
                <svg class="chart" viewBox="0 0 560 170" role="img" aria-label="Aktivität der letzten 14 Tage">
                    <?php $i = 0; foreach ($activity as $day => $a): $x = 10 + $i * 39; $y = 140; ?>
                        <?php foreach ([['login', 'var(--accent-cyan)'], ['other', 'var(--accent-gold)'], ['sec', 'var(--accent-red)']] as [$k, $col]): $h = $a[$k] / $max * 120; if ($h <= 0) continue; $y -= $h; ?>
                            <rect x="<?php echo $x; ?>" y="<?php echo round($y, 1); ?>" width="26" height="<?php echo round($h, 1); ?>" fill="<?php echo $col; ?>" class="bar"><title><?php echo date('d.m.', strtotime($day)) . ': ' . $a[$k] . ' ' . ['login' => 'Logins', 'other' => 'Aktionen', 'sec' => 'Fehlversuche'][$k]; ?></title></rect>
                        <?php endforeach; ?>
                        <text x="<?php echo $x + 13; ?>" y="160" text-anchor="middle"><?php echo date('d.', strtotime($day)); ?></text>
                    <?php $i++; endforeach; ?>
                    <line x1="5" y1="140.5" x2="555" y2="140.5" />
                </svg>
            </div>
            <div class="panel">
                <div class="panel-title"><span>// Schnellzugriff</span></div>
                <div class="quick">
                    <?php if (can('users.manage')): ?><a href="admin.php?tab=users#new">➕<span>Benutzer anlegen</span></a><?php endif; ?>
                    <?php if (can('pages.manage')): ?><a href="index.php?edit=1#new">✨<span>Neue Seite</span></a><a href="index.php?edit=1">✎<span>Index sortieren</span></a><?php endif; ?>
                    <?php if (can('linkcheck.run')): ?><a href="linkcheck.php">🩺<span>Link-Check</span></a><?php endif; ?>
                    <?php if (can('backup.download')): ?><a href="backup.php">💾<span>Backup</span></a><?php endif; ?>
                    <a href="calendar.php">📅<span>Kalender</span></a>
                    <?php if (can('budget.access')): ?><a href="budget.php">💶<span>Budget</span></a><?php endif; ?>
                    <?php if (can('settings.nsfw')): ?>
                        <form method="POST"><input type="hidden" name="action" value="toggle_nsfw"><input type="hidden" name="back" value="admin.php"><button>🔞<span>NSFW <?php echo $settings['show_nsfw'] ? 'ausblenden' : 'einblenden'; ?></span></button></form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="grid2">
            <div class="panel">
                <div class="panel-title"><span>// Zuletzt passiert</span><?php if (can('audit.view')): ?><a href="admin.php?tab=audit" class="btn btn-sm">Alles</a><?php endif; ?></div>
                <div class="feed-list">
                    <?php foreach ($recent as $r): ?>
                        <div class="fl lv-<?php echo e($r['level']); ?>"><span class="fl-dot"></span><div><b><?php echo e($r['action']); ?></b><?php echo $r['detail'] !== '' ? ' <span class="muted">· ' . e(mb_substr($r['detail'], 0, 70)) . '</span>' : ''; ?><small><?php echo e($r['username'] ?: 'System'); ?> · <?php echo e(ago($r['ts'])); ?></small></div></div>
                    <?php endforeach; ?>
                    <?php if (!$recent): ?><div class="muted">Noch keine Einträge.</div><?php endif; ?>
                </div>
            </div>
            <div class="panel">
                <div class="panel-title"><span>// Team</span><?php if (can('users.manage')): ?><a href="admin.php?tab=users" class="btn btn-sm">Verwalten</a><?php endif; ?></div>
                <div class="team">
                    <?php foreach ($users as $u): ?>
                        <div class="tm <?php echo $u['active'] ? '' : 'off'; ?>"><?php echo avatar($u); ?><div><b><?php echo e($u['display_name'] ?: $u['username']); ?></b><small style="color: <?php echo e($u['role_color']); ?>;"><?php echo e($u['role_name']); ?></small></div><span class="muted"><?php echo e(ago($u['last_login'])); ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

<?php elseif ($tab === 'users'): ?>
        <div class="panel">
            <div class="panel-title"><span>// <?php echo count($users); ?> Benutzer</span><button type="button" class="btn btn-sm btn-gold" data-user-edit="new" id="newUserBtn">＋ Benutzer anlegen</button></div>
            <div class="table-wrap">
                <table class="tbl">
                    <thead><tr><th>Benutzer</th><th>Rolle</th><th>Status</th><th>Letzter Login</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr class="<?php echo $u['active'] ? '' : 'off'; ?>">
                            <td><div class="ucell"><?php echo avatar($u); ?><div><b><?php echo e($u['display_name'] ?: $u['username']); ?></b><small>@<?php echo e($u['username']); ?><?php echo $u['id'] == $me['id'] ? ' · du' : ''; ?><?php echo $u['note'] !== '' ? ' · ' . e($u['note']) : ''; ?></small></div></div></td>
                            <td><span class="role-pill" style="--rc: <?php echo e($u['role_color']); ?>;"><?php echo e($u['role_name']); ?></span></td>
                            <td><?php echo $u['active'] ? '<span class="dot-ok">aktiv</span>' : '<span class="dot-off">deaktiviert</span>'; ?></td>
                            <td><?php echo e(ago($u['last_login'])); ?><?php echo $u['last_ip'] ? '<small class="muted"> · ' . e($u['last_ip']) . '</small>' : ''; ?></td>
                            <td class="actions">
                                <button type="button" class="btn btn-sm btn-gold" data-user-edit="<?php echo e(json_encode(array_intersect_key($u, array_flip(['id', 'username', 'display_name', 'role_id', 'active', 'note'])))); ?>">✎</button>
                                <form method="POST"><input type="hidden" name="tab" value="users"><input type="hidden" name="action" value="user_logout"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><button class="btn btn-sm" title="Alle Sitzungen beenden">⏻</button></form>
                                <?php if ($u['id'] != $me['id']): ?>
                                    <form method="POST" onsubmit="return confirm('Benutzer „<?php echo e(addslashes($u['username'])); ?>“ löschen?');"><input type="hidden" name="tab" value="users"><input type="hidden" name="action" value="user_delete"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><button class="btn btn-sm btn-danger" title="Löschen">✕</button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="modal-overlay" id="userModal" role="dialog" aria-modal="true">
            <form method="POST" class="modal-box" id="userForm" autocomplete="off">
                <button type="button" class="modal-close" data-close aria-label="Schließen">&times;</button>
                <div class="panel-title" id="userFormTitle">// Benutzer anlegen</div>
                <input type="hidden" name="tab" value="users"><input type="hidden" name="action" value="user_save"><input type="hidden" name="id" value="">
                <div class="f2">
                    <div><label class="field">Benutzername *</label><input type="text" name="username" required minlength="3" maxlength="32" autocomplete="off"></div>
                    <div><label class="field">Anzeigename</label><input type="text" name="display_name" maxlength="60"></div>
                </div>
                <label class="field">Rolle *</label>
                <div class="role-pick">
                    <?php foreach ($roles as $r): ?>
                        <label class="rp" style="--rc: <?php echo e($r['color']); ?>;"><input type="radio" name="role_id" value="<?php echo (int)$r['id']; ?>" <?php echo $r['name'] === 'Mitglied' ? 'checked' : ''; ?>><span><b><?php echo e($r['name']); ?></b><small><?php echo e($r['description']); ?></small></span></label>
                    <?php endforeach; ?>
                </div>
                <label class="field" id="pwLabel">Passwort *</label>
                <div class="pw-row"><input type="text" name="password" id="userPw" minlength="8" autocomplete="new-password" spellcheck="false"><button type="button" class="btn btn-sm" id="genPw" title="Sicheres Passwort erzeugen">🎲 Erzeugen</button><button type="button" class="btn btn-sm" id="copyPw" title="Kopieren">⧉</button></div>
                <p class="muted small" id="pwHint">Mindestens 8 Zeichen. Gib es dem Benutzer sicher weiter – er kann es danach unter „Mein Konto“ ändern.</p>
                <label class="field">Notiz (nur für Admins)</label>
                <input type="text" name="note" maxlength="200" placeholder="z. B. Discord-Name, Zweck des Kontos">
                <label class="check"><input type="checkbox" name="active" value="1" checked> Konto aktiv</label>
                <button type="submit" class="btn btn-gold" style="width: 100%;">Speichern</button>
            </form>
        </div>

<?php elseif ($tab === 'roles'): ?>
        <p class="muted" style="margin-top: 0;">Jeder Benutzer hat genau eine Rolle. Die Rolle <b>Administrator</b> hat immer alle Rechte. Seiten können zusätzlich auf bestimmte Rollen beschränkt werden (Index → Bearbeiten).</p>
        <div class="roles">
            <?php foreach ($roles as $r): $rp = $r['is_system'] ? array_keys(PERMISSIONS) : ($role_perms[(int)$r['id']] ?? []); ?>
                <form method="POST" class="panel role-card" style="--rc: <?php echo e($r['color']); ?>;">
                    <input type="hidden" name="tab" value="roles"><input type="hidden" name="action" value="role_save"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <div class="rc-head">
                        <input type="color" name="color" value="<?php echo e($r['color']); ?>" title="Farbe">
                        <input type="text" name="name" value="<?php echo e($r['name']); ?>" maxlength="40" class="rc-name" <?php echo $r['is_system'] ? 'readonly' : ''; ?>>
                        <span class="muted small"><?php echo (int)$r['user_count']; ?> Benutzer · <?php echo count($rp); ?>/<?php echo count(PERMISSIONS); ?> Rechte</span>
                    </div>
                    <input type="text" name="description" value="<?php echo e($r['description']); ?>" maxlength="200" placeholder="Beschreibung" class="rc-desc">
                    <div class="perm-grid">
                        <?php foreach ($perm_groups as $group => $perms): ?>
                            <div class="pg"><div class="pg-title"><?php echo e($group); ?></div>
                                <?php foreach ($perms as $key => $label): ?>
                                    <label class="perm"><input type="checkbox" name="perms[]" value="<?php echo e($key); ?>" <?php echo in_array($key, $rp, true) ? 'checked' : ''; ?> <?php echo $r['is_system'] ? 'disabled' : ''; ?>><span><?php echo e($label); ?><code><?php echo e($key); ?></code></span></label>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="rc-actions">
                        <?php if (!$r['is_system']): ?>
                            <button type="button" class="btn btn-sm" data-perm-all>Alle</button><button type="button" class="btn btn-sm" data-perm-none>Keine</button>
                        <?php else: ?><span class="muted small">🔒 Systemrolle – hat immer alle Rechte</span><?php endif; ?>
                        <span style="flex: 1;"></span>
                        <?php if (!$r['is_system']): ?><button type="submit" name="action" value="role_delete" class="btn btn-sm btn-danger" formnovalidate onclick="return confirm('Rolle löschen?');">Löschen</button><?php endif; ?>
                        <button type="submit" class="btn btn-sm btn-gold">Speichern</button>
                    </div>
                </form>
            <?php endforeach; ?>
            <form method="POST" class="panel role-card new">
                <input type="hidden" name="tab" value="roles"><input type="hidden" name="action" value="role_save"><input type="hidden" name="id" value="0">
                <div class="panel-title"><span>// Neue Rolle</span></div>
                <div class="rc-head"><input type="color" name="color" value="#bd93f9"><input type="text" name="name" maxlength="40" placeholder="z. B. Moderator" class="rc-name" required></div>
                <input type="text" name="description" maxlength="200" placeholder="Beschreibung" class="rc-desc">
                <p class="muted small">Rechte kannst du nach dem Anlegen festlegen.</p>
                <button type="submit" class="btn btn-sm btn-gold">＋ Rolle anlegen</button>
            </form>
        </div>

<?php elseif ($tab === 'pages'): ?>
        <div class="panel">
            <div class="panel-title"><span>// <?php echo count($pages); ?> Seiten</span><span><a href="index.php?edit=1#new" class="btn btn-sm btn-gold">＋ Neue Seite</a> <a href="index.php?edit=1" class="btn btn-sm">✎ Index sortieren</a></span></div>
            <div class="table-wrap">
                <table class="tbl">
                    <thead><tr><th>Seite</th><th>Kategorie</th><th>Art</th><th>Zugriff</th><th>Geändert</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($pages as $p): ?>
                        <tr class="<?php echo $p['hidden'] ? 'off' : ''; ?>">
                            <td><div class="ucell"><span class="pg-ic"><?php echo e($p['icon'] ?: '📄'); ?></span><div><b><?php echo e($p['title']); ?></b><small><?php echo e($p['description'] ?: '–'); ?></small></div></div></td>
                            <td><?php echo e(($p['cat_icon'] ? $p['cat_icon'] . ' ' : '') . ($p['cat_name'] ?? 'ohne')); ?></td>
                            <td><?php echo $p['kind'] === 'file' ? '<span class="muted">Datei</span>' : e(PAGE_TEMPLATES[$p['template']][0] . ' ' . PAGE_TEMPLATES[$p['template']][1]); ?><?php echo $p['hidden'] ? ' <span class="muted small">· versteckt</span>' : ''; ?></td>
                            <td><?php echo e(PAGE_ACCESS[$p['access']]); ?><?php if ($p['access'] === 'roles'): ?><small class="muted"> · <?php echo e(implode(', ', array_map(function ($id) use ($role_names) { return $role_names[$id] ?? '?'; }, array_filter(explode(',', $p['allowed_roles']))))); ?></small><?php endif; ?></td>
                            <td><?php echo e(ago($p['updated_at'])); ?><?php echo $p['creator'] ? '<small class="muted"> · ' . e($p['creator']) . '</small>' : ''; ?></td>
                            <td class="actions"><a href="<?php echo e(page_href($p)); ?>" class="btn btn-sm" <?php echo $p['template'] === 'external' ? 'target="_blank" rel="noopener"' : ''; ?>>Öffnen</a> <a href="index.php?edit=1#page-<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-gold">✎</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="tpl-info">
            <?php foreach (PAGE_TEMPLATES as [$ic, $name, $desc]): ?><div class="panel"><b><?php echo $ic . ' ' . e($name); ?></b><p class="muted small"><?php echo e($desc); ?></p></div><?php endforeach; ?>
        </div>

<?php elseif ($tab === 'audit'): ?>
        <form method="GET" class="audit-filter">
            <input type="hidden" name="tab" value="audit">
            <input type="search" name="q" value="<?php echo e($_GET['q'] ?? ''); ?>" placeholder="Suchen: Aktion, Benutzer, IP …">
            <select name="level"><option value="">Alle Stufen</option><?php foreach (['info' => 'Info', 'warn' => 'Warnung', 'security' => 'Sicherheit'] as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo ($_GET['level'] ?? '') === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select>
            <button class="btn btn-sm">Filtern</button>
            <a href="admin.php?tab=audit&amp;export=1" class="btn btn-sm">⬇ CSV</a>
        </form>
        <div class="panel">
            <div class="panel-title"><span>// <?php echo $audit_total; ?> Einträge</span><span class="muted small">Seite <?php echo $page_no; ?> von <?php echo max(1, ceil($audit_total / 50)); ?></span></div>
            <div class="table-wrap">
                <table class="tbl audit">
                    <thead><tr><th>Zeit</th><th>Benutzer</th><th>Aktion</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($audit as $a): ?>
                        <tr class="lvl-<?php echo e($a['level']); ?>" title="<?php echo e($a['agent']); ?>">
                            <td class="nowrap"><?php echo e(date('d.m.y H:i:s', strtotime($a['ts']))); ?></td>
                            <td><?php echo e($a['username'] ?: '–'); ?></td>
                            <td><span class="lvl"><?php echo ['info' => '●', 'warn' => '▲', 'security' => '◆'][$a['level']] ?? '●'; ?></span> <b><?php echo e($a['action']); ?></b><?php echo $a['detail'] !== '' ? '<br><small class="muted">' . e($a['detail']) . '</small>' : ''; ?></td>
                            <td class="mono"><?php echo e($a['ip']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$audit): ?><tr><td colspan="4" class="muted">Keine Einträge.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="pager">
                <?php $qs = function ($p) { return 'admin.php?' . http_build_query(['tab' => 'audit', 'q' => $_GET['q'] ?? '', 'level' => $_GET['level'] ?? '', 'page' => $p]); }; ?>
                <?php if ($page_no > 1): ?><a class="btn btn-sm" href="<?php echo e($qs($page_no - 1)); ?>">‹ Neuer</a><?php endif; ?>
                <?php if ($page_no * 50 < $audit_total): ?><a class="btn btn-sm" href="<?php echo e($qs($page_no + 1)); ?>">Älter ›</a><?php endif; ?>
            </div>
        </div>

<?php elseif ($tab === 'system'): ?>
        <div class="grid2">
            <form method="POST" class="panel">
                <input type="hidden" name="tab" value="system"><input type="hidden" name="action" value="settings_save">
                <div class="panel-title"><span>// Webseite</span></div>
                <label class="field">Titel der Startseite</label><input type="text" name="site_title" value="<?php echo e($settings['site_title']); ?>" maxlength="60">
                <label class="field">Untertitel</label><input type="text" name="site_tagline" value="<?php echo e($settings['site_tagline']); ?>" maxlength="120">
                <div class="maint <?php echo $settings['maintenance'] ? 'on' : ''; ?>">
                    <label class="check big"><input type="checkbox" name="maintenance" value="1" <?php echo $settings['maintenance'] ? 'checked' : ''; ?>> 🛠️ Wartungsmodus</label>
                    <p class="muted small">Alle ohne Admin-Zugang sehen nur einen Hinweis. Anmelden bleibt möglich.</p>
                    <input type="text" name="maintenance_message" value="<?php echo e($settings['maintenance_message']); ?>" maxlength="300" placeholder="Hinweistext">
                </div>
                <button class="btn btn-gold" style="margin-top: 12px;">Speichern</button>
            </form>
            <div class="panel">
                <div class="panel-title"><span>// Systemstatus</span></div>
                <table class="sys">
                    <?php foreach ($checks as [$label, $ok, $detail]): ?>
                        <tr><td><span class="sdot <?php echo $ok ? 'ok' : 'bad'; ?>"></span><?php echo e($label); ?></td><td class="<?php echo $ok ? '' : 'red'; ?>"><?php echo e($detail); ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        <div class="grid2">
            <div class="panel">
                <div class="panel-title"><span>// Speicher</span><span class="muted small"><?php echo bytes(array_sum($storage)); ?> gesamt</span></div>
                <?php $smax = max(1, max($storage ?: [1])); foreach ($storage as $name => $size): ?>
                    <div class="stor"><span><?php echo e($name); ?></span><span class="stor-bar"><i style="width: <?php echo round($size / $smax * 100); ?>%;"></i></span><span class="mono"><?php echo bytes((int)$size); ?></span></div>
                <?php endforeach; ?>
            </div>
            <div class="panel">
                <div class="panel-title"><span>// Werkzeuge</span></div>
                <div class="tools">
                    <form method="POST"><input type="hidden" name="tab" value="system"><input type="hidden" name="action" value="clear_feed_cache"><button class="btn btn-sm">📡 Feed neu laden</button></form>
                    <form method="POST" onsubmit="return confirm('Alle anderen Benutzer abmelden?');"><input type="hidden" name="tab" value="system"><input type="hidden" name="action" value="logout_all"><button class="btn btn-sm btn-danger">⏻ Alle anderen abmelden</button></form>
                    <form method="POST"><input type="hidden" name="tab" value="system"><input type="hidden" name="action" value="unlock_ips"><button class="btn btn-sm">🔓 Login-Sperren aufheben</button></form>
                    <form method="POST" onsubmit="return confirm('Protokolleinträge löschen?');"><input type="hidden" name="tab" value="system"><input type="hidden" name="action" value="audit_prune"><select name="days" style="width: auto; padding: 4px 8px;"><option value="30">älter als 30 Tage</option><option value="90" selected>älter als 90 Tage</option><option value="365">älter als 1 Jahr</option></select><button class="btn btn-sm">🧹 Protokoll aufräumen</button></form>
                    <?php if (can('backup.download')): ?><a href="backup.php" class="btn btn-sm">💾 Backup herunterladen</a><?php endif; ?>
                </div>
                <form method="POST" class="tools" style="margin-top: 14px;" onsubmit="return confirm('Alle im Browser gemachten Änderungen an diesem Hub verwerfen? (Eine Kopie landet im Ordner archive/)');">
                    <input type="hidden" name="tab" value="system"><input type="hidden" name="action" value="reset_hub">
                    <select name="hub" style="width: auto;"><?php foreach ($hubs as $h): ?><option value="<?php echo e($h); ?>"><?php echo e($h); ?><?php echo is_file(hub_data_file($h)) ? ' (bearbeitet)' : ''; ?></option><?php endforeach; ?></select>
                    <button class="btn btn-sm btn-danger">Hub zurücksetzen</button>
                </form>
            </div>
        </div>

<?php elseif ($tab === 'account'): ?>
        <div class="grid2">
            <form method="POST" class="panel" autocomplete="off">
                <input type="hidden" name="tab" value="account"><input type="hidden" name="action" value="account_save">
                <div class="panel-title"><span>// Profil &amp; Passwort</span></div>
                <div class="profile"><?php echo avatar($me, 'xl'); ?><div><b><?php echo e(user_label($me)); ?></b><small>@<?php echo e($me['username']); ?> · <span style="color: <?php echo e($me['role_color']); ?>;"><?php echo e($me['role_name']); ?></span></small><small>dabei seit <?php echo e(date('d.m.Y', strtotime($me['created_at']))); ?> · Passwort geändert <?php echo e(ago($me['password_changed'])); ?></small></div></div>
                <label class="field">Anzeigename</label><input type="text" name="display_name" value="<?php echo e($me['display_name']); ?>" maxlength="60">
                <div class="field-wrap"><label class="field">Neues Passwort (leer = unverändert)</label><input type="password" name="password" id="np" minlength="8" autocomplete="new-password" data-meter="1"><button type="button" class="eye" data-eye="np">👁</button><div class="meter"><div></div></div><div class="meter-label"></div></div>
                <label class="field">Neues Passwort wiederholen</label><input type="password" name="password2" minlength="8" autocomplete="new-password">
                <label class="field">Aktuelles Passwort (nur bei Passwortwechsel)</label><input type="password" name="current" autocomplete="current-password">
                <button class="btn btn-gold" style="margin-top: 12px; width: 100%;">Speichern</button>
            </form>
            <div class="panel">
                <div class="panel-title"><span>// Meine Rechte</span></div>
                <div class="my-perms">
                    <?php foreach (PERMISSIONS as $k => [$label]): ?><span class="<?php echo can($k) ? 'yes' : 'no'; ?>"><?php echo can($k) ? '✓' : '✕'; ?> <?php echo e($label); ?></span><?php endforeach; ?>
                </div>
                <div class="panel-title" style="margin-top: 20px;"><span>// Meine letzten Aktivitäten</span></div>
                <div class="feed-list">
                    <?php foreach ($my_log as $r): ?><div class="fl lv-<?php echo e($r['level']); ?>"><span class="fl-dot"></span><div><b><?php echo e($r['action']); ?></b><small><?php echo e(ago($r['ts'])); ?> · <?php echo e($r['ip']); ?></small></div></div><?php endforeach; ?>
                </div>
                <form method="POST" style="margin-top: 14px;" onsubmit="return confirm('Alle anderen Geräte/Browser abmelden?');"><input type="hidden" name="tab" value="account"><input type="hidden" name="action" value="account_logout_everywhere"><button class="btn btn-sm btn-danger" style="width: 100%;">Alle anderen Geräte abmelden</button></form>
            </div>
        </div>
<?php endif; ?>
    </main>
</div>

<!-- Befehlspalette -->
<div class="palette" id="palette" hidden>
    <div class="palette-box">
        <input type="text" id="paletteInput" placeholder="Wohin? Seite, Bereich oder Aktion …" autocomplete="off">
        <div class="palette-list" id="paletteList"></div>
        <div class="palette-foot"><kbd>↑</kbd><kbd>↓</kbd> wählen · <kbd>Enter</kbd> öffnen · <kbd>Esc</kbd> schließen</div>
    </div>
</div>
<script>window.PALETTE = <?php echo json_encode($palette, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
<?php endif; ?>

<script src="assets/admin.js"></script>
</body>
</html>
