<?php
/**
 * Aufgabenplaner von außen anstoßen (Admin → System → Aufgaben).
 *
 *   Kommandozeile:  php /pfad/zur/seite/cron.php            fällige Aufgaben
 *                   php cron.php --all | --task=feed         alle bzw. eine bestimmte (auch wenn ausgeschaltet)
 *   Web (z. B. cron-job.org):  https://…/cron.php?key=<Schlüssel aus Admin → System>
 * <title>Cron</title>
 */
require_once __DIR__ . '/inc/core/tasks.php';

if (PHP_SAPI === 'cli') {
    $opt = getopt('', ['all', 'task:']);
    $only = isset($opt['task']) ? [(string)$opt['task']] : (isset($opt['all']) ? array_keys(TASKS) : null);
    $res = tasks_run($only, $only !== null);
    if (!$res) echo $only === null ? "Nichts fällig.\n" : "Läuft schon (Sperre) oder unbekannte Aufgabe.\n";
    foreach ($res as $id => $r) printf("%s %-10s %5.1fs  %s\n", $r['ok'] ? '✔' : '✖', $id, $r['seconds'], $r['text']);
    exit(in_array(false, array_column($res, 'ok'), true) ? 1 : 0);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!hash_equals(tasks_key(), (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'Falscher Schlüssel.']);
    exit;
}
ignore_user_abort(true);
echo json_encode(['ok' => true, 'ran' => tasks_run()], JSON_UNESCAPED_UNICODE);
