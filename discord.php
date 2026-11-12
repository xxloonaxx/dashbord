<?php
/**
 * Discord-Zentrale: Server über einen eigenen Bot verwalten – Übersicht, Nachrichten mit Embed-Baukasten,
 * Mitglieder & Moderation, Rollen, Kanäle & Einladungen, Events (verbunden mit dem Kalender), geplante Nachrichten.
 * Alle Aktionen laufen als JSON-Anfragen (assets/js/discord.js).
 */
require_once __DIR__ . '/inc/modules/discord_bot.php';

// Slash-Befehle & Knöpfe: Discord ruft discord.php?interactions auf (signiert mit Ed25519, ohne Anmeldung)
if (isset($_GET['interactions'])) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Content-Type: text/plain; charset=utf-8'); exit("Interactions-Endpunkt der Discord-Zentrale – bereit.\n"); }
    dbot_endpoint();
}

// Cron-Aufruf (ohne Anmeldung, nur mit geheimem Schlüssel): discord.php?cron=<schlüssel>
if (isset($_GET['cron'])) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!hash_equals(discord_cron_key(), (string)$_GET['cron'])) { http_response_code(403); exit("falscher Schlüssel\n"); }
    $r = dbot_run_all();
    echo "ok – {$r['scheduled']} geplant, {$r['welcome']} Begrüßung/Auto-Rolle, {$r['autoposts']} Auto-Post(s)\n" . ($r['errors'] ? implode("\n", $r['errors']) . "\n" : '');
    exit;
}

/** Eigene Adresse (für Endpunkt- und Cron-URL) */
function dc_self_url(string $query): string {
    return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok($_SERVER['REQUEST_URI'] ?? '/discord.php', '?') . '?' . $query;
}

auth_require_setup();
require_permission('discord.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    guard_post('discord.manage');
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $in = $_POST;
    $act = (string)($in['act'] ?? '');
    $gid = (string)($in['guild'] ?? setting('discord_guild', ''));
    $id = function (string $k) use ($in) { return discord_valid_id((string)($in[$k] ?? '')); };
    $reason = mb_substr(trim((string)($in['reason'] ?? '')), 0, 400);
    $out = ['ok' => true];
    try {
        switch ($act) {
            // ----- Einrichtung -----
            case 'setup_save':
                $token = trim((string)($in['token'] ?? ''));
                if (!preg_match('/^[\w\-.]{50,120}$/', $token)) throw new DiscordError('Das sieht nicht wie ein Bot-Token aus (Developer-Portal → Bot → „Reset Token“).');
                try { $me = discord_api('GET', '/users/@me', [], null, '', $token); }
                catch (DiscordError $e) { throw new DiscordError(strpos($e->getMessage(), 'ungültig') !== false ? 'Discord kennt diesen Token nicht – bitte im Developer-Portal unter Bot → „Reset Token“ einen neuen erzeugen und komplett kopieren.' : $e->getMessage()); }
                if (empty($me['bot'])) throw new DiscordError('Das ist kein Bot-Token.');
                discord_set_token($token);
                audit('Discord-Bot eingerichtet', discord_name($me), 'security');
                $out = ['msg' => 'Bot „' . discord_name($me) . '“ verbunden.'];
                break;
            case 'setup_remove':
                discord_set_token(null);
                audit('Discord-Bot entfernt', '', 'security');
                break;
            case 'settings_save':
                if (isset($in['guild'])) set_setting('discord_guild', preg_match('/^\d{15,22}$/', $in['guild']) ? $in['guild'] : '');
                if (isset($in['channel'])) set_setting('discord_channel', preg_match('/^\d{15,22}$/', $in['channel']) ? $in['channel'] : '');
                $out = ['msg' => 'Gespeichert.'];
                break;
            case 'cron_reset':
                set_setting('discord_cron', bin2hex(random_bytes(16)));
                $out = ['msg' => 'Neuer Cron-Schlüssel erstellt – alte Adresse funktioniert nicht mehr.'];
                break;

            // ----- Übersicht -----
            case 'init':
                $me = discord_api('GET', '/users/@me');
                if (setting('discord_bot_id', '') !== $me['id']) set_setting('discord_bot_id', $me['id']);
                $guilds = discord_api('GET', '/users/@me/guilds');
                $auto = dbot_run_all();
                $sent = $auto['scheduled'] + $auto['autoposts'];
                $cur = setting('discord_guild', '');
                if (!array_filter($guilds, function ($g) use ($cur) { return $g['id'] === $cur; }) && $guilds) { $cur = $guilds[0]['id']; set_setting('discord_guild', $cur); }
                $out = ['bot' => ['id' => $me['id'], 'name' => discord_name($me), 'avatar' => discord_user_avatar($me), 'invite' => discord_invite_url($me['id'])],
                        'guilds' => array_map(function ($g) { return ['id' => $g['id'], 'name' => $g['name'], 'icon' => discord_guild_icon($g)]; }, $guilds),
                        'guild' => $cur, 'channel' => setting('discord_channel', ''), 'sent' => $sent];
                break;
            case 'overview':
                $g = discord_api('GET', '/guilds/' . discord_valid_id($gid), ['with_counts' => 'true']);
                $channels = discord_api('GET', '/guilds/' . $gid . '/channels');
                $stats = null;
                try {
                    $members = discord_api('GET', '/guilds/' . $gid . '/members', ['limit' => 1000]);
                    $months = [];
                    for ($i = 11; $i >= 0; $i--) $months[date('Y-m', strtotime("first day of -$i month"))] = 0;
                    $bots = 0; $boosters = 0; $timeouts = 0; $role_count = [];
                    foreach ($members as $m) {
                        if (!empty($m['user']['bot'])) $bots++;
                        if (!empty($m['premium_since'])) $boosters++;
                        if (!empty($m['communication_disabled_until']) && strtotime($m['communication_disabled_until']) > time()) $timeouts++;
                        $mo = substr((string)$m['joined_at'], 0, 7);
                        if (isset($months[$mo])) $months[$mo]++;
                        foreach ($m['roles'] as $r) $role_count[$r] = ($role_count[$r] ?? 0) + 1;
                    }
                    $recent = $members;
                    usort($recent, function ($a, $b) { return strcmp($b['joined_at'], $a['joined_at']); });
                    $stats = ['loaded' => count($members), 'bots' => $bots, 'humans' => count($members) - $bots, 'boosters' => $boosters, 'timeouts' => $timeouts, 'joins' => $months,
                              'recent' => array_map(function ($m) { return ['id' => $m['user']['id'], 'name' => $m['nick'] ?? discord_name($m['user']), 'avatar' => discord_user_avatar($m['user']), 'joined' => $m['joined_at']]; }, array_slice($recent, 0, 8)),
                              'top_roles' => $role_count];
                } catch (DiscordError $e) {
                    $stats = ['error' => $e->getMessage()];
                }
                $types = [];
                foreach ($channels as $c) { $t = DISCORD_CHANNEL_TYPES[$c['type']][1] ?? 'Sonstige'; $types[$t] = ($types[$t] ?? 0) + 1; }
                $roles = array_values(array_filter($g['roles'] ?? [], function ($r) { return $r['name'] !== '@everyone'; }));
                usort($roles, function ($a, $b) { return $b['position'] <=> $a['position']; });
                $out = ['guild' => ['id' => $g['id'], 'name' => $g['name'], 'icon' => discord_guild_icon($g), 'description' => $g['description'] ?? '', 'members' => (int)($g['approximate_member_count'] ?? 0),
                        'online' => (int)($g['approximate_presence_count'] ?? 0), 'boosts' => (int)($g['premium_subscription_count'] ?? 0), 'tier' => (int)($g['premium_tier'] ?? 0),
                        'created' => discord_ts($g['id']), 'verification' => (int)($g['verification_level'] ?? 0), 'features' => array_slice((array)($g['features'] ?? []), 0, 12),
                        'emojis' => count($g['emojis'] ?? []), 'stickers' => count($g['stickers'] ?? []), 'roles' => count($roles), 'channels' => count($channels), 'channel_types' => $types,
                        'banner' => !empty($g['banner']) ? DISCORD_CDN . '/banners/' . $g['id'] . '/' . $g['banner'] . '.png?size=960' : ''],
                        'stats' => $stats, 'roles' => array_map(function ($r) { return ['id' => $r['id'], 'name' => $r['name'], 'color' => discord_color((int)$r['color'])]; }, $roles)];
                break;

            // ----- Kanäle & Nachrichten -----
            case 'channels':
                $channels = discord_api('GET', '/guilds/' . discord_valid_id($gid) . '/channels');
                usort($channels, function ($a, $b) { return [$a['type'] === 4 ? 0 : 1, $a['position']] <=> [$b['type'] === 4 ? 0 : 1, $b['position']]; });
                $out = ['channels' => array_map(function ($c) {
                    return ['id' => $c['id'], 'name' => $c['name'], 'type' => (int)$c['type'], 'icon' => DISCORD_CHANNEL_TYPES[$c['type']][0] ?? '•', 'type_name' => DISCORD_CHANNEL_TYPES[$c['type']][1] ?? '',
                            'parent' => $c['parent_id'] ?? '', 'topic' => $c['topic'] ?? '', 'nsfw' => !empty($c['nsfw']), 'position' => (int)$c['position'], 'slowmode' => (int)($c['rate_limit_per_user'] ?? 0)];
                }, $channels)];
                break;
            case 'messages':
                $q = ['limit' => 50];
                if (!empty($in['before'])) $q['before'] = $id('before');
                $msgs = discord_api('GET', '/channels/' . $id('channel') . '/messages', $q);
                $out = ['messages' => array_map(function ($m) {
                    return ['id' => $m['id'], 'author' => discord_name($m['author']), 'author_id' => $m['author']['id'], 'avatar' => discord_user_avatar($m['author']), 'bot' => !empty($m['author']['bot']),
                            'ts' => strtotime($m['timestamp']), 'edited' => !empty($m['edited_timestamp']), 'pinned' => !empty($m['pinned']),
                            'html' => format_text(preg_replace('/<(@[!&]?|#)(\d+)>/', '[$1$2]', (string)($m['content'] ?? '')), ['external' => true]),
                            'embeds' => array_map(function ($e) { return ['title' => $e['title'] ?? '', 'desc' => mb_substr((string)($e['description'] ?? ''), 0, 400), 'color' => discord_color((int)($e['color'] ?? 0))]; }, (array)($m['embeds'] ?? [])),
                            'files' => array_map(function ($a) { return ['name' => $a['filename'] ?? 'Datei', 'url' => $a['url'] ?? '', 'image' => strpos((string)($a['content_type'] ?? ''), 'image/') === 0]; }, (array)($m['attachments'] ?? [])),
                            'reactions' => array_map(function ($r) { return ($r['emoji']['name'] ?? '?') . ' ' . (int)$r['count']; }, (array)($m['reactions'] ?? [])),
                            'mine' => ($m['author']['id'] ?? '') === setting('discord_bot_id', ''), 'raw' => ($m['author']['id'] ?? '') === setting('discord_bot_id', '') ? (string)($m['content'] ?? '') : '', 'webhook' => !empty($m['webhook_id']), 'thread' => isset($m['thread']) ? ($m['thread']['name'] ?? '') : '',
                            'components' => !empty($m['components']), 'poll' => isset($m['poll']) ? discord_poll_view($m['poll']) : null];
                }, $msgs)];
                break;
            case 'send':
                $r = discord_send($id('channel'), discord_payload($in));
                audit('Discord-Nachricht gesendet', '#' . $in['channel']);
                $out = ['msg' => 'Nachricht gesendet.', 'id' => $r['id'] ?? ''];
                break;
            case 'msg_delete':
                discord_api('DELETE', '/channels/' . $id('channel') . '/messages/' . $id('message'), [], null, $reason ?: 'über die Discord-Zentrale');
                audit('Discord-Nachricht gelöscht', '#' . $in['channel'], 'warn');
                $out = ['msg' => 'Nachricht gelöscht.'];
                break;
            case 'msg_pin':
                discord_api(!empty($in['on']) ? 'PUT' : 'DELETE', '/channels/' . $id('channel') . '/pins/' . $id('message'));
                $out = ['msg' => !empty($in['on']) ? 'Angepinnt.' : 'Gelöst.'];
                break;
            case 'channel_create':
                $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 100);
                if ($name === '') throw new DiscordError('Bitte einen Kanalnamen eingeben.');
                $body = ['name' => $name, 'type' => in_array((int)($in['type'] ?? 0), [0, 2, 4, 5, 15], true) ? (int)$in['type'] : 0];
                if (!empty($in['parent']) && $body['type'] !== 4) $body['parent_id'] = $id('parent');
                if (trim((string)($in['topic'] ?? '')) !== '') $body['topic'] = mb_substr(trim($in['topic']), 0, 1024);
                discord_api('POST', '/guilds/' . discord_valid_id($gid) . '/channels', [], $body, 'über die Discord-Zentrale');
                audit('Discord-Kanal erstellt', $name);
                $out = ['msg' => 'Kanal „' . $name . '“ erstellt.'];
                break;
            case 'invites':
                $list = discord_api('GET', '/guilds/' . discord_valid_id($gid) . '/invites');
                $out = ['invites' => array_map(function ($i) { return ['code' => $i['code'], 'channel' => $i['channel']['name'] ?? '', 'uses' => (int)($i['uses'] ?? 0), 'max' => (int)($i['max_uses'] ?? 0),
                                                                     'inviter' => isset($i['inviter']) ? discord_name($i['inviter']) : '', 'expires' => $i['expires_at'] ?? null, 'temporary' => !empty($i['temporary'])]; }, $list)];
                break;
            case 'invite_create':
                $r = discord_api('POST', '/channels/' . $id('channel') . '/invites', [], ['max_age' => max(0, min(604800, (int)($in['max_age'] ?? 86400))), 'max_uses' => max(0, min(100, (int)($in['max_uses'] ?? 0))), 'unique' => true]);
                $out = ['msg' => 'Einladung erstellt.', 'url' => 'https://discord.gg/' . ($r['code'] ?? '')];
                break;
            case 'invite_delete':
                if (!preg_match('/^[\w-]{2,32}$/', (string)($in['code'] ?? ''))) throw new DiscordError('Ungültiger Code.');
                discord_api('DELETE', '/invites/' . $in['code'], [], null, 'über die Discord-Zentrale');
                $out = ['msg' => 'Einladung gelöscht.'];
                break;

            // ----- Mitglieder & Moderation -----
            case 'members':
                $g = discord_valid_id($gid);
                $q = trim((string)($in['q'] ?? ''));
                $list = $q !== '' ? discord_api('GET', '/guilds/' . $g . '/members/search', ['query' => mb_substr($q, 0, 50), 'limit' => 100])
                                  : discord_api('GET', '/guilds/' . $g . '/members', ['limit' => 200] + (!empty($in['after']) ? ['after' => $id('after')] : []));
                $out = ['members' => array_map(function ($m) {
                    return ['id' => $m['user']['id'], 'name' => discord_name($m['user']), 'username' => $m['user']['username'] ?? '', 'nick' => $m['nick'] ?? '', 'avatar' => discord_user_avatar($m['user']),
                            'bot' => !empty($m['user']['bot']), 'roles' => $m['roles'], 'joined' => $m['joined_at'], 'booster' => !empty($m['premium_since']),
                            'timeout' => !empty($m['communication_disabled_until']) && strtotime($m['communication_disabled_until']) > time() ? $m['communication_disabled_until'] : '',
                            'created' => discord_ts($m['user']['id'])];
                }, $list)];
                break;
            case 'member_kick':
                discord_api('DELETE', '/guilds/' . discord_valid_id($gid) . '/members/' . $id('user'), [], null, $reason ?: 'über die Discord-Zentrale');
                audit('Discord: Mitglied gekickt', $in['user'] . ($reason ? ' – ' . $reason : ''), 'warn');
                $out = ['msg' => 'Gekickt.'];
                break;
            case 'member_ban':
                discord_api('PUT', '/guilds/' . discord_valid_id($gid) . '/bans/' . $id('user'), [], ['delete_message_seconds' => max(0, min(7, (int)($in['days'] ?? 0))) * 86400], $reason ?: 'über die Discord-Zentrale');
                audit('Discord: Mitglied gebannt', $in['user'] . ($reason ? ' – ' . $reason : ''), 'warn');
                $out = ['msg' => 'Gebannt.'];
                break;
            case 'member_unban':
                discord_api('DELETE', '/guilds/' . discord_valid_id($gid) . '/bans/' . $id('user'), [], null, $reason ?: 'über die Discord-Zentrale');
                audit('Discord: Bann aufgehoben', $in['user']);
                $out = ['msg' => 'Bann aufgehoben.'];
                break;
            case 'member_timeout':
                $min = max(0, min(40320, (int)($in['minutes'] ?? 0)));
                discord_api('PATCH', '/guilds/' . discord_valid_id($gid) . '/members/' . $id('user'), [], ['communication_disabled_until' => $min ? gmdate('c', time() + $min * 60) : null], $reason ?: 'über die Discord-Zentrale');
                audit('Discord: ' . ($min ? 'Timeout ' . $min . ' min' : 'Timeout aufgehoben'), $in['user'], $min ? 'warn' : 'info');
                $out = ['msg' => $min ? 'Timeout gesetzt.' : 'Timeout aufgehoben.'];
                break;
            case 'member_nick':
                discord_api('PATCH', '/guilds/' . discord_valid_id($gid) . '/members/' . $id('user'), [], ['nick' => mb_substr(trim((string)($in['nick'] ?? '')), 0, 32) ?: null], 'über die Discord-Zentrale');
                $out = ['msg' => 'Spitzname geändert.'];
                break;
            case 'member_role':
                discord_api(!empty($in['on']) ? 'PUT' : 'DELETE', '/guilds/' . discord_valid_id($gid) . '/members/' . $id('user') . '/roles/' . $id('role'), [], null, 'über die Discord-Zentrale');
                audit('Discord: Rolle ' . (!empty($in['on']) ? 'vergeben' : 'entzogen'), $in['user'] . ' / ' . $in['role']);
                $out = ['msg' => !empty($in['on']) ? 'Rolle vergeben.' : 'Rolle entzogen.'];
                break;
            case 'roles':
                $roles = discord_api('GET', '/guilds/' . discord_valid_id($gid) . '/roles');
                usort($roles, function ($a, $b) { return $b['position'] <=> $a['position']; });
                $out = ['roles' => array_map(function ($r) {
                    $p = (int)$r['permissions'];
                    return ['id' => $r['id'], 'name' => $r['name'], 'color' => discord_color((int)$r['color']), 'position' => (int)$r['position'], 'hoist' => !empty($r['hoist']), 'mentionable' => !empty($r['mentionable']),
                            'managed' => !empty($r['managed']), 'admin' => (bool)($p & 8), 'mod' => (bool)($p & (2 | 4 | (1 << 40))), 'everyone' => $r['name'] === '@everyone'];
                }, $roles)];
                break;
            case 'bans':
                $list = discord_api('GET', '/guilds/' . discord_valid_id($gid) . '/bans', ['limit' => 200]);
                $out = ['bans' => array_map(function ($b) { return ['id' => $b['user']['id'], 'name' => discord_name($b['user']), 'avatar' => discord_user_avatar($b['user']), 'reason' => $b['reason'] ?? '']; }, $list)];
                break;
            case 'audit':
                $r = discord_api('GET', '/guilds/' . discord_valid_id($gid) . '/audit-logs', ['limit' => 60]);
                $users = [];
                foreach ((array)($r['users'] ?? []) as $u) $users[$u['id']] = discord_name($u);
                $out = ['entries' => array_map(function ($e) use ($users) {
                    return ['ts' => discord_ts($e['id']), 'action' => DISCORD_AUDIT[$e['action_type']] ?? ('Aktion ' . $e['action_type']), 'type' => (int)$e['action_type'],
                            'user' => $users[$e['user_id'] ?? ''] ?? ($e['user_id'] ?? ''), 'target' => $users[$e['target_id'] ?? ''] ?? ($e['target_id'] ?? ''), 'reason' => $e['reason'] ?? ''];
                }, (array)($r['audit_log_entries'] ?? []))];
                break;

            // ----- Events (mit Kalender) -----
            case 'events':
                $list = discord_api('GET', '/guilds/' . discord_valid_id($gid) . '/scheduled-events', ['with_user_count' => 'true']);
                $out = ['events' => array_map(function ($e) {
                    return ['id' => $e['id'], 'name' => $e['name'], 'desc' => $e['description'] ?? '', 'start' => strtotime($e['scheduled_start_time']), 'end' => !empty($e['scheduled_end_time']) ? strtotime($e['scheduled_end_time']) : null,
                            'location' => $e['entity_metadata']['location'] ?? '', 'channel' => $e['channel_id'] ?? '', 'users' => (int)($e['user_count'] ?? 0), 'status' => (int)($e['status'] ?? 1)];
                }, $list)];
                if (can('calendar.edit') || can('calendar.private')) {
                    require_once __DIR__ . '/inc/modules/calendar.php';
                    $now = new DateTimeImmutable('today');
                    $items = array_values(array_filter(cal_items($now, $now->modify('+120 days'), can('calendar.private'), ['events', 'countdowns']), function ($it) { return empty($it['source']); }));
                    $out['calendar'] = array_slice(array_map(function ($it) {
                        return ['key' => $it['key'], 'title' => $it['title'], 'start' => $it['start']->getTimestamp(), 'end' => $it['end']->getTimestamp(), 'all_day' => !empty($it['all_day']),
                                'location' => $it['location'] ?? '', 'desc' => format_plain($it['description'] ?? ''), 'type' => $it['type']];
                    }, $items), 0, 60);
                }
                break;
            case 'event_create':
                $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 100);
                $start = strtotime((string)($in['start'] ?? ''));
                $end = strtotime((string)($in['end'] ?? '')) ?: ($start ? $start + 7200 : 0);
                if ($name === '' || !$start) throw new DiscordError('Bitte Name und Beginn angeben.');
                if ($start < time() + 60) throw new DiscordError('Discord-Events müssen in der Zukunft beginnen.');
                if ($end <= $start) $end = $start + 3600;
                discord_api('POST', '/guilds/' . discord_valid_id($gid) . '/scheduled-events', [], ['name' => $name, 'privacy_level' => 2, 'entity_type' => 3,
                    'scheduled_start_time' => gmdate('c', $start), 'scheduled_end_time' => gmdate('c', $end), 'entity_metadata' => ['location' => mb_substr(trim((string)($in['location'] ?? '')) ?: 'Online', 0, 100)],
                    'description' => mb_substr(trim((string)($in['description'] ?? '')), 0, 1000)], 'über die Discord-Zentrale');
                audit('Discord-Event erstellt', $name);
                $out = ['msg' => 'Event „' . $name . '“ auf Discord angelegt.'];
                break;
            case 'event_delete':
                discord_api('DELETE', '/guilds/' . discord_valid_id($gid) . '/scheduled-events/' . $id('event'));
                $out = ['msg' => 'Event gelöscht.'];
                break;
            case 'event_to_calendar':
                if (!can('calendar.edit')) throw new DiscordError('Dafür fehlt dir das Recht „Kalender bearbeiten“.');
                require_once __DIR__ . '/inc/modules/calendar.php';
                $e = discord_api('GET', '/guilds/' . discord_valid_id($gid) . '/scheduled-events/' . $id('event'));
                $s = strtotime($e['scheduled_start_time']);
                $en = !empty($e['scheduled_end_time']) ? strtotime($e['scheduled_end_time']) : $s + 3600;
                cal_insert(cal_event_from_post(['title' => '💬 ' . $e['name'], 'start_date' => date('Y-m-d', $s), 'start_time' => date('H:i', $s), 'end_date' => date('Y-m-d', $en), 'end_time' => date('H:i', $en),
                                                'location' => $e['entity_metadata']['location'] ?? 'Discord', 'description' => trim(($e['description'] ?? '') . "\n\nAus Discord übernommen."), 'private' => 0]));
                $out = ['msg' => 'In den Kalender übernommen.'];
                break;

            // ----- Geplante Nachrichten -----
            case 'scheduled':
                $out = ['items' => array_map(function ($s) { return $s + ['preview' => mb_substr($s['payload']['content'] ?? ($s['payload']['embeds'][0]['title'] ?? ''), 0, 120)]; }, discord_scheduled()),
                        'cron' => dc_self_url('cron=' . discord_cron_key()), 'cron_last' => (int)setting('discord_cron_last', '0')];
                break;
            case 'schedule_save':
                $at = strtotime((string)($in['at'] ?? ''));
                if (!$at || $at < time() - 60) throw new DiscordError('Bitte einen Zeitpunkt in der Zukunft wählen.');
                $list = discord_scheduled();
                if (count($list) >= 100) throw new DiscordError('Maximal 100 geplante Nachrichten.');
                $list[] = ['id' => bin2hex(random_bytes(6)), 'channel' => $id('channel'), 'channel_name' => mb_substr((string)($in['channel_name'] ?? ''), 0, 100), 'payload' => discord_payload($in),
                           'at' => $at, 'repeat' => in_array($in['repeat'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $in['repeat'] : 'none', 'created_by' => user_label(), 'last_sent' => 0, 'last_error' => ''];
                discord_scheduled_save($list);
                $out = ['msg' => 'Geplant für ' . date('d.m.Y H:i', $at) . '.'];
                break;
            case 'schedule_delete':
            case 'schedule_toggle':
            case 'schedule_now':
                $list = discord_scheduled();
                foreach ($list as $k => &$s) {
                    if ($s['id'] !== ($in['id'] ?? '')) continue;
                    if ($act === 'schedule_delete') unset($list[$k]);
                    if ($act === 'schedule_toggle') { $s['paused'] = empty($s['paused']); if (!$s['paused'] && $s['at'] < time()) $s['at'] = time() + 60; }
                    if ($act === 'schedule_now') { discord_send($s['channel'], $s['payload']); $s['last_sent'] = time(); if ($s['repeat'] === 'none') unset($list[$k]); }
                }
                unset($s);
                discord_scheduled_save($list);
                $out = ['msg' => ['schedule_delete' => 'Gelöscht.', 'schedule_toggle' => 'Geändert.', 'schedule_now' => 'Gesendet.'][$act]];
                break;
            case 'countdowns':
                require_once __DIR__ . '/inc/modules/countdown.php';
                [$up] = sorted_countdowns(load_countdowns()['events']);
                $out = ['items' => array_map(function ($e) { return ['id' => $e['id'], 'title' => $e['title'], 'icon' => $e['icon'], 'ts' => $e['ts'], 'days' => (int)ceil(($e['ts'] - time()) / 86400), 'link' => $e['link'] ?? '', 'note' => $e['note'] ?? '']; }, $up)];
                break;
            // ----- Nachrichten: erweitert -----
            case 'msg_edit':
                $content = mb_substr(trim((string)($in['content'] ?? '')), 0, 2000);
                if ($content === '') throw new DiscordError('Der Text darf nicht leer sein.');
                discord_api('PATCH', '/channels/' . $id('channel') . '/messages/' . $id('message'), [], ['content' => $content, 'allowed_mentions' => ['parse' => ['users']]]);
                $out = ['msg' => 'Nachricht bearbeitet.'];
                break;
            case 'msg_react':
                $emoji = trim((string)($in['emoji'] ?? ''));
                if (preg_match('/^<a?:(\w+):(\d+)>$/', $emoji, $m)) $emoji = $m[1] . ':' . $m[2];
                if ($emoji === '' || mb_strlen($emoji) > 60) throw new DiscordError('Bitte ein Emoji angeben.');
                discord_api('PUT', '/channels/' . $id('channel') . '/messages/' . $id('message') . '/reactions/' . rawurlencode($emoji) . '/@me');
                $out = ['msg' => 'Reaktion hinzugefügt.'];
                break;
            case 'poll_end':
                discord_api('POST', '/channels/' . $id('channel') . '/polls/' . $id('message') . '/expire');
                $out = ['msg' => 'Umfrage beendet.'];
                break;
            case 'thread_create':
                $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 100);
                if ($name === '') throw new DiscordError('Bitte einen Thread-Namen angeben.');
                $path = '/channels/' . $id('channel') . (!empty($in['message']) ? '/messages/' . $id('message') : '') . '/threads';
                discord_api('POST', $path, [], ['name' => $name, 'auto_archive_duration' => in_array((int)($in['archive'] ?? 1440), [60, 1440, 4320, 10080], true) ? (int)($in['archive'] ?? 1440) : 1440] + (empty($in['message']) ? ['type' => 11] : []), 'über die Discord-Zentrale');
                $out = ['msg' => 'Thread „' . $name . '“ erstellt.'];
                break;
            case 'threads':
                $r = discord_api('GET', '/guilds/' . discord_valid_id($gid) . '/threads/active');
                $out = ['threads' => array_map(function ($t) { return ['id' => $t['id'], 'name' => $t['name'], 'parent' => $t['parent_id'] ?? '', 'messages' => (int)($t['message_count'] ?? 0), 'members' => (int)($t['member_count'] ?? 0),
                                                                     'archived' => !empty($t['thread_metadata']['archived']), 'locked' => !empty($t['thread_metadata']['locked'])]; }, (array)($r['threads'] ?? []))];
                break;
            case 'thread_edit':
                $body = [];
                foreach (['archived', 'locked'] as $k) if (isset($in[$k])) $body[$k] = !empty($in[$k]);
                discord_api('PATCH', '/channels/' . $id('thread'), [], $body, 'über die Discord-Zentrale');
                $out = ['msg' => 'Thread geändert.'];
                break;
            case 'purge':
                $ch = $id('channel');
                $count = max(1, min(100, (int)($in['count'] ?? 20)));
                $filter = (string)($in['filter'] ?? 'all');
                $needle = mb_strtolower(trim((string)($in['text'] ?? '')));
                $uid = trim((string)($in['user'] ?? ''));
                $msgs = discord_api('GET', '/channels/' . $ch . '/messages', ['limit' => 100]);
                $limit = time() - 14 * 86400 + 3600;
                $ids = [];
                foreach ($msgs as $m) {
                    if (count($ids) >= $count) break;
                    if (strtotime($m['timestamp']) < $limit || !empty($m['pinned'])) continue;
                    if ($filter === 'bots' && empty($m['author']['bot'])) continue;
                    if ($filter === 'mine' && ($m['author']['id'] ?? '') !== (string)setting('discord_bot_id', '')) continue;
                    if ($filter === 'user' && ($m['author']['id'] ?? '') !== $uid) continue;
                    if ($filter === 'text' && ($needle === '' || mb_strpos(mb_strtolower((string)$m['content']), $needle) === false)) continue;
                    if ($filter === 'links' && !preg_match('#https?://#', (string)$m['content'])) continue;
                    if ($filter === 'files' && empty($m['attachments'])) continue;
                    $ids[] = $m['id'];
                }
                if (!$ids) throw new DiscordError('Keine passenden Nachrichten (Discord löscht in einem Rutsch nur Nachrichten der letzten 14 Tage, angepinnte bleiben stehen).');
                if (count($ids) === 1) discord_api('DELETE', '/channels/' . $ch . '/messages/' . $ids[0], [], null, $reason ?: 'Aufräumen über die Discord-Zentrale');
                else discord_api('POST', '/channels/' . $ch . '/messages/bulk-delete', [], ['messages' => $ids], $reason ?: 'Aufräumen über die Discord-Zentrale');
                audit('Discord: ' . count($ids) . ' Nachrichten gelöscht', '#' . $ch, 'warn');
                $out = ['msg' => count($ids) . ' Nachricht(en) gelöscht.'];
                break;
            case 'send_webhook':
                $ch = $id('channel');
                $hooks = dbot_get('webhooks', []);
                $h = $hooks[$ch] ?? null;
                $token = $h ? crypto_decrypt('discord', (string)$h['token']) : null;
                $payload = discord_payload($in);
                $payload['username'] = mb_substr(trim((string)($in['wh_name'] ?? '')), 0, 80) ?: 'GhostNode';
                if (preg_match('#^https://\S+$#', trim((string)($in['wh_avatar'] ?? '')))) $payload['avatar_url'] = trim($in['wh_avatar']);
                if (preg_match('/discord|clyde/i', $payload['username'])) throw new DiscordError('Namen mit „Discord“ oder „Clyde“ erlaubt Discord für Webhooks nicht.');
                unset($payload['message_reference']);
                if (isset($payload['embeds'])) $payload['embeds'] = array_map(function ($e) { return array_filter($e, function ($v) { return $v !== null; }); }, $payload['embeds']);
                if (($payload['content'] ?? '') === '') unset($payload['content']);
                for ($try = 0; $try < 2; $try++) {
                    if (!$h || !$token) {
                        $w = discord_api('POST', '/channels/' . $ch . '/webhooks', [], ['name' => 'GhostNode Persona'], 'Persona-Nachrichten der Discord-Zentrale');
                        $h = ['id' => $w['id'], 'token' => crypto_encrypt('discord', (string)$w['token'])];
                        $token = (string)$w['token'];
                        $hooks[$ch] = $h;
                        dbot_set('webhooks', $hooks);
                    }
                    try { discord_api('POST', '/webhooks/' . $h['id'] . '/' . $token, ['wait' => 'true'], $payload); break; }
                    catch (DiscordError $e) { if ($try === 0 && strpos($e->getMessage(), '10015') === false && strpos($e->getMessage(), 'Unknown Webhook') === false && strpos($e->getMessage(), 'nicht gefunden') === false) throw $e; $h = null; }
                }
                audit('Discord-Nachricht als Persona gesendet', $payload['username'] . ' → #' . $ch);
                $out = ['msg' => 'Als „' . $payload['username'] . '“ gesendet.'];
                break;
            case 'webhooks':
                $list = discord_api('GET', '/guilds/' . discord_valid_id($gid) . '/webhooks');
                $names = [];
                foreach (discord_api('GET', '/guilds/' . $gid . '/channels') as $c) $names[$c['id']] = $c['name'];
                $out = ['webhooks' => array_map(function ($w) use ($names) { return ['id' => $w['id'], 'name' => $w['name'] ?? '', 'channel' => $names[$w['channel_id'] ?? ''] ?? '', 'type' => (int)($w['type'] ?? 1),
                                                                                   'by' => isset($w['user']) ? discord_name($w['user']) : '', 'avatar' => !empty($w['avatar']) ? DISCORD_CDN . '/avatars/' . $w['id'] . '/' . $w['avatar'] . '.png?size=64' : '']; }, $list)];
                break;
            case 'webhook_create':
                $w = discord_api('POST', '/channels/' . $id('channel') . '/webhooks', [], ['name' => mb_substr(trim((string)($in['name'] ?? '')), 0, 80) ?: 'Webhook'], 'über die Discord-Zentrale');
                $out = ['msg' => 'Webhook erstellt.', 'url' => 'https://discord.com/api/webhooks/' . $w['id'] . '/' . ($w['token'] ?? '')];
                break;
            case 'webhook_delete':
                discord_api('DELETE', '/webhooks/' . $id('webhook'), [], null, 'über die Discord-Zentrale');
                $hooks = array_filter(dbot_get('webhooks', []), function ($h) use ($in) { return $h['id'] !== $in['webhook']; });
                dbot_set('webhooks', $hooks);
                $out = ['msg' => 'Webhook gelöscht.'];
                break;

            // ----- Kanäle & Rollen bearbeiten -----
            case 'channel_edit':
                $body = [];
                if (isset($in['name'])) { $body['name'] = mb_substr(trim((string)$in['name']), 0, 100); if ($body['name'] === '') throw new DiscordError('Name fehlt.'); }
                if (isset($in['topic'])) $body['topic'] = mb_substr(trim((string)$in['topic']), 0, 1024);
                if (isset($in['slowmode'])) $body['rate_limit_per_user'] = max(0, min(21600, (int)$in['slowmode']));
                if (isset($in['nsfw'])) $body['nsfw'] = !empty($in['nsfw']);
                discord_api('PATCH', '/channels/' . $id('channel'), [], $body, $reason ?: 'über die Discord-Zentrale');
                audit('Discord-Kanal geändert', '#' . ($body['name'] ?? $in['channel']));
                $out = ['msg' => 'Kanal gespeichert.'];
                break;
            case 'channel_delete':
                discord_api('DELETE', '/channels/' . $id('channel'), [], null, $reason ?: 'über die Discord-Zentrale');
                audit('Discord-Kanal gelöscht', $in['channel'], 'warn');
                $out = ['msg' => 'Kanal gelöscht.'];
                break;
            case 'role_save':
                $body = ['name' => mb_substr(trim((string)($in['name'] ?? '')), 0, 100), 'color' => preg_match('/^#?([0-9a-f]{6})$/i', (string)($in['color'] ?? ''), $m) ? hexdec($m[1]) : 0,
                         'hoist' => !empty($in['hoist']), 'mentionable' => !empty($in['mentionable'])];
                if ($body['name'] === '') throw new DiscordError('Bitte einen Rollennamen angeben.');
                if (!empty($in['role'])) discord_api('PATCH', '/guilds/' . discord_valid_id($gid) . '/roles/' . $id('role'), [], $body, 'über die Discord-Zentrale');
                else discord_api('POST', '/guilds/' . discord_valid_id($gid) . '/roles', [], $body + ['permissions' => '0'], 'über die Discord-Zentrale');
                audit('Discord-Rolle ' . (!empty($in['role']) ? 'geändert' : 'erstellt'), $body['name']);
                $out = ['msg' => 'Rolle „' . $body['name'] . '“ gespeichert.'];
                break;
            case 'role_delete':
                discord_api('DELETE', '/guilds/' . discord_valid_id($gid) . '/roles/' . $id('role'), [], null, 'über die Discord-Zentrale');
                audit('Discord-Rolle gelöscht', $in['role'], 'warn');
                $out = ['msg' => 'Rolle gelöscht.'];
                break;

            // ----- Emojis & Sticker -----
            case 'emojis':
                $g = discord_valid_id($gid);
                $em = discord_api('GET', '/guilds/' . $g . '/emojis');
                $st = [];
                try { $st = discord_api('GET', '/guilds/' . $g . '/stickers'); } catch (DiscordError $e) {}
                $gi = discord_api('GET', '/guilds/' . $g);
                $out = ['emojis' => array_map(function ($e) { return ['id' => $e['id'], 'name' => $e['name'], 'animated' => !empty($e['animated']), 'url' => DISCORD_CDN . '/emojis/' . $e['id'] . '.' . (!empty($e['animated']) ? 'gif' : 'png') . '?size=96',
                                                                    'by' => isset($e['user']) ? discord_name($e['user']) : '', 'managed' => !empty($e['managed'])]; }, $em),
                        'stickers' => array_map(function ($s) { return ['id' => $s['id'], 'name' => $s['name'], 'desc' => $s['description'] ?? '', 'url' => DISCORD_CDN . '/stickers/' . $s['id'] . '.png?size=160', 'format' => (int)($s['format_type'] ?? 1)]; }, $st),
                        'limit' => [0 => 50, 1 => 100, 2 => 150, 3 => 250][(int)($gi['premium_tier'] ?? 0)] ?? 50];
                break;
            case 'emoji_upload':
                $name = preg_replace('/[^\w]/', '_', trim((string)($in['name'] ?? '')));
                if (strlen($name) < 2) throw new DiscordError('Name: mindestens 2 Zeichen (Buchstaben, Zahlen, _).');
                $f = $_FILES['file'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new DiscordError('Bitte ein Bild auswählen.');
                if ($f['size'] > 256 * 1024) throw new DiscordError('Emojis dürfen höchstens 256 KB groß sein.');
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
                if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) throw new DiscordError('Nur PNG, JPG, GIF oder WebP.');
                discord_api('POST', '/guilds/' . discord_valid_id($gid) . '/emojis', [], ['name' => mb_substr($name, 0, 32), 'image' => 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($f['tmp_name']))], 'über die Discord-Zentrale');
                audit('Discord-Emoji hochgeladen', ':' . $name . ':');
                $out = ['msg' => 'Emoji :' . $name . ': hochgeladen.'];
                break;
            case 'emoji_rename':
                $name = preg_replace('/[^\w]/', '_', trim((string)($in['name'] ?? '')));
                if (strlen($name) < 2) throw new DiscordError('Name: mindestens 2 Zeichen.');
                discord_api('PATCH', '/guilds/' . discord_valid_id($gid) . '/emojis/' . $id('emoji'), [], ['name' => mb_substr($name, 0, 32)]);
                $out = ['msg' => 'Umbenannt.'];
                break;
            case 'emoji_delete':
                discord_api('DELETE', '/guilds/' . discord_valid_id($gid) . '/emojis/' . $id('emoji'), [], null, 'über die Discord-Zentrale');
                $out = ['msg' => 'Emoji gelöscht.'];
                break;
            case 'sticker_delete':
                discord_api('DELETE', '/guilds/' . discord_valid_id($gid) . '/stickers/' . $id('sticker'), [], null, 'über die Discord-Zentrale');
                $out = ['msg' => 'Sticker gelöscht.'];
                break;

            // ----- AutoMod -----
            case 'automod':
                $rules = discord_api('GET', '/guilds/' . discord_valid_id($gid) . '/auto-moderation/rules');
                $out = ['rules' => array_map(function ($r) {
                    return ['id' => $r['id'], 'name' => $r['name'], 'enabled' => !empty($r['enabled']), 'trigger' => (int)$r['trigger_type'], 'keywords' => (array)($r['trigger_metadata']['keyword_filter'] ?? []),
                            'presets' => (array)($r['trigger_metadata']['presets'] ?? []), 'mention_limit' => (int)($r['trigger_metadata']['mention_total_limit'] ?? 0),
                            'actions' => array_map(function ($a) { return (int)$a['type']; }, (array)($r['actions'] ?? []))];
                }, $rules)];
                break;
            case 'automod_create':
                $trigger = (int)($in['trigger'] ?? 1);
                if (!in_array($trigger, [1, 3, 4, 5], true)) throw new DiscordError('Unbekannte Regel-Art.');
                $meta = [];
                if ($trigger === 1) {
                    $kw = array_slice(array_values(array_filter(array_map(function ($k) { return mb_substr(trim($k), 0, 60); }, preg_split('/[\n,]+/u', (string)($in['keywords'] ?? '')) ?: []), 'strlen')), 0, 1000);
                    if (!$kw) throw new DiscordError('Bitte mindestens ein Wort angeben (Sternchen erlaubt, z. B. *spam*).');
                    $meta['keyword_filter'] = $kw;
                }
                if ($trigger === 4) { $meta['presets'] = array_values(array_intersect(array_map('intval', (array)($in['presets'] ?? [])), [1, 2, 3])) ?: [1, 2, 3]; }
                if ($trigger === 5) $meta['mention_total_limit'] = max(1, min(50, (int)($in['mention_limit'] ?? 5)));
                $actions = [['type' => 1, 'metadata' => ['custom_message' => mb_substr(trim((string)($in['block_msg'] ?? '')) ?: 'Diese Nachricht wurde von der Moderation blockiert.', 0, 150)]]];
                if (!empty($in['alert']) && preg_match('/^\d{15,22}$/', (string)$in['alert'])) $actions[] = ['type' => 2, 'metadata' => ['channel_id' => $in['alert']]];
                if ((int)($in['timeout'] ?? 0) > 0 && in_array($trigger, [1, 5], true)) $actions[] = ['type' => 3, 'metadata' => ['duration_seconds' => min(2419200, (int)$in['timeout'] * 60)]];
                discord_api('POST', '/guilds/' . discord_valid_id($gid) . '/auto-moderation/rules', [], ['name' => mb_substr(trim((string)($in['name'] ?? '')) ?: 'Regel', 0, 100), 'event_type' => 1,
                    'trigger_type' => $trigger, 'trigger_metadata' => $meta ?: new stdClass(), 'actions' => $actions, 'enabled' => true], 'über die Discord-Zentrale');
                audit('Discord: AutoMod-Regel erstellt', (string)($in['name'] ?? ''));
                $out = ['msg' => 'AutoMod-Regel erstellt.'];
                break;
            case 'automod_toggle':
                discord_api('PATCH', '/guilds/' . discord_valid_id($gid) . '/auto-moderation/rules/' . $id('rule'), [], ['enabled' => !empty($in['on'])], 'über die Discord-Zentrale');
                $out = ['msg' => !empty($in['on']) ? 'Regel aktiv.' : 'Regel pausiert.'];
                break;
            case 'automod_delete':
                discord_api('DELETE', '/guilds/' . discord_valid_id($gid) . '/auto-moderation/rules/' . $id('rule'), [], null, 'über die Discord-Zentrale');
                $out = ['msg' => 'Regel gelöscht.'];
                break;

            // ----- Slash-Befehle -----
            case 'cmds':
                $cfg = dbot_cmd_cfg();
                $st = dbot_get('istats', []);
                $out = ['builtins' => array_map(function ($n, $b) use ($cfg, $st) { return ['name' => $n, 'desc' => $b[0], 'nsfw' => $b[2], 'on' => !in_array($n, $cfg['disabled'], true), 'uses' => (int)($st[$n] ?? 0),
                                                                                   'options' => array_map(function ($o) { return $o['name']; }, $b[1])]; }, array_keys(dbot_builtins()), dbot_builtins()),
                        'custom' => $cfg['custom'], 'nsfw_default' => $cfg['nsfw_default'], 'sfw_default' => $cfg['sfw_default'],
                        'app_id' => setting('discord_app_id', ''), 'pubkey' => setting('discord_pubkey', '') !== '', 'endpoint' => dc_self_url('interactions'), 'endpoint_set' => setting('discord_endpoint_ok', ''),
                        'registered' => (int)setting('discord_cmds_registered_' . $gid, '0'), 'log' => array_slice(dbot_get('ilog', []), 0, 40), 'stats' => $st, 'sodium' => function_exists('sodium_crypto_sign_verify_detached'),
                        'https' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'sources_nsfw' => DBOT_NSFW_SOURCES, 'sources_sfw' => DBOT_SFW_SOURCES,
                        'providers' => array_map(function ($p) { return $p[1] . ' ' . $p[0]; }, NSFW_PROVIDERS)];
                break;
            case 'bot_setup':
                $app = dbot_app();
                $msg = 'Anwendung „' . ($app['name'] ?? '') . '“ erkannt.';
                if (!empty($in['set_endpoint'])) {
                    $url = dc_self_url('interactions');
                    if (strpos($url, 'https://') !== 0) throw new DiscordError('Discord verlangt eine HTTPS-Adresse. Die Seite läuft gerade über http – bitte über https:// aufrufen (SSL beim Hoster aktivieren).');
                    try { discord_api('PATCH', '/applications/@me', [], ['interactions_endpoint_url' => $url]); }
                    catch (DiscordError $e) { throw new DiscordError('Discord konnte den Endpunkt nicht bestätigen: ' . $e->getMessage() . ' – trage die Adresse sonst im Developer-Portal unter „General Information → Interactions Endpoint URL“ ein.'); }
                    set_setting('discord_endpoint_ok', $url);
                    $msg .= ' Endpunkt eingetragen.';
                }
                $n = dbot_register(discord_valid_id($gid));
                audit('Discord: Slash-Befehle registriert', $n . ' Befehle');
                $out = ['msg' => $msg . ' ' . $n . ' Befehle auf dem Server registriert.'];
                break;
            case 'endpoint_confirm':
                set_setting('discord_endpoint_ok', dc_self_url('interactions'));
                $out = ['msg' => 'Gemerkt.'];
                break;
            case 'cmds_register':
                $n = dbot_register(discord_valid_id($gid));
                $out = ['msg' => $n . ' Befehle bei Discord registriert – sie sind sofort verfügbar.'];
                break;
            case 'cmd_toggle':
                $cfg = dbot_cmd_cfg();
                $name = (string)($in['name'] ?? '');
                if (!isset(dbot_builtins()[$name])) throw new DiscordError('Unbekannter Befehl.');
                $cfg['disabled'] = array_values(array_diff($cfg['disabled'], [$name]));
                if (empty($in['on'])) $cfg['disabled'][] = $name;
                dbot_set('cmds', $cfg);
                $out = ['msg' => '/' . $name . (empty($in['on']) ? ' aus' : ' an') . ' – zum Übernehmen „Bei Discord registrieren“ klicken.'];
                break;
            case 'cmd_defaults':
                $cfg = dbot_cmd_cfg();
                if (in_array($in['nsfw_default'] ?? '', DBOT_NSFW_SOURCES, true)) $cfg['nsfw_default'] = $in['nsfw_default'];
                if (in_array($in['sfw_default'] ?? '', DBOT_SFW_SOURCES, true)) $cfg['sfw_default'] = $in['sfw_default'];
                dbot_set('cmds', $cfg);
                $out = ['msg' => 'Gespeichert.'];
                break;
            case 'cmd_save':
                $cfg = dbot_cmd_cfg();
                $name = mb_strtolower(trim((string)($in['name'] ?? '')));
                if (!preg_match(DBOT_NAME_RE, $name)) throw new DiscordError('Befehlsname: 1–32 Kleinbuchstaben, Zahlen, - oder _ (keine Leerzeichen).');
                if (isset(dbot_builtins()[$name])) throw new DiscordError('/' . $name . ' ist schon eingebaut.');
                $resp = mb_substr(trim((string)($in['response'] ?? '')), 0, 2000);
                if ($resp === '') throw new DiscordError('Bitte eine Antwort eingeben.');
                $c = ['name' => $name, 'desc' => mb_substr(trim((string)($in['desc'] ?? '')), 0, 100), 'response' => $resp, 'title' => mb_substr(trim((string)($in['title'] ?? '')), 0, 256),
                      'embed' => !empty($in['embed']), 'color' => preg_match('/^#?([0-9a-f]{6})$/i', (string)($in['color'] ?? ''), $m) ? hexdec($m[1]) : 0x5865f2,
                      'image' => preg_match('#^https://\S+$#', trim((string)($in['image'] ?? ''))) ? trim($in['image']) : '', 'ephemeral' => !empty($in['ephemeral']), 'nsfw' => !empty($in['nsfw']), 'uses' => 0];
                $old = (string)($in['old'] ?? '');
                $found = false;
                foreach ($cfg['custom'] as &$x) if ($x['name'] === ($old ?: $name)) { $c['uses'] = (int)($x['uses'] ?? 0); $x = $c; $found = true; }
                unset($x);
                if (!$found) {
                    if (array_filter($cfg['custom'], function ($x) use ($name) { return $x['name'] === $name; })) throw new DiscordError('/' . $name . ' gibt es schon.');
                    if (count($cfg['custom']) >= 80) throw new DiscordError('Maximal 80 eigene Befehle.');
                    $cfg['custom'][] = $c;
                }
                dbot_set('cmds', $cfg);
                $out = ['msg' => '/' . $name . ' gespeichert – „Bei Discord registrieren“ klicken, damit er erscheint.'];
                break;
            case 'cmd_delete':
                $cfg = dbot_cmd_cfg();
                $cfg['custom'] = array_values(array_filter($cfg['custom'], function ($x) use ($in) { return $x['name'] !== ($in['name'] ?? ''); }));
                dbot_set('cmds', $cfg);
                $out = ['msg' => 'Gelöscht – „Bei Discord registrieren“ klicken, damit er verschwindet.'];
                break;
            case 'cmd_test':
                // Befehl im Dashboard ausprobieren – ohne Discord, ohne Seiteneffekte
                $me = ['id' => '100000000000000001', 'username' => user_label() ?: 'du', 'global_name' => user_label() ?: 'Du'];
                $opts = [];
                foreach ((array)json_decode((string)($in['options'] ?? '{}'), true) as $k => $v) if ($v !== '' && $v !== null) $opts[] = ['name' => (string)$k, 'value' => $v];
                $it = ['type' => 2, 'guild_id' => $gid, 'channel_id' => setting('discord_channel', '') ?: '0', 'application_id' => '0', 'token' => '', 'member' => ['user' => $me, 'roles' => [], 'joined_at' => date('c')],
                       'data' => ['name' => (string)($in['name'] ?? ''), 'options' => $opts]];
                $GLOBALS['dbot_test'] = true;   // nichts protokollieren
                $r = dbot_handle($it, true);
                if (!empty($r['_later'])) { $r = ['type' => 4, 'data' => $r['_later']()]; }
                $out = ['response' => $r['data'] ?? $r];
                break;

            // ----- Rollen-Knöpfe -----
            case 'panels':
                $out = ['panels' => dbot_get('panels', [])];
                break;
            case 'panel_save':
                $btns = [];
                foreach ((array)($in['b_role'] ?? []) as $i => $role) {
                    if (!preg_match('/^\d{15,22}$/', (string)$role)) continue;
                    $label = mb_substr(trim((string)(($in['b_label'] ?? [])[$i] ?? '')), 0, 80);
                    $btns[] = ['role' => $role, 'label' => $label !== '' ? $label : 'Rolle', 'emoji' => mb_substr(trim((string)(($in['b_emoji'] ?? [])[$i] ?? '')), 0, 64), 'style' => (int)(($in['b_style'] ?? [])[$i] ?? 2)];
                }
                if (!$btns) throw new DiscordError('Bitte mindestens eine Rolle hinzufügen.');
                if (count($btns) > 25) throw new DiscordError('Höchstens 25 Knöpfe pro Nachricht.');
                $panels = dbot_get('panels', []);
                $pid = (string)($in['id'] ?? '');
                $p = ['id' => $pid !== '' ? preg_replace('/\W/', '', $pid) : bin2hex(random_bytes(4)), 'channel' => $id('channel'), 'title' => mb_substr(trim((string)($in['title'] ?? '')) ?: 'Rollen auswählen', 0, 256),
                      'desc' => mb_substr(trim((string)($in['desc'] ?? '')), 0, 2000), 'color' => preg_match('/^#?([0-9a-f]{6})$/i', (string)($in['color'] ?? ''), $m) ? hexdec($m[1]) : 0x5865f2,
                      'mode' => in_array($in['mode'] ?? '', ['toggle', 'unique', 'add'], true) ? $in['mode'] : 'toggle', 'buttons' => $btns, 'message' => '', 'guild' => $gid];
                $old = null;
                foreach ($panels as $k => $x) if ($x['id'] === $p['id']) { $old = $x; unset($panels[$k]); }
                if ($old && $old['message'] !== '' && $old['channel'] === $p['channel']) {
                    try { discord_api('PATCH', '/channels/' . $p['channel'] . '/messages/' . $old['message'], [], dbot_panel_message($p)); $p['message'] = $old['message']; } catch (DiscordError $e) {}
                }
                if ($p['message'] === '') {
                    if ($old && $old['message'] !== '') { try { discord_api('DELETE', '/channels/' . $old['channel'] . '/messages/' . $old['message']); } catch (DiscordError $e) {} }
                    $r = discord_send($p['channel'], dbot_panel_message($p));
                    $p['message'] = (string)($r['id'] ?? '');
                }
                $panels[] = $p;
                dbot_set('panels', array_values($panels));
                audit('Discord: Rollen-Knöpfe ' . ($old ? 'aktualisiert' : 'gepostet'), $p['title']);
                $out = ['msg' => $old ? 'Rollen-Knöpfe aktualisiert.' : 'Rollen-Knöpfe gepostet.'];
                break;
            case 'panel_delete':
                $panels = dbot_get('panels', []);
                foreach ($panels as $k => $x) if ($x['id'] === ($in['id'] ?? '')) {
                    if (!empty($in['message']) && $x['message'] !== '') { try { discord_api('DELETE', '/channels/' . $x['channel'] . '/messages/' . $x['message']); } catch (DiscordError $e) {} }
                    unset($panels[$k]);
                }
                dbot_set('panels', array_values($panels));
                $out = ['msg' => 'Entfernt.'];
                break;

            // ----- Automatik -----
            case 'auto':
                $w = dbot_welcome_cfg();
                unset($w['known']);
                $out = ['welcome' => $w, 'autoposts' => array_map(function ($a) { unset($a['seen']); return $a; }, dbot_autoposts()), 'cron' => dc_self_url('cron=' . discord_cron_key()), 'cron_last' => (int)setting('discord_cron_last', '0'),
                        'providers' => array_map(function ($p) { return ['name' => $p[1] . ' ' . $p[0], 'nsfw' => $p[4], 'sfw' => $p[5]]; }, NSFW_PROVIDERS), 'ratings' => NSFW_RATINGS,
                        'saved' => nsfw_saved((int)(current_user()['id'] ?? 0))];
                break;
            case 'welcome_save':
                $w = dbot_welcome_cfg();
                foreach (['on', 'embed', 'bye_on'] as $k) $w[$k] = !empty($in[$k]);
                foreach (['channel', 'bye_channel', 'autorole'] as $k) $w[$k] = preg_match('/^\d{15,22}$/', (string)($in[$k] ?? '')) ? $in[$k] : '';
                $w['text'] = mb_substr(trim((string)($in['text'] ?? '')), 0, 1500) ?: $w['text'];
                $w['bye_text'] = mb_substr(trim((string)($in['bye_text'] ?? '')), 0, 1000) ?: $w['bye_text'];
                $w['color'] = preg_match('/^#[0-9a-f]{6}$/i', (string)($in['color'] ?? '')) ? $in['color'] : '#38d5e6';
                if ($w['on'] && $w['channel'] === '') throw new DiscordError('Bitte einen Kanal für die Begrüßung wählen.');
                dbot_set('welcome', $w);
                $out = ['msg' => 'Gespeichert.'];
                break;
            case 'welcome_test':
                $w = dbot_welcome_cfg();
                if ($w['channel'] === '') throw new DiscordError('Erst einen Kanal wählen und speichern.');
                $me = discord_api('GET', '/users/@me');
                $g = discord_api('GET', '/guilds/' . discord_valid_id($gid), ['with_counts' => 'true']);
                $txt = strtr($w['text'], ['{user}' => '**' . discord_name($me) . '**', '{name}' => discord_name($me), '{server}' => $g['name'] ?? '', '{count}' => (string)(int)($g['approximate_member_count'] ?? 0)]);
                discord_send($w['channel'], $w['embed'] ? ['content' => '🧪 Test der Begrüßung:', 'embeds' => [['description' => $txt, 'color' => hexdec(ltrim($w['color'], '#')), 'thumbnail' => ['url' => discord_user_avatar($me)]]]] : ['content' => '🧪 ' . $txt]);
                $out = ['msg' => 'Test-Begrüßung gesendet.'];
                break;
            case 'welcome_run':
                $n = dbot_run_welcome(true);
                $out = ['msg' => 'Geprüft – ' . $n . ' Aktion(en).'];
                break;
            case 'autopost_save':
                $list = dbot_autoposts();
                $kind = ($in['kind'] ?? '') === 'rss' ? 'rss' : 'images';
                $a = ['id' => preg_replace('/\W/', '', (string)($in['id'] ?? '')) ?: bin2hex(random_bytes(4)), 'name' => mb_substr(trim((string)($in['name'] ?? '')), 0, 80), 'kind' => $kind, 'channel' => $id('channel'),
                      'interval' => max(30, min(10080, (int)($in['interval'] ?? 240))), 'paused' => false, 'last_error' => '', 'last_post' => 0, 'count' => 0, 'seen' => [], 'note' => mb_substr(trim((string)($in['note'] ?? '')), 0, 300)];
                if ($kind === 'rss') {
                    $a['url'] = trim((string)($in['url'] ?? ''));
                    $items = dbot_rss($a['url']);
                    if ($a['name'] === '') $a['name'] = parse_url($a['url'], PHP_URL_HOST) ?: 'Feed';
                    $a['seen'] = array_slice(array_column($items, 'id'), 1);   // nur der neueste kommt beim ersten Lauf
                } else {
                    $a['provider'] = isset(NSFW_PROVIDERS[$in['provider'] ?? '']) ? $in['provider'] : 'e926';
                    $a['rating'] = isset(NSFW_RATINGS[$in['rating'] ?? '']) ? $in['rating'] : 'safe';
                    $a['q'] = implode(' ', nsfw_parse_tags((string)($in['q'] ?? '')));
                    if (!NSFW_PROVIDERS[$a['provider']][4]) $a['rating'] = 'safe';
                    if ($a['rating'] !== 'safe') {
                        $ch = discord_api('GET', '/channels/' . $a['channel']);
                        if (empty($ch['nsfw'])) throw new DiscordError('NSFW-Auto-Posts gehen nur in altersbeschränkte Kanäle (Kanal bearbeiten → „Altersbeschränkter Kanal“).');
                    }
                    if ($a['name'] === '') $a['name'] = NSFW_PROVIDERS[$a['provider']][0] . ($a['q'] !== '' ? ': ' . $a['q'] : '');
                }
                $a['next_at'] = !empty($in['now']) ? time() : time() + $a['interval'] * 60;
                $found = false;
                foreach ($list as &$x) if ($x['id'] === $a['id']) { $a['count'] = (int)($x['count'] ?? 0); $a['seen'] = $kind === $x['kind'] && $kind === 'images' ? (array)($x['seen'] ?? []) : $a['seen']; $x = $a; $found = true; }
                unset($x);
                if (!$found) { if (count($list) >= 30) throw new DiscordError('Maximal 30 Auto-Posts.'); $list[] = $a; }
                dbot_set('autoposts', $list);
                $sent = !empty($in['now']) ? dbot_run_autoposts() : 0;
                $after = array_values(array_filter(dbot_autoposts(), function ($x) use ($a) { return $x['id'] === $a['id']; }));
                if ($sent === 0 && $after && $after[0]['last_error'] !== '') throw new DiscordError('Gespeichert, aber der erste Post klappte nicht: ' . $after[0]['last_error']);
                $out = ['msg' => 'Auto-Post gespeichert' . ($sent ? ' und ersten Beitrag gesendet.' : '.')];
                break;
            case 'autopost_toggle':
            case 'autopost_delete':
            case 'autopost_now':
                $list = dbot_autoposts();
                foreach ($list as $k => &$x) {
                    if ($x['id'] !== ($in['id'] ?? '')) continue;
                    if ($act === 'autopost_delete') unset($list[$k]);
                    if ($act === 'autopost_toggle') { $x['paused'] = empty($x['paused']); $x['last_error'] = ''; }
                    if ($act === 'autopost_now') { $x['next_at'] = 0; $x['paused'] = false; }
                }
                unset($x);
                dbot_set('autoposts', array_values($list));
                $n = $act === 'autopost_now' ? dbot_run_autoposts() : 0;
                if ($act === 'autopost_now') {
                    $after = array_values(array_filter(dbot_autoposts(), function ($x) use ($in) { return $x['id'] === $in['id']; }));
                    if (!$n && $after && $after[0]['last_error'] !== '') throw new DiscordError($after[0]['last_error']);
                }
                $out = ['msg' => ['autopost_toggle' => 'Geändert.', 'autopost_delete' => 'Gelöscht.', 'autopost_now' => $n . ' Beitrag/Beiträge gesendet.'][$act]];
                break;
            default:
                throw new DiscordError('Unbekannte Aktion.');
        }
    } catch (Throwable $e) {
        http_response_code(400);
        $out = ['error' => $e->getMessage()];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$configured = discord_configured();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discord-Zentrale</title>
    <?php echo emoji_favicon('🤖'); ?>
    <link rel="stylesheet" href="assets/css/hud.css">
    <link rel="stylesheet" href="assets/css/discord.css">
</head>
<body>
<?php auth_corner(); ?>
<div class="wrapper" style="max-width: 1320px;">
    <div class="nav-bar">
        <a href="index.php" class="nav-back">&larr; ZURÜCK ZUM INDEX</a>
        <?php if ($configured): ?><span class="dc-guildpick"><span id="botInfo"></span><select id="guildSel" aria-label="Server"></select></span><?php endif; ?>
    </div>
    <header class="dc-head">
        <div>
            <div class="hud-tag">Discord // Bot-Steuerung</div>
            <h1>Discord-Zentrale</h1>
        </div>
    </header>

<?php if (!$configured): ?>
    <div class="dc-setup">
        <div class="panel">
            <div class="panel-title">🤖 Bot verbinden</div>
            <form id="setupForm">
                <label class="field" for="dcToken">Bot-Token</label>
                <input type="password" id="dcToken" name="token" autocomplete="off" required placeholder="MTEy…">
                <button class="btn btn-gold" style="margin-top: 12px; width: 100%;">Verbinden</button>
            </form>
            <div class="dc-error" id="setupErr" hidden></div>
            <p class="dc-small">🔒 Der Token wird verschlüsselt gespeichert (AES-256-GCM) und nie wieder angezeigt. Wer ihn hat, kann den Bot steuern – nicht weitergeben!</p>
        </div>
        <div class="panel">
            <div class="panel-title">So bekommst du einen Bot (5 Minuten)</div>
            <ol class="dc-steps">
                <li>Auf <a href="https://discord.com/developers/applications" target="_blank" rel="noopener noreferrer">discord.com/developers/applications</a> → <b>New Application</b>, Namen vergeben.</li>
                <li>Links <b>Bot</b> → <b>Reset Token</b> → Token kopieren und hier einfügen.</li>
                <li>Unter <b>Privileged Gateway Intents</b> einschalten: <b>Server Members Intent</b> (für Mitgliederlisten) und <b>Message Content Intent</b> (um Nachrichten-Texte zu lesen).</li>
                <li>Nach dem Verbinden zeigt dir die Zentrale einen <b>Einladungslink</b>, mit dem du den Bot auf deinen Server holst.</li>
                <li>Auf dem Server die Bot-Rolle in der Rollenliste <b>über</b> die Rollen schieben, die er verwalten soll.</li>
            </ol>
        </div>
    </div>
<?php else: ?>
    <nav class="dc-tabs" id="tabs" role="tablist">
        <?php foreach (['overview' => ['📊', 'Übersicht'], 'messages' => ['💬', 'Nachrichten'], 'members' => ['👥', 'Mitglieder'], 'roles' => ['🎭', 'Rollen'], 'channels' => ['#', 'Kanäle'],
                        'events' => ['📅', 'Events'], 'commands' => ['⚡', 'Befehle'], 'panels' => ['🎛', 'Rollen-Knöpfe'], 'auto' => ['🤖', 'Automatik'], 'emojis' => ['😀', 'Emojis'],
                        'mod' => ['🛡️', 'Moderation'], 'scheduled' => ['⏰', 'Geplant'], 'settings' => ['⚙', 'Einstellungen']] as $k => [$ic, $l]): ?>
            <button type="button" class="dc-tab" data-tab="<?php echo $k; ?>" role="tab"><span><?php echo $ic; ?></span><?php echo $l; ?></button>
        <?php endforeach; ?>
    </nav>
    <div class="dc-error" id="globalErr" hidden></div>

    <section class="dc-pane" data-pane="overview"><div id="ovBox"><div class="dc-loading">Lade Server …</div></div></section>

    <section class="dc-pane" data-pane="messages" hidden>
        <div class="dc-cols">
            <form class="panel" id="composer">
                <div class="panel-title">✍ Nachricht verfassen</div>
                <label class="field">Kanal</label>
                <select name="channel" class="dc-chsel" required></select>
                <label class="field">Text <small>(Discord-Markdown: **fett**, *kursiv*, __unterstrichen__, ||Spoiler||, `Code`, &gt; Zitat)</small></label>
                <textarea name="content" rows="4" maxlength="2000" placeholder="Hallo zusammen! 🐾"></textarea>
                <div class="dc-counter"><span id="cCount">0</span> / 2000</div>
                <label class="dc-check"><input type="checkbox" name="use_embed" value="1" id="useEmbed"> 🧱 Embed (Infobox) hinzufügen</label>
                <div id="embedFields" hidden>
                    <div class="dc-row"><div><label class="field">Titel</label><input type="text" name="e_title" maxlength="256"></div>
                        <div style="max-width: 110px;"><label class="field">Farbe</label><input type="color" name="e_color" value="#38d5e6"></div></div>
                    <label class="field">Link des Titels</label><input type="text" name="e_url" placeholder="https://…">
                    <label class="field">Beschreibung</label><textarea name="e_desc" rows="4" maxlength="4096"></textarea>
                    <div class="dc-row"><div><label class="field">Bild (groß)</label><input type="text" name="e_image" placeholder="https://…/bild.png"></div><div><label class="field">Vorschaubild (klein)</label><input type="text" name="e_thumb" placeholder="https://…"></div></div>
                    <div class="dc-row"><div><label class="field">Autor-Zeile</label><input type="text" name="e_author" maxlength="256"></div><div><label class="field">Fußzeile</label><input type="text" name="e_footer" maxlength="2048"></div></div>
                    <label class="dc-check"><input type="checkbox" name="e_time" value="1"> Zeitstempel anzeigen</label>
                    <label class="field">Felder</label>
                    <div id="fieldRows"></div>
                    <button type="button" class="btn btn-sm" id="addField">＋ Feld</button>
                </div>
                <label class="dc-check"><input type="checkbox" name="use_poll" value="1" id="usePoll"> 📊 Umfrage anhängen</label>
                <div id="pollFields" hidden>
                    <label class="field">Frage</label><input type="text" name="poll_q" maxlength="300" placeholder="Wann treffen wir uns?">
                    <label class="field">Antworten <small>(ein Emoji am Anfang wird zum Antwort-Emoji, z. B. „🍕 Pizza“)</small></label>
                    <div id="pollRows"></div>
                    <button type="button" class="btn btn-sm" id="addAnswer">＋ Antwort</button>
                    <div class="dc-row"><div><label class="field">Laufzeit</label><select name="poll_hours"><option value="1">1 Stunde</option><option value="4">4 Stunden</option><option value="8">8 Stunden</option><option value="24" selected>1 Tag</option><option value="72">3 Tage</option><option value="168">1 Woche</option><option value="336">2 Wochen</option></select></div>
                        <div><label class="dc-check" style="margin-top: 30px;"><input type="checkbox" name="poll_multi" value="1"> Mehrfachauswahl</label></div></div>
                </div>
                <label class="dc-check"><input type="checkbox" name="as_webhook" value="1" id="asWebhook"> 🎭 Als Persona senden (eigener Name &amp; Bild über Webhook)</label>
                <div id="whFields" hidden>
                    <div class="dc-row"><div><label class="field">Anzeigename</label><input type="text" name="wh_name" maxlength="80" placeholder="z. B. Con-Orga, Wetterfuchs"></div>
                        <div><label class="field">Profilbild-Adresse</label><input type="text" name="wh_avatar" placeholder="https://…/bild.png"></div></div>
                    <select id="personaSel" aria-label="Gespeicherte Personas"><option value="">🎭 gespeicherte Personas …</option></select> <button type="button" class="btn btn-sm" id="personaSave">💾 Persona merken</button>
                </div>
                <label class="dc-check"><input type="checkbox" name="mentions" value="1"> @everyone / @here / Rollen-Erwähnungen erlauben</label>
                <input type="hidden" name="reply_to" value="">
                <div class="dc-reply" id="replyInfo" hidden></div>
                <div class="dc-sched" id="schedBox" hidden>
                    <div class="dc-row"><div><label class="field">Senden am</label><input type="datetime-local" name="at"></div>
                        <div><label class="field">Wiederholen</label><select name="repeat"><option value="none">einmalig</option><option value="daily">täglich</option><option value="weekly">wöchentlich</option><option value="monthly">monatlich</option></select></div></div>
                </div>
                <div class="dc-actions">
                    <button class="btn btn-gold" id="sendBtn">📨 Jetzt senden</button>
                    <button type="button" class="btn" id="schedToggle">⏰ Planen …</button>
                    <select id="tplSel" aria-label="Vorlagen"><option value="">📁 Vorlagen …</option></select>
                    <button type="button" class="btn btn-sm" id="tplSave" title="Als Vorlage speichern (in diesem Browser)">💾</button>
                    <button type="button" class="btn btn-sm" id="cdBtn" title="Countdown als Nachricht einfügen">⏳ Countdown</button>
                </div>
            </form>
            <div>
                <div class="panel">
                    <div class="panel-title">👁 Vorschau</div>
                    <div class="dc-preview" id="preview"></div>
                </div>
                <div class="panel">
                    <div class="panel-title">📜 Letzte Nachrichten <span class="dc-ptools"><button type="button" class="btn btn-sm" id="threadNew" title="Neuer Thread in diesem Kanal">🧵</button><button type="button" class="btn btn-sm" id="purgeBtn" title="Nachrichten aufräumen">🧹</button><button type="button" class="btn btn-sm" id="msgReload">⟳</button></span></div>
                    <div id="msgBox" class="dc-msgs"><div class="dc-empty">Kanal auswählen.</div></div>
                    <button type="button" class="btn btn-sm" id="msgMore" hidden>Ältere laden</button>
                </div>
            </div>
        </div>
    </section>

    <section class="dc-pane" data-pane="members" hidden>
        <div class="dc-tools">
            <form id="memberSearch" class="dc-inline"><input type="search" name="q" placeholder="Mitglied suchen (Name beginnt mit …)" aria-label="Mitglied suchen"><button class="btn btn-sm btn-gold">Suchen</button></form>
            <select id="memberRole" aria-label="Rolle"><option value="">Alle Rollen</option></select>
            <label class="dc-check"><input type="checkbox" id="hideBots"> Bots ausblenden</label>
            <label class="dc-check"><input type="checkbox" id="onlyTimeout"> nur Timeouts</label>
            <span class="dc-small" id="memberCount"></span>
        </div>
        <div id="memberBox"><div class="dc-loading">Lade Mitglieder …</div></div>
        <button type="button" class="btn btn-sm" id="memberMore" hidden>Weitere laden</button>
    </section>

    <section class="dc-pane" data-pane="roles" hidden><div id="roleBox"><div class="dc-loading">Lade Rollen …</div></div></section>

    <section class="dc-pane" data-pane="channels" hidden>
        <div class="dc-cols">
            <div class="panel"><div class="panel-title">Kanäle</div><div id="chanBox"><div class="dc-loading">Lade …</div></div></div>
            <div>
                <form class="panel" id="chanCreate">
                    <div class="panel-title">＋ Kanal erstellen</div>
                    <div class="dc-row"><div><label class="field">Name</label><input type="text" name="name" maxlength="100" required placeholder="z. B. con-planung"></div>
                        <div><label class="field">Art</label><select name="type"><option value="0"># Text</option><option value="2">🔊 Sprache</option><option value="5">📢 Ankündigungen</option><option value="15">💬 Forum</option><option value="4">📁 Kategorie</option></select></div></div>
                    <label class="field">In Kategorie</label><select name="parent" class="dc-catsel"><option value="">– keine –</option></select>
                    <label class="field">Thema</label><input type="text" name="topic" maxlength="1024">
                    <button class="btn btn-gold" style="margin-top: 10px;">Erstellen</button>
                </form>
                <div class="panel"><div class="panel-title">🔗 Einladungen <button type="button" class="btn btn-sm" id="invReload">⟳</button></div><div id="invBox"><div class="dc-loading">Lade …</div></div></div>
                <div class="panel"><div class="panel-title">🧵 Aktive Threads <button type="button" class="btn btn-sm" id="thrReload">⟳</button></div><div id="thrBox"><div class="dc-loading">Lade …</div></div></div>
                <div class="panel"><div class="panel-title">🪝 Webhooks <button type="button" class="btn btn-sm" id="whReload">⟳</button></div><div id="whBox"><div class="dc-loading">Lade …</div></div>
                    <p class="dc-small">Webhooks erlauben anderen Diensten (z. B. GitHub, IFTTT, Twitch-Bots), in einen Kanal zu posten. Über 🔗 bei einem Kanal kannst du einen neuen anlegen.</p></div>
            </div>
        </div>
    </section>

    <section class="dc-pane" data-pane="events" hidden>
        <div class="dc-cols">
            <div class="panel"><div class="panel-title">📅 Server-Events</div><div id="evBox"><div class="dc-loading">Lade …</div></div></div>
            <div>
                <form class="panel" id="evCreate">
                    <div class="panel-title">＋ Event erstellen</div>
                    <label class="field">Name</label><input type="text" name="name" maxlength="100" required>
                    <div class="dc-row"><div><label class="field">Beginn</label><input type="datetime-local" name="start" required></div><div><label class="field">Ende</label><input type="datetime-local" name="end"></div></div>
                    <label class="field">Ort</label><input type="text" name="location" maxlength="100" placeholder="z. B. VRChat – Furry Hideout, oder Adresse">
                    <label class="field">Beschreibung</label><textarea name="description" rows="3" maxlength="1000"></textarea>
                    <button class="btn btn-gold" style="margin-top: 10px;">Auf Discord anlegen</button>
                </form>
                <div class="panel"><div class="panel-title">🗓 Aus deinem Kalender</div><div id="calBox"><div class="dc-empty">Lade …</div></div></div>
            </div>
        </div>
    </section>

    <section class="dc-pane" data-pane="mod" hidden>
        <div class="dc-cols">
            <div class="panel"><div class="panel-title">🔨 Banns</div><div id="banBox"><div class="dc-loading">Lade …</div></div></div>
            <div class="panel"><div class="panel-title">📋 Audit-Log</div><div id="auditBox"><div class="dc-loading">Lade …</div></div></div>
            <div class="panel"><div class="panel-title">🤖 AutoMod-Regeln</div><div id="amBox"><div class="dc-loading">Lade …</div></div></div>
            <form class="panel" id="amCreate">
                <div class="panel-title">＋ AutoMod-Regel</div>
                <label class="field">Name</label><input type="text" name="name" maxlength="100" placeholder="z. B. Schimpfwörter" required>
                <label class="field">Art</label>
                <select name="trigger" id="amTrigger"><option value="1">🔤 Bestimmte Wörter</option><option value="4">📕 Vorgefertigte Listen (Discord)</option><option value="5">📣 Zu viele Erwähnungen</option><option value="3">🗑 Verdächtiger Spam</option></select>
                <div data-am="1"><label class="field">Wörter <small>(eins pro Zeile oder mit Komma; * als Platzhalter, z. B. *spam*)</small></label><textarea name="keywords" rows="3"></textarea></div>
                <div data-am="4" hidden><label class="dc-check"><input type="checkbox" name="presets[]" value="1" checked> Beleidigungen</label><label class="dc-check"><input type="checkbox" name="presets[]" value="2" checked> Sexuelle Inhalte</label><label class="dc-check"><input type="checkbox" name="presets[]" value="3" checked> Hassrede</label></div>
                <div data-am="5" hidden><label class="field">Maximal erlaubte Erwähnungen pro Nachricht</label><input type="number" name="mention_limit" min="1" max="50" value="5"></div>
                <label class="field">Hinweis an die Person</label><input type="text" name="block_msg" maxlength="150" placeholder="Diese Nachricht wurde blockiert.">
                <div class="dc-row"><div><label class="field">Meldung in Kanal</label><select name="alert" class="dc-chsel"><option value="">– keine –</option></select></div>
                    <div><label class="field">Timeout</label><select name="timeout"><option value="0">kein Timeout</option><option value="1">1 Minute</option><option value="10">10 Minuten</option><option value="60">1 Stunde</option><option value="1440">1 Tag</option></select></div></div>
                <button class="btn btn-gold" style="margin-top: 10px;">Regel erstellen</button>
            </form>
        </div>
    </section>

    <section class="dc-pane" data-pane="commands" hidden>
        <div id="cmdSetup"><div class="dc-loading">Lade …</div></div>
        <div class="dc-cols">
            <div class="panel"><div class="panel-title">⚡ Eingebaute Befehle</div><div id="cmdBuiltins"></div></div>
            <div>
                <div class="panel"><div class="panel-title">✨ Eigene Befehle <button type="button" class="btn btn-sm btn-gold" id="cmdNew">＋ Neu</button></div><div id="cmdCustom"></div>
                    <p class="dc-small">Platzhalter: <code>{user}</code> erwähnt die Person, <code>{name}</code> ihr Name, <code>{text}</code> der Zusatz-Text, <code>{channel}</code>, <code>{date}</code>, <code>{time}</code>, <code>{random:A|B|C}</code> zufällige Auswahl.</p></div>
                <div class="panel"><div class="panel-title">🧪 Ausprobieren</div>
                    <form id="cmdTest" class="dc-inline" style="max-width: none;"><select name="name" id="cmdTestSel"></select><input type="text" name="opts" placeholder="Optionen, z. B. seiten=20 anzahl=3"><button class="btn btn-sm btn-gold">Testen</button></form>
                    <div id="cmdTestOut" class="dc-preview" style="margin-top: 10px;"></div></div>
                <div class="panel"><div class="panel-title">📜 Letzte Benutzungen</div><div id="cmdLog"></div></div>
            </div>
        </div>
    </section>

    <section class="dc-pane" data-pane="panels" hidden>
        <div class="dc-cols">
            <form class="panel" id="panelForm">
                <div class="panel-title" id="panelFormTitle">🎛 Rollen-Knöpfe erstellen</div>
                <input type="hidden" name="id" value="">
                <p class="dc-small">Mitglieder holen sich Rollen per Klick selbst – z. B. Spezies, Pronomen, Interessen, Ping-Rollen oder „Regeln akzeptiert“. Braucht die eingerichteten Slash-Befehle (Reiter „Befehle“), weil Discord die Klicks an deinen Server schickt.</p>
                <label class="field">Kanal</label><select name="channel" class="dc-chsel" required></select>
                <div class="dc-row"><div><label class="field">Überschrift</label><input type="text" name="title" maxlength="256" placeholder="🐾 Wähle deine Spezies"></div><div style="max-width: 110px;"><label class="field">Farbe</label><input type="color" name="color" value="#5865f2"></div></div>
                <label class="field">Text</label><textarea name="desc" rows="2" maxlength="2000" placeholder="Klick auf einen Knopf, um die Rolle zu bekommen."></textarea>
                <label class="field">Verhalten</label>
                <select name="mode"><option value="toggle">Mehrere Rollen – erneuter Klick nimmt die Rolle wieder weg</option><option value="unique">Nur eine Rolle aus dieser Gruppe (z. B. Pronomen)</option><option value="add">Nur hinzufügen (z. B. „Regeln akzeptiert“)</option></select>
                <label class="field">Knöpfe</label>
                <div id="panelRows"></div>
                <button type="button" class="btn btn-sm" id="panelAdd">＋ Knopf</button>
                <div class="dc-actions"><button class="btn btn-gold">📨 Posten / aktualisieren</button><button type="button" class="btn" id="panelReset" hidden>Abbrechen</button></div>
            </form>
            <div class="panel"><div class="panel-title">📋 Vorhandene Rollen-Knöpfe</div><div id="panelList"><div class="dc-loading">Lade …</div></div></div>
        </div>
    </section>

    <section class="dc-pane" data-pane="auto" hidden>
        <div class="dc-cols">
            <form class="panel" id="welcomeForm">
                <div class="panel-title">👋 Begrüßung, Auto-Rolle &amp; Abschied</div>
                <label class="dc-check"><input type="checkbox" name="on" value="1"> Neue Mitglieder begrüßen</label>
                <div class="dc-row"><div><label class="field">Kanal</label><select name="channel" class="dc-chsel"><option value="">– wählen –</option></select></div><div style="max-width: 110px;"><label class="field">Farbe</label><input type="color" name="color" value="#38d5e6"></div></div>
                <label class="field">Text <small>({user}, {name}, {server}, {count} = Mitgliederzahl)</small></label><textarea name="text" rows="3" maxlength="1500"></textarea>
                <label class="dc-check"><input type="checkbox" name="embed" value="1"> als Infobox mit Profilbild</label>
                <label class="field">Auto-Rolle für neue Mitglieder</label><select name="autorole" class="dc-rolesel"><option value="">– keine –</option></select>
                <label class="dc-check"><input type="checkbox" name="bye_on" value="1"> Melden, wenn jemand den Server verlässt</label>
                <div class="dc-row"><div><label class="field">Abschieds-Kanal</label><select name="bye_channel" class="dc-chsel"><option value="">– wählen –</option></select></div></div>
                <label class="field">Abschieds-Text</label><input type="text" name="bye_text" maxlength="1000">
                <div class="dc-actions"><button class="btn btn-gold">Speichern</button><button type="button" class="btn btn-sm" id="welcomeTest">🧪 Test senden</button><button type="button" class="btn btn-sm" id="welcomeRun">⟳ Jetzt prüfen</button></div>
                <p class="dc-small">Ohne Dauerbetrieb erkennt der Bot neue Mitglieder beim Cron-Aufruf (alle 5 Minuten, siehe unten) oder wenn du die Zentrale öffnest.</p>
            </form>
            <div>
                <div class="panel"><div class="panel-title">📡 Auto-Posts <button type="button" class="btn btn-sm btn-gold" id="apNew">＋ Neu</button></div><div id="apList"><div class="dc-loading">Lade …</div></div>
                    <p class="dc-small">Postet regelmäßig Bilder aus den Bilder-APIs (e926/e621, Danbooru, Rule34 …) oder neue Einträge eines RSS-Feeds. NSFW nur in altersbeschränkte Kanäle.
                       Die <a href="nsfw.php">🔞 NSFW-Galerie</a> zum Stöbern ist eine eigene Seite.</p></div>
                <div class="panel"><div class="panel-title">⏲ Cron</div><div id="autoCron" class="dc-small"></div></div>
            </div>
        </div>
    </section>

    <section class="dc-pane" data-pane="emojis" hidden>
        <div class="dc-cols">
            <div class="panel"><div class="panel-title">😀 Emojis <span class="dc-small" id="emojiCount"></span></div><input type="search" class="dc-filter" id="emojiFilter" placeholder="Emojis filtern …"><div id="emojiBox"><div class="dc-loading">Lade …</div></div></div>
            <div>
                <form class="panel" id="emojiUpload" enctype="multipart/form-data">
                    <div class="panel-title">⬆ Emoji hochladen</div>
                    <label class="field">Bild (PNG, JPG, GIF, WebP – max. 256 KB, ideal 128×128)</label><input type="file" name="file" accept="image/png,image/jpeg,image/gif,image/webp" required>
                    <label class="field">Name</label><input type="text" name="name" maxlength="32" pattern="[A-Za-z0-9_]{2,32}" placeholder="z. B. fuchs_wink" required>
                    <button class="btn btn-gold" style="margin-top: 10px;">Hochladen</button>
                </form>
                <div class="panel"><div class="panel-title">🏷 Sticker</div><div id="stickerBox"></div></div>
            </div>
        </div>
    </section>

    <section class="dc-pane" data-pane="scheduled" hidden>
        <div class="panel"><div class="panel-title">⏰ Geplante Nachrichten</div><div id="schedListBox"><div class="dc-loading">Lade …</div></div></div>
        <div class="panel">
            <div class="panel-title">Automatisch senden (Cron)</div>
            <p class="dc-small">Geplante Nachrichten werden verschickt, sobald jemand die Discord-Zentrale öffnet. Damit sie pünktlich rausgehen, lass diese Adresse alle 5 Minuten aufrufen –
               z. B. über die Cronjob-Funktion deines Hosters oder einen kostenlosen Dienst wie cron-job.org:</p>
            <div class="dc-cron"><code id="cronUrl">…</code><button type="button" class="btn btn-sm" id="cronCopy">⧉ Kopieren</button><button type="button" class="btn btn-sm btn-danger" id="cronReset">Neuer Schlüssel</button></div>
        </div>
    </section>

    <section class="dc-pane" data-pane="settings" hidden>
        <div class="dc-cols">
            <div class="panel">
                <div class="panel-title">🤖 Bot</div>
                <div id="botBox"></div>
                <p class="dc-small">Bot auf einen weiteren Server holen: Einladungslink öffnen und Server auswählen. Die Rechte sind so gewählt, dass alle Funktionen der Zentrale gehen.</p>
            </div>
            <form class="panel" id="settingsForm">
                <div class="panel-title">⚙ Standard</div>
                <label class="field">Standard-Kanal (für „In Discord teilen“ aus VRC Nexus usw.)</label>
                <select name="channel" class="dc-chsel"><option value="">– keiner –</option></select>
                <button class="btn btn-gold" style="margin-top: 10px;">Speichern</button>
            </form>
            <div class="panel">
                <div class="panel-title">🔑 Token</div>
                <form id="tokenForm"><label class="field">Neuen Token eintragen (ersetzt den alten)</label><input type="password" name="token" autocomplete="off" required><button class="btn btn-sm btn-gold" style="margin-top: 8px;">Ersetzen</button></form>
                <button type="button" class="btn btn-danger" id="removeBot" style="margin-top: 14px;">Bot-Verbindung entfernen</button>
            </div>
        </div>
    </section>

    <div class="pm" id="dcModal" hidden role="dialog" aria-modal="true"><div class="pm-box"><button type="button" class="pm-x" data-close aria-label="Schließen">&times;</button><div id="dcModalBody"></div></div></div>
<?php endif; ?>
</div>
<script>window.DC = { configured: <?php echo $configured ? 'true' : 'false'; ?>, calendarEdit: <?php echo can('calendar.edit') ? 'true' : 'false'; ?> };</script>
<script src="assets/js/discord.js"></script>
</body>
</html>
