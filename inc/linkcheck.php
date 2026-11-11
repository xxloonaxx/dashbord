<?php
/**
 * Link-Prüfung für alle Hub-Seiten.
 * Wird von linkcheck.php (Weboberfläche) genutzt; die Ergebnisse landen in linkcheck_cache.json
 * und werden von den Hub-Seiten als kleine Warnhinweise angezeigt.
 */
require_once __DIR__ . '/common.php';

const LINKCHECK_CACHE = __DIR__ . '/../linkcheck_cache.json';

/** Diese Zeile kennzeichnet eine Hub-Datei (steht direkt nach dem \$categories-Array). */
const HUB_MARKER = "if (defined('LINKS_ONLY')) return \$categories;";

/** Alle Hub-Dateien finden (erkennbar an der LINKS_ONLY-Zeile). */
function find_hub_files(): array {
    $dir = dirname(__DIR__);
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
    $dir = dirname(__DIR__);
    $links = [];
    foreach (find_hub_files() as $file) {
        $cats = (function () use ($dir, $file) { return include "$dir/$file"; })();
        if (!is_array($cats)) continue;
        foreach ($cats as $category => $items) {
            foreach ($items as $item) {
                $links[$item['url']]['title'] = $item['title'];
                $links[$item['url']]['category'] = $category;
                $links[$item['url']]['hubs'][] = $file;
            }
        }
    }
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

/** Komplette Prüfung ausführen und Ergebnis speichern. */
function run_linkcheck(): array {
    $links = collect_hub_links();
    $results = check_urls(array_keys($links));

    // Zweiter Versuch für Ausfälle – langsamer und mit mehr Geduld, um Fehlalarme (kurze Timeouts) zu vermeiden
    $retry = array_keys(array_filter($results, function ($r) { return in_array($r['status'], ['dead', 'broken'], true); }));
    if ($retry) {
        foreach (check_urls($retry, 4, 25) as $url => $r) {
            if ($r['status'] !== 'dead' || $results[$url]['status'] === 'dead') $results[$url] = $r;
        }
    }
    $report = ['checked_at' => time(), 'results' => $results];
    save_json(LINKCHECK_CACHE, $report);
    return $report;
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
