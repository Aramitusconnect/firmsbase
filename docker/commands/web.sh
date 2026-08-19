#!/bin/bash
# ECS "web" role. Single foreground process (FrankenPHP), execed directly so
# it is PID 1 and receives SIGTERM straight from ECS/Docker. See
# docs/ecs/graceful-shutdown.md for the drain-timeout budget.
set -euo pipefail

fail() {
  echo "[web] FATAL: $*" >&2
  exit 1
}

if [[ -z "${MARKETING_URL:-}" ]]; then
  fail "MARKETING_URL is required for the web role"
fi

if ! healthcheck_host="$(/usr/local/bin/php -r '
$url = getenv("MARKETING_URL");

if (! is_string($url) || $url === "") {
    exit(2);
}

$host = parse_url($url, PHP_URL_HOST);

if (
    ! is_string($host)
    || $host === ""
    || preg_match("/[\s\/\\\\:@]/", $host) === 1
    || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
) {
    exit(3);
}

echo strtolower($host);
')" || [[ -z "$healthcheck_host" ]]; then
  fail "MARKETING_URL must be a valid URL with a hostname for the web role"
fi

export FIRMSVAULT_ALB_HEALTHCHECK_HOST="$healthcheck_host"

cd /var/www/html

exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile
