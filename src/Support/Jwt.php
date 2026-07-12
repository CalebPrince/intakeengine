<?php

declare(strict_types=1);

namespace Support;

use RuntimeException;

/**
 * Hand-rolled HS256 JWT — no external libraries, per project rules.
 */
final class Jwt
{
    private static function secret(): string
    {
        $keyFile = dirname(__DIR__, 2) . '/storage/app.key';
        if (!is_file($keyFile)) {
            file_put_contents($keyFile, bin2hex(random_bytes(32)));
        }

        return (string) file_get_contents($keyFile);
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): string
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4), '=');

        return (string) base64_decode($padded, true);
    }

    /** @param array<string, mixed> $claims */
    public static function encode(array $claims, int $ttlSeconds = 60 * 60 * 24 * 7): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $claims['iat'] = time();
        $claims['exp'] = time() + $ttlSeconds;

        $segments = [
            self::b64url(json_encode($header, JSON_THROW_ON_ERROR)),
            self::b64url(json_encode($claims, JSON_THROW_ON_ERROR)),
        ];

        $signature = hash_hmac('sha256', implode('.', $segments), self::secret(), true);
        $segments[] = self::b64url($signature);

        return implode('.', $segments);
    }

    /**
     * @return array<string, mixed>
     * @throws RuntimeException on invalid/expired token
     */
    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed token.');
        }
        [$headerB64, $payloadB64, $sigB64] = $parts;

        $expected = self::b64url(hash_hmac('sha256', "{$headerB64}.{$payloadB64}", self::secret(), true));
        if (!hash_equals($expected, $sigB64)) {
            throw new RuntimeException('Invalid signature.');
        }

        $claims = json_decode(self::b64urlDecode($payloadB64), true);
        if (!is_array($claims)) {
            throw new RuntimeException('Invalid payload.');
        }
        if (($claims['exp'] ?? 0) < time()) {
            throw new RuntimeException('Token expired.');
        }

        return $claims;
    }
}
