<?php
/**
 * Live-Vorschau für die Editor-Werkzeugleiste (assets/js/editor.js).
 * Nur für angemeldete Benutzer und nur von dieser Seite aus aufrufbar.
 */
require_once __DIR__ . '/inc/core/auth.php';
require_once __DIR__ . '/inc/core/format.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_logged_in() || !same_origin_request()) {
    http_response_code(403);
    exit;
}
$text = (string)($_POST['text'] ?? '');
echo ($_POST['mode'] ?? '') === 'inline' ? format_inline($text) : format_text($text);
