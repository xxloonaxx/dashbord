<?php
/**
 * Furry Hub: gemeinsame Zähler (Boop, Awoo, Headpat, Umarmung), Fursona-Steckbriefe der Benutzer,
 * Pfotenabdrücke (Gästebuch) und die Inhalte für Spielereien (Glossar, Fakten, Bingo, Quiz …).
 * Datei: data/furhub_<zufall>.sqlite
 */
require_once __DIR__ . '/../core/auth.php';

const FH_REACTIONS = ['boop' => ['👃', 'Boops'], 'awoo' => ['🐺', 'Awoos'], 'pat' => ['🤚', 'Headpats'], 'hug' => ['🫂', 'Umarmungen']];
const FH_RATE_PER_MIN = 900;    // Reaktionen je Adresse und Minute (gegen Skripte)

function fh_db_present(): bool { return (bool)(glob(DATA_DIR . '/db/furhub_*.sqlite') ?: []); }

function fh_db(): PDO {
    static $db = null;
    if ($db) return $db;
    $dir = DATA_DIR . '/db';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (!is_file($dir . '/.htaccess')) file_put_contents($dir . '/.htaccess', "Require all denied\n");
    $found = glob($dir . '/furhub_*.sqlite') ?: [];
    $file = $found ? $found[0] : $dir . '/furhub_' . bin2hex(random_bytes(12)) . '.sqlite';
    $db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 5000');
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() < 1) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS counters (key TEXT PRIMARY KEY, n INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE IF NOT EXISTS user_counts (user_id INTEGER NOT NULL, key TEXT NOT NULL, day TEXT NOT NULL, n INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (user_id, key, day));
            CREATE TABLE IF NOT EXISTS rate (ip TEXT PRIMARY KEY, minute INTEGER NOT NULL, n INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS sonas (
                user_id INTEGER PRIMARY KEY, name TEXT NOT NULL, species TEXT NOT NULL DEFAULT '', pronouns TEXT NOT NULL DEFAULT '',
                bio TEXT NOT NULL DEFAULT '', ref_url TEXT NOT NULL DEFAULT '', look TEXT NOT NULL DEFAULT '{}', traits TEXT NOT NULL DEFAULT '[]',
                likes INTEGER NOT NULL DEFAULT 0, updated INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE IF NOT EXISTS sona_likes (sona_user INTEGER NOT NULL, liker INTEGER NOT NULL, PRIMARY KEY (sona_user, liker));
            CREATE TABLE IF NOT EXISTS guestbook (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, msg TEXT NOT NULL, paw TEXT NOT NULL DEFAULT '#f3c262', ts INTEGER NOT NULL);
            PRAGMA user_version = 1;
        ");
        foreach (array_keys(FH_REACTIONS) as $k) $db->prepare('INSERT OR IGNORE INTO counters (key, n) VALUES (?, 0)')->execute([$k]);
    }
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() < 2) {
        // Eigene Bilder (Fursona-Emotionen, Haustier, Karten), Tamagotchi-Stand, Karten
        $db->exec("
            CREATE TABLE IF NOT EXISTS images (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, kind TEXT NOT NULL, mime TEXT NOT NULL, w INTEGER NOT NULL, h INTEGER NOT NULL, size INTEGER NOT NULL, created INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS idx_images_user ON images(user_id);
            CREATE TABLE IF NOT EXISTS pets (user_id INTEGER PRIMARY KEY, state TEXT NOT NULL, updated INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS cards (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, data TEXT NOT NULL, shared INTEGER NOT NULL DEFAULT 0, updated INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS idx_cards_user ON cards(user_id);
            PRAGMA user_version = 2;
        ");
    }
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() < 3) {
        $db->exec("ALTER TABLE sonas ADD COLUMN gallery TEXT NOT NULL DEFAULT '[]'; PRAGMA user_version = 3;");
    }
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() < 4) {
        // Avatar als Profilbild (gerendertes SVG) & persönliche Hub-Daten je Benutzer (Erfolge, Bingo, Pixel …)
        $db->exec("ALTER TABLE sonas ADD COLUMN avatar_svg TEXT NOT NULL DEFAULT '';
                   CREATE TABLE IF NOT EXISTS userdata (user_id INTEGER NOT NULL, k TEXT NOT NULL, v TEXT NOT NULL, updated INTEGER NOT NULL, PRIMARY KEY (user_id, k));
                   PRAGMA user_version = 4;");
    }
    return $db;
}

/** Namen & Farben der Benutzer (aus der Site-DB) */
function fh_user_names(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    foreach (site_db()->query('SELECT u.id, u.username, u.display_name, r.color FROM users u JOIN roles r ON r.id = u.role_id') as $u) {
        $map[(int)$u['id']] = ['name' => $u['display_name'] !== '' ? $u['display_name'] : $u['username'], 'color' => $u['color']];
    }
    return $map;
}

// ---------- Reaktionen ----------

function fh_react(string $key, int $n): int {
    if (!isset(FH_REACTIONS[$key])) throw new InvalidArgumentException('Unbekannte Reaktion.');
    $n = max(1, min(60, $n));
    $db = fh_db();
    $ip = client_ip();
    $min = intdiv(time(), 60);
    $db->beginTransaction();
    $st = $db->prepare('SELECT minute, n FROM rate WHERE ip = ?');
    $st->execute([$ip]);
    $r = $st->fetch();
    $used = ($r && (int)$r['minute'] === $min) ? (int)$r['n'] : 0;
    $n = min($n, FH_RATE_PER_MIN - $used);
    if ($n <= 0) { $db->commit(); return 0; }
    $db->prepare('INSERT INTO rate (ip, minute, n) VALUES (?, ?, ?) ON CONFLICT(ip) DO UPDATE SET minute = excluded.minute, n = excluded.n')->execute([$ip, $min, $used + $n]);
    $db->prepare('INSERT INTO counters (key, n) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET n = n + excluded.n')->execute([$key, $n]);
    if ($u = current_user()) {
        $db->prepare('INSERT INTO user_counts (user_id, key, day, n) VALUES (?, ?, ?, ?) ON CONFLICT(user_id, key, day) DO UPDATE SET n = n + excluded.n')
           ->execute([(int)$u['id'], $key, date('Y-m-d'), $n]);
    }
    if (random_int(1, 50) === 1) $db->prepare('DELETE FROM rate WHERE minute < ?')->execute([$min - 5]);
    $db->commit();
    return $n;
}

function fh_stats(): array {
    $db = fh_db();
    $out = ['total' => [], 'mine' => null, 'today' => null, 'top' => []];
    foreach ($db->query('SELECT key, n FROM counters') as $r) $out['total'][$r['key']] = (int)$r['n'];
    $names = fh_user_names();
    foreach ($db->query("SELECT user_id, SUM(n) AS n FROM user_counts WHERE key = 'boop' GROUP BY user_id ORDER BY n DESC LIMIT 10") as $r) {
        if (!isset($names[(int)$r['user_id']])) continue;
        $out['top'][] = ['name' => $names[(int)$r['user_id']]['name'], 'color' => $names[(int)$r['user_id']]['color'], 'n' => (int)$r['n'], 'avatar' => fh_avatar_url((int)$r['user_id'])];
    }
    if ($u = current_user()) {
        $st = $db->prepare('SELECT key, SUM(n) AS n, SUM(CASE WHEN day = ? THEN n ELSE 0 END) AS today FROM user_counts WHERE user_id = ? GROUP BY key');
        $st->execute([date('Y-m-d'), (int)$u['id']]);
        $out['mine'] = $out['today'] = [];
        foreach ($st as $r) { $out['mine'][$r['key']] = (int)$r['n']; $out['today'][$r['key']] = (int)$r['today']; }
    }
    return $out;
}

// ---------- Fursonas ----------

const FH_LOOK_ENUMS = [
    'ears' => ['pointy', 'round', 'long', 'floppy', 'big', 'small', 'swept', 'none'],
    'muzzle' => ['long', 'medium', 'short', 'beak'],
    'marking' => ['none', 'mask', 'stripes', 'spots', 'blaze', 'cheeks', 'freckles', 'glow'],
    'extra' => ['none', 'horns', 'antlers', 'mane', 'fins', 'tuft', 'visor'],
    'acc' => ['none', 'glasses', 'bandana', 'bow', 'collar', 'headphones', 'flower', 'earring', 'scarf'],
    'eyeShape' => ['round', 'happy', 'sleepy', 'sharp'],
    'gender' => ['neutral', 'female', 'male', 'nonbinary'],
    'hair' => ['none', 'bangs', 'long', 'spiky', 'bun', 'curly', 'mohawk'],
    'pin' => ['none', 'rainbow', 'trans', 'nonbinary', 'bi', 'pan', 'lesbian', 'ace', 'gay'],
];
const FH_LOOK_COLORS = ['bg', 'main', 'second', 'muzzleC', 'inner', 'eye', 'nose', 'markC', 'accC', 'hairC'];

const FH_EMOTIONS = ['neutral', 'happy', 'sad', 'angry', 'surprised', 'sleepy'];
const FH_IMG_RE = '/^furhub\.php\?img=([0-9a-f]{24})$/';

function fh_clean_look($look, ?int $uid = null): array {
    $look = is_array($look) ? $look : [];
    $out = [];
    $out['mode'] = ($look['mode'] ?? '') === 'custom' ? 'custom' : 'svg';
    $out['species'] = preg_match('/^[a-z]{2,12}$/', (string)($look['species'] ?? '')) ? $look['species'] : '';
    $out['images'] = [];
    foreach (FH_EMOTIONS as $e) {
        $u = $look['images'][$e] ?? '';
        if (is_string($u) && preg_match(FH_IMG_RE, $u, $m) && $uid && fh_img_owner($m[1]) === $uid) $out['images'][$e] = $u;
    }
    $main = (string)($look['mainImg'] ?? '');   // Hauptbild (nicht verwechseln mit 'main' = Fellfarbe)
    $out['mainImg'] = (preg_match(FH_IMG_RE, $main, $m) && $uid && fh_img_owner($m[1]) === $uid) ? $main : '';
    if (!$out['images'] && $out['mainImg'] === '') $out['mode'] = 'svg';
    if (!$out['images']) $out['images'] = new stdClass();
    foreach (FH_LOOK_ENUMS as $k => $allowed) $out[$k] = in_array($look[$k] ?? '', $allowed, true) ? $look[$k] : $allowed[0];
    foreach (FH_LOOK_COLORS as $k) $out[$k] = preg_match('/^#[0-9a-f]{6}$/i', $look[$k] ?? '') ? strtolower($look[$k]) : '#888888';
    $out['blush'] = !empty($look['blush']);
    $out['fluff'] = !empty($look['fluff']);
    return $out;
}

/** Galerie: bis zu 12 eigene Bilder mit Beschriftung */
function fh_clean_gallery($g, int $uid): array {
    $out = [];
    foreach (array_slice(is_array($g) ? $g : [], 0, 12) as $it) {
        $u = (string)($it['url'] ?? '');
        if (!preg_match(FH_IMG_RE, $u, $m) || fh_img_owner($m[1]) !== $uid) continue;
        $out[] = ['url' => $u, 'caption' => mb_substr(trim((string)($it['caption'] ?? '')), 0, 80),
                  'kind' => in_array($it['kind'] ?? '', ['ref', 'full', 'head', 'art', 'other'], true) ? $it['kind'] : 'other'];
    }
    return $out;
}

function fh_sona_save(int $uid, array $in): array {
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 40);
    if ($name === '') throw new InvalidArgumentException('Deine Fursona braucht einen Namen.');
    $traits = array_slice(array_values(array_filter(array_map(function ($t) { return mb_substr(trim((string)$t), 0, 40); }, (array)($in['traits'] ?? [])))), 0, 6);
    $ref = trim((string)($in['ref_url'] ?? ''));
    if ($ref !== '' && safe_url($ref) === '#') throw new InvalidArgumentException('Der Ref-Link muss mit https:// beginnen.');
    $avatar = fh_avatar_clean((string)($in['avatar_svg'] ?? ''));
    fh_db()->prepare('INSERT INTO sonas (user_id, name, species, pronouns, bio, ref_url, look, traits, updated, gallery, avatar_svg) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                      ON CONFLICT(user_id) DO UPDATE SET name = excluded.name, species = excluded.species, pronouns = excluded.pronouns, bio = excluded.bio,
                      ref_url = excluded.ref_url, look = excluded.look, traits = excluded.traits, updated = excluded.updated, gallery = excluded.gallery, avatar_svg = excluded.avatar_svg')
        ->execute([$uid, $name, mb_substr(trim((string)($in['species'] ?? '')), 0, 40), mb_substr(trim((string)($in['pronouns'] ?? '')), 0, 30),
                   mb_substr(trim((string)($in['bio'] ?? '')), 0, 500), $ref, json_encode(fh_clean_look($in['look'] ?? [], $uid)), json_encode($traits, JSON_UNESCAPED_UNICODE), time(),
                   json_encode(fh_clean_gallery($in['gallery'] ?? [], $uid), JSON_UNESCAPED_UNICODE), $avatar]);
    return fh_sona((int)$uid) ?? [];
}

function fh_sona_row(array $r, ?int $me = null, array $liked = []): ?array {
    $names = fh_user_names();
    if (!isset($names[(int)$r['user_id']])) return null;   // gelöschte Benutzer ausblenden
    return ['user_id' => (int)$r['user_id'], 'owner' => $names[(int)$r['user_id']]['name'], 'owner_color' => $names[(int)$r['user_id']]['color'],
            'name' => $r['name'], 'species' => $r['species'], 'pronouns' => $r['pronouns'], 'bio' => $r['bio'], 'ref_url' => $r['ref_url'],
            'look' => json_decode($r['look'], true) ?: [], 'traits' => json_decode($r['traits'], true) ?: [], 'likes' => (int)$r['likes'],
            'gallery' => json_decode($r['gallery'] ?? '[]', true) ?: [],
            'avatar' => ($r['avatar_svg'] ?? '') !== '' ? 'furhub.php?avatar=' . (int)$r['user_id'] . '&v=' . (int)$r['updated'] : '',
            'liked' => in_array((int)$r['user_id'], $liked, true), 'mine' => $me === (int)$r['user_id'], 'updated' => (int)$r['updated']];
}

function fh_sona(int $uid): ?array {
    $st = fh_db()->prepare('SELECT * FROM sonas WHERE user_id = ?');
    $st->execute([$uid]);
    $r = $st->fetch();
    return $r ? fh_sona_row($r, $uid) : null;
}

function fh_sonas(): array {
    $me = current_user() ? (int)current_user()['id'] : null;
    $liked = [];
    if ($me) {
        $st = fh_db()->prepare('SELECT sona_user FROM sona_likes WHERE liker = ?');
        $st->execute([$me]);
        $liked = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    return array_values(array_filter(array_map(function ($r) use ($me, $liked) { return fh_sona_row($r, $me, $liked); },
        fh_db()->query("SELECT user_id, name, species, pronouns, bio, ref_url, look, traits, likes, updated, gallery, CASE WHEN avatar_svg <> '' THEN 'x' ELSE '' END AS avatar_svg FROM sonas ORDER BY updated DESC LIMIT 300")->fetchAll())));
}

function fh_sona_like(int $sona_user, int $liker): array {
    $db = fh_db();
    $db->beginTransaction();
    $st = $db->prepare('DELETE FROM sona_likes WHERE sona_user = ? AND liker = ?');
    $st->execute([$sona_user, $liker]);
    $liked = $st->rowCount() === 0;
    if ($liked) $db->prepare('INSERT INTO sona_likes (sona_user, liker) VALUES (?, ?)')->execute([$sona_user, $liker]);
    $db->prepare('UPDATE sonas SET likes = (SELECT COUNT(*) FROM sona_likes WHERE sona_user = ?) WHERE user_id = ?')->execute([$sona_user, $sona_user]);
    $db->commit();
    $n = $db->prepare('SELECT likes FROM sonas WHERE user_id = ?');
    $n->execute([$sona_user]);
    return ['liked' => $liked, 'likes' => (int)$n->fetchColumn()];
}

// ---------- Eigene Bilder ----------

const FH_IMG_KINDS = ['sona', 'pet', 'card', 'gallery'];
const FH_IMG_MAX_BYTES = 2500000;
const FH_IMG_MAX_PER_USER = 120;

function fh_img_dir(): string {
    $d = DATA_DIR . '/uploads/furhub';
    if (!is_dir($d)) mkdir($d, 0755, true);
    return $d;
}

function fh_img_owner(string $id): ?int {
    $st = fh_db()->prepare('SELECT user_id FROM images WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetchColumn();
    return $u === false ? null : (int)$u;
}

/** Hochgeladenes Bild prüfen, (wenn möglich) neu kodieren, speichern. Liefert ['id', 'url']. */
function fh_img_store(int $uid, array $file, string $kind): array {
    if (!in_array($kind, FH_IMG_KINDS, true)) throw new InvalidArgumentException('Unbekannte Bildart.');
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Upload fehlgeschlagen.');
    if ($file['size'] > FH_IMG_MAX_BYTES) throw new InvalidArgumentException('Das Bild ist zu groß (max. 2,5 MB).');
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_GIF => 'image/gif', IMAGETYPE_WEBP => 'image/webp'];
    if (!$info || !isset($types[$info[2]])) throw new InvalidArgumentException('Nur PNG, JPG, WebP oder GIF.');
    if ($info[0] > 4096 || $info[1] > 4096 || $info[0] < 8 || $info[1] < 8) throw new InvalidArgumentException('Ungültige Bildgröße.');
    fh_img_gc($uid);
    $n = fh_db()->prepare('SELECT COUNT(*) FROM images WHERE user_id = ?');
    $n->execute([$uid]);
    if ((int)$n->fetchColumn() >= FH_IMG_MAX_PER_USER) throw new InvalidArgumentException('Du hast zu viele Bilder – lösche alte Karten oder Bilder.');
    $id = bin2hex(random_bytes(12));
    $mime = $types[$info[2]];
    $w = $info[0]; $h = $info[1];
    $dest = fh_img_dir() . '/' . $id;
    $done = false;
    // Neu kodieren (entfernt Metadaten & versteckten Inhalt), wenn GD vorhanden – animierte GIFs bleiben unverändert
    if (function_exists('imagecreatefromstring') && $mime !== 'image/gif') {
        $im = @imagecreatefromstring((string)file_get_contents($file['tmp_name']));
        if ($im) {
            $max = $kind === 'gallery' ? 1600 : 768;
            if ($w > $max || $h > $max) {
                $f = $max / max($w, $h);
                $nw = max(1, (int)round($w * $f)); $nh = max(1, (int)round($h * $f));
                $dst = imagecreatetruecolor($nw, $nh);
                imagealphablending($dst, false); imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
                imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($im); $im = $dst; $w = $nw; $h = $nh;
            } else { imagealphablending($im, false); imagesavealpha($im, true); }
            if (function_exists('imagewebp') && @imagewebp($im, $dest, 88)) { $mime = 'image/webp'; $done = true; }
            elseif (@imagepng($im, $dest, 7)) { $mime = 'image/png'; $done = true; }
            imagedestroy($im);
        }
    }
    if (!$done && !move_uploaded_file($file['tmp_name'], $dest)) throw new InvalidArgumentException('Bild konnte nicht gespeichert werden.');
    fh_db()->prepare('INSERT INTO images (id, user_id, kind, mime, w, h, size, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$id, $uid, $kind, $mime, $w, $h, filesize($dest), time()]);
    return ['id' => $id, 'url' => 'furhub.php?img=' . $id, 'w' => $w, 'h' => $h];
}

/** Bilder eines Benutzers löschen, die nirgends mehr verwendet werden (älter als 1 Tag) */
function fh_img_gc(int $uid): void {
    $db = fh_db();
    $st = $db->prepare('SELECT id FROM images WHERE user_id = ? AND created < ?');
    $st->execute([$uid, time() - 86400]);
    $old = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$old) return;
    $blob = '';
    foreach ([['SELECT look || gallery FROM sonas WHERE user_id = ?'], ['SELECT state FROM pets WHERE user_id = ?'], ['SELECT data FROM cards WHERE user_id = ?']] as [$sql]) {
        $q = $db->prepare($sql);
        $q->execute([$uid]);
        $blob .= implode("\n", $q->fetchAll(PDO::FETCH_COLUMN));
    }
    foreach ($old as $id) {
        if (strpos($blob, $id) !== false) continue;
        @unlink(fh_img_dir() . '/' . $id);
        $db->prepare('DELETE FROM images WHERE id = ?')->execute([$id]);
    }
}

function fh_img_serve(string $id): void {
    if (!preg_match('/^[0-9a-f]{24}$/', $id)) { http_response_code(404); exit; }
    $st = fh_db()->prepare('SELECT mime FROM images WHERE id = ?');
    $st->execute([$id]);
    $mime = $st->fetchColumn();
    $file = fh_img_dir() . '/' . $id;
    if (!$mime || !is_file($file)) { http_response_code(404); exit; }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($file));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($file);
    exit;
}

// ---------- Tamagotchi (Stand je Benutzer) ----------

function fh_pet_get(int $uid): ?array {
    $st = fh_db()->prepare('SELECT state, updated FROM pets WHERE user_id = ?');
    $st->execute([$uid]);
    $r = $st->fetch();
    return $r ? ['state' => json_decode($r['state'], true), 'updated' => (int)$r['updated']] : null;
}

function fh_pet_save(int $uid, string $json): void {
    if (strlen($json) > 250000) throw new InvalidArgumentException('Zu viele Daten.');
    $state = json_decode($json, true);
    if (!is_array($state)) throw new InvalidArgumentException('Ungültige Daten.');
    // Ein Spielstand: nur eigene, gespeicherte Bilder; leere Objekte bleiben Objekte
    $clean = function (array $p) use ($uid): array {
        foreach ((array)($p['images'] ?? []) as $k => $u) {
            if (!is_string($u) || !preg_match(FH_IMG_RE, $u, $m) || fh_img_owner($m[1]) !== $uid) unset($p['images'][$k]);
        }
        foreach (['images', 'inv', 'stat', 'randomLook', 'cfg', 'fx'] as $k) if (isset($p[$k])) $p[$k] = (object)$p[$k];
        return $p;
    };
    if (isset($state['slots']) && is_array($state['slots'])) {   // Version 3: mehrere Spielstände
        if (count($state['slots']) > 6) throw new InvalidArgumentException('Maximal 6 Spielstände.');
        foreach ($state['slots'] as $id => $p) $state['slots'][$id] = is_array($p) ? $clean($p) : null;
        $state['slots'] = (object)array_filter($state['slots']);
    } else {
        $state = $clean($state);
    }
    fh_db()->prepare('INSERT INTO pets (user_id, state, updated) VALUES (?, ?, ?) ON CONFLICT(user_id) DO UPDATE SET state = excluded.state, updated = excluded.updated')
           ->execute([$uid, json_encode($state, JSON_UNESCAPED_UNICODE), time()]);
}

// ---------- Karten ----------

const FH_CARD_MAX = 40;

/** Karteninhalt säubern: nur bekannte Felder, Längen begrenzt, Bilder nur eigene */
function fh_card_clean(array $d, int $uid): array {
    $str = function ($v, int $n) { return mb_substr(trim((string)$v), 0, $n); };
    $num = function ($v, int $min, int $max) { return max($min, min($max, (int)$v)); };
    $img = $d['img'] ?? [];
    $url = (string)($img['url'] ?? '');
    if (!(preg_match(FH_IMG_RE, $url, $m) && fh_img_owner($m[1]) === $uid)) $url = '';
    $out = [
        'tpl' => in_array($d['tpl'] ?? '', ['trading', 'badge', 'profile'], true) ? $d['tpl'] : 'trading',
        'theme' => preg_match('/^[a-z]{2,12}$/', (string)($d['theme'] ?? '')) ? $d['theme'] : 'fire',
        'name' => $str($d['name'] ?? '', 40), 'subtitle' => $str($d['subtitle'] ?? '', 60), 'type' => $str($d['type'] ?? '', 4),
        'hp' => $num($d['hp'] ?? 100, 0, 999), 'flavor' => $str($d['flavor'] ?? '', 200), 'footer' => $str($d['footer'] ?? '', 60),
        'rarity' => $num($d['rarity'] ?? 1, 1, 5), 'holo' => !empty($d['holo']),
        'img' => ['src' => in_array($img['src'] ?? '', ['sona', 'upload', 'none'], true) ? $img['src'] : 'sona', 'url' => $url,
                  'emo' => in_array($img['emo'] ?? '', FH_EMOTIONS, true) ? $img['emo'] : 'happy', 'zoom' => $num($img['zoom'] ?? 100, 50, 250),
                  'x' => $num($img['x'] ?? 0, -100, 100), 'y' => $num($img['y'] ?? 0, -100, 100)],
        'abilities' => [], 'stats' => [], 'fields' => [],
    ];
    foreach (array_slice((array)($d['abilities'] ?? []), 0, 2) as $a) $out['abilities'][] = ['name' => $str($a['name'] ?? '', 30), 'cost' => $str($a['cost'] ?? '', 8), 'text' => $str($a['text'] ?? '', 120), 'dmg' => $str($a['dmg'] ?? '', 6)];
    foreach (array_slice((array)($d['stats'] ?? []), 0, 4) as $a) $out['stats'][] = ['label' => $str($a['label'] ?? '', 20), 'value' => $num($a['value'] ?? 5, 0, 10)];
    foreach (array_slice((array)($d['fields'] ?? []), 0, 6) as $a) $out['fields'][] = ['k' => $str($a['k'] ?? '', 20), 'v' => $str($a['v'] ?? '', 50)];
    return $out;
}

function fh_cards(int $uid): array {
    $st = fh_db()->prepare('SELECT * FROM cards WHERE user_id = ? ORDER BY updated DESC');
    $st->execute([$uid]);
    return array_map(function ($r) { return ['id' => (int)$r['id'], 'data' => json_decode($r['data'], true), 'shared' => (bool)$r['shared'], 'updated' => (int)$r['updated']]; }, $st->fetchAll());
}

function fh_card_save(int $uid, ?int $id, array $data, bool $shared): int {
    $json = json_encode(fh_card_clean($data, $uid), JSON_UNESCAPED_UNICODE);
    $db = fh_db();
    if ($id) {
        $st = $db->prepare('UPDATE cards SET data = ?, shared = ?, updated = ? WHERE id = ? AND user_id = ?');
        $st->execute([$json, $shared ? 1 : 0, time(), $id, $uid]);
        if ($st->rowCount()) return $id;
    }
    $n = $db->prepare('SELECT COUNT(*) FROM cards WHERE user_id = ?');
    $n->execute([$uid]);
    if ((int)$n->fetchColumn() >= FH_CARD_MAX) throw new InvalidArgumentException('Maximal ' . FH_CARD_MAX . ' Karten – lösche eine alte.');
    $db->prepare('INSERT INTO cards (user_id, data, shared, updated) VALUES (?, ?, ?, ?)')->execute([$uid, $json, $shared ? 1 : 0, time()]);
    return (int)$db->lastInsertId();
}

function fh_cards_shared(): array {
    $names = fh_user_names();
    $out = [];
    foreach (fh_db()->query('SELECT * FROM cards WHERE shared = 1 ORDER BY updated DESC LIMIT 200') as $r) {
        if (!isset($names[(int)$r['user_id']])) continue;
        $out[] = ['id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'owner' => $names[(int)$r['user_id']]['name'], 'data' => json_decode($r['data'], true), 'updated' => (int)$r['updated']];
    }
    return $out;
}

// ---------- Avatar als Profilbild ----------

/** Nur unser eigenes, harmloses SVG annehmen (wird außerdem nur per <img> mit strenger CSP ausgeliefert) */
function fh_avatar_clean(string $svg): string {
    $svg = trim($svg);
    if ($svg === '' || strlen($svg) > 40000 || strpos($svg, '<svg') !== 0) return '';
    if (preg_match('/<\s*(script|foreignObject|image|iframe|object|embed|use|a|style|animate|set)\b|\bon[a-z]+\s*=|javascript:|href\s*=|xlink:|@import|<!/i', $svg)) return '';
    if (!preg_match('/<\/svg>\s*$/', $svg)) return '';
    return $svg;
}

/** Benutzer-ID => Version (Zeitstempel) aller vorhandenen Avatare */
function fh_avatar_map(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    if (!(fh_db_present())) return $map;   // Furry Hub noch nie benutzt
    try { foreach (fh_db()->query("SELECT user_id, updated FROM sonas WHERE avatar_svg <> ''") as $r) $map[(int)$r['user_id']] = (int)$r['updated']; } catch (Throwable $e) { /* egal */ }
    return $map;
}

function fh_avatar_url(int $uid): string {
    $m = fh_avatar_map();
    return isset($m[$uid]) ? 'furhub.php?avatar=' . $uid . '&v=' . $m[$uid] : '';
}

/** <img> mit Avatar oder '' */
function fh_avatar_img(int $uid, string $cls = 'fh-pfp', string $alt = ''): string {
    $u = fh_avatar_url($uid);
    return $u ? '<img class="' . e($cls) . '" src="' . e($u) . '" alt="' . e($alt) . '" loading="lazy">' : '';
}

function fh_avatar_serve(int $uid): void {
    $st = fh_db()->prepare('SELECT avatar_svg FROM sonas WHERE user_id = ?');
    $st->execute([$uid]);
    $svg = fh_avatar_clean((string)$st->fetchColumn());
    if ($svg === '') { http_response_code(404); exit; }
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
    header('Cache-Control: public, max-age=86400');
    echo $svg;
    exit;
}

// ---------- Persönliche Hub-Daten (je Benutzer, geräteübergreifend) ----------

const FH_USERDATA_KEYS = ['ach', 'boops', 'bingo', 'quiz', 'best', 'pops', 'pixel', 'pixMirror', 'played', 'wheel', 'horoSp', 'owoMode', 'vol', 'trail', 'suit', 'draft', 'cardDraft'];

function fh_userdata(int $uid): array {
    $st = fh_db()->prepare('SELECT k, v FROM userdata WHERE user_id = ?');
    $st->execute([$uid]);
    $out = [];
    foreach ($st as $r) $out[$r['k']] = json_decode($r['v'], true);
    return $out;
}

function fh_userdata_save(int $uid, array $data): void {
    $db = fh_db();
    $up = $db->prepare('INSERT INTO userdata (user_id, k, v, updated) VALUES (?, ?, ?, ?) ON CONFLICT(user_id, k) DO UPDATE SET v = excluded.v, updated = excluded.updated');
    $del = $db->prepare('DELETE FROM userdata WHERE user_id = ? AND k = ?');
    foreach ($data as $k => $v) {
        if (!in_array($k, FH_USERDATA_KEYS, true)) continue;
        if ($v === null) { $del->execute([$uid, $k]); continue; }
        $json = json_encode($v, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > 150000) continue;   // zu groß (z. B. Bilder im Entwurf) → nur lokal
        $up->execute([$uid, $k, $json, time()]);
    }
}

// ---------- Pfotenabdrücke ----------

function fh_guestbook(): array {
    $names = fh_user_names();
    $me = current_user();
    $mod = can('furhub.moderate');
    $out = [];
    $av = [];
    foreach (fh_db()->query('SELECT user_id, look FROM sonas') as $r) $av[(int)$r['user_id']] = json_decode($r['look'], true);
    foreach (fh_db()->query('SELECT * FROM guestbook ORDER BY id DESC LIMIT 80') as $r) {
        $out[] = ['id' => (int)$r['id'], 'name' => $names[(int)$r['user_id']]['name'] ?? 'Ehemaliges Mitglied', 'msg' => $r['msg'], 'paw' => $r['paw'], 'ts' => (int)$r['ts'],
                  'can_delete' => $mod || ($me && (int)$me['id'] === (int)$r['user_id']), 'avatar' => $av[(int)$r['user_id']] ?? null, 'avatar_url' => fh_avatar_url((int)$r['user_id'])];
    }
    return $out;
}

function fh_guestbook_add(int $uid, string $msg, string $paw): void {
    $msg = trim(preg_replace('/\s+/u', ' ', $msg));
    if (mb_strlen($msg) < 2) throw new InvalidArgumentException('Schreib ein paar Worte. 🐾');
    if (mb_strlen($msg) > 280) throw new InvalidArgumentException('Maximal 280 Zeichen.');
    $st = fh_db()->prepare('SELECT MAX(ts) FROM guestbook WHERE user_id = ?');
    $st->execute([$uid]);
    if (time() - (int)$st->fetchColumn() < 30) throw new InvalidArgumentException('Nicht so schnell – warte ein paar Sekunden. 🐾');
    fh_db()->prepare('INSERT INTO guestbook (user_id, msg, paw, ts) VALUES (?, ?, ?, ?)')
           ->execute([$uid, $msg, preg_match('/^#[0-9a-f]{6}$/i', $paw) ? $paw : '#f3c262', time()]);
}

// ---------- Inhalte ----------

/** Etwas „vom Tag“ – für alle gleich, wechselt um Mitternacht */
function fh_daily(array $list, string $salt) {
    return $list[hexdec(substr(md5(date('Y-m-d') . $salt), 0, 7)) % count($list)];
}

const FH_FACTS = [
    'Seeotter halten sich beim Schlafen oft an den Pfoten, damit sie nicht auseinandertreiben.',
    'Der Fennek hat im Verhältnis zu seinem Körper die größten Ohren aller Hundeartigen – sie dienen auch zur Kühlung.',
    'Waschbären „waschen“ ihr Futter nicht: Mit nassen Pfoten können sie es nur besser ertasten.',
    'Der Nasenabdruck eines Hundes ist so einzigartig wie ein menschlicher Fingerabdruck.',
    'Schneeleoparden können nicht brüllen – sie fauchen, schnurren und machen ein „Chuffing“-Geräusch.',
    'Tiger sind nicht nur im Fell gestreift: Auch ihre Haut darunter zeigt das Streifenmuster.',
    'Der Kleine (Rote) Panda ist nicht eng mit dem Großen Panda verwandt, sondern bildet eine eigene Familie.',
    'Ein „schwarzer Panther“ ist meist ein Leopard oder Jaguar mit sehr dunklem Fell – die Flecken sieht man im Licht trotzdem.',
    'Fledermäuse sind die einzigen Säugetiere, die aktiv fliegen können.',
    'Geparden können nicht brüllen, aber schnurren und zwitschern wie ein Vogel.',
    'Krähen können sich menschliche Gesichter merken – auch über Jahre.',
    'Ratten geben beim Kitzeln Ultraschall-Laute von sich, die Forschende als eine Art Lachen deuten.',
    'Axolotl können verlorene Beine und sogar Teile ihres Herzens nachwachsen lassen.',
    'Bei Huskys sind zwei verschiedenfarbige Augen (Heterochromie) recht häufig.',
    'Koalas schlafen bis zu 18–22 Stunden am Tag – Eukalyptus liefert kaum Energie.',
    'Polarfüchse wechseln ihr Fell: im Winter weiß, im Sommer braun-grau.',
    'Die Haarbüschel auf den Ohren des Luchses heißen „Pinsel“.',
    'Hirsche werfen ihr Geweih jedes Jahr ab und bilden es neu.',
    'Ziegen haben rechteckige Pupillen – das erweitert ihr Sichtfeld enorm.',
    'Delfine schlafen mit nur einer Gehirnhälfte, die andere bleibt wach.',
    'Eulen können ihren Kopf um etwa 270 Grad drehen.',
    'Die Zähne von Kaninchen wachsen ein Leben lang nach – Knabbern hält sie kurz.',
    'Bei Tüpfelhyänen haben die Weibchen das Sagen und sind meist größer als die Männchen.',
    'Wölfe heulen, um ihr Rudel zusammenzurufen und ihr Revier zu markieren.',
    'Der Schwanz des Fuchses heißt in der Jägersprache „Lunte“ – er dient auch als Decke im Winter.',
    'Katzen schnurren nicht nur, wenn sie zufrieden sind, sondern auch zur Selbstberuhigung.',
    'Rote Füchse richten sich beim Mäusesprung vermutlich am Erdmagnetfeld aus – Sprünge Richtung Nordosten treffen öfter.',
    'Pinguine schenken sich bei manchen Arten Kieselsteine, um eine Partnerin oder einen Partner zu gewinnen.',
];

const FH_PROMPTS = [
    'Deine Sona beim ersten Kaffee des Tages', 'Deine Sona als Ritter*in mit viel zu großem Helm', 'Zwei Sonas teilen sich eine Pizza',
    'Deine Sona im Regen mit einem Blatt als Schirm', 'Deine Sona als Barista in einem Sternen-Café', 'Ein Con-Badge im Stil deiner Sona',
    'Deine Sona im Synthwave-Look', 'Deine Sona als Teil einer Weltraum-Crew', 'Deine Sona beim Zelten unter Polarlichtern',
    'Deine Sona in Pixel-Art (32×32)', 'Deine Sona als Plüschtier', 'Deine Sona, gerade frisch geboopt', 'Deine Sona als Bäcker*in mit Mehl im Fell',
    'Deine Sona im Herbstlaub', 'Deine Sona als Detektiv*in im Noir-Stil', 'Ein Sticker mit deiner Sona und einem kurzen Spruch',
    'Deine Sona beim Gaming-Marathon um 3 Uhr nachts', 'Deine Sona als Gärtner*in mit Riesen-Gemüse', 'Deine Sona in einem Lieblings-Videospiel',
    'Deine Sona als Briefmarke', 'Deine Sona im Schlafanzug mit Kuscheltier', 'Deine Sona mit einem Haustier – das selbst ein Haustier hat',
    'Deine Sona im Stil eines alten Reiseplakats', 'Deine Sona als Zauberlehrling, bei dem etwas schiefging',
];

const FH_GLOSSARY = [
    ['Fursona', 'Eine selbst erdachte tierische (anthropomorphe) Figur, die eine Person im Fandom repräsentiert – oft mit eigenem Namen, Charakter und Farben.'],
    ['Fursuit', 'Ein Kostüm einer Figur, meist einer Fursona. Es gibt Fullsuits (ganzer Körper) und Partials (z. B. Kopf, Pfoten und Schwanz).'],
    ['Partial', 'Teil-Fursuit: meist Kopf, Pfoten und Schwanz, getragen mit normaler Kleidung.'],
    ['Suiter', 'Person, die gerade einen Fursuit trägt.'],
    ['Handler / Spotter', 'Begleitperson, die auf Suiter achtet: Wege freihalten, Wasser reichen, Pausen erinnern – Suiter sehen und hören oft wenig.'],
    ['Headless Lounge', 'Ruheraum auf Cons, in dem Suiter den Kopf abnehmen, trinken und sich abkühlen können. Fotos sind dort tabu.'],
    ['Ref Sheet', 'Referenzbild einer Figur mit Ansichten, Farben und Details – wichtig für Artists bei Aufträgen.'],
    ['Commission', 'Bezahlter Kunstauftrag, z. B. ein Bild, ein Badge oder ein Fursuit.'],
    ['YCH', '„Your Character Here“: Eine vorbereitete Pose oder Szene, in die gegen Bezahlung deine Figur eingezeichnet wird.'],
    ['Artist Alley / Dealers’ Den', 'Bereiche auf Cons, in denen Künstler*innen und Händler*innen Kunst, Sticker, Badges und Merch verkaufen.'],
    ['Con', 'Kurz für Convention – ein mehrtägiges Treffen mit Panels, Partys, Fursuit-Parade und mehr.'],
    ['Furwalk / Parade', 'Gemeinsamer Spaziergang vieler Suiter, z. B. durch die Stadt oder über das Con-Gelände.'],
    ['Stammtisch', 'Regelmäßiges, lockeres Treffen der lokalen Community – oft in einem Lokal.'],
    ['Badge', 'Namensschild mit gezeichneter Fursona, auf Cons beliebt zum Tauschen und Sammeln.'],
    ['Boop', 'Ein sanftes Antippen der Nase. Sehr wichtig. Sehr ernst gemeint. 👃'],
    ['Headpat', 'Ein liebevolles Tätscheln des Kopfes – nur mit Zustimmung!'],
    ['Awoo', 'Das klassische Wolfsgeheul, oft als fröhlicher Ruf in Chats oder auf Cons.'],
    ['OwO / UwU', 'Emoticons für überraschte bzw. niedliche Gesichter – oft scherzhaft übertrieben verwendet.'],
    ['Mrrp / Mlem / Blep', 'Lautmalereien: ein fragender Katzenlaut, Zunge-rein-Zunge-raus, und eine herausgestreckte Zungenspitze.'],
    ['Beans', 'Die Pfotenballen – ob echt oder am Fursuit gern liebevoll „Bohnen“ genannt.'],
    ['Anthro', 'Anthropomorph: aufrecht gehende Tierfigur mit menschlichen Zügen.'],
    ['Feral', 'Tierfigur in natürlicher, vierbeiniger Gestalt.'],
    ['Toony / Realistic', 'Stilrichtungen bei Kunst und Fursuits: cartoonhaft und rund bzw. naturgetreu.'],
    ['Kemono', 'Japanisch geprägter Stil: niedliche, große Augen, weiche Formen – auch bei Fursuits beliebt.'],
    ['Hybrid', 'Eine Fursona, die zwei oder mehr Tierarten kombiniert, z. B. Fuchs-Wolf.'],
    ['Protogen', 'Beliebte erfundene Spezies: Wesen mit Visier-Gesicht aus LEDs und robotischen Teilen.'],
    ['Sergal', 'Erfundene Spezies mit keilförmigem Kopf, stammt aus der Welt „Vilous“.'],
    ['Dutch Angel Dragon', 'Erfundene, geschlossene Spezies mit Flügeln und Ohren-„Federn“ – Figuren dürfen nur mit Erlaubnis erstellt werden.'],
    ['Closed Species', 'Erfundene Arten, für die man eine Erlaubnis (oft einen Kauf) braucht, um eigene Figuren zu erstellen.'],
    ['Con Crud', 'Die Erkältung, die viele nach einer großen Con mit nach Hause nehmen. Händewaschen hilft!'],
];

const FH_BINGO = [
    'Jemand ruft „Awoo!“', 'Badge getauscht', 'Gruppenfoto mit 50+ Suitern', 'Kaffee verschüttet', 'Jemand schläft in der Lobby',
    'Dealers’ Den: Geld weg', 'Neue Freund*innen gefunden', 'Pfoten-Umarmung bekommen', 'Headless Lounge ist voll', 'Aufzug überfüllt',
    'Jemand findet seinen Badge nicht', 'Weniger als 5 Std. Schlaf', 'Furwalk mitgelaufen', 'Glitter überall', 'Protogen-Visier blinkt',
    'Karaoke um Mitternacht', 'Pizza um 3 Uhr nachts', 'Panel verpasst', 'Sticker gesammelt', 'Ein Fremder kennt deine Sona',
    'Discord: „Wo seid ihr?“', 'Auf die Nase geboopt', 'Jemand tritt auf einen Schwanz', 'Dance-Wettbewerb gesehen', 'Wasserflasche vergessen',
    'Commission bestellt', 'Hotelzimmer voller Plüschtiere', 'Jemand trägt Pfoten-Hausschuhe', 'Tränen beim Abschied', 'Handler rettet einen Suiter',
    'Jemand macht Tiergeräusche im Aufzug', 'Kunstwerk gekauft', 'Fursuit-Kopf als Sitzplatz-Reservierung', 'Eine Ente ist dabei', 'Jemand verteilt Süßigkeiten',
];

/** Quiz: Fragen mit Antworten => Punkte je Tierart */
const FH_QUIZ = [
    ['Dein perfekter Samstag?', [['Abenteuer im Wald', ['wolf' => 2, 'fox' => 1]], ['Gemütlich mit Decke & Tee', ['cat' => 2, 'redpanda' => 1]], ['Am See oder im Schwimmbad', ['otter' => 2, 'dragon' => 0]], ['Flohmarkt & Schätze suchen', ['raccoon' => 2, 'fox' => 1]]]],
    ['Wie bist du in einer Gruppe?', [['Ich halte alle zusammen', ['wolf' => 2]], ['Ich bringe alle zum Lachen', ['otter' => 2, 'fox' => 1]], ['Ich beobachte erst mal', ['cat' => 2, 'rabbit' => 1]], ['Ich übernehme die Führung', ['dragon' => 2, 'wolf' => 1]]]],
    ['Dein Lieblingssnack?', [['Etwas Süßes', ['redpanda' => 2, 'rabbit' => 1]], ['Fisch oder Sushi', ['otter' => 2, 'cat' => 1]], ['Was gerade im Kühlschrank ist', ['raccoon' => 2]], ['Ein riesiges Grillfest', ['dragon' => 2, 'wolf' => 1]]]],
    ['Welche Tageszeit magst du am liebsten?', [['Früher Morgen', ['rabbit' => 2]], ['Nachmittag in der Sonne', ['cat' => 1, 'otter' => 1, 'redpanda' => 1]], ['Dämmerung', ['fox' => 2]], ['Tiefe Nacht', ['raccoon' => 1, 'wolf' => 1, 'dragon' => 1]]]],
    ['Was sammelst du?', [['Glänzende Dinge', ['dragon' => 2, 'raccoon' => 1]], ['Erinnerungen & Fotos', ['wolf' => 1, 'otter' => 1]], ['Plüschtiere', ['redpanda' => 2, 'rabbit' => 1]], ['Geheimnisse', ['fox' => 2, 'cat' => 1]]]],
    ['Jemand ist traurig. Du …', [['… machst einen Witz', ['otter' => 2, 'fox' => 1]], ['… setzt dich still dazu', ['cat' => 1, 'rabbit' => 2]], ['… planst einen Ausflug', ['wolf' => 2]], ['… bringst Snacks', ['raccoon' => 1, 'redpanda' => 2]]]],
    ['Dein Stil?', [['Bequem & flauschig', ['redpanda' => 2, 'rabbit' => 1]], ['Dunkel & elegant', ['cat' => 2, 'dragon' => 1]], ['Praktisch für draußen', ['wolf' => 2, 'otter' => 1]], ['Bunt zusammengewürfelt', ['raccoon' => 2, 'fox' => 1]]]],
    ['Welche Superkraft?', [['Fliegen', ['dragon' => 2]], ['Unsichtbarkeit', ['fox' => 1, 'cat' => 2]], ['Mit Tieren reden', ['wolf' => 1, 'rabbit' => 1, 'otter' => 1]], ['Alles reparieren können', ['raccoon' => 2, 'redpanda' => 1]]]],
];

const FH_QUIZ_RESULTS = [
    'fox' => ['🦊', 'Fuchs', 'Schlau, neugierig und immer für einen Streich zu haben. Du findest Wege, wo andere Mauern sehen.'],
    'wolf' => ['🐺', 'Wolf', 'Treu und mit Herz fürs Rudel. Bei dir fühlen sich alle sicher – und zum Heulen gibt es immer einen Grund.'],
    'cat' => ['🐱', 'Katze', 'Unabhängig, elegant und mit gutem Gespür. Wer dein Vertrauen hat, bekommt das beste Schnurren.'],
    'otter' => ['🦦', 'Otter', 'Verspielt, gesellig und voller Energie. Du hältst Freund*innen an der Pfote – wortwörtlich.'],
    'raccoon' => ['🦝', 'Waschbär', 'Kreativ, findig und nachtaktiv. Aus Kram baust du Wunder – und der Snack-Vorrat ist legendär.'],
    'dragon' => ['🐉', 'Drache', 'Mutig, stolz und mit einem Hort voller Schätze. Wenn du dich einsetzt, brennt die Leidenschaft.'],
    'redpanda' => ['🐼', 'Roter Panda', 'Flauschig, gemütlich und liebevoll. Du machst aus jedem Raum einen Ort zum Wohlfühlen.'],
    'rabbit' => ['🐰', 'Hase', 'Sanft, aufmerksam und schneller als alle denken. Du merkst, wenn jemand Hilfe braucht.'],
];

/** Daten für die Seite (Spielereien, die im Browser laufen) */
function fh_client_data(): array {
    return [
        'reactions' => FH_REACTIONS, 'glossary' => FH_GLOSSARY, 'bingo' => FH_BINGO, 'emotions' => FH_EMOTIONS,
        'quiz' => FH_QUIZ, 'quizResults' => FH_QUIZ_RESULTS, 'enums' => FH_LOOK_ENUMS,
    ];
}
