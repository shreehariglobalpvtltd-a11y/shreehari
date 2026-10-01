<?php
/** Exercise the request endpoint with sender results, without network or DB. */
declare(strict_types=1);
class Security {
    public static function requireCsrf(): void {}
    public static function clientIp(): string { return '127.0.0.1'; }
    public static function rateLimit(...$args): bool { return true; }
    public static function clean(string $s, int $limit): string { return mb_substr($s, 0, $limit); }
}
class Response {
    public static array $result = [];
    public static function field(string $key, $default = null) { return ['name'=>'Test Passenger','phone'=>'9000000001'][$key] ?? $default; }
    public static function success(array $data, string $message): void { self::$result = $data; }
    public static function serverError(Throwable $e): void { throw $e; }
}
class Settings {
    public static function officePhone(): string { return '9000000002'; }
    public static function getString(string $key, string $default): string { return $default; }
}
class Notify {
    public static bool|string $next = false;
    public static function whatsapp(...$args): bool|string { return self::$next; }
}
class Logger {
    public static function audit(...$args): void {}
}
function normalisePhone(string $s): string { return $s; }
$source = file_get_contents(dirname(__DIR__) . '/api/wa-request.php');
$source = str_replace("require __DIR__ . '/_init.php';", '', substr($source, 5));
foreach ([['result'=>true,'sent'=>true], ['result'=>false,'sent'=>false], ['result'=>'https://wa.me/9000000002','sent'=>false]] as $case) {
    Notify::$next = $case['result'];
    eval($source);
    if ((Response::$result['sent'] ?? null) !== $case['sent']) { throw new RuntimeException('Incorrect send status'); }
}
echo "3 sender-result checks passed\n";
