<?php
/**
 * Con-Rechner: Datenmodell, Übernahme der alten Daten und alle Berechnungen.
 *
 * con_data.json (Version 2):
 *   ['version' => 2, 'active' => <con-id>, 'cons' => [ <con>, … ], 'imported' => [<archivdatei>, …]]
 * Eine Con enthält Stammdaten, Personen (Mitreisende), Ausgaben, Kategorie-Budgets,
 * Zeitplan, Packliste, Wunschliste (Dealers Den) und Notizen.
 */
require_once __DIR__ . '/common.php';

const CON_FILE = __DIR__ . '/../con_data.json';
const CON_ARCHIVE_DIR = __DIR__ . '/../archive/';

/** Kategorien: Schlüssel => [Icon, Name, Farbe] */
const CON_CATS = [
    'hotel'  => ['🏨', 'Hotel / Unterkunft', '#bd93f9'],
    'travel' => ['🚆', 'Anreise', '#38d5e6'],
    'ticket' => ['🎟️', 'Ticket / Badge', '#f3c262'],
    'food'   => ['🍕', 'Essen & Trinken', '#ffb86c'],
    'merch'  => ['🛍️', 'Dealers Den / Merch', '#ff79c6'],
    'art'    => ['🎨', 'Kunst / Commissions', '#50fa7b'],
    'suit'   => ['🦊', 'Fursuit / Kostüm', '#ff5555'],
    'party'  => ['🎉', 'Party & Events', '#8be9fd'],
    'other'  => ['📦', 'Sonstiges', '#8b98ad'],
];
const CON_LEGACY_CATS = ['Hotel' => 'hotel', 'Bahn' => 'travel', 'Ticket' => 'ticket', 'Food' => 'food', 'Merchandise' => 'merch', 'Anderes' => 'other'];
const CON_EXP_STATUS = ['planned' => ['📝', 'Geplant / Schätzung'], 'open' => ['⏳', 'Gebucht, noch offen'], 'paid' => ['✅', 'Bezahlt']];
const CON_METHODS = ['' => '–', 'bar' => 'Bar', 'karte' => 'Karte', 'paypal' => 'PayPal', 'ueberweisung' => 'Überweisung', 'revolut' => 'Revolut', 'sonst' => 'Sonstiges'];
const CON_STATUS = ['planned' => ['📝', 'In Planung'], 'booked' => ['✅', 'Gebucht'], 'running' => ['🎪', 'Läuft'], 'done' => ['🏁', 'Vorbei']];
const CON_CURRENCIES = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF', 'CZK' => 'Kč', 'PLN' => 'zł', 'SEK' => 'kr', 'DKK' => 'kr', 'JPY' => '¥', 'CAD' => 'C$', 'AUD' => 'A$'];
const CON_PACK_TEMPLATE = [
    'Dokumente' => ['Personalausweis / Reisepass', 'Con-Registrierung / Badge-Bestätigung', 'Hotelbestätigung', 'Bahn- oder Flugticket', 'Bargeld & Karte', 'Krankenkassenkarte'],
    'Technik'   => ['Handy + Ladekabel', 'Powerbank', 'Kopfhörer', 'Mehrfachsteckdose', 'Kamera / Akkus'],
    'Kleidung'  => ['T-Shirts (1 pro Tag + 1)', 'Unterwäsche & Socken', 'Bequeme Schuhe', 'Pullover / Jacke', 'Schlafsachen', 'Outfit für Party / Abend'],
    'Fursuit'   => ['Kopf', 'Pfoten', 'Füße', 'Schwanz', 'Bodysuit', 'Kühlweste + Kühlakkus', 'Lüfter + Akkus', 'Desinfektions-/Deospray', 'Reparaturset (Nadel, Faden, Kleber)', 'Transporttasche'],
    'Hygiene'   => ['Zahnbürste & Zahnpasta', 'Duschgel & Shampoo', 'Deo', 'Medikamente', 'Pflaster', 'Ohrstöpsel'],
    'Con-Kram'  => ['Badge-Halter / Lanyard', 'Wasserflasche', 'Snacks', 'Taschen für Dealers-Den-Einkäufe', 'Stifte & Marker (Autogramme, Skizzen)', 'Visitenkarten / Sticker zum Tauschen'],
];

// ---------- Laden, Speichern, Übernahme ----------

function con_id(string $p = 'c'): string { return $p . bin2hex(random_bytes(5)); }

function con_blank(array $over = []): array {
    return $over + [
        'id' => con_id(), 'name' => 'Neue Con', 'icon' => '🎪', 'start' => '', 'end' => '', 'city' => '', 'venue' => '', 'website' => '', 'hotel' => '',
        'budget' => 0.0, 'currency' => 'EUR', 'fx_rate' => 1.0, 'status' => 'planned', 'notes' => '',
        'people' => [['id' => 'me', 'name' => 'Ich', 'me' => true]],
        'expenses' => [], 'cat_budgets' => [], 'schedule' => [], 'packing' => [], 'wishlist' => [], 'created' => time(),
    ];
}

/** Eine Con auf alle Felder bringen (für ältere Daten) */
function con_fill(array $c): array {
    $c = $c + con_blank(['id' => $c['id'] ?? con_id()]);
    if (!array_filter($c['people'], function ($p) { return !empty($p['me']); })) array_unshift($c['people'], ['id' => 'me', 'name' => 'Ich', 'me' => true]);
    foreach ($c['expenses'] as &$e) {
        $e += ['currency' => 'EUR', 'status' => 'paid', 'due' => '', 'method' => '', 'payer' => 'me', 'split' => [], 'docs' => '', 'date' => '', 'category' => 'other'];
        if (!isset(CON_CATS[$e['category']])) $e['category'] = CON_LEGACY_CATS[$e['category']] ?? 'other';
    }
    unset($e);
    return $c;
}

/** Altes Format (eine aktive Con + Archivdateien) übernehmen */
function con_migrate(array $old): array {
    $data = ['version' => 2, 'active' => '', 'cons' => [], 'imported' => []];
    $conv = function (array $o, string $status) {
        $c = con_blank(['name' => trim($o['con_name'] ?? '') ?: 'Unbenannte Con', 'budget' => (float)($o['total_budget'] ?? 0), 'notes' => (string)($o['con_docs'] ?? ''), 'status' => $status]);
        if (trim($c['notes']) === 'Reisepläne, Orga-Infos und wichtige Notizen hier hinterlegen...') $c['notes'] = '';
        foreach ($o['expenses'] ?? [] as $e) {
            $d = DateTime::createFromFormat('d.m.Y H:i', (string)($e['date'] ?? ''));
            $c['expenses'][] = ['id' => (string)($e['id'] ?? con_id('e')), 'title' => (string)($e['title'] ?? ''), 'amount' => (float)($e['amount'] ?? 0),
                                'category' => CON_LEGACY_CATS[$e['category'] ?? ''] ?? 'other', 'status' => ($e['status'] ?? 'Bezahlt') === 'Offen' ? 'open' : 'paid',
                                'docs' => (string)($e['docs'] ?? ''), 'date' => $d ? $d->format('Y-m-d') : '', 'currency' => 'EUR', 'payer' => 'me', 'split' => [], 'due' => '', 'method' => ''];
        }
        return $c;
    };
    if ($old && (isset($old['con_name']) || !empty($old['expenses']))) {
        $c = $conv($old, 'planned');
        $data['cons'][] = $c;
        $data['active'] = $c['id'];
    }
    return $data;
}

/** Archivierte Cons (archive/con_*.json) einmalig als „vorbei“ übernehmen */
function con_import_archives(array &$data): bool {
    $changed = false;
    foreach (glob(CON_ARCHIVE_DIR . 'con_*.json') ?: [] as $f) {
        $base = basename($f);
        if (in_array($base, $data['imported'], true)) continue;
        $o = load_json($f, null);
        $data['imported'][] = $base;
        $changed = true;
        if (!is_array($o) || !isset($o['con_name'])) continue;
        $c = con_migrate($o)['cons'][0] ?? null;
        if (!$c) continue;
        $c['status'] = 'done';
        if (preg_match('/_(\d{4}-\d{2}-\d{2})_\d{6}\.json$/', $base, $m)) { $c['end'] = $m[1]; $c['created'] = strtotime($m[1]); }
        $c['archived_from'] = $base;
        $data['cons'][] = $c;
    }
    return $changed;
}

function con_load(): array {
    $raw = load_json(CON_FILE, []);
    $changed = false;
    if (($raw['version'] ?? 0) < 2) {
        $data = con_migrate($raw);
        if (is_file(CON_FILE)) @copy(CON_FILE, CON_FILE . '.v1-backup');
        $changed = true;
    } else {
        $data = $raw + ['imported' => [], 'cons' => [], 'active' => ''];
    }
    if (con_import_archives($data)) $changed = true;
    if (!$data['cons']) {
        $data['cons'][] = con_blank(['name' => 'Eurofurence', 'icon' => '🐾', 'city' => 'Hamburg', 'website' => 'https://www.eurofurence.org', 'budget' => 500]);
        $changed = true;
    }
    $data['cons'] = array_map('con_fill', $data['cons']);
    if (con_index($data, (string)$data['active']) === null) $data['active'] = $data['cons'][0]['id'];
    if ($changed) con_save($data);
    return $data;
}

function con_save(array $data): void {
    save_json(CON_FILE, $data);
}

/** Index einer Con (oder null) – 0 ist gültig, daher === null prüfen */
function con_index(array $data, string $id): ?int {
    foreach ($data['cons'] as $i => $c) if ($c['id'] === $id) return $i;
    return null;
}

function con_item_index(array $list, string $id): ?int {
    foreach ($list as $i => $x) if (($x['id'] ?? '') === $id) return $i;
    return null;
}

// ---------- Berechnungen ----------

function con_eur(array $con, array $e): float {
    return ($e['currency'] ?? 'EUR') === 'EUR' ? (float)$e['amount'] : (float)$e['amount'] * (float)($con['fx_rate'] ?: 1);
}

function con_money(float $v): string { return eur($v) . ' €'; }

function con_cur(array $con, array $e): string {
    $c = $e['currency'] ?? 'EUR';
    return $c === 'EUR' ? con_money((float)$e['amount']) : number_format((float)$e['amount'], 2, ',', '.') . ' ' . (CON_CURRENCIES[$c] ?? $c);
}

/** Wer teilt sich eine Ausgabe? (leer = alle) */
function con_split(array $con, array $e): array {
    $ids = array_column($con['people'], 'id');
    $split = array_values(array_intersect((array)($e['split'] ?? []), $ids));
    return $split ?: $ids;
}

function con_days(array $con): array {
    $s = $con['start'] ? strtotime($con['start']) : null;
    $e = $con['end'] ? strtotime($con['end']) : $s;
    $today = strtotime(date('Y-m-d'));
    $out = ['days' => 0, 'nights' => 0, 'until' => null, 'phase' => 'unknown', 'day_no' => 0];
    if (!$s) return $out;
    $out['nights'] = max(0, (int)round(($e - $s) / 86400));
    $out['days'] = $out['nights'] + 1;
    $out['until'] = (int)round(($s - $today) / 86400);
    if ($today < $s) $out['phase'] = 'before';
    elseif ($today <= $e) { $out['phase'] = 'running'; $out['day_no'] = (int)round(($today - $s) / 86400) + 1; }
    else $out['phase'] = 'after';
    return $out;
}

/** Alles, was die Übersicht braucht */
function con_calc(array $con): array {
    $people = array_column($con['people'], null, 'id');
    $me = 'me';
    foreach ($con['people'] as $p) if (!empty($p['me'])) $me = $p['id'];
    $r = ['total' => 0.0, 'my' => 0.0, 'my_paid' => 0.0, 'my_open' => 0.0, 'my_planned' => 0.0, 'paid' => 0.0, 'open' => 0.0, 'planned' => 0.0,
          'by_cat' => [], 'my_by_cat' => [], 'balance' => array_fill_keys(array_keys($people), 0.0), 'paid_by' => array_fill_keys(array_keys($people), 0.0),
          'share' => array_fill_keys(array_keys($people), 0.0), 'dues' => [], 'by_day' => [], 'me' => $me];
    foreach ($con['expenses'] as $e) {
        $v = con_eur($con, $e);
        $st = $e['status'];
        $r['total'] += $v;
        $r[$st] = ($r[$st] ?? 0) + $v;
        $r['by_cat'][$e['category']] = ($r['by_cat'][$e['category']] ?? 0) + $v;
        $split = con_split($con, $e);
        $share = $v / max(1, count($split));
        $payer = isset($people[$e['payer']]) ? $e['payer'] : $me;
        if ($st !== 'planned') {
            // Nur echte Ausgaben zählen für die Abrechnung „wer schuldet wem“
            $r['balance'][$payer] += $v;
            $r['paid_by'][$payer] += $v;
            foreach ($split as $pid) $r['balance'][$pid] -= $share;
        }
        foreach ($split as $pid) $r['share'][$pid] += $share;
        if (in_array($me, $split, true)) {
            $r['my'] += $share;
            $r['my_' . $st] += $share;
            $r['my_by_cat'][$e['category']] = ($r['my_by_cat'][$e['category']] ?? 0) + $share;
        }
        if ($st === 'open') $r['dues'][] = $e + ['eur' => $v];
        if ($e['date'] !== '' && $st !== 'planned') $r['by_day'][$e['date']] = ($r['by_day'][$e['date']] ?? 0) + $v;
    }
    usort($r['dues'], function ($a, $b) { return [$a['due'] === '' ? '9' : $a['due']] <=> [$b['due'] === '' ? '9' : $b['due']]; });
    arsort($r['by_cat']);
    ksort($r['by_day']);
    $r['settle'] = con_settle($r['balance']);
    $d = con_days($con);
    $r['days'] = $d;
    $r['budget'] = (float)$con['budget'];
    $r['rest'] = $r['budget'] - $r['my'];
    $r['ratio'] = $r['budget'] > 0 ? $r['my'] / $r['budget'] * 100 : ($r['my'] > 0 ? 100 : 0);
    $r['per_day'] = $d['days'] > 0 ? $r['rest'] / $d['days'] : null;
    // Sparziel: was ich bis zur Con noch aufbringen muss (alles außer bereits Bezahltem)
    $r['to_save'] = max(0, $r['my'] - $r['my_paid']);
    $months = $d['until'] !== null && $d['until'] > 0 ? max(1, $d['until'] / 30.44) : null;
    $r['save_month'] = $months ? $r['to_save'] / $months : null;
    $r['save_week'] = $d['until'] !== null && $d['until'] > 0 ? $r['to_save'] / max(1, $d['until'] / 7) : null;
    $wish = array_filter($con['wishlist'], function ($w) { return empty($w['bought']); });
    $r['wish_open'] = array_sum(array_map(function ($w) { return (float)$w['price']; }, $wish));
    $r['wish_count'] = count($wish);
    $all = count($con['packing']);
    $done = count(array_filter($con['packing'], function ($p) { return !empty($p['done']); }));
    $r['pack'] = [$done, $all];
    return $r;
}

/** Ausgleichszahlungen: wenige Überweisungen, damit alle Salden 0 werden */
function con_settle(array $balance): array {
    $cred = [];
    $debt = [];
    foreach ($balance as $id => $b) {
        if ($b > 0.005) $cred[$id] = $b;
        elseif ($b < -0.005) $debt[$id] = -$b;
    }
    arsort($cred);
    arsort($debt);
    $out = [];
    foreach ($debt as $d => $amount) {
        foreach ($cred as $c => $have) {
            if ($amount < 0.005) break;
            if ($have < 0.005) continue;
            $pay = min($amount, $have);
            $out[] = ['from' => $d, 'to' => $c, 'amount' => round($pay, 2)];
            $amount -= $pay;
            $cred[$c] -= $pay;
        }
    }
    return $out;
}

/** Kurzinfo für die Kachel im Index */
function con_live_info(): string {
    try {
        $data = load_json(CON_FILE, []);
        if (($data['version'] ?? 0) < 2) return '';
        $c = null;
        foreach ($data['cons'] as $x) if ($x['id'] === $data['active']) $c = con_fill($x);
        if (!$c) return '';
        $r = con_calc($c);
        $d = $r['days'];
        $when = $d['phase'] === 'before' ? ' · in ' . $d['until'] . ' Tagen' : ($d['phase'] === 'running' ? ' · Tag ' . $d['day_no'] : '');
        return $c['icon'] . ' ' . $c['name'] . $when . ' · Rest ' . con_money($r['rest']);
    } catch (Throwable $e) {
        return '';
    }
}
