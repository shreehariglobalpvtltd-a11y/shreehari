<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit; }

require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/devshape.php';
require_once __DIR__ . '/helpers.php';

/** Standalone document generation: no database reads, writes or booking side effects. */
final class AgreementPdf
{
    private const NAVY = [18, 38, 78];
    private const RED = [153, 35, 43];
    private const GOLD = [169, 120, 26];
    private const INK = [35, 44, 60];
    private Pdf $pdf;
    private float $y = 132;
    private int $page = 0;
    private array $data;

    public static function validate(array $input): array
    {
        $text = static function ($value, int $max, string $label): string {
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')
                || preg_match('/[\x00-\x1F\x7F<>\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value)) {
                throw new InvalidArgumentException($label . ': अमान्य पाठ।');
            }
            $value = trim(preg_replace('/\s+/u', ' ', $value));
            if ($value === '' || mb_strlen($value) > $max) { throw new InvalidArgumentException($label . ': आवश्यक है या बहुत लंबा है।'); }
            return $value;
        };
        $money = static function ($value, string $label): string {
            if (!is_string($value) || !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $value) || (float)$value <= 0) {
                throw new InvalidArgumentException($label . ': सही राशि भरें।');
            }
            return number_format((float)$value, 2, '.', '');
        };
        $d = [];
        foreach (['agent_name' => [90, 'एजेंट का नाम'], 'agreement_no' => [40, 'समझौता संख्या'],
            'address' => [180, 'पता'], 'payment_cycle' => [90, 'भुगतान चक्र']] as $key => [$max, $label]) {
            $d[$key] = $text($input[$key] ?? '', $max, $label);
        }
        $date = is_string($input['date'] ?? null) ? $input['date'] : '';
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) { throw new InvalidArgumentException('सही तारीख चुनें।'); }
        $d['date'] = $date;
        $d['commission_type'] = $input['commission_type'] ?? '';
        if (!in_array($d['commission_type'], ['fixed', 'percent'], true)) { throw new InvalidArgumentException('कमीशन का प्रकार चुनें।'); }
        $d['commission_rate'] = $money($input['commission_rate'] ?? '', 'कमीशन');
        if ($d['commission_type'] === 'percent' && (float)$d['commission_rate'] > 100) { throw new InvalidArgumentException('कमीशन 100% से अधिक नहीं हो सकता।'); }
        $d['currency'] = $input['currency'] ?? '';
        if (!in_array($d['currency'], ['INR', 'NPR'], true)) { throw new InvalidArgumentException('मुद्रा चुनें।'); }
        $fares = $input['fares'] ?? null;
        if (!is_array($fares) || count($fares) < 1 || count($fares) > 6) { throw new InvalidArgumentException('किराया तालिका में 1 से 6 पंक्तियाँ भरें।'); }
        $d['fares'] = [];
        foreach ($fares as $fare) {
            if (!is_array($fare)) { throw new InvalidArgumentException('अमान्य किराया पंक्ति।'); }
            $d['fares'][] = ['route' => $text($fare['route'] ?? '', 90, 'मार्ग / सीट'), 'amount' => $money($fare['amount'] ?? '', 'किराया')];
        }
        return $d;
    }

    public static function generate(array $input): string
    {
        $self = new self();
        $self->data = self::validate($input);
        $self->pdf = new Pdf();
        $self->pdf->registerTTF(DevShape::fontFile());
        $self->page();
        $self->heading('01  पक्ष एवं निश्चित किराया');
        $self->paragraph('कंपनी: श्री हरि ग्लोबल प्रा. लि. (SHREE HARI GLOBAL PVT. LTD.)');
        $self->paragraph('एजेंट / सहयोगी: ' . $self->data['agent_name']);
        $self->paragraph('पता: ' . $self->data['address']);
        $self->paragraph('दोनों पक्ष टिकट बुकिंग और यात्रियों की सेवा के लिए नीचे दिए नियम स्वीकार करते हैं।');
        $self->heading('02  स्वीकृत किराया तालिका');
        $self->paragraph('प्रति यात्री / सीट निश्चित किराया। सभी राशियाँ ' . $self->data['currency'] . ' में हैं।');
        $self->space(26 + 31);
        $self->pdf->rect(40, $self->y, 515, 24, self::GOLD);
        $self->draw(49, $self->y + 5, 'मार्ग / सीट', 10.5, [255, 255, 255]);
        $self->draw(410, $self->y + 5, 'निश्चित किराया', 10.5, [255, 255, 255]);
        $self->y += 26;
        foreach ($self->data['fares'] as $fare) {
            $lines = $self->wrap($fare['route'], 340, 10.5);
            $height = count($lines) * 17 + 14;
            $self->space($height);
            $self->pdf->rect(40, $self->y, 515, $height, [247, 244, 235]);
            foreach ($lines as $i => $line) { $self->draw(49, $self->y + 7 + $i * 17, $line, 10.5); }
            $self->draw(410, $self->y + 7, $self->data['currency'] . ' ' . $fare['amount'], 10.5);
            $self->y += $height + 3;
        }
        $self->y += 8;
        $self->paragraph('एजेंट अपनी ओर से किराया, छूट या शुल्क नहीं बदलेगा। बदलाव केवल कंपनी की लिखित स्वीकृति से होगा।');
        $self->heading('03  एजेंट कमीशन');
        $rate = $self->data['commission_type'] === 'percent'
            ? rtrim(rtrim($self->data['commission_rate'], '0'), '.') : $self->data['commission_rate'];
        $self->paragraph($self->data['commission_type'] === 'fixed'
            ? 'कमीशन: प्रत्येक पात्र टिकट पर ' . $self->data['currency'] . ' ' . $rate . '।'
            : 'कमीशन: प्रत्येक पात्र टिकट के स्वीकृत मूल किराये का ' . $rate . '%। अतिरिक्त शुल्क पर कमीशन नहीं मिलेगा।');
        $self->paragraph('भुगतान चक्र: ' . $self->data['payment_cycle']);
        $self->paragraph('केवल एजेंट की अपनी ID से कंपनी के सर्वर में दर्ज, पुष्ट और पात्र टिकट पर कमीशन मिलेगा। रद्द या रिफंड हुए टिकट का कमीशन वापस घटेगा।');
        $self->paragraph('पूरा किराया कंपनी को जमा होने और हिसाब मिलान के बाद कमीशन देय होगा। ग्राहक से मिली राशि में से एजेंट स्वयं कमीशन नहीं काटेगा।');
        if ($self->y > 300) { $self->page(); }
        $self->heading('04  संचालन और जिम्मेदारी');
        $self->paragraph('एजेंट अपनी ID और पासवर्ड सुरक्षित रखेगा तथा यात्री का नाम, मोबाइल, मार्ग और यात्रा की तारीख सही दर्ज करेगा। ID के बाहर की बुकिंग पर कमीशन देय नहीं होगा।');
        $self->paragraph('कंपनी के नाम, लोगो और प्रचार सामग्री का उपयोग केवल अधिकृत बुकिंग के लिए होगा। सीट, समय और सुविधाओं के बारे में गलत वादा नहीं किया जाएगा।');
        $self->paragraph('यात्री की निजी जानकारी केवल बुकिंग और यात्रा सेवा के लिए इस्तेमाल होगी। इसे किसी अन्य व्यक्ति को बेचना या अनधिकृत रूप से साझा करना निषिद्ध है।');
        $self->heading('05  नियम उल्लंघन, नुकसान और 10% अतिरिक्त कटौती', true);
        // 2 Oct 2026 (owner): a percentage, as in his agreement (22222.docx 10.1-10.3), never a multiple.
        $self->paragraph('नियम तोड़ने, धोखाधड़ी, ग्राहक की राशि के गबन या अनधिकृत वसूली से कंपनी को हुआ, रिकॉर्ड से प्रमाणित वास्तविक नुकसान एजेंट को मिलने वाली राशि से काटा जा सकता है। कटौती से पहले कंपनी लिखित सूचना देगी और कारण तथा हिसाब रिकॉर्ड में रखेगी। नुकसान की राशि का 10% अतिरिक्त केवल तभी लागू होगा जब कंपनी कानूनी सलाह लेने के बाद यहाँ "लागू" पर निशान लगाए:   [   ] लागू     [   ] लागू नहीं', self::RED);
        $self->paragraph('कंपनी उल्लंघन पर एजेंट ID निलंबित कर सकती है और समझौता समाप्त कर सकती है। बकाया राशि और कमीशन का अंतिम हिसाब कंपनी के सर्वर रिकॉर्ड से मिलाया जाएगा।');
        $self->heading('06  समाप्ति और हिसाब');
        $self->paragraph('एजेंट समझौता छोड़ने के लिए 30 दिन पहले लिखित सूचना देगा और सभी बकाया हिसाब चुकाएगा। समाप्ति के बाद भी गोपनीयता और बकाया भुगतान की जिम्मेदारी लागू रहेगी।');
        $self->heading('07  स्वीकृति और हस्ताक्षर');
        $self->paragraph('हमने किराया तालिका, कमीशन के नियम और 10% अतिरिक्त कटौती की शर्त पढ़कर समझ ली है और सहमति से हस्ताक्षर कर रहे हैं।');
        $self->space(125);
        $y = $self->y + 35;
        $signers = [40 => ['एजेंट / सहयोगी के हस्ताक्षर', $self->data['agent_name']],
            310 => ['कंपनी के अधिकृत हस्ताक्षर', 'श्री हरि ग्लोबल प्रा. लि.']];
        foreach ($signers as $x => [$label, $name]) {
            $self->pdf->line($x, $y, $x + 235, $y, self::GOLD);
            $self->draw($x, $y + 8, $label, 10);
            $self->draw($x, $y + 27, $self->fit('नाम: ' . $name, 235, 10), 10, self::NAVY);
            $self->draw($x, $y + 50, 'तारीख: __________________', 10);
        }
        return $self->pdf->output();
    }

    /** Fail closed on missing glyphs AND failed shaping, rather than issuing broken Hindi. */
    private function ensureText(string $text): void
    {
        $shape = DevShape::shape($text);
        if (!$shape || !$shape['g']) { throw new RuntimeException('Hindi shaping is unavailable. Check HarfBuzz and PHP CLI/FFI.'); }
        foreach ($shape['g'] as $glyph) {
            if ($glyph[0] === 0) { throw new InvalidArgumentException('किसी अक्षर का फ़ॉन्ट उपलब्ध नहीं है। नाम और विवरण हिंदी या अंग्रेज़ी में भरें।'); }
        }
    }
    private function draw(float $x, float $y, string $text, float $size = 11, array $color = self::INK): void
    {
        $this->ensureText($text);
        $this->pdf->textCID($x, $y, $text, $size, 'F7', $color);
    }
    private function wrap(string $text, float $width = 510, float $size = 11): array
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        // Prime all candidates in one child process on PHP-FPM; shaping a line at a time is costly.
        $candidates = [];
        for ($i = 0; $i < count($words); $i++) {
            $line = '';
            for ($j = $i; $j < count($words); $j++) {
                $line .= ($line === '' ? '' : ' ') . $words[$j];
                $candidates[] = $line;
            }
        }
        DevShape::prime($candidates);
        $lines = []; $line = '';
        foreach ($words as $word) {
            $this->ensureText($word);
            if ($this->pdf->textWidthCID($word, $size) > $width) { throw new InvalidArgumentException('बहुत लंबा शब्द है; कृपया बीच में स्थान दें।'); }
            $next = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && $this->pdf->textWidthCID($next, $size) > $width) { $lines[] = $line; $line = $word; }
            else { $line = $next; }
        }
        if ($line !== '') { $lines[] = $line; }
        return $lines;
    }
    /** One line only: a long name under a signature line is cut at a word with "...". */
    private function fit(string $text, float $width, float $size): string
    {
        $lines = $this->wrap($text, $width - $this->pdf->textWidthCID('...', $size), $size);
        return count($lines) > 1 ? $lines[0] . '...' : $lines[0];
    }
    private function space(float $height): void { if ($this->y + $height > 756) { $this->page(); } }
    private function paragraph(string $text, array $color = self::INK): void
    {
        $lines = $this->wrap($text);
        $this->space(count($lines) * 18 + 9);
        foreach ($lines as $line) { $this->draw(42, $this->y, $line, 11, $color); $this->y += 18; }
        $this->y += 9;
    }
    private function heading(string $text, bool $red = false): void
    {
        $this->space(80);
        $this->pdf->rect(40, $this->y, 515, 29, $red ? self::RED : self::NAVY);
        $this->draw(50, $this->y + 6, $text, 12, [255, 255, 255]);
        $this->y += 40;
    }
    private function page(): void
    {
        if ($this->page++) { $this->pdf->newPage(); }
        $this->pdf->rect(0, 0, 595.28, 108, self::NAVY);
        $this->pdf->rect(0, 108, 595.28, 4, self::GOLD);
        $this->draw(40, 23, 'श्री हरि ग्लोबल प्रा. लि.', 22, [255, 255, 255]);
        $this->draw(40, 57, 'व्यवसाय सहयोग / एजेंट समझौता', 15, [234, 201, 123]);
        $this->pdf->text(40, 88, 'SHREE HARI GLOBAL PVT. LTD.  |  CIN U52291GJ2026PTC174029  |  shreehariglobal.in', 9, 'F1', [255, 255, 255]);
        $this->pdf->line(40, 778, 555, 778, self::GOLD);
        $this->draw(40, 786, 'समझौता: ' . $this->data['agreement_no'] . '  |  ' . $this->data['date'], 8);
        $this->draw(497, 786, 'पृष्ठ ' . $this->page, 9);
        $this->y = 130;
    }
}
