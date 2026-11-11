<?php
// Der aktuelle Ordner, in dem das Skript liegt
$dir = __DIR__;
$allowed_extensions = ['php', 'html', 'htm'];

$files = scandir($dir);
$clean_links = [];

// Con-Daten für die Live-Vorschau auf der Karte laden
$con_json_path = __DIR__ . '/con_data.json';
$con_preview_text = "Keine aktiven Con-Daten gefunden";
if (file_exists($con_json_path)) {
    $con_data = json_decode(file_get_contents($con_json_path), true);
    if ($con_data) {
        $c_name = $con_data['con_name'] ?? 'Unbenannt';
        $c_budget = $con_data['total_budget'] ?? 0;
        $c_spent = 0;
        if (!empty($con_data['expenses'])) {
            foreach ($con_data['expenses'] as $ex) {
                $c_spent += $ex['amount'];
            }
        }
        $c_rem = $c_budget - $c_spent;
        $con_preview_text = "AKTIVE CON: {$c_name} // BUDGET: " . number_format($c_budget, 2, ',', '.') . " € // AUSGABEN: " . number_format($c_spent, 2, ',', '.') . " € // REST: " . number_format($c_rem, 2, ',', '.') . " €";
    }
}

foreach ($files as $file) {
    if ($file === '.' || $file === '..' || $file === basename(__FILE__)) {
        continue;
    }
    $file_info = pathinfo($file);
    if (isset($file_info['extension']) && in_array(strtolower($file_info['extension']), $allowed_extensions)) {
        $filename = $file_info['filename'];
        $sort_order = 999;
        $clean_filename = $filename;

        if (preg_match('/^(\d+)[\s_\-]*(.*)$/', $filename, $matches)) {
            $sort_order = (int)$matches[1];
            $clean_filename = $matches[2];
        }

        if (trim($clean_filename) === '') {
            $clean_filename = $filename;
        }
        
        $clean_name = strtoupper(str_replace(['_', '-'], ' ', $clean_filename));
        
        // Spezifische Beschreibung für con.php mit Live-Daten versehen
        $desc = 'Zentrale Hauptseite / Datenknoten';
        if (strtolower($file) === 'con.php') {
            $desc = $con_preview_text;
        }

        $clean_links[] = [
            'url' => $file,
            'name' => $clean_name,
            'sort' => $sort_order,
            'desc' => $desc,
            'category' => 'SYSTEM NODES'
        ];
    }
}

usort($clean_links, function($a, $b) {
    if ($a['sort'] == $b['sort']) {
        return strcmp($a['name'], $b['name']);
    }
    return ($a['sort'] < $b['sort']) ? -1 : 1;
});

// Interne Datenbank aller Unter-Links aus deinen Hubs für die globale Suche
$global_sub_links = [
    ["title" => "Südstaatenfurs (S-S-F)", "url" => "https://s-s-f.de/", "desc" => "Offizielle Website & Portal der Südstaatenfurs für Stammtische, Treffen und Events.", "category" => "Regionale Stammtische"],
    ["title" => "Eurofurence", "url" => "https://www.eurofurence.org", "desc" => "Die größte Furry-Convention Europas (Hamburg).", "category" => "Conventions"],
    ["title" => "NordicFuzzCon", "url" => "https://nordicfuzzcon.org", "desc" => "Skandinavische Convention in Malmö.", "category" => "Conventions"],
    ["title" => "Furality Online Xperience", "url" => "https://furality.org", "desc" => "Weltgrößte virtuelle VR-Convention in VRChat.", "category" => "Online & VR"],
    ["title" => "FurPlanet", "url" => "https://furplanet.com", "desc" => "Verlag und Online-Shop für Romane und Comics.", "category" => "SFW-Shops & Verlage"],
    ["title" => "Hyena Agenda", "url" => "https://hyena-agenda.com/", "desc" => "Hochwertiges Streetwear- und Apparel-Label.", "category" => "SFW-Shops & Verlage"],
    ["title" => "Pawprint Press", "url" => "https://store.pawprint.press/", "desc" => "Verlag für Comics (Lackadaisy) und Artbooks.", "category" => "SFW-Shops & Verlage"],
    ["title" => "IllumiCyberwear", "url" => "https://illumicyberwear.com/", "desc" => "Cyberpunk- und LED-Apparel & Fursuit-Illumination.", "category" => "SFW-Shops & Verlage"],
    ["title" => "e621 (NSFW)", "url" => "https://e621.net", "desc" => "Tag-basiertes Archiv für Adult Art und Animationen.", "category" => "NSFW & Adult"],
    ["title" => "Bad Dragon", "url" => "https://bad-dragon.com", "desc" => "Spezialshop für Adult-Toys und Silikon-Produkte.", "category" => "NSFW & Adult"],
    ["title" => "FurAffinity", "url" => "https://www.furaffinity.net", "desc" => "Größte Plattform für Furry-Kunst und Geschichten.", "category" => "Art & Galerien"],
    ["title" => "WikiFur (Deutsch)", "url" => "https://de.wikifur.com", "desc" => "Freie Enzyklopädie rund um das Furry-Fandom.", "category" => "Wissen & Tools"]
];

$total_nodes = count($clean_links) + count($global_sub_links);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GHOST NODE // TERMINAL</title>
    <style>
        :root {
            --bg-color: #040609;
            --panel-bg: rgba(10, 16, 24, 0.85);
            --panel-hover: rgba(18, 28, 42, 0.95);
            --accent-gold: #f3c262;
            --accent-cyan: #38d5e6;
            --accent-glow: rgba(56, 213, 230, 0.3);
            --text-main: #e2e8f0;
            --text-muted: #64748b;
            --border-color: rgba(56, 213, 230, 0.3);
        }

        * { box-sizing: border-box; }

        body {
            font-family: "Segoe UI", Roboto, -apple-system, sans-serif;
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(circle at 50% 10%, rgba(56, 213, 230, 0.08), transparent 70%),
                linear-gradient(rgba(4, 6, 9, 0.9), rgba(4, 6, 9, 0.98));
            background-attachment: fixed;
            color: var(--text-main);
            margin: 0;
            padding: 40px 20px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.3) 50%);
            background-size: 100% 4px;
            z-index: 1;
            pointer-events: none;
            opacity: 0.5;
        }

        .wrapper {
            width: 100%;
            max-width: 750px;
            z-index: 2;
        }

        .telemetry-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(56, 213, 230, 0.05);
            border: 1px solid var(--border-color);
            padding: 8px 14px;
            margin-bottom: 20px;
            font-size: 0.75rem;
            letter-spacing: 2px;
            color: var(--accent-cyan);
            text-transform: uppercase;
            clip-path: polygon(0 0, calc(100% - 8px) 0, 100% 8px, 100% 100%, 0 100%);
        }

        .telemetry-item span {
            color: #ffffff;
            font-weight: 700;
        }

        header {
            margin-bottom: 25px;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 15px;
        }

        .hud-tag {
            font-size: 0.7rem;
            letter-spacing: 3px;
            color: var(--accent-cyan);
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .hud-tag::before {
            content: "";
            display: inline-block;
            width: 6px;
            height: 6px;
            background-color: var(--accent-cyan);
            box-shadow: 0 0 10px var(--accent-cyan);
        }

        h1 {
            font-size: 2.4rem;
            font-weight: 800;
            letter-spacing: 5px;
            margin: 0 0 6px 0;
            text-transform: uppercase;
            color: var(--accent-gold);
            text-shadow: 0 0 15px rgba(243, 194, 98, 0.4);
        }

        .subtitle {
            color: var(--text-muted);
            margin: 0;
            font-size: 0.85rem;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .search-box {
            background-color: var(--panel-bg);
            border: 1px solid var(--border-color);
            padding: 20px;
            margin-bottom: 30px;
            backdrop-filter: blur(12px);
            clip-path: polygon(0 0, calc(100% - 12px) 0, 100% 12px, 100% 100%, 0 100%);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
        }

        .search-form {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .search-controls {
            display: flex;
            gap: 10px;
        }

        select#search-engine {
            background-color: rgba(4, 6, 9, 0.95);
            color: var(--accent-gold);
            border: 1px solid var(--border-color);
            padding: 12px 14px;
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 1px;
            outline: none;
            cursor: pointer;
            text-transform: uppercase;
        }

        select#search-engine:focus {
            border-color: var(--accent-cyan);
            box-shadow: 0 0 10px var(--accent-glow);
        }

        input[type="text"].search-input {
            flex: 1;
            background-color: rgba(4, 6, 9, 0.95);
            border: 1px solid var(--border-color);
            color: #ffffff;
            padding: 12px 16px;
            font-size: 1rem;
            outline: none;
            letter-spacing: 1px;
            transition: all 0.2s ease;
        }

        input[type="text"].search-input:focus {
            border-color: var(--accent-cyan);
            box-shadow: 0 0 15px var(--accent-glow);
        }

        button.search-btn {
            background-color: rgba(243, 194, 98, 0.15);
            border: 1px solid var(--accent-gold);
            color: var(--accent-gold);
            padding: 0 22px;
            font-weight: 700;
            letter-spacing: 2px;
            cursor: pointer;
            text-transform: uppercase;
            transition: all 0.2s ease;
        }

        button.search-btn:hover {
            background-color: var(--accent-cyan);
            border-color: var(--accent-cyan);
            color: #040609;
            box-shadow: 0 0 15px var(--accent-cyan);
        }

        .section-title {
            font-size: 0.8rem;
            letter-spacing: 2.5px;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 15px;
            font-weight: 700;
            display: flex;
            justify-content: space-between;
        }

        .list-container {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .file-link {
            background-color: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--accent-gold);
            padding: 18px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-decoration: none;
            color: var(--text-main);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            backdrop-filter: blur(10px);
            clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 14px, 100% 100%, 0 100%);
        }

        .file-link:hover {
            background-color: var(--panel-hover);
            border-color: var(--accent-cyan);
            border-left-color: var(--accent-cyan);
            transform: translateX(8px);
            box-shadow: 0 0 20px var(--accent-glow);
        }

        .file-details {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .file-name {
            font-weight: 800;
            font-size: 1.05rem;
            letter-spacing: 2px;
            color: #ffffff;
        }

        .file-meta {
            font-size: 0.75rem;
            color: var(--text-muted);
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .file-desc {
            font-size: 0.78rem;
            color: var(--accent-cyan);
            margin-top: 2px;
            letter-spacing: 0.5px;
        }

        .action-zone {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-muted);
            font-size: 0.75rem;
            letter-spacing: 2px;
            transition: color 0.2s ease;
            white-space: nowrap;
        }

        .file-link:hover .action-zone {
            color: var(--accent-cyan);
        }

        .arrow {
            font-size: 1.2rem;
            transition: transform 0.2s ease;
        }

        .file-link:hover .arrow {
            transform: translateX(6px);
        }

        .empty-msg {
            text-align: center;
            color: var(--text-muted);
            padding: 30px;
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
        <div class="telemetry-bar">
            <div class="telemetry-item">STATUS: <span>ONLINE // SECURE</span></div>
            <div class="telemetry-item">INDEXED NODES: <span id="nodeCount"><?php echo $total_nodes; ?></span></div>
            <div class="telemetry-item">TIME: <span id="liveClock">--:--:--</span></div>
        </div>

        <header>
            <div class="hud-tag">System // Central Terminal</div>
            <h1>Startseite</h1>
            <p class="subtitle">Zentrale Schnittstelle &amp; Datenknoten</p>
        </header>

        <div class="search-box">
            <form id="searchForm" action="https://www.google.com/search" method="GET" class="search-form" onsubmit="return handleSearchSubmit(event)">
                <div class="search-controls">
                    <select id="search-engine" onchange="updateSearchEngine(this.value)">
                        <option value="google" selected>Google</option>
                        <option value="duckduckgo">DuckDuckGo</option>
                        <option value="ecosia">Ecosia</option>
                        <option value="bing">Bing</option>
                        <option value="internal">// LOKAL (ALLE NODES &amp; LINKS)</option>
                    </select>
                    <input type="text" id="searchInput" name="q" class="search-input" placeholder="Suchbegriff eingeben..." autofocus autocomplete="off" onkeyup="filterLocalLinks()">
                    <button type="submit" id="searchBtn" class="search-btn">Suchen</button>
                </div>
            </form>
        </div>

        <div class="section-title">
            <span id="sectionHeader">Aktive Verzeichnisse &amp; Hubs</span>
            <span id="modeIndicator">[EXECUTE MODE]</span>
        </div>

        <!-- Kombinierte Liste aller lokalen Seiten und Unter-Links -->
        <div class="list-container" id="linkList">
            <!-- Lokale PHP/HTML Hauptseiten -->
            <?php foreach ($clean_links as $link): ?>
                <a href="<?php echo htmlspecialchars($link['url']); ?>" class="file-link search-item" data-search-text="<?php echo strtolower($link['name'] . ' ' . $link['url'] . ' ' . $link['desc']); ?>">
                    <div class="file-details">
                        <span class="file-name"><?php echo htmlspecialchars($link['name']); ?></span>
                        <span class="file-meta">TARGET: <?php echo htmlspecialchars($link['url']); ?></span>
                        <?php if (strtolower($link['url']) === 'con.php'): ?>
                            <span class="file-desc"><?php echo htmlspecialchars($link['desc']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="action-zone">
                        <span>EXECUTE</span>
                        <span class="arrow">&rarr;</span>
                    </div>
                </a>
            <?php endforeach; ?>

            <!-- Globale Unter-Links aus den Hubs -->
            <?php foreach ($global_sub_links as $sub): ?>
                <a href="<?php echo htmlspecialchars($sub['url']); ?>" target="_blank" class="file-link search-item" data-search-text="<?php echo strtolower($sub['title'] . ' ' . $sub['desc'] . ' ' . $sub['category']); ?>" style="display: none; border-left-color: var(--accent-cyan);">
                    <div class="file-details">
                        <span class="file-name"><?php echo htmlspecialchars($sub['title']); ?></span>
                        <span class="file-meta">HUB LINK // <?php echo htmlspecialchars($sub['category']); ?></span>
                        <span class="file-desc"><?php echo htmlspecialchars($sub['desc']); ?></span>
                    </div>
                    <div class="action-zone">
                        <span>OPEN</span>
                        <span class="arrow">&rarr;</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        function updateClock() {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            document.getElementById('liveClock').textContent = `${hours}:${minutes}:${seconds}`;
        }
        setInterval(updateClock, 1000);
        updateClock();

        function updateSearchEngine(engine) {
            const form = document.getElementById('searchForm');
            const input = document.getElementById('searchInput');
            const btn = document.getElementById('searchBtn');
            const modeInd = document.getElementById('modeIndicator');
            const items = document.querySelectorAll('.search-item');

            if (engine === 'internal') {
                input.placeholder = "Tippen zum Durchsuchen von Nodes & Links...";
                btn.style.display = 'none';
                modeInd.textContent = '[SEARCH MODE]';
                
                // Im Suchmodus erst mal alle Unter-Links ausblenden bis getippt wird
                items.forEach(i => {
                    if (!i.getAttribute('href').includes('con.php')) {
                        i.style.display = 'none';
                    }
                });
                input.value = '';
                input.focus();
            } else {
                btn.style.display = 'block';
                modeInd.textContent = '[EXECUTE MODE]';
                
                // Bei Web-Suche wieder normal alle Hauptseiten anzeigen, Unter-Links verstecken
                items.forEach(i => {
                    if (i.getAttribute('target') === '_blank') {
                        i.style.display = 'none';
                    } else {
                        i.style.display = 'flex';
                    }
                });

                if (engine === 'google') {
                    form.action = 'https://www.google.com/search';
                    input.name = 'q';
                } else if (engine === 'duckduckgo') {
                    form.action = 'https://duckduckgo.com/';
                    input.name = 'q';
                } else if (engine === 'ecosia') {
                    form.action = 'https://www.ecosia.org/search';
                    input.name = 'q';
                } else if (engine === 'bing') {
                    form.action = 'https://www.bing.com/search';
                    input.name = 'q';
                }
            }
        }

        function filterLocalLinks() {
            const engine = document.getElementById('search-engine').value;
            if (engine !== 'internal') return;

            const query = document.getElementById('searchInput').value.toLowerCase().trim();
            const items = document.querySelectorAll('.search-item');

            if (query === '') {
                items.forEach(item => {
                    if (!item.getAttribute('href').includes('con.php')) {
                        item.style.display = 'none';
                    } else {
                        item.style.display = 'flex';
                    }
                });
                return;
            }

            items.forEach(item => {
                const searchText = item.getAttribute('data-search-text');
                if (searchText.includes(query)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        function handleSearchSubmit(event) {
            const engine = document.getElementById('search-engine').value;
            if (engine === 'internal') {
                event.preventDefault();
                return false;
            }
            return true;
        }
    </script>
</body>
</html>