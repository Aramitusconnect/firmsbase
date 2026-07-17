#!/usr/bin/env bash
# =============================================================================
# SCHEDULER SERVICE LAUNCH — NOT EXECUTED. Run only after
# 05-launch-worker.sh has confirmed worker stable. Creates ONLY the
# scheduler service. Never attaches a load balancer, never exposes a
# port, never runs a migration, never registers another task definition.
#
# IMPORTANT: this service must NEVER be scaled beyond desiredCount=1.
# schedule:work loops internally; two concurrent tasks would double-
# dispatch scheduled jobs. routes/console.php currently registers zero
# Schedule:: entries, so this process is correct but has nothing to
# dispatch yet — that is expected, not a defect in this launch.
#
# Environment acknowledgements alone are not trusted here — this script
# re-verifies web, critical-worker, AND worker LIVE before creating
# scheduler, on top of requiring the human acknowledgement.
# =============================================================================
set -euo pipefail
set +x
export AWS_PAGER=""

CLUSTER=firmsbase-staging-cluster
REGION=us-east-1
SUBNETS=subnet-07efcb5d4bcf5aa59,subnet-020540b8377bb4d0e
SG=sg-0db14e50ea5c5466c
LOG_GROUP=/ecs/firmsbase-staging/app
EXPECTED_ACCOUNT=603013471426
EXPECTED_ARN="arn:aws:iam::603013471426:user/firmsbase-staging-operator"
APPROVED_IMAGE="603013471426.dkr.ecr.us-east-1.amazonaws.com/firmsbase-staging@sha256:92eecbeeef5225fcfe0f4256a0b375a773cd84c1f4974fc08b137811a27e46fd"
TG_ARN="arn:aws:elasticloadbalancing:us-east-1:603013471426:targetgroup/firmsbase-staging-tg/1830c01b9aaac37d"
ALB_NAME=firmsbase-staging-alb
MANIFEST_FILE=runtime-task-definitions.manifest.json
STABLE_WAIT_TIMEOUT=180
APPROVED_SOURCE_COMMIT=6a1affdaad2bc1c4a48c5e411b9e39056039cde9

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
    if [ "$count" != "1" ]; then echo "STOP ($context): expected exactly one $var selector, found $count." >&2; fail=1; continue; fi
    matched=$(echo "$cd_json" | jq -r --arg n "$var" '.secrets[] | select(.name == $n) | .valueFrom')
    [ "$matched" = "$expected" ] || { echo "STOP ($context): $var selector does not match exactly." >&2; fail=1; }
  done
  local redis_count redis_val
  redis_count=$(echo "$cd_json" | jq '[.secrets[]? | select(.name == "REDIS_PASSWORD")] | length')
  if [ "$redis_count" != "1" ]; then
    echo "STOP ($context): expected exactly one REDIS_PASSWORD selector, found $redis_count." >&2; fail=1
  else
    redis_val=$(echo "$cd_json" | jq -r '.secrets[] | select(.name == "REDIS_PASSWORD") | .valueFrom')
    [ "$redis_val" = "$expected_redis" ] || { echo "STOP ($context): REDIS_PASSWORD selector does not match exactly." >&2; fail=1; }
  fi
  local all_secret_arns
  all_secret_arns=$(echo "$cd_json" | jq -r '.secrets[]?.valueFrom')
  echo "$all_secret_arns" | grep -qi "database-migrator" && { echo "STOP ($context): database-migrator referenced." >&2; fail=1; }
  echo "$all_secret_arns" | grep -qiE "rds-master|master-secret|mastersecret" && { echo "STOP ($context): master-secret-like reference found." >&2; fail=1; }
  local redis_host redis_port
  redis_host=$(echo "$cd_json" | jq -r '.environment[]? | select(.name=="REDIS_HOST") | .value')
  redis_port=$(echo "$cd_json" | jq -r '.environment[]? | select(.name=="REDIS_PORT") | .value')
  case "$redis_host" in tls://*) ;; *) echo "STOP ($context): REDIS_HOST is not tls://." >&2; fail=1 ;; esac
  [ "$redis_port" = "6379" ] || { echo "STOP ($context): REDIS_PORT is not 6379." >&2; fail=1; }
  return $fail
}

validate_scheduler_shape() {
  local cd_json="$1"
  local fail=0
  local cmd ports
  cmd=$(echo "$cd_json" | jq -c '.command')
  [ "$cmd" = '["scheduler"]' ] || { echo "STOP (scheduler): command is not exactly [\"scheduler\"]." >&2; fail=1; }
  ports=$(echo "$cd_json" | jq -c '.portMappings // []')
  [ "$ports" = "[]" ] || { echo "STOP (scheduler): must have no port mapping." >&2; fail=1; }
  return $fail
}

assert_deployment_state() {
  local svc_json="$1" name="$2"
  local fail=0
  local desired running pending deploy_count status rollout events
  desired=$(echo "$svc_json" | jq -r '.services[0].desiredCount')
  running=$(echo "$svc_json" | jq -r '.services[0].runningCount')
  pending=$(echo "$svc_json" | jq -r '.services[0].pendingCount')
  deploy_count=$(echo "$svc_json" | jq -r '.services[0].deployments | length')
  status=$(echo "$svc_json" | jq -r '.services[0].deployments[0].status // "none"')
  rollout=$(echo "$svc_json" | jq -r '.services[0].deployments[0].rolloutState // "none"')
  events=$(echo "$svc_json" | jq -r '[.services[0].events[0:5][]?.message] | join(" | ")')
  echo "$name: desired=$desired running=$running pending=$pending deployments=$deploy_count status=$status rolloutState=$rollout"
  [ "$desired" = "1" ] || { echo "STOP ($name): desiredCount is not 1." >&2; fail=1; }
  [ "$running" = "1" ] || { echo "STOP ($name): runningCount is not 1." >&2; fail=1; }
  [ "$pending" = "0" ] || { echo "STOP ($name): pendingCount is not 0." >&2; fail=1; }
  [ "$deploy_count" = "1" ] || { echo "STOP ($name): expected exactly one deployment." >&2; fail=1; }
  [ "$status" = "PRIMARY" ] || { echo "STOP ($name): deployment status is not PRIMARY." >&2; fail=1; }
  [ "$rollout" = "COMPLETED" ] || { echo "STOP ($name): rolloutState is not COMPLETED." >&2; fail=1; }
  echo "$events" | grep -qiE "circuit breaker.*(fail|rollback)" && { echo "STOP ($name): circuit-breaker failure/rollback event found: $events" >&2; fail=1; }
  return $fail
}

inspect_service_health(){
  local name="$1" role_label="$2"
  local fail=0
  local svc events
  svc=$(aws ecs describe-services --region "$REGION" --cluster "$CLUSTER" --services "$name")
  events=$(echo "$svc" | jq -r '[.services[0].events[0:10][]?.message] | join("\n")')
  echo "--- recent service events ($name) ---"
  echo "$events"

  local stopped_arns stopped_count
  stopped_arns=$(aws ecs list-tasks --region "$REGION" --cluster "$CLUSTER" --service-name "$name" --desired-status STOPPED --query 'taskArns' --output json)
  stopped_count=$(echo "$stopped_arns" | jq 'length')
  echo "Stopped task count for $name: $stopped_count"
  if [ "$stopped_count" != "0" ]; then
    for arn in $(echo "$stopped_arns" | jq -r '.[]'); do
      local desc reason exit_code
      desc=$(aws ecs describe-tasks --region "$REGION" --cluster "$CLUSTER" --tasks "$arn")
      reason=$(echo "$desc" | jq -r '.tasks[0].stoppedReason // "none"')
      exit_code=$(echo "$desc" | jq -r '.tasks[0].containers[] | select(.name=="app") | .exitCode // "null"')
      echo "STOPPED task $arn: reason=$reason exitCode=$exit_code" >&2
      fail=1
    done
    echo "STOP ($name): $stopped_count previously-stopped task(s) found — a newly healthy replacement does not clear this." >&2
  fi

  local running_arn recent
  running_arn=$(aws ecs list-tasks --region "$REGION" --cluster "$CLUSTER" --service-name "$name" --desired-status RUNNING --query 'taskArns[0]' --output text)
  if [ -n "$running_arn" ] && [ "$running_arn" != "None" ]; then
    local task_id
    task_id=$(echo "$running_arn" | awk -F/ '{print $NF}')
    echo "Current running task: $running_arn"
    recent=$(aws logs tail "$LOG_GROUP" --region "$REGION" --log-stream-names "${name}/app/${task_id}" --since 15m 2>/dev/null || echo "")
    local bad starts
    bad=$(printf '%s\n' "$recent" | grep -icE "fatal error|unhandled exception|SQLSTATE|redis.*(auth|connection).*fail|permission denied|secret.*inject.*fail|queue connection.*fail" || true)
    starts=$(printf '%s\n' "$recent" | grep -c "starting role '${role_label}'" || true)
    echo "high-severity markers: $bad  entrypoint-starts: $starts"
    [ "$bad" = "0" ] || { echo "STOP ($name): high-severity marker in recent logs." >&2; fail=1; }
    [ "$starts" -le "1" ] || { echo "STOP ($name): more than one entrypoint start — possible restart loop." >&2; fail=1; }
  else
    echo "STOP ($name): no RUNNING task found." >&2
    fail=1
  fi
  return $fail
}

reverify_web_live() {
  local fail=0
  local svc desired running pending rollout
  svc=$(aws ecs describe-services --region "$REGION" --cluster "$CLUSTER" --services web)
  [ "$(echo "$svc" | jq '.services | length')" = "1" ] || { echo "STOP: web service does not exist exactly once." >&2; fail=1; }
  desired=$(echo "$svc" | jq -r '.services[0].desiredCount')
  running=$(echo "$svc" | jq -r '.services[0].runningCount')
  pending=$(echo "$svc" | jq -r '.services[0].pendingCount')
  rollout=$(echo "$svc" | jq -r '.services[0].deployments[0].rolloutState // "none"')
  echo "web (live re-check): desired=$desired running=$running pending=$pending rolloutState=$rollout"
  [ "$desired" = "1" ] || { echo "STOP: web desiredCount is not 1." >&2; fail=1; }
  [ "$running" = "1" ] || { echo "STOP: web runningCount is not 1." >&2; fail=1; }
  [ "$pending" = "0" ] || { echo "STOP: web pendingCount is not 0." >&2; fail=1; }
  [ "$rollout" = "COMPLETED" ] || { echo "STOP: web rolloutState is not COMPLETED." >&2; fail=1; }

  local targets total healthy
  targets=$(aws elbv2 describe-target-health --region "$REGION" --target-group-arn "$TG_ARN")
  total=$(echo "$targets" | jq '.TargetHealthDescriptions | length')
  healthy=$(echo "$targets" | jq '[.TargetHealthDescriptions[] | select(.TargetHealth.State == "healthy")] | length')
  echo "web ALB targets: total=$total healthy=$healthy"
  [ "$total" = "1" ] || { echo "STOP: expected exactly one ALB target, found $total." >&2; fail=1; }
  [ "$healthy" = "1" ] || { echo "STOP: ALB target is not healthy." >&2; fail=1; }

  local alb_dns up_code readyz_code readyz_body db_check redis_check
  alb_dns=$(aws elbv2 describe-load-balancers --region "$REGION" --names "$ALB_NAME" --query 'LoadBalancers[0].DNSName' --output text)
  up_code=$(curl -s -o /dev/null -w "%{http_code}" "http://${alb_dns}/up" || echo "000")
  readyz_body=$(curl -s "http://${alb_dns}/readyz" || echo '{}')
  readyz_code=$(curl -s -o /dev/null -w "%{http_code}" "http://${alb_dns}/readyz" || echo "000")
  db_check=$(echo "$readyz_body" | jq -r '.checks.database // "missing"')
  redis_check=$(echo "$readyz_body" | jq -r '.checks.redis // "not_required"')
  echo "web /up: $up_code   /readyz: $readyz_code database=$db_check redis=$redis_check"
  [ "$up_code" = "200" ] || { echo "STOP: web /up is not 200." >&2; fail=1; }
  [ "$readyz_code" = "200" ] || { echo "STOP: web /readyz is not 200." >&2; fail=1; }
  [ "$db_check" = "ok" ] || { echo "STOP: web /readyz database check is not ok." >&2; fail=1; }
  [ "$redis_check" = "ok" ] || [ "$redis_check" = "not_required" ] || { echo "STOP: web /readyz redis check is not ok." >&2; fail=1; }
  return $fail
}

reverify_worker_family_live() {
  local name="$1" expected_queues="$2"
  local fail=0
  local svc desired running pending rollout td_arn
  svc=$(aws ecs describe-services --region "$REGION" --cluster "$CLUSTER" --services "$name")
  [ "$(echo "$svc" | jq '.services | length')" = "1" ] || { echo "STOP: $name service does not exist exactly once." >&2; fail=1; }
  desired=$(echo "$svc" | jq -r '.services[0].desiredCount')
  running=$(echo "$svc" | jq -r '.services[0].runningCount')
  pending=$(echo "$svc" | jq -r '.services[0].pendingCount')
  rollout=$(echo "$svc" | jq -r '.services[0].deployments[0].rolloutState // "none"')
  td_arn=$(echo "$svc" | jq -r '.services[0].taskDefinition')
  echo "$name (live re-check): desired=$desired running=$running pending=$pending rolloutState=$rollout taskDefinition=$td_arn"
  [ "$desired" = "1" ] || { echo "STOP: $name desiredCount is not 1." >&2; fail=1; }
  [ "$running" = "1" ] || { echo "STOP: $name runningCount is not 1." >&2; fail=1; }
  [ "$pending" = "0" ] || { echo "STOP: $name pendingCount is not 0." >&2; fail=1; }
  [ "$rollout" = "COMPLETED" ] || { echo "STOP: $name rolloutState is not COMPLETED." >&2; fail=1; }

  local td cd queues
  td=$(aws ecs describe-task-definition --region "$REGION" --task-definition "$td_arn")
  cd=$(echo "$td" | jq -c '.taskDefinition.containerDefinitions[0]')
  queues=$(echo "$cd" | jq -r '.environment[]? | select(.name=="WORKER_QUEUES") | .value')
  echo "$name exact task-definition ARN: $td_arn  WORKER_QUEUES=$queues"
  [ "$queues" = "$expected_queues" ] || { echo "STOP: $name WORKER_QUEUES does not match '$expected_queues'." >&2; fail=1; }

  inspect_service_health "$name" worker || fail=1
  return $fail
}

echo "=== Step 1: verify caller identity ==="
IDENTITY=$(aws sts get-caller-identity --region "$REGION")
CALLER_ACCOUNT=$(echo "$IDENTITY" | jq -r '.Account')
CALLER_ARN=$(echo "$IDENTITY" | jq -r '.Arn')
[ "$CALLER_ACCOUNT" = "$EXPECTED_ACCOUNT" ] || { echo "STOP: wrong account." >&2; exit 1; }
[ "$CALLER_ARN" = "$EXPECTED_ARN" ] || { echo "STOP: caller ARN is not the expected restricted operator." >&2; exit 1; }
echo "Identity OK."

echo ""
echo "=== Step 2: LIVE re-verification of web, critical-worker, and worker (not env-var trust alone) ==="
reverify_web_live || { echo "STOP: web live gate re-verification failed." >&2; exit 1; }
reverify_worker_family_live critical-worker trust || { echo "STOP: critical-worker live gate re-verification failed." >&2; exit 1; }
reverify_worker_family_live worker "default,documents,notifications,integrations,billing,low-priority" || { echo "STOP: worker live gate re-verification failed." >&2; exit 1; }
echo "web, critical-worker, and worker live gates fully re-confirmed."

echo ""
echo "=== Step 3: human acknowledgement (ADDITIONAL to the live checks above) ==="
[ "${CONFIRMED_WORKER_VERIFIED:-}" = "yes" ] || { echo "STOP: CONFIRMED_WORKER_VERIFIED is not set to 'yes'." >&2; exit 1; }
echo "CONFIRMED_WORKER_VERIFIED=yes acknowledged."

echo ""
echo "=== Step 4: read exact scheduler ARN from manifest and verify it ==="
[ -f "$MANIFEST_FILE" ] || { echo "STOP: $MANIFEST_FILE not found." >&2; exit 1; }
SCHED_TD_ARN=$(jq -r '.scheduler' "$MANIFEST_FILE")
[ -n "$SCHED_TD_ARN" ] && [ "$SCHED_TD_ARN" != "null" ] || { echo "STOP: manifest has no scheduler entry." >&2; exit 1; }
echo "scheduler ARN from manifest: $SCHED_TD_ARN"

TD=$(aws ecs describe-task-definition --region "$REGION" --task-definition "$SCHED_TD_ARN")
TD_STATUS=$(echo "$TD" | jq -r '.taskDefinition.status')
TD_FAMILY=$(echo "$TD" | jq -r '.taskDefinition.family')
TD_IMAGE=$(echo "$TD" | jq -r '.taskDefinition.containerDefinitions[0].image')
CD=$(echo "$TD" | jq -c '.taskDefinition.containerDefinitions[0]')
[ "$TD_STATUS" = "ACTIVE" ] || { echo "STOP: $SCHED_TD_ARN is not ACTIVE." >&2; exit 1; }
[ "$TD_FAMILY" = "firmsbase-staging-scheduler" ] || { echo "STOP: family mismatch." >&2; exit 1; }
[ "$TD_IMAGE" = "$APPROVED_IMAGE" ] || { echo "STOP: image mismatch." >&2; exit 1; }
validate_runtime_secrets "$CD" "scheduler (pre-service-creation, exact ARN)" || exit 1
validate_scheduler_shape "$CD" || exit 1
echo "scheduler task definition fully re-verified against its exact ARN."

echo ""
echo "=== Step 5: confirm zero existing scheduler service ==="
EXISTING=$(aws ecs describe-services --region "$REGION" --cluster "$CLUSTER" --services scheduler --query 'services[?status!=`INACTIVE`]' --output json 2>/dev/null || echo "[]")
[ "$(echo "$EXISTING" | jq 'length')" = "0" ] || { echo "STOP: a scheduler service already exists." >&2; exit 1; }

echo ""
echo "=== Step 6: create the scheduler service using the EXACT registered ARN (desiredCount MUST stay 1) ==="
aws ecs create-service \
  --region "$REGION" \
  --cluster "$CLUSTER" \
  --service-name scheduler \
  --task-definition "$SCHED_TD_ARN" \
  --desired-count 1 \
  --launch-type FARGATE \
  --platform-version LATEST \
  --network-configuration "awsvpcConfiguration={subnets=[$SUBNETS],securityGroups=[$SG],assignPublicIp=ENABLED}" \
  --deployment-configuration "deploymentCircuitBreaker={enable=true,rollback=true},minimumHealthyPercent=0,maximumPercent=100" \
  --disable-execute-command \
  --tags \
    "key=Application,value=FirmsBase" \
    "key=Environment,value=staging" \
    "key=ManagedBy,value=manual-reviewed-deployment" \
    "key=SourceCommit,value=${APPROVED_SOURCE_COMMIT}" \
    "key=ImageDigest,value=sha256:92eecbeeef5225fcfe0f4256a0b375a773cd84c1f4974fc08b137811a27e46fd" \
  >/dev/null
echo "scheduler service created using exact ARN $SCHED_TD_ARN."

echo ""
echo "=== Step 7: wait for stability (guarded), assert full deployment state ==="
if ! timeout "$STABLE_WAIT_TIMEOUT" aws ecs wait services-stable --region "$REGION" --cluster "$CLUSTER" --services scheduler; then
  echo "STOP: scheduler did not stabilize." >&2
  aws ecs describe-services --region "$REGION" --cluster "$CLUSTER" --services scheduler \
    | jq -r '.services[0].deployments[] | "Deployment status: \(.status) desired=\(.desiredCount) running=\(.runningCount) pending=\(.pendingCount)"' >&2
  echo "This was the last service in the launch order — no further service was attempted. Containment:" >&2
  echo "  aws ecs update-service --cluster $CLUSTER --service scheduler --desired-count 0" >&2
  echo "  aws ecs delete-service --cluster $CLUSTER --service scheduler --force" >&2
  exit 1
fi
SVC_AFTER=$(aws ecs describe-services --region "$REGION" --cluster "$CLUSTER" --services scheduler)
assert_deployment_state "$SVC_AFTER" "scheduler" || { echo "STOP: deployment-state assertions failed." >&2; exit 1; }
echo "scheduler deployment state fully confirmed."

echo ""
echo "=== Step 8: deep health inspection (current + stopped tasks + service events) ==="
inspect_service_health scheduler scheduler || { echo "STOP: scheduler health inspection failed." >&2; exit 1; }

echo ""
echo "=== Step 9: confirm the task survives at least 2 minutes without restart ==="
TASK_ARN=$(aws ecs list-tasks --region "$REGION" --cluster "$CLUSTER" --service-name scheduler --desired-status RUNNING --query 'taskArns[0]' --output text)
echo "Task ARN: $TASK_ARN"
STARTED_1=$(aws ecs describe-tasks --region "$REGION" --cluster "$CLUSTER" --tasks "$TASK_ARN" --query 'tasks[0].startedAt' --output text)
echo "startedAt (t=0): $STARTED_1"
sleep 120
STARTED_2=$(aws ecs describe-tasks --region "$REGION" --cluster "$CLUSTER" --tasks "$TASK_ARN" --query 'tasks[0].startedAt' --output text)
STATUS_2=$(aws ecs describe-tasks --region "$REGION" --cluster "$CLUSTER" --tasks "$TASK_ARN" --query 'tasks[0].lastStatus' --output text)
echo "startedAt (t=120s): $STARTED_2   lastStatus: $STATUS_2"
if [ "$STARTED_1" != "$STARTED_2" ] || [ "$STATUS_2" != "RUNNING" ]; then
  echo "STOP: scheduler task restarted or is no longer RUNNING (schedule:work -> schedule:run /bin/sh dependency may have regressed)." >&2
  exit 1
fi
echo "No restart detected over 2 minutes."

echo ""
echo "=== scheduler launched and stable — all four runtime services are now up. Proceed to 07-final-runtime-verification.sh next. ==="
echo "SCHEDULER_VERIFIED"
