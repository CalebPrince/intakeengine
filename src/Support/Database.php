<?php

declare(strict_types=1);

namespace Support;

use PDO;
use RuntimeException;

/**
 * Dynamic connection handler.
 *
 * Every incoming request is bound to exactly one PDO handle, chosen at
 * runtime by inspecting the active Host header. There is no shared
 * "tenant_id" column anywhere — isolation is a physical file boundary:
 * storage/db/tenant_{subdomain}.sqlite.
 */
final class Database
{
    /** @var array<string, PDO> */
    private static array $handles = [];

    public static function dbDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/db';
    }

    private static function connect(string $path): PDO
    {
        if (isset(self::$handles[$path])) {
            return self::$handles[$path];
        }

        if (!is_file($path)) {
            throw new RuntimeException("Database not found: {$path}");
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON;');

        return self::$handles[$path] = $pdo;
    }

    /** Global system control database. */
    public static function central(): PDO
    {
        return self::connect(self::dbDir() . '/central.sqlite');
    }

    /** Physically isolated workspace database for one tenant. */
    public static function forSubdomain(string $subdomain): PDO
    {
        $subdomain = self::sanitizeSubdomain($subdomain);

        return self::connect(self::dbDir() . "/tenant_{$subdomain}.sqlite");
    }

    public static function tenantDbPath(string $subdomain): string
    {
        $subdomain = self::sanitizeSubdomain($subdomain);

        return self::dbDir() . "/tenant_{$subdomain}.sqlite";
    }

    public static function tenantDbExists(string $subdomain): bool
    {
        return is_file(self::tenantDbPath($subdomain));
    }

    public static function sanitizeSubdomain(string $subdomain): string
    {
        $subdomain = strtolower(trim($subdomain));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $subdomain)) {
            throw new RuntimeException('Invalid subdomain format.');
        }

        return $subdomain;
    }

    /**
     * Detects the tenant subdomain the current request is targeting by
     * reading the active Host header, e.g. "accraclinic.localhost:8090"
     * -> "accraclinic". Returns null for the bare apex host (the public
     * marketing site / admin console live there, not a tenant workspace).
     */
    public static function currentSubdomain(): ?string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $host = strtolower(explode(':', $host)[0]);

        $apex = ['localhost', '127.0.0.1', 'princecaleb.dev'];
        $parts = explode('.', $host);

        // "accraclinic.localhost" -> 2 labels, first one is the tenant.
        if (count($parts) >= 2 && in_array(implode('.', array_slice($parts, 1)), $apex, true)) {
            return $parts[0];
        }

        // Local dev convenience only: explicit override when hitting the
        // bare apex host directly (e.g. via curl without wildcard DNS).
        if (in_array($host, $apex, true) && isset($_GET['tenant'])) {
            return (string) $_GET['tenant'];
        }

        return null;
    }
}
