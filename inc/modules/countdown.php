<?php
/**
 * Countdown-Daten (gemeinsam genutzt von countdown.php, feed.php und index.php).
 */
require_once __DIR__ . '/../core/common.php';

const COUNTDOWN_FILE = DATA_DIR . '/json/countdown_data.json';

function default_countdowns(): array {
    $now = date('Y-m-d\TH:i');
    return ['events' => [
        ['id' => 'ef', 'title' => 'Eurofurence', 'date' => '2027-08-25T14:00', 'icon' => '🐾', 'repeat' => 'none',
         'note' => 'Hamburg – Datum bei Bedarf anpassen', 'link' => 'https://www.eurofurence.org', 'featured' => true, 'created' => $now],
        ['id' => 'xmas', 'title' => 'Heiligabend', 'date' => '2026-12-24T18:00', 'icon' => '🎄', 'repeat' => 'yearly',
         'note' => '', 'link' => '', 'featured' => false, 'created' => $now],
        ['id' => 'ny', 'title' => 'Neujahr', 'date' => '2027-01-01T00:00', 'icon' => '🎆', 'repeat' => 'yearly',
         'note' => '', 'link' => '', 'featured' => false, 'created' => $now],
    ]];
}

function load_countdowns(): array {
    if (!is_file(COUNTDOWN_FILE)) {
        $data = default_countdowns();
        save_json(COUNTDOWN_FILE, $data);
        return $data;
    }
    $data = load_json(COUNTDOWN_FILE, ['events' => []]);
    $data['events'] = $data['events'] ?? [];
    return $data;
}

/**
 * Nächster Termin eines Events als Timestamp.
 * Jährliche Events (Geburtstage, Feiertage) springen automatisch ins nächste Jahr.
 */
function next_occurrence(array $event, ?int $now = null): int {
    $now = $now ?? time();
    $ts = strtotime($event['date']) ?: $now;
    if (($event['repeat'] ?? 'none') === 'yearly') {
        $guard = 0;
        while ($ts <= $now && $guard++ < 200) {
            $ts = strtotime('+1 year', $ts);
        }
    }
    return $ts;
}

/** Start der Wartezeit (für den Fortschrittsbalken): Erstelldatum bzw. letztes Vorkommen bei jährlichen Events. */
function countdown_start(array $event, int $target): int {
    if (($event['repeat'] ?? 'none') === 'yearly') return strtotime('-1 year', $target);
    $created = strtotime($event['created'] ?? '') ?: $target - 86400 * 30;
    return min($created, $target - 60);
}

/** Events sortiert: [anstehend[], vergangen[]], jeweils mit 'ts' ergänzt. */
function sorted_countdowns(array $events): array {
    $now = time();
    $upcoming = [];
    $past = [];
    foreach ($events as $ev) {
        $ev['ts'] = next_occurrence($ev, $now);
        if ($ev['ts'] > $now) $upcoming[] = $ev; else $past[] = $ev;
    }
    usort($upcoming, function ($a, $b) { return $a['ts'] <=> $b['ts']; });
    usort($past, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
    return [$upcoming, $past];
}

/** Haupt-Countdown: das als "featured" markierte Event, sonst das nächste anstehende. */
function featured_countdown(): ?array {
    [$upcoming] = sorted_countdowns(load_countdowns()['events']);
    foreach ($upcoming as $ev) {
        if (!empty($ev['featured'])) return $ev;
    }
    return $upcoming[0] ?? null;
}

/** "in 3 Tagen", "in 5 Std." … */
function relative_days(int $ts): string {
    $diff = $ts - time();
    if ($diff <= 0) return 'jetzt';
    $days = (int)floor($diff / 86400);
    if ($days === 0) return 'in ' . max(1, (int)floor($diff / 3600)) . ' Std.';
    if ($days === 1) return 'in 1 Tag';
    return 'in ' . $days . ' Tagen';
}
