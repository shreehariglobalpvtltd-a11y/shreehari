/* =====================================================================
 *  16-lazy.js — the parts of the customer app that no first screen needs.
 *
 *  Split out of 13-admin-routes.js on 11 Sep 2026 (perf pass): the unified
 *  live map + full India/Nepal map (Leaflet, dead since #/track redirected
 *  to #/nav), the Trip Companion (#/trip/PNR, live GPS rail, checklist) and
 *  the SHG Sahayak assistant (rule-based chat, voice search, the agent
 *  application form and the home timetable box). Loaded by shgLazyLoad()
 *  in 13-admin-routes.js: on the first tap that needs it, else on the first
 *  idle moment after `load`. Classic script — same globals as the bundle;
 *  it REPLACES the stubs renderTrip / cleanupTrip / cleanupTracker.
 *  Nothing here runs before the DOM is complete, so no load listeners.
 * ===================================================================== */
/* ================================================================
   [JS] 14B. UNIFIED LIVE MAP — ONE Leaflet + OpenStreetMap map for
   the whole site (replaces the old MapLibre tracker, 3D Drive and
   Analog Map). Leaflet is lazy-loaded ONLY when the map container
   scrolls into view. Road geometry is fetched once per session from
   the free OSRM demo router (cached in sessionStorage); if OSRM or
   Leaflet fail, the static SVG route map is shown instead. There is
   no fake bus position anywhere — the map shows the real route/stops
   plus the passenger's own device location, nothing invented.
================================================================ */

/* Named stops per path — still used by the Route Map PDF download. */

/* OSRM waypoints (lng,lat): Ahmedabad → Mehsana → Palanpur → Udaipur →
   Jaipur → Agra → Lucknow → Bahraich → Rupaidiha border → Nepalgunj.
   OpenStreetMap-based routing only — never Google Maps data. */
var OSRM_WAYPOINTS = '72.5714,23.0225;72.3693,23.5880;72.4375,24.1749;73.7125,24.5854;75.7873,26.9124;78.0081,27.1767;80.9462,26.8467;81.5985,27.5706;81.7116,27.8630;81.6127,27.8940';
var LIVE_TOTAL_KM = 1375;      /* business km, same scale as ROUTE_STOPS */
var LIVE_BORDER_KM = 1305;     /* Rupaidiha border crossing (India → Nepal) */

var LFT = { map: null, you: null, io: null, coords: null, borderIdx: 0, is3D: false };
var MAP_3D_TILT_DEG = 58;   /* GTA-style high-angle camera tilt, shared by CSS + JS */

/* Lazy Leaflet loader — nothing map-related is downloaded until the
   map container actually enters the viewport. unpkg → jsdelivr fallback. */
var leafletPromise = null;
function ensureLeaflet() {
  if (window.L && window.L.map) return Promise.resolve(true);
  if (leafletPromise) return leafletPromise;
  leafletPromise = new Promise(function (resolve) {
    var css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    document.head.appendChild(css);
    var s = document.createElement('script');
    s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    s.onload = function () { resolve(true); };
    s.onerror = function () {
      var s2 = document.createElement('script');
      s2.src = 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js';
      s2.onload = function () { resolve(true); };
      s2.onerror = function () { resolve(false); };
      document.head.appendChild(s2);
    };
    document.head.appendChild(s);
  });
  return leafletPromise;
}

/* Real road geometry from OSRM (free/open). Cached in localStorage so the
   route keeps working OFFLINE after the first successful load. If OSRM is
   unreachable and nothing is cached, an approximate stop-to-stop line is
   drawn instead (honestly labelled) — the map never comes up empty. */
function fetchRoadGeometry() {
  try {
    var cached = localStorage.getItem('shg:osrm:v1');
    if (cached) {
      var p = JSON.parse(cached);
      if (p && p.coords && p.coords.length > 50) return Promise.resolve(p);
    }
  } catch (e) {}
  var url = 'https://router.project-osrm.org/route/v1/driving/' + OSRM_WAYPOINTS + '?overview=full&geometries=geojson';
  return fetch(url).then(function (res) {
    if (!res.ok) throw new Error('osrm ' + res.status);
    return res.json();
  }).then(function (j) {
    var route = j && j.routes && j.routes[0];
    if (!route || !route.geometry || !route.geometry.coordinates || route.geometry.coordinates.length < 50) throw new Error('osrm empty');
    var coords = route.geometry.coordinates.map(function (c) { return [c[1], c[0]]; });   /* → [lat,lng] */
    var out = { coords: coords, km: Math.round((route.distance || 0) / 1000) };
    try { localStorage.setItem('shg:osrm:v1', JSON.stringify(out)); } catch (e) {}
    return out;
  }).catch(function () {
    /* Offline / OSRM down → approximate straight-segment line through the
       named stops. Clearly marked approx so nobody mistakes it for roads. */
    var stops = TRACKER_PATHS.via_bahraich;
    return { coords: stops.map(function (s) { return [s.lat, s.lng]; }), km: 1375, approx: true };
  });
}

/* Finds where the India→Nepal border falls along the real road polyline,
   so the route line can be colored saffron/crimson on either side. */
function buildLiveCum(coords) {
  var cum = [0], total = 0;
  for (var i = 1; i < coords.length; i++) {
    total += haversineKm(coords[i - 1][0], coords[i - 1][1], coords[i][0], coords[i][1]);
    cum.push(total);
  }
  var target = (LIVE_BORDER_KM / LIVE_TOTAL_KM) * (total || 1);
  LFT.borderIdx = cum.length - 1;
  for (var k = 0; k < cum.length; k++) { if (cum[k] >= target) { LFT.borderIdx = k; break; } }
}

/* ---- TEMPLE LAYER — दर्शनीय मन्दिरहरू ------------------------------
   Curated famous temples of India & Nepal shown as 🛕 markers with
   deity / establishment-era / significance popups. Data is local
   (works offline). est values stay conservative — "Ancient" where no
   reliable year exists; nothing is invented. */
var templeLayerGroup = null, templesOn = true, templesOnInitialized = false;

function templePopupHTML(tp) {
  return '🛕 <b>' + esc(tp.name) + '</b>'
    + '<br><small>' + (tp.country === 'NP' ? '🇳🇵' : '🇮🇳') + ' ' + esc(tp.city) + ' · ' + esc(tp.deity) + '</small>'
    + '<br><small>स्थापना · Est: <b>' + esc(tp.est || '—') + '</b></small>'
    + (tp.note ? '<br><small>' + esc(tp.note) + '</small>' : '')
    /* V7 — the old "दर्शन & दान बुकिंग — चाँडै आउँदैछ / coming soon" teaser was a
       standing promise with nothing behind it. Darshan halts are genuinely
       arranged by phone today, so this now offers the real thing: one tap to
       our own support number, honestly labelled, in all three languages. */
    + '<br><small style="color:#B4690A">🙏 <a href="https://wa.me/' + esc(String(CONFIG.adminWhatsApp || '').replace(/[^0-9]/g, '')) + '?text=' + encodeURIComponent('Namaste! ' + (tp.name || 'Temple') + ' darshan halt ko bare ma sodhna chaheko.') + '" target="_blank" rel="noopener" style="color:#B4690A;text-decoration:underline">'
    + t('tplAsk') + '</a></small>';
}
function addTempleLayer(map) {
  if (!window.L || !SHG_TEMPLES.length) return;
  templeLayerGroup = L.layerGroup();
  SHG_TEMPLES.forEach(function (tp) {
    var mk = L.marker([tp.lat, tp.lng], {
      icon: L.divIcon({ className: 'shg-temple-wrap', html: '<div class="shg-temple">🛕</div>', iconSize: [26, 26], iconAnchor: [13, 13] }),
      zIndexOffset: 200
    });
    mk.bindPopup(templePopupHTML(tp), { maxWidth: 260 });
    templeLayerGroup.addLayer(mk);
  });
  if (templesOn) templeLayerGroup.addTo(map);
  renderTempleGuide();
}
function toggleTemples() {
  if (!templeLayerGroup || !LFT.map) return;
  templesOn = !templesOn;
  if (templesOn) templeLayerGroup.addTo(LFT.map); else templeLayerGroup.remove();
  var btn = $('#trkTemples');
  if (btn) btn.classList.toggle('on', templesOn);
}
/* GTA-style tilted camera — a pure CSS 3D transform on a wrapper OUTSIDE
   Leaflet's own DOM, so Leaflet never sees it and click/pan math stays
   untouched. Same real OSM tiles/roads/markers underneath either way. */
function toggle3DView() {
  LFT.is3D = !LFT.is3D;
  var wrap = document.getElementById('trackerWrap');
  if (wrap) wrap.classList.toggle('map-3d-scene', LFT.is3D);
  var btn = $('#trk3D');
  if (btn) btn.classList.toggle('on', LFT.is3D);
  if (LFT.map) {
    /* Leaflet's drag math reads raw screen-pixel deltas, which no longer
       line up with the map once it's visually tilted in 3D — dragging
       would feel broken/wrong. 3D is a "look" mode: use the 🚌/📍/+/−
       buttons to move instead; drag-to-pan comes back in 2D. */
    if (LFT.is3D) LFT.map.dragging.disable(); else LFT.map.dragging.enable();
    if (LFT.is3D && typeof toast === 'function') toast(t('lm3DHint'));
    setTimeout(function () { LFT.map.invalidateSize(); }, 650);
  }
}
/* ================================================================
   [JS] FULL INDIA + NEPAL MAP — a second, lazy-loaded Leaflet map
   ("look at the whole subcontinent") living alongside the focused
   live-tracker map (LFT/liveMapEl). Own map instance (FM.map), own
   tile layer (CartoDB Voyager), own MarkerClusterGroup for landmarks.
   Reuses real data already in this file (ROUTE_STOPS, the OSRM-derived
   road geometry, SHG_TEMPLES) for the "S Hari route" overlay rather
   than duplicating it.
================================================================ */
var FM = { map: null, booted: false, cluster: null, cityLayer: null,
  routeLine: null, stopMarkers: [], you: null, activeFilter: 'all' };

/* ---- India + Nepal city dot markers (approximate city-center coords,
   fine-grained precision isn't the point — this is a whole-subcontinent
   overview layer, not a routing layer). ---- */
var FM_INDIA_CITIES = [
  // Gujarat
  ['Ahmedabad',23.0225,72.5714],['Surat',21.1702,72.8311],['Vadodara',22.3072,73.1812],
  ['Rajkot',22.3039,70.8022],['Bhavnagar',21.7645,72.1519],['Jamnagar',22.4707,70.0577],
  ['Gandhinagar',23.2156,72.6369],['Mehsana',23.5880,72.3693],['Palanpur',24.1710,72.4380],
  ['Anand',22.5645,72.9289],['Nadiad',22.6939,72.8618],['Morbi',22.8173,70.8378],
  // Rajasthan
  ['Jaipur',26.9124,75.7873],['Jodhpur',26.2389,73.0243],['Udaipur',24.5854,73.7125],
  ['Bikaner',28.0229,73.3119],['Ajmer',26.4499,74.6399],['Kota',25.2138,75.8648],
  ['Alwar',27.5530,76.6346],['Bharatpur',27.2152,77.4909],['Sikar',27.6094,75.1399],
  ['Pali',25.7711,73.3234],['Abu Road',24.4800,72.7700],['Sri Ganganagar',29.9094,73.8800],
  ['Hanumangarh',29.5822,74.3297],['Barmer',25.7521,71.3961],['Jaisalmer',26.9157,70.9083],
  ['Chittorgarh',24.8887,74.6269],
  // Haryana
  ['Gurugram',28.4595,77.0266],['Faridabad',28.4089,77.3178],['Hisar',29.1492,75.7217],
  ['Rohtak',28.8955,76.6066],['Panipat',29.3909,76.9635],['Karnal',29.6857,76.9905],
  ['Ambala',30.3752,76.7821],['Yamunanagar',30.1290,77.2674],['Sirsa',29.5321,75.0280],
  ['Fatehabad',29.5157,75.4548],['Bhiwani',28.7975,76.1322],
  // Delhi
  ['New Delhi',28.6139,77.2090],['Old Delhi',28.6562,77.2410],['Noida',28.5355,77.3910],
  ['Ghaziabad',28.6692,77.4538],['Dwarka',28.5921,77.0460],
  // Uttar Pradesh
  ['Agra',27.1767,78.0081],['Lucknow',26.8467,80.9462],['Kanpur',26.4499,80.3319],
  ['Varanasi',25.3176,83.0062],['Prayagraj',25.4358,81.8463],['Meerut',28.9845,77.7064],
  ['Mathura',27.4924,77.6737],['Vrindavan',27.5806,77.6944],['Aligarh',27.8974,78.0880],
  ['Bareilly',28.3670,79.4304],['Gorakhpur',26.7606,83.3732],['Bahraich',27.5745,81.5959],
  ['Lakhimpur',27.9494,80.7794],['Ayodhya',26.7953,82.1944],['Jhansi',25.4484,78.5685],
  ['Firozabad',27.1591,78.3957],
  // Bihar
  ['Patna',25.5941,85.1376],['Gaya',24.7955,84.9994],['Muzaffarpur',26.1225,85.3906],
  ['Bhagalpur',25.2425,86.9842],['Bodh Gaya',24.6961,84.9914],
  // Uttarakhand
  ['Dehradun',30.3165,78.0322],['Haridwar',29.9457,78.1642],['Rishikesh',30.0869,78.2676],
  ['Nainital',29.3803,79.4636],['Mussoorie',30.4598,78.0644],
  // Punjab
  ['Amritsar',31.6340,74.8723],['Ludhiana',30.9010,75.8573],['Chandigarh',30.7333,76.7794],
  ['Jalandhar',31.3260,75.5762],['Patiala',30.3398,76.3869],
  // Himachal Pradesh
  ['Shimla',31.1048,77.1734],['Manali',32.2432,77.1892],['Dharamsala',32.2190,76.3234],
  // Maharashtra
  ['Mumbai',19.0760,72.8777],['Pune',18.5204,73.8567],['Nagpur',21.1458,79.0882],
  ['Nashik',19.9975,73.7898],['Aurangabad',19.8762,75.3433],
  // Madhya Pradesh
  ['Bhopal',23.2599,77.4126],['Indore',22.7196,75.8577],['Gwalior',26.2183,78.1828],
  ['Jabalpur',23.1815,79.9864],['Ujjain',23.1828,75.7682],
  // Reference metros
  ['Kolkata',22.5726,88.3639],['Chennai',13.0827,80.2707],['Hyderabad',17.3850,78.4867],['Bengaluru',12.9716,77.5946],
];

var FM_NEPAL_CITIES = [
  // Koshi
  ['Biratnagar',26.4525,87.2718],['Dharan',26.8065,87.2846],['Birtamod',26.6428,87.9967],
  ['Ilam',26.9088,87.9280],['Taplejung',27.3500,87.6700],['Bhojpur',27.1667,87.0500],
  ['Okhaldhunga',27.3167,86.5000],['Khotang',27.2000,86.8000],['Solukhumbu',27.7500,86.7167],
  // Madhesh
  ['Janakpur',26.7288,85.9266],['Birgunj',27.0104,84.8770],['Simara',27.1591,84.9781],
  ['Rajbiraj',26.5411,86.7460],['Lahan',26.7211,86.4936],['Siraha',26.6550,86.2075],
  ['Malangwa (Sarlahi)',26.8590,85.5590],['Jaleshwar (Mahottari)',26.6483,85.8010],
  ['Kalaiya (Bara)',27.0369,84.9678],['Nawalpur (Parsa)',27.0300,84.8800],['Rautahat (Gaur)',26.7622,85.2660],
  // Bagmati
  ['Kathmandu',27.7172,85.3240],['Lalitpur (Patan)',27.6644,85.3188],['Bhaktapur',27.6710,85.4298],
  ['Hetauda',27.4287,85.0325],['Chitwan (Bharatpur)',27.6833,84.4333],['Sindhuli',27.2544,85.9711],
  ['Ramechhap',27.3264,86.0850],['Dolakha (Charikot)',27.6667,86.0500],['Sindhupalchok (Chautara)',27.8167,85.7167],
  // Gandaki
  ['Pokhara',28.2096,83.9856],['Gorkha',28.0000,84.6333],['Lamjung (Besisahar)',28.2333,84.3667],
  ['Damauli (Tanahu)',27.9500,84.2667],['Waling (Syangja)',28.0972,83.5497],['Kusma (Parbat)',28.2333,83.6167],
  ['Baglung',28.2667,83.5890],['Beni (Myagdi)',28.3333,83.5833],['Jomsom (Mustang)',28.7810,83.7240],
  ['Chame (Manang)',28.5500,84.2333],
  // Lumbini
  ['Butwal',27.7000,83.4486],['Bhairahawa',27.5052,83.4483],['Tansen (Palpa)',27.8667,83.5500],
  ['Tulsipur (Dang)',28.1300,82.2967],['Taulihawa (Kapilvastu)',27.5667,83.0500],['Sandhikharka (Arghakhanchi)',27.9167,83.0000],
  ['Tamghas (Gulmi)',28.0667,83.2333],['Rupandehi',27.5500,83.4500],['Kawasoti (Nawalpur)',27.7000,84.1000],
  ['Pyuthan',28.1000,82.8667],['Liwang (Rolpa)',28.3333,82.6333],['Musikot (Rukum East)',28.6300,82.5300],
  // Karnali
  ['Birendranagar (Surkhet)',28.6020,81.6339],['Jumla',29.2747,82.1826],['Dunai (Dolpa)',28.9333,82.9000],
  ['Simikot (Humla)',29.9700,81.8200],['Gamgadhi (Mugu)',29.5667,82.1167],['Manma (Kalikot)',29.3667,81.6167],
  ['Khalanga (Jajarkot)',28.7000,82.2000],['Dailekh',28.8447,81.7222],['Salyan',28.3833,82.1667],
  ['Musikot (Rukum West)',28.6300,82.2000],
  // Sudurpaschim
  ['Nepalgunj',28.0500,81.6167],['Dhangadhi',28.6833,80.6000],['Mahendranagar (Bhimdatta)',28.9167,80.1667],
  ['Dadeldhura',29.3000,80.5833],['Darchula',29.8500,80.5500],['Baitadi',29.5333,80.4667],
  ['Bajhang (Chainpur)',29.5500,81.2000],['Bajura (Martadi)',29.5167,81.4500],['Achham (Mangalsen)',29.2167,81.3000],
  ['Doti (Dipayal)',29.2667,80.9333],
];

/* ---- Highways (approximate paths, as given). ---- */
var FM_HIGHWAYS = [
  { name: 'NH-48 Ahmedabad–Delhi', color: '#E65100', weight: 3,
    path: [[23.02,72.57],[24.58,72.97],[26.24,73.02],[28.09,73.31],[28.89,76.60],[28.61,77.20]] },
  { name: 'NH-19 Delhi–Varanasi', color: '#1565C0', weight: 3,
    path: [[28.61,77.20],[27.17,78.00],[26.44,80.33],[25.31,82.99]] },
  { name: 'NH-27 Lucknow–Gorakhpur', color: '#2E7D32', weight: 3,
    path: [[26.84,80.94],[26.75,83.37],[27.49,83.44]] },
  { name: 'BP Koirala Highway / Mahendra Rajmarga', color: '#C62828', weight: 3,
    path: [[26.47,87.27],[27.67,84.43],[28.05,81.61],[28.85,80.55],[29.30,80.21]] },
  { name: 'Prithvi Highway', color: '#6A1B9A', weight: 3,
    path: [[27.70,85.31],[27.99,84.39],[28.20,83.98]] },
  { name: 'Siddhartha Highway', color: '#00695C', weight: 3,
    path: [[28.20,83.98],[27.99,83.54],[27.50,83.45]] },
];

var FM_RIVERS = [
  { name: 'Ganga River', color: '#1E88E5', weight: 2,
    path: [[25.31,82.99],[25.44,81.88],[25.74,81.35],[26.84,80.94]] },
  { name: 'Karnali River', color: '#29B6F6', weight: 2,
    path: [[29.10,81.90],[28.62,81.70],[28.05,81.61]] },
  { name: 'Gandaki / Narayani River', color: '#29B6F6', weight: 2,
    path: [[28.20,83.98],[27.70,84.43],[27.01,84.86]] },
];

/* ---- Landmarks — temples/forts/monuments/nature with Wikimedia photos.
   Popup images fail gracefully (onerror hides the <img>) since these are
   third-party hotlinked URLs that could move/break independently of this
   file. ---- */
var FM_LANDMARKS = [
  { name:'Somnath Temple', lat:20.8880, lng:70.4012, emoji:'🛕', type:'Temple',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/4/41/Somnath_temple.jpg/280px-Somnath_temple.jpg',
    desc:'One of 12 Jyotirlinga — on Arabian Sea coast' },
  { name:'Dwarka Temple', lat:22.2374, lng:68.9676, emoji:'🛕', type:'Temple',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/b/b9/Dwarkadhish_temple.jpg/280px-Dwarkadhish_temple.jpg',
    desc:'Dwarkadhish — one of Char Dham pilgrimages' },
  { name:'Ambaji Temple', lat:24.3371, lng:72.8561, emoji:'🛕', type:'Temple',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/3/31/Ambaji.jpg/280px-Ambaji.jpg',
    desc:'Shakti Peeth — on S Hari route near Palanpur' },
  { name:'Akshardham Gandhinagar', lat:23.2156, lng:72.6369, emoji:'🛕', type:'Temple',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/0/0e/Akshardham_Gandhinagar.jpg/280px-Akshardham_Gandhinagar.jpg',
    desc:'BAPS Swaminarayan grand temple' },
  { name:'Rann of Kutch', lat:23.7337, lng:69.8597, emoji:'🏜️', type:'Nature',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/b/b3/Great_Rann_of_Kutch.jpg/280px-Great_Rann_of_Kutch.jpg',
    desc:'Largest salt desert in the world' },
  { name:'Mehrangarh Fort', lat:26.2980, lng:73.0187, emoji:'🏰', type:'Fort',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/0/01/Mehrangarh_Fort%2C_Jodhpur.jpg/280px-Mehrangarh_Fort%2C_Jodhpur.jpg',
    desc:'Massive fort — Blue City Jodhpur' },
  { name:'Hawa Mahal Jaipur', lat:26.9239, lng:75.8267, emoji:'🏛️', type:'Monument',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/3/3b/Hawa_Mahal_2010.jpg/280px-Hawa_Mahal_2010.jpg',
    desc:'Palace of Winds — Jaipur Pink City' },
  { name:'Jaisalmer Fort', lat:26.9124, lng:70.9078, emoji:'🏰', type:'Fort',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/d/d3/Jaisalmer_fort.jpg/280px-Jaisalmer_fort.jpg',
    desc:'Golden fort in Thar Desert' },
  { name:'Udaipur City Palace', lat:24.5763, lng:73.6832, emoji:'🏰', type:'Palace',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/1/16/Udaipur_city_palace.jpg/280px-Udaipur_city_palace.jpg',
    desc:'Lake palace — City of Lakes' },
  { name:'Karni Mata Temple', lat:27.9696, lng:73.2883, emoji:'🛕', type:'Temple',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/9/91/Karni_Mata_temple.jpg/280px-Karni_Mata_temple.jpg',
    desc:'Rat temple — near Bikaner' },
  { name:'Ajmer Dargah Sharif', lat:26.4524, lng:74.6252, emoji:'🕌', type:'Dargah',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/3/3f/Ajmer_Sharif_Dargah.jpg/280px-Ajmer_Sharif_Dargah.jpg',
    desc:'Sufi shrine of Moinuddin Chishti — all faiths pilgrimage' },
  { name:'Chittorgarh Fort', lat:24.8887, lng:74.6269, emoji:'🏰', type:'Fort',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/f/f9/Chittorgarh_Fort.jpg/280px-Chittorgarh_Fort.jpg',
    desc:'Largest fort in India — Rajput valor' },
  { name:'Red Fort Delhi', lat:28.6562, lng:77.2410, emoji:'🏛️', type:'Monument',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/6/6a/Red_Fort_in_Delhi_03-2016.jpg/280px-Red_Fort_in_Delhi_03-2016.jpg',
    desc:'UNESCO — Mughal imperial fortress' },
  { name:'Qutub Minar', lat:28.5245, lng:77.1855, emoji:'🗼', type:'Monument',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/8/8a/Qtub_Minar_1.jpg/280px-Qtub_Minar_1.jpg',
    desc:'UNESCO — tallest brick minaret in world' },
  { name:'Taj Mahal Agra', lat:27.1751, lng:78.0421, emoji:'🕌', type:'Monument',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/1/1d/Taj_Mahal_%28Edited%29.jpeg/280px-Taj_Mahal_%28Edited%29.jpeg',
    desc:'UNESCO Wonder of World — white marble mausoleum' },
  { name:'Mathura Krishna Janmabhoomi', lat:27.5036, lng:77.6767, emoji:'🛕', type:'Temple',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/5/5a/KrishnaJanmabhoomi.jpg/280px-KrishnaJanmabhoomi.jpg',
    desc:'Birthplace of Lord Krishna' },
  { name:'Varanasi Ghats', lat:25.3176, lng:83.0062, emoji:'🛕', type:'Temple',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/5/54/Varanasi_Ghats.jpg/280px-Varanasi_Ghats.jpg',
    desc:'Oldest living city — 84 ghats on Ganga' },
  { name:'Ayodhya Ram Mandir', lat:26.7953, lng:82.1944, emoji:'🛕', type:'Temple',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/3/33/Ram_Mandir_Ayodhya.jpg/280px-Ram_Mandir_Ayodhya.jpg',
    desc:'New Ram Temple — birthplace of Lord Ram' },
  { name:'Sarnath', lat:25.3814, lng:83.0237, emoji:'☸️', type:'Buddhist',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a4/Dhamek_Stupa_Sarnath.jpg/280px-Dhamek_Stupa_Sarnath.jpg',
    desc:'Where Buddha gave first sermon — Dhamek Stupa' },
  { name:'Bodh Gaya Mahabodhi', lat:24.6961, lng:84.9914, emoji:'☸️', type:'Buddhist',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/b/b5/Mahabodhi_Temple_Bodh_Gaya.jpg/280px-Mahabodhi_Temple_Bodh_Gaya.jpg',
    desc:'UNESCO — where Buddha attained enlightenment' },
  { name:'Haridwar Har Ki Pauri', lat:29.9457, lng:78.1642, emoji:'🛕', type:'Temple',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a1/Har_ki_Pauri_Haridwar.jpg/280px-Har_ki_Pauri_Haridwar.jpg',
    desc:'Gateway to Gods — sacred Ganga ghat' },
  { name:'Rishikesh Laxman Jhula', lat:30.1290, lng:78.3195, emoji:'🌉', type:'Nature',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a5/Laxman_Jhula.jpg/280px-Laxman_Jhula.jpg',
    desc:'Yoga capital of world — suspension bridge over Ganga' },
  { name:'Valley of Flowers', lat:30.7282, lng:79.6050, emoji:'🌸', type:'Nature',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/0/0b/Valley_of_Flowers_Uttarakhand.jpg/280px-Valley_of_Flowers_Uttarakhand.jpg',
    desc:'UNESCO — alpine flowers national park' },
  { name:'Pashupatinath Temple', lat:27.7105, lng:85.3487, emoji:'🛕', type:'Temple',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/4/45/Pashupatinath_Temple.jpg/280px-Pashupatinath_Temple.jpg',
    desc:'UNESCO — sacred Shiva temple, Kathmandu' },
  { name:'Boudhanath Stupa', lat:27.7215, lng:85.3620, emoji:'☸️', type:'Buddhist',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/1/13/Bodnath.jpg/280px-Bodnath.jpg',
    desc:'UNESCO — largest stupa in Nepal' },
  { name:'Swayambhunath (Monkey Temple)', lat:27.7149, lng:85.2904, emoji:'☸️', type:'Buddhist',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/5/5e/Swayambhunath_stupa.jpg/280px-Swayambhunath_stupa.jpg',
    desc:'Ancient stupa on hill overlooking Kathmandu' },
  { name:'Lumbini Buddha Birthplace', lat:27.4833, lng:83.2765, emoji:'☸️', type:'Buddhist',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/5/5f/Maya_Devi_Temple%2C_Lumbini.jpg/280px-Maya_Devi_Temple%2C_Lumbini.jpg',
    desc:'UNESCO — birthplace of Gautam Buddha' },
  { name:'Phewa Lake Pokhara', lat:28.2101, lng:83.9560, emoji:'🏔️', type:'Nature',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/6/6a/Phewa_Lake_Pokhara.jpg/280px-Phewa_Lake_Pokhara.jpg',
    desc:"Annapurna reflection — Nepal's tourist capital" },
  { name:'Everest Base Camp', lat:28.0026, lng:86.8528, emoji:'🏔️', type:'Nature',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/e/e7/Everest_North_Face_toward_Base_Camp_Tibet_Luca_Galuzzi_2006.jpg/280px-Everest_North_Face_toward_Base_Camp_Tibet_Luca_Galuzzi_2006.jpg',
    desc:"World's highest mountain — 8,848m" },
  { name:'Annapurna Base Camp', lat:28.5308, lng:83.8768, emoji:'🏔️', type:'Nature',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a4/Annapurna_I.jpg/280px-Annapurna_I.jpg',
    desc:'Annapurna massif — top trekking destination' },
  { name:'Manakamana Temple', lat:27.9211, lng:84.5688, emoji:'🛕', type:'Temple',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/4/49/Manakamana_temple.jpg/280px-Manakamana_temple.jpg',
    desc:'Wish-fulfilling goddess — cable car access' },
  { name:'Muktinath Temple', lat:28.8174, lng:83.8703, emoji:'🛕', type:'Temple',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/3/3a/Muktinath_temple.jpg/280px-Muktinath_temple.jpg',
    desc:'Sacred for both Hindus and Buddhists — Mustang' },
  { name:'Chitwan National Park', lat:27.5291, lng:84.3542, emoji:'🐘', type:'Nature',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/9/91/Rhinoceros_Chitwan.jpg/280px-Rhinoceros_Chitwan.jpg',
    desc:'UNESCO — one-horned rhino, Bengal tiger' },
  { name:'Bardiya National Park', lat:28.3765, lng:81.4234, emoji:'🐯', type:'Nature',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/b/b1/Royal_Bengal_Tiger.jpg/280px-Royal_Bengal_Tiger.jpg',
    desc:'Royal Bengal Tiger reserve — near Nepalgunj' },
  { name:'Bageshwari Temple Nepalgunj', lat:28.0517, lng:81.6068, emoji:'🛕', type:'Temple',
    photo:'https://upload.wikimedia.org/wikipedia/commons/thumb/4/44/Bageshwari_temple_nepalgunj.jpg/280px-Bageshwari_temple_nepalgunj.jpg',
    desc:'Main Durga temple at S Hari final destination' },
];

function fmPopupHTML(lm) {
  return '<div style="width:220px;font-family:var(--f-body)">'
    + '<img src="' + esc(lm.photo) + '" width="200" style="border-radius:8px;margin-bottom:8px;display:block" onerror="this.style.display=\'none\'">'
    + '<h3 style="font-size:14px;font-weight:700;margin-bottom:4px">' + lm.emoji + ' ' + esc(lm.name) + '</h3>'
    + '<span style="font-size:11px;padding:2px 8px;border-radius:20px;background:#EBF2FF;color:#2E5FA8;font-weight:600">' + esc(lm.type) + '</span>'
    + '<p style="font-size:12.5px;color:#5C6B85;margin-top:6px;line-height:1.4">' + esc(lm.desc) + '</p>'
    + '</div>';
}

/* Lazy-load leaflet.markercluster (JS+CSS), independent of ensureLeaflet
   since MarkerCluster is only needed once the Full Map tab is opened, not
   for the core live-tracker map. */
var markerClusterPromise = null;
function ensureMarkerCluster() {
  if (window.L && window.L.markerClusterGroup) return Promise.resolve(true);
  if (markerClusterPromise) return markerClusterPromise;
  markerClusterPromise = new Promise(function (resolve) {
    ['https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css',
     'https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css'].forEach(function (href) {
      var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = href; document.head.appendChild(l);
    });
    var s = document.createElement('script');
    s.src = 'https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js';
    s.onload = function () { resolve(true); };
    s.onerror = function () { markerClusterPromise = null; resolve(false); };
    document.head.appendChild(s);
  });
  return markerClusterPromise;
}

function switchFmTab(tab) {
  ['tracker', 'fullmap', 'stops'].forEach(function (k) {
    var pane = document.getElementById('fmPane_' + k);
    if (pane) pane.style.display = (k === tab) ? '' : 'none';
    var btn = document.querySelector('[data-fmtab="' + k + '"]');
    if (btn) btn.classList.toggle('on', k === tab);
  });
  if (tab === 'fullmap') {
    if (!FM.booted) { FM.booted = true; bootFullMap(); }
    else if (FM.map) setTimeout(function () { FM.map.invalidateSize(); }, 60);
  } else if (tab === 'tracker' && LFT.map) {
    setTimeout(function () { LFT.map.invalidateSize(); }, 60);
  }
}

function bootFullMap() {
  var el = document.getElementById('fullMapEl');
  if (!el) return;
  Promise.all([ensureLeaflet(), ensureMarkerCluster()]).then(function (rr) {
    if (!rr[0] || !window.L) {
      el.innerHTML = '<div style="padding:20px;text-align:center;color:var(--muted)">🗺️ Map failed to load — check your connection and reopen this tab.</div>';
      return;
    }
    initFullMapLeaflet(el, rr[1]);
  });
}

function initFullMapLeaflet(el, hasCluster) {
  var map = L.map(el, { zoomControl: false, minZoom: 4, maxZoom: 17 });
  FM.map = map;
  L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
    maxZoom: 19, subdomains: 'abcd',
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>'
  }).addTo(map);

  // Fit to full India + Nepal on first open.
  map.fitBounds([[6.4, 68.1], [30.4, 88.2]]);

  // Own flat zoom buttons (consistent with the live-tracker map).
  L.control.zoom({ position: 'bottomright' }).addTo(map);

  // ---- City dot markers, radius grows once zoomed in ----
  FM.cityLayer = L.layerGroup().addTo(map);
  var cityMarkers = [];
  function addCityDots(list, color) {
    list.forEach(function (c) {
      var mk = L.circleMarker([c[1], c[2]], { radius: 5, color: '#fff', weight: 1, fillColor: color, fillOpacity: .9 });
      mk.bindTooltip(c[0], { direction: 'top', offset: [0, -4] });
      mk.addTo(FM.cityLayer);
      cityMarkers.push(mk);
    });
  }
  addCityDots(FM_INDIA_CITIES, '#F07C1F');
  addCityDots(FM_NEPAL_CITIES, '#DC143C');
  map.on('zoomend', function () {
    var r = map.getZoom() > 8 ? 7 : 5;
    cityMarkers.forEach(function (mk) { mk.setRadius(r); });
  });

  // ---- Highways + rivers (click a line to see its label) ----
  FM_HIGHWAYS.forEach(function (h) {
    L.polyline(h.path, { color: h.color, weight: h.weight })
      .addTo(map).bindPopup('<b>' + esc(h.name) + '</b>');
  });
  FM_RIVERS.forEach(function (r) {
    L.polyline(r.path, { color: r.color, weight: r.weight, dashArray: '4,3' })
      .addTo(map).bindPopup('<b>' + esc(r.name) + '</b> (river)');
  });

  // ---- Landmarks — clustered, filterable ----
  FM.cluster = hasCluster ? L.markerClusterGroup() : L.layerGroup();
  FM_LANDMARKS.forEach(function (lm) {
    var mk = L.marker([lm.lat, lm.lng], {
      icon: L.divIcon({ className: 'fm-landmark-wrap', html: '<div class="fm-landmark">' + lm.emoji + '</div>', iconSize: [28, 28], iconAnchor: [14, 14] })
    });
    mk.landmarkType = lm.type;
    mk.bindPopup(fmPopupHTML(lm), { maxWidth: 240 });
    FM.cluster.addLayer(mk);
  });
  FM.cluster.addTo(map);

  // ---- S Hari route overlay (reuses the SAME real road geometry + stops
  // already loaded for the live tracker — no separate/approximate line). ----
  function drawShgRoute() {
    if (LFT.coords && LFT.coords.length) {
      FM.routeLine = L.polyline(LFT.coords, { color: '#F07C1F', weight: 4, dashArray: '2,6' }).addTo(map);
    }
    ROUTE_STOPS.forEach(function (s) {
      var mk = L.circleMarker([s.lat, s.lng], { radius: 4, color: '#fff', weight: 1, fillColor: '#2E5FA8', fillOpacity: 1 })
        .bindTooltip((s.flag ? s.flag + ' ' : '') + s.name, { direction: 'top', offset: [0, -4] });
      mk.addTo(map);
      FM.stopMarkers.push(mk);
    });
  }
  function clearShgRoute() {
    if (FM.routeLine) { map.removeLayer(FM.routeLine); FM.routeLine = null; }
    FM.stopMarkers.forEach(function (mk) { map.removeLayer(mk); }); FM.stopMarkers = [];
  }
  drawShgRoute(); // default ON, per spec

  var routeToggle = document.getElementById('fmRouteToggle');
  if (routeToggle) routeToggle.onchange = function () { this.checked ? drawShgRoute() : clearShgRoute(); };

  // ---- Filter buttons — markerClusterGroup has no per-marker visibility
  // toggle, so filtering rebuilds the cluster's contents directly. ----
  document.querySelectorAll('.fm-filter-btn').forEach(function (btn) {
    btn.onclick = function () {
      var type = btn.getAttribute('data-fmtype');
      document.querySelectorAll('.fm-filter-btn').forEach(function (b) { b.classList.remove('on'); });
      if (type === 'all' && FM.activeFilter === 'all') {
        // second click on "All" hides everything, per spec
        FM.cluster.clearLayers();
        FM.activeFilter = 'none';
        return;
      }
      btn.classList.add('on');
      FM.cluster.clearLayers();
      FM_LANDMARKS.forEach(function (lm) {
        if (type !== 'all' && lm.type !== type) return;
        var mk = L.marker([lm.lat, lm.lng], {
          icon: L.divIcon({ className: 'fm-landmark-wrap', html: '<div class="fm-landmark">' + lm.emoji + '</div>', iconSize: [28, 28], iconAnchor: [14, 14] })
        });
        mk.landmarkType = lm.type;
        mk.bindPopup(fmPopupHTML(lm), { maxWidth: 240 });
        FM.cluster.addLayer(mk);
      });
      FM.activeFilter = type;
    };
  });

  // ---- Search box ----
  var searchInput = document.getElementById('fmSearchInput');
  var searchBtn = document.getElementById('fmSearchBtn');
  /* [MAP PRO] Full-map search — same reusable Google-style autocomplete as the
     navigator. Typing shows live geocoded suggestions (India+Nepal); selecting flies
     the map there. The "Go" button matches a local landmark first, else geocodes. */
  function fmLocal(q) {
    q = (q || '').trim().toLowerCase(); if (!q) return null;
    var all = FM_INDIA_CITIES.concat(FM_NEPAL_CITIES).map(function (c) { return { name: c[0], lat: c[1], lng: c[2] }; })
      .concat(FM_LANDMARKS.map(function (lm) { return { name: lm.name, lat: lm.lat, lng: lm.lng }; }));
    return all.find(function (p) { return p.name.toLowerCase().indexOf(q) !== -1; }) || null;
  }
  function fmGoTo(lat, lng, label) {
    map.flyTo([lat, lng], 11);
    if (FM.searchMk) FM.searchMk.remove();
    FM.searchMk = L.circleMarker([lat, lng], { radius: 8, color: '#fff', weight: 2.5, fillColor: '#FF6B00', fillOpacity: 1 }).addTo(map);
    L.popup().setLatLng([lat, lng]).setContent('<b>' + esc(label || '') + '</b>').openOn(map);
  }
  if (searchInput && typeof createLocationSearchInput === 'function')
    createLocationSearchInput(searchInput, { historyKey: 'shari:searchHist',
      onSelect: function (r) { fmGoTo(r.lat, r.lng, r.shortName || r.displayName); } });
  function runSearch() {
    var q = (searchInput.value || '').trim(); if (!q) return;
    var loc = fmLocal(q);
    if (loc) { fmGoTo(loc.lat, loc.lng, loc.name); return; }
    window.mapGeocode(q, 1).then(function (res) {
      var hit = res && res.results && res.results[0];
      if (hit) fmGoTo(hit.lat, hit.lng, hit.shortName || hit.displayName);
      else if (typeof toast === 'function') toast('📍 "' + q + '" not found on the map.');
    });
  }
  if (searchBtn) searchBtn.onclick = runSearch;

  // ---- My Location ----
  var locBtn = document.getElementById('fmLocateBtn');
  if (locBtn) locBtn.onclick = function () { map.locate({ setView: false, enableHighAccuracy: true }); };
  map.on('locationfound', function (ev) {
    if (FM.you) FM.you.remove();
    FM.you = L.circleMarker(ev.latlng, { radius: 8, color: '#fff', weight: 2.5, fillColor: '#4285F4', fillOpacity: 1 }).addTo(map);
    map.flyTo(ev.latlng, Math.max(map.getZoom(), 10));
  });
  map.on('locationerror', function () { if (typeof toast === 'function') toast('📍 Location permission needed.'); });
}
/* Nepal mandir guide — Surkhet → Kathmandu corridor first, then national
   icons; India list follows. Rendered inside the map bottom sheet. */
function renderTempleGuide() {
  var el = $('#lmTempleGuide');
  if (!el || !SHG_TEMPLES.length) return;
  var np = SHG_TEMPLES.filter(function (t2) { return t2.country === 'NP'; });
  var ind = SHG_TEMPLES.filter(function (t2) { return t2.country === 'IN'; });
  var row = function (tp) {
    return '<div class="trk-stop-row"><div style="font-size:16px;flex:none">🛕</div>'
      + '<div class="info"><div class="nm">' + esc(tp.name) + '</div>'
      + '<div class="km">' + (tp.country === 'NP' ? '🇳🇵' : '🇮🇳') + ' ' + esc(tp.city) + ' · ' + esc(tp.deity) + '</div></div>'
      + '<div class="tm">' + esc(tp.est || '—') + '</div></div>';
  };
  el.innerHTML = '<h4>' + esc(t('lmTempleNepalHdr')) + ' (' + np.length + ')</h4>'
    + np.map(row).join('')
    + '<h4 style="margin-top:18px">' + esc(t('lmTempleIndiaHdr')) + ' (' + ind.length + ')</h4>'
    + ind.map(row).join('')
    + '<div class="jt-note" style="margin-top:10px">' + esc(t('lmTempleEstNote')) + '</div>';
}

/* Build the map view skeleton, then lazy-init Leaflet via IntersectionObserver. */
function renderLiveMapView(box, b) {
  if (!templesOnInitialized) {
    templesOnInitialized = true;
    if (DB.settings && DB.settings.templesDefaultOn === false) templesOn = false;
  }
  var r = b ? (routeById(b.routeId) || {}) : {};
  var defTitle = ROUTE_STOPS.length >= 2 ? (ROUTE_STOPS[0].name + ' ⇄ ' + ROUTE_STOPS[ROUTE_STOPS.length - 1].name) : 'Route';
  var title = b ? esc((r.from || ROUTE_STOPS[0].name) + ' → ' + (r.to || ROUTE_STOPS[ROUTE_STOPS.length - 1].name)) : defTitle;
  var sub = b ? esc(b.id) + ' · ' + fmtDate(b.date) : 'Full route · via Rupaidiha Border 🛃';

  var stopsRows = ROUTE_STOPS.map(function (s) {
    return '<div class="trk-stop-row"><div class="dot upcoming"></div>'
      + '<div class="info"><div class="nm">' + (s.flag ? s.flag + ' ' : '') + esc(s.name) + '</div>'
      + '<div class="km">' + s.km.toLocaleString() + ' km</div></div></div>';
  }).join('');

  box.innerHTML = '<div class="fm-tabs">'
    + '<button class="fm-tab-btn on" data-fmtab="tracker" type="button">📍 ' + esc(t('lmTabTracker')) + '</button>'
    + '<button class="fm-tab-btn" data-fmtab="fullmap" type="button">🗺️ ' + esc(t('lmTabFullMap')) + '</button>'
    + '<button class="fm-tab-btn" data-fmtab="stops" type="button">📋 ' + esc(t('lmTabStops')) + '</button>'
    + '</div>'
    + '<div id="fmPane_tracker">'
    + '<div class="tracker-wrap" id="trackerWrap">'
    + '<div id="liveMapStage" class="live-map-stage"><div id="liveMapEl"></div></div>'
    + '<div id="mapHorizonHaze" class="map-horizon-haze" aria-hidden="true"></div>'
    + '<div id="liveMapBadge"></div>'
    + '<button class="trk-center-btn" id="trkLocate" type="button" title="' + esc(t('lmLocateTitle')) + '">📍</button>'
    + '<button class="trk-center-btn' + (templesOn ? ' on' : '') + '" id="trkTemples" type="button" style="top:58px" title="' + esc(t('lmTemplesTitle')) + '">🛕</button>'
    + '<button class="trk-center-btn' + (LFT.is3D ? ' on' : '') + '" id="trk3D" type="button" style="top:104px" title="' + esc(t('lm3DTitle')) + '">🏙️</button>'
    + '<div class="trk-zoom-ctrl"><button class="trk-zoom-btn" id="trkZoomIn" type="button" aria-label="Zoom in">+</button><button class="trk-zoom-btn" id="trkZoomOut" type="button" aria-label="Zoom out">−</button></div>'
    + '<div class="trk-sheet" id="trkSheet">'
    + '<div class="trk-sheet-handle"></div>'
    + '<div class="trk-sheet-head">'
    + '<img data-logo-clone alt="" style="width:22px;height:22px;object-fit:contain;border-radius:5px">'
    + '<span class="trk-bus-name">' + title + '</span>'
    + '<span class="trk-bus-no">' + sub + '</span>'
    + '</div>'
    + '<div class="trk-eta-strip">'
    + '<div class="trk-eta-cell"><div class="val" id="lmYou">' + esc(t('lmTapLocate')) + '</div><div class="lbl">' + esc(t('lmYourLoc')) + '</div></div>'
    + '<div class="trk-eta-cell"><div class="val" id="lmNearest">--</div><div class="lbl">' + esc(t('lmNearestStop')) + '</div></div>'
    + '<div class="trk-eta-cell"><div class="val" id="lmDist">--</div><div class="lbl">' + esc(t('lmDistLbl')) + '</div></div>'
    + '<div class="trk-eta-cell"><div class="val">' + LIVE_TOTAL_KM.toLocaleString() + ' km</div><div class="lbl">' + esc(t('lmTotalRoute')) + '</div></div>'
    + '</div>'
    + '<div class="trk-stops-list" id="lmStops"><h4>' + esc(t('lmRouteHalts')) + ' · ≈ ' + LIVE_TOTAL_KM.toLocaleString() + ' KM</h4>' + stopsRows + '</div>'
    + '<div class="trk-stops-list" id="lmTempleGuide"></div>'
    + '</div></div>'
    + '</div>'
    + '<div id="fmPane_fullmap" style="display:none">'
    + '<div class="fm-toolbar">'
    + '<div class="fm-search"><input id="fmSearchInput" type="text" placeholder="🔍 Search a city or landmark…"><button id="fmSearchBtn" type="button">Go</button></div>'
    + '<button class="fm-tool-btn" id="fmLocateBtn" type="button">📍 My Location</button>'
    + '<label class="fm-route-toggle"><input type="checkbox" id="fmRouteToggle" checked> 🛣️ Show S Hari Route</label>'
    + '</div>'
    + '<div class="fm-filterbar">'
    + '<button class="fm-filter-btn on" data-fmtype="all" type="button">All</button>'
    + '<button class="fm-filter-btn" data-fmtype="Temple" type="button">🛕 Temples</button>'
    + '<button class="fm-filter-btn" data-fmtype="Fort" type="button">🏰 Forts</button>'
    + '<button class="fm-filter-btn" data-fmtype="Buddhist" type="button">☸️ Buddhist</button>'
    + '<button class="fm-filter-btn" data-fmtype="Nature" type="button">🐯 Nature</button>'
    + '<button class="fm-filter-btn" data-fmtype="Dargah" type="button">🕌 Dargah/Mosque</button>'
    + '</div>'
    + '<div class="fm-map-wrap"><div id="fullMapEl"></div></div>'
    + '</div>'
    + '<div id="fmPane_stops" style="display:none">'
    + '<div class="fm-stops-pane"><h4>' + esc(t('lmRouteHalts')) + ' · ≈ ' + LIVE_TOTAL_KM.toLocaleString() + ' KM</h4>' + stopsRows + '</div>'
    + '</div>';

  $$('.fm-tab-btn').forEach(function (btn) {
    btn.onclick = function () { switchFmTab(btn.getAttribute('data-fmtab')); };
  });

  var el = $('#liveMapEl');
  var booted = false;
  var boot = function () {
    if (booted) return; booted = true;
    Promise.all([ensureLeaflet(), fetchRoadGeometry()]).then(function (rr) {
      if (!rr[0] || !window.L || !rr[1]) { renderTrackFallback(box, b); return; }
      if (document.getElementById('liveMapEl') !== el) return;   /* stale render — a newer view replaced this node while loading */
      initLiveMap(el, b, r, rr[1]);
    });
  };
  if ('IntersectionObserver' in window) {
    LFT.io = new IntersectionObserver(function (es) {
      if (es.some(function (e) { return e.isIntersecting; })) {
        if (LFT.io) { LFT.io.disconnect(); LFT.io = null; }
        boot();
      }
    }, { rootMargin: '200px' });
    LFT.io.observe(el);
  } else boot();
}

function initLiveMap(el, b, r, geo) {
  LFT.coords = geo.coords;
  buildLiveCum(geo.coords);
  var map = L.map(el, { zoomControl: false, minZoom: 4, maxZoom: 16 });
  LFT.map = map;
  var tileErrs = 0;
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
  }).on('tileerror', function () {
    /* Offline / tiles unreachable — the route, stops & temples still render
       from local data on the grey canvas. Say so honestly, once. */
    tileErrs++;
    if (tileErrs === 6) {
      var bd = $('#liveMapBadge');
      if (bd && !$('#lmOffline')) bd.insertAdjacentHTML('beforeend',
        '<div class="trk-demo-badge" id="lmOffline" style="background:linear-gradient(135deg,rgba(80,90,110,.95),rgba(50,58,74,.95))">' + t('lmOfflineBadge') + '</div>');
    }
  }).addTo(map);

  /* Road line: dark casing + animated dashed overlay, saffron in India,
     crimson in Nepal (split at the Rupaidiha border). */
  var inCoords = LFT.coords.slice(0, LFT.borderIdx + 1);
  var npCoords = LFT.coords.slice(LFT.borderIdx);
  L.polyline(LFT.coords, { color: '#12264E', weight: 8, opacity: .35, lineJoin: 'round' }).addTo(map);
  L.polyline(inCoords, { color: '#FF9933', weight: 4, opacity: .95, className: 'shg-dash' }).addTo(map);
  L.polyline(npCoords, { color: '#DC143C', weight: 4, opacity: .95, className: 'shg-dash' }).addTo(map);
  if (geo.approx) {
    var bd2 = $('#liveMapBadge');
    if (bd2) bd2.insertAdjacentHTML('beforeend',
      '<div class="trk-demo-badge" style="background:linear-gradient(135deg,rgba(80,90,110,.95),rgba(50,58,74,.95))">' + t('lmApproxBadge') + '</div>');
  }

  ROUTE_STOPS.forEach(function (s) {
    var mk = L.circleMarker([s.lat, s.lng], {
      radius: s.flag ? 7 : 5, color: '#fff', weight: 2,
      fillColor: s.km >= LIVE_BORDER_KM ? '#DC143C' : '#F07C1F', fillOpacity: 1
    }).addTo(map);
    mk.bindTooltip((s.flag ? s.flag + ' ' : '') + s.name + ' · ' + s.km + ' km', { direction: 'top', offset: [0, -6] });
  });

  addTempleLayer(map);

  map.fitBounds(L.latLngBounds(LFT.coords), { padding: [30, 30] });

  var lbtn = $('#trkLocate');
  if (lbtn) lbtn.onclick = function () {
    var el2 = $('#lmYou');
    if (el2) el2.textContent = t('lmLocating');
    if (LFT.map) LFT.map.locate({ setView: false, enableHighAccuracy: true });
  };
  var tbtn = $('#trkTemples');
  if (tbtn) tbtn.onclick = toggleTemples;
  var zinBtn = $('#trkZoomIn'), zoutBtn = $('#trkZoomOut');
  if (zinBtn) zinBtn.onclick = function () { if (LFT.map) LFT.map.zoomIn(); };
  if (zoutBtn) zoutBtn.onclick = function () { if (LFT.map) LFT.map.zoomOut(); };
  var d3Btn = $('#trk3D');
  if (d3Btn) d3Btn.onclick = toggle3DView;
  map.on('locationfound', function (ev) {
    if (LFT.you) LFT.you.remove();
    LFT.you = L.circleMarker(ev.latlng, { radius: 8, color: '#fff', weight: 2.5, fillColor: '#4285F4', fillOpacity: 1 }).addTo(map);
    var best = null, bd = 1e9;
    ROUTE_STOPS.forEach(function (s) {
      var d = haversineKm(ev.latlng.lat, ev.latlng.lng, s.lat, s.lng);
      if (d < bd) { bd = d; best = s; }
    });
    var distTxt = bd < 1 ? Math.round(bd * 1000) + ' m' : bd.toFixed(1) + ' km';
    LFT.you.bindPopup('<b>' + esc(t('lmYouAreHere')) + '</b>'
      + (best ? '<br>' + esc(t('lmNearestStop')) + ': <b>' + best.name + '</b> · ' + distTxt : '')).openPopup();
    var youEl = $('#lmYou'), nearEl = $('#lmNearest'), distEl = $('#lmDist');
    if (youEl) youEl.textContent = t('lmLocated');
    if (best && nearEl) nearEl.textContent = (best.flag ? best.flag + ' ' : '') + best.name;
    if (best && distEl) distEl.textContent = distTxt;
    LFT.map.flyTo(ev.latlng, Math.max(LFT.map.getZoom(), 10));
  });
  map.on('locationerror', function () {
    var el3 = $('#lmYou');
    if (el3) el3.textContent = t('lmTapLocate');
    if (typeof toast === 'function') toast(t('lmGeoDenied'));
  });
}

/* Static SVG fallback (spec: shown whenever OSRM or Leaflet is unavailable).
   Same honest admin-position display as before, plus the journey timeline. */
/* Shown only when Leaflet or OSRM fails to load — a static route map
   (no live bus position anywhere, honest by design) plus the scheduled
   journey timeline. pct is always 0: this is a route preview, not a
   position feed. */
function renderTrackFallback(box, b) {
  cleanupTracker();
  if (!box) box = $('#trackBody');
  if (!box) return;
  var r = b ? (routeById(b.routeId) || {}) : {};
  // reverse = the return leg (origin on the Nepal side). The old test was
  // `from !== 'Ahmedabad'`, which flipped every outbound trip once the
  // forward run started from Surat.
  var reverse = b ? isNepalPoint(r.from) : false;
  box.innerHTML = '<div class="c-card">'
    + '<div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:baseline">'
    + '<b style="font-family:var(--f-display);font-size:17px">' + esc(b ? ((r.from || '?') + ' → ' + (r.to || '?')) : (ROUTE_STOPS.length >= 2 ? (ROUTE_STOPS[0].name + ' ⇄ ' + ROUTE_STOPS[ROUTE_STOPS.length - 1].name) : 'Route')) + '</b>'
    + (b ? '<small style="color:var(--muted)">' + esc(b.id) + ' · ' + fmtDate(b.date) + '</small>'
         : '<small style="color:var(--muted)">Static route map — interactive map could not load</small>')
    + '</div>'
    + '<div style="margin:14px 0;overflow-x:auto">' + trackMapSVG(0, reverse) + '</div>'
    + '<div class="verify-note">ℹ️ <span>' + t('trkFallbackNote') + '</span></div>'
    + '</div>'
    + '<details class="c-card" style="margin-top:12px;cursor:pointer">'
    + '<summary style="font-family:var(--f-display);font-size:15px;font-weight:700;padding:4px 0">📍 Route Stops &amp; Distance Timeline</summary>'
    + '<div style="margin-top:12px" id="trkJourneyTimeline"></div>'
    + '</details>'
    + '<div class="status-actions" style="margin-top:14px">'
    + (b ? '<a class="btn btn-blue" href="#/ticket/' + esc(b.id) + '">🎫 ' + t('st5') + '</a>' : '')
    + '<a class="btn btn-ghost" href="#/">' + t('btnHome') + '</a>'
    + '</div>';
  renderJourneyTimeline($('#trkJourneyTimeline'), 0, reverse, r);
  positionTrackMarker();
}

function cleanupTracker() {
  if (LFT.io) { LFT.io.disconnect(); LFT.io = null; }
  if (LFT.map) { try { LFT.map.remove(); } catch (e) {} LFT.map = null; }
  LFT.you = null;
  // Full Map tab has its own separate Leaflet instance — clean it up too,
  // and reset `booted` so reopening #/track lazy-boots it fresh next time.
  if (FM.map) { try { FM.map.remove(); } catch (e) {} FM.map = null; }
  FM.booted = false; FM.cluster = null; FM.cityLayer = null;
  FM.routeLine = null; FM.stopMarkers = []; FM.you = null;
}

/* ================================================================
   [JS] 13b. TRIP COMPANION — ixigo-style "My Trip" per PNR
   Firebase live tracking, weather, leave-by, phase management.
   Replaces the old static Track My Bus with a rich trip experience.
================================================================ */
var TC = {
  pnr: null, booking: null, tripId: null,
  phase: 0,  // 0=pre-trip, 1=departure, 2=live, 3=arrived
  map: null, busMarker: null, paxMarker: null,
  watchId: null, fbRef: null, fbUnsub: null,
  countdownT: null, geoT: null, manualT: null, wxFetched: false,
  lastBusPos: null, targetBusPos: null, interpFrame: null,
  routeCoords: null, routeCumKm: null, routeTotalKm: 1375
};
var TC_FIREBASE_CONFIG = {
  apiKey:            'YOUR_API_KEY',
  authDomain:        'YOUR_PROJECT.firebaseapp.com',
  databaseURL:       'https://YOUR_PROJECT-default-rtdb.firebaseio.com',
  projectId:         'YOUR_PROJECT',
  storageBucket:     'YOUR_PROJECT.appspot.com',
  messagingSenderId: '000000000000',
  appId:             '1:000000000000:web:0000000000000000'
};
var TC_OWM_KEY = '';  // OpenWeatherMap free API key — leave empty for demo

function renderTrip(pnr) {
  TC.pnr = pnr;
  TC.booking = null;
  if (DB && DB.bookings) {
    TC.booking = DB.bookings.find(function(b) { return b.id === pnr; }) || null;
  }
  tcPopulateHero();
  tcPopulateChecklist();
  tcDeterminePhase();
  tcPopulateCards();
  tcFetchWeather();
  tcStartLeaveBy();
  tcStartCountdown();
  tcInitLiveTimeline();
  tcInitFirebase();
  tcInitManualLive();
}

/* Admin-driven LIVE bus position (cross-device, no GPS/Firebase).
   Reads the shared `livebus` key an admin wrote from Live Ops -> Set position,
   and shows a banner "Bus at X · next Y · Zm ago". Polls every 40s while the
   Trip Companion is open. A stale mark (> 5h) is ignored so yesterday's bus
   never shows as live. */
function tcRenderManualLive(lb) {
  var box = document.getElementById('tcManualLive');
  if (!box) return;
  var FRESH = 5 * 3600 * 1000;
  if (!lb || !lb.stop || !lb.at || (Date.now() - lb.at) > FRESH) { box.hidden = true; TC.liveGps = false; return; }
  var stopEl = document.getElementById('tlbStop');
  var nextEl = document.getElementById('tlbNext');
  var agoEl  = document.getElementById('tlbAgo');
  /* GPS payload (4 Sep 2026): a signed-in driver's phone writes lat / lng /
     speed to the same shared key every ~10 s (see onSnPos in the navigator).
     A fix younger than 3 minutes is LIVE: the banner shows the speed and the
     🚌 marker on the trip map moves through the lerp the old Firebase path
     used — nothing new is drawn, it is just fed from the shared store. */
  var live = !!lb.gps && lb.lat != null && lb.lng != null && (Date.now() - lb.at) < 3 * 60000;
  if (stopEl) stopEl.textContent = (typeof t === 'function' ? t('tlbAt') : 'Bus is at') + ' ' + lb.stop + (live ? ' 🛰️' : '');
  if (nextEl) nextEl.textContent = lb.next ? ((typeof t === 'function' ? t('tlbNext') : 'Next') + ': ' + lb.next) : '';
  if (agoEl) {
    var mins = Math.max(0, Math.round((Date.now() - lb.at) / 60000));
    var ago = mins < 1 ? 'just now' : (mins < 60 ? mins + 'm ago' : Math.round(mins / 60) + 'h ago');
    var kmh = (live && lb.spd != null) ? Math.max(0, Math.round(lb.spd * 3.6)) : null;
    agoEl.textContent = ago + (kmh != null ? ' · ' + kmh + ' km/h' : '');
  }
  box.hidden = false;
  TC.liveGps = live;
  if (live && TC.map && typeof tcUpdateBusPosition === 'function') {
    var key = String(lb.at);
    if (TC.lastLiveKey !== key) {
      TC.lastLiveKey = key;
      try {
        tcUpdateBusPosition({ lat: lb.lat, lng: lb.lng, bearing: lb.bearing != null ? lb.bearing : null, speed: lb.spd != null ? lb.spd : null });
      } catch (e) {}
    }
  }
}
function tcInitManualLive() {
  if (TC.manualT != null) { clearInterval(TC.manualT); TC.manualT = null; }
  TC.manualMs = 0;
  /* 40 s while the office only types a stop name; 12 s while a driver's GPS
     is streaming (the kv_get budget is 240 / min per IP — this uses 5). */
  var arm = function () {
    var want = TC.liveGps ? 12000 : 40000;
    if (TC.manualMs === want && TC.manualT != null) return;
    if (TC.manualT != null) clearInterval(TC.manualT);
    TC.manualMs = want;
    TC.manualT = setInterval(pull, want);
  };
  function pull() {
    try {
      store.get('shg:livebus', null).then(function (lb) { tcRenderManualLive(lb); arm(); }).catch(function () {});
    } catch (e) {}
  }
  pull();
  arm();
}

function cleanupTrip() {
  if (TC.watchId != null) { navigator.geolocation.clearWatch(TC.watchId); TC.watchId = null; }
  if (TC.fbUnsub) { TC.fbUnsub(); TC.fbUnsub = null; }
  if (TC.interpFrame != null) { cancelAnimationFrame(TC.interpFrame); TC.interpFrame = null; }
  if (TC.countdownT != null) { clearInterval(TC.countdownT); TC.countdownT = null; }
  if (TC.manualT != null) { clearInterval(TC.manualT); TC.manualT = null; }
  if (TC.geoT != null) { clearInterval(TC.geoT); TC.geoT = null; }
  TC.map = null; TC.busMarker = null; TC.paxMarker = null;
  TC.wxFetched = false; TC.lastBusPos = null; TC.targetBusPos = null;
  if (typeof ltStop === 'function') ltStop(false);
}

/* ================================================================
   [JS] 13b-LT. LIVE GPS TIMELINE — self-contained, phone-GPS-driven
   all-stops tracker inside the Trip Companion. NO map library, NO
   Firebase: the passenger's own phone GPS drives the bus position,
   projected onto the ROUTE_STOPS corridor (cumulative km) to give
   progress · speed · distance-remaining · ETA · next halt. Runs even
   offline. Kept fully independent of the MapLibre live section
   (tcLiveSection) so it can never break it. Cleared by cleanupTrip().
================================================================ */
var LT = { watchId: null, built: false, lastFix: null, spd: [], destKm: 1375, endKm: 1375 };

function ltAmenStr(s) {
  if (!s || !s.amen) return '';
  var a = [];
  if (s.amen.meal || s.amen.lunch) a.push('🍽️');
  if (s.amen.fuel) a.push('⛽');
  if (s.amen.wash) a.push('🚻');
  if (s.amen.prayer) a.push('🙏');
  if (s.amen.hosp) a.push('🏥');
  if (s.amen.police) a.push('👮');
  if (s.amen.hotel) a.push('🏨');
  if (s.amen.border) a.push('🛃');
  return a.join(' ');
}

function ltStatus(msg, cls) {
  var el = $('#ltStatus'); if (!el) return;
  el.textContent = msg || '';
  el.className = 'lt-status' + (cls ? ' ' + cls : '');
}

/* Build the all-stops rail once and wire the Start/Pause buttons. */
function tcInitLiveTimeline() {
  var rail = $('#ltRail'); if (!rail) return;
  var cnt = $('#ltCount'); if (cnt) cnt.textContent = ROUTE_STOPS.length;
  LT.endKm = ROUTE_STOPS[ROUTE_STOPS.length - 1].km || 1375;

  var b = TC.booking;
  var destName = (b && b.to) || 'Rupaidiha';
  var destStop = ROUTE_STOPS.find(function (s) { return s.name === destName; }) || ROUTE_STOPS[ROUTE_STOPS.length - 1];
  LT.destKm = destStop.km || LT.endKm;

  var html = '<div class="lt-fill" id="ltFill"></div><div class="lt-bus" id="ltBus" style="display:none">🚌</div>';
  ROUTE_STOPS.forEach(function (s, i) {
    var amen = ltAmenStr(s);
    html += '<div class="lt-stop" data-i="' + i + '">'
      + '<div class="lt-marker"><span class="lt-dot"></span></div>'
      + '<div class="lt-info"><div class="lt-name">' + (s.flag ? s.flag + ' ' : '') + esc(s.name)
      + ' <span class="lt-km">' + s.km + ' km</span></div>'
      + (amen ? '<div class="lt-amen">' + amen + '</div>' : '')
      + '</div></div>';
  });
  rail.innerHTML = html;
  LT.built = true;

  var startBtn = $('#ltStartBtn'), stopBtn = $('#ltStopBtn');
  if (startBtn) startBtn.onclick = ltStart;
  if (stopBtn) stopBtn.onclick = function () { ltStop(true); };

  // Static starting state: highlight the boarding stop, no bus yet.
  var board = (b && b.boarding && parseBP ? parseBP(b.boarding).name : (b && b.boarding)) || ROUTE_STOPS[0].name;
  var bi = ROUTE_STOPS.findIndex(function (s) { return s.name === board; });
  if (bi < 0) bi = 0;
  requestAnimationFrame(function () { ltMarkProgress(ROUTE_STOPS[bi].km, bi, 0, false); });
}

/* Vertical pixel centre of stop i's dot, relative to the rail. */
function ltDotCenterY(i) {
  var rail = $('#ltRail'); if (!rail) return 0;
  var row = rail.querySelector('.lt-stop[data-i="' + i + '"]');
  return row ? row.offsetTop + 11 : 0;
}

/* Repaint stop states + move the bus marker / progress fill. */
function ltMarkProgress(progressKm, segIdx, t, moving) {
  var rail = $('#ltRail'); if (!rail) return;
  var last = ROUTE_STOPS.length - 1;
  if (segIdx < 0) segIdx = 0; if (segIdx > last) segIdx = last;
  $$('.lt-stop', rail).forEach(function (r) {
    var i = +r.getAttribute('data-i');
    r.classList.remove('done', 'here', 'next');
    if (ROUTE_STOPS[i].km <= progressKm + 0.5) r.classList.add('done');
  });
  var hereRow = rail.querySelector('.lt-stop[data-i="' + segIdx + '"]');
  var nextRow = rail.querySelector('.lt-stop[data-i="' + Math.min(segIdx + 1, last) + '"]');
  if (hereRow) hereRow.classList.add('here');
  if (nextRow && segIdx < last) nextRow.classList.add('next');

  var bus = $('#ltBus'), fill = $('#ltFill');
  var y0 = ltDotCenterY(0);
  var ya = ltDotCenterY(segIdx), yb = ltDotCenterY(Math.min(segIdx + 1, last));
  var busY = ya + (yb - ya) * Math.max(0, Math.min(1, t || 0));
  if (fill) { fill.style.top = y0 + 'px'; fill.style.height = Math.max(0, busY - y0) + 'px'; }
  if (bus && moving) { bus.style.display = 'block'; bus.style.top = (busY - 9) + 'px'; }
}

/* Project a lat/lng onto the route polyline (equirectangular planar
   approximation — accurate enough over this corridor). Returns the
   travelled km, the segment index + fraction, off-route km, and the
   index of the nearest named stop. */
function ltProject(lat, lng) {
  var refLat = lat * Math.PI / 180, R = 6371;
  function xy(la, lo) { return { x: R * (lo * Math.PI / 180) * Math.cos(refLat), y: R * (la * Math.PI / 180) }; }
  var P = xy(lat, lng);
  var best = { d: Infinity, seg: 0, t: 0, km: 0 };
  for (var i = 0; i < ROUTE_STOPS.length - 1; i++) {
    var A = xy(ROUTE_STOPS[i].lat, ROUTE_STOPS[i].lng), B = xy(ROUTE_STOPS[i + 1].lat, ROUTE_STOPS[i + 1].lng);
    var ABx = B.x - A.x, ABy = B.y - A.y, APx = P.x - A.x, APy = P.y - A.y;
    var len2 = ABx * ABx + ABy * ABy || 1e-9;
    var tt = Math.max(0, Math.min(1, (APx * ABx + APy * ABy) / len2));
    var cx = A.x + tt * ABx, cy = A.y + tt * ABy;
    var d = Math.sqrt((P.x - cx) * (P.x - cx) + (P.y - cy) * (P.y - cy));
    if (d < best.d) { best = { d: d, seg: i, t: tt, km: ROUTE_STOPS[i].km + tt * (ROUTE_STOPS[i + 1].km - ROUTE_STOPS[i].km) }; }
  }
  var nd = Infinity, ni = 0;
  ROUTE_STOPS.forEach(function (s, i) { var d = haversineKm(lat, lng, s.lat, s.lng); if (d < nd) { nd = d; ni = i; } });
  return { progressKm: best.km, t: best.t, segIdx: best.seg, offKm: best.d, nearIdx: ni };
}

function ltStart() {
  if (!navigator.geolocation) { ltStatus('GPS not supported on this device', 'err'); return; }
  ltStatus('📡 Locating you…', 'on');
  var sb = $('#ltStartBtn'), tb = $('#ltStopBtn');
  if (sb) sb.style.display = 'none';
  if (tb) tb.style.display = '';
  LT.spd = []; LT.lastFix = null;
  LT.watchId = navigator.geolocation.watchPosition(ltOnFix, function (err) {
    ltStatus(err && err.code === 1 ? '⚠️ Location permission denied — allow it to track' : '⚠️ Location unavailable — retrying…', 'err');
  }, { enableHighAccuracy: true, maximumAge: 2000, timeout: 15000 });
}

function ltStop(userPaused) {
  if (LT.watchId != null) { navigator.geolocation.clearWatch(LT.watchId); LT.watchId = null; }
  var sb = $('#ltStartBtn'), tb = $('#ltStopBtn');
  if (sb) sb.style.display = '';
  if (tb) tb.style.display = 'none';
  if (userPaused) ltStatus('⏸️ Tracking paused', '');
}

function ltOnFix(pos) {
  var lat = pos.coords.latitude, lng = pos.coords.longitude;
  var now = pos.timestamp || Date.now();
  var proj = ltProject(lat, lng);

  // Speed: prefer the device value, else derive from the previous fix.
  var spd = (pos.coords.speed != null && pos.coords.speed >= 0) ? pos.coords.speed * 3.6 : null;
  if (spd == null && LT.lastFix) {
    var dtH = (now - LT.lastFix.t) / 3600000;
    if (dtH > 0) spd = haversineKm(lat, lng, LT.lastFix.lat, LT.lastFix.lng) / dtH;
  }
  LT.lastFix = { lat: lat, lng: lng, t: now };
  if (spd != null && isFinite(spd)) { LT.spd.push(spd); if (LT.spd.length > 5) LT.spd.shift(); }
  var avgSpd = LT.spd.length ? LT.spd.reduce(function (a, c) { return a + c; }, 0) / LT.spd.length : 0;

  var progressKm = proj.progressKm;
  var pct = Math.max(0, Math.min(100, progressKm / (LT.endKm || 1) * 100));
  var remain = Math.max(0, LT.destKm - progressKm);

  ltMarkProgress(progressKm, proj.segIdx, proj.t, true);

  var setTxt = function (id, v) { var e = $(id); if (e) e.textContent = v; };
  setTxt('#ltProg', Math.round(pct) + '%');
  setTxt('#ltSpeed', avgSpd > 1 ? Math.round(avgSpd) : (spd != null ? Math.max(0, Math.round(spd)) : '0'));
  setTxt('#ltRemain', remain > 10 ? Math.round(remain) + ' km' : remain.toFixed(1) + ' km');
  var useSpd = Math.max(avgSpd, 35);
  var etaH = remain / useSpd, eh = Math.floor(etaH), em = Math.round((etaH - eh) * 60);
  var etaStr = remain < 1 ? 'Arrived' : (eh > 0 ? eh + 'h ' + em + 'm' : em + 'm');
  setTxt('#ltEta', etaStr);
  // The prominent headline banner (Zomato-style). "Arrived 🎉" when there,
  // otherwise the same ETA string, larger and first.
  setTxt('#ltEtaBig', remain < 1 ? 'Arrived 🎉' : etaStr);
  var lehLbl = $('#ltEtaHeroLbl'); if (lehLbl) lehLbl.textContent = remain < 1 ? 'Your bus has' : 'Arriving in';
  var lehDest = $('#ltEtaDest'); if (lehDest && TC.booking && TC.booking.to) lehDest.textContent = TC.booking.to;

  var nextStop = null;
  for (var i = 0; i < ROUTE_STOPS.length; i++) { if (ROUTE_STOPS[i].km > progressKm + 0.5) { nextStop = ROUTE_STOPS[i]; break; } }
  var nb = $('#ltNext');
  if (nb) {
    if (nextStop && remain > 0.5) {
      nb.style.display = '';
      setTxt('#ltNextName', 'Next: ' + (nextStop.flag ? nextStop.flag + ' ' : '') + nextStop.name);
      var toNext = nextStop.km - progressKm, nmin = Math.round(toNext / useSpd * 60), amen = ltAmenStr(nextStop);
      setTxt('#ltNextMeta', (toNext < 10 ? toNext.toFixed(1) : Math.round(toNext)) + ' km · ~' + nmin + ' min' + (amen ? ' · ' + amen : ''));
    } else { nb.style.display = 'none'; }
  }

  var off = proj.offKm > 25 ? ' · ⚠️ ' + Math.round(proj.offKm) + ' km off route' : '';
  ltStatus('🟢 Live · nearest ' + ROUTE_STOPS[proj.nearIdx].name + off, 'on');
}

function tcPopulateHero() {
  var b = TC.booking;
  var origin = (b && b.from) || ROUTE_STOPS[0].name;
  var dest = (b && b.to) || ROUTE_STOPS[ROUTE_STOPS.length - 1].name;
  var code = function(c) { return c.substring(0, 3).toUpperCase(); };
  $('#tcOriginCity').innerHTML = code(origin) + '<small>' + esc(origin) + '</small>';
  $('#tcDestCity').innerHTML = code(dest) + '<small>' + esc(dest) + '</small>';
  $('#tcPnrCode').textContent = TC.pnr ? TC.pnr.substring(0, 10) : '—';
  if (b) {
    $('#tcTripDate').textContent = b.date || '—';
    $('#tcCabinType').textContent = b.cabinLabel || b.seatLabel || 'AC Sleeper';
  }
}

function tcPopulateChecklist() {
  var items = CONFIG.booking.checklist;
  var stored = {};
  try { stored = JSON.parse(localStorage.getItem('tc:chk:' + TC.pnr) || '{}'); } catch (e) {}
  var html = '';
  items.forEach(function(item, i) {
    var checked = stored[i] ? 'checked' : '';
    var cls = stored[i] ? 'checked' : '';
    html += '<li><input type="checkbox" data-chk="' + i + '" ' + checked + '><span class="' + cls + '">' + esc(item) + '</span></li>';
  });
  $('#tcChecklist').innerHTML = html;
  $$('#tcChecklist input[type=checkbox]').forEach(function(cb) {
    cb.addEventListener('change', function() {
      var idx = cb.getAttribute('data-chk');
      var s = {}; try { s = JSON.parse(localStorage.getItem('tc:chk:' + TC.pnr) || '{}'); } catch (e) {}
      if (cb.checked) s[idx] = true; else delete s[idx];
      try { localStorage.setItem('tc:chk:' + TC.pnr, JSON.stringify(s)); } catch (e) {}
      var span = cb.nextElementSibling;
      if (span) span.classList.toggle('checked', cb.checked);
    });
  });
}

function tcDeterminePhase() {
  var b = TC.booking;
  if (!b || !b.date) { TC.phase = 0; return; }
  var dep = new Date(b.date);
  if (isNaN(dep.getTime())) { TC.phase = 0; return; }
  dep.setHours(8, 15, 0, 0);  // default departure 08:15
  var now = Date.now();
  var tMinus2h = dep.getTime() - 2 * 3600000;
  if (b.status === 'cancelled') { TC.phase = 0; return; }
  if (now >= dep.getTime() + 33 * 3600000) { TC.phase = 3; return; }  // ~33h after dep = arrived
  if (now >= dep.getTime()) { TC.phase = 2; return; }
  if (now >= tMinus2h) { TC.phase = 1; return; }
  TC.phase = 0;
}

function tcPopulateCards() {
  // Update timeline dots
  for (var i = 0; i <= 3; i++) {
    var el = $('#tcPhase' + i);
    if (!el) continue;
    el.classList.remove('done', 'active', 'future');
    if (i < TC.phase) { el.classList.add('done'); el.querySelector('.dot').textContent = '✓'; }
    else if (i === TC.phase) { el.classList.add('active'); el.querySelector('.dot').textContent = '✓'; }
    else { el.classList.add('future'); el.querySelector('.dot').textContent = (i + 1); }
  }
  // Show/hide cards by phase
  var show = function(id, yes) { var e = $(id); if (e) e.style.display = yes ? '' : 'none'; };
  show('#tcChecklistCard', TC.phase <= 1);
  show('#tcWeatherCard', TC.phase <= 1);
  show('#tcLeaveByCard', TC.phase <= 1);
  show('#tcBoardingCard', TC.phase <= 1);
  show('#tcCabinCard', TC.phase <= 1);
  show('#tcCountdownCard', TC.phase === 1);
  show('#tcLiveSection', TC.phase === 2);
  show('#tcHaltBanner', false);
  show('#tcArrivedSection', TC.phase === 3);

  // Cabin info
  var b = TC.booking;
  if (b) {
    $('#tcCabinTypeFull').textContent = b.cabinLabel || b.seatLabel || 'Sleeper';
    $('#tcCabinPax').textContent = (b.passengers ? b.passengers.length : b.seats ? b.seats.length : 1) + ' pax';
    $('#tcCabinFare').textContent = '₹' + (b.totalFare || b.fare || '—');
    $('#tcCabinStatus').textContent = b.status === 'confirmed' ? 'Confirmed' : (b.status || 'Booked');
    $('#tcCabinStatus').style.color = b.status === 'confirmed' ? 'var(--ok)' : 'var(--warn)';
  }

  // Boarding point
  var boarding = (b && b.boarding) || (b && b.from) || ROUTE_STOPS[0].name;
  var bStop = ROUTE_STOPS.find(function(s) { return s.name === boarding; });
  $('#tcBoardingName').textContent = boarding;
  if (boarding === 'Mehsana' || boarding === 'Ahmedabad') {
    $('#tcBoardingAddr').textContent = boarding === 'Mehsana' ? 'Near Shilpa Garage, Silver Complex, Mehsana – 384002' : 'Ahmedabad, Gujarat';
  } else {
    $('#tcBoardingAddr').textContent = boarding;
  }

  // Arrived
  if (TC.phase === 3) {
    var dest = (b && b.to) || 'Rupaidiha';
    $('#tcArrivedTitle').textContent = 'Arrived at ' + dest;
    $('#tcArrivedTs').textContent = 'Journey completed';
  }

  // Live map
  if (TC.phase === 2) tcInitLiveMap();
}

/* ── WEATHER (OpenWeatherMap free tier) ── */
function tcFetchWeather() {
  if (TC.wxFetched || !TC_OWM_KEY) {
    if (!TC_OWM_KEY) {
      $('#tcWxOriginTemp').textContent = '—';
      $('#tcWxOriginCond').textContent = 'Set OWM key for live weather';
      $('#tcWxDestTemp').textContent = '—';
      $('#tcWxDestCond').textContent = '';
    }
    return;
  }
  TC.wxFetched = true;
  var b = TC.booking;
  var origin = ROUTE_STOPS.find(function(s) { return s.name === ((b && b.from) || ROUTE_STOPS[0].name); }) || ROUTE_STOPS[0];
  var dest = ROUTE_STOPS.find(function(s) { return s.name === ((b && b.to) || ROUTE_STOPS[ROUTE_STOPS.length - 1].name); }) || ROUTE_STOPS[ROUTE_STOPS.length - 1];
  tcFetchWx(origin, 'Origin');
  tcFetchWx(dest, 'Dest');
}
function tcFetchWx(stop, prefix) {
  var url = 'https://api.openweathermap.org/data/2.5/weather?lat=' + stop.lat + '&lon=' + stop.lng + '&units=metric&appid=' + TC_OWM_KEY;
  fetch(url).then(function(r) { return r.json(); }).then(function(d) {
    if (!d || !d.main) return;
    $('#tcWx' + prefix + 'Temp').textContent = Math.round(d.main.temp) + '°C';
    $('#tcWx' + prefix + 'Cond').textContent = d.weather && d.weather[0] ? d.weather[0].description : '';
    $('#tcWx' + prefix + 'Name').textContent = stop.name;
  }).catch(function() {});
}

/* ── LEAVE-BY CALCULATOR ── */
function tcStartLeaveBy() {
  var b = TC.booking;
  if (!b || !b.date || TC.phase > 1) return;
  if (!navigator.geolocation) {
    $('#tcLeaveByDist').textContent = 'GPS not available';
    return;
  }
  navigator.geolocation.getCurrentPosition(function(pos) {
    var boarding = (b && b.boarding) || (b && b.from) || ROUTE_STOPS[0].name;
    var bStop = ROUTE_STOPS.find(function(s) { return s.name === boarding; }) || ROUTE_STOPS[0];
    var dist = haversineKm(pos.coords.latitude, pos.coords.longitude, bStop.lat, bStop.lng);
    var speedKmh = CONFIG.booking.assumedTravelSpeedKmh || 40;
    var travelMin = Math.ceil((dist / speedKmh) * 60);
    var bufferMin = 60;
    var dep = new Date(b.date);
    dep.setHours(8, 15, 0, 0);
    var leaveBy = new Date(dep.getTime() - (travelMin + bufferMin) * 60000);
    var hh = leaveBy.getHours(), mm = leaveBy.getMinutes();
    $('#tcLeaveByTime').textContent = (hh < 10 ? '0' : '') + hh + ':' + (mm < 10 ? '0' : '') + mm;
    $('#tcLeaveByDist').textContent = 'Distance: ' + dist.toFixed(1) + ' km · Travel ~' + travelMin + ' min';
    // Urgency
    if (TC.phase === 1 && Date.now() > leaveBy.getTime()) {
      $('#tcLeaveByCard').classList.add('urgent');
      $('#tcLeaveByTime').style.color = 'var(--warn)';
    }
  }, function() {
    $('#tcLeaveByDist').textContent = 'Allow GPS to calculate';
  }, { enableHighAccuracy: false, timeout: 8000 });
}

/* ── COUNTDOWN ── */
function tcStartCountdown() {
  if (TC.countdownT) clearInterval(TC.countdownT);
  if (TC.phase !== 1) return;
  var b = TC.booking;
  if (!b || !b.date) return;
  var dep = new Date(b.date);
  dep.setHours(8, 15, 0, 0);
  function tick() {
    var diff = dep.getTime() - Date.now();
    if (diff <= 0) { $('#tcCountdown').textContent = 'Departing now!'; clearInterval(TC.countdownT); return; }
    var h = Math.floor(diff / 3600000);
    var m = Math.floor((diff % 3600000) / 60000);
    var s = Math.floor((diff % 60000) / 1000);
    $('#tcCountdown').textContent = (h > 0 ? h + 'h ' : '') + (m < 10 ? '0' : '') + m + 'm ' + (s < 10 ? '0' : '') + s + 's';
  }
  tick();
  TC.countdownT = setInterval(tick, 1000);
}

/* ── FIREBASE INIT (passenger side — read only) ── */
function tcInitFirebase() {
  /* 4 Sep 2026: Firebase was never configured (placeholder project) and the
     security policy blocked its scripts anyway — every trip page paid two
     failed downloads for nothing. The passenger side now reads the shared
     `livebus` store (tcInitManualLive) plus the same-browser shim below.
     TC_FIREBASE_CONFIG / tcListenLive stay as dormant code. */
  if (TC.phase !== 2) return;
  tcFallbackToLocalTracking();
}

function tcListenLive() {
  var tripId = TC.tripId || TC.pnr;
  if (!tripId) {
    tcFallbackToLocalTracking();
    return;
  }
  try {
    var db = firebase.database();
    var locRef = db.ref('trips/' + tripId + '/liveLocation');
    var haltRef = db.ref('trips/' + tripId + '/meta/status');
    var onLoc = locRef.on('value', function(snap) {
      var d = snap.val();
      if (!d || !d.lat) return;
      tcUpdateBusPosition(d);
    });
    var onHalt = haltRef.on('value', function(snap) {
      var status = snap.val();
      tcUpdateHaltStatus(status);
    });
    TC.fbUnsub = function() {
      locRef.off('value', onLoc);
      haltRef.off('value', onHalt);
    };
  } catch (e) {
    tcFallbackToLocalTracking();
  }
}

function tcFallbackToLocalTracking() {
  TC.fbUnsub = subscribeLocation(function(loc) {
    if (loc && loc.lat) tcUpdateBusPosition(loc);
  });
}

/* ================================================================
   V7 UPGRADE — one MapLibre GL engine, loaded once (Aug 2026).
   The library used to be fetched twice at two different versions
   (4.5.0 here for the Trip Companion map, 4.7.1 in the Nav app), so
   visiting both views downloaded and parsed the whole engine twice.
   One pinned version + one shared promise: every caller awaits the
   same load, and a second view reuses the already-parsed engine.
   This also fixes two real bugs in the old loaders — a double
   <script> append here, and a dropped callback in Nav's
   ensureMapLibre() when a load was already in flight.
================================================================ */

/* ── LIVE MAP ── */
function tcInitLiveMap() {
  if (TC.map) return;
  var mapDiv = $('#tcMap');
  if (!mapDiv) return;
  loadMapLibre()
    .then(function () { if (!TC.map) tcBuildMap(mapDiv); })
    .catch(function () { toast('Failed to load map engine'); });
}

function tcBuildMap(container) {
  TC.map = new maplibregl.Map({
    container: container,
    style: 'https://tiles.openfreemap.org/styles/liberty',
    center: [77.5, 25.5],
    zoom: 5,
    attributionControl: false,
    /* 5 Sep 2026: same feel as the navigator — tiles cross-fade, a sensible
       tilt ceiling, fenced to the India + Nepal corridor. */
    fadeDuration: 250, maxPitch: 60, maxBounds: [[61.0, 4.5], [99.5, 38.5]], minZoom: 3.8
  });
  TC.map.addControl(new maplibregl.AttributionControl({ compact: true }), 'bottom-left');
  TC.map.on('load', function() {
    tcDrawRouteOnMap();
    tcStartPaxTracking();
  });
  if (TC.map.loaded()) {
    tcDrawRouteOnMap();
    tcStartPaxTracking();
  }
}

/* Route + stops from the DATABASE (4 Sep 2026). /api/timetable.php ships
   every pickup and drop with its coordinates — the same route_stops rows the
   boarding cut-off uses — so the map no longer depends on a hand-typed list.
   The passenger's OWN pickup and drop are drawn bigger, in brand orange,
   with a label; other stops are small blue (pickup) / green (drop) dots.
   ROUTE_STOPS stays as the offline fallback. */
function tcRouteStopsFromDb() {
  var b = TC.booking;
  if (!b || !b.routeId || typeof shgApi === 'undefined') return Promise.resolve(null);
  if (TC.ttStops && TC.ttStops.routeId === b.routeId) return Promise.resolve(TC.ttStops.stops.length >= 2 ? TC.ttStops.stops : null);
  return ((typeof shgTimetableGet === 'function') ? shgTimetableGet(b.date || '') : shgApi.get('/timetable.php?date=' + encodeURIComponent(b.date || ''))).then(function (d) {
    var r = ((d && d.routes) || []).find(function (x) { return x.routeCode === b.routeId; });
    if (!r) return null;
    var stops = [];
    (r.boarding || []).forEach(function (s) { if (s.lat != null && s.lng != null) stops.push({ name: s.name, lat: s.lat, lng: s.lng, time: s.time || '', kind: 'pickup' }); });
    (r.drop || []).forEach(function (s) { if (s.lat != null && s.lng != null) stops.push({ name: s.name, lat: s.lat, lng: s.lng, time: s.time || '', kind: 'drop' }); });
    TC.ttStops = { routeId: b.routeId, stops: stops };
    return stops.length >= 2 ? stops : null;
  }).catch(function () { return null; });
}
function tcDrawRouteOnMap() {
  if (!TC.map || TC.map.getSource('tc-route')) return;
  tcRouteStopsFromDb().then(function (dbStops) {
    if (!TC.map || TC.map.getSource('tc-route')) return;
    var stops = dbStops || ROUTE_STOPS.map(function (s) { return { name: s.name, lat: s.lat, lng: s.lng, time: '', kind: 'pickup' }; });
    var coords = stops.map(function (s) { return [s.lng, s.lat]; });
    TC.map.addSource('tc-route', {
      type: 'geojson',
      data: { type: 'Feature', geometry: { type: 'LineString', coordinates: coords } }
    });
    TC.map.addLayer({ id: 'tc-route-glow', type: 'line', source: 'tc-route',
      paint: { 'line-color': '#F07C1F', 'line-width': 10, 'line-opacity': 0.18, 'line-blur': 4 } });
    TC.map.addLayer({ id: 'tc-route-line', type: 'line', source: 'tc-route',
      paint: { 'line-color': '#F07C1F', 'line-width': 4, 'line-opacity': 0.85 } });

    var b = TC.booking || {};
    var myBoard = (typeof parseBP === 'function' ? (parseBP(b.boarding || '').name || '') : '');
    var myDrop  = (typeof parseBP === 'function' ? (parseBP(b.drop || '').name || '') : '');
    var norm = function (x) { return String(x || '').toLowerCase().replace(/[^a-z0-9]+/g, ''); };
    var isMine = function (s, mine) { var a = norm(s.name), m = norm(mine); return !!m && !!a && (a === m || a.indexOf(m) >= 0 || m.indexOf(a) >= 0); };
    var bounds = new maplibregl.LngLatBounds(coords[0], coords[0]);
    stops.forEach(function (s) {
      var mineB = s.kind === 'pickup' && isMine(s, myBoard);
      var mineD = s.kind === 'drop' && isMine(s, myDrop);
      var mine = mineB || mineD;
      var col = mine ? '#F07C1F' : (s.kind === 'drop' ? '#178A50' : '#2E5FA8');
      var el = document.createElement('div');
      el.style.cssText = 'width:' + (mine ? 18 : 10) + 'px;height:' + (mine ? 18 : 10) + 'px;background:' + (mine ? col : '#fff')
        + ';border:' + (mine ? 3 : 2) + 'px solid ' + col + ';border-radius:50%;box-shadow:0 2px 8px rgba(0,0,0,.25)';
      el.title = s.name + (s.time ? ' · ' + s.time : '');
      var mk = new maplibregl.Marker({ element: el, anchor: 'center' }).setLngLat([s.lng, s.lat]);
      if (mine) {
        mk.setPopup(new maplibregl.Popup({ offset: 14, closeButton: false, closeOnClick: false })
          .setText((mineB ? '🚏 Your pickup · ' : '🏁 Your drop · ') + s.name + (s.time ? ' · ' + s.time : '')));
      }
      mk.addTo(TC.map);
      if (mine) { try { mk.togglePopup(); } catch (e) {} }
      bounds.extend([s.lng, s.lat]);
    });
    try { TC.map.fitBounds(bounds, { padding: 48, maxZoom: 9, duration: 600 }); } catch (e) {}
  });
}

function tcStartPaxTracking() {
  if (!navigator.geolocation || TC.watchId != null) return;
  TC.watchId = navigator.geolocation.watchPosition(function(pos) {
    var lat = pos.coords.latitude, lng = pos.coords.longitude;
    if (!TC.paxMarker) {
      var el = document.createElement('div');
      el.style.cssText = 'width:14px;height:14px;background:#4285F4;border:2.5px solid #fff;border-radius:50%;box-shadow:0 0 8px rgba(66,133,244,.5)';
      TC.paxMarker = new maplibregl.Marker({ element: el }).setLngLat([lng, lat]).addTo(TC.map);
    } else {
      TC.paxMarker.setLngLat([lng, lat]);
    }
    tcCheckBusNearMe(lat, lng);
  }, null, { enableHighAccuracy: true, maximumAge: 3000 });
}

/* ── BUS POSITION UPDATE + INTERPOLATION ── */
function tcUpdateBusPosition(data) {
  TC.lastBusPos = TC.targetBusPos || data;
  TC.targetBusPos = data;
  if (TC.interpFrame) cancelAnimationFrame(TC.interpFrame);
  tcInterpolate(TC.lastBusPos, TC.targetBusPos, 0);
  tcUpdateLiveStats(data);
  tcCheckGeofences(data.lat, data.lng);
}

function tcInterpolate(from, to, progress) {
  if (progress > 1 || !TC.map) return;
  /* 5 Sep 2026: time-based ease-out (~1.1 s per fix) instead of +0.05 per
     frame, so the bus glides identically on 60 Hz and 120 Hz screens and
     decelerates into each fix like a real vehicle. */
  if (progress === 0) { TC._interpT0 = performance.now(); }
  var _el = performance.now() - (TC._interpT0 || performance.now());
  var _k = Math.min(1, _el / 1100), eased = 1 - Math.pow(1 - _k, 3);
  var lat = from.lat + (to.lat - from.lat) * eased;
  var lng = from.lng + (to.lng - from.lng) * eased;
  progress = _k;
  if (!TC.busMarker) {
    var el = document.createElement('div');
    el.style.cssText = 'width:30px;height:30px;display:flex;align-items:center;justify-content:center';
    /* The rotation lives on an INNER span (4 Sep 2026): MapLibre positions
       the marker by writing `transform` on the element itself, so rotating
       that element used to throw the bus off its coordinates. */
    el.innerHTML = '<span class="tc-bus-ico" style="display:block;font-size:22px;line-height:1;filter:drop-shadow(0 2px 4px rgba(0,0,0,.35))">🚌</span>';
    TC.busMarker = new maplibregl.Marker({ element: el, anchor: 'center' }).setLngLat([lng, lat]).addTo(TC.map);
    TC.map.flyTo({ center: [lng, lat], zoom: 10, duration: 1000 });
  } else {
    TC.busMarker.setLngLat([lng, lat]);
  }
  if (to.bearing != null) {
    /* Shortest-arc rotation (359° → 1° turns 2°, not 358°), eased with the position. */
    var fromB = (from.bearing != null) ? from.bearing : to.bearing;
    var delta = ((to.bearing - fromB) % 360 + 540) % 360 - 180;
    var rot   = fromB + delta * Math.min(1, progress);
    var ico   = TC.busMarker.getElement().querySelector('.tc-bus-ico');
    if (ico) ico.style.transform = 'rotate(' + rot + 'deg)';
  }
  if (progress >= 1) { TC.interpFrame = null; return; }
  TC.interpFrame = requestAnimationFrame(function() { tcInterpolate(from, to, progress + 0.0001); });
}

function tcUpdateLiveStats(data) {
  var b = TC.booking;
  var dest = ROUTE_STOPS.find(function(s) { return s.name === ((b && b.to) || 'Rupaidiha'); }) || ROUTE_STOPS[ROUTE_STOPS.length - 1];
  var remaining = haversineKm(data.lat, data.lng, dest.lat, dest.lng);
  $('#tcLiveDist').textContent = remaining > 10 ? Math.round(remaining) + ' km' : remaining.toFixed(1) + ' km';
  var speedKmh = data.speed != null ? Math.round(data.speed * 3.6) : 0;
  $('#tcLiveSpeed').textContent = speedKmh || '—';
  var avgSpeed = Math.max(speedKmh, 35);
  var etaH = remaining / avgSpeed;
  var h = Math.floor(etaH), m = Math.round((etaH - h) * 60);
  $('#tcLiveEta').textContent = h > 0 ? h + 'h ' + m + 'm' : m + ' min';

  // Next halt
  var bestStop = null, bestDist = Infinity;
  ROUTE_STOPS.forEach(function(s) {
    var d = haversineKm(data.lat, data.lng, s.lat, s.lng);
    if (d > 5 && d < bestDist) { bestDist = d; bestStop = s; }
  });
  if (bestStop) {
    var amenStr = '';
    if (bestStop.amen) {
      if (bestStop.amen.meal || bestStop.amen.lunch) amenStr += '🍽️ Meal ';
      if (bestStop.amen.fuel) amenStr += '⛽ Fuel ';
      if (bestStop.amen.wash) amenStr += '🚻 Washroom ';
      if (bestStop.amen.border) amenStr += '🛃 Border ';
      if (bestStop.amen.prayer) amenStr += '🙏 Prayer ';
      if (bestStop.amen.hosp) amenStr += '🏥 Hospital ';
    }
    $('#tcNextHaltName').textContent = 'Next: ' + bestStop.name;
    var etaMin = Math.round((bestDist / avgSpeed) * 60);
    $('#tcNextHaltMeta').textContent = bestDist.toFixed(0) + ' km · ~' + etaMin + ' min' + (amenStr ? ' · ' + amenStr.trim() : '');
  }
}

function tcUpdateHaltStatus(status) {
  var show = status === 'halted';
  var el = $('#tcHaltBanner');
  if (el) el.style.display = show ? '' : 'none';
  if (show) {
    var nearest = null, bestD = Infinity;
    if (TC.targetBusPos) {
      ROUTE_STOPS.forEach(function(s) {
        var d = haversineKm(TC.targetBusPos.lat, TC.targetBusPos.lng, s.lat, s.lng);
        if (d < bestD) { bestD = d; nearest = s; }
      });
    }
    var name = nearest ? nearest.name : 'a stop';
    $('#tcHaltText').textContent = 'Bus is halted at ' + name;
  }
  if (status === 'complete') {
    TC.phase = 3;
    tcPopulateCards();
  }
}

/* ── GEOFENCE BANNERS ── */
function tcCheckGeofences(lat, lng) {
  var banner = $('#tcGeoBanner');
  if (!banner) return;
  var closest = null, closestDist = Infinity;
  ROUTE_STOPS.forEach(function(s) {
    if (!s.amen || Object.keys(s.amen).length === 0) return;
    var d = haversineKm(lat, lng, s.lat, s.lng);
    if (d < 5 && d < closestDist) { closestDist = d; closest = s; }
  });
  if (closest) {
    var etaMin = Math.round((closestDist / 40) * 60);
    var msg = '';
    var cls = 'show ';
    if (closest.amen.border) { msg = '🛃 Approaching ' + closest.name + ' border — prepare documents'; cls += 'border'; }
    else if (closest.amen.meal || closest.amen.lunch) { msg = '🍽️ Meal halt in ~' + etaMin + ' min — ' + closest.name; cls += 'meal'; }
    else if (closest.amen.fuel) { msg = '⛽ Fuel stop in ~' + etaMin + ' min — ' + closest.name; cls += 'fuel'; }
    else { msg = '📍 Approaching ' + closest.name + ' in ~' + etaMin + ' min'; cls += 'meal'; }
    banner.textContent = msg;
    banner.className = 'tc-geobanner ' + cls;
    if (TC.geoT) clearTimeout(TC.geoT);
    TC.geoT = setTimeout(function() { banner.classList.remove('show'); }, 8000);
  }
}

/* ── BUS NEAR ME ALERT ── */
function tcCheckBusNearMe(paxLat, paxLng) {
  if (!TC.targetBusPos || TC.phase !== 2) return;
  var dist = haversineKm(paxLat, paxLng, TC.targetBusPos.lat, TC.targetBusPos.lng);
  if (dist < 10) {
    var banner = $('#tcGeoBanner');
    if (banner && !banner.classList.contains('show')) {
      banner.textContent = '🚌 Your bus is ' + dist.toFixed(1) + ' km away!';
      banner.className = 'tc-geobanner show near';
      if (TC.geoT) clearTimeout(TC.geoT);
      TC.geoT = setTimeout(function() { banner.classList.remove('show'); }, 6000);
    }
  }
}

/* ── BACK BUTTON ── */
document.addEventListener('click', function(e) {
  if (e.target.id === 'tcBack' || e.target.closest('#tcBack')) {
    location.hash = '#/my';
  }
});


/* ================================================================
   [JS] AI ASSISTANT — "SHG Sahayak". 100% offline rule-based intent
   engine (EN/HI/NE/GU) + Web Speech API voice input + voice search.
   No external AI calls — answers come from live DB data (fares,
   timings) and the policies already published on this site.
================================================================ */
(function () {
  const fab = $('#aiFab'), panel = $('#aiPanel');
  if (!fab || !panel) return;
  const msgs = $('#aiMsgs'), input = $('#aiInput'), chipsBox = $('#aiChips'), langSel = $('#aiLang');

  /* — live data helpers — */
  function depTimes() {
    // Boarding cities on the to-Nepal leg — the info a passenger actually
    // wants ("where does it stop"), from the real schedule so it never drifts.
    var r = (DB.routes || []).find(function(x){ return x && x.active && isNepalPoint(x.to); });
    if (r && r.boarding && r.boarding.length) {
      return r.boarding.map(function(b){ return String(b).split(' [')[0]; }).join(' · ');
    }
    return (DB.routes || []).filter(r => r.active).map(r => r.from + ' → ' + r.to + ': ' + r.depTime).join(' · ') || 'No active routes';
  }
  function cp() { try { return CONFIG.cabinPricing; } catch (e) { return null; } }
  function privFrom() { const p = cp(); return p ? inr(p.private.single_1pax.online) : '₹3,800'; }
  /* Sharing fares by direction (13 Sep 2026): the same public settings the
     server prices with (fare_to_nepal / fare_to_india, Fare::dirFares), then
     the active route's own fare, then the published 2000 / 1800. The chat used
     to quote a seater that no longer runs and a legacy per-person share. */
  function dirFareNum(dir) {
    try {
      const st = (window.SHG_BOOT && window.SHG_BOOT.settings) || {};
      const v = Number(st[dir === 'toIndia' ? 'fare_to_india' : 'fare_to_nepal']);
      if (v > 0) return v;
    } catch (e) {}
    const r = (DB.routes || []).find(function (x) {
      return x && x.active && (dir === 'toIndia' ? isNepalPoint(x.from) : isNepalPoint(x.to));
    });
    if (r && Number(r.fare) > 0) return Number(r.fare);
    return dir === 'toIndia' ? 1800 : 2000;
  }
  function shareFrom() { return inr(Math.min(dirFareNum('toNepal'), dirFareNum('toIndia'))); }

  /* — intents: [keywords], answers per lang — */
  function A(en, hi, ne, gu) { return { en: en, hi: hi, ne: ne, gu: gu }; }
  const INTENTS = [
    { k: ['fare', 'price', 'rate', 'cost', 'kiraya', 'kiraaya', 'bhada', 'bhaada', 'kitne ka', 'kitne rupay', 'kitne rupaye', 'kati parcha', 'kati parchha', 'kati lagcha', 'kati lagchha', 'ticket kitne', 'daam', 'किराया', 'कीमत', 'दाम', 'भाडा', 'कति पर्छ', 'पैसा', 'ભાડું', 'કિંમત', 'કેટલા'],
      a: () => {
        const go = '<b>' + inr(dirFareNum('toNepal')) + '</b>', back = '<b>' + inr(dirFareNum('toIndia')) + '</b>';
        return A(
          '🛏️ Sleeper, sharing: Gujarat → Rupaidiha ' + go + '/person · Rupaidiha → Gujarat ' + back + '/person · 🔒 Private cabin from ' + privFrom() + '. Same price online and at the counter. <a href="#/" data-scroll="pricing">Full pricing →</a>',
          '🛏️ स्लीपर, शेयरिंग: Gujarat → Rupaidiha ' + go + '/व्यक्ति · Rupaidiha → Gujarat ' + back + '/व्यक्ति · 🔒 प्राइवेट केबिन ' + privFrom() + ' से। ऑनलाइन और काउंटर पर एक ही किराया। <a href="#/" data-scroll="pricing">पूरी कीमतें →</a>',
          '🛏️ स्लिपर, सेयरिङ: Gujarat → Rupaidiha ' + go + '/व्यक्ति · Rupaidiha → Gujarat ' + back + '/व्यक्ति · 🔒 प्राइभेट क्याबिन ' + privFrom() + ' देखि। अनलाइन र काउन्टरमा एउटै भाडा। <a href="#/" data-scroll="pricing">पूरा मूल्य →</a>',
          '🛏️ સ્લીપર, શેરિંગ: Gujarat → Rupaidiha ' + go + '/વ્યક્તિ · Rupaidiha → Gujarat ' + back + '/વ્યક્તિ · 🔒 પ્રાઇવેટ કેબિન ' + privFrom() + ' થી. ઓનલાઇન અને કાઉન્ટર પર એક જ ભાડું. <a href="#/" data-scroll="pricing">સંપૂર્ણ ભાવ →</a>');
      } },
    { k: ['private', 'sharing', 'cabin', 'प्राइवेट', 'प्राईभेट', 'शेयरिंग', 'सेयरिङ', 'साझा', 'केबिन', 'क्याबिन', 'ખાનગી', 'શેરિંગ', 'કેબિન'],
      a: () => A(
        '🔒 <b>Private</b> = entire cabin only yours (couple/family, full privacy, VIP) from ' + privFrom() + '. 🤝 <b>Sharing</b> = per-berth booking with co-passengers, cheapest, from ' + shareFrom() + '/person.',
        '🔒 <b>प्राइवेट</b> = पूरा केबिन सिर्फ आपका (कपल/परिवार, पूरी प्राइवेसी, VIP) — ' + privFrom() + ' से। 🤝 <b>शेयरिंग</b> = बर्थ के हिसाब से, सबसे सस्ता — ' + shareFrom() + '/व्यक्ति से।',
        '🔒 <b>प्राइभेट</b> = पूरा क्याबिन तपाईंको मात्र (couple/family, पूर्ण गोपनीयता, VIP) — ' + privFrom() + ' देखि। 🤝 <b>सेयरिङ</b> = बर्थ अनुसार, सबैभन्दा किफायती — ' + shareFrom() + '/व्यक्ति।',
        '🔒 <b>પ્રાઇવેટ</b> = આખી કેબિન ફક્ત તમારી (કપલ/પરિવાર, સંપૂર્ણ પ્રાઇવસી, VIP) — ' + privFrom() + ' થી. 🤝 <b>શેરિંગ</b> = બર્થ પ્રમાણે, સૌથી સસ્તું — ' + shareFrom() + '/વ્યક્તિ.') },
    { k: ['cancel', 'refund', 'रद्द', 'रिफंड', 'रिफण्ड', 'फिर्ता', 'कैंसिल', 'क्यान्सिल', 'રદ', 'રિફંડ'],
      a: () => A(
        'Cancellation: ≥96h before departure → 90% refund · 48–96h → 75% · 24–48h → 50% · 6–24h → 25% · under 6h → no refund. Refund reaches the same account in 5–7 working days. <a href="#/terms">Full T&amp;C →</a>',
        'रद्दीकरण: प्रस्थान से ≥96 घंटे पहले → 90% रिफंड · 48–96 घंटे → 75% · 24–48 घंटे → 50% · 6–24 घंटे → 25% · 6 घंटे से कम → कोई रिफंड नहीं। रिफंड 5–7 कार्यदिवस में। <a href="#/terms">पूरे नियम →</a>',
        'रद्द: प्रस्थान भन्दा ≥96 घण्टा अगाडि → 90% फिर्ता · 48–96 घण्टा → 75% · 24–48 घण्टा → 50% · 6–24 घण्टा → 25% · 6 घण्टाभित्र → फिर्ता हुँदैन। रकम 5–7 कार्य दिनमा। <a href="#/terms">पूरा नियम →</a>',
        'કેન્સલેશન: ≥96 કલાક પહેલાં → 90% રિફંડ · 48–96 કલાક → 75% · 24–48 કલાક → 50% · 6–24 કલાક → 25% · 6 કલાકથી ઓછું → રિફંડ નહીં. રિફંડ 5–7 કાર્યદિવસમાં. <a href="#/terms">સંપૂર્ણ નિયમો →</a>') },
    { k: ['luggage', 'baggage', 'bag', 'सामान', 'झोला', 'लगेज', 'સામાન', 'બેગ'],
      a: () => A('1 suitcase (20 kg) + 1 cabin bag per passenger travels FREE. Excess baggage is charged. Valuables are your responsibility.',
        'प्रति यात्री 1 सूटकेस (20 किलो) + 1 केबिन बैग मुफ़्त। अतिरिक्त सामान पर शुल्क। कीमती सामान आपकी ज़िम्मेदारी।',
        'प्रति यात्रु 1 सुटकेस (20 kg) + 1 झोला निःशुल्क। बढी सामानमा शुल्क लाग्छ। बहुमूल्य सामान आफ्नै जिम्मेवारी।',
        'દરેક મુસાફર દીઠ 1 સૂટકેસ (20 કિલો) + 1 કેબિન બેગ મફત. વધારાના સામાન પર ચાર્જ. કિંમતી વસ્તુઓ તમારી જવાબદારી.') },
    { k: ['passport', 'border', 'document', ' id', 'आधार', 'दस्तावेज', 'कागजात', 'सीमा', 'नाका', 'बॉर्डर', 'બોર્ડર', 'દસ્તાવેજ'],
      a: () => A('A valid govt photo ID (passport / voter ID / Nepali citizenship) matching the ticket name is MANDATORY — checked at the Rupaidiha ⇄ Jamunaha border.',
        'टिकट के नाम से मेल खाता सरकारी फोटो ID (पासपोर्ट/वोटर ID/नेपाली नागरिकता) अनिवार्य — रुपईडिहा ⇄ जमुनाहा बॉर्डर पर जाँच होती है।',
        'टिकटको नामसँग मिल्ने सरकारी फोटो ID (राहदानी/मतदाता परिचयपत्र/नागरिकता) अनिवार्य — रुपईडिहा ⇄ जमुनाहा नाकामा जाँच हुन्छ।',
        'ટિકિટના નામ સાથે મેળ ખાતું સરકારી ફોટો ID (પાસપોર્ટ/વોટર ID/નેપાળી નાગરિકતા) ફરજિયાત — રૂપઈડિહા બોર્ડર પર ચેક થાય છે.') },
    { k: ['track', 'location', 'where', 'कहाँ', 'कहां', 'ट्र्याक', 'ट्रैक', 'लोकेशन', 'ક્યાં', 'ટ્રેક'],
      a: () => A('Open <a href="#/my">My Bookings</a> → your ticket → 🛰️ Track — live route progress, journey timeline and ETA.',
        '<a href="#/my">मेरी बुकिंग</a> → अपना टिकट → 🛰️ ट्रैक खोलें — लाइव प्रगति, यात्रा टाइमलाइन और ETA।',
        '<a href="#/my">मेरो बुकिङ</a> → आफ्नो टिकट → 🛰️ Track खोल्नुहोस् — लाइभ प्रगति, यात्रा समयरेखा र ETA।',
        '<a href="#/my">મારી બુકિંગ</a> → તમારી ટિકિટ → 🛰️ ટ્રેક ખોલો — લાઇવ પ્રગતિ અને ETA.') },
    { k: ['payment', 'upi', 'esewa', 'cod', 'cash', 'link', 'भुक्तानी', 'भुगतान', 'पेमेंट', 'नगद', 'ચુકવણી', 'રોકડ'],
      a: () => A('Pay by 🇮🇳 UPI, 🇳🇵 eSewa, 🔗 payment link (send to family), or 💵 cash at the boarding counter (COD). One fixed fare either way. Ticket releases after payment verification.',
        '🇮🇳 UPI, 🇳🇵 eSewa, 🔗 पेमेंट लिंक (परिवार को भेजें), या 💵 बोर्डिंग काउंटर पर नकद (COD)। एक तय किराया। भुगतान सत्यापन के बाद टिकट जारी।',
        '🇮🇳 UPI, 🇳🇵 eSewa, 🔗 भुक्तानी लिंक (परिवारलाई पठाउनुहोस्), वा 💵 बोर्डिङ काउन्टरमा नगद (COD)। एउटै तय भाडा। भुक्तानी प्रमाणित भएपछि टिकट जारी हुन्छ।',
        '🇮🇳 UPI, 🇳🇵 eSewa, 🔗 પેમેન્ટ લિંક, અથવા 💵 બોર્ડિંગ કાઉન્ટર પર રોકડ (COD). ચુકવણી ચકાસ્યા પછી ટિકિટ મળે છે.') },
    { k: ['contact', 'phone', 'office', 'number', 'call', 'whatsapp', 'सम्पर्क', 'संपर्क', 'फोन', 'नम्बर', 'नंबर', 'कार्यालय', 'ऑफिस', 'સંપર્ક', 'ફોન', 'નંબર'],
      a: () => {
        // Live from the contact strip so it always matches Admin -> Settings.
        var nums = (typeof contactNumbers === 'function') ? contactNumbers().filter(function(n){ return n.show && n.num; }) : [];
        var lines = nums.length
          ? nums.map(function(n){ return '📞 ' + (n.label ? n.label + ': ' : '') + n.num; }).join('<br>')
          : '📞 +91 91048 01507 (24×7)';
        var body = lines + '<br>✉️ shreehariglobalpvtltd@gmail.com<br><a href="#/" data-scroll="contact">Details →</a>';
        return A(body, body, body, body);
      } },
    { k: ['time', 'timing', 'departure', 'schedule', 'boarding', 'stop', 'stops', 'kaha', 'where', 'समय', 'कति बजे', 'कब', 'टाइम', 'बोर्डिङ', 'बोर्डिंग', 'कहाँ', 'कहां', 'ટાઈમ', 'ક્યારે'],
      a: () => A('Departures — ' + depTimes() + '. Report 60 min early at your boarding point (international route).',
        'प्रस्थान — ' + depTimes() + '। बोर्डिंग पॉइंट पर 60 मिनट पहले पहुँचें।',
        'प्रस्थान — ' + depTimes() + '। बोर्डिङ पोइन्टमा 60 मिनेट अगाडि आउनुहोस्।',
        'પ્રસ્થાન — ' + depTimes() + '. બોર્ડિંગ પોઇન્ટ પર 60 મિનિટ વહેલા પહોંચો.') },
    { k: ['sos', 'safety', 'women', 'emergency', 'महिला', 'सुरक्षा', 'આપાતકાલ', 'મહિલા', 'સુરક્ષા'],
      a: () => A('🛡️ Women-safe travel: reserved seats, CCTV, experienced crew, and a red 🆘 SOS button on your ticket & tracking pages (police, driver, live-location share).',
        '🛡️ महिला-सुरक्षित यात्रा: आरक्षित सीटें, CCTV, अनुभवी क्रू, और टिकट/ट्रैकिंग पेज पर लाल 🆘 SOS बटन (पुलिस, ड्राइवर, लाइव लोकेशन)।',
        '🛡️ महिला-सुरक्षित यात्रा: आरक्षित सिट, CCTV, अनुभवी चालक दल, र टिकट/ट्र्याकिङ पेजमा रातो 🆘 SOS बटन (प्रहरी, चालक, लाइभ लोकेसन)।',
        '🛡️ મહિલા-સુરક્ષિત મુસાફરી: અનામત સીટ, CCTV, અનુભવી ક્રૂ, અને ટિકિટ પેજ પર લાલ 🆘 SOS બટન.') },
    { k: ['become agent', 'become an agent', 'agent program', 'agent banna', 'agent banne', 'commission', 'कमीशन', 'कमिसन', 'एजेंट बनना', 'एजेन्ट बन्न', 'एजेन्ट बन्ने', 'એજન્ટ બનવું', 'કમિશન'],
      a: () => A('Join our agent program: earn commission on every booking. <a href="/agent-signup.php" target="_blank">🤝 Apply here</a> to register, then sign in at the <a href="/admin/" target="_blank">Agent Panel</a>.',
        'एजेंट प्रोग्राम: हर बुकिंग पर कमीशन कमाएँ। <a href="/agent-signup.php" target="_blank">🤝 यहाँ आवेदन करें</a>, फिर <a href="/admin/" target="_blank">एजेंट पैनल</a> में साइन इन करें।',
        'एजेन्ट कार्यक्रम: हरेक बुकिङमा कमिसन कमाउनुहोस्। <a href="/agent-signup.php" target="_blank">🤝 यहाँ आवेदन दिनुहोस्</a>, अनि <a href="/admin/" target="_blank">एजेन्ट प्यानल</a> मा साइन इन गर्नुहोस्।',
        'એજન્ટ પ્રોગ્રામ: દરેક બુકિંગ પર કમિશન કમાઓ. <a href="/agent-signup.php" target="_blank">🤝 અહીં અરજી કરો</a>, પછી <a href="/admin/" target="_blank">એજન્ટ પેનલ</a> માં સાઇન ઇન કરો.') },
    { k: ['book', 'seat', 'booking', 'बुक', 'सिट', 'सीट', 'બુક', 'સીટ'],
      a: () => A('Booking is easy: pick From/To & date above → choose seats or cabin → passenger details → pay by UPI/eSewa/link/COD → digital QR ticket. <a href="#/" data-scroll="search-anchor">Start →</a>',
        'बुकिंग आसान है: ऊपर From/To और तारीख चुनें → सीट/केबिन चुनें → यात्री विवरण → UPI/eSewa/लिंक/COD से भुगतान → डिजिटल QR टिकट। <a href="#/" data-scroll="search-anchor">शुरू करें →</a>',
        'बुकिङ सजिलो छ: माथि From/To र मिति छान्नुहोस् → सिट/क्याबिन → यात्रु विवरण → भुक्तानी → डिजिटल QR टिकट। <a href="#/" data-scroll="search-anchor">सुरु गर्नुहोस् →</a>',
        'બુકિંગ સરળ છે: ઉપર From/To અને તારીખ પસંદ કરો → સીટ/કેબિન → મુસાફર વિગતો → ચુકવણી → ડિજિટલ QR ટિકિટ. <a href="#/" data-scroll="search-anchor">શરૂ કરો →</a>') }
  ];
  const GREET = A('🙏 Namaste! I am SHG Sahayak. Ask me about fares, private/sharing cabins, refunds, tracking, payments, timings…',
    '🙏 नमस्ते! मैं SHG सहायक हूँ। किराया, प्राइवेट/शेयरिंग केबिन, रिफंड, ट्रैकिंग, भुगतान, समय — कुछ भी पूछें…',
    '🙏 नमस्ते! म SHG सहायक हुँ। भाडा, प्राइभेट/सेयरिङ क्याबिन, फिर्ता, ट्र्याकिङ, भुक्तानी, समय — जे पनि सोध्नुहोस्…',
    '🙏 નમસ્તે! હું SHG સહાયક છું. ભાડું, પ્રાઇવેટ/શેરિંગ કેબિન, રિફંડ, ટ્રેકિંગ, ચુકવણી — કંઈપણ પૂછો…');
  const FALLBACK = A('Sorry, I did not understand that. Try the quick buttons below, or call 📞 +91 91048 01507 (24×7).',
    'माफ़ कीजिए, समझ नहीं पाया। नीचे के बटन आज़माएँ, या 📞 +91 91048 01507 (24×7) पर कॉल करें।',
    'माफ गर्नुहोस्, बुझिनँ। तलका बटन प्रयोग गर्नुहोस्, वा 📞 +91 91048 01507 (24×7) मा फोन गर्नुहोस्।',
    'માફ કરશો, સમજાયું નહીં. નીચેના બટન અજમાવો, અથવા 📞 +91 91048 01507 પર કૉલ કરો.');
  /* Quick replies (13 Sep 2026, master prompt): Book · Track · Cancel/Refund ·
     Call agent · Route info · Luggage first, then Fares and the Complaint desk.
     The second string is what gets "typed", so each chip lands on an intent
     that already exists (or on the human handoff for Call Agent). */
  const CHIPS = {
    en: [['🎫 Book Ticket', 'book ticket'], ['🛰️ Track Bus', 'track bus'], ['↩️ Cancel / Refund', 'cancel refund'], ['📞 Call Agent', 'talk to agent'], ['🗺️ Route Info', 'route stops timing'], ['🧳 Luggage Rules', 'luggage'], ['💰 Fares', 'fare'], ['❌ Complaint', 'complaint']],
    hi: [['🎫 टिकट बुक', 'बुक टिकट'], ['🛰️ बस ट्रैक', 'ट्रैक'], ['↩️ रद्द / रिफंड', 'रद्द रिफंड'], ['📞 एजेंट से बात', 'एजेंट से बात करनी'], ['🗺️ रूट जानकारी', 'बोर्डिंग समय'], ['🧳 सामान नियम', 'सामान'], ['💰 किराया', 'किराया'], ['❌ शिकायत', 'शिकायत']],
    ne: [['🎫 टिकट बुक', 'बुक गर्नु'], ['🛰️ बस ट्र्याक', 'ट्र्याक'], ['↩️ रद्द / फिर्ता', 'रद्द फिर्ता'], ['📞 कर्मचारीसँग कुरा', 'कर्मचारीसँग कुरा गर्नु'], ['🗺️ रुट जानकारी', 'बोर्डिङ समय'], ['🧳 सामान नियम', 'सामान'], ['💰 भाडा', 'भाडा'], ['❌ गुनासो', 'गुनासो']],
    gu: [['🎫 ટિકિટ બુક', 'બુક'], ['🛰️ બસ ટ્રેક', 'ટ્રેક'], ['↩️ રદ / રિફંડ', 'રદ રિફંડ'], ['📞 વ્યક્તિ સાથે વાત', 'વ્યક્તિ સાથે વાત કરવી'], ['🗺️ રૂટ માહિતી', 'ટાઈમ'], ['🧳 સામાન', 'સામાન'], ['💰 ભાડું', 'ભાડું'], ['❌ ફરિયાદ', 'ફરિયાદ']]
  };

  /* Reply language (13 Sep 2026). The dropdown stays the user's choice, but a
     message that clearly shows its language sets the reply language, so
     "bus kitne baje hai?" is answered in Hindi and "bus kati baje chha?" in
     Nepali whatever the panel is set to. Sticky until the dropdown changes;
     chips always follow the dropdown. Whole-word markers only, so a "छ"
     inside a Hindi word never reads as Nepali; a tie means "not sure" and the
     current language stays. var, not let: lang() must never hit a TDZ. */
  var msgLang = '';
  function lang() { return msgLang || langSel.value || 'en'; }
  const LANG_MARKS = {
    hi: ['hai', 'hain', 'kitne', 'kitna', 'kitni', 'kya', 'kab', 'kahan', 'mujhe', 'mera', 'meri', 'mere', 'chahiye', 'nahi', 'nahin',
         'karna', 'karni', 'kaise', 'aaj', 'hoga', 'hogi', 'milega', 'milegi', 'wala', 'wali', 'raha', 'rahi', 'kaun', 'kyun',
         'bataiye', 'batao', 'chalegi', 'jayegi', 'hamein', 'humein', 'apna', 'apni',
         'है', 'हैं', 'कितने', 'कितना', 'कितनी', 'क्या', 'कब', 'मुझे', 'मेरा', 'मेरी', 'मेरे', 'चाहिए', 'नहीं', 'करना', 'कैसे',
         'होगा', 'होगी', 'मिलेगा', 'मिलेगी', 'वाला', 'रहा', 'रही', 'कौन', 'क्यों', 'बताइए', 'बताओ', 'चलेगी', 'जाएगी', 'हमें', 'अपना'],
    ne: ['cha', 'chha', 'chhan', 'chan', 'chaina', 'chhaina', 'kati', 'kahile', 'mero', 'garnu', 'garne', 'huncha', 'hunchha',
         'bhayo', 'kasari', 'parcha', 'parchha', 'sakchu', 'bholi', 'aaja', 'hola', 'paincha', 'aune', 'kata', 'bhannu', 'dinu',
         'hajur', 'chhutcha', 'chalcha', 'pugcha', 'hidcha', 'tapai', 'tapain', 'tapaiko', 'malai', 'hami', 'hamro',
         'छ', 'छन्', 'छैन', 'कति', 'कहिले', 'मेरो', 'गर्नु', 'गर्ने', 'हुन्छ', 'भयो', 'कसरी', 'पर्छ', 'सक्छु', 'भोलि', 'होला',
         'पाइन्छ', 'आउने', 'कता', 'छुट्छ', 'चल्छ', 'पुग्छ', 'तपाईं', 'तपाईंको', 'मलाई', 'हामी', 'हाम्रो'],
    en: ['what', 'when', 'where', 'how', 'which', 'who', 'why', 'is', 'are', 'does', 'do', 'can', 'the', 'please', 'will',
         'there', 'much', 'many', 'my', 'i', 'am', 'want', 'need']
  };
  function detectLang(text) {
    const s = String(text || '');
    if (/[\u0A80-\u0AFF]/.test(s)) return 'gu';
    const toks = s.toLowerCase().split(/[\s,.!?;:()"'\u0964\u0965\u2018\u2019\u201C\u201D\/-]+/).filter(Boolean);
    let hi = 0, ne = 0, en = 0;
    toks.forEach(function (w) {
      if (LANG_MARKS.hi.indexOf(w) >= 0) hi++;
      if (LANG_MARKS.ne.indexOf(w) >= 0) ne++;
      if (LANG_MARKS.en.indexOf(w) >= 0) en++;
    });
    if (hi > ne) return 'hi';
    if (ne > hi) return 'ne';
    if (hi === 0 && en > 0 && !/[\u0900-\u097F]/.test(s)) return 'en';
    return '';
  }

  /* ================================================================
     LIVE ANSWERS — the assistant reading the app's own data.

     The intents above are good at policy ("what is the refund rule")
     but they cannot answer the questions people actually type: "where
     is my ticket", "SHG-2026-00042 ko status", "bholi seat cha?",
     "next bus kahile?". Those need the data already sitting in DB, and
     it is all right here in the browser — no server round-trip, so the
     reply is instant even on a bad connection.

     Tried BEFORE the keyword intents; returns null when nothing here
     applies, and the keyword matcher takes over unchanged.
  ================================================================ */

  /* Pick a string for the active language, falling back to English. */
  function L(en, hi, ne, gu) { const m = { en: en, hi: hi, ne: ne, gu: gu }; return m[lang()] || en; }

  /* Keyword test that actually works in Devanagari and Gujarati.

     `\b` in JavaScript is defined on ASCII word characters only, so a
     pattern like /\bसिट\b/ can never match — which is how "भोलि सिट खाली
     छ?" used to fall through to a generic answer while the English "is
     there a seat tomorrow" worked fine. That is backwards for this route.
     ASCII words still get a boundary check (so "id" doesn't match
     "video"); everything else is a plain substring test. */
  function has(q, words) {
    return words.some(function (w) {
      if (/^[\x00-\x7F]+$/.test(w)) {
        return new RegExp('(^|[^a-z0-9])' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '([^a-z0-9]|$)', 'i').test(q);
      }
      return q.indexOf(w) >= 0;
    });
  }

  /** today / tomorrow / a written date mentioned in the question. */
  function dateFromText(q) {
    if (has(q, ['today', 'aaj', 'आज', 'આજ'])) return todayISO();
    if (has(q, ['tomorrow', 'kal', 'bholi', 'भोलि', 'कल', 'આવતીકાલ'])) return addDaysISO(todayISO(), 1);
    const iso = q.match(/(20\d{2}-\d{2}-\d{2})/);
    if (iso) return iso[1];
    return null;
  }

  /** Routes whose city names appear in the question. */
  function routesFromText(q) {
    const active = (DB.routes || []).filter(r => r.active);
    const hits = active.filter(r =>
      q.indexOf(String(r.from).toLowerCase()) >= 0 || q.indexOf(String(r.to).toLowerCase()) >= 0);
    return hits.length ? hits : active;
  }

  /** A booking summary line the passenger can act on. */
  function bookingCard(b) {
    const r = routeById(b.routeId) || {};
    const st = b.status === 'confirmed' ? '✅' : b.status === 'pending' ? '⏳' : '❌';
    return st + ' <b>' + esc(b.id) + '</b> — ' + esc((r.from || '?') + ' → ' + (r.to || '?'))
      + '<br>' + fmtDate(b.date) + ' · ' + L('Seat', 'सीट', 'सिट', 'સીટ') + ' ' + esc(seatLabelJoin(b.seats, r.type, b.bookingType))
      + ' · <b>' + inr(b.total) + '</b>'
      + '<br><a href="#/ticket/' + esc(b.id) + '">' + L('Open ticket →', 'टिकट खोलें →', 'टिकट खोल्नुहोस् →', 'ટિકિટ ખોલો →') + '</a>';
  }

  /* Where everything lives, so "X kaha cha?" always has an answer. */
  const WHERE = [
    { k: ['download', 'pdf', 'डाउनलोड', 'ડાઉનલોડ'],
      a: () => L('Your ticket PDF: <a href="#/my">My Bookings</a> → the booking → <b>PDF</b>. It also auto-downloads the moment a booking is confirmed.',
                 'टिकट PDF: <a href="#/my">मेरी बुकिंग</a> → बुकिंग → <b>PDF</b>। पुष्टि होते ही अपने-आप भी डाउनलोड होता है।',
                 'टिकट PDF: <a href="#/my">मेरो बुकिङ</a> → बुकिङ → <b>PDF</b>। पुष्टि हुनेबित्तिकै आफैं पनि डाउनलोड हुन्छ।',
                 'ટિકિટ PDF: <a href="#/my">મારી બુકિંગ</a> → બુકિંગ → <b>PDF</b>.') },
    { k: ['cancel', 'रद्द', 'कैंसिल', 'क्यान्सिल', 'રદ'],
      a: () => L('Cancel: <a href="#/my">My Bookings</a> → the booking → <b>Cancel</b>. The refund amount is shown before you confirm.',
                 'रद्द करें: <a href="#/my">मेरी बुकिंग</a> → बुकिंग → <b>Cancel</b>। पुष्टि से पहले रिफंड रकम दिखती है।',
                 'रद्द गर्न: <a href="#/my">मेरो बुकिङ</a> → बुकिङ → <b>Cancel</b>। पुष्टि गर्नुअघि फिर्ता रकम देखिन्छ।',
                 'રદ કરો: <a href="#/my">મારી બુકિંગ</a> → બુકિંગ → <b>Cancel</b>.') },
    { k: ['rebook', 'again', 'फिर से', 'फेरि', 'दोबारा', 'ફરીથી'],
      a: () => L('One-tap rebook: <a href="#/my">My Bookings</a> → <b>🔁 Book again</b> — same route, tomorrow\'s date, your details already filled.',
                 'एक टैप में दोबारा: <a href="#/my">मेरी बुकिंग</a> → <b>🔁 फिर से बुक करें</b> — वही रूट, कल की तारीख, विवरण भरा हुआ।',
                 'एक ट्यापमा फेरि: <a href="#/my">मेरो बुकिङ</a> → <b>🔁 फेरि बुक गर्नुहोस्</b> — उही रुट, भोलिको मिति, विवरण भरिएको।',
                 'એક ટેપમાં ફરીથી: <a href="#/my">મારી બુકિંગ</a> → <b>🔁 ફરી બુક કરો</b>.') },
    { k: ['language', 'भाषा', 'ભાષા'],
      a: () => L('Language: the 🌐 buttons in the header — English / हिन्दी / नेपाली. Your choice is remembered.',
                 'भाषा: हेडर में 🌐 बटन — English / हिन्दी / नेपाली। आपकी पसंद याद रहती है।',
                 'भाषा: हेडरमा 🌐 बटन — English / हिन्दी / नेपाली। तपाईंको छनोट सम्झिन्छ।',
                 'ભાષા: હેડરમાં 🌐 બટન.') },
    { k: ['dark', 'night', 'डार्क', 'डार्क मोड', 'ડાર્ક'],
      a: () => L('Dark mode: the 🌙 toggle in the header.', 'डार्क मोड: हेडर में 🌙 बटन।',
                 'डार्क मोड: हेडरमा 🌙 बटन।', 'ડાર્ક મોડ: હેડરમાં 🌙 બટન.') },
    { k: ['proof', 'screenshot', 'utr', 'स्क्रीनशॉट', 'प्रमाण', 'સ્ક્રીનશોટ'],
      a: () => L('Payment proof: at checkout, paste the 12-digit UTR <i>or</i> upload the payment screenshot — either one is enough.',
                 'भुगतान प्रमाण: चेकआउट पर 12-अंकों का UTR डालें <i>या</i> स्क्रीनशॉट अपलोड करें — कोई एक ही काफ़ी है।',
                 'भुक्तानी प्रमाण: चेकआउटमा 12 अंकको UTR हाल्नुहोस् <i>वा</i> स्क्रिनसट अपलोड गर्नुहोस् — कुनै एक भए पुग्छ।',
                 'ચુકવણી પુરાવો: ચેકઆઉટ પર UTR અથવા સ્ક્રીનશોટ.') },
    { k: ['agent', 'commission', 'एजेन्ट', 'एजेंट', 'कमिसन', 'कमीशन', 'એજન્ટ'],
      a: () => L('Agents sign in with their own username and password: <a href="/admin/" target="_blank">Agent Panel</a> → sales, wallet, commission and cash-in-hand.',
                 'एजेंट अपने यूज़रनेम-पासवर्ड से साइन इन करें: <a href="/admin/" target="_blank">एजेंट पैनल</a> → बिक्री, वॉलेट, कमीशन।',
                 'एजेन्टले आफ्नै युजरनेम-पासवर्डबाट साइन इन गर्नुहोस्: <a href="/admin/" target="_blank">एजेन्ट प्यानल</a> → बिक्री, वालेट, कमिसन।',
                 'એજન્ટ પોતાના યુઝરનેમ-પાસવર્ડથી: <a href="/admin/" target="_blank">એજન્ટ પેનલ</a>.') },
  ];

  function liveAnswer(text) {
    const raw = String(text || '');
    const q   = ' ' + raw.toLowerCase() + ' ';

    /* ---- 1. A PNR in the message — the most specific thing anyone can ask */
    const pnrHit = raw.toUpperCase().match(/\bSHG[-\s]?[A-Z0-9][A-Z0-9-]{3,}/);
    if (pnrHit) {
      const pnr = pnrHit[0].replace(/\s+/g, '-').replace(/-+/g, '-');
      const b = (DB.bookings || []).find(x => String(x.id).toUpperCase() === pnr);
      if (b) {
        const when = depTimestamp(b) - Date.now();
        const soon = when > 0 && when < 36 * 3600 * 1000
          ? '<br>🕒 ' + L('Departs in about ', 'लगभग ', 'लगभग ', 'લગભગ ') + Math.round(when / 3600000)
            + L(' hours.', ' घंटे में प्रस्थान।', ' घण्टामा प्रस्थान।', ' કલાકમાં પ્રસ્થાન.')
          : '';
        return bookingCard(b) + soon;
      }
      return L('I could not find ' + esc(pnr) + ' on this device. Open <a href="#/my">My Bookings</a> and sign in with the mobile number used at booking.',
               esc(pnr) + ' इस डिवाइस पर नहीं मिला। <a href="#/my">मेरी बुकिंग</a> खोलें और बुकिंग वाले मोबाइल से साइन इन करें।',
               esc(pnr) + ' यो डिभाइसमा भेटिएन। <a href="#/my">मेरो बुकिङ</a> खोली बुकिङ गरेको मोबाइलबाट साइन इन गर्नुहोस्।',
               esc(pnr) + ' આ ડિવાઇસ પર મળ્યું નહીં. <a href="#/my">મારી બુકિંગ</a> ખોલો.');
    }

    /* ---- 2. "my ticket / my booking" — their own trips */
    if (has(q, ['my ticket', 'my tickets', 'my booking', 'my bookings', 'my trip', 'my trips',
                'mero ticket', 'mera ticket', 'mero booking',
                'मेरो टिकट', 'मेरो बुकिङ', 'मेरा टिकट', 'मेरी बुकिंग', 'मेरी टिकट', 'મારી ટિકિટ', 'મારી બુકિંગ'])) {
      const mine = (DB.bookings || []).slice().sort((a, b2) => b2.createdAt - a.createdAt);
      if (!mine.length) {
        return L('No bookings on this device yet. <a href="#/" data-scroll="search-anchor">Book a seat →</a>',
                 'इस डिवाइस पर अभी कोई बुकिंग नहीं। <a href="#/" data-scroll="search-anchor">सीट बुक करें →</a>',
                 'यो डिभाइसमा अहिलेसम्म बुकिङ छैन। <a href="#/" data-scroll="search-anchor">सिट बुक गर्नुहोस् →</a>',
                 'આ ડિવાઇસ પર હજી કોઈ બુકિંગ નથી.');
      }
      const head = L('You have ', 'आपकी ', 'तपाईंका ', 'તમારી ') + mine.length
        + L(' booking(s). Most recent:', ' बुकिंग हैं। नवीनतम:', ' बुकिङ छन्। पछिल्लो:', ' બુકિંગ છે. તાજેતરની:');
      return head + '<br>' + bookingCard(mine[0])
        + '<br><a href="#/my">' + L('See all →', 'सभी देखें →', 'सबै हेर्नुहोस् →', 'બધી જુઓ →') + '</a>';
    }

    /* ---- 3. Seats free on a date — a real count, not a promise */
    if (has(q, ['seat', 'seats', 'khali', 'khaali', 'available', 'availability',
                'सिट', 'सीट', 'खाली', 'उपलब्ध', 'સીટ', 'ખાલી'])
        && !has(q, ['seat map', 'seat no', 'seat number'])) {
      const date = dateFromText(q) || addDaysISO(todayISO(), 1);
      /* This route runs five buses each way, so the city pair alone would
         print the same line five times. The departure time is what actually
         tells them apart to a passenger. */
      const lines = routesFromText(q).slice(0, 5).map(r => {
        const total = seatIdsFor(r).length;
        const taken = bookedSeatsFor(r.id, date).length + lockedSeatsFor(r.id, date).length;
        const free  = Math.max(0, total - taken);
        const dot   = free === 0 ? '🔴' : free < 6 ? '🟠' : '🟢';
        return dot + ' ' + esc(r.depTime || '') + ' ' + esc(r.from + ' → ' + r.to)
          + ' · <b>' + free + '</b>/' + total
          + L(' free', ' खाली', ' खाली', ' ખાલી');
      }).join('<br>');
      return '📅 ' + fmtDate(date) + '<br>' + lines
        + '<br><a href="#/" data-scroll="search-anchor">' + L('Pick a seat →', 'सीट चुनें →', 'सिट छान्नुहोस् →', 'સીટ પસંદ કરો →') + '</a>';
    }

    /* ---- 4. Next departure — with a live countdown */
    if (has(q, ['next bus', 'next departure', 'next trip', 'kahile', 'kab jayegi', 'kab hai',
                'अर्को बस', 'अर्को', 'अगली बस', 'कहिले', 'कब जाएगी', 'હવે પછી'])) {
      const now = Date.now();
      let best = null;
      (DB.routes || []).filter(r => r.active).forEach(r => {
        [0, 1, 2].forEach(d => {
          const iso = addDaysISO(todayISO(), d);
          const ts  = new Date(iso + 'T' + (r.depTime || '00:00') + ':00').getTime();
          if (ts > now && (!best || ts < best.ts)) best = { ts: ts, r: r, iso: iso };
        });
      });
      if (best) {
        const hrs = (best.ts - now) / 3600000;
        return '🚌 ' + esc(best.r.from + ' → ' + best.r.to) + '<br>'
          + fmtDate(best.iso) + ' · ' + esc(best.r.depTime)
          + ' — ' + (hrs < 24 ? L('in about ', 'लगभग ', 'लगभग ', 'લગભગ ') + Math.round(hrs) + L(' hours', ' घंटे में', ' घण्टामा', ' કલાકમાં')
                              : L('in ', '', '', '') + Math.round(hrs / 24) + L(' days', ' दिन में', ' दिनमा', ' દિવસમાં'))
          + '<br><a href="#/" data-scroll="search-anchor">' + L('Book it →', 'बुक करें →', 'बुक गर्नुहोस् →', 'બુક કરો →') + '</a>';
      }
    }

    /* ---- 3b. Today's buses (v4.0 A4) — the whole board in one reply.
       DB.routes is local, so this answers offline too; when the server
       occupancy snapshot is warm (SeatSrv) the seat counts are the
       database's, not this browser's guess. */
    if (has(q, ["today's buses", 'todays buses', 'aaj ki bus', 'aajko bus', 'aaj ka bus',
                'आज की बस', 'आजको बस', 'आजका बस', 'આજની બસ'])) {
      const today = todayISO();
      const rows = (DB.routes || []).filter(r => r.active).slice(0, 10).map(r => {
        const srv = (typeof SeatSrv !== 'undefined') && SeatSrv.snap(r.id, today);
        const total = seatIdsFor(r).length;
        const free = srv ? Math.max(0, total - srv.booked.length - srv.locked.length)
                         : Math.max(0, total - bookedSeatsFor(r.id, today).length - lockedSeatsFor(r.id, today).length);
        const dot = free === 0 ? '🔴' : free < 6 ? '🟠' : '🟢';
        return dot + ' <b>' + esc(r.depTime || '') + '</b> ' + esc(r.from + ' → ' + r.to)
          + ' <small>(' + esc(r.busName || '') + ')</small> · ' + free + L(' free', ' खाली', ' खाली', ' ખાલી');
      });
      if (!rows.length) return L('No buses on the board today.', 'आज कोई बस नहीं।', 'आज कुनै बस छैन।', 'આજે કોઈ બસ નથી.');
      return '🚌 ' + fmtDate(today) + '<br>' + rows.join('<br>')
        + '<br><a href="#/" data-scroll="search-anchor">' + L('Book a seat →', 'सीट बुक करें →', 'सिट बुक गर्नुहोस् →', 'સીટ બુક કરો →') + '</a>';
    }

    /* ---- 4a. Complaint desk — category menu, then the flow in send() */
    if (has(q, ['complaint', 'complain', 'shikayat', 'gunaso', 'शिकायत', 'गुनासो', 'ફરિયાદ'])) {
      return Complaint.menuHtml();
    }
    if (has(q, ['still not resolved', 'not resolved', 'हल नहीं', 'समाधान भएन'])) {
      return L('Taking you straight to the office — they answer complaints personally:',
               'सीधे ऑफिस से जोड़ रहा हूँ:', 'सीधै अफिससँग जोड्दैछु:', 'સીધા ઓફિસ સાથે:')
        + '<br>' + waForwardHtml('Complaint escalation — still not resolved');
    }

    /* ---- 4b. District transit advisor — "म Surkhet मा छु, kasari aaune?"
       Pure cached data (works offline), but the leave-by time is computed
       from the LIVE departure in DB.routes, so a rescheduled bus reprices
       the advice automatically instead of quoting a stale 6 AM. */
    {
      const hit = SHG_DISTRICTS.find(d => has(q, d.k));
      if (hit) {
        // Earliest departure from the district's boarding city, today's board.
        let dep = null;
        (DB.routes || []).filter(r => r.active && r.from === hit.city).forEach(r => {
          if (!dep || String(r.depTime) < String(dep)) dep = r.depTime;
        });
        const opts = hit.opts ? '<br>' + hit.opts : '';
        let leaveLine = '';
        if (dep) {
          const [dh, dm] = String(dep).split(':').map(Number);
          const mins = dh * 60 + dm - Math.round(hit.h * 60) - 45;   // 45 min buffer
          const lm = ((mins % 1440) + 1440) % 1440;
          const leaveAt = String(Math.floor(lm / 60)).padStart(2, '0') + ':' + String(lm % 60).padStart(2, '0');
          leaveLine = '<br>⏰ ' + L('Bus departs ' + esc(hit.city) + ' at <b>' + esc(dep) + '</b> — leave ' + esc(hit.name) + ' by <b>' + leaveAt + '</b>' + (mins < 0 ? ' (the previous day)' : '') + '.',
            'बस ' + esc(hit.city) + ' से <b>' + esc(dep) + '</b> बजे छूटती है — ' + esc(hit.name) + ' से <b>' + leaveAt + '</b>' + (mins < 0 ? ' (एक दिन पहले)' : '') + ' तक निकलें।',
            'बस ' + esc(hit.city) + 'बाट <b>' + esc(dep) + '</b> बजे छुट्छ — ' + esc(hit.name) + 'बाट <b>' + leaveAt + '</b>' + (mins < 0 ? ' (अघिल्लो दिन)' : '') + ' सम्ममा निस्कनुहोस्।',
            'બસ ' + esc(hit.city) + 'થી <b>' + esc(dep) + '</b> વાગ્યે ઉપડે છે.');
        }
        return '📍 ' + esc(hit.name) + ' → ' + esc(hit.city) + ': <b>~' + hit.km + ' km</b> ('
          + L('about ', 'लगभग ', 'लगभग ', 'લગભગ ') + hit.h + L(' h by road', ' घंटे सड़क से', ' घण्टा सडकबाट', ' કલાક') + ')'
          + opts + leaveLine
          + '<br><a href="#/" data-scroll="search-anchor">' + L('Book your seat →', 'सीट बुक करें →', 'सिट बुक गर्नुहोस् →', 'સીટ બુક કરો →') + '</a>';
      }
    }

    /* ---- 5. "Where is X?" — the app's own map */
    if (has(q, ['where', 'kaha', 'kahan', 'kata', 'how do i', 'how to', 'kasari', 'kaise',
                'कहाँ', 'कहां', 'कता', 'कैसे', 'कसरी', 'ક્યાં', 'કેવી રીતે'])) {
      for (let i = 0; i < WHERE.length; i++) {
        if (has(q, WHERE[i].k)) return WHERE[i].a();
      }
    }

    return null;
  }

  /* Feeder-district table for the transit advisor above. Cached in the app
     bundle itself, so it answers offline — exactly the passengers (Surkhet,
     Dailekh, Jumla hill roads) who most often have no signal. km/h are road
     figures for the standard route; `city` is the S Hari boarding city the
     district feeds into, and `opts` lists local transport with fares. */
  const SHG_DISTRICTS = [
    { k: ['surkhet', 'सुर्खेत', 'birendranagar', 'बिरेन्द्रनगर'], name: 'Surkhet', city: 'Nepalgunj', km: 89, h: 2.5,
      opts: '🚌 Local bus ~NPR 250 (3h) · 🚕 Taxi ~NPR 2500 (2.5h)' },
    { k: ['dailekh', 'दैलेख'], name: 'Dailekh', city: 'Nepalgunj', km: 120, h: 3.5,
      opts: '🚌 Local bus ~NPR 350 (4h)' },
    { k: ['jumla', 'जुम्ला'], name: 'Jumla', city: 'Nepalgunj', km: 240, h: 7,
      opts: '🚌 ~7h hill road — अघिल्लो दिन नै Nepalgunj आएर बस्नु राम्रो · previous-day travel recommended' },
    { k: ['dang', 'tulsipur', 'ghorahi', 'दाङ', 'तुल्सीपुर', 'घोराही'], name: 'Dang', city: 'Nepalgunj', km: 110, h: 3,
      opts: '🚌 Local bus ~NPR 300 (3h)' },
    { k: ['kohalpur', 'कोहलपुर'], name: 'Kohalpur', city: 'Nepalgunj', km: 20, h: 0.5,
      opts: '🛺 Auto ~NPR 100 (30 min)' },
    { k: ['bardiya', 'gulariya', 'बर्दिया', 'गुलरिया'], name: 'Bardiya', city: 'Nepalgunj', km: 55, h: 1.5,
      opts: '🚌 Local bus ~NPR 150 (1.5h)' },
    { k: ['salyan', 'सल्यान'], name: 'Salyan', city: 'Nepalgunj', km: 100, h: 3, opts: '' },
    { k: ['rolpa', 'रोल्पा', 'liwang'], name: 'Rolpa', city: 'Nepalgunj', km: 150, h: 4.5, opts: '' },
    { k: ['bahraich', 'बहराइच'], name: 'Bahraich', city: 'Nepalgunj', km: 97, h: 2,
      opts: '🚌 Rupaidiha border ~97 km — S Hari bus stops at Bahraich itself; check your boarding list.' },
    { k: ['lucknow', 'लखनऊ', 'लखनउ'], name: 'Lucknow', city: 'Nepalgunj', km: 180, h: 3.5,
      opts: '🚌 The bus has a Lucknow (Alambagh) stop — board there instead of travelling to the border.' },
    { k: ['mehsana', 'मेहसाणा', 'महेसाणा', 'મહેસાણા'], name: 'Mehsana', city: 'Ahmedabad', km: 75, h: 1.5,
      opts: '🚌 The bus has a Mehsana boarding point (SHG office) — no need to go to Ahmedabad.' },
  ];

  function answerIntent(text) {
    const q = ' ' + String(text || '').toLowerCase() + ' ';

    /* Live data first — a real answer about this passenger's own trip
       always beats a general one. Guarded so a data hiccup can never
       take the assistant down; it just falls through to the intents. */
    try {
      const live = liveAnswer(text);
      if (live) return { a: live, matched: true, live: true };
    } catch (e) {}

    /* Human handoff (13 Sep 2026): "agent", "human", "talk to someone" and
       their Hindi / Nepali / Gujarati forms go straight to the office on
       WhatsApp plus a call button. A person asking for a person must never
       get a FAQ paragraph. */
    if (has(q, HUMAN_WORDS)) return { a: humanHandoffHtml(text), matched: true, human: true };

    /* Timing questions (13 Sep 2026, "bus kitne baje hai?"): an o'clock word in
       any of the four languages, checked before the keyword intents so the
       Gujarati "કેટલા" (how many) cannot land on the fare answer. */
    if (has(q, TIME_WORDS)) return { a: timeAnswer(q), matched: true };

    for (let i = 0; i < INTENTS.length; i++) {
      if (INTENTS[i].k.some(kw => q.indexOf(kw.toLowerCase()) >= 0)) return { a: INTENTS[i].a()[lang()], matched: true };
    }
    if (/^\s*(hi|hello|hey|namaste|नमस्ते|नमस्कार|નમસ્તે)\b/i.test(text)) return { a: GREET[lang()], matched: true };
    return { a: FALLBACK[lang()], matched: false };
  }
  /* Every pickup with its time, both runs, from the app's own route data (the
     same strings the booking flow reads), so a retimed stop reprices this
     answer by itself. Arrival is never promised: the office confirms it. */
  const TIME_WORDS = ['baje', 'baaje', 'bajey', 'what time', 'which time', 'departure time', 'bus time', 'bus timing', 'arrival time',
    'kab chalegi', 'kab chalti', 'kab niklegi', 'kab nikalti', 'kab jaati', 'kab jati', 'kab chhutegi', 'kab chutegi', 'kab pahunchegi',
    'kahile chalcha', 'kahile chalchha', 'kahile chhutcha', 'kahile chhutchha', 'kahile hidcha', 'kahile pugcha', 'kahile pugchha',
    'बजे', 'कब चलेगी', 'कब निकलेगी', 'कब छूटेगी', 'कब पहुँचेगी', 'कहिले छुट्छ', 'कहिले हिँड्छ', 'कहिले पुग्छ',
    'વાગે', 'ક્યારે ઉપડે', 'ક્યારે ઉપડશે', 'ક્યારે પહોંચ'];
  const ARRIVE_WORDS = ['arrive', 'arrives', 'arrival', 'reach', 'reaches', 'pahunchegi', 'pahuchegi', 'pahunchti', 'pahunchta',
    'pugcha', 'pugchha', 'pugne', 'पहुँच', 'पहुंच', 'पुग्छ', 'पुग्ने', 'પહોંચ'];
  function timeAnswer(q) {
    const routes = (DB.routes || []).filter(function (r) { return r && r.active; });
    const go = routes.find(function (r) { return isNepalPoint(r.to); });
    const back = routes.find(function (r) { return isNepalPoint(r.from); });
    if (!go && !back) {
      const ti = INTENTS.find(function (x) { return x.k.indexOf('timing') >= 0; });
      return ti ? (ti.a()[lang()] || ti.a().en) : FALLBACK[lang()];
    }
    const line = function (r) {
      const stops = (r.boarding || []).map(function (b) {
        const bp = parseBP(b);
        return bp.name ? esc(bp.name) + (bp.time ? ' <b>' + esc(bp.time) + '</b>' : '') : '';
      }).filter(Boolean);
      return '🚌 ' + esc(r.from + ' → ' + r.to) + ': ' + (stops.length ? stops.join(' · ') : '<b>' + esc(r.depTime || '') + '</b>');
    };
    let out = [go, back].filter(Boolean).map(line).join('<br>')
      + '<br>' + L('Reach your pickup 60 minutes early.', 'बोर्डिंग पॉइंट पर 60 मिनट पहले पहुँचें।',
                   'बोर्डिङ पोइन्टमा ६० मिनेट अगाडि आउनुहोस्।', 'બોર્ડિંગ પોઇન્ટ પર 60 મિનિટ વહેલા પહોંચો.');
    if (has(q, ARRIVE_WORDS)) {
      out += '<br>' + L('The arrival time is confirmed by the office on the day of travel.',
                        'पहुँचने का समय यात्रा के दिन ऑफिस बताता है।',
                        'पुग्ने समय यात्राको दिन कार्यालयले पुष्टि गर्छ।',
                        'પહોંચવાનો સમય મુસાફરીના દિવસે ઓફિસ જણાવે છે.');
    }
    return out + '<br><a href="#/" data-scroll="search-anchor">'
      + L('Book a seat →', 'सीट बुक करें →', 'सिट बुक गर्नुहोस् →', 'સીટ બુક કરો →') + '</a>';
  }
  const HUMAN_WORDS = ['human', 'agent', 'real person', 'talk to', 'speak to', 'call me', 'operator', 'customer care', 'helpline',
    'manav', 'insaan', 'aadmi', 'baat karni', 'baat karo', 'kura garna', 'manche', 'karmachari',
    'मानिस', 'मान्छे', 'कर्मचारी', 'कुरा गर्न', 'एजेन्ट', 'इंसान', 'आदमी', 'बात करनी', 'बात करो', 'एजेंट',
    'માણસ', 'વ્યક્તિ સાથે', 'વાત કરવી', 'એજન્ટ'];
  function humanHandoffHtml(q) {
    const num = (typeof digits === 'function' ? digits(S().phone) : '') || '919104801507';
    const wa = 'https://wa.me/' + num + '?text=' + encodeURIComponent(
      L('Namaste, I need to talk to a person about: ', 'नमस्ते, मुझे किसी व्यक्ति से बात करनी है: ', 'नमस्ते, मलाई कर्मचारीसँग कुरा गर्नु छ: ', 'નમસ્તે, મને વ્યક્તિ સાથે વાત કરવી છે: ') + String(q || '').slice(0, 300));
    return L('Connecting you to our team 🙏 A real person replies on WhatsApp within minutes (7 AM – 11 PM).',
             'आपको हमारी टीम से जोड़ रहे हैं 🙏 WhatsApp पर एक व्यक्ति कुछ मिनटों में जवाब देगा (सुबह 7 – रात 11)।',
             'तपाईंलाई हाम्रो टिमसँग जोड्दैछु 🙏 WhatsApp मा कर्मचारीले केही मिनेटमा जवाफ दिन्छन् (बिहान ७ – राति ११)।',
             'તમને અમારી ટીમ સાથે જોડી રહ્યા છીએ 🙏 WhatsApp પર વ્યક્તિ થોડી મિનિટોમાં જવાબ આપશે (સવારે 7 – રાત્રે 11).')
      + '<br><a class="ai-wa-cta" href="' + wa + '" target="_blank" rel="noopener">💬 WhatsApp</a>'
      + '<a class="ai-wa-cta ai-call-cta" href="tel:+' + num + '">📞 ' + L('Call', 'कॉल', 'फोन', 'કૉલ') + '</a>';
  }
  /* When the rule-based bot can't answer, hand off to a human on WhatsApp
     with the user's own question pre-filled — a miss becomes a lead. */
  function waForwardHtml(q) {
    const num = (typeof digits === 'function' ? digits(S().phone) : '') || '919104801507';
    const url = 'https://wa.me/' + num + '?text=' + encodeURIComponent('SHG Sahayak — ' + q);
    const label = { en: '💬 Ask us on WhatsApp', hi: '💬 WhatsApp पर पूछें', ne: '💬 WhatsApp मा सोध्नुहोस्', gu: '💬 WhatsApp પર પૂછો' }[lang()] || '💬 Ask us on WhatsApp';
    return '<a class="ai-wa-cta" href="' + url + '" target="_blank" rel="noopener">' + label + '</a>';
  }
  function addMsg(html, who) {
    const d = document.createElement('div');
    d.className = 'ai-msg ' + who;
    if (who === 'user') d.textContent = html; else d.innerHTML = html;
    msgs.appendChild(d);
    msgs.scrollTop = msgs.scrollHeight;
    persistChat(who, html);
    if (who === 'bot') markUnread();
  }
  /* Transcript memory (13 Sep 2026): the last 20 bubbles survive a reload,
     so a passenger who comes back sees the answer they were given, and the
     Claude fallback gets the same context (rebuilt in restoreChat()).
     Cleared by the 🗑️ button. User bubbles are restored as text, never HTML. */
  const CHAT_KEY = 'shg:chat';
  function chatLoad() { try { return JSON.parse(localStorage.getItem(CHAT_KEY) || '[]'); } catch (e) { return []; } }
  function chatSave(list) { try { localStorage.setItem(CHAT_KEY, JSON.stringify(list.slice(-20))); } catch (e) {} }
  function persistChat(who, html) {
    const list = chatLoad();
    list.push({ w: who === 'user' ? 'user' : 'bot', h: String(html).slice(0, 2000), t: Date.now() });
    chatSave(list);
  }
  function restoreChat() {
    const list = chatLoad();
    if (!list.length) return false;
    list.forEach(function (m) {
      const d = document.createElement('div');
      d.className = 'ai-msg ' + (m.w === 'user' ? 'user' : 'bot');
      if (m.w === 'user') d.textContent = m.h; else d.innerHTML = m.h;
      msgs.appendChild(d);
      remember(m.w === 'user' ? 'user' : 'assistant', m.h);
    });
    msgs.scrollTop = msgs.scrollHeight;
    return true;
  }
  /* Unread dot on the floating button, only while the panel is closed. */
  function markUnread() { if (!panel.classList.contains('open')) fab.classList.add('has-unread'); }
  /* Claude answers arrive as plain text: make app routes and links tappable
     AFTER escaping, so nothing the model writes can inject markup. */
  function linkify(escaped) {
    return String(escaped)
      .replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>')
      .replace(/(^|[\s(])(#\/[a-z][a-z0-9\/-]*)/gi, '$1<a href="$2">$2</a>');
  }
  /* ================================================================
     v4.0 PATCH A — session conversation memory + Claude API fallback.

     The rule engine stays the first line (free, instant, offline). Only
     a MISS escalates — and only when the operator has configured a key
     (SHG_BOOT.ai, set server-side; the key itself never reaches the
     browser — api/ai-proxy.php owns it and the system prompt). If the
     proxy fails or we're offline, the old behavior is untouched: the
     static fallback + WhatsApp handoff. History is capped at 10 turns.
  ================================================================ */
  const SHG_CHAT_HISTORY = [];   // {role:'user'|'assistant', content:string}
  function remember(role, content) {
    SHG_CHAT_HISTORY.push({ role: role, content: String(content).replace(/<[^>]*>/g, ' ').slice(0, 2000) });
    while (SHG_CHAT_HISTORY.length > 20) SHG_CHAT_HISTORY.shift();
  }
  const aiOn = () => !!(window.SHG_BOOT && window.SHG_BOOT.ai) && navigator.onLine;

  function typingDots() {
    const t = document.createElement('div');
    t.className = 'ai-typing';
    t.innerHTML = '<span></span><span></span><span></span>';
    msgs.appendChild(t); msgs.scrollTop = msgs.scrollHeight;
    return t;
  }

  /* Speak bot replies aloud (A6) — off by default, toggled from the header. */
  function speakBot(html) {
    try {
      if (localStorage.getItem('shg:botVoice') !== 'on' || !window.speechSynthesis) return;
      const plain = String(html).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
      if (!plain) return;
      const u = new SpeechSynthesisUtterance(plain);
      u.lang = ({ en: 'en-IN', hi: 'hi-IN', ne: 'ne-NP', gu: 'gu-IN' })[lang()] || 'en-IN';
      speechSynthesis.cancel(); speechSynthesis.speak(u);
    } catch (e) {}
  }

  function botReply(q) {
    remember('user', q);
    const typing = typingDots();
    setTimeout(function () {
      const r = answerIntent(q);
      if (r.matched) {
        typing.remove();
        addMsg(r.a, 'bot');
        remember('assistant', r.a);
        speakBot(r.a);
        try { SFX.pop(); } catch (e) {}
        return;
      }
      /* Rule engine missed. Escalate to Claude when configured + online;
         the dots stay up while the API thinks. */
      if (!aiOn()) {
        typing.remove();
        addMsg(r.a, 'bot');
        addMsg(waForwardHtml(q), 'bot');
        try { SFX.pop(); } catch (e) {}
        return;
      }
      shgApi.post('/ai-proxy.php', { messages: SHG_CHAT_HISTORY.slice() })
        .then(function (d) {
          typing.remove();
          const text = (d && d.text) ? linkify(esc(d.text)).replace(/\n/g, '<br>') : '';
          if (!text) throw new Error('empty');
          addMsg(text, 'bot');
          remember('assistant', d.text);
          speakBot(d.text);
        })
        .catch(function () {
          // Same landing as before AI existed: static answer + WhatsApp.
          typing.remove();
          addMsg(r.a, 'bot');
          addMsg(waForwardHtml(q), 'bot');
        })
        .then(function () { try { SFX.pop(); } catch (e) {} });
    }, 420);
  }
  /* Booking-ID lookup (13 Sep 2026): a PNR this phone does not hold is
     fetched from /api/track.php. Without the booking mobile the server only
     confirms the status (a PNR is printed on every ticket, so it is no
     secret); the bot then asks for the mobile and shows the full card, the
     same two-step bar the My Bookings track box uses. */
  const PnrAsk = { pending: null };
  function pnrIn(text) {
    const m = String(text || '').toUpperCase().match(/\bSHG[-\s]?[A-Z0-9][A-Z0-9-]{3,}/);
    return m ? m[0].replace(/\s+/g, '-').replace(/-+/g, '-') : '';
  }
  function statusWord(st) {
    const map = {
      confirmed: L('✅ Confirmed', '✅ कन्फर्म', '✅ पुष्टि भयो', '✅ કન્ફર્મ'),
      pending: L('⏳ Payment being checked', '⏳ भुगतान जाँच बाकी', '⏳ भुक्तानी जाँच बाँकी', '⏳ ચુકવણી તપાસ બાકી'),
      cancelled: L('❌ Cancelled', '❌ रद्द', '❌ रद्द', '❌ રદ'),
      expired: L('⏱ Expired', '⏱ समाप्त', '⏱ म्याद सकियो', '⏱ સમાપ્ત'),
      completed: L('🏁 Completed', '🏁 पूरी हुई', '🏁 सम्पन्न', '🏁 પૂર્ણ'),
      rejected: L('⚠️ Payment not verified', '⚠️ भुगतान सत्यापित नहीं', '⚠️ भुक्तानी प्रमाणित भएन', '⚠️ ચુકવણી ચકાસાઈ નહીં')
    };
    return map[st] || esc(st || '');
  }
  function lookupPnr(pnr, phone) {
    const typing = typingDots();
    return shgApi.post('/track.php', phone ? { pnr: pnr, phone: phone } : { pnr: pnr })
      .then(function (d) {
        typing.remove();
        if (!d || d.partial) {
          const live = (d && d.live_status && d.live_status.label) ? ' · ' + esc(d.live_status.label) : '';
          const head = '🎫 <b>' + esc(pnr) + '</b> — ' + (d ? statusWord(d.status) : '') + live + '<br>';
          if (!phone) {
            PnrAsk.pending = { pnr: pnr };
            addMsg(head + L('Send the mobile number used at booking and I will show the full ticket.',
                            'बुकिंग वाला मोबाइल नंबर भेजें, पूरा टिकट दिखा दूँगा।',
                            'बुकिङ गर्दा दिएको मोबाइल नम्बर पठाउनुहोस्, पूरा टिकट देखाउँछु।',
                            'બુકિંગ વખતનો મોબાઇલ નંબર મોકલો, આખી ટિકિટ બતાવીશ.'), 'bot');
          } else {
            addMsg(head + L('That mobile number does not match this booking. Check the number, or ask the office:',
                            'यह मोबाइल नंबर इस बुकिंग से मेल नहीं खाता। नंबर जाँचें या ऑफिस से पूछें:',
                            'यो मोबाइल नम्बर यो बुकिङसँग मिलेन। नम्बर जाँच्नुहोस् वा अफिसलाई सोध्नुहोस्:',
                            'આ મોબાઇલ નંબર આ બુકિંગ સાથે મેળ ખાતો નથી:') + '<br>' + waForwardHtml('Ticket ' + pnr), 'bot');
          }
          return;
        }
        const owner = phone || ((typeof USER !== 'undefined' && USER && USER.phone) || '');
        const b = bookingFromServer(d, owner);
        const at = DB.bookings.findIndex(function (x) { return x && x.id === b.id; });
        if (at >= 0) DB.bookings[at] = Object.assign({}, DB.bookings[at], b); else DB.bookings.unshift(b);
        try { persist('bookings'); } catch (e) {}
        const html = bookingCard(b);
        addMsg(html, 'bot');
        remember('assistant', html);
      })
      .catch(function (e) {
        typing.remove();
        const nf = e && e.status === 404;
        addMsg(nf
          ? L('No booking found with ' + esc(pnr) + '. Check the ID on your ticket or WhatsApp message.',
              esc(pnr) + ' से कोई बुकिंग नहीं मिली। टिकट या WhatsApp संदेश पर ID जाँचें।',
              esc(pnr) + ' को बुकिङ भेटिएन। टिकट वा WhatsApp सन्देशमा ID जाँच्नुहोस्।',
              esc(pnr) + ' બુકિંગ મળ્યું નહીં.')
          : L('Could not check right now — please try again in a moment.', 'अभी जाँच नहीं हो सकी — थोड़ी देर में फिर कोशिश करें।', 'अहिले जाँच्न सकिएन — केही बेरमा फेरि प्रयास गर्नुहोस्।', 'હમણાં તપાસી શકાયું નહીં.'), 'bot');
      });
  }
  function send(text) {
    const q = String(text == null ? input.value : text).trim();
    if (!q) return;
    { const dl = detectLang(q); if (dl) msgLang = dl; }
    addMsg(q, 'user');
    input.value = '';
    /* A complaint mid-flight owns the next message(s): first the phone if we
       don't know one, then the description. Everything else falls through to
       the normal intent engine. */
    if (Complaint.pending && Complaint.consume(q)) return;
    if (PnrAsk.pending) {
      const d10 = digits(q);
      const held = PnrAsk.pending.pnr;
      PnrAsk.pending = null;               // one follow-up only; anything else is a new question
      if (d10.length >= 10) { remember('user', 'mobile for ' + held); return lookupPnr(held, d10); }
    }
    {
      const pnr = pnrIn(q);
      if (pnr && navigator.onLine && !(DB.bookings || []).some(function (x) { return String(x.id).toUpperCase() === pnr; })) {
        remember('user', q);
        return lookupPnr(pnr, (typeof USER !== 'undefined' && USER && USER.phone) || '');
      }
    }
    if (has(q.toLowerCase(), ['where am i', 'mero location', 'मैं कहाँ हूँ', 'म कहाँ छु', 'हुं क्यां छुं', 'હું ક્યાં છું', 'najik ko boarding', 'nearest boarding'])) {
      return locateMe();
    }
    botReply(q);
  }

  /* ================================================================
     DAILY TIMETABLE (#ttBox) — rendered from /api/timetable.php.

     One board a passenger can read for the whole run. The server marks
     each pickup `open` using the SAME cut-off booking enforces, so a
     stop shown as already-served here is exactly a stop checkout will
     refuse. Departed stops are struck through rather than removed.
  ================================================================= */
  (function wireTimetable() {
    const box = document.getElementById('ttBox');
    if (!box) return;

    const esc2 = (s) => esc(s == null ? '' : s);

    const stopRow = (s, isBoard) => {
      const gone = isBoard && s.open === false;
      const tags = (s.isBorder ? ' <span class="tt-tag">🛂 border</span>' : '')
                 + (s.isMeal   ? ' <span class="tt-tag">🍽️ meal halt</span>' : '');
      return '<li class="tt-stop' + (gone ? ' tt-gone' : '') + '">'
        + '<span class="tt-time">' + esc2(s.time || '—') + '</span>'
        + '<span class="tt-name">' + esc2(s.name) + tags
        + (gone ? ' <span class="tt-gone-tag">बस गइसक्यो · already departed</span>' : '')
        + '</span></li>';
    };

    const card = (r) => {
      const board = (r.boarding || []).map(s => stopRow(s, true)).join('');
      const drop  = (r.drop || []).map(s => stopRow(s, false)).join('');
      return '<div class="tt-card' + (r.sellable ? '' : ' tt-done') + '">'
        + '<div class="tt-head">'
        +   '<b>' + esc2(r.from) + ' → ' + esc2(r.to) + '</b>'
        +   '<span class="tt-dep">🕒 ' + esc2(r.depTime) + '</span>'
        +   (r.sellable
              ? '<span class="tt-live">आज बुक हुन्छ · booking open</span>'
              : '<span class="tt-off">आजको बस गइसक्यो · today’s bus has gone</span>')
        + '</div>'
        + (board ? '<div class="tt-sub">बोर्डिङ · Pickup points</div><ul class="tt-list">' + board + '</ul>' : '')
        + (drop  ? '<div class="tt-sub">ओर्लने · Drop points</div><ul class="tt-list">' + drop + '</ul>' : '')
        + '</div>';
    };

    ((typeof shgTimetableGet === 'function') ? shgTimetableGet('') : shgApi.get('/timetable.php')).then(function (res) {
      const d = (res && res.data) || res || {};
      const routes = (d.routes || []).filter(r => (r.boarding || []).length || (r.drop || []).length);
      if (!routes.length) {
        box.innerHTML = '<p class="muted" style="text-align:center;padding:18px">'
          + 'समय तालिका अहिले उपलब्ध छैन — कृपया हामीलाई फोन गर्नुहोस्. · Timetable unavailable right now — please call us.</p>';
        return;
      }
      box.innerHTML = routes.map(card).join('');
    }).catch(function () {
      box.innerHTML = '<p class="muted" style="text-align:center;padding:18px">'
        + 'समय तालिका लोड भएन — इन्टरनेट जाँच्नुहोस्. · Could not load the timetable — check your connection.</p>';
    });
  })();

  /* ================================================================
     BECOME AN AGENT — the public application form (#agentApplyForm).
     Posts to the same api/enquiry.php the complaint desk uses, with
     source='agent_apply', so it lands in the one admin Enquiries inbox
     staff already watch instead of a second disconnected queue. The
     office then phones the applicant and creates the real account by
     hand — this form never grants a login.
  ================================================================= */
  (function wireAgentApply() {
    const form = document.getElementById('agentApplyForm');
    if (!form) return;

    const msg = document.getElementById('aaMsg');
    const btn = document.getElementById('aaSubmit');

    const say = (text, ok) => {
      if (!msg) return;
      msg.textContent = text;
      msg.style.color = ok ? '#0a6b3b' : '#b3261e';
    };

    form.addEventListener('submit', function (e) {
      e.preventDefault();

      const name  = (document.getElementById('aaName')  || {}).value || '';
      const phone = (document.getElementById('aaPhone') || {}).value || '';
      const area  = (document.getElementById('aaArea')  || {}).value || '';
      const about = (document.getElementById('aaNote')  || {}).value || '';

      if (!name.trim()) { say('कृपया आफ्नो नाम लेख्नुहोस् · Please enter your name.', false); return; }
      // The server validates the number too; this is just a fast local check.
      if (String(phone).replace(/\D/g, '').length < 10) {
        say('१० अङ्कको मोबाइल नम्बर लेख्नुहोस् · Enter a valid 10-digit mobile number.', false);
        return;
      }

      // The enquiries row has no dedicated area/about columns, so both are
      // folded into `note` (255 chars server-side) with a clear prefix the
      // office can read at a glance in the inbox.
      const note = ('AGENT APPLY'
        + (area.trim()  ? ' · ' + area.trim()  : '')
        + (about.trim() ? ' · ' + about.trim() : '')).slice(0, 250);

      if (btn) { btn.disabled = true; btn.textContent = 'पठाउँदै… · Sending…'; }
      say('', true);

      shgApi.post('/enquiry.php', {
        name: name.trim(),
        phone: phone,
        note: note,
        source: 'agent_apply'
      }).then(function () {
        form.reset();
        say('✅ आवेदन प्राप्त भयो — हाम्रो कार्यालयबाट यही नम्बरमा फोन आउनेछ। · Application received, our office will call you.', true);
      }).catch(function (err) {
        say('पठाउन सकिएन — इन्टरनेट जाँच्नुहोस् वा फोन गर्नुहोस्। · Could not send'
          + (err && err.message ? ' (' + err.message + ')' : '') + ' — please check your connection or call us.', false);
      }).finally(function () {
        if (btn) { btn.disabled = false; btn.textContent = 'आवेदन पठाउनुहोस् · Send application'; }
      });
    });
  })();

  /* ================================================================
     COMPLAINT DESK (Blueprint Module 1.2 / 4) — files into the SAME
     admin Enquiries inbox staff already watch, via api/enquiry.php
     with source='complaint'. Offline it queues in this browser and
     auto-sends when the connection returns; the passenger gets their
     reference number either way, because the reference is minted
     locally — exactly what the blueprint asks for.
  ================================================================ */
  const Complaint = {
    pending: null,
    QKEY: 'shg:cq',
    CATS: [
      ['late',    '🚌', ['Late bus', 'बस लेट', 'बस ढिलो', 'બસ મોડી']],
      ['staff',   '😤', ['Staff behaviour', 'स्टाफ व्यवहार', 'स्टाफ व्यवहार', 'સ્ટાફ વર્તન']],
      ['luggage', '🧳', ['Luggage', 'सामान', 'सामान', 'સામાન']],
      ['seat',    '💺', ['Seat issue', 'सीट समस्या', 'सिट समस्या', 'સીટ સમસ્યા']],
      ['refund',  '💸', ['Refund delay', 'रिफंड देरी', 'फिर्ता ढिलाइ', 'રિફંડ વિલંબ']],
      ['border',  '🛃', ['Border issue', 'बॉर्डर समस्या', 'बोर्डर समस्या', 'બોર્ડર સમસ્યા']],
      ['ac',      '🌡️', ['AC not working', 'AC खराब', 'AC बिग्रेको', 'AC ખરાબ']],
      ['other',   '⚡', ['Other', 'अन्य', 'अन्य', 'અન્ય']],
    ],
    catLabel(id) {
      const c = this.CATS.find(x => x[0] === id);
      const i = { en: 0, hi: 1, ne: 2, gu: 3 }[lang()] || 0;
      return c ? c[1] + ' ' + c[2][i] : id;
    },
    menuHtml() {
      return L('What went wrong? Pick one:', 'क्या समस्या हुई? चुनें:', 'के समस्या भयो? छान्नुहोस्:', 'શું સમસ્યા થઈ? પસંદ કરો:')
        + '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">'
        + this.CATS.map(c => {
            const i = { en: 0, hi: 1, ne: 2, gu: 3 }[lang()] || 0;
            return '<button type="button" class="ai-chip" data-aicat="' + c[0] + '">' + c[1] + ' ' + c[2][i] + '</button>';
          }).join('')
        + '</div>';
    },
    start(cat) {
      // Phone: the latest booking's, else the signed-in user's, else ask.
      const b = (DB.bookings || [])[0];
      const known = digits((b && b.contact && b.contact.phone) || (window.USER ? USER.phone : '') || '');
      this.pending = { cat: cat, phone: known, pnr: b ? b.id : '' };
      if (!known) {
        this.pending.step = 'phone';
        addMsg(L('Your mobile number? (so we can call you back)', 'आपका मोबाइल नंबर? (ताकि हम संपर्क कर सकें)', 'तपाईंको मोबाइल नम्बर? (सम्पर्कका लागि)', 'તમારો મોબાઇલ નંબર?'), 'bot');
      } else {
        this.pending.step = 'desc';
        addMsg(L('Tell me what happened — one message is enough.', 'क्या हुआ बताएं — एक संदेश काफी है।', 'के भयो भन्नुहोस् — एउटै सन्देश पुग्छ।', 'શું થયું કહો — એક સંદેશ પૂરતો છે.'), 'bot');
      }
    },
    /* Returns true when the message was eaten by the flow. */
    consume(q) {
      const p = this.pending;
      if (p.step === 'phone') {
        const d = digits(q);
        if (d.length < 10) {
          addMsg(L('That does not look like a mobile number — 10 digits please.', '10 अंकों का मोबाइल नंबर लिखें।', '१० अङ्कको मोबाइल नम्बर लेख्नुहोस्।', '10 અંકનો નંબર લખો.'), 'bot');
          return true;
        }
        p.phone = d; p.step = 'desc';
        addMsg(L('Got it. Now tell me what happened.', 'ठीक है। अब बताएं क्या हुआ।', 'भयो। अब के भयो भन्नुहोस्।', 'બરાબર. હવે કહો શું થયું.'), 'bot');
        return true;
      }
      if (p.step === 'desc') {
        this.pending = null;
        this.file(p, q);
        return true;
      }
      return false;
    },
    timeline(cat) {
      if (cat === 'refund') return L('Refunds resolve in 3–5 working days.', 'रिफंड 3–5 कार्यदिवस में।', 'फिर्ता ३–५ कार्यदिनमा।', 'રિફંડ 3–5 દિવસમાં.');
      if (cat === 'staff')  return L('Senior staff will review within 24 hours.', 'सीनियर स्टाफ 24 घंटे में देखेंगे।', 'सिनियर स्टाफले २४ घण्टामा हेर्नेछन्।', '24 કલાકમાં રિવ્યૂ.');
      return L('You will hear back within 24–48 hours.', '24–48 घंटे में जवाब मिलेगा।', '२४–४८ घण्टामा जवाफ पाउनुहुनेछ।', '24–48 કલાકમાં જવાબ.');
    },
    file(p, desc) {
      const ref = 'SHG-C-' + Date.now().toString(36).toUpperCase();
      const b = (DB.bookings || []).find(x => x.id === p.pnr);
      const item = {
        name: (b && b.passengers && b.passengers[0] && b.passengers[0].name) || 'Sahayak guest',
        phone: p.phone,
        note: ('[' + ref + '] ' + p.cat + (p.pnr ? ' · ' + p.pnr : '') + ' · ' + desc).slice(0, 250),
        source: 'complaint'
      };
      const done = L('Complaint <b>' + ref + '</b> registered — ' + this.catLabel(p.cat) + '.',
        'शिकायत <b>' + ref + '</b> दर्ज — ' + this.catLabel(p.cat) + '।',
        'गुनासो <b>' + ref + '</b> दर्ता — ' + this.catLabel(p.cat) + '।',
        'ફરિયાદ <b>' + ref + '</b> નોંધાઈ.');
      const escal = '<br><small>' + L('Not resolved? Say "still not resolved" and I will hand you straight to the office on WhatsApp.',
        'हल नहीं हुआ? "still not resolved" लिखें — सीधे WhatsApp पर ऑफिस से जोड़ दूँगा।',
        'समाधान भएन? "still not resolved" लेख्नुहोस् — सीधै WhatsApp मा अफिससँग जोडिदिन्छु।',
        'ઉકેલ ન આવ્યો? "still not resolved" લખો.') + '</small>';
      shgApi.post('/enquiry.php', item).then(() => {
        addMsg('✅ ' + done + '<br>⏱️ ' + this.timeline(p.cat) + escal, 'bot');
      }).catch(() => {
        // Offline or server said no — keep it, send it later, same reference.
        try {
          const qd = JSON.parse(localStorage.getItem(this.QKEY) || '[]');
          qd.push(item); localStorage.setItem(this.QKEY, JSON.stringify(qd));
        } catch (e) {}
        addMsg('📵 ' + done + '<br>' + L('You are offline — it is saved on this phone and will send itself when the network returns.',
          'आप ऑफलाइन हैं — फोन में सेव है, नेटवर्क आते ही अपने-आप भेज दी जाएगी।',
          'तपाईं अफलाइन हुनुहुन्छ — फोनमै सुरक्षित छ, नेटवर्क आउनासाथ आफैं पठाइनेछ।',
          'ઑફલાઇન છો — નેટવર્ક આવતાં આપમેળે મોકલાશે.') + escal, 'bot');
      });
    },
    flush() {
      let qd = [];
      try { qd = JSON.parse(localStorage.getItem(this.QKEY) || '[]'); } catch (e) { return; }
      if (!qd.length) return;
      const rest = qd.slice();
      const next = () => {
        if (!rest.length) { localStorage.setItem(this.QKEY, '[]'); return; }
        shgApi.post('/enquiry.php', rest[0])
          .then(() => { rest.shift(); localStorage.setItem(this.QKEY, JSON.stringify(rest)); next(); })
          .catch(() => { localStorage.setItem(this.QKEY, JSON.stringify(rest)); });
      };
      next();
    }
  };
  window.addEventListener('online', function () { Complaint.flush(); });
  setTimeout(function () { Complaint.flush(); }, 4000);   // queued while offline last visit

  /* ================================================================
     "WHERE AM I?" (Blueprint Module 1.1) — GPS → nearest boarding
     point, measured against the stop coordinates already embedded in
     the route catalogue. Asked-for only (a chip / typed question);
     never requested silently on open.
  ================================================================ */
  function boardingPoints() {
    const pts = [];
    (DB.routes || []).forEach(r => {
      (r.boarding || []).forEach(s => {
        const m = String(s).match(/^(.*?)(?:\s*@[^\[]*)?\[([\d.\-]+),([\d.\-]+)\]\s*$/);
        if (m) pts.push({ name: m[1].trim(), lat: +m[2], lng: +m[3], city: r.from });
      });
    });
    return pts;
  }
  function havKm(a, b, c, d) {
    const R = 6371, dLat = (c - a) * Math.PI / 180, dLng = (d - b) * Math.PI / 180;
    const x = Math.sin(dLat / 2) ** 2 + Math.cos(a * Math.PI / 180) * Math.cos(c * Math.PI / 180) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(x));
  }
  function locateMe() {
    if (!navigator.geolocation) {
      return addMsg(L('This device has no GPS. Type your district (e.g. "Surkhet") and I will guide you.',
        'GPS नहीं है। जिला लिखें (जैसे "Surkhet")।', 'GPS छैन। जिल्ला लेख्नुहोस् (जस्तै "Surkhet")।', 'GPS નથી. જિલ્લો લખો.'), 'bot');
    }
    addMsg('📡 ' + L('Finding you…', 'ढूंढ रहा हूँ…', 'खोज्दैछु…', 'શોધી રહ્યો છું…'), 'bot');
    navigator.geolocation.getCurrentPosition(function (pos) {
      const me = pos.coords;
      let best = null;
      boardingPoints().forEach(p => {
        const km = havKm(me.latitude, me.longitude, p.lat, p.lng);
        if (!best || km < best.km) best = { p: p, km: km };
      });
      if (!best) {
        return addMsg(L('Route data is still loading — try again in a moment.', 'डेटा लोड हो रहा है — फिर कोशिश करें।', 'डाटा लोड हुँदैछ — फेरि प्रयास गर्नुहोस्।', 'ડેટા લોડ થાય છે.'), 'bot');
      }
      const km = Math.round(best.km);
      addMsg('📍 ' + L('Nearest boarding point: <b>' + esc(best.p.name) + '</b> (' + esc(best.p.city) + ' side) — about <b>' + km + ' km</b> from you.',
        'नज़दीकी बोर्डिंग: <b>' + esc(best.p.name) + '</b> — आपसे लगभग <b>' + km + ' km</b>।',
        'नजिकको बोर्डिङ: <b>' + esc(best.p.name) + '</b> — तपाईंबाट लगभग <b>' + km + ' km</b>।',
        'નજીકનું બોર્ડિંગ: <b>' + esc(best.p.name) + '</b> — <b>' + km + ' km</b>.')
        + '<br><a href="#/" data-scroll="search-anchor">' + L('Book your seat →', 'सीट बुक करें →', 'सिट बुक गर्नुहोस् →', 'સીટ બુક કરો →') + '</a>', 'bot');
    }, function () {
      addMsg(L('Could not read GPS (permission denied?). Type your district instead — e.g. "Surkhet".',
        'GPS नहीं मिला (अनुमति नहीं?)। जिला लिखें — जैसे "Surkhet"।',
        'GPS पढ्न सकिएन (अनुमति छैन?)। जिल्ला लेख्नुहोस् — जस्तै "Surkhet"।',
        'GPS ન મળ્યું. જિલ્લો લખો.'), 'bot');
    }, { timeout: 8000, maximumAge: 120000 });
  }
  function renderChips() {
    chipsBox.innerHTML = '';
    (CHIPS[langSel.value] || CHIPS.en).forEach(function (c) {
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'ai-chip'; b.textContent = c[0];
      b.onclick = function () { send(c[1]); };
      chipsBox.appendChild(b);
    });
  }
  let greeted = false;
  const NUDGE = A('👋 Namaste! Booking a seat, checking a ticket or the bus timing? Ask me here, or tap 📞 Call Agent to talk to a person.',
    '👋 नमस्ते! सीट बुक करनी है, टिकट देखना है या बस का समय? यहाँ पूछें, या व्यक्ति से बात के लिए 📞 दबाएँ।',
    '👋 नमस्ते! सिट बुक गर्नु, टिकट हेर्नु वा बसको समय? यहाँ सोध्नुहोस्, वा कर्मचारीसँग कुरा गर्न 📞 थिच्नुहोस्।',
    '👋 નમસ્તે! સીટ બુક કરવી છે, ટિકિટ જોવી છે કે બસનો સમય? અહીં પૂછો.');
  (function nudge() {
    try {
      if (chatLoad().length) return;                        // they have talked to us before
      const last = +(localStorage.getItem('shg:aiNudge') || 0);
      if (Date.now() - last < 7 * 86400000) return;        // once a week at most
      setTimeout(function () {
        if (panel.classList.contains('open') || greeted || (location.hash || '#/') !== '#/') return;
        try { localStorage.setItem('shg:aiNudge', String(Date.now())); } catch (e) {}
        greeted = true;
        addMsg(NUDGE[lang()], 'bot');                      // panel closed, so the dot lights up
      }, 30000);
    } catch (e) {}
  })();
  fab.addEventListener('click', function () {
    panel.classList.toggle('open');
    fab.classList.remove('has-unread');
    if (panel.classList.contains('open')) {
      renderChips();
      if (!greeted) { greeted = true; if (!restoreChat()) addMsg(GREET[lang()], 'bot'); }
      input.focus();
      try { SFX.pop(); } catch (e) {}
    }
  });
  $('#aiClose').addEventListener('click', function () { panel.classList.remove('open'); });
  $('#aiSend').addEventListener('click', function () { send(); });
  input.addEventListener('keydown', function (e) { if (e.key === 'Enter') send(); });
  langSel.addEventListener('change', function () { msgLang = ''; renderChips(); addMsg(GREET[lang()], 'bot'); });
  /* links inside bot replies use data-scroll — close the panel so the scroll is visible */
  msgs.addEventListener('click', function (e) {
    if (e.target.closest('a')) panel.classList.remove('open');
  });
  /* complaint category buttons rendered inside bot replies */
  msgs.addEventListener('click', function (e) {
    const c = e.target.closest('[data-aicat]');
    if (c) Complaint.start(c.getAttribute('data-aicat'));
  });

  /* — v4.0 header controls: AI badge, clear history, voice-reply toggle — */
  (function () {
    const badge = $('#aiPoweredBadge');
    if (badge && window.SHG_BOOT && window.SHG_BOOT.ai) badge.classList.remove('hide');
    const clr = $('#aiClearHist');
    if (clr) clr.addEventListener('click', function () {
      SHG_CHAT_HISTORY.length = 0;
      chatSave([]);
      msgs.innerHTML = '';
      addMsg(GREET[lang()], 'bot');
      try { SFX.pop(); } catch (e) {}
    });
    const vt = $('#aiVoiceTog');
    const paint = () => { if (vt) vt.textContent = localStorage.getItem('shg:botVoice') === 'on' ? '🔊' : '🔇'; };
    if (vt) {
      if (!window.speechSynthesis) vt.style.display = 'none';
      vt.addEventListener('click', function () {
        const on = localStorage.getItem('shg:botVoice') === 'on';
        try { localStorage.setItem('shg:botVoice', on ? 'off' : 'on'); } catch (e) {}
        if (on) { try { speechSynthesis.cancel(); } catch (e) {} }
        paint();
      });
      paint();
    }
  })();

  /* — voice input (Web Speech API, guarded) — */
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  const SR_LANGS = { en: 'en-IN', hi: 'hi-IN', ne: 'ne-NP', gu: 'gu-IN' };
  const mic = $('#aiMic');
  if (!SR) { if (mic) mic.style.display = 'none'; }
  else if (mic) {
    let rec = null, listening = false;
    mic.addEventListener('click', function () {
      if (listening && rec) { try { rec.stop(); } catch (e) {} return; }
      rec = new SR();
      rec.lang = SR_LANGS[lang()] || 'en-IN';
      rec.interimResults = false; rec.maxAlternatives = 1;
      rec.onstart = function () { listening = true; mic.classList.add('listening'); };
      rec.onend = function () { listening = false; mic.classList.remove('listening'); };
      rec.onerror = function () { listening = false; mic.classList.remove('listening'); toast('🎤 Voice not available — कृपया टाइप गर्नुहोस् · कृपया टाइप करें'); };
      rec.onresult = function (ev) {
        const said = ev.results[0][0].transcript;
        input.value = said;
        send(said);
      };
      try { rec.start(); } catch (e) { toast('🎤 Voice not available'); }
    });
  }

  /* — voice search on the home search card — */
  const vsBtn = $('#voiceSearchBtn');
  if (vsBtn && SR) {
    vsBtn.classList.remove('hide');
    const CITY_ALIASES = {
      'surat': 'Surat', 'सूरत': 'Surat', 'सुरत': 'Surat', 'સુરત': 'Surat',
      'rupaidiha': 'Rupaidiha', 'rupaidia': 'Rupaidiha', 'rupediha': 'Rupaidiha',
      'रुपईडीहा': 'Rupaidiha', 'रुपैडिहा': 'Rupaidiha', 'रुपइडिहा': 'Rupaidiha', 'રૂપૈડીહા': 'Rupaidiha'
    };
    vsBtn.addEventListener('click', function () {
      const rec = new SR();
      rec.lang = 'hi-IN'; rec.interimResults = false;
      vsBtn.textContent = '🔴';
      rec.onend = function () { vsBtn.textContent = '🎤'; };
      rec.onerror = function () { vsBtn.textContent = '🎤'; toast('🎤 Voice not available — please type'); };
      rec.onresult = function (ev) {
        const said = (ev.results[0][0].transcript || '').toLowerCase();
        toast('🎤 "' + said + '"');
        const found = [];
        Object.keys(CITY_ALIASES).forEach(function (alias) {
          const idx = said.indexOf(alias);
          if (idx >= 0 && found.every(f => f.city !== CITY_ALIASES[alias])) found.push({ city: CITY_ALIASES[alias], idx: idx });
        });
        found.sort((a, b) => a.idx - b.idx);
        const from = $('#fromSel'), to = $('#toSel');
        function setSel(sel, city) {
          if (!sel) return false;
          for (let i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value.toLowerCase().indexOf(city.toLowerCase()) >= 0) { sel.value = sel.options[i].value; return true; }
          }
          return false;
        }
        if (found.length >= 2) {
          setSel(from, found[0].city); setSel(to, found[1].city);
          try { SFX.success(); } catch (e) {}
          const form = from && from.closest('form');
          if (form) { if (form.requestSubmit) form.requestSubmit(); else form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true })); }
        } else if (found.length === 1) {
          if (from && from.value.toLowerCase().indexOf(found[0].city.toLowerCase()) >= 0) setSel(to, found[0].city);
          else setSel(to, found[0].city) || setSel(from, found[0].city);
          toast('✅ ' + found[0].city + ' set — अब date चुनें और Search दबाएँ');
        } else {
          toast('⚠️ City बुझिएन — Surat वा Rupaidiha भन्नुहोस् · सूरत या रुपईडीहा बोलें');
        }
      };
      try { rec.start(); } catch (e) { vsBtn.textContent = '🎤'; }
    });
  }

  /* — 📍 Location button: distance to Mehsana office — */
  var OFFICE = { lat: 23.588, lng: 72.369, name: 'SHG Office, Mehsana' };
  var locBtn = document.createElement('button');
  locBtn.type = 'button'; locBtn.className = 'ai-chip'; locBtn.textContent = '📍 My distance';
  locBtn.style.cssText = 'background:var(--orange-100);color:var(--orange-600);border-color:var(--orange)';
  locBtn.onclick = function () {
    if (!navigator.geolocation) { addMsg('📍 Geolocation is not supported by your browser.', 'bot'); return; }
    addMsg('📍 Finding your location…', 'bot');
    navigator.geolocation.getCurrentPosition(function (pos) {
      var km = haversineKm(pos.coords.latitude, pos.coords.longitude, OFFICE.lat, OFFICE.lng);
      var L = lang();
      var msg = L === 'hi' ? '📍 आप ' + OFFICE.name + ' से <b>' + km.toFixed(1) + ' km</b> दूर हैं।'
        : L === 'ne' ? '📍 तपाईं ' + OFFICE.name + ' बाट <b>' + km.toFixed(1) + ' km</b> टाढा हुनुहुन्छ।'
        : L === 'gu' ? '📍 તમે ' + OFFICE.name + ' થી <b>' + km.toFixed(1) + ' km</b> દૂર છો.'
        : '📍 You are <b>' + km.toFixed(1) + ' km</b> from ' + OFFICE.name + '.';
      addMsg(msg, 'bot');
    }, function () {
      addMsg('📍 Location access denied. Please enable location in your browser settings and try again.', 'bot');
    }, { enableHighAccuracy: false, timeout: 8000 });
  };
  chipsBox.parentNode.insertBefore(locBtn, chipsBox.nextSibling);

  /* — 📊 Chat Analytics (session-only, for admin) — */
  var chatStats = { opens: 0, intents: {}, langs: {} };
  var origFabClick = fab.onclick;
  fab.addEventListener('click', function () { chatStats.opens++; });
  var origSend = send;
  send = function (t) {
    var q = String(t == null ? input.value : t).trim();
    if (q) {
      var matched = false;
      var ql = ' ' + q.toLowerCase() + ' ';
      for (var i = 0; i < INTENTS.length; i++) {
        if (INTENTS[i].k.some(function (kw) { return ql.indexOf(kw.toLowerCase()) >= 0; })) {
          var key = INTENTS[i].k[0]; chatStats.intents[key] = (chatStats.intents[key] || 0) + 1; matched = true; break;
        }
      }
      if (!matched) chatStats.intents['_unknown'] = (chatStats.intents['_unknown'] || 0) + 1;
      var cl = lang(); chatStats.langs[cl] = (chatStats.langs[cl] || 0) + 1;
    }
    origSend(t);
  };
  window.getChatAnalytics = function () { return chatStats; };
})();
