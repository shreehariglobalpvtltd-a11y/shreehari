<?php
/**
 * offer-card.php — the running offer's pamphlet as a PUBLIC picture
 * (6 Oct 2026).
 *
 * The WhatsApp image template (shg_offer_image) needs a public https link
 * for its header photo, and so does a Facebook post or an email that
 * shows the picture inline. Same drawing as Admin → Offer pamphlet
 * (includes/offercard.php): the offer running TODAY + the live fares, so
 * it can never show an offer that has ended. No personal data.
 *
 *   /offer-card.php?lang=ne|hi|en   PNG 1080×1350, 404 when no offer runs
 */
declare(strict_types=1);
define('SHG_APP', true);
require __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/offercard.php';
require_once INCLUDE_PATH . '/ticket.php';

$lang = in_array($_GET['lang'] ?? '', OfferCard::LANGS, true) ? (string) $_GET['lang'] : 'ne';
try {
    $offer = OfferCard::offer();
} catch (Throwable $e) {
    $offer = null;
}
if ($offer === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No offer is running.');
}

$phone = Settings::getString('company_phone', '');
$site  = preg_replace('#^(https?://)?(www\.)?#', '', rtrim(APP_URL, '/')) ?: 'shreehariglobal.in';

/* One drawing per offer + fares + language: shaping Devanagari costs a child
   process, and a campaign asks for the same picture hundreds of times. */
$key  = md5(json_encode([$offer, Fare::dirFares(), $lang, $phone, $site, filemtime(INCLUDE_PATH . '/offercard.php')]) ?: '');
$dir  = sys_get_temp_dir() . '/shg-offer-card';
$file = $dir . '/' . $key . '.png';
$png  = is_file($file) ? (string) file_get_contents($file) : '';
if ($png === '') {
    $png = OfferCard::png($lang, $offer, $phone, $site, Ticket::logoFile());
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    @file_put_contents($file . '.tmp', $png) !== false && @rename($file . '.tmp', $file);
}

header('Content-Type: image/png');
header('Content-Disposition: inline; filename="shg-offer-' . strtolower((string) $offer['code']) . '-' . $lang . '.png"');
header('Cache-Control: public, max-age=3600');
header('Content-Length: ' . strlen($png));
echo $png;
