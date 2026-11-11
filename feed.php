<?php
require_once __DIR__ . '/inc/common.php';
require_once __DIR__ . '/inc/countdown.php';

// Caching-Einstellungen (Cache-Datei & Gültigkeit in Sekunden -> 15 Min = 900 Sek)
$cache_file = __DIR__ . '/feed_cache.json';
$cache_lifetime = 900;
$max_items = 80;

// Countdown-Banner: zeigt den Haupt-Countdown von countdown.php (dort verwalten)
$featured = featured_countdown();
$countdown_name = $featured ? $featured['icon'] . ' ' . $featured['title'] : 'Eurofurence';
$countdown_ts = $featured ? $featured['ts'] : strtotime('2027-08-25 14:00:00');

// RSS-Feed-Quellen (inklusive deiner angegebenen Bluesky-Profile & weiteren Feeds)
$feed_sources = [
    "Furry & Bluesky" => [
        ["name" => "Bluesky Eurofurence Feed", "url" => "https://bsky.app/profile/did:plc:uneztaz2bvfqtzyyopyzi3em/rss"],
        ["name" => "Bluesky Furry Feed", "url" => "https://bsky.app/profile/did:plc:swluodtgntgcwia4s5xrf7qr/rss"],
        ["name" => "Bluesky Furry Art Feed", "url" => "https://bsky.app/profile/did:plc:z72i7hdynmk6bbwg2jsc3qx5/rss"],
        ["name" => "WikiFur (Recent Changes)", "url" => "https://en.wikifur.com/w/api.php?action=feedrecentchanges&feedformat=rss"]
    ],
    "Tech & Linux" => [
        ["name" => "Golem.de", "url" => "https://rss.golem.de/rss.php?feed=RSS2.0"],
        ["name" => "Heise Online", "url" => "https://www.heise.de/rss/heise-atom.xml"],
        ["name" => "Phoronix", "url" => "https://www.phoronix.com/phoronix-rss.xml"],
        ["name" => "Hacker News", "url" => "https://news.ycombinator.com/rss"]
    ],
    "Gaming & Modding" => [
        ["name" => "Modrinth News", "url" => "https://modrinth.com/feed"]
    ]
];

/**
 * Alle Feeds gleichzeitig herunterladen (curl_multi).
 * Vorher wurden die Feeds nacheinander geladen – bei 9 Quellen konnte ein Seitenaufruf
 * so bis zu ~30 Sekunden hängen. Jetzt dauert es höchstens so lange wie der langsamste Feed.
 */
function download_all(array $urls): array {
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) GHOST-Node/3.1';
    $results = [];

    if (function_exists('curl_multi_init')) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $key => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_USERAGENT => $ua,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_ENCODING => '',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $key => $ch) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = curl_multi_getcontent($ch);
            $results[$key] = ($code >= 200 && $code < 400) ? $body : false;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $results;
    }

    // Fallback ohne cURL
    $context = stream_context_create(['http' => ['method' => 'GET', 'header' => "User-Agent: $ua\r\n", 'timeout' => 4]]);
    foreach ($urls as $key => $url) {
        $results[$key] = @file_get_contents($url, false, $context);
    }
    return $results;
}

/** Beschreibung säubern: HTML entfernen, Entities dekodieren, Leerraum glätten, kürzen. */
function clean_text(string $html, int $max = 300): string {
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max)) . '…' : $text;
}

// Funktion zum Abrufen der Feeds aus dem Netz
function fetch_fresh_feed_items(array $sources, int $max_items): array {
    $flat = [];
    foreach ($sources as $category => $feed_list) {
        foreach ($feed_list as $source) {
            $flat[] = $source + ['category' => $category];
        }
    }
    $contents = download_all(array_column($flat, 'url'));

    $items = [];
    foreach ($flat as $i => $source) {
        $content = $contents[$i] ?? false;
        if (!$content) continue;

        $xml = @simplexml_load_string($content);
        if (!$xml) continue;

        // RSS 2.0 Parser (wird u. a. von Bluesky genutzt)
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $entry) {
                $pubDate = strtotime((string)$entry->pubDate);
                $desc = clean_text((string)$entry->description);
                $title = clean_text((string)$entry->title, 200);
                // Bluesky-Posts haben keinen Titel -> Textanfang als Titel verwenden
                if ($title === '') $title = $desc !== '' ? clean_text($desc, 120) : '(ohne Titel)';
                $items[] = [
                    'title' => $title,
                    'link' => safe_url((string)$entry->link),
                    'description' => $desc === $title ? '' : $desc,
                    'date' => $pubDate ?: time(),
                    'source' => $source['name'],
                    'category' => $source['category']
                ];
            }
        }
        // Atom Parser
        elseif (isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                $link = '';
                foreach ($entry->link as $l) {
                    if ((string)$l['rel'] === 'alternate' || empty($l['rel'])) {
                        $link = (string)$l['href'];
                        break;
                    }
                }
                if ($link === '' && isset($entry->link)) $link = (string)$entry->link['href'];

                $pubDate = strtotime((string)$entry->updated) ?: strtotime((string)$entry->published);
                $summary = (string)$entry->summary !== '' ? (string)$entry->summary : (string)$entry->content;

                $items[] = [
                    'title' => clean_text((string)$entry->title, 200) ?: '(ohne Titel)',
                    'link' => safe_url($link),
                    'description' => clean_text($summary),
                    'date' => $pubDate ?: time(),
                    'source' => $source['name'],
                    'category' => $source['category']
                ];
            }
        }
    }

    // Doppelte Links entfernen
    $unique = [];
    foreach ($items as $item) {
        $key = $item['link'] !== '#' ? $item['link'] : md5($item['title'] . $item['source']);
        if (!isset($unique[$key])) $unique[$key] = $item;
    }
    $items = array_values($unique);

    // Nach Datum sortieren (neueste zuerst)
    usort($items, function ($a, $b) {
        return $b['date'] <=> $a['date'];
    });

    return array_slice($items, 0, $max_items);
}

// Caching-Logik: Falls Cache existiert und noch frisch ist, aus Datei laden.
// "?refresh=1" erzwingt ein Neuladen (max. einmal pro Minute, damit niemand den Server dauerhaft beschäftigt).
$cache_age = is_file($cache_file) ? time() - filemtime($cache_file) : PHP_INT_MAX;
$force_refresh = isset($_GET['refresh']) && $cache_age > 60;
$fetch_failed = false;

if (!$force_refresh && $cache_age < $cache_lifetime) {
    $feed_items = load_json($cache_file, []);
} else {
    $feed_items = fetch_fresh_feed_items($feed_sources, $max_items);
    if ($feed_items) {
        save_json($cache_file, $feed_items);
        $cache_age = 0;
    } else {
        // Netzwerkproblem: lieber alte Meldungen zeigen als eine leere Seite
        $feed_items = load_json($cache_file, []);
        $fetch_failed = true;
        if (is_file($cache_file)) touch($cache_file); // nicht bei jedem Aufruf erneut versuchen
    }
    if ($force_refresh) {
        header('Location: feed.php');
        exit;
    }
}

// Links aus älteren Cache-Dateien ebenfalls absichern
foreach ($feed_items as &$item) {
    $item['link'] = safe_url($item['link'] ?? '');
}
unset($item);

$categories = array_keys($feed_sources);
$category_counts = array_count_values(array_column($feed_items, 'category'));
$cache_time = is_file($cache_file) ? date('H:i', filemtime($cache_file)) : '--:--';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FEED NODE // LIVE STREAM & EF COUNTDOWN</title>
    <?php echo emoji_favicon('📡'); ?>
    <style>
        :root {
            --bg-color: #06090e;
            --panel-bg: rgba(14, 20, 27, 0.85);
            --panel-hover: rgba(22, 33, 46, 0.95);
            --accent-gold: #f3c262;
            --accent-cyan: #38d5e6;
            --accent-pink: #ff79c6;
            --text-main: #e2e8f0;
            --text-muted: #64748b;
            --border-color: rgba(56, 213, 230, 0.25);
        }

        * { box-sizing: border-box; }

        body {
            font-family: "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(circle at 50% 10%, rgba(56, 213, 230, 0.08), transparent 60%),
                linear-gradient(rgba(6, 9, 14, 0.9), rgba(6, 9, 14, 0.98));
            background-attachment: fixed;
            color: var(--text-main);
            margin: 0;
            padding: 40px 20px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .wrapper { width: 100%; max-width: 950px; }

        .nav-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--accent-cyan);
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 25px;
            padding: 6px 14px;
            background: rgba(56, 213, 230, 0.08);
            border: 1px solid var(--border-color);
            clip-path: polygon(0 0, calc(100% - 8px) 0, 100% 8px, 100% 100%, 0 100%);
            transition: all 0.2s ease;
        }

        .nav-back:hover {
            background: rgba(56, 213, 230, 0.2);
            transform: translateX(-3px);
        }

        a.ef-banner { text-decoration: none; color: inherit; }
        a.ef-banner:hover { filter: brightness(1.25); }

        .ef-banner {
            background: rgba(255, 121, 198, 0.1);
            border: 1px solid rgba(255, 121, 198, 0.4);
            border-left: 4px solid var(--accent-pink);
            padding: 12px 18px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 10px, 100% 100%, 0 100%);
            font-size: 0.85rem;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .ef-title {
            color: var(--accent-pink);
            font-weight: 800;
        }

        .ef-timer {
            color: #ffffff;
            font-weight: 700;
            font-family: monospace;
            font-size: 1rem;
        }

        header {
            margin-bottom: 25px;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 20px;
        }

        .hud-tag {
            font-size: 0.7rem;
            letter-spacing: 3px;
            color: var(--accent-cyan);
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 6px;
        }

        h1 {
            font-size: 2.2rem;
            font-weight: 800;
            letter-spacing: 3px;
            margin: 0 0 15px 0;
            text-transform: uppercase;
            color: var(--accent-gold);
            text-shadow: 0 0 12px rgba(243, 194, 98, 0.3);
        }

        .controls {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 15px;
        }

        .search-box {
            width: 100%;
            padding: 12px 18px;
            background: rgba(10, 15, 22, 0.8);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            font-size: 0.95rem;
            outline: none;
            clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 10px, 100% 100%, 0 100%);
            transition: border-color 0.2s;
        }

        .search-box:focus {
            border-color: var(--accent-cyan);
            box-shadow: 0 0 10px rgba(56, 213, 230, 0.2);
        }

        .filter-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-btn {
            background: rgba(14, 20, 27, 0.8);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            padding: 6px 14px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.2s;
            clip-path: polygon(0 0, calc(100% - 6px) 0, 100% 6px, 100% 100%, 0 100%);
        }

        .filter-btn:hover, .filter-btn.active {
            background: rgba(56, 213, 230, 0.15);
            border-color: var(--accent-cyan);
            color: var(--accent-cyan);
        }

        .feed-container {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .feed-card {
            background: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--accent-cyan);
            padding: 16px 20px;
            text-decoration: none;
            color: var(--text-main);
            transition: all 0.2s ease;
            clip-path: polygon(0 0, calc(100% - 12px) 0, 100% 12px, 100% 100%, 0 100%);
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .feed-card:hover {
            background: var(--panel-hover);
            border-left-color: var(--accent-gold);
            transform: translateX(4px);
            box-shadow: 0 0 15px rgba(56, 213, 230, 0.15);
        }

        .feed-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.7rem;
            color: var(--text-muted);
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .source-tag {
            color: var(--accent-cyan);
            font-weight: 700;
        }

        .feed-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #ffffff;
            margin: 0;
            line-height: 1.35;
        }

        .feed-desc {
            font-size: 0.82rem;
            color: var(--text-muted);
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .empty-state {
            text-align: center;
            color: var(--text-muted);
            padding: 40px;
            border: 1px dashed var(--border-color);
            background: var(--panel-bg);
            letter-spacing: 2px;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        .feed-status {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            font-size: 0.7rem;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin: 8px 0 14px;
        }

        .refresh-btn {
            color: var(--accent-cyan);
            text-decoration: none;
            border: 1px solid rgba(56, 213, 230, 0.3);
            padding: 4px 10px;
            transition: all 0.2s ease;
        }

        .refresh-btn:hover { background: rgba(56, 213, 230, 0.15); color: #fff; }

        .empty-state[hidden] { display: none; }

        :focus-visible { outline: 2px solid var(--accent-cyan); outline-offset: 2px; }

        @media (max-width: 640px) {
            body { padding: 20px 14px; }
            h1 { font-size: 1.6rem; }
            .ef-banner { flex-direction: column; align-items: flex-start; gap: 6px; }
        }
    </style>
</head>
<body>

    <div class="wrapper">
        <a href="index.php" class="nav-back">&larr; ZURÜCK ZUM INDEX</a>

        <!-- Eurofurence Countdown Banner -->
        <a href="countdown.php" class="ef-banner" title="Countdowns verwalten">
            <span class="ef-title">// <?php echo e($countdown_name); ?> Countdown</span>
            <span id="efTimer" class="ef-timer">Berechne...</span>
        </a>

        <header>
            <div class="hud-tag">System // Aggregated Data Stream</div>
            <h1>Live Feed Node</h1>
            <div class="feed-status">
                <span><?php echo count($feed_items); ?> Meldungen &bull; Stand <?php echo e($cache_time); ?> Uhr<?php if ($fetch_failed): ?> &bull; <span style="color: var(--accent-red);">Aktualisierung fehlgeschlagen – zeige Cache</span><?php endif; ?></span>
                <a href="feed.php?refresh=1" class="refresh-btn" title="Feeds jetzt neu laden">&#x21bb; Aktualisieren</a>
            </div>
            
            <div class="controls">
                <input type="search" id="searchInput" class="search-box" placeholder="Streams nach Stichworten durchsuchen..." autocomplete="off" aria-label="Feeds durchsuchen">
                
                <div class="filter-buttons">
                    <button type="button" class="filter-btn active" data-category="all">Alle Streams (<?php echo count($feed_items); ?>)</button>
                    <?php foreach ($categories as $cat): ?>
                        <button type="button" class="filter-btn" data-category="<?php echo e($cat); ?>"><?php echo e($cat); ?> (<?php echo (int)($category_counts[$cat] ?? 0); ?>)</button>
                    <?php endforeach; ?>
                </div>
            </div>
        </header>

        <div class="feed-container" id="feedContainer">
            <?php if (empty($feed_items)): ?>
                <div class="empty-state">Keine aktiven Datenströme erreichbar. Server-Netzwerkverbindung prüfen.</div>
            <?php else: ?>
                <?php foreach ($feed_items as $item): ?>
                    <a href="<?php echo e($item['link']); ?>" target="_blank" rel="noopener noreferrer" class="feed-card" data-category="<?php echo e($item['category']); ?>">
                        <div class="feed-meta">
                            <span class="source-tag"><?php echo htmlspecialchars($item['source']); ?></span>
                            <span>// <?php echo htmlspecialchars($item['category']); ?></span>
                            <span>&bull; <time class="rel-time" datetime="<?php echo date('c', (int)$item['date']); ?>" title="<?php echo date('d.m.Y - H:i', (int)$item['date']); ?> Uhr"><?php echo date('d.m.Y - H:i', (int)$item['date']); ?> Uhr</time></span>
                        </div>
                        <h2 class="feed-title"><?php echo htmlspecialchars($item['title']); ?></h2>
                        <?php if (!empty($item['description'])): ?>
                            <div class="feed-desc"><?php echo htmlspecialchars($item['description']); ?></div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="empty-state" id="noResults" hidden>Keine passenden Meldungen gefunden.</div>
        </div>
    </div>

    <script>
        // Eurofurence Ziel-Datum (Nächster August-Termin)
        const efDate = new Date(<?php echo json_encode(date('c', $countdown_ts)); ?>).getTime();

        function updateCountdown() {
            const now = new Date().getTime();
            const distance = efDate - now;

            if (distance < 0) {
                document.getElementById("efTimer").textContent = <?php echo json_encode($countdown_name . ' läuft / beendet!', JSON_UNESCAPED_UNICODE); ?>;
                return;
            }

            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);

            document.getElementById("efTimer").textContent = `${days}T ${hours}h ${minutes}m ${seconds}s`;
        }

        setInterval(updateCountdown, 1000);
        updateCountdown();

        // Kategorien-Filter & Live-Suche
        let currentCategory = 'all';

        // Relative Zeitangaben ("vor 5 Min.")
        function updateRelativeTimes() {
            const now = Date.now();
            document.querySelectorAll('.rel-time').forEach(el => {
                const diff = Math.round((now - new Date(el.getAttribute('datetime')).getTime()) / 60000);
                let txt;
                if (diff < 1) txt = 'gerade eben';
                else if (diff < 60) txt = 'vor ' + diff + ' Min.';
                else if (diff < 1440) txt = 'vor ' + Math.round(diff / 60) + ' Std.';
                else if (diff < 10080) txt = 'vor ' + Math.round(diff / 1440) + ' Tag' + (Math.round(diff / 1440) === 1 ? '' : 'en');
                else return;
                el.textContent = txt;
            });
        }
        updateRelativeTimes();
        setInterval(updateRelativeTimes, 60000);

        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', () => setCategory(btn.dataset.category, btn));
        });
        const searchInput = document.getElementById('searchInput');
        searchInput.addEventListener('input', filterFeeds);
        searchInput.addEventListener('keydown', e => { if (e.key === 'Escape') { searchInput.value = ''; filterFeeds(); } });
        document.addEventListener('keydown', e => {
            if (e.key === '/' && document.activeElement !== searchInput) { e.preventDefault(); searchInput.focus(); }
        });

        function setCategory(category, buttonElement) {
            currentCategory = category;
            document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
            buttonElement.classList.add('active');
            filterFeeds();
        }

        function filterFeeds() {
            const terms = searchInput.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
            const cards = document.querySelectorAll('.feed-card');
            let visible = 0;

            cards.forEach(card => {
                const text = card.textContent.toLowerCase();
                const matchesCategory = (currentCategory === 'all' || card.dataset.category === currentCategory);
                const matchesSearch = terms.every(t => text.includes(t));
                const show = matchesCategory && matchesSearch;
                card.style.display = show ? 'flex' : 'none';
                if (show) visible++;
            });

            const noResults = document.getElementById('noResults');
            if (noResults) noResults.hidden = visible !== 0 || cards.length === 0;
        }
    </script>

</body>
</html>