<?php
/**
 * Anmeldung, Admin-Rechte und Seiten-Einstellungen.
 *
 * - Beim allerersten Aufruf wird man zu admin.php geleitet und legt Benutzername + Passwort an.
 * - Budget, Con-Rechner und Backup sind nur angemeldet sichtbar.
 * - Alle Änderungen (Formulare / POST) sind nur angemeldet möglich.
 *
 * Die Zugangsdaten liegen in .admin_auth.php. Die Datei beginnt mit "<?php exit;" –
 * ein direkter Aufruf im Browser liefert also nie ihren Inhalt (auch ohne .htaccess, z. B. bei nginx).
 */
require_once __DIR__ . '/common.php';

const AUTH_FILE = __DIR__ . '/../.admin_auth.php';
const AUTH_LOG_FILE = __DIR__ . '/../.admin_log.php';
const SETTINGS_FILE = __DIR__ . '/../.site_settings.php';
const AUTH_MAX_FAILS = 5;          // Fehlversuche pro IP …
const AUTH_LOCK_SECONDS = 600;     // … dann 10 Minuten Sperre
const AUTH_IDLE_TIMEOUT = 43200;   // 12 Std. ohne Aktivität -> abmelden (außer "angemeldet bleiben")
const AUTH_REMEMBER = 2592000;     // "Angemeldet bleiben" = 30 Tage

// ---------- Geschützter Datenspeicher ----------

function secure_load(string $path, $default) {
    if (!is_file($path)) return $default;
    $raw = (string)@file_get_contents($path);
    $json = substr($raw, (int)strpos($raw, "\n") + 1);
    $data = json_decode($json, true);
    return is_array($data) ? $data : $default;
}

function secure_save(string $path, array $data): bool {
    $content = "<?php http_response_code(404); exit; ?>\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    return rename($tmp, $path);
}

// ---------- Einstellungen ----------

function site_settings(): array {
    static $settings = null;
    if ($settings === null) $settings = secure_load(SETTINGS_FILE, []) + ['show_nsfw' => false];
    return $settings;
}

function save_site_setting(string $key, $value): void {
    $s = secure_load(SETTINGS_FILE, []);
    $s[$key] = $value;
    secure_save(SETTINGS_FILE, $s);
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

function auth_account(): ?array {
    return secure_load(AUTH_FILE, null);
}

function auth_configured(): bool {
    return auth_account() !== null;
}

/** Angemeldeter Benutzername oder null */
function auth_user(): ?string {
    static $checked = false, $user = null;
    if ($checked) return $user;
    $checked = true;

    // Ohne Session-Cookie gar keine Session starten (Besucher bekommen kein Cookie)
    if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE['GHOSTNODE'])) return null;
    auth_session();

    $acc = auth_account();
    $s = $_SESSION['auth'] ?? null;
    if (!$acc || !$s) return null;

    $limit = !empty($s['remember']) ? AUTH_REMEMBER : AUTH_IDLE_TIMEOUT;
    // Passwortwechsel / "überall abmelden" erhöht die Version -> alte Sitzungen ungültig
    if (($s['version'] ?? -1) !== ($acc['session_version'] ?? 0) || $s['user'] !== $acc['username'] || time() - ($s['last'] ?? 0) > $limit) {
        unset($_SESSION['auth']);
        return null;
    }
    $_SESSION['auth']['last'] = time();
    return $user = $s['user'];
}

function is_admin(): bool {
    return auth_user() !== null;
}

function client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '?');
}

// ---------- Protokoll & Sperre bei Fehlversuchen ----------

function auth_log(string $event, string $user = ''): void {
    $log = secure_load(AUTH_LOG_FILE, ['entries' => [], 'fails' => []]);
    array_unshift($log['entries'], ['t' => time(), 'event' => $event, 'user' => mb_substr($user, 0, 40), 'ip' => client_ip(),
        'agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120)]);
    $log['entries'] = array_slice($log['entries'], 0, 50);
    secure_save(AUTH_LOG_FILE, $log);
}

function auth_locked_seconds(): int {
    $log = secure_load(AUTH_LOG_FILE, ['fails' => []]);
    $f = $log['fails'][client_ip()] ?? null;
    if (!$f || $f['count'] < AUTH_MAX_FAILS) return 0;
    return max(0, $f['last'] + AUTH_LOCK_SECONDS - time());
}

function auth_register_fail(): void {
    $log = secure_load(AUTH_LOG_FILE, ['entries' => [], 'fails' => []]);
    $ip = client_ip();
    $f = $log['fails'][$ip] ?? ['count' => 0, 'last' => 0];
    if (time() - $f['last'] > AUTH_LOCK_SECONDS) $f['count'] = 0;
    $f['count']++;
    $f['last'] = time();
    $log['fails'][$ip] = $f;
    // alte Einträge aufräumen
    foreach ($log['fails'] as $k => $v) if (time() - $v['last'] > 86400) unset($log['fails'][$k]);
    secure_save(AUTH_LOG_FILE, $log);
}

function auth_clear_fails(): void {
    $log = secure_load(AUTH_LOG_FILE, ['entries' => [], 'fails' => []]);
    unset($log['fails'][client_ip()]);
    secure_save(AUTH_LOG_FILE, $log);
}

// ---------- Konto ----------

/** Prüft Benutzername/Passwort-Regeln, liefert Fehlermeldung oder '' */
function auth_validate(string $user, string $pass, string $pass2): string {
    if (!preg_match('/^[\p{L}\p{N}_.\-]{3,32}$/u', $user)) return 'Benutzername: 3–32 Zeichen (Buchstaben, Zahlen, _ . -).';
    if (mb_strlen($pass) < 8) return 'Das Passwort muss mindestens 8 Zeichen lang sein.';
    if ($pass !== $pass2) return 'Die beiden Passwörter stimmen nicht überein.';
    if (mb_strtolower($pass) === mb_strtolower($user)) return 'Passwort und Benutzername dürfen nicht gleich sein.';
    return '';
}

function auth_create(string $user, string $pass): void {
    secure_save(AUTH_FILE, [
        'username' => $user,
        'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
        'created' => time(),
        'password_changed' => time(),
        'session_version' => 1,
    ]);
}

function auth_login(string $user, string $pass, bool $remember): bool {
    $acc = auth_account();
    $ok = $acc && hash_equals(mb_strtolower($acc['username']), mb_strtolower($user)) && password_verify($pass, $acc['password_hash']);
    if (!$ok) {
        auth_register_fail();
        auth_log('Login fehlgeschlagen', $user);
        usleep(random_int(400000, 900000)); // bremst automatisches Durchprobieren
        return false;
    }
    if (password_needs_rehash($acc['password_hash'], PASSWORD_DEFAULT)) {
        $acc['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
        secure_save(AUTH_FILE, $acc);
    }
    auth_start_session($acc, $remember);
    auth_clear_fails();
    auth_log('Login', $acc['username']);
    return true;
}

function auth_start_session(array $acc, bool $remember = false): void {
    auth_session();
    session_regenerate_id(true);
    $_SESSION['auth'] = ['user' => $acc['username'], 'version' => $acc['session_version'] ?? 0, 'last' => time(),
                         'since' => time(), 'remember' => $remember];
    if ($remember) {
        $p = session_get_cookie_params();
        setcookie(session_name(), session_id(), ['expires' => time() + AUTH_REMEMBER, 'path' => $p['path'],
            'httponly' => true, 'samesite' => 'Lax', 'secure' => $p['secure']]);
    }
}

function auth_logout(): void {
    auth_session();
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'httponly' => true, 'samesite' => 'Lax', 'secure' => $p['secure']]);
    session_destroy();
}

// ---------- Schutz für Seiten & Formulare ----------

/** Beim allerersten Aufruf (noch kein Konto) zur Einrichtung auf admin.php leiten. */
function auth_require_setup(): void {
    if (!auth_configured()) {
        header('Location: admin.php');
        exit;
    }
    auth_user();   // Sitzung jetzt starten – später (nach HTML-Ausgabe) ginge das nicht mehr
}

/** Ganze Seite nur für Angemeldete (Budget, Con, Backup). */
function require_admin_page(): void {
    auth_require_setup();
    if (!is_admin()) {
        header('Location: admin.php?next=' . urlencode(basename($_SERVER['SCRIPT_NAME']) . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '')));
        exit;
    }
}

/** Formular kommt wirklich von dieser Seite? (Schutz gegen fremde Seiten, die im Namen des Admins Formulare abschicken) */
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

/** Jede Änderung (POST) nur angemeldet & von der eigenen Seite – sonst Abbruch. */
function guard_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!is_admin()) {
        http_response_code(403);
        header('Location: admin.php?next=' . urlencode(basename($_SERVER['SCRIPT_NAME'])) . '&denied=1');
        exit;
    }
    if (!same_origin_request()) {
        http_response_code(403);
        exit('Anfrage abgelehnt (fremde Herkunft).');
    }
}

// ---------- NSFW ----------

/** Gilt ein Link als NSFW? (Markierung am Link oder Kategorie-Name mit NSFW/Adult/18+) */
function is_nsfw_link(array $link, string $category = ''): bool {
    return !empty($link['nsfw']) || (bool)preg_match('/\b(nsfw|adult)\b|18\+/i', $category);
}

/** Wird der Link angezeigt? Admins sehen immer alles (zum Verwalten). */
function nsfw_visible(array $link, string $category = ''): bool {
    return site_settings()['show_nsfw'] || is_admin() || !is_nsfw_link($link, $category);
}

/** Kleine Leiste "Angemeldet als … / Login" für die Seiten */
function auth_corner(): void {
    $user = is_admin() ? auth_user() : null; ?>
    <div class="auth-corner">
        <?php if ($user): ?>
            <a href="admin.php" title="Admin-Bereich">⚙ <?php echo e($user); ?></a>
        <?php else: ?>
            <a href="admin.php?next=<?php echo urlencode(basename($_SERVER['SCRIPT_NAME'])); ?>" title="Anmelden, um Änderungen vorzunehmen">🔒 Login</a>
        <?php endif; ?>
    </div>
    <style>
        .auth-corner { position: fixed; top: 12px; right: 14px; z-index: 50; font-family: inherit; }
        .auth-corner a { display: inline-block; padding: 5px 11px; font-size: 0.72rem; letter-spacing: 1.5px; text-transform: uppercase; text-decoration: none;
            color: #e2e8f0; background: rgba(4, 6, 9, 0.85); border: 1px solid rgba(56, 213, 230, 0.35); backdrop-filter: blur(6px); }
        .auth-corner a:hover { border-color: #38d5e6; color: #38d5e6; }
        @media (max-width: 640px) { .auth-corner { top: auto; bottom: 12px; } }
    </style>
<?php }
