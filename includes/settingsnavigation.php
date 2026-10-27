<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit('Forbidden'); }

/** Presentation only. Stored keys, groups, validation and write permissions stay unchanged. */
final class SettingsNavigation
{
    public const CATEGORIES = [
        'company' => ['🏢', 'Company & Branch Settings', 'Company identity, offices, contacts and public website presentation.'],
        'booking' => ['🎫', 'Booking & Ticket Settings', 'Seat rules, fares, offers, ticket documents and delivery preferences.'],
        'fleet' => ['🚌', 'Bus, Route & GPS Settings', 'Routing services, maps, GPS display and existing fleet controls.'],
        'agents' => ['🤝', 'Agent Commission & Wallet Settings', 'Commission rules, wallet limits, counter caps and settlements.'],
        'staff' => ['👥', 'Staff & Payroll Settings', 'Existing staff, duties and payroll controls; private records keep their permissions.'],
        'partners' => ['🏢', 'Pvt Ltd Partner Settings', 'Existing partner records and agreements. Legal capital and share ownership require verified company records.'],
        'payments' => ['💳', 'Payments & Refund Settings', 'Payment methods, proof checks, risk controls, refunds and currency conversion.'],
        'notifications' => ['📲', 'WhatsApp & Notification Settings', 'Message providers, templates, reminders, reports and delivery controls.'],
        'devices' => ['📱', 'Android App & Device Settings', 'Existing app preferences and tracker connections. Native background tracking requires device verification.'],
        'security' => ['🔒', 'Security, Roles & Permissions', 'Sign-in, sessions, access controls and connections. Stored secrets remain write-only.'],
        'ai' => ['🤖', 'AI & Automation Settings', 'The existing Sahayak assistant, AI providers, predictions and marketing automation.'],
        'backup' => ['☁️', 'Backup, Audit & Deployment Settings', 'Existing backup, data checks and deployment controls. Restore readiness must be tested.'],
    ];

    /** Classify metadata, never values. The legacy classification remains a fallback. */
    public static function category(string $key, string $group, string $legacy): string
    {
        if (preg_match('/^(backup_|gdrive_|data_audit_|automation_log_|deploy_)/', $key)
            || in_array($group, ['backup', 'deployment', 'audit'], true)) { return 'backup'; }
        if (preg_match('/^(android_|device_|tracker_|owntracks_|app_)/', $key)
            || in_array($group, ['android', 'devices', 'trackers'], true)) { return 'devices'; }
        if (preg_match('/^(staffmsg_|wa_|whatsapp_|twilio_|aisensy_|sms_|smtp_|push_|notify_|reminder_|office_report_|eta_alert_)/', $key)) { return 'notifications'; }
        if (preg_match('/^(partner_|capital_|shareholder_)/', $key)
            || in_array($group, ['partner', 'partners'], true)) { return 'partners'; }
        if (preg_match('/^(staff_|payroll_|salary_|attendance_|hr_|driver_|conductor_)/', $key)
            || in_array($group, ['staff', 'payroll', 'hr'], true)) { return 'staff'; }
        if (str_contains($key, 'refund') || preg_match('/^(payment_|pay_|risk_|fraud_|upi_|esewa_|icici_)/', $key)) { return 'payments'; }
        if ($legacy === 'tickets') { return 'booking'; }
        if (preg_match('/^(gps_|nav_|map_|maps_|route_|bus_)/', $key)
            || in_array($group, ['gps', 'maps', 'routes', 'fleet'], true)) { return 'fleet'; }
        if (preg_match('/^(session_|otp_|pin_|login_|google_client_)/', $key)) { return 'security'; }
        if (preg_match('/^(ai_|brain_|cerebras_|grok_|gemini_|openai_|openrouter_|mk_|social_|telegram_)/', $key)) { return 'ai'; }
        return match ($legacy) {
            'booking', 'fares' => 'booking',
            'agents' => 'agents',
            'payments' => 'payments',
            'whatsapp', 'notify' => 'notifications',
            'ai', 'marketing' => 'ai',
            'security', 'keys' => 'security',
            default => 'company',
        };
    }

    /** Existing category/deep links continue to open a meaningful section. */
    public const LEGACY = [
        'company'=>'company', 'booking'=>'booking', 'fares'=>'booking', 'tickets'=>'booking',
        'payments'=>'payments', 'agents'=>'agents', 'whatsapp'=>'notifications', 'notify'=>'notifications',
        'marketing'=>'ai', 'ai'=>'ai', 'keys'=>'security', 'website'=>'company', 'security'=>'security', 'other'=>'company',
    ];
}
