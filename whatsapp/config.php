<?php
/**
 * =====================================================================
 *  whatsapp/config.php — Meta WhatsApp Cloud API configuration.
 *
 *  Every value is read from the environment first (Hostinger / php-fpm
 *  pool `env[...]`), then from the admin `settings` table, where the rest
 *  of this app keeps its provider credentials. Nothing secret lives in
 *  this file or in git.
 *
 *    env var                 settings key
 *    META_ACCESS_TOKEN       whatsapp_api_token      (system-user permanent token)
 *    META_PHONE_NUMBER_ID    whatsapp_phone_id
 *    META_WABA_ID            whatsapp_waba_id
 *    META_APP_SECRET         whatsapp_app_secret     (signs webhook POSTs)
 *    META_API_VERSION        whatsapp_api_version
 *    WA_WEBHOOK_TOKEN        whatsapp_webhook_verify_token
 *
 *  Loaded only from inside the app (webhook.php / includes/notify.php);
 *  a direct browser hit answers 404.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

if (!function_exists('wa_config_value')) {
    /** Environment first, then the settings table, then the default. */
    function wa_config_value(string $env, string $settingKey, string $default = ''): string
    {
        $v = getenv($env);
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
        if (class_exists('Settings')) {
            $s = trim(Settings::getString($settingKey, ''));
            if ($s !== '') {
                return $s;
            }
        }
        return $default;
    }
}

defined('META_ACCESS_TOKEN')      || define('META_ACCESS_TOKEN',      wa_config_value('META_ACCESS_TOKEN', 'whatsapp_api_token'));
defined('META_PHONE_NUMBER_ID')   || define('META_PHONE_NUMBER_ID',   wa_config_value('META_PHONE_NUMBER_ID', 'whatsapp_phone_id'));
defined('META_WABA_ID')           || define('META_WABA_ID',           wa_config_value('META_WABA_ID', 'whatsapp_waba_id'));
defined('META_APP_SECRET')        || define('META_APP_SECRET',        wa_config_value('META_APP_SECRET', 'whatsapp_app_secret'));
defined('META_API_VERSION')       || define('META_API_VERSION',       wa_config_value('META_API_VERSION', 'whatsapp_api_version', 'v25.0'));
defined('META_API_BASE')          || define('META_API_BASE',          'https://graph.facebook.com/' . META_API_VERSION);
defined('WHATSAPP_WEBHOOK_TOKEN') || define('WHATSAPP_WEBHOOK_TOKEN', wa_config_value('WA_WEBHOOK_TOKEN', 'whatsapp_webhook_verify_token'));
defined('WHATSAPP_LOG_DIR')       || define('WHATSAPP_LOG_DIR',       __DIR__ . '/logs');
