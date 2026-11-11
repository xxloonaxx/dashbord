<?php
// Umfangreiche Tool-, Prefab- & Asset-Datenbank für VRChat Creator
$categories = [
    "Plattformen, Logs & Management" => [
        [
            "title" => "VRChat Home",
            "url" => "https://vrchat.com",
            "desc" => "Offizielle Homepage für Instanzen, Invites, Gruppen und Avatare.",
            "icon" => "🌐"
        ],
        [
            "title" => "VRCX Management Tool",
            "url" => "https://github.com/vrcx-team/VRCX",
            "desc" => "Desktop-App für Freundes-Logs, Avatar-Verwaltung und Einladungen ohne VR-Headset.",
            "icon" => "💻"
        ],
        [
            "title" => "VRChat Creator Companion (VCC)",
            "url" => "https://creators.vrchat.com",
            "desc" => "Das offizielle Tool zur Verwaltung deiner Unity-Projekte und SDK-Updates.",
            "icon" => "🛠️"
        ],
        [
            "title" => "VRChat Status Board",
            "url" => "https://status.vrchat.com",
            "desc" => "Live-Überwachung der VRChat-Server, API und Ausfälle.",
            "icon" => "📊"
        ]
    ],

    "YouTube Creator & Tutorials" => [
        [
            "title" => "Sipp's Avatar Box",
            "url" => "https://www.youtube.com/@SippsAvatarBox",
            "desc" => "Sehr beliebte, kompakte Video-Tutorials zu PhysBones, Toggles und AudioLink.",
            "icon" => "🎥"
        ],
        [
            "title" => "The Virtual Reality Show",
            "url" => "https://www.youtube.com/@TheVirtualRealityShow",
            "desc" => "Umfangreiche Guides zu VR-Technik, Avatar-Uploads, VRoid und World-Building.",
            "icon" => "📺"
        ],
        [
            "title" => "Ask Amber",
            "url" => "https://www.youtube.com/@AskAmber",
            "desc" => "Sehr verständliche Tutorials für Blender, Weight Painting und Outfit-Anpassungen.",
            "icon" => "📐"
        ],
        [
            "title" => "VRChat Official Channel",
            "url" => "https://www.youtube.com/@VRChat",
            "desc" => "Offizielle Dev-Updates, Developer-Stream-Recaps und Feature-Vorschauen.",
            "icon" => "📣"
        ],
        [
            "title" => "Lucinda Beskins",
            "url" => "https://www.youtube.com/@LucindaBeskins",
            "desc" => "Detailreiche Anleitungen für Booth-Modelle, Kisekae-Outfits und Unity-Tricks.",
            "icon" => "👗"
        ],
    ],

    "Asset-Suchmaschinen & Creator-Shops" => [
        [
            "title" => "Alcom Store",
            "url" => "https://alcom.su", // oder entsprechend deiner bevorzugten Alcom-Plattform
            "desc" => "Beliebter Marktplatz und Hub für erstklassige 3D-Assets, Kleidung und Avatare.",
            "icon" => "✨"
        ],
        [
            "title" => "Jinxxy Asset Market",
            "url" => "https://jinxxy.com",
            "desc" => "Spezialisierte Suchmaschine für VR-Avatare, Kleidung und Welten-Assets.",
            "icon" => "🔍"
        ],
        [
            "title" => "VRCArena Catalog",
            "url" => "https://www.vrcarena.com",
            "desc" => "Großes Verzeichnis für Avatar-Bases, Texturen und Prefabs.",
            "icon" => "🦖"
        ],
        [
            "title" => "Booth Explorer",
            "url" => "https://booth.pm/en",
            "desc" => "Der japanische Hauptmarkt für Anime-Bases, Kisekae-Outfits und Shader.",
            "icon" => "📦"
        ],
        [
            "title" => "Gumroad VR Assets",
            "url" => "https://gumroad.com",
            "desc" => "Marktplatz für westliche Avatare, Hair-Assets, Props und Partikelsysteme.",
            "icon" => "🛒"
        ],
        [
            "title" => "Payhip VR Assets",
            "url" => "https://payhip.com",
            "desc" => "Beliebter Ausweichmarkt für 3D-Creator und Bekleidungs-Assets.",
            "icon" => "💳"
        ]
    ],

    "Unverzichtbare Avatar-Prefabs & Logik" => [
        [
            "title" => "GoGo Loco",
            "url" => "https://booth.pm/en/items/3290806",
            "desc" => "All-in-One Locomotion-System: Posen, Fliegen, Skalierung und AFK-Animationen.",
            "icon" => "🏃"
        ],
        [
            "title" => "VRC Face Tracking (VRCFT)",
            "url" => "https://github.com/vrcft/VRCFT",
            "desc" => "Standard-System für Gesichts- und Augentracking (Quest Pro, Vive Pro Eye).",
            "icon" => "👁️"
        ],
        [
            "title" => "Jerry's Face Tracking Template",
            "url" => "https://github.com/iris-vrc/Jerrys-Face-Tracking",
            "desc" => "Drag-and-Drop-System zum einfachen Verbinden von VRCFT mit Avataren.",
            "icon" => "🎭"
        ],
        [
            "title" => "azukitiger Advanced Logic",
            "url" => "https://github.com/azukitiger/vrc-prefabs",
            "desc" => "Open-Source-Logik für Ohren-Twitch, Nose-Boop und PhysBone-Interaktionen.",
            "icon" => "🦊"
        ],
        [
            "title" => "VRCFury",
            "url" => "https://vrcfury.com",
            "desc" => "Automatisiert das Zusammenfügen von Outfits, Toggles und Animationen in Unity.",
            "icon" => "🔥"
        ],
        [
            "title" => "Modular Avatar",
            "url" => "https://modular-avatar.nadena.dev",
            "desc" => "Erlaubt das Hinzufügen von Kleidung und Menüs ohne manuelles Anpassen von Animatoren.",
            "icon" => "🧩"
        ]
    ],

    "Weltenbau, Udon & Videoplayer" => [
        [
            "title" => "ProTV / USharpVideo",
            "url" => "https://github.com/ArchiTechAnon/ProTV",
            "desc" => "Leistungsstarker Videoplayer für Welten mit Playlist- und Stream-Unterstützung.",
            "icon" => "📺"
        ],
        [
            "title" => "AudioLink (PiMaker)",
            "url" => "https://github.com/AudioLink/AudioLink",
            "desc" => "Reagiert in Echtzeit auf Audiofrequenzen (Bass/Höhen) für Welten-Lichter und Shader.",
            "icon" => "🎵"
        ],
        [
            "title" => "Vowgan Prefabs Library",
            "url" => "https://github.com/VirtualVisions/VowganPrefabs",
            "desc" => "Udon-Scripts für interaktive Stifte, Uhren, Kameras und Buttons.",
            "icon" => "🪄"
        ],
        [
            "title" => "CyanEmu (Unity Testing)",
            "url" => "https://github.com/CyanLaser/CyanEmu",
            "desc" => "Simuliert VRChat-Welten direkt im Unity-Editor ohne Hochladen.",
            "icon" => "🧪"
        ],
        [
            "title" => "UdonSharp",
            "url" => "https://github.com/vrchat/udonsharp",
            "desc" => "Ermöglicht das Schreiben von VRChat-Weltenlogik in echtem C#.",
            "icon" => "⚡"
        ]
    ],

    "Shader, Texturen & Optimierung" => [
        [
            "title" => "Poiyomi Shader",
            "url" => "https://github.com/poiyomi/PoiyomiToonShader",
            "desc" => "Der meistgenutzte Shader für Avatare mit unzähligen Effekten und Optimierungen.",
            "icon" => "🎨"
        ],
        [
            "title" => "Mochie Shaders",
            "url" => "https://github.com/MochiesCode/Mochies-Unity-Shaders",
            "desc" => "Hervorragende Shader für Wasser, Glas, Partikel und Cel-Shading.",
            "icon" => "💧"
        ],
        [
            "title" => "d4rkAvatarOptimizer",
            "url" => "https://github.com/d4rkplayer/d4rkAvatarOptimizer",
            "desc" => "Analysiert Avatare und kombiniert Material-Passes für deutlich bessere Performance.",
            "icon" => "🚀"
        ],
        [
            "title" => "Pumkin's Avatar Tools",
            "url" => "https://github.com/rRaptor/PumkinsAvatarTools",
            "desc" => "Handliches Hilfstool für Unity: Copy-Posen, Viewpoint-Anpassung und Avatar-Setup.",
            "icon" => "🎃"
        ]
    ],

    "3D-Software & Texturierung" => [
        [
            "title" => "Blender 3D",
            "url" => "https://www.blender.org",
            "desc" => "Kostenlose 3D-Suite für Avatarediting, Weight-Painting und Modeling.",
            "icon" => "🟠"
        ],
        [
            "title" => "Substance 3D Painter",
            "url" => "https://www.adobe.com/products/substance3d-painter.html",
            "desc" => "Industriestandard für das Bemalen von 3D-Modellen und Erstellen realistischer Texturen.",
            "icon" => "🖌️"
        ],
        [
            "title" => "Unity Hub",
            "url" => "https://unity.com",
            "desc" => "Notwendige Basis zur Installation der passenden Unity-Version für VRChat.",
            "icon" => "🎮"
        ]
    ]
];

// Im Browser hinzugefügte/geänderte Links laden (gespeichert in links_<hub>.json, hat Vorrang vor der Liste oben)
require_once __DIR__ . '/inc/hub.php';
$categories = hub_load(__FILE__, $categories);

// Wird von index.php für die globale Suche eingebunden – dann nur die Daten liefern.
if (defined('LINKS_ONLY')) return $categories;

// Link-Check-Ergebnisse (⚠-Hinweise an kaputten Links) und Hilfsfunktionen
require_once __DIR__ . '/inc/linkcheck.php';

// Beim allerersten Aufruf zur Admin-Einrichtung; danach "+ Link" / "+ Kategorie" usw. verarbeiten (nur mit Login)
auth_require_setup();
hub_handle_post(__FILE__, $categories);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VRChat Creator Hub</title>
    <?php echo emoji_favicon('🥽'); ?>
    <style>
        :root {
            --bg-color: #0b0e14;
            --card-bg: rgba(18, 26, 36, 0.9);
            --card-hover: rgba(28, 42, 58, 0.95);
            --text-color: #f0f6fc;
            --text-muted: #8b949e;
            --accent-color: #2bc5d3;
            --border-color: #1b2a3a;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-color);
            background-image: radial-gradient(circle at 50% 0%, rgba(43, 197, 211, 0.15), transparent 50%);
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
            background: rgba(43, 197, 211, 0.08);
            border: 1px solid rgba(43, 197, 211, 0.25);
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .nav-back:hover {
            background: rgba(43, 197, 211, 0.18);
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
            text-shadow: 0 0 15px rgba(43, 197, 211, 0.4);
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
            box-shadow: 0 0 10px rgba(43, 197, 211, 0.2);
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
            box-shadow: 0 4px 15px rgba(43, 197, 211, 0.15);
        }

        .icon-wrapper {
            width: 42px;
            height: 42px;
            background-color: rgba(43, 197, 211, 0.08);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 14px;
            flex-shrink: 0;
            border: 1px solid rgba(43, 197, 211, 0.2);
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
            <h1>VRChat Creator Hub</h1>
            <p class="subtitle">// Asset-, Prefab- &amp; Tutorial-Zentrale</p>
            <input type="search" id="searchInput" class="search-box" placeholder="Tool, Creator oder Prefab suchen..." autocomplete="off" autofocus aria-label="Links durchsuchen">
            <?php hub_toolbar(); ?>
        </header>

        <?php hub_flash(); ?>

        <div id="contentWrapper">
            <?php if (!$categories && is_admin()): ?>
                <div class="hub-empty">Noch keine Kategorien – <button type="button" data-hub-open="category">erste Kategorie anlegen</button></div>
            <?php endif; ?>
            <?php $cat_pos = 0; foreach ($categories as $catName => $tools): $cat_index = $cat_pos++; if (!hub_category_visible($catName, $tools)) continue; ?>
                <div class="category-section">
                    <div class="category-title hub-editable"><span><?php echo htmlspecialchars($catName); ?></span><?php hub_category_actions($catName, $cat_index, count($categories)); ?></div>
                    <div class="grid">
                        <?php if (!$tools && is_admin()): ?>
                            <div class="hub-empty">Noch keine Links – <button type="button" data-hub-open="link" data-category="<?php echo htmlspecialchars($catName); ?>">jetzt hinzufügen</button></div>
                        <?php endif; ?>
                        <?php foreach ($tools as $i => $tool): if (!nsfw_visible($tool, $catName)) continue; ?>
                            <div class="card-wrap">
                            <a href="<?php echo htmlspecialchars($tool['url']); ?>" class="card" target="_blank" rel="noopener noreferrer">
                                <div class="icon-wrapper"><?php echo htmlspecialchars($tool['icon']); ?></div>
                                <div class="info">
                                    <div class="title"><?php echo htmlspecialchars($tool['title']); ?> <?php echo hub_nsfw_badge($tool, $catName); ?> <?php echo link_badge($tool['url']); ?></div>
                                    <?php if (($tool['desc'] ?? '') !== ''): ?><div class="desc"><?php echo htmlspecialchars($tool['desc']); ?></div><?php endif; ?>
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
    <script src="assets/hub-search.js"></script>

</body>
</html>