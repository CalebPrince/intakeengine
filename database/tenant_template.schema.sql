-- tenant_template.sqlite : blank schema cloned per-tenant on registration.
-- Every business gets its own physical copy of this file at
-- storage/db/tenant_{subdomain}.sqlite — a real filesystem boundary, not a
-- shared table with a tenant_id column.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS team_members (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT NOT NULL,
    email           TEXT NOT NULL UNIQUE,
    role            TEXT NOT NULL DEFAULT 'owner', -- 'owner' | 'staff'
    created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

CREATE TABLE IF NOT EXISTS intake_forms (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT NOT NULL,
    description     TEXT,
    is_active       INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
    updated_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

CREATE TABLE IF NOT EXISTS form_fields (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    form_id         INTEGER NOT NULL REFERENCES intake_forms(id) ON DELETE CASCADE,
    label           TEXT NOT NULL,
    field_type      TEXT NOT NULL DEFAULT 'text', -- text|textarea|dropdown|file|date|email|phone
    options_json    TEXT,       -- JSON array of choices for dropdown fields
    is_required     INTEGER NOT NULL DEFAULT 1,
    order_index     INTEGER NOT NULL DEFAULT 0,
    created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

CREATE INDEX IF NOT EXISTS idx_form_fields_form ON form_fields(form_id, order_index);

CREATE TABLE IF NOT EXISTS customers (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT NOT NULL,
    email           TEXT,
    phone           TEXT,
    created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

CREATE TABLE IF NOT EXISTS bookings (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    form_id         INTEGER NOT NULL REFERENCES intake_forms(id),
    customer_id     INTEGER NOT NULL REFERENCES customers(id),
    status          TEXT NOT NULL DEFAULT 'pending', -- pending|accepted|rescheduled|cancelled|completed
    scheduled_at    TEXT,
    responses_json  TEXT NOT NULL DEFAULT '{}',
    created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
    updated_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

CREATE INDEX IF NOT EXISTS idx_bookings_status ON bookings(status);
CREATE INDEX IF NOT EXISTS idx_bookings_scheduled ON bookings(scheduled_at);
