'use strict';
// Run the real intake client with deterministic HTTP/DOM boundaries. No OCR provider or database.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const elements = [], ids = new Map();
class Element {
  constructor(tag) { this.tagName = tag.toUpperCase(); this.children = []; this.dataset = {}; this.style = {}; this.value = ''; this.listeners = {}; this.disabled = false; this.hidden = false; this.checked = false; this.classList = { add() {}, toggle() {} }; elements.push(this); }
  set id(v) { this._id = v; ids.set(v, this); } get id() { return this._id; }
  appendChild(e) { e.parent = this; this.children.push(e); return e; }
  insertBefore(e) { e.parent = this; this.children.unshift(e); }
  get firstChild() { return this.children[0]; }
  setAttribute(k, v) { this[k] = v; } removeAttribute(k) { delete this[k]; }
  addEventListener(k, fn) { (this.listeners[k] ||= []).push(fn); }
  dispatchEvent(e) { for (const fn of this.listeners[e.type] || []) fn(e); return true; }
  contains(e) { return e === this || this.children.some(c => c.contains?.(e)); }
  focus() {} scrollIntoView() {}
}
const host = new Element('form'); host.id = 'form';
const submit = new Element('button'); submit.id = 'send';
const name = new Element('input'); name.id = 'name'; host.appendChild(name);
const document = { createElement: t => new Element(t), createTextNode: t => ({ textContent: t }), getElementById: id => ids.get(id), head: new Element('head'), documentElement: new Element('html') };
let response = { ok: true, status: 'read', fields: { name: 'Synthetic Applicant' } }, deferred = null;
const requests = [], window = {};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../assets/js/application-import.js'), 'utf8'), {
  window, document, Event, AbortController, setTimeout, clearTimeout, setInterval: () => 1, clearInterval() {},
  fetch: (_url, options) => { requests.push(JSON.parse(options.body)); return deferred ? deferred.promise : Promise.resolve({ json: () => Promise.resolve(response) }); }
});
const api = window.ShgIntake.mount({ host: 'form', submit: 'send', kind: 'agent', token: () => Promise.resolve('synthetic-csrf'), onId() {}, onFields: (f, source) => window.ShgIntake.setField('name', f.name, source) ? 1 : 0 });
(async () => {
  await api.readData('synthetic-id', 'id');
  assert.equal(requests.at(-1).application_kind, 'agent');
  assert.equal(name.value, 'Synthetic Applicant');
  assert.equal(api.reviewed(), false, 'OCR cannot tick the review checkbox');
  name.value = 'Manual correction'; name.dataset.userEdited = '1';
  response = { ok: true, status: 'read', receipt: 'a'.repeat(32), fields: { name: 'OCR replacement' } };
  await api.readData('synthetic-pdf', 'form');
  assert.equal(api.receipt(), 'a'.repeat(32)); assert.equal(name.value, 'Manual correction');
  api.setKind('partner');
  assert.equal(api.receipt(), ''); assert.equal(api.reviewed(), false); assert.equal(submit.disabled, true);
  assert.equal(elements.find(e => e.tagName === 'A').href, '/agreements/form.php?kind=partner');
  await api.readData('partner-id', 'id');
  assert.equal(requests.at(-1).application_kind, 'partner');
  let resolve; deferred = { promise: new Promise(r => { resolve = r; }) };
  const pending = api.readData('old-partner-pdf', 'form');
  await Promise.resolve(); await Promise.resolve();
  assert.equal(requests.at(-1).application_kind, 'partner');
  api.setKind('staff');
  resolve({ json: () => Promise.resolve(response) });
  await assert.rejects(pending, /Application kind changed/);
  assert.equal(api.receipt(), ''); assert.equal(name.value, 'Manual correction'); assert.equal(submit.disabled, true);
  deferred = null; response = { ok: true, status: 'unavailable', fields: {} };
  await api.readData('staff-id', 'id');
  assert.equal(requests.at(-1).application_kind, 'staff');
  assert.equal(api.reviewed(), false); assert.equal(ids.get('intakeReviewed').disabled, false);
  ids.get('intakeReviewed').checked = true;
  assert.equal(api.reviewed(), true, 'Only an explicit applicant review unlocks submission');
  response = { ok: true, status: 'unavailable', receipt: 'b'.repeat(32), fields: {} };
  await api.readData('staff-pdf', 'form');
  assert.equal(api.receipt(), 'b'.repeat(32)); assert.equal(api.reviewed(), false);
  console.log('PASS intake preserves manual corrections, rejects stale cross-kind responses and requires explicit review');
})().catch(e => { console.error(e); process.exitCode = 1; });
