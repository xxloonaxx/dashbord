<?php
/**
 * Sicherheits-Erweiterungen der Benutzerverwaltung:
 *  - Sitzungen je Gerät (einzeln beendbar), Konto-Ablaufdatum, „Ansicht als Rolle“
 *  - Passwort-Richtlinie (Länge, Zeichenarten, Höchstalter, verbreitete Passwörter)
 *  - IP-Sperrliste (einzelne Adressen & Bereiche in CIDR-Schreibweise, optional befristet)
 *  - Zwei-Faktor-Anmeldung (TOTP, RFC 6238) mit Wiederherstellungscodes
 *  - Einladungscodes zur Selbst-Registrierung
 *  - geplante Wartung & Ankündigungs-Banner
 * Wird von inc/auth.php eingebunden.
 */

// ---------- Wartung & Konten ----------

/** Liegt „jetzt“ im geplanten Wartungsfenster? (leer = sofort bzw. unbegrenzt) */
function maintenance_window_active(): bool {
    $from = setting('maintenance_from', '');
    $until = setting('maintenance_until', '');
    if ($from !== '' && time() < strtotime($from)) return false;
    if ($until !== '' && time() >= strtotime($until)) return false;
    return true;
}

function user_expired(array $u): bool {
    return !empty($u['expires_at']) && strtotime($u['expires_at']) < time();
}

// ---------- Sitzungen je Gerät ----------

function session_row_create(int $uid, bool $remember): string {
    $id = bin2hex(random_bytes(16));
    site_db()->prepare('INSERT INTO user_sessions (id, user_id, ip, agent, remember) VALUES (?, ?, ?, ?, ?)')
             ->execute([$id, $uid, client_ip(), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250), $remember ? 1 : 0]);
    if (random_int(1, 20) === 1) site_db()->exec("DELETE FROM user_sessions WHERE last_seen < datetime('now','localtime','-31 days')");
    return $id;
}

/** Sitzung noch gültig (nicht von einem Admin / anderen Gerät beendet)? Aktualisiert „zuletzt aktiv“. */
function session_row_touch(int $uid, bool $remember): bool {
    $sid = (string)($_SESSION['auth']['sid'] ?? '');
    if ($sid === '') {   // Sitzung aus der Vorversion → nachträglich erfassen
        $_SESSION['auth']['sid'] = session_row_create($uid, $remember);
        $_SESSION['auth']['touch'] = time();
        return true;
    }
    $st = site_db()->prepare('SELECT user_id FROM user_sessions WHERE id = ?');
    $st->execute([$sid]);
    if ((int)$st->fetchColumn() !== $uid) return false;
    if (time() - (int)($_SESSION['auth']['touch'] ?? 0) < 60) return true;
    site_db()->prepare("UPDATE user_sessions SET last_seen = datetime('now','localtime'), ip = ? WHERE id = ?")->execute([client_ip(), $sid]);
    $_SESSION['auth']['touch'] = time();
    return true;
}

function current_session_id(): string {
    return (string)($_SESSION['auth']['sid'] ?? '');
}

function sessions_list(?int $uid = null): array {
    $sql = "SELECT s.*, u.username, u.display_name, r.color AS role_color, s.last_seen > datetime('now','localtime','-15 minutes') AS online FROM user_sessions s JOIN users u ON u.id = s.user_id JOIN roles r ON r.id = u.role_id"
         . ($uid ? ' WHERE s.user_id = ?' : '') . ' ORDER BY s.last_seen DESC LIMIT 300';
    $st = site_db()->prepare($sql);
    $st->execute($uid ? [$uid] : []);
    return $st->fetchAll();
}

/** Grobe Gerätebeschreibung aus dem User-Agent: [Symbol, Text] */
function device_label(string $ua): array {
    $os = preg_match('/iPhone|iPad/i', $ua) ? 'iOS' : (preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/Windows/i', $ua) ? 'Windows'
        : (preg_match('/Mac OS X|Macintosh/i', $ua) ? 'macOS' : (preg_match('/Linux/i', $ua) ? 'Linux' : 'Unbekannt'))));
    $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\//', $ua) ? 'Opera' : (preg_match('/Firefox\//', $ua) ? 'Firefox'
        : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : ($ua === '' ? '' : 'Browser')))));
    $mobile = preg_match('/Mobile|iPhone|Android/i', $ua);
    return [$mobile ? '📱' : '💻', trim($br . ' · ' . $os, ' ·')];
}

// ---------- Ansicht als Rolle ----------

function role_preview_apply(array $u, int $rid): array {
    $st = site_db()->prepare('SELECT * FROM roles WHERE id = ?');
    $st->execute([$rid]);
    $r = $st->fetch();
    if (!$r) return $u;
    $u['preview'] = ['real_role' => $u['role_name'], 'role' => $r['name']];
    $u['role_id'] = (int)$r['id'];
    $u['role_name'] = $r['name'];
    $u['role_color'] = $r['color'];
    $u['role_is_system'] = (int)$r['is_system'];
    $u['permissions'] = role_permissions((int)$r['id'], (bool)$r['is_system']);
    return $u;
}

// ---------- Passwort-Richtlinie ----------

function password_policy(): array {
    return [
        'min' => max(8, min(64, (int)setting('pw_min', '8'))),
        'classes' => max(0, min(4, (int)setting('pw_classes', '0'))),
        'max_age' => max(0, (int)setting('pw_max_age', '0')),
        'common' => setting('pw_common', '1') === '1',
        'admin_2fa' => setting('pw_admin_2fa', '0') === '1',
    ];
}

function password_is_common(string $pw): bool {
    $p = mb_strtolower($pw);
    $list = ['password', 'passwort', '12345678', '123456789', '1234567890', 'qwertzui', 'qwertyui', 'qwertz123', 'qwerty123', 'iloveyou', 'sunshine', 'princess',
             'football', 'fussball', 'master123', 'admin123', 'administrator', 'welcome1', 'willkommen', 'hallo123', 'letmein1', 'passwort1', 'password1',
             'abc12345', 'abcdefgh', '11111111', '00000000', 'geheim123', 'schatz123', 'dragon12', 'monkey12', 'starwars', 'pokemon1', 'minecraft', 'baseball'];
    if (in_array($p, $list, true)) return true;
    if (preg_match('/^(.)\1+$/u', $pw)) return true;                     // aaaaaaaa
    if (strpos('01234567890123456789', $p) !== false || strpos('abcdefghijklmnopqrstuvwxyz', $p) !== false) return true;   // Folgen
    return false;
}

// ---------- IP-Sperrliste ----------

function ip_rules(): array {
    $rules = json_decode(setting('ip_block', '[]'), true);
    if (!is_array($rules)) return [];
    $now = time();
    $keep = array_values(array_filter($rules, function ($r) use ($now) { return empty($r['until']) || strtotime($r['until']) > $now; }));
    if (count($keep) !== count($rules)) set_setting('ip_block', json_encode($keep, JSON_UNESCAPED_UNICODE));
    return $keep;
}

/** Gültige Adresse oder Bereich (z. B. 203.0.113.7, 203.0.113.0/24, 2001:db8::/32)? Liefert normalisiert oder ''. */
function ip_rule_normalize(string $s): string {
    $s = trim($s);
    [$ip, $bits] = array_pad(explode('/', $s, 2), 2, null);
    $bin = @inet_pton((string)$ip);
    if ($bin === false) return '';
    $max = strlen($bin) * 8;
    if ($bits === null) return inet_ntop($bin);
    if (!ctype_digit($bits) || (int)$bits < ($max === 32 ? 8 : 16) || (int)$bits > $max) return '';
    return inet_ntop($bin) . '/' . (int)$bits;
}

function ip_match(string $ip, string $rule): bool {
    [$net, $bits] = array_pad(explode('/', $rule, 2), 2, null);
    $a = @inet_pton($ip);
    $b = @inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
    $bits = $bits === null ? strlen($a) * 8 : (int)$bits;
    $bytes = intdiv($bits, 8);
    if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) return false;
    $rest = $bits % 8;
    if (!$rest) return true;
    $mask = chr((0xff << (8 - $rest)) & 0xff);
    return ($a[$bytes] & $mask) === ($b[$bytes] & $mask);
}

function ip_blocked(?string $ip = null): ?array {
    $ip = $ip ?? client_ip();
    foreach (ip_rules() as $r) if (ip_match($ip, $r['ip'])) return $r;
    return null;
}

function ip_block_check(): void {
    if (!ip_blocked()) return;
    render_notice_page(403, '🚫', 'Zugriff gesperrt', 'Deine Adresse wurde für diese Webseite gesperrt.');
}

// ---------- Zwei-Faktor (TOTP) ----------

function base32_encode(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function base32_decode(string $s): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
    $bits = '';
    foreach (str_split($s) as $c) $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totp_new_secret(): string {
    return base32_encode(random_bytes(20));
}

function totp_code(string $secret_b32, int $step): string {
    $h = hash_hmac('sha1', pack('N2', 0, $step), base32_decode($secret_b32), true);
    $o = ord($h[19]) & 0x0f;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Code prüfen (±30 s Toleranz). Liefert den Zeitschritt oder 0. */
function totp_verify(string $secret_b32, string $code): int {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return 0;
    $now = intdiv(time(), 30);
    for ($d = -1; $d <= 1; $d++) if (hash_equals(totp_code($secret_b32, $now + $d), $code)) return $now + $d;
    return 0;
}

function totp_uri(string $secret_b32, string $account): string {
    $issuer = setting('site_title', 'GhostNode') ?: 'GhostNode';
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret_b32 . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
}

function totp_secret_of(array $u): string {
    if (($u['totp_secret'] ?? '') === '') return '';
    require_once __DIR__ . '/crypto.php';
    return (string)(crypto_decrypt('totp', $u['totp_secret']) ?? '');
}

/** 8 Wiederherstellungscodes: [Klartext-Liste, Hash-Liste] */
function totp_recovery_new(): array {
    $alpha = 'abcdefghjkmnpqrstuvwxyz23456789';
    $plain = [];
    for ($i = 0; $i < 8; $i++) {
        $c = '';
        for ($j = 0; $j < 8; $j++) $c .= $alpha[random_int(0, strlen($alpha) - 1)];
        $plain[] = substr($c, 0, 4) . '-' . substr($c, 4);
    }
    return [$plain, array_map(function ($c) { return hash('sha256', str_replace('-', '', $c)); }, $plain)];
}

function totp_enable(int $uid, string $secret_b32): array {
    require_once __DIR__ . '/crypto.php';
    [$plain, $hashes] = totp_recovery_new();
    site_db()->prepare('UPDATE users SET totp_secret = ?, totp_recovery = ? WHERE id = ?')->execute([crypto_encrypt('totp', $secret_b32), json_encode($hashes), $uid]);
    return $plain;
}

function totp_disable(int $uid): void {
    site_db()->prepare("UPDATE users SET totp_secret = '', totp_recovery = '[]' WHERE id = ?")->execute([$uid]);
    set_setting('totp_last_' . $uid, '0');
}

/** Code beim Login prüfen: '' (falsch), 'totp' oder 'recovery' (Code wird verbraucht) */
function totp_check_login(array $u, string $code): string {
    $code = trim($code);
    if (preg_match('/^\d{3}\s?\d{3}$/', $code)) {
        $step = totp_verify(totp_secret_of($u), $code);
        if (!$step || $step <= (int)setting('totp_last_' . $u['id'], '0')) return '';   // kein zweimal benutzter Code
        set_setting('totp_last_' . $u['id'], (string)$step);
        return 'totp';
    }
    $h = hash('sha256', strtolower(preg_replace('/[^A-Za-z0-9]/', '', $code)));
    $list = json_decode($u['totp_recovery'] ?: '[]', true) ?: [];
    $i = array_search($h, $list, true);
    if ($i === false) return '';
    array_splice($list, $i, 1);
    site_db()->prepare('UPDATE users SET totp_recovery = ? WHERE id = ?')->execute([json_encode(array_values($list)), $u['id']]);
    return 'recovery';
}

// ---------- Einladungen ----------

function invite_find(string $code): ?array {
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    if ($code === '') return null;
    $st = site_db()->prepare("SELECT i.*, r.name AS role_name, r.color AS role_color, r.is_system FROM invites i JOIN roles r ON r.id = i.role_id
                              WHERE i.code = ? AND i.uses < i.max_uses AND (i.expires_at IS NULL OR i.expires_at > ?)");
    $st->execute([$code, date('Y-m-d H:i:s')]);
    $r = $st->fetch();
    return $r ?: null;
}

function invite_new(int $role_id, int $max_uses, int $days, string $note, string $by): string {
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 10; $i++) $code .= $alpha[random_int(0, strlen($alpha) - 1)];
    site_db()->prepare('INSERT INTO invites (code, role_id, max_uses, expires_at, note, created_by) VALUES (?, ?, ?, ?, ?, ?)')
             ->execute([$code, $role_id, max(1, min(100, $max_uses)), $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null, $note, $by]);
    return $code;
}

// ---------- Ankündigung & Hinweise ----------

function announcement(): array {
    $a = json_decode(setting('announcement', ''), true);
    $a = is_array($a) ? $a : [];
    return $a + ['text' => '', 'level' => 'info', 'until' => '', 'audience' => 'all', 'dismissible' => true, 'id' => ''];
}

function announcement_active(?array $a = null): bool {
    $a = $a ?? announcement();
    if (trim($a['text']) === '') return false;
    if ($a['until'] !== '' && strtotime($a['until']) < time()) return false;
    if ($a['audience'] === 'users' && !is_logged_in()) return false;
    if ($a['audience'] === 'guests' && is_logged_in()) return false;
    return true;
}

/** Ankündigung, Rollen-Vorschau und Passwort-Hinweis oben auf jeder Seite (einmal pro Aufruf) */
function site_banners(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $u = current_user();
    $a = announcement();
    $out = '';
    if ($u && !empty($u['preview'])) {
        $out .= '<div class="sb sb-preview">👁 Vorschau als Rolle <b>' . e($u['preview']['role']) . '</b> – du siehst die Seite mit deren Rechten.'
              . '<form method="POST" action="admin.php"><input type="hidden" name="action" value="preview_end"><button>Vorschau beenden</button></form></div>';
    }
    if (announcement_active($a)) {
        $ic = ['info' => 'ℹ️', 'success' => '✅', 'warn' => '⚠️', 'danger' => '🚨'][$a['level']] ?? 'ℹ️';
        $out .= '<div class="sb sb-' . e($a['level']) . '" data-sb="' . e($a['id']) . '"><span>' . $ic . '</span><span class="sb-t">' . nl2br(e($a['text'])) . '</span>'
              . (!empty($a['dismissible']) ? '<button type="button" class="sb-x" aria-label="Ausblenden">×</button>' : '') . '</div>';
    }
    if ($out === '') return;
    echo '<div class="site-banners">' . $out . '</div>';
    echo '<style>.site-banners{position:relative;z-index:60;font-size:.85rem}.sb{display:flex;align-items:center;gap:10px;padding:8px 16px;border-bottom:1px solid;justify-content:center;flex-wrap:wrap;text-align:center}'
       . '.sb form{margin:0}.sb button{background:transparent;border:1px solid currentColor;color:inherit;padding:2px 10px;cursor:pointer;font:inherit;font-size:.75rem}.sb .sb-x{border:0;font-size:1.1rem;padding:0 6px}'
       . '.sb-info{background:rgba(56,213,230,.12);color:#9be9f2;border-color:rgba(56,213,230,.4)}.sb-success{background:rgba(80,250,123,.1);color:#8ff5ab;border-color:rgba(80,250,123,.4)}'
       . '.sb-warn{background:rgba(243,194,98,.12);color:#f3c262;border-color:rgba(243,194,98,.45)}.sb-danger{background:rgba(255,85,85,.14);color:#ff8a8a;border-color:rgba(255,85,85,.5)}'
       . '.sb-preview{background:rgba(189,147,249,.15);color:#d6bcfa;border-color:rgba(189,147,249,.5)}.sb.gone{display:none}</style>';
    echo '<script>(function(){document.querySelectorAll(".sb[data-sb]").forEach(function(b){var k="sb-hide-"+b.dataset.sb;try{if(localStorage.getItem(k))b.classList.add("gone")}catch(e){}'
       . 'var x=b.querySelector(".sb-x");if(x)x.addEventListener("click",function(){b.classList.add("gone");try{localStorage.setItem(k,"1")}catch(e){}})})})();</script>';
}
