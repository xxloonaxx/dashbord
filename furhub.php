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
if (isset($_GET['img'])) fh_img_serve((string)$_GET['img']);

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
            case 'img_upload':
                $need_post(); $need_login();
                $out += fh_img_store((int)$me['id'], $_FILES['file'] ?? [], (string)($_POST['kind'] ?? 'sona'));
                break;
            case 'pet':
                $need_login();
                $out['pet'] = fh_pet_get((int)$me['id']);
                break;
            case 'pet_save':
                $need_post(); $need_login();
                fh_pet_save((int)$me['id'], (string)($_POST['state'] ?? ''));
                break;
            case 'cards':
                $out['cards'] = $me ? fh_cards((int)$me['id']) : [];
                break;
            case 'cards_shared':
                $out['cards'] = fh_cards_shared();
                break;
            case 'card_save':
                $need_post(); $need_login();
                $out['id'] = fh_card_save((int)$me['id'], (int)($_POST['id'] ?? 0) ?: null, (array)json_decode((string)($_POST['data'] ?? '{}'), true), !empty($_POST['shared']));
                $out['cards'] = fh_cards((int)$me['id']);
                break;
            case 'card_delete':
                $need_post(); $need_login();
                $cid = (int)($_POST['id'] ?? 0);
                if (can('furhub.moderate') && !empty($_POST['mod'])) {
                    fh_db()->prepare('DELETE FROM cards WHERE id = ?')->execute([$cid]);
                    audit('Furry Hub: Karte entfernt', '#' . $cid, 'warn');
                } else fh_db()->prepare('DELETE FROM cards WHERE id = ? AND user_id = ?')->execute([$cid, (int)$me['id']]);
                $out['cards'] = fh_cards((int)$me['id']);
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
$TABS = ['start' => ['🏠', 'Start'], 'tama' => ['🥚', 'Tamagotchi'], 'sona' => ['🎨', 'Fursona'], 'avatar' => ['🧑‍🎨', 'Avatar'], 'cards' => ['🃏', 'Karten'], 'community' => ['🐾', 'Community'],
         'games' => ['🕹️', 'Minispiele'], 'toys' => ['🎉', 'Spielzeug'], 'bingo' => ['🎲', 'Con-Bingo'], 'quiz' => ['❓', 'Quiz'], 'sounds' => ['🔊', 'Sounds'], 'owo' => ['💬', 'OwO'], 'glossary' => ['📖', 'Glossar'], 'paws' => ['✍️', 'Pfotenabdrücke']];
$GENDERS = ['neutral' => '⚪ Keine Angabe', 'female' => '♀ Weiblich', 'male' => '♂ Männlich', 'nonbinary' => '⚧ Nicht-binär'];
$EMO = ['neutral' => ['😐', 'Normal'], 'happy' => ['😊', 'Fröhlich'], 'sad' => ['🥺', 'Traurig'], 'angry' => ['😠', 'Wütend'], 'surprised' => ['😳', 'Überrascht'], 'sleepy' => ['😴', 'Müde']];
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

    <!-- Tamagotchi -->
    <section class="fh-panel" data-panel="tama" hidden>
        <div class="tama-layout">
            <div class="tama-col">
                <div class="tama-device" id="tamaDevice">
                    <div class="tama-brand" id="tamaBrand">FLUFFOGOTCHI</div>
                    <div class="tama-screen" id="tamaScreen">
                        <div class="tama-icons" id="tamaIcons"></div>
                        <div class="tama-pet" id="tamaPet" title="Boop!"></div>
                        <div class="tama-poops" id="tamaPoops"></div>
                        <div class="tama-say" id="tamaSay"></div>
                    </div>
                    <div class="tama-hw">
                        <button type="button" data-hw="a" title="A: Boop">A</button>
                        <button type="button" data-hw="b" title="B: Füttern (Trockenfutter)">B</button>
                        <button type="button" data-hw="c" title="C: Ball spielen">C</button>
                    </div>
                </div>
                <div class="tama-quick" id="tamaQuick">
                    <button type="button" class="fh-btn" data-t="boop">👃 Boop</button>
                    <button type="button" class="fh-btn" data-t="pat">🤚 Kraulen</button>
                    <button type="button" class="fh-btn" data-t="hug">🫂 Umarmen</button>
                    <button type="button" class="fh-btn" data-t="awoo">🐺 Awoo</button>
                    <button type="button" class="fh-btn" data-t="feed">🍽️ Füttern</button>
                    <button type="button" class="fh-btn" data-t="play">🎾 Spielen</button>
                    <button type="button" class="fh-btn" data-t="wash">🛁 Waschen</button>
                    <button type="button" class="fh-btn" data-t="clean">🧹 Aufräumen</button>
                    <button type="button" class="fh-btn" data-t="medicine">💊 Medizin</button>
                    <button type="button" class="fh-btn" data-t="sleep" id="tamaSleep">💡 Licht aus</button>
                </div>
                <div class="tama-menu" id="tamaMenu" hidden></div>
            </div>
            <div class="tama-side">
                <div class="fh-card"><div class="fh-ct"><span id="tamaTitle">💗 Zustand</span><span class="coins" id="tamaCoins"></span></div><div id="tamaBars"></div><div class="tama-meta" id="tamaMeta"></div></div>
                <div class="fh-card">
                    <div class="tama-tabs"><button type="button" data-tt="shop" class="on">🛒 Shop</button><button type="button" data-tt="bag">🎒 Inventar</button><button type="button" data-tt="custom">🎨 Anpassen</button><button type="button" data-tt="settings">⚙️ Einstellungen</button><button type="button" data-tt="slots">💾 Spielstände</button><button type="button" data-tt="log">📜 Tagebuch</button></div>
                    <p class="muted small tama-keys">⌨️ Leertaste Boop · F Füttern · P Spielen · W Waschen · C Aufräumen · M Medizin · K Kraulen · H Umarmen · L Licht</p>
                    <div id="tamaTab"></div>
                </div>
                <div class="fh-card"><div class="fh-ct">📊 Gemeinsame Boops</div><div id="myBoops" class="my-boops"></div><div class="fh-ct" style="margin-top: 12px;">👑 Bestenliste</div><ol id="boopTop" class="boop-top"></ol></div>
            </div>
        </div>
    </section>

    <!-- Fursona: deine eigene Sona mit eigenen Bildern -->
    <section class="fh-panel" data-panel="sona" hidden>
        <div class="sona-layout">
            <div class="fh-card sona-preview">
                <div class="sona-hero" id="sonaHero"></div>
                <div class="sona-name" id="sonaTitle"></div>
                <div class="sona-traits" id="sonaTraitsView"></div>
                <div class="emo-strip" id="emoStrip"></div>
                <div class="sona-tools">
                    <button type="button" class="fh-btn sm" id="sonaPng">⬇ Bild speichern</button>
                    <button type="button" class="fh-btn sm" id="sonaGalleryView">🖼️ Galerie ansehen</button>
                    <button type="button" class="fh-btn sm" data-goto="avatar">🧑‍🎨 Avatar bearbeiten</button>
                </div>
            </div>
            <form class="fh-card sona-form" id="sonaForm" autocomplete="off">
                <div class="fh-ct">🎨 Meine Fursona</div>
                <div class="f2">
                    <label>Name <span class="inl"><input type="text" name="name" maxlength="40" required><button type="button" class="fh-btn sm" id="nameGen" title="Namen würfeln">🎲</button></span></label>
                    <label>Art <input type="text" name="species" maxlength="40" placeholder="z. B. Fuchs-Wolf-Hybrid"></label>
                </div>
                <div class="f2">
                    <label>Geschlecht <select name="gender"><?php foreach ($GENDERS as $k => $l): ?><option value="<?php echo $k; ?>"><?php echo $l; ?></option><?php endforeach; ?></select></label>
                    <label>Pronomen <input type="text" name="pronouns" maxlength="30" placeholder="z. B. sie/ihr, er/ihm, they/them"></label>
                </div>
                <div class="sub">So wird deine Sona gezeigt</div>
                <div class="mode-switch"><button type="button" data-mode-s="custom">🖼️ Meine Bilder</button><button type="button" data-mode-s="svg">🧑‍🎨 Mein Avatar</button></div>
                <p class="muted small" style="margin-top: 0;">Mit „Meine Bilder“ erscheinen deine Bilder in Community, Karten, Tamagotchi &amp; Spielen. Dein Avatar bleibt dein kleines Profilbild und springt ein, wo ein Bild fehlt.</p>
                <div class="sub">⭐ Hauptbild / Ref-Sheet</div>
                <div class="main-slot" id="mainSlot"></div>
                <div class="sub">😊 Emotionen <small class="muted">(optional – je ein Bild)</small></div>
                <div class="emo-slots" data-emo-slots="sona"></div>
                <div class="sub">🖼️ Galerie <small class="muted" id="galCount"></small></div>
                <div class="gallery-edit" id="galEdit"></div>
                <p class="muted small" style="margin-top: 0;">PNG, JPG, WebP oder GIF. Große Bilder werden automatisch verkleinert (Galerie bis 1600 px). Mehrere Bilder auf einmal oder per Drag &amp; Drop.<?php echo $me ? '' : ' Ohne Anmeldung bleiben die Bilder nur in diesem Browser.'; ?></p>
                <div class="sub">Persönlichkeit <button type="button" class="fh-btn sm" id="traitGen">🎲 neu würfeln</button></div>
                <div class="traits" id="sonaTraits"></div>
                <label>Über meine Sona <textarea name="bio" rows="3" maxlength="500" placeholder="Lieblingsessen, Macken, Hintergrundgeschichte …"></textarea></label>
                <label>Ref-Sheet-Link (optional) <input type="url" name="ref_url" maxlength="300" placeholder="https://…"></label>
                <div class="sona-save">
                    <?php if ($me): ?>
                        <button type="submit" class="fh-btn gold">💾 Speichern</button>
                        <button type="button" class="fh-btn sm danger" id="sonaDelete" hidden>Aus der Community entfernen</button>
                    <?php else: ?>
                        <p class="muted small">Dein Entwurf wird in diesem Browser gemerkt. <a href="admin.php?next=furhub.php">Melde dich an</a>, um deine Sona in der Community zu zeigen.</p>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <!-- Avatar: gezeichnet, mit Geschlecht, Frisur & Anstecker -->
    <section class="fh-panel" data-panel="avatar" hidden>
        <div class="sona-layout">
            <div class="fh-card sona-preview">
                <div id="sonaSvg" class="sona-svg"></div>
                <div class="sona-tools">
                    <button type="button" class="fh-btn" id="sonaRandom">🎲 Alles zufällig</button>
                    <button type="button" class="fh-btn" id="sonaColors">🎨 Nur Farben</button>
                    <button type="button" class="fh-btn sm" id="avPng">⬇ PNG</button>
                    <button type="button" class="fh-btn sm" id="sonaSvgDl">⬇ SVG</button>
                </div>
                <div class="expr-row">Emotion: <?php foreach ($EMO as $k => [$ic, $l]): ?><button type="button" data-expr="<?php echo $k; ?>" title="<?php echo e($l); ?>"><?php echo $ic; ?></button><?php endforeach; ?></div>
                <p class="muted small">Dein Avatar ist dein Profilbild im Furry Hub – in der Community, bei Pfotenabdrücken und überall, wo kein eigenes Bild da ist.</p>
            </div>
            <form class="fh-card sona-form" id="avatarForm" autocomplete="off">
                <div class="fh-ct">🧑‍🎨 Avatar-Baukasten</div>
                <div class="f2">
                    <label>Tierart <select name="preset" id="speciesSel"></select></label>
                    <label>Geschlecht <select name="gender"><?php foreach ($GENDERS as $k => $l): ?><option value="<?php echo $k; ?>"><?php echo $l; ?></option><?php endforeach; ?></select></label>
                </div>
                <p class="muted small gender-hint" id="genderHint"></p>
                <div class="sub">Farben</div>
                <div class="colors" id="sonaColorsIn"></div>
                <div class="sub">Form, Frisur &amp; Extras</div>
                <div class="shapes" id="sonaShapes"></div>
                <div class="checks"><label><input type="checkbox" name="blush"> Rosige Wangen</label><label><input type="checkbox" name="fluff"> Wangen-Fluff</label></div>
                <div class="sona-save">
                    <?php if ($me): ?><button type="submit" class="fh-btn gold">💾 Speichern</button><?php else: ?><p class="muted small">Wird in diesem Browser gemerkt.</p><?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <!-- Karten-Editor -->
    <section class="fh-panel" data-panel="cards" hidden>
        <div class="cards-layout">
            <form class="fh-card card-form" id="cardForm" autocomplete="off">
                <div class="fh-ct">🃏 Karten-Editor <button type="button" class="fh-btn sm" id="cardNew">＋ Neue Karte</button></div>
                <div class="tpl-pick" id="cardTpl"></div>
                <div class="sub">Thema</div>
                <div class="theme-pick" id="cardTheme"></div>
                <div class="f2">
                    <label>Name <input name="name" maxlength="40"></label>
                    <label>Untertitel / Con <input name="subtitle" maxlength="60"></label>
                </div>
                <div class="f3">
                    <label>Typ-Symbol <input name="type" maxlength="4" placeholder="🔥"></label>
                    <label>Flausch-Punkte <input name="hp" type="number" min="0" max="999"></label>
                    <label>Seltenheit <select name="rarity"><option value="1">★</option><option value="2">★★</option><option value="3">★★★</option><option value="4">★★★★</option><option value="5">★★★★★</option></select></label>
                </div>
                <label class="chk"><input type="checkbox" name="holo"> ✨ Holo-Glanz</label>
                <div class="sub">Bild</div>
                <div class="img-pick">
                    <select name="imgsrc"><option value="sona">Meine Fursona</option><option value="upload">Eigenes Bild hochladen</option><option value="none">Kein Bild</option></select>
                    <select name="imgemo"><?php foreach ($EMO as $k => [$ic, $l]): ?><option value="<?php echo $k; ?>"><?php echo $ic . ' ' . e($l); ?></option><?php endforeach; ?></select>
                    <button type="button" class="fh-btn sm" id="cardUpload">⬆ Bild wählen</button>
                    <input type="file" id="cardFile" accept="image/png,image/jpeg,image/webp,image/gif" hidden>
                </div>
                <div class="f3">
                    <label>Zoom <input type="range" name="zoom" min="50" max="250"></label>
                    <label>← → <input type="range" name="ix" min="-100" max="100"></label>
                    <label>↑ ↓ <input type="range" name="iy" min="-100" max="100"></label>
                </div>
                <div id="cardAbilities"></div>
                <div id="cardStats"></div>
                <div id="cardFields"></div>
                <label>Text unten (Flavor) <textarea name="flavor" rows="2" maxlength="200"></textarea></label>
                <label>Fußzeile <input name="footer" maxlength="60" placeholder="z. B. Art by … · Eurofurence 2027"></label>
            </form>
            <div class="card-side">
                <div class="card-stage"><div class="card-prev" id="cardPrev"></div></div>
                <div class="card-tools">
                    <button type="button" class="fh-btn" id="cardRandom">🎲 Zufallswerte</button>
                    <button type="button" class="fh-btn" id="cardPng">⬇ PNG</button>
                    <?php if ($me): ?><button type="button" class="fh-btn gold" id="cardSave">💾 Speichern</button><label class="chk sm"><input type="checkbox" id="cardShared"> in der Community zeigen</label>
                    <?php else: ?><button type="button" class="fh-btn gold" id="cardSave">💾 Im Browser merken</button><?php endif; ?>
                </div>
                <div class="fh-card"><div class="fh-ct">🗂️ Meine Karten <small id="cardCount"></small></div><div class="card-list" id="cardList"></div></div>
            </div>
        </div>
    </section>

    <!-- Minispiele -->
    <section class="fh-panel" data-panel="games" hidden>
        <div class="games-pick" id="gamesPick">
            <button type="button" data-game="catch"><b>🍖</b>Leckerli fangen<small>Fange Futter, meide Zwiebeln & Schoki</small></button>
            <button type="button" data-game="whack"><b>👃</b>Nasen-Boopen<small>Boope alle Nasen, die auftauchen</small></button>
            <button type="button" data-game="memory"><b>🃏</b>Pfoten-Memory<small>Finde alle Paare</small></button>
            <button type="button" data-game="sound"><b>🔊</b>Tierlaute raten<small>Wer macht dieses Geräusch?</small></button>
            <button type="button" data-game="wheel"><b>🎡</b>Glücksrad<small>Was machen wir auf der Con?</small></button>
        </div>
        <div class="fh-card game-box" id="gameBox"><p class="muted">Wähle ein Spiel. Gewonnene 🪙 Münzen landen im Tamagotchi-Shop!</p></div>
    </section>

    <!-- Spielzeug -->
    <section class="fh-panel" data-panel="toys" hidden>
        <div class="toys" id="toysGrid"></div>
    </section>

    <!-- Community -->
    <section class="fh-panel" data-panel="community" hidden>
        <div class="fh-toolbar">
            <span class="seg"><button type="button" data-com="sonas" class="on">🐾 Sonas</button><button type="button" data-com="cards">🃏 Karten</button></span>
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
<div class="fh-lightbox" id="fhLightbox" hidden><button type="button" class="lb-x" data-lb="close" aria-label="Schließen">×</button><button type="button" class="lb-nav prev" data-lb="prev" aria-label="Zurück">‹</button><figure><img id="lbImg" alt=""><figcaption id="lbCap"></figcaption></figure><button type="button" class="lb-nav next" data-lb="next" aria-label="Weiter">›</button></div>
<script>window.FH = <?php echo json_encode($boot, $json); ?>;</script>
<?php foreach (['furhub', 'furhub-tama', 'furhub-cards', 'furhub-games', 'furhub-toys'] as $js): ?><script src="assets/<?php echo $js; ?>.js?v=<?php echo filemtime(__DIR__ . '/assets/' . $js . '.js'); ?>"></script>
<?php endforeach; ?>
</body>
</html>
