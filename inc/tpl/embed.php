<?php
/** Vorlage „Einbettungen“: YouTube, Twitch, Spotify, Karten oder andere Webseiten direkt auf der Seite */

function tpl_embed_start(): array {
    return ['embeds' => []];
}

/** Adresse -> [iframe-src, Art (video|audio|page)] – bekannte Dienste werden auf ihren Einbettungs-Player umgestellt */
function tpl_embed_src(string $url): array {
    $host = strtolower(preg_replace('/^www\./', '', (string)parse_url($url, PHP_URL_HOST)));
    $parent = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    if ($id = fmt_youtube_id($url)) return ['https://www.youtube-nocookie.com/embed/' . $id, 'video'];
    if (preg_match('/[?&]list=([\w-]+)/', $url, $m) && strpos($host, 'youtube') !== false) return ['https://www.youtube-nocookie.com/embed/videoseries?list=' . $m[1], 'video'];
    if ($host === 'vimeo.com' && preg_match('#/(\d+)#', $url, $m)) return ['https://player.vimeo.com/video/' . $m[1], 'video'];
    if (in_array($host, ['twitch.tv', 'm.twitch.tv'], true)) {
        if (preg_match('#/videos/(\d+)#', $url, $m)) return ['https://player.twitch.tv/?video=' . $m[1] . '&parent=' . rawurlencode($parent) . '&autoplay=false', 'video'];
        if (preg_match('#twitch\.tv/(\w+)#', $url, $m)) return ['https://player.twitch.tv/?channel=' . $m[1] . '&parent=' . rawurlencode($parent) . '&autoplay=false', 'video'];
    }
    if ($host === 'open.spotify.com' && preg_match('#/(track|album|playlist|episode|show|artist)/(\w+)#', $url, $m)) return ['https://open.spotify.com/embed/' . $m[1] . '/' . $m[2], $m[1] === 'track' || $m[1] === 'episode' ? 'audio' : 'audio-lg'];
    if ($host === 'soundcloud.com') return ['https://w.soundcloud.com/player/?url=' . rawurlencode($url), 'audio-lg'];
    return [$url, 'page'];
}

function tpl_embed_post(array &$c, string $act, array $page): array {
    $list = &$c['embeds'];
    $id = (string)($_POST['id'] ?? '');
    switch ($act) {
        case 'emb_save':
            $url = trim((string)($_POST['url'] ?? ''));
            if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
            if (!filter_var($url, FILTER_VALIDATE_URL) || fmt_url($url) === '' || !preg_match('#^https?://#i', $url)) throw new InvalidArgumentException('Bitte eine gültige Adresse (https://…) angeben.');
            $data = ['title' => tpl_in('title', 120), 'url' => mb_substr($url, 0, 2000), 'height' => max(150, min(1500, (int)($_POST['height'] ?? 450))), 'note' => tpl_body('note', 4000)];
            if ($id === '') {
                $list[] = $data + ['id' => tpl_id('e')];
                return ['msg' => 'Einbettung hinzugefügt.', 'anchor' => 'e-' . end($list)['id'], 'audit' => 'Einbettung hinzugefügt: ' . parse_url($url, PHP_URL_HOST)];
            }
            $i = tpl_need($list, $id);
            $list[$i] = $data + $list[$i];
            return ['msg' => 'Gespeichert.', 'anchor' => 'e-' . $id];
        case 'emb_delete':
            array_splice($list, tpl_need($list, $id), 1);
            return ['msg' => 'Entfernt.'];
        case 'emb_move':
            tpl_move($list, tpl_need($list, $id), (int)($_POST['dir'] ?? 1));
            return ['anchor' => 'e-' . $id];
    }
    throw new InvalidArgumentException('Unbekannte Aktion.');
}

function tpl_embed_live(array $c): string {
    return count($c['embeds'] ?? []) . ' Einbettungen';
}

function tpl_embed_render(array $c, array $page, bool $can_edit): void {
    $list = $c['embeds'] ?? [];
    if ($can_edit): ?>
        <div class="tb"><span class="muted small" style="flex: 1;">YouTube, Twitch, Vimeo, Spotify &amp; SoundCloud werden automatisch erkannt. Manche Webseiten verbieten das Einbetten – dann hilft nur der Link „↗ Öffnen“.</span>
            <button type="button" class="btn btn-gold" data-open="embModal" data-title="Neue Einbettung" data-fill="<?php echo tpl_fill(['id' => '', 'height' => 450]); ?>">＋ Einbettung</button></div>
    <?php endif; ?>
    <?php foreach ($list as $k => $em): [$src, $kind] = tpl_embed_src($em['url']); ?>
        <section class="panel emb" id="e-<?php echo e($em['id']); ?>">
            <div class="panel-title">
                <span><?php echo e($em['title'] !== '' ? $em['title'] : parse_url($em['url'], PHP_URL_HOST)); ?></span>
                <span class="acts always">
                    <a class="ic" href="<?php echo e($em['url']); ?>" target="_blank" rel="noopener noreferrer" title="Im neuen Tab öffnen">↗</a>
                    <?php if ($can_edit): ?>
                        <button type="button" class="ic" title="Bearbeiten" aria-label="Bearbeiten" data-open="embModal" data-title="Einbettung bearbeiten" data-fill="<?php echo tpl_fill(['id' => $em['id'], 'title' => $em['title'], 'url' => $em['url'], 'height' => $em['height'], 'note' => $em['note']]); ?>">✎</button>
                        <?php if ($k > 0) echo tpl_button('emb_move', ['id' => $em['id'], 'dir' => -1], '↑', 'Nach oben');
                              if ($k < count($list) - 1) echo tpl_button('emb_move', ['id' => $em['id'], 'dir' => 1], '↓', 'Nach unten');
                              echo tpl_button('emb_delete', ['id' => $em['id']], '✕', 'Entfernen', 'ic danger', 'Einbettung entfernen?'); ?>
                    <?php endif; ?>
                </span>
            </div>
            <?php if (trim($em['note']) !== ''): ?><div class="fmt emb-note"><?php echo format_text($em['note']); ?></div><?php endif; ?>
            <div class="emb-frame emb-<?php echo e($kind); ?>" <?php echo $kind === 'page' ? 'style="height: ' . (int)$em['height'] . 'px;"' : ''; ?>>
                <iframe src="<?php echo e($src); ?>" title="<?php echo e($em['title'] ?: 'Eingebettete Seite'); ?>" loading="lazy" referrerpolicy="strict-origin-when-cross-origin"
                        sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-forms allow-presentation"
                        allow="fullscreen; picture-in-picture; encrypted-media; autoplay; clipboard-write" allowfullscreen></iframe>
            </div>
        </section>
    <?php endforeach; ?>
    <?php if (!$list): ?><div class="empty-msg">Noch nichts eingebettet.</div><?php endif; ?>
    <?php if (!$can_edit) return;
    tpl_modal_open('embModal', 'Neue Einbettung', 'emb_save', false, '620px'); ?>
        <input type="hidden" name="id" value="">
        <label class="field">Adresse *</label>
        <input type="text" name="url" required inputmode="url" placeholder="https://www.youtube.com/watch?v=… oder https://twitch.tv/…">
        <div class="pm-row">
            <div style="flex: 1;"><label class="field">Titel</label><input type="text" name="title" maxlength="120"></div>
            <div style="width: 150px;"><label class="field">Höhe (Webseiten)</label><input type="number" name="height" min="150" max="1500" step="10"></div>
        </div>
        <label class="field">Notiz</label>
        <textarea name="note" rows="3" data-format="text"></textarea>
    <?php tpl_modal_close();
}
