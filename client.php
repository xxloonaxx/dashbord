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
 * <title>Nexus Client API</title>
 */
require_once __DIR__ . '/inc/modules/nexus_client.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function nc_reply(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('getallheaders')) foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $auth = (string)$v;
$token = preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m) ? $m[1] : (string)($_SERVER['HTTP_X_NEXUS_TOKEN'] ?? '');

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
    nc_reply(['error' => 'Unbekanntes oder entferntes Geräte-Token.', 'auth' => true], 401);
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
        default:
            nc_reply(['error' => 'Unbekannte Aktion.'], 404);
    }
} catch (Throwable $e) {
    nc_reply(['error' => $e instanceof RuntimeException ? $e->getMessage() : 'Serverfehler.'], 500);
}
