/*
 * build.js — renders the eight agreement templates (2 types x 4 languages) and
 * the /agreements/ landing page as self-contained HTML.
 *
 *   node tools/agreements/build.js                   -> agreements/*.html (the site folder)
 *   node tools/agreements/build.js --content=x.js --out=dir
 *                                                    -> preview another content file elsewhere
 *
 * Each page is fillable on a phone (autosaved in that phone's localStorage),
 * can send the application to /api/agreement-apply.php, and prints to A4 in
 * colour or black & white. pdf.sh prints both with headless Chrome ("#bw" in
 * the URL switches the theme) and check-pdf.py checks the PDFs.
 *
 * Optional content keys (the page falls back to the strings in FB below):
 *   T[lang].need = { org: [..], ind: [..] }   "what you need" checklist
 *   T[lang].ui.needTitle, needNote, apply, applying, applied ("{ref}"), applyFail,
 *   applyOffline, publicOk, publicOkNote, dirTitle, dirNote, dirEmpty, verified,
 *   signed, required, schedule, applyHead, applyNote, all
 */
const fs = require('fs');
const path = require('path');

const arg = (k) => { const a = process.argv.find((x) => x.startsWith('--' + k + '=')); return a ? a.slice(k.length + 3) : null; };
const CONTENT = path.resolve(arg('content') || path.join(__dirname, 'content.js'));
const OUT = path.resolve(arg('out') || path.join(__dirname, '..', '..', 'agreements'));
const { CO, T } = require(CONTENT);

fs.mkdirSync(OUT, { recursive: true });
const b64 = (f) => fs.readFileSync(path.join(__dirname, 'assets', f)).toString('base64');
const LOGO = 'data:image/webp;base64,' + b64('logo.webp');
const BUS = 'data:image/webp;base64,' + b64('bus.webp');

const LANGS = ['ne', 'hi', 'en', 'gu'];
const TYPES = ['org', 'ind'];
const FILE = { org: 'partner-agreement', ind: 'agent-agreement' };
const DOC_CODE = { org: 'P', ind: 'A' };
const VERSION = 'v2.2';
const API_APPLY = '/api/agreement-apply.php';
const API_DIR = '/api/agent-directory.php';
/* Fields the application API requires (client-side check; the server checks again). */
const REQUIRED = { org: ['p_name', 'p_phone', 'p_area', 'p_rep'], ind: ['p_name', 'p_phone', 'p_area', 'p_idno', 'p_addr'] };

const TYPE_LABEL = {
  org: { ne: 'साझेदार संस्था', hi: 'पार्टनर संस्था', en: 'Partner organization', gu: 'ભાગીદાર સંસ્થા' },
  ind: { ne: 'व्यक्तिगत एजेन्ट', hi: 'व्यक्तिगत एजेंट', en: 'Individual agent', gu: 'વ્યક્તિગત એજન્ટ' },
};
/* Short labels for the phone toolbar. */
const TYPE_SHORT = {
  org: { ne: 'संस्था', hi: 'संस्था', en: 'Partner', gu: 'સંસ્થા' },
  ind: { ne: 'एजेन्ट', hi: 'एजेंट', en: 'Agent', gu: 'એજન્ટ' },
};
/* One line per type and language for the landing page. */
const DESC = {
  org: {
    ne: 'यात्रु ल्याएर टिकट बुकिङ गर्ने संस्था, फर्म वा प्रा. लि. कम्पनीका लागि।',
    hi: 'यात्री लाकर टिकट बुकिंग करने वाली संस्था, फ़र्म या प्रा. लि. कंपनी के लिए।',
    en: 'For an organization, firm or private limited company that brings passengers and books tickets.',
    gu: 'મુસાફરો લાવીને ટિકિટ બુક કરતી સંસ્થા, પેઢી અથવા પ્રા. લિ. કંપની માટે.',
  },
  ind: {
    ne: 'यात्रु ल्याएर टिकट बुकिङ गर्ने एक जना व्यक्ति (एजेन्ट / कनेक्टर) का लागि।',
    hi: 'यात्री लाकर टिकट बुकिंग करने वाले एक व्यक्ति (एजेंट / कनेक्टर) के लिए।',
    en: 'For one person (agent / connector) who brings passengers and books tickets.',
    gu: 'મુસાફરો લાવીને ટિકિટ બુક કરતી એક વ્યક્તિ (એજન્ટ / કનેક્ટર) માટે.',
  },
};

/* Fallback strings, used only when content.js does not carry the key. */
const FB = {
  ne: {
    needTitle: 'यो सम्झौताका लागि चाहिने कुरा',
    needNote: 'यी कुरा तयार राख्नुहोस्। भएको कुरामा ✓ लगाउनुहोस्।',
    apply: 'आवेदन पठाउनुहोस्',
    applying: 'पठाउँदै…',
    applied: 'आवेदन पुग्यो। तपाईंको नम्बर {ref} हो। हाम्रो कार्यालयले फोन गर्नेछ।',
    applyFail: 'पठाउन सकिएन। चिन्ह लागेका ठाउँ भरेर फेरि पठाउनुहोस्।',
    applyOffline: 'अहिले इन्टरनेट छैन। फारम यही फोनमा सुरक्षित छ — पछि फेरि पठाउनुहोस्, वा 9104801507 मा फोन गर्नुहोस्।',
    publicOk: 'मेरो फोन नम्बर सार्वजनिक सूचीमा देखाउनुहोस्',
    publicOkNote: 'यसमा ✓ नलगाए सूचीमा तपाईंको नाम, क्षेत्र र नम्बरको केही भाग (जस्तै 98•••••123) मात्र देखिन्छ।',
    dirTitle: 'दर्ता भएका एजेन्ट र साझेदार',
    dirNote: 'यहाँ नाम, क्षेत्र र फोन नम्बर मात्र देखाइन्छ।',
    dirEmpty: 'अहिलेसम्म देखाउने नाम छैन।',
    verified: 'KYC जाँच भयो',
    signed: 'सम्झौता भयो',
    required: 'यो भर्नुहोस्',
    schedule: 'अनुसूची — भुक्तानी सर्त',
    applyHead: 'यो फारम अनलाइन पठाउनुहोस्',
    applyNote: 'हाम्रो कार्यालयले हेरेर फोन गर्नेछ। सम्झौतामा हस्ताक्षर पछि कागजमै गरिन्छ।',
    all: 'सबै सम्झौता',
  },
  hi: {
    needTitle: 'इस अनुबंध के लिए ज़रूरी चीज़ें',
    needNote: 'ये चीज़ें तैयार रखें। जो है, उस पर ✓ लगाएँ।',
    apply: 'आवेदन भेजें',
    applying: 'भेजा जा रहा है…',
    applied: 'आवेदन पहुँच गया। आपका नंबर {ref} है। हमारा ऑफ़िस आपको फ़ोन करेगा।',
    applyFail: 'भेजा नहीं जा सका। निशान लगे खाने भरकर फिर से भेजें।',
    applyOffline: 'अभी इंटरनेट नहीं है। फ़ॉर्म इसी फ़ोन में सुरक्षित है — बाद में फिर भेजें, या 9104801507 पर फ़ोन करें।',
    publicOk: 'मेरा फ़ोन नंबर सार्वजनिक सूची में दिखाएँ',
    publicOkNote: 'इस पर ✓ न लगाने पर सूची में सिर्फ़ आपका नाम, क्षेत्र और नंबर का कुछ हिस्सा (जैसे 98•••••123) दिखेगा।',
    dirTitle: 'पंजीकृत एजेंट और पार्टनर',
    dirNote: 'यहाँ सिर्फ़ नाम, क्षेत्र और फ़ोन नंबर दिखाए जाते हैं।',
    dirEmpty: 'अभी दिखाने के लिए कोई नाम नहीं है।',
    verified: 'KYC जाँचा गया',
    signed: 'अनुबंध हो गया',
    required: 'यह भरें',
    schedule: 'अनुसूची — भुगतान की शर्तें',
    applyHead: 'यह फ़ॉर्म ऑनलाइन भेजें',
    applyNote: 'हमारा ऑफ़िस इसे देखकर आपको फ़ोन करेगा। अनुबंध पर हस्ताक्षर बाद में काग़ज़ पर होंगे।',
    all: 'सभी अनुबंध',
  },
  en: {
    needTitle: 'What you need for this agreement',
    needNote: 'Keep these ready. Tick each one you have.',
    apply: 'Send application',
    applying: 'Sending…',
    applied: 'Application sent. Your number is {ref}. Our office will call you.',
    applyFail: 'Could not send. Please fill the marked boxes and try again.',
    applyOffline: 'No internet right now. The form is saved on this phone — send it again later, or call 9104801507.',
    publicOk: 'Show my phone number in the public list',
    publicOkNote: 'If you leave this empty, the list shows only your name, your area and part of your number (like 98•••••123).',
    dirTitle: 'Registered agents and partners',
    dirNote: 'Only names, areas and phone numbers are shown here.',
    dirEmpty: 'No names to show yet.',
    verified: 'KYC verified',
    signed: 'Agreement signed',
    required: 'Please fill this',
    schedule: 'Schedule — pay terms',
    applyHead: 'Send this form online',
    applyNote: 'Our office will check it and call you. The agreement is signed on paper later.',
    all: 'All agreements',
  },
  gu: {
    needTitle: 'આ કરાર માટે શું જોઈએ',
    needNote: 'આ વસ્તુઓ તૈયાર રાખો. જે હોય તેના પર ✓ કરો.',
    apply: 'અરજી મોકલો',
    applying: 'મોકલી રહ્યા છીએ…',
    applied: 'અરજી પહોંચી ગઈ. તમારો નંબર {ref} છે. અમારી ઓફિસ તમને ફોન કરશે.',
    applyFail: 'મોકલી શકાયું નહીં. નિશાની કરેલાં ખાનાં ભરીને ફરી મોકલો.',
    applyOffline: 'હમણાં ઇન્ટરનેટ નથી. ફોર્મ આ ફોનમાં સેવ છે — પછી ફરી મોકલો, અથવા 9104801507 પર ફોન કરો.',
    publicOk: 'જાહેર યાદીમાં મારો ફોન નંબર બતાવો',
    publicOkNote: 'આ ખાનું ખાલી રાખો તો યાદીમાં ફક્ત તમારું નામ, વિસ્તાર અને નંબરનો થોડો ભાગ (જેમ કે 98•••••123) દેખાશે.',
    dirTitle: 'નોંધાયેલા એજન્ટ અને ભાગીદારો',
    dirNote: 'અહીં ફક્ત નામ, વિસ્તાર અને ફોન નંબર બતાવવામાં આવે છે.',
    dirEmpty: 'હજી બતાવવા માટે કોઈ નામ નથી.',
    verified: 'KYC તપાસ થઈ',
    signed: 'કરાર થયો',
    required: 'આ ભરો',
    schedule: 'અનુસૂચિ — ચુકવણીની શરતો',
    applyHead: 'આ ફોર્મ ઓનલાઇન મોકલો',
    applyNote: 'અમારી ઓફિસ તપાસીને તમને ફોન કરશે. કરાર પર સહી પછી કાગળ પર થશે.',
    all: 'બધા કરાર',
  },
};
/* Landing-page strings. */
const IDX = {
  title: { ne: 'सम्झौता पत्र', hi: 'अनुबंध पत्र', en: 'Agreements', gu: 'કરાર પત્ર' },
  lead: {
    ne: 'फारम फोनमै भर्नुहोस् र अनलाइन पठाउनुहोस्, वा PDF डाउनलोड गरी प्रिन्ट गरेर हातैले हस्ताक्षर गर्नुहोस्।',
    hi: 'फ़ॉर्म फ़ोन पर ही भरें और ऑनलाइन भेजें, या PDF डाउनलोड करके प्रिंट करें और हाथ से हस्ताक्षर करें।',
    en: 'Fill in the form on your phone and send it online, or download the PDF, print it and sign by hand.',
    gu: 'ફોર્મ ફોનમાં જ ભરો અને ઓનલાઇન મોકલો, અથવા PDF ડાઉનલોડ કરીને પ્રિન્ટ કરો અને હાથથી સહી કરો.',
  },
  open: { ne: 'फारम खोल्नुहोस्', hi: 'फ़ॉर्म खोलें', en: 'Open the form', gu: 'ફોર્મ ખોલો' },
  other: { ne: 'अन्य पृष्ठ', hi: 'अन्य पेज', en: 'Other pages', gu: 'અન્ય પાનાં' },
  old: { ne: 'पहिलेको एजेन्ट सम्झौता फारम', hi: 'पुराना एजेंट अनुबंध फ़ॉर्म', en: 'Earlier agent agreement form', gu: 'અગાઉનું એજન્ટ કરાર ફોર્મ' },
  panel: { ne: 'एजेन्ट प्यानल (अफलाइन नोटबुक)', hi: 'एजेंट पैनल (ऑफ़लाइन नोटबुक)', en: 'Agent panel (offline notebook)', gu: 'એજન્ટ પેનલ (ઓફલાઇન નોટબુક)' },
  desk: { ne: 'एजेन्ट / स्टाफ लगइन — टिकट बुक गर्न', hi: 'एजेंट / स्टाफ़ लॉगिन — टिकट बुक करने के लिए', en: 'Agent / staff sign-in — to book tickets', gu: 'એજન્ટ / સ્ટાફ લોગિન — ટિકિટ બુક કરવા' },
  search: { ne: 'नाम वा क्षेत्र खोज्नुहोस्', hi: 'नाम या क्षेत्र खोजें', en: 'Search a name or area', gu: 'નામ અથવા વિસ્તાર શોધો' },
  agent: { ne: 'एजेन्ट', hi: 'एजेंट', en: 'Agent', gu: 'એજન્ટ' },
  partner: { ne: 'साझेदार', hi: 'पार्टनर', en: 'Partner', gu: 'ભાગીદાર' },
};

/* ---------------------------------------------------------------- checks */
function fieldNames(s) { return (s.match(/\[\[([^\]]+)\]\]/g) || []).map((t) => t.slice(2, -2).split('|')[0]); }
let problems = 0;
for (const ty of TYPES) {
  const ref = T.en[ty].sections;
  for (const lg of LANGS) {
    const s = T[lg][ty].sections;
    if (s.length !== ref.length) { console.error(`${lg}/${ty}: ${s.length} sections, English has ${ref.length}`); problems++; continue; }
    s.forEach((sec, i) => {
      if (sec[1].length !== ref[i][1].length) { console.error(`${lg}/${ty} section ${i + 1}: ${sec[1].length} clauses, English has ${ref[i][1].length}`); problems++; }
      sec[1].forEach((c, j) => {
        const a = fieldNames(c).join(','), b = fieldNames(ref[i][1][j] || '').join(',');
        if (a !== b) { console.error(`${lg}/${ty} ${i + 1}.${j + 1}: fields [${a}] vs English [${b}]`); problems++; }
      });
    });
  }
}
/* The "what you need" checklist: optional, but when English has one every language must match it item for item. */
if (T.en.need) {
  for (const ty of TYPES) {
    const n = (T.en.need[ty] || []).length;
    for (const lg of LANGS) {
      const m = ((T[lg].need || {})[ty] || []).length;
      if (m !== n) { console.error(`${lg}/need.${ty}: ${m} items, English has ${n}`); problems++; }
    }
  }
}
if (problems) { console.error(problems + ' structure problem(s) — nothing written'); process.exit(1); }
console.log('structure: 2 types x ' + LANGS.length + ' languages, ' + T.en.org.sections.length + ' sections each, clause counts and fields identical' +
  (T.en.need ? ', checklists aligned' : ', no checklist in content (block omitted)'));

/* A light typo lint over every string of the content (warnings only). */
(function lint() {
  const out = [];
  const walk = (v, where) => {
    if (typeof v === 'string') {
      // 10 Oct 2026 (trust): a {{f:key|text}} fact reads as its printed text
      const s = v.replace(/\{\{f:[a-zA-Z0-9_.]{1,40}\|([^{}|]{0,300})\}\}/g, '$1').replace(/\[\[[^\]]*\]\]/g, 'X');
      /* two spaces before a tick box or between "____" blanks are deliberate */
      if (/\S {2,}\S/.test(s.replace(/ {2,}(?=X)/g, ' ').replace(/(_) {2,}/g, '$1 '))) out.push(where + ': double space');
      if (/ [,.;:।?!)]/.test(s)) out.push(where + ': space before punctuation');
      if (/([,;:])(?=[^\s\d"”’)\]*])/.test(s)) out.push(where + ': no space after "' + RegExp.$1 + '"');
      if (/\b(\w{2,})\s+\1\b/i.test(s)) out.push(where + ': repeated word "' + RegExp.$1 + '"');
      if (/^\s|\s$/.test(v) && !/ \*$/.test(v)) out.push(where + ': leading/trailing space');
      if ((s.match(/\(/g) || []).length !== (s.match(/\)/g) || []).length) out.push(where + ': unbalanced ( )');
      if (/[।.]{2,}(?!\.)/.test(s.replace(/…/g, ''))) out.push(where + ': repeated full stop');
      return;
    }
    if (Array.isArray(v)) v.forEach((x, i) => walk(x, where + '[' + i + ']'));
    else if (v && typeof v === 'object') Object.keys(v).forEach((k) => walk(v[k], where + '.' + k));
  };
  for (const lg of LANGS) walk(T[lg], lg);
  walk(FB, 'fallback');
  if (out.length) { console.warn('lint (check these):'); out.slice(0, 40).forEach((x) => console.warn('  ' + x)); }
  else console.log('lint: no double spaces, stray punctuation or repeated words');
})();

/* ---------------------------------------------------------------- helpers */
const esc = (s) => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const cssStr = (s) => '"' + String(s).replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/[\r\n]+/g, ' ') + '"';
const js = (v) => JSON.stringify(v).replace(/</g, '\\u003c').replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');
const plain = (s) => String(s || '').replace(/\s*\*\s*$/, '');
/* A label: a trailing "*" becomes the required marker. */
const lab = (s) => /\*\s*$/.test(String(s || '')) ? `${esc(plain(s))}<b class="rq" aria-hidden="true">*</b>` : esc(s);
const ui = (lg, k) => (T[lg].ui && T[lg].ui[k]) || FB[lg][k] || FB.en[k] || '';
const needList = (lg, ty) => ((T[lg].need || {})[ty] || []).filter(Boolean);

/* 10 Oct 2026 (trust, owner: "agreement ko data live hunu paryo"): a company
   fact printed on the page carries its SiteFacts key, so the page follows
   Admin -> Company details without a rebuild:
     {{f:brand.cin|U52291GJ2026PTC174029}} in content.js  → <span data-fact="brand.cin">U52291GJ2026PTC174029</span>
     fact('head.phone', CO.phone) in a template literal → the same span
   The printed text stays in the HTML (the page reads right without JS and
   is what AgreementText parses); assets/js/agreement-live.js refreshes the
   spans from /api/agreement-points.php "facts". Applied AFTER esc(), so
   both the key and the text are already safe. Keys: brand.legal, brand.cin,
   brand.web, head.address, head.addressNe, head.phone (AgreementText::FACT_KEYS).
   The Hindi / Gujarati pages keep their own-script office line as built:
   there is no Hindi or Gujarati address setting, and a Roman address would
   replace the reader's language.
   14 Oct 2026 (review): on hi / gu the LETTERHEAD office line is plain built
   text too. Only following Settings in the letterhead would let one signed
   agreement name two different registered offices (letterhead = Settings,
   party block = built Hindi / Gujarati text). Both stay the built address
   until a Hindi / Gujarati address setting exists.
   The pages load agreement-live.js?v=20261010t: bump that stamp whenever
   agreement-live.js changes, or returning visitors keep the cached copy. */
const facts = (h) => String(h).replace(/\{\{f:([a-zA-Z0-9_.]{1,40})\|([^{}|]{0,300})\}\}/g, (m, k, t) => `<span data-fact="${k}">${t}</span>`);
const fact = (key, text) => `<span data-fact="${esc(key)}">${esc(text)}</span>`;

function inline(text) {
  const html = facts(esc(text)).replace(/\[\[([^\]]+)\]\]/g, (m, body) => {
    const p = body.split('|');
    if (p[0].startsWith('r:')) {
      const [, group, value] = p[0].split(':');
      const box = `<input type="radio" class="rd" name="${esc(group)}" value="${esc(value)}">`;
      /* the box and its words stay on one line, so a tick can never sit beside the wrong words */
      return p[1] ? `<label class="ro">${box}<span>${p[1]}</span></label>` : box;
    }
    if (p[0].startsWith('k:')) {
      /* a tick box for the office (KYC papers received); kept on this phone, never sent */
      const name = 'k_' + p[0].slice(2);
      return `<input type="checkbox" class="chk kb" name="${esc(name)}" value="1" aria-label="${esc(name)}">`;
    }
    const [name, def = '', width = '5em'] = p;
    const num = /pct|day|target|rate|fixed|dep|hours|years/.test(name);
    return `<input class="in" name="${esc(name)}" value="${esc(def)}" style="width:${esc(width)}" inputmode="${num ? 'decimal' : 'text'}" aria-label="${esc(name)}">`;
  });
  /* a box never starts a line with its ₹ left behind, or ends one with its full stop pushed to the next */
  return html.replace(/(₹?)(<input [^>]*>)([।.,;:%)]*)/g, (m, a, b, c) => (a || c ? `<span class="nw">${a}${b}${c}</span>` : m));
}
/* A labelled field box. p = columns of 12 on paper, s = columns of 12 on a wide screen. */
function fld(lg, key, name, o = {}) {
  const L = T[lg];
  const id = 'i_' + name;
  const type = o.type || 'text';
  const req = o.req ? ' aria-required="true"' : '';
  const im = o.im ? ` inputmode="${o.im}"` : '';
  const ac = o.ac ? ` autocomplete="${o.ac}"` : '';
  const ctl = o.area
    ? `<textarea id="${id}" name="${name}" rows="2"${req}></textarea>`
    : `<input id="${id}" type="${type}" name="${name}"${im}${ac}${req}>`;
  return `<div class="f" style="--p:${o.p || 12};--s:${o.s || o.p || 12}"><label for="${id}">${lab(L.f[key])}</label>${ctl}</div>`;
}
function fixed(label, value, p, s) {
  const empty = !String(label || '').trim();
  return `<div class="f" style="--p:${p};--s:${s || p}"><span class="lb${empty ? ' lb-empty' : ''}">${empty ? '&nbsp;' : lab(label)}</span><div class="fixed">${esc(value)}</div></div>`;
}
function radios(lg, key, listKey, name, p = 12, s = 12) {
  const L = T[lg];
  const opts = (L.f[listKey] || []).map((o, i) => `<label class="opt"><input type="radio" name="${name}" value="${i}"><span>${esc(o)}</span></label>`).join('');
  return `<div class="f" style="--p:${p};--s:${s}"><span class="lb" id="lb_${name}">${lab(L.f[key])}</span><div class="opts" role="radiogroup" aria-labelledby="lb_${name}">${opts}</div></div>`;
}
const mirror = (name) => `<span class="mirror" data-of="${name}"></span>`;
/* "1. Agreement details" -> numbered badge + text */
function subHead(text) {
  const m = /^\s*(\d+)\.\s*(.+)$/.exec(String(text || ''));
  return m ? `<h3 class="sub"><span class="k">A${m[1]}</span>${esc(m[2])}</h3>` : `<h3 class="sub">${esc(text)}</h3>`;
}
const ICON = {
  web: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9.2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M3 12h18M12 2.8c2.6 2.6 3.9 5.7 3.9 9.2s-1.3 6.6-3.9 9.2M12 2.8C9.4 5.4 8.1 8.5 8.1 12s1.3 6.6 3.9 9.2" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>',
  phone: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1L6.6 10.8z" fill="currentColor"/></svg>',
  person: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8.5" r="4" fill="currentColor"/><path d="M4 21c.6-4.4 3.9-7 8-7s7.4 2.6 8 7" fill="currentColor"/></svg>',
  print: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h10v5H7zM5 9h14a2 2 0 0 1 2 2v6h-4v4H7v-4H3v-6a2 2 0 0 1 2-2zm4 7v3h6v-3z" fill="currentColor"/></svg>',
};
const FONTS = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>\n' +
  '<link href="https://fonts.googleapis.com/css2?family=Noto+Sans:wght@400;600;700&family=Noto+Sans+Devanagari:wght@400;600;700&family=Noto+Sans+Gujarati:wght@400;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">';

/* ================================================================ client: agreement page */
/* Runs in the browser; serialised with toString(), so it must not use build-time names. */
function agreementPage(CFG) {
  var root = document.documentElement, form = document.getElementById('doc'), U = CFG.ui;
  var KEY = 'shg-agreement-' + CFG.id + '-v1', REFKEY = KEY + '-ref';
  var blank = /[?&]blank=1/.test(location.search);
  function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); return true; } catch (e) { return false; } }
  function each(sel, fn) { [].forEach.call(document.querySelectorAll(sel), fn); }
  var toast = document.getElementById('toast'), T0;
  function say(t) { toast.textContent = t; toast.classList.add('on'); clearTimeout(T0); T0 = setTimeout(function () { toast.classList.remove('on'); }, 2000); }

  /* colour / black & white */
  function theme(t) {
    root.classList.toggle('bw', t === 'bw');
    each('[data-theme]', function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-theme') === t ? 'true' : 'false'); });
    lsSet('shg-agreement-theme', t);
  }
  var h = (location.hash || '').replace('#', '');
  theme(h === 'bw' || h === 'colour' ? h : (lsGet('shg-agreement-theme') || 'colour'));
  each('[data-theme]', function (b) { b.addEventListener('click', function () { theme(b.getAttribute('data-theme')); }); });

  /* fields, mirrors, autosave */
  function fields() { return [].filter.call(form.querySelectorAll('input[name],textarea[name]'), function (el) { return el.name !== 'website'; }); }
  function mark(el) { if (el.type !== 'radio' && el.type !== 'checkbox') el.classList.toggle('empty', !el.value); }
  function mirrors() {
    each('.mirror', function (m) {
      var src = form.querySelector('[name="' + m.getAttribute('data-of') + '"]'), v = src ? src.value : '';
      if (src && src.type === 'date' && v) { var p = v.split('-'); v = p[2] + '-' + p[1] + '-' + p[0]; }
      m.textContent = v;
    });
  }
  var needs = [].slice.call(form.querySelectorAll('input[data-need]'));
  var pub = document.getElementById('pubOk');
  var photo = document.getElementById('photo'), pimg = photo.querySelector('img');
  function photoData() { return pimg.getAttribute('data-saved') === '1' && /^data:image\//.test(pimg.getAttribute('src') || '') ? pimg.getAttribute('src') : null; }
  function setPhoto(src, keep) { pimg.src = src; photo.classList.add('has'); pimg.setAttribute('data-saved', keep ? '1' : '0'); }
  function save() {
    var d = {};
    fields().forEach(function (el) {
      if (el.type === 'radio') { if (el.checked) d[el.name] = el.value; }
      else if (el.type === 'checkbox') d[el.name] = el.checked ? '1' : '';
      else d[el.name] = el.value;
    });
    d.__need = needs.map(function (c) { return c.checked ? 1 : 0; });
    if (pub) d.__pub = pub.checked ? 1 : 0;
    var ph = photoData();
    if (ph) d.__photo = ph;
    if (!lsSet(KEY, JSON.stringify(d))) { delete d.__photo; lsSet(KEY, JSON.stringify(d)); }
  }
  function load() {
    if (blank) return;
    var d = null;
    try { d = JSON.parse(lsGet(KEY) || 'null'); } catch (e) {}
    if (!d) return;
    fields().forEach(function (el) {
      if (!(el.name in d)) return;
      if (el.type === 'radio') el.checked = d[el.name] === el.value;
      else if (el.type === 'checkbox') el.checked = d[el.name] === '1';
      else el.value = d[el.name];
    });
    if (d.__need) needs.forEach(function (c, i) { c.checked = !!d.__need[i]; });
    if (pub) pub.checked = !!d.__pub;
    if (d.__photo) setPhoto(d.__photo, true);
  }
  document.getElementById('photoIn').addEventListener('change', function (e) {
    var file = e.target.files && e.target.files[0];
    if (!file) return;
    var fr = new FileReader();
    fr.onload = function () {
      var im = new Image();
      im.onload = function () {
        var s = Math.min(1, 480 / Math.max(im.width, im.height)), c = document.createElement('canvas');
        c.width = Math.round(im.width * s); c.height = Math.round(im.height * s);
        c.getContext('2d').drawImage(im, 0, 0, c.width, c.height);
        setPhoto(c.toDataURL('image/jpeg', 0.82), true); save(); say(U.saved);
      };
      im.src = fr.result;
    };
    fr.readAsDataURL(file);
  });
  document.getElementById('photoRm').addEventListener('click', function (e) {
    e.preventDefault(); pimg.removeAttribute('src'); photo.classList.remove('has'); pimg.setAttribute('data-saved', '0');
    document.getElementById('photoIn').value = ''; save();
  });
  load();
  fields().forEach(function (el) {
    mark(el);
    el.addEventListener('input', function () {
      mark(el); mirrors(); save(); say(U.saved);
      var box = el.closest('.f');
      if (box && box.classList.contains('bad')) { box.classList.remove('bad'); var er = box.querySelector('.err'); if (er) er.parentNode.removeChild(er); }
    });
    el.addEventListener('change', function () { mark(el); mirrors(); save(); });
  });
  needs.concat(pub ? [pub] : []).forEach(function (c) { c.addEventListener('change', save); });
  mirrors();
  document.getElementById('btnPrint').addEventListener('click', function () { window.print(); });
  window.addEventListener('beforeprint', function () { fields().forEach(mark); mirrors(); });

  /* send the application */
  var btn = document.getElementById('btnApply'), st = document.getElementById('applyMsg');
  var hp = form.querySelector('input[name="website"]');
  function status(t, kind) { st.textContent = t || ''; st.className = 'status' + (kind ? ' ' + kind : ''); }
  function boxOf(name) { var el = form.querySelector('[name="' + name + '"]'); return el ? el.closest('.f') : null; }
  function clearErrs() {
    each('.f.bad', function (b) { b.classList.remove('bad'); });
    each('.f .err', function (e) { e.parentNode.removeChild(e); });
  }
  function markErr(name, text) {
    var box = boxOf(name);
    if (!box) return null;
    box.classList.add('bad');
    var e = box.querySelector('.err');
    if (!e) { e = document.createElement('small'); e.className = 'err'; e.setAttribute('role', 'alert'); box.appendChild(e); }
    e.textContent = text || U.required;
    return box;
  }
  function focusBox(box) {
    var el = box && box.querySelector('input,textarea');
    if (!el) return;
    try { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) { el.scrollIntoView(); }
    try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }
  }
  function collect() {
    var o = {};
    fields().forEach(function (el) {
      if (el.type === 'checkbox') return;   /* the office's KYC tick boxes stay on this phone */
      if (el.type === 'radio') { if (!(el.name in o)) o[el.name] = ''; if (el.checked) o[el.name] = el.value; }
      else o[el.name] = String(el.value || '').trim();
    });
    return o;
  }
  var label = btn.textContent;
  /* Sent: the button says so and stays off, so a second tap cannot file the form twice. "Clear form" frees it. */
  function applied(ref) { status(U.applied.split('{ref}').join(ref), 'ok'); btn.textContent = U.sentBtn; btn.disabled = true; }
  var lastRef = lsGet(REFKEY);
  if (lastRef && !blank) applied(lastRef);
  function net(p) {
    var t = new Promise(function (res, rej) { setTimeout(function () { rej({ timeout: true }); }, 25000); });
    return Promise.race([p, t]).then(null, function (e) { throw (e && e.timeout) ? e : { offline: true }; });
  }
  function asJSON(r) { return r.json().then(function (j) { return { s: r.status, j: j }; }, function () { return { s: r.status, j: null }; }); }
  function submit(body, again) {
    return net(fetch(CFG.api, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })).then(asJSON).then(function (g) {
      if (!g.j || !g.j.csrf) return g;
      return net(fetch(CFG.api, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': g.j.csrf, Accept: 'application/json' },
        body: JSON.stringify(body),
      })).then(asJSON);
    }).then(function (res) { return res.s === 419 && !again ? submit(body, true) : res; });
  }
  btn.addEventListener('click', function () {
    clearErrs();
    var f = collect(), first = null;
    CFG.req.forEach(function (n) {
      var v = f[n] || '';
      var bad = n === 'p_phone' ? v.replace(/\D/g, '').length < 10 : !v;
      if (bad) { var b = markErr(n, U.required); if (!first) first = b; }
    });
    if (first) { status(U.applyFail, 'bad'); focusBox(first); return; }
    if (location.protocol === 'file:' || !window.fetch || !window.Promise || navigator.onLine === false) { status(U.applyOffline, 'warn'); return; }
    save();
    btn.disabled = true; status(U.applying, 'busy');
    submit({ type: CFG.type, lang: CFG.lang, fields: f, photo: photoData(), public_ok: !!(pub && pub.checked), website: hp ? hp.value : '' })
      .then(function (res) {
        var j = res.j || {};
        if (res.s === 200 && j.ok && j.ref) { lsSet(REFKEY, String(j.ref)); applied(String(j.ref)); return; }
        if (res.s === 422 && j.fields && typeof j.fields === 'object') {
          var fb = null;
          Object.keys(j.fields).forEach(function (n) { var b = markErr(n, j.fields[n] || U.required); if (!fb) fb = b; });
          status(j.error || U.applyFail, 'bad');
          if (fb) focusBox(fb);
          return;
        }
        status(j.error || U.applyFail, 'bad');
      }, function (e) {
        /* A timeout is not "no internet": the form may already be stored, so the page says not to send it again. */
        if (e && e.timeout) status(U.applyUnsure, 'warn');
        else status(e && e.offline ? U.applyOffline : U.applyFail, e && e.offline ? 'warn' : 'bad');
      })
      .then(function () { if (!lsGet(REFKEY)) btn.disabled = false; });
  });
  document.getElementById('btnClear').addEventListener('click', function () {
    if (!confirm(U.confirmClear)) return;
    lsSet(KEY, null); lsSet(REFKEY, null);
    form.reset(); pimg.removeAttribute('src'); photo.classList.remove('has'); pimg.setAttribute('data-saved', '0');
    clearErrs(); status('', ''); btn.textContent = label; btn.disabled = false;
    fields().forEach(mark); mirrors(); say(U.cleared);
  });
}

/* ================================================================ agreement page */
function page(lg, ty) {
  const L = T[lg];
  const D = L[ty];
  const f = L.f;
  const id = `${ty}-${lg}`;
  const code = `SHG-AGR-${DOC_CODE[ty]}-${lg.toUpperCase()} ${VERSION}`;
  const REQ = REQUIRED[ty];
  const rq = (n) => REQ.includes(n);
  const nSec = D.sections.length;

  const langNav = LANGS.map((x) => x === lg
    ? `<a aria-current="page" lang="${x}">${esc(T[x].langName)}</a>`
    : `<a href="${FILE[ty]}-${x}.html" lang="${x}" hreflang="${x}">${esc(T[x].langName)}</a>`).join('');
  const typeText = (t) => `<span class="ln">${esc(TYPE_LABEL[t][lg])}</span><span class="sn">${esc(TYPE_SHORT[t][lg])}</span>`;
  const typeNav = TYPES.map((t) => t === ty
    ? `<a aria-current="page">${typeText(t)}</a>`
    : `<a href="${FILE[t]}-${lg}.html">${typeText(t)}</a>`).join('');

  /* ---- what you need */
  const need = needList(lg, ty);
  const needBlock = need.length ? `
  <div class="need">
    <div class="need-h"><b>${esc(ui(lg, 'needTitle'))}</b><span>${esc(ui(lg, 'needNote'))}</span></div>
    <ul>${need.map((x, i) => `<li><label><input type="checkbox" class="chk" data-need="${i}"><span>${esc(x)}</span></label></li>`).join('')}</ul>
  </div>` : '';

  /* ---- second party */
  const photoBox = `<label class="photo" id="photo">${ICON.person}<span>${esc(ty === 'org' ? f.photoOrg : f.photo)}</span><span class="no-print tap">${esc(L.ui.tapPhoto)}</span><img alt=""><input type="file" accept="image/*" id="photoIn" aria-label="${esc(ty === 'org' ? f.photoOrg : f.photo)}"><button type="button" class="rm no-print" id="photoRm">${esc(L.ui.remove)}</button></label>`;
  const party = ty === 'org' ? `
  <div class="party">
    <div class="grid">
      ${fld(lg, 'orgName', 'p_name', { ac: 'organization', req: rq('p_name') })}
      ${radios(lg, 'orgType', 'orgTypes', 'p_type')}
      ${fld(lg, 'regNo', 'p_reg', { p: 6, s: 6 })}
      ${fld(lg, 'taxNo', 'p_tax', { p: 6, s: 6 })}
    </div>
    ${photoBox}
  </div>
  <div class="grid more">
    ${fld(lg, 'regAddr', 'p_addr', { area: true, ac: 'street-address' })}
    ${fld(lg, 'offAddr', 'p_office')}
    ${fld(lg, 'mobile', 'p_phone', { p: 4, s: 6, type: 'tel', im: 'tel', ac: 'tel', req: rq('p_phone') })}
    ${fld(lg, 'email', 'p_email', { p: 4, s: 6, type: 'email', im: 'email', ac: 'email' })}
    ${fld(lg, 'repName', 'p_rep', { p: 4, s: 6, ac: 'name', req: rq('p_rep') })}
    ${fld(lg, 'repDesig', 'p_rep_desig', { p: 4, s: 6 })}
    ${fld(lg, 'repPhone', 'p_rep_phone', { p: 4, s: 6, type: 'tel', im: 'tel' })}
    ${fld(lg, 'partnerId', 'p_id', { p: 4, s: 6 })}
    ${fld(lg, 'dirId', 'p_dir_id', { p: 4, s: 6 })}
    ${fld(lg, 'route', 'p_route', { p: 4, s: 6 })}
    ${fld(lg, 'area', 'p_area', { req: rq('p_area') })}
  </div>` : `
  <div class="party">
    <div class="grid">
      ${fld(lg, 'fullName', 'p_name', { ac: 'name', req: rq('p_name') })}
      ${fld(lg, 'dob', 'p_dob', { p: 6, s: 6, type: 'date', ac: 'bday' })}
      ${fld(lg, 'occupation', 'p_occ', { p: 6, s: 6 })}
      ${radios(lg, 'idType', 'idTypes', 'p_idtype')}
    </div>
    ${photoBox}
  </div>
  <div class="grid more">
    ${fld(lg, 'permAddr', 'p_addr', { area: true, ac: 'street-address', req: rq('p_addr') })}
    ${fld(lg, 'curAddr', 'p_addr2')}
    ${fld(lg, 'mobile', 'p_phone', { p: 4, s: 6, type: 'tel', im: 'tel', ac: 'tel', req: rq('p_phone') })}
    ${fld(lg, 'email', 'p_email', { p: 4, s: 6, type: 'email', im: 'email', ac: 'email' })}
    ${fld(lg, 'idNo', 'p_idno', { p: 4, s: 6, req: rq('p_idno') })}
    ${fld(lg, 'panInd', 'p_pan', { p: 4, s: 6 })}
    ${fld(lg, 'agentId', 'p_id', { p: 4, s: 6 })}
    ${fld(lg, 'route', 'p_route', { p: 4, s: 6 })}
    ${fld(lg, 'area', 'p_area', { req: rq('p_area') })}
  </div>`;

  /* ---- part B: sections; the pay section sits in a "Schedule" box, the last one carries the signatures */
  const isPay = (sec) => sec[1].some((c) => /\[\[(org|ind)_/.test(c));
  const clauses = (sec, n) => sec[1].map((c, j) => `<div class="cl"><span class="n">${n}.${j + 1}</span><div class="t">${inline(c)}</div></div>`).join('\n');
  const head = (sec, n) => `<h3 class="sh"><span class="no">${n}</span><span>${esc(sec[0])}</span></h3>`;
  const sections = D.sections.map((sec, i) => {
    const n = i + 1;
    if (n !== nSec) {
      if (isPay(sec)) return `<section class="sec">${head(sec, n)}<div class="sched"><div class="tag">${esc(ui(lg, 'schedule'))}</div>\n${clauses(sec, n)}</div></section>`;
      return `<section class="sec">${head(sec, n)}\n${clauses(sec, n)}</section>`;
    }
    const org = ty === 'org';
    const sl = (label, inner = '', cls = '') => `<div class="sl${cls ? ' ' + cls : ''}"><div class="v">${inner}</div><div class="lb">${esc(plain(label))}</div></div>`;
    const agentIdLabel = plain(f.agentId).replace(/\s*\(.*\)\s*$/, '');
    const thumbs = `<div class="thumbs">${[f.thumbR, f.thumbL].map((t) => `<div class="thumb"><span>${esc(t)}</span></div>`).join('')}</div>`;
    const gRow = (i) => {
      const who = i <= 2 ? `${esc(f.gNp)} – ${i}` : `${esc(f.gIn)} – ${i - 2}`;
      const cell = (key, name) => `<div class="gc"><label class="gl" for="i_g${i}_${name}">${esc(f[key])}</label><input id="i_g${i}_${name}" name="g${i}_${name}" autocomplete="off"></div>`;
      return `<div class="gr"><div class="gw">${who}</div>${cell('name', 'name')}${cell('gId', 'id')}${cell('gAddr', 'addr')}<div class="gc gs"><span class="gl">${esc(f.gSign)}</span><div class="gsv"></div></div></div>`;
    };
    return `<section class="sec">${head(sec, n)}
${clauses(sec, n)}</section>
<div class="sigwrap">
  <div class="dp">${sl(f.dateL, mirror('a_date'))}${sl(f.place, mirror('a_place'))}</div>
  <div class="parties">
    <div class="pb has-seal">
      <div class="pb-h">${esc(f.fp)}</div>
      <div class="pb-s">${facts(esc(f.forCo))}</div>
      ${sl(f.sign, '', 'sig')}
      <div class="seal"><span>${esc(f.seal)}</span></div>
      <div class="pb-row">${sl(f.name, mirror('co_sig'))}${sl(f.designation, mirror('co_desig'))}</div>
      <div class="pb-row">${sl(f.dateL)}<div></div></div>
    </div>
    <div class="pb${org ? ' has-seal' : ''}">
      <div class="pb-h">${esc(org ? f.spOrg : f.spInd)}</div>
      <div class="pb-s">${org ? esc(f.forOrg) + ': ' + mirror('p_name') : '&nbsp;'}</div>
      ${sl(f.sign, '', 'sig')}
      ${org ? `<div class="seal"><span>${esc(f.sealOrg)}</span></div>` : ''}
      <div class="pb-row">${org ? sl(f.name, mirror('p_rep')) + sl(f.designation, mirror('p_rep_desig')) : sl(f.name, mirror('p_name')) + sl(agentIdLabel, mirror('p_id'))}</div>
      <div class="pb-row">${sl(f.dateL)}<div></div></div>
      ${thumbs}
    </div>
  </div>
  <div class="parties wit">
    ${[1, 2].map((w) => `<div class="pb">
      <div class="pb-h">${esc(f.witness)} – ${w}</div>
      <div class="wrow"><div class="f"><label for="i_w${w}_name">${esc(f.name)}</label><input id="i_w${w}_name" name="w${w}_name" autocomplete="off"></div><div class="f"><label for="i_w${w}_addr">${esc(f.address)}</label><input id="i_w${w}_addr" name="w${w}_addr" autocomplete="off"></div></div>
      ${sl(f.sign, '', 'sig')}
    </div>`).join('\n    ')}
  </div>
  <div class="guar">
    <div class="g-h">${esc(f.gHead)}</div>
    <div class="gt">
      <div class="gr gh"><div class="gw"></div><div class="gc">${esc(f.name)}</div><div class="gc">${esc(f.gId)}</div><div class="gc">${esc(f.gAddr)}</div><div class="gc">${esc(f.gSign)}</div></div>
      ${[1, 2, 3, 4].map(gRow).join('\n      ')}
    </div>
  </div>
  <div class="bar"><span>${ICON.web}${esc(CO.web)}</span><span>${ICON.phone}${esc(CO.phone)}</span></div>
</div>`;
  }).join('\n');

  const coOffice = (T.en.co && T.en.co.office) || CO.headerAddr;
  const cfg = {
    id, type: ty, lang: lg, api: API_APPLY, req: REQ,
    ui: {
      saved: L.ui.saved, cleared: L.ui.cleared, confirmClear: L.ui.confirmClear,
      required: ui(lg, 'required'), applying: ui(lg, 'applying'), applied: ui(lg, 'applied'),
      applyFail: ui(lg, 'applyFail'), applyOffline: ui(lg, 'applyOffline'),
      applyUnsure: ui(lg, 'applyUnsure'), sentBtn: ui(lg, 'sentBtn'),
    },
  };

  return `<!DOCTYPE html>
<html lang="${lg}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
<meta name="theme-color" content="#0c2250">
<title>${esc(D.title)} — S Hari Global Pvt. Ltd.</title>
${FONTS}
<style>
:root{--navy:#0c2250;--saffron:#ee7a1b;--ink:#161c2b;--muted:#566075;--rule:#c8d0de;--hair:#dfe4ec;--fill:#f6f8fc;--boxb:#97a3b8;--paper:#fff;
  --fs:10pt;--sans:"Noto Sans","Noto Sans Devanagari","Noto Sans Gujarati","Nirmala UI","Mangal","Shruti",Arial,sans-serif;--brand:"Poppins","Noto Sans","Noto Sans Devanagari","Noto Sans Gujarati",Arial,sans-serif}
html[lang=ne],html[lang=hi],html[lang=gu]{--fs:10.3pt}
html.bw{--navy:#000;--saffron:#000;--ink:#000;--muted:#333;--rule:#8a8a8a;--hair:#c4c4c4;--fill:#fff;--boxb:#6a6a6a}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{font-family:var(--sans);color:var(--ink);background:#e8ecf2;line-height:1.5;-webkit-print-color-adjust:exact;print-color-adjust:exact;-webkit-text-size-adjust:100%;text-size-adjust:100%}
.doc{position:relative;font-size:var(--fs);overflow-wrap:break-word}
.wm{display:none;position:fixed;left:50%;top:52%;width:160mm;transform:translate(-50%,-50%);opacity:.025;z-index:-1;pointer-events:none}
/* ---------- letterhead ---------- */
.lh{display:grid;grid-template-columns:38mm minmax(0,1fr) auto;align-items:center;gap:0 5mm;padding-top:1mm}
.lh-logo{width:38mm;height:auto;display:block}
html.bw .lh-logo{filter:grayscale(1) contrast(1.6) brightness(1.08)}
.lh-nm{font:700 19pt/1.2 var(--brand);color:var(--navy);letter-spacing:.01em;white-space:nowrap}
.lh-nm span{font-size:10.5pt;color:var(--saffron);margin-left:1.2mm;letter-spacing:.05em}
.lh-ad{font:500 7.9pt/1.45 var(--brand);color:var(--muted);margin-top:1.6mm}
.lh-meta{font:500 7.8pt/1.6 var(--brand);color:var(--muted);text-align:right;white-space:nowrap}
.lh-meta b{font-weight:600;color:var(--navy)}
.lh-rule{position:relative;height:.4mm;background:var(--navy);margin:3.2mm 0 0}
.lh-rule::before{content:"";position:absolute;left:0;top:-.4mm;width:24mm;height:1.2mm;border-radius:.6mm;background:var(--saffron)}
/* ---------- title ---------- */
.title{text-align:center;margin:5.2mm 0 3.4mm}
.title h1{margin:0;font-size:14.5pt;line-height:1.3;font-weight:700;color:var(--navy)}
html[lang=en] .title h1{letter-spacing:.03em}
.title p{margin:.8mm 0 0;font-size:9.5pt;color:var(--muted)}
.title::after{content:"";display:block;width:16mm;height:.8mm;border-radius:.4mm;background:var(--saffron);margin:2.6mm auto 0}
.intro{margin:0 0 1mm}
.req{margin:0;color:var(--muted);font-size:.84em}
/* ---------- parts, headings ---------- */
.pt{display:flex;align-items:center;gap:2.6mm;margin:4.8mm 0 2.6mm;font-size:.95em;font-weight:700;color:var(--navy);line-height:1.3;break-after:avoid}
html[lang=en] .pt{letter-spacing:.07em}
.pt::before{content:"";flex:none;width:1.4mm;height:4.6mm;border-radius:.4mm;background:var(--saffron)}
.pt::after{content:"";flex:1;min-width:8mm;height:.3mm;background:var(--navy)}
.sub{display:flex;align-items:center;gap:2mm;margin:3.6mm 0 1.5mm;font-size:.98em;font-weight:700;color:var(--navy);line-height:1.3;break-after:avoid}
.sub .k{flex:none;min-width:4.8mm;height:4.8mm;padding:0 1.1mm;border:.3mm solid var(--navy);border-radius:2.4mm;display:inline-flex;align-items:center;justify-content:center;font:700 7.8pt/1 "Noto Sans",Arial,sans-serif}
/* ---------- checklist ---------- */
.need{border:.3mm solid var(--rule);border-left:1.2mm solid var(--saffron);border-radius:1.6mm;background:var(--fill);padding:2.4mm 3.4mm 2.6mm;break-inside:avoid}
.need-h{display:flex;flex-wrap:wrap;align-items:baseline;gap:.4mm 3mm;margin:0 0 1.6mm}
.need-h b{color:var(--navy)}
.need-h span{color:var(--muted);font-size:.84em}
.need ul{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1.1mm 6mm}
.need label{display:flex;align-items:flex-start;gap:2mm;font-size:.9em;line-height:1.4;cursor:pointer}
.need .chk{margin-top:.35em}
/* ---------- fields ---------- */
.grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:2mm 3.2mm}
.grid.more{margin-top:2mm}
.f{display:flex;flex-direction:column;min-width:0;grid-column:span var(--p,12);break-inside:avoid}
.f>label,.f>.lb{display:block;margin:0 0 .8mm;font-size:8pt;font-weight:600;color:var(--muted);line-height:1.25}
.rq{color:var(--saffron);font-weight:700;margin-left:.7mm}
.f input:not([type=radio]):not([type=checkbox]),.f textarea,.f .fixed{font:inherit;font-size:.96em;color:var(--ink);width:100%;min-width:0;height:7.2mm;margin:0;padding:0 2mm;border:.25mm solid var(--boxb);border-radius:1mm;background:transparent}
.f textarea{height:11.5mm;padding:1.2mm 2mm;line-height:1.4;resize:vertical}
.f .fixed{display:flex;align-items:center;height:auto;min-height:7.2mm;padding:.8mm 2mm;font-weight:700;line-height:1.3;border-color:var(--rule);background:var(--fill)}
.opts{display:flex;flex-wrap:wrap;align-items:center;gap:1mm 5mm;min-height:7.2mm;padding:1mm 2.4mm;border:.25mm solid var(--boxb);border-radius:1mm}
.opt{display:inline-flex;align-items:center;gap:1.6mm;font-size:.95em;line-height:1.3;cursor:pointer}
.opt input,.rd,.chk{-webkit-appearance:none;appearance:none;flex:none;display:inline-block;width:3.8mm;height:3.8mm;margin:0;border:.3mm solid var(--boxb);border-radius:.7mm;background:#fff center/80% no-repeat;vertical-align:-.7mm;cursor:pointer}
.opt input:checked,.rd:checked,.chk:checked{border-color:var(--navy);background-color:var(--navy);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath d='M3.2 8.4l3.1 3.1 6.5-6.7' fill='none' stroke='%23fff' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E")}
.rd{margin:0 1mm 0 1.4mm}
input.in{font:inherit;font-weight:700;color:var(--ink);height:5.2mm;margin:0 .8mm;padding:0 1mm;border:.25mm solid var(--boxb);border-radius:.8mm;background:#fff;text-align:center;vertical-align:middle;min-width:0}
.nw{white-space:nowrap}
.ro{display:inline-flex;align-items:center;gap:1.2mm;white-space:nowrap;margin:0 0 0 1mm;cursor:pointer}
.ro .rd{margin:0}
.kb{margin:0 .6mm 0 .8mm}
/* ---------- company block ---------- */
.co{border:.3mm solid var(--rule);border-radius:1.6mm;background:var(--fill);padding:2.4mm 3.4mm 3mm}
.kv{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.7mm 6mm;margin:0 0 2.4mm}
.kv div{display:flex;align-items:baseline;gap:2.4mm;min-width:0}
.kv .w{grid-column:span 2}
.kv dt{flex:none;width:24mm;font-size:8pt;font-weight:600;color:var(--muted)}
.kv dd{margin:0;min-width:0;font-weight:700;font-size:.92em}
.co .f input:not([type=radio]):not([type=checkbox]){background:#fff}
/* ---------- second party ---------- */
.party{display:grid;grid-template-columns:minmax(0,1fr) 35mm;gap:0 4.5mm;align-items:start;break-inside:avoid}
.photo{position:relative;width:35mm;height:45mm;margin-top:4mm;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1mm;padding:2mm;border:.3mm dashed var(--boxb);border-radius:1.2mm;background:#fff;text-align:center;font-size:7.8pt;line-height:1.3;color:var(--muted);overflow:hidden;cursor:pointer}
.photo svg{width:9mm;height:9mm;opacity:.45}
.photo img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:none}
.photo.has img{display:block}
.photo input{position:absolute;inset:0;opacity:0;cursor:pointer;font-size:0}
.photo .rm{display:none}
/* ---------- part B ---------- */
.agree{margin:0 0 .5mm;font-weight:700}
.sh{display:flex;align-items:center;gap:3.7mm;margin:3.8mm 0 1.5mm;padding:0 0 1.1mm;border-bottom:.25mm solid var(--hair);font-size:1.02em;font-weight:700;color:var(--navy);line-height:1.3;break-after:avoid;break-inside:avoid}
.sh .no{flex:none;width:5.8mm;height:5.8mm;border-radius:50%;background:var(--navy);color:#fff;display:inline-flex;align-items:center;justify-content:center;font:700 8.2pt/1 "Noto Sans",Arial,sans-serif}
.cl{display:grid;grid-template-columns:9.5mm minmax(0,1fr);margin:0 0 1mm;line-height:1.47;break-inside:avoid}
.cl .n{font-weight:700;color:var(--navy);font-size:.94em;padding-top:.08em;font-variant-numeric:tabular-nums}
.cl .t{text-align:left}
.sched{position:relative;margin:2.6mm 0 1.2mm;padding:4mm 3.4mm .6mm 0;border:.3mm solid var(--rule);border-radius:1.6mm;background:var(--fill);break-inside:avoid}
.sched .cl{grid-template-columns:9.5mm minmax(0,1fr)}
.sched .cl .n{padding-left:2.4mm}
.sched .cl{line-height:1.6}
.sched .cl .t{text-align:left}
.sched .tag{position:absolute;top:0;left:3mm;transform:translateY(-50%);padding:.1mm 2mm;border:.3mm solid var(--rule);border-radius:1mm;background:#fff;font-size:7.8pt;font-weight:700;line-height:1.5;color:var(--saffron)}
html.bw .sched .tag{color:#000}
/* ---------- signatures ---------- */
.sigwrap{break-inside:avoid;page-break-inside:avoid}
.sl{display:flex;flex-direction:column;min-width:0}
.sl .v{display:flex;align-items:flex-end;min-height:6.6mm;padding:0 1mm .5mm;border-bottom:.3mm solid var(--ink);font-weight:700;line-height:1.3}
.sl .lb{margin-top:.8mm;font-size:8pt;font-weight:600;color:var(--muted);line-height:1.2}
.sl.sig .v{min-height:11.5mm}
.mirror{display:inline}
.dp{display:grid;grid-template-columns:1fr 1fr;gap:10mm;margin:2.6mm 0 3mm}
.parties{display:grid;grid-template-columns:1fr 1fr;gap:5mm}
.pb{display:grid;grid-template-columns:minmax(0,1fr);align-content:start;min-width:0;padding:2.4mm 3.6mm 2.8mm;border:.3mm solid var(--rule);border-radius:1.6mm;background:#fff}
.pb.has-seal{grid-template-columns:minmax(0,1fr) 32mm;column-gap:3mm}
.pb .f{grid-column:1 / -1}
.pb-h{grid-column:1 / -1;font-weight:700;color:var(--navy);line-height:1.3}
.pb-s{grid-column:1 / -1;min-height:1.3em;margin-top:.3mm;font-size:.84em;color:var(--muted);line-height:1.3}
.pb-s .mirror{color:var(--ink);font-weight:700}
.pb>.sl.sig{margin-top:.4mm}
.pb.has-seal>.sl.sig{grid-column:1;grid-row:3}
.pb.has-seal>.pb-row{grid-column:1}
.seal{grid-column:2;grid-row:3 / span 3;align-self:center;justify-self:end;width:32mm;height:32mm;display:flex;align-items:center;justify-content:center;padding:2mm;border:.3mm dashed var(--boxb);border-radius:50%;text-align:center;font-size:7.6pt;line-height:1.2;color:var(--muted)}
.pb-row{grid-column:1 / -1;display:grid;grid-template-columns:1fr 1fr;gap:4mm;margin-top:2mm}
.thumbs{grid-column:1 / -1;display:grid;grid-template-columns:1fr 1fr;gap:4mm;margin-top:2.4mm}
.thumb{height:19mm;display:flex;align-items:flex-end;justify-content:center;padding:1mm;border:.3mm dashed var(--boxb);border-radius:1.2mm;font-size:7.6pt;line-height:1.2;color:var(--muted);text-align:center}
.wit{margin-top:3.2mm}
.wrow{display:grid;grid-template-columns:1fr 1fr;gap:4mm;align-items:start;margin:1.2mm 0 .4mm}
.wrow .f{grid-column:auto}
.wit .sl.sig .v{min-height:9mm}
.guar{margin-top:3.2mm;padding:2.4mm 3.6mm 2.8mm;border:.3mm solid var(--rule);border-radius:1.6mm;background:#fff;break-inside:avoid}
.g-h{font-weight:700;color:var(--navy);line-height:1.3;margin:0 0 1.6mm}
.gr{display:grid;grid-template-columns:27mm minmax(0,1fr) 36mm minmax(0,1.2fr) 30mm;gap:0 2mm;align-items:stretch}
.gr+.gr{border-top:.25mm solid var(--hair)}
.gh{font-size:7.8pt;font-weight:600;color:var(--muted);line-height:1.25;padding-bottom:.8mm}
.gw{display:flex;align-items:center;font-size:.86em;font-weight:600;color:var(--navy);line-height:1.25}
.gc{display:flex;flex-direction:column;justify-content:flex-end;min-width:0;padding:.8mm 0}
.gc input{font:inherit;font-size:.92em;height:7mm;width:100%;min-width:0;margin:0;padding:0 1.6mm;border:.25mm solid var(--boxb);border-radius:1mm;background:transparent}
.gl{display:none}
.gs .gsv{height:9mm;border-bottom:.3mm solid var(--ink)}
.bar{display:flex;flex-wrap:wrap;justify-content:center;align-items:center;gap:1mm 14mm;margin-top:3.6mm;padding:1.6mm 4mm;border:.3mm solid var(--rule);border-radius:2mm;background:var(--fill);color:var(--navy);font:600 9.5pt/1.3 var(--brand);letter-spacing:.02em}
.bar span{display:inline-flex;align-items:center;gap:1.8mm}
.bar svg{width:4mm;height:4mm}
html.bw .bar{background:#fff;color:#000;border:.3mm solid #000}
/* ---------- screen: toolbar, sheet, inputs ---------- */
.tb{position:sticky;top:0;z-index:10;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:8px 12px;padding:8px 12px;background:#0c2250;color:#fff;font:500 13.5px/1.2 var(--brand);box-shadow:0 2px 12px rgba(12,34,80,.25)}
.seg{display:inline-flex;border:1px solid rgba(255,255,255,.34);border-radius:999px;overflow:hidden}
.seg a,.seg button{font:inherit;color:#fff;text-decoration:none;background:transparent;border:0;padding:7px 12px;cursor:pointer;white-space:nowrap}
.seg a+a,.seg button+button{border-left:1px solid rgba(255,255,255,.22)}
.seg [aria-current],.seg [aria-pressed="true"]{background:#fff;color:#0c2250;font-weight:600}
.seg .sn{display:none}
@media (max-width:480px){.seg .ln{display:none}.seg .sn{display:inline}}
.seg a[href]:hover,.seg button[aria-pressed="false"]:hover{background:rgba(255,255,255,.12)}
.tb .pri{display:inline-flex;align-items:center;gap:6px;font:600 13.5px/1.2 var(--brand);border:0;border-radius:999px;padding:8px 16px;background:#ee7a1b;color:#fff;cursor:pointer;white-space:nowrap}
.tb .pri svg{width:16px;height:16px}
.tb a:focus-visible,.tb button:focus-visible{outline:2px solid #ffc58f;outline-offset:2px}
.toast{position:fixed;left:50%;bottom:18px;z-index:20;max-width:calc(100vw - 32px);padding:8px 16px;border-radius:999px;background:#0c2250;color:#fff;font:500 14px/1.3 var(--sans);text-align:center;opacity:0;transform:translate(-50%,16px);transition:opacity .2s,transform .2s;pointer-events:none}
.toast.on{opacity:1;transform:translate(-50%,0)}
.apply{margin:6mm 0 1mm;padding:16px 18px 18px;border:1px solid #cdd6e6;border-radius:12px;background:#f5f8fd}
.apply h3{margin:0 0 2px;font-size:1.08em;color:#0c2250}
.apply .note{margin:0 0 12px;color:var(--muted);font-size:.9em}
.pub{display:flex;align-items:flex-start;gap:10px;margin:0 0 14px;cursor:pointer;line-height:1.4}
.pub .chk{margin-top:2px}
.pub small{display:block;margin-top:2px;color:var(--muted);font-size:.86em}
.hp{position:absolute!important;left:-10000px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important}
.apply-row{display:flex;flex-wrap:wrap;align-items:center;gap:10px 18px}
.btn-apply{min-height:48px;padding:12px 26px;border:0;border-radius:999px;background:#ee7a1b;color:#fff;font:600 16px/1.2 var(--brand);cursor:pointer;box-shadow:0 3px 10px rgba(238,122,27,.35)}
.btn-apply[disabled]{opacity:.6;cursor:progress}
.btn-apply:focus-visible,.linkbtn:focus-visible{outline:3px solid #0c2250;outline-offset:2px}
.linkbtn{padding:8px 4px;border:0;background:none;color:var(--muted);font:inherit;font-size:.9em;text-decoration:underline;cursor:pointer}
.status{margin:12px 0 0;font-size:.95em;line-height:1.45}
.status:empty{display:none}
.status.busy{color:var(--muted)}
.status.ok{padding:10px 12px;border-radius:8px;background:#e6f5ea;color:#12652b;font-weight:600}
.status.bad{padding:10px 12px;border-radius:8px;background:#fdecec;color:#a3161a;font-weight:600}
.status.warn{padding:10px 12px;border-radius:8px;background:#fff4e0;color:#7a4300;font-weight:600}
.f.bad input,.f.bad textarea{border-color:#c62828!important;box-shadow:0 0 0 3px rgba(198,40,40,.15)}
.f .err{margin-top:4px;color:#c62828;font-size:12.5px;font-weight:600;line-height:1.3}
.foot-scr{max-width:210mm;margin:0 auto 32px;padding:0 16px;text-align:center;color:#56627c;font:13px/1.6 var(--sans)}
.foot-scr a{color:#0c2250;font-weight:600}
@media screen{
  .doc{max-width:210mm;margin:18px auto 22px;padding:14mm 13mm 15mm;background:#fff;border-radius:4px;box-shadow:0 1px 2px rgba(12,34,80,.08),0 10px 30px rgba(12,34,80,.12);font-size:14.5px}
  .f input:not([type=radio]):not([type=checkbox]),.f textarea{height:42px;padding:0 10px;border:1px solid #c2cbdb;border-radius:8px;background:#fff;font-size:16px}
  .f textarea{height:auto;min-height:66px;padding:8px 10px}
  .f .fixed{min-height:42px;padding:8px 10px;border-radius:8px;font-size:15px}
  .f input:focus,.f textarea:focus,input.in:focus{outline:2px solid #ee7a1b;outline-offset:1px;border-color:#0c2250}
  .f>label,.f>.lb{margin-bottom:4px;font-size:13px}
  .opts{min-height:42px;gap:4px 18px;padding:6px 10px;border:1px solid #c2cbdb;border-radius:8px}
  .opt{padding:4px 0;font-size:15px}
  .opt input,.rd,.chk{width:20px;height:20px;border-width:1.5px;border-radius:5px}
  .opt input:focus-visible,.rd:focus-visible,.chk:focus-visible{outline:2px solid #ee7a1b;outline-offset:2px}
  input.in{height:1.8em;border:1px solid #ee7a1b;border-radius:6px;background:#fff8ef;font-size:1em}
  .kv dt,.sl .lb{font-size:12.5px}
  .photo{font-size:12px}
  .photo:hover{border-color:#0c2250}
  .photo.has .rm{display:block;position:absolute;left:50%;bottom:4px;z-index:2;transform:translateX(-50%);padding:3px 8px;border:0;border-radius:10px;background:rgba(0,0,0,.6);color:#fff;font-size:12px}
  .photo:focus-within{outline:2px solid #ee7a1b;outline-offset:2px}
  .sched .tag,.seal,.thumb{font-size:12px}
  .gc input:focus{outline:2px solid #ee7a1b;outline-offset:1px;border-color:#0c2250}
}
@media screen and (min-width:721px){
  .f{grid-column:span var(--s,var(--p,12))}
}
@media screen and (max-width:720px){
  body{background:#fff}
  .tb{position:static;gap:8px;padding:8px}
  .seg-theme{order:1}.seg-type{order:2}.tb .pri{order:3}
  .lb-empty{display:none!important}
  .doc{margin:0;padding:18px 16px 26px;border-radius:0;box-shadow:none;font-size:15.5px}
  .lh{grid-template-columns:1fr;justify-items:center;gap:6px;text-align:center}
  .lh-logo{width:150px}
  .lh-nm{font-size:clamp(20px,6.4vw,27px);white-space:normal}
  .lh-nm span{font-size:.56em}
  .lh-ad{margin-top:4px;font-size:12.5px}
  .lh-meta{display:flex;flex-wrap:wrap;justify-content:center;gap:0 12px;text-align:center;white-space:normal;font-size:12px}
  .lh-rule{margin-top:12px}
  .title{margin:20px 0 14px}
  .title h1{font-size:19px}
  .title p{font-size:14px}
  .pt{margin:24px 0 12px;font-size:14px}
  .sub{margin:22px 0 10px}
  .sub .k{width:22px;height:22px;font-size:12px}
  .grid{grid-template-columns:minmax(0,1fr);gap:14px}
  .grid.more{margin-top:14px}
  .f{grid-column:1/-1}
  .need ul{grid-template-columns:minmax(0,1fr);gap:10px}
  .need label{font-size:15px}
  .kv{grid-template-columns:minmax(0,1fr);gap:8px}
  .kv .w{grid-column:auto}
  .kv div{flex-direction:column;gap:0}
  .kv dt{width:auto}
  .party{grid-template-columns:minmax(0,1fr)}
  .photo{width:124px;height:156px;margin:16px auto 0}
  .sh{gap:10px;font-size:16px}
  .sh .no{width:26px;height:26px;font-size:12px}
  .cl{grid-template-columns:38px minmax(0,1fr)}
  .cl .t{text-align:left}
  .sched{padding-right:10px}
  .sched .cl{grid-template-columns:44px minmax(0,1fr)}
  .sched .cl .n{padding-left:10px}
  .dp,.parties,.pb-row,.wrow{grid-template-columns:minmax(0,1fr)}
  .pb.has-seal{grid-template-columns:minmax(0,1fr) 112px}
  .seal{width:108px;height:108px}
  .thumb{height:84px}
  .gt .gh{display:none}
  .gr{grid-template-columns:minmax(0,1fr);gap:8px;padding:12px 0}
  .gw{font-size:15px}
  .gl{display:block;margin:0 0 4px;font-size:12.5px;font-weight:600;color:var(--muted)}
  .gc input{height:42px;padding:0 10px;border:1px solid #c2cbdb;border-radius:8px;background:#fff;font-size:16px}
  .gs .gsv{height:40px}
  .wrow{gap:14px;margin-bottom:14px}
  .wrow .sl.sig .v{min-height:48px}
  .dp{gap:14px}
  .parties{gap:14px}
  .bar{flex-direction:column;gap:6px;font-size:15px;border-radius:14px}
  .apply{padding:14px}
  .btn-apply{width:100%}
}
@media screen and (max-width:420px){
  .tb{font-size:12.5px;gap:6px}
  .seg a,.seg button{padding:7px 9px}
  .tb .pri{padding:8px 12px;font-size:12.5px}
  .doc{padding:16px 14px 24px}
}
/* ---------- print ---------- */
@page{size:A4;margin:14mm 13mm 16mm 13mm;
  @top-left{content:"S HARI GLOBAL PVT. LTD.";font:600 7.8pt "Poppins","Noto Sans",Arial,sans-serif;color:#555;vertical-align:bottom;padding-bottom:3.5mm}
  @top-right{content:${cssStr(D.title)};font:400 7.8pt "Noto Sans","Noto Sans Devanagari","Noto Sans Gujarati","Nirmala UI",Arial,sans-serif;color:#555;vertical-align:bottom;padding-bottom:3.5mm}
  @bottom-left{content:${cssStr(L.ui.initials)};font:400 7.8pt "Noto Sans","Noto Sans Devanagari","Noto Sans Gujarati","Nirmala UI",Arial,sans-serif;color:#333;vertical-align:top;padding-top:4mm;white-space:pre}
  @bottom-center{content:${cssStr(code)};font:400 7.6pt "Noto Sans",Arial,sans-serif;color:#666;vertical-align:top;padding-top:4mm}
  @bottom-right{content:${cssStr(L.ui.page + ' ')} counter(page) " / " counter(pages);font:400 7.8pt "Noto Sans","Noto Sans Devanagari","Noto Sans Gujarati","Nirmala UI",Arial,sans-serif;color:#333;vertical-align:top;padding-top:4mm}
}
@page:first{@top-left{content:none}@top-right{content:none}}
@media print{
  body{background:#fff}
  .no-print,.tb,.toast,.foot-scr{display:none!important}
  .doc{margin:0;padding:0}
  html:not(.bw) .wm{display:block}
  ::placeholder{color:transparent}
  input.empty::-webkit-datetime-edit{color:transparent}
  input::-webkit-calendar-picker-indicator{display:none}
  .f textarea{overflow:hidden;resize:none}
  .photo{cursor:default}
}
</style>
</head>
<body>
<nav class="tb no-print" aria-label="${esc(D.type)}">
  <div class="seg seg-lang">${langNav}</div>
  <div class="seg seg-type">${typeNav}</div>
  <div class="seg seg-theme" role="group"><button type="button" data-theme="colour" aria-pressed="true">${esc(L.ui.colour)}</button><button type="button" data-theme="bw" aria-pressed="false">${esc(L.ui.bw)}</button></div>
  <button type="button" class="pri" id="btnPrint">${ICON.print}${esc(L.ui.print)}</button>
</nav>
<img class="wm" src="${BUS}" alt="">
<form class="doc" id="doc" autocomplete="on" onsubmit="return false" novalidate>
  <header class="lh">
    <img class="lh-logo" src="${LOGO}" alt="S Hari Global">
    <div class="lh-id">
      <div class="lh-nm">S HARI GLOBAL<span>PVT. LTD.</span></div>
      <div class="lh-ad">${lg === 'en' || lg === 'ne' ? fact('head.address', coOffice) : esc(coOffice)}</div>
    </div>
    <div class="lh-meta"><div><b>CIN</b> ${fact('brand.cin', CO.cin)}</div><div><b>PAN</b> ${esc(CO.pan)}</div><div>${fact('brand.web', CO.web)}</div><div>${fact('head.phone', CO.phone)}</div></div>
  </header>
  <div class="lh-rule"></div>
  <div class="title"><h1>${esc(D.title)}</h1><p>${esc(D.sub)}</p></div>
  <p class="intro">${esc(D.intro)}</p>
  <p class="req">${esc(L.ui.reqNote)}</p>

  <h2 class="pt">${esc(L.ui.partA)}</h2>
${needBlock}
  ${subHead(f.a1)}
  <div class="grid">
    ${fixed(f.agrType, D.type, 5, 12)}
    ${fld(lg, 'agrNo', 'a_no', { p: 2, s: 4 })}
    ${fld(lg, 'date', 'a_date', { p: 2, s: 4, type: 'date' })}
    ${fld(lg, 'place', 'a_place', { p: 3, s: 4 })}
  </div>

  ${subHead(f.a2)}
  <div class="co">
    <dl class="kv">
      <div class="w"><dt>${esc(f.coName)}</dt><dd>${fact('brand.legal', CO.name)}</dd></div>
      <div><dt>${esc(f.cin)}</dt><dd>${fact('brand.cin', CO.cin)}</dd></div>
      <div><dt>${esc(f.pan)}</dt><dd>${esc(CO.pan)}</dd></div>
      <div class="w"><dt>${esc(f.office)}</dt><dd>${lg === 'en' ? fact('head.address', L.co.office) : lg === 'ne' ? fact('head.addressNe', L.co.office) : esc(L.co.office)}</dd></div>
      <div><dt>${esc(f.web)}</dt><dd>${fact('brand.web', CO.web)}</dd></div>
      <div><dt>${esc(f.phone)}</dt><dd>${fact('head.phone', CO.phone)}</dd></div>
    </dl>
    <div class="grid">
      ${fld(lg, 'coGst', 'co_gst', { p: 6, s: 6, ac: 'off' })}
      ${fld(lg, 'coEmail', 'co_email', { p: 6, s: 6, type: 'email', im: 'email', ac: 'off' })}
      ${fld(lg, 'sigName', 'co_sig', { p: 6, s: 6, ac: 'off' })}
      ${fld(lg, 'designation', 'co_desig', { p: 6, s: 6, ac: 'off' })}
    </div>
  </div>

  ${subHead(ty === 'org' ? f.a3org : f.a3ind)}
${party}

  ${subHead(f.a4)}
  <div class="grid">
    ${fld(lg, 'start', 'a_start', { p: 3, s: 6, type: 'date' })}
    ${fld(lg, 'end', 'a_end', { p: 3, s: 6, type: 'date' })}
    ${fixed('\u00a0', f.termNote, 6, 12)}
  </div>

  ${subHead(f.a5)}
  <div class="grid">
    ${fld(lg, 'accName', 'b_name', { p: 6, s: 6 })}
    ${fld(lg, 'bank', 'b_bank', { p: 6, s: 6 })}
    ${fld(lg, 'accNo', 'b_acc', { p: 4, s: 6, im: 'numeric' })}
    ${fld(lg, 'ifsc', 'b_ifsc', { p: 4, s: 6 })}
    ${fld(lg, 'upi', 'b_upi', { p: 4, s: 12 })}
  </div>

  <section class="apply no-print" id="apply" aria-labelledby="applyHead">
    <h3 id="applyHead">${esc(ui(lg, 'applyHead'))}</h3>
    <p class="note">${esc(ui(lg, 'applyNote'))}</p>
    <label class="pub"><input type="checkbox" class="chk" id="pubOk"><span>${esc(ui(lg, 'publicOk'))}<small>${esc(ui(lg, 'publicOkNote'))}</small></span></label>
    <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off" value=""></label></div>
    <div class="apply-row"><button type="button" class="btn-apply" id="btnApply">${esc(ui(lg, 'apply'))}</button><button type="button" class="linkbtn" id="btnClear">${esc(L.ui.clear)}</button></div>
    <p class="status" id="applyMsg" role="status" aria-live="polite"></p>
  </section>

  <h2 class="pt">${esc(L.ui.partB)}</h2>
  <p class="agree">${esc(L.ui.agreeLine)}</p>
${sections}
</form>
<p class="foot-scr no-print"><a href="index.html">${esc(ui(lg, 'all'))}</a> · ${fact('brand.legal', CO.name)} · CIN ${fact('brand.cin', CO.cin)} · ${fact('brand.web', CO.web)} · ${fact('head.phone', CO.phone)}</p>
<div class="toast no-print" id="toast" role="status" aria-live="polite"></div>
<script>
(${agreementPage.toString()})(${js(cfg)});
</script>
<script src="/assets/js/agreement-live.js?v=20261010t" defer></script>
</body>
</html>
`;
}

/* ================================================================ client: landing page */
function agreementIndex(CFG) {
  var root = document.documentElement, cur = 'ne', items = null;
  function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function each(sel, fn) { [].forEach.call(document.querySelectorAll(sel), fn); }
  var sec = document.getElementById('dir'), list = document.getElementById('dirList'), empty = document.getElementById('dirEmpty');
  var count = document.getElementById('dirCount'), q = document.getElementById('dirQ');
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function render() {
    var S = CFG.s[cur];
    q.placeholder = S.search; q.setAttribute('aria-label', S.search);
    if (!items) return;
    var term = (q.value || '').trim().toLowerCase();
    list.textContent = '';
    var shown = items.filter(function (it) {
      return !term || (String(it.name || '') + ' ' + String(it.area || '')).toLowerCase().indexOf(term) >= 0;
    });
    shown.forEach(function (it) {
      var li = el('li'), top = el('div', 'who');
      top.appendChild(el('b', null, String(it.name || '')));
      top.appendChild(el('span', 'kind', it.kind === 'partner' ? S.partner : S.agent));
      li.appendChild(top);
      var meta = el('div', 'meta');
      if (it.area) meta.appendChild(el('span', 'area', String(it.area)));
      var ph = String(it.phone || '');
      if (ph) {
        var digits = ph.replace(/[^\d+]/g, '');
        if (/^\+?\d{10,15}$/.test(digits) && ph.indexOf('\u2022') < 0) {
          var a = el('a', 'ph', ph); a.href = 'tel:' + digits; meta.appendChild(a);
        } else meta.appendChild(el('span', 'ph', ph));
      }
      li.appendChild(meta);
      if (it.verified || it.signed) {
        var bd = el('div', 'badges');
        if (it.verified) bd.appendChild(el('span', 'bd v', S.verified));
        if (it.signed) bd.appendChild(el('span', 'bd s', S.signed));
        li.appendChild(bd);
      }
      list.appendChild(li);
    });
    count.textContent = items.length ? String(items.length) : '';
    empty.hidden = shown.length > 0;
    q.parentNode.hidden = items.length < 8;
  }
  function setLang(l) {
    if (!CFG.s[l]) l = 'ne';
    cur = l; root.setAttribute('data-ui', l); root.lang = l;
    each('[data-setlang]', function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-setlang') === l ? 'true' : 'false'); });
    lsSet('shg-agreements-lang', l);
    render();
  }
  each('[data-setlang]', function (b) { b.addEventListener('click', function () { setLang(b.getAttribute('data-setlang')); }); });
  q.addEventListener('input', render);
  var m = /[?&]lang=(ne|hi|en|gu)\b/.exec(location.search);
  setLang(m ? m[1] : (lsGet('shg-agreements-lang') || 'ne'));
  if (location.protocol === 'file:' || !window.fetch) return;
  fetch(CFG.api, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
    .then(function (j) {
      if (!j || !j.ok || !Array.isArray(j.items)) throw new Error('bad');
      items = j.items; sec.hidden = false; render();
    })
    .catch(function () { sec.hidden = true; });
}

/* ================================================================ landing page */
function indexPage() {
  const t3 = (m) => LANGS.map((l) => `<span data-l="${l}" lang="${l}">${esc(m[l])}</span>`).join('');
  const t3ui = (k) => t3(Object.fromEntries(LANGS.map((l) => [l, ui(l, k)])));
  const anyNeed = TYPES.some((ty) => LANGS.some((l) => needList(l, ty).length));
  const typeCard = (ty) => `
  <section class="card type" id="${ty === 'org' ? 'partner' : 'agent'}">
    <div class="kicker">${t3(TYPE_LABEL[ty])}</div>
    <h2>${t3(Object.fromEntries(LANGS.map((l) => [l, T[l][ty].title])))}</h2>
    <ul class="desc">${LANGS.map((l) => `<li lang="${l}">${esc(DESC[ty][l])}</li>`).join('')}</ul>
    <div class="go" role="group">${LANGS.map((l) => `<a class="btn" href="${FILE[ty]}-${l}.html" hreflang="${l}"><b lang="${l}">${esc(T[l].langName)}</b><small>${t3(IDX.open)}</small></a>`).join('')}</div>
  </section>`;
  const needCard = anyNeed ? `
  <section class="card need">
    <h2>${t3ui('needTitle')}</h2>
    <p class="muted">${t3ui('needNote')}</p>
    <div class="cols">${TYPES.map((ty) => `
      <div><h3>${t3(TYPE_LABEL[ty])}</h3>${LANGS.map((l) => `<ul data-l="${l}" lang="${l}">${needList(l, ty).map((x) => `<li>${esc(x)}</li>`).join('')}</ul>`).join('')}</div>`).join('')}
    </div>
  </section>` : '';
  const cfg = {
    api: API_DIR,
    s: Object.fromEntries(LANGS.map((l) => [l, {
      verified: ui(l, 'verified'), signed: ui(l, 'signed'), search: IDX.search[l], agent: IDX.agent[l], partner: IDX.partner[l],
    }])),
  };
  return `<!DOCTYPE html>
<html lang="ne" data-ui="ne">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
<meta name="theme-color" content="#0c2250">
<title>सम्झौता · Agreements — S Hari Global Pvt. Ltd.</title>
${FONTS}
<style>
:root{--navy:#0c2250;--navy2:#1a3a7c;--saffron:#ee7a1b;--ink:#161c2b;--muted:#566075;--line:#dde3ec;--bg:#f2f5f9;--card:#fff;
  --sans:"Noto Sans","Noto Sans Devanagari","Noto Sans Gujarati","Nirmala UI",Arial,sans-serif;--brand:"Poppins","Noto Sans","Noto Sans Devanagari","Noto Sans Gujarati",Arial,sans-serif}
html[data-ui=ne] [data-l]:not([data-l=ne]),html[data-ui=hi] [data-l]:not([data-l=hi]),html[data-ui=en] [data-l]:not([data-l=en]),html[data-ui=gu] [data-l]:not([data-l=gu]){display:none!important}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{background:var(--bg);color:var(--ink);font:16px/1.55 var(--sans);-webkit-text-size-adjust:100%;text-size-adjust:100%;overflow-wrap:break-word}
a{color:var(--navy)}
.wrap{max-width:820px;margin:0 auto;padding:0 16px}
.top{background:#fff;border-bottom:1px solid var(--line)}
.top .wrap{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px 16px;padding-top:12px;padding-bottom:12px}
.brand{display:flex;align-items:center;gap:12px;text-decoration:none;color:var(--navy);min-width:0}
.brand img{width:92px;height:auto;flex:none}
.brand b{display:block;font:700 19px/1.1 var(--brand);letter-spacing:.01em}
.brand b i{font-style:normal;font-size:.6em;color:var(--saffron);letter-spacing:.05em;margin-left:4px}
.brand small{display:block;margin-top:3px;color:var(--muted);font:500 12px/1.3 var(--brand)}
.seg{display:inline-flex;border:1px solid #c9d2e1;border-radius:999px;overflow:hidden;background:#fff}
.seg button{font:500 14px/1.2 var(--sans);border:0;background:transparent;color:var(--navy);padding:8px 13px;cursor:pointer}
.seg button+button{border-left:1px solid #dde3ec}
.seg button[aria-pressed=true]{background:var(--navy);color:#fff;font-weight:600}
.seg button:focus-visible,.btn:focus-visible,.links a:focus-visible{outline:3px solid var(--saffron);outline-offset:2px}
.hero{background:linear-gradient(180deg,#fff,var(--bg));padding:26px 0 8px}
.hero h1{margin:0;font:700 clamp(24px,5vw,32px)/1.2 var(--brand);color:var(--navy)}
.hero h1::after{content:"";display:block;width:44px;height:4px;border-radius:2px;background:var(--saffron);margin-top:12px}
.hero p{margin:12px 0 0;color:var(--muted);max-width:60ch}
main.wrap{padding-bottom:32px}
.types{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:18px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:0 1px 2px rgba(12,34,80,.05)}
.card+.card,.types+.card{margin-top:16px}
.kicker{font:600 12.5px/1.3 var(--brand);color:var(--saffron);letter-spacing:.02em}
.card h2{margin:4px 0 8px;font-size:18px;line-height:1.35;color:var(--navy)}
.desc{list-style:none;margin:0 0 14px;padding:0;color:var(--muted);font-size:14.5px}
.desc li{padding:3px 0 3px 12px;border-left:2px solid var(--line)}
.desc li+li{margin-top:4px}
.go{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.btn{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:56px;padding:9px 6px;border-radius:12px;background:var(--navy);color:#fff;text-decoration:none;text-align:center;line-height:1.25}
.btn:hover{background:var(--navy2)}
.btn b{font-size:16px}
.btn small{margin-top:2px;font-size:12px;opacity:.85}
.muted{color:var(--muted);margin:0 0 12px;font-size:14.5px}
.need .cols{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 24px}
.need h3{margin:0 0 6px;font-size:15px;color:var(--navy)}
.need ul{margin:0;padding:0;list-style:none}
.need li{position:relative;padding:5px 0 5px 28px;font-size:15px;line-height:1.45;border-bottom:1px dashed var(--line)}
.need li:last-child{border-bottom:0}
.need li::before{content:"";position:absolute;left:2px;top:8px;width:15px;height:15px;border:1.5px solid var(--navy);border-radius:4px}
.dir h2{display:flex;align-items:center;gap:10px}
.dir h2 .count:not(:empty){font:600 13px/1 var(--sans);background:#eef2f8;color:var(--navy);border-radius:999px;padding:5px 9px}
.search{margin:0 0 10px}
.search input{width:100%;height:44px;padding:0 12px;border:1px solid #c9d2e1;border-radius:10px;font:16px var(--sans)}
#dirList{list-style:none;margin:0;padding:0}
#dirList li{display:grid;gap:2px;padding:11px 0;border-top:1px solid var(--line)}
#dirList li:first-child{border-top:0}
.who{display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 10px}
.who b{font-size:16px}
.kind{font-size:12px;color:var(--muted);border:1px solid var(--line);border-radius:999px;padding:1px 8px}
.meta{display:flex;flex-wrap:wrap;gap:2px 14px;color:var(--muted);font-size:14.5px}
.meta .ph{font-variant-numeric:tabular-nums;letter-spacing:.02em;color:var(--ink)}
.badges{display:flex;flex-wrap:wrap;gap:6px;margin-top:4px}
.bd{font-size:12px;font-weight:600;border-radius:999px;padding:2px 9px}
.bd.v{background:#e6f5ea;color:#12652b}
.bd.s{background:#e9effa;color:var(--navy)}
.links a{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 2px;border-top:1px solid var(--line);color:var(--ink);text-decoration:none}
.links a:first-of-type{border-top:0}
.links a b{color:var(--saffron);font-size:22px;line-height:1}
footer{padding:0 16px 30px;text-align:center;color:var(--muted);font-size:13px}
@media (max-width:640px){
  .types,.need .cols{grid-template-columns:minmax(0,1fr)}
  .top .wrap{justify-content:center}
  .brand img{width:78px}
}
@media (max-width:340px){
  .go{grid-template-columns:minmax(0,1fr)}
  .seg button{padding:8px 10px;font-size:13.5px}
}
</style>
</head>
<body>
<header class="top">
  <div class="wrap">
    <a class="brand" href="/"><img src="${LOGO}" alt=""><span><b>S HARI GLOBAL<i>PVT. LTD.</i></b><small>CIN ${esc(CO.cin)} · Mehsana, Gujarat</small></span></a>
    <div class="seg" role="group" aria-label="भाषा · Language">${LANGS.map((l) => `<button type="button" data-setlang="${l}" lang="${l}" aria-pressed="${l === 'ne'}">${esc(T[l].langName)}</button>`).join('')}</div>
  </div>
</header>
<section class="hero"><div class="wrap"><h1>${t3(IDX.title)}</h1><p>${t3(IDX.lead)}</p></div></section>
<main class="wrap">
  <div class="types">${TYPES.map(typeCard).join('')}
  </div>
${needCard}
  <section class="card dir" id="dir" hidden>
    <h2>${t3ui('dirTitle')}<span class="count" id="dirCount"></span></h2>
    <p class="muted">${t3ui('dirNote')}</p>
    <div class="search" hidden><input type="search" id="dirQ" autocomplete="off"></div>
    <ul id="dirList"></ul>
    <p class="muted" id="dirEmpty" hidden>${t3ui('dirEmpty')}</p>
  </section>
</main>
<footer>${esc(CO.name)} · CIN ${esc(CO.cin)} · ${esc(CO.web)} · ${esc(CO.phone)}</footer>
<script>
(${agreementIndex.toString()})(${js(cfg)});
</script>
</body>
</html>
`;
}

/* ================================================================ write */
let n = 0;
for (const ty of TYPES) for (const lg of LANGS) {
  const html = page(lg, ty);
  const file = path.join(OUT, `${FILE[ty]}-${lg}.html`);
  fs.writeFileSync(file, html, 'utf8');
  n++;
  console.log(`${path.basename(file)}  ${(html.length / 1024).toFixed(0)} KB`);
}
const idx = indexPage();
fs.writeFileSync(path.join(OUT, 'index.html'), idx, 'utf8');
console.log(`index.html  ${(idx.length / 1024).toFixed(0)} KB`);
console.log((n + 1) + ' pages written to ' + OUT);
