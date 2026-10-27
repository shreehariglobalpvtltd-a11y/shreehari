<?php
/**
 * /api/application-status.php — Public Application Status Tracker.
 *
 * Allows applicants (agents, sub-agents, company staff, partners) to track
 * their application standing in real time using their Application Reference
 * (e.g. AGR-000021, STF-000005) or registered WhatsApp mobile number.
 *
 * Returns sanitized standing, KYC verification state, and any correction
 * instructions from the office without exposing sensitive identity details.
 */

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST'], true)) {
        Response::error('This endpoint takes GET or POST.', 405);
    }

    $ip = Security::clientIp();
    if (!Security::rateLimit('app_status_track', $ip, 30, 60)) {
        Response::error('Too many requests. Please wait a minute and try again.', 429);
    }

    $query = trim(Security::clean(
        $method === 'POST' ? (Response::field('query', '') ?: Response::field('q', '') ?: Response::field('phone', '') ?: Response::field('ref', '')) : ($_GET['query'] ?? ($_GET['q'] ?? ($_GET['phone'] ?? ($_GET['ref'] ?? '')))),
        60
    ));

    if ($query === '') {
        Response::invalid(['query' => 'Enter your Application Reference (e.g. AGR-000010) or WhatsApp mobile number.']);
    }

    $cleanDigits = preg_replace('/\D/', '', $query);
    if (str_starts_with($cleanDigits, '91') && strlen($cleanDigits) === 12) {
        $cleanDigits = substr($cleanDigits, 2);
    } elseif (str_starts_with($cleanDigits, '977') && strlen($cleanDigits) === 13) {
        $cleanDigits = substr($cleanDigits, 3);
    }

    $match = null;
    $typeFound = null;

    // 1. Search in agent_applications
    if (Database::exists("SHOW TABLES LIKE 'agent_applications'")) {
        $sql = "SELECT id, ref, type, role_category, name, phone, status, kyc_status, agreement_status, 
                       correction_notes, correction_fields, admin_id, created_at, updated_at
                FROM agent_applications
                WHERE ref = :q OR phone LIKE :p OR phone_raw LIKE :p2
                ORDER BY id DESC LIMIT 1";
        $row = Database::fetch($sql, [
            'q'  => strtoupper($query),
            'p'  => '%' . $cleanDigits . '%',
            'p2' => '%' . $query . '%'
        ]);
        if ($row !== null) {
            $match = $row;
            $typeFound = 'agent';
        }
    }

    // 2. Search in staff_applications if not found
    if ($match === null && Database::exists("SHOW TABLES LIKE 'staff_applications'")) {
        $sql = "SELECT id, full_name AS name, phone, role_applied, status, kyc_status,
                       correction_notes, correction_fields, admin_id, created_at, updated_at
                FROM staff_applications
                WHERE (CONCAT('STF-', LPAD(id, 6, '0')) = :q) OR phone LIKE :p
                ORDER BY id DESC LIMIT 1";
        $srow = Database::fetch($sql, [
            'q' => strtoupper($query),
            'p' => '%' . $cleanDigits . '%'
        ]);
        if ($srow !== null) {
            $match = $srow;
            $match['ref'] = 'STF-' . str_pad((string)$srow['id'], 6, '0', STR_PAD_LEFT);
            $match['role_category'] = 'staff';
            $match['type'] = 'staff';
            $match['agreement_status'] = 'signed';
            $typeFound = 'staff';
        }
    }

    if ($match === null) {
        Response::notFound('No application found for "' . htmlspecialchars($query) . '". Please check your reference code or registered mobile number.');
    }

    // Determine clean status label
    $rawStatus = (string) ($match['status'] ?? 'new');
    $statusLabel = 'Pending Review';
    $statusClass = 'pending';
    $hasCorrection = !empty($match['correction_notes']) || !empty($match['correction_fields']);

    if ($hasCorrection) {
        $statusLabel = 'Correction Needed';
        $statusClass = 'correction';
    } elseif ($rawStatus === 'approved') {
        $statusLabel = 'Approved & Active';
        $statusClass = 'approved';
    } elseif ($rawStatus === 'rejected') {
        $statusLabel = 'Application Rejected';
        $statusClass = 'rejected';
    } elseif ($rawStatus === 'reviewing' || $rawStatus === 'submitted') {
        $statusLabel = 'Under Review by Admin';
        $statusClass = 'reviewing';
    }

    // Mask name for privacy
    $nameParts = explode(' ', trim((string) ($match['name'] ?? '')));
    $maskedName = $nameParts[0];
    if (isset($nameParts[1])) {
        $maskedName .= ' ' . mb_substr($nameParts[1], 0, 1) . '...';
    }

    // Lookup official assigned code if approved
    $officialCode = null;
    $admId = (int) ($match['admin_id'] ?? 0);
    if ($admId > 0) {
        require_once INCLUDE_PATH . '/agentwallet.php';
        $officialCode = AgentWallet::agentCodeLabel($admId);
    }

    Response::success([
        'found'             => true,
        'ref'               => (string) $match['ref'],
        'name'              => $maskedName,
        'role_category'     => (string) ($match['role_category'] ?? ($match['type'] ?? 'agent')),
        'status'            => $rawStatus,
        'status_label'      => $statusLabel,
        'status_class'      => $statusClass,
        'has_correction'    => $hasCorrection,
        'correction_notes'  => (string) ($match['correction_notes'] ?? ''),
        'correction_fields' => (string) ($match['correction_fields'] ?? ''),
        'kyc_status'        => (string) ($match['kyc_status'] ?? 'none'),
        'agreement_status'  => (string) ($match['agreement_status'] ?? 'none'),
        'official_code'     => $officialCode,
        'applied_on'        => substr((string) $match['created_at'], 0, 10),
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
