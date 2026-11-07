<?php
/**
 * admin/_pro.php — the office's professional look (7 Nov 2026).
 *
 * Owner: "bus management, seat management, chalani, agent management —
 * professional looks deu; cartoon jasto bhayo, chalauna ni garo; ekdam
 * professional look ma category ma design gardeu."
 *
 * One small design system for the operations pages: quiet neutral surfaces,
 * one navy, orange only as an accent, status as small text badges (never
 * emoji), line icons, tabs per category, tables that read like a register.
 * Every class starts with "pc-" so nothing else in the admin is touched;
 * colours come from the admin's own tokens, so dark mode keeps working.
 */
declare(strict_types=1);

/** Text without emoji / pictographs (labels stay readable, look stays sober). */
function admin_plain(string $s): string
{
    $s = (string) preg_replace('/[\x{1F000}-\x{1FAFF}\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{2460}-\x{24FF}\x{25A0}-\x{27BF}\x{2900}-\x{297F}\x{2B00}-\x{2BFF}\x{3030}\x{303D}\x{FE0E}\x{FE0F}\x{200D}\x{20E3}]/u', '', $s);
    return trim((string) preg_replace('/\s{2,}/u', ' ', $s));
}

/** A line icon: the admin sprite (a-*) or the pro sprite (p-*). */
function pc_icon(string $name, string $cls = ''): string
{
    $id = str_starts_with($name, 'p-') || str_starts_with($name, 'a-') ? $name : 'p-' . $name;
    return '<svg class="a-ic pc-ic' . ($cls !== '' ? ' ' . $cls : '') . '" aria-hidden="true"><use href="#' . $id . '"/></svg>';
}

/**
 * Page header: title, one line of context, actions on the right.
 * $actions is trusted HTML (built by the page).
 */
function pc_head(string $title, string $sub = '', string $actions = ''): string
{
    return '<header class="pc-head"><div class="pc-head-t"><h2>' . Security::e($title) . '</h2>'
        . ($sub !== '' ? '<p>' . Security::e($sub) . '</p>' : '') . '</div>'
        . ($actions !== '' ? '<div class="pc-head-a">' . $actions . '</div>' : '') . '</header>';
}

/**
 * Category tabs. $tabs = key => [label, icon, count|null]; links keep $keep query keys.
 *
 * @param array<string, array{0:string,1:string,2?:int|null}> $tabs
 * @param array<string, string> $keep
 */
function pc_tabs(array $tabs, string $active, array $keep = [], string $param = 'tab'): string
{
    $h = '<nav class="pc-tabs" aria-label="Sections">';
    foreach ($tabs as $k => $t) {
        $q = http_build_query($keep + [$param => $k]);
        $n = $t[2] ?? null;
        $h .= '<a href="?' . Security::e($q) . '" class="' . ($k === $active ? 'on' : '') . '"' . ($k === $active ? ' aria-current="page"' : '') . '>'
            . pc_icon($t[1]) . '<span>' . Security::e($t[0]) . '</span>'
            . ($n !== null ? '<em>' . (int) $n . '</em>' : '') . '</a>';
    }
    return $h . '</nav>';
}

/** A status badge: tone = ok | warn | bad | info | mute. */
function pc_badge(string $text, string $tone = 'mute'): string
{
    return '<span class="pc-badge pc-' . Security::e($tone) . '">' . Security::e($text) . '</span>';
}

function admin_pro_css(): string
{
    return <<<'CSS'
/* ---- professional operations look (admin/_pro.php) ---- */
.pc{display:grid;gap:16px;max-width:1240px}
.wrap>h1:has(+.pc>.pc-head:first-child),.wrap>h1:has(+.pc>.flash+.pc-head){display:none}
.pc-ic{width:16px;height:16px}
.pc-head{display:flex;flex-wrap:wrap;gap:12px 20px;align-items:flex-end;justify-content:space-between;padding:0 0 4px;border-bottom:1px solid var(--line)}
.pc-head h2{margin:0;font-size:20px;font-weight:650;letter-spacing:-.01em;color:var(--ink)}
.pc-head p{margin:4px 0 10px;font-size:13px;color:var(--mut)}
.pc-head-a{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding-bottom:10px}
.pc-tabs{display:flex;gap:2px;border-bottom:1px solid var(--line);overflow-x:auto;scrollbar-width:none}
.pc-tabs a{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;font-size:13.5px;font-weight:600;color:var(--mut);border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap;text-decoration:none}
.pc-tabs a:hover{color:var(--ink)}
.pc-tabs a.on{color:var(--navy);border-bottom-color:var(--navy)}
:root[data-theme="dark"] .pc-tabs a.on{color:#9cc0ff;border-bottom-color:#9cc0ff}
.pc-tabs em{font-style:normal;font-size:11px;font-weight:700;background:var(--hover);color:var(--ink);border-radius:999px;padding:1px 7px}
.pc-card{background:var(--card);border:1px solid var(--line);border-radius:12px}
.pc-card-h{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--line)}
.pc-card-h h3{margin:0;font-size:15px;font-weight:650;color:var(--ink)}
.pc-card-h small{color:var(--mut);font-size:12.5px}
.pc-card-b{padding:14px 16px}
.pc-card-f{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:10px 16px;border-top:1px solid var(--line);background:var(--head);border-radius:0 0 12px 12px}
.pc-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}
.pc-kpi{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 14px}
.pc-kpi small{display:block;font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--mut)}
.pc-kpi b{display:block;margin-top:4px;font-size:20px;font-weight:650;color:var(--ink);font-variant-numeric:tabular-nums}
.pc-kpi i{display:block;font-style:normal;font-size:12px;color:var(--mut);margin-top:2px}
.pc-kpi.ok b{color:#15803D}.pc-kpi.warn b{color:#B45309}.pc-kpi.bad b{color:#B91C1C}
.pc-badge{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:650;letter-spacing:.01em;border-radius:6px;padding:3px 8px;white-space:nowrap;border:1px solid transparent}
.pc-badge::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
.pc-ok{background:#ECFDF3;color:#15803D;border-color:#BBF0CF}.pc-warn{background:#FFF7E8;color:#B45309;border-color:#F5D9A8}
.pc-bad{background:#FEF2F2;color:#B91C1C;border-color:#F6C9C9}.pc-info{background:#EEF4FF;color:#1D4FA3;border-color:#C9DAF8}
.pc-mute{background:var(--hover);color:var(--mut);border-color:var(--line)}
:root[data-theme="dark"] .pc-ok{background:rgba(22,163,74,.15);border-color:rgba(22,163,74,.35);color:#6EE7A0}
:root[data-theme="dark"] .pc-warn{background:rgba(245,158,11,.14);border-color:rgba(245,158,11,.35);color:#FBC56B}
:root[data-theme="dark"] .pc-bad{background:rgba(220,38,38,.14);border-color:rgba(220,38,38,.35);color:#FCA5A5}
:root[data-theme="dark"] .pc-info{background:rgba(59,130,246,.14);border-color:rgba(59,130,246,.35);color:#9CC0FF}
.pc-btn{display:inline-flex;align-items:center;gap:7px;min-height:36px;padding:0 12px;border-radius:8px;border:1px solid var(--line);background:var(--card);color:var(--ink);font:inherit;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap}
.pc-btn:hover{background:var(--hover)}
.pc-btn.pri{background:var(--navy);border-color:var(--navy);color:#fff}.pc-btn.pri:hover{filter:brightness(1.12)}
.pc-btn.acc{background:#F07C1F;border-color:#F07C1F;color:#fff}
.pc-btn.ok{background:#15803D;border-color:#15803D;color:#fff}
.pc-btn.dan{color:#B91C1C;border-color:#F0C4C4}.pc-btn.dan:hover{background:#FEF2F2}
.pc-btn.sm{min-height:30px;padding:0 9px;font-size:12.5px}
.pc-btn[disabled]{opacity:.5;cursor:not-allowed}
.pc-in,.pc-sel{min-height:36px;border-radius:8px;border:1px solid var(--line);background:var(--card);color:var(--ink);padding:0 10px;font:inherit;font-size:13.5px}
.pc-in:focus,.pc-sel:focus{outline:2px solid rgba(29,79,163,.25);border-color:#1D4FA3}
.pc-form{display:inline-flex;flex-wrap:wrap;gap:6px;align-items:center;margin:0}
.pc-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.pc-sep{width:1px;height:24px;background:var(--line);margin:0 4px}
.pc-meta{display:flex;flex-wrap:wrap;gap:6px 18px;font-size:13px;color:var(--mut)}
.pc-meta b{color:var(--ink);font-weight:600}
.pc-meta span{display:inline-flex;align-items:center;gap:6px}
.pc-table{width:100%;border-collapse:collapse;font-size:13.5px}
.pc-table th{text-align:left;font-size:11px;font-weight:650;letter-spacing:.05em;text-transform:uppercase;color:var(--mut);background:var(--head);padding:9px 12px;border-bottom:1px solid var(--line)}
.pc-table td{padding:10px 12px;border-bottom:1px solid var(--line);vertical-align:middle}
.pc-table tr:last-child td{border-bottom:0}
.pc-table td.num,.pc-table th.num{text-align:right;font-variant-numeric:tabular-nums}
.pc-switch{display:inline-flex;align-items:center;gap:8px;border:0;background:none;padding:0;cursor:pointer;font:inherit;font-size:12.5px;font-weight:650;color:var(--mut)}
.pc-switch i{width:34px;height:20px;border-radius:999px;background:#CBD5E1;position:relative;transition:background .15s}
.pc-switch i::after{content:"";position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:left .15s}
.pc-switch.on i{background:#15803D}.pc-switch.on i::after{left:16px}.pc-switch.on{color:#15803D}
.pc-dates{display:flex;gap:4px;overflow-x:auto;scrollbar-width:thin}
.pc-dates a{display:flex;flex-direction:column;align-items:center;min-width:56px;padding:6px 8px;border-radius:8px;border:1px solid var(--line);background:var(--card);color:var(--ink);text-decoration:none;font-size:13px;font-weight:650;line-height:1.25}
.pc-dates a small{font-size:10.5px;font-weight:600;color:var(--mut);text-transform:uppercase;letter-spacing:.04em}
.pc-dates a.on{background:var(--navy);border-color:var(--navy);color:#fff}.pc-dates a.on small{color:#C7D6F3}
/* seat picture */
.pc-seats{display:flex;flex-wrap:wrap;gap:12px}
.pc-deck{border:1px solid var(--line);border-radius:10px;padding:8px 10px;background:var(--head)}
.pc-deck h4{margin:0 0 6px;font-size:11px;font-weight:650;letter-spacing:.05em;text-transform:uppercase;color:var(--mut)}
.pc-srow{display:flex;gap:10px;margin:4px 0}.pc-sg{display:inline-flex;gap:4px}
.pc-seat{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:28px;padding:0 4px;border-radius:6px;font-style:normal;font-size:11px;font-weight:650;border:1px solid var(--line);background:var(--card);color:var(--mut);position:relative;font-variant-numeric:tabular-nums}
.pc-seat.paid{background:#DCFCE7;border-color:#86EFAC;color:#14532D}
.pc-seat.agent{background:#FEF3C7;border-color:#FCD34D;color:#78350F}
.pc-seat.due{background:#FEE2E2;border-color:#FCA5A5;color:#7F1D1D}
.pc-seat.w::after{content:"";position:absolute;top:3px;right:3px;width:5px;height:5px;border-radius:50%;background:#DB2777}
:root[data-theme="dark"] .pc-seat.paid{background:rgba(22,163,74,.25);border-color:rgba(22,163,74,.6);color:#BBF7D0}
:root[data-theme="dark"] .pc-seat.agent{background:rgba(245,158,11,.22);border-color:rgba(245,158,11,.6);color:#FDE68A}
:root[data-theme="dark"] .pc-seat.due{background:rgba(220,38,38,.22);border-color:rgba(220,38,38,.6);color:#FECACA}
.pc-legend{display:flex;flex-wrap:wrap;gap:14px;font-size:12px;color:var(--mut)}
.pc-legend span{display:inline-flex;align-items:center;gap:6px}
.pc-legend i{width:12px;height:12px;border-radius:3px;border:1px solid var(--line);display:inline-block}
.pc-note{font-size:12.5px;color:var(--mut);margin:0}
.pc-empty{padding:28px;text-align:center;color:var(--mut);font-size:14px}
.pc-ai{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.pc-ai .pc-in{flex:1 1 300px}
.pc-grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px}
@media(max-width:640px){.pc-head h2{font-size:18px}.pc-seat{min-width:28px;height:26px;font-size:10px}.pc-card-b{padding:12px}}
CSS;
}

/** Line icons the operations pages need (prefix p-), stroke = currentColor. */
function admin_pro_sprite(): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true" focusable="false"><defs>'
        . '<symbol id="p-printer" viewBox="0 0 24 24"><path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 14h12v7H6z"/></symbol>'
        . '<symbol id="p-download" viewBox="0 0 24 24"><path d="M12 3v12M7 10l5 5 5-5M4 21h16"/></symbol>'
        . '<symbol id="p-image" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/></symbol>'
        . '<symbol id="p-link" viewBox="0 0 24 24"><path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></symbol>'
        . '<symbol id="p-share" viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></symbol>'
        . '<symbol id="p-edit" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></symbol>'
        . '<symbol id="p-refresh" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></symbol>'
        . '<symbol id="p-left" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></symbol>'
        . '<symbol id="p-right" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></symbol>'
        . '<symbol id="p-check" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></symbol>'
        . '<symbol id="p-x" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></symbol>'
        . '<symbol id="p-pin" viewBox="0 0 24 24"><path d="M12 22s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/></symbol>'
        . '<symbol id="p-wallet" viewBox="0 0 24 24"><path d="M3 7a2 2 0 0 1 2-2h13v4"/><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M16 13h2"/></symbol>'
        . '<symbol id="p-id" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="11" r="2.5"/><path d="M5.5 17a3.5 3.5 0 0 1 7 0M14 9h4M14 13h4"/></symbol>'
        . '<symbol id="p-phone" viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/></symbol>'
        . '<symbol id="p-bus" viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="15" rx="3"/><path d="M4 11h16M8 18v3M16 18v3"/><circle cx="8" cy="14.5" r="1"/><circle cx="16" cy="14.5" r="1"/></symbol>'
        . '<symbol id="p-seat" viewBox="0 0 24 24"><path d="M6 4v9a2 2 0 0 0 2 2h8"/><path d="M6 15v5M18 15v5M6 18h12"/><rect x="9" y="9" width="9" height="4" rx="1"/></symbol>'
        . '<symbol id="p-route" viewBox="0 0 24 24"><circle cx="6" cy="19" r="2.5"/><circle cx="18" cy="5" r="2.5"/><path d="M8.5 19H15a3.5 3.5 0 0 0 0-7H9a3.5 3.5 0 0 1 0-7h6.5"/></symbol>'
        . '<symbol id="p-list" viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></symbol>'
        . '<symbol id="p-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>'
        . '<symbol id="p-spark" viewBox="0 0 24 24"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/></symbol>'
        . '<symbol id="p-calendar" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></symbol>'
        . '<symbol id="p-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5a6.5 6.5 0 0 1 3.5 5.5"/></symbol>'
        . '<symbol id="p-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></symbol>'
        . '<symbol id="p-cash" viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/></symbol>'
        . '<symbol id="p-trash" viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></symbol>'
        . '<symbol id="p-swap" viewBox="0 0 24 24"><path d="M7 4L3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7"/></symbol>'
        . '<symbol id="p-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>'
        . '</defs></svg>';
}
