#!/usr/bin/env python3
"""check-pdf.py — quality gate for the printed agreement PDFs (python + PyMuPDF).

    python tools/agreements/check-pdf.py                 # every out/pdf/*.pdf
    python tools/agreements/check-pdf.py --png           # also render out/png/<name>-p<N>.png at 80 dpi
    python tools/agreements/check-pdf.py a.pdf b.pdf

Per PDF:
  pages      4..6 (target 4-5)
  min_font   smallest text size in the PDF, must be >= 7.5 pt
  outside    text crossing the 5 mm unprintable edge, or body text (> 8 pt) inside the page
             margins, which only the running header / footer (7.6-7.8 pt) may use — must be 0
  overlaps   text runs drawn on top of each other (clipped / colliding text) — must be 0
  sigpage    both party blocks, the witnesses, the guarantors' table (its heading ends
             "11.3)" in every language) and the website bar all on the last page
  fill       how full the last page is (information only)
Exit code 1 when any PDF fails.
"""
import glob
import os
import sys

try:
    import pymupdf as fitz
except ImportError:  # older PyMuPDF
    import fitz

HERE = os.path.dirname(os.path.abspath(__file__))
PDF_DIR = os.path.join(HERE, 'out', 'pdf')
PNG_DIR = os.path.join(HERE, 'out', 'png')
MM = 72 / 25.4
# Must match @page in build.js: margin 14mm 13mm 16mm 13mm.
TOP, RIGHT, BOTTOM, LEFT = 14 * MM, 13 * MM, 16 * MM, 13 * MM
EDGE = 5 * MM          # printers cannot print closer to the paper edge than this
MARGIN_TEXT_MAX = 8.0  # running header / footer size; anything bigger in a margin is body text that leaked
MIN_PT = 7.5
MIN_PAGES, MAX_PAGES = 4, 6
SIG_MARKS = ('11.3)', 'www.shreehariglobal.in')


def spans_of(page):
    out = []
    for block in page.get_text('dict')['blocks']:
        for line in block.get('lines', []):
            for sp in line['spans']:
                txt = sp['text'].strip()
                if txt:
                    out.append((sp['bbox'], sp['size'], txt))
    return out


def check(path, png=False):
    doc = fitz.open(path)
    name = os.path.basename(path)
    pages = doc.page_count
    min_size, min_where = 99.0, ''
    outside, overlaps = [], []
    last_fill = 0.0
    for pno in range(pages):
        page = doc[pno]
        W, H = page.rect.width, page.rect.height
        spans = spans_of(page)
        for (x0, y0, x1, y1), size, txt in spans:
            if size < min_size:
                min_size, min_where = size, 'p%d "%s"' % (pno + 1, txt[:24])
            where = 'p%d (%.1f,%.1f mm) %.1fpt "%s"' % (pno + 1, x0 / MM, y0 / MM, size, txt[:30])
            if x0 < EDGE or y0 < EDGE or x1 > W - EDGE or y1 > H - EDGE:
                outside.append('edge ' + where)
            elif size > MARGIN_TEXT_MAX and (y0 < TOP - 0.5 * MM or y1 > H - BOTTOM + 0.5 * MM
                                             or x0 < LEFT - 0.5 * MM or x1 > W - RIGHT + 0.5 * MM):
                outside.append('margin ' + where)
        boxes = sorted(((b, t) for b, s, t in spans), key=lambda s: (s[0][1], s[0][0]))
        for i in range(len(boxes)):
            (a0, a1, a2, a3), at = boxes[i]
            for j in range(i + 1, min(i + 60, len(boxes))):
                (b0, b1, b2, b3), bt = boxes[j]
                if b1 > a3:
                    break
                oy = min(a3, b3) - max(a1, b1)
                ox = min(a2, b2) - max(a0, b0)
                if oy > 0.35 * min(a3 - a1, b3 - b1) and ox > 1.5 * MM:
                    overlaps.append('p%d "%s" x "%s"' % (pno + 1, at[:20], bt[:20]))
        if pno == pages - 1:
            body = [b for b, s, t in spans if s > MARGIN_TEXT_MAX]
            if body:
                last_fill = (max(b[3] for b in body) - TOP) / (H - TOP - BOTTOM)
            text = page.get_text()
            sig_ok = all(m in text for m in SIG_MARKS)
        if png:
            os.makedirs(PNG_DIR, exist_ok=True)
            page.get_pixmap(dpi=80).save(os.path.join(PNG_DIR, '%s-p%d.png' % (name[:-4], pno + 1)))
    # the signature marks must not also appear on an earlier page (i.e. the block was not split)
    for pno in range(pages - 1):
        t = doc[pno].get_text()
        if SIG_MARKS[0] in t:
            sig_ok = False
    ok = MIN_PAGES <= pages <= MAX_PAGES and min_size >= MIN_PT and not outside and not overlaps and sig_ok
    print('%-32s pages=%d  min_font=%.2fpt  outside=%d  overlaps=%d  sigpage=%s  last_page_fill=%d%%  %s' % (
        name, pages, min_size, len(outside), len(overlaps), 'ok' if sig_ok else 'SPLIT',
        round(last_fill * 100), 'OK' if ok else 'FAIL'))
    if min_size < MIN_PT:
        print('    smallest: ' + min_where)
    for o in outside[:6]:
        print('    outside: ' + o)
    for o in overlaps[:6]:
        print('    overlap: ' + o)
    return ok


def main():
    try:
        sys.stdout.reconfigure(encoding='utf-8')  # Devanagari snippets on a Windows console
    except Exception:
        pass
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    png = '--png' in sys.argv
    files = args or sorted(glob.glob(os.path.join(PDF_DIR, '*.pdf')))
    if not files:
        print('no PDFs found in ' + PDF_DIR)
        return 1
    bad = sum(0 if check(f, png) else 1 for f in files)
    print('%d PDF(s), %d failing' % (len(files), bad))
    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main())
