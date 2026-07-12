<?php

declare(strict_types=1);

namespace Controllers;

use Support\Auth;
use Support\Database;
use Support\Response;

/**
 * Global command station — the only place in the codebase permitted to see
 * across every tenant at once. Everything here reads storage/db/central.sqlite;
 * it never opens a tenant_*.sqlite file directly except to stat() its size.
 */
final class AdminController
{
    /** @return array<string, mixed> */
    private function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    private function diskUsageBytes(): int
    {
        $total = 0;
        foreach (glob(Database::dbDir() . '/tenant_*.sqlite') ?: [] as $file) {
            if (basename($file) === 'tenant_template.sqlite') {
                continue;
            }
            $total += filesize($file) ?: 0;
        }

        return $total;
    }

    public function metrics(): void
    {
        Auth::requireAdmin();
        $central = Database::central();

        $totalTenants = (int) $central->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
        $activeTenants = (int) $central->query("SELECT COUNT(*) FROM tenants WHERE status = 'active'")->fetchColumn();
        $suspendedTenants = $totalTenants - $activeTenants;

        $mrrCents = (int) $central->query(
            "SELECT COALESCE(SUM(subscription_tiers.price_cents), 0)
             FROM tenants JOIN subscription_tiers ON subscription_tiers.id = tenants.tier_id
             WHERE tenants.status = 'active'"
        )->fetchColumn();

        $webhookRow = $central->query(
            'SELECT COUNT(*) AS cnt, COALESCE(SUM(payload_bytes), 0) AS bytes FROM webhook_logs'
        )->fetch();

        Response::json([
            'total_tenants' => $totalTenants,
            'active_tenants' => $activeTenants,
            'suspended_tenants' => $suspendedTenants,
            'mrr_cents' => $mrrCents,
            'mrr_dollars' => round($mrrCents / 100, 2),
            'disk_usage_bytes' => $this->diskUsageBytes(),
            'webhook_events_total' => (int) $webhookRow['cnt'],
            'webhook_payload_bytes_total' => (int) $webhookRow['bytes'],
        ]);
    }

    public function tenants(): void
    {
        Auth::requireAdmin();
        $central = Database::central();

        $rows = $central->query(
            'SELECT tenants.id, tenants.subdomain, tenants.business_name, tenants.owner_email,
                    tenants.status, tenants.created_at, subscription_tiers.code AS tier_code,
                    subscription_tiers.name AS tier_name
             FROM tenants JOIN subscription_tiers ON subscription_tiers.id = tenants.tier_id
             ORDER BY tenants.created_at DESC'
        )->fetchAll();

        foreach ($rows as &$row) {
            $path = Database::tenantDbPath($row['subdomain']);
            $row['disk_usage_bytes'] = is_file($path) ? filesize($path) : 0;
        }

        Response::json(['tenants' => $rows]);
    }

    public function updateTenant(string $id): void
    {
        Auth::requireAdmin();
        $central = Database::central();
        $body = $this->body();

        $stmt = $central->prepare('SELECT * FROM tenants WHERE id = ?');
        $stmt->execute([$id]);
        $tenant = $stmt->fetch();
        if (!$tenant) {
            Response::error('Tenant not found.', 404);
        }

        $status = null;
        if (isset($body['status'])) {
            if (!in_array($body['status'], ['active', 'suspended'], true)) {
                Response::error('status must be active or suspended.', 422);
            }
            $status = $body['status'];
        }

        $tierId = null;
        if (isset($body['tier_code'])) {
            $tierStmt = $central->prepare('SELECT id FROM subscription_tiers WHERE code = ?');
            $tierStmt->execute([$body['tier_code']]);
            $tierId = $tierStmt->fetchColumn();
            if ($tierId === false) {
                Response::error('Unknown tier_code.', 422);
            }
        }

        $central->prepare(
            "UPDATE tenants SET
                status = COALESCE(?, status),
                tier_id = COALESCE(?, tier_id),
                updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
             WHERE id = ?"
        )->execute([$status, $tierId ?: null, $id]);

        Response::json(['ok' => true]);
    }

    public function logs(): void
    {
        Auth::requireAdmin();
        $central = Database::central();

        $logs = $central->query('SELECT * FROM system_logs ORDER BY created_at DESC LIMIT 100')->fetchAll();
        $webhooks = $central->query(
            'SELECT webhook_logs.*, tenants.subdomain FROM webhook_logs
             JOIN tenants ON tenants.id = webhook_logs.tenant_id
             ORDER BY webhook_logs.created_at DESC LIMIT 100'
        )->fetchAll();

        Response::json(['system_logs' => $logs, 'webhook_logs' => $webhooks]);
    }
}
