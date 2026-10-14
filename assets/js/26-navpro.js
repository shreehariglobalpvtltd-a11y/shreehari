/* =====================================================================
 *  26-navpro.js — S Hari Nav PRO (10 Oct 2026).
 *
 *  Owner: "S Hari Nav should be a pro version like the new Google Maps
 *  features: a 3D inside-the-vehicle view when you pick car / bike / bus,
 *  flight too; low-latency GPS; use the device's processor, RAM and
 *  storage so the map is quick and compact; works on a TV and every
 *  device; ready for the live bus animation from a GPS tracking system
 *  (coming soon) and CCTV live (coming soon)".
 *
 *  Loaded by snNavLoad() (13-admin-routes.js) right after 15-nav.js has
 *  resolved, in the same classic-script global scope. 15-nav.js keeps
 *  every map / motion / i18n helper; it reaches into this file through a
 *  handful of hooks (each marked "10 Oct 2026 (navpro)" there) and hands
 *  its closure helpers out as SN.ease / SN.toast / SN.kick / SN.navCamOn /
 *  SN.peekSet / SN.sheet / SN.focus / SN.fmtTime. Nothing here duplicates
 *  the rAF motion loop, the toast, the ease curve or the snT() reader.
 *
 *  What lives here
 *   1. Cockpit (inside-the-vehicle) view - a per-mode camera preset the
 *      motion loop reads every frame (snProCamTarget: pure arithmetic, no
 *      DOM) and a CSS-3D overlay (#snCockpitLayer, pointer-events:none)
 *      with the vehicle silhouette, speed, heading and the next turn.
 *   2. Flight mode - a great-circle arc (64 points), straight-line
 *      distance and a cruise-time ESTIMATE. No booking promise (the owner
 *      has not decided on a flight product). The routing engine is never
 *      asked; SN.routeGeo stays null so turn-by-turn never starts.
 *   3. Device-tiered engine - snDeviceProfile() (cores, memory, network,
 *      WebGL renderer, window.SHG_TIER, storage estimate) -> low / mid /
 *      high, applied to the MapLibre options (snProMapOpts), to the
 *      quota-aware offline save (snProSavePlan: never more than 25 % of the
 *      free quota, the MB said before anything downloads) and shown as the
 *      "This device" row in Layers. A low tier forces Fast mode: the switch
 *      is greyed with a "basic device" note and the cockpit is hidden, so
 *      nothing offers a choice that cannot work (snProLockLow).
 *   4. Low-latency GPS - the MEASURED fix cadence and age in the HUD
 *      ("GPS 1.0 s · ±8 m"; accuracy only until a real gap exists),
 *      compass fusion below 2 m/s so the arrow holds its heading at a
 *      standstill (snProFuseHeading); every sensor listener is removed in
 *      cleanupNav (SN.stopCompass + snProCleanup).
 *   5. TV / big screen + keyboard - html.shg-tv, or >= 1800 px wide with no
 *      hover / no pointer (a TV, not a mouse desktop), gives #view-nav the
 *      sn-tv class (bigger controls, 20 px type, strong focus ring); arrows
 *      pan, + / - zoom, R / L rotate, 3 = 3D, C = cockpit, Enter = Get
 *      directions, Esc = exit focus / back. Only while #view-nav is active
 *      and no input has focus; removed in cleanupNav. MapLibre's own
 *      keyboard handler is switched off (snProMapOpts) so one key never
 *      drives two animations.
 *   6. Honest live status - two rows in the track card and in Layers:
 *      "Live bus (driver's phone sharing)" reflects the real kv feed, and
 *      "this phone is sharing" only after the server ACCEPTED a publish
 *      from a signed-in staff phone in the last 30 s;
 *      "Fleet GPS unit + CCTV live view" carries a Coming-soon badge. No
 *      fake position, no fake video.
 *
 *  The future fleet-GPS entry point (the only public API of this file):
 *
 *      window.SHG_NAV.onBusFix({lat, lng, bearing, spd, ts})
 *        lat / lng   WGS-84 degrees (required, finite numbers)
 *        bearing     degrees clockwise from north, or null
 *        spd         metres per second, or null
 *        ts          epoch milliseconds of the fix (default: now)
 *        returns     true when the fix was delivered
 *
 *  A GPS-unit feed (or a test page) pushes a fix here and it flows into
 *  the SAME path the driver's phone uses (SN.busFixSink = onBusFix inside
 *  the "Track live bus" toggle of 15-nav.js): bus marker, dead-reckoning
 *  glide, trail, ETA, current / next stop, follow camera. Tracking is
 *  switched on if it was off. Until a fix arrives the status rows say
 *  "not live", and the fleet-unit + CCTV row says "coming soon".
 *
 *  Performance rules kept: one rAF loop only (SN.motionAnim, in 15-nav.js),
 *  no per-frame DOM reads (overlay text is written on a GPS fix, and only
 *  when the value changed), passive listeners, everything stopped when the
 *  view is left (snProCleanup) or the tab is hidden (visibilitychange
 *  pauses the sway; the motion loop already sleeps there).
 * ===================================================================== */

/* All pro state in one namespace (SN stays the navigator's own). */
var SNP = { profile:null, storage:null, storageAt:0, cockpitPref:null, armed:false, shown:false, builtMode:'',
  attached:false, keyFn:null, visFn:null, resizeFn:null, dragFn:null, ageT:null, lastFixAt:0, fixGapMs:0, lastAcc:null,
  heldHdg:null, compassLP:null, tvHint:false, flightOn:false, flightKm:0, kvAt:0, lastTurnKey:'', lastGauge:'' };

/* Per-vehicle camera presets (owner's spec): pitch / zoom of the driving
   camera while the cockpit is on, and how far ahead (km) the road bearing
   is read so a bus looks further down the road than a cycle. */
var SNP_CAM = {
  auto:       { pitch:62, zoom:17,   look:0.07 },
  motorcycle: { pitch:66, zoom:17.5, look:0.06 },
  bus:        { pitch:55, zoom:16,   look:0.12 },
  bicycle:    { pitch:45, zoom:18,   look:0.03 },
  pedestrian: { pitch:45, zoom:18,   look:0.025 },
  flight:     { pitch:30, zoom:9,    look:0.5 }
};
/* The mode colours of 15-nav.js (SN_MODE_CLR is closure-local there);
   flight is the one new entry, the royal blue of the design tokens. */
var SNP_CLR = { auto:'#4285F4', motorcycle:'#7B2FBE', bus:'#178A50', bicycle:'#FF9800', pedestrian:'#00BCD4', flight:'#1E6FD9' };
var SNP_FLIGHT_KMH = 750;        /* typical airliner cruise - an estimate, labelled so */
var SNP_TILE_KB = 45;            /* a typical vector tile, used only for the MB estimate */
var SNP_COCKPIT_KEY = 'shg:navCockpit';
/* a big screen driven by a remote, not a mouse (see snProTvCheck) */
var SNP_TV_MQ = '(min-width:1800px) and (hover:none), (min-width:1200px) and (any-pointer:none)';
/* Valhalla maneuver type -> arrow rotation on the cockpit (degrees, clockwise). */
var SNP_TURN_ROT = { 7:0, 8:0, 17:0, 22:0, 9:45, 18:45, 20:45, 23:45, 10:90, 11:90, 16:-45, 19:-45, 21:-45, 24:-45, 14:-90, 15:-90, 12:180, 13:180 };

function snProEl(id){ return document.getElementById(id); }
function snProToast(key, vars){ try{ if(typeof SN.toast==='function') SN.toast(snT(key, vars)); }catch(e){} }
/* One answer for "may this move?": the in-app Motion switch and the OS preference. */
function snProReduced(){
  try{
    if(document.documentElement.getAttribute('data-motion')==='reduced') return true;
    if(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches) return true;
  }catch(e){}
  return false;
}

/* ================================================================
   3. DEVICE PROFILE - read once, cached. Nothing here asks the user
   anything; every API is optional and guarded. The WebGL renderer
   string is read from a throwaway context that is released at once.
================================================================ */
function snDeviceProfile(){
  if(SNP.profile) return SNP.profile;
  var n=navigator, cores=+n.hardwareConcurrency||0, mem=+n.deviceMemory||0;
  var conn=n.connection||n.mozConnection||n.webkitConnection||null;
  var eff=conn?String(conn.effectiveType||''):'', save=!!(conn&&conn.saveData);
  var gpu='';
  try{
    var cv=document.createElement('canvas'), gl=cv.getContext('webgl')||cv.getContext('experimental-webgl');
    if(gl){
      var dbg=gl.getExtension('WEBGL_debug_renderer_info');
      if(dbg) gpu=String(gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL)||'');
      var lose=gl.getExtension('WEBGL_lose_context'); if(lose) lose.loseContext();
    }
  }catch(e){}
  /* the perf package may have decided already (window.SHG_TIER) - it wins */
  var forced=(typeof window.SHG_TIER==='string'&&/^(low|mid|high)$/.test(window.SHG_TIER))?window.SHG_TIER:null;
  var score=0;
  if(cores>=8) score+=2; else if(cores>=4) score+=1;
  if(mem>=8) score+=2; else if(mem>=4) score+=1;
  if(/swiftshader|llvmpipe|software|microsoft basic render/i.test(gpu)) score-=3;
  else if(/apple|adreno ?6|adreno ?7|mali-g7|mali-g9|nvidia|radeon|iris|arc|rtx|gtx|immortalis/i.test(gpu)) score+=1;
  if(save||/(^|[^0-9])(2g|3g)$/.test(eff)) score-=2;
  var weak=(mem>0&&mem<=2)||(cores>0&&cores<=2);
  var tier=forced||(weak||score<=0?'low':(score>=4?'high':'mid'));
  SNP.profile={ tier:tier, cores:cores, mem:mem, gpu:gpu, net:eff, saveData:save, forced:!!forced,
    tiles: tier==='low'?60:(tier==='mid'?150:400) };
  snProStorage();   /* async: fills the storage budget for the Layers row / offline save */
  return SNP.profile;
}
/* navigator.storage.estimate(), cached 30 s -> {quota, usage, free} or null. */
function snProStorage(){
  var now=Date.now();
  if(SNP.storage&&now-SNP.storageAt<30000) return Promise.resolve(SNP.storage);
  try{
    if(navigator.storage&&typeof navigator.storage.estimate==='function'){
      return navigator.storage.estimate().then(function(e){
        var q=+e.quota||0, u=+e.usage||0;
        SNP.storage={ quota:q, usage:u, free:Math.max(0,q-u) }; SNP.storageAt=Date.now();
        return SNP.storage;
      }).catch(function(){ return null; });
    }
  }catch(e){}
  return Promise.resolve(null);
}
/* A low-tier device opens the light map whatever the saved "Full map" switch says. */
function snProForceLite(){ return snDeviceProfile().tier==='low'; }
/* Applied by 15-nav.js to the maplibregl.Map options right before the map is built. */
function snProMapOpts(o){
  var p=snDeviceProfile(), t=p.tier, dpr=window.devicePixelRatio||1;
  o.maxTileCacheSize=p.tiles;
  o.pixelRatio=Math.min(dpr, t==='low'?1.5:((t==='mid'||SN.lite)?2:dpr));
  o.antialias=(t==='high')&&!SN.lite;
  o.fadeDuration=(SN.lite||t==='low')?0:(t==='mid'?150:250);
  /* terrain / 3D buildings stay mid+ : a low tier is forced into Fast mode
     (snProForceLite), and Fast mode never builds them (15-nav.js). */
  o.maxPitch=t==='low'?60:70;
  if(t==='low') o.prefetchZoomDelta=1;
  /* review fix (10 Oct): snProKey is the ONE keyboard driver. With MapLibre's
     own handler on too, a focused canvas ran two easeTo animations per arrow /
     +/- press. R / L cover rotation. If this bundle fails to load, this line
     never runs and MapLibre keeps its keyboard as before. */
  o.keyboard=false;
  SN.proTier=t;
  return o;
}
/* "This device" row in Layers: cores · GB RAM · tier · MB of site data.
   navigator.deviceMemory is rounded down and capped at 8, so 8 reads "8+";
   storage.estimate().usage is the WHOLE site's data (app shell, saved
   tickets, map pieces), so it is labelled "site data", never "saved maps". */
function snProDeviceRow(){
  var el=snProEl('snDeviceV'); if(!el) return;
  var p=snDeviceProfile();
  var paint=function(st){
    var tk={low:'snTierLow',mid:'snTierMid',high:'snTierHigh'}[p.tier]||'snTierMid';
    var mem=p.mem>=8?'8+':(p.mem||'?');
    el.textContent=snT('snDeviceRowFmt',{ c:p.cores||'?', m:mem, t:snT(tk), mb:(st&&st.usage!=null)?Math.round(st.usage/1048576):'?' });
  };
  paint(SNP.storage);
  snProStorage().then(paint);
}
/* The offline save asks here before it downloads: the batch is capped at
   25 % of the free quota and the toast says the MB. Returns {urls, mb, msg},
   or false when there is no room (already said to the user). */
function snProSavePlan(urls){
  return snProStorage().then(function(st){
    var perMb=1024/SNP_TILE_KB, list=urls;
    if(st&&st.quota>0){
      var capMb=st.free/1048576*0.25;
      if(capMb<1){ snProToast('snSaveNoRoom'); return false; }
      var maxN=Math.max(1,Math.floor(capMb*perMb));
      if(list.length>maxN) list=list.slice(0,maxN);
    }
    var mb=list.length/perMb;
    return { urls:list, mb:mb, msg:snT('snSaveQuota',{ n:list.length, mb:mb<1?'<1':String(Math.round(mb)) }) };
  }).catch(function(){ return { urls:urls, mb:0, msg:snT('snSaving',{n:urls.length}) }; });
}

/* ================================================================
   1. COCKPIT VIEW
================================================================ */
function snProCockpitWanted(){
  if(SNP.cockpitPref==='1') return true;
  if(SNP.cockpitPref==='0') return false;
  return snDeviceProfile().tier==='high'&&!SN.lite;     /* default: on for a fast device, off in Fast mode */
}
/* "armed" = the preset drives the camera and the overlay may show. */
function snProCockpitArmed(){ return snProCockpitWanted()&&!SN.lite&&!SN.weak&&snDeviceProfile().tier!=='low'; }
/* Read by the motion loop EVERY FRAME while a route is followed: pure
   arithmetic. Faster = a little further out, around the vehicle's preset. */
function snProCamTarget(){
  if(!SNP.armed) return null;
  var pc=SNP_CAM[SN.mode]||SNP_CAM.auto, g=SN.gpsPos||{}, kmh=(g.spd!=null&&!isNaN(g.spd))?g.spd*3.6:0;
  return { pitch:pc.pitch, zoom:pc.zoom-Math.max(0,Math.min(1.4,(kmh-30)/45)) };
}
/* Vehicle interior silhouettes - inline SVG, one per mode, drawn into the
   lower third of the screen with a CSS perspective tilt (views.css). The
   .ck strokes take the mode colour (--ck). */
function snProCockpitSvg(mode){
  var A='<svg class="sn-ck-art" viewBox="0 0 400 240" preserveAspectRatio="none" aria-hidden="true">';
  if(mode==='motorcycle') return A
    +'<path class="ckf" d="M0 240V196Q200 170 400 196V240Z"/><path class="ck" d="M34 176Q200 128 366 176"/>'
    +'<rect class="ckg" x="18" y="166" width="34" height="14" rx="7"/><rect class="ckg" x="348" y="166" width="34" height="14" rx="7"/>'
    +'<path class="ck2" d="M56 168 44 120M344 168 356 120"/><ellipse class="ckm" cx="40" cy="108" rx="22" ry="13"/><ellipse class="ckm" cx="360" cy="108" rx="22" ry="13"/>'
    +'<circle class="ckm" cx="200" cy="168" r="24"/></svg>';
  if(mode==='bus') return A
    +'<path class="ckf" d="M0 240V188H400V240Z"/><path class="ck2" d="M10 0V190M390 0V190"/>'
    +'<rect class="ckm" x="4" y="46" width="40" height="78" rx="9"/><rect class="ckm" x="356" y="46" width="40" height="78" rx="9"/>'
    +'<path class="ck2" d="M44 90H70M356 90H330"/><ellipse class="ck" cx="118" cy="238" rx="74" ry="38"/><path class="ck2" d="M118 200V238M50 238H186"/>'
    +'<path class="ck2" d="M210 60Q290 40 380 70" stroke-dasharray="3 5"/></svg>';
  if(mode==='bicycle') return A
    +'<path class="ck" d="M52 212Q118 150 200 160Q282 150 348 212"/><path class="ck" d="M200 160V240"/>'
    +'<rect class="ckg" x="34" y="200" width="30" height="14" rx="7"/><rect class="ckg" x="336" y="200" width="30" height="14" rx="7"/></svg>';
  if(mode==='pedestrian') return A+'<path class="ck2" d="M14 200v26h26M386 200v26h-26"/></svg>';
  if(mode==='flight') return A
    +'<path class="ck2" d="M20 20v30M20 20h30M380 20v30M380 20h-30M20 220v-30M20 220h30M380 220v-30M380 220h-30"/>'
    +'<circle class="ck" cx="200" cy="130" r="9"/><path class="ck" d="M150 130h32M218 130h32M200 110v-10"/>'
    +'<path class="ck2" d="M120 70h50M230 70h50M130 190h40M230 190h40M100 130h30M270 130h30"/>'
    +'<path class="ck2" d="M140 14h120M160 10v8M180 10v8M200 6v12M220 10v8M240 10v8"/></svg>';
  /* car (auto) */
  return A
    +'<path class="ckf" d="M0 240V156Q200 112 400 156V240Z"/><path class="ck2" d="M0 0 48 150M400 0 352 150"/><path class="ck2" d="M60 150Q200 124 340 150"/>'
    +'<circle class="ck" cx="150" cy="238" r="80"/><circle class="ck" cx="150" cy="238" r="18"/><path class="ck" d="M150 220V170M94 262l40-14M206 262l-40-14"/></svg>';
}
/* Builds the overlay once, swaps the silhouette when the mode changed. */
function snProCockpitBuild(){
  var L=snProEl('snCockpitLayer'); if(!L) return null;
  if(!L.firstChild){
    L.innerHTML='<div class="sn-ck-tint"></div><div class="sn-ck-vehicle" id="snCkVehicle"></div>'
      +'<div class="sn-ck-gauge"><b id="snCkSpeed">0</b><span>km/h</span></div>'
      +'<div class="sn-ck-turn" id="snCkTurn"><svg viewBox="0 0 48 48" aria-hidden="true"><path id="snCkArrow" d="M24 6 10 24h9v18h10V24h9z"/></svg><em id="snCkTurnIco" hidden></em><small id="snCkTurnD">—</small></div>'
      +'<div class="sn-ck-head" id="snCkHead">—</div>';
  }
  var mode=SN.mode||'auto';
  if(SNP.builtMode!==mode){
    var v=snProEl('snCkVehicle'); if(v) v.innerHTML=snProCockpitSvg(mode);
    L.style.setProperty('--ck',SNP_CLR[mode]||SNP_CLR.auto);
    L.setAttribute('data-ck',mode);
    SNP.builtMode=mode; SNP.lastTurnKey='';
  }
  return L;
}
function snProCockpitShow(show){
  var L=snProEl('snCockpitLayer'), nv=snProEl('view-nav');
  show=!!show;
  if(show&&L) snProCockpitBuild();
  if(L) L.classList.toggle('sn-ck-on',show);
  if(nv) nv.classList.toggle('sn-cockpit-on',show);
  SNP.shown=show;
}
/* The overlay is on screen only while the camera is actually inside the
   vehicle: following a route with GPS (SN.navCamOn), or the flight arc. */
function snProCockpitSync(){
  var want=SNP.armed&&!document.hidden&&(
    (typeof SN.navCamOn==='function'&&SN.navCamOn()) || (SNP.flightOn&&SN.mode==='flight'));
  if(want!==SNP.shown) snProCockpitShow(want);
}
function snProCockpitPaint(){
  SNP.armed=snProCockpitArmed();
  var on=snProCockpitWanted();
  var b=snProEl('snCockpit'); if(b){ b.classList.toggle('on',on&&SNP.armed); b.setAttribute('aria-pressed',on?'true':'false'); }
  var c=snProEl('snLyCockpit'); if(c) c.checked=on;
  snProCockpitSync();
}
/* Read by snTargetBearing (15-nav.js) every frame: how far ahead (km) the
   road bearing is taken for the CURRENT mode while the cockpit is armed.
   Live, not cached - the S Hari preset and a restored route change SN.mode
   without a chip click (review fix, 10 Oct). Pure arithmetic. */
function snProLook(){ return SNP.armed?(SNP_CAM[SN.mode]||SNP_CAM.auto).look:null; }
function snProSetCockpit(on, save){
  SNP.cockpitPref=on?'1':'0';
  if(save){ try{ localStorage.setItem(SNP_COCKPIT_KEY,SNP.cockpitPref); }catch(e){} }
  snProCockpitPaint();
  /* the toast names a fix only when one exists: unticking Fast mode helps on a
     mid / fast device; a basic / weak one (data saver, reduce motion, low RAM)
     cannot carry the cockpit at all, so it is told so plainly */
  if(on&&!SNP.armed) snProToast((SN.weak||snDeviceProfile().tier==='low')?'snCockpitNoDev':'snCockpitLite');
  else snProToast(on?'snCockpitOn':'snCockpitOff');
  if(typeof SN.kick==='function') SN.kick();     /* the camera settles into the new preset */
}
function snProModeChanged(){
  snProCockpitPaint();
  if(SNP.shown) snProCockpitBuild();
  if(SN.mode!=='flight') snProFlightClear();
  if(typeof SN.kick==='function') SN.kick();
}
function snProCardinal(h){
  var names=['N','NE','E','SE','S','SW','W','NW'];
  return names[Math.round((((h%360)+360)%360)/45)%8];
}
/* Next-turn arrow on the dashboard - written only when the value changed. */
function snProTurnPaint(){
  var t=snProEl('snCkTurn'); if(!t) return;
  var mv=SN.routeManeuvers;
  if(!mv||!mv.length){ if(SNP.lastTurnKey!==''){ SNP.lastTurnKey=''; t.classList.remove('show'); } return; }
  var at=SN.routeProgKm||0, i=0;
  while(i<mv.length-1&&mv[i+1]._distKm<=at) i++;
  var up=i<mv.length-1, m=up?mv[i+1]:mv[i];
  var rem=up?Math.max(0,m._distKm-at):Math.max(0,SN.routeTotalKm-at);
  var dist=rem<1?Math.round(rem*1000/50)*50+' m':rem.toFixed(1)+' km';
  var key=m.type+':'+dist;
  if(key===SNP.lastTurnKey) return;
  SNP.lastTurnKey=key;
  var arrow=snProEl('snCkArrow'), ico=snProEl('snCkTurnIco');
  var special=(m.type>=4&&m.type<=6)?'🏁':((m.type===26||m.type===27)?'🔄':((m.type===28||m.type===29)?'⛴️':''));
  if(special){ ico.textContent=special; ico.hidden=false; arrow.style.display='none'; }
  else { ico.hidden=true; arrow.style.display=''; arrow.style.transform='rotate('+(SNP_TURN_ROT[m.type]||0)+'deg)'; }
  snProEl('snCkTurnD').textContent=dist;
  t.classList.add('show');
}

/* ================================================================
   4. LOW-LATENCY GPS - cadence / age in the HUD, heading at a standstill
================================================================ */
function snProGpsCell(acc){
  var el=snProEl('snGps'); if(!el) return;
  /* only a MEASURED gap between two real fixes is shown as the cadence -
     never SN.gpsIntervalMs (a 1000 ms default) - so the first fix reads
     accuracy only ("GPS ±20 m") and nothing claims a cadence the phone did
     not deliver (review fix, 10 Oct) */
  var m=(acc!=null&&!isNaN(acc))?Math.round(acc):'?', gap=SNP.fixGapMs;
  el.textContent=gap>0?snT('snGpsFix',{ s:(gap/1000).toFixed(1), m:m }):snT('snGpsAcc',{ m:m });
  el.classList.remove('stale');
  SNP.lastAcc=acc;
}
/* Every 2 s while GPS runs: a fix older than 4 s is said so (orange). */
function snProGpsAge(){
  /* while this phone broadcasts, its own status row follows the server's
     answers (a publish lands ~10 s apart, accepted or not) */
  if(SN.driverBroadcast) snProLiveStatus(false);
  if(!SNP.lastFixAt||SN.watchId==null) return;
  var age=Math.round((Date.now()-SNP.lastFixAt)/1000);
  if(age<4) return;
  var el=snProEl('snGps'); if(!el) return;
  el.textContent=snT('snGpsOld',{s:age}); el.classList.add('stale');
}
/* Called from onSnPos after the HUD is written (c = position.coords). */
function snProOnFix(c, hdg){
  var now=Date.now(), gap=SNP.lastFixAt?now-SNP.lastFixAt:0;
  /* the one-shot getCurrentPosition fix and the first watchPosition fix land
     a few ms apart: a gap under 200 ms is not a cadence, so it is not kept
     (a gap over a minute is a pause, not a cadence: back to accuracy only) */
  if(gap>60000) SNP.fixGapMs=0; else if(gap>=200) SNP.fixGapMs=gap;
  SNP.lastFixAt=now;
  snProGpsCell(c.accuracy);
  snProCockpitSync();
  if(!SNP.shown) return;
  if(SNP.builtMode!==(SN.mode||'auto')) snProCockpitBuild();   /* the preset / a restored route changed the mode */
  var spd=c.speed!=null&&!isNaN(c.speed)?Math.max(0,Math.round(c.speed*3.6)):0;
  var head=(hdg!=null&&!isNaN(hdg))?Math.round(hdg)+'° '+snProCardinal(hdg):'—';
  var g=spd+'|'+head;
  if(g!==SNP.lastGauge){
    SNP.lastGauge=g;
    var sp=snProEl('snCkSpeed'); if(sp) sp.textContent=spd;
    var hd=snProEl('snCkHead'); if(hd) hd.textContent=head;
  }
  var L=snProEl('snCockpitLayer'); if(L) L.classList.toggle('sn-ck-moving',spd>=5&&!snProReduced());
  snProTurnPaint();
}
/* Below 2 m/s the GPS course is noise: the compass (low-passed on the
   circle so a jittery magnetometer cannot twitch the arrow) takes over,
   and with no compass the last good heading is HELD instead of spinning
   or vanishing. Above 2 m/s the course wins, as before. */
function snProFuseHeading(c, gpsHdg, hdg){
  var spd=(c&&c.speed!=null&&!isNaN(c.speed))?c.speed:0;
  var cmp=SN.compassHeading;
  if(spd<2){
    if(cmp!=null&&!isNaN(cmp)){
      if(SNP.compassLP==null) SNP.compassLP=cmp;
      else { var d=((cmp-SNP.compassLP)%360+540)%360-180; SNP.compassLP=((SNP.compassLP+d*0.25)%360+360)%360; }
      hdg=SNP.compassLP;
    } else if(SNP.heldHdg!=null) hdg=SNP.heldHdg;
  } else { SNP.compassLP=null; if(gpsHdg!=null&&!isNaN(gpsHdg)) hdg=gpsHdg; }
  if(hdg!=null&&!isNaN(hdg)) SNP.heldHdg=hdg;
  return hdg;
}

/* ================================================================
   2. FLIGHT MODE - no air routing exists, so: a great-circle arc, the
   straight-line distance and a cruise ESTIMATE. Valhalla is never asked.
================================================================ */
function snProArc(a, b, n){
  var r=Math.PI/180, la1=a.lat*r, lo1=a.lng*r, la2=b.lat*r, lo2=b.lng*r;
  var d=2*Math.asin(Math.sqrt(Math.pow(Math.sin((la2-la1)/2),2)+Math.cos(la1)*Math.cos(la2)*Math.pow(Math.sin((lo2-lo1)/2),2)));
  if(!(d>1e-9)) return [[a.lng,a.lat],[b.lng,b.lat]];
  var out=[], sd=Math.sin(d);
  for(var i=0;i<=n;i++){
    var f=i/n, A=Math.sin((1-f)*d)/sd, B=Math.sin(f*d)/sd;
    var x=A*Math.cos(la1)*Math.cos(lo1)+B*Math.cos(la2)*Math.cos(lo2);
    var y=A*Math.cos(la1)*Math.sin(lo1)+B*Math.cos(la2)*Math.sin(lo2);
    var z=A*Math.sin(la1)+B*Math.sin(la2);
    out.push([Math.atan2(y,x)/r, Math.atan2(z,Math.sqrt(x*x+y*y))/r]);
  }
  return out;
}
/* The road route (if one was drawn before the Flight chip) is taken off
   the map so turn-by-turn cannot continue under the arc. */
function snProDropRoadRoute(){
  var m=SN.map; if(!m) return;
  SN.routeGeo=null; SN.routeCoords=null; SN.routeManeuvers=null; SN.routeCumKm=null;
  SN.routeTotalKm=0; SN.routeProgKm=0; SN.offRouteSince=0; SN.lastSpokenInstr=null; SN.altRoutes=null; SN.lastTripSum=null;
  try{
    ['sn-rline','sn-rglow','sn-rdone','sn-alt0','sn-alt1'].forEach(function(l){ if(m.getLayer(l)) m.removeLayer(l); });
    ['sn-route-ahead','sn-route-done','sn-alt-0','sn-alt-1'].forEach(function(s){ if(m.getSource(s)) m.removeSource(s); });
  }catch(e){}
  ['snSummary','snBanner','snNavCard','snAltBar','snArrival'].forEach(function(id){ var el=snProEl(id); if(el) el.classList.remove('show'); });
  if(typeof SN.focus==='function') SN.focus(false);
}
function snProFlightText(){
  var km=SNP.flightKm, el=snProEl('snFlightDist');
  var t=(typeof SN.fmtTime==='function')?SN.fmtTime(km/SNP_FLIGHT_KMH*3600):Math.round(km/SNP_FLIGHT_KMH*60)+' min';
  if(el) el.textContent=snT('snFlightDist',{ km:Math.round(km), t:t, v:SNP_FLIGHT_KMH });
  if(typeof SN.peekSet==='function') SN.peekSet(snT('snFlightPeek',{km:Math.round(km)}), snT('snFlightPeekSub'));
}
function snProFlight(){
  var m=SN.map; if(!m||!SN.origin||!SN.dest) return;
  snProDropRoadRoute();
  var pts=snProArc(SN.origin,SN.dest,64);
  var geo={type:'Feature',geometry:{type:'LineString',coordinates:pts}};
  try{
    if(m.getSource('sn-flight')) m.getSource('sn-flight').setData(geo);
    else{
      m.addSource('sn-flight',{type:'geojson',data:geo});
      m.addLayer({id:'sn-flight-glow',type:'line',source:'sn-flight',layout:{'line-cap':'round','line-join':'round'},
        paint:{'line-color':SNP_CLR.flight,'line-width':10,'line-opacity':.18,'line-blur':4}});
      m.addLayer({id:'sn-flight-l',type:'line',source:'sn-flight',layout:{'line-cap':'round','line-join':'round'},
        paint:{'line-color':SNP_CLR.flight,'line-width':3.5,'line-dasharray':[2,2.5]}});
    }
  }catch(e){}
  SNP.flightKm=haversineKm(SN.origin.lat,SN.origin.lng,SN.dest.lat,SN.dest.lng);
  SNP.flightOn=true;
  var nv=snProEl('view-nav'); if(nv) nv.classList.add('sn-flight');
  var card=snProEl('snFlightCard'); if(card) card.classList.add('show');
  snProFlightText();
  try{
    var b=pts.reduce(function(bb,c){ return bb.extend(c); }, new maplibregl.LngLatBounds(pts[0],pts[0]));
    var still=SN.weak||snProReduced();
    m.fitBounds(b,{padding:{top:110,bottom:260,left:60,right:60},duration:still?0:900,pitch:0,bearing:0});
    /* the bird's-eye preset (pitch 30) once the whole arc is in view */
    if(!still&&typeof SN.ease==='function') m.once('moveend',function(){ if(SNP.flightOn) SN.ease({pitch:SNP_CAM.flight.pitch,duration:900}); });
  }catch(e){}
  if(typeof SN.sheet==='function') SN.sheet('half');
  snProCockpitSync();
}
function snProFlightClear(){
  if(!SNP.flightOn) return;
  SNP.flightOn=false;
  var m=SN.map;
  try{ if(m){ ['sn-flight-l','sn-flight-glow'].forEach(function(l){ if(m.getLayer(l)) m.removeLayer(l); }); if(m.getSource('sn-flight')) m.removeSource('sn-flight'); } }catch(e){}
  var nv=snProEl('view-nav'); if(nv) nv.classList.remove('sn-flight');
  var card=snProEl('snFlightCard'); if(card) card.classList.remove('show');
  snProCockpitSync();
}

/* ================================================================
   6. HONEST LIVE STATUS + the fleet-GPS entry point
================================================================ */
function snProIsStaff(){ return !!(window.SHG_BOOT&&window.SHG_BOOT.staff); }
/* "This phone is sharing" is said only when it is TRUE for other phones: the
   driver toggle is on, on a signed-in staff phone (only staff publish to the
   shared livebus key - anyone else shares with this browser only), the GPS
   watch is running, and the server accepted a publish in the last 30 s
   (SN.kvPushOkAt, set by onSnPos in 15-nav.js). A blocked location or a
   phone still waiting for its first fix stays "not live" (review fix, 10 Oct). */
function snProSelfLive(){
  return !!SN.driverBroadcast&&snProIsStaff()&&SN.watchId!=null&&!!SN.kvPushOkAt&&(Date.now()-SN.kvPushOkAt)<30000;
}
function snProLiveStatus(peekKv){
  var at=Math.max(SN.trackLastTs||0, SNP.kvAt||0), age=at?Date.now()-at:Infinity, txt, st;
  if(age<180000){
    var secs=Math.round(age/1000);
    var when=secs<5?snT('snJustNow'):(secs<60?snT('snSecAgo',{n:secs}):snT('snMinAgo',{n:Math.round(secs/60)}));
    txt=snT('snLiveOn',{t:when}); st='live';
  } else if(snProSelfLive()){ txt=snT('snLiveSelf'); st='live'; }
  else if(SN.driverBroadcast&&!snProIsStaff()){ txt=snT('snLiveLocal'); st='wait'; }
  else { txt=snT('snLiveWait'); st='wait'; }
  ['snStDriverV','snTcDriverV'].forEach(function(id){ var el=snProEl(id); if(el){ el.textContent=txt; el.setAttribute('data-st',st); } });
  /* opening Layers outside tracking mode: one read of the shared key, so the
     row is honest even before "Track live bus" was pressed */
  if(peekKv&&!SN.trackingMode&&typeof store!=='undefined'&&store&&typeof store.get==='function'){
    try{
      store.get('shg:livebus',null).then(function(lb){
        if(lb&&lb.gps&&lb.at&&lb.at>SNP.kvAt){ SNP.kvAt=+lb.at; snProLiveStatus(false); }
      }).catch(function(){});
    }catch(e){}
  }
}
/* window.SHG_NAV.onBusFix - see the header. */
function snProBusFix(fix){
  if(!fix||typeof fix.lat!=='number'||typeof fix.lng!=='number'||!isFinite(fix.lat)||!isFinite(fix.lng)) return false;
  if(!SN.map) return false;
  if(!SN.trackingMode){ var t=snProEl('snTrackToggle'); if(t) t.click(); }
  if(typeof SN.busFixSink!=='function') return false;
  SN.busFixSink({ lat:fix.lat, lng:fix.lng,
    bearing:(fix.bearing!=null&&isFinite(+fix.bearing))?+fix.bearing:null,
    spd:(fix.spd!=null&&isFinite(+fix.spd))?+fix.spd:null,
    ts:(fix.ts&&isFinite(+fix.ts))?+fix.ts:Date.now() });
  SNP.kvAt=Math.max(SNP.kvAt,(fix.ts&&isFinite(+fix.ts))?+fix.ts:Date.now());
  snProLiveStatus(false);
  return true;
}

/* ================================================================
   5. TV / BIG SCREEN + KEYBOARD
================================================================ */
function snProTvCheck(){
  var nv=snProEl('view-nav'); if(!nv) return;
  var tv=false;
  /* width alone is not a TV: a maximised browser on a Full-HD office monitor
     is 1920 px too. A TV browser has no hover (remote / D-pad) or no pointer
     at all; a mouse desktop keeps the normal layout (review fix, 10 Oct). */
  try{ tv=document.documentElement.classList.contains('shg-tv')||(window.matchMedia&&window.matchMedia(SNP_TV_MQ).matches); }catch(e){}
  nv.classList.toggle('sn-tv',!!tv);
  if(tv&&!SNP.tvHint&&nv.classList.contains('active')){ SNP.tvHint=true; snProToast('snKeysHint'); }
}
function snProKey(e){
  /* a key another handler already used is left alone (MapLibre's own keyboard
     handler is off - snProMapOpts - so this is the one map driver) */
  var m=SN.map; if(!m||!e||e.defaultPrevented||e.altKey||e.ctrlKey||e.metaKey) return;
  var nv=snProEl('view-nav'); if(!nv||!nv.classList.contains('active')) return;
  var ae=document.activeElement;
  if(ae&&(/^(INPUT|TEXTAREA|SELECT)$/.test(ae.tagName)||ae.isContentEditable)) return;
  /* Enter / Space on a focused button or chip is that control's own click */
  if((e.key==='Enter'||e.key===' ')&&ae&&(/^(BUTTON|A|LABEL)$/.test(ae.tagName)||ae.getAttribute('role')==='button')) return;
  var step=Math.round((m.getContainer().clientWidth||800)*0.12), dur=SN.weak?0:200, did=true;
  var pan=function(dx,dy){ m.fire('dragstart'); m.panBy([dx,dy],{duration:dur}); };
  var click=function(id){ var b=snProEl(id); if(b) b.click(); };
  switch(e.key){
    case 'ArrowUp':    pan(0,-step); break;
    case 'ArrowDown':  pan(0,step); break;
    case 'ArrowLeft':  pan(-step,0); break;
    case 'ArrowRight': pan(step,0); break;
    case '+': case '=': m.zoomIn({duration:dur}); break;
    case '-': case '_': m.zoomOut({duration:dur}); break;
    case 'r': case 'R': SN.userRotated=true; m.easeTo({bearing:m.getBearing()+15,duration:dur}); break;
    case 'l': case 'L': SN.userRotated=true; m.easeTo({bearing:m.getBearing()-15,duration:dur}); break;
    case '3': click('sn3D'); break;
    case 'c': case 'C': snProSetCockpit(!snProCockpitWanted(),true); break;
    case 'Enter':
      if(SN.origin&&SN.dest&&!(snProEl('snGo')||{}).disabled) click('snGo'); else did=false;
      break;
    case 'Escape':
      var panel=snProEl('snLayersPanel');
      if(panel&&panel.classList.contains('show')) click('snLayers');
      else if(nv.classList.contains('sn-focus')) click('snFocusExit');
      else click('snBack');
      break;
    default: did=false;
  }
  if(did) e.preventDefault();
}

/* ================================================================
   LIFECYCLE - init once (end of initNavApp), attach on every entry
   (renderNav), cleanup on every exit (cleanupNav).
================================================================ */
function snProAttach(){
  if(SNP.attached) return;
  SNP.attached=true;
  SNP.keyFn=snProKey; document.addEventListener('keydown',SNP.keyFn);
  SNP.visFn=function(){
    var L=snProEl('snCockpitLayer'); if(L) L.classList.toggle('sn-ck-paused',!!document.hidden);
    snProCockpitSync();
  };
  document.addEventListener('visibilitychange',SNP.visFn);
  /* one TV / large-screen check per frame however fast the resize events come (perf-hints rule) */
  window.addEventListener('resize',SNP.resizeFn=function(){ if(SNP.rzRaf) return; SNP.rzRaf=requestAnimationFrame(function(){ SNP.rzRaf=0; snProTvCheck(); }); },{passive:true});
  SNP.ageT=setInterval(snProGpsAge,2000);
  snProTvCheck();
  snProCockpitPaint();
  snProLiveStatus(false);
}
function snProCleanup(){
  if(SNP.keyFn){ document.removeEventListener('keydown',SNP.keyFn); SNP.keyFn=null; }
  if(SNP.visFn){ document.removeEventListener('visibilitychange',SNP.visFn); SNP.visFn=null; }
  if(SNP.resizeFn){ window.removeEventListener('resize',SNP.resizeFn); SNP.resizeFn=null; }
  if(SNP.rzRaf){ cancelAnimationFrame(SNP.rzRaf); SNP.rzRaf=0; }
  if(SNP.ageT!=null){ clearInterval(SNP.ageT); SNP.ageT=null; }
  /* the GPS watch stops with the view: the next visit measures afresh, so the
     first fix after coming back never shows the minutes spent away */
  SNP.lastFixAt=0; SNP.fixGapMs=0; SNP.lastAcc=null;
  snProCockpitShow(false);
  SNP.attached=false;
}
/* A language switch (renderNav -> SN.relabel) re-writes the lines this file set. */
function snProRelabel(){
  snProLiveStatus(false);
  snProDeviceRow();
  if(SNP.flightOn) snProFlightText();
  if(SNP.lastAcc!=null) snProGpsCell(SNP.lastAcc);
}
/* A basic (low-tier) device is always on Fast mode (snProForceLite), so the
   "Fast mode" switch and the ⚡ pill must not offer the full map: before this,
   unticking it said "Full map on", reloaded, and came back in Fast mode every
   time. The switch is greyed with a "basic device" note, the pill only says
   why, and the cockpit button / switch are hidden there (review fix, 10 Oct). */
function snProLockLow(){
  if(!snProForceLite()) return;
  var lt=snProEl('snLyLite');
  if(lt){
    lt.checked=true; lt.disabled=true;
    lt.onchange=function(){ lt.checked=true; snProToast('snLiteLocked'); };
    var lab=lt.closest?lt.closest('label'):null; if(lab) lab.classList.add('sn-ly-locked');
  }
  var note=snProEl('snLyLiteNote'); if(note) note.hidden=false;
  var fp=snProEl('snFastPill'); if(fp) fp.onclick=function(){ snProToast('snLiteLocked'); };
  var b=snProEl('snCockpit'); if(b){ b.style.display='none'; b.setAttribute('aria-hidden','true'); }
  var c=snProEl('snLyCockpit'), cl=c&&c.closest?c.closest('label'):null; if(cl) cl.style.display='none';
}
function snProInit(){
  try{ SNP.cockpitPref=localStorage.getItem(SNP_COCKPIT_KEY); }catch(e){}
  if(SNP.cockpitPref!=='1'&&SNP.cockpitPref!=='0') SNP.cockpitPref=null;
  var b=snProEl('snCockpit'); if(b) b.onclick=function(){ snProSetCockpit(!snProCockpitWanted(),true); };
  var c=snProEl('snLyCockpit'); if(c) c.onchange=function(){ snProSetCockpit(!!c.checked,true); };
  /* the chips keep their 15-nav.js onclick (sets SN.mode, re-routes); this runs after it */
  document.querySelectorAll('#view-nav [data-snmode]').forEach(function(ch){ ch.addEventListener('click',snProModeChanged); });
  var ly=snProEl('snLayers'); if(ly) ly.addEventListener('click',function(){ snProDeviceRow(); snProLiveStatus(true); });
  var tt=snProEl('snTrackToggle'); if(tt) tt.addEventListener('click',function(){ setTimeout(function(){ snProLiveStatus(false); },0); });
  var dt=snProEl('snDriverToggle'); if(dt) dt.addEventListener('click',function(){ setTimeout(function(){ snProLiveStatus(false); },0); });
  /* the map object outlives the view (SN.map is kept across #/nav visits), so this is bound once */
  if(SN.map&&!SNP.dragFn){ SNP.dragFn=function(){ snProCockpitSync(); }; SN.map.on('dragstart',SNP.dragFn); }
  window.SHG_NAV=window.SHG_NAV||{};
  window.SHG_NAV.onBusFix=snProBusFix;
  window.SHG_NAV.profile=snDeviceProfile;
  snProLockLow();
  snProDeviceRow();
  snProAttach();
}
