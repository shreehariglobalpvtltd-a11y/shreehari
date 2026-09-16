<?php
/**
 * POST /api/lang.php — remember which language this traveller reads in.
 *
 * users.preferred_lang ENUM('en','hi','ne') has been in schema.sql since
 * the first build with zero code reading or writing it. The choice lived
 * only in localStorage, so a passenger who set Nepali on their phone got
 * English again on a borrowed device or after clearing site data.
 *
 * Deliberately tiny and deliberately quiet: it needs a session, writes one
 * column, and returns nothing the caller did not already know. Signed-out
 * visitors are not an error — localStorage still holds their choice and
 * there is simply no row to write it to.
 *
 * Body { lang: 'en' | 'hi' | 'ne' }
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    Security::requireCsrf();

    $lang = strtolower(Security::clean((string) Response::field('lang', ''), 5));
    if (!in_array($lang, ['en', 'hi', 'ne'], true)) {
        Response::invalid(['lang' => 'Unknown language.']);
    }

    $me = Auth::user();
    if ($me === null) {
        // Not signed in — nothing to persist, and nothing went wrong.
        Response::success(['lang' => $lang, 'stored' => false]);
    }

    Security::requireRateLimit('lang_pref', Security::clientIp(), 30, 300);

    Database::update('users', ['preferred_lang' => $lang], 'id = :id', ['id' => (int) $me['id']]);

    Response::success(['lang' => $lang, 'stored' => true]);
} catch (Throwable $e) {
    Response::serverError($e);
}
