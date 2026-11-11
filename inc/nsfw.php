<?php
/**
 * Bilder-APIs (SFW & NSFW): e621/e926, Danbooru, Gelbooru, Rule34, Yande.re, Konachan, waifu.im, waifu.pics.
 * Alles wird auf ein gemeinsames Format gebracht. Eine feste Sperrliste (Darstellungen Minderjähriger) wird
 * IMMER angewendet – in der Suche selbst (soweit die Seite es erlaubt) und zusätzlich beim Filtern der Ergebnisse.
 * Wird von nsfw.php (Galerie), discord.php (Slash-Befehle, Auto-Posts) genutzt.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/crypto.php';

final class NsfwError extends RuntimeException {}

/** Anbieter => [Name, Icon, Basis-URL, Format, kann NSFW, kann SFW, Hinweis, Seiten-URL für Beiträge] */
const NSFW_PROVIDERS = [
    'e621'      => ['e621', '🦊', 'https://e621.net', 'e621', true, true, 'Furry-Kunst – die größte Furry-Datenbank', 'https://e621.net/posts/'],
    'e926'      => ['e926 (jugendfrei)', '🐾', 'https://e926.net', 'e621', false, true, 'Wie e621, aber nur sichere Bilder', 'https://e926.net/posts/'],
    'danbooru'  => ['Danbooru', '🎨', 'https://danbooru.donmai.us', 'danbooru', true, true, 'Anime-Kunst; ohne Konto max. 2 Tags pro Suche', 'https://danbooru.donmai.us/posts/'],
    'gelbooru'  => ['Gelbooru', '📚', 'https://gelbooru.com', 'gelbooru', true, true, 'Anime-Kunst; braucht API-Schlüssel (Konto → Einstellungen)', 'https://gelbooru.com/index.php?page=post&s=view&id='],
    'rule34'    => ['Rule34', '🔞', 'https://api.rule34.xxx', 'gelbooru', true, false, 'Rule 34; braucht API-Schlüssel (Konto → Optionen)', 'https://rule34.xxx/index.php?page=post&s=view&id='],
    'yandere'   => ['Yande.re', '🌸', 'https://yande.re', 'moebooru', true, true, 'Hochauflösende Anime-Scans', 'https://yande.re/post/show/'],
    'konachan'  => ['Konachan', '🌌', 'https://konachan.com', 'moebooru', true, true, 'Anime-Wallpaper', 'https://konachan.com/post/show/'],
    'waifuim'   => ['waifu.im', '💮', 'https://api.waifu.im', 'waifuim', true, true, 'Zufallsbilder nach Kategorie', ''],
    'waifupics' => ['waifu.pics', '💕', 'https://api.waifu.pics', 'waifupics', true, true, 'Zufallsbilder & Reaktions-GIFs nach Kategorie', ''],
];

/** Kategorien der Zufalls-APIs (statt Tags) */
const NSFW_CATEGORIES = [
    'waifuim'   => ['sfw' => ['waifu', 'maid', 'uniform', 'selfies', 'marin-kitagawa', 'raiden-shogun', 'mori-calliope', 'oppai'],
                    'nsfw' => ['ero', 'hentai', 'ecchi', 'ass', 'oppai', 'milf', 'oral', 'paizuri']],
    'waifupics' => ['sfw' => ['waifu', 'neko', 'shinobu', 'megumin', 'hug', 'cuddle', 'pat', 'kiss', 'lick', 'bonk', 'blush', 'smile', 'wave', 'highfive', 'happy', 'wink', 'dance', 'awoo', 'smug', 'nom', 'poke', 'slap', 'cry', 'cringe'],
                    'nsfw' => ['waifu', 'neko', 'trap', 'blowjob']],
];

/**
 * Feste Sperrliste – nicht abschaltbar. Diese Tags kennzeichnen auf den Booru-Seiten Darstellungen Minderjähriger
 * bzw. kindlich wirkender Figuren. Beiträge mit einem davon werden nie angezeigt oder gepostet; Suchen danach sind gesperrt.
 */
const NSFW_FORCED_BLACKLIST = ['young', 'cub', 'loli', 'lolicon', 'shota', 'shotacon', 'child', 'children', 'kid', 'kids', 'toddler', 'baby', 'infant',
                               'underage', 'preteen', 'minor', 'female_child', 'male_child', 'aged_down', 'age_regression', 'young_(lore)', 'child_(lore)',
                               'student_(young)', 'elementary_school', 'middle_school', 'jailbait', 'cp'];

const NSFW_RATINGS = ['safe' => 'nur sicher (SFW)', 'nsfw' => 'nur NSFW (fragwürdig + explizit)', 'questionable' => 'fragwürdig', 'explicit' => 'explizit', 'all' => 'alles'];

// ---------- Einstellungen ----------

function nsfw_cfg(): array {
    $c = json_decode(setting('nsfw_cfg', '{}'), true);
    return (is_array($c) ? $c : []) + ['keys' => [], 'blacklist' => [], 'proxy' => false, 'default' => 'e621', 'blur' => true];
}

function nsfw_cfg_save(array $c): void {
    set_setting('nsfw_cfg', json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Zugangsdaten eines Anbieters (entschlüsselt): ['user' => …, 'key' => …] */
function nsfw_key(string $p): array {
    $k = nsfw_cfg()['keys'][$p] ?? null;
    if (!$k) return ['user' => '', 'key' => ''];
    $key = crypto_decrypt('nsfw', (string)($k['key'] ?? ''));
    return ['user' => (string)($k['user'] ?? ''), 'key' => is_string($key) ? $key : ''];
}

function nsfw_key_set(string $p, string $user, ?string $key): void {
    $c = nsfw_cfg();
    if ($user === '' && ($key === null || $key === '')) unset($c['keys'][$p]);
    else $c['keys'][$p] = ['user' => mb_substr($user, 0, 80), 'key' => $key !== null && $key !== '' ? crypto_encrypt('nsfw', $key) : ($c['keys'][$p]['key'] ?? '')];
    nsfw_cfg_save($c);
}

function nsfw_user_blacklist(): array {
    return array_values(array_filter(array_map('nsfw_norm_tag', (array)nsfw_cfg()['blacklist'])));
}

function nsfw_blacklist(): array {
    return array_values(array_unique(array_merge(NSFW_FORCED_BLACKLIST, nsfw_user_blacklist())));
}

function nsfw_norm_tag(string $t): string {
    return mb_strtolower(trim(str_replace(' ', '_', $t)));
}

function nsfw_provider(string $p): array {
    if (!isset(NSFW_PROVIDERS[$p])) throw new NsfwError('Unbekannte Bilder-Quelle.');
    [$name, $icon, $base, $fmt, $nsfw, $sfw, $hint, $page] = NSFW_PROVIDERS[$p];
    // Basis-URL lässt sich für Tests oder Spiegel-Server überschreiben
    $base = rtrim(setting('nsfw_base_' . $p, $base), '/');
    return compact('name', 'icon', 'base', 'fmt', 'nsfw', 'sfw', 'hint', 'page') + ['id' => $p];
}

/** Zugelassene Bild-Hosts (für den Bild-Proxy) */
function nsfw_host_allowed(string $host): bool {
    $host = strtolower($host);
    foreach (['e621.net', 'e926.net', 'donmai.us', 'gelbooru.com', 'rule34.xxx', 'yande.re', 'konachan.com', 'waifu.im', 'waifu.pics', 'catbox.moe'] as $h) {
        if ($host === $h || substr($host, -strlen($h) - 1) === '.' . $h) return true;
    }
    foreach (NSFW_PROVIDERS as $p => $_) {
        $b = parse_url(setting('nsfw_base_' . $p, ''), PHP_URL_HOST);
        if ($b && strtolower($b) === $host) return true;
    }
    return false;
}

// ---------- HTTP ----------

function nsfw_user_agent(): string {
    $u = nsfw_key('e621')['user'];
    return 'GhostNodeDashboard/1.0 (' . ($u !== '' ? 'by ' . $u . ' on e621' : 'private dashboard') . ')';
}

function nsfw_http(string $url, string $method = 'GET', ?array $json = null, array $headers = []) {
    if (!function_exists('curl_init')) throw new NsfwError('Auf dem Server fehlt die PHP-Erweiterung cURL.');
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json'], $headers);
    if ($json !== null) $h[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15,
                            CURLOPT_USERAGENT => nsfw_user_agent(), CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
    if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new NsfwError('Die Bilder-Seite ist nicht erreichbar (' . $err . ').');
    if ($code === 401 || $code === 403) throw new NsfwError('Zugriff verweigert (HTTP ' . $code . ') – API-Schlüssel in den Einstellungen prüfen.');
    if ($code === 429 || $code === 503) throw new NsfwError('Die Bilder-Seite bremst gerade (HTTP ' . $code . '). Bitte kurz warten.');
    if ($code === 422 && strpos((string)$raw, 'tag') !== false) throw new NsfwError('Zu viele Tags für diese Seite (Danbooru erlaubt ohne Konto nur 2).');
    if ($code >= 400) throw new NsfwError('Die Bilder-Seite meldet Fehler ' . $code . '.');
    $j = json_decode((string)$raw, true);
    if ($j === null && trim((string)$raw) !== '' && trim((string)$raw) !== 'null') {
        if (stripos((string)$raw, 'api key') !== false || stripos((string)$raw, 'authenticat') !== false || stripos((string)$raw, 'missing') !== false)
            throw new NsfwError('Diese Seite verlangt einen API-Schlüssel – bitte in den Einstellungen eintragen.');
        throw new NsfwError('Unerwartete Antwort der Bilder-Seite.');
    }
    return $j ?? [];
}

// ---------- Suche ----------

/** Tags aus Eingabe zerlegen und gegen die Sperrliste prüfen */
function nsfw_parse_tags(string $q): array {
    $tags = array_values(array_filter(array_map('nsfw_norm_tag', preg_split('/[\s,]+/u', $q) ?: []), 'strlen'));
    foreach ($tags as $t) {
        $bare = ltrim($t, '~');
        if ($bare === '' || $bare[0] === '-') continue;
        foreach (NSFW_FORCED_BLACKLIST as $b) {
            if ($bare === $b || strpos($bare, $b . '_') === 0 || substr($bare, -strlen($b) - 1) === '_' . $b) throw new NsfwError('Diese Suche ist gesperrt.');
        }
        if (preg_match('/^rating:/', $bare)) throw new NsfwError('Die Bewertung bitte über die Auswahl „Inhalt“ wählen.');
    }
    return array_slice($tags, 0, 12);
}

/** Beitrag gegen Sperrliste (fest + eigene) und gewünschte Bewertung prüfen */
function nsfw_allowed(array $post, string $rating): bool {
    $tags = array_flip($post['tags']);
    foreach (nsfw_blacklist() as $b) if (isset($tags[$b])) return false;
    foreach ($post['tags'] as $t) foreach (NSFW_FORCED_BLACKLIST as $b) if (strpos($t, $b . '_(') === 0) return false;
    $r = $post['rating'];
    switch ($rating) {
        case 'safe': return $r === 's';
        case 'nsfw': return $r === 'q' || $r === 'e';
        case 'questionable': return $r === 'q';
        case 'explicit': return $r === 'e';
    }
    return true;
}

/**
 * Suche bei einem Anbieter.
 * @param string $rating safe|nsfw|questionable|explicit|all
 * @param string $order new|score|favs|random
 * @return array ['posts' => [...], 'more' => bool, 'hidden' => int]
 */
function nsfw_search(string $p, string $q, string $rating = 'nsfw', string $order = 'new', int $page = 1, int $limit = 40): array {
    $P = nsfw_provider($p);
    if (!$P['nsfw'] && $rating !== 'safe') $rating = 'safe';
    if (!$P['sfw'] && $rating === 'safe') throw new NsfwError($P['name'] . ' hat keine jugendfreien Inhalte.');
    $limit = max(1, min(100, $limit));
    $page = max(1, min(500, $page));
    $tags = nsfw_parse_tags($q);
    $neg = array_map(function ($t) { return '-' . $t; }, nsfw_blacklist());
    $k = nsfw_key($p);
    $posts = [];
    switch ($P['fmt']) {
        case 'e621':
            $r = ['safe' => 'rating:s', 'nsfw' => '-rating:s', 'questionable' => 'rating:q', 'explicit' => 'rating:e'][$rating] ?? '';
            $o = ['score' => 'order:score', 'favs' => 'order:favcount', 'random' => 'order:random'][$order] ?? '';
            // e621 erlaubt 40 Tags – Sperrliste komplett mitschicken
            $all = array_merge($tags, array_filter([$r, $o]), array_slice($neg, 0, 40 - count($tags) - 2));
            $url = $P['base'] . '/posts.json?' . http_build_query(['tags' => implode(' ', $all), 'limit' => $limit, 'page' => $page]);
            $hdr = $k['user'] !== '' && $k['key'] !== '' ? ['Authorization: Basic ' . base64_encode($k['user'] . ':' . $k['key'])] : [];
            $j = nsfw_http($url, 'GET', null, $hdr);
            foreach ((array)($j['posts'] ?? []) as $x) {
                if (empty($x['file']['url'])) continue;
                $flat = [];
                foreach ((array)($x['tags'] ?? []) as $grp) $flat = array_merge($flat, (array)$grp);
                $posts[] = nsfw_post($p, (string)$x['id'], $x['file']['url'], $x['sample']['url'] ?? '', $x['preview']['url'] ?? '', (string)($x['file']['ext'] ?? ''),
                    (int)($x['file']['width'] ?? 0), (int)($x['file']['height'] ?? 0), (string)($x['rating'] ?? 'e'), (int)($x['score']['total'] ?? 0), (int)($x['fav_count'] ?? 0),
                    $flat, (array)($x['tags']['artist'] ?? []), (array)($x['sources'] ?? []), $P, (string)($x['created_at'] ?? ''));
            }
            break;
        case 'danbooru':
            $r = ['safe' => 'rating:g,s', 'nsfw' => 'rating:q,e', 'questionable' => 'rating:q', 'explicit' => 'rating:e'][$rating] ?? '';
            $o = ['score' => 'order:score', 'favs' => 'order:favcount', 'random' => 'order:random'][$order] ?? '';
            $max = $k['key'] !== '' ? 6 : 2;
            $all = $tags;
            foreach (array_filter([$r, $o]) as $extra) if (count($all) < $max) $all[] = $extra;
            if (count($all) > $max) throw new NsfwError('Danbooru erlaubt ohne Konto nur 2 Tags pro Suche.');
            $qs = ['tags' => implode(' ', $all), 'limit' => $limit, 'page' => $page];
            if ($k['user'] !== '' && $k['key'] !== '') $qs += ['login' => $k['user'], 'api_key' => $k['key']];
            $j = nsfw_http($P['base'] . '/posts.json?' . http_build_query($qs));
            foreach ((array)$j as $x) {
                if (empty($x['file_url'])) continue;
                $posts[] = nsfw_post($p, (string)$x['id'], $x['file_url'], $x['large_file_url'] ?? '', $x['preview_file_url'] ?? '', (string)($x['file_ext'] ?? ''),
                    (int)($x['image_width'] ?? 0), (int)($x['image_height'] ?? 0), ['g' => 's', 's' => 's'][$x['rating'] ?? ''] ?? (string)($x['rating'] ?? 'q'),
                    (int)($x['score'] ?? 0), (int)($x['fav_count'] ?? 0), explode(' ', (string)($x['tag_string'] ?? '')), array_filter(explode(' ', (string)($x['tag_string_artist'] ?? ''))),
                    array_filter([(string)($x['source'] ?? '')]), $P, (string)($x['created_at'] ?? ''));
            }
            break;
        case 'gelbooru':
            $gr = $p === 'rule34' ? ['safe' => 'rating:safe', 'questionable' => 'rating:questionable', 'explicit' => 'rating:explicit', 'nsfw' => '-rating:safe']
                                  : ['safe' => 'rating:general', 'questionable' => 'rating:questionable', 'explicit' => 'rating:explicit', 'nsfw' => '-rating:general'];
            $o = ['score' => 'sort:score', 'random' => 'sort:random'][$order] ?? '';
            $all = array_merge($tags, array_filter([$gr[$rating] ?? '', $o]), array_slice($neg, 0, 20));
            $qs = ['page' => 'dapi', 's' => 'post', 'q' => 'index', 'json' => 1, 'tags' => implode(' ', $all), 'limit' => $limit, 'pid' => $page - 1];
            if ($k['key'] !== '') $qs += ['api_key' => $k['key'], 'user_id' => $k['user']];
            $j = nsfw_http($P['base'] . '/index.php?' . http_build_query($qs));
            $list = isset($j['post']) ? (array)$j['post'] : (array)$j;
            if (isset($list['id'])) $list = [$list];
            foreach ($list as $x) {
                if (!is_array($x) || empty($x['file_url'])) continue;
                $rt = (string)($x['rating'] ?? 'e');
                $rt = in_array($rt, ['general', 'safe', 'sensitive', 's', 'g'], true) ? 's' : ($rt === 'questionable' || $rt === 'q' ? 'q' : 'e');
                $ext = strtolower(pathinfo(parse_url($x['file_url'], PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
                $posts[] = nsfw_post($p, (string)$x['id'], $x['file_url'], $x['sample_url'] ?? '', $x['preview_url'] ?? '', $ext, (int)($x['width'] ?? 0), (int)($x['height'] ?? 0),
                    $rt, (int)($x['score'] ?? 0), 0, explode(' ', trim((string)($x['tags'] ?? ''))), [], array_filter([(string)($x['source'] ?? '')]), $P, (string)($x['created_at'] ?? ''));
            }
            break;
        case 'moebooru':
            $r = ['safe' => 'rating:s', 'nsfw' => '-rating:s', 'questionable' => 'rating:q', 'explicit' => 'rating:e'][$rating] ?? '';
            $o = ['score' => 'order:score', 'favs' => 'order:score', 'random' => 'order:random'][$order] ?? '';
            $all = array_merge($tags, array_filter([$r, $o]), array_slice($neg, 0, max(0, 6 - count($tags) - 2)));
            $j = nsfw_http($P['base'] . '/post.json?' . http_build_query(['tags' => implode(' ', $all), 'limit' => $limit, 'page' => $page]));
            foreach ((array)$j as $x) {
                if (empty($x['file_url'])) continue;
                $posts[] = nsfw_post($p, (string)$x['id'], $x['file_url'], $x['sample_url'] ?? '', $x['preview_url'] ?? '', (string)($x['file_ext'] ?? ''),
                    (int)($x['width'] ?? 0), (int)($x['height'] ?? 0), (string)($x['rating'] ?? 'q'), (int)($x['score'] ?? 0), 0,
                    explode(' ', (string)($x['tags'] ?? '')), array_filter([(string)($x['author'] ?? '')]), array_filter([(string)($x['source'] ?? '')]), $P, isset($x['created_at']) ? date('c', (int)$x['created_at']) : '');
            }
            break;
        case 'waifuim':
            $nsfw = $rating !== 'safe';
            $cat = $tags[0] ?? ($nsfw ? 'ero' : 'waifu');
            if (!in_array($cat, NSFW_CATEGORIES['waifuim'][$nsfw ? 'nsfw' : 'sfw'], true)) throw new NsfwError('Kategorie gibt es bei waifu.im nicht. Möglich: ' . implode(', ', NSFW_CATEGORIES['waifuim'][$nsfw ? 'nsfw' : 'sfw']));
            $j = nsfw_http($P['base'] . '/search?' . http_build_query(['included_tags' => $cat, 'is_nsfw' => $nsfw ? 'true' : 'false', 'limit' => min(30, $limit)]), 'GET', null, ['Accept-Version: v5']);
            foreach ((array)($j['images'] ?? []) as $x) {
                if (empty($x['url'])) continue;
                $tg = array_map(function ($t) { return nsfw_norm_tag(is_array($t) ? (string)($t['name'] ?? '') : (string)$t); }, (array)($x['tags'] ?? []));
                $posts[] = nsfw_post($p, (string)($x['image_id'] ?? md5($x['url'])), $x['url'], $x['url'], $x['preview_url'] ?? $x['url'], ltrim((string)($x['extension'] ?? ''), '.'),
                    (int)($x['width'] ?? 0), (int)($x['height'] ?? 0), !empty($x['is_nsfw']) ? 'e' : 's', (int)($x['favorites'] ?? 0), (int)($x['favorites'] ?? 0), $tg,
                    array_filter([(string)($x['artist']['name'] ?? '')]), array_filter([(string)($x['source'] ?? '')]), $P, (string)($x['uploaded_at'] ?? ''));
            }
            break;
        case 'waifupics':
            $nsfw = $rating !== 'safe';
            $type = $nsfw ? 'nsfw' : 'sfw';
            $cat = $tags[0] ?? 'waifu';
            if (!in_array($cat, NSFW_CATEGORIES['waifupics'][$type], true)) throw new NsfwError('Kategorie gibt es bei waifu.pics nicht. Möglich: ' . implode(', ', NSFW_CATEGORIES['waifupics'][$type]));
            $j = nsfw_http($P['base'] . '/many/' . $type . '/' . $cat, 'POST', ['exclude' => []]);
            foreach (array_slice((array)($j['files'] ?? []), 0, $limit) as $u) {
                if (!is_string($u)) continue;
                $posts[] = nsfw_post($p, substr(md5($u), 0, 12), $u, $u, $u, strtolower(pathinfo(parse_url($u, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION)), 0, 0, $nsfw ? 'e' : 's', 0, 0, [$cat], [], [], $P, '');
            }
            break;
    }
    $before = count($posts);
    $posts = array_values(array_filter($posts, function ($x) use ($rating) { return nsfw_allowed($x, $rating); }));
    return ['posts' => $posts, 'more' => $before >= min($limit, 20) && !in_array($P['fmt'], ['waifuim', 'waifupics'], true), 'hidden' => $before - count($posts)];
}

function nsfw_post(string $p, string $id, string $url, string $sample, string $preview, string $ext, int $w, int $h, string $rating, int $score, int $favs,
                   array $tags, array $artists, array $sources, array $P, string $created): array {
    $ext = strtolower($ext ?: pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    $tags = array_values(array_unique(array_filter(array_map('nsfw_norm_tag', $tags), 'strlen')));
    return ['key' => $p . ':' . $id, 'provider' => $p, 'id' => $id, 'url' => $url, 'sample' => $sample ?: $url, 'preview' => $preview ?: ($sample ?: $url), 'ext' => $ext,
            'video' => in_array($ext, ['webm', 'mp4', 'mov'], true), 'w' => $w, 'h' => $h, 'rating' => in_array($rating, ['s', 'q', 'e'], true) ? $rating : 'e',
            'score' => $score, 'favs' => $favs, 'tags' => $tags, 'artists' => array_values(array_filter(array_map('nsfw_norm_tag', $artists), function ($a) { return $a !== '' && !in_array($a, ['unknown_artist', 'conditional_dnp', 'sound_warning'], true); })),
            'sources' => array_values(array_filter($sources, function ($s) { return preg_match('#^https?://#', $s); })), 'page' => $P['page'] !== '' ? $P['page'] . rawurlencode($id) : ($sources[0] ?? $url),
            'created' => $created];
}

/** Ein zufälliges passendes Bild (für Slash-Befehle und Auto-Posts) */
function nsfw_random(string $p, string $q, string $rating, array $exclude = []): ?array {
    $r = nsfw_search($p, $q, $rating, 'random', 1, 30);
    $list = array_values(array_filter($r['posts'], function ($x) use ($exclude) { return !in_array($x['key'], $exclude, true); }));
    if (!$list) $list = $r['posts'];
    return $list ? $list[array_rand($list)] : null;
}

/** Tag-Vorschläge (e621/e926/Danbooru) */
function nsfw_autocomplete(string $p, string $term): array {
    $P = nsfw_provider($p);
    $neg = $term !== '' && $term[0] === '-';
    $term = nsfw_norm_tag(ltrim($term, '-~'));
    if (mb_strlen($term) < 2) return [];
    $out = [];
    if ($P['fmt'] === 'e621') {
        $j = nsfw_http($P['base'] . '/tags/autocomplete.json?' . http_build_query(['search[name_matches]' => $term, 'expiry' => 7]));
        foreach ((array)$j as $t) if (isset($t['name'])) $out[] = ['tag' => $t['name'], 'count' => (int)($t['post_count'] ?? 0), 'cat' => (int)($t['category'] ?? 0)];
    } elseif ($P['fmt'] === 'danbooru') {
        $j = nsfw_http($P['base'] . '/autocomplete.json?' . http_build_query(['search[query]' => $term, 'search[type]' => 'tag_query', 'limit' => 12]));
        foreach ((array)$j as $t) if (isset($t['value'])) $out[] = ['tag' => $t['value'], 'count' => (int)($t['post_count'] ?? 0), 'cat' => (int)($t['category'] ?? 0)];
    } elseif ($P['fmt'] === 'waifuim' || $P['fmt'] === 'waifupics') {
        foreach (array_unique(array_merge(...array_values(NSFW_CATEGORIES[$p]))) as $c) if (strpos($c, $term) === 0) $out[] = ['tag' => $c, 'count' => 0, 'cat' => 0];
    }
    $bl = nsfw_blacklist();
    return array_slice(array_values(array_filter(array_map(function ($t) use ($neg) { $t['tag'] = ($neg ? '-' : '') . $t['tag']; return $t; }, $out), function ($t) use ($bl, $neg) {
        $bare = ltrim($t['tag'], '-');
        if ($neg) return true;
        foreach (NSFW_FORCED_BLACKLIST as $b) if ($bare === $b || strpos($bare, $b . '_') === 0) return false;
        return !in_array($bare, $bl, true);
    })), 0, 12);
}

// ---------- Bild-Proxy (signiert, nur bekannte Hosts) ----------

function nsfw_proxy_secret(): string {
    $s = setting('nsfw_proxy_secret', '');
    if ($s === '') { $s = bin2hex(random_bytes(16)); set_setting('nsfw_proxy_secret', $s); }
    return $s;
}

/** Signierte Proxy-Adresse (Bild über den eigenen Server laden) */
function nsfw_proxy_url(string $url): string {
    if (!preg_match('#^https?://#', $url)) return $url;
    return 'nsfw.php?img=' . rawurlencode($url) . '&s=' . substr(hash_hmac('sha256', $url, nsfw_proxy_secret()), 0, 24);
}

function nsfw_img(string $url): string {
    return nsfw_cfg()['proxy'] ? nsfw_proxy_url($url) : $url;
}

/**
 * Bilder-URLs für die Anzeige: v_* = wie eingestellt (direkt oder über den Server), x_* = immer über den Server.
 * Der Browser weicht bei Ladefehlern automatisch auf x_* aus (z. B. wenn ein Werbeblocker, DNS-Filter oder
 * Hotlink-Schutz die Bilder-Seite blockiert).
 */
function nsfw_view(array $post): array {
    foreach (['preview', 'sample', 'url'] as $k) {
        $post['v_' . $k] = nsfw_img($post[$k]);
        $post['x_' . $k] = nsfw_proxy_url($post[$k]);
    }
    return $post;
}

/** Bild vom Server aus holen: [Daten, MIME, HTTP-Code, Fehlertext] */
function nsfw_fetch_image(string $url, int $max = 60 * 1024 * 1024): array {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $referer = '';
    foreach (NSFW_PROVIDERS as $p) {
        $h = strtolower((string)parse_url($p[2], PHP_URL_HOST));
        $root = implode('.', array_slice(explode('.', $h), -2));
        if ($host === $h || substr($host, -strlen($root) - 1) === '.' . $root) { $referer = ($p[7] !== '' ? preg_replace('#^(https://[^/]+/).*$#', '$1', $p[7]) : $p[2] . '/'); break; }
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 40, CURLOPT_USERAGENT => nsfw_user_agent(), CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_MAXREDIRS => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => $max,
                            CURLOPT_HTTPHEADER => array_filter(['Accept: image/avif,image/webp,image/*,video/*;q=0.9,*/*;q=0.5', $referer !== '' ? 'Referer: ' . $referer : ''])]);
    $data = curl_exec($ch);
    $type = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($data === false) return ['', '', 0, 'nicht erreichbar (' . $err . ')'];
    if ($code >= 400) {
        $cf = stripos((string)$data, 'challenge') !== false || stripos((string)$data, 'cloudflare') !== false;
        return ['', '', $code, 'HTTP ' . $code . ($cf ? ' – Cloudflare-Schutz der Bilder-Seite blockiert deinen Server' : '')];
    }
    if (!preg_match('#^(image|video)/[\w.+-]+#', $type, $m)) {
        // manche CDNs liefern application/octet-stream – dann am Inhalt erkennen
        $sniff = class_exists('finfo') ? (string)(new finfo(FILEINFO_MIME_TYPE))->buffer(substr((string)$data, 0, 4096)) : '';
        if (!preg_match('#^(image|video)/[\w.+-]+#', $sniff, $m)) return ['', '', $code, 'keine Bilddatei (' . ($type ?: 'unbekannt') . ')'];
    }
    return [(string)$data, $m[0], $code, ''];
}

function nsfw_proxy(string $url, string $sig): void {
    $host = (string)parse_url($url, PHP_URL_HOST);
    if (!hash_equals(substr(hash_hmac('sha256', $url, nsfw_proxy_secret()), 0, 24), $sig) || !nsfw_host_allowed($host)) { http_response_code(403); exit; }
    [$data, $mime, $code, $err] = nsfw_fetch_image($url);
    if ($err !== '') {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Nsfw-Proxy: ' . rawurlencode($err));
        exit($err);
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; sandbox');
    echo $data;
    exit;
}

// ---------- Favoriten & gespeicherte Suchen (pro Benutzer) ----------

function nsfw_uid(): int {
    return (int)(current_user()['id'] ?? 0);
}

function nsfw_favs(int $uid): array {
    $l = json_decode(setting('nsfw_favs_' . $uid, '[]'), true);
    return is_array($l) ? $l : [];
}

function nsfw_favs_save(int $uid, array $l): void {
    set_setting('nsfw_favs_' . $uid, json_encode(array_values(array_slice($l, 0, 2000)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Beitrag für Favoriten/Posts auf das Nötigste kürzen */
function nsfw_slim(array $p): array {
    $keep = ['key', 'provider', 'id', 'url', 'sample', 'preview', 'ext', 'video', 'w', 'h', 'rating', 'score', 'tags', 'artists', 'sources', 'page'];
    $o = array_intersect_key($p, array_flip($keep));
    $o['tags'] = array_slice((array)($o['tags'] ?? []), 0, 80);
    return $o;
}

/** Beitrag aus dem Browser (JSON) prüfen – URLs nur https/http, Bewertung gültig, nicht gesperrt */
function nsfw_post_from_input(string $json): array {
    $p = json_decode($json, true);
    if (!is_array($p) || !isset(NSFW_PROVIDERS[$p['provider'] ?? '']) || !preg_match('#^https?://#', (string)($p['url'] ?? ''))) throw new NsfwError('Ungültiger Beitrag.');
    foreach (['sample', 'preview', 'page'] as $k) if (!preg_match('#^https?://#', (string)($p[$k] ?? ''))) $p[$k] = $p['url'];
    $p['tags'] = array_values(array_filter(array_map(function ($t) { return nsfw_norm_tag((string)$t); }, (array)($p['tags'] ?? []))));
    $p['artists'] = array_values(array_map('strval', (array)($p['artists'] ?? [])));
    $p['sources'] = array_values(array_filter((array)($p['sources'] ?? []), function ($s) { return is_string($s) && preg_match('#^https?://#', $s); }));
    $p['rating'] = in_array($p['rating'] ?? '', ['s', 'q', 'e'], true) ? $p['rating'] : 'e';
    $p['id'] = mb_substr((string)($p['id'] ?? ''), 0, 40);
    $p['key'] = $p['provider'] . ':' . $p['id'];
    $p['video'] = !empty($p['video']);
    if (!nsfw_allowed($p, 'all')) throw new NsfwError('Dieser Beitrag ist gesperrt.');
    return nsfw_slim($p);
}

function nsfw_saved(int $uid): array {
    $l = json_decode(setting('nsfw_saved_' . $uid, '[]'), true);
    return is_array($l) ? $l : [];
}

function nsfw_saved_save(int $uid, array $l): void {
    set_setting('nsfw_saved_' . $uid, json_encode(array_values(array_slice($l, 0, 100)), JSON_UNESCAPED_UNICODE));
}

// ---------- Discord ----------

const NSFW_RATING_LABEL = ['s' => 'Sicher', 'q' => 'Fragwürdig', 'e' => 'Explizit'];

/** Discord-Nachricht (Embed) für einen Beitrag */
function nsfw_discord_payload(array $p, string $note = '', bool $more_button = false, string $more_id = ''): array {
    $P = nsfw_provider($p['provider']);
    $artists = $p['artists'] ? implode(', ', array_slice($p['artists'], 0, 3)) : '';
    $e = ['title' => $P['icon'] . ' ' . $P['name'] . ' #' . $p['id'], 'url' => $p['page'], 'color' => ['s' => 0x3ba55d, 'q' => 0xf3c262, 'e' => 0xed4245][$p['rating']] ?? 0x5865f2,
          'footer' => ['text' => NSFW_RATING_LABEL[$p['rating']] . ($p['score'] ? ' · ⬆ ' . $p['score'] : '') . ($artists !== '' ? ' · 🎨 ' . $artists : '')]];
    if ($note !== '') $e['description'] = mb_substr($note, 0, 1000);
    if (!$p['video']) $e['image'] = ['url' => $p['sample'] ?: $p['url']];
    $payload = ['embeds' => [$e], 'allowed_mentions' => ['parse' => []]];
    if ($p['video']) $payload['content'] = $p['url'];   // Discord bettet Videos nur über die Adresse ein
    $buttons = [['type' => 2, 'style' => 5, 'label' => 'Quelle', 'emoji' => ['name' => '🔗'], 'url' => $p['page']]];
    if (!empty($p['sources'][0]) && $p['sources'][0] !== $p['page']) $buttons[] = ['type' => 2, 'style' => 5, 'label' => 'Original', 'url' => mb_substr($p['sources'][0], 0, 512)];
    if ($more_button && $more_id !== '') $buttons[] = ['type' => 2, 'style' => 1, 'label' => 'Noch eins', 'emoji' => ['name' => '🔁'], 'custom_id' => mb_substr($more_id, 0, 100)];
    $payload['components'] = [['type' => 1, 'components' => $buttons]];
    return $payload;
}
