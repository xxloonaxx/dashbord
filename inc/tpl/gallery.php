<?php
/** Vorlage „Galerie“: Bilder per Adresse oder Upload, mit Titel, Bildunterschrift und Großansicht */

const TPL_UPLOAD_DIR = APP_ROOT . '/uploads';
const TPL_UPLOAD_MAX = 10 * 1024 * 1024;

function tpl_gallery_start(): array {
    return ['images' => []];
}

/** Upload-Ordner anlegen und gegen Ausführen von Skripten absichern */
function tpl_upload_dir(): string {
    $dir = TPL_UPLOAD_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new InvalidArgumentException('Der Ordner „uploads“ konnte nicht angelegt werden – bitte Schreibrechte prüfen.');
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "# Nur Bilder ausliefern, nie Skripte ausführen\nOptions -Indexes\n"
            . "<FilesMatch \"(?i)\\.(php\\d?|phtml|phar|pl|py|cgi|sh|html?|svg|js)$\">\n    Require all denied\n</FilesMatch>\n"
            . "<IfModule mod_headers.c>\n    Header set X-Content-Type-Options nosniff\n</IfModule>\n");
    }
    if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
    return $dir;
}

/** Ein hochgeladenes Bild prüfen und speichern -> relativer Pfad */
function tpl_upload_image(array $f): string {
    $errors = [UPLOAD_ERR_INI_SIZE => 'zu groß für den Server (Limit: ' . ini_get('upload_max_filesize') . ')', UPLOAD_ERR_FORM_SIZE => 'zu groß',
               UPLOAD_ERR_PARTIAL => 'nur teilweise übertragen', UPLOAD_ERR_NO_TMP_DIR => 'kein Temp-Ordner auf dem Server', UPLOAD_ERR_CANT_WRITE => 'konnte nicht gespeichert werden'];
    $name = mb_substr(basename((string)$f['name']), 0, 80);
    if ($f['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('„' . $name . '“: ' . ($errors[$f['error']] ?? 'Upload fehlgeschlagen'));
    if ($f['size'] > TPL_UPLOAD_MAX) throw new InvalidArgumentException('„' . $name . '“ ist größer als 10 MB.');
    $info = @getimagesize($f['tmp_name']);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext) throw new InvalidArgumentException('„' . $name . '“ ist kein Bild (erlaubt: JPG, PNG, GIF, WebP).');
    $file = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!is_uploaded_file($f['tmp_name']) || !move_uploaded_file($f['tmp_name'], tpl_upload_dir() . '/' . $file)) throw new InvalidArgumentException('„' . $name . '“ konnte nicht gespeichert werden.');
    @chmod(TPL_UPLOAD_DIR . '/' . $file, 0644);
    return 'uploads/' . $file;
}

function tpl_gallery_delete_file(string $src): void {
    if (preg_match('#^uploads/(\d{8}_[0-9a-f]{16}\.(jpg|png|gif|webp))$#', $src, $m)) @unlink(TPL_UPLOAD_DIR . '/' . $m[1]);
}

function tpl_gallery_post(array &$c, string $act, array $page): array {
    $imgs = &$c['images'];
    $id = (string)($_POST['id'] ?? '');
    switch ($act) {
        case 'img_add':
            $added = 0;
            $errors = [];
            foreach (preg_split('/\r?\n/', trim((string)($_POST['urls'] ?? ''))) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (!preg_match('#^https?://#i', $line)) $line = 'https://' . $line;
                if (fmt_url($line, true) === '' || !filter_var($line, FILTER_VALIDATE_URL)) { $errors[] = '„' . mb_substr($line, 0, 60) . '“ ist keine gültige Adresse'; continue; }
                $imgs[] = ['id' => tpl_id('p'), 'src' => $line, 'title' => '', 'caption' => '', 'added' => time()];
                $added++;
            }
            $files = $_FILES['files'] ?? null;
            if ($files && is_array($files['name'])) {
                foreach ($files['name'] as $k => $n) {
                    if ($files['error'][$k] === UPLOAD_ERR_NO_FILE) continue;
                    try {
                        $src = tpl_upload_image(['name' => $n, 'tmp_name' => $files['tmp_name'][$k], 'error' => $files['error'][$k], 'size' => $files['size'][$k]]);
                        $imgs[] = ['id' => tpl_id('p'), 'src' => $src, 'title' => mb_substr(pathinfo($n, PATHINFO_FILENAME), 0, 120), 'caption' => '', 'added' => time()];
                        $added++;
                    } catch (InvalidArgumentException $e) { $errors[] = $e->getMessage(); }
                }
            }
            if (!$added && !$errors) throw new InvalidArgumentException('Bitte Bilder auswählen oder Adressen eintragen.');
            if (!$added) throw new InvalidArgumentException(implode(' · ', $errors));
            return ['msg' => $added . ' Bild(er) hinzugefügt.' . ($errors ? ' Nicht geklappt: ' . implode(' · ', $errors) : ''), 'audit' => $added . ' Bild(er) zur Galerie hinzugefügt'];
        case 'img_save':
            $i = tpl_need($imgs, $id);
            $imgs[$i]['title'] = tpl_in('title', 120);
            $imgs[$i]['caption'] = tpl_body('caption', 2000);
            $src = trim((string)($_POST['src'] ?? ''));
            if ($src !== '' && $src !== $imgs[$i]['src'] && strpos($imgs[$i]['src'], 'uploads/') !== 0) {
                if (!preg_match('#^https?://#i', $src)) $src = 'https://' . $src;
                if (fmt_url($src, true) === '' || !filter_var($src, FILTER_VALIDATE_URL)) throw new InvalidArgumentException('Bitte eine gültige Bildadresse angeben.');
                $imgs[$i]['src'] = $src;
            }
            return ['msg' => 'Bild gespeichert.', 'anchor' => 'p-' . $id];
        case 'img_delete':
            $i = tpl_need($imgs, $id);
            tpl_gallery_delete_file($imgs[$i]['src']);
            array_splice($imgs, $i, 1);
            return ['msg' => 'Bild entfernt.'];
        case 'img_move':
            tpl_move($imgs, tpl_need($imgs, $id), (int)($_POST['dir'] ?? 1));
            return ['anchor' => 'p-' . $id];
    }
    throw new InvalidArgumentException('Unbekannte Aktion.');
}

function tpl_gallery_live(array $c): string {
    return count($c['images'] ?? []) . ' Bilder';
}

function tpl_gallery_render(array $c, array $page, bool $can_edit): void {
    $imgs = $c['images'] ?? [];
    $extra = $can_edit ? '<button type="button" class="btn btn-gold" data-open="imgAddModal">＋ Bilder hinzufügen</button>' : '';
    tpl_filter_bar('#gal > .gal-item', 'Bilder durchsuchen …', [], $extra); ?>
    <div class="gal" id="gal">
        <?php foreach ($imgs as $k => $im): ?>
            <figure class="gal-item" id="p-<?php echo e($im['id']); ?>" data-search="<?php echo tpl_search_text($im['title'], $im['caption']); ?>">
                <button type="button" class="gal-open" data-lightbox="<?php echo $k; ?>" aria-label="Großansicht: <?php echo e($im['title'] ?: 'Bild'); ?>">
                    <img src="<?php echo e($im['src']); ?>" alt="<?php echo e($im['title']); ?>" loading="lazy" referrerpolicy="no-referrer">
                </button>
                <?php if ($im['title'] !== '' || $im['caption'] !== ''): ?>
                    <figcaption><?php echo e($im['title']); ?></figcaption>
                <?php endif; ?>
                <div class="gal-cap" hidden><?php echo format_text($im['caption']); ?></div>
                <?php if ($can_edit): ?>
                    <div class="acts gal-acts">
                        <?php if ($k > 0) echo tpl_button('img_move', ['id' => $im['id'], 'dir' => -1], '◀', 'Nach vorne'); ?>
                        <button type="button" class="ic" title="Bearbeiten" aria-label="Bearbeiten" data-open="imgModal" data-fill="<?php echo tpl_fill(['id' => $im['id'], 'title' => $im['title'], 'caption' => $im['caption'], 'src' => $im['src']]); ?>">✎</button>
                        <?php echo tpl_button('img_delete', ['id' => $im['id']], '✕', 'Entfernen', 'ic danger', 'Bild „' . ($im['title'] ?: 'ohne Titel') . '“ entfernen?' . (strpos($im['src'], 'uploads/') === 0 ? ' Die hochgeladene Datei wird gelöscht.' : ''));
                              if ($k < count($imgs) - 1) echo tpl_button('img_move', ['id' => $im['id'], 'dir' => 1], '▶', 'Nach hinten'); ?>
                    </div>
                <?php endif; ?>
            </figure>
        <?php endforeach; ?>
    </div>
    <div class="empty-msg" data-empty-for="#gal > .gal-item" <?php echo $imgs ? 'hidden' : ''; ?>><?php echo $imgs ? 'Kein Bild passt zur Suche.' : 'Noch keine Bilder.' . ($can_edit ? ' Oben auf „＋ Bilder hinzufügen“ klicken.' : ''); ?></div>

    <div class="lb" id="lb" hidden role="dialog" aria-modal="true" aria-label="Großansicht">
        <button type="button" class="lb-x" data-lb="close" aria-label="Schließen">&times;</button>
        <button type="button" class="lb-nav prev" data-lb="prev" aria-label="Vorheriges Bild">‹</button>
        <figure><img alt=""><figcaption><b></b><div class="fmt"></div><small></small></figcaption></figure>
        <button type="button" class="lb-nav next" data-lb="next" aria-label="Nächstes Bild">›</button>
    </div>
    <script>
    (function () {
        const lb = document.getElementById('lb'), items = () => [...document.querySelectorAll('#gal > .gal-item:not([hidden])')];
        let cur = 0;
        const show = i => {
            const list = items();
            if (!list.length) return;
            cur = (i + list.length) % list.length;
            const it = list[cur], img = it.querySelector('img');
            lb.querySelector('img').src = img.src;
            lb.querySelector('img').alt = img.alt;
            lb.querySelector('b').textContent = img.alt;
            lb.querySelector('.fmt').innerHTML = it.querySelector('.gal-cap').innerHTML;
            lb.querySelector('small').textContent = (cur + 1) + ' / ' + list.length;
            lb.hidden = false;
            document.body.style.overflow = 'hidden';
        };
        const close = () => { lb.hidden = true; document.body.style.overflow = ''; };
        document.getElementById('gal').addEventListener('click', e => { const b = e.target.closest('.gal-open'); if (b) show(items().indexOf(b.closest('.gal-item'))); });
        lb.addEventListener('click', e => {
            const a = (e.target.closest('[data-lb]') || {}).dataset?.lb;
            if (a === 'close' || e.target === lb) close();
            if (a === 'prev') show(cur - 1);
            if (a === 'next') show(cur + 1);
        });
        document.addEventListener('keydown', e => {
            if (lb.hidden) return;
            if (e.key === 'Escape') close();
            if (e.key === 'ArrowLeft') show(cur - 1);
            if (e.key === 'ArrowRight') show(cur + 1);
        });
        let x0 = null;
        lb.addEventListener('touchstart', e => { x0 = e.touches[0].clientX; }, { passive: true });
        lb.addEventListener('touchend', e => { if (x0 === null) return; const dx = e.changedTouches[0].clientX - x0; if (Math.abs(dx) > 50) show(cur + (dx < 0 ? 1 : -1)); x0 = null; });
    })();
    </script>

    <?php if (!$can_edit) return;
    tpl_modal_open('imgAddModal', 'Bilder hinzufügen', 'img_add', true, '620px'); ?>
        <label class="field">Bilder hochladen (JPG, PNG, GIF, WebP – max. 10 MB pro Bild)</label>
        <input type="file" name="files[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
        <label class="field" style="margin-top: 14px;">… und/oder Bildadressen (eine pro Zeile)</label>
        <textarea name="urls" rows="4" placeholder="https://example.org/bild.jpg"></textarea>
        <p class="muted small">Hochgeladene Bilder landen im Ordner <code>uploads/</code> deines Webspace und sind im Backup enthalten.</p>
    <?php tpl_modal_close('Hinzufügen');
    tpl_modal_open('imgModal', 'Bild bearbeiten', 'img_save', false, '620px'); ?>
        <input type="hidden" name="id" value="">
        <label class="field">Titel</label>
        <input type="text" name="title" maxlength="120">
        <label class="field">Bildunterschrift (wird in der Großansicht gezeigt)</label>
        <textarea name="caption" rows="4" data-format="text"></textarea>
        <label class="field">Bildadresse (nur bei verlinkten Bildern änderbar)</label>
        <input type="text" name="src" maxlength="2000">
    <?php tpl_modal_close();
}
