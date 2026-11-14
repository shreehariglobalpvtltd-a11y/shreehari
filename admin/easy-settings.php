<?php
/**
 * admin/easy-settings.php — 🤖 Easy Settings: say it, the app changes it.
 *
 * 6 Nov 2026 (owner: "setting ma API halne thau sabai bhanda sajilo; AI ni hos,
 * tesllai j bhane ni, jun bhasa ma bhane ni change gardos manually bhanda;
 * installed AI lai command dine, tesle kaam gardos; API / password halne thau
 * ekdam kam — pura app automatic"). One page, three things:
 *
 *   1. 🤖 COMMAND  — type (or speak) in Nepali / Hindi / English / roman:
 *                    "whatsapp bot band gara", "PIN login on", "agent le cancel
 *                    garda request matra". Words are matched first (no AI
 *                    needed); anything the words miss goes to the VPS model
 *                    (ApAi, Ollama) or the cloud brain, which may ONLY pick
 *                    from the whitelist below. Every change is shown first
 *                    (old → new) and saved only on "✅ Yes, change".
 *   2. ⚡ SWITCHES  — the everyday on/off switches, one tap each.
 *   3. 🔑 ONE KEY BOX — paste any API key; the app recognises whose key it is
 *                    (sk-ant- → Claude, AIza → Gemini, sk- → OpenAI, EAA →
 *                    Meta WhatsApp/Facebook …) and files it. Keys are
 *                    write-only: this page shows only ✅ set / ❌ missing.
 *
 * Nothing here can touch a setting outside $ES_KEYS; every save is audited
 * (Settings::set + Logger::audit, secrets masked). Super-admin / settings
 * managers only (Auth::canManageSettings, the same gate as settings.php).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');
$canEdit = Auth::canManageSettings();
require_once INCLUDE_PATH . '/aiops.php';
/* 6 Nov 2026: S Hari AI also changes fares, routes, bus numbers, extra seats, commission — each
   action checks its own permission (AiOps::allowed), so a routes / schedules manager may command too. */
$canCmd = $canEdit || Auth::can('routes.edit') || Auth::can('schedules.manage');
$e = static fn($v): string => Security::e((string) ($v ?? ''));

/* ---- the whitelist: key => [label, type, words that name it, options] ---- */
$ES_KEYS = [
    'quick_unlock_on'          => ['🔐 PIN / Pattern login', 'bool', ['pin', 'pattern', 'पिन', 'प्याटर्न', 'quick unlock', 'lock']],
    'login_one_door_hi_on'     => ['💬 WhatsApp "hi" login', 'bool', ['hi login', 'whatsapp login', 'wa login', 'hi pathaune', 'login hi']],
    'agent_chalani_on'         => ['🧾 Agent ले आफ्नो chalani हेर्ने', 'bool', ['agent chalani', 'chalani agent', 'agent copy', 'एजेन्ट चलानी']],
    'ap_join_open'             => ['📝 नयाँ agent form (public) खुला', 'bool', ['join form', 'agent form', 'apply form', 'naya agent', 'registration', 'join']],
    'whatsapp_notify_customer' => ['📲 Customer लाई WhatsApp ticket', 'bool', ['customer whatsapp', 'ticket whatsapp', 'whatsapp ticket', 'customer lai whatsapp']],
    'whatsapp_notify_admin'    => ['🔔 Office लाई WhatsApp alert', 'bool', ['admin whatsapp', 'office alert', 'admin alert', 'office lai whatsapp']],
    'wa_booking_on'            => ['🤖 WhatsApp बाट booking (bot)', 'bool', ['whatsapp bot', 'wa bot', 'whatsapp booking', 'bot booking', 'bot', 'बोट']],
    'ai_web_agent_on'          => ['🧠 Website AI assistant', 'bool', ['website ai', 'web ai', 'ai assistant', 'sahayak', 'chat ai', 'website chat']],
    'ai_kb_on'                 => ['📚 AI knowledge base', 'bool', ['knowledge', 'kb', 'gyan', 'ज्ञान']],
    'fb_msgr_on'               => ['📘 Facebook Messenger AI', 'bool', ['facebook', 'messenger', 'fb']],
    'allow_cod'                => ['💵 Cash on boarding (COD)', 'bool', ['cod', 'cash on', 'nagad', 'नगद', 'cash']],
    'trip_reminders_enabled'   => ['⏰ Trip reminder message', 'bool', ['reminder', 'yaad', 'सम्झना', 'remind']],
    'eta_alert_on'             => ['🛰 Bus aaune samay (ETA) alert', 'bool', ['eta', 'arrival alert', 'bus aaune', 'gps alert']],
    'wa_marketing_on'          => ['📣 WhatsApp marketing', 'bool', ['marketing', 'promotion', 'offer message', 'campaign']],
    'seat_pick_manual_first'   => ['💺 सिट आफैं छान्ने (auto भन्दा पहिले)', 'bool', ['seat select', 'seat chhan', 'auto seat', 'seat pick', 'सिट छान', 'manual seat']],
    'staff_door_only'          => ['🔐 Agent/staff only on .network', 'bool', ['network', '.network', 'staff door', 'agent door', 'network matra']],
    'chalani_public_on'        => ['🔗 Public chalani link (login बिना)', 'bool', ['public chalani', 'chalani link', 'public link', 'chalani public']],
    'cancel_fine_on'           => ['💸 Cancel गर्दा fine', 'bool', ['cancel fine', 'fine', 'jariwana', 'जरिवाना']],
    'agent_cancel_mode'        => ['🗑 Agent cancel: direct / request', 'enum', ['agent cancel', 'cancel mode', 'agent le cancel'], ['direct' => 'Direct (agent cancels)', 'request' => 'Request (office cancels)']],
];
$ES_SECRETS = [
    'anthropic_api_key'  => ['🟠 Claude (Anthropic)', '/^sk-ant-[A-Za-z0-9_\-]{20,}$/'],
    'gemini_api_key'     => ['🔵 Gemini (Google)', '/^AIza[0-9A-Za-z_\-]{30,}$/'],
    'openrouter_api_key' => ['🟣 OpenRouter', '/^sk-or-[A-Za-z0-9_\-]{20,}$/'],
    'openai_api_key'     => ['🟢 OpenAI', '/^sk-(proj-)?[A-Za-z0-9_\-]{20,}$/'],
    'grok_api_key'       => ['⚫ Grok (xAI)', '/^xai-[A-Za-z0-9_\-]{20,}$/'],
    'whatsapp_api_token' => ['💬 Meta WhatsApp token', '/^EAA[A-Za-z0-9]{40,}$/'],
    'aisensy_api_key'    => ['💬 AiSensy key', '/^eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/'],
];
$ON  = ['on', 'chalu', 'chalau', 'chalaideu', 'chalaidinu', 'chalaunu', 'khol', 'kholdeu', 'kholdinu', 'enable', 'start', 'activate', 'yes', 'haan', 'gar on',
        'चालु', 'खोल', 'चलाउ', 'सुरु', 'अन', 'शुरू', 'चालू', 'करो चालू', 'खोलो', 'shuru', 'suru', 'lagau', 'lagaideu', 'lagaa'];
$OFF = ['off', 'band', 'banda', 'bandh', 'bnd', 'disable', 'stop', 'roka', 'rok', 'rokdeu', 'hatau', 'hatai', 'na chalau', 'nachalau', 'deactivate',
        'बन्द', 'बंद', 'रोक', 'हटाउ', 'अफ', 'रोको', 'hatao'];

$curVal = static function (string $k) use ($ES_KEYS): string {
    $t = $ES_KEYS[$k][1] ?? 'bool';
    if ($t === 'bool') {
        // the default each switch has in the code that reads it (6 Nov 2026), so an unsaved switch shows what really happens
        $codeDefaultOn = ['quick_unlock_on', 'login_one_door_hi_on', 'agent_chalani_on', 'ap_join_open', 'whatsapp_notify_customer',
                          'whatsapp_notify_admin', 'wa_booking_on', 'allow_cod', 'trip_reminders_enabled', 'seat_pick_manual_first',
                          'staff_door_only', 'chalani_public_on', 'cancel_fine_on'];
        return Settings::getBool($k, in_array($k, $codeDefaultOn, true)) ? '1' : '0';
    }
    return Settings::getString($k, $t === 'enum' ? (string) array_key_first($ES_KEYS[$k][3]) : '');
};
$show = static function (string $k, string $v) use ($ES_KEYS): string {
    $t = $ES_KEYS[$k][1] ?? 'bool';
    if ($t === 'bool') { return $v === '1' ? 'ON ✅' : 'OFF ⛔'; }
    return (string) ($ES_KEYS[$k][3][$v] ?? $v);
};

/* ---- 1a. words first: which whitelisted setting(s) + on/off ---- */
$parseWords = static function (string $text) use ($ES_KEYS, $ON, $OFF): array {
    $t = ' ' . mb_strtolower(preg_replace('/\s+/u', ' ', $text) ?? $text) . ' ';
    // last position (bytes) of any of the words; a latin word must stand alone
    // ("on" is not the "on" inside "promotion")
    $hasAny = static function (string $t, array $words): int {
        $best = -1;
        foreach ($words as $w) {
            $w = mb_strtolower(trim($w));
            if ($w === '') { continue; }
            if (preg_match('/^[a-z ]+$/', $w) === 1) {
                if (preg_match_all('/(?<![a-z])' . preg_quote($w, '/') . '(?![a-z])/', $t, $mm, PREG_OFFSET_CAPTURE) > 0) {
                    $best = max($best, (int) end($mm[0])[1]);
                }
            } elseif (($p = strrpos($t, $w)) !== false) {
                $best = max($best, (int) $p);
            }
        }
        return $best;
    };
    $onAt  = $hasAny($t, $ON);
    $offAt = $hasAny($t, $OFF);
    $out = [];
    foreach ($ES_KEYS as $k => $def) {
        $hit = false;
        foreach ($def[2] as $w) {
            if (mb_strpos($t, mb_strtolower($w)) !== false) { $hit = true; break; }
        }
        if (!$hit) { continue; }
        if ($def[1] === 'bool') {
            if ($onAt < 0 && $offAt < 0) { continue; }
            // the verb said LAST wins ("on chha, band gara" → off)
            $out[$k] = $offAt > $onAt ? '0' : '1';
        } elseif ($def[1] === 'enum') {
            foreach ($def[3] as $opt => $lbl) {
                if (mb_strpos($t, $opt) !== false) { $out[$k] = $opt; }
            }
            if (!isset($out[$k]) && preg_match('/(request|anurodh|अनुरोध|office)/u', $t) === 1) { $out[$k] = 'request'; }
        }
    }
    return $out;
};

/* ---- 1b. the AI, only for what the words missed; held to the whitelist ---- */
$parseAi = static function (string $text) use ($ES_KEYS): array {
    $catalog = [];
    foreach ($ES_KEYS as $k => $def) {
        $catalog[] = $k . ' (' . $def[0] . ', ' . ($def[1] === 'bool' ? 'value "1" or "0"' : 'one of: ' . implode('|', array_keys($def[3]))) . ')';
    }
    $sys = "You turn an office manager's instruction (any language: Nepali, Hindi, English, romanised) into settings changes.\n"
         . "Allowed settings ONLY:\n- " . implode("\n- ", $catalog) . "\n"
         . 'Reply JSON {"changes":[{"key":"...","value":"..."}]}. Use an empty list when the instruction does not clearly name one of these. Never invent keys.';
    $j = null;
    try {
        require_once INCLUDE_PATH . '/agentpro-ai.php';
        if (ApAi::enabled()) {
            $j = ApAi::json($sys, $text, ['type' => 'object', 'properties' => ['changes' => ['type' => 'array', 'items' => ['type' => 'object',
                'properties' => ['key' => ['type' => 'string'], 'value' => ['type' => 'string']], 'required' => ['key', 'value']]]], 'required' => ['changes']],
                [], ['purpose' => 'settings', 'max_tokens' => 300, 'timeout' => 25, 'lock_wait' => 5]);
        }
    } catch (Throwable $ex) {
        $j = null;
    }
    if (!is_array($j)) {
        try {
            require_once INCLUDE_PATH . '/aibrain.php';
            if (AiBrain::configured()) {
                $r = AiBrain::text($sys . "\nJSON only.", [['role' => 'user', 'content' => $text]], 300, 'settings');
                $raw = trim((string) ($r['text'] ?? ''));
                if (preg_match('/\{.*\}/s', $raw, $m) === 1) { $j = json_decode($m[0], true); }
            }
        } catch (Throwable $ex) {
            $j = null;
        }
    }
    $out = [];
    foreach ((array) ($j['changes'] ?? []) as $c) {
        $k = (string) ($c['key'] ?? '');
        $v = (string) ($c['value'] ?? '');
        if (!isset($ES_KEYS[$k])) { continue; }
        if ($ES_KEYS[$k][1] === 'bool') { $v = in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true) ? '1' : '0'; }
        elseif ($ES_KEYS[$k][1] === 'enum' && !isset($ES_KEYS[$k][3][$v])) { continue; }
        $out[$k] = $v;
    }
    return $out;
};

$save = static function (string $k, string $v) use ($ES_KEYS, $curVal, $admin): void {
    $old = $curVal($k);
    $type = $ES_KEYS[$k][1] === 'bool' ? 'bool' : 'string';
    // seat_pick_manual_first is read by the public booking page too (SHG_BOOT.settings) — public on first save
    Settings::set($k, $type === 'bool' ? ($v === '1') : $v, $type, 'general', $k === 'seat_pick_manual_first');
    Logger::audit('settings.easy', 'setting', $k, ['value' => $old], ['value' => $v], 'Easy Settings by admin #' . (int) $admin['id']);
};

$flash = null;
$proposal = null;   // [key => new value] waiting for "Yes"
$opsProp  = [];     // AiOps actions waiting for "Yes"
$cmdText = '';
$backTo  = '';
/* the switches above are known to S Hari AI too (label, type, options, value now) */
$reg = [];
foreach ($ES_KEYS as $k => $def) {
    $reg[$k] = [$def[0], $def[1], $def[3] ?? [], $curVal($k)];
}
AiOps::registerKeys($reg);
$cleanBack = static function (string $b): string {
    return preg_match('/^[a-z0-9\-]+\.php(\?[A-Za-z0-9_=&%\-.:]*)?$/', $b) === 1 ? $b : '';
};
// a routes / schedules manager may send commands (cmd / yes) only — never switches or keys
if (($canEdit || ($canCmd && in_array((string) ($_POST['act'] ?? ''), ['cmd', 'yes'], true))) && Security::isPost()) {
    $act = (string) ($_POST['act'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Page was open too long — try again.'];
    } elseif ($act === 'cmd') {
        $cmdText = Security::clean((string) ($_POST['cmd'] ?? ''), 400);
        $backTo  = $cleanBack((string) ($_POST['back'] ?? ''));
        if ($cmdText !== '') {
            /* words first: the operations (fare, route, bus, extra, commission, fine),
               then the one-tap switches; the AI only when the words found nothing */
            $ops  = AiOps::parseWords($cmdText);
            $prop = $canEdit ? $parseWords($cmdText) : [];
            $via  = 'words';
            if ($ops === [] && $prop === []) {
                $ops = AiOps::parseAi($cmdText);
                $via = 'AI';
            }
            // drop no-ops and what this login may not do
            $prop = array_filter($prop, static fn($v, $k): bool => $curVal((string) $k) !== (string) $v, ARRAY_FILTER_USE_BOTH);
            $denied = [];
            $ops = array_values(array_filter($ops, static function (array $a) use (&$denied): bool {
                if (!AiOps::allowed($a)) { $denied[] = $a['type']; return false; }
                $rows = AiOps::preview($a);
                foreach ($rows as $r) { if ((string) $r[1] !== (string) $r[2]) { return true; } }
                return false;
            }));
            if ($prop === [] && $ops === []) {
                $flash = ['bad', $denied !== []
                    ? '⛔ यो बदल्ने अनुमति तपाईंसँग छैन · Not allowed for your login (' . implode(', ', array_unique($denied)) . ').'
                    : '🤔 बुझिएन वा पहिल्यै त्यस्तै छ · Not understood, or already like that. अर्को शब्दमा भन्नुहोस्, वा तलको switch / खोज प्रयोग गर्नुहोस्।'];
            } else {
                $proposal = $prop;
                $opsProp  = $ops;
                $_SESSION['es_prop'] = ['p' => $prop, 'ops' => $ops, 't' => time(), 'via' => $via, 'cmd' => $cmdText, 'back' => $backTo];
            }
        }
    } elseif ($act === 'yes') {
        $p = $_SESSION['es_prop'] ?? null;
        unset($_SESSION['es_prop']);
        if (!is_array($p) || (int) ($p['t'] ?? 0) < time() - 600) {
            $flash = ['bad', 'Time ran out — say it again.'];
        } else {
            $done = [];
            if ($canEdit) {
                foreach ((array) ($p['p'] ?? []) as $k => $v) {
                    if (isset($ES_KEYS[$k])) { $save((string) $k, (string) $v); $done[] = $ES_KEYS[$k][0] . ' → ' . $show((string) $k, (string) $v); }
                }
            }
            foreach (AiOps::apply((array) ($p['ops'] ?? []), $admin) as $line) {
                $done[] = $line;
            }
            Logger::audit('settings.ai_command', 'setting', 'easy-settings', null, ['cmd' => (string) ($p['cmd'] ?? ''), 'via' => (string) ($p['via'] ?? '')], implode(' | ', $done));
            $backTo = (string) ($p['back'] ?? '');
            $flash = ['ok', '✅ ' . ($done !== [] ? implode(' · ', $done) : 'Nothing changed.')];
        }
    } elseif ($act === 'toggle') {
        $k = (string) ($_POST['key'] ?? '');
        if (isset($ES_KEYS[$k]) && $ES_KEYS[$k][1] === 'bool') {
            $v = $curVal($k) === '1' ? '0' : '1';
            $save($k, $v);
            $flash = ['ok', '✅ ' . $ES_KEYS[$k][0] . ' → ' . $show($k, $v)];
        } elseif (isset($ES_KEYS[$k]) && $ES_KEYS[$k][1] === 'enum') {
            $v = (string) ($_POST['value'] ?? '');
            if (isset($ES_KEYS[$k][3][$v])) { $save($k, $v); $flash = ['ok', '✅ ' . $ES_KEYS[$k][0] . ' → ' . $show($k, $v)]; }
        }
    } elseif ($act === 'key') {
        $raw = trim((string) ($_POST['secret'] ?? ''));
        $raw = trim($raw, " \t\n\r\"'");
        $hit = '';
        foreach ($ES_SECRETS as $k => $def) {
            if (preg_match($def[1], $raw) === 1) { $hit = $k; break; }
        }
        if ($raw === '') {
            $flash = ['bad', 'Key खाली छ · Paste a key first.'];
        } elseif ($hit === '') {
            $flash = ['bad', '🤔 यो कुन कम्पनीको key हो चिनिएन — पूरा key copy गरेर फेरि paste गर्नुहोस्, वा "All settings" मा राख्नुहोस्।'];
        } else {
            Settings::set($hit, $raw, 'string', 'keys');
            Logger::audit('settings.easy_key', 'setting', $hit, null, ['value' => '***' . substr($raw, -4)], 'Key filed by Easy Settings (auto-detected)');
            $flash = ['ok', '✅ ' . $ES_SECRETS[$hit][0] . ' key राखियो · saved (…' . substr($raw, -4) . ')'];
        }
    }
}

admin_header('🤖 S Hari AI · सेटिङ र काम (बोलेर)', 'easy-settings');
?>
<style>
.es{max-width:880px;display:grid;gap:16px}
.es .card{background:var(--card,#fff);border:1px solid var(--line,#dcdcdc);border-radius:18px;padding:16px 18px}
.es h2{margin:0 0 8px;font-size:18px}
.es .cmd{display:flex;gap:8px;flex-wrap:wrap}
.es .cmd input{flex:1 1 260px;min-height:54px;font-size:17px;padding:10px 14px;border-radius:12px;border:1.5px solid var(--line,#9aa6ba);background:var(--bg,#f6f8fc);color:inherit}
.es .cmd .btn{min-height:54px;font-size:16px;font-weight:800}
.es .ex{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.es .ex button{border:1px dashed var(--line,#c9d3e3);background:transparent;color:inherit;border-radius:999px;padding:5px 11px;font-size:12.5px;cursor:pointer}
/* 14 Nov 2026 (staff 360px audit): the example chips were 27px on a touch phone */
@media(max-width:820px) and (pointer:coarse){.es .ex button{min-height:40px;padding:5px 14px;font-size:13px}}
.es .prop{border:2px solid #F0B44C;background:#FFF8E6;color:#3A2A00;border-radius:14px;padding:12px 14px;margin-top:10px}
.es .prop li{margin:4px 0;font-size:15px}
.es .sw{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:8px}
.es .sw form{margin:0}
.es .sw button{width:100%;display:flex;justify-content:space-between;align-items:center;gap:8px;min-height:50px;border-radius:12px;border:1.5px solid var(--line,#c9d3e3);background:var(--bg,#f6f8fc);color:inherit;font:inherit;font-size:14px;font-weight:700;padding:8px 12px;cursor:pointer;text-align:left}
.es .pill{flex:0 0 auto;font-size:12px;font-weight:900;border-radius:999px;padding:3px 10px}
.es .pill.on{background:#16A34A;color:#fff}.es .pill.off{background:#9CA3AF;color:#fff}
.es .keys{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
.es .keys span{font-size:12.5px;font-weight:700;border-radius:999px;padding:4px 10px;border:1px solid var(--line,#dcdcdc)}
.es .muted{font-size:12.5px;color:var(--mut,#5c6b85);line-height:1.5}
.es .mic{min-width:54px}
.es-find{display:flex;flex-direction:column;gap:4px;margin-top:8px;max-height:420px;overflow:auto}
.es-hit{display:block;text-align:left;border:1px solid var(--line,#dcdcdc);border-radius:10px;padding:8px 10px;background:var(--card,#fff);color:inherit;text-decoration:none;font:inherit;font-size:14px;cursor:pointer}
.es-hit small{display:block;color:var(--mut,#5c6b85);font-size:11.5px}.es-hit.pg{border-left:4px solid #2E5FA8}.es-hit.st{border-left:4px solid #F0B44C}
</style>
<div class="es">
<?php if ($flash !== null): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= $e($flash[1]) ?></div><?php endif; ?>
<?php if ($backTo !== ''): ?><p style="margin:0"><a class="btn ghost" href="/admin/<?= $e($backTo) ?>">← फर्कनुहोस् · Back</a></p><?php endif; ?>
<?php if (!$canEdit): ?><div class="flash bad">सेटिङ बदल्ने अनुमति छैन · You may view only; ask the super-admin.</div><?php endif; ?>

  <div class="card">
    <h2>🤖 S Hari AI लाई भन्नुहोस् · Tell the AI what to change</h2>
    <form method="post" class="cmd">
      <?= Security::csrfField() ?><input type="hidden" name="act" value="cmd">
      <input id="esCmd" name="cmd" value="<?= $e($cmdText) ?>" placeholder="जस्तै: Surat ko bhada 2300 · 9 Nov ko bus band · Bus 02 ko number GJ05AB1234 · whatsapp bot band gara" <?= $canCmd ? '' : 'disabled' ?> autocomplete="off">
      <button type="button" class="btn ghost mic" id="esMic" title="बोलेर · Speak" hidden>🎤</button>
      <button class="btn ok" type="submit" <?= $canCmd ? '' : 'disabled' ?>>पठाउनुहोस् →</button>
    </form>
    <div class="ex">
      <?php foreach (['Surat ko bhada 2300', 'bholi ko bus band', 'Bus 02 ko number GJ05AB1234', 'Rajkot route band', 'Surat Rupaidiha matra', '10 extra seat', 'cancel fine 500', 'SHG-0027 commission 250', 'whatsapp bot band gara', 'PIN pattern login chalu'] as $x): ?>
        <button type="button" onclick="document.getElementById('esCmd').value=this.textContent;document.getElementById('esCmd').focus()"><?= $e($x) ?></button>
      <?php endforeach; ?>
    </div>
    <?php if ($proposal !== null || $opsProp !== []): ?>
      <div class="prop">
        <b>यो बदल्ने हो? · Change this?</b> <small class="muted">(<?= $e($_SESSION['es_prop']['via'] ?? '') ?>)</small>
        <ul>
          <?php foreach ((array) $proposal as $k => $v): ?>
            <li><?= $e($ES_KEYS[$k][0]) ?>: <s><?= $e($show((string) $k, $curVal((string) $k))) ?></s> → <b><?= $e($show((string) $k, (string) $v)) ?></b></li>
          <?php endforeach; ?>
          <?php foreach ($opsProp as $a): foreach (AiOps::preview($a) as [$what, $old, $new]): if ((string) $old === (string) $new) { continue; } ?>
            <li><?= $e($what) ?>: <s><?= $e($old) ?></s> → <b><?= $e($new) ?></b></li>
          <?php endforeach; endforeach; ?>
        </ul>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
          <?= Security::csrfField() ?><input type="hidden" name="act" value="yes">
          <button class="btn ok" type="submit">✅ हो, बदल्नुहोस् · Yes, change</button>
          <a class="btn ghost" href="/admin/easy-settings.php">✖ होइन · No</a>
        </form>
      </div>
    <?php endif; ?>
    <p class="muted">नेपाली, हिन्दी, English — जुन भाषामा पनि। शब्दले बुझिएन भने VPS को AI ले बुझ्छ। सधैं पहिला देखाउँछ, अनि मात्र बदल्छ।</p>
  </div>

  <?php
  /* 6 Nov 2026 (owner: "S Hari AI lai sabai setting ko har ek kuna tha hos"):
     every admin page and every plain setting, searchable in one box. A tap on a
     setting puts it in the AI box above, so it changes the same safe way. */
  $allSets = [];
  try {
      foreach (Database::fetchAll("SELECT skey, svalue, stype, sgroup, label FROM settings WHERE stype IN ('bool','int','float','string') ORDER BY sgroup, skey") as $r) {
          if (AiOps::settingEditable((string) $r['skey'])) { $allSets[] = $r; }
      }
  } catch (Throwable $ex) { $allSets = []; }
  $allPages = admin_nav();
  ?>
  <div class="card">
    <h2>🔎 कुन सेटिङ कहाँ? · Find any page or setting</h2>
    <input id="esFind" placeholder="खोज्नुहोस्: fare, seat, chalani, whatsapp, commission, refund, extra, bus …" style="width:100%;min-height:48px;font-size:16px;padding:8px 12px;border-radius:12px;border:1.5px solid var(--line,#9aa6ba);background:var(--bg,#f6f8fc);color:inherit" autocomplete="off">
    <div id="esFindOut" class="es-find"></div>
    <p class="muted"><?= count($allPages) ?> pages · <?= count($allSets) ?> settings. Setting मा ट्याप गर्दा माथिको AI box मा आउँछ — मान लेखेर पठाउनुहोस्।</p>
  </div>
  <script>
  (function(){
    var pages = <?= json_encode(array_map(static fn(array $i): array => ['l' => (string) $i['label'], 'h' => str_starts_with((string) $i['href'], '/') ? (string) $i['href'] : '/admin/' . $i['href'], 's' => (string) ($i['section'] ?? '')], $allPages), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var sets = <?= json_encode(array_map(static fn(array $r): array => ['k' => (string) $r['skey'], 'l' => (string) ($r['label'] ?? ''), 'g' => (string) $r['sgroup'], 't' => (string) $r['stype'], 'v' => mb_substr((string) $r['svalue'], 0, 40)], $allSets), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var box = document.getElementById('esFind'), out = document.getElementById('esFindOut'), cmd = document.getElementById('esCmd');
    function esc(s){ return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function run(){
      var q = box.value.trim().toLowerCase(); out.innerHTML = '';
      if (q.length < 2) return;
      var words = q.split(/\s+/), html = [], n = 0;
      pages.forEach(function(p){ var hay = (p.l + ' ' + p.s + ' ' + p.h).toLowerCase();
        if (words.every(function(w){ return hay.indexOf(w) >= 0; }) && n < 12) { n++; html.push('<a class="es-hit pg" href="' + esc(p.h) + '">📄 ' + esc(p.l) + ' <small>' + esc(p.s) + '</small></a>'); } });
      var m = 0;
      sets.forEach(function(s){ var hay = (s.k.replace(/_/g, ' ') + ' ' + s.l + ' ' + s.g).toLowerCase();
        if (words.every(function(w){ return hay.indexOf(w) >= 0; }) && m < 25) { m++; html.push('<button type="button" class="es-hit st" data-k="' + esc(s.k) + '" data-t="' + esc(s.t) + '">⚙️ ' + esc(s.l || s.k) + ' <small>' + esc(s.k) + ' = ' + esc(s.t === 'bool' ? (s.v === '1' ? 'ON' : 'OFF') : s.v) + '</small></button>'); } });
      out.innerHTML = html.length ? html.join('') : '<p class="muted">केही भेटिएन · nothing found</p>';
    }
    box.addEventListener('input', run);
    out.addEventListener('click', function(e){
      var b = e.target.closest('button.es-hit'); if (!b || !cmd) return;
      cmd.value = b.getAttribute('data-k') + (b.getAttribute('data-t') === 'bool' ? ' on' : ' ');
      cmd.focus(); window.scrollTo({top: 0, behavior: 'smooth'});
    });
  })();
  </script>

  <div class="card">
    <h2>⚡ एक ट्याप switch · One-tap switches</h2>
    <div class="sw">
      <?php foreach ($ES_KEYS as $k => $def): $v = $curVal($k); ?>
        <form method="post">
          <?= Security::csrfField() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="key" value="<?= $e($k) ?>">
          <?php if ($def[1] === 'bool'): ?>
            <button type="submit" <?= $canEdit ? '' : 'disabled' ?>><span><?= $e($def[0]) ?></span><span class="pill <?= $v === '1' ? 'on' : 'off' ?>"><?= $v === '1' ? 'ON' : 'OFF' ?></span></button>
          <?php else: $next = $v === 'direct' ? 'request' : 'direct'; ?>
            <input type="hidden" name="value" value="<?= $e($next) ?>">
            <button type="submit" <?= $canEdit ? '' : 'disabled' ?>><span><?= $e($def[0]) ?></span><span class="pill on"><?= $e(strtoupper($v)) ?></span></button>
          <?php endif; ?>
        </form>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <h2>🔑 कुनै पनि API key यहाँ paste · Paste any API key here</h2>
    <form method="post" class="cmd" autocomplete="off">
      <?= Security::csrfField() ?><input type="hidden" name="act" value="key">
      <input type="password" name="secret" placeholder="sk-ant-… / AIza… / EAA… — कुन हो app आफैं चिन्छ" <?= $canEdit ? '' : 'disabled' ?> autocomplete="new-password">
      <button class="btn ok" type="submit" <?= $canEdit ? '' : 'disabled' ?>>💾 राख्नुहोस्</button>
    </form>
    <div class="keys">
      <?php foreach ($ES_SECRETS as $k => $def): $has = Settings::getString($k, '') !== ''; ?>
        <span style="<?= $has ? 'border-color:#16A34A' : '' ?>"><?= $e($def[0]) ?> <?= $has ? '✅' : '❌' ?></span>
      <?php endforeach; ?>
    </div>
    <p class="muted">Key कहिल्यै फेरि देखाइँदैन (सुरक्षाका लागि) — ✅ = राखिएको। अरू सबै सेटिङ: <a href="/admin/settings.php">⚙️ All settings →</a> · Local AI: <a href="/admin/ai-local.php">AI (VPS) →</a></p>
  </div>
</div>
<script>
(function(){
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition, b = document.getElementById('esMic'), i = document.getElementById('esCmd');
  if (!SR || !b || !i || i.disabled) return;
  b.hidden = false;
  b.onclick = function(){
    var r = new SR(); r.lang = 'ne-NP'; r.interimResults = false;
    b.textContent = '⏺'; r.onresult = function(ev){ i.value = ev.results[0][0].transcript; };
    r.onend = function(){ b.textContent = '🎤'; if (i.value) i.form.submit(); };
    try { r.start(); } catch (e) { b.textContent = '🎤'; }
  };
})();
</script>
<?php admin_footer();
