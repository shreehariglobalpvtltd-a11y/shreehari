/* =====================================================================
 *  15-nav.js — S Hari Nav (fullscreen MapLibre navigator).
 *
 *  Split out of 13-admin-routes.js on 5 Sep 2026 and loaded ON DEMAND by
 *  snNavLoad() (13-admin-routes.js) the first time #/nav opens, so the
 *  ~1,650 lines of map code no longer parse on every page of the site.
 *  SN_STOPS / SN_HOME (data other views read) stay in 13-admin-routes.js.
 *  Everything here is classic-script global scope, exactly as before.
 * ===================================================================== */

/* ================================================================
   [JS] NAV APP — fullscreen MapLibre navigator (merged from shari-nav-prototype)
   Lazy-loads MapLibre GL JS on first visit to #/nav. All state in the SN namespace.
================================================================ */
var SN = { map:null, ready:false, loading:false, watchId:null, meMarker:null, meAnim:null,
  gpsPos:null, origin:null, dest:null, originMarker:null, destMarker:null, routeGeo:null,
  mode:'auto', currentStyle:'day', is3D:false, followMe:false, presetMarkers:[],
  templeMarkers:[], templesOn:true, toastT:null,
  /* live-tracking upgrade state (SN-local only — nothing outside this block reads these) */
  prevGps:null, routeCoords:null, routeManeuvers:null, routeCumKm:null, routeTotalKm:0,
  routeProgKm:0, offRouteSince:0, rerouteT:null, userRotated:false, voiceOn:true, voiceLang:'en', lastSpokenInstr:null,
  driverBroadcast:false, trackingMode:false, busMarker:null, trackUnsub:null, trackLastTs:0, trackTickT:null,
  persistFollow:false, compassMode:false, arrived:false, altRoutes:null, busAnim:null,
  /* shg-v59 map-smooth — Google-Maps-style motion polish. All frame IDs so cleanupNav can cancel.
     gpsIntervalMs adapts marker-tween duration to the real fix cadence so the marker glides into
     the next fix instead of stopping short of it. meRotAnim/busRotAnim carry shortest-arc rotation
     lerps so heading changes never snap. routeDrawAnim owns the "route draws itself" line-gradient
     sweep and mapReady gates the initial map fade-in from opacity:0. */
  gpsIntervalMs:1000, lastGpsTs:0, meRotAnim:null, meRotFrom:0, meRotTo:0,
  busRotAnim:null, busRotFrom:0, busRotTo:0, routeDrawAnim:null, mapReady:false,
  /* 5 Sep 2026 — lite mode (low-end phones), 3D terrain, motion engine (one rAF loop
     that glides both markers + the follow camera between fixes with dead reckoning),
     bus trail, follow-bus camera, cross-device live-bus poll, observed-speed ETA. */
  lite:false, terrainOn:false, motionAnim:null, motionLast:0, meTarget:null, meDisp:null,
  busTarget:null, busDisp:null, busTrail:[], followBus:true, camLock:false, trackPollT:null, lastKvAt:0, avgSpdKmh:0 };

var SN_TEMPLES=[
  {n:'Somnath',lat:20.888,lng:70.4013,deity:'Shiva / शिव',wiki:'Somnath temple',hi:'प्रथम ज्योतिर्लिंग — समुद्र तट पर शिव मंदिर, कई बार नष्ट और पुनर्निर्मित।',special:'First of the 12 Jyotirlingas — a coastal Shiva shrine destroyed and rebuilt many times across a thousand years.'},
  {n:'Dwarkadhish',lat:22.2376,lng:68.9685,deity:'Krishna / कृष्ण',wiki:'Dwarkadhish Temple',hi:'चार धाम में से एक, श्री कृष्ण की प्राचीन नगरी; पाँच मंज़िला शिखर पर दिन में पाँच बार ध्वज बदलता है।',special:'One of the Char Dham, marking the ancient kingdom of Krishna.'},
  {n:'Nageshwar Jyotirlinga',lat:22.3363,lng:68.9686,deity:'Shiva / शिव',wiki:'Nageshvara Jyotirlinga',hi:'द्वारका के पास 12 ज्योतिर्लिंगों में से एक, 25 मीटर ऊँची शिव प्रतिमा।',special:'One of the 12 Jyotirlingas near Dwarka, 25-metre Shiva statue.'},
  {n:'Modhera Sun Temple',lat:23.5836,lng:72.1319,deity:'Surya / सूर्य',wiki:'Sun Temple, Modhera',hi:'मेहसाणा के पास 11वीं सदी का सूर्य मंदिर, शानदार सीढ़ीदार कुंड; सूर्योदय की किरणें गर्भगृह तक पहुँचती हैं।',special:'An 11th-century Sun temple beside Mehsana with a magnificent stepped tank.'},
  {n:'Shrinathji, Nathdwara',lat:24.9316,lng:73.8205,deity:'Krishna / कृष्ण',wiki:'Nathdwara',hi:'पुष्टिमार्ग का सबसे महत्वपूर्ण मंदिर, गोवर्धन पर्वत उठाते बाल कृष्ण।',special:'The most important Pushtimarg shrine, worshipping Krishna lifting Govardhan hill.'},
  {n:'Brahma Temple, Pushkar',lat:26.4871,lng:74.5551,deity:'Brahma / ब्रह्मा',wiki:'Brahma Temple, Pushkar',hi:'विश्व के गिने-चुने ब्रह्मा मंदिरों में से एक, पवित्र पुष्कर सरोवर के किनारे।',special:'One of very few temples to Lord Brahma, beside holy Pushkar Lake.'},
  {n:'Govind Dev Ji, Jaipur',lat:26.9257,lng:75.8235,deity:'Krishna / कृष्ण',wiki:'Govind Dev Ji Temple',hi:'जयपुर का प्रिय कृष्ण मंदिर, सिटी पैलेस के भीतर; मूर्ति वृंदावन से लाई गई।',special:'Jaipur beloved Krishna temple inside the City Palace.'},
  {n:'Khatu Shyam Ji',lat:27.3869,lng:75.4008,deity:'Krishna / कृष्ण',wiki:'Khatushyamji',hi:'राजस्थान का अत्यंत लोकप्रिय मंदिर; मेलों में लाखों भक्त आते हैं।',special:'Rajasthan hugely popular shrine to Khatu Shyam Ji.'},
  {n:'Salasar Balaji',lat:27.6019,lng:74.7906,deity:'Hanuman / हनुमान',wiki:'Salasar Balaji',hi:'दाढ़ी-मूँछ वाले बालाजी का प्रसिद्ध हनुमान मंदिर।',special:'A famous Hanuman shrine with a unique bearded form of Balaji.'},
  {n:'Kashi Vishwanath',lat:25.3109,lng:83.0107,deity:'Shiva / शिव',wiki:'Kashi Vishwanath Temple',hi:'वाराणसी का आध्यात्मिक हृदय, गंगा किनारे ज्योतिर्लिंग; नवनिर्मित भव्य कॉरिडोर।',special:'The spiritual heart of Varanasi and a Jyotirlinga by the Ganga.'},
  {n:'Ram Mandir, Ayodhya',lat:26.7957,lng:82.1943,deity:'Ram / राम',wiki:'Ram Mandir',hi:'2024 में भगवान राम की जन्मभूमि पर नवनिर्मित — भारत के सबसे महत्वपूर्ण आधुनिक मंदिरों में।',special:'Newly built in 2024 at the believed birthplace of Lord Ram.'},
  {n:'Kedarnath',lat:30.7352,lng:79.0669,deity:'Shiva / शिव',wiki:'Kedarnath Temple',hi:'हिमालय में 3,583 मीटर पर सर्वोच्च ज्योतिर्लिंग, वर्ष में लगभग छह महीने ही खुला।',special:'The highest of the 12 Jyotirlingas at 3,583 metres in the Himalayas.'},
  {n:'Badrinath',lat:30.7433,lng:79.4938,deity:'Vishnu / विष्णु',wiki:'Badrinath Temple',hi:'चार धाम में से एक, गढ़वाल हिमालय में अलकनंदा नदी के किनारे।',special:'One of the Char Dham, set in the Garhwal Himalayas.'},
  {n:'Vaishno Devi',lat:33.0308,lng:74.949,deity:'Shakti / शक्ति',wiki:'Vaishno Devi Temple',hi:'12 किमी पैदल यात्रा से पहुँचने वाला माता का गुफा मंदिर; भारत के सबसे अधिक दर्शन वाले तीर्थों में।',special:'A cave shrine to the Mother Goddess reached by a 12-km trek.'},
  {n:'Mahakaleshwar, Ujjain',lat:23.1828,lng:75.7682,deity:'Shiva / शिव',wiki:'Mahakaleshwar Jyotirlinga',hi:'ज्योतिर्लिंग, प्रसिद्ध भस्म आरती — लिंग पर पवित्र भस्म चढ़ाई जाती है।',special:'A Jyotirlinga famous for the pre-dawn Bhasma Aarti.'},
  {n:'Siddhivinayak, Mumbai',lat:19.0169,lng:72.8305,deity:'Ganesha / गणेश',wiki:'Siddhivinayak Temple, Mumbai',hi:'मुंबई का सबसे प्रसिद्ध गणेश मंदिर, मंगलवार को भक्तों की लंबी कतारें।',special:'Mumbai most famous Ganesha temple, long queues on Tuesdays.'},
  {n:'Akshardham, Delhi',lat:28.6127,lng:77.2773,deity:'Swaminarayan / स्वामीनारायण',wiki:'Swaminarayan Akshardham (Delhi)',hi:'विश्व का सबसे बड़ा व्यापक हिंदू मंदिर (गिनीज़ रिकॉर्ड), अत्यंत सुंदर नक्काशी।',special:'World largest comprehensive Hindu temple, Guinness record holder.'},
  {n:'Meenakshi, Madurai',lat:9.9195,lng:78.1194,deity:'Parvati / पार्वती',wiki:'Meenakshi Temple',hi:'14 विशाल रंगीन गोपुरम और हज़ार स्तंभों का मंडप वाला प्रतिष्ठित द्रविड़ मंदिर।',special:'An iconic Dravidian temple with 14 colourful gopurams.'},
  {n:'Tirupati Balaji',lat:13.6833,lng:79.3474,deity:'Vishnu / विष्णु',wiki:'Venkateswara Temple, Tirumala',hi:'विश्व का सबसे अधिक दर्शन वाला और सबसे धनी मंदिर; भक्त बाल चढ़ाते हैं।',special:'The world most visited and richest temple.'},
  {n:'Jagannath, Puri',lat:19.8048,lng:85.818,deity:'Krishna / कृष्ण',wiki:'Jagannath Temple, Puri',hi:'चार धाम में से एक, विश्वप्रसिद्ध रथ यात्रा उत्सव का स्थान।',special:'One of the Char Dham, home of the Rath Yatra chariot festival.'},
  {n:'Konark Sun Temple',lat:19.8876,lng:86.0945,deity:'Surya / सूर्य',wiki:'Konark Sun Temple',hi:'यूनेस्को विश्व धरोहर — 13वीं सदी का सूर्य रथ आकार का विशाल पत्थर मंदिर।',special:'A UNESCO 13th-century temple shaped as a colossal stone chariot.'},
  {n:'Pashupatinath',lat:27.7104,lng:85.3487,deity:'Shiva / शिव',wiki:'Pashupatinath Temple',hi:'नेपाल का सबसे पवित्र मंदिर, बागमती नदी पर यूनेस्को स्थल — शिव के सबसे पवित्र स्थलों में।',special:'Nepal holiest temple, UNESCO site on the Bagmati river.'},
  {n:'Muktinath',lat:28.8167,lng:83.8719,deity:'Vishnu / विष्णु',wiki:'Muktinath',hi:'3,800 मीटर पर मुस्तांग में, हिंदू और बौद्ध दोनों के लिए पवित्र; 108 धाराओं में स्नान।',special:'At 3,800m in Mustang, sacred to Hindus and Buddhists alike.'},
  {n:'Janaki Mandir',lat:26.7288,lng:85.9241,deity:'Sita / सीता',wiki:'Janaki Mandir',hi:'माता सीता की जन्मभूमि पर भव्य संगमरमर मंदिर, राम-सीता विवाह स्थल।',special:'Grand marble temple at the believed birthplace of Sita.'},
  {n:'Manakamana',lat:27.8983,lng:84.6389,deity:'Bhagwati / भगवती',wiki:'Manakamana Temple',hi:'गोरखा की मनोकामना पूर्ण करने वाली देवी, केबल कार से पहुँच।',special:'The wish-fulfilling goddess of Gorkha, reached by cable car.'},
  {n:'Swayambhunath',lat:27.7149,lng:85.2903,deity:'Buddha-Hindu / बुद्ध-हिंदू',wiki:'Swayambhunath',hi:'काठमांडू के ऊपर यूनेस्को बंदर मंदिर स्तूप, बौद्ध और हिंदू दोनों पूजनीय।',special:'The UNESCO Monkey Temple stupa above Kathmandu.'},
  {n:'Budhanilkantha',lat:27.7788,lng:85.3625,deity:'Vishnu / विष्णु',wiki:'Budhanilkantha Temple',hi:'शेषनाग पर लेटे पाँच मीटर के विष्णु — नेपाल की सबसे बड़ी पत्थर प्रतिमा।',special:'Five-metre Vishnu reclining on serpents — Nepal largest stone statue.'},
  {n:'Bageshwori, Nepalgunj',lat:28.0526,lng:81.6206,deity:'Shakti / शक्ति',wiki:'Bageshwori Temple',hi:'नेपालगंज का सबसे महत्वपूर्ण मंदिर, S Hari के गंतव्य शहर की देवी बागेश्वरी।',special:'The most important temple of Nepalgunj, S Hari destination city.'},
  {n:'Lumbini',lat:27.4692,lng:83.2755,deity:'Buddha / बुद्ध',wiki:'Lumbini',hi:'गौतम बुद्ध की जन्मभूमि, यूनेस्को विश्व धरोहर तीर्थ स्थल।',special:'The birthplace of Gautam Buddha, UNESCO World Heritage site.'}
];


/* V7 — routed through the shared loadMapLibre() singleton so the engine
   is fetched once per session. Previously a caller arriving while a load
   was already in flight hit `if(SN.loading) return;` and its callback was
   dropped silently — the promise now resolves every waiting caller. */
function ensureMapLibre(cb){
  loadMapLibre()
    .then(function(){ SN.loading=false; cb(); })
    .catch(function(){ SN.loading=false; toast('Failed to load map engine'); });
}

function renderNav(){
  var loader=$('#snLoad');
  if(loader) loader.style.display='flex';
  if(typeof snLoadMapInit==='function') snLoadMapInit();
  if(SN.ready && SN.map){
    if(loader) loader.style.display='none';
    // [MAP PRO] Bug 3 — resize AFTER the view is laid out & painted (double rAF),
    // so the GL canvas never keeps a stale 0×0 / previous size when we re-enter nav.
    requestAnimationFrame(function(){ requestAnimationFrame(function(){ if(SN.map) SN.map.resize(); }); });
    return;
  }
  ensureMapLibre(function(){ initNavApp(); });
}
function cleanupNav(){
  if(SN.watchId!=null){ navigator.geolocation.clearWatch(SN.watchId); SN.watchId=null; }
  if(SN.meAnim!=null){ cancelAnimationFrame(SN.meAnim); SN.meAnim=null; }
  if(SN.busAnim!=null){ cancelAnimationFrame(SN.busAnim); SN.busAnim=null; }
  if(SN.trailAnim!=null){ cancelAnimationFrame(SN.trailAnim); SN.trailAnim=null; }
  if(SN.presetBusAnim!=null){ cancelAnimationFrame(SN.presetBusAnim); SN.presetBusAnim=null; }
  if(SN.presetBusMarker){ SN.presetBusMarker.remove(); SN.presetBusMarker=null; }
  if(SN.rerouteT!=null){ clearTimeout(SN.rerouteT); SN.rerouteT=null; }
  if(SN.trackUnsub){ SN.trackUnsub(); SN.trackUnsub=null; }
  if(SN.trackTickT!=null){ clearInterval(SN.trackTickT); SN.trackTickT=null; }
  /* shg-v59 — cancel the rotation lerps and route-draw sweep too; leaving them running
     while the nav view is off screen wasted rAF budget and could resume mid-tween on re-entry. */
  if(SN.meRotAnim!=null){ cancelAnimationFrame(SN.meRotAnim); SN.meRotAnim=null; }
  if(SN.busRotAnim!=null){ cancelAnimationFrame(SN.busRotAnim); SN.busRotAnim=null; }
  if(SN.routeDrawAnim!=null){ cancelAnimationFrame(SN.routeDrawAnim); SN.routeDrawAnim=null; }
  if(SN.motionAnim!=null){ cancelAnimationFrame(SN.motionAnim); SN.motionAnim=null; }
  if(SN.trackPollT!=null){ clearInterval(SN.trackPollT); SN.trackPollT=null; }
  SN.driverBroadcast=false; SN.persistFollow=false;
}

function initNavApp(){
  var STYLES={
    day:'https://tiles.openfreemap.org/styles/liberty',
    night:'https://tiles.openfreemap.org/styles/dark',
    sat:{version:8,glyphs:'https://tiles.openfreemap.org/fonts/{fontstack}/{range}.pbf',
      sources:{esri:{type:'raster',tileSize:256,
        tiles:['https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'],
        attribution:'Imagery © Esri'}},
      layers:[{id:'esri',type:'raster',source:'esri'}]},
    terrain:{version:8,glyphs:'https://tiles.openfreemap.org/fonts/{fontstack}/{range}.pbf',
      sources:{topo:{type:'raster',tileSize:256,
        tiles:['https://tile.opentopomap.org/{z}/{x}/{y}.png'],
        attribution:'© OpenTopoMap (CC-BY-SA)'}},
      layers:[{id:'topo',type:'raster',source:'topo'}]}
  };

  var s=function(q){ return document.querySelector('#view-nav '+q); };
  var snEsc=function(v){ return String(v==null?'':v).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]}); };
  var snToastT;
  function snToast(msg){ var el=s('.sn-toast'); if(!el) return; el.textContent=msg; el.classList.add('show');
    clearTimeout(snToastT); snToastT=setTimeout(function(){el.classList.remove('show');},2800); }

  /* ================================================================
     shg-v59 — Motion helpers (Google-Maps-style smoothness)
     Kept in the initNavApp() closure so the marker lerps see SN state
     without leaking helpers to the global scope. The ease-out curve is
     the same one MapLibre's own `easeTo` defaults to (a bezier close to
     cubic-out) so marker + camera glide in visual sync.
  ================================================================ */
  /* Cubic ease-out — decelerates as it approaches the target; used for BOTH
     marker interpolation and camera easeTo() so nothing "punches" past its stop. */
  function snEaseOut(t){ return 1 - Math.pow(1 - t, 3); }
  /* Shortest-arc between two bearings, always taken along the ≤180° side so a
     359°→1° swing rotates 2° forward, not 358° backward. */
  function snShortestArcDelta(from, to){
    var d = ((to - from) % 360 + 540) % 360 - 180;
    return d;   /* signed; add to `from` to reach `to` along the short path */
  }
  /* Smoothly rotate a marker to `to` degrees over `dur` ms. Cancels any prior
     tween on the marker so a rapid heading change doesn't fight itself. `slot`
     is the SN key that owns the frame id ('meRotAnim' or 'busRotAnim'). */
  function snRotateMarker(marker, to, dur, slot){
    if(!marker || to == null || isNaN(to)) return;
    var from = 0;
    try{ var el = marker.getElement(); var t = el && el.__snRot; if(typeof t === 'number') from = t; }catch(e){}
    var delta = snShortestArcDelta(from, to);
    /* Deltas below ~0.5° read as jitter — snap without spending frames on them. */
    if(Math.abs(delta) < 0.5){
      try{ marker.setRotation(to); marker.getElement().__snRot = to; }catch(e){}
      return;
    }
    if(SN[slot] != null) cancelAnimationFrame(SN[slot]);
    var t0 = performance.now();
    (function step(now){
      var k = Math.min(1, (now - t0) / dur), e = snEaseOut(k);
      var cur = from + delta * e;
      try{ marker.setRotation(cur); marker.getElement().__snRot = cur; }catch(e2){}
      if(k < 1) SN[slot] = requestAnimationFrame(step);
      else SN[slot] = null;
    })(t0);
  }
  /* Ease-out camera helper — every follow/fly call routes through this so all
     camera moves share ONE feel. MapLibre's own default easing is cubic; this
     just makes the choice explicit and lets us tune it in one place. */
  function snEase(opts){
    if(!opts) return;
    if(opts.easing == null) opts.easing = snEaseOut;
    if(opts.duration == null) opts.duration = 600;
    map.easeTo(opts);
  }

  /* [SECTION 1] Auto day/night — open in the premium night theme after 18:00 / before
     06:00 local time; the manual Day/Night/Sat/Terrain buttons still fully override. */
  try{ var _snH=new Date().getHours(); if((_snH>=18||_snH<6)&&SN.currentStyle==='day') SN.currentStyle='night'; }catch(e){}

  /* ---- Lite mode (5 Sep 2026) ----
     A low-end phone (<=2 GB RAM or <=2 cores), a data-saver / 2G-3G link, or a
     reduced-motion preference gets a lighter map: no tile cross-fade, no 3D
     buildings / terrain / glow layers, no temple markers, markers snap instead
     of glide. The Layers panel has a manual switch (remembered per device). */
  function snDetectLite(){
    try{ var o=localStorage.getItem('shari:navlite'); if(o==='1') return true; if(o==='0') return false; }catch(e){}
    try{
      var n=navigator, conn=n.connection||n.mozConnection||n.webkitConnection;
      if(n.deviceMemory!=null&&n.deviceMemory<=2) return true;
      if(n.hardwareConcurrency!=null&&n.hardwareConcurrency<=2) return true;
      if(conn&&(conn.saveData||/(^|[^0-9])(2g|3g)$/.test(String(conn.effectiveType||'')))) return true;
      if(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches) return true;
    }catch(e){}
    return false;
  }
  SN.lite=snDetectLite();
  var _nv=document.getElementById('view-nav'); if(_nv) _nv.classList.toggle('sn-lite',SN.lite);
  if(SN.lite){ SN.templesOn=false; try{ s('#snTemples').classList.remove('on'); var _lt=s('#snLyTemples'); if(_lt) _lt.checked=false; }catch(e){} }

  /* ---- No WebGL: a static route card instead of a spinner forever ---- */
  function snNoMapFallback(err){
    try{ console.warn('S Hari Nav: map unavailable', err); }catch(e){}
    var loader=$('#snLoad'); if(loader) loader.style.display='none';
    var box=document.getElementById('sn-map');
    if(box){
      var svg=''; try{ if(typeof routeOverviewSVG==='function') svg=routeOverviewSVG({from:'Surat',to:'Rupaidiha'}); }catch(e){}
      box.classList.add('sn-map-ready');
      box.innerHTML='<div class="sn-nomap">'+(svg||'')+'<div class="sn-nomap-txt"><b>Map view is not available on this device</b>'
        +'<p>This phone / browser cannot draw the live map (no WebGL). The route overview is shown instead; bus timings and booking work as usual.</p>'
        +'<button type="button" class="sn-nomap-btn" onclick="location.hash=\'#/\'">Back to home</button></div></div>';
    }
    try{ var bk=$('#snBack'); if(bk) bk.onclick=function(){ location.hash='#/'; }; }catch(e){}
    if(_nv) _nv.classList.add('sn-nomap-on');
    SN.ready=false; SN.map=null;
  }

  var map;
  try{
    if(typeof maplibregl==='undefined'||(typeof maplibregl.supported==='function'&&!maplibregl.supported())) throw new Error('webgl-unsupported');
    map=new maplibregl.Map({
      container:'sn-map', style:STYLES[SN.currentStyle]||STYLES.day, center:[76.8,26.6], zoom:4.7, pitch:0, bearing:0,
      attributionControl:{compact:true},
      /* shg-v59 — MapLibre already defaults to inertia on drag, smooth wheel-zoom,
         and rotate on two-finger; naming them here documents the intent so a future
         refactor doesn't silently flip one off. fadeDuration controls the tile
         cross-fade (item #5 — tiles fade in instead of popping). maxPitch bump
         lets 3D buildings tilt to a nicer camera. Lite: no cross-fade, flatter
         camera, no antialiasing. */
      dragPan:true, scrollZoom:true, doubleClickZoom:true, touchZoomRotate:true,
      fadeDuration:SN.lite?0:250, maxPitch:SN.lite?45:70, antialias:!SN.lite,
      /* 4 Sep 2026 — this is an India + Nepal service, so the map is fenced to
         that part of the world (Arabian Sea to Myanmar, Sri Lanka to the high
         Himalaya). Nobody can drag it off to the Atlantic on a 3G phone and
         pull tiles that never matter; zoom floor keeps the corridor readable. */
      maxBounds:[[61.0,4.5],[99.5,38.5]], minZoom:3.8
    });
  }catch(err){ snNoMapFallback(err); return; }
  map.addControl(new maplibregl.ScaleControl({maxWidth:96,unit:'metric'}),'bottom-left');
  SN.map=map;
  /* A GL context can also die AFTER construction (tab discarded, driver reset). */
  try{ map.on('error',function(e){ var m=e&&e.error&&e.error.message||''; if(/webgl|context/i.test(m)&&!SN._glLost){ SN._glLost=true; snToast('Map engine lost — reload the page'); } }); }catch(e){}

  function firstVS(){ var ss=map.getStyle().sources||{}; for(var id in ss){ if(ss[id].type==='vector') return id; } return null; }
  function circPoly(lng,lat,r,pts){
    pts=pts||48; var coords=[],dLat=(r/1000)/110.574,dLng=(r/1000)/(111.320*Math.cos(lat*Math.PI/180));
    for(var i=0;i<=pts;i++){ var t=2*Math.PI*i/pts; coords.push([lng+dLng*Math.cos(t),lat+dLat*Math.sin(t)]); }
    return{type:'Feature',geometry:{type:'Polygon',coordinates:[coords]}};
  }
  function decodePoly(str){
    var idx=0,lat=0,lng=0,coords=[],f=1e6,b,sh,res;
    while(idx<str.length){ sh=0;res=0;
      do{b=str.charCodeAt(idx++)-63;res|=(b&0x1f)<<sh;sh+=5;}while(b>=0x20);
      lat+=(res&1)?~(res>>1):(res>>1); sh=0;res=0;
      do{b=str.charCodeAt(idx++)-63;res|=(b&0x1f)<<sh;sh+=5;}while(b>=0x20);
      lng+=(res&1)?~(res>>1):(res>>1); coords.push([lng/f,lat/f]); }
    return coords;
  }
  /* ---- route-progress helpers — road snapping, traveled/ahead split, turn banner.
     Everything here is derived from the polyline + maneuvers Valhalla already returns
     (SN.routeCoords / SN.routeCumKm / SN.routeManeuvers, set in snRoute()) — no new API. */
  function buildCumDist(coords){
    var cum=[0];
    for(var i=1;i<coords.length;i++) cum.push(cum[i-1]+haversineKm(coords[i-1][1],coords[i-1][0],coords[i][1],coords[i][0]));
    return cum;
  }
  function snapToRoute(lng,lat){
    var coords=SN.routeCoords; if(!coords||coords.length<2) return null;
    var bestIdx=-1,bestPx=lng,bestPy=lat,bestD=Infinity;
    for(var i=0;i<coords.length-1;i++){
      var ax=coords[i][0],ay=coords[i][1],bx=coords[i+1][0],by=coords[i+1][1];
      var dx=bx-ax,dy=by-ay,len2=dx*dx+dy*dy;
      var tt=len2>0?((lng-ax)*dx+(lat-ay)*dy)/len2:0; tt=Math.max(0,Math.min(1,tt));
      var px=ax+dx*tt,py=ay+dy*tt;
      var dKm=haversineKm(lat,lng,py,px);
      if(dKm<bestD){ bestD=dKm; bestIdx=i; bestPx=px; bestPy=py; }
    }
    if(bestIdx<0) return null;
    var distAlongKm=SN.routeCumKm[bestIdx]+haversineKm(coords[bestIdx][1],coords[bestIdx][0],bestPy,bestPx);
    return {lng:bestPx,lat:bestPy,idx:bestIdx,offKm:bestD,distAlongKm:distAlongKm};
  }
  function sliceRouteAt(distAlongKm){
    var coords=SN.routeCoords,cum=SN.routeCumKm,done=[],ahead=[],i;
    if(!coords||!cum) return{done:done,ahead:ahead};
    for(i=0;i<coords.length;i++){ if(cum[i]<=distAlongKm) done.push(coords[i]); else break; }
    if(i>0&&i<coords.length){
      var frac=(distAlongKm-cum[i-1])/Math.max(1e-6,cum[i]-cum[i-1]);
      var split=[coords[i-1][0]+(coords[i][0]-coords[i-1][0])*frac,coords[i-1][1]+(coords[i][1]-coords[i-1][1])*frac];
      done.push(split); ahead=[split].concat(coords.slice(i));
    } else ahead=coords.slice(i);
    return{done:done,ahead:ahead};
  }
  function updateTraveledRoute(distAlongKm){
    if(!SN.routeCoords) return;
    var sl=sliceRouteAt(Math.max(0,distAlongKm||0));
    var doneGeo={type:'Feature',geometry:{type:'LineString',coordinates:sl.done.length>1?sl.done:[]}};
    var aheadGeo={type:'Feature',geometry:{type:'LineString',coordinates:sl.ahead.length>1?sl.ahead:sl.ahead.concat(sl.ahead)}};
    if(!map.getSource('sn-route-done')){
      /* shg-v59 — lineMetrics required for the "route draws itself" sweep (item #7).
         Cheap flag, no visual impact until a gradient/dasharray reads line-progress. */
      map.addSource('sn-route-done',{type:'geojson',data:doneGeo,lineMetrics:true});
      map.addLayer({id:'sn-rdone',type:'line',source:'sn-route-done',layout:{'line-cap':'round','line-join':'round'},
        paint:{'line-color':'#8a94a6','line-width':5,'line-opacity':.6}});
    } else map.getSource('sn-route-done').setData(doneGeo);
    if(!map.getSource('sn-route-ahead')){
      map.addSource('sn-route-ahead',{type:'geojson',data:aheadGeo,lineMetrics:true});
      map.addLayer({id:'sn-rglow',type:'line',source:'sn-route-ahead',layout:{'line-cap':'round','line-join':'round'},
        paint:{'line-color':'#FF6B00','line-width':11,'line-opacity':.25,'line-blur':3}});
      map.addLayer({id:'sn-rline',type:'line',source:'sn-route-ahead',layout:{'line-cap':'round','line-join':'round'},
        paint:{'line-color':'#FF7A1A','line-width':6}});
    } else map.getSource('sn-route-ahead').setData(aheadGeo);
  }
  /* shg-v59 — animate the just-loaded route "drawing itself" from origin to destination
     using a line-gradient stop that sweeps 0→1 (item #7). Also fades the glow in from
     transparent so the whole route appears rather than pops. Respects reduced motion. */
  function animateRouteDraw(){
    if(!map.getLayer('sn-rline') || !map.getLayer('sn-rglow')) return;
    var reduce = SN.lite || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    if(reduce){
      try{ map.setPaintProperty('sn-rline','line-gradient',null); map.setPaintProperty('sn-rglow','line-opacity',0.25);}catch(e){}
      return;
    }
    if(SN.routeDrawAnim != null) cancelAnimationFrame(SN.routeDrawAnim);
    var t0 = performance.now(), dur = 1100;
    /* line-gradient needs an interpolation on the source's `line-progress`. We drive
       the stop at `p` from 0 to 1: everything before p is the warm orange, everything
       after is fully transparent — the visible tip races along the polyline. */
    (function frame(now){
      var k = Math.min(1, (now - t0) / dur), e = snEaseOut(k);
      try{
        map.setPaintProperty('sn-rline','line-gradient',[
          'interpolate',['linear'],['line-progress'],
          0, '#FF7A1A',
          Math.max(0.0001, Math.min(0.9999, e)), '#FF7A1A',
          Math.min(1, e + 0.0001), 'rgba(255,122,26,0)',
          1, 'rgba(255,122,26,0)'
        ]);
        map.setPaintProperty('sn-rglow','line-opacity', 0.05 + 0.20 * e);
      }catch(err){}
      if(k < 1) SN.routeDrawAnim = requestAnimationFrame(frame);
      else { SN.routeDrawAnim = null;
        /* clear the gradient so subsequent progress splits use the plain solid color */
        try{ map.setPaintProperty('sn-rline','line-gradient',null); }catch(err){}
      }
    })(t0);
  }
  var SN_VLANG={en:'en-IN',hi:'hi-IN',ne:'ne-NP'};
  /* guarded voice match — returns null (fall back to u.lang string) if none/empty */
  function snPickVoice(lang){
    try{ if(!window.speechSynthesis||!window.speechSynthesis.getVoices) return null;
      var vs=window.speechSynthesis.getVoices()||[], pre=(lang||'').split('-')[0].toLowerCase();
      for(var i=0;i<vs.length;i++){ if((vs[i].lang||'')===lang) return vs[i]; }
      for(var j=0;j<vs.length;j++){ if((vs[j].lang||'').toLowerCase().indexOf(pre)===0) return vs[j]; }
    }catch(e){}
    return null;
  }
  /* coarse maneuver direction: Valhalla maneuver type first, instruction text as fallback */
  function snCoarseDir(type,instr){
    var t=+type;
    if(t===1||t===2||t===3) return 'start';
    if(t===4||t===5||t===6) return 'arrive';
    if(t===12||t===13) return 'uturn';
    if(t===26||t===27) return 'roundabout';
    if(t===25) return 'merge';
    if(t===28||t===29) return 'ferry';
    if(t===9||t===10||t===11||t===18||t===20||t===23) return 'right';
    if(t===14||t===15||t===16||t===19||t===21||t===24) return 'left';
    if(t===7||t===8||t===17||t===22) return 'continue';
    var x=(instr||'').toLowerCase();
    if(x.indexOf('arriv')>=0||x.indexOf('destination')>=0) return 'arrive';
    if(x.indexOf('u-turn')>=0||x.indexOf('uturn')>=0) return 'uturn';
    if(x.indexOf('roundabout')>=0) return 'roundabout';
    if(x.indexOf('left')>=0) return 'left';
    if(x.indexOf('right')>=0) return 'right';
    return 'continue';
  }
  /* pragmatic core-phrase lookup — only the few common maneuvers, en is native Valhalla */
  var SN_PHRASE={
    start:     {hi:'यात्रा शुरू करें',          ne:'यात्रा सुरु गर्नुहोस्'},
    continue:  {hi:'सीधे चलते रहें',            ne:'सिधा अगाडि बढ्नुहोस्'},
    right:     {hi:'दाएँ मुड़ें',               ne:'दायाँ मोड्नुहोस्'},
    left:      {hi:'बाएँ मुड़ें',               ne:'बायाँ मोड्नुहोस्'},
    uturn:     {hi:'यू-टर्न लें',               ne:'यू-टर्न लिनुहोस्'},
    roundabout:{hi:'गोल चक्कर में प्रवेश करें', ne:'गोलचक्करमा प्रवेश गर्नुहोस्'},
    merge:     {hi:'मुख्य मार्ग में मिलें',      ne:'मुख्य मार्गमा मिसिनुहोस्'},
    ferry:     {hi:'नौका लें',                  ne:'फेरी लिनुहोस्'},
    arrive:    {hi:'आप पहुँच गए हैं',           ne:'तपाईं गन्तव्यमा आइपुग्नुभयो'}
  };
  /* Valhalla instructions arrive in English; for hi/ne swap in a localized template by coarse direction */
  function snSpeakManeuver(instr,type){
    var lg=SN.voiceLang||'en';
    if(lg==='en') return instr;
    var row=SN_PHRASE[snCoarseDir(type,instr)];
    return (row&&row[lg])||instr;
  }
  function speak(text){
    if(!SN.voiceOn||!text||!window.speechSynthesis) return;
    try{ window.speechSynthesis.cancel();
      var lg=SN_VLANG[SN.voiceLang]||'en-IN';
      var u=new SpeechSynthesisUtterance(text); u.rate=1; u.lang=lg;
      var vc=snPickVoice(lg); if(vc) u.voice=vc;
      window.speechSynthesis.speak(u);
    }catch(e){}
  }
  function updateManeuverBanner(distAlongKm){
    var mv=SN.routeManeuvers; if(!mv||!mv.length){ s('#snBanner').classList.remove('show'); return; }
    var i=0; while(i<mv.length-1&&mv[i+1]._distKm<=distAlongKm) i++;
    var upcoming=i<mv.length-1, show=upcoming?mv[i+1]:mv[i];
    var remainKm=upcoming?Math.max(0,show._distKm-distAlongKm):Math.max(0,SN.routeTotalKm-distAlongKm);
    var v=snSpeedKmh();
    s('#snBanIcon').textContent=snManIcon(show.type);
    s('#snBanInstr').textContent=show.instruction||(upcoming?'Continue':'You have arrived');
    s('#snBanDist').textContent=(remainKm<1?Math.round(remainKm*1000)+' m':remainKm.toFixed(1)+' km')
      +(upcoming?' · '+snFmtTime(remainKm/v*3600):'');
    var totalRemainKm=Math.max(0,SN.routeTotalKm-distAlongKm), remainSec=totalRemainKm/snSpeedKmh()*3600;
    var _arr=new Date(Date.now()+remainSec*1000), _h=_arr.getHours(), _ap=_h<12?'AM':'PM', _h12=_h%12||12, _mm=_arr.getMinutes();
    s('#snBanRem').textContent=snFmtTime(remainSec)+' left';
    s('#snBanEta').textContent=_h12+':'+(_mm<10?'0'+_mm:_mm)+' '+_ap;
    s('#snBanner').classList.add('show');
    if(show.instruction&&show.instruction!==SN.lastSpokenInstr){ speak(snSpeakManeuver(show.instruction,show.type)); SN.lastSpokenInstr=show.instruction; }
  }
  function updateNavCard(distAlongKm){
    var card=s('#snNavCard'); if(!card) return;
    if(!SN.routeCoords||!SN.routeManeuvers||!SN.routeManeuvers.length){card.classList.remove('show');return;}
    var remainKm=Math.max(0,SN.routeTotalKm-distAlongKm);
    var v=snSpeedKmh();
    s('#snNcEta').textContent=snFmtTime(remainKm/v*3600);
    s('#snNcDist').textContent=remainKm<1?Math.round(remainKm*1000)+' m':remainKm.toFixed(1)+' km';
    var mv=SN.routeManeuvers, roadName='';
    for(var i=mv.length-1;i>=0;i--){ if(mv[i]._distKm<=distAlongKm&&mv[i].street_names&&mv[i].street_names.length){ roadName=mv[i].street_names.join(' / '); break; } }
    s('#snNcRoad').textContent=roadName||'—';
    var nextIdx=-1;
    for(var j=0;j<mv.length;j++){if(mv[j]._distKm>distAlongKm){nextIdx=j;break;}}
    if(nextIdx>=0){
      s('#snNcIco').textContent=snManIcon(mv[nextIdx].type);
      s('#snNcTxt').textContent=mv[nextIdx].instruction||'Continue';
      var nd=Math.max(0,mv[nextIdx]._distKm-distAlongKm);
      s('#snNcDist2').textContent=nd<1?Math.round(nd*1000)+' m':nd.toFixed(1)+' km';
    }
    card.classList.add('show');
  }
  /* Styling-only road-hierarchy tuning on top of OpenFreeMap's own hosted
     liberty/dark styles — verified against their actual layer IDs (fetched and
     inspected directly), not guessed. No tile source swap, no new style JSON
     shipped — just setPaintProperty nudges after their style finishes loading,
     each wrapped so a future OpenFreeMap style update can't break anything here. */
  function tuneRoadStyle(){
    function setP(id,prop,val){ try{ if(map.getLayer(id)) map.setPaintProperty(id,prop,val); }catch(e){} }
    if(SN.currentStyle==='day'){
      /* liberty: road_trunk_primary and road_secondary_tertiary share the exact
         same #fea color — a highway and a back street look identical at a glance. */
      setP('road_trunk_primary','line-color','#ffc266');
      setP('road_trunk_primary_casing','line-color','#e08a3e');
      /* SECTION 2 glow: widen + blur the trunk/motorway casing (it sits UNDER the
         road fill, so only a warm halo bleeds out = soft Apple/Tesla glow). Zoom-
         scaled so it stays hairline at low zoom and only blooms when zoomed in. */
      setP('road_trunk_primary_casing','line-width',['interpolate',['linear'],['zoom'],6,2.4,10,5.5,14,12,18,22]);
      setP('road_trunk_primary_casing','line-blur',['interpolate',['linear'],['zoom'],6,0.4,14,2.4,18,4.5]);
      setP('highway_motorway_casing','line-color','rgba(255,150,55,0.6)');
      setP('highway_motorway_casing','line-width',['interpolate',['linear'],['zoom'],6,3,10,7,14,14,18,26]);
      setP('highway_motorway_casing','line-blur',['interpolate',['linear'],['zoom'],6,0.5,14,2.8,18,5]);
      /* hierarchy: cool + thin the back streets so warm highways clearly outrank them */
      setP('road_secondary_tertiary','line-color','#dfe3ea');
      setP('road_secondary_tertiary','line-width',['interpolate',['linear'],['zoom'],10,0.8,14,2.2,18,5]);
    } else if(SN.currentStyle==='night'){
      /* dark: road "inner" fill goes near-black on a near-black background,
         readable only via a faint grey casing — brighten/warm the casings. */
      setP('highway_motorway_casing','line-color','rgba(255,167,38,0.55)');
      setP('highway_major_casing','line-color','rgba(150,150,150,0.85)');
      /* SECTION 2 glow (night): widen + blur the casings so motorways/trunks emit a
         warm halo on the dark base; slightly stronger blur than day to read at night,
         still zoom-scaled so it never overpowers or costs fps at low zoom. */
      setP('highway_motorway_casing','line-width',['interpolate',['linear'],['zoom'],6,3,10,7,14,14,18,26]);
      setP('highway_motorway_casing','line-blur',['interpolate',['linear'],['zoom'],6,0.6,14,3,18,5.5]);
      setP('highway_major_casing','line-width',['interpolate',['linear'],['zoom'],6,1.4,10,3,14,6,18,11]);
      setP('highway_major_casing','line-blur',['interpolate',['linear'],['zoom'],6,0.2,14,1,18,2]);
      /* hierarchy: cool-grey + thin minor roads so the warm highway glow stands out */
      setP('road_secondary_tertiary','line-color','rgba(150,162,186,0.7)');
      setP('road_secondary_tertiary','line-width',['interpolate',['linear'],['zoom'],10,0.6,14,1.7,18,3.8]);
    }
  }
  /* ---- 3D terrain (5 Sep 2026) ----
     Real elevation from the open Terrarium DEM tiles (AWS/Mapzen, keyless) —
     hillshade on every style plus map.setTerrain() while 3D is on. Falls back
     silently (flat map + toast) on anything that cannot do it. Skipped in lite. */
  var SN_DEM={type:'raster-dem',tiles:['https://s3.amazonaws.com/elevation-tiles-prod/terrarium/{z}/{x}/{y}.png'],
    encoding:'terrarium',tileSize:256,maxzoom:15,attribution:'Terrain: Mapzen / AWS Open Data'};
  function snApplyTerrain(){
    if(!SN.is3D||SN.lite||typeof map.setTerrain!=='function'){
      if(SN.terrainOn){ try{ map.setTerrain(null); if(map.getLayer('sn-hill')) map.removeLayer('sn-hill'); }catch(e){} SN.terrainOn=false; }
      return;
    }
    try{
      if(!map.getSource('sn-dem')) map.addSource('sn-dem',SN_DEM);
      if(!map.getLayer('sn-hill')){
        map.addLayer({id:'sn-hill',type:'hillshade',source:'sn-dem',
          paint:{'hillshade-exaggeration':0.45,'hillshade-shadow-color':'#2b2338','hillshade-highlight-color':'#fff5e6','hillshade-accent-color':'#5b4636'}},
          map.getLayer('sn-rglow')?'sn-rglow':undefined);
      }
      map.setTerrain({source:'sn-dem',exaggeration:1.35});
      if(typeof map.setSky==='function'){ try{ map.setSky({'sky-color':'#8fc4ff','horizon-color':'#f7e2c6','fog-color':'#e9e3d8','sky-horizon-blend':0.6,'horizon-fog-blend':0.8,'fog-ground-blend':0.6}); }catch(e){} }
      if(!SN.terrainOn) snToast('🏔️ 3D terrain on');
      SN.terrainOn=true;
    }catch(e){ SN.terrainOn=false; snToast('3D terrain is not available on this device'); }
  }

  function applyOL(){
    tuneRoadStyle();
    if(SN.is3D&&!SN.lite){ var src=firstVS();
      if(src&&!map.getLayer('sn-bld3d')){
        try{ map.addLayer({id:'sn-bld3d',source:src,'source-layer':'building',type:'fill-extrusion',minzoom:13,
          paint:{'fill-extrusion-color':['interpolate',['linear'],['coalesce',['get','render_height'],['get','height'],8],0,'#26203f',12,'#312a52',40,'#463c6d',120,'#5c5088'],
            'fill-extrusion-height':['coalesce',['get','render_height'],['get','height'],8],
            'fill-extrusion-base':['coalesce',['get','render_min_height'],['get','min_height'],0],
            'fill-extrusion-vertical-gradient':true,
            'fill-extrusion-opacity':.7}}); }catch(e){} } }
    snApplyTerrain();
    snBusTrailEnsure();
    /* key transport-city label priority — guarded, light (6 symbols, no continuous work) */
    try{ var pv=firstVS();
      if(pv&&!SN.lite&&map.getSource(pv)&&!map.getLayer('sn-keycity')){
        map.addLayer({id:'sn-keycity',source:pv,'source-layer':'place',type:'symbol',minzoom:5,
          filter:['in',['coalesce',['get','name:en'],['get','name:latin'],['get','name'],''],['literal',['Ahmedabad','Mehsana','Rupaidiha','Nepalgunj','Butwal','Kathmandu']]],
          layout:{'text-field':['coalesce',['get','name:en'],['get','name:latin'],['get','name']],
            'text-font':['Noto Sans Bold'],'text-max-width':7,'text-allow-overlap':true,'text-ignore-placement':true,
            'text-size':['interpolate',['linear'],['zoom'],5,12.5,8,16,12,20],'symbol-sort-key':0},
          paint:{'text-color':'#FFF7ED','text-halo-color':'#E85D04','text-halo-width':2.3,'text-halo-blur':.5}});
      } }catch(e){}
    if(SN.routeCoords) updateTraveledRoute(SN.routeProgKm||0);
    if(SN.gpsPos){
      var ring=circPoly(SN.gpsPos.lng,SN.gpsPos.lat,SN.gpsPos.acc||30);
      if(!map.getSource('sn-acc')){
        map.addSource('sn-acc',{type:'geojson',data:ring});
        map.addLayer({id:'sn-accfill',type:'fill',source:'sn-acc',
          paint:{'fill-color':'#4285F4','fill-opacity':.14}},map.getLayer('sn-rglow')?'sn-rglow':undefined);
      } else map.getSource('sn-acc').setData(ring); }
    /* Sanatan Mode — faint saffron dashed pilgrimage thread linking the major dhams,
       visible only zoomed-out and only while temples are on; fully guarded, no rAF */
    try{
      if(!SN.lite&&!map.getSource('sn-pilgrim')){
        var snPK=['Somnath','Dwarkadhish','Kashi Vishwanath','Ram Mandir, Ayodhya','Kedarnath','Badrinath','Jagannath, Puri','Tirupati Balaji','Pashupatinath','Vaishno Devi'];
        var snBy={}; SN_TEMPLES.forEach(function(t){ snBy[t.n]=t; });
        var snCo=snPK.map(function(n){ var t=snBy[n]; return t?[t.lng,t.lat]:null; }).filter(Boolean);
        if(snCo.length>1){
          map.addSource('sn-pilgrim',{type:'geojson',data:{type:'Feature',geometry:{type:'LineString',coordinates:snCo}}});
          map.addLayer({id:'sn-pilgrimline',type:'line',source:'sn-pilgrim',
            layout:{'line-cap':'round','line-join':'round','visibility':SN.templesOn?'visible':'none'},
            paint:{'line-color':'#FF9A3D','line-width':1.6,'line-dasharray':[2,3],
              'line-opacity':['interpolate',['linear'],['zoom'],4,0.5,7,0.28,9,0]}},
            map.getLayer('sn-rglow')?'sn-rglow':undefined);
        }
      }
    }catch(e){}
  }
  map.on('style.load',applyOL);

  /* style switch */
  document.querySelectorAll('#view-nav [data-snstyle]').forEach(function(b){
    b.onclick=function(){
      var st=b.getAttribute('data-snstyle'); if(st===SN.currentStyle) return;
      document.querySelectorAll('#view-nav [data-snstyle]').forEach(function(x){x.classList.remove('on');});
      b.classList.add('on'); SN.currentStyle=st; map.setStyle(STYLES[st]);
    };
  });
  /* [SECTION 1] reflect the auto-picked day/night choice on the style bar */
  try{ document.querySelectorAll('#view-nav [data-snstyle]').forEach(function(x){ x.classList.toggle('on', x.getAttribute('data-snstyle')===SN.currentStyle); }); }catch(e){}

  /* v4.0 B7 — keep day/night in step while the map stays open. The boot-time
     pick above only ran once; a driver who opened the navigator at 17:30 was
     still on the day style at 21:00. Only flips between the two auto styles —
     a manual choice of 'topo' (or any explicit tap) is left alone until the
     clock next crosses a boundary. */
  SN._autoStyleTimer = setInterval(function(){
    try{
      var h = new Date().getHours();
      var want = (h >= 18 || h < 6) ? 'night' : 'day';
      if (SN.currentStyle !== want && (SN.currentStyle === 'day' || SN.currentStyle === 'night')) {
        SN.currentStyle = want; map.setStyle(STYLES[want]);
        document.querySelectorAll('#view-nav [data-snstyle]').forEach(function(x){ x.classList.toggle('on', x.getAttribute('data-snstyle') === want); });
      }
    }catch(e){}
  }, 10 * 60 * 1000);

  /* 3D toggle — pitch + buildings + real terrain (falls back to pitch only) */
  s('#sn3D').onclick=function(){
    SN.is3D=!SN.is3D; s('#sn3D').classList.toggle('on',SN.is3D);
    s('#sn3D').querySelector('small').textContent=SN.is3D?'3D':'2D';
    snEase({pitch:SN.is3D?(SN.lite?40:60):0,duration:SN.lite?300:800});
    if(SN.is3D) applyOL(); else { if(map.getLayer('sn-bld3d')) map.removeLayer('sn-bld3d'); snApplyTerrain(); }
  };

  /* compass + zoom */
  map.on('rotate',function(e){ s('#snCompass .sn-needle').style.transform='rotate('+ -map.getBearing()+'deg)'; if(e.originalEvent) SN.userRotated=true; });
  s('#snCompass').onclick=function(){
    SN.compassMode=!SN.compassMode; SN.userRotated=false;
    s('#snCompass').classList.toggle('on',SN.compassMode);
    if(!SN.compassMode) map.easeTo({bearing:0,pitch:SN.is3D?60:0,duration:500});
    snToast(SN.compassMode?'🧭 Compass mode':'🧭 North-up mode');
  };
  s('#snZin').onclick=function(){ map.zoomIn({duration:SN.lite?0:380,easing:snEaseOut}); };
  s('#snZout').onclick=function(){ map.zoomOut({duration:SN.lite?0:380,easing:snEaseOut}); };
  /* A pinch / wheel zoom pauses the follow camera for its duration so the
     motion engine never fights the finger; panning (dragstart) ends follow. */
  map.on('zoomstart',function(e){ if(e&&e.originalEvent) SN.camLock=true; });
  map.on('zoomend',function(){ SN.camLock=false; });
  map.on('rotatestart',function(e){ if(e&&e.originalEvent) SN.camLock=true; });
  map.on('rotateend',function(){ SN.camLock=false; });

  /* [MAP PRO] Layers panel — open/close + wire each toggle to its real control.
     Satellite lives in the style bar (already present). Live traffic is a graceful
     placeholder (toast) until a live speed provider is configured. */
  (function(){
    var btn=s('#snLayers'), panel=s('#snLayersPanel');
    if(btn&&panel){
      btn.setAttribute('aria-label','Map layers');
      btn.onclick=function(e){ e.stopPropagation(); var on=panel.classList.toggle('show'); btn.classList.toggle('on',on); };
      document.addEventListener('click',function(e){
        if(panel.classList.contains('show') && !e.target.closest('#snLayersPanel') && !e.target.closest('#snLayers')){
          panel.classList.remove('show'); btn.classList.remove('on');
        }
      });
    }
    var tmp=s('#snLyTemples'); if(tmp) tmp.onchange=function(){ s('#snTemples').click(); tmp.checked=s('#snTemples').classList.contains('on'); };
    var d3=s('#snLy3D'); if(d3) d3.onchange=function(){ s('#sn3D').click(); d3.checked=!!SN.is3D; };
    var lt=s('#snLyLite'); if(lt){ lt.checked=!!SN.lite; lt.onchange=function(){
      try{ localStorage.setItem('shari:navlite', lt.checked?'1':'0'); }catch(e){}
      snToast(lt.checked?'⚡ Lite mode on — reloading map…':'✨ Full mode — reloading map…');
      setTimeout(function(){ location.reload(); },700);
    }; }
    var stp=s('#snLyStops'); if(stp) stp.onchange=function(){ SN.presetMarkers.forEach(function(m){ var el=m.getElement(); if(el) el.style.display=stp.checked?'':'none'; }); snToast(stp.checked?'🚏 Bus stops shown':'Bus stops hidden'); };
    var poi=s('#snLyPoi'); if(poi) poi.onchange=function(){
      if(!SN.poiMarkers||!SN.poiMarkers.length){ snToast('📍 Tap a category below (Food, ATM, Hospital…) to drop nearby places'); poi.checked=false; return; }
      SN.poiMarkers.forEach(function(m){ var el=m.getElement(); if(el) el.style.display=poi.checked?'':'none'; });
      snToast(poi.checked?'📍 Nearby places shown':'📍 Nearby places hidden');
    };
  })();

  /* [MAP PRO] Share button — Web Share API with clipboard fallback */
  (function(){
    var btn=s('#snShare');
    if(!btn) return;
    btn.onclick=function(){
      var center=map.getCenter(), z=Math.round(map.getZoom());
      var label='S Hari Nav';
      var text='';
      if(SN.dest) { label=SN.dest.label||'Destination'; text='📍 '+label+'\n'; }
      else if(SN.gpsPos) { label='My location'; text='📍 My location\n'; }
      var url='https://www.google.com/maps/@'+center.lat.toFixed(5)+','+center.lng.toFixed(5)+','+z+'z';
      if(SN.dest) url='https://www.google.com/maps/search/?api=1&query='+SN.dest.lat.toFixed(5)+','+SN.dest.lng.toFixed(5);
      text+=url;
      if(navigator.share){
        navigator.share({title:label,text:text}).catch(function(){});
      } else {
        navigator.clipboard.writeText(text).then(function(){ snToast('📋 Location copied'); })
          .catch(function(){ snToast('Could not share'); });
      }
    };
  })();

  /* [MAP PRO] Explore POI chips — query Overpass API for nearby amenities */
  SN.poiMarkers=[];
  SN.poiActive=null;
  (function(){
    var POI_MAP={
      fuel:{q:'amenity=fuel',icon:'⛽',cat:'Fuel Station'},
      food:{q:'amenity~"restaurant|fast_food|cafe"',icon:'🍽️',cat:'Restaurant / Cafe'},
      hotel:{q:'tourism~"hotel|guest_house|hostel"',icon:'🏨',cat:'Hotel / Lodge'},
      atm:{q:'amenity~"atm|bank"',icon:'🏦',cat:'ATM / Bank'},
      hospital:{q:'amenity~"hospital|clinic|doctors"',icon:'🏥',cat:'Hospital / Clinic'},
      bus_stop:{q:'highway=bus_stop',icon:'🚏',cat:'Bus Stop'},
      parking:{q:'amenity=parking',icon:'🅿️',cat:'Parking'},
      pharmacy:{q:'amenity=pharmacy',icon:'💊',cat:'Pharmacy'}
    };
    function clearPoi(){
      SN.poiMarkers.forEach(function(m){m.remove();});
      SN.poiMarkers=[];
      document.querySelectorAll('#view-nav .sn-chip').forEach(function(c){c.classList.remove('on');});
      SN.poiActive=null;
      var _ply=s('#snLyPoi'); if(_ply) _ply.checked=false;  /* keep the Layers toggle in sync */
    }
    function fetchPoi(key){
      var def=POI_MAP[key]; if(!def) return;
      var center=SN.gpsPos?{lat:SN.gpsPos.lat,lng:SN.gpsPos.lng}:map.getCenter();
      var r=5000;
      var tag=def.q;
      var query='[out:json][timeout:10];('
        +'node['+tag+'](around:'+r+','+center.lat+','+center.lng+');'
        +'way['+tag+'](around:'+r+','+center.lat+','+center.lng+');'
        +');out center body 25;';
      var chip=document.querySelector('#view-nav .sn-chip[data-poi="'+key+'"]');
      if(chip) chip.classList.add('loading');
      fetch('https://overpass-api.de/api/interpreter',{method:'POST',body:'data='+encodeURIComponent(query)})
        .then(function(r){return r.json();})
        .then(function(j){
          if(!j.elements||!j.elements.length){ snToast('No '+def.cat+' found within 5 km'); return; }
          snToast(j.elements.length+' '+def.cat+' found nearby');
          j.elements.forEach(function(el){
            var lat=el.lat||(el.center&&el.center.lat);
            var lng=el.lon||(el.center&&el.center.lon);
            if(!lat||!lng) return;
            var name=(el.tags&&el.tags.name)||def.cat;
            var addr=(el.tags&&(el.tags['addr:street']||el.tags['addr:full']||''))||'';
            var markerEl=document.createElement('div');
            markerEl.className='sn-poi-m';
            markerEl.textContent=def.icon;
            var popup=new maplibregl.Popup({offset:14,maxWidth:'220px'}).setHTML(
              '<div class="sn-poi-card"><span class="sn-pc-cat">'+def.icon+' '+snEsc(def.cat)+'</span>'
              +'<h4>'+snEsc(name)+'</h4>'
              +(addr?'<p>'+snEsc(addr)+'</p>':'')
              +'<button class="sn-pc-nav" onclick="snPoiNav('+lng+','+lat+',\''+snEsc(name).replace(/'/g,"\\'")+'\')">🧭 Navigate here</button></div>'
            );
            var m=new maplibregl.Marker({element:markerEl}).setLngLat([lng,lat]).setPopup(popup).addTo(map);
            SN.poiMarkers.push(m);
          });
          var b=SN.poiMarkers.reduce(function(bb,m){return bb.extend(m.getLngLat());},
            new maplibregl.LngLatBounds(SN.poiMarkers[0].getLngLat(),SN.poiMarkers[0].getLngLat()));
          map.fitBounds(b,{padding:80,maxZoom:15,duration:800});
          var _ply=s('#snLyPoi'); if(_ply) _ply.checked=true;  /* reflect dropped POIs in the Layers toggle */
        })
        .catch(function(){ snToast('⚠️ Could not fetch nearby places — try again'); })
        .finally(function(){ if(chip) chip.classList.remove('loading'); });
    }
    document.querySelectorAll('#view-nav .sn-chip[data-poi]').forEach(function(chip){
      chip.onclick=function(){
        var key=chip.getAttribute('data-poi');
        if(SN.poiActive===key){ clearPoi(); return; }
        clearPoi();
        SN.poiActive=key;
        chip.classList.add('on');
        fetchPoi(key);
      };
    });
    window.snPoiNav=function(lng,lat,name){ snSetDest(lng,lat,name); snToast('🧭 Routing to '+name); };
  })();

  /* ====== FAVORITES: Saved Places (Home / Office / ★ Saved) ====== */
  (function(){
    var SN_FAV_KEY='shari:favorites';
    function snFavGet(){
      try{ var f=JSON.parse(localStorage.getItem(SN_FAV_KEY)||'null');
        if(f&&typeof f==='object') return {home:f.home||null,office:f.office||null,saved:Array.isArray(f.saved)?f.saved:[]};
      }catch(e){}
      return {home:null,office:null,saved:[]};
    }
    function snFavSet(f){ try{ localStorage.setItem(SN_FAV_KEY,JSON.stringify(f)); }catch(e){} }
    /* tap -> route to the saved place, or coach the user if the slot is empty */
    function snFavGo(slot,label){
      var fav=snFavGet()[slot];
      if(fav&&fav.lat!=null&&fav.lng!=null){
        snSetDest(fav.lng,fav.lat,fav.label||label);
        snToast('🧭 Routing to '+(fav.label||label));
        if(typeof snRoute==='function') snRoute();   /* auto-route — snRoute self-guards on origin+dest */
      } else {
        snToast('Set a destination, then long-press '+label+' to save it here');
      }
    }
    /* long-press -> pin the current destination into this favorite slot */
    function snFavSaveSlot(slot,label){
      if(!SN.dest){ snToast('Set a destination first, then long-press '+label); return; }
      var f=snFavGet();
      f[slot]={lng:SN.dest.lng,lat:SN.dest.lat,label:SN.dest.label||label};
      snFavSet(f); snToast('✅ '+label+' saved');
    }
    /* ★ tap -> append the current destination to the saved-stops list */
    function snFavSaveStop(){
      if(!SN.dest){ snToast('Set a destination first to save it'); return; }
      var f=snFavGet();
      f.saved.push({lng:SN.dest.lng,lat:SN.dest.lat,label:SN.dest.label||'Saved stop'});
      snFavSet(f); snToast('⭐ Saved');
    }
    /* Home / Office: 600ms long-press vs tap split via pointer events */
    [['home','Home'],['office','Office']].forEach(function(pair){
      var chip=s('.sn-fav-chip[data-fav="'+pair[0]+'"]'); if(!chip) return;
      var timer=null, longpressed=false;
      chip.addEventListener('pointerdown',function(){ longpressed=false;
        timer=setTimeout(function(){ longpressed=true; snFavSaveSlot(pair[0],pair[1]); },600); });
      chip.addEventListener('pointerup',function(){
        if(timer){ clearTimeout(timer); timer=null; }
        if(!longpressed) snFavGo(pair[0],pair[1]); });
      var cancel=function(){ if(timer){ clearTimeout(timer); timer=null; } };
      chip.addEventListener('pointercancel',cancel);
      chip.addEventListener('pointerleave',cancel);
      chip.addEventListener('contextmenu',function(e){ e.preventDefault(); });
    });
    var savedChip=s('.sn-fav-chip[data-fav="saved"]');
    if(savedChip) savedChip.addEventListener('click',snFavSaveStop);
  })();

  /* back button */
  s('#snBack').onclick=function(){ if(typeof snFocus==='function') snFocus(false); location.hash='#/'; };

  /* voice guidance toggle — speaks the upcoming turn via the browser's own
     free, built-in speechSynthesis (no API/key), matching the reference banner's mic icon */
  s('#snBanMic').onclick=function(){
    SN.voiceOn=!SN.voiceOn; s('#snBanMic').classList.toggle('on',SN.voiceOn); s('#snBanMic').classList.toggle('off',!SN.voiceOn);
    if(!SN.voiceOn&&window.speechSynthesis) try{window.speechSynthesis.cancel();}catch(e){}
    snToast(SN.voiceOn?'🔊 Voice guidance on':'🔇 Voice guidance off');
  };

  /* tri-lingual voice language chip — cycles EN → हि → ने, updates SN.voiceLang */
  (function(){
    var order=['en','hi','ne'], label={en:'EN',hi:'हि',ne:'ने'},
        name={en:'English',hi:'हिन्दी',ne:'नेपाली'}, chip=s('#snBanLang');
    if(!chip) return;
    function paint(){ chip.textContent=label[SN.voiceLang]||'EN'; }
    paint();
    chip.onclick=function(){
      var idx=order.indexOf(SN.voiceLang); if(idx<0) idx=0;
      SN.voiceLang=order[(idx+1)%order.length]; paint();
      SN.lastSpokenInstr=null;
      try{ if(window.speechSynthesis) window.speechSynthesis.cancel(); }catch(e){}
      snToast('🗣️ Voice: '+name[SN.voiceLang]);
    };
  })();

  /* ---- public live bus tracking (backend-agnostic — see postLocation/subscribeLocation) ----
     Driver side: reuses the SAME watchPosition loop below (onSnPos already calls
     postLocation() when this is on) — no second GPS listener is ever started.
     Passenger side: does NOT touch GPS at all, just renders whatever the driver
     last broadcast, so a waiting passenger can watch without granting location. */
  s('#snDriverToggle').onclick=function(){
    SN.driverBroadcast=!SN.driverBroadcast;
    s('#snDriverToggle').classList.toggle('on',SN.driverBroadcast);
    if(SN.driverBroadcast){
      /* Cross-device since 4 Sep 2026: a signed-in STAFF phone publishes the
         fix to the shared store every 10 s (see onSnPos); anyone else only
         shares with other tabs of the same browser, as before. */
      var staffOk=!!(window.SHG_BOOT&&window.SHG_BOOT.staff);
      snToast(staffOk?'📡 Sharing the bus position with every passenger — allow GPS access':'📡 Sharing on this phone only. Sign in as staff at shreehariglobal.network to broadcast to passengers.');
      if(SN.watchId==null) s('#snLocate').click();
    } else snToast('📡 Broadcast stopped');
  };
  function snTrackTick(){
    if(!SN.trackLastTs){ s('#snTrackUpd').textContent='waiting for driver…'; return; }
    var secs=Math.round((Date.now()-SN.trackLastTs)/1000);
    s('#snTrackUpd').textContent=secs<5?'just now':(secs<60?secs+'s ago':Math.round(secs/60)+'m ago');
  }
  s('#snTrackToggle').onclick=function(){
    SN.trackingMode=!SN.trackingMode;
    s('#snTrackToggle').classList.toggle('on',SN.trackingMode);
    s('#snTrackCard').classList.toggle('show',SN.trackingMode);
    if(SN.trackingMode){
      if(!SN.busMarker){ var el=document.createElement('div'); el.className='sn-bus-m'; el.textContent='🚌';
        SN.busMarker=new maplibregl.Marker({element:el}); }
      SN.followBus=true;
      var onBusFix=function(loc){
        SN.trackLastTs=loc.ts||Date.now();
        if(!SN.busMarker._addedOnce){
          SN.busMarker.setLngLat([loc.lng,loc.lat]).addTo(map); SN.busMarker._addedOnce=true;
          SN.busDisp={lng:loc.lng,lat:loc.lat}; SN.busTarget={lng:loc.lng,lat:loc.lat,spd:loc.spd||0,head:loc.bearing,ts:Date.now()};
          snBusTrailPush(loc.lng,loc.lat);
          /* first fix: glide to the bus (cinematic), then the motion engine follows it */
          if(SN.followBus) snEase({center:[loc.lng,loc.lat],zoom:Math.max(map.getZoom(),12.5),duration:SN.lite?0:1400});
          try{ SN.busMarker.getElement().onclick=function(){ SN.followBus=true; snEase({center:[loc.lng,loc.lat],zoom:Math.max(map.getZoom(),13),duration:700}); snToast('🎯 Following the bus'); }; }catch(e){}
        }
        else snAnimateBus(loc.lng,loc.lat,loc.spd,loc.bearing);
        /* shg-v59 — shortest-arc smooth rotation for the shared-live-bus marker too
           (was a hard snap). Same helper as the me-marker so the two markers move
           with identical feel. Duration is loose (1200ms) because live-bus fixes
           come from another device at variable cadence. */
        if(loc.bearing!=null&&!isNaN(loc.bearing)) snRotateMarker(SN.busMarker, loc.bearing, 1200, 'busRotAnim');
        snTrackTick();
        if(SN.routeCoords){
          var snap=snapToRoute(loc.lng,loc.lat);
          if(snap){
            updateTraveledRoute(snap.distAlongKm);
            /* ETA from the bus's OWN reported speed when it is moving; the mode
               constant only as a floor while it crawls / stands still. */
            var remainKm=Math.max(0,SN.routeTotalKm-snap.distAlongKm), v=(loc.spd!=null&&loc.spd*3.6>8)?loc.spd*3.6:(SN_SPD.bus||35);
            s('#snTrackDist').textContent=remainKm<1?Math.round(remainKm*1000)+' m':remainKm.toFixed(1)+' km';
            s('#snTrackEta').textContent=snFmtTime(remainKm/v*3600);
            try{
              if(typeof SN_STOPS!=='undefined'&&SN_STOPS.length){
                if(!SN.stopAlong||SN.stopAlong._forCoords!==SN.routeCoords){
                  SN.stopAlong=SN_STOPS.map(function(st){ var ss=snapToRoute(st.lng,st.lat); return ss?ss.distAlongKm:null; });
                  SN.stopAlong._forCoords=SN.routeCoords;
                }
                var busAlong=snap.distAlongKm,cur=null,nxt=null,i2,sa;
                for(i2=0;i2<SN_STOPS.length;i2++){
                  sa=SN.stopAlong[i2]; if(sa==null) continue;
                  if(sa<=busAlong+0.05){ if(cur===null||sa>SN.stopAlong[cur]) cur=i2; }
                  else if(nxt===null||sa<SN.stopAlong[nxt]) nxt=i2;
                }
                s('#snTrackCur').textContent=cur!==null?((SN_STOPS[cur].f?SN_STOPS[cur].f+' ':'')+SN_STOPS[cur].n):'—';
                s('#snTrackNext').textContent=nxt!==null?((SN_STOPS[nxt].f?SN_STOPS[nxt].f+' ':'')+SN_STOPS[nxt].n):'—';
              }
            }catch(e){}
          }
        }
      };
      SN.trackUnsub=subscribeLocation(onBusFix);   /* same-device shim (driver phone / tests) */
      /* Cross-device (5 Sep 2026): the driver's phone publishes its GPS to the shared
         `livebus` key every ~10 s (see onSnPos); the Trip Companion already reads it,
         now the navigator does too — a passenger on ANY phone sees the bus move. */
      var pollBus=function(){
        try{
          store.get('shg:livebus',null).then(function(lb){
            if(!lb||!lb.gps||lb.lat==null||lb.lng==null) return;
            if(!lb.at||(Date.now()-lb.at)>3*60000||lb.at===SN.lastKvAt) return;
            SN.lastKvAt=lb.at;
            onBusFix({lat:lb.lat,lng:lb.lng,bearing:lb.bearing!=null?lb.bearing:null,spd:lb.spd!=null?lb.spd:null,ts:lb.at});
          }).catch(function(){});
        }catch(e){}
      };
      if(SN.trackPollT) clearInterval(SN.trackPollT);
      SN.trackPollT=setInterval(pollBus,12000); pollBus();
      if(SN.trackTickT) clearInterval(SN.trackTickT);
      SN.trackTickT=setInterval(snTrackTick,5000);
      snToast('🚌 Watching for the live bus position…');
    } else {
      if(SN.trackUnsub){ SN.trackUnsub(); SN.trackUnsub=null; }
      if(SN.trackPollT){ clearInterval(SN.trackPollT); SN.trackPollT=null; }
      if(SN.trackTickT){ clearInterval(SN.trackTickT); SN.trackTickT=null; }
      if(SN.busMarker){SN.busMarker.remove();SN.busMarker._addedOnce=false;}
      if(SN.busAnim){cancelAnimationFrame(SN.busAnim);SN.busAnim=null;}
      SN.busTarget=null; SN.busDisp=null; SN.busTrail=[]; snBusTrailUpdate();
    }
  };

  /* GPS — smart vehicle markers + speed-adaptive camera */
  var SN_MODE_CLR={auto:'#4285F4',motorcycle:'#7B2FBE',bus:'#178A50',bicycle:'#FF9800',pedestrian:'#00BCD4'};
  function ensureMe(){
    if(SN.meMarker) return;
    var el=document.createElement('div'); el.className='sn-me';
    var col=SN_MODE_CLR[SN.mode]||'#4285F4';
    el.innerHTML='<div class="sn-me-pulse" style="background:'+col+'55"></div>'
      +'<div class="sn-me-cone" style="border-bottom-color:'+col+'88"></div>'
      +'<div class="sn-me-icon" style="background:'+col+'">'
      +'<svg viewBox="0 0 24 24"><path d="M12 2L4.5 20.29l.71.71L12 18l6.79 3 .71-.71z"/></svg></div>';
    SN.meMarker=new maplibregl.Marker({element:el,rotationAlignment:'map',pitchAlignment:'map'});
    SN.meMarker.setLngLat(SN.gpsPos?[SN.gpsPos.lng,SN.gpsPos.lat]:[0,0]).addTo(map);
  }
  function snUpdateMeMode(){
    if(!SN.meMarker) return;
    var el=SN.meMarker.getElement(), col=SN_MODE_CLR[SN.mode]||'#4285F4';
    var p=el.querySelector('.sn-me-pulse'); if(p) p.style.background=col+'55';
    var cn=el.querySelector('.sn-me-cone'); if(cn) cn.style.borderBottomColor=col+'88';
    var ic=el.querySelector('.sn-me-icon'); if(ic) ic.style.background=col;
  }
  /* ================================================================
     MOTION ENGINE (5 Sep 2026) — what makes the map feel like Google Maps.
     One requestAnimationFrame loop drives the "me" marker, the live-bus
     marker AND the follow camera. Each marker has a TARGET (the last fix,
     with speed + heading) and a DISPLAY position that chases a PREDICTED
     point: target + velocity x time-since-fix (dead reckoning, capped) via
     critically-damped exponential smoothing. So the marker keeps rolling
     between fixes at the vehicle's real speed and settles into each new fix
     without a stop-and-go, at any GPS cadence; the camera glides the same
     way instead of easing per fix. The loop sleeps when nothing moves.
     Lite mode snaps instead (no per-frame work). */
  var SN_TAU_ME=260, SN_TAU_BUS=420, SN_TAU_CAM=380, SN_DR_CAP_MS=1600;
  function snPredict(t,nowMs){
    var dt=Math.min(SN_DR_CAP_MS,Math.max(0,nowMs-(t.ts||nowMs)))/1000;
    if(!(t.spd>0.7)||t.head==null||isNaN(t.head)||dt<=0) return {lng:t.lng,lat:t.lat};
    var dKm=(t.spd*dt)/1000, br=t.head*Math.PI/180;
    var dLat=(dKm/110.574)*Math.cos(br), dLng=(dKm/(111.320*Math.cos(t.lat*Math.PI/180)))*Math.sin(br);
    return {lng:t.lng+dLng,lat:t.lat+dLat};
  }
  function snChase(disp,goal,tau,dtMs){ var k=1-Math.exp(-dtMs/tau); return {lng:disp.lng+(goal.lng-disp.lng)*k,lat:disp.lat+(goal.lat-disp.lat)*k}; }
  function snMotionKick(){ if(SN.motionAnim!=null||SN.lite) return; SN.motionLast=performance.now(); SN.motionAnim=requestAnimationFrame(snMotionStep); }
  function snMotionStep(now){
    SN.motionAnim=null;
    if(!SN.map) return;
    var dt=Math.min(100,Math.max(1,now-(SN.motionLast||now))); SN.motionLast=now;
    var active=false, nowMs=Date.now(), eps=1e-7;
    if(SN.meMarker&&SN.meTarget){
      var g=snPredict(SN.meTarget,nowMs);
      SN.meDisp=SN.meDisp?snChase(SN.meDisp,g,SN_TAU_ME,dt):g;
      SN.meMarker.setLngLat([SN.meDisp.lng,SN.meDisp.lat]);
      if(Math.abs(g.lng-SN.meDisp.lng)>eps||Math.abs(g.lat-SN.meDisp.lat)>eps||(nowMs-SN.meTarget.ts)<SN_DR_CAP_MS) active=true;
      if((SN.followMe||SN.persistFollow)&&!SN.camLock){
        var c=map.getCenter(), cc=snChase({lng:c.lng,lat:c.lat},SN.meDisp,SN_TAU_CAM,dt);
        if(Math.abs(cc.lng-c.lng)>eps||Math.abs(cc.lat-c.lat)>eps){ map.jumpTo({center:[cc.lng,cc.lat]}); active=true; }
      }
    }
    if(SN.busMarker&&SN.busTarget&&SN.busMarker._addedOnce){
      var gb=snPredict(SN.busTarget,nowMs);
      SN.busDisp=SN.busDisp?snChase(SN.busDisp,gb,SN_TAU_BUS,dt):gb;
      SN.busMarker.setLngLat([SN.busDisp.lng,SN.busDisp.lat]);
      if(Math.abs(gb.lng-SN.busDisp.lng)>eps||Math.abs(gb.lat-SN.busDisp.lat)>eps||(nowMs-SN.busTarget.ts)<SN_DR_CAP_MS) active=true;
      if(SN.trackingMode&&SN.followBus&&!SN.camLock&&!(SN.followMe||SN.persistFollow)){
        var c2=map.getCenter(), cb=snChase({lng:c2.lng,lat:c2.lat},SN.busDisp,SN_TAU_CAM,dt);
        if(Math.abs(cb.lng-c2.lng)>eps||Math.abs(cb.lat-c2.lat)>eps){ map.jumpTo({center:[cb.lng,cb.lat]}); active=true; }
      }
    }
    if(active) SN.motionAnim=requestAnimationFrame(snMotionStep);
  }
  function setMeSmooth(lng,lat){
    if(!SN.meMarker) return;
    var from=SN.meDisp||SN.prevGps||SN.meMarker.getLngLat();
    var big=Math.abs(from.lng-lng)>.03||Math.abs(from.lat-lat)>.03;
    SN.prevGps={lng:lng,lat:lat};
    var g=SN.gpsPos||{};
    SN.meTarget={lng:lng,lat:lat,spd:(g.spd!=null&&!isNaN(g.spd))?g.spd:0,head:(g.head!=null&&!isNaN(g.head))?g.head:null,ts:Date.now()};
    if(big||SN.lite){ SN.meDisp={lng:lng,lat:lat}; SN.meMarker.setLngLat([lng,lat]); return; }
    snMotionKick();
  }
  /* Observed speed for ETAs: an EMA of the GPS speed while moving, else the mode constant. */
  function snSpeedKmh(){ var v=SN.avgSpdKmh||0; return v>5?v:(SN_SPD[SN.mode]||40); }
  /* ================================================================
     [MAP PRO] UPGRADE #34 — a heading that survives standing still.
     The direction cone was driven only by GPS course, and GPS reports
     no course when the phone is not moving — so the cone vanished at
     exactly the moment someone stops to work out which way to walk.
     Phones have a magnetometer for this, which is what Google Maps
     leans on. We prefer the device compass when it is reporting, and
     fall back to GPS course while actually moving. Nothing is invented:
     if neither source gives a heading the cone stays hidden, as before.
     iOS needs the permission asked from a user gesture, so this is
     started from the 📍 button rather than on load.
  ================================================================ */
  SN.compassHeading = null;
  function snOnOrient(e){
    var h = null;
    /* iOS reports true-north degrees directly; Android's absolute frame
       gives alpha counter-clockwise from north, hence 360 - alpha. */
    if (typeof e.webkitCompassHeading === 'number' && !isNaN(e.webkitCompassHeading)) h = e.webkitCompassHeading;
    else if (e.absolute === true && typeof e.alpha === 'number' && !isNaN(e.alpha)) h = 360 - e.alpha;
    if (h != null && isFinite(h)) SN.compassHeading = (h % 360 + 360) % 360;
  }
  function snStartCompass(){
    if (SN.compassStarted || !window.DeviceOrientationEvent) return;
    SN.compassStarted = true;
    try {
      if (typeof DeviceOrientationEvent.requestPermission === 'function') {
        DeviceOrientationEvent.requestPermission()
          .then(function(state){ if (state === 'granted') window.addEventListener('deviceorientation', snOnOrient, true); })
          .catch(function(){});   /* declined — GPS course still works while moving */
      } else {
        window.addEventListener('deviceorientationabsolute', snOnOrient, true);
        window.addEventListener('deviceorientation', snOnOrient, true);
      }
    } catch(e){}
  }

  function onSnPos(p){
    var c=p.coords, fresh=!SN.meMarker;
    /* A1 — reject GPS outliers: if the implied speed between this fix and the
       last one is physically absurd (>500km/h), it's a bad fix (cold-start jump,
       multipath reflection) — keep the marker where it was and wait for the next one. */
    if(!fresh&&SN.gpsPos){
      var dtS=(c.timestamp&&SN.gpsPos.ts?(c.timestamp-SN.gpsPos.ts)/1000:2);
      if(dtS>0&&dtS<30){
        var jumpKm=haversineKm(SN.gpsPos.lat,SN.gpsPos.lng,c.latitude,c.longitude);
        if(jumpKm/dtS*3600>500) return;
      }
    }
    /* shg-v59 — Perf item #6: cap fix processing at ~1/sec. Chrome/Firefox routinely
       fire watchPosition at 2–5Hz on a moving device — running the whole snap/route
       progress pipeline that often is wasted work AND makes the marker tween chase
       its tail. We keep the raw stream (so a rare outlier gets filtered above) and
       just skip the heavy work when fixes come in faster than the marker can glide.
       Also records SN.gpsIntervalMs so setMeSmooth() sizes its tween to the real
       cadence rather than a hardcoded 450ms window. */
    var nowMs = (c.timestamp || (performance.now() + performance.timeOrigin)) | 0;
    if(SN.lastGpsTs){
      var sinceMs = nowMs - SN.lastGpsTs;
      if(!fresh && sinceMs < 900) return;
      if(sinceMs > 0 && sinceMs < 5000) SN.gpsIntervalMs = sinceMs;
    }
    SN.lastGpsTs = nowMs;
    SN.gpsPos={lng:c.longitude,lat:c.latitude,acc:c.accuracy,head:c.heading,spd:c.speed,alt:c.altitude,ts:c.timestamp||Date.now()};
    /* observed-speed EMA for ETAs (5 Sep 2026) */
    if(c.speed!=null&&!isNaN(c.speed)&&c.speed>0.5){ var _kmh=c.speed*3.6; SN.avgSpdKmh=SN.avgSpdKmh?SN.avgSpdKmh*0.7+_kmh*0.3:_kmh; }
    try{localStorage.setItem('shari:lastpos',JSON.stringify({lng:SN.gpsPos.lng,lat:SN.gpsPos.lat,acc:SN.gpsPos.acc,ts:Date.now()}));}catch(e){}

    /* driver broadcast — piggybacks on this SAME watchPosition loop (no second
       GPS listener started) whenever the "I'm the driver" toggle is on. */
    if(SN.driverBroadcast) postLocation({lat:SN.gpsPos.lat,lng:SN.gpsPos.lng,bearing:c.heading,ts:SN.gpsPos.ts});
    /* Cross-device (4 Sep 2026): a signed-in staff phone also writes the fix
       to the shared `livebus` store — at most every 10 s — which every
       passenger's Trip Companion polls (tcInitManualLive). The nearest stop
       keeps the text banner meaningful; the office's "Set position" writes
       the same key by hand and stays fully compatible. */
    if(SN.driverBroadcast&&window.SHG_BOOT&&window.SHG_BOOT.staff&&typeof shgApi!=='undefined'&&(nowMs-(SN.kvPushTs||0))>=10000){
      SN.kvPushTs=nowMs;
      var nearName='—'; try{ nearName=snNearestCity(SN.gpsPos.lng,SN.gpsPos.lat); }catch(e){}
      var lbPayload={gps:true,lat:SN.gpsPos.lat,lng:SN.gpsPos.lng,
        bearing:c.heading!=null?Math.round(c.heading):null,
        spd:c.speed!=null?Math.round(c.speed*100)/100:null,
        acc:c.accuracy!=null?Math.round(c.accuracy):null,
        stop:nearName,next:'',at:Date.now(),by:(window.SHG_BOOT.staff.name||'driver')};
      shgApi.post('/kv.php',{action:'set',key:'livebus',value:JSON.stringify(lbPayload)}).catch(function(){});
    }

    /* A3 — road snapping: while a route is active, show the dot on the road
       geometry Valhalla already gave us instead of the raw (noisy) GPS point,
       but only when we're plausibly still ON that road — a snap from 2km away
       would be worse than showing the truth. */
    var dispLng=SN.gpsPos.lng, dispLat=SN.gpsPos.lat, snap=null;
    if(SN.routeCoords){
      snap=snapToRoute(SN.gpsPos.lng,SN.gpsPos.lat);
      if(snap&&snap.offKm<=0.3){ dispLng=snap.lng; dispLat=snap.lat; }
    }

    ensureMe();
    if(fresh){ SN.meMarker.setLngLat([dispLng,dispLat]); SN.prevGps={lng:dispLng,lat:dispLat}; }
    else setMeSmooth(dispLng,dispLat);
    /* #34 — GPS course is only trustworthy while actually moving; below
       walking pace it goes stale or null, so the compass takes over there. */
    var moving = (c.speed != null && !isNaN(c.speed) && c.speed > 0.7);
    var gpsHdg = (c.heading != null && !isNaN(c.heading)) ? c.heading : null;
    var hdg    = (moving && gpsHdg != null) ? gpsHdg
               : (SN.compassHeading != null ? SN.compassHeading : gpsHdg);
    if(SN.meMarker&&hdg!=null&&!isNaN(hdg)){
      /* shg-v59 — shortest-arc smooth rotation (was a hard snap). Duration is a hair
         shorter than the position tween so the direction "locks in" before the marker
         finishes gliding, which reads as leading with the front of the vehicle. */
      snRotateMarker(SN.meMarker, hdg, Math.max(280, Math.min(700, (SN.gpsIntervalMs||1000) * 0.6)), 'meRotAnim');
      SN.meMarker.getElement().querySelector('.sn-me-cone').style.opacity=1;
    } else if(SN.meMarker){ SN.meMarker.getElement().querySelector('.sn-me-cone').style.opacity=0; }
    var ring=circPoly(dispLng,dispLat,SN.gpsPos.acc||30);
    if(map.getSource('sn-acc')) map.getSource('sn-acc').setData(ring); else applyOL();

    /* A4/A5 — traveled/ahead route split + live turn-by-turn banner, driven by
       how far along the route geometry we've actually progressed. */
    if(snap){
      SN.routeProgKm=snap.distAlongKm;
      updateTraveledRoute(snap.distAlongKm);
      updateManeuverBanner(snap.distAlongKm);
      updateNavCard(snap.distAlongKm);
      /* A7 — auto re-route: only after being sustainedly off-route (not one jittery
         fix) so we don't hammer the free/rate-limited Valhalla demo server. */
      var offLimit=SN_OFFROUTE_KM[SN.mode]||0.06;
      if(snap.offKm>offLimit){
        if(!SN.offRouteSince) SN.offRouteSince=Date.now();
        else if(Date.now()-SN.offRouteSince>6000&&!SN.rerouteT){
          SN.rerouteT=setTimeout(function(){ SN.rerouteT=null; },15000);
          snToast('↻ Off route — recalculating…'); snSetOrigin(SN.gpsPos.lng,SN.gpsPos.lat,'My location'); snRoute();
        }
      } else SN.offRouteSince=0;
    }

    /* A6 — camera: speed-adaptive zoom + persistent follow + compass mode */
    var easeOpts=null;
    if(SN.followMe||SN.persistFollow){
      var spdKmh=c.speed!=null?c.speed*3.6:0;
      var tz=spdKmh<5?18:spdKmh<20?17:spdKmh<50?16:spdKmh<80?15:14;
      /* Centre is driven per-frame by the motion engine (no per-fix ease that
         "chops"); only a real zoom-band change is eased here. Lite keeps the
         old per-fix ease including the centre. */
      if(SN.lite) easeOpts={center:[dispLng,dispLat],zoom:tz};
      else if(Math.abs(map.getZoom()-tz)>0.6) easeOpts={zoom:tz};
      if(SN.followMe) SN.followMe=false;
      snMotionKick();
    }
    if(c.heading!=null&&!isNaN(c.heading)&&SN.compassMode&&!SN.userRotated&&(c.speed==null||c.speed*3.6>3)){
      easeOpts=easeOpts||{}; easeOpts.bearing=c.heading;
    }
    /* shg-v59 — route every follow-camera move through snEase() so pan/zoom/bearing
       share ONE cubic ease-out (item #1). Slightly longer window than the previous 700ms
       so the camera visibly decelerates instead of feeling "chopped". */
    if(easeOpts){ easeOpts.duration=Math.max(600, Math.min(900, (SN.gpsIntervalMs||1000)*0.85)); snEase(easeOpts); }

    /* arrival detection — 50m threshold */
    if(SN.dest&&SN.routeCoords&&!SN.arrived){
      var arrDist=haversineKm(SN.gpsPos.lat,SN.gpsPos.lng,SN.dest.lat,SN.dest.lng);
      if(arrDist<0.05){
        SN.arrived=true;
        var ae=s('#snArrival'); if(ae){s('#snArrDest').textContent=SN.dest.label||'Destination';ae.classList.add('show');}
        speak('You have arrived at your destination');
        snToast('🎉 Arrived!');
      }
    }

    s('#snHud').classList.remove('off');
    var spd=c.speed!=null?Math.max(0,Math.round(c.speed*3.6)):0;
    s('#snSpeed').textContent=spd;
    /* 4 Sep 2026 — the pace band (walking / cycle / car / bus) is read from
       the LIVE speed, not the mode chip, and recolours the HUD so a glance
       says how the phone is moving. Thresholds: <6 walk, <28 cycle, <75 car. */
    var band=spd<6?'walk':(spd<28?'bike':(spd<75?'car':'bus'));
    var hudEl=s('#snHud');
    if(hudEl&&hudEl.getAttribute('data-band')!==band){
      hudEl.setAttribute('data-band',band);
      var unitEl=s('#snSpeed').nextElementSibling;
      if(unitEl) unitEl.textContent='km/h · '+({walk:'🚶',bike:'🚲',car:'🚗',bus:'🚌'})[band];
    }
    s('#snHead').textContent=c.heading!=null?Math.round(c.heading)+'°':'—';
    s('#snAcc').textContent=c.accuracy!=null?'±'+Math.round(c.accuracy)+' m':'—';
    s('#snAlt').textContent=c.altitude!=null?Math.round(c.altitude)+' m':'—';
    s('#snCoord').textContent=c.latitude.toFixed(4)+', '+c.longitude.toFixed(4);
    snFocusCardSync(false);   /* [SECTION 4] keep Focus card live (speed + throttled CURRENT CITY) */
    if(!SN.origin) snSetOrigin(SN.gpsPos.lng,SN.gpsPos.lat,'My location');
  }
  s('#snLocate').onclick=function(){
    if(!navigator.geolocation){ snToast('GPS not supported'); return; }
    snStartCompass();   /* #34 — must be asked from a user gesture on iOS */
    SN.followMe=true; SN.persistFollow=true; SN.userRotated=false;
    s('#snLocate').classList.add('on');
    /* shg-v59 — tap bounce (item #7). Class toggle triggers a CSS keyframe
       so the visual response is instant even while GPS is warming up. */
    var _lb = s('#snLocate'); _lb.classList.remove('sn-bounce'); void _lb.offsetWidth; _lb.classList.add('sn-bounce');
    if(SN.gpsPos) snEase({center:[SN.gpsPos.lng,SN.gpsPos.lat],zoom:Math.max(map.getZoom(),15),duration:800});
    if(SN.watchId==null){
      /* shg-v59 — persistent pulsing "locating" state on the button (item #5) instead
         of only a transient toast; cleared automatically on the first successful fix. */
      _lb.classList.add('locating');
      snToast('📍 Locating…');
      var _clearLocating = function(){ _lb.classList.remove('locating'); };
      navigator.geolocation.getCurrentPosition(function(pos){ _clearLocating(); onSnPos(pos); },function(){ _clearLocating(); },{enableHighAccuracy:false,maximumAge:60000,timeout:8000});
      SN.watchId=navigator.geolocation.watchPosition(function(pos){ _clearLocating(); onSnPos(pos); },function(){
        _clearLocating(); s('#snLocate').classList.remove('on'); snToast('📍 Location blocked — allow GPS');
      },{enableHighAccuracy:true,maximumAge:0,timeout:15000});
    }
  };
  map.on('dragstart',function(){ SN.followMe=false; SN.persistFollow=false; SN.followBus=false; s('#snLocate').classList.remove('on'); });

  /* search — enhanced: history, category icons, 2-char trigger, multi-language */
  /* [MAP PRO] Navigator search — one reusable Google-Maps-style autocomplete for
     all three location fields. #snQ searches & flies to a destination; #snFrom and
     #snTo are now full origin/destination search inputs too (still settable by GPS,
     map-tap and the preset route as before — those set .value directly, which does
     NOT trigger a search). Fixes address truncation (the full displayName is stored)
     and the vanishing-dropdown bug (body-mounted; closes only on outside-tap / Esc). */
  function snPlaceIcon(txt){
    if(!txt)return'📍';txt=txt.toLowerCase();
    if(/temple|mandir|kovil|dham/.test(txt))return'🛕';if(/hospital|clinic/.test(txt))return'🏥';
    if(/school|college|university|vidhyalaya/.test(txt))return'🎓';if(/hotel|lodge|dharmshala/.test(txt))return'🏨';
    if(/restaurant|food|dhaba|bhojanalaya/.test(txt))return'🍽️';if(/petrol|fuel|gas/.test(txt))return'⛽';
    if(/airport/.test(txt))return'✈️';if(/railway|station/.test(txt))return'🚉';if(/bus.?st/.test(txt))return'🚏';
    if(/park|garden|udyan/.test(txt))return'🌳';if(/mosque|masjid/.test(txt))return'🕌';if(/church/.test(txt))return'⛪';
    if(/market|mall|shop|bazaar/.test(txt))return'🛒';if(/bank|atm/.test(txt))return'🏦';
    if(/river|nadi|lake/.test(txt))return'🌊';if(/mountain|hill|parbat/.test(txt))return'⛰️';
    if(/village|gaon/.test(txt))return'🏘️';if(/city|town|nagar|pur/.test(txt))return'🏙️';return'📍';
  }
  function snFly(r){ if(isFinite(r.lat)&&isFinite(r.lng)) snEase({center:[r.lng,r.lat],zoom:Math.max(map.getZoom(),12),duration:900}); }
  createLocationSearchInput(s('#snQ'),   { historyKey:'shari:searchHist', icon:snPlaceIcon,
    onSelect:function(r){ snSetDest(r.lng,r.lat,r.displayName); snFly(r); if(typeof snSheet==='function') snSheet('half'); } });
  createLocationSearchInput(s('#snFrom'),{ icon:snPlaceIcon,
    onSelect:function(r){ snSetOrigin(r.lng,r.lat,r.displayName); snFly(r); } });
  createLocationSearchInput(s('#snTo'),  { icon:snPlaceIcon,
    onSelect:function(r){ snSetDest(r.lng,r.lat,r.displayName); snFly(r); } });

  /* [MAP PRO] Premium bottom-sheet controller — peek (map dominant) → half → full.
     Tap the grabber / summary bar to cycle; drag the grabber to fling open or shut. */
  SN.sheetState='peek';
  function snSheet(state){
    var dock=s('.sn-dock'); if(!dock) return;
    SN.sheetState=state; dock.setAttribute('data-sheet',state);
    var caret=s('#snPeek .pk-caret'); if(caret) caret.textContent=(state==='peek'?'▴':'▾');
    if(SN.map) requestAnimationFrame(function(){ SN.map.resize(); });
  }
  function snSheetCycle(){ snSheet(SN.sheetState==='peek'?'half':(SN.sheetState==='half'?'full':'peek')); }
  function snPeekSet(title,sub){ var t=s('#snPeekTitle'),u=s('#snPeekSub'); if(t)t.textContent=title; if(u&&sub!=null)u.textContent=sub; }

  /* ================= [SECTION 4] FOCUS NAVIGATION MODE (Apple-Maps style) =================
     snFocus(on) just toggles the .sn-focus class on #view-nav — every hide is CSS (scoped
     to #view-nav.sn-focus), so nothing is destroyed and exit restores all chrome. The
     compact card mirrors the live ETA / distance / road already computed by updateNavCard,
     plus speed, plus a throttled CURRENT CITY (nearest known SN_STOPS). No new rAF/timers. */
  function snNearestCity(lng,lat){
    var best=null,bestD=Infinity;
    for(var i=0;i<SN_STOPS.length;i++){
      var d=haversineKm(lat,lng,SN_STOPS[i].lat,SN_STOPS[i].lng);
      if(d<bestD){ bestD=d; best=SN_STOPS[i]; }
    }
    return best?(best.n+(bestD>6?' area':'')):'—';
  }
  function snFocusCardSync(force){
    try{
      var nv=document.getElementById('view-nav');
      if(!force && (!nv || !nv.classList.contains('sn-focus'))) return;
      var sp=s('#snFcSpeed');
      if(sp) sp.textContent=(SN.gpsPos&&SN.gpsPos.spd!=null)?Math.max(0,Math.round(SN.gpsPos.spd*3.6)):0;
      var pairs=[['snFcEta','snNcEta'],['snFcDist','snNcDist'],['snFcRoad','snNcRoad']],i,t,u;
      for(i=0;i<pairs.length;i++){ t=s('#'+pairs[i][0]); u=s('#'+pairs[i][1]); if(t&&u&&u.textContent) t.textContent=u.textContent; }
      var now=Date.now();
      if(SN.gpsPos && (force || !SN.focusCityT || now-SN.focusCityT>4000)){
        SN.focusCityT=now;
        var fc=s('#snFcCity'); if(fc) fc.textContent=snNearestCity(SN.gpsPos.lng,SN.gpsPos.lat);
      }
    }catch(e){}
  }
  function snFocus(on){
    try{
      var nv=document.getElementById('view-nav'); if(!nv) return;
      nv.classList.toggle('sn-focus', !!on);
      if(on){ if(typeof updateNavCard==='function') updateNavCard(SN.routeProgKm||0); snFocusCardSync(true); }
    }catch(e){}
  }
  (function(){ var fx=s('#snFocusExit'); if(fx) fx.onclick=function(){ snFocus(false); }; })();
  (function(){
    var grab=s('#snGrab'), peek=s('#snPeek');
    /* [AUTO-ROUTE V4] once a route is drawn (SN.routeCoords set) the peek bar becomes a
       "start navigation" button — a tap re-enters GPS-follow (persistFollow=true) for the
       full-screen turn-by-turn feel. With no active route it keeps the peek/half/full cycle. */
    function snPeekTap(){
      if(SN.routeCoords && SN.routeCoords.length){ s('#snLocate').click(); return; }
      snSheetCycle();
    }
    if(peek){ peek.addEventListener('click',snPeekTap);
      peek.addEventListener('keydown',function(e){ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); snPeekTap(); } }); }
    var startY=0, dragging=false, moved=0;
    if(grab){
      grab.addEventListener('pointerdown',function(e){ dragging=true; startY=e.clientY; moved=0; try{grab.setPointerCapture(e.pointerId);}catch(_){} });
      grab.addEventListener('pointermove',function(e){ if(dragging) moved=e.clientY-startY; });
      grab.addEventListener('pointerup',function(){ if(!dragging) return; dragging=false;
        if(moved<-28) snSheet(SN.sheetState==='peek'?'half':'full');
        else if(moved>28) snSheet(SN.sheetState==='full'?'half':'peek');
        else snSheetCycle();
      });
    }
    snSheet('peek');
  })();

  /* origin / dest */
  function snSetOrigin(lng,lat,label){
    SN.origin={lng:lng,lat:lat,label:label||lat.toFixed(4)+', '+lng.toFixed(4)};
    s('#snFrom').value=SN.origin.label;
    if(!SN.originMarker) SN.originMarker=new maplibregl.Marker({color:'#4285F4'}).setLngLat([lng,lat]).addTo(map);
    else SN.originMarker.setLngLat([lng,lat]);
    snRefreshGo();
  }
  function snSetDest(lng,lat,label){
    SN.dest={lng:lng,lat:lat,label:label||lat.toFixed(4)+', '+lng.toFixed(4)};
    s('#snTo').value=SN.dest.label;
    if(!SN.destMarker) SN.destMarker=new maplibregl.Marker({color:'#FF6B00'}).setLngLat([lng,lat]).addTo(map);
    else SN.destMarker.setLngLat([lng,lat]);
    snRefreshGo();
    /* [AUTO-ROUTE V4] tapping/selecting a destination auto-calculates the route so the
       rider never presses "Get directions" (<=2 taps to navigate). Debounced 250ms so a
       rapid map-tap / search-pick that re-sets dest doesn't double-fire snRoute(). */
    if(SN.origin){
      if(SN._autoRouteT) clearTimeout(SN._autoRouteT);
      SN._autoRouteT=setTimeout(function(){ SN._autoRouteT=null; if(SN.origin&&SN.dest) snRoute(); },250);
    }
  }
  /* instant, offline distance readout — shows the moment a place is picked,
     no Valhalla round-trip needed (that only fires once "Get directions" is pressed) */
  var SN_SPD={auto:40,motorcycle:35,bus:35,bicycle:15,pedestrian:5};
  /* off-route distance (km) beyond which we stop trusting the snap and treat the
     rider as genuinely off the suggested path — tighter for walking, looser for a bus */
  var SN_OFFROUTE_KM={auto:0.06,motorcycle:0.05,bus:0.08,bicycle:0.03,pedestrian:0.02};
  function snUpdateQuickDist(){ /* straight-line quick-distance readout removed — no-op kept so callers stay wired */ }
  function snRefreshGo(){
    s('#snGo').disabled=!(SN.origin&&SN.dest); snUpdateQuickDist();
    /* [MAP PRO] keep the collapsed peek bar informative so the map stays full-screen */
    if(typeof snPeekSet==='function'){
      if(SN.origin&&SN.dest){
        /* [V4] no manual straight-line distance — the real road route auto-calculates.
           Once a route is drawn, leave snRoute's ETA / "Tap to start navigation" text intact. */
        if(!(SN.routeCoords&&SN.routeCoords.length))
          snPeekSet('🧭 Calculating best route…',(SN.origin.label||'Start')+' → '+(SN.dest.label||'Destination'));
      } else if(SN.dest){ snPeekSet(SN.dest.label||'Destination set','Set a start, or tap 📍 My location'); }
      else if(SN.origin){ snPeekSet('Start: '+(SN.origin.label||'set'),'Search or tap your destination'); }
      else { snPeekSet('Plan your journey','Search a place, or tap the map'); }
    }
  }
  s('#snUseGps').onclick=function(){ s('#snLocate').click(); };
  map.on('click',function(e){
    var ll=e.lngLat;
    if(!SN.origin){ snSetOrigin(ll.lng,ll.lat,'Dropped start'); snToast('Start set — tap destination'); }
    else snSetDest(ll.lng,ll.lat,'Dropped pin');
  });

  /* mode chips */
  document.querySelectorAll('#view-nav [data-snmode]').forEach(function(m){
    m.onclick=function(){
      document.querySelectorAll('#view-nav [data-snmode]').forEach(function(x){x.classList.remove('on');});
      m.classList.add('on'); SN.mode=m.getAttribute('data-snmode');
      document.getElementById('view-nav').dataset.navMode=SN.mode;
      snUpdateMeMode();
      snUpdateQuickDist();
      if(SN.origin&&SN.dest) snRoute();
    };
  });

  /* routing */
  /* shg-v59 — tap bounce (item #7). Wraps snRoute so the button flashes the
     scale keyframe even while Valhalla is still round-tripping. */
  s('#snGo').onclick=function(){ var g=s('#snGo'); g.classList.remove('sn-bounce'); void g.offsetWidth; g.classList.add('sn-bounce'); snRoute(); };
  s('#snClear').onclick=function(){
    SN.origin=SN.dest=SN.routeGeo=SN.routeCoords=SN.routeManeuvers=SN.routeCumKm=null;
    SN.routeTotalKm=0; SN.routeProgKm=0; SN.offRouteSince=0; SN.lastSpokenInstr=null;
    SN.arrived=false; SN.altRoutes=null;
    [SN.originMarker,SN.destMarker].forEach(function(m){if(m)m.remove();}); SN.originMarker=SN.destMarker=null;
    ['sn-rline','sn-rglow','sn-rdone','sn-alt0','sn-alt1'].forEach(function(l){if(map.getLayer(l))map.removeLayer(l);});
    ['sn-route-ahead','sn-route-done','sn-alt-0','sn-alt-1'].forEach(function(src){if(map.getSource(src))map.removeSource(src);});
    SN.presetMarkers.forEach(function(m){m.remove();}); SN.presetMarkers=[];
    s('#snFrom').value=''; s('#snTo').value=''; s('#snQ').value='';
    s('#snSummary').classList.remove('show'); s('#snBanner').classList.remove('show');
    var nc=s('#snNavCard');if(nc)nc.classList.remove('show');
    var ab=s('#snAltBar');if(ab)ab.classList.remove('show');
    var ar=s('#snArrival');if(ar)ar.classList.remove('show');
    if(typeof snTrailClear==='function') snTrailClear();
    if(typeof snFocus==='function') snFocus(false);   /* [SECTION 4] leaving a route exits Focus mode */
    snRefreshGo();
  };

  function snCostingOpts(){
    if(SN.mode==='motorcycle') return {motorcycle:{use_highways:0.85,use_ferry:0.1,use_tracks:0.05,use_hills:0.5,top_speed:90}};
    if(SN.mode==='bicycle') return {bicycle:{bicycle_type:'Hybrid',use_roads:0.5,use_hills:0.5}};
    if(SN.mode==='bus') return {bus:{use_highways:1,top_speed:90}};
    return null;
  }
  function snRoute(){
    if(!(SN.origin&&SN.dest)) return;
    if(typeof snTrailClear==='function') snTrailClear();   /* [MAP PRO] real directions replace the signature trail */
    s('#snGo').textContent='Routing…'; s('#snGo').disabled=true;
    var body={locations:[{lat:SN.origin.lat,lon:SN.origin.lng},{lat:SN.dest.lat,lon:SN.dest.lng}],
      costing:SN.mode,directions_options:{units:'kilometers'}};
    var _co=snCostingOpts(); if(_co) body.costing_options=_co;
    fetch('https://valhalla1.openstreetmap.de/route?json='+encodeURIComponent(JSON.stringify(body)))
      .then(function(r){if(!r.ok)throw new Error('http');return r.json();})
      .then(function(j){
        var trip=j.trip; if(!trip||trip.status!==0)throw new Error('noroute');
        var leg=trip.legs[0], coords=decodePoly(leg.shape);
        SN.routeGeo={type:'Feature',geometry:{type:'LineString',coordinates:coords}};
        SN.routeCoords=coords;
        SN.routeCumKm=buildCumDist(coords);
        SN.routeTotalKm=SN.routeCumKm[SN.routeCumKm.length-1]||0;
        SN.routeManeuvers=leg.maneuvers||[];
        SN.routeManeuvers.forEach(function(m){ m._distKm=SN.routeCumKm[Math.min(m.begin_shape_index,SN.routeCumKm.length-1)]||0; });
        SN.routeProgKm=0; SN.offRouteSince=0; SN.lastSpokenInstr=null;
        updateTraveledRoute(0);
        /* shg-v59 — sweep the freshly drawn route from origin to destination (item #7)
           so it doesn't just pop onto the map. Fires once per new route. */
        animateRouteDraw();
        var b=coords.reduce(function(bb,c){return bb.extend(c);},new maplibregl.LngLatBounds(coords[0],coords[0]));
        map.fitBounds(b,{padding:{top:110,bottom:300,left:60,right:60},duration:SN.lite?0:900, easing: snEaseOut, pitch:0, bearing:0});
        /* cinematic settle (5 Sep 2026): once the whole route is in view, tilt the
           camera a little so the road reads in perspective like a real navigator */
        if(!SN.lite){ map.once('moveend',function(){ try{ if(map.getPitch()<28) snEase({pitch:28,duration:1100}); }catch(e){} }); }
        snShowSummary(trip.summary,leg.maneuvers);
        /* [AUTO-ROUTE V4] collapse the setup card to a full-screen map and surface ETA on
           the peek bar with a one-tap "start navigation" affordance (2nd tap -> follow). */
        if(typeof snSheet==='function') snSheet('peek');
        if(typeof snPeekSet==='function') snPeekSet('🚌 '+snFmtTime(trip.summary.time)+' · '+trip.summary.length.toFixed(trip.summary.length<10?1:0)+' km','Tap to start navigation');
        if(typeof snFocus==='function') snFocus(true);   /* [SECTION 4] auto-enter clean Focus navigation */
        if(typeof snSaveLastRoute==='function') snSaveLastRoute();   /* [OFFLINE] persist route for offline reopen */
        SN.arrived=false;
        snFetchAlts();
      })
      .catch(function(){snToast('No route found — try another mode');})
      .finally(function(){s('#snGo').textContent='Get directions';snRefreshGo();});
  }

  function snFmtTime(sec){var m=Math.round(sec/60);if(m<60)return m+' min';return Math.floor(m/60)+'h '+(m%60)+'m';}
  function snManIcon(type){
    var m={1:'🚩',2:'🚩',3:'🚩',4:'🏁',5:'🏁',6:'🏁',7:'⬆️',8:'⬆️',17:'⬆️',22:'⬆️',
      9:'↗️',18:'↗️',20:'↗️',23:'↗️',10:'➡️',11:'➡️',16:'↖️',19:'↖️',21:'↖️',24:'↖️',
      15:'⬅️',14:'⬅️',12:'↩️',13:'↩️',25:'🔀',26:'🔄',27:'🔄',28:'⛴️',29:'⛴️'};
    return m[type]||'➡️';
  }
  function snShowSummary(sum,maneuvers){
    s('#snSTime').textContent=snFmtTime(sum.time);
    s('#snSDist').textContent=sum.length.toFixed(sum.length<10?1:0)+' km';
    var modeName={auto:'car',motorcycle:'motorcycle',bus:'bus',bicycle:'bicycle',pedestrian:'walking'}[SN.mode];
    s('#snSVia').textContent='by '+modeName;
    s('#snSteps').innerHTML=maneuvers.map(function(m){
      var d=m.length>=1?m.length.toFixed(1)+' km':Math.round(m.length*1000)+' m';
      return '<div class="sn-step"><span class="man">'+snManIcon(m.type)+'</span><div class="txt">'+
        (m.instruction||'')+'<small>'+d+' · '+snFmtTime(m.time)+'</small></div></div>';
    }).join('');
    s('#snSummary').classList.add('show');
  }
  function snFetchAlts(){
    if(!(SN.origin&&SN.dest))return;
    var body={locations:[{lat:SN.origin.lat,lon:SN.origin.lng},{lat:SN.dest.lat,lon:SN.dest.lng}],
      costing:SN.mode,directions_options:{units:'kilometers'},alternates:2};
    var _co=snCostingOpts(); if(_co) body.costing_options=_co;
    fetch('https://valhalla1.openstreetmap.de/route?json='+encodeURIComponent(JSON.stringify(body)))
      .then(function(r){return r.json();}).then(function(j){
        var alts=j.alternates;if(!alts||!alts.length)return;
        var origCoords=SN.routeCoords,origCum=SN.routeCumKm,origTotal=SN.routeTotalKm,origMan=SN.routeManeuvers;
        SN.altRoutes=[];
        alts.forEach(function(alt,idx){
          var leg=alt.trip.legs[0],coords=decodePoly(leg.shape);
          SN.altRoutes.push({coords:coords,summary:alt.trip.summary,maneuvers:leg.maneuvers});
          var srcId='sn-alt-'+idx,layId='sn-alt'+idx;
          var geo={type:'Feature',geometry:{type:'LineString',coordinates:coords}};
          if(map.getSource(srcId))map.getSource(srcId).setData(geo);
          else{
            map.addSource(srcId,{type:'geojson',data:geo});
            map.addLayer({id:layId,type:'line',source:srcId,layout:{'line-cap':'round','line-join':'round'},
              paint:{'line-color':'#9B7DC4','line-width':5,'line-opacity':.3,'line-dasharray':[4,3]}},
              map.getLayer('sn-rglow')?'sn-rglow':undefined);
          }
        });
        var bar=s('#snAltBar');if(!bar)return;
        bar.innerHTML='<button class="sn-altbtn on" data-snalt="-1"><b>Main</b>'+snFmtTime(j.trip.summary.time)+'</button>'
          +SN.altRoutes.map(function(a,i){return'<button class="sn-altbtn" data-snalt="'+i+'"><b>Alt '+(i+1)+'</b>'+snFmtTime(a.summary.time)+' · '+a.summary.length.toFixed(1)+' km</button>';}).join('');
        bar.classList.add('show');
        bar.querySelectorAll('.sn-altbtn').forEach(function(btn){
          btn.onclick=function(){
            bar.querySelectorAll('.sn-altbtn').forEach(function(x){x.classList.remove('on');});
            btn.classList.add('on');
            var ai=+btn.dataset.snalt;
            if(ai<0){SN.routeCoords=origCoords;SN.routeCumKm=origCum;SN.routeTotalKm=origTotal;SN.routeManeuvers=origMan;SN.routeProgKm=0;updateTraveledRoute(0);snShowSummary(j.trip.summary,j.trip.legs[0].maneuvers);}
            else{
              var alt=SN.altRoutes[ai];
              SN.routeCoords=alt.coords;SN.routeCumKm=buildCumDist(alt.coords);
              SN.routeTotalKm=SN.routeCumKm[SN.routeCumKm.length-1]||0;
              SN.routeManeuvers=alt.maneuvers||[];
              SN.routeManeuvers.forEach(function(m){m._distKm=SN.routeCumKm[Math.min(m.begin_shape_index,SN.routeCumKm.length-1)]||0;});
              SN.routeProgKm=0;updateTraveledRoute(0);snShowSummary(alt.summary,alt.maneuvers);
            }
          };
        });
      }).catch(function(){});
  }

  /* [MAP PRO] S Hari preset — signature animated corridor: saffron (India) → crimson
     (Nepal), split at the Rupaidiha border, with a flowing dash overlay and a bus that
     glides the full corridor. Pure client visual (SN_STOPS polyline) — no Valhalla, so
     it always renders. Respects prefers-reduced-motion. */
  var SN_BORDER_LNG=81.617;
  function snTrailClear(){
    if(SN.trailAnim!=null){ cancelAnimationFrame(SN.trailAnim); SN.trailAnim=null; }
    if(SN.presetBusAnim!=null){ cancelAnimationFrame(SN.presetBusAnim); SN.presetBusAnim=null; }
    if(SN.presetBusMarker){ SN.presetBusMarker.remove(); SN.presetBusMarker=null; }
    ['shg-trail-flow','shg-trail-np-l','shg-trail-in-l'].forEach(function(l){ if(map.getLayer(l)) map.removeLayer(l); });
    ['shg-trail-flow-src','shg-trail-np','shg-trail-in'].forEach(function(n){ if(map.getSource(n)) map.removeSource(n); });
  }
  function snShariTrail(){
    snTrailClear();
    var pts=SN_STOPS.map(function(st){ return [st.lng,st.lat]; });
    var bi=0; for(var i=0;i<SN_STOPS.length;i++){ if(SN_STOPS[i].n==='Rupaidiha'){ bi=i; break; } }
    if(bi<=0) bi=Math.max(1,pts.length-2);
    var inPts=pts.slice(0,bi+1), npPts=pts.slice(bi);
    function line(c){ return {type:'Feature',geometry:{type:'LineString',coordinates:c}}; }
    map.addSource('shg-trail-in',{type:'geojson',data:line(inPts)});
    map.addSource('shg-trail-np',{type:'geojson',data:line(npPts)});
    map.addSource('shg-trail-flow-src',{type:'geojson',data:line(pts)});
    map.addLayer({id:'shg-trail-in-l',type:'line',source:'shg-trail-in',
      layout:{'line-cap':'round','line-join':'round'},paint:{'line-color':'#FF9933','line-width':5,'line-opacity':.92}});
    map.addLayer({id:'shg-trail-np-l',type:'line',source:'shg-trail-np',
      layout:{'line-cap':'round','line-join':'round'},paint:{'line-color':'#DC143C','line-width':5,'line-opacity':.92}});
    map.addLayer({id:'shg-trail-flow',type:'line',source:'shg-trail-flow-src',
      layout:{'line-cap':'round'},paint:{'line-color':'#FFFFFF','line-width':2.4,'line-opacity':.85,'line-dasharray':[0,4,3]}});
    var reduce=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if(!reduce){
      var seq=[[0,4,3],[0.5,4,2.5],[1,4,2],[1.5,4,1.5],[2,4,1],[2.5,4,0.5],[3,4,0],
               [0,0.5,3,3.5],[0,1,3,3],[0,1.5,3,2.5],[0,2,3,2],[0,2.5,3,1.5],[0,3,3,1],[0,3.5,3,0.5]];
      var step=0;
      (function flow(ts){
        var ns=parseInt((ts/55)%seq.length,10)||0;
        if(ns!==step && map.getLayer('shg-trail-flow')){ map.setPaintProperty('shg-trail-flow','line-dasharray',seq[step]); step=ns; }
        SN.trailAnim=requestAnimationFrame(flow);
      })(0);
    }
    var bel=document.createElement('div'); bel.className='sn-bus-m'; bel.textContent='🚌';
    SN.presetBusMarker=new maplibregl.Marker({element:bel}).setLngLat(pts[0]).addTo(map);
    var cum=[0]; for(var j=1;j<pts.length;j++) cum.push(cum[j-1]+haversineKm(pts[j-1][1],pts[j-1][0],pts[j][1],pts[j][0]));
    var total=cum[cum.length-1]||1;
    if(!reduce){
      var dur=22000, t0=0;
      (function drive(ts){
        if(!t0) t0=ts; var d=(((ts-t0)%dur)/dur)*total, seg=1;
        while(seg<pts.length && cum[seg]<d) seg++;
        var a=pts[seg-1], b=pts[Math.min(seg,pts.length-1)];
        var span=Math.max(1e-6, cum[Math.min(seg,pts.length-1)]-cum[seg-1]);
        var f=Math.min(1,(d-cum[seg-1])/span);
        if(SN.presetBusMarker) SN.presetBusMarker.setLngLat([a[0]+(b[0]-a[0])*f, a[1]+(b[1]-a[1])*f]);
        SN.presetBusAnim=requestAnimationFrame(drive);
      })(0);
    }
    var lons=pts.map(function(p){return p[0];}), lats=pts.map(function(p){return p[1];});
    map.fitBounds([[Math.min.apply(null,lons),Math.min.apply(null,lats)],[Math.max.apply(null,lons),Math.max.apply(null,lats)]],
      {padding:{top:90,bottom:220,left:50,right:50},duration:1200});
  }
  s('#snPreset').onclick=function(){
    s('#snClear').click();
    var A=SN_STOPS[0],B=SN_STOPS[SN_STOPS.length-1];
    snSetOrigin(A.lng,A.lat,A.n); snSetDest(B.lng,B.lat,B.n);
    SN_STOPS.forEach(function(st,idx){
      if(idx===0||idx===SN_STOPS.length-1) return;
      var el=document.createElement('div');
      el.style.cssText='width:13px;height:13px;border-radius:50%;background:#FFD700;border:2px solid #7B2FBE;box-shadow:0 1px 5px rgba(0,0,0,.5);cursor:pointer';
      var kmA=haversineKm(A.lat,A.lng,st.lat,st.lng);
      var side=st.lng>=SN_BORDER_LNG?'🇳🇵 Nepal':'🇮🇳 India';
      var html='<b>'+(st.f?st.f+' ':'')+snEsc(st.n)+'</b><br><small>'+side+' · ~'+Math.round(kmA)+' km from Ahmedabad</small>';
      var m=new maplibregl.Marker({element:el}).setLngLat([st.lng,st.lat])
        .setPopup(new maplibregl.Popup({offset:14,closeButton:false,maxWidth:'220px'}).setHTML(html)).addTo(map);
      /* 13 Sep 2026: a tapped stop also opens the route guide on that stop
         (pickup time, distance, border) in the sheet (17-pwa.js). */
      el.addEventListener('click',function(){ try{ if(window.SHGRoute) window.SHGRoute.focus(st.n); }catch(e){} });
      SN.presetMarkers.push(m);
    });
    snShariTrail();
    document.querySelectorAll('#view-nav [data-snmode]').forEach(function(x){ x.classList.toggle('on', x.getAttribute('data-snmode')==='bus'); });
    SN.mode='bus'; snUpdateMeMode(); snUpdateQuickDist();
    if(typeof snPeekSet==='function') snPeekSet('🚌 S Hari Route · Surat ⇄ Rupaidiha','Tap a stop · or Get directions for turn-by-turn');
    if(typeof snSheet==='function') snSheet('half');
    snToast('🚌 S Hari route loaded · 🇮🇳 India → 🇳🇵 Nepal');
  };

  /* temples with Hindi details */
  function snTempleCard(i,photo){
    var t=SN_TEMPLES[i];
    var media=photo==='loading'?'<div style="height:64px;display:flex;align-items:center;justify-content:center;color:#a99cc4;font-size:12px;border-radius:10px;background:#f3f0f9;margin-bottom:8px">Loading…</div>'
      :(photo?'<img class="tc-img" src="'+photo+'" alt="'+snEsc(t.n)+'" onerror="this.style.display=\'none\'" style="width:100%;height:128px;object-fit:cover;border-radius:10px;margin-bottom:8px;background:#efeaf6">':'');
    return '<div class="tc">'+media+'<h3>🛕 '+snEsc(t.n)+'</h3>'
      +'<span class="tc-deity">'+snEsc(t.deity)+'</span>'
      +'<p>'+snEsc(t.hi||t.special)+'</p>'
      +'<p style="font-size:11.5px;color:#777;margin-top:-4px;margin-bottom:8px">'+snEsc(t.special)+'</p>'
      +'<button class="tc-nav" onclick="snTempleNav('+i+')">🧭 Navigate here · यहाँ नेविगेट करें</button></div>';
  }
  function snLoadCard(i,popup){
    popup.setHTML(snTempleCard(i,'loading'));
    fetch('https://en.wikipedia.org/api/rest_v1/page/summary/'+encodeURIComponent((SN_TEMPLES[i].wiki||'').replace(/ /g,'_')))
      .then(function(r){return r.json();}).then(function(j){popup.setHTML(snTempleCard(i,j.thumbnail?j.thumbnail.source:null));})
      .catch(function(){popup.setHTML(snTempleCard(i,null));});
  }
  SN_TEMPLES.forEach(function(t,i){
    var el=document.createElement('div');
    var snDC=((t.deity||'')+' '+(t.n||''));
    var snCat=/Shiva|शिव|Jyotir|ज्योति/i.test(snDC)?'shiva'
      :/Shakti|शक्ति|Parvati|पार्वती|Vaishno|Bhagwati|भगवती|Manakamana|Meenakshi/i.test(snDC)?'shakti'
      :/Ram|राम|Sita|सीता|Janaki|Hanuman|हनुमान/i.test(snDC)?'ram'
      :/Krishna|कृष्ण|Dwarka|Jagannath|Shrinathji|Govind/i.test(snDC)?'krishna':'';
    el.className='sn-temple-m'+(snCat?' sn-cat-'+snCat:'');
    el.innerHTML='<span class="sn-temple-ico"><svg viewBox="0 0 26 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path class="sn-pin-body" d="M13 1C6.4 1 1 6.3 1 12.9 1 21.5 13 31 13 31s12-9.5 12-18.1C25 6.3 19.6 1 13 1z" stroke="rgba(110,36,0,.28)" stroke-width=".7"/><path class="sn-pin-glyph" fill-rule="evenodd" d="M13 4.9 14 6.5 17.2 12 16.4 12 16.4 17.6 17.6 17.6 17.6 18.8 8.4 18.8 8.4 17.6 9.6 17.6 9.6 12 8.8 12 12 6.5Z M11.8 18.8 11.8 15Q11.8 13.4 13 13.4 14.2 13.4 14.2 15L14.2 18.8Z"/></svg></span>';
    var popup=new maplibregl.Popup({offset:16,maxWidth:'260px'});
    popup.on('open',function(){snLoadCard(i,popup);});
    var m=new maplibregl.Marker({element:el}).setLngLat([t.lng,t.lat]).setPopup(popup);
    if(SN.templesOn) m.addTo(map);
    SN.templeMarkers.push(m);
  });
  /* temple visual hierarchy — smaller flags for whole-country browsing, full size
     once zoomed to city/district level, so 29 markers don't clutter a national view
     (they're plain DOM markers, so this is our own transform, not a vendor style layer) */
  function updTempleScale(){
    var z=map.getZoom(), sc=z<=6?0.75:(z>=10?1.1:0.75+(z-6)/4*0.35);
    SN.templeMarkers.forEach(function(m){
      var ic=m.getElement().querySelector('.sn-temple-ico'); if(ic) ic.style.transform='scale('+sc.toFixed(2)+')';
    });
  }
  updTempleScale(); map.on('zoom',updTempleScale);
  window.snTempleNav=function(i){ var t=SN_TEMPLES[i]; snSetDest(t.lng,t.lat,t.n);
    document.querySelector('#view-nav [data-snmode="auto"]').click(); snToast('🧭 Routing to '+t.n); };
  s('#snTemples').onclick=function(){
    SN.templesOn=!SN.templesOn;
    SN.templeMarkers.forEach(function(m){ SN.templesOn?m.addTo(map):m.remove(); });
    s('#snTemples').classList.toggle('on',SN.templesOn); try{ if(map.getLayer('sn-pilgrimline')) map.setLayoutProperty('sn-pilgrimline','visibility',SN.templesOn?'visible':'none'); }catch(e){} snToast(SN.templesOn?'🛕 Sanatan Mode on':'Sanatan Mode off');
  };

  /* home marker */
  var hel=document.createElement('div'); hel.className='sn-home-m';
  hel.innerHTML='<div class="sn-home-ico">🏠</div><div class="sn-home-lbl">'+snEsc(SN_HOME.label)+'</div>';
  new maplibregl.Marker({element:hel,anchor:'bottom'}).setLngLat([SN_HOME.lng,SN_HOME.lat]).addTo(map);
  var updHome=function(){ hel.style.display=map.getZoom()>=13?'flex':'none'; };
  updHome(); map.on('zoom',updHome);

  /* S Hari Global head office, Mehsana — branded marker, always visible (5 Sep 2026).
     Popup: address, call, WhatsApp, in-app directions, Google Maps. */
  (function(){
    var C=(typeof CONFIG!=='undefined'&&CONFIG)||{}, co=C.company||{};
    var OFF={lat:23.588,lng:72.369,name:co.name||'S Hari Global Pvt Ltd',
      addr:co.address||'Near Shilpa Garage, Silver Complex, Mehsana - 384002, Gujarat',
      tel:C.phone||'+91 91048 01507', wa:C.adminWhatsApp||'919104801507'};
    var oel=document.createElement('div'); oel.className='sn-office-m';
    oel.innerHTML='<div class="sn-office-ring"></div><div class="sn-office-pin">🚌</div><div class="sn-office-lbl">S Hari Global · Mehsana</div>';
    var telDigits=String(OFF.tel).replace(/[^\d+]/g,'');
    var gmaps='https://www.google.com/maps/dir/?api=1&destination='+OFF.lat+','+OFF.lng;
    var html='<div class="sn-office-pop"><div class="op-hd"><span class="op-logo">🚌</span><div><b>'+snEsc(OFF.name)+'</b><small>Head office · Mehsana, Gujarat</small></div></div>'
      +'<div class="op-addr">📍 '+snEsc(OFF.addr)+'</div>'
      +'<div class="op-acts"><a class="op-btn" href="tel:'+snEsc(telDigits)+'">📞 Call</a>'
      +'<a class="op-btn" href="https://wa.me/'+snEsc(OFF.wa)+'" target="_blank" rel="noopener">💬 WhatsApp</a>'
      +'<button type="button" class="op-btn op-dir" onclick="window.snOfficeNav&&window.snOfficeNav()">🧭 Directions</button>'
      +'<a class="op-btn" href="'+gmaps+'" target="_blank" rel="noopener">🗺️ Google Maps</a></div></div>';
    var pop=new maplibregl.Popup({offset:[0,-38],closeButton:true,maxWidth:'290px',className:'sn-office-popup'}).setHTML(html);
    new maplibregl.Marker({element:oel,anchor:'bottom'}).setLngLat([OFF.lng,OFF.lat]).setPopup(pop).addTo(map);
    window.snOfficeNav=function(){
      try{ pop.remove(); }catch(e){}
      snSetDest(OFF.lng,OFF.lat,OFF.name+' (Mehsana office)');
      if(SN.gpsPos&&!SN.origin) snSetOrigin(SN.gpsPos.lng,SN.gpsPos.lat,'My location');
      if(SN.origin) snRoute();
      else { snToast('📍 Tap the location button first, then Directions'); snEase({center:[OFF.lng,OFF.lat],zoom:14,duration:900}); }
      if(typeof snSheet==='function') snSheet('half');
    };
  })();

  /* quick open from cached position */
  try{ var c=JSON.parse(localStorage.getItem('shari:lastpos')||'null');
    if(c&&Date.now()-c.ts<7*864e5){
      SN.gpsPos={lng:c.lng,lat:c.lat,acc:c.acc}; ensureMe();
      SN.meMarker.setLngLat([c.lng,c.lat]);
      s('#snHud').classList.remove('off'); s('#snCoord').textContent=c.lat.toFixed(4)+', '+c.lng.toFixed(4);
    }
  }catch(e){}
  /* auto-request GPS as soon as the nav view opens (unless the user has
     explicitly denied it before) so "my location" is ready to measure
     distance from the instant a place is picked — no extra tap needed */
  if(navigator.permissions&&navigator.permissions.query){
    navigator.permissions.query({name:'geolocation'}).then(function(p){if(p.state!=='denied')s('#snLocate').click();})
      .catch(function(){ if(navigator.geolocation) s('#snLocate').click(); });
  }else if(navigator.geolocation){
    s('#snLocate').click();
  }

  /* smooth bus marker interpolation — shg-v59: ease-out (was ease-in-out) so the
     bus decelerates into each new fix the way a real vehicle would, and the
     duration is a hair longer since shared-position fixes come slower than local GPS. */
  function snAnimateBus(lng,lat,spd,head){
    if(!SN.busMarker)return;
    var from=SN.busDisp||SN.busMarker.getLngLat();
    var big=Math.abs(from.lng-lng)>.05||Math.abs(from.lat-lat)>.05;
    SN.busTarget={lng:lng,lat:lat,spd:(spd!=null&&!isNaN(spd))?spd:0,head:(head!=null&&!isNaN(head))?head:null,ts:Date.now()};
    snBusTrailPush(lng,lat);
    if(big||SN.lite){ SN.busDisp={lng:lng,lat:lat}; SN.busMarker.setLngLat([lng,lat]); return; }
    snMotionKick();   /* the motion engine glides it (with dead reckoning) from here */
  }
  /* Glowing fading trail behind the live bus — the last ~40 fixes as a
     line-gradient (transparent tail -> orange head). Not drawn in lite. */
  function snBusTrailEnsure(){
    if(SN.lite||!SN.map) return;
    try{
      if(!map.getSource('sn-bus-trail')){
        map.addSource('sn-bus-trail',{type:'geojson',lineMetrics:true,data:{type:'Feature',geometry:{type:'LineString',coordinates:[]}}});
        map.addLayer({id:'sn-bus-trail-glow',type:'line',source:'sn-bus-trail',layout:{'line-cap':'round','line-join':'round'},
          paint:{'line-width':10,'line-blur':4,'line-gradient':['interpolate',['linear'],['line-progress'],0,'rgba(255,122,26,0)',1,'rgba(255,122,26,0.55)']}});
        map.addLayer({id:'sn-bus-trail-line',type:'line',source:'sn-bus-trail',layout:{'line-cap':'round','line-join':'round'},
          paint:{'line-width':3.5,'line-gradient':['interpolate',['linear'],['line-progress'],0,'rgba(255,255,255,0)',0.5,'rgba(255,170,90,0.7)',1,'#FF7A1A']}});
      }
      snBusTrailUpdate();
    }catch(e){}
  }
  function snBusTrailPush(lng,lat){
    if(SN.lite) return;
    var t=SN.busTrail, last=t[t.length-1];
    if(last&&Math.abs(last[0]-lng)<1e-6&&Math.abs(last[1]-lat)<1e-6) return;
    t.push([lng,lat]); if(t.length>40) t.splice(0,t.length-40);
    snBusTrailUpdate();
  }
  function snBusTrailUpdate(){
    try{ var src=SN.map&&map.getSource('sn-bus-trail'); if(!src) return;
      var c=SN.busTrail.length>=2?SN.busTrail:[]; src.setData({type:'Feature',geometry:{type:'LineString',coordinates:c}}); }catch(e){}
  }

  /* arrival close */
  var arrBtn=s('#snArrClose');if(arrBtn)arrBtn.onclick=function(){
    var ae=s('#snArrival');if(ae)ae.classList.remove('show');
  };

  /* ================= [OFFLINE] connectivity badge + save-map + last-route =================
     Runtime tile caching is automatic in sw.js as the map is viewed; this layer adds the
     visible offline badge, an explicit "save this route/area" pre-download, and last-route
     persistence so a planned trip stays usable after the network drops. All best-effort. */
  (function(){
    var nvEl=document.getElementById('view-nav');
    function setOnline(){ if(nvEl) nvEl.classList.toggle('is-offline', !navigator.onLine); }
    setOnline();
    window.addEventListener('online', function(){ setOnline(); snToast('🌐 Back online'); });
    window.addEventListener('offline', function(){ setOnline(); snToast('⚡ Offline — saved map & GPS still work'); });

    /* resolve the active style's vector tile URL template (inline tiles or a TileJSON url) */
    async function snTileTemplate(){
      try{
        var st=map.getStyle(), srcs=(st&&st.sources)||{};
        for(var id in srcs){ var sc=srcs[id];
          if(sc&&sc.type==='vector'){
            if(sc.tiles&&sc.tiles.length) return sc.tiles[0];
            if(sc.url){ var r=await fetch(sc.url); if(r.ok){ var tj=await r.json(); if(tj.tiles&&tj.tiles.length) return tj.tiles[0]; } }
          }
        }
      }catch(e){}
      return null;
    }
    function lon2x(lon,z){ return Math.floor((lon+180)/360*Math.pow(2,z)); }
    function lat2y(lat,z){ var r=lat*Math.PI/180; return Math.floor((1-Math.log(Math.tan(r)+1/Math.cos(r))/Math.PI)/2*Math.pow(2,z)); }
    function clampT(v,z){ var m=Math.pow(2,z); return Math.max(0,Math.min(m-1,v)); }
    function bboxForSave(){
      if(SN.routeCoords&&SN.routeCoords.length){
        var w=180,e=-180,s=90,n=-90;
        SN.routeCoords.forEach(function(c){ if(c[0]<w)w=c[0]; if(c[0]>e)e=c[0]; if(c[1]<s)s=c[1]; if(c[1]>n)n=c[1]; });
        return {w:w,e:e,s:s,n:n,route:true};
      }
      var b=map.getBounds();
      return {w:b.getWest(),e:b.getEast(),s:b.getSouth(),n:b.getNorth(),route:false};
    }
    function buildTileUrls(tmpl,bb,zmin,zmax,cap){
      var urls=[];
      for(var z=zmin;z<=zmax;z++){
        var x0=clampT(lon2x(bb.w,z),z),x1=clampT(lon2x(bb.e,z),z);
        var y0=clampT(lat2y(bb.n,z),z),y1=clampT(lat2y(bb.s,z),z);
        for(var x=x0;x<=x1;x++){ for(var y=y0;y<=y1;y++){
          urls.push(tmpl.replace('{z}',z).replace('{x}',x).replace('{y}',y));
          if(urls.length>=cap) return urls;
        } }
      }
      return urls;
    }
    var saveBtn=s('#snOffSave');
    if(saveBtn) saveBtn.onclick=async function(){
      if(!('serviceWorker' in navigator)||!navigator.serviceWorker.controller){ snToast('⚡ Offline saving works once the app is live (https)'); return; }
      var tmpl=await snTileTemplate();
      if(!tmpl){ snToast('Map not ready — pan once, then try Save again'); return; }
      var bb=bboxForSave();
      var zmin=5, zmax=bb.route?11:Math.min(13,Math.round(map.getZoom())+1), cap=700;
      var urls=buildTileUrls(tmpl,bb,zmin,zmax,cap);
      if(!urls.length){ snToast('Nothing to save here'); return; }
      saveBtn.classList.add('saving');
      snToast('⬇️ Saving '+urls.length+(bb.route?' route':' area')+' map tiles for offline…');
      navigator.serviceWorker.controller.postMessage({type:'shg-prefetch',urls:urls});
    };
    if(navigator.serviceWorker) navigator.serviceWorker.addEventListener('message',function(ev){
      var d=ev.data||{};
      if(d.type==='shg-prefetch-done'){ if(saveBtn) saveBtn.classList.remove('saving'); snToast('✅ Saved '+d.count+' tiles — this area now works offline'); }
    });

    /* last-route persistence — save the geometry Valhalla already returned so a planned
       trip can be redrawn (and turn-by-turn followed) after going offline, no re-routing. */
    window.snSaveLastRoute=function(){
      try{ if(!SN.routeCoords) return;
        localStorage.setItem('shg:lastroute', JSON.stringify({
          origin:SN.origin, dest:SN.dest, mode:SN.mode,
          coords:SN.routeCoords, cum:SN.routeCumKm, total:SN.routeTotalKm, man:SN.routeManeuvers,
          sum:{time:SN.routeTotalKm/(SN_SPD[SN.mode]||40)*3600, length:SN.routeTotalKm}, ts:Date.now()
        }));
      }catch(e){}
    };
    window.snRestoreLastRoute=function(){
      try{
        var j=JSON.parse(localStorage.getItem('shg:lastroute')||'null');
        if(!j||!j.coords||!j.coords.length) return false;
        if(j.mode) SN.mode=j.mode;
        SN.routeCoords=j.coords; SN.routeCumKm=j.cum; SN.routeTotalKm=j.total; SN.routeManeuvers=j.man||[]; SN.routeProgKm=0;
        if(j.origin) snSetOrigin(j.origin.lng,j.origin.lat,j.origin.label);
        if(j.dest) snSetDest(j.dest.lng,j.dest.lat,j.dest.label);
        if(SN._autoRouteT){ clearTimeout(SN._autoRouteT); SN._autoRouteT=null; }   /* don't try to re-route offline */
        updateTraveledRoute(0);
        if(j.sum) snShowSummary(j.sum,SN.routeManeuvers);
        var b=SN.routeCoords.reduce(function(bb,c){return bb.extend(c);},new maplibregl.LngLatBounds(SN.routeCoords[0],SN.routeCoords[0]));
        map.fitBounds(b,{padding:{top:110,bottom:200,left:50,right:50},duration:600});
        return true;
      }catch(e){ return false; }
    };
    /* opened offline → silently bring back the last planned route so the trip is still usable */
    if(!navigator.onLine){
      setTimeout(function(){ if(window.snRestoreLastRoute&&snRestoreLastRoute()) snToast('⚡ Offline — your last saved route is restored'); },800);
    }
  })();

  map.on('load',function(){
    snToast('🕉️ S Hari Nav ready · 📍 for live location'); map.resize();
    /* shg-v59 — fade the map canvas in from opacity:0 (item #5). Guarded so a
       re-load (theme swap) only fades in the first time. */
    if(!SN.mapReady){ SN.mapReady=true; var mn=document.getElementById('sn-map'); if(mn) mn.classList.add('sn-map-ready'); }
  });
  var loader=$('#snLoad'); if(loader) loader.style.display='none';
  SN.ready=true;
  // [MAP PRO] Bug 3 — keep the GL canvas matched to the viewport on rotate/resize
  // (only while the navigator view is actually on screen), and one resize now that
  // the container is guaranteed visible.
  if(!SN._resizeBound){
    SN._resizeBound=true;
    window.addEventListener('resize',function(){
      var nv=document.getElementById('view-nav');
      if(SN.map && nv && nv.classList.contains('active')) requestAnimationFrame(function(){ SN.map.resize(); });
    });
  }
  requestAnimationFrame(function(){ if(SN.map) SN.map.resize(); });
}
