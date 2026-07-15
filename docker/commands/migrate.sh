#!/bin/bash
# ECS "migrate" role — a dedicated ONE-OFF ECS task (RunTask, never a
# service), never invoked as a side effect of web/worker/scheduler startup.
# See docs/ecs/database-migrations.md for the full migration runbook,
# including the expand-contract discipline this task assumes callers follow
# and its interaction with the RLS rollout.
set -euo pipefail
cd /var/www/html

echo "[migrate] pre-migration status:" >&2
php artisan migrate:status --no-interaction || true

echo "[migrate] running: php artisan migrate --force" >&2
# --force is required outside local/testing envs; intentionally not run with
# --isolated (Laravel's own cross-process migration lock) disabled — the
# lock protects against two migration tasks accidentally racing, which is a
# real possibility during a deploy if a caller mis-triggers this task twice.
exec php artisan migrate --force --no-interaction
