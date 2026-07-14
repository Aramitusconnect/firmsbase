#!/bin/bash
# ECS "web" role. Single foreground process (FrankenPHP), execed directly so
# it is PID 1 and receives SIGTERM straight from ECS/Docker. See
# docs/ecs/graceful-shutdown.md for the drain-timeout budget.
set -euo pipefail
cd /var/www/html

exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile
