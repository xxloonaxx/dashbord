<?php
/**
 * VRC Nexus Desktop-Client (Windows): Anbindung an das Dashboard.
 *
 * Der Client (clients/nexus-desktop) liest die VRChat-Logdateien (Welten, Spieler, Avatare, Videos, Screenshots,
 * Einladungen) und schickt sie als „Spiel-Log“ hierher. Umgekehrt holt er OSC-Befehle ab (Chatbox, Avatar-Parameter,
 * Eingaben), die man im Dashboard abschickt – so lässt sich VRChat auch vom Handy aus steuern.
 *
 * Anmeldung des Clients: Geräte-Token (im Nexus unter „🖥️ Client“ erzeugt, nur einmal sichtbar, gespeichert als SHA-256).
 * Schnittstelle: client.php (siehe dort).
 */
require_once __DIR__ . '/vrchat.php';

const NC_KINDS = [
    'world_join'   => ['🌐', 'Welt betreten'],
    'world_leave'  => ['🚪', 'Welt verlassen'],
    'player_join'  => ['👋', 'Spieler kam'],
    'player_leave' => ['👋', 'Spieler ging'],
    'avatar'       => ['🎭', 'Avatar gewechselt'],
    'video'        => ['🎬', 'Video'],
    'screenshot'   => ['📸', 'Screenshot'],
    'notification' => ['🔔', 'Benachrichtigung'],
    'vrc_start'    => ['▶️', 'VRChat gestartet'],
    'vrc_quit'     => ['⏹️', 'VRChat beendet'],
    'note'         => ['📝', 'Notiz'],
];
const NC_OSC_KINDS = ['chatbox', 'typing', 'param', 'input'];
const NC_MAX_TOKENS = 10;

// ---------- Geräte-Tokens ----------

function nc_token_create(int $uid, string $name): string {
    $n = vrc_db()->prepare('SELECT COUNT(*) FROM client_tokens WHERE user_id = ?');
    $n->execute([$uid]);
    if ((int)$n->fetchColumn() >= NC_MAX_TOKENS) throw new RuntimeException('Maximal ' . NC_MAX_TOKENS . ' Geräte – bitte ein altes entfernen.');
    $token = 'gnx_' . bin2hex(random_bytes(20));
    vrc_db()->prepare('INSERT INTO client_tokens (user_id, name, token_hash, created) VALUES (?, ?, ?, ?)')
        ->execute([$uid, mb_substr(trim($name) ?: 'Mein PC', 0, 40), hash('sha256', $token), time()]);
    return $token;
}

function nc_tokens(int $uid): array {
    $st = vrc_db()->prepare('SELECT id, name, created, last_seen, last_ip, version, platform FROM client_tokens WHERE user_id = ? ORDER BY id');
    $st->execute([$uid]);
    return array_map(function ($r) { return ['id' => (int)$r['id'], 'name' => $r['name'], 'created' => (int)$r['created'], 'last_seen' => (int)$r['last_seen'],
                                            'ip' => $r['last_ip'], 'version' => $r['version'], 'platform' => $r['platform'], 'online' => (int)$r['last_seen'] > time() - 90]; }, $st->fetchAll());
}

function nc_token_revoke(int $uid, int $id): void {
    vrc_db()->prepare('DELETE FROM client_tokens WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
}

/** Client anhand des Tokens erkennen → ['token' => Zeile, 'user' => Benutzer] oder null */
function nc_auth(string $token): ?array {
    if (!preg_match('/^gnx_[0-9a-f]{40}$/', $token)) return null;
    $st = vrc_db()->prepare('SELECT * FROM client_tokens WHERE token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    $t = $st->fetch();
    if (!$t) return null;
    $u = user_load((int)$t['user_id']);
    if (!$u || empty($u['active']) || ($u['expires_at'] && strtotime($u['expires_at']) < time()) || !in_array('vrcx.access', $u['permissions'], true)) return null;
    return ['token' => $t, 'user' => $u];
}

function nc_touch(array $t, string $version, string $platform): void {
    if ((int)$t['last_seen'] > time() - 20 && $t['version'] === $version) return;   // nicht bei jeder Anfrage schreiben
    vrc_db()->prepare('UPDATE client_tokens SET last_seen = ?, last_ip = ?, version = ?, platform = ? WHERE id = ?')
        ->execute([time(), (string)($_SERVER['REMOTE_ADDR'] ?? ''), mb_substr($version, 0, 20), mb_substr($platform, 0, 40), $t['id']]);
}

// ---------- Spiel-Log ----------

/** Ereignisse vom Client speichern (doppelte werden erkannt und übersprungen) */
function nc_log_store(int $uid, int $client, array $events): array {
    $ins = vrc_db()->prepare('INSERT OR IGNORE INTO game_log (user_id, ts, kind, world_id, instance, world_name, player_id, player_name, detail, hash, client_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stored = 0; $skipped = 0;
    $db = vrc_db();
    $db->beginTransaction();
    foreach (array_slice($events, 0, 1000) as $e) {
        if (!is_array($e)) { $skipped++; continue; }
        $kind = (string)($e['kind'] ?? '');
        $ts = (int)($e['ts'] ?? 0);
        if (!isset(NC_KINDS[$kind]) || $ts < 1262304000 || $ts > time() + 86400) { $skipped++; continue; }
        $f = function (string $k, int $len) use ($e) { return mb_substr(trim((string)($e[$k] ?? '')), 0, $len); };
        $wid = $f('world_id', 64); $pid = $f('player_id', 64);
        if ($wid !== '' && !preg_match('/^(wrld|wld)_[0-9a-f\-]{8,40}$/i', $wid)) $wid = '';
        if ($pid !== '' && !preg_match('/^usr_[0-9a-f\-]{8,40}$/i', $pid) && !preg_match('/^[0-9A-Za-z]{10}$/', $pid)) $pid = '';
        $row = [$uid, $ts, $kind, $wid, $f('instance', 160), $f('world_name', 120), $pid, $f('player_name', 64), $f('detail', 500)];
        $hash = sha1(implode("\x1f", array_slice($row, 1)));
        $ins->execute(array_merge($row, [$hash, $client]));
        $ins->rowCount() ? $stored++ : $skipped++;
    }
    $db->commit();
    return ['stored' => $stored, 'skipped' => $skipped];
}

function nc_log_list(int $uid, array $f): array {
    $where = ['user_id = ?']; $args = [$uid];
    if (!empty($f['kind']) && isset(NC_KINDS[$f['kind']])) { $where[] = 'kind = ?'; $args[] = $f['kind']; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $where[] = '(world_name LIKE ? OR player_name LIKE ? OR detail LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
    if (!empty($f['player'])) { $where[] = 'player_id = ?'; $args[] = (string)$f['player']; }
    if (!empty($f['before'])) { $where[] = 'id < ?'; $args[] = (int)$f['before']; }
    if (!empty($f['day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['day'])) { $d = strtotime($f['day']); $where[] = 'ts >= ? AND ts < ?'; array_push($args, $d, $d + 86400); }
    $limit = max(10, min(300, (int)($f['limit'] ?? 100)));
    $st = vrc_db()->prepare('SELECT id, ts, kind, world_id, instance, world_name, player_id, player_name, detail FROM game_log WHERE ' . implode(' AND ', $where) . ' ORDER BY ts DESC, id DESC LIMIT ' . $limit);
    $st->execute($args);
    return array_map(function ($r) { $r['id'] = (int)$r['id']; $r['ts'] = (int)$r['ts']; return $r; }, $st->fetchAll());
}

/** Getroffene Spieler (aus dem Spiel-Log) – auch Nicht-Freunde */
function nc_players(int $uid, int $days, string $q = ''): array {
    $args = [$uid, time() - $days * 86400];
    $extra = '';
    if ($q !== '') { $extra = ' AND player_name LIKE ?'; $args[] = "%$q%"; }
    $st = vrc_db()->prepare("SELECT player_id, MAX(player_name) AS name, COUNT(*) AS meets, MAX(ts) AS last, MIN(ts) AS first, COUNT(DISTINCT world_id) AS worlds
                             FROM game_log WHERE user_id = ? AND kind = 'player_join' AND ts >= ? AND player_name <> ''$extra
                             GROUP BY player_id, CASE WHEN player_id = '' THEN player_name ELSE '' END ORDER BY meets DESC, last DESC LIMIT 300");
    $st->execute($args);
    $friends = [];
    $fs = vrc_db()->prepare('SELECT vrc_id FROM friends WHERE user_id = ?');
    $fs->execute([$uid]);
    foreach ($fs->fetchAll(PDO::FETCH_COLUMN) as $id) $friends[$id] = true;
    // gemeinsame Zeit: Paare aus Beitritt/Verlassen zusammenzählen
    $time = [];
    $ev = vrc_db()->prepare("SELECT ts, kind, player_id, player_name FROM game_log WHERE user_id = ? AND ts >= ? AND kind IN ('player_join', 'player_leave', 'world_leave', 'vrc_quit') ORDER BY ts, id");
    $ev->execute([$uid, time() - $days * 86400]);
    $open = [];
    foreach ($ev as $e) {
        $k = $e['player_id'] !== '' ? $e['player_id'] : 'n:' . $e['player_name'];
        if ($e['kind'] === 'player_join') $open[$k] = (int)$e['ts'];
        elseif ($e['kind'] === 'player_leave') { if (isset($open[$k])) { $time[$k] = ($time[$k] ?? 0) + min(43200, (int)$e['ts'] - $open[$k]); unset($open[$k]); } }
        else { foreach ($open as $ok => $t0) $time[$ok] = ($time[$ok] ?? 0) + min(43200, (int)$e['ts'] - $t0); $open = []; }
    }
    return array_map(function ($r) use ($friends, $time) {
        $k = $r['player_id'] !== '' ? $r['player_id'] : 'n:' . $r['name'];
        return ['id' => $r['player_id'], 'name' => $r['name'], 'meets' => (int)$r['meets'], 'last' => (int)$r['last'], 'first' => (int)$r['first'],
                'worlds' => (int)$r['worlds'], 'friend' => isset($friends[$r['player_id']]), 'seconds' => (int)($time[$k] ?? 0)];
    }, $st->fetchAll());
}

/** Weltbesuche mit Dauer (aus Betreten/Verlassen) */
function nc_visits(int $uid, int $days): array {
    $st = vrc_db()->prepare("SELECT ts, kind, world_id, instance, world_name FROM game_log WHERE user_id = ? AND ts >= ? AND kind IN ('world_join', 'world_leave', 'vrc_quit', 'player_join') ORDER BY ts, id");
    $st->execute([$uid, time() - $days * 86400]);
    $visits = []; $cur = null;
    foreach ($st as $e) {
        if ($e['kind'] === 'player_join') { if ($cur !== null) $visits[$cur]['players']++; continue; }
        if ($cur !== null && $visits[$cur]['end'] === 0) $visits[$cur]['end'] = (int)$e['ts'];
        $cur = null;
        if ($e['kind'] === 'world_join') {
            $visits[] = ['world_id' => $e['world_id'], 'instance' => $e['instance'], 'name' => $e['world_name'], 'start' => (int)$e['ts'], 'end' => 0, 'players' => 0];
            $cur = count($visits) - 1;
        }
    }
    foreach ($visits as &$v) $v['seconds'] = ($v['end'] ?: min(time(), $v['start'] + 43200)) - $v['start'];
    unset($v);
    return array_reverse($visits);
}

/** Kennzahlen für die Übersicht */
function nc_stats(int $uid): array {
    $since = strtotime('today');
    $st = vrc_db()->prepare("SELECT kind, COUNT(*) AS n FROM game_log WHERE user_id = ? AND ts >= ? GROUP BY kind");
    $st->execute([$uid, $since]);
    $today = array_column($st->fetchAll(), 'n', 'kind');
    $tot = vrc_db()->prepare('SELECT COUNT(*) AS n, MIN(ts) AS first FROM game_log WHERE user_id = ?');
    $tot->execute([$uid]);
    $t = $tot->fetch();
    $play = 0;
    foreach (nc_visits($uid, 1) as $v) if ($v['start'] >= $since || $v['end'] >= $since) $play += $v['seconds'] - max(0, $since - $v['start']);
    $uniq = vrc_db()->prepare("SELECT COUNT(DISTINCT CASE WHEN player_id = '' THEN player_name ELSE player_id END) FROM game_log WHERE user_id = ? AND kind = 'player_join'");
    $uniq->execute([$uid]);
    return ['today' => ['worlds' => (int)($today['world_join'] ?? 0), 'players' => (int)($today['player_join'] ?? 0), 'screenshots' => (int)($today['screenshot'] ?? 0),
                        'avatars' => (int)($today['avatar'] ?? 0), 'videos' => (int)($today['video'] ?? 0), 'playtime' => max(0, $play)],
            'total' => (int)$t['n'], 'since' => (int)$t['first'], 'people' => (int)$uniq->fetchColumn()];
}

function nc_log_clear(int $uid): int {
    $st = vrc_db()->prepare('DELETE FROM game_log WHERE user_id = ?');
    $st->execute([$uid]);
    return $st->rowCount();
}

// ---------- Live-Zustand ----------

function nc_state_save(int $uid, int $client, array $s): void {
    $players = [];
    foreach (array_slice((array)($s['players'] ?? []), 0, 100) as $p) {
        if (!is_array($p)) continue;
        $players[] = ['id' => mb_substr((string)($p['id'] ?? ''), 0, 64), 'name' => mb_substr((string)($p['name'] ?? ''), 0, 64), 'since' => (int)($p['since'] ?? 0)];
    }
    $params = [];
    foreach (array_slice((array)($s['params'] ?? []), 0, 64, true) as $k => $v) {
        if (preg_match('/^[\w\/\-. ]{1,64}$/u', (string)$k) && (is_scalar($v))) $params[(string)$k] = is_bool($v) ? $v : (is_numeric($v) ? $v + 0 : mb_substr((string)$v, 0, 64));
    }
    $clean = [
        'running' => !empty($s['running']), 'world_id' => mb_substr((string)($s['world_id'] ?? ''), 0, 64), 'instance' => mb_substr((string)($s['instance'] ?? ''), 0, 160),
        'world_name' => mb_substr((string)($s['world_name'] ?? ''), 0, 120), 'since' => (int)($s['since'] ?? 0), 'players' => $players,
        'avatar' => mb_substr((string)($s['avatar'] ?? ''), 0, 80), 'avatar_id' => mb_substr((string)($s['avatar_id'] ?? ''), 0, 64),
        'osc' => !empty($s['osc']), 'params' => $params, 'display_name' => mb_substr((string)($s['display_name'] ?? ''), 0, 64),
    ];
    vrc_db()->prepare('INSERT INTO client_state (user_id, client_id, updated, state) VALUES (?, ?, ?, ?) ON CONFLICT(user_id) DO UPDATE SET client_id = excluded.client_id, updated = excluded.updated, state = excluded.state')
        ->execute([$uid, $client, time(), json_encode($clean, JSON_UNESCAPED_UNICODE)]);
}

function nc_state(int $uid): ?array {
    $st = vrc_db()->prepare('SELECT * FROM client_state WHERE user_id = ?');
    $st->execute([$uid]);
    $r = $st->fetch();
    if (!$r) return null;
    $s = json_decode($r['state'], true) ?: [];
    $s['updated'] = (int)$r['updated'];
    $s['fresh'] = (int)$r['updated'] > time() - 90;
    return $s;
}

// ---------- OSC-Warteschlange ----------

/** OSC-Befehl prüfen und in die Warteschlange legen */
function nc_osc_enqueue(int $uid, string $kind, array $in): int {
    if (!in_array($kind, NC_OSC_KINDS, true)) throw new RuntimeException('Unbekannte OSC-Art.');
    switch ($kind) {
        case 'chatbox':
            $text = trim(str_replace(["\r", "\t"], ['', ' '], (string)($in['text'] ?? '')));
            if ($text === '') throw new RuntimeException('Text fehlt.');
            // „/n“ bzw. „\n“ als Zeilenumbruch (nicht in Links)
            $text = preg_replace_callback('~https?://\S+|/n|\\\\n~', function ($m) { return strlen($m[0]) === 2 ? "\n" : $m[0]; }, $text);
            $on = function ($v) { return $v === true || in_array((string)$v, ['1', 'true', 'on'], true); };
            $split = $on($in['split'] ?? '');
            $translate = $on($in['translate'] ?? '');
            if ($translate) {
                require_once __DIR__ . '/translate.php';
                if (tr_provider($uid) !== '') { $text = tr_chat($uid, $text, (string)($in['target'] ?? '')); $translate = false; }   // Server übersetzt selbst
            }
            $payload = ['text' => mb_substr($text, 0, $split ? 1000 : 144), 'sound' => in_array((string)($in['sound'] ?? ''), ['1', 'true', 'on'], true) || ($in['sound'] ?? null) === true, 'split' => $split];
            if ($translate) $payload += ['translate' => true, 'target' => preg_replace('/[^A-Z-]/', '', strtoupper((string)($in['target'] ?? '')))];   // übersetzt die Windows-App
            if (!empty($in['clear']) && $on($in['clear'])) $payload = ['text' => '', 'sound' => false, 'clear' => true];
            if (!empty($in['delay'])) $payload['delay'] = max(0, min(3600, (int)$in['delay']));
            break;
        case 'typing':
            $payload = ['on' => !empty($in['on'])];
            break;
        case 'param':
            $name = trim((string)($in['name'] ?? ''));
            if (!preg_match('/^[\w\/\-. ]{1,64}$/u', $name)) throw new RuntimeException('Ungültiger Parametername.');
            $type = (string)($in['type'] ?? 'bool');
            $v = $in['value'] ?? '';
            if ($type === 'bool') $v = in_array(strtolower((string)$v), ['1', 'true', 'an', 'on', 'ja'], true);
            elseif ($type === 'int') { $v = (int)$v; if ($v < 0 || $v > 255) throw new RuntimeException('Ganzzahl-Parameter: 0 bis 255.'); }
            elseif ($type === 'float') { $v = (float)str_replace(',', '.', (string)$v); if ($v < -1 || $v > 1) throw new RuntimeException('Kommazahl-Parameter: -1 bis 1.'); }
            else throw new RuntimeException('Typ: bool, int oder float.');
            $payload = ['name' => $name, 'type' => $type, 'value' => $v];
            break;
        default: // input
            $name = (string)($in['name'] ?? '');
            $allowed = ['Jump', 'Run', 'Voice', 'QuickMenuToggleLeft', 'MoveForward', 'MoveBackward', 'MoveLeft', 'MoveRight', 'LookLeft', 'LookRight', 'ComfortLeft', 'ComfortRight', 'GrabRight', 'UseRight', 'DropRight', 'PanicButton'];
            if (!in_array($name, $allowed, true)) throw new RuntimeException('Diese Eingabe wird nicht unterstützt.');
            $payload = ['name' => $name, 'value' => (int)($in['value'] ?? 1) ? 1 : 0, 'pulse' => !empty($in['pulse'])];
    }
    $cnt = vrc_db()->prepare("SELECT COUNT(*) FROM osc_queue WHERE user_id = ? AND created > ?");
    $cnt->execute([$uid, time() - 60]);
    if ((int)$cnt->fetchColumn() >= 40) throw new RuntimeException('Zu viele Befehle – bitte kurz warten.');
    vrc_db()->prepare('INSERT INTO osc_queue (user_id, created, kind, payload) VALUES (?, ?, ?, ?)')->execute([$uid, time(), $kind, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    return (int)vrc_db()->lastInsertId();
}

/** Offene Befehle für den Client abholen (älter als 2 Minuten verfallen) */
function nc_osc_take(int $uid, int $client): array {
    $db = vrc_db();
    $db->prepare("UPDATE osc_queue SET status = 'expired', done = ? WHERE user_id = ? AND status = 'pending' AND created < ?")->execute([time(), $uid, time() - 120]);
    $st = $db->prepare("SELECT id, kind, payload, created FROM osc_queue WHERE user_id = ? AND status = 'pending' ORDER BY id LIMIT 20");
    $st->execute([$uid]);
    $rows = $st->fetchAll();
    if ($rows) {
        $ids = array_column($rows, 'id');
        $db->prepare("UPDATE osc_queue SET status = 'sent', client_id = ? WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')')->execute(array_merge([$client], $ids));
    }
    return array_map(function ($r) { return ['id' => (int)$r['id'], 'kind' => $r['kind'], 'payload' => json_decode($r['payload'], true), 'created' => (int)$r['created']]; }, $rows);
}

function nc_osc_ack(int $uid, array $results): void {
    $up = vrc_db()->prepare("UPDATE osc_queue SET status = ?, done = ?, result = ? WHERE id = ? AND user_id = ? AND status = 'sent'");
    foreach (array_slice($results, 0, 50) as $r) {
        if (!is_array($r)) continue;
        $up->execute([!empty($r['ok']) ? 'done' : 'failed', time(), mb_substr((string)($r['error'] ?? ''), 0, 200), (int)($r['id'] ?? 0), $uid]);
    }
}

function nc_osc_recent(int $uid): array {
    $st = vrc_db()->prepare('SELECT id, created, kind, payload, status, result FROM osc_queue WHERE user_id = ? ORDER BY id DESC LIMIT 15');
    $st->execute([$uid]);
    return array_map(function ($r) { return ['id' => (int)$r['id'], 'created' => (int)$r['created'], 'kind' => $r['kind'], 'payload' => json_decode($r['payload'], true), 'status' => $r['status'], 'result' => $r['result']]; }, $st->fetchAll());
}

// ---------- OSC-Vorlagen (Schnellknöpfe) ----------

function nc_presets(int $uid): array {
    $st = vrc_db()->prepare('SELECT id, label, kind, payload FROM osc_presets WHERE user_id = ? ORDER BY sort, id');
    $st->execute([$uid]);
    return array_map(function ($r) { return ['id' => (int)$r['id'], 'label' => $r['label'], 'kind' => $r['kind'], 'payload' => json_decode($r['payload'], true) ?: []]; }, $st->fetchAll());
}

function nc_preset_save(int $uid, string $label, string $kind, array $in): void {
    $label = mb_substr(trim($label), 0, 30);
    if ($label === '') throw new RuntimeException('Bitte eine Beschriftung angeben.');
    if (!in_array($kind, ['chatbox', 'param', 'input'], true)) throw new RuntimeException('Unbekannte Art.');
    $n = vrc_db()->prepare('SELECT COUNT(*) FROM osc_presets WHERE user_id = ?');
    $n->execute([$uid]);
    if ((int)$n->fetchColumn() >= 40) throw new RuntimeException('Maximal 40 Schnellknöpfe.');
    $payload = array_intersect_key($in, array_flip(['text', 'name', 'type', 'value', 'sound', 'pulse']));
    vrc_db()->prepare('INSERT INTO osc_presets (user_id, label, kind, payload, sort) VALUES (?, ?, ?, ?, ?)')->execute([$uid, $label, $kind, json_encode($payload, JSON_UNESCAPED_UNICODE), time()]);
}

function nc_preset_delete(int $uid, int $id): void {
    vrc_db()->prepare('DELETE FROM osc_presets WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
}

/** Spiel-Log als CSV */
function nc_export_csv(int $uid): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vrchat-spiellog_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Zeit', 'Art', 'Welt', 'Welt-ID', 'Instanz', 'Spieler', 'Spieler-ID', 'Details'], ';');
    $st = vrc_db()->prepare('SELECT * FROM game_log WHERE user_id = ? ORDER BY ts, id');
    $st->execute([$uid]);
    foreach ($st as $r) fputcsv($out, [date('d.m.Y H:i:s', (int)$r['ts']), NC_KINDS[$r['kind']][1] ?? $r['kind'], $r['world_name'], $r['world_id'], $r['instance'], $r['player_name'], $r['player_id'], $r['detail']], ';');
    fclose($out);
}

// ---------- Notizen-Abgleich mit der Windows-App ----------

/** Notizen vom Gerät übernehmen, wenn neuer; Server-Notizen seit $since zurückgeben */
function nc_notes_sync(int $uid, int $since, array $notes): array {
    $db = vrc_db();
    $get = $db->prepare('SELECT updated FROM notes WHERE user_id = ? AND vrc_id = ?');
    $put = $db->prepare('INSERT OR REPLACE INTO notes (user_id, vrc_id, note, favorite, updated, tags, watch) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $got = [];
    foreach (array_slice($notes, 0, 2000) as $n) {
        if (!is_array($n) || !preg_match('/^usr_[0-9a-f-]{36}$/i', (string)($n['vrc_id'] ?? ''))) continue;
        $upd = min(time(), (int)($n['updated'] ?? 0));
        $get->execute([$uid, $n['vrc_id']]);
        $cur = $get->fetchColumn();
        if ($cur !== false && (int)$cur >= $upd) continue;
        $put->execute([$uid, $n['vrc_id'], mb_substr((string)($n['note'] ?? ''), 0, 4000), !empty($n['favorite']) ? 1 : 0, $upd, mb_substr((string)($n['tags'] ?? ''), 0, 200), !empty($n['watch']) ? 1 : 0]);
        $got[$n['vrc_id']] = true;
    }
    $st = $db->prepare('SELECT vrc_id, note, favorite, tags, watch, updated FROM notes WHERE user_id = ? AND updated > ? ORDER BY updated LIMIT 2000');
    $st->execute([$uid, $since]);
    $out = array_values(array_filter($st->fetchAll(), function ($r) use ($got) { return !isset($got[$r['vrc_id']]); }));
    return ['notes' => array_map(function ($r) { return ['vrc_id' => $r['vrc_id'], 'note' => $r['note'], 'favorite' => (int)$r['favorite'], 'tags' => $r['tags'], 'watch' => (int)$r['watch'], 'updated' => (int)$r['updated']]; }, $out),
            'received' => count($got), 'time' => time()];
}
