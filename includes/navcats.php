<?php
/**
 * =====================================================================
 *  includes/navcats.php - "<any kind of place> near me" (22 Oct 2026).
 *
 *  Owner: "suggest me restaurant near me, near hair salon jasta question
 *  easily fetch garera dina paros, ekdam fast". The SHARI NAV guide knew
 *  13 kinds of place (food, petrol, ATM ...); "hair salon near me", "gym",
 *  "cafe", "mobile shop" went to the AI assistant, which cannot see the
 *  map and answered nothing useful.
 *
 *  nav_places already holds 1.5 million named places of India + Nepal
 *  with their OpenStreetMap kind (shop:hairdresser, amenity:cafe, ...)
 *  and an index on (kind, lat, lng). So one lexicon - English, Hindi,
 *  Nepali, Roman and Devanagari, common typos - turns a question into
 *  kinds, and one indexed box query per radius answers it in a few ms.
 *  No AI key, no outside service.
 *
 *    NavCats::parse($text)            -> {cat, rest[], near} (pure, tested)
 *    NavCats::search($cat|null, $rest, $lat, $lng, $limit) -> rows, nearest first
 *
 *  Used by api/nav-near.php (the guide's answers) and api/nav-search.php
 *  (the search box: typing "salon" lists the salons around you).
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class NavCats
{
    /** half-box sizes in degrees, tried in turn until enough places are found (~3, 7, 17, 45 km) */
    public const RADII = [0.025, 0.06, 0.15, 0.4];
    public const ENOUGH = 5;

    /**
     * id => [emoji, [ne, hi, en] label, kinds, words]. Words are matched as
     * whole tokens (a Roman word of 4+ letters also takes a short ending:
     * "salons", "restaurants"); a word with a space is matched as a phrase.
     * The longest match wins, so "bus stand" beats "bus" and "ice cream"
     * beats "cream".
     *
     * @var array<string, array{0:string, 1:array{0:string,1:string,2:string}, 2:list<string>, 3:list<string>}>
     */
    public const CATS = [
        'food' => ['🍽️', ['खाना / रेस्टुरेन्ट', 'खाना / रेस्टोरेंट', 'restaurants'], ['amenity:restaurant', 'amenity:food_court'],
            ['restaurant', 'restro', 'restaurent', 'resturant', 'resturent', 'restorant', 'restorent', 'ressurtent', 'restaurnt', 'restuarant', 'restraunt',
             'food', 'khana', 'khaana', 'khane', 'bhojan', 'dhaba', 'thali', 'lunch', 'dinner', 'breakfast', 'nasta', 'nashta', 'biryani', 'veg',
             'खाना', 'भोजन', 'ढाबा', 'थाली', 'रेस्टुरेन्ट', 'रेस्टुरेंट', 'रेस्टोरेंट', 'रेस्टोरेन्ट', 'रेष्टुरेन्ट', 'नास्ता', 'नाश्ता', 'खाजा', 'khaja', 'food court']],
        'fastfood' => ['🍔', ['फास्ट फुड', 'फास्ट फूड', 'fast food'], ['amenity:fast_food'],
            ['fast food', 'fastfood', 'burger', 'pizza', 'momo', 'momos', 'chowmein', 'chaumin', 'snacks', 'chaat', 'samosa',
             'पिज्जा', 'मोमो', 'बर्गर', 'चाउमिन', 'चाट', 'समोसा']],
        'cafe' => ['☕', ['क्याफे / चिया', 'कैफे / चाय', 'cafes'], ['amenity:cafe', 'shop:coffee', 'shop:tea'],
            ['cafe', 'café', 'coffee', 'chai', 'chiya', 'tea', 'tea shop', 'चिया', 'चाय', 'कफी', 'कॉफी', 'क्याफे', 'कैफे']],
        'bakery' => ['🥐', ['बेकरी / मिठाई', 'बेकरी / मिठाई', 'bakeries & sweets'], ['shop:bakery', 'shop:pastry', 'shop:confectionery'],
            ['bakery', 'cake', 'pastry', 'sweets', 'sweet shop', 'mithai', 'misthan', 'बेकरी', 'केक', 'मिठाई', 'मिष्ठान']],
        'icecream' => ['🍦', ['आइसक्रिम', 'आइसक्रीम', 'ice cream'], ['amenity:ice_cream'],
            ['ice cream', 'icecream', 'kulfi', 'आइसक्रिम', 'आइसक्रीम', 'कुल्फी']],
        'bar' => ['🍺', ['बार', 'बार', 'bars & pubs'], ['amenity:bar', 'amenity:pub', 'amenity:nightclub'],
            ['bar', 'pub', 'beer', 'nightclub', 'club', 'बार', 'बियर']],
        'liquor' => ['🍷', ['वाइन पसल', 'शराब की दुकान', 'liquor shops'], ['shop:alcohol', 'shop:wine'],
            ['liquor', 'wine', 'wine shop', 'daru', 'thekka', 'theka', 'दारु', 'दारू', 'ठेका', 'शराब', 'रक्सी', 'raksi']],
        'salon' => ['💇', ['सैलुन / ब्युटी पार्लर', 'सैलून / ब्यूटी पार्लर', 'salons & parlours'], ['shop:hairdresser', 'shop:beauty', 'shop:massage'],
            ['salon', 'saloon', 'sallon', 'hair salon', 'hair saloon', 'hair cut', 'haircut', 'barber', 'nai', 'hajam', 'hajaam', 'parlour', 'parlor',
             'beauty parlour', 'beauty parlor', 'beauty', 'makeup', 'spa', 'massage', 'shave', 'facial', 'threading',
             'सैलुन', 'सैलून', 'सलून', 'नाई', 'हजाम', 'पार्लर', 'ब्युटी', 'ब्यूटी', 'स्पा', 'मसाज', 'कपाल काट्ने', 'बाल काटने']],
        'gym' => ['🏋️', ['जिम', 'जिम', 'gyms'], ['leisure:fitness_centre', 'leisure:sports_centre'],
            ['gym', 'gymnasium', 'fitness', 'workout', 'yoga', 'जिम', 'योग']],
        'hospital' => ['🏥', ['अस्पताल', 'अस्पताल', 'hospitals'], ['amenity:hospital', 'healthcare:hospital'],
            ['hospital', 'hospitel', 'aspatal', 'aspataal', 'emergency ward', 'अस्पताल', 'हस्पिटल', 'हॉस्पिटल']],
        'clinic' => ['🩺', ['क्लिनिक / डाक्टर', 'क्लिनिक / डॉक्टर', 'clinics & doctors'], ['amenity:clinic', 'amenity:doctors', 'healthcare:clinic', 'healthcare:centre', 'amenity:health_post'],
            ['clinic', 'doctor', 'doctors', 'daktar', 'health post', 'health centre', 'health center', 'क्लिनिक', 'डाक्टर', 'डॉक्टर', 'स्वास्थ्य चौकी']],
        'dentist' => ['🦷', ['दाँतको डाक्टर', 'दांत का डॉक्टर', 'dentists'], ['amenity:dentist'],
            ['dentist', 'dental', 'daant', 'dant', 'teeth', 'दाँत', 'दांत']],
        'pharmacy' => ['💊', ['औषधि पसल', 'दवा की दुकान', 'pharmacies'], ['amenity:pharmacy', 'shop:chemist', 'shop:medical_supply'],
            ['pharmacy', 'medical', 'medical store', 'medical shop', 'chemist', 'medicine', 'dawai', 'dawa', 'aushadhi', 'ausadhi',
             'औषधि', 'औषधी', 'दवा', 'दवाई', 'मेडिकल', 'फार्मेसी']],
        'lab' => ['🧪', ['ल्याब / जाँच', 'लैब / जांच', 'labs'], ['healthcare:laboratory'],
            ['lab', 'laboratory', 'pathology', 'blood test', 'xray', 'x-ray', 'ल्याब', 'लैब', 'जाँच', 'जांच']],
        'vet' => ['🐾', ['पशु अस्पताल', 'पशु चिकित्सक', 'vets'], ['amenity:veterinary', 'shop:pet'],
            ['vet', 'veterinary', 'pet', 'pet shop', 'पशु', 'भेटेरिनरी']],
        'optician' => ['👓', ['चस्मा पसल', 'चश्मा की दुकान', 'opticians'], ['shop:optician'],
            ['optician', 'optical', 'spectacles', 'glasses', 'chashma', 'chasma', 'चस्मा', 'चश्मा']],
        'atm' => ['🏧', ['ATM', 'ATM', 'ATMs'], ['amenity:atm'], ['atm', 'atms', 'cash', 'एटीएम', 'नगद']],
        'bank' => ['🏦', ['बैंक', 'बैंक', 'banks'], ['amenity:bank'], ['bank', 'banks', 'बैंक']],
        'exchange' => ['💱', ['पैसा सटही / रेमिट', 'मनी एक्सचेंज', 'money exchange'], ['amenity:bureau_de_change', 'amenity:money_transfer'],
            ['money exchange', 'exchange', 'satahi', 'sahati', 'remit', 'remittance', 'money transfer', 'western union', 'सटही', 'रेमिट', 'रेमिट्यान्स']],
        'fuel' => ['⛽', ['पेट्रोल पम्प', 'पेट्रोल पंप', 'fuel stations'], ['amenity:fuel'],
            ['petrol', 'petrol pump', 'petrol station', 'fuel station', 'diesel', 'fuel', 'pump', 'cng', 'gas station', 'पेट्रोल', 'डिजेल', 'डीजल', 'पम्प', 'पंप']],
        'ev' => ['🔌', ['EV चार्जिङ', 'EV चार्जिंग', 'EV charging'], ['amenity:charging_station'],
            ['ev', 'charging', 'charging station', 'ev charger', 'चार्जिङ', 'चार्जिंग']],
        'hotel' => ['🏨', ['होटल / लज', 'होटल / लॉज', 'hotels & stays'], ['tourism:hotel', 'tourism:guest_house', 'tourism:motel', 'tourism:hostel'],
            ['hotel', 'hotels', 'lodge', 'guest house', 'guesthouse', 'homestay', 'home stay', 'hostel', 'motel', 'room', 'stay', 'dharamshala',
             'होटल', 'लज', 'लॉज', 'गेस्ट हाउस', 'होमस्टे', 'धर्मशाला', 'कोठा', 'कमरा']],
        'temple' => ['🛕', ['मन्दिर / धार्मिक स्थल', 'मंदिर / धार्मिक स्थल', 'temples & worship'], ['amenity:place_of_worship', 'amenity:monastery'],
            ['temple', 'mandir', 'mosque', 'masjid', 'church', 'gurudwara', 'gurdwara', 'gompa', 'monastery', 'stupa',
             'मन्दिर', 'मंदिर', 'मस्जिद', 'चर्च', 'गुरुद्वारा', 'गुम्बा', 'स्तूप']],
        'police' => ['🚓', ['प्रहरी', 'पुलिस', 'police'], ['amenity:police'],
            ['police', 'police station', 'thana', 'chowki', 'prahari', 'प्रहरी', 'पुलिस', 'थाना', 'प्रहरी चौकी', 'पुलिस स्टेशन']],
        'fire' => ['🚒', ['दमकल', 'फायर स्टेशन', 'fire stations'], ['amenity:fire_station'], ['fire station', 'fire brigade', 'damkal', 'दमकल']],
        'post' => ['📮', ['हुलाक', 'डाकघर', 'post offices'], ['amenity:post_office'], ['post office', 'post', 'courier', 'hulak', 'dak ghar', 'हुलाक', 'डाकघर']],
        'school' => ['🏫', ['विद्यालय', 'स्कूल', 'schools'], ['amenity:school', 'amenity:prep_school', 'amenity:kindergarten'],
            ['school', 'schools', 'vidyalaya', 'kindergarten', 'स्कूल', 'विद्यालय']],
        'college' => ['🎓', ['कलेज / विश्वविद्यालय', 'कॉलेज / विश्वविद्यालय', 'colleges'], ['amenity:college', 'amenity:university'],
            ['college', 'university', 'campus', 'कलेज', 'कॉलेज', 'विश्वविद्यालय', 'क्याम्पस']],
        'library' => ['📚', ['पुस्तकालय', 'पुस्तकालय', 'libraries'], ['amenity:library'], ['library', 'pustakalaya', 'पुस्तकालय']],
        'park' => ['🌳', ['पार्क / बगैंचा', 'पार्क / बगीचा', 'parks'], ['leisure:park', 'leisure:garden', 'leisure:playground'],
            ['park', 'garden', 'playground', 'bagaicha', 'पार्क', 'बगैंचा', 'बगीचा']],
        'cinema' => ['🎬', ['सिनेमा हल', 'सिनेमा हॉल', 'cinemas'], ['amenity:cinema', 'amenity:theatre'],
            ['cinema', 'movie', 'movies', 'film', 'theatre', 'theater', 'multiplex', 'सिनेमा', 'फिल्म', 'चलचित्र']],
        'mall' => ['🛍️', ['मल / डिपार्टमेन्ट स्टोर', 'मॉल', 'malls'], ['shop:mall', 'shop:department_store'],
            ['mall', 'shopping', 'shopping mall', 'department store', 'मल', 'मॉल']],
        'grocery' => ['🛒', ['किराना / सुपरमार्केट', 'किराना / सुपरमार्केट', 'grocery & supermarkets'],
            ['shop:supermarket', 'shop:convenience', 'shop:grocery', 'shop:general', 'shop:variety_store'],
            ['grocery', 'kirana', 'supermarket', 'super market', 'mart', 'general store', 'convenience', 'provision', 'किराना', 'सुपरमार्केट', 'मार्ट']],
        'vegetables' => ['🥦', ['तरकारी / फलफूल', 'सब्जी / फल', 'vegetables & fruit'], ['shop:greengrocer'],
            ['vegetable', 'vegetables', 'sabji', 'sabzi', 'tarkari', 'fruit', 'fruits', 'phal', 'तरकारी', 'सब्जी', 'फलफूल', 'फल']],
        'market' => ['🧺', ['बजार', 'बाज़ार', 'markets'], ['amenity:marketplace'], ['market', 'bazar', 'bazaar', 'haat', 'hatiya', 'mandi', 'sabji market', 'sabzi mandi', 'vegetable market', 'मण्डी', 'मंडी', 'बजार', 'बाजार', 'बाज़ार', 'हाट']],
        'meat' => ['🍗', ['मासु पसल', 'मीट की दुकान', 'meat & fish'], ['shop:butcher', 'shop:seafood'],
            ['meat', 'chicken', 'mutton', 'fish', 'masu', 'machha', 'मासु', 'माछा', 'मीट', 'चिकन', 'मटन', 'मछली']],
        'dairy' => ['🥛', ['दुध पसल', 'डेयरी', 'dairies'], ['shop:dairy'], ['dairy', 'milk', 'doodh', 'dudh', 'paneer', 'दुध', 'दूध', 'डेरी', 'डेयरी']],
        'clothes' => ['👕', ['कपडा पसल', 'कपड़े की दुकान', 'clothes shops'], ['shop:clothes', 'shop:boutique', 'shop:fabric', 'shop:fashion_accessories'],
            ['clothes', 'clothing', 'cloth', 'kapda', 'kapada', 'kapara', 'garment', 'garments', 'boutique', 'saree', 'sari', 'कपडा', 'कपड़ा', 'साडी', 'साड़ी']],
        'tailor' => ['🧵', ['सिलाई / दर्जी', 'दर्ज़ी', 'tailors'], ['shop:tailor'], ['tailor', 'darji', 'darzi', 'silai', 'दर्जी', 'दर्ज़ी', 'सिलाई']],
        'shoes' => ['👟', ['जुत्ता पसल', 'जूते की दुकान', 'shoe shops'], ['shop:shoes'], ['shoes', 'shoe', 'footwear', 'juta', 'jutta', 'chappal', 'जुत्ता', 'जूता', 'चप्पल']],
        'jewellery' => ['💍', ['गहना / सुनार', 'ज्वेलरी / सुनार', 'jewellers'], ['shop:jewelry', 'shop:watches'],
            ['jewellery', 'jewelry', 'jeweller', 'jewellers', 'gold', 'sunar', 'gahana', 'watch', 'गहना', 'सुनार', 'ज्वेलरी', 'घडी', 'घड़ी']],
        'mobile' => ['📱', ['मोबाइल पसल', 'मोबाइल की दुकान', 'mobile shops'], ['shop:mobile_phone'],
            ['mobile', 'mobile shop', 'phone', 'phone shop', 'mobile repair', 'recharge', 'sim', 'मोबाइल', 'फोन', 'रिचार्ज', 'सिम']],
        'electronics' => ['🔌', ['इलेक्ट्रोनिक्स', 'इलेक्ट्रॉनिक्स', 'electronics'], ['shop:electronics', 'shop:electrical', 'shop:appliance', 'shop:computer'],
            ['electronics', 'electronic', 'electrical', 'computer', 'laptop', 'tv', 'appliance', 'इलेक्ट्रोनिक्स', 'इलेक्ट्रॉनिक्स', 'कम्प्युटर', 'कंप्यूटर']],
        'hardware' => ['🔧', ['हार्डवेयर पसल', 'हार्डवेयर', 'hardware shops'], ['shop:hardware', 'shop:paint', 'shop:doityourself', 'shop:trade', 'shop:tiles'],
            ['hardware', 'paint', 'cement', 'tiles', 'plumber', 'plumbing', 'sanitary', 'हार्डवेयर', 'रङ', 'रंग', 'सिमेन्ट']],
        'stationery' => ['📝', ['स्टेशनरी / फोटोकपी', 'स्टेशनरी / फोटोकॉपी', 'stationery & print'], ['shop:stationery', 'shop:copyshop', 'shop:books'],
            ['stationery', 'stationary', 'xerox', 'photocopy', 'print', 'printing', 'printout', 'books', 'book shop', 'kitab', 'स्टेशनरी', 'फोटोकपी', 'फोटोकॉपी', 'किताब']],
        'cyber' => ['💻', ['साइबर', 'साइबर कैफे', 'internet cafés'], ['amenity:internet_cafe'], ['cyber', 'cyber cafe', 'internet cafe', 'internet', 'साइबर']],
        'garage' => ['🛠️', ['ग्यारेज / मिस्त्री', 'गैराज / मैकेनिक', 'garages & mechanics'],
            ['shop:car_repair', 'shop:motorcycle_repair', 'shop:tyres', 'shop:car_parts', 'shop:repair'],
            ['garage', 'mechanic', 'mistri', 'puncture', 'punchar', 'panchar', 'tyre', 'tyres', 'tire', 'service center', 'service centre', 'workshop',
             'bike repair', 'car repair', 'ग्यारेज', 'गैराज', 'मेकानिक', 'मैकेनिक', 'मिस्त्री', 'पंक्चर', 'पन्चर', 'टायर']],
        'vehicles' => ['🏍️', ['गाडी / बाइक शोरुम', 'गाड़ी / बाइक शोरूम', 'vehicle showrooms'], ['shop:car', 'shop:motorcycle', 'shop:bicycle'],
            ['showroom', 'bike shop', 'car showroom', 'cycle', 'bicycle', 'scooter', 'शोरुम', 'शोरूम', 'साइकल', 'साइकिल']],
        'carwash' => ['🚿', ['कार धुने', 'कार वॉश', 'car wash'], ['amenity:car_wash'], ['car wash', 'carwash', 'washing', 'bike wash']],
        'parking' => ['🅿️', ['पार्किङ', 'पार्किंग', 'parking'], ['amenity:parking', 'amenity:motorcycle_parking'], ['parking', 'park garne', 'पार्किङ', 'पार्किंग']],
        'toilet' => ['🚻', ['शौचालय', 'शौचालय', 'toilets'], ['amenity:toilets'],
            ['toilet', 'toilets', 'washroom', 'bathroom', 'restroom', 'charpi', 'peshab', 'शौचालय', 'चर्पी', 'टॉयलेट', 'ट्वाइलेट']],
        'water' => ['🚰', ['खाने पानी', 'पीने का पानी', 'drinking water'], ['amenity:drinking_water', 'amenity:water_point'],
            ['drinking water', 'water', 'khane pani', 'pine ka pani', 'पानी', 'खानेपानी']],
        'busstand' => ['🚌', ['बसपार्क / बस स्टप', 'बस अड्डा / बस स्टॉप', 'bus stands & stops'],
            ['amenity:bus_station', 'highway:bus_stop', 'public_transport:station'],
            ['bus stand', 'bus park', 'buspark', 'busstand', 'bus stop', 'bus station', 'bus adda', 'बसपार्क', 'बस अड्डा', 'बस स्टैंड', 'बस स्टप', 'बस स्टॉप']],
        'railway' => ['🚆', ['रेलवे स्टेशन', 'रेलवे स्टेशन', 'railway stations'], ['railway:station', 'railway:halt'],
            ['railway', 'railway station', 'train', 'train station', 'station', 'रेलवे', 'रेल', 'ट्रेन', 'स्टेशन']],
        'taxi' => ['🚕', ['ट्याक्सी / गाडी भाडा', 'टैक्सी / गाड़ी किराया', 'taxis & rentals'], ['amenity:taxi', 'amenity:car_rental', 'amenity:bicycle_rental'],
            ['taxi', 'cab', 'auto', 'rickshaw', 'car rental', 'rent a car', 'bike rental', 'ट्याक्सी', 'टैक्सी', 'रिक्सा', 'रिक्शा']],
        'travel' => ['🎫', ['ट्राभल एजेन्सी', 'ट्रैवल एजेंसी', 'travel agents'], ['shop:travel_agency', 'shop:ticket'],
            ['travel agency', 'travel agent', 'travels', 'tour', 'ticket counter', 'ट्राभल', 'ट्रैवल']],
        'sights' => ['📸', ['घुम्ने ठाउँ', 'घूमने की जगह', 'places to visit'],
            ['tourism:attraction', 'tourism:viewpoint', 'tourism:museum', 'historic:fort', 'historic:castle', 'historic:monument', 'tourism:zoo',
             'tourism:theme_park', 'leisure:water_park', 'tourism:aquarium', 'tourism:gallery', 'historic:archaeological_site'],
            ['tourist', 'tourist place', 'places to visit', 'sightseeing', 'attraction', 'viewpoint', 'museum', 'fort', 'zoo', 'water park',
             'संग्रहालय', 'किल्ला', 'किला', 'चिडियाखाना', 'चिड़ियाघर']],
        // 23 Oct 2026: "mood off xa, najik ghumne thau" - somewhere to go out: parks, views, lakes, sights together
        'outing' => ['🌄', ['घुम्ने / रमाइलो ठाउँ', 'घूमने / मन बहलाने की जगह', 'places to go out'],
            ['leisure:park', 'leisure:garden', 'tourism:viewpoint', 'tourism:attraction', 'tourism:zoo', 'tourism:theme_park', 'leisure:water_park',
             'leisure:nature_reserve', 'tourism:picnic_site', 'natural:beach', 'waterway:waterfall'],
            ['ghumne', 'ghumna', 'ghumne thau', 'ghumna jane', 'ghoomne', 'ghumne jagah', 'outing', 'picnic', 'hangout', 'hang out', 'chill', 'fresh hune',
             'घुम्ने', 'घुम्न', 'घूमने', 'घुमघाम', 'पिकनिक', 'रमाइलो']],
        'laundry' => ['🧺', ['लुगा धुने', 'लॉन्ड्री / ड्राई क्लीन', 'laundries'], ['shop:laundry', 'shop:dry_cleaning'],
            ['laundry', 'dry clean', 'dry cleaning', 'dhobi', 'iron', 'धोबी', 'लन्ड्री', 'लॉन्ड्री']],
        'florist' => ['💐', ['फूल पसल', 'फूल की दुकान', 'florists'], ['shop:florist', 'shop:garden_centre'], ['florist', 'flower', 'flowers', 'phool', 'nursery', 'फूल']],
        'gifts' => ['🎁', ['उपहार / खेलौना', 'गिफ्ट / खिलौने', 'gifts & toys'], ['shop:gift', 'shop:toys'], ['gift', 'gifts', 'toys', 'toy', 'उपहार', 'गिफ्ट', 'खेलौना', 'खिलौने']],
        'furniture' => ['🛋️', ['फर्निचर', 'फर्नीचर', 'furniture'], ['shop:furniture', 'shop:bed', 'shop:interior_decoration', 'shop:kitchen'],
            ['furniture', 'sofa', 'bed', 'mattress', 'फर्निचर', 'फर्नीचर']],
        'sports' => ['🏟️', ['खेलमैदान / पौडी', 'खेल मैदान / स्विमिंग', 'sports & pools'], ['leisure:stadium', 'leisure:pitch', 'leisure:swimming_pool', 'leisure:sports_hall'],
            ['stadium', 'ground', 'cricket', 'football', 'futsal', 'swimming', 'swimming pool', 'pool', 'खेलमैदान', 'स्टेडियम', 'पौडी', 'फुटसल']],
        'venue' => ['💒', ['पार्टी प्यालेस / हल', 'बैंक्वेट / मैरिज हॉल', 'party & wedding venues'], ['amenity:events_venue', 'amenity:conference_centre', 'amenity:community_centre'],
            ['banquet', 'party palace', 'wedding hall', 'marriage hall', 'marriage garden', 'venue', 'community hall', 'प्यालेस', 'बैंक्वेट', 'मैरिज']],
        'govt' => ['🏛️', ['सरकारी कार्यालय', 'सरकारी दफ्तर', 'government offices'], ['office:government', 'amenity:townhall', 'amenity:courthouse', 'amenity:public_building'],
            ['government', 'government office', 'sarkari', 'municipality', 'nagarpalika', 'ward office', 'court', 'tehsil', 'collector', 'नगरपालिका', 'सरकारी', 'अदालत', 'तहसील', 'वडा']],
        'embassy' => ['🛂', ['दूतावास', 'दूतावास', 'embassies'], ['office:diplomatic'], ['embassy', 'consulate', 'दूतावास']],
        'bloodbank' => ['🩸', ['ब्लड बैंक', 'ब्लड बैंक', 'blood banks'], ['amenity:blood_bank', 'healthcare:blood_bank'], ['blood bank', 'blood', 'रगत', 'खून']],
        'lawyer' => ['⚖️', ['वकिल', 'वकील', 'lawyers'], ['office:lawyer'], ['lawyer', 'advocate', 'vakil', 'wakil', 'वकिल', 'वकील']],
        'drivingschool' => ['🚗', ['ड्राइभिङ स्कुल', 'ड्राइविंग स्कूल', 'driving schools'], ['amenity:driving_school'], ['driving school', 'driving licence', 'driving license']],
    ];

    /**
     * Gujarati words (Gujarati script + Roman), 22 Oct 2026 (owner: the guide
     * must understand Hindi, Gujarati, English and Nepali). Merged into CATS
     * words by parse().
     *
     * @var array<string, list<string>>
     */
    public const GU = [
        'food' => ['જમવાનું', 'જમવા', 'ખાવાનું', 'ખાવા', 'ભોજન', 'રેસ્ટોરન્ટ', 'રેસ્ટોરાં', 'રેસ્ટોરેન્ટ', 'ઢાબા', 'થાળી', 'નાસ્તો', 'jamvanu', 'jamva', 'khavanu', 'khava', 'naasto', 'nasto'],
        'fastfood' => ['પિઝા', 'બર્ગર', 'સમોસા', 'ચાટ'],
        'cafe' => ['ચા', 'કોફી', 'કાફે', 'cha'],
        'bakery' => ['બેકરી', 'મીઠાઈ', 'કેક'],
        'icecream' => ['આઈસ્ક્રીમ', 'આઇસક્રીમ'],
        'salon' => ['સલૂન', 'સલુન', 'વાળંદ', 'પાર્લર'],
        'gym' => ['જીમ', 'વ્યાયામશાળા'],
        'hospital' => ['હોસ્પિટલ', 'દવાખાનું', 'દવાખાનુ', 'ઇસ્પિતાલ', 'davakhanu', 'davakhana'],
        'clinic' => ['ક્લિનિક', 'ડૉક્ટર', 'ડોક્ટર', 'દાક્તર'],
        'dentist' => ['દાંતના', 'ડેન્ટિસ્ટ'],
        'pharmacy' => ['મેડિકલ', 'દવા', 'દવાની', 'ફાર્મસી', 'dava', 'davani'],
        'atm' => ['એટીએમ'],
        'bank' => ['બેંક', 'બેન્ક'],
        'fuel' => ['પેટ્રોલ', 'ડીઝલ', 'પંપ', 'સીએનજી'],
        'hotel' => ['હોટલ', 'હોટેલ', 'લોજ', 'ધર્મશાળા', 'રૂમ', 'ગેસ્ટ', 'dharamshala', 'dharmshala'],
        'temple' => ['મંદિર', 'દેરાસર', 'ગુરુદ્વારા', 'મસ્જિદ', 'derasar'],
        'police' => ['પોલીસ', 'પોલીસ સ્ટેશન', 'થાણું', 'chowki', 'ચોકી'],
        'park' => ['બગીચો', 'ઉદ્યાન', 'પાર્ક', 'bagicho'],
        'mall' => ['મોલ'],
        'grocery' => ['કરિયાણું', 'કરિયાણા', 'કિરાણા', 'kariyana', 'kariyanu'],
        'vegetables' => ['શાકભાજી', 'શાક', 'shakbhaji'],
        'market' => ['બજાર', 'માર્કેટ'],
        'mobile' => ['મોબાઇલ', 'મોબાઈલ'],
        'garage' => ['ગેરેજ', 'પંચર', 'puncture'],
        'parking' => ['પાર્કિંગ'],
        'toilet' => ['શૌચાલય', 'ટોયલેટ', 'બાથરૂમ', 'સંડાસ', 'sandas'],
        'water' => ['પાણી', 'પીવાનું પાણી'],
        'busstand' => ['બસ સ્ટેન્ડ', 'બસ સ્ટેશન', 'બસ ડેપો', 'એસટી', 'st stand', 'st depot', 'bus depot'],
        'railway' => ['રેલવે સ્ટેશન', 'રેલ્વે સ્ટેશન', 'સ્ટેશન'],
        'taxi' => ['રિક્ષા', 'ટેક્સી', 'rickshaw', 'riksha'],
        'school' => ['શાળા', 'નિશાળ', 'સ્કૂલ'],
        'college' => ['કોલેજ'],
        'cinema' => ['સિનેમા', 'થિયેટર'],
        'clothes' => ['કપડાં', 'કપડા'],
        'post' => ['પોસ્ટ ઓફિસ', 'ટપાલ'],
        'outing' => ['ફરવા', 'ફરવાની', 'ફરવાનું', 'ફરવા જવું', 'farva', 'pharva', 'ફરવાલાયક'],
    ];

    /**
     * How the rider FEELS -> the kind of place that helps (23 Oct 2026, owner:
     * "bhok lagyo kata jaam -> restaurant, mood off xa -> ghumne thau, park").
     * mood id => [category, words]. Words match like CATS words (a phrase
     * with a space, or one whole token). A kind of place the rider named
     * ("bhok lagyo, momo kaha paincha") wins over the mood's own kind; the
     * mood still colours the reply. A mood is a "near me" question by itself.
     *
     * @var array<string, array{0:string, 1:list<string>}>
     */
    public const MOODS = [
        'hungry' => ['food', ['bhok', 'bhokh', 'bhoke', 'bhook', 'bhuk', 'bhukh', 'bhookh', 'hungry', 'starving', 'eat', 'khau', 'khaun', 'khaau', 'khaam', 'khaane',
            'pet bhok', 'bhok lagyo', 'bhuk lagi', 'bhookh lagi', 'kuch khana', 'kehi khana', 'bhukh lagi che', 'bhukh lagi chhe',
            'भोक', 'भोकायो', 'भूख', 'भुख', 'खाऔं', 'खाउँ', 'ભૂખ', 'ભૂખ લાગી', 'જમવું']],
        'thirsty' => ['cafe', ['pyas', 'pyaas', 'tirkha', 'tirka', 'thirsty', 'thirst', 'juice', 'cold drink', 'lassi', 'प्यास', 'तिर्खा', 'तिर्खायो', 'जुस', 'લસ્સી', 'તરસ']],
        'tired' => ['hotel', ['tired', 'sleepy', 'sleep', 'thakai', 'thakeko', 'thakyo', 'thak gaya', 'thaka', 'nidra', 'nind', 'neend', 'sutna', 'sutne', 'sutnu',
            'aaram', 'aram', 'rest', 'raat basne', 'rat basne', 'basne thau', 'basna', 'basne', 'night stay', 'overnight',
            'थकान', 'थाकेको', 'थाक्यो', 'थक', 'निद्रा', 'निन्द्रा', 'नींद', 'सुत्ने', 'सुत्न', 'सोना', 'आराम', 'बस्ने', 'ઊંઘ', 'થાક', 'આરામ', 'રાત રોકાવું']],
        'mood' => ['outing', ['mood off', 'mood kharab', 'mood thik chaina', 'mood chaina', 'man kharab', 'man lagena', 'man ramaena', 'bored', 'boring', 'bore', 'sad',
            'upset', 'udas', 'udaas', 'dukhi', 'stress', 'stressed', 'tension', 'tense', 'depressed', 'lonely', 'akela', 'eklai', 'relax', 'relaxing', 'fresh', 'refresh',
            'timepass', 'time pass', 'ramailo garna', 'enjoy', 'masti', 'ghumfir', 'ghum phir',
            'मुड अफ', 'मुड खराब', 'मन खराब', 'उदास', 'दुखी', 'बोर', 'तनाव', 'टेन्सन', 'टेंशन', 'एक्लै', 'अकेला', 'मज़ा', 'मजा',
            'મૂડ', 'કંટાળો', 'ઉદાસ', 'ટેન્શન']],
        'date' => ['outing', ['romantic', 'couple', 'date jane', 'date ma', 'date par', 'date pe', 'girlfriend', 'boyfriend', 'gf', 'bf', 'wife sanga', 'family sanga',
            'family ke sath', 'family outing', 'रोमान्टिक', 'प्रेमी']],
        'kids' => ['park', ['kids', 'kid', 'children', 'child', 'bachha', 'bachcha', 'baccha', 'bachche', 'baccha haru', 'nani', 'chora chori', 'bacchon', 'खेल्ने',
            'बच्चा', 'बच्चे', 'केटाकेटी', 'नानी', 'બાળકો', 'છોકરાં']],
        'peace' => ['temple', ['peace', 'shanti', 'shaanti', 'pray', 'prayer', 'prarthana', 'puja', 'pooja', 'bhagwan', 'bhagawan', 'god', 'dharmik', 'darshan garne',
            'शान्ति', 'शांति', 'पूजा', 'प्रार्थना', 'भगवान', 'धार्मिक', 'ભગવાન', 'દર્શન', 'પૂજા', 'શાંતિ']],
        'sick' => ['clinic', ['sick', 'ill', 'unwell', 'fever', 'bimar', 'biramee', 'birami', 'bimari', 'jwaro', 'jaro', 'bukhar', 'headache', 'tauko dukhyo', 'pet dukhyo',
            'sir dard', 'pet dard', 'vomit', 'ulti', 'injury', 'chot', 'ghau', 'बिरामी', 'बीमार', 'ज्वरो', 'बुखार', 'टाउको दुख्यो', 'पेट दुख्यो', 'सिर दर्द', 'पेट दर्द',
            'उल्टी', 'चोट', 'घाउ', 'બીમાર', 'તાવ', 'માથું દુખે']],
        'cash' => ['atm', ['paisa sakiyo', 'paisa chaiyo', 'paisa chahiyo', 'paisa nikalna', 'paise chahiye', 'paise nikalne', 'cash chahiyo', 'need cash', 'need money',
            'withdraw', 'पैसा सकियो', 'पैसा चाहियो', 'पैसे चाहिए', 'પૈસા જોઈએ']],
    ];

    /** Gujarati labels (others fall back to the Hindi one) */
    private const GU_LABEL = [
        'food' => 'જમવાનું / રેસ્ટોરન્ટ', 'cafe' => 'ચા / કાફે', 'hospital' => 'હોસ્પિટલ', 'clinic' => 'ક્લિનિક / ડૉક્ટર', 'pharmacy' => 'મેડિકલ સ્ટોર',
        'atm' => 'એટીએમ', 'bank' => 'બેંક', 'fuel' => 'પેટ્રોલ પંપ', 'hotel' => 'હોટલ', 'temple' => 'મંદિર', 'police' => 'પોલીસ',
        'park' => 'બગીચા', 'toilet' => 'શૌચાલય', 'busstand' => 'બસ સ્ટેન્ડ', 'railway' => 'રેલવે સ્ટેશન', 'salon' => 'સલૂન', 'grocery' => 'કરિયાણું',
        'outing' => 'ફરવાલાયક જગ્યા',
    ];

    /**
     * Nepal districts people name instead of their headquarters town
     * ("Surkhet" = Birendranagar): word => [town, lat, lng]. 22 Oct 2026,
     * owner: "surkhet bulbule samne ko location navigate gardeu".
     * Coordinates are the towns' own nav_places points.
     *
     * @var array<string, array{0:string, 1:float, 2:float}>
     */
    public const DISTRICTS = [
        'surkhet' => ['Birendranagar', 28.5954, 81.6285], 'सुर्खेत' => ['Birendranagar', 28.5954, 81.6285],
        'banke' => ['Nepalgunj', 28.0570, 81.6170], 'बाँके' => ['Nepalgunj', 28.0570, 81.6170], 'बांके' => ['Nepalgunj', 28.0570, 81.6170],
        'kailali' => ['Dhangadhi', 28.6990, 80.5710], 'कैलाली' => ['Dhangadhi', 28.6990, 80.5710],
        'kanchanpur' => ['Bhimdatta', 28.9700, 80.1800], 'कञ्चनपुर' => ['Bhimdatta', 28.9700, 80.1800], 'कंचनपुर' => ['Bhimdatta', 28.9700, 80.1800],
        'mahendranagar' => ['Bhimdatta', 28.9700, 80.1800], 'महेन्द्रनगर' => ['Bhimdatta', 28.9700, 80.1800],
        'dang' => ['Ghorahi', 28.0400, 82.4900], 'दाङ' => ['Ghorahi', 28.0400, 82.4900], 'दांग' => ['Ghorahi', 28.0400, 82.4900],
        'bardiya' => ['Gulariya', 28.2050, 81.3450], 'bardia' => ['Gulariya', 28.2050, 81.3450], 'बर्दिया' => ['Gulariya', 28.2050, 81.3450],
        'rupandehi' => ['Butwal', 27.7000, 83.4650], 'रुपन्देही' => ['Butwal', 27.7000, 83.4650],
        'bhairahawa' => ['Siddharthanagar', 27.5060, 83.4520], 'भैरहवा' => ['Siddharthanagar', 27.5060, 83.4520],
        'chitwan' => ['Bharatpur', 27.6830, 84.4330], 'चितवन' => ['Bharatpur', 27.6830, 84.4330],
        'narayangarh' => ['Bharatpur', 27.6830, 84.4330], 'नारायणगढ' => ['Bharatpur', 27.6830, 84.4330],
        'kaski' => ['Pokhara', 28.2100, 83.9870], 'कास्की' => ['Pokhara', 28.2100, 83.9870],
        'morang' => ['Biratnagar', 26.4570, 87.2800], 'मोरङ' => ['Biratnagar', 26.4570, 87.2800],
        'sunsari' => ['Itahari', 26.6630, 87.2740], 'सुनसरी' => ['Itahari', 26.6630, 87.2740],
        'parsa' => ['Birgunj', 27.0120, 84.8770], 'पर्सा' => ['Birgunj', 27.0120, 84.8770],
        'dhanusha' => ['Janakpur', 26.7290, 85.9250], 'dhanusa' => ['Janakpur', 26.7290, 85.9250], 'धनुषा' => ['Janakpur', 26.7290, 85.9250],
        'jhapa' => ['Bhadrapur', 26.5500, 88.0900], 'झापा' => ['Bhadrapur', 26.5500, 88.0900],
        'palpa' => ['Tansen', 27.8700, 83.5500], 'पाल्पा' => ['Tansen', 27.8700, 83.5500],
        'doti' => ['Dipayal', 29.2600, 80.9400], 'डोटी' => ['Dipayal', 29.2600, 80.9400],
        'dailekh' => ['Dailekh', 28.8400, 81.7100], 'दैलेख' => ['Dailekh', 28.8400, 81.7100],
        'makwanpur' => ['Hetauda', 27.4280, 85.0320], 'मकवानपुर' => ['Hetauda', 27.4280, 85.0320],
        'dadeldhura' => ['Dadeldhura', 29.3000, 80.5900], 'डडेलधुरा' => ['Dadeldhura', 29.3000, 80.5900],
    ];

    /** The headquarters town of a district word, or null. @return array{name:string, lat:float, lng:float}|null */
    public static function district(string $word): ?array
    {
        $d = self::DISTRICTS[self::norm($word)] ?? null;
        return $d === null ? null : ['name' => $d[0], 'lat' => $d[1], 'lng' => $d[2]];
    }

    /** A Gujarati word without its case ending ("હોસ્પિટલમાં" -> "હોસ્પિટલ"). */
    private static function guStem(string $t): string
    {
        if (preg_match('/^[\x{0A80}-\x{0AFF}]+$/u', $t) !== 1) {
            return $t;
        }
        foreach (['માંથી', 'માં', 'નું', 'ની', 'નો', 'ના', 'ને', 'થી', 'પર', 'એ'] as $s) {
            if (mb_strlen($t) > mb_strlen($s) + 1 && str_ends_with($t, $s)) {
                return mb_substr($t, 0, mb_strlen($t) - mb_strlen($s));
            }
        }
        return $t;
    }

    /** words that only shape the question ("suggest me … near me please") */
    private const FILLER = [
        'near', 'nearby', 'nearest', 'closest', 'close', 'around', 'nerar', 'neer', 'nera', 'my', 'mine', 'i', 'am', 'here', 'suggest', 'show', 'find', 'search', 'where', 'which',
        'is', 'are', 'the', 'a', 'an', 'any', 'some', 'good', 'best', 'top', 'cheap', 'please', 'plz', 'pls', 'want', 'need', 'looking', 'for', 'of', 'to', 'in',
        'at', 'on', 'from', 'by', 'with', 'and', 'or', 'it', 'there', 'this', 'that', 'what', 'list', 'open', 'now', 'today', 'location', 'area', 'place', 'places',
        'shop', 'shops', 'store', 'center', 'centre', 'can', 'you', 'tell', 'give', 'get', 'go', 'how', 'far',
        'paas', 'pass', 'pas', 'najik', 'najikai', 'nazdik', 'najdik', 'ke', 'ka', 'ki', 'ko', 'ma', 'me', 'mere', 'mero', 'hamro', 'yaha', 'yahan', 'yahaan', 'aaspas',
        'kaha', 'kahan', 'kata', 'kun', 'kaun', 'kon', 'chha', 'cha', 'xa', 'hai', 'he', 'hain', 'ho', 'wala', 'vala', 'wali', 'vali', 'ramro', 'achha', 'accha', 'sasto',
        'batau', 'batao', 'bataunu', 'dekhau', 'dekhao', 'dikhao', 'khoj', 'khojnu', 'chahiyo', 'chahiye', 'chaiyo', 'chaie', 'pasal', 'dukan', 'dokan', 'koi', 'kei', 'kehi',
        'नजिक', 'नजिकको', 'नजिकै', 'नज़दीक', 'नजदीक', 'नज़दीकी', 'पास', 'आसपास', 'यहाँ', 'यहां', 'मेरो', 'मेरे', 'मेरा', 'हाम्रो', 'को', 'का', 'की', 'के', 'मा', 'में',
        'कहाँ', 'कहां', 'कता', 'कुन', 'कौन', 'छ', 'हो', 'है', 'हैं', 'वाला', 'वाली', 'राम्रो', 'अच्छा', 'सस्तो', 'बताउ', 'बताऊ', 'बताओ', 'बताइदेउ', 'देखाउ', 'दिखाओ',
        'खोज', 'चाहियो', 'चाहिए', 'पसल', 'दुकान', 'कुनै', 'कोई', 'एक', 'नि', 'न', 'त', 'पनि', 'भी',
        'મને', 'મારે', 'મારી', 'મારું', 'અમને', 'નજીક', 'નજીકનું', 'નજીકની', 'નજીકમાં', 'પાસે', 'આસપાસ', 'અહીં', 'ક્યાં', 'કયું', 'કઈ', 'છે', 'બતાવો', 'બતાવ',
        'શોધો', 'સારું', 'સારી', 'સારો', 'કોઈ', 'એક', 'જોઈએ', 'જોઇએ', 'દુકાન', 'પણ', 'તો',
        'malai', 'malaai', 'hamlai', 'mujhe', 'muje', 'humein', 'gara', 'garnu', 'garnus', 'gardeu', 'gardinu', 'gardinus', 'garideu', 'deu', 'dinu', 'dinus',
        'lai', 'jau', 'jaau', 'jao', 'chalo', 'lijau', 'lejau', 'laijau', 'navigate', 'take', 'drive', 'pugau', 'pugaideu', 'लैजाउ', 'लगिदेउ', 'जाउ', 'લઈ', 'લઇ', 'જાઓ', 'જાવ',
        'karo', 'kijiye', 'do', 'dijiye', 'sujhao', 'suggestion', 'suggestions', 'recommend', 'recommendation',
        'मलाई', 'हामीलाई', 'मुझे', 'हमें', 'गर', 'गर्नु', 'गर्नुस्', 'गरिदेउ', 'गर्दिनुस्', 'देउ', 'दिनुस्', 'करो', 'कीजिए', 'दो', 'दीजिए', 'सुझाव',
        'mane', 'mare', 'mari', 'amne', 'paase', 'aaspaas', 'kyan', 'kya', 'che', 'chhe', 'batavo', 'batav', 'shodho', 'saru', 'sari', 'saro', 'joie', 'joiye', 'dukan',
        // 23 Oct 2026: the rest of how people really talk ("mlai hotel suggest garnus", "bhok lagyo kata jaam")
        'mlai', 'mlae', 'malae', 'haru', 'harru', 'lagyo', 'lageko', 'lagi', 'lagi', 'lagra', 'jaam', 'jaun', 'jaaun', 'jaun', 'jane', 'jaane', 'thau', 'thaun', 'thaau',
        'jagah', 'jagha', 'jaga', 'garna', 'garne', 'man', 'ekdam', 'dherai', 'kunai', 'ani', 'sanga', 'hola', 'hunxa', 'huncha', 'hunchha', 'paincha', 'painxa', 'milxa',
        'milcha', 'xan', 'chan', 'chhan', 'bhayo', 'vayo', 'bhaira', 'vaira', 'sabai', 'sab', 'kuch', 'kuchh', 'thoda', 'ali', 'feri', 'aaja', 'aja', 'aile', 'ahile',
        'हरु', 'हरू', 'लाग्यो', 'लागेको', 'ठाउँ', 'ठाउं', 'जगह', 'जाम', 'जाऔं', 'जाने', 'गर्न', 'गर्ने', 'मन', 'छन्', 'छन', 'पाइन्छ', 'मिल्छ', 'भयो', 'सबै', 'कुछ', 'अहिले', 'आज',
    ];

    /** words that say "around me" when no kind of place was named ("dominos near me") */
    private const NEAR_WORDS = ['near', 'nearby', 'nearest', 'closest', 'around', 'nerar', 'neer', 'nera', 'paas', 'najik', 'najikai', 'nazdik', 'najdik', 'aaspas',
        'नजिक', 'नजिकको', 'नजिकै', 'नज़दीक', 'नजदीक', 'नज़दीकी', 'पास', 'आसपास',
        'નજીક', 'નજીકનું', 'નજીકની', 'નજીકમાં', 'પાસે', 'આસપાસ', 'paase', 'aaspaas'];

    /** Lower-case, nukta dropped, chandrabindu = anusvara, punctuation = space. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = str_replace(["\u{093C}", "\u{0901}", '’', "'"], ['', "\u{0902}", '', ''], $s);
        $s = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /**
     * Which kind of place does this question ask for?
     *
     * @return array{cat: ?string, rest: list<string>, near: bool, mood: ?string}
     *         rest = the words left once the kind and the filler are gone
     *         (a town name, or a brand like "dominos"); mood = a MOODS id
     */
    public static function parse(string $text): array
    {
        $norm = self::norm($text);
        $toks = $norm === '' ? [] : explode(' ', $norm);
        // the feeling first, by whole words only: "tired" must never reach the
        // CATS prefix rule (it starts with "tire" = garage)
        $mood = null;
        $moodLen = 0;
        $moodUsed = [];
        foreach (self::MOODS as $id => [, $words]) {
            foreach ($words as $w) {
                $w = self::norm($w);
                $hit = [];
                if (str_contains($w, ' ')) {
                    if (str_contains(' ' . $norm . ' ', ' ' . $w . ' ')) {
                        $hit = explode(' ', $w);
                    }
                } elseif ($w !== '' && in_array($w, $toks, true)) {
                    $hit = [$w];
                }
                if ($hit !== [] && mb_strlen($w) > $moodLen) {
                    $mood = $id;
                    $moodLen = mb_strlen($w);
                    $moodUsed = $hit;
                }
            }
        }
        $best = null;
        $bestLen = 0;
        $used = [];
        foreach (self::CATS as $id => $c) {
            foreach (array_merge($c[3], self::GU[$id] ?? []) as $w) {
                $w = self::norm($w);
                if ($w === '') {
                    continue;
                }
                $hit = [];
                if (str_contains($w, ' ')) {
                    if (str_contains(' ' . $norm . ' ', ' ' . $w . ' ')) {
                        $hit = explode(' ', $w);
                    }
                } else {
                    foreach ($toks as $t) {
                        if (in_array($t, $moodUsed, true) && $t !== $w) {
                            continue;                   // a mood word is not the start of a kind ("tired" / "tire")
                        }
                        if ($t === $w || self::guStem($t) === $w || (mb_strlen($w) >= 4 && preg_match('/^[a-z]+$/', $w) === 1 && str_starts_with($t, $w) && strlen($t) - strlen($w) <= 2)) {
                            $hit = [$t];
                            break;
                        }
                    }
                }
                if ($hit !== [] && mb_strlen($w) > $bestLen) {
                    $best = $id;
                    $bestLen = mb_strlen($w);
                    $used = $hit;
                }
            }
        }
        $near = false;
        foreach ($toks as $t) {
            if (in_array($t, self::NEAR_WORDS, true)) {
                $near = true;
                break;
            }
        }
        $near = $near || str_contains(' ' . $norm . ' ', ' near me ');
        if ($mood !== null) {
            $best ??= self::MOODS[$mood][0];            // a kind the rider named wins; the mood still shapes the reply
            $near = true;                               // "bhok lagyo" means here and now
        }
        $rest = [];
        foreach ($toks as $t) {
            $i = array_search($t, $used, true);
            if ($i !== false) {
                unset($used[$i]);                       // each matched word once ("bus bus stand")
                continue;
            }
            if ($mood !== null && self::moodWord($t)) {
                continue;                               // "tired … sleep": every feeling word goes, not just the winner (a village may be called "Date")
            }
            if (in_array($t, self::FILLER, true) || mb_strlen($t) < 2) {
                continue;
            }
            $rest[] = $t;
        }
        return ['cat' => $best, 'rest' => $rest, 'near' => $near, 'mood' => $mood];
    }

    /** Is this one token a word of any mood (or of a mood phrase)? */
    private static function moodWord(string $t): bool
    {
        static $set = null;
        if ($set === null) {
            $set = [];
            foreach (self::MOODS as [, $words]) {
                foreach ($words as $w) {
                    foreach (explode(' ', self::norm($w)) as $x) {
                        $set[$x] = true;
                    }
                }
            }
            foreach (['date', 'aa', 'rahi', 'raha', 'rahe', 'rahyo', 'liye', 'liya', 'off', 'xu', 'chu', 'chhu', 'hu', 'hun', 'hoon', 'gaya', 'gayi', 'lagi', 'ho', 'gayo'] as $x) {
                $set[$x] = true;                        // the rest of a feeling sentence ("neend aa rahi hai", "mood off xu")
            }
        }
        return isset($set[$t]);
    }

    /** The label of a category in ne / hi / en. */
    public static function label(string $id, string $lang = 'en'): string
    {
        $l = self::CATS[$id][1] ?? ['', '', $id];
        if ($lang === 'gu') {
            return self::GU_LABEL[$id] ?? $l[1];
        }
        return $lang === 'ne' ? $l[0] : ($lang === 'hi' ? $l[1] : $l[2]);
    }

    /** Ground distance in metres (haversine). */
    public static function metres(float $a, float $b, float $c, float $d): float
    {
        $r = M_PI / 180;
        $h = sin(($c - $a) * $r / 2) ** 2 + cos($a * $r) * cos($c * $r) * sin(($d - $b) * $r / 2) ** 2;
        return 2 * 6371000 * asin(min(1, sqrt($h)));
    }

    /**
     * A town / city / village of exactly this name (the rest of "salon in
     * pokhara"), or null.
     *
     * @param list<string> $words
     * @return array{name:string, lat:float, lng:float}|null
     */
    public static function town(array $words): ?array
    {
        for ($k = min(3, count($words)); $k >= 1; $k--) {
            foreach ([array_slice($words, -$k), array_slice($words, 0, $k)] as $tw) {
                $t = implode(' ', $tw);
                if (mb_strlen($t) < 3) {
                    continue;
                }
                if ($k === 1 && ($d = self::district($t)) !== null) {
                    return ['name' => $d['name'], 'lat' => $d['lat'], 'lng' => $d['lng'], 'words' => $tw];
                }
                $row = Database::fetch(
                    "SELECT name, name_en, lat, lng, kind FROM (
                        (SELECT name, name_en, lat + 0 AS lat, lng + 0 AS lng, kind FROM nav_places WHERE name = :a AND kind IN ('place:city','place:town','place:suburb','place:village') LIMIT 20)
                        UNION ALL
                        (SELECT name, name_en, lat + 0 AS lat, lng + 0 AS lng, kind FROM nav_places WHERE name_en = :b AND kind IN ('place:city','place:town','place:suburb','place:village') LIMIT 20)
                     ) t ORDER BY FIELD(kind, 'place:city', 'place:town', 'place:suburb', 'place:village') LIMIT 1",
                    ['a' => $t, 'b' => $t]
                );
                if ($row !== null) {
                    return ['name' => (string) ($row['name_en'] ?: $row['name']), 'lat' => (float) $row['lat'], 'lng' => (float) $row['lng'], 'words' => $tw];
                }
            }
        }
        return null;
    }

    /**
     * Places of one category (or, with no category, whose NAME holds the
     * words: "dominos near me") around a point, nearest first. The box grows
     * until ENOUGH places are found.
     *
     * @param list<string> $words name words (used only when $cat is null)
     * @return array{rows: list<array<string,mixed>>, km: float}
     */
    public static function search(?string $cat, array $words, float $lat, float $lng, int $limit = 12): array
    {
        $kinds = $cat !== null ? (self::CATS[$cat][2] ?? []) : [];
        $words = array_values(array_filter($words, static fn ($w) => mb_strlen($w) >= 2));
        if ($kinds === [] && $words === []) {
            return ['rows' => [], 'km' => 0.0];
        }
        $found = [];
        $half = self::RADII[0];
        foreach (self::RADII as $half) {
            $p = ['a1' => $lat - $half, 'a2' => $lat + $half, 'b1' => $lng - $half / max(0.2, cos(deg2rad($lat))), 'b2' => $lng + $half / max(0.2, cos(deg2rad($lat)))];
            if ($kinds !== []) {
                $in = [];
                foreach ($kinds as $i => $k) {
                    $in[] = ':k' . $i;
                    $p['k' . $i] = $k;
                }
                $sql = 'SELECT name, name_en, kind, lat + 0 AS lat, lng + 0 AS lng FROM nav_places
                         WHERE kind IN (' . implode(',', $in) . ') AND lat BETWEEN :a1 AND :a2 AND lng BETWEEN :b1 AND :b2 LIMIT 800';
            } else {
                $cond = [];
                foreach (array_slice($words, 0, 3) as $i => $w) {
                    if (strlen($w) > 4 && str_ends_with($w, 's') && preg_match('/^[a-z]+$/', $w) === 1) {
                        $w = substr($w, 0, -1);           // "dominos" finds "Domino's", "mcdonalds" "McDonald's"
                    }
                    $lk = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $w) . '%';
                    $cond[] = "(name LIKE :n$i OR name_en LIKE :e$i)";
                    $p["n$i"] = $lk;
                    $p["e$i"] = $lk;
                }
                $sql = "SELECT name, name_en, kind, lat + 0 AS lat, lng + 0 AS lng FROM nav_places
                         WHERE lat BETWEEN :a1 AND :a2 AND lng BETWEEN :b1 AND :b2 AND kind NOT LIKE 'road:%' AND " . implode(' AND ', $cond) . ' LIMIT 400';
            }
            $found = Database::fetchAll($sql, $p);
            if (count($found) >= self::ENOUGH || ($kinds === [] && $half >= self::RADII[2])) {
                break;
            }
        }
        $rows = [];
        $seen = [];                                       // name => [[lat, lng], ...] kept so far
        foreach ($found as $r) {
            $name = trim((string) ($r['name_en'] ?: $r['name']));
            if ($name === '') {
                continue;
            }
            $m = self::metres($lat, $lng, (float) $r['lat'], (float) $r['lng']);
            $key = mb_strtolower($name);
            $dup = false;                                 // one place mapped twice (a point and a building) = one answer
            foreach ($seen[$key] ?? [] as [$sl, $sg]) {
                if (self::metres($sl, $sg, (float) $r['lat'], (float) $r['lng']) < 300) {
                    $dup = true;
                    break;
                }
            }
            if ($dup) {
                continue;
            }
            $seen[$key][] = [(float) $r['lat'], (float) $r['lng']];
            $kind = (string) $r['kind'];
            $rows[] = [
                'name'  => $name,
                'local' => preg_match('/[\x{0900}-\x{097F}\x{0A80}-\x{0AFF}]/u', (string) $r['name']) === 1 && $r['name'] !== $name ? (string) $r['name'] : '',
                'kind'  => $kind,
                'what'  => ucfirst(str_replace('_', ' ', explode(':', $kind)[1] ?? $kind)),
                'lat'   => round((float) $r['lat'], 6),
                'lng'   => round((float) $r['lng'], 6),
                'm'     => (int) round($m),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['m'] <=> $b['m']);
        return ['rows' => array_slice($rows, 0, $limit), 'km' => round($half * 111, 1)];
    }
}
