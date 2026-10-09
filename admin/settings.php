<?php
/**
 * admin/settings.php — edit the live configuration held in the settings
 * table. Only superadmin/manager reach this. Unknown keys are ignored by
 * Settings::setMany, so the form can never create stray rows.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

// Writing settings is a privileged action.
$canEdit = Auth::canManageSettings();

$flash = null;

/* ── CEO Photo upload (separate from the settings form) ── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($canEdit) && isset($_FILES['ceo_photo'])) {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $f = $_FILES['ceo_photo'];
        $allowed = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp'];
        $mime = mime_content_type($f['tmp_name'] ?? '') ?: '';
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flash = ['bad', 'Upload failed (error ' . (int)$f['error'] . ').'];
        } elseif (!isset($allowed[$mime])) {
            $flash = ['bad', 'Only JPG, PNG, or WebP images are accepted.'];
        } elseif (($f['size'] ?? 0) > 5 * 1024 * 1024) {
            $flash = ['bad', 'Image too large — maximum 5 MB.'];
        } else {
            // Always save as ceo.jpg for simplicity (browsers cache by filename)
            $dest = dirname(__DIR__) . '/assets/img/ceo.jpg';
            if ($mime === 'image/png' || $mime === 'image/webp') {
                // Convert to JPEG if GD is available, else just copy
                if (function_exists('imagecreatefromstring')) {
                    $src = imagecreatefromstring(file_get_contents($f['tmp_name']));
                    if ($src !== false) {
                        imagejpeg($src, $dest, 92);
                        imagedestroy($src);
                    } else {
                        copy($f['tmp_name'], $dest);
                    }
                } else {
                    copy($f['tmp_name'], $dest);
                }
            } else {
                copy($f['tmp_name'], $dest);
            }
            Logger::audit('settings.ceo_photo', 'admin', (string)($admin['username'] ?? ''), null, null, 'CEO photo updated');
            $flash = ['ok', 'CEO photo updated! It will appear on the splash screen and agent login page. Hard-refresh to see it.'];
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canEdit && !isset($_FILES['ceo_photo'])) {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $pairs = [];
        foreach (($_POST['s'] ?? []) as $key => $val) {
            $val = is_string($val) ? $val : (string) $val;
            /* Secrets are write-only: the form never carries the stored
               value, so an empty field means "keep what is there" and the
               word CLEAR means "remove it". Anything else is a new secret. */
            // 5 Oct 2026: the same test the form uses to hide a secret (it also
            // covers *_private / private_key — push_vapid_private was posted
            // blank by the masked field and wiped on every Settings save).
            if (Settings::isSecretKey((string) $key)) {
                if (trim($val) === '') { continue; }
                if (trim($val) === 'CLEAR') { $val = ''; }
            }
            $pairs[(string) $key] = $val;
        }
        // Checkboxes are absent from POST when unticked, so set every known
        // bool key explicitly from whether its box came through.
        foreach (explode(',', (string) ($_POST['_boolkeys'] ?? '')) as $bk) {
            $bk = trim($bk);
            if ($bk !== '') { $pairs[$bk] = isset($_POST['s'][$bk]) ? '1' : '0'; }
        }
        /* Fares panel (3 Sep 2026): the private-cabin prices live INSIDE the
           cabin_pricing JSON, so the two number fields are merged into it here
           rather than exposing the blob. Flat pricing: online == offline. */
        $fpSingle = isset($_POST['fp_private_single']) ? (float) $_POST['fp_private_single'] : 0.0;
        $fpDouble = isset($_POST['fp_private_double']) ? (float) $_POST['fp_private_double'] : 0.0;
        try {
            /* 4 Sep 2026: the private-cabin prices are merged into cabin_pricing
               AFTER the generic rows are saved, so an edited cabin_pricing
               textarea and the two number fields cannot overwrite each other
               (the number fields win, as they are the curated place). */
            Settings::setMany($pairs);
            if ($fpSingle > 0 || $fpDouble > 0) {
                Settings::flush();
                $cp = Settings::getArray('cabin_pricing', []);
                /* 8 Oct 2026: a cabin_pricing row without a 'private' block used to
                   drop the two prices silently ("saved" but unchanged). Start from
                   the engine's own shape instead. */
                if (!isset($cp['private']) || !is_array($cp['private'])) {
                    $base = Fare::pricing();
                    $cp['private'] = is_array($base['private'] ?? null) ? $base['private'] : [];
                }
                if ($fpSingle > 0) { $cp['private']['single_1pax']['offline'] = $fpSingle; $cp['private']['single_1pax']['online'] = $fpSingle; }
                if ($fpDouble > 0) { $cp['private']['double_2pax']['offline'] = $fpDouble; $cp['private']['double_2pax']['online'] = $fpDouble; }
                Settings::set('cabin_pricing', $cp, 'json', 'pricing', true);
            }
            /* Sharing fare per pickup (8 Oct 2026, owner: "private / sharing anusar
               price badhauna ghataauna milos, setting bata"). Every pickup carries
               its own fare (Routes -> Pickup points) and that fare is what a
               sharing seat costs, so the default above alone changed nothing the
               customer saw. pf[<stop id>] = rupees, blank = the direction default.
               Same rule as routes.php save_stop_fares; one audit row. */
            $pfPosted = is_array($_POST['pf'] ?? null) ? $_POST['pf'] : [];
            if ($pfPosted !== [] && Boarding::hasStopControls()) {
                $pfPlan = []; $pfBefore = []; $pfAfter = [];
                $ids = array_values(array_filter(array_map('intval', array_keys($pfPosted))));
                $rowsPf = $ids === [] ? [] : Database::fetchAll('SELECT id, stop_name, fare FROM route_stops WHERE id IN (' . implode(',', $ids) . ')');
                foreach ($rowsPf as $row) {
                    $raw = trim((string) ($pfPosted[(int) $row['id']] ?? $pfPosted[(string) $row['id']] ?? ''));
                    if ($raw !== '' && (!is_numeric($raw) || (float) $raw < 1 || (float) $raw > 100000)) {
                        throw new InvalidArgumentException('pickup fare for ' . $row['stop_name'] . ' must be 1-100000 or blank');
                    }
                    $new = $raw === '' ? null : round((float) $raw, 2);
                    $old = $row['fare'] !== null ? round((float) $row['fare'], 2) : null;
                    if ($new === $old) { continue; }
                    $pfPlan[(int) $row['id']] = $new;
                    $pfBefore[(string) $row['stop_name'] . ' #' . $row['id']] = $old;
                    $pfAfter[(string) $row['stop_name'] . ' #' . $row['id']]  = $new;
                }
                if ($pfPlan !== []) {
                    Database::transaction(static function () use ($pfPlan): void {
                        foreach ($pfPlan as $sid => $fare) {
                            Database::update('route_stops', ['fare' => $fare], 'id = :id', ['id' => $sid]);
                        }
                    });
                    Boarding::flush();
                    Logger::audit('route_stop.fares', 'settings', 'fares-panel', $pfBefore, $pfAfter,
                        'Pickup fares changed from Settings (' . count($pfPlan) . ' stop' . (count($pfPlan) === 1 ? '' : 's') . ')');
                }
            }
            /* The legacy in-app "Main-point fares" override (main_fares) would win
               over fare_to_* inside Fare::dirFares(); keep it in step when it exists. */
            if (isset($pairs['fare_to_nepal'], $pairs['fare_to_india']) && Settings::get('main_fares', null) !== null
                && (float) $pairs['fare_to_nepal'] > 0 && (float) $pairs['fare_to_india'] > 0) {
                Settings::set('main_fares', ['toNepal' => (float) $pairs['fare_to_nepal'], 'toIndia' => (float) $pairs['fare_to_india']], 'json', 'pricing', false);
            }
            Settings::flush();
            $flash = ['ok', 'Settings saved.'];
        } catch (InvalidArgumentException $e) {
            // A JSON textarea with a typo: nothing was written (the bulk write
            // is one transaction), so say which key and keep the old values.
            Settings::flush();
            $flash = ['bad', 'Not saved — ' . $e->getMessage() . '. Fix the JSON and press Save again.'];
        } catch (Throwable $e) {
            /* 8 Oct 2026: a database refusal used to end in the bare 500 page, so
               the office only saw "it does not save". Say so, keep the log. */
            Settings::flush();
            Logger::error('Settings save failed: ' . $e->getMessage());
            $flash = ['bad', 'Not saved — the database refused one value (' . mb_substr($e->getMessage(), 0, 160) . '). Nothing was changed; please send this message to support.'];
        }
    }
}

$rows = Database::fetchAll('SELECT skey, svalue, stype, sgroup, label, is_public FROM settings ORDER BY sgroup, skey');
$groups = [];
foreach ($rows as $r) { $groups[$r['sgroup'] ?: 'general'][] = $r; }
ksort($groups);

$boolKeys = array_values(array_map(static fn($r) => $r['skey'],
    array_filter($rows, static fn($r) => $r['stype'] === 'bool')));

$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;

/* Oct 2026 redesign: each stored group gets a plain title and one sentence on
   what it controls. Unknown groups fall back to their stored name. */
$groupInfo = [
    'booking'    => ['Booking rules', 'Seat holds, limits and the discount switches every sale uses.'],
    'seat_ai'    => ['🪑 Seat AI', 'Automatic seat recommendation: on/off, rear-to-front or front-first, which deck first, groups kept together. Women-only and cabin gender rules always apply on top and cannot be switched off here.'],
    'buttons'    => ['🔘 Website buttons', 'Switch each customer-site button or pop-up ON or OFF. OFF hides it for every visitor at the next page load; nothing else changes. The office panel is not affected.'],
    'gps'        => ['📍 Near-me pickup & maps', '"Use my location" on the seat page suggests the nearest pickup that has coordinates (Routes → Pickup points). The position is used once in the browser and never stored. The ticket can carry a Google Maps QR of the pickup.'],
    'marketing'  => ['📣 Marketing campaigns', 'Campaign limits and consent. With "opted in only" ON, campaigns reach only numbers recorded as opted in.'],
    'payment'    => ['Payment', 'How customers pay: UPI, eSewa, payment links and pay at boarding.'],
    'pricing'    => ['Fares (advanced)', 'Cabin prices and fare tables. Everyday fares are in "Fares & booking rules" above.'],
    'whatsapp'   => ['WhatsApp messaging', 'How tickets and notices are sent on WhatsApp (Meta Cloud API / Twilio).'],
    'aisensy'    => ['WhatsApp via AiSensy', 'The AiSensy sender used alongside or instead of the main WhatsApp line.'],
    'notify'     => ['Notifications', 'Which messages go out (ticket, reminders, office reports) and to whom.'],
    'company'    => ['Company details', 'Legal name, address, phone numbers and documents printed on tickets and chalani.'],
    'site'       => ['Website', 'What the public website shows: notices, contact numbers, features.'],
    'app'        => ['App', 'Customer app behaviour and switches.'],
    'agent'      => ['Agents', 'Agent sign-up, commission defaults and settlement.'],
    'agents'     => ['Agents', 'Agent sign-up, commission defaults and settlement.'],
    'ai'         => ['AI assistant', 'The Sahayak assistant on the website and in the office.'],
    'security'   => ['Access & security', 'Sign-in protection and who may do what.'],
    'reports'    => ['Reports', 'Scheduled office reports and what they include.'],
    'referral'   => ['Referrals', 'Customer referral rewards.'],
    'loyalty'    => ['Loyalty', 'Customer loyalty tiers and rewards.'],
    'realtime'   => ['Live updates', 'How often screens refresh seat and booking changes.'],
    'automation' => ['Automation', 'Background jobs: reminders, expiries, retries.'],
    'general'    => ['General', 'Everything else.'],
];

/* 8 Oct 2026: settings with a fixed set of answers get a dropdown instead of
   a free text box (a typo there would silently fall back to the default). */
$choiceKeys = [
    'seat_ai_row_order'    => ['rear' => 'Rear first - fill from the back of the bus towards the front', 'front' => 'Front first - front rows before back rows'],
    'seat_ai_default_deck' => ['L' => 'Lower deck first', 'U' => 'Upper deck first'],
    /* 9 Oct 2026 (Pixel / Material You themes): the owner's defaults for a
       visitor who has not picked anything in the website's Appearance sheet.
       The ids must match assets/css/themes.css (tests/theme-palette-test.php). */
    'app_theme_palette'    => ['' => 'S Hari brand colours (navy, royal blue, red)', 'himalaya' => 'Himalaya Blue', 'laligurans' => 'Laligurans Red',
                               'saffron' => 'Saffron', 'tulsi' => 'Tulsi Green', 'phewa' => 'Phewa Teal', 'lotus' => 'Lotus Pink',
                               'lavender' => 'Lavender Dusk', 'marigold' => 'Marigold', 'monsoon' => 'Monsoon Slate', 'everest' => 'Everest Sky',
                               'terracotta' => 'Bhaktapur Brick', 'peacock' => 'Peacock'],
    'app_ui_style'         => ['pixel' => 'Pixel - rounded, soft, Android Pixel style', 'classic' => 'Classic 3D - raised buttons and cards'],
    'app_sound_pack'       => ['pixel' => 'Pixel - new soft chimes and clicks', 'classic' => 'Classic - the original tones'],
    // 9 Oct 2026: WhatsApp routing (includes/waroute.php) and the staff reply language (includes/walocal.php).
    'wa_policy'            => ['meta_first' => 'Meta first - free replies on the Meta number inside the 24 h window, AiSensy utility template only when needed',
                               'aisensy_only' => 'AiSensy only - everything on AiSensy (as before 9 Oct 2026)',
                               'meta_only' => 'Meta only - never AiSensy (when the Meta number can send templates)'],
    'wa_staff_lang'        => ['ne' => 'Nepali', 'hi' => 'Hindi', 'en' => 'English', 'auto' => 'Follow the message'],
    'aisensy_scope'        => ['ticket_only' => 'Ticket only - AiSensy pays for a customer\'s ticket to a cold number, nothing else (saves the wallet)',
                               'all' => 'Everything - OTP, bus updates, staff notes, compose and campaigns may use AiSensy too'],
];

/* ── Settings by CATEGORY (8 Oct 2026, owner: "sabai setting milayera, ticket
   ko ek thau, agent ko ek thau, API key chuttai, WhatsApp chuttai") ──
   456 rows had grown into 30 storage groups — API keys sat under AI, notify,
   marketing and AiSensy; WhatsApp under four groups. The page now files every
   row under one of these tabs by its meaning. Display only: the stored group
   (sgroup) and every key are untouched, so nothing in the code moves. */
$cats = [
    'company'  => ['🏢', 'Company & offices', 'Legal names, addresses, phone numbers, Nepal office, counters and social links printed on tickets and chalani.'],
    'booking'  => ['🪑', 'Booking & seats', 'How seats are held and sold: limits, women-only seats, Seat AI, refunds, Quick Ticket, booking window.'],
    'fares'    => ['💰', 'Fares & offers', 'Private and sharing prices, every pickup\'s fare, discounts, offers, loyalty and referral rewards.'],
    'tickets'  => ['🎫', 'Tickets', 'What the ticket carries and how it is delivered: PDF / PNG, maps QR, border card, travel checklist.'],
    'payments' => ['💳', 'Payments', 'UPI, eSewa, pay at counter, payment proof, payment webhook and the NPR rate.'],
    'agents'   => ['🤝', 'Agents & counter', 'Agent commission, cash limits, settlement, KYC, counter desks, shifts and counter discount / seat caps.'],
    'whatsapp' => ['📲', 'WhatsApp', 'WhatsApp sending (AiSensy / Meta / Twilio), templates, the WhatsApp bot, staff menu and seat-status messages.'],
    'notify'   => ['🔔', 'Notifications & reports', 'SMS, e-mail, push, reminders, ETA alerts, office reports and the help desk.'],
    'marketing'=> ['📣', 'Marketing', 'Campaign limits, consent, AI marketing autopilot, social posting.'],
    'ai'       => ['🤖', 'AI', 'The AI assistant, provider order and models, AI watch, Passive Brain predictions.'],
    'keys'     => ['🔑', 'API keys & connections', 'Every API key, token, password and account id in one place. Keys are write-only: the stored value is never shown.'],
    'website'  => ['🌐', 'Website & app', 'What the public site shows: buttons ON/OFF, near-me pickup, language, notices, maps, sign-in, sounds and motion.'],
    'security' => ['🔒', 'Security & backup', 'Admin sign-in protection, sessions, PIN lock, backups and data checks.'],
    'other'    => ['🧩', 'Other', 'Settings not filed anywhere else.'],
];
$catOf = static function (string $key, string $group): string {
    // non-secret ids / hosts that belong with the keys
    $conn = ['aisensy_project_id', 'whatsapp_phone_id', 'whatsapp_waba_id', 'meta_page_id', 'ig_user_id', 'telegram_channel',
             'google_client_id', 'twilio_account_sid', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_secure', 'push_vapid_public',
             'push_vapid_subject', 'ai_local_url', 'ai_local_endpoint', 'nav_engine_url', 'whatsapp_api_version'];
    if (Settings::isSecretKey($key) || in_array($key, $conn, true)) { return 'keys'; }
    $exact = [
        'tickets'  => ['whatsapp_send_pdf', 'border_card_hours', 'travel_checklist', 'ticket_maps_qr', 'email_attach_pdf', 'email_attach_ics', 'wa_ticket_number', 'border_point_name'],
        'fares'    => ['group_discount_on', 'group_discount_percent', 'group_discount_min_seats', 'online_discount_pct', 'tax_percent', 'booking_fee',
                       'sharing_short_run_on', 'loyalty_tier_discount_on', 'loyalty_trips'],
        'agents'   => ['counter_max_discount_pct', 'counter_max_seats_per_booking', 'agent_daily_summary_enabled', 'agent_notify_enabled', 'offline_queue_on',
                       'agent_commission_percent', 'agent_target_monthly', 'commission_agent_only'],
        'company'  => ['women_helpline', 'company_whatsapp'],
        'security' => ['session_timeout_min', 'automation_log_days', 'data_audit_days_back', 'data_audit_on', 'backup_offsite_email', 'backup_offsite_on'],
        'website'  => ['default_lang', 'maintenance_mode', 'notice_text', 'contact_dial_on', 'google_ads_conversion', 'google_ads_contact_conversion'],
        'whatsapp' => ['notify_rate_minutes', 'wam_on', 'twilio_whatsapp_from', 'twilio_status_callback_url', 'twilio_webhook_url', 'admin_whatsapp', 'whatsapp_notify_admin'],
        'notify'   => ['approval_reminder_minutes', 'low_seat_threshold', 'low_seat_alert_enabled', 'eta_alert_minutes', 'eta_alert_on', 'email_enabled'],
    ];
    foreach ($exact as $c => $list) { if (in_array($key, $list, true)) { return $c; } }
    $prefix = [
        'ai_bot_' => 'ai', 'brain_' => 'ai', 'mk_' => 'marketing', 'social_' => 'marketing', 'advance_offer_' => 'fares',
        'wa_' => 'whatsapp', 'whatsapp_' => 'whatsapp', 'twilio_content_sid' => 'whatsapp', 'seat_status_wa_' => 'whatsapp', 'aisensy_' => 'whatsapp',
        'quick_ticket_' => 'booking', 'counter_' => 'agents', 'agent_' => 'agents', 'nepal_' => 'company', 'company_' => 'company',
        'sms_' => 'notify', 'smtp_' => 'notify', 'push_' => 'notify', 'reminder_' => 'notify', 'trip_' => 'notify', 'notify_' => 'notify',
        'helpdesk_' => 'notify', 'office_report_' => 'notify', 'payment_' => 'payments',
        'risk_' => 'payments', 'fraud_' => 'payments', 'pay_two_step_on' => 'payments', 'ticket_live_code_on' => 'payments', 'staffmsg_' => 'agents', 'loyalty_' => 'fares', 'referral_' => 'fares',
        'btn_' => 'website', 'nearme_' => 'website', 'nav_' => 'website', 'app_' => 'website', 'seat_ai_' => 'booking', 'gdrive_' => 'security',
        'ai_' => 'ai', 'cerebras_' => 'ai', 'grok_' => 'ai', 'gemini_' => 'ai', 'openai_' => 'ai', 'openrouter_' => 'ai', 'telegram_' => 'marketing',
    ];
    foreach ($prefix as $px => $c) { if (str_starts_with($key, $px)) { return $c; } }
    $byGroup = [
        'company' => 'company', 'trust' => 'company', 'booking' => 'booking', 'general' => 'booking', 'seats' => 'booking', 'seat_ai' => 'booking',
        'realtime' => 'booking', 'pricing' => 'fares', 'referral' => 'fares', 'loyalty' => 'fares', 'payment' => 'payments',
        'agent' => 'agents', 'agents' => 'agents', 'whatsapp' => 'whatsapp', 'aisensy' => 'whatsapp', 'notify' => 'notify',
        'reports' => 'notify', 'automation' => 'notify', 'helpdesk' => 'notify', 'marketing' => 'marketing', 'ai' => 'ai',
        'site' => 'website', 'app' => 'website', 'buttons' => 'website', 'gps' => 'website', 'maps' => 'website', 'customers' => 'website',
        'performance' => 'website', 'integrations' => 'keys', 'security' => 'security', 'backup' => 'security',
    ];
    return $byGroup[$group] ?? 'other';
};
/* Sub-heading inside a category: what that slice means THERE (a WhatsApp
   key stored under "ai" is a WhatsApp-bot setting, not an AI-assistant one). */
$subTitle = [
    'keys'     => ['ai' => 'AI provider keys & models', 'aisensy' => 'AiSensy (WhatsApp) keys', 'whatsapp' => 'Meta WhatsApp Cloud API', 'notify' => 'SMS · e-mail · push · Twilio',
                   'marketing' => 'Facebook · Instagram · Telegram', 'automation' => 'Off-site backup', 'maps' => 'Maps / navigation server', 'integrations' => 'Google sign-in', 'payment' => 'Payment webhook'],
    'whatsapp' => ['ai' => 'WhatsApp bot & AI on WhatsApp', 'notify' => 'WhatsApp ticket delivery', 'aisensy' => 'AiSensy sender', 'whatsapp' => 'WhatsApp messaging & staff menu', 'reports' => 'Office WhatsApp alerts'],
    'fares'    => ['booking' => 'Discounts, tax & booking fee', 'pricing' => 'Offers & fare tables'],
    'ai'       => ['booking' => 'Bot answers & Passive Brain'],
    'agents'   => ['booking' => 'Counter caps', 'automation' => 'Agent messages', 'pricing' => 'Commission & targets', 'agent' => 'Agent & counter rules'],
    'tickets'  => ['notify' => 'Ticket delivery', 'automation' => 'Ticket e-mail', 'whatsapp' => 'Ticket on WhatsApp', 'site' => 'Travel checklist', 'booking' => 'Border card', 'gps' => 'Pickup map on the ticket'],
    'notify'   => ['automation' => 'Automation & alerts', 'reports' => 'Office reports', 'helpdesk' => 'Help desk'],
    'company'  => ['site' => 'Company WhatsApp', 'trust' => 'Women helpline'],
    'security' => ['automation' => 'Data checks & off-site backup', 'booking' => 'Sessions'],
];
$byCat = [];
foreach ($groups as $g => $items) {
    foreach ($items as $r) {
        $c = $catOf((string) $r['skey'], (string) $g);
        $byCat[$c][$g][] = $r;
    }
}
/* The keys the two curated panels (Fares, Agent rules) show instead of the
   generic table — the same lists as $fpKeys / $arKeys further down. */
$curatedKeys = ['fare_to_nepal', 'fare_to_india', 'max_seats_per_booking', 'counter_max_seats_per_booking', 'counter_max_discount_pct',
                'agent_commission_mode', 'agent_flat_direct', 'agent_flat_joint', 'agent_commission_percent',
                'agent_advance_max', 'agent_advance_recover_pct', 'agent_payout_min', 'agent_settlement_due_days', 'agent_settle_reminder_days',
                'agent_cash_limit_enforce', 'agent_kyc_required', 'agent_notify_settlement', 'agent_cash_limit_default', 'agent_daily_booking_limit'];
$catCount = [];
foreach ($cats as $c => $_) {
    $n = 0;
    foreach ($byCat[$c] ?? [] as $its) {
        foreach ($its as $r) { if (!in_array((string) $r['skey'], $curatedKeys, true)) { $n++; } }
    }
    $catCount[$c] = $n;
}
// the curated panels: Fares (its fpKeys + the two private-cabin prices) and Agent rules (the rest of fpKeys)
$fareFp = array_intersect($curatedKeys, ['fare_to_nepal', 'fare_to_india', 'max_seats_per_booking', 'counter_max_seats_per_booking', 'counter_max_discount_pct']);
$catCount['fares']  += count($fareFp) + 2;
$catCount['agents'] += count($curatedKeys) - count($fareFp);
$catCount = array_filter($catCount, static fn(int $n, string $c): bool => $n > 0 || in_array($c, ['fares', 'agents'], true), ARRAY_FILTER_USE_BOTH);

admin_header('Settings', 'settings');

if ($flash !== null) { echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>'; }
if (!$canEdit) { echo '<div class="flash bad">Your role can view settings but not change them.</div>'; }

$ceoPhoto = dirname(__DIR__) . '/assets/img/ceo.jpg';
?>
<nav class="sg set-jump" aria-label="Settings categories">
  <p class="set-lede"><b><?= count($rows) ?> settings</b> in <?= count($catCount) ?> categories. Pick a category, or search. Changes apply after <b>Save all settings</b> (one button, saves every category); the page then confirms with “Settings saved.”</p>
  <input type="search" id="setSearch" class="set-search" placeholder="🔍 Search a setting — e.g. fare, upi, whatsapp, key, women, ticket…" autocomplete="off" aria-label="Search settings">
  <div class="set-cats" role="tablist">
    <?php foreach ($catCount as $c => $n): ?>
      <button type="button" class="set-cat-btn" role="tab" data-cat="<?= Security::e($c) ?>">
        <span class="sc-ic" aria-hidden="true"><?= $cats[$c][0] ?></span>
        <span class="sc-t"><?= Security::e($cats[$c][1]) ?> <em><?= (int) $n ?></em></span>
        <small><?= Security::e($cats[$c][2]) ?></small>
      </button>
    <?php endforeach; ?>
  </div>
</nav>

<!-- ── CEO / Brand Photo Upload ── -->
<?php if ($canEdit): ?>
<div class="panel set-in" data-cat="company" style="border-left:4px solid #F07C1F">
  <h2>📸 CEO / Brand Photo</h2>
  <p class="muted" style="font-size:13px;margin-bottom:14px">
    This photo appears on the app splash screen and the agent/admin login page.
    Upload a clear portrait photo (JPG/PNG/WebP, max 5 MB). It is saved as
    <code>assets/img/ceo.jpg</code> — a hard refresh clears the browser cache.
  </p>
  <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
    <?php if (file_exists($ceoPhoto)): ?>
      <img src="/assets/img/ceo.jpg?t=<?= filemtime($ceoPhoto) ?>" alt="CEO"
           style="width:80px;height:80px;border-radius:50%;object-fit:cover;object-position:center top;
                  border:3px solid #F07C1F;box-shadow:0 4px 14px rgba(0,0,0,.2)">
    <?php else: ?>
      <div style="width:80px;height:80px;border-radius:50%;background:#F0F4FF;border:2px dashed #ccc;
                  display:flex;align-items:center;justify-content:center;font-size:28px">👤</div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="file" name="ceo_photo" accept="image/jpeg,image/png,image/webp" required
             style="font-size:14px">
      <button type="submit" class="btn btn-ok btn-sm">Upload Photo</button>
    </form>
  </div>
</div>
<?php endif; ?>

<form method="post">
  <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
  <input type="hidden" name="_boolkeys" value="<?= Security::e(implode(',', $boolKeys)) ?>">

  <?php
  /* -- Fares & booking rules (3 Sep 2026) ------------------------------
     One curated place for the numbers the office actually changes. The
     fare_to_* / max-seats / discount-cap fields post as normal s[key] rows
     (seeded by database/upgrade-2026-09-fares.php); the private-cabin prices
     are merged into the cabin_pricing JSON by the POST handler above. The
     generic key/value loop below skips these keys so nothing is shown twice. */
  $fpKeys   = ['fare_to_nepal', 'fare_to_india', 'max_seats_per_booking', 'counter_max_seats_per_booking', 'counter_max_discount_pct'];
  $fpHave   = static fn(string $key): bool => Settings::get($key, null) !== null;
  $dirFares = Fare::dirFares();
  $cpNow    = Settings::getArray('cabin_pricing', Fare::pricing());
  $fpSingle = (float) ($cpNow['private']['single_1pax']['offline'] ?? 3800);
  $fpDouble = (float) ($cpNow['private']['double_2pax']['offline'] ?? 7600);
  $fpRo     = $canEdit ? '' : 'readonly';
  /* Sharing fare per pickup, grouped by route (8 Oct 2026). Going = the
     boarding stops of a Gujarat -> Nepal route, return = the drops of a
     Nepal -> Gujarat route. */
  $pfGroups = [];
  if (Boarding::hasStopControls()) {
      try {
          foreach (Database::fetchAll('SELECT id, route_code, from_city, to_city FROM routes WHERE is_active = 1 ORDER BY sort_order, id') as $rt) {
              $dirR = Boarding::routeDirection($rt);
              $type = $dirR === 'toNepal' ? 'boarding' : 'drop';
              $stopsR = Database::fetchAll(
                  'SELECT id, stop_name, fare, is_active FROM route_stops WHERE route_id = :r AND stop_type = :t ORDER BY sort_order, id',
                  ['r' => (int) $rt['id'], 't' => $type]
              );
              if ($stopsR !== []) {
                  $pfGroups[] = ['title' => ($dirR === 'toNepal' ? 'Going · जाने' : 'Return · फर्कने') . ' — ' . $rt['from_city'] . ' → ' . $rt['to_city'] . ($rt['route_code'] ? ' (' . $rt['route_code'] . ')' : ''),
                                 'def' => $dirR === 'toNepal' ? $dirFares['toNepal'] : $dirFares['toIndia'], 'stops' => $stopsR];
              }
          }
      } catch (Throwable $e) {
          $pfGroups = [];
      }
  }
  $fpMissing = 'disabled title="Run database/upgrade-2026-09-fares.php once to enable this field"';
  ?>
  <div class="panel set-in" data-cat="fares" id="faresPanel">
    <h2>💰 Fares &amp; booking rules <span class="muted" style="font-weight:400">· भाडा · the numbers that change, in one place</span></h2>
    <div class="fp-grid">
      <label>Default fare Gujarat → Rupaidiha (₹ / person)
        <input type="number" name="s[fare_to_nepal]" min="1" max="100000" step="1" value="<?= (int) round($dirFares['toNepal']) ?>" <?= $fpRo ?> <?= $fpHave('fare_to_nepal') ? '' : $fpMissing ?>>
        <small>toNepal · “jane” · for a pickup with no fare of its own</small></label>
      <label>Default fare Rupaidiha → Gujarat (₹ / person)
        <input type="number" name="s[fare_to_india]" min="1" max="100000" step="1" value="<?= (int) round($dirFares['toIndia']) ?>" <?= $fpRo ?> <?= $fpHave('fare_to_india') ? '' : $fpMissing ?>>
        <small>toIndia · “aune” · for a drop with no fare of its own</small></label>
      <label>Private Single cabin (₹)
        <input type="number" name="fp_private_single" min="1" max="100000" step="1" value="<?= (int) round($fpSingle) ?>" <?= $fpRo ?>>
        <small>1 passenger · online = offline</small></label>
      <label>Private Double cabin (₹)
        <input type="number" name="fp_private_double" min="1" max="100000" step="1" value="<?= (int) round($fpDouble) ?>" <?= $fpRo ?>>
        <small>2 passengers · online = offline</small></label>
      <label>Max seats per booking (customers)
        <input type="number" name="s[max_seats_per_booking]" min="1" max="20" step="1" value="<?= Settings::getInt('max_seats_per_booking', 6) ?>" <?= $fpRo ?> <?= $fpHave('max_seats_per_booking') ? '' : $fpMissing ?>>
        <small>online self-service cap</small></label>
      <label>Max seats per booking (counter / staff)
        <input type="number" name="s[counter_max_seats_per_booking]" min="1" max="40" step="1" value="<?= Settings::getInt('counter_max_seats_per_booking', 20) ?>" <?= $fpRo ?> <?= $fpHave('counter_max_seats_per_booking') ? '' : str_replace('2026-09-fares.php', '2026-09-counter-bulk.sql', $fpMissing) ?>>
        <small>bulk booking — one party under one name at the desk</small></label>
      <label>Counter discount cap (%)
        <input type="number" name="s[counter_max_discount_pct]" min="0" max="100" step="0.5" value="<?= Security::e(rtrim(rtrim(number_format(Settings::getFloat('counter_max_discount_pct', 15.0), 2, '.', ''), '0'), '.')) ?>" <?= $fpRo ?> <?= $fpHave('counter_max_discount_pct') ? '' : $fpMissing ?>>
        <small>agents / admin may discount up to this</small></label>
    </div>
    <?php if ($pfGroups !== []): ?>
    <div class="pf-wrap">
      <b>🪑 Sharing seat fare per pickup (₹ / person)</b>
      <small class="muted">This is the price a sharing seat is sold at from each pickup. Blank = the default fare above. Use the buttons to raise or lower a whole list, then press <b>Save all settings</b>. Existing bookings keep the price they paid.</small>
      <?php foreach ($pfGroups as $gi => $grp): ?>
        <div class="pf-group" data-pf-group="<?= (int) $gi ?>">
          <div class="pf-head"><span><?= Security::e($grp['title']) ?></span>
            <?php if ($canEdit): ?><span class="pf-bump">
              <?php foreach ([-100, -50, 50, 100] as $dlt): ?><button type="button" class="btn ghost" data-pf-bump="<?= $dlt ?>"><?= $dlt > 0 ? '+' : '−' ?>₹<?= abs($dlt) ?> all</button><?php endforeach; ?>
            </span><?php endif; ?>
          </div>
          <div class="pf-grid">
            <?php foreach ($grp['stops'] as $st): ?>
              <label class="<?= (int) $st['is_active'] ? '' : 'pf-off' ?>"><?= Security::e((string) $st['stop_name']) ?><?= (int) $st['is_active'] ? '' : ' <em>(OFF)</em>' ?>
                <input type="number" name="pf[<?= (int) $st['id'] ?>]" min="1" max="100000" step="1" value="<?= $st['fare'] !== null ? (int) round((float) $st['fare']) : '' ?>" placeholder="<?= (int) round((float) $grp['def']) ?> (default)" data-def="<?= (int) round((float) $grp['def']) ?>" <?= $fpRo ?>>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <script>
    document.querySelectorAll('[data-pf-bump]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var d = parseInt(btn.getAttribute('data-pf-bump'), 10) || 0;
        btn.closest('.pf-group').querySelectorAll('input[name^="pf["]').forEach(function (inp) {
          var v = inp.value === '' ? parseInt(inp.getAttribute('data-def'), 10) : parseInt(inp.value, 10);
          if (!isNaN(v)) { inp.value = Math.max(1, v + d); inp.dispatchEvent(new Event('input', { bubbles: true })); }
        });
      });
    });
    </script>
    <?php endif; ?>
    <div style="padding:0 18px 14px"><small class="muted">Changes apply to the next search and the next counter sale. Agent commission (₹200 direct / ₹400 team) is set on the Agent Panel. Save with the button at the bottom.</small></div>
    <?php
    /* Pickup points & fares (owner ask, 1 Oct 2026): each pickup carries its
       own fare + ON/OFF in Admin -> Routes; the two numbers above are only the
       default for a pickup left blank. A link, not a form — this panel sits
       inside the settings form. */
    $pkLoc = Boarding::bootLocations();
    $pkGo  = (array) ($pkLoc['go'] ?? []);
    ?>
    <div class="pk-card">
      <div>
        <b>📍 Pickup points &amp; fares</b>
        <small class="muted">Every pickup's own fare, ON/OFF, order and edit — Rajkot included. Customers, agents, the counter and the AI see only the pickups switched ON, at the fare set there.</small>
        <?php if ($pkGo !== []): ?>
        <div class="pk-chips">
          <?php foreach ($pkGo as $pk): ?>
            <span><?= Security::e((string) $pk['name']) ?> · <b><?= Security::e(inr((float) ($pk['fare'] ?? $dirFares['toNepal']))) ?></b><?= ($pk['fare'] ?? null) === null ? ' <em class="muted">default</em>' : '' ?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php if (Auth::can('routes.view')): ?>
      <a class="btn" href="routes.php#pickups">Manage pickup points →</a>
      <?php endif; ?>
    </div>
  </div>
  <style>.fp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;padding:16px 18px}.fp-grid label{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.3px}.fp-grid input{padding:10px 12px;border:1px solid var(--line);border-radius:9px;font-size:16px;font-weight:700;background:var(--card);color:var(--ink);min-width:0}.fp-grid input:disabled{opacity:.5}.fp-grid small{font-weight:500;text-transform:none;letter-spacing:0}.pk-card{display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin:0 18px 16px;padding:12px 14px;border:1px dashed var(--line);border-radius:10px;background:var(--hover)}.pk-card small{display:block;margin-top:2px}.pk-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.pk-chips span{font-size:12px;padding:3px 8px;border-radius:999px;background:var(--card);border:1px solid var(--line)}.pf-wrap{margin:0 18px 16px;padding:12px 14px;border:1px solid var(--line);border-radius:10px}.pf-wrap>small{display:block;margin:3px 0 8px}.pf-group{margin-top:10px}.pf-head{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;font-weight:700;font-size:13.5px}.pf-bump{display:flex;flex-wrap:wrap;gap:6px}.pf-bump .btn{min-height:36px;padding:4px 10px;font-size:13px}.pf-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;margin-top:8px}.pf-grid label{display:flex;flex-direction:column;gap:4px;font-size:12.5px;font-weight:600;color:var(--ink)}.pf-grid input{padding:9px 10px;border:1px solid var(--line);border-radius:9px;font-size:16px;font-weight:700;background:var(--card);color:var(--ink);min-width:0}.pf-off{opacity:.6}.pf-off em{font-style:normal;color:var(--mut)}</style>


  <?php
  /* -- Agent rules (17 Sep 2026) ------------------------------------------
     The money rules the office asked to own, in one curated place. Every row
     is seeded by database/upgrade-2026-09-agent-rules.sql with TODAY'S
     behaviour as its default, so this panel changes nothing until the office
     edits a value. Bool rows post through the same _boolkeys mechanism as the
     generic loop (unticked = 0); the generic loop skips these keys. */
  $arKeys = ['agent_commission_mode', 'agent_flat_direct', 'agent_flat_joint', 'agent_commission_percent',
             'agent_advance_max', 'agent_advance_recover_pct', 'agent_payout_min', 'agent_settlement_due_days', 'agent_settle_reminder_days',
             'agent_cash_limit_enforce', 'agent_kyc_required', 'agent_notify_settlement',
             'agent_cash_limit_default', 'agent_daily_booking_limit'];
  $fpKeys  = array_merge($fpKeys, $arKeys);
  $arMissing = 'disabled title="Run database/upgrade-2026-09-agent-rules.sql once to enable this field"';
  $arNum = static function (string $key, string $label, string $help, float $def, string $min, string $max, string $step, string $unit = '') use ($fpHave, $fpRo, $arMissing): string {
      $val = Settings::get($key, null);
      $val = $val === null ? $def : (float) $val;
      $shown = rtrim(rtrim(number_format($val, 2, '.', ''), '0'), '.');
      return '<label>' . Security::e($label) . ($unit !== '' ? ' <span class="muted">(' . Security::e($unit) . ')</span>' : '')
           . '<input type="number" name="s[' . Security::e($key) . ']" min="' . $min . '" max="' . $max . '" step="' . $step . '" value="' . Security::e($shown) . '" ' . $fpRo . ' ' . ($fpHave($key) ? '' : $arMissing) . '>'
           . '<small>' . Security::e($help) . '</small></label>';
  };
  $arBool = static function (string $key, string $label, string $help) use ($fpHave, $canEdit, $arMissing): string {
      $on = Settings::getBool($key, false);
      return '<label class="ar-bool"><span>' . Security::e($label) . '</span>'
           . '<span class="row"><label class="switch"><input type="checkbox" name="s[' . Security::e($key) . ']" value="1" ' . ($on ? 'checked' : '') . ' ' . ($canEdit ? '' : 'disabled') . ' ' . ($fpHave($key) ? '' : $arMissing) . '><i></i></label>'
           . '<small>' . Security::e($help) . '</small></span></label>';
  };
  $arMode = (string) Settings::getString('agent_commission_mode', 'flat_per_seat');
  ?>
  <div class="panel set-in" data-cat="agents" id="agentRulesPanel">
    <h2><svg class="a-ic"><use href="#a-handshake"/></svg> Agent rules <span class="muted" style="font-weight:400">· commission, advances, settlement, KYC — the office decides here</span></h2>
    <div class="fp-grid">
      <label>Commission scheme
        <select name="s[agent_commission_mode]" <?= $canEdit ? '' : 'disabled' ?> <?= $fpHave('agent_commission_mode') ? '' : $arMissing ?>>
          <option value="flat_per_seat" <?= $arMode === 'flat_per_seat' ? 'selected' : '' ?>>Flat ₹ per passenger (direct / team tiers)</option>
          <option value="percent" <?= $arMode === 'percent' ? 'selected' : '' ?>>Percent of ticket value</option>
        </select>
        <small>Switching to “percent” re-rates EVERY agent’s next sale at the company % (or their own override).</small></label>
      <?= $arNum('agent_flat_direct', 'Flat commission — direct agent', 'per passenger, flat scheme', 200, '0', '10000', '1', '₹') ?>
      <?= $arNum('agent_flat_joint', 'Flat commission — team / organisation', 'per passenger, flat scheme', 400, '0', '10000', '1', '₹') ?>
      <?= $arNum('agent_commission_percent', 'Company commission %', 'percent scheme default; a per-agent override on the Agent Panel wins', 5, '0', '100', '0.5', '%') ?>
      <?= $arNum('agent_advance_max', 'Advance / loan cap per agent', '0 = no cap. Refuses an advance that would take the agent above this', 0, '0', '10000000', '1', '₹') ?>
      <?= $arNum('agent_advance_recover_pct', 'Recovery share of each payout', 'Recorded as the agreed plan. The ledger always nets an advance from commission in full', 100, '0', '100', '1', '%') ?>
      <?= $arNum('agent_payout_min', 'Minimum payout', '0 = any amount. Paying the whole balance is always allowed', 0, '0', '10000000', '1', '₹') ?>
      <?= $arNum('agent_settlement_due_days', 'Settlement due in', '0 = never flag. Cash held longer shows “settlement overdue”', 0, '0', '365', '1', 'days') ?>
      <?= $arNum('agent_settle_reminder_days', 'WhatsApp reminder after', '0 = off. Once cash is overdue this many days, the daily cron (cron/wa-reminders.php) sends the agent one payment reminder a day', 0, '0', '365', '1', 'days') ?>
      <?= $arNum('agent_cash_limit_default', 'Default cash-in-hand limit', 'per-agent limit on the Agent Panel overrides this; 0 = no limit', 0, '0', '10000000', '1', '₹') ?>
      <?= $arNum('agent_daily_booking_limit', 'Default daily booking limit', 'bookings per agent per day; 0 = no limit', 0, '0', '1000', '1', '') ?>
    </div>
    <div class="fp-grid" style="padding-top:0">
      <?= $arBool('agent_cash_limit_enforce', 'Block selling above the cash limit', 'off = the limit only warns (today)') ?>
      <?= $arBool('agent_kyc_required', 'KYC must be verified before selling', 'off = KYC is informational; verify every agent first') ?>
      <?= $arBool('agent_notify_settlement', 'WhatsApp the agent on payout / cash handover', 'settlement receipt goes to the agent\'s number') ?>
    </div>
    <div style="padding:0 18px 14px"><small class="muted">Per-agent rates, overrides, salary, deposit and route permissions stay on the Agent Panel. Nothing here touches past ledger rows — rules apply from the next sale, payout or advance.</small></div>
  </div>
  <style>.ar-bool{display:flex;flex-direction:column;gap:6px}.ar-bool>span:first-child{font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--mut)}.ar-bool .row{gap:12px;align-items:center}.ar-bool small{font-weight:400;text-transform:none;letter-spacing:0;color:var(--mut)}</style>

  <?php
  /* 17 Sep 2026 — one-click WhatsApp (admin/api/wa-send.php): what the office
     must know under the keys that decide whether a message actually goes out.
     Help text only — the keys come from database/upgrade-2026-09-wa-templates.sql. */
  $waTplHelp = 'A business-initiated message (the office writes first) only reaches WhatsApp through an approved Twilio Content template — one per purpose, its HX… SID pasted here. Blank = free text, which WhatsApp delivers only inside the 24-hour window after this number last wrote to us; outside it the button hands the message to the staff phone (wa.me) instead of burning a send that would fail.';
  $waHelp = [
      'wa_admin_tools_enabled'              => 'Shows the "Send on WhatsApp" buttons on the agent, booking, manifest and dashboard pages. Every press previews first, then sends through the WhatsApp API (Twilio / Cloud API) or opens wa.me on the staff phone; each attempt is logged under Messages with its purpose.',
      'twilio_content_sid_agent_statement'  => $waTplHelp . ' Used for agent statements, booking history, commission, advance / loan balance and the daily / monthly summaries.',
      'twilio_content_sid_payment_reminder' => $waTplHelp . ' Used for agent and customer payment reminders and the outstanding-balance message.',
      'twilio_content_sid_settlement'       => $waTplHelp . ' Used for settlement receipts and cash-settlement details.',
      'twilio_content_sid_booking_detail'   => $waTplHelp . ' Used for booking status, passenger / seat details and the bus chalan picture.',
      'wa_statement_link_days'              => 'Statement PDFs and chalan pictures go out as signed download links, not attachments. A link stops opening after this many days — the message tells the recipient so.',
      // 5 Oct 2026: Google Ads conversion tracking (index.php boot + ShgAds in 05-router.js).
      'google_ads_conversion'               => 'Google Ads → Goals → Conversions → New → Website → "Purchase" → "Use Google tag" → copy the send_to value, e.g. AW-123456789/AbCdEfGhIjk. When set, every online booking is reported to Google Ads (value in ₹), so the campaign learns which clicks buy tickets. Blank = Google\'s tag is not loaded at all.',
      'google_ads_contact_conversion'       => 'Optional second conversion (category "Contact") for a tap on a phone number or WhatsApp button, same AW-…/label format. Leave blank if you only want bookings counted.',
      // 9 Oct 2026: Pixel / Material You themes (assets/css/themes.css, the "🎨 रूप · Appearance" sheet).
      'app_theme_palette'                   => 'The colour theme a visitor sees until they choose their own (phone menu → 🎨 रूप · Appearance). Every theme works in light, dark and AMOLED, and never changes what a seat, fare or status colour means. A visitor\'s own choice always wins.',
      'app_ui_style'                        => 'Pixel = rounded cards, pill buttons, soft shadows and spring motion (Android Pixel / Material You). Classic 3D = the raised, deep buttons and cards. Visitors can switch in the Appearance sheet.',
      'app_sound_pack'                      => 'The sound style for taps, seats, switches and the ticket chime. All sounds are generated in the phone (no download) and follow the phone\'s silent mode. Visitors can turn sound off or switch the style.',
      'app_motion_on'                       => 'On = smooth, springy movement. Off = a calm screen for every visitor who has not chosen otherwise (phones set to "reduce motion" are always calm).',
  ];
  ?>
  <?php foreach ($cats as $cat => $catInfo): foreach ($byCat[$cat] ?? [] as $group => $items):
      $visible = array_values(array_filter($items, static fn($r) => !in_array((string) $r['skey'], $fpKeys, true)));
      if ($visible === []) { continue; } ?>
    <div class="panel set-group set-in" data-cat="<?= Security::e($cat) ?>" id="grp-<?= Security::e($cat . '-' . (string) $group) ?>">
      <?php $subT = $subTitle[$cat][$group] ?? null; ?>
      <h2><?= $catInfo[0] ?> <?= Security::e($subT ?? ($groupInfo[$group][0] ?? ucfirst(str_replace(['-', '_'], ' ', (string) $group)))) ?> <small class="muted set-cnt"><?= count($visible) ?></small></h2>
      <?php if ($subT === null): ?><p class="muted set-why"><?= Security::e($groupInfo[$group][1] ?? 'Settings stored under "' . $group . '".') ?></p><?php endif; ?>
      <table class="set-table no-card">
        <?php foreach ($items as $r):
          $key = (string) $r['skey'];
          if (in_array($key, $fpKeys, true)) { continue; }   // shown in the Fares panel above
          $label = $r['label'] ?: ucwords(str_replace('_', ' ', $key));
          $val = (string) ($r['svalue'] ?? '');
          $isJson = $r['stype'] === 'json';
          $isSecret = Settings::isSecretKey($key);   // one rule, shared with the audit mask
        ?>
        <tr class="set-row" data-q="<?= Security::e(mb_strtolower($key . ' ' . $label)) ?>">
          <th style="width:34%"><?= Security::e($label) ?>
            <div class="muted mono" style="font-weight:400;font-size:11px"><?= Security::e($key) ?></div>
          </th>
          <td>
            <?php if ($r['stype'] === 'bool'): ?>
              <label class="set-switch">
                <input type="checkbox" role="switch" name="s[<?= Security::e($key) ?>]" value="1" <?= $val === '1' ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?> data-saved="<?= $val === '1' ? '1' : '0' ?>">
                <span class="set-knob" aria-hidden="true"></span>
                <span class="set-state"><?= $val === '1' ? 'On' : 'Off' ?></span>
              </label>
            <?php elseif (isset($choiceKeys[$key])): ?>
              <select name="s[<?= Security::e($key) ?>]" style="padding:9px 11px;border:1px solid var(--line);border-radius:8px;min-height:44px" <?= $canEdit ? '' : 'disabled' ?>>
                <?php foreach ($choiceKeys[$key] as $cv => $cl): ?><option value="<?= Security::e((string) $cv) ?>" <?= $val === (string) $cv ? 'selected' : '' ?>><?= Security::e($cl) ?></option><?php endforeach; ?>
              </select>
            <?php elseif ($isJson): ?>
              <textarea name="s[<?= Security::e($key) ?>]" rows="2" style="width:100%;font-family:ui-monospace,monospace;font-size:12px;padding:8px;border:1px solid var(--line);border-radius:8px" <?= $canEdit ? '' : 'readonly' ?>><?= Security::e($val) ?></textarea>
            <?php elseif ($isSecret): ?>
              <?php /* Write-only: the stored value is never sent to the browser.
                       It used to be printed into value="…" for every dashboard.view
                       role — API keys and the WhatsApp token, readable in the page
                       source by support staff. */ ?>
              <input type="password" autocomplete="new-password" name="s[<?= Security::e($key) ?>]" value="" placeholder="<?= $val !== '' ? '•••••••• set — leave blank to keep' : 'not set' ?>" style="width:100%;max-width:420px;padding:9px 11px;border:1px solid var(--line);border-radius:8px" <?= $canEdit ? '' : 'readonly' ?>>
              <small class="muted" style="display:block;margin-top:4px;font-size:11.5px"><?= $val !== '' ? 'Set. Type a new value to replace it, or CLEAR to remove it.' : 'Not set.' ?></small>
            <?php else: ?>
              <input type="text" name="s[<?= Security::e($key) ?>]" value="<?= Security::e($val) ?>" style="width:100%;max-width:420px;padding:9px 11px;border:1px solid var(--line);border-radius:8px" <?= $canEdit ? '' : 'readonly' ?>>
            <?php endif; ?>
            <?php if (isset($waHelp[$key])): ?><small class="muted" style="display:block;margin-top:6px;max-width:560px;line-height:1.45"><?= Security::e($waHelp[$key]) ?></small><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endforeach; endforeach; ?>

  <?php if ($canEdit): ?>
    <div class="toolbar save-sticky"><span class="set-dirty" id="setDirty" hidden>You have unsaved changes</span><button class="btn" type="submit">Save all settings</button></div>
  <?php endif; ?>
</form>
<style>
.set-search { width: 100%; min-height: 46px; padding: 10px 14px; border: 1.5px solid var(--line-control); border-radius: 12px; background: var(--surface); color: var(--ink); font-size: 16px; }
.set-cats { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 10px; }
.set-cat-btn { display: grid; grid-template-columns: 34px 1fr; gap: 2px 10px; align-items: center; text-align: left; padding: 12px; min-height: 64px; border-radius: 14px; border: 1.5px solid var(--line); background: var(--surface); color: var(--ink); cursor: pointer; }
.set-cat-btn .sc-ic { grid-row: 1 / span 2; font-size: 24px; line-height: 1; text-align: center; }
.set-cat-btn .sc-t { font-weight: 800; font-size: 14.5px; }
.set-cat-btn .sc-t em { font-style: normal; font-size: 12px; font-weight: 700; padding: 1px 8px; margin-left: 4px; border-radius: 999px; background: var(--surface-sunken); color: var(--muted); }
.set-cat-btn small { font-size: 12px; line-height: 1.35; color: var(--muted); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.set-cat-btn.on { border-color: var(--royal); box-shadow: 0 0 0 2px var(--royal) inset; }
.set-cat-btn.on .sc-t em { background: var(--royal); color: var(--on-royal); }
.set-cnt { font-size: 12px; font-weight: 700; }
.set-hide { display: none !important; }
@media (max-width: 640px) {
  .set-cats { grid-template-columns: 1fr 1fr; gap: 8px; }
  .set-cat-btn { grid-template-columns: 26px 1fr; padding: 9px 10px; min-height: 52px; }
  .set-cat-btn .sc-ic { font-size: 20px; }
  .set-cat-btn .sc-t { font-size: 13px; }
  .set-cat-btn small { display: none; }
}
.set-jump { margin: -6px 0 14px; display: grid; gap: 8px; }
.set-lede { margin: 0; color: var(--muted); font-size: 13.5px; }
.set-jump .sg-chip { text-decoration: none; }
.set-why { margin: -4px 0 10px; font-size: 13px; }
.set-switch { display: inline-flex; align-items: center; gap: 10px; min-height: 44px; cursor: pointer; position: relative; }
.set-switch input { position: absolute; opacity: 0; width: 52px; height: 32px; margin: 0; cursor: pointer; }
.set-knob { width: 52px; height: 32px; border-radius: 16px; background: var(--surface-sunken); border: 1.5px solid var(--line-control); position: relative; flex: none; transition: background-color .15s; }
.set-knob::after { content: ""; position: absolute; top: 3px; left: 3px; width: 23px; height: 23px; border-radius: 50%; background: var(--line-control); transition: transform .15s; }
.set-switch input:checked + .set-knob { background: var(--royal); border-color: var(--royal); }
.set-switch input:checked + .set-knob::after { transform: translateX(20px); background: var(--on-royal); }
.set-switch input:focus-visible + .set-knob { outline: 3px solid var(--focus); outline-offset: 2px; }
.set-state { font-weight: 700; font-size: 13.5px; }
.set-state.is-unsaved { color: var(--warning); }
.set-dirty { margin-right: auto; color: var(--warning); font-weight: 700; font-size: 13.5px; }
@media (max-width: 820px) {
  .set-table, .set-table tbody, .set-table tr, .set-table th, .set-table td { display: block; width: 100% !important; }
  .set-table tr { padding: 10px 0; border-bottom: 1px solid var(--line); }
  .set-table th { border: 0 !important; padding: 0 0 6px !important; background: none !important; text-align: left; }
  /* 8 Oct 2026 (D9-03): the panel-wide th rule (nowrap, 11.5px capitals) cut
     14 of 40 Company labels off at 390px - on a phone a label wraps and reads
     as a sentence, with its setting key under it */
  .set-table th { white-space: normal !important; text-transform: none; letter-spacing: 0; font-size: 13.5px; line-height: 1.35; color: var(--ink); overflow-wrap: anywhere; }
  .set-table th .mono { overflow-wrap: anywhere; color: var(--muted); }
  .set-table td { border: 0 !important; padding: 0 !important; }
  .set-table input[type=text], .set-table input[type=password], .set-table textarea { max-width: 100% !important; }
  .save-sticky { display: flex; gap: 10px; align-items: center; justify-content: flex-end; }
}
</style>
<script>
(function () {
  var dirty = document.getElementById('setDirty'), n = 0;
  document.querySelectorAll('.set-switch input').forEach(function (cb) {
    cb.addEventListener('change', function () {
      var st = cb.parentNode.querySelector('.set-state'), changed = (cb.checked ? '1' : '0') !== cb.getAttribute('data-saved');
      st.textContent = (cb.checked ? 'On' : 'Off') + (changed ? ' · not saved yet' : '');
      st.classList.toggle('is-unsaved', changed);
      mark();
    });
  });
  var form = dirty && dirty.closest('form');
  if (form) form.addEventListener('input', function (e) { if (e.target && e.target.id !== 'setSearch') mark(); });
  function mark() { if (dirty) dirty.hidden = false; }

  /* Categories (8 Oct 2026): one category at a time; the choice is kept in the
     address (#cat=…) so Save returns to the same tab. Hidden panels still post,
     so Save all settings saves every category. Search looks across all. */
  var btns = Array.prototype.slice.call(document.querySelectorAll('.set-cat-btn'));
  // queried each time: the test panels come AFTER this script in the page
  var allPanels = function () { return Array.prototype.slice.call(document.querySelectorAll('.set-in')); };
  var search = document.getElementById('setSearch');
  function show(cat) {
    btns.forEach(function (b) { var on = b.getAttribute('data-cat') === cat; b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
    allPanels().forEach(function (p) { p.classList.toggle('set-hide', p.getAttribute('data-cat') !== cat); });
    document.querySelectorAll('.set-row').forEach(function (r) { r.classList.remove('set-hide'); });
    try { history.replaceState(null, '', '#cat=' + cat); } catch (e) {}
  }
  function find(q) {
    q = q.trim().toLowerCase();
    if (!q) { var c = (location.hash.match(/cat=([a-z]+)/) || [])[1] || (btns[0] && btns[0].getAttribute('data-cat')); show(c); return; }
    btns.forEach(function (b) { b.classList.remove('on'); });
    allPanels().forEach(function (p) {
      var rows = p.querySelectorAll('.set-row'), any = false;
      if (rows.length) {
        rows.forEach(function (r) { var hit = r.getAttribute('data-q').indexOf(q) >= 0; r.classList.toggle('set-hide', !hit); if (hit) any = true; });
      } else {
        any = p.textContent.toLowerCase().indexOf(q) >= 0;
      }
      p.classList.toggle('set-hide', !any);
    });
  }
  btns.forEach(function (b) { b.addEventListener('click', function () {
    if (search) search.value = '';
    show(b.getAttribute('data-cat'));
    // on a phone the cards fill the screen: jump to the first settings panel of the tab
    if (window.innerWidth <= 820) {
      var firstP = allPanels().filter(function (p) { return !p.classList.contains('set-hide'); })[0];
      if (firstP) firstP.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }); });
  if (search) search.addEventListener('input', function () { find(search.value); });
  var start = (location.hash.match(/cat=([a-z]+)/) || [])[1];
  var legacy = { faresPanel: 'fares', agentRulesPanel: 'agents', waTestPanel: 'whatsapp', 'grp-site': 'website' }[location.hash.replace('#', '')];
  if (form) form.addEventListener('submit', function () { form.action = location.pathname + location.hash; });   // come back to the same tab after Save
  var first = function () { show(start || legacy || (btns[0] && btns[0].getAttribute('data-cat'))); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', first); else first();
})();
</script>

<?php if ($canEdit):
  $testPhone = Settings::officePhone();
?>
<div class="panel set-in" data-cat="whatsapp" id="waTestPanel">
  <h2>📲 Test WhatsApp ticket delivery</h2>
  <?php
    // Notify is not bootstrap-loaded (events.php pulls it lazily), so this
    // page asks for it before reading the sender-breaker state.
    require_once INCLUDE_PATH . '/notify.php';
    // Only the Twilio driver can be paused by the breaker — a leftover row must
    // not accuse a Cloud API / click-to-chat setup of refusing anything.
    $waPause = Settings::getString('whatsapp_driver', 'click_to_chat') === 'twilio' ? Notify::twilioPause() : null;
  if ($waPause !== null): ?>
  <p style="margin:-4px 0 14px;padding:11px 13px;border-radius:9px;background:#FFF4E5;color:#8a4b00;border:1px solid #ffcc80;font-size:13.5px;line-height:1.55">
    ⏸️ <b>Automatic WhatsApp is paused.</b> Twilio refused the account
    <?= $waPause['message'] !== '' ? '(' . Security::e($waPause['message']) . ')' : '(code ' . (int) $waPause['code'] . ')' ?>,
    so tickets are going out as one-tap click-to-chat links instead of costing every sale a failed API call.
    It retries by itself after <b><?= Security::e(date('H:i', $waPause['until'])) ?></b> — or send a test below:
    a successful test resumes automatic delivery immediately.
  </p>
  <?php endif; ?>
  <p class="muted" style="margin:-4px 0 14px;max-width:640px">
    Sends a real WhatsApp to the number below through your current settings, so you can confirm
    tickets will reach customers. Set <span class="mono">whatsapp_driver</span> = <b>twilio</b> and fill
    the Twilio SID / Auth Token / sender above (then <b>Save</b>) before testing.
    On the Twilio <b>sandbox</b>, the test phone must first send the join code to your sandbox number once.
  </p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="tel" id="waTestPhone" placeholder="+9198XXXXXXXX or +97798XXXXXXXX"
           value="<?= Security::e($testPhone) ?>"
           style="flex:1;min-width:210px;padding:9px 11px;border:1px solid var(--line);border-radius:8px">
    <select id="waTestCountry" style="padding:9px;border:1px solid var(--line);border-radius:8px">
      <option value="">Country: Auto</option>
      <option value="in">🇮🇳 India (+91)</option>
      <option value="np">🇳🇵 Nepal (+977)</option>
    </select>
    <button class="btn" type="button" id="waTestBtn">Send test</button>
  </div>
  <div id="waTestResult" style="margin-top:12px;display:none;padding:11px 13px;border-radius:9px;font-size:13.5px;line-height:1.5"></div>
</div>
<script>
(function(){
  var btn=document.getElementById('waTestBtn'), out=document.getElementById('waTestResult');
  if(!btn) return;
  function show(kind,msg){
    out.style.display='block';
    out.style.background = kind==='ok' ? 'var(--success-soft)' : 'var(--warning-soft)';
    out.style.color      = 'var(--ink)';
    out.style.border     = '1px solid ' + (kind==='ok' ? 'var(--success)' : 'var(--warning)');
    out.textContent      = (kind==='ok' ? '✅ ' : '⚠️ ') + msg;
  }
  btn.onclick=function(){
    var phone=(document.getElementById('waTestPhone').value||'').trim();
    if(!phone){ show('bad','Enter a phone number first.'); return; }
    btn.disabled=true; var old=btn.textContent; btn.textContent='Sending…';
    var fd=new FormData();
    fd.append('phone', phone);
    fd.append('country', document.getElementById('waTestCountry').value);
    fd.append('<?= $k ?>', '<?= $csrf ?>');
    fetch('notify-test.php', { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){ show(j && j.ok ? 'ok' : 'bad', (j && j.detail) || (j && j.ok ? 'Sent.' : 'Send failed.')); })
      .catch(function(){ show('bad','Could not reach the server — check your connection and try again.'); })
      .finally(function(){ btn.disabled=false; btn.textContent=old; });
  };
})();
</script>

<div class="panel set-in" data-cat="notify" id="smsTestPanel">
  <h2>📩 Test SMS ticket delivery</h2>
  <p class="muted" style="margin:-4px 0 14px;max-width:640px">
    Sends a real SMS through Twilio. Tick <span class="mono">sms_enabled</span>, fill the
    Twilio <b>SID / Auth Token</b> (same as WhatsApp) and <span class="mono">twilio_sms_from</span>
    (an <b>SMS-capable</b> Twilio number — not the WhatsApp sender) above, then <b>Save</b> before testing.
    Every send — success or failure — is recorded in the message log.
  </p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="tel" id="smsTestPhone" placeholder="+9198XXXXXXXX or +97798XXXXXXXX"
           value="<?= Security::e($testPhone) ?>"
           style="flex:1;min-width:210px;padding:9px 11px;border:1px solid var(--line);border-radius:8px">
    <select id="smsTestCountry" style="padding:9px;border:1px solid var(--line);border-radius:8px">
      <option value="">Country: Auto</option>
      <option value="in">🇮🇳 India (+91)</option>
      <option value="np">🇳🇵 Nepal (+977)</option>
    </select>
    <button class="btn" type="button" id="smsTestBtn">Send test SMS</button>
  </div>
  <div id="smsTestResult" style="margin-top:12px;display:none;padding:11px 13px;border-radius:9px;font-size:13.5px;line-height:1.5"></div>
</div>
<script>
(function(){
  var btn=document.getElementById('smsTestBtn'), out=document.getElementById('smsTestResult');
  if(!btn) return;
  function show(kind,msg){
    out.style.display='block';
    out.style.background = kind==='ok' ? 'var(--success-soft)' : 'var(--warning-soft)';
    out.style.color      = 'var(--ink)';
    out.style.border     = '1px solid ' + (kind==='ok' ? 'var(--success)' : 'var(--warning)');
    out.textContent      = (kind==='ok' ? '✅ ' : '⚠️ ') + msg;
  }
  btn.onclick=function(){
    var phone=(document.getElementById('smsTestPhone').value||'').trim();
    if(!phone){ show('bad','Enter a phone number first.'); return; }
    btn.disabled=true; var old=btn.textContent; btn.textContent='Sending…';
    var fd=new FormData();
    fd.append('phone', phone);
    fd.append('country', document.getElementById('smsTestCountry').value);
    fd.append('channel', 'sms');
    fd.append('<?= $k ?>', '<?= $csrf ?>');
    fetch('notify-test.php', { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){ show(j && j.ok ? 'ok' : 'bad', (j && j.detail) || (j && j.ok ? 'Sent.' : 'Send failed.')); })
      .catch(function(){ show('bad','Could not reach the server — check your connection and try again.'); })
      .finally(function(){ btn.disabled=false; btn.textContent=old; });
  };
})();
</script>

<div class="panel set-in" data-cat="notify" id="emailTestPanel">
  <h2>📧 Test email (Gmail SMTP)</h2>
  <p class="muted" style="margin:-4px 0 14px;max-width:640px">
    Sends a real email through your SMTP settings above. For Gmail set
    <span class="mono">smtp_host</span> = smtp.gmail.com, <span class="mono">smtp_port</span> = 587,
    <span class="mono">smtp_secure</span> = tls, <span class="mono">smtp_user</span> = your Gmail address and
    <span class="mono">smtp_pass</span> = a 16-character <b>App Password</b>
    (Google Account → Security → 2-Step Verification → App Passwords — <b>not</b> the normal password),
    then <b>Save</b> before testing.
  </p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="email" id="emailTestTo" placeholder="you@example.com"
           style="flex:1;min-width:210px;padding:9px 11px;border:1px solid var(--line);border-radius:8px">
    <button class="btn" type="button" id="emailTestBtn">Send test email</button>
  </div>
  <div id="emailTestResult" style="margin-top:12px;display:none;padding:11px 13px;border-radius:9px;font-size:13.5px;line-height:1.5"></div>
</div>
<script>
(function(){
  var btn=document.getElementById('emailTestBtn'), out=document.getElementById('emailTestResult');
  if(!btn) return;
  function show(kind,msg){
    out.style.display='block';
    out.style.background = kind==='ok' ? 'var(--success-soft)' : 'var(--warning-soft)';
    out.style.color      = 'var(--ink)';
    out.style.border     = '1px solid ' + (kind==='ok' ? 'var(--success)' : 'var(--warning)');
    out.textContent      = (kind==='ok' ? '✅ ' : '⚠️ ') + msg;
  }
  btn.onclick=function(){
    var to=(document.getElementById('emailTestTo').value||'').trim();
    if(!to){ show('bad','Enter an email address first.'); return; }
    btn.disabled=true; var old=btn.textContent; btn.textContent='Sending…';
    var fd=new FormData();
    fd.append('email', to);
    fd.append('channel', 'email');
    fd.append('<?= $k ?>', '<?= $csrf ?>');
    fetch('notify-test.php', { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){ show(j && j.ok ? 'ok' : 'bad', (j && j.detail) || (j && j.ok ? 'Sent.' : 'Send failed.')); })
      .catch(function(){ show('bad','Could not reach the server — check your connection and try again.'); })
      .finally(function(){ btn.disabled=false; btn.textContent=old; });
  };
})();
</script>
<?php endif; ?>
<?php
admin_footer();
