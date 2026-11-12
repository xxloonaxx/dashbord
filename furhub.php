<?php
/**
 * Furry Hub – Spielereien für die Community:
 * Boop-Zähler (gemeinsam), Fursona-Baukasten mit gezeichnetem Avatar, Community-Sonas, virtueller Begleiter,
 * Con-Bingo, Tier-Quiz, Soundboard, OwO-Übersetzer, Glossar, Pfotenabdrücke, Erfolge & Easter Eggs.
 * JSON-Schnittstelle: furhub.php?api=…
 */
require_once __DIR__ . '/inc/furhub.php';
require_once __DIR__ . '/inc/countdown.php';
require_once __DIR__ . '/inc/linkcheck.php';

auth_require_setup();
$me = current_user();

// ---------- JSON-Schnittstelle ----------
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $out = ['ok' => true];
    try {
        $post = $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($post && !same_origin_request()) { http_response_code(403); exit(json_encode(['error' => 'Anfrage abgelehnt.'])); }
        $need_login = function () use ($me) { if (!$me) throw new InvalidArgumentException('Dafür musst du angemeldet sein.'); };
        $need_post = function () use ($post) { if (!$post) throw new InvalidArgumentException('Nur per POST.'); };
        switch ((string)$_GET['api']) {
            case 'stats':
                $out['stats'] = fh_stats();
                break;
            case 'react':
                $need_post();
                $out['added'] = fh_react((string)($_POST['key'] ?? ''), (int)($_POST['n'] ?? 1));
                $out['stats'] = fh_stats();
                break;
            case 'sonas':
                $out['sonas'] = fh_sonas();
                break;
            case 'sona_save':
                $need_post(); $need_login();
                $out['sona'] = fh_sona_save((int)$me['id'], (array)json_decode((string)($_POST['data'] ?? '{}'), true));
                break;
            case 'sona_delete':
                $need_post(); $need_login();
                $uid = (int)($_POST['user_id'] ?? $me['id']);
                if ($uid !== (int)$me['id'] && !can('furhub.moderate')) throw new InvalidArgumentException('Das darfst du nicht.');
                fh_db()->prepare('DELETE FROM sonas WHERE user_id = ?')->execute([$uid]);
                fh_db()->prepare('DELETE FROM sona_likes WHERE sona_user = ?')->execute([$uid]);
                if ($uid !== (int)$me['id']) audit('Furry Hub: Sona entfernt', '#' . $uid, 'warn');
                break;
            case 'sona_like':
                $need_post(); $need_login();
                $uid = (int)($_POST['user_id'] ?? 0);
                if ($uid === (int)$me['id']) throw new InvalidArgumentException('Die eigene Sona kannst du nicht liken – aber wir finden sie auch toll. 💖');
                if (!fh_sona($uid)) throw new InvalidArgumentException('Diese Sona gibt es nicht mehr.');
                $out += fh_sona_like($uid, (int)$me['id']);
                break;
            case 'gb':
                $out['entries'] = fh_guestbook();
                break;
            case 'gb_add':
                $need_post(); $need_login();
                fh_guestbook_add((int)$me['id'], (string)($_POST['msg'] ?? ''), (string)($_POST['paw'] ?? ''));
                $out['entries'] = fh_guestbook();
                break;
            case 'gb_delete':
                $need_post(); $need_login();
                $st = fh_db()->prepare('SELECT user_id FROM guestbook WHERE id = ?');
                $st->execute([(int)($_POST['id'] ?? 0)]);
                $owner = $st->fetchColumn();
                if ($owner === false) throw new InvalidArgumentException('Eintrag nicht gefunden.');
                if ((int)$owner !== (int)$me['id'] && !can('furhub.moderate')) throw new InvalidArgumentException('Das darfst du nicht.');
                fh_db()->prepare('DELETE FROM guestbook WHERE id = ?')->execute([(int)$_POST['id']]);
                if ((int)$owner !== (int)$me['id']) audit('Furry Hub: Pfotenabdruck gelöscht', '#' . (int)$_POST['id'], 'warn');
                $out['entries'] = fh_guestbook();
                break;
            default:
                throw new InvalidArgumentException('Unbekannte Aktion.');
        }
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        $out = ['error' => $e->getMessage()];
    } catch (Throwable $e) {
        http_response_code(500);
        $out = ['error' => 'Da ist etwas schiefgelaufen.'];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------- Seite ----------
$fact = fh_daily(FH_FACTS, 'fact');
$prompt = fh_daily(FH_PROMPTS, 'prompt');
[$upcoming] = sorted_countdowns(load_countdowns()['events']);
$upcoming = array_slice($upcoming, 0, 4);
$links = [];
try {
    foreach (collect_hub_links() as $url => $l) {
        if (in_array('furry.php', $l['hubs'] ?? [], true) && empty($l['nsfw'])) $links[] = ['title' => $l['title'], 'url' => $url, 'category' => $l['category']];
    }
} catch (Throwable $e) { /* Link-Hub nicht lesbar – Zufallslink entfällt */ }

$boot = fh_client_data() + [
    'me' => $me ? ['id' => (int)$me['id'], 'name' => user_label($me)] : null,
    'mySona' => $me ? fh_sona((int)$me['id']) : null,
    'stats' => fh_stats(),
    'links' => $links,
    'canModerate' => can('furhub.moderate'),
];
$json = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
$TABS = ['start' => ['🏠', 'Start'], 'boop' => ['👃', 'Boop'], 'sona' => ['🎨', 'Fursona'], 'community' => ['🐾', 'Community'], 'pet' => ['🐣', 'Begleiter'],
         'bingo' => ['🎲', 'Con-Bingo'], 'quiz' => ['❓', 'Quiz'], 'sounds' => ['🔊', 'Sounds'], 'owo' => ['💬', 'OwO'], 'glossary' => ['📖', 'Glossar'], 'paws' => ['✍️', 'Pfotenabdrücke']];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Furry Hub</title>
    <?php echo emoji_favicon('🦊'); ?>
    <link rel="stylesheet" href="assets/hud.css">
    <link rel="stylesheet" href="assets/furhub.css?v=<?php echo filemtime(__DIR__ . '/assets/furhub.css'); ?>">
</head>
<body class="fh">
<?php auth_corner(); ?>
<div class="wrapper fh-wrap">
    <div class="nav-bar">
        <a href="index.php" class="nav-back">&larr; ZURÜCK ZUM INDEX</a>
        <div class="nav-tabs">
            <a href="furry.php" class="nav-tab">🔗 Furry-Links</a>
            <a href="countdown.php" class="nav-tab">⏳ Countdowns</a>
            <a href="con.php" class="nav-tab">🎪 Con-Rechner</a>
            <button type="button" class="nav-tab" id="pawTrail" title="Pfotenspur am Mauszeiger">🐾 Spur</button>
        </div>
    </div>

    <header class="fh-head">
        <button type="button" class="fh-logo" id="fhLogo" aria-label="Furry Hub">🦊</button>
        <div>
            <div class="hud-tag">Community // Fluff Department</div>
            <h1>Furry Hub</h1>
            <p class="subtitle" id="fhHello"><?php echo $me ? 'Hallo ' . e(user_label($me)) . '! Schön, dass du da bist. 🐾' : 'Willkommen! Boopen geht auch ohne Konto. 🐾'; ?></p>
        </div>
        <div class="fh-counters" id="fhCounters"></div>
    </header>

    <nav class="fh-tabs" id="fhTabs" role="tablist">
        <?php foreach ($TABS as $k => [$ic, $l]): ?><button type="button" role="tab" data-tab="<?php echo $k; ?>"><span><?php echo $ic; ?></span><?php echo e($l); ?></button><?php endforeach; ?>
    </nav>

    <!-- Start -->
    <section class="fh-panel" data-panel="start">
        <div class="fh-grid">
            <div class="fh-card fact"><div class="fh-ct">🐾 Tierfakt des Tages</div><p><?php echo e($fact); ?></p></div>
            <div class="fh-card prompt"><div class="fh-ct">✏️ Zeichen-Idee des Tages</div><p><?php echo e($prompt); ?></p><button type="button" class="fh-btn sm" data-goto="sona">Sona dafür bauen →</button></div>
            <div class="fh-card sotd"><div class="fh-ct">⭐ Sona des Tages</div><div id="sotd" class="sotd-body"><span class="muted">Lädt …</span></div></div>
            <div class="fh-card"><div class="fh-ct">⏳ Demnächst</div>
                <?php foreach ($upcoming as $u): ?><div class="fh-cd"><span><?php echo e($u['icon'] ?? '⏳'); ?></span><b><?php echo e($u['title']); ?></b><small><?php echo e(relative_days($u['ts'])); ?></small></div><?php endforeach; ?>
                <?php if (!$upcoming): ?><p class="muted">Keine Countdowns – <a href="countdown.php">einen anlegen</a>, z. B. bis zur nächsten Con.</p><?php endif; ?>
            </div>
            <div class="fh-card"><div class="fh-ct">🎲 Entdecke etwas</div><div id="randLink" class="rand-link"></div><button type="button" class="fh-btn sm" id="randLinkBtn">Noch einer</button></div>
            <div class="fh-card wide"><div class="fh-ct">🏆 Deine Erfolge <small id="achCount"></small></div><div class="ach-grid" id="achGrid"></div></div>
            <div class="fh-card wide quick"><div class="fh-ct">🎮 Schnell spielen</div>
                <div class="quick-btns"><?php foreach (array_slice($TABS, 1, null, true) as $k => [$ic, $l]): ?><button type="button" data-goto="<?php echo $k; ?>"><b><?php echo $ic; ?></b><?php echo e($l); ?></button><?php endforeach; ?></div>
                <p class="muted small">Psst: Es gibt versteckte Geheimnisse. Probier mal den Konami-Code, tippe „awoo“ – oder klick ganz oft auf den Fuchs. 🦊</p>
            </div>
        </div>
    </section>

    <!-- Boop -->
    <section class="fh-panel" data-panel="boop" hidden>
        <div class="boop-layout">
            <div class="fh-card boop-stage">
                <div class="boop-pick"><label>Wessen Nase? <select id="boopWho"><option value="random">🎲 Zufällige Fluffbälle</option></select></label></div>
                <div class="boop-face" id="boopFace" title="Boop!"></div>
                <div class="boop-combo" id="boopCombo"></div>
                <div class="boop-actions">
                    <button type="button" class="fh-btn" data-react="awoo">🐺 Awoo</button>
                    <button type="button" class="fh-btn" data-react="pat">🤚 Headpat</button>
                    <button type="button" class="fh-btn" data-react="hug">🫂 Umarmen</button>
                </div>
                <p class="muted small">Klick (oder Leertaste) auf die Nase. Jeder Boop zählt für alle!</p>
            </div>
            <div class="boop-side">
                <div class="fh-card"><div class="fh-ct">📊 Deine Boops</div><div id="myBoops" class="my-boops"></div></div>
                <div class="fh-card"><div class="fh-ct">👑 Bestenliste</div><ol id="boopTop" class="boop-top"></ol></div>
            </div>
        </div>
    </section>

    <!-- Fursona-Baukasten -->
    <section class="fh-panel" data-panel="sona" hidden>
        <div class="sona-layout">
            <div class="fh-card sona-preview">
                <div id="sonaSvg" class="sona-svg"></div>
                <div class="sona-name" id="sonaTitle"></div>
                <div class="sona-traits" id="sonaTraitsView"></div>
                <div class="sona-tools">
                    <button type="button" class="fh-btn" id="sonaRandom">🎲 Alles zufällig</button>
                    <button type="button" class="fh-btn" id="sonaColors">🎨 Nur Farben</button>
                    <button type="button" class="fh-btn sm" id="sonaPng">⬇ PNG</button>
                    <button type="button" class="fh-btn sm" id="sonaSvgDl">⬇ SVG</button>
                </div>
                <div class="expr-row">Ausdruck: <?php foreach (['normal' => '🙂', 'happy' => '😊', 'boop' => '😳', 'sleep' => '😴', 'sad' => '🥺'] as $k => $ic): ?><button type="button" data-expr="<?php echo $k; ?>"><?php echo $ic; ?></button><?php endforeach; ?></div>
            </div>
            <form class="fh-card sona-form" id="sonaForm" autocomplete="off">
                <div class="fh-ct">🎨 Fursona-Baukasten</div>
                <div class="f2">
                    <label>Name <span class="inl"><input type="text" name="name" maxlength="40" required><button type="button" class="fh-btn sm" id="nameGen" title="Namen würfeln">🎲</button></span></label>
                    <label>Tierart <select name="preset" id="speciesSel"></select></label>
                </div>
                <div class="f2">
                    <label>Art (frei) <input type="text" name="species" maxlength="40" placeholder="z. B. Fuchs-Wolf-Hybrid"></label>
                    <label>Pronomen <input type="text" name="pronouns" maxlength="30" placeholder="z. B. sie/ihr, er/ihm, they/them"></label>
                </div>
                <div class="sub">Farben</div>
                <div class="colors" id="sonaColorsIn"></div>
                <div class="sub">Form</div>
                <div class="shapes" id="sonaShapes"></div>
                <div class="checks"><label><input type="checkbox" name="blush"> Rosige Wangen</label><label><input type="checkbox" name="fluff"> Wangen-Fluff</label></div>
                <div class="sub">Persönlichkeit <button type="button" class="fh-btn sm" id="traitGen">🎲 neu würfeln</button></div>
                <div class="traits" id="sonaTraits"></div>
                <label>Über meine Sona <textarea name="bio" rows="3" maxlength="500" placeholder="Lieblingsessen, Macken, Hintergrundgeschichte …"></textarea></label>
                <label>Ref-Sheet-Link (optional) <input type="url" name="ref_url" maxlength="300" placeholder="https://…"></label>
                <div class="sona-save">
                    <?php if ($me): ?>
                        <button type="submit" class="fh-btn gold">💾 Als meine Sona speichern</button>
                        <button type="button" class="fh-btn sm danger" id="sonaDelete" hidden>Entfernen</button>
                    <?php else: ?>
                        <p class="muted small">Dein Entwurf wird in diesem Browser gemerkt. <a href="admin.php?next=furhub.php">Melde dich an</a>, um deine Sona in der Community zu zeigen.</p>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <!-- Community -->
    <section class="fh-panel" data-panel="community" hidden>
        <div class="fh-toolbar">
            <input type="search" id="comSearch" placeholder="Suchen: Name, Art, Merkmal …">
            <select id="comSort"><option value="new">Neueste</option><option value="likes">Beliebteste</option><option value="name">Name A–Z</option></select>
            <span class="muted small" id="comCount"></span>
        </div>
        <div class="com-grid" id="comGrid"></div>
    </section>

    <!-- Begleiter -->
    <section class="fh-panel" data-panel="pet" hidden>
        <div class="pet-layout">
            <div class="fh-card pet-stage">
                <div class="pet-name"><b id="petName"></b> <button type="button" class="fh-btn sm" id="petRename" title="Umbenennen">✎</button><span class="pet-lvl" id="petLvl"></span></div>
                <div class="pet-face" id="petFace"></div>
                <div class="pet-say" id="petSay"></div>
                <div class="pet-actions">
                    <button type="button" class="fh-btn" data-pet="feed">🍖 Füttern</button>
                    <button type="button" class="fh-btn" data-pet="play">🎾 Spielen</button>
                    <button type="button" class="fh-btn" data-pet="brush">🪮 Bürsten</button>
                    <button type="button" class="fh-btn" data-pet="pat">🤚 Kraulen</button>
                    <button type="button" class="fh-btn" data-pet="sleep" id="petSleep">😴 Schlafen</button>
                </div>
            </div>
            <div class="fh-card pet-stats">
                <div class="fh-ct">💗 Zustand</div>
                <div id="petBars"></div>
                <div class="fh-ct" style="margin-top: 16px;">📜 Tagebuch</div>
                <ul id="petLog" class="pet-log"></ul>
                <p class="muted small">Dein Begleiter sieht aus wie deine Fursona aus dem Baukasten und lebt in diesem Browser weiter – auch wenn du weg bist. Schau ab und zu vorbei!</p>
            </div>
        </div>
    </section>

    <!-- Bingo -->
    <section class="fh-panel" data-panel="bingo" hidden>
        <div class="fh-toolbar"><b class="bingo-title">🎪 Con-Bingo</b><span class="muted small" id="bingoInfo"></span><span style="flex: 1;"></span><button type="button" class="fh-btn sm" id="bingoNew">🔀 Neue Karte</button><button type="button" class="fh-btn sm" id="bingoPrint">🖨 Drucken</button></div>
        <div class="bingo" id="bingo"></div>
    </section>

    <!-- Quiz -->
    <section class="fh-panel" data-panel="quiz" hidden>
        <div class="fh-card quiz" id="quiz"></div>
    </section>

    <!-- Sounds -->
    <section class="fh-panel" data-panel="sounds" hidden>
        <div class="fh-toolbar"><b>🔊 Tier-Soundboard</b><span class="muted small">alle Laute werden live im Browser erzeugt · Tasten 1–0</span><span style="flex: 1;"></span><label class="small">Lautstärke <input type="range" id="vol" min="0" max="100" value="60"></label></div>
        <div class="sounds" id="sounds"></div>
    </section>

    <!-- OwO -->
    <section class="fh-panel" data-panel="owo" hidden>
        <div class="owo-layout">
            <div class="fh-card"><div class="fh-ct">✍️ Dein Text</div><textarea id="owoIn" rows="7" placeholder="Hallo! Ich freue mich riesig auf die nächste Con, das wird großartig!"></textarea>
                <div class="owo-modes" id="owoModes"></div></div>
            <div class="fh-card"><div class="fh-ct">✨ Übersetzung <button type="button" class="fh-btn sm" id="owoCopy">⧉ Kopieren</button></div><div id="owoOut" class="owo-out"></div></div>
        </div>
    </section>

    <!-- Glossar -->
    <section class="fh-panel" data-panel="glossary" hidden>
        <div class="fh-toolbar"><input type="search" id="glossSearch" placeholder="Begriff suchen: Fursuit, YCH, Headless Lounge …"><span class="muted small" id="glossCount"></span></div>
        <div class="gloss" id="gloss"></div>
    </section>

    <!-- Pfotenabdrücke -->
    <section class="fh-panel" data-panel="paws" hidden>
        <?php if ($me): ?>
            <form class="fh-card paw-form" id="pawForm">
                <div class="fh-ct">🐾 Hinterlasse einen Pfotenabdruck</div>
                <div class="paw-row"><input type="color" name="paw" value="#f3c262" title="Pfotenfarbe"><input type="text" name="msg" maxlength="280" placeholder="Grüße, Con-Erinnerungen, ein Awoo …" required><button class="fh-btn gold">Stempeln</button></div>
            </form>
        <?php else: ?>
            <div class="fh-card"><p class="muted"><a href="admin.php?next=furhub.php">Melde dich an</a>, um einen Pfotenabdruck zu hinterlassen.</p></div>
        <?php endif; ?>
        <div class="paws" id="paws"></div>
    </section>
</div>

<div class="fh-toast" id="fhToast" hidden></div>
<script>window.FH = <?php echo json_encode($boot, $json); ?>;</script>
<script src="assets/furhub.js?v=<?php echo filemtime(__DIR__ . '/assets/furhub.js'); ?>"></script>
</body>
</html>
