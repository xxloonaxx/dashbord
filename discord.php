<?php
/**
 * Discord-Zentrale: Server über einen eigenen Bot verwalten – Übersicht, Nachrichten mit Embed-Baukasten,
 * Mitglieder & Moderation, Rollen, Kanäle & Einladungen, Events (verbunden mit dem Kalender), geplante Nachrichten.
 * Alle Aktionen laufen als JSON-Anfragen (assets/discord.js).
 */
require_once __DIR__ . '/inc/discord.php';

// Cron-Aufruf (ohne Anmeldung, nur mit geheimem Schlüssel): discord.php?cron=<schlüssel>
if (isset($_GET['cron'])) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!hash_equals(discord_cron_key(), (string)$_GET['cron'])) { http_response_code(403); exit("falscher Schlüssel\n"); }
    [$n, $errors] = discord_run_scheduled();
    echo "ok – $n Nachricht(en) gesendet\n" . ($errors ? implode("\n", $errors) . "\n" : '');
    exit;
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
                $guilds = discord_api('GET', '/users/@me/guilds');
                [$sent] = discord_run_scheduled();
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
                            'html' => format_text(preg_replace('/<(@[!&]?|#)(\d+)>/', '[$1$2]', (string)$m['content']), ['external' => true]),
                            'embeds' => array_map(function ($e) { return ['title' => $e['title'] ?? '', 'desc' => mb_substr((string)($e['description'] ?? ''), 0, 400), 'color' => discord_color((int)($e['color'] ?? 0))]; }, (array)($m['embeds'] ?? [])),
                            'files' => array_map(function ($a) { return ['name' => $a['filename'] ?? 'Datei', 'url' => $a['url'] ?? '', 'image' => strpos((string)($a['content_type'] ?? ''), 'image/') === 0]; }, (array)($m['attachments'] ?? [])),
                            'reactions' => array_map(function ($r) { return ($r['emoji']['name'] ?? '?') . ' ' . (int)$r['count']; }, (array)($m['reactions'] ?? []))];
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
                    require_once __DIR__ . '/inc/calendar.php';
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
                require_once __DIR__ . '/inc/calendar.php';
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
                        'cron' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok($_SERVER['REQUEST_URI'] ?? '/discord.php', '?') . '?cron=' . discord_cron_key()];
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
                require_once __DIR__ . '/inc/countdown.php';
                [$up] = sorted_countdowns(load_countdowns()['events']);
                $out = ['items' => array_map(function ($e) { return ['id' => $e['id'], 'title' => $e['title'], 'icon' => $e['icon'], 'ts' => $e['ts'], 'days' => (int)ceil(($e['ts'] - time()) / 86400), 'link' => $e['link'] ?? '', 'note' => $e['note'] ?? '']; }, $up)];
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
    <link rel="stylesheet" href="assets/hud.css">
    <link rel="stylesheet" href="assets/discord.css">
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
                        'events' => ['📅', 'Events'], 'mod' => ['🛡️', 'Moderation'], 'scheduled' => ['⏰', 'Geplant'], 'settings' => ['⚙', 'Einstellungen']] as $k => [$ic, $l]): ?>
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
                    <div class="panel-title">📜 Letzte Nachrichten <button type="button" class="btn btn-sm" id="msgReload">⟳</button></div>
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
<script src="assets/discord.js"></script>
</body>
</html>
