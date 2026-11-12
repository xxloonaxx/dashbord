<?php
// Discord-Server auf einen Blick (über den Bot der Discord-Zentrale, 5 Min. zwischengespeichert)
require_once dirname(__DIR__, 2) . '/inc/modules/discord.php';
return [
    'name' => 'Discord-Server',
    'icon' => '🤖',
    'description' => 'Mitglieder, gerade online, Boosts und das nächste Server-Event deines Discord-Servers (über den Bot).',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'permission' => 'discord.manage',
    'refresh' => 600,
    'render' => function (WidgetCtx $w): string {
        if (!discord_configured()) return '<div class="wg-empty">Kein Bot eingerichtet. <a href="discord.php">Discord-Zentrale →</a></div>';
        $gid = setting('discord_guild', '');
        if ($gid === '') return '<div class="wg-empty">Kein Server gewählt.</div>';
        $s = $w->state();
        if (($s['t'] ?? 0) < time() - 300) {
            $g = discord_api('GET', '/guilds/' . discord_valid_id($gid), ['with_counts' => 'true']);
            $ev = [];
            try { $ev = discord_api('GET', '/guilds/' . $gid . '/scheduled-events', ['with_user_count' => 'true']); } catch (Throwable $e) {}
            usort($ev, function ($a, $b) { return strcmp($a['scheduled_start_time'], $b['scheduled_start_time']); });
            $s = ['t' => time(), 'name' => $g['name'], 'icon' => discord_guild_icon($g), 'members' => (int)($g['approximate_member_count'] ?? 0), 'online' => (int)($g['approximate_presence_count'] ?? 0),
                  'boosts' => (int)($g['premium_subscription_count'] ?? 0), 'tier' => (int)($g['premium_tier'] ?? 0),
                  'event' => $ev ? ['name' => $ev[0]['name'], 'ts' => strtotime($ev[0]['scheduled_start_time']), 'users' => (int)($ev[0]['user_count'] ?? 0)] : null];
            $w->save_state($s);
        }
        $h = '<a href="discord.php" class="dw"><div class="dw-top">' . ($s['icon'] ? '<img src="' . e($s['icon']) . '" alt="" referrerpolicy="no-referrer">' : '<span class="dw-noic">🤖</span>') . '<b>' . e($s['name']) . '</b></div>'
           . '<div class="dw-kpi"><div><b>' . number_format($s['members'], 0, ',', '.') . '</b><small>Mitglieder</small></div><div><b class="on">' . number_format($s['online'], 0, ',', '.') . '</b><small>online</small></div><div><b>' . $s['boosts'] . '</b><small>Boosts · Stufe ' . $s['tier'] . '</small></div></div>';
        if ($s['event']) $h .= '<div class="dw-ev">📅 <b>' . e($s['event']['name']) . '</b><small>' . e(date('d.m. H:i', $s['event']['ts'])) . ' · ⭐ ' . $s['event']['users'] . '</small></div>';
        return $h . '</a>';
    },
];
