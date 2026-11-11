<?php
/**
 * VRCX – VRChat-Freunde, Verlauf, Statistik, Welten, Avatare, Moderation & Gruppen im Dashboard.
 * Anmeldung mit VRChat-Benutzername/Passwort + 2FA; jeder Dashboard-Benutzer verbindet sein eigenes Konto.
 * Alle Aktionen laufen als JSON-Anfragen (assets/vrcx.js).
 */
require_once __DIR__ . '/inc/vrchat.php';

auth_require_setup();
require_permission('vrcx.access');
$me = current_user();
$uid = (int)$me['id'];

if (isset($_GET['export']) && vrc_account($uid)) { vrc_export($uid, (string)$_GET['export']); exit; }

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
                        'loc' => $loc, 'note' => $n['note'], 'favorite' => (bool)$n['favorite'], 'stats' => vrc_friend_stats($uid, $id),
                        'groups' => array_map(function ($g) { return ['id' => $g['groupId'] ?? '', 'name' => $g['name'] ?? '', 'icon' => $g['iconUrl'] ?? '', 'members' => (int)($g['memberCount'] ?? 0), 'rep' => !empty($g['isRepresenting'])]; }, is_array($groups) ? $groups : []),
                    ]];
                    break;
                case 'note_save':
                    vrc_note_save($uid, $valid($id, 'usr'), (string)($in['note'] ?? ''), !empty($in['favorite']));
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
                    $res = vrc_call($acc, 'GET', '/auth/user/notifications', ['n' => 60]);
                    $out = ['items' => array_map(function ($n) { return ['id' => $n['id'] ?? '', 'type' => $n['type'] ?? '', 'from' => $n['senderUsername'] ?? '', 'from_id' => $n['senderUserId'] ?? '',
                                                                          'message' => $n['message'] ?? '', 'created' => $n['created_at'] ?? '', 'seen' => !empty($n['seen'])]; }, is_array($res) ? $res : [])];
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
                    $out = ['groups' => array_map(function ($g) use ($mine) { return ['id' => $g['groupId'] ?? '', 'name' => $g['name'] ?? '', 'short' => ($g['shortCode'] ?? '') . '.' . ($g['discriminator'] ?? ''),
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
                // ----- Statistik -----
                case 'stats':
                    $out = vrc_stats($uid);
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
                    $out = ['current' => $current, 'avatars' => array_values(array_map(function ($a) use ($current) {
                        return ['id' => $a['id'] ?? '', 'name' => $a['name'] ?? '', 'author' => $a['authorName'] ?? '', 'thumb' => $a['thumbnailImageUrl'] ?? '', 'status' => $a['releaseStatus'] ?? '',
                                'desc' => mb_substr((string)($a['description'] ?? ''), 0, 300), 'updated' => $a['updated_at'] ?? '', 'current' => ($a['id'] ?? '') === $current,
                                'platforms' => array_values(array_unique(array_map(function ($p) { return VRC_PLATFORM[$p['platform'] ?? ''] ?? ($p['platform'] ?? ''); }, (array)($a['unityPackages'] ?? []))))];
                    }, is_array($res) ? array_filter($res, 'is_array') : []))];
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
    <title>VRCX</title>
    <?php echo emoji_favicon('🥽'); ?>
    <link rel="stylesheet" href="assets/hud.css">
    <link rel="stylesheet" href="assets/vrcx.css">
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
            <h1>VRCX</h1>
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
            <li>⚠ Die VRChat-API ist inoffiziell. VRCX fragt bewusst sparsam ab (höchstens alle 45 s) und führt nichts automatisch aus.</li>
        </ul>
    </div>
<?php else: ?>
    <nav class="vx-tabs" id="tabs" role="tablist">
        <button type="button" class="vx-tabbtn active" data-tab="friends" role="tab"><span>👥</span>Freunde</button>
        <button type="button" class="vx-tabbtn" data-tab="feed" role="tab"><span>📜</span>Verlauf</button>
        <button type="button" class="vx-tabbtn" data-tab="stats" role="tab"><span>📈</span>Statistik</button>
        <button type="button" class="vx-tabbtn" data-tab="worlds" role="tab"><span>🌐</span>Welten</button>
        <button type="button" class="vx-tabbtn" data-tab="avatars" role="tab"><span>🎭</span>Avatare</button>
        <button type="button" class="vx-tabbtn" data-tab="groups" role="tab"><span>🛡️</span>Gruppen</button>
        <button type="button" class="vx-tabbtn" data-tab="notifs" role="tab"><span>🔔</span>Anfragen</button>
        <button type="button" class="vx-tabbtn" data-tab="moderation" role="tab"><span>🚫</span>Moderation</button>
        <button type="button" class="vx-tabbtn" data-tab="search" role="tab"><span>🔍</span>Suche</button>
        <button type="button" class="vx-tabbtn" data-tab="account" role="tab"><span>⚙</span>Konto</button>
    </nav>
    <div class="vx-bar" id="syncBar">
        <span id="syncInfo">Lade …</span>
        <svg class="vx-spark" id="spark" viewBox="0 0 120 26" preserveAspectRatio="none" aria-hidden="true"></svg>
        <span class="vx-bar-right">
            <label class="vx-auto"><input type="checkbox" id="autoRefresh" checked> automatisch</label>
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
            <a class="btn btn-sm" href="vrcx.php?export=friends" title="Freundesliste als CSV (Excel)">⬇ CSV</a>
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
            <a class="btn btn-sm" href="vrcx.php?export=feed" title="Kompletten Verlauf als CSV">⬇ CSV</a>
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
            </div>
            <form id="worldSearch" class="vx-inline"><input type="search" name="q" placeholder="Welten suchen …" minlength="2" aria-label="Welten suchen"><button class="btn btn-sm btn-gold">Suchen</button></form>
        </div>
        <div class="vx-cards" id="worldsBox"></div>
    </section>

    <section class="vx-tab" data-pane="avatars" hidden>
        <div class="vx-tools">
            <div class="chips" id="avatarKinds"><button type="button" class="chip on" data-kind="mine">🎭 Meine Avatare</button><button type="button" class="chip" data-kind="favorites">⭐ Favoriten</button></div>
            <input type="search" id="avatarSearch" placeholder="Avatare filtern …" aria-label="Avatare filtern">
        </div>
        <div class="vx-cards" id="avatarsBox"></div>
    </section>

    <section class="vx-tab" data-pane="groups" hidden>
        <div class="vx-groups" id="groupsBox"></div>
        <div id="groupDetail"></div>
    </section>

    <section class="vx-tab" data-pane="notifs" hidden>
        <div id="notifBox"></div>
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
                <p><a class="btn btn-sm" href="vrcx.php?export=friends">⬇ Freunde (CSV)</a> <a class="btn btn-sm" href="vrcx.php?export=feed">⬇ Verlauf (CSV)</a></p>
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
        <p class="vx-small">Tastenkürzel: <kbd>/</kbd> Suche · <kbd>R</kbd> aktualisieren · <kbd>1</kbd>–<kbd>9</kbd>, <kbd>0</kbd> Reiter · <kbd>Esc</kbd> schließen</p>
    </section>

    <div class="pm" id="userModal" hidden role="dialog" aria-modal="true" aria-label="Details">
        <div class="pm-box vx-user"><button type="button" class="pm-x" data-close aria-label="Schließen">&times;</button><div id="userBody">Lade …</div></div>
    </div>
<?php endif; ?>
</div>
<script>
    window.VRCX = { connected: <?php echo $acc ? 'true' : 'false'; ?>, status: <?php echo json_encode(array_map(function ($s) { return ['label' => $s[0], 'color' => $s[1]]; }, VRC_STATUS), JSON_UNESCAPED_UNICODE); ?>, syncMin: <?php echo VRC_SYNC_MIN; ?> };
</script>
<script src="assets/vrcx.js"></script>
</body>
</html>
