<?php
/**
 * Discord-Bot ohne Dauerbetrieb: Slash-Befehle und Knöpfe laufen über den „Interactions Endpoint“ (HTTP + Ed25519-Signatur),
 * Begrüßung, Auto-Rolle und Auto-Posts über den Cron-Aufruf. Kein Gateway, kein Node.js – reines PHP.
 */
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/nsfw.php';

const DBOT_SFW_SOURCES = ['e926', 'danbooru', 'yandere', 'konachan', 'waifupics', 'waifuim'];
const DBOT_NSFW_SOURCES = ['e621', 'rule34', 'gelbooru', 'danbooru', 'yandere', 'konachan', 'waifuim', 'waifupics'];
const DBOT_8BALL = ['Ganz sicher! ✨', 'Ja.', 'Sieht gut aus. 👍', 'Frag später nochmal. 🌀', 'Darauf würde ich nicht wetten.', 'Nein.', 'Auf keinen Fall! 🙅',
                    'Die Sterne sagen ja. 🌟', 'Schwer zu sagen …', 'Meine Quellen sagen nein.', 'Ohne Zweifel.', 'Konzentrier dich und frag nochmal.', 'Sehr wahrscheinlich.', 'Eher nicht. 🐾'];

// ---------- Konfiguration ----------

function dbot_get(string $key, $default = []) {
    $v = json_decode(setting('discord_' . $key, ''), true);
    return is_array($v) ? $v : $default;
}

function dbot_set(string $key, $value): void {
    set_setting('discord_' . $key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Eingebaute Befehle: Name => [Beschreibung, Optionen, NSFW] */
function dbot_builtins(): array {
    $src = function (array $list) { return array_map(function ($p) { return ['name' => NSFW_PROVIDERS[$p][0], 'value' => $p]; }, $list); };
    return [
        'hilfe'      => ['Zeigt alle Befehle dieses Bots', [], false],
        'countdown'  => ['Die nächsten Countdowns (Cons, Urlaub, Geburtstage …)', [], false],
        'termine'    => ['Die nächsten Termine aus dem Kalender', [['type' => 4, 'name' => 'anzahl', 'description' => 'Wie viele (1–10)', 'min_value' => 1, 'max_value' => 10]], false],
        'würfel'     => ['Würfeln', [['type' => 4, 'name' => 'seiten', 'description' => 'Seiten pro Würfel (Standard 6)', 'min_value' => 2, 'max_value' => 1000],
                                     ['type' => 4, 'name' => 'anzahl', 'description' => 'Wie viele Würfel (1–20)', 'min_value' => 1, 'max_value' => 20]], false],
        'münze'      => ['Münze werfen: Kopf oder Zahl', [], false],
        'wähle'      => ['Wählt zufällig aus deinen Optionen', [['type' => 3, 'name' => 'optionen', 'description' => 'Optionen, getrennt mit Komma oder |', 'required' => true]], false],
        '8ball'      => ['Die magische Kugel beantwortet deine Frage', [['type' => 3, 'name' => 'frage', 'description' => 'Deine Ja/Nein-Frage', 'required' => true]], false],
        'umfrage'    => ['Startet eine Discord-Umfrage', [['type' => 3, 'name' => 'frage', 'description' => 'Die Frage', 'required' => true, 'max_length' => 300],
                                                       ['type' => 3, 'name' => 'antworten', 'description' => 'Antworten, getrennt mit ; (max. 10)', 'required' => true],
                                                       ['type' => 4, 'name' => 'stunden', 'description' => 'Laufzeit in Stunden (Standard 24)', 'min_value' => 1, 'max_value' => 768],
                                                       ['type' => 5, 'name' => 'mehrfach', 'description' => 'Mehrere Antworten erlauben']], false],
        'erinnerung' => ['Erinnert dich hier im Kanal', [['type' => 3, 'name' => 'wann', 'description' => 'z. B. 30m, 2h, 1d, 18:30 oder 24.12. 18:00', 'required' => true],
                                                       ['type' => 3, 'name' => 'text', 'description' => 'Woran erinnert werden soll', 'required' => true, 'max_length' => 500]], false],
        'info'       => ['Infos zu einem Mitglied', [['type' => 6, 'name' => 'nutzer', 'description' => 'Wen? (leer = du selbst)']], false],
        'server'     => ['Infos zu diesem Server', [], false],
        'avatar'     => ['Zeigt das Profilbild groß', [['type' => 6, 'name' => 'nutzer', 'description' => 'Wen? (leer = du selbst)']], false],
        'bild'       => ['Zufälliges jugendfreies Bild (e926, Danbooru, waifu.pics …)', [['type' => 3, 'name' => 'tags', 'description' => 'Tags bzw. Kategorie', 'autocomplete' => true],
                                                       ['type' => 3, 'name' => 'quelle', 'description' => 'Bilder-Seite', 'choices' => $src(DBOT_SFW_SOURCES)]], false],
        'nsfw'       => ['🔞 Zufälliges NSFW-Bild (nur in altersbeschränkten Kanälen)', [['type' => 3, 'name' => 'tags', 'description' => 'Tags bzw. Kategorie', 'autocomplete' => true],
                                                       ['type' => 3, 'name' => 'quelle', 'description' => 'Bilder-Seite', 'choices' => $src(DBOT_NSFW_SOURCES)],
                                                       ['type' => 3, 'name' => 'inhalt', 'description' => 'Fragwürdig oder explizit', 'choices' => [['name' => 'beides', 'value' => 'nsfw'], ['name' => 'fragwürdig', 'value' => 'questionable'], ['name' => 'explizit', 'value' => 'explicit']]]], true],
    ];
}

function dbot_cmd_cfg(): array {
    return dbot_get('cmds', []) + ['disabled' => [], 'custom' => [], 'nsfw_default' => 'e621', 'sfw_default' => 'e926'];
}

const DBOT_NAME_RE = '/^[-_\p{Ll}\p{Lo}\p{N}]{1,32}$/u';

/** Befehls-Definitionen für die Registrierung bei Discord */
function dbot_command_defs(): array {
    $cfg = dbot_cmd_cfg();
    $defs = [];
    foreach (dbot_builtins() as $name => [$desc, $opts, $nsfw]) {
        if (in_array($name, $cfg['disabled'], true)) continue;
        $d = ['name' => $name, 'type' => 1, 'description' => $desc, 'options' => $opts];
        if ($nsfw) $d['nsfw'] = true;
        $defs[] = $d;
    }
    foreach ($cfg['custom'] as $c) {
        if (isset(dbot_builtins()[$c['name']])) continue;
        $defs[] = ['name' => $c['name'], 'type' => 1, 'description' => mb_substr($c['desc'] ?: 'Eigener Befehl', 0, 100),
                   'options' => [['type' => 3, 'name' => 'text', 'description' => 'Zusatz-Text (für {text})']]] + (!empty($c['nsfw']) ? ['nsfw' => true] : []);
    }
    return $defs;
}

/** Anwendung abfragen (ID + öffentlicher Schlüssel für die Signaturprüfung) */
function dbot_app(): array {
    $a = discord_api('GET', '/applications/@me');
    if (empty($a['id']) || empty($a['verify_key'])) throw new DiscordError('Discord hat keine Anwendungsdaten geliefert.');
    set_setting('discord_app_id', $a['id']);
    set_setting('discord_pubkey', $a['verify_key']);
    return $a;
}

function dbot_register(string $guild): int {
    $app = setting('discord_app_id', '') ?: dbot_app()['id'];
    $r = discord_api('PUT', '/applications/' . $app . '/guilds/' . discord_valid_id($guild) . '/commands', [], dbot_command_defs());
    set_setting('discord_cmds_registered_' . $guild, (string)time());
    return count($r);
}

// ---------- Signatur & Antworten ----------

function dbot_verify(string $body, string $sig, string $ts): bool {
    $pub = setting('discord_pubkey', '');
    if ($pub === '' || !function_exists('sodium_crypto_sign_verify_detached') || !ctype_xdigit($sig) || strlen($sig) !== 128 || !ctype_xdigit($pub)) return false;
    if (abs(time() - (int)$ts) > 600) return false;   // Wiederholungs-Angriffe abwehren
    try { return sodium_crypto_sign_verify_detached(hex2bin($sig), $ts . $body, hex2bin($pub)); } catch (Throwable $e) { return false; }
}

function dbot_reply(string $content, array $embeds = [], bool $ephemeral = false, array $extra = []): array {
    $d = ['allowed_mentions' => ['parse' => ['users']]] + $extra;
    if ($content !== '') $d['content'] = mb_substr($content, 0, 2000);
    if ($embeds) $d['embeds'] = array_map(function ($e) { return array_filter($e, function ($v) { return $v !== null && $v !== '' && $v !== []; }); }, $embeds);
    if ($ephemeral) $d['flags'] = 64;
    return ['type' => 4, 'data' => $d];
}

/** Antwort sofort schicken (Discord wartet max. 3 s) und danach weiterarbeiten */
function dbot_respond_then(array $resp, callable $later): void {
    $json = json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ignore_user_abort(true);
    header('Content-Type: application/json');
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
    echo $json;
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else { while (ob_get_level()) ob_end_flush(); flush(); }
    $later();
    exit;
}

function dbot_followup(array $it, array $data): void {
    discord_api('PATCH', '/webhooks/' . $it['application_id'] . '/' . $it['token'] . '/messages/@original', [], $data);
}

function dbot_opts(array $it): array {
    $o = [];
    foreach ((array)($it['data']['options'] ?? []) as $x) $o[$x['name']] = $x['value'] ?? null;
    return $o;
}

function dbot_user(array $it): array {
    return $it['member']['user'] ?? $it['user'] ?? ['id' => '0', 'username' => '?'];
}

function dbot_log(array $it, string $what, bool $ok, string $note = ''): void {
    if (!empty($GLOBALS['dbot_test'])) return;
    $u = dbot_user($it);
    $log = dbot_get('ilog', []);
    array_unshift($log, ['ts' => time(), 'user' => discord_name($u), 'what' => $what, 'ok' => $ok, 'note' => mb_substr($note, 0, 160), 'guild' => (string)($it['guild_id'] ?? '')]);
    dbot_set('ilog', array_slice($log, 0, 80));
    if ($ok) { $st = dbot_get('istats', []); $st[$what] = ($st[$what] ?? 0) + 1; dbot_set('istats', $st); }
}

/** Ist der Kanal der Interaktion altersbeschränkt? (Threads erben vom Elternkanal) */
function dbot_channel_nsfw(array $it): bool {
    $c = $it['channel'] ?? null;
    if (is_array($c) && !empty($c['nsfw'])) return true;
    try {
        $c = discord_api('GET', '/channels/' . discord_valid_id((string)($it['channel_id'] ?? '')));
        if (!empty($c['nsfw'])) return true;
        if (in_array((int)($c['type'] ?? 0), [10, 11, 12], true) && !empty($c['parent_id'])) return !empty(discord_api('GET', '/channels/' . $c['parent_id'])['nsfw']);
    } catch (Throwable $e) {}
    return false;
}

function dbot_fill(string $t, array $it, string $text = '', array $more = []): string {
    $u = dbot_user($it);
    $t = preg_replace_callback('/\{random:([^}]+)\}/u', function ($m) { $o = array_map('trim', explode('|', $m[1])); return $o[array_rand($o)]; }, $t);
    return strtr($t, $more + ['{user}' => '<@' . $u['id'] . '>', '{name}' => discord_name($u), '{channel}' => '<#' . ($it['channel_id'] ?? '') . '>',
                     '{text}' => $text, '{date}' => date('d.m.Y'), '{time}' => date('H:i'), '{server}' => (string)($more['{server}'] ?? 'dem Server')]);
}

function dbot_parse_when(string $s, int $now): ?int {
    $s = mb_strtolower(trim($s));
    if (preg_match('/^(\d+)\s*(s|sek|m|min|minuten?|h|std|stunden?|d|t|tage?|w|wochen?)$/u', $s, $m)) {
        $mul = ['s' => 1, 'sek' => 1, 'm' => 60, 'min' => 60, 'h' => 3600, 'std' => 3600, 'd' => 86400, 't' => 86400, 'w' => 604800][$m[2]] ?? null;
        if ($mul === null) $mul = strpos($m[2], 'min') === 0 ? 60 : (strpos($m[2], 'stund') === 0 ? 3600 : (strpos($m[2], 'tag') === 0 ? 86400 : 604800));
        return $now + (int)$m[1] * $mul;
    }
    if (preg_match('/^(morgen\s+)?(\d{1,2})[:.](\d{2})$/u', $s, $m)) {
        $t = mktime((int)$m[2], (int)$m[3], 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
        if ($m[1] !== '') $t = strtotime('+1 day', $t); elseif ($t <= $now) $t = strtotime('+1 day', $t);
        return $t;
    }
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})?\s*(?:(\d{1,2})[:.](\d{2}))?$/u', $s, $m)) {
        $y = !empty($m[3]) ? (int)$m[3] : (int)date('Y', $now);
        if ($y < 100) $y += 2000;
        $t = mktime(isset($m[4]) && $m[4] !== '' ? (int)$m[4] : 9, isset($m[5]) && $m[5] !== '' ? (int)$m[5] : 0, 0, (int)$m[2], (int)$m[1], $y);
        if (empty($m[3]) && $t <= $now) $t = strtotime('+1 year', $t);
        return $t;
    }
    $t = strtotime($s, $now);
    return $t ?: null;
}

// ---------- Verarbeitung ----------

/**
 * Interaktion verarbeiten. $test = true: aus dem Dashboard simuliert (keine Seiteneffekte, Antwort wird zurückgegeben).
 * Liefert die Antwort an Discord als Array; „später“-Arbeiten werden über ['_later' => callable] signalisiert.
 */
function dbot_handle(array $it, bool $test = false): array {
    $type = (int)($it['type'] ?? 0);
    if ($type === 1) return ['type' => 1];
    if ($type === 4) return ['type' => 8, 'data' => ['choices' => dbot_autocomplete($it)]];
    if ($type === 3) return dbot_component($it, $test);
    if ($type !== 2) return dbot_reply('Unbekannte Interaktion.', [], true);

    $name = (string)($it['data']['name'] ?? '');
    $o = dbot_opts($it);
    $cfg = dbot_cmd_cfg();
    $u = dbot_user($it);
    $gid = (string)($it['guild_id'] ?? '');
    foreach ($cfg['custom'] as $c) {
        if ($c['name'] !== $name) continue;
        if (!empty($c['nsfw']) && !dbot_channel_nsfw($it)) return dbot_reply('🔞 Dieser Befehl geht nur in altersbeschränkten Kanälen.', [], true);
        $text = mb_substr((string)($o['text'] ?? ''), 0, 500);
        $resp = dbot_fill((string)$c['response'], $it, $text);
        if (!$test) {
            $all = dbot_cmd_cfg();
            foreach ($all['custom'] as &$cc) if ($cc['name'] === $name) $cc['uses'] = (int)($cc['uses'] ?? 0) + 1;
            unset($cc);
            dbot_set('cmds', $all);
        }
        if (!empty($c['embed'])) {
            $e = ['description' => $resp, 'color' => (int)($c['color'] ?? 0x5865f2)];
            if (!empty($c['title'])) $e['title'] = dbot_fill((string)$c['title'], $it, $text);
            if (!empty($c['image']) && preg_match('#^https://#', $c['image'])) $e['image'] = ['url' => $c['image']];
            return dbot_reply('', [$e], !empty($c['ephemeral']));
        }
        return dbot_reply($resp . (!empty($c['image']) && preg_match('#^https://#', $c['image']) ? "\n" . $c['image'] : ''), [], !empty($c['ephemeral']));
    }
    if (!isset(dbot_builtins()[$name]) || in_array($name, $cfg['disabled'], true)) return dbot_reply('Diesen Befehl gibt es (nicht mehr). Im Dashboard „Befehle registrieren“ klicken.', [], true);

    switch ($name) {
        case 'hilfe':
            $lines = [];
            foreach (dbot_builtins() as $n => [$d]) if (!in_array($n, $cfg['disabled'], true)) $lines[] = '`/' . $n . '` – ' . $d;
            foreach ($cfg['custom'] as $c) $lines[] = '`/' . $c['name'] . '` – ' . ($c['desc'] ?: 'Eigener Befehl');
            return dbot_reply('', [['title' => '🤖 Befehle', 'description' => mb_substr(implode("\n", $lines), 0, 4000), 'color' => 0x5865f2]], true);
        case 'countdown':
            require_once __DIR__ . '/countdown.php';
            [$up] = sorted_countdowns(load_countdowns()['events']);
            if (!$up) return dbot_reply('Gerade keine Countdowns. ⏳');
            $lines = array_map(function ($e) { return $e['icon'] . ' **' . $e['title'] . '** – <t:' . $e['ts'] . ':R> (<t:' . $e['ts'] . ':D>)'; }, array_slice($up, 0, 6));
            return dbot_reply('', [['title' => '⏳ Countdowns', 'description' => implode("\n", $lines), 'color' => 0xf3c262]]);
        case 'termine':
            require_once __DIR__ . '/calendar.php';
            $now = new DateTimeImmutable('now');
            $items = array_values(array_filter(cal_items($now, $now->modify('+90 days'), false, ['events', 'countdowns']), function ($x) { return $x['end']->getTimestamp() >= time(); }));
            $items = array_slice($items, 0, max(1, min(10, (int)($o['anzahl'] ?? 5))));
            if (!$items) return dbot_reply('Keine Termine in den nächsten 90 Tagen. 📅');
            $lines = array_map(function ($x) { $t = $x['start']->getTimestamp(); return '📅 **' . $x['title'] . '** – ' . (!empty($x['all_day']) ? '<t:' . $t . ':D>' : '<t:' . $t . ':f>') . ' (<t:' . $t . ':R>)' . (!empty($x['location']) ? ' · 📍 ' . $x['location'] : ''); }, $items);
            return dbot_reply('', [['title' => '📅 Nächste Termine', 'description' => mb_substr(implode("\n", $lines), 0, 4000), 'color' => 0x38d5e6]]);
        case 'würfel':
            $s = max(2, min(1000, (int)($o['seiten'] ?? 6)));
            $n = max(1, min(20, (int)($o['anzahl'] ?? 1)));
            $r = [];
            for ($i = 0; $i < $n; $i++) $r[] = random_int(1, $s);
            return dbot_reply('🎲 ' . discord_name($u) . ' würfelt ' . $n . 'W' . $s . ': **' . implode('**, **', $r) . '**' . ($n > 1 ? ' → Summe **' . array_sum($r) . '**' : ''));
        case 'münze':
            return dbot_reply(random_int(0, 1) ? '🪙 **Kopf!**' : '🪙 **Zahl!**');
        case 'wähle':
            $opts = array_values(array_filter(array_map('trim', preg_split('/[,|;]/u', (string)($o['optionen'] ?? '')) ?: []), 'strlen'));
            if (count($opts) < 2) return dbot_reply('Bitte mindestens zwei Optionen angeben, z. B. `Pizza, Sushi, Döner`.', [], true);
            return dbot_reply('🤔 Ich wähle: **' . $opts[array_rand($opts)] . '**');
        case '8ball':
            return dbot_reply('🎱 *' . mb_substr((string)($o['frage'] ?? ''), 0, 300) . "*\n" . DBOT_8BALL[array_rand(DBOT_8BALL)]);
        case 'umfrage':
            $ans = array_slice(array_values(array_filter(array_map('trim', explode(';', (string)($o['antworten'] ?? ''))), 'strlen')), 0, 10);
            if (count($ans) < 2) return dbot_reply('Bitte mindestens zwei Antworten mit `;` getrennt angeben.', [], true);
            return dbot_reply('', [], false, ['poll' => discord_poll((string)$o['frage'], $ans, (int)($o['stunden'] ?? 24), !empty($o['mehrfach']))]);
        case 'erinnerung':
            $at = dbot_parse_when((string)($o['wann'] ?? ''), time());
            if (!$at || $at < time() + 30) return dbot_reply('Das Datum verstehe ich nicht. Beispiele: `30m`, `2h`, `1d`, `18:30`, `morgen 9:00`, `24.12. 18:00`.', [], true);
            if ($at > time() + 366 * 86400) return dbot_reply('Höchstens ein Jahr im Voraus.', [], true);
            if (!$test) {
                $list = discord_scheduled();
                if (count(array_filter($list, function ($s) { return !empty($s['reminder']); })) >= 200) return dbot_reply('Zu viele offene Erinnerungen.', [], true);
                $list[] = ['id' => bin2hex(random_bytes(6)), 'channel' => (string)$it['channel_id'], 'channel_name' => '/erinnerung', 'reminder' => true,
                           'payload' => ['content' => '⏰ <@' . $u['id'] . '>, Erinnerung: ' . mb_substr((string)$o['text'], 0, 500), 'allowed_mentions' => ['users' => [$u['id']]]],
                           'at' => $at, 'repeat' => 'none', 'created_by' => 'Discord: ' . discord_name($u), 'last_sent' => 0, 'last_error' => ''];
                discord_scheduled_save($list);
            }
            return dbot_reply('⏰ Alles klar, ich erinnere dich <t:' . $at . ':R> (<t:' . $at . ':f>).', [], true);
        case 'info':
        case 'avatar':
            $tid = (string)($o['nutzer'] ?? $u['id']);
            $tu = $it['data']['resolved']['users'][$tid] ?? ($tid === $u['id'] ? $u : ['id' => $tid, 'username' => '?']);
            $tm = $it['data']['resolved']['members'][$tid] ?? ($tid === $u['id'] ? ($it['member'] ?? []) : []);
            $av = !empty($tu['avatar']) ? DISCORD_CDN . '/avatars/' . $tu['id'] . '/' . $tu['avatar'] . '.png?size=1024' : discord_user_avatar($tu);
            if ($name === 'avatar') return dbot_reply('', [['title' => '🖼 ' . discord_name($tu), 'image' => ['url' => $av], 'url' => $av, 'color' => 0x5865f2]]);
            $fields = [['name' => 'Konto erstellt', 'value' => '<t:' . discord_ts($tu['id']) . ':D> (<t:' . discord_ts($tu['id']) . ':R>)', 'inline' => true]];
            if (!empty($tm['joined_at'])) $fields[] = ['name' => 'Beigetreten', 'value' => '<t:' . strtotime($tm['joined_at']) . ':D> (<t:' . strtotime($tm['joined_at']) . ':R>)', 'inline' => true];
            if (!empty($tm['premium_since'])) $fields[] = ['name' => 'Booster seit', 'value' => '<t:' . strtotime($tm['premium_since']) . ':D>', 'inline' => true];
            if (!empty($tm['roles'])) $fields[] = ['name' => 'Rollen (' . count($tm['roles']) . ')', 'value' => mb_substr(implode(' ', array_map(function ($r) { return '<@&' . $r . '>'; }, $tm['roles'])), 0, 1024)];
            return dbot_reply('', [['title' => '👤 ' . discord_name($tu) . (!empty($tm['nick']) ? ' (' . $tm['nick'] . ')' : ''), 'description' => '@' . ($tu['username'] ?? '') . ' · ID `' . $tu['id'] . '`' . (!empty($tu['bot']) ? ' · 🤖 Bot' : ''),
                                    'thumbnail' => ['url' => $av], 'fields' => $fields, 'color' => 0x5865f2]]);
        case 'server':
            if ($gid === '') return dbot_reply('Nur auf Servern.', [], true);
            $g = discord_api('GET', '/guilds/' . discord_valid_id($gid), ['with_counts' => 'true']);
            return dbot_reply('', [['title' => '🏠 ' . $g['name'], 'description' => (string)($g['description'] ?? ''), 'thumbnail' => discord_guild_icon($g) ? ['url' => discord_guild_icon($g)] : null,
                'fields' => [['name' => 'Mitglieder', 'value' => (string)(int)($g['approximate_member_count'] ?? 0), 'inline' => true], ['name' => 'Online', 'value' => (string)(int)($g['approximate_presence_count'] ?? 0), 'inline' => true],
                             ['name' => 'Boosts', 'value' => (int)($g['premium_subscription_count'] ?? 0) . ' (Stufe ' . (int)($g['premium_tier'] ?? 0) . ')', 'inline' => true], ['name' => 'Erstellt', 'value' => '<t:' . discord_ts($g['id']) . ':D>', 'inline' => true],
                             ['name' => 'Rollen', 'value' => (string)count($g['roles'] ?? []), 'inline' => true], ['name' => 'Emojis', 'value' => (string)count($g['emojis'] ?? []), 'inline' => true]], 'color' => 0x5865f2]]);
        case 'bild':
        case 'nsfw':
            $nsfw = $name === 'nsfw';
            if ($nsfw && $gid === '') return dbot_reply('🔞 Nur auf Servern in altersbeschränkten Kanälen.', [], true);
            if ($nsfw && !$test && !dbot_channel_nsfw($it)) return dbot_reply('🔞 NSFW geht nur in **altersbeschränkten Kanälen** (Kanal bearbeiten → „Altersbeschränkter Kanal“).', [], true);
            $prov = (string)($o['quelle'] ?? ($nsfw ? $cfg['nsfw_default'] : $cfg['sfw_default']));
            if (!in_array($prov, $nsfw ? DBOT_NSFW_SOURCES : DBOT_SFW_SOURCES, true)) $prov = $nsfw ? 'e621' : 'e926';
            $rating = $nsfw ? (in_array($o['inhalt'] ?? '', ['questionable', 'explicit'], true) ? $o['inhalt'] : 'nsfw') : 'safe';
            $tags = mb_substr(trim((string)($o['tags'] ?? '')), 0, 200);
            return ['type' => 5, '_later' => function () use ($it, $prov, $tags, $rating, $name) { return dbot_image_message($it, $prov, $tags, $rating, $name); }];
    }
    return dbot_reply('?', [], true);
}

/** Bild suchen und als Nachricht aufbereiten (für /bild, /nsfw, „Noch eins“) */
function dbot_image_message(array $it, string $prov, string $tags, string $rating, string $what): array {
    try {
        $p = nsfw_random($prov, $tags, $rating);
        if (!$p) { dbot_log($it, $what, false, 'nichts gefunden: ' . $tags); return ['content' => '🔍 Nichts gefunden für `' . ($tags ?: '–') . '` bei ' . NSFW_PROVIDERS[$prov][0] . '.']; }
        // custom_id max. 100 Zeichen: m|quelle|bewertung|tags
        $more = 'm|' . $prov . '|' . ['safe' => 's', 'nsfw' => 'n', 'questionable' => 'q', 'explicit' => 'e'][$rating] . '|' . mb_substr($tags, 0, 80);
        dbot_log($it, $what, true, $prov . ' ' . $tags);
        return nsfw_discord_payload($p, '', true, $more);
    } catch (Throwable $e) {
        dbot_log($it, $what, false, $e->getMessage());
        return ['content' => '⚠ ' . $e->getMessage()];
    }
}

function dbot_autocomplete(array $it): array {
    $o = dbot_opts($it);
    $focused = '';
    foreach ((array)($it['data']['options'] ?? []) as $x) if (!empty($x['focused'])) $focused = (string)($x['value'] ?? '');
    $nsfw = ($it['data']['name'] ?? '') === 'nsfw';
    $cfg = dbot_cmd_cfg();
    $prov = (string)($o['quelle'] ?? ($nsfw ? $cfg['nsfw_default'] : $cfg['sfw_default']));
    if (!isset(NSFW_PROVIDERS[$prov])) return [];
    $words = preg_split('/\s+/', $focused) ?: [''];
    $lastw = array_pop($words);
    $prefix = $words ? implode(' ', $words) . ' ' : '';
    try {
        return array_slice(array_map(function ($t) use ($prefix) { $v = mb_substr($prefix . $t['tag'], 0, 100); return ['name' => mb_substr($v . ($t['count'] ? ' (' . $t['count'] . ')' : ''), 0, 100), 'value' => $v]; },
                                     nsfw_autocomplete($prov, $lastw)), 0, 25);
    } catch (Throwable $e) { return []; }
}

/** Knöpfe: Rollen-Panels und „Noch eins“ */
function dbot_component(array $it, bool $test): array {
    $cid = (string)($it['data']['custom_id'] ?? '');
    $u = dbot_user($it);
    $gid = (string)($it['guild_id'] ?? '');
    if (strpos($cid, 'm|') === 0) {
        [, $prov, $r, $tags] = array_pad(explode('|', $cid, 4), 4, '');
        $rating = ['s' => 'safe', 'n' => 'nsfw', 'q' => 'questionable', 'e' => 'explicit'][$r] ?? 'safe';
        if (!isset(NSFW_PROVIDERS[$prov])) return dbot_reply('Unbekannte Quelle.', [], true);
        if ($rating !== 'safe' && !dbot_channel_nsfw($it)) return dbot_reply('🔞 Nur in altersbeschränkten Kanälen.', [], true);
        return ['type' => 5, '_later' => function () use ($it, $prov, $tags, $rating) { return dbot_image_message($it, $prov, $tags, $rating, $rating === 'safe' ? 'bild' : 'nsfw'); }];
    }
    if (preg_match('/^rp:(\w+):(\d+)$/', $cid, $m)) {
        $panel = null;
        foreach (dbot_get('panels', []) as $p) if ($p['id'] === $m[1]) $panel = $p;
        if (!$panel || !in_array($m[2], array_column($panel['buttons'], 'role'), true)) return dbot_reply('Dieser Knopf ist veraltet.', [], true);
        $has = in_array($m[2], (array)($it['member']['roles'] ?? []), true);
        $label = '';
        foreach ($panel['buttons'] as $b) if ($b['role'] === $m[2]) $label = trim(($b['emoji'] ?? '') . ' ' . $b['label']);
        if ($test) return dbot_reply(($has ? 'Rolle entfernt: ' : 'Rolle erhalten: ') . $label, [], true);
        try {
            $base = '/guilds/' . discord_valid_id($gid) . '/members/' . discord_valid_id((string)$u['id']) . '/roles/';
            if ($has && $panel['mode'] === 'add') return dbot_reply('✅ Du hast **' . $label . '** schon.', [], true);
            if ($has) { discord_api('DELETE', $base . $m[2], [], null, 'Rollen-Knopf'); dbot_log($it, 'rolle', true, '− ' . $label); return dbot_reply('➖ Rolle **' . $label . '** entfernt.', [], true); }
            $removed = [];
            if ($panel['mode'] === 'unique') foreach ($panel['buttons'] as $b) {
                if ($b['role'] !== $m[2] && in_array($b['role'], (array)($it['member']['roles'] ?? []), true)) { discord_api('DELETE', $base . $b['role'], [], null, 'Rollen-Knopf'); $removed[] = $b['label']; }
            }
            discord_api('PUT', $base . $m[2], [], null, 'Rollen-Knopf');
            dbot_log($it, 'rolle', true, '+ ' . $label);
            return dbot_reply('✅ Rolle **' . $label . '** erhalten.' . ($removed ? ' (entfernt: ' . implode(', ', $removed) . ')' : ''), [], true);
        } catch (Throwable $e) {
            dbot_log($it, 'rolle', false, $e->getMessage());
            return dbot_reply('⚠ ' . $e->getMessage(), [], true);
        }
    }
    return dbot_reply('Unbekannter Knopf.', [], true);
}

/** Einstieg für discord.php?interactions */
function dbot_endpoint(): void {
    $body = (string)file_get_contents('php://input');
    if (!dbot_verify($body, (string)($_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? ''), (string)($_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? ''))) {
        http_response_code(401);
        exit('invalid request signature');
    }
    $it = json_decode($body, true);
    if (!is_array($it)) { http_response_code(400); exit; }
    try { $resp = dbot_handle($it); }
    catch (Throwable $e) { dbot_log($it, (string)($it['data']['name'] ?? 'knopf'), false, $e->getMessage()); $resp = dbot_reply('⚠ ' . $e->getMessage(), [], true); }
    if ((int)($it['type'] ?? 0) === 2 && empty($resp['_later'])) dbot_log($it, (string)($it['data']['name'] ?? ''), !preg_match('/^(⚠|🔞|Diesen Befehl|Bitte|Das Datum|Zu viele|Höchstens|Nur auf)/u', (string)($resp['data']['content'] ?? '')), (string)($resp['data']['flags'] ?? 0) === '64' ? mb_substr((string)($resp['data']['content'] ?? ''), 0, 80) : '');
    if (!empty($resp['_later'])) {
        $later = $resp['_later'];
        unset($resp['_later']);
        dbot_respond_then($resp, function () use ($it, $later) {
            try { dbot_followup($it, $later()); } catch (Throwable $e) { try { dbot_followup($it, ['content' => '⚠ ' . $e->getMessage()]); } catch (Throwable $e2) {} }
        });
    }
    header('Content-Type: application/json');
    echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------- Rollen-Panels ----------

function dbot_panel_message(array $p): array {
    $rows = [];
    foreach (array_chunk(array_slice($p['buttons'], 0, 25), 5) as $chunk) {
        $rows[] = ['type' => 1, 'components' => array_map(function ($b) use ($p) {
            $btn = ['type' => 2, 'style' => in_array((int)($b['style'] ?? 2), [1, 2, 3, 4], true) ? (int)$b['style'] : 2, 'label' => mb_substr($b['label'], 0, 80), 'custom_id' => 'rp:' . $p['id'] . ':' . $b['role']];
            if (!empty($b['emoji'])) {
                if (preg_match('/^<a?:(\w+):(\d+)>$/', $b['emoji'], $m)) $btn['emoji'] = ['name' => $m[1], 'id' => $m[2]];
                else $btn['emoji'] = ['name' => mb_substr($b['emoji'], 0, 8)];
            }
            return $btn;
        }, $chunk)];
    }
    $mode = ['toggle' => 'Klicken = Rolle nehmen oder abgeben.', 'unique' => 'Du kannst nur **eine** dieser Rollen haben.', 'add' => 'Klicken, um die Rolle zu erhalten.'][$p['mode']] ?? '';
    return ['embeds' => [['title' => $p['title'], 'description' => trim($p['desc'] . "\n\n*" . $mode . '*'), 'color' => (int)$p['color']]], 'components' => $rows, 'allowed_mentions' => ['parse' => []]];
}

// ---------- Automatik (Cron) ----------

function dbot_welcome_cfg(): array {
    return dbot_get('welcome', []) + ['on' => false, 'channel' => '', 'text' => 'Willkommen {user} auf **{server}**! 🎉 Du bist Mitglied Nr. {count}.', 'embed' => true, 'color' => '#38d5e6',
                                       'autorole' => '', 'bye_on' => false, 'bye_channel' => '', 'bye_text' => '**{name}** hat den Server verlassen. 👋', 'known' => [], 'checked' => 0, 'guild' => ''];
}

/** Neue Mitglieder begrüßen, Auto-Rolle vergeben, Abschiede melden – liefert Anzahl Aktionen */
function dbot_run_welcome(bool $force = false): int {
    $w = dbot_welcome_cfg();
    if ((!$w['on'] && !$w['bye_on'] && $w['autorole'] === '') || (!$force && time() - (int)$w['checked'] < 240)) return 0;
    $gid = setting('discord_guild', '');
    if ($gid === '') return 0;
    $w['checked'] = time();
    $members = discord_api('GET', '/guilds/' . discord_valid_id($gid) . '/members', ['limit' => 1000]);
    $ids = array_map(function ($m) { return $m['user']['id']; }, $members);
    $acts = 0;
    $names = dbot_get('member_names', []);
    foreach ($members as $m) $names[$m['user']['id']] = discord_name($m['user']);
    if (!$w['known'] || $w['guild'] !== $gid) {   // erster Lauf: nur merken
        dbot_set('member_names', $names);
        $w['known'] = $ids;
        $w['guild'] = $gid;
        dbot_set('welcome', $w);
        return 0;
    }
    $known = array_flip($w['known']);
    $g = null;
    $new = array_values(array_filter($members, function ($m) use ($known) { return !isset($known[$m['user']['id']]) && empty($m['user']['bot']); }));
    $fill = function (string $t, array $usr) use (&$g, $gid, $members) {
        if ($g === null) $g = discord_api('GET', '/guilds/' . $gid, ['with_counts' => 'true']);
        return strtr($t, ['{user}' => '<@' . $usr['id'] . '>', '{name}' => discord_name($usr), '{server}' => $g['name'] ?? '', '{count}' => (string)(int)($g['approximate_member_count'] ?? count($members))]);
    };
    foreach (array_slice($new, 0, 10) as $m) {
        if ($w['autorole'] !== '') { try { discord_api('PUT', '/guilds/' . $gid . '/members/' . $m['user']['id'] . '/roles/' . discord_valid_id($w['autorole']), [], null, 'Auto-Rolle'); $acts++; } catch (Throwable $e) {} }
        if ($w['on'] && $w['channel'] !== '') {
            $txt = $fill($w['text'], $m['user']);
            $payload = $w['embed'] ? ['content' => '<@' . $m['user']['id'] . '>', 'embeds' => [['description' => $txt, 'color' => hexdec(ltrim($w['color'], '#')) ?: 0x38d5e6, 'thumbnail' => ['url' => discord_user_avatar($m['user'])]]]] : ['content' => $txt];
            $payload['allowed_mentions'] = ['users' => [$m['user']['id']]];
            try { discord_send($w['channel'], $payload); $acts++; } catch (Throwable $e) {}
        }
    }
    if ($w['bye_on'] && $w['bye_channel'] !== '' && count($members) < 1000) {
        $now = array_flip($ids);
        $gone = array_values(array_filter($w['known'], function ($id) use ($now) { return !isset($now[$id]); }));
        foreach (array_slice($gone, 0, 10) as $id) {
            try { discord_send($w['bye_channel'], ['content' => $fill($w['bye_text'], ['id' => $id, 'username' => $names[$id] ?? 'Jemand']), 'allowed_mentions' => ['parse' => []]]); $acts++; } catch (Throwable $e) {}
        }
    }
    // Namen merken, damit Abschiede einen Namen haben (nur aktuelle Mitglieder)
    dbot_set('member_names', array_intersect_key($names, array_flip($ids)));
    $w['known'] = $ids;
    $w['guild'] = $gid;
    dbot_set('welcome', $w);
    return $acts;
}

function dbot_autoposts(): array {
    return dbot_get('autoposts', []);
}

/** Fällige Auto-Posts senden (Bilder-APIs & RSS) */
function dbot_run_autoposts(): int {
    $list = dbot_autoposts();
    $sent = 0;
    $now = time();
    $changed = false;
    foreach ($list as &$a) {
        if (!empty($a['paused']) || (int)$a['next_at'] > $now) continue;
        $changed = true;
        $a['next_at'] = $now + max(30, (int)$a['interval']) * 60;
        try {
            if ($a['kind'] === 'rss') {
                $items = dbot_rss((string)$a['url']);
                $seen = (array)($a['seen'] ?? []);
                $fresh = array_values(array_filter($items, function ($x) use ($seen) { return !in_array($x['id'], $seen, true); }));
                if (!$seen) $fresh = array_slice($fresh, 0, 1);   // erster Lauf: nur den neuesten posten
                foreach (array_reverse(array_slice($fresh, 0, 3)) as $x) {
                    $e = ['title' => mb_substr($x['title'], 0, 256), 'url' => $x['link'], 'description' => mb_substr($x['desc'], 0, 600), 'color' => 0xf3c262, 'footer' => ['text' => '📡 ' . mb_substr($a['name'], 0, 100)]];
                    if ($x['image']) $e['image'] = ['url' => $x['image']];
                    if ($x['ts']) $e['timestamp'] = gmdate('c', $x['ts']);
                    discord_send($a['channel'], ['embeds' => [array_filter($e)], 'allowed_mentions' => ['parse' => []]]);
                    $sent++;
                }
                $a['seen'] = array_slice(array_values(array_unique(array_merge(array_column($items, 'id'), $seen))), 0, 300);
            } else {
                if ($a['rating'] !== 'safe') {
                    $ch = discord_api('GET', '/channels/' . discord_valid_id($a['channel']));
                    if (empty($ch['nsfw'])) throw new DiscordError('Der Kanal ist nicht (mehr) altersbeschränkt – NSFW-Auto-Post pausiert.');
                }
                $p = nsfw_random($a['provider'], (string)$a['q'], $a['rating'], (array)($a['seen'] ?? []));
                if (!$p) throw new DiscordError('Nichts gefunden.');
                discord_send($a['channel'], nsfw_discord_payload($p, (string)($a['note'] ?? '')));
                $a['seen'] = array_slice(array_merge([$p['key']], (array)($a['seen'] ?? [])), 0, 300);
                $sent++;
            }
            $a['last_post'] = $now;
            $a['last_error'] = '';
            $a['count'] = (int)($a['count'] ?? 0) + 1;
        } catch (Throwable $e) {
            $a['last_error'] = $e->getMessage();
            if (strpos($e->getMessage(), 'altersbeschränkt') !== false) $a['paused'] = true;
        }
    }
    unset($a);
    if ($changed) dbot_set('autoposts', $list);
    return $sent;
}

/** Einfacher RSS/Atom-Leser für Auto-Posts */
function dbot_rss(string $url): array {
    if (!preg_match('#^https?://#', $url)) throw new DiscordError('Ungültige Feed-Adresse.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15,
                            CURLOPT_USERAGENT => 'GhostNodeDashboard/1.0 (RSS)', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false || $code >= 400) throw new DiscordError('Feed nicht erreichbar (HTTP ' . $code . ').');
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string((string)$raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_use_internal_errors($prev);
    if (!$xml) throw new DiscordError('Das ist kein RSS- oder Atom-Feed.');
    $out = [];
    $clean = function ($s) { return trim(html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8')); };
    $img = function ($html) { return preg_match('#<img[^>]+src=["\'](https://[^"\']+)#i', (string)$html, $m) ? $m[1] : ''; };
    if (isset($xml->channel->item)) {
        foreach ($xml->channel->item as $i) {
            $enc = $i->enclosure ? (string)$i->enclosure['url'] : '';
            $media = $i->children('http://search.yahoo.com/mrss/');
            $mimg = isset($media->content) ? (string)$media->content->attributes()->url : (isset($media->thumbnail) ? (string)$media->thumbnail->attributes()->url : '');
            $out[] = ['id' => (string)($i->guid ?: $i->link), 'title' => $clean($i->title), 'link' => (string)$i->link, 'desc' => mb_substr($clean($i->description), 0, 600),
                      'image' => preg_match('#^https://#', $enc) && preg_match('/\.(jpe?g|png|gif|webp)/i', $enc) ? $enc : (preg_match('#^https://#', $mimg) ? $mimg : $img($i->description)), 'ts' => strtotime((string)$i->pubDate) ?: 0];
        }
    } elseif (isset($xml->entry)) {
        foreach ($xml->entry as $e) {
            $link = '';
            foreach ($e->link as $l) if ((string)$l['rel'] === '' || (string)$l['rel'] === 'alternate') $link = (string)$l['href'];
            $out[] = ['id' => (string)($e->id ?: $link), 'title' => $clean($e->title), 'link' => $link, 'desc' => mb_substr($clean($e->summary ?: $e->content), 0, 600),
                      'image' => $img($e->content ?: $e->summary), 'ts' => strtotime((string)($e->updated ?: $e->published)) ?: 0];
        }
    }
    return array_slice(array_values(array_filter($out, function ($x) { return $x['id'] !== '' && preg_match('#^https?://#', $x['link']); })), 0, 30);
}

/** Alles, was der Cron-Aufruf erledigt */
function dbot_run_all(bool $force_welcome = false): array {
    $res = ['scheduled' => 0, 'welcome' => 0, 'autoposts' => 0, 'errors' => []];
    if (!discord_configured()) return $res;
    [$res['scheduled'], $res['errors']] = discord_run_scheduled();
    try { $res['welcome'] = dbot_run_welcome($force_welcome); } catch (Throwable $e) { $res['errors'][] = 'Begrüßung: ' . $e->getMessage(); }
    try { $res['autoposts'] = dbot_run_autoposts(); } catch (Throwable $e) { $res['errors'][] = 'Auto-Posts: ' . $e->getMessage(); }
    set_setting('discord_cron_last', (string)time());
    return $res;
}
