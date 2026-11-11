<?php
/**
 * VRC Nexus – VRChat-Anbindung: Anmeldung (Benutzer/Passwort + 2FA), Freundesliste mit Verlauf (Feed),
 * Statistik (Online-Zeiten, Welten, Aktivität), Welten & Instanzen, Avatare, Moderation,
 * Weltnamen-Zwischenspeicher, eigene Notizen zu Freunden und Gruppenverwaltung.
 *
 * Wichtig:
 *  - Das VRChat-Passwort wird NIE gespeichert. Es wird nur einmal an VRChat geschickt; gespeichert wird danach
 *    nur das Sitzungs-Cookie, verschlüsselt (AES-256-GCM, Schlüssel in data/, Ordner per .htaccess gesperrt).
 *  - Jeder Benutzer des Dashboards verbindet sein eigenes VRChat-Konto.
 *  - Die VRChat-API ist inoffiziell: sparsam abfragen (Freunde höchstens alle 45 s), sonst drohen Sperren.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/format.php';
require_once __DIR__ . '/crypto.php';

const VRC_API_DEFAULT = 'https://api.vrchat.cloud/api/1';
const VRC_SYNC_MIN = 45;           // Sekunden zwischen zwei Freundes-Abgleichen
const VRC_OFFLINE_SYNC = 900;      // Offline-Freunde nur alle 15 min komplett abrufen
const VRC_FEED_KEEP_DAYS = 180;

const VRC_STATUS = ['join me' => ['Komm vorbei', '#42caff'], 'active' => ['Online', '#51e57e'], 'ask me' => ['Frag mich', '#e88134'], 'busy' => ['Beschäftigt', '#ff4f4f'], 'offline' => ['Offline', '#808a9b']];
const VRC_PLATFORM = ['standalonewindows' => 'PC', 'android' => 'Quest / Android', 'ios' => 'iOS', 'web' => 'Website / App'];

final class VrcAuthError extends RuntimeException {}

// ---------- Datenbank ----------

function vrc_db(): PDO {
    static $db = null;
    if ($db) return $db;
    site_db();   // legt data/ samt .htaccess an
    $dir = dirname(__DIR__) . '/data';
    $file = (glob($dir . '/vrcx_*.sqlite') ?: [])[0] ?? $dir . '/vrcx_' . bin2hex(random_bytes(12)) . '.sqlite';
    $db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 5000');
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() < 1) {
        $db->exec("
            CREATE TABLE accounts (
                user_id      INTEGER PRIMARY KEY,              -- Benutzer des Dashboards
                vrc_id       TEXT NOT NULL,
                display_name TEXT NOT NULL DEFAULT '',
                avatar       TEXT NOT NULL DEFAULT '',
                cookies      TEXT NOT NULL,                    -- verschlüsselt
                me_json      TEXT NOT NULL DEFAULT '{}',
                connected_at INTEGER NOT NULL,
                last_sync    INTEGER NOT NULL DEFAULT 0,
                last_offline INTEGER NOT NULL DEFAULT 0,
                last_error   TEXT NOT NULL DEFAULT ''
            );
            CREATE TABLE friends (
                user_id      INTEGER NOT NULL,
                vrc_id       TEXT NOT NULL,
                display_name TEXT NOT NULL DEFAULT '',
                state        TEXT NOT NULL DEFAULT 'offline',   -- online (im Spiel) | active (Website/App) | offline
                status       TEXT NOT NULL DEFAULT '',
                status_desc  TEXT NOT NULL DEFAULT '',
                location     TEXT NOT NULL DEFAULT '',
                platform     TEXT NOT NULL DEFAULT '',
                image        TEXT NOT NULL DEFAULT '',
                avatar_thumb TEXT NOT NULL DEFAULT '',
                tags         TEXT NOT NULL DEFAULT '',
                bio          TEXT NOT NULL DEFAULT '',
                last_login   TEXT NOT NULL DEFAULT '',
                since        INTEGER NOT NULL DEFAULT 0,        -- seit wann im aktuellen Zustand/Ort
                updated      INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, vrc_id)
            );
            CREATE TABLE feed (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      INTEGER NOT NULL,
                ts           INTEGER NOT NULL,
                type         TEXT NOT NULL,                     -- online | offline | active | gps | status | avatar | name | bio | friend_add | friend_remove
                vrc_id       TEXT NOT NULL,
                display_name TEXT NOT NULL DEFAULT '',
                old          TEXT NOT NULL DEFAULT '',
                new          TEXT NOT NULL DEFAULT ''
            );
            CREATE INDEX idx_feed ON feed(user_id, ts);
            CREATE TABLE notes (
                user_id  INTEGER NOT NULL,
                vrc_id   TEXT NOT NULL,
                note     TEXT NOT NULL DEFAULT '',
                favorite INTEGER NOT NULL DEFAULT 0,
                updated  INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, vrc_id)
            );
            CREATE TABLE worlds (
                world_id TEXT PRIMARY KEY,
                name     TEXT NOT NULL DEFAULT '',
                thumb    TEXT NOT NULL DEFAULT '',
                author   TEXT NOT NULL DEFAULT '',
                capacity INTEGER NOT NULL DEFAULT 0,
                fetched  INTEGER NOT NULL DEFAULT 0
            );
            PRAGMA user_version = 1;
        ");
    }
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() < 2) {
        $db->exec("
            ALTER TABLE friends ADD COLUMN fav_group TEXT NOT NULL DEFAULT '';        -- VRChat-Favoritengruppe (group_0 …)
            ALTER TABLE friends ADD COLUMN friend_since INTEGER NOT NULL DEFAULT 0;   -- seit wann in der Liste (0 = vor der Aufzeichnung)
            ALTER TABLE accounts ADD COLUMN my_location TEXT NOT NULL DEFAULT '';
            ALTER TABLE accounts ADD COLUMN fav_groups TEXT NOT NULL DEFAULT '{}';
            CREATE TABLE stats (user_id INTEGER NOT NULL, ts INTEGER NOT NULL, online INTEGER NOT NULL, active INTEGER NOT NULL, total INTEGER NOT NULL);
            CREATE INDEX idx_stats ON stats(user_id, ts);
            CREATE INDEX idx_feed_friend ON feed(user_id, vrc_id, ts);
            PRAGMA user_version = 2;
        ");
    }
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() < 3) {
        $db->exec("
            ALTER TABLE notes ADD COLUMN tags TEXT NOT NULL DEFAULT '';      -- eigene Listen, kommagetrennt
            ALTER TABLE notes ADD COLUMN watch INTEGER NOT NULL DEFAULT 0;   -- benachrichtigen, wenn online
            PRAGMA user_version = 3;
        ");
    }
    return $db;
}

// ---------- Verschlüsselung der Sitzungs-Cookies ----------

function vrc_encrypt(array $data): string {
    return crypto_encrypt('vrc', $data);
}

function vrc_decrypt(string $s): array {
    $d = crypto_decrypt('vrc', $s);
    return is_array($d) ? $d : [];
}

// ---------- HTTP ----------

function vrc_api_base(): string {
    return rtrim(setting('vrc_api_base', VRC_API_DEFAULT), '/');
}

function vrc_user_agent(): string {
    $ua = trim(setting('vrc_user_agent', ''));
    return $ua !== '' ? $ua : 'GhostNode-VRCNexus/3.0';
}

/**
 * Anfrage an die VRChat-API. $cookies wird um neue Set-Cookie-Werte ergänzt.
 * Rückgabe: [HTTP-Code, JSON (Array) oder null]
 */
function vrc_http(array &$cookies, string $method, string $path, array $query = [], $body = null, array $headers = []): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Auf dem Server fehlt die PHP-Erweiterung cURL.');
    $url = vrc_api_base() . $path . ($query ? '?' . http_build_query($query) : '');
    $headers[] = 'Accept: application/json';
    $jar = [];
    foreach ($cookies as $k => $v) if ($v !== '') $jar[] = $k . '=' . $v;
    if ($jar) $headers[] = 'Cookie: ' . implode('; ', $jar);
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => vrc_user_agent(),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$cookies) {
            if (preg_match('/^set-cookie:\s*(auth|twoFactorAuth)=([^;]*)/i', $line, $m)) $cookies[$m[1]] = $m[2];
            return strlen($line);
        },
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('VRChat ist nicht erreichbar (' . $err . '). Blockiert der Hoster ausgehende Verbindungen?');
    $json = json_decode((string)$raw, true);
    return [$code, is_array($json) ? $json : null];
}

function vrc_error_text(int $code, ?array $json): string {
    $msg = $json['error']['message'] ?? ($json['message'] ?? '');
    $msg = trim(is_string($msg) ? $msg : json_encode($msg), "\" ");
    $hints = [401 => 'Nicht (mehr) angemeldet.', 403 => 'Keine Berechtigung dafür.', 404 => 'Nicht gefunden.', 429 => 'Zu viele Anfragen – VRChat bremst gerade. Bitte kurz warten.',
              500 => 'Fehler bei VRChat.', 502 => 'VRChat ist gerade überlastet.', 503 => 'VRChat ist gerade nicht verfügbar.'];
    return ($hints[$code] ?? 'Fehler ' . $code . '.') . ($msg !== '' ? ' (' . $msg . ')' : '');
}

// ---------- Anmeldung ----------

/** Schritt 1: Benutzername + Passwort. Liefert ['user' => …] oder ['need2fa' => [...], 'cookies' => …] */
function vrc_login(string $username, string $password): array {
    $cookies = [];
    [$code, $json] = vrc_http($cookies, 'GET', '/auth/user', [], null, ['Authorization: Basic ' . base64_encode(rawurlencode($username) . ':' . rawurlencode($password))]);
    if ($code === 401) throw new RuntimeException('VRChat: Benutzername/E-Mail oder Passwort falsch.');
    if ($code === 429) throw new RuntimeException('VRChat: Zu viele Anmeldeversuche – bitte einige Minuten warten.');
    if ($code !== 200 || !$json) throw new RuntimeException('VRChat-Anmeldung fehlgeschlagen: ' . vrc_error_text($code, $json));
    if (empty($cookies['auth'])) throw new RuntimeException('VRChat hat kein Sitzungs-Cookie geschickt – bitte später erneut versuchen.');
    if (!empty($json['requiresTwoFactorAuth'])) return ['need2fa' => array_values((array)$json['requiresTwoFactorAuth']), 'cookies' => $cookies];
    return ['user' => $json, 'cookies' => $cookies];
}

/** Schritt 2: 2FA-Code prüfen (totp = Authenticator-App, otp = Wiederherstellungscode, emailOtp = Code aus der E-Mail) */
function vrc_verify_2fa(array &$cookies, string $type, string $code): array {
    $paths = ['totp' => '/auth/twofactorauth/totp/verify', 'otp' => '/auth/twofactorauth/otp/verify', 'emailOtp' => '/auth/twofactorauth/emailotp/verify'];
    if (!isset($paths[$type])) throw new RuntimeException('Unbekannte 2FA-Art.');
    $code = preg_replace('/\s+/', '', $code);
    [$st, $json] = vrc_http($cookies, 'POST', $paths[$type], [], ['code' => $code]);
    if ($st === 429) throw new RuntimeException('Zu viele Versuche – bitte kurz warten.');
    if ($st !== 200 || empty($json['verified'])) throw new RuntimeException('Der Code stimmt nicht (oder ist abgelaufen). Bitte erneut versuchen.');
    [$st, $user] = vrc_http($cookies, 'GET', '/auth/user');
    if ($st !== 200 || empty($user['id'])) throw new RuntimeException('Anmeldung nach 2FA fehlgeschlagen: ' . vrc_error_text($st, $user));
    return $user;
}

function vrc_save_account(int $uid, array $user, array $cookies): void {
    // Anderes VRChat-Konto als vorher? Dann alte Freunde/Verlauf nicht vermischen
    $st = vrc_db()->prepare('SELECT vrc_id FROM accounts WHERE user_id = ?');
    $st->execute([$uid]);
    $prev = $st->fetchColumn();
    $known = vrc_db()->prepare('SELECT COUNT(*) FROM friends WHERE user_id = ?');
    $known->execute([$uid]);
    if (($prev && $prev !== $user['id']) || (!$prev && (int)$known->fetchColumn() > 0 && setting('vrc_last_' . $uid) !== $user['id'])) {
        foreach (['friends', 'feed', 'notes'] as $t) vrc_db()->prepare("DELETE FROM $t WHERE user_id = ?")->execute([$uid]);
    }
    set_setting('vrc_last_' . $uid, $user['id']);
    vrc_db()->prepare('INSERT INTO accounts (user_id, vrc_id, display_name, avatar, cookies, me_json, connected_at) VALUES (?, ?, ?, ?, ?, ?, ?)
                       ON CONFLICT(user_id) DO UPDATE SET vrc_id = excluded.vrc_id, display_name = excluded.display_name, avatar = excluded.avatar,
                       cookies = excluded.cookies, me_json = excluded.me_json, connected_at = excluded.connected_at, last_error = \'\'')
            ->execute([$uid, $user['id'], $user['displayName'] ?? '', vrc_user_image($user), vrc_encrypt($cookies), json_encode(vrc_me_slim($user), JSON_UNESCAPED_UNICODE), time()]);
}

function vrc_account(int $uid): ?array {
    $st = vrc_db()->prepare('SELECT * FROM accounts WHERE user_id = ?');
    $st->execute([$uid]);
    $a = $st->fetch();
    if (!$a) return null;
    $a['cookie_jar'] = vrc_decrypt($a['cookies']);
    $a['me'] = json_decode($a['me_json'], true) ?: [];
    return $a;
}

function vrc_disconnect(int $uid, bool $remote = true): void {
    $a = vrc_account($uid);
    if ($a && $remote && $a['cookie_jar']) { try { vrc_http($a['cookie_jar'], 'PUT', '/logout'); } catch (Throwable $e) {} }
    vrc_db()->prepare('DELETE FROM accounts WHERE user_id = ?')->execute([$uid]);
}

/** API-Aufruf im Namen des verbundenen Kontos; bei abgelaufener Sitzung VrcAuthError */
function vrc_call(array &$acc, string $method, string $path, array $query = [], $body = null) {
    $before = $acc['cookie_jar'];
    [$code, $json] = vrc_http($acc['cookie_jar'], $method, $path, $query, $body);
    if ($acc['cookie_jar'] !== $before) vrc_db()->prepare('UPDATE accounts SET cookies = ? WHERE user_id = ?')->execute([vrc_encrypt($acc['cookie_jar']), $acc['user_id']]);
    if ($code === 401 && strpos($path, '/auth/user') === 0) {
        vrc_db()->prepare("UPDATE accounts SET last_error = 'Sitzung abgelaufen' WHERE user_id = ?")->execute([$acc['user_id']]);
        throw new VrcAuthError('Die VRChat-Sitzung ist abgelaufen – bitte neu anmelden.');
    }
    if ($code < 200 || $code >= 300) throw new RuntimeException(vrc_error_text($code, $json));
    return $json ?? [];
}

// ---------- Hilfen ----------

function vrc_user_image(array $u): string {
    foreach (['userIcon', 'profilePicOverrideThumbnail', 'profilePicOverride', 'currentAvatarThumbnailImageUrl', 'thumbnailUrl', 'currentAvatarImageUrl', 'imageUrl'] as $k) {
        if (!empty($u[$k]) && preg_match('#^https://#', $u[$k])) return $u[$k];
    }
    return '';
}

function vrc_me_slim(array $u): array {
    return array_intersect_key($u, array_flip(['id', 'displayName', 'status', 'statusDescription', 'bio', 'tags', 'last_platform', 'pronouns', 'date_joined',
        'currentAvatarThumbnailImageUrl', 'userIcon', 'profilePicOverride', 'friends', 'onlineFriends', 'activeFriends', 'offlineFriends', 'presence', 'statusHistory',
        'currentAvatar', 'location', 'bioLinks', 'last_login']));
}

/** Vertrauensstufe aus den Tags */
function vrc_trust(array $tags): array {
    if (array_filter($tags, function ($t) { return strpos($t, 'admin_') === 0; })) return ['VRChat-Team', '#ff2626'];
    if (in_array('system_troll', $tags, true)) return ['Nuisance', '#782f2f'];
    foreach ([['system_trust_veteran', 'Trusted User', '#8143e6'], ['system_trust_trusted', 'Known User', '#ff7b42'],
              ['system_trust_known', 'User', '#2bcf5c'], ['system_trust_basic', 'New User', '#1778ff']] as [$t, $n, $c]) {
        if (in_array($t, $tags, true)) return [$n, $c];
    }
    return ['Visitor', '#cccccc'];
}

/** Ort zerlegen: wrld_x:12345~friends(usr_y)~region(eu) */
function vrc_location(string $loc): array {
    $loc = trim($loc);
    if ($loc === '' || $loc === 'offline') return ['kind' => 'offline', 'label' => 'Offline'];
    if ($loc === 'private') return ['kind' => 'private', 'label' => 'Private Welt'];
    if (strpos($loc, 'traveling') === 0) return ['kind' => 'traveling', 'label' => 'Unterwegs …', 'world' => explode(':', substr($loc, 10))[0] ?? ''];
    $parts = explode(':', $loc, 2);
    $world = $parts[0];
    $inst = $parts[1] ?? '';
    $access = 'Public';
    if (strpos($inst, '~hidden(') !== false) $access = 'Friends+';
    elseif (strpos($inst, '~friends(') !== false) $access = 'Friends';
    elseif (strpos($inst, '~private(') !== false) $access = strpos($inst, '~canRequestInvite') !== false ? 'Invite+' : 'Invite';
    if (preg_match('/~groupAccessType\((\w+)\)/', $inst, $m)) $access = ['public' => 'Gruppe öffentlich', 'plus' => 'Group+', 'members' => 'Gruppe'][$m[1]] ?? 'Gruppe';
    elseif (strpos($inst, '~group(') !== false) $access = 'Gruppe';
    $region = preg_match('/~region\((\w+)\)/', $inst, $m) ? strtoupper($m[1]) : 'US';
    $name = explode('~', $inst)[0];
    return ['kind' => 'world', 'world' => $world, 'instance' => $inst, 'name' => $name, 'access' => $access, 'region' => $region,
            'group' => preg_match('/~group\((grp_[\w-]+)\)/', $inst, $m) ? $m[1] : '', 'location' => $loc,
            'label' => $access . ' · #' . $name . ' · ' . $region];
}

function vrc_worlds(array $ids): array {
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) return [];
    $st = vrc_db()->prepare('SELECT * FROM worlds WHERE world_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    $out = [];
    foreach ($st as $w) $out[$w['world_id']] = ['name' => $w['name'], 'thumb' => $w['thumb'], 'author' => $w['author'], 'capacity' => (int)$w['capacity']];
    return $out;
}

/** Unbekannte Weltnamen nachladen (höchstens $max pro Aufruf, damit VRChat nicht gebremst wird) */
function vrc_fetch_worlds(array &$acc, array $ids, int $max = 12): void {
    $known = vrc_worlds($ids);
    $ins = vrc_db()->prepare('INSERT OR REPLACE INTO worlds (world_id, name, thumb, author, capacity, fetched) VALUES (?, ?, ?, ?, ?, ?)');
    foreach (array_unique($ids) as $id) {
        if ($max <= 0) break;
        if (!preg_match('/^wrld_[\w-]+$/', $id) || isset($known[$id])) continue;
        $max--;
        try {
            $w = vrc_call($acc, 'GET', '/worlds/' . rawurlencode($id));
            $ins->execute([$id, $w['name'] ?? '?', $w['thumbnailImageUrl'] ?? '', $w['authorName'] ?? '', (int)($w['capacity'] ?? 0), time()]);
        } catch (VrcAuthError $e) {
            throw $e;
        } catch (Throwable $e) {
            $ins->execute([$id, '(unbekannte Welt)', '', '', 0, time()]);
        }
    }
}

// ---------- Abgleich der Freundesliste + Verlauf ----------

/** Alle Seiten einer Freundesliste holen */
function vrc_fetch_friends(array &$acc, bool $offline, int $max_pages): array {
    $all = [];
    for ($p = 0; $p < $max_pages; $p++) {
        $page = vrc_call($acc, 'GET', '/auth/user/friends', ['offline' => $offline ? 'true' : 'false', 'n' => 100, 'offset' => $p * 100]);
        if (!is_array($page) || !$page) break;
        foreach ($page as $f) if (!empty($f['id'])) $all[$f['id']] = $f;
        if (count($page) < 100) break;
    }
    return $all;
}

/**
 * Freunde abgleichen und Änderungen in den Verlauf schreiben.
 * Liefert ['synced' => bool, 'wait' => Sekunden bis zum nächsten erlaubten Abgleich]
 */
function vrc_sync(int $uid, bool $force = false): array {
    $acc = vrc_account($uid);
    if (!$acc) throw new VrcAuthError('Kein VRChat-Konto verbunden.');
    $age = time() - (int)$acc['last_sync'];
    if ($age < ($force ? 15 : VRC_SYNC_MIN)) return ['synced' => false, 'wait' => ($force ? 15 : VRC_SYNC_MIN) - $age];
    $db = vrc_db();
    $db->prepare('UPDATE accounts SET last_sync = ? WHERE user_id = ?')->execute([time(), $uid]);   // auch bei Fehlern nicht sofort erneut

    $me = vrc_call($acc, 'GET', '/auth/user');
    if (empty($me['id'])) throw new VrcAuthError('Die VRChat-Sitzung ist abgelaufen – bitte neu anmelden.');
    $online_ids = array_flip((array)($me['onlineFriends'] ?? []));
    $active_ids = array_flip((array)($me['activeFriends'] ?? []));
    $offline_ids = array_flip((array)($me['offlineFriends'] ?? []));
    $all_ids = array_flip((array)($me['friends'] ?? []));

    $st = $db->prepare('SELECT * FROM friends WHERE user_id = ?');
    $st->execute([$uid]);
    $old = [];
    foreach ($st as $r) $old[$r['vrc_id']] = $r;
    $first = !$old;

    $fetched = vrc_fetch_friends($acc, false, 10);
    $unknown = array_diff_key($all_ids, $old, $fetched);
    $need_offline = $first || $unknown || time() - (int)$acc['last_offline'] > VRC_OFFLINE_SYNC;
    if ($need_offline) {
        $fetched += vrc_fetch_friends($acc, true, 30);
        $db->prepare('UPDATE accounts SET last_offline = ? WHERE user_id = ?')->execute([time(), $uid]);
    }

    $now = time();
    $feed = $db->prepare('INSERT INTO feed (user_id, ts, type, vrc_id, display_name, old, new) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $up = $db->prepare('INSERT OR REPLACE INTO friends (user_id, vrc_id, display_name, state, status, status_desc, location, platform, image, avatar_thumb, tags, bio, last_login, since, updated, fav_group, friend_since)
                        VALUES (:u, :id, :name, :state, :status, :sd, :loc, :pf, :img, :av, :tags, :bio, :ll, :since, :upd, :fg, :fs)');
    $log = function ($type, $id, $name, $o = '', $n = '') use ($feed, $uid, $now, $first) { if (!$first) $feed->execute([$uid, $now, $type, $id, $name, (string)$o, (string)$n]); };
    $worlds = [];
    $db->beginTransaction();
    foreach ($fetched as $id => $f) {
        $loc = (string)($f['location'] ?? '');
        if (isset($online_ids[$id])) $state = 'online';
        elseif (isset($active_ids[$id])) $state = 'active';
        elseif (isset($offline_ids[$id])) $state = 'offline';
        else $state = ($loc !== '' && $loc !== 'offline') ? 'online' : (($f['status'] ?? 'offline') === 'offline' ? 'offline' : 'active');
        if ($state === 'online' && ($loc === '' || $loc === 'offline')) $loc = 'private';
        if ($state !== 'online') $loc = 'offline';
        $row = ['name' => (string)($f['displayName'] ?? ''), 'state' => $state, 'status' => (string)($f['status'] ?? ''), 'sd' => (string)($f['statusDescription'] ?? ''),
                'loc' => $loc, 'pf' => (string)($f['last_platform'] ?? ($f['platform'] ?? '')), 'img' => vrc_user_image($f), 'av' => (string)($f['currentAvatarThumbnailImageUrl'] ?? ''),
                'tags' => implode(' ', (array)($f['tags'] ?? [])), 'bio' => mb_substr((string)($f['bio'] ?? ''), 0, 2000), 'll' => (string)($f['last_login'] ?? '')];
        $o = $old[$id] ?? null;
        if ($o && strpos($loc, 'traveling') === 0 && $o['state'] === 'online') $row['loc'] = $loc = $o['location'];   // unterwegs: alten Ort behalten
        $since = $o ? (int)$o['since'] : $now;
        if ($o) {
            if ($o['state'] !== $state) {
                $log($state === 'online' ? 'online' : ($state === 'offline' ? 'offline' : 'active'), $id, $row['name'], $o['state'] === 'online' ? $o['location'] : $o['state'], $state === 'online' ? $loc : $state);
                $since = $now;
            } elseif ($state === 'online' && $o['location'] !== $loc && strpos($loc, 'traveling') !== 0) {
                $log('gps', $id, $row['name'], $o['location'], $loc);
                $since = $now;
            }
            $both_on = $o['state'] !== 'offline' && $state !== 'offline';   // beim Ein-/Ausloggen keinen extra Status-Eintrag
            if ($o['status'] !== $row['status'] && $row['status'] !== 'offline' && $o['status'] !== '' && $both_on) $log('status', $id, $row['name'], $o['status'] . '|' . $o['status_desc'], $row['status'] . '|' . $row['sd']);
            elseif ($o['status_desc'] !== $row['sd'] && $both_on) $log('status', $id, $row['name'], $o['status'] . '|' . $o['status_desc'], $row['status'] . '|' . $row['sd']);
            if ($o['avatar_thumb'] !== '' && $row['av'] !== '' && $o['avatar_thumb'] !== $row['av']) $log('avatar', $id, $row['name'], $o['avatar_thumb'], $row['av']);
            if ($o['display_name'] !== $row['name'] && $o['display_name'] !== '') $log('name', $id, $row['name'], $o['display_name'], $row['name']);
            if ($o['bio'] !== $row['bio'] && $o['bio'] !== '' && $row['bio'] !== '') $log('bio', $id, $row['name'], $o['bio'], $row['bio']);
            $ot = vrc_trust($o['tags'] !== '' ? explode(' ', $o['tags']) : [])[0];
            $nt = vrc_trust((array)($f['tags'] ?? []))[0];
            if ($o['tags'] !== '' && $ot !== $nt) $log('trust', $id, $row['name'], $ot, $nt);
        } elseif (!$first) {
            $log('friend_add', $id, $row['name']);
        }
        if ($state === 'online') { $l = vrc_location($loc); if (!empty($l['world'])) $worlds[] = $l['world']; }
        $up->execute(['u' => $uid, 'id' => $id, 'since' => $since, 'upd' => $now, 'fg' => $o['fav_group'] ?? '', 'fs' => $o ? (int)$o['friend_since'] : ($first ? 0 : $now)] + $row);
    }
    // Freunde, die nicht mitgeladen wurden, aber laut /auth/user offline sind
    foreach ($old as $id => $o) {
        if (isset($fetched[$id])) continue;
        if ($all_ids && !isset($all_ids[$id])) {
            $log('friend_remove', $id, $o['display_name']);
            $db->prepare('DELETE FROM friends WHERE user_id = ? AND vrc_id = ?')->execute([$uid, $id]);
            continue;
        }
        if ($o['state'] !== 'offline' && isset($offline_ids[$id])) {
            $log('offline', $id, $o['display_name'], $o['state'] === 'online' ? $o['location'] : $o['state'], 'offline');
            $db->prepare("UPDATE friends SET state = 'offline', location = 'offline', since = ?, updated = ? WHERE user_id = ? AND vrc_id = ?")->execute([$now, $now, $uid, $id]);
        }
    }
    // Eigener Ort (wenn man selbst im Spiel ist)
    $myloc = (string)($me['location'] ?? '');
    if (strpos($myloc, 'traveling') !== 0 && $myloc !== (string)$acc['my_location']) {
        if ($acc['my_location'] !== '' && !$first && ($myloc !== '' || $acc['my_location'] !== 'offline')) {
            $feed->execute([$uid, $now, 'self', $me['id'], $me['displayName'] ?? '', (string)$acc['my_location'], $myloc ?: 'offline']);
        }
        $db->prepare('UPDATE accounts SET my_location = ? WHERE user_id = ?')->execute([$myloc ?: 'offline', $uid]);
    }
    $cnt = ['online' => 0, 'active' => 0];
    foreach ($fetched as $id => $f) { if (isset($online_ids[$id])) $cnt['online']++; elseif (isset($active_ids[$id])) $cnt['active']++; }
    $db->prepare('INSERT INTO stats (user_id, ts, online, active, total) VALUES (?, ?, ?, ?, ?)')->execute([$uid, $now, count($online_ids) ?: $cnt['online'], count($active_ids) ?: $cnt['active'], count($all_ids) ?: count($fetched)]);
    $db->prepare('DELETE FROM stats WHERE user_id = ? AND ts < ?')->execute([$uid, $now - 90 * 86400]);
    $db->prepare("DELETE FROM feed WHERE user_id = ? AND ts < ?")->execute([$uid, $now - VRC_FEED_KEEP_DAYS * 86400]);
    $db->prepare("UPDATE accounts SET me_json = ?, display_name = ?, avatar = ?, last_error = '' WHERE user_id = ?")
       ->execute([json_encode(vrc_me_slim($me), JSON_UNESCAPED_UNICODE), $me['displayName'] ?? '', vrc_user_image($me), $uid]);
    $db->commit();

    $l = vrc_location((string)($me['location'] ?? ''));
    if (!empty($l['world'])) $worlds[] = $l['world'];
    if ($need_offline) vrc_sync_favorites($acc);
    vrc_fetch_worlds($acc, $worlds);
    return ['synced' => true, 'wait' => VRC_SYNC_MIN];
}

/** VRChat-Favoriten (Freunde) und Namen der Favoritengruppen übernehmen – nicht schlimm, wenn es scheitert */
function vrc_sync_favorites(array &$acc): void {
    try {
        $favs = vrc_call($acc, 'GET', '/favorites', ['type' => 'friend', 'n' => 400]);
        $groups = vrc_call($acc, 'GET', '/favorite/groups', ['type' => 'friend', 'n' => 50]);
    } catch (VrcAuthError $e) {
        throw $e;
    } catch (Throwable $e) {
        return;
    }
    $names = [];
    foreach ((array)$groups as $g) if (!empty($g['name'])) $names[$g['name']] = (string)($g['displayName'] ?? $g['name']);
    $db = vrc_db();
    $db->beginTransaction();
    $db->prepare("UPDATE friends SET fav_group = '' WHERE user_id = ?")->execute([$acc['user_id']]);
    $up = $db->prepare('UPDATE friends SET fav_group = ? WHERE user_id = ? AND vrc_id = ?');
    foreach ((array)$favs as $f) if (!empty($f['favoriteId'])) $up->execute([(string)(((array)($f['tags'] ?? []))[0] ?? 'group_0'), $acc['user_id'], $f['favoriteId']]);
    $db->prepare('UPDATE accounts SET fav_groups = ? WHERE user_id = ?')->execute([json_encode($names, JSON_UNESCAPED_UNICODE), $acc['user_id']]);
    $db->commit();
}

/** Freundesliste für die Anzeige */
function vrc_friend_list(int $uid): array {
    $st = vrc_db()->prepare('SELECT f.*, n.note, n.favorite, n.tags AS ltags, n.watch FROM friends f LEFT JOIN notes n ON n.user_id = f.user_id AND n.vrc_id = f.vrc_id WHERE f.user_id = ? ORDER BY f.display_name COLLATE NOCASE');
    $st->execute([$uid]);
    $rows = $st->fetchAll();
    $worlds = vrc_worlds(array_map(function ($r) { return vrc_location($r['location'])['world'] ?? ''; }, $rows));
    return array_map(function ($r) use ($worlds) {
        $loc = vrc_location($r['location']);
        if (!empty($loc['world'])) $loc['world_name'] = $worlds[$loc['world']]['name'] ?? '';
        if (!empty($loc['world'])) $loc['world_thumb'] = $worlds[$loc['world']]['thumb'] ?? '';
        $tags = $r['tags'] !== '' ? explode(' ', $r['tags']) : [];
        return ['id' => $r['vrc_id'], 'name' => $r['display_name'], 'state' => $r['state'], 'status' => $r['status'], 'status_desc' => $r['status_desc'],
                'loc' => $loc, 'platform' => VRC_PLATFORM[$r['platform']] ?? $r['platform'], 'image' => $r['image'], 'trust' => vrc_trust($tags),
                'since' => (int)$r['since'], 'note' => (string)$r['note'], 'favorite' => (bool)$r['favorite'], 'last_login' => $r['last_login'],
                'pf' => $r['platform'], 'fav_group' => $r['fav_group'], 'friend_since' => (int)$r['friend_since'], 'status_key' => $r['status'],
                'lists' => array_values(array_filter(array_map('trim', explode(',', (string)$r['ltags'])), 'strlen')), 'watch' => (bool)$r['watch']];
    }, $rows);
}

/** Verlauf, neueste zuerst */
function vrc_feed(int $uid, array $f = []): array {
    $sql = 'SELECT * FROM feed WHERE user_id = :u';
    $p = ['u' => $uid];
    if (!empty($f['type'])) { $sql .= ' AND type IN (' . implode(',', array_map(function ($i) { return ':t' . $i; }, array_keys((array)$f['type']))) . ')'; foreach ((array)$f['type'] as $i => $t) $p['t' . $i] = $t; }
    if (!empty($f['friend'])) { $sql .= ' AND vrc_id = :fr'; $p['fr'] = $f['friend']; }
    if (!empty($f['q'])) { $sql .= ' AND (display_name LIKE :q OR new LIKE :q OR old LIKE :q)'; $p['q'] = '%' . $f['q'] . '%'; }
    if (!empty($f['before'])) { $sql .= ' AND id < :b'; $p['b'] = (int)$f['before']; }
    if (!empty($f['favorites'])) $sql .= ' AND vrc_id IN (SELECT vrc_id FROM notes WHERE user_id = :u AND favorite = 1)';
    $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(200, (int)($f['limit'] ?? 80)));
    $st = vrc_db()->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll();
    $wids = [];
    foreach ($rows as $r) foreach (['old', 'new'] as $k) { $l = vrc_location($r[$k]); if (!empty($l['world'])) $wids[] = $l['world']; }
    $worlds = vrc_worlds($wids);
    return array_map(function ($r) use ($worlds) {
        $out = ['id' => (int)$r['id'], 'ts' => (int)$r['ts'], 'type' => $r['type'], 'vrc_id' => $r['vrc_id'], 'name' => $r['display_name'], 'old' => $r['old'], 'new' => $r['new']];
        foreach (['old', 'new'] as $k) {
            if (strpos($r[$k], 'wrld_') === 0 || $r[$k] === 'private') {
                $l = vrc_location($r[$k]);
                $l['world_name'] = $worlds[$l['world'] ?? '']['name'] ?? '';
                $out[$k . '_loc'] = $l;
            }
        }
        return $out;
    }, $rows);
}

function vrc_note(int $uid, string $id): array {
    $st = vrc_db()->prepare('SELECT note, favorite, tags, watch FROM notes WHERE user_id = ? AND vrc_id = ?');
    $st->execute([$uid, $id]);
    return $st->fetch() ?: ['note' => '', 'favorite' => 0, 'tags' => '', 'watch' => 0];
}

function vrc_note_save(int $uid, string $id, string $note, bool $fav, ?string $tags = null, ?bool $watch = null): void {
    $old = vrc_note($uid, $id);
    $tags = $tags === null ? (string)$old['tags'] : implode(', ', array_slice(array_unique(array_filter(array_map(function ($t) { return mb_substr(trim($t), 0, 30); }, explode(',', $tags)), 'strlen')), 0, 10));
    vrc_db()->prepare('INSERT OR REPLACE INTO notes (user_id, vrc_id, note, favorite, updated, tags, watch) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$uid, $id, mb_substr($note, 0, 4000), $fav ? 1 : 0, time(), $tags, $watch === null ? (int)$old['watch'] : ($watch ? 1 : 0)]);
}

/** Zuletzt besuchte eigene Instanzen (aus dem Verlauf „Meine Orte“) */
function vrc_recent_instances(int $uid, int $limit = 30): array {
    $st = vrc_db()->prepare("SELECT ts, new FROM feed WHERE user_id = ? AND type = 'self' AND new LIKE 'wrld_%' ORDER BY id DESC LIMIT 300");
    $st->execute([$uid]);
    $seen = [];
    foreach ($st as $r) {
        if (isset($seen[$r['new']])) { $seen[$r['new']]['visits']++; continue; }
        if (count($seen) >= $limit) continue;
        $seen[$r['new']] = ['location' => $r['new'], 'ts' => (int)$r['ts'], 'visits' => 1];
    }
    $worlds = vrc_worlds(array_map(function ($x) { return explode(':', $x['location'])[0]; }, $seen));
    return array_values(array_map(function ($x) use ($worlds) {
        $l = vrc_location($x['location']);
        return $x + ['loc' => $l, 'world_name' => $worlds[$l['world']]['name'] ?? 'Welt', 'thumb' => $worlds[$l['world']]['thumb'] ?? ''];
    }, $seen));
}

/** Kurzinfo für die Kachel im Index */
function vrc_live_info(int $uid): string {
    try {
        $st = vrc_db()->prepare("SELECT SUM(state = 'online'), SUM(state = 'active'), COUNT(*) FROM friends WHERE user_id = ?");
        $st->execute([$uid]);
        [$on, $act, $all] = array_map('intval', $st->fetch(PDO::FETCH_NUM));
        if (!vrc_account($uid)) return 'Nicht verbunden';
        return $all ? "🟢 $on im Spiel · $act online · $all Freunde" : 'Verbunden';
    } catch (Throwable $e) {
        return '';
    }
}

// ---------- Statistik ----------

/** Spiel-Sitzungen aus dem Verlauf: [vrc_id => [[start, ende], …]] im Zeitraum */
function vrc_sessions(int $uid, int $from, ?string $friend = null): array {
    $sql = "SELECT vrc_id, ts, type FROM feed WHERE user_id = ? AND ts >= ? AND type IN ('online', 'offline', 'active', 'gps')" . ($friend ? ' AND vrc_id = ?' : '') . ' ORDER BY vrc_id, ts';
    $st = vrc_db()->prepare($sql);
    $st->execute($friend ? [$uid, $from - 86400, $friend] : [$uid, $from - 86400]);
    $open = [];
    $out = [];
    foreach ($st as $r) {
        $id = $r['vrc_id'];
        $ts = (int)$r['ts'];
        if ($r['type'] === 'online' || ($r['type'] === 'gps' && !isset($open[$id]))) {
            if (!isset($open[$id])) $open[$id] = $ts;
        } elseif (isset($open[$id])) {
            if ($ts > $from) $out[$id][] = [max($from, $open[$id]), $ts];
            unset($open[$id]);
        }
    }
    // Wer gerade im Spiel ist, zählt bis jetzt
    $st = vrc_db()->prepare("SELECT vrc_id, since FROM friends WHERE user_id = ? AND state = 'online'" . ($friend ? ' AND vrc_id = ?' : ''));
    $st->execute($friend ? [$uid, $friend] : [$uid]);
    foreach ($st as $r) {
        $start = $open[$r['vrc_id']] ?? (int)$r['since'];
        if ($start && $start < time()) $out[$r['vrc_id']][] = [max($from, $start), time()];
    }
    return $out;
}

function vrc_friend_stats(int $uid, string $id): array {
    $sum = function (array $list, int $from) { $t = 0; foreach ($list as [$a, $b]) if ($b > $from) $t += $b - max($a, $from); return $t; };
    $s = vrc_sessions($uid, time() - 30 * 86400, $id)[$id] ?? [];
    $q = vrc_db()->prepare("SELECT ts, new FROM feed WHERE user_id = ? AND vrc_id = ? AND type IN ('gps', 'online') AND new LIKE 'wrld_%' ORDER BY id DESC LIMIT 1");
    $q->execute([$uid, $id]);
    $last_world = $q->fetch() ?: null;
    $q = vrc_db()->prepare("SELECT MAX(ts) FROM feed WHERE user_id = ? AND vrc_id = ? AND type IN ('online', 'offline')");
    $q->execute([$uid, $id]);
    $last_seen = (int)$q->fetchColumn();
    $q = vrc_db()->prepare('SELECT friend_since FROM friends WHERE user_id = ? AND vrc_id = ?');
    $q->execute([$uid, $id]);
    $out = ['week' => $sum($s, time() - 7 * 86400), 'month' => $sum($s, time() - 30 * 86400), 'sessions' => count($s), 'last_seen' => $last_seen, 'friend_since' => (int)$q->fetchColumn()];
    if ($last_world) {
        $l = vrc_location($last_world['new']);
        $l['world_name'] = vrc_worlds([$l['world'] ?? ''])[$l['world'] ?? '']['name'] ?? '';
        $out['last_world'] = $l + ['ts' => (int)$last_world['ts']];
    }
    return $out;
}

function vrc_stats(int $uid): array {
    $db = vrc_db();
    $now = time();
    $from = $now - 30 * 86400;

    // Online-Verlauf (7 Tage, stündlich gemittelt)
    $st = $db->prepare('SELECT ts, online, active, total FROM stats WHERE user_id = ? AND ts > ? ORDER BY ts');
    $st->execute([$uid, $now - 7 * 86400]);
    $rows = $st->fetchAll();
    $span = $rows ? (int)end($rows)['ts'] - (int)$rows[0]['ts'] : 0;
    $bucket = $span > 2 * 86400 ? 3600 : ($span > 6 * 3600 ? 900 : 300);   // wenig Daten: feinere Punkte
    $hours = [];
    foreach ($rows as $r) {
        $h = intdiv((int)$r['ts'], $bucket) * $bucket;
        $hours[$h][] = $r;
    }
    $history = [];
    foreach ($hours as $h => $rows) {
        $history[] = ['ts' => $h, 'online' => round(array_sum(array_column($rows, 'online')) / count($rows), 1), 'active' => round(array_sum(array_column($rows, 'active')) / count($rows), 1)];
    }
    $peak = $history ? max(array_column($history, 'online')) : 0;

    // Spielzeit pro Freund
    $names = [];
    $st = $db->prepare('SELECT vrc_id, display_name, image FROM friends WHERE user_id = ?');
    $st->execute([$uid]);
    foreach ($st as $r) $names[$r['vrc_id']] = $r;
    $play = [];
    foreach (vrc_sessions($uid, $from) as $id => $list) {
        $t = 0;
        foreach ($list as [$a, $b]) $t += $b - $a;
        if ($t > 0 && isset($names[$id])) $play[] = ['id' => $id, 'name' => $names[$id]['display_name'], 'image' => $names[$id]['image'], 'seconds' => $t, 'sessions' => count($list)];
    }
    usort($play, function ($a, $b) { return $b['seconds'] <=> $a['seconds']; });

    // Beliebteste Welten (Freunde) und eigene Welten
    $top = function (string $types) use ($db, $uid, $from) {
        $st = $db->prepare("SELECT new FROM feed WHERE user_id = ? AND ts >= ? AND type IN ($types) AND new LIKE 'wrld_%'");
        $st->execute([$uid, $from]);
        $c = [];
        foreach ($st as $r) { $w = explode(':', $r['new'])[0]; $c[$w] = ($c[$w] ?? 0) + 1; }
        arsort($c);
        $c = array_slice($c, 0, 10, true);
        $info = vrc_worlds(array_keys($c));
        $out = [];
        foreach ($c as $w => $n) $out[] = ['id' => $w, 'name' => $info[$w]['name'] ?? $w, 'thumb' => $info[$w]['thumb'] ?? '', 'count' => $n];
        return $out;
    };

    // Aktivität nach Wochentag/Stunde (wann kommen Freunde online?)
    $heat = array_fill(0, 7, array_fill(0, 24, 0));
    $st = $db->prepare("SELECT ts FROM feed WHERE user_id = ? AND ts >= ? AND type = 'online'");
    $st->execute([$uid, $from]);
    foreach ($st as $r) $heat[(int)date('N', (int)$r['ts']) - 1][(int)date('G', (int)$r['ts'])]++;

    // Verteilung
    $trust = [];
    $platform = [];
    $state = ['online' => 0, 'active' => 0, 'offline' => 0];
    $st = $db->prepare('SELECT tags, platform, state FROM friends WHERE user_id = ?');
    $st->execute([$uid]);
    foreach ($st as $r) {
        [$t, $col] = vrc_trust($r['tags'] !== '' ? explode(' ', $r['tags']) : []);
        $trust[$t] = ['name' => $t, 'color' => $col, 'count' => ($trust[$t]['count'] ?? 0) + 1];
        $p = VRC_PLATFORM[$r['platform']] ?? ($r['platform'] ?: 'unbekannt');
        $platform[$p] = ($platform[$p] ?? 0) + 1;
        $state[$r['state']] = ($state[$r['state']] ?? 0) + 1;
    }
    usort($trust, function ($a, $b) { return $b['count'] <=> $a['count']; });
    arsort($platform);
    $st = $db->prepare('SELECT type, COUNT(*) AS n FROM feed WHERE user_id = ? AND ts >= ? GROUP BY type');
    $st->execute([$uid, $from]);
    $types = [];
    foreach ($st as $r) $types[$r['type']] = (int)$r['n'];
    $st = $db->prepare('SELECT MIN(ts) FROM feed WHERE user_id = ?');
    $st->execute([$uid]);

    return ['history' => $history, 'peak' => $peak, 'play' => array_slice($play, 0, 15), 'worlds' => $top("'gps', 'online'"), 'my_worlds' => $top("'self'"),
            'heat' => $heat, 'trust' => array_values($trust), 'platform' => $platform, 'state' => $state, 'types' => $types,
            'recording_since' => (int)$st->fetchColumn(), 'total_play' => array_sum(array_column($play, 'seconds'))];
}

/** Daten als CSV ausgeben (Freunde oder Verlauf) */
function vrc_export(int $uid, string $what): void {
    $name = 'vrc-nexus_' . ($what === 'feed' ? 'verlauf' : 'freunde') . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($what === 'feed') {
        fputcsv($out, ['Zeit', 'Ereignis', 'Name', 'VRChat-ID', 'Vorher', 'Nachher'], ';');
        $st = vrc_db()->prepare('SELECT * FROM feed WHERE user_id = ? ORDER BY id DESC LIMIT 20000');
        $st->execute([$uid]);
        foreach ($st as $r) fputcsv($out, [date('d.m.Y H:i', (int)$r['ts']), $r['type'], $r['display_name'], $r['vrc_id'], $r['old'], $r['new']], ';');
    } else {
        fputcsv($out, ['Name', 'VRChat-ID', 'Status', 'Statustext', 'Ort', 'Plattform', 'Vertrauen', 'Favorit', 'Notiz', 'Letzter Login'], ';');
        foreach (vrc_friend_list($uid) as $f) {
            fputcsv($out, [$f['name'], $f['id'], $f['state'], $f['status_desc'], $f['loc']['kind'] === 'world' ? ($f['loc']['world_name'] ?? '') . ' (' . $f['loc']['access'] . ')' : $f['loc']['label'],
                           $f['platform'], $f['trust'][0], $f['favorite'] ? 'ja' : '', $f['note'], $f['last_login']], ';');
        }
    }
    fclose($out);
}
