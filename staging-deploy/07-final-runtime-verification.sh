#!/usr/bin/env bash
# =============================================================================
# FINAL RUNTIME VERIFICATION — READ-ONLY, NOT EXECUTED. Run only after
# 06-launch-scheduler.sh has succeeded. Creates and modifies nothing.
#
# HTTPS IS NOT CONFIGURED YET. Passing this script is a staging-internal,
# HTTP-only synthetic confirmation — NOT production approval and NOT
# authorization for real client traffic.
# =============================================================================
set -euo pipefail
set +x
export AWS_PAGER=""

CLUSTER=firmsbase-staging-cluster
REGION=us-east-1
LOG_GROUP=/ecs/firmsbase-staging/app
EXPECTED_ACCOUNT=603013471426
EXPECTED_ARN="arn:aws:iam::603013471426:user/firmsbase-staging-operator"
APPROVED_IMAGE="603013471426.dkr.ecr.us-east-1.amazonaws.com/firmsbase-staging@sha256:92eecbeeef5225fcfe0f4256a0b375a773cd84c1f4974fc08b137811a27e46fd"
TG_ARN="arn:aws:elasticloadbalancing:us-east-1:603013471426:targetgroup/firmsbase-staging-tg/1830c01b9aaac37d"
ALB_NAME=firmsbase-staging-alb
MANIFEST_FILE=runtime-task-definitions.manifest.json
EXPECTED_SERVICES="web critical-worker worker scheduler"

FAIL=0
fail() { echo "FAIL: $1" >&2; FAIL=1; }

validate_runtime_secrets() {
  local cd_json="$1" context="$2"
  local expected_db_app_prefix="arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/database-app-8NUj2a"
  local expected_redis="arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/redis-auth-token-p6rVKN:REDIS_PASSWORD::"
  for pair in "DB_HOST:host" "DB_PORT:port" "DB_DATABASE:dbname" "DB_USERNAME:username" "DB_PASSWORD:password"; do
    local var="${pair%%:*}" field="${pair##*:}"
    local expected="${expected_db_app_prefix}:${field}::"
    local count matched
    count=$(echo "$cd_json" | jq --arg n "$var" '[.secrets[]? | select(.name == $n)] | length')
    if [ "$count" != "1" ]; then fail "$context: expected exactly one $var selector, found $count"; continue; fi
    matched=$(echo "$cd_json" | jq -r --arg n "$var" '.secrets[] | select(.name == $n) | .valueFrom')
    [ "$matched" = "$expected" ] || fail "$context: $var selector does not match exactly"
  done
  local redis_count redis_val
  redis_count=$(echo "$cd_json" | jq '[.secrets[]? | select(.name == "REDIS_PASSWORD")] | length')
  if [ "$redis_count" != "1" ]; then
    fail "$context: expected exactly one REDIS_PASSWORD selector, found $redis_count"
  else
    redis_val=$(echo "$cd_json" | jq -r '.secrets[] | select(.name == "REDIS_PASSWORD") | .valueFrom')
    [ "$redis_val" = "$expected_redis" ] || fail "$context: REDIS_PASSWORD selector does not match exactly"
  fi
  local all_secret_arns
  all_secret_arns=$(echo "$cd_json" | jq -r '.secrets[]?.valueFrom')
  echo "$all_secret_arns" | grep -qi "database-migrator" && fail "$context: database-migrator referenced"
  echo "$all_secret_arns" | grep -qiE "rds-master|master-secret|mastersecret" && fail "$context: master-secret-like reference found"
  local redis_host redis_port
  redis_host=$(echo "$cd_json" | jq -r '.environment[]? | select(.name=="REDIS_HOST") | .value')
  redis_port=$(echo "$cd_json" | jq -r '.environment[]? | select(.name=="REDIS_PORT") | .value')
  case "$redis_host" in tls://*) ;; *) fail "$context: REDIS_HOST is not tls://" ;; esac
  [ "$redis_port" = "6379" ] || fail "$context: REDIS_PORT is not 6379"
}

echo "=== Check 0: verify caller identity ==="
IDENTITY=$(aws sts get-caller-identity --region "$REGION")
CALLER_ACCOUNT=$(echo "$IDENTITY" | jq -r '.Account')
CALLER_ARN=$(echo "$IDENTITY" | jq -r '.Arn')
echo "Account: $CALLER_ACCOUNT"
echo "Arn: $CALLER_ARN"
[ "$CALLER_ACCOUNT" = "$EXPECTED_ACCOUNT" ] || { echo "STOP: wrong account." >&2; exit 1; }
[ "$CALLER_ARN" = "$EXPECTED_ARN" ] || { echo "STOP: caller ARN is not the expected restricted operator." >&2; exit 1; }

echo ""
echo "=== Check 1: exactly four services exist, matching the expected set ==="
ALL_SERVICE_ARNS=$(aws ecs list-services --region "$REGION" --cluster "$CLUSTER" --query 'serviceArns' --output json)
SERVICE_COUNT=$(echo "$ALL_SERVICE_ARNS" | jq 'length')
echo "Service count: $SERVICE_COUNT"
[ "$SERVICE_COUNT" = "4" ] || fail "expected exactly 4 services, found $SERVICE_COUNT"

SVC=$(aws ecs describe-services --region "$REGION" --cluster "$CLUSTER" --services web critical-worker worker scheduler)
for name in $EXPECTED_SERVICES; do
  present=$(echo "$SVC" | jq --arg n "$name" '[.services[] | select(.serviceName == $n)] | length')
  [ "$present" = "1" ] || fail "service '$name' not found"
done

echo ""
echo "=== Check 2: full deployment state per service — not inferred from deployments.length=1 alone ==="
for name in $EXPECTED_SERVICES; do
  desired=$(echo "$SVC" | jq -r --arg n "$name" '.services[] | select(.serviceName==$n) | .desiredCount')
  running=$(echo "$SVC" | jq -r --arg n "$name" '.services[] | select(.serviceName==$n) | .runningCount')
  pending=$(echo "$SVC" | jq -r --arg n "$name" '.services[] | select(.serviceName==$n) | .pendingCount')
  deploy_count=$(echo "$SVC" | jq -r --arg n "$name" '.services[] | select(.serviceName==$n) | .deployments | length')
  status=$(echo "$SVC" | jq -r --arg n "$name" '.services[] | select(.serviceName==$n) | .deployments[0].status // "none"')
  rollout=$(echo "$SVC" | jq -r --arg n "$name" '.services[] | select(.serviceName==$n) | .deployments[0].rolloutState // "none"')
  events=$(echo "$SVC" | jq -r --arg n "$name" '[.services[] | select(.serviceName==$n) | .events[0:5][]?.message] | join(" | ")')
  echo "$name: desired=$desired running=$running pending=$pending deployments=$deploy_count status=$status rolloutState=$rollout"
  [ "$desired" = "$running" ] || fail "$name: desired ($desired) != running ($running)"
  [ "$pending" = "0" ] || fail "$name: pendingCount is not 0"
  [ "$deploy_count" = "1" ] || fail "$name: deployment still in progress ($deploy_count deployments present)"
  [ "$status" = "PRIMARY" ] || fail "$name: deployment status is not PRIMARY"
  [ "$rollout" = "COMPLETED" ] || fail "$name: rolloutState is not COMPLETED"
  echo "$events" | grep -qiE "circuit breaker.*(fail|rollback)" && fail "$name: circuit-breaker failure/rollback event found: $events"
done

echo ""
echo "=== Check 3: scheduler desired count is exactly 1 ==="
SCHED_DESIRED=$(echo "$SVC" | jq -r '.services[] | select(.serviceName=="scheduler") | .desiredCount')
echo "scheduler desired count: $SCHED_DESIRED"
[ "$SCHED_DESIRED" = "1" ] || fail "scheduler desired count is not exactly 1"

echo ""
echo "=== Check 4: all running tasks use the approved immutable digest AND the manifest's exact ARN ==="
MANIFEST_OK=0
[ -f "$MANIFEST_FILE" ] && jq empty "$MANIFEST_FILE" 2>/dev/null && MANIFEST_OK=1
for name in $EXPECTED_SERVICES; do
  arns=$(aws ecs list-tasks --region "$REGION" --cluster "$CLUSTER" --service-name "$name" --desired-status RUNNING --query 'taskArns' --output json)
  count=$(echo "$arns" | jq 'length')
  if [ "$count" = "0" ]; then fail "$name: no RUNNING task found"; continue; fi
  for arn in $(echo "$arns" | jq -r '.[]'); do
    desc=$(aws ecs describe-tasks --region "$REGION" --cluster "$CLUSTER" --tasks "$arn")
    image=$(echo "$desc" | jq -r '.tasks[0].containers[] | select(.name=="app") | .image')
    task_td=$(echo "$desc" | jq -r '.tasks[0].taskDefinitionArn')
    [ "$image" = "$APPROVED_IMAGE" ] || fail "$name task $arn does not use the approved digest (got: $image)"
    if [ "$MANIFEST_OK" = "1" ]; then
      manifest_arn=$(jq -r --arg n "$name" '.[$n]' "$MANIFEST_FILE")
      [ "$task_td" = "$manifest_arn" ] || fail "$name task $arn does not use the manifest's exact ARN"
    fi
  done
done
echo "Digest and exact-ARN check complete."

echo ""
echo "=== Check 5: no runtime service uses migrator credentials; exact secret selectors; no port except web; no worker attached to ALB ==="
for name in $EXPECTED_SERVICES; do
  td_family="firmsbase-staging-${name}"
  TD=$(aws ecs describe-task-definition --region "$REGION" --task-definition "$td_family")
  CD=$(echo "$TD" | jq -c '.taskDefinition.containerDefinitions[0]')
  validate_runtime_secrets "$CD" "$name (final verification)"
  ports=$(echo "$CD" | jq -c '.portMappings // []')

  lbs=$(echo "$SVC" | jq -c --arg n "$name" '.services[] | select(.serviceName==$n) | .loadBalancers')
  if [ "$name" = "web" ]; then
    [ "$ports" != "[]" ] || fail "web does not expose a port"
    [ "$(echo "$lbs" | jq 'length')" = "1" ] || fail "web is not attached to the ALB target group"
    lb_tg=$(echo "$lbs" | jq -r '.[0].targetGroupArn')
    lb_container=$(echo "$lbs" | jq -r '.[0].containerName')
    lb_port=$(echo "$lbs" | jq -r '.[0].containerPort')
    [ "$lb_tg" = "$TG_ARN" ] || fail "web load-balancer entry does not use the reviewed target-group ARN"
    [ "$lb_container" = "app" ] || fail "web load-balancer entry container name is not 'app'"
    [ "$lb_port" = "8080" ] || fail "web load-balancer entry container port is not 8080"
  else
    [ "$ports" = "[]" ] || fail "$name unexpectedly exposes a port"
    [ "$(echo "$lbs" | jq 'length')" = "0" ] || fail "$name is unexpectedly attached to a load balancer"
  fi
done
echo "Secret-separation, exact-selector, and network-exposure checks complete."

echo ""
echo "=== Check 6: web ALB target — exactly one, healthy ==="
TARGET_HEALTH=$(aws elbv2 describe-target-health --region "$REGION" --target-group-arn "$TG_ARN")
TOTAL=$(echo "$TARGET_HEALTH" | jq '.TargetHealthDescriptions | length')
HEALTHY=$(echo "$TARGET_HEALTH" | jq '[.TargetHealthDescriptions[] | select(.TargetHealth.State == "healthy")] | length')
echo "$TARGET_HEALTH" | jq -c '.TargetHealthDescriptions[] | {target: .Target.Id, state: .TargetHealth.State}'
if [ "$TOTAL" = "0" ]; then
  fail "target group is empty — an empty target list is never a pass"
else
  [ "$TOTAL" = "1" ] || fail "expected exactly one target, found $TOTAL"
  [ "$HEALTHY" = "1" ] || fail "expected exactly one healthy target, found $HEALTHY"
fi

echo ""
echo "=== Check 7: /up and /readyz (synthetic HTTP-only) ==="
ALB_DNS=$(aws elbv2 describe-load-balancers --region "$REGION" --names "$ALB_NAME" --query 'LoadBalancers[0].DNSName' --output text)
UP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://${ALB_DNS}/up" || echo "000")
echo "/up status: $UP_CODE"
[ "$UP_CODE" = "200" ] || fail "/up did not return 200"

READYZ_BODY=$(curl -s "http://${ALB_DNS}/readyz" || echo '{}')
READYZ_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://${ALB_DNS}/readyz" || echo "000")
DB_CHECK=$(echo "$READYZ_BODY" | jq -r '.checks.database // "missing"')
REDIS_CHECK=$(echo "$READYZ_BODY" | jq -r '.checks.redis // "not_required"')
echo "/readyz status: $READYZ_CODE database=$DB_CHECK redis=$REDIS_CHECK"
[ "$READYZ_CODE" = "200" ] || fail "/readyz did not return 200"
[ "$DB_CHECK" = "ok" ] || fail "/readyz database check is not ok"
[ "$REDIS_CHECK" = "ok" ] || [ "$REDIS_CHECK" = "not_required" ] || fail "/readyz redis check is not ok"

echo ""
echo "=== Check 8: recent logs free of restart loops / high-severity errors, for every service (current + stopped tasks) ==="
declare -A ENTRYPOINT_ROLE=( [web]=web [critical-worker]=worker [worker]=worker [scheduler]=scheduler )
for name in $EXPECTED_SERVICES; do
  stopped_arns=$(aws ecs list-tasks --region "$REGION" --cluster "$CLUSTER" --service-name "$name" --desired-status STOPPED --query 'taskArns' --output json)
  stopped_count=$(echo "$stopped_arns" | jq 'length')
  [ "$stopped_count" = "0" ] || fail "$name: $stopped_count previously-stopped task(s) found — a newly healthy replacement does not clear this"

  arns=$(aws ecs list-tasks --region "$REGION" --cluster "$CLUSTER" --service-name "$name" --desired-status RUNNING --query 'taskArns' --output json)
  arn=$(echo "$arns" | jq -r '.[0] // empty')
  [ -z "$arn" ] && continue
  task_id=$(echo "$arn" | awk -F/ '{print $NF}')
  recent=$(aws logs tail "$LOG_GROUP" --region "$REGION" --log-stream-names "${name}/app/${task_id}" --since 15m 2>/dev/null || echo "")
  bad=$(printf '%s\n' "$recent" | grep -icE "fatal error|unhandled exception|SQLSTATE|redis.*(auth|connection).*fail|permission denied|secret.*inject.*fail|queue connection.*fail" || true)
  role="${ENTRYPOINT_ROLE[$name]}"
  starts=$(printf '%s\n' "$recent" | grep -c "starting role '${role}'" || true)
  [ "$bad" = "0" ] || fail "$name: high-severity marker found in recent logs"
  [ "$starts" -le "1" ] || fail "$name: entrypoint started more than once in the last 15m — possible restart loop"
  echo "$name: high-severity markers=$bad entrypoint-starts=$starts stopped-tasks=$stopped_count"
done

echo ""
echo "=== Check 9: no migration task currently running ==="
MIGRATE_RUNNING=$(aws ecs list-tasks --region "$REGION" --cluster "$CLUSTER" --family firmsbase-staging-migrate --desired-status RUNNING --query 'taskArns' --output json)
MIGRATE_PENDING=$(aws ecs list-tasks --region "$REGION" --cluster "$CLUSTER" --family firmsbase-staging-migrate --desired-status PENDING --query 'taskArns' --output json)
echo "Running migrate tasks: $MIGRATE_RUNNING"
echo "Pending migrate tasks: $MIGRATE_PENDING"
[ "$(echo "$MIGRATE_RUNNING" | jq 'length')" = "0" ] || fail "a migrate task is currently RUNNING"
[ "$(echo "$MIGRATE_PENDING" | jq 'length')" = "0" ] || fail "a migrate task is currently PENDING"

echo ""
echo "=== Check 10: no runtime service uses database-migrator (explicit final assertion) ==="
for name in $EXPECTED_SERVICES; do
  td_family="firmsbase-staging-${name}"
  TD=$(aws ecs describe-task-definition --region "$REGION" --task-definition "$td_family")
  ARNS_USED=$(echo "$TD" | jq -r '.taskDefinition.containerDefinitions[0].secrets[]?.valueFrom')
  echo "$ARNS_USED" | grep -qi "database-migrator" && fail "$name: task definition references database-migrator"
done
echo "Migrator-credential exclusion confirmed for all four runtime services."

echo ""
if [ "$FAIL" = "1" ]; then
  echo "STOP: one or more runtime verification checks failed. See FAIL lines above." >&2
  exit 1
fi

echo "All runtime verification checks passed. This is HTTP-only synthetic verification;"
echo "real client traffic remains blocked until HTTPS is configured (see https-remediation-plan.md)."
echo "RUNTIME_SERVICES_VERIFIED"
echo "HTTP_SYNTHETIC_ONLY_HTTPS_STILL_REQUIRED"
