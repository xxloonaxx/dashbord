<?php
// Fehleranzeige aktivieren, falls auf dem Server etwas schiefgeht
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Umfangreiche, extrem ausführliche & duplikatsfreie Linksammlung
$categories = [
    "Regionale Cons, Meets & Stammtische (Süddeutschland & Nachbarn)" => [
        [
            "title" => "RheinFurs",
            "url" => "https://rheinfurs.de",
            "desc" => "Regionale Furry-Community, Stammtische und Walks im Rhein-Neckar- / Rhein-Main-Gebiet.",
            "icon" => "🍷"
        ],
	[
            "title" => "Südstaatenfurs (S-S-F)",
            "url" => "https://s-s-f.de/",
            "desc" => "Offizielle Website & Portal der Südstaatenfurs für Stammtische, Treffen und Events.",
            "icon" => "🦁"
        ],
        [
            "title" => "SaarFurs",
            "url" => "https://saarfurs.de",
            "desc" => "Regionale Treffen, Stammtische und gemütliches Beisammensein im Saarland.",
            "icon" => "⛏️"
        ],
        [
            "title" => "SwissFurry (Schweiz)",
            "url" => "https://swissfurry.ch",
            "desc" => "Schweizer Fandom-Portal für regionale Meets, Walks und die Romandie/Deutschschweiz.",
            "icon" => "🧀"
        ]
    ],

    "Europäische Präsenz-Conventions" => [
        [
            "title" => "Eurofurence (Deutschland)",
            "url" => "https://www.eurofurence.org",
            "desc" => "Die größte Furry-Convention Europas (Hamburg) mit über 9.000 Teilnehmern.",
            "icon" => "🏰"
        ],
        [
            "title" => "NordicFuzzCon (Schweden)",
            "url" => "https://nordicfuzzcon.org",
            "desc" => "Riesige skandinavische Convention in Malmö mit fantastischer Atmosphäre.",
            "icon" => "❄️"
        ],
        [
            "title" => "ConFuzzled (Großbritannien)",
            "url" => "https://confuzzled.org.uk",
            "desc" => "Die traditionsreichste und größte britische Furry-Convention.",
            "icon" => "🇬🇧"
        ],
        [
            "title" => "Scotiacon (Schottland)",
            "url" => "https://scotiacon.org.uk",
            "desc" => "Die schottische Convention mit toller Stimmung in Glasgow.",
            "icon" => "🏴󠁧󠁢󠁳󠁣󠁴󠁿"
        ],
        [
            "title" => "Dutch FurCon / FWH (Niederlande)",
            "url" => "https://dutchfurcon.nl",
            "desc" => "Die zentrale niederländische Convention für Events, Dances und Fursuiting.",
            "icon" => "🌷"
        ],
        [
            "title" => "Awoostria (Österreich)",
            "url" => "https://awoostria.at",
            "desc" => "Die österreichische Hotel-Convention im Herzen von Wien.",
            "icon" => "🇦🇹"
        ],
        [
            "title" => "Furizon (Italien)",
            "url" => "https://furizon.net",
            "desc" => "Italienische Resort-Convention mit Fokus auf Urlaub, Community und Strand/Berge.",
            "icon" => "🇮🇹"
        ],
        [
            "title" => "CESFUR (Tschechien)",
            "url" => "https://cesfur.org",
            "desc" => "Die älteste und bekannteste tschechische Furry-Convention.",
            "icon" => "🇨🇿"
        ],
        [
            "title" => "Furest / Fauntastic (Frankreich)",
            "url" => "https://fauntastic.eu",
            "desc" => "Die größte französische Furry-Convention in der Nähe von Lyon.",
            "icon" => "🇫🇷"
        ]
    ],

    "Online-Furcons & VR-Events" => [
        [
            "title" => "Furality Online Xperience (F.O.X.)",
            "url" => "https://furality.org",
            "desc" => "Die weltweit größte virtuelle VR-Convention in VRChat mit Custom-Welten & Club F.Y.N.N.",
            "icon" => "🥽"
        ],
        [
            "title" => "Furality Flower Festival",
            "url" => "https://furality.org/festival",
            "desc" => "Das offizielle Frühlings-Event von Furality direkt in VRChat.",
            "icon" => "🌸"
        ],
        [
            "title" => "CozyCon",
            "url" => "https://cozycononline.com",
            "desc" => "Eine der beliebtesten gemütlichen Online-Conventions (VRChat, Discord & Streaming).",
            "icon" => "☕"
        ],
    ],

"Bücher, Verlage, Merch & SFW-Shops" => [
        [
            "title" => "FurPlanet",
            "url" => "https://furplanet.com",
            "desc" => "Der bekannteste Verlag und Online-Shop für Romane, Kurzgeschichten und Comics.",
            "icon" => "📚"
        ],
        [
            "title" => "Hyena Agenda",
            "url" => "https://hyena-agenda.com/",
            "desc" => "Hochwertiges Streetwear- und Apparel-Label mit einzigartigen, stylischen Furry-Designs.",
            "icon" => "👕"
        ],
        [
            "title" => "Pawprint Press",
            "url" => "https://store.pawprint.press/",
            "desc" => "Offizieller Verlag & Shop für beliebte Comics (z. B. Lackadaisy), Artbooks, Plüschis und Merch.",
            "icon" => "🧸"
        ],
        [
            "title" => "Sofawolf Press",
            "url" => "https://www.sofawolf.com",
            "desc" => "Unabhängiger Verlag für hochwertige Romane, Graphic Novels und Anthologien.",
            "icon" => "📖"
        ],
        [
            "title" => "IllumiCyberwear",
            "url" => "https://illumicyberwear.com/",
            "desc" => "Cyberpunk- und LED-Apparel, leuchtende Accessoires & Visiere für Events und Fursuits.",
            "icon" => "💡"
        ],
        [
            "title" => "Angy Paws",
            "url" => "https://angypaws.com/",
            "desc" => "Spezialanbieter für bunte Fandom-Socken, Armstulpen, Pins und Kleidung.",
            "icon" => "🧦"
        ],
        [
            "title" => "Critter Cove",
            "url" => "https://crittercove.ca/",
            "desc" => "Alternative Streetwear, Prints und Accessoires inspiriert von Natur, Geschöpfen und Furries.",
            "icon" => "🐾"
        ],
        [
            "title" => "0 Fox Shop",
            "url" => "https://0foxshop.com/",
            "desc" => "Kleidung, Accessoires und Deko-Artikel im Fuchsmotiv und Anthro-Stil.",
            "icon" => "🦊"
        ]
    ],
    "NSFW & Adult Content" => [
        [
            "title" => "e621 (NSFW)",
            "url" => "https://e621.net",
            "nsfw" => true,
            "desc" => "Das größte, tag-basierte Archiv für Adult Art, Animationen und Illustrationen.",
            "icon" => "🔞"
        ],
        [
            "title" => "Bad Dragon",
            "url" => "https://bad-dragon.com",
            "desc" => "Spezialshop für Adult-Toys, Custom-Silikon-Produkte und Merchandise.",
            "icon" => "🐉"
        ],
        [
            "title" => "Subscribestar / Patreon (Furry Tag)",
            "url" => "https://subscribestar.adult",
            "desc" => "Beliebte Crowdfunding-Plattformen für NSFW-Künstler und Comic-Ersteller.",
            "icon" => "💳"
        ]
    ],
    "Art & Galerien" => [
        [
            "title" => "FurAffinity",
            "url" => "https://www.furaffinity.net",
            "desc" => "Die traditionsreichste und größte Plattform für Furry-Kunst, Geschichten und Fotografie.",
            "icon" => "🦊"
        ],
        [
            "title" => "e621",
            "url" => "https://e621.net",
            "nsfw" => true,
            "desc" => "Das umfassendste, tag-basierte Archiv für digitale Illustrationen und Kunst.",
            "icon" => "🐾"
        ],
        [
            "title" => "e926",
            "url" => "https://e926.net",
            "desc" => "Die SFW-Version (Safe for Work) von e621 – ideal für den Alltag.",
            "icon" => "🌿"
        ],
        [
            "title" => "Inkbunny",
            "url" => "https://inkbunny.net",
            "nsfw" => true,
            "desc" => "Beliebte Galerie- und Hosting-Plattform mit Fokus auf Artwork, Comics und Literatur.",
            "icon" => "🐰"
        ],
        [
            "title" => "Furry Network",
            "url" => "https://furrynetwork.com",
            "desc" => "Moderne Plattform für Artworks, Multimedia-Inhalte, Literatur und Künstlerprofile.",
            "icon" => "🌐"
        ],
        [
            "title" => "Weasyl",
            "url" => "https://www.weasyl.com",
            "desc" => "Open-Source-orientierte Galerie-Plattform für Künstler, Schreiber und Musiker.",
            "icon" => "🖌️"
        ]
    ],

    "Marktplätze & Fursuit-Services" => [
        [
            "title" => "The Dealers Den",
            "url" => "https://www.thedealersden.com",
            "desc" => "Der weltweit größte Auktions- und Marktplatz für Fursuits, Artworks und Sammlerstücke.",
            "icon" => "🛍️"
        ],
        [
            "title" => "FursuitReview",
            "url" => "https://fursuitreview.com",
            "desc" => "Unabhängige Bewertungsplattform für Fursuit-Maker, Qualität und Kundenerfahrungen.",
            "icon" => "⭐"
        ],
        [
            "title" => "Matrices' Fursuit Tutorials",
            "url" => "https://matrices.net",
            "desc" => "Die legendäre Anleitungssammlung zum Selberbauen von Fursuits und Masken.",
            "icon" => "🧵"
        ],
        [
            "title" => "GetCritter",
            "url" => "https://getcritter.com",
            "desc" => "Nützliche Plattform zur Übersicht und Vermittlung rund um Fursuiting und Equipment.",
            "icon" => "🐺"
        ],
        [
            "title" => "Fursuit Maker Database",
            "url" => "https://fursuitmaker.com",
            "desc" => "Verzeichnis zum Suchen und Entdecken aktiver Fursuit-Bauer rund um den Globus.",
            "icon" => "🪡"
        ]
    ],

    "Wissen & Community-Tools" => [
        [
            "title" => "WikiFur (Deutsch)",
            "url" => "https://de.wikifur.com",
            "desc" => "Die freie Enzyklopädie rund um die Furry-Fandom, Geschichte, Slang und Begriffe.",
            "icon" => "📚"
        ],
        [
            "title" => "Art Fight",
            "url" => "https://artfight.net",
            "desc" => "Das beliebte jährliche Sommer-Event für spielerischen Art-Trade zwischen Teams.",
            "icon" => "⚔️"
        ],
        [
            "title" => "Barq App",
            "url" => "https://barq.app",
            "desc" => "Social-App zum Finden von lokalen Furries, Treffen und Events in deiner Umgebung.",
            "icon" => "📲"
        ],
        [
            "title" => "WikiFur Conventions List",
            "url" => "https://en.wikifur.com/wiki/Category:Conventions",
            "desc" => "Die weltweite vollständige Datenbank aller vergangenen und kommenden Conventions.",
            "icon" => "📅"
        ]
    ]
];

// Im Browser hinzugefügte/geänderte Links laden (gespeichert in links_<hub>.json, hat Vorrang vor der Liste oben)
require_once __DIR__ . '/inc/modules/hub.php';
$categories = hub_load(__FILE__, $categories);

// Wird von index.php für die globale Suche eingebunden – dann nur die Daten liefern.
if (defined('LINKS_ONLY')) return $categories;

// Link-Check-Ergebnisse (⚠-Hinweise an kaputten Links) und Hilfsfunktionen
require_once __DIR__ . '/inc/modules/linkcheck.php';

// Beim allerersten Aufruf zur Admin-Einrichtung; danach "+ Link" / "+ Kategorie" usw. verarbeiten (nur mit Login)
auth_require_setup();
hub_handle_post(__FILE__, $categories);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Furry Community Hub</title>
    <?php echo emoji_favicon('🐾'); ?>
    <style>
        :root {
            --bg-color: #0b0e14;
            --card-bg: rgba(18, 26, 36, 0.9);
            --card-hover: rgba(28, 42, 58, 0.95);
            --text-color: #f0f6fc;
            --text-muted: #8b949e;
            --accent-color: #ff79c6;
            --border-color: #282a36;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-color);
            background-image: radial-gradient(circle at 50% 0%, rgba(255, 121, 198, 0.1), transparent 50%);
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
            background: rgba(255, 121, 198, 0.08);
            border: 1px solid rgba(255, 121, 198, 0.25);
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .nav-back:hover {
            background: rgba(255, 121, 198, 0.18);
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
            text-shadow: 0 0 15px rgba(255, 121, 198, 0.4);
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
            box-shadow: 0 0 10px rgba(255, 121, 198, 0.2);
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
            box-shadow: 0 4px 15px rgba(255, 121, 198, 0.15);
        }

        .icon-wrapper {
            width: 42px;
            height: 42px;
            background-color: rgba(255, 121, 198, 0.08);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 14px;
            flex-shrink: 0;
            border: 1px solid rgba(255, 121, 198, 0.2);
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
            <h1>Furry Community Hub</h1>
            <p class="subtitle">// Europäische Cons, VR-Events, Shops &amp; Regionales</p>
            <input type="search" id="searchInput" class="search-box" placeholder="Links durchsuchen..." autocomplete="off" autofocus aria-label="Links durchsuchen">
            <?php hub_toolbar(); ?>
        </header>

        <?php hub_flash(); ?>

        <div id="contentWrapper">
            <?php if (!$categories && can('links.edit')): ?>
                <div class="hub-empty">Noch keine Kategorien – <button type="button" data-hub-open="category">erste Kategorie anlegen</button></div>
            <?php endif; ?>
            <?php $cat_pos = 0; foreach ($categories as $catName => $tools): $cat_index = $cat_pos++; if (!hub_category_visible($catName, $tools)) continue; ?>
                <div class="category-section">
                    <div class="category-title hub-editable"><span><?php echo htmlspecialchars($catName); ?></span><?php hub_category_actions($catName, $cat_index, count($categories)); ?></div>
                    <div class="grid">
                        <?php if (!$tools && can('links.edit')): ?>
                            <div class="hub-empty">Noch keine Links – <button type="button" data-hub-open="link" data-category="<?php echo htmlspecialchars($catName); ?>">jetzt hinzufügen</button></div>
                        <?php endif; ?>
                        <?php foreach ($tools as $i => $tool): if (!nsfw_visible($tool, $catName)) continue; ?>
                            <div class="card-wrap">
                            <a href="<?php echo htmlspecialchars($tool['url']); ?>" class="card" target="_blank" rel="noopener noreferrer">
                                <div class="icon-wrapper"><?php echo htmlspecialchars($tool['icon']); ?></div>
                                <div class="info">
                                    <div class="title"><?php echo htmlspecialchars($tool['title']); ?> <?php echo hub_nsfw_badge($tool, $catName); ?> <?php echo link_badge($tool['url']); ?></div>
                                    <?php if (($tool['desc'] ?? '') !== ''): ?><div class="desc"><?php echo format_inline($tool['desc'], ['links' => false]); ?></div><?php endif; ?>
                                </div>
                            </a>
                            <?php hub_link_actions($catName, $i, $tool); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php hub_dialogs($categories); ?>
    <script src="assets/js/hub-search.js"></script>

</body>
</html>