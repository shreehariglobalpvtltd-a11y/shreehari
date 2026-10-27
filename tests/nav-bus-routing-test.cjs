const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const html = fs.readFileSync('navigate.html','utf8');
const routeCode = html.slice(html.indexOf('async function routeFetch('), html.indexOf('function normalize(', html.indexOf('async function routeFetch(')));
assert.ok(routeCode.length > 500, 'exercise the real navigation fetch function');
for (const block of html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)) {
  if (block[1].trim()) new vm.Script(block[1]);
}
async function scenario(mode, response, fail) {
  const calls = [];
  const context = { MODES:{bus:{costing:'bus',osrm:null},car:{costing:'auto',osrm:'car'}},
    ROUTE_API:'/api/nav-route.php?json=',OSRM:{car:'https://car.example/route/'},
    costingOpts:()=>null,osrmExclude:()=>'',busy(){},normalize:()=>['route'],
    fetchT:async url=>{calls.push(url);if(fail&&calls.length===1)throw Error('unavailable');
      return {ok:response,headers:{get:()=> 'own'},json:async()=>({code:response?'Ok':'NoRoute',routes:response?[{}]:[]})};}
  };
  vm.createContext(context); vm.runInContext(routeCode,context);
  let value,error;
  try {value=await context.routeFetch(mode,{lat:23,lng:72},{lat:24,lng:73},{});} catch(e) {error=e;}
  return {calls,value,error};
}
(async()=>{
  for(const fail of [true,false]) {
    const r=await scenario('bus',false,fail);
    assert.equal(r.calls.length,1,'bus never calls car fallback after failure/no route');
    assert.equal(r.error.busRoutingUnavailable,true);
    assert.match(r.error.message,/Bus routing is unavailable/);
    const body=JSON.parse(decodeURIComponent(r.calls[0].split('?json=')[1]));
    assert.equal(body.costing,'bus');
  }
  const bus=await scenario('bus',true,false); assert.equal(bus.value.src,'own'); assert.equal(bus.calls.length,1);
  const car=await scenario('car',true,true); assert.equal(car.value.src,'osrm'); assert.equal(car.calls.length,2);
  console.log('Real navigation JS: bus costing, failure/no-route safety, car fallback and inline syntax passed');
})().catch(e=>{console.error(e);process.exitCode=1;});
