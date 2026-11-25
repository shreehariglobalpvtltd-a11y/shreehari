#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
tests/fx1-contrast-check.py - WCAG contrast check for assets/css/fx1-tokens.css

CLI only, Python standard library only, no network, writes nothing.

  python tests/fx1-contrast-check.py                 # checks assets/css/fx1-tokens.css
  python tests/fx1-contrast-check.py path/to/file.css
  python tests/fx1-contrast-check.py --quiet         # only failures + summary

What it does
  1. Reads every "pair:" line from the CSS comments, e.g.
         /* pair: --fx-text on --fx-bg */            text, needs 4.5:1
         /* pair: --fx-line-control on --fx-bg @3 */ large text / UI border, needs 3:1
  2. Builds the token table three times:
         light = :root { ... }
         dark  = light + :root[data-theme="dark"] { ... }
         auto  = light + the block inside @media (prefers-color-scheme: dark)
                 whose selector carries data-fx-theme="auto"
     var(--x) and var(--x, fallback) are resolved; a cycle or a missing token
     is an error.
  3. Prints each pair with its ratio in light and dark, and fails (exit 1) when
       - a pair is under its minimum in any theme,
       - a pair names a token that does not resolve to an opaque colour,
       - the "auto" dark block and the data-theme="dark" block disagree
         (they must be the same set so both doors give the same dark mode),
       - the file contains a selector other than the :root forms and .fx-num
         (the token file must not restyle existing pages).

Exit codes: 0 all pass, 1 a check failed, 2 usage / file problem.
"""
import os
import re
import sys

ALLOWED_SELECTOR = re.compile(
    r'^(:root(\[data-(theme|fx-theme|motion)="[a-z]+"\])*(:not\(\[data-theme="[a-z]+"\]\))?|\.fx-num)$'
)
PAIR = re.compile(r'pair:\s*(--[\w-]+)\s+on\s+(--[\w-]+)(?:\s+@\s*([\d.]+))?')
DECL = re.compile(r'(--[\w-]+)\s*:\s*([^;{}]+)')
VAR = re.compile(r'var\(\s*(--[\w-]+)\s*(?:,\s*([^()]*(?:\([^()]*\)[^()]*)*))?\)')


def blocks(css):
    """Yield (media, selector, body) for every rule, one level of @media deep."""
    i, n, depth, start, media, sel = 0, len(css), 0, 0, '', ''
    stack = []
    while i < n:
        c = css[i]
        if c == '{':
            head = css[start:i].strip()
            if head.startswith('@'):
                stack.append(('at', head))
            else:
                stack.append(('rule', head, i + 1))
            start = i + 1
        elif c == '}':
            if not stack:
                raise ValueError('unbalanced } at offset %d' % i)
            top = stack.pop()
            if top[0] == 'rule':
                media = ' '.join(s[1] for s in stack if s[0] == 'at')
                yield media, top[1], css[top[2]:i]
            start = i + 1
        i += 1
    if stack:
        raise ValueError('unbalanced { in file')


def parse_color(v):
    """'#rgb' / '#rrggbb' / 'rgb(r,g,b)' -> (r,g,b) 0..255, else None (alpha is refused)."""
    v = v.strip().lower()
    m = re.fullmatch(r'#([0-9a-f]{3})', v)
    if m:
        return tuple(int(ch * 2, 16) for ch in m.group(1))
    m = re.fullmatch(r'#([0-9a-f]{6})', v)
    if m:
        h = m.group(1)
        return tuple(int(h[k:k + 2], 16) for k in (0, 2, 4))
    m = re.fullmatch(r'rgb\(\s*(\d+)\s*[, ]\s*(\d+)\s*[, ]\s*(\d+)\s*\)', v)
    if m:
        return tuple(min(255, int(g)) for g in m.groups())
    return None


def resolve(name, table, seen=()):
    if name in seen:
        raise KeyError('cycle: ' + ' -> '.join(seen + (name,)))
    if name not in table:
        raise KeyError('token not defined: ' + name)
    val = table[name].strip()
    for _ in range(12):
        m = VAR.search(val)
        if not m:
            break
        try:
            rep = resolve(m.group(1), table, seen + (name,))
        except KeyError:
            if m.group(2) is None:
                raise
            rep = m.group(2).strip()
        val = val[:m.start()] + rep + val[m.end():]
    return val.strip()


def luminance(rgb):
    def lin(c):
        c = c / 255.0
        return c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4
    r, g, b = (lin(c) for c in rgb)
    return 0.2126 * r + 0.7152 * g + 0.0722 * b


def ratio(a, b):
    la, lb = luminance(a), luminance(b)
    hi, lo = (la, lb) if la >= lb else (lb, la)
    return (hi + 0.05) / (lo + 0.05)


def main(argv):
    quiet = '--quiet' in argv
    args = [a for a in argv[1:] if not a.startswith('--')]
    here = os.path.dirname(os.path.abspath(__file__))
    path = args[0] if args else os.path.join(here, '..', 'assets', 'css', 'fx1-tokens.css')
    try:
        with open(path, 'r', encoding='utf-8') as fh:
            raw = fh.read()
    except OSError as e:
        print('cannot read %s: %s' % (path, e))
        return 2

    comments = re.findall(r'/\*.*?\*/', raw, flags=re.S)
    pairs, seen_pairs = [], set()
    for c in comments:
        for m in PAIR.finditer(c):
            key = (m.group(1), m.group(2))
            if key in seen_pairs:
                continue
            seen_pairs.add(key)
            pairs.append((m.group(1), m.group(2), float(m.group(3)) if m.group(3) else 4.5))
    css = re.sub(r'/\*.*?\*/', '', raw, flags=re.S)

    light, dark, auto, problems = {}, {}, {}, []
    try:
        for media, selector, body in blocks(css):
            decls = dict((k, v.strip()) for k, v in DECL.findall(body))
            for part in (p.strip() for p in selector.split(',')):
                if not ALLOWED_SELECTOR.match(part):
                    problems.append('selector not allowed in a token file: %s' % part)
            # custom properties only (the .fx-num helper is the one exception)
            if selector.strip() != '.fx-num':
                for prop in re.findall(r'(?:^|;)\s*([a-zA-Z-]+)\s*:', body):
                    if not prop.startswith('--'):
                        problems.append('plain property "%s" in %s' % (prop, selector.strip()))
            if 'prefers-color-scheme' in media and 'dark' in media:
                if 'data-fx-theme="auto"' in selector:
                    auto.update(decls)
                else:
                    problems.append('dark media block without the data-fx-theme="auto" guard: %s' % selector)
            elif media:
                continue                      # reduced-motion etc.: no colours
            elif selector.strip() == ':root':
                light.update(decls)
            elif selector.strip() == ':root[data-theme="dark"]':
                dark.update(decls)
    except ValueError as e:
        print('CSS parse error: %s' % e)
        return 2

    for k in light:
        if not k.startswith('--fx-'):
            problems.append('token without the --fx- prefix: %s' % k)
    if dark != auto:
        only_d = sorted(set(dark) - set(auto))
        only_a = sorted(set(auto) - set(dark))
        diff = sorted(k for k in set(dark) & set(auto) if dark[k] != auto[k])
        problems.append('dark blocks disagree: only in data-theme=dark %s; only in auto %s; different %s'
                        % (only_d, only_a, diff))
    if not pairs:
        problems.append('no "pair:" lines found in the comments')

    themes = (('light', light), ('dark', dict(light, **dark)))
    rows, fails, worst = [], 0, None
    for fg, bg, need in pairs:
        cells, bad = [], False
        for tname, table in themes:
            try:
                f, b = resolve(fg, table), resolve(bg, table)
            except KeyError as e:
                cells.append('ERR'); bad = True
                problems.append('%s on %s (%s): %s' % (fg, bg, tname, e))
                continue
            cf, cb = parse_color(f), parse_color(b)
            if cf is None or cb is None:
                cells.append('ERR'); bad = True
                problems.append('%s on %s (%s): not an opaque colour (%s / %s)' % (fg, bg, tname, f, b))
                continue
            r = ratio(cf, cb)
            ok = r + 1e-9 >= need
            cells.append('%5.2f%s' % (r, '' if ok else '!'))
            if not ok:
                bad = True
            margin = r / need
            if worst is None or margin < worst[0]:
                worst = (margin, fg, bg, tname, r, need, f, b)
        if bad:
            fails += 1
        rows.append((fg, bg, need, cells, bad))

    w1 = max([len(r[0]) for r in rows] + [10])
    w2 = max([len(r[1]) for r in rows] + [10])
    print('fx1 contrast check: %s' % os.path.normpath(path))
    print('%-*s  %-*s  %4s  %7s  %7s  %s' % (w1, 'foreground', w2, 'background', 'min', 'light', 'dark', 'result'))
    for fg, bg, need, cells, bad in rows:
        if quiet and not bad:
            continue
        print('%-*s  %-*s  %4.1f  %7s  %7s  %s' % (w1, fg, w2, bg, need, cells[0], cells[1], 'FAIL' if bad else 'ok'))
    for p in problems:
        print('PROBLEM: ' + p)
    print('%d pairs x 2 themes checked, %d failing pair(s), %d other problem(s); %d light tokens, %d dark overrides'
          % (len(rows), fails, len(problems), len(light), len(dark)))
    if worst:
        print('closest to its limit: %s on %s (%s) = %.2f:1, needs %.1f:1  [%s on %s]'
              % (worst[1], worst[2], worst[3], worst[4], worst[5], worst[6], worst[7]))
    return 1 if (fails or problems) else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
