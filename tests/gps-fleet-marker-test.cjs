const vm=require('node:vm'),fs=require('node:fs'),assert=require('node:assert/strict');
let now=1000,frames=new Map(),canceled=[],seq=0,markerCount=0;
function element(){ return {style:{},appendChild(){},addEventListener(){},setAttribute(){},querySelector(){return {innerHTML:''}}}; }
class Marker{
 constructor(o){this.el=o.element;markerCount++;this.removed=false;}
 setLngLat(p){this.p={lng:p[0],lat:p[1]};return this;} getLngLat(){return this.p;} addTo(){return this;}
 getElement(){return this.el;} remove(){this.removed=true;}
}
let c={URLSearchParams,location:{search:''},localStorage:{getItem(){return null;}},window:{maplibregl:{Marker}},maplibregl:{Marker},document:{hidden:false,documentElement:{lang:'en'},querySelector(){return null;},createElement:element,addEventListener(){}},performance:{now:()=>now},requestAnimationFrame:f=>{frames.set(++seq,f);return seq;},cancelAnimationFrame:id=>{canceled.push(id);frames.delete(id);},setTimeout(){},console};
let src=fs.readFileSync('assets/nav/nav-live.js','utf8');
src=src.replace("if (window.SHGNAV_X) start();", "window.QA = {BUS:BUS, apply:busApply, fresh:busFresh, stop:busStop, move:moveMarker, setX:function(v){X=v;}}; if (window.SHGNAV_X) start();");
vm.runInNewContext(src,c);let q=c.window.QA;
q.setX({S:{lang:'en',saver:false,map:{easeTo(){throw Error('updates must not center navigation');}}},esc:String});
const base=1792900000000;
function snap(t,age=5,lat=23){return {t,buses:[{id:1,bus:'QA-BUS',to:'Nepal',from:'Gujarat',dep:'13:00',lat,lng:72,kmh:42,fixAt:t-age*1000,age}],fleet:[]};}
q.apply(snap(base));assert.equal(markerCount,1);assert.equal(q.BUS.by[1].kmh,42);
const first=q.BUS.m[1];now+=5000;q.apply(snap(base));assert.equal(q.BUS.received,1000,'duplicate snapshot cannot extend freshness');
q.apply(snap(base-1000,5,24));assert.equal(q.BUS.by[1].lat,23,'out of order snapshots are ignored');
q.apply(snap(base+10000,5,23.001));assert.ok(first._shgAnim);let prior=first._shgAnim;
q.apply(snap(base+20000,5,23.002));assert.ok(canceled.includes(prior),'new animation cancels the old one');
q.BUS.animate=false;q.apply(snap(base+30000,5,23.003));assert.equal(first.p.lat,23.003);assert.equal(first._shgAnim,null);
q.stop();assert.equal(Object.keys(q.BUS.m).length,0);assert.ok(q.BUS.by[1],'overlay toggle preserves live list');
q.apply(snap(base+40000,121));assert.equal(Object.keys(q.BUS.by).length,0,'stale fix has no live speed/marker');
q.apply(snap(base+50000,5));now+=116000;assert.equal(q.fresh(q.BUS.by[1]),false,'local expiry works even when connection stops');
console.log('GPS marker checks passed: speed, no map recenter, duplicate/order, animation cancellation/off, toggle, stale expiry');
