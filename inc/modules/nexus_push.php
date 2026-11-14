<?php
/**
 * VRC Nexus – Push-Benachrichtigungen ans Handy/PC (Web-Push), auch wenn Nexus nicht offen ist.
 * Läuft als Aufgabe „vrcpush“ (Admin → System → Aufgaben, am besten mit echtem Cron alle 15 min):
 *   gleicht VRChat ab und meldet  🟢 beobachtete Freunde online · 📨 neue VRChat-Benachrichtigungen (Einladungen, Anfragen …) · 📅 Gruppen-Events kurz vorher
 */
require_once __DIR__ . '/vrchat.php';
require_once __DIR__ . '/vrchat_extra.php';
require_once APP_ROOT . '/inc/core/webpush.php';

const NX_PUSH_DEFAULTS = ['watch' => true, 'favorites' => false, 'notifs' => true, 'events' => true, 'events_min' => 30, 'quiet_from' => '', 'quiet_to' => ''];

function nx_push_prefs(int $uid): array {
    $p = json_decode(setting('push_prefs_' . $uid, ''), true);
    return array_merge(NX_PUSH_DEFAULTS, is_array($p) ? $p : []);
}
function nx_push_save_prefs(int $uid, array $in): array {
    $b = fn($k) => !empty($in[$k]) && $in[$k] !== '0';
    $t = fn($k) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($in[$k] ?? '')) ? (string)$in[$k] : '';
    $p = ['watch' => $b('watch'), 'favorites' => $b('favorites'), 'notifs' => $b('notifs'), 'events' => $b('events'),
          'events_min' => max(5, min(240, (int)($in['events_min'] ?? 30))), 'quiet_from' => $t('quiet_from'), 'quiet_to' => $t('quiet_to')];
    set_setting('push_prefs_' . $uid, json_encode($p));
    return $p;
}
/** Ruhezeit (z. B. 23:00–08:00) */
function nx_push_quiet(array $p): bool {
    if ($p['quiet_from'] === '' || $p['quiet_to'] === '') return false;
    $n = date('H:i');
    return $p['quiet_from'] <= $p['quiet_to'] ? ($n >= $p['quiet_from'] && $n < $p['quiet_to']) : ($n >= $p['quiet_from'] || $n < $p['quiet_to']);
}

/** Auslöser für einen Benutzer prüfen → Anzahl verschickter Meldungen */
function nx_push_check(int $uid): int {
    $acc = vrc_account($uid);
    if (!$acc) return 0;
    $p = nx_push_prefs($uid);
    $last = (int)setting('push_last_' . $uid, '0');
    $now = time();
    if ($last === 0 || $last < $now - 6 * 3600) $last = $now - 900;        // nach langer Pause nicht alles nachholen
    try { vrc_sync($uid); } catch (Throwable $e) { /* Abgleich zu früh oder VRChat weg – mit dem Stand arbeiten, der da ist */ }
    $msgs = [];
    $db = vrc_db();
    if ($p['watch'] || $p['favorites']) {
        $st = $db->prepare("SELECT f.ts, f.vrc_id, f.display_name, f.new FROM feed f JOIN notes n ON n.user_id = f.user_id AND n.vrc_id = f.vrc_id
                            WHERE f.user_id = ? AND f.type = 'online' AND f.ts > ? AND (" . ($p['watch'] ? 'n.watch = 1' : '0') . ($p['favorites'] ? ' OR n.favorite = 1' : '') . ') ORDER BY f.ts LIMIT 20');
        $st->execute([$uid, $last]);
        foreach ($st->fetchAll() as $r) {
            $l = vrc_location((string)$r['new']);
            $w = !empty($l['world']) ? (vrc_worlds([$l['world']])[$l['world']]['name'] ?? '') : '';
            $msgs[] = ['title' => '🟢 ' . $r['display_name'] . ' ist im Spiel', 'body' => ($l['kind'] ?? '') === 'world' ? ($w ?: 'In einer Welt') . (!empty($l['access']) ? ' · ' . $l['access'] : '') : (($l['label'] ?? '') ?: 'gerade in VRChat gekommen'), 'tag' => 'online-' . $r['vrc_id'], 'url' => 'nexus.php'];
        }
    }
    if ($p['notifs']) {
        try { vrc_notif_sync($uid, $acc); } catch (Throwable $e) { /* egal */ }
        $st = $db->prepare('SELECT id, type, sender_name, title, message FROM notif_log WHERE user_id = ? AND active = 1 AND seen = 0 AND created > ? ORDER BY created LIMIT 10');
        $st->execute([$uid, $last]);
        $label = ['invite' => 'Einladung', 'requestInvite' => 'Einladungsanfrage', 'friendRequest' => 'Freundschaftsanfrage', 'inviteResponse' => 'Antwort auf Einladung', 'votetokick' => 'Kick-Abstimmung'];
        foreach ($st->fetchAll() as $n) $msgs[] = ['title' => '📨 ' . ($label[$n['type']] ?? ($n['title'] ?: 'VRChat-Benachrichtigung')), 'body' => trim(($n['sender_name'] ? $n['sender_name'] . ': ' : '') . mb_substr((string)$n['message'], 0, 140)), 'tag' => 'notif-' . $n['id'], 'url' => 'nexus.php'];
    }
    if ($p['events']) {
        $done = json_decode(setting('push_ev_' . $uid, '[]'), true) ?: [];
        foreach (vrc_calendar_list($uid) as $e) {
            $s = (int)$e['starts'];
            if ($s > $now && $s - $now <= $p['events_min'] * 60 && !in_array($e['id'], $done, true)) {
                $done[] = $e['id'];
                $msgs[] = ['title' => '📅 ' . $e['title'], 'body' => 'beginnt um ' . date('H:i', $s) . ($e['group_name'] ? ' · ' . $e['group_name'] : ''), 'tag' => 'event-' . $e['id'], 'url' => 'nexus.php'];
            }
        }
        set_setting('push_ev_' . $uid, json_encode(array_slice($done, -200)));
    }
    set_setting('push_last_' . $uid, (string)$now);
    if (!$msgs || nx_push_quiet($p)) return 0;
    $sent = 0;
    // höchstens 5 einzeln, der Rest als Sammelmeldung
    foreach (array_slice($msgs, 0, 5) as $m) $sent += webpush_send_user($uid, $m) ? 1 : 0;
    if (count($msgs) > 5) $sent += webpush_send_user($uid, ['title' => '🔔 ' . (count($msgs) - 5) . ' weitere Meldungen', 'body' => 'in VRC Nexus', 'tag' => 'more', 'url' => 'nexus.php']) ? 1 : 0;
    return $sent;
}

/** Aufgabe: alle Benutzer mit Push-Abo prüfen */
function nx_push_run(): string {
    $uids = webpush_db()->query('SELECT DISTINCT user_id FROM push_subs')->fetchAll(PDO::FETCH_COLUMN);
    $sent = 0;
    foreach ($uids as $uid) { try { $sent += nx_push_check((int)$uid); } catch (Throwable $e) { /* nächster Benutzer */ } }
    return count($uids) . ' Benutzer geprüft, ' . $sent . ' Meldungen verschickt';
}
