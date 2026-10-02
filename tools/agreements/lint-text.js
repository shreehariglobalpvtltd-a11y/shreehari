#!/usr/bin/env node
/*
 * lint-text.js — reads every string in content.js and reports typing mistakes.
 *
 *   node tools/agreements/lint-text.js        (exit code 1 when anything is found)
 *
 * Per string: leading / trailing / double spaces, space before (or none after)
 * punctuation, repeated words ("the the", "को को"), unbalanced brackets and
 * quotes, straight quotes, "।।", Latin letters or digits glued to Devanagari
 * inside one word, broken Devanagari (a vowel sign after a space, two vowel
 * signs in a row), mixed digit systems, sentence endings (। in Nepali and
 * Hindi, . : ; ? ! in English), English full stops in Nepali / Hindi,
 * untranslated strings (identical to English, or English words inside Nepali /
 * Hindi), Hindi words in Nepali and Nepali words in Hindi, known misspellings
 * and English legal jargon ("shall", "hereinafter" ...). House rules: no comma
 * before र / वा (Nepali) or और / या (Hindi), Hindi nukta only on ज़ / फ़ (never
 * on क ख ग), Hindi ए-forms (किराए, not किराये), Nepali "यस" (not "यो") before a
 * postposition, and no bookish words (ऐच्छिक, अतिरिक्त).
 *
 * Across strings: a word spelt two ways in one language (with / without
 * nukta, ँ / ं, पञ्चम वर्ण / anusvara, ये / ए), and the three languages must carry
 * the same ui / f keys, the same list lengths, the same "need" lists, the
 * same field tokens (with their defaults) in every clause, the same
 * "(optional)" and " *" markers, and the {ref} placeholder in ui.applied.
 */
'use strict';
const { T } = require('./content');

const LANGS = ['ne', 'hi', 'en', 'gu'];
/* The languages checked against English (every one but English). */
const OTHER = LANGS.filter((l) => l !== 'en');
const GUJ = '\u0A80-\u0AFF';
const RE_GUJ = /[઀-૿]/;
const problems = [];
let checked = 0;
function report(lg, where, msg, s) {
  problems.push(`${lg} ${where}: ${msg}` + (s === undefined ? '' : `\n      «${s}»`));
}

/* ------------------------------------------------------------ what is what */
const SENTENCE_UI = new Set(['reqNote', 'confirmClear', 'agreeLine', 'needNote', 'applied', 'applyFail',
  'applyOffline', 'applyUnsure', 'publicOkNote', 'dirNote', 'dirEmpty']);
function kindOf(path) {
  if (/^(org|ind)\.sections\.\d+\.1\.\d+$/.test(path)) return 'sentence';
  if (/^(org|ind)\.intro$/.test(path)) return 'sentence';
  const m = path.match(/^ui\.(\w+)$/);
  if (m && SENTENCE_UI.has(m[1])) return 'sentence';
  if (/^need\./.test(path)) return 'item';
  return 'label';
}
function walk(node, path, out) {
  if (typeof node === 'string') { out.push([path, node]); return; }
  if (Array.isArray(node)) { node.forEach((v, i) => walk(v, path ? `${path}.${i}` : String(i), out)); return; }
  if (node && typeof node === 'object') for (const k of Object.keys(node)) walk(node[k], path ? `${path}.${k}` : k, out);
}
function get(obj, path) { return path.split('.').reduce((o, k) => (o == null ? undefined : o[k]), obj); }

/* ------------------------------------------------------------ helpers */
const TOKEN = /\[\[[^\]]+\]\]/g;
/* Tokens become neutral placeholders so their Latin names and digits are not read as text. */
const plain = (s) => s.replace(TOKEN, (t) => (t.startsWith('[[r:') ? '◯' + (t.includes('|') ? ' ' + t.slice(t.indexOf('|') + 1, -2) : '') : '▢')).replace(/\{ref\}/g, '▢');
/* A radio token carries its label after "|": only the group and value must match across languages. */
const tokenKey = (t) => (t.startsWith('[[r:') ? t.replace(/\|[^\]]*\]\]$/, ']]') : t);
const RE_LAT_GUJ = new RegExp(`[A-Za-z][${GUJ}]|[${GUJ}][A-Za-z]`);
const RE_DIG_GUJ = new RegExp(`[0-9][${GUJ}]|[${GUJ}][0-9]`);
const DEVL = '\\u0900-\\u0963\\u0971-\\u097F'; /* Devanagari letters and signs, not danda, not digits */
const RE_LAT_DEV = new RegExp(`[A-Za-z][${DEVL}]|[${DEVL}][A-Za-z]`);
const RE_DIG_DEV = new RegExp(`[0-9][${DEVL}]|[${DEVL}][0-9]`);
const WORD_SPLIT = /[\s,;:।॥?!()[\]{}“”‘’"'—–\-/·…%₹*.]+/;
/* Full stops that are not sentence ends: abbreviations, the web address, clause numbers, "1. " headings. */
const ABBR = [/प्रा\. लि\./g, /नं\./g, /सं\./g, /www\.shreehariglobal\.in/g, /\d+\.\d+/g, /^\d+\. /];
const LATIN_OK = new Set(['eSewa', 'WhatsApp', 'www.shreehariglobal.in']);

/* Words that belong to the other language. */
const WRONG_LANG = {
  ne: ['है', 'हैं', 'करेगा', 'करेगी', 'करेंगे', 'होगा', 'होगी', 'होंगे', 'नहीं', 'और', 'में', 'से', 'जाएगा', 'जाएगी', 'किया', 'गया', 'ज़रूरी', 'जिम्मेदारी'],
  hi: ['छ', 'छन्', 'छैन', 'गर्नेछ', 'हुनेछ', 'हुन्छ', 'गर्छ', 'पनि', 'गर्नुहोस्', 'र', 'बाट', 'सँग', 'जिम्मेवारी', 'यात्रु'],
};
/* Common misspellings: wrong -> right. */
const MISSPELT = {
  ne: { 'संझौता': 'सम्झौता', 'सम्झोता': 'सम्झौता', 'कृप्या': 'कृपया', 'कारवाही': 'कारबाही', 'नविकरण': 'नवीकरण',
    'उतराधिकारी': 'उत्तराधिकारी', 'हस्तक्षर': 'हस्ताक्षर', 'ब्यवहार': 'व्यवहार', 'ब्यवसाय': 'व्यवसाय', 'कानून': 'कानुन',
    'कानूनी': 'कानुनी', 'यात्री': 'यात्रु', 'प्रतिसत': 'प्रतिशत', 'गम्भिर': 'गम्भीर', 'तुरुन्त्': 'तुरुन्त', 'पेश्की': 'पेस्की', 'शर्त': 'सर्त' },
  hi: { 'कार्यवाही': 'कार्रवाई', 'कारवाई': 'कार्रवाई', 'उतराधिकारी': 'उत्तराधिकारी', 'हस्तक्षर': 'हस्ताक्षर', 'कृप्या': 'कृपया',
    'इस्तमाल': 'इस्तेमाल', 'इस्तेमल': 'इस्तेमाल', 'जिम्मेवारी': 'ज़िम्मेदारी', 'सहुलियत': 'सहूलियत', 'मुताबीक': 'मुताबिक',
    'धोकाधड़ी': 'धोखाधड़ी', 'गम्भीर': 'गंभीर', 'कानुन': 'कानून', 'कानुनी': 'कानूनी' },
  gu: {},
  en: { recieve: 'receive', recieved: 'received', seperate: 'separate', untill: 'until', adress: 'address', commision: 'commission',
    agreemnet: 'agreement', accomodation: 'accommodation', neccessary: 'necessary', occured: 'occurred', payed: 'paid',
    refered: 'referred', withold: 'withhold', guarentee: 'guarantee', responsibile: 'responsible', authorised: 'authorized',
    organisation: 'organization', teh: 'the', wich: 'which', registerd: 'registered', aggrement: 'agreement' },
};
/* Formal words people do not say; the plain word is given. */
const BOOKISH = {
  ne: { 'ऐच्छिक': '(नभए पनि हुन्छ)' },
  hi: { 'अतिरिक्त': 'अलग से / और', 'एवं': 'और' },
};
const LEGALESE = ['shall','hereinafter', 'hereby', 'herein', 'hereto', 'hereof', 'thereof', 'therein', 'whereas', 'aforesaid',
  'notwithstanding', 'forthwith', 'pursuant', 'hereunder', 'witnesseth'];

function words(s) {
  return s.split(WORD_SPLIT).filter((w) => w && /[A-Za-zऀ-ॿ઀-૿]/.test(w));
}
function brackets(s) {
  const pairs = { ')': '(', ']': '[', '}': '{', '”': '“' };
  const stack = [];
  for (const ch of s) {
    if ('([{“'.includes(ch)) stack.push(ch);
    else if (pairs[ch]) { if (stack.pop() !== pairs[ch]) return `closing ${ch} without its opening ${pairs[ch]}`; }
  }
  if (stack.length) return `opening ${stack.join('')} never closed`;
  /* ‘ is only ever an opening quote; ’ doubles as the apostrophe. */
  const open = (s.match(/‘/g) || []).length, close = (s.match(/’/g) || []).length;
  if (open > close) return 'opening ‘ never closed';
  return '';
}

/* ------------------------------------------------------------ per string */
const perLang = {};
for (const lg of LANGS) {
  const all = [];
  walk(T[lg], '', all);
  perLang[lg] = all.filter(([p]) => p !== 'lang' && p !== 'langName');
}

for (const lg of LANGS) {
  for (const [path, s] of perLang[lg]) {
    checked++;
    const kind = kindOf(path);
    const p = plain(s);
    const bad = (msg) => report(lg, path, msg, s);

    if (!s.trim()) { bad('empty string'); continue; }
    if (/^\s|\s$/.test(s)) bad('leading or trailing space');
    if (/\s{2,}/.test(s)) bad('double space');
    if (/[\t\r\n]/.test(s)) bad('tab or line break');
    if (/\s[,.;:!?।%)\]}’”]/.test(p)) bad('space before punctuation');
    if (/[,;।](?=[^\s\d)”’])/.test(p) || /:(?=[^\s\d])/.test(p)) bad('no space after punctuation');
    if (/[a-z]\.[A-Z]/.test(p)) bad('no space after full stop');
    if (/["'`]/.test(s)) bad('straight quote: use ’ “ ” instead');
    if (/।।|॥/.test(s)) bad('double danda');
    const br = brackets(p);
    if (br) bad(br);

    /* repeated words (numbers and field / radio placeholders count as words: "◯ लागू ◯ लागू नहीं" is fine) */
    const ws = p.split(/\s+/).map((w) => w.replace(/^[("“‘[]+|[)"”’\].,;:!?।]+$/g, '').toLowerCase())
      .filter((w) => /[a-z0-9ऀ-ॿ઀-૿◯▢]/.test(w));
    for (let i = 1; i < ws.length; i++) if (ws[i] === ws[i - 1]) bad(`repeated word "${ws[i]}"`);

    /* broken Devanagari */
    if (/\s[ँ-ः़ा-्]/.test(s) || /^[ँ-ः़ा-्]/.test(s)) bad('vowel sign after a space');
    if (/[ा-ौ]{2}/.test(s)) bad('two vowel signs in a row');
    if (/््|्[ा-ौ]/.test(s)) bad('halant in the wrong place');

    /* scripts and digits */
    if (lg === 'ne' || lg === 'hi') {
      if (RE_LAT_DEV.test(p)) bad('Latin letter glued to Devanagari');
      if (RE_DIG_DEV.test(p)) bad('digit glued to Devanagari');
    }
    if (lg === 'gu') {
      if (RE_LAT_GUJ.test(p)) bad('Latin letter glued to Gujarati');
      if (RE_DIG_GUJ.test(p)) bad('digit glued to Gujarati');
      if (/[૦-૯]/.test(p)) bad('Gujarati digit: this content uses 0-9 everywhere');
      if (/[ऀ-ॿ]/.test(s)) bad('Devanagari inside Gujarati');
      if (/[।]/.test(s)) bad('danda (।) in Gujarati: Gujarati ends a sentence with a full stop');
    }
    if (/[0-9]/.test(p) && /[०-९]/.test(p)) bad('mixed digit systems (0-9 and ०-९)');
    if (/[०-९]/.test(p)) bad('Devanagari digit: this content uses 0-9 everywhere');
    if (lg === 'en' && /[ऀ-ॿ]/.test(s)) bad('Devanagari inside English');

    /* sentence endings */
    if (kind === 'sentence') {
      if (lg === 'gu') {
        if (!/[.:?]$/.test(p)) bad('Gujarati sentence must end with . : or ?');
      } else if (lg === 'en') {
        if (!/[.:;?!]$/.test(p)) bad('English sentence must end with . : ; ? or !');
        if (/^[a-z]/.test(p)) bad('sentence starts with a small letter');
        if (/[.?!] [a-z]/.test(p)) bad('small letter after a full stop');
      } else if (!/[।:?]$/.test(p)) {
        bad('sentence must end with ।');
      }
    } else if (/[।]$/.test(p)) {
      bad('a label or list item must not end with ।');
    } else if (kind === 'item' && /\.$/.test(p)) {
      bad('a list item must not end with a full stop');
    }
    if (lg === 'ne' || lg === 'hi') {
      let q = p;
      for (const a of ABBR) q = q.replace(a, '');
      if (/\.(\s|$)/.test(q)) bad('English full stop in Nepali / Hindi text: use ।');
    }

    /* untranslated */
    if (lg !== 'en') {
      const en = get(T.en, path);
      if (typeof en === 'string' && en === s && /[a-z]/.test(s)) bad('same as the English text (not translated)');
      if (/[a-z]/.test(p) && !(lg === 'gu' ? RE_GUJ : /[ऀ-ॿ]/).test(p)) bad(lg === 'gu' ? 'no Gujarati at all' : 'no Devanagari at all');
      for (const w of p.split(/[\s(),:;।]+/)) {
        if (/[a-z]/.test(w) && !LATIN_OK.has(w.replace(/[.’]+$/, ''))) bad(`English word "${w}" in ${lg} text`);
      }
    }

    /* wrong-language words, misspellings, jargon */
    const wl = words(p);
    if (WRONG_LANG[lg]) for (const w of wl) if (WRONG_LANG[lg].includes(w)) bad(`"${w}" is ${lg === 'ne' ? 'Hindi' : 'Nepali'}, not ${lg === 'ne' ? 'Nepali' : 'Hindi'}`);
    for (const w of wl) {
      const key = lg === 'en' ? w.toLowerCase() : w;
      if (MISSPELT[lg] && Object.prototype.hasOwnProperty.call(MISSPELT[lg], key)) bad(`misspelt "${w}" (write "${MISSPELT[lg][key]}")`);
    }
    if (lg === 'en') for (const w of wl) if (LEGALESE.includes(w.toLowerCase())) bad(`legal jargon "${w}": say it plainly`);
    if (lg === 'ne' && /[़क़-य़]/.test(s)) bad('nukta (़) is Hindi spelling, not Nepali');
    if (lg === 'ne' && /[ऑॅॉ]/.test(s)) bad('ॉ / ॅ is Hindi spelling, not Nepali');
    if (lg === 'hi') for (const w of wl) if (/्$/.test(w)) bad(`"${w}" ends with a halant (Nepali spelling)`);

    /* house rules */
    if (BOOKISH[lg]) for (const w of wl) if (Object.prototype.hasOwnProperty.call(BOOKISH[lg], w)) bad(`bookish word "${w}" (say "${BOOKISH[lg][w]}")`);
    if (lg === 'ne' && /, (र|वा) /.test(p)) bad('comma before र / वा: Nepali has no serial comma');
    if (lg === 'hi' && /, (और|या) /.test(p)) bad('comma before और / या: Hindi has no serial comma');
    if (lg === 'hi' && /[क़-ग़]|[क-ग]़/.test(s)) bad('nukta on क / ख / ग: this text keeps the nukta only on ज़ and फ़');
    if (lg === 'hi') for (const w of wl) if (w !== 'ये' && /ये$/.test(w)) bad(`"${w}": write the ए-form (किराए, लिए, गए)`);
    if (lg === 'ne') {
      const m = p.match(/(^|\s)यो ([^\s,।:;()]+?(?:का|को|की|मा|बाट|बारे|लाई|ले|सँग|भन्दा|अन्तर्गत))(?=[\s,।:;)]|$)/);
      if (m) bad(`"यो ${m[2]}": write "यस" before a postposition`);
    }
  }
}

/* ------------------------------------------------------------ one spelling per word */
function norm(w) {
  return w.normalize('NFC')
    .replace(/[‌‍]/g, '')
    .replace(/[क़-य़]/g, (c) => 'कखगजडढफय'['क़ख़ग़ज़ड़ढ़फ़य़'.indexOf(c)])
    .replace(/़/g, '')
    .replace(/ँ/g, 'ं')
    .replace(/ङ्(?=[कखगघ])|ञ्(?=[चछजझ])|ण्(?=[टठडढ])|न्(?=[तथदध])|म्(?=[पफबभ])/g, 'ं')
    .replace(/ये/g, 'ए').replace(/यी/g, 'ई');
}
for (const lg of ['ne', 'hi']) {
  const forms = new Map();
  for (const [, s] of perLang[lg]) {
    for (const w of words(plain(s))) {
      if (!/[ऀ-ॿ]/.test(w)) continue;
      const n = norm(w);
      if (!forms.has(n)) forms.set(n, new Set());
      forms.get(n).add(w);
    }
  }
  for (const set of forms.values()) if (set.size > 1) report(lg, '(all strings)', `one word, ${set.size} spellings: ${[...set].join(' / ')}`);
}

/* ------------------------------------------------------------ the three languages line up */
const OPTIONAL = { en: '(optional)', ne: '(नभए पनि हुन्छ)', hi: '(ज़रूरी नहीं)', gu: '(જરૂરી નથી)' };
const keysOf = (o) => Object.keys(o || {}).sort().join(',');
for (const lg of OTHER) {
  for (const part of ['ui', 'f', 'co']) {
    if (keysOf(T[lg][part]) !== keysOf(T.en[part])) {
      const a = Object.keys(T[lg][part] || {}), b = Object.keys(T.en[part]);
      report(lg, part, `keys differ from English: missing [${b.filter((k) => !a.includes(k))}] extra [${a.filter((k) => !b.includes(k))}]`);
    }
  }
  for (const k of ['orgTypes', 'idTypes']) {
    if ((T[lg].f[k] || []).length !== T.en.f[k].length) report(lg, `f.${k}`, 'list length differs from English');
  }
}
for (const lg of LANGS) {
  const need = T[lg].need;
  if (!need || !Array.isArray(need.org) || !Array.isArray(need.ind)) { report(lg, 'need', 'need.org and need.ind lists are missing'); continue; }
  for (const ty of ['org', 'ind']) {
    const n = need[ty].length;
    if (n < 6 || n > 9) report(lg, `need.${ty}`, `${n} items (must be 6 to 9)`);
    if (n !== T.en.need[ty].length) report(lg, `need.${ty}`, `${n} items, English has ${T.en.need[ty].length}`);
  }
  if ((T[lg].ui.applied.match(/\{ref\}/g) || []).length !== 1) report(lg, 'ui.applied', 'must contain {ref} exactly once', T[lg].ui.applied);
  for (const [path, s] of perLang[lg]) {
    if (/\{(?!ref\})/.test(s)) report(lg, path, 'unknown {placeholder}', s);
  }
}
/* markers and tokens, compared with English path by path */
for (const [path, en] of perLang.en) {
  for (const lg of OTHER) {
    const s = get(T[lg], path);
    if (typeof s !== 'string') { report(lg, path, 'missing (English has it)'); continue; }
    if (en.includes(OPTIONAL.en) !== s.includes(OPTIONAL[lg])) report(lg, path, `"${OPTIONAL.en}" marker does not match English (${OPTIONAL[lg]})`, s);
    if (/ \*$/.test(en) !== / \*$/.test(s)) report(lg, path, 'required marker " *" does not match English', s);
    const te = (en.match(TOKEN) || []).map(tokenKey).join(' '), ts = (s.match(TOKEN) || []).map(tokenKey).join(' ');
    if (te !== ts) report(lg, path, `field tokens differ from English: [${ts}] vs [${te}]`);
  }
}
for (const ty of ['org', 'ind']) {
  for (const lg of LANGS) {
    const secs = T[lg][ty].sections;
    if (secs.length !== 15) report(lg, `${ty}.sections`, `${secs.length} sections (must be 15 since v2.2)`);
    secs.forEach((sec, i) => {
      const ref = T.en[ty].sections[i];
      if (ref && sec[1].length !== ref[1].length) report(lg, `${ty}.sections.${i}`, `${sec[1].length} clauses, English has ${ref[1].length}`);
    });
  }
}

/* ------------------------------------------------------------ result */
if (problems.length) {
  console.error(problems.join('\n'));
  console.error(`lint-text: ${problems.length} problem(s) in ${checked} strings`);
  process.exit(1);
}
console.log(`lint-text: ${checked} strings checked in ${LANGS.join(', ')}, no problems`);
