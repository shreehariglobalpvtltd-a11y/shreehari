<?php
/**
 * /api/helpdesk.php — the customer side of the Help Desk (5 Oct 2026).
 *
 *   POST {action:'open',   name, phone, country, category, message, pnr?, lang?}
 *        -> {ref:'HD-0051', token:7, status:'open', url}
 *   POST {action:'status', ref, phone}           -> the ticket + replies the desk SENT
 *   POST {action:'reply',  ref, phone, message}  -> adds a line (re-opens a resolved one)
 *
 * A ticket is shown only to the phone it was opened from; "no such ticket"
 * and "not your ticket" read the same. Everything is rate-limited per IP and
 * per phone so the number space cannot be walked.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/helpdesk.php';

try {
    Security::requirePost();
    Security::requireCsrf();
    $ip  = Security::clientIp();
    $act = (string) Response::field('action', '');

    if ($act === 'open') {
        if (!HelpDesk::on()) {
            Response::error('The help desk is closed online right now — please call the office.', 503);
        }
        Security::requireRateLimit('helpdesk_open', $ip, 8, 3600);
        if (trim((string) Response::field('website', '')) !== '') {          // honeypot
            Response::success(['ref' => 'HD-0000', 'token' => 0, 'status' => 'open', 'url' => ''], 'Saved.');
        }
        $phone = Security::digits(Response::field('phone', ''), 15);
        if (!Security::isValidPhone(normalisePhone($phone))) {
            Response::invalid(['phone' => 'Enter your 10-digit mobile number. / १० अङ्कको मोबाइल नम्बर लेख्नुहोस्।']);
        }
        Security::requireRateLimit('helpdesk_open_phone', normalisePhone($phone), 5, 3600);
        $r = HelpDesk::open([
            'name'     => (string) Response::field('name', ''),
            'phone'    => $phone,
            'country'  => (string) Response::field('country', ''),
            'category' => (string) Response::field('category', ''),
            'message'  => (string) Response::field('message', ''),
            'pnr'      => (string) Response::field('pnr', ''),
            'lang'     => (string) Response::field('lang', 'ne'),
            'source'   => (string) Response::field('via', '') === 'chat' ? 'chat' : 'web',
        ]);
        if (!$r['ok']) {
            if (($r['error'] ?? '') === 'message') {
                Response::invalid(['message' => 'Tell us what happened (a few words). / के भयो, छोटकरीमा लेख्नुहोस्।']);
            }
            if (($r['error'] ?? '') === 'phone') {
                Response::invalid(['phone' => 'Enter your 10-digit mobile number.']);
            }
            Response::error('Could not save right now — please call the office.', 500);
        }
        Response::success([
            'ref'       => $r['ref'],
            'token'     => $r['token'] ?? null,
            'status'    => $r['status'] ?? 'open',
            'duplicate' => $r['duplicate'] ?? false,
            'acked'     => $r['ack'] ?? false,
            'url'       => HelpDesk::statusUrl((string) $r['ref']),
        ], 'Your help desk number is ' . $r['ref'] . '.');
    }

    if ($act === 'status' || $act === 'reply') {
        Security::requireRateLimit('helpdesk_look', $ip, 30, 600);
        $ref   = (string) Response::field('ref', '');
        $phone = Security::digits(Response::field('phone', ''), 15);
        if ($act === 'reply') {
            Security::requireRateLimit('helpdesk_reply', $ip, 10, 600);
            if (!HelpDesk::customerReply($ref, $phone, (string) Response::field('message', ''))) {
                Response::error('That number and mobile do not match an open ticket. / नम्बर र मोबाइल मिलेन।', 404);
            }
        }
        $view = HelpDesk::publicView($ref, $phone);
        if ($view === null) {
            Response::error('No ticket with that number on this mobile. / यो मोबाइलमा त्यो नम्बरको टिकट भेटिएन।', 404);
        }
        Response::success($view);
    }

    Response::error('Unknown action.', 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
