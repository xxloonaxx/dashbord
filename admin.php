<?php
/**
 * Admin-Zentrale: Ersteinrichtung, Login, Einstellungen (NSFW), Sicherheit, Systemstatus.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/linkcheck.php';
require_once __DIR__ . '/inc/countdown.php';

/** Nur lokale Ziele für die Weiterleitung nach dem Login zulassen */
function safe_next(?string $next): string {
    return ($next && preg_match('/^[A-Za-z0-9_\-]+\.php(\?[A-Za-z0-9_\-=&%.+]*)?$/', $next)) ? $next : 'admin.php';
}

$next = safe_next($_GET['next'] ?? $_POST['next'] ?? null);
$error = '';
$configured = auth_configured();

// ---------- Ersteinrichtung ----------
if (!$configured) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup'])) {
        $user = trim($_POST['username'] ?? '');
        $error = auth_validate($user, $_POST['password'] ?? '', $_POST['password2'] ?? '');
        if (!$error && !same_origin_request()) $error = 'Anfrage abgelehnt.';
        if (!$error) {
            auth_create($user, $_POST['password']);
            auth_start_session(auth_account(), false);
            auth_log('Konto eingerichtet', $user);
            header('Location: admin.php?welcome=1');
            exit;
        }
    }
    $mode = 'setup';
}
// ---------- Login ----------
elseif (!is_admin()) {
    $locked = auth_locked_seconds();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        if ($locked) {
            $error = 'Zu viele Fehlversuche – bitte warten.';
        } elseif (!same_origin_request()) {
            $error = 'Anfrage abgelehnt.';
        } elseif (auth_login(trim($_POST['username'] ?? ''), $_POST['password'] ?? '', !empty($_POST['remember']))) {
            header('Location: ' . $next);
            exit;
        } else {
            $locked = auth_locked_seconds();
            $error = $locked ? 'Zu viele Fehlversuche – Login für ' . ceil($locked / 60) . ' Minuten gesperrt.' : 'Benutzername oder Passwort falsch.';
        }
    }
    $mode = 'login';
}
// ---------- Angemeldet: Aktionen ----------
else {
    $mode = 'dashboard';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        guard_post();
        $action = $_POST['action'] ?? '';
        $msg = '';
        $back = safe_next($_POST['back'] ?? 'admin.php');

        switch ($action) {
            case 'logout':
                auth_log('Logout', auth_user());
                auth_logout();
                header('Location: index.php');
                exit;

            case 'toggle_nsfw':
                $on = !site_settings()['show_nsfw'];
                save_site_setting('show_nsfw', $on);
                auth_log($on ? 'NSFW eingeblendet' : 'NSFW ausgeblendet', auth_user());
                header('Location: ' . $back);
                exit;

            case 'change_credentials':
                $acc = auth_account();
                $user = trim($_POST['username'] ?? '');
                $new = (string)($_POST['password'] ?? '');
                if (!password_verify((string)($_POST['current'] ?? ''), $acc['password_hash'])) {
                    $error = 'Das aktuelle Passwort ist falsch.';
                    auth_log('Passwortänderung abgelehnt', auth_user());
                    break;
                }
                if ($new === '') { // nur Benutzername ändern
                    $new = (string)$_POST['current'];
                    $_POST['password2'] = $new;
                }
                if ($error = auth_validate($user, $new, (string)($_POST['password2'] ?? ''))) break;
                $acc['username'] = $user;
                $acc['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
                $acc['password_changed'] = time();
                $acc['session_version'] = ($acc['session_version'] ?? 0) + 1;   // alle anderen Geräte abmelden
                secure_save(AUTH_FILE, $acc);
                auth_start_session($acc, !empty($_SESSION['auth']['remember']));
                auth_log('Zugangsdaten geändert', $user);
                $msg = 'Zugangsdaten gespeichert. Andere Geräte wurden abgemeldet.';
                break;

            case 'logout_everywhere':
                $acc = auth_account();
                $acc['session_version'] = ($acc['session_version'] ?? 0) + 1;
                secure_save(AUTH_FILE, $acc);
                auth_start_session($acc, !empty($_SESSION['auth']['remember']));
                auth_log('Alle anderen Geräte abgemeldet', auth_user());
                $msg = 'Alle anderen Sitzungen wurden beendet.';
                break;

            case 'clear_feed_cache':
                @unlink(__DIR__ . '/feed_cache.json');
                $msg = 'Feed-Cache geleert – der Feed lädt beim nächsten Aufruf neu.';
                break;

            case 'reset_hub':
                $hub = basename($_POST['hub'] ?? '');
                if (in_array($hub, find_hub_files(), true)) {
                    require_once __DIR__ . '/inc/hub.php';
                    $file = hub_data_file($hub);
                    if (is_file($file)) {
                        // Sicherheitskopie im Archiv-Ordner behalten
                        $archive = __DIR__ . '/archive';
                        if (!is_dir($archive)) mkdir($archive, 0755, true);
                        rename($file, $archive . '/' . pathinfo($file, PATHINFO_FILENAME) . '_backup_' . date('Y-m-d_His') . '.json');
                        auth_log('Hub zurückgesetzt: ' . $hub, auth_user());
                        $msg = '„' . $hub . '“ wurde auf den Startbestand zurückgesetzt (Kopie im Ordner archive/).';
                    } else {
                        $msg = '„' . $hub . '“ nutzt bereits den Startbestand.';
                    }
                }
                break;
        }

        if (!$error) {
            header('Location: admin.php' . ($msg ? '?msg=' . urlencode($msg) : ''));
            exit;
        }
    }
}

// ---------- Daten fürs Dashboard ----------
if ($mode === 'dashboard') {
    $acc = auth_account();
    $settings = site_settings();
    $links = collect_hub_links();
    $nsfw_count = 0;
    foreach ($links as $url => $info) if (is_nsfw_link(['nsfw' => $info['nsfw'] ?? false], $info['category'])) $nsfw_count++;
    $hubs = find_hub_files();
    require_once __DIR__ . '/inc/hub.php';

    $lc = load_linkcheck();
    $lc_bad = 0;
    foreach ($lc['results'] as $r) if (in_array($r['status'], ['dead', 'broken'], true)) $lc_bad++;

    [$upcoming] = sorted_countdowns(load_countdowns()['events']);
    $budget = load_json(__DIR__ . '/budget_data.json', ['month_name' => '–', 'entries' => []]);
    $b_net = 0;
    foreach ($budget['entries'] as $en) $b_net += (($en['section'] ?? '') === 'income' ? 1 : -1) * (float)$en['amount'];
    $con = load_json(__DIR__ . '/con_data.json', ['con_name' => '–', 'total_budget' => 0, 'expenses' => []]);
    $con_rest = (float)$con['total_budget'] - array_sum(array_column($con['expenses'] ?? [], 'amount'));
    $feed_sources = load_json(__DIR__ . '/feed_sources.json', null);

    $log = secure_load(AUTH_LOG_FILE, ['entries' => []])['entries'];
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $server_soft = (string)($_SERVER['SERVER_SOFTWARE'] ?? '');
    $checks = [
        ['HTTPS-Verbindung', $https, $https ? 'verschlüsselt' : 'Passwort wird unverschlüsselt übertragen – HTTPS beim Hoster aktivieren!'],
        ['PHP-Version', version_compare(PHP_VERSION, '7.4', '>='), PHP_VERSION],
        ['cURL (Feed & Link-Check)', function_exists('curl_multi_init'), function_exists('curl_multi_init') ? 'aktiv' : 'fehlt'],
        ['ZIP (Backup)', class_exists('ZipArchive'), class_exists('ZipArchive') ? 'aktiv' : 'fehlt – Backup als JSON'],
        ['Ordner beschreibbar', is_writable(__DIR__), is_writable(__DIR__) ? 'ja' : 'nein – Daten können nicht gespeichert werden'],
        ['.htaccess (Datenschutz)', is_file(__DIR__ . '/.htaccess'), stripos($server_soft, 'nginx') !== false ? 'nginx erkannt – .htaccess wirkt dort nicht!' : (is_file(__DIR__ . '/.htaccess') ? 'vorhanden' : 'fehlt')],
        ['Zeitzone', true, date_default_timezone_get() . ' · ' . date('H:i')],
    ];
}

$flash = $_GET['msg'] ?? '';
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
    <style>
        .gate { max-width: 440px; margin: 6vh auto 0; }
        .gate-box { position: relative; background: var(--panel-bg); border: 1px solid var(--border-color); padding: 34px 30px 30px; clip-path: polygon(0 0, calc(100% - 18px) 0, 100% 18px, 100% 100%, 18px 100%, 0 calc(100% - 18px)); box-shadow: 0 0 40px rgba(56, 213, 230, 0.08); }
        .gate-box::before { content: ""; position: absolute; inset: 0; background: linear-gradient(transparent 0, rgba(56, 213, 230, 0.06) 50%, transparent 100%); background-size: 100% 220%; animation: scan 4s linear infinite; pointer-events: none; }
        @keyframes scan { from { background-position: 0 -120%; } to { background-position: 0 120%; } }
        @media (prefers-reduced-motion: reduce) { .gate-box::before { animation: none; } }
        .gate-icon { width: 64px; height: 64px; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; font-size: 1.9rem; border: 1px solid var(--accent-gold); color: var(--accent-gold); box-shadow: 0 0 18px rgba(243, 194, 98, 0.25), inset 0 0 18px rgba(243, 194, 98, 0.1); clip-path: polygon(50% 0, 100% 25%, 100% 75%, 50% 100%, 0 75%, 0 25%); background: rgba(243, 194, 98, 0.06); }
        .gate h1 { text-align: center; font-size: 1.5rem; }
        .gate .subtitle { text-align: center; margin-bottom: 24px; font-size: 0.75rem; }
        .field-wrap { position: relative; margin-bottom: 14px; }
        .field-wrap .eye { position: absolute; right: 8px; top: 18px; height: 39px; background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 1rem; }
        .field-wrap .eye:hover { color: var(--accent-cyan); }
        .field-wrap input[type=password], .field-wrap input[data-pw] { padding-right: 40px; }
        .meter { height: 4px; background: rgba(255, 255, 255, 0.06); margin-top: 6px; }
        .meter div { height: 100%; width: 0; transition: all 0.3s; }
        .meter-label { font-size: 0.65rem; letter-spacing: 1px; text-transform: uppercase; color: var(--text-muted); margin-top: 4px; min-height: 1em; }
        .err { border: 1px solid var(--accent-red); color: var(--accent-red); background: rgba(255, 85, 85, 0.08); padding: 10px 12px; font-size: 0.82rem; margin-bottom: 16px; }
        .info { border: 1px solid var(--accent-gold); color: var(--accent-gold); background: rgba(243, 194, 98, 0.08); padding: 10px 12px; font-size: 0.8rem; margin-bottom: 16px; line-height: 1.5; }
        .check { display: flex; align-items: center; gap: 8px; font-size: 0.8rem; color: var(--text-muted); margin: 4px 0 18px; }
        .gate .btn { width: 100%; padding: 13px; }
        .gate-foot { text-align: center; margin-top: 16px; font-size: 0.75rem; }
        .gate-foot a { color: var(--text-muted); }

        .tiles { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 25px; }
        .tile { display: block; text-decoration: none; color: inherit; background: var(--panel-bg); border: 1px solid var(--border-color); padding: 14px 16px; clip-path: var(--cut-sm); transition: all 0.2s; position: relative; overflow: hidden; }
        a.tile:hover { border-color: var(--accent-cyan); transform: translateY(-2px); box-shadow: 0 0 18px rgba(56, 213, 230, 0.12); }
        .tile .k { font-size: 0.62rem; letter-spacing: 2px; text-transform: uppercase; color: var(--text-muted); }
        .tile .v { font-size: 1.45rem; font-weight: 800; color: #fff; margin: 4px 0 2px; font-variant-numeric: tabular-nums; }
        .tile .s { font-size: 0.7rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tile .bg { position: absolute; right: 8px; bottom: -6px; font-size: 2.6rem; opacity: 0.08; }
        .green { color: var(--accent-green) !important; } .red { color: var(--accent-red) !important; } .gold { color: var(--accent-gold) !important; } .purple { color: var(--accent-purple) !important; }

        .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 0 20px; }
        .toggle-row { display: flex; justify-content: space-between; align-items: center; gap: 16px; }
        .toggle-row p { margin: 4px 0 0; font-size: 0.78rem; color: var(--text-muted); line-height: 1.5; }
        .switch { position: relative; width: 64px; height: 32px; border: 1px solid var(--border-color); background: rgba(4, 6, 9, 0.9); cursor: pointer; flex-shrink: 0; padding: 0; transition: all 0.25s; }
        .switch::after { content: ""; position: absolute; top: 3px; left: 3px; width: 24px; height: 24px; background: var(--text-muted); transition: all 0.25s; }
        .switch.on { border-color: var(--accent-red); box-shadow: 0 0 14px rgba(255, 85, 85, 0.3); }
        .switch.on::after { left: 35px; background: var(--accent-red); box-shadow: 0 0 10px var(--accent-red); }
        .actions { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 8px; }
        .actions .btn, .actions form { width: 100%; margin: 0; }
        .actions .btn { text-align: center; }
        .sys { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        .sys td { padding: 7px 4px; border-bottom: 1px solid rgba(56, 213, 230, 0.1); vertical-align: top; }
        .sys td:first-child { color: var(--text-muted); white-space: nowrap; width: 1%; padding-right: 14px; }
        .dot { display: inline-block; width: 8px; height: 8px; margin-right: 8px; box-shadow: 0 0 8px currentColor; background: currentColor; }
        .log { max-height: 320px; overflow-y: auto; }
        .log-row { display: grid; grid-template-columns: 110px 1fr auto; gap: 10px; padding: 6px 4px; border-bottom: 1px solid rgba(56, 213, 230, 0.08); font-size: 0.76rem; }
        .log-row .t { color: var(--text-muted); font-variant-numeric: tabular-nums; }
        .log-row .ip { color: var(--text-muted); font-family: ui-monospace, monospace; font-size: 0.7rem; }
        .log-row.bad .ev { color: var(--accent-red); }
        .welcome { border: 1px solid var(--accent-green); background: rgba(80, 250, 123, 0.07); padding: 16px 18px; margin-bottom: 22px; font-size: 0.85rem; line-height: 1.6; }
        .welcome b { color: var(--accent-green); letter-spacing: 1px; }
        @media (max-width: 800px) { .tiles { grid-template-columns: 1fr 1fr; } .cols { grid-template-columns: 1fr; } .log-row { grid-template-columns: 1fr; gap: 0; } }
    </style>
</head>
<body>
<div class="wrapper">

<?php if ($mode === 'setup' || $mode === 'login'): ?>
    <div class="gate">
        <div class="gate-box">
            <div class="gate-icon"><?php echo $mode === 'setup' ? '⚡' : '🔒'; ?></div>
            <div class="hud-tag" style="justify-content: center;"><?php echo $mode === 'setup' ? 'System // Erstinitialisierung' : 'System // Zugriff erforderlich'; ?></div>
            <h1><?php echo $mode === 'setup' ? 'Admin anlegen' : 'Admin-Login'; ?></h1>
            <p class="subtitle"><?php echo $mode === 'setup' ? 'Lege Benutzername & Passwort für dein Dashboard fest' : 'Budget, Con-Rechner & Änderungen nur für Admins'; ?></p>

            <?php if ($error): ?><div class="err" role="alert"><?php echo e($error); ?></div><?php endif; ?>
            <?php if ($mode === 'login' && isset($_GET['denied']) && !$error): ?><div class="info">Für diese Änderung musst du angemeldet sein.</div><?php endif; ?>
            <?php if ($mode === 'login' && !$error && ($locked ?? 0)): ?><div class="err">Zu viele Fehlversuche – Login für <?php echo ceil($locked / 60); ?> Minuten gesperrt.</div><?php endif; ?>
            <?php if ($mode === 'setup'): ?>
                <div class="info">Willkommen! Das Dashboard hat noch keinen Admin. Wer dieses Formular als Erstes ausfüllt, wird Admin – also am besten direkt nach dem Hochladen erledigen.</div>
            <?php endif; ?>

            <form method="POST" autocomplete="on">
                <input type="hidden" name="next" value="<?php echo e($next); ?>">
                <div class="field-wrap">
                    <label class="field" for="u">Benutzername</label>
                    <input type="text" id="u" name="username" required minlength="3" maxlength="32" autocomplete="username" autofocus value="<?php echo e($_POST['username'] ?? ''); ?>">
                </div>
                <div class="field-wrap">
                    <label class="field" for="p"><?php echo $mode === 'setup' ? 'Neues Passwort (mind. 8 Zeichen)' : 'Passwort'; ?></label>
                    <input type="password" id="p" name="password" required <?php echo $mode === 'setup' ? 'minlength="8" autocomplete="new-password"' : 'autocomplete="current-password"'; ?>>
                    <button type="button" class="eye" data-eye="p" title="Passwort anzeigen">👁</button>
                    <?php if ($mode === 'setup'): ?><div class="meter"><div id="meter"></div></div><div class="meter-label" id="meterLabel"></div><?php endif; ?>
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

<?php else: ?>
    <div class="nav-bar">
        <a href="index.php" class="nav-back">&larr; ZURÜCK ZUM INDEX</a>
        <form method="POST" style="margin: 0;"><input type="hidden" name="action" value="logout"><button type="submit" class="btn btn-danger btn-sm" style="padding: 7px 14px;">⏻ Abmelden</button></form>
    </div>

    <header>
        <div class="hud-tag">System // Admin Core</div>
        <h1>Admin-Zentrale</h1>
        <p class="subtitle">Angemeldet als <strong style="color: var(--accent-cyan);"><?php echo e(auth_user()); ?></strong> &bull; seit <?php echo date('H:i', $_SESSION['auth']['since'] ?? time()); ?> Uhr<?php echo !empty($_SESSION['auth']['remember']) ? ' &bull; bleibt 30 Tage angemeldet' : ''; ?></p>
    </header>

    <?php if (isset($_GET['welcome'])): ?>
        <div class="welcome"><b>✓ SYSTEM INITIALISIERT.</b> Dein Admin-Konto ist eingerichtet und du bist angemeldet.<br>
            Budget und Con-Rechner sind ab jetzt nur noch angemeldet sichtbar, und alle Änderungen (Links, Kategorien, Countdowns, Feeds …) gehen nur mit Login.</div>
    <?php endif; ?>
    <?php if ($flash): ?><div class="notice"><?php echo e($flash); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="err"><?php echo e($error); ?></div><?php endif; ?>

    <div class="tiles">
        <a class="tile" href="index.php"><span class="bg">🔗</span><div class="k">Links</div><div class="v"><?php echo count($links); ?></div><div class="s"><?php echo count($hubs); ?> Hubs &bull; <?php echo $nsfw_count; ?> NSFW</div></a>
        <a class="tile" href="linkcheck.php"><span class="bg">🩺</span><div class="k">Link-Check</div><div class="v <?php echo $lc['checked_at'] ? ($lc_bad ? 'red' : 'green') : ''; ?>"><?php echo $lc['checked_at'] ? ($lc_bad ? $lc_bad . ' ⚠' : 'OK') : '–'; ?></div><div class="s"><?php echo $lc['checked_at'] ? 'geprüft ' . date('d.m. H:i', (int)$lc['checked_at']) : 'noch nie geprüft'; ?></div></a>
        <a class="tile" href="budget.php"><span class="bg">💶</span><div class="k">Budget</div><div class="v <?php echo $b_net >= 0 ? 'green' : 'red'; ?>"><?php echo eur($b_net); ?> €</div><div class="s"><?php echo e($budget['month_name']); ?> &bull; Netto</div></a>
        <a class="tile" href="con.php"><span class="bg">🎪</span><div class="k">Con-Rest</div><div class="v <?php echo $con_rest >= 0 ? 'purple' : 'red'; ?>"><?php echo eur($con_rest); ?> €</div><div class="s"><?php echo e($con['con_name']); ?></div></a>
        <a class="tile" href="countdown.php"><span class="bg">⏳</span><div class="k">Nächster Termin</div><div class="v gold"><?php echo $upcoming ? e(relative_days($upcoming[0]['ts'])) : '–'; ?></div><div class="s"><?php echo $upcoming ? e($upcoming[0]['icon'] . ' ' . $upcoming[0]['title']) : 'keine Countdowns'; ?></div></a>
        <a class="tile" href="feed.php"><span class="bg">📡</span><div class="k">Feed-Quellen</div><div class="v"><?php echo $feed_sources ? array_sum(array_map('count', $feed_sources)) : 'Standard'; ?></div><div class="s"><?php echo $feed_sources ? 'eigene Liste' : 'mitgelieferte Liste'; ?></div></a>
        <div class="tile"><span class="bg">🔞</span><div class="k">NSFW für Besucher</div><div class="v <?php echo $settings['show_nsfw'] ? 'red' : 'green'; ?>"><?php echo $settings['show_nsfw'] ? 'Sichtbar' : 'Versteckt'; ?></div><div class="s"><?php echo $nsfw_count; ?> Links betroffen</div></div>
        <div class="tile"><span class="bg">🛡️</span><div class="k">Passwort</div><div class="v" style="font-size: 1.1rem; padding: 5px 0;"><?php echo e(date('d.m.Y', (int)$acc['password_changed'])); ?></div><div class="s">zuletzt geändert</div></div>
    </div>

    <div class="cols">
        <div>
            <div class="panel">
                <div class="panel-title"><span>// Inhalte</span></div>
                <div class="toggle-row">
                    <div>
                        <strong>NSFW-Inhalte für Besucher anzeigen</strong>
                        <p>Betrifft alle Links mit NSFW-Markierung oder in Kategorien mit „NSFW“/„Adult“ im Namen – in den Hubs, in der Startseiten-Suche und im Link-Check. Als Admin siehst du sie immer (mit 18+-Markierung).</p>
                    </div>
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="action" value="toggle_nsfw">
                        <button type="submit" class="switch <?php echo $settings['show_nsfw'] ? 'on' : ''; ?>" role="switch" aria-checked="<?php echo $settings['show_nsfw'] ? 'true' : 'false'; ?>" title="Umschalten"></button>
                    </form>
                </div>
            </div>

            <div class="panel">
                <div class="panel-title"><span>// Schnellzugriff</span></div>
                <div class="actions">
                    <a class="btn" href="linkcheck.php">🩺 Link-Check</a>
                    <a class="btn" href="backup.php">⬇ Backup (ZIP)</a>
                    <a class="btn" href="countdown.php">⏳ Countdowns</a>
                    <form method="POST"><input type="hidden" name="action" value="clear_feed_cache"><button type="submit" class="btn" style="width: 100%;">📡 Feed neu laden</button></form>
                </div>
                <form method="POST" style="display: flex; gap: 8px; margin-top: 14px;" onsubmit="return confirm('Alle im Browser gemachten Änderungen an diesem Hub verwerfen? (Eine Kopie landet im Ordner archive/)');">
                    <input type="hidden" name="action" value="reset_hub">
                    <select name="hub">
                        <?php foreach ($hubs as $h): ?><option value="<?php echo e($h); ?>"><?php echo e($h); ?><?php echo is_file(hub_data_file($h)) ? ' (bearbeitet)' : ''; ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-danger" style="white-space: nowrap;">Hub zurücksetzen</button>
                </form>
            </div>

            <div class="panel">
                <div class="panel-title"><span>// Systemstatus</span></div>
                <table class="sys">
                    <?php foreach ($checks as [$label, $ok, $detail]): ?>
                        <tr><td><span class="dot <?php echo $ok ? 'green' : 'red'; ?>"></span><?php echo e($label); ?></td><td class="<?php echo $ok ? '' : 'red'; ?>"><?php echo e($detail); ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>

        <div>
            <div class="panel">
                <div class="panel-title"><span>// Zugangsdaten ändern</span></div>
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="action" value="change_credentials">
                    <div class="field-wrap"><label class="field">Benutzername</label><input type="text" name="username" value="<?php echo e($acc['username']); ?>" required minlength="3" maxlength="32" autocomplete="username"></div>
                    <div class="field-wrap"><label class="field">Neues Passwort (leer = unverändert)</label><input type="password" name="password" id="np" minlength="8" autocomplete="new-password"><button type="button" class="eye" data-eye="np">👁</button><div class="meter"><div id="meter"></div></div><div class="meter-label" id="meterLabel"></div></div>
                    <div class="field-wrap"><label class="field">Neues Passwort wiederholen</label><input type="password" name="password2" minlength="8" autocomplete="new-password"></div>
                    <div class="field-wrap"><label class="field">Aktuelles Passwort (zur Bestätigung) *</label><input type="password" name="current" required autocomplete="current-password"></div>
                    <button type="submit" class="btn btn-gold" style="width: 100%;">Speichern</button>
                </form>
                <form method="POST" style="margin-top: 10px;" onsubmit="return confirm('Alle anderen Geräte/Browser abmelden?');">
                    <input type="hidden" name="action" value="logout_everywhere">
                    <button type="submit" class="btn btn-danger" style="width: 100%;">Alle anderen Geräte abmelden</button>
                </form>
            </div>

            <div class="panel">
                <div class="panel-title"><span>// Zugriffsprotokoll</span><span style="font-size: 0.65rem; color: var(--text-muted);">letzte <?php echo count($log); ?></span></div>
                <div class="log">
                    <?php foreach ($log as $l): $bad = stripos($l['event'], 'fehl') !== false || stripos($l['event'], 'abgelehnt') !== false; ?>
                        <div class="log-row <?php echo $bad ? 'bad' : ''; ?>" title="<?php echo e($l['agent'] ?? ''); ?>">
                            <span class="t"><?php echo date('d.m. H:i:s', (int)$l['t']); ?></span>
                            <span class="ev"><?php echo $bad ? '⚠ ' : ''; ?><?php echo e($l['event']); ?><?php echo $l['user'] !== '' ? ' · ' . e($l['user']) : ''; ?></span>
                            <span class="ip"><?php echo e($l['ip']); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$log): ?><div class="empty-msg">Noch keine Einträge.</div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

</div>

<script>
    // Passwort anzeigen/verbergen
    document.querySelectorAll('[data-eye]').forEach(btn => btn.addEventListener('click', () => {
        const f = document.getElementById(btn.dataset.eye);
        f.type = f.type === 'password' ? 'text' : 'password';
    }));

    // Passwort-Stärke
    const pw = document.getElementById('p') && document.querySelector('[name=password2]') ? document.getElementById('p') : document.getElementById('np');
    const meter = document.getElementById('meter');
    if (pw && meter) {
        const label = document.getElementById('meterLabel');
        pw.addEventListener('input', () => {
            const v = pw.value;
            let score = 0;
            if (v.length >= 8) score++;
            if (v.length >= 12) score++;
            if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
            if (/\d/.test(v)) score++;
            if (/[^A-Za-z0-9]/.test(v)) score++;
            const steps = [['', 'transparent', 0], ['Sehr schwach', '#ff5555', 20], ['Schwach', '#ff5555', 40], ['Okay', '#f3c262', 60], ['Gut', '#50fa7b', 80], ['Stark', '#50fa7b', 100]];
            const [txt, col, w] = v ? steps[Math.max(1, score)] : steps[0];
            meter.style.width = w + '%';
            meter.style.background = col;
            label.textContent = txt;
            label.style.color = col;
        });
    }
</script>
</body>
</html>
