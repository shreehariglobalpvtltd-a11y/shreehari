const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
let now=1000;
const c={URLSearchParams,location:{search:''},localStorage:{getItem(){return null;}},window:{},
  document:{hidden:false,querySelector(){return null;},addEventListener(){}},performance:{now:()=>now},
  cancelAnimationFrame(){},setTimeout(){},console};
let src=fs.readFileSync('assets/nav/nav-live.js','utf8');
src=src.replace('if (window.SHGNAV_X) start();','window.QA={MB:MB,expire:myBusExpire,tick:myBusTick,setX:function(v){X=v;}}; if (window.SHGNAV_X) start();');
vm.runInNewContext(src,c);const q=c.window.QA;
q.setX({S:{lang:'en',map:null},toast(){},esc:String});
q.MB.st={live:true,lat:23,lng:72,fixAge:10,kmh:45,etaMin:12,km:8};
q.MB.received=now;q.MB.following=true;
assert.equal(q.expire(false),false);
now+=111000;assert.equal(q.expire(false),true);
assert.equal(q.MB.st.live,false);assert.equal(q.MB.st.lastKnown,true);
assert.equal(q.MB.st.kmh,null);assert.equal(q.MB.st.etaMin,null);assert.equal(q.MB.following,false);
assert.equal(q.expire(false),false,'expiry does not repeatedly rebuild a focused panel');
(async()=>{
  let resolve;
  const x={S:{lang:'en',map:null},toast(){},esc:String,fetchT:()=>new Promise(r=>{resolve=r;})};
  q.setX(x);q.MB.pnr='A';q.MB.generation=1;q.MB.st=null;
  const old=q.tick(false);q.MB.pnr='B';q.MB.generation=2;
  const data={updatedAt:'2026-10-26T18:00:00Z',live:true,fixAge:5,lat:23,lng:72,kmh:40};
  resolve({status:200,json:async()=>({ok:true,data})});await old;
  assert.equal(q.MB.st,null,'a previous ticket response cannot replace the selected ticket');
  x.fetchT=async()=>({status:200,json:async()=>({ok:true,data:{...data}})});
  await q.tick(false);const received=q.MB.received;
  now+=50000;await q.tick(false);assert.equal(q.MB.received,received,'replayed API snapshot cannot renew GPS freshness');
  x.fetchT=async()=>{throw Error('offline');};await q.tick(false);
  assert.equal(q.MB.st.live,false);assert.equal(q.MB.st.kmh,null,'network failure never retains live speed');

  const html=fs.readFileSync('navigate.html','utf8');
  const a=html.indexOf('function submitRoadReport(kind)'),b=html.indexOf('function toggleGoogleAlongBar',a);
  let reports=0,toasts=[];
  const r={S:{me:null},window:{SHGNAV_LIVE:{reportIncident:()=>reports++}},closeIncidentReportModal(){},toast:m=>toasts.push(m)};
  vm.createContext(r);vm.runInContext(html.slice(a,b),r);
  r.submitRoadReport('jam');assert.equal(reports,0,'no invented incident position');
  r.S.me={lat:23,lng:72};r.submitRoadReport('jam');assert.equal(reports,1,'drawer reuses the authenticated existing reporting module');
  r.window.SHGNAV_LIVE=null;r.submitRoadReport('jam');assert.match(toasts.at(-1),/unavailable/,'missing report service never shows success');

  const outbound={routeId:2,from:'Surat',to:'Rupaidiha'},inbound={routeId:7,from:'Rupaidiha',to:'Rajkot'};
  const stops=new Map([[outbound,[{name:'Official A',lng:72,lat:23},{name:'Official B',lng:81,lat:28}]],
    [inbound,[{name:'Official B',lng:81,lat:28},{name:'Official C',lng:70,lat:22}]]]);
  let routes=[outbound,inbound],opened=0;
  const d={S:{},MODES:{bus:{color:'gold'}},document:{documentElement:{style:{setProperty(){}}}},
    srCorridor:()=>outbound,srRoutes:()=>routes,srLoad:async()=>{},srCorridorStops:r=>stops.get(r)||[],
    voUnlock(){},startGeo(){},push(){opened++;},toast:m=>toasts.push(m)};
  vm.createContext(d);
  const u=html.indexOf('var GUJ_RUPAIDIHA_STOPS ='),v=html.indexOf('function checkCorridorProximity',u);
  const s=html.indexOf('function startCorridorNav(reverse)'),e=html.indexOf('\n\n/* =====================================================================',s);
  vm.runInContext(html.slice(u,v)+html.slice(s,e),d);
  await d.startCorridorNav(false);assert.equal(d.S.from.name,'Official A');assert.equal(d.S.to.name,'Official B');
  await d.startCorridorNav(true);assert.equal(d.S.to.name,'Official C','reverse uses its actual configured route');
  routes=[outbound];await d.startCorridorNav(true);assert.equal(opened,2,'missing direction does not invent a route');
  console.log('Passenger GPS expiry/races, honest incident reporting and official corridor stop checks passed');
})().catch(e=>{console.error(e);process.exitCode=1;});
