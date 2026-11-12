<?php
/**
 * NSFW-Galerie: Bilder-APIs (e621, Danbooru, Gelbooru, Rule34, Yande.re, Konachan, waifu.im, waifu.pics) durchsuchen,
 * Favoriten, gespeicherte Suchen und Posten in NSFW-Kanäle über den Discord-Bot.
 * Nur für angemeldete Benutzer mit dem Recht „nsfw.browse“ und nach einmaliger Altersbestätigung (18+).
 */
require_once __DIR__ . '/inc/nsfw.php';

auth_require_setup();
require_permission('nsfw.browse');
$uid = nsfw_uid();
$age_ok = setting('nsfw_age_' . $uid, '') !== '';

if (isset($_GET['img'])) {
    if (!$age_ok) { http_response_code(403); exit; }
    session_write_close();
    nsfw_proxy((string)$_GET['img'], (string)($_GET['s'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    guard_post('nsfw.browse');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $in = $_POST;
    $act = (string)($in['act'] ?? '');
    $out = ['ok' => true];
    try {
        if ($act !== 'age_ok' && !$age_ok) throw new NsfwError('Bitte zuerst das Alter bestätigen.');
        if ($act !== 'settings_save') session_write_close();
        switch ($act) {
            case 'age_ok':
                if (empty($in['confirm'])) throw new NsfwError('Bitte bestätigen.');
                set_setting('nsfw_age_' . $uid, date('c'));
                audit('NSFW-Galerie: Alter bestätigt (18+)');
                break;
            case 'search':
                $r = nsfw_search((string)($in['provider'] ?? 'e621'), (string)($in['q'] ?? ''), (string)($in['rating'] ?? 'nsfw'), (string)($in['order'] ?? 'new'), (int)($in['page'] ?? 1), (int)($in['limit'] ?? 40));
                $favs = array_flip(array_column(nsfw_favs($uid), 'key'));
                $out = ['posts' => array_map(function ($p) use ($favs) { return nsfw_view($p) + ['fav' => isset($favs[$p['key']])]; }, $r['posts']), 'more' => $r['more'], 'hidden' => $r['hidden']];
                break;
            case 'diag':
                // Fehlersuche: API, Bild über den Server, Bild direkt (prüft der Browser)
                $p = (string)($in['provider'] ?? 'e621');
                $res = ['provider' => $p, 'api' => '', 'count' => 0, 'server' => '', 'url' => '', 'proxy' => ''];
                try {
                    $t = microtime(true);
                    $r = nsfw_search($p, '', NSFW_PROVIDERS[$p][5] ? 'safe' : 'nsfw', 'new', 1, 5);
                    $res['count'] = count($r['posts']);
                    $res['api'] = 'ok (' . $res['count'] . ' Treffer, ' . round((microtime(true) - $t) * 1000) . ' ms)';
                    if ($r['posts']) {
                        $u = $r['posts'][0]['preview'];
                        $res['url'] = $u;
                        $res['proxy'] = nsfw_proxy_url($u);
                        $t = microtime(true);
                        [$data, $mime, $code, $err] = nsfw_fetch_image($u, 5 * 1024 * 1024);
                        $res['server'] = $err !== '' ? '✗ ' . $err : 'ok – ' . $mime . ', ' . round(strlen($data) / 1024) . ' KB, ' . round((microtime(true) - $t) * 1000) . ' ms';
                        $res['host'] = parse_url($u, PHP_URL_HOST);
                    }
                } catch (Throwable $e) { $res['api'] = '✗ ' . $e->getMessage(); }
                $out = $res;
                break;
            case 'autocomplete':
                $out = ['tags' => nsfw_autocomplete((string)($in['provider'] ?? 'e621'), (string)($in['term'] ?? ''))];
                break;
            case 'fav_toggle':
                $p = nsfw_post_from_input((string)($in['post'] ?? ''));
                $l = nsfw_favs($uid);
                $had = false;
                foreach ($l as $i => $f) if ($f['key'] === $p['key']) { unset($l[$i]); $had = true; }
                if (!$had) array_unshift($l, $p + ['added' => time()]);
                nsfw_favs_save($uid, $l);
                $out = ['fav' => !$had, 'msg' => $had ? 'Aus den Favoriten entfernt.' : '⭐ Zu den Favoriten.'];
                break;
            case 'favs':
                $out = ['posts' => array_map(function ($p) { return nsfw_view($p) + ['fav' => true]; }, array_values(array_filter(nsfw_favs($uid), function ($p) { return nsfw_allowed($p, 'all'); })))];
                break;
            case 'saved':
                $out = ['items' => nsfw_saved($uid)];
                break;
            case 'saved_add':
                $l = nsfw_saved($uid);
                $q = implode(' ', nsfw_parse_tags((string)($in['q'] ?? '')));
                $item = ['id' => bin2hex(random_bytes(5)), 'name' => mb_substr(trim((string)($in['name'] ?? '')) ?: ($q ?: 'Suche'), 0, 60), 'provider' => isset(NSFW_PROVIDERS[$in['provider'] ?? '']) ? $in['provider'] : 'e621',
                         'q' => $q, 'rating' => isset(NSFW_RATINGS[$in['rating'] ?? '']) ? $in['rating'] : 'nsfw', 'order' => in_array($in['order'] ?? '', ['new', 'score', 'favs', 'random'], true) ? $in['order'] : 'new'];
                array_unshift($l, $item);
                nsfw_saved_save($uid, $l);
                $out = ['msg' => 'Suche gespeichert.', 'items' => $l];
                break;
            case 'saved_delete':
                $l = array_values(array_filter(nsfw_saved($uid), function ($s) use ($in) { return $s['id'] !== ($in['id'] ?? ''); }));
                nsfw_saved_save($uid, $l);
                $out = ['items' => $l];
                break;
            case 'settings_save':
                $c = nsfw_cfg();
                $c['blacklist'] = array_slice(array_values(array_unique(array_filter(array_map('nsfw_norm_tag', preg_split('/[\s,]+/u', (string)($in['blacklist'] ?? '')) ?: []), 'strlen'))), 0, 200);
                $c['proxy'] = !empty($in['proxy']);
                $c['default'] = isset(NSFW_PROVIDERS[$in['default'] ?? '']) ? $in['default'] : 'e621';
                nsfw_cfg_save($c);
                foreach (['e621', 'danbooru', 'gelbooru', 'rule34'] as $p) {
                    if (!isset($in['user_' . $p])) continue;
                    if (!empty($in['clear_' . $p])) { nsfw_key_set($p, '', null); continue; }
                    nsfw_key_set($p, trim((string)$in['user_' . $p]), trim((string)($in['key_' . $p] ?? '')) ?: null);
                }
                audit('NSFW-Galerie: Einstellungen gespeichert');
                $out = ['msg' => 'Gespeichert.'];
                break;
            case 'discord_channels':
                require_once __DIR__ . '/inc/discord.php';
                if (!can('discord.manage') || !discord_configured()) throw new NsfwError('Kein Discord-Bot eingerichtet (Discord-Zentrale).');
                $gid = setting('discord_guild', '');
                $chs = discord_api('GET', '/guilds/' . discord_valid_id($gid) . '/channels');
                $out = ['channels' => array_values(array_map(function ($c) { return ['id' => $c['id'], 'name' => $c['name'], 'nsfw' => !empty($c['nsfw'])]; },
                        array_filter($chs, function ($c) { return in_array((int)$c['type'], [0, 5], true); })))];
                break;
            case 'discord_post':
                require_once __DIR__ . '/inc/discord.php';
                if (!can('discord.manage') || !discord_configured()) throw new NsfwError('Kein Discord-Bot eingerichtet (Discord-Zentrale).');
                $p = nsfw_post_from_input((string)($in['post'] ?? ''));
                $ch = discord_api('GET', '/channels/' . discord_valid_id((string)($in['channel'] ?? '')));
                if ($p['rating'] !== 's' && empty($ch['nsfw'])) throw new NsfwError('NSFW-Bilder gehen nur in Kanäle mit „Altersbeschränkt“ (NSFW). In Discord: Kanal bearbeiten → „Altersbeschränkter Kanal“ einschalten.');
                discord_send($ch['id'], nsfw_discord_payload($p, mb_substr(trim((string)($in['note'] ?? '')), 0, 500)));
                audit('NSFW-Galerie: Bild in Discord gepostet', '#' . ($ch['name'] ?? $ch['id']) . ' – ' . $p['key']);
                $out = ['msg' => 'In #' . ($ch['name'] ?? '') . ' gepostet.'];
                break;
            default:
                throw new NsfwError('Unbekannte Aktion.');
        }
    } catch (Throwable $e) {
        http_response_code(400);
        $out = ['error' => $e->getMessage()];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$cfg = nsfw_cfg();
$providers = [];
foreach (NSFW_PROVIDERS as $id => $_) {
    $P = nsfw_provider($id);
    $k = nsfw_key($id);
    $providers[$id] = ['name' => $P['name'], 'icon' => $P['icon'], 'hint' => $P['hint'], 'nsfw' => $P['nsfw'], 'sfw' => $P['sfw'], 'fmt' => $P['fmt'],
                       'auto' => in_array($P['fmt'], ['e621', 'danbooru', 'waifuim', 'waifupics'], true), 'cats' => NSFW_CATEGORIES[$id] ?? null,
                       'needs_key' => in_array($id, ['gelbooru', 'rule34'], true) && $k['key'] === '', 'user' => $k['user'], 'has_key' => $k['key'] !== ''];
}
$discord = can('discord.manage');
if ($discord) { require_once __DIR__ . '/inc/discord.php'; $discord = discord_configured(); }
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>NSFW-Galerie</title>
    <?php echo emoji_favicon('🔞'); ?>
    <link rel="stylesheet" href="assets/hud.css">
    <link rel="stylesheet" href="assets/nsfw.css">
</head>
<body class="ns-body">
<?php auth_corner(); ?>
<div class="wrapper" style="max-width: 1500px;">
    <div class="nav-bar">
        <a href="index.php" class="nav-back">&larr; ZURÜCK ZUM INDEX</a>
        <span class="ns-panic-hint" title="Panik-Taste">⇧ + P = sofort weg</span>
    </div>
    <header class="ns-head">
        <div>
            <div class="hud-tag">18+ // Bilder-APIs</div>
            <h1>NSFW-Galerie</h1>
        </div>
    </header>

<?php if (!$age_ok): ?>
    <div class="ns-gate panel">
        <div class="ns-gate-icon">🔞</div>
        <h2>Nur für Erwachsene</h2>
        <p>Diese Seite zeigt Bilder von e621, Rule34, Danbooru und anderen Bilder-Seiten – darunter <b>explizite Inhalte für Erwachsene</b>.</p>
        <form id="ageForm">
            <label class="ns-check"><input type="checkbox" name="confirm" value="1" required> Ich bin mindestens 18 Jahre alt und möchte solche Inhalte sehen.</label>
            <button class="btn btn-gold">Bestätigen &amp; weiter</button>
            <a class="btn" href="index.php">Zurück</a>
        </form>
        <p class="ns-small">Darstellungen Minderjähriger (z. B. die Tags <code>young</code>, <code>cub</code>, <code>loli</code>, <code>shota</code>) sind immer gesperrt – das lässt sich nicht abschalten.</p>
    </div>
<?php else: ?>
    <nav class="ns-tabs" id="tabs">
        <button type="button" data-tab="search" class="on">🔎 Suche</button>
        <button type="button" data-tab="favs">⭐ Favoriten</button>
        <button type="button" data-tab="saved">📌 Gespeichert</button>
        <button type="button" data-tab="settings">⚙ Einstellungen</button>
        <label class="ns-check ns-blurtoggle"><input type="checkbox" id="blurToggle"> Unschärfe</label>
    </nav>

    <section data-pane="search">
        <form id="searchForm" class="ns-search panel">
            <select name="provider" id="provider" aria-label="Quelle"></select>
            <div class="ns-tagwrap">
                <input type="search" name="q" id="q" autocomplete="off" placeholder="Tags, z. B. fox solo -feral" aria-label="Tags">
                <div class="ns-ac" id="ac" hidden></div>
            </div>
            <select name="rating" id="rating" aria-label="Inhalt">
                <?php foreach (NSFW_RATINGS as $k => $l): ?><option value="<?php echo $k; ?>"<?php echo $k === 'nsfw' ? ' selected' : ''; ?>><?php echo e($l); ?></option><?php endforeach; ?>
            </select>
            <select name="order" id="order" aria-label="Sortierung">
                <option value="new">neueste</option><option value="score">beste Bewertung</option><option value="favs">meiste Favoriten</option><option value="random">🎲 zufällig</option>
            </select>
            <button class="btn btn-gold">Suchen</button>
            <button type="button" class="btn" id="saveSearch" title="Suche merken">📌</button>
        </form>
        <div class="ns-info" id="provHint"></div>
        <div class="ns-cats" id="cats" hidden></div>
        <div id="results" class="ns-grid"><div class="ns-empty">Wähle eine Quelle und suche nach Tags – oder einfach „Suchen“ für die neuesten Bilder.</div></div>
        <div class="ns-more"><button type="button" class="btn" id="moreBtn" hidden>Mehr laden</button></div>
    </section>

    <section data-pane="favs" hidden>
        <div class="ns-tools"><input type="search" id="favFilter" placeholder="Favoriten nach Tag filtern …"><select id="favProv"><option value="">alle Quellen</option></select><span class="ns-small" id="favCount"></span><button type="button" class="btn btn-sm" id="favSlide">▶ Diashow</button><button type="button" class="btn btn-sm" id="favExport">⬇ Export</button></div>
        <div id="favBox" class="ns-grid"></div>
    </section>

    <section data-pane="saved" hidden>
        <div class="panel"><div class="panel-title">📌 Gespeicherte Suchen</div><div id="savedBox"></div>
            <p class="ns-small">Gespeicherte Suchen kannst du in der Discord-Zentrale unter „Automatik“ auch automatisch in einen NSFW-Kanal posten lassen.</p></div>
    </section>

    <section data-pane="settings" hidden>
        <form id="settingsForm" class="ns-cols">
            <div class="panel">
                <div class="panel-title">🔑 Konten &amp; API-Schlüssel</div>
                <p class="ns-small">Gelbooru und Rule34 verlangen inzwischen einen kostenlosen API-Schlüssel. Bei e621 und Danbooru ist er optional (mehr Tags, eigene Blacklist der Seite).
                   Schlüssel werden verschlüsselt gespeichert und nie wieder angezeigt.</p>
                <?php foreach (['e621' => ['Benutzername', 'API-Key (Konto → Manage API Access)'], 'danbooru' => ['Benutzername', 'API-Key (Profil → API Key)'],
                                'gelbooru' => ['User-ID', 'API-Key (Settings → Options)'], 'rule34' => ['User-ID', 'API-Key (Options → API Access Credentials)']] as $p => [$lu, $lk]): ?>
                    <fieldset class="ns-key">
                        <legend><?php echo NSFW_PROVIDERS[$p][1] . ' ' . e(NSFW_PROVIDERS[$p][0]); ?> <?php if ($providers[$p]['has_key']): ?><span class="ns-ok">✔ hinterlegt</span><?php endif; ?></legend>
                        <input type="text" name="user_<?php echo $p; ?>" value="<?php echo e($providers[$p]['user']); ?>" placeholder="<?php echo e($lu); ?>" autocomplete="off">
                        <input type="password" name="key_<?php echo $p; ?>" placeholder="<?php echo $providers[$p]['has_key'] ? '•••••• (leer lassen = behalten)' : e($lk); ?>" autocomplete="off">
                        <?php if ($providers[$p]['has_key']): ?><label class="ns-check"><input type="checkbox" name="clear_<?php echo $p; ?>" value="1"> entfernen</label><?php endif; ?>
                    </fieldset>
                <?php endforeach; ?>
            </div>
            <div class="panel">
                <div class="panel-title">🚫 Blacklist</div>
                <label class="field">Eigene gesperrte Tags (Leerzeichen oder Komma getrennt)</label>
                <textarea name="blacklist" rows="5" placeholder="z. B. gore scat vore"><?php echo e(implode(' ', nsfw_user_blacklist())); ?></textarea>
                <p class="ns-small">Immer gesperrt (fest eingebaut): <?php echo e(implode(', ', NSFW_FORCED_BLACKLIST)); ?></p>
                <div class="panel-title" style="margin-top: 18px;">🛡 Privatsphäre</div>
                <label class="ns-check"><input type="checkbox" name="proxy" value="1"<?php echo $cfg['proxy'] ? ' checked' : ''; ?>> Bilder über deinen Server laden (die Bilder-Seiten sehen dann deine IP nicht; braucht mehr Server-Traffic)</label>
                <p class="ns-small">Kann dein Browser die Bilder nicht direkt laden (Werbeblocker, Jugendschutz-/DNS-Filter des Routers oder Anbieters, Hotlink-Schutz),
                   weicht die Galerie automatisch auf deinen Server aus.</p>
                <button type="button" class="btn btn-sm" id="diagBtn">🩺 Bilder-Test für die Standard-Quelle</button>
                <div id="diagBox" class="ns-diag"></div>
                <label class="field">Standard-Quelle</label>
                <select name="default"><?php foreach ($providers as $id => $p): ?><option value="<?php echo $id; ?>"<?php echo $cfg['default'] === $id ? ' selected' : ''; ?>><?php echo e($p['icon'] . ' ' . $p['name']); ?></option><?php endforeach; ?></select>
                <button class="btn btn-gold" style="margin-top: 14px;">Speichern</button>
            </div>
        </form>
    </section>

    <div class="ns-lb" id="lb" hidden role="dialog" aria-modal="true">
        <button type="button" class="ns-lbx" data-close aria-label="Schließen">&times;</button>
        <button type="button" class="ns-nav prev" data-nav="-1" aria-label="Vorheriges">‹</button>
        <button type="button" class="ns-nav next" data-nav="1" aria-label="Nächstes">›</button>
        <div class="ns-lbmedia" id="lbMedia"></div>
        <aside class="ns-lbside" id="lbSide"></aside>
    </div>
<?php endif; ?>
</div>
<script>window.NS = <?php echo json_encode(['age' => $age_ok, 'providers' => $providers, 'default' => $cfg['default'], 'blur' => (bool)$cfg['blur'], 'discord' => $discord], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?>;</script>
<script src="assets/nsfw.js"></script>
</body>
</html>
