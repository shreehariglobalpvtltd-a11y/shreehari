<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit; }

final class AiLanguage
{
    public const INTENTS = ['company','contact','routes','schedule','fare','seats','booking_draft','my_bookings','ticket',
        'payment_status','refund','cancel_request','change_request','resend','wallet','sales','summary','failures',
        'visiting_card','company_profile','handoff','knowledge','deny'];

    public static function detect(string $text): string
    {
        if (preg_match('/(?:reply|answer|respond|speak) (?:in )?english/i',$text)) { return 'en'; }
        if (preg_match('/(?:reply|answer|respond) (?:in )?hindi/i',$text)) { return 'hi'; }
        if (preg_match('/(?:reply|answer|respond) (?:in )?nepali/i',$text)) { return 'ne'; }
        require_once INCLUDE_PATH . '/ticketbot.php';
        $lang=TicketBot::detectLang($text);
        if ($lang==='en') {
            if (preg_match('/\b(mero|hamro|pathau|pathaideu|deu|garna|cha|chha|xa)\b/i',$text)) { $lang='ne'; }
            elseif (preg_match('/\b(mera|meri|bhejo|banao|ka|ki)\b/i',$text)) { $lang='hi'; }
        }
        if (!in_array($lang,['en','hi','ne'],true)) { $lang='en'; }
        return $lang!=='en' && !preg_match('/\p{Devanagari}/u',$text) ? $lang.'-Latn' : $lang;
    }

    public static function normalize(string $text): string
    {
        $text=mb_strtolower(trim($text));
        $text=strtr($text,['tikat'=>'ticket','tikcet'=>'ticket','tiket'=>'ticket','buking'=>'booking','bookng'=>'booking',
            'whatapp'=>'whatsapp','whatsap '=>'whatsapp ','pement'=>'payment','paymant'=>'payment','rupaidha'=>'rupaidiha',
            'nepalganj'=>'nepalgunj','विजिटिंग'=>'visiting','टिकिट'=>'टिकट']);
        return preg_replace('/\s+/u',' ',$text)??$text;
    }

    public static function intent(string $text): string
    {
        $q=self::normalize($text);
        $patterns=[
            'deny'=>'ignore (?:all |previous |the )?instructions|system prompt|api.?key|access token|run sql|drop table|act as admin|another (?:customer|passenger)|credit my wallet|mark .*payment.*verified|cancel without',
            'handoff'=>'human|support|complaint|emergency|staff help|मान्छे|कर्मचारी|सहयोग|सहायता|मदद|madad|sahayog',
            'visiting_card'=>'visiting.?card|business.?card|contact.?card|company.{0,12}card|vcard|v?cf\b|विज़िटिंग|भिजिटिङ|कार्ड',
            'company_profile'=>'company profile|कम्पनी परिचय|कंपनी प्रोफाइल',
            'wallet'=>'wallet|commission|कमिशन|कमीशन|वालेट|वॉलेट',
            'sales'=>'sales|agent.*booking|बिक्री|कमाइ|कमाई',
            'failures'=>'failed.*(?:whatsapp|message|notification)|(?:whatsapp|message).*fail',
            'summary'=>'summary|revenue|occupancy|passenger count|daily report|सारांश|राजस्व',
            'change_request'=>'change.*(?:seat|date|ticket)|(?:seat|date).*change|सीट.*बदल|सिट.*सार|मिति.*सार',
            'refund'=>'refund|फिर्ता|रिफंड',
            'cancel_request'=>'cancel|रद्द|क्यान्सिल',
            'payment_status'=>'payment status|paid|payment|भुक्तानी|भुगतान|paisa|paisa tir',
            'resend'=>'resend|send.*ticket|ticket.*(?:send|pathau|pathaideu|bhejo|deu)|टिकट.*(?:भेज|पठा|देऊ)',
            'my_bookings'=>'my booking|my ticket|mero booking|मेरो बुकिङ|मेरी बुकिंग|मेरे टिकट',
            'ticket'=>'\bshg[- ]|check.*ticket|find.*ticket|ticket status|टिकट.*(?:स्थिति|जाँच)',
            'booking_draft'=>'book (?:a |my )?ticket|booking draft|ticket book|seat book|बुक|टिकट.*(?:चाह|बना)|ticket.*(?:chahiyo|chahiye)|\d+ (?:passengers|jana|जना)',
            'fare'=>'fare|price|cost|किराया|किराय|भाडा|भाड़ा|भाड|कति पैसा|kati paisa|bhada|kiraya',
            'seats'=>'seat|berth|availability|सिट|सीट|खाली',
            'schedule'=>'time|schedule|departure|bus.*(?:today|tomorrow)|today.*bus|bus.*(?:kati|baje)|समय|बजे|कति बेला|आज.*बस|भोलि.*बस',
            'routes'=>'route|boarding|drop point|pickup|कहाँ|कहां|रुट|रूट|चढ्ने|चढ़ने',
            'contact'=>'contact|phone|email|address|office|सम्पर्क|संपर्क|फोन|ठेगाना|पता',
            'company'=>'company|s hari|shree hari|कम्पनी|कंपनी|who are you|hello|namaste|नमस्ते'
        ];
        foreach($patterns as $intent=>$pattern) { if(preg_match('~'.$pattern.'~iu',$q)) { return $intent; } }
        return 'knowledge';
    }

    public static function say(string $key,string $lang): string
    {
        $messages=[
            'hello'=>['I am S Hari AI Helper. Ask about routes, live fares, your tickets or our company.', 'म S Hari AI Helper हुँ। रुट, भाडा, आफ्नो टिकट वा कम्पनीबारे सोध्नुहोस्।','मैं S Hari AI Helper हूँ। रूट, किराया, अपने टिकट या कंपनी के बारे में पूछें।','Ma S Hari AI Helper hu. Route, bhada, afno ticket wa company bare sodhnuhos.','Main S Hari AI Helper hoon. Route, kiraya, apne ticket ya company ke bare mein poochhen.'],
            'unknown'=>['I could not verify an answer. I have saved this question for staff review.','यो उत्तर पुष्टि गर्न सकिनँ। कर्मचारीको समीक्षाका लागि प्रश्न राखेको छु।','इस उत्तर की पुष्टि नहीं हुई। सवाल कर्मचारी की समीक्षा के लिए रखा है।','Yo uttar pramanit bhayena. Karmachariko samikshaka lagi prashna rakheko chhu.','Is jawab ki pushti nahi hui. Sawal staff ki samiksha ke liye rakha hai.'],
            'denied'=>['Please sign in with the account that owns this information. I cannot disclose other customers’ or agents’ data.','यो जानकारी भएको खाताबाट लगइन गर्नुहोस्। अरू ग्राहक वा एजेन्टको जानकारी दिन मिल्दैन।','इस जानकारी वाले खाते से लॉगिन करें। दूसरे ग्राहक या एजेंट की जानकारी नहीं दे सकता।','Yo jankari bhayeko khatabata login garnuhos. Aruko jankari dina mildaina.','Is jankari wale account se login karen. Doosron ki jankari nahi de sakta.'],
            'handoff'=>['Your request is in the support inbox. Staff will review it. For urgent help, call: ','अनुरोध सहयोग टोलीलाई पठाइएको छ। जरुरी सहयोगका लागि फोन गर्नुहोस्: ','अनुरोध सहायता टीम को भेजा है। तुरंत मदद के लिए फोन करें: ','Anurodh support tolilai pathaiyo. Jaruri sahayogko lagi phone: ','Anurodh support team ko bheja hai. Turant madad ke liye phone: '],
            'paused'=>['Staff is handling this conversation. Automated replies are paused.','यो कुराकानी कर्मचारीले हेर्दै हुनुहुन्छ। स्वचालित जवाफ रोकिएको छ।','कर्मचारी यह बातचीत संभाल रहे हैं। स्वचालित उत्तर रुके हैं।','Yo kura karmacharile herdaichhan. AI jawaf rokeko chha.','Staff yeh baatcheet sambhal raha hai. AI jawab ruke hain.'],
            'offline'=>['The assistant is temporarily unavailable. You can still book normally or call support.','सहायक अहिले उपलब्ध छैन। सामान्य बुकिङ वा सहयोग फोन प्रयोग गर्नुहोस्।','सहायक अभी उपलब्ध नहीं है। सामान्य बुकिंग या सहायता फोन इस्तेमाल करें।','Sahayak ahile upalabdha chhaina. Samanya booking wa support phone garnuhos.','Sahayak abhi uplabdh nahi hai. Samanya booking ya support phone karen.'],
            'pnr'=>['Send your booking reference after signing in.','लगइन गरेर आफ्नो बुकिङ नम्बर पठाउनुहोस्।','लॉगिन करके अपना बुकिंग नंबर भेजें।','Login garera afno booking number pathaunuhos.','Login karke apna booking number bhejen.'],
            'trip'=>['Please provide your boarding place, travel date and number of passengers.','चढ्ने ठाउँ, यात्रा मिति र यात्रु संख्या बताउनुहोस्।','चढ़ने की जगह, यात्रा की तारीख और यात्रियों की संख्या बताएं।','Chadhne thau, yatra miti ra yatru sankhya bhannuhos.','Chadhne ki jagah, yatra ki tarikh aur yatri sankhya batayen.'],
            'quote'=>['Live quote only. No seat is held or booked. Complete the secure booking screen to confirm.','यो हालको दर मात्र हो। सिट रोकिएको वा बुक भएको छैन। सुरक्षित बुकिङ पृष्ठमा पुष्टि गर्नुहोस्।','यह वर्तमान दर है। सीट रोकी या बुक नहीं हुई है। सुरक्षित बुकिंग पृष्ठ पर पुष्टि करें।','Yo ahileko dar ho. Seat hold wa book bhayeko chhaina. Booking page ma pushti garnuhos.','Yeh vartaman dar hai. Seat hold ya book nahi hui. Booking page par pushti karen.'],
            'action'=>['Review this request on the secure confirmation page. Nothing has been changed.','सुरक्षित पुष्टि पृष्ठमा अनुरोध जाँच्नुहोस्। केही परिवर्तन भएको छैन।','सुरक्षित पुष्टि पृष्ठ पर अनुरोध जांचें। कोई बदलाव नहीं हुआ है।','Surakshit confirmation page ma anurodh hernuhos. Kehi badaliyeko chhaina.','Surakshit confirmation page par anurodh dekhen. Koi badlav nahi hua.'],
            'card'=>['Your company contact card is ready.','कम्पनीको सम्पर्क कार्ड तयार छ।','कंपनी का संपर्क कार्ड तैयार है।','Company ko samparka card tayar chha.','Company ka sampark card taiyar hai.'],
            'blocked'=>['Automated messages are stopped. Send START to resume.','स्वचालित सन्देश रोकियो। सुरु गर्न START पठाउनुहोस्।','स्वचालित संदेश बंद हैं। शुरू करने के लिए START भेजें।','Swachalit sandesh rokeko chha. Suru garna START pathaunuhos.','Swachalit sandesh band hain. Shuru karne ke liye START bhejen.']
        ];
        $index=['en'=>0,'ne'=>1,'hi'=>2,'ne-Latn'=>3,'hi-Latn'=>4][$lang]??0;
        return ($messages[$key]??$messages['unknown'])[$index];
    }
}
