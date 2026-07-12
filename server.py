#!/usr/bin/env python3
"""
Unified entry point / orchestrator.

    python server.py

Responsibilities (per project blueprint):
  1. Check environment requirements (PHP 8.1+, pdo_sqlite).
  2. Run idempotent database migrations (database/migrate.php).
  3. Launch PHP's built-in dev server on port 8090, routed through the
     public/index.php front controller.

Stdlib only — no pip installs.
"""

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
PUBLIC_DIR = ROOT / "public"
MIGRATE_SCRIPT = ROOT / "database" / "migrate.php"
HOST = "127.0.0.1"
PORT = 8090


def find_php() -> str:
    php = shutil.which("php")
    if not php:
        sys.exit(
            "[server.py] PHP was not found on PATH. Install PHP 8.1+ and "
            "make sure `php` is runnable from this shell, then try again."
        )
    return php


def check_requirements(php: str) -> None:
    version_output = subprocess.run(
        [php, "-r", "echo PHP_VERSION;"], capture_output=True, text=True, check=True
    ).stdout.strip()
    major, minor = (int(p) for p in version_output.split(".")[:2])
    if (major, minor) < (8, 1):
        sys.exit(f"[server.py] PHP 8.1+ required, found {version_output}.")

    modules = subprocess.run([php, "-m"], capture_output=True, text=True, check=True).stdout
    if "pdo_sqlite" not in modules:
        sys.exit("[server.py] The pdo_sqlite PHP extension is required but not enabled.")

    print(f"[server.py] PHP {version_output} OK, pdo_sqlite enabled.")


def run_migrations(php: str) -> None:
    print("[server.py] Running database migrations...")
    result = subprocess.run([php, str(MIGRATE_SCRIPT)], cwd=str(ROOT))
    if result.returncode != 0:
        sys.exit("[server.py] Migration failed, aborting startup.")


def launch_dev_server(php: str) -> None:
    print(f"[server.py] Starting PHP dev server on http://{HOST}:{PORT}")
    print(f"[server.py]   Marketing site / admin console -> http://{HOST}:{PORT}/")
    print(f"[server.py]   Tenant workspaces                -> http://<subdomain>.localhost:{PORT}/")
    print("[server.py] Press Ctrl+C to stop.\n")

    try:
        subprocess.run(
            [php, "-S", f"{HOST}:{PORT}", "-t", str(PUBLIC_DIR), str(PUBLIC_DIR / "index.php")],
            cwd=str(ROOT),
        )
    except KeyboardInterrupt:
        print("\n[server.py] Stopped.")


def main() -> None:
    php = find_php()
    check_requirements(php)
    run_migrations(php)
    launch_dev_server(php)


if __name__ == "__main__":
    main()
