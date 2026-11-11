<?php
/**
 * Benutzer, Rollen & Rechte.
 *
 * - Beim allerersten Aufruf legt man auf admin.php das erste Konto an (wird Administrator).
 * - Jeder Benutzer hat eine Rolle; Rollen bündeln Rechte (siehe PERMISSIONS in inc/db.php).
 * - can('recht') prüft ein Recht, require_permission() schützt ganze Seiten, guard_post() alle Änderungen.
 * - Seiten haben eine Zugriffsstufe (öffentlich / angemeldet / bestimmte Rollen), die automatisch geprüft wird.
 * - Wartungsmodus: nur Benutzer mit Admin-Zugang sehen die Seite.
 */
require_once __DIR__ . '/db.php';

const AUTH_MAX_FAILS = 5;          // Fehlversuche pro IP …
const AUTH_LOCK_SECONDS = 600;     // … dann 10 Minuten Sperre
const AUTH_IDLE_TIMEOUT = 43200;   // 12 Std. ohne Aktivität -> abmelden (außer "angemeldet bleiben")
const AUTH_REMEMBER = 2592000;     // "Angemeldet bleiben" = 30 Tage

// ---------- Geschützter Datenspeicher (für .openbanking.php u. ä.) ----------

function secure_load(string $path, $default) {
    if (!is_file($path)) return $default;
    $raw = (string)@file_get_contents($path);
    $data = json_decode(substr($raw, (int)strpos($raw, "\n") + 1), true);
    return is_array($data) ? $data : $default;
}

function secure_save(string $path, array $data): bool {
    $content = "<?php http_response_code(404); exit; ?>\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    return rename($tmp, $path);
}

// ---------- Einstellungen (kompatibel zur Vorversion) ----------

function site_settings(): array {
    return [
        'show_nsfw' => setting('show_nsfw', '0') === '1',
        'maintenance' => setting('maintenance', '0') === '1',
        'maintenance_message' => setting('maintenance_message', 'Wir sind gleich wieder da.'),
        'site_title' => setting('site_title', 'Startseite'),
        'site_tagline' => setting('site_tagline', 'Zentrale Schnittstelle & Datenknoten'),
    ];
}

function save_site_setting(string $key, $value): void {
    set_setting($key, is_bool($value) ? ($value ? '1' : '0') : (string)$value);
}

// ---------- Session ----------

function auth_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    // Eigener Session-Ordner, damit fremde Aufräum-Jobs (oft nach 24 Min.) uns nicht abmelden
    $dir = sys_get_temp_dir() . '/ghostnode_sessions_' . substr(md5(__DIR__), 0, 8);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string)AUTH_REMEMBER);
    ini_set('session.use_strict_mode', '1');
    session_name('GHOSTNODE');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
    session_start();
}

function auth_configured(): bool {
    return (int)site_db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

/** Benutzer samt Rolle und Rechten laden */
function user_load(int $id): ?array {
    $st = site_db()->prepare('SELECT u.*, r.name AS role_name, r.color AS role_color, r.is_system AS role_is_system FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) return null;
    $u['permissions'] = role_permissions((int)$u['role_id'], (bool)$u['role_is_system']);
    return $u;
}

function role_permissions(int $role_id, bool $is_system = false): array {
    if ($is_system) return array_keys(PERMISSIONS);
    $st = site_db()->prepare('SELECT permission FROM role_permissions WHERE role_id = ?');
    $st->execute([$role_id]);
    return array_values(array_intersect($st->fetchAll(PDO::FETCH_COLUMN), array_keys(PERMISSIONS)));
}

/** Angemeldeter Benutzer (Array) oder null */
function current_user(bool $refresh = false): ?array {
    static $checked = false, $user = null;
    if ($refresh) { $checked = false; $user = null; }
    if ($checked) return $user;
    $checked = true;
    // Ohne Session-Cookie gar keine Session starten (Besucher bekommen kein Cookie)
    if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE['GHOSTNODE'])) return null;
    auth_session();
    $s = $_SESSION['auth'] ?? null;
    if (!$s || empty($s['uid'])) return null;
    $u = user_load((int)$s['uid']);
    $limit = !empty($s['remember']) ? AUTH_REMEMBER : AUTH_IDLE_TIMEOUT;
    // Passwortwechsel / "überall abmelden" / Deaktivierung machen alte Sitzungen ungültig
    if (!$u || !$u['active'] || (int)($s['version'] ?? -1) !== (int)$u['session_version'] || time() - ($s['last'] ?? 0) > $limit) {
        unset($_SESSION['auth']);
        return null;
    }
    $_SESSION['auth']['last'] = time();
    return $user = $u;
}

/** Benutzername (kompatibel zur Vorversion) */
function auth_user(): ?string {
    $u = current_user();
    return $u ? $u['username'] : null;
}

function user_label(?array $u = null): string {
    $u = $u ?? current_user();
    return $u ? ($u['display_name'] !== '' ? $u['display_name'] : $u['username']) : '';
}

function is_logged_in(): bool {
    return current_user() !== null;
}

/** Hat der angemeldete Benutzer dieses Recht? */
function can(string $permission): bool {
    $u = current_user();
    return $u !== null && in_array($permission, $u['permissions'], true);
}

/** Kompatibel zur Vorversion: "Admin" = darf die Admin-Zentrale öffnen */
function is_admin(): bool {
    return can('admin.access');
}

function client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '?');
}

// ---------- Protokoll ----------

/** Eintrag ins Protokoll. $level: info | warn | security */
function audit(string $action, string $detail = '', string $level = 'info', ?array $user = null): void {
    try {
        $u = $user ?? current_user();
        site_db()->prepare('INSERT INTO audit_log (user_id, username, action, detail, level, ip, agent) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
            $u['id'] ?? null, $u['username'] ?? '', mb_substr($action, 0, 120), mb_substr($detail, 0, 500), $level,
            client_ip(), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
        ]);
        // Protokoll begrenzen
        if (random_int(1, 50) === 1) site_db()->exec("DELETE FROM audit_log WHERE ts < datetime('now', '-365 days')");
    } catch (Throwable $e) { /* Protokoll darf nie die Seite stoppen */ }
}

/** Kompatibel zur Vorversion */
function auth_log(string $event, ?string $user = null): void {
    $level = preg_match('/fehl|abgelehnt|gesperrt/i', $event) ? 'security' : 'info';
    audit($event, '', $level, $user !== null && !current_user() ? ['id' => null, 'username' => $user] : null);
}

// ---------- Sperre bei Fehlversuchen ----------

function auth_locked_seconds(): int {
    $st = site_db()->prepare('SELECT count, last FROM login_attempts WHERE ip = ?');
    $st->execute([client_ip()]);
    $f = $st->fetch();
    if (!$f || $f['count'] < AUTH_MAX_FAILS) return 0;
    return max(0, $f['last'] + AUTH_LOCK_SECONDS - time());
}

function auth_register_fail(): void {
    $db = site_db();
    $db->prepare('INSERT INTO login_attempts (ip, count, last) VALUES (:ip, 1, :t)
                  ON CONFLICT(ip) DO UPDATE SET count = CASE WHEN :t - last > :lock THEN 1 ELSE count + 1 END, last = :t')
       ->execute([':ip' => client_ip(), ':t' => time(), ':lock' => AUTH_LOCK_SECONDS]);
    $db->prepare('DELETE FROM login_attempts WHERE last < ?')->execute([time() - 86400]);
}

function auth_clear_fails(): void {
    site_db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
}

// ---------- Konten ----------

/** Prüft Benutzername/Passwort-Regeln, liefert Fehlermeldung oder '' */
function auth_validate(string $user, string $pass, string $pass2): string {
    if (!preg_match('/^[\p{L}\p{N}_.\-]{3,32}$/u', $user)) return 'Benutzername: 3–32 Zeichen (Buchstaben, Zahlen, _ . -).';
    return auth_validate_password($pass, $pass2, $user);
}

function auth_validate_password(string $pass, string $pass2, string $user = ''): string {
    if (mb_strlen($pass) < 8) return 'Das Passwort muss mindestens 8 Zeichen lang sein.';
    if ($pass !== $pass2) return 'Die beiden Passwörter stimmen nicht überein.';
    if ($user !== '' && mb_strtolower($pass) === mb_strtolower($user)) return 'Passwort und Benutzername dürfen nicht gleich sein.';
    return '';
}

/** Neues Konto anlegen (Standard: Administrator-Rolle). Liefert die ID. */
function auth_create(string $user, string $pass, int $role_id = 1, string $display = ''): int {
    site_db()->prepare('INSERT INTO users (username, display_name, password_hash, role_id) VALUES (?, ?, ?, ?)')
             ->execute([$user, $display, password_hash($pass, PASSWORD_DEFAULT), $role_id]);
    return (int)site_db()->lastInsertId();
}

/** Kompatibel zur Vorversion: erstes/ältestes Administrator-Konto */
function auth_account(): ?array {
    $id = site_db()->query('SELECT id FROM users WHERE role_id = 1 ORDER BY id LIMIT 1')->fetchColumn();
    return $id ? user_load((int)$id) : null;
}

function auth_login(string $username, string $pass, bool $remember): bool {
    $st = site_db()->prepare('SELECT id, password_hash, active FROM users WHERE username = ? COLLATE NOCASE');
    $st->execute([$username]);
    $row = $st->fetch();
    $ok = $row && password_verify($pass, $row['password_hash']);
    if (!$ok || !$row['active']) {
        auth_register_fail();
        audit($ok ? 'Login abgelehnt (Konto deaktiviert)' : 'Login fehlgeschlagen', 'Benutzername: ' . mb_substr($username, 0, 40), 'security', ['id' => $row['id'] ?? null, 'username' => $username]);
        usleep(random_int(400000, 900000)); // bremst automatisches Durchprobieren
        return false;
    }
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        site_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $row['id']]);
    }
    site_db()->prepare("UPDATE users SET last_login = datetime('now','localtime'), last_ip = ? WHERE id = ?")->execute([client_ip(), $row['id']]);
    $u = user_load((int)$row['id']);
    auth_start_session($u, $remember);
    auth_clear_fails();
    audit('Login', $remember ? 'angemeldet bleiben' : '', 'info', $u);
    return true;
}

function auth_start_session(array $u, bool $remember = false): void {
    auth_session();
    session_regenerate_id(true);
    $_SESSION['auth'] = ['uid' => (int)$u['id'], 'version' => (int)$u['session_version'], 'last' => time(), 'since' => time(), 'remember' => $remember];
    current_user(true);   // Zwischenspeicher auffrischen
    if ($remember) {
        $p = session_get_cookie_params();
        setcookie(session_name(), session_id(), ['expires' => time() + AUTH_REMEMBER, 'path' => $p['path'], 'httponly' => true, 'samesite' => 'Lax', 'secure' => $p['secure']]);
    }
}

function auth_logout(): void {
    auth_session();
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'httponly' => true, 'samesite' => 'Lax', 'secure' => $p['secure']]);
    session_destroy();
    current_user(true);
}

/** Alle Sitzungen eines Benutzers beenden (z. B. nach Passwortwechsel) */
function user_bump_sessions(int $uid): void {
    site_db()->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = ?')->execute([$uid]);
}

// ---------- Schutz für Seiten & Formulare ----------

/** Seite mit 403 bzw. Wartungs-Hinweis im HUD-Look beenden */
function render_notice_page(int $code, string $icon, string $title, string $text, bool $login_link = false): void {
    http_response_code($code);
    $next = urlencode(basename($_SERVER['SCRIPT_NAME'] ?? 'index.php') . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . e($title) . '</title>'
       . emoji_favicon($icon) . '<link rel="stylesheet" href="assets/hud.css"><style>.notice-box{max-width:520px;margin:12vh auto 0;text-align:center;padding:40px 30px;background:var(--panel-bg);border:1px solid var(--border-color);clip-path:var(--cut-md)}'
       . '.notice-box .big{font-size:3rem;margin-bottom:10px}.notice-box p{color:var(--text-muted);line-height:1.6}.notice-box .btn{margin:6px}</style></head><body><div class="wrapper"><div class="notice-box">'
       . '<div class="big">' . e($icon) . '</div><div class="hud-tag" style="justify-content:center">System // ' . $code . '</div><h1 style="font-size:1.6rem">' . e($title) . '</h1><p>' . nl2br(e($text)) . '</p>'
       . ($login_link ? '<a class="btn btn-gold" href="admin.php?next=' . $next . '">Anmelden</a>' : '') . '<a class="btn" href="index.php">Zur Startseite</a></div></div></body></html>';
    exit;
}

/**
 * Bei jedem Seitenaufruf: Ersteinrichtung, Wartungsmodus und Zugriffsstufe der Seite prüfen.
 * Startet außerdem die Sitzung, bevor HTML ausgegeben wird.
 */
function auth_require_setup(): void {
    if (!auth_configured()) {
        header('Location: admin.php');
        exit;
    }
    current_user();
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === 'admin.php') return;
    if (site_settings()['maintenance'] && !can('admin.access')) {
        render_notice_page(503, '🛠️', 'Wartungsarbeiten', site_settings()['maintenance_message'], !is_logged_in());
    }
    if ($script !== 'index.php' && $script !== 'page.php') require_page_access($script);
}

/** Zugriffsstufe einer registrierten Seite prüfen */
function page_access_ok(array $page): bool {
    if ($page['access'] === 'public') return true;
    $u = current_user();
    if (!$u) return false;
    if ($page['access'] === 'users' || $u['role_is_system']) return true;
    return in_array((string)$u['role_id'], array_filter(explode(',', (string)$page['allowed_roles'])), true);
}

function require_page_access(string $slug): void {
    $st = site_db()->prepare('SELECT * FROM pages WHERE slug = ?');
    $st->execute([$slug]);
    $page = $st->fetch();
    if (!$page || page_access_ok($page)) return;
    if (!is_logged_in()) {
        header('Location: admin.php?next=' . urlencode($slug . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '')));
        exit;
    }
    render_notice_page(403, '⛔', 'Kein Zugriff', 'Diese Seite ist für deine Rolle nicht freigegeben.');
}

/** Ganze Seite nur mit einem bestimmten Recht */
function require_permission(string $permission): void {
    auth_require_setup();
    if (can($permission)) return;
    if (!is_logged_in()) {
        header('Location: admin.php?next=' . urlencode(basename($_SERVER['SCRIPT_NAME']) . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '')));
        exit;
    }
    render_notice_page(403, '⛔', 'Kein Zugriff', 'Dafür fehlt deiner Rolle das Recht „' . (PERMISSIONS[$permission][0] ?? $permission) . '“.');
}

/** Kompatibel zur Vorversion */
function require_admin_page(): void {
    require_permission('admin.access');
}

/** Formular kommt wirklich von dieser Seite? (Schutz gegen fremde Seiten, die im Namen eines Benutzers Formulare abschicken) */
function same_origin_request(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        if (!empty($_SERVER[$h])) {
            $src = strtolower((string)parse_url($_SERVER[$h], PHP_URL_HOST) . (parse_url($_SERVER[$h], PHP_URL_PORT) ? ':' . parse_url($_SERVER[$h], PHP_URL_PORT) : ''));
            return $src === $host;
        }
    }
    return true; // manche Browser/Datenschutz-Add-ons senden keinen der Header
}

/** Jede Änderung (POST) nur angemeldet, mit Recht und von der eigenen Seite – sonst Abbruch. */
function guard_post(?string $permission = null): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!is_logged_in()) {
        http_response_code(403);
        header('Location: admin.php?next=' . urlencode(basename($_SERVER['SCRIPT_NAME'])) . '&denied=1');
        exit;
    }
    if (!same_origin_request()) {
        audit('Anfrage abgelehnt', 'Fremde Herkunft: ' . ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''), 'security');
        http_response_code(403);
        exit('Anfrage abgelehnt (fremde Herkunft).');
    }
    if ($permission !== null && !can($permission)) {
        audit('Aktion verweigert', 'Fehlendes Recht: ' . $permission . ' auf ' . basename($_SERVER['SCRIPT_NAME']), 'warn');
        render_notice_page(403, '⛔', 'Keine Berechtigung', 'Dafür fehlt deiner Rolle das Recht „' . (PERMISSIONS[$permission][0] ?? $permission) . '“.');
    }
}

// ---------- NSFW ----------

/** Gilt ein Link als NSFW? (Markierung am Link oder Kategorie-Name mit NSFW/Adult/18+) */
function is_nsfw_link(array $link, string $category = ''): bool {
    return !empty($link['nsfw']) || (bool)preg_match('/\b(nsfw|adult)\b|18\+/i', $category);
}

/** Wird der Link angezeigt? Wer "nsfw.view" hat, sieht immer alles (mit 18+-Markierung). */
function nsfw_visible(array $link, string $category = ''): bool {
    return site_settings()['show_nsfw'] || can('nsfw.view') || can('links.edit') || !is_nsfw_link($link, $category);
}

/** Kleine Leiste "Angemeldet als … / Login" für die Seiten */
function auth_corner(): void {
    $u = current_user(); ?>
    <div class="auth-corner">
        <?php if ($u): ?>
            <a href="admin.php" title="<?php echo e($u['role_name']); ?>"><span class="ac-dot" style="background: <?php echo e($u['role_color']); ?>;"></span><?php echo e(user_label($u)); ?></a>
        <?php else: ?>
            <a href="admin.php?next=<?php echo urlencode(basename($_SERVER['SCRIPT_NAME'])); ?>" title="Anmelden">🔒 Login</a>
        <?php endif; ?>
    </div>
    <style>
        .auth-corner { position: fixed; top: 12px; right: 14px; z-index: 50; font-family: inherit; }
        .auth-corner a { display: inline-flex; align-items: center; gap: 7px; padding: 5px 11px; font-size: 0.72rem; letter-spacing: 1.5px; text-transform: uppercase; text-decoration: none;
            color: #e2e8f0; background: rgba(4, 6, 9, 0.85); border: 1px solid rgba(56, 213, 230, 0.35); backdrop-filter: blur(6px); }
        .auth-corner a:hover { border-color: #38d5e6; color: #38d5e6; }
        .ac-dot { width: 8px; height: 8px; border-radius: 50%; box-shadow: 0 0 8px currentColor; }
        @media (max-width: 640px) { .auth-corner { top: auto; bottom: 12px; } }
    </style>
<?php }
