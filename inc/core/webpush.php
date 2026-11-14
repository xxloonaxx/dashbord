<?php
/**
 * Web-Push ohne Zusatzbibliotheken (RFC 8030 + RFC 8291 „aes128gcm“ + RFC 8292 VAPID).
 * Funktioniert mit Chrome/Edge/Firefox am PC und mit Chrome auf Android (auch bei geschlossenem Browser).
 *
 *   webpush_public_key()            → Base64url des öffentlichen VAPID-Schlüssels (für pushManager.subscribe)
 *   webpush_save(uid, sub, ua)      → Abo speichern
 *   webpush_send_user(uid, payload) → an alle Geräte eines Benutzers schicken (abgelaufene Abos werden entfernt)
 */
require_once __DIR__ . '/site.php';

function wp_b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function wp_unb64u(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

function webpush_db(): PDO {
    static $ok = false;
    $db = site_db();
    if (!$ok) {
        $db->exec('CREATE TABLE IF NOT EXISTS push_subs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, endpoint TEXT NOT NULL UNIQUE, p256dh TEXT NOT NULL, auth TEXT NOT NULL,
                   ua TEXT NOT NULL DEFAULT \'\', created INTEGER NOT NULL, last_ok INTEGER NOT NULL DEFAULT 0, fails INTEGER NOT NULL DEFAULT 0)');
        $ok = true;
    }
    return $db;
}

/** VAPID-Schlüsselpaar (einmal erzeugt, im Einstellungsspeicher der Seite) */
function webpush_vapid(): array {
    $pem = setting('webpush_vapid_pem', '');
    if ($pem === '') {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($k, $pem);
        set_setting('webpush_vapid_pem', $pem);
    }
    $key = openssl_pkey_get_private($pem);
    $d = openssl_pkey_get_details($key);
    $raw = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    return ['key' => $key, 'pub' => $raw];
}
function webpush_public_key(): string { return wp_b64u(webpush_vapid()['pub']); }

/** rohen P-256-Punkt (65 Byte) als PEM-Schlüssel */
function wp_pub_pem(string $raw): string {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** ECDSA-Signatur (DER) → JOSE-Format r‖s (64 Byte) */
function wp_der_to_jose(string $der): string {
    $o = 2;
    if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
    $rl = ord($der[$o + 1]); $r = substr($der, $o + 2, $rl); $o += 2 + $rl;
    $sl = ord($der[$o + 1]); $s = substr($der, $o + 2, $sl);
    $fix = fn($x) => str_pad(ltrim($x, "\0"), 32, "\0", STR_PAD_LEFT);
    return $fix($r) . $fix($s);
}

function webpush_jwt(string $endpoint, array $v): string {
    $u = parse_url($endpoint);
    $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $sub = setting('webpush_subject', '');
    if ($sub === '') $sub = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $h = wp_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = wp_b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub]));
    openssl_sign($h . '.' . $c, $sig, $v['key'], OPENSSL_ALGO_SHA256);
    return $h . '.' . $c . '.' . wp_b64u(wp_der_to_jose($sig));
}

/** Nachricht für ein Abo verschlüsseln (RFC 8291, ein Datensatz) */
function webpush_encrypt(string $payload, string $p256dh, string $auth): string {
    $ua = wp_unb64u($p256dh);
    $secret = wp_unb64u($auth);
    if (strlen($ua) !== 65 || strlen($secret) < 16) throw new RuntimeException('Ungültiges Push-Abo.');
    $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $ed = openssl_pkey_get_details($eph);
    $as = "\x04" . str_pad($ed['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ed['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $shared = openssl_pkey_derive(openssl_pkey_get_public(wp_pub_pem($ua)), $eph, 32);
    if ($shared === false) throw new RuntimeException('Schlüsseltausch fehlgeschlagen.');
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $ua . $as, $secret);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(65) . $as . $ct . $tag;
}

/** an ein Abo schicken → HTTP-Status (201 = ok; 404/410 = Abo abgelaufen) */
function webpush_send(array $sub, array $payload, int $ttl = 3600): int {
    $v = webpush_vapid();
    $body = webpush_encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $sub['p256dh'], $sub['auth']);
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: ' . $ttl, 'Urgency: normal',
            'Authorization: vapid t=' . webpush_jwt($sub['endpoint'], $v) . ', k=' . wp_b64u($v['pub'])]]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code;
}

function webpush_save(int $uid, array $sub, string $ua = ''): void {
    $ep = (string)($sub['endpoint'] ?? '');
    $k = (array)($sub['keys'] ?? []);
    if (!preg_match('~^https://[^\s]+$~', $ep) && !(getenv('NEXUS_PUSH_TEST') && preg_match('~^http://127\.0\.0\.1:\d+/~', $ep))) throw new RuntimeException('Ungültige Push-Adresse.');
    if (strlen(wp_unb64u((string)($k['p256dh'] ?? ''))) !== 65 || strlen(wp_unb64u((string)($k['auth'] ?? ''))) < 16) throw new RuntimeException('Ungültige Push-Schlüssel.');
    webpush_db()->prepare('INSERT INTO push_subs (user_id, endpoint, p256dh, auth, ua, created) VALUES (?, ?, ?, ?, ?, ?)
                            ON CONFLICT(endpoint) DO UPDATE SET user_id = excluded.user_id, p256dh = excluded.p256dh, auth = excluded.auth, ua = excluded.ua, fails = 0')
        ->execute([$uid, $ep, (string)$k['p256dh'], (string)$k['auth'], mb_substr($ua, 0, 120), time()]);
}
function webpush_list(int $uid): array {
    $st = webpush_db()->prepare('SELECT id, ua, created, last_ok, fails FROM push_subs WHERE user_id = ? ORDER BY created DESC');
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function webpush_delete(int $uid, string $endpoint = '', int $id = 0): void {
    webpush_db()->prepare('DELETE FROM push_subs WHERE user_id = ? AND (endpoint = ? OR id = ?)')->execute([$uid, $endpoint, $id]);
}
/** an alle Geräte eines Benutzers; gibt die Zahl erfolgreicher Zustellungen zurück */
function webpush_send_user(int $uid, array $payload): int {
    $st = webpush_db()->prepare('SELECT * FROM push_subs WHERE user_id = ?');
    $st->execute([$uid]);
    $ok = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        try { $code = webpush_send($s, $payload); } catch (Throwable $e) { $code = 0; }
        if ($code >= 200 && $code < 300) { $ok++; webpush_db()->prepare('UPDATE push_subs SET last_ok = ?, fails = 0 WHERE id = ?')->execute([time(), $s['id']]); }
        elseif (in_array($code, [404, 410], true) || (int)$s['fails'] >= 20) webpush_db()->prepare('DELETE FROM push_subs WHERE id = ?')->execute([$s['id']]);
        else webpush_db()->prepare('UPDATE push_subs SET fails = fails + 1 WHERE id = ?')->execute([$s['id']]);
    }
    return $ok;
}
