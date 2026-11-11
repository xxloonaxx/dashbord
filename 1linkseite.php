<?php
// Fehleranzeige aktivieren, falls auf dem Server etwas schiefgeht
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Sehr ausführliche, universelle Linksammlung für alles Mögliche
$categories = [
    "Linux, Server & Open Source" => [
        [
            "title" => "ArchWiki",
            "url" => "https://wiki.archlinux.org",
            "desc" => "Das detailreichste Linux-Nachschlagewerk überhaupt – hilfreich für nahezu jede Distribution.",
            "icon" => "🐧"
        ],
        [
            "title" => "DistroWatch",
            "url" => "https://distrowatch.com",
            "desc" => "Übersicht, News und Benchmark-Rankings für fast alle Linux- und BSD-Distributionen.",
            "icon" => "💿"
        ],
        [
            "title" => "Awesome Selfhosted",
            "url" => "https://awesome-selfhosted.net",
            "desc" => "Die gigantische Liste für selbstgehostete Dienste, Docker-Container und Server-Software.",
            "icon" => "🏠"
        ],
        [
            "title" => "Flathub",
            "url" => "https://flathub.org",
            "desc" => "Der primäre App-Store für Flatpak-Anwendungen unter Linux.",
            "icon" => "📦"
        ],
        [
            "title" => "Proxmox VE",
            "url" => "https://www.proxmox.com",
            "desc" => "Open-Source-Plattform für Server-Virtualisierung, LXC-Container und KVM.",
            "icon" => "🖥️"
        ]
    ],

    "Entwicklung, Code & Tools" => [
        [
            "title" => "GitHub",
            "url" => "https://github.com",
            "desc" => "Das weltweit größte Entwickler-Netzwerk für Open-Source-Projekte und Code-Hosting.",
            "icon" => "🐙"
        ],
        [
            "title" => "Stack Overflow",
            "url" => "https://stackoverflow.com",
            "desc" => "Die unverzichtbare Anlaufstelle für Programmierfragen und Problemlösungen.",
            "icon" => "💻"
        ],
        [
            "title" => "CodePen",
            "url" => "https://codepen.io",
            "desc" => "Online-Code-Editor zum schnellen Testen von HTML, CSS und JavaScript im Browser.",
            "icon" => "⚡"
        ],
        [
            "title" => "Regex101",
            "url" => "https://regex101.com",
            "desc" => "Hervorragender Tester und Erklärer für reguläre Ausdrücke (RegEx) in Echtzeit.",
            "icon" => "🔍"
        ],
        [
            "title" => "Postman",
            "url" => "https://www.postman.com",
            "desc" => "Das Standard-Tool zum Testen, Entwickeln und Dokumentieren von REST- und GraphQL-APIs.",
            "icon" => "🚀"
        ],
        [
            "title" => "DevDocs.io",
            "url" => "https://devdocs.io",
            "desc" => "Kombiniert unzählige API-Dokumentationen in einer einzigen, rasend schnellen Oberfläche.",
            "icon" => "📚"
        ]
    ],

    "Gaming, Furry & Emulation" => [
        [
            "title" => "e621",
            "url" => "https://e621.net",
            "desc" => "Das größte Archiv für Furry-Artworks, Illustrationen und digitale Kunst.",
            "icon" => "🐾"
        ],
        [
            "title" => "ProtonDB",
            "url" => "https://www.protondb.com",
            "desc" => "Community-Datenbank zur Spielbarkeit von Steam-Spielen unter Linux und Steam Deck.",
            "icon" => "🎮"
        ],
        [
            "title" => "Modrinth",
            "url" => "https://modrinth.com",
            "desc" => "Moderne, schnelle Open-Source-Plattform für Minecraft-Mods, Modpacks und Shader.",
            "icon" => "⛏️"
        ],
        [
            "title" => "CurseForge",
            "url" => "https://www.curseforge.com",
            "desc" => "Riesiger Katalog für Add-ons und Mods für Minecraft, WoW und viele weitere Spiele.",
            "icon" => "🔥"
        ],
        [
            "title" => "Emulation General Wiki",
            "url" => "https://emulation.gametechwiki.com",
            "desc" => "Das umfangreichste Wiki rund um Konsolen-Emulatoren, ROMs und Setup-Guides.",
            "icon" => "🕹️"
        ]
    ],

    "Produktivität & Organisation" => [
        [
            "title" => "Notion",
            "url" => "https://www.notion.com",
            "desc" => "Alles-in-Einem-Workspace für Notizen, Wikis, Datenbanken und Projektmanagement.",
            "icon" => "📓"
        ],
        [
            "title" => "Trello",
            "url" => "https://trello.com",
            "desc" => "Visuelles Aufgabenmanagement und Projektplanung im schlanken Kanban-Board-Stil.",
            "icon" => "📋"
        ],
        [
            "title" => "DeepL Translate",
            "url" => "https://www.deepl.com",
            "desc" => "Der aktuell präziseste KI-basierte Übersetzer für Texte und ganze Dokumente.",
            "icon" => "🌍"
        ],
        [
            "title" => "WolframAlpha",
            "url" => "https://www.wolframalpha.com",
            "desc" => "Computational Intelligence für Mathematik, Physik, Daten und Faktenrecherche.",
            "icon" => "🧮"
        ],
        [
            "title" => "Excalidraw",
            "url" => "https://excalidraw.com",
            "desc" => "Virtuelles Open-Source-Whiteboard für schnelle Skizzen, Diagramme und Flowcharts.",
            "icon" => "✏️"
        ]
    ],

    "Streaming, Audio & Unterhaltung" => [
        [
            "title" => "Twitch",
            "url" => "https://www.twitch.tv",
            "desc" => "Führende Livestreaming-Plattform für Gaming, Just Chatting und Creative Streams.",
            "icon" => "🟣"
        ],
        [
            "title" => "MyAnimeList (MAL)",
            "url" => "https://myanimelist.net",
            "desc" => "Die größte Datenbank zum Tracken, Bewerten und Entdecken von Animes und Mangas.",
            "icon" => "⛩️"
        ],
        [
            "title" => "AniList",
            "url" => "https://anilist.co",
            "desc" => "Moderne, stylische Alternative zu MAL für Anime- und Manga-Listen mit toller API.",
            "icon" => "🌸"
        ],
        [
            "title" => "Bandcamp",
            "url" => "https://bandcamp.com",
            "desc" => "Plattform für unabhängige Musiker und Labels zum Kaufen und Streamen von Alben.",
            "icon" => "🎧"
        ]
    ],

    "Cloud, Hosting & Utilities" => [
        [
            "title" => "Cloudflare",
            "url" => "https://www.cloudflare.com",
            "desc" => "Web-Infrastruktur, DNS, SSL-Zertifikate, CDN und DDoS-Schutz.",
            "icon" => "🛡️"
        ],
        [
            "title" => "Proton",
            "url" => "https://proton.me",
            "desc" => "Sicherer, Ende-zu-Ende verschlüsselter E-Mail-, Kalender- und Cloud-Speicher-Anbieter.",
            "icon" => "🔒"
        ],
        [
            "title" => "Speedtest by Ookla",
            "url" => "https://www.speedtest.net",
            "desc" => "Zuverlässiger Test für Internet-Download-, Upload-Geschwindigkeit und Ping.",
            "icon" => "📈"
        ],
        [
            "title" => "VirusTotal",
            "url" => "https://www.virustotal.com",
            "desc" => "Überprüft Dateien und URLs kostenlos mit Dutzenden von Antiviren-Scannern.",
            "icon" => "🦠"
        ],
        [
            "title" => "Have I Been Pwned",
            "url" => "https://haveibeenpwned.com",
            "desc" => "Prüfe, ob deine E-Mail-Adresse oder dein Passwort in Datenlecks aufgetaucht ist.",
            "icon" => "🔑"
        ]
    ],

    "News & Wissen" => [
        [
            "title" => "Hacker News (Y Combinator)",
            "url" => "https://news.ycombinator.com",
            "desc" => "Schlichtes Aggregat für IT-News, Open-Source-Projekte und Tech-Diskussionen.",
            "icon" => "📰"
        ],
        [
            "title" => "Golem.de",
            "url" => "https://www.golem.de",
            "desc" => "Deutsches IT-Newsportal für IT-Profis, Open Source, Hardware und Recht.",
            "icon" => "⚙️"
        ],
        [
            "title" => "Heise Online",
            "url" => "https://www.heise.de",
            "desc" => "Nachrichten aus der IT-Welt, IT-Sicherheit (c't) und Technik-Trends.",
            "icon" => "💻"
        ],
        [
            "title" => "Internet Archive (Wayback Machine)",
            "url" => "https://archive.org",
            "desc" => "Digitale Bibliothek für historische Webseiten, alte Software, Bücher und Medien.",
            "icon" => "🏛️"
        ],
        [
            "title" => "Wikipedia",
            "url" => "https://www.wikipedia.org",
            "desc" => "Die freie Online-Enzyklopädie mit Wissen zu nahezu jedem erdenklichen Thema.",
            "icon" => "📚"
        ]
    ]
];

// Wird von index.php für die globale Suche eingebunden – dann nur die Daten liefern.
if (defined('LINKS_ONLY')) return $categories;

// Link-Check-Ergebnisse (⚠-Hinweise an kaputten Links) und Hilfsfunktionen
require_once __DIR__ . '/inc/linkcheck.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generelle Linksammlung</title>
    <?php echo emoji_favicon('🔗'); ?>
    <style>
        :root {
            --bg-color: #0b0e14;
            --card-bg: rgba(18, 26, 36, 0.9);
            --card-hover: rgba(28, 42, 58, 0.95);
            --text-color: #f0f6fc;
            --text-muted: #8b949e;
            --accent-color: #38d5e6;
            --border-color: #1b2a3a;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-color);
            background-image: radial-gradient(circle at 50% 0%, rgba(56, 213, 230, 0.12), transparent 50%);
            color: var(--text-color);
            margin: 0;
            padding: 40px 20px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .main-wrapper {
            width: 100%;
            max-width: 1100px;
        }

        .nav-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--accent-color);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 25px;
            padding: 8px 16px;
            background: rgba(56, 213, 230, 0.08);
            border: 1px solid rgba(56, 213, 230, 0.25);
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .nav-back:hover {
            background: rgba(56, 213, 230, 0.18);
            border-color: var(--accent-color);
            transform: translateX(-3px);
        }

        header {
            text-align: center;
            margin-bottom: 35px;
            border-bottom: 2px solid var(--accent-color);
            padding-bottom: 20px;
            width: 100%;
        }

        h1 {
            font-size: 2.2rem;
            font-weight: 800;
            letter-spacing: 2px;
            margin: 0 0 10px 0;
            text-transform: uppercase;
            text-shadow: 0 0 15px rgba(56, 213, 230, 0.4);
        }

        .subtitle {
            color: var(--accent-color);
            margin: 0 0 20px 0;
            font-size: 0.9rem;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            font-weight: bold;
        }

        .search-box {
            width: 100%;
            max-width: 400px;
            padding: 10px 16px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            background: rgba(10, 15, 22, 0.8);
            color: var(--text-color);
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s;
        }

        .search-box:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 10px rgba(56, 213, 230, 0.2);
        }

        .category-section {
            margin-bottom: 30px;
        }

        .category-title {
            font-size: 1.1rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #ffffff;
            margin-bottom: 15px;
            border-left: 4px solid var(--accent-color);
            padding-left: 12px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(320px, 100%), 1fr));
            gap: 16px;
        }

        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 16px;
            display: flex;
            align-items: flex-start;
            text-decoration: none;
            color: var(--text-color);
            transition: all 0.2s ease;
        }

        .card:hover {
            background-color: var(--card-hover);
            border-color: var(--accent-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(56, 213, 230, 0.15);
        }

        .icon-wrapper {
            width: 42px;
            height: 42px;
            background-color: rgba(56, 213, 230, 0.08);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 14px;
            flex-shrink: 0;
            border: 1px solid rgba(56, 213, 230, 0.2);
            font-size: 1.5rem;
        }

        .info {
            display: flex;
            flex-direction: column;
        }

        .title {
            font-weight: 700;
            font-size: 1rem;
            margin-bottom: 4px;
            color: #ffffff;
        }

        .desc {
            font-size: 0.82rem;
            color: var(--text-muted);
            line-height: 1.35;
        }
        /* Warnhinweis aus dem Link-Check */
        .link-warn {
            display: inline-block;
            font-size: 0.62rem;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #ff5555;
            border: 1px solid rgba(255, 85, 85, 0.5);
            padding: 1px 5px;
            margin-left: 4px;
            vertical-align: middle;
        }

        /* Suche: Trefferzähler & Leer-Hinweis */
        .search-counter {
            margin-top: 8px;
            font-size: 0.72rem;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--text-muted);
        }

        .no-results {
            text-align: center;
            padding: 30px;
            border: 1px dashed var(--accent-color);
            color: var(--text-muted);
            letter-spacing: 2px;
            text-transform: uppercase;
            font-size: 0.85rem;
        }

        /* Tastatur-Navigation sichtbar machen */
        a:focus-visible, input:focus-visible {
            outline: 2px solid var(--accent-color);
            outline-offset: 2px;
        }

        @media (max-width: 600px) {
            body { padding: 24px 14px; }
            h1 { font-size: 1.6rem; }
            .card { padding: 14px; }
        }
    </style>
</head>
<body>

    <div class="main-wrapper">
        <a href="index.php" class="nav-back">&larr; ZURÜCK ZUR INDEX</a>

        <header>
            <h1>Generelle Linksammlung</h1>
            <p class="subtitle">// Umfassende Zentrale für Tools, News &amp; Ressourcen</p>
            <input type="search" id="searchInput" class="search-box" placeholder="Links durchsuchen..." autocomplete="off" autofocus aria-label="Links durchsuchen">
        </header>

        <div id="contentWrapper">
            <?php foreach ($categories as $catName => $tools): ?>
                <div class="category-section">
                    <div class="category-title"><?php echo htmlspecialchars($catName); ?></div>
                    <div class="grid">
                        <?php foreach ($tools as $tool): ?>
                            <a href="<?php echo htmlspecialchars($tool['url']); ?>" class="card" target="_blank" rel="noopener noreferrer">
                                <div class="icon-wrapper"><?php echo htmlspecialchars($tool['icon']); ?></div>
                                <div class="info">
                                    <div class="title"><?php echo htmlspecialchars($tool['title']); ?> <?php echo link_badge($tool['url']); ?></div>
                                    <div class="desc"><?php echo htmlspecialchars($tool['desc']); ?></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script src="assets/hub-search.js"></script>

</body>
</html>