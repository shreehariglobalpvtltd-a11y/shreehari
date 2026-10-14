<?php
/**
 * GET /api/explore.php — small public JSON for the home page teasers
 * (9 Oct 2026). No personal data, cacheable for 5 minutes.
 *
 *   ?what=featured[&lang=en|hi|ne]
 *       {ok, data:{places:[{slug,name,nameNe,nameHi,country,category,blurb,blurbNe,unesco,featured,image,imageIllus,url}],
 *                  packages:[{slug,title,titleNe,titleHi,days,nights,country,priceFrom,image,imageIllus,url}]}}
 *       imageIllus (10 Oct 2026): true when the picture is an illustration, not a photo
 *       (ContentPages::isIllustration) - the home cards then label it "Illustration".
 *   ?what=timeline[&limit=6]
 *       {ok, data:{events:[{year,yearNe,title,titleNe,titleHi,icon}], reviewed}}
 *
 * url values point at /explore#place-<slug> and /tours/<slug> (under /hi/ or
 * /ne/ when lang says so). Empty lists until Admin -> Website content is filled.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/contentpages.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        Response::error('This endpoint only answers GET.', 405);
    }
    $what = (string) ($_GET['what'] ?? 'featured');
    $lang = (string) ($_GET['lang'] ?? 'en');
    $lang = in_array($lang, PublicLayout::LANGS, true) ? $lang : 'en';
    Response::$publicCacheSeconds = 300;

    if ($what === 'timeline') {
        $limit = max(1, min(30, (int) ($_GET['limit'] ?? 6)));
        Response::success(['events' => ContentPages::timeline($limit), 'reviewed' => ContentPages::indiaNepalData()['reviewed'],
            'url' => PublicLayout::path('/india-nepal', $lang)]);
    }
    if ($what === 'featured') {
        Response::success(ContentPages::featured($lang));
    }
    Response::error('Unknown what= (featured | timeline).', 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
