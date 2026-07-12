<?php
/**
 * Idempotent migration runner.
 * Invoked by server.py on every boot. Safe to run any number of times:
 * schemas use CREATE TABLE IF NOT EXISTS / INSERT OR IGNORE throughout.
 */
declare(strict_types=1);

$root      = dirname(__DIR__);
$dbDir     = $root . '/storage/db';
$logDir    = $root . '/storage/logs';

if (!is_dir($dbDir)) {
    mkdir($dbDir, 0777, true);
}
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

function applySchema(string $sqlitePath, string $schemaPath): void
{
    $pdo = new PDO('sqlite:' . $sqlitePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $sql = file_get_contents($schemaPath);
    if ($sql === false) {
        throw new RuntimeException("Cannot read schema: {$schemaPath}");
    }
    $pdo->exec($sql);
}

// 1. Central control database — tenants, tiers, admins, logs.
applySchema($dbDir . '/central.sqlite', __DIR__ . '/central.schema.sql');

// Seed a default platform super-admin on first run only.
$central = new PDO('sqlite:' . $dbDir . '/central.sqlite');
$central->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$adminCount = (int) $central->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
if ($adminCount === 0) {
    $defaultPassword = 'AdminPass123!';
    $central->prepare('INSERT INTO admin_users (email, password_hash) VALUES (?, ?)')
        ->execute(['admin@platform.local', password_hash($defaultPassword, PASSWORD_BCRYPT)]);
    fwrite(STDOUT, "[migrate] seeded default admin -> admin@platform.local / {$defaultPassword} (change this)\n");
}

// 2. Blank clonable template — every new tenant gets a byte-for-byte copy.
applySchema($dbDir . '/tenant_template.sqlite', __DIR__ . '/tenant_template.schema.sql');

fwrite(STDOUT, "[migrate] central.sqlite and tenant_template.sqlite are up to date.\n");
