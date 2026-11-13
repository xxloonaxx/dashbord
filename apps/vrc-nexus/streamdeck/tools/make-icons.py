#!/usr/bin/env python3
"""Erzeugt die Symbole des Stream-Deck-Plugins (einfache Vektorformen, keine fremden Grafiken).
Aufruf:  python3 streamdeck/tools/make-icons.py
"""
import math
import os
from PIL import Image, ImageDraw

OUT = os.path.join(os.path.dirname(__file__), '..', 'de.ghostnode.vrcnexus.sdPlugin', 'imgs')
CYAN, GOLD, BG, WHITE, GREEN, RED = (56, 213, 230), (243, 194, 98), (8, 13, 20), (226, 232, 240), (80, 250, 123), (255, 85, 85)


def canvas(n, key):
    im = Image.new('RGBA', (n, n), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    if key:  # Tastenhintergrund: dunkel mit Rahmen und abgeschnittener Ecke
        c = n * 0.12
        d.polygon([(0, 0), (n - c, 0), (n, c), (n, n), (0, n)], fill=BG + (255,))
        d.line([(n * 0.06, n * 0.94), (n * 0.94, n * 0.94)], fill=CYAN + (90,), width=max(1, n // 48))
    return im, d


def draw(name, d, n, on=False):
    s = n / 100.0
    P = lambda *pts: [(x * s, y * s) for x, y in pts]
    w = max(2, int(7 * s))
    col = GOLD if on else CYAN
    if name == 'status':
        d.ellipse(P((22, 22), (78, 78)), outline=col, width=w)
        d.ellipse(P((36, 36), (64, 64)), fill=GREEN if on else col)
    elif name == 'chatbox':
        d.rounded_rectangle(P((14, 20), (86, 66)), radius=10 * s, outline=col, width=w)
        d.polygon(P((30, 64), (30, 84), (48, 66)), fill=col)
        for x in (34, 50, 66):
            d.ellipse(P((x - 4, 39), (x + 4, 47)), fill=col)
    elif name == 'param':
        d.rounded_rectangle(P((14, 34), (86, 66)), radius=16 * s, outline=col, width=w, fill=(col + (70,)) if on else None)
        cx = 70 if on else 30
        d.ellipse(P((cx - 13, 37), (cx + 13, 63)), fill=GOLD if on else WHITE)
    elif name == 'input':
        d.polygon(P((50, 14), (80, 46), (62, 46), (62, 70), (38, 70), (38, 46), (20, 46)), outline=col, width=w)
        d.line(P((24, 84), (76, 84)), fill=col, width=w)
    elif name == 'rotator':
        d.arc(P((18, 18), (82, 82)), 200, 500, fill=col, width=w)
        d.polygon(P((70, 10), (88, 30), (64, 34)), fill=col)
        d.line(P((50, 32), (50, 52), (64, 60)), fill=GOLD if on else WHITE, width=w)
    elif name == 'friends':
        for cx, r in ((36, 13), (64, 13)):
            d.ellipse(P((cx - r, 22), (cx + r, 22 + 2 * r)), outline=col, width=w)
        d.arc(P((12, 54), (60, 100)), 180, 360, fill=col, width=w)
        d.arc(P((40, 54), (88, 100)), 180, 360, fill=col, width=w)
    elif name == 'players':
        for cx, cy, r in ((50, 26, 12), (24, 40, 9), (76, 40, 9)):
            d.ellipse(P((cx - r, cy - r), (cx + r, cy + r)), fill=col)
        d.pieslice(P((28, 44), (72, 96)), 180, 360, fill=col)
        d.pieslice(P((8, 54), (40, 86)), 180, 360, fill=col + (150,))
        d.pieslice(P((60, 54), (92, 86)), 180, 360, fill=col + (150,))
    elif name == 'launch':
        d.ellipse(P((14, 14), (86, 86)), outline=col, width=w)
        d.polygon(P((40, 30), (72, 50), (40, 70)), fill=GOLD)
    elif name == 'rejoin':
        d.arc(P((18, 18), (82, 82)), 30, 330, fill=col, width=w)
        d.polygon(P((70, 22), (90, 34), (72, 46)), fill=col)
        d.ellipse(P((42, 42), (58, 58)), fill=GOLD)
    elif name == 'open':
        d.ellipse(P((14, 14), (86, 86)), outline=col, width=w)
        d.polygon(P((50, 22), (60, 50), (50, 78), (40, 50)), outline=col, width=max(1, w // 2))
        d.polygon(P((50, 22), (60, 50), (40, 50)), fill=GOLD)
    elif name == 'plugin':
        d.polygon(P((50, 8), (88, 30), (88, 70), (50, 92), (12, 70), (12, 30)), outline=CYAN, width=w)
        d.line(P((34, 66), (34, 34), (66, 66), (66, 34)), fill=GOLD, width=w + 2)


def save(name, size, key, on=False, suffix=''):
    im, d = canvas(size, key)
    draw(name, d, size, on)
    im.save(os.path.join(OUT, name + suffix + '.png'))


os.makedirs(OUT, exist_ok=True)
for name in ('status', 'chatbox', 'param', 'input', 'rotator', 'friends', 'players', 'launch', 'rejoin', 'open'):
    save(name, 20, False, suffix='_icon')
    save(name, 40, False, suffix='_icon@2x')
    save(name, 72, True, suffix='_key')
    save(name, 144, True, suffix='_key@2x')
    if name in ('param', 'rotator', 'status'):
        save(name, 72, True, True, '_key_on')
        save(name, 144, True, True, '_key_on@2x')
save('plugin', 28, False, suffix='_category')
save('plugin', 56, False, suffix='_category@2x')
save('plugin', 256, True)
save('plugin', 512, True, suffix='@2x')
print('Symbole erzeugt in', os.path.abspath(OUT))
