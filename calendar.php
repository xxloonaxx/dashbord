<?php
/**
 * Kalender (SQLite) – Monat, Woche, Agenda, Jahr; Feiertage; Countdowns als eigene Ebene (nur lesend).
 */
require_once __DIR__ . '/inc/modules/calendar.php';
require_once __DIR__ . '/inc/modules/calendar_ics.php';
require_once __DIR__ . '/inc/core/auth.php';
require_once __DIR__ . '/inc/core/format.php';

// Abo-Link fürs Handy: muss ohne Login funktionieren, deshalb vor der Zugriffsprüfung
if (isset($_GET['feed'])) {
    $kind = cal_feed_kind_for_token((string)$_GET['feed']);
    if (!$kind) { http_response_code(404); exit('Unbekannter Kalender-Link.'); }
    header('Content-Type: text/calendar; charset=utf-8');
    header('Cache-Control: private, max-age=900');
    header('Content-Disposition: inline; filename="ghostnode.ics"');
    echo cal_export_ics($kind === 'private', cal_setting('feed_include_subs', '0') === '1');
    exit;
}

auth_require_setup();
$admin = can('calendar.edit');                              // Termine anlegen/bearbeiten
$see_private = $admin || can('calendar.private');           // private Termine sehen
$manage_subs = can('calendar.subscriptions');               // Abos & Abo-Links verwalten

$WEEKDAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
$WEEKDAYS_LONG = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
$MONTHS = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

try {
    cal_db();
} catch (Throwable $e) {
    http_response_code(500);
    exit('<p style="font-family:sans-serif;padding:30px">Kalender nicht verfügbar: ' . e($e->getMessage()) . '</p>');
}

/** Zurück zur aktuellen Ansicht */
function cal_back(array $extra = []): string {
    $q = array_filter(['view' => $_POST['view'] ?? $_GET['view'] ?? null, 'date' => $_POST['date'] ?? $_GET['date'] ?? null]) + $extra;
    return 'calendar.php' . ($q ? '?' . http_build_query($q) : '');
}

// ---------- Änderungen (nur Admin) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    guard_post(in_array($act, ['sub_save', 'sub_sync', 'sub_delete', 'feed_token'], true) ? 'calendar.subscriptions' : 'calendar.edit');
    $msg = ''; $err = '';
    try {
        switch ($_POST['action'] ?? '') {
            case 'save_event':
                $ev = cal_event_from_post($_POST);
                $id = (int)($_POST['id'] ?? 0);
                $existing = $id ? cal_get($id) : null;
                if ($existing && $existing['source_id']) throw new InvalidArgumentException('Termine aus Abos sind schreibgeschützt – bitte im Originalkalender ändern.');
                if ($existing) { cal_update($id, $ev); $msg = 'Termin „' . $ev['title'] . '“ gespeichert.'; }
                else { $id = cal_insert($ev); $msg = 'Termin „' . $ev['title'] . '“ angelegt.'; }
                $_POST['date'] = substr($ev['start_at'], 0, 10);
                if ($conf = cal_conflicts($id, $ev['start_at'], $ev['end_at'], (bool)$ev['all_day'])) $msg .= ' ⚠ Überschneidung mit: ' . implode(', ', $conf);
                break;

            case 'quick_add':
                $q = cal_quick_parse((string)($_POST['text'] ?? ''));
                $ev = cal_event_from_post($q);
                $id = cal_insert($ev);
                $_POST['date'] = substr($ev['start_at'], 0, 10);
                $msg = '„' . $ev['title'] . '“ angelegt: ' . implode(' · ', $q['understood']) . '.';
                if ($conf = cal_conflicts($id, $ev['start_at'], $ev['end_at'], (bool)$ev['all_day'])) $msg .= ' ⚠ Überschneidung mit: ' . implode(', ', $conf);
                break;

            case 'sub_save':
                $url = cal_normalize_feed_url((string)($_POST['url'] ?? ''));
                if (!cal_url_allowed($url)) throw new InvalidArgumentException('Bitte eine gültige, öffentliche Kalender-Adresse (https://… oder webcal://…) angeben.');
                $fields = [
                    'name' => mb_substr(trim($_POST['name'] ?? ''), 0, 80),
                    'url' => $url,
                    'color' => preg_match('/^#[0-9a-f]{6}$/i', $_POST['color'] ?? '') ? $_POST['color'] : '#bd93f9',
                    'private' => !empty($_POST['private']) ? 1 : 0,
                    'enabled' => isset($_POST['sid']) && !isset($_POST['enabled']) ? 0 : 1,
                    'refresh_minutes' => max(15, min(10080, (int)($_POST['refresh_minutes'] ?? 60))),
                    'skip_past_days' => max(0, min(3650, (int)($_POST['skip_past_days'] ?? 0))),
                ];
                $sid = (int)($_POST['sid'] ?? 0);
                if ($sid) {
                    $old = cal_db()->prepare('SELECT url FROM subscriptions WHERE id = ?');
                    $old->execute([$sid]);
                    $url_changed = $old->fetchColumn() !== $url;
                    $set = implode(', ', array_map(function ($k) { return "$k = :$k"; }, array_keys($fields)));
                    cal_db()->prepare("UPDATE subscriptions SET $set" . ($url_changed ? ", etag = '', last_modified = ''" : '') . ' WHERE id = :id')->execute($fields + ['id' => $sid]);
                    // Sichtbarkeit sofort auf vorhandene Termine übertragen
                    cal_db()->prepare('UPDATE events SET private = ? WHERE source_id = ?')->execute([$fields['private'], $sid]);
                } else {
                    cal_db()->prepare('INSERT INTO subscriptions (name, url, color, private, enabled, refresh_minutes, skip_past_days) VALUES (:name, :url, :color, :private, :enabled, :refresh_minutes, :skip_past_days)')->execute($fields);
                    $sid = (int)cal_db()->lastInsertId();
                }
                $r = cal_sync_subscription($sid, true);
                $msg = 'Abo gespeichert und abgeglichen: ' . $r['total'] . ' Termine (' . $r['added'] . ' neu, ' . $r['updated'] . ' aktualisiert, ' . $r['removed'] . ' entfernt).';
                break;

            case 'sub_sync':
                $r = cal_sync_subscription((int)($_POST['sid'] ?? 0), true);
                $msg = 'Abgleich fertig: ' . $r['total'] . ' Termine – ' . $r['added'] . ' neu, ' . $r['updated'] . ' aktualisiert, ' . $r['removed'] . ' entfernt.';
                break;

            case 'sub_delete':
                cal_db()->prepare('DELETE FROM subscriptions WHERE id = ?')->execute([(int)($_POST['sid'] ?? 0)]);
                $msg = 'Abo und seine Termine wurden entfernt.';
                break;

            case 'feed_token':
                cal_feed_token($_POST['kind'] === 'private' ? 'private' : 'public', true);
                $msg = 'Neuer Abo-Link erzeugt – der alte funktioniert nicht mehr.';
                break;

            case 'delete_event':
                $id = (int)($_POST['id'] ?? 0);
                $ev = cal_get($id);
                if (!$ev) break;
                if ($ev['source_id']) throw new InvalidArgumentException('Termine aus Abos sind schreibgeschützt.');
                if (($_POST['scope'] ?? 'all') === 'single' && $ev['recurrence'] !== 'none' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['day'] ?? '')) {
                    cal_db()->prepare('INSERT OR IGNORE INTO event_exceptions (event_id, day) VALUES (?, ?)')->execute([$id, $_POST['day']]);
                    $msg = 'Termin am ' . date('d.m.Y', strtotime($_POST['day'])) . ' entfernt (Serie bleibt bestehen).';
                } else {
                    cal_db()->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
                    $msg = 'Termin „' . $ev['title'] . '“ gelöscht.';
                }
                break;

            case 'move_event':   // Drag & Drop in der Monatsansicht (nur einmalige Termine)
                $id = (int)($_POST['id'] ?? 0);
                $ev = cal_get($id);
                $to = $_POST['to_day'] ?? '';
                if ($ev && !$ev['source_id'] && $ev['recurrence'] === 'none' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                    $shift = (strtotime($to) - strtotime(substr($ev['start_at'], 0, 10))) / 86400;
                    $mv = function ($dt) use ($shift) { return date('Y-m-d H:i', strtotime(($shift >= 0 ? '+' : '') . (int)$shift . ' days', strtotime($dt))); };
                    cal_db()->prepare("UPDATE events SET start_at = ?, end_at = ?, updated_at = datetime('now','localtime') WHERE id = ?")->execute([$mv($ev['start_at']), $mv($ev['end_at']), $id]);
                    $msg = '„' . $ev['title'] . '“ auf ' . date('d.m.Y', strtotime($to)) . ' verschoben.';
                }
                break;

            case 'save_settings':
                $state = $_POST['state'] ?? '';
                if (array_key_exists($state, CAL_STATES)) cal_set_setting('state', $state);
                cal_set_setting('show_holidays', !empty($_POST['show_holidays']) ? '1' : '0');
                $msg = 'Einstellungen gespeichert.';
                break;

            case 'save_category':
                $name = mb_substr(trim($_POST['name'] ?? ''), 0, 40);
                $color = preg_match('/^#[0-9a-f]{6}$/i', $_POST['color'] ?? '') ? $_POST['color'] : '#38d5e6';
                if ($name === '') throw new InvalidArgumentException('Bitte einen Kategorie-Namen eingeben.');
                $cid = (int)($_POST['id'] ?? 0);
                if ($cid) cal_db()->prepare('UPDATE categories SET name = ?, color = ? WHERE id = ?')->execute([$name, $color, $cid]);
                else cal_db()->prepare('INSERT INTO categories (name, color, sort) VALUES (?, ?, (SELECT COALESCE(MAX(sort), 0) + 1 FROM categories))')->execute([$name, $color]);
                $msg = 'Kategorie „' . $name . '“ gespeichert.';
                break;

            case 'delete_category':
                cal_db()->prepare('DELETE FROM categories WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
                $msg = 'Kategorie gelöscht (Termine bleiben erhalten, ohne Kategorie).';
                break;

            case 'import_ics':
                if (empty($_FILES['ics']['tmp_name']) || $_FILES['ics']['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Bitte eine .ics-Datei auswählen.');
                if ($_FILES['ics']['size'] > 5 * 1024 * 1024) throw new InvalidArgumentException('Die Datei ist zu groß (max. 5 MB).');
                $cat = (int)($_POST['category_id'] ?? 0);
                cal_db()->beginTransaction();
                [$imp, $skip] = cal_import_ics((string)file_get_contents($_FILES['ics']['tmp_name']), $cat ?: null, !empty($_POST['private']));
                cal_db()->commit();
                $msg = "Import fertig: $imp Termine übernommen" . ($skip ? ", $skip übersprungen (doppelt oder unvollständig)" : '') . '.';
                break;
        }
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        if (cal_db()->inTransaction()) cal_db()->rollBack();
        $err = 'Fehler: ' . $e->getMessage();
    }
    header('Location: ' . cal_back($err ? ['error' => $err] : ($msg ? ['msg' => $msg] : [])));
    exit;
}

// ---------- Export ----------
if (($_GET['export'] ?? '') === 'ics') {
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="kalender_' . date('Y-m-d') . '.ics"');
    echo cal_export_ics($see_private, !empty($_GET['subs']));
    exit;
}

// Einzelner Termin als .ics
if (isset($_GET['ics_event'])) {
    $ev = cal_get((int)$_GET['ics_event']);
    if (!$ev || ($ev['private'] && !$see_private)) { http_response_code(404); exit('Nicht gefunden.'); }
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . (preg_replace('/[^A-Za-z0-9äöüÄÖÜß_-]+/u', '-', $ev['title']) ?: 'termin') . '.ics"');
    echo cal_export_ics(true, true, (int)$ev['id']);
    exit;
}

// Anstehende Erinnerungen als JSON (für Browser-Benachrichtigungen auf Startseite & Kalender)
if (isset($_GET['reminders'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $now = new DateTimeImmutable();
    $list = [];
    foreach (cal_items($now->setTime(0, 0), $now->modify('+8 days'), $see_private, ['events']) as $it) {
        if ($it['cancelled'] || $it['start'] < $now) continue;
        $remind = $it['reminder'] > 0 ? $it['start']->modify('-' . $it['reminder'] . ' minutes') : null;
        if ($it['start']->getTimestamp() - $now->getTimestamp() > 86400 && (!$remind || $remind->getTimestamp() - $now->getTimestamp() > 86400)) continue;
        $list[] = ['key' => $it['key'], 'title' => $it['title'], 'start' => $it['start']->format('c'), 'all_day' => $it['all_day'],
                   'remind_at' => $remind ? $remind->format('c') : null, 'location' => $it['location'], 'color' => $it['color']];
    }
    echo json_encode(['now' => $now->format('c'), 'items' => $list], JSON_UNESCAPED_UNICODE);
    exit;
}

// Fällige Abos im Hintergrund dieses Aufrufs abgleichen (max. 3, nach ihrem eigenen Intervall)
$auto_synced = cal_sync_due(3);

// ---------- Ansicht & Zeitraum ----------
$view = in_array($_GET['view'] ?? '', ['month', 'week', 'agenda', 'year'], true) ? $_GET['view'] : 'month';
$anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') && strtotime($_GET['date']) ? new DateTimeImmutable($_GET['date']) : new DateTimeImmutable('today');
$today = new DateTimeImmutable('today');
$q = trim($_GET['q'] ?? '');

switch ($view) {
    case 'week':
        $from = $anchor->modify('monday this week');
        $to = $from->modify('+6 days');
        $prev = $from->modify('-7 days'); $next = $from->modify('+7 days');
        $label = 'KW ' . (int)$from->format('W') . ' · ' . $from->format('d.m.') . ' – ' . $to->format('d.m.Y');
        break;
    case 'agenda':
        $from = $anchor;
        $to = $from->modify('+44 days');
        $prev = $from->modify('-45 days'); $next = $from->modify('+45 days');
        $label = $from->format('d.m.Y') . ' – ' . $to->format('d.m.Y');
        break;
    case 'year':
        $from = $anchor->setDate((int)$anchor->format('Y'), 1, 1);
        $to = $from->setDate((int)$from->format('Y'), 12, 31);
        $prev = $from->modify('-1 year'); $next = $from->modify('+1 year');
        $label = $from->format('Y');
        break;
    default:
        $first = $anchor->modify('first day of this month');
        $from = $first->modify('monday this week');
        $to = $anchor->modify('last day of this month')->modify('sunday this week');
        $prev = $first->modify('-1 month'); $next = $first->modify('+1 month');
        $label = $MONTHS[(int)$first->format('n')] . ' ' . $first->format('Y');
}

$items = cal_items($from, $to, $see_private);
$cats = cal_categories();
$subs = cal_subscriptions();
$sub_errors = array_filter($subs, function ($x) { return $x['enabled'] && $x['last_status'] === 'fehler'; });
$self_url = (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) ? 'https' : 'http')
          . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/calendar.php')), '/') . '/calendar.php';
$state = cal_setting('state', '');

// Seitenleiste: heute + demnächst
$upcoming = array_values(array_filter(cal_items($today, $today->modify('+90 days'), $see_private), function ($it) {
    return $it['end'] >= new DateTimeImmutable();
}));
$today_items = cal_items_on($upcoming, $today->format('Y-m-d'));

// Suche (Titel, Ort, Beschreibung)
$search_results = [];
if ($q !== '') {
    $st = cal_db()->prepare('SELECT * FROM events WHERE (title LIKE :q OR location LIKE :q OR description LIKE :q)' . ($see_private ? '' : ' AND private = 0') . ' ORDER BY start_at DESC LIMIT 100');
    $st->execute([':q' => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%']);
    foreach ($st->fetchAll() as $ev) {
        // nächstes Vorkommen ab heute, sonst das erste
        $occ = cal_expand($ev, $today, $today->modify('+5 years'));
        [$s] = $occ[0] ?? [cal_dt($ev['start_at'])];
        $search_results[] = ['ev' => $ev, 'start' => $s, 'future' => !empty($occ)];
    }
    // Kommende Termine zuerst (nächster oben), danach vergangene (neuester oben)
    usort($search_results, function ($a, $b) {
        if ($a['future'] !== $b['future']) return $b['future'] <=> $a['future'];
        return $a['future'] ? $a['start'] <=> $b['start'] : $b['start'] <=> $a['start'];
    });
}

/** Daten eines Eintrags für das Detail-/Bearbeiten-Fenster (JS) */
function cal_item_json(array $it, string $day): array {
    $d = [
        'key' => $it['key'], 'type' => $it['type'], 'title' => $it['title'], 'color' => $it['color'], 'category' => $it['category'],
        'when' => german_datetime($it['start']->getTimestamp(), !$it['all_day'])
            . ($it['end']->format('Y-m-d') !== $it['start']->format('Y-m-d') ? ' – ' . german_datetime($it['end']->getTimestamp(), !$it['all_day']) : ($it['all_day'] ? '' : ' – ' . $it['end']->format('H:i') . ' Uhr')),
        'location' => $it['location'] ?? '', 'description' => $it['description'] ?? '', 'description_html' => format_text($it['description'] ?? '', ['external' => !empty($it['source'])]), 'url' => $it['url'] ?? '', 'icon' => $it['icon'] ?? '',
        'recurrence' => CAL_RECURRENCE[$it['recurrence'] ?? 'none'] ?? '', 'private' => !empty($it['private']), 'day' => $day,
        'start_iso' => $it['start']->format('c'),
    ];
    if ($it['type'] === 'event') {
        $rs = cal_dt($it['raw_start']); $re = cal_dt($it['raw_end']);
        $d += ['id' => $it['id'], 'all_day' => $it['all_day'], 'rec' => $it['recurrence'], 'recur_interval' => $it['recur_interval'], 'recur_until' => $it['recur_until'] ?? '',
               'category_id' => $it['category_id'], 'start_date' => $rs->format('Y-m-d'), 'start_time' => $rs->format('H:i'),
               'end_date' => $re->format('Y-m-d'), 'end_time' => $re->format('H:i'), 'url' => $it['url'], 'priv' => $it['private'],
               'recur_byday' => $it['recur_byday'], 'recur_count' => $it['recur_count'] ?: '', 'reminder' => $it['reminder'], 'show_age' => $it['show_age'],
               'cancelled' => $it['cancelled'], 'geo' => $it['geo'], 'ext_category' => $it['ext_category'], 'source' => $it['source'],
               'title' => $it['title'], 'base_title' => $it['base_title'],
               'reminder_label' => $it['reminder'] ? CAL_REMINDERS[$it['reminder']] ?? '' : ''];
        $d['recurrence'] = cal_recurrence_text($it);
    }
    return $d;
}

$json_items = [];
/** Filter-Schlüssel (Kategorie-ID bzw. "s<Abo-ID>") */
function cal_filter_key(array $it): string {
    return (string)($it['filter'] ?? $it['type']);
}
/** Eintrag als Chip ausgeben */
function cal_chip(array $it, string $day, bool $admin, bool $compact = true): string {
    global $json_items;
    $json_items[$it['key']] = cal_item_json($it, $day);
    $time = $it['all_day'] ? '' : '<span class="t">' . e(cal_time_label($it, $day) === 'ganztägig' ? '' : $it['start']->format('H:i')) . '</span>';
    $drag = $admin && $it['type'] === 'event' && $it['recurrence'] === 'none' && empty($it['source']) ? ' draggable="true" data-id="' . (int)$it['id'] . '"' : '';
    $icon = $it['type'] === 'countdown' ? e($it['icon']) . ' ' : ($it['type'] === 'holiday' ? '★ ' : (!empty($it['source']) ? '📡 ' : (!empty($it['private']) && $admin ? '🔒 ' : '')));
    return '<button type="button" class="chip ' . $it['type'] . ($it['all_day'] ? ' allday' : '') . (!empty($it['cancelled']) ? ' cancelled' : '') . '" style="--c: ' . e($it['color']) . ';" data-key="' . e($it['key']) . '" data-cat="' . e(cal_filter_key($it)) . '" data-layer="' . $it['type'] . '"' . $drag . ' title="' . e($it['title'] . (!empty($it['cancelled']) ? ' (abgesagt)' : '')) . '">'
        . $time . '<span class="n">' . $icon . e($it['title']) . '</span></button>';
}

$flash = $_GET['msg'] ?? '';
$flash_err = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KALENDER NODE</title>
    <?php echo emoji_favicon('📅'); ?>
    <link rel="stylesheet" href="assets/css/hud.css">
    <link rel="stylesheet" href="assets/css/calendar.css">
</head>
<body>
<?php auth_corner(); ?>
<div class="wrapper cal-wrapper">
    <div class="nav-bar">
        <a href="index.php" class="nav-back">&larr; ZURÜCK ZUM INDEX</a>
        <div class="nav-tabs">
            <?php foreach (['month' => 'Monat', 'week' => 'Woche', 'agenda' => 'Agenda', 'year' => 'Jahr'] as $v => $vl): ?>
                <a href="calendar.php?view=<?php echo $v; ?>&amp;date=<?php echo $anchor->format('Y-m-d'); ?>" class="nav-tab <?php echo $view === $v && $q === '' ? 'active' : ''; ?>" data-key="<?php echo $vl[0]; ?>"><?php echo $vl; ?></a>
            <?php endforeach; ?>
            <a href="countdown.php" class="nav-tab" title="Countdowns verwalten (erscheinen hier im Kalender)">⏳ Countdowns</a>
        </div>
    </div>

    <header class="cal-header">
        <div>
            <div class="hud-tag">System // Temporal Grid · SQLite</div>
            <h1>Kalender</h1>
        </div>
        <?php if ($admin): ?><button type="button" class="btn btn-gold" id="newEventBtn" title="Neuer Termin (Taste N)">＋ Termin</button><?php endif; ?>
    </header>

    <?php if ($flash): ?><div class="cal-flash ok"><?php echo e($flash); ?></div><?php endif; ?>
    <?php if ($flash_err): ?><div class="cal-flash err"><?php echo e($flash_err); ?></div><?php endif; ?>

    <div class="cal-toolbar">
        <div class="cal-nav">
            <a class="btn btn-sm" href="calendar.php?view=<?php echo $view; ?>&amp;date=<?php echo $prev->format('Y-m-d'); ?>" id="prevBtn" title="Zurück (←)">‹</a>
            <a class="btn btn-sm" href="calendar.php?view=<?php echo $view; ?>&amp;date=<?php echo $today->format('Y-m-d'); ?>" id="todayBtn" title="Heute (T)">Heute</a>
            <a class="btn btn-sm" href="calendar.php?view=<?php echo $view; ?>&amp;date=<?php echo $next->format('Y-m-d'); ?>" id="nextBtn" title="Weiter (→)">›</a>
            <span class="cal-label"><?php echo e($q !== '' ? 'Suche: „' . $q . '“' : $label); ?></span>
        </div>
        <form class="cal-search" method="GET">
            <input type="hidden" name="view" value="<?php echo $view; ?>">
            <input type="hidden" name="date" value="<?php echo $anchor->format('Y-m-d'); ?>">
            <input type="month" class="jump" title="Zu Monat springen" value="<?php echo $anchor->format('Y-m'); ?>" onchange="if (this.value) location.href='calendar.php?view=month&date=' + this.value + '-01'">
            <input type="search" name="q" id="calSearch" value="<?php echo e($q); ?>" placeholder="Termine durchsuchen… (/)" autocomplete="off">
        </form>
    </div>

    <?php if ($admin): ?>
        <form method="POST" class="quick-add" id="quickAdd">
            <input type="hidden" name="action" value="quick_add"><input type="hidden" name="view" value="<?php echo $view; ?>"><input type="hidden" name="date" value="<?php echo $anchor->format('Y-m-d'); ?>">
            <span class="qa-icon" aria-hidden="true">⚡</span>
            <input type="text" name="text" id="quickText" maxlength="300" autocomplete="off" required
                   placeholder="Schnell-Eingabe: „Morgen 19 Uhr Stammtisch @Stuttgart #Con“ · „jeden 2. Samstag 15 Uhr Walk“ · „24.12. Heiligabend“">
            <button type="submit" class="btn btn-sm btn-gold">Anlegen</button>
            <button type="button" class="btn btn-sm" id="quickHelpBtn" title="Was verstehe ich?">?</button>
        </form>
        <div class="quick-help" id="quickHelp" hidden>
            <b>Datum:</b> heute, morgen, übermorgen, Freitag, 12.11., 12.11.2026 &nbsp;·&nbsp;
            <b>Zeit:</b> 19 Uhr, 19:30, 18-22 Uhr, von 19 bis 23 Uhr, ganztägig &nbsp;·&nbsp;
            <b>Wiederholung:</b> jeden Freitag, jeden 2. Samstag, jeden letzten Freitag, wöchentlich, monatlich, jährlich &nbsp;·&nbsp;
            <b>Ort:</b> @Stuttgart &nbsp;·&nbsp; <b>Kategorie:</b> #Con &nbsp;·&nbsp; <b>Sichtbar für alle:</b> !öffentlich
        </div>
        <?php foreach ($sub_errors as $se): ?>
            <div class="cal-flash err">📡 Abo „<?php echo e($se['name'] ?: $se['url']); ?>“: <?php echo e($se['last_error']); ?></div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="cal-layout">
        <main class="cal-main">
            <?php if ($q !== ''): ?>
                <!-- ===== Suchergebnisse ===== -->
                <div class="panel">
                    <div class="panel-title"><span>// <?php echo count($search_results); ?> Treffer</span><a href="calendar.php?view=<?php echo $view; ?>&amp;date=<?php echo $anchor->format('Y-m-d'); ?>" class="btn btn-sm">Suche beenden</a></div>
                    <?php if (!$search_results): ?><div class="empty-msg">Keine Termine gefunden.</div><?php endif; ?>
                    <div class="agenda">
                        <?php foreach ($search_results as $r): $ev = $r['ev']; $c = $cats[(int)$ev['category_id']]['color'] ?? '#8b949e'; ?>
                            <a class="agenda-row" style="--c: <?php echo e($c); ?>;" href="calendar.php?view=month&amp;date=<?php echo $r['start']->format('Y-m-d'); ?>">
                                <span class="agenda-time"><?php echo e($r['start']->format('d.m.Y')); ?><br><small><?php echo $ev['all_day'] ? 'ganztägig' : $r['start']->format('H:i'); ?></small></span>
                                <span class="agenda-body"><strong><?php echo e($ev['title']); ?></strong>
                                    <small><?php echo e(implode(' · ', array_filter([$cats[(int)$ev['category_id']]['name'] ?? '', $ev['location'], $ev['recurrence'] !== 'none' ? CAL_RECURRENCE[$ev['recurrence']] : '', $r['future'] ? '' : 'vergangen']))); ?></small></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

            <?php elseif ($view === 'month'): ?>
                <!-- ===== Monat ===== -->
                <div class="month" role="grid" aria-label="<?php echo e($label); ?>">
                    <div class="month-head"><span class="kw">KW</span><?php foreach ($WEEKDAYS as $wd): ?><span><?php echo $wd; ?></span><?php endforeach; ?></div>
                    <?php for ($w = $from; $w <= $to; $w = $w->modify('+7 days')): ?>
                        <div class="month-row">
                            <a class="kw" href="calendar.php?view=week&amp;date=<?php echo $w->format('Y-m-d'); ?>" title="Woche anzeigen"><?php echo (int)$w->format('W'); ?></a>
                            <?php for ($i = 0; $i < 7; $i++): $d = $w->modify("+$i days"); $ds = $d->format('Y-m-d'); $day_items = cal_items_on($items, $ds);
                                $is_holiday = (bool)array_filter($day_items, function ($x) { return $x['type'] === 'holiday'; }); ?>
                                <div class="day <?php echo $d->format('n') !== $anchor->format('n') ? 'other' : ''; ?> <?php echo $ds === $today->format('Y-m-d') ? 'today' : ''; ?> <?php echo $i >= 5 ? 'weekend' : ''; ?> <?php echo $is_holiday ? 'holiday' : ''; ?>" data-day="<?php echo $ds; ?>" role="gridcell">
                                    <div class="day-num"><span><?php echo (int)$d->format('j'); ?></span><?php if (count($day_items) > 3): ?><button type="button" class="more" data-more="<?php echo $ds; ?>">+<?php echo count($day_items) - 3; ?></button><?php endif; ?></div>
                                    <div class="chips">
                                        <?php foreach ($day_items as $n => $it): ?>
                                            <?php echo $n < 3 ? cal_chip($it, $ds, $admin) : '<span hidden>' . cal_chip($it, $ds, $admin) . '</span>'; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endfor; ?>
                        </div>
                    <?php endfor; ?>
                </div>

            <?php elseif ($view === 'week'): ?>
                <!-- ===== Woche (mit Zeitleiste) ===== -->
                <?php $hour_h = 44; ?>
                <div class="week" style="--hour: <?php echo $hour_h; ?>px;">
                    <div class="week-head">
                        <span></span>
                        <?php for ($i = 0; $i < 7; $i++): $d = $from->modify("+$i days"); ?>
                            <a href="calendar.php?view=agenda&amp;date=<?php echo $d->format('Y-m-d'); ?>" class="<?php echo $d == $today ? 'today' : ''; ?>"><?php echo $WEEKDAYS[$i]; ?> <b><?php echo $d->format('j'); ?></b><small><?php echo $MONTHS[(int)$d->format('n')]; ?></small></a>
                        <?php endfor; ?>
                    </div>
                    <div class="week-allday">
                        <span class="lbl">ganzt.</span>
                        <?php for ($i = 0; $i < 7; $i++): $ds = $from->modify("+$i days")->format('Y-m-d'); ?>
                            <div class="day" data-day="<?php echo $ds; ?>">
                                <?php foreach (cal_items_on($items, $ds) as $it) if ($it['all_day'] || $it['start']->format('Y-m-d') !== $it['end']->format('Y-m-d')) echo cal_chip($it, $ds, $admin); ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                    <div class="week-grid" id="weekGrid">
                        <div class="hours"><?php for ($h = 0; $h < 24; $h++): ?><span><?php echo sprintf('%02d:00', $h); ?></span><?php endfor; ?></div>
                        <?php for ($i = 0; $i < 7; $i++): $d = $from->modify("+$i days"); $ds = $d->format('Y-m-d');
                            // Zeitlich überlappende Termine nebeneinander (Spuren)
                            $timed = array_values(array_filter(cal_items_on($items, $ds), function ($it) { return !$it['all_day'] && $it['start']->format('Y-m-d') === $it['end']->format('Y-m-d'); }));
                            $lanes = []; $lane_of = [];
                            foreach ($timed as $k => $it) {
                                $placed = false;
                                foreach ($lanes as $li => $end) if ($end <= $it['start']) { $lanes[$li] = $it['end']; $lane_of[$k] = $li; $placed = true; break; }
                                if (!$placed) { $lane_of[$k] = count($lanes); $lanes[] = $it['end']; }
                            }
                            $lane_count = max(1, count($lanes)); ?>
                            <div class="week-col <?php echo $ds === $today->format('Y-m-d') ? 'today' : ''; ?> <?php echo $i >= 5 ? 'weekend' : ''; ?>" data-day="<?php echo $ds; ?>">
                                <?php foreach ($timed as $k => $it):
                                    $top = ((int)$it['start']->format('G') * 60 + (int)$it['start']->format('i')) / 60 * $hour_h;
                                    $height = max(22, ($it['end']->getTimestamp() - $it['start']->getTimestamp()) / 3600 * $hour_h);
                                    global $json_items; $json_items[$it['key']] = cal_item_json($it, $ds); ?>
                                    <button type="button" class="block <?php echo $it['type']; ?><?php echo !empty($it['cancelled']) ? ' cancelled' : ''; ?>" data-key="<?php echo e($it['key']); ?>" data-cat="<?php echo e(cal_filter_key($it)); ?>" data-layer="<?php echo $it['type']; ?>"
                                            style="--c: <?php echo e($it['color']); ?>; top: <?php echo $top; ?>px; height: <?php echo $height; ?>px; left: calc(<?php echo $lane_of[$k] / $lane_count * 100; ?>% + 2px); width: calc(<?php echo 100 / $lane_count; ?>% - 4px);">
                                        <b><?php echo e(($it['type'] === 'countdown' ? $it['icon'] . ' ' : '') . $it['title']); ?></b>
                                        <small><?php echo e(cal_time_label($it, $ds)); ?><?php echo !empty($it['location']) ? ' · ' . e($it['location']) : ''; ?></small>
                                    </button>
                                <?php endforeach; ?>
                                <?php if ($ds === $today->format('Y-m-d')): ?><div class="now-line" id="nowLine"></div><?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>

            <?php elseif ($view === 'agenda'): ?>
                <!-- ===== Agenda ===== -->
                <div class="panel agenda-panel">
                    <?php $any = false; for ($d = $from; $d <= $to; $d = $d->modify('+1 day')): $ds = $d->format('Y-m-d'); $day_items = cal_items_on($items, $ds); if (!$day_items) continue; $any = true;
                        $diff = (int)$today->diff($d)->format('%r%a'); ?>
                        <div class="agenda-day <?php echo $ds === $today->format('Y-m-d') ? 'today' : ''; ?>">
                            <div class="agenda-date">
                                <b><?php echo $d->format('j'); ?></b>
                                <span><?php echo $WEEKDAYS_LONG[(int)$d->format('N') - 1]; ?><br><?php echo $MONTHS[(int)$d->format('n')] . ' ' . $d->format('Y'); ?></span>
                                <em><?php echo $diff === 0 ? 'heute' : ($diff === 1 ? 'morgen' : ($diff === -1 ? 'gestern' : ($diff > 0 ? "in $diff Tagen" : 'vor ' . -$diff . ' Tagen'))); ?></em>
                            </div>
                            <div class="agenda-list">
                                <?php foreach ($day_items as $it): $json_items[$it['key']] = cal_item_json($it, $ds); ?>
                                    <button type="button" class="agenda-row <?php echo $it['type']; ?><?php echo !empty($it['cancelled']) ? ' cancelled' : ''; ?>" style="--c: <?php echo e($it['color']); ?>;" data-key="<?php echo e($it['key']); ?>" data-cat="<?php echo e(cal_filter_key($it)); ?>" data-layer="<?php echo $it['type']; ?>">
                                        <span class="agenda-time"><?php echo e(cal_time_label($it, $ds)); ?></span>
                                        <span class="agenda-body"><strong><?php echo e(($it['type'] === 'countdown' ? $it['icon'] . ' ' : ($it['type'] === 'holiday' ? '★ ' : '')) . $it['title']); ?></strong>
                                            <small><?php echo e(implode(' · ', array_filter([$it['category'], $it['location'] ?? '', $it['recurrence'] ?? '' ? (CAL_RECURRENCE[$it['recurrence']] ?? '') : '']))); ?></small></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endfor; ?>
                    <?php if (!$any): ?><div class="empty-msg">In diesem Zeitraum stehen keine Termine an.</div><?php endif; ?>
                </div>

            <?php else: ?>
                <!-- ===== Jahr ===== -->
                <div class="year">
                    <?php for ($m = 1; $m <= 12; $m++): $first = $from->setDate((int)$from->format('Y'), $m, 1); $start = $first->modify('monday this week'); ?>
                        <div class="mini">
                            <a class="mini-title" href="calendar.php?view=month&amp;date=<?php echo $first->format('Y-m-d'); ?>"><?php echo $MONTHS[$m]; ?></a>
                            <div class="mini-grid">
                                <?php foreach ($WEEKDAYS as $wd): ?><span class="wd"><?php echo $wd[0]; ?></span><?php endforeach; ?>
                                <?php for ($d = $start; $d <= $first->modify('last day of this month'); $d = $d->modify('+1 day')):
                                    $ds = $d->format('Y-m-d');
                                    if ($d->format('n') != $m) { echo '<span></span>'; continue; }
                                    $di = cal_items_on($items, $ds);
                                    $hol = array_filter($di, function ($x) { return $x['type'] === 'holiday'; });
                                    $dots = array_slice(array_filter($di, function ($x) { return $x['type'] !== 'holiday'; }), 0, 3); ?>
                                    <a href="calendar.php?view=agenda&amp;date=<?php echo $ds; ?>" class="mini-day <?php echo $ds === $today->format('Y-m-d') ? 'today' : ''; ?> <?php echo $hol ? 'holiday' : ''; ?> <?php echo $d->format('N') >= 6 ? 'weekend' : ''; ?>"
                                       title="<?php echo e(implode("\n", array_map(function ($x) { return ($x['all_day'] ? '' : $x['start']->format('H:i') . ' ') . $x['title']; }, $di))); ?>">
                                        <?php echo (int)$d->format('j'); ?>
                                        <?php if ($dots): ?><i><?php foreach ($dots as $x): ?><b style="background: <?php echo e($x['color']); ?>;"></b><?php endforeach; ?></i><?php endif; ?>
                                    </a>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </main>

        <aside class="cal-side">
            <div class="panel side-today">
                <div class="panel-title"><span>// Heute</span><span><?php echo e(german_datetime($today->getTimestamp(), false)); ?></span></div>
                <?php if (!$today_items): ?><div class="side-empty">Keine Termine heute.</div><?php endif; ?>
                <?php foreach ($today_items as $it): $json_items[$it['key']] = cal_item_json($it, $today->format('Y-m-d')); ?>
                    <button type="button" class="side-item" style="--c: <?php echo e($it['color']); ?>;" data-key="<?php echo e($it['key']); ?>"><span><?php echo e(cal_time_label($it, $today->format('Y-m-d'))); ?></span><b><?php echo e(($it['icon'] ?? '') . ' ' . $it['title']); ?></b></button>
                <?php endforeach; ?>
            </div>

            <div class="panel">
                <div class="panel-title"><span>// Demnächst</span></div>
                <?php $shown = 0; foreach ($upcoming as $it): if ($it['start']->format('Y-m-d') <= $today->format('Y-m-d') || $shown >= 8) continue; $shown++;
                    $json_items[$it['key']] = cal_item_json($it, $it['start']->format('Y-m-d')); ?>
                    <button type="button" class="side-item" style="--c: <?php echo e($it['color']); ?>;" data-key="<?php echo e($it['key']); ?>">
                        <span><?php echo e($it['start']->format('d.m.')); ?> · <?php echo e(relative_days($it['start']->getTimestamp())); ?></span><b><?php echo e(($it['icon'] ?? '') . ' ' . $it['title']); ?></b>
                    </button>
                <?php endforeach; ?>
                <?php if (!$shown): ?><div class="side-empty">Nichts in den nächsten 90 Tagen.</div><?php endif; ?>
            </div>

            <div class="panel">
                <div class="panel-title"><span>// Ebenen &amp; Kategorien</span></div>
                <label class="toggle"><input type="checkbox" data-layer-toggle="event" checked> Termine</label>
                <label class="toggle"><input type="checkbox" data-layer-toggle="holiday" checked> <span class="sw" style="background: #ff5555;"></span> Feiertage <small><?php echo e($state ? $state : 'bundesweit'); ?></small></label>
                <label class="toggle"><input type="checkbox" data-layer-toggle="countdown" checked> <span class="sw" style="background: #f3c262;"></span> Countdowns <small>nur lesend</small></label>
                <hr>
                <?php foreach ($cats as $c): ?>
                    <label class="toggle"><input type="checkbox" data-cat-toggle="<?php echo (int)$c['id']; ?>" checked> <span class="sw" style="background: <?php echo e($c['color']); ?>;"></span> <?php echo e($c['name']); ?></label>
                <?php endforeach; ?>
                <label class="toggle"><input type="checkbox" data-cat-toggle="0" checked> <span class="sw" style="background: #8b949e;"></span> Ohne Kategorie</label>
                <?php if ($subs): ?>
                    <hr>
                    <?php foreach ($subs as $sb): if (!$sb['enabled'] || ($sb['private'] && !$see_private)) continue; ?>
                        <label class="toggle"><input type="checkbox" data-cat-toggle="s<?php echo (int)$sb['id']; ?>" checked> <span class="sw" style="background: <?php echo e($sb['color']); ?>;"></span> 📡 <?php echo e($sb['name'] ?: 'Abo'); ?> <small><?php echo (int)$sb['event_count']; ?></small></label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="panel-title"><span>// Erinnerungen</span></div>
                <button type="button" class="btn btn-sm" id="notifyBtn" style="width: 100%;">🔔 Browser-Benachrichtigungen aktivieren</button>
                <p class="side-note" id="notifyNote">Bei Terminen mit Erinnerung meldet sich der Browser, solange Startseite oder Kalender geöffnet sind.</p>
            </div>

            <?php if ($manage_subs): ?>
                <div class="panel">
                    <div class="panel-title"><span>// 📡 Kalender-Abos</span><button type="button" class="btn btn-sm btn-gold" data-sub-edit="new" title="Kalender per URL abonnieren">＋</button></div>
                    <?php if (!$subs): ?><p class="side-note" style="margin-top: 0;">Fremde Kalender per Link abonnieren – z. B. Forum-Kalender (WoltLab), Google, Outlook, Nextcloud. Sie werden automatisch aktuell gehalten.</p><?php endif; ?>
                    <?php foreach ($subs as $sb): ?>
                        <div class="sub-row <?php echo $sb['enabled'] ? '' : 'off'; ?>" style="--c: <?php echo e($sb['color']); ?>;">
                            <div class="sub-head">
                                <b><?php echo e($sb['name'] ?: 'Abo'); ?></b>
                                <span class="sub-state <?php echo $sb['last_status'] === 'fehler' ? 'bad' : ''; ?>" title="<?php echo e($sb['last_error']); ?>">
                                    <?php echo !$sb['enabled'] ? 'pausiert' : ($sb['last_status'] === 'fehler' ? '⚠ Fehler' : (int)$sb['event_count'] . ' Termine'); ?>
                                </span>
                            </div>
                            <small>
                                <?php echo $sb['last_sync'] ? 'Abgleich ' . e(date('d.m. H:i', strtotime($sb['last_sync']))) : 'noch nie'; ?>
                                · alle <?php echo (int)$sb['refresh_minutes'] >= 60 ? round($sb['refresh_minutes'] / 60, 1) . ' Std.' : (int)$sb['refresh_minutes'] . ' Min.'; ?>
                                · <?php echo $sb['private'] ? '🔒 privat' : 'öffentlich'; ?>
                            </small>
                            <div class="sub-actions">
                                <form method="POST"><input type="hidden" name="action" value="sub_sync"><input type="hidden" name="sid" value="<?php echo (int)$sb['id']; ?>"><input type="hidden" name="view" value="<?php echo $view; ?>"><input type="hidden" name="date" value="<?php echo $anchor->format('Y-m-d'); ?>"><button type="submit" class="btn btn-sm" title="Jetzt abgleichen">⟳</button></form>
                                <button type="button" class="btn btn-sm" title="Bearbeiten" data-sub-edit="<?php echo e(json_encode(array_intersect_key($sb, array_flip(['id', 'name', 'url', 'color', 'enabled', 'private', 'refresh_minutes', 'skip_past_days'])))); ?>">✎</button>
                                <form method="POST" onsubmit="return confirm('Abo „<?php echo e(addslashes($sb['name'])); ?>“ und alle seine Termine entfernen?');"><input type="hidden" name="action" value="sub_delete"><input type="hidden" name="sid" value="<?php echo (int)$sb['id']; ?>"><input type="hidden" name="view" value="<?php echo $view; ?>"><input type="hidden" name="date" value="<?php echo $anchor->format('Y-m-d'); ?>"><button type="submit" class="btn btn-sm btn-danger" title="Entfernen">✕</button></form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="panel">
                    <div class="panel-title"><span>// Kalender aufs Handy</span></div>
                    <p class="side-note" style="margin-top: 0;">Diesen Link in Google Kalender („Per URL hinzufügen“), Apple Kalender („Kalenderabo“) oder Outlook eintragen – er bleibt automatisch aktuell. Link geheim halten!</p>
                    <?php foreach (['public' => 'Nur öffentliche Termine', 'private' => 'Alle Termine (inkl. privat)'] as $kind => $klabel): $feed = $self_url . '?feed=' . cal_feed_token($kind); ?>
                        <label class="field" style="margin-top: 10px;"><?php echo $klabel; ?></label>
                        <div class="feed-link">
                            <input type="text" readonly value="<?php echo e($feed); ?>" onclick="this.select()">
                            <button type="button" class="btn btn-sm" data-copy="<?php echo e($feed); ?>" title="Kopieren">⧉</button>
                            <a class="btn btn-sm" href="<?php echo e(preg_replace('#^https?://#', 'webcal://', $feed)); ?>" title="Direkt im Kalender-Programm öffnen">↗</a>
                            <form method="POST" onsubmit="return confirm('Neuen Link erzeugen? Der alte hört sofort auf zu funktionieren.');"><input type="hidden" name="action" value="feed_token"><input type="hidden" name="kind" value="<?php echo $kind; ?>"><button type="submit" class="btn btn-sm btn-danger" title="Neuen Link erzeugen">↻</button></form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="panel">
                <div class="panel-title"><span>// Export<?php echo $admin ? ' &amp; Import' : ''; ?></span></div>
                <a class="btn btn-sm" style="width: 100%; text-align: center;" href="calendar.php?export=ics">⬇ Kalender als .ics</a>
                <p class="side-note">Für Google, Apple, Outlook, Thunderbird.<?php echo $see_private ? ' Enthält auch private Termine.' : ''; ?></p>
                <?php if ($admin): ?>
                    <form method="POST" enctype="multipart/form-data" class="side-form">
                        <input type="hidden" name="action" value="import_ics"><input type="hidden" name="view" value="<?php echo $view; ?>"><input type="hidden" name="date" value="<?php echo $anchor->format('Y-m-d'); ?>">
                        <input type="file" name="ics" accept=".ics,text/calendar" required>
                        <select name="category_id"><option value="0">Kategorie: keine</option><?php foreach ($cats as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo e($c['name']); ?></option><?php endforeach; ?></select>
                        <label class="toggle"><input type="checkbox" name="private" value="1" checked> als privat importieren</label>
                        <button type="submit" class="btn btn-sm">⬆ .ics importieren</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($admin): ?>
                <details class="panel">
                    <summary class="panel-title"><span>// Einstellungen</span></summary>
                    <form method="POST" class="side-form">
                        <input type="hidden" name="action" value="save_settings"><input type="hidden" name="view" value="<?php echo $view; ?>"><input type="hidden" name="date" value="<?php echo $anchor->format('Y-m-d'); ?>">
                        <label class="field">Feiertage für</label>
                        <select name="state"><?php foreach (CAL_STATES as $k => $n): ?><option value="<?php echo $k; ?>" <?php echo $k === $state ? 'selected' : ''; ?>><?php echo e($n); ?></option><?php endforeach; ?></select>
                        <label class="toggle"><input type="checkbox" name="show_holidays" value="1" <?php echo cal_setting('show_holidays', '1') === '1' ? 'checked' : ''; ?>> Feiertage anzeigen</label>
                        <button type="submit" class="btn btn-sm">Speichern</button>
                    </form>
                    <label class="field" style="margin-top: 16px;">Kategorien</label>
                    <?php foreach ($cats as $c): ?>
                        <form method="POST" class="cat-row">
                            <input type="hidden" name="action" value="save_category"><input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>"><input type="hidden" name="view" value="<?php echo $view; ?>"><input type="hidden" name="date" value="<?php echo $anchor->format('Y-m-d'); ?>">
                            <input type="color" name="color" value="<?php echo e($c['color']); ?>" title="Farbe">
                            <input type="text" name="name" value="<?php echo e($c['name']); ?>" maxlength="40">
                            <button type="submit" class="btn btn-sm" title="Speichern">✓</button>
                            <button type="submit" class="btn btn-sm btn-danger" title="Löschen" formaction="calendar.php" name="action" value="delete_category" onclick="return confirm('Kategorie löschen? Termine bleiben erhalten.');">✕</button>
                        </form>
                    <?php endforeach; ?>
                    <form method="POST" class="cat-row">
                        <input type="hidden" name="action" value="save_category"><input type="hidden" name="view" value="<?php echo $view; ?>"><input type="hidden" name="date" value="<?php echo $anchor->format('Y-m-d'); ?>">
                        <input type="color" name="color" value="#38d5e6"><input type="text" name="name" placeholder="Neue Kategorie" maxlength="40" required>
                        <button type="submit" class="btn btn-sm btn-gold">＋</button>
                    </form>
                </details>
            <?php endif; ?>

            <p class="side-note keys">Tasten: <kbd>←</kbd><kbd>→</kbd> blättern · <kbd>T</kbd> heute · <kbd>M</kbd><kbd>W</kbd><kbd>A</kbd><kbd>J</kbd> Ansicht<?php echo $admin ? ' · <kbd>N</kbd> neuer Termin' : ''; ?> · <kbd>/</kbd> Suche</p>
        </aside>
    </div>
</div>

<!-- Detailansicht -->
<div class="modal-overlay" id="detailModal" role="dialog" aria-modal="true">
    <div class="modal-box">
        <button type="button" class="modal-close" data-close aria-label="Schließen">&times;</button>
        <div class="detail-type" id="dType"></div>
        <h2 class="detail-title" id="dTitle"></h2>
        <div class="detail-when" id="dWhen"></div>
        <dl class="detail-list" id="dList"></dl>
        <div class="detail-actions" id="dActions"></div>
    </div>
</div>

<!-- Tagesliste ("+N mehr") -->
<div class="modal-overlay" id="dayModal" role="dialog" aria-modal="true">
    <div class="modal-box">
        <button type="button" class="modal-close" data-close aria-label="Schließen">&times;</button>
        <div class="panel-title" id="dayTitle"></div>
        <div id="dayList" class="day-list"></div>
        <?php if ($admin): ?><button type="button" class="btn btn-gold btn-sm" id="dayNew" style="margin-top: 12px;">＋ Termin an diesem Tag</button><?php endif; ?>
    </div>
</div>

<?php if ($admin): ?>
<!-- Termin anlegen / bearbeiten -->
<div class="modal-overlay" id="eventModal" role="dialog" aria-modal="true">
    <form method="POST" class="modal-box event-form" id="eventForm">
        <button type="button" class="modal-close" data-close aria-label="Schließen">&times;</button>
        <div class="panel-title" id="eventFormTitle">// Neuer Termin</div>
        <input type="hidden" name="action" value="save_event">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="view" value="<?php echo $view; ?>">
        <input type="hidden" name="date" value="<?php echo $anchor->format('Y-m-d'); ?>">

        <label class="field">Titel *</label>
        <input type="text" name="title" maxlength="150" required placeholder="z. B. Stammtisch, Arzttermin, Geburtstag Luna">

        <label class="toggle" style="margin: 10px 0;"><input type="checkbox" name="all_day" value="1" id="fAllDay"> Ganztägig</label>
        <div class="f-grid">
            <div><label class="field">Beginn</label><input type="date" name="start_date" required></div>
            <div class="time-f"><label class="field">Uhrzeit</label><input type="time" name="start_time" value="10:00"></div>
            <div><label class="field">Ende</label><input type="date" name="end_date"></div>
            <div class="time-f"><label class="field">Uhrzeit</label><input type="time" name="end_time" value="11:00"></div>
        </div>

        <div class="f-grid two">
            <div><label class="field">Kategorie</label>
                <select name="category_id"><option value="0">– keine –</option><?php foreach ($cats as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo e($c['name']); ?></option><?php endforeach; ?></select></div>
            <div><label class="field">Ort</label><input type="text" name="location" maxlength="200" placeholder="Adresse oder Online"></div>
        </div>

        <div class="f-grid two">
            <div><label class="field">Wiederholen</label>
                <select name="recurrence" id="fRec"><?php foreach (CAL_RECURRENCE as $k => $n): ?><option value="<?php echo $k; ?>"><?php echo $n; ?></option><?php endforeach; ?></select></div>
            <div class="rec-f"><label class="field">Alle …</label><div class="inline-unit"><input type="number" name="recur_interval" min="1" max="99" value="1"><span id="fRecUnit">Wochen</span></div></div>
        </div>
        <div class="rec-f rec-weekly">
            <label class="field">An diesen Tagen</label>
            <div class="weekday-pick">
                <?php foreach (CAL_WEEKDAY_CODES as $code => $num): ?><label><input type="checkbox" name="byday[]" value="<?php echo $code; ?>"><span><?php echo mb_substr(CAL_WEEKDAY_NAMES[$num], 0, 2); ?></span></label><?php endforeach; ?>
            </div>
        </div>
        <div class="rec-f rec-monthly">
            <label class="field">Monatlich am</label>
            <select name="monthly_mode" id="fMonthly"></select>
        </div>
        <div class="rec-f rec-end">
            <label class="field">Endet</label>
            <div class="end-pick">
                <label><input type="radio" name="recur_end" value="never" checked> nie</label>
                <label><input type="radio" name="recur_end" value="until"> am <input type="date" name="recur_until"></label>
                <label><input type="radio" name="recur_end" value="count"> nach <input type="number" name="recur_count" min="1" max="999" value="10" style="width: 70px;"> Terminen</label>
            </div>
        </div>
        <label class="toggle rec-yearly" style="margin-top: 8px;"><input type="checkbox" name="show_age" value="1"> Jahre zählen – zeigt z. B. „Luna (30)“ beim Geburtstag (Startjahr = Geburtsjahr)</label>

        <div class="f-grid two">
            <div><label class="field">🔔 Erinnerung</label>
                <select name="reminder"><?php foreach (CAL_REMINDERS as $min => $label): ?><option value="<?php echo $min; ?>"><?php echo e($label); ?></option><?php endforeach; ?></select></div>
            <div></div>
        </div>

        <label class="field">Link</label>
        <input type="url" name="url" maxlength="500" placeholder="https://…">
        <label class="field" style="margin-top: 10px;">Notizen</label>
        <textarea name="description" rows="4" maxlength="4000" placeholder="Details, Ticketnummer, Mitbringen … (Markdown, BBCode & HTML möglich)" data-format="text"></textarea>

        <label class="toggle" style="margin: 12px 0;"><input type="checkbox" name="private" value="1" checked> 🔒 Privat – nur angemeldet sichtbar</label>
        <p class="side-note" id="seriesNote" hidden>Änderungen gelten für die ganze Serie.</p>
        <button type="submit" class="btn btn-gold" style="width: 100%;">Speichern</button>
    </form>
</div>

<?php endif; ?>
<?php if ($manage_subs): ?>
<div class="modal-overlay" id="subModal" role="dialog" aria-modal="true">
    <form method="POST" class="modal-box event-form" id="subForm">
        <button type="button" class="modal-close" data-close aria-label="Schließen">&times;</button>
        <div class="panel-title" id="subFormTitle">// Kalender abonnieren</div>
        <input type="hidden" name="action" value="sub_save"><input type="hidden" name="sid" value="">
        <input type="hidden" name="view" value="<?php echo $view; ?>"><input type="hidden" name="date" value="<?php echo $anchor->format('Y-m-d'); ?>">
        <label class="field">Kalender-Adresse (iCal / .ics / webcal) *</label>
        <input type="text" name="url" required placeholder="https://forum.example.de/calendar/calendar-export/…  oder  webcal://…" autocomplete="off" spellcheck="false">
        <p class="side-note">Im Forum meist unter Kalender → „Exportieren“/„Abonnieren“, bei Google unter „Geheime Adresse im iCal-Format“.</p>
        <div class="f-grid two">
            <div><label class="field">Name (leer = aus dem Kalender)</label><input type="text" name="name" maxlength="80" placeholder="z. B. SüdStaaten Forum"></div>
            <div><label class="field">Farbe</label><input type="color" name="color" value="#bd93f9" style="height: 40px; padding: 2px;"></div>
        </div>
        <div class="f-grid two">
            <div><label class="field">Aktualisieren alle</label>
                <select name="refresh_minutes"><?php foreach ([15 => '15 Minuten', 30 => '30 Minuten', 60 => '1 Stunde', 180 => '3 Stunden', 360 => '6 Stunden', 720 => '12 Stunden', 1440 => '1 Tag', 10080 => '1 Woche'] as $m => $l): ?><option value="<?php echo $m; ?>" <?php echo $m === 60 ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
            <div><label class="field">Alte Termine</label>
                <select name="skip_past_days"><?php foreach ([0 => 'alle übernehmen', 30 => 'älter als 1 Monat weglassen', 365 => 'älter als 1 Jahr weglassen', 730 => 'älter als 2 Jahre weglassen'] as $d => $l): ?><option value="<?php echo $d; ?>"><?php echo $l; ?></option><?php endforeach; ?></select></div>
        </div>
        <label class="toggle" style="margin-top: 10px;"><input type="checkbox" name="private" value="1" checked> 🔒 Privat – Termine nur angemeldet sichtbar (Abo-Links enthalten oft persönliche Zugangsschlüssel)</label>
        <label class="toggle" id="subEnabledRow"><input type="checkbox" name="enabled" value="1" checked> Aktiv (pausierte Abos werden ausgeblendet und nicht abgeglichen)</label>
        <button type="submit" class="btn btn-gold" style="width: 100%; margin-top: 10px;">Speichern &amp; jetzt abgleichen</button>
    </form>
</div>

<?php endif; ?>
<?php if ($admin): ?>
<form method="POST" id="moveForm" hidden>
    <input type="hidden" name="action" value="move_event"><input type="hidden" name="id"><input type="hidden" name="to_day">
    <input type="hidden" name="view" value="<?php echo $view; ?>"><input type="hidden" name="date" value="<?php echo $anchor->format('Y-m-d'); ?>">
</form>
<form method="POST" id="deleteForm" hidden>
    <input type="hidden" name="action" value="delete_event"><input type="hidden" name="id"><input type="hidden" name="scope"><input type="hidden" name="day">
    <input type="hidden" name="view" value="<?php echo $view; ?>"><input type="hidden" name="date" value="<?php echo $anchor->format('Y-m-d'); ?>">
</form>
<?php endif; ?>

<script>
    window.CAL = {
        items: <?php echo json_encode($json_items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>,
        admin: <?php echo $admin ? 'true' : 'false'; ?>,
        view: <?php echo json_encode($view); ?>,
        today: <?php echo json_encode($today->format('Y-m-d')); ?>,
        anchor: <?php echo json_encode($anchor->format('Y-m-d')); ?>
    };
</script>
<script src="assets/js/calendar.js"></script>
<?php if ($admin): ?><script src="assets/js/editor.js"></script><?php endif; ?>
<script src="assets/js/reminders.js" data-endpoint="calendar.php?reminders=1"></script>
</body>
</html>
