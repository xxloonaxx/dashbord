<?php
/**
 * Bank-Anbindung für das Budget über Enable Banking (Open Banking / PSD2).
 *
 * Warum nicht direkt die "Revolut Open Banking API"?
 *   Revolut gibt diese Schnittstelle nur an regulierte Zahlungsdienstleister (PSD2-Lizenz + eIDAS-Zertifikate)
 *   heraus; für private Revolut-Konten gibt es keine eigene API. Enable Banking ist ein lizenzierter
 *   Kontoinformationsdienst, der Revolut (und viele andere Banken) über genau diese Open-Banking-Schnittstelle
 *   anbindet – und die Nutzung mit den EIGENEN Konten für Privatpersonen kostenlos erlaubt ("restricted mode").
 *
 * Ablauf:
 *   1. Konto bei enablebanking.com anlegen, Production-App registrieren -> Application-ID + privater Schlüssel (.pem)
 *   2. Beides hier hinterlegen, Bank (Revolut) wählen, verbinden -> Freigabe in der Revolut-App bestätigen
 *   3. Umsätze werden in den aktuellen Budget-Monat übernommen (ohne Duplikate, ohne interne Umbuchungen)
 *
 * Alle Zugangsdaten liegen in .openbanking.php (geschützter Speicher, siehe inc/auth.php).
 */
require_once __DIR__ . '/auth.php';

const OB_FILE = __DIR__ . '/../.openbanking.php';
const OB_AUTO_INTERVAL = 21600;   // automatischer Abgleich höchstens alle 6 Std. (Banken erlauben ohne Nutzer ca. 4 Abrufe/Tag)

function ob_api_origin(): string {
    // Für Tests kann eine andere Adresse gesetzt werden
    return rtrim(getenv('EB_API_ORIGIN') ?: 'https://api.enablebanking.com', '/');
}

function ob_config(): array {
    return secure_load(OB_FILE, []) + [
        'app_id' => '', 'private_key' => '', 'aspsp' => null, 'session_id' => '', 'valid_until' => '',
        'accounts' => [], 'last_sync' => 0, 'auto_sync' => true, 'history' => [],
    ];
}

function ob_save(array $cfg): void {
    secure_save(OB_FILE, $cfg);
}

function ob_connected(array $cfg): bool {
    return $cfg['session_id'] !== '' && $cfg['valid_until'] !== '' && strtotime($cfg['valid_until']) > time();
}

/** Callback-Adresse, die in der Enable-Banking-App als Redirect-URL eingetragen werden muss. */
function ob_redirect_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/budget.php')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/budget.php';
}

// ---------- API-Zugriff ----------

function b64url(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** JWT (RS256) für die API – signiert mit dem privaten Schlüssel der App, 1 Std. gültig. */
function ob_jwt(array $cfg): string {
    $key = openssl_pkey_get_private($cfg['private_key']);
    if (!$key) throw new RuntimeException('Der private Schlüssel (.pem) ist ungültig.');
    $iat = time();
    $head = b64url(json_encode(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $cfg['app_id']]));
    $body = b64url(json_encode(['iss' => 'enablebanking.com', 'aud' => 'api.enablebanking.com', 'iat' => $iat, 'exp' => $iat + 3600]));
    openssl_sign("$head.$body", $sig, $key, OPENSSL_ALGO_SHA256);
    return "$head.$body." . b64url($sig);
}

/**
 * API-Aufruf. Liefert das JSON als Array oder wirft eine verständliche Fehlermeldung.
 * $psu_present = true schickt IP/Browser des Nutzers mit – dann gelten keine Abruf-Limits der Bank.
 */
function ob_request(array $cfg, string $method, string $path, ?array $body = null, array $query = [], bool $psu_present = false): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Die PHP-Erweiterung cURL fehlt auf dem Server.');
    $url = ob_api_origin() . $path . ($query ? '?' . http_build_query($query) : '');
    $headers = ['Authorization: Bearer ' . ob_jwt($cfg), 'Accept: application/json'];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    if ($psu_present) {
        $headers[] = 'Psu-Ip-Address: ' . client_ip();
        $headers[] = 'Psu-User-Agent: ' . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0'), 0, 200);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) throw new RuntimeException('Enable Banking nicht erreichbar: ' . $err);
    $json = json_decode((string)$raw, true);
    if ($code >= 200 && $code < 300) return is_array($json) ? $json : [];

    $msg = is_array($json) ? ($json['message'] ?? $json['error'] ?? $json['detail'] ?? '') : '';
    if (is_array($msg)) $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
    $hints = [
        401 => 'Application-ID oder Schlüssel passen nicht zusammen.',
        403 => 'Zugriff verweigert – ist die App aktiviert („Activate by linking accounts“) bzw. die Freigabe noch gültig?',
        422 => 'Die Bank hat die Anfrage abgelehnt.',
        429 => 'Zu viele Abrufe – die Bank erlaubt ohne dich ca. 4 Abrufe pro Tag. Später erneut versuchen.',
    ];
    if (stripos((string)$msg, 'application does not exist') !== false) {
        throw new RuntimeException('Enable Banking kennt diese Application-ID nicht – bitte ID prüfen (Production-App, nicht Sandbox?).');
    }
    throw new RuntimeException('Fehler ' . $code . ($msg ? ': ' . $msg : '') . (isset($hints[$code]) ? ' – ' . $hints[$code] : ''));
}

// ---------- Einordnung von Buchungen (auch vom CSV-Import genutzt) ----------

/**
 * Ordnet eine Buchung einer Budget-Sektion zu.
 * Liefert null für interne Umbuchungen (Pockets, Unterkonten, Transfers zwischen eigenen Konten).
 */
function budget_classify(string $desc, float $signed_amount, string $type = ''): ?array {
    $d = mb_strtolower($desc);
    if (mb_strtolower($type) === 'transfer' || preg_match('/unterkonto|einzustecken|pocket|tresor|vault|savings|sparkonto|exchanged to|umgetauscht/u', $d)) {
        return null;
    }
    if ($signed_amount > 0) return ['section' => 'income', 'category' => 'Lohn / Abschlag'];
    if (preg_match('/miete|strom|nebenkosten|\bgas\b|wasser|versicherung|kfz|rundfunk|internet|telekom|vodafone|\bo2\b/u', $d)) return ['section' => 'fixed', 'category' => 'Fixkosten'];
    if (preg_match('/netflix|spotify|gym|youtube|disney|amazon prime|xbox|playstation|patreon|apple\.com\/bill|google \*|steam|discord|crunchyroll|dazn|audible/u', $d)) return ['section' => 'subs', 'category' => 'Abo'];
    if (preg_match('/tankstelle|\baral\b|\bshell\b|\besso\b|\bjet\b|\btotal\b|\bhem\b/u', $d)) return ['section' => 'other', 'category' => 'Tanken'];
    if (preg_match('/\brewe\b|\baldi\b|\blidl\b|edeka|\bnetto\b|\bpenny\b|kaufland|\bdm\b|rossmann/u', $d)) return ['section' => 'other', 'category' => 'Lebensmittel'];
    return ['section' => 'other', 'category' => 'Shopping / Sonstiges'];
}

/** Zeitraum des Budget-Monats ("November 2026") – sonst aktueller Kalendermonat. */
function budget_month_range(string $month_name): array {
    $months = ['januar' => 1, 'februar' => 2, 'märz' => 3, 'maerz' => 3, 'april' => 4, 'mai' => 5, 'juni' => 6, 'juli' => 7,
               'august' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'dezember' => 12];
    if (preg_match('/(\p{L}+)\s+(\d{4})/u', mb_strtolower($month_name), $m) && isset($months[$m[1]])) {
        $start = mktime(0, 0, 0, $months[$m[1]], 1, (int)$m[2]);
    } else {
        $start = mktime(0, 0, 0, (int)date('n'), 1, (int)date('Y'));
    }
    return [$start, strtotime('+1 month', $start) - 1];
}

// ---------- Verbindung ----------

/** Banken eines Landes (für die Auswahl) */
function ob_aspsps(array $cfg, string $country): array {
    $res = ob_request($cfg, 'GET', '/aspsps', null, ['country' => strtoupper($country), 'psu_type' => 'personal']);
    $list = [];
    foreach ($res['aspsps'] ?? [] as $a) {
        $list[] = ['name' => $a['name'], 'country' => $a['country'], 'max' => (int)($a['maximum_consent_validity'] ?? 7776000), 'beta' => !empty($a['beta'])];
    }
    usort($list, function ($a, $b) {
        $ra = stripos($a['name'], 'revolut') !== false ? 0 : 1;
        $rb = stripos($b['name'], 'revolut') !== false ? 0 : 1;
        return [$ra, $a['name']] <=> [$rb, $b['name']];
    });
    return $list;
}

/** Freigabe bei der Bank starten – liefert die Adresse, zu der der Nutzer weitergeleitet wird. */
function ob_start_auth(array $cfg): string {
    $state = bin2hex(random_bytes(16));
    $_SESSION['ob_state'] = $state;
    $days = max(1, min(180, (int)floor(($cfg['aspsp']['max'] ?? 7776000) / 86400)));
    $_SESSION['ob_valid_until'] = gmdate('Y-m-d\TH:i:s\Z', time() + $days * 86400 - 3600);
    $res = ob_request($cfg, 'POST', '/auth', [
        'access' => ['valid_until' => $_SESSION['ob_valid_until']],
        'aspsp' => ['name' => $cfg['aspsp']['name'], 'country' => $cfg['aspsp']['country']],
        'state' => $state,
        'redirect_url' => ob_redirect_url(),
        'psu_type' => 'personal',
    ], [], true);
    if (empty($res['url'])) throw new RuntimeException('Enable Banking hat keine Weiterleitungs-Adresse geliefert.');
    return $res['url'];
}

/** Rückkehr von der Bank: Code gegen eine Sitzung tauschen und Konten speichern. */
function ob_finish_auth(array $cfg, string $code, string $state): array {
    if (empty($_SESSION['ob_state']) || !hash_equals($_SESSION['ob_state'], $state)) {
        throw new RuntimeException('Ungültige Rückmeldung der Bank (state passt nicht) – bitte erneut verbinden.');
    }
    unset($_SESSION['ob_state']);
    $res = ob_request($cfg, 'POST', '/sessions', ['code' => $code], [], true);

    $cfg['session_id'] = $res['session_id'] ?? '';
    $cfg['valid_until'] = $res['access']['valid_until'] ?? ($_SESSION['ob_valid_until'] ?? gmdate('c', time() + 90 * 86400));
    $cfg['accounts'] = [];
    foreach ($res['accounts'] ?? [] as $acc) {
        $cfg['accounts'][] = [
            'uid' => $acc['uid'],
            'iban' => $acc['account_id']['iban'] ?? '',
            'name' => $acc['name'] ?? ($acc['product'] ?? 'Konto'),
            'currency' => $acc['currency'] ?? 'EUR',
            'sync' => true,
            'balance' => null,
        ];
    }
    $cfg['last_sync'] = 0;
    return $cfg;
}

/** Verbindung trennen (Freigabe bei der Bank wird – wenn möglich – mit beendet). */
function ob_disconnect(array $cfg): array {
    if ($cfg['session_id'] !== '') {
        try { ob_request($cfg, 'DELETE', '/sessions/' . rawurlencode($cfg['session_id'])); } catch (Throwable $e) { /* ist evtl. schon abgelaufen */ }
    }
    $cfg['session_id'] = '';
    $cfg['valid_until'] = '';
    $cfg['accounts'] = [];
    return $cfg;
}

// ---------- Synchronisieren ----------

/** Text für den Budget-Eintrag aus einer Bankbuchung bilden */
function ob_transaction_title(array $t): string {
    $credit = ($t['credit_debit_indicator'] ?? '') === 'CRDT';
    $party = $credit ? ($t['debtor']['name'] ?? '') : ($t['creditor']['name'] ?? '');
    $remit = trim(implode(' ', array_filter((array)($t['remittance_information'] ?? []), 'is_string')));
    $title = trim($party) !== '' ? trim($party) : ($remit !== '' ? $remit : ($t['bank_transaction_code']['description'] ?? 'Bankbuchung'));
    return mb_substr(preg_replace('/\s+/u', ' ', $title), 0, 100);
}

/**
 * Holt die Umsätze aller ausgewählten Konten für den aktuellen Budget-Monat und übernimmt neue Buchungen.
 * - Duplikate werden erkannt (Bank-ID, sonst Datum+Betrag+Text; auch gegen CSV-Importe)
 * - Fixkosten/Abos aus den Vorlagen werden abgeglichen statt doppelt angelegt
 * - Interne Umbuchungen und vorgemerkte (noch nicht gebuchte) Umsätze werden übersprungen
 */
function ob_sync(array &$cfg, array &$data, bool $psu_present): array {
    if (!ob_connected($cfg)) throw new RuntimeException('Keine gültige Bankverbindung – bitte (erneut) verbinden.');
    [$from, $to] = budget_month_range($data['month_name'] ?? '');
    $to = min($to, time());
    $stats = ['imported' => 0, 'matched' => 0, 'skipped' => 0, 'duplicates' => 0, 'pending' => 0];

    // Bekannte Buchungen merken
    $known = [];
    $fuzzy = [];
    foreach ($data['entries'] as $e) {
        if (!empty($e['import_key'])) $known[$e['import_key']] = true;
        $day = date('Y-m-d', strtotime(str_replace('.', '-', substr($e['date'] ?? '', 0, 10))) ?: 0);
        $fuzzy[$day . '|' . number_format((float)$e['amount'], 2, '.', '') . '|' . mb_strtolower(mb_substr($e['title'], 0, 12))] = true;
    }

    foreach ($cfg['accounts'] as &$acc) {
        if (empty($acc['sync'])) continue;

        // Kontostand (nicht kritisch)
        try {
            $bal = ob_request($cfg, 'GET', '/accounts/' . rawurlencode($acc['uid']) . '/balances', null, [], $psu_present);
            $b = $bal['balances'][0] ?? null;
            if ($b) $acc['balance'] = ['amount' => (float)$b['balance_amount']['amount'], 'currency' => $b['balance_amount']['currency'] ?? $acc['currency'], 'at' => time()];
        } catch (Throwable $e) { /* Kontostand ist optional */ }

        $query = ['date_from' => date('Y-m-d', $from), 'date_to' => date('Y-m-d', $to)];
        $pages = 0;
        do {
            $res = ob_request($cfg, 'GET', '/accounts/' . rawurlencode($acc['uid']) . '/transactions', null, $query, $psu_present);
            foreach ($res['transactions'] ?? [] as $t) {
                if (($t['status'] ?? 'BOOK') !== 'BOOK') { $stats['pending']++; continue; }   // vorgemerkt -> später

                $amount = round((float)($t['transaction_amount']['amount'] ?? 0), 2);
                if ($amount == 0.0) continue;
                $signed = (($t['credit_debit_indicator'] ?? 'DBIT') === 'CRDT' ? 1 : -1) * abs($amount);
                $title = ob_transaction_title($t);
                $date = $t['booking_date'] ?? $t['value_date'] ?? $t['transaction_date'] ?? date('Y-m-d');
                $ref = $t['entry_reference'] ?? $t['transaction_id'] ?? '';
                $key = 'eb_' . md5($acc['uid'] . '|' . ($ref !== '' ? $ref : $date . '|' . $signed . '|' . $title));

                $fkey = $date . '|' . number_format(abs($signed), 2, '.', '') . '|' . mb_strtolower(mb_substr($title, 0, 12));
                if (isset($known[$key]) || isset($fuzzy[$fkey])) { $stats['duplicates']++; continue; }

                $class = budget_classify($title . ' ' . implode(' ', (array)($t['remittance_information'] ?? [])), $signed);
                if ($class === null) { $stats['skipped']++; $known[$key] = true; continue; }

                // Abgleich mit Fixkosten/Abos aus den Vorlagen (gleicher Betrag + passender Name)
                $matched = false;
                if ($signed < 0) {
                    foreach ($data['entries'] as &$e) {
                        if (empty($e['template_id']) || !empty($e['import_key']) || abs((float)$e['amount'] - abs($signed)) > 0.01) continue;
                        foreach (preg_split('/[\s\-\/]+/u', mb_strtolower($e['title'])) as $word) {
                            if (mb_strlen($word) >= 3 && mb_strpos(mb_strtolower($title), $word) !== false) {
                                $e['import_key'] = $key;
                                $e['status'] = 'Bezahlt';
                                $e['docs'] = trim(($e['docs'] ?? '') . ' · Bank: ' . date('d.m.', strtotime($date)) . ' ' . $title, ' ·');
                                $matched = true;
                                break 2;
                            }
                        }
                    }
                    unset($e);
                }
                $known[$key] = true;
                $fuzzy[$fkey] = true;
                if ($matched) { $stats['matched']++; continue; }

                $data['entries'][] = [
                    'id' => uniqid(),
                    'section' => $class['section'],
                    'title' => $title,
                    'amount' => abs($signed),
                    'category' => $class['category'],
                    'status' => $class['section'] === 'income' ? 'Erhalten' : 'Bezahlt',
                    'docs' => 'Bank-Sync (' . ($cfg['aspsp']['name'] ?? 'Bank') . ')',
                    'date' => date('d.m.Y', strtotime($date)) . ' 00:00',
                    'import_key' => $key,
                ];
                $stats['imported']++;
            }
            $query['continuation_key'] = $res['continuation_key'] ?? null;
        } while (!empty($query['continuation_key']) && ++$pages < 50);
    }
    unset($acc);

    $cfg['last_sync'] = time();
    array_unshift($cfg['history'], ['t' => time(), 'auto' => !$psu_present] + $stats);
    $cfg['history'] = array_slice($cfg['history'], 0, 15);
    return $stats;
}
