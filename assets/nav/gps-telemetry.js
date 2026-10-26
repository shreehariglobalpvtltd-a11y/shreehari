/* Fleet display freshness uses server time plus a monotonic local clock. */
(function (root) {
  'use strict';
  function GpsTelemetry(clock) {
    this.clock = clock || function () { return performance.now(); };
    this.at = null;
    this.received = 0;
    this.devices = [];
    this.failed = false;
  }
  GpsTelemetry.prototype.accept = function (snapshot) {
    if (!snapshot || !Number.isFinite(snapshot.at) || snapshot.at <= 0 || snapshot.simulation
        || !Array.isArray(snapshot.devices) || (this.at !== null && snapshot.at <= this.at)) return false;
    this.at = snapshot.at;
    this.received = this.clock();
    this.devices = snapshot.devices.filter(function (row) { return !row.simulation; }).map(function (row) {
      return Object.assign({}, row, { diagnostic: row.diagnostic ? Object.assign({}, row.diagnostic) : null });
    });
    this.failed = false;
    return true;
  };
  GpsTelemetry.prototype.rows = function () {
    var now = this.at + Math.max(0, this.clock() - this.received), failed = this.failed;
    return this.devices.map(function (device) {
      var row = Object.assign({}, device);
      if (row.state !== 'live') return row;
      var seen = now - row.seenAt, fix = now - row.fixAt;
      if (failed || !Number.isFinite(row.seenAt) || seen > 120000 || seen < -120000) row.state = 'offline';
      else if (!Number.isFinite(row.fixAt) || fix > 120000 || fix < -120000) row.state = 'stale';
      else if (!Number.isFinite(row.lat) || !Number.isFinite(row.lng)
          || row.lat < -90 || row.lat > 90 || row.lng < -180 || row.lng > 180) row.state = 'no_fix';
      if (row.state !== 'live') row.kmh = null;
      // Accuracy belongs to its exact fix, never to a later accepted location.
      if (row.diagnostic && row.diagnostic.reportedAt !== row.fixAt) row.diagnostic = null;
      return row;
    });
  };
  root.GpsTelemetry = GpsTelemetry;
  if (typeof module !== 'undefined' && module.exports) module.exports = GpsTelemetry;
})(typeof window === 'undefined' ? globalThis : window);
