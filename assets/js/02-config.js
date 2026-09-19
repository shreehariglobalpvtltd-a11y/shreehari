
if (!CanvasRenderingContext2D.prototype.roundRect) {
  CanvasRenderingContext2D.prototype.roundRect = function(x,y,w,h,r) {
    if (typeof r === 'number') r = [r,r,r,r]; r = r||[0,0,0,0];
    this.moveTo(x+r[0],y); this.lineTo(x+w-r[1],y); this.arcTo(x+w,y,x+w,y+r[1],r[1]);
    this.lineTo(x+w,y+h-r[2]); this.arcTo(x+w,y+h,x+w-r[2],y+h,r[2]);
    this.lineTo(x+r[3],y+h); this.arcTo(x,y+h,x,y+h-r[3],r[3]);
    this.lineTo(x,y+r[0]); this.arcTo(x,y,x+r[0],y,r[0]); this.closePath();
  };
}
/* ================================================================
   [JS] 1. CONFIG — the ONE place a beginner edits first.
   Everything here can also be changed later from Admin → Settings
   (settings are stored, and override these defaults).
================================================================ */
/* ----------------------------------------------------------------
   // ROADMAP — requires backend (Phase 3). Do NOT half-build these
   // client-side; they need real servers/providers to be honest:
   //  · Real-time GPS bus tracking with live traffic & geofencing
   //    (driver-side app + mapping API + server feed).
   //  · Payment gateway integration — auto wallet top-up, automatic
   //    commission payouts (licensed payment processor required).
   //  · Automated SMS / WhatsApp / Email sending (Twilio, WhatsApp
   //    Business API, etc. + a server to call them from).
   //  · AI chatbot (API-backed assistant; cannot be embedded statically).
   //  · True JWT-based server auth & KYC document verification
   //    (backend + storage + verification workflow).
   //  · Native Driver / Passenger / Admin apps (separate codebases).
   // What exists today (this file) is fully offline-first: the manual
   // "Track my bus" updater (admin-entered positions) and the
   // passenger-side "distance to boarding point" calculator are real,
   // honest client-side features — everything above is aspirational.
---------------------------------------------------------------- */
/* === MAP PRO UPGRADE ============================================================
   MAP PRO CONFIG — edit these to enable premium features. An empty googleMapsApiKey
   keeps the free Photon + Nominatim fallback (works end-to-end with no key). */
var MAP_CONFIG = {
  googleMapsApiKey: '',                                    // ← paste a Google Maps JS+Places key to switch search to Google
  valhallaEndpoint: 'https://valhalla1.openstreetmap.de',  // existing turn-by-turn router
  defaultCenter: { lat: 25.5, lng: 79.5 },                 // midpoint of the India–Nepal corridor
  defaultZoom: 6,
  indiaNepalBounds: { sw: [6.0, 68.0], ne: [36.5, 97.5] }, // [lat,lng] — restrict geocoding to IN+NP
  restrictCountries: ['IN', 'NP']
};

/* [MAP PRO] Dual geocoder. window.mapGeocode(q,limit) -> Promise<{results,provider}>.
   result: { shortName, displayName, localName, lat, lng, provider, placeId?, _resolve? } */
(function(){
  var B = MAP_CONFIG.indiaNepalBounds;
  var PHOTON_BBOX = B.sw[1] + ',' + B.sw[0] + ',' + B.ne[1] + ',' + B.ne[0]; // minLon,minLat,maxLon,maxLat
  function devanagari(s){ return /[ऀ-ॿ]/.test(s || '') ? s : ''; }

  function photon(q, limit){
    var url = 'https://photon.komoot.io/api/?q=' + encodeURIComponent(q) + '&limit=' + limit + '&lang=en&bbox=' + PHOTON_BBOX;
    return fetch(url, { referrerPolicy:'origin' }).then(function(r){ return r.json(); }).then(function(j){
      return ((j && j.features) || []).map(function(f){
        var p = f.properties || {}, c = (f.geometry && f.geometry.coordinates) || [];
        var seen = {}, disp = [p.name,p.street,p.district,p.city,p.county,p.state,p.country]
          .filter(function(x){ return x && !seen[x] && (seen[x]=1); });
        return { shortName:p.name || disp[0] || q, displayName:disp.join(', '),
                 localName:'', lat:+c[1], lng:+c[0], provider:'Photon' };
      }).filter(function(s){ return isFinite(s.lat) && isFinite(s.lng); });
    }).catch(function(){ return []; });
  }

  function nominatim(q, limit){
    var url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&namedetails=1&limit='
      + limit + '&countrycodes=' + MAP_CONFIG.restrictCountries.join(',').toLowerCase()
      + '&accept-language=en&q=' + encodeURIComponent(q);
    return fetch(url, { referrerPolicy:'origin' }).then(function(r){ return r.json(); }).then(function(list){
      return (Array.isArray(list) ? list : []).map(function(p){
        var nd = p.namedetails || {};
        return { shortName:p.name || (p.display_name||'').split(',')[0] || q,
                 displayName:p.display_name || '',
                 localName:devanagari(nd['name:ne']) || devanagari(nd['name:hi']) || devanagari(nd.name) || '',
                 lat:+p.lat, lng:+p.lon, provider:'OpenStreetMap' };
      }).filter(function(s){ return isFinite(s.lat) && isFinite(s.lng); });
    }).catch(function(){ return []; });
  }

  function dedupe(list, limit){
    var out = [];
    list.forEach(function(s){
      var dup = out.some(function(o){
        return haversineKm(o.lat,o.lng,s.lat,s.lng) < 0.15
          && (o.shortName||'').toLowerCase() === (s.shortName||'').toLowerCase();
      });
      if(!dup) out.push(s);
    });
    return out.slice(0, limit);
  }

  function freeGeocode(q, limit){
    return Promise.all([ photon(q,limit), nominatim(q,limit) ]).then(function(res){
      var merged = dedupe(res[0].concat(res[1]), limit);
      merged.forEach(function(m){                    // graft a Devanagari alias from a nearby OSM hit
        if(m.localName) return;
        for(var i=0;i<res[1].length;i++){
          if(res[1][i].localName && haversineKm(m.lat,m.lng,res[1][i].lat,res[1][i].lng) < 1){ m.localName = res[1][i].localName; break; }
        }
      });
      return { results:merged, provider:'via OpenStreetMap' };
    });
  }

  /* Google Places (opt-in). Loaded lazily only when a key is set. */
  var G = { loaded:false, loading:false, svc:null, places:null, token:null, waiters:[] };
  function loadGoogle(cb){
    if(G.loaded){ cb(true); return; }
    G.waiters.push(cb);
    if(G.loading) return;
    G.loading = true;
    window.__shgGmapsReady = function(){
      try{
        G.svc = new google.maps.places.AutocompleteService();
        G.places = new google.maps.places.PlacesService(document.createElement('div'));
        G.token = new google.maps.places.AutocompleteSessionToken();
        G.loaded = true;
      }catch(e){ G.loaded = false; }
      G.waiters.forEach(function(w){ w(G.loaded); }); G.waiters = [];
    };
    var sc = document.createElement('script');
    sc.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(MAP_CONFIG.googleMapsApiKey) + '&libraries=places&callback=__shgGmapsReady';
    sc.async = true; sc.defer = true;
    sc.onerror = function(){ G.loading = false; G.waiters.forEach(function(w){ w(false); }); G.waiters = []; };
    document.head.appendChild(sc);
  }
  function googleGeocode(q, limit){
    return new Promise(function(resolve){
      loadGoogle(function(ok){
        if(!ok || !G.svc){ resolve(null); return; }
        G.svc.getPlacePredictions({
          input:q, sessionToken:G.token,
          componentRestrictions:{ country: MAP_CONFIG.restrictCountries.map(function(c){ return c.toLowerCase(); }) }
        }, function(preds, status){
          if(status !== google.maps.places.PlacesServiceStatus.OK || !preds){ resolve({ results:[], provider:'via Google' }); return; }
          resolve({ provider:'via Google', results: preds.slice(0,limit).map(function(pr){
            return {
              shortName: pr.structured_formatting ? pr.structured_formatting.main_text : pr.description,
              displayName: pr.description, localName:'', lat:null, lng:null,
              provider:'Google', placeId:pr.place_id,
              _resolve: function(){ return new Promise(function(res){
                G.places.getDetails({ placeId:pr.place_id, fields:['geometry','formatted_address','name'], sessionToken:G.token }, function(det, st){
                  G.token = new google.maps.places.AutocompleteSessionToken();
                  if(st === google.maps.places.PlacesServiceStatus.OK && det && det.geometry)
                    res({ lat:det.geometry.location.lat(), lng:det.geometry.location.lng() });
                  else res(null);
                });
              }); }
            };
          }) });
        });
      });
    });
  }

  window.mapGeocode = function(q, limit){
    limit = limit || 6;
    if(MAP_CONFIG.googleMapsApiKey){
      return googleGeocode(q, limit).then(function(g){ return (g && g.results && g.results.length) ? g : freeGeocode(q, limit); });
    }
    return freeGeocode(q, limit);
  };
})();

/* [MAP PRO] createLocationSearchInput(inputEl, {onSelect, placeholder, historyKey,
   icon, debounceMs, maxResults}) — one Google-Maps-style search box, reused by the
   navigator (#snQ/#snFrom/#snTo) and the full map (#fmSearchInput).
   onSelect receives { lat, lng, displayName, shortName, localName, provider }.
   Returns { input, setValue, clear, focus, destroy }. */
function createLocationSearchInput(inputEl, options){
  if(!inputEl) return null;
  if(inputEl.__locSearch) return inputEl.__locSearch;
  options = options || {};
  /* shg-v59 — debounce bumped 250→300ms (item #3): tighter than typical typing
     bursts (~150ms/char) so autocomplete waits for a real pause, while a
     one-shot paste still fires within one 300ms window. */
  var debounceMs = options.debounceMs || 300, maxResults = options.maxResults || 6;
  var histKey = options.historyKey || '', iconFn = options.icon || function(){ return '📍'; };
  var onSelect = options.onSelect || function(){};
  if(options.placeholder) inputEl.setAttribute('placeholder', options.placeholder);
  inputEl.removeAttribute('readonly'); inputEl.setAttribute('autocomplete','off');
  inputEl.setAttribute('role','combobox'); inputEl.setAttribute('aria-autocomplete','list'); inputEl.setAttribute('aria-expanded','false');

  var dd = document.createElement('div');
  dd.className = 'locss-dd'; dd.setAttribute('role','listbox'); dd.style.display = 'none';
  document.body.appendChild(dd);

  var items = [], active = -1, t = null, seq = 0, open = false;

  function esc(v){ return String(v==null?'':v).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  function hist(){ if(!histKey) return []; try{ return (JSON.parse(localStorage.getItem(histKey)||'[]')||[]).map(function(x){
      return { shortName:x.shortName||x.label||'', displayName:x.displayName||x.label||'', localName:x.localName||'', lat:x.lat, lng:x.lng, icon:x.icon||'📍' }; }); }catch(e){ return []; } }
  function pushHist(r){ if(!histKey) return; var h = hist().filter(function(x){ return x.displayName!==r.displayName; });
    h.unshift({ shortName:r.shortName, displayName:r.displayName, localName:r.localName||'', lat:r.lat, lng:r.lng, icon:iconFn((r.shortName||'')+' '+(r.displayName||'')) });
    if(h.length>8) h = h.slice(0,8); try{ localStorage.setItem(histKey, JSON.stringify(h)); }catch(e){} }
  function clearHist(){ if(histKey){ try{ localStorage.removeItem(histKey); }catch(e){} } }

  function place(){
    var r = inputEl.getBoundingClientRect();
    dd.style.left = r.left + 'px'; dd.style.top = (r.bottom + 6) + 'px'; dd.style.width = r.width + 'px';
    dd.style.maxHeight = Math.max(150, Math.min(360, window.innerHeight - r.bottom - 16)) + 'px';
  }
  /* shg-v59 — show/hide toggles a class so CSS can fade+slide the dropdown in
     (item #3). We flip display first (so measurement/layout is correct), pin the
     initial state (no .locss-open → opacity 0, translateY -6px), force a reflow
     so the browser commits those styles, THEN add the class so the transition
     plays from that pinned state to the open one.
     Reflow beats requestAnimationFrame here: rAF is throttled to zero in hidden
     tabs, so a rAF-scheduled class add can wait indefinitely; a forced reflow
     (reading offsetHeight) is synchronous and never throttled. */
  function show(){
    if(!open){
      open = true; dd.style.display = 'block'; inputEl.setAttribute('aria-expanded','true');
      dd.classList.remove('locss-open');
      /* reading offsetHeight is the standard trick — it forces layout and commits
         the opacity:0 initial state to the compositor before we add the target class. */
      void dd.offsetHeight;
    }
    dd.classList.add('locss-open');
    place();
  }
  function hide(){ if(open){ open = false; dd.classList.remove('locss-open'); dd.style.display = 'none'; active = -1; inputEl.setAttribute('aria-expanded','false'); } }
  /* Lightweight shimmer skeleton while a network geocode is in flight (item #3) —
     avoids the blank flash the old code left between typing pause and results arriving. */
  function renderSkeleton(){
    dd.innerHTML = '<div class="locss-sk"><span></span><b></b></div>'
      +'<div class="locss-sk"><span></span><b></b></div>'
      +'<div class="locss-sk"><span></span><b></b></div>';
    show();
  }

  function rowHtml(s, i, isHist){
    var ic = s.icon || iconFn((s.shortName||'')+' '+(s.displayName||''));
    var chip = s.localName ? '<span class="locss-chip">'+esc(s.localName)+'</span>' : '';
    var sec = (!isHist && s.displayName) ? '<span class="locss-sec">'+esc(s.displayName)+'</span>' : '';
    return '<div class="locss-row'+(i===active?' on':'')+'" role="option" data-i="'+i+'"><span class="locss-ic">'+ic+'</span>'
      + '<span class="locss-txt"><b>'+esc(s.shortName)+chip+'</b>'+sec+'</span></div>';
  }
  function wireRows(){
    dd.querySelectorAll('.locss-row').forEach(function(el){
      el.addEventListener('mousedown', function(e){ e.preventDefault(); choose(+el.getAttribute('data-i')); });
      el.addEventListener('mouseenter', function(){ setActive(+el.getAttribute('data-i')); });
    });
  }
  function setActive(i){
    active = i;
    dd.querySelectorAll('.locss-row').forEach(function(el){ el.classList.toggle('on', +el.getAttribute('data-i')===i); });
    var on = dd.querySelector('.locss-row.on'); if(on && on.scrollIntoView) on.scrollIntoView({ block:'nearest' });
  }
  function renderHistory(){
    var h = hist(); if(!h.length){ hide(); return; }
    items = h; active = -1;
    dd.innerHTML = '<div class="locss-hd">Recent<button type="button" class="locss-clear">Clear</button></div>'
      + h.map(function(x,i){ return rowHtml(x,i,true); }).join('');
    wireRows();
    var cb = dd.querySelector('.locss-clear'); if(cb) cb.addEventListener('mousedown', function(e){ e.preventDefault(); e.stopPropagation(); clearHist(); hide(); });
    show();
  }
  function renderResults(list, provider){
    items = list; active = -1;
    if(!list.length){ hide(); return; }
    dd.innerHTML = list.map(function(s,i){ return rowHtml(s,i,false); }).join('')
      + (provider ? '<div class="locss-ft">'+esc(provider)+'</div>' : '');
    wireRows(); show();
  }
  function choose(i){
    var s = items[i]; if(!s) return;
    var apply = function(r){ inputEl.value = r.displayName || r.shortName || ''; pushHist(r); hide(); onSelect(r); };
    if((s.lat==null || s.lng==null) && typeof s._resolve==='function') s._resolve().then(function(g){ if(g){ s.lat=g.lat; s.lng=g.lng; apply(s); } });
    else apply(s);
  }
  function search(q){
    var mine = ++seq;
    window.mapGeocode(q, maxResults).then(function(res){
      if(mine!==seq || document.activeElement!==inputEl) return;
      renderResults((res && res.results) || [], (res && res.provider) || '');
    }).catch(function(){});
  }

  inputEl.addEventListener('input', function(){
    var q = inputEl.value.trim(); clearTimeout(t);
    if(q.length < 2){ if(q.length===0) renderHistory(); else hide(); return; }
    /* shg-v59 — paint the shimmer immediately so the user sees "we're on it"
       during the 300ms debounce window and the network round-trip after. Actual
       search still fires after the debounce so we don't hammer geocoders. */
    renderSkeleton();
    t = setTimeout(function(){ search(q); }, debounceMs);
  });
  inputEl.addEventListener('focus', function(){ if(inputEl.value.trim().length===0) renderHistory(); else if(items.length) show(); });
  inputEl.addEventListener('keydown', function(e){
    if(e.key==='ArrowDown'){ e.preventDefault(); if(!open && items.length) show(); setActive(Math.min((active<0?-1:active)+1, items.length-1)); }
    else if(e.key==='ArrowUp'){ e.preventDefault(); setActive(Math.max(active-1, 0)); }
    else if(e.key==='Enter'){ if(open && items.length){ e.preventDefault(); choose(active>=0?active:0); } }
    else if(e.key==='Escape'){ hide(); }
  });
  document.addEventListener('mousedown', function(e){ if(e.target===inputEl || dd.contains(e.target)) return; hide(); });
  window.addEventListener('resize', function(){ if(open) place(); });
  window.addEventListener('scroll', function(){ if(open) place(); }, true);

  var api = { input:inputEl,
    setValue:function(txt){ inputEl.value = txt||''; hide(); },
    clear:function(){ inputEl.value=''; hide(); },
    focus:function(){ inputEl.focus(); },
    destroy:function(){ clearTimeout(t); if(dd.parentNode) dd.parentNode.removeChild(dd); try{ inputEl.__locSearch=null; }catch(e){} } };
  inputEl.__locSearch = api; return api;
}
/* === /MAP PRO UPGRADE (geocoder + search component) === */

const CONFIG = {
  company: {
    name: 'S Hari Global Pvt Ltd',
    tagline: 'India to Nepal Bus Transport | International Import & Export',
    address: 'Near Shilpa Garage, Silver Complex, Mehsana – 384002, Gujarat, India',
    cin: 'U52291GJ2026PTC174029',
    gstin: '',                     // ← add your GSTIN here and it prints on tickets & footer
    ceo: 'Sher Bahadur Bishwokarma',
    mantra: 'ॐ नमो नारायणाय'
  },
  // 🏦 Real ICICI eazypay VPA (M/S. S HARI GLOBAL PRIVATE LIMITED).
  // The checkout QR is GENERATED dynamically as upi://pay?...&am=<total>
  // so the exact booking amount is pre-filled in any UPI app.
  // customQrImage stays null on purpose — the static bank QR has no amount.
  upiId: '9104801507.eazypay@icici',
  upiName: 'S HARI GLOBAL PRIVATE LIMITED',
  customQrImage: null,
  // 🇳🇵 eSewa (Nepal side) — default receiver: Dileep Sunar.
  // If the official eSewa QR image is saved beside this file as
  // esewa-qr.jpg it is shown as-is; otherwise an info-QR is generated.
  esewaId: 'dileepjeth15@gmail.com',
  esewaName: 'Dileep Sunar',
  customEsewaQrImage: 'esewa-qr.jpg',
  /* adminPin / agentPin used to live here. They were removed, not moved:
     every visitor can download this file, so a password in it is a public
     password. Nothing reads them any more either — the admin and agent
     gates both hand over to /admin/login.php, which checks a bcrypt hash
     server-side, rate-limits attempts and scopes what the account may do.
     See goRealAdmin() and renderAgentGateOrApp(). */
  phone: '+91 91048 01507',
  email: 'shreehariglobalpvtltd@gmail.com',
  adminWhatsApp: '919104801507',
  adminEmail: 'booking@shariglobal.com',
  verifyTimeText: '1–3 minutes',
  allowScreenshotUpload: true,
  maxUploadSizeMB: 10,
  nprPerInr: 1.6,                 // fixed INR→NPR peg used for fare estimates
  booking: {
    seatHoldMinutes: 30,          // picked seats stay locked for one visitor this long
    maxSeats: 6,
    groupDiscount: { minSeats: 5, percent: 5 },  // ← group booking: 5+ seats → 5% off
    bookingFee: 0,                // per-booking fee in ₹ (0 shows as "Free")
    loyaltyTrips: 3,              // confirmed trips after which "welcome back" shows
    refundSlabs: [                // must match the Terms & Conditions page
      { minHrs: 96, pct: 90 },
      { minHrs: 48, pct: 75 },
      { minHrs: 24, pct: 50 },
      { minHrs: 6,  pct: 25 },
      { minHrs: 0,  pct: 0 }
    ],
    loyaltyPointsPer100: 1,       // loyalty: points earned per ₹100 of confirmed fare
    loyaltyReferralPoints: 20,    // loyalty: bonus points for a successful referral
    loyaltyTripBonus: 10,         // loyalty: bonus points once a trip is completed
    loyaltyRedeem: { points: 100, rupees: 10 },  // 100 points = ₹10 off at checkout
    assumedTravelSpeedKmh: 40,    // "distance to boarding point" estimate (admin-editable)
    sessionTimeoutMin: 45,        // idle minutes before auto sign-out (0 = never)
    checklist: [                  // travel checklist printed on the ticket (edit freely)
      'Government photo ID (passport / voter ID / citizenship card)',
      'Downloaded PDF ticket (save it before you lose signal)',
      'Phone + charger / power bank',
      'Cash in INR and NPR for small expenses'
    ]
  },
  /* Referral programme defaults — admin can override in Settings
     (stored in DB.commissionRules). One calculation path:
     computeReferralCommission() below. */
  referral: {
    mode: 'flat',                 // 'flat' (₹ per confirmed ticket) or 'percent' (% of fare)
    flat: 100,                    // ₹ per confirmed booking
    percent: 5,                   // used when mode === 'percent'
    windowDays: 7                 // must confirm within N days of createdAt
  },
  /* Loyalty tiers — thresholds are lifetime points (admin-editable). */
  loyaltyTiers: [
    { name: 'Silver',   min: 0,    discountPct: 0, icon: '🥈' },
    { name: 'Gold',     min: 300,  discountPct: 2, icon: '🥇' },
    { name: 'Platinum', min: 900,  discountPct: 3, icon: '⭐' },
    { name: 'Diamond',  min: 2000, discountPct: 5, icon: '💎' }
  ],
  femaleSeats: {                  // "female preferred" seats per coach type
    seater:  ['1A', '1B', '2A', '2B'],
    sleeper: ['L1', 'L2', 'L3']
  },
  staffSeats: {                   // permanently reserved — Staff/Emergency only
    seater:  ['1A'],              // (server enforces; only Super Admin can override)
    sleeper: ['L5', 'L6']         // cabin L-3 — owner decision 27 Aug 2026; L1 sells normally
  },
  /* MODE-AWARE emergency berth held back from online selection, ADDITIVE to
     staffSeats. Owner decision 3 Sep 2026: Private Sleeper's emergency seat is
     L3 (the row-1 single berth). Sharing keeps L5/L6 via staffSeats above, so a
     sharing "L3" (a different physical berth sharing the label) still sells.
     Mirror of includes/seats.php Seats::emergencySeats() — change both together. */
  emergencySeats: {
    sleeper: { private: ['L3'] }
  },
  /* Sales window — travel dates the site may sell. Server-enforced
     (bookingWindow() in includes/helpers.php); mirrored here for the date
     picker. Inauguration: 2 Sep 2026 (udghatan + puja). */
  bookingWindow: {
    openFrom: '2026-09-02',
    horizonDays: 30
  },
  /* MAIN POINTS — the boarding towns the fare board is priced on. Which
     side of the border a town is on is what decides the direction (and so
     the fare), so this list is the single place that knows the geography.
     Adding a town here puts it on the fare board and prices it correctly;
     it does not by itself create a bus, which stays a Manage Routes job. */
  mainPoints: {
    /* The five PERMANENT Gujarat-side stops of the daily run, in the order
       the coach reaches them. These names are canonical: they must read the
       same here, on the ticket, in Admin and in route_stops, so no alias
       may be introduced. Search matches a town against the route's real
       STOPS, so all five find the one daily bus.
       ⚠️ SINGLE SOURCE OF TRUTH — every display (homepage picker, fare board,
       boarding dropdown, ticket, PDF, admin, agent) reads from here or from
       the boarding arrays in seedRoutes() below. Change names HERE, they
       propagate everywhere. */
    india: ['Surat', 'Baroda', 'Emli Bhupal', 'S Hari Parking, Nana Chiloda', 'Mehsana — Silver Complex'],
    /* Rupaidiha is the ONLY Nepal-side point the company may sell today: the service is licensed to the India-side border and no further. Nepalgunj, Kohalpur and Lumbini Pradesh are a FUTURE extension — listing them here put them on the public fare board and in the search boxes as if they were bookable. Add them back the day the permit exists. */
    nepal: ['Rupaidiha']
  },
  cabinPricing: {
    onlineDiscountPct: 0,   // 5% online discount REMOVED 26 Aug 2026 — one flat fare
    /* Sharing per-person fare by travel direction.
       Owner-confirmed: Gujarat→Rupaidiha (toNepal, "jane") 2000,
       Rupaidiha→Gujarat (toIndia, "aune") 1800. FLAT — the same online & offline,
       no 5% discount. This matches the AUTHORITATIVE server value in
       Fare::dirFares(); the old live `cabin_pricing.sharingByDir` DB row
       (reversed 1800/2000) is intentionally no longer read by the server.
       The Admin → Main-point fares box still overrides both sides if needed. */
    sharingByDir: { toNepal: 2000, toIndia: 1800 },
    sharing: {
      single_2pax: { capacity:2, offline:4400, online:4180, perPerson:2090,
                     label:'Single Sleeper (Sharing)', cabinType:'single', emoji:'🛏️' },
      double_3pax: { capacity:3, offline:7500, online:7125, perPerson:2375,
                     label:'Double Sleeper (Sharing) · 3P', cabinType:'double', emoji:'🛏️🛏️' },
      double_4pax: { capacity:4, offline:8800, online:8360, perPerson:2090,
                     label:'Double Sleeper (Sharing) · 4P', cabinType:'double', emoji:'🛏️🛏️' },
    },
    private: {
      single_1pax: { capacity:1, offline:3800, online:3800,
                     label:'Single Sleeper (Private)', cabinType:'single', emoji:'🔒🛏️' },
      double_2pax: { capacity:2, offline:7600, online:7600,
                     label:'Double Sleeper (Private)', cabinType:'double', emoji:'🔒🛏️🛏️' },
    }
  }
};

/* ---- The prices above are a FALLBACK, not the truth --------------------
   Everything in CONFIG.cabinPricing is also a row in the server's settings
   table (`cabin_pricing`), and includes/fare.php bills from THAT row. The
   two agreed only because nobody had edited Settings yet: the first time
   the owner changed the online discount or a cabin rate in Admin, the page
   would have quoted the old number and the invoice charged the new one.

   index.php already ships the row to us in SHG_BOOT.settings.cabin_pricing
   (it is is_public = 1), so take it. Merged key-by-key rather than
   wholesale, so a partial row from an older install still gets the missing
   sub-tables from the defaults above instead of blanking a price.

   If the server sends nothing — offline, or an install that predates the
   settings row — the hardcoded table stands and the page prices exactly as
   it did before. */
/* Same seat cap everywhere (3 Sep 2026): the server's max_seats_per_booking
   setting is public, so the picker, the tap-to-select limit and the counter all
   read one number. Falls back to the constant above when nothing is shipped. */
(function applyServerSeatCap() {
  var v = null;
  try { v = window.SHG_BOOT && window.SHG_BOOT.settings && window.SHG_BOOT.settings.max_seats_per_booking; }
  catch (e) { v = null; }
  var n = parseInt(v, 10);
  if (n > 0 && n <= 20) CONFIG.booking.maxSeats = n;
})();

/* Round trip gate (17 Sep 2026): the engine sells ONE leg per ticket —
   api/book.php has no returnLeg — so a "Round trip" chosen in the app would
   silently drop its return. The pill is offered only once the office turns
   the public setting round_trip_on on (database/upgrade-2026-09-round-trip.sql);
   off, the confirmed ticket offers "Book return journey" instead. A missing
   row reads as off, exactly like the shipped value. */
CONFIG.roundTripOn = false;
(function applyServerRoundTrip() {
  var v = null;
  try { v = window.SHG_BOOT && window.SHG_BOOT.settings && window.SHG_BOOT.settings.round_trip_on; }
  catch (e) { v = null; }
  if (v === undefined || v === null) return;
  CONFIG.roundTripOn = (v === true || v === 1 || v === '1' || String(v).toLowerCase() === 'true');
})();

(function applyServerPricing() {
  var srv = null;
  try { srv = window.SHG_BOOT && window.SHG_BOOT.settings && window.SHG_BOOT.settings.cabin_pricing; }
  catch (e) { srv = null; }
  if (!srv || typeof srv !== 'object') return;
  /* The row is stored as JSON; Settings::getArray decodes it, but an older
     deploy could still hand it over as a string. Accept both. */
  if (typeof srv === 'string') { try { srv = JSON.parse(srv); } catch (e) { return; } }

  var local = CONFIG.cabinPricing;
  // NOTE: 'onlineDiscountPct' is deliberately excluded from the server merge
  // (like 'sharingByDir' below). The 5% online discount was removed 26 Aug 2026
  // for one flat fare; the live cabin_pricing DB row may still carry the old 5,
  // so the hardcoded 0 above stands and a stale row can never reinstate it.
  // NOTE: 'sharingByDir' is deliberately excluded from the server merge.
  // The live cabin_pricing DB row still holds the OLD reversed direction fare
  // (toNepal 1800 / toIndia 2000) and can't be rewritten from here, so the
  // hardcoded value above (toNepal 2000 / toIndia 1800, owner-confirmed
  // 25 Aug 2026) is authoritative — it matches Fare::dirFares() on the server.
  // Cabin tiers + private rates DO still sync. To restore admin-editable
  // direction fares later, put 'sharingByDir' back here AND fix the DB row.
  ['sharing', 'private'].forEach(function (k) {
    if (srv[k] && typeof srv[k] === 'object') local[k] = Object.assign({}, local[k], srv[k]);
  });
  // Flat pricing (26 Aug 2026): the 5% online discount is removed, so force
  // every cabin's online price equal to its offline price. This runs AFTER the
  // server merge, so even a stale cabin_pricing row (with a discounted online
  // value) can never show a fake "was/now" saving — every `.online` read (the
  // search-result cards, the pricing tables) reflects the single flat fare.
  ['sharing', 'private'].forEach(function (k) {
    var tbl = local[k] || {};
    Object.keys(tbl).forEach(function (key) {
      var row = tbl[key];
      if (row && typeof row === 'object' && typeof row.offline === 'number') row.online = row.offline;
    });
  });
})();


/* ================================================================
   [JS] 1b. TERMS & CONDITIONS DATA (14 comprehensive sections)
   Generated from workflow agents + 2 manual sections (disputes, amendments)
================================================================ */
var TERMS_DATA = [];
/* The 14 T&C sections are ~186 KB of text that almost nobody opens, so they
   live in /assets/js/terms-data.js and are fetched on first visit to #/terms. */
function loadTermsData(cb) {
  if (window.__shgTermsReady) { return cb(); }
  if (window.__shgTermsQueue) { window.__shgTermsQueue.push(cb); return; }
  window.__shgTermsQueue = [cb];
  var el = document.createElement('script');
  el.src = '/assets/js/terms-data.js?v=20260919b';
  el.onload = function () {
    TERMS_DATA = window.TERMS_DATA || [];
    window.__shgTermsReady = true;
    var q = window.__shgTermsQueue || []; window.__shgTermsQueue = null;
    q.forEach(function (f) { try { f(); } catch (e) {} });
  };
  el.onerror = function () {
    window.__shgTermsQueue = null;
    var box = document.getElementById('termsContent');
    if (box) box.innerHTML = '<p style="padding:18px">Terms load hunna sakena. Internet check garera page reload garnuhos.</p>';
  };
  document.head.appendChild(el);
}


function renderTerms() {
  var el = document.getElementById('termsContent');
  if (!el || el.children.length) return;
  if (!TERMS_DATA.length) {
    el.innerHTML = '<p class="tc-loading" style="padding:18px;opacity:.7">\u0932\u094b\u0921 \u0939\u0941\u0901\u0926\u0948\u091b\u2026</p>';
    loadTermsData(function () { el.innerHTML = ''; renderTerms(); });
    return;
  }
  var h = '';
  TERMS_DATA.forEach(function(s) {
    h += '<details class="tc-section" id="tc-' + s.id + '" data-kw="' + s.kw.join('|') + '">';
    h += '<summary class="tc-head" style="border-left:4px solid ' + s.color + '">';
    h += '<span class="tc-letter">' + s.letter + '</span>';
    h += '<span class="tc-emoji">' + s.emoji + '</span>';
    h += '<span class="tc-title">' + s.t_ne + ' / ' + s.t_hi + ' / ' + s.t_en + '</span>';
    h += '<span class="tc-arrow">▼</span>';
    h += '</summary>';
    h += '<div class="tc-body">';
    h += '<div class="tc-sum">';
    h += '<p><span class="tc-lang">NE</span> ' + s.s_ne + '</p>';
    h += '<p><span class="tc-lang">HI</span> ' + s.s_hi + '</p>';
    h += '<p><span class="tc-lang">EN</span> ' + s.s_en + '</p>';
    h += '</div>';
    h += '<div class="tc-clauses">';
    s.clauses.forEach(function(c) {
      h += '<div class="tc-clause"><h4>' + c.h + '</h4><p>' + c.t + '</p></div>';
    });
    h += '</div>';
    h += '<div class="tc-dosdnts">';
    h += '<div class="tc-dos"><h5>\u2705 Do\u2019s</h5><ul>';
    s.dos.forEach(function(d) { h += '<li>' + d + '</li>'; });
    h += '</ul></div>';
    h += '<div class="tc-donts"><h5>\u274C Don\u2019ts</h5><ul>';
    s.donts.forEach(function(d) { h += '<li>' + d + '</li>'; });
    h += '</ul></div>';
    h += '</div>';
    h += '<div class="tc-kw">' + s.kw.join(', ') + '</div>';
    h += '</div></details>';
  });
  el.innerHTML = h;

  var searchInput = document.getElementById('tcSearch');
  var clearBtn = document.getElementById('tcSearchClear');
  if (searchInput && !searchInput._tcBound) {
    searchInput._tcBound = true;
    var debounce;
    searchInput.addEventListener('input', function() {
      clearTimeout(debounce);
      debounce = setTimeout(function() { searchTerms(searchInput.value); }, 200);
      clearBtn.style.display = searchInput.value ? '' : 'none';
    });
    clearBtn.addEventListener('click', function() {
      searchInput.value = '';
      clearBtn.style.display = 'none';
      searchTerms('');
      searchInput.focus();
    });
  }
}

function searchTerms(query) {
  if (!TERMS_DATA.length) return;
  var resultsEl = document.getElementById('tcSearchResults');
  var sections = document.querySelectorAll('.tc-section');
  if (!resultsEl) return;
  var q = (query || '').trim().toLowerCase();
  if (!q || q.length < 2) {
    resultsEl.style.display = 'none';
    resultsEl.innerHTML = '';
    sections.forEach(function(s) { s.style.display = ''; });
    return;
  }
  var words = q.split(/\s+/).filter(function(w) { return w.length >= 2; });
  if (!words.length) { resultsEl.style.display = 'none'; sections.forEach(function(s) { s.style.display = ''; }); return; }
  var hits = [];
  TERMS_DATA.forEach(function(sec) {
    var score = 0;
    var matchSnippet = '';
    var allText = (sec.t_en + ' ' + sec.t_hi + ' ' + sec.t_ne + ' ' + sec.s_en + ' ' + sec.s_hi + ' ' + sec.s_ne + ' ' + sec.kw.join(' ')).toLowerCase();
    var clauseTexts = sec.clauses.map(function(c) { return c.h + ' ' + c.t; }).join(' ').toLowerCase();
    var dosText = sec.dos.join(' ').toLowerCase();
    var dontsText = sec.donts.join(' ').toLowerCase();
    var fullText = allText + ' ' + clauseTexts + ' ' + dosText + ' ' + dontsText;
    words.forEach(function(w) {
      if (sec.kw.some(function(k) { return k.toLowerCase().indexOf(w) >= 0; })) score += 10;
      if ((sec.t_en + ' ' + sec.t_ne + ' ' + sec.t_hi).toLowerCase().indexOf(w) >= 0) score += 8;
      if ((sec.s_en + ' ' + sec.s_ne + ' ' + sec.s_hi).toLowerCase().indexOf(w) >= 0) score += 5;
      if (clauseTexts.indexOf(w) >= 0) score += 3;
      if (dosText.indexOf(w) >= 0) score += 2;
      if (dontsText.indexOf(w) >= 0) score += 2;
    });
    if (score > 0) {
      var bestClause = '';
      sec.clauses.forEach(function(c) {
        var ct = (c.h + ' ' + c.t).toLowerCase();
        words.forEach(function(w) {
          if (ct.indexOf(w) >= 0 && !bestClause) {
            var idx = ct.indexOf(w);
            var start = Math.max(0, idx - 60);
            var end = Math.min(ct.length, idx + w.length + 80);
            bestClause = (start > 0 ? '…' : '') + c.t.substring(start, end) + (end < c.t.length ? '…' : '');
          }
        });
      });
      if (!bestClause && sec.s_en.toLowerCase().indexOf(words[0]) >= 0) bestClause = sec.s_en.substring(0, 140) + '…';
      if (!bestClause) bestClause = sec.s_en.substring(0, 140) + '…';
      hits.push({ sec: sec, score: score, snippet: bestClause });
    }
  });
  hits.sort(function(a, b) { return b.score - a.score; });
  sections.forEach(function(s) {
    var sid = s.id.replace('tc-', '');
    var matched = hits.some(function(h) { return h.sec.id === sid; });
    s.style.display = matched ? '' : 'none';
  });
  if (hits.length) {
    var rh = '';
    hits.forEach(function(h) {
      var snippet = h.snippet;
      words.forEach(function(w) {
        var re = new RegExp('(' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        snippet = snippet.replace(re, '<mark>$1</mark>');
      });
      rh += '<div class="tc-sr" data-target="tc-' + h.sec.id + '">';
      rh += '<span class="tc-sr-letter">' + h.sec.letter + '</span>';
      rh += '<div class="tc-sr-body">';
      rh += '<div class="tc-sr-title">' + h.sec.emoji + ' ' + h.sec.t_en + '</div>';
      rh += '<div class="tc-sr-match">' + snippet + '</div>';
      rh += '</div></div>';
    });
    resultsEl.innerHTML = rh;
    resultsEl.style.display = '';
    resultsEl.querySelectorAll('.tc-sr').forEach(function(row) {
      row.addEventListener('click', function() {
        var target = document.getElementById(row.getAttribute('data-target'));
        if (target) { target.open = true; target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
      });
    });
  } else {
    resultsEl.innerHTML = '<div class="tc-no-results">No matching sections found / कुनै मिल्दो खण्ड भेटिएन</div>';
    resultsEl.style.display = '';
  }
}

/* ================================================================
   [JS] 2. STORAGE ADAPTER
   Order of preference: host window.storage (Claude artifact) →
   browser localStorage (normal hosting) → in-memory fallback.
   🔁 PRODUCTION SWAP: replace get/set with fetch() calls to your
   backend API — nothing else in the app needs to change.
================================================================ */
const store = {
  mem: {},
  remote: (typeof window !== 'undefined') && !!window.storage,
  local: (function () {
    try { localStorage.setItem('shg:t', '1'); localStorage.removeItem('shg:t'); return true; }
    catch (e) { return false; }
  })(),
  async get(key, fallback) {
    if (this.remote) {
      try { const r = await window.storage.get(key); return r ? JSON.parse(r.value) : fallback; }
      catch (e) { return fallback; }
    }
    if (this.local) {
      try { const v = localStorage.getItem(key); return v == null ? fallback : JSON.parse(v); }
      catch (e) { return fallback; }
    }
    return (key in this.mem) ? this.mem[key] : fallback;
  },
  /* This browser's own copy of a key, or the fallback. Synchronous, so the
     batch reader can consult it per key without another round-trip. */
  _local(key, fallback) {
    if (!this.local) return (key in this.mem) ? this.mem[key] : fallback;
    try { const v = localStorage.getItem(key); return v == null ? fallback : JSON.parse(v); }
    catch (e) { return fallback; }
  },
  async set(key, val) {
    if (this.remote) {
      try { await window.storage.set(key, JSON.stringify(val)); return; } catch (e) {}
    }
    if (this.local) {
      try { localStorage.setItem(key, JSON.stringify(val)); return; } catch (e) {}
    }
    this.mem[key] = val;
  },

  /* Fetch many keys in ONE request.
     Boot needs sixteen of these. As individual gets that is sixteen
     round-trips and sixteen PHP boots — on a phone at 250ms RTT it was
     the single slowest thing between tapping the icon and using the app.
     /api/kv.php?action=mget answers them all at once.

     Falls back to parallel single gets whenever the batch endpoint is not
     there (an older deploy) or fails, so this can never be the reason the
     app won't start.

     @param {string[]} keys  full keys, e.g. ['shg:routes','shg:settings']
     @param {object}   defs  key -> fallback when the key is missing
     @returns {Promise<object>} key -> value */
  async getMany(keys, defs) {
    defs = defs || {};
    const fallback = () => Promise.all(keys.map(k => this.get(k, defs[k])))
      .then(vals => { const out = {}; keys.forEach((k, i) => { out[k] = vals[i]; }); return out; });

    // The batch endpoint only backs the remote bridge; local/in-memory
    // storage has no round-trip to save, so go straight to the plain path.
    if (!this.remote || !window.SHG_BOOT || !window.SHG_BOOT.apiBase) return fallback();

    try {
      const short = keys.map(k => k.replace(/^shg:/, ''));
      const res = await fetch(
        window.SHG_BOOT.apiBase.replace(/\/$/, '') + '/kv.php?action=mget&keys=' + encodeURIComponent(short.join(',')),
        { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
      );
      if (!res.ok) return fallback();

      const body = await res.json();
      const values = (body && body.data && body.data.values) || (body && body.values);
      if (!values) return fallback();

      const out = {};
      keys.forEach((k, i) => {
        const raw = values[short[i]];
        /* A server MISS is not an empty value — it means "not here, or not
           yours to read". api/kv.php answers that way for every non-public
           key, so for a guest `bookings`, `locks` and `waitlist` all come
           back null on every single boot.

           The single-key bridge has always fallen back to this browser's own
           copy at that point; this batch path did not, and handed back the
           default instead. A guest's saved tickets were therefore wiped to []
           on every page load — and then persisted back over the real ones.
           Falling back to local here restores the contract the batch was
           only ever meant to make faster, not different. */
        if (raw === null || raw === undefined) { out[k] = this._local(k, defs[k]); return; }
        try { out[k] = JSON.parse(raw); } catch (e) { out[k] = this._local(k, defs[k]); }
      });
      return out;
    } catch (e) {
      return fallback();
    }
  }
};

/* ================================================================
   [JS] 2b. LIVE API BRIDGE — the real PHP/MySQL backend.
   Booking creation, payment proof and OTP sign-in are financial /
   identity actions, so they go straight to the server (unlike the
   `store` KV mirror above, which is for shared read-mostly data).
   Every call throws a plain Error with a toast()-ready message on
   failure — callers never need to inspect the envelope themselves.
================================================================ */
/* Haptics (4 Sep 2026): a short buzz on the taps that matter — a seat
   picked, a booking sent, a refusal. Android Chrome only (iOS Safari has no
   vibrate API); silent under prefers-reduced-motion and wherever the OS
   hides the API, so it can never break a flow. */
function shgHaptic(kind) {
  try {
    if (!('vibrate' in navigator)) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var pat = kind === 'success' ? [12, 40, 18] : kind === 'error' ? [45] : kind === 'select' ? 10 : 6;
    navigator.vibrate(pat);
  } catch (e) {}
}

const shgApi = {
  base()  { return (window.SHG_BOOT && window.SHG_BOOT.apiBase) || '/api'; },
  csrf()  { return (window.SHG_BOOT && window.SHG_BOOT.csrf) || ''; },
  async _read(res) {
    let json;
    try { json = await res.json(); }
    catch (e) { throw new Error('The server sent an unexpected response. Please try again.'); }
    if (!json || json.ok !== true) {
      const err = new Error((json && json.error) || 'Something went wrong. Please try again.');
      err.fields = json && json.fields; err.status = res.status;
      /* Carry the payload through too. A refusal often ships the detail the
         caller needs to recover — /lock.php returns which seats were taken
         so the seat map can drop exactly those and leave the rest picked. */
      err.data = json && json.data;
      throw err;
    }
    return json.data;
  },
  /* 4 Sep 2026: every call gives up after a deadline instead of leaving a
     button spinning forever on a stalled 3G link. JSON calls get 25 s; the
     multipart proof upload gets 90 s because a 10 MB screenshot on a slow
     link legitimately takes that long. The error reads as advice, not a
     code, because it is shown to the passenger as-is. */
  _slow: 'The network is slow right now — please check your connection and try again. · नेटवर्क ढिलो छ, फेरि प्रयास गर्नुहोस्।',
  async _fetch(url, init, ms) {
    const ctl = (typeof AbortController === 'function') ? new AbortController() : null;
    if (ctl) init.signal = ctl.signal;
    const timer = ctl ? setTimeout(() => ctl.abort(), ms) : null;
    try {
      return await fetch(url, init);
    } catch (e) {
      if (e && e.name === 'AbortError') throw new Error(this._slow);
      throw e;
    } finally {
      if (timer) clearTimeout(timer);
    }
  },
  /* 11 Sep 2026 (perf pass): a 419 means the session token the page was
     rendered with is no longer the session's token — the tab sat open past
     the session lifetime, or the service worker opened a cached shell on a
     slow link. Fetch the live token once and retry, instead of the old
     "Security token expired. Please refresh the page" dead end. */
  async _refreshCsrf() {
    try {
      const r = await this._fetch(this.base() + '/csrf.php',
        { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }, 10000);
      const j = await r.json();
      if (j && j.ok === true && j.data && j.data.csrf) {
        window.SHG_BOOT = window.SHG_BOOT || {};
        window.SHG_BOOT.csrf = j.data.csrf;
        return true;
      }
    } catch (e) {}
    return false;
  },
  async post(path, body) {
    const send = () => this._fetch(this.base() + path, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf() },
      body: JSON.stringify(body || {})
    }, 25000);
    let res = await send();
    if (res.status === 419 && await this._refreshCsrf()) res = await send();
    return this._read(res);
  },
  async postForm(path, formData) {
    const send = () => this._fetch(this.base() + path, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-Token': this.csrf() },
      body: formData
    }, 90000);
    let res = await send();
    if (res.status === 419 && await this._refreshCsrf()) res = await send();
    return this._read(res);
  },
  async get(path) {
    const res = await this._fetch(this.base() + path, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }, 25000);
    return this._read(res);
  },
  /** data:URL (from a FileReader thumb) -> Blob, for multipart uploads. */
  dataUrlToBlob(dataUrl) {
    const [head, b64] = dataUrl.split(',');
    const mime = (head.match(/data:(.*?);base64/) || [, 'image/jpeg'])[1];
    const bin = atob(b64);
    const bytes = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    return new Blob([bytes], { type: mime });
  }
};

/* In-memory working copy of all data (kept in sync with storage). */
let DB = {
  routes: [], bookings: [], settings: {}, messages: [], locks: [], waitlist: [],
  users: [],            // accounts created at OTP sign-in: {phone, name, role, ...}
  commissions: [],      // one referral commission entry per booking
  payoutRequests: [],   // agent withdrawal requests (settled offline by admin)
  auditLog: [],         // {ts, actor, action, detail} — admin actions
  commissionRules: {},  // admin overrides for CONFIG.referral (+ per-route / bonuses)
  delays: [],           // {routeId, date, delayMinutes, note} — live-ops banners
  tripExpenses: [],     // {date, routeId, diesel, toll, driver, misc, note}
  notifications: [],    // in-app notification centre: {id, at, icon, title, body, read} (max 60)
  /* Loaded in the background after first paint (see init()), so they must
     start as real empty arrays — anything reading them early gets an empty
     list rather than `undefined`. */
  paymentSubmissions: [],
  verificationLogs: []
};
const persist = (k) => { store.set('shg:' + k, DB[k]); };
/* Effective settings = CONFIG defaults overridden by saved admin settings. */
const S = () => Object.assign({
  upiId: CONFIG.upiId, upiName: CONFIG.upiName,
  esewaId: CONFIG.esewaId, esewaName: CONFIG.esewaName,
  phone: CONFIG.phone, email: CONFIG.email,
  noticeOn: false, noticeText: '',
  assumedTravelSpeedKmh: CONFIG.booking.assumedTravelSpeedKmh,
  sessionTimeoutMin: CONFIG.booking.sessionTimeoutMin,
  loyaltyTiers: CONFIG.loyaltyTiers,
  mainFares: CONFIG.cabinPricing.sharingByDir
}, DB.settings || {});
/* Effective commission rules = CONFIG.referral overridden by admin rules. */
const CR = () => Object.assign({}, CONFIG.referral, DB.commissionRules || {});

/* ================================================================
   [JS] 3. SEED DATA — default routes & a couple of demo bookings
   (only inserted the very first time, when storage is empty).
   Boarding/drop point format: "Name · Landmark @ HH:MM"
   (the landmark and time are optional — plain names still work).
================================================================ */
function seedRoutes() {
  /* ONE daily bus each way (27 Aug 2026). The ids MUST equal the MySQL
     route_code ('r2' / 'r7') — checkout resolves the server route by id,
     so an id the routes table does not carry cannot be booked. The five
     Gujarat stop names are canonical (see CONFIG.mainPoints.india) and
     byte-exact with route_stops — never reword them. */
  return [
    {
      id: 'r2', from: 'Surat', to: 'Rupaidiha',
      busName: 'SHG Gandaki Sleeper', busNo: 'GJ-02-T-5580', type: 'sleeper',
      pathId: 'via_bahraich',
      depTime: '13:00', arrTime: '', dayOffset: 1, duration: '', fare: 2000,
      configVer: 3,   // reseed guard — bump when stop names change
      boarding: [
        'Surat · Departure @ 13:00 [21.170,72.831]',
        'Baroda @ 17:00 [22.307,73.181]',
        'Emli Bhupal @ 19:00',
        'S Hari Parking, Nana Chiloda @ 21:00 [23.171,72.623]',
        'Mehsana — Silver Complex @ 23:00 [23.588,72.369]'
      ],
      drop: ['Rupaidiha · India-Nepal border checkpoint [28.060,81.617]'],
      amenities: ['AC Sleeper', 'Blanket', 'Charging Point', 'Water Bottle'],
      crewName: '', crewPhone: '',
      active: true
    },
    {
      id: 'r7', from: 'Rupaidiha', to: 'Surat',
      busName: 'SHG Gandaki Sleeper', busNo: 'GJ-02-T-5580', type: 'sleeper',
      pathId: 'via_bahraich',
      depTime: '18:00', arrTime: '', dayOffset: 1, duration: '', fare: 1800,
      configVer: 3,
      boarding: ['Rupaidiha · India-Nepal border checkpoint @ 18:00 [28.060,81.617]'],
      drop: [
        'Mehsana — Silver Complex [23.588,72.369]',
        'S Hari Parking, Nana Chiloda [23.171,72.623]',
        'Emli Bhupal',
        'Baroda [22.307,73.181]',
        'Surat · Final drop [21.170,72.831]'
      ],
      amenities: ['AC Sleeper', 'Blanket', 'Charging Point', 'Water Bottle'],
      crewName: '', crewPhone: '',
      active: true
    }
  ];
}
/* There is deliberately no seedBookings(). Two demo tickets used to be
   written into every fresh browser (SHG-DEMO01 / SHG-DEMO02, seats 4C, 4D
   and 7A on r1). They were indistinguishable from real ones: they showed up
   in My Bookings as the visitor's own tickets, and — because availability
   was computed from this same list — they marked three berths of a real bus
   as sold to every single visitor. A booking now exists only if the database
   says it does. */

/* ================================================================
   [JS] 4. SMALL UTILITIES
================================================================ */
const $  = (s, r) => (r || document).querySelector(s);
const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const inr = (n) => '₹' + Number(n || 0).toLocaleString('en-IN');
const nprEst = (n) => '≈ NPR ' + Math.round(Number(n || 0) * CONFIG.nprPerInr).toLocaleString('en-IN');
const digits = (s) => String(s || '').replace(/\D/g, '');
function todayISO() { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
function addDaysISO(iso, n) { const p = iso.split('-').map(Number); const d = new Date(p[0], p[1] - 1, p[2] + n); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
function fmtDate(iso) {
  if (!iso) return '';
  const p = iso.split('-').map(Number);
  const loc = LANG === 'hi' ? 'hi-IN' : (LANG === 'ne' ? 'ne-NP' : 'en-IN');
  try { return new Date(p[0], p[1] - 1, p[2]).toLocaleDateString(loc, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }); }
  catch (e) { return new Date(p[0], p[1] - 1, p[2]).toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }); }
}
/* Offline-safe random chars: crypto.getRandomValues (no network needed) with a
   Math.random fallback only for the rare environment lacking crypto support. */
function secureRandomChars(n, alphabet) {
  const A = alphabet || 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
  let s = '';
  if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
    const buf = new Uint32Array(n);
    crypto.getRandomValues(buf);
    for (let i = 0; i < n; i++) s += A[buf[i] % A.length];
  } else {
    for (let i = 0; i < n; i++) s += A[Math.floor(Math.random() * A.length)];
  }
  return s;
}
/* Short from→to code for a route, e.g. Ahmedabad→Nepalgunj = "AHM-NPJ". */
function routeCode(r) {
  const abbr = (name) => String(name || 'XXX').replace(/[^A-Za-z]/g, '').slice(0, 3).toUpperCase().padEnd(3, 'X');
  return r ? (abbr(r.from) + '-' + abbr(r.to)) : 'AHM-NPJ';
}
/* Fully offline unique ticket ID: SHG-<routeCode>-<base36 timestamp>-<4-char random>.
   No server round-trip — works with zero connectivity. */
function genId(route) {
  const ts = Date.now().toString(36).toUpperCase();
  return 'SHG-' + routeCode(route) + '-' + ts + '-' + secureRandomChars(4);
}
const routeById = (id) => DB.routes.find(r => r.id === id);
let toastTimer;
function toast(msg) { const t = $('#toast'); t.textContent = msg; t.classList.add('show'); clearTimeout(toastTimer); toastTimer = setTimeout(() => t.classList.remove('show'), 3200); }

/* Apply Bhagwan image to hero background & ticket watermark via CSS variable. */
function applyBhagwanImg() {
  const url = (DB.settings && DB.settings.bhagwanImg) || '';
  if (url) {
    document.documentElement.style.setProperty('--bhagwan-img', 'url(' + url + ')');
  } else {
    document.documentElement.style.removeProperty('--bhagwan-img');
  }
  const ogImg = document.querySelector('meta[property="og:image"]');
  if (ogImg) ogImg.setAttribute('content', url);
  const admImg = $('#admBhagwanImg');
  if (admImg) { admImg.src = url; admImg.style.display = url ? 'block' : 'none'; }
}

/* Per-browser-tab session id — powers the seat locks. */
const SID = (function () {
  try {
    let s = sessionStorage.getItem('shg:sid');
    if (!s) { s = 'S' + Math.random().toString(36).slice(2, 10); sessionStorage.setItem('shg:sid', s); }
    return s;
  } catch (e) { return 'S' + Math.random().toString(36).slice(2, 10); }
})();

/* Boarding/drop point parser: "Name · Landmark @ HH:MM [lat,lng]" → parts.
   The [lat,lng] suffix is optional — it powers the passenger-side
   "distance to boarding point" calculator (admin adds it per point). */
function parseBP(str) {
  let s = String(str || ''), time = '', landmark = '', lat = null, lng = null;
  const gm = s.match(/\[\s*(-?\d+(?:\.\d+)?)\s*[,;]\s*(-?\d+(?:\.\d+)?)\s*\]\s*$/);
  if (gm) { lat = parseFloat(gm[1]); lng = parseFloat(gm[2]); s = s.slice(0, gm.index).trim(); }
  const tm = s.match(/@\s*([0-2]?\d:\d{2})\s*$/);
  if (tm) { time = tm[1]; s = s.slice(0, tm.index).trim(); }
  const parts = s.split('·').map(x => x.trim()).filter(Boolean);
  if (parts.length > 1) { landmark = parts.slice(1).join(' · '); s = parts[0]; }
  return { name: s, landmark: landmark, time: time, lat: lat, lng: lng, raw: String(str || '') };
}
/* Nepali time-of-day label for the five Gujarat stops */
function nepaliTime(t24) {
  var h = parseInt(t24, 10);
  if (h === 13) return '(दिउँसो १ बजे)';
  if (h === 17) return '(साँझ ५ बजे)';
  if (h === 19) return '(साँझ ७ बजे)';
  if (h === 21) return '(राति ९ बजे)';
  if (h === 23) return '(राति ११ बजे)';
  if (h === 18) return '(साँझ ६ बजे)';
  return '';
}
/* How a stop reads on the ticket card: short code + short name + pickup time.
   MIRRORS includes/boarding.php Boarding::stopDisplay() / STOP_CODES — keep
   the two lists identical. The card used to show the ROUTE's origin (STV
   Surat) for a passenger boarding at Nana Chiloda; now it shows their stop.
   No \p{..} regex here: an old WebView would fail to parse the whole file. */
var STOP_CODES = [
  ['nana chiloda', 'AMD', 'Nana Chiloda'], ['hari parking', 'AMD', 'Nana Chiloda'], ['ahmedabad', 'AMD', 'Ahmedabad'],
  ['emli', 'EMB', null], ['bhupal', 'EMB', null], ['mehsana', 'MSN', 'Mehsana'], ['surat', 'STV', 'Surat'],
  ['baroda', 'BRC', 'Baroda'], ['barauda', 'BRC', 'Baroda'], ['vadodara', 'BRC', 'Vadodara'],
  ['rupaidiha', 'RPD', 'Rupaidiha'], ['jamunaha', 'RPD', 'Jamunaha'], ['nepalgunj', 'NPJ', 'Nepalgunj'], ['nepalganj', 'NPJ', 'Nepalgunj'],
  ['kohalpur', 'KHL', 'Kohalpur'], ['lucknow', 'LKO', 'Lucknow'], ['bahraich', 'BRK', 'Bahraich'], ['gorakhpur', 'GKP', 'Gorakhpur'],
  ['kathmandu', 'KTM', 'Kathmandu'], ['delhi', 'DEL', 'Delhi'], ['jaipur', 'JAI', 'Jaipur'], ['udaipur', 'UDR', 'Udaipur']
];
function stopTown(lead) {
  var t = String(lead || '').split(/\s+[\u2014\u2013-]\s+/)[0].trim();
  if (t.indexOf(',') >= 0) { var ps = t.split(',').map(function (x) { return x.trim(); }).filter(Boolean); t = ps[ps.length - 1] || t; }
  return t || String(lead || '').trim();
}
function stopDisplay(label, fallbackName) {
  var p = parseBP(label || '');
  var lead = (p.name || String(fallbackName || '')).trim();
  var key = lead.toLowerCase().replace(/[^a-z0-9\u0900-\u097f]+/g, ' ').trim();
  for (var i = 0; i < STOP_CODES.length; i++) {
    if (key && key.indexOf(STOP_CODES[i][0]) >= 0) return { code: STOP_CODES[i][1], name: STOP_CODES[i][2] || stopTown(lead), time: p.time || '' };
  }
  var town = stopTown(lead), letters = town.replace(/[^A-Za-z]/g, '');
  return { code: (letters || 'SHG').slice(0, 3).toUpperCase(), name: town, time: p.time || '' };
}
function bpLabel(str) { const p = parseBP(str); var ne = p.time ? nepaliTime(p.time) : ''; return (p.time ? p.time + (ne ? ' ' + ne : '') + ' · ' : '') + p.name + (p.landmark ? ' (' + p.landmark + ')' : ''); }
function bpShort(str) { const p = parseBP(str); var ne = p.time ? nepaliTime(p.time) : ''; return p.name + (p.time ? ' · ' + p.time + (ne ? ' ' + ne : '') : ''); }
/* Full Nepali time label for any HH:MM — बिहान/दिउँसो/साँझ/राति + Devanagari digits */
function nepaliTimeFull(t24) {
  if (!t24) return '';
  var parts = t24.split(':'), h = parseInt(parts[0], 10), m = parseInt(parts[1] || '0', 10);
  var nd = function(s) { return String(s).replace(/[0-9]/g, function(d) { return '०१२३४५६७८९'[d]; }); };
  var h12 = h % 12 || 12;
  var period = (h >= 4 && h < 12) ? 'बिहान' : (h >= 12 && h < 16) ? 'दिउँसो' : (h >= 16 && h < 20) ? 'साँझ' : 'राति';
  return period + ' ' + nd(h12) + ':' + nd(String(m).replace(/^(\d)$/, '0$1')) + ' बजे';
}
/* Nepali date — "०२ सेप्टेम्बर २०२६" */
function nepaliDateFull(iso) {
  if (!iso) return '';
  var months = ['जनवरी','फेब्रुअरी','मार्च','अप्रिल','मे','जुन','जुलाई','अगस्ट','सेप्टेम्बर','अक्टोबर','नोभेम्बर','डिसेम्बर'];
  var nd = function(s) { return String(s).replace(/[0-9]/g, function(d) { return '०१२३४५६७८९'[d]; }); };
  var p = iso.split('-');
  return nd(parseInt(p[2], 10)) + ' ' + months[parseInt(p[1], 10) - 1] + ' ' + nd(p[0]);
}

/* ================================================================
   BIKRAM SAMBAT (विक्रम संवत्) — real AD→BS conversion.

   Everything above shows the GREGORIAN date in Devanagari script;
   Nepali passengers actually reckon travel dates in BS ("भदौ १७"),
   so both calendars are shown side by side. BS month lengths are not
   formulaic — each year is a fixed table published by the Nepali
   panchanga — so the years the app can sell tickets for are tabled
   here. Outside the table nepaliBS() returns null and every caller
   silently falls back to the AD-only label (never a wrong date).

   Table: BS 2080..2090, twelve month-lengths each, and the AD date
   of 1 Baishakh (BS new year) for that year.
================================================================ */
/* INVARIANT — every year's start + sum(months) MUST equal the next year's
   start. A gap prints "no BS date" for a day; an overlap prints the WRONG
   day. tests/bs-calendar-test.php enforces this and the anchors below.
   Only years verified against the published panchanga are listed: outside
   this range nepaliBS() returns null and the UI shows the AD date alone,
   because a wrong Nepali date on a ticket is worse than none. Extend from
   official data — and add the matching row to Ticket::BS_DATA in the SAME
   deploy (includes/ticket.php), or the site and the PDF would disagree. */
var BS_DATA = {
  2080: { start: '2023-04-14', m: [31,31,31,32,31,31,30,29,30,29,30,30] }, // 365
  2081: { start: '2024-04-13', m: [31,32,31,32,31,30,30,30,29,30,30,30] }, // 366
  2082: { start: '2025-04-14', m: [30,32,31,32,31,30,30,30,29,30,30,30] }, // 365
  2083: { start: '2026-04-14', m: [31,31,32,31,31,30,30,30,29,30,30,30] }, // 365
  2084: { start: '2027-04-14', m: [31,31,32,31,31,30,30,30,29,30,30,30] }  // 365
};
var BS_MONTHS = ['बैशाख','जेठ','असार','साउन','भदौ','असोज','कार्तिक','मंसिर','पुष','माघ','फागुन','चैत'];
var BS_DAYS   = ['आइतबार','सोमबार','मंगलबार','बुधबार','बिहीबार','शुक्रबार','शनिबार'];
var BS_DAYS_S = ['आइत','सोम','मंगल','बुध','बिही','शुक्र','शनि'];

function bsDevanagari(s) {
  return String(s).replace(/[0-9]/g, function (d) { return '०१२३४५६७८९'[d]; });
}

/* AD ISO (YYYY-MM-DD) -> {y,m,d,monthName,dayName,dayShort} in BS, or null
   when the date falls outside BS_DATA. Uses UTC throughout so a device in
   any timezone counts the same number of days. */
function nepaliBS(iso) {
  if (!iso) return null;
  var p = String(iso).split('-');
  if (p.length < 3) return null;
  var target = Date.UTC(+p[0], +p[1] - 1, +p[2]);
  if (isNaN(target)) return null;
  var DAY = 86400000;
  for (var y in BS_DATA) {
    var rec = BS_DATA[y], sp = rec.start.split('-');
    var startMs = Date.UTC(+sp[0], +sp[1] - 1, +sp[2]);
    var total = rec.m.reduce(function (a, b) { return a + b; }, 0);
    if (target < startMs || target >= startMs + total * DAY) continue;
    var offset = Math.round((target - startMs) / DAY);
    for (var i = 0; i < 12; i++) {
      if (offset < rec.m[i]) {
        var dow = new Date(target).getUTCDay();
        return {
          y: +y, m: i + 1, d: offset + 1,
          monthName: BS_MONTHS[i],
          dayName: BS_DAYS[dow],
          dayShort: BS_DAYS_S[dow]
        };
      }
      offset -= rec.m[i];
    }
  }
  return null;
}

/* "भदौ १७, २०८३" — full BS label (empty string when out of range) */
function nepaliBSFull(iso) {
  var b = nepaliBS(iso);
  return b ? b.monthName + ' ' + bsDevanagari(b.d) + ', ' + bsDevanagari(b.y) : '';
}
/* "शनिबार, भदौ १७" — weekday + BS date, what a passenger actually reads */
function nepaliBSWithDay(iso) {
  var b = nepaliBS(iso);
  return b ? b.dayName + ', ' + b.monthName + ' ' + bsDevanagari(b.d) : '';
}

/* Departure timestamp of a booking (for refund slabs). */
function depTimestamp(b) {
  const r = routeById(b.routeId) || {};
  const t = (r.depTime || '00:00').split(':').map(Number);
  const p = (b.date || todayISO()).split('-').map(Number);
  return new Date(p[0], p[1] - 1, p[2], t[0] || 0, t[1] || 0).getTime();
}
function refundInfo(b) {
  const hrs = (depTimestamp(b) - Date.now()) / 3600000;
  const slab = CONFIG.booking.refundSlabs.find(s => hrs >= s.minHrs) || { pct: 0 };
  return { hrs: hrs, pct: slab.pct, amount: Math.round((b.total || 0) * slab.pct / 100) };
}

/* Fare maths for one or two legs: base − group discount + fee. */
function calcFare(legs) {
  let base = 0, seats = 0;
  legs.forEach(l => { base += l.seats.length * l.fare; seats += l.seats.length; });
  const gd = CONFIG.booking.groupDiscount;
  const discount = seats >= gd.minSeats ? Math.round(base * gd.percent / 100) : 0;
  const fee = CONFIG.booking.bookingFee || 0;
  /* perSeat: the rate the "N passengers × ₹fare" line shows (first leg). */
  return { base: base, seats: seats, discount: discount, fee: fee, total: base - discount + fee,
           perSeat: legs.length && legs[0] ? (legs[0].fare || 0) : 0 };
}

/* Cabin fare calculator: private/sharing × cabin type × pax count × online discount. */
/* Which side of the border a town is on. This used to be the single test
   `toCity === 'Ahmedabad'`, which was true only while Ahmedabad was the one
   Indian stop on the board — the moment Mehsana, Surat and Vadodara became
   bookable, a Nepalgunj → Surat passenger fell through to the outbound
   price and was quoted the wrong fare in the wrong direction. */
function isNepalPoint(city) {
  const mp = (CONFIG.mainPoints || {});
  return (mp.nepal || []).indexOf(String(city || '')) >= 0;
}
/* The live directional fares: admin values win, CONFIG is the fallback, so
   an operator can change a price from the panel with no deploy. */
function sharingDir() {
  // AUTHORITATIVE code value (owner-confirmed 25 Aug 2026): toNepal 2000,
  // toIndia 1800. The admin main_fares (S().mainFares) override is intentionally
  // NOT applied while the live cabin_pricing DB row still holds the old reversed
  // pair — this mirrors Fare::dirFares() server-side so the page quotes exactly
  // what checkout charges. Restore the override + fix the DB row together later.
  const base = (CONFIG.cabinPricing && CONFIG.cabinPricing.sharingByDir) || { toNepal: 2000, toIndia: 1800 };
  return {
    toNepal: Math.max(0, Math.round(Number(base.toNepal) || 2000)),
    toIndia: Math.max(0, Math.round(Number(base.toIndia) || 1800))
  };
}
/* Sharing per-person base fare (offline) for a destination. Heading INTO
   Nepal (towards Rupaidiha) costs the outbound fare; heading back into
   India costs the return fare. Anything not on the Nepal list is treated
   as India-side, which is the safe default for a Gujarat operator. */
function sharingBasePP(toCity) {
  const d = sharingDir();
  return isNepalPoint(toCity) ? d.toNepal : d.toIndia;
}
/* Flat fare (26 Aug 2026): the 5% online discount is REMOVED, so the online
   price equals the offline base. Kept as a function so every caller reads one
   flat number in one place; the `online` flag no longer changes it. (Note the
   old `|| 5` fallback would have reinstated 5% whenever the pct was 0.) */
function sharingPP(toCity, online) {
  return sharingBasePP(toCity);
}
/* `perSeatOverride` (4 Sep 2026): the office can give ONE departure its own
   per-seat price on the Bus Calendar (schedules.fare_override). When the
   customer has picked such a bus, that number replaces the directional
   sharing fare here so the seat summary and the checkout total match what
   the server will actually charge. Private cabins keep the cabin price list,
   and every daily bus passes 0/undefined and prices exactly as before. */
function calcCabinFare(cabinType, bookingType, passengers, isOnline, toCity, perSeatOverride) {
  const pricing = CONFIG.cabinPricing;
  const ovr = Number(perSeatOverride) > 0 ? Number(perSeatOverride) : 0;
  if (bookingType === 'private') {
    const key = cabinType === 'single' ? 'single_1pax' : 'double_2pax';
    const p = pricing.private[key];
    const cabins = Math.ceil(passengers / p.capacity);
    const total = cabins * p.offline;   // flat — no online discount (26 Aug 2026)
    const offTotal = cabins * p.offline;
    return { base: offTotal, total: total, saved: offTotal - total, perPerson: Math.round(total / passengers), label: p.label + (cabins > 1 ? ' x' + cabins : ''), emoji: p.emoji, cabins: cabins };
  }
  if (bookingType === 'sharing') {
    let key;
    if (cabinType === 'single') key = 'single_2pax';
    else if (passengers === 3) key = 'double_3pax';
    else key = 'double_4pax';
    const p = pricing.sharing[key];
    // Per-person is a flat, direction-based fare (2000 to Nepal / 1800 to
    // India), same across every sharing tier — no online discount — unless
    // this particular departure was given its own price by the office.
    const off = ovr > 0 ? ovr : sharingBasePP(toCity);
    const perPerson = off;
    const total = perPerson * passengers;
    const offTotal = off * passengers;
    return { base: offTotal, total: total, saved: offTotal - total, perPerson: perPerson, label: p.label, emoji: p.emoji };
  }
  return { base: 0, total: 0, saved: 0, perPerson: 0, label: '', emoji: '' };
}

/* How many confirmed journeys has this phone number completed with us? */
function tripsForPhone(phone) {
  const p = digits(phone);
  if (p.length < 10) return 0;
  return DB.bookings.filter(b => b.status === 'confirmed' && digits(b.contact && b.contact.phone) === p).length;
}

/* QR helper — renders a QR with qrcodejs and resolves a PNG data-URI.
   Falls back to '' if the CDN library could not load (e.g. offline). */
/* ================================================================
   V7 UPGRADE — the QR library loads only when a QR is actually drawn
   (Aug 2026).
   It used to sit in <head> as a `defer` script. `defer` still blocks
   DOMContentLoaded, so on a slow or unreachable CDN — which is the
   normal case on this route's border stretch — the whole app stalled
   waiting for a library that only the ticket screen needs. Measured
   here: first paint 904ms but DOM interactive 9.3s.
   Same promise-singleton shape as loadMapLibre(): fetched once, every
   caller awaits the same load, and a failure resolves to no-QR rather
   than throwing (makeQR already degrades to an empty image).
================================================================ */
function loadQRCode() {
  if (window._qrPromise) return window._qrPromise;
  window._qrPromise = new Promise(function (resolve) {
    if (typeof QRCode !== 'undefined') { resolve(true); return; }
    var srcs = [
      'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
      'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js'
    ];
    var i = 0;
    (function next() {
      if (i >= srcs.length) { resolve(false); return; }   /* offline — ticket still renders, just without the QR */
      var s = document.createElement('script');
      s.src = srcs[i++];
      s.onload = function () { resolve(typeof QRCode !== 'undefined'); };
      s.onerror = next;
      document.head.appendChild(s);
    })();
  });
  return window._qrPromise;
}

function makeQR(text, size) {
  return loadQRCode().then(function () { return new Promise(resolve => {
    if (typeof QRCode === 'undefined') { resolve(''); return; }
    const holder = document.createElement('div');
    $('#qrwork').appendChild(holder);
    /* qrcodejs 1.0.0 throws "code length overflow" synchronously when the
       payload does not fit the version it picked. Uncaught, that rejected
       the promise and every caller's .then() never ran — so the failure
       showed up as a silently missing QR plus an unhandled rejection in the
       console. Degrade to an empty image, which is what every caller already
       checks for. */
    try {
      new QRCode(holder, { text: text, width: size || 220, height: size || 220, correctLevel: QRCode.CorrectLevel.M });
    } catch (e) {
      console.warn('QR encode failed (' + e.message + ') for a ' + String(text).length + '-char payload');
      holder.remove(); resolve(''); return;
    }
    setTimeout(() => {
      const c = holder.querySelector('canvas');
      const i = holder.querySelector('img');
      let url = '';
      try { url = c ? c.toDataURL('image/png') : (i ? i.src : ''); } catch (e) {}
      holder.remove(); resolve(url);
    }, 80);
  }); });
}

/* The one payload every ticket QR carries — screen, boarding pass and PDF.
   Three copies of this list had drifted apart, and all three were broken in
   the same two ways:

   1. They led with the literal 'SHG-TICKET'. admin/scan.php pulls the PNR out
      with /SHG-[A-Z0-9-]+/i, which matched that marker instead of the real
      reference — so a scan at boarding looked up "SHG-TICKET" and reported
      every genuine ticket as "not valid".
   2. They packed in the company name, CIN, phone and a '→' arrow. Those are
      identical on every ticket and already printed on it, and the multi-byte
      arrow pushed the payload past what qrcodejs would encode — so no QR was
      produced at all.

   The PNR now comes first (the scanner's regex finds the right thing) and
   the payload is short, ASCII, and carries only what a person checking a
   ticket at the roadside cannot read off the paper in front of them. */
function ticketQrPayload(b, r) {
  r = r || {};
  const ascii = (s) => String(s == null ? '' : s).replace(/[^\x20-\x7E]/g, '').trim();
  const parts = [
    ascii(b.id),
    ascii(b.date),
    ascii((b.seats || []).join('+')),
    ascii((r.from || '') + '-' + (r.to || '')),
    ascii(r.busNo || ''),
    ascii(b.agentCode || 'DIRECT')
  ];
  if (b.bookingType) parts.push(ascii(b.bookingType), ascii(b.cabinType || ''));
  return 'SHGTICKET:' + parts.join('|');   // no hyphen in the marker, so the scanner skips it
}

/* Tiny modal helper — one overlay reused by sign-in, waitlist & cancel.
   Android back-button integration: openModal pushes a synthetic history
   entry so the system back tap dismisses the sheet rather than popping
   the hash and leaving the modal floating over a different view. The
   popstate handler in 05-router.js reads window._shgModalClosingBack to
   distinguish programmatic close (which walks history itself to consume
   its own entry) from a real user back tap (which the browser has
   already walked, so history.back must NOT be re-called). */
function openModal(html) {
  const m = $('#shgModal');
  $('#shgModalBox').innerHTML = html;
  m.classList.add('open');
  document.body.style.overflow = 'hidden';
  try {
    if (!m.dataset.pushed) {
      history.pushState({ shgModal: 1 }, '', location.href);
      m.dataset.pushed = '1';
    }
  } catch (e) {}
}
function closeModal(fromPopState) {
  const m = $('#shgModal');
  if (!m || !m.classList.contains('open')) return;   // idempotent
  m.classList.remove('open');
  document.body.style.overflow = '';
  const wasPushed = m.dataset.pushed === '1';
  m.dataset.pushed = '';
  if (wasPushed && !fromPopState) {
    /* Programmatic close (X button, Escape, backdrop click, sign-in
       success, cancel confirm, etc.) — walk our synthetic history entry
       so the URL doesn't accumulate one dead back-step per modal open. */
    try { window._shgModalClosingBack = true; history.back(); } catch (e) { window._shgModalClosingBack = false; }
  }
}
