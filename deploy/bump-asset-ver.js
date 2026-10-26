/* Bump the ?v= asset stamp across the tree, byte-for-byte.
   Git Bash's `sed -i` rewrites CRLF files as LF, which turns a one-line
   change into a whole-file diff; this only touches the stamp.

   Usage: node bump-asset-ver.js <old> <new> [swVersion] [note] */
const fs = require('fs');
const path = require('path');

const [OLD, NEW, SW_VER, NOTE] = process.argv.slice(2);
if (!OLD || !NEW) { console.error('usage: bump-asset-ver.js <old> <new> [swVersion] [note]'); process.exit(1); }

const ROOT = process.cwd();
const EXT = new Set(['.html', '.js', '.php', '.webmanifest', '.css']);
const SKIP = new Set(['.git', 'node_modules', 'assets/generated', 'tmp', 'output']);

const touched = [];
(function walk(dir) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, e.name);
    const rel = path.relative(ROOT, full).replace(/\\/g, '/');
    if (SKIP.has(e.name) || SKIP.has(rel)) continue;
    if (e.isDirectory()) { walk(full); continue; }
    if (!EXT.has(path.extname(e.name).toLowerCase())) continue;
    const buf = fs.readFileSync(full);
    const txt = buf.toString('binary');
    if (!txt.includes(OLD)) continue;
    const n = txt.split(OLD).length - 1;
    fs.writeFileSync(full, Buffer.from(txt.split(OLD).join(NEW), 'binary'));
    touched.push(rel + ' (' + n + ')');
  }
})(ROOT);

if (SW_VER) {
  /* 2 Oct 2026: the VERSION line keeps ONE note. It used to prepend every
     release note to that line forever (42 KB of comment that every browser
     re-downloaded on each SW update check); the previous version + note now
     go to the top of docs/SW-VERSIONS.md instead. */
  const p = path.join(ROOT, 'sw.js');
  const txt = fs.readFileSync(p).toString('binary');
  const m = txt.match(/var VERSION = '([^']+)';([^\r\n]*)/);
  if (!m) { console.error('sw.js VERSION line not found'); process.exit(1); }
  const oldNote = m[2].replace(/^\s*\/\/\s?/, '').trim();
  const line = "var VERSION = '" + SW_VER + "';   // " + Buffer.from(NOTE || '', 'utf8').toString('binary');
  const at = txt.indexOf(m[0]);
  fs.writeFileSync(p, Buffer.from(txt.slice(0, at) + line + txt.slice(at + m[0].length), 'binary'));
  const hist = path.join(ROOT, 'docs', 'SW-VERSIONS.md');
  if (fs.existsSync(hist)) {
    const h = fs.readFileSync(hist, 'utf8');
    const entry = '- **' + m[1] + '** \u2014 ' + (Buffer.from(oldNote, 'binary').toString('utf8') || '(no note)') + '\n';
    const mark = h.indexOf('\n- **');
    fs.writeFileSync(hist, mark >= 0 ? h.slice(0, mark + 1) + entry + h.slice(mark + 1) : h + entry);
  }
  console.log('sw VERSION ' + m[1] + ' -> ' + SW_VER);
}

console.log('stamped ' + OLD + ' -> ' + NEW + ' in ' + touched.length + ' file(s):');
touched.forEach(t => console.log('  ' + t));
