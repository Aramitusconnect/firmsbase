#!/usr/bin/env bash
# =============================================================================
# SUPERSEDED — DO NOT USE. This file previously ran one-off `aws ecs
# run-task` diagnostic probes (DB connectivity, Redis TLS PING, config
# boot) before the migration and web launch. That run-task invocation has
# been removed so no shell script under staging-deploy/ can invoke
# run-task or register-task-definition outside the reviewed 00-07
# workflow — see tests/Feature/Ecs/StagingDeploymentPackageTest.php.
#
# Equivalent live verification is now covered by the approved numbered
# scripts instead of a standalone probe task:
#   - staging-deploy/03-verify-web-health.sh checks /readyz, which itself
#     exercises the firmsbase_app DB connection and the Redis connection
#     through the running web container.
#   - staging-deploy/07-final-runtime-verification.sh repeats those same
#     checks across all four runtime services.
#   - staging-deploy/migration-sequence-historical.md records that the
#     migrator-role (firmsbase_migrator) DB connectivity was already
#     proven by the completed migration itself.
#
# Historical note preserved for anyone auditing why a migrate-role
# read-only DB probe would ever need special handling: `docker/commands/
# migrate.sh` is NOT a passthrough — it unconditionally runs `php artisan
# migrate:status` then `php artisan migrate --force` regardless of any
# extra command-override arguments, so overriding the migrate task
# definition's command to anything else would still run a real migration.
# There is no safe way to probe the migrator role's DB connectivity via
# run-task without either running the real migration or dispatching to a
# different role (e.g. maintenance) that happens to share the same
# secrets — which is why this file is being retired rather than patched.
#
# This stub exits non-zero so an operator who runs it by habit is stopped
# immediately rather than silently falling back to the superseded path.
# =============================================================================
set -euo pipefail

echo "STOP: connectivity-probes.sh is superseded and must not be used." >&2
echo "Use staging-deploy/03-verify-web-health.sh and staging-deploy/07-final-runtime-verification.sh instead." >&2
exit 1
