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
        return Settings::getBool($k, false) ? '1' : '0';
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
    Settings::set($k, $type === 'bool' ? ($v === '1') : $v, $type, 'general');
    Logger::audit('settings.easy', 'setting', $k, ['value' => $old], ['value' => $v], 'Easy Settings by admin #' . (int) $admin['id']);
};

$flash = null;
$proposal = null;   // [key => new value] waiting for "Yes"
$cmdText = '';
if ($canEdit && Security::isPost()) {
    $act = (string) ($_POST['act'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Page was open too long — try again.'];
    } elseif ($act === 'cmd') {
        $cmdText = Security::clean((string) ($_POST['cmd'] ?? ''), 400);
        if ($cmdText !== '') {
            $prop = $parseWords($cmdText);
            $via = 'words';
            if ($prop === []) { $prop = $parseAi($cmdText); $via = 'AI'; }
            // drop no-ops
            $prop = array_filter($prop, static fn($v, $k): bool => $curVal((string) $k) !== (string) $v, ARRAY_FILTER_USE_BOTH);
            if ($prop === []) {
                $flash = ['bad', '🤔 बुझिएन वा पहिल्यै त्यस्तै छ · Not understood, or already like that. तलको switch बाट पनि गर्न सकिन्छ।'];
            } else {
                $proposal = $prop;
                $_SESSION['es_prop'] = ['p' => $prop, 't' => time(), 'via' => $via, 'cmd' => $cmdText];
            }
        }
    } elseif ($act === 'yes') {
        $p = $_SESSION['es_prop'] ?? null;
        unset($_SESSION['es_prop']);
        if (!is_array($p) || (int) ($p['t'] ?? 0) < time() - 600) {
            $flash = ['bad', 'Time ran out — say it again.'];
        } else {
            $done = [];
            foreach ((array) $p['p'] as $k => $v) {
                if (isset($ES_KEYS[$k])) { $save((string) $k, (string) $v); $done[] = $ES_KEYS[$k][0] . ' → ' . $show((string) $k, (string) $v); }
            }
            $flash = ['ok', '✅ ' . implode(' · ', $done)];
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

admin_header('🤖 Easy Settings · बोलेर सेटिङ', 'easy-settings');
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
</style>
<div class="es">
<?php if ($flash !== null): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= $e($flash[1]) ?></div><?php endif; ?>
<?php if (!$canEdit): ?><div class="flash bad">सेटिङ बदल्ने अनुमति छैन · You may view only; ask the super-admin.</div><?php endif; ?>

  <div class="card">
    <h2>🤖 AI लाई भन्नुहोस् · Tell the AI what to change</h2>
    <form method="post" class="cmd">
      <?= Security::csrfField() ?><input type="hidden" name="act" value="cmd">
      <input id="esCmd" name="cmd" value="<?= $e($cmdText) ?>" placeholder="जस्तै: whatsapp bot band gara · PIN login on karo · agent cancel request matra" <?= $canEdit ? '' : 'disabled' ?> autocomplete="off">
      <button type="button" class="btn ghost mic" id="esMic" title="बोलेर · Speak" hidden>🎤</button>
      <button class="btn ok" type="submit" <?= $canEdit ? '' : 'disabled' ?>>पठाउनुहोस् →</button>
    </form>
    <div class="ex">
      <?php foreach (['whatsapp bot band gara', 'PIN pattern login chalu', 'customer lai whatsapp ticket on', 'agent le cancel garda request matra', 'naya agent join form band', 'facebook messenger off'] as $x): ?>
        <button type="button" onclick="document.getElementById('esCmd').value=this.textContent;document.getElementById('esCmd').focus()"><?= $e($x) ?></button>
      <?php endforeach; ?>
    </div>
    <?php if ($proposal !== null): ?>
      <div class="prop">
        <b>यो बदल्ने हो? · Change this?</b> <small class="muted">(<?= $e($_SESSION['es_prop']['via'] ?? '') ?>)</small>
        <ul>
          <?php foreach ($proposal as $k => $v): ?>
            <li><?= $e($ES_KEYS[$k][0]) ?>: <s><?= $e($show((string) $k, $curVal((string) $k))) ?></s> → <b><?= $e($show((string) $k, (string) $v)) ?></b></li>
          <?php endforeach; ?>
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
