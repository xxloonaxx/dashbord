<?php
/**
 * VRC Nexus – VRChat-Freunde, Verlauf, Statistik, Welten, Avatare, Moderation & Gruppen im Dashboard.
 * Anmeldung mit VRChat-Benutzername/Passwort + 2FA; jeder Dashboard-Benutzer verbindet sein eigenes Konto.
 * Alle Aktionen laufen als JSON-Anfragen (assets/js/nexus.js).
 */
require_once __DIR__ . '/inc/modules/vrchat.php';
require_once __DIR__ . '/inc/modules/vrchat_favs.php';
require_once __DIR__ . '/inc/modules/nexus_client.php';
require_once __DIR__ . '/inc/modules/vrchat_extra.php';

// Handy-/Kalender-Abo der VRChat-Gruppen-Events: nexus.php?ics=<token> (ohne Anmeldung, nur mit geheimem Token)
if (isset($_GET['ics'])) {
    $st = site_db()->prepare("SELECT key FROM settings WHERE key LIKE 'vrc_ics_%' AND value = ?");
    $st->execute([(string)$_GET['ics']]);
    $key = $st->fetchColumn();
    if (!$key || strlen((string)$_GET['ics']) !== 32) { http_response_code(404); exit('Unbekannter Link'); }
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="vrchat-events.ics"');
    echo vrc_calendar_ics((int)substr($key, 8));
    exit;
}

auth_require_setup();
require_permission('vrcx.access');
$me = current_user();
$uid = (int)$me['id'];

if (isset($_GET['export']) && vrc_account($uid)) { vrc_export($uid, (string)$_GET['export']); exit; }
if (isset($_GET['client_export'])) { nc_export_csv($uid); exit; }
// Desktop-Client als ZIP (Quellcode + Start-/Build-Skripte; die fertige EXE baut build.bat bzw. GitHub Actions)
if (isset($_GET['client_download'])) {
    $dir = __DIR__ . '/clients/nexus-desktop';
    if (!class_exists('ZipArchive') || !is_dir($dir)) { http_response_code(404); exit('Client nicht gefunden.'); }
    $tmp = tempnam(sys_get_temp_dir(), 'ncz');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
        if (preg_match('#(^|/)(__pycache__|build|dist)/|\.pyc$|\.spec$#', $rel)) continue;
        $zip->addFile($file->getPathname(), 'GhostNodeNexus/' . $rel);
    }
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="GhostNodeNexus-Client.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}
// Bilder aus der VRChat-Galerie (brauchen die VRChat-Anmeldung) durchreichen
if (isset($_GET['img'])) {
    session_write_close();
    $acc = vrc_account($uid);
    if (!$acc || !preg_match('/^file_[0-9a-f\-]{36}$/i', (string)$_GET['img'])) { http_response_code(404); exit; }
    vrc_proxy_file($acc, (string)$_GET['img'], max(1, (int)($_GET['v'] ?? 1)));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    guard_post('vrcx.access');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $in = $_POST;
    $act = (string)($in['act'] ?? '');
    $id = (string)($in['id'] ?? '');
    $user = (string)($in['user'] ?? '');
    $valid = function (string $v, string $prefix) {
        // VRChat-Kennungen: usr_/grp_ + UUID; sehr alte Konten haben eine 10-stellige Kennung ohne Präfix
        if (!preg_match('/^' . $prefix . '_[0-9a-f\-]{8,40}$/i', $v) && !($prefix === 'usr' && preg_match('/^[0-9A-Za-z]{10}$/', $v))) throw new RuntimeException('Ungültige Kennung.');
        return $v;
    };
    $out = ['ok' => true];
    try {
        if ($act === 'login') {
            @session_start();
            $r = vrc_login(trim((string)($in['username'] ?? '')), (string)($in['password'] ?? ''));
            if (isset($r['need2fa'])) {
                $_SESSION['vrc_pending'] = ['cookies' => $r['cookies'], 'types' => $r['need2fa'], 'until' => time() + 600];
                $out = ['need2fa' => $r['need2fa']];
            } else {
                vrc_save_account($uid, $r['user'], $r['cookies']);
                audit('VRChat verbunden', $r['user']['displayName'] ?? '');
            }
        } elseif ($act === 'verify') {
            @session_start();
            $p = $_SESSION['vrc_pending'] ?? null;
            if (!$p || $p['until'] < time()) throw new RuntimeException('Die Anmeldung ist abgelaufen – bitte noch einmal Benutzername und Passwort eingeben.');
            $type = (string)($in['type'] ?? 'totp');
            $cookies = $p['cookies'];
            $u = vrc_verify_2fa($cookies, $type, (string)($in['code'] ?? ''));
            unset($_SESSION['vrc_pending']);
            vrc_save_account($uid, $u, $cookies);
            audit('VRChat verbunden', ($u['displayName'] ?? '') . ' (2FA: ' . $type . ')');
        } elseif ($act === 'cancel_login') {
            @session_start();
            unset($_SESSION['vrc_pending']);
        } elseif (strpos($act, 'client_') === 0) {
            // Desktop-Client – funktioniert auch ohne verbundenes VRChat-Web-Konto
            session_write_close();
            switch ($act) {
                case 'client_overview':
                    $out = ['tokens' => nc_tokens($uid), 'state' => nc_state($uid), 'stats' => nc_stats($uid), 'osc' => nc_osc_recent($uid), 'presets' => nc_presets($uid),
                            'kinds' => NC_KINDS, 'api' => rtrim(dirname((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['SCRIPT_NAME'] ?? '/nexus.php')), '/') . '/'];
                    break;
                case 'client_token_create':
                    $out = ['token' => nc_token_create($uid, (string)($in['name'] ?? '')), 'tokens' => nc_tokens($uid)];
                    audit('Nexus-Client: Gerät hinzugefügt', mb_substr((string)($in['name'] ?? ''), 0, 40), 'security');
                    break;
                case 'client_token_revoke':
                    nc_token_revoke($uid, (int)($in['token'] ?? 0));
                    audit('Nexus-Client: Gerät entfernt', '#' . (int)($in['token'] ?? 0), 'security');
                    $out = ['tokens' => nc_tokens($uid)];
                    break;
                case 'client_log':
                    $out = ['events' => nc_log_list($uid, ['kind' => (string)($in['kind'] ?? ''), 'q' => (string)($in['q'] ?? ''), 'before' => (int)($in['before'] ?? 0),
                                                           'player' => (string)($in['player'] ?? ''), 'day' => (string)($in['day'] ?? ''), 'limit' => 100])];
                    break;
                case 'client_players':
                    $out = ['players' => nc_players($uid, max(1, min(3650, (int)($in['days'] ?? 30))), trim((string)($in['q'] ?? '')))];
                    break;
                case 'client_visits':
                    $out = ['visits' => array_slice(nc_visits($uid, max(1, min(365, (int)($in['days'] ?? 7)))), 0, 200)];
                    break;
                case 'client_osc':
                    $nid = nc_osc_enqueue($uid, (string)($in['kind'] ?? ''), $in);
                    $out = ['id' => $nid, 'osc' => nc_osc_recent($uid)];
                    break;
                case 'client_osc_status':
                    $out = ['osc' => nc_osc_recent($uid), 'state' => nc_state($uid)];
                    break;
                case 'client_preset_save':
                    nc_preset_save($uid, (string)($in['label'] ?? ''), (string)($in['kind'] ?? ''), $in);
                    $out = ['presets' => nc_presets($uid)];
                    break;
                case 'client_preset_delete':
                    nc_preset_delete($uid, (int)($in['preset'] ?? 0));
                    $out = ['presets' => nc_presets($uid)];
                    break;
                case 'client_log_clear':
                    $n = nc_log_clear($uid);
                    audit('Nexus-Client: Spiel-Log gelöscht', $n . ' Einträge', 'warn');
                    $out = ['deleted' => $n];
                    break;
                default:
                    throw new RuntimeException('Unbekannte Aktion.');
            }
        } else {
            session_write_close();   // lange VRChat-Abfragen sollen andere Tabs nicht blockieren
            $acc = vrc_account($uid);
            if (!$acc) throw new VrcAuthError('Kein VRChat-Konto verbunden.');
            switch ($act) {
                case 'logout':
                    vrc_disconnect($uid, true);
                    audit('VRChat getrennt', $acc['display_name']);
                    break;
                case 'state':
                    $sync = ['synced' => false, 'wait' => 0];
                    $error = '';
                    if (!empty($in['refresh'])) {
                        try { $sync = vrc_sync($uid, !empty($in['force'])); }
                        catch (VrcAuthError $e) { throw $e; }
                        catch (Throwable $e) { $error = $e->getMessage(); vrc_db()->prepare('UPDATE accounts SET last_error = ? WHERE user_id = ?')->execute([$error, $uid]); }
                        $acc = vrc_account($uid);
                    }
                    $m = $acc['me'];
                    $myloc = vrc_location((string)($m['location'] ?? ''));
                    $out = ['me' => ['id' => $acc['vrc_id'], 'name' => $acc['display_name'], 'image' => $acc['avatar'], 'status' => $m['status'] ?? '', 'status_desc' => $m['statusDescription'] ?? '',
                                     'trust' => vrc_trust((array)($m['tags'] ?? [])), 'platform' => VRC_PLATFORM[$m['last_platform'] ?? ''] ?? '', 'pronouns' => $m['pronouns'] ?? '',
                                     'history' => array_slice((array)($m['statusHistory'] ?? []), 0, 6), 'location' => $myloc['kind'] === 'world' ? $myloc['location'] : '',
                                     'loc' => $myloc + (!empty($myloc['world']) ? ['world_name' => vrc_worlds([$myloc['world']])[$myloc['world']]['name'] ?? ''] : []),
                                     'bio' => $m['bio'] ?? '', 'links' => array_values((array)($m['bioLinks'] ?? [])), 'avatar' => $m['currentAvatar'] ?? ''],
                            'fav_groups' => json_decode($acc['fav_groups'] ?? '{}', true) ?: [],
                            'friends' => vrc_friend_list($uid), 'feed' => vrc_feed($uid, ['limit' => 30]),
                            'last_sync' => (int)$acc['last_sync'], 'connected_at' => (int)$acc['connected_at'], 'error' => $error ?: $acc['last_error'], 'sync' => $sync];
                    break;
                case 'feed':
                    $out = ['items' => vrc_feed($uid, ['type' => array_filter(explode(',', (string)($in['type'] ?? ''))), 'q' => mb_substr(trim((string)($in['q'] ?? '')), 0, 60),
                                                         'before' => (int)($in['before'] ?? 0), 'friend' => (string)($in['friend'] ?? ''), 'favorites' => !empty($in['favorites']), 'limit' => 100])];
                    break;
                case 'user':
                    $u = vrc_call($acc, 'GET', '/users/' . rawurlencode($valid($id, 'usr')));
                    $loc = vrc_location((string)($u['location'] ?? ''));
                    if (!empty($loc['world'])) { vrc_fetch_worlds($acc, [$loc['world']], 1); $loc += ['world_info' => vrc_worlds([$loc['world']])[$loc['world']] ?? null]; }
                    $groups = [];
                    try { $groups = vrc_call($acc, 'GET', '/users/' . rawurlencode($id) . '/groups'); } catch (VrcAuthError $e) { throw $e; } catch (Throwable $e) {}
                    $n = vrc_note($uid, $id);
                    $out = ['user' => [
                        'id' => $u['id'], 'name' => $u['displayName'] ?? '', 'image' => vrc_user_image($u), 'status' => $u['status'] ?? '', 'status_desc' => $u['statusDescription'] ?? '',
                        'bio_html' => format_text((string)($u['bio'] ?? ''), ['external' => true]), 'links' => array_values(array_filter((array)($u['bioLinks'] ?? []), function ($l) { return fmt_url((string)$l) !== '' && preg_match('#^https?://#', (string)$l); })),
                        'pronouns' => $u['pronouns'] ?? '', 'trust' => vrc_trust((array)($u['tags'] ?? [])), 'platform' => VRC_PLATFORM[$u['last_platform'] ?? ''] ?? ($u['last_platform'] ?? ''),
                        'is_friend' => !empty($u['isFriend']), 'joined' => $u['date_joined'] ?? '', 'last_login' => $u['last_login'] ?? '', 'last_activity' => $u['last_activity'] ?? '',
                        'loc' => $loc, 'note' => $n['note'], 'favorite' => (bool)$n['favorite'], 'stats' => vrc_friend_stats($uid, $id), 'lists' => (string)$n['tags'], 'watch' => (bool)$n['watch'],
                        'groups' => array_map(function ($g) { return ['id' => $g['groupId'] ?? '', 'name' => $g['name'] ?? '', 'icon' => $g['iconUrl'] ?? '', 'members' => (int)($g['memberCount'] ?? 0), 'rep' => !empty($g['isRepresenting'])]; }, is_array($groups) ? $groups : []),
                    ]];
                    break;
                case 'note_save':
                    vrc_note_save($uid, $valid($id, 'usr'), (string)($in['note'] ?? ''), !empty($in['favorite']), isset($in['lists']) ? (string)$in['lists'] : null, isset($in['watch']) ? !empty($in['watch']) : null);
                    if (!empty($in['remote'])) {   // zusätzlich als VRChat-Notiz (im Spiel sichtbar, nur für dich)
                        vrc_call($acc, 'POST', '/userNotes', [], ['targetUserId' => $id, 'note' => mb_substr((string)($in['note'] ?? ''), 0, 256)]);
                        $out = ['msg' => 'Notiz gespeichert – auch in VRChat.'];
                    }
                    break;
                case 'invite_self':
                    $loc = (string)($in['location'] ?? '');
                    if (!preg_match('/^wrld_[0-9a-f\-]{36}:[\w~()\-.:]+$/i', $loc)) throw new RuntimeException('Diese Instanz kann man nicht betreten.');
                    vrc_call($acc, 'POST', '/invite/myself/to/' . $loc);
                    $out = ['msg' => 'Einladung an dich selbst gesendet – im Spiel annehmen.'];
                    break;
                case 'request_invite':
                    vrc_call($acc, 'POST', '/requestInvite/' . rawurlencode($valid($id, 'usr')), [], new stdClass());
                    $out = ['msg' => 'Einladungs-Anfrage gesendet.'];
                    break;
                case 'friend_request':
                    vrc_call($acc, 'POST', '/user/' . rawurlencode($valid($id, 'usr')) . '/friendRequest');
                    audit('VRChat: Freundschaftsanfrage', $id);
                    $out = ['msg' => 'Freundschaftsanfrage gesendet.'];
                    break;
                case 'unfriend':
                    vrc_call($acc, 'DELETE', '/auth/user/friends/' . rawurlencode($valid($id, 'usr')));
                    audit('VRChat: Freundschaft beendet', $id, 'warn');
                    $out = ['msg' => 'Freundschaft beendet.'];
                    break;
                case 'search':
                    $q = trim((string)($in['q'] ?? ''));
                    if (mb_strlen($q) < 2) throw new RuntimeException('Bitte mindestens 2 Zeichen eingeben.');
                    $res = vrc_call($acc, 'GET', '/users', ['search' => $q, 'n' => 30]);
                    $out = ['users' => array_map(function ($u) { return ['id' => $u['id'], 'name' => $u['displayName'] ?? '', 'image' => vrc_user_image($u), 'trust' => vrc_trust((array)($u['tags'] ?? [])),
                                                                         'status' => $u['status'] ?? '', 'status_desc' => $u['statusDescription'] ?? '', 'is_friend' => !empty($u['isFriend'])]; }, is_array($res) ? $res : [])];
                    break;
                case 'notifications':
                    $errors = empty($in['cached']) ? vrc_notif_sync($uid, $acc) : [];
                    $out = vrc_notif_list($uid, ['view' => (string)($in['view'] ?? 'open'), 'type' => (string)($in['type'] ?? ''), 'q' => (string)($in['q'] ?? '')])
                         + ['labels' => VRC_NOTIF_TYPES, 'resp_labels' => VRC_NOTIF_RESPONSES, 'warn' => $errors];
                    break;
                case 'notif_act':
                    $out = ['msg' => vrc_notif_action($uid, $acc, $id, (string)($in['op'] ?? ''), (string)($in['rtype'] ?? ''))];
                    break;
                case 'notif_clear':
                    $out = ['msg' => vrc_notif_clear_history($uid) . ' alte Einträge aus dem Verlauf gelöscht.'];
                    break;
                case 'shop':
                    $out = vrc_shop_overview($acc);
                    break;
                case 'shop_browse':
                    $out = vrc_shop_browse($acc, (string)($in['target'] ?? ''));
                    break;
                case 'global_stats':
                    $out = vrc_global_stats(max(1, min(365, (int)($in['days'] ?? 1))));
                    $fr = vrc_db()->prepare("SELECT COUNT(*) AS n, SUM(CASE WHEN state = 'online' THEN 1 ELSE 0 END) AS online FROM friends WHERE user_id = ?");
                    $fr->execute([$uid]);
                    $out['friends'] = $fr->fetch();
                    break;
                case 'hot_worlds':
                    $out = ['worlds' => vrc_hot_worlds($acc)];
                    break;
                case 'notif_accept':
                    if (!preg_match('/^frq_[0-9a-f\-]{8,40}$|^not_[0-9a-f\-]{8,40}$/i', $id)) throw new RuntimeException('Ungültige Benachrichtigung.');
                    vrc_call($acc, 'PUT', '/auth/user/notifications/' . rawurlencode($id) . '/accept');
                    audit('VRChat: Freundschaftsanfrage angenommen');
                    $out = ['msg' => 'Angenommen.'];
                    break;
                case 'notif_hide':
                    if (!preg_match('/^(frq|not)_[0-9a-f\-]{8,40}$/i', $id)) throw new RuntimeException('Ungültige Benachrichtigung.');
                    vrc_call($acc, 'PUT', '/auth/user/notifications/' . rawurlencode($id) . '/hide');
                    break;
                case 'status':
                    $status = (string)($in['status'] ?? '');
                    if (!in_array($status, ['join me', 'active', 'ask me', 'busy'], true)) throw new RuntimeException('Ungültiger Status.');
                    vrc_call($acc, 'PUT', '/users/' . rawurlencode($acc['vrc_id']), [], ['status' => $status, 'statusDescription' => mb_substr(trim((string)($in['desc'] ?? '')), 0, 32)]);
                    vrc_db()->prepare('UPDATE accounts SET last_sync = 0 WHERE user_id = ?')->execute([$uid]);
                    $out = ['msg' => 'Status geändert.'];
                    break;

                // ----- Gruppen -----
                case 'groups':
                    $res = vrc_call($acc, 'GET', '/users/' . rawurlencode($acc['vrc_id']) . '/groups');
                    $mine = $acc['vrc_id'];
                    $gperms = vrc_group_perms($acc, (array)$res);
                    $out = ['groups' => array_map(function ($g) use ($mine, $gperms) { $p = ($g['ownerId'] ?? '') === $mine ? ['*'] : ($gperms[$g['groupId'] ?? ''] ?? []); return ['perms' => $p, 'mod' => vrc_can_moderate($p)] + ['id' => $g['groupId'] ?? '', 'name' => $g['name'] ?? '', 'short' => ($g['shortCode'] ?? '') . '.' . ($g['discriminator'] ?? ''),
                                                                          'icon' => $g['iconUrl'] ?? '', 'banner' => $g['bannerUrl'] ?? '', 'members' => (int)($g['memberCount'] ?? 0),
                                                                          'rep' => !empty($g['isRepresenting']), 'owner' => ($g['ownerId'] ?? '') === $mine]; }, is_array($res) ? $res : [])];
                    break;
                case 'group':
                    $g = vrc_call($acc, 'GET', '/groups/' . rawurlencode($valid($id, 'grp')), ['includeRoles' => 'true']);
                    $perms = (array)($g['myMember']['permissions'] ?? []);
                    $out = ['group' => ['id' => $g['id'], 'name' => $g['name'] ?? '', 'short' => ($g['shortCode'] ?? '') . '.' . ($g['discriminator'] ?? ''), 'icon' => $g['iconUrl'] ?? '', 'banner' => $g['bannerUrl'] ?? '',
                        'desc_html' => format_text((string)($g['description'] ?? ''), ['external' => true]), 'rules_html' => format_text((string)($g['rules'] ?? ''), ['external' => true]), 'members' => (int)($g['memberCount'] ?? 0),
                        'online' => (int)($g['onlineMemberCount'] ?? 0), 'privacy' => $g['privacy'] ?? '', 'join_state' => $g['joinState'] ?? '', 'perms' => $perms,
                        'owner' => ($g['ownerId'] ?? '') === $acc['vrc_id'], 'rep' => !empty($g['myMember']['isRepresenting']), 'member' => !empty($g['myMember']),
                        'roles' => array_map(function ($r) { return ['id' => $r['id'] ?? '', 'name' => $r['name'] ?? '']; }, (array)($g['roles'] ?? [])),
                        'links' => array_values(array_filter((array)($g['links'] ?? []), function ($l) { return preg_match('#^https?://#', (string)$l); }))]];
                    break;
                case 'group_members':
                case 'group_requests':
                case 'group_bans':
                    $path = ['group_members' => 'members', 'group_requests' => 'requests', 'group_bans' => 'bans'][$act];
                    $q = $act === 'group_members' ? ['n' => 50, 'offset' => max(0, (int)($in['offset'] ?? 0)), 'sort' => 'joinedAt:desc'] : ['n' => 100];
                    $res = vrc_call($acc, 'GET', '/groups/' . rawurlencode($valid($id, 'grp')) . '/' . $path, $q);
                    $out = ['items' => array_map(function ($m) {
                        $u = $m['user'] ?? [];
                        return ['id' => $m['userId'] ?? ($u['id'] ?? ''), 'name' => $u['displayName'] ?? '?', 'image' => vrc_user_image($u), 'joined' => $m['joinedAt'] ?? ($m['createdAt'] ?? ''),
                                'roles' => (array)($m['roleIds'] ?? []), 'rep' => !empty($m['isRepresenting']), 'banned' => $m['bannedAt'] ?? ''];
                    }, is_array($res) ? $res : [])];
                    break;
                case 'group_instances':
                    $res = vrc_call($acc, 'GET', '/groups/' . rawurlencode($valid($id, 'grp')) . '/instances');
                    $out = ['items' => array_map(function ($i) {
                        $loc = vrc_location((string)($i['location'] ?? (($i['world']['id'] ?? '') . ':' . ($i['instanceId'] ?? ''))));
                        return ['loc' => $loc, 'world' => $i['world']['name'] ?? '', 'thumb' => $i['world']['thumbnailImageUrl'] ?? '', 'users' => (int)($i['memberCount'] ?? ($i['n_users'] ?? 0)), 'capacity' => (int)($i['world']['capacity'] ?? 0)];
                    }, is_array($res) ? $res : [])];
                    break;
                case 'group_audit':
                    $res = vrc_call($acc, 'GET', '/groups/' . rawurlencode($valid($id, 'grp')) . '/auditLogs', ['n' => 60]);
                    $out = ['items' => array_map(function ($l) { return ['ts' => $l['created_at'] ?? '', 'actor' => $l['actorDisplayName'] ?? '', 'type' => $l['eventType'] ?? '', 'desc' => $l['description'] ?? '']; }, (array)($res['results'] ?? []))];
                    break;
                case 'group_request':
                    $action = ($in['decision'] ?? '') === 'accept' ? 'accept' : 'reject';
                    vrc_call($acc, 'PUT', '/groups/' . rawurlencode($valid($id, 'grp')) . '/requests/' . rawurlencode($valid($user, 'usr')), [], ['action' => $action, 'block' => !empty($in['block'])]);
                    audit('VRChat-Gruppe: Beitrittsanfrage ' . ($action === 'accept' ? 'angenommen' : 'abgelehnt'), $id . ' / ' . $user);
                    $out = ['msg' => $action === 'accept' ? 'Aufgenommen.' : 'Abgelehnt.'];
                    break;
                case 'group_invite':
                    vrc_call($acc, 'POST', '/groups/' . rawurlencode($valid($id, 'grp')) . '/invites', [], ['userId' => $valid($user, 'usr'), 'confirmOverrideBlock' => true]);
                    audit('VRChat-Gruppe: Einladung', $id . ' / ' . $user);
                    $out = ['msg' => 'Einladung verschickt.'];
                    break;
                case 'group_kick':
                    vrc_call($acc, 'DELETE', '/groups/' . rawurlencode($valid($id, 'grp')) . '/members/' . rawurlencode($valid($user, 'usr')));
                    audit('VRChat-Gruppe: Mitglied entfernt', $id . ' / ' . $user, 'warn');
                    $out = ['msg' => 'Mitglied entfernt.'];
                    break;
                case 'group_ban':
                    vrc_call($acc, 'POST', '/groups/' . rawurlencode($valid($id, 'grp')) . '/bans', [], ['userId' => $valid($user, 'usr')]);
                    audit('VRChat-Gruppe: Mitglied gebannt', $id . ' / ' . $user, 'warn');
                    $out = ['msg' => 'Gebannt.'];
                    break;
                case 'group_unban':
                    vrc_call($acc, 'DELETE', '/groups/' . rawurlencode($valid($id, 'grp')) . '/bans/' . rawurlencode($valid($user, 'usr')));
                    audit('VRChat-Gruppe: Bann aufgehoben', $id . ' / ' . $user);
                    $out = ['msg' => 'Bann aufgehoben.'];
                    break;
                case 'group_represent':
                    vrc_call($acc, 'PUT', '/groups/' . rawurlencode($valid($id, 'grp')) . '/representation', [], ['isRepresenting' => !empty($in['on'])]);
                    $out = ['msg' => !empty($in['on']) ? 'Du repräsentierst jetzt diese Gruppe.' : 'Repräsentation entfernt.'];
                    break;
                // ----- Gruppen-Moderation im Profil -----
                case 'user_mod':
                    $target = $valid($id, 'usr');
                    $groups = (array)vrc_call($acc, 'GET', '/users/' . rawurlencode($acc['vrc_id']) . '/groups');
                    $perms = vrc_group_perms($acc, $groups);
                    $items = [];
                    foreach ($groups as $g) {
                        $gid = (string)($g['groupId'] ?? '');
                        $p = ($g['ownerId'] ?? '') === $acc['vrc_id'] ? ['*'] : ($perms[$gid] ?? []);
                        if ($gid === '' || !vrc_can_moderate($p) || count($items) >= 15) continue;
                        $member = null;
                        try { $member = vrc_call($acc, 'GET', '/groups/' . rawurlencode($gid) . '/members/' . rawurlencode($target)); }
                        catch (VrcAuthError $e) { throw $e; } catch (Throwable $e) { $member = null; }
                        $roles = [];
                        try { $roles = array_map(function ($r) { return ['id' => $r['id'] ?? '', 'name' => $r['name'] ?? '']; }, (array)vrc_call($acc, 'GET', '/groups/' . rawurlencode($gid) . '/roles')); }
                        catch (VrcAuthError $e) { throw $e; } catch (Throwable $e) {}
                        $items[] = ['id' => $gid, 'name' => $g['name'] ?? '', 'icon' => $g['iconUrl'] ?? '', 'perms' => $p, 'roles' => $roles,
                                    'member' => $member && !empty($member['userId'] ?? ($member['id'] ?? '')) ? ['roles' => (array)($member['roleIds'] ?? []), 'joined' => $member['joinedAt'] ?? '',
                                                 'status' => $member['membershipStatus'] ?? 'member', 'rep' => !empty($member['isRepresenting'])] : null];
                    }
                    $out = ['groups' => $items];
                    break;

                // ----- VRChat-Kalender -----
                case 'calendar':
                    $last = (int)setting('vrc_cal_refresh_' . $uid, '0');
                    $err = '';
                    if (!empty($in['refresh']) || time() - $last > 900) {
                        try { vrc_calendar_refresh($acc); } catch (VrcAuthError $e) { throw $e; } catch (Throwable $e) { $err = $e->getMessage(); }
                    }
                    $groups = (array)vrc_call($acc, 'GET', '/users/' . rawurlencode($acc['vrc_id']) . '/groups');
                    $perms = vrc_group_perms($acc, $groups);
                    $can = [];
                    foreach ($groups as $g) {
                        $p = ($g['ownerId'] ?? '') === $acc['vrc_id'] ? ['*'] : ($perms[$g['groupId'] ?? ''] ?? []);
                        if (array_intersect($p, ['*', 'group-calendar-manage'])) $can[] = ['id' => $g['groupId'], 'name' => $g['name'] ?? ''];
                    }
                    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok($_SERVER['REQUEST_URI'] ?? '/nexus.php', '?');
                    $out = ['events' => array_map(function ($e) {
                        return ['id' => $e['id'], 'group' => $e['group_id'], 'group_name' => $e['group_name'], 'title' => $e['title'], 'html' => format_text($e['description'], ['external' => true]),
                                'start' => (int)$e['starts'], 'end' => (int)$e['ends'], 'category' => $e['category'], 'access' => $e['access'], 'image' => $e['image'], 'interested' => (int)$e['interested'],
                                'following' => (bool)$e['following'], 'platforms' => array_filter(explode(',', $e['platforms'])), 'languages' => array_filter(explode(',', $e['languages'])), 'tags' => array_filter(explode(',', $e['tags']))];
                    }, vrc_calendar_list($uid)), 'linked' => (bool)vrc_calendar_sub($uid, false), 'can_link' => can('calendar.edit') || can('calendar.subscriptions'),
                       'ics' => $base . '?ics=' . vrc_ics_token($uid), 'refreshed' => (int)setting('vrc_cal_refresh_' . $uid, '0'), 'error' => $err, 'can_create' => $can];
                    break;
                case 'calendar_link':
                    if (!can('calendar.edit') && !can('calendar.subscriptions')) throw new RuntimeException('Dafür brauchst du das Recht, den Kalender zu bearbeiten.');
                    if (!empty($in['on'])) { vrc_calendar_sub($uid, true); $r = vrc_calendar_push($uid); $out = ['msg' => 'Verbunden – ' . (int)($r['total'] ?? 0) . ' Events stehen jetzt im Dashboard-Kalender.']; }
                    else { $sid = vrc_calendar_sub($uid, false); if ($sid) cal_db()->prepare('DELETE FROM subscriptions WHERE id = ?')->execute([$sid]); $out = ['msg' => 'Verbindung zum Dashboard-Kalender getrennt.']; }
                    break;
                case 'ics_reset':
                    vrc_ics_token($uid, true);
                    $out = ['msg' => 'Neuer Abo-Link erstellt – der alte funktioniert nicht mehr.'];
                    break;
                case 'cal_follow':
                    vrc_call($acc, 'POST', '/calendar/' . rawurlencode($valid((string)($in['group'] ?? ''), 'grp')) . '/' . rawurlencode($valid($id, 'cal')) . '/follow', [], ['isFollowing' => !empty($in['on'])]);
                    vrc_db()->prepare('UPDATE vrc_events SET following = ? WHERE user_id = ? AND id = ?')->execute([!empty($in['on']) ? 1 : 0, $uid, $id]);
                    vrc_calendar_push($uid);
                    $out = ['msg' => !empty($in['on']) ? '⭐ Du bist interessiert.' : 'Nicht mehr interessiert.'];
                    break;
                case 'cal_create':
                    $g = $valid((string)($in['group'] ?? ''), 'grp');
                    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 64);
                    $start = strtotime((string)($in['start'] ?? ''));
                    $end = strtotime((string)($in['end'] ?? '')) ?: ($start + 7200);
                    if ($title === '' || !$start || $start < time()) throw new RuntimeException('Bitte Titel und einen Beginn in der Zukunft angeben.');
                    if ($end <= $start) $end = $start + 3600;
                    $cats = ['hangout', 'gaming', 'music', 'dance', 'performance', 'arts', 'education', 'exploration', 'film_media', 'avatars', 'wellness', 'roleplaying', 'other'];
                    vrc_call($acc, 'POST', '/calendar/' . rawurlencode($g) . '/event', [], ['title' => $title, 'description' => mb_substr(trim((string)($in['description'] ?? '')), 0, 2000),
                        'startsAt' => gmdate('Y-m-d\TH:i:s.000\Z', $start), 'endsAt' => gmdate('Y-m-d\TH:i:s.000\Z', $end), 'category' => in_array($in['category'] ?? '', $cats, true) ? $in['category'] : 'hangout',
                        'accessType' => ($in['access'] ?? '') === 'public' ? 'public' : 'group', 'sendCreationNotification' => !empty($in['notify']),
                        'platforms' => array_values(array_intersect((array)($in['platforms'] ?? []), ['standalonewindows', 'android', 'ios'])), 'languages' => ['deu'], 'tags' => []]);
                    audit('VRChat-Gruppen-Event erstellt', $title);
                    try { vrc_calendar_refresh($acc); } catch (Throwable $e) {}
                    $out = ['msg' => 'Event „' . $title . '“ im VRChat-Kalender angelegt.'];
                    break;
                case 'cal_delete':
                    vrc_call($acc, 'DELETE', '/calendar/' . rawurlencode($valid((string)($in['group'] ?? ''), 'grp')) . '/' . rawurlencode($valid($id, 'cal')));
                    vrc_db()->prepare('DELETE FROM vrc_events WHERE user_id = ? AND id = ?')->execute([$uid, $id]);
                    vrc_calendar_push($uid);
                    audit('VRChat-Gruppen-Event gelöscht', $id, 'warn');
                    $out = ['msg' => 'Event gelöscht.'];
                    break;

                // ----- Galerie: Fotos, Icons, Emojis, Sticker, Prints, Inventar -----
                case 'gallery':
                    $res = [];
                    foreach (['gallery' => 'photos', 'icon' => 'icons', 'emoji' => 'emojis', 'sticker' => 'stickers'] as $tag => $key) {
                        try {
                            $files = (array)vrc_call($acc, 'GET', '/files', ['tag' => $tag, 'n' => 100, 'offset' => 0]);
                            $res[$key] = array_values(array_map(function ($f) {
                                $v = vrc_file_version($f);
                                $anim = '';
                                foreach ((array)($f['tags'] ?? []) as $t) if (strpos($t, 'animationStyle_') === 0) $anim = substr($t, 15);
                                return ['id' => $f['id'] ?? '', 'name' => $f['name'] ?? '', 'v' => $v, 'img' => 'nexus.php?img=' . rawurlencode($f['id'] ?? '') . '&v=' . $v, 'anim' => $anim,
                                        'created' => $f['versions'][$v]['created_at'] ?? ($f['versions'][0]['created_at'] ?? '')];
                            }, array_filter($files, function ($f) { return is_array($f) && preg_match('/^file_[0-9a-f\-]{36}$/i', $f['id'] ?? ''); })));
                        } catch (VrcAuthError $e) { throw $e; } catch (Throwable $e) { $res[$key] = ['error' => $e->getMessage()]; }
                    }
                    try {
                        $prints = (array)vrc_call($acc, 'GET', '/prints/user/' . rawurlencode($acc['vrc_id']), ['n' => 100]);
                        $res['prints'] = array_values(array_map(function ($p) {
                            return ['id' => $p['id'] ?? '', 'img' => vrc_img_url((string)($p['files']['image'] ?? '')), 'note' => $p['note'] ?? '', 'world' => $p['worldName'] ?? '', 'world_id' => $p['worldId'] ?? '',
                                    'author' => $p['authorName'] ?? '', 'created' => $p['timestamp'] ?? ($p['createdAt'] ?? '')];
                        }, array_filter($prints, 'is_array')));
                    } catch (VrcAuthError $e) { throw $e; } catch (Throwable $e) { $res['prints'] = ['error' => $e->getMessage()]; }
                    $out = ['gallery' => $res, 'limits' => ['photos' => 64, 'icons' => 18, 'emojis' => 18, 'stickers' => 18, 'prints' => 64]];
                    break;
                case 'inventory':
                    $r = vrc_call($acc, 'GET', '/inventory', ['n' => 100, 'offset' => max(0, (int)($in['offset'] ?? 0)), 'order' => 'newest']);
                    $list = isset($r['data']) ? (array)$r['data'] : (array)$r;
                    $out = ['total' => (int)($r['totalCount'] ?? count($list)), 'items' => array_values(array_map(function ($i) {
                        return ['id' => $i['id'] ?? '', 'name' => $i['name'] ?? '', 'desc' => mb_substr((string)($i['description'] ?? ''), 0, 200), 'type' => $i['itemType'] ?? ($i['itemTypeLabel'] ?? ''),
                                'img' => vrc_img_url((string)($i['imageUrl'] ?? '')), 'created' => $i['created_at'] ?? '', 'archived' => !empty($i['isArchived'])];
                    }, array_filter($list, 'is_array')))];
                    break;
                case 'file_delete':
                    if (!preg_match('/^file_[0-9a-f\-]{36}$/i', $id)) throw new RuntimeException('Ungültige Datei.');
                    vrc_call($acc, 'DELETE', '/file/' . rawurlencode($id));
                    $out = ['msg' => 'Gelöscht.'];
                    break;
                case 'print_delete':
                    if (!preg_match('/^prnt_[0-9a-f\-]{36}$/i', $id)) throw new RuntimeException('Ungültiger Print.');
                    vrc_call($acc, 'DELETE', '/prints/' . rawurlencode($id));
                    $out = ['msg' => 'Print gelöscht.'];
                    break;
                case 'set_icon':
                    if (!preg_match('/^file_[0-9a-f\-]{36}$/i', $id)) throw new RuntimeException('Ungültige Datei.');
                    vrc_call($acc, 'PUT', '/users/' . rawurlencode($acc['vrc_id']), [], ['userIcon' => vrc_api_base() . '/file/' . $id . '/' . max(1, (int)($in['v'] ?? 1))]);
                    vrc_db()->prepare('UPDATE accounts SET last_sync = 0 WHERE user_id = ?')->execute([$uid]);
                    $out = ['msg' => 'Als Profil-Icon gesetzt.'];
                    break;
                case 'file_upload':
                    $tags = ['photos' => 'gallery', 'icons' => 'icon', 'emojis' => 'emoji', 'stickers' => 'sticker'];
                    $tag = $tags[$in['kind'] ?? ''] ?? null;
                    $f = $_FILES['file'] ?? null;
                    if (!$tag || !$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Bitte eine Bilddatei auswählen.');
                    if ($f['size'] > 10 * 1024 * 1024) throw new RuntimeException('Die Datei ist größer als 10 MB.');
                    $info = @getimagesize($f['tmp_name']);
                    $mime = $info['mime'] ?? '';
                    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) throw new RuntimeException('Bitte ein Bild (PNG, JPG, GIF oder WebP) wählen.');
                    $fields = ['tag' => $tag];
                    if ($tag === 'emoji') { $fields['animationStyle'] = preg_match('/^[a-z]{2,20}$/', (string)($in['anim'] ?? '')) ? $in['anim'] : 'none'; $fields['maskTag'] = 'square'; }
                    if ($tag === 'sticker') $fields['maskTag'] = 'square';
                    vrc_upload($acc, '/file/image', $fields, $f['tmp_name'], $mime, preg_replace('/[^\w.\-]/', '_', basename($f['name'])));
                    $out = ['msg' => 'Hochgeladen.'];
                    break;

                // ----- Info-Push (VRChat-Neuigkeiten) -----
                case 'infopush':
                    // VRChat verlangt die Filter „require“ und „include“ als Liste (wie der VRChat-Client: require=[]&include=[]).
                    // Ältere/andere Server-Versionen erwarten die Listen-Schreibweise require[]= – dann zweiter Versuch.
                    $r = [];
                    $variants = [['require' => '[]', 'include' => '[]'], ['require' => [''], 'include' => ['']], []];
                    foreach ($variants as $i => $q) {
                        try { $r = (array)vrc_call($acc, 'GET', '/infoPush', $q); break; }
                        catch (Throwable $e) { if ($i === count($variants) - 1 || stripos($e->getMessage(), 'require') === false && stripos($e->getMessage(), 'include') === false && strpos($e->getMessage(), '400') === false) throw $e; }
                    }
                    $out = ['items' => array_values(array_map(function ($i) {
                        $d = (array)($i['data'] ?? []);
                        $tags = implode(' ', (array)($i['tags'] ?? []));
                        $kind = preg_match('/loading/i', $tags) ? 'loading' : (preg_match('/banner|quickmenu|carousel/i', $tags) ? 'banner' : (preg_match('/help|article/i', $tags) || !empty($d['article']) ? 'help' : 'other'));
                        $press = (array)($d['onPressed'] ?? []);
                        $link = '';
                        foreach ((array)($press['parameters'] ?? []) as $p) if (is_string($p) && preg_match('#^https://#', $p)) $link = $p;
                        return ['id' => $i['id'] ?? '', 'kind' => $kind, 'title' => $d['name'] ?? ($i['name'] ?? ''), 'desc' => $d['description'] ?? '', 'img' => vrc_img_url((string)($d['imageUrl'] ?? '')),
                                'date' => $i['createdAt'] ?? ($i['startDate'] ?? ''), 'priority' => (int)($i['priority'] ?? 0), 'link' => $link,
                                'article' => format_text((string)($d['article']['content']['text'] ?? ''), ['external' => true]), 'article_img' => vrc_img_url((string)($d['article']['content']['imageUrl'] ?? ''))];
                    }, array_filter($r, 'is_array')))];
                    break;

                // ----- Direkt-Zugriff: Avatare, Mehrfach-Einladung, letzte Instanzen, Beobachten -----
                case 'avatar':
                    $a = vrc_call($acc, 'GET', '/avatars/' . rawurlencode($valid($id, 'avtr')));
                    vrc_history_add($uid, 'avatar', $a['id'], (string)($a['name'] ?? ''), (string)($a['thumbnailImageUrl'] ?? ''), (string)($a['authorName'] ?? ''));
                    $out = ['avatar' => ['id' => $a['id'], 'name' => $a['name'] ?? '', 'author' => $a['authorName'] ?? '', 'author_id' => $a['authorId'] ?? '', 'image' => $a['imageUrl'] ?? ($a['thumbnailImageUrl'] ?? ''),
                        'desc_html' => format_text((string)($a['description'] ?? ''), ['external' => true]), 'status' => $a['releaseStatus'] ?? '', 'created' => $a['created_at'] ?? '', 'updated' => $a['updated_at'] ?? '',
                        'version' => (int)($a['version'] ?? 0), 'featured' => !empty($a['featured']), 'current' => ($acc['me']['currentAvatar'] ?? '') === $a['id'],
                        'tags' => array_values(array_map(function ($t) { return preg_replace('/^(author_tag_|content_)/', '', $t); }, array_filter((array)($a['tags'] ?? []), function ($t) { return preg_match('/^(author_tag_|content_)/', $t); }))),
                        'platforms' => array_values(array_unique(array_map(function ($p) { return VRC_PLATFORM[$p['platform'] ?? ''] ?? ($p['platform'] ?? ''); }, (array)($a['unityPackages'] ?? [])))),
                        'performance' => array_values(array_filter(array_map(function ($p) { return isset($p['performanceRating']) ? (VRC_PLATFORM[$p['platform'] ?? ''] ?? '') . ': ' . $p['performanceRating'] : ''; }, (array)($a['unityPackages'] ?? []))))]];
                    break;
                // ----- Favoriten wie in VRCX: VRChat-Gruppen + lokale Gruppen + Verlauf -----
                case 'favs':
                    $kind = vrc_fav_kind((string)($in['kind'] ?? 'friend'));
                    $groups = vrc_fav_groups($acc, $kind);
                    $items = vrc_fav_remote($acc, $kind);
                    $count = array_count_values(array_column($items, 'group'));
                    $out = ['kind' => $kind, 'limit' => vrc_fav_limits($acc)[$kind], 'groups' => array_values(array_map(function ($g) use ($count) { return $g + ['count' => $count[$g['name']] ?? 0]; }, $groups)),
                            'items' => $items, 'local_groups' => vrc_local_groups($uid, $kind), 'local' => vrc_local_items($uid, $kind), 'history' => $kind === 'friend' ? [] : vrc_history($uid, $kind)];
                    break;
                case 'fav_status':
                    // Für die Auswahl in Profil-, Welt- und Avatar-Dialogen: wo steckt die ID schon drin?
                    $kind = vrc_fav_kind((string)($in['kind'] ?? ''));
                    $prefix = ['friend' => 'usr', 'world' => 'wrld', 'avatar' => 'avtr'][$kind];
                    $vid = $valid($id, $prefix);
                    $groups = vrc_fav_groups($acc, $kind);
                    $cur = null;
                    foreach (vrc_favorites_all($acc, $kind) as $f) if (($f['favoriteId'] ?? '') === $vid) $cur = ['group' => (string)(((array)($f['tags'] ?? []))[0] ?? ''), 'fav_id' => (string)$f['id']];
                    $out = ['groups' => array_values($groups), 'current' => $cur, 'local_groups' => vrc_local_groups($uid, $kind), 'local' => vrc_local_of($uid, $kind, $vid)];
                    break;
                case 'fav_set':
                    // target = „remote:<gruppe>“ (VRChat, ersetzt die bisherige Gruppe) oder „local:<gruppe>“ (zusätzlich)
                    $kind = vrc_fav_kind((string)($in['kind'] ?? ''));
                    $vid = $valid($id, ['friend' => 'usr', 'world' => 'wrld', 'avatar' => 'avtr'][$kind]);
                    $target = (string)($in['target'] ?? '');
                    if (strpos($target, 'remote:') === 0) {
                        $grp = substr($target, 7);
                        if (!preg_match('/^[\w\-]{1,40}$/', $grp)) throw new RuntimeException('Ungültige Gruppe.');
                        if (!empty($in['fav_id'])) { try { vrc_call($acc, 'DELETE', '/favorites/' . rawurlencode($valid((string)$in['fav_id'], 'fvrt'))); } catch (VrcAuthError $e) { throw $e; } catch (Throwable $e) {} }
                        elseif ($kind !== 'friend') { try { vrc_call($acc, 'DELETE', '/favorites/' . rawurlencode($vid)); } catch (VrcAuthError $e) { throw $e; } catch (Throwable $e) {} }
                        $r = vrc_call($acc, 'POST', '/favorites', [], ['type' => $kind, 'favoriteId' => $vid, 'tags' => [$grp]]);
                        if ($kind === 'friend') vrc_db()->prepare('UPDATE friends SET fav_group = ? WHERE user_id = ? AND vrc_id = ?')->execute([$grp, $uid, $vid]);
                        $out = ['msg' => 'In VRChat-Favoriten gespeichert.', 'fav_id' => $r['id'] ?? ''];
                    } elseif (strpos($target, 'local:') === 0) {
                        vrc_local_add($uid, $kind, substr($target, 6), ['id' => $vid, 'name' => (string)($in['name'] ?? ''), 'image' => (string)($in['image'] ?? ''), 'sub' => (string)($in['sub'] ?? '')]);
                        if (!empty($in['from_local']) && $in['from_local'] !== substr($target, 6)) vrc_local_remove($uid, $kind, (string)$in['from_local'], $vid);
                        $out = ['msg' => 'Lokal gemerkt in „' . substr($target, 6) . '“.'];
                    } else throw new RuntimeException('Bitte eine Liste wählen.');
                    break;
                case 'fav_remove':
                    $kind = vrc_fav_kind((string)($in['kind'] ?? ''));
                    $vid = $valid($id, ['friend' => 'usr', 'world' => 'wrld', 'avatar' => 'avtr'][$kind]);
                    if (isset($in['local'])) { vrc_local_remove($uid, $kind, (string)$in['local'], $vid); $out = ['msg' => 'Aus der lokalen Liste entfernt.']; break; }
                    vrc_call($acc, 'DELETE', '/favorites/' . rawurlencode(!empty($in['fav_id']) ? $valid((string)$in['fav_id'], 'fvrt') : $vid));
                    if ($kind === 'friend') vrc_db()->prepare("UPDATE friends SET fav_group = '' WHERE user_id = ? AND vrc_id = ?")->execute([$uid, $vid]);
                    $out = ['msg' => 'Aus den VRChat-Favoriten entfernt.'];
                    break;
                case 'local_group':
                    $kind = vrc_fav_kind((string)($in['kind'] ?? ''));
                    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 40);
                    $old = (string)($in['old'] ?? '');
                    $op = (string)($in['op'] ?? 'create');
                    $db = vrc_db();
                    if ($op !== 'delete' && $name === '') throw new RuntimeException('Bitte einen Namen eingeben.');
                    if ($op === 'create') vrc_local_group_add($uid, $kind, $name);
                    if ($op === 'rename') { $db->prepare('UPDATE local_fav_groups SET name = ? WHERE user_id = ? AND kind = ? AND name = ?')->execute([$name, $uid, $kind, $old]); $db->prepare('UPDATE OR IGNORE local_favs SET grp = ? WHERE user_id = ? AND kind = ? AND grp = ?')->execute([$name, $uid, $kind, $old]); $db->prepare('DELETE FROM local_favs WHERE user_id = ? AND kind = ? AND grp = ?')->execute([$uid, $kind, $old]); }
                    if ($op === 'delete') { $db->prepare('DELETE FROM local_fav_groups WHERE user_id = ? AND kind = ? AND name = ?')->execute([$uid, $kind, $old]); $db->prepare('DELETE FROM local_favs WHERE user_id = ? AND kind = ? AND grp = ?')->execute([$uid, $kind, $old]); }
                    $out = ['msg' => ['create' => 'Gruppe angelegt.', 'rename' => 'Umbenannt.', 'delete' => 'Gruppe gelöscht.'][$op] ?? 'OK'];
                    break;
                case 'fav_group_rename':
                    $kind = vrc_fav_kind((string)($in['kind'] ?? ''));
                    $grp = (string)($in['group'] ?? '');
                    if (!preg_match('/^[\w\-]{1,40}$/', $grp)) throw new RuntimeException('Ungültige Gruppe.');
                    $body = [];
                    if (isset($in['name'])) $body['displayName'] = mb_substr(trim((string)$in['name']), 0, 30);
                    if (in_array($in['visibility'] ?? '', ['private', 'friends', 'public'], true)) $body['visibility'] = $in['visibility'];
                    vrc_call($acc, 'PUT', '/favorite/group/' . $kind . '/' . rawurlencode($grp) . '/' . rawurlencode((string)$acc['vrc_id']), [], $body);
                    $out = ['msg' => 'Gruppe gespeichert.'];
                    break;
                case 'history_clear':
                    vrc_db()->prepare('DELETE FROM vrc_history WHERE user_id = ? AND kind = ?')->execute([$uid, vrc_fav_kind((string)($in['kind'] ?? ''))]);
                    $out = ['msg' => 'Verlauf geleert.'];
                    break;

                case 'avatar_fav':
                    if (!empty($in['move'])) {   // in eine andere Favoritengruppe verschieben
                        $grp = preg_match('/^avatars\d{1,2}$/', (string)($in['group'] ?? '')) ? $in['group'] : 'avatars1';
                        try { vrc_call($acc, 'DELETE', '/favorites/' . rawurlencode(!empty($in['fav_id']) ? $valid((string)$in['fav_id'], 'fvrt') : $valid($id, 'avtr'))); } catch (VrcAuthError $e) { throw $e; } catch (Throwable $e) {}
                        vrc_call($acc, 'POST', '/favorites', [], ['type' => 'avatar', 'favoriteId' => $valid($id, 'avtr'), 'tags' => [$grp]]);
                        $out = ['msg' => 'Verschoben.'];
                        break;
                    }
                    if (!empty($in['on'])) {
                        vrc_call($acc, 'POST', '/favorites', [], ['type' => 'avatar', 'favoriteId' => $valid($id, 'avtr'), 'tags' => [preg_match('/^avatars\d{1,2}$/', (string)($in['group'] ?? '')) ? $in['group'] : 'avatars1']]);
                        $out = ['msg' => 'Avatar favorisiert.'];
                    } else {
                        vrc_call($acc, 'DELETE', '/favorites/' . rawurlencode($valid($id, 'avtr')));
                        $out = ['msg' => 'Aus den Favoriten entfernt.'];
                    }
                    break;
                case 'invite_many':
                    $loc = (string)($in['location'] ?? '') ?: (string)($acc['me']['location'] ?? '');
                    if (!preg_match('/^wrld_[0-9a-f\-]{36}:[\w~()\-.:]+$/i', $loc)) throw new RuntimeException('Du bist gerade in keiner Instanz, in die man einladen kann.');
                    $ids = array_slice(array_unique((array)($in['ids'] ?? [])), 0, 25);
                    $ok = 0; $failed = [];
                    foreach ($ids as $k => $fid) {
                        try { vrc_call($acc, 'POST', '/invite/' . rawurlencode($valid((string)$fid, 'usr')), [], ['instanceId' => $loc, 'messageSlot' => 0]); $ok++; }
                        catch (VrcAuthError $e) { throw $e; }
                        catch (Throwable $e) { $failed[] = $fid; }
                        if ($k < count($ids) - 1) usleep(350000);   // sanft, damit VRChat nicht bremst
                    }
                    $out = ['msg' => $ok . ' Einladung(en) verschickt' . ($failed ? ', ' . count($failed) . ' fehlgeschlagen' : '') . '.'];
                    break;
                case 'recent_instances':
                    $out = ['items' => vrc_recent_instances($uid)];
                    break;
                case 'watch':
                    $n = vrc_note($uid, $valid($id, 'usr'));
                    vrc_note_save($uid, $id, (string)$n['note'], (bool)$n['favorite'], null, !empty($in['on']));
                    $out = ['msg' => !empty($in['on']) ? 'Du bekommst eine Benachrichtigung, sobald die Person online kommt.' : 'Beobachtung beendet.'];
                    break;
                case 'group_share':
                    if (!can('discord.manage')) throw new RuntimeException('Dafür brauchst du Zugriff auf die Discord-Zentrale.');
                    require_once __DIR__ . '/inc/modules/discord.php';
                    $g = vrc_call($acc, 'GET', '/groups/' . rawurlencode($valid($id, 'grp')));
                    $url = 'https://vrc.group/' . rawurlencode((string)($g['shortCode'] ?? '') . '.' . (string)($g['discriminator'] ?? ''));
                    discord_send_default(['content' => mb_substr(trim((string)($in['text'] ?? '')), 0, 500), 'embeds' => [array_filter([
                        'title' => '👥 ' . ($g['name'] ?? 'Gruppe'), 'url' => !empty($g['shortCode']) ? $url : 'https://vrchat.com/home/group/' . rawurlencode($g['id']),
                        'description' => mb_substr((string)($g['description'] ?? ''), 0, 600), 'color' => 0xf3c262,
                        'thumbnail' => !empty($g['iconUrl']) ? ['url' => $g['iconUrl']] : null, 'image' => !empty($g['bannerUrl']) ? ['url' => $g['bannerUrl']] : null,
                        'fields' => [['name' => 'Mitglieder', 'value' => (string)(int)($g['memberCount'] ?? 0), 'inline' => true], ['name' => 'Beitritt', 'value' => (string)($g['joinState'] ?? '–'), 'inline' => true]],
                        'footer' => ['text' => 'geteilt von ' . $acc['display_name'] . ' über VRC Nexus']])]]);
                    $out = ['msg' => 'Gruppe in Discord geteilt.'];
                    break;
                case 'discord_share':
                    if (!can('discord.manage')) throw new RuntimeException('Dafür brauchst du Zugriff auf die Discord-Zentrale.');
                    require_once __DIR__ . '/inc/modules/discord.php';
                    $loc = (string)($in['location'] ?? '');
                    if (!preg_match('/^(wrld_[0-9a-f\-]{36}):([\w~()\-.:]+)$/i', $loc, $m)) throw new RuntimeException('Ungültige Instanz.');
                    $l = vrc_location($loc);
                    vrc_fetch_worlds($acc, [$m[1]], 1);
                    $w = vrc_worlds([$m[1]])[$m[1]] ?? ['name' => 'Welt', 'thumb' => ''];
                    $link = 'https://vrchat.com/home/launch?worldId=' . rawurlencode($m[1]) . '&instanceId=' . rawurlencode($m[2]);
                    discord_send_default(['content' => mb_substr(trim((string)($in['text'] ?? '')), 0, 500), 'embeds' => [[
                        'title' => '🥽 ' . $w['name'], 'url' => $link, 'description' => $l['access'] . ' · Region ' . $l['region'] . "\n[▶ Beitreten](" . $link . ')',
                        'color' => 0x38d5e6, 'thumbnail' => $w['thumb'] ? ['url' => $w['thumb']] : null, 'footer' => ['text' => 'geteilt von ' . $acc['display_name'] . ' über VRC Nexus']]]]);
                    audit('Instanz in Discord geteilt', $w['name']);
                    $out = ['msg' => 'In Discord geteilt.'];
                    break;

                // ----- Statistik -----
                case 'stats':
                    $out = vrc_stats($uid);
                    break;
                case 'encounters':
                    $out = ['items' => array_slice(vrc_encounters($uid, 30), 0, 40)];
                    break;
                case 'timeline':
                    $out = vrc_timeline($uid, (int)($in['day'] ?? 0));
                    break;

                // ----- Welten & Instanzen -----
                case 'worlds':
                    $kind = (string)($in['kind'] ?? 'favorites');
                    $q = ['n' => 60];
                    switch ($kind) {
                        case 'favorites': $path = '/worlds/favorites'; break;
                        case 'recent': $path = '/worlds/recent'; break;
                        case 'active': $path = '/worlds/active'; break;
                        case 'mine': $path = '/worlds'; $q += ['user' => 'me', 'releaseStatus' => 'all', 'sort' => 'updated', 'order' => 'descending']; break;
                        case 'search':
                            $term = trim((string)($in['q'] ?? ''));
                            if (mb_strlen($term) < 2) throw new RuntimeException('Bitte mindestens 2 Zeichen eingeben.');
                            $path = '/worlds'; $q += ['search' => $term, 'sort' => 'relevance'];
                            break;
                        default: throw new RuntimeException('Unbekannte Liste.');
                    }
                    $res = vrc_call($acc, 'GET', $path, $q);
                    $ins = vrc_db()->prepare('INSERT OR REPLACE INTO worlds (world_id, name, thumb, author, capacity, fetched) VALUES (?, ?, ?, ?, ?, ?)');
                    $out = ['worlds' => array_values(array_map(function ($w) use ($ins) {
                        if (!empty($w['id'])) $ins->execute([$w['id'], $w['name'] ?? '?', $w['thumbnailImageUrl'] ?? '', $w['authorName'] ?? '', (int)($w['capacity'] ?? 0), time()]);
                        return ['id' => $w['id'] ?? '', 'name' => $w['name'] ?? '', 'author' => $w['authorName'] ?? '', 'thumb' => $w['thumbnailImageUrl'] ?? '', 'occupants' => (int)($w['occupants'] ?? 0),
                                'capacity' => (int)($w['capacity'] ?? 0), 'favorites' => (int)($w['favorites'] ?? 0), 'visits' => (int)($w['visits'] ?? 0), 'fav_id' => $w['favoriteId'] ?? '',
                                'status' => $w['releaseStatus'] ?? '', 'updated' => $w['updated_at'] ?? ''];
                    }, is_array($res) ? array_filter($res, 'is_array') : []))];
                    break;
                case 'world':
                    $w = vrc_call($acc, 'GET', '/worlds/' . rawurlencode($valid($id, 'wrld')));
                    vrc_history_add($uid, 'world', $w['id'], (string)($w['name'] ?? ''), (string)($w['thumbnailImageUrl'] ?? ''), (string)($w['authorName'] ?? ''));
                    vrc_db()->prepare('INSERT OR REPLACE INTO worlds (world_id, name, thumb, author, capacity, fetched) VALUES (?, ?, ?, ?, ?, ?)')
                            ->execute([$w['id'], $w['name'] ?? '?', $w['thumbnailImageUrl'] ?? '', $w['authorName'] ?? '', (int)($w['capacity'] ?? 0), time()]);
                    $instances = [];
                    foreach ((array)($w['instances'] ?? []) as $i) {
                        if (!is_array($i) || !isset($i[0])) continue;
                        $loc = vrc_location($w['id'] . ':' . $i[0]);
                        $instances[] = ['loc' => $loc, 'users' => (int)($i[1] ?? 0)];
                    }
                    $platforms = array_values(array_unique(array_map(function ($p) { return VRC_PLATFORM[$p['platform'] ?? ''] ?? ($p['platform'] ?? ''); }, (array)($w['unityPackages'] ?? []))));
                    $out = ['world' => ['id' => $w['id'], 'name' => $w['name'] ?? '', 'author' => $w['authorName'] ?? '', 'author_id' => $w['authorId'] ?? '', 'image' => $w['imageUrl'] ?? ($w['thumbnailImageUrl'] ?? ''),
                        'desc_html' => format_text((string)($w['description'] ?? ''), ['external' => true]), 'capacity' => (int)($w['capacity'] ?? 0), 'recommended' => (int)($w['recommendedCapacity'] ?? 0),
                        'visits' => (int)($w['visits'] ?? 0), 'favorites' => (int)($w['favorites'] ?? 0), 'popularity' => (int)($w['popularity'] ?? 0), 'heat' => (int)($w['heat'] ?? 0),
                        'occupants' => (int)($w['occupants'] ?? 0), 'public' => (int)($w['publicOccupants'] ?? 0), 'private' => (int)($w['privateOccupants'] ?? 0),
                        'tags' => array_values(array_map(function ($t) { return preg_replace('/^author_tag_/', '', $t); }, array_filter((array)($w['tags'] ?? []), function ($t) { return strpos($t, 'author_tag_') === 0; }))),
                        'created' => $w['created_at'] ?? '', 'updated' => $w['updated_at'] ?? '', 'status' => $w['releaseStatus'] ?? '', 'platforms' => $platforms, 'instances' => $instances]];
                    break;
                case 'world_fav':
                    if (!empty($in['on'])) {
                        $r = vrc_call($acc, 'POST', '/favorites', [], ['type' => 'world', 'favoriteId' => $valid($id, 'wrld'), 'tags' => [preg_match('/^worlds\d{1,2}$/', (string)($in['group'] ?? '')) ? $in['group'] : 'worlds1']]);
                        $out = ['msg' => 'Zu den Favoriten hinzugefügt.', 'fav_id' => $r['id'] ?? ''];
                    } else {
                        $fav = (string)($in['fav_id'] ?? '');
                        vrc_call($acc, 'DELETE', '/favorites/' . rawurlencode($fav !== '' ? $valid($fav, 'fvrt') : $valid($id, 'wrld')));
                        $out = ['msg' => 'Aus den Favoriten entfernt.'];
                    }
                    break;
                case 'instance':
                    $loc = (string)($in['location'] ?? '');
                    if (!preg_match('/^wrld_[0-9a-f\-]{36}:[\w~()\-.:]+$/i', $loc)) throw new RuntimeException('Ungültige Instanz.');
                    $i = vrc_call($acc, 'GET', '/instances/' . $loc);
                    $pf = (array)($i['platforms'] ?? []);
                    $friends_here = array_values(array_filter(vrc_friend_list($uid), function ($f) use ($loc) { return ($f['loc']['location'] ?? '') === $loc; }));
                    $out = ['instance' => ['location' => $loc, 'loc' => vrc_location($loc), 'users' => (int)($i['userCount'] ?? ($i['n_users'] ?? 0)), 'capacity' => (int)($i['capacity'] ?? 0),
                        'pc' => (int)($pf['standalonewindows'] ?? 0), 'quest' => (int)($pf['android'] ?? 0), 'ios' => (int)($pf['ios'] ?? 0), 'full' => !empty($i['full']),
                        'queue' => (int)($i['queueSize'] ?? 0), 'closed' => !empty($i['closedAt']), 'name' => $i['displayName'] ?? ($i['name'] ?? ''),
                        'friends' => array_map(function ($f) { return ['id' => $f['id'], 'name' => $f['name'], 'image' => $f['image']]; }, $friends_here)]];
                    break;
                case 'instance_create':
                    $world = $valid((string)($in['world'] ?? ''), 'wrld');
                    $type = (string)($in['type'] ?? 'hidden');
                    $region = in_array($in['region'] ?? '', ['us', 'use', 'eu', 'jp'], true) ? $in['region'] : 'eu';
                    $body = ['worldId' => $world, 'region' => $region];
                    switch ($type) {
                        case 'public': $body['type'] = 'public'; break;
                        case 'friends+': $body += ['type' => 'hidden', 'ownerId' => $acc['vrc_id']]; break;
                        case 'friends': $body += ['type' => 'friends', 'ownerId' => $acc['vrc_id']]; break;
                        case 'invite+': $body += ['type' => 'private', 'ownerId' => $acc['vrc_id'], 'canRequestInvite' => true]; break;
                        case 'invite': $body += ['type' => 'private', 'ownerId' => $acc['vrc_id']]; break;
                        case 'group':
                            $body += ['type' => 'group', 'ownerId' => $valid((string)($in['group'] ?? ''), 'grp'),
                                      'groupAccessType' => in_array($in['access'] ?? '', ['members', 'plus', 'public'], true) ? $in['access'] : 'members'];
                            break;
                        default: throw new RuntimeException('Unbekannter Instanz-Typ.');
                    }
                    $i = vrc_call($acc, 'POST', '/instances', [], $body);
                    $loc = (string)($i['location'] ?? ($i['id'] ?? ''));
                    if ($loc === '' && !empty($i['instanceId'])) $loc = $world . ':' . $i['instanceId'];
                    if (!preg_match('/^wrld_[0-9a-f\-]{36}:[\w~()\-.:]+$/i', $loc)) throw new RuntimeException('VRChat hat keine gültige Instanz zurückgegeben.');
                    if (!empty($in['invite_me'])) vrc_call($acc, 'POST', '/invite/myself/to/' . $loc);
                    $out = ['msg' => 'Instanz erstellt' . (!empty($in['invite_me']) ? ' – Einladung an dich ist unterwegs.' : '.'), 'location' => $loc];
                    break;
                case 'invite':
                    $loc = (string)($in['location'] ?? '');
                    if ($loc === '') $loc = (string)($acc['me']['location'] ?? '');
                    if (!preg_match('/^wrld_[0-9a-f\-]{36}:[\w~()\-.:]+$/i', $loc)) throw new RuntimeException('Du bist gerade in keiner Instanz, in die man einladen kann.');
                    vrc_call($acc, 'POST', '/invite/' . rawurlencode($valid($id, 'usr')), [], ['instanceId' => $loc, 'messageSlot' => max(0, min(11, (int)($in['slot'] ?? 0)))]);
                    $out = ['msg' => 'Einladung verschickt.'];
                    break;

                // ----- Avatare -----
                case 'avatars':
                    $kind = (string)($in['kind'] ?? 'mine');
                    $res = $kind === 'favorites'
                        ? vrc_call($acc, 'GET', '/avatars/favorites', ['n' => 100])
                        : vrc_call($acc, 'GET', '/avatars', ['user' => 'me', 'releaseStatus' => 'all', 'n' => 100, 'sort' => 'updated', 'order' => 'descending']);
                    $current = (string)($acc['me']['currentAvatar'] ?? '');
                    $fgroup = []; $fnames = [];
                    if ($kind === 'favorites') {
                        try {
                            foreach (vrc_favorites_all($acc, 'avatar') as $f) if (!empty($f['favoriteId'])) $fgroup[$f['favoriteId']] = [(string)(((array)($f['tags'] ?? []))[0] ?? 'avatars1'), (string)($f['id'] ?? '')];
                            foreach ((array)vrc_call($acc, 'GET', '/favorite/groups', ['type' => 'avatar', 'n' => 50]) as $g) if (!empty($g['name'])) $fnames[$g['name']] = (string)($g['displayName'] ?? $g['name']);
                        } catch (VrcAuthError $e) { throw $e; } catch (Throwable $e) {}
                    }
                    $out = ['current' => $current, 'fav_groups' => $fnames ?: new stdClass(), 'avatars' => array_values(array_map(function ($a) use ($current, $fgroup) {
                        return ['fav_group' => $fgroup[$a['id'] ?? ''][0] ?? '', 'fav_id' => $fgroup[$a['id'] ?? ''][1] ?? '', 'created' => $a['created_at'] ?? '',
                                'id' => $a['id'] ?? '', 'name' => $a['name'] ?? '', 'author' => $a['authorName'] ?? '', 'thumb' => $a['thumbnailImageUrl'] ?? '', 'status' => $a['releaseStatus'] ?? '',
                                'desc' => mb_substr((string)($a['description'] ?? ''), 0, 300), 'updated' => $a['updated_at'] ?? '', 'current' => ($a['id'] ?? '') === $current,
                                'platforms' => array_values(array_unique(array_map(function ($p) { return VRC_PLATFORM[$p['platform'] ?? ''] ?? ($p['platform'] ?? ''); }, (array)($a['unityPackages'] ?? []))))];
                    }, is_array($res) ? array_filter($res, 'is_array') : []))];
                    break;
                case 'avatar_db':
                    $out = ['avatars' => vrc_avatar_db_search((int)($in['provider'] ?? 0), (string)($in['q'] ?? '')), 'current' => (string)($acc['me']['currentAvatar'] ?? '')];
                    foreach ($out['avatars'] as &$av) $av['current'] = $av['id'] === $out['current'];
                    unset($av);
                    break;
                case 'avatar_providers':
                    if (isset($in['save'])) {
                        if (!can('system.manage')) throw new RuntimeException('Nur Admins (System) dürfen Provider ändern.');
                        vrc_avatar_providers_save((string)($in['list'] ?? ''));
                        audit('Nexus: Avatar-Provider geändert');
                    }
                    $out = ['providers' => vrc_avatar_providers(), 'can_edit' => can('system.manage')];
                    break;
                case 'avatar_select':
                    $u = vrc_call($acc, 'PUT', '/avatars/' . rawurlencode($valid($id, 'avtr')) . '/select');
                    if (!empty($u['id'])) vrc_db()->prepare('UPDATE accounts SET me_json = ?, avatar = ? WHERE user_id = ?')->execute([json_encode(vrc_me_slim($u), JSON_UNESCAPED_UNICODE), vrc_user_image($u), $uid]);
                    $out = ['msg' => 'Avatar gewechselt.'];
                    break;

                // ----- Moderation (Blockieren, Stummschalten …) -----
                case 'moderations':
                    $res = vrc_call($acc, 'GET', '/auth/user/playermoderations');
                    $out = ['items' => array_values(array_map(function ($m) {
                        return ['id' => $m['id'] ?? '', 'type' => $m['type'] ?? '', 'user' => $m['targetUserId'] ?? '', 'name' => $m['targetDisplayName'] ?? '?', 'created' => $m['created'] ?? ''];
                    }, is_array($res) ? array_filter($res, 'is_array') : []))];
                    break;
                case 'moderate':
                case 'unmoderate':
                    $type = (string)($in['type'] ?? '');
                    if (!in_array($type, ['block', 'mute', 'unmute', 'hideAvatar', 'showAvatar', 'interactOff', 'interactOn', 'muteChat'], true)) throw new RuntimeException('Unbekannte Moderation.');
                    $body = ['moderated' => $valid($user, 'usr'), 'type' => $type];
                    if ($act === 'moderate') vrc_call($acc, 'POST', '/auth/user/playermoderations', [], $body);
                    else vrc_call($acc, 'PUT', '/auth/user/unplayermoderate', [], $body);
                    audit('VRChat: ' . ($act === 'moderate' ? 'Moderation' : 'Moderation aufgehoben') . ' (' . $type . ')', $user, $type === 'block' ? 'warn' : 'info');
                    $out = ['msg' => $act === 'moderate' ? 'Erledigt.' : 'Aufgehoben.'];
                    break;

                // ----- Eigenes Profil -----
                case 'profile_save':
                    $links = array_values(array_filter(array_map('trim', (array)($in['links'] ?? [])), function ($l) { return $l !== '' && preg_match('#^https?://\S+$#i', $l); }));
                    $body = ['bio' => mb_substr((string)($in['bio'] ?? ''), 0, 512), 'pronouns' => mb_substr(trim((string)($in['pronouns'] ?? '')), 0, 32), 'bioLinks' => array_slice($links, 0, 3)];
                    $u = vrc_call($acc, 'PUT', '/users/' . rawurlencode($acc['vrc_id']), [], $body);
                    if (!empty($u['id'])) vrc_db()->prepare('UPDATE accounts SET me_json = ? WHERE user_id = ?')->execute([json_encode(vrc_me_slim($u), JSON_UNESCAPED_UNICODE), $uid]);
                    $out = ['msg' => 'Profil gespeichert.'];
                    break;

                // ----- Gruppen: Beiträge, Beitreten, Suche, Rollen -----
                case 'group_posts':
                    $res = vrc_call($acc, 'GET', '/groups/' . rawurlencode($valid($id, 'grp')) . '/posts', ['n' => 20, 'offset' => 0]);
                    $list = isset($res['posts']) ? (array)$res['posts'] : (is_array($res) ? $res : []);
                    $out = ['items' => array_values(array_map(function ($p) {
                        return ['id' => $p['id'] ?? '', 'title' => $p['title'] ?? '', 'html' => format_text((string)($p['text'] ?? ''), ['external' => true]), 'created' => $p['createdAt'] ?? ($p['created_at'] ?? ''),
                                'visibility' => $p['visibility'] ?? '', 'image' => $p['imageUrl'] ?? ''];
                    }, array_filter($list, 'is_array')))];
                    break;
                case 'group_post':
                    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 120);
                    $text = mb_substr(trim((string)($in['text'] ?? '')), 0, 2000);
                    if ($title === '' || $text === '') throw new RuntimeException('Bitte Titel und Text eingeben.');
                    vrc_call($acc, 'POST', '/groups/' . rawurlencode($valid($id, 'grp')) . '/posts', [], ['title' => $title, 'text' => $text,
                        'visibility' => ($in['visibility'] ?? '') === 'public' ? 'public' : 'group', 'sendNotification' => !empty($in['notify']), 'roleIds' => []]);
                    audit('VRChat-Gruppe: Beitrag veröffentlicht', $id . ' / ' . $title);
                    $out = ['msg' => 'Beitrag veröffentlicht.'];
                    break;
                case 'group_post_delete':
                    vrc_call($acc, 'DELETE', '/groups/' . rawurlencode($valid($id, 'grp')) . '/posts/' . rawurlencode($valid((string)($in['post'] ?? ''), '[a-z]{3,5}')));
                    $out = ['msg' => 'Beitrag gelöscht.'];
                    break;
                case 'group_join':
                    vrc_call($acc, 'POST', '/groups/' . rawurlencode($valid($id, 'grp')) . '/join');
                    $out = ['msg' => 'Beitritt angefragt bzw. beigetreten.'];
                    break;
                case 'group_leave':
                    vrc_call($acc, 'POST', '/groups/' . rawurlencode($valid($id, 'grp')) . '/leave');
                    audit('VRChat-Gruppe verlassen', $id);
                    $out = ['msg' => 'Gruppe verlassen.'];
                    break;
                case 'group_search':
                    $term = trim((string)($in['q'] ?? ''));
                    if (mb_strlen($term) < 2) throw new RuntimeException('Bitte mindestens 2 Zeichen eingeben.');
                    $res = vrc_call($acc, 'GET', '/groups', ['query' => $term, 'n' => 30]);
                    $out = ['groups' => array_values(array_map(function ($g) {
                        return ['id' => $g['id'] ?? '', 'name' => $g['name'] ?? '', 'short' => ($g['shortCode'] ?? '') . '.' . ($g['discriminator'] ?? ''), 'icon' => $g['iconUrl'] ?? '', 'members' => (int)($g['memberCount'] ?? 0)];
                    }, is_array($res) ? array_filter($res, 'is_array') : []))];
                    break;
                case 'group_role':
                    $role = $valid((string)($in['role'] ?? ''), 'grol');
                    vrc_call($acc, !empty($in['on']) ? 'PUT' : 'DELETE', '/groups/' . rawurlencode($valid($id, 'grp')) . '/members/' . rawurlencode($valid($user, 'usr')) . '/roles/' . rawurlencode($role));
                    audit('VRChat-Gruppe: Rolle ' . (!empty($in['on']) ? 'vergeben' : 'entzogen'), $id . ' / ' . $user . ' / ' . $role);
                    $out = ['msg' => !empty($in['on']) ? 'Rolle vergeben.' : 'Rolle entzogen.'];
                    break;
                case 'settings':
                    if (!can('system.manage')) throw new RuntimeException('Nur mit dem Recht „System“.');
                    $ua = trim((string)($in['user_agent'] ?? ''));
                    if ($ua !== '' && !preg_match('#^[\w.\-]+/[\w.\-]+( [\x20-\x7E]{0,120})?$#', $ua)) throw new RuntimeException('Format: Name/Version Kontakt, z. B. MeinDashboard/1.0 kontakt@example.org');
                    set_setting('vrc_user_agent', $ua);
                    $out = ['msg' => 'Gespeichert.'];
                    break;
                default:
                    throw new RuntimeException('Unbekannte Aktion.');
            }
        }
    } catch (VrcAuthError $e) {
        http_response_code(401);
        $out = ['error' => $e->getMessage(), 'auth' => true];
    } catch (Throwable $e) {
        http_response_code(400);
        $out = ['error' => $e->getMessage()];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$acc = vrc_account($uid);
@session_start();
$pending = !$acc && !empty($_SESSION['vrc_pending']) && $_SESSION['vrc_pending']['until'] > time() ? $_SESSION['vrc_pending']['types'] : null;
session_write_close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VRC Nexus</title>
    <?php echo emoji_favicon('🥽'); ?>
    <link rel="stylesheet" href="assets/css/hud.css">
    <link rel="stylesheet" href="assets/css/nexus.css">
</head>
<body>
<?php auth_corner(); ?>
<div class="wrapper" style="max-width: 1320px;">
    <div class="nav-bar">
        <a href="index.php" class="nav-back">&larr; ZURÜCK ZUM INDEX</a>
    </div>
    <header class="vx-head">
        <div>
            <div class="hud-tag">VRChat // Freunde · Welten · Avatare · Gruppen</div>
            <h1>VRC Nexus</h1>
        </div>
        <?php if ($acc): ?>
            <div class="vx-me" id="me"></div>
        <?php endif; ?>
    </header>

<?php if (!$acc): ?>
    <div class="vx-login panel">
        <div class="panel-title">VRChat-Konto verbinden</div>
        <form id="loginForm" <?php echo $pending ? 'hidden' : ''; ?>>
            <label class="field" for="vxU">VRChat-Benutzername oder E-Mail</label>
            <input type="text" id="vxU" name="username" autocomplete="username" required>
            <label class="field" for="vxP" style="margin-top: 12px;">Passwort</label>
            <input type="password" id="vxP" name="password" autocomplete="current-password" required>
            <button type="submit" class="btn btn-gold" style="margin-top: 16px; width: 100%;">Anmelden</button>
        </form>
        <form id="tfaForm" <?php echo $pending ? '' : 'hidden'; ?> data-types="<?php echo e(implode(',', $pending ?? [])); ?>">
            <p class="vx-tfa-info" id="tfaInfo">Zwei-Faktor-Code eingeben.</p>
            <div class="vx-tfa-types" id="tfaTypes"></div>
            <input type="text" id="vxCode" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" placeholder="123456" required>
            <button type="submit" class="btn btn-gold" style="margin-top: 14px; width: 100%;">Bestätigen</button>
            <button type="button" class="btn btn-sm" id="tfaCancel" style="margin-top: 10px;">Abbrechen</button>
        </form>
        <div class="vx-error" id="loginErr" hidden></div>
        <ul class="vx-notes">
            <li>🔒 Dein Passwort wird <b>nicht gespeichert</b> – es geht nur einmal direkt an VRChat. Gespeichert wird nur das Sitzungs-Cookie, verschlüsselt.</li>
            <li>🔑 Zwei-Faktor-Schutz (Authenticator-App, E-Mail-Code oder Wiederherstellungscode) wird unterstützt.</li>
            <li>⚠ Die VRChat-API ist inoffiziell. VRC Nexus fragt bewusst sparsam ab (höchstens alle 45 s) und führt nichts automatisch aus.</li>
        </ul>
    </div>
    <details class="panel nc-standalone" id="clientStandalone">
        <summary class="panel-title" style="cursor: pointer;">🖥️ Desktop-Client &amp; OSC – funktioniert auch ohne VRChat-Web-Anmeldung</summary>
        <div id="clientPane" class="nc"></div>
    </details>
<?php else: ?>
    <nav class="vx-tabs" id="tabs" role="tablist">
        <button type="button" class="vx-tabbtn active" data-tab="friends" role="tab"><span>👥</span>Freunde</button>
        <button type="button" class="vx-tabbtn" data-tab="favs" role="tab"><span>⭐</span>Favoriten</button>
        <button type="button" class="vx-tabbtn" data-tab="feed" role="tab"><span>📜</span>Verlauf</button>
        <button type="button" class="vx-tabbtn" data-tab="stats" role="tab"><span>📈</span>Statistik</button>
        <button type="button" class="vx-tabbtn" data-tab="worlds" role="tab"><span>🌐</span>Welten</button>
        <button type="button" class="vx-tabbtn" data-tab="avatars" role="tab"><span>🎭</span>Avatare</button>
        <button type="button" class="vx-tabbtn" data-tab="groups" role="tab"><span>🛡️</span>Gruppen</button>
        <button type="button" class="vx-tabbtn" data-tab="calendar" role="tab"><span>📅</span>Kalender</button>
        <button type="button" class="vx-tabbtn" data-tab="gallery" role="tab"><span>🖼️</span>Galerie</button>
        <button type="button" class="vx-tabbtn" data-tab="notifs" role="tab"><span>🔔</span>Benachrichtigungen</button>
        <button type="button" class="vx-tabbtn" data-tab="shop" role="tab"><span>🛒</span>Shop</button>
        <button type="button" class="vx-tabbtn" data-tab="global" role="tab"><span>🌍</span>VRChat</button>
        <button type="button" class="vx-tabbtn" data-tab="moderation" role="tab"><span>🚫</span>Moderation</button>
        <button type="button" class="vx-tabbtn" data-tab="news" role="tab"><span>📰</span>Info-Push</button>
        <button type="button" class="vx-tabbtn" data-tab="search" role="tab"><span>🔍</span>Suche</button>
        <button type="button" class="vx-tabbtn" data-tab="client" role="tab"><span>🖥️</span>Client</button>
        <button type="button" class="vx-tabbtn" data-tab="account" role="tab"><span>⚙</span>Konto</button>
    </nav>
    <div class="vx-bar" id="syncBar">
        <span id="syncInfo">Lade …</span>
        <svg class="vx-spark" id="spark" viewBox="0 0 120 26" preserveAspectRatio="none" aria-hidden="true"></svg>
        <span class="vx-bar-right">
            <label class="vx-auto"><input type="checkbox" id="autoRefresh" checked> automatisch</label>
            <button type="button" class="btn btn-sm btn-gold" id="clipBtn" title="ID oder Link aus der Zwischenablage öffnen (oder einfach Strg+V)">📋 Direkt-Zugriff</button>
            <button type="button" class="btn btn-sm" id="palBtn" title="Befehlspalette (Strg+K)">⌘ Strg+K</button>
            <button type="button" class="btn btn-sm" id="refreshBtn" title="Jetzt mit VRChat abgleichen (Taste R)">⟳ Aktualisieren</button>
        </span>
    </div>
    <div class="vx-myloc" id="myLoc" hidden></div>
    <div class="vx-error" id="globalErr" hidden></div>

    <section class="vx-tab" data-pane="friends">
        <div class="vx-tools">
            <input type="search" id="friendSearch" placeholder="Freunde suchen (Name, Welt, Status, Notiz) …  Taste /" aria-label="Freunde suchen">
            <select id="friendSort" aria-label="Sortierung">
                <option value="state">Nach Status</option><option value="name">Name A–Z</option><option value="since">Zuletzt geändert</option>
                <option value="trust">Vertrauensstufe</option><option value="world">Nach Welt</option><option value="friend_since">Neueste Freunde</option>
            </select>
            <select id="friendPlatform" aria-label="Plattform"><option value="">Alle Plattformen</option><option value="standalonewindows">💻 PC</option><option value="android">📱 Quest / Android</option><option value="ios">🍏 iOS</option><option value="web">🌐 Website / App</option></select>
            <select id="friendTrust" aria-label="Vertrauensstufe"><option value="">Alle Stufen</option></select>
            <label class="vx-auto"><input type="checkbox" id="showOffline"> Offline zeigen</label>
            <label class="vx-auto"><input type="checkbox" id="onlyNotes"> nur mit Notiz</label>
            <label class="vx-auto"><input type="checkbox" id="compactView"> kompakt</label>
            <a class="btn btn-sm" href="nexus.php?export=friends" title="Freundesliste als CSV (Excel)">⬇ CSV</a>
        </div>
        <div class="chips vx-favchips" id="favChips"></div>
        <div id="friendsBox"></div>
    </section>

    <section class="vx-tab" data-pane="feed" hidden>
        <div class="vx-tools">
            <input type="search" id="feedSearch" placeholder="Verlauf durchsuchen …" aria-label="Verlauf durchsuchen">
            <select id="feedType" aria-label="Art">
                <option value="">Alle Ereignisse</option><option value="online,offline,active">Online / Offline</option><option value="gps">Ortswechsel</option>
                <option value="status">Status</option><option value="avatar">Avatar</option><option value="friend_add,friend_remove">Freunde +/−</option>
                <option value="name,bio,trust">Name / Bio / Vertrauen</option><option value="self">🧭 Meine Orte</option>
            </select>
            <label class="vx-auto"><input type="checkbox" id="feedFav"> nur ⭐ Favoriten</label>
            <a class="btn btn-sm" href="nexus.php?export=feed" title="Kompletten Verlauf als CSV">⬇ CSV</a>
        </div>
        <div class="vx-feed" id="feedBox"></div>
        <button type="button" class="btn btn-sm" id="feedMore" hidden>Ältere laden</button>
    </section>

    <section class="vx-tab" data-pane="stats" hidden>
        <div id="statsBox"><div class="vx-loading">Lade Statistik …</div></div>
    </section>

    <section class="vx-tab" data-pane="worlds" hidden>
        <div class="vx-tools">
            <div class="chips" id="worldKinds">
                <button type="button" class="chip on" data-kind="favorites">⭐ Favoriten</button><button type="button" class="chip" data-kind="recent">🕘 Zuletzt besucht</button>
                <button type="button" class="chip" data-kind="active">🔥 Gerade beliebt</button><button type="button" class="chip" data-kind="mine">🛠 Meine Welten</button>
                <button type="button" class="chip" data-kind="friends">👥 Wo Freunde sind</button>
                <button type="button" class="chip" data-kind="recentinst">🧭 Meine letzten Instanzen</button><button type="button" class="chip" data-kind="bookmarks">🔖 Gemerkt</button>
                <button type="button" class="chip" data-kind="roulette">🎲 Welt-Roulette</button>
            </div>
            <form id="worldSearch" class="vx-inline"><input type="search" name="q" placeholder="Welten suchen …" minlength="2" aria-label="Welten suchen"><button class="btn btn-sm btn-gold">Suchen</button></form>
        </div>
        <div class="vx-cards" id="worldsBox"></div>
    </section>

    <section class="vx-tab" data-pane="avatars" hidden>
        <div class="vx-tools">
            <div class="chips" id="avatarKinds"><button type="button" class="chip on" data-kind="mine">🎭 Meine Avatare</button><button type="button" class="chip" data-kind="favorites">⭐ Favoriten</button><button type="button" class="chip" data-kind="search">🔎 Avatar-Suche</button></div>
            <input type="search" id="avatarSearch" placeholder="Avatare filtern (Name, Autor) …" aria-label="Avatare filtern">
            <select id="avatarSort" aria-label="Sortierung">
                <option value="group">Nach Favoritengruppe</option><option value="updated">Zuletzt aktualisiert</option><option value="created">Neueste zuerst</option>
                <option value="name">Name A–Z</option><option value="author">Nach Autor</option><option value="platform">PC + Quest zuerst</option><option value="vrchat">Reihenfolge wie in VRChat</option>
            </select>
            <select id="avatarPf" aria-label="Plattform"><option value="">Alle Plattformen</option><option value="cross">💻+📱 PC &amp; Quest</option><option value="PC">💻 PC</option><option value="Quest / Android">📱 Quest</option></select>
            <button type="button" class="btn btn-sm" id="avatarRandom" title="Zufälligen Avatar aus der (gefilterten) Liste anziehen">🎲 Zufall</button>
            <span class="vx-small" id="avatarCount"></span>
        </div>
        <form class="vx-bar avdb-bar" id="avDbForm" hidden>
            <input type="search" name="q" placeholder="Avatar suchen – Name, Stichwort, Autor-ID (usr_…) oder Avatar-ID" required minlength="2">
            <select name="provider" id="avDbProv" aria-label="Datenbank"></select>
            <button class="btn btn-sm btn-gold">🔎 Suchen</button>
            <button type="button" class="btn btn-sm" id="avDbEdit" hidden title="Provider verwalten">⚙</button>
        </form>
        <div id="avatarsBox"></div>
    </section>

    <section class="vx-tab" data-pane="calendar" hidden>
        <div class="vx-tools">
            <select id="calGroup" aria-label="Gruppe"><option value="">Alle Gruppen</option></select>
            <select id="calCat" aria-label="Kategorie"><option value="">Alle Kategorien</option><option value="hangout">💬 Treffen</option><option value="gaming">🎮 Gaming</option><option value="music">🎵 Musik</option>
                <option value="dance">💃 Tanz</option><option value="performance">🎭 Auftritt</option><option value="arts">🎨 Kunst</option><option value="education">📚 Bildung</option><option value="exploration">🧭 Erkunden</option>
                <option value="film_media">🎬 Film &amp; Medien</option><option value="avatars">🦊 Avatare</option><option value="wellness">🧘 Wohlfühlen</option><option value="roleplaying">🐉 Rollenspiel</option><option value="other">📦 Sonstiges</option></select>
            <label class="vx-auto"><input type="checkbox" id="calFav"> nur ⭐ interessiert</label>
            <button type="button" class="btn btn-sm" id="calRefresh">⟳ Neu laden</button>
            <span class="vx-small" id="calInfo"></span>
        </div>
        <div class="vx-cols vx-calcols">
            <div id="calList"></div>
            <div>
                <div class="panel"><div class="panel-title">🔄 Synchronisieren</div><div id="calSync"></div></div>
                <form class="panel" id="calCreate" hidden>
                    <div class="panel-title">＋ Event im VRChat-Kalender</div>
                    <label class="field">Gruppe</label><select name="group"></select>
                    <label class="field">Titel</label><input type="text" name="title" maxlength="64" required>
                    <div class="vx-tools" style="margin: 0;"><div style="flex: 1;"><label class="field">Beginn</label><input type="datetime-local" name="start" required></div><div style="flex: 1;"><label class="field">Ende</label><input type="datetime-local" name="end"></div></div>
                    <label class="field">Kategorie</label><select name="category"><option value="hangout">💬 Treffen</option><option value="gaming">🎮 Gaming</option><option value="music">🎵 Musik</option><option value="dance">💃 Tanz</option>
                        <option value="performance">🎭 Auftritt</option><option value="arts">🎨 Kunst</option><option value="education">📚 Bildung</option><option value="exploration">🧭 Erkunden</option><option value="film_media">🎬 Film &amp; Medien</option>
                        <option value="avatars">🦊 Avatare</option><option value="wellness">🧘 Wohlfühlen</option><option value="roleplaying">🐉 Rollenspiel</option><option value="other">📦 Sonstiges</option></select>
                    <label class="field">Sichtbarkeit</label><select name="access"><option value="group">🔒 Nur Gruppe</option><option value="public">🌍 Öffentlich</option></select>
                    <label class="field">Plattformen</label>
                    <div class="chips"><label class="chip"><input type="checkbox" name="pf" value="standalonewindows" checked> PC</label><label class="chip"><input type="checkbox" name="pf" value="android" checked> Quest</label><label class="chip"><input type="checkbox" name="pf" value="ios"> iOS</label></div>
                    <label class="field">Beschreibung</label><textarea name="description" rows="3" maxlength="2000"></textarea>
                    <label class="vx-auto"><input type="checkbox" name="notify"> Gruppe benachrichtigen</label>
                    <button class="btn btn-gold" style="margin-top: 10px;">Event anlegen</button>
                </form>
            </div>
        </div>
    </section>

    <section class="vx-tab" data-pane="gallery" hidden>
        <div class="vx-tools">
            <div class="chips" id="galKinds"></div>
            <button type="button" class="btn btn-sm" id="galRefresh">⟳ Aktualisieren</button>
            <form id="galUpload" class="vx-inline" enctype="multipart/form-data">
                <input type="file" name="file" accept="image/png,image/jpeg,image/gif,image/webp" required aria-label="Bilddatei">
                <select name="anim" id="galAnim" aria-label="Emoji-Animation" hidden><option value="none">ohne Animation</option><option value="aura">Aura</option><option value="bats">Fledermäuse</option><option value="bees">Bienen</option>
                    <option value="bounce">Hüpfen</option><option value="cloud">Wolke</option><option value="confetti">Konfetti</option><option value="crying">Tränen</option><option value="fire">Feuer</option><option value="idea">Idee</option>
                    <option value="lasers">Laser</option><option value="magnet">Magnet</option><option value="money">Geld</option><option value="orbit">Orbit</option><option value="rain">Regen</option><option value="rotate">Drehen</option>
                    <option value="shake">Wackeln</option><option value="snow">Schnee</option><option value="spin">Kreiseln</option><option value="splash">Platsch</option><option value="zzz">Zzz</option></select>
                <button class="btn btn-sm btn-gold">⬆ Hochladen</button>
            </form>
        </div>
        <div id="galBox"><div class="vx-empty">Lade …</div></div>
    </section>

    <section class="vx-tab" data-pane="news" hidden>
        <div class="vx-tools"><div class="chips" id="newsKinds"></div><button type="button" class="btn btn-sm" id="newsRefresh" title="Neu laden">⟳</button></div>
        <div id="newsBox"></div>
    </section>

    <section class="vx-tab" data-pane="favs" hidden>
        <div class="vx-chips" id="favKinds"><button type="button" class="chip on" data-fk="friend">👥 Freunde</button><button type="button" class="chip" data-fk="world">🌐 Welten</button><button type="button" class="chip" data-fk="avatar">🎭 Avatare</button></div>
        <div class="vx-favwrap"><aside class="vx-favside" id="favSide"></aside><div class="vx-favmain" id="favMain"></div></div>
    </section>

    <section class="vx-tab" data-pane="groups" hidden>
        <div id="groupsBox"></div>
    </section>

    <section class="vx-tab" data-pane="notifs" hidden>
        <div class="vx-bar nf-bar">
            <span class="chips" id="nfView"><button type="button" class="chip on" data-view="open">Offen bei VRChat</button><button type="button" class="chip" data-view="all">Ganzer Verlauf</button></span>
            <select id="nfType"><option value="">Alle Arten</option></select>
            <input type="search" id="nfQ" placeholder="Von, Gruppe, Text …">
            <button type="button" class="btn btn-sm" id="nfReload">⟳ Neu laden</button>
            <button type="button" class="btn btn-sm btn-danger" id="nfClear" title="Einträge löschen, die es bei VRChat nicht mehr gibt">🧹 Verlauf leeren</button>
        </div>
        <div id="notifBox"></div>
    </section>
    <section class="vx-tab" data-pane="shop" hidden>
        <div id="shopBox"></div>
        <form class="vx-bar shop-browse" id="shopForm">
            <b>🔎 Shop ansehen:</b>
            <input type="text" name="target" placeholder="Profil-Link / usr_… eines Creators oder Store-ID esto_…" required>
            <button class="btn btn-sm btn-gold">Anzeigen</button>
        </form>
        <div id="shopBrowse"></div>
    </section>
    <section class="vx-tab" data-pane="global" hidden>
        <div id="globalBox"></div>
        <div class="vx-sec"><h3>🔥 Gerade beliebte Welten</h3><div id="hotWorlds"></div></div>
    </section>

    <section class="vx-tab" data-pane="moderation" hidden>
        <p class="vx-small">Hier siehst du, wen du in VRChat blockiert, stummgeschaltet oder dessen Avatar du ausgeblendet hast – und kannst es aufheben.</p>
        <div id="modBox"></div>
    </section>

    <section class="vx-tab" data-pane="search" hidden>
        <form class="vx-tools" id="searchForm">
            <div class="chips" id="searchKinds"><button type="button" class="chip on" data-kind="users">👤 Benutzer</button><button type="button" class="chip" data-kind="worlds">🌐 Welten</button><button type="button" class="chip" data-kind="groups">🛡️ Gruppen</button></div>
            <input type="search" id="userSearch" placeholder="Suchbegriff …" minlength="2" required aria-label="Suchbegriff"><button class="btn btn-gold">Suchen</button>
        </form>
        <div class="vx-results" id="searchBox"></div>
    </section>

    <section class="vx-tab" data-pane="client" hidden><div id="clientPane" class="nc"></div></section>
    <section class="vx-tab" data-pane="account" hidden>
        <div class="vx-cols">
            <div class="panel">
                <div class="panel-title">Mein Status</div>
                <form id="statusForm" class="vx-status-form">
                    <div class="vx-status-pick">
                        <?php foreach (['join me', 'active', 'ask me', 'busy'] as $s): ?>
                            <label style="--sc: <?php echo VRC_STATUS[$s][1]; ?>;"><input type="radio" name="status" value="<?php echo e($s); ?>"><span><?php echo e(VRC_STATUS[$s][0]); ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <label class="field" style="margin-top: 12px;">Statustext (max. 32 Zeichen)</label>
                    <input type="text" name="desc" maxlength="32" list="statusHistory">
                    <datalist id="statusHistory"></datalist>
                    <div class="chips" id="statusPresets" style="margin-top: 8px;"></div>
                    <button class="btn btn-gold" style="margin-top: 12px;">Status setzen</button>
                    <button type="button" class="btn btn-sm" id="presetSave" style="margin-top: 12px;" title="Aktuelle Auswahl als Vorlage merken">＋ Als Vorlage</button>
                </form>
            </div>
            <div class="panel">
                <div class="panel-title">Mein Profil</div>
                <form id="profileForm">
                    <label class="field">Pronomen</label>
                    <input type="text" name="pronouns" maxlength="32" placeholder="z. B. sie/ihr">
                    <label class="field" style="margin-top: 10px;">Bio (max. 512 Zeichen)</label>
                    <textarea name="bio" rows="5" maxlength="512"></textarea>
                    <label class="field" style="margin-top: 10px;">Links (bis zu 3)</label>
                    <input type="text" name="links[]" placeholder="https://…"><input type="text" name="links[]" placeholder="https://…" style="margin-top: 4px;"><input type="text" name="links[]" placeholder="https://…" style="margin-top: 4px;">
                    <button class="btn btn-gold" style="margin-top: 12px;">Profil speichern</button>
                </form>
            </div>
            <div class="panel">
                <div class="panel-title">Benachrichtigungen &amp; Anzeige</div>
                <form id="prefsForm" class="vx-prefs">
                    <label class="field">Browser-Benachrichtigung, wenn jemand ins Spiel kommt</label>
                    <select name="notify"><option value="off">Aus</option><option value="favorites">Nur ⭐ Favoriten</option><option value="vrcfav">⭐ Favoriten + VRChat-Favoriten</option><option value="all">Alle Freunde</option></select>
                    <label class="vx-auto"><input type="checkbox" name="notify_offline"> auch beim Offline-Gehen</label>
                    <label class="vx-auto"><input type="checkbox" name="notify_gps"> auch bei Ortswechsel (nur Favoriten)</label>
                    <label class="vx-auto"><input type="checkbox" name="sound"> mit Ton</label>
                    <label class="field" style="margin-top: 10px;">⚧ Gender-Modus (Texte gendern)</label>
                    <select name="gender"><option value="off">Aus</option><option value="star">Sternchen – Freund*innen</option><option value="colon">Doppelpunkt – Freund:innen</option>
                        <option value="gap">Unterstrich – Freund_innen</option><option value="dot">Mediopunkt – Freund·innen</option><option value="binnen">Binnen-I – FreundInnen</option></select>
                    <label class="vx-auto"><input type="checkbox" name="clipwatch"> 📋 Zwischenablage beim Zurückkehren prüfen und erkannte IDs anbieten</label>
                    <label class="field" style="margin-top: 10px;">Automatisch aktualisieren alle</label>
                    <select name="interval"><option value="60">1 Minute</option><option value="120">2 Minuten</option><option value="300">5 Minuten</option><option value="600">10 Minuten</option></select>
                    <button type="button" class="btn btn-sm" id="notifyTest" style="margin-top: 10px;">🔔 Test-Benachrichtigung</button>
                </form>
            </div>
            <div class="panel">
                <div class="panel-title">Verbindung &amp; Daten</div>
                <p class="vx-small" id="connInfo"></p>
                <p class="vx-small">Freunde werden höchstens alle <?php echo VRC_SYNC_MIN; ?> Sekunden abgeglichen, offline Freunde alle <?php echo VRC_OFFLINE_SYNC / 60; ?> Minuten.
                   Verlauf und Statistik entstehen durch diese Abgleiche – solange die Seite nicht offen ist, wird nichts aufgezeichnet. Der Verlauf wird <?php echo VRC_FEED_KEEP_DAYS; ?> Tage aufbewahrt.</p>
                <p><a class="btn btn-sm" href="nexus.php?export=friends">⬇ Freunde (CSV)</a> <a class="btn btn-sm" href="nexus.php?export=feed">⬇ Verlauf (CSV)</a></p>
                <button type="button" class="btn btn-danger" id="logoutBtn">VRChat abmelden &amp; trennen</button>
            </div>
            <?php if (can('system.manage')): ?>
                <div class="panel">
                    <div class="panel-title">Einstellungen (Admin)</div>
                    <form id="settingsForm">
                        <label class="field">User-Agent für VRChat</label>
                        <input type="text" name="user_agent" value="<?php echo e(setting('vrc_user_agent', '')); ?>" placeholder="<?php echo e(vrc_user_agent()); ?>">
                        <p class="vx-small">VRChat bittet darum, dass sich Programme mit Name, Version und einer Kontaktmöglichkeit ausweisen, z. B. <code>MeinDashboard/1.0 kontakt@example.org</code>.</p>
                        <button class="btn btn-sm">Speichern</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <p class="vx-small">Tastenkürzel: <kbd>Strg</kbd>+<kbd>K</kbd> Befehlspalette · <kbd>Strg</kbd>+<kbd>V</kbd> ID/Link öffnen · <kbd>/</kbd> Suche · <kbd>R</kbd> aktualisieren · <kbd>1</kbd>–<kbd>9</kbd>, <kbd>0</kbd> Reiter · <kbd>Esc</kbd> schließen</p>
        <div class="panel"><div class="panel-title">📋 Direkt-Zugriff – was erkannt wird</div>
            <p class="vx-small">Kopiere irgendwo eine VRChat-ID oder einen Link und drücke auf dieser Seite <kbd>Strg</kbd>+<kbd>V</kbd> (oder „📋 Direkt-Zugriff“). Erkannt werden:
            Benutzer <code>usr_…</code>, Welten <code>wrld_…</code>, Avatare <code>avtr_…</code>, Gruppen <code>grp_…</code> oder Kürzel wie <code>FURRY.1234</code> / <code>vrc.group/FURRY.1234</code>,
            Instanzen <code>wrld_…:12345~region(eu)</code> sowie Links von vrchat.com (Profile, Welten, Avatare, Gruppen, Einladungslinks) und <code>vrchat://launch</code>-Links.</p></div>
    </section>

    <div class="pm" id="groupModal" hidden role="dialog" aria-modal="true" aria-label="Gruppe">
        <div class="pm-box vx-gbox"><button type="button" class="pm-x" data-gclose aria-label="Schließen">&times;</button><div id="groupDetail"></div></div>
    </div>
    <div class="pm" id="userModal" hidden role="dialog" aria-modal="true" aria-label="Details">
        <div class="pm-box vx-user"><button type="button" class="pm-x" data-close aria-label="Schließen">&times;</button><div id="userBody">Lade …</div></div>
    </div>
<?php endif; ?>
</div>
<script>
    window.VRCX = { connected: <?php echo $acc ? 'true' : 'false'; ?>, status: <?php echo json_encode(array_map(function ($s) { return ['label' => $s[0], 'color' => $s[1]]; }, VRC_STATUS), JSON_UNESCAPED_UNICODE); ?>, syncMin: <?php echo VRC_SYNC_MIN; ?>, discord: <?php echo can('discord.manage') && (function () { require_once __DIR__ . '/inc/modules/discord.php'; return discord_configured(); })() ? 'true' : 'false'; ?> };
</script>
<script src="assets/js/nexus.js"></script>
<script src="assets/js/nexus-client.js"></script>
</body>
</html>
