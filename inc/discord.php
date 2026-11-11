<?php
/**
 * Discord-Zentrale: Anbindung an die Discord-API über einen eigenen Bot.
 * Der Bot-Token wird verschlüsselt in den Einstellungen gespeichert (crypto_encrypt) und nie wieder angezeigt.
 * Geplante Nachrichten werden beim Öffnen der Seite oder über einen Cron-Aufruf (discord.php?cron=…) verschickt.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/format.php';

const DISCORD_API_DEFAULT = 'https://discord.com/api/v10';
const DISCORD_CDN = 'https://cdn.discordapp.com';
/** Rechte, um die der Bot beim Einladen bittet */
const DISCORD_PERMS = [0 => 'Einladungen erstellen', 1 => 'Mitglieder kicken', 2 => 'Mitglieder bannen', 4 => 'Kanäle verwalten', 5 => 'Server verwalten', 6 => 'Reaktionen',
                       7 => 'Audit-Log ansehen', 10 => 'Kanäle sehen', 11 => 'Nachrichten senden', 13 => 'Nachrichten verwalten', 14 => 'Links einbetten', 15 => 'Dateien anhängen',
                       16 => 'Verlauf lesen', 27 => 'Spitznamen verwalten', 28 => 'Rollen verwalten', 29 => 'Webhooks verwalten',
                       30 => 'Emojis & Sticker verwalten', 33 => 'Events verwalten', 34 => 'Threads verwalten', 35 => 'Threads erstellen', 38 => 'In Threads schreiben', 40 => 'Timeout vergeben', 49 => 'Umfragen senden'];
const DISCORD_CHANNEL_TYPES = [0 => ['#', 'Text'], 2 => ['🔊', 'Sprache'], 4 => ['📁', 'Kategorie'], 5 => ['📢', 'Ankündigungen'], 13 => ['🎙️', 'Bühne'], 15 => ['💬', 'Forum'], 16 => ['🖼️', 'Medien']];
const DISCORD_AUDIT = [1 => 'Server geändert', 10 => 'Kanal erstellt', 11 => 'Kanal geändert', 12 => 'Kanal gelöscht', 13 => 'Kanal-Rechte erstellt', 14 => 'Kanal-Rechte geändert',
                       15 => 'Kanal-Rechte gelöscht', 20 => 'Mitglied gekickt', 21 => 'Mitglieder aussortiert', 22 => 'Mitglied gebannt', 23 => 'Bann aufgehoben', 24 => 'Mitglied geändert',
                       25 => 'Rollen eines Mitglieds geändert', 26 => 'Mitglied verschoben', 27 => 'Mitglied getrennt', 28 => 'Bot hinzugefügt', 30 => 'Rolle erstellt', 31 => 'Rolle geändert',
                       32 => 'Rolle gelöscht', 40 => 'Einladung erstellt', 41 => 'Einladung geändert', 42 => 'Einladung gelöscht', 50 => 'Webhook erstellt', 51 => 'Webhook geändert',
                       52 => 'Webhook gelöscht', 60 => 'Emoji erstellt', 61 => 'Emoji geändert', 62 => 'Emoji gelöscht', 72 => 'Nachricht gelöscht', 73 => 'Nachrichten gelöscht',
                       74 => 'Nachricht angepinnt', 75 => 'Nachricht gelöst', 80 => 'Integration erstellt', 83 => 'Stage gestartet', 100 => 'Event erstellt', 101 => 'Event geändert',
                       102 => 'Event gelöscht', 110 => 'Thread erstellt', 111 => 'Thread geändert', 112 => 'Thread gelöscht', 140 => 'AutoMod-Regel erstellt', 141 => 'AutoMod-Regel geändert',
                       143 => 'AutoMod hat Nachricht blockiert', 145 => 'AutoMod-Timeout'];

final class DiscordError extends RuntimeException {}

// ---------- Einrichtung ----------

function discord_token(): ?string {
    $enc = setting('discord_token', '');
    if ($enc === '') return null;
    $t = crypto_decrypt('discord', $enc);
    return is_string($t) && $t !== '' ? $t : null;
}

function discord_configured(): bool {
    return discord_token() !== null;
}

function discord_set_token(?string $token): void {
    set_setting('discord_token', $token === null ? '' : crypto_encrypt('discord', $token));
}

function discord_cron_key(): string {
    $k = setting('discord_cron', '');
    if ($k === '') { $k = bin2hex(random_bytes(16)); set_setting('discord_cron', $k); }
    return $k;
}

function discord_invite_url(string $client_id): string {
    $perm = 0;
    foreach (array_keys(DISCORD_PERMS) as $bit) $perm |= (1 << $bit);
    return 'https://discord.com/oauth2/authorize?client_id=' . rawurlencode($client_id) . '&scope=bot%20applications.commands&permissions=' . $perm;
}

// ---------- HTTP ----------

function discord_api(string $method, string $path, array $query = [], $body = null, string $reason = '', ?string $token = null) {
    if (!function_exists('curl_init')) throw new DiscordError('Auf dem Server fehlt die PHP-Erweiterung cURL.');
    $token = $token ?? discord_token();
    if (!$token) throw new DiscordError('Noch kein Bot eingerichtet.');
    $url = rtrim(setting('discord_api_base', DISCORD_API_DEFAULT), '/') . $path . ($query ? '?' . http_build_query($query) : '');
    $headers = ['Authorization: Bot ' . $token, 'Accept: application/json'];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    if ($reason !== '') $headers[] = 'X-Audit-Log-Reason: ' . rawurlencode(mb_substr($reason, 0, 400));
    for ($try = 0; $try < 2; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25,
                                CURLOPT_USERAGENT => 'DiscordBot (https://github.com/ghostnode-dashboard, 1.0)']);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new DiscordError('Discord ist nicht erreichbar (' . $err . '). Blockiert der Hoster ausgehende Verbindungen?');
        $json = json_decode((string)$raw, true);
        if ($code === 429 && $try === 0 && (float)($json['retry_after'] ?? 9) <= 2) { usleep((int)(((float)$json['retry_after'] + 0.1) * 1e6)); continue; }
        break;
    }
    if ($code === 204 || ($code >= 200 && $code < 300)) return is_array($json) ? $json : [];
    $dc = (int)($json['code'] ?? 0);
    $msg = (string)($json['message'] ?? '');
    $hints = [
        50001 => 'Der Bot hat keinen Zugriff darauf (fehlt er im Kanal, oder ist im Developer-Portal der „Server Members Intent“ aus?).',
        50013 => 'Dem Bot fehlt dafür ein Recht. Server-Einstellungen → Rollen: Bot-Rolle prüfen und über die Rolle des Ziels schieben.',
        10007 => 'Mitglied nicht gefunden.', 10003 => 'Kanal nicht gefunden.', 10008 => 'Nachricht nicht gefunden.', 10004 => 'Server nicht gefunden – ist der Bot dort eingeladen?',
        10011 => 'Rolle nicht gefunden.', 10026 => 'Bann nicht gefunden.', 50035 => 'Ungültige Eingabe',
    ];
    if ($code === 401) throw new DiscordError('Der Bot-Token ist ungültig oder wurde zurückgesetzt. Bitte in den Einstellungen neu eintragen.');
    if ($code === 429) throw new DiscordError('Discord bremst gerade (Rate-Limit). Bitte in ' . ceil((float)($json['retry_after'] ?? 5)) . ' s erneut versuchen.');
    $detail = '';
    if ($dc === 50035 && !empty($json['errors'])) $detail = ': ' . mb_substr(json_encode($json['errors'], JSON_UNESCAPED_UNICODE), 0, 200);
    throw new DiscordError(($hints[$dc] ?? 'Discord meldet Fehler ' . $code . ($msg ? ' (' . $msg . ')' : '') . '.') . $detail);
}

// ---------- Hilfen ----------

function discord_ts(string $snowflake): int {
    return (int)((intdiv((int)$snowflake, 4194304) + 1420070400000) / 1000);
}

function discord_user_avatar(array $u, ?string $guild_avatar = null): string {
    if (!empty($u['avatar'])) return DISCORD_CDN . '/avatars/' . $u['id'] . '/' . $u['avatar'] . '.png?size=64';
    $idx = isset($u['id']) ? (int)(intdiv((int)$u['id'], 4194304) % 6) : 0;
    return DISCORD_CDN . '/embed/avatars/' . $idx . '.png';
}

function discord_guild_icon(array $g): string {
    return !empty($g['icon']) ? DISCORD_CDN . '/icons/' . $g['id'] . '/' . $g['icon'] . '.png?size=128' : '';
}

function discord_name(array $u): string {
    return (string)($u['global_name'] ?? '') ?: (string)($u['username'] ?? '?');
}

function discord_color(int $c): string {
    return $c ? sprintf('#%06x', $c) : '';
}

function discord_valid_id(string $id): string {
    if (!preg_match('/^\d{15,22}$/', $id)) throw new DiscordError('Ungültige Discord-ID.');
    return $id;
}

/** Nachricht (Text + optional Embed) aus Formularfeldern bauen */
function discord_payload(array $in): array {
    $content = mb_substr(trim((string)($in['content'] ?? '')), 0, 2000);
    $p = ['content' => $content];
    if (!empty($in['use_embed'])) {
        $url = function ($k) use ($in) { $u = trim((string)($in[$k] ?? '')); return preg_match('#^https://\S+$#i', $u) ? mb_substr($u, 0, 2000) : null; };
        $e = array_filter([
            'title' => mb_substr(trim((string)($in['e_title'] ?? '')), 0, 256) ?: null,
            'url' => $url('e_url'),
            'description' => mb_substr(trim((string)($in['e_desc'] ?? '')), 0, 4096) ?: null,
            'color' => preg_match('/^#?([0-9a-f]{6})$/i', (string)($in['e_color'] ?? ''), $m) ? hexdec($m[1]) : null,
            'image' => $url('e_image') ? ['url' => $url('e_image')] : null,
            'thumbnail' => $url('e_thumb') ? ['url' => $url('e_thumb')] : null,
            'author' => trim((string)($in['e_author'] ?? '')) !== '' ? ['name' => mb_substr(trim($in['e_author']), 0, 256)] : null,
            'footer' => trim((string)($in['e_footer'] ?? '')) !== '' ? ['text' => mb_substr(trim($in['e_footer']), 0, 2048)] : null,
            'timestamp' => !empty($in['e_time']) ? gmdate('c') : null,
        ], function ($v) { return $v !== null; });
        $fields = [];
        foreach ((array)($in['f_name'] ?? []) as $i => $n) {
            $n = mb_substr(trim((string)$n), 0, 256);
            $v = mb_substr(trim((string)(($in['f_value'] ?? [])[$i] ?? '')), 0, 1024);
            if ($n !== '' && $v !== '') $fields[] = ['name' => $n, 'value' => $v, 'inline' => !empty(($in['f_inline'] ?? [])[$i])];
        }
        if ($fields) $e['fields'] = array_slice($fields, 0, 25);
        if ($e) $p['embeds'] = [$e];
    }
    if (!empty($in['use_poll'])) {
        $ans = array_values(array_filter(array_map('trim', (array)($in['poll_a'] ?? [])), 'strlen'));
        if (trim((string)($in['poll_q'] ?? '')) === '' || count($ans) < 2) throw new DiscordError('Eine Umfrage braucht eine Frage und mindestens zwei Antworten.');
        $p['poll'] = discord_poll((string)$in['poll_q'], $ans, (int)($in['poll_hours'] ?? 24), !empty($in['poll_multi']));
    }
    if ($p['content'] === '' && empty($p['embeds']) && empty($p['poll'])) throw new DiscordError('Die Nachricht ist leer.');
    $p['allowed_mentions'] = ['parse' => !empty($in['mentions']) ? ['users', 'roles', 'everyone'] : ['users']];
    if (!empty($in['reply_to']) && preg_match('/^\d{15,22}$/', $in['reply_to'])) $p['message_reference'] = ['message_id' => $in['reply_to'], 'fail_if_not_exists' => false];
    return $p;
}

/** Discord-Umfrage (max. 10 Antworten, Laufzeit 1–768 Stunden). Ein Emoji am Anfang einer Antwort wird als Antwort-Emoji genutzt. */
function discord_poll(string $question, array $answers, int $hours, bool $multi): array {
    return ['question' => ['text' => mb_substr(trim($question), 0, 300)], 'duration' => max(1, min(768, $hours ?: 24)), 'allow_multiselect' => $multi, 'layout_type' => 1,
            'answers' => array_map(function ($a) {
                $a = trim((string)$a);
                $media = ['text' => $a];
                if (@preg_match('/^(\p{Extended_Pictographic}(?:\x{FE0F})?(?:\x{200D}\p{Extended_Pictographic}(?:\x{FE0F})?)*)\s+(.+)$/u', $a, $m)) $media = ['text' => $m[2], 'emoji' => ['name' => $m[1]]];
                $media['text'] = mb_substr($media['text'], 0, 55);
                return ['poll_media' => $media];
            }, array_slice($answers, 0, 10))];
}

/** Umfrage einer Nachricht für die Anzeige */
function discord_poll_view(array $p): array {
    $counts = [];
    foreach ((array)($p['results']['answer_counts'] ?? []) as $c) $counts[(int)$c['id']] = (int)$c['count'];
    return ['question' => (string)($p['question']['text'] ?? ''), 'multi' => !empty($p['allow_multiselect']), 'expiry' => !empty($p['expiry']) ? strtotime($p['expiry']) : null,
            'final' => !empty($p['results']['is_finalized']), 'answers' => array_map(function ($a) use ($counts) {
                return ['text' => trim(($a['poll_media']['emoji']['name'] ?? '') . ' ' . ($a['poll_media']['text'] ?? '')), 'count' => $counts[(int)($a['answer_id'] ?? 0)] ?? 0];
            }, (array)($p['answers'] ?? []))];
}

function discord_send(string $channel, array $payload): array {
    if (isset($payload['embeds'])) $payload['embeds'] = array_map(function ($e) { return array_filter($e, function ($v) { return $v !== null; }); }, $payload['embeds']);
    if (($payload['content'] ?? '') === '') unset($payload['content']);
    return discord_api('POST', '/channels/' . discord_valid_id($channel) . '/messages', [], $payload);
}

/** An den Standard-Kanal (Einstellungen) senden – z. B. „Instanz teilen“ aus VRC Nexus */
function discord_send_default(array $payload): array {
    $ch = setting('discord_channel', '');
    if ($ch === '') throw new DiscordError('In der Discord-Zentrale ist noch kein Standard-Kanal gewählt (Einstellungen).');
    return discord_send($ch, $payload);
}

// ---------- Geplante Nachrichten ----------

function discord_scheduled(): array {
    $l = json_decode(setting('discord_scheduled', '[]'), true);
    return is_array($l) ? $l : [];
}

function discord_scheduled_save(array $list): void {
    usort($list, function ($a, $b) { return $a['at'] <=> $b['at']; });
    set_setting('discord_scheduled', json_encode(array_values($list), JSON_UNESCAPED_UNICODE));
}

/** Fällige Nachrichten senden; liefert [gesendet, Fehler[]] */
function discord_run_scheduled(): array {
    if (!discord_configured()) return [0, []];
    $list = discord_scheduled();
    $sent = 0;
    $errors = [];
    $now = time();
    foreach ($list as $k => &$s) {
        if ($s['at'] > $now || !empty($s['paused'])) continue;
        try {
            discord_send($s['channel'], $s['payload']);
            $sent++;
            $s['last_sent'] = $now;
            $s['last_error'] = '';
        } catch (Throwable $e) {
            $s['last_error'] = $e->getMessage();
            $errors[] = $e->getMessage();
        }
        $step = ['daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => '+1 month'][$s['repeat']] ?? null;
        if ($step) { while ($s['at'] <= $now) $s['at'] = strtotime($step, $s['at']); }
        elseif ($s['last_error'] !== '') $s['paused'] = true;   // einmalige Nachricht nicht verlieren – pausiert mit Fehlerhinweis
        else unset($list[$k]);
    }
    unset($s);
    discord_scheduled_save($list);
    return [$sent, $errors];
}
