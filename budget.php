<?php
require_once __DIR__ . '/inc/common.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/openbanking.php';

// Nur für angemeldete Admins sichtbar – alle anderen landen beim Login
require_admin_page();
guard_post();

// Dateipfade
$data_file = __DIR__ . '/budget_data.json';
$fix_subs_file = __DIR__ . '/fix_subs_data.json';
$archive_dir = __DIR__ . '/archive_budget/';

if (!is_dir($archive_dir)) {
    mkdir($archive_dir, 0755, true);
}

$valid_sections = ['income', 'fixed', 'subs', 'other'];

// 1. Monatliche Daten laden
$data = load_json($data_file, ['month_name' => german_month_year(), 'entries' => []]);
$data['entries'] = $data['entries'] ?? [];

// 2. Fixkosten & Abos laden
$default_fix_subs = [
    'fixed' => [
        ['id' => 'f1', 'title' => 'Miete', 'amount' => 650.00, 'category' => 'Wohnen', 'docs' => 'Kontoüberweisung'],
        ['id' => 'f2', 'title' => 'Strom', 'amount' => 80.00, 'category' => 'Energie', 'docs' => 'Stadtwerke']
    ],
    'subs' => [
        ['id' => 's1', 'title' => 'Netflix', 'amount' => 13.99, 'category' => 'Streaming', 'docs' => 'PayPal'],
        ['id' => 's2', 'title' => 'Gym', 'amount' => 35.00, 'category' => 'Sport', 'docs' => 'Lastschrift']
    ]
];

if (is_file($fix_subs_file)) {
    $fix_subs_data = load_json($fix_subs_file, ['fixed' => [], 'subs' => []]);
} else {
    $fix_subs_data = $default_fix_subs;
    save_json($fix_subs_file, $fix_subs_data);
}
$fix_subs_data += ['fixed' => [], 'subs' => []];

/** "Erledigt"-Status je nach Sektion: Einnahmen werden "Erhalten", Ausgaben "Bezahlt". */
function done_label(string $section): string {
    return $section === 'income' ? 'Erhalten' : 'Bezahlt';
}

/** Status vereinheitlichen: alles außer "Offen" gilt als erledigt. */
function normalize_status(string $section, ?string $status): string {
    return $status === 'Offen' ? 'Offen' : done_label($section);
}

/** Monatseintrag aus einer Fixkosten-/Abo-Vorlage erzeugen. */
function entry_from_template(array $tpl, string $section): array {
    return [
        'id' => uniqid(),
        'section' => $section,
        'title' => $tpl['title'],
        'amount' => $tpl['amount'],
        'category' => $tpl['category'],
        'status' => 'Bezahlt',
        'docs' => $tpl['docs'] ?? '',
        'date' => date('d.m.Y H:i'),
        'template_id' => $tpl['id']
    ];
}

/**
 * Fixkosten / Abos in den aktuellen Monat übernehmen.
 * Jede Vorlage wird pro Monat nur EINMAL eingefügt (gemerkt in "synced_templates").
 * Dadurch bleibt ein bewusst gelöschter Posten auch gelöscht und taucht nicht beim
 * nächsten Seitenaufruf wieder auf.
 */
function ensure_fix_subs_in_month(array &$data, array $fix_subs_data): bool {
    $changed = false;

    // Umstellung alter Daten: bereits vorhandene Posten als "übernommen" markieren
    if (!isset($data['synced_templates'])) {
        $data['synced_templates'] = [];
        $existing_titles = [];
        foreach ($data['entries'] as $e) {
            if (!empty($e['template_id'])) $data['synced_templates'][] = $e['template_id'];
            $existing_titles[($e['section'] ?? '') . '|' . mb_strtolower($e['title'] ?? '')] = true;
        }
        foreach (['fixed', 'subs'] as $sec) {
            foreach ($fix_subs_data[$sec] as $tpl) {
                if (isset($existing_titles[$sec . '|' . mb_strtolower($tpl['title'])])) {
                    $data['synced_templates'][] = $tpl['id'];
                }
            }
        }
        $changed = true;
    }

    foreach (['fixed', 'subs'] as $sec) {
        foreach ($fix_subs_data[$sec] as $tpl) {
            if (!in_array($tpl['id'], $data['synced_templates'], true)) {
                $data['entries'][] = entry_from_template($tpl, $sec);
                $data['synced_templates'][] = $tpl['id'];
                $changed = true;
            }
        }
    }

    $data['synced_templates'] = array_values(array_unique($data['synced_templates']));
    return $changed;
}

if (ensure_fix_subs_in_month($data, $fix_subs_data)) {
    save_json($data_file, $data);
}

// ================= BANK-SYNC (Enable Banking / Open Banking) =================
$ob = ob_config();
$ob_flash = function (string $type, string $text) { $_SESSION['ob_flash'] = [$type, $text]; };

// Rückkehr von der Bank (Enable Banking leitet mit ?code=…&state=… bzw. ?error=… auf budget.php zurück)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['code']) || (isset($_GET['error']) && isset($_GET['state'])))) {
    if (isset($_GET['error'])) {
        $ob_flash('err', 'Die Bank-Freigabe wurde abgebrochen: ' . ($_GET['error_description'] ?? $_GET['error']));
    } else {
        try {
            $ob = ob_finish_auth($ob, (string)$_GET['code'], (string)($_GET['state'] ?? ''));
            ob_save($ob);
            auth_log('Bank verbunden: ' . ($ob['aspsp']['name'] ?? ''), auth_user());
            $stats = ob_sync($ob, $data, true);
            ob_save($ob);
            save_json($data_file, $data);
            $ob_flash('ok', 'Verbunden! ' . count($ob['accounts']) . ' Konto/Konten gefunden, ' . $stats['imported'] . ' Buchungen übernommen, ' . $stats['matched'] . ' Fixkosten/Abos abgeglichen.');
        } catch (Throwable $e) {
            $ob_flash('err', $e->getMessage());
        }
    }
    header('Location: budget.php?view=bank');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ob_action'])) {
    try {
        switch ($_POST['ob_action']) {
            case 'save_credentials':
                $app_id = trim($_POST['app_id'] ?? '');
                $pem = '';
                if (!empty($_FILES['pem_file']['tmp_name']) && $_FILES['pem_file']['error'] === UPLOAD_ERR_OK) {
                    $pem = (string)file_get_contents($_FILES['pem_file']['tmp_name']);
                } elseif (trim($_POST['pem_text'] ?? '') !== '') {
                    $pem = trim($_POST['pem_text']);
                }
                if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $app_id)) {
                    throw new RuntimeException('Die Application-ID hat das Format aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee (steht auch im Dateinamen der .pem).');
                }
                if ($pem === '' && $ob['private_key'] === '') throw new RuntimeException('Bitte die .pem-Schlüsseldatei hochladen.');
                if ($pem !== '' && !openssl_pkey_get_private($pem)) throw new RuntimeException('Die Datei ist kein gültiger privater RSA-Schlüssel (.pem).');
                if ($ob['app_id'] !== $app_id && $ob['session_id'] !== '') $ob = ob_disconnect($ob);
                $ob['app_id'] = $app_id;
                if ($pem !== '') $ob['private_key'] = $pem;
                // Verbindung gleich testen
                $app = ob_request($ob, 'GET', '/application');
                ob_save($ob);
                auth_log('Bank-Zugang hinterlegt', auth_user());
                $redirect_ok = in_array(ob_redirect_url(), (array)($app['redirect_urls'] ?? []), true);
                $ob_flash($redirect_ok ? 'ok' : 'warn', 'Zugang gespeichert – App „' . ($app['name'] ?? '?') . '“ (' . ($app['environment'] ?? '?') . ', ' . (!empty($app['active']) ? 'aktiv' : 'noch NICHT aktiviert') . ').'
                    . ($redirect_ok ? '' : ' Achtung: Die Redirect-URL ' . ob_redirect_url() . ' ist in der App nicht eingetragen – bitte im Enable-Banking-Control-Panel ergänzen.'));
                break;

            case 'search_banks':
                $_SESSION['ob_banks'] = ob_aspsps($ob, $_POST['country'] ?? 'DE');
                $_SESSION['ob_country'] = strtoupper($_POST['country'] ?? 'DE');
                if (!$_SESSION['ob_banks']) $ob_flash('warn', 'Für dieses Land wurden keine Banken gefunden.');
                break;

            case 'select_bank':
                foreach ($_SESSION['ob_banks'] ?? [] as $bank) {
                    if ($bank['name'] . '|' . $bank['country'] === ($_POST['bank'] ?? '')) {
                        $ob['aspsp'] = $bank;
                        ob_save($ob);
                        $ob_flash('ok', 'Bank „' . $bank['name'] . '“ (' . $bank['country'] . ') ausgewählt – jetzt verbinden.');
                    }
                }
                unset($_SESSION['ob_banks']);
                break;

            case 'connect':
                if (!$ob['aspsp']) throw new RuntimeException('Bitte zuerst eine Bank auswählen.');
                header('Location: ' . ob_start_auth($ob));
                exit;

            case 'sync':
                $stats = ob_sync($ob, $data, true);
                ob_save($ob);
                save_json($data_file, $data);
                $ob_flash('ok', 'Abgleich fertig: ' . $stats['imported'] . ' neu übernommen, ' . $stats['matched'] . ' Fixkosten/Abos abgeglichen, '
                    . $stats['duplicates'] . ' schon vorhanden, ' . $stats['skipped'] . ' interne Umbuchungen übersprungen'
                    . ($stats['pending'] ? ', ' . $stats['pending'] . ' noch vorgemerkt' : '') . '.');
                break;

            case 'toggle_account':
                foreach ($ob['accounts'] as &$acc) if ($acc['uid'] === ($_POST['uid'] ?? '')) $acc['sync'] = empty($acc['sync']);
                unset($acc);
                ob_save($ob);
                break;

            case 'toggle_auto':
                $ob['auto_sync'] = empty($ob['auto_sync']);
                ob_save($ob);
                break;

            case 'disconnect':
                $ob = ob_disconnect($ob);
                ob_save($ob);
                auth_log('Bank getrennt', auth_user());
                $ob_flash('ok', 'Bankverbindung getrennt. Bereits übernommene Buchungen bleiben im Budget.');
                break;

            case 'forget':
                ob_disconnect($ob);
                @unlink(OB_FILE);
                auth_log('Bank-Zugang gelöscht', auth_user());
                $ob_flash('ok', 'Alle Bank-Zugangsdaten wurden gelöscht.');
                break;
        }
    } catch (Throwable $e) {
        $ob_flash('err', $e->getMessage());
    }
    header('Location: ' . ((($_POST['back'] ?? '') === 'active') ? 'budget.php' : 'budget.php?view=bank'));
    exit;
}

// Automatischer Abgleich beim Öffnen des Budgets (höchstens alle 6 Std.)
$ob_auto_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['view'] ?? 'active') === 'active' && !isset($_GET['export'])
    && ob_connected($ob) && !empty($ob['auto_sync']) && time() - (int)$ob['last_sync'] > OB_AUTO_INTERVAL) {
    try {
        $stats = ob_sync($ob, $data, false);
        save_json($data_file, $data);
        if ($stats['imported'] || $stats['matched']) $ob_flash('ok', 'Automatischer Bank-Abgleich: ' . $stats['imported'] . ' neue Buchungen, ' . $stats['matched'] . ' abgeglichen.');
    } catch (Throwable $e) {
        $ob['last_sync'] = time();   // nicht bei jedem Aufruf erneut versuchen
        $ob_auto_error = $e->getMessage();
    }
    ob_save($ob);
}
$ob_message = $_SESSION['ob_flash'] ?? null;
unset($_SESSION['ob_flash']);

/** CSV-Trennzeichen anhand der Kopfzeile erkennen (Komma oder Semikolon). */
function detect_csv_delimiter(string $line): string {
    return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
}

// POST-Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Monatsnamen anpassen
    if (isset($_POST['update_month'])) {
        $data['month_name'] = trim($_POST['month_name'] ?? '') ?: 'Unbenannter Monat';
        save_json($data_file, $data);
        header('Location: budget.php');
        exit;
    }

    // CSV-Import verarbeiten (Filter für interne Transfers / Unterkonten)
    if (isset($_POST['import_csv'])) {
        $imported = 0;
        $skipped = 0;
        $duplicates = 0;

        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');

            if ($handle !== false) {
                // Kopfzeile lesen, BOM entfernen und Trennzeichen erkennen
                $first_line = (string)fgets($handle);
                $first_line = preg_replace('/^\xEF\xBB\xBF/', '', $first_line);
                $delimiter = detect_csv_delimiter($first_line);

                // Bereits vorhandene Buchungen merken, damit ein doppelter Import nichts verdoppelt
                $existing = [];
                foreach ($data['entries'] as $e) {
                    $existing[($e['import_key'] ?? '')] = true;
                }

                while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                    if (count($row) < 6) continue;

                    // Revolut-Format: Type, Product, Started Date, Completed Date, Description, Amount, Fee, Currency, State, Balance
                    $art = trim($row[0] ?? '');
                    $date_str = trim($row[2] ?? '');
                    $desc = trim($row[4] ?? '') ?: 'Import';
                    $amount = parse_amount($row[5] ?? '0') - abs(parse_amount($row[6] ?? '0'));
                    $state = strtoupper(trim($row[8] ?? ''));

                    if ($amount == 0) continue;

                    // --- INTERNE TRANSFERS, UNTERKONTEN & FEHLGESCHLAGENE BUCHUNGEN FILTERN ---
                    $class = budget_classify($desc, $amount, $art);
                    if ($class === null || in_array($state, ['REVERTED', 'DECLINED', 'FAILED'], true)) {
                        $skipped++;
                        continue;
                    }

                    $timestamp = strtotime($date_str) ?: time();
                    $import_key = md5(date('Y-m-d H:i', $timestamp) . '|' . $desc . '|' . $amount);
                    if (isset($existing[$import_key])) {
                        $duplicates++;
                        continue;
                    }
                    $existing[$import_key] = true;

                    // Sektion & Kategorie (gemeinsame Regeln mit dem Bank-Sync, siehe inc/openbanking.php)
                    $section = $class['section'];
                    $category = $class['category'];
                    $amount = abs($amount);

                    $data['entries'][] = [
                        'id' => uniqid(),
                        'section' => $section,
                        'title' => $desc,
                        'amount' => $amount,
                        'category' => $category,
                        'status' => done_label($section),
                        'docs' => 'CSV-Import (' . $art . ')',
                        'date' => date('d.m.Y H:i', $timestamp),
                        'import_key' => $import_key
                    ];
                    $imported++;
                }
                fclose($handle);
                save_json($data_file, $data);
            }
        }
        header('Location: budget.php?view=import&imported=' . $imported . '&skipped=' . $skipped . '&duplicates=' . $duplicates);
        exit;
    }

    // Normalen Eintrag hinzufügen
    if (isset($_POST['add_entry'])) {
        $section = in_array($_POST['section'] ?? '', $valid_sections, true) ? $_POST['section'] : 'other';
        $title = trim($_POST['title'] ?? '');
        $amount = parse_amount($_POST['amount'] ?? 0);
        $category = trim($_POST['category'] ?? '') ?: ($section === 'income' ? 'Lohn / Abschlag' : 'Sonstiges');

        if ($title !== '' && $amount > 0) {
            $data['entries'][] = [
                'id' => uniqid(),
                'section' => $section,
                'title' => $title,
                'amount' => $amount,
                'category' => $category,
                'status' => normalize_status($section, $_POST['status'] ?? null),
                'docs' => trim($_POST['docs'] ?? ''),
                'date' => date('d.m.Y H:i')
            ];
            save_json($data_file, $data);
        }
        header('Location: budget.php');
        exit;
    }

    // Fixkosten / Abos Vorlage hinzufügen
    if (isset($_POST['add_fix_sub_item'])) {
        $sec = ($_POST['section'] ?? '') === 'subs' ? 'subs' : 'fixed';
        $title = trim($_POST['title'] ?? '');
        $amount = parse_amount($_POST['amount'] ?? 0);

        if ($title !== '' && $amount > 0) {
            $tpl = [
                'id' => uniqid(),
                'title' => $title,
                'amount' => $amount,
                'category' => trim($_POST['category'] ?? ''),
                'docs' => trim($_POST['docs'] ?? '')
            ];
            $fix_subs_data[$sec][] = $tpl;
            save_json($fix_subs_file, $fix_subs_data);

            $data['entries'][] = entry_from_template($tpl, $sec);
            $data['synced_templates'][] = $tpl['id'];
            save_json($data_file, $data);
        }
        header('Location: budget.php?view=templates');
        exit;
    }

    // Fixkosten / Abos Vorlage bearbeiten (auch Wechsel zwischen Fixkosten <-> Abo)
    if (isset($_POST['edit_fix_sub_item'])) {
        $new_sec = ($_POST['section'] ?? '') === 'subs' ? 'subs' : 'fixed';
        $id = $_POST['item_id'] ?? '';
        $updated = null;

        foreach (['fixed', 'subs'] as $sec) {
            foreach ($fix_subs_data[$sec] as $i => $item) {
                if ($item['id'] === $id) {
                    $updated = array_merge($item, [
                        'title' => trim($_POST['title'] ?? '') ?: $item['title'],
                        'amount' => parse_amount($_POST['amount'] ?? $item['amount']),
                        'category' => trim($_POST['category'] ?? ''),
                        'docs' => trim($_POST['docs'] ?? '')
                    ]);
                    unset($fix_subs_data[$sec][$i]);
                    $fix_subs_data[$sec] = array_values($fix_subs_data[$sec]);
                    break 2;
                }
            }
        }

        if ($updated) {
            $fix_subs_data[$new_sec][] = $updated;
            save_json($fix_subs_file, $fix_subs_data);

            foreach ($data['entries'] as &$entry) {
                if (($entry['template_id'] ?? '') === $id) {
                    $entry['section'] = $new_sec;
                    $entry['title'] = $updated['title'];
                    $entry['amount'] = $updated['amount'];
                    $entry['category'] = $updated['category'];
                    $entry['docs'] = $updated['docs'];
                }
            }
            unset($entry);
            save_json($data_file, $data);
        }

        header('Location: budget.php?view=templates');
        exit;
    }

    // Fixkosten / Abos Vorlage löschen (der Posten im laufenden Monat bleibt erhalten)
    if (isset($_POST['delete_fix_sub_item'])) {
        $sec = ($_POST['section'] ?? '') === 'subs' ? 'subs' : 'fixed';
        $id = $_POST['item_id'] ?? '';
        $fix_subs_data[$sec] = array_values(array_filter($fix_subs_data[$sec], function ($item) use ($id) {
            return $item['id'] !== $id;
        }));
        save_json($fix_subs_file, $fix_subs_data);
        header('Location: budget.php?view=templates');
        exit;
    }

    // Monatseintrag bearbeiten
    if (isset($_POST['edit_entry'])) {
        $id = $_POST['entry_id'] ?? '';
        $section = in_array($_POST['section'] ?? '', $valid_sections, true) ? $_POST['section'] : 'other';

        foreach ($data['entries'] as &$entry) {
            if ($entry['id'] === $id) {
                $entry['section'] = $section;
                $entry['title'] = trim($_POST['title'] ?? '') ?: $entry['title'];
                $entry['amount'] = parse_amount($_POST['amount'] ?? $entry['amount']);
                $entry['category'] = trim($_POST['category'] ?? '');
                $entry['status'] = normalize_status($section, $_POST['status'] ?? null);
                $entry['docs'] = trim($_POST['docs'] ?? '');
                break;
            }
        }
        unset($entry);
        save_json($data_file, $data);
        header('Location: budget.php');
        exit;
    }

    // Status umschalten
    if (isset($_POST['toggle_status'])) {
        $id = $_POST['entry_id'] ?? '';
        foreach ($data['entries'] as &$entry) {
            if ($entry['id'] === $id) {
                $entry['status'] = (($entry['status'] ?? '') === 'Offen') ? done_label($entry['section']) : 'Offen';
                break;
            }
        }
        unset($entry);
        save_json($data_file, $data);
        header('Location: budget.php');
        exit;
    }

    // Eintrag löschen
    if (isset($_POST['delete_entry'])) {
        $id = $_POST['entry_id'] ?? '';
        $data['entries'] = array_values(array_filter($data['entries'], function ($item) use ($id) {
            return $item['id'] !== $id;
        }));
        save_json($data_file, $data);
        header('Location: budget.php');
        exit;
    }

    // Monat archivieren & Neustart
    if (isset($_POST['archive_month'])) {
        $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $data['month_name']);
        $archive_file = $archive_dir . 'budget_' . $safe_name . '_' . date('Y-m-d_His') . '.json';
        save_json($archive_file, $data);

        $new_entries = [];
        $synced = [];
        foreach (['fixed', 'subs'] as $sec) {
            foreach ($fix_subs_data[$sec] as $tpl) {
                $new_entries[] = entry_from_template($tpl, $sec);
                $synced[] = $tpl['id'];
            }
        }

        // Nächster Monat: ausgehend vom 1. des aktuellen Monats (vermeidet den 31.-Januar-Sprung in den März)
        $data = [
            'month_name' => german_month_year(strtotime('first day of next month')),
            'entries' => $new_entries,
            'synced_templates' => $synced
        ];
        save_json($data_file, $data);
        header('Location: budget.php');
        exit;
    }

    // Archiv löschen
    if (isset($_POST['delete_archive'])) {
        $target = $archive_dir . basename($_POST['archive_filename'] ?? '');
        if (is_file($target)) unlink($target);
        header('Location: budget.php?view=archive');
        exit;
    }
}

/** Summen je Sektion berechnen */
function budget_totals(array $entries): array {
    $totals = ['income' => 0, 'fixed' => 0, 'subs' => 0, 'other' => 0, 'open' => 0];
    foreach ($entries as $entry) {
        $sec = $entry['section'] ?? 'other';
        if (!isset($totals[$sec]) || $sec === 'open') $sec = 'other';
        $totals[$sec] += (float)$entry['amount'];
        if ($sec !== 'income' && ($entry['status'] ?? '') === 'Offen') $totals['open'] += (float)$entry['amount'];
    }
    $totals['expense'] = $totals['fixed'] + $totals['subs'] + $totals['other'];
    $totals['net'] = $totals['income'] - $totals['expense'];
    return $totals;
}

// Berechnungen
$categorized_entries = ['income' => [], 'fixed' => [], 'subs' => [], 'other' => []];
foreach ($data['entries'] as $entry) {
    $sec = $entry['section'] ?? 'other';
    if (!isset($categorized_entries[$sec])) $sec = 'other';
    $categorized_entries[$sec][] = $entry;
}

$totals = budget_totals($data['entries']);
$total_income = $totals['income'];
$total_expense = $totals['expense'];
$net_balance = $totals['net'];
$total_open = $totals['open'];
$spend_ratio = $total_income > 0 ? min(100, round($total_expense / $total_income * 100)) : ($total_expense > 0 ? 100 : 0);

// CSV-Export (aktueller Monat oder ein Archiv) – Semikolon + BOM, damit Excel Umlaute & Spalten korrekt erkennt
if (($_GET['export'] ?? '') === 'csv') {
    $export = $data;
    if (!empty($_GET['archive']) && is_file($archive_dir . basename($_GET['archive']))) {
        $export = load_json($archive_dir . basename($_GET['archive']), $data);
    }
    $sec_names = ['income' => 'Einnahme', 'fixed' => 'Fixkosten', 'subs' => 'Abo', 'other' => 'Sonstige'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="budget_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $export['month_name']) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Datum', 'Bereich', 'Bezeichnung', 'Kategorie', 'Betrag', 'Status', 'Notizen'], ';');
    foreach ($export['entries'] as $en) {
        $sec = $en['section'] ?? 'other';
        $signed = ($sec === 'income' ? 1 : -1) * (float)$en['amount'];
        fputcsv($out, [$en['date'] ?? '', $sec_names[$sec] ?? $sec, $en['title'], $en['category'] ?? '', number_format($signed, 2, ',', ''), $en['status'] ?? '', $en['docs'] ?? ''], ';');
    }
    fclose($out);
    exit;
}

$view_mode = $_GET['view'] ?? 'active';
$selected_archive = $_GET['archive'] ?? null;
$archive_data = null;
if ($selected_archive && is_file($archive_dir . basename($selected_archive))) {
    $archive_data = load_json($archive_dir . basename($selected_archive), null);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MONATS-FINANZ &amp; LOHN NODE</title>
    <?php echo emoji_favicon('💶'); ?>
    <style>
        :root {
            --bg-color: #040609;
            --panel-bg: rgba(10, 16, 24, 0.85);
            --panel-hover: rgba(18, 28, 42, 0.95);
            --accent-gold: #f3c262;
            --accent-cyan: #38d5e6;
            --accent-red: #ff5555;
            --accent-green: #50fa7b;
            --accent-purple: #bd93f9;
            --accent-glow: rgba(56, 213, 230, 0.3);
            --text-main: #e2e8f0;
            --text-muted: #64748b;
            --border-color: rgba(56, 213, 230, 0.3);
        }

        * { box-sizing: border-box; }
        body {
            font-family: "Segoe UI", Roboto, sans-serif; background-color: var(--bg-color);
            background-image: radial-gradient(circle at 50% 10%, rgba(56, 213, 230, 0.08), transparent 70%), linear-gradient(rgba(4, 6, 9, 0.9), rgba(4, 6, 9, 0.98));
            background-attachment: fixed; color: var(--text-main); margin: 0; padding: 40px 20px;
            min-height: 100vh; display: flex; flex-direction: column; align-items: center;
        }
        body::before {
            content: ""; position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.3) 50%);
            background-size: 100% 4px; z-index: 1; pointer-events: none; opacity: 0.5;
        }
        .wrapper { width: 100%; max-width: 950px; z-index: 2; }
        .nav-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .nav-back, .nav-tab {
            display: inline-flex; align-items: center; gap: 8px; color: var(--accent-cyan);
            text-decoration: none; font-size: 0.8rem; font-weight: 700; letter-spacing: 2px;
            text-transform: uppercase; padding: 6px 14px; background: rgba(56, 213, 230, 0.08);
            border: 1px solid var(--border-color); clip-path: polygon(0 0, calc(100% - 8px) 0, 100% 8px, 100% 100%, 0 100%);
            transition: all 0.2s ease;
        }
        .nav-back:hover, .nav-tab:hover, .nav-tab.active { background: rgba(56, 213, 230, 0.2); border-color: var(--accent-cyan); color: #fff; }
        .nav-tabs { display: flex; gap: 10px; flex-wrap: wrap; }
        header { margin-bottom: 25px; border-bottom: 2px solid var(--border-color); padding-bottom: 15px; }
        .hud-tag { font-size: 0.7rem; letter-spacing: 3px; color: var(--accent-cyan); text-transform: uppercase; font-weight: 700; margin-bottom: 6px; }
        h1 { font-size: 2.2rem; font-weight: 800; letter-spacing: 4px; margin: 0; text-transform: uppercase; color: var(--accent-gold); text-shadow: 0 0 12px rgba(243, 194, 98, 0.3); }
        .dashboard-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 18px; }
        @media (max-width: 768px) { .dashboard-grid { grid-template-columns: 1fr 1fr; } }
        .card { background-color: var(--panel-bg); border: 1px solid var(--border-color); padding: 16px; clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 10px, 100% 100%, 0 100%); backdrop-filter: blur(10px); }
        .card-title { font-size: 0.65rem; letter-spacing: 2px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; }
        .card-value { font-size: 1.3rem; font-weight: 700; color: #ffffff; letter-spacing: 1px; }
        .card-value.green { color: var(--accent-green); }
        .card-value.red { color: var(--accent-red); }
        .card-value.purple { color: var(--accent-purple); }
        .card-value.gold { color: var(--accent-gold); }
        .progress-wrap { margin-bottom: 25px; }
        .progress-label { display: flex; justify-content: space-between; font-size: 0.68rem; letter-spacing: 2px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px; }
        .progress { height: 8px; background: rgba(56, 213, 230, 0.08); border: 1px solid var(--border-color); }
        .progress-fill { height: 100%; background: var(--accent-green); box-shadow: 0 0 10px rgba(80, 250, 123, 0.4); transition: width 0.4s ease; }
        .progress-fill.warn { background: var(--accent-gold); box-shadow: 0 0 10px rgba(243, 194, 98, 0.4); }
        .progress-fill.over { background: var(--accent-red); box-shadow: 0 0 10px rgba(255, 85, 85, 0.4); }
        .btn-sm { padding: 3px 8px; font-size: 0.6rem; }
        .ob-msg { padding: 11px 15px; margin-bottom: 18px; font-size: 0.85rem; border: 1px solid; line-height: 1.5; }
        .ob-msg.ok { border-color: var(--accent-green); color: var(--accent-green); background: rgba(80, 250, 123, 0.07); }
        .ob-msg.warn { border-color: var(--accent-gold); color: var(--accent-gold); background: rgba(243, 194, 98, 0.07); }
        .ob-msg.err { border-color: var(--accent-red); color: var(--accent-red); background: rgba(255, 85, 85, 0.07); }
        .ob-bar { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; padding: 8px 14px; margin-bottom: 16px; font-size: 0.75rem; letter-spacing: 0.5px; border: 1px solid rgba(80, 250, 123, 0.35); background: rgba(80, 250, 123, 0.05); color: var(--text-main); }
        .ob-bar.warn { border-color: rgba(243, 194, 98, 0.5); background: rgba(243, 194, 98, 0.06); }
        .ob-bar strong { color: var(--accent-green); }
        .ob-steps { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 22px; }
        .ob-step { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid var(--border-color); background: var(--panel-bg); font-size: 0.72rem; letter-spacing: 1.5px; text-transform: uppercase; color: var(--text-muted); }
        .ob-step b { width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; border: 1px solid currentColor; flex-shrink: 0; }
        .ob-step.done { color: var(--accent-green); border-color: rgba(80, 250, 123, 0.4); }
        .ob-step.now { color: var(--accent-gold); border-color: var(--accent-gold); box-shadow: 0 0 12px rgba(243, 194, 98, 0.15); }
        .ob-explain { font-size: 0.85rem; line-height: 1.6; color: var(--text-main); }
        .ob-explain p { margin: 0 0 12px; }
        .ob-explain ol { margin: 0 0 16px; padding-left: 22px; }
        .ob-explain li { margin-bottom: 8px; }
        .ob-explain a { color: var(--accent-cyan); }
        .ob-copy { display: flex; gap: 8px; align-items: center; margin: 6px 0; flex-wrap: wrap; }
        .ob-copy code { background: rgba(4, 6, 9, 0.9); border: 1px solid var(--border-color); padding: 6px 10px; color: var(--accent-gold); word-break: break-all; }
        .ob-label { display: block; font-size: 0.7rem; letter-spacing: 1px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; }
        .ob-hint { font-size: 0.78rem; color: var(--text-muted); line-height: 1.6; margin: 12px 0; }
        .ob-hint code, .ob-explain code { color: var(--accent-cyan); }
        .ob-accounts { display: flex; flex-direction: column; gap: 8px; margin-bottom: 14px; }
        .ob-account { display: grid; grid-template-columns: 1fr auto auto; gap: 14px; align-items: center; padding: 10px 14px; border: 1px solid var(--border-color); border-left: 4px solid var(--accent-green); background: rgba(14, 20, 27, 0.7); }
        .ob-account.off { border-left-color: var(--text-muted); opacity: 0.6; }
        .ob-balance { font-size: 1.15rem; font-weight: 700; color: var(--accent-green); font-variant-numeric: tabular-nums; }
        .ob-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .ob-log div { display: flex; justify-content: space-between; gap: 10px; padding: 6px 2px; border-bottom: 1px solid rgba(56, 213, 230, 0.08); font-size: 0.78rem; color: var(--text-muted); flex-wrap: wrap; }
        .ob-log b { color: var(--accent-green); }
        .ob-footer { display: flex; gap: 16px; justify-content: flex-end; font-size: 0.72rem; margin-top: -10px; }
        .ob-footer a, .ob-footer button { color: var(--text-muted); background: none; border: none; font: inherit; cursor: pointer; text-decoration: underline; padding: 0; }
        @media (max-width: 768px) { .ob-steps { grid-template-columns: 1fr 1fr; } .ob-account { grid-template-columns: 1fr; gap: 6px; } }
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .notice { border: 1px solid var(--accent-gold); background: rgba(243, 194, 98, 0.08); color: var(--text-main); padding: 12px 16px; margin-bottom: 20px; font-size: 0.85rem; letter-spacing: 0.5px; }
        .notice.ok { border-color: var(--accent-green); background: rgba(80, 250, 123, 0.08); }
        .notice a { color: var(--accent-cyan); margin-left: 8px; }
        :focus-visible { outline: 2px solid var(--accent-cyan); outline-offset: 2px; }
        .panel { background-color: var(--panel-bg); border: 1px solid var(--border-color); padding: 20px; margin-bottom: 25px; clip-path: polygon(0 0, calc(100% - 12px) 0, 100% 12px, 100% 100%, 0 100%); }
        .panel-title { font-size: 0.85rem; letter-spacing: 2px; color: var(--accent-cyan); text-transform: uppercase; font-weight: 700; margin-bottom: 15px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px; display: flex; justify-content: space-between; align-items: center; }
        .form-grid { display: grid; grid-template-columns: 1.5fr 2fr 1fr 1.5fr 1fr; gap: 10px; margin-bottom: 10px; }
        @media (max-width: 768px) { .form-grid { grid-template-columns: 1fr; } }
        input, select, textarea { background-color: rgba(4, 6, 9, 0.95); border: 1px solid var(--border-color); color: #ffffff; padding: 10px 14px; font-size: 0.9rem; outline: none; width: 100%; letter-spacing: 1px; font-family: inherit; }
        input:focus, select:focus, textarea:focus { border-color: var(--accent-cyan); box-shadow: 0 0 10px var(--accent-glow); }
        .btn { background-color: rgba(56, 213, 230, 0.15); border: 1px solid var(--accent-cyan); color: var(--accent-cyan); padding: 10px 20px; font-weight: 700; letter-spacing: 2px; cursor: pointer; text-transform: uppercase; transition: all 0.2s ease; }
        .btn:hover { background-color: var(--accent-cyan); color: #040609; box-shadow: 0 0 12px var(--accent-cyan); }
        .btn-gold { background-color: rgba(243, 194, 98, 0.15); border-color: var(--accent-gold); color: var(--accent-gold); }
        .btn-gold:hover { background-color: var(--accent-gold); box-shadow: 0 0 12px var(--accent-gold); color: #040609; }
        .btn-danger { background-color: rgba(255, 85, 85, 0.15); border-color: var(--accent-red); color: var(--accent-red); }
        .btn-danger:hover { background-color: var(--accent-red); box-shadow: 0 0 12px var(--accent-red); color: #040609; }
        .entry-list { display: flex; flex-direction: column; gap: 8px; }
        .entry-item { background: rgba(14, 20, 27, 0.7); border: 1px solid var(--border-color); padding: 12px 16px; display: flex; justify-content: space-between; align-items: flex-start; clip-path: polygon(0 0, calc(100% - 8px) 0, 100% 8px, 100% 100%, 0 100%); }
        .entry-item.sec-income { border-left: 4px solid var(--accent-green); }
        .entry-item.sec-fixed { border-left: 4px solid var(--accent-cyan); }
        .entry-item.sec-subs { border-left: 4px solid var(--accent-purple); }
        .entry-item.sec-other { border-left: 4px solid var(--accent-gold); }
        .entry-info h4 { margin: 0 0 4px 0; font-size: 0.95rem; color: #fff; letter-spacing: 1px; }
        .entry-meta { font-size: 0.7rem; color: var(--text-muted); letter-spacing: 1px; text-transform: uppercase; margin-bottom: 4px; }
        .entry-docs { font-size: 0.78rem; color: var(--accent-cyan); background: rgba(56, 213, 230, 0.05); border-left: 2px solid var(--accent-cyan); padding: 3px 6px; margin-top: 4px; word-break: break-all; }
        .badge { display: inline-block; padding: 2px 6px; font-size: 0.65rem; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; border: 1px solid currentColor; }
        .badge.paid { color: var(--accent-green); background: rgba(80,250,123,0.1); }
        .badge.open { color: var(--accent-red); background: rgba(255,85,85,0.1); }
        .entry-right { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
        .entry-amount { font-weight: 700; font-size: 1.1rem; }
        .entry-amount.inc { color: var(--accent-green); }
        .entry-amount.exp { color: var(--accent-gold); }
        .empty-msg { text-align: center; color: var(--text-muted); padding: 15px; font-size: 0.8rem; letter-spacing: 2px; text-transform: uppercase; }
        .archive-section { margin-top: 30px; border-top: 1px dashed var(--border-color); padding-top: 20px; display: flex; justify-content: space-between; align-items: center; }
        
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(4, 6, 9, 0.85); display: none; justify-content: center; align-items: center; z-index: 1000; backdrop-filter: blur(6px); }
        .modal-box { background-color: var(--panel-bg); border: 1px solid var(--accent-cyan); width: 100%; max-width: 500px; padding: 25px; clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 14px, 100% 100%, 0 100%); box-shadow: 0 0 25px var(--accent-glow); position: relative; }
        @media (max-width: 768px) {
            body { padding: 20px 14px; }
            h1 { font-size: 1.6rem; letter-spacing: 2px; }
            .nav-bar { flex-direction: column; align-items: flex-start; gap: 10px; }
            .two-col { grid-template-columns: 1fr; }
            .entry-item { flex-direction: column; gap: 10px; }
            .entry-right { align-items: flex-start; }
            .archive-section { flex-direction: column; align-items: flex-start; gap: 12px; }
            .modal-box { margin: 0 12px; max-height: 90vh; overflow-y: auto; }
        }
        .modal-close { position: absolute; top: 15px; right: 20px; background: none; border: none; color: var(--accent-red); font-size: 1.2rem; font-weight: bold; cursor: pointer; }
    </style>
</head>
<body>

    <div class="wrapper">
        <div class="nav-bar">
            <a href="index.php" class="nav-back">&larr; ZURÜCK ZUM INDEX</a>
            <div class="nav-tabs">
                <a href="admin.php" class="nav-tab" title="Admin-Zentrale">⚙ <?php echo e(auth_user()); ?></a>
                <a href="budget.php" class="nav-tab <?php echo ($view_mode === 'active') ? 'active' : ''; ?>">Aktueller Monat</a>
                <a href="budget.php?view=bank" class="nav-tab <?php echo ($view_mode === 'bank') ? 'active' : ''; ?>">🏦 Bank-Sync<?php echo ob_connected($ob) ? ' ●' : ''; ?></a>
                <a href="budget.php?view=import" class="nav-tab <?php echo ($view_mode === 'import') ? 'active' : ''; ?>">CSV-Import</a>
                <a href="budget.php?view=templates" class="nav-tab <?php echo ($view_mode === 'templates') ? 'active' : ''; ?>">Fix &amp; Abos</a>
                <a href="budget.php?view=archive" class="nav-tab <?php echo ($view_mode === 'archive' || $selected_archive) ? 'active' : ''; ?>">Archiv</a>
            </div>
        </div>

        <?php if ($view_mode === 'active' && !$selected_archive): ?>
            <!-- ================= AKTUELER MONAT ================= -->
            <header>
                <div class="hud-tag">System // Monthly Financial Terminal</div>
                <h1><?php echo htmlspecialchars($data['month_name']); ?></h1>
                <a href="budget.php?export=csv" class="btn btn-sm" style="margin-top: 10px; display: inline-block; text-decoration: none;" title="Für Excel / LibreOffice">&darr; CSV-Export</a>
            </header>

            <?php if ($ob_message): ?><div class="ob-msg <?php echo e($ob_message[0]); ?>"><?php echo e($ob_message[1]); ?></div><?php endif; ?>
            <?php if (ob_connected($ob)): ?>
                <div class="ob-bar">
                    <span>🏦 <?php echo e($ob['aspsp']['name'] ?? 'Bank'); ?> verbunden
                        <?php foreach ($ob['accounts'] as $acc): if (empty($acc['sync']) || !$acc['balance']) continue; ?> &bull; Kontostand <strong><?php echo eur($acc['balance']['amount']); ?> <?php echo e($acc['balance']['currency']); ?></strong><?php endforeach; ?>
                        &bull; Abgleich <?php echo $ob['last_sync'] ? e(date('d.m. H:i', (int)$ob['last_sync'])) : 'noch nie'; ?>
                        <?php if ($ob_auto_error): ?> &bull; <span style="color: var(--accent-red);" title="<?php echo e($ob_auto_error); ?>">⚠ Auto-Abgleich fehlgeschlagen</span><?php endif; ?>
                    </span>
                    <form method="POST" style="margin: 0;"><input type="hidden" name="ob_action" value="sync"><input type="hidden" name="back" value="active"><button type="submit" class="btn btn-sm" style="padding: 4px 10px; font-size: 0.65rem;">⟳ Abgleichen</button></form>
                </div>
            <?php elseif ($ob['session_id'] !== '' && !ob_connected($ob)): ?>
                <div class="ob-bar warn"><span>🏦 Die Bank-Freigabe ist abgelaufen.</span><a href="budget.php?view=bank" class="btn btn-sm" style="padding: 4px 10px; font-size: 0.65rem;">Erneuern</a></div>
            <?php endif; ?>

            <div class="dashboard-grid">
                <div class="card"><div class="card-title">Einnahmen</div><div class="card-value green">+<?php echo eur($total_income); ?> €</div></div>
                <div class="card"><div class="card-title">Ausgaben</div><div class="card-value red">-<?php echo eur($total_expense); ?> €</div></div>
                <div class="card"><div class="card-title">Noch offen</div><div class="card-value <?php echo $total_open > 0 ? 'gold' : ''; ?>"><?php echo eur($total_open); ?> €</div></div>
                <div class="card"><div class="card-title">Netto Bilanz</div><div class="card-value <?php echo $net_balance >= 0 ? 'purple' : 'red'; ?>"><?php echo eur($net_balance); ?> €</div></div>
            </div>

            <div class="progress-wrap" title="Anteil der Ausgaben an den Einnahmen">
                <div class="progress-label">
                    <span>Ausgaben-Quote</span>
                    <span><?php echo $total_income > 0 ? round($total_expense / $total_income * 100) . ' % der Einnahmen' : 'keine Einnahmen erfasst'; ?></span>
                </div>
                <div class="progress"><div class="progress-fill <?php echo $spend_ratio >= 100 ? 'over' : ($spend_ratio >= 85 ? 'warn' : ''); ?>" style="width: <?php echo $spend_ratio; ?>%;"></div></div>
            </div>

            <div class="panel">
                <div class="panel-title"><span>// Abrechnungsmonat Anpassen</span></div>
                <form method="POST" style="display: flex; gap: 10px;">
                    <input type="text" name="month_name" value="<?php echo htmlspecialchars($data['month_name']); ?>" required>
                    <button type="submit" name="update_month" class="btn btn-gold">Aktualisieren</button>
                </form>
            </div>

            <div class="panel">
                <div class="panel-title">// Einnahme oder Sonstige Ausgabe Hinzufügen</div>
                <form method="POST">
                    <div class="form-grid">
                        <select name="section" id="new_section" onchange="updateCat(this.value)">
                            <option value="income">1. Einnahmen</option>
                            <option value="other" selected>4. Sonstige</option>
                        </select>
                        <input type="text" name="title" placeholder="Bezeichnung..." required>
                        <input type="number" step="0.01" min="0.01" name="amount" placeholder="Betrag €" inputmode="decimal" required>
                        <input type="text" name="category" id="new_category" placeholder="Kategorie">
                        <select name="status" id="new_status">
                            <option value="Erledigt" id="new_status_done">Bezahlt</option>
                            <option value="Offen">Offen</option>
                        </select>
                    </div>
                    <div style="margin-bottom: 10px;"><input type="text" name="docs" placeholder="Notizen / Details..."></div>
                    <button type="submit" name="add_entry" class="btn">Hinzufügen</button>
                </form>
            </div>

            <?php 
            $sections_meta = [
                'income' => ['title' => '1. Einnahmen', 'color' => 'var(--accent-green)', 'class' => 'sec-income'],
                'fixed' => ['title' => '2. Fixkosten', 'color' => 'var(--accent-cyan)', 'class' => 'sec-fixed'],
                'subs' => ['title' => '3. Abos', 'color' => 'var(--accent-purple)', 'class' => 'sec-subs'],
                'other' => ['title' => '4. Sonstige Ausgaben', 'color' => 'var(--accent-gold)', 'class' => 'sec-other']
            ];
            foreach ($sections_meta as $sec_key => $meta): 
            ?>
                <div class="panel">
                    <div class="panel-title">
                        <span style="color: <?php echo $meta['color']; ?>;"><?php echo $meta['title']; ?></span>
                        <span>Summe: <?php echo $sec_key==='income'?'+':'-'; ?><?php echo eur($totals[$sec_key]); ?> € &bull; <?php echo count($categorized_entries[$sec_key]); ?> Posten</span>
                    </div>
                    <div class="entry-list">
                        <?php if (empty($categorized_entries[$sec_key])): ?>
                            <div class="empty-msg">Keine Einträge in dieser Sektion.</div>
                        <?php else: ?>
                            <?php foreach ($categorized_entries[$sec_key] as $entry): ?>
                                <?php $is_done = (($entry['status'] ?? '') !== 'Offen'); ?>
                                <div class="entry-item <?php echo $meta['class']; ?>">
                                    <div class="entry-info">
                                        <h4><?php echo htmlspecialchars($entry['title']); ?></h4>
                                        <div class="entry-meta">
                                            Kat: <strong><?php echo htmlspecialchars($entry['category']); ?></strong> 
                                            &bull; <span class="badge <?php echo $is_done ? 'paid' : 'open'; ?>"><?php echo e($entry['status'] ?? done_label($sec_key)); ?></span>
                                            &bull; <?php echo e($entry['date'] ?? ''); ?>
                                            <?php if (!empty($entry['template_id'])): ?>&bull; <span title="Automatisch aus Vorlage übernommen">↻ Vorlage</span><?php endif; ?>
                                        </div>
                                        <?php if (!empty($entry['docs'])): ?>
                                            <div class="entry-docs">DOCS: <?php echo htmlspecialchars($entry['docs']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="entry-right">
                                        <span class="entry-amount <?php echo $sec_key==='income'?'inc':'exp'; ?>">
                                            <?php echo $sec_key==='income'?'+':'-'; ?><?php echo eur($entry['amount']); ?> €
                                        </span>
                                        <div style="display: flex; gap: 6px;">
                                            <button type="button" class="btn btn-gold btn-sm" data-entry="<?php echo e(json_encode(['id' => $entry['id'], 'section' => $sec_key, 'title' => $entry['title'], 'amount' => $entry['amount'], 'category' => $entry['category'] ?? '', 'status' => $is_done ? 'Erledigt' : 'Offen', 'docs' => $entry['docs'] ?? ''])); ?>" onclick="openEditEntryModal(JSON.parse(this.dataset.entry))">Edit</button>
                                            <form method="POST" style="margin:0;"><input type="hidden" name="entry_id" value="<?php echo e($entry['id']); ?>"><button type="submit" name="toggle_status" class="btn btn-sm <?php echo $is_done ? '' : 'btn-gold'; ?>" title="Status umschalten"><?php echo $is_done ? 'Offen' : ($sec_key === 'income' ? 'Erhalten' : 'Bezahlt'); ?></button></form>
                                            <form method="POST" style="margin:0;" onsubmit="return confirm(<?php echo e(json_encode('Eintrag „' . $entry['title'] . '“ wirklich löschen?', JSON_UNESCAPED_UNICODE)); ?>);"><input type="hidden" name="entry_id" value="<?php echo e($entry['id']); ?>"><button type="submit" name="delete_entry" class="btn btn-danger btn-sm" title="Löschen">X</button></form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="archive-section">
                <div>
                    <strong style="color: #fff; letter-spacing: 1px; font-size: 0.85rem; text-transform: uppercase;">Monat beenden?</strong>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Sichert diesen Monat ins Archiv und startet <?php echo e(german_month_year(strtotime('first day of next month'))); ?>. Fixkosten &amp; Abos werden automatisch übernommen.</div>
                </div>
                <form method="POST" onsubmit="return confirm('Monat wirklich ins Archiv verschieben?');">
                    <button type="submit" name="archive_month" class="btn btn-danger">Monat archivieren &amp; Neustart</button>
                </form>
            </div>

            <!-- POPUP -->
            <div id="editEntryModal" class="modal-overlay">
                <div class="modal-box">
                    <button type="button" class="modal-close" onclick="closeEditEntryModal()">&times;</button>
                    <div class="panel-title">// Monatsposten Bearbeiten</div>
                    <form method="POST">
                        <input type="hidden" name="entry_id" id="ee_entry_id">
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom: 10px;">
                            <div>
                                <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Sektion</label>
                                <select name="section" id="ee_section">
                                    <option value="income">1. Einnahmen</option>
                                    <option value="fixed">2. Fixkosten</option>
                                    <option value="subs">3. Abos</option>
                                    <option value="other">4. Sonstige</option>
                                </select>
                            </div>
                            <div>
                                <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Status</label>
                                <select name="status" id="ee_status">
                                    <option value="Erledigt">Bezahlt / Erhalten</option>
                                    <option value="Offen">Offen</option>
                                </select>
                            </div>
                        </div>
                        <div style="margin-bottom: 10px;">
                            <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Bezeichnung</label>
                            <input type="text" name="title" id="ee_title" required>
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom: 10px;">
                            <div>
                                <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Betrag (€)</label>
                                <input type="number" step="0.01" min="0.01" name="amount" id="ee_amount" inputmode="decimal" required>
                            </div>
                            <div>
                                <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Kategorie</label>
                                <input type="text" name="category" id="ee_category">
                            </div>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Notizen</label>
                            <input type="text" name="docs" id="ee_docs">
                        </div>
                        <button type="submit" name="edit_entry" class="btn btn-gold" style="width:100%;">Änderungen Speichern</button>
                    </form>
                </div>
            </div>

            <script>
                function updateCat(sec) {
                    document.getElementById('new_category').value = (sec === 'income') ? 'Lohn / Abschlag' : 'Sonstiges';
                    document.getElementById('new_status_done').textContent = (sec === 'income') ? 'Erhalten' : 'Bezahlt';
                }
                function openEditEntryModal(entry) {
                    document.getElementById('ee_entry_id').value = entry.id;
                    document.getElementById('ee_section').value = entry.section;
                    document.getElementById('ee_title').value = entry.title;
                    document.getElementById('ee_amount').value = entry.amount;
                    document.getElementById('ee_category').value = entry.category;
                    document.getElementById('ee_status').value = entry.status;
                    document.getElementById('ee_docs').value = entry.docs;
                    document.getElementById('editEntryModal').style.display = 'flex';
                    document.getElementById('ee_title').focus();
                }
                function closeEditEntryModal() { document.getElementById('editEntryModal').style.display = 'none'; }
                window.addEventListener('click', e => { if (e.target === document.getElementById('editEntryModal')) closeEditEntryModal(); });
                document.addEventListener('keydown', e => { if (e.key === 'Escape') closeEditEntryModal(); });
                updateCat(document.getElementById('new_section').value);
            </script>

        <?php elseif ($view_mode === 'import'): ?>
            <!-- ================= KONTOAUSZUG IMPORT ================= -->
            <header>
                <div class="hud-tag">System // Bank Statement Importer</div>
                <h1>Kontoauszug Import</h1>
            </header>

            <?php if (isset($_GET['imported'])): ?>
                <div class="notice <?php echo (int)$_GET['imported'] > 0 ? 'ok' : ''; ?>">
                    Import abgeschlossen: <strong><?php echo (int)$_GET['imported']; ?></strong> Buchungen übernommen,
                    <?php echo (int)($_GET['skipped'] ?? 0); ?> interne Umbuchungen übersprungen,
                    <?php echo (int)($_GET['duplicates'] ?? 0); ?> Duplikate ignoriert.
                    <a href="budget.php">&rarr; Zum aktuellen Monat</a>
                </div>
            <?php endif; ?>

            <div class="panel" style="border-color: var(--accent-cyan);">
                <div class="panel-title">// CSV-Datei Hochladen (z.B. Revolut / Bank Export)</div>
                <form method="POST" enctype="multipart/form-data">
                    <div style="margin-bottom: 15px;">
                        <label style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; display: block; margin-bottom: 6px;">Wähle eine CSV-Kontoauszugsdatei aus:</label>
                        <input type="file" name="csv_file" accept=".csv" required style="padding: 12px; background: rgba(0,0,0,0.5);">
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 15px;">
                        * Interne Umbuchungen (Transfers, Unterkonten, Pockets) sowie stornierte/abgelehnte Buchungen werden automatisch herausgefiltert. Bereits importierte Buchungen werden beim erneuten Hochladen erkannt und nicht doppelt angelegt. Komma- und Semikolon-CSV werden automatisch erkannt.
                    </div>
                    <button type="submit" name="import_csv" class="btn btn-gold">Kontoauszug Jetzt Importieren</button>
                </form>
            </div>

        <?php elseif ($view_mode === 'bank'): ?>
            <!-- ================= BANK-SYNC ================= -->
            <header>
                <div class="hud-tag">System // Open Banking Uplink</div>
                <h1>Bank-Sync</h1>
            </header>

            <?php if ($ob_message): ?><div class="ob-msg <?php echo e($ob_message[0]); ?>"><?php echo e($ob_message[1]); ?></div><?php endif; ?>

            <?php
            $ob_step = $ob['app_id'] === '' ? 1 : (!$ob['aspsp'] ? 2 : (!ob_connected($ob) ? 3 : 5));
            $ob_days_left = ob_connected($ob) ? (int)floor((strtotime($ob['valid_until']) - time()) / 86400) : 0;
            ?>
            <div class="ob-steps">
                <?php foreach (['Zugang', 'Bank wählen', 'Verbinden', 'Synchron'] as $i => $label): $n = $i + 1; ?>
                    <div class="ob-step <?php echo $n < $ob_step ? 'done' : ($n === $ob_step ? 'now' : ''); ?>"><b><?php echo $n < $ob_step ? '✓' : $n; ?></b><span><?php echo $label; ?></span></div>
                <?php endforeach; ?>
            </div>

            <?php if (ob_connected($ob)): ?>
                <!-- Verbunden -->
                <div class="panel" style="border-color: var(--accent-green);">
                    <div class="panel-title">
                        <span style="color: var(--accent-green);">● Verbunden mit <?php echo e($ob['aspsp']['name'] ?? 'Bank'); ?></span>
                        <span>Freigabe gültig bis <?php echo e(date('d.m.Y', strtotime($ob['valid_until']))); ?><?php if ($ob_days_left <= 14): ?> <span style="color: var(--accent-gold);">(noch <?php echo $ob_days_left; ?> Tage – bald erneuern)</span><?php endif; ?></span>
                    </div>

                    <div class="ob-accounts">
                        <?php foreach ($ob['accounts'] as $acc): ?>
                            <div class="ob-account <?php echo empty($acc['sync']) ? 'off' : ''; ?>">
                                <div>
                                    <strong><?php echo e($acc['name']); ?></strong>
                                    <div class="entry-meta"><?php echo e($acc['iban'] !== '' ? substr($acc['iban'], 0, 4) . ' •••• ' . substr($acc['iban'], -4) : $acc['currency']); ?></div>
                                </div>
                                <div class="ob-balance"><?php echo $acc['balance'] ? eur($acc['balance']['amount']) . ' ' . e($acc['balance']['currency']) : '–'; ?></div>
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="ob_action" value="toggle_account"><input type="hidden" name="uid" value="<?php echo e($acc['uid']); ?>">
                                    <button type="submit" class="btn btn-sm <?php echo empty($acc['sync']) ? '' : 'btn-gold'; ?>" style="padding: 4px 9px; font-size: 0.62rem;"><?php echo empty($acc['sync']) ? 'Nicht synchron.' : '✓ Synchronisiert'; ?></button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="ob-actions">
                        <form method="POST" style="margin: 0;" onsubmit="this.querySelector('button').textContent='⟳ Gleiche ab…'; this.querySelector('button').disabled=true;">
                            <input type="hidden" name="ob_action" value="sync">
                            <button type="submit" class="btn btn-gold">⟳ Jetzt abgleichen</button>
                        </form>
                        <form method="POST" style="margin: 0;">
                            <input type="hidden" name="ob_action" value="toggle_auto">
                            <button type="submit" class="btn"><?php echo !empty($ob['auto_sync']) ? '✓ Automatisch alle 6 Std.' : 'Automatik aus'; ?></button>
                        </form>
                        <form method="POST" style="margin: 0;">
                            <input type="hidden" name="ob_action" value="connect">
                            <button type="submit" class="btn" title="Freigabe bei der Bank erneuern (verlängert die Gültigkeit)">↻ Freigabe erneuern</button>
                        </form>
                        <form method="POST" style="margin: 0;" onsubmit="return confirm('Bankverbindung trennen? Bereits übernommene Buchungen bleiben erhalten.');">
                            <input type="hidden" name="ob_action" value="disconnect">
                            <button type="submit" class="btn btn-danger">Trennen</button>
                        </form>
                    </div>
                    <p class="ob-hint">
                        Übernommen werden alle <strong>gebuchten</strong> Umsätze des Budget-Monats „<?php echo e($data['month_name']); ?>“.
                        Interne Umbuchungen (Pockets, Tresore, Unterkonten, Währungstausch) werden übersprungen, bereits vorhandene Buchungen
                        (auch aus dem CSV-Import) erkannt, und Fixkosten/Abos aus deinen Vorlagen mit der passenden Bankbuchung abgeglichen.
                        <?php if ($ob['last_sync']): ?>Letzter Abgleich: <?php echo e(german_datetime((int)$ob['last_sync'])); ?>.<?php endif; ?>
                    </p>
                </div>

                <?php if ($ob['history']): ?>
                    <div class="panel">
                        <div class="panel-title"><span>// Abgleich-Protokoll</span></div>
                        <div class="ob-log">
                            <?php foreach ($ob['history'] as $h): ?>
                                <div><span><?php echo date('d.m. H:i', $h['t']); ?><?php echo !empty($h['auto']) ? ' · auto' : ''; ?></span>
                                    <span><b><?php echo (int)$h['imported']; ?></b> neu · <?php echo (int)$h['matched']; ?> abgeglichen · <?php echo (int)$h['duplicates']; ?> vorhanden · <?php echo (int)$h['skipped']; ?> intern<?php echo !empty($h['pending']) ? ' · ' . (int)$h['pending'] . ' vorgemerkt' : ''; ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($ob_step === 1 || isset($_GET['setup'])): ?>
                <!-- Schritt 1: Zugang -->
                <div class="panel">
                    <div class="panel-title"><span>// 1. Zugang zu Enable Banking einrichten</span></div>
                    <div class="ob-explain">
                        <p><strong>Warum Enable Banking?</strong> Revolut gibt seine Open-Banking-Schnittstelle nur an lizenzierte Zahlungsdienstleister heraus –
                            für private Konten gibt es keine eigene Revolut-API. <strong>Enable Banking</strong> ist ein solcher lizenzierter Dienst, nutzt
                            genau diese Revolut-Open-Banking-Schnittstelle und ist für die private Nutzung mit den <em>eigenen</em> Konten kostenlos.
                            Er bekommt nur <strong>Lesezugriff</strong> (Kontostand &amp; Umsätze) – Überweisungen sind damit nicht möglich.</p>
                        <ol>
                            <li>Auf <a href="https://enablebanking.com/sign-in/" target="_blank" rel="noopener noreferrer">enablebanking.com</a> mit deiner E-Mail anmelden (Konto wird automatisch angelegt).</li>
                            <li>Im Control Panel unter <em>API applications</em> eine neue App anlegen: Umgebung <strong>Production</strong>, Name z. B. „Mein Budget“.</li>
                            <li>Als <strong>Redirect URL</strong> genau diese Adresse eintragen:
                                <div class="ob-copy"><code id="obRedirect"><?php echo e(ob_redirect_url()); ?></code><button type="button" class="btn btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('obRedirect').textContent); this.textContent='✓ Kopiert';">Kopieren</button></div>
                                <?php if (strpos(ob_redirect_url(), 'https://') !== 0): ?><span style="color: var(--accent-red);">⚠ Deine Seite läuft ohne HTTPS – Banken verlangen eine https-Adresse. Bitte HTTPS beim Hoster aktivieren.</span><?php endif; ?>
                            </li>
                            <li>„Register“ klicken – dein Browser lädt eine Datei <code>xxxxxxxx-xxxx-….pem</code> herunter (der Dateiname ist die Application-ID).</li>
                            <li>Bei der App auf <strong>„Activate by linking accounts“</strong> klicken und dein Revolut-Konto verknüpfen (schaltet den kostenlosen Privatmodus frei).</li>
                            <li>Application-ID und .pem-Datei hier eintragen:</li>
                        </ol>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="ob_action" value="save_credentials">
                        <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
                            <div><label class="ob-label">Application-ID</label><input type="text" name="app_id" id="obAppId" value="<?php echo e($ob['app_id']); ?>" placeholder="aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee" required autocomplete="off" spellcheck="false"></div>
                            <div><label class="ob-label">Privater Schlüssel (.pem)<?php echo $ob['private_key'] !== '' ? ' – hinterlegt ✓' : ''; ?></label><input type="file" name="pem_file" id="obPem" accept=".pem,.key,.txt" <?php echo $ob['private_key'] === '' ? 'required' : ''; ?>></div>
                        </div>
                        <p class="ob-hint">Der Schlüssel wird nur auf deinem Server gespeichert (geschützte Datei <code>.openbanking.php</code>) und nie wieder angezeigt.</p>
                        <button type="submit" class="btn btn-gold">Speichern &amp; Verbindung testen</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($ob_step >= 2 && !ob_connected($ob)): ?>
                <!-- Schritt 2 + 3: Bank wählen und verbinden -->
                <div class="panel">
                    <div class="panel-title"><span>// 2. Bank wählen</span><?php if ($ob['aspsp']): ?><span style="color: var(--accent-green);">✓ <?php echo e($ob['aspsp']['name'] . ' (' . $ob['aspsp']['country'] . ')'); ?></span><?php endif; ?></div>
                    <form method="POST" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                        <input type="hidden" name="ob_action" value="search_banks">
                        <div style="flex: 1; min-width: 180px;"><label class="ob-label">Land deines Revolut-Kontos</label>
                            <select name="country">
                                <?php foreach (['DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz', 'LT' => 'Litauen (Revolut Bank UAB)', 'NL' => 'Niederlande', 'FR' => 'Frankreich', 'ES' => 'Spanien', 'IT' => 'Italien', 'PL' => 'Polen', 'IE' => 'Irland', 'BE' => 'Belgien', 'FI' => 'Finnland', 'SE' => 'Schweden', 'DK' => 'Dänemark', 'GB' => 'Großbritannien'] as $cc => $cn): ?>
                                    <option value="<?php echo $cc; ?>" <?php echo ($_SESSION['ob_country'] ?? ($ob['aspsp']['country'] ?? 'DE')) === $cc ? 'selected' : ''; ?>><?php echo e($cn); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn">Banken laden</button>
                    </form>
                    <?php if (!empty($_SESSION['ob_banks'])): ?>
                        <form method="POST" style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px;">
                            <input type="hidden" name="ob_action" value="select_bank">
                            <select name="bank" style="flex: 1; min-width: 200px;">
                                <?php foreach ($_SESSION['ob_banks'] as $bank): ?>
                                    <option value="<?php echo e($bank['name'] . '|' . $bank['country']); ?>"><?php echo e($bank['name']); ?><?php echo $bank['beta'] ? ' (Beta)' : ''; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-gold">Auswählen</button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if ($ob['aspsp']): ?>
                    <div class="panel" style="border-color: var(--accent-gold);">
                        <div class="panel-title"><span>// 3. Mit <?php echo e($ob['aspsp']['name']); ?> verbinden</span></div>
                        <p class="ob-hint" style="margin-top: 0;">Du wirst zu Enable Banking und dann zu <?php echo e($ob['aspsp']['name']); ?> weitergeleitet. Dort bestätigst du den Lesezugriff
                            (bei Revolut meist in der App). Danach kommst du automatisch hierher zurück und die Umsätze des Monats werden übernommen.
                            Die Freigabe gilt bis zu <?php echo (int)min(180, floor(($ob['aspsp']['max'] ?? 7776000) / 86400)); ?> Tage und muss dann erneuert werden (PSD2-Vorgabe).</p>
                        <form method="POST"><input type="hidden" name="ob_action" value="connect"><button type="submit" class="btn btn-gold" style="font-size: 0.95rem; padding: 14px 26px;">🏦 Jetzt mit <?php echo e($ob['aspsp']['name']); ?> verbinden</button></form>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($ob_step > 1): ?>
                <div class="ob-footer">
                    <a href="budget.php?view=bank&amp;setup=1">Zugangsdaten ändern</a>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Alle Bank-Zugangsdaten (Application-ID, Schlüssel, Verbindung) löschen?');"><input type="hidden" name="ob_action" value="forget"><button type="submit">Alles löschen</button></form>
                </div>
            <?php endif; ?>

            <script>
                // Application-ID automatisch aus dem Dateinamen der .pem übernehmen
                const pemInput = document.getElementById('obPem');
                if (pemInput) pemInput.addEventListener('change', () => {
                    const m = (pemInput.files[0] || {}).name && pemInput.files[0].name.match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i);
                    const id = document.getElementById('obAppId');
                    if (m && !id.value) id.value = m[0];
                });
            </script>

        <?php elseif ($view_mode === 'templates'): ?>
            <!-- ================= FIXKOSTEN & ABOS VORLAGEN ================= -->
            <header>
                <div class="hud-tag">System // Recurring Template Master Node</div>
                <h1>Fixkosten &amp; Abos Vorlagen</h1>
            </header>

            <div class="panel" style="border-color: var(--accent-cyan);">
                <div class="panel-title">
                    <span>// Vorlagen-Verwaltung</span>
                    <button type="button" class="btn btn-gold" onclick="openAddFixSubModal()">+ Neue Vorlage</button>
                </div>
                
                <div class="two-col">
                    <div>
                        <h3 style="color: var(--accent-cyan); font-size: 0.9rem; text-transform: uppercase; margin-bottom: 12px; border-bottom: 1px dashed var(--border-color); padding-bottom: 5px;">Fixkosten Vorlagen: <span style="float:right;"><?php echo eur(array_sum(array_column($fix_subs_data['fixed'], 'amount'))); ?> € / Monat</span></h3>
                        <div class="entry-list">
                            <?php if (empty($fix_subs_data['fixed'])): ?>
                                <div class="empty-msg">Keine Fixkosten-Vorlagen.</div>
                            <?php else: ?>
                                <?php foreach ($fix_subs_data['fixed'] as $f): ?>
                                    <div class="entry-item sec-fixed" style="align-items: center;">
                                        <div>
                                            <div style="font-weight: 700; font-size: 0.95rem; color:#fff;"><?php echo htmlspecialchars($f['title']); ?></div>
                                            <div style="font-size: 0.7rem; color:var(--text-muted);"><?php echo eur($f['amount']); ?> € &bull; Kat: <?php echo e($f['category']); ?><?php if (!empty($f['docs'])): ?> &bull; <?php echo e($f['docs']); ?><?php endif; ?></div>
                                        </div>
                                        <div style="display: flex; gap: 6px;">
                                            <button type="button" class="btn btn-gold btn-sm" data-item="<?php echo e(json_encode($f + ['section' => 'fixed'])); ?>" onclick="openEditFixSubModal(JSON.parse(this.dataset.item))">Edit</button>
                                            <form method="POST" style="margin:0;" onsubmit="return confirm('Vorlage wirklich löschen? Der Posten im aktuellen Monat bleibt bestehen.');"><input type="hidden" name="section" value="fixed"><input type="hidden" name="item_id" value="<?php echo e($f['id']); ?>"><button type="submit" name="delete_fix_sub_item" class="btn btn-danger btn-sm">X</button></form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <h3 style="color: var(--accent-purple); font-size: 0.9rem; text-transform: uppercase; margin-bottom: 12px; border-bottom: 1px dashed var(--border-color); padding-bottom: 5px;">Abo Vorlagen: <span style="float:right;"><?php echo eur(array_sum(array_column($fix_subs_data['subs'], 'amount'))); ?> € / Monat</span></h3>
                        <div class="entry-list">
                            <?php if (empty($fix_subs_data['subs'])): ?>
                                <div class="empty-msg">Keine Abo-Vorlagen.</div>
                            <?php else: ?>
                                <?php foreach ($fix_subs_data['subs'] as $s): ?>
                                    <div class="entry-item sec-subs" style="align-items: center;">
                                        <div>
                                            <div style="font-weight: 700; font-size: 0.95rem; color:#fff;"><?php echo htmlspecialchars($s['title']); ?></div>
                                            <div style="font-size: 0.7rem; color:var(--text-muted);"><?php echo eur($s['amount']); ?> € &bull; Kat: <?php echo e($s['category']); ?><?php if (!empty($s['docs'])): ?> &bull; <?php echo e($s['docs']); ?><?php endif; ?></div>
                                        </div>
                                        <div style="display: flex; gap: 6px;">
                                            <button type="button" class="btn btn-gold btn-sm" data-item="<?php echo e(json_encode($s + ['section' => 'subs'])); ?>" onclick="openEditFixSubModal(JSON.parse(this.dataset.item))">Edit</button>
                                            <form method="POST" style="margin:0;" onsubmit="return confirm('Vorlage wirklich löschen? Der Posten im aktuellen Monat bleibt bestehen.');"><input type="hidden" name="section" value="subs"><input type="hidden" name="item_id" value="<?php echo e($s['id']); ?>"><button type="submit" name="delete_fix_sub_item" class="btn btn-danger btn-sm">X</button></form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- POPUP -->
            <div id="fixSubModal" class="modal-overlay">
                <div class="modal-box">
                    <button type="button" class="modal-close" onclick="closeFixSubModal()">&times;</button>
                    <div class="panel-title" id="fixSubModalTitle">// Fixkosten / Abo Vorlage</div>
                    <form method="POST">
                        <input type="hidden" name="item_id" id="fs_item_id">
                        <div style="margin-bottom: 10px;">
                            <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Bereich</label>
                            <select name="section" id="fs_section">
                                <option value="fixed">Fixkosten</option>
                                <option value="subs">Abo</option>
                            </select>
                        </div>
                        <div style="margin-bottom: 10px;">
                            <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Bezeichnung</label>
                            <input type="text" name="title" id="fs_title" required>
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom: 10px;">
                            <div>
                                <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Betrag (€)</label>
                                <input type="number" step="0.01" min="0.01" name="amount" id="fs_amount" inputmode="decimal" required>
                            </div>
                            <div>
                                <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Kategorie</label>
                                <input type="text" name="category" id="fs_category">
                            </div>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Notizen</label>
                            <input type="text" name="docs" id="fs_docs">
                        </div>
                        <button type="submit" name="add_fix_sub_item" id="fs_submit_btn" class="btn btn-gold" style="width:100%;">Speichern</button>
                    </form>
                </div>
            </div>

            <script>
                function openAddFixSubModal() {
                    document.getElementById('fixSubModalTitle').textContent = '// Neue Fixkosten / Abo Vorlage';
                    document.getElementById('fs_item_id').value = '';
                    document.getElementById('fs_title').value = '';
                    document.getElementById('fs_amount').value = '';
                    document.getElementById('fs_category').value = '';
                    document.getElementById('fs_docs').value = '';
                    document.getElementById('fs_submit_btn').name = 'add_fix_sub_item';
                    document.getElementById('fixSubModal').style.display = 'flex';
                }
                function openEditFixSubModal(item) {
                    document.getElementById('fixSubModalTitle').textContent = '// Vorlage Bearbeiten';
                    document.getElementById('fs_item_id').value = item.id;
                    document.getElementById('fs_section').value = item.section;
                    document.getElementById('fs_title').value = item.title;
                    document.getElementById('fs_amount').value = item.amount;
                    document.getElementById('fs_category').value = item.category || '';
                    document.getElementById('fs_docs').value = item.docs || '';
                    document.getElementById('fs_submit_btn').name = 'edit_fix_sub_item';
                    document.getElementById('fixSubModal').style.display = 'flex';
                }
                function closeFixSubModal() { document.getElementById('fixSubModal').style.display = 'none'; }
                window.addEventListener('click', e => { if (e.target === document.getElementById('fixSubModal')) closeFixSubModal(); });
                document.addEventListener('keydown', e => { if (e.key === 'Escape') closeFixSubModal(); });
            </script>

        <?php else: ?>
            <!-- ================= ARCHIV ================= -->
            <header>
                <div class="hud-tag">System // Historical Financial Archives</div>
                <h1>Archiv-Reader</h1>
            </header>
            
            <?php if ($selected_archive && $archive_data): ?>
                <div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;"><a href="budget.php?view=archive" class="nav-back">&larr; Zurück zur Liste</a><a href="budget.php?export=csv&amp;archive=<?php echo urlencode($selected_archive); ?>" class="nav-back">&darr; CSV-Export</a></div>
                <div class="panel" style="border-color: var(--accent-gold);">
                    <div class="panel-title" style="color: var(--accent-gold);">Archiv: <?php echo e($archive_data['month_name'] ?? ''); ?></div>
                    <?php $at = budget_totals($archive_data['entries'] ?? []); ?>
                    <div class="dashboard-grid">
                        <div class="card"><div class="card-title">Einnahmen</div><div class="card-value green">+<?php echo eur($at['income']); ?> €</div></div>
                        <div class="card"><div class="card-title">Fixkosten + Abos</div><div class="card-value"><?php echo eur($at['fixed'] + $at['subs']); ?> €</div></div>
                        <div class="card"><div class="card-title">Sonstige</div><div class="card-value gold"><?php echo eur($at['other']); ?> €</div></div>
                        <div class="card"><div class="card-title">Netto Bilanz</div><div class="card-value <?php echo $at['net'] >= 0 ? 'purple' : 'red'; ?>"><?php echo eur($at['net']); ?> €</div></div>
                    </div>
                    <?php $sec_names = ['income' => 'Einnahme', 'fixed' => 'Fixkosten', 'subs' => 'Abo', 'other' => 'Sonstige']; ?>
                    <div class="entry-list">
                        <?php foreach ($archive_data['entries'] ?? [] as $ae): $asec = $ae['section'] ?? 'other'; ?>
                            <div class="entry-item sec-<?php echo e($asec); ?>">
                                <div class="entry-info">
                                    <h4><?php echo e($ae['title']); ?></h4>
                                    <div class="entry-meta"><?php echo e($sec_names[$asec] ?? $asec); ?> &bull; <?php echo e($ae['category'] ?? ''); ?> &bull; <?php echo e($ae['status'] ?? ''); ?> &bull; <?php echo e($ae['date'] ?? ''); ?></div>
                                    <?php if (!empty($ae['docs'])): ?><div class="entry-docs">DOCS: <?php echo e($ae['docs']); ?></div><?php endif; ?>
                                </div>
                                <div class="entry-right"><span class="entry-amount <?php echo $asec === 'income' ? 'inc' : 'exp'; ?>"><?php echo $asec === 'income' ? '+' : '-'; ?><?php echo eur($ae['amount']); ?> €</span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="panel">
                    <div class="panel-title">// Gespeicherte Monats-Archive</div>
                    <div class="entry-list">
                        <?php
                        $archives = glob($archive_dir . '*.json') ?: [];
                        usort($archives, function ($a, $b) { return filemtime($b) <=> filemtime($a); });
                        if (empty($archives)):
                        ?>
                            <div class="empty-msg">Keine Monats-Archive vorhanden.</div>
                        <?php else: ?>
                            <?php foreach ($archives as $arch_file): 
                                $content = load_json($arch_file, ['month_name' => '?', 'entries' => []]);
                                $ct = budget_totals($content['entries'] ?? []);
                                $basename = basename($arch_file);
                            ?>
                                <div class="entry-item" style="align-items: center;">
                                    <div class="entry-info">
                                        <h4><?php echo e($content['month_name'] ?? '?'); ?></h4>
                                        <div class="entry-meta">Einnahmen <?php echo eur($ct['income']); ?> € &bull; Ausgaben <?php echo eur($ct['expense']); ?> € &bull; Netto <span style="color: <?php echo $ct['net'] >= 0 ? 'var(--accent-green)' : 'var(--accent-red)'; ?>;"><?php echo eur($ct['net']); ?> €</span></div>
                                    </div>
                                    <div style="display: flex; gap: 8px;">
                                        <a href="budget.php?view=archive&archive=<?php echo urlencode($basename); ?>" class="btn" style="padding: 4px 8px; font-size: 0.7rem;">Ansehen</a>
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('Archiv wirklich unwiderruflich löschen?');"><input type="hidden" name="archive_filename" value="<?php echo e($basename); ?>"><button type="submit" name="delete_archive" class="btn btn-danger" style="padding: 4px 8px; font-size: 0.7rem;">X</button></form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>