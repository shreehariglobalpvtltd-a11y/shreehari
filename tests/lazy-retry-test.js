'use strict';
// Exercise the real lazy-loader and click bridge with a failed connection.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/13-admin-routes.js'), 'utf8');
const start = source.indexOf('function shgLazyLoad()');
const end = source.indexOf('\n})();', start) + '\n})();'.length;
assert.ok(start >= 0 && end > start);
const handlers = new Map();
const scripts = [];
let replayed = 0;
const button = {
  addEventListener: (type, fn) => handlers.set(type, fn),
  removeEventListener: type => handlers.delete(type),
  click: () => replayed++
};
const context = vm.createContext({
  Promise, Error, encodeURIComponent,
  window: {addEventListener() {}},
  document: {
    readyState: 'loading',
    querySelector: selector => selector === '#aiFab' ? button : null,
    createElement: () => ({remove() {this.removed = true;}}),
    head: {appendChild: script => scripts.push(script)}
  }
});
vm.runInContext(source.slice(start, end), context);
const tap = () => handlers.get('click')({preventDefault() {}});
const flush = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  tap(); tap();
  assert.equal(scripts.length, 1, 'rapid taps share one download');
  scripts[0].onerror();
  await flush();
  assert.equal(replayed, 0, 'failed load never replays an action');
  assert.ok(scripts[0].removed, 'failed script removed');
  assert.ok(handlers.has('click'), 'button remains retryable');
  tap(); tap();
  assert.equal(scripts.length, 2, 'next tap retries once');
  scripts[1].onload();
  await flush();
  assert.equal(replayed, 1, 'successful retry replays exactly once');
  assert.equal(handlers.has('click'), false, 'loaded feature owns subsequent clicks');
  await context.shgLazyLoad();
  assert.equal(scripts.length, 2, 'loaded chunk is reused');
  console.log('PASS: lazy loading recovers after failure without duplicate actions');
})().catch(error => {console.error(error); process.exitCode = 1;});
