<?php
/**
 * Link-Prüfung für alle Hub-Seiten.
 * Wird von linkcheck.php (Weboberfläche) genutzt; die Ergebnisse landen in linkcheck_cache.json
 * und werden von den Hub-Seiten als kleine Warnhinweise angezeigt.
 */
require_once __DIR__ . '/../core/common.php';

const LINKCHECK_CACHE = DATA_DIR . '/cache/linkcheck_cache.json';

/** Diese Zeile kennzeichnet eine Hub-Datei (steht direkt nach dem \$categories-Array). */
const HUB_MARKER = "if (defined('LINKS_ONLY')) return \$categories;";

/** Alle Hub-Dateien finden (erkennbar an der LINKS_ONLY-Zeile). */
function find_hub_files(): array {
    $dir = APP_ROOT;
    $hubs = [];
    foreach (scandir($dir) as $file) {
        if (substr($file, -4) !== '.php' || $file[0] === '.') continue;
        if (strpos((string)file_get_contents("$dir/$file"), HUB_MARKER) !== false) {
            $hubs[] = $file;
        }
    }
    return $hubs;
}

/** Alle Links aller Hubs einsammeln: [url => ['title', 'hub', 'category']] */
function collect_hub_links(): array {
    if (!defined('LINKS_ONLY')) define('LINKS_ONLY', true);
    $dir = APP_ROOT;
    $links = [];
    foreach (find_hub_files() as $file) {
        $cats = (function () use ($dir, $file) { return include "$dir/$file"; })();
        if (!is_array($cats)) continue;
        foreach ($cats as $category => $items) {
            foreach ($items as $item) {
                $links[$item['url']]['title'] = $item['title'];
                $links[$item['url']]['category'] = $category;
                $links[$item['url']]['hubs'][] = $file;
                $links[$item['url']]['nsfw'] = !empty($links[$item['url']]['nsfw']) || !empty($item['nsfw'])
                    || (bool)preg_match('/\b(nsfw|adult)\b|18\+/i', $category);
            }
        }
    }
    // Eigene Seiten mit der Vorlage "Link-Hub"
    try {
        require_once __DIR__ . '/../core/pages.php';
        require_once __DIR__ . '/hub.php';
        foreach (site_db()->query("SELECT slug, title FROM pages WHERE kind = 'custom' AND template = 'links'") as $p) {
            foreach (hub_load('page_' . $p['slug'], []) as $category => $items) {
                foreach ($items as $item) {
                    $links[$item['url']]['title'] = $item['title'];
                    $links[$item['url']]['category'] = $category;
                    $links[$item['url']]['hubs'][] = $p['title'];
                    $links[$item['url']]['nsfw'] = !empty($links[$item['url']]['nsfw']) || !empty($item['nsfw']) || (bool)preg_match('/\b(nsfw|adult)\b|18\+/i', $category);
                }
            }
        }
    } catch (Throwable $e) { /* Datenbank (noch) nicht verfügbar */ }
    return $links;
}

/**
 * Status-Code einordnen.
 * ok      = erreichbar
 * blocked = Seite existiert, sperrt aber automatische Abrufe (Cloudflare, Login, Rate-Limit) -> im Browser meist ok
 * broken  = Seite fehlt (404/410) oder Serverfehler
 * dead    = Domain/Server gar nicht erreichbar (DNS, Timeout, SSL)
 */
function classify_status(int $code): string {
    if ($code >= 200 && $code < 400) return 'ok';
    if (in_array($code, [401, 403, 405, 406, 429, 451, 503], true)) return 'blocked';
    if ($code === 0) return 'dead';
    return 'broken';
}

/** Alle URLs parallel prüfen (max. $parallel gleichzeitig). */
function check_urls(array $urls, int $parallel = 16, int $timeout = 12): array {
    $results = [];
    $queue = array_values($urls);
    $mh = curl_multi_init();
    $active = [];

    $start = function (string $url) use ($mh, &$active, $timeout) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 6,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8', 'Accept-Language: de,en;q=0.8'],
            CURLOPT_ENCODING => '',
            // Nur die ersten ~64 KB laden – für den Status reicht das
            CURLOPT_RANGE => '0-65535',
        ]);
        curl_multi_add_handle($mh, $ch);
        $active[(int)$ch] = [$ch, $url, microtime(true)];
    };

    while ($queue && count($active) < $parallel) $start(array_shift($queue));

    while ($active) {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.5);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            [, $url, $t0] = $active[(int)$ch];
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $error = $info['result'] !== CURLE_OK ? curl_strerror($info['result']) : '';
            // 416 = Server mag den Range-Header nicht, Seite existiert aber
            if ($code === 416 || $code === 206) $code = 200;
            $results[$url] = [
                'code' => $code,
                'status' => classify_status($code),
                'final_url' => $final,
                'error' => $error,
                'ms' => (int)round((microtime(true) - $t0) * 1000),
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($active[(int)$ch]);
            if ($queue) $start(array_shift($queue));
        }
    }
    curl_multi_close($mh);
    return $results;
}

/** Hat die Weiterleitung auf eine andere Domain geführt? (Hinweis: Link evtl. aktualisieren) */
function moved_to_other_host(string $url, string $final): bool {
    // Nur die Hauptdomain vergleichen (de.jinxxy.com == jinxxy.com, www egal)
    $base = function (string $u): string {
        $parts = explode('.', strtolower((string)parse_url($u, PHP_URL_HOST)));
        return implode('.', array_slice($parts, -2));
    };
    return $final !== '' && $base($url) !== $base($final);
}

const LINKCHECK_JOB = DATA_DIR . '/cache/linkcheck_job.json';
const LINKCHECK_BATCH = 12;        // Links pro Anfrage (hält jede Anfrage deutlich unter üblichen 30-Sekunden-Limits)
const LINKCHECK_RETRY_BATCH = 4;   // zweiter Versuch: weniger auf einmal, mehr Geduld

/** Prüfen mit dem, was der Server kann: curl_multi (parallel), sonst curl einzeln, sonst get_headers. */
function check_urls_any(array $urls, int $parallel, int $timeout): array {
    if (function_exists('curl_multi_init')) return check_urls($urls, $parallel, $timeout);
    $results = [];
    foreach ($urls as $url) {
        $t0 = microtime(true);
        $code = 0; $final = $url; $error = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6, CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT => $timeout, CURLOPT_RANGE => '0-65535', CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36']);
            if (curl_exec($ch) === false) $error = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
        } elseif (ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 1,
                'header' => "User-Agent: Mozilla/5.0 (compatible; GhostNode-Linkcheck)\r\nRange: bytes=0-65535\r\n"]]);
            $headers = @get_headers($url, false, $ctx);
            if ($headers) { foreach ($headers as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1]; }
            else $error = 'nicht erreichbar';
        } else {
            $error = 'Server kann keine Webseiten abrufen (weder cURL noch allow_url_fopen)';
        }
        if ($code === 416 || $code === 206) $code = 200;
        $results[$url] = ['code' => $code, 'status' => classify_status($code), 'final_url' => $final, 'error' => $error, 'ms' => (int)round((microtime(true) - $t0) * 1000)];
    }
    return $results;
}

/** Zweiten Versuch übernehmen: "nicht erreichbar" nur dann, wenn es beim ersten Mal auch so war */
function linkcheck_merge_retry(array &$results, array $retry_results): void {
    foreach ($retry_results as $url => $r) {
        if ($r['status'] !== 'dead' || ($results[$url]['status'] ?? '') === 'dead') $results[$url] = $r;
    }
}

function linkcheck_finish(array $results): array {
    $report = ['checked_at' => time(), 'results' => $results];
    save_json(LINKCHECK_CACHE, $report);
    @unlink(LINKCHECK_JOB);
    $dead = count(array_filter($results, function ($r) { return $r['code'] === 0; }));
    return ['report' => $report, 'all_dead' => $results && $dead === count($results)];
}

/** Komplette Prüfung in einer Anfrage (Ausweichweg ohne JavaScript). */
function run_linkcheck(): array {
    $results = check_urls_any(array_keys(collect_hub_links()), 16, 10);
    $retry = array_keys(array_filter($results, function ($r) { return in_array($r['status'], ['dead', 'broken'], true); }));
    if ($retry) linkcheck_merge_retry($results, check_urls_any($retry, LINKCHECK_RETRY_BATCH, 15));
    return linkcheck_finish($results)['report'];
}

// ---------- Schrittweise Prüfung (Live-Fortschritt, robust gegen Zeitlimits des Hosters) ----------

function linkcheck_start(): array {
    $urls = array_keys(collect_hub_links());
    save_json(LINKCHECK_JOB, ['urls' => $urls, 'pos' => 0, 'retry' => null, 'rpos' => 0, 'results' => [], 'started' => time()]);
    return ['total' => count($urls)];
}

/** Nächstes Paket prüfen. Liefert Fortschritt; 'finished' => true, wenn alles fertig ist. */
function linkcheck_step(): array {
    $job = load_json(LINKCHECK_JOB, null);
    if (!$job) throw new RuntimeException('Keine laufende Prüfung gefunden – bitte neu starten.');
    @set_time_limit(60);
    $total = count($job['urls']);
    $current = '';

    if ($job['pos'] < $total) {
        // 1. Durchgang: alle Links, parallel, kurzes Zeitlimit
        $slice = array_slice($job['urls'], $job['pos'], LINKCHECK_BATCH);
        $job['results'] = check_urls_any($slice, LINKCHECK_BATCH, 10) + $job['results'];
        $job['pos'] += count($slice);
        $current = (string)parse_url(end($slice), PHP_URL_HOST);
    } else {
        // 2. Durchgang: Ausfälle noch einmal langsamer prüfen (vermeidet Fehlalarme)
        if ($job['retry'] === null) {
            $job['retry'] = array_keys(array_filter($job['results'], function ($r) { return in_array($r['status'], ['dead', 'broken'], true); }));
            // Kann der Server überhaupt nach außen? Wenn ALLES "nicht erreichbar" ist, ist der Zweitversuch sinnlos.
            if ($job['retry'] && count($job['retry']) === $total) $job['retry'] = [];
        }
        $slice = array_slice($job['retry'], $job['rpos'], LINKCHECK_RETRY_BATCH);
        if ($slice) {
            $tmp = $job['results'];
            linkcheck_merge_retry($tmp, check_urls_any($slice, LINKCHECK_RETRY_BATCH, 15));
            $job['results'] = $tmp;
            $job['rpos'] += count($slice);
            $current = (string)parse_url(end($slice), PHP_URL_HOST);
        }
    }

    $retry_total = is_array($job['retry']) ? count($job['retry']) : 0;
    $finished = $job['pos'] >= $total && is_array($job['retry']) && $job['rpos'] >= $retry_total;
    if ($finished) {
        $f = linkcheck_finish($job['results']);
        $bad = count(array_filter($job['results'], function ($r) { return in_array($r['status'], ['dead', 'broken'], true); }));
        return ['finished' => true, 'done' => $total, 'total' => $total, 'bad' => $bad, 'all_dead' => $f['all_dead']];
    }
    save_json(LINKCHECK_JOB, $job);
    return ['finished' => false, 'done' => $job['pos'], 'total' => $total, 'retry_done' => $job['rpos'], 'retry_total' => $retry_total,
            'phase' => $job['pos'] < $total ? 1 : 2, 'current' => $current];
}

function load_linkcheck(): array {
    return load_json(LINKCHECK_CACHE, ['checked_at' => 0, 'results' => []]);
}

/** Kleiner Warnhinweis für eine Hub-Karte (leer, wenn alles ok oder ungeprüft). */
function link_badge(string $url): string {
    static $results = null;
    if ($results === null) $results = load_linkcheck()['results'];
    $r = $results[$url] ?? null;
    if (!$r || $r['status'] === 'ok' || $r['status'] === 'blocked') return '';
    $label = $r['status'] === 'dead' ? 'nicht erreichbar' : 'Fehler ' . $r['code'];
    return '<span class="link-warn" title="Link-Check: ' . e($r['error'] ?: 'HTTP ' . $r['code']) . '">⚠ ' . e($label) . '</span>';
}
