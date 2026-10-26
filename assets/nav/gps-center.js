/* GPS-CENTER-20261025: private live GPS and separate demo view. */
(function () {
'use strict';
if(!window.GpsTelemetry){var status=document.getElementById('gcPoll');if(status)status.textContent='GPS display could not load. Reload to retry; current status is unavailable.';return;}
var telemetry=new window.GpsTelemetry(), mode=(window.GPS_CENTER||{}).mode||'real', map=null, markers={}, real=[], demo=[], selected=null, timer=null, busy=false, failures=0, needLine=true, firstFit=true, line=null, lineAdded=false, lastDemoKey='';
var $=function(s){return document.querySelector(s);};
var stateNames={live:'🟢 Live GPS',waiting:'पहिलो GPS report पर्खिँदै',offline:'Offline · last known',no_fix:'Connected · no GPS fix',stale:'GPS fix पुरानो छ',disabled:'Tracker OFF'};
function motionOff(){return window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches;}
function age(t){if(!t)return 'never';var n=Math.max(0,Math.floor((Date.now()-t)/1000));return n<60?n+' s ago':Math.floor(n/60)+' min ago';}
function esc(v){var d=document.createElement('span');d.textContent=String(v==null?'':v);return d.innerHTML;}
function key(r){return mode==='sim'?r.id:String(r.id);}
function activeRows(){return mode==='sim'?demo:real;}
function clearMarker(k){var f=markers[k];if(f){if(f.anim)cancelAnimationFrame(f.anim);f.marker.remove();delete markers[k];}}
function position(r){return r.lat!=null&&r.lng!=null&&Number.isFinite(+r.lat)&&Number.isFinite(+r.lng);}
function glide(f,r){
 if(f.anim)cancelAnimationFrame(f.anim); f.anim=null;
 var to=[+r.lng,+r.lat],from=f.marker.getLngLat(),start=performance.now();
 if(document.hidden||motionOff()||(mode==='real'&&r.state!=='live')){f.marker.setLngLat(to);return;}
 (function frame(t){var u=Math.min(1,(t-start)/900);f.marker.setLngLat([from.lng+(to[0]-from.lng)*u,from.lat+(to[1]-from.lat)*u]);f.anim=u<1?requestAnimationFrame(frame):null;})(start);
}
function selectedRow(){return activeRows().find(function(r){return key(r)===selected;});}
function focus(k){selected=String(k);var r=selectedRow();if(r&&position(r)&&map)map.easeTo({center:[+r.lng,+r.lat],zoom:Math.max(map.getZoom(),15),duration:motionOff()?0:600});}
function fit(){
 if(!map)return;var points=activeRows().filter(position);if(!points.length)return;
 var b=new maplibregl.LngLatBounds([+points[0].lng,+points[0].lat],[+points[0].lng,+points[0].lat]);points.forEach(function(r){b.extend([+r.lng,+r.lat]);});
 map.fitBounds(b,{padding:100,maxZoom:14.5,duration:motionOff()?0:650});
}
function drawLine(){
 if(!map||!map.isStyleLoaded())return;
 if(lineAdded){if(map.getLayer('gps-demo-line'))map.removeLayer('gps-demo-line');if(map.getSource('gps-demo-line'))map.removeSource('gps-demo-line');lineAdded=false;}
 if(mode==='sim'&&line&&line.length>1){map.addSource('gps-demo-line',{type:'geojson',data:{type:'Feature',geometry:{type:'LineString',coordinates:line},properties:{}}});map.addLayer({id:'gps-demo-line',type:'line',source:'gps-demo-line',paint:{'line-color':'#d48816','line-width':4,'line-opacity':0.7}});lineAdded=true;}
}
function draw(){
 if(!map)return;var seen={};
 activeRows().forEach(function(r){
  if(!position(r)||(mode==='real'&&r.state==='disabled'))return;
  var k=key(r);seen[k]=true;var f=markers[k];
  if(!f){var el=document.createElement('button');el.type='button';el.className='gc-marker'+(mode==='sim'?' demo':'');el.onclick=function(){focus(k);};f=markers[k]={el:el,marker:new maplibregl.Marker({element:el}).setLngLat([+r.lng,+r.lat]).addTo(map),anim:null,fixAt:null};}
  f.el.textContent=(mode==='sim'?'🧪 ':'🚌 ')+r.bus+(r.kmh!=null?' · '+Math.round(r.kmh)+' km/h':'');
  f.el.title=mode==='sim'?'SIMULATION · not a real GPS position':r.bus+' · '+(stateNames[r.state]||r.state);
  f.el.style.opacity=mode==='sim'||r.state==='live'?'1':'.5';
  if(f.fixAt!==r.fixAt){glide(f,r);f.fixAt=r.fixAt;}
 });
 Object.keys(markers).forEach(function(k){if(!seen[k])clearMarker(k);});
 if(firstFit&&activeRows().some(position)){fit();firstFit=false;}
 var followed=selectedRow();if($('#gcFollow').checked&&followed&&position(followed))map.easeTo({center:[+followed.lng,+followed.lat],duration:motionOff()?0:600});
}
function realCards(){
 real.forEach(function(r){var box=document.querySelector('[data-gps-id="'+Number(r.id)+'"]');if(!box)return;
  var st=box.querySelector('[data-role="state"]');st.textContent=stateNames[r.state]||r.state;st.className='gc-status'+(r.state==='live'?' live':r.state==='stale'||r.state==='no_fix'?' error':'');
  var text='Received '+age(r.seenAt)+' · GPS '+age(r.fixAt)+(r.kmh!=null?' · '+r.kmh+' km/h':' · speed unavailable');
  if(r.diagnostic&&r.diagnostic.accuracyM!=null)text+=' · ±'+Math.round(r.diagnostic.accuracyM)+' m';
  if(r.diagnostic&&r.diagnostic.reason==='poor_accuracy')text+=' · GPS accuracy कमजोर';
  if(r.diagnostic&&r.diagnostic.reason==='old_fix')text+=' · repeated fix';
  box.querySelector('[data-role="metrics"]').textContent=text;
 });
}
function simCards(frame){
 var box=$('#gcSimRows'),signature=demo.map(function(r){return r.id;}).join(',');
 if(!demo.length){box.innerHTML='<p class="gc-hint">Choose a route and press Start demo.</p>';lastDemoKey='';return;}
 if(signature!==lastDemoKey){box.innerHTML='<div class="gc-panel"><h3>Demo buses · SIMULATION</h3><p id="gcDemoRoute" class="gc-hint"></p>'+demo.map(function(r){return '<button type="button" class="gc-demobus" data-demo="'+esc(r.id)+'"><b>'+esc(r.bus)+' · SIMULATION</b><span></span></button>';}).join('')+'</div>';lastDemoKey=signature;box.querySelectorAll('[data-demo]').forEach(function(el){el.onclick=function(){focus(el.dataset.demo);};});}
 $('#gcDemoRoute').textContent=frame.route+' · '+frame.source+' · '+(frame.running?'Running':'Paused');
 demo.forEach(function(r){var el=box.querySelector('[data-demo="'+r.id+'"] span');if(el)el.textContent=Math.round(r.kmh)+' km/h · route '+r.km.toFixed(2)+' km · 1-second refresh';});
}
function schedule(delay){clearTimeout(timer);if(!document.hidden)timer=setTimeout(poll,delay);}
function poll(){
 clearTimeout(timer);if(document.hidden||busy)return;busy=true;var requestedMode=mode;
 var controller=new AbortController(),abort=setTimeout(function(){controller.abort();},8000);
 var url=mode==='sim'?'/admin/api/gps-simulation.php'+(needLine?'?route=1':''):'/admin/api/gps-fleet.php';
 fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:controller.signal})
 .then(function(r){if(!r.ok)throw Error('HTTP '+r.status);return r.json();})
 .then(function(j){if(!j.ok)throw Error(j.error||'Unavailable');if(requestedMode!==mode)return;failures=0;
  if(mode==='sim'){demo=j.data.buses||[];if(j.data.line){line=j.data.line;needLine=false;drawLine();}simCards(j.data);}
  else{if(!telemetry.accept(j.data))return;real=telemetry.rows();realCards();}
  $('#gcPoll').textContent=(mode==='sim'?'DEMO · ':'Live GPS · ')+new Date().toLocaleTimeString()+' · screen refresh 1 s';draw();
 }).catch(function(e){if(requestedMode!==mode)return;failures++;$('#gcPoll').textContent='Connection unavailable · retrying';
  if(mode==='real'){telemetry.failed=true;real.forEach(function(r){if(r.state==='live'){r.state='offline';r.kmh=null;}});realCards();draw();}
  else $('#gcPoll').textContent='Simulation paused on screen · '+e.message;
 }).finally(function(){clearTimeout(abort);busy=false;schedule(Math.min(30000,1000*Math.pow(2,failures)));});
}
function setMode(next){if(next==='sim'&&!(window.GPS_CENTER||{}).simulationAllowed)return;if(next===mode)return;mode=next;selected=null;$('#gcFollow').checked=false;Object.keys(markers).forEach(clearMarker);firstFit=true;needLine=true;line=null;drawLine();
 document.querySelectorAll('[data-gc-mode]').forEach(function(b){b.setAttribute('aria-pressed',String(b.dataset.gcMode===mode));});
 $('#gcSimControls').hidden=mode!=='sim';$('#gcSimRows').hidden=mode!=='sim';$('#gcRealRows').hidden=mode!=='real';
 $('#gcMapHint').textContent=mode==='sim'?'SIMULATION · private demo · no real GPS/booking data is changed.':'Screen refresh: 1 second. Marker transitions are visual interpolation between device readings; see the GPS timestamp and accuracy.';
 history.replaceState(null,'','?mode='+mode);poll();
}
function refreshHealth(){
 if(mode!=='real'||document.hidden)return;
 var before=real.map(function(r){return r.id+':'+r.state;}).join(',');
 real=telemetry.rows();realCards();
 if(before!==real.map(function(r){return r.id+':'+r.state;}).join(','))draw();
}
function wire(){
 document.querySelectorAll('[data-gc-mode]').forEach(function(b){b.onclick=function(){setMode(b.dataset.gcMode);};});
 document.querySelectorAll('[data-locate]').forEach(function(b){b.onclick=function(){focus(b.dataset.locate);};});
 document.querySelectorAll('[data-copy-link]').forEach(function(b){b.onclick=function(){navigator.clipboard.writeText(b.dataset.copyLink).then(function(){b.textContent='Link copied';}).catch(function(){b.textContent='Open the setup link to copy it from the address bar';});};});
 $('#gcFit').onclick=fit;
 var sel=$('#gcBus'),kind=$('#gcKind');if(sel)sel.onchange=function(){var fresh=sel.value==='0';$('#gcNewBus').hidden=!fresh;document.querySelector('[name="bus_number"]').required=fresh;var pub=$('#gcPublic');if(pub)pub.checked=sel.selectedOptions[0].dataset.public==='1';};
 if(kind)kind.onchange=function(){$('#gcHardware').hidden=kind.value!=='hardware';$('#gcImei').required=kind.value==='hardware';};
 var coach=$('#gcCoach');if(coach)coach.onchange=function(){$('#gcSeats').value=coach.value==='sleeper'?72:45;};
 try{map=new maplibregl.Map({container:'gcMap',style:'https://tiles.openfreemap.org/styles/liberty',center:[76.5,25.5],zoom:5,attributionControl:{compact:true}});
  map.addControl(new maplibregl.NavigationControl(),'top-right');map.on('load',function(){var load=$('#gcMapLoad');if(load)load.remove();drawLine();draw();});map.on('dragstart',function(){$('#gcFollow').checked=false;});
 }catch(e){var load=$('#gcMapLoad');if(load)load.textContent='Map unavailable on this browser; GPS status remains available below.';}
 if(mode==='sim')$('#gcMapHint').textContent='SIMULATION · private demo · no real GPS/booking data is changed.';
 setInterval(refreshHealth,1000);poll();document.addEventListener('visibilitychange',function(){if(document.hidden){clearTimeout(timer);Object.keys(markers).forEach(function(k){if(markers[k].anim)cancelAnimationFrame(markers[k].anim);});}else {refreshHealth();poll();}});window.addEventListener('online',poll);
}
wire();
})();
