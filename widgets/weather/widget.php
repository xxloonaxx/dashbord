<?php
// Wetter über Open-Meteo (kostenlos, ohne API-Schlüssel). Daten werden 30 Minuten zwischengespeichert.
const WG_WEATHER_CODES = [0 => ['☀️', 'Klar'], 1 => ['🌤️', 'Überwiegend klar'], 2 => ['⛅', 'Teils bewölkt'], 3 => ['☁️', 'Bedeckt'], 45 => ['🌫️', 'Nebel'], 48 => ['🌫️', 'Raureifnebel'],
    51 => ['🌦️', 'Leichter Niesel'], 53 => ['🌦️', 'Niesel'], 55 => ['🌧️', 'Starker Niesel'], 56 => ['🌧️', 'Gefrierender Niesel'], 57 => ['🌧️', 'Gefrierender Niesel'],
    61 => ['🌦️', 'Leichter Regen'], 63 => ['🌧️', 'Regen'], 65 => ['🌧️', 'Starker Regen'], 66 => ['🌧️', 'Gefrierender Regen'], 67 => ['🌧️', 'Gefrierender Regen'],
    71 => ['🌨️', 'Leichter Schneefall'], 73 => ['🌨️', 'Schneefall'], 75 => ['❄️', 'Starker Schneefall'], 77 => ['🌨️', 'Schneegriesel'],
    80 => ['🌦️', 'Regenschauer'], 81 => ['🌧️', 'Regenschauer'], 82 => ['⛈️', 'Heftige Schauer'], 85 => ['🌨️', 'Schneeschauer'], 86 => ['🌨️', 'Schneeschauer'],
    95 => ['⛈️', 'Gewitter'], 96 => ['⛈️', 'Gewitter mit Hagel'], 99 => ['⛈️', 'Gewitter mit Hagel']];
return [
    'name' => 'Wetter',
    'icon' => '🌦️',
    'description' => 'Aktuelles Wetter und Vorhersage für die nächsten Tage (Open-Meteo, ohne Anmeldung).',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'refresh' => 1800,
    'settings' => [
        'city' => ['label' => 'Ort', 'type' => 'text', 'default' => 'Berlin'],
        'days' => ['label' => 'Vorhersage-Tage', 'type' => 'number', 'default' => 4, 'min' => 0, 'max' => 7],
        'unit' => ['label' => 'Einheit', 'type' => 'select', 'default' => 'celsius', 'options' => ['celsius' => '°C', 'fahrenheit' => '°F']],
    ],
    'render' => function (WidgetCtx $w): string {
        $city = trim((string)$w->cfg['city']) ?: 'Berlin';
        $geo = $w->cached_json('https://geocoding-api.open-meteo.com/v1/search?' . http_build_query(['name' => $city, 'count' => 1, 'language' => 'de', 'format' => 'json']), 7 * 86400);
        $place = $geo['results'][0] ?? null;
        if (!$place) return '<div class="wg-empty">Ort „' . e($city) . '“ nicht gefunden – im ⚙ einen anderen Namen eintragen.</div>';
        $days = (int)$w->cfg['days'];
        $d = $w->cached_json('https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude' => $place['latitude'], 'longitude' => $place['longitude'], 'timezone' => 'auto', 'forecast_days' => max(1, $days),
            'current' => 'temperature_2m,apparent_temperature,relative_humidity_2m,weather_code,wind_speed_10m,is_day',
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunrise,sunset',
            'temperature_unit' => $w->cfg['unit'] === 'fahrenheit' ? 'fahrenheit' : 'celsius', 'wind_speed_unit' => 'kmh']), 1800);
        $c = $d['current'] ?? [];
        $u = $w->cfg['unit'] === 'fahrenheit' ? '°F' : '°C';
        [$ic, $txt] = WG_WEATHER_CODES[(int)($c['weather_code'] ?? 0)] ?? ['🌡️', ''];
        if (empty($c['is_day']) && in_array((int)($c['weather_code'] ?? 0), [0, 1], true)) $ic = '🌙';
        $h = '<div class="wt-now"><span class="wt-icon">' . $ic . '</span><div><b>' . round((float)($c['temperature_2m'] ?? 0)) . $u . '</b><span>' . e($txt) . '</span></div></div>'
           . '<div class="wt-place">📍 ' . e($place['name'] . (isset($place['admin1']) ? ', ' . $place['admin1'] : '')) . '</div>'
           . '<div class="wt-meta">gefühlt ' . round((float)($c['apparent_temperature'] ?? 0)) . $u . ' · 💧 ' . (int)($c['relative_humidity_2m'] ?? 0) . ' % · 💨 ' . round((float)($c['wind_speed_10m'] ?? 0)) . ' km/h';
        if (!empty($d['daily']['sunrise'][0])) $h .= ' · 🌅 ' . substr($d['daily']['sunrise'][0], 11, 5) . ' · 🌇 ' . substr($d['daily']['sunset'][0], 11, 5);
        $h .= '</div>';
        if ($days > 0) {
            $h .= '<div class="wt-days">';
            $wd = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
            foreach ((array)($d['daily']['time'] ?? []) as $i => $day) {
                [$di] = WG_WEATHER_CODES[(int)($d['daily']['weather_code'][$i] ?? 0)] ?? ['🌡️'];
                $h .= '<div><span>' . ($i === 0 ? 'Heute' : $wd[(int)date('w', strtotime($day))]) . '</span><i>' . $di . '</i><b>' . round((float)$d['daily']['temperature_2m_max'][$i]) . '°</b><small>' . round((float)$d['daily']['temperature_2m_min'][$i]) . '°'
                    . ((int)($d['daily']['precipitation_probability_max'][$i] ?? 0) >= 30 ? ' · ☔' . (int)$d['daily']['precipitation_probability_max'][$i] . '%' : '') . '</small></div>';
            }
            $h .= '</div>';
        }
        return $h;
    },
];
