<?php
/**
 * Con-Rechner: mehrere Conventions planen – Budget, Ausgaben (auch in Fremdwährung), Mitreisende mit
 * automatischer Abrechnung „wer schuldet wem“, Rechner (Zimmer, Auto, Sparplan …), Zeitplan, Packliste,
 * Wunschliste für den Dealers Den, Notizen, Vergleich aller Cons sowie Countdown- und Kalender-Anbindung.
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/auth.php';
require_once __DIR__ . '/inc/modules/con.php';
require_once __DIR__ . '/inc/core/templates.php';

require_permission('con.access');
guard_post('con.access');

$data = con_load();
$cid = (string)($_GET['c'] ?? $_POST['con'] ?? $data['active']);
if (con_index($data, $cid) === null) $cid = $data['active'];
if ($cid !== $data['active'] && $_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['export'])) {   // zuletzt angesehene Con = Haupt-Con (Index-Kachel)
    $data['active'] = $cid;
    con_save($data);
}
$ci = con_index($data, $cid);

// ---------- Exporte ----------
if (isset($_GET['export'])) {
    $con = $data['cons'][$ci];
    $slug = preg_replace('/[^a-z0-9]+/i', '_', $con['name']);
    if ($_GET['export'] === 'ics') {
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $slug . '_zeitplan.ics"');
        $esc = function ($s) { return str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], (string)$s); };
        $out = ["BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//GhostNode//Con-Rechner//DE", "CALSCALE:GREGORIAN"];
        if ($con['start']) {
            $out = array_merge($out, ["BEGIN:VEVENT", "UID:con-" . $con['id'] . "@ghostnode", "DTSTAMP:" . gmdate('Ymd\THis\Z'), "DTSTART;VALUE=DATE:" . str_replace('-', '', $con['start']),
                                      "DTEND;VALUE=DATE:" . date('Ymd', strtotime(($con['end'] ?: $con['start']) . ' +1 day')), "SUMMARY:" . $esc($con['icon'] . ' ' . $con['name']), "LOCATION:" . $esc(trim($con['venue'] . ', ' . $con['city'], ', ')), "END:VEVENT"]);
        }
        foreach ($con['schedule'] as $s) {
            if (!$s['date']) continue;
            $st = str_replace('-', '', $s['date']) . 'T' . str_replace(':', '', $s['time'] ?: '10:00') . '00';
            $en = str_replace('-', '', $s['date']) . 'T' . str_replace(':', '', $s['end'] ?: date('H:i', strtotime(($s['time'] ?: '10:00') . ' +1 hour'))) . '00';
            $out = array_merge($out, ["BEGIN:VEVENT", "UID:" . $s['id'] . "@ghostnode", "DTSTAMP:" . gmdate('Ymd\THis\Z'), "DTSTART;TZID=Europe/Berlin:$st", "DTEND;TZID=Europe/Berlin:$en",
                                      "SUMMARY:" . $esc(($s['fav'] ? '★ ' : '') . $s['title']), "LOCATION:" . $esc($s['place']), "DESCRIPTION:" . $esc(format_plain($s['note'])), "END:VEVENT"]);
        }
        $out[] = "END:VCALENDAR";
        echo implode("\r\n", $out) . "\r\n";
        exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $slug . '_ausgaben.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    $people = array_column($con['people'], 'name', 'id');
    fputcsv($f, ['Datum', 'Bezeichnung', 'Kategorie', 'Status', 'Betrag', 'Währung', 'Betrag EUR', 'Fällig', 'Zahlart', 'Bezahlt von', 'Geteilt mit', 'Notiz'], ';');
    foreach ($con['expenses'] as $e) {
        fputcsv($f, [$e['date'] ? date('d.m.Y', strtotime($e['date'])) : '', $e['title'], CON_CATS[$e['category']][1], CON_EXP_STATUS[$e['status']][1], number_format((float)$e['amount'], 2, ',', ''),
                     $e['currency'], number_format(con_eur($con, $e), 2, ',', ''), $e['due'] ? date('d.m.Y', strtotime($e['due'])) : '', CON_METHODS[$e['method']] ?? '',
                     $people[$e['payer']] ?? 'Ich', implode(', ', array_map(function ($id) use ($people) { return $people[$id] ?? $id; }, con_split($con, $e))), format_plain($e['docs'])], ';');
    }
    fclose($f);
    exit;
}

// ---------- Änderungen ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    $tab = (string)($_POST['tab'] ?? 'overview');
    $msg = '';
    $err = '';
    $in = function (string $k, int $max = 200) { return mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max); };
    $date = function (string $k) { $v = (string)($_POST[$k] ?? ''); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : ''; };
    $time = function (string $k) { $v = (string)($_POST[$k] ?? ''); return preg_match('/^\d{2}:\d{2}$/', $v) ? $v : ''; };
    try {
        $con = &$data['cons'][$ci];
        $id = (string)($_POST['id'] ?? '');
        $pids = array_column($con['people'], 'id');
        switch ($act) {
            // ----- Con selbst -----
            case 'con_save':
                $fields = ['name' => $in('name', 80) ?: 'Unbenannte Con', 'icon' => mb_substr($in('icon', 8), 0, 4) ?: '🎪', 'start' => $date('start'), 'end' => $date('end'),
                           'city' => $in('city', 80), 'venue' => $in('venue', 120), 'hotel' => $in('hotel', 200), 'budget' => max(0, parse_amount($_POST['budget'] ?? 0)),
                           'currency' => isset(CON_CURRENCIES[$_POST['currency'] ?? '']) ? $_POST['currency'] : 'EUR', 'fx_rate' => max(0.0001, (float)str_replace(',', '.', (string)($_POST['fx_rate'] ?? '1')) ?: 1),
                           'status' => isset(CON_STATUS[$_POST['status'] ?? '']) ? $_POST['status'] : 'planned'];
                $web = $in('website', 300);
                if ($web !== '' && !preg_match('#^https?://#i', $web)) $web = 'https://' . $web;
                $fields['website'] = safe_url($web) === '#' ? '' : $web;
                if ($fields['end'] && $fields['start'] && $fields['end'] < $fields['start']) throw new InvalidArgumentException('Das Ende liegt vor dem Beginn.');
                if ($fields['currency'] === 'EUR') $fields['fx_rate'] = 1.0;
                if ($id === '') {
                    $new = con_blank($fields);
                    $data['cons'][] = $new;
                    $data['active'] = $cid = $new['id'];
                    $msg = 'Con „' . $new['name'] . '“ angelegt.';
                    audit('Con angelegt', $new['name']);
                } else {
                    $con = $fields + $con;
                    $msg = 'Gespeichert.';
                }
                break;
            case 'con_duplicate':
                $copy = $con;
                $copy['id'] = con_id();
                if (preg_match('/\b(20\d{2})\b/', $copy['name'], $m)) $copy['name'] = str_replace($m[1], (string)($m[1] + 1), $copy['name']);        // Jahr hochzählen
                elseif (preg_match('/^(.*?)(\d{1,3})$/', $copy['name'], $m)) $copy['name'] = $m[1] . ((int)$m[2] + 1);                             // „EF 31“ → „EF 32“
                else $copy['name'] .= ' (Kopie)';
                foreach (['start', 'end'] as $k) $copy[$k] = $copy[$k] ? date('Y-m-d', strtotime($copy[$k] . ' +1 year')) : '';
                $copy['status'] = 'planned';
                $copy['created'] = time();
                $copy['expenses'] = array_map(function ($e) { return ['id' => con_id('e'), 'status' => 'planned', 'due' => '', 'date' => '', 'docs' => ''] + $e; }, $con['expenses']);
                foreach ($copy['expenses'] as &$e) { $e['status'] = 'planned'; $e['due'] = ''; $e['date'] = ''; $e['id'] = con_id('e'); } unset($e);
                $copy['schedule'] = [];
                $copy['wishlist'] = [];
                $copy['packing'] = array_map(function ($p) { return ['done' => false, 'id' => con_id('p')] + $p; }, $con['packing']);
                foreach ($copy['packing'] as &$p) $p['done'] = false; unset($p);
                unset($copy['cal_id'], $copy['archived_from']);
                $data['cons'][] = $copy;
                $data['active'] = $cid = $copy['id'];
                $msg = 'Als Vorlage kopiert – alle Ausgaben stehen auf „geplant“.';
                break;
            case 'con_delete':
                if (count($data['cons']) < 2) throw new InvalidArgumentException('Die letzte Con kann nicht gelöscht werden – lieber umbenennen.');
                $name = $con['name'];
                unset($con);
                array_splice($data['cons'], $ci, 1);
                $data['active'] = $cid = $data['cons'][0]['id'];
                $msg = 'Con „' . $name . '“ gelöscht.';
                audit('Con gelöscht', $name, 'warn');
                break;
            case 'con_status':
                if (isset(CON_STATUS[$_POST['status'] ?? ''])) $con['status'] = $_POST['status'];
                break;

            // ----- Ausgaben -----
            case 'exp_save':
                $amount = parse_amount($_POST['amount'] ?? 0);
                $title = $in('title', 120);
                if ($title === '' || $amount <= 0) throw new InvalidArgumentException('Bitte Bezeichnung und einen Betrag über 0 eingeben.');
                $split = array_values(array_intersect((array)($_POST['split'] ?? []), $pids));
                if (count($split) === count($pids)) $split = [];
                $e = ['title' => $title, 'amount' => $amount, 'currency' => ($_POST['currency'] ?? 'EUR') === $con['currency'] ? $con['currency'] : 'EUR',
                      'category' => isset(CON_CATS[$_POST['category'] ?? '']) ? $_POST['category'] : 'other', 'status' => isset(CON_EXP_STATUS[$_POST['status'] ?? '']) ? $_POST['status'] : 'paid',
                      'date' => $date('date') ?: date('Y-m-d'), 'due' => $date('due'), 'method' => isset(CON_METHODS[(string)($_POST['method'] ?? '')]) ? (string)($_POST['method'] ?? '') : '',
                      'payer' => in_array($_POST['payer'] ?? '', $pids, true) ? $_POST['payer'] : 'me', 'split' => $split, 'docs' => mb_substr((string)($_POST['docs'] ?? ''), 0, 4000)];
                if ($id === '') { $con['expenses'][] = $e + ['id' => con_id('e')]; $msg = 'Ausgabe hinzugefügt.'; }
                else { $i = con_item_index($con['expenses'], $id); if ($i === null) throw new InvalidArgumentException('Ausgabe nicht gefunden.'); $con['expenses'][$i] = $e + $con['expenses'][$i]; $msg = 'Ausgabe gespeichert.'; }
                $tab = (string)($_POST['tab'] ?? 'expenses');
                break;
            case 'exp_status':
                $i = con_item_index($con['expenses'], $id);
                if ($i !== null && isset(CON_EXP_STATUS[$_POST['status'] ?? ''])) $con['expenses'][$i]['status'] = $_POST['status'];
                $tab = 'expenses';
                break;
            case 'exp_dup':
                $i = con_item_index($con['expenses'], $id);
                if ($i !== null) { $copy = $con['expenses'][$i]; $copy['id'] = con_id('e'); $copy['title'] .= ' (Kopie)'; array_splice($con['expenses'], $i + 1, 0, [$copy]); }
                $tab = 'expenses';
                break;
            case 'exp_delete':
                $i = con_item_index($con['expenses'], $id);
                if ($i !== null) { array_splice($con['expenses'], $i, 1); $msg = 'Ausgabe gelöscht.'; }
                $tab = 'expenses';
                break;
            case 'budget_save':
                $con['cat_budgets'] = [];
                foreach (CON_CATS as $k => $_) { $v = parse_amount($_POST['cb'][$k] ?? 0); if ($v > 0) $con['cat_budgets'][$k] = $v; }
                $msg = 'Kategorie-Budgets gespeichert.';
                break;

            // ----- Personen -----
            case 'person_save':
                $name = $in('name', 40);
                if ($name === '') throw new InvalidArgumentException('Bitte einen Namen eingeben.');
                if ($id === '') {
                    // Bisherige Ausgaben „für alle“ bleiben bei den bisherigen Personen – die neue Person zahlt nicht rückwirkend mit
                    foreach ($con['expenses'] as &$e) if (!$e['split']) $e['split'] = $pids;
                    unset($e);
                    $con['people'][] = ['id' => con_id('u'), 'name' => $name, 'me' => false];
                }
                else { $i = con_item_index($con['people'], $id); if ($i !== null) $con['people'][$i]['name'] = $name; }
                $tab = 'group';
                break;
            case 'person_delete':
                $i = con_item_index($con['people'], $id);
                if ($i === null || !empty($con['people'][$i]['me'])) throw new InvalidArgumentException('Dich selbst kannst du nicht entfernen.');
                array_splice($con['people'], $i, 1);
                foreach ($con['expenses'] as &$e) {
                    if ($e['payer'] === $id) $e['payer'] = 'me';
                    $e['split'] = array_values(array_diff($e['split'], [$id]));
                }
                unset($e);
                $tab = 'group';
                $msg = 'Person entfernt – ihre Ausgaben zählen jetzt für dich.';
                break;

            // ----- Wunschliste -----
            case 'wish_save':
                $title = $in('title', 120);
                if ($title === '') throw new InvalidArgumentException('Bitte eingeben, was du dir wünschst.');
                $link = $in('link', 500);
                if ($link !== '' && !preg_match('#^https?://#i', $link)) $link = 'https://' . $link;
                $w = ['title' => $title, 'shop' => $in('shop', 80), 'price' => max(0, parse_amount($_POST['price'] ?? 0)), 'prio' => max(1, min(3, (int)($_POST['prio'] ?? 2))),
                      'cat' => in_array($_POST['cat'] ?? '', ['merch', 'art', 'suit', 'other'], true) ? $_POST['cat'] : 'merch', 'link' => safe_url($link) === '#' ? '' : $link, 'note' => $in('note', 300)];
                if ($id === '') $con['wishlist'][] = $w + ['id' => con_id('w'), 'bought' => false];
                else { $i = con_item_index($con['wishlist'], $id); if ($i !== null) $con['wishlist'][$i] = $w + $con['wishlist'][$i]; }
                $tab = 'wish';
                break;
            case 'wish_buy':
                $i = con_item_index($con['wishlist'], $id);
                if ($i === null) break;
                $w = &$con['wishlist'][$i];
                $price = isset($_POST['price']) && $_POST['price'] !== '' ? parse_amount($_POST['price']) : (float)$w['price'];
                $w['bought'] = true;
                if ($price > 0) {
                    $w['expense'] = con_id('e');
                    $con['expenses'][] = ['id' => $w['expense'], 'title' => $w['title'] . ($w['shop'] ? ' (' . $w['shop'] . ')' : ''), 'amount' => $price, 'currency' => 'EUR', 'category' => $w['cat'],
                                          'status' => 'paid', 'date' => date('Y-m-d'), 'due' => '', 'method' => '', 'payer' => 'me', 'split' => ['me'], 'docs' => $w['note']];
                }
                unset($w);
                $msg = 'Gekauft! 🎉 ' . ($price > 0 ? 'Als Ausgabe eingetragen.' : '');
                $tab = 'wish';
                break;
            case 'wish_unbuy':
                $i = con_item_index($con['wishlist'], $id);
                if ($i === null) break;
                if (!empty($con['wishlist'][$i]['expense']) && ($j = con_item_index($con['expenses'], $con['wishlist'][$i]['expense'])) !== null) array_splice($con['expenses'], $j, 1);
                $con['wishlist'][$i]['bought'] = false;
                unset($con['wishlist'][$i]['expense']);
                $tab = 'wish';
                break;
            case 'wish_delete':
                $i = con_item_index($con['wishlist'], $id);
                if ($i !== null) array_splice($con['wishlist'], $i, 1);
                $tab = 'wish';
                break;

            // ----- Zeitplan -----
            case 'sched_save':
                $title = $in('title', 120);
                if ($title === '' || !$date('date')) throw new InvalidArgumentException('Bitte Titel und Datum angeben.');
                $s = ['date' => $date('date'), 'time' => $time('time'), 'end' => $time('end'), 'title' => $title, 'place' => $in('place', 80), 'note' => mb_substr((string)($_POST['note'] ?? ''), 0, 1000), 'fav' => !empty($_POST['fav'])];
                if ($id === '') $con['schedule'][] = $s + ['id' => con_id('s')];
                else { $i = con_item_index($con['schedule'], $id); if ($i !== null) $con['schedule'][$i] = $s + $con['schedule'][$i]; }
                $tab = 'schedule';
                break;
            case 'sched_fav':
                $i = con_item_index($con['schedule'], $id);
                if ($i !== null) $con['schedule'][$i]['fav'] = empty($con['schedule'][$i]['fav']);
                $tab = 'schedule';
                break;
            case 'sched_delete':
                $i = con_item_index($con['schedule'], $id);
                if ($i !== null) array_splice($con['schedule'], $i, 1);
                $tab = 'schedule';
                break;

            // ----- Packliste -----
            case 'pack_add':
                $group = $in('group', 40) ?: 'Sonstiges';
                foreach (preg_split('/\r?\n/', (string)($_POST['text'] ?? '')) as $line) {
                    $line = mb_substr(trim($line), 0, 150);
                    if ($line !== '') $con['packing'][] = ['id' => con_id('p'), 'group' => $group, 'text' => $line, 'done' => false];
                }
                $tab = 'pack';
                break;
            case 'pack_template':
                $have = array_map(function ($p) { return mb_strtolower($p['text']); }, $con['packing']);
                $groups = array_intersect(array_keys(CON_PACK_TEMPLATE), (array)($_POST['groups'] ?? array_keys(CON_PACK_TEMPLATE)));
                $n = 0;
                foreach ($groups as $g) foreach (CON_PACK_TEMPLATE[$g] as $t) {
                    if (in_array(mb_strtolower($t), $have, true)) continue;
                    $con['packing'][] = ['id' => con_id('p'), 'group' => $g, 'text' => $t, 'done' => false];
                    $n++;
                }
                $msg = $n . ' Punkte aus der Vorlage übernommen.';
                $tab = 'pack';
                break;
            case 'pack_toggle':
                $i = con_item_index($con['packing'], $id);
                if ($i !== null) $con['packing'][$i]['done'] = empty($con['packing'][$i]['done']);
                $tab = 'pack';
                if (!empty($_POST['ajax'])) { con_save($data); header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit; }
                break;
            case 'pack_delete':
                $i = con_item_index($con['packing'], $id);
                if ($i !== null) array_splice($con['packing'], $i, 1);
                $tab = 'pack';
                break;
            case 'pack_reset':
                foreach ($con['packing'] as &$p) $p['done'] = false;
                unset($p);
                $tab = 'pack';
                break;
            case 'pack_clear':
                $con['packing'] = array_values(array_filter($con['packing'], function ($p) { return empty($p['done']); }));
                $tab = 'pack';
                break;

            // ----- Notizen -----
            case 'notes_save':
                $con['notes'] = mb_substr((string)($_POST['notes'] ?? ''), 0, 50000);
                $con['hotel'] = $in('hotel', 200);
                $msg = 'Notizen gespeichert.';
                $tab = 'notes';
                break;

            // ----- Anbindung Countdown & Kalender -----
            case 'countdown':
                if (!can('countdown.edit')) throw new InvalidArgumentException('Dafür fehlt dir das Recht „Countdowns bearbeiten“.');
                if (!$con['start']) throw new InvalidArgumentException('Bitte zuerst ein Startdatum eintragen.');
                require_once __DIR__ . '/inc/modules/countdown.php';
                $cd = load_countdowns();
                $ev = ['id' => 'con_' . $con['id'], 'title' => $con['name'], 'date' => $con['start'] . 'T10:00', 'icon' => $con['icon'], 'repeat' => 'none',
                       'note' => trim($con['city'] . ($con['venue'] ? ' – ' . $con['venue'] : '')), 'link' => $con['website'], 'featured' => false, 'created' => date('Y-m-d\TH:i')];
                $found = false;
                foreach ($cd['events'] as &$x) if ($x['id'] === $ev['id']) { $x = array_merge($x, array_diff_key($ev, ['created' => 1, 'featured' => 1])); $found = true; }
                unset($x);
                if (!$found) $cd['events'][] = $ev;
                save_json(COUNTDOWN_FILE, $cd);
                $msg = $found ? 'Countdown aktualisiert.' : 'Countdown angelegt – zu sehen auf der Countdown-Seite und im Kalender.';
                break;
            case 'calendar':
                if (!can('calendar.edit')) throw new InvalidArgumentException('Dafür fehlt dir das Recht „Kalender-Termine bearbeiten“.');
                if (!$con['start']) throw new InvalidArgumentException('Bitte zuerst ein Startdatum eintragen.');
                require_once __DIR__ . '/inc/modules/calendar.php';
                $ev = cal_event_from_post(['title' => $con['icon'] . ' ' . $con['name'], 'all_day' => 1, 'start_date' => $con['start'], 'end_date' => $con['end'] ?: $con['start'],
                                           'location' => trim($con['venue'] . ', ' . $con['city'], ', '), 'url' => $con['website'], 'private' => 1,
                                           'description' => 'Aus dem Con-Rechner' . ($con['hotel'] ? "\nHotel: " . $con['hotel'] : '')]);
                if (!empty($con['cal_id']) && cal_get((int)$con['cal_id'])) { cal_update((int)$con['cal_id'], $ev); $msg = 'Kalendertermin aktualisiert.'; }
                else { $con['cal_id'] = cal_insert($ev); $msg = 'Als (privater) Termin in den Kalender eingetragen.'; }
                break;
            default:
                throw new InvalidArgumentException('Unbekannte Aktion.');
        }
        unset($con);
        con_save($data);
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    }
    header('Location: con.php?c=' . urlencode($cid) . ($err ? '&error=' . urlencode($err) : ($msg ? '&msg=' . urlencode($msg) : '')) . '#' . preg_replace('/\W/', '', $tab));
    exit;
}

// ---------- Anzeige ----------
$con = $data['cons'][$ci];
$r = con_calc($con);
$people = array_column($con['people'], null, 'id');
$pname = function ($id) use ($people) { return $people[$id]['name'] ?? '?'; };
$cf = ['con' => $con['id']];
$d = $r['days'];
$cur_sym = CON_CURRENCIES[$con['currency']] ?? $con['currency'];
$when = '';
if ($con['start']) {
    $when = date('d.m.Y', strtotime($con['start'])) . ($con['end'] && $con['end'] !== $con['start'] ? ' – ' . date('d.m.Y', strtotime($con['end'])) : '');
}
$phase = ['before' => $d['until'] === 1 ? 'morgen geht\'s los!' : 'noch ' . $d['until'] . ' Tage', 'running' => '🎪 läuft – Tag ' . $d['day_no'] . ' von ' . $d['days'], 'after' => 'vorbei', 'unknown' => 'Datum noch offen'][$d['phase']];

/** Donut-Diagramm als SVG */
function con_donut(array $parts, float $total): string {
    if ($total <= 0) return '<div class="cn-empty">Noch keine Ausgaben.</div>';
    $r = 42; $c = 2 * M_PI * $r; $off = 0; $svg = '';
    foreach ($parts as $k => $v) {
        $len = $v / $total * $c;
        $svg .= '<circle r="' . $r . '" cx="60" cy="60" fill="none" stroke="' . CON_CATS[$k][2] . '" stroke-width="18" stroke-dasharray="' . round($len, 2) . ' ' . round($c - $len, 2) . '" stroke-dashoffset="' . round(-$off, 2) . '"><title>' . e(CON_CATS[$k][1] . ': ' . con_money($v)) . '</title></circle>';
        $off += $len;
    }
    return '<svg class="cn-donut" viewBox="0 0 120 120" role="img" aria-label="Verteilung nach Kategorie"><g transform="rotate(-90 60 60)">' . $svg . '</g><text x="60" y="57" text-anchor="middle" class="t1">' . e(eur($total)) . '</text><text x="60" y="72" text-anchor="middle" class="t2">€ gesamt</text></svg>';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Con-Rechner – <?php echo e($con['name']); ?></title>
    <?php echo emoji_favicon($con['icon'] ?: '🎪'); ?>
    <link rel="stylesheet" href="assets/css/hud.css">
    <link rel="stylesheet" href="assets/css/page.css">
    <link rel="stylesheet" href="assets/css/con.css">
</head>
<body>
<?php auth_corner(); ?>
<div class="wrapper" style="max-width: 1200px;">
    <div class="nav-bar">
        <a href="index.php" class="nav-back">&larr; ZURÜCK ZUM INDEX</a>
        <span class="cn-navright">
            <button type="button" class="nav-tab" data-open="conModal" data-title="Neue Con anlegen" data-fill="<?php echo tpl_fill(['id' => '', 'icon' => '🎪', 'status' => 'planned', 'currency' => 'EUR', 'fx_rate' => '1', 'budget' => '']); ?>">＋ Neue Con</button>
            <a class="nav-tab" href="#compare" data-tabjump="compare">📊 Vergleich</a>
        </span>
    </div>

    <!-- Con-Auswahl -->
    <div class="cn-switch" role="tablist" aria-label="Cons">
        <?php foreach ($data['cons'] as $x): $xd = con_days($x); ?>
            <a href="con.php?c=<?php echo e(urlencode($x['id'])); ?>" class="cn-pick<?php echo $x['id'] === $con['id'] ? ' on' : ''; ?> st-<?php echo e($x['status']); ?>">
                <span class="cn-pick-ic"><?php echo e($x['icon']); ?></span>
                <span><b><?php echo e($x['name']); ?></b><small><?php echo e(CON_STATUS[$x['status']][0] . ' ' . ($x['start'] ? date('d.m.Y', strtotime($x['start'])) : CON_STATUS[$x['status']][1])); ?><?php echo $xd['phase'] === 'before' ? ' · in ' . $xd['until'] . ' T.' : ''; ?></small></span>
            </a>
        <?php endforeach; ?>
    </div>

    <header class="cn-head">
        <div>
            <div class="hud-tag">Con-Rechner // <?php echo e(CON_STATUS[$con['status']][0] . ' ' . CON_STATUS[$con['status']][1]); ?></div>
            <h1><span class="cn-icon"><?php echo e($con['icon']); ?></span><?php echo e($con['name']); ?></h1>
            <div class="cn-sub">
                <?php if ($when): ?><span>📅 <?php echo e($when); ?><?php echo $d['days'] ? ' · ' . $d['days'] . ' Tage / ' . $d['nights'] . ' Nächte' : ''; ?></span><?php endif; ?>
                <span class="cn-phase ph-<?php echo e($d['phase']); ?>"><?php echo e($phase); ?></span>
                <?php if ($con['city'] || $con['venue']): ?><span>📍 <?php echo e(trim($con['venue'] . ', ' . $con['city'], ', ')); ?></span><?php endif; ?>
                <?php if ($con['website']): ?><a href="<?php echo e($con['website']); ?>" target="_blank" rel="noopener noreferrer">🌐 Webseite ↗</a><?php endif; ?>
                <?php if ($con['currency'] !== 'EUR'): ?><span>💱 1 <?php echo e($con['currency']); ?> = <?php echo e(number_format($con['fx_rate'], 4, ',', '.')); ?> €</span><?php endif; ?>
            </div>
        </div>
        <div class="cn-actions">
            <button type="button" class="btn btn-sm btn-gold" data-open="conModal" data-title="Con bearbeiten"
                    data-fill="<?php echo tpl_fill(['id' => $con['id'], 'name' => $con['name'], 'icon' => $con['icon'], 'start' => $con['start'], 'end' => $con['end'], 'city' => $con['city'], 'venue' => $con['venue'], 'website' => $con['website'], 'hotel' => $con['hotel'], 'budget' => eur($con['budget']), 'currency' => $con['currency'], 'fx_rate' => (string)$con['fx_rate'], 'status' => $con['status']]); ?>">✎ Bearbeiten</button>
            <?php echo tpl_button('con_duplicate', $cf, '⧉ Als Vorlage', 'Für nächstes Jahr kopieren (Ausgaben als „geplant“)', 'btn btn-sm');
                  if (can('countdown.edit')) echo tpl_button('countdown', $cf, '⏳ Countdown', 'Countdown anlegen/aktualisieren', 'btn btn-sm');
                  if (can('calendar.edit')) echo tpl_button('calendar', $cf, '📅 Kalender', !empty($con['cal_id']) ? 'Kalendertermin aktualisieren' : 'In den Kalender eintragen', 'btn btn-sm'); ?>
            <a class="btn btn-sm" href="con.php?c=<?php echo e(urlencode($con['id'])); ?>&amp;export=csv" title="Ausgaben als CSV (Excel)">⬇ CSV</a>
            <button type="button" class="btn btn-sm" onclick="window.print()" title="Drucken / als PDF speichern">🖨</button>
            <?php if (count($data['cons']) > 1) echo tpl_button('con_delete', $cf, '🗑', 'Con löschen', 'btn btn-sm btn-danger', 'Con „' . $con['name'] . '“ mit allen Ausgaben, Zeitplan und Listen wirklich löschen?'); ?>
        </div>
    </header>

    <?php if (!empty($_GET['msg'])): ?><div class="flash"><?php echo e($_GET['msg']); ?></div><?php endif; ?>
    <?php if (!empty($_GET['error'])): ?><div class="flash err">⚠ <?php echo e($_GET['error']); ?></div><?php endif; ?>

    <nav class="cn-tabs" id="cnTabs" role="tablist">
        <?php foreach (['overview' => '📊 Übersicht', 'expenses' => '💶 Ausgaben', 'group' => '👥 Gruppe & Abrechnung', 'calc' => '🧮 Rechner', 'schedule' => '🗓️ Zeitplan', 'pack' => '🎒 Packliste', 'wish' => '🛍️ Wunschliste', 'notes' => '📝 Notizen', 'compare' => '📈 Vergleich'] as $k => $l): ?>
            <button type="button" class="cn-tab" data-tab="<?php echo $k; ?>" role="tab"><?php echo $l; ?></button>
        <?php endforeach; ?>
    </nav>

    <!-- ================= Übersicht ================= -->
    <section class="cn-pane" data-pane="overview">
        <div class="cn-kpis">
            <div class="k gold"><span>Mein Budget</span><b><?php echo con_money($r['budget']); ?></b></div>
            <div class="k"><span>Meine Kosten</span><b><?php echo con_money($r['my']); ?></b><small><?php echo count($con['people']) > 1 ? 'Gruppe gesamt: ' . con_money($r['total']) : 'inkl. geplanter'; ?></small></div>
            <div class="k green"><span>Davon bezahlt</span><b><?php echo con_money($r['my_paid']); ?></b></div>
            <div class="k red"><span>Offen</span><b><?php echo con_money($r['my_open']); ?></b><?php if ($r['dues'] && $r['dues'][0]['due']): ?><small>nächste Fälligkeit <?php echo e(date('d.m.', strtotime($r['dues'][0]['due']))); ?></small><?php endif; ?></div>
            <div class="k purple"><span>Geplant / Schätzung</span><b><?php echo con_money($r['my_planned']); ?></b></div>
            <div class="k <?php echo $r['rest'] >= 0 ? 'cyan' : 'red'; ?>"><span>Rest / Puffer</span><b><?php echo con_money($r['rest']); ?></b><?php if ($r['wish_open'] > 0): ?><small>nach Wunschliste: <?php echo con_money($r['rest'] - $r['wish_open']); ?></small><?php endif; ?></div>
            <?php if ($r['per_day'] !== null): ?><div class="k"><span>Rest pro Con-Tag</span><b><?php echo con_money($r['per_day']); ?></b><small><?php echo $d['days']; ?> Tage</small></div><?php endif; ?>
            <?php if ($r['save_month'] !== null && $r['to_save'] > 0): ?><div class="k"><span>Sparziel</span><b><?php echo con_money($r['save_month']); ?>/Monat</b><small><?php echo con_money($r['save_week']); ?>/Woche · noch <?php echo con_money($r['to_save']); ?></small></div><?php endif; ?>
        </div>

        <div class="cn-budgetbar" title="Bezahlt / offen / geplant im Verhältnis zum Budget">
            <?php $base = max($r['budget'], $r['my'], 0.01);
            foreach (['my_paid' => 'paid', 'my_open' => 'open', 'my_planned' => 'planned'] as $k => $cls): ?>
                <i class="<?php echo $cls; ?>" style="width: <?php echo round($r[$k] / $base * 100, 2); ?>%;"></i>
            <?php endforeach; ?>
            <?php if ($r['budget'] > 0 && $r['my'] > $r['budget']): ?><span class="cn-over" style="left: <?php echo round($r['budget'] / $base * 100, 2); ?>%;" title="Budgetgrenze"></span><?php endif; ?>
        </div>
        <div class="cn-legend"><span><i class="paid"></i>bezahlt</span><span><i class="open"></i>offen</span><span><i class="planned"></i>geplant</span><span><?php echo $r['budget'] > 0 ? round($r['ratio']) . ' % vom Budget' : 'kein Budget gesetzt'; ?></span></div>

        <div class="cn-grid2">
            <div class="panel">
                <div class="panel-title">Nach Kategorie <button type="button" class="btn btn-sm" data-open="budgetModal">Budgets je Kategorie</button></div>
                <div class="cn-catwrap">
                    <?php echo con_donut($r['by_cat'], $r['total']); ?>
                    <div class="cn-cats">
                        <?php foreach (CON_CATS as $k => [$ic, $name, $col]):
                            $v = $r['my_by_cat'][$k] ?? 0; $b = (float)($con['cat_budgets'][$k] ?? 0);
                            if ($v <= 0 && $b <= 0) continue; ?>
                            <div class="cn-cat" style="--cc: <?php echo $col; ?>;">
                                <span><?php echo $ic; ?> <?php echo e($name); ?></span>
                                <b><?php echo con_money($v); ?><?php echo $b > 0 ? ' <small>/ ' . con_money($b) . '</small>' : ''; ?></b>
                                <?php if ($b > 0): ?><div class="cn-mini"><i style="width: <?php echo min(100, round($v / $b * 100)); ?>%;" class="<?php echo $v > $b ? 'over' : ''; ?>"></i></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$r['my_by_cat'] && !$con['cat_budgets']): ?><div class="cn-empty">Noch nichts erfasst.</div><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="panel">
                <div class="panel-title">Schnell erfassen</div>
                <form method="POST" class="cn-quick">
                    <input type="hidden" name="action" value="exp_save"><input type="hidden" name="con" value="<?php echo e($con['id']); ?>"><input type="hidden" name="tab" value="overview"><input type="hidden" name="split[]" value="<?php echo e($r['me']); ?>">
                    <input type="text" name="title" placeholder="Wofür? z. B. Hotel, Badge, Pizza …" required maxlength="120">
                    <div class="cn-row">
                        <input type="text" name="amount" placeholder="Betrag" inputmode="decimal" required>
                        <select name="category"><?php foreach (CON_CATS as $k => [$ic, $n]): ?><option value="<?php echo $k; ?>"><?php echo $ic . ' ' . e($n); ?></option><?php endforeach; ?></select>
                        <select name="status"><?php foreach (CON_EXP_STATUS as $k => [$ic, $n]): ?><option value="<?php echo $k; ?>" <?php echo $k === 'paid' ? 'selected' : ''; ?>><?php echo $ic . ' ' . e($n); ?></option><?php endforeach; ?></select>
                    </div>
                    <button class="btn btn-gold">＋ Hinzufügen</button>
                    <button type="button" class="btn" data-open="expModal" data-title="Neue Ausgabe" data-fill="<?php echo tpl_fill(['id' => '', 'status' => 'paid', 'category' => 'other', 'currency' => 'EUR', 'payer' => $r['me'], 'date' => date('Y-m-d')]); ?>">Mit allen Details …</button>
                </form>
                <?php if ($r['dues']): ?>
                    <div class="panel-title" style="margin-top: 18px;">⏳ Offene Zahlungen</div>
                    <?php foreach (array_slice($r['dues'], 0, 6) as $e): $late = $e['due'] && $e['due'] < date('Y-m-d'); ?>
                        <div class="cn-due<?php echo $late ? ' late' : ''; ?>"><span><?php echo CON_CATS[$e['category']][0]; ?> <?php echo e($e['title']); ?></span>
                            <small><?php echo $e['due'] ? ($late ? '⚠ überfällig seit ' : 'fällig ') . e(date('d.m.Y', strtotime($e['due']))) : 'ohne Fälligkeit'; ?></small>
                            <b><?php echo con_money($e['eur']); ?></b>
                            <?php echo tpl_button('exp_status', $cf + ['id' => $e['id'], 'status' => 'paid'], '✓', 'Als bezahlt markieren', 'ic'); ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="cn-mini-stats">
                    <a href="#pack" data-tabjump="pack">🎒 Packliste <b><?php echo $r['pack'][0]; ?>/<?php echo $r['pack'][1]; ?></b></a>
                    <a href="#wish" data-tabjump="wish">🛍️ Wunschliste <b><?php echo $r['wish_count']; ?> · <?php echo con_money($r['wish_open']); ?></b></a>
                    <a href="#schedule" data-tabjump="schedule">🗓️ Termine <b><?php echo count($con['schedule']); ?></b></a>
                    <a href="#group" data-tabjump="group">👥 Personen <b><?php echo count($con['people']); ?></b></a>
                </div>
            </div>
        </div>
        <?php if ($r['by_day']): $mx = max($r['by_day']); ?>
            <div class="panel">
                <div class="panel-title">Ausgaben pro Tag</div>
                <div class="cn-days">
                    <?php foreach ($r['by_day'] as $day => $v): ?>
                        <div class="cn-day" title="<?php echo e(date('d.m.Y', strtotime($day)) . ': ' . con_money($v)); ?>"><i style="height: <?php echo max(3, round($v / $mx * 100)); ?>%;"></i><span><?php echo e(date('d.m.', strtotime($day))); ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <!-- ================= Ausgaben ================= -->
    <section class="cn-pane" data-pane="expenses" hidden>
        <div class="tb">
            <input type="search" class="tb-search" placeholder="Ausgaben durchsuchen …" data-filter="#expList > .cn-exp">
            <select id="expCat" aria-label="Kategorie"><option value="">Alle Kategorien</option><?php foreach (CON_CATS as $k => [$ic, $n]): ?><option value="<?php echo $k; ?>"><?php echo $ic . ' ' . e($n); ?></option><?php endforeach; ?></select>
            <select id="expStatus" aria-label="Status"><option value="">Jeder Status</option><?php foreach (CON_EXP_STATUS as $k => [$ic, $n]): ?><option value="<?php echo $k; ?>"><?php echo $ic . ' ' . e($n); ?></option><?php endforeach; ?></select>
            <select id="expSort" aria-label="Sortierung"><option value="date">Neueste zuerst</option><option value="amount">Teuerste zuerst</option><option value="cat">Nach Kategorie</option><option value="due">Nach Fälligkeit</option></select>
            <button type="button" class="btn btn-gold" data-open="expModal" data-title="Neue Ausgabe" data-fill="<?php echo tpl_fill(['id' => '', 'status' => 'paid', 'category' => 'other', 'currency' => 'EUR', 'payer' => $r['me'], 'date' => date('Y-m-d')]); ?>">＋ Ausgabe</button>
        </div>
        <div class="cn-exps" id="expList">
            <?php foreach (array_reverse($con['expenses']) as $e): $v = con_eur($con, $e); $split = con_split($con, $e); [$sic, $sname] = CON_EXP_STATUS[$e['status']]; ?>
                <article class="cn-exp st-<?php echo e($e['status']); ?>" style="--cc: <?php echo CON_CATS[$e['category']][2]; ?>;" data-cat="<?php echo e($e['category']); ?>" data-status="<?php echo e($e['status']); ?>"
                         data-amount="<?php echo $v; ?>" data-date="<?php echo e($e['date']); ?>" data-due="<?php echo e($e['due'] ?: '9999'); ?>" data-search="<?php echo e(mb_strtolower($e['title'] . ' ' . format_plain($e['docs']) . ' ' . CON_CATS[$e['category']][1])); ?>">
                    <span class="cn-exp-ic"><?php echo CON_CATS[$e['category']][0]; ?></span>
                    <div class="cn-exp-main">
                        <b><?php echo e($e['title']); ?></b>
                        <small><?php echo e(CON_CATS[$e['category']][1]); ?><?php echo $e['date'] ? ' · ' . e(date('d.m.Y', strtotime($e['date']))) : ''; ?><?php echo $e['method'] ? ' · ' . e(CON_METHODS[$e['method']]) : ''; ?>
                            <?php if (count($con['people']) > 1): ?> · 💳 <?php echo e($pname($e['payer'])); ?> → <?php echo count($split) === count($con['people']) ? 'alle' : e(implode(', ', array_map($pname, $split))); ?><?php endif; ?>
                            <?php if ($e['due'] && $e['status'] === 'open'): ?> · <span class="<?php echo $e['due'] < date('Y-m-d') ? 'late' : ''; ?>">fällig <?php echo e(date('d.m.Y', strtotime($e['due']))); ?></span><?php endif; ?></small>
                        <?php if (trim($e['docs']) !== ''): ?><div class="fmt cn-docs"><?php echo format_text($e['docs']); ?></div><?php endif; ?>
                    </div>
                    <div class="cn-exp-side">
                        <b class="cn-amt"><?php echo e(con_cur($con, $e)); ?></b>
                        <?php if ($e['currency'] !== 'EUR'): ?><small>≈ <?php echo con_money($v); ?></small><?php endif; ?>
                        <?php if (count($split) > 1): ?><small>dein Anteil <?php echo in_array($r['me'], $split, true) ? con_money($v / count($split)) : '0,00 €'; ?></small><?php endif; ?>
                        <span class="cn-st" title="<?php echo e($sname); ?>"><?php echo $sic . ' ' . e($sname); ?></span>
                        <span class="acts always">
                            <?php foreach (CON_EXP_STATUS as $sk => [$si, $sn]) if ($sk !== $e['status']) echo tpl_button('exp_status', $cf + ['id' => $e['id'], 'status' => $sk], $si, 'Status: ' . $sn); ?>
                            <button type="button" class="ic" title="Bearbeiten" aria-label="Bearbeiten" data-open="expModal" data-title="Ausgabe bearbeiten"
                                    data-fill="<?php echo tpl_fill(['id' => $e['id'], 'title' => $e['title'], 'amount' => number_format((float)$e['amount'], 2, ',', ''), 'currency' => $e['currency'], 'category' => $e['category'], 'status' => $e['status'], 'date' => $e['date'], 'due' => $e['due'], 'method' => $e['method'], 'payer' => $e['payer'], 'docs' => $e['docs'], 'split[]' => $split]); ?>">✎</button>
                            <?php echo tpl_button('exp_dup', $cf + ['id' => $e['id']], '⧉', 'Kopieren') . tpl_button('exp_delete', $cf + ['id' => $e['id']], '✕', 'Löschen', 'ic danger', 'Ausgabe „' . $e['title'] . '“ löschen?'); ?>
                        </span>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="empty-msg" data-empty-for="#expList > .cn-exp" <?php echo $con['expenses'] ? 'hidden' : ''; ?>><?php echo $con['expenses'] ? 'Keine Ausgabe passt zum Filter.' : 'Noch keine Ausgaben erfasst.'; ?></div>
        <div class="cn-sum" id="expSum"></div>
    </section>

    <!-- ================= Gruppe & Abrechnung ================= -->
    <section class="cn-pane" data-pane="group" hidden>
        <div class="cn-grid2">
            <div class="panel">
                <div class="panel-title">Mitreisende</div>
                <p class="muted small">Wer fährt mit, wer teilt sich Zimmer oder Auto? Bei jeder Ausgabe kannst du einstellen, wer bezahlt hat und wer sie sich teilt – die Abrechnung rechnet alles aus.</p>
                <?php foreach ($con['people'] as $p): ?>
                    <div class="cn-person">
                        <span class="cn-avatar"><?php echo e(mb_strtoupper(mb_substr($p['name'], 0, 1))); ?></span>
                        <b><?php echo e($p['name']); ?><?php echo !empty($p['me']) ? ' <small>(du)</small>' : ''; ?></b>
                        <span class="cn-person-n">bezahlt <?php echo con_money($r['paid_by'][$p['id']] ?? 0); ?> · Anteil <?php echo con_money($r['share'][$p['id']] ?? 0); ?></span>
                        <span class="cn-bal <?php echo ($r['balance'][$p['id']] ?? 0) >= 0 ? 'pos' : 'neg'; ?>"><?php echo ($r['balance'][$p['id']] ?? 0) >= 0 ? '+' : ''; ?><?php echo con_money($r['balance'][$p['id']] ?? 0); ?></span>
                        <span class="acts always">
                            <form method="POST" class="inl" data-prompt="Neuer Name:" data-prompt-field="name" data-prompt-value="<?php echo e($p['name']); ?>"><input type="hidden" name="action" value="person_save"><input type="hidden" name="con" value="<?php echo e($con['id']); ?>"><input type="hidden" name="id" value="<?php echo e($p['id']); ?>"><input type="hidden" name="name"><button class="ic" title="Umbenennen">✎</button></form>
                            <?php if (empty($p['me'])) echo tpl_button('person_delete', $cf + ['id' => $p['id']], '✕', 'Entfernen', 'ic danger', $p['name'] . ' entfernen? Ausgaben, die diese Person bezahlt hat, zählen dann für dich.'); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
                <form method="POST" class="cn-row" style="margin-top: 12px;">
                    <input type="hidden" name="action" value="person_save"><input type="hidden" name="con" value="<?php echo e($con['id']); ?>">
                    <input type="text" name="name" placeholder="Name, z. B. Fuchs Felix" maxlength="40" required>
                    <button class="btn btn-gold">＋ Person</button>
                </form>
            </div>
            <div class="panel">
                <div class="panel-title">💸 Wer schuldet wem?</div>
                <?php if (count($con['people']) < 2): ?>
                    <div class="cn-empty">Füge Mitreisende hinzu, um Kosten zu teilen.</div>
                <?php elseif (!$r['settle']): ?>
                    <div class="cn-empty">✅ Alles ausgeglichen – niemand schuldet jemandem etwas.</div>
                <?php else: foreach ($r['settle'] as $t): ?>
                    <div class="cn-transfer"><b><?php echo e($pname($t['from'])); ?></b><span>→</span><b><?php echo e($pname($t['to'])); ?></b><em><?php echo con_money($t['amount']); ?></em></div>
                <?php endforeach; endif; ?>
                <p class="muted small" style="margin-top: 12px;">Nur gebuchte und bezahlte Ausgaben zählen für die Abrechnung, geplante nicht. Positiver Saldo = bekommt Geld zurück.</p>
                <?php if (count($con['people']) > 1): ?>
                    <div class="panel-title" style="margin-top: 16px;">Kosten pro Person</div>
                    <?php $mxs = max(0.01, max($r['share'])); foreach ($con['people'] as $p): ?>
                        <div class="cn-barrow"><span><?php echo e($p['name']); ?></span><span class="cn-bb"><i style="width: <?php echo round(($r['share'][$p['id']] ?? 0) / $mxs * 100); ?>%;"></i></span><b><?php echo con_money($r['share'][$p['id']] ?? 0); ?></b></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ================= Rechner ================= -->
    <section class="cn-pane" data-pane="calc" hidden>
        <div class="cn-calcs">
            <div class="panel cn-calc" data-calc="room">
                <div class="panel-title">🏨 Zimmer teilen</div>
                <label class="field">Gesamtpreis (€)</label><input type="text" name="total" inputmode="decimal" value="">
                <div class="cn-row"><div><label class="field">Nächte</label><input type="number" name="nights" min="1" value="<?php echo max(1, $d['nights']); ?>"></div>
                    <div><label class="field">Personen</label><input type="number" name="persons" min="1" value="<?php echo max(1, count($con['people'])); ?>"></div></div>
                <label class="field">Kurtaxe / City-Tax pro Person &amp; Nacht (€)</label><input type="text" name="tax" inputmode="decimal" value="0">
                <div class="cn-result"></div>
                <button type="button" class="btn btn-sm btn-gold cn-take" data-open="expModal" data-title="Hotel als Ausgabe">→ Als Ausgabe übernehmen</button>
            </div>
            <div class="panel cn-calc" data-calc="car">
                <div class="panel-title">🚗 Anreise mit dem Auto</div>
                <div class="cn-row"><div><label class="field">Strecke (km, einfach)</label><input type="number" name="km" min="0" value="300"></div>
                    <div><label class="field">Hin &amp; zurück</label><select name="trip"><option value="2">ja</option><option value="1">nein</option></select></div></div>
                <div class="cn-row"><div><label class="field">Verbrauch (l/100 km)</label><input type="text" name="cons" inputmode="decimal" value="6,5"></div>
                    <div><label class="field">Preis pro Liter (€)</label><input type="text" name="price" inputmode="decimal" value="1,79"></div></div>
                <div class="cn-row"><div><label class="field">Maut / Parken (€)</label><input type="text" name="extra" inputmode="decimal" value="0"></div>
                    <div><label class="field">Mitfahrende</label><input type="number" name="persons" min="1" value="<?php echo max(1, count($con['people'])); ?>"></div></div>
                <label class="vx-check"><input type="checkbox" name="ev"> Elektroauto (Verbrauch in kWh/100 km, Preis pro kWh)</label>
                <div class="cn-result"></div>
                <button type="button" class="btn btn-sm btn-gold cn-take" data-open="expModal" data-title="Anreise als Ausgabe">→ Als Ausgabe übernehmen</button>
            </div>
            <div class="panel cn-calc" data-calc="train">
                <div class="panel-title">🚆 Bahn / Flug teilen</div>
                <div class="cn-row"><div><label class="field">Preis pro Ticket (€)</label><input type="text" name="price" inputmode="decimal" value="49,90"></div>
                    <div><label class="field">Fahrten</label><input type="number" name="trips" min="1" value="2"></div></div>
                <div class="cn-row"><div><label class="field">Personen</label><input type="number" name="persons" min="1" value="1"></div>
                    <div><label class="field">Rabatt (%)</label><input type="number" name="discount" min="0" max="100" value="0"></div></div>
                <label class="field">Sitzplatz / Gepäck pro Fahrt (€)</label><input type="text" name="extra" inputmode="decimal" value="0">
                <div class="cn-result"></div>
                <button type="button" class="btn btn-sm btn-gold cn-take" data-open="expModal" data-title="Tickets als Ausgabe">→ Als Ausgabe übernehmen</button>
            </div>
            <div class="panel cn-calc" data-calc="daily" data-rest="<?php echo round($r['rest'], 2); ?>" data-days="<?php echo max(1, $d['days']); ?>">
                <div class="panel-title">🍕 Tagesbudget vor Ort</div>
                <div class="cn-row"><div><label class="field">Verfügbar (€)</label><input type="text" name="avail" inputmode="decimal" value="<?php echo e(number_format(max(0, $r['rest']), 2, ',', '')); ?>"></div>
                    <div><label class="field">Con-Tage</label><input type="number" name="days" min="1" value="<?php echo max(1, $d['days']); ?>"></div></div>
                <div class="cn-row"><div><label class="field">Essen pro Tag (€)</label><input type="text" name="food" inputmode="decimal" value="25"></div>
                    <div><label class="field">Reserve (%)</label><input type="number" name="reserve" min="0" max="90" value="10"></div></div>
                <div class="cn-result"></div>
            </div>
            <div class="panel cn-calc" data-calc="save" data-until="<?php echo (int)($d['until'] ?? 0); ?>">
                <div class="panel-title">🐷 Sparplan</div>
                <div class="cn-row"><div><label class="field">Noch aufzubringen (€)</label><input type="text" name="need" inputmode="decimal" value="<?php echo e(number_format($r['to_save'] + max(0, $r['wish_open']), 2, ',', '')); ?>"></div>
                    <div><label class="field">Schon gespart (€)</label><input type="text" name="have" inputmode="decimal" value="0"></div></div>
                <label class="field">Bis zum</label><input type="date" name="until" value="<?php echo e($con['start'] ?: date('Y-m-d', strtotime('+3 months'))); ?>">
                <div class="cn-result"></div>
            </div>
            <div class="panel cn-calc" data-calc="fx" data-rate="<?php echo e((string)$con['fx_rate']); ?>" data-cur="<?php echo e($con['currency']); ?>">
                <div class="panel-title">💱 Währungsrechner</div>
                <div class="cn-row"><div><label class="field">Betrag</label><input type="text" name="amount" inputmode="decimal" value="100"></div>
                    <div><label class="field">Währung</label><select name="cur"><?php foreach (CON_CURRENCIES as $k => $s): if ($k === 'EUR') continue; ?><option value="<?php echo $k; ?>" <?php echo $k === $con['currency'] ? 'selected' : ''; ?>><?php echo $k; ?></option><?php endforeach; ?></select></div></div>
                <label class="field">Kurs: 1 Fremdwährung = … € (aus den Con-Einstellungen oder eigener Wert)</label><input type="text" name="rate" inputmode="decimal" value="<?php echo e(number_format($con['currency'] !== 'EUR' ? $con['fx_rate'] : 0.92, 4, ',', '')); ?>">
                <label class="field">Auslandsgebühr der Karte (%)</label><input type="number" name="fee" min="0" max="10" step="0.1" value="0">
                <div class="cn-result"></div>
            </div>
            <div class="panel cn-calc" data-calc="bill">
                <div class="panel-title">🧾 Rechnung teilen</div>
                <div class="cn-row"><div><label class="field">Rechnung (€)</label><input type="text" name="total" inputmode="decimal" value="84,50"></div>
                    <div><label class="field">Personen</label><input type="number" name="persons" min="1" value="<?php echo max(2, count($con['people'])); ?>"></div></div>
                <label class="field">Trinkgeld (%)</label><input type="range" name="tip" min="0" max="25" value="10">
                <div class="cn-result"></div>
                <button type="button" class="btn btn-sm btn-gold cn-take" data-open="expModal" data-title="Essen als Ausgabe">→ Meinen Anteil übernehmen</button>
            </div>
            <div class="panel cn-calc" data-calc="dd" data-rest="<?php echo round($r['rest'], 2); ?>" data-wish="<?php echo round($r['wish_open'], 2); ?>">
                <div class="panel-title">🛍️ Dealers-Den-Limit</div>
                <p class="muted small">Wie viel darf ich im Dealers Den ausgeben, ohne das Budget zu sprengen?</p>
                <div class="cn-row"><div><label class="field">Rest-Budget (€)</label><input type="text" name="rest" inputmode="decimal" value="<?php echo e(number_format($r['rest'], 2, ',', '')); ?>"></div>
                    <div><label class="field">Für Essen &amp; Sonstiges zurückhalten (€)</label><input type="text" name="keep" inputmode="decimal" value="<?php echo e(number_format(25 * max(1, $d['days']), 2, ',', '')); ?>"></div></div>
                <div class="cn-result"></div>
            </div>
        </div>
    </section>

    <!-- ================= Zeitplan ================= -->
    <section class="cn-pane" data-pane="schedule" hidden>
        <div class="tb">
            <button type="button" class="btn btn-gold" data-open="schedModal" data-title="Neuer Programmpunkt" data-fill="<?php echo tpl_fill(['id' => '', 'date' => $con['start'] ?: date('Y-m-d')]); ?>">＋ Programmpunkt</button>
            <a class="btn" href="con.php?c=<?php echo e(urlencode($con['id'])); ?>&amp;export=ics" title="Zeitplan für Handy-Kalender (.ics)">📥 In Kalender-App (.ics)</a>
            <label class="vx-check"><input type="checkbox" id="onlyFav"> nur ★ Favoriten</label>
        </div>
        <?php
        $sched = $con['schedule'];
        usort($sched, function ($a, $b) { return [$a['date'], $a['time']] <=> [$b['date'], $b['time']]; });
        $byday = [];
        foreach ($sched as $s) $byday[$s['date']][] = $s;
        $wd = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        if (!$byday): ?><div class="empty-msg">Noch kein Programm. Panels, Treffen, Fursuit-Walk, Abreise … alles hier eintragen.</div><?php endif;
        foreach ($byday as $day => $items): $ts = strtotime($day); $dn = $con['start'] ? (int)round(($ts - strtotime($con['start'])) / 86400) + 1 : 0; ?>
            <div class="cn-sday">
                <h3><?php echo e($wd[(int)date('w', $ts)] . ', ' . date('d.m.Y', $ts)); ?><?php echo $dn >= 1 && $dn <= $d['days'] ? ' <small>Con-Tag ' . $dn . '</small>' : ''; ?></h3>
                <?php foreach ($items as $s): ?>
                    <div class="cn-sitem<?php echo $s['fav'] ? ' fav' : ''; ?>" data-fav="<?php echo $s['fav'] ? 1 : 0; ?>">
                        <span class="cn-time"><?php echo e($s['time'] ?: 'ganztags'); ?><?php echo $s['end'] ? '<small>– ' . e($s['end']) . '</small>' : ''; ?></span>
                        <div><b><?php echo e($s['title']); ?></b><?php if ($s['place']): ?><small>📍 <?php echo e($s['place']); ?></small><?php endif; ?>
                            <?php if (trim($s['note']) !== ''): ?><div class="fmt cn-docs"><?php echo format_text($s['note']); ?></div><?php endif; ?></div>
                        <span class="acts always">
                            <?php echo tpl_button('sched_fav', $cf + ['id' => $s['id']], $s['fav'] ? '★' : '☆', $s['fav'] ? 'Favorit entfernen' : 'Als Favorit markieren', 'ic'); ?>
                            <button type="button" class="ic" title="Bearbeiten" data-open="schedModal" data-title="Programmpunkt bearbeiten" data-fill="<?php echo tpl_fill(['id' => $s['id'], 'date' => $s['date'], 'time' => $s['time'], 'end' => $s['end'], 'title' => $s['title'], 'place' => $s['place'], 'note' => $s['note'], 'fav' => $s['fav']]); ?>">✎</button>
                            <?php echo tpl_button('sched_delete', $cf + ['id' => $s['id']], '✕', 'Löschen', 'ic danger', 'Programmpunkt löschen?'); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <!-- ================= Packliste ================= -->
    <section class="cn-pane" data-pane="pack" hidden>
        <?php [$pd, $pa] = $r['pack']; ?>
        <div class="cl-label"><span>Gepackt</span><span><?php echo $pd; ?> / <?php echo $pa; ?><?php echo $pa ? ' · ' . round($pd / $pa * 100) . ' %' : ''; ?></span></div>
        <div class="bar" style="--c: var(--accent-green); margin-bottom: 16px;"><div style="width: <?php echo $pa ? round($pd / $pa * 100) : 0; ?>%;" id="packBar"></div></div>
        <div class="tb">
            <form method="POST" class="inl cn-tplform">
                <input type="hidden" name="action" value="pack_template"><input type="hidden" name="con" value="<?php echo e($con['id']); ?>">
                <?php foreach (array_keys(CON_PACK_TEMPLATE) as $g): ?><label class="chip"><input type="checkbox" name="groups[]" value="<?php echo e($g); ?>" <?php echo $g !== 'Fursuit' ? 'checked' : ''; ?>> <?php echo e($g); ?></label><?php endforeach; ?>
                <button class="btn btn-sm">＋ Aus Vorlage</button>
            </form>
            <?php echo tpl_button('pack_reset', $cf, '↺ Alle Haken weg', 'Für die Rückreise: alle Haken entfernen', 'btn btn-sm') . tpl_button('pack_clear', $cf, '🧹 Gepacktes entfernen', 'Erledigte Punkte löschen', 'btn btn-sm', 'Alle abgehakten Punkte löschen?'); ?>
        </div>
        <?php $groups = [];
        foreach ($con['packing'] as $p) $groups[$p['group']][] = $p; ?>
        <div class="cn-packs">
            <?php foreach ($groups as $g => $items): $gd = count(array_filter($items, function ($x) { return !empty($x['done']); })); ?>
                <div class="panel cn-pack">
                    <div class="panel-title"><span><?php echo e($g); ?></span><small><?php echo $gd; ?>/<?php echo count($items); ?></small></div>
                    <?php foreach ($items as $p): ?>
                        <div class="cl-item<?php echo !empty($p['done']) ? ' done' : ''; ?>">
                            <form method="POST" class="inl cn-packtoggle"><input type="hidden" name="action" value="pack_toggle"><input type="hidden" name="con" value="<?php echo e($con['id']); ?>"><input type="hidden" name="id" value="<?php echo e($p['id']); ?>">
                                <button class="cl-check<?php echo !empty($p['done']) ? ' on' : ''; ?>" aria-label="Gepackt umschalten"><?php echo !empty($p['done']) ? '✓' : ''; ?></button></form>
                            <span class="cl-text"><?php echo format_inline($p['text']); ?></span>
                            <?php echo tpl_button('pack_delete', $cf + ['id' => $p['id']], '✕', 'Löschen', 'ic danger'); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$groups): ?><div class="empty-msg">Noch leer – oben Gruppen auswählen und „Aus Vorlage“ klicken oder eigene Punkte eintragen.</div><?php endif; ?>
        <form method="POST" class="panel cn-packadd">
            <input type="hidden" name="action" value="pack_add"><input type="hidden" name="con" value="<?php echo e($con['id']); ?>">
            <div class="cn-row">
                <input type="text" name="group" list="packGroups" placeholder="Gruppe (z. B. Fursuit)" maxlength="40" style="max-width: 220px;">
                <datalist id="packGroups"><?php foreach (array_unique(array_merge(array_keys($groups), array_keys(CON_PACK_TEMPLATE))) as $g): ?><option value="<?php echo e($g); ?>"><?php endforeach; ?></datalist>
                <textarea name="text" rows="1" placeholder="Was muss mit? (mehrere Zeilen = mehrere Punkte)" required></textarea>
                <button class="btn btn-gold">＋</button>
            </div>
        </form>
    </section>

    <!-- ================= Wunschliste ================= -->
    <section class="cn-pane" data-pane="wish" hidden>
        <div class="tb">
            <button type="button" class="btn btn-gold" data-open="wishModal" data-title="Neuer Wunsch" data-fill="<?php echo tpl_fill(['id' => '', 'prio' => '2', 'cat' => 'merch']); ?>">＋ Wunsch</button>
            <span class="muted small">Offen: <b><?php echo $r['wish_count']; ?></b> Wünsche · <b><?php echo con_money($r['wish_open']); ?></b> · Rest-Budget danach <b class="<?php echo $r['rest'] - $r['wish_open'] < 0 ? 'neg' : ''; ?>"><?php echo con_money($r['rest'] - $r['wish_open']); ?></b></span>
        </div>
        <?php $wl = $con['wishlist'];
        usort($wl, function ($a, $b) { return [(int)!empty($a['bought']), $a['prio']] <=> [(int)!empty($b['bought']), $b['prio']]; });
        $prio = [1 => ['🔥', 'Muss haben'], 2 => ['⭐', 'Wäre toll'], 3 => ['💭', 'Vielleicht']]; ?>
        <div class="cn-wishes">
            <?php foreach ($wl as $w): ?>
                <div class="cn-wish<?php echo !empty($w['bought']) ? ' bought' : ''; ?> p<?php echo (int)$w['prio']; ?>">
                    <span class="cn-prio" title="<?php echo e($prio[$w['prio']][1]); ?>"><?php echo $prio[$w['prio']][0]; ?></span>
                    <div><b><?php echo e($w['title']); ?></b><small><?php echo e(CON_CATS[$w['cat']][0] . ' ' . ($w['shop'] ?: CON_CATS[$w['cat']][1])); ?><?php if ($w['link']): ?> · <a href="<?php echo e($w['link']); ?>" target="_blank" rel="noopener noreferrer">Link ↗</a><?php endif; ?></small>
                        <?php if ($w['note'] !== ''): ?><small class="muted"><?php echo format_inline($w['note']); ?></small><?php endif; ?></div>
                    <b class="cn-amt"><?php echo (float)$w['price'] > 0 ? con_money((float)$w['price']) : '–'; ?></b>
                    <span class="acts always">
                        <?php if (empty($w['bought'])): ?>
                            <form method="POST" class="inl" data-prompt-empty="1" data-prompt="Tatsächlicher Preis in € (leer = geschätzter Preis, 0 = keine Ausgabe eintragen):" data-prompt-field="price" data-prompt-value="<?php echo e(number_format((float)$w['price'], 2, ',', '')); ?>">
                                <input type="hidden" name="action" value="wish_buy"><input type="hidden" name="con" value="<?php echo e($con['id']); ?>"><input type="hidden" name="id" value="<?php echo e($w['id']); ?>"><input type="hidden" name="price">
                                <button class="btn btn-sm btn-gold" title="Gekauft – wird als Ausgabe eingetragen">✓ Gekauft</button></form>
                        <?php else: echo tpl_button('wish_unbuy', $cf + ['id' => $w['id']], '↺', 'Doch nicht gekauft (Ausgabe wird entfernt)'); endif; ?>
                        <button type="button" class="ic" title="Bearbeiten" data-open="wishModal" data-title="Wunsch bearbeiten" data-fill="<?php echo tpl_fill(['id' => $w['id'], 'title' => $w['title'], 'shop' => $w['shop'], 'price' => number_format((float)$w['price'], 2, ',', ''), 'prio' => (string)$w['prio'], 'cat' => $w['cat'], 'link' => $w['link'], 'note' => $w['note']]); ?>">✎</button>
                        <?php echo tpl_button('wish_delete', $cf + ['id' => $w['id']], '✕', 'Löschen', 'ic danger', 'Wunsch „' . $w['title'] . '“ löschen?'); ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$wl): ?><div class="empty-msg">Noch keine Wünsche – Art, Badges, Sticker, Plüschis … mit Preis eintragen und vor Ort abhaken.</div><?php endif; ?>
    </section>

    <!-- ================= Notizen ================= -->
    <section class="cn-pane" data-pane="notes" hidden>
        <div class="cn-grid2">
            <div class="panel">
                <div class="panel-title">Notizen &amp; Infos</div>
                <?php if (trim($con['notes']) !== ''): ?><div class="fmt"><?php echo format_text($con['notes']); ?></div><?php else: ?><div class="cn-empty">Noch keine Notizen.</div><?php endif; ?>
                <?php if ($con['hotel'] !== ''): ?><p class="cn-hotel">🏨 <b>Hotel:</b> <?php echo e($con['hotel']); ?> · <a href="https://www.openstreetmap.org/search?query=<?php echo e(rawurlencode($con['hotel'])); ?>" target="_blank" rel="noopener noreferrer">Karte ↗</a></p><?php endif; ?>
            </div>
            <form method="POST" class="panel">
                <input type="hidden" name="action" value="notes_save"><input type="hidden" name="con" value="<?php echo e($con['id']); ?>">
                <div class="panel-title">Bearbeiten</div>
                <label class="field">Hotel / Unterkunft (Name &amp; Adresse)</label>
                <input type="text" name="hotel" maxlength="200" value="<?php echo e($con['hotel']); ?>">
                <label class="field" style="margin-top: 10px;">Notizen (Anreise, Zimmernummer, Orga-Links, Treffpunkte …)</label>
                <textarea name="notes" rows="12" data-format="text"><?php echo e($con['notes']); ?></textarea>
                <button class="btn btn-gold" style="margin-top: 10px;">Speichern</button>
            </form>
        </div>
    </section>

    <!-- ================= Vergleich ================= -->
    <section class="cn-pane" data-pane="compare" hidden>
        <?php
        $rows = [];
        foreach ($data['cons'] as $x) { $xr = con_calc($x); $rows[] = ['c' => $x, 'r' => $xr]; }
        usort($rows, function ($a, $b) { return [$a['c']['start'] ?: date('Y-m-d', $a['c']['created'])] <=> [$b['c']['start'] ?: date('Y-m-d', $b['c']['created'])]; });
        $mxc = max(0.01, max(array_map(function ($x) { return max($x['r']['my'], $x['r']['budget']); }, $rows)));
        $done_rows = array_filter($rows, function ($x) { return $x['r']['my'] > 0; });
        $avg = $done_rows ? array_sum(array_map(function ($x) { return $x['r']['my']; }, $done_rows)) / count($done_rows) : 0;
        $avg_day = array_filter(array_map(function ($x) { return $x['r']['days']['days'] ? $x['r']['my'] / $x['r']['days']['days'] : null; }, $done_rows));
        ?>
        <div class="cn-kpis">
            <div class="k gold"><span>Cons gesamt</span><b><?php echo count($rows); ?></b></div>
            <div class="k"><span>Ausgaben gesamt (dein Anteil)</span><b><?php echo con_money(array_sum(array_map(function ($x) { return $x['r']['my']; }, $rows))); ?></b></div>
            <div class="k"><span>Ø pro Con</span><b><?php echo con_money($avg); ?></b></div>
            <div class="k"><span>Ø pro Con-Tag</span><b><?php echo $avg_day ? con_money(array_sum($avg_day) / count($avg_day)) : '–'; ?></b></div>
        </div>
        <div class="panel">
            <div class="panel-title">Kosten je Con (Balken) · Budget (Linie)</div>
            <div class="cn-cmp">
                <?php foreach ($rows as $x): $c = $x['c']; $xr = $x['r']; ?>
                    <a class="cn-cmprow<?php echo $c['id'] === $con['id'] ? ' on' : ''; ?>" href="con.php?c=<?php echo e(urlencode($c['id'])); ?>">
                        <span class="cn-cmpname"><?php echo e($c['icon'] . ' ' . $c['name']); ?><small><?php echo $c['start'] ? e(date('m/Y', strtotime($c['start']))) : e(CON_STATUS[$c['status']][1]); ?></small></span>
                        <span class="cn-cmpbar">
                            <?php $l = 0; foreach ($xr['my_by_cat'] as $k => $v): $w = $v / $mxc * 100; ?><i style="left: <?php echo round($l, 2); ?>%; width: <?php echo round($w, 2); ?>%; background: <?php echo CON_CATS[$k][2]; ?>;" title="<?php echo e(CON_CATS[$k][1] . ': ' . con_money($v)); ?>"></i><?php $l += $w; endforeach; ?>
                            <?php if ($xr['budget'] > 0): ?><em style="left: <?php echo round($xr['budget'] / $mxc * 100, 2); ?>%;" title="Budget <?php echo e(con_money($xr['budget'])); ?>"></em><?php endif; ?>
                        </span>
                        <b><?php echo con_money($xr['my']); ?></b>
                        <small class="cn-cmpday"><?php echo $xr['days']['days'] ? con_money($xr['my'] / $xr['days']['days']) . '/Tag' : ''; ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="cn-legend"><?php foreach (CON_CATS as $k => [$ic, $n, $col]): ?><span><i style="background: <?php echo $col; ?>"></i><?php echo e($n); ?></span><?php endforeach; ?></div>
        </div>
        <div class="panel dt-wrap">
            <table class="dt">
                <thead><tr><th>Con</th><th>Datum</th><th>Tage</th><th class="t-money">Budget</th><th class="t-money">Kosten</th><th class="t-money">Differenz</th><th>Größter Posten</th></tr></thead>
                <tbody>
                    <?php foreach (array_reverse($rows) as $x): $c = $x['c']; $xr = $x['r']; $top = $xr['my_by_cat'] ? array_keys($xr['my_by_cat'], max($xr['my_by_cat']))[0] : null; ?>
                        <tr><td><a href="con.php?c=<?php echo e(urlencode($c['id'])); ?>"><?php echo e($c['icon'] . ' ' . $c['name']); ?></a> <small class="muted"><?php echo e(CON_STATUS[$c['status']][0]); ?></small></td>
                            <td><?php echo $c['start'] ? e(date('d.m.Y', strtotime($c['start']))) : '–'; ?></td><td><?php echo $xr['days']['days'] ?: '–'; ?></td>
                            <td class="t-money"><?php echo con_money($xr['budget']); ?></td><td class="t-money"><?php echo con_money($xr['my']); ?></td>
                            <td class="t-money <?php echo $xr['rest'] < 0 ? 'neg' : 'pos'; ?>"><?php echo con_money($xr['rest']); ?></td>
                            <td><?php echo $top ? e(CON_CATS[$top][0] . ' ' . CON_CATS[$top][1]) : '–'; ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php
// ---------- Dialoge ----------
tpl_modal_open('conModal', 'Con bearbeiten', 'con_save', false, '640px'); ?>
    <input type="hidden" name="id" value=""><input type="hidden" name="con" value="<?php echo e($con['id']); ?>">
    <div class="pm-row">
        <div style="width: 80px;"><label class="field">Icon</label><input type="text" name="icon" maxlength="8"></div>
        <div style="flex: 1;"><label class="field">Name *</label><input type="text" name="name" maxlength="80" required placeholder="z. B. Eurofurence 30"></div>
        <div><label class="field">Status</label><select name="status"><?php foreach (CON_STATUS as $k => [$ic, $n]): ?><option value="<?php echo $k; ?>"><?php echo $ic . ' ' . e($n); ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="pm-row">
        <div style="flex: 1;"><label class="field">Beginn</label><input type="date" name="start"></div>
        <div style="flex: 1;"><label class="field">Ende</label><input type="date" name="end"></div>
        <div style="flex: 1;"><label class="field">Mein Budget (€)</label><input type="text" name="budget" inputmode="decimal" placeholder="500"></div>
    </div>
    <div class="pm-row">
        <div style="flex: 1;"><label class="field">Stadt</label><input type="text" name="city" maxlength="80" placeholder="Hamburg"></div>
        <div style="flex: 1;"><label class="field">Veranstaltungsort</label><input type="text" name="venue" maxlength="120" placeholder="CCH"></div>
    </div>
    <label class="field">Webseite</label><input type="text" name="website" maxlength="300" placeholder="https://…" inputmode="url">
    <label class="field">Hotel / Unterkunft</label><input type="text" name="hotel" maxlength="200">
    <div class="pm-row">
        <div><label class="field">Währung vor Ort</label><select name="currency"><?php foreach (CON_CURRENCIES as $k => $s): ?><option value="<?php echo $k; ?>"><?php echo $k . ' (' . e($s) . ')'; ?></option><?php endforeach; ?></select></div>
        <div style="flex: 1;"><label class="field">Kurs: 1 Fremdwährung = … €</label><input type="text" name="fx_rate" inputmode="decimal" placeholder="z. B. 0,92 für USD"></div>
    </div>
<?php tpl_modal_close();

tpl_modal_open('expModal', 'Ausgabe', 'exp_save', false, '640px'); ?>
    <input type="hidden" name="id" value=""><input type="hidden" name="con" value="<?php echo e($con['id']); ?>"><input type="hidden" name="tab" value="expenses">
    <label class="field">Bezeichnung *</label><input type="text" name="title" maxlength="120" required>
    <div class="pm-row">
        <div style="flex: 1;"><label class="field">Betrag *</label><input type="text" name="amount" inputmode="decimal" required></div>
        <div><label class="field">Währung</label><select name="currency"><option value="EUR">EUR €</option><?php if ($con['currency'] !== 'EUR'): ?><option value="<?php echo e($con['currency']); ?>"><?php echo e($con['currency'] . ' ' . $cur_sym); ?></option><?php endif; ?></select></div>
        <div style="flex: 1;"><label class="field">Kategorie</label><select name="category"><?php foreach (CON_CATS as $k => [$ic, $n]): ?><option value="<?php echo $k; ?>"><?php echo $ic . ' ' . e($n); ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="pm-row">
        <div style="flex: 1;"><label class="field">Status</label><select name="status"><?php foreach (CON_EXP_STATUS as $k => [$ic, $n]): ?><option value="<?php echo $k; ?>"><?php echo $ic . ' ' . e($n); ?></option><?php endforeach; ?></select></div>
        <div style="flex: 1;"><label class="field">Datum</label><input type="date" name="date"></div>
        <div style="flex: 1;"><label class="field">Fällig am (wenn offen)</label><input type="date" name="due"></div>
    </div>
    <div class="pm-row">
        <div style="flex: 1;"><label class="field">Zahlart</label><select name="method"><?php foreach (CON_METHODS as $k => $n): ?><option value="<?php echo $k; ?>"><?php echo e($n); ?></option><?php endforeach; ?></select></div>
        <div style="flex: 1;"><label class="field">Bezahlt von</label><select name="payer"><?php foreach ($con['people'] as $p): ?><option value="<?php echo e($p['id']); ?>"><?php echo e($p['name']); ?></option><?php endforeach; ?></select></div>
    </div>
    <?php if (count($con['people']) > 1): ?>
        <label class="field">Geteilt mit (keine Auswahl = alle aktuellen Personen)</label>
        <div class="cn-split"><?php foreach ($con['people'] as $p): ?><label class="chip"><input type="checkbox" name="split[]" value="<?php echo e($p['id']); ?>"> <?php echo e($p['name']); ?></label><?php endforeach; ?></div>
    <?php endif; ?>
    <label class="field">Notiz / Buchungsnummer / Link</label>
    <textarea name="docs" rows="3" data-format="text"></textarea>
<?php tpl_modal_close();

tpl_modal_open('budgetModal', 'Budget je Kategorie', 'budget_save'); ?>
    <input type="hidden" name="con" value="<?php echo e($con['id']); ?>">
    <p class="muted small">Optional: Wie viel möchtest du pro Bereich ausgeben? Die Übersicht zeigt dann, wo du drüber liegst.</p>
    <div class="cn-cbgrid">
        <?php foreach (CON_CATS as $k => [$ic, $n]): ?>
            <label class="field"><?php echo $ic . ' ' . e($n); ?></label>
            <input type="text" name="cb[<?php echo $k; ?>]" inputmode="decimal" value="<?php echo isset($con['cat_budgets'][$k]) ? e(number_format($con['cat_budgets'][$k], 2, ',', '')) : ''; ?>" placeholder="–">
        <?php endforeach; ?>
    </div>
<?php tpl_modal_close();

tpl_modal_open('schedModal', 'Programmpunkt', 'sched_save'); ?>
    <input type="hidden" name="id" value=""><input type="hidden" name="con" value="<?php echo e($con['id']); ?>">
    <label class="field">Titel *</label><input type="text" name="title" maxlength="120" required placeholder="z. B. Fursuit Parade, Dealers Den öffnet, Abreise">
    <div class="pm-row">
        <div style="flex: 1;"><label class="field">Datum *</label><input type="date" name="date" required></div>
        <div><label class="field">Von</label><input type="time" name="time"></div>
        <div><label class="field">Bis</label><input type="time" name="end"></div>
    </div>
    <label class="field">Ort / Raum</label><input type="text" name="place" maxlength="80">
    <label class="field">Notiz</label><textarea name="note" rows="3" data-format="text"></textarea>
    <label class="chk"><input type="checkbox" name="fav" value="1"> ★ Favorit (will ich auf keinen Fall verpassen)</label>
<?php tpl_modal_close();

tpl_modal_open('wishModal', 'Wunsch', 'wish_save'); ?>
    <input type="hidden" name="id" value=""><input type="hidden" name="con" value="<?php echo e($con['id']); ?>">
    <label class="field">Was? *</label><input type="text" name="title" maxlength="120" required placeholder="z. B. Badge von …, Plüschi, Print">
    <div class="pm-row">
        <div style="flex: 1;"><label class="field">Künstler*in / Stand</label><input type="text" name="shop" maxlength="80"></div>
        <div style="width: 130px;"><label class="field">Preis ca. (€)</label><input type="text" name="price" inputmode="decimal"></div>
    </div>
    <div class="pm-row">
        <div style="flex: 1;"><label class="field">Wichtigkeit</label><select name="prio"><option value="1">🔥 Muss haben</option><option value="2">⭐ Wäre toll</option><option value="3">💭 Vielleicht</option></select></div>
        <div style="flex: 1;"><label class="field">Kategorie</label><select name="cat"><?php foreach (['merch', 'art', 'suit', 'other'] as $k): ?><option value="<?php echo $k; ?>"><?php echo CON_CATS[$k][0] . ' ' . e(CON_CATS[$k][1]); ?></option><?php endforeach; ?></select></div>
    </div>
    <label class="field">Link (Shop, Galerie)</label><input type="text" name="link" maxlength="500" inputmode="url">
    <label class="field">Notiz</label><input type="text" name="note" maxlength="300" data-format="inline">
<?php tpl_modal_close(); ?>

<script src="assets/js/page.js"></script>
<script src="assets/js/editor.js"></script>
<script src="assets/js/con.js"></script>
</body>
</html>
