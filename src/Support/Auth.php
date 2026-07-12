<?php

declare(strict_types=1);

namespace Support;

use RuntimeException;

/**
 * Session/claims helpers built on the HttpOnly JWT cookie set at login.
 */
final class Auth
{
    private const COOKIE_NAME = 'auth_token';

    public static function issueCookie(string $token): void
    {
        setcookie(self::COOKIE_NAME, $token, [
            'expires'  => time() + 60 * 60 * 24 * 7,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            // 'secure' left off for local http:// dev; set true behind TLS in production.
        ]);
    }

    public static function clearCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', ['expires' => time() - 3600, 'path' => '/']);
    }

    /** @return array<string, mixed>|null */
    public static function claims(): ?array
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!$token) {
            return null;
        }
        try {
            return Jwt::decode($token);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Requires a valid tenant-owner session whose token subdomain matches
     * the subdomain the request actually arrived on — this is what stops
     * one business's logged-in cookie from reading another tenant's data
     * even if both live on the same shared server process.
     *
     * @return array<string, mixed>
     */
    public static function requireTenant(): array
    {
        $claims = self::claims();
        $activeSubdomain = Database::currentSubdomain();

        if (!$claims || ($claims['type'] ?? null) !== 'tenant') {
            Response::error('Not authenticated.', 401);
        }
        if (!$activeSubdomain || ($claims['subdomain'] ?? null) !== $activeSubdomain) {
            Response::error('Session does not match this workspace.', 403);
        }

        return $claims;
    }

    /** @return array<string, mixed> */
    public static function requireAdmin(): array
    {
        $claims = self::claims();
        if (!$claims || ($claims['type'] ?? null) !== 'admin') {
            Response::error('Admin authentication required.', 401);
        }

        return $claims;
    }
}
