<?php
/**
 * Übersetzen mit DeepL oder Google Cloud Translation (für VRC Nexus im Dashboard).
 * Schlüssel je Benutzer, verschlüsselt (AES-256-GCM, inc/core/crypto.php) in der Tabelle user_prefs.
 */
require_once __DIR__ . '/vrchat.php';

const TR_LANGS = [
    'DE' => ['de', '🇩🇪 Deutsch'], 'EN-US' => ['en', '🇺🇸 Englisch (US)'], 'EN-GB' => ['en', '🇬🇧 Englisch (UK)'], 'JA' => ['ja', '🇯🇵 Japanisch'], 'KO' => ['ko', '🇰🇷 Koreanisch'],
    'ZH-HANS' => ['zh-CN', '🇨🇳 Chinesisch (vereinfacht)'], 'ZH-HANT' => ['zh-TW', '🇹🇼 Chinesisch (traditionell)'], 'FR' => ['fr', '🇫🇷 Französisch'], 'ES' => ['es', '🇪🇸 Spanisch'],
    'PT-BR' => ['pt', '🇧🇷 Portugiesisch (BR)'], 'IT' => ['it', '🇮🇹 Italienisch'], 'NL' => ['nl', '🇳🇱 Niederländisch'], 'PL' => ['pl', '🇵🇱 Polnisch'], 'RU' => ['ru', '🇷🇺 Russisch'],
    'UK' => ['uk', '🇺🇦 Ukrainisch'], 'TR' => ['tr', '🇹🇷 Türkisch'], 'SV' => ['sv', '🇸🇪 Schwedisch'], 'DA' => ['da', '🇩🇰 Dänisch'], 'NB' => ['no', '🇳🇴 Norwegisch'], 'FI' => ['fi', '🇫🇮 Finnisch'],
    'CS' => ['cs', '🇨🇿 Tschechisch'], 'HU' => ['hu', '🇭🇺 Ungarisch'], 'RO' => ['ro', '🇷🇴 Rumänisch'], 'EL' => ['el', '🇬🇷 Griechisch'], 'ID' => ['id', '🇮🇩 Indonesisch'],
    'AR' => ['ar', '🇸🇦 Arabisch'], 'TH' => ['th', '🇹🇭 Thai'], 'VI' => ['vi', '🇻🇳 Vietnamesisch'],
];
const TR_DEFAULTS = ['provider' => 'auto', 'chat_target' => 'EN-US', 'chat_mode' => 'both', 'ui_target' => 'DE', 'chat_default' => false, 'select_button' => true];

function tr_pref_get(int $uid, string $k): ?string {
    $st = vrc_db()->prepare('SELECT v FROM user_prefs WHERE user_id = ? AND k = ?');
    $st->execute([$uid, $k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}
function tr_pref_set(int $uid, string $k, string $v): void {
    vrc_db()->prepare('INSERT OR REPLACE INTO user_prefs (user_id, k, v) VALUES (?, ?, ?)')->execute([$uid, $k, $v]);
}

function tr_keys(int $uid): array {
    $raw = tr_pref_get($uid, 'tr_keys');
    return $raw ? ((array)(crypto_decrypt('translate', $raw) ?: [])) : [];
}
function tr_prefs(int $uid): array {
    $p = json_decode((string)tr_pref_get($uid, 'tr_prefs'), true);
    return array_merge(TR_DEFAULTS, is_array($p) ? array_intersect_key($p, TR_DEFAULTS) : []);
}
function tr_prefs_save(int $uid, array $in): array {
    $p = tr_prefs($uid);
    if (isset($in['provider']) && in_array($in['provider'], ['auto', 'deepl', 'google'], true)) $p['provider'] = $in['provider'];
    foreach (['chat_target', 'ui_target'] as $k) if (isset($in[$k]) && isset(TR_LANGS[$in[$k]])) $p[$k] = $in[$k];
    if (isset($in['chat_mode']) && in_array($in['chat_mode'], ['only', 'both', 'both_nl'], true)) $p['chat_mode'] = $in['chat_mode'];
    foreach (['chat_default', 'select_button'] as $k) if (isset($in[$k])) $p[$k] = in_array((string)$in[$k], ['1', 'true', 'on'], true);
    tr_pref_set($uid, 'tr_prefs', json_encode($p));
    return $p;
}
function tr_key_save(int $uid, string $provider, string $key): void {
    if (!in_array($provider, ['deepl', 'google'], true)) throw new RuntimeException('Unbekannter Anbieter.');
    $key = trim($key);
    if ($key !== '' && !preg_match('/^[\w:.\-]{20,120}$/', $key)) throw new RuntimeException('Der Schlüssel sieht nicht gültig aus.');
    $k = tr_keys($uid);
    if ($key === '') unset($k[$provider]); else $k[$provider] = $key;
    tr_pref_set($uid, 'tr_keys', crypto_encrypt('translate', $k));
}
function tr_provider(int $uid): string {
    $k = tr_keys($uid); $p = tr_prefs($uid)['provider'];
    if ($p !== 'auto' && !empty($k[$p])) return $p;
    return !empty($k['deepl']) ? 'deepl' : (!empty($k['google']) ? 'google' : '');
}
function tr_status(int $uid): array {
    $k = tr_keys($uid);
    $mask = function ($s) { return $s ? substr($s, 0, 4) . '…' . substr($s, -4) : ''; };
    return ['deepl' => $mask($k['deepl'] ?? ''), 'google' => $mask($k['google'] ?? ''), 'provider' => tr_provider($uid), 'prefs' => tr_prefs($uid),
            'langs' => array_map(function ($c, $l) { return ['code' => $c, 'name' => $l[1]]; }, array_keys(TR_LANGS), TR_LANGS)];
}

function tr_post(string $url, array $headers, array $body, string $who): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6,
                            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (getenv('NEXUS_TR_TEST') ? CURLPROTO_HTTP : 0),
                            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers), CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException($who . ' ist nicht erreichbar (' . $err . ').');
    $d = json_decode((string)$raw, true);
    if ($code < 200 || $code >= 300) {
        $hint = [400 => 'Anfrage ungültig', 401 => 'Schlüssel ungültig', 403 => 'Schlüssel ungültig oder nicht freigeschaltet', 429 => 'zu viele Anfragen – kurz warten', 456 => 'Monatskontingent aufgebraucht'][$code] ?? 'Fehler ' . $code;
        $msg = is_array($d) ? (string)($d['message'] ?? ($d['error']['message'] ?? '')) : '';
        throw new RuntimeException($who . ': ' . $hint . ($msg !== '' ? ' (' . mb_substr($msg, 0, 120) . ')' : ''));
    }
    return is_array($d) ? $d : [];
}

/** → ['text', 'detected', 'provider', 'target'] */
function tr_translate(int $uid, string $text, string $target = '', string $provider = ''): array {
    $text = trim($text);
    if ($text === '') throw new RuntimeException('Kein Text.');
    if (mb_strlen($text) > 5000) throw new RuntimeException('Text zu lang (max. 5000 Zeichen).');
    $provider = $provider ?: tr_provider($uid);
    if ($provider === '') throw new RuntimeException('Kein Übersetzungs-Schlüssel eingetragen (Konto → Übersetzen).');
    $key = tr_keys($uid)[$provider] ?? '';
    if ($key === '') throw new RuntimeException(($provider === 'deepl' ? 'DeepL' : 'Google') . '-Schlüssel fehlt.');
    $target = strtoupper($target);
    if (!isset(TR_LANGS[$target])) $target = tr_prefs($uid)['ui_target'];
    if ($provider === 'deepl') {
        $base = getenv('NEXUS_DEEPL_BASE') ?: (substr($key, -3) === ':fx' ? 'https://api-free.deepl.com' : 'https://api.deepl.com');
        $d = tr_post($base . '/v2/translate', ['Authorization: DeepL-Auth-Key ' . $key], ['text' => [$text], 'target_lang' => $target], 'DeepL');
        $t = $d['translations'][0] ?? [];
        return ['text' => (string)($t['text'] ?? ''), 'detected' => (string)($t['detected_source_language'] ?? ''), 'provider' => 'deepl', 'target' => $target];
    }
    $base = getenv('NEXUS_GOOGLE_BASE') ?: 'https://translation.googleapis.com';
    $d = tr_post($base . '/language/translate/v2?key=' . rawurlencode($key), [], ['q' => [$text], 'target' => TR_LANGS[$target][0], 'format' => 'text'], 'Google');
    $t = $d['data']['translations'][0] ?? [];
    return ['text' => (string)($t['translatedText'] ?? ''), 'detected' => strtoupper((string)($t['detectedSourceLanguage'] ?? '')), 'provider' => 'google', 'target' => $target];
}

/** Chatbox: übersetzen und je nach Einstellung mit Original kombinieren (max. 144 Zeichen) */
function tr_chat(int $uid, string $text, string $target = ''): string {
    $p = tr_prefs($uid);
    $r = tr_translate($uid, $text, $target ?: $p['chat_target']);
    $out = $p['chat_mode'] === 'both' ? $text . ' / ' . $r['text'] : ($p['chat_mode'] === 'both_nl' ? $text . "\n" . $r['text'] : $r['text']);
    if (mb_strlen($out) > 144) $out = $r['text'];
    return mb_substr($out, 0, 144);
}
