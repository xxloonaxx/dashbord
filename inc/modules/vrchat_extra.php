<?php
/**
 * VRC Nexus – Benachrichtigungen (alte API + „V2“ für Gruppen, Events, Boops …) mit eigenem Verlauf,
 * Shop (Guthaben, VRC+, Käufe, Lizenzen, Creator-/Store-Angebote) und weltweite VRChat-Statistik (Spieler online).
 *
 * Kaufen geht bewusst NICHT über das Dashboard – nur ansehen; gekauft wird im VRChat-Marktplatz.
 */
require_once __DIR__ . '/vrchat.php';

const VRC_NOTIF_TYPES = [
    // alte API (/auth/user/notifications)
    'friendRequest' => ['🤝', 'Freundschaftsanfrage'], 'invite' => ['📨', 'Einladung'], 'requestInvite' => ['🙋', 'Einladungsanfrage'],
    'inviteResponse' => ['↩️', 'Antwort auf Einladung'], 'requestInviteResponse' => ['↩️', 'Antwort auf Anfrage'], 'votetokick' => ['🗳️', 'Abstimmung zum Kicken'],
    'message' => ['💬', 'Nachricht'], 'halp' => ['🆘', 'Hilfe'],
    // V2 (/notifications)
    'group.announcement' => ['📢', 'Gruppen-Ankündigung'], 'group.invite' => ['✉️', 'Gruppen-Einladung'], 'group.joinRequest' => ['🚪', 'Beitrittsanfrage'],
    'group.informative' => ['ℹ️', 'Gruppen-Info'], 'group.post' => ['📝', 'Gruppen-Beitrag'], 'group.event.created' => ['📅', 'Neues Gruppen-Event'],
    'group.event.starting' => ['⏰', 'Event beginnt'], 'group.transfer' => ['🔁', 'Gruppen-Übergabe'], 'event.announcement' => ['🎉', 'Event'],
    'boop' => ['👃', 'Boop'], 'badge.earned' => ['🏅', 'Abzeichen'], 'economy.alert' => ['💰', 'Shop-Hinweis'], 'economy.received.gift' => ['🎁', 'Geschenk erhalten'],
    'vrcplus.gift' => ['💎', 'VRC+ geschenkt'], 'promo.redeem' => ['🎟️', 'Gutschein'], 'avatarreview.success' => ['✅', 'Avatar geprüft'],
    'avatarreview.failure' => ['❌', 'Avatar abgelehnt'], 'moderation.warning.group' => ['⚠️', 'Gruppen-Verwarnung'], 'moderation.notice' => ['🛡️', 'Moderations-Hinweis'],
    'moderation.report.closed' => ['📋', 'Meldung abgeschlossen'], 'moderation.contentrestriction' => ['🚫', 'Inhaltsbeschränkung'],
    'invite.instance.contentGated' => ['🔒', 'Einladung (eingeschränkt)'], 'text.adventure' => ['📖', 'Textabenteuer'],
];
const VRC_NOTIF_RESPONSES = ['accept' => '✓ Annehmen', 'decline' => '✕ Ablehnen', 'block' => '⛔ Blockieren', 'unsubscribe' => '🔕 Abbestellen', 'delete' => '🗑 Löschen', 'boop' => '👃 Zurück-Boopen'];

// =====================================================================
// Benachrichtigungen
// =====================================================================

function vrc_notif_norm_v1(array $n): array {
    $d = $n['details'] ?? [];
    if (is_string($d)) $d = json_decode($d, true) ?: [];
    $msg = (string)($n['message'] ?? '');
    if (!empty($d['worldName'])) $msg = trim('🌐 ' . $d['worldName'] . ($msg !== '' ? ' · ' . $msg : ''));
    if (!empty($d['inviteMessage'])) $msg = trim($msg . ' „' . $d['inviteMessage'] . '“');
    if (!empty($d['requestMessage'])) $msg = trim($msg . ' „' . $d['requestMessage'] . '“');
    if (!empty($d['responseMessage'])) $msg = trim($msg . ' „' . $d['responseMessage'] . '“');
    return ['id' => (string)($n['id'] ?? ''), 'src' => 'v1', 'type' => (string)($n['type'] ?? ''), 'created' => (int)strtotime((string)($n['created_at'] ?? 'now')),
            'sender_id' => (string)($n['senderUserId'] ?? ''), 'sender_name' => (string)($n['senderUsername'] ?? ''), 'group_id' => '', 'group_name' => '',
            'image' => '', 'title' => '', 'message' => mb_substr($msg, 0, 1000), 'link' => '', 'responses' => [], 'seen' => !empty($n['seen']), 'can_delete' => true];
}

function vrc_notif_norm_v2(array $n): array {
    $data = is_array($n['data'] ?? null) ? $n['data'] : [];
    $resp = [];
    foreach ((array)($n['responses'] ?? []) as $r) {
        if (!is_array($r) || !isset(VRC_NOTIF_RESPONSES[$r['type'] ?? ''])) continue;
        $resp[] = ['type' => $r['type'], 'data' => (string)($r['data'] ?? ''), 'text' => (string)($r['text'] ?? '')];
    }
    $img = (string)($n['imageUrl'] ?? '');
    return ['id' => (string)($n['id'] ?? ''), 'src' => 'v2', 'type' => (string)($n['type'] ?? ''), 'created' => (int)strtotime((string)($n['createdAt'] ?? 'now')),
            'sender_id' => (string)($n['senderUserId'] ?? ''), 'sender_name' => (string)($n['senderUsername'] ?? ''),
            'group_id' => (string)($data['groupId'] ?? ''), 'group_name' => (string)($data['groupName'] ?? ''),
            'image' => preg_match('#^https://#', $img) ? $img : '', 'title' => mb_substr((string)($n['title'] ?? ($data['announcementTitle'] ?? '')), 0, 300),
            'message' => mb_substr((string)($n['message'] ?? ''), 0, 1000), 'link' => mb_substr((string)($n['link'] ?? ''), 0, 300), 'responses' => $resp,
            'seen' => !empty($n['seen']), 'can_delete' => ($n['canDelete'] ?? true) !== false];
}

/** Von VRChat holen, im Verlauf speichern; was bei VRChat nicht mehr da ist, wird „inaktiv“ */
function vrc_notif_sync(int $uid, array &$acc): array {
    $items = []; $errors = [];
    try { foreach ((array)vrc_call($acc, 'GET', '/auth/user/notifications', ['n' => 100]) as $n) if (is_array($n) && !empty($n['id'])) $items[] = vrc_notif_norm_v1($n); }
    catch (VrcAuthError $e) { throw $e; } catch (Throwable $e) { $errors[] = 'Benachrichtigungen: ' . $e->getMessage(); }
    try { foreach ((array)vrc_call($acc, 'GET', '/notifications', ['limit' => 100]) as $n) if (is_array($n) && !empty($n['id'])) $items[] = vrc_notif_norm_v2($n); }
    catch (VrcAuthError $e) { throw $e; } catch (Throwable $e) { $errors[] = 'Gruppen-Benachrichtigungen: ' . $e->getMessage(); }
    $db = vrc_db();
    $db->beginTransaction();
    if (!$errors) $db->prepare('UPDATE notif_log SET active = 0 WHERE user_id = ?')->execute([$uid]);
    $up = $db->prepare('INSERT INTO notif_log (user_id, id, src, type, created, sender_id, sender_name, group_id, group_name, image, title, message, link, responses, seen, active, can_delete)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                        ON CONFLICT(user_id, id) DO UPDATE SET seen = excluded.seen, active = 1, responses = excluded.responses, message = excluded.message, title = excluded.title, image = excluded.image');
    foreach ($items as $n) {
        $up->execute([$uid, $n['id'], $n['src'], $n['type'], $n['created'], $n['sender_id'], $n['sender_name'], $n['group_id'], $n['group_name'], $n['image'],
                      $n['title'], $n['message'], $n['link'], json_encode($n['responses'], JSON_UNESCAPED_UNICODE), $n['seen'] ? 1 : 0, $n['can_delete'] ? 1 : 0]);
    }
    $db->commit();
    return $errors;
}

function vrc_notif_list(int $uid, array $f): array {
    $where = ['user_id = ?']; $args = [$uid];
    if (($f['view'] ?? 'open') === 'open') $where[] = 'active = 1';
    if (!empty($f['type'])) { $where[] = 'type = ?'; $args[] = (string)$f['type']; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $where[] = '(sender_name LIKE ? OR group_name LIKE ? OR message LIKE ? OR title LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%", "%$q%"); }
    $st = vrc_db()->prepare('SELECT * FROM notif_log WHERE ' . implode(' AND ', $where) . ' ORDER BY created DESC LIMIT 300');
    $st->execute($args);
    $out = [];
    foreach ($st as $r) {
        $out[] = ['id' => $r['id'], 'src' => $r['src'], 'type' => $r['type'], 'created' => (int)$r['created'], 'sender_id' => $r['sender_id'], 'sender_name' => $r['sender_name'],
                  'group_id' => $r['group_id'], 'group_name' => $r['group_name'], 'image' => $r['image'], 'title' => $r['title'], 'message' => $r['message'],
                  'link' => $r['link'], 'responses' => json_decode($r['responses'], true) ?: [], 'seen' => (bool)$r['seen'], 'active' => (bool)$r['active'], 'can_delete' => (bool)$r['can_delete']];
    }
    $cnt = vrc_db()->prepare('SELECT type, COUNT(*) AS n FROM notif_log WHERE user_id = ? GROUP BY type ORDER BY n DESC');
    $cnt->execute([$uid]);
    $open = vrc_db()->prepare('SELECT COUNT(*) FROM notif_log WHERE user_id = ? AND active = 1 AND seen = 0');
    $open->execute([$uid]);
    return ['items' => $out, 'types' => array_column($cnt->fetchAll(), 'n', 'type'), 'unseen' => (int)$open->fetchColumn()];
}

function vrc_notif_get(int $uid, string $id): array {
    $st = vrc_db()->prepare('SELECT * FROM notif_log WHERE user_id = ? AND id = ?');
    $st->execute([$uid, $id]);
    $r = $st->fetch();
    if (!$r) throw new RuntimeException('Benachrichtigung nicht gefunden – bitte neu laden.');
    return $r;
}

/** Aktion auf eine Benachrichtigung: accept (Freundschaft), respond (V2), see, delete */
function vrc_notif_action(int $uid, array &$acc, string $id, string $op, string $rtype = '', string $rdata = ''): string {
    if (!preg_match('/^[a-z]{2,6}_[0-9a-f\-]{8,40}$/i', $id)) throw new RuntimeException('Ungültige Benachrichtigung.');
    $n = vrc_notif_get($uid, $id);
    $db = vrc_db();
    $done = function (array $set) use ($db, $uid, $id) {
        $db->prepare('UPDATE notif_log SET ' . implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($set))) . ' WHERE user_id = ? AND id = ?')
           ->execute(array_merge(array_values($set), [$uid, $id]));
    };
    switch ($op) {
        case 'accept':
            if ($n['src'] !== 'v1' || $n['type'] !== 'friendRequest') throw new RuntimeException('Nur Freundschaftsanfragen lassen sich so annehmen.');
            vrc_call($acc, 'PUT', '/auth/user/notifications/' . rawurlencode($id) . '/accept');
            $done(['active' => 0, 'seen' => 1]);
            audit('VRChat: Freundschaftsanfrage angenommen', $n['sender_name']);
            return 'Angenommen – ' . $n['sender_name'] . ' ist jetzt befreundet.';
        case 'respond':
            if ($n['src'] !== 'v2') throw new RuntimeException('Diese Benachrichtigung hat keine Antwortmöglichkeiten.');
            $allowed = array_column(json_decode($n['responses'], true) ?: [], 'data', 'type');
            if (!array_key_exists($rtype, $allowed)) throw new RuntimeException('Diese Antwort ist hier nicht möglich.');
            vrc_call($acc, 'POST', '/notifications/' . rawurlencode($id) . '/respond', [], ['responseType' => $rtype, 'responseData' => (string)$allowed[$rtype]]);
            $done(['active' => in_array($rtype, ['unsubscribe'], true) ? 1 : 0, 'seen' => 1]);
            if (in_array($rtype, ['accept', 'decline', 'block'], true)) audit('VRChat: auf Benachrichtigung geantwortet', $rtype . ' · ' . ($n['group_name'] ?: $n['sender_name']));
            return ['accept' => 'Angenommen.', 'decline' => 'Abgelehnt.', 'block' => 'Blockiert.', 'unsubscribe' => 'Abbestellt – davon kommt nichts mehr.', 'delete' => 'Gelöscht.', 'boop' => 'Zurück-gebooped! 👃'][$rtype];
        case 'see':
            if ($n['src'] === 'v2') vrc_call($acc, 'POST', '/notifications/' . rawurlencode($id) . '/see');
            else vrc_call($acc, 'PUT', '/auth/user/notifications/' . rawurlencode($id) . '/see');
            $done(['seen' => 1]);
            return 'Als gelesen markiert.';
        case 'delete':
            if ($n['active']) {
                if ($n['src'] === 'v2') vrc_call($acc, 'DELETE', '/notifications/' . rawurlencode($id));
                else vrc_call($acc, 'PUT', '/auth/user/notifications/' . rawurlencode($id) . '/hide');
            }
            $done(['active' => 0, 'seen' => 1]);
            return 'Bei VRChat entfernt (bleibt im Verlauf).';
        case 'forget':
            $db->prepare('DELETE FROM notif_log WHERE user_id = ? AND id = ?')->execute([$uid, $id]);
            return 'Aus dem Verlauf gelöscht.';
    }
    throw new RuntimeException('Unbekannte Aktion.');
}

function vrc_notif_clear_history(int $uid): int {
    $st = vrc_db()->prepare('DELETE FROM notif_log WHERE user_id = ? AND active = 0');
    $st->execute([$uid]);
    return $st->rowCount();
}

// =====================================================================
// Shop
// =====================================================================

function vrc_listing_slim(array $l): array {
    $img = (string)($l['imageUrl'] ?? '');
    $types = array_values(array_filter([!empty($l['hasAvatar']) ? 'Avatar' : '', !empty($l['hasUdon']) ? 'Welt-Inhalt' : '', !empty($l['hasInventory']) ? 'Inventar' : '', !empty($l['hasCompanion']) ? 'Begleiter' : '']));
    return ['id' => (string)($l['id'] ?? ''), 'name' => (string)($l['displayName'] ?? ''), 'subtitle' => (string)($l['subtitle'] ?? ''), 'desc' => mb_substr((string)($l['description'] ?? ''), 0, 600),
            'price' => (int)($l['priceTokens'] ?? 0), 'vrcplus_price' => isset($l['vrcPlusDiscountPrice']) ? (int)$l['vrcPlusDiscountPrice'] : null,
            'image' => preg_match('#^https://#', $img) ? $img : '', 'image_id' => preg_match('/^file_[0-9a-f\-]{36}$/i', (string)($l['imageId'] ?? '')) ? $l['imageId'] : '',
            'type' => (string)($l['listingType'] ?? ''), 'kinds' => $types, 'seller' => (string)($l['sellerDisplayName'] ?? ''), 'seller_id' => (string)($l['sellerId'] ?? ''),
            'group' => (string)($l['groupName'] ?? ''), 'duration' => $l['duration'] ?? null, 'duration_type' => (string)($l['durationType'] ?? ''),
            'active' => !empty($l['active']), 'sold' => (int)($l['purchaseCount'] ?? 0), 'by_vrc' => !empty($l['soldByVrc']), 'permanent' => !empty($l['permanent'])];
}

/** Übersicht des eigenen Kontos: Guthaben, Abos, Lizenzen, Käufe, eigene Angebote. Teile, die VRChat verweigert, bleiben leer. */
function vrc_shop_overview(array &$acc): array {
    $me = $acc['vrc_id'];
    $out = ['balance' => null, 'subs' => [], 'licenses' => 0, 'purchases' => [], 'listings' => [], 'errors' => []];
    $try = function (string $label, callable $fn) use (&$out) {
        try { $fn(); } catch (VrcAuthError $e) { throw $e; } catch (Throwable $e) { $out['errors'][] = $label . ': ' . $e->getMessage(); }
    };
    $try('Guthaben', function () use (&$acc, &$out, $me) { $b = (array)vrc_call($acc, 'GET', '/user/' . rawurlencode($me) . '/balance'); $out['balance'] = (int)($b['balance'] ?? 0); });
    $try('Abos', function () use (&$acc, &$out) {
        foreach ((array)vrc_call($acc, 'GET', '/auth/user/subscription') as $s) if (is_array($s)) $out['subs'][] = ['name' => (string)($s['description'] ?? ($s['id'] ?? '')), 'active' => !empty($s['active']),
            'status' => (string)($s['status'] ?? ''), 'expires' => (string)($s['expires'] ?? ''), 'starts' => (string)($s['starts'] ?? ''), 'period' => (string)($s['period'] ?? ''),
            'store' => (string)($s['store'] ?? ''), 'gift' => !empty($s['isGift'])];
    });
    $try('Lizenzen', function () use (&$acc, &$out) { $out['licenses'] = count((array)vrc_call($acc, 'GET', '/economy/licenses/active')); });
    $try('Käufe', function () use (&$acc, &$out, $me) {
        foreach ((array)vrc_call($acc, 'GET', '/economy/purchases', ['buyerId' => $me, 'n' => 100, 'mostRecent' => 'true']) as $p) {
            if (!is_array($p)) continue;
            $out['purchases'][] = ['id' => (string)($p['purchaseId'] ?? ''), 'name' => (string)($p['listingDisplayName'] ?? ''), 'subtitle' => (string)($p['listingSubtitle'] ?? ''),
                'seller' => (string)($p['sellerDisplayName'] ?? ''), 'seller_id' => (string)($p['sellerId'] ?? ''), 'price' => (int)($p['purchasePrice'] ?? 0),
                'date' => (string)($p['purchaseDate'] ?? ''), 'end' => (string)($p['purchaseEndDate'] ?? ''), 'active' => !empty($p['purchaseActive']),
                'gift' => !empty($p['isGift']), 'receiver' => (string)($p['receiverDisplayName'] ?? ''), 'type' => (string)($p['listingType'] ?? ''),
                'image_id' => preg_match('/^file_[0-9a-f\-]{36}$/i', (string)($p['listingImageId'] ?? '')) ? $p['listingImageId'] : '', 'recurs' => !empty($p['willRecur'])];
        }
    });
    $try('Eigene Angebote', function () use (&$acc, &$out, $me) {
        foreach ((array)vrc_call($acc, 'GET', '/user/' . rawurlencode($me) . '/listings', ['n' => 100, 'hydrate' => 'true']) as $l) if (is_array($l)) $out['listings'][] = vrc_listing_slim($l);
    });
    return $out;
}

/** Angebote eines Creators (usr_…) oder eines Stores (esto_…) */
function vrc_shop_browse(array &$acc, string $target): array {
    $target = trim($target);
    if (preg_match('#(usr_[0-9a-f\-]{36}|esto_[0-9a-f\-]{36})#i', $target, $m)) $target = $m[1];
    if (strpos($target, 'usr_') === 0) {
        $u = (array)vrc_call($acc, 'GET', '/users/' . rawurlencode($target));
        $list = [];
        for ($off = 0; $off < 300; $off += 100) {
            $page = (array)vrc_call($acc, 'GET', '/user/' . rawurlencode($target) . '/listings', ['n' => 100, 'offset' => $off, 'hydrate' => 'true', 'active' => 'true']);
            foreach ($page as $l) if (is_array($l)) $list[] = vrc_listing_slim($l);
            if (count($page) < 100) break;
        }
        return ['title' => 'Shop von ' . ($u['displayName'] ?? $target), 'shelves' => [['title' => 'Angebote', 'desc' => '', 'listings' => $list]]];
    }
    if (strpos($target, 'esto_') === 0) {
        $s = (array)vrc_call($acc, 'GET', '/economy/store', ['storeId' => $target, 'hydrateListings' => 'true']);
        $shelves = [];
        if (!empty($s['listings'])) $shelves[] = ['title' => 'Angebote', 'desc' => '', 'listings' => array_map('vrc_listing_slim', array_filter((array)$s['listings'], 'is_array'))];
        try {
            foreach ((array)vrc_call($acc, 'GET', '/economy/store/shelves', ['storeId' => $target, 'hydrateListings' => 'true']) as $sh) {
                if (!is_array($sh)) continue;
                $shelves[] = ['title' => (string)($sh['shelfTitle'] ?? ''), 'desc' => (string)($sh['shelfDescription'] ?? ''), 'listings' => array_map('vrc_listing_slim', array_filter((array)($sh['listings'] ?? []), 'is_array'))];
            }
        } catch (VrcAuthError $e) { throw $e; } catch (Throwable $e) { /* Store ohne Regale */ }
        return ['title' => (string)($s['displayName'] ?? 'Store'), 'seller' => (string)($s['sellerDisplayName'] ?? ''), 'shelves' => $shelves];
    }
    throw new RuntimeException('Bitte eine Benutzer-ID (usr_…), einen Profil-Link oder eine Store-ID (esto_…) angeben.');
}

// =====================================================================
// Weltweite Statistik
// =====================================================================

/** Aktuelle Spielerzahl von VRChat (öffentlich, ohne Anmeldung) – höchstens alle 10 Minuten abfragen und speichern */
function vrc_visits_sample(bool $force = false): ?int {
    $db = vrc_db();
    $last = $db->query('SELECT ts, online FROM global_stats ORDER BY ts DESC LIMIT 1')->fetch();
    if (!$force && $last && (int)$last['ts'] > time() - 600) return (int)$last['online'];
    $jar = [];
    [$code, $json] = vrc_http($jar, 'GET', '/visits');
    if ($code !== 200 || !is_numeric($json)) return $last ? (int)$last['online'] : null;
    $db->prepare('INSERT OR REPLACE INTO global_stats (ts, online) VALUES (?, ?)')->execute([time(), (int)$json]);
    $db->prepare('DELETE FROM global_stats WHERE ts < ?')->execute([time() - 400 * 86400]);
    return (int)$json;
}

function vrc_global_stats(int $days): array {
    $now = vrc_visits_sample();
    $st = vrc_db()->prepare('SELECT ts, online FROM global_stats WHERE ts >= ? ORDER BY ts');
    $st->execute([time() - $days * 86400]);
    $rows = array_map(function ($r) { return [(int)$r['ts'], (int)$r['online']]; }, $st->fetchAll());
    $vals = array_column($rows, 1);
    // Durchschnitt je Wochentag & Stunde (alle Daten)
    $heat = array_fill(0, 7, array_fill(0, 24, [0, 0]));
    foreach (vrc_db()->query('SELECT ts, online FROM global_stats') as $r) { $w = (int)date('N', (int)$r['ts']) - 1; $h = (int)date('G', (int)$r['ts']); $heat[$w][$h][0] += (int)$r['online']; $heat[$w][$h][1]++; }
    $heat = array_map(function ($row) { return array_map(function ($c) { return $c[1] ? (int)round($c[0] / $c[1]) : null; }, $row); }, $heat);
    $rec = vrc_db()->query('SELECT ts, online FROM global_stats ORDER BY online DESC LIMIT 1')->fetch();
    $first = (int)vrc_db()->query('SELECT MIN(ts) FROM global_stats')->fetchColumn();
    $yday = vrc_db()->prepare('SELECT online FROM global_stats WHERE ts BETWEEN ? AND ? ORDER BY ABS(ts - ?) LIMIT 1');
    $yday->execute([time() - 86400 - 1800, time() - 86400 + 1800, time() - 86400]);
    return ['now' => $now, 'series' => $rows, 'min' => $vals ? min($vals) : null, 'max' => $vals ? max($vals) : null, 'avg' => $vals ? (int)round(array_sum($vals) / count($vals)) : null,
            'heat' => $heat, 'record' => $rec ? ['ts' => (int)$rec['ts'], 'online' => (int)$rec['online']] : null, 'since' => $first, 'samples' => count($rows),
            'yesterday' => ($y = $yday->fetchColumn()) !== false ? (int)$y : null];
}

/** Gerade beliebte Welten (nach „heat“) */
function vrc_hot_worlds(array &$acc): array {
    $out = [];
    foreach ((array)vrc_call($acc, 'GET', '/worlds', ['sort' => 'heat', 'order' => 'descending', 'n' => 24, 'featured' => 'false']) as $w) {
        if (!is_array($w) || empty($w['id'])) continue;
        $out[] = ['id' => $w['id'], 'name' => (string)($w['name'] ?? ''), 'author' => (string)($w['authorName'] ?? ''), 'thumb' => preg_match('#^https://#', (string)($w['thumbnailImageUrl'] ?? '')) ? $w['thumbnailImageUrl'] : '',
                  'occupants' => (int)($w['occupants'] ?? 0), 'favorites' => (int)($w['favorites'] ?? 0), 'heat' => (int)($w['heat'] ?? 0), 'visits' => (int)($w['visits'] ?? 0)];
    }
    usort($out, function ($a, $b) { return $b['occupants'] <=> $a['occupants']; });
    return $out;
}

// =====================================================================
// Avatar-Suche über externe Datenbanken (VRCX-kompatible „Avatar Database Provider“)
// =====================================================================

const VRC_AVATAR_PROVIDERS_DEFAULT = ['https://api.avtrdb.com/v3/avatar/search/vrcx'];

function vrc_avatar_providers(): array {
    $list = json_decode(setting('vrc_avatar_providers', ''), true);
    return is_array($list) && $list ? array_values($list) : VRC_AVATAR_PROVIDERS_DEFAULT;
}

/** Provider-Liste speichern (nur https, keine internen Adressen) */
function vrc_avatar_providers_save(string $text): array {
    $out = [];
    foreach (preg_split('/\s+/', trim($text)) as $u) {
        if ($u === '') continue;
        if (!preg_match('#^https://[a-z0-9.\-]+(:\d+)?(/[^\s?\#]*)?$#i', $u)) throw new RuntimeException('Nur https-Adressen ohne ?-Teil erlaubt: ' . $u);
        $host = strtolower((string)parse_url($u, PHP_URL_HOST));
        if (preg_match('/^(localhost|.*\.local|.*\.internal)$/', $host) || filter_var($host, FILTER_VALIDATE_IP)) throw new RuntimeException('Interne Adressen sind nicht erlaubt: ' . $host);
        $out[] = $u;
    }
    $out = array_slice(array_values(array_unique($out)), 0, 8);
    set_setting('vrc_avatar_providers', json_encode($out ?: VRC_AVATAR_PROVIDERS_DEFAULT, JSON_UNESCAPED_SLASHES));
    return vrc_avatar_providers();
}

/** Suche bei einem Provider: Text oder Autor (usr_…) */
function vrc_avatar_db_search(int $provider, string $q): array {
    $list = vrc_avatar_providers();
    if (!isset($list[$provider])) throw new RuntimeException('Unbekannter Provider.');
    $q = trim($q);
    if (mb_strlen($q) < 2) throw new RuntimeException('Bitte mindestens 2 Zeichen eingeben.');
    if (preg_match('/(usr_[0-9a-f\-]{36})/i', $q, $m)) $params = ['authorId' => $m[1], 'n' => 500];
    elseif (preg_match('/(avtr_[0-9a-f\-]{36})/i', $q, $m)) $params = ['search' => $m[1], 'n' => 50];
    else $params = ['search' => mb_substr($q, 0, 100), 'n' => 500];
    $url = $list[$provider] . '?' . http_build_query($params);
    $host = (string)parse_url($url, PHP_URL_HOST);
    $ip = gethostbyname($host);
    $resolved = $ip !== $host && filter_var($ip, FILTER_VALIDATE_IP);   // ohne eigene Namensauflösung (z. B. hinter einem Proxy) entscheidet cURL
    if ($resolved && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) throw new RuntimeException('Provider zeigt auf eine interne Adresse.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
                            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => 20000000,
                            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: GhostNode-Dashboard/1.0 (VRC Nexus avatar search)']]);
    if ($resolved) curl_setopt($ch, CURLOPT_RESOLVE, [$host . ':' . ((int)parse_url($url, PHP_URL_PORT) ?: 443) . ':' . $ip]);   // geprüfte Adresse festhalten
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Provider nicht erreichbar: ' . $err);
    if ($code === 404) return [];
    if ($code !== 200) throw new RuntimeException('Provider antwortet mit HTTP ' . $code . '.');
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) throw new RuntimeException('Provider liefert kein gültiges Ergebnis.');
    $https = function ($u) { return is_string($u) && preg_match('#^https://#', $u) ? $u : ''; };
    $out = [];
    foreach (array_slice($data, 0, 500) as $a) {
        if (!is_array($a) || !preg_match('/^avtr_[0-9a-f\-]{36}$/i', (string)($a['id'] ?? ''))) continue;
        $perf = is_array($a['performance'] ?? null) ? $a['performance'] : [];
        $pf = [];
        if (!empty($perf['pc_rating']) && $perf['pc_rating'] !== 'None') $pf[] = 'PC';
        if (!empty($perf['android_rating']) && $perf['android_rating'] !== 'None') $pf[] = 'Quest / Android';
        if (!empty($perf['ios_rating']) && $perf['ios_rating'] !== 'None') $pf[] = 'iOS';
        $out[] = ['id' => $a['id'], 'name' => mb_substr((string)($a['name'] ?? ''), 0, 120), 'author' => mb_substr((string)($a['authorName'] ?? ''), 0, 64),
                  'author_id' => preg_match('/^usr_[0-9a-f\-]{36}$/i', (string)($a['authorId'] ?? '')) ? $a['authorId'] : '',
                  'thumb' => $https($a['thumbnailImageUrl'] ?? '') ?: $https($a['imageUrl'] ?? ''), 'desc' => mb_substr((string)($a['description'] ?? ''), 0, 300),
                  'status' => (string)($a['releaseStatus'] ?? ''), 'updated' => (string)($a['updated_at'] ?? ''), 'created' => (string)($a['created_at'] ?? ''),
                  'platforms' => $pf, 'perf' => array_filter(['PC' => $perf['pc_rating'] ?? '', 'Quest' => $perf['android_rating'] ?? '', 'iOS' => $perf['ios_rating'] ?? ''])];
    }
    return $out;
}
