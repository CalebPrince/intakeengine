#!/usr/bin/env bash
# Kills whatever process is listening on a given local TCP port (Windows via Git Bash).
#
# Usage: ./scripts/free-port.sh 8010

set -euo pipefail

port="${1:-}"
if [ -z "$port" ]; then
    echo "Usage: $0 <port>"
    exit 1
fi

pids=$(netstat -ano | grep ":${port} " | grep LISTENING | awk '{print $5}' | sort -u)

if [ -z "$pids" ]; then
    echo "Nothing is listening on port ${port}."
    exit 0
fi

for pid in $pids; do
    echo "Killing PID ${pid} on port ${port}"
    taskkill //F //PID "$pid" || true
done

echo "Port ${port} is free."
