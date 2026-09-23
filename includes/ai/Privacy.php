<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit; }

final class AiPrivacy
{
    public static function redact(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[email]', $text) ?? '';
        $text = preg_replace('/\bSHG[-\s][A-Z0-9-]+/iu', '[booking]', $text) ?? '';
        $text = preg_replace('/(?<!\w)\+?\d[\d\s().-]{7,}\d(?!\w)/u', '[number]', $text) ?? '';
        $text = preg_replace('/\b(?:sk-|AIza|Bearer\s+)[A-Za-z0-9_\-]{10,}/u', '[secret]', $text) ?? '';
        $text = preg_replace('/\b(password|token|secret|api[_ ]?key|utr|aadhaar|pan)\s*[:=]\s*\S+/iu', '$1=[redacted]', $text) ?? '';
        return mb_substr($text, 0, 3000);
    }

    public static function seal(array $value): string
    {
        $iv = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'aes-256-gcm', hash('sha256', APP_KEY . ':ai-manager-v1', true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) { throw new RuntimeException('Private storage unavailable.'); }
        return base64_encode($iv . $tag . $encrypted);
    }

    public static function open(?string $value): array
    {
        if (!$value) { return []; }
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 29) { throw new RuntimeException('Private storage invalid.'); }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', APP_KEY . ':ai-manager-v1', true),
            OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) { throw new RuntimeException('Private storage invalid.'); }
        return json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
    }

    public static function owner(string $kind, string $id): string
    {
        return hash_hmac('sha256', $kind . ':' . $id, APP_KEY);
    }

    public static function audit(string $event, string $entity, string $id, array $ctx, array $safe, string $key): void
    {
        Database::run('INSERT IGNORE INTO domain_events
            (event_id,event_type,entity_type,entity_id,actor_type,actor_id,channel,idempotency_key,safe_payload,processed_at)
            VALUES (:uuid,:event,:entity,:id,:actor,:aid,:channel,:key,:payload,NOW())', [
            'uuid'=>bin2hex(random_bytes(16)), 'event'=>$event, 'entity'=>$entity, 'id'=>$id,
            'actor'=>$ctx['role'], 'aid'=>$ctx['actor_id'] ?: null, 'channel'=>$ctx['channel'],
            'key'=>hash('sha256',$key), 'payload'=>json_encode($safe,JSON_THROW_ON_ERROR)]);
    }
}
