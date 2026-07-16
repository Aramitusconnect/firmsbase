#!/usr/bin/env bash
# NOT EXECUTED. Review-only. Run in CloudShell, region us-east-1, account
# 603013471426. These are one-off `run-task` diagnostic probes, run BEFORE
# migration-sequence.sh (probes 1 and 3), and before/after web launch
# (probe 5). None of these mutate application data. None print secret
# values, dump the process environment, or call
# `secretsmanager get-secret-value`. Each probe stops on first
# auth/TLS/certificate failure rather than retrying.
set -euo pipefail

CLUSTER=firmsbase-staging-cluster
SUBNETS=subnet-07efcb5d4bcf5aa59,subnet-020540b8377bb4d0e
SG=sg-0db14e50ea5c5466c
LOG_GROUP=/ecs/firmsbase-staging/app

run_probe_task() {
  local family="$1" cmd_json="$2" stream_prefix="$3"
  local out task_arn task_id
  out=$(aws ecs run-task \
    --cluster "$CLUSTER" \
    --launch-type FARGATE \
    --platform-version LATEST \
    --task-definition "$family" \
    --overrides "$cmd_json" \
    --network-configuration "awsvpcConfiguration={subnets=[$SUBNETS],securityGroups=[$SG],assignPublicIp=ENABLED}" \
    --count 1)
  echo "$out"
  task_arn=$(echo "$out" | jq -r '.tasks[0].taskArn')
  [ -n "$task_arn" ] && [ "$task_arn" != "null" ] || { echo "FAILED TO START PROBE TASK" >&2; exit 1; }
  aws ecs wait tasks-stopped --cluster "$CLUSTER" --tasks "$task_arn"
  local describe exit_code
  describe=$(aws ecs describe-tasks --cluster "$CLUSTER" --tasks "$task_arn")
  echo "$describe"
  exit_code=$(echo "$describe" | jq -r '.tasks[0].containers[0].exitCode')
  task_id=$(echo "$task_arn" | awk -F/ '{print $NF}')
  aws logs tail "$LOG_GROUP" --log-stream-names "${stream_prefix}/app/${task_id}" --since 15m
  if [ "$exit_code" != "0" ]; then
    echo "PROBE FAILED (family=$family, exitCode=$exit_code). STOP." >&2
    exit 1
  fi
}

# =============================================================================
# Probe 1: runtime DB connectivity (firmsbase_app), using the maintenance
# task def (already carries the runtime database secret + APP_KEY + Redis
# config) so no new task definition is needed. Uses `tinker` to run a
# read-only query through the application's actual DB connection path
# (config/database.php's pgsql connection, populated by DB_HOST/PORT/
# DATABASE/USERNAME/PASSWORD from the database-app secret's JSON keys).
# Confirms connectivity WITHOUT confirming or exercising migration
# privileges (SELECT only, no schema access, no writes).
# =============================================================================
# NOTE ON ALL OVERRIDES BELOW: ECS `containerOverrides[].command` REPLACES
# the entire base command array — it does not append to it. `docker/entrypoint.sh`
# dispatches on argv[0] as the "role" (`docker/commands/${role}.sh`), so every
# override below must start with a role name, not a bare artisan subcommand.
echo "=== Probe 1: firmsbase_app DB connectivity (read-only) ==="
run_probe_task firmsbase-staging-maintenance \
  '{"containerOverrides":[{"name":"app","command":["maintenance","tinker","--execute=echo DB::connection()->getPdo() ? \"DB_OK firmsbase_app connected, server_version=\" . DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION) : \"DB_FAIL\";"]}]}' \
  maintenance

# =============================================================================
# Probe 2: migrator DB connectivity (firmsbase_migrator) — read-only.
#
# IMPORTANT: `docker/commands/migrate.sh` is NOT a passthrough — it
# unconditionally runs `php artisan migrate:status` then
# `php artisan migrate --force`, ignoring any extra args. Overriding the
# migrate task definition's command to `["migrate", "tinker", ...]` would
# NOT skip the real migration — migrate.sh would still run it. To get a
# read-only check using the migrator role's credentials, this probe runs
# the `firmsbase-staging-migrate` task DEFINITION (so the container gets
# the migrator DB env/secret) but overrides the command's role to
# `maintenance` instead, which dispatches to `docker/commands/maintenance.sh`
# — a genuine thin passthrough — instead of migrate.sh. This never invokes
# `php artisan migrate`.
# =============================================================================
echo "=== Probe 2: firmsbase_migrator DB connectivity (read-only) ==="
run_probe_task firmsbase-staging-migrate \
  '{"containerOverrides":[{"name":"app","command":["maintenance","tinker","--execute=echo DB::connection()->getPdo() ? \"DB_OK firmsbase_migrator connected, server_version=\" . DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION) : \"DB_FAIL\";"]}]}' \
  migrate
# stream_prefix stays "migrate" (not "maintenance") — logConfiguration is
# fixed by the task definition and is NOT affected by containerOverrides.

# =============================================================================
# Probe 3: Redis TLS authentication + PING, through the application's own
# Redis client path (Illuminate\Support\Facades\Redis, using the 'default'
# connection populated by REDIS_HOST=tls://... + REDIS_PASSWORD from
# Secrets Manager). A successful response proves: TLS handshake succeeded,
# certificate/hostname validation succeeded (PHP's SSL stream defaults —
# no context override exists anywhere in this repo to disable them), and
# AUTH succeeded using the Secrets-Manager-injected token. Prints only the
# PING response and the connection's negotiated TLS status if available
# from phpredis — never the token itself.
# =============================================================================
echo "=== Probe 3: Redis TLS connectivity + PING ==="
run_probe_task firmsbase-staging-maintenance \
  '{"containerOverrides":[{"name":"app","command":["maintenance","tinker","--execute=echo \"REDIS_OK \" . Redis::connection()->ping();"]}]}' \
  maintenance

# =============================================================================
# Probe 4: Laravel configuration boot (confirms config:cache / service
# container boot succeeds end-to-end with all injected secrets and env
# vars — a broader smoke check than probes 1-3 individually).
# =============================================================================
echo "=== Probe 4: Laravel configuration boot ==="
run_probe_task firmsbase-staging-maintenance \
  '{"containerOverrides":[{"name":"app","command":["maintenance","config:cache"]}]}' \
  maintenance

# =============================================================================
# Probe 5: /up and /readyz after web launch — HTTP-only, synthetic, no
# client data. Run only after Stage 1 of runtime-verification-commands.sh
# has created the web service and it has reached steady state.
# =============================================================================
echo "=== Probe 5: web /up and /readyz (run after web service is stable) ==="
ALB_DNS="<paste from live-state ALB description — DNS name only, HTTP:80>"
curl -s -o /dev/null -w "up: %{http_code}\n"     "http://${ALB_DNS}/up"
curl -s -o /dev/null -w "readyz: %{http_code}\n" "http://${ALB_DNS}/readyz"
