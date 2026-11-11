<?php
// Fehleranzeige für Entwicklung/Debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Caching-Einstellungen (Cache-Datei & Gültigkeit in Sekunden -> 15 Min = 900 Sek)
$cache_file = __DIR__ . '/feed_cache.json';
$cache_lifetime = 900; 

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
        ["name" => "Phoronix", "url" => "https://www.phoronix.com/phoronix-rss.xml"]
    ],
    "Gaming & Modding" => [
        ["name" => "Hacker News", "url" => "https://news.ycombinator.com/rss"],
        ["name" => "Modrinth News", "url" => "https://modrinth.com/feed"]
    ]
];

// Funktion zum Abrufen der Feeds aus dem Netz
function fetch_fresh_feed_items($sources) {
    $items = [];

    foreach ($sources as $category => $feed_list) {
        foreach ($feed_list as $source) {
            $content = false;
            
            if (function_exists('curl_init')) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $source['url']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
                curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) GHOST-Node/3.0');
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                $content = curl_exec($ch);
                curl_close($ch);
            } else {
                $opts = [
                    'http' => [
                        'method' => "GET",
                        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) GHOST-Node/3.0\r\n",
                        'timeout' => 3
                    ]
                ];
                $context = stream_context_create($opts);
                $content = @file_get_contents($source['url'], false, $context);
            }

            if (!$content) continue;

            $xml = @simplexml_load_string($content);
            if (!$xml) continue;

            // RSS 2.0 Parser (wird u. a. von Bluesky genutzt)
            if (isset($xml->channel->item)) {
                foreach ($xml->channel->item as $entry) {
                    $pubDate = strtotime((string)$entry->pubDate);
                    $items[] = [
                        'title' => (string)$entry->title,
                        'link' => (string)$entry->link,
                        'description' => strip_tags((string)$entry->description),
                        'date' => $pubDate ? $pubDate : time(),
                        'source' => $source['name'],
                        'category' => $category
                    ];
                }
            } 
            // Atom Parser
            elseif (isset($xml->entry)) {
                foreach ($xml->entry as $entry) {
                    $link = "";
                    if (isset($entry->link)) {
                        foreach ($entry->link as $l) {
                            if ((string)$l['rel'] === 'alternate' || empty($l['rel'])) {
                                $link = (string)$l['href'];
                                break;
                            }
                        }
                        if (empty($link)) $link = (string)$entry->link['href'];
                    }
                    
                    $pubDate = strtotime((string)$entry->updated);
                    if (!$pubDate) $pubDate = strtotime((string)$entry->published);

                    $items[] = [
                        'title' => (string)$entry->title,
                        'link' => $link ? $link : '#',
                        'description' => strip_tags((string)($entry->summary ?? $entry->content ?? '')),
                        'date' => $pubDate ? $pubDate : time(),
                        'source' => $source['name'],
                        'category' => $category
                    ];
                }
            }
        }
    }

    // Nach Datum sortieren (neueste zuerst)
    usort($items, function($a, $b) {
        return $b['date'] <=> $a['date'];
    });

    return array_slice($items, 0, 60);
}

// Caching-Logik: Falls Cache existiert und noch frisch ist, aus Datei laden
if (file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_lifetime)) {
    $feed_items = json_decode(file_get_contents($cache_file), true);
} else {
    // Andernfalls neu laden und in Cache-Datei schreiben
    $feed_items = fetch_fresh_feed_items($feed_sources);
    @file_put_contents($cache_file, json_encode($feed_items));
}

$categories = array_keys($feed_sources);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FEED NODE // LIVE STREAM & EF COUNTDOWN</title>
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
    </style>
</head>
<body>

    <div class="wrapper">
        <a href="index.php" class="nav-back">&larr; ZURÜCK ZUM INDEX</a>

        <!-- Eurofurence Countdown Banner -->
        <div class="ef-banner">
            <span class="ef-title">// Eurofurence Countdown</span>
            <span id="efTimer" class="ef-timer">Berechne...</span>
        </div>

        <header>
            <div class="hud-tag">System // Aggregated Data Stream</div>
            <h1>Live Feed Node</h1>
            
            <div class="controls">
                <input type="text" id="searchInput" class="search-box" placeholder="Streams nach Stichworten durchsuchen..." onkeyup="filterFeeds()">
                
                <div class="filter-buttons">
                    <button class="filter-btn active" onclick="setCategory('all', this)">Alle Streams</button>
                    <?php foreach ($categories as $cat): ?>
                        <button class="filter-btn" onclick="setCategory('<?php echo htmlspecialchars($cat); ?>', this)"><?php echo htmlspecialchars($cat); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </header>

        <div class="feed-container" id="feedContainer">
            <?php if (empty($feed_items)): ?>
                <div class="empty-state">Keine aktiven Datenströme erreichbar. Server-Netzwerkverbindung prüfen.</div>
            <?php else: ?>
                <?php foreach ($feed_items as $item): ?>
                    <a href="<?php echo htmlspecialchars($item['link']); ?>" target="_blank" class="feed-card" data-category="<?php echo htmlspecialchars($item['category']); ?>">
                        <div class="feed-meta">
                            <span class="source-tag"><?php echo htmlspecialchars($item['source']); ?></span>
                            <span>// <?php echo htmlspecialchars($item['category']); ?></span>
                            <span>&bull; <?php echo date("d.m.Y - H:i", $item['date']); ?> Uhr</span>
                        </div>
                        <h2 class="feed-title"><?php echo htmlspecialchars($item['title']); ?></h2>
                        <?php if (!empty($item['description'])): ?>
                            <div class="feed-desc"><?php echo htmlspecialchars($item['description']); ?></div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Eurofurence Ziel-Datum (Nächster August-Termin)
        const efDate = new Date("August 25, 2027 14:00:00").getTime();

        function updateCountdown() {
            const now = new Date().getTime();
            const distance = efDate - now;

            if (distance < 0) {
                document.getElementById("efTimer").innerHTML = "Eurofurence läuft / beendet!";
                return;
            }

            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);

            document.getElementById("efTimer").innerHTML = `${days}T ${hours}h ${minutes}m ${seconds}s`;
        }

        setInterval(updateCountdown, 1000);
        updateCountdown();

        // Kategorien-Filter & Live-Suche
        let currentCategory = 'all';

        function setCategory(category, buttonElement) {
            currentCategory = category;
            document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
            buttonElement.classList.add('active');
            filterFeeds();
        }

        function filterFeeds() {
            const input = document.getElementById('searchInput').value.toLowerCase();
            const cards = document.querySelectorAll('.feed-card');

            cards.forEach(card => {
                const text = card.textContent.toLowerCase();
                const cardCategory = card.getAttribute('data-category');
                
                const matchesCategory = (currentCategory === 'all' || cardCategory === currentCategory);
                const matchesSearch = text.includes(input);

                if (matchesCategory && matchesSearch) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }
    </script>

</body>
</html>