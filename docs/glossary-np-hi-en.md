# One word list: Nepali, Hindi, English

S Hari Global, fx1 brand pass, 26 Nov 2026. Base: release commit d2cca50a.

The same thing must have the same name on the website, the ticket, WhatsApp,
the staff panel and the chalani. This list says which name wins.

- **Nepali** column: simple spoken Nepali in Devanagari. This is the default
  on tickets and staff screens.
- **Hindi** and **English** columns: the matching word for the other two
  languages of the app.
- **Avoid** column: other names for the same thing that are in the code today,
  with one or two places (`file:line`). They are the fix list. A word that is
  only in a WhatsApp-bot alias list (so the bot understands a customer who
  types it) is fine and stays.
- **(?)** = not sure. Either the code has no Devanagari form yet, or two forms
  are both common and the owner should choose. Nothing marked (?) should be
  rolled out before he has seen it.

Where the words were taken from: `assets/js/04-i18n.js` (1,490 keys, en at line
9, hi at 608, ne at 1192), the menu labels in `admin/_guard.php` `admin_nav()`
(lines 80-205), and a count of every variant across the tracked `php / js /
html` files (tests, docs and vendor code left out). Counts are "uses in files".

## 1. The eight hubs (fixed by the lead, do not change)

| Hub | Nepali · English |
|---|---|
| 1 | गृह · Home |
| 2 | टिकट काट्ने · Book ticket |
| 3 | टिकटहरू · Tickets |
| 4 | बस र चलानी · Buses & Chalani |
| 5 | मान्छे · People |
| 6 | पैसा · Money |
| 7 | रिपोर्ट · Reports |
| 8 | सेटिङ · Settings |

Hindi for the same eight, for the customer app only: होम · टिकट बुक करें · टिकट ·
बस और चलानी · लोग (?) · पैसा · रिपोर्ट · सेटिंग.

## 2. Ticket and booking

| Concept | Nepali (use this) | Hindi | English | Avoid (found in code) |
|---|---|---|---|---|
| Ticket | टिकट | टिकट | Ticket | टिकेट (bot alias only, `includes/wabot.php:1369`: fine) |
| Book / cut a ticket | टिकट काट्ने (button: टिकट काट्नुहोस्) | टिकट बुक करें | Book ticket | "टिकट बुक गर्नुहोस्" `assets/js/04-i18n.js:1194`, `:1267`; "Book Tickets" `assets/js/04-i18n.js:11`; "Book a ticket" `assets/js/04-i18n.js:20`; "QuickBot Ticket (10s)" `admin/_guard.php:132`; "+ Counter Booking" `admin/_guard.php:139` |
| Booking (the record) | बुकिङ | बुकिंग | Booking | English "booking" inside a Nepali line `assets/js/04-i18n.js:1207`; "book" `:1272` |
| My tickets | मेरो टिकट | मेरे टिकट | My tickets | "My Bookings / मेरो बुकिङ" `assets/js/04-i18n.js:12`, `:1195` beside "My Tickets / मेरो टिकट" `:378`, `:1552` |
| Tickets (the list) | टिकटहरू | टिकट | Tickets | "Tickets Register" `admin/_guard.php:138`; "WhatsApp Tickets" `:144` |
| PNR | PNR (टिकट नम्बर) | PNR (टिकट नंबर) | PNR | "Booking ID" `assets/js/04-i18n.js:331`; "Booking number (PNR)" `:103`; "Booking ID (PNR)" `:424`; "Ticket no." `admin/booking-view.php:2636` |
| Passenger | यात्रु | यात्री | Passenger | Nepali "यात्री" `assets/js/04-i18n.js:1203`, `:1221`; `admin/quick-ticket.php:475`; "Pax" on a visible label |
| Passenger name | यात्रुको नाम | यात्री का नाम | Passenger name | |
| Mobile number | मोबाइल नम्बर | मोबाइल नंबर | Mobile number | |
| Journey date | यात्रा मिति | यात्रा की तारीख़ | Journey date | Hindi "तिथि" `assets/js/04-i18n.js:616` beside "तारीख़" `:792` |
| One-way / round trip | एकतर्फी / जाने-फर्कने | एक तरफ़ा / आना-जाना | One-way / Round trip | |
| Quick ticket | क्विक टिकट | क्विक टिकट | Quick ticket | "QuickBot" on staff screens `admin/_guard.php:132`, `admin/agent.php:1284` (a nickname, 138 uses) |
| Confirmed | पक्का भयो | पक्का हो गया | Confirmed | "Confirm गरी" `assets/js/04-i18n.js:1287`, `:1299`; Hindi "कन्फ़र्म" `:637` beside "कन्फर्म" `:915` |
| Cancel | रद्द गर्ने | रद्द करना | Cancel | क्यान्सिल (bot alias only: fine) |
| Cancel request | रद्द अनुरोध | रद्द करने का अनुरोध | Cancel request | "Cancellation" mixed in: "Refunds & Cancellations" `admin/_guard.php:142` |
| Refund | पैसा फिर्ता | रिफंड | Refund | Nepali "रिफन्ड" `includes/homeboard.php:97`, `includes/helpdesk.php:47`; Hindi "रिफ़ंड" `assets/js/04-i18n.js:993` beside "रिफंड" `:1008` |
| Refund request | पैसा फिर्ता अनुरोध | रिफंड अनुरोध | Refund request | |
| Date change | मिति सार्ने (?) | तारीख़ बदलना | Date change | "मिति परिवर्तन" `includes/ticketrequests.php:63`; "मिति बदल्ने" `includes/waedit.php:409`; "Change Date / Reschedule" `admin/_guard.php:140` |
| Date / seat request | मिति / सिट अनुरोध | तारीख़ / सीट अनुरोध | Date / seat request | "यात्रु अनुरोध · Date / Seat Requests" `admin/_guard.php:143` |
| Download ticket | टिकट डाउनलोड | टिकट डाउनलोड | Download ticket | |
| Booking fee | बुकिङ शुल्क | बुकिंग शुल्क | Booking fee | |

(?) Date change: "मिति सार्ने" is what people say and is on the staff home
(`includes/homeboard.php:103`); "मिति परिवर्तन" is the label of the request type
(10 uses). Pick one.

## 3. Bus and seat

| Concept | Nepali (use this) | Hindi | English | Avoid (found in code) |
|---|---|---|---|---|
| Bus | बस | बस | Bus | "coach" for the same thing: "Available coaches" `assets/js/04-i18n.js:230` |
| Sleeper | स्लिपर | स्लीपर | Sleeper | Nepali "स्लीपर" `assets/js/04-i18n.js:1201`, `:1209` beside "स्लिपर" `:1392`, `:1555` |
| Seat | सिट | सीट | Seat | |
| Berth | बर्थ (only when describing the bed) | बर्थ | Berth | The seat screen is titled "Choose your seats" `assets/js/04-i18n.js:243` and "Choose your berth" `:405`; "Seat / Berth Layout Map" `admin/_guard.php:151` |
| Upper | माथिल्लो तला (2F) | ऊपरी मंज़िल (2F) | Upper floor (2F) | "Upper Deck" `admin/trip-dashboard.php:562` |
| Lower | तल्लो तला (1F) | निचली मंज़िल (1F) | Lower floor (1F) | "Lower Deck" `admin/trip-dashboard.php:561`; Hindi "नीचे (1F)" `includes/autoberth.php:700` |
| Single | सिङ्गल | सिंगल | Single | Nepali "सिंगल" `assets/js/04-i18n.js:1741`, `admin/manifest.php:553`; "एकल" `assets/js/06-results.js:2041` |
| Double | डबल | डबल | Double | |
| Cabin | क्याबिन (?) | केबिन | Cabin | Nepali has both: "क्याबिन" `assets/js/04-i18n.js:1243`, `:1256`; "केबिन" `:1439`, `:1441` |
| Sharing / private | सेयरिङ / प्राइभेट | शेयरिंग / प्राइवेट | Sharing / Private | "निजी केबिन" `assets/js/04-i18n.js:1424` beside "प्राइभेट" `:1415` |
| Women-only cabin | महिला मात्र क्याबिन | सिर्फ़ महिला केबिन | Women-only cabin | "महिलाका लागि आरक्षित" `assets/js/04-i18n.js:1423` beside "महिला मात्र" `:1424` |
| Extra seat | थप सिट (EX) | अतिरिक्त सीट (EX) | Extra seat (EX) | |
| Seats left | सिट बाँकी | सीटें बाकी | Seats left | Hindi "सीटें शेष" `assets/js/04-i18n.js:829` beside "सीटें बाकी" `:712` |
| Sold out | भरिभराउ (?) | हाउसफुल | Sold out | |
| Hold | होल्ड | होल्ड | Hold | "रोकियो / रोकेको" `assets/js/04-i18n.js:1244`, `:1424`: the same word means "bus stopped" in `assets/nav/nav-live.js:115` |
| Seat map | सिट नक्सा | सीट मैप | Seat map | "सिट म्याप" `assets/js/04-i18n.js:1272` beside "सिट नक्सा" `admin/seatmap.php:597` |
| Bus number | बस नम्बर | बस नंबर | Bus number | |
| Bus routine | बस तालिका (?) | बस समय-सारणी (?) | Bus routine | Two different pages with one name: "Bus Routine (alternate days)" `admin/_guard.php:145` and "Bus Routine (3 Bus · Live)" `:147`, plus "Bus Calendar" `:146` |

## 4. Trip and places

| Concept | Nepali (use this) | Hindi | English | Avoid (found in code) |
|---|---|---|---|---|
| Pickup point | चढ्ने ठाउँ | चढ़ने की जगह | Pickup point | "पिकअप पोइन्ट" `assets/js/04-i18n.js:1426`; "बोर्डिङ पोइन्ट" `:1692`; English "pickup" in a Nepali line `:1302`; Hindi left as "Boarding point" `:711`, "बोर्डिंग" `:830`, "चढ़ने का स्थान" `:847`; English "Boarding point" (133 uses) beside "Pickup point" (53) |
| Drop point | झर्ने ठाउँ | उतरने की जगह | Drop point | "ओर्लने ठाउँ" `assets/js/04-i18n.js:1431`, `:1434` (the ticket prints "झर्ने ठाउँ", `includes/ticketclean.php:523`, and `includes/ticket.php:2741` explains why); "ड्रप" `admin/routes.php:1250`; Hindi "ड्रॉप पॉइंट" `assets/js/04-i18n.js:1155` |
| Pickup time | चढ्ने समय | चढ़ने का समय | Pickup time | |
| Departure | छुट्ने समय (?) | छूटने का समय | Departure | "प्रस्थान" `assets/js/04-i18n.js:1690`, `:1716` (56 uses); Hindi "रवाना" `:711`, "रवानगी" `:1181`, "प्रस्थान" `:1106` |
| Route | रुट | रूट | Route | "मार्ग" `assets/js/04-i18n.js:1395` |
| Trip | ट्रिप | ट्रिप | Trip | |
| India to Nepal | नेपाल जाने | नेपाल जाना | Going to Nepal | "Gujarat → Rupaidiha" used as the direction name `app.template.html:1398`; "Going — India to Nepal" `assets/js/04-i18n.js:209` |
| Nepal to India | गुजरात फर्कने | गुजरात वापसी | Returning to Gujarat | "Rupaidiha → Gujarat" `app.template.html:1403`; "Coming — Nepal to India" `assets/js/04-i18n.js:210`; "Return" `admin/quick-ticket.php:97` |
| Border | बोर्डर (?) | बॉर्डर | Border | Three Nepali words: "बोर्डर" `assets/js/04-i18n.js:1733`, "सिमाना" `:1748`, "सीमा" `:1193` |
| Border ID | बोर्डर परिचयपत्र (?) | बॉर्डर पहचान पत्र (?) | Border ID | English only today: `admin/manifest.php:1756`, `includes/chalanipdf.php:258` |
| ID document | परिचयपत्र | पहचान पत्र | ID | Hindi left as "ID" `assets/js/04-i18n.js:869` |
| Luggage | सामान | सामान | Luggage | लगेज, झोला (bot aliases only, `includes/aiops.php:506`: fine) |
| Parcel | पार्सल | पार्सल | Parcel | |
| Customs | भन्सार | कस्टम | Customs | |
| Map | नक्सा | मैप | Map | "म्याप" `assets/js/04-i18n.js:1272` |
| Office | कार्यालय (?) | कार्यालय | Office | Nepali "अफिस" `assets/js/04-i18n.js:1322`, `admin/agent-quick.php:58` (281 uses, against 504 for कार्यालय); Hindi "ऑफ़िस" `assets/js/04-i18n.js:621` beside "कार्यालय" `:771` |
| Head office | मुख्य कार्यालय | मुख्य कार्यालय | Head office | "हेड अफिस" `admin/report-problem.php:110` |
| Counter | काउन्टर | काउंटर | Counter | "Desk" for the same thing: "Desk · काउन्टर" `admin/manifest.php:1476` |
| Help | सहायता | मदद | Help | Hindi "सहायता" `assets/js/04-i18n.js:968` beside "मदद" `:619`; "हेल्प डेस्क" `:988` beside "सहायता केंद्र" `:993` |

(?) Office: people say "अफिस"; the customer app and the lead's sample write
"कार्यालय". Both are everywhere. Choose one for Nepali.
(?) Departure: "छुट्ने समय" is the spoken form. Always write it with "समय" or
"बस", because "छुट" alone is "discount" (the bot code already guards this,
`includes/aiagent.php:718`). If that risk is not wanted, keep "प्रस्थान".

## 5. Money

| Concept | Nepali (use this) | Hindi | English | Avoid (found in code) |
|---|---|---|---|---|
| Fare | भाडा | किराया | Fare | "Ticket price" |
| Total fare | कुल भाडा | कुल किराया | Total fare | |
| Normal fare | सामान्य भाडा | सामान्य किराया | Normal fare | |
| Discount | छुट | छूट | Discount | डिस्काउन्ट (bot alias only: fine) |
| Cash | नगद | नकद | Cash | "क्यास" `includes/homeboard.php:102`, `admin/home.php:393` |
| Online | अनलाइन | ऑनलाइन | Online | |
| Pay at boarding | बसमा तिर्ने | बस में चढ़ते समय भुगतान (?) | Pay at boarding | One status (`cod_pending`), five labels: "PAY ON BOARDING" `includes/ticket.php:3638`, `pay.php:560`; "Cash on boarding" `includes/customerbook.php:58`; "Cash due (COD)" `admin/bookings.php:636`; "Pay at counter" `admin/_guard.php:1441`, `assets/js/04-i18n.js:307`; "बसमा भुक्तानी" `chalani-live.php:360`; "चढ्दा तिर्नुहोस्" `assets/js/04-i18n.js:1603` |
| Paid | तिरेको (ticket stamp: भुक्तानी भयो · PAID) | भुगतान हो गया | Paid | "भुक्तानी प्रमाणित" for PAID `assets/js/04-i18n.js:1193` (that means "verified", a different step) |
| Due | बाँकी | बाकी | Due | "Pending amount" `assets/js/04-i18n.js:10`; Hindi "बकाया" `:609` beside "बाकी" `:1019`; "Outstanding" on agent pages |
| Payment | भुक्तानी (to pay: तिर्ने) | भुगतान | Payment | |
| Verify payment | भुक्तानी जाँच | भुगतान जाँच | Verify payment | "प्रमाणीकरण" `assets/js/04-i18n.js:1193` and "प्रमाणिकरण" `:1497` (two spellings of one word); Hindi "सत्यापन" `:609` beside "जाँच" `:637` |
| Payment being checked | भुक्तानी जाँचमा छ | भुगतान की जाँच हो रही है | Payment being checked | "Payment Under Verification" `assets/js/04-i18n.js:322`; "प्रक्रियामा" `:1630` |
| Payment rejected | भुक्तानी अस्वीकृत | भुगतान अस्वीकृत | Payment rejected | |
| QR | QR | QR | QR | |
| Scan and pay | स्क्यान गरेर तिर्नुहोस् | स्कैन करके भुगतान करें | Scan & pay | |
| Money signs | रू (Nepal) · ₹ (India) | रू · ₹ | NPR रू · INR ₹ | |
| Commission | कमिसन | कमीशन | Commission | Nepali "कमिशन" `includes/waonboard.php:81` |
| Settlement | हिसाब मिलान | हिसाब मिलान | Settlement | "सेटलमेन्ट" `admin/staff-chat.php:378`; "Commission & Wallet Settlements" `admin/_guard.php:166` |
| Credit limit | उधारो सीमा (?) | उधार सीमा (?) | Credit limit | Only "सीमा" `admin/agent-360.php:1638`; English everywhere else |
| Statement | स्टेटमेन्ट | स्टेटमेंट | Statement | Three different things share the word: "Ticket Statement" `admin/_guard.php:109`, "Monthly Statements" `:167`, bank statement `admin/payments.php:1356` |
| Wallet | वालेट | वॉलेट | Wallet | "ब्यालेन्स" as a menu word `admin/agent.php:783` |
| Cash book | नगद खाता (?) | कैश बुक | Cash book | "क्यास बुक" `includes/homeboard.php:102` |
| Expense | खर्च | खर्च | Expense | |
| Day memo | दैनिक हिसाब | दैनिक हिसाब | Day memo | |

## 6. People

| Concept | Nepali (use this) | Hindi | English | Avoid (found in code) |
|---|---|---|---|---|
| Agent | एजेन्ट | एजेंट | Agent | "Agent code" left in English inside Nepali and Hindi `assets/js/04-i18n.js:1290`, `:706` |
| Sub-agent | सब-एजेन्ट (?) | सब-एजेंट (?) | Sub-agent | No Devanagari form in the code; "Master & Sub-Agent Portal" `admin/_guard.php:95` |
| Staff | स्टाफ | स्टाफ | Staff | "कर्मचारी" in menus (it is right in the legal agreement, `agreements/apply.html:1897`) |
| Driver | चालक | ड्राइवर | Driver | Nepali "ड्राइभर" `admin/agent-quick.php:55`, `includes/payroll.php:48`; Hindi "चालक" `assets/js/04-i18n.js:905` beside "ड्राइवर" `:741` |
| Conductor | कन्डक्टर | कंडक्टर | Conductor | "सहचालक" / "CREW" on the ID card `includes/agentpro-card.php:1399`; "Bus Crew" `admin/_guard.php:104` |
| Helper | खलासी | हेल्पर | Helper | Not the same job as conductor (`includes/payroll.php:46-47`): keep both |
| Customer | ग्राहक | ग्राहक | Customer | "Customer CRM" `admin/_guard.php:157` |
| People (hub) | मान्छे | लोग (?) | People | "टिम · Agents & Staff" `admin/_guard.php:80`; "Agents & Staff · एजेन्ट र स्टाफ" `:89`; "Team & ID Cards" `:92` |
| ID card | परिचयपत्र | ID कार्ड | ID card | |
| Sign in | लगइन | लॉगिन | Sign in | English "Sign in" in the customer app `assets/js/04-i18n.js:13` beside "Easy login" `admin/_guard.php:99` |
| Password / PIN / code | पासवर्ड / PIN / कोड | पासवर्ड / PIN / कोड | Password / PIN / Code | |

## 7. Bus work

| Concept | Nepali (use this) | Hindi | English | Avoid (found in code) |
|---|---|---|---|---|
| Chalani | चलानी | चलानी (?) | Chalani | "Manifest & Chalani" `admin/_guard.php:153`; "Bus Chalani" `admin/_chalani-png-view.php:83`; "Challan" (class and file names such as `admin/chalan.php:79` can stay; never on a screen) |
| Passenger list | यात्रु विवरण | यात्री सूची | Passenger list | "Manifest" on a screen |
| Live bus | बस कहाँ छ? | बस कहाँ है? | Live bus | "बस ट्र्याक गर्नुहोस् / Track Bus" `assets/js/04-i18n.js:1269`, `:87`; "बस लाइभ हेर्नुहोस्" `:1204`; "SHARI NAV Live Bus Radar" `admin/_guard.php:122`; "Passenger Live Radar Map" `:125` |
| Live | लाइभ | लाइव | Live | |
| Bus is late | बस ढिलो छ | बस देर से है | Bus delayed | |
| Emergency | आपतकालीन (SOS) | आपातकाल (SOS) | Emergency (SOS) | |
| Scan boarding QR | चढ्दा QR स्क्यान (?) | बोर्डिंग QR स्कैन (?) | Scan boarding QR | English only `admin/_guard.php:155` |

## 8. Places, as the company spells them

| English | Nepali | Hindi | Avoid (found in code) |
|---|---|---|---|
| Nepal · India | नेपाल · भारत | नेपाल · भारत | |
| Gujarat | गुजरात | गुजरात | |
| Nepalgunj | नेपालगञ्ज | नेपालगंज | Nepali "नेपालगन्ज" (28 uses in 8 files) `includes/content/indianepal.php:1231`, `assets/js/terms-data.js`; "Nepalganj" only as a typed alias (`includes/boarding.php:60`: fine) |
| Rupaidiha | रुपैडिहा | रुपईडीहा | Five Devanagari forms in the code: "रूपैडिहा" `navigate.html:4794`; "रुपैडीहा" `assets/js/04-i18n.js:614`, `includes/promocard.php:27`; "रूपईडीहा" `travel-info.php:179`, `includes/routepages.php:179` |
| Ahmedabad (Nana Chiloda) | अहमदाबाद (नाना चिलोडा) | अहमदाबाद (नाना चिलोडा) | "अहमदावाद", "Amdavad" (aliases only: fine) |
| Mahesana **(?)** | महेसाणा (?) | महेसाणा (?) | The brief says "Mahesana". The code says "Mehsana" 187 times in 70 files and "Mahesana" 0 times; Devanagari "मेहसाणा" 40 uses (`navigate.html:4541`), "मेहसाना" 6, "महेसाणा" 6. Owner decides; whichever wins is a rename across about 70 files or none |
| Surat | सुरत | सूरत | (already right in `includes/routepages.php:178`) |
| Baroda **(?)** | बडोदा (?) | बड़ौदा (?) | The brief says "Baroda". The code shows "Vadodara" 57 times (pickup label "Vadodara · Golden Chokdi" `assets/js/02-config.js:1256`; Devanagari "वडोदरा" `includes/routepages.php:183`) and "Baroda" 28 times (`includes/boarding.php:48`). No customer-facing Devanagari "Baroda" exists |
| Rajkot | राजकोट | राजकोट | |
| Emli Bhupal **(?)** | इम्ली भूपाल (?) | इमली भूपाल (?) | Latin only on screens (`assets/js/02-config.js:1253`). Devanagari exists only as bot aliases "इमली भूपाल", "एमली भूपाल" `includes/ticketbot.php:2060` |

## 9. Inconsistencies found (the lead's fix list)

The 20 most visible places where one thing has two names, or a label is
English only.

1. **"Book ticket" has six names.** Hub: टिकट काट्ने · Book ticket. Customer app:
   "टिकट बुक गर्नुहोस्" `assets/js/04-i18n.js:1194`, `:1267`; "Book Tickets" `:11`;
   "Book a ticket" `:20`; staff menu "🤖 QuickBot Ticket (10s)" `admin/_guard.php:132`
   and "+ Counter Booking" `admin/_guard.php:139`; Nepal desk "नयाँ टिकट काट्नुहोस्"
   `admin/nepal-counter.php:257`.
2. **One list, two names:** "My Bookings / मेरो बुकिङ" `assets/js/04-i18n.js:12`, `:1195`
   and "My Tickets / मेरो टिकट" `assets/js/04-i18n.js:378`, `:1552`.
3. **PNR has four names:** "Booking ID" `assets/js/04-i18n.js:331`; "Booking number
   (PNR)" `:103`; "Booking ID (PNR)" `:424`; "Ticket no." `admin/booking-view.php:2636`.
4. **Passenger in Nepali:** "यात्रु" `assets/js/04-i18n.js:1219` and "यात्री"
   `assets/js/04-i18n.js:1203`, `:1221`, `admin/quick-ticket.php:475`.
5. **Pickup point:** "चढ्ने ठाउँ" `assets/js/04-i18n.js:1431`, "पिकअप पोइन्ट" `:1426`,
   "बोर्डिङ पोइन्ट" `:1692`, English "pickup" `:1302`; Hindi never translated:
   "Boarding point" `:711`.
6. **Drop point:** the app says "ओर्लने ठाउँ" `assets/js/04-i18n.js:1431`, the ticket
   says "झर्ने ठाउँ" `includes/ticketclean.php:523`, the staff panel says "ड्रप"
   `admin/routes.php:1250`.
7. **Pay at boarding:** one booking status, five labels. `includes/ticket.php:3638`
   "PAY ON BOARDING"; `includes/customerbook.php:58` "Cash on boarding";
   `admin/bookings.php:636` "Cash due (COD)"; `admin/_guard.php:1441` "Pay at counter";
   `chalani-live.php:360` "बसमा भुक्तानी".
8. **Paid and verified are mixed, and "verify" has two spellings:** "PAID · Verified"
   = "भुक्तानी प्रमाणित" `assets/js/04-i18n.js:10`, `:1193`; "प्रमाणीकरण" `:1193`,
   "प्रमाणिकरण" `:1497`, "जाँच" `:1221`.
9. **Seat or berth:** the same screen is "Choose your seats" `assets/js/04-i18n.js:243`
   and "Choose your berth" `assets/js/04-i18n.js:405` (Nepali `:1422` "सिटहरू", `:1578` "बर्थ").
10. **Nepali spellings of loan words:** "स्लीपर" `assets/js/04-i18n.js:1201` / "स्लिपर"
    `:1392`; "क्याबिन" `:1243` / "केबिन" `:1441`; "सिङ्गल" `:1439` / "सिंगल" `:1741`;
    "प्राइभेट" `:1415` / "निजी" `:1424`.
11. **Office:** "कार्यालय" `assets/js/04-i18n.js:1205`, "अफिस" `:1322` and
    `admin/agent-quick.php:58`; Hindi "ऑफ़िस" `assets/js/04-i18n.js:621`, "कार्यालय" `:771`.
12. **Driver and conductor:** "चालक" `admin/_guard.php:105`, "ड्राइभर"
    `admin/agent-quick.php:55`, `includes/payroll.php:48`; conductor is "कन्डक्टर"
    `admin/agent-quick.php:56` but "सहचालक" / "CREW" on the ID card
    `includes/agentpro-card.php:1399`, and "Drivers & Bus Crew" `admin/_guard.php:104`.
13. **Refund:** "फिर्ता" `assets/js/04-i18n.js:1592`, "रिफन्ड" `includes/homeboard.php:97`;
    Hindi "रिफंड" `assets/js/04-i18n.js:1008`, "रिफ़ंड" `:993`.
14. **Date change has four names:** "Change Date / Reschedule" `admin/_guard.php:140`;
    "मिति परिवर्तन" `includes/ticketrequests.php:63`; "मिति सार्नुहोस्"
    `includes/homeboard.php:103`; "मिति बदल्ने" `includes/waedit.php:409`.
15. **Live bus has five names:** "मेरो बस कहाँ छ?" and "बस लाइभ हेर्नुहोस्"
    `assets/js/04-i18n.js:1204`; "बस ट्र्याक गर्नुहोस्" `:1269`; "SHARI NAV Live Bus
    Radar" `admin/_guard.php:122`; "Passenger Live Radar Map" `admin/_guard.php:125`.
16. **Home:** hub "गृह · Home" `admin/_guard.php:209`, but "होम" in the customer app
    `assets/js/04-i18n.js:1194`, `:1552`, "Dashboard Overview" `admin/_guard.php:86`,
    "Control Center" `admin/_guard.php:91`. Four pages are a "Center": `:91`, "GPS
    Control Center" `:124`, "AI Command Center" `:192`, "AI Control Center" `:193`.
17. **People:** hub "मान्छे · People", menu "टिम · Agents & Staff" `admin/_guard.php:80`,
    "Agents & Staff · एजेन्ट र स्टाफ" `:89`, "Team & ID Cards" `:92`.
18. **Chalani:** "Manifest & Chalani" `admin/_guard.php:153`, "Bus Chalani"
    `admin/_chalani-png-view.php:83`, "बस चलानी · यात्रु विवरण" `admin/manifest.php:1073`.
    Screens should say only चलानी · Chalani.
19. **Place names:** Rupaidiha is written five ways in Devanagari (section 8);
    "नेपालगन्ज" `includes/content/indianepal.php:1231` beside "नेपालगञ्ज"; the brief's
    "Mahesana" and "Baroda" are "Mehsana" (187 uses) and "Vadodara" (57 uses) in the
    code, e.g. `assets/js/02-config.js:1256`, `includes/boarding.php:48`.
20. **English-only labels and English left inside Nepali.** Menu rows with no
    Nepali: "Dashboard Overview" `admin/_guard.php:86`, "Tickets Register" `:138`,
    "Refunds & Cancellations" `:142`, "Manifest & Chalani" `:153`, "Scan Boarding QR"
    `:155`, "Verify Payments (UPI/eSewa)" `:162`, "Cash Book" `:170`. Two different
    pages are both "Bus Routine" `:145`, `:147`. Nepali lines that still hold English
    words: "booking" `assets/js/04-i18n.js:1207`, "book" `:1272`, "Confirm गरी" `:1287`,
    "Agent code" `:1290`.

Also seen, smaller: "Upper / Lower Deck" `admin/trip-dashboard.php:561-562` against
"Upper / Lower Floor" `includes/seats.php:3283`; "क्यास बुक" `includes/homeboard.php:102`;
Nepali "कमिशन" `includes/waonboard.php:81`; "Desk · काउन्टर" `admin/manifest.php:1476`;
three Nepali words for border (`assets/js/04-i18n.js:1193`, `:1733`, `:1748`);
"होल्ड" `assets/js/04-i18n.js:1436` against "रोकियो" `:1244`; Hindi "कन्फ़र्म" `:637`
against "कन्फर्म" `:915`.

## 10. Still to decide: everything marked (?)

| Word | Why it is open |
|---|---|
| Mahesana / Mehsana | The brief and the code disagree (0 against 187 uses) |
| Baroda / Vadodara | The brief and the code disagree (28 against 57 uses) |
| Emli Bhupal in Devanagari | No screen shows it in Devanagari yet |
| कार्यालय / अफिस | Both very common in Nepali text (504 against 281) |
| क्याबिन / केबिन | Both in the Nepali strings |
| छुट्ने समय / प्रस्थान | Spoken form against the form in the app; "छुट" also means discount |
| मिति सार्ने / मिति परिवर्तन | Spoken form against the request label |
| बोर्डर / सीमा / सिमाना | Three words in use; "बोर्डर" is what passengers say |
| भरिभराउ for "sold out" | Found in the app; means "packed", may read as informal |
| Border ID, credit limit, sub-agent, cash book, bus routine, scan boarding QR in Nepali | No Devanagari form in the code today; the forms given are proposals |
| "Pay at boarding" in Hindi | Hindi strings describe it in a sentence; no short label exists |
| "People" hub in Hindi (लोग) | Proposal; the hub names were fixed in Nepali and English only |
| चलानी in Hindi | The company word is used as-is; Hindi readers may expect "चालान" |
