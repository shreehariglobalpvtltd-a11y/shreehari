/* =====================================================================
 *  sw.js — S Hari Global offline service worker (navigator / PWA).
 *
 *  SAFE BY DESIGN — this controls the whole origin, so it is deliberately
 *  conservative:
 *    • /api/* and /admin/* and every non-GET request are NEVER touched
 *      (pure network passthrough) — booking, payments, admin and the
 *      per-session CSRF are never cached or served stale.
 *    • The app document is NETWORK-FIRST: fresh (correct CSRF) whenever
 *      online, and only falls back to the last-good shell when offline.
 *    • Only static map assets — MapLibre's own tiles / glyphs / sprites /
 *      fonts and the MapLibre library itself — are CACHE-FIRST, with a
 *      hard size cap. That is the actual offline-navigation win.
 *    • Anything not explicitly matched is left as a normal network fetch.
 *
 *  Bump VERSION to invalidate old caches on the next deploy.
 * ===================================================================== */

var VERSION = 'shg-v132';   // 2026-09-19d (ETA IN THE APP): Trip Companion shows 'about N min to your stop' from /api/eta.php - the same EtaAlerts engine as the bus-is-near WhatsApp, so the app and the message agree; silent when the fix is stale / off-route. asset ver 20260919c.   // 2026-09-19c (PHONE + LANGUAGE PASS): admin toolbars no longer push Seat Map / Trip Dashboard sideways on a phone (all 42 admin pages audited at 375px + 1366px); the seat screen's women-safety line speaks the app language only. asset ver 20260919b.   // 2026-09-19b (OFFLINE DESK): /offline-desk.html is precached and served from the cache when the network is gone - a counter with no signal writes ticket REQUESTS on the phone (never a seat), the server seats each one once by its uuid when the phone is back online (api/offline-sync.php, includes/offlinequeue.php; ships OFF). asset ver 20260919a.   // 2026-09-19 (COMPUTER + CLARITY PASS, owner): admin tables scroll ONE way (no forced 900px, no 78vh box on short lists); seat screen - the key is 4 signs + 'More signs' on a computer (labels now i18n en/hi/ne), CEO ribbon + office pills hidden inside the flow on every width, shorter header + road scene; bus strip and route bar stand on the 1160px page column on a wide monitor; NEW Admin > Settings > System Health (first reader of health_incidents) + nightly read-only data audit (cron/data-audit.php); WhatsApp: Meta's refusal reason kept in the ledger, 'template missing' named by the delivery sentinel; counterSale() honours the caller's boarding town. asset ver 20260919a.   // 2026-09-18 (ADMIN + AGENT PREMIUM UPGRADE, owner 9-point brief): design system v2 across the admin panel (tokens, sprite, cards, pills, bottom nav), Agent 360 hub (statement / settlements / loans & advances / deposits / payout requests / KYC / activity), reschedule onto any departure + per-passenger ID, passenger register + ID documents, one Bus Chalan hub (PDF / PNG / WhatsApp, signed download gate), WhatsApp office message book with one-click sends on every agent and ticket page + daily reminder cron, counter one-screen confirm + same-bus repeat + ticket poll + round-trip gate, 'Other' typed pickup / drop point (customer + counter), bus-on-the-road journey strip (18-journey.js) on results / seats. asset ver 20260918a.   // 2026-09-17 (3D + WHATSAPP PASS, owner: 'smooth, 3D animation, WhatsApp sajilo, ticket auto customer ma'): route scene = perspective road + the real coach cut from the visiting card (transform-only layers), route-overview map draws itself with slab depth + pointer tilt, India-Nepal outline draws behind the splash brand, navigator loader shows the route card, WhatsApp FAB opens a chooser (book on WhatsApp pre-filled from the search card, director, every India + Nepal office), search card 'Book on WhatsApp' button, ticket page shows whether the WhatsApp ticket was delivered (track.php wa), Nepal entity (Shree Hari Global Pvt. Ltd., Companies Act 2063, Nepalgunj Ward 2) + Nepal team numbers + digital visiting cards. asset ver 20260918a.   // 2026-09-13b (CHAT READS TIME QUESTIONS): 'bus kitne baje hai?', 'bus kati baje chha?', 'kab chalegi' and the Gujarati o'clock word now answer with every pickup time for both runs (the rule engine used to fall back to 'did not understand' while no AI key is set); a message that clearly shows Hindi, Nepali, Gujarati or English sets the reply language, the dropdown still wins when changed; the fare answer quotes the live direction fares ₹2,000 / ₹1,800 (it said a retired ₹2,199 seater and a legacy ₹2,090 share); push JWT claims keep https:// unescaped. asset ver 20260918a.   // 2026-09-13 (PWA MASTER UPGRADE - owner master prompt, Fable 5.1): WEB PUSH (VAPID + RFC 8291 in pure PHP, includes/webpush.php + api/push.php; delay / reminder / ticket-ready alerts ride Notify::tripEvent + bookingConfirmed; push + notificationclick + pushsubscriptionchange below), INSTALL SHEET (17-pwa.js: Android prompt as a bottom sheet, iOS Share -> Add to Home Screen steps), OFFLINE FALLBACK PAGE (/offline.html, precached, served when there is no shell to fall back to), BORDER CROSSING PREP card (auto-opens border_card_hours before an India->Nepal departure, printable), 7-DAY SEAT-FILL chart on the results board (api/occupancy.php), GROUP SPLIT-PAY (api/split-pay.php + pay-share.php), Sahayak: last 20 messages persisted, human handoff on agent/human, unread badge, system prompt built from live settings; language auto-detect (ne/hi). asset ver 20260913a. // 2026-09-12 (HOME = QUICK BOOKING, owner: 'quick booking 5-6 sec, regular option, show the AI helping, animation, smooth'): booking CHOICE on the card (⚡ QuickBot / 🪑 seat map - body.bk-regular folds the card to one strip so the regular search card is first on screen, remembered, any QuickBot link unfolds), AI strip (what it reads/picks + a real issued-tickets counter from index.php with a count-up), 🎤 voice into the smart line (SpeechRecognition, app language), AI 'thinking' dots, first plan lands tile by tile, pickup→drop dashed line with a moving bus, seat-map link under the plan. All motion transform/opacity, one element each. asset ver 20260912a. // 2026-09-11 (PERF PASS 2 - owner: fast hunu paryo, touch smooth, Android+iOS): boot file split - live map + full map + Trip Companion + SHG Sahayak (chat, voice search, agent form, timetable box) moved to 16-lazy.js (331 -> 170 KB boot JS), loaded on the first tap that needs it or on idle after load; ceo.jpg 267 KB -> 70 KB (it was on the splash); phone nav row carries the number pills (one bar, not two); QuickBot confirm at y=720 on 375x812; touch: no sticky hover lifts on touch screens, touch-action manipulation, 16px inputs (no iOS focus zoom). asset ver 20260912a. // 2026-09-11 (PERF PASS): bundle tags in <head> (fetch starts with the first HTML bytes), splash floor 1.5s/250ms, decorative infinite animations + blurred orbs off on phones (36 -> 14 running), global transition:all -> paint-only, mobile chrome one credit row, QuickBot confirm button before the change toggle + tighter plan tiles (y=947 -> 800 at 375x812), view switch jumps then fades, SW navigate: only '/' is the shell (verify-ticket/agent-signup no longer cached as the app) + 3.5s race to the cached shell on slow links, api/csrf.php + shgApi retry-once on 419, index.php strips HTML comments at serve time (52 -> 40 KB gz), flow headers compact on phones. asset ver 20260912a. // 2026-09-11 (SEAT LABELS): sleeper berths now show the LA1/UA1 row-letter grid everywhere (customer + agent/counter + admin map + ticket PNG/PDF + chalani + WhatsApp); display-only, storage stays canonical L1/U1. asset ver 20260912a. // 2026-09-10 (SHG AI BRAIN PHASE 2 - QUICKBOT IN FOUR LANGUAGES): TicketBot::parse now reads Hindi and Nepali in Devanagari and Gujarati in its own script - dates (kal / bholi / kaale, weekdays, parson), number + seat words (dui jana, be tikit, 3 log), Devanagari and Gujarati digits as a mobile, gender, payment and pickup names in all three scripts, with Unicode-safe word boundaries (PCRE b is ASCII-only, so 'kala' no longer reads as 'kal') and the destination phrase winning over a bare origin word. TicketBot::suggest carries the writer's language (detectLang), a confidence LADDER (>=0.85 proceed / 0.60-0.84 tag the auto-filled facts / <0.60 ask ONE question in that language with tappable answers - never for a bare name + mobile, so the 10-second ticket stays one tap) and 'same as last time' (usual pickup, direction, party, berths; QuickTicket::plan honours prefer when the berths are free and the gender rules allow). Customer card and desk both show the question and the one-tap chip. WhatsApp requests captured by the brain parse in the same scripts. asset ver 20260910b. // 2026-09-10 (SHG AI BRAIN PHASE 1): the per-bus per-date CHALLAN is now a PICTURE - includes/challanpng.php draws both floors of the coach from the live booking register (every berth: name, mobile, pickup -> drop codes, PNR, paid/cash/pending, private cabins merged onto the two beds they really take), totals and revenue, English only for the border desk, cached on a DATA fingerprint so it redraws itself the moment a seat, hold, block, bus or driver changes and never runs on the sale path; admin/challan.php serves it (bookings.view, counter included, scoped agents refused), linked from manifest / seat map / date view / dashboard; challan_reports logs every render. Ticket edits: a REASON box on passenger, contact, seat and date changes, written to audit_logs.reason with actor_role; the passenger gets the corrected ticket on WhatsApp after an edit (booking.edit_notify, off via notify_ticket_edit=0); Activity log gains booking filter, date range, reason column and a CSV export (one row per changed field). FIXED: an edited passenger / cash settlement kept the stale PNG ticket cached (only the PDF was dropped) - now both. Seat map: legend was display:none on phones (a promised ? button never existed) - now a one-line strip; a berth taken by the OTHER seat type (sharing <-> private) is hatched and explained instead of plain grey; floor pills read First Floor / Second Floor. asset ver 20260910a. // 2026-09-08 (CUSTOMER GEM VAULT + THE GOVERNOR + RELIABILITY SPINE): every name and phone that reaches the company is now a durable asset. includes/gemvault.php keys a profile by the normalised phone - the one identity the app, WhatsApp, the counter and an agent all agree on - and fills it from post-commit EventBus hooks, so a walk-in whose ticket the desk typed is no longer invisible (127 historic customers adopted by cron/gem-hygiene.php on the first run, all of them named). Enrichment is ONE-WAY for contact facts (a later blank never erases a name) and a full RECOMPUTE for habits (an ambiguous deck stops claiming to know). NO gender is stored or returned - the seat lock reads it per passenger at sale time, every time - and a scoped counter agent gets NOTHING from the vault, only their own book via TicketBot::profile. includes/aigovernor.php makes 'AI never touches booking/seat/fare/refund' a property of the code: an allow-list with bounds, a second name-shaped lock, shadow mode by default, an audit row for every attempt including refusals, and one-call rollback. includes/health.php + cron/health-delivery.php turn silent failures loud - one incident per FAULT CLASS in owner language with the exact Meta Security Centre steps for 63112, and classifying on TEXT as well as code because most real rows carry no provider code at all. cron_done() now leaves a heartbeat, so a job that STOPS is visible (cron/health-heartbeat.php). api/events.php + 01-boot.js add a product beacon that is redacted at the writer and pruned on a retention window. Battery 54 -> 57 suites; HTTP suites take SHG_TEST_BASE so a second worktree cannot report green for another checkout's code. asset ver 20260908d. // 2026-09-08 (SEAT MAP READS THE PICKUP + THE MODE MAP IS CONFIGURABLE): every sold berth on the staff seat map now carries its pickup short code (STV/BRC/EMB/AMD/MSN) as a chip under the seat number with a matching left stripe, coloured by the stop's POSITION ALONG THE ROUTE so two stops on one bus can never share a hue and the map reads in travel order; a Pickup key lists the route's stops. Status keeps the fill colour - pickup is a second channel, so no fact is told by hue alone. The 15s poller was taught all of it (it rewrites the tile wholesale, so a server-rendered label used to survive exactly one tick). Legend swatches now reuse the CELL classes instead of repeating their light hex, which is why the key stopped matching the map in dark mode; seat cells gained role/tabindex/aria-label. FIXED: Seats::adminSeatMap never joined bookings.booking_mode, so a private cabin sold as 'L6' marked SHARING berth L6 booked while beds L11/L12 - with a passenger on them - rendered open; the desk and the customer app were showing two different inventories for one coach (never an oversell: assertAvailable was always physical). The private<->bed mapping is no longer a literal 2 in three PHP methods and two JS ones: it is the seat_mode_map settings row, with per-label `explicit` overrides for an irregular cabin that win over the ratio in BOTH directions, loud validation, and Seats::mapChangeImpact() to name the already-sold berths a rule change would relocate. The bundle reads it from SHG_BOOT, so re-shaping a coach is no longer a two-file deploy. Battery 20 -> 54 suites (35 were written but never run). asset ver 20260908a. // 2026-09-07 (🤖 QUICKBOT TICKET — 10-SECOND BOOKING): Quick Ticket and the AI Ticket Bot are ONE automation now, for passengers and desks alike. One smart line — name + mobile, or 'Ram 9876543210, 2 seats, Mehsana tomorrow' — is read by TicketBot::parse (name · mobile incl. +977 · seats · town · date words · gender words · payment · agent code SHG-NNN) and merged by ONE engine (TicketBot::suggest) with the passenger's own verified history and the company defaults into one confirmation card → [Confirm & Issue Ticket] → ticket PNG + PDF + WhatsApp. Customer privacy: memory only by the SESSION's number, gender and date never from history. Optional agent code on a passenger's sale (unknown code = direct sale, never a refusal; notice says which). Desk: the separate bot panel is gone — the smart line sits above name/mobile, every pause re-suggests, Enter confirms; the plan aside explains why. Learning switch off still parses and plans. Renamed everywhere: nav, counter bar, dashboard, agent card, app banner, menu, quick action. asset ver 20260907b. // 2026-09-07 (ONE CLICK, TICKET READY IN 10 SECONDS): the Quick Ticket card now sits directly under the hero (no reveal) with a hero CTA carrying the slogan, plans itself on load — next bus · S Hari Parking pickup (new setting quick_ticket_customer_boarding, desks unchanged) · 1 passenger · fare — and its big button IS the booking: passengers, date (7-day strip + any date inside the horizon), pickup, direction and woman/man edit in place and re-plan. The sale is PINNED to the facts shown (expect{} → 409 plan_changed re-renders instead of selling a bus never seen), the customer plan closes each pickup at the same boarding cut-off create() uses, a mistaken tap is undone free within quick_ticket_undo_min (customer_undo → BookingService::cancel, nothing paid), a signed-in passenger gets an identity chip (Not you? = server logout via otp.php) and their own usual pickup / direction / party pre-filled from verified tickets (session-bound; gender never from history), and customer sales feed the bot's outcome loop. Stale-session user rows no longer break a sale; DB errors never reach a passenger as raw SQL. asset ver 20260907b. // 2026-09-06 (QUICK TICKET FOR EVERYONE + AI TICKET BOT): the app home banner now sells the passenger's own ticket in place — name + mobile → api/quick-ticket.php customer_plan (next catchable bus, boarding point, gender-safe seat, fare under the PUBLIC caps) → a one-line card with confirm → customer_sell through the SAME BookingService::create() the seat map uses (pay-on-boarding = confirmed on the spot, ticket PNG + PDF on #/ticket/PNR; pay-on-boarding off = pending with the payment targets). Staff still open the desk pre-filled. The desk gains a 🤖 AI Ticket Bot (includes/ticketbot.php): one typed line or just a mobile → passenger memory from VERIFIED tickets (name, gender as recorded, usual pickup, direction, party size, deck) + the company's own patterns (pickup by hour, per-desk pickup) merged UNDER explicit input, with reasons + confidence, an outcome loop (hit/miss per field; an untrusted field is shown but not auto-applied), the plan itself still from QuickTicket::plan() and every rule still BookingService::create()'s. No external AI call. Agent dashboard card, Download PDF on the desk result, settings quick_ticket_customer_on / ai_bot_*. asset ver 20260907b. // 2026-09-06 (QUICK TICKET SERVICE): name + mobile → the server picks the next catchable bus, the desk's boarding point, a gender-safe seat and the fare (includes/quickticket.php), sells through BookingService::create() as a counter sale, renders the 1080x1620 PNG on the spot and reports whether the WhatsApp went (Notify keeps a per-request journal; a one-tap click-to-chat link covers the days until the Twilio WhatsApp sender's KYC is approved). New admin desk /admin/quick-ticket.php (hot nav item + dashboard banner), api/quick-ticket.php (selling staff only), a highlighted "Quick Ticket Service" banner + quick-action card + menu entry on the app home (i18n qt* keys en/hi/ne; a passenger's request opens the office WhatsApp pre-written, a staff session opens the desk pre-filled), counter bar link. asset ver 20260907b. // 2026-09-06 (counter: fresh form per sale): a selling staff session no longer gets the repeat-customer prefills - remembered contact, PaxMemory chips, welcome-back, the signed-in account's name/phone - which carried the PREVIOUS passenger onto the next ticket at the desk; 14-counter blanks phone/email/ID per new draft. asset ver 20260906h. // 2026-09-06 (leftovers): the checkout countdown at a staff counter now reads 'Seat hold mm:ss' instead of 'Complete payment within' (a desk sale confirms on submit — there is no payment deadline); the status hero strip, cancel dialog and WhatsApp share text print the passenger's boarding → drop point like the ticket does. asset ver 20260906g. // 2026-09-06 (ticket = the passenger's own stop + counter no-stop): the PNG/PDF/app ticket now print the BOARDING POINT the passenger chose (AMD · Nana Chiloda · DEP 9:00 PM) instead of the route origin (STV Surat · 1 PM) — one helper, Boarding::stopDisplay, mirrored in 02-config stopDisplay; verify page shows the pickup time; a selling staff session no longer gets the 'this number already has a booking' confirm, which was stopping bulk counter sales. asset ver 20260906f. // 2026-09-06 (any-date counter + chalani + ticket issued-by): selling staff (admin/counter/agent) may now record a ticket for ANY travel date - the 'Choose a valid travel date' refusal at the counter was api/book.php flooring a seller at yesterday, with the customer window, the departed-bus gate and the pickup cut-off stacked behind it; all lifted for staff via Auth::isSellingStaff(), customers unchanged, the inaugural date / cancelled / per-date OFF still refuse everyone. 14-counter.js drops the date input's min. Ticket PNG gains an ISSUED BY line (agent - SHG code - phone / Office / Online), fits long stop names before cutting them, and re-renders cached files once after a layout change. Chalani is Nepali-only with the ticket's brand header, plus a real Devanagari PDF (?format=chalanipdf). asset ver 20260906e. // 2026-09-06 (offline ticket + ratings): the 1080x1620 server PNG is now kept in a NON-version-scoped cache 'shg-tickets-v1' (spared by name in the activate purge, or a deploy the night before travel would delete the passenger's ticket), populated only by an explicit page message, keyed without the HMAC token so an APP_KEY rotation cannot orphan it, capped at 10 and wiped on sign-out; PDF/invoice/api/admin/uploads stay passthrough. Post-journey rating wired to the `feedback` table that has been dead schema since the first build - api/feedback.php (ticket key or signed-in owner only, arrived-only, one per booking), a star card on the ticket screen, admin/feedback.php, and an OPT-IN auto-arrive in cron/lifecycle.php (auto_arrive_hours, default 0 = off, because 'arrived' WhatsApps every passenger). asset ver 20260906b. // 2026-09-06 (payment progress + office proof upload + version discipline): the ticket screen now shows a FOUR-STEP payment strip (Booked -> Payment sent -> Being checked -> Ticket ready) built entirely from data the payload already carried, because "has my payment gone through?" was the most common support message and the old single band said where you were but not what came next; COD keeps step 3 inactive until the cash is actually handed over so the bar never shows two pulsing steps at once. Admin booking-view gains an office-side proof upload for the passenger who cannot upload from their own phone, reusing attachScreenshot + submitPaymentProof so the file is validated identically and the customer still gets the "proof received" message. Every ?v= stamp in the tree unified on one value - four had drifted (terms-data 20260822b, logo 20260903e, manifest icons 20260823m, ceo 20260828) which is exactly how an installed PWA keeps a stale bundle. asset ver 20260906a. // 2026-09-05 night 5 (THE stale-checkout deadlock — owner: "feri tei problem, check highlight"): ROOT CAUSE — the "Please check the highlighted fields" toast is the SERVER's Response::invalid default; the real reason (e.g. blank phone → $seller null → "valid mobile") sat in .fields and the client only showed the top line. WORSE: safeToReload() refused to auto-update while on #/checkout with typed data, so a staff member stuck retrying a broken counter sale kept the tab on #/checkout → the update that FIXES checkout was deferred forever. Fixes: (1) submitBooking surfaces e.fields (server per-field reason); (2) safeToReload()=true for a selling staff session (walk-in re-key is cheap); (3) deferred update shows a TAPPABLE "🆕 New version — tap to update" banner (one-tap escape from a stale-checkout loop). asset ver 20260905l. // 2026-09-05 night 4 (counter T&C hard fix — owner: "tick lagaune agreement jasto option"): the T&C requirement is now DROPPED ENTIRELY from the validator (validateCheckoutDetails + coSyncContinue) for a selling staff session — no longer merely auto-ticked, which depended on decorate timing and could resurrect as an invisible highlighted field. asset ver 20260905k. // 2026-09-05 night 3 (counter diagnosability — owner: "abhi same problems"): the fix-fields toast now NAMES the failing fields ("→ मोबाइल / Mobile · नाम / Name", and a dedicated "UTR / भुक्तानी प्रमाण" message when only the proof gate failed), counter bar shows the RUNNING bundle version (read off 14-counter's own ?v=) and watches sw.js's ETag every 10 min / on tab-foreground to show a "🔄 New version — tap to refresh" chip (a counter tab stays open all day, so deploys never reached it — every fix looked still-broken at the desk); document-capture guard re-asserts cod + T&C right before submit. asset ver 20260905j. // 2026-09-05 night 2 (counter desk polish): phone field SAYS optional for staff (label/placeholder/err swapped in counter mode, maxlength 10→15 so +977 fits — the HTML cap was truncating Nepali numbers), and every "check the highlighted fields" toast now SCROLLS to + focuses the first invalid field (the counter stared at the confirm button seeing nothing highlighted); asset ver 20260905i. // 2026-09-05 night (PNG ticket + counter phone fix): the PRIMARY ticket is now a 1080x1620 HD PNG (Ticket::pngPath, GD + Noto, logo/route/seat chips/fare/one verify-URL QR that ALSO works at the boarding scanner — verifyQrPayload accepts the URL form); download-ticket.php?img=1 (&view=1 inline), WhatsApp/email confirmations link the image first, app's main button + auto-download + share all use the server PNG (client canvas = offline fallback), agent-sales/booking-view get Ticket links; PDF stays for printing. COUNTER FIX: walk-in phone may be blank and Nepali +977 numbers (8-15 digits) accepted at the desk — the strict 10-digit rule was rejecting them ("Please check the highlighted fields", owner report from dileep/shariglobal); customers online unchanged. asset ver 20260905h. // 2026-09-05 night (counter T&C fix): a counter sale no longer demands the customer's e-signature checkbox — 14-counter.js auto-accepts T&C at the desk (the unticked box kept "भुक्तानीमा जानुहोस्" disabled and lit the checkbox red on every sale — owner: "ticket hun lako xain, check highlight vaxn"); asset ver 20260905g. // 2026-09-05 evening (5-point pass): counter BULK BOOKING — staff sales up to counter_max_seats_per_booking=20 in one booking (customers stay at 6; server split in BookingService::create/counterSale, staff boot ships maxSeats, 20-button picker for staff); company LOGO embedded on ticket/invoice/report PDF headers (palette-PNG GD guard in Pdf::parseImage); seatmap→counter deep link carries sid+seat ("Book in app" on an open seat), trip-dashboard keeps sid on seatmap links, poll refreshes mine/pnr; staff.php sign-in editor (email/username/password) for counter+office roles + email shown in roster + agent resetpw keeps must_change_pw=0; chalani print view viewport meta, 44px touch fixes; asset ver 20260905f. // 2026-09-05 counter role + refund slabs matched to Terms (new 'counter' staff role: company ticket windows with own logins — book/edit/reschedule/cancel/reprint/verify, no revenue KPIs, no agent ledgers, no fleet/staff; enforced refund slabs now the 5 tiers the T&C page always promised: 96h/90 · 48h/75 · 24h/50 · 6h/25 · 0; ticket footer QR now opens the live verify-ticket status page instead of the homepage; Mehsana office number on the staff login; asset ver 20260905e). // 2026-09-04 agent login + bulk cancel + per-bus price (agents sign in with email + username + password; office manages those credentials; tickets register bulk-cancel with a confirm step, scoped to the seller; a manually added bus can run at its own fare; audit rows for bulk cancels and every bus add/edit; asset ver 20260905d). // 2026-09-05 nav (navigator split into lazy 15-nav.js, office marker, 3D terrain, lite mode + no-WebGL fallback, motion engine w/ dead reckoning, follow-bus + cross-device bus, bus trail; asset ver 20260905d). // 2026-09-05 perf (timetable memo + public cache, settings memo, dashboard sparkline 1 query, agents balances 1 query, 5 indexes; asset ver 20260905b). // 2026-09-05 (Bus Calendar): extra buses on a date are their own result cards (scheduleId carried through seats/holds/checkout), per-date OFF refused at checkout too; asset ver 20260905a. // 2026-09-04 batch 3 (ticket v2 simple/modern layout; daily-service ON/OFF master switch; asset ver 20260904d). // 2026-09-04 batch 2 (physical-space seat holds, both decks + Lower/Upper/Any, smarter seat pick + patient mode, women-only enforced, cross-device bus position via livebus, SW single download; asset ver 20260904c). // 2026-09-04 (login = name + mobile, no OTP; per-passenger fare lines; seat state badges + legend; dark pre-paint; map fenced to India+Nepal, DB stops on the trip map, speed bands; OTP per-IP cap; asset ver 20260904c). // 2026-09-03 (i18n): agentLateSummary key in en/hi/ne; asset ver 20260903e. // 2026-09-03 (counter mode): staff book through the customer app's own seat map + checkout (assets/js/14-counter.js), same seat cap from settings, 7th-seat toast; asset ver 20260903d. // 2026-09-03 (one login door): footer/menu 'Agent / Staff sign-in' link + navStaff i18n key + agent launcher now opens /admin/login.php?portal=agent (code + OTP); asset ver 20260903c. (Older notes kept below.) // 2026-09-03 (booking upgrade batch): L3 emergency seat (private) + mode-aware reserved/booked (private L5/L6 fixed), Floor 1/Floor 2 grouping + "How many seats?" suggestion, cross-mode Private⇄Sharing seat sync (double-booking safety), private cabin pricing 3800/7600, group-under-one-name checkout + ticket, Surat 24×7 next-available roll-forward, admin/agent counter discount. // 2026-09-03 (agent 24h late-booking on the app): a valid SHG-NNN agent code now surfaces & books a bus within 24h of its departure — new search-screen agent field + agentCode passed to /search.php + date floor lowered one day. Server (create/search) re-checks the code; anonymous customers unchanged. (Older note kept below.) // 2026-09-02 (everything fits the phone): the owner's recording showed seat F - the red EMERGENCY berth - hanging off the right edge of a 393px screen, and the topbar mantra + phone number clipped at both ends. Both were the same mistake in two places: a bare `1fr` grid track is minmax(AUTO,1fr) and will not shrink below its content, so the 6-across sharing row stayed a fixed 314px in a 265px slot; and the topbar's relief rule was gated at max-width:380px, which no current phone is. Tracks are now minmax(0,1fr), the phone chrome around them is trimmed, and the topbar breakpoint is 460px - measured at zero overflow on 320/360/393/412. `body{overflow-x:hidden}` also went: it made BODY a scroll container, which is what let Android swallow vertical swipes.
var SHELL_CACHE = 'shg-shell-' + VERSION;
/* How long a returning visitor waits for a fresh document before the
   cached app shell opens instead (see the navigate handler). */
var SHELL_WAIT_MS = 3500;
var TILE_CACHE = 'shg-tiles-' + VERSION;
var ASSET_CACHE = 'shg-assets-' + VERSION;
var MAX_TILES = 1500; // cap on cached map assets (FIFO eviction)

/* The passenger's own ticket, kept for the border.
   DELIBERATELY NOT VERSION-SCOPED. Every other cache here is
   'shg-<kind>-' + VERSION so a deploy drops it — correct for code, fatal
   for this one: bumping VERSION the night before travel would silently
   delete the ticket the passenger is relying on. It is therefore a fixed
   name and is explicitly spared in activate() below. The stored object is
   a rendered PNG of an already-issued ticket, so it cannot go "stale" the
   way a JS bundle can; a reschedule re-issues under the same PNR and
   Ticket::reissue() unlinks the old render, so the next online fetch
   replaces it. */
var TICKET_CACHE = 'shg-tickets-v1';
var MAX_TICKETS = 10;

/* Our own versioned JS/CSS. Every URL carries ?v=, so a cached entry can
   never go stale: a new build changes the URL and the old cache is dropped
   with the old VERSION. This is what makes a re-open feel instant — the
   ~1 MB of app code is read from disk instead of the network. */
var ASSET_VER = '20260919c';
var PRECACHE = [
  '/assets/img/logo.png?v=' + ASSET_VER,
  '/assets/css/app.css?v=' + ASSET_VER,
  '/assets/css/views.css?v=' + ASSET_VER,
  '/assets/js/01-boot.js?v=' + ASSET_VER,
  '/assets/js/02-config.js?v=' + ASSET_VER,
  '/assets/js/03-accounts.js?v=' + ASSET_VER,
  '/assets/js/04-i18n.js?v=' + ASSET_VER,
  '/assets/js/05-router.js?v=' + ASSET_VER,
  '/assets/js/06-results.js?v=' + ASSET_VER,
  '/assets/js/07-checkout.js?v=' + ASSET_VER,
  '/assets/js/14-counter.js?v=' + ASSET_VER,
  '/assets/js/08-signin.js?v=' + ASSET_VER,

  '/assets/js/10-track.js?v=' + ASSET_VER,
  '/assets/js/11-pdf-ticket.js?v=' + ASSET_VER,

  '/assets/js/13-admin-routes.js?v=' + ASSET_VER,
  /* 5 Sep 2026: the navigator is loaded on demand (#/nav) but pre-cached
     here so the offline navigator still opens with no network. */
  '/assets/js/15-nav.js?v=' + ASSET_VER,
  /* 11 Sep 2026: trip companion + assistant + maps chunk, loaded on idle. */
  '/assets/js/16-lazy.js?v=' + ASSET_VER,
  /* 13 Sep 2026: PWA layer (install sheet, push, border card, split-pay,
     seat-fill chart) + the static page shown when even the shell is gone. */
  '/assets/js/17-pwa.js?v=' + ASSET_VER,
  '/offline.html',
  /* 19 Sep 2026: the counter's offline desk - must open with no signal. */
  '/offline-desk.html'
];

/* Cross-origin hosts whose GET responses are safe, static, cacheable map
   assets. Everything else cross-origin (Valhalla routing, Nominatim/Photon
   geocoding, Overpass, Wikipedia) is intentionally absent → passthrough. */
var TILE_HOSTS = [
  'tiles.openfreemap.org',            // OpenFreeMap vector tiles + glyphs + sprite (day/night)
  'server.arcgisonline.com',          // Esri world imagery (satellite)
  'tile.opentopomap.org',             // OpenTopoMap raster (terrain)
  's3.amazonaws.com',                 // Terrarium elevation tiles (3D terrain, 5 Sep 2026)
  'unpkg.com'                         // maplibre-gl.js + maplibre-gl.css
];

self.addEventListener('install', function (event) {
  /* Warm the asset cache, one entry at a time so a single 404 can never
     fail the whole install (cache.addAll is all-or-nothing). */
  event.waitUntil((async function () {
    try {
      var cache = await caches.open(ASSET_CACHE);
      /* 4 Sep 2026: no more `cache:'reload'` — every URL here is versioned
         and served immutable, so the HTTP cache the page just filled is the
         right source. It used to download the whole bundle a second time on
         every first visit. */
      await Promise.all(PRECACHE.map(function (u) {
        return cache.add(new Request(u)).catch(function () {});
      }));
    } catch (e) {}
    try { await self.skipWaiting(); } catch (e) {}
  })());
});

self.addEventListener('activate', function (event) {
  event.waitUntil((async function () {
    try {
      var keys = await caches.keys();
      await Promise.all(keys.map(function (k) {
        // drop our own older-version caches; leave anything else alone.
        // TICKET_CACHE is spared on purpose — see its declaration above.
        if (k === TICKET_CACHE) return null;
        if (k.indexOf('shg-') === 0 && k.indexOf(VERSION) === -1) return caches.delete(k);
        return null;
      }));
    } catch (e) {}
    try { await self.clients.claim(); } catch (e) {}
  })());
});

/* FIFO-trim a cache to a maximum number of entries (Cache API preserves
   insertion order, so the first keys are the oldest). */
async function trimCache(name, max) {
  try {
    var c = await caches.open(name);
    var keys = await c.keys();
    if (keys.length > max) {
      for (var i = 0; i < keys.length - max; i++) { await c.delete(keys[i]); }
    }
  } catch (e) {}
}

/* Is this the passenger's ticket IMAGE? Not the PDF, not the invoice. */
function isTicketImage(url) {
  return url.pathname === '/download-ticket.php' && url.searchParams.has('img');
}

/* Cache key for a ticket, with the HMAC download key stripped.
   Ticket::downloadToken() is derived from APP_KEY, so if that is ever
   rotated every stored URL would miss and the passenger would silently
   lose their offline ticket. Keying on the PNR alone keeps one entry per
   ticket no matter which link it arrived through (WhatsApp, email, the
   app's own button all carry different query orders). */
function ticketKey(url) {
  var pnr = (url.searchParams.get('pnr') || '').toUpperCase();
  return new Request(url.origin + '/download-ticket.php?pnr=' + encodeURIComponent(pnr) + '&img=1');
}

/* The static offline fallback, precached with the assets (13 Sep 2026):
   what a phone with no network AND no cached shell sees - it reads the
   tickets already saved in this browser instead of the browser's dinosaur. */
async function offlinePage() {
  try {
    var c = await caches.open(ASSET_CACHE);
    return (await c.match('/offline.html')) || null;
  } catch (e) { return null; }
}
function netError() {
  try { if (typeof Response.error === 'function') return Response.error(); } catch (e) {}
  return new Response('', { status: 504, statusText: 'offline' });
}

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') return; // never intercept POST/PUT/etc.

  var url;
  try { url = new URL(req.url); } catch (e) { return; }

  /* The passenger's own ticket image — the ONE carve-out from the
     never-cache rule below, and it is deliberately narrow:
       - same origin, exactly /download-ticket.php, and only with ?img=
       - the PDF and the invoice are untouched and stay passthrough
     Rationale: the stated use case is a border post with no data. Without
     this the app falls back to buildTicketCanvas(), which redraws the OLD
     client-side design from localStorage — so the passenger presents a
     ticket that does not match the one the office issued and WhatsApp'd.
     A mismatched ticket at an international border is worse than none.

     Cache-first, because at the border there is no network to revalidate
     against; when there IS a network the response is refreshed in the
     background so a reissue propagates. */
  if (url.origin === self.location.origin && isTicketImage(url)) {
    event.respondWith((async function () {
      var cache = await caches.open(TICKET_CACHE);
      var key   = ticketKey(url);
      var hit   = await cache.match(key);
      if (hit) {
        // refresh in the background, but never block the passenger on it
        event.waitUntil((async function () {
          try {
            var fresh = await fetch(req);
            if (fresh && fresh.ok) { await cache.put(key, fresh.clone()); }
          } catch (e) {}
        })());
        return hit;
      }
      try {
        var res = await fetch(req);
        if (res && res.ok) {
          await cache.put(key, res.clone());
          await trimCache(TICKET_CACHE, MAX_TICKETS);
        }
        return res;
      } catch (e) {
        // offline and never cached — let the page show its own fallback
        return new Response('', { status: 504, statusText: 'offline' });
      }
    })());
    return;
  }

  // Booking / admin / API surface — pure passthrough, never cached.
  if (url.origin === self.location.origin &&
      (url.pathname.indexOf('/api/') === 0 ||
       url.pathname.indexOf('/admin') === 0 ||
       url.pathname.indexOf('/download-ticket') === 0 ||
       url.pathname.indexOf('/invoice') === 0 ||
       url.pathname.indexOf('/uploads') === 0)) {
    return;
  }

  // Our own versioned app code → cache-first. Safe because the ?v= in the
  // URL changes on every build, so a cached copy is never the wrong build.
  if (url.origin === self.location.origin && url.pathname.indexOf('/assets/') === 0) {
    event.respondWith((async function () {
      var cache = await caches.open(ASSET_CACHE);
      var hit = await cache.match(req);
      if (hit) return hit;
      try {
        var res = await fetch(req);
        if (res && res.ok) { try { await cache.put(req, res.clone()); } catch (e) {} }
        return res;
      } catch (e) {
        return hit || netError();
      }
    })());
    return;
  }

  // Static map assets (cross-origin) → cache-first, capped.
  if (TILE_HOSTS.indexOf(url.hostname) !== -1) {
    event.respondWith((async function () {
      var cache = await caches.open(TILE_CACHE);
      var hit = await cache.match(req);
      if (hit) return hit;
      try {
        var res = await fetch(req);
        if (res && (res.ok || res.type === 'opaque')) {
          try { await cache.put(req, res.clone()); } catch (e) {}
          trimCache(TILE_CACHE, MAX_TILES);
        }
        return res;
      } catch (e) {
        return hit || netError();
      }
    })());
    return;
  }

  /* The offline desk (19 Sep 2026): fresh from the network when there is one,
     the precached copy when there is not. It is the one page whose whole job
     is to open with no signal, so it must never fall through to a net error. */
  if (url.origin === self.location.origin && url.pathname === '/offline-desk.html') {
    event.respondWith(fetch(req).catch(function () {
      return caches.open(ASSET_CACHE).then(function (c) { return c.match('/offline-desk.html'); })
        .then(function (r) { return r || netError(); });
    }));
    return;
  }

  // Same-origin document / SPA navigation → network-first, cache fallback.
  if (req.mode === 'navigate' ||
      (url.origin === self.location.origin && req.destination === 'document')) {
    /* Only the app itself is the "shell". This used to store EVERY navigated
       document under '/', so after opening verify-ticket.php or
       agent-signup.php an offline start of the app rendered that page
       instead (perf pass, 11 Sep 2026). */
    var isShell = url.origin === self.location.origin &&
                  (url.pathname === '/' || url.pathname === '/index.php');
    event.respondWith((async function () {
      var cache = null;
      try { cache = await caches.open(SHELL_CACHE); } catch (e) {}
      var shellPromise = (isShell && cache) ? cache.match('/').catch(function () { return null; }) : Promise.resolve(null);
      var netPromise = fetch(req).then(function (res) {
        if (isShell && cache && res && res.ok && res.type !== 'opaqueredirect') {
          try { event.waitUntil(cache.put('/', res.clone())); } catch (e) {}
        }
        return res;
      });
      /* Network-first, but not network-ONLY: on a slow border-town 3G the
         document used to be the one thing every open waited on, even
         though the whole bundle was already on disk. If the network has not
         answered within SHELL_WAIT_MS and a last-good shell exists, open on
         the shell now; the fetch above keeps running and refreshes the
         cache for the next open. A first visit (no shell) still waits. */
      try {
        var shell = await shellPromise;
        if (!shell) {
          try { return await netPromise; }
          catch (e0) {
            if (isShell) { var off = await offlinePage(); if (off) return off; }
            throw e0;
          }
        }
        var timer;
        var timeout = new Promise(function (resolve) {
          timer = setTimeout(function () { resolve('timeout'); }, SHELL_WAIT_MS);
        });
        var winner = await Promise.race([
          netPromise.then(function (r) { return r; }, function () { return 'error'; }),
          timeout
        ]);
        clearTimeout(timer);
        if (winner === 'timeout' || winner === 'error') {
          event.waitUntil(netPromise.catch(function () {}));
          return shell;
        }
        /* A 5xx mid-deploy (php-fpm reload, opcache window) used to paint the
           nginx error page over an app that was fully cached. */
        if (winner && winner.status >= 500) return shell;
        return winner;
      } catch (e) {
        var shell2 = cache ? await cache.match('/') : null;
        return shell2 || (cache ? await cache.match(req) : null) || (isShell ? await offlinePage() : null) || netError();
      }
    })());
    return;
  }

  // Everything else → default network passthrough (no respondWith).
});

/* The page can ask the worker to pre-download a batch of tile URLs so a
   planned route works fully offline. Bounded + best-effort; failures are
   swallowed so a flaky tile never blocks the rest. */
self.addEventListener('message', function (event) {
  var data = event.data || {};
  /* Auto-update: the page asks the freshly-installed worker to take over
     right now instead of waiting for every tab to close. Paired with
     clients.claim() in activate and a controllerchange reload on the page,
     this is what makes a new deploy reach an already-open app on its own. */
  if (data.type === 'shg-skip-waiting') {
    self.skipWaiting();
    return;
  }
  if (data.type === 'shg-prefetch' && Array.isArray(data.urls)) {
    event.waitUntil((async function () {
      var cache = await caches.open(TILE_CACHE);
      var done = 0, urls = data.urls;
      for (var i = 0; i < urls.length; i++) {
        try {
          var existing = await cache.match(urls[i]);
          if (!existing) {
            var r = await fetch(urls[i]);
            if (r && (r.ok || r.type === 'opaque')) { await cache.put(urls[i], r.clone()); }
          }
        } catch (e) {}
        done++;
      }
      await trimCache(TILE_CACHE, Math.max(MAX_TILES, urls.length + 200));
      try {
        var list = await self.clients.matchAll();
        list.forEach(function (c) { c.postMessage({ type: 'shg-prefetch-done', count: done, total: urls.length }); });
      } catch (e) {}
    })());
  } else if (data.type === 'shg-clear-tiles') {
    event.waitUntil(caches.delete(TILE_CACHE).catch(function () {}));

  /* Store one ticket for offline use. Population is message-driven ONLY —
     the fetch handler above caches what it is asked for, but nothing walks
     the network opportunistically. That matters on a shared office or
     counter device, where silently hoarding every ticket that passed
     through the browser would be a privacy problem. The page asks, once,
     for the ticket it just confirmed. */
  } else if (data.type === 'shg-cache-ticket' && typeof data.pnr === 'string' && data.pnr) {
    event.waitUntil((async function () {
      try {
        var u = new URL('/download-ticket.php', self.location.origin);
        u.searchParams.set('pnr', data.pnr);
        u.searchParams.set('img', '1');
        u.searchParams.set('view', '1');
        if (data.k) { u.searchParams.set('k', data.k); }

        var res = await fetch(u.toString(), { credentials: 'same-origin' });
        // Only a real image is worth keeping: an HTML error page or the
        // keyless 302 into #/my would otherwise be cached AS the ticket.
        var ok = res && res.ok &&
                 (res.headers.get('content-type') || '').indexOf('image/') === 0;
        if (ok) {
          var cache = await caches.open(TICKET_CACHE);
          await cache.put(ticketKey(u), res.clone());
          await trimCache(TICKET_CACHE, MAX_TICKETS);
        }
        var list = await self.clients.matchAll();
        list.forEach(function (c) {
          c.postMessage({ type: 'shg-ticket-cached', pnr: data.pnr, ok: !!ok });
        });
      } catch (e) {}
    })());

  /* Signing out on a shared device must not leave the last passenger's
     ticket readable by the next one. */
  } else if (data.type === 'shg-clear-tickets') {
    event.waitUntil(caches.delete(TICKET_CACHE).catch(function () {}));
  }
});

/* =====================================================================
 *  WEB PUSH (13 Sep 2026) - delay alerts, reminders, "ticket ready".
 *  The payload is JSON encrypted by includes/webpush.php: {title, body,
 *  url, tag, icon, badge, lang}. A tap focuses the open app (and routes it
 *  to the ticket) or opens a new window on it. pushsubscriptionchange
 *  re-registers a rotated endpoint with the server so a phone never goes
 *  silently quiet. Everything is guarded: a worker running where push is
 *  absent (the test harness, an old WebView) just never fires these.
 * ===================================================================== */
self.addEventListener('push', function (event) {
  var data = {};
  try { data = event.data ? (event.data.json() || {}) : {}; }
  catch (e) { try { data = { body: event.data ? event.data.text() : '' }; } catch (e2) { data = {}; } }
  if (!self.registration || typeof self.registration.showNotification !== 'function') return;
  var title = data.title || 'S Hari Global';
  var opts = {
    body: data.body || '',
    icon: data.icon || '/assets/img/icon-192.png',
    badge: data.badge || '/assets/img/icon-192.png',
    tag: data.tag || 'shg',
    renotify: !!data.tag,
    lang: data.lang || 'ne',
    data: { url: data.url || '/#/my' },
    vibrate: [120, 60, 120]
  };
  event.waitUntil(self.registration.showNotification(title, opts));
});

self.addEventListener('notificationclick', function (event) {
  try { event.notification.close(); } catch (e) {}
  var url = (event.notification && event.notification.data && event.notification.data.url) || '/#/my';
  event.waitUntil((async function () {
    try {
      var list = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
      for (var i = 0; i < list.length; i++) {
        var c = list[i];
        if (c.url && c.url.indexOf(self.location.origin) === 0) {
          try { c.postMessage({ type: 'shg-open', url: url }); } catch (e) {}
          if ('focus' in c) { await c.focus(); }
          return;
        }
      }
      if (self.clients.openWindow) { await self.clients.openWindow(url); }
    } catch (e) {}
  })());
});

self.addEventListener('pushsubscriptionchange', function (event) {
  event.waitUntil((async function () {
    try {
      var old  = event.oldSubscription || null;
      var opts = (old && old.options) || (event.newSubscription && event.newSubscription.options) || null;
      var sub  = event.newSubscription
        || (opts && self.registration && self.registration.pushManager ? await self.registration.pushManager.subscribe(opts) : null);
      if (!sub || !old) return;
      await fetch('/api/push.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'resubscribe', oldEndpoint: old.endpoint, subscription: sub.toJSON() })
      });
    } catch (e) {}
  })());
});
