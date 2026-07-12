<?php

declare(strict_types=1);

namespace Controllers;

use PDO;
use RuntimeException;
use Support\Auth;
use Support\Database;
use Support\Jwt;
use Support\Response;

final class AuthController
{
    /** @return array<string, mixed> */
    private function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Onboarding pipeline: creates the central account entry, then
     * physically clones the blank tenant_template.sqlite into a brand new
     * storage/db/tenant_{subdomain}.sqlite — an unbreachable disk-level
     * boundary for this business's data, separate from every other tenant.
     */
    public function register(): void
    {
        $body = $this->body();
        $businessName = trim((string) ($body['business_name'] ?? ''));
        $ownerEmail   = strtolower(trim((string) ($body['owner_email'] ?? '')));
        $password     = (string) ($body['password'] ?? '');
        $subdomainRaw = (string) ($body['subdomain'] ?? '');

        if ($businessName === '' || $ownerEmail === '' || $subdomainRaw === '') {
            Response::error('business_name, owner_email and subdomain are required.', 422);
        }
        if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email address.', 422);
        }
        if (strlen($password) < 8) {
            Response::error('Password must be at least 8 characters.', 422);
        }

        try {
            $subdomain = Database::sanitizeSubdomain($subdomainRaw);
        } catch (RuntimeException) {
            Response::error('Subdomain may only contain lowercase letters, numbers and hyphens (3-63 chars).', 422);
        }

        $reserved = ['www', 'api', 'admin', 'app', 'central', 'localhost'];
        if (in_array($subdomain, $reserved, true)) {
            Response::error('That subdomain is reserved.', 422);
        }

        $central = Database::central();

        $exists = $central->prepare('SELECT id FROM tenants WHERE subdomain = ? OR owner_email = ?');
        $exists->execute([$subdomain, $ownerEmail]);
        if ($exists->fetch()) {
            Response::error('That subdomain or email is already registered.', 409);
        }

        if (Database::tenantDbExists($subdomain)) {
            Response::error('That subdomain is already in use.', 409);
        }

        $dbPath = Database::tenantDbPath($subdomain);
        $templatePath = Database::dbDir() . '/tenant_template.sqlite';

        if (!copy($templatePath, $dbPath)) {
            Response::error('Failed to provision the tenant workspace.', 500);
        }

        // Seed the owner as the first team member inside their own workspace.
        $tenantPdo = Database::forSubdomain($subdomain);
        $tenantPdo->prepare('INSERT INTO team_members (name, email, role) VALUES (?, ?, ?)')
            ->execute([$businessName . ' Owner', $ownerEmail, 'owner']);

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $central->prepare(
            'INSERT INTO tenants (subdomain, business_name, owner_email, password_hash, tier_id, status, db_path)
             VALUES (?, ?, ?, ?, 1, ?, ?)'
        );
        $stmt->execute([$subdomain, $businessName, $ownerEmail, $passwordHash, 'active', $dbPath]);

        Response::json([
            'ok' => true,
            'subdomain' => $subdomain,
            'workspace_hint' => "Sign in at http://{$subdomain}.localhost:8090/login.html",
        ], 201);
    }

    /**
     * Tenant login must occur on the business's own subdomain — the active
     * Host header is what Database::currentSubdomain() resolves, and the
     * issued JWT is bound to that exact subdomain.
     */
    public function login(): void
    {
        $subdomain = Database::currentSubdomain();
        if (!$subdomain) {
            Response::error('Sign in from your workspace subdomain, e.g. yourbusiness.localhost:8090', 400);
        }

        $body = $this->body();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        $central = Database::central();
        $stmt = $central->prepare('SELECT * FROM tenants WHERE subdomain = ? AND owner_email = ?');
        $stmt->execute([$subdomain, $email]);
        $tenant = $stmt->fetch();

        if (!$tenant || !password_verify($password, $tenant['password_hash'])) {
            Response::error('Invalid credentials.', 401);
        }
        if ($tenant['status'] !== 'active') {
            Response::error('This workspace has been suspended. Contact support.', 403);
        }

        $token = Jwt::encode([
            'type' => 'tenant',
            'tenant_id' => (int) $tenant['id'],
            'subdomain' => $tenant['subdomain'],
            'email' => $tenant['owner_email'],
        ]);
        Auth::issueCookie($token);

        Response::json([
            'ok' => true,
            'business_name' => $tenant['business_name'],
            'subdomain' => $tenant['subdomain'],
        ]);
    }

    public function adminLogin(): void
    {
        $body = $this->body();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        $central = Database::central();
        $stmt = $central->prepare('SELECT * FROM admin_users WHERE email = ?');
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            Response::error('Invalid admin credentials.', 401);
        }

        $token = Jwt::encode([
            'type' => 'admin',
            'admin_id' => (int) $admin['id'],
            'email' => $admin['email'],
        ]);
        Auth::issueCookie($token);

        Response::json(['ok' => true, 'email' => $admin['email']]);
    }

    public function logout(): void
    {
        Auth::clearCookie();
        Response::json(['ok' => true]);
    }

    public function me(): void
    {
        $claims = Auth::claims();
        if (!$claims) {
            Response::json(['authenticated' => false]);
        }
        Response::json(['authenticated' => true] + $claims);
    }
}
