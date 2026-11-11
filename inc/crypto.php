<?php
/**
 * Verschlüsselung von Geheimnissen (Sitzungs-Cookies, Bot-Tokens) mit AES-256-GCM.
 * Der Schlüssel liegt je Zweck in data/.<name>_key_<zufall>.php – data/ ist per .htaccess gesperrt,
 * die Datei beginnt zusätzlich mit einem PHP-Abbruch.
 */
require_once __DIR__ . '/auth.php';

function crypto_key(string $name): string {
    static $cache = [];
    if (isset($cache[$name])) return $cache[$name];
    $dir = dirname(__DIR__) . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = (glob($dir . '/.' . $name . '_key_*.php') ?: [])[0] ?? null;
    if ($file) {
        $k = base64_decode(secure_load($file, [])['key'] ?? '');
        if (strlen($k) === 32) return $cache[$name] = $k;
    }
    $key = random_bytes(32);
    secure_save($dir . '/.' . $name . '_key_' . bin2hex(random_bytes(6)) . '.php', ['key' => base64_encode($key)]);
    return $cache[$name] = $key;
}

function crypto_encrypt(string $name, $data): string {
    $plain = json_encode($data);
    if (!function_exists('openssl_encrypt')) return 'plain:' . base64_encode($plain);
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', crypto_key($name), OPENSSL_RAW_DATA, $iv, $tag);
    return 'gcm:' . base64_encode($iv . $tag . $ct);
}

function crypto_decrypt(string $name, string $s) {
    if (strpos($s, 'plain:') === 0) return json_decode(base64_decode(substr($s, 6)), true);
    if (strpos($s, 'gcm:') !== 0 || !function_exists('openssl_decrypt')) return null;
    $raw = base64_decode(substr($s, 4));
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', crypto_key($name), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? null : json_decode($plain, true);
}
