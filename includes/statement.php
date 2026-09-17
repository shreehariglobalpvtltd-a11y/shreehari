<?php
/**
 * =====================================================================
 *  includes/statement.php — agent statement PDFs as WhatsApp media
 *  (17 Sep 2026)
 *
 *  ReportPdf::agentReport() streams straight to the browser and exits, so
 *  nothing could ever hand a statement to Twilio / Meta, which need a
 *  PUBLIC URL they can fetch. This writes the same PDF (via
 *  ReportPdf::agentReportBytes) to TICKET_PATH/statements/ — a tree nginx
 *  already denies — and returns a signed, EXPIRING link served by
 *  download-statement.php, the same pattern as the ticket PNG
 *  (Ticket::imageUrl / download-ticket.php) but with an expiry, because a
 *  statement carries money figures and should not live forever on a URL.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/reportpdf.php';

final class Statement
{
    /** Where the files live (inside the nginx-denied /tickets tree). */
    public static function dir(): string
    {
        return rtrim(TICKET_PATH, '/\\') . '/statements';
    }

    /** The 20-char key that download-statement.php checks. */
    public static function token(string $basename, int $exp): string
    {
        return substr(Security::sign('stmt|' . $basename . '|' . $exp), 0, 20);
    }

    /** A basename this module produced — nothing else is ever served. */
    public static function validName(string $basename): bool
    {
        return preg_match('/^agent-\d+-\d{4}-\d{2}-\d{2}-\d{4}-\d{2}-\d{2}-[a-f0-9]{8}\.pdf$/', $basename) === 1;
    }

    /**
     * Render one agent's statement for [$from, $to] and publish it.
     *
     * @return array{path:string, file:string, url:string, expires:int}
     */
    public static function agentStatementPdf(int $agentId, string $from, string $to, int $byAdminId = 0): array
    {
        if (!Security::isValidDate($from) || !Security::isValidDate($to)) {
            throw new RuntimeException('Statement period is not a valid date range.');
        }
        $built = ReportPdf::agentReportBytes($agentId, $from, $to);

        $dir = self::dir();
        ensureDir($dir);
        self::sweep($dir);

        $file = 'agent-' . $agentId . '-' . $from . '-' . $to . '-' . bin2hex(random_bytes(4)) . '.pdf';
        $path = $dir . '/' . $file;
        if (file_put_contents($path, $built['bytes']) === false) {
            throw new RuntimeException('Could not write the statement file.');
        }
        @chmod($path, 0640);

        $days = max(1, Settings::getInt('wa_statement_link_days', 7));
        $exp  = time() + $days * 86400;
        $url  = appUrl('download-statement.php?f=' . rawurlencode($file) . '&exp=' . $exp . '&k=' . self::token($file, $exp));

        Logger::audit('agent.statement_pdf', 'admin', (string) $agentId, null,
            ['file' => $file, 'from' => $from, 'to' => $to, 'expires' => date('Y-m-d H:i', $exp)],
            'statement PDF published for WhatsApp by admin #' . $byAdminId);

        return ['path' => $path, 'file' => $file, 'url' => $url, 'expires' => $exp];
    }

    /** Delete statements older than the link validity + 1 day (cheap: one glob per publish). */
    public static function sweep(string $dir): void
    {
        $days = max(1, Settings::getInt('wa_statement_link_days', 7)) + 1;
        $cut  = time() - $days * 86400;
        foreach (glob($dir . '/agent-*.pdf') ?: [] as $f) {
            if (@filemtime($f) < $cut) {
                @unlink($f);
            }
        }
    }
}
