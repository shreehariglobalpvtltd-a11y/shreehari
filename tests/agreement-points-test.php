<?php
/**
 * Agreement points the office corrects from admin (2 Oct 2026).
 *
 *   1. Every /agreements/ page (2 agreements × ne / hi / en / gu) parses into
 *      sections and points, and every point's own wording passes its own
 *      blank check (so "Save" with nothing changed can never fail).
 *   2. An edit must keep each {{blank}} exactly once; an added point has none.
 *   3. Save → the public payload carries it; the original wording again →
 *      the row is gone; hide → hidden; add → listed under its section.
 *   4. Over HTTP (SHG_TEST_BASE): /api/agreement-points.php answers the page.
 *
 *   php -c .claude/php-dev.ini tests/agreement-points-test.php
 *
 * Writes only rows for doc 'partner' lang 'gu' under test numbers and puts
 * the table back as it found it. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agreementtext.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== Agreement points ===\n\n";

/* ---- 1. the pages --------------------------------------------------- */
foreach (array_keys(AgreementText::DOCS) as $d) {
    foreach (array_keys(AgreementText::LANGS) as $l) {
        $secs = AgreementText::original($d, $l);
        $pts  = AgreementText::points($d, $l);
        $bad  = array_filter($pts, static fn(array $p): bool => AgreementText::validate($p['text'], $p['keys']) !== null);
        $html = (string) @file_get_contents(AgreementText::file($d, $l));
        check("$d-$l: 11 sections, 40+ points, each point valid as built", count($secs) === 11 && count($pts) >= 40 && $bad === [],
            count($secs) . ' sections · ' . count($pts) . ' points');
        check("$d-$l: the page loads the corrections script", str_contains($html, '/assets/js/agreement-live.js'));
    }
}
$en = AgreementText::points('partner', 'en');
check('a blank is shown as {{name}}', str_contains($en['2.1']['text'], '{{org_rate}}'), $en['2.1']['text']);
check('a radio pair is two blanks name=value', $en['7.3']['keys'] === ['c73=yes', 'c73=no']);
check('the point numbers are the same in all four languages',
    array_keys(AgreementText::points('agent', 'ne')) === array_keys(AgreementText::points('agent', 'gu'))
    && array_keys(AgreementText::points('partner', 'hi')) === array_keys($en));

/* ---- 2. the blank rules --------------------------------------------- */
$k21 = $en['2.1']['keys'];
check('dropping a blank is refused', AgreementText::validate(str_replace('{{org_pct}}', '', $en['2.1']['text']), $k21) !== null);
check('a blank twice is refused', AgreementText::validate($en['2.1']['text'] . ' {{org_rate}}', $k21) !== null);
check('an unknown blank is refused', AgreementText::validate($en['2.1']['text'] . ' {{nope}}', $k21) !== null);
check('blanks may move in the sentence', AgreementText::validate('{{org_fixed}} {{org_target}} {{org_pct}} {{org_rate}} reordered', $k21) === null);
check('empty text is refused (hide instead)', AgreementText::validate('   ', $k21) !== null);

/* ---- 3. save / reset / hide / add ----------------------------------- */
if (!AgreementText::available()) {
    echo "  SKIP  agreement_points table missing — run database/upgrade-2026-10-02-agreement-points.sql\n";
} else {
    $before = Database::fetchAll("SELECT * FROM agreement_points WHERE doc = 'partner' AND lang = 'gu'");
    Database::delete('agreement_points', "doc = 'partner' AND lang = 'gu'");
    try {
        $gu  = AgreementText::points('partner', 'gu');
        $p11 = $gu['1.1'];
        check('save an edited wording', AgreementText::savePoint('partner', 'gu', '1.1', $p11['text'] . ' (TEST)', false, 0) === null);
        $pl = AgreementText::publicPayload('partner', 'gu');
        check('…the page payload carries it', ($pl['points']['1.1']['text'] ?? '') === $p11['text'] . ' (TEST)');
        check('the original wording again → the row is removed',
            AgreementText::savePoint('partner', 'gu', '1.1', $p11['text'], false, 0) === null
            && !Database::exists("SELECT 1 FROM agreement_points WHERE doc = 'partner' AND lang = 'gu' AND num = '1.1'"));
        check('hide a point', AgreementText::savePoint('partner', 'gu', '1.2', $gu['1.2']['text'], true, 0) === null
            && (AgreementText::publicPayload('partner', 'gu')['points']['1.2']['hidden'] ?? false) === true);
        $p21 = $gu['2.1'];
        check('an edit that loses a blank is not saved',
            AgreementText::savePoint('partner', 'gu', '2.1', 'no boxes at all', false, 0) !== null
            && !Database::exists("SELECT 1 FROM agreement_points WHERE doc = 'partner' AND lang = 'gu' AND num = '2.1'"));
        check('add a point under section 1', AgreementText::addPoint('partner', 'gu', '1', '1.9', 'TEST added point', 0) === null);
        $add = AgreementText::publicPayload('partner', 'gu')['added'];
        check('…listed with its section', $add !== [] && $add[0]['num'] === '1.9' && $add[0]['section'] === '1');
        check('an existing number cannot be added again', AgreementText::addPoint('partner', 'gu', '1', '1.1', 'dup', 0) !== null);
        check('an added point cannot carry blanks', AgreementText::addPoint('partner', 'gu', '1', '1.10', 'x {{org_rate}}', 0) !== null);
        check('a section the page does not have is refused', AgreementText::addPoint('partner', 'gu', '99', '99.1', 'x', 0) !== null);
        check('remove the added point', AgreementText::saveAdded('partner', 'gu', '1.9', '', true, 0) === null
            && AgreementText::publicPayload('partner', 'gu')['added'] === []);
        $audit = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'agreement.point_%' AND entity_id LIKE 'partner-gu %' AND created_at >= NOW() - INTERVAL 5 MINUTE", [], 0);
        check('every change is in the activity log', $audit >= 5, $audit . ' rows');

        /* ---- 4. the API --------------------------------------------- */
        $base = getenv('SHG_TEST_BASE') ?: '';
        if ($base === '') {
            echo "  SKIP  HTTP part (set SHG_TEST_BASE)\n";
        } else {
            AgreementText::savePoint('partner', 'gu', '1.1', $p11['text'] . ' (HTTP)', false, 0);
            $raw = @file_get_contents(rtrim($base, '/') . '/api/agreement-points.php?doc=partner&lang=gu');
            $j   = is_string($raw) ? json_decode($raw, true) : null;
            check('the API answers the page with the change', is_array($j) && ($j['points']['1.1']['text'] ?? '') === $p11['text'] . ' (HTTP)');
            $bad = @file_get_contents(rtrim($base, '/') . '/api/agreement-points.php?doc=nope&lang=gu');
            check('an unknown agreement is refused', $bad === false || str_contains((string) $bad, '"ok":false'));
        }
    } catch (Throwable $e) {
        check('unexpected error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
    } finally {
        Database::delete('agreement_points', "doc = 'partner' AND lang = 'gu'");
        foreach ($before as $r) {
            unset($r['id']);
            Database::insert('agreement_points', $r);
        }
    }
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
