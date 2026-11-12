<?php
/**
 * VRC Nexus – Favoriten wie in VRCX: VRChat-Favoritengruppen (Freunde, Welten, Avatare) mit Zähler/Limit,
 * eigene lokale Favoritengruppen (unbegrenzt, nur im Dashboard) und ein lokaler Verlauf angesehener Welten/Avatare.
 */
require_once __DIR__ . '/vrchat.php';

const VRC_FAV_KINDS = ['friend' => 'friends', 'world' => 'worlds', 'avatar' => 'avatars'];
const VRC_FAV_LIMIT_DEFAULT = ['friend' => 150, 'world' => 100, 'avatar' => 50];

function vrc_fav_kind(string $k): string {
    if (!isset(VRC_FAV_KINDS[$k])) throw new RuntimeException('Unbekannte Favoriten-Art.');
    return $k;
}

/** Limits pro Gruppe (VRChat, VRC+ hat mehr) – 1 Std. zwischengespeichert */
function vrc_fav_limits(array &$acc): array {
    $key = 'vrc_fav_limits_' . $acc['user_id'];
    $c = json_decode(setting($key, ''), true);
    if (is_array($c) && ($c['t'] ?? 0) > time() - 3600) return $c['l'];
    $l = VRC_FAV_LIMIT_DEFAULT;
    try {
        $r = vrc_call($acc, 'GET', '/auth/user/favoritelimits');
        foreach (['friend', 'world', 'avatar'] as $k) if (!empty($r['maxFavoritesPerGroup'][$k])) $l[$k] = (int)$r['maxFavoritesPerGroup'][$k];
    } catch (VrcAuthError $e) { throw $e; } catch (Throwable $e) {}
    set_setting($key, json_encode(['t' => time(), 'l' => $l]));
    return $l;
}

/** VRChat-Favoritengruppen einer Art: name => [display, visibility] */
function vrc_fav_groups(array &$acc, string $kind): array {
    $out = [];
    foreach ((array)vrc_call($acc, 'GET', '/favorite/groups', ['type' => $kind, 'n' => 50]) as $g) {
        if (empty($g['name'])) continue;
        $out[$g['name']] = ['name' => $g['name'], 'display' => (string)($g['displayName'] ?? $g['name']), 'visibility' => (string)($g['visibility'] ?? 'private'), 'id' => (string)($g['id'] ?? '')];
    }
    uksort($out, 'strnatcmp');
    return $out;
}

/** Alle VRChat-Favoriten einer Art mit Anzeige-Daten */
function vrc_fav_remote(array &$acc, string $kind): array {
    $items = [];
    if ($kind === 'friend') {
        $favs = (array)vrc_call($acc, 'GET', '/favorites', ['type' => 'friend', 'n' => 400]);
        $friends = [];
        foreach (vrc_friend_list((int)$acc['user_id']) as $f) $friends[$f['id']] = $f;
        foreach ($favs as $f) {
            if (empty($f['favoriteId'])) continue;
            $fr = $friends[$f['favoriteId']] ?? null;
            $where = '';
            if ($fr) $where = $fr['state'] === 'offline' ? '' : (($fr['loc']['world_name'] ?? '') !== '' ? $fr['loc']['world_name'] . ' · ' . ($fr['loc']['access'] ?? '') : ($fr['state'] === 'active' ? 'Web/App' : ucfirst((string)($fr['loc']['type'] ?? ''))));
            $items[] = ['id' => $f['favoriteId'], 'fav_id' => $f['id'], 'group' => (string)(((array)($f['tags'] ?? []))[0] ?? ''), 'name' => $fr['name'] ?? $f['favoriteId'],
                        'image' => $fr['image'] ?? '', 'sub' => $fr ? ($fr['status_desc'] ?: $where) : 'kein Freund mehr?', 'where' => $where, 'state' => $fr['state'] ?? 'offline',
                        'status' => $fr['status'] ?? 'offline', 'color' => $fr['trust'][1] ?? '', 'locked' => false];
        }
        return $items;
    }
    $path = $kind === 'world' ? '/worlds/favorites' : '/avatars/favorites';
    for ($off = 0; $off < 1000; $off += 100) {
        $page = (array)vrc_call($acc, 'GET', $path, ['n' => 100, 'offset' => $off]);
        foreach ($page as $x) {
            if (!is_array($x) || empty($x['id'])) continue;
            $items[] = ['id' => $x['id'], 'fav_id' => (string)($x['favoriteId'] ?? ''), 'group' => (string)($x['favoriteGroup'] ?? ''), 'name' => (string)($x['name'] ?? '?'),
                        'image' => (string)($x['thumbnailImageUrl'] ?? $x['imageUrl'] ?? ''),
                        'sub' => (string)($x['authorName'] ?? '') . ($kind === 'world' && isset($x['occupants']) ? ' (' . (int)$x['occupants'] . ')' : ''),
                        'author_id' => (string)($x['authorId'] ?? ''), 'locked' => ($x['releaseStatus'] ?? 'public') !== 'public', 'occupants' => (int)($x['occupants'] ?? 0)];
        }
        if (count($page) < 100) break;
    }
    return $items;
}

// ---------- Lokal ----------

function vrc_local_groups(int $uid, string $kind): array {
    $st = vrc_db()->prepare('SELECT g.name, (SELECT COUNT(*) FROM local_favs f WHERE f.user_id = g.user_id AND f.kind = g.kind AND f.grp = g.name) AS n FROM local_fav_groups g WHERE g.user_id = ? AND g.kind = ? ORDER BY g.sort, g.name COLLATE NOCASE');
    $st->execute([$uid, $kind]);
    $rows = $st->fetchAll();
    if (!$rows) {   // Standardgruppe anlegen
        vrc_db()->prepare('INSERT OR IGNORE INTO local_fav_groups (user_id, kind, name, sort) VALUES (?, ?, ?, 0)')->execute([$uid, $kind, 'Favoriten']);
        $rows = [['name' => 'Favoriten', 'n' => 0]];
    }
    return array_map(function ($r) { return ['name' => $r['name'], 'count' => (int)$r['n']]; }, $rows);
}

function vrc_local_items(int $uid, string $kind): array {
    $st = vrc_db()->prepare('SELECT grp, vrc_id, name, image, sub, added FROM local_favs WHERE user_id = ? AND kind = ? ORDER BY added DESC');
    $st->execute([$uid, $kind]);
    return array_map(function ($r) { return ['id' => $r['vrc_id'], 'group' => $r['grp'], 'name' => $r['name'], 'image' => $r['image'], 'sub' => $r['sub'], 'added' => (int)$r['added']]; }, $st->fetchAll());
}

/** Lokale Favoriten-Gruppe anlegen (hinten anhängen) */
function vrc_local_group_add(int $uid, string $kind, string $name): void {
    $st = vrc_db()->prepare('SELECT COALESCE(MAX(sort), 0) + 1 FROM local_fav_groups WHERE user_id = ? AND kind = ?');
    $st->execute([$uid, $kind]);
    vrc_db()->prepare('INSERT OR IGNORE INTO local_fav_groups (user_id, kind, name, sort) VALUES (?, ?, ?, ?)')->execute([$uid, $kind, $name, (int)$st->fetchColumn()]);
}

function vrc_local_add(int $uid, string $kind, string $grp, array $item): void {
    $grp = mb_substr(trim($grp), 0, 40) ?: 'Favoriten';
    vrc_local_group_add($uid, $kind, $grp);
    vrc_db()->prepare('INSERT OR REPLACE INTO local_favs (user_id, kind, grp, vrc_id, name, image, sub, added) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$uid, $kind, $grp, $item['id'], mb_substr((string)($item['name'] ?? ''), 0, 120), mb_substr((string)($item['image'] ?? ''), 0, 500), mb_substr((string)($item['sub'] ?? ''), 0, 120), time()]);
}

function vrc_local_remove(int $uid, string $kind, string $grp, string $id): void {
    vrc_db()->prepare('DELETE FROM local_favs WHERE user_id = ? AND kind = ? AND grp = ? AND vrc_id = ?')->execute([$uid, $kind, $grp, $id]);
}

/** In welchen lokalen Gruppen steckt eine ID? */
function vrc_local_of(int $uid, string $kind, string $id): array {
    $st = vrc_db()->prepare('SELECT grp FROM local_favs WHERE user_id = ? AND kind = ? AND vrc_id = ?');
    $st->execute([$uid, $kind, $id]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

// ---------- Verlauf ----------

function vrc_history_add(int $uid, string $kind, string $id, string $name, string $image, string $sub): void {
    $db = vrc_db();
    $db->prepare('INSERT OR REPLACE INTO vrc_history (user_id, kind, vrc_id, name, image, sub, ts) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$uid, $kind, $id, mb_substr($name, 0, 120), mb_substr($image, 0, 500), mb_substr($sub, 0, 120), time()]);
    // nur die neuesten 100 behalten (ohne LIMIT in Unterabfrage – das kann MySQL nicht)
    $cut = $db->prepare('SELECT ts FROM vrc_history WHERE user_id = ? AND kind = ? ORDER BY ts DESC LIMIT 1 OFFSET 99');
    $cut->execute([$uid, $kind]);
    if (($ts = $cut->fetchColumn()) !== false) $db->prepare('DELETE FROM vrc_history WHERE user_id = ? AND kind = ? AND ts < ?')->execute([$uid, $kind, (int)$ts]);
}

function vrc_history(int $uid, string $kind): array {
    $st = vrc_db()->prepare('SELECT vrc_id, name, image, sub, ts FROM vrc_history WHERE user_id = ? AND kind = ? ORDER BY ts DESC LIMIT 100');
    $st->execute([$uid, $kind]);
    return array_map(function ($r) { return ['id' => $r['vrc_id'], 'name' => $r['name'], 'image' => $r['image'], 'sub' => $r['sub'], 'ts' => (int)$r['ts']]; }, $st->fetchAll());
}
