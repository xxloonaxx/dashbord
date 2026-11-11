<?php
// Dateipfade
$data_file = __DIR__ . '/budget_data.json';
$fix_subs_file = __DIR__ . '/fix_subs_data.json';
$archive_dir = __DIR__ . '/archive_budget/';

if (!is_dir($archive_dir)) {
    mkdir($archive_dir, 0777, true);
}

// 1. Monatliche Daten laden
$default_data = [
    'month_name' => date('F Y'),
    'entries' => []
];

if (file_exists($data_file)) {
    $data = json_decode(file_get_contents($data_file), true);
} else {
    $data = $default_data;
}

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

if (file_exists($fix_subs_file)) {
    $fix_subs_data = json_decode(file_get_contents($fix_subs_file), true);
} else {
    $fix_subs_data = $default_fix_subs;
    file_put_contents($fix_subs_file, json_encode($fix_subs_data, JSON_PRETTY_PRINT));
}

// Automatischer Abgleich der Fixkosten / Abos im aktuellen Monat
function ensure_fix_subs_in_month(&$data, $fix_subs_data) {
    $existing_titles = [];
    foreach ($data['entries'] as $e) {
        if (in_array($e['section'], ['fixed', 'subs'])) {
            $existing_titles[$e['section'] . '|' . strtolower($e['title'])] = true;
        }
    }

    $added = false;
    foreach ($fix_subs_data['fixed'] as $f) {
        $key = 'fixed|' . strtolower($f['title']);
        if (!isset($existing_titles[$key])) {
            $data['entries'][] = [
                'id' => uniqid(),
                'section' => 'fixed',
                'title' => $f['title'],
                'amount' => $f['amount'],
                'category' => $f['category'],
                'status' => 'Bezahlt',
                'docs' => $f['docs'],
                'date' => date('d.m.Y H:i'),
                'template_id' => $f['id']
            ];
            $added = true;
        }
    }

    foreach ($fix_subs_data['subs'] as $s) {
        $key = 'subs|' . strtolower($s['title']);
        if (!isset($existing_titles[$key])) {
            $data['entries'][] = [
                'id' => uniqid(),
                'section' => 'subs',
                'title' => $s['title'],
                'amount' => $s['amount'],
                'category' => $s['category'],
                'status' => 'Bezahlt',
                'docs' => $s['docs'],
                'date' => date('d.m.Y H:i'),
                'template_id' => $s['id']
            ];
            $added = true;
        }
    }

    if ($added) {
        global $data_file;
        file_put_contents($data_file, json_encode($data, JSON_PRETTY_PRINT));
    }
}

ensure_fix_subs_in_month($data, $fix_subs_data);

// POST-Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Monatsnamen anpassen
    if (isset($_POST['update_month'])) {
        $data['month_name'] = trim($_POST['month_name']) ?: 'Unbenannter Monat';
        file_put_contents($data_file, json_encode($data, JSON_PRETTY_PRINT));
        header('Location: budget.php');
        exit;
    }
    
    // CSV-Import verarbeiten (Filter für interne Transfers / Unterkonten)
    if (isset($_POST['import_csv'])) {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file_tmp, 'r');
            
            if ($handle !== FALSE) {
                // Header einlesen
                $header = fgetcsv($handle, 1000, ',');
                if (!$header || count($header) < 5) {
                    rewind($handle);
                    $header = fgetcsv($handle, 1000, ';');
                }

                while (($row = fgetcsv($handle, 1000, ',')) !== FALSE) {
                    if (count($row) < 5) {
                        $row = str_getcsv(implode(',', $row), ';');
                    }
                    if (count($row) >= 6) {
                        $art = trim($row[0] ?? '');
                        $date_str = $row[2] ?? date('d.m.Y H:i');
                        $desc = trim($row[4] ?? 'Import');
                        $amount_raw = str_replace(',', '.', $row[5] ?? '0');
                        $amount = floatval($amount_raw);

                        if ($amount == 0) continue;

                        $art_lower = strtolower($art);
                        $desc_lower = strtolower($desc);

                        // --- INTERNE TRANSFERS & UNTERKONTEN FILTERN ---
                        // Ignoriert alle internen Umbuchungen, Pockets, Unterkonten & Transfers zwischen eigenen Konten
                        if ($art_lower === 'transfer' || 
                            strpos($desc_lower, 'unterkonto') !== false || 
                            strpos($desc_lower, 'einzustecken') !== false || 
                            strpos($desc_lower, 'pocket') !== false) {
                            continue; // Eintrag überspringen
                        }

                        // Sektion & Kategorie ermitteln
                        $section = 'other';
                        $category = 'Sonstiges';
                        $status = 'Bezahlt';

                        if ($amount > 0) {
                            $section = 'income';
                            $category = 'Lohn / Abschlag';
                            $status = 'Erhalten';
                        } else {
                            $amount = abs($amount);
                            
                            if (strpos($desc_lower, 'miete') !== false || strpos($desc_lower, 'strom') !== false) {
                                $section = 'fixed';
                                $category = 'Fixkosten';
                            } elseif (strpos($desc_lower, 'netflix') !== false || strpos($desc_lower, 'spotify') !== false || strpos($desc_lower, 'gym') !== false) {
                                $section = 'subs';
                                $category = 'Abo';
                            } else {
                                $section = 'other';
                                $category = 'Shopping / Tanken';
                            }
                        }

                        $data['entries'][] = [
                            'id' => uniqid(),
                            'section' => $section,
                            'title' => $desc,
                            'amount' => $amount,
                            'category' => $category,
                            'status' => $status,
                            'docs' => 'CSV-Import (' . $art . ')',
                            'date' => date('d.m.Y H:i', strtotime($date_str))
                        ];
                    }
                }
                fclose($handle);
                file_put_contents($data_file, json_encode($data, JSON_PRETTY_PRINT));
            }
        }
        header('Location: budget.php');
        exit;
    }

    // Normalen Eintrag hinzufügen
    if (isset($_POST['add_entry'])) {
        $section = $_POST['section'];
        $title = trim($_POST['title']);
        $amount = floatval($_POST['amount']);
        $category = trim($_POST['category']) ?: ($section === 'income' ? 'Lohn / Abschlag' : 'Sonstiges');
        $status = $_POST['status'];
        $docs = trim($_POST['docs']);
        
        if ($title !== '' && $amount > 0) {
            $data['entries'][] = [
                'id' => uniqid(),
                'section' => $section,
                'title' => $title,
                'amount' => $amount,
                'category' => $category,
                'status' => $status,
                'docs' => $docs,
                'date' => date('d.m.Y H:i')
            ];
            file_put_contents($data_file, json_encode($data, JSON_PRETTY_PRINT));
        }
        header('Location: budget.php');
        exit;
    }

    // Fixkosten / Abos Vorlage hinzufügen
    if (isset($_POST['add_fix_sub_item'])) {
        $sec = $_POST['section'];
        $title = trim($_POST['title']);
        $amount = floatval($_POST['amount']);
        $category = trim($_POST['category']);
        $docs = trim($_POST['docs']);

        if ($title !== '' && $amount > 0) {
            $newItemId = uniqid();
            $fix_subs_data[$sec][] = [
                'id' => $newItemId,
                'title' => $title,
                'amount' => $amount,
                'category' => $category,
                'docs' => $docs
            ];
            file_put_contents($fix_subs_file, json_encode($fix_subs_data, JSON_PRETTY_PRINT));

            $data['entries'][] = [
                'id' => uniqid(),
                'section' => $sec,
                'title' => $title,
                'amount' => $amount,
                'category' => $category,
                'status' => 'Bezahlt',
                'docs' => $docs,
                'date' => date('d.m.Y H:i'),
                'template_id' => $newItemId
            ];
            file_put_contents($data_file, json_encode($data, JSON_PRETTY_PRINT));
        }
        header('Location: budget.php?view=templates');
        exit;
    }

    // Fixkosten / Abos Vorlage bearbeiten
    if (isset($_POST['edit_fix_sub_item'])) {
        $sec = $_POST['section'];
        $id = $_POST['item_id'];
        $title = trim($_POST['title']);
        $amount = floatval($_POST['amount']);
        $category = trim($_POST['category']);
        $docs = trim($_POST['docs']);

        foreach ($fix_subs_data[$sec] as &$item) {
            if ($item['id'] === $id) {
                $item['title'] = $title;
                $item['amount'] = $amount;
                $item['category'] = $category;
                $item['docs'] = $docs;
                break;
            }
        }
        unset($item);
        file_put_contents($fix_subs_file, json_encode($fix_subs_data, JSON_PRETTY_PRINT));

        foreach ($data['entries'] as &$entry) {
            if (($entry['template_id'] ?? '') === $id) {
                $entry['title'] = $title;
                $entry['amount'] = $amount;
                $entry['category'] = $category;
                $entry['docs'] = $docs;
            }
        }
        unset($entry);
        file_put_contents($data_file, json_encode($data, JSON_PRETTY_PRINT));

        header('Location: budget.php?view=templates');
        exit;
    }

    // Fixkosten / Abos Vorlage löschen
    if (isset($_POST['delete_fix_sub_item'])) {
        $sec = $_POST['section'];
        $id = $_POST['item_id'];
        $fix_subs_data[$sec] = array_values(array_filter($fix_subs_data[$sec], function($item) use ($id) {
            return $item['id'] !== $id;
        }));
        file_put_contents($fix_subs_file, json_encode($fix_subs_data, JSON_PRETTY_PRINT));
        header('Location: budget.php?view=templates');
        exit;
    }

    // Monatseintrag bearbeiten
    if (isset($_POST['edit_entry'])) {
        $id = $_POST['entry_id'];
        $section = $_POST['section'];
        $title = trim($_POST['title']);
        $amount = floatval($_POST['amount']);
        $category = trim($_POST['category']);
        $status = $_POST['status'];
        $docs = trim($_POST['docs']);

        foreach ($data['entries'] as &$entry) {
            if ($entry['id'] === $id) {
                $entry['section'] = $section;
                $entry['title'] = $title;
                $entry['amount'] = $amount;
                $entry['category'] = $category;
                $entry['status'] = $status;
                $entry['docs'] = $docs;
                break;
            }
        }
        unset($entry);
        file_put_contents($data_file, json_encode($data, JSON_PRETTY_PRINT));
        header('Location: budget.php');
        exit;
    }
    
    // Status umschalten
    if (isset($_POST['toggle_status'])) {
        $id = $_POST['entry_id'];
        foreach ($data['entries'] as &$entry) {
            if ($entry['id'] === $id) {
                $is_inc = ($entry['section'] === 'income');
                $default_done = $is_inc ? 'Erhalten' : 'Bezahlt';
                $current_status = $entry['status'] ?? $default_done;
                $entry['status'] = ($current_status === $default_done) ? 'Offen' : $default_done;
                break;
            }
        }
        unset($entry);
        file_put_contents($data_file, json_encode($data, JSON_PRETTY_PRINT));
        header('Location: budget.php');
        exit;
    }

    // Eintrag löschen
    if (isset($_POST['delete_entry'])) {
        $id = $_POST['entry_id'];
        $data['entries'] = array_values(array_filter($data['entries'], function($item) use ($id) {
            return $item['id'] !== $id;
        }));
        file_put_contents($data_file, json_encode($data, JSON_PRETTY_PRINT));
        header('Location: budget.php');
        exit;
    }
    
    // Monat archivieren & Neustart
    if (isset($_POST['archive_month'])) {
        $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $data['month_name']);
        $archive_file = $archive_dir . 'budget_' . $safe_name . '_' . date('Y-m-d_His') . '.json';
        copy($data_file, $archive_file);
        
        $new_entries = [];
        foreach ($fix_subs_data['fixed'] as $f) {
            $new_entries[] = [
                'id' => uniqid(), 'section' => 'fixed', 'title' => $f['title'],
                'amount' => $f['amount'], 'category' => $f['category'],
                'status' => 'Bezahlt', 'docs' => $f['docs'], 'date' => date('d.m.Y H:i'), 'template_id' => $f['id']
            ];
        }
        foreach ($fix_subs_data['subs'] as $s) {
            $new_entries[] = [
                'id' => uniqid(), 'section' => 'subs', 'title' => $s['title'],
                'amount' => $s['amount'], 'category' => $s['category'],
                'status' => 'Bezahlt', 'docs' => $s['docs'], 'date' => date('d.m.Y H:i'), 'template_id' => $s['id']
            ];
        }

        $data = [
            'month_name' => date('F Y', strtotime('+1 month')),
            'entries' => $new_entries
        ];
        file_put_contents($data_file, json_encode($data, JSON_PRETTY_PRINT));
        header('Location: budget.php');
        exit;
    }

    // Archiv löschen
    if (isset($_POST['delete_archive'])) {
        $filename = basename($_POST['archive_filename']);
        $target = $archive_dir . $filename;
        if (file_exists($target)) unlink($target);
        header('Location: budget.php?view=archive');
        exit;
    }
}

// Berechnungen
$totals = ['income' => 0, 'fixed' => 0, 'subs' => 0, 'other' => 0];
$categorized_entries = ['income' => [], 'fixed' => [], 'subs' => [], 'other' => []];

foreach ($data['entries'] as $entry) {
    $sec = $entry['section'] ?? 'other';
    if (!isset($categorized_entries[$sec])) $sec = 'other';
    $categorized_entries[$sec][] = $entry;
    $totals[$sec] += $entry['amount'];
}

$total_income = $totals['income'];
$total_expense = $totals['fixed'] + $totals['subs'] + $totals['other'];
$net_balance = $total_income - $total_expense;

$view_mode = $_GET['view'] ?? 'active';
$selected_archive = $_GET['archive'] ?? null;
$archive_data = null;
if ($selected_archive && file_exists($archive_dir . basename($selected_archive))) {
    $archive_data = json_decode(file_get_contents($archive_dir . basename($selected_archive)), true);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MONATS-FINANZ &amp; LOHN NODE</title>
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
        .dashboard-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 25px; }
        @media (max-width: 768px) { .dashboard-grid { grid-template-columns: 1fr; } }
        .card { background-color: var(--panel-bg); border: 1px solid var(--border-color); padding: 16px; clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 10px, 100% 100%, 0 100%); backdrop-filter: blur(10px); }
        .card-title { font-size: 0.65rem; letter-spacing: 2px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; }
        .card-value { font-size: 1.3rem; font-weight: 700; color: #ffffff; letter-spacing: 1px; }
        .card-value.green { color: var(--accent-green); }
        .card-value.red { color: var(--accent-red); }
        .card-value.purple { color: var(--accent-purple); }
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
        .modal-close { position: absolute; top: 15px; right: 20px; background: none; border: none; color: var(--accent-red); font-size: 1.2rem; font-weight: bold; cursor: pointer; }
    </style>
</head>
<body>

    <div class="wrapper">
        <div class="nav-bar">
            <a href="index.php" class="nav-back">&larr; ZURÜCK ZUM INDEX</a>
            <div class="nav-tabs">
                <a href="budget.php" class="nav-tab <?php echo ($view_mode === 'active') ? 'active' : ''; ?>">Aktueller Monat</a>
                <a href="budget.php?view=import" class="nav-tab <?php echo ($view_mode === 'import') ? 'active' : ''; ?>">Konto-Import</a>
                <a href="budget.php?view=templates" class="nav-tab <?php echo ($view_mode === 'templates') ? 'active' : ''; ?>">Fix &amp; Abos</a>
                <a href="budget.php?view=archive" class="nav-tab <?php echo ($view_mode === 'archive' || $selected_archive) ? 'active' : ''; ?>">Archiv</a>
            </div>
        </div>

        <?php if ($view_mode === 'active' && !$selected_archive): ?>
            <!-- ================= AKTUELER MONAT ================= -->
            <header>
                <div class="hud-tag">System // Monthly Financial Terminal</div>
                <h1><?php echo htmlspecialchars($data['month_name']); ?></h1>
            </header>

            <div class="dashboard-grid">
                <div class="card"><div class="card-title">Einnahmen</div><div class="card-value green">+<?php echo number_format($total_income, 2, ',', '.'); ?> €</div></div>
                <div class="card"><div class="card-title">Ausgaben</div><div class="card-value red">-<?php echo number_format($total_expense, 2, ',', '.'); ?> €</div></div>
                <div class="card"><div class="card-title">Netto Bilanz</div><div class="card-value <?php echo $net_balance >= 0 ? 'purple' : 'red'; ?>"><?php echo number_format($net_balance, 2, ',', '.'); ?> €</div></div>
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
                        <input type="number" step="0.01" name="amount" placeholder="Betrag €" required>
                        <input type="text" name="category" id="new_category" placeholder="Kategorie">
                        <select name="status" id="new_status">
                            <option value="Erhalten">Erhalten</option>
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
                        <span>Summe: <?php echo $sec_key==='income'?'+':'-'; ?><?php echo number_format($totals[$sec_key], 2, ',', '.'); ?> €</span>
                    </div>
                    <div class="entry-list">
                        <?php if (empty($categorized_entries[$sec_key])): ?>
                            <div class="empty-msg">Keine Einträge in dieser Sektion.</div>
                        <?php else: ?>
                            <?php foreach ($categorized_entries[$sec_key] as $entry): ?>
                                <?php $is_done = (($entry['status'] ?? 'Bezahlt') === 'Bezahlt' || ($entry['status'] ?? '') === 'Erhalten'); ?>
                                <div class="entry-item <?php echo $meta['class']; ?>">
                                    <div class="entry-info">
                                        <h4><?php echo htmlspecialchars($entry['title']); ?></h4>
                                        <div class="entry-meta">
                                            Kat: <strong><?php echo htmlspecialchars($entry['category']); ?></strong> 
                                            &bull; <span class="badge <?php echo $is_done ? 'paid' : 'open'; ?>"><?php echo htmlspecialchars($entry['status'] ?? 'Erledigt'); ?></span>
                                            &bull; <?php echo $entry['date']; ?>
                                        </div>
                                        <?php if (!empty($entry['docs'])): ?>
                                            <div class="entry-docs">DOCS: <?php echo htmlspecialchars($entry['docs']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="entry-right">
                                        <span class="entry-amount <?php echo $sec_key==='income'?'inc':'exp'; ?>">
                                            <?php echo $sec_key==='income'?'+':'-'; ?><?php echo number_format($entry['amount'], 2, ',', '.'); ?> €
                                        </span>
                                        <div style="display: flex; gap: 6px;">
                                            <button type="button" class="btn btn-gold" style="padding: 3px 6px; font-size: 0.6rem;" onclick="openEditEntryModal('<?php echo $entry['id']; ?>', '<?php echo $sec_key; ?>', '<?php echo addslashes($entry['title']); ?>', '<?php echo $entry['amount']; ?>', '<?php echo addslashes($entry['category']); ?>', '<?php echo $entry['status'] ?? 'Bezahlt'; ?>', '<?php echo addslashes($entry['docs'] ?? ''); ?>')">Edit</button>
                                            <form method="POST" style="margin:0;"><input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>"><button type="submit" name="toggle_status" class="btn <?php echo $is_done ? '' : 'btn-gold'; ?>" style="padding: 3px 6px; font-size: 0.6rem;"><?php echo $is_done ? 'Offen' : 'Erledigt'; ?></button></form>
                                            <form method="POST" style="margin:0;"><input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>"><button type="submit" name="delete_entry" class="btn btn-danger" style="padding: 3px 6px; font-size: 0.6rem;">X</button></form>
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
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Sichert diesen Monat ins Archiv. Fixkosten &amp; Abos werden automatisch übernommen.</div>
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
                                    <option value="Bezahlt">Bezahlt / Erhalten</option>
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
                                <input type="number" step="0.01" name="amount" id="ee_amount" required>
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
                function updateCat(sec) { document.getElementById('new_category').value = (sec === 'income') ? 'Lohn / Abschlag' : 'Sonstiges'; }
                function openEditEntryModal(id, section, title, amount, category, status, docs) {
                    document.getElementById('ee_entry_id').value = id;
                    document.getElementById('ee_section').value = section;
                    document.getElementById('ee_title').value = title;
                    document.getElementById('ee_amount').value = amount;
                    document.getElementById('ee_category').value = category;
                    document.getElementById('ee_status').value = status;
                    document.getElementById('ee_docs').value = docs;
                    document.getElementById('editEntryModal').style.display = 'flex';
                }
                function closeEditEntryModal() { document.getElementById('editEntryModal').style.display = 'none'; }
                window.onclick = function(event) { if (event.target === document.getElementById('editEntryModal')) closeEditEntryModal(); }
                updateCat('other');
            </script>

        <?php elseif ($view_mode === 'import'): ?>
            <!-- ================= KONTOAUSZUG IMPORT ================= -->
            <header>
                <div class="hud-tag">System // Bank Statement Importer</div>
                <h1>Kontoauszug Import</h1>
            </header>

            <div class="panel" style="border-color: var(--accent-cyan);">
                <div class="panel-title">// CSV-Datei Hochladen (z.B. Revolut / Bank Export)</div>
                <form method="POST" enctype="multipart/form-data">
                    <div style="margin-bottom: 15px;">
                        <label style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; display: block; margin-bottom: 6px;">Wähle eine CSV-Kontoauszugsdatei aus:</label>
                        <input type="file" name="csv_file" accept=".csv" required style="padding: 12px; background: rgba(0,0,0,0.5);">
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 15px;">
                        * Interne Umbuchungen (Transfers, Unterkonten, Pockets) werden automatisch herausgefiltert. Nur echte Einnahmen und Ausgaben werden importiert.
                    </div>
                    <button type="submit" name="import_csv" class="btn btn-gold">Kontoauszug Jetzt Importieren</button>
                </form>
            </div>

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
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <h3 style="color: var(--accent-cyan); font-size: 0.9rem; text-transform: uppercase; margin-bottom: 12px; border-bottom: 1px dashed var(--border-color); padding-bottom: 5px;">Fixkosten Vorlagen:</h3>
                        <div class="entry-list">
                            <?php if (empty($fix_subs_data['fixed'])): ?>
                                <div class="empty-msg">Keine Fixkosten-Vorlagen.</div>
                            <?php else: ?>
                                <?php foreach ($fix_subs_data['fixed'] as $f): ?>
                                    <div class="entry-item sec-fixed" style="align-items: center;">
                                        <div>
                                            <div style="font-weight: 700; font-size: 0.95rem; color:#fff;"><?php echo htmlspecialchars($f['title']); ?></div>
                                            <div style="font-size: 0.7rem; color:var(--text-muted);"><?php echo number_format($f['amount'], 2, ',', '.'); ?> € &bull; Kat: <?php echo htmlspecialchars($f['category']); ?></div>
                                        </div>
                                        <div style="display: flex; gap: 6px;">
                                            <button type="button" class="btn btn-gold" style="padding: 4px 8px; font-size: 0.65rem;" onclick="openEditFixSubModal('fixed', '<?php echo $f['id']; ?>', '<?php echo addslashes($f['title']); ?>', '<?php echo $f['amount']; ?>', '<?php echo addslashes($f['category']); ?>', '<?php echo addslashes($f['docs'] ?? ''); ?>')">Edit</button>
                                            <form method="POST" style="margin:0;"><input type="hidden" name="section" value="fixed"><input type="hidden" name="item_id" value="<?php echo $f['id']; ?>"><button type="submit" name="delete_fix_sub_item" class="btn btn-danger" style="padding: 4px 8px; font-size: 0.65rem;">X</button></form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <h3 style="color: var(--accent-purple); font-size: 0.9rem; text-transform: uppercase; margin-bottom: 12px; border-bottom: 1px dashed var(--border-color); padding-bottom: 5px;">Abo Vorlagen:</h3>
                        <div class="entry-list">
                            <?php if (empty($fix_subs_data['subs'])): ?>
                                <div class="empty-msg">Keine Abo-Vorlagen.</div>
                            <?php else: ?>
                                <?php foreach ($fix_subs_data['subs'] as $s): ?>
                                    <div class="entry-item sec-subs" style="align-items: center;">
                                        <div>
                                            <div style="font-weight: 700; font-size: 0.95rem; color:#fff;"><?php echo htmlspecialchars($s['title']); ?></div>
                                            <div style="font-size: 0.7rem; color:var(--text-muted);"><?php echo number_format($s['amount'], 2, ',', '.'); ?> € &bull; Kat: <?php echo htmlspecialchars($s['category']); ?></div>
                                        </div>
                                        <div style="display: flex; gap: 6px;">
                                            <button type="button" class="btn btn-gold" style="padding: 4px 8px; font-size: 0.65rem;" onclick="openEditFixSubModal('subs', '<?php echo $s['id']; ?>', '<?php echo addslashes($s['title']); ?>', '<?php echo $s['amount']; ?>', '<?php echo addslashes($s['category']); ?>', '<?php echo addslashes($s['docs'] ?? ''); ?>')">Edit</button>
                                            <form method="POST" style="margin:0;"><input type="hidden" name="section" value="subs"><input type="hidden" name="item_id" value="<?php echo $s['id']; ?>"><button type="submit" name="delete_fix_sub_item" class="btn btn-danger" style="padding: 4px 8px; font-size: 0.65rem;">X</button></form>
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
                                <input type="number" step="0.01" name="amount" id="fs_amount" required>
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
                    document.getElementById('fs_item_id').value = '';
                    document.getElementById('fs_title').value = '';
                    document.getElementById('fs_amount').value = '';
                    document.getElementById('fs_category').value = '';
                    document.getElementById('fs_docs').value = '';
                    document.getElementById('fs_submit_btn').name = 'add_fix_sub_item';
                    document.getElementById('fixSubModal').style.display = 'flex';
                }
                function openEditFixSubModal(sec, id, title, amount, category, docs) {
                    document.getElementById('fs_item_id').value = id;
                    document.getElementById('fs_section').value = sec;
                    document.getElementById('fs_title').value = title;
                    document.getElementById('fs_amount').value = amount;
                    document.getElementById('fs_category').value = category;
                    document.getElementById('fs_docs').value = docs;
                    document.getElementById('fs_submit_btn').name = 'edit_fix_sub_item';
                    document.getElementById('fixSubModal').style.display = 'flex';
                }
                function closeFixSubModal() { document.getElementById('fixSubModal').style.display = 'none'; }
                window.onclick = function(event) { if (event.target === document.getElementById('fixSubModal')) closeFixSubModal(); }
            </script>

        <?php else: ?>
            <!-- ================= ARCHIV ================= -->
            <header>
                <div class="hud-tag">System // Historical Financial Archives</div>
                <h1>Archiv-Reader</h1>
            </header>
            
            <?php if ($selected_archive && $archive_data): ?>
                <div style="margin-bottom: 20px;"><a href="budget.php?view=archive" class="nav-back">&larr; Zurück zur Liste</a></div>
                <div class="panel" style="border-color: var(--accent-gold);">
                    <div class="panel-title" style="color: var(--accent-gold);">Archiv: <?php echo htmlspecialchars($archive_data['month_name']); ?></div>
                    <div class="entry-list">
                        <?php foreach ($archive_data['entries'] as $ae): ?>
                            <div class="entry-item">
                                <div class="entry-info">
                                    <h4><?php echo htmlspecialchars($ae['title']); ?></h4>
                                    <div class="entry-meta">Sektion: <?php echo htmlspecialchars($ae['section']); ?> &bull; <?php echo htmlspecialchars($ae['category']); ?></div>
                                </div>
                                <div class="entry-right"><span class="entry-amount"><?php echo number_format($ae['amount'], 2, ',', '.'); ?> €</span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="panel">
                    <div class="panel-title">// Gespeicherte Monats-Archive</div>
                    <div class="entry-list">
                        <?php
                        $archives = glob($archive_dir . '*.json');
                        if (empty($archives)):
                        ?>
                            <div class="empty-msg">Keine Monats-Archive vorhanden.</div>
                        <?php else: ?>
                            <?php foreach ($archives as $arch_file): 
                                $content = json_decode(file_get_contents($arch_file), true);
                                $basename = basename($arch_file);
                            ?>
                                <div class="entry-item" style="align-items: center;">
                                    <div><h4><?php echo htmlspecialchars($content['month_name']); ?></h4></div>
                                    <div style="display: flex; gap: 8px;">
                                        <a href="budget.php?view=archive&archive=<?php echo urlencode($basename); ?>" class="btn" style="padding: 4px 8px; font-size: 0.7rem;">Ansehen</a>
                                        <form method="POST" style="margin:0;"><input type="hidden" name="archive_filename" value="<?php echo htmlspecialchars($basename); ?>"><button type="submit" name="delete_archive" class="btn btn-danger" style="padding: 4px 8px; font-size: 0.7rem;">X</button></form>
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