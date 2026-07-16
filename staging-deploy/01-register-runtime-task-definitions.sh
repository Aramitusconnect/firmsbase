#!/usr/bin/env bash
# =============================================================================
# REGISTER RUNTIME TASK DEFINITIONS — NOT EXECUTED. Prepared for manual
# review and manual execution in CloudShell by:
#   arn:aws:iam::603013471426:user/firmsbase-staging-operator
# Region: us-east-1. Cluster: firmsbase-staging-cluster.
#
# Registers exactly four task definitions: firmsbase-staging-web,
# firmsbase-staging-worker, firmsbase-staging-critical-worker,
# firmsbase-staging-scheduler. Creates NO service, runs NO task, modifies
# NO ALB resource. Run from the repository root (staging-deploy/*.json
# must resolve as relative paths).
#
# Writes a sanitized local manifest (runtime-task-definitions.manifest.json)
# containing the four EXACT registered task-definition ARNs. Every launch
# script downstream reads its ARN from this manifest and passes that exact
# ARN to create-service — never a family-only reference — so a newer
# ACTIVE revision registered by someone else between verification and
# service creation can never be silently selected instead. The manifest
# contains only ARNs; no secrets.
# =============================================================================
set -euo pipefail
set +x
export AWS_PAGER=""

CLUSTER=firmsbase-staging-cluster
REGION=us-east-1
EXPECTED_ACCOUNT=603013471426
EXPECTED_ARN="arn:aws:iam::603013471426:user/firmsbase-staging-operator"
APPROVED_IMAGE="603013471426.dkr.ecr.us-east-1.amazonaws.com/firmsbase-staging@sha256:92eecbeeef5225fcfe0f4256a0b375a773cd84c1f4974fc08b137811a27e46fd"
APPROVED_EXEC_ROLE="arn:aws:iam::603013471426:role/firmsbase-staging-ecs-execution-role"
APPROVED_TASK_ROLE="arn:aws:iam::603013471426:role/firmsbase-staging-ecs-task-role"
MANIFEST_FILE=runtime-task-definitions.manifest.json
EXPOSURE_EVIDENCE_FILE=00-http-exposure-preflight-evidence.json

declare -A TD_FILES=(
  [web]=staging-deploy/firmsbase-staging-web.json
  [worker]=staging-deploy/firmsbase-staging-worker.json
  [critical-worker]=staging-deploy/firmsbase-staging-critical-worker.json
  [scheduler]=staging-deploy/firmsbase-staging-scheduler.json
)
declare -A TD_FAMILIES=(
  [web]=firmsbase-staging-web
  [worker]=firmsbase-staging-worker
  [critical-worker]=firmsbase-staging-critical-worker
  [scheduler]=firmsbase-staging-scheduler
)
ORDER=(web critical-worker worker scheduler)

# -----------------------------------------------------------------------------
# Exact secret-selector + Redis-TLS validation (Blocker 4). Never prints a
# secret value — only ARNs (not secret values) and pass/fail verdicts.
# -----------------------------------------------------------------------------
validate_runtime_secrets() {
  local cd_json="$1" context="$2"
  local fail=0
  local expected_db_app_prefix="arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/database-app-8NUj2a"
  local expected_redis="arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/redis-auth-token-p6rVKN:REDIS_PASSWORD::"

  for pair in "DB_HOST:host" "DB_PORT:port" "DB_DATABASE:dbname" "DB_USERNAME:username" "DB_PASSWORD:password"; do
    local var="${pair%%:*}" field="${pair##*:}"
    local expected="${expected_db_app_prefix}:${field}::"
    local count matched
    count=$(echo "$cd_json" | jq --arg n "$var" '[.secrets[]? | select(.name == $n)] | length')
    if [ "$count" != "1" ]; then
      echo "STOP ($context): expected exactly one $var selector, found $count." >&2
      fail=1
      continue
    fi
    matched=$(echo "$cd_json" | jq -r --arg n "$var" '.secrets[] | select(.name == $n) | .valueFrom')
    [ "$matched" = "$expected" ] || { echo "STOP ($context): $var selector does not match the expected database-app field exactly." >&2; fail=1; }
  done

  local redis_count redis_val
  redis_count=$(echo "$cd_json" | jq '[.secrets[]? | select(.name == "REDIS_PASSWORD")] | length')
  if [ "$redis_count" != "1" ]; then
    echo "STOP ($context): expected exactly one REDIS_PASSWORD selector, found $redis_count." >&2
    fail=1
  else
    redis_val=$(echo "$cd_json" | jq -r '.secrets[] | select(.name == "REDIS_PASSWORD") | .valueFrom')
    [ "$redis_val" = "$expected_redis" ] || { echo "STOP ($context): REDIS_PASSWORD selector does not match exactly." >&2; fail=1; }
  fi

  local all_secret_arns
  all_secret_arns=$(echo "$cd_json" | jq -r '.secrets[]?.valueFrom')
  echo "$all_secret_arns" | grep -qi "database-migrator" && { echo "STOP ($context): database-migrator referenced." >&2; fail=1; }
  echo "$all_secret_arns" | grep -qiE "rds-master|master-secret|mastersecret" && { echo "STOP ($context): an RDS-master-secret-like reference was found." >&2; fail=1; }

  local redis_host redis_port
  redis_host=$(echo "$cd_json" | jq -r '.environment[]? | select(.name=="REDIS_HOST") | .value')
  redis_port=$(echo "$cd_json" | jq -r '.environment[]? | select(.name=="REDIS_PORT") | .value')
  case "$redis_host" in
    tls://*) ;;
    *) echo "STOP ($context): REDIS_HOST does not use the tls:// scheme." >&2; fail=1 ;;
  esac
  [ "$redis_port" = "6379" ] || { echo "STOP ($context): REDIS_PORT is not exactly 6379." >&2; fail=1; }

  return $fail
}

# -----------------------------------------------------------------------------
# Exact family/command/queue/port preflight (Blocker 5) — run BEFORE any
# registration, never discovered after service creation.
# -----------------------------------------------------------------------------
validate_shape() {
  local role="$1" cd_json="$2" td_json="$3"
  local fail=0
  local family
  family=$(echo "$td_json" | jq -r '.family')
  [ "$family" = "${TD_FAMILIES[$role]}" ] || { echo "STOP ($role): family is not ${TD_FAMILIES[$role]} (got $family)." >&2; fail=1; }

  case "$role" in
    web)
      local cmd name ports
      cmd=$(echo "$cd_json" | jq -c '.command')
      [ "$cmd" = '["web"]' ] || { echo "STOP (web): command is not exactly [\"web\"] (got $cmd)." >&2; fail=1; }
      name=$(echo "$cd_json" | jq -r '.name')
      [ "$name" = "app" ] || { echo "STOP (web): container name is not 'app' (got $name)." >&2; fail=1; }
      ports=$(echo "$cd_json" | jq -c '.portMappings // []')
      [ "$(echo "$ports" | jq 'length')" = "1" ] || { echo "STOP (web): expected exactly one port mapping." >&2; fail=1; }
      if [ "$(echo "$ports" | jq 'length')" = "1" ]; then
        local cport hport proto
        cport=$(echo "$ports" | jq -r '.[0].containerPort')
        hport=$(echo "$ports" | jq -r '.[0].hostPort // empty')
        proto=$(echo "$ports" | jq -r '.[0].protocol')
        [ "$cport" = "8080" ] || { echo "STOP (web): containerPort is not 8080 (got $cport)." >&2; fail=1; }
        [ -z "$hport" ] || [ "$hport" = "8080" ] || { echo "STOP (web): hostPort is explicitly present and not 8080 (got $hport)." >&2; fail=1; }
        [ "$proto" = "tcp" ] || { echo "STOP (web): port protocol is not tcp (got $proto)." >&2; fail=1; }
      fi
      ;;
    critical-worker|worker)
      local cmd ports queues expected_queues
      cmd=$(echo "$cd_json" | jq -c '.command')
      [ "$cmd" = '["worker"]' ] || { echo "STOP ($role): command is not exactly [\"worker\"] (got $cmd)." >&2; fail=1; }
      ports=$(echo "$cd_json" | jq -c '.portMappings // []')
      [ "$ports" = "[]" ] || { echo "STOP ($role): must have no port mapping." >&2; fail=1; }
      queues=$(echo "$cd_json" | jq -r '.environment[]? | select(.name=="WORKER_QUEUES") | .value')
      if [ "$role" = "critical-worker" ]; then
        expected_queues="trust"
      else
        expected_queues="default,documents,notifications,integrations,billing,low-priority"
        echo "$queues" | grep -q "trust" && { echo "STOP (worker): queue list unexpectedly contains 'trust'." >&2; fail=1; }
      fi
      [ "$queues" = "$expected_queues" ] || { echo "STOP ($role): WORKER_QUEUES is not exactly '$expected_queues' (got '$queues')." >&2; fail=1; }
      ;;
    scheduler)
      local cmd ports
      cmd=$(echo "$cd_json" | jq -c '.command')
      [ "$cmd" = '["scheduler"]' ] || { echo "STOP (scheduler): command is not exactly [\"scheduler\"] (got $cmd)." >&2; fail=1; }
      ports=$(echo "$cd_json" | jq -c '.portMappings // []')
      [ "$ports" = "[]" ] || { echo "STOP (scheduler): must have no port mapping." >&2; fail=1; }
      ;;
  esac
  return $fail
}

echo "=== Step 1: verify caller identity ==="
IDENTITY=$(aws sts get-caller-identity --region "$REGION")
CALLER_ACCOUNT=$(echo "$IDENTITY" | jq -r '.Account')
CALLER_ARN=$(echo "$IDENTITY" | jq -r '.Arn')
echo "Account: $CALLER_ACCOUNT"
echo "Arn: $CALLER_ARN"
[ "$CALLER_ACCOUNT" = "$EXPECTED_ACCOUNT" ] || { echo "STOP: wrong account." >&2; exit 1; }
[ "$CALLER_ARN" = "$EXPECTED_ARN" ] || { echo "STOP: caller ARN is not the expected restricted operator." >&2; exit 1; }

echo ""
echo "=== Step 2: require the saved HTTP-exposure preflight evidence ==="
[ -f "$EXPOSURE_EVIDENCE_FILE" ] || { echo "STOP: $EXPOSURE_EVIDENCE_FILE not found — run 00-http-exposure-preflight.sh first." >&2; exit 1; }
jq empty "$EXPOSURE_EVIDENCE_FILE" || { echo "STOP: $EXPOSURE_EVIDENCE_FILE is not valid JSON." >&2; exit 1; }
EVIDENCE_ALB_ARN=$(jq -r '.alb.arn' "$EXPOSURE_EVIDENCE_FILE")
[ -n "$EVIDENCE_ALB_ARN" ] && [ "$EVIDENCE_ALB_ARN" != "null" ] || { echo "STOP: exposure evidence file has no ALB ARN recorded." >&2; exit 1; }
echo "Exposure evidence present, ALB ARN: $EVIDENCE_ALB_ARN  verdict: $(jq -r '.verdict' "$EXPOSURE_EVIDENCE_FILE")"

echo ""
echo "=== Step 3: verify cluster ACTIVE ==="
CLUSTER_STATUS=$(aws ecs describe-clusters --region "$REGION" --clusters "$CLUSTER" --query 'clusters[0].status' --output text)
echo "Cluster status: $CLUSTER_STATUS"
[ "$CLUSTER_STATUS" = "ACTIVE" ] || { echo "STOP: cluster is not ACTIVE." >&2; exit 1; }

echo ""
echo "=== Step 4: confirm zero existing services ==="
SERVICE_ARNS=$(aws ecs list-services --region "$REGION" --cluster "$CLUSTER" --query 'serviceArns' --output json)
echo "Existing services: $SERVICE_ARNS"
[ "$(echo "$SERVICE_ARNS" | jq 'length')" = "0" ] || { echo "STOP: at least one ECS service already exists." >&2; exit 1; }

echo ""
echo "=== Step 5: confirm zero running or pending tasks ==="
RUNNING=$(aws ecs list-tasks --region "$REGION" --cluster "$CLUSTER" --desired-status RUNNING --query 'taskArns' --output json)
PENDING=$(aws ecs list-tasks --region "$REGION" --cluster "$CLUSTER" --desired-status PENDING --query 'taskArns' --output json)
echo "Running tasks: $RUNNING"
echo "Pending tasks: $PENDING"
[ "$(echo "$RUNNING" | jq 'length')" = "0" ] && [ "$(echo "$PENDING" | jq 'length')" = "0" ] || { echo "STOP: at least one task already running or pending." >&2; exit 1; }

echo ""
echo "=== Step 6: local jq validation of all four JSON files ==="
for role in "${ORDER[@]}"; do
  file="${TD_FILES[$role]}"
  jq empty "$file" 2>/dev/null || { echo "STOP: $file is not valid JSON." >&2; exit 1; }
  echo "$file: valid JSON"
done

echo ""
echo "=== Step 7: full preflight — image/roles/secrets/Redis-TLS/shape — BEFORE registering anything ==="
for role in "${ORDER[@]}"; do
  file="${TD_FILES[$role]}"
  td_json=$(cat "$file")
  cd_json=$(echo "$td_json" | jq -c '.containerDefinitions[0]')
  image=$(echo "$td_json" | jq -r '.containerDefinitions[0].image')
  exec_role=$(echo "$td_json" | jq -r '.executionRoleArn')
  task_role=$(echo "$td_json" | jq -r '.taskRoleArn')

  fail=0
  [ "$image" = "$APPROVED_IMAGE" ] || { echo "STOP ($role): image does not match the approved digest." >&2; fail=1; }
  [ "$exec_role" = "$APPROVED_EXEC_ROLE" ] || { echo "STOP ($role): execution role does not match." >&2; fail=1; }
  [ "$task_role" = "$APPROVED_TASK_ROLE" ] || { echo "STOP ($role): task role does not match." >&2; fail=1; }
  validate_runtime_secrets "$cd_json" "$role (local, pre-registration)" || fail=1
  validate_shape "$role" "$cd_json" "$td_json" || fail=1

  [ "$fail" = "0" ] || exit 1
  echo "$role: image/roles/secrets/Redis-TLS/shape all verified"
done

echo ""
echo "=== Step 8: explicit acknowledgement ==="
if [ "${CONFIRMED_RUNTIME_TASK_DEFINITIONS:-}" != "yes" ]; then
  echo "STOP: CONFIRMED_RUNTIME_TASK_DEFINITIONS is not set to 'yes'." >&2
  echo "Re-run with:" >&2
  echo "  CONFIRMED_RUNTIME_TASK_DEFINITIONS=yes ./01-register-runtime-task-definitions.sh" >&2
  exit 1
fi
echo "CONFIRMED_RUNTIME_TASK_DEFINITIONS=yes acknowledged."

echo ""
echo "=== Step 9: register each definition once, then re-verify the EXACT registered ARN ==="
: > "$MANIFEST_FILE"
echo "{" >> "$MANIFEST_FILE"
first=1
for role in "${ORDER[@]}"; do
  file="${TD_FILES[$role]}"
  reg=$(aws ecs register-task-definition --region "$REGION" --cli-input-json "file://${file}")
  arn=$(echo "$reg" | jq -r '.taskDefinition.taskDefinitionArn')
  revision=$(echo "$reg" | jq -r '.taskDefinition.revision')
  echo "Registered: $arn (revision $revision)"

  # Post-registration re-verification against the EXACT registered ARN
  # (Blocker 4: "after registration against the exact registered ARN").
  post_td=$(aws ecs describe-task-definition --region "$REGION" --task-definition "$arn")
  post_cd=$(echo "$post_td" | jq -c '.taskDefinition.containerDefinitions[0]')
  post_status=$(echo "$post_td" | jq -r '.taskDefinition.status')
  [ "$post_status" = "ACTIVE" ] || { echo "STOP: $arn is not ACTIVE immediately after registration." >&2; exit 1; }
  validate_runtime_secrets "$post_cd" "$role (post-registration, exact ARN $arn)" || exit 1
  validate_shape "$role" "$post_cd" "$post_td" || exit 1
  echo "$role: exact registered ARN re-verified"

  [ "$first" = "1" ] || echo "," >> "$MANIFEST_FILE"
  first=0
  printf '  "%s": "%s"' "$role" "$arn" >> "$MANIFEST_FILE"
done
echo "" >> "$MANIFEST_FILE"
echo "}" >> "$MANIFEST_FILE"

jq empty "$MANIFEST_FILE" || { echo "STOP: manifest file is not valid JSON." >&2; exit 1; }
echo ""
echo "Manifest written: $MANIFEST_FILE"
cat "$MANIFEST_FILE"

echo ""
echo "=== All four runtime task definitions registered and re-verified against their exact ARNs. No service created. No task run. No ALB modified. ==="
