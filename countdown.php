<?php
require_once __DIR__ . '/inc/modules/countdown.php';
require_once __DIR__ . '/inc/core/auth.php';
require_once __DIR__ . '/inc/core/format.php';

auth_require_setup();
guard_post('countdown.edit');   // Anlegen/Bearbeiten/Löschen nur mit Recht
$admin = can('countdown.edit');

$data = load_countdowns();

/** Formulardaten in ein Event übernehmen */
function event_from_post(array $base = []): ?array {
    $title = trim($_POST['title'] ?? '');
    $date = trim($_POST['date'] ?? '');
    if ($title === '' || !strtotime($date)) return null;
    return array_merge($base, [
        'title' => mb_substr($title, 0, 80),
        'date' => date('Y-m-d\TH:i', strtotime($date)),
        'icon' => mb_substr(trim($_POST['icon'] ?? '') ?: '⏳', 0, 4),
        'repeat' => ($_POST['repeat'] ?? '') === 'yearly' ? 'yearly' : 'none',
        'note' => mb_substr(trim($_POST['note'] ?? ''), 0, 300),
        'link' => safe_url($_POST['link'] ?? '') === '#' ? '' : trim($_POST['link']),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['event_id'] ?? '';

    if (isset($_POST['add_event']) && ($ev = event_from_post(['id' => uniqid(), 'featured' => false, 'created' => date('Y-m-d\TH:i')]))) {
        $data['events'][] = $ev;
    }

    foreach ($data['events'] as $i => &$ev) {
        if ($ev['id'] !== $id) continue;
        if (isset($_POST['edit_event']) && ($new = event_from_post($ev))) $ev = $new;
        if (isset($_POST['delete_event'])) unset($data['events'][$i]);
    }
    unset($ev);

    // Nur ein Event kann Haupt-Countdown sein (wird auch im Feed und auf der Startseite gezeigt)
    if (isset($_POST['feature_event'])) {
        foreach ($data['events'] as &$ev) {
            $ev['featured'] = ($ev['id'] === $id) ? empty($ev['featured']) : false;
        }
        unset($ev);
    }

    $data['events'] = array_values($data['events']);
    save_json(COUNTDOWN_FILE, $data);
    header('Location: countdown.php');
    exit;
}

// Kalender-Export (.ics) für ein einzelnes Event
if (isset($_GET['ics'])) {
    foreach ($data['events'] as $ev) {
        if ($ev['id'] !== $_GET['ics']) continue;
        $ts = next_occurrence($ev);
        $esc = function ($s) { return str_replace(["\\", ";", ",", "\n"], ["\\\\", "\;", "\\,", "\\n"], $s); };
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//GHOST NODE//Countdown//DE', 'BEGIN:VEVENT',
            'UID:' . $ev['id'] . '@ghost-node',
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . gmdate('Ymd\THis\Z', $ts),
            'DTEND:' . gmdate('Ymd\THis\Z', $ts + 3600),
            'SUMMARY:' . $esc($ev['title']),
        ];
        if (($ev['repeat'] ?? '') === 'yearly') $lines[] = 'RRULE:FREQ=YEARLY';
        if (!empty($ev['note'])) $lines[] = 'DESCRIPTION:' . $esc($ev['note']);
        if (!empty($ev['link'])) $lines[] = 'URL:' . $ev['link'];
        array_push($lines, 'END:VEVENT', 'END:VCALENDAR');

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9]+/i', '-', $ev['title']) . '.ics"');
        echo implode("\r\n", $lines) . "\r\n";
        exit;
    }
}

[$upcoming, $past] = sorted_countdowns($data['events']);
$hero = featured_countdown();
$icons = ['⏳', '🐾', '🎉', '🎂', '🎄', '🎆', '✈️', '🚆', '🏨', '🎮', '🎤', '💼', '💸', '❤️', '🏖️', '🦊', '🐺', '🐉'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COUNTDOWN NODE</title>
    <?php echo emoji_favicon('⏳'); ?>
    <link rel="stylesheet" href="assets/css/hud.css">
    <style>
        .hero {
            text-align: center;
            padding: 30px 20px;
            margin-bottom: 25px;
            background: linear-gradient(135deg, rgba(243, 194, 98, 0.08), rgba(56, 213, 230, 0.06));
            border: 1px solid rgba(243, 194, 98, 0.4);
            clip-path: polygon(0 0, calc(100% - 16px) 0, 100% 16px, 100% 100%, 0 100%);
        }
        .hero-label { font-size: 0.7rem; letter-spacing: 3px; color: var(--accent-gold); text-transform: uppercase; }
        .hero-title { font-size: 1.6rem; font-weight: 800; letter-spacing: 3px; color: #fff; text-transform: uppercase; margin: 6px 0 4px; }
        .hero-date { font-size: 0.8rem; color: var(--text-muted); letter-spacing: 1px; margin-bottom: 20px; }
        .digits { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; }
        .digit { min-width: 92px; padding: 14px 10px; background: rgba(4, 6, 9, 0.8); border: 1px solid var(--border-color); clip-path: var(--cut-sm); }
        .digit b { display: block; font-size: 2.6rem; font-weight: 800; color: var(--accent-cyan); text-shadow: 0 0 14px var(--accent-glow); font-variant-numeric: tabular-nums; line-height: 1.1; }
        .digit span { font-size: 0.62rem; letter-spacing: 2px; color: var(--text-muted); text-transform: uppercase; }
        .hero-done { font-size: 1.6rem; color: var(--accent-green); font-weight: 800; letter-spacing: 3px; }

        .cd-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(280px, 100%), 1fr)); gap: 14px; }
        .cd-card { background: var(--panel-bg); border: 1px solid var(--border-color); border-left: 3px solid var(--accent-gold); padding: 16px; clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 10px, 100% 100%, 0 100%); display: flex; flex-direction: column; gap: 8px; }
        .cd-card.featured { border-left-color: var(--accent-cyan); box-shadow: inset 0 0 25px rgba(56, 213, 230, 0.07); }
        .cd-card.soon { border-left-color: var(--accent-green); }
        .cd-head { display: flex; gap: 12px; align-items: center; }
        .cd-icon { font-size: 1.6rem; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; background: rgba(243, 194, 98, 0.06); border: 1px solid rgba(243, 194, 98, 0.25); flex-shrink: 0; }
        .cd-title { font-weight: 800; color: #fff; letter-spacing: 1px; }
        .cd-title a { color: inherit; text-decoration: none; }
        .cd-title a:hover { color: var(--accent-cyan); }
        .cd-date { font-size: 0.72rem; color: var(--text-muted); letter-spacing: 0.5px; }
        .cd-live { font-size: 1.15rem; font-weight: 700; color: var(--accent-cyan); font-variant-numeric: tabular-nums; letter-spacing: 1px; }
        .cd-note { font-size: 0.78rem; color: var(--text-muted); border-left: 2px solid var(--border-color); padding-left: 8px; }
        .cd-progress { height: 5px; background: rgba(56, 213, 230, 0.08); }
        .cd-progress div { height: 100%; background: var(--accent-cyan); box-shadow: 0 0 8px var(--accent-glow); }
        .cd-meta { display: flex; justify-content: space-between; font-size: 0.62rem; color: var(--text-muted); letter-spacing: 1px; text-transform: uppercase; }
        .cd-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px; }
        .tag { font-size: 0.6rem; letter-spacing: 1px; text-transform: uppercase; color: var(--accent-purple); border: 1px solid currentColor; padding: 1px 5px; margin-left: 6px; vertical-align: middle; }

        .form-grid { display: grid; grid-template-columns: 2fr 1.4fr 0.8fr; gap: 10px; margin-bottom: 10px; }
        .form-row { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; font-size: 0.8rem; color: var(--text-muted); }
        .icon-pick { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 10px; }
        .icon-pick button { background: rgba(4, 6, 9, 0.8); border: 1px solid var(--border-color); font-size: 1.1rem; width: 36px; height: 36px; cursor: pointer; }
        .icon-pick button:hover, .icon-pick button.sel { border-color: var(--accent-gold); background: rgba(243, 194, 98, 0.12); }
        .past-list { display: flex; flex-direction: column; gap: 6px; }
        .past-item { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 8px 12px; border: 1px dashed var(--border-color); color: var(--text-muted); font-size: 0.85rem; }
        details summary { cursor: pointer; color: var(--text-muted); letter-spacing: 2px; text-transform: uppercase; font-size: 0.75rem; margin-bottom: 10px; }

        @media (max-width: 700px) {
            .form-grid { grid-template-columns: 1fr; }
            .digits { gap: 6px; flex-wrap: nowrap; }
            .digit { min-width: 0; flex: 1; padding: 10px 4px; }
            .digit b { font-size: 1.7rem; }
            .digit span { font-size: 0.55rem; letter-spacing: 1px; }
            .hero { padding: 22px 12px; }
            .hero-title { font-size: 1.2rem; }
        }
    </style>
</head>
<body>
<?php auth_corner(); ?>
<div class="wrapper">
    <div class="nav-bar">
        <a href="index.php" class="nav-back">&larr; ZURÜCK ZUM INDEX</a>
        <div class="nav-tabs">
            <a href="calendar.php" class="nav-tab" title="Countdowns erscheinen automatisch im Kalender">📅 Kalender</a>
            <a href="feed.php" class="nav-tab">Feed</a>
            <a href="con.php" class="nav-tab">Con</a>
        </div>
    </div>

    <header>
        <div class="hud-tag">System // Temporal Tracking Node</div>
        <h1>Countdowns</h1>
        <p class="subtitle"><?php echo count($upcoming); ?> anstehende Termine</p>
    </header>

    <?php if ($hero): ?>
        <div class="hero">
            <div class="hero-label"><?php echo !empty($hero['featured']) ? '★ Haupt-Countdown' : 'Nächster Termin'; ?></div>
            <div class="hero-title"><?php echo e($hero['icon'] . ' ' . $hero['title']); ?></div>
            <div class="hero-date"><?php echo e(german_datetime($hero['ts'])); ?></div>
            <div class="digits" data-target="<?php echo date('c', $hero['ts']); ?>" id="heroDigits">
                <div class="digit"><b data-unit="d">--</b><span>Tage</span></div>
                <div class="digit"><b data-unit="h">--</b><span>Stunden</span></div>
                <div class="digit"><b data-unit="m">--</b><span>Minuten</span></div>
                <div class="digit"><b data-unit="s">--</b><span>Sekunden</span></div>
            </div>
        </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel-title"><span>// Anstehende Termine</span></div>
        <?php if (!$upcoming): ?>
            <div class="empty-msg">Noch keine Countdowns<?php echo $admin ? ' – unten einfach einen anlegen' : ''; ?>.</div>
        <?php else: ?>
            <div class="cd-grid">
                <?php foreach ($upcoming as $ev):
                    $start = countdown_start($ev, $ev['ts']);
                    $progress = max(0, min(100, round((time() - $start) / max(1, $ev['ts'] - $start) * 100)));
                    $soon = $ev['ts'] - time() < 7 * 86400;
                ?>
                    <div class="cd-card <?php echo !empty($ev['featured']) ? 'featured' : ($soon ? 'soon' : ''); ?>">
                        <div class="cd-head">
                            <div class="cd-icon"><?php echo e($ev['icon']); ?></div>
                            <div>
                                <div class="cd-title">
                                    <?php if (!empty($ev['link'])): ?><a href="<?php echo e($ev['link']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($ev['title']); ?> &nearr;</a><?php else: ?><?php echo e($ev['title']); ?><?php endif; ?>
                                    <?php if (($ev['repeat'] ?? '') === 'yearly'): ?><span class="tag">jährlich</span><?php endif; ?>
                                </div>
                                <div class="cd-date"><?php echo e(german_datetime($ev['ts'])); ?></div>
                            </div>
                        </div>
                        <div class="cd-live" data-target="<?php echo date('c', $ev['ts']); ?>"><?php echo e(relative_days($ev['ts'])); ?></div>
                        <div class="cd-progress" title="<?php echo $progress; ?> % der Wartezeit geschafft"><div style="width: <?php echo $progress; ?>%;"></div></div>
                        <div class="cd-meta"><span><?php echo $progress; ?> % geschafft</span><span><?php echo e(relative_days($ev['ts'])); ?></span></div>
                        <?php if (!empty($ev['note'])): ?><div class="cd-note fmt-inline"><?php echo format_inline($ev['note']); ?></div><?php endif; ?>
                        <div class="cd-actions">
                            <?php if ($admin): ?>
                            <form method="POST" style="margin:0;"><input type="hidden" name="event_id" value="<?php echo e($ev['id']); ?>"><button type="submit" name="feature_event" class="btn btn-sm <?php echo !empty($ev['featured']) ? '' : 'btn-gold'; ?>" title="Wird im Feed und auf der Startseite angezeigt"><?php echo !empty($ev['featured']) ? '★ Haupt' : '☆ Als Haupt'; ?></button></form>
                            <button type="button" class="btn btn-sm btn-gold" data-event="<?php echo e(json_encode($ev)); ?>" onclick="openEdit(JSON.parse(this.dataset.event))">Edit</button>
                            <?php endif; ?>
                            <a class="btn btn-sm" href="countdown.php?ics=<?php echo urlencode($ev['id']); ?>" title="In den Kalender übernehmen">.ics</a>
                            <?php if ($admin): ?>
                            <form method="POST" style="margin:0;" onsubmit="return confirm(<?php echo e(json_encode('Countdown „' . $ev['title'] . '“ löschen?', JSON_UNESCAPED_UNICODE)); ?>);"><input type="hidden" name="event_id" value="<?php echo e($ev['id']); ?>"><button type="submit" name="delete_event" class="btn btn-sm btn-danger">X</button></form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($admin): ?>
    <div class="panel">
        <div class="panel-title"><span>// Neuen Countdown anlegen</span></div>
        <form method="POST" id="addForm">
            <div class="form-grid">
                <div><label class="field">Bezeichnung</label><input type="text" name="title" maxlength="80" placeholder="z. B. Eurofurence, Urlaub, Geburtstag…" required></div>
                <div><label class="field">Datum &amp; Uhrzeit</label><input type="datetime-local" name="date" required></div>
                <div><label class="field">Icon</label><input type="text" name="icon" id="addIcon" value="⏳" maxlength="4"></div>
            </div>
            <div class="icon-pick" data-target="addIcon">
                <?php foreach ($icons as $ic): ?><button type="button"><?php echo $ic; ?></button><?php endforeach; ?>
            </div>
            <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
                <div><label class="field">Notiz (optional)</label><input type="text" name="note" maxlength="300" placeholder="Hotel gebucht, Zug um 9:12 …" data-format="inline"></div>
                <div><label class="field">Link (optional)</label><input type="url" name="link" placeholder="https://…"></div>
            </div>
            <div class="form-row">
                <label><input type="checkbox" name="repeat" value="yearly"> Jährlich wiederholen (Geburtstage, Feiertage)</label>
            </div>
            <button type="submit" name="add_event" class="btn">Countdown starten</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($past): ?>
        <details class="panel">
            <summary>Vergangene Termine (<?php echo count($past); ?>)</summary>
            <div class="past-list">
                <?php foreach ($past as $ev): ?>
                    <div class="past-item">
                        <span><?php echo e($ev['icon'] . ' ' . $ev['title']); ?> &bull; <?php echo e(german_datetime($ev['ts'], false)); ?></span>
                        <?php if ($admin): ?><form method="POST" style="margin:0;" onsubmit="return confirm('Eintrag löschen?');"><input type="hidden" name="event_id" value="<?php echo e($ev['id']); ?>"><button type="submit" name="delete_event" class="btn btn-sm btn-danger">X</button></form><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>
</div>

<!-- Bearbeiten -->
<div id="editModal" class="modal-overlay">
    <div class="modal-box">
        <button type="button" class="modal-close" onclick="closeEdit()" aria-label="Schließen">&times;</button>
        <div class="panel-title">// Countdown bearbeiten</div>
        <form method="POST">
            <input type="hidden" name="event_id" id="ed_id">
            <div style="margin-bottom: 10px;"><label class="field">Bezeichnung</label><input type="text" name="title" id="ed_title" maxlength="80" required></div>
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 10px; margin-bottom: 10px;">
                <div><label class="field">Datum &amp; Uhrzeit</label><input type="datetime-local" name="date" id="ed_date" required></div>
                <div><label class="field">Icon</label><input type="text" name="icon" id="ed_icon" maxlength="4"></div>
            </div>
            <div class="icon-pick" data-target="ed_icon">
                <?php foreach ($icons as $ic): ?><button type="button"><?php echo $ic; ?></button><?php endforeach; ?>
            </div>
            <div style="margin-bottom: 10px;"><label class="field">Notiz</label><input type="text" name="note" id="ed_note" maxlength="300" data-format="inline"></div>
            <div style="margin-bottom: 10px;"><label class="field">Link</label><input type="url" name="link" id="ed_link"></div>
            <div class="form-row"><label><input type="checkbox" name="repeat" value="yearly" id="ed_repeat"> Jährlich wiederholen</label></div>
            <button type="submit" name="edit_event" class="btn btn-gold" style="width: 100%;">Speichern</button>
        </form>
    </div>
</div>

<script>
    const pad = n => String(n).padStart(2, '0');

    function split(ms) {
        const s = Math.max(0, Math.floor(ms / 1000));
        return { d: Math.floor(s / 86400), h: Math.floor(s % 86400 / 3600), m: Math.floor(s % 3600 / 60), s: s % 60 };
    }

    const hero = document.getElementById('heroDigits');
    const heroTitle = document.title;
    const cards = document.querySelectorAll('.cd-live');

    function tick() {
        const now = Date.now();
        if (hero) {
            const left = new Date(hero.dataset.target) - now;
            const t = split(left);
            if (left <= 0) {
                hero.outerHTML = '<div class="hero-done">🎉 Es ist so weit!</div>';
                setTimeout(() => location.reload(), 60000);
            } else {
                hero.querySelector('[data-unit=d]').textContent = t.d;
                hero.querySelector('[data-unit=h]').textContent = pad(t.h);
                hero.querySelector('[data-unit=m]').textContent = pad(t.m);
                hero.querySelector('[data-unit=s]').textContent = pad(t.s);
                document.title = t.d + 'T ' + pad(t.h) + ':' + pad(t.m) + ':' + pad(t.s) + ' // ' + heroTitle;
            }
        }
        cards.forEach(el => {
            const left = new Date(el.dataset.target) - now;
            const t = split(left);
            el.textContent = left <= 0 ? '🎉 Jetzt!' : (t.d ? t.d + 'T ' : '') + pad(t.h) + 'h ' + pad(t.m) + 'm ' + pad(t.s) + 's';
        });
    }
    tick();
    setInterval(tick, 1000);

    // Icon-Auswahl
    document.querySelectorAll('.icon-pick').forEach(box => {
        box.addEventListener('click', e => {
            if (e.target.tagName !== 'BUTTON') return;
            document.getElementById(box.dataset.target).value = e.target.textContent;
            box.querySelectorAll('button').forEach(b => b.classList.toggle('sel', b === e.target));
        });
    });

    // Bearbeiten
    function openEdit(ev) {
        document.getElementById('ed_id').value = ev.id;
        document.getElementById('ed_title').value = ev.title;
        document.getElementById('ed_date').value = ev.date;
        document.getElementById('ed_icon').value = ev.icon;
        document.getElementById('ed_note').value = ev.note || '';
        document.getElementById('ed_link').value = ev.link || '';
        document.getElementById('ed_repeat').checked = ev.repeat === 'yearly';
        document.getElementById('editModal').style.display = 'flex';
        document.getElementById('ed_title').focus();
    }
    function closeEdit() { document.getElementById('editModal').style.display = 'none'; }
    window.addEventListener('click', e => { if (e.target.id === 'editModal') closeEdit(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeEdit(); });
</script>
<?php if ($admin): ?><script src="assets/js/editor.js"></script><?php endif; ?>
</body>
</html>
