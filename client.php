<?php
/**
 * Schnittstelle für den VRC Nexus Desktop-Client (clients/nexus-desktop).
 *
 * Anmeldung: Kopfzeile „Authorization: Bearer gnx_…“ (Geräte-Token aus Nexus → 🖥️ Client).
 * Antworten sind JSON. Keine Sitzung, keine Cookies.
 *
 *   GET  client.php?a=hello              Wer bin ich? Server-Zeit, Abfrage-Intervalle
 *   POST client.php?a=events             {"events":[{ts, kind, world_id, instance, world_name, player_id, player_name, detail}, …]}
 *   POST client.php?a=state              {"state":{running, world_id, instance, world_name, since, players:[{id,name,since}], avatar, avatar_id, osc, params}}
 *   GET  client.php?a=osc                offene OSC-Befehle abholen
 *   POST client.php?a=osc_ack            {"results":[{id, ok, error}]}
 *   POST client.php?a=notes              {"since":ts, "notes":[{vrc_id, note, favorite, tags, watch, updated}]} → neuere Notizen vom Server
 *   POST client.php?a=send               {"kind":"chatbox", "text":…} Befehl an den PC schicken (z. B. vom Handy)
 *   GET  client.php?a=remote             Live-Status des PCs + letzte Befehle (für die Fernbedienung)
 * <title>Nexus Client API</title>
 */
require_once __DIR__ . '/inc/modules/nexus_client.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const NC_BACKUP_MAX_MB = 200;
function nc_backup_dir(int $uid): string {
    $d = __DIR__ . '/data/nexus-backup/u' . $uid;
    if (!is_dir($d)) { mkdir($d, 0700, true); @file_put_contents(dirname($d) . '/.htaccess', "Require all denied\nDeny from all\n"); }
    return $d;
}
function nc_backup_id(string $id): string {
    if (!preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}$/', $id)) nc_reply(['error' => 'Ungültige Kennung.'], 400);
    return $id;
}
function nc_backup_list(int $uid): array {
    $out = [];
    foreach (glob(nc_backup_dir($uid) . '/*.json') ?: [] as $f) {
        $m = json_decode((string)file_get_contents($f), true);
        if (!$m) continue;
        if (empty($m['complete'])) { if ((int)($m['created'] ?? 0) < time() - 86400) { @unlink($f); @unlink(substr($f, 0, -5) . '.part'); } continue; }
        $out[] = ['id' => $m['id'], 'size' => (int)$m['size'], 'created' => (int)$m['created'], 'device' => (string)$m['device'], 'version' => (string)$m['version'], 'note' => (string)($m['note'] ?? '')];
    }
    usort($out, fn($a, $b) => $b['created'] <=> $a['created']);
    return $out;
}
function nc_backup_prune(int $uid, int $keep): void {
    foreach (array_slice(nc_backup_list($uid), $keep) as $b) foreach (['bin', 'json'] as $x) @unlink(nc_backup_dir($uid) . '/' . $b['id'] . '.' . $x);
}

function nc_reply(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('getallheaders')) foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $auth = (string)$v;
$token = preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m) ? $m[1] : '';
// Viele Hoster (PHP als CGI/FastCGI) filtern „Authorization“ heraus – darum schickt der Client das Token auch als X-Nexus-Token
if ($token === '') $token = trim((string)($_SERVER['HTTP_X_NEXUS_TOKEN'] ?? ''));

// Gesperrte IPs ablehnen; falsche Tokens zählen getrennt vom Web-Login (sonst sperrt ein alter Client die Anmeldung)
if (ip_blocked(client_ip())) nc_reply(['error' => 'Zugriff gesperrt.'], 403);
$fail_key = 'client:' . client_ip();
$fails = site_db()->prepare('SELECT count, last FROM login_attempts WHERE ip = ?');
$fails->execute([$fail_key]);
$f = $fails->fetch();
if ($f && $f['count'] >= 20 && $f['last'] > time() - 600) nc_reply(['error' => 'Zu viele Fehlversuche – bitte 10 Minuten warten.'], 429);
$c = nc_auth($token);
if (!$c) {
    site_db()->prepare('INSERT INTO login_attempts (ip, count, last) VALUES (:ip, 1, :t) ON CONFLICT(ip) DO UPDATE SET count = CASE WHEN :t - last > 600 THEN 1 ELSE count + 1 END, last = :t')
        ->execute([':ip' => $fail_key, ':t' => time()]);
    if ($token === '') $why = 'Es kam kein Token beim Server an – der Webserver filtert vermutlich die Anmelde-Kopfzeile. Bitte den Client aktualisieren (schickt das Token zusätzlich als X-Nexus-Token).';
    elseif (!preg_match('/^gnx_[0-9a-f]{40}$/', $token)) $why = 'Das Token hat das falsche Format (gnx_ + 40 Zeichen, ' . strlen($token) . ' Zeichen angekommen) – bitte vollständig kopieren.';
    else $why = 'Unbekanntes oder entferntes Geräte-Token – im Nexus unter 🖥️ Client → Geräte neu erstellen (gehört es zu dieser Seite/Datenbank?).';
    nc_reply(['error' => $why, 'auth' => true], 401);
}
$uid = (int)$c['user']['id'];
$cid = (int)$c['token']['id'];
nc_touch($c['token'], substr((string)($_SERVER['HTTP_X_CLIENT_VERSION'] ?? ''), 0, 20), substr((string)($_SERVER['HTTP_X_CLIENT_PLATFORM'] ?? ''), 0, 40));

$a = (string)($_GET['a'] ?? 'hello');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$body = [];
if ($post) {
    $raw = (string)file_get_contents('php://input', false, null, 0, 2000001);
    if (strlen($raw) > 2000000) nc_reply(['error' => 'Anfrage zu groß.'], 413);
    $body = json_decode($raw, true);
    if (!is_array($body)) nc_reply(['error' => 'Ungültiges JSON.'], 400);
}

try {
    switch ($a) {
        case 'hello':
            nc_reply(['ok' => true, 'user' => ['id' => $uid, 'name' => $c['user']['display_name'] ?: $c['user']['username']], 'device' => $c['token']['name'],
                      'server' => ['title' => setting('site_title', 'GHOST NODE'), 'time' => time()], 'poll' => ['osc' => 3, 'state' => 20, 'events' => 10],
                      'kinds' => array_keys(NC_KINDS)]);
        case 'events':
            if (!$post) nc_reply(['error' => 'Nur per POST.'], 405);
            nc_reply(['ok' => true] + nc_log_store($uid, $cid, (array)($body['events'] ?? [])));
        case 'state':
            if (!$post) nc_reply(['error' => 'Nur per POST.'], 405);
            nc_state_save($uid, $cid, (array)($body['state'] ?? []));
            nc_reply(['ok' => true]);
        case 'osc':
            nc_reply(['ok' => true, 'commands' => nc_osc_take($uid, $cid)]);
        case 'osc_ack':
            if (!$post) nc_reply(['error' => 'Nur per POST.'], 405);
            nc_osc_ack($uid, (array)($body['results'] ?? []));
            nc_reply(['ok' => true]);
        case 'notes':
            if (!$post) nc_reply(['error' => 'Nur per POST.'], 405);
            nc_reply(['ok' => true] + nc_notes_sync($uid, (int)($body['since'] ?? 0), (array)($body['notes'] ?? [])));
        case 'send':
            if (!$post) nc_reply(['error' => 'Nur per POST.'], 405);
            $id = nc_osc_enqueue($uid, (string)($body['kind'] ?? ''), $body);
            nc_reply(['ok' => true, 'id' => $id]);
        case 'remote':
            nc_reply(['ok' => true, 'state' => nc_state($uid), 'osc' => nc_osc_recent($uid), 'presets' => nc_presets($uid)]);
        // ---------- Cloud-Sicherung der App (schon in der App verschlüsselt – der Server sieht nur Zufallsdaten) ----------
        case 'backup_list':
            nc_reply(['ok' => true, 'backups' => nc_backup_list($uid), 'limit_mb' => NC_BACKUP_MAX_MB]);
        case 'backup_begin': {
            if (!$post) nc_reply(['error' => 'Nur per POST.'], 405);
            $size = (int)($body['size'] ?? 0);
            if ($size <= 0 || $size > NC_BACKUP_MAX_MB * 1048576) nc_reply(['error' => 'Sicherung zu groß (höchstens ' . NC_BACKUP_MAX_MB . ' MB).'], 413);
            $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $dir = nc_backup_dir($uid);
            file_put_contents($dir . '/' . $id . '.part', '');
            file_put_contents($dir . '/' . $id . '.json', json_encode(['id' => $id, 'size' => $size, 'sha256' => preg_replace('/[^0-9a-f]/', '', (string)($body['sha256'] ?? '')), 'device' => $c['token']['name'],
                'version' => substr((string)($body['version'] ?? ''), 0, 20), 'note' => mb_substr((string)($body['note'] ?? ''), 0, 80), 'created' => time(), 'complete' => false]));
            nc_reply(['ok' => true, 'id' => $id]);
        }
        case 'backup_chunk': {
            if (!$post) nc_reply(['error' => 'Nur per POST.'], 405);
            $id = nc_backup_id((string)($body['id'] ?? ''));
            $dir = nc_backup_dir($uid);
            $meta = json_decode((string)@file_get_contents($dir . '/' . $id . '.json'), true);
            if (!$meta || !empty($meta['complete']) || !is_file($dir . '/' . $id . '.part')) nc_reply(['error' => 'Unbekannte Sicherung.'], 404);
            $data = base64_decode((string)($body['data'] ?? ''), true);
            if ($data === false) nc_reply(['error' => 'Ungültige Daten.'], 400);
            clearstatcache();
            if (filesize($dir . '/' . $id . '.part') !== (int)($body['offset'] ?? -1)) nc_reply(['error' => 'Reihenfolge stimmt nicht – Sicherung bitte neu starten.'], 409);
            if (filesize($dir . '/' . $id . '.part') + strlen($data) > $meta['size']) nc_reply(['error' => 'Mehr Daten als angekündigt.'], 400);
            file_put_contents($dir . '/' . $id . '.part', $data, FILE_APPEND | LOCK_EX);
            nc_reply(['ok' => true]);
        }
        case 'backup_end': {
            if (!$post) nc_reply(['error' => 'Nur per POST.'], 405);
            $id = nc_backup_id((string)($body['id'] ?? ''));
            $dir = nc_backup_dir($uid);
            $meta = json_decode((string)@file_get_contents($dir . '/' . $id . '.json'), true);
            if (!$meta || !is_file($dir . '/' . $id . '.part')) nc_reply(['error' => 'Unbekannte Sicherung.'], 404);
            clearstatcache();
            if (filesize($dir . '/' . $id . '.part') !== (int)$meta['size'] || ($meta['sha256'] !== '' && hash_file('sha256', $dir . '/' . $id . '.part') !== $meta['sha256'])) {
                @unlink($dir . '/' . $id . '.part'); @unlink($dir . '/' . $id . '.json');
                nc_reply(['error' => 'Sicherung unvollständig oder beschädigt – bitte erneut versuchen.'], 400);
            }
            rename($dir . '/' . $id . '.part', $dir . '/' . $id . '.bin');
            $meta['complete'] = true;
            file_put_contents($dir . '/' . $id . '.json', json_encode($meta));
            nc_backup_prune($uid, max(1, min(20, (int)($body['keep'] ?? 5))));
            nc_reply(['ok' => true, 'backups' => nc_backup_list($uid)]);
        }
        case 'backup_get': {
            $id = nc_backup_id((string)($_GET['id'] ?? ''));
            $f = nc_backup_dir($uid) . '/' . $id . '.bin';
            if (!is_file($f)) nc_reply(['error' => 'Unbekannte Sicherung.'], 404);
            $off = max(0, (int)($_GET['offset'] ?? 0));
            $fh = fopen($f, 'rb'); fseek($fh, $off); $data = fread($fh, 1048576); fclose($fh);
            nc_reply(['ok' => true, 'offset' => $off, 'size' => filesize($f), 'data' => base64_encode((string)$data)]);
        }
        case 'backup_delete': {
            if (!$post) nc_reply(['error' => 'Nur per POST.'], 405);
            $id = nc_backup_id((string)($body['id'] ?? ''));
            foreach (['bin', 'json', 'part'] as $x) @unlink(nc_backup_dir($uid) . '/' . $id . '.' . $x);
            nc_reply(['ok' => true, 'backups' => nc_backup_list($uid)]);
        }
        default:
            nc_reply(['error' => 'Unbekannte Aktion.'], 404);
    }
} catch (Throwable $e) {
    nc_reply(['error' => $e instanceof RuntimeException ? $e->getMessage() : 'Serverfehler.'], 500);
}
