
/* ================================================================
   [JS] 10d. AGENT DASHBOARD — referral link/QR, stats, history,
   wallet & payout requests, leaderboard, become-an-agent flow.
   (Replaces the old "Phase 2 coming soon" placeholder.)
================================================================ */
function agentLink(u) {
  return location.origin + location.pathname + '#/?ref=' + u.refCode;
}
function commissionForBooking(bookingId) {
  return DB.commissions.find(c => c.bookingId === bookingId);
}
const AGENT_MILESTONES = [1, 10, 25, 50, 100];

function renderAgentGateOrApp() {
  const box = $('#agentBody'); if (!box) return;

  /* Not signed in → launcher into the real staff Agent Panel.

     This used to be a password box compared in JavaScript against
     S().agentPin — a value any visitor could read from the console, and a
     check anyone could skip by setting one sessionStorage key. It unlocked
     a local mirror only, but it read to staff like a real login. The admin
     gate was moved server-side for exactly this reason (see goRealAdmin);
     the agent gate now follows it.

     Who signs in how, deliberately:
        customer  → phone + OTP, no password to remember
        agent     → username + password at /admin/ (bcrypt, rate-limited,
                    role-scoped, and the only place a sale can be
                    attributed to them for commission)
        admin     → the same real staff sign-in                            */
  if (!USER) {
    box.innerHTML = `
      <div class="admin-gate" style="margin:10px auto">
        <div class="lock">🤝</div>
        <h3 style="font-family:var(--f-display);font-size:20px;margin-bottom:6px">${t('agTitle')}</h3>
        <p style="font-size:13.5px;color:var(--muted);margin-bottom:16px">${t('agGateP')}</p>
        <button class="btn btn-orange" type="button" id="agentOpenReal" style="width:100%">${t('agGateBtn')}</button>
        <p style="font-size:12px;color:var(--muted);margin-top:12px">${t('agGateHint')}</p>
        <div style="margin-top:14px;border-top:1px solid var(--line);padding-top:14px">
          <p style="font-size:12px;color:var(--muted);margin-bottom:8px">${t('agGateCust')}</p>
          <button class="btn btn-sm" type="button" id="agLoginBtn" style="width:100%">${t('myLoginBtn')}</button>
        </div>
      </div>`;
    $('#agLoginBtn').onclick = openLoginModal;
    $('#agentOpenReal').onclick = () => {
      const base = (window.SHG_BOOT && window.SHG_BOOT.appUrl) || location.origin;
      /* One login door (3 Sep 2026): land on the AGENT portal (code + OTP),
         not the bare username/password form; next= carries them to their
         dashboard after sign-in. Already signed-in staff skip straight through. */
      window.open(base.replace(/\/$/, '') + '/admin/login.php?portal=agent&next=agent.php', '_blank', 'noopener');
      toast(t('agGateToast'));
    };
    return;
  }

  const u = ensureUser(USER.phone);

  /* Customer → become-an-agent request flow */
  if (u.role !== 'agent') {
    box.innerHTML = u.agentRequestPending
      ? `<div class="admin-gate" style="margin:10px auto">
          <div class="lock">⏳</div>
          <h3 style="font-family:var(--f-display);font-size:20px;margin-bottom:6px">${t('agPendingT')}</h3>
          <p style="font-size:13.5px;color:var(--muted)">${t('agPendingP')}</p>
        </div>`
      : `<div class="admin-gate" style="margin:10px auto">
          <div class="lock">🤝</div>
          <h3 style="font-family:var(--f-display);font-size:20px;margin-bottom:6px">${t('agBecomeT')}</h3>
          <p style="font-size:13.5px;color:var(--muted);margin-bottom:18px">${t('agBecomeP')}</p>
          <button class="btn btn-orange" type="button" id="agReqBtn2" style="width:100%">${t('agBecomeBtn')}</button>
        </div>`;
    const rb = $('#agReqBtn2');
    if (rb) rb.onclick = () => {
      u.agentRequestPending = true;
      u.agentRequestedAt = Date.now();
      persist('users');
      toast(t('agReqAgentOk'));
      renderAgentGateOrApp();
    };
    return;
  }

  if (u.blocked) {
    box.innerHTML = `<div class="admin-gate" style="margin:10px auto">
      <div class="lock">🚫</div>
      <h3 style="font-family:var(--f-display);font-size:20px;margin-bottom:6px">${t('agBlockedT')}</h3>
      <p style="font-size:13.5px;color:var(--muted)">${t('agBlockedP')} ☎ ${esc(S().phone)}</p>
    </div>`;
    return;
  }

  /* ---- The dashboard proper ---- */
  const led = agentLedger(u.phone);
  const refs = DB.bookings.filter(b => String(b.referralCode || '').toUpperCase() === u.refCode)
    .sort((a, b) => b.createdAt - a.createdAt);
  const lead = referralLeaderboard(10);
  const myRank = lead.findIndex(x => x.phone === u.phone) + 1;
  const link = agentLink(u);
  const rule = commissionRuleFor('', todayISO());
  const ruleLabel = rule.mode === 'percent' ? rule.percent + '%' : inr(rule.flat);
  const tierInfo = tierFor(u.loyaltyPoints || 0);

  const csBadge = (st) => ({
    pending:   '<span class="badge pend">' + t('csPending') + '</span>',
    confirmed: '<span class="badge ok">' + t('csConfirmed') + '</span>',
    expired:   '<span class="badge">' + t('csExpired') + '</span>',
    cancelled: '<span class="badge bad">' + t('csCancelled') + '</span>',
    paid:      '<span class="badge ok">💸 ' + t('csPaid') + '</span>'
  }[st] || esc(st));

  const histRows = refs.map(b => {
    const r = routeById(b.routeId) || {};
    const c = commissionForBooking(b.id);
    const st = c ? c.status : 'pending';
    const amt = c ? inr(c.amount) : '—';
    return '<tr><td>' + esc(maskName((b.passengers[0] || {}).name)) + '<br><small>' + esc(maskPhone(b.contact && b.contact.phone)) + '</small></td>'
      + '<td>' + esc((r.from || '?') + ' → ' + (r.to || '?')) + '</td>'
      + '<td>' + fmtDate(b.date) + '</td>'
      + '<td style="font-family:var(--f-code);font-size:12px">' + esc(b.id) + '</td>'
      + '<td>' + csBadge(st) + '<br><b>' + amt + '</b>' + (c && c.note ? '<br><small style="color:var(--muted)">' + esc(c.note) + '</small>' : '') + '</td></tr>';
  }).join('');

  const milestones = AGENT_MILESTONES.map(m =>
    '<span class="ag-badge' + (led.confirmedCount >= m ? ' won' : '') + '">' + (led.confirmedCount >= m ? '🏅' : '🔒') + ' ' + m + '</span>').join('');

  const myPayouts = DB.payoutRequests.filter(p => p.agentId === u.phone).sort((a, b) => b.at - a.at);
  const poBadge = (st) => st === 'paid' ? '<span class="badge ok">' + t('poPaid') + '</span>'
    : st === 'declined' ? '<span class="badge bad">' + t('poDeclined') + '</span>'
    : '<span class="badge pend">' + t('poRequested') + '</span>';
  const payoutRows = myPayouts.length ? myPayouts.map(p =>
    '<div class="sum-row"><span>' + new Date(p.at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })
    + (p.note ? ' · ' + esc(p.note) : '') + '</span><b>' + inr(p.amount) + ' ' + poBadge(p.status) + '</b></div>').join('')
    : '<p style="color:var(--muted);font-size:13.5px">' + t('agPayoutNone') + '</p>';

  const leadRows = lead.length ? lead.map((x, i) =>
    '<div class="lead-row' + (x.phone === u.phone ? ' me' : '') + '"><span class="lr-rank">' + (i + 1) + '</span>'
    + '<span class="lr-name">' + (x.phone === u.phone ? '<b>★ ' + t('agStRank') + ' — ' + esc(u.refCode) + '</b>' : esc((x.user && maskName(x.user.name)) !== '—' ? maskName(x.user.name) : 'Agent ' + maskPhone(x.phone)))
    + '</span><span class="lr-n">' + x.n + ' ✓</span></div>').join('')
    : '<p style="color:var(--muted);font-size:13.5px">' + t('agLeadNone') + '</p>';

  box.innerHTML = `
    <div class="ag-code-card glass">
      <div class="ag-code-left">
        <small>${t('agYourCode')}</small>
        <div class="ag-code">${esc(u.refCode)}</div>
        <p style="font-size:13px;color:var(--muted);margin:6px 0 4px">${t('agShareHint')}</p>
        <p style="font-size:12.5px;color:var(--muted)">${tf('agCommRule', { r: ruleLabel, d: rule.windowDays })}</p>
        <div class="ag-share">
          <button class="btn btn-blue btn-sm" type="button" id="agCopyBtn">📋 ${t('agCopy')}</button>
          <button class="btn btn-wa btn-sm" type="button" id="agWaBtn">🟢 ${t('agWa')}</button>
        </div>
      </div>
      <div class="ag-qr" id="agQrBox"><small>${t('agQrT')}</small></div>
    </div>

    <div class="ag-stats">
      <div class="astat"><b>${agentReferralCount(u.refCode)}</b><span>${t('agStRefs')}</span></div>
      <div class="astat"><b style="color:var(--ok)">${led.confirmedCount}</b><span>${t('agStConf')}</span></div>
      <div class="astat"><b style="color:var(--warn)">${inr(led.pending)}</b><span>${t('agStPend')}</span></div>
      <div class="astat"><b style="color:var(--blue)">${inr(led.earned)}</b><span>${t('agStEarned')}</span></div>
      <div class="astat"><b>${inr(led.wallet)}</b><span>${t('agStWallet')}</span></div>
      <div class="astat"><b>${myRank ? '#' + myRank : '—'} ${tierInfo.tier.icon}</b><span>${t('agStRank')}</span></div>
    </div>

    <div class="c-card" style="margin-bottom:18px">
      <b style="font-family:var(--f-display);font-size:16px">🏅 ${t('agMilestones')}</b>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">${milestones}</div>
    </div>

    <div class="c-card" style="margin-bottom:18px">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
        <b style="font-family:var(--f-display);font-size:16px">💼 ${t('agBookForT')}</b>
        <a class="btn btn-orange btn-sm" href="#/" data-scroll="search-anchor">${t('agBookForBtn')}</a>
      </div>
      <p style="font-size:13.5px;color:var(--muted);margin-top:8px">${t('agBookForP')}</p>
    </div>

    <div class="c-card" style="margin-bottom:18px">
      <b style="font-family:var(--f-display);font-size:16px">📒 ${t('agHistT')}</b>
      ${refs.length
        ? '<div class="table-wrap" style="margin-top:12px"><table class="admin"><thead><tr><th>' + t('agColPax') + '</th><th>' + t('agColRoute') + '</th><th>' + t('agColDate') + '</th><th>' + t('agColBk') + '</th><th>' + t('agColComm') + '</th></tr></thead><tbody>' + histRows + '</tbody></table></div>'
        : '<p style="color:var(--muted);font-size:13.5px;margin-top:8px">' + t('agHistNone') + '</p>'}
    </div>

    <div class="c-card" style="margin-bottom:18px">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
        <b style="font-family:var(--f-display);font-size:16px">👛 ${t('agWalletT')}</b>
        <button class="btn btn-blue btn-sm" type="button" id="agPayoutBtn"${led.wallet <= 0 ? ' disabled' : ''}>${t('agReqBtn')}</button>
      </div>
      <p style="font-size:13.5px;color:var(--muted);margin:8px 0 12px">${t('agWalletP')}</p>
      ${payoutRows}
    </div>

    <div class="c-card">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
        <b style="font-family:var(--f-display);font-size:16px">🏆 ${t('agLeadT')}</b>
        ${myRank ? '<button class="btn btn-ghost btn-sm" type="button" id="agRankWaBtn">🟢 ' + t('agShareRank') + '</button>' : ''}
      </div>
      <div style="margin-top:12px">${leadRows}</div>
    </div>`;

  /* QR for the share link (same helper as the ticket QR) */
  makeQR(link, 170).then(url => {
    if (!url) return;
    const qb = $('#agQrBox'); if (!qb) return;
    const img = new Image(); img.src = url; img.width = 150; img.height = 150; img.alt = 'Referral link QR';
    qb.insertBefore(img, qb.firstChild);
  });

  $('#agCopyBtn').onclick = () => {
    const tmp = document.createElement('textarea'); tmp.value = link;
    document.body.appendChild(tmp); tmp.select();
    try { document.execCommand('copy'); toast(t('tCopied')); } catch (e) { toast(link); }
    tmp.remove();
  };
  $('#agWaBtn').onclick = () => {
    window.open('https://wa.me/?text=' + encodeURIComponent(tf('agWaMsg', { u: link })), '_blank', 'noopener');
  };
  const rw = $('#agRankWaBtn');
  if (rw) rw.onclick = () => {
    window.open('https://wa.me/?text=' + encodeURIComponent(tf('agRankWa', { n: myRank, c: led.confirmedCount, u: link })), '_blank', 'noopener');
  };
  const pb = $('#agPayoutBtn');
  if (pb) pb.onclick = () => openPayoutModal(u);
}

function openPayoutModal(u) {
  const led = agentLedger(u.phone);
  openModal(`
    <h3 class="m-title">👛 ${t('agReqT')}</h3>
    <p class="m-sub">${tf('agReqP', { a: inr(led.wallet) })}</p>
    <div class="field"><label>${t('agReqAmt')}</label><input id="poAmt" type="number" inputmode="numeric" min="1" max="${led.wallet}" value="${led.wallet}"><div class="err">${t('agReqErr')}</div></div>
    <div class="m-actions"><button class="btn btn-orange" type="button" id="poSend" style="width:100%">${t('agReqSend')}</button></div>`);
  $('#poSend').onclick = () => {
    const amt = Math.round(parseFloat($('#poAmt').value));
    const ok = amt >= 1 && amt <= led.wallet;
    $('#poAmt').closest('.field').classList.toggle('invalid', !ok);
    if (!ok) return;
    DB.payoutRequests.unshift({ id: 'P' + Date.now().toString(36), agentId: u.phone, amount: amt, at: Date.now(), status: 'requested', note: '' });
    persist('payoutRequests');
    closeModal();
    toast(t('agReqOk'));
    renderAgentGateOrApp();
  };
}
