<?php
/**
 * RSS-Reader: Quellen, Artikel, Gelesen/Gemerkt pro Benutzer (SQLite data/feed_*.sqlite).
 * Versteht RSS 2.0, RSS 1.0 (RDF), Atom und JSON Feed; findet Feeds auf normalen Webseiten (Autodiscovery)
 * und kennt Abkürzungen für YouTube, Reddit, Bluesky, Mastodon und GitHub.
 * Schreibt zusätzlich feed_cache.json (neueste Meldungen) für Startseite und Widgets.
 */
require_once __DIR__ . '/../core/common.php';
require_once __DIR__ . '/../core/auth.php';

const FEED_CACHE_FILE = DATA_DIR . '/cache/feed_cache.json';
const FEED_REFRESH = 900;          // Quellen höchstens alle 15 Min. neu laden
const FEED_KEEP_DAYS = 90;         // ältere, nicht gemerkte Artikel werden aufgeräumt
const FEED_UA = 'Mozilla/5.0 (compatible; GhostNodeReader/2.0; +RSS)';
const FEED_DEFAULT_SOURCES = [
    ['Bluesky Eurofurence', 'https://bsky.app/profile/did:plc:uneztaz2bvfqtzyyopyzi3em/rss', 'Furry & Bluesky', '🦊'],
    ['Bluesky Furry Feed', 'https://bsky.app/profile/did:plc:swluodtgntgcwia4s5xrf7qr/rss', 'Furry & Bluesky', '🐾'],
    ['Bluesky Furry Art', 'https://bsky.app/profile/did:plc:z72i7hdynmk6bbwg2jsc3qx5/rss', 'Furry & Bluesky', '🎨'],
    ['WikiFur – Änderungen', 'https://en.wikifur.com/w/api.php?action=feedrecentchanges&feedformat=rss', 'Furry & Bluesky', '📖'],
    ['Golem.de', 'https://rss.golem.de/rss.php?feed=RSS2.0', 'Tech & Linux', '💻'],
    ['Heise Online', 'https://www.heise.de/rss/heise-atom.xml', 'Tech & Linux', '📰'],
    ['Phoronix', 'https://www.phoronix.com/phoronix-rss.xml', 'Tech & Linux', '🐧'],
    ['Hacker News', 'https://news.ycombinator.com/rss', 'Tech & Linux', '🟧'],
    ['Modrinth News', 'https://modrinth.com/feed', 'Gaming & Modding', '🧱'],
];

function feed_db(): PDO {
    static $db = null;
    if ($db) return $db;
    $dir = DATA_DIR . '/db';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (!is_file($dir . '/.htaccess')) file_put_contents($dir . '/.htaccess', "Require all denied\n");
    $found = glob($dir . '/feed_*.sqlite') ?: [];
    $file = $found ? $found[0] : $dir . '/feed_' . bin2hex(random_bytes(12)) . '.sqlite';
    $db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 5000');
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() < 1) {
        $db->exec("CREATE TABLE IF NOT EXISTS sources (id INTEGER PRIMARY KEY, title TEXT NOT NULL, url TEXT NOT NULL UNIQUE, site_url TEXT NOT NULL DEFAULT '',
                   category TEXT NOT NULL DEFAULT 'Allgemein', icon TEXT NOT NULL DEFAULT '', enabled INTEGER NOT NULL DEFAULT 1, sort INTEGER NOT NULL DEFAULT 0,
                   last_fetch INTEGER NOT NULL DEFAULT 0, last_ok INTEGER NOT NULL DEFAULT 0, last_error TEXT NOT NULL DEFAULT '', etag TEXT NOT NULL DEFAULT '',
                   modified TEXT NOT NULL DEFAULT '', full_content INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL DEFAULT 0);
                   CREATE TABLE IF NOT EXISTS items (id INTEGER PRIMARY KEY, source_id INTEGER NOT NULL REFERENCES sources(id) ON DELETE CASCADE, guid TEXT NOT NULL,
                   title TEXT NOT NULL, link TEXT NOT NULL DEFAULT '', summary TEXT NOT NULL DEFAULT '', content TEXT NOT NULL DEFAULT '', image TEXT NOT NULL DEFAULT '',
                   author TEXT NOT NULL DEFAULT '', published INTEGER NOT NULL, fetched INTEGER NOT NULL, UNIQUE(source_id, guid));
                   CREATE INDEX IF NOT EXISTS items_pub ON items(published DESC);
                   CREATE TABLE IF NOT EXISTS item_state (user_id INTEGER NOT NULL, item_id INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
                   read INTEGER NOT NULL DEFAULT 0, starred INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(user_id, item_id));
                   PRAGMA user_version = 1;");
        // Übernahme der alten Quellen (feed_sources.json) bzw. Standardquellen
        $old = load_json(DATA_DIR . '/json/feed_sources.json', null);
        $ins = $db->prepare('INSERT OR IGNORE INTO sources (title, url, category, icon, sort, created) VALUES (?, ?, ?, ?, ?, ?)');
        $n = 0;
        if (is_array($old)) { foreach ($old as $cat => $list) foreach ((array)$list as $s) if (!empty($s['url'])) $ins->execute([$s['name'] ?? $s['url'], $s['url'], (string)$cat, '', $n++, time()]); }
        else foreach (FEED_DEFAULT_SOURCES as [$t, $u, $c, $i]) $ins->execute([$t, $u, $c, $i, $n++, time()]);
    }
    return $db;
}

// ---------- Netz ----------

/** Mehrere Adressen gleichzeitig laden. $reqs: key => ['url', 'etag', 'modified'] → key => ['code', 'body', 'etag', 'modified', 'error', 'url'] */
function feed_download(array $reqs, int $timeout = 12): array {
    $out = [];
    if (!function_exists('curl_multi_init')) {
        foreach ($reqs as $k => $r) { $b = @file_get_contents($r['url']); $out[$k] = ['code' => $b === false ? 0 : 200, 'body' => (string)$b, 'etag' => '', 'modified' => '', 'error' => $b === false ? 'nicht erreichbar' : '', 'url' => $r['url']]; }
        return $out;
    }
    $mh = curl_multi_init();
    $hs = [];
    foreach ($reqs as $k => $r) {
        $hdr = [];
        $ch = curl_init($r['url']);
        $headers = ['Accept: application/rss+xml, application/atom+xml, application/feed+json, application/xml;q=0.9, text/html;q=0.8, */*;q=0.5'];
        if (!empty($r['etag'])) $headers[] = 'If-None-Match: ' . $r['etag'];
        if (!empty($r['modified'])) $headers[] = 'If-Modified-Since: ' . $r['modified'];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => $timeout,
                                CURLOPT_USERAGENT => FEED_UA, CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => $headers, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                                CURLOPT_MAXFILESIZE => 8 * 1024 * 1024,
                                CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$hdr, $k) { if (preg_match('/^(etag|last-modified):\s*(.+)$/i', trim($line), $m)) $hdr[$k][strtolower($m[1])] = $m[2]; return strlen($line); }]);
        curl_multi_add_handle($mh, $ch);
        $hs[$k] = $ch;
    }
    do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
    foreach ($hs as $k => $ch) {
        $out[$k] = ['code' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => (string)curl_multi_getcontent($ch), 'etag' => $hdr[$k]['etag'] ?? '', 'modified' => $hdr[$k]['last-modified'] ?? '',
                    'error' => curl_error($ch), 'url' => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

// ---------- Parser ----------

function feed_text(string $s, int $max = 0): string {
    $s = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $s);
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br', '</p>', '</div>'], [' <br', ' </p>', ' </div>'], $s)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return $max && mb_strlen($t) > $max ? rtrim(mb_substr($t, 0, $max)) . '…' : $t;
}

function feed_abs(string $url, string $base): string {
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '' || preg_match('#^https?://#i', $url)) return $url;
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) return '';   // javascript:, data:, mailto: … nie übernehmen
    if (strpos($url, '//') === 0) return 'https:' . $url;
    $p = parse_url($base);
    if (empty($p['host'])) return '';
    $root = ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if ($url[0] === '/') return $root . $url;
    return $root . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $url;
}

/** HTML aus Feeds entschärfen: nur harmlose Tags, nur http(s)-Links, Bilder lazy und ohne Referrer */
function feed_sanitize(string $html, string $base): string {
    if (trim($html) === '') return '';
    if (!class_exists('DOMDocument')) return '<p>' . e(feed_text($html)) . '</p>';
    $allowed = ['p', 'br', 'a', 'img', 'b', 'strong', 'i', 'em', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'code', 'figure', 'figcaption',
                'table', 'thead', 'tbody', 'tr', 'td', 'th', 'hr', 'small', 'sup', 'sub', 'span', 'div', 'video', 'source', 'picture'];
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__r">' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('__r');
    if (!$root) return '';
    $walk = function (DOMNode $n) use (&$walk, $allowed, $base, $doc) {
        for ($c = $n->firstChild; $c; $c = $next) {
            $next = $c->nextSibling;
            if ($c instanceof DOMComment) { $n->removeChild($c); continue; }
            if (!($c instanceof DOMElement)) continue;
            $tag = strtolower($c->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'svg', 'math', 'noscript', 'link', 'meta'], true)) { $n->removeChild($c); continue; }
            $walk($c);
            if (!in_array($tag, $allowed, true)) {   // Inhalt behalten, Tag entfernen
                while ($c->firstChild) $n->insertBefore($c->firstChild, $c);
                $n->removeChild($c);
                continue;
            }
            $keep = [];
            if ($tag === 'a') { $h = feed_abs($c->getAttribute('href'), $base); if (preg_match('#^https?://#', $h)) $keep = ['href' => $h, 'target' => '_blank', 'rel' => 'noopener noreferrer nofollow']; }
            if ($tag === 'img') {
                $src = $c->getAttribute('src') ?: $c->getAttribute('data-src');
                $src = feed_abs($src, $base);
                if (!preg_match('#^https?://#', $src)) { $n->removeChild($c); continue; }
                $keep = ['src' => $src, 'alt' => mb_substr($c->getAttribute('alt'), 0, 200), 'loading' => 'lazy', 'referrerpolicy' => 'no-referrer'];
            }
            if ($tag === 'video' || $tag === 'source') { $s = feed_abs($c->getAttribute('src'), $base); if (preg_match('#^https?://#', $s)) $keep = ['src' => $s] + ($tag === 'video' ? ['controls' => 'controls', 'preload' => 'none'] : []); }
            if ($tag === 'td' || $tag === 'th') foreach (['colspan', 'rowspan'] as $a) if (ctype_digit($c->getAttribute($a))) $keep[$a] = $c->getAttribute($a);
            while ($c->attributes->length) $c->removeAttribute($c->attributes->item(0)->name);
            foreach ($keep as $a => $v) $c->setAttribute($a, $v);
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return trim(preg_replace('#(<br\s*/?>\s*){3,}#', '<br><br>', $out));
}

function feed_first_img(string $html, string $base): string {
    if (preg_match('#<img[^>]+src=["\']([^"\']+)#i', $html, $m)) { $u = feed_abs($m[1], $base); if (preg_match('#^https?://#', $u) && !preg_match('#(pixel|tracking|feeds\.feedburner|/1x1|spacer)#i', $u)) return $u; }
    return '';
}

/**
 * Feed-Inhalt auswerten. Rückgabe: ['title', 'site', 'items' => [[guid, title, link, summary, content, image, author, published]]] oder null.
 */
function feed_parse(string $body, string $url): ?array {
    $body = ltrim($body, "\xEF\xBB\xBF \t\r\n");
    if ($body === '') return null;
    // JSON Feed
    if ($body[0] === '{') {
        $j = json_decode($body, true);
        if (!is_array($j) || empty($j['items'])) return null;
        $items = [];
        foreach ((array)$j['items'] as $it) {
            $html = (string)($it['content_html'] ?? '');
            $text = (string)($it['content_text'] ?? $it['summary'] ?? '');
            $link = feed_abs((string)($it['url'] ?? $it['external_url'] ?? ''), $url);
            $t = feed_text((string)($it['title'] ?? ''), 300);
            $items[] = ['guid' => (string)($it['id'] ?? $link), 'title' => $t !== '' ? $t : (feed_text($text ?: $html, 120) ?: '(ohne Titel)'), 'link' => $link, 'summary' => feed_text($text ?: $html, 400),
                        'content' => $html !== '' ? feed_sanitize($html, $link ?: $url) : nl2br(e($text)), 'image' => feed_abs((string)($it['image'] ?? $it['banner_image'] ?? ''), $url) ?: feed_first_img($html, $url),
                        'author' => (string)($it['authors'][0]['name'] ?? $it['author']['name'] ?? ''), 'published' => strtotime((string)($it['date_published'] ?? $it['date_modified'] ?? '')) ?: 0];
        }
        return ['title' => (string)($j['title'] ?? ''), 'site' => (string)($j['home_page_url'] ?? ''), 'items' => $items];
    }
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_use_internal_errors($prev);
    if (!$xml) return null;
    $items = [];
    $ns = $xml->getDocNamespaces(true);
    $media = function ($node) use ($url) {
        $m = $node->children('http://search.yahoo.com/mrss/');
        foreach (['content', 'thumbnail'] as $t) if (isset($m->$t)) foreach ($m->$t as $x) { $u = (string)$x->attributes()->url; $ty = (string)$x->attributes()->type; $me = (string)$x->attributes()->medium;
            if ($u !== '' && ($t === 'thumbnail' || $me === 'image' || strpos($ty, 'image/') === 0 || preg_match('/\.(jpe?g|png|gif|webp)(\?|$)/i', $u))) return feed_abs($u, $url); }
        if (isset($m->group)) { $g = $m->group->children('http://search.yahoo.com/mrss/'); if (isset($g->thumbnail)) return feed_abs((string)$g->thumbnail->attributes()->url, $url); }
        return '';
    };
    if (isset($xml->channel)) {   // RSS 2.0 / 1.0
        $ch = $xml->channel;
        $list = isset($ch->item) ? $ch->item : $xml->item;
        foreach ($list as $it) {
            $c = $it->children('http://purl.org/rss/1.0/modules/content/');
            $dc = $it->children('http://purl.org/dc/elements/1.1/');
            $html = isset($c->encoded) ? (string)$c->encoded : (string)$it->description;
            $link = feed_abs((string)$it->link, $url);
            $enc = isset($it->enclosure) ? (string)$it->enclosure['url'] : '';
            $img = $media($it) ?: (preg_match('/^image\//', (string)($it->enclosure['type'] ?? '')) || preg_match('/\.(jpe?g|png|gif|webp)(\?|$)/i', $enc) ? feed_abs($enc, $url) : '') ?: feed_first_img($html, $link ?: $url);
            $title = feed_text((string)$it->title, 300);
            $summary = feed_text((string)$it->description ?: $html, 400);
            if ($title === '') $title = $summary !== '' ? feed_text($summary, 120) : '(ohne Titel)';   // z. B. Bluesky
            $items[] = ['guid' => (string)($it->guid ?: $link ?: md5($title)), 'title' => $title, 'link' => $link, 'summary' => $summary === $title ? '' : $summary,
                        'content' => feed_sanitize($html, $link ?: $url), 'image' => $img, 'author' => (string)($dc->creator ?? $it->author ?? ''),
                        'published' => strtotime((string)($it->pubDate ?: ($dc->date ?? ''))) ?: 0];
        }
        return ['title' => feed_text((string)$ch->title, 150), 'site' => feed_abs((string)$ch->link, $url), 'items' => $items];
    }
    if (isset($xml->entry) || $xml->getName() === 'feed') {   // Atom
        $site = '';
        foreach ($xml->link as $l) if ((string)$l['rel'] === 'alternate' || (string)$l['rel'] === '') $site = feed_abs((string)$l['href'], $url);
        foreach ($xml->entry as $it) {
            $link = '';
            foreach ($it->link as $l) if ((string)$l['rel'] === 'alternate' || (string)$l['rel'] === '') { $link = feed_abs((string)$l['href'], $url); break; }
            $html = (string)$it->content !== '' ? (string)$it->content : (string)$it->summary;
            $img = $media($it) ?: feed_first_img($html, $link ?: $url);
            foreach ($it->link as $l) if ((string)$l['rel'] === 'enclosure' && strpos((string)$l['type'], 'image/') === 0 && !$img) $img = feed_abs((string)$l['href'], $url);
            $title = feed_text((string)$it->title, 300);
            $summary = feed_text((string)$it->summary ?: $html, 400);
            $items[] = ['guid' => (string)($it->id ?: $link), 'title' => $title !== '' ? $title : feed_text($summary, 120), 'link' => $link, 'summary' => $summary === $title ? '' : $summary,
                        'content' => feed_sanitize($html, $link ?: $url), 'image' => $img, 'author' => (string)($it->author->name ?? ''),
                        'published' => strtotime((string)($it->published ?: $it->updated)) ?: 0];
        }
        return ['title' => feed_text((string)$xml->title, 150), 'site' => $site, 'items' => $items];
    }
    return null;
}

/** Adressen von YouTube, Reddit, Bluesky, Mastodon, GitHub in Feed-Adressen umwandeln */
function feed_shortcut(string $url): string {
    $u = trim($url);
    if ($u !== '' && !preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
    if (preg_match('#youtube\.com/channel/(UC[\w-]{22})#', $u, $m)) return 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $m[1];
    if (preg_match('#youtube\.com/playlist\?list=([\w-]+)#', $u, $m)) return 'https://www.youtube.com/feeds/videos.xml?playlist_id=' . $m[1];
    if (preg_match('#^https?://(www\.|old\.)?reddit\.com/(r|u|user)/([\w-]+)/?$#i', $u, $m)) return 'https://www.reddit.com/' . $m[2] . '/' . $m[3] . '/.rss';
    if (preg_match('#^https?://bsky\.app/profile/([^/?\#]+)/?$#i', $u, $m)) return 'https://bsky.app/profile/' . $m[1] . '/rss';
    if (preg_match('#^https?://github\.com/([\w.-]+)/([\w.-]+)/?$#i', $u, $m)) return 'https://github.com/' . $m[1] . '/' . $m[2] . '/releases.atom';
    if (preg_match('#^https?://([\w.-]+)/@([\w.]+)/?$#i', $u, $m) && !preg_match('#youtube|medium#i', $m[1])) return 'https://' . $m[1] . '/@' . $m[2] . '.rss';   // Mastodon
    return $u;
}

/** Feed unter einer Adresse finden (direkt oder über <link rel=alternate> der Webseite) */
function feed_discover(string $url): array {
    $url = feed_shortcut($url);
    if (!preg_match('#^https?://[^\s/]+\.[^\s]+#i', $url)) throw new RuntimeException('Bitte eine gültige Adresse eingeben.');
    $r = feed_download(['x' => ['url' => $url]])['x'];
    if ($r['code'] >= 400 || $r['body'] === '') throw new RuntimeException('Adresse nicht erreichbar (' . ($r['code'] ?: $r['error']) . ').');
    $p = feed_parse($r['body'], $r['url']);
    if ($p) return ['url' => $url, 'title' => $p['title'] ?: (parse_url($url, PHP_URL_HOST) ?: 'Feed'), 'site' => $p['site'], 'count' => count($p['items']), 'sample' => array_slice(array_column($p['items'], 'title'), 0, 3), 'alternatives' => []];
    // Webseite → nach Feed-Links suchen
    preg_match_all('#<link[^>]+>#i', $r['body'], $links);
    $found = [];
    foreach ($links[0] as $l) {
        if (!preg_match('#rel=["\']?alternate#i', $l) || !preg_match('#type=["\']?application/(rss\+xml|atom\+xml|feed\+json|json)#i', $l) || !preg_match('#href=["\']([^"\']+)#i', $l, $h)) continue;
        $t = preg_match('#title=["\']([^"\']*)#i', $l, $tm) ? html_entity_decode($tm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $found[] = ['url' => feed_abs($h[1], $r['url']), 'title' => $t];
    }
    if (!$found) foreach (['/feed', '/rss', '/rss.xml', '/feed.xml', '/atom.xml', '/index.xml'] as $guess) $found[] = ['url' => feed_abs($guess, $r['url']), 'title' => '', 'guess' => true];
    foreach ($found as $i => $f) {
        $x = feed_download(['x' => ['url' => $f['url']]], 8)['x'];
        $p = $x['code'] < 400 ? feed_parse($x['body'], $x['url']) : null;
        if (!$p) continue;
        return ['url' => $f['url'], 'title' => $p['title'] ?: $f['title'] ?: (parse_url($url, PHP_URL_HOST) ?: 'Feed'), 'site' => $p['site'] ?: $url, 'count' => count($p['items']),
                'sample' => array_slice(array_column($p['items'], 'title'), 0, 3), 'alternatives' => array_values(array_filter($found, function ($a) use ($f) { return empty($a['guess']) && $a['url'] !== $f['url']; }))];
    }
    throw new RuntimeException('Auf dieser Seite wurde kein RSS-, Atom- oder JSON-Feed gefunden.');
}

// ---------- Aktualisieren ----------

/** Quellen neu laden (alle fälligen, oder bestimmte IDs). Liefert ['sources' => n, 'new' => n, 'errors' => n] */
function feed_refresh(?array $ids = null, bool $force = false): array {
    $db = feed_db();
    $rows = $db->query('SELECT * FROM sources WHERE enabled = 1')->fetchAll();
    $due = array_values(array_filter($rows, function ($s) use ($ids, $force) {
        if ($ids !== null) return in_array((int)$s['id'], $ids, true);
        return $force || time() - (int)$s['last_fetch'] > FEED_REFRESH;
    }));
    $res = ['sources' => count($due), 'new' => 0, 'errors' => 0];
    if (!$due) return $res;
    $mark = $db->prepare('UPDATE sources SET last_fetch = ? WHERE id = ?');
    foreach ($due as $s) $mark->execute([time(), $s['id']]);   // gegen gleichzeitige Doppelabrufe
    $reqs = [];
    foreach ($due as $s) $reqs[$s['id']] = ['url' => $s['url'], 'etag' => $force ? '' : $s['etag'], 'modified' => $force ? '' : $s['modified']];
    $dl = feed_download($reqs);
    $ins = $db->prepare('INSERT OR IGNORE INTO items (source_id, guid, title, link, summary, content, image, author, published, fetched) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $upd = $db->prepare('UPDATE sources SET last_ok = ?, last_error = ?, etag = ?, modified = ?, site_url = CASE WHEN site_url = \'\' THEN ? ELSE site_url END WHERE id = ?');
    $err = $db->prepare('UPDATE sources SET last_error = ? WHERE id = ?');
    $db->beginTransaction();
    foreach ($due as $s) {
        $r = $dl[$s['id']] ?? null;
        if (!$r) continue;
        if ($r['code'] === 304) { $upd->execute([time(), '', $s['etag'], $s['modified'], '', $s['id']]); continue; }
        $p = $r['code'] >= 200 && $r['code'] < 400 ? feed_parse($r['body'], $r['url']) : null;
        if (!$p) { $res['errors']++; $err->execute([$r['code'] >= 400 ? 'HTTP ' . $r['code'] : ($r['error'] ?: ($r['code'] ? 'Kein gültiger Feed' : 'Nicht erreichbar')), $s['id']]); continue; }
        $now = time();
        foreach ($p['items'] as $i => $it) {
            $pub = $it['published'] ?: $now - $i;   // ohne Datum: Reihenfolge beibehalten
            if ($pub > $now + 3600) $pub = $now;
            $ins->execute([$s['id'], mb_substr($it['guid'] ?: $it['link'] ?: md5($it['title']), 0, 500), $it['title'], $it['link'], $it['summary'], mb_substr($it['content'], 0, 200000), $it['image'], mb_substr($it['author'], 0, 120), $pub, $now]);
            $res['new'] += $ins->rowCount();
        }
        $upd->execute([$now, '', $r['etag'], $r['modified'], $p['site'], $s['id']]);
    }
    // Aufräumen: alte, nicht gemerkte Artikel; je Quelle max. 600
    $db->prepare('DELETE FROM items WHERE published < ? AND id NOT IN (SELECT item_id FROM item_state WHERE starred = 1)')->execute([time() - FEED_KEEP_DAYS * 86400]);
    try { $db->exec('DELETE FROM items WHERE id IN (SELECT id FROM (SELECT id, ROW_NUMBER() OVER (PARTITION BY source_id ORDER BY published DESC) AS rn FROM items) WHERE rn > 600) AND id NOT IN (SELECT item_id FROM item_state WHERE starred = 1)'); } catch (PDOException $e) { /* ältere SQLite ohne Fensterfunktionen */ }
    $db->commit();
    feed_write_cache();
    return $res;
}

/** feed_cache.json für Startseite & Widgets */
function feed_write_cache(): void {
    $rows = feed_db()->query('SELECT i.title, i.link, i.summary AS description, i.published AS date, s.title AS source, s.category FROM items i JOIN sources s ON s.id = i.source_id WHERE s.enabled = 1 ORDER BY i.published DESC LIMIT 100')->fetchAll();
    save_json(FEED_CACHE_FILE, array_map(function ($r) { $r['date'] = (int)$r['date']; $r['link'] = safe_url((string)$r['link']); $r['description'] = mb_substr((string)$r['description'], 0, 300); return $r; }, $rows));
}

// ---------- Abfragen ----------

function feed_uid(): int { return (int)(current_user()['id'] ?? 0); }

/** Quellen mit ungelesen-Zähler */
function feed_sources_list(): array {
    $uid = feed_uid();
    $st = feed_db()->prepare('SELECT s.*, (SELECT COUNT(*) FROM items i WHERE i.source_id = s.id) AS total,
        (SELECT COUNT(*) FROM items i LEFT JOIN item_state st ON st.item_id = i.id AND st.user_id = ? WHERE i.source_id = s.id AND COALESCE(st.read, 0) = 0) AS unread,
        (SELECT MAX(published) FROM items i WHERE i.source_id = s.id) AS newest
        FROM sources s ORDER BY s.category COLLATE NOCASE, s.sort, s.title COLLATE NOCASE');
    $st->execute([$uid]);
    return array_map(function ($s) {
        $host = parse_url($s['site_url'] ?: $s['url'], PHP_URL_HOST) ?: '';
        return ['id' => (int)$s['id'], 'title' => $s['title'], 'url' => $s['url'], 'site' => $s['site_url'], 'host' => $host, 'category' => $s['category'], 'icon' => $s['icon'],
                'enabled' => (bool)$s['enabled'], 'last_fetch' => (int)$s['last_fetch'], 'last_ok' => (int)$s['last_ok'], 'error' => $s['last_error'], 'total' => (int)$s['total'],
                'unread' => (int)$s['unread'], 'newest' => (int)$s['newest']];
    }, $st->fetchAll());
}

/**
 * Artikel-Liste. $f: view (all|unread|starred|today), source, category, q, sort (new|old), offset, limit
 */
function feed_items(array $f): array {
    $uid = feed_uid();
    $where = ['s.enabled = 1'];
    $args = [$uid];
    if (!empty($f['source'])) { $where[] = 'i.source_id = ?'; $args[] = (int)$f['source']; }
    if (!empty($f['category'])) { $where[] = 's.category = ?'; $args[] = (string)$f['category']; }
    $view = (string)($f['view'] ?? 'all');
    if ($view === 'unread' || !empty($f['unread_only'])) $where[] = 'COALESCE(st.read, 0) = 0';
    if ($view === 'starred') $where[] = 'COALESCE(st.starred, 0) = 1';
    if ($view === 'today') { $where[] = 'i.published >= ?'; $args[] = strtotime('today'); }
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') foreach (preg_split('/\s+/u', mb_substr($q, 0, 100)) as $w) { $where[] = '(i.title LIKE ? OR i.summary LIKE ? OR i.author LIKE ?)'; array_push($args, "%$w%", "%$w%", "%$w%"); }
    $order = ($f['sort'] ?? '') === 'old' ? 'i.published ASC' : 'i.published DESC';
    $limit = max(10, min(100, (int)($f['limit'] ?? 40)));
    $offset = max(0, (int)($f['offset'] ?? 0));
    $st = feed_db()->prepare('SELECT i.id, i.source_id, i.title, i.link, i.summary, i.image, i.author, i.published, COALESCE(st.read, 0) AS read, COALESCE(st.starred, 0) AS starred,
        s.title AS source, s.icon, s.category FROM items i JOIN sources s ON s.id = i.source_id LEFT JOIN item_state st ON st.item_id = i.id AND st.user_id = ?
        WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order . ' LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset);
    $st->execute($args);
    $rows = $st->fetchAll();
    return ['items' => array_map(function ($r) {
        return ['id' => (int)$r['id'], 'sid' => (int)$r['source_id'], 'title' => $r['title'], 'link' => safe_url($r['link']), 'summary' => mb_substr($r['summary'], 0, 300), 'image' => $r['image'],
                'author' => $r['author'], 'ts' => (int)$r['published'], 'read' => (bool)$r['read'], 'starred' => (bool)$r['starred'], 'source' => $r['source'], 'icon' => $r['icon'], 'category' => $r['category']];
    }, array_slice($rows, 0, $limit)), 'more' => count($rows) > $limit];
}

function feed_item(int $id): ?array {
    $st = feed_db()->prepare('SELECT i.*, s.title AS source, s.icon, s.site_url FROM items i JOIN sources s ON s.id = i.source_id WHERE i.id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    $words = str_word_count(feed_text($r['content'] ?: $r['summary']), 0, 'äöüÄÖÜß');
    return ['id' => (int)$r['id'], 'title' => $r['title'], 'link' => safe_url($r['link']), 'content' => $r['content'] !== '' ? $r['content'] : '<p>' . e($r['summary']) . '</p>', 'image' => $r['image'],
            'author' => $r['author'], 'ts' => (int)$r['published'], 'source' => $r['source'], 'icon' => $r['icon'], 'site' => $r['site_url'], 'minutes' => max(1, (int)round($words / 220))];
}

function feed_set_state(array $ids, ?bool $read, ?bool $starred): void {
    $uid = feed_uid();
    if (!$uid || !$ids) return;
    $db = feed_db();
    $db->beginTransaction();
    $ins = $db->prepare('INSERT OR IGNORE INTO item_state (user_id, item_id) VALUES (?, ?)');
    foreach ($ids as $id) {
        $ins->execute([$uid, (int)$id]);
        if ($read !== null) $db->prepare('UPDATE item_state SET read = ? WHERE user_id = ? AND item_id = ?')->execute([$read ? 1 : 0, $uid, (int)$id]);
        if ($starred !== null) $db->prepare('UPDATE item_state SET starred = ? WHERE user_id = ? AND item_id = ?')->execute([$starred ? 1 : 0, $uid, (int)$id]);
    }
    $db->commit();
}

/** Alle (gefilterten) als gelesen markieren */
function feed_mark_all(array $f): int {
    $uid = feed_uid();
    if (!$uid) return 0;
    $where = ['s.enabled = 1'];
    $args = [];
    if (!empty($f['source'])) { $where[] = 'i.source_id = ?'; $args[] = (int)$f['source']; }
    if (!empty($f['category'])) { $where[] = 's.category = ?'; $args[] = (string)$f['category']; }
    if (($f['view'] ?? '') === 'today') { $where[] = 'i.published >= ?'; $args[] = strtotime('today'); }
    if (!empty($f['older'])) { $where[] = 'i.published < ?'; $args[] = time() - (int)$f['older'] * 86400; }
    $st = feed_db()->prepare('SELECT i.id FROM items i JOIN sources s ON s.id = i.source_id WHERE ' . implode(' AND ', $where));
    $st->execute($args);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    feed_set_state($ids, true, null);
    return count($ids);
}

// ---------- OPML ----------

function feed_opml(): string {
    $x = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><opml version="2.0"/>');
    $h = $x->addChild('head');
    $h->addChild('title', 'GHOST NODE Feeds');
    $h->addChild('dateCreated', date(DATE_RFC2822));
    $b = $x->addChild('body');
    $cats = [];
    foreach (feed_db()->query('SELECT * FROM sources ORDER BY category, sort, title')->fetchAll() as $s) {
        if (!isset($cats[$s['category']])) { $cats[$s['category']] = $b->addChild('outline'); $cats[$s['category']]->addAttribute('text', $s['category']); }
        $o = $cats[$s['category']]->addChild('outline');
        foreach (['type' => 'rss', 'text' => $s['title'], 'title' => $s['title'], 'xmlUrl' => $s['url'], 'htmlUrl' => $s['site_url']] as $k => $v) $o->addAttribute($k, $v);
    }
    return $x->asXML();
}

function feed_import_opml(string $xml): int {
    $prev = libxml_use_internal_errors(true);
    $o = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    libxml_use_internal_errors($prev);
    if (!$o || !isset($o->body)) throw new RuntimeException('Das ist keine OPML-Datei.');
    $ins = feed_db()->prepare('INSERT OR IGNORE INTO sources (title, url, site_url, category, created) VALUES (?, ?, ?, ?, ?)');
    $n = 0;
    $walk = function ($node, string $cat) use (&$walk, $ins, &$n) {
        foreach ($node->outline as $ol) {
            $url = (string)($ol['xmlUrl'] ?? '');
            if ($url !== '' && preg_match('#^https?://#i', $url)) { $ins->execute([mb_substr((string)($ol['title'] ?: $ol['text'] ?: $url), 0, 150), $url, (string)($ol['htmlUrl'] ?? ''), $cat, time()]); $n += $ins->rowCount(); }
            elseif (isset($ol->outline)) $walk($ol, mb_substr((string)($ol['text'] ?: $ol['title'] ?: $cat), 0, 60));
        }
    };
    $walk($o->body, 'Importiert');
    return $n;
}

function feed_cron_key(): string {
    $k = setting('feed_cron', '');
    if ($k === '') { $k = bin2hex(random_bytes(12)); set_setting('feed_cron', $k); }
    return $k;
}
