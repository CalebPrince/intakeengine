# IntakeEngine

A zero-bloat, multi-tenant intake & booking engine for independent service
businesses — clinics, law firms, salons — built on plain PHP, SQLite, and
vanilla JavaScript. No frameworks, no bundlers, no npm/Composer install step.

Every business gets its own subdomain and its own **physically isolated**
SQLite database file. There is no shared table with a `tenant_id` column —
isolation is a real filesystem boundary, established by cloning a blank
template database at signup.

## Core principles

- **Zero dependencies.** No Node, no npm, no Composer, no bundlers. The
  frontend is static HTML + vanilla JS; the backend is plain PHP 8.1+ OOP
  with PDO.
- **Physical multi-tenancy.** `storage/db/tenant_{subdomain}.sqlite` is a
  byte-for-byte clone of a blank template, created once at registration.
- **One entry point.** `python server.py` checks the environment, runs
  idempotent migrations, and starts the PHP dev server — nothing else to
  configure.

## Tech stack

| Layer          | Choice                                              |
|----------------|------------------------------------------------------|
| Orchestration  | Python 3 (stdlib only)                              |
| Backend        | PHP 8.1+, PDO, SQLite (`pdo_sqlite`)                |
| Auth           | Hand-rolled JWT (HS256) in an HttpOnly cookie       |
| Frontend       | Static HTML, vanilla JS, Bootstrap 5 (layout utilities only) |
| Database       | SQLite — one `central.sqlite` + one file per tenant |

## Getting started

**Prerequisites:** PHP 8.1+ with `pdo_sqlite` enabled, Python 3.

```bash
python server.py
```

This will:
1. Verify PHP and the `pdo_sqlite` extension are available.
2. Run `database/migrate.php` — idempotently creates `storage/db/central.sqlite`
   and `storage/db/tenant_template.sqlite`, and seeds a default platform admin
   on first run.
3. Start the PHP built-in dev server on **http://127.0.0.1:8090**.

Tenant workspaces are reachable at `http://<subdomain>.localhost:8090` —
modern browsers resolve any `*.localhost` subdomain to `127.0.0.1`
automatically, no hosts file editing required.

### Default admin login

Seeded on first migration run (`admin_users` table in `central.sqlite`):

```
email:    admin@platform.local
password: AdminPass123!
```

Change this before using the app for anything real — it's a hardcoded dev
seed in `database/migrate.php`.

## Project structure

```
/ (project root)
  server.py                    Orchestrator entry point — `python server.py`
  .ai-blueprint.md              Architecture spec this project was built from

/database
  migrate.php                  Idempotent migration runner
  central.schema.sql           Schema for the global control database
  tenant_template.schema.sql   Blank schema cloned per tenant at signup

/src
  Router.php                   Minimal regex route table
  Controllers/
    AuthController.php         Registration, login, logout, session check
    TenantController.php       Workspace overview analytics
    FormController.php         Dynamic intake form builder (CRUD)
    BookingController.php      Public booking submission + tenant registry
    AdminController.php        Platform-wide metrics, tenant management
    ContactController.php      Marketing site contact form
  Support/
    Database.php               Dynamic connection handler (subdomain → PDO)
    Auth.php                   JWT cookie issuing/verification, session guards
    Jwt.php                    Hand-rolled HS256 JWT encode/decode
    Response.php                JSON response helper

/public                        Web root — served by the PHP dev server
  index.php                    Front controller for /api/v1/* routes
  index.html                   Marketing landing page
  about.html / contact.html    Marketing pages
  register.html / login.html   Tenant auth
  dashboard.html               Tenant workspace (overview, forms, registry)
  book.html                    Public booking form (per tenant subdomain)
  admin.html                   Platform admin console
  css/theme.css                Light/dark theme variables
  js/                          api.js (fetch wrapper), theme.js, page scripts

/storage
  db/                          SQLite files (gitignored — regenerated locally)
  app.key                      JWT signing secret (gitignored, auto-generated)
  logs/                        Runtime logs (gitignored)

/scripts
  free-port.ps1 / free-port.sh Kill whatever process is bound to a given port
```

## How multi-tenancy works

1. **Registration** (`AuthController::register`) creates a row in
   `central.sqlite`'s `tenants` table, then physically `copy()`s
   `tenant_template.sqlite` to `storage/db/tenant_{subdomain}.sqlite`.
2. **Every request** is bound to exactly one database at runtime.
   `Database::currentSubdomain()` reads the active `Host` header
   (`accraclinic.localhost:8090` → `accraclinic`) and
   `Database::forSubdomain()` opens that tenant's file — never anyone
   else's.
3. **Session binding.** The JWT issued at login encodes the tenant's
   subdomain as a claim. `Auth::requireTenant()` checks that claim against
   the subdomain the request actually arrived on, so a tenant's cookie
   can't be replayed against a different workspace.

## Features

- Self-serve tenant registration with instant workspace provisioning
- Subdomain-scoped login (HttpOnly JWT cookie)
- Dynamic intake form builder — text, textarea, dropdown, file, date, email,
  phone fields; subscription-tier field-count quotas
- Public, unauthenticated booking page that renders a tenant's live form
- Live intake registry — accept / reschedule / cancel, full response detail
- Platform admin console — MRR, disk usage, tenant suspend/tier changes,
  runtime + webhook logs, contact form submissions
- Light/dark theme toggle, persisted per-origin in `localStorage`

## Utility scripts

Free up a port that's stuck in use (e.g. an orphaned dev server process):

```powershell
.\scripts\free-port.ps1 -Port 8090
```

```bash
./scripts/free-port.sh 8090
```
