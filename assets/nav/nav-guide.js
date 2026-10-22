/* =====================================================================
   SHARI NAV · assets/nav/nav-guide.js  (21 Oct 2026)

   Add-on for navigate.html. Loads after the page and talks to it only
   through window.SHGNAV_X (a small bridge at the end of navigate.html's
   script). If the bridge is missing, this file does nothing.

   What it adds (ideas taken from Google Maps "Ask Maps" / Gemini
   navigation and Apple Maps iOS 26, rebuilt for the India-Nepal road):
     · SHARI Guide   — ask in नेपाली / हिन्दी / English, by text or voice.
                       Finds places from our own index (/api/nav-search.php)
                       and answers through the site's AI (/api/ai-chat.php,
                       key stays on the server). Works without AI too.
     · Tirtha        — curated Hindu dhams of Nepal + India with darshan
                       notes, map pins, one-tap navigation.
     · Border        — India-Nepal crossing assistant: notices when a route
                       crosses the border, documents, INR <-> NPR, posts.
     · SOS           — the right emergency numbers for the country you are
                       in, nearest hospital / police, send my location.
     · Landmark hint — "near <named place>" under the turn banner.
     · Parking + Visits (Apple Maps style) — kept on this phone only.
   No Google API, no new server tables.
===================================================================== */
(function () {
'use strict';

var X = null;                       /* the bridge, set in start() */
var GV = '20261022e';

/* ---------------------------------------------------------------------
   DATA
--------------------------------------------------------------------- */
/* Nepal outline (geoBoundaries ADM0, simplified to ~0.006°), delta-encoded ×1000 */
var NPL_ENC = '80608,30473,-39,-5,-42,-43,14,-10,34,27,39,4,26,-55,17,-13,11,-33,90,-39,6,-27,109,-118,7,-32,-34,-13,-4,-17,-34,-9,-27,-33,-18,-7,-14,-43,-28,-7,-41,-41,-30,-3,-18,9,-35,-12,-21,-30,-2,-28,-20,-45,-45,-27,-14,-27,-59,10,-27,-12,-10,-35,-32,-11,19,-74,30,-20,6,-15,-15,-26,3,-18,-29,-24,0,-10,-16,3,1,-7,-22,-4,0,-13,16,-7,-9,-14,-35,-20,-1,-14,-14,6,-13,-14,16,-24,-22,-2,-4,8,-7,-4,5,-5,-31,-6,-2,-32,33,-19,5,-14,-8,-14,9,-19,16,-22,21,-9,-17,-73,-10,-10,7,-27,-10,-10,-39,26,-8,-19,18,-15,13,-45,-38,-24,-50,19,-39,-34,-16,-42,8,-2,-12,-46,11,-6,-9,-1,-8,-24,-18,4,-42,-70,6,-79,11,-13,43,5,98,-74,35,2,14,-14,-9,-14,21,-19,40,-10,9,-34,45,-37,43,10,37,-10,13,-14,4,-32,13,2,42,-32,7,19,-9,5,-15,94,34,20,50,-11,-10,-19,17,-5,-8,-8,17,4,5,-13,30,4,10,-9,16,8,12,-22,-2,-16,18,-1,-5,-16,24,-19,10,7,14,-9,0,8,30,-8,1,-16,20,-7,0,-19,26,-3,29,-26,21,-5,-4,14,15,6,32,-12,-7,-12,14,-28,-1,10,16,-10,12,10,-10,-9,19,-3,0,-9,29,8,-15,-10,14,-11,-1,8,11,0,10,12,18,-2,-4,-14,6,-6,10,6,5,-10,-18,-12,16,-17,3,14,50,-30,125,-22,24,-36,-2,-36,88,-92,1,-28,-19,-3,26,-18,-10,-3,0,-12,53,9,7,13,-11,10,7,12,71,-15,5,-21,34,-23,-6,-36,146,-81,74,-13,107,-85,28,-5,52,-41,41,3,-1,16,43,53,103,-6,52,-58,27,-7,4,8,54,-23,96,-71,63,-29,35,-40,47,-24,24,-3,43,14,25,-4,64,20,14,-4,45,20,17,-8,27,8,-2,-19,12,-16,-6,-22,24,-22,-1,-34,21,-21,-9,-33,-9,-3,11,-29,-12,-5,-2,-16,194,-1,25,-33,80,-19,154,5,30,-37,54,-34,-6,-18,29,-32,48,1,45,42,-3,14,25,23,-26,23,18,21,-13,22,226,-10,215,-99,33,-24,25,12,18,25,-1,7,-32,2,-4,37,62,20,47,-10,18,7,31,-13,25,10,19,39,25,7,2,28,11,5,15,-11,22,8,3,-33,57,-17,3,-27,11,-2,12,16,22,-4,24,-37,-1,-20,15,-10,9,5,91,-22,49,2,29,-15,7,7,146,-26,68,-121,-10,-15,4,-20,-10,-9,12,-13,-17,-48,5,-14,-29,-21,-3,-29,114,-43,16,15,21,-22,18,25,14,0,29,-13,1,-22,36,-16,71,-10,15,-20,-10,-24,32,-4,2,-9,-9,0,11,-8,56,-8,-31,-12,-7,-20,65,-2,0,14,24,5,79,-2,1,-39,-14,-22,35,-51,28,4,95,-21,21,19,19,0,33,31,47,-9,23,10,2,10,16,-5,2,15,22,2,23,24,23,3,10,20,9,-14,39,26,11,-1,8,-19,78,-33,14,-24,-11,-109,2,-17,11,-6,-3,-14,36,-20,32,-6,16,-22,13,-1,0,10,20,-3,7,-18,-7,-22,37,15,4,11,37,22,19,-3,13,38,15,7,34,-4,17,12,112,-37,-3,-23,57,8,3,-20,22,-6,17,2,18,27,81,2,11,-3,-4,-10,56,-13,-2,-8,61,-17,42,-26,44,-4,29,-42,50,-12,13,-20,60,-13,6,-14,21,-2,10,-13,16,6,-3,7,23,24,67,-20,27,18,33,5,-2,24,38,3,3,27,83,16,27,36,2,19,27,-2,20,-135,71,-46,27,1,-4,9,26,-1,7,-10,10,10,28,-2,10,-6,-1,-31,47,-3,28,-24,18,14,-2,27,12,20,98,32,81,-22,5,-13,35,-13,0,-10,17,-2,8,10,39,3,-1,13,28,9,-1,20,34,-8,22,-20,6,10,28,-6,9,11,-3,28,15,19,41,-19,-1,-10,20,-4,13,13,-3,14,27,13,3,10,21,-29,-11,-9,26,-3,-8,-13,10,-14,65,-26,1,-23,15,-8,23,4,4,27,37,16,23,29,-3,26,16,12,-9,30,-8,2,13,12,-5,18,13,8,-6,16,8,-3,-1,13,18,2,-1,28,13,24,18,11,7,63,19,39,2,33,-21,66,7,22,-38,39,10,23,-26,29,15,35,-43,19,-15,31,-18,-6,-22,7,-21,37,5,14,-30,18,-4,14,32,52,-7,45,18,34,1,36,13,7,-3,11,25,32,-25,36,9,41,30,17,-12,8,-1,20,-23,20,-1,18,18,16,6,14,-8,9,22,30,-6,7,7,13,31,25,7,35,24,20,12,71,22,11,3,29,16,15,-24,29,28,17,-6,17,-60,27,-40,-14,-11,28,-20,-9,-34,20,-46,-20,-52,32,-67,-7,-11,18,3,19,-26,5,-7,-33,-11,1,-29,-22,-6,-33,-53,-61,-27,7,-33,-5,0,29,-30,-2,-24,-24,-22,10,-3,33,-24,14,-26,-24,-46,3,-41,-24,-46,12,28,8,-13,20,-45,-17,-16,-17,-40,-2,-14,17,-43,8,-22,-12,-13,-20,-54,2,-56,19,-8,35,-19,7,-10,33,-25,11,-14,24,-24,7,-33,-8,-48,13,-9,26,-36,9,-22,28,-15,2,-17,-12,-45,4,-17,19,-21,0,-6,12,18,24,-26,30,-49,4,-55,-38,-26,6,-4,24,-30,13,-13,-8,4,-36,-17,-18,-13,-1,-14,-31,-5,-62,-47,-11,0,-16,-27,-25,-32,1,-29,32,-34,-1,-12,28,-17,-17,-30,30,-51,-5,-11,8,-1,50,-21,53,3,10,15,2,-3,15,-22,27,-11,36,-13,-6,4,-25,-36,-17,-11,-26,-13,-9,-26,7,-9,-7,12,-29,-15,-42,24,-36,4,-28,16,-15,0,-14,-57,-16,-2,-12,-51,16,-13,-4,-52,30,28,55,-76,58,-3,55,-28,15,2,20,-20,23,-3,17,-20,1,-13,18,-16,-7,-23,12,-28,30,-16,53,5,28,-18,40,5,14,-13,13,-28,-4,3,-35,-21,1,-12,-31,7,-14,-52,-46,-8,47,-39,6,-55,24,-49,3,-42,-10,-4,-19,-34,-30,-29,10,-8,16,-68,-16,-2,9,-16,-3,-13,24,-39,22,-21,-16,-61,12,-13,10,19,46,-11,17,-1,34,-15,14,19,28,34,5,13,42,21,2,5,35,-18,28,24,24,-13,11,4,9,-32,2,-42,44,-31,-16,-25,11,-70,-69,4,-13,-9,-9,-35,-11,-4,15,-44,-2,-44,-23,-76,39,-44,8,-42,19,10,35,-36,12,-25,42,-17,11,-55,-1,-15,17,-32,-10,-10,6,-23,-13,-31,16,-24,18,9,14,-14,18,3,20,-16,10,-18,-1,8,23,-34,11,-18,-5,-14,14,-19,-10,-36,14,-22,21,-12,-8,-28,4,-5,22,21,60,6,61,-26,16,-32,-6,-4,34,16,23,-1,20,-26,7,-13,54,16,22,22,7,3,23,-10,7,-52,-8,-25,8,-3,19,7,17,-24,12,-27,-7,-27,16,-15,-15,-37,10,11,16,-25,17,-13,2,-20,-15,-38,10,-48,-16,-9,-12,-35,4,-18,-25,2,-27,-35,-10,-51,5,-27,-32,-28,-9,7,-21,-22,-22,-10,-1,-21,21,-31,-2,-7,23,-33,1,-20,15,4,20,-8,18,-23,9,-5,15,-22,-5,-21,25,1,40,-32,27,13,35,-13,20,-42,7,-5,21,-24,16,5,27,-31,16,-34,-10,-15,8,-12,50,27,1,-4,11,-46,9,-17,11,3,16,-21,6,-17,-18,-21,3,6,20,-15,8,-18,-6,-12,11,-3,-13,-27,-4,-4,-9,-17,7,-4,16,-22,5,-10,23,-12,-3,-12,22,-8,-8,-45,-2,-15,11,7,18,-13,13,-44,-4,-30,-16,-35,5,-11,15,3,16,-55,10,4,11,-15,24,-28,5,-34,-8,4,28,16,2,27,21,-10,21,-16,4,-14,16,-57,-21,-19,3,21,34,-40,18,-17,32,-18,11,-4,23,-14,8,-19,8,-31,-25,-25,19,2,9,-44,15,-37,35,-19,2,2,-15,-15,-1,-29,37,-36,-9,-17,23,-34,14,-16,-10,-59,0,-6,16,20,30,-15,11,28,14,6,18,-23,7,2,30,-25,-2,-20,10,-12,27,-18,0,-9,11,18,23,-9,17,19,25,-17,43,-26,14,-18,-2,-11,-24,-40,11,-4,-15,-24,-8,-31,20,5,10,-13,8,-10,-11,-25,2,-32,11,-14,16,-42,-2,-17,12,-58,2,-19,9,-5,13,-93,34,-16,1,4,-22,-15,-12,-46,16,-2,-21,-8,-4,1,-33,-63,11,-44,35,-37,5,-8,-25,29,-13,-9,-12,-25,-3,11,-15,-10,-30,27,-18,-21,-12,-3,-11,17,0,-8,-21,-16,-10,3,-44,-45,-29,-18,-25,-15,-1,-12,12,-47,-13,32,-38,-1,-39,-17,-29,-35,-13,16,-8,-8,-13,-28,-4,-22,16,-11,-12,-21,-1,-39,10,-33,34,17,32,-10,14,-17,0,2,48,-50,49,12,11,-19,19,6,22,-43,22,-37,2,-17,-8,-9,8,4,8,-27,21,-101,26,-16,30,-52,46,-28,17,-18,-3,-30,27,-35,19,-10,-3,-7,12';
var NPL = (function () {
  var a = NPL_ENC.split(',').map(Number), out = [], x = a[0], y = a[1];
  out.push([x / 1000, y / 1000]);
  for (var i = 2; i < a.length; i += 2) { x += a[i]; y += a[i + 1]; out.push([x / 1000, y / 1000]); }
  return out;
})();

/* Major dhams. Coordinates checked against our own OSM place index (21 Oct 2026).
   Darshan hours change with season and festivals, so the cards say "about". */
var DHAMS = [
  { id: 'pashupati', c: 'np', lat: 27.71048, lng: 85.3488, a: 'pashupati pashupatinath पशुपति पशुपतिनाथ पशुपतीनाथ',
    n: { ne: 'पशुपतिनाथ मन्दिर', hi: 'पशुपतिनाथ मंदिर', en: 'Pashupatinath Temple' },
    city: { ne: 'काठमाडौं', hi: 'काठमांडू', en: 'Kathmandu' }, d: { ne: 'भगवान शिव', hi: 'भगवान शिव', en: 'Lord Shiva' },
    time: { ne: 'बिहान करिब ४ – साँझ करिब ७', hi: 'सुबह लगभग 4 – शाम लगभग 7', en: 'about 4 am – 7 pm' },
    tip: { ne: 'मुख्य प्राङ्गणमा हिन्दूमात्र। छालाका सामान र क्यामेरा भित्र नलैजानुस्।', hi: 'मुख्य प्रांगण में केवल हिंदू। चमड़े का सामान और कैमरा अंदर न ले जाएं।', en: 'Main courtyard is for Hindus only. No leather or cameras inside.' },
    fest: { ne: 'महाशिवरात्रि, तीज', hi: 'महाशिवरात्रि, तीज', en: 'Maha Shivaratri, Teej' } },
  { id: 'muktinath', c: 'np', lat: 28.8205, lng: 83.864, a: 'muktinath मुक्तिनाथ',
    n: { ne: 'मुक्तिनाथ मन्दिर', hi: 'मुक्तिनाथ मंदिर', en: 'Muktinath Temple' },
    city: { ne: 'मुस्ताङ', hi: 'मुस्तांग', en: 'Mustang' }, d: { ne: 'श्री विष्णु (मुक्तिनारायण)', hi: 'श्री विष्णु (मुक्तिनारायण)', en: 'Lord Vishnu (Muktinarayan)' },
    tip: { ne: 'उचाइ ३,७१० मिटर — बिस्तारै हिँड्नुस्, न्यानो लुगा र पानी राख्नुस्। १०८ धारा।', hi: 'ऊंचाई 3,710 मीटर — धीरे चलें, गर्म कपड़े और पानी रखें। 108 धाराएं।', en: '3,710 m altitude — walk slowly, carry warm clothes and water. 108 water spouts.' },
    fest: { ne: 'उत्तम समय: चैत–जेठ, असोज–कात्तिक', hi: 'सबसे अच्छा समय: मार्च–मई, सितंबर–नवंबर', en: 'Best: Mar–May, Sep–Nov' } },
  { id: 'janaki', c: 'np', lat: 26.73041, lng: 85.92566, a: 'janaki janki janakpur जानकी जनकपुर',
    n: { ne: 'जानकी मन्दिर', hi: 'जानकी मंदिर', en: 'Janaki Mandir' },
    city: { ne: 'जनकपुर', hi: 'जनकपुर', en: 'Janakpur' }, d: { ne: 'माता सीता', hi: 'माता सीता', en: 'Goddess Sita' },
    fest: { ne: 'विवाह पञ्चमी, राम नवमी', hi: 'विवाह पंचमी, राम नवमी', en: 'Vivah Panchami, Ram Navami' } },
  { id: 'manakamana', c: 'np', lat: 27.904, lng: 84.585, a: 'manakamana मनकामना',
    n: { ne: 'मनकामना मन्दिर', hi: 'मनकामना मंदिर', en: 'Manakamana Temple' },
    city: { ne: 'गोरखा', hi: 'गोरखा', en: 'Gorkha' }, d: { ne: 'माता भगवती', hi: 'माता भगवती', en: 'Goddess Bhagwati' },
    tip: { ne: 'कुरिनटार (पृथ्वी राजमार्ग) बाट केबलकार।', hi: 'कुरिनटार (पृथ्वी राजमार्ग) से केबल कार।', en: 'Cable car from Kurintar on the Prithvi Highway.' } },
  { id: 'swayambhu', c: 'np', lat: 27.7149, lng: 85.2904, a: 'swayambhu swayambhunath स्वयम्भू स्वयम्भूनाथ स्वयंभू',
    n: { ne: 'स्वयम्भूनाथ', hi: 'स्वयंभूनाथ', en: 'Swayambhunath' },
    city: { ne: 'काठमाडौं', hi: 'काठमांडू', en: 'Kathmandu' }, d: { ne: 'बौद्ध र हिन्दू तीर्थ', hi: 'बौद्ध और हिंदू तीर्थ', en: 'Buddhist & Hindu shrine' },
    tip: { ne: 'पूर्वी ढोकामा ३६५ सिँढी। बाँदर धेरै — खाना हातमा नराख्नुस्।', hi: 'पूर्वी द्वार पर 365 सीढ़ियां। बंदर बहुत — खाना हाथ में न रखें।', en: '365 steps on the east side. Many monkeys — keep food away.' } },
  { id: 'dakshinkali', c: 'np', lat: 27.60526, lng: 85.26349, a: 'dakshinkali दक्षिणकाली',
    n: { ne: 'दक्षिणकाली मन्दिर', hi: 'दक्षिणकाली मंदिर', en: 'Dakshinkali Temple' },
    city: { ne: 'फर्पिङ, काठमाडौं', hi: 'फर्पिंग, काठमांडू', en: 'Pharping, Kathmandu' }, d: { ne: 'माता काली', hi: 'माता काली', en: 'Goddess Kali' },
    tip: { ne: 'मंगलबार र शनिबार धेरै भीड।', hi: 'मंगलवार और शनिवार बहुत भीड़।', en: 'Very busy on Tuesdays and Saturdays.' } },
  { id: 'lumbini', c: 'np', lat: 27.4693, lng: 83.2756, a: 'lumbini maya devi लुम्बिनी मायादेवी',
    n: { ne: 'लुम्बिनी (मायादेवी मन्दिर)', hi: 'लुम्बिनी (मायादेवी मंदिर)', en: 'Lumbini (Maya Devi Temple)' },
    city: { ne: 'रुपन्देही', hi: 'रूपंदेही', en: 'Rupandehi' }, d: { ne: 'भगवान बुद्धको जन्मस्थल (युनेस्को)', hi: 'भगवान बुद्ध का जन्मस्थान (यूनेस्को)', en: 'Birthplace of the Buddha (UNESCO)' },
    tip: { ne: 'सुनौली–भैरहवा नाकाबाट करिब २२ किमी।', hi: 'सुनौली–भैरहवा सीमा से लगभग 22 किमी।', en: 'About 22 km from the Sunauli–Bhairahawa border.' } },
  { id: 'kashi', c: 'in', lat: 25.31083, lng: 83.01057, a: 'kashi vishwanath varanasi banaras काशी विश्वनाथ वाराणसी बनारस',
    n: { ne: 'काशी विश्वनाथ मन्दिर', hi: 'काशी विश्वनाथ मंदिर', en: 'Kashi Vishwanath Temple' },
    city: { ne: 'वाराणसी', hi: 'वाराणसी', en: 'Varanasi' }, d: { ne: 'शिव ज्योतिर्लिङ्ग', hi: 'शिव ज्योतिर्लिंग', en: 'Shiva Jyotirlinga' },
    tip: { ne: 'साँझ दशाश्वमेध घाटमा गंगा आरती। फोन भित्र लैजान पाइँदैन।', hi: 'शाम को दशाश्वमेध घाट पर गंगा आरती। फोन अंदर नहीं।', en: 'Ganga Aarti at Dashashwamedh Ghat every evening. No phones inside.' } },
  { id: 'ayodhya', c: 'in', lat: 26.79565, lng: 82.19457, a: 'ayodhya ram mandir janmabhoomi अयोध्या राम मन्दिर राम मंदिर',
    n: { ne: 'श्री राम जन्मभूमि मन्दिर', hi: 'श्री राम जन्मभूमि मंदिर', en: 'Shri Ram Janmabhoomi Mandir' },
    city: { ne: 'अयोध्या', hi: 'अयोध्या', en: 'Ayodhya' }, d: { ne: 'भगवान श्रीराम', hi: 'भगवान श्रीराम', en: 'Lord Shri Ram' },
    tip: { ne: 'फोन, झोला लकरमा राख्नुपर्छ।', hi: 'फोन, बैग लॉकर में जमा करें।', en: 'Phones and bags go in the lockers.' } },
  { id: 'gorakhnath', c: 'in', lat: 26.77399, lng: 83.35773, a: 'gorakhnath gorakhpur गोरखनाथ गोरखपुर',
    n: { ne: 'गोरखनाथ मन्दिर', hi: 'गोरखनाथ मंदिर', en: 'Gorakhnath Temple' },
    city: { ne: 'गोरखपुर', hi: 'गोरखपुर', en: 'Gorakhpur' }, d: { ne: 'गुरु गोरखनाथ', hi: 'गुरु गोरखनाथ', en: 'Guru Gorakhnath' },
    fest: { ne: 'मकर संक्रान्ति (खिचडी मेला)', hi: 'मकर संक्रांति (खिचड़ी मेला)', en: 'Makar Sankranti (Khichdi Mela)' } },
  { id: 'devipatan', c: 'in', lat: 27.53672, lng: 82.39529, a: 'devipatan patan devi tulsipur पाटन देवीपाटन तुलसीपुर',
    n: { ne: 'देवीपाटन शक्तिपीठ', hi: 'देवीपाटन शक्तिपीठ', en: 'Devipatan Shaktipeeth' },
    city: { ne: 'तुलसीपुर, बलरामपुर', hi: 'तुलसीपुर, बलरामपुर', en: 'Tulsipur, Balrampur' }, d: { ne: 'माता पाटेश्वरी', hi: 'माता पाटेश्वरी', en: 'Goddess Pateshwari' },
    tip: { ne: 'नेपाल नाकाको नजिक — चैत्र नवरात्रिमा ठूलो मेला।', hi: 'नेपाल सीमा के पास — चैत्र नवरात्रि में बड़ा मेला।', en: 'Near the Nepal border — big fair in Chaitra Navratri.' } },
  { id: 'somnath', c: 'in', lat: 20.88797, lng: 70.40109, a: 'somnath सोमनाथ',
    n: { ne: 'सोमनाथ मन्दिर', hi: 'सोमनाथ मंदिर', en: 'Somnath Temple' },
    city: { ne: 'प्रभास पाटन, गुजरात', hi: 'प्रभास पाटन, गुजरात', en: 'Prabhas Patan, Gujarat' }, d: { ne: 'शिव ज्योतिर्लिङ्ग (पहिलो)', hi: 'शिव ज्योतिर्लिंग (प्रथम)', en: 'Shiva Jyotirlinga (first)' },
    time: { ne: 'बिहान करिब ६ – राति करिब ९:३०', hi: 'सुबह लगभग 6 – रात लगभग 9:30', en: 'about 6 am – 9:30 pm' },
    tip: { ne: 'आरती बिहान ७, दिउँसो १२, साँझ ७। साँझ लाइट-एन्ड-साउन्ड शो।', hi: 'आरती सुबह 7, दोपहर 12, शाम 7। शाम को लाइट-एंड-साउंड शो।', en: 'Aarti 7 am, 12 noon, 7 pm. Light-and-sound show in the evening.' } },
  { id: 'dwarka', c: 'in', lat: 22.2376, lng: 68.9675, a: 'dwarka dwarkadhish jagat mandir द्वारका द्वारकाधीश',
    n: { ne: 'द्वारकाधीश मन्दिर', hi: 'द्वारकाधीश मंदिर', en: 'Dwarkadhish Temple' },
    city: { ne: 'द्वारका, गुजरात', hi: 'द्वारका, गुजरात', en: 'Dwarka, Gujarat' }, d: { ne: 'भगवान श्रीकृष्ण', hi: 'भगवान श्रीकृष्ण', en: 'Lord Krishna' },
    time: { ne: 'बिहान र साँझ दुई पालोमा (दिउँसो बन्द)', hi: 'सुबह और शाम दो पाली में (दोपहर बंद)', en: 'morning and evening sessions (closed midday)' } },
  { id: 'ambaji', c: 'in', lat: 24.33574, lng: 72.84941, a: 'ambaji amba ambe अम्बाजी अंबाजी',
    n: { ne: 'अम्बाजी मन्दिर', hi: 'अंबाजी मंदिर', en: 'Ambaji Temple' },
    city: { ne: 'बनासकाँठा, गुजरात', hi: 'बनासकांठा, गुजरात', en: 'Banaskantha, Gujarat' }, d: { ne: 'माता अम्बा (शक्तिपीठ)', hi: 'माता अंबा (शक्तिपीठ)', en: 'Goddess Amba (Shaktipeeth)' },
    fest: { ne: 'भादौ पूर्णिमा मेला, नवरात्रि', hi: 'भादरवी पूनम मेला, नवरात्रि', en: 'Bhadarvi Poonam fair, Navratri' } },
  { id: 'bahuchar', c: 'in', lat: 23.49979, lng: 72.04437, a: 'bahucharaji becharaji bahuchar बहुचराजी बहुचर',
    n: { ne: 'बहुचराजी मन्दिर', hi: 'बहुचराजी मंदिर', en: 'Bahucharaji Temple' },
    city: { ne: 'मेहसाणा, गुजरात', hi: 'मेहसाणा, गुजरात', en: 'Mehsana, Gujarat' }, d: { ne: 'माता बहुचर (शक्तिपीठ)', hi: 'माता बहुचर (शक्तिपीठ)', en: 'Goddess Bahuchar (Shaktipeeth)' } },
  { id: 'modhera', c: 'in', lat: 23.58342, lng: 72.13308, a: 'modhera sun temple surya मोढेरा सूर्य',
    n: { ne: 'मोढेरा सूर्य मन्दिर', hi: 'मोढेरा सूर्य मंदिर', en: 'Modhera Sun Temple' },
    city: { ne: 'मेहसाणा, गुजरात', hi: 'मेहसाणा, गुजरात', en: 'Mehsana, Gujarat' }, d: { ne: 'सूर्य देव (ऐतिहासिक)', hi: 'सूर्य देव (ऐतिहासिक)', en: 'Surya (heritage site)' },
    fest: { ne: 'उत्तरायण नृत्य महोत्सव (माघ)', hi: 'उत्तरार्ध नृत्य महोत्सव (जनवरी)', en: 'Uttarardh dance festival (January)' } },
  { id: 'akshardham', c: 'in', lat: 23.2308, lng: 72.67364, a: 'akshardham अक्षरधाम',
    n: { ne: 'अक्षरधाम मन्दिर', hi: 'अक्षरधाम मंदिर', en: 'Akshardham Temple' },
    city: { ne: 'गान्धीनगर, गुजरात', hi: 'गांधीनगर, गुजरात', en: 'Gandhinagar, Gujarat' }, d: { ne: 'भगवान स्वामिनारायण', hi: 'भगवान स्वामिनारायण', en: 'Bhagwan Swaminarayan' },
    tip: { ne: 'सोमबार बन्द। फोन, झोला भित्र लैजान पाइँदैन।', hi: 'सोमवार बंद। फोन, बैग अंदर नहीं।', en: 'Closed Mondays. No phones or bags inside.' } },
  { id: 'pavagadh', c: 'in', lat: 22.46343, lng: 73.51573, a: 'pavagadh mahakali पावागढ महाकाली',
    n: { ne: 'पावागढ महाकाली मन्दिर', hi: 'पावागढ़ महाकाली मंदिर', en: 'Pavagadh Mahakali Temple' },
    city: { ne: 'पञ्चमहाल, गुजरात', hi: 'पंचमहाल, गुजरात', en: 'Panchmahal, Gujarat' }, d: { ne: 'माता महाकाली (शक्तिपीठ)', hi: 'माता महाकाली (शक्तिपीठ)', en: 'Goddess Mahakali (Shaktipeeth)' },
    tip: { ne: 'माचीबाट रोपवे वा सिँढी।', hi: 'माची से रोपवे या सीढ़ियां।', en: 'Ropeway or steps from Machi.' } },
  { id: 'kedarnath', c: 'in', lat: 30.7352, lng: 79.0669, a: 'kedarnath kedar केदारनाथ',
    n: { ne: 'केदारनाथ मन्दिर', hi: 'केदारनाथ मंदिर', en: 'Kedarnath Temple' },
    city: { ne: 'रुद्रप्रयाग, उत्तराखण्ड', hi: 'रुद्रप्रयाग, उत्तराखंड', en: 'Rudraprayag, Uttarakhand' }, d: { ne: 'शिव ज्योतिर्लिङ्ग', hi: 'शिव ज्योतिर्लिंग', en: 'Shiva Jyotirlinga' },
    tip: { ne: 'जाडोमा बन्द (प्रायः कात्तिक–वैशाख)। गौरीकुण्डबाट करिब १६ किमी पैदल।', hi: 'सर्दी में बंद (आमतौर पर नवंबर–अप्रैल)। गौरीकुंड से लगभग 16 किमी पैदल।', en: 'Closed in winter (about Nov–Apr). ~16 km trek from Gaurikund.' } },
  { id: 'badrinath', c: 'in', lat: 30.7443, lng: 79.4938, a: 'badrinath badri बद्रीनाथ',
    n: { ne: 'बद्रीनाथ मन्दिर', hi: 'बद्रीनाथ मंदिर', en: 'Badrinath Temple' },
    city: { ne: 'चमोली, उत्तराखण्ड', hi: 'चमोली, उत्तराखंड', en: 'Chamoli, Uttarakhand' }, d: { ne: 'भगवान बद्रीनारायण', hi: 'भगवान बद्रीनारायण', en: 'Lord Badrinarayan' },
    tip: { ne: 'जाडोमा बन्द (प्रायः कात्तिक–वैशाख)।', hi: 'सर्दी में बंद (आमतौर पर नवंबर–अप्रैल)।', en: 'Closed in winter (about Nov–Apr).' } },
  { id: 'vaishno', c: 'in', lat: 33.0298, lng: 74.949, a: 'vaishno devi वैष्णो देवी',
    n: { ne: 'वैष्णो देवी', hi: 'वैष्णो देवी', en: 'Vaishno Devi' },
    city: { ne: 'कटरा, जम्मु', hi: 'कटरा, जम्मू', en: 'Katra, Jammu' }, d: { ne: 'माता वैष्णो देवी', hi: 'माता वैष्णो देवी', en: 'Mata Vaishno Devi' },
    tip: { ne: 'कटराबाट करिब १३ किमी पैदल; यात्रा दर्ता अनिवार्य।', hi: 'कटरा से लगभग 13 किमी पैदल; यात्रा पंजीकरण अनिवार्य।', en: '~13 km trek from Katra; yatra registration required.' } }
];

/* Which dham a question names. Words that name many places ("मन्दिर", "devi",
   "ram", "patan", "sun") never pick a dham; a city name ("Janakpur", "Varanasi")
   picks its dham but the place search still runs, so "Janakpur कसरी जाने" shows
   the city too. Latin words match from the start of a word ("ram" != "program"). */
var DH_GENERIC = { mandir: 1, mandeer: 1, temple: 1, sun: 1, surya: 1, ram: 1, devi: 1, maya: 1, patan: 1, jagat: 1, amba: 1, ambe: 1,
  'मन्दिर': 1, 'मंदिर': 1, 'सूर्य': 1, 'राम': 1, 'देवी': 1, 'पाटन': 1 };
var DH_CITY = { varanasi: 1, banaras: 1, gorakhpur: 1, janakpur: 1, tulsipur: 1, dwarka: 1, ayodhya: 1,
  'वाराणसी': 1, 'बनारस': 1, 'गोरखपुर': 1, 'जनकपुर': 1, 'तुलसीपुर': 1, 'द्वारका': 1, 'अयोध्या': 1 };
function findDham(low) {
  for (var i = 0; i < DHAMS.length; i++) {
    var hit = false, cityOnly = true;
    DHAMS[i].a.toLowerCase().split(' ').forEach(function (w) {
      if (w.length < 3 || DH_GENERIC[w]) return;
      var at = /^[a-z]/.test(w) ? new RegExp('(^|[^a-z])' + w).test(low) : low.indexOf(w) >= 0;
      if (at) { hit = true; if (!DH_CITY[w]) cityOnly = false; }
    });
    if (hit) return { d: DHAMS[i], city: cityOnly };
  }
  return null;
}

/* India-Nepal border posts (the crossing itself, on the line) */
var POSTS = [
  { id: 'rpd', lat: 28.009, lng: 81.621, n: { ne: 'रुपैडिहा – जमुनाहा (नेपालगञ्ज)', hi: 'रुपईडीहा – जमुनाहा (नेपालगंज)', en: 'Rupaidiha – Jamunaha (Nepalgunj)' }, shg: true },
  { id: 'snl', lat: 27.476, lng: 83.466, n: { ne: 'सुनौली – बेलहिया (भैरहवा)', hi: 'सुनौली – बेलहिया (भैरहवा)', en: 'Sunauli – Belahiya (Bhairahawa)' } },
  { id: 'rxl', lat: 27.0, lng: 84.856, n: { ne: 'रक्सौल – वीरगञ्ज', hi: 'रक्सौल – बीरगंज', en: 'Raxaul – Birgunj' } },
  { id: 'jbn', lat: 26.41, lng: 87.262, n: { ne: 'जोगबनी – विराटनगर', hi: 'जोगबनी – विराटनगर', en: 'Jogbani – Biratnagar' } },
  { id: 'kkb', lat: 26.633, lng: 88.16, n: { ne: 'पानीटंकी – काँकडभिट्टा', hi: 'पानीटंकी – काकड़भिट्टा', en: 'Panitanki – Kakarbhitta' } },
  { id: 'bnb', lat: 28.98, lng: 80.105, n: { ne: 'बनबसा – गड्डाचौकी (महेन्द्रनगर)', hi: 'बनबसा – गड्डाचौकी (महेंद्रनगर)', en: 'Banbasa – Gaddachauki (Mahendranagar)' } }
];

/* words people use -> our place-index search word */
var CATS = [
  { q: 'temple', e: 'temple', re: /मन्दिर|मंदिर|मन्दीर|temple|mandir|mandeer|धाम|तीर्थ|गुम्बा|monastery|gurudwara|गुरुद्वारा/i },
  { q: 'petrol', e: 'fuel', re: /पेट्रोल|डिजेल|डीजल|इन्धन|ईंधन|petrol|diesel|fuel|cng|पम्प|pump/i },
  { q: 'restaurant', e: 'food', re: /खाना|भोजन|खाने|रेस्टुरेन्ट|रेस्टोरेंट|ढाबा|restaurant|food|dhaba|khana|hotel khana|भोक|भूख/i },
  { q: 'hotel', e: 'hotel', re: /होटल|लज|बास|ठहर|रहने|lodge|hotel|guest ?house|stay|dharamshala|धर्मशाला/i },
  { q: 'hospital', e: 'health', re: /अस्पताल|हस्पिटल|अस्पताल|डाक्टर|डॉक्टर|hospital|doctor|clinic|क्लिनिक/i },
  { q: 'pharmacy', e: 'health', re: /औषधि|दवाई|दवा|फार्मेसी|pharmacy|medical|chemist|medicine/i },
  { q: 'atm', e: 'atm', re: /एटीएम|atm|पैसा निकाल|cash/i },
  { q: 'toilet', e: 'wc', re: /शौचालय|ट्वाइलेट|टॉयलेट|toilet|washroom|bathroom/i },
  { q: 'police', e: 'police', re: /प्रहरी|पुलिस|police|thana|थाना/i },
  { q: 'parking', e: 'parking', re: /पार्किङ|पार्किंग|parking/i },
  { q: 'bus stand', e: 'bus', re: /बस ?पार्क|बस ?स्ट्याण्ड|बस ?अड्डा|बस ?स्टैंड|bus ?(stand|park|station)/i },
  { q: 'bank', e: 'bank', re: /बैंक|bank/i }
];
/* phrases around a place name: removed to find the name itself */
var STRIP = /(कसरी जाने|कसरी जान्छ|कसरी पुग्ने|कसरी पुगिन्छ|कहाँ छ|कहाँ पर्छ|कति टाढा छ|कति टाढा|जाने बाटो|को बाटो|मा जानु छ|जानु छ|जान चाहन्छु|लैजाउ|लैजानुस्|लैजानु|देखाउ|देखाउनुस्|बताउ|बताउनुस्|नजिकको|नजिकै|नजिक|कैसे जाएं|कैसे जाएँ|कैसे जाये|कैसे पहुंचे|कहाँ है|कहां है|कितनी दूर|का रास्ता|ले चलो|जाना है|पास में|नज़दीक|नजदीक|kasari jane|kasari janey|kaha xa|kaha cha|kaha chha|kaise jaye|kaise jayen|kahan hai|kaha hai|how (do i |to )?(go|get) to|how to reach|where is|take me to|navigate to|directions? to|route to|near me|nearby|please|कृपया|हजुर)/gi;
var NAVWORD = /(कसरी|कहाँ|टाढा|बाटो|लैजा|जाने|जानु|कैसे|कहाँ|कहां|दूर|रास्ता|ले चलो|jane|kaha|kaise|kahan|how|where|take me|navigate|direction|route)/i;
var BUSWORD = /(बस|bus|टिकट|ticket|सिट|seat|बुकिङ|बुकिंग|booking|pnr|भाडा|fare|किराया|refund)/i;

/* ---------------------------------------------------------------------
   TEXT (follows the page's language: ne | hi | en)
--------------------------------------------------------------------- */
var TX = {
  title: { ne: 'SHARI गाइड', hi: 'SHARI गाइड', en: 'SHARI Guide' },
  sub: { ne: 'AI सहयोगी · नेपाली', hi: 'AI सहायक · हिन्दी', en: 'AI assistant · English' },
  tAsk: { ne: 'सोध्नुस्', hi: 'पूछें', en: 'Ask' },
  tTirtha: { ne: 'तीर्थ', hi: 'तीर्थ', en: 'Tirtha' },
  tBorder: { ne: 'बोर्डर', hi: 'बॉर्डर', en: 'Border' },
  tSos: { ne: 'SOS', hi: 'SOS', en: 'SOS' },
  tMine: { ne: 'मेरो', hi: 'मेरा', en: 'Mine' },
  parkT: { ne: 'पार्किङ', hi: 'पार्किंग', en: 'Parking' },
  chipAsk: { ne: 'AI सोध्नुस्', hi: 'AI से पूछें', en: 'Ask AI' },
  chipTirtha: { ne: 'तीर्थ', hi: 'तीर्थ', en: 'Tirtha' },
  hello: { ne: 'नमस्ते 🙏 म SHARI गाइड। मन्दिर, बाटो, खाना, पेट्रोल, बोर्डर — जे पनि सोध्नुस्। बोलेर पनि सोध्न सकिन्छ।',
           hi: 'नमस्ते 🙏 मैं SHARI गाइड हूँ। मंदिर, रास्ता, खाना, पेट्रोल, बॉर्डर — कुछ भी पूछें। बोलकर भी पूछ सकते हैं।',
           en: 'Namaste 🙏 I am the SHARI Guide. Ask about temples, routes, food, fuel or the border — by typing or by voice.' },
  ph: { ne: 'जस्तै: नजिकको मन्दिर कहाँ छ?', hi: 'जैसे: पास में मंदिर कहाँ है?', en: 'e.g. Where is the nearest temple?' },
  qTemple: { ne: 'नजिकको मन्दिर', hi: 'पास का मंदिर', en: 'Temples near me' },
  qFuel: { ne: 'पेट्रोल पम्प', hi: 'पेट्रोल पंप', en: 'Fuel' },
  qFood: { ne: 'शाकाहारी खाना', hi: 'शाकाहारी खाना', en: 'Veg food' },
  qHosp: { ne: 'अस्पताल', hi: 'अस्पताल', en: 'Hospital' },
  qLeft: { ne: 'कति बाँकी?', hi: 'कितना बाकी?', en: 'How far left?' },
  qPark: { ne: 'यहाँ पार्क गरें', hi: 'यहाँ पार्क किया', en: 'Parked here' },
  qCar: { ne: 'मेरो गाडी कहाँ?', hi: 'मेरी गाड़ी कहाँ?', en: 'Where is my car?' },
  qVisits: { ne: 'मेरा भ्रमण', hi: 'मेरी यात्राएं', en: 'My visits' },
  dir: { ne: 'बाटो', hi: 'रास्ता', en: 'Route' },
  start: { ne: 'सुरु', hi: 'शुरू', en: 'Start' },
  askAbout: { ne: 'AI लाई सोध्नुस्', hi: 'AI से पूछें', en: 'Ask AI' },
  found: { ne: 'तपाईंको नजिक भेटिएका ठाउँ:', hi: 'आपके पास मिली जगहें:', en: 'Places near you:' },
  foundNamed: { ne: 'यी ठाउँ भेटिए:', hi: 'ये जगहें मिलीं:', en: 'I found these:' },
  none: { ne: 'नजिक केही भेटिएन।', hi: 'पास में कुछ नहीं मिला।', en: 'Nothing found nearby.' },
  thinking: { ne: 'सोच्दैछु…', hi: 'सोच रहा हूँ…', en: 'Thinking…' },
  aiOff: { ne: 'AI अहिले जोडिन सकेन — माथिका नतिजा हाम्रो नक्साबाटै हुन्।', hi: 'AI अभी जुड़ नहीं पाया — ऊपर के नतीजे हमारे नक्शे से हैं।', en: 'The AI could not answer right now — the results above come from our own map.' },
  offline: { ne: 'इन्टरनेट छैन। तीर्थ, बोर्डर र SOS जानकारी इन्टरनेटबिना पनि चल्छ।', hi: 'इंटरनेट नहीं है। तीर्थ, बॉर्डर और SOS जानकारी बिना इंटरनेट भी चलती है।', en: 'You are offline. Tirtha, Border and SOS work without internet.' },
  noLoc: { ne: 'तपाईंको लोकेसन चाहिन्छ — नक्सामा ◎ थिच्नुस्।', hi: 'आपकी लोकेशन चाहिए — नक्शे पर ◎ दबाएं।', en: 'I need your location — tap ◎ on the map.' },
  notNav: { ne: 'अहिले नेभिगेसन चलिरहेको छैन। कहाँ जाने हो, भन्नुस्।', hi: 'अभी नेविगेशन नहीं चल रहा। कहाँ जाना है, बताइए।', en: 'No navigation running. Tell me where you want to go.' },
  left: { ne: 'गन्तव्य बाँकी', hi: 'मंज़िल बाकी', en: 'Left to destination' },
  allC: { ne: 'सबै', hi: 'सभी', en: 'All' }, np: { ne: 'नेपाल', hi: 'नेपाल', en: 'Nepal' }, inn: { ne: 'भारत', hi: 'भारत', en: 'India' },
  nearT: { ne: 'नजिकका मन्दिर', hi: 'पास के मंदिर', en: 'Temples nearby' },
  deity: { ne: 'आराध्य', hi: 'आराध्य', en: 'Deity' }, hours: { ne: 'दर्शन', hi: 'दर्शन', en: 'Darshan' },
  festT: { ne: 'पर्व', hi: 'पर्व', en: 'Festivals' }, tipT: { ne: 'ध्यान दिनुस्', hi: 'ध्यान दें', en: 'Good to know' },
  hoursNote: { ne: 'समय पर्व र मौसमअनुसार फरक पर्न सक्छ।', hi: 'समय पर्व और मौसम के अनुसार बदल सकता है।', en: 'Hours change with festivals and season.' },
  askDham: { ne: '{n} को दर्शन समय, नियम, नजिकको बस्ने ठाउँ र जाने बाटो छोटकरीमा बताउनुस्।', hi: '{n} के दर्शन का समय, नियम, पास में ठहरने की जगह और जाने का रास्ता संक्षेप में बताइए।', en: 'Briefly tell me darshan hours, rules, where to stay and how to reach {n}.' },
  bTitle: { ne: 'भारत–नेपाल बोर्डर सहायक', hi: 'भारत–नेपाल बॉर्डर सहायक', en: 'India–Nepal border assistant' },
  bRoute: { ne: 'तपाईंको रुटले बोर्डर पार गर्छ:', hi: 'आपका रास्ता बॉर्डर पार करता है:', en: 'Your route crosses the border at:' },
  bAhead: { ne: 'अगाडि भारत–नेपाल बोर्डर — परिचयपत्र तयार राख्नुस्', hi: 'आगे भारत–नेपाल बॉर्डर — पहचान पत्र तैयार रखें', en: 'India–Nepal border ahead — keep your ID ready' },
  bRouteToast: { ne: 'यो रुटले भारत–नेपाल बोर्डर पार गर्छ · ✦ गाइडमा जानकारी', hi: 'यह रास्ता भारत–नेपाल बॉर्डर पार करता है · ✦ गाइड में जानकारी', en: 'This route crosses the India–Nepal border · see ✦ Guide' },
  docsT: { ne: 'चाहिने कागजात', hi: 'ज़रूरी दस्तावेज़', en: 'Documents' },
  docs: { ne: ['भारतीय र नेपाली नागरिक: भिसा चाहिँदैन। मान्य फोटो परिचयपत्र राख्नुस् — राहदानी वा मतदाता परिचयपत्र (भारतीय), नागरिकता वा राहदानी (नेपाली)।', 'आधार कार्ड नेपाल प्रवेशको लागि मान्य परिचयपत्र होइन।', 'बालबालिका: जन्मदर्ता वा विद्यालय परिचयपत्र।', 'अन्य देशका नागरिक: प्रमुख नाकामा नेपाल भिसा अन अराइभल; भारत प्रवेशमा भारतीय भिसा चाहिन्छ।', 'आफ्नै गाडी: नाकामा भन्सार (दैनिक सवारी अनुमति) लिनुपर्छ।'],
          hi: ['भारतीय और नेपाली नागरिक: वीज़ा नहीं चाहिए। वैध फोटो पहचान पत्र रखें — पासपोर्ट या वोटर आईडी (भारतीय), नागरिकता या पासपोर्ट (नेपाली)।', 'आधार कार्ड नेपाल प्रवेश के लिए मान्य पहचान पत्र नहीं है।', 'बच्चे: जन्म प्रमाणपत्र या स्कूल आईडी।', 'अन्य देशों के नागरिक: मुख्य सीमाओं पर नेपाल वीज़ा ऑन अराइवल; भारत आने के लिए भारतीय वीज़ा ज़रूरी।', 'अपनी गाड़ी: सीमा पर भंसार (दैनिक वाहन परमिट) लेना होगा।'],
          en: ['Indian and Nepali citizens: no visa. Carry valid photo ID — passport or voter ID (Indians), citizenship or passport (Nepalis).', 'Aadhaar is not accepted as ID for entering Nepal.', 'Children: birth certificate or school ID.', 'Other nationalities: Nepal visa on arrival at the main posts; an Indian visa is needed to enter India.', 'Own vehicle: get a Bhansar (daily vehicle permit) at the post.'] },
  curT: { ne: 'रुपैयाँ साटो (स्थिर दर)', hi: 'रुपया बदलें (स्थिर दर)', en: 'Currency (fixed peg)' },
  curNote: { ne: '१ भारु = १.६० नेरु (सरकारी स्थिर दर)। साटहीमा केही कमिसन लाग्न सक्छ; ठूला भारतीय नोटबारे नियम बदलिन सक्छ।', hi: '1 भारतीय रुपया = 1.60 नेपाली रुपया (सरकारी स्थिर दर)। एक्सचेंज पर कुछ कमीशन लग सकता है; बड़े भारतीय नोटों के नियम बदल सकते हैं।', en: '1 INR = 1.60 NPR (official peg). Exchangers may take a small commission; rules on large Indian notes can change.' },
  postsT: { ne: 'मुख्य नाका', hi: 'मुख्य सीमा चौकियां', en: 'Main crossings' },
  shgPost: { ne: 'S Hari Global बसको नाका', hi: 'S Hari Global बस की सीमा', en: 'S Hari Global bus crossing' },
  sosT: { ne: 'आपतकालीन', hi: 'आपातकालीन', en: 'Emergency' },
  youIn: { ne: 'तपाईं अहिले {c} मा हुनुहुन्छ', hi: 'आप अभी {c} में हैं', en: 'You are in {c}' },
  sendLoc: { ne: 'मेरो लोकेसन पठाउनुस्', hi: 'मेरी लोकेशन भेजें', en: 'Send my location' },
  nHosp: { ne: 'नजिकको अस्पताल', hi: 'पास का अस्पताल', en: 'Nearest hospital' },
  nPol: { ne: 'नजिकको प्रहरी', hi: 'पास की पुलिस', en: 'Nearest police' },
  nFuel: { ne: 'नजिकको पेट्रोल', hi: 'पास का पेट्रोल', en: 'Nearest fuel' },
  parked: { ne: 'पार्किङ स्थान सेभ भयो 🅿️', hi: 'पार्किंग जगह सेव हो गई 🅿️', en: 'Parking spot saved 🅿️' },
  noPark: { ne: 'पार्किङ स्थान सेभ गरिएको छैन।', hi: 'कोई पार्किंग जगह सेव नहीं है।', en: 'No parking spot saved.' },
  myCar: { ne: 'मेरो गाडी', hi: 'मेरी गाड़ी', en: 'My vehicle' },
  walkThere: { ne: 'पैदल जाने', hi: 'पैदल जाएं', en: 'Walk there' },
  visitsT: { ne: 'तपाईं पुगेका ठाउँ (यो फोनमा मात्र)', hi: 'आप जहाँ पहुँचे (केवल इस फोन में)', en: 'Places you arrived at (this phone only)' },
  noVisits: { ne: 'अहिलेसम्म कुनै भ्रमण छैन।', hi: 'अभी तक कोई यात्रा नहीं।', en: 'No visits yet.' },
  clear: { ne: 'मेटाउनुस्', hi: 'मिटाएं', en: 'Clear' },
  near: { ne: 'नजिकै', hi: 'पास में', en: 'near' },
  welcome: { ne: { np: 'नेपालमा स्वागत छ 🙏 प्रहरी १०० · एम्बुलेन्स १०२', in: 'भारतमा स्वागत छ 🙏 आपतकालीन ११२ · एम्बुलेन्स १०८' },
             hi: { np: 'नेपाल में स्वागत है 🙏 पुलिस 100 · एम्बुलेंस 102', in: 'भारत में स्वागत है 🙏 आपातकालीन 112 · एम्बुलेंस 108' },
             en: { np: 'Welcome to Nepal 🙏 Police 100 · Ambulance 102', in: 'Welcome to India 🙏 Emergency 112 · Ambulance 108' } },
  listen: { ne: 'सुन्दैछु… बोल्नुस्', hi: 'सुन रहा हूँ… बोलिए', en: 'Listening… speak now' },
  noMic: { ne: 'यो ब्राउजरमा आवाजबाट सोध्न मिल्दैन।', hi: 'इस ब्राउज़र में आवाज़ से पूछना संभव नहीं।', en: 'Voice input is not available in this browser.' },
  close: { ne: 'बन्द', hi: 'बंद', en: 'Close' }
};
var SOS = {
  np: [['100', { ne: 'प्रहरी', hi: 'पुलिस', en: 'Police' }], ['102', { ne: 'एम्बुलेन्स', hi: 'एम्बुलेंस', en: 'Ambulance' }],
       ['101', { ne: 'दमकल', hi: 'दमकल', en: 'Fire' }], ['1144', { ne: 'पर्यटक प्रहरी', hi: 'पर्यटक पुलिस', en: 'Tourist police' }]],
  in: [['112', { ne: 'आपतकालीन (सबै)', hi: 'आपातकालीन (सभी)', en: 'Emergency (all)' }], ['108', { ne: 'एम्बुलेन्स', hi: 'एम्बुलेंस', en: 'Ambulance' }],
       ['101', { ne: 'दमकल', hi: 'दमकल', en: 'Fire' }], ['1033', { ne: 'राजमार्ग सहायता', hi: 'हाईवे हेल्पलाइन', en: 'Highway helpline' }],
       ['1091', { ne: 'महिला हेल्पलाइन', hi: 'महिला हेल्पलाइन', en: 'Women helpline' }]]
};

/* ---------------------------------------------------------------------
   ICONS (stroke SVG, same family as the page's own)
--------------------------------------------------------------------- */
function svg(d, fill) { return '<svg viewBox="0 0 24 24" ' + (fill ? 'fill="currentColor"' : 'fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"') + ' aria-hidden="true">' + d + '</svg>'; }
var GI = {
  spark: svg('<path d="M11 3.2l1.9 5.4 5.4 1.9-5.4 1.9L11 17.8l-1.9-5.4L3.7 10.5l5.4-1.9z"/><path d="M18.4 14.6l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/>', true),
  temple: svg('<path d="M12 2.5v2"/><path d="M12 4.6c-2.6 1.6-4.3 3.5-4.3 5.9h8.6c0-2.4-1.7-4.3-4.3-5.9z"/><path d="M5.5 10.5h13"/><path d="M6.6 10.5V20.5h10.8V10.5"/><path d="M10.2 20.5v-4a1.8 1.8 0 0 1 3.6 0v4"/><path d="M4 20.5h16"/>'),
  flag: svg('<path d="M5 21V3.5"/><path d="M5 4h11.5l-2.3 3.6 2.3 3.6H5"/><path d="M19 21v-7"/>'),
  shield: svg('<path d="M12 3l7.5 3v5.5c0 4.6-3.2 8.3-7.5 9.5-4.3-1.2-7.5-4.9-7.5-9.5V6z"/><path d="M12 8v4.8M12 15.8v.2"/>'),
  chat: svg('<path d="M4.5 5.5h15v10h-8.5l-4.5 3.5v-3.5h-2z"/>'),
  mic: svg('<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21"/>'),
  send: svg('<path d="M4 12l16-7.5-6 16-2.5-6.5z"/><path d="M11.5 14L20 4.5"/>'),
  x: svg('<path d="M6 6l12 12M18 6L6 18"/>'),
  vol: svg('<path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="M15.5 9a4 4 0 0 1 0 6M18 6.5a7.5 7.5 0 0 1 0 11"/>'),
  volx: svg('<path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="M16 9.5l5 5M21 9.5l-5 5"/>'),
  dir: svg('<path d="M21.7 11.3l-9-9a1 1 0 0 0-1.4 0l-9 9a1 1 0 0 0 0 1.4l9 9a1 1 0 0 0 1.4 0l9-9a1 1 0 0 0 0-1.4zM14 14.5V12h-4v3H8v-4a1 1 0 0 1 1-1h5V7.5l3.5 3.5z"/>', true),
  go: svg('<path d="M12 2.5l7.5 18.2-7.5-4.3-7.5 4.3z"/>', true),
  phone: svg('<path d="M6.6 3.5h3l1.5 4-2 1.4a11 11 0 0 0 6 6l1.4-2 4 1.5v3a2 2 0 0 1-2.2 2A17.5 17.5 0 0 1 4.6 5.7a2 2 0 0 1 2-2.2z"/>'),
  pin: svg('<path d="M12 21.5s-7-6.6-7-12a7 7 0 0 1 14 0c0 5.4-7 12-7 12zm0-9.3a2.7 2.7 0 1 0 0-5.4 2.7 2.7 0 0 0 0 5.4z"/>', true),
  park: svg('<rect x="4" y="3.5" width="16" height="17" rx="3.5"/><path d="M9.5 16.5v-9h3.2a2.8 2.8 0 0 1 0 5.6H9.5"/>'),
  hist: svg('<path d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1"/><path d="M3.5 4v4h4"/><path d="M12 8v4.2l3 1.8"/>'),
  fuel: svg('<path d="M5 20.5V5a1.5 1.5 0 0 1 1.5-1.5h6A1.5 1.5 0 0 1 14 5v15.5"/><path d="M3.5 20.5h12"/><path d="M7 7.5h5"/><path d="M14 9.5h1.5a1.5 1.5 0 0 1 1.5 1.5v5a1.5 1.5 0 0 0 3 0V8l-2.5-2.5"/>'),
  food: svg('<path d="M7 3v8M4.5 3v5a2.5 2.5 0 0 0 5 0V3M7 11v10"/><path d="M17 21V3c-2 1-3.5 3.5-3.5 7v3H17"/>'),
  health: svg('<rect x="3.5" y="3.5" width="17" height="17" rx="4"/><path d="M12 8v8M8 12h8"/>'),
  police: svg('<path d="M12 3l7.5 3v5.5c0 4.6-3.2 8.3-7.5 9.5-4.3-1.2-7.5-4.9-7.5-9.5V6z"/><path d="M12 8.2l1.2 2.4 2.6.4-1.9 1.8.5 2.6-2.4-1.3-2.4 1.3.5-2.6-1.9-1.8 2.6-.4z"/>'),
  clock: svg('<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>'),
  place: svg('<path d="M12 21.5s-7-6.6-7-12a7 7 0 0 1 14 0c0 5.4-7 12-7 12z"/><circle cx="12" cy="9.5" r="2.5"/>'),
  swap: svg('<path d="M7 4L3.5 7.5 7 11M3.5 7.5H17M17 13l3.5 3.5L17 20M20.5 16.5H7"/>')
};
var KIND_IC = { temple: GI.temple, fuel: GI.fuel, food: GI.food, health: GI.health, police: GI.police, hotel: GI.place, bus: GI.go, parking: GI.park };

/* ---------------------------------------------------------------------
   HELPERS
--------------------------------------------------------------------- */
function $(s, r) { return (r || document).querySelector(s); }
function lang() { var l = X && X.S.lang; return l === 'hi' || l === 'en' ? l : 'ne'; }
function t(k, vars) {
  var v = TX[k], s = v ? (v[lang()] != null ? v[lang()] : v.en) : k;
  if (vars && typeof s === 'string') Object.keys(vars).forEach(function (n) { s = s.split('{' + n + '}').join(vars[n]); });
  return s;
}
function L(o) { return o ? (o[lang()] || o.en || '') : ''; }
function esc(s) { return X.esc(s); }
var LSg = {
  get: function (k, d) { try { var v = localStorage.getItem(k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
  set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
};
function here() {
  var S = X.S;
  if (S.me) return [S.me.lng, S.me.lat];
  if (S.map) { var c = S.map.getCenter(); return [c.lng, c.lat]; }
  return null;
}
function distTo(p) { var h = here(); return h ? X.hav(h, [p.lng, p.lat]) : Infinity; }
function dLabel(m) { return isFinite(m) ? X.fmtDist(m) : ''; }
function inNepal(lng, lat) {
  if (lng < 80.0 || lng > 88.3 || lat < 26.3 || lat > 30.5) return false;
  var ins = false;
  for (var i = 0, j = NPL.length - 1; i < NPL.length; j = i++) {
    var xi = NPL[i][0], yi = NPL[i][1], xj = NPL[j][0], yj = NPL[j][1];
    if (((yi > lat) !== (yj > lat)) && (lng < (xj - xi) * (lat - yi) / (yj - yi) + xi)) ins = !ins;
  }
  return ins;
}
function countryAt(p) { return p ? (inNepal(p[0], p[1]) ? 'np' : 'in') : 'in'; }
function cName(c) { return c === 'np' ? t('np') : t('inn'); }
function speakable(s) {
  return String(s).replace(/<[^>]+>/g, ' ').replace(/\*\*/g, '').replace(/https?:\S+/g, '')
    .replace(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}]/gu, '').replace(/\s+/g, ' ').trim().slice(0, 320);
}
function online() { return navigator.onLine !== false; }
/* the page has its own AI chat (navigate.html AI module, 22 Oct 2026): every
   question goes there, and this panel keeps Tirtha / Border / SOS / Mine */
function hasAI() { return !!(X && X.aiOpen && X.aiAsk); }
function askAI(q) {
  function run() { if (q) X.aiAsk(q); else X.aiOpen(); }
  if (G.open) { closeG(); afterClose(run); } else run();
}

/* ---------------------------------------------------------------------
   SERVER: CSRF + AI + place search (all same-origin)
--------------------------------------------------------------------- */
var CSRF = { tok: null, at: 0 };
function csrf(force) {
  if (!force && CSRF.tok && Date.now() - CSRF.at < 15 * 60e3) return Promise.resolve(CSRF.tok);
  return X.fetchT('/api/csrf.php', { credentials: 'same-origin', cache: 'no-store' }, 8000)
    .then(function (r) { return r.json(); })
    .then(function (j) { CSRF.tok = j && j.data && j.data.csrf; CSRF.at = Date.now(); return CSRF.tok; });
}
function aiAsk(message, nav) {
  function once(force) {
    return csrf(force).then(function (tok) {
      return X.fetchT('/api/ai-chat.php', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tok || '' },
        body: JSON.stringify(nav ? { message: message.slice(0, 1500), lang: lang(), src: 'nav' } : { message: message.slice(0, 1500), lang: lang() })
      }, 30000);
    });
  }
  return once(false).then(function (r) {
    if (r.status === 400 || r.status === 403 || r.status === 419) return once(true);
    return r;
  }).then(function (r) {
    return r.json().catch(function () { return null; }).then(function (j) {
      if (!r.ok || !j || !j.ok || !j.data || !j.data.text) throw new Error('ai ' + r.status);
      return j.data;
    });
  });
}
function search(q, near, limit) {
  var u = '/api/nav-search.php?limit=' + (limit || 8) + '&q=' + encodeURIComponent(q);
  if (near) u += '&lat=' + near[1].toFixed(4) + '&lng=' + near[0].toFixed(4);
  return X.fetchT(u, { credentials: 'same-origin' }, 9000).then(function (r) { return r.json(); }).then(function (j) {
    return (j.results || []).map(function (x) {
      return { name: x.localName && lang() !== 'en' && /[ऀ-ॿ]/.test(x.localName) ? x.localName : x.shortName,
               sub: (x.displayName || '').replace(x.shortName + ' · ', ''), lng: +x.lng, lat: +x.lat, kind: x.kind || '', ours: x.provider === 'S Hari' };
    });
  });
}
var AREA = {};
function areaName(p) {
  var k = p[1].toFixed(2) + ',' + p[0].toFixed(2);
  if (AREA[k] !== undefined) return Promise.resolve(AREA[k]);
  return X.fetchT('/api/nav-search.php?city=' + p[1].toFixed(4) + ',' + p[0].toFixed(4), { credentials: 'same-origin' }, 6000)
    .then(function (r) { return r.json(); }).then(function (j) { AREA[k] = j.name || ''; return AREA[k]; }).catch(function () { return ''; });
}

/* ---------------------------------------------------------------------
   PANEL
--------------------------------------------------------------------- */
var G = { open: false, tab: 'ask', msgs: [], last: [], speak: false, rec: null, pins: [], tf: 'all', hist: false, nearT: null, sosRes: null };

function css() {
  var st = document.createElement('style');
  st.id = 'sgCss';
  st.textContent = [
    '.sg-fab{background:linear-gradient(145deg,#E6BE5A,#B98A26)!important;color:#1A1405!important;border:none!important;box-shadow:0 6px 18px rgba(212,168,67,.35),0 2px 6px rgba(0,0,0,.35)!important;position:relative}',
    '.sg-fab svg{width:24px;height:24px}',
    '.sg-fab .sg-dot{position:absolute;top:4px;right:4px;width:10px;height:10px;border-radius:50%;background:var(--cta,#E8652E);border:2px solid #1A1405;display:none}',
    '.sg-fab.alert .sg-dot{display:block}',
    '.chip.sg-chip svg{color:var(--gold,#D4A843)}',
    '.sg-wrap{position:fixed;inset:0;z-index:58;pointer-events:none}',
    '.sg-scrim{position:absolute;inset:0;background:rgba(3,7,16,.38);opacity:0;transition:opacity .25s;pointer-events:auto}',
    '.sg-wrap.on .sg-scrim{opacity:1}',
    'body[data-lvl=nav] .sg-scrim{background:transparent;pointer-events:none}',
    '.sg-panel{position:absolute;left:calc(var(--sl,0px) + 8px);right:calc(var(--sr,0px) + 8px);bottom:calc(var(--sb,0px) + 8px + var(--sg-kb,0px));max-width:520px;margin:0 auto;',
    '  height:min(78vh,680px);display:flex;flex-direction:column;border-radius:22px;overflow:hidden;pointer-events:auto;',
    '  background:rgba(14,24,44,.94);-webkit-backdrop-filter:blur(22px) saturate(1.4);backdrop-filter:blur(22px) saturate(1.4);',
    '  border:1px solid rgba(255,255,255,.09);box-shadow:0 18px 60px rgba(0,0,0,.55);transform:translateY(110%);transition:transform .38s var(--ease,cubic-bezier(.2,.8,.2,1))}',
    '.sg-wrap.on .sg-panel{transform:none}',
    'body[data-lvl=nav] .sg-panel{height:min(56vh,520px);bottom:calc(var(--sb,0px) + 96px + var(--sg-kb,0px))}',
    '@media (min-width:900px){.sg-panel{left:auto;right:calc(var(--sr,0px) + 16px);width:420px;bottom:calc(var(--sb,0px) + 16px + var(--sg-kb,0px))}}',
    '@media (prefers-reduced-motion:reduce){.sg-panel,.sg-scrim{transition:none}}',
    '.sg-h{display:flex;align-items:center;gap:10px;padding:12px 10px 8px 14px}',
    '.sg-logo{width:36px;height:36px;border-radius:12px;display:grid;place-items:center;background:linear-gradient(145deg,#E6BE5A,#B98A26);color:#1A1405;flex:none}',
    '.sg-logo svg{width:20px;height:20px}',
    '.sg-ttl{flex:1;min-width:0;line-height:1.2}.sg-ttl b{font:600 16px/1.2 Poppins,Inter,system-ui,sans-serif;color:var(--text,#EEF2FA);display:block}',
    '.sg-ttl small{font-size:12px;color:var(--text3,#6F7D99)}',
    '.sg-ib{width:44px;height:44px;border-radius:50%;border:none;background:rgba(255,255,255,.06);color:var(--text2,#A8B4CC);display:grid;place-items:center;cursor:pointer;flex:none}',
    '.sg-ib svg{width:20px;height:20px}.sg-ib.on{color:var(--gold,#D4A843);background:rgba(212,168,67,.14)}',
    '.sg-tabs{display:flex;gap:6px;padding:0 12px 8px;border-bottom:1px solid rgba(255,255,255,.06)}',
    '.sg-tab{flex:1;height:44px;border-radius:12px;border:1px solid transparent;background:transparent;color:var(--text2,#A8B4CC);font:500 13px Inter,system-ui,sans-serif;display:flex;align-items:center;justify-content:center;gap:6px;cursor:pointer}',
    '.sg-tab svg{width:17px;height:17px}.sg-tab.on{background:rgba(212,168,67,.13);color:var(--gold,#D4A843);border-color:rgba(212,168,67,.28)}',
    '.sg-body{flex:1;min-height:0;overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;touch-action:pan-y;padding:12px 12px 6px;display:flex;flex-direction:column;gap:10px}',
    '.sg-body>*{flex-shrink:0}',   /* an overflow:hidden card would otherwise shrink to a sliver in a long list */
    '.sg-m{max-width:88%;padding:10px 13px;border-radius:16px;font-size:14.5px;line-height:1.5;white-space:normal;overflow-wrap:anywhere}',
    '.sg-m.u{align-self:flex-end;background:var(--cta,#E8652E);color:#fff;border-bottom-right-radius:5px}',
    '.sg-m.b{align-self:flex-start;background:rgba(255,255,255,.06);color:var(--text,#EEF2FA);border-bottom-left-radius:5px}',
    '.sg-m.b b{color:var(--gold,#D4A843);font-weight:600}',
    '.sg-m.note{align-self:stretch;max-width:none;background:transparent;color:var(--text3,#6F7D99);font-size:12.5px;padding:0 4px}',
    '.sg-acts{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}',
    '.sg-a{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 12px;border-radius:17px;border:1px solid rgba(255,255,255,.12);background:transparent;color:var(--text,#EEF2FA);font:500 13px Inter,system-ui,sans-serif;text-decoration:none;cursor:pointer}',
    '.sg-a svg{width:16px;height:16px}.sg-a.cta{background:var(--cta,#E8652E);border-color:var(--cta,#E8652E);color:#fff}.sg-a.gold{color:var(--gold,#D4A843);border-color:rgba(212,168,67,.35)}',
    '.sg-typing{display:inline-flex;gap:4px;align-items:center}.sg-typing i{width:6px;height:6px;border-radius:50%;background:var(--text3,#6F7D99);animation:sgb 1s infinite}',
    '.sg-typing i:nth-child(2){animation-delay:.15s}.sg-typing i:nth-child(3){animation-delay:.3s}',
    '@keyframes sgb{0%,80%,100%{opacity:.3;transform:none}40%{opacity:1;transform:translateY(-3px)}}',
    '.sg-card{align-self:stretch;border-radius:16px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);overflow:hidden}',
    '.sg-row{display:flex;align-items:center;gap:12px;width:100%;padding:10px 12px;background:none;border:none;color:inherit;text-align:left;cursor:pointer;font:inherit}',
    '.sg-row+.sg-row{border-top:1px solid rgba(255,255,255,.06)}',
    '.sg-ric{width:38px;height:38px;min-width:38px;border-radius:12px;display:grid;place-items:center;background:rgba(212,168,67,.12);color:var(--gold,#D4A843)}',
    '.sg-ric svg{width:20px;height:20px}.sg-ric.np{background:rgba(220,20,60,.13);color:#FF6B6B}.sg-ric.in{background:rgba(255,153,51,.13);color:#FF9F45}',
    '.sg-rt{flex:1;min-width:0}.sg-rt b{display:block;font-weight:500;font-size:14.5px;color:var(--text,#EEF2FA);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.sg-rt small{display:block;font-size:12.5px;color:var(--text3,#6F7D99);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.sg-rd{font-size:12.5px;color:var(--text2,#A8B4CC);white-space:nowrap}',
    '.sg-rb{display:flex;gap:6px;padding:0 12px 10px 62px;flex-wrap:wrap}',
    '.sg-det{padding:4px 14px 12px;display:grid;gap:8px;font-size:13.5px;color:var(--text2,#A8B4CC);line-height:1.5}',
    '.sg-det dt{font-size:11.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--text3,#6F7D99)}.sg-det dd{margin:0;color:var(--text,#EEF2FA)}',
    '.sg-h3{font:600 14px Poppins,Inter,system-ui,sans-serif;color:var(--text,#EEF2FA);margin:6px 2px 0}',
    '.sg-p{font-size:13.5px;line-height:1.55;color:var(--text2,#A8B4CC);margin:0 2px}',
    '.sg-ul{margin:0;padding:0 2px 0 20px;font-size:13.5px;line-height:1.55;color:var(--text2,#A8B4CC);display:grid;gap:4px}',
    '.sg-seg{display:flex;gap:6px;flex-wrap:wrap}',
    '.sg-seg button{height:32px;padding:0 13px;border-radius:16px;border:1px solid rgba(255,255,255,.12);background:transparent;color:var(--text2,#A8B4CC);font:500 13px Inter,system-ui,sans-serif;cursor:pointer}',
    '.sg-seg button.on{background:var(--gold,#D4A843);border-color:var(--gold,#D4A843);color:#1A1405}',
    '.sg-alert{border-radius:14px;padding:11px 13px;background:rgba(232,101,46,.12);border:1px solid rgba(232,101,46,.35);color:var(--text,#EEF2FA);font-size:13.5px;line-height:1.5}',
    '.sg-cur{display:grid;grid-template-columns:1fr auto 1fr;gap:8px;align-items:center}',
    '.sg-cur label{display:grid;gap:4px;font-size:12px;color:var(--text3,#6F7D99)}',
    '.sg-cur input{width:100%;min-width:0;height:44px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);color:var(--text,#EEF2FA);font:600 17px Inter,system-ui,sans-serif;padding:0 12px}',
    '.sg-cur .sg-ib{margin-top:16px}',
    '.sg-sos{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px}',
    '.sg-num{display:flex;flex-direction:column;gap:2px;padding:12px;border-radius:14px;text-decoration:none;background:rgba(255,69,58,.1);border:1px solid rgba(255,69,58,.3);color:var(--text,#EEF2FA)}',
    '.sg-num b{font:700 22px Poppins,Inter,system-ui,sans-serif;color:#FF6A60;letter-spacing:.02em}.sg-num small{font-size:12.5px;color:var(--text2,#A8B4CC)}',
    '.sg-quick{display:flex;gap:8px;overflow-x:auto;scrollbar-width:none;padding:8px 12px 2px;flex:none;touch-action:pan-x}',
    '.sg-quick::-webkit-scrollbar{display:none}',
    '.sg-q{flex:none;height:34px;padding:0 12px;border-radius:17px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);color:var(--text,#EEF2FA);font:500 13px Inter,system-ui,sans-serif;display:inline-flex;align-items:center;gap:6px;cursor:pointer}',
    '.sg-q svg{width:16px;height:16px;color:var(--gold,#D4A843)}',
    '.sg-in{display:flex;gap:8px;align-items:center;padding:8px 10px calc(10px);border-top:1px solid rgba(255,255,255,.06);flex:none}',
    '.sg-in input{flex:1;min-width:0;height:46px;border-radius:23px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);color:var(--text,#EEF2FA);font:16px Inter,system-ui,sans-serif;padding:0 16px;outline:none}',
    '.sg-in input:focus{border-color:rgba(212,168,67,.55)}',
    '.sg-send{width:46px;height:46px;border-radius:50%;border:none;background:var(--cta,#E8652E);color:#fff;display:grid;place-items:center;cursor:pointer;flex:none}',
    '.sg-send svg{width:20px;height:20px}',
    '.sg-ib.rec{background:rgba(255,69,58,.18);color:#FF6A60;animation:sgp 1.2s infinite}',
    '@keyframes sgp{50%{box-shadow:0 0 0 8px rgba(255,69,58,0)}0%{box-shadow:0 0 0 0 rgba(255,69,58,.45)}}',
    '.sg-pin{width:30px;height:30px;border-radius:50% 50% 50% 4px;transform:rotate(-45deg);display:grid;place-items:center;cursor:pointer;',
    '  background:linear-gradient(145deg,#FF8A3D,#E8652E);border:2px solid #fff;box-shadow:0 3px 10px rgba(0,0,0,.4)}',
    '.sg-pin svg{transform:rotate(45deg);width:16px;height:16px;color:#fff}',
    '.sg-lm{position:absolute;z-index:31;left:50%;transform:translateX(-50%);top:calc(var(--st,0px) + var(--sg-lmtop,132px));max-width:min(92vw,420px);',
    '  display:flex;align-items:center;gap:8px;padding:7px 14px;border-radius:16px;background:rgba(14,24,44,.92);border:1px solid rgba(212,168,67,.35);',
    '  color:var(--text,#EEF2FA);font:500 13.5px Inter,system-ui,sans-serif;box-shadow:0 6px 18px rgba(0,0,0,.4);pointer-events:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.sg-lm svg{width:16px;height:16px;color:var(--gold,#D4A843);flex:none}',
    '.sg-empty{color:var(--text3,#6F7D99);font-size:13.5px;text-align:center;padding:18px 8px}',
    /* the small pills keep their look but take a 44 px tall tap */
    '.sg-q,.sg-a,.sg-seg button{position:relative}',
    '.sg-q::after,.sg-a::after,.sg-seg button::after{content:"";position:absolute;left:0;right:0;top:-6px;bottom:-6px}'
  ].join('\n');
  document.head.appendChild(st);
}

function build() {
  css();
  /* gold AI button at the top of the map controls (stays during navigation) */
  var ctr = $('#ctrls');
  if (ctr) {
    var fab = document.createElement('button');
    fab.type = 'button'; fab.id = 'sgFab'; fab.className = 'fab sg-fab';
    fab.setAttribute('aria-label', 'SHARI Guide — AI');
    fab.innerHTML = GI.spark + '<i class="sg-dot"></i>';
    fab.onclick = function () { openG(G.borderNew ? 'border' : hasAI() ? 'tirtha' : 'ask'); };
    ctr.insertBefore(fab, ctr.firstChild);
  }
  /* two chips on the home row: Ask AI, Tirtha */
  var hw = $('#hwChips');
  if (hw && hw.parentNode) {
    var c1 = document.createElement('button'); c1.type = 'button'; c1.className = 'chip sg-chip'; c1.dataset.sg = 'ask';
    var c2 = document.createElement('button'); c2.type = 'button'; c2.className = 'chip sg-chip'; c2.dataset.sg = 'tirtha';
    hw.parentNode.insertBefore(c2, hw.nextSibling); hw.parentNode.insertBefore(c1, hw.nextSibling);
    [c1, c2].forEach(function (b) { b.addEventListener('click', function (e) { e.stopPropagation(); openG(b.dataset.sg); }); });
  }
  var w = document.createElement('div');
  w.className = 'sg-wrap'; w.id = 'sgWrap'; w.hidden = true;
  w.innerHTML =
    '<div class="sg-scrim" id="sgScrim"></div>' +
    '<section class="sg-panel" role="dialog" aria-modal="false" aria-labelledby="sgTtl">' +
      '<header class="sg-h"><div class="sg-logo">' + GI.spark + '</div>' +
        '<div class="sg-ttl"><b id="sgTtl"></b><small id="sgSub"></small></div>' +
        '<button class="sg-ib" id="sgSpk" type="button"></button>' +
        '<button class="sg-ib" id="sgX" type="button"></button></header>' +
      '<nav class="sg-tabs" id="sgTabs" role="tablist"></nav>' +
      '<div class="sg-body" id="sgBody" aria-live="polite"></div>' +
      '<div class="sg-quick" id="sgQuick"></div>' +
      '<form class="sg-in" id="sgForm" autocomplete="off">' +
        '<button class="sg-ib" id="sgMic" type="button"></button>' +
        '<input id="sgInput" type="text" enterkeyhint="send" maxlength="400">' +
        '<button class="sg-send" id="sgSend" type="submit"></button></form>' +
    '</section>';
  document.body.appendChild(w);
  $('#sgScrim').onclick = function () { closeG(); };
  $('#sgX').onclick = function () { closeG(); };
  $('#sgSpk').onclick = function () { G.speak = !G.speak; LSg.set('shg:ng:speak', G.speak); if (!G.speak) stopTalk(); labels(); };
  $('#sgMic').onclick = mic;
  $('#sgForm').onsubmit = function (e) { e.preventDefault(); var v = $('#sgInput').value.trim(); if (v) { $('#sgInput').value = ''; ask(v, false); } };
  $('#sgTabs').onclick = function (e) { var b = e.target.closest('[data-t]'); if (b) { G.tab = b.dataset.t; render(); } };
  $('#sgQuick').onclick = function (e) { var b = e.target.closest('[data-q]'); if (b) quick(b.dataset.q); };
  $('#sgBody').addEventListener('click', bodyClick);
  /* phone keyboard: lift the panel above it */
  if (window.visualViewport) {
    var kb = function () { var vv = window.visualViewport, k = Math.max(0, innerHeight - vv.height - vv.offsetTop); document.documentElement.style.setProperty('--sg-kb', (k > 60 ? k : 0) + 'px'); };
    visualViewport.addEventListener('resize', kb); visualViewport.addEventListener('scroll', kb);
  }
  /* Escape / Android back close the panel first */
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && G.open) { e.stopImmediatePropagation(); closeG(); } }, true);
  addEventListener('popstate', function () { G.backing = false; if (G.open && G.hist) { G.hist = false; closeG(true); } });
  G.speak = !!LSg.get('shg:ng:speak', false);
  labels();
}

function labels() {
  var ch = document.querySelectorAll('.sg-chip');
  ch.forEach(function (b) { b.innerHTML = (b.dataset.sg === 'ask' ? GI.spark + t('chipAsk') : GI.temple + t('chipTirtha')); });
  if (!$('#sgWrap')) return;
  $('#sgTtl').textContent = t('title'); $('#sgSub').textContent = t('sub');
  $('#sgSpk').innerHTML = G.speak ? GI.vol : GI.volx; $('#sgSpk').classList.toggle('on', G.speak);
  $('#sgSpk').setAttribute('aria-label', 'Voice replies ' + (G.speak ? 'on' : 'off'));
  $('#sgX').innerHTML = GI.x; $('#sgX').setAttribute('aria-label', t('close'));
  $('#sgMic').innerHTML = GI.mic; $('#sgMic').setAttribute('aria-label', 'Voice');
  $('#sgSend').innerHTML = GI.send; $('#sgSend').setAttribute('aria-label', 'Send');
  $('#sgInput').placeholder = t('ph');
  var tabs = hasAI() ? [['tirtha', GI.temple, t('tTirtha')], ['border', GI.flag, t('tBorder')], ['sos', GI.shield, t('tSos')], ['mine', GI.park, t('tMine')]]
                     : [['ask', GI.chat, t('tAsk')], ['tirtha', GI.temple, t('tTirtha')], ['border', GI.flag, t('tBorder')], ['sos', GI.shield, t('tSos')]];
  $('#sgTabs').innerHTML = tabs.map(function (x) {
    return '<button class="sg-tab' + (G.tab === x[0] ? ' on' : '') + '" type="button" role="tab" aria-selected="' + (G.tab === x[0]) + '" data-t="' + x[0] + '">' + x[1] + '<span>' + esc(x[2]) + '</span></button>';
  }).join('');
}

function openG(tab) {
  if (hasAI() && (tab || G.tab) === 'ask') { if (tab === 'ask') { askAI(); return; } G.tab = 'tirtha'; }
  if (tab) G.tab = tab;
  var w = $('#sgWrap'); if (!w) return;
  if (!G.open) {
    G.open = true; w.hidden = false;
    requestAnimationFrame(function () { requestAnimationFrame(function () { w.classList.add('on'); }); });
    try { history.pushState({ lvl: X.S.level, d: X.S.depth, sg: 1 }, ''); G.hist = true; } catch (e) { G.hist = false; }
  }
  if (G.tab === 'border') { G.borderNew = false; $('#sgFab') && $('#sgFab').classList.remove('alert'); }
  render();
}
function closeG(fromPop) {
  if (!G.open) return;
  G.open = false; stopMic(); clearPins();
  var w = $('#sgWrap'); w.classList.remove('on');
  setTimeout(function () { if (!G.open) w.hidden = true; }, 380);
  if (!fromPop && G.hist) { G.hist = false; G.backing = true; try { history.back(); } catch (e) { G.backing = false; } }
  try { $('#sgInput').blur(); } catch (e) {}
}
/* run fn once the history entry the panel pushed is gone: a screen pushed
   before that back() lands would be the entry it pops instead */
function afterClose(fn) {
  if (!G.backing) { setTimeout(fn, 0); return; }
  var done = false;
  function f() { if (done) return; done = true; removeEventListener('popstate', f); setTimeout(fn, 0); }
  addEventListener('popstate', f); setTimeout(f, 500);
}

function render() {
  labels();
  var b = $('#sgBody'), q = $('#sgQuick'), f = $('#sgForm');
  var chat = G.tab === 'ask' && !hasAI();
  q.hidden = !chat; f.hidden = !chat;
  if (G.tab !== 'tirtha') clearPins();
  if (chat) { renderChat(); quickChips(); }
  else if (G.tab === 'tirtha') renderTirtha();
  else if (G.tab === 'border') renderBorder();
  else if (G.tab === 'mine') renderMine();
  else renderSos();
  if (!chat) b.scrollTop = 0;
}

/* ---------------------------------------------------------------------
   ASK (chat)
--------------------------------------------------------------------- */
function quickChips() {
  var nav = !!(X.S.nav && !X.S.nav.preview);
  var park = LSg.get('shg:ng:park', null);
  var items = [];
  if (nav) items.push(['left', GI.clock, t('qLeft')]);
  items.push(['temple', GI.temple, t('qTemple')], ['fuel', GI.fuel, t('qFuel')], ['food', GI.food, t('qFood')], ['hosp', GI.health, t('qHosp')]);
  items.push(park ? ['car', GI.park, t('qCar')] : ['park', GI.park, t('qPark')]);
  if (park) items.push(['park', GI.park, t('qPark')]);
  items.push(['visits', GI.hist, t('qVisits')]);
  $('#sgQuick').innerHTML = items.map(function (x) { return '<button class="sg-q" type="button" data-q="' + x[0] + '">' + x[1] + esc(x[2]) + '</button>'; }).join('');
}
function quick(k) {
  if (k === 'left') return ask(t('qLeft'), false);
  if (k === 'temple') return ask(t('qTemple'), false);
  if (k === 'fuel') return ask(t('qFuel'), false);
  if (k === 'food') return ask(t('qFood'), false);
  if (k === 'hosp') return ask(t('qHosp'), false);
  if (k === 'park') return savePark(true);
  if (k === 'car') return showCar();
  if (k === 'visits') return showVisits();
}
function push(m) { G.msgs.push(m); if (G.msgs.length > 60) G.msgs.splice(0, G.msgs.length - 60); if (G.open && G.tab === 'ask') renderChat(); }
function fmtAI(s) {
  s = String(s || '').replace(/\s*👉\s*#\/?\s*$/u, '').trim();
  var h = esc(s).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/\n/g, '<br>');
  /* the assistant points at the booking app as "👉 Book #/": a link to the main site */
  h = h.replace(/👉\s*([^<#]*?)\s*(#\/[\w\/-]*)/gu, function (_, lb, hs) {
    return '<a class="sg-a gold" href="/' + hs + '" target="_blank" rel="noopener">' + (lb || 'S Hari Global') + '</a>';
  });
  return h.replace(/(https?:\/\/[^\s<]+)/g, '<a class="sg-a" style="height:auto;padding:0;border:none;color:var(--gold)" href="$1" target="_blank" rel="noopener">$1</a>');
}
function renderChat() {
  var b = $('#sgBody'); if (!b) return;
  if (!G.msgs.length) G.msgs.push({ k: 'b', html: esc(t('hello')) });
  b.innerHTML = G.msgs.map(function (m, i) {
    if (m.k === 'u') return '<div class="sg-m u">' + esc(m.text) + '</div>';
    if (m.k === 'typing') return '<div class="sg-m b"><span class="sg-typing" aria-label="' + esc(t('thinking')) + '"><i></i><i></i><i></i></span></div>';
    if (m.k === 'note') return '<div class="sg-m note">' + esc(m.text) + '</div>';
    if (m.k === 'places') return placesHtml(m, i);
    if (m.k === 'dham') return dhamCard(m.d, true);
    return '<div class="sg-m b">' + m.html + (m.acts && m.acts.length ? '<div class="sg-acts">' + m.acts.map(function (a) {
      return /^https?:/.test(a.href || '') ? '<a class="sg-a gold" href="' + esc(a.href) + '" target="_blank" rel="noopener">' + esc(a.label) + '</a>' : '';
    }).join('') + '</div>' : '') + '</div>';
  }).join('');
  b.scrollTop = b.scrollHeight;
}
function placesHtml(m, mi) {
  if (!m.items.length) return '<div class="sg-m b">' + esc(t('none')) + '</div>';
  return '<div class="sg-m b" style="max-width:none;align-self:stretch;padding:0;background:none"><div style="padding:0 2px 6px;color:var(--text2)">' + esc(m.title) + '</div>' +
    '<div class="sg-card">' + m.items.map(function (p, i) {
      var ic = KIND_IC[p.kind] || (m.e && KIND_IC[m.e]) || GI.place;
      return '<div><button class="sg-row" type="button" data-pl="' + mi + ':' + i + ':open"><span class="sg-ric">' + ic + '</span>' +
        '<span class="sg-rt"><b>' + (i + 1) + '. ' + esc(p.name) + '</b><small>' + esc(p.sub || '') + '</small></span><span class="sg-rd">' + dLabel(distTo(p)) + '</span></button>' +
        '<div class="sg-rb"><button class="sg-a" type="button" data-pl="' + mi + ':' + i + ':dir">' + GI.dir + esc(t('dir')) + '</button>' +
        '<button class="sg-a cta" type="button" data-pl="' + mi + ':' + i + ':go">' + GI.go + esc(t('start')) + '</button></div></div>';
    }).join('') + '</div></div>';
}
function bodyClick(e) {
  var b = e.target.closest('[data-pl],[data-dh],[data-tf],[data-dhq],[data-post],[data-sosq],[data-vis],[data-park]');
  if (!b) return;
  if (b.dataset.pl) {
    var a = b.dataset.pl.split(':'), m = G.msgs[+a[0]] || (a[0] === 's' ? G.sosRes : a[0] === 'n' ? G.nearT : a[0] === 'v' ? G.visRes : null);
    var p = m && m.items[+a[1]]; if (!p) return;
    if (a[2] === 'open') { closeG(); afterClose(function () { X.openPlace(p); }); }
    else go(p, a[2] === 'go');
    return;
  }
  if (b.dataset.dh) {
    var parts = b.dataset.dh.split(':'), d = DHAMS.find(function (x) { return x.id === parts[0]; }); if (!d) return;
    var p2 = { name: L(d.n), sub: L(d.city), lat: d.lat, lng: d.lng, kind: 'temple' };
    if (parts[1] === 'go' || parts[1] === 'dir') go(p2, parts[1] === 'go');
    else if (parts[1] === 'ask') {
      if (hasAI()) askAI(t('askDham', { n: L(d.n) }));
      else { G.tab = 'ask'; render(); ask(t('askDham', { n: L(d.n) }), false); }
    }
    else if (parts[1] === 'open') { G.openDham = G.openDham === d.id ? null : d.id; renderTirtha(); }
    return;
  }
  if (b.dataset.tf) { G.tf = b.dataset.tf; if (G.tf === 'near') nearTemples(); else renderTirtha(); return; }
  if (b.dataset.post) { var po = POSTS.find(function (x) { return x.id === b.dataset.post; }); if (po) go({ name: L(po.n), sub: t('bTitle'), lat: po.lat, lng: po.lng }, false); return; }
  if (b.dataset.sosq) { sosNear(b.dataset.sosq); return; }
  if (b.dataset.vis === 'clear') { LSg.set('shg:ng:visits', []); if (G.tab === 'mine') renderMine(); else showVisits(true); return; }
  if (b.dataset.park === 'save') {
    if (!X.S.me) { X.toast(t('noLoc'), 3500); X.startGeo(); return; }
    savePark(false); renderMine(); return;
  }
  if (b.dataset.park === 'ai') { askAI(); return; }
  if (b.dataset.park === 'walk') { var pk = LSg.get('shg:ng:park', null); if (pk) go({ name: t('myCar'), sub: new Date(pk.t).toLocaleString(), lat: pk.lat, lng: pk.lng }, false, 'walk'); return; }
}
function go(p, start, mode) {
  closeG();
  afterClose(function () { X.go(p, { start: !!start, mode: mode }); });
}

/* the brain: our map first, the AI second */
function ask(text, byVoice) {
  text = String(text || '').trim(); if (!text) return;
  if (G.tab !== 'ask') { G.tab = 'ask'; render(); }
  push({ k: 'u', text: text });
  if (byVoice && !G.speak) { G.speak = true; LSg.set('shg:ng:speak', true); labels(); }
  var S = X.S, me = here(), low = text.toLowerCase();

  /* 1) "how far is left?" during navigation — answered from the drive itself */
  if (/(कति बाँकी|कति टाढा बाँकी|कति समय|कहिले पुग|कितना बाकी|कब पहुंच|कब पहुँच|how (far|long)( is)? left|eta|remaining)/i.test(text)) {
    if (S.nav && !S.nav.preview) {
      var tm = ($('#nbTime') || {}).textContent || '', sb = ($('#nbSub') || {}).textContent || '';
      var rem = S.nav.route && S.nav.route.L ? Math.max(0, S.nav.route.L.len - S.nav.along) : 0;
      return reply('<b>' + esc(t('left')) + ':</b> ' + esc(X.fmtDist(rem)) + (tm ? ' · ' + esc(tm) : '') + (sb ? '<br>' + esc(sb) : '') +
        (S.to ? '<br>→ ' + esc(S.to.name) : ''));
    }
    if (!/[a-zऀ-ॿ]{3,}\s+(कति|kitna|how)/i.test(text)) return reply(esc(t('notNav')));
  }

  /* 2) follow-up: "take me to the first one" */
  var idx = /(पहिलो|पहला|first|^1\b|१)/i.test(text) ? 0 : /(दोस्रो|दूसरा|second|^2\b|२)/i.test(text) ? 1 : /(तेस्रो|तीसरा|third|^3\b|३)/i.test(text) ? 2 : -1;
  if (idx >= 0 && G.last[idx] && text.length < 40) return go(G.last[idx], /(सुरु|start|शुरू|चलाउ|चलो)/i.test(text));

  /* 3) a named dham? (a city name also runs the place search below) */
  var fd = findDham(low), dh = fd && fd.d;
  var job, dhFound = [];
  if (dh) {
    G.last = [{ name: L(dh.n), sub: L(dh.city), lat: dh.lat, lng: dh.lng, kind: 'temple' }];
    push({ k: 'dham', d: dh });
    dhFound = [L(dh.n) + ' (' + L(dh.city) + (isFinite(distTo(dh)) ? ', ' + X.fmtDist(distTo(dh)) : '') + ')'];
    job = Promise.resolve(dhFound);
  }
  if (!dh || fd.city) {
    /* 4) a category ("petrol", "मन्दिर") near me */
    var cat = CATS.find(function (c) { return c.re.test(text); });
    var name = text.replace(STRIP, ' ').replace(/[?？।!.,]/g, ' ').replace(/\s+/g, ' ').trim();
    if (cat) name = name.replace(cat.re, ' ').replace(/\s+/g, ' ').trim();
    if (cat && (name.length < 3 || /^(को|का|की|के|मा|में|the|a)$/i.test(name))) {
      if (!me) { push({ k: 'note', text: t('noLoc') }); job = Promise.resolve([]); }
      else job = online() ? search(cat.q, me, 10).then(function (items) {
        items = items.filter(function (x) { return distTo(x) < 60000; }).sort(function (a, b) { return distTo(a) - distTo(b); }).slice(0, 5);
        G.last = items; push({ k: 'places', title: t('found'), items: items, e: cat.e });
        return items.map(function (x) { return x.name + ' (' + X.fmtDist(distTo(x)) + ')'; });
      }) : Promise.resolve([]);
    } else if (name.length >= 3 && (NAVWORD.test(text) || name.split(' ').length <= 4) && !BUSWORD.test(text) && online()) {
      /* 5) a named place: "Janakpur कसरी जाने", "Ahmedabad railway station" */
      job = search(name, me, 6).then(function (items) {
        items = items.slice(0, 4);
        if (items.length) { G.last = items; push({ k: 'places', title: t('foundNamed'), items: items }); }
        return items.map(function (x) { return x.name + ' — ' + (x.sub || '') + (isFinite(distTo(x)) ? ' (' + X.fmtDist(distTo(x)) + ')' : ''); });
      }).catch(function () { return []; });
    } else if (!dh) job = Promise.resolve([]);
    if (dh) job = job.then(function (f) { return f === dhFound ? f : dhFound.concat(f || []); }, function () { return dhFound; });
  }

  /* 6) the AI, told where the rider is and what the map found */
  if (!online()) { job.then(function () { push({ k: 'note', text: t('offline') }); }); return; }
  push({ k: 'typing' });
  var bus = BUSWORD.test(text);
  job.catch(function () { return []; }).then(function (found) {
    return (me ? areaName(me) : Promise.resolve('')).then(function (area) { return [found, area]; });
  }).then(function (fa) {
    var found = fa[0], area = fa[1];
    var msg = text;
    if (!bus) {
      var ctx = [];
      if (me) ctx.push('यात्रुको स्थान: ' + (area ? area + ', ' : '') + cName(countryAt(me)) + ' (' + me[1].toFixed(4) + ',' + me[0].toFixed(4) + ')');
      if (S.to && S.level !== 'home') ctx.push('गन्तव्य: ' + S.to.name);
      if (S.nav && !S.nav.preview && S.nav.route && S.nav.route.L) ctx.push('बाँकी दूरी: ' + X.fmtDist(Math.max(0, S.nav.route.L.len - S.nav.along)) + ', साधन: ' + S.mode);
      if (found && found.length) ctx.push('भेटिएका ठाउँ: ' + found.slice(0, 5).join('; '));
      msg = '[SHARI NAV एपबाट सोधिएको यात्राको प्रश्न। ' + ctx.join('. ') + '. उत्तर छोटो (४–६ वाक्य) र ' +
        (lang() === 'hi' ? 'हिन्दीमा' : lang() === 'en' ? 'अंग्रेजीमा' : 'सरल नेपालीमा') +
        ' दिनुस्; बाटो बताउँदा दिशा र दूरी भन्नुस्; थाहा नभएको कुरा नबनाउनुस्।]\n' + text;
    }
    /* not a bus question: the server skips its bus/office quick answers (WaLocal),
       which would read "map", "direction", "kasari pugne" as the office address */
    return aiAsk(msg, !bus);
  }).then(function (d) {
    unTyping();
    push({ k: 'b', html: fmtAI(d.text), acts: d.actions || [] });
    talk(d.text);
  }).catch(function () {
    unTyping();
    var hadResults = G.msgs.some(function (m) { return m.k === 'places' || m.k === 'dham'; });
    if (dh) talk(L(dh.n) + '. ' + (dh.time ? L(dh.time) : ''));
    push({ k: 'note', text: hadResults ? t('aiOff') : t('aiOff').split('—')[0].trim() });
  });
}
function unTyping() { G.msgs = G.msgs.filter(function (m) { return m.k !== 'typing'; }); if (G.open && G.tab === 'ask') renderChat(); }
function reply(html) { push({ k: 'b', html: html }); talk(html); }

/* voice in / out */
var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
function mic() {
  if (G.rec) return stopMic();
  if (!SR) { push({ k: 'note', text: t('noMic') }); return; }
  try { X.voUnlock(); } catch (e) {}
  var rec = new SR(), tried = false;
  function setup(l) {
    rec.lang = l; rec.interimResults = true; rec.maxAlternatives = 1; rec.continuous = false;
  }
  setup(lang() === 'ne' ? 'ne-NP' : lang() === 'hi' ? 'hi-IN' : 'en-IN');
  var input = $('#sgInput'), finalT = '';
  rec.onresult = function (e) {
    var s = ''; for (var i = e.resultIndex; i < e.results.length; i++) { s += e.results[i][0].transcript; if (e.results[i].isFinal) finalT += e.results[i][0].transcript; }
    input.value = finalT || s;
  };
  rec.onerror = function (e) {
    if (e.error === 'language-not-supported' && !tried) { tried = true; setup('hi-IN'); try { rec.start(); } catch (x) {} return; }
    stopMic();
  };
  rec.onend = function () {
    var v = (input.value || '').trim(); G.rec = null; $('#sgMic').classList.remove('rec'); input.placeholder = t('ph');
    if (v) { input.value = ''; ask(v, true); }
  };
  try { rec.start(); G.rec = rec; $('#sgMic').classList.add('rec'); input.value = ''; input.placeholder = t('listen'); X.vib(15); } catch (e) { G.rec = null; }
}
function stopMic() { if (G.rec) { try { G.rec.stop(); } catch (e) {} } G.rec = null; var m = $('#sgMic'); if (m) m.classList.remove('rec'); }
function talk(s) { if (!G.speak) return; var tx = speakable(s); if (tx) { try { X.sayText(tx, lang()); } catch (e) {} } }
function stopTalk() { try { speechSynthesis.cancel(); } catch (e) {} }

/* ---------------------------------------------------------------------
   TIRTHA
--------------------------------------------------------------------- */
function dhamCard(d, inChat) {
  var open = inChat || G.openDham === d.id;
  var dist = distTo(d);
  var h = '<div class="sg-card"' + (inChat ? '' : ' style="border-radius:14px"') + '><button class="sg-row" type="button" data-dh="' + d.id + ':open">' +
    '<span class="sg-ric ' + d.c + '">' + GI.temple + '</span><span class="sg-rt"><b>' + esc(L(d.n)) + '</b><small>' + esc(L(d.city)) + ' · ' + esc(L(d.d)) + '</small></span>' +
    '<span class="sg-rd">' + dLabel(dist) + '</span></button>';
  if (open) {
    h += '<dl class="sg-det">' +
      '<div><dt>' + esc(t('deity')) + '</dt><dd>' + esc(L(d.d)) + '</dd></div>' +
      (d.time ? '<div><dt>' + esc(t('hours')) + '</dt><dd>' + esc(L(d.time)) + '</dd></div>' : '') +
      (d.fest ? '<div><dt>' + esc(t('festT')) + '</dt><dd>' + esc(L(d.fest)) + '</dd></div>' : '') +
      (d.tip ? '<div><dt>' + esc(t('tipT')) + '</dt><dd>' + esc(L(d.tip)) + '</dd></div>' : '') +
      '<div style="font-size:12px;color:var(--text3)">' + esc(t('hoursNote')) + '</div></dl>' +
      '<div class="sg-rb" style="padding-left:12px"><button class="sg-a" type="button" data-dh="' + d.id + ':dir">' + GI.dir + esc(t('dir')) + '</button>' +
      '<button class="sg-a cta" type="button" data-dh="' + d.id + ':go">' + GI.go + esc(t('start')) + '</button>' +
      (inChat ? '' : '<button class="sg-a gold" type="button" data-dh="' + d.id + ':ask">' + GI.spark + esc(t('askAbout')) + '</button>') + '</div>';
  }
  return h + '</div>';
}
function renderTirtha() {
  var b = $('#sgBody'); if (!b) return;
  var seg = '<div class="sg-seg">' + [['all', t('allC')], ['np', t('np')], ['in', t('inn')], ['near', t('nearT')]].map(function (x) {
    return '<button type="button" data-tf="' + x[0] + '" class="' + (G.tf === x[0] ? 'on' : '') + '">' + esc(x[1]) + '</button>';
  }).join('') + '</div>';
  if (G.tf === 'near') {
    var nt = G.nearT;
    b.innerHTML = seg + (nt ? placesHtml(nt, 'n') : '<div class="sg-m b"><span class="sg-typing"><i></i><i></i><i></i></span></div>');
    showPins([]);
    return;
  }
  var list = DHAMS.filter(function (d) { return G.tf === 'all' || d.c === G.tf; }).slice().sort(function (a, c) { return distTo(a) - distTo(c); });
  b.innerHTML = seg + list.map(function (d) { return dhamCard(d, false); }).join('');
  showPins(list);
}
function nearTemples() {
  G.nearT = null; renderTirtha();
  var me = here();
  if (!me) { G.nearT = { title: t('noLoc'), items: [] }; renderTirtha(); return; }
  search('temple', me, 12).then(function (items) {
    items = items.filter(function (x) { return distTo(x) < 30000; }).sort(function (a, c) { return distTo(a) - distTo(c); }).slice(0, 8);
    G.nearT = { title: t('nearT'), items: items, e: 'temple' }; G.last = items;
    if (G.open && G.tab === 'tirtha' && G.tf === 'near') renderTirtha();
  }).catch(function () { G.nearT = { title: t('nearT'), items: [] }; if (G.tab === 'tirtha') renderTirtha(); });
}
function showPins(list) {
  clearPins();
  if (!window.maplibregl || !X.S.map) return;
  list.forEach(function (d) {
    var el = document.createElement('div'); el.className = 'sg-pin'; el.innerHTML = GI.temple; el.title = L(d.n);
    el.addEventListener('click', function (e) { e.stopPropagation(); G.openDham = d.id; G.tf = d.c === G.tf ? G.tf : 'all'; openG('tirtha'); });
    var w = document.createElement('div'); w.appendChild(el);
    G.pins.push(new maplibregl.Marker({ element: w, anchor: 'bottom' }).setLngLat([d.lng, d.lat]).addTo(X.S.map));
  });
}
function clearPins() { G.pins.forEach(function (m) { try { m.remove(); } catch (e) {} }); G.pins = []; }

/* ---------------------------------------------------------------------
   BORDER
--------------------------------------------------------------------- */
var B = { cur: null, sel: -1, cross: null, warned: {} };
function analyseRoute() {
  var S = X.S;
  if (!S.cur || !S.cur.routes || !S.cur.routes[S.sel]) { B.cur = S.cur; B.sel = S.sel; B.cross = null; return; }
  if (B.cur === S.cur && B.sel === S.sel) return;
  B.cur = S.cur; B.sel = S.sel; B.cross = null;
  var c = S.cur.routes[S.sel].coords; if (!c || c.length < 2) return;
  /* quick reject: route nowhere near Nepal */
  var near = false;
  for (var i = 0; i < c.length; i += Math.max(1, Math.floor(c.length / 400))) { if (c[i][0] > 79.8 && c[i][0] < 88.4 && c[i][1] > 26.1 && c[i][1] < 30.6) { near = true; break; } }
  if (!near) return;
  var step = Math.max(1, Math.floor(c.length / 1500)), prev = inNepal(c[0][0], c[0][1]), along = 0, last = c[0];
  for (var k = step; k < c.length; k += step) {
    along += X.hav(last, c[k]); last = c[k];
    var nowIn = inNepal(c[k][0], c[k][1]);
    if (nowIn !== prev) {
      var post = POSTS.slice().sort(function (a, b) { return X.hav([a.lng, a.lat], c[k]) - X.hav([b.lng, b.lat], c[k]); })[0];
      B.cross = { at: c[k], along: along, into: nowIn ? 'np' : 'in', post: X.hav([post.lng, post.lat], c[k]) < 25000 ? post : null };
      break;
    }
    prev = nowIn;
  }
  if (B.cross && S.level === 'dir') {
    if (!hasAI()) X.toast(t('bRouteToast'), 4200);     /* the page's border module shows its own card */
    G.borderNew = true; var f = $('#sgFab'); if (f) f.classList.add('alert');
  }
}
function renderBorder() {
  var b = $('#sgBody'); if (!b) return;
  var me = here(), h = '';
  if (B.cross) {
    h += '<div class="sg-alert"><b>' + esc(t('bRoute')) + '</b> ' + esc(B.cross.post ? L(B.cross.post.n) : (B.cross.at[1].toFixed(3) + ', ' + B.cross.at[0].toFixed(3))) +
      ' → ' + esc(cName(B.cross.into)) + (X.S.nav ? '' : ' · ' + esc(X.fmtDist(B.cross.along))) + '</div>';
  }
  h += '<div class="sg-h3">' + esc(t('docsT')) + '</div><ul class="sg-ul">' + t('docs').map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>';
  h += '<div class="sg-h3">' + esc(t('curT')) + '</div>' +
    '<div class="sg-cur"><label>₹ ' + esc(t('inn')) + '<input id="sgInr" type="number" inputmode="decimal" min="0" value="1000"></label>' +
    '<span class="sg-ib" aria-hidden="true">' + GI.swap + '</span>' +
    '<label>रु ' + esc(t('np')) + '<input id="sgNpr" type="number" inputmode="decimal" min="0" value="1600"></label></div>' +
    '<p class="sg-p" style="font-size:12.5px;color:var(--text3)">' + esc(t('curNote')) + '</p>';
  var posts = POSTS.slice().sort(function (a, c) { return (me ? X.hav(me, [a.lng, a.lat]) - X.hav(me, [c.lng, c.lat]) : 0); });
  h += '<div class="sg-h3">' + esc(t('postsT')) + '</div><div class="sg-card">' + posts.map(function (p) {
    return '<button class="sg-row" type="button" data-post="' + p.id + '"><span class="sg-ric">' + GI.flag + '</span><span class="sg-rt"><b>' + esc(L(p.n)) + '</b>' +
      (p.shg ? '<small style="color:var(--gold)">' + esc(t('shgPost')) + '</small>' : '<small>' + esc(t('np')) + ' ⇄ ' + esc(t('inn')) + '</small>') +
      '</span><span class="sg-rd">' + dLabel(me ? X.hav(me, [p.lng, p.lat]) : Infinity) + '</span></button>';
  }).join('') + '</div>';
  b.innerHTML = h;
  var inr = $('#sgInr'), npr = $('#sgNpr');
  function r2(v) { return Math.round(v * 100) / 100; }
  inr.oninput = function () { var v = parseFloat(inr.value); npr.value = isFinite(v) ? r2(v * 1.6) : ''; };
  npr.oninput = function () { var v = parseFloat(npr.value); inr.value = isFinite(v) ? r2(v / 1.6) : ''; };
}

/* ---------------------------------------------------------------------
   SOS
--------------------------------------------------------------------- */
function renderSos() {
  var b = $('#sgBody'); if (!b) return;
  var me = here(), c = X.S.me ? countryAt([X.S.me.lng, X.S.me.lat]) : countryAt(me);
  var h = '<div class="sg-h3">' + esc(t('sosT')) + ' · ' + esc(t('youIn', { c: cName(c) })) + '</div>' +
    '<div class="sg-sos">' + SOS[c].map(function (n) {
      return '<a class="sg-num" href="tel:' + n[0] + '"><b>' + n[0] + '</b><small>' + esc(L(n[1])) + '</small></a>';
    }).join('') + '</div>';
  var other = c === 'np' ? 'in' : 'np';
  h += '<p class="sg-p" style="font-size:12.5px;color:var(--text3)">' + esc(cName(other)) + ': ' + SOS[other].slice(0, 3).map(function (n) { return n[0] + ' ' + L(n[1]); }).join(' · ') + '</p>';
  h += '<div class="sg-acts">' +
    '<button class="sg-a cta" type="button" data-sosq="share">' + GI.pin + esc(t('sendLoc')) + '</button>' +
    '<button class="sg-a" type="button" data-sosq="hospital">' + GI.health + esc(t('nHosp')) + '</button>' +
    '<button class="sg-a" type="button" data-sosq="police">' + GI.police + esc(t('nPol')) + '</button>' +
    '<button class="sg-a" type="button" data-sosq="petrol">' + GI.fuel + esc(t('nFuel')) + '</button></div>';
  if (G.sosRes) h += G.sosRes.loading ? '<div class="sg-m b"><span class="sg-typing"><i></i><i></i><i></i></span></div>' : placesHtml(G.sosRes, 's');
  b.innerHTML = h;
}
function sosNear(q) {
  var me = here();
  if (q === 'share') {
    if (!X.S.me) { X.toast(t('noLoc')); X.startGeo(); return; }
    var url = 'https://www.shreehariglobal.in/navigate.html?to=' + X.S.me.lat.toFixed(6) + ',' + X.S.me.lng.toFixed(6) + '&name=' + encodeURIComponent('SOS');
    var txt = 'SOS — ' + (lang() === 'en' ? 'I need help. My location: ' : lang() === 'hi' ? 'मुझे मदद चाहिए। मेरी लोकेशन: ' : 'मलाई सहयोग चाहियो। मेरो लोकेसन: ') + url +
      ' (Google Maps: https://maps.google.com/?q=' + X.S.me.lat.toFixed(6) + ',' + X.S.me.lng.toFixed(6) + ')';
    if (navigator.share) { navigator.share({ title: 'SOS', text: txt }).catch(function () {}); }
    else window.open('https://wa.me/?text=' + encodeURIComponent(txt), '_blank', 'noopener');
    return;
  }
  if (!me) { X.toast(t('noLoc')); return; }
  G.sosRes = { loading: true, items: [] }; renderSos();
  search(q, me, 10).then(function (items) {
    items = items.filter(function (x) { return distTo(x) < 50000; }).sort(function (a, c) { return distTo(a) - distTo(c); }).slice(0, 5);
    G.sosRes = { title: q === 'hospital' ? t('nHosp') : q === 'police' ? t('nPol') : t('nFuel'), items: items, e: q === 'hospital' ? 'health' : q === 'police' ? 'police' : 'fuel' };
    if (G.open && G.tab === 'sos') renderSos();
  }).catch(function () { G.sosRes = null; if (G.open && G.tab === 'sos') renderSos(); });
}

/* ---------------------------------------------------------------------
   PARKING + VISITS (this phone only)
--------------------------------------------------------------------- */
function savePark(say) {
  var S = X.S;
  if (!S.me) { if (say) { push({ k: 'note', text: t('noLoc') }); X.startGeo(); } return; }
  LSg.set('shg:ng:park', { lat: S.me.lat, lng: S.me.lng, t: Date.now() });
  if (say) { reply(esc(t('parked'))); quickChips(); } else X.toast(t('parked'), 3000);
}
function showCar() {
  var pk = LSg.get('shg:ng:park', null);
  if (!pk) return reply(esc(t('noPark')));
  var d = distTo(pk);
  push({ k: 'b', html: '<b>' + esc(t('myCar')) + '</b> · ' + esc(new Date(pk.t).toLocaleString()) + (isFinite(d) ? ' · ' + esc(X.fmtDist(d)) : '') +
    '<div class="sg-acts"><button class="sg-a cta" type="button" data-park="walk">' + GI.go + esc(t('walkThere')) + '</button></div>' });
}
/* "Mine" tab (pages with their own AI chat): parking + visits, which live in the chat otherwise */
function renderMine() {
  var b = $('#sgBody'); if (!b) return;
  var pk = LSg.get('shg:ng:park', null), d = pk ? distTo(pk) : Infinity;
  var h = '<div class="sg-h3">' + esc(t('parkT')) + '</div><div class="sg-card"><div class="sg-row" style="cursor:default"><span class="sg-ric">' + GI.park + '</span>' +
    '<span class="sg-rt"><b>' + esc(pk ? t('myCar') : t('noPark')) + '</b>' + (pk ? '<small>' + esc(new Date(pk.t).toLocaleString()) + '</small>' : '') + '</span>' +
    '<span class="sg-rd">' + dLabel(d) + '</span></div><div class="sg-rb" style="padding-left:12px">' +
    (pk ? '<button class="sg-a cta" type="button" data-park="walk">' + GI.go + esc(t('walkThere')) + '</button>' : '') +
    '<button class="sg-a" type="button" data-park="save">' + GI.park + esc(t('qPark')) + '</button></div></div>';
  var v = LSg.get('shg:ng:visits', []);
  G.visRes = { title: t('visitsT'), items: v.slice(0, 20).map(function (x) { return { name: x.name, sub: new Date(x.t).toLocaleDateString(), lat: x.lat, lng: x.lng, kind: x.kind || '' }; }) };
  h += '<div class="sg-h3">' + esc(t('qVisits')) + '</div>' + (v.length ? placesHtml(G.visRes, 'v') +
    '<div class="sg-acts" style="margin:0"><button class="sg-a" type="button" data-vis="clear">' + GI.x + esc(t('clear')) + '</button></div>' : '<div class="sg-empty">' + esc(t('noVisits')) + '</div>');
  h += '<div class="sg-acts"><button class="sg-a gold" type="button" data-park="ai">' + GI.spark + esc(t('chipAsk')) + '</button></div>';
  b.innerHTML = h;
}
function showVisits(cleared) {
  var v = LSg.get('shg:ng:visits', []);
  if (cleared) G.msgs = G.msgs.filter(function (m) { return !m.visits; });
  if (!v.length) return reply(esc(t('noVisits')));
  var items = v.slice(0, 12).map(function (x) { return { name: x.name, sub: new Date(x.t).toLocaleDateString(), lat: x.lat, lng: x.lng, kind: x.kind || '' }; });
  G.last = items;
  push({ k: 'places', title: t('visitsT'), items: items, visits: true });
  push({ k: 'b', visits: true, html: '<div class="sg-acts" style="margin:0"><button class="sg-a" type="button" data-vis="clear">' + GI.x + esc(t('clear')) + '</button></div>' });
}

/* ---------------------------------------------------------------------
   WATCHER (once a second, cheap): border, landmark hint, arrival, country
--------------------------------------------------------------------- */
var W = { arrivedFor: null, lmFor: null, lmEl: null, country: null, cAt: 0, lang: null };
function tick() {
  var S = X.S;
  if (W.lang !== S.lang) { W.lang = S.lang; labels(); if (G.open) render(); }
  analyseRoute();
  var N = S.nav;
  /* border ahead (once per route) - on a page with its own border module, that one speaks */
  if (N && !N.preview && B.cross && B.cur === S.cur && !hasAI()) {
    var ahead = B.cross.along - (N.along || 0);
    if (ahead > 0 && ahead < 5000 && !B.warned[B.cross.along]) { B.warned[B.cross.along] = 1; X.toast(t('bAhead'), 6000); talkForce(t('bAhead')); }
  }
  /* landmark hint for the next turn */
  landmark(N);
  /* arrival -> visit log + parking memory */
  if (N && N.arrived && W.arrivedFor !== N) {
    W.arrivedFor = N;
    if (S.to && !S.to.me) {
      var v = LSg.get('shg:ng:visits', []);
      v.unshift({ name: S.to.name, lat: S.to.lat, lng: S.to.lng, kind: S.to.kind || '', t: Date.now() });
      LSg.set('shg:ng:visits', v.slice(0, 100));
    }
    if (S.mode === 'car' || S.mode === 'bike') setTimeout(function () { savePark(false); }, 1500);
  }
  /* crossed into the other country (by GPS, every 15 s) */
  if (S.me && Date.now() - W.cAt > 15000) {
    W.cAt = Date.now();
    var c = countryAt([S.me.lng, S.me.lat]);
    if (W.country && c !== W.country && !hasAI()) { var wm = TX.welcome[lang()][c]; X.toast(wm, 6000); talkForce(wm); }
    W.country = c;
  }
}
function talkForce(s) { if (X.S.sound === 'off') return; try { X.sayText(speakable(s), lang()); } catch (e) {} }
var LMC = {};
function landmark(N) {
  var show = null;
  if (N && !N.preview && !N.arrived && !N.offRoute && N.route && N.route.steps) {
    var m = N.route.steps[N.k + 1];
    if (m && m.loc && m.type !== 'arrive' && !m.stop && m.type !== 'depart') {
      var d = m.along - N.along;
      if (d > 30 && d < 900) {
        var key = m.loc[1].toFixed(5) + ',' + m.loc[0].toFixed(5);
        if (LMC[key] === undefined && online()) {
          LMC[key] = null;
          X.fetchT('/api/nav-search.php?rev=' + m.loc[1].toFixed(5) + ',' + m.loc[0].toFixed(5), { credentials: 'same-origin' }, 5000)
            .then(function (r) { return r.json(); })
            .then(function (j) {
              var nm = (j && j.name) || '';
              var road = (m.name || '') + ' ' + (m.ref || '');
              if (!nm || nm.length > 48 || /(road|marg|path|highway|street|lane|गल्ली|मार्ग|पथ|सडक|रोड|राजमार्ग|NH|SH)/i.test(nm) || road.indexOf(nm) >= 0) nm = '';
              LMC[key] = nm;
            }).catch(function () { LMC[key] = ''; });
        }
        if (LMC[key]) show = LMC[key];
      }
    }
  }
  var el = W.lmEl;
  if (!show) { if (el) el.hidden = true; return; }
  if (!el) { el = W.lmEl = document.createElement('div'); el.className = 'sg-lm'; document.body.appendChild(el); }
  var top = $('#navTop'); if (top) { var r = top.getBoundingClientRect(); document.documentElement.style.setProperty('--sg-lmtop', Math.round(r.bottom + 8) + 'px'); el.style.top = Math.round(r.bottom + 8) + 'px'; }
  el.innerHTML = GI.pin + '<span>' + esc(t('near')) + ': ' + esc(show) + '</span>';
  el.hidden = false;
}

/* ---------------------------------------------------------------------
   START
--------------------------------------------------------------------- */
function start() {
  if (X || !window.SHGNAV_X) return;
  X = window.SHGNAV_X;
  build();
  setInterval(function () { try { tick(); } catch (e) { if (window.console) console.warn('nav-guide', e); } }, 1000);
  /* deep link: navigate.html?guide=1 | ?guide=tirtha | border | sos */
  var g = new URLSearchParams(location.search).get('guide');
  if (g) setTimeout(function () { openG(['ask', 'tirtha', 'border', 'sos', 'mine'].indexOf(g) >= 0 ? g : 'ask'); }, 900);
  if (new URLSearchParams(location.search).has('debug')) window.SHGGUIDE = { G: G, B: B, inNepal: inNepal, ask: ask, openG: openG };
}
if (window.SHGNAV_X) start(); else document.addEventListener('shgnav:ready', start, { once: true });
})();
