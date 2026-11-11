<?php
require_once __DIR__ . '/inc/common.php';
require_once __DIR__ . '/inc/auth.php';

// Nur für angemeldete Admins sichtbar – alle anderen landen beim Login
require_permission('con.access');
guard_post('con.access');

// Dateipfade
$data_file = __DIR__ . '/con_data.json';
$archive_dir = __DIR__ . '/archive/';

if (!is_dir($archive_dir)) {
    mkdir($archive_dir, 0755, true);
}

// Standard-Datenstruktur
$default_data = [
    'con_name' => 'Eurofurence',
    'total_budget' => 500.00,
    'con_docs' => 'Reisepläne, Orga-Infos und wichtige Notizen hier hinterlegen...',
    'expenses' => []
];

$con_categories = [
    'Hotel' => 'Hotel',
    'Bahn' => 'Bahn',
    'Ticket' => 'Ticket',
    'Food' => 'Food / Verpflegung',
    'Merchandise' => 'Merchandise',
    'Anderes' => 'Anderes'
];

// Aktive Daten laden
if (is_file($data_file)) {
    $data = load_json($data_file, $default_data);
    $data += ['con_docs' => '', 'expenses' => [], 'total_budget' => 0, 'con_name' => 'Unbenannte Con'];
} else {
    $data = $default_data;
    save_json($data_file, $data);
}

// POST-Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Con-Daten & Allgemeine Dokumentation aktualisieren
    if (isset($_POST['update_con'])) {
        $data['con_name'] = trim($_POST['con_name']) ?: 'Unbenannte Con';
        $data['total_budget'] = parse_amount($_POST['total_budget'] ?? 0);
        $data['con_docs'] = trim($_POST['con_docs']);
        save_json($data_file, $data);
        header('Location: con.php');
        exit;
    }
    
    // 2. Ausgabe hinzufügen
    if (isset($_POST['add_expense'])) {
        $title = trim($_POST['title']);
        $amount = parse_amount($_POST['amount'] ?? 0);
        $category = isset($con_categories[$_POST['category'] ?? '']) ? $_POST['category'] : 'Anderes';
        $status = ($_POST['status'] ?? '') === 'Offen' ? 'Offen' : 'Bezahlt';
        $docs = trim($_POST['docs']);
        
        if ($title !== '' && $amount > 0) {
            $data['expenses'][] = [
                'id' => uniqid(),
                'title' => $title,
                'amount' => $amount,
                'category' => $category,
                'status' => $status,
                'docs' => $docs,
                'date' => date('d.m.Y H:i')
            ];
            save_json($data_file, $data);
        }
        header('Location: con.php');
        exit;
    }

    // 3. Ausgabe bearbeiten (Popup Edit)
    if (isset($_POST['edit_expense'])) {
        $id = $_POST['expense_id'];
        $title = trim($_POST['title']);
        $amount = parse_amount($_POST['amount'] ?? 0);
        $category = isset($con_categories[$_POST['category'] ?? '']) ? $_POST['category'] : 'Anderes';
        $status = ($_POST['status'] ?? '') === 'Offen' ? 'Offen' : 'Bezahlt';
        $docs = trim($_POST['docs']);

        foreach ($data['expenses'] as &$exp) {
            if ($exp['id'] === $id) {
                $exp['title'] = $title;
                $exp['amount'] = $amount;
                $exp['category'] = $category;
                $exp['status'] = $status;
                $exp['docs'] = $docs;
                break;
            }
        }
        unset($exp);
        save_json($data_file, $data);
        header('Location: con.php');
        exit;
    }
    
    // 4. Status umschalten (Bezahlt / Offen)
    if (isset($_POST['toggle_status'])) {
        $id = $_POST['expense_id'];
        foreach ($data['expenses'] as &$exp) {
            if ($exp['id'] === $id) {
                $current_status = $exp['status'] ?? 'Bezahlt';
                $exp['status'] = ($current_status === 'Bezahlt') ? 'Offen' : 'Bezahlt';
                break;
            }
        }
        unset($exp);
        save_json($data_file, $data);
        header('Location: con.php');
        exit;
    }

    // 5. Einzelne Ausgabe löschen
    if (isset($_POST['delete_expense'])) {
        $id = $_POST['expense_id'];
        $data['expenses'] = array_values(array_filter($data['expenses'], function($item) use ($id) {
            return $item['id'] !== $id;
        }));
        save_json($data_file, $data);
        header('Location: con.php');
        exit;
    }
    
    // 6. Con archivieren & zurücksetzen
    if (isset($_POST['archive_con'])) {
        $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $data['con_name']);
        $archive_file = $archive_dir . 'con_' . $safe_name . '_' . date('Y-m-d_His') . '.json';
        save_json($archive_file, $data);
        
        // Name & Budget als Startwerte für die nächste Con behalten
        $data = array_merge($default_data, ['con_name' => $data['con_name'], 'total_budget' => $data['total_budget']]);
        save_json($data_file, $data);
        header('Location: con.php');
        exit;
    }

    // 7. Archiviertes File löschen
    if (isset($_POST['delete_archive'])) {
        $filename = basename($_POST['archive_filename'] ?? '');
        $target = $archive_dir . $filename;
        if (file_exists($target)) {
            unlink($target);
        }
        header('Location: con.php?view=archive');
        exit;
    }
}

// Berechnungen für aktive Con
$total_spent = 0;
$total_paid = 0;
$total_open = 0;
foreach ($data['expenses'] as $exp) {
    $total_spent += $exp['amount'];
    if (($exp['status'] ?? 'Bezahlt') === 'Bezahlt') {
        $total_paid += $exp['amount'];
    } else {
        $total_open += $exp['amount'];
    }
}
$remaining_budget = $data['total_budget'] - $total_spent;
$budget_ratio = $data['total_budget'] > 0 ? min(100, round($total_spent / $data['total_budget'] * 100)) : ($total_spent > 0 ? 100 : 0);

// Ausgaben je Kategorie für die Aufschlüsselung
$by_category = [];
foreach ($data['expenses'] as $exp) {
    $cat = $exp['category'] ?? 'Anderes';
    $by_category[$cat] = ($by_category[$cat] ?? 0) + $exp['amount'];
}
arsort($by_category);

// Ansichts-Modus
$view_mode = $_GET['view'] ?? 'active';
$selected_archive = $_GET['archive'] ?? null;
$archive_data = null;

if ($selected_archive) {
    $archive_path = $archive_dir . basename($selected_archive);
    if (file_exists($archive_path)) {
        $archive_data = load_json($archive_path, null);
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CON FINANZ &amp; DOKUMENTATIONS-NODE</title>
    <?php echo emoji_favicon('🎪'); ?>
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
            font-family: "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(circle at 50% 10%, rgba(56, 213, 230, 0.08), transparent 70%),
                linear-gradient(rgba(4, 6, 9, 0.9), rgba(4, 6, 9, 0.98));
            background-attachment: fixed;
            color: var(--text-main);
            margin: 0;
            padding: 40px 20px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        body::before {
            content: "";
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.3) 50%);
            background-size: 100% 4px;
            z-index: 1;
            pointer-events: none;
            opacity: 0.5;
        }

        .wrapper {
            width: 100%;
            max-width: 900px;
            z-index: 2;
        }

        .nav-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .nav-back, .nav-tab {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--accent-cyan);
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            padding: 6px 14px;
            background: rgba(56, 213, 230, 0.08);
            border: 1px solid var(--border-color);
            clip-path: polygon(0 0, calc(100% - 8px) 0, 100% 8px, 100% 100%, 0 100%);
            transition: all 0.2s ease;
        }

        .nav-back:hover, .nav-tab:hover, .nav-tab.active {
            background: rgba(56, 213, 230, 0.2);
            border-color: var(--accent-cyan);
            color: #fff;
        }

        .nav-tabs {
            display: flex;
            gap: 10px;
        }

        header {
            margin-bottom: 25px;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 15px;
        }

        .hud-tag {
            font-size: 0.7rem;
            letter-spacing: 3px;
            color: var(--accent-cyan);
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 6px;
        }

        h1 {
            font-size: 2.2rem;
            font-weight: 800;
            letter-spacing: 4px;
            margin: 0;
            text-transform: uppercase;
            color: var(--accent-gold);
            text-shadow: 0 0 12px rgba(243, 194, 98, 0.3);
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 25px;
        }

        @media (max-width: 768px) {
            .dashboard-grid { grid-template-columns: repeat(2, 1fr); }
        }

        .card {
            background-color: var(--panel-bg);
            border: 1px solid var(--border-color);
            padding: 16px;
            clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 10px, 100% 100%, 0 100%);
            backdrop-filter: blur(10px);
        }

        .card-title {
            font-size: 0.65rem;
            letter-spacing: 2px;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .card-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: 1px;
        }

        .card-value.green { color: var(--accent-green); }
        .card-value.red { color: var(--accent-red); }
        .card-value.gold { color: var(--accent-gold); }
        .card-value.purple { color: var(--accent-purple); }
        .card-sub { font-size: 0.7rem; color: var(--accent-red); letter-spacing: 1px; margin-top: 4px; text-transform: uppercase; }
        .progress-wrap { margin: -10px 0 25px; }
        .progress-label { display: flex; justify-content: space-between; font-size: 0.68rem; letter-spacing: 2px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px; }
        .progress { height: 8px; background: rgba(56, 213, 230, 0.08); border: 1px solid var(--border-color); }
        .progress-fill { height: 100%; background: var(--accent-green); box-shadow: 0 0 10px rgba(80, 250, 123, 0.4); transition: width 0.4s ease; }
        .progress-fill.warn { background: var(--accent-gold); box-shadow: 0 0 10px rgba(243, 194, 98, 0.4); }
        .progress-fill.over { background: var(--accent-red); box-shadow: 0 0 10px rgba(255, 85, 85, 0.4); }
        .cat-breakdown { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .cat-chip { font-size: 0.7rem; letter-spacing: 1px; text-transform: uppercase; color: var(--text-muted); border: 1px solid var(--border-color); padding: 3px 8px; background: rgba(56, 213, 230, 0.04); }
        .cat-chip strong { color: var(--accent-gold); }
        :focus-visible { outline: 2px solid var(--accent-cyan); outline-offset: 2px; }

        /* Panels */
        .panel {
            background-color: var(--panel-bg);
            border: 1px solid var(--border-color);
            padding: 20px;
            margin-bottom: 25px;
            clip-path: polygon(0 0, calc(100% - 12px) 0, 100% 12px, 100% 100%, 0 100%);
        }

        .panel-title {
            font-size: 0.85rem;
            letter-spacing: 2px;
            color: var(--accent-cyan);
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 8px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 10px;
            margin-bottom: 10px;
        }

        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
        }

        input, select, textarea {
            background-color: rgba(4, 6, 9, 0.95);
            border: 1px solid var(--border-color);
            color: #ffffff;
            padding: 10px 14px;
            font-size: 0.9rem;
            outline: none;
            width: 100%;
            letter-spacing: 1px;
            font-family: inherit;
        }

        input:focus, select:focus, textarea:focus {
            border-color: var(--accent-cyan);
            box-shadow: 0 0 10px var(--accent-glow);
        }

        textarea { resize: vertical; min-height: 80px; }

        .btn {
            background-color: rgba(56, 213, 230, 0.15);
            border: 1px solid var(--accent-cyan);
            color: var(--accent-cyan);
            padding: 10px 20px;
            font-weight: 700;
            letter-spacing: 2px;
            cursor: pointer;
            text-transform: uppercase;
            transition: all 0.2s ease;
        }

        .btn:hover {
            background-color: var(--accent-cyan);
            color: #040609;
            box-shadow: 0 0 12px var(--accent-cyan);
        }

        .btn-gold {
            background-color: rgba(243, 194, 98, 0.15);
            border-color: var(--accent-gold);
            color: var(--accent-gold);
        }
        .btn-gold:hover {
            background-color: var(--accent-gold);
            box-shadow: 0 0 12px var(--accent-gold);
            color: #040609;
        }

        .btn-danger {
            background-color: rgba(255, 85, 85, 0.15);
            border-color: var(--accent-red);
            color: var(--accent-red);
        }
        .btn-danger:hover {
            background-color: var(--accent-red);
            box-shadow: 0 0 12px var(--accent-red);
            color: #040609;
        }

        /* Expense List */
        .expense-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .expense-item {
            background: rgba(14, 20, 27, 0.7);
            border: 1px solid var(--border-color);
            padding: 14px 18px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 10px, 100% 100%, 0 100%);
        }

        .expense-info h4 {
            margin: 0 0 4px 0;
            font-size: 1rem;
            color: #fff;
            letter-spacing: 1px;
        }

        .expense-meta {
            font-size: 0.7rem;
            color: var(--text-muted);
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .expense-docs {
            font-size: 0.8rem;
            color: var(--accent-cyan);
            background: rgba(56, 213, 230, 0.05);
            border-left: 2px solid var(--accent-cyan);
            padding: 4px 8px;
            margin-top: 6px;
            white-space: pre-wrap;
            word-break: break-all;
        }

        .badge {
            display: inline-block;
            padding: 2px 6px;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            border: 1px solid currentColor;
        }
        .badge.paid { color: var(--accent-green); background: rgba(80,250,123,0.1); }
        .badge.open { color: var(--accent-red); background: rgba(255,85,85,0.1); }

        .expense-right {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
        }

        .expense-amount {
            font-weight: 700;
            font-size: 1.2rem;
            color: var(--accent-gold);
        }

        .empty-msg {
            text-align: center;
            color: var(--text-muted);
            padding: 25px;
            font-size: 0.85rem;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .archive-section {
            margin-top: 30px;
            border-top: 1px dashed var(--border-color);
            padding-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* MODAL POPUP STYLING */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(4, 6, 9, 0.85);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            backdrop-filter: blur(6px);
        }
        .modal-box {
            background-color: var(--panel-bg);
            border: 1px solid var(--accent-cyan);
            width: 100%;
            max-width: 500px;
            padding: 25px;
            clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 14px, 100% 100%, 0 100%);
            box-shadow: 0 0 25px var(--accent-glow);
            position: relative;
        }
        @media (max-width: 768px) {
            body { padding: 20px 14px; }
            h1 { font-size: 1.6rem; letter-spacing: 2px; }
            .nav-bar { flex-direction: column; align-items: flex-start; gap: 10px; }
            .expense-item { flex-direction: column; gap: 10px; }
            .expense-right { align-items: flex-start !important; flex-direction: column !important; }
            .archive-section { flex-direction: column; align-items: flex-start; gap: 12px; }
            .modal-box { margin: 0 12px; max-height: 90vh; overflow-y: auto; }
        }
        .modal-close {
            position: absolute;
            top: 15px; right: 20px;
            background: none; border: none;
            color: var(--accent-red);
            font-size: 1.2rem; font-weight: bold;
            cursor: pointer;
        }
    </style>
</head>
<body>

    <div class="wrapper">
        <div class="nav-bar">
            <a href="index.php" class="nav-back">&larr; ZURÜCK ZUM INDEX</a>
            <div class="nav-tabs">
                <a href="admin.php" class="nav-tab" title="Mein Konto">⚙ <?php echo e(user_label()); ?></a>
                <a href="con.php" class="nav-tab <?php echo ($view_mode === 'active' && !$selected_archive) ? 'active' : ''; ?>">Aktive Con</a>
                <a href="con.php?view=archive" class="nav-tab <?php echo ($view_mode === 'archive' || $selected_archive) ? 'active' : ''; ?>">Archiv-Reader</a>
            </div>
        </div>

        <?php if ($view_mode === 'active' && !$selected_archive): ?>
            <!-- ================= ACTIVE CON VIEW ================= -->
            <header>
                <div class="hud-tag">System // Active Event Node &amp; Documentation</div>
                <h1><?php echo htmlspecialchars($data['con_name']); ?></h1>
            </header>

            <!-- Dashboard Widgets -->
            <div class="dashboard-grid">
                <div class="card">
                    <div class="card-title">Gesamt-Budget</div>
                    <div class="card-value gold"><?php echo eur($data['total_budget']); ?> €</div>
                </div>
                <div class="card">
                    <div class="card-title">Ausgaben (Gesamt)</div>
                    <div class="card-value red"><?php echo eur($total_spent); ?> €</div>
                </div>
                <div class="card">
                    <div class="card-title">Bereits Bezahlt</div>
                    <div class="card-value green"><?php echo eur($total_paid); ?> €</div>
                    <?php if ($total_open > 0): ?><div class="card-sub">Offen: <?php echo eur($total_open); ?> €</div><?php endif; ?>
                </div>
                <div class="card">
                    <div class="card-title">Rest / Puffer</div>
                    <div class="card-value <?php echo $remaining_budget >= 0 ? 'purple' : 'red'; ?>">
                        <?php echo eur($remaining_budget); ?> €
                    </div>
                </div>
            </div>

            <div class="progress-wrap">
                <div class="progress-label">
                    <span>Budget verbraucht</span>
                    <span><?php echo $data['total_budget'] > 0 ? round($total_spent / $data['total_budget'] * 100) . ' %' : '–'; ?></span>
                </div>
                <div class="progress"><div class="progress-fill <?php echo $budget_ratio >= 100 ? 'over' : ($budget_ratio >= 85 ? 'warn' : ''); ?>" style="width: <?php echo $budget_ratio; ?>%;"></div></div>
                <?php if ($by_category): ?>
                    <div class="cat-breakdown">
                        <?php foreach ($by_category as $cat => $sum): ?>
                            <span class="cat-chip"><?php echo e($con_categories[$cat] ?? $cat); ?>: <strong><?php echo eur($sum); ?> €</strong></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Con Einstellungen & Allgemeine Dokumentation -->
            <div class="panel">
                <div class="panel-title">// Con-Stammdaten &amp; Allgemeine Dokumentation</div>
                <form method="POST">
                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 10px; margin-bottom: 12px;">
                        <input type="text" name="con_name" value="<?php echo htmlspecialchars($data['con_name']); ?>" placeholder="Con Name" required>
                        <input type="number" step="0.01" min="0" name="total_budget" value="<?php echo e($data['total_budget']); ?>" placeholder="Budget in €" inputmode="decimal" required>
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label style="font-size: 0.75rem; letter-spacing: 1px; color: var(--text-muted); text-transform: uppercase; display: block; margin-bottom: 4px;">Allgemeine Dokumentation &amp; Notizen (z.B. Hotel-Adresse, Zugverbindung, Orga-Links):</label>
                        <textarea name="con_docs" placeholder="Notizen zur Con..."><?php echo htmlspecialchars($data['con_docs'] ?? ''); ?></textarea>
                    </div>
                    <button type="submit" name="update_con" class="btn btn-gold">Stammdaten &amp; Dokumentation Speichern</button>
                </form>
            </div>

            <!-- Neue Ausgabe Hinzufügen -->
            <div class="panel">
                <div class="panel-title">// Neue Ausgabe &amp; Details erfassen</div>
                <form method="POST">
                    <div class="form-grid">
                        <input type="text" name="title" placeholder="Bezeichnung (z.B. Hotel, Zugticket...)" required>
                        <input type="number" step="0.01" min="0.01" name="amount" placeholder="Betrag in €" inputmode="decimal" required>
                        <select name="category">
                            <option value="Hotel">Hotel</option>
                            <option value="Bahn">Bahn</option>
                            <option value="Ticket">Ticket</option>
                            <option value="Food">Food / Verpflegung</option>
                            <option value="Merchandise">Merchandise</option>
                            <option value="Anderes" selected>Anderes</option>
                        </select>
                        <select name="status">
                            <option value="Bezahlt">Bezahlt</option>
                            <option value="Offen">Offen</option>
                        </select>
                    </div>
                    <div style="margin-bottom: 10px;">
                        <input type="text" name="docs" placeholder="Dokumentation / Buchungsnummer / Ticket-Link / Sitzplatz...">
                    </div>
                    <button type="submit" name="add_expense" class="btn">Ausgabe Hinzufügen</button>
                </form>
            </div>

            <!-- Ausgaben Liste -->
            <div class="panel">
                <div class="panel-title">// Ausgaben- &amp; Dokumentationsliste</div>
                <div class="expense-list">
                    <?php if (empty($data['expenses'])): ?>
                        <div class="empty-msg">Keine Ausgaben erfasst.</div>
                    <?php else: ?>
                        <?php foreach ($data['expenses'] as $exp): ?>
                            <?php $is_paid = (($exp['status'] ?? 'Bezahlt') === 'Bezahlt'); ?>
                            <div class="expense-item">
                                <div class="expense-info">
                                    <h4><?php echo htmlspecialchars($exp['title']); ?></h4>
                                    <div class="expense-meta">
                                        Kategorie: <strong><?php echo htmlspecialchars($exp['category']); ?></strong> 
                                        &bull; <span class="badge <?php echo $is_paid ? 'paid' : 'open'; ?>"><?php echo htmlspecialchars($exp['status'] ?? 'Bezahlt'); ?></span>
                                        &bull; <?php echo e($exp['date'] ?? ''); ?>
                                    </div>
                                    <?php if (!empty($exp['docs'])): ?>
                                        <div class="expense-docs">DOCS: <?php echo htmlspecialchars($exp['docs']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="expense-right">
                                    <span class="expense-amount">-<?php echo eur($exp['amount']); ?> €</span>
                                    <div style="display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end;">
                                        <!-- Bearbeiten Button (öffnet Popup) -->
                                        <button type="button" class="btn btn-gold" style="padding: 4px 8px; font-size: 0.65rem;" 
                                                data-expense="<?php echo e(json_encode($exp)); ?>" onclick="openEditModal(JSON.parse(this.dataset.expense))">
                                            Bearbeiten
                                        </button>
                                        
                                        <!-- Status umschalten -->
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="expense_id" value="<?php echo e($exp['id']); ?>">
                                            <button type="submit" name="toggle_status" class="btn <?php echo $is_paid ? '' : 'btn-gold'; ?>" style="padding: 4px 8px; font-size: 0.65rem;">
                                                <?php echo $is_paid ? 'Als Offen' : 'Bezahlen'; ?>
                                            </button>
                                        </form>

                                        <!-- Löschen -->
                                        <form method="POST" style="margin:0;" onsubmit="return confirm(<?php echo e(json_encode('Ausgabe „' . $exp['title'] . '“ wirklich löschen?', JSON_UNESCAPED_UNICODE)); ?>);">
                                            <input type="hidden" name="expense_id" value="<?php echo e($exp['id']); ?>">
                                            <button type="submit" name="delete_expense" class="btn btn-danger" style="padding: 4px 8px; font-size: 0.65rem;">Löschen</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Archivierungs-Funktion -->
            <div class="archive-section">
                <div>
                    <strong style="color: #fff; letter-spacing: 1px; font-size: 0.85rem; text-transform: uppercase;">Con abgeschlossen?</strong>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Sichert alle Daten &amp; Dokumentationen in den Archiv-Ordner und leert die Ausgabenliste (Name &amp; Budget bleiben als Vorlage stehen).</div>
                </div>
                <form method="POST" onsubmit="return confirm('Möchtest du diese Con wirklich archivieren und den Rechner für eine neue Con zurücksetzen?');">
                    <button type="submit" name="archive_con" class="btn btn-danger">Con archivieren &amp; Neustart</button>
                </form>
            </div>

            <!-- POPUP MODAL FÜR BEARBEITUNG -->
            <div id="editModal" class="modal-overlay">
                <div class="modal-box">
                    <button type="button" class="modal-close" onclick="closeEditModal()">&times;</button>
                    <div class="panel-title">// Ausgabe Bearbeiten</div>
                    <form method="POST">
                        <input type="hidden" name="expense_id" id="edit_expense_id">
                        
                        <div style="margin-bottom: 10px;">
                            <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Bezeichnung</label>
                            <input type="text" name="title" id="edit_title" required>
                        </div>
                        
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom: 10px;">
                            <div>
                                <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Betrag (€)</label>
                                <input type="number" step="0.01" min="0.01" name="amount" id="edit_amount" inputmode="decimal" required>
                            </div>
                            <div>
                                <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Status</label>
                                <select name="status" id="edit_status">
                                    <option value="Bezahlt">Bezahlt</option>
                                    <option value="Offen">Offen</option>
                                </select>
                            </div>
                        </div>

                        <div style="margin-bottom: 10px;">
                            <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Kategorie</label>
                            <select name="category" id="edit_category">
                                <option value="Hotel">Hotel</option>
                                <option value="Bahn">Bahn</option>
                                <option value="Ticket">Ticket</option>
                                <option value="Food">Food / Verpflegung</option>
                                <option value="Merchandise">Merchandise</option>
                                <option value="Anderes">Anderes</option>
                            </select>
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">Dokumentation / Notizen</label>
                            <input type="text" name="docs" id="edit_docs">
                        </div>

                        <button type="submit" name="edit_expense" class="btn btn-gold" style="width:100%;">Änderungen Speichern</button>
                    </form>
                </div>
            </div>

            <script>
                function openEditModal(exp) {
                    document.getElementById('edit_expense_id').value = exp.id;
                    document.getElementById('edit_title').value = exp.title;
                    document.getElementById('edit_amount').value = exp.amount;
                    document.getElementById('edit_category').value = exp.category || 'Anderes';
                    document.getElementById('edit_status').value = exp.status || 'Bezahlt';
                    document.getElementById('edit_docs').value = exp.docs || '';
                    document.getElementById('editModal').style.display = 'flex';
                    document.getElementById('edit_title').focus();
                }

                function closeEditModal() {
                    document.getElementById('editModal').style.display = 'none';
                }

                document.addEventListener('keydown', e => { if (e.key === 'Escape') closeEditModal(); });

                // Schließen bei Klick außerhalb des Modals
                window.onclick = function(event) {
                    let modal = document.getElementById('editModal');
                    if (event.target === modal) {
                        closeEditModal();
                    }
                }
            </script>

        <?php else: ?>
            <!-- ================= ARCHIVE READER VIEW ================= -->
            <header>
                <div class="hud-tag">System // Historical Event Archives</div>
                <h1>Archiv-Reader</h1>
            </header>

            <?php if ($selected_archive && $archive_data): ?>
                <div style="margin-bottom: 20px;">
                    <a href="con.php?view=archive" class="nav-back">&larr; Zurück zur Archiv-Liste</a>
                </div>

                <div class="panel" style="border-color: var(--accent-gold);">
                    <div class="panel-title" style="color: var(--accent-gold);">
                        Archiv-Details: <?php echo htmlspecialchars($archive_data['con_name']); ?>
                    </div>
                    
                    <?php 
                        $arch_spent = 0;
                        if (!empty($archive_data['expenses'])) {
                            foreach ($archive_data['expenses'] as $ae) { $arch_spent += $ae['amount']; }
                        }
                        $arch_budget = $archive_data['total_budget'] ?? 0;
                        $arch_diff = $arch_budget - $arch_spent;
                    ?>
                    
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 20px;">
                        <div class="card"><div class="card-title">Budget</div><div class="card-value gold"><?php echo eur($arch_budget); ?> €</div></div>
                        <div class="card"><div class="card-title">Ausgaben</div><div class="card-value red"><?php echo eur($arch_spent); ?> €</div></div>
                        <div class="card"><div class="card-title">Bilanz</div><div class="card-value <?php echo $arch_diff >= 0 ? 'green':'red'; ?>"><?php echo eur($arch_diff); ?> €</div></div>
                    </div>

                    <?php if (!empty($archive_data['con_docs'])): ?>
                        <div style="margin-bottom: 20px;">
                            <div class="card-title" style="margin-bottom:4px;">Gespeicherte Dokumentation / Notizen:</div>
                            <div class="expense-docs"><?php echo htmlspecialchars($archive_data['con_docs']); ?></div>
                        </div>
                    <?php endif; ?>

                    <h4 style="color: #fff; letter-spacing: 1px; text-transform: uppercase; font-size: 0.9rem; margin-bottom: 10px;">Ausgaben der Con:</h4>
                    <div class="expense-list">
                        <?php if (empty($archive_data['expenses'])): ?>
                            <div class="empty-msg">Keine Ausgaben in diesem Archiv.</div>
                        <?php else: ?>
                            <?php foreach ($archive_data['expenses'] as $ae): ?>
                                <div class="expense-item">
                                    <div class="expense-info">
                                        <h4><?php echo htmlspecialchars($ae['title']); ?></h4>
                                        <div class="expense-meta">Kategorie: <?php echo htmlspecialchars($ae['category']); ?> &bull; Status: <?php echo htmlspecialchars($ae['status'] ?? 'Bezahlt'); ?></div>
                                        <?php if (!empty($ae['docs'])): ?>
                                            <div class="expense-docs">DOCS: <?php echo htmlspecialchars($ae['docs']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="expense-right">
                                        <span class="expense-amount">-<?php echo eur($ae['amount']); ?> €</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: ?>
                <div class="panel">
                    <div class="panel-title">// Gespeicherte Con-Archive</div>
                    <div class="expense-list">
                        <?php
                        $archives = glob($archive_dir . '*.json') ?: [];
                        usort($archives, function ($a, $b) { return filemtime($b) <=> filemtime($a); });
                        if (empty($archives)):
                        ?>
                            <div class="empty-msg">Keine Archive gefunden.</div>
                        <?php else: ?>
                            <?php foreach ($archives as $arch_file): 
                                $content = load_json($arch_file, []);
                                $name = $content['con_name'] ?? 'Unbekannte Con';
                                $budget = $content['total_budget'] ?? 0;
                                $spent = 0;
                                if (!empty($content['expenses'])) {
                                    foreach ($content['expenses'] as $ex) { $spent += $ex['amount']; }
                                }
                                $basename = basename($arch_file);
                            ?>
                                <div class="expense-item" style="align-items: center;">
                                    <div class="expense-info">
                                        <h4><?php echo htmlspecialchars($name); ?></h4>
                                        <div class="expense-meta">Datei: <?php echo htmlspecialchars($basename); ?></div>
                                    </div>
                                    <div class="expense-right" style="flex-direction: row; align-items: center; gap: 20px;">
                                        <div style="text-align: right;">
                                            <div style="font-size: 0.9rem; color: var(--accent-gold); font-weight: 700;">Budget: <?php echo eur($budget); ?> €</div>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);">Ausgaben: <?php echo eur($spent); ?> €</div>
                                        </div>
                                        <div style="display: flex; gap: 8px;">
                                            <a href="con.php?view=archive&archive=<?php echo urlencode($basename); ?>" class="btn" style="padding: 6px 12px; font-size: 0.75rem;">Ansehen</a>
                                            <form method="POST" onsubmit="return confirm('Archiv wirklich unwiderruflich löschen?');" style="margin:0;">
                                                <input type="hidden" name="archive_filename" value="<?php echo htmlspecialchars($basename); ?>">
                                                <button type="submit" name="delete_archive" class="btn btn-danger" style="padding: 6px 10px; font-size: 0.75rem;">X</button>
                                            </form>
                                        </div>
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