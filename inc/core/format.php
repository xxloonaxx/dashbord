<?php
/**
 * Einheitliche, sichere Textformatierung für die ganze Seite.
 *
 * Erlaubt gleichzeitig:
 *   - Markdown  (**fett**, *kursiv*, # Überschrift, - Listen, > Zitat, Tabellen, ```Code```, [Text](URL) …)
 *   - BBCode    ([b], [i], [u], [s], [color=red], [size=5], [url=…], [img], [quote=Name], [spoiler], [list][*] …)
 *   - HTML      (eine erlaubte Auswahl wie <b>, <span style="color:…">, <table>, <details> …)
 *
 * Alles wird gefiltert: <script>, on…-Handler, javascript:-Links, gefährliches CSS usw. kommen nie durch,
 * nicht geschlossene Tags werden automatisch geschlossen, damit das Seitenlayout nicht kaputtgeht.
 *
 *   format_text($s)   – mehrzeilige Texte (Notizen, Kalender-Notizen, Tagebuch, Karten …)
 *   format_inline($s) – kurze Texte (Kachel-Beschreibungen, Checklisten-Punkte); ['links' => false] innerhalb von Links
 *   format_plain($s)  – nur der reine Text (Suche, Vorschauen)
 */
require_once __DIR__ . '/common.php';

const FMT_BLOCK = ['p', 'div', 'blockquote', 'pre', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'thead', 'tbody', 'tfoot',
                   'tr', 'th', 'td', 'caption', 'details', 'summary', 'hr', 'dl', 'dt', 'dd', 'figure', 'figcaption', 'center', 'section', 'aside'];
const FMT_INLINE = ['b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'ins', 'mark', 'small', 'big', 'sup', 'sub', 'br', 'span', 'a', 'img',
                    'code', 'kbd', 'samp', 'var', 'abbr', 'q', 'cite', 'font', 'time', 'wbr'];
const FMT_VOID = ['br', 'hr', 'img', 'wbr'];
/** Tags, die auf ein anderes Tag abgebildet werden */
const FMT_ALIAS = ['strike' => 's', 'center' => 'div', 'font' => 'span', 'big' => 'span', 'section' => 'div', 'aside' => 'div'];
/** Erlaubte CSS-Eigenschaften in style="…" (keine Positionierung, damit nichts über die Seite gelegt werden kann) */
const FMT_CSS = ['color', 'background-color', 'background', 'font-size', 'font-weight', 'font-style', 'font-family', 'font-variant',
                 'text-align', 'text-decoration', 'text-transform', 'text-shadow', 'letter-spacing', 'line-height', 'border', 'border-left',
                 'border-right', 'border-top', 'border-bottom', 'border-color', 'border-radius', 'padding', 'padding-left', 'padding-right',
                 'padding-top', 'padding-bottom', 'width', 'max-width', 'height', 'vertical-align', 'white-space', 'display', 'float', 'opacity'];
const FMT_EMOJI = [':)' => '🙂', ':smile:' => '😄', ':grin:' => '😁', ':joy:' => '😂', ':wink:' => '😉', ':heart:' => '❤️', ':star:' => '⭐',
                   ':fire:' => '🔥', ':thumbsup:' => '👍', ':+1:' => '👍', ':thumbsdown:' => '👎', ':check:' => '✅', ':x:' => '❌',
                   ':warning:' => '⚠️', ':info:' => 'ℹ️', ':tada:' => '🎉', ':paw:' => '🐾', ':fox:' => '🦊', ':wolf:' => '🐺', ':cat:' => '🐱',
                   ':dog:' => '🐶', ':dragon:' => '🐉', ':rocket:' => '🚀', ':eyes:' => '👀', ':think:' => '🤔', ':cry:' => '😢',
                   ':angry:' => '😠', ':cool:' => '😎', ':sleep:' => '😴', ':party:' => '🥳', ':vr:' => '🥽', ':money:' => '💶',
                   ':calendar:' => '📅', ':pin:' => '📌', ':link:' => '🔗', ':lock:' => '🔒', ':idea:' => '💡', ':music:' => '🎵'];

final class FmtCtx {
    public $store = [];
    public $block = [];
    public $links = true;
    public $inline = false;
    public $external = false;   // Fremdinhalte (VRChat, Kalender-Abos): nur absolute https-Adressen
}

function fmt_hold(FmtCtx $c, string $html, bool $block = false): string {
    $c->store[] = $html;
    $c->block[] = $block;
    return "\x01" . (count($c->store) - 1) . "\x02";
}

function fmt_restore(FmtCtx $c, string $s): string {
    for ($n = 0; $n < 12 && strpos($s, "\x01") !== false; $n++) {
        $s = preg_replace_callback('/\x01(\d+)\x02/', function ($m) use ($c) { return $c->store[(int)$m[1]] ?? ''; }, $s);
    }
    return $s;
}

function fmt_prepare(string $s): string {
    if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    $s = str_replace(["\r\n", "\r"], "\n", mb_substr($s, 0, 200000));
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
}

/** Text maskieren; vorhandene HTML-Entities (&amp;, &hearts; …) bleiben erhalten */
function fmt_escape(string $s): string {
    $s = preg_replace('/&(?!(?:[a-zA-Z][a-zA-Z0-9]{1,31}|#\d{1,7}|#x[0-9a-fA-F]{1,6});)/', '&amp;', $s);
    return str_replace(['<', '>'], ['&lt;', '&gt;'], $s);
}

/** Sichere Adresse oder '' (javascript:, data: usw. werden verworfen) */
function fmt_url(string $u, bool $image = false): string {
    $u = trim(html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $u = preg_replace('/[\x00-\x1F\x7F]+/', '', str_replace(' ', '%20', $u));
    if ($u === '') return '';
    if (preg_match('/^www\./i', $u)) $u = 'https://' . $u;
    if (strpos($u, '//') === 0) $u = 'https:' . $u;
    if (preg_match('#^https?://[^/?\#\s]+#i', $u)) return $u;
    if (!$image && preg_match('/^(mailto|tel):[^\s]+$/i', $u)) return $u;
    if ($image && preg_match('#^data:image/(png|gif|jpe?g|webp);base64,[a-z0-9+/=]+$#i', $u)) return $u;
    if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $u)) return '';
    return $u;   // relative Adresse innerhalb der Seite
}

function fmt_is_external(string $url): bool {
    return (bool)preg_match('#^(https?:|mailto:|tel:)#i', $url);
}

/** style="…" filtern: nur erlaubte Eigenschaften, keine url(), expression() usw. */
function fmt_style(string $css): string {
    $out = [];
    foreach (explode(';', html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8')) as $decl) {
        if (strpos($decl, ':') === false) continue;
        [$prop, $val] = array_map('trim', explode(':', $decl, 2));
        $prop = strtolower($prop);
        $val = trim(preg_replace('/!\s*important/i', '', $val));
        if (!in_array($prop, FMT_CSS, true) || $val === '' || mb_strlen($val) > 80) continue;
        if (preg_match('/url\s*\(|expression|javascript|behavior|@import|\\\\|[<>{}]|\/\*/i', $val)) continue;
        if (!preg_match('/^[#\p{L}\p{N}\s.,%()\-+\'"]+$/u', $val)) continue;
        if ($prop === 'display' && !in_array(strtolower($val), ['inline', 'block', 'inline-block', 'flex', 'grid'], true)) continue;
        $out[] = $prop . ': ' . $val;
    }
    return implode('; ', $out);
}

function fmt_youtube_id(string $s): string {
    $s = trim(html_entity_decode($s, ENT_QUOTES, 'UTF-8'));
    if (preg_match('/^[\w-]{11}$/', $s)) return $s;
    if (preg_match('#(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([\w-]{11})#i', $s, $m)) return $m[1];
    return '';
}

/** Link-Tag bauen (als Platzhalter) – bei unsicherer Adresse nur der Text */
function fmt_link(FmtCtx $c, string $url, string $label_html): string {
    $href = fmt_url($url);
    if ($c->external && !fmt_is_external($href)) $href = '';
    if ($href === '') return $label_html;
    if (!$c->links) return fmt_hold($c, '<span class="fmt-link">' . $label_html . '</span>');
    $ext = fmt_is_external($href) ? ' target="_blank" rel="noopener noreferrer nofollow ugc"' : '';
    return fmt_hold($c, '<a href="' . e($href) . '"' . $ext . '>' . $label_html . '</a>');
}

/** Ein Stück Rohtext vollständig inline formatieren (für Link-Texte usw.) */
function fmt_fragment(FmtCtx $c, string $raw): string {
    $saved = $c->links;
    $c->links = false;
    $html = fmt_md_inline($c, fmt_escape(fmt_html($c, fmt_bbcode($c, $raw))));
    $c->links = $saved;
    return $html;
}

// ---------- 1. Code und Escapes zuerst sichern (darin wird nichts formatiert) ----------

function fmt_code(FmtCtx $c, string $s): string {
    $s = preg_replace_callback('/^[ \t]*```[ \t]*([\w+#.\-]*)[^\n]*\n(.*?)\n[ \t]*```[ \t]*$/ms', function ($m) use ($c) {
        if ($c->inline) return fmt_hold($c, '<code>' . e($m[2]) . '</code>');
        $lang = $m[1] !== '' ? ' data-lang="' . e($m[1]) . '"' : '';
        return fmt_hold($c, '<pre class="fmt-code"' . $lang . '><code>' . e($m[2]) . '</code></pre>', true);
    }, $s);
    $s = preg_replace_callback('/\[code(?:=([\w+#.\-]+))?\](.*?)\[\/code\]/is', function ($m) use ($c) {
        $code = trim($m[2], "\n");
        if ($c->inline || strpos($code, "\n") === false) return fmt_hold($c, '<code>' . e($code) . '</code>');
        $lang = $m[1] !== '' ? ' data-lang="' . e($m[1]) . '"' : '';
        return fmt_hold($c, '<pre class="fmt-code"' . $lang . '><code>' . e($code) . '</code></pre>', true);
    }, $s);
    $s = preg_replace_callback('/\[noparse\](.*?)\[\/noparse\]/is', function ($m) use ($c) {
        return fmt_hold($c, nl2br(e($m[1]), false));
    }, $s);
    $s = preg_replace_callback('/(`+)(?!`)(.+?)(?<!`)\1(?!`)/', function ($m) use ($c) {
        return fmt_hold($c, '<code>' . e(trim($m[2])) . '</code>');
    }, $s);
    // \* \_ \[ … = Zeichen wörtlich
    return preg_replace_callback('/\\\\([\\\\`*_{}\[\]()#+\-.!|~=<>^:])/', function ($m) use ($c) {
        return fmt_hold($c, e($m[1]));
    }, $s);
}

// ---------- 2. BBCode -> HTML (wird danach wie normales HTML gefiltert) ----------

function fmt_bbcode(FmtCtx $c, string $s): string {
    if (strpos($s, '[') === false) return $s;
    $param = '(?:=(["\']?)([^\]\n]{1,80}?)\1)';

    $s = preg_replace_callback('/\[url\]\s*(.+?)\s*\[\/url\]/is', function ($m) use ($c) {
        return fmt_link($c, $m[1], e($m[1]));
    }, $s);
    $s = preg_replace_callback('/\[url=(["\']?)([^\]\n]{1,2000}?)\1\](.*?)\[\/url\]/is', function ($m) use ($c) {
        return fmt_link($c, $m[2], fmt_fragment($c, $m[3] !== '' ? $m[3] : $m[2]));
    }, $s);
    $s = preg_replace_callback('/\[email\]\s*([^\s\[\]]+@[^\s\[\]]+)\s*\[\/email\]/i', function ($m) use ($c) {
        return fmt_link($c, 'mailto:' . $m[1], e($m[1]));
    }, $s);
    $s = preg_replace_callback('/\[email=([^\]\s]+@[^\]\s]+)\](.*?)\[\/email\]/is', function ($m) use ($c) {
        return fmt_link($c, 'mailto:' . $m[1], fmt_fragment($c, $m[2]));
    }, $s);
    $s = preg_replace_callback('/\[img(?:=(\d{1,4})(?:x(\d{1,4}))?|\s+width=(\d{1,4}))?\](.+?)\[\/img\]/is', function ($m) use ($c) {
        $src = fmt_url($m[4], true);
        if ($c->external && !preg_match('#^https://#i', $src)) $src = '';
        if ($src === '') return fmt_hold($c, e($m[0]));
        $w = (int)($m[1] ?: ($m[3] ?? 0));
        $h = (int)($m[2] ?? 0);
        return fmt_hold($c, '<img src="' . e($src) . '" alt=""' . ($w ? ' width="' . min($w, 2000) . '"' : '') . ($h ? ' height="' . min($h, 2000) . '"' : '')
                            . ' loading="lazy" referrerpolicy="no-referrer">');
    }, $s);
    $s = preg_replace_callback('/\[(?:youtube|video)\]\s*(.+?)\s*\[\/(?:youtube|video)\]/is', function ($m) use ($c) {
        $id = fmt_youtube_id($m[1]);
        if ($id === '') return fmt_hold($c, e($m[0]));
        if ($c->inline || !$c->links) return fmt_link($c, 'https://www.youtube.com/watch?v=' . $id, '▶ YouTube-Video');
        return fmt_hold($c, '<div class="fmt-embed"><iframe src="https://www.youtube-nocookie.com/embed/' . $id . '" title="YouTube-Video" loading="lazy" '
                            . 'allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen></iframe></div>', true);
    }, $s);

    // [list] [*] … [/list] – von innen nach außen, damit Verschachtelung klappt
    for ($k = 0; $k < 6; $k++) {
        $new = preg_replace_callback('/\[list(?:=([1aAiI]))?\]((?:(?!\[list(?:=[1aAiI])?\]).)*?)\[\/list\]/is', function ($m) {
            $tag = ($m[1] ?? '') !== '' ? 'ol' : 'ul';
            $type = $tag === 'ol' && $m[1] !== '1' ? ' type="' . $m[1] . '"' : '';
            $items = array_filter(array_map('trim', preg_split('/\[\*\]/', $m[2])), 'strlen');
            return "<$tag$type>" . implode('', array_map(function ($x) { return '<li>' . $x . '</li>'; }, $items)) . "</$tag>";
        }, $s);
        if ($new === $s) break;
        $s = $new;
    }

    $s = preg_replace_callback('/\[(color|colour|bgcolor)=(["\']?)([#\w(),.\s%]{1,30})\2\]/i', function ($m) {
        $css = fmt_style((strtolower($m[1]) === 'bgcolor' ? 'background-color' : 'color') . ': ' . $m[3]);
        return $css !== '' ? '<span style="' . e($css) . '">' : $m[0];
    }, $s);
    $s = preg_replace_callback('/\[size=(["\']?)([\w.%]{1,8})\1\]/i', function ($m) {
        $v = strtolower($m[2]);
        $map = ['1' => '0.7em', '2' => '0.85em', '3' => '1em', '4' => '1.2em', '5' => '1.5em', '6' => '2em', '7' => '2.6em'];
        if (isset($map[$v])) $size = $map[$v];
        elseif (preg_match('/^(\d{1,3})(px|pt)$/', $v, $x)) $size = max(8, min(60, (int)$x[1])) . $x[2];
        elseif (preg_match('/^(\d{2,3})%$/', $v, $x)) $size = max(50, min(300, (int)$x[1])) . '%';
        elseif (in_array($v, ['small', 'large', 'x-large', 'xx-large', 'smaller', 'larger'], true)) $size = $v;
        else return $m[0];
        return '<span style="font-size: ' . $size . '">';
    }, $s);
    $s = preg_replace_callback('/\[font=(["\']?)([\p{L}\p{N}\s\-,]{1,40})\1\]/iu', function ($m) {
        return '<span style="' . e(fmt_style('font-family: ' . $m[2])) . '">';
    }, $s);
    $s = preg_replace('/\[\/(color|colour|bgcolor|size|font)\]/i', '</span>', $s);
    $s = preg_replace_callback('/\[(center|left|right|justify)\]/i', function ($m) { return '<div style="text-align: ' . strtolower($m[1]) . '">'; }, $s);
    $s = preg_replace_callback('/\[align=(["\']?)(center|left|right|justify)\1\]/i', function ($m) { return '<div style="text-align: ' . strtolower($m[2]) . '">'; }, $s);
    $s = preg_replace('/\[\/(center|left|right|justify|align|indent)\]/i', '</div>', $s);
    $s = preg_replace('/\[indent\]/i', '<div style="padding-left: 2em">', $s);
    $s = preg_replace_callback('/\[quote' . $param . '?\]/i', function ($m) {
        return '<blockquote class="fmt-quote">' . (($m[2] ?? '') !== '' ? '<cite>' . e($m[2]) . ' schrieb:</cite>' : '');
    }, $s);
    $s = preg_replace('/\[\/quote\]/i', '</blockquote>', $s);
    $s = preg_replace_callback('/\[(?:spoiler|details)' . $param . '?\]/i', function ($m) use ($c) {
        if ($c->inline) return '<span class="fmt-spoiler" tabindex="0">';
        return '<details class="fmt-details"><summary>' . e(($m[2] ?? '') !== '' ? $m[2] : 'Spoiler') . '</summary>';
    }, $s);
    $s = preg_replace('/\[\/(spoiler|details)\]/i', $c->inline ? '</span>' : '</details>', $s);
    $s = preg_replace_callback('/\[abbr' . $param . '\]/i', function ($m) { return '<abbr title="' . e($m[2]) . '">'; }, $s);
    $s = preg_replace('/\[\/abbr\]/i', '</abbr>', $s);
    $s = preg_replace('/\[(hr|br)\s*\/?\]/i', '<$1>', $s);
    return preg_replace_callback('/\[(\/?)(b|i|u|s|strike|del|ins|em|strong|sup|sub|small|mark|highlight|kbd|h[1-6]|table|thead|tbody|tr|td|th|ul|ol|li|p)\]/i', function ($m) {
        $t = strtolower($m[2]);
        $t = ['strike' => 's', 'highlight' => 'mark'][$t] ?? $t;
        return '<' . $m[1] . $t . '>';
    }, $s);
}

// ---------- 3. HTML filtern: erlaubte Tags sauber neu aufbauen, alles andere bleibt sichtbarer Text ----------

function fmt_attrs(string $tag, string $raw, FmtCtx $c): ?array {
    $a = [];
    if (preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/', $raw, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            $v = $m[2] !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''));
            $a[strtolower($m[1])] = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    $out = [];
    $style = isset($a['style']) ? fmt_style($a['style']) : '';
    $add_style = function (string $css) use (&$style) { $css = fmt_style($css); if ($css !== '') $style = $css . ($style !== '' ? '; ' . $style : ''); };
    if (isset($a['align']) && in_array(strtolower($a['align']), ['left', 'right', 'center', 'justify'], true)) $add_style('text-align: ' . strtolower($a['align']));
    switch ($tag) {
        case 'a':
            $href = fmt_url($a['href'] ?? '');
            if ($c->external && !fmt_is_external($href)) $href = '';
            if ($href !== '' && $c->links) {
                $out['href'] = $href;
                if (fmt_is_external($href)) { $out['target'] = '_blank'; $out['rel'] = 'noopener noreferrer nofollow ugc'; }
            }
            break;
        case 'img':
            $src = fmt_url($a['src'] ?? '', true);
            if ($c->external && !preg_match('#^https://#i', $src)) $src = '';
            if ($src === '') return null;
            $out['src'] = $src;
            $out['alt'] = mb_substr($a['alt'] ?? '', 0, 200);
            foreach (['width', 'height'] as $k) if (isset($a[$k]) && ctype_digit($a[$k])) $out[$k] = (string)min(2000, (int)$a[$k]);
            $out['loading'] = 'lazy';
            $out['referrerpolicy'] = 'no-referrer';
            break;
        case 'font':
            if (isset($a['color'])) $add_style('color: ' . $a['color']);
            if (isset($a['face'])) $add_style('font-family: ' . $a['face']);
            if (isset($a['size']) && preg_match('/^[1-7]$/', $a['size'])) $add_style('font-size: ' . ['', '0.7em', '0.85em', '1em', '1.2em', '1.5em', '2em', '2.6em'][(int)$a['size']]);
            break;
        case 'big':
            $add_style('font-size: 1.25em');
            break;
        case 'center':
            $add_style('text-align: center');
            break;
        case 'td': case 'th':
            foreach (['colspan', 'rowspan'] as $k) if (isset($a[$k]) && ctype_digit($a[$k])) $out[$k] = (string)max(1, min(50, (int)$a[$k]));
            break;
        case 'ol':
            if (isset($a['start']) && preg_match('/^-?\d{1,6}$/', $a['start'])) $out['start'] = $a['start'];
            if (isset($a['type']) && in_array($a['type'], ['1', 'a', 'A', 'i', 'I'], true)) $out['type'] = $a['type'];
            if (isset($a['reversed'])) $out['reversed'] = 'reversed';
            break;
        case 'li':
            if (isset($a['value']) && preg_match('/^\d{1,6}$/', $a['value'])) $out['value'] = $a['value'];
            break;
        case 'details':
            if (isset($a['open'])) $out['open'] = 'open';
            break;
        case 'time':
            if (isset($a['datetime']) && preg_match('/^[\d\-:T+Z ]{4,30}$/', $a['datetime'])) $out['datetime'] = $a['datetime'];
            break;
    }
    if (isset($a['title'])) $out['title'] = mb_substr($a['title'], 0, 200);
    if (isset($a['class'])) {
        $cls = array_filter(preg_split('/\s+/', $a['class']), function ($x) { return preg_match('/^fmt-[a-z0-9-]{1,30}$/', $x); });
        if ($tag === 'a' && !$c->links) $cls[] = 'fmt-link';
        if ($cls) $out['class'] = implode(' ', $cls);
    } elseif ($tag === 'a' && !$c->links) {
        $out['class'] = 'fmt-link';
    }
    if ($style !== '') $out['style'] = $style;
    return $out;
}

function fmt_html(FmtCtx $c, string $s): string {
    if (strpos($s, '<') === false) return $s;
    $s = preg_replace('/<!--.*?(?:-->|$)/s', '', $s);
    $s = preg_replace('/<(script|style|iframe|object|embed|noscript|template|textarea|select|form|svg|math)\b[^>]*>.*?(?:<\/\1\s*>|$)/is', '', $s);
    $parts = preg_split('/(<\/?[a-zA-Z][a-zA-Z0-9]*(?:\s+[^<>]*?)?\s*\/?>)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    $stack = [];
    foreach ($parts as $i => $p) {
        if ($i % 2 === 0) {
            // Text in <a>/<pre> nicht weiter formatieren (sonst doppelte Links bzw. Umbrüche)
            if ($p !== '') $out .= (in_array('a', $stack, true) || in_array('pre', $stack, true)) ? fmt_hold($c, fmt_escape($p)) : $p;
            continue;
        }
        preg_match('/^<(\/?)([a-zA-Z][a-zA-Z0-9]*)(.*?)\/?>$/s', $p, $m);
        $close = $m[1] === '/';
        $tag = strtolower($m[2]);
        $is_inline = in_array($tag, FMT_INLINE, true);
        $is_block = in_array($tag, FMT_BLOCK, true);
        if (!$is_inline && !$is_block) { $out .= $p; continue; }          // unbekanntes Tag: als Text anzeigen
        if ($is_block && $c->inline) { $out .= $close ? '' : ($tag === 'br' ? ' ' : ''); continue; }   // Kurztexte: Block-Tags weglassen
        $name = FMT_ALIAS[$tag] ?? $tag;
        if ($tag === 'a' && !$c->links) $name = 'span';
        if ($close) {
            if (in_array($name, FMT_VOID, true)) continue;
            $pos = null;
            for ($j = count($stack) - 1; $j >= 0; $j--) if ($stack[$j] === $name) { $pos = $j; break; }
            if ($pos === null) continue;                                    // schließendes Tag ohne Gegenstück: weg
            $html = '';
            $block = false;
            while (count($stack) > $pos) { $t = array_pop($stack); $html .= '</' . $t . '>'; $block = $block || in_array($t, FMT_BLOCK, true) || $t === 'div'; }
            $out .= fmt_hold($c, $html, $block);
            continue;
        }
        if ($tag === 'a' && in_array('a', $stack, true)) continue;          // keine Links in Links
        $attrs = fmt_attrs($tag, $m[3], $c);
        if ($attrs === null) continue;
        $html = '<' . $name;
        foreach ($attrs as $k => $v) $html .= ' ' . $k . '="' . e($v) . '"';
        $html .= '>';
        $out .= fmt_hold($c, $html, $is_block);
        if (!in_array($name, FMT_VOID, true)) $stack[] = $name;
    }
    if ($stack) {
        $html = '';
        $block = false;
        while ($stack) { $t = array_pop($stack); $html .= '</' . $t . '>'; $block = $block || in_array($t, FMT_BLOCK, true) || $t === 'div'; }
        $out .= fmt_hold($c, $html, $block);
    }
    return $out;
}

// ---------- 4. Markdown ----------

function fmt_md_inline(FmtCtx $c, string $s): string {
    // Bilder ![Alt](URL "Titel")
    $s = preg_replace_callback('/!\[([^\]\n]*)\]\(\s*([^\s)\x01\x02]+)(?:\s+"([^"\n]*)")?\s*\)/', function ($m) use ($c) {
        $src = fmt_url($m[2], true);
        if ($c->external && !preg_match('#^https://#i', $src)) $src = '';
        if ($src === '') return $m[0];
        $alt = str_replace('"', '&quot;', preg_replace('/\x01\d+\x02/', '', $m[1]));
        $title = isset($m[3]) && $m[3] !== '' ? ' title="' . str_replace('"', '&quot;', $m[3]) . '"' : '';
        return fmt_hold($c, '<img src="' . e($src) . '" alt="' . $alt . '"' . $title . ' loading="lazy" referrerpolicy="no-referrer">');
    }, $s);
    // Links [Text](URL)
    $s = preg_replace_callback('/\[((?:[^\[\]\n]|\[[^\[\]\n]*\])+)\]\(\s*([^\s)\x01\x02]+)(?:\s+"([^"\n]*)")?\s*\)/', function ($m) use ($c) {
        if (fmt_url($m[2]) === '') return $m[0];
        $saved = $c->links;
        $c->links = false;
        $label = fmt_md_inline($c, $m[1]);
        $c->links = $saved;
        return fmt_link($c, $m[2], $label);
    }, $s);
    // <https://…> und nackte Adressen
    $s = preg_replace_callback('/&lt;((?:https?:\/\/|mailto:)[^\s\x01\x02]+?)&gt;/i', function ($m) use ($c) { return fmt_link($c, $m[1], $m[1]); }, $s);
    $s = preg_replace_callback('/(?<![\w\/@=:"\x02])((?:https?:\/\/|www\.)(?:(?!&[lg]t;|&quot;)[^\s<\x01\x02])+)/i', function ($m) use ($c) {
        $url = $m[1];
        $tail = '';
        while (preg_match('/[.,;:!?\'"]$|\)$/', $url) && (substr($url, -1) !== ')' || substr_count($url, '(') < substr_count($url, ')'))) {
            $tail = substr($url, -1) . $tail;
            $url = substr($url, 0, -1);
        }
        return fmt_link($c, $url, $url) . $tail;
    }, $s);
    // ||Spoiler||
    $s = preg_replace_callback('/\|\|(?=\S)(.+?)(?<=\S)\|\|/s', function ($m) use ($c) {
        return fmt_hold($c, '<span class="fmt-spoiler" tabindex="0" title="Spoiler – anklicken zum Anzeigen">') . $m[1] . fmt_hold($c, '</span>');
    }, $s);
    $s = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<![\w])__(?=\S)(.+?)(?<=\S)__(?![\w])/su', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<![*\w])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![*\w])/su', '<em>$1</em>', $s);
    $s = preg_replace('/(?<![\w])_(?=[^\s_])(.+?)(?<=[^\s_])_(?![\w])/su', '<em>$1</em>', $s);
    $s = preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '<del>$1</del>', $s);
    $s = preg_replace('/==(?=\S)(.+?)(?<=\S)==/s', '<mark>$1</mark>', $s);
    // :emoji: und :)
    return preg_replace_callback('/(?<![\w:])(:[a-z0-9_+\-]{1,20}:|:\))(?![\w])/', function ($m) { return FMT_EMOJI[$m[1]] ?? $m[1]; }, $s);
}

function fmt_has_block(FmtCtx $c, string $line): bool {
    if (!preg_match_all('/\x01(\d+)\x02/', $line, $m)) return false;
    foreach ($m[1] as $i) if (!empty($c->block[(int)$i])) return true;
    return false;
}

function fmt_md_list(FmtCtx $c, array $lines, int $i): array {
    $items = [];
    $n = count($lines);
    while ($i < $n) {
        $line = $lines[$i];
        if (preg_match('/^(\s*)([-*+]|(\d{1,9})[.)])\s+(.*)$/', $line, $m)) {
            $text = $m[4];
            $task = null;
            if (preg_match('/^\[([ xX])\]\s+(.*)$/', $text, $t)) { $task = $t[1] !== ' '; $text = $t[2]; }
            $items[] = ['indent' => strlen(str_replace("\t", '    ', $m[1])), 'type' => ($m[3] ?? '') !== '' ? 'ol' : 'ul',
                        'start' => ($m[3] ?? '') !== '' ? (int)$m[3] : 1, 'text' => $text, 'task' => $task];
        } elseif ($items && trim($line) !== '' && preg_match('/^\s+\S/', $line)) {
            $items[count($items) - 1]['text'] .= "\n" . trim($line);
        } else {
            break;
        }
        $i++;
    }
    $html = '';
    $stack = [];
    $open = function (array $it) use (&$html, &$stack) {
        $cls = $it['task'] !== null ? ' class="fmt-tasks"' : '';
        $start = $it['type'] === 'ol' && $it['start'] !== 1 ? ' start="' . $it['start'] . '"' : '';
        $html .= '<' . $it['type'] . $cls . $start . '>';
        $stack[] = [$it['indent'], $it['type']];
    };
    foreach ($items as $it) {
        if (!$stack || $it['indent'] > end($stack)[0]) {
            $open($it);
        } else {
            while (count($stack) > 1 && $it['indent'] < end($stack)[0]) $html .= '</li></' . array_pop($stack)[1] . '>';
            $html .= '</li>';
            if (end($stack)[1] !== $it['type']) { $html .= '</' . array_pop($stack)[1] . '>'; $open($it); }
        }
        $body = str_replace("\n", '<br>', fmt_md_inline($c, $it['text']));
        $html .= $it['task'] === null ? '<li>' . $body
                                      : '<li class="fmt-task' . ($it['task'] ? ' done' : '') . '"><span class="fmt-box">' . ($it['task'] ? '✓' : '') . '</span> ' . $body;
    }
    while ($stack) $html .= '</li></' . array_pop($stack)[1] . '>';
    return [$html . "\n", $i - 1];
}

function fmt_md_row(string $line): array {
    $line = trim($line);
    if ($line !== '' && $line[0] === '|') $line = substr($line, 1);
    if (substr($line, -1) === '|') $line = substr($line, 0, -1);
    return array_map('trim', explode('|', $line));
}

function fmt_md_blocks(FmtCtx $c, string $s, int $depth = 0): string {
    $lines = explode("\n", $s);
    $n = count($lines);
    $html = '';
    $para = [];
    $flush = function () use (&$para, &$html, $c) {
        if (!$para) return;
        $html .= '<p>' . str_replace("\n", "<br>\n", fmt_md_inline($c, implode("\n", $para))) . "</p>\n";
        $para = [];
    };
    for ($i = 0; $i < $n; $i++) {
        $line = rtrim($lines[$i]);
        $t = trim($line);
        if ($t === '') { $flush(); continue; }
        if (fmt_has_block($c, $line)) { $flush(); $html .= fmt_md_inline($c, $line) . "\n"; continue; }
        if (preg_match('/^(#{1,6})\s+(.+?)(?:\s+#+)?$/', $t, $m)) {
            $flush();
            $l = strlen($m[1]);
            $html .= "<h$l>" . fmt_md_inline($c, $m[2]) . "</h$l>\n";
            continue;
        }
        if (preg_match('/^([-*_])(\s*\1){2,}$/', $t)) { $flush(); $html .= "<hr>\n"; continue; }
        if (strpos($t, '&gt;') === 0) {
            $flush();
            $q = [];
            while ($i < $n && preg_match('/^\s*&gt;\s?(.*)$/', $lines[$i], $m)) { $q[] = $m[1]; $i++; }
            $i--;
            $html .= '<blockquote>' . ($depth < 4 ? fmt_md_blocks($c, implode("\n", $q), $depth + 1) : fmt_md_inline($c, implode('<br>', $q))) . "</blockquote>\n";
            continue;
        }
        if (strpos($t, '|') !== false && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)+\|?\s*$|^\s*\|\s*:?-+:?\s*\|\s*$/', $lines[$i + 1])) {
            $flush();
            $head = fmt_md_row($line);
            $align = array_map(function ($x) {
                $x = trim($x);
                $l = $x !== '' && $x[0] === ':'; $r = substr($x, -1) === ':';
                return $l && $r ? 'center' : ($r ? 'right' : ($l ? 'left' : ''));
            }, fmt_md_row($lines[$i + 1]));
            $cell = function (string $tag, string $txt, int $k) use ($align, $c) {
                $a = $align[$k] ?? '';
                return "<$tag" . ($a ? ' style="text-align: ' . $a . '"' : '') . '>' . fmt_md_inline($c, $txt) . "</$tag>";
            };
            $out = '<div class="fmt-table"><table><thead><tr>';
            foreach ($head as $k => $h) $out .= $cell('th', $h, $k);
            $out .= '</tr></thead><tbody>';
            $i += 2;
            while ($i < $n && trim($lines[$i]) !== '' && strpos($lines[$i], '|') !== false) {
                $out .= '<tr>';
                foreach (array_pad(fmt_md_row($lines[$i]), count($head), '') as $k => $v) if ($k < count($head)) $out .= $cell('td', $v, $k);
                $out .= '</tr>';
                $i++;
            }
            $i--;
            $html .= $out . "</tbody></table></div>\n";
            continue;
        }
        if (preg_match('/^\s*([-*+]|\d{1,9}[.)])\s+\S/', $line)) {
            $flush();
            [$out, $i] = fmt_md_list($c, $lines, $i);
            $html .= $out;
            continue;
        }
        $para[] = $line;
    }
    $flush();
    return $html;
}

// ---------- Öffentliche Funktionen ----------

function fmt_run(?string $s, array $opt, bool $inline): string {
    $s = fmt_prepare((string)$s);
    if (trim($s) === '') return '';
    $c = new FmtCtx();
    $c->links = $opt['links'] ?? true;
    $c->external = !empty($opt['external']);
    $c->inline = $inline;
    $s = fmt_code($c, $s);
    $s = fmt_bbcode($c, $s);
    $s = fmt_escape(fmt_html($c, $s));
    $s = $inline ? str_replace("\n", '<br>', fmt_md_inline($c, trim($s))) : fmt_md_blocks($c, $s);
    return trim(fmt_restore($c, $s));
}

/** Mehrzeiliger Text mit Absätzen, Listen, Tabellen … (Ausgabe in ein Element mit class="fmt" setzen) */
function format_text(?string $s, array $opt = []): string {
    return fmt_run($s, $opt, false);
}

/** Kurzer Text ohne Block-Elemente; ['links' => false], wenn er selbst in einem Link steht */
function format_inline(?string $s, array $opt = []): string {
    return fmt_run($s, $opt, true);
}

/** Reiner Text ohne jede Formatierung */
function format_plain(?string $s): string {
    $t = html_entity_decode(strip_tags(str_replace('<br>', ' ', format_inline($s, ['links' => false]))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $t));
}
