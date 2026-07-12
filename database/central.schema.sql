-- central.sqlite : global system control database
-- Owns tenant identity, billing tier, platform admins, and cross-tenant telemetry.
-- This database NEVER stores client/booking data — that lives only inside each
-- tenant's own physically isolated storage/db/tenant_{subdomain}.sqlite file.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS subscription_tiers (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    code            TEXT NOT NULL UNIQUE,      -- 'starter' | 'pro' | 'enterprise'
    name            TEXT NOT NULL,
    price_cents     INTEGER NOT NULL DEFAULT 0,
    max_team_members INTEGER NOT NULL DEFAULT 1,   -- -1 = unlimited
    max_custom_fields INTEGER NOT NULL DEFAULT 5,  -- -1 = unlimited
    custom_branding INTEGER NOT NULL DEFAULT 0,    -- 0/1
    webhooks_enabled INTEGER NOT NULL DEFAULT 0    -- 0/1
);

CREATE TABLE IF NOT EXISTS tenants (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    subdomain       TEXT NOT NULL UNIQUE,
    business_name   TEXT NOT NULL,
    owner_email     TEXT NOT NULL UNIQUE,
    password_hash   TEXT NOT NULL,
    tier_id         INTEGER NOT NULL DEFAULT 1 REFERENCES subscription_tiers(id),
    status          TEXT NOT NULL DEFAULT 'active', -- 'active' | 'suspended'
    db_path         TEXT NOT NULL,
    created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
    updated_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

CREATE INDEX IF NOT EXISTS idx_tenants_subdomain ON tenants(subdomain);
CREATE INDEX IF NOT EXISTS idx_tenants_status ON tenants(status);

CREATE TABLE IF NOT EXISTS admin_users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    email           TEXT NOT NULL UNIQUE,
    password_hash   TEXT NOT NULL,
    created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

CREATE TABLE IF NOT EXISTS webhook_logs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id       INTEGER NOT NULL REFERENCES tenants(id),
    event_type      TEXT NOT NULL,
    payload_bytes   INTEGER NOT NULL DEFAULT 0,
    status_code     INTEGER NOT NULL DEFAULT 0,
    created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

CREATE INDEX IF NOT EXISTS idx_webhook_logs_tenant ON webhook_logs(tenant_id);

CREATE TABLE IF NOT EXISTS system_logs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    level           TEXT NOT NULL DEFAULT 'info', -- 'info' | 'warn' | 'error'
    message         TEXT NOT NULL,
    context_json    TEXT,
    created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

-- Public contact-form submissions from the marketing site (not tenant data).
CREATE TABLE IF NOT EXISTS contact_messages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT NOT NULL,
    email           TEXT NOT NULL,
    subject         TEXT,
    message         TEXT NOT NULL,
    created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

CREATE INDEX IF NOT EXISTS idx_contact_messages_created ON contact_messages(created_at);

-- Seed the three subscription tiers (idempotent).
INSERT OR IGNORE INTO subscription_tiers
    (id, code, name, price_cents, max_team_members, max_custom_fields, custom_branding, webhooks_enabled)
VALUES
    (1, 'starter',    'Starter',    0,    1,  5, 0, 0),
    (2, 'pro',        'Pro',        4900, -1, -1, 1, 1),
    (3, 'enterprise', 'Enterprise', 19900, -1, -1, 1, 1);
