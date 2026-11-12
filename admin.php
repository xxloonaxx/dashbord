<?php
/**
 * Admin-Zentrale: Ersteinrichtung, Login, Übersicht, Benutzer, Rollen & Rechte, Seiten, Protokoll, System, Mein Konto.
 */
require_once __DIR__ . '/inc/pages.php';
require_once __DIR__ . '/inc/linkcheck.php';
require_once __DIR__ . '/inc/countdown.php';
require_once __DIR__ . '/inc/hub.php';

/** Nur lokale Ziele für die Weiterleitung nach dem Login zulassen */
function safe_next(?string $next): string {
    return ($next && preg_match('/^[A-Za-z0-9_\-]+\.php(\?[A-Za-z0-9_\-=&%.+#]*)?$/', $next)) ? $next : 'admin.php';
}

$db = site_db();
$next = safe_next($_GET['next'] ?? $_POST['next'] ?? null);
$error = '';
$configured = auth_configured();
ip_block_check();
if (isset($_COOKIE['GHOSTNODE'])) auth_session();
$pending = $_SESSION['auth_pending'] ?? null;
if ($pending && time() - $pending['t'] > 300) { unset($_SESSION['auth_pending']); $pending = null; }
$invite_code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['invite'] ?? $_POST['invite'] ?? '')));

// =====================================================================
// Ersteinrichtung & Login
// =====================================================================
if (!$configured) {
    $mode = 'setup';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup'])) {
        $u = trim($_POST['username'] ?? '');
        $error = auth_validate($u, $_POST['password'] ?? '', $_POST['password2'] ?? '');
        if (!$error && !same_origin_request()) $error = 'Anfrage abgelehnt.';
        if (!$error) {
            $id = auth_create($u, $_POST['password'], 1, $u);
            $user = user_load($id);
            auth_start_session($user);
            audit('Konto eingerichtet', 'Erster Administrator', 'security', $user);
            header('Location: admin.php?welcome=1');
            exit;
        }
    }
} elseif (!is_logged_in() && $pending) {
    // Zweiter Schritt: Code aus der Authenticator-App oder Wiederherstellungscode
    $mode = 'totp';
    $locked = auth_locked_seconds();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_cancel'])) {
        unset($_SESSION['auth_pending']);
        header('Location: admin.php');
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_login'])) {
        $pu = user_load((int)$pending['uid']);
        if ($locked) $error = 'Zu viele Fehlversuche – bitte warten.';
        elseif (!same_origin_request() || !$pu) $error = 'Anfrage abgelehnt.';
        elseif ($how = totp_check_login($pu, (string)($_POST['code'] ?? ''))) {
            auth_finish_login($pu, !empty($pending['remember']), $how === 'recovery' ? 'mit Wiederherstellungscode' : '2FA');
            if ($how === 'recovery') audit('Wiederherstellungscode benutzt', count(json_decode(user_load((int)$pu['id'])['totp_recovery'], true) ?: []) . ' übrig', 'security');
            header('Location: ' . ($next === 'admin.php' && !can('admin.access') ? 'index.php' : $next));
            exit;
        } else {
            auth_register_fail();
            $_SESSION['auth_pending']['tries'] = ($pending['tries'] ?? 0) + 1;
            audit('2FA-Code falsch', 'Benutzer: ' . $pu['username'], 'security', $pu);
            if ($_SESSION['auth_pending']['tries'] >= 5) { unset($_SESSION['auth_pending']); header('Location: admin.php?error=' . urlencode('Zu viele falsche Codes – bitte neu anmelden.')); exit; }
            $error = 'Der Code stimmt nicht.';
        }
    }
} elseif (!is_logged_in() && (isset($_GET['invite']) || isset($_POST['invite']))) {
    // Registrierung mit Einladungscode
    $mode = 'register';
    $locked = auth_locked_seconds();
    $invite = ($locked || $invite_code === '') ? null : invite_find($invite_code);
    if (!$invite && !$locked && $invite_code !== '') { auth_register_fail(); $error = 'Dieser Einladungscode ist ungültig, abgelaufen oder schon aufgebraucht.'; }
    if ($locked) $error = 'Zu viele Fehlversuche – bitte warten.';
    if ($invite && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
        $u = trim($_POST['username'] ?? '');
        $error = auth_validate($u, $_POST['password'] ?? '', $_POST['password2'] ?? '');
        if (!$error && !same_origin_request()) $error = 'Anfrage abgelehnt.';
        if (!$error) {
            $d = $db->prepare('SELECT 1 FROM users WHERE username = ? COLLATE NOCASE');
            $d->execute([$u]);
            if ($d->fetchColumn()) $error = 'Den Benutzernamen gibt es schon.';
        }
        if (!$error) {
            $db->beginTransaction();
            $upd = $db->prepare('UPDATE invites SET uses = uses + 1 WHERE code = ? AND uses < max_uses');
            $upd->execute([$invite['code']]);
            if ($upd->rowCount() !== 1) { $db->rollBack(); $error = 'Der Code wurde gerade aufgebraucht.'; }
            else {
                $id = auth_create($u, $_POST['password'], (int)$invite['role_id'], mb_substr(trim($_POST['display_name'] ?? ''), 0, 60));
                $db->prepare('UPDATE users SET invited_by = ?, note = ? WHERE id = ?')->execute([$invite['created_by'], 'Einladung ' . $invite['code'] . ($invite['note'] !== '' ? ' · ' . $invite['note'] : ''), $id]);
                $db->commit();
                $nu = user_load($id);
                audit('Registriert per Einladung', $u . ' · Rolle ' . $invite['role_name'] . ' · Code ' . $invite['code'], 'security', $nu);
                auth_finish_login($nu, false);
                header('Location: ' . (can('admin.access') ? 'admin.php?welcome=1' : 'index.php'));
                exit;
            }
        }
    }
} elseif (!is_logged_in()) {
    $mode = 'login';
    $locked = auth_locked_seconds();
    if (isset($_GET['error'])) $error = (string)$_GET['error'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        $res = false;
        if ($locked) $error = 'Zu viele Fehlversuche – bitte warten.';
        elseif (!same_origin_request()) $error = 'Anfrage abgelehnt.';
        elseif (($res = auth_login(trim($_POST['username'] ?? ''), $_POST['password'] ?? '', !empty($_POST['remember']))) === '2fa') {
            header('Location: admin.php?next=' . urlencode($next));
            exit;
        } elseif ($res) {
            // Ohne Admin-Zugang direkt zur gewünschten Seite bzw. zum Index
            header('Location: ' . ($next === 'admin.php' && !can('admin.access') ? 'index.php' : $next));
            exit;
        } else {
            $locked = auth_locked_seconds();
            $error = $locked ? 'Zu viele Fehlversuche – Login für ' . ceil($locked / 60) . ' Minuten gesperrt.' : 'Benutzername oder Passwort falsch.';
        }
    }
} else {
    $mode = 'panel';
}

// =====================================================================
// Aktionen (angemeldet)
// =====================================================================
$me = current_user();
if ($mode === 'panel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $perm_for = [
        'toggle_nsfw' => 'settings.nsfw', 'user_save' => 'users.manage', 'user_delete' => 'users.manage', 'user_logout' => 'users.manage', 'user_bulk' => 'users.manage',
        'role_save' => 'roles.manage', 'role_delete' => 'roles.manage', 'role_copy' => 'roles.manage', 'matrix_save' => 'roles.manage', 'preview_start' => 'roles.manage',
        'settings_save' => 'system.manage', 'clear_feed_cache' => 'system.manage', 'db_optimize' => 'system.manage',
        'reset_hub' => 'system.manage', 'logout_all' => 'system.manage', 'audit_prune' => 'system.manage', 'unlock_ips' => 'system.manage',
        'security_save' => 'security.manage', 'ip_add' => 'security.manage', 'ip_remove' => 'security.manage', 'ip_unlock' => 'security.manage', 'user_2fa_reset' => 'security.manage',
        'sessions_user_revoke' => 'sessions.manage', 'sessions_prune' => 'sessions.manage',
        'invite_create' => 'invites.manage', 'invite_delete' => 'invites.manage', 'announce_save' => 'announce.manage',
    ];
    // Während „Ansicht als Rolle“ zählt für diese Aktionen die echte Rolle nicht – nur die Vorschau beenden geht immer.
    guard_post($perm_for[$act] ?? null);
    $tab = $_POST['tab'] ?? 'overview';
    // Offener Passwortwechsel: nur das eigene Konto ist bedienbar
    if (!empty($me['must_change_pw']) && empty($me['preview']) && !in_array($act, ['logout', 'account_save', 'totp_begin', 'totp_confirm', 'totp_disable', 'totp_recovery', 'session_revoke', 'account_logout_everywhere', 'preview_end'], true)) {
        header('Location: admin.php?tab=account&error=' . urlencode('Bitte zuerst dein Passwort ändern.'));
        exit;
    }
    $msg = '';
    $i_am_admin = !empty($me['role_is_system']);
    /** Nicht-Admins dürfen keine Administratoren bearbeiten und niemanden zum Administrator machen */
    $assert_can_touch = function (array $target) use ($i_am_admin) {
        if (!$i_am_admin && !empty($target['role_is_system'])) throw new InvalidArgumentException('Administratoren kann nur ein Administrator ändern.');
    };
    /** Rechte, die jemand vergeben darf: nur eigene (Admins: alle). Rechte, die man selbst nicht hat, bleiben unverändert. */
    $merge_perms = function (array $wanted, array $existing) use ($me, $i_am_admin): array {
        $all = array_keys(PERMISSIONS);
        $wanted = array_values(array_intersect($all, $wanted));
        if ($i_am_admin) return $wanted;
        $mine = $me['permissions'];
        return array_values(array_unique(array_merge(array_intersect($wanted, $mine), array_diff($existing, $mine))));
    };
    $role_row = function (int $rid) use ($db) {
        $st = $db->prepare('SELECT * FROM roles WHERE id = ?');
        $st->execute([$rid]);
        return $st->fetch() ?: null;
    };
    $admins_left = function (?int $except = null) use ($db): int {
        $st = $db->prepare('SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_system = 1 AND u.active = 1' . ($except ? ' AND u.id <> ?' : ''));
        $st->execute($except ? [$except] : []);
        return (int)$st->fetchColumn();
    };

    try {
        switch ($act) {
            case 'logout':
                audit('Logout');
                auth_logout();
                header('Location: index.php');
                exit;

            case 'toggle_nsfw':
                $on = !site_settings()['show_nsfw'];
                save_site_setting('show_nsfw', $on);
                audit($on ? 'NSFW für Besucher eingeblendet' : 'NSFW für Besucher ausgeblendet');
                header('Location: ' . safe_next($_POST['back'] ?? 'admin.php'));
                exit;

            // ----- Mein Konto -----
            case 'account_save':
                $display = mb_substr(trim($_POST['display_name'] ?? ''), 0, 60);
                $db->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute([$display, $me['id']]);
                $msg = 'Profil gespeichert.';
                if (($_POST['password'] ?? '') !== '') {
                    $row = $db->query('SELECT password_hash FROM users WHERE id = ' . (int)$me['id'])->fetchColumn();
                    if (!password_verify((string)($_POST['current'] ?? ''), $row)) throw new InvalidArgumentException('Das aktuelle Passwort ist falsch.');
                    if ($e = auth_validate_password($_POST['password'], $_POST['password2'] ?? '', $me['username'])) throw new InvalidArgumentException($e);
                    if (password_verify($_POST['password'], $row)) throw new InvalidArgumentException('Das neue Passwort muss sich vom alten unterscheiden.');
                    $db->prepare("UPDATE users SET password_hash = ?, password_changed = datetime('now','localtime'), session_version = session_version + 1, must_change_pw = 0 WHERE id = ?")
                       ->execute([password_hash($_POST['password'], PASSWORD_DEFAULT), $me['id']]);
                    $db->prepare('DELETE FROM user_sessions WHERE user_id = ?')->execute([$me['id']]);
                    auth_start_session(user_load((int)$me['id']), !empty($_SESSION['auth']['remember']));
                    audit('Eigenes Passwort geändert', '', 'security');
                    $msg = 'Passwort geändert – andere Geräte wurden abgemeldet.';
                }
                break;

            // ----- Zwei-Faktor (eigenes Konto) -----
            case 'totp_begin':
                $_SESSION['totp_setup'] = totp_new_secret();
                $msg = '';
                header('Location: admin.php?tab=account&totp=setup#tfa');
                exit;

            case 'totp_confirm':
                $sec = (string)($_SESSION['totp_setup'] ?? '');
                if ($sec === '') throw new InvalidArgumentException('Bitte die Einrichtung neu starten.');
                if (!totp_verify($sec, (string)($_POST['code'] ?? ''))) {
                    header('Location: admin.php?tab=account&totp=setup&error=' . urlencode('Der Code stimmt nicht – Uhrzeit am Handy prüfen und erneut versuchen.') . '#tfa');
                    exit;
                }
                $_SESSION['totp_codes'] = totp_enable((int)$me['id'], $sec);
                unset($_SESSION['totp_setup']);
                audit('Zwei-Faktor-Anmeldung eingeschaltet', '', 'security');
                header('Location: admin.php?tab=account&totp=codes#tfa');
                exit;

            case 'totp_disable':
            case 'totp_recovery':
                $row = $db->query('SELECT password_hash FROM users WHERE id = ' . (int)$me['id'])->fetchColumn();
                if (!password_verify((string)($_POST['current'] ?? ''), $row)) throw new InvalidArgumentException('Das Passwort ist falsch.');
                if ($act === 'totp_disable') {
                    if (password_policy()['admin_2fa'] && $i_am_admin) throw new InvalidArgumentException('Für Administratoren ist Zwei-Faktor Pflicht (Sicherheit → Richtlinie).');
                    totp_disable((int)$me['id']);
                    audit('Zwei-Faktor-Anmeldung ausgeschaltet', '', 'security');
                    $msg = 'Zwei-Faktor-Anmeldung ausgeschaltet.';
                } else {
                    [$plain, $hashes] = totp_recovery_new();
                    $db->prepare('UPDATE users SET totp_recovery = ? WHERE id = ?')->execute([json_encode($hashes), $me['id']]);
                    $_SESSION['totp_codes'] = $plain;
                    audit('Neue Wiederherstellungscodes erzeugt', '', 'security');
                    header('Location: admin.php?tab=account&totp=codes#tfa');
                    exit;
                }
                break;

            case 'session_revoke':
                $sid = (string)($_POST['sid'] ?? '');
                $st = $db->prepare('SELECT s.user_id, u.username FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE s.id = ?');
                $st->execute([$sid]);
                $row = $st->fetch();
                if (!$row) break;
                if ((int)$row['user_id'] !== (int)$me['id'] && !can('sessions.manage')) throw new InvalidArgumentException('Fremde Sitzungen darfst du nicht beenden.');
                if ((int)$row['user_id'] !== (int)$me['id']) $assert_can_touch(user_load((int)$row['user_id']));
                $db->prepare('DELETE FROM user_sessions WHERE id = ?')->execute([$sid]);
                audit('Sitzung beendet', $row['username'] . ($sid === current_session_id() ? ' (eigene)' : ''), 'security');
                $msg = 'Sitzung beendet.';
                break;

            case 'sessions_user_revoke':
                $uid = (int)($_POST['id'] ?? 0);
                if ($u = user_load($uid)) { $assert_can_touch($u); user_bump_sessions($uid); }
                if ($uid === (int)$me['id']) auth_start_session(user_load($uid), !empty($_SESSION['auth']['remember']));
                audit('Alle Sitzungen eines Benutzers beendet', $u['username'] ?? '#' . $uid, 'security');
                $msg = 'Alle Sitzungen beendet.';
                break;

            case 'sessions_prune':
                $n = $db->exec("DELETE FROM user_sessions WHERE last_seen < datetime('now','localtime','-" . max(1, (int)($_POST['days'] ?? 14)) . " days')");
                $msg = "$n alte Sitzungen entfernt.";
                break;

            // ----- Ansicht als Rolle -----
            case 'preview_start':
                $r = $role_row((int)($_POST['id'] ?? 0));
                if (!$r) break;
                $_SESSION['auth']['preview'] = (int)$r['id'];
                audit('Ansicht als Rolle', $r['name']);
                header('Location: index.php');
                exit;

            case 'preview_end':
                unset($_SESSION['auth']['preview']);
                header('Location: admin.php?tab=roles&msg=' . urlencode('Vorschau beendet.'));
                exit;

            case 'account_logout_everywhere':
                user_bump_sessions((int)$me['id']);
                auth_start_session(user_load((int)$me['id']), !empty($_SESSION['auth']['remember']));
                audit('Andere Geräte abgemeldet', '', 'security');
                $msg = 'Alle anderen Sitzungen wurden beendet.';
                break;

            // ----- Benutzer -----
            case 'user_save':
                $uid = (int)($_POST['id'] ?? 0);
                $username = trim($_POST['username'] ?? '');
                $role = (int)($_POST['role_id'] ?? 0);
                $active = !empty($_POST['active']) ? 1 : 0;
                $display = mb_substr(trim($_POST['display_name'] ?? ''), 0, 60);
                $note = mb_substr(trim($_POST['note'] ?? ''), 0, 200);
                $pw = (string)($_POST['password'] ?? '');
                $rst = $db->prepare('SELECT is_system FROM roles WHERE id = ?');
                $rst->execute([$role]);
                $role_system = $rst->fetchColumn();
                if ($role_system === false) throw new InvalidArgumentException('Bitte eine Rolle wählen.');
                if ($role_system && !$i_am_admin) throw new InvalidArgumentException('Nur Administratoren dürfen die Administrator-Rolle vergeben.');
                $expires = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['expires_at'] ?? '') ? $_POST['expires_at'] . ' 23:59:59' : null;
                $must = !empty($_POST['must_change_pw']) ? 1 : 0;
                if (!preg_match('/^[\p{L}\p{N}_.\-]{3,32}$/u', $username)) throw new InvalidArgumentException('Benutzername: 3–32 Zeichen (Buchstaben, Zahlen, _ . -).');
                $dupe = $db->prepare('SELECT id FROM users WHERE username = ? COLLATE NOCASE AND id <> ?');
                $dupe->execute([$username, $uid]);
                if ($dupe->fetchColumn()) throw new InvalidArgumentException('Den Benutzernamen gibt es schon.');
                if ($uid) {
                    // Den letzten aktiven Administrator nicht entmachten
                    $was = user_load($uid);
                    if (!$was) throw new InvalidArgumentException('Benutzer nicht gefunden.');
                    $assert_can_touch($was);
                    if ($was['role_is_system'] && $was['active'] && (!$role_system || !$active) && $admins_left($uid) === 0) throw new InvalidArgumentException('Es muss mindestens ein aktiver Administrator bleiben.');
                    $db->prepare('UPDATE users SET username = ?, display_name = ?, role_id = ?, active = ?, note = ?, expires_at = ?, must_change_pw = ? WHERE id = ?')->execute([$username, $display, $role, $active, $note, $expires, $must, $uid]);
                    if ($pw !== '') {
                        if ($e = auth_validate_password($pw, $pw, $username)) throw new InvalidArgumentException($e);
                        $db->prepare("UPDATE users SET password_hash = ?, password_changed = datetime('now','localtime') WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), $uid]);
                    }
                    if ($pw !== '' || !$active || (int)$was['role_id'] !== $role) user_bump_sessions($uid);   // neu anmelden lassen
                    if ($uid === (int)$me['id']) auth_start_session(user_load($uid), !empty($_SESSION['auth']['remember']));
                    audit('Benutzer geändert', $username . ' · Rolle #' . $role . ($active ? '' : ' · deaktiviert') . ($pw !== '' ? ' · Passwort neu gesetzt' : ''), 'security');
                    $msg = 'Benutzer „' . $username . '“ gespeichert.';
                } else {
                    if ($e = auth_validate_password($pw, $pw, $username)) throw new InvalidArgumentException($e);
                    $new = auth_create($username, $pw, $role, $display);
                    $db->prepare('UPDATE users SET active = ?, note = ?, expires_at = ?, must_change_pw = ? WHERE id = ?')->execute([$active, $note, $expires, $must, $new]);
                    audit('Benutzer angelegt', $username . ' · Rolle #' . $role, 'security');
                    $msg = 'Benutzer „' . $username . '“ angelegt.';
                }
                break;

            case 'user_delete':
                $uid = (int)($_POST['id'] ?? 0);
                $u = user_load($uid);
                if (!$u) break;
                if ($uid === (int)$me['id']) throw new InvalidArgumentException('Du kannst dich nicht selbst löschen.');
                $assert_can_touch($u);
                if ($u['role_is_system'] && $admins_left($uid) === 0) throw new InvalidArgumentException('Es muss mindestens ein aktiver Administrator bleiben.');
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
                audit('Benutzer gelöscht', $u['username'], 'security');
                $msg = 'Benutzer „' . $u['username'] . '“ gelöscht.';
                break;

            case 'user_logout':
                $uid = (int)($_POST['id'] ?? 0);
                if ($u = user_load($uid)) $assert_can_touch($u);
                user_bump_sessions($uid);
                if ($uid === (int)$me['id']) auth_start_session(user_load($uid), !empty($_SESSION['auth']['remember']));
                audit('Sitzungen eines Benutzers beendet', '#' . $uid, 'security');
                $msg = 'Alle Sitzungen des Benutzers wurden beendet.';
                break;

            case 'user_bulk':
                $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
                $op = (string)($_POST['op'] ?? '');
                if (!$ids) throw new InvalidArgumentException('Bitte zuerst Benutzer auswählen.');
                $done = 0;
                $db->beginTransaction();
                foreach ($ids as $uid) {
                    $u = user_load($uid);
                    if (!$u) continue;
                    $assert_can_touch($u);
                    $self = $uid === (int)$me['id'];
                    switch ($op) {
                        case 'activate': $db->prepare('UPDATE users SET active = 1 WHERE id = ?')->execute([$uid]); break;
                        case 'deactivate':
                            if ($self) continue 2;
                            if ($u['role_is_system'] && $admins_left($uid) === 0) throw new InvalidArgumentException('Es muss mindestens ein aktiver Administrator bleiben.');
                            $db->prepare('UPDATE users SET active = 0 WHERE id = ?')->execute([$uid]);
                            user_bump_sessions($uid);
                            break;
                        case 'logout': if (!$self) user_bump_sessions($uid); break;
                        case 'must_change': $db->prepare('UPDATE users SET must_change_pw = 1 WHERE id = ?')->execute([$uid]); break;
                        case 'role':
                            $nr = $role_row((int)($_POST['role_id'] ?? 0));
                            if (!$nr) throw new InvalidArgumentException('Bitte eine Rolle wählen.');
                            if ($nr['is_system'] && !$i_am_admin) throw new InvalidArgumentException('Nur Administratoren dürfen die Administrator-Rolle vergeben.');
                            if ($u['role_is_system'] && !$nr['is_system'] && $admins_left($uid) === 0) throw new InvalidArgumentException('Es muss mindestens ein aktiver Administrator bleiben.');
                            $db->prepare('UPDATE users SET role_id = ? WHERE id = ?')->execute([$nr['id'], $uid]);
                            if (!$self) user_bump_sessions($uid);
                            break;
                        case 'expire':
                            $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date'] ?? '') ? $_POST['date'] . ' 23:59:59' : null;
                            $db->prepare('UPDATE users SET expires_at = ? WHERE id = ?')->execute([$d, $uid]);
                            break;
                        case 'delete':
                            if ($self) continue 2;
                            if ($u['role_is_system'] && $admins_left($uid) === 0) throw new InvalidArgumentException('Es muss mindestens ein aktiver Administrator bleiben.');
                            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
                            break;
                        default: throw new InvalidArgumentException('Unbekannte Aktion.');
                    }
                    $done++;
                }
                $db->commit();
                $labels = ['activate' => 'aktiviert', 'deactivate' => 'deaktiviert', 'logout' => 'abgemeldet', 'must_change' => 'müssen Passwort ändern', 'role' => 'Rolle geändert', 'expire' => 'Ablaufdatum gesetzt', 'delete' => 'gelöscht'];
                audit('Sammelaktion Benutzer', $done . ' × ' . ($labels[$op] ?? $op), 'security');
                $msg = $done . ' Benutzer: ' . ($labels[$op] ?? $op) . '.';
                break;

            case 'user_2fa_reset':
                $u = user_load((int)($_POST['id'] ?? 0));
                if (!$u) break;
                $assert_can_touch($u);
                totp_disable((int)$u['id']);
                audit('Zwei-Faktor zurückgesetzt', $u['username'], 'security');
                $msg = 'Zwei-Faktor für „' . $u['username'] . '“ zurückgesetzt.';
                break;

            // ----- Rollen -----
            case 'role_save':
                $rid = (int)($_POST['id'] ?? 0);
                $name = mb_substr(trim($_POST['name'] ?? ''), 0, 40);
                if ($name === '') throw new InvalidArgumentException('Bitte einen Rollennamen eingeben.');
                $color = preg_match('/^#[0-9a-f]{6}$/i', $_POST['color'] ?? '') ? $_POST['color'] : '#38d5e6';
                $desc = mb_substr(trim($_POST['description'] ?? ''), 0, 200);
                $prev = $rid ? role_permissions($rid) : [];
                $perms = $merge_perms((array)($_POST['perms'] ?? []), $prev);
                $dupe = $db->prepare('SELECT id FROM roles WHERE name = ? COLLATE NOCASE AND id <> ?');
                $dupe->execute([$name, $rid]);
                if ($dupe->fetchColumn()) throw new InvalidArgumentException('Diese Rolle gibt es schon.');
                $db->beginTransaction();
                if ($rid) {
                    $db->prepare('UPDATE roles SET name = ?, color = ?, description = ? WHERE id = ?')->execute([$name, $color, $desc, $rid]);
                } else {
                    $db->prepare('INSERT INTO roles (name, color, description) VALUES (?, ?, ?)')->execute([$name, $color, $desc]);
                    $rid = (int)$db->lastInsertId();
                }
                $sys = (int)$db->query('SELECT is_system FROM roles WHERE id = ' . $rid)->fetchColumn();
                if (!$sys) {
                    $db->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$rid]);
                    $ins = $db->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)');
                    foreach ($perms as $p) $ins->execute([$rid, $p]);
                }
                $db->commit();
                audit('Rolle gespeichert', $name . ($sys ? '' : ' · ' . count($perms) . ' Rechte'), 'security');
                $msg = 'Rolle „' . $name . '“ gespeichert.';
                break;

            case 'role_copy':
                $r = $role_row((int)($_POST['id'] ?? 0));
                if (!$r) break;
                $name = mb_substr($r['name'] . ' (Kopie)', 0, 40);
                for ($n = 2; $db->query('SELECT COUNT(*) FROM roles WHERE name = ' . $db->quote($name))->fetchColumn(); $n++) $name = mb_substr($r['name'] . ' (Kopie ' . $n . ')', 0, 40);
                $db->beginTransaction();
                $db->prepare('INSERT INTO roles (name, color, description) VALUES (?, ?, ?)')->execute([$name, $r['color'], $r['description']]);
                $nid = (int)$db->lastInsertId();
                $ins = $db->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)');
                foreach ($merge_perms(role_permissions((int)$r['id'], (bool)$r['is_system']), []) as $p) $ins->execute([$nid, $p]);
                $db->commit();
                audit('Rolle kopiert', $r['name'] . ' → ' . $name, 'security');
                $msg = 'Rolle „' . $name . '“ angelegt.';
                break;

            case 'matrix_save':
                $grid = (array)($_POST['m'] ?? []);
                $db->beginTransaction();
                $n = 0;
                foreach ($db->query('SELECT * FROM roles WHERE is_system = 0')->fetchAll() as $r) {
                    $rid = (int)$r['id'];
                    if (!isset($_POST['present'][$rid])) continue;
                    $perms = $merge_perms(array_keys((array)($grid[$rid] ?? [])), role_permissions($rid));
                    $db->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$rid]);
                    $ins = $db->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)');
                    foreach ($perms as $p) $ins->execute([$rid, $p]);
                    $n++;
                }
                $db->commit();
                audit('Rechte-Matrix gespeichert', $n . ' Rollen', 'security');
                $msg = 'Rechte-Matrix gespeichert.';
                break;

            // ----- Einladungen -----
            case 'invite_create':
                $r = $role_row((int)($_POST['role_id'] ?? 0));
                if (!$r) throw new InvalidArgumentException('Bitte eine Rolle wählen.');
                if ($r['is_system'] && !$i_am_admin) throw new InvalidArgumentException('Nur Administratoren dürfen in die Administrator-Rolle einladen.');
                if (!$i_am_admin && array_diff(role_permissions((int)$r['id']), $me['permissions'])) throw new InvalidArgumentException('Diese Rolle hat Rechte, die du selbst nicht hast.');
                $code = invite_new((int)$r['id'], (int)($_POST['max_uses'] ?? 1), (int)($_POST['days'] ?? 7), mb_substr(trim($_POST['note'] ?? ''), 0, 120), $me['username']);
                audit('Einladung erstellt', $code . ' · Rolle ' . $r['name'], 'security');
                header('Location: admin.php?tab=invites&new=' . $code);
                exit;

            case 'invite_delete':
                $db->prepare('DELETE FROM invites WHERE code = ?')->execute([(string)($_POST['code'] ?? '')]);
                audit('Einladung gelöscht', (string)($_POST['code'] ?? ''));
                $msg = 'Einladung gelöscht.';
                break;

            // ----- Sicherheit -----
            case 'security_save':
                set_setting('pw_min', (string)max(8, min(64, (int)($_POST['pw_min'] ?? 8))));
                set_setting('pw_classes', (string)max(0, min(4, (int)($_POST['pw_classes'] ?? 0))));
                set_setting('pw_max_age', (string)max(0, min(3650, (int)($_POST['pw_max_age'] ?? 0))));
                set_setting('pw_common', !empty($_POST['pw_common']) ? '1' : '0');
                set_setting('pw_admin_2fa', !empty($_POST['pw_admin_2fa']) ? '1' : '0');
                audit('Sicherheits-Richtlinie gespeichert', 'min ' . setting('pw_min') . ' · Zeichenarten ' . setting('pw_classes') . ' · Höchstalter ' . setting('pw_max_age') . ' T.', 'security');
                $msg = 'Richtlinie gespeichert.';
                break;

            case 'ip_add':
                $ip = ip_rule_normalize((string)($_POST['ip'] ?? ''));
                if ($ip === '') throw new InvalidArgumentException('Ungültige Adresse. Beispiele: 203.0.113.7 · 203.0.113.0/24 · 2001:db8::/48');
                if (ip_match(client_ip(), $ip)) throw new InvalidArgumentException('Damit würdest du dich selbst aussperren (deine Adresse: ' . client_ip() . ').');
                $hours = (int)($_POST['hours'] ?? 0);
                $rules = array_values(array_filter(ip_rules(), function ($r) use ($ip) { return $r['ip'] !== $ip; }));
                $rules[] = ['ip' => $ip, 'note' => mb_substr(trim($_POST['note'] ?? ''), 0, 120), 'added' => date('Y-m-d H:i:s'), 'by' => $me['username'],
                            'until' => $hours > 0 ? date('Y-m-d H:i:s', time() + $hours * 3600) : ''];
                set_setting('ip_block', json_encode($rules, JSON_UNESCAPED_UNICODE));
                $db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
                audit('IP gesperrt', $ip . ($hours ? " · $hours Std." : ' · dauerhaft'), 'security');
                $msg = $ip . ' gesperrt.';
                break;

            case 'ip_remove':
                $ip = (string)($_POST['ip'] ?? '');
                set_setting('ip_block', json_encode(array_values(array_filter(ip_rules(), function ($r) use ($ip) { return $r['ip'] !== $ip; })), JSON_UNESCAPED_UNICODE));
                audit('IP-Sperre aufgehoben', $ip, 'security');
                $msg = 'Sperre für ' . $ip . ' aufgehoben.';
                break;

            case 'ip_unlock':
                $db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([(string)($_POST['ip'] ?? '')]);
                audit('Login-Sperre aufgehoben', (string)($_POST['ip'] ?? ''), 'security');
                $msg = 'Login-Sperre aufgehoben.';
                break;

            // ----- Ankündigung -----
            case 'announce_save':
                $text = mb_substr(trim($_POST['text'] ?? ''), 0, 500);
                $until = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $_POST['until'] ?? '') ? str_replace('T', ' ', $_POST['until']) . ':00' : '';
                $a = ['text' => isset($_POST['clear']) ? '' : $text, 'level' => in_array($_POST['level'] ?? '', ['info', 'success', 'warn', 'danger'], true) ? $_POST['level'] : 'info',
                      'until' => $until, 'audience' => in_array($_POST['audience'] ?? '', ['all', 'users', 'guests'], true) ? $_POST['audience'] : 'all',
                      'dismissible' => !empty($_POST['dismissible']), 'id' => substr(md5($text . $until . microtime()), 0, 10)];
                set_setting('announcement', json_encode($a, JSON_UNESCAPED_UNICODE));
                audit($a['text'] === '' ? 'Ankündigung entfernt' : 'Ankündigung gesetzt', mb_substr($a['text'], 0, 80));
                $msg = $a['text'] === '' ? 'Ankündigung entfernt.' : 'Ankündigung ist jetzt sichtbar.';
                break;

            case 'role_delete':
                $rid = (int)($_POST['id'] ?? 0);
                $r = $db->query('SELECT * FROM roles WHERE id = ' . $rid)->fetch();
                if (!$r) break;
                if ($r['is_system']) throw new InvalidArgumentException('Die Administrator-Rolle kann nicht gelöscht werden.');
                if ((int)$db->query('SELECT COUNT(*) FROM users WHERE role_id = ' . $rid)->fetchColumn()) throw new InvalidArgumentException('Der Rolle sind noch Benutzer zugeordnet – bitte erst umstellen.');
                $db->prepare('DELETE FROM roles WHERE id = ?')->execute([$rid]);
                audit('Rolle gelöscht', $r['name'], 'security');
                $msg = 'Rolle „' . $r['name'] . '“ gelöscht.';
                break;

            // ----- System -----
            case 'settings_save':
                save_site_setting('site_title', mb_substr(trim($_POST['site_title'] ?? '') ?: 'Startseite', 0, 60));
                save_site_setting('site_tagline', mb_substr(trim($_POST['site_tagline'] ?? ''), 0, 120));
                $maint = !empty($_POST['maintenance']);
                if ($maint !== site_settings()['maintenance']) audit($maint ? 'Wartungsmodus EIN' : 'Wartungsmodus AUS', '', 'warn');
                save_site_setting('maintenance', $maint);
                $dt = function ($v) { return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string)$v) ? str_replace('T', ' ', $v) . ':00' : ''; };
                set_setting('maintenance_from', $dt($_POST['maintenance_from'] ?? ''));
                set_setting('maintenance_until', $dt($_POST['maintenance_until'] ?? ''));
                save_site_setting('maintenance_message', mb_substr(trim($_POST['maintenance_message'] ?? ''), 0, 300) ?: 'Wir sind gleich wieder da.');
                $msg = 'Einstellungen gespeichert.';
                break;

            case 'clear_feed_cache':
                require_once __DIR__ . '/inc/feed.php';
                $r = feed_refresh(null, true);
                audit('Feeds neu geladen');
                $msg = 'Alle Feeds wurden neu abgerufen' . (is_array($r) && isset($r['new']) ? ' – ' . (int)$r['new'] . ' neue Einträge.' : '.');
                break;

            case 'db_optimize':
                $before = filesize(site_db_file());
                $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
                $db->exec('VACUUM');
                $db->exec('PRAGMA optimize');
                clearstatcache();
                audit('Datenbank optimiert');
                $msg = 'Datenbank optimiert (' . round($before / 1024) . ' → ' . round(filesize(site_db_file()) / 1024) . ' KB).';
                break;

            case 'reset_hub':
                $hub = basename($_POST['hub'] ?? '');
                if (in_array($hub, find_hub_files(), true)) {
                    $file = hub_data_file($hub);
                    if (is_file($file)) {
                        if (!is_dir(__DIR__ . '/archive')) mkdir(__DIR__ . '/archive', 0755, true);
                        rename($file, __DIR__ . '/archive/' . pathinfo($file, PATHINFO_FILENAME) . '_backup_' . date('Y-m-d_His') . '.json');
                        audit('Hub zurückgesetzt', $hub, 'warn');
                        $msg = '„' . $hub . '“ wurde auf den Startbestand zurückgesetzt (Kopie im Ordner archive/).';
                    } else {
                        $msg = '„' . $hub . '“ nutzt bereits den Startbestand.';
                    }
                }
                break;

            case 'logout_all':
                $db->prepare('UPDATE users SET session_version = session_version + 1 WHERE id <> ?')->execute([$me['id']]);
                audit('Alle anderen Benutzer abgemeldet', '', 'security');
                $msg = 'Alle anderen Benutzer wurden abgemeldet.';
                break;

            case 'unlock_ips':
                $db->exec('DELETE FROM login_attempts');
                audit('Login-Sperren aufgehoben', '', 'security');
                $msg = 'Alle Login-Sperren wurden aufgehoben.';
                break;

            case 'audit_prune':
                $days = max(7, (int)($_POST['days'] ?? 90));
                $n = $db->exec("DELETE FROM audit_log WHERE ts < datetime('now', '-$days days')");
                audit('Protokoll aufgeräumt', "$n Einträge älter als $days Tage gelöscht");
                $msg = "$n alte Einträge gelöscht.";
                break;
        }
    } catch (InvalidArgumentException $e) {
        if ($db->inTransaction()) $db->rollBack();
        header('Location: admin.php?tab=' . urlencode($tab) . (isset($_POST['back_user']) ? '&user=' . (int)$_POST['back_user'] : '') . '&error=' . urlencode($e->getMessage()));
        exit;
    }
    header('Location: admin.php?tab=' . urlencode($tab) . (isset($_POST['back_user']) ? '&user=' . (int)$_POST['back_user'] : '') . ($msg ? '&msg=' . urlencode($msg) : ''));
    exit;
}

// =====================================================================
// Daten für die Ansichten
// =====================================================================
if ($mode === 'panel') {
    pages_sync();
    $TABS = [
        'overview' => ['📊', 'Übersicht', 'admin.access'],
        'users'    => ['👥', 'Benutzer', ['users.view', 'users.manage']],
        'roles'    => ['🛡️', 'Rollen & Rechte', 'roles.manage'],
        'invites'  => ['🎟️', 'Einladungen', 'invites.manage'],
        'sessions' => ['🖥️', 'Sitzungen', 'sessions.manage'],
        'security' => ['🔐', 'Sicherheit', 'security.manage'],
        'pages'    => ['🗂️', 'Seiten', 'pages.manage'],
        'audit'    => ['📜', 'Protokoll', 'audit.view'],
        'system'   => ['🛰️', 'System', 'system.manage'],
        'account'  => ['👤', 'Mein Konto', null],
    ];
    $TABS = array_filter($TABS, function ($t) { return $t[2] === null || count(array_filter((array)$t[2], 'can')) > 0; });
    $tab = $_GET['tab'] ?? (can('admin.access') ? 'overview' : 'account');
    if (!isset($TABS[$tab])) $tab = 'account';
    // Pflichten zuerst: Passwort ändern bzw. Zwei-Faktor für Admins einrichten
    $must_pw = !empty($me['must_change_pw']) && empty($me['preview']);
    $must_2fa = !$must_pw && empty($me['preview']) && !empty($me['role_is_system']) && password_policy()['admin_2fa'] && $me['totp_secret'] === '';
    if ($must_pw || $must_2fa) $tab = 'account';
    $pol = password_policy();

    // Filter des Protokolls (Ansicht & CSV-Export)
    $audit_where = function () {
        $where = []; $args = [];
        if (($q = trim($_GET['q'] ?? '')) !== '') { $where[] = '(action LIKE :q OR detail LIKE :q OR username LIKE :q OR ip LIKE :q)'; $args[':q'] = '%' . $q . '%'; }
        if (in_array($_GET['level'] ?? '', ['info', 'warn', 'security'], true)) { $where[] = 'level = :lvl'; $args[':lvl'] = $_GET['level']; }
        if (($uf = trim($_GET['user'] ?? '')) !== '') { $where[] = 'username = :u'; $args[':u'] = $uf; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '')) { $where[] = 'ts >= :from'; $args[':from'] = $_GET['from'] . ' 00:00:00'; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')) { $where[] = 'ts <= :to'; $args[':to'] = $_GET['to'] . ' 23:59:59'; }
        return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $args];
    };
    if ($tab === 'audit' && isset($_GET['export'])) {
        if (!can('audit.export')) { header('Location: admin.php?tab=audit&error=' . urlencode('Für den Export fehlt das Recht „Protokoll als CSV exportieren“.')); exit; }
        [$sqlw, $args] = $audit_where();
        audit('Protokoll exportiert', $sqlw !== '' ? 'gefiltert' : 'komplett');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="protokoll_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Zeit', 'Benutzer', 'Aktion', 'Details', 'Stufe', 'IP'], ';');
        $st = $db->prepare('SELECT ts, username, action, detail, level, ip FROM audit_log' . $sqlw . ' ORDER BY id DESC');
        $st->execute($args);
        foreach ($st as $r) fputcsv($out, array_values($r), ';');
        fclose($out);
        exit;
    }

    $roles = $db->query('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count FROM roles r ORDER BY r.is_system DESC, r.id')->fetchAll();
    $settings = site_settings();
    $flash = $_GET['msg'] ?? '';
    $flash_err = $_GET['error'] ?? '';

    if ($tab === 'overview') {
        $stats = [
            'users' => (int)$db->query('SELECT COUNT(*) FROM users WHERE active = 1')->fetchColumn(),
            'users_all' => (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'pages' => (int)$db->query('SELECT COUNT(*) FROM pages')->fetchColumn(),
            'custom' => (int)$db->query("SELECT COUNT(*) FROM pages WHERE kind = 'custom'")->fetchColumn(),
            'protected' => (int)$db->query("SELECT COUNT(*) FROM pages WHERE access <> 'public'")->fetchColumn(),
            'fails24' => (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE level = 'security' AND action LIKE 'Login fehl%' AND ts > datetime('now','localtime','-1 day')")->fetchColumn(),
            'locked' => (int)$db->query('SELECT COUNT(*) FROM login_attempts WHERE count >= ' . AUTH_MAX_FAILS . ' AND last > ' . (time() - AUTH_LOCK_SECONDS))->fetchColumn(),
        ];
        $links = collect_hub_links();
        $lc = load_linkcheck();
        $lc_bad = count(array_filter($lc['results'], function ($r) { return in_array($r['status'], ['dead', 'broken'], true); }));
        try {
            require_once __DIR__ . '/inc/calendar.php';
            $cal_events = (int)cal_db()->query('SELECT COUNT(*) FROM events')->fetchColumn();
            $cal_subs = (int)cal_db()->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn();
        } catch (Throwable $e) { $cal_events = $cal_subs = 0; }
        [$upcoming] = sorted_countdowns(load_countdowns()['events']);

        // Aktivität der letzten 14 Tage
        $activity = [];
        for ($i = 13; $i >= 0; $i--) $activity[date('Y-m-d', strtotime("-$i days"))] = ['login' => 0, 'other' => 0, 'sec' => 0];
        foreach ($db->query("SELECT date(ts) AS d, action, level FROM audit_log WHERE ts >= date('now','localtime','-13 days')") as $r) {
            if (!isset($activity[$r['d']])) continue;
            if ($r['level'] === 'security' && stripos($r['action'], 'fehl') !== false) $activity[$r['d']]['sec']++;
            elseif ($r['action'] === 'Login') $activity[$r['d']]['login']++;
            else $activity[$r['d']]['other']++;
        }
        $recent = $db->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 8')->fetchAll();
        $stats['online'] = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM user_sessions WHERE last_seen > datetime('now','localtime','-15 minutes')")->fetchColumn();
        $stats['sessions'] = (int)$db->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn();
        $stats['tfa'] = (int)$db->query("SELECT COUNT(*) FROM users WHERE active = 1 AND totp_secret <> ''")->fetchColumn();
        $stats['blocked'] = count(ip_rules());
        $ann = announcement();
    }
    if ($tab === 'users' || $tab === 'overview' || $tab === 'security') {
        $users = $db->query("SELECT u.*, r.name AS role_name, r.color AS role_color, r.is_system,
                                    (SELECT COUNT(*) FROM user_sessions s WHERE s.user_id = u.id) AS session_count,
                                    (SELECT MAX(last_seen) FROM user_sessions s WHERE s.user_id = u.id) AS last_seen,
                                    EXISTS (SELECT 1 FROM user_sessions s WHERE s.user_id = u.id AND s.last_seen > datetime('now','localtime','-15 minutes')) AS online
                             FROM users u JOIN roles r ON r.id = u.role_id ORDER BY r.is_system DESC, u.username")->fetchAll();
    }
    $can_um = can('users.manage');
    if ($tab === 'users' && ($detail_id = (int)($_GET['user'] ?? 0))) {
        $detail = user_load($detail_id);
        if ($detail) {
            $st = $db->prepare("SELECT * FROM audit_log WHERE user_id = ? OR (username = ? AND action LIKE 'Login%') ORDER BY id DESC LIMIT 40");
            $st->execute([$detail_id, $detail['username']]);
            $detail_log = $st->fetchAll();
            $detail_sessions = sessions_list($detail_id);
            $detail_ips = $db->prepare("SELECT ip, COUNT(*) AS n, MAX(ts) AS last FROM audit_log WHERE user_id = ? AND action = 'Login' GROUP BY ip ORDER BY last DESC LIMIT 10");
            $detail_ips->execute([$detail_id]);
            $detail_ips = $detail_ips->fetchAll();
        }
    }
    if ($tab === 'invites') {
        $invites = $db->query("SELECT i.*, r.name AS role_name, r.color AS role_color,
                                      CASE WHEN i.uses >= i.max_uses THEN 'used' WHEN i.expires_at IS NOT NULL AND i.expires_at < " . $db->quote(date('Y-m-d H:i:s')) . " THEN 'expired' ELSE 'ok' END AS state
                               FROM invites i JOIN roles r ON r.id = i.role_id ORDER BY i.created_at DESC")->fetchAll();
        $invited = $db->query("SELECT username, display_name, note, created_at FROM users WHERE note LIKE 'Einladung %' ORDER BY id DESC LIMIT 20")->fetchAll();
        $base_url = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/admin.php?invite=';
    }
    if ($tab === 'sessions') $all_sessions = sessions_list();
    if ($tab === 'security') {
        $ip_rules = ip_rules();
        $locks = $db->query('SELECT * FROM login_attempts ORDER BY last DESC LIMIT 50')->fetchAll();
        $fail_ips = $db->query("SELECT ip, COUNT(*) AS n, MAX(ts) AS last, GROUP_CONCAT(DISTINCT REPLACE(detail, 'Benutzername: ', '')) AS names FROM audit_log
                                WHERE level = 'security' AND (action LIKE 'Login fehl%' OR action LIKE '2FA-Code%') AND ts > datetime('now','localtime','-7 days') GROUP BY ip ORDER BY n DESC LIMIT 15")->fetchAll();
    }
    if ($tab === 'roles') {
        $role_perms = [];
        foreach ($db->query('SELECT role_id, permission FROM role_permissions') as $r) $role_perms[(int)$r['role_id']][] = $r['permission'];
        $perm_groups = [];
        foreach (PERMISSIONS as $k => [$label, $group]) $perm_groups[$group][$k] = $label;
    }
    if ($tab === 'pages') {
        $pages = $db->query('SELECT p.*, c.name AS cat_name, c.icon AS cat_icon, u.username AS creator FROM pages p LEFT JOIN page_categories c ON c.id = p.category_id LEFT JOIN users u ON u.id = p.created_by ORDER BY c.sort, p.sort')->fetchAll();
        $role_names = array_column($roles, 'name', 'id');
    }
    if ($tab === 'audit') {
        [$sqlw, $args] = $audit_where();
        $page_no = max(1, (int)($_GET['page'] ?? 1));
        $audit_users = $db->query("SELECT DISTINCT username FROM audit_log WHERE username <> '' ORDER BY username COLLATE NOCASE LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
        $cnt = $db->prepare('SELECT COUNT(*) FROM audit_log' . $sqlw); $cnt->execute($args);
        $audit_total = (int)$cnt->fetchColumn();
        $st = $db->prepare('SELECT * FROM audit_log' . $sqlw . ' ORDER BY id DESC LIMIT 50 OFFSET ' . (($page_no - 1) * 50));
        $st->execute($args);
        $audit = $st->fetchAll();
    }
    if ($tab === 'system') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $server_soft = (string)($_SERVER['SERVER_SOFTWARE'] ?? '');
        $sqlite = class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true);
        $checks = [
            ['HTTPS-Verbindung', $https, $https ? 'verschlüsselt' : 'Passwörter werden unverschlüsselt übertragen – HTTPS beim Hoster aktivieren!'],
            ['PHP-Version', version_compare(PHP_VERSION, '7.4', '>='), PHP_VERSION],
            ['SQLite (Benutzer, Seiten, Kalender)', $sqlite, $sqlite ? 'aktiv · Version ' . $db->query('SELECT sqlite_version()')->fetchColumn() : 'fehlt'],
            ['cURL (Feed, Link-Check, Abos, Bank)', function_exists('curl_multi_init'), function_exists('curl_multi_init') ? 'aktiv' : 'fehlt'],
            ['ZIP (Backup)', class_exists('ZipArchive'), class_exists('ZipArchive') ? 'aktiv' : 'fehlt – Backup als JSON'],
            ['Ordner beschreibbar', is_writable(__DIR__) && is_writable(__DIR__ . '/data'), is_writable(__DIR__) ? 'ja' : 'nein – Daten können nicht gespeichert werden'],
            ['.htaccess (Datenschutz)', is_file(__DIR__ . '/.htaccess'), stripos($server_soft, 'nginx') !== false ? 'nginx erkannt – .htaccess wirkt dort nicht!' : (is_file(__DIR__ . '/.htaccess') ? 'vorhanden' : 'fehlt')],
            ['Zeitzone', true, date_default_timezone_get() . ' · ' . date('d.m.Y H:i')],
        ];
        $storage = [];
        foreach (array_merge(glob(__DIR__ . '/data/*.sqlite') ?: [], glob(__DIR__ . '/*.json') ?: []) as $f) $storage[str_replace(__DIR__ . '/', '', preg_replace('/_[0-9a-f]{24}\.sqlite$/', '_….sqlite', $f))] = filesize($f);
        $storage['archive/ (' . count(glob(__DIR__ . '/archive*/*.json') ?: []) . ' Dateien)'] = array_sum(array_map('filesize', glob(__DIR__ . '/archive*/*.json') ?: []));
        arsort($storage);
        $hubs = find_hub_files();
    }
    if ($tab === 'account') {
        $my_log = $db->prepare('SELECT * FROM audit_log WHERE user_id = ? ORDER BY id DESC LIMIT 12');
        $my_log->execute([$me['id']]);
        $my_log = $my_log->fetchAll();
        $my_sessions = sessions_list((int)$me['id']);
        $totp_setup = ($_GET['totp'] ?? '') === 'setup' ? (string)($_SESSION['totp_setup'] ?? '') : '';
        $totp_codes = ($_GET['totp'] ?? '') === 'codes' ? ($_SESSION['totp_codes'] ?? []) : [];
        unset($_SESSION['totp_codes']);
        $recovery_left = count(json_decode($me['totp_recovery'] ?: '[]', true) ?: []);
    }

    // Befehlspalette (Strg+K)
    $palette = [];
    foreach ($TABS as $k => [$ic, $label]) $palette[] = ['icon' => $ic, 'label' => $label, 'hint' => 'Bereich', 'href' => 'admin.php?tab=' . $k];
    if (can('users.manage')) $palette[] = ['icon' => '➕', 'label' => 'Benutzer anlegen', 'hint' => 'Aktion', 'href' => 'admin.php?tab=users#new'];
    if (can('invites.manage')) $palette[] = ['icon' => '🎟️', 'label' => 'Einladung erstellen', 'hint' => 'Aktion', 'href' => 'admin.php?tab=invites'];
    if (can('security.manage')) $palette[] = ['icon' => '🚫', 'label' => 'IP sperren', 'hint' => 'Aktion', 'href' => 'admin.php?tab=security#ipblock'];
    if (can('announce.manage')) $palette[] = ['icon' => '📢', 'label' => 'Ankündigung setzen', 'hint' => 'Aktion', 'href' => 'admin.php?tab=overview#announce'];
    $palette[] = ['icon' => '🔐', 'label' => 'Zwei-Faktor-Anmeldung', 'hint' => 'Mein Konto', 'href' => 'admin.php?tab=account#tfa'];
    if (can('pages.manage')) {
        $palette[] = ['icon' => '✨', 'label' => 'Neue Seite anlegen', 'hint' => 'Aktion', 'href' => 'index.php?edit=1#new'];
        $palette[] = ['icon' => '✎', 'label' => 'Index bearbeiten & sortieren', 'hint' => 'Aktion', 'href' => 'index.php?edit=1'];
    }
    foreach ($db->query('SELECT * FROM pages ORDER BY title') as $p) {
        if (page_access_ok($p)) $palette[] = ['icon' => $p['icon'] ?: '📄', 'label' => $p['title'], 'hint' => 'Seite', 'href' => page_href($p)];
    }
}

/** Kleine Helfer für die Ansicht */
function ago(?string $ts): string {
    if (!$ts) return 'nie';
    $d = time() - strtotime($ts);
    if ($d < 60) return 'gerade eben';
    if ($d < 3600) return 'vor ' . floor($d / 60) . ' Min.';
    if ($d < 86400) return 'vor ' . floor($d / 3600) . ' Std.';
    if ($d < 86400 * 30) return 'vor ' . floor($d / 86400) . ' Tagen';
    return date('d.m.Y', strtotime($ts));
}
function avatar(array $u, string $cls = ''): string {
    $name = $u['display_name'] ?: $u['username'];
    // Furry-Hub-Avatar als Profilbild (Sitzungs-Zeilen haben user_id, Benutzer-Zeilen id)
    require_once __DIR__ . '/inc/furhub.php';
    $uid = (int)($u['user_id'] ?? (is_numeric($u['id'] ?? null) ? $u['id'] : 0));
    $img = $uid ? fh_avatar_img($uid, 'av-img', $name) : '';
    return '<span class="av ' . $cls . ($img ? ' has-img' : '') . '" style="--rc: ' . e($u['role_color']) . ';">' . ($img ?: e(mb_strtoupper(mb_substr($name, 0, 1)))) . '</span>';
}
function bytes(int $b): string {
    return $b > 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : number_format($b / 1024, 1, ',', '.') . ' KB';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>ADMIN CORE</title>
    <?php echo emoji_favicon('⚙️'); ?>
    <link rel="stylesheet" href="assets/hud.css">
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="<?php echo $mode === 'panel' ? 'panel-mode' : 'gate-mode'; ?>">

<?php if ($mode !== 'panel'): ?>
<!-- ======================= Einrichtung / Login / 2FA / Einladung ======================= -->
<?php $gate = [
    'setup'    => ['⚡', 'System // Erstinitialisierung', 'Admin anlegen', 'Lege das erste Konto an – es wird Administrator'],
    'login'    => ['🔒', 'System // Zugriff erforderlich', 'Anmelden', 'Melde dich mit deinem Benutzerkonto an'],
    'totp'     => ['🔐', 'System // Zweiter Faktor', 'Bestätigen', 'Code aus deiner Authenticator-App eingeben'],
    'register' => ['🎟️', 'System // Einladung', 'Konto erstellen', 'Du wurdest eingeladen – lege dein Konto an'],
][$mode]; ?>
<div class="wrapper">
    <div class="gate">
        <div class="gate-box">
            <div class="gate-orbit"><div class="gate-icon"><?php echo $gate[0]; ?></div></div>
            <div class="hud-tag" style="justify-content: center;"><?php echo $gate[1]; ?></div>
            <h1><?php echo $gate[2]; ?></h1>
            <p class="subtitle"><?php echo $gate[3]; ?></p>

            <?php if ($error): ?><div class="err" role="alert"><?php echo e($error); ?></div><?php endif; ?>
            <?php if ($mode === 'login' && isset($_GET['denied']) && !$error): ?><div class="info">Für diese Änderung musst du angemeldet sein.</div><?php endif; ?>
            <?php if (in_array($mode, ['login', 'totp', 'register'], true) && !$error && ($locked ?? 0)): ?><div class="err">Zu viele Fehlversuche – für <?php echo ceil($locked / 60); ?> Minuten gesperrt.</div><?php endif; ?>
            <?php if ($mode === 'setup'): ?><div class="info">Willkommen! Das Dashboard hat noch kein Konto. Wer dieses Formular als Erstes ausfüllt, wird Administrator – also am besten direkt nach dem Hochladen erledigen.</div><?php endif; ?>

            <?php if ($mode === 'totp'): ?>
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="next" value="<?php echo e($next); ?>">
                    <div class="field-wrap">
                        <label class="field" for="code">6-stelliger Code oder Wiederherstellungscode</label>
                        <input type="text" id="code" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="12" class="code-input" placeholder="123 456">
                    </div>
                    <button type="submit" name="totp_login" class="btn btn-gold" <?php echo ($locked ?? 0) ? 'disabled' : ''; ?>>Bestätigen</button>
                    <button type="submit" name="totp_cancel" class="btn" formnovalidate style="margin-top: 8px;">Abbrechen</button>
                </form>
                <p class="muted small" style="text-align: center;">Handy verloren? Nutze einen deiner Wiederherstellungscodes (Format <code>abcd-efgh</code>) oder bitte einen Administrator, die Zwei-Faktor-Anmeldung zurückzusetzen.</p>
            <?php elseif ($mode === 'register'): ?>
                <?php if (!empty($invite)): ?>
                    <div class="info">Einladung für die Rolle <b style="color: <?php echo e($invite['role_color']); ?>;"><?php echo e($invite['role_name']); ?></b><?php echo $invite['created_by'] ? ' von <b>' . e($invite['created_by']) . '</b>' : ''; ?>.<?php echo $invite['expires_at'] ? ' Gültig bis ' . e(date('d.m.Y H:i', strtotime($invite['expires_at']))) . '.' : ''; ?></div>
                    <form method="POST" autocomplete="on">
                        <input type="hidden" name="invite" value="<?php echo e($invite_code); ?>">
                        <div class="field-wrap"><label class="field" for="u">Benutzername</label><input type="text" id="u" name="username" required minlength="3" maxlength="32" autocomplete="username" autofocus value="<?php echo e($_POST['username'] ?? ''); ?>"></div>
                        <div class="field-wrap"><label class="field" for="dn">Anzeigename (optional)</label><input type="text" id="dn" name="display_name" maxlength="60" value="<?php echo e($_POST['display_name'] ?? ''); ?>"></div>
                        <div class="field-wrap">
                            <label class="field" for="p">Passwort (mind. <?php echo password_policy()['min']; ?> Zeichen)</label>
                            <input type="password" id="p" name="password" required minlength="<?php echo password_policy()['min']; ?>" autocomplete="new-password" data-meter="1">
                            <button type="button" class="eye" data-eye="p" title="Passwort anzeigen">👁</button>
                            <div class="meter"><div></div></div><div class="meter-label"></div>
                        </div>
                        <div class="field-wrap"><label class="field" for="p2">Passwort wiederholen</label><input type="password" id="p2" name="password2" required autocomplete="new-password"></div>
                        <button type="submit" name="register" class="btn btn-gold">Konto erstellen</button>
                    </form>
                <?php else: ?>
                    <form method="GET"><div class="field-wrap"><label class="field" for="inv">Einladungscode</label><input type="text" id="inv" name="invite" required maxlength="20" class="code-input" autofocus></div><button class="btn btn-gold">Prüfen</button></form>
                <?php endif; ?>
            <?php else: ?>
            <form method="POST" autocomplete="on">
                <input type="hidden" name="next" value="<?php echo e($next); ?>">
                <div class="field-wrap">
                    <label class="field" for="u">Benutzername</label>
                    <input type="text" id="u" name="username" required minlength="3" maxlength="32" autocomplete="username" autofocus value="<?php echo e($_POST['username'] ?? ''); ?>">
                </div>
                <div class="field-wrap">
                    <label class="field" for="p"><?php echo $mode === 'setup' ? 'Neues Passwort (mind. 8 Zeichen)' : 'Passwort'; ?></label>
                    <input type="password" id="p" name="password" required <?php echo $mode === 'setup' ? 'minlength="8" autocomplete="new-password" data-meter="1"' : 'autocomplete="current-password"'; ?>>
                    <button type="button" class="eye" data-eye="p" title="Passwort anzeigen">👁</button>
                    <?php if ($mode === 'setup'): ?><div class="meter"><div></div></div><div class="meter-label"></div><?php endif; ?>
                </div>
                <?php if ($mode === 'setup'): ?>
                    <div class="field-wrap">
                        <label class="field" for="p2">Passwort wiederholen</label>
                        <input type="password" id="p2" name="password2" required minlength="8" autocomplete="new-password">
                    </div>
                    <button type="submit" name="setup" class="btn btn-gold" style="margin-top: 8px;">System initialisieren</button>
                <?php else: ?>
                    <label class="check"><input type="checkbox" name="remember" value="1"> Angemeldet bleiben (30 Tage)</label>
                    <button type="submit" name="login" class="btn btn-gold" <?php echo ($locked ?? 0) ? 'disabled' : ''; ?>>Zugriff anfordern</button>
                <?php endif; ?>
            </form>
            <?php if ($mode === 'login'): ?><p class="muted small" style="text-align: center; margin-top: 14px;">Du hast einen Einladungscode? <a href="admin.php?invite=-">Konto erstellen</a></p><?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="gate-foot"><a href="index.php">&larr; Zur Startseite</a></div>
    </div>
</div>

<?php else: ?>
<!-- ======================= Admin-Zentrale ======================= -->
<div class="shell">
    <aside class="side">
        <a href="index.php" class="brand"><span class="brand-logo">◈</span><span>GHOST<b>NODE</b><small>Admin Core</small></span></a>
        <nav>
            <?php foreach ($TABS as $k => [$ic, $label]): ?>
                <a href="admin.php?tab=<?php echo $k; ?>" class="<?php echo $tab === $k ? 'active' : ''; ?><?php echo ($must_pw || $must_2fa) && $k !== 'account' ? ' locked' : ''; ?>"><span class="ni"><?php echo $ic; ?></span><?php echo e($label); ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="side-foot">
            <a href="index.php" class="side-link">← Zur Startseite</a>
            <button type="button" class="side-link" id="paletteBtn">⌘ Befehle <kbd>Strg K</kbd></button>
        </div>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <div class="hud-tag">Admin Core // <?php echo e($TABS[$tab][1]); ?></div>
                <h1><?php echo $TABS[$tab][0] . ' ' . e($TABS[$tab][1]); ?></h1>
            </div>
            <div class="me">
                <?php echo avatar($me, 'lg'); ?>
                <div><b><?php echo e(user_label($me)); ?></b><small style="color: <?php echo e($me['role_color']); ?>;"><?php echo e($me['role_name']); ?></small></div>
                <form method="POST"><input type="hidden" name="action" value="logout"><button class="btn btn-sm btn-danger" title="Abmelden">⏻</button></form>
            </div>
        </div>

        <?php site_banners(); ?>
        <?php if ($must_pw): ?><div class="flash warn">🔑 <b>Bitte ändere jetzt dein Passwort.</b> <?php echo strtotime($me['password_changed']) < time() - max(1, $pol['max_age']) * 86400 && $pol['max_age'] ? 'Es ist älter als ' . $pol['max_age'] . ' Tage.' : 'Ein Administrator hat einen Passwortwechsel angefordert.'; ?> Bis dahin sind die anderen Bereiche gesperrt.</div><?php endif; ?>
        <?php if ($must_2fa): ?><div class="flash warn">🔐 <b>Zwei-Faktor-Anmeldung ist für Administratoren Pflicht.</b> Richte sie unten unter „Zwei-Faktor“ ein – bis dahin sind die anderen Bereiche gesperrt.</div><?php endif; ?>
        <?php if (isset($_GET['welcome'])): ?>
            <div class="welcome"><b>✓ SYSTEM INITIALISIERT.</b> Dein Administrator-Konto ist eingerichtet und du bist angemeldet. Unter <a href="admin.php?tab=users">Benutzer</a> kannst du weitere Konten anlegen, unter <a href="admin.php?tab=roles">Rollen &amp; Rechte</a> festlegen, wer was darf.</div>
        <?php endif; ?>
        <?php if ($flash): ?><div class="flash ok"><?php echo e($flash); ?></div><?php endif; ?>
        <?php if ($flash_err): ?><div class="flash err"><?php echo e($flash_err); ?></div><?php endif; ?>
        <?php if ($settings['maintenance']): ?><div class="flash warn">🛠️ Wartungsmodus ist aktiv – Besucher sehen nur den Wartungshinweis. <?php if (can('system.manage')): ?><a href="admin.php?tab=system">Ausschalten</a><?php endif; ?></div><?php endif; ?>

<?php if ($tab === 'overview'): ?>
        <div class="kpis">
            <a class="kpi" href="admin.php?tab=users"><span class="k">Benutzer</span><span class="v" data-count="<?php echo $stats['users']; ?>">0</span><span class="s"><?php echo $stats['users_all'] - $stats['users']; ?> deaktiviert · <?php echo count($roles); ?> Rollen</span><i>👥</i></a>
            <a class="kpi" href="admin.php?tab=pages"><span class="k">Seiten</span><span class="v" data-count="<?php echo $stats['pages']; ?>">0</span><span class="s"><?php echo $stats['custom']; ?> eigene · <?php echo $stats['protected']; ?> geschützt</span><i>🗂️</i></a>
            <a class="kpi" href="linkcheck.php"><span class="k">Links</span><span class="v" data-count="<?php echo count($links); ?>">0</span><span class="s <?php echo $lc_bad ? 'red' : ''; ?>"><?php echo $lc['checked_at'] ? ($lc_bad ? "⚠ $lc_bad kaputt" : '✓ alle erreichbar') : 'noch nicht geprüft'; ?></span><i>🔗</i></a>
            <a class="kpi" href="calendar.php"><span class="k">Termine</span><span class="v" data-count="<?php echo $cal_events; ?>">0</span><span class="s"><?php echo $cal_subs; ?> Abos · <?php echo $upcoming ? e($upcoming[0]['title'] . ' ' . relative_days($upcoming[0]['ts'])) : 'kein Countdown'; ?></span><i>📅</i></a>
            <div class="kpi <?php echo $stats['fails24'] ? 'alert' : ''; ?>"><span class="k">Sicherheit (24 Std.)</span><span class="v" data-count="<?php echo $stats['fails24']; ?>">0</span><span class="s">Fehlversuche · <?php echo $stats['locked']; ?> IP gesperrt</span><i>🛡️</i></div>
            <a class="kpi" href="<?php echo can('sessions.manage') ? 'admin.php?tab=sessions' : '#'; ?>"><span class="k">Gerade online</span><span class="v" data-count="<?php echo $stats['online']; ?>">0</span><span class="s"><?php echo $stats['sessions']; ?> Sitzungen · 🔐 2FA <?php echo $stats['tfa']; ?>/<?php echo $stats['users']; ?></span><i>🟢</i></a>
            <div class="kpi"><span class="k">Status</span><span class="v small <?php echo $settings['maintenance'] ? 'gold' : 'green'; ?>"><?php echo $settings['maintenance'] ? 'Wartung' : 'Online'; ?></span><span class="s">NSFW für Besucher: <?php echo $settings['show_nsfw'] ? 'sichtbar' : 'versteckt'; ?><?php echo $stats['blocked'] ? ' · 🚫 ' . $stats['blocked'] . ' IP-Sperren' : ''; ?></span><i>🛰️</i></div>
        </div>

        <div class="grid2">
            <div class="panel">
                <div class="panel-title"><span>// Aktivität – 14 Tage</span><span class="legend"><i class="l1"></i>Logins <i class="l2"></i>Aktionen <i class="l3"></i>Fehlversuche</span></div>
                <?php $max = max(1, max(array_map(function ($a) { return $a['login'] + $a['other'] + $a['sec']; }, $activity))); ?>
                <svg class="chart" viewBox="0 0 560 170" role="img" aria-label="Aktivität der letzten 14 Tage">
                    <?php $i = 0; foreach ($activity as $day => $a): $x = 10 + $i * 39; $y = 140; ?>
                        <?php foreach ([['login', 'var(--accent-cyan)'], ['other', 'var(--accent-gold)'], ['sec', 'var(--accent-red)']] as [$k, $col]): $h = $a[$k] / $max * 120; if ($h <= 0) continue; $y -= $h; ?>
                            <rect x="<?php echo $x; ?>" y="<?php echo round($y, 1); ?>" width="26" height="<?php echo round($h, 1); ?>" fill="<?php echo $col; ?>" class="bar"><title><?php echo date('d.m.', strtotime($day)) . ': ' . $a[$k] . ' ' . ['login' => 'Logins', 'other' => 'Aktionen', 'sec' => 'Fehlversuche'][$k]; ?></title></rect>
                        <?php endforeach; ?>
                        <text x="<?php echo $x + 13; ?>" y="160" text-anchor="middle"><?php echo date('d.', strtotime($day)); ?></text>
                    <?php $i++; endforeach; ?>
                    <line x1="5" y1="140.5" x2="555" y2="140.5" />
                </svg>
            </div>
            <div class="panel">
                <div class="panel-title"><span>// Schnellzugriff</span></div>
                <div class="quick">
                    <?php if (can('users.manage')): ?><a href="admin.php?tab=users#new">➕<span>Benutzer anlegen</span></a><?php endif; ?>
                    <?php if (can('invites.manage')): ?><a href="admin.php?tab=invites">🎟️<span>Einladung</span></a><?php endif; ?>
                    <?php if (can('security.manage')): ?><a href="admin.php?tab=security">🔐<span>Sicherheit</span></a><?php endif; ?>
                    <?php if (can('pages.manage')): ?><a href="index.php?edit=1#new">✨<span>Neue Seite</span></a><a href="index.php?edit=1">✎<span>Index sortieren</span></a><?php endif; ?>
                    <?php if (can('linkcheck.run')): ?><a href="linkcheck.php">🩺<span>Link-Check</span></a><?php endif; ?>
                    <?php if (can('backup.download')): ?><a href="backup.php">💾<span>Backup</span></a><?php endif; ?>
                    <a href="calendar.php">📅<span>Kalender</span></a>
                    <?php if (can('budget.access')): ?><a href="budget.php">💶<span>Budget</span></a><?php endif; ?>
                    <?php if (can('settings.nsfw')): ?>
                        <form method="POST"><input type="hidden" name="action" value="toggle_nsfw"><input type="hidden" name="back" value="admin.php"><button>🔞<span>NSFW <?php echo $settings['show_nsfw'] ? 'ausblenden' : 'einblenden'; ?></span></button></form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (can('announce.manage')): ?>
        <form method="POST" class="panel announce" id="announce">
            <input type="hidden" name="tab" value="overview"><input type="hidden" name="action" value="announce_save">
            <div class="panel-title"><span>// 📢 Ankündigung für alle</span><?php if (announcement_active($ann)): ?><span class="dot-ok">sichtbar</span><?php elseif (trim($ann['text']) !== ''): ?><span class="dot-off">abgelaufen / Zielgruppe</span><?php endif; ?></div>
            <textarea name="text" rows="2" maxlength="500" placeholder="z. B. Heute ab 22 Uhr Wartung · Neue Galerie online!"><?php echo e($ann['text']); ?></textarea>
            <div class="ann-row">
                <label>Stil <select name="level"><?php foreach (['info' => 'ℹ️ Info', 'success' => '✅ Erfolg', 'warn' => '⚠️ Warnung', 'danger' => '🚨 Wichtig'] as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $ann['level'] === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></label>
                <label>Für <select name="audience"><?php foreach (['all' => 'alle', 'users' => 'nur Angemeldete', 'guests' => 'nur Besucher'] as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $ann['audience'] === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></label>
                <label>bis <input type="datetime-local" name="until" value="<?php echo $ann['until'] ? e(date('Y-m-d\TH:i', strtotime($ann['until']))) : ''; ?>"></label>
                <label class="check"><input type="checkbox" name="dismissible" value="1" <?php echo $ann['dismissible'] ? 'checked' : ''; ?>> wegklickbar</label>
                <span style="flex: 1;"></span>
                <?php if (trim($ann['text']) !== ''): ?><button type="submit" name="clear" value="1" class="btn btn-sm btn-danger" formnovalidate>Entfernen</button><?php endif; ?>
                <button class="btn btn-sm btn-gold">Veröffentlichen</button>
            </div>
        </form>
        <?php endif; ?>

        <div class="grid2">
            <div class="panel">
                <div class="panel-title"><span>// Zuletzt passiert</span><?php if (can('audit.view')): ?><a href="admin.php?tab=audit" class="btn btn-sm">Alles</a><?php endif; ?></div>
                <div class="feed-list">
                    <?php foreach ($recent as $r): ?>
                        <div class="fl lv-<?php echo e($r['level']); ?>"><span class="fl-dot"></span><div><b><?php echo e($r['action']); ?></b><?php echo $r['detail'] !== '' ? ' <span class="muted">· ' . e(mb_substr($r['detail'], 0, 70)) . '</span>' : ''; ?><small><?php echo e($r['username'] ?: 'System'); ?> · <?php echo e(ago($r['ts'])); ?></small></div></div>
                    <?php endforeach; ?>
                    <?php if (!$recent): ?><div class="muted">Noch keine Einträge.</div><?php endif; ?>
                </div>
            </div>
            <div class="panel">
                <div class="panel-title"><span>// Team</span><?php if (isset($TABS['users'])): ?><a href="admin.php?tab=users" class="btn btn-sm">Verwalten</a><?php endif; ?></div>
                <div class="team">
                    <?php foreach ($users as $u): ?>
                        <div class="tm <?php echo $u['active'] ? '' : 'off'; ?>"><?php echo avatar($u, !empty($u['online']) ? 'online' : ''); ?><div><b><?php echo e($u['display_name'] ?: $u['username']); ?></b><small style="color: <?php echo e($u['role_color']); ?>;"><?php echo e($u['role_name']); ?></small></div><span class="muted"><?php echo e(ago($u['last_login'])); ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

<?php elseif ($tab === 'users'): ?>
        <?php if (!empty($detail)): $dd = $detail; ?>
        <div class="panel udetail">
            <div class="panel-title"><span>// Benutzer-Details</span><a href="admin.php?tab=users" class="btn btn-sm">✕ Schließen</a></div>
            <div class="ud-head">
                <?php echo avatar($dd, 'xl'); ?>
                <div>
                    <b><?php echo e(user_label($dd)); ?></b>
                    <small>@<?php echo e($dd['username']); ?> · <span style="color: <?php echo e($dd['role_color']); ?>;"><?php echo e($dd['role_name']); ?></span> · <?php echo $dd['active'] ? '<span class="dot-ok">aktiv</span>' : '<span class="dot-off">deaktiviert</span>'; ?></small>
                    <small>dabei seit <?php echo e(date('d.m.Y', strtotime($dd['created_at']))); ?> · Passwort geändert <?php echo e(ago($dd['password_changed'])); ?><?php echo $dd['invited_by'] ? ' · eingeladen von ' . e($dd['invited_by']) : ''; ?></small>
                    <div class="badges">
                        <?php echo $dd['totp_secret'] !== '' ? '<span class="bdg ok">🔐 2FA aktiv</span>' : '<span class="bdg">🔓 ohne 2FA</span>'; ?>
                        <?php if ($dd['must_change_pw']): ?><span class="bdg warn">🔑 muss Passwort ändern</span><?php endif; ?>
                        <?php if ($dd['expires_at']): ?><span class="bdg <?php echo user_expired($dd) ? 'bad' : 'warn'; ?>">⌛ <?php echo user_expired($dd) ? 'abgelaufen' : 'läuft ab'; ?> <?php echo e(date('d.m.Y', strtotime($dd['expires_at']))); ?></span><?php endif; ?>
                        <?php if ($dd['note'] !== ''): ?><span class="bdg">📝 <?php echo e($dd['note']); ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="ud-actions">
                    <?php if ($can_um && ($dd['role_is_system'] ? !empty($me['role_is_system']) : true)): ?>
                        <button type="button" class="btn btn-sm btn-gold" data-user-edit="<?php echo e(json_encode(['id' => $dd['id'], 'username' => $dd['username'], 'display_name' => $dd['display_name'], 'role_id' => $dd['role_id'], 'active' => $dd['active'], 'note' => $dd['note'], 'expires_at' => $dd['expires_at'] ? substr($dd['expires_at'], 0, 10) : '', 'must_change_pw' => $dd['must_change_pw']])); ?>">✎ Bearbeiten</button>
                    <?php endif; ?>
                    <?php if (can('security.manage') && $dd['totp_secret'] !== ''): ?>
                        <form method="POST" onsubmit="return confirm('Zwei-Faktor für diesen Benutzer zurücksetzen?');"><input type="hidden" name="tab" value="users"><input type="hidden" name="back_user" value="<?php echo (int)$dd['id']; ?>"><input type="hidden" name="action" value="user_2fa_reset"><input type="hidden" name="id" value="<?php echo (int)$dd['id']; ?>"><button class="btn btn-sm">🔓 2FA zurücksetzen</button></form>
                    <?php endif; ?>
                    <?php if (can('sessions.manage') && $detail_sessions): ?>
                        <form method="POST"><input type="hidden" name="tab" value="users"><input type="hidden" name="back_user" value="<?php echo (int)$dd['id']; ?>"><input type="hidden" name="action" value="sessions_user_revoke"><input type="hidden" name="id" value="<?php echo (int)$dd['id']; ?>"><button class="btn btn-sm btn-danger">⏻ Alle Geräte abmelden</button></form>
                    <?php endif; ?>
                    <?php if (can('audit.view')): ?><a class="btn btn-sm" href="admin.php?tab=audit&amp;user=<?php echo urlencode($dd['username']); ?>">📜 Protokoll</a><?php endif; ?>
                </div>
            </div>
            <div class="grid3">
                <div>
                    <div class="sub-title">Geräte &amp; Sitzungen (<?php echo count($detail_sessions); ?>)</div>
                    <?php foreach ($detail_sessions as $se): [$dic, $dlb] = device_label($se['agent']); ?>
                        <div class="sess"><span class="sess-ic"><?php echo $dic; ?></span><div><b><?php echo e($dlb); ?></b><small><?php echo e($se['ip']); ?> · aktiv <?php echo e(ago($se['last_seen'])); ?><?php echo $se['remember'] ? ' · 30 Tage' : ''; ?></small></div>
                            <?php if (can('sessions.manage')): ?><form method="POST"><input type="hidden" name="tab" value="users"><input type="hidden" name="back_user" value="<?php echo (int)$dd['id']; ?>"><input type="hidden" name="action" value="session_revoke"><input type="hidden" name="sid" value="<?php echo e($se['id']); ?>"><button class="btn btn-sm" title="Abmelden">⏻</button></form><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$detail_sessions): ?><div class="muted small">Keine aktive Sitzung.</div><?php endif; ?>
                </div>
                <div>
                    <div class="sub-title">Login-Adressen</div>
                    <?php foreach ($detail_ips as $ipr): ?><div class="kv"><span class="mono"><?php echo e($ipr['ip']); ?></span><span class="muted small"><?php echo (int)$ipr['n']; ?>× · <?php echo e(ago($ipr['last'])); ?></span></div><?php endforeach; ?>
                    <?php if (!$detail_ips): ?><div class="muted small">Noch keine Logins protokolliert.</div><?php endif; ?>
                </div>
                <div>
                    <div class="sub-title">Verlauf</div>
                    <div class="feed-list compact">
                        <?php foreach ($detail_log as $r): ?><div class="fl lv-<?php echo e($r['level']); ?>"><span class="fl-dot"></span><div><b><?php echo e($r['action']); ?></b><?php echo $r['detail'] !== '' ? ' <span class="muted">· ' . e(mb_substr($r['detail'], 0, 60)) . '</span>' : ''; ?><small><?php echo e(ago($r['ts'])); ?> · <?php echo e($r['ip']); ?></small></div></div><?php endforeach; ?>
                        <?php if (!$detail_log): ?><div class="muted small">Keine Einträge.</div><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="panel">
            <div class="panel-title"><span>// <?php echo count($users); ?> Benutzer</span><?php if ($can_um): ?><button type="button" class="btn btn-sm btn-gold" data-user-edit="new" id="newUserBtn">＋ Benutzer anlegen</button><?php endif; ?></div>
            <div class="u-toolbar">
                <input type="search" id="uSearch" placeholder="Suchen: Name, Notiz, IP …">
                <select id="uRole"><option value="">Alle Rollen</option><?php foreach ($roles as $r): ?><option value="<?php echo (int)$r['id']; ?>"><?php echo e($r['name']); ?></option><?php endforeach; ?></select>
                <select id="uState"><option value="">Alle</option><option value="active">aktiv</option><option value="off">deaktiviert</option><option value="online">gerade online</option><option value="no2fa">ohne 2FA</option><option value="expiring">mit Ablaufdatum</option><option value="must">Passwortwechsel offen</option></select>
                <span class="muted small" id="uCount"></span>
            </div>
            <?php if ($can_um): ?>
            <form method="POST" class="bulk" id="bulkForm" hidden onsubmit="return this.op.value !== 'delete' || confirm('Ausgewählte Benutzer wirklich löschen?');">
                <input type="hidden" name="tab" value="users"><input type="hidden" name="action" value="user_bulk">
                <b id="bulkN">0</b> ausgewählt:
                <select name="op" id="bulkOp">
                    <option value="activate">✓ aktivieren</option><option value="deactivate">⦸ deaktivieren</option><option value="logout">⏻ abmelden</option>
                    <option value="must_change">🔑 Passwortwechsel erzwingen</option><option value="role">🛡️ Rolle ändern …</option><option value="expire">⌛ Ablaufdatum setzen …</option><option value="delete">✕ löschen</option>
                </select>
                <select name="role_id" id="bulkRole" hidden><?php foreach ($roles as $r): if ($r['is_system'] && empty($me['role_is_system'])) continue; ?><option value="<?php echo (int)$r['id']; ?>"><?php echo e($r['name']); ?></option><?php endforeach; ?></select>
                <input type="date" name="date" id="bulkDate" hidden title="leer = nie">
                <button class="btn btn-sm btn-gold">Ausführen</button>
                <button type="button" class="btn btn-sm" id="bulkClear">Auswahl aufheben</button>
            </form>
            <?php endif; ?>
            <div class="table-wrap">
                <table class="tbl" id="userTable">
                    <thead><tr><?php if ($can_um): ?><th class="cb"><input type="checkbox" id="uAll" title="Alle sichtbaren auswählen"></th><?php endif; ?><th>Benutzer</th><th>Rolle</th><th>Status</th><th>Letzter Login</th><th>Geräte</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $u):
                        $online = !empty($u['online']);
                        $flags = trim(($u['active'] ? 'active' : 'off') . ($online ? ' online' : '') . ($u['totp_secret'] === '' ? ' no2fa' : '') . ($u['expires_at'] ? ' expiring' : '') . ($u['must_change_pw'] ? ' must' : ''));
                        $touch = !$u['is_system'] || !empty($me['role_is_system']); ?>
                        <tr class="<?php echo $u['active'] ? '' : 'off'; ?>" data-role="<?php echo (int)$u['role_id']; ?>" data-flags="<?php echo $flags; ?>" data-search="<?php echo e(mb_strtolower($u['username'] . ' ' . $u['display_name'] . ' ' . $u['note'] . ' ' . $u['last_ip'])); ?>">
                            <?php if ($can_um): ?><td class="cb"><?php if ($touch): ?><input type="checkbox" name="ids[]" value="<?php echo (int)$u['id']; ?>" form="bulkForm" class="u-cb"><?php endif; ?></td><?php endif; ?>
                            <td><a class="ucell" href="admin.php?tab=users&amp;user=<?php echo (int)$u['id']; ?>"><?php echo avatar($u, $online ? 'online' : ''); ?><div><b><?php echo e($u['display_name'] ?: $u['username']); ?></b><small>@<?php echo e($u['username']); ?><?php echo $u['id'] == $me['id'] ? ' · du' : ''; ?><?php echo $u['note'] !== '' ? ' · ' . e($u['note']) : ''; ?></small></div></a></td>
                            <td><span class="role-pill" style="--rc: <?php echo e($u['role_color']); ?>;"><?php echo e($u['role_name']); ?></span></td>
                            <td>
                                <?php echo $u['active'] ? (user_expired($u) ? '<span class="dot-off">abgelaufen</span>' : '<span class="dot-ok">aktiv</span>') : '<span class="dot-off">deaktiviert</span>'; ?>
                                <span class="mini-badges"><?php echo $u['totp_secret'] !== '' ? '<i title="Zwei-Faktor aktiv">🔐</i>' : ''; ?><?php echo $u['must_change_pw'] ? '<i title="Muss Passwort ändern">🔑</i>' : ''; ?><?php echo $u['expires_at'] && !user_expired($u) ? '<i title="Läuft ab am ' . e(date('d.m.Y', strtotime($u['expires_at']))) . '">⌛</i>' : ''; ?></span>
                            </td>
                            <td><?php echo e(ago($u['last_login'])); ?><?php echo $u['last_ip'] ? '<small class="muted"> · ' . e($u['last_ip']) . '</small>' : ''; ?></td>
                            <td><?php echo (int)$u['session_count']; ?><?php echo $online ? ' <span class="dot-ok">online</span>' : ''; ?></td>
                            <td class="actions">
                                <a class="btn btn-sm" href="admin.php?tab=users&amp;user=<?php echo (int)$u['id']; ?>" title="Details">ⓘ</a>
                                <?php if ($can_um && $touch): ?>
                                <button type="button" class="btn btn-sm btn-gold" data-user-edit="<?php echo e(json_encode(['id' => $u['id'], 'username' => $u['username'], 'display_name' => $u['display_name'], 'role_id' => $u['role_id'], 'active' => $u['active'], 'note' => $u['note'], 'expires_at' => $u['expires_at'] ? substr($u['expires_at'], 0, 10) : '', 'must_change_pw' => $u['must_change_pw']])); ?>">✎</button>
                                <form method="POST"><input type="hidden" name="tab" value="users"><input type="hidden" name="action" value="user_logout"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><button class="btn btn-sm" title="Alle Sitzungen beenden">⏻</button></form>
                                <?php if ($u['id'] != $me['id']): ?>
                                    <form method="POST" onsubmit="return confirm('Benutzer „<?php echo e(addslashes($u['username'])); ?>“ löschen?');"><input type="hidden" name="tab" value="users"><input type="hidden" name="action" value="user_delete"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><button class="btn btn-sm btn-danger" title="Löschen">✕</button></form>
                                <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="modal-overlay" id="userModal" role="dialog" aria-modal="true">
            <form method="POST" class="modal-box" id="userForm" autocomplete="off">
                <button type="button" class="modal-close" data-close aria-label="Schließen">&times;</button>
                <div class="panel-title" id="userFormTitle">// Benutzer anlegen</div>
                <input type="hidden" name="tab" value="users"><input type="hidden" name="action" value="user_save"><input type="hidden" name="id" value="">
                <div class="f2">
                    <div><label class="field">Benutzername *</label><input type="text" name="username" required minlength="3" maxlength="32" autocomplete="off"></div>
                    <div><label class="field">Anzeigename</label><input type="text" name="display_name" maxlength="60"></div>
                </div>
                <label class="field">Rolle *</label>
                <div class="role-pick">
                    <?php foreach ($roles as $r): if ($r['is_system'] && empty($me['role_is_system'])) continue; ?>
                        <label class="rp" style="--rc: <?php echo e($r['color']); ?>;"><input type="radio" name="role_id" value="<?php echo (int)$r['id']; ?>" <?php echo $r['name'] === 'Mitglied' ? 'checked' : ''; ?>><span><b><?php echo e($r['name']); ?></b><small><?php echo e($r['description']); ?></small></span></label>
                    <?php endforeach; ?>
                </div>
                <label class="field" id="pwLabel">Passwort *</label>
                <div class="pw-row"><input type="text" name="password" id="userPw" minlength="8" autocomplete="new-password" spellcheck="false"><button type="button" class="btn btn-sm" id="genPw" title="Sicheres Passwort erzeugen">🎲 Erzeugen</button><button type="button" class="btn btn-sm" id="copyPw" title="Kopieren">⧉</button></div>
                <p class="muted small" id="pwHint">Mindestens <?php echo $pol['min']; ?> Zeichen<?php echo $pol['classes'] ? ', ' . $pol['classes'] . ' Zeichenarten' : ''; ?>. Gib es dem Benutzer sicher weiter – er kann es danach unter „Mein Konto“ ändern.</p>
                <label class="field">Notiz (nur für Admins)</label>
                <input type="text" name="note" maxlength="200" placeholder="z. B. Discord-Name, Zweck des Kontos">
                <div class="f2">
                    <div><label class="field">Konto läuft ab am (optional)</label><input type="date" name="expires_at"></div>
                    <div><label class="field">&nbsp;</label><label class="check"><input type="checkbox" name="must_change_pw" value="1" checked> Muss Passwort beim nächsten Login ändern</label></div>
                </div>
                <label class="check"><input type="checkbox" name="active" value="1" checked> Konto aktiv</label>
                <button type="submit" class="btn btn-gold" style="width: 100%;">Speichern</button>
            </form>
        </div>

<?php elseif ($tab === 'roles'): ?>
        <p class="muted" style="margin-top: 0;">Jeder Benutzer hat genau eine Rolle. Die Rolle <b>Administrator</b> hat immer alle Rechte. Seiten können zusätzlich auf bestimmte Rollen beschränkt werden (Index → Bearbeiten).<?php if (empty($me['role_is_system'])): ?> <span class="gold">Du kannst nur Rechte vergeben, die du selbst hast.</span><?php endif; ?></p>
        <?php $nonsys = array_values(array_filter($roles, function ($r) { return !$r['is_system']; })); ?>
        <details class="panel matrix-wrap" <?php echo isset($_GET['matrix']) ? 'open' : ''; ?>>
            <summary class="panel-title"><span>// ▦ Rechte-Matrix – alle Rollen auf einen Blick</span><span class="muted small">aufklappen</span></summary>
            <form method="POST">
                <input type="hidden" name="tab" value="roles"><input type="hidden" name="action" value="matrix_save">
                <div class="table-wrap">
                    <table class="tbl matrix">
                        <thead><tr><th>Recht</th><?php foreach ($roles as $r): ?><th style="--rc: <?php echo e($r['color']); ?>;"><span class="mx-role"><?php echo e($r['name']); ?></span><small><?php echo (int)$r['user_count']; ?> 👤</small><?php if (!$r['is_system']): ?><input type="hidden" name="present[<?php echo (int)$r['id']; ?>]" value="1"><?php endif; ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                        <?php foreach ($perm_groups as $group => $perms): ?>
                            <tr class="mx-group"><td colspan="<?php echo count($roles) + 1; ?>"><?php echo e($group); ?></td></tr>
                            <?php foreach ($perms as $key => $label): $mine = can($key) || !empty($me['role_is_system']); ?>
                                <tr><td><?php echo e($label); ?><code><?php echo e($key); ?></code></td>
                                <?php foreach ($roles as $r): $has = $r['is_system'] || in_array($key, $role_perms[(int)$r['id']] ?? [], true); ?>
                                    <td class="mx-c"><input type="checkbox" name="m[<?php echo (int)$r['id']; ?>][<?php echo e($key); ?>]" value="1" <?php echo $has ? 'checked' : ''; ?> <?php echo $r['is_system'] || !$mine ? 'disabled' : ''; ?> title="<?php echo e($r['name'] . ': ' . $label); ?>"></td>
                                <?php endforeach; ?></tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="rc-actions"><span class="muted small">Tipp: Spaltenkopf anklicken = ganze Rolle an/aus.</span><span style="flex: 1;"></span><button class="btn btn-sm btn-gold">Matrix speichern</button></div>
            </form>
        </details>
        <div class="roles">
            <?php foreach ($roles as $r): $rp = $r['is_system'] ? array_keys(PERMISSIONS) : ($role_perms[(int)$r['id']] ?? []); ?>
                <form method="POST" class="panel role-card" style="--rc: <?php echo e($r['color']); ?>;">
                    <input type="hidden" name="tab" value="roles"><input type="hidden" name="action" value="role_save"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <div class="rc-head">
                        <input type="color" name="color" value="<?php echo e($r['color']); ?>" title="Farbe">
                        <input type="text" name="name" value="<?php echo e($r['name']); ?>" maxlength="40" class="rc-name" <?php echo $r['is_system'] ? 'readonly' : ''; ?>>
                        <span class="muted small"><?php echo (int)$r['user_count']; ?> Benutzer · <?php echo count($rp); ?>/<?php echo count(PERMISSIONS); ?> Rechte</span>
                    </div>
                    <input type="text" name="description" value="<?php echo e($r['description']); ?>" maxlength="200" placeholder="Beschreibung" class="rc-desc">
                    <div class="perm-grid">
                        <?php foreach ($perm_groups as $group => $perms): ?>
                            <div class="pg"><div class="pg-title"><?php echo e($group); ?></div>
                                <?php foreach ($perms as $key => $label): ?>
                                    <label class="perm"><input type="checkbox" name="perms[]" value="<?php echo e($key); ?>" <?php echo in_array($key, $rp, true) ? 'checked' : ''; ?> <?php echo $r['is_system'] || (!can($key) && empty($me['role_is_system'])) ? 'disabled' : ''; ?>><span><?php echo e($label); ?><code><?php echo e($key); ?></code></span></label>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="rc-actions">
                        <?php if (!$r['is_system']): ?>
                            <button type="button" class="btn btn-sm" data-perm-all>Alle</button><button type="button" class="btn btn-sm" data-perm-none>Keine</button>
                        <?php else: ?><span class="muted small">🔒 Systemrolle – hat immer alle Rechte</span><?php endif; ?>
                        <span style="flex: 1;"></span>
                        <button type="submit" name="action" value="role_copy" class="btn btn-sm" formnovalidate title="Rolle mit allen Rechten kopieren">⧉ Kopieren</button>
                        <button type="submit" name="action" value="preview_start" class="btn btn-sm" formnovalidate title="Die Webseite so sehen wie diese Rolle">👁 Ansicht</button>
                        <?php if (!$r['is_system']): ?><button type="submit" name="action" value="role_delete" class="btn btn-sm btn-danger" formnovalidate onclick="return confirm('Rolle löschen?');">Löschen</button><?php endif; ?>
                        <button type="submit" class="btn btn-sm btn-gold">Speichern</button>
                    </div>
                </form>
            <?php endforeach; ?>
            <form method="POST" class="panel role-card new">
                <input type="hidden" name="tab" value="roles"><input type="hidden" name="action" value="role_save"><input type="hidden" name="id" value="0">
                <div class="panel-title"><span>// Neue Rolle</span></div>
                <div class="rc-head"><input type="color" name="color" value="#bd93f9"><input type="text" name="name" maxlength="40" placeholder="z. B. Moderator" class="rc-name" required></div>
                <input type="text" name="description" maxlength="200" placeholder="Beschreibung" class="rc-desc">
                <p class="muted small">Rechte kannst du nach dem Anlegen festlegen.</p>
                <button type="submit" class="btn btn-sm btn-gold">＋ Rolle anlegen</button>
            </form>
        </div>

<?php elseif ($tab === 'invites'): ?>
        <?php if (!empty($_GET['new'])): $nc = preg_replace('/[^A-Z0-9]/', '', $_GET['new']); ?>
            <div class="flash ok invite-new">🎟️ Einladung erstellt: <code class="big-code"><?php echo e($nc); ?></code>
                <span class="inv-link"><input type="text" readonly value="<?php echo e($base_url . $nc); ?>" id="invLink"><button type="button" class="btn btn-sm" data-copy="#invLink">⧉ Link kopieren</button></span></div>
        <?php endif; ?>
        <div class="grid2">
            <form method="POST" class="panel">
                <input type="hidden" name="tab" value="invites"><input type="hidden" name="action" value="invite_create">
                <div class="panel-title"><span>// Neue Einladung</span></div>
                <p class="muted small" style="margin-top: 0;">Wer den Link öffnet, kann sich selbst ein Konto mit der gewählten Rolle anlegen – ohne dass du ein Passwort weitergeben musst.</p>
                <label class="field">Rolle</label>
                <select name="role_id"><?php foreach ($roles as $r): if ($r['is_system'] && empty($me['role_is_system'])) continue; ?><option value="<?php echo (int)$r['id']; ?>" <?php echo $r['name'] === 'Mitglied' ? 'selected' : ''; ?>><?php echo e($r['name']); ?></option><?php endforeach; ?></select>
                <div class="f2">
                    <div><label class="field">Wie oft nutzbar</label><select name="max_uses"><?php foreach ([1, 3, 5, 10, 25, 100] as $n): ?><option value="<?php echo $n; ?>"><?php echo $n === 1 ? 'einmal' : $n . '×'; ?></option><?php endforeach; ?></select></div>
                    <div><label class="field">Gültig</label><select name="days"><option value="1">1 Tag</option><option value="7" selected>7 Tage</option><option value="30">30 Tage</option><option value="0">unbegrenzt</option></select></div>
                </div>
                <label class="field">Notiz</label><input type="text" name="note" maxlength="120" placeholder="z. B. für den Discord-Server">
                <button class="btn btn-gold" style="margin-top: 12px; width: 100%;">🎟️ Einladung erstellen</button>
            </form>
            <div class="panel">
                <div class="panel-title"><span>// Zuletzt per Einladung registriert</span></div>
                <div class="feed-list">
                    <?php foreach ($invited as $iu): ?><div class="fl lv-info"><span class="fl-dot"></span><div><b><?php echo e($iu['display_name'] ?: $iu['username']); ?></b> <span class="muted">· <?php echo e($iu['note']); ?></span><small><?php echo e(ago($iu['created_at'])); ?></small></div></div><?php endforeach; ?>
                    <?php if (!$invited): ?><div class="muted">Noch niemand.</div><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="panel">
            <div class="panel-title"><span>// <?php echo count($invites); ?> Einladungen</span></div>
            <div class="table-wrap">
                <table class="tbl">
                    <thead><tr><th>Code</th><th>Rolle</th><th>Genutzt</th><th>Gültig bis</th><th>Von</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($invites as $i => $iv): ?>
                        <tr class="<?php echo $iv['state'] === 'ok' ? '' : 'off'; ?>">
                            <td><code class="mono"><?php echo e($iv['code']); ?></code><?php echo $iv['note'] !== '' ? '<br><small class="muted">' . e($iv['note']) . '</small>' : ''; ?></td>
                            <td><span class="role-pill" style="--rc: <?php echo e($iv['role_color']); ?>;"><?php echo e($iv['role_name']); ?></span></td>
                            <td><?php echo (int)$iv['uses']; ?> / <?php echo (int)$iv['max_uses']; ?> <?php echo ['ok' => '<span class="dot-ok">offen</span>', 'used' => '<span class="dot-off">aufgebraucht</span>', 'expired' => '<span class="dot-off">abgelaufen</span>'][$iv['state']]; ?></td>
                            <td><?php echo $iv['expires_at'] ? e(date('d.m.Y H:i', strtotime($iv['expires_at']))) : 'unbegrenzt'; ?></td>
                            <td><?php echo e($iv['created_by']); ?><small class="muted"> · <?php echo e(ago($iv['created_at'])); ?></small></td>
                            <td class="actions">
                                <?php if ($iv['state'] === 'ok'): ?><input type="hidden" id="inv<?php echo $i; ?>" value="<?php echo e($base_url . $iv['code']); ?>"><button type="button" class="btn btn-sm" data-copy="#inv<?php echo $i; ?>" title="Link kopieren">⧉</button><?php endif; ?>
                                <form method="POST"><input type="hidden" name="tab" value="invites"><input type="hidden" name="action" value="invite_delete"><input type="hidden" name="code" value="<?php echo e($iv['code']); ?>"><button class="btn btn-sm btn-danger" title="Löschen">✕</button></form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$invites): ?><tr><td colspan="6" class="muted">Noch keine Einladungen.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

<?php elseif ($tab === 'sessions'): ?>
        <div class="panel">
            <div class="panel-title"><span>// <?php echo count($all_sessions); ?> Sitzungen</span>
                <form method="POST" class="inline"><input type="hidden" name="tab" value="sessions"><input type="hidden" name="action" value="sessions_prune"><select name="days"><option value="7">inaktiv &gt; 7 Tage</option><option value="14" selected>inaktiv &gt; 14 Tage</option><option value="30">inaktiv &gt; 30 Tage</option></select><button class="btn btn-sm">🧹 Aufräumen</button></form>
            </div>
            <div class="table-wrap">
                <table class="tbl">
                    <thead><tr><th>Benutzer</th><th>Gerät</th><th>IP</th><th>Angemeldet</th><th>Zuletzt aktiv</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($all_sessions as $se): [$dic, $dlb] = device_label($se['agent']); $on = !empty($se['online']); ?>
                        <tr>
                            <td><a href="admin.php?tab=users&amp;user=<?php echo (int)$se['user_id']; ?>" class="ucell"><?php echo avatar($se, $on ? 'online' : ''); ?><div><b><?php echo e($se['display_name'] ?: $se['username']); ?></b><small>@<?php echo e($se['username']); ?></small></div></a></td>
                            <td title="<?php echo e($se['agent']); ?>"><?php echo $dic . ' ' . e($dlb); ?><?php echo $se['id'] === current_session_id() ? ' <span class="bdg ok">diese</span>' : ''; ?></td>
                            <td class="mono"><?php echo e($se['ip']); ?></td>
                            <td><?php echo e(ago($se['created'])); ?><?php echo $se['remember'] ? '<small class="muted"> · 30 Tage</small>' : ''; ?></td>
                            <td><?php echo $on ? '<span class="dot-ok">online</span>' : e(ago($se['last_seen'])); ?></td>
                            <td class="actions"><form method="POST"><input type="hidden" name="tab" value="sessions"><input type="hidden" name="action" value="session_revoke"><input type="hidden" name="sid" value="<?php echo e($se['id']); ?>"><button class="btn btn-sm btn-danger" title="Abmelden">⏻</button></form></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$all_sessions): ?><tr><td colspan="6" class="muted">Keine Sitzungen.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

<?php elseif ($tab === 'security'): ?>
        <div class="grid2">
            <form method="POST" class="panel">
                <input type="hidden" name="tab" value="security"><input type="hidden" name="action" value="security_save">
                <div class="panel-title"><span>// 🔑 Passwort-Richtlinie</span></div>
                <div class="f2">
                    <div><label class="field">Mindestlänge</label><input type="number" name="pw_min" min="8" max="64" value="<?php echo $pol['min']; ?>"></div>
                    <div><label class="field">Zeichenarten (0–4)</label><select name="pw_classes"><?php foreach ([0 => 'egal', 2 => '2 von 4', 3 => '3 von 4', 4 => 'alle 4'] as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $pol['classes'] === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
                </div>
                <label class="field">Höchstalter (Tage, 0 = nie wechseln müssen)</label><input type="number" name="pw_max_age" min="0" max="3650" value="<?php echo $pol['max_age']; ?>">
                <label class="check"><input type="checkbox" name="pw_common" value="1" <?php echo $pol['common'] ? 'checked' : ''; ?>> Verbreitete Passwörter ablehnen (password, 12345678, qwertz …)</label>
                <label class="check"><input type="checkbox" name="pw_admin_2fa" value="1" <?php echo $pol['admin_2fa'] ? 'checked' : ''; ?>> Zwei-Faktor-Anmeldung für Administratoren Pflicht</label>
                <p class="muted small">Zeichenarten: kleine Buchstaben, GROSSE Buchstaben, Ziffern, Sonderzeichen. Bestehende Passwörter bleiben gültig – die Regeln gelten beim nächsten Wechsel.</p>
                <button class="btn btn-gold" style="width: 100%;">Speichern</button>
            </form>
            <div class="panel" id="ipblock">
                <div class="panel-title"><span>// 🚫 IP-Sperrliste</span><span class="muted small">deine Adresse: <code><?php echo e(client_ip()); ?></code></span></div>
                <form method="POST" class="ip-add">
                    <input type="hidden" name="tab" value="security"><input type="hidden" name="action" value="ip_add">
                    <input type="text" name="ip" required placeholder="203.0.113.7 oder 203.0.113.0/24" maxlength="64" value="<?php echo e($_GET['ip'] ?? ''); ?>">
                    <select name="hours"><option value="1">1 Std.</option><option value="24">24 Std.</option><option value="168">7 Tage</option><option value="720">30 Tage</option><option value="0" selected>dauerhaft</option></select>
                    <input type="text" name="note" placeholder="Grund (optional)" maxlength="120">
                    <button class="btn btn-sm btn-danger">Sperren</button>
                </form>
                <?php foreach ($ip_rules as $r): ?>
                    <div class="kv"><span><code class="mono"><?php echo e($r['ip']); ?></code><small class="muted"> · <?php echo e($r['note'] ?: 'ohne Grund'); ?> · <?php echo e($r['by'] ?? ''); ?> <?php echo e(ago($r['added'])); ?> · <?php echo $r['until'] ? 'bis ' . e(date('d.m. H:i', strtotime($r['until']))) : 'dauerhaft'; ?></small></span>
                        <form method="POST"><input type="hidden" name="tab" value="security"><input type="hidden" name="action" value="ip_remove"><input type="hidden" name="ip" value="<?php echo e($r['ip']); ?>"><button class="btn btn-sm" title="Freigeben">✓ freigeben</button></form></div>
                <?php endforeach; ?>
                <?php if (!$ip_rules): ?><div class="muted small">Keine Sperren. Gesperrte Adressen sehen auf allen Seiten nur „Zugriff gesperrt“.</div><?php endif; ?>
            </div>
        </div>
        <div class="grid2">
            <div class="panel">
                <div class="panel-title"><span>// ⚠️ Fehlversuche (7 Tage)</span></div>
                <?php foreach ($fail_ips as $f): $isb = ip_blocked($f['ip']); ?>
                    <div class="kv"><span><code class="mono"><?php echo e($f['ip']); ?></code> <b class="red"><?php echo (int)$f['n']; ?>×</b><small class="muted"> · <?php echo e(mb_substr((string)$f['names'], 0, 60)); ?> · <?php echo e(ago($f['last'])); ?></small></span>
                        <?php if ($isb): ?><span class="bdg bad">gesperrt</span><?php else: ?><a class="btn btn-sm btn-danger" href="admin.php?tab=security&amp;ip=<?php echo urlencode($f['ip']); ?>#ipblock">🚫 sperren</a><?php endif; ?></div>
                <?php endforeach; ?>
                <?php if (!$fail_ips): ?><div class="muted small">Keine fehlgeschlagenen Logins – sehr gut. ✓</div><?php endif; ?>
                <div class="sub-title" style="margin-top: 16px;">Login-Sperren (<?php echo AUTH_MAX_FAILS; ?> Fehler → <?php echo AUTH_LOCK_SECONDS / 60; ?> Min.)</div>
                <?php foreach ($locks as $l): $act_lock = $l['count'] >= AUTH_MAX_FAILS && $l['last'] > time() - AUTH_LOCK_SECONDS; ?>
                    <div class="kv"><span><code class="mono"><?php echo e($l['ip']); ?></code> · <?php echo (int)$l['count']; ?> Fehler<small class="muted"> · <?php echo e(date('d.m. H:i', (int)$l['last'])); ?></small> <?php echo $act_lock ? '<span class="bdg bad">gesperrt</span>' : ''; ?></span>
                        <form method="POST"><input type="hidden" name="tab" value="security"><input type="hidden" name="action" value="ip_unlock"><input type="hidden" name="ip" value="<?php echo e($l['ip']); ?>"><button class="btn btn-sm">🔓</button></form></div>
                <?php endforeach; ?>
                <?php if (!$locks): ?><div class="muted small">Keine.</div><?php endif; ?>
            </div>
            <div class="panel">
                <div class="panel-title"><span>// 🔐 Zwei-Faktor-Status</span><span class="muted small"><?php echo count(array_filter($users, function ($u) { return $u['totp_secret'] !== ''; })); ?>/<?php echo count($users); ?> geschützt</span></div>
                <?php foreach ($users as $u): ?>
                    <div class="kv"><span class="ucell"><?php echo avatar($u); ?><span><b><?php echo e($u['display_name'] ?: $u['username']); ?></b> <small class="muted"><?php echo e($u['role_name']); ?> · Passwort <?php echo e(ago($u['password_changed'])); ?></small></span></span>
                        <?php if ($u['totp_secret'] !== ''): ?>
                            <form method="POST" onsubmit="return confirm('Zwei-Faktor zurücksetzen?');"><input type="hidden" name="tab" value="security"><input type="hidden" name="action" value="user_2fa_reset"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><span class="bdg ok">🔐 aktiv</span> <button class="btn btn-sm" title="Zurücksetzen">↺</button></form>
                        <?php else: ?><span class="bdg <?php echo $u['is_system'] ? 'warn' : ''; ?>">🔓 aus</span><?php endif; ?></div>
                <?php endforeach; ?>
            </div>
        </div>

<?php elseif ($tab === 'pages'): ?>
        <div class="panel">
            <div class="panel-title"><span>// <?php echo count($pages); ?> Seiten</span><span><a href="index.php?edit=1#new" class="btn btn-sm btn-gold">＋ Neue Seite</a> <a href="index.php?edit=1" class="btn btn-sm">✎ Index sortieren</a></span></div>
            <div class="table-wrap">
                <table class="tbl">
                    <thead><tr><th>Seite</th><th>Kategorie</th><th>Art</th><th>Zugriff</th><th>Geändert</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($pages as $p): ?>
                        <tr class="<?php echo $p['hidden'] ? 'off' : ''; ?>">
                            <td><div class="ucell"><span class="pg-ic"><?php echo e($p['icon'] ?: '📄'); ?></span><div><b><?php echo e($p['title']); ?></b><small><?php echo e($p['description'] ?: '–'); ?></small></div></div></td>
                            <td><?php echo e(($p['cat_icon'] ? $p['cat_icon'] . ' ' : '') . ($p['cat_name'] ?? 'ohne')); ?></td>
                            <td><?php echo $p['kind'] === 'file' ? '<span class="muted">Datei</span>' : e(PAGE_TEMPLATES[$p['template']][0] . ' ' . PAGE_TEMPLATES[$p['template']][1]); ?><?php echo $p['hidden'] ? ' <span class="muted small">· versteckt</span>' : ''; ?></td>
                            <td><?php echo e(PAGE_ACCESS[$p['access']]); ?><?php if ($p['access'] === 'roles'): ?><small class="muted"> · <?php echo e(implode(', ', array_map(function ($id) use ($role_names) { return $role_names[$id] ?? '?'; }, array_filter(explode(',', $p['allowed_roles']))))); ?></small><?php endif; ?></td>
                            <td><?php echo e(ago($p['updated_at'])); ?><?php echo $p['creator'] ? '<small class="muted"> · ' . e($p['creator']) . '</small>' : ''; ?></td>
                            <td class="actions"><a href="<?php echo e(page_href($p)); ?>" class="btn btn-sm" <?php echo $p['template'] === 'external' ? 'target="_blank" rel="noopener"' : ''; ?>>Öffnen</a> <a href="index.php?edit=1#page-<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-gold">✎</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="tpl-info">
            <?php foreach (PAGE_TEMPLATES as [$ic, $name, $desc]): ?><div class="panel"><b><?php echo $ic . ' ' . e($name); ?></b><p class="muted small"><?php echo e($desc); ?></p></div><?php endforeach; ?>
        </div>

<?php elseif ($tab === 'audit'): ?>
        <form method="GET" class="audit-filter">
            <input type="hidden" name="tab" value="audit">
            <input type="search" name="q" value="<?php echo e($_GET['q'] ?? ''); ?>" placeholder="Suchen: Aktion, Benutzer, IP …">
            <select name="level"><option value="">Alle Stufen</option><?php foreach (['info' => 'Info', 'warn' => 'Warnung', 'security' => 'Sicherheit'] as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo ($_GET['level'] ?? '') === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select>
            <select name="user"><option value="">Alle Benutzer</option><?php foreach ($audit_users as $au): ?><option value="<?php echo e($au); ?>" <?php echo ($_GET['user'] ?? '') === $au ? 'selected' : ''; ?>><?php echo e($au); ?></option><?php endforeach; ?></select>
            <input type="date" name="from" value="<?php echo e($_GET['from'] ?? ''); ?>" title="von">
            <input type="date" name="to" value="<?php echo e($_GET['to'] ?? ''); ?>" title="bis">
            <button class="btn btn-sm">Filtern</button>
            <?php if (array_filter([$_GET['q'] ?? '', $_GET['level'] ?? '', $_GET['user'] ?? '', $_GET['from'] ?? '', $_GET['to'] ?? ''])): ?><a href="admin.php?tab=audit" class="btn btn-sm">✕</a><?php endif; ?>
            <?php if (can('audit.export')): ?><a href="admin.php?<?php echo e(http_build_query(['tab' => 'audit', 'export' => 1] + array_intersect_key($_GET, array_flip(['q', 'level', 'user', 'from', 'to'])))); ?>" class="btn btn-sm">⬇ CSV</a><?php endif; ?>
        </form>
        <div class="panel">
            <div class="panel-title"><span>// <?php echo $audit_total; ?> Einträge</span><span class="muted small">Seite <?php echo $page_no; ?> von <?php echo max(1, ceil($audit_total / 50)); ?></span></div>
            <div class="table-wrap">
                <table class="tbl audit">
                    <thead><tr><th>Zeit</th><th>Benutzer</th><th>Aktion</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($audit as $a): ?>
                        <tr class="lvl-<?php echo e($a['level']); ?>" title="<?php echo e($a['agent']); ?>">
                            <td class="nowrap"><?php echo e(date('d.m.y H:i:s', strtotime($a['ts']))); ?></td>
                            <td><?php echo e($a['username'] ?: '–'); ?></td>
                            <td><span class="lvl"><?php echo ['info' => '●', 'warn' => '▲', 'security' => '◆'][$a['level']] ?? '●'; ?></span> <b><?php echo e($a['action']); ?></b><?php echo $a['detail'] !== '' ? '<br><small class="muted">' . e($a['detail']) . '</small>' : ''; ?></td>
                            <td class="mono"><?php echo e($a['ip']); ?><?php if ($a['level'] === 'security' && $a['ip'] && can('security.manage')): ?> <a href="admin.php?tab=security&amp;ip=<?php echo urlencode($a['ip']); ?>#ipblock" class="ip-act" title="IP sperren">🚫</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$audit): ?><tr><td colspan="4" class="muted">Keine Einträge.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="pager">
                <?php $qs = function ($p) { return 'admin.php?' . http_build_query(['tab' => 'audit', 'q' => $_GET['q'] ?? '', 'level' => $_GET['level'] ?? '', 'user' => $_GET['user'] ?? '', 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '', 'page' => $p]); }; ?>
                <?php if ($page_no > 1): ?><a class="btn btn-sm" href="<?php echo e($qs($page_no - 1)); ?>">‹ Neuer</a><?php endif; ?>
                <?php if ($page_no * 50 < $audit_total): ?><a class="btn btn-sm" href="<?php echo e($qs($page_no + 1)); ?>">Älter ›</a><?php endif; ?>
            </div>
        </div>

<?php elseif ($tab === 'system'): ?>
        <div class="grid2">
            <form method="POST" class="panel">
                <input type="hidden" name="tab" value="system"><input type="hidden" name="action" value="settings_save">
                <div class="panel-title"><span>// Webseite</span></div>
                <label class="field">Titel der Startseite</label><input type="text" name="site_title" value="<?php echo e($settings['site_title']); ?>" maxlength="60">
                <label class="field">Untertitel</label><input type="text" name="site_tagline" value="<?php echo e($settings['site_tagline']); ?>" maxlength="120">
                <div class="maint <?php echo $settings['maintenance'] ? 'on' : ''; ?>">
                    <label class="check big"><input type="checkbox" name="maintenance" value="1" <?php echo $settings['maintenance_flag'] ? 'checked' : ''; ?>> 🛠️ Wartungsmodus</label>
                    <p class="muted small">Alle ohne Admin-Zugang sehen nur einen Hinweis. Anmelden bleibt möglich.<?php if ($settings['maintenance_flag'] && !$settings['maintenance']): ?> <b class="gold">Geplant – gerade nicht aktiv.</b><?php endif; ?></p>
                    <input type="text" name="maintenance_message" value="<?php echo e($settings['maintenance_message']); ?>" maxlength="300" placeholder="Hinweistext">
                    <div class="f2" style="margin-top: 8px;">
                        <div><label class="field">Ab (optional)</label><input type="datetime-local" name="maintenance_from" value="<?php echo $settings['maintenance_from'] ? e(date('Y-m-d\TH:i', strtotime($settings['maintenance_from']))) : ''; ?>"></div>
                        <div><label class="field">Bis (endet automatisch)</label><input type="datetime-local" name="maintenance_until" value="<?php echo $settings['maintenance_until'] ? e(date('Y-m-d\TH:i', strtotime($settings['maintenance_until']))) : ''; ?>"></div>
                    </div>
                </div>
                <button class="btn btn-gold" style="margin-top: 12px;">Speichern</button>
            </form>
            <div class="panel">
                <div class="panel-title"><span>// Systemstatus</span></div>
                <table class="sys">
                    <?php foreach ($checks as [$label, $ok, $detail]): ?>
                        <tr><td><span class="sdot <?php echo $ok ? 'ok' : 'bad'; ?>"></span><?php echo e($label); ?></td><td class="<?php echo $ok ? '' : 'red'; ?>"><?php echo e($detail); ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        <div class="grid2">
            <div class="panel">
                <div class="panel-title"><span>// Speicher</span><span class="muted small"><?php echo bytes(array_sum($storage)); ?> gesamt</span></div>
                <?php $smax = max(1, max($storage ?: [1])); foreach ($storage as $name => $size): ?>
                    <div class="stor"><span><?php echo e($name); ?></span><span class="stor-bar"><i style="width: <?php echo round($size / $smax * 100); ?>%;"></i></span><span class="mono"><?php echo bytes((int)$size); ?></span></div>
                <?php endforeach; ?>
            </div>
            <div class="panel">
                <div class="panel-title"><span>// Werkzeuge</span></div>
                <div class="tools">
                    <form method="POST"><input type="hidden" name="tab" value="system"><input type="hidden" name="action" value="clear_feed_cache"><button class="btn btn-sm">📡 Feed neu laden</button></form>
                    <form method="POST" onsubmit="return confirm('Alle anderen Benutzer abmelden?');"><input type="hidden" name="tab" value="system"><input type="hidden" name="action" value="logout_all"><button class="btn btn-sm btn-danger">⏻ Alle anderen abmelden</button></form>
                    <form method="POST"><input type="hidden" name="tab" value="system"><input type="hidden" name="action" value="unlock_ips"><button class="btn btn-sm">🔓 Login-Sperren aufheben</button></form>
                    <form method="POST"><input type="hidden" name="tab" value="system"><input type="hidden" name="action" value="db_optimize"><button class="btn btn-sm">🗜️ Datenbank optimieren</button></form>
                    <form method="POST" onsubmit="return confirm('Protokolleinträge löschen?');"><input type="hidden" name="tab" value="system"><input type="hidden" name="action" value="audit_prune"><select name="days" style="width: auto; padding: 4px 8px;"><option value="30">älter als 30 Tage</option><option value="90" selected>älter als 90 Tage</option><option value="365">älter als 1 Jahr</option></select><button class="btn btn-sm">🧹 Protokoll aufräumen</button></form>
                    <?php if (can('backup.download')): ?><a href="backup.php" class="btn btn-sm">💾 Backup herunterladen</a><?php endif; ?>
                </div>
                <form method="POST" class="tools" style="margin-top: 14px;" onsubmit="return confirm('Alle im Browser gemachten Änderungen an diesem Hub verwerfen? (Eine Kopie landet im Ordner archive/)');">
                    <input type="hidden" name="tab" value="system"><input type="hidden" name="action" value="reset_hub">
                    <select name="hub" style="width: auto;"><?php foreach ($hubs as $h): ?><option value="<?php echo e($h); ?>"><?php echo e($h); ?><?php echo is_file(hub_data_file($h)) ? ' (bearbeitet)' : ''; ?></option><?php endforeach; ?></select>
                    <button class="btn btn-sm btn-danger">Hub zurücksetzen</button>
                </form>
            </div>
        </div>

<?php elseif ($tab === 'account'): ?>
        <div class="grid2">
            <form method="POST" class="panel" autocomplete="off">
                <input type="hidden" name="tab" value="account"><input type="hidden" name="action" value="account_save">
                <div class="panel-title"><span>// Profil &amp; Passwort</span></div>
                <div class="profile"><?php echo avatar($me, 'xl'); ?><div><b><?php echo e(user_label($me)); ?></b><small>@<?php echo e($me['username']); ?> · <span style="color: <?php echo e($me['role_color']); ?>;"><?php echo e($me['role_name']); ?></span></small><small>dabei seit <?php echo e(date('d.m.Y', strtotime($me['created_at']))); ?> · Passwort geändert <?php echo e(ago($me['password_changed'])); ?></small></div></div>
                <label class="field">Anzeigename</label><input type="text" name="display_name" value="<?php echo e($me['display_name']); ?>" maxlength="60">
                <div class="field-wrap"><label class="field">Neues Passwort <?php echo $must_pw ? '*' : '(leer = unverändert)'; ?> · mind. <?php echo $pol['min']; ?> Zeichen<?php echo $pol['classes'] ? ', ' . $pol['classes'] . ' Zeichenarten' : ''; ?></label><input type="password" name="password" id="np" minlength="<?php echo $pol['min']; ?>" autocomplete="new-password" data-meter="1" <?php echo $must_pw ? 'required autofocus' : ''; ?>><button type="button" class="eye" data-eye="np">👁</button><div class="meter"><div></div></div><div class="meter-label"></div></div>
                <label class="field">Neues Passwort wiederholen</label><input type="password" name="password2" minlength="8" autocomplete="new-password">
                <label class="field">Aktuelles Passwort (nur bei Passwortwechsel)</label><input type="password" name="current" autocomplete="current-password">
                <button class="btn btn-gold" style="margin-top: 12px; width: 100%;">Speichern</button>
            </form>
            <div class="panel">
                <div class="panel-title"><span>// Meine Rechte</span></div>
                <div class="my-perms">
                    <?php $lastg = ''; foreach (PERMISSIONS as $k => [$label, $grp]): if ($grp !== $lastg): $lastg = $grp; ?><em><?php echo e($grp); ?></em><?php endif; ?><span class="<?php echo can($k) ? 'yes' : 'no'; ?>"><?php echo can($k) ? '✓' : '✕'; ?> <?php echo e($label); ?></span><?php endforeach; ?>
                </div>
                <div class="panel-title" style="margin-top: 20px;"><span>// Meine letzten Aktivitäten</span></div>
                <div class="feed-list">
                    <?php foreach ($my_log as $r): ?><div class="fl lv-<?php echo e($r['level']); ?>"><span class="fl-dot"></span><div><b><?php echo e($r['action']); ?></b><small><?php echo e(ago($r['ts'])); ?> · <?php echo e($r['ip']); ?></small></div></div><?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="grid2">
            <div class="panel" id="tfa">
                <div class="panel-title"><span>// 🔐 Zwei-Faktor-Anmeldung</span><?php echo $me['totp_secret'] !== '' ? '<span class="dot-ok">aktiv</span>' : '<span class="dot-off">aus</span>'; ?></div>
                <?php if ($totp_codes): ?>
                    <div class="flash ok" style="margin: 0 0 10px;">✓ Eingerichtet! Speichere diese <b>Wiederherstellungscodes</b> jetzt an einem sicheren Ort – jeder funktioniert genau einmal, falls du dein Handy verlierst. Sie werden nie wieder angezeigt.</div>
                    <div class="codes" id="recCodes"><?php foreach ($totp_codes as $c): ?><code><?php echo e($c); ?></code><?php endforeach; ?></div>
                    <button type="button" class="btn btn-sm" data-copy-text="<?php echo e(implode("\n", $totp_codes)); ?>">⧉ Codes kopieren</button>
                <?php elseif ($totp_setup !== ''): $uri = totp_uri($totp_setup, $me['username']); ?>
                    <ol class="steps">
                        <li>Öffne eine Authenticator-App (z. B. Aegis, 2FAS, Google Authenticator, Microsoft Authenticator, 1Password, Bitwarden).</li>
                        <li>Scanne den QR-Code oder tippe den Schlüssel ab:
                            <div class="qr-wrap"><canvas id="qrCanvas" data-qr="<?php echo e($uri); ?>" width="180" height="180"></canvas>
                                <div><code class="secret"><?php echo e(trim(chunk_split($totp_setup, 4, ' '))); ?></code><a class="btn btn-sm" href="<?php echo e($uri); ?>">📱 In App öffnen</a></div></div></li>
                        <li>Gib den 6-stelligen Code aus der App ein:</li>
                    </ol>
                    <form method="POST" class="ip-add"><input type="hidden" name="tab" value="account"><input type="hidden" name="action" value="totp_confirm"><input type="text" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123456" class="code-input" autofocus><button class="btn btn-sm btn-gold">Bestätigen &amp; einschalten</button></form>
                <?php elseif ($me['totp_secret'] === ''): ?>
                    <p class="muted">Schützt dein Konto zusätzlich: Beim Anmelden brauchst du neben dem Passwort einen wechselnden Code aus einer App auf deinem Handy. Selbst wer dein Passwort kennt, kommt so nicht hinein.</p>
                    <form method="POST"><input type="hidden" name="tab" value="account"><input type="hidden" name="action" value="totp_begin"><button class="btn btn-gold" style="width: 100%;">🔐 Zwei-Faktor einrichten</button></form>
                <?php else: ?>
                    <p class="muted">Beim Anmelden wird nach dem Passwort ein Code aus deiner App abgefragt. Noch <b><?php echo $recovery_left; ?></b> von 8 Wiederherstellungscodes übrig.</p>
                    <form method="POST" class="ip-add"><input type="hidden" name="tab" value="account"><input type="password" name="current" required placeholder="Passwort zur Bestätigung" autocomplete="current-password">
                        <button name="action" value="totp_recovery" class="btn btn-sm">♻ Neue Codes</button>
                        <button name="action" value="totp_disable" class="btn btn-sm btn-danger" onclick="return confirm('Zwei-Faktor wirklich ausschalten?');">Ausschalten</button></form>
                <?php endif; ?>
            </div>
            <div class="panel">
                <div class="panel-title"><span>// 🖥️ Meine Geräte (<?php echo count($my_sessions); ?>)</span></div>
                <?php foreach ($my_sessions as $se): [$dic, $dlb] = device_label($se['agent']); $this_one = $se['id'] === current_session_id(); ?>
                    <div class="sess <?php echo $this_one ? 'this' : ''; ?>"><span class="sess-ic"><?php echo $dic; ?></span><div><b><?php echo e($dlb); ?></b><?php echo $this_one ? ' <span class="bdg ok">dieses Gerät</span>' : ''; ?><small><?php echo e($se['ip']); ?> · angemeldet <?php echo e(ago($se['created'])); ?> · aktiv <?php echo e(ago($se['last_seen'])); ?></small></div>
                        <?php if (!$this_one): ?><form method="POST"><input type="hidden" name="tab" value="account"><input type="hidden" name="action" value="session_revoke"><input type="hidden" name="sid" value="<?php echo e($se['id']); ?>"><button class="btn btn-sm" title="Abmelden">⏻</button></form><?php endif; ?></div>
                <?php endforeach; ?>
                <form method="POST" style="margin-top: 14px;" onsubmit="return confirm('Alle anderen Geräte/Browser abmelden?');"><input type="hidden" name="tab" value="account"><input type="hidden" name="action" value="account_logout_everywhere"><button class="btn btn-sm btn-danger" style="width: 100%;">Alle anderen Geräte abmelden</button></form>
            </div>
        </div>
<?php endif; ?>
    </main>
</div>

<!-- Befehlspalette -->
<div class="palette" id="palette" hidden>
    <div class="palette-box">
        <input type="text" id="paletteInput" placeholder="Wohin? Seite, Bereich oder Aktion …" autocomplete="off">
        <div class="palette-list" id="paletteList"></div>
        <div class="palette-foot"><kbd>↑</kbd><kbd>↓</kbd> wählen · <kbd>Enter</kbd> öffnen · <kbd>Esc</kbd> schließen</div>
    </div>
</div>
<script>window.PALETTE = <?php echo json_encode($palette, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
<?php endif; ?>

<script src="assets/qr.js"></script>
<script src="assets/admin.js"></script>
<script src="assets/gender.js"></script>
</body>
</html>
