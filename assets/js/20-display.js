/* Opt-in display preference only. No booking, network, audio or GPS code.
 * Not loaded by the live template until the category rollout is reviewed.
 */
(function () {
  'use strict';
  if (window.SHGDisplay) return;
  var root = document.documentElement;
  var key = 'shg:display';
  var value = 'standard';
  try { if (localStorage.getItem(key) === 'amoled') value = 'amoled'; } catch (e) {}
  function apply(mode, save) {
    if (mode !== 'standard' && mode !== 'amoled') return false;
    value = mode;
    if (mode === 'amoled') root.setAttribute('data-shg-display', 'amoled');
    else root.removeAttribute('data-shg-display');
    if (save) { try { localStorage.setItem(key, mode); } catch (e) {} }
    document.querySelectorAll('[data-shg-display-control]').forEach(function (control) {
      control.value = mode;
    });
    return true;
  }
  window.SHGDisplay = {
    get: function () { return value; },
    set: function (mode) { return apply(mode, true); }
  };
  apply(value, false);
  function init() {
    document.querySelectorAll('[data-shg-display-control]').forEach(function (control) {
      control.value = value;
      control.addEventListener('change', function () { apply(control.value, true); });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
}());
