#!/usr/bin/env bash
# ECS "maintenance" role — a dedicated ONE-OFF ECS task (RunTask) for ad hoc
# operational Artisan commands (e.g. `queue:prune-failed`, `cache:clear`,
# `tinker` for a supervised one-off script, a future backup/restore drill
# command). The ECS task's `command` array supplies the actual Artisan
# subcommand and arguments after the role name, e.g.:
#   ["maintenance", "queue:prune-failed", "--hours=24"]
#
# Deliberately a thin passthrough — this script does not maintain an
# allowlist of "safe" commands. The safety boundary is which humans/pipeline
# steps are authorized to invoke ECS RunTask with this task definition and
# IAM role (see docs/ecs/iam-matrix.md), not a check inside the container.
set -euo pipefail
cd /var/www/html

if [[ $# -eq 0 ]]; then
  echo "[maintenance] FATAL: no artisan subcommand given — usage: maintenance <artisan-subcommand> [args...]" >&2
  exit 1
fi

echo "[maintenance] running: php artisan $*" >&2
exec php artisan "$@"
