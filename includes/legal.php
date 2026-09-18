<?php
/**
 * =====================================================================
 *  includes/legal.php — server-rendered legal pages.
 *
 *    /privacy-policy     Privacy Policy
 *    /terms-of-service   Terms of Service (summary + link to the full T&C at /#/terms)
 *    /data-deletion      User data deletion instructions
 *
 *  These must be real HTML at a plain URL: Meta's app review (WhatsApp
 *  Cloud API app "SHG Messaging API") and crawlers do not run the SPA, and
 *  before 18 Sep 2026 all three URLs answered with the homepage.
 *
 *  Company facts come from the settings table so the pages never drift
 *  from the ticket / invoice header. Everything described here is what the
 *  code actually does — keep it that way when the product changes.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

final class LegalPages
{
    public const UPDATED = '18 September 2026';

    /** Route path => page key. */
    public const ROUTES = [
        '/privacy-policy'   => 'privacy',
        '/privacy'          => 'privacy',
        '/terms-of-service' => 'terms',
        '/data-deletion'    => 'deletion',
    ];

    public static function render(string $page): never
    {
        $c = self::company();
        [$title, $body] = match ($page) {
            'terms'    => ['Terms of Service', self::terms($c)],
            'deletion' => ['User Data Deletion', self::deletion($c)],
            default    => ['Privacy Policy', self::privacy($c)],
        };

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo self::layout($title, $body, $c, $page);
        exit;
    }

    /** @return array<string, string> escaped company facts */
    private static function company(): array
    {
        $e = static fn (string $v): string => Security::e($v);
        $wa = preg_replace('/\D/', '', Settings::getString('company_whatsapp', '')) ?: '919104801507';
        return [
            'name'    => $e(Settings::getString('company_name', 'S Hari Global Pvt Ltd')),
            'legal'   => $e(Settings::getString('company_legal', 'S Hari Global Private Limited')),
            'address' => $e(Settings::getString('company_address', '')),
            'cin'     => $e(Settings::getString('company_cin', '')),
            'phone'   => $e(Settings::officePhone()),
            'email'   => $e(Settings::getString('company_email', '')),
            'wa'      => $e($wa),
            'waShow'  => $e('+' . substr($wa, 0, strlen($wa) - 10) . ' ' . substr($wa, -10, 5) . ' ' . substr($wa, -5)),
            'site'    => 'https://www.shreehariglobal.in',
        ];
    }

    /* ----------------------------------------------------------------- */

    private static function privacy(array $c): string
    {
        return <<<HTML
<p class="lead">This policy explains what personal data {$c['legal']} ("we", "us") collects when you use
<a href="{$c['site']}/">shreehariglobal.in</a>, book a bus ticket with us (online, at our counters or through an
authorised agent), or message us on WhatsApp, and how we use, share, keep and delete it.</p>

<h2>1. Who we are</h2>
<p>{$c['legal']} (CIN {$c['cin']}), {$c['address']}. We run the India–Nepal passenger bus service between Gujarat and
Rupaidiha / Nepalgunj, together with our partner company in Nepal for the Nepal side of the journey.
Contact: <a href="tel:{$c['phone']}">{$c['phone']}</a> · <a href="mailto:{$c['email']}">{$c['email']}</a>.</p>

<h2>2. What we collect</h2>
<ul>
<li><b>Booking details</b>: passenger names, age / gender where needed for the seat and the manifest, mobile number,
email (optional), boarding and drop point, travel date, seats and fare.</li>
<li><b>Identity documents</b>: ID type and number, nationality and, where you upload them, a photo of the ID
(e.g. Aadhaar, Nepali citizenship card, passport). Cross-border travel and the passenger manifest require them.</li>
<li><b>Payment proof</b>: the UPI / eSewa reference and the screenshot you upload so we can verify the payment.
We never see or store your card, bank or UPI PIN details.</li>
<li><b>Messages</b>: WhatsApp messages you send to our business number (for example a PNR to check a booking) and
the messages we send you.</li>
<li><b>Sign-in data</b>: your mobile number and one-time codes (OTP). OTP codes expire and are masked in our message logs.</li>
<li><b>Technical data</b>: IP address, browser / device type and security logs, used to keep the service safe and to
stop abuse. We do not use advertising or third-party analytics trackers.</li>
<li><b>Location</b>: only if you allow it in your browser, to show the nearest boarding point or live bus position.
It is not stored against your booking.</li>
</ul>

<h2>3. How we use it</h2>
<ul>
<li>to make, confirm, change, cancel and refund your booking, and to issue your e-ticket;</li>
<li>to send booking confirmations, e-tickets, boarding / trip updates, payment and refund updates by WhatsApp, SMS
and email;</li>
<li>to prepare the passenger manifest and complete border and legal formalities;</li>
<li>to answer your questions and complaints;</li>
<li>to keep accounts and meet tax, company-law and police / border requirements;</li>
<li>to prevent fraud and misuse of the website.</li>
</ul>
<p>We do <b>not</b> sell your personal data and we do not send you marketing messages unless you asked for them.</p>

<h2>4. WhatsApp messages</h2>
<p>We use the <b>WhatsApp Business Platform (Cloud API) by Meta</b> to send you service messages about your own
bookings: ticket confirmation with the e-ticket image, trip and payment updates, and replies when you message us.
When you give us your mobile number for a booking, or message our WhatsApp number, you agree to receive these
messages. Messages are delivered through Meta's servers under
<a href="https://www.whatsapp.com/legal/business-policy/" rel="noopener">WhatsApp's Business Policy</a> and
<a href="https://www.whatsapp.com/legal/privacy-policy" rel="noopener">WhatsApp's Privacy Policy</a>.
To stop WhatsApp updates, tell us on any contact channel below; you will still get the SMS or email for a ticket you buy.</p>

<h2>5. Who we share it with</h2>
<p>Only what each needs to do its job:</p>
<ul>
<li><b>Meta Platforms</b> (WhatsApp messages) and <b>Twilio</b> (SMS and, as a fallback, WhatsApp) to deliver messages;</li>
<li>our <b>email provider</b> (Google) to send tickets by email;</li>
<li>our <b>hosting provider</b> (Hostinger) where the website and database run;</li>
<li>our <b>partner company in Nepal</b>, bus crew and authorised booking agents, for the journey you booked;</li>
<li><b>government, police, customs or border authorities</b> when the law requires it.</li>
</ul>
<p>If the AI help assistant on the website is switched on, the question you type in it is sent to our AI provider
(Anthropic) to generate an answer; do not type ID or payment details into it.</p>

<h2>6. How long we keep it</h2>
<ul>
<li>Booking, payment and invoice records: as long as Indian tax and company law requires (generally up to 8 years).</li>
<li>ID documents and payment screenshots: as long as needed for the journey, border checks, disputes and legal
requirements, then deleted on request (see <a href="/data-deletion">Data deletion</a>).</li>
<li>Security and technical logs: normally 30–90 days. Expired one-time codes: removed within a day.</li>
</ul>

<h2>7. How we protect it</h2>
<p>HTTPS everywhere, access limited to staff who need it with individual logins (two-factor sign-in available),
uploaded documents blocked from public web access, and an audit log of staff actions.</p>

<h2>8. Your rights</h2>
<p>You can ask us to show, correct or delete the personal data we hold about you, or to stop WhatsApp updates.
We answer within 30 days. Some records must be kept by law even after a deletion request; we will tell you which.
See <a href="/data-deletion">how to request deletion</a>. You can also complain to the Data Protection Board of
India under the Digital Personal Data Protection Act, 2023.</p>

<h2>9. Children</h2>
<p>Bookings are made by adults. A child's details are given by the adult booking for them, only for the journey.</p>

<h2>10. Changes</h2>
<p>We will update this page when our practices change and show the new date at the top.</p>

<h2>11. Contact / grievance</h2>
<p>{$c['legal']}, {$c['address']}<br>
Phone / WhatsApp: <a href="tel:{$c['phone']}">{$c['phone']}</a> · Email: <a href="mailto:{$c['email']}">{$c['email']}</a></p>
HTML;
    }

    /**
     * The refund scale exactly as the refund engine applies it (settings
     * refund_slabs, read by includes/fare.php) — never a copy that can drift.
     */
    private static function refundSlabs(): string
    {
        $slabs = json_decode(Settings::getString('refund_slabs', ''), true);
        if (!is_array($slabs) || $slabs === []) {
            $slabs = [['minHrs' => 96, 'pct' => 90], ['minHrs' => 48, 'pct' => 75], ['minHrs' => 24, 'pct' => 50],
                      ['minHrs' => 6, 'pct' => 25], ['minHrs' => 0, 'pct' => 0]];
        }
        usort($slabs, static fn ($x, $y) => (int) ($y['minHrs'] ?? 0) <=> (int) ($x['minHrs'] ?? 0));
        $out = '';
        $upper = null;
        foreach ($slabs as $s) {
            $h   = (int) ($s['minHrs'] ?? 0);
            $pct = (int) ($s['pct'] ?? 0);
            $when = $upper === null
                ? $h . ' hours or more before departure'
                : ($h === 0 ? 'less than ' . $upper . ' hours before departure, or no-show' : $h . '–' . $upper . ' hours before departure');
            $out .= '<li>' . $when . ': ' . ($pct > 0 ? $pct . '% refund' : 'no refund') . '</li>';
            $upper = $h;
        }
        return '<ul>' . $out . '</ul>';
    }

    private static function terms(array $c): string
    {
        $slabs = self::refundSlabs();
        return <<<HTML
<p class="lead">These Terms of Service apply to everyone who uses <a href="{$c['site']}/">shreehariglobal.in</a>,
books a ticket with {$c['legal']} ("we", "us"), or messages our WhatsApp number. The complete, section-by-section
<b><a href="/#/terms">Terms &amp; Conditions of Carriage</a></b> (English, हिन्दी, नेपाली) form part of these Terms;
if the two differ, the full Terms &amp; Conditions apply.</p>

<h2>1. The service</h2>
<p>We sell seats on our India–Nepal sleeper bus service (Gujarat ⇄ Rupaidiha / Nepalgunj) through this website,
our counters and authorised agents. The Nepal side of the journey is operated with our partner company in Nepal.</p>

<h2>2. Booking and payment</h2>
<ul>
<li>A seat is confirmed only after we verify your payment; you then receive a PNR and an e-ticket.</li>
<li>Check the names, mobile number, date and boarding point before paying. You are responsible for the details you give.</li>
<li>Fares are shown in INR (and NPR where offered) before you pay. We may revise fares for future bookings; a
confirmed ticket keeps its fare.</li>
<li>Fake or misused payment proof cancels the booking and may be reported.</li>
</ul>

<h2>3. Cancellation and refund</h2>
<p>Cancellation before departure is refunded on this scale:</p>
{$slabs}
<p>Refunds go back to the same account within 5–7 working days after approval. If we cancel a trip, you get a full
refund or a free change of date.</p>

<h2>4. Travel rules</h2>
<ul>
<li>Reach the boarding point 30 minutes before departure with your e-ticket (image, PDF or PNR).</li>
<li>Carry a valid photo ID. For the India–Nepal border, carry the documents the authorities require
(for Indian and Nepali citizens, typically a passport, voter ID or citizenship certificate).</li>
<li>No illegal, dangerous or restricted goods. Luggage must follow the allowance in the full Terms and customs rules.</li>
<li>Staff may refuse travel to anyone who is unsafe, abusive or without valid documents.</li>
</ul>

<h2>5. Delays and events outside our control</h2>
<p>Road, weather, border, strike or government action can delay or stop a journey. We will inform you and help with
a change of date or a refund as set out in the full Terms; we are not liable for losses caused by such events.</p>

<h2>6. WhatsApp and other messages</h2>
<p>By booking or messaging us you agree to receive service messages about your bookings on WhatsApp, SMS and email.
Our WhatsApp assistant can tell you a booking's status when you send its PNR; full details and the e-ticket are only
sent to the mobile number used for the booking. Use of WhatsApp is also subject to Meta's WhatsApp terms.</p>

<h2>7. Using the website</h2>
<p>Do not misuse the website: no automated scraping, fake bookings, attempts to access other people's bookings or to
break its security. We may block access that harms the service.</p>

<h2>8. Liability</h2>
<p>Our liability for any booking is limited to the fare paid for it, except where the law does not allow a limit.</p>

<h2>9. Privacy</h2>
<p>How we handle personal data is explained in our <a href="/privacy-policy">Privacy Policy</a>.</p>

<h2>10. Law and disputes</h2>
<p>These Terms are governed by the laws of India. Courts at Mehsana, Gujarat have jurisdiction, without affecting any
consumer rights you have under Nepali law for the Nepal part of the journey. Please contact us first; most issues
are solved in a call.</p>

<h2>11. Contact</h2>
<p>{$c['legal']} (CIN {$c['cin']}), {$c['address']}<br>
Phone / WhatsApp: <a href="tel:{$c['phone']}">{$c['phone']}</a> · Email: <a href="mailto:{$c['email']}">{$c['email']}</a></p>
HTML;
    }

    private static function deletion(array $c): string
    {
        $msg = rawurlencode("DELETE MY DATA\nName:\nMobile used for booking:\nPNR (if any):");
        return <<<HTML
<p class="lead">You can ask {$c['legal']} to delete the personal data we hold about you — booking contact details,
ID documents and photos you uploaded, payment screenshots, WhatsApp chat history with our business number, and your
website sign-in account.</p>

<h2>How to request deletion</h2>
<ol>
<li><b>WhatsApp</b>: send <b>DELETE MY DATA</b> with your name, the mobile number used for booking and your PNR (if you
have one) to <a href="https://wa.me/{$c['wa']}?text={$msg}" rel="noopener">{$c['waShow']}</a>.</li>
<li><b>Email</b>: write to <a href="mailto:{$c['email']}?subject=Data%20deletion%20request">{$c['email']}</a> with the
subject "Data deletion request" and the same details.</li>
<li><b>Phone / counter</b>: call <a href="tel:{$c['phone']}">{$c['phone']}</a> or visit any of our booking counters.</li>
</ol>
<p>To protect you, we confirm the request from the same mobile number or email that is on the booking before
deleting anything.</p>

<h2>What happens next</h2>
<ul>
<li>We acknowledge your request within 7 days and complete it within 30 days.</li>
<li>We delete or anonymise your contact details, uploaded ID documents and photos, payment screenshots, WhatsApp
chat records and sign-in account.</li>
<li>We keep only what the law requires — for example the invoice and payment record of a ticket you bought (needed for
tax and company accounts, generally up to 8 years) — and nothing else. We tell you exactly what was kept and why.</li>
<li>If you have an upcoming journey, deleting your data cancels that booking under the normal refund rules; we will
check with you first.</li>
</ul>

<h2>Facebook / Meta</h2>
<p>We do not offer "Log in with Facebook" and do not receive your Facebook profile. Our Meta app is used only to send
and receive WhatsApp business messages. WhatsApp keeps its own copy of chats on your phone; to remove it there, delete
the chat in WhatsApp.</p>

<p>More about how we handle data: <a href="/privacy-policy">Privacy Policy</a> · <a href="/terms-of-service">Terms of Service</a></p>
HTML;
    }

    /* ----------------------------------------------------------------- */

    private static function layout(string $title, string $body, array $c, string $page): string
    {
        $t   = Security::e($title);
        $upd = self::UPDATED;
        $nav = '';
        foreach (['privacy' => ['/privacy-policy', 'Privacy Policy'], 'terms' => ['/terms-of-service', 'Terms of Service'], 'deletion' => ['/data-deletion', 'Data Deletion']] as $k => [$href, $label]) {
            $nav .= '<a href="' . $href . '"' . ($k === $page ? ' aria-current="page"' : '') . '>' . $label . '</a>';
        }
        $canon = $c['site'] . array_search($page, self::ROUTES, true);
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$t} — {$c['name']}</title>
<meta name="description" content="{$t} of {$c['legal']}, operator of the India–Nepal bus service at shreehariglobal.in.">
<link rel="canonical" href="{$canon}">
<link rel="icon" href="/favicon.ico">
<style>
:root{--bg:#f7f8fa;--card:#fff;--ink:#1c2230;--mute:#5b6475;--line:#e3e6ec;--brand:#b3261e;--link:#1557b0}
@media (prefers-color-scheme:dark){:root{--bg:#11141a;--card:#1a1e26;--ink:#e8ebf1;--mute:#a3abba;--line:#2c3240;--brand:#ff7a70;--link:#8ab4ff}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.65 system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans",sans-serif}
header{background:var(--card);border-bottom:1px solid var(--line)}
.wrap{max-width:820px;margin:0 auto;padding:0 16px}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 0;flex-wrap:wrap}
.brand{font-weight:700;color:var(--ink);text-decoration:none}
.brand span{color:var(--brand)}
nav{display:flex;gap:6px;flex-wrap:wrap}
nav a{font-size:14px;color:var(--mute);text-decoration:none;padding:6px 10px;border-radius:8px;border:1px solid var(--line)}
nav a[aria-current]{color:var(--card);background:var(--ink);border-color:var(--ink)}
main{background:var(--card);border:1px solid var(--line);border-radius:14px;margin:20px auto 32px;padding:22px 20px}
h1{font-size:28px;line-height:1.2;margin:0 0 4px}
.upd{color:var(--mute);font-size:14px;margin:0 0 18px}
h2{font-size:19px;margin:26px 0 8px}
.lead{font-size:17px}
ul,ol{padding-left:22px}
li{margin:4px 0}
a{color:var(--link);overflow-wrap:anywhere}
footer{color:var(--mute);font-size:14px;text-align:center;padding:0 16px 32px}
</style>
</head>
<body>
<header><div class="wrap top"><a class="brand" href="/">🚌 <span>S Hari</span> Global</a><nav>{$nav}</nav></div></header>
<div class="wrap"><main>
<h1>{$t}</h1>
<p class="upd">Last updated: {$upd}</p>
{$body}
</main></div>
<footer>© {$c['legal']} · CIN {$c['cin']} · <a href="/">Book a ticket</a></footer>
</body>
</html>
HTML;
    }
}
