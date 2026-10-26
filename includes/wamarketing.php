<?php
/** Explicit opt-in marketing. Only approved Meta templates can reach the queue. */
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit('Forbidden'); }

final class WaMarketing
{
    private const PREVIEW_TTL = 900;

    /** Only call with the raw sender/text from an authenticated provider webhook. */
    public static function consentMessage(string $sender, string $text): ?array
    {
        $command = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
        $state = match ($command) {
            'start offers', 'subscribe offers', 'offers on', 'अफर सुरु' => 'opted_in',
            'stop', 'stop offers', 'unsubscribe', 'unsubscribe offers', 'offers off', 'अफर बन्द', 'बन्द' => 'opted_out',
            default => null,
        };
        if ($state === null) { return null; }
        $phone = self::internationalPhone($sender);
        if ($phone === '') {
            return ['text' => 'WhatsApp number could not be verified. Please contact the office to update offer preferences.', 'media' => null];
        }
        try {
            Database::run(
                'INSERT INTO wa_marketing_consents (phone,country,state,evidence,source,revision,updated_at)
                 VALUES (:phone,:country,:state,:evidence,\'whatsapp_inbound\',1,:now)
                 ON DUPLICATE KEY UPDATE state = VALUES(state), evidence = VALUES(evidence),
                   source = VALUES(source), revision = revision + 1, updated_at = VALUES(updated_at)',
                ['phone' => $phone, 'country' => str_starts_with($phone, '977') ? 'NP' : 'IN',
                 'state' => $state, 'evidence' => mb_substr($text, 0, 200), 'now' => self::now()]
            );
            return ['text' => $state === 'opted_in'
                ? 'अफर र सेवाका प्रचार सन्देशका लागि तपाईंको सहमति सुरक्षित भयो। बन्द गर्न STOP पठाउनुहोस्। Ticket सम्बन्धी सूचना अलग रहन्छ।'
                : 'तपाईंको नम्बरमा प्रचार/अफर सन्देश बन्द गरियो। Ticket सम्बन्धी आवश्यक सूचना जारी रहन्छ। फेरि अफर चाहिँदा START OFFERS पठाउनुहोस्।', 'media' => null];
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
            return ['text' => 'Your offer preference could not be saved. Please contact the office; no successful update has been recorded.', 'media' => null];
        }
    }

    /** No inference from a 10-digit booking/contact number: webhook E.164 only. */
    public static function internationalPhone(string $raw): string
    {
        $phone = preg_replace('/\D/', '', $raw) ?? '';
        if (str_starts_with($phone, '00')) { $phone = substr($phone, 2); }
        return preg_match('/^(?:91[6-9][0-9]{9}|9779[0-9]{9})$/D', $phone) ? $phone : '';
    }

    public static function canManage(array $ctx): bool
    {
        if (($ctx['role'] ?? '') !== 'admin' || (int) ($ctx['adminId'] ?? 0) < 1) { return false; }
        $admin = Database::fetch('SELECT id,phone,role,is_active FROM admins WHERE id = :id', ['id' => (int) $ctx['adminId']]);
        return $admin !== null && (int) $admin['is_active'] === 1
            && in_array($admin['role'], ['superadmin', 'manager'], true)
            && normalisePhone((string) $admin['phone']) !== ''
            && normalisePhone((string) $admin['phone']) === normalisePhone((string) ($ctx['phone'] ?? ''));
    }

    public static function draft(array $ctx, array $args): array
    {
        if (!self::canManage($ctx)) { return self::error('Only an active manager or owner may manage marketing campaigns.'); }
        $title = trim((string) ($args['title'] ?? ''));
        $name = trim((string) ($args['template_name'] ?? ''));
        $lang = trim((string) ($args['language'] ?? 'en'));
        $country = (string) ($args['country'] ?? 'all');
        $vars = $args['body_vars'] ?? [];
        if ($title === '' || mb_strlen($title) > 120 || !preg_match('/^[a-z0-9_]{1,100}$/D', $name)
            || !preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D', $lang)
            || !in_array($country, ['all', 'IN', 'NP'], true) || !is_array($vars) || !array_is_list($vars) || count($vars) > 10) {
            return self::error('Supply title (1–120 characters), template_name, language, country all/IN/NP and up to 10 body_vars.');
        }
        foreach ($vars as $v) {
            if (!is_string($v) || trim($v) === '' || mb_strlen($v) > 500 || preg_match('/[\r\n\t]/', $v)) {
                return self::error('Template variables must be non-empty single-line strings of at most 500 characters.');
            }
        }
        $id = Database::insert('wa_marketing_campaigns', [
            'created_by' => (int) $ctx['adminId'], 'title' => $title, 'template_name' => $name,
            'template_lang' => $lang, 'body_vars' => self::json($vars), 'country' => $country,
            'state' => 'draft', 'created_at' => self::now(), 'updated_at' => self::now(),
        ]);
        return ['ok' => true, 'campaign_id' => $id, 'state' => 'draft', 'sent' => false,
            'next' => 'Call marketing_preview to see the approved text and exact consenting audience before requesting confirmation.'];
    }

    public static function preview(array $ctx, array $args): array
    {
        if (!self::canManage($ctx)) { return self::error('Only an active manager or owner may preview campaigns.'); }
        $campaign = self::campaign((int) ($args['campaign_id'] ?? 0));
        if ($campaign === null) { return self::error('Campaign not found.'); }
        if (in_array($campaign['state'], ['queued', 'complete'], true)) { return self::status($ctx, $args); }
        $eligible = self::eligible((string) $campaign['country']);
        $max = max(1, min(5000, Settings::getInt('wa_marketing_max_recipients', 200)));
        $blockers = self::readiness();
        if (count($eligible) === 0) { $blockers[] = 'No explicitly opted-in eligible recipients. Customers must send START OFFERS themselves.'; }
        if (count($eligible) > $max) { $blockers[] = 'Audience exceeds the configured campaign limit (' . $max . '). Choose a smaller audience.'; }
        $template = self::verifyTemplate($campaign);
        if (!$template['ok']) { $blockers[] = $template['error']; }
        $summary = ['ok' => true, 'campaign_id' => (int) $campaign['id'], 'title' => $campaign['title'],
            'country' => $campaign['country'], 'eligible_recipients' => count($eligible),
            'sample' => array_map(static fn(array $r): string => self::mask((string) $r['phone']), array_slice($eligible, 0, 5)),
            'message' => $template['body'] ?? null, 'sent' => false, 'ready' => $blockers === [], 'blockers' => $blockers];
        if ($blockers !== []) { return $summary; }
        $token = strtoupper(bin2hex(random_bytes(4)));
        $id = (int) $campaign['id'];
        $staged = Database::transaction(static function () use ($id, $eligible, $ctx, $template, $token): bool {
            $locked = Database::fetch('SELECT state FROM wa_marketing_campaigns WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($locked === null || !in_array($locked['state'], ['draft', 'preview'], true)) { return false; }
            Database::delete('wa_marketing_recipients', 'campaign_id = :id', ['id' => $id]);
            foreach ($eligible as $row) {
                Database::insert('wa_marketing_recipients', ['campaign_id' => $id, 'phone' => $row['phone'], 'state' => 'preview', 'updated_at' => self::now()]);
            }
            Database::update('wa_marketing_campaigns', [
                'state' => 'preview', 'preview_token' => $token, 'preview_admin_id' => (int) $ctx['adminId'],
                'preview_turn' => (int) ($ctx['turn'] ?? 0), 'preview_at' => self::now(),
                'template_hash' => $template['hash'], 'rendered_body' => $template['body'],
                'recipient_count' => count($eligible), 'updated_at' => self::now(),
            ], 'id = :id', ['id' => $id]);
            return true;
        });
        if (!$staged) { return self::error('Campaign changed while previewing. Check its status.'); }
        return $summary + ['preview_token' => $token, 'confirmation' => 'SEND CAMPAIGN ' . $id . ' ' . $token,
            'expires_in_seconds' => self::PREVIEW_TTL,
            'next' => 'Show the full message, exact recipient count and confirmation phrase to the manager. Queue only after that phrase arrives in a later message. Meta marketing charges may apply.'];
    }

    public static function confirm(array $ctx, array $args): array
    {
        if (!self::canManage($ctx)) { return self::error('Only an active manager or owner may queue campaigns.'); }
        $blockers = self::readiness();
        if ($blockers !== []) { return self::error(implode(' ', $blockers)); }
        $id = (int) ($args['campaign_id'] ?? 0);
        return Database::transaction(static function () use ($ctx, $id): array {
            $campaign = Database::fetch('SELECT * FROM wa_marketing_campaigns WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($campaign === null) { return self::error('Campaign not found.'); }
            $expected = 'SEND CAMPAIGN ' . $id . ' ' . (string) $campaign['preview_token'];
            if ((int) $campaign['preview_admin_id'] !== (int) $ctx['adminId']
                || !hash_equals($expected, strtoupper(trim((string) ($ctx['raw_text'] ?? ''))))) {
                return self::error('The same manager must send the exact confirmation phrase shown in the preview.');
            }
            if (in_array($campaign['state'], ['queued', 'complete'], true)) {
                return ['ok' => true, 'campaign_id' => $id, 'state' => $campaign['state'], 'duplicate' => true, 'sent' => false];
            }
            if ($campaign['state'] !== 'preview' || (int) ($ctx['turn'] ?? 0) <= (int) $campaign['preview_turn']
                || time() - (int) strtotime((string) $campaign['preview_at']) > self::PREVIEW_TTL) {
                return self::error('Preview expired or confirmation is in the same turn. Request a fresh preview, then confirm in the next message.');
            }
            $template = self::verifyTemplate($campaign);
            if (!$template['ok'] || !hash_equals((string) $campaign['template_hash'], (string) ($template['hash'] ?? ''))) {
                return self::error('Template approval or content changed. Request a fresh preview.');
            }
            Database::update('wa_marketing_recipients', ['state' => 'queued', 'updated_at' => self::now()],
                'campaign_id = :id AND state = \'preview\'', ['id' => $id]);
            Database::update('wa_marketing_campaigns', ['state' => 'queued', 'confirmed_by' => (int) $ctx['adminId'],
                'confirmed_at' => self::now(), 'updated_at' => self::now()], 'id = :id', ['id' => $id]);
            return ['ok' => true, 'campaign_id' => $id, 'state' => 'queued', 'queued' => (int) $campaign['recipient_count'],
                'sent' => false, 'detail' => 'Confirmed and queued. Delivery happens in the marketing worker; this is not a delivery receipt.'];
        });
    }

    public static function status(array $ctx, array $args): array
    {
        if (!self::canManage($ctx)) { return self::error('Only an active manager or owner may inspect campaigns.'); }
        $id = (int) ($args['campaign_id'] ?? 0);
        if ($id < 1) {
            return ['ok' => true, 'campaigns' => Database::fetchAll('SELECT id,title,state,recipient_count,created_at FROM wa_marketing_campaigns ORDER BY id DESC LIMIT 10')];
        }
        $campaign = self::campaign($id);
        if ($campaign === null) { return self::error('Campaign not found.'); }
        $counts = [];
        foreach (Database::fetchAll('SELECT state,COUNT(*) AS total FROM wa_marketing_recipients WHERE campaign_id = :id GROUP BY state', ['id' => $id]) as $r) {
            $counts[$r['state']] = (int) $r['total'];
        }
        return ['ok' => true, 'campaign_id' => $id, 'title' => $campaign['title'], 'state' => $campaign['state'],
            'message' => $campaign['rendered_body'], 'counts' => $counts,
            'detail' => 'accepted = Meta accepted the request; delivered/read require a webhook receipt. unknown is not retried automatically.'];
    }

    /** Run only from the CLI worker. A single global DB lock bounds concurrent runs. */
    public static function work(int $limit = 10): array
    {
        if (PHP_SAPI !== 'cli') { return self::error('Marketing delivery is CLI only.'); }
        $blockers = self::readiness();
        if ($blockers !== []) { return ['ok' => false, 'blocked' => $blockers]; }
        if ((int) Database::scalar("SELECT GET_LOCK('shg_wa_marketing',0)", [], 0) !== 1) { return ['ok' => true, 'busy' => true]; }
        $out = ['ok' => true, 'attempted' => 0, 'accepted' => 0, 'failed' => 0, 'unknown' => 0, 'skipped' => 0, 'blocked' => []];
        try {
            // A worker can die after Meta accepted a request. Never resend its claim.
            Database::run("UPDATE wa_marketing_recipients SET state = 'unknown', error = 'Worker stopped during send; inspect Meta before any manual action', updated_at = :now WHERE state = 'sending' AND attempted_at < :cutoff",
                ['now' => self::now(), 'cutoff' => date('Y-m-d H:i:s', time() - 600)]);
            $cap = max(1, min(2000, Settings::getInt('wa_marketing_daily_cap', 100)));
            $used = (int) Database::scalar('SELECT COUNT(*) FROM wa_marketing_recipients WHERE attempted_at >= :day', ['day' => date('Y-m-d 00:00:00')], 0);
            $limit = min(max(1, $limit), 20, max(1, Settings::getInt('wa_marketing_batch_size', 10)), max(0, $cap - $used));
            if ($limit === 0) { $out['blocked'][] = 'Daily attempt cap reached.'; return $out; }
            $rows = Database::fetchAll("SELECT r.id AS recipient_id,r.phone,c.* FROM wa_marketing_recipients r JOIN wa_marketing_campaigns c ON c.id = r.campaign_id WHERE r.state = 'queued' AND c.state = 'queued' ORDER BY r.id LIMIT " . $limit);
            $checked = [];
            foreach ($rows as $r) {
                if (self::readiness() !== []) { $out['blocked'][] = 'Marketing configuration changed.'; break; }
                $cid = (int) $r['id'];
                if (!isset($checked[$cid])) { $checked[$cid] = self::verifyTemplate($r); }
                $template = $checked[$cid];
                if (!$template['ok'] || !hash_equals((string) $r['template_hash'], (string) ($template['hash'] ?? ''))) {
                    $out['blocked'][] = 'Campaign ' . $cid . ': template is unavailable, unapproved or changed.';
                    continue;
                }
                // Revoked managers cannot leave campaigns running after removal.
                $admin = Database::fetch('SELECT is_active,role FROM admins WHERE id = :id', ['id' => (int) $r['confirmed_by']]);
                if ($admin === null || !(int) $admin['is_active'] || !in_array($admin['role'], ['superadmin', 'manager'], true)) {
                    $out['blocked'][] = 'Campaign ' . $cid . ': confirming manager is inactive or no longer authorized.';
                    continue;
                }
                $consent = Database::fetch('SELECT state FROM wa_marketing_consents WHERE phone = :phone', ['phone' => $r['phone']]);
                if ($consent === null || $consent['state'] !== 'opted_in' || self::internationalPhone((string) $r['phone']) === '' || self::blocked((string) $r['phone'])) {
                    Database::update('wa_marketing_recipients', ['state' => 'skipped', 'error' => 'Consent withdrawn, invalid number or blocked account', 'updated_at' => self::now()], 'id = :id AND state = \'queued\'', ['id' => (int) $r['recipient_id']]);
                    $out['skipped']++;
                    continue;
                }
                $claimed = Database::update('wa_marketing_recipients', ['state' => 'sending', 'attempted_at' => self::now(), 'updated_at' => self::now()],
                    'id = :id AND state = \'queued\'', ['id' => (int) $r['recipient_id']]);
                if ($claimed !== 1) { continue; }
                // Check again immediately before the outbound call, after claiming.
                $current = Database::fetch('SELECT state FROM wa_marketing_consents WHERE phone = :phone', ['phone' => $r['phone']]);
                if ($current === null || $current['state'] !== 'opted_in') {
                    Database::update('wa_marketing_recipients', ['state' => 'skipped', 'error' => 'Consent withdrawn before send', 'updated_at' => self::now()], 'id = :id', ['id' => (int) $r['recipient_id']]);
                    $out['skipped']++;
                    continue;
                }
                $result = self::sendOnce((string) $r['phone'], $r);
                $out['attempted']++;
                $out[$result['state']]++;
                Database::update('wa_marketing_recipients', ['state' => $result['state'], 'provider_ref' => $result['message_id'] ?: null,
                    'error' => $result['error'] ?: null, 'updated_at' => self::now()], 'id = :id', ['id' => (int) $r['recipient_id']]);
            }
            Database::run("UPDATE wa_marketing_campaigns c SET state = 'complete', updated_at = :now WHERE c.state = 'queued' AND NOT EXISTS (SELECT 1 FROM wa_marketing_recipients r WHERE r.campaign_id = c.id AND r.state IN ('queued','sending'))", ['now' => self::now()]);
            return $out;
        } finally { Database::scalar("SELECT RELEASE_LOCK('shg_wa_marketing')"); }
    }

    /** Called from a signature-verified Meta delivery webhook, before its legacy log lookup. */
    public static function deliveryStatus(string $messageId, string $state): void
    {
        if ($messageId === '' || !in_array($state, ['delivered', 'read', 'failed'], true)) { return; }
        try {
            $allowed = match ($state) {
                'read' => ['accepted', 'delivered', 'failed', 'unknown'],
                'delivered' => ['accepted', 'failed', 'unknown'],
                'failed' => ['accepted', 'unknown'],
            };
            $holders = []; $params = ['ref' => $messageId];
            foreach ($allowed as $i => $value) { $holders[] = ':s' . $i; $params['s' . $i] = $value; }
            Database::update('wa_marketing_recipients', ['state' => $state, 'updated_at' => self::now()],
                'provider_ref = :ref AND state IN (' . implode(',', $holders) . ')', $params);
        } catch (Throwable $e) { Logger::exception($e, 'whatsapp'); }
    }

    public static function readiness(): array
    {
        $out = [];
        if (!Settings::getBool('wa_marketing_on', false)) { $out[] = 'wa_marketing_on is off.'; }
        if (!Settings::getBool('wa_agent_admin_write', false)) { $out[] = 'wa_agent_admin_write is off.'; }
        if (!Settings::getBool('whatsapp_enabled', true)) { $out[] = 'WhatsApp is disabled.'; }
        if (Settings::getString('whatsapp_driver', '') !== 'cloud_api') { $out[] = 'Marketing currently requires the Meta cloud_api driver.'; }
        require_once dirname(__DIR__) . '/whatsapp/config.php';
        if (META_ACCESS_TOKEN === '' || META_PHONE_NUMBER_ID === '' || META_WABA_ID === '') { $out[] = 'Meta access token, phone number ID and WABA ID are required.'; }
        if (!function_exists('curl_init')) { $out[] = 'PHP cURL is unavailable.'; }
        if (trim(Settings::getString('wa_marketing_templates', '')) === '') { $out[] = 'No marketing template is allowlisted.'; }
        return $out;
    }

    /** Pure validation is public so policy tests never need a provider or real account. */
    public static function validateTemplate(array $template, string $name, string $lang, array $vars): array
    {
        if (($template['name'] ?? '') !== $name || ($template['language'] ?? '') !== $lang
            || ($template['status'] ?? '') !== 'APPROVED' || ($template['category'] ?? '') !== 'MARKETING') {
            return self::error('A matching APPROVED MARKETING template is required.');
        }
        $body = ''; $footer = ''; $seen = [];
        foreach ($template['components'] ?? [] as $component) {
            $type = (string) ($component['type'] ?? '');
            if (!in_array($type, ['BODY', 'FOOTER'], true) || isset($seen[$type])) {
                return self::error('Marketing supports a text BODY and optional static FOOTER only.');
            }
            $seen[$type] = true;
            if ($type === 'BODY') { $body = (string) ($component['text'] ?? ''); }
            else { $footer = (string) ($component['text'] ?? ''); }
        }
        if ($body === '' || str_contains($footer, '{{')) { return self::error('Template BODY is missing or FOOTER contains variables.'); }
        preg_match_all('/\{\{([1-9][0-9]*)\}\}/', $body, $matches);
        $numbers = array_values(array_unique(array_map('intval', $matches[1]))); sort($numbers);
        $expected = count($vars) ? range(1, count($vars)) : [];
        if ($numbers !== $expected || preg_match('/\{\{|\}\}/', preg_replace('/\{\{[1-9][0-9]*\}\}/', '', $body) ?? '')) {
            return self::error('Supply exactly one body_vars value for each consecutive positional template variable.');
        }
        // The opt-out instruction must be part of the approved static content.
        $static = (preg_replace('/\{\{[1-9][0-9]*\}\}/', '', $body) ?? '') . ' ' . $footer;
        if (!preg_match('/\bSTOP\b/i', $static)) { return self::error('The approved template must include a STOP opt-out instruction.'); }
        $body = preg_replace_callback('/\{\{([1-9][0-9]*)\}\}/', static fn(array $m): string => (string) $vars[(int) $m[1] - 1], $body) ?? '';
        $rendered = $body . ($footer !== '' ? "\n" . $footer : '');
        if (mb_strlen($rendered) > 4096) { return self::error('Rendered template is too long.'); }
        return ['ok' => true, 'body' => $rendered, 'hash' => hash('sha256', self::json([$name, $lang, $template['components'], $vars]))];
    }

    private static function verifyTemplate(array $campaign): array
    {
        require_once dirname(__DIR__) . '/whatsapp/config.php';
        $name = (string) $campaign['template_name'];
        $allowed = preg_split('/[\s,]+/', trim(Settings::getString('wa_marketing_templates', '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!in_array($name, $allowed, true)) { return self::error('Template is not in wa_marketing_templates allowlist.'); }
        if (META_ACCESS_TOKEN === '' || META_WABA_ID === '' || !function_exists('curl_init')) { return self::error('Meta template approval cannot be verified: configuration unavailable.'); }
        $path = '/' . rawurlencode(META_WABA_ID) . '/message_templates?' . http_build_query([
            'name' => $name, 'fields' => 'name,language,status,category,components', 'limit' => 100,
        ]);
        $result = self::request('GET', $path);
        if ($result['http'] !== 200) { return self::error('Meta template approval lookup failed (HTTP ' . $result['http'] . ').'); }
        foreach ($result['data']['data'] ?? [] as $template) {
            if (($template['name'] ?? '') === $name && ($template['language'] ?? '') === $campaign['template_lang']) {
                return self::validateTemplate($template, $name, (string) $campaign['template_lang'], json_decode((string) $campaign['body_vars'], true) ?: []);
            }
        }
        return self::error('Requested template/language was not found in this Meta business account.');
    }

    private static function sendOnce(string $phone, array $campaign): array
    {
        $vars = json_decode((string) $campaign['body_vars'], true) ?: [];
        $template = ['name' => $campaign['template_name'], 'language' => ['code' => $campaign['template_lang']]];
        if ($vars !== []) { $template['components'] = [['type' => 'body', 'parameters' => array_map(static fn(string $v): array => ['type' => 'text', 'text' => $v], $vars)]]; }
        $result = self::request('POST', '/' . rawurlencode(META_PHONE_NUMBER_ID) . '/messages', [
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $phone, 'type' => 'template', 'template' => $template,
        ]);
        $id = (string) ($result['data']['messages'][0]['id'] ?? '');
        if ($result['http'] >= 200 && $result['http'] < 300 && $id !== '') { return ['state' => 'accepted', 'message_id' => $id, 'error' => '']; }
        // Timeout, invalid success response and 5xx cannot prove non-delivery.
        $state = $result['http'] >= 400 && $result['http'] < 500 ? 'failed' : 'unknown';
        return ['state' => $state, 'message_id' => '', 'error' => mb_substr('Meta HTTP ' . $result['http'] . ' ' . (string) ($result['data']['error']['message'] ?? 'Delivery outcome uncertain; do not automatically retry.'), 0, 255)];
    }

    /** No automatic retry and no caller-supplied host/URL or credentials. */
    private static function request(string $method, string $path, ?array $body = null): array
    {
        $version = preg_match('/^v[0-9]+\.[0-9]+$/D', META_API_VERSION) ? META_API_VERSION : 'v25.0';
        $ch = curl_init('https://graph.facebook.com/' . $version . $path);
        try {
            $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . META_ACCESS_TOKEN, 'Content-Type: application/json']];
            if ($method === 'POST') { $options[CURLOPT_POST] = true; $options[CURLOPT_POSTFIELDS] = self::json($body ?? []); }
            curl_setopt_array($ch, $options);
            $raw = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            return ['http' => $http, 'data' => is_array($data) ? $data : []];
        } catch (Throwable $e) { return ['http' => 0, 'data' => []]; }
        finally { curl_close($ch); }
    }

    private static function eligible(string $country): array
    {
        $params = [];
        $where = "c.state = 'opted_in'";
        if ($country !== 'all') { $where .= ' AND c.country = :country'; $params['country'] = $country; }
        $rows = Database::fetchAll('SELECT c.phone FROM wa_marketing_consents c WHERE ' . $where . ' ORDER BY c.phone', $params);
        return array_values(array_filter($rows, static fn(array $r): bool => self::internationalPhone((string) $r['phone']) !== '' && !self::blocked((string) $r['phone'])));
    }

    private static function blocked(string $phone): bool
    {
        $code = str_starts_with($phone, '977') ? '977' : '91';
        return Database::exists('SELECT id FROM users WHERE is_blocked = 1 AND (phone = :full OR (phone = :local AND country_code = :code))',
            ['full' => $phone, 'local' => substr($phone, strlen($code)), 'code' => $code]);
    }

    private static function campaign(int $id): ?array { return Database::fetch('SELECT * FROM wa_marketing_campaigns WHERE id = :id', ['id' => $id]); }
    private static function error(string $message): array { return ['ok' => false, 'error' => $message, 'sent' => false]; }
    private static function mask(string $phone): string { return '+' . substr($phone, 0, -7) . '•••••' . substr($phone, -2); }
    private static function now(): string { return date('Y-m-d H:i:s'); }
    private static function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
}
