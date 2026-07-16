#!/usr/bin/env bash
# =============================================================================
# WEB SERVICE LAUNCH — NOT EXECUTED. Run only after
# 01-register-runtime-task-definitions.sh has succeeded and written
# runtime-task-definitions.manifest.json. Creates ONLY the web service.
# Never creates worker/critical-worker/scheduler, never runs a migration,
# never registers another task definition, never modifies RDS/Redis.
# =============================================================================
set -euo pipefail
set +x
export AWS_PAGER=""

CLUSTER=firmsbase-staging-cluster
REGION=us-east-1
SUBNETS=subnet-07efcb5d4bcf5aa59,subnet-020540b8377bb4d0e
SG=sg-0db14e50ea5c5466c
VPC_ID=vpc-0fd81b688155ded2b
LOG_GROUP=/ecs/firmsbase-staging/app
EXPECTED_ACCOUNT=603013471426
EXPECTED_ARN="arn:aws:iam::603013471426:user/firmsbase-staging-operator"
APPROVED_IMAGE="603013471426.dkr.ecr.us-east-1.amazonaws.com/firmsbase-staging@sha256:92eecbeeef5225fcfe0f4256a0b375a773cd84c1f4974fc08b137811a27e46fd"
TG_ARN="arn:aws:elasticloadbalancing:us-east-1:603013471426:targetgroup/firmsbase-staging-tg/1830c01b9aaac37d"
ALB_NAME=firmsbase-staging-alb
MANIFEST_FILE=runtime-task-definitions.manifest.json
EXPOSURE_EVIDENCE_FILE=00-http-exposure-preflight-evidence.json
STABLE_WAIT_TIMEOUT=300
APPROVED_SOURCE_COMMIT=6a1affdaad2bc1c4a48c5e411b9e39056039cde9

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# (Identical to 01's function — see that file for full commentary.)
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
    [ "$matched" = "$expected" ] || { echo "STOP ($context): $var selector does not match exactly." >&2; fail=1; }
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
  echo "$all_secret_arns" | grep -qiE "rds-master|master-secret|mastersecret" && { echo "STOP ($context): master-secret-like reference found." >&2; fail=1; }
  local redis_host redis_port
  redis_host=$(echo "$cd_json" | jq -r '.environment[]? | select(.name=="REDIS_HOST") | .value')
  redis_port=$(echo "$cd_json" | jq -r '.environment[]? | select(.name=="REDIS_PORT") | .value')
  case "$redis_host" in tls://*) ;; *) echo "STOP ($context): REDIS_HOST is not tls://." >&2; fail=1 ;; esac
  [ "$redis_port" = "6379" ] || { echo "STOP ($context): REDIS_PORT is not 6379." >&2; fail=1; }
  return $fail
}

validate_web_shape() {
  local cd_json="$1"
  local fail=0
  local cmd name ports
  cmd=$(echo "$cd_json" | jq -c '.command')
  [ "$cmd" = '["web"]' ] || { echo "STOP (web): command is not exactly [\"web\"]." >&2; fail=1; }
  name=$(echo "$cd_json" | jq -r '.name')
  [ "$name" = "app" ] || { echo "STOP (web): container name is not 'app'." >&2; fail=1; }
  ports=$(echo "$cd_json" | jq -c '.portMappings // []')
  [ "$(echo "$ports" | jq 'length')" = "1" ] || { echo "STOP (web): expected exactly one port mapping." >&2; fail=1; }
  if [ "$(echo "$ports" | jq 'length')" = "1" ]; then
    local cport hport proto
    cport=$(echo "$ports" | jq -r '.[0].containerPort')
    hport=$(echo "$ports" | jq -r '.[0].hostPort // empty')
    proto=$(echo "$ports" | jq -r '.[0].protocol')
    [ "$cport" = "8080" ] || { echo "STOP (web): containerPort is not 8080." >&2; fail=1; }
    [ -z "$hport" ] || [ "$hport" = "8080" ] || { echo "STOP (web): hostPort present and not 8080." >&2; fail=1; }
    [ "$proto" = "tcp" ] || { echo "STOP (web): protocol is not tcp." >&2; fail=1; }
  fi
  return $fail
}

# -----------------------------------------------------------------------------
# Blocker 9: assert the full deployment state, not just deployments.length=1.
# -----------------------------------------------------------------------------
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
  if echo "$events" | grep -qiE "circuit breaker.*(fail|rollback)"; then
    echo "STOP ($name): a circuit-breaker failure/rollback event was found: $events" >&2
    fail=1
  fi
  return $fail
}

echo "=== Step 1: verify caller identity ==="
IDENTITY=$(aws sts get-caller-identity --region "$REGION")
CALLER_ACCOUNT=$(echo "$IDENTITY" | jq -r '.Account')
CALLER_ARN=$(echo "$IDENTITY" | jq -r '.Arn')
[ "$CALLER_ACCOUNT" = "$EXPECTED_ACCOUNT" ] || { echo "STOP: wrong account." >&2; exit 1; }
[ "$CALLER_ARN" = "$EXPECTED_ARN" ] || { echo "STOP: caller ARN is not the expected restricted operator." >&2; exit 1; }

echo ""
echo "=== Step 2: require the saved HTTP-exposure preflight evidence, then re-run it LIVE ==="
[ -f "$EXPOSURE_EVIDENCE_FILE" ] || { echo "STOP: $EXPOSURE_EVIDENCE_FILE not found — run 00-http-exposure-preflight.sh first." >&2; exit 1; }
jq empty "$EXPOSURE_EVIDENCE_FILE" || { echo "STOP: $EXPOSURE_EVIDENCE_FILE is not valid JSON." >&2; exit 1; }
echo "Saved exposure evidence found (verdict: $(jq -r '.verdict' "$EXPOSURE_EVIDENCE_FILE")). Re-running the live checks now — never trusting the saved file alone."
# shellcheck source=00-http-exposure-preflight.sh
source "${SCRIPT_DIR}/00-http-exposure-preflight.sh"
run_http_exposure_checks || { echo "STOP: live HTTP-exposure preflight re-check failed." >&2; exit 1; }
LIVE_VERDICT=$(jq -r '.verdict' "$EXPOSURE_EVIDENCE_FILE")
echo "Live re-check verdict: $LIVE_VERDICT"

echo ""
echo "=== Step 3: verify cluster ACTIVE ==="
CLUSTER_STATUS=$(aws ecs describe-clusters --region "$REGION" --clusters "$CLUSTER" --query 'clusters[0].status' --output text)
[ "$CLUSTER_STATUS" = "ACTIVE" ] || { echo "STOP: cluster is not ACTIVE." >&2; exit 1; }
echo "Cluster ACTIVE."

echo ""
echo "=== Step 4: read the exact web ARN from the manifest and verify it ==="
[ -f "$MANIFEST_FILE" ] || { echo "STOP: $MANIFEST_FILE not found — run 01-register-runtime-task-definitions.sh first." >&2; exit 1; }
jq empty "$MANIFEST_FILE" || { echo "STOP: manifest is not valid JSON." >&2; exit 1; }
WEB_TD_ARN=$(jq -r '.web' "$MANIFEST_FILE")
[ -n "$WEB_TD_ARN" ] && [ "$WEB_TD_ARN" != "null" ] || { echo "STOP: manifest has no 'web' entry." >&2; exit 1; }
echo "web ARN from manifest: $WEB_TD_ARN"

TD=$(aws ecs describe-task-definition --region "$REGION" --task-definition "$WEB_TD_ARN")
TD_STATUS=$(echo "$TD" | jq -r '.taskDefinition.status')
TD_FAMILY=$(echo "$TD" | jq -r '.taskDefinition.family')
TD_REVISION=$(echo "$TD" | jq -r '.taskDefinition.revision')
TD_IMAGE=$(echo "$TD" | jq -r '.taskDefinition.containerDefinitions[0].image')
CD=$(echo "$TD" | jq -c '.taskDefinition.containerDefinitions[0]')
echo "Family: $TD_FAMILY  Revision: $TD_REVISION  Status: $TD_STATUS"
[ "$TD_STATUS" = "ACTIVE" ] || { echo "STOP: $WEB_TD_ARN is not ACTIVE." >&2; exit 1; }
[ "$TD_FAMILY" = "firmsbase-staging-web" ] || { echo "STOP: manifest ARN family is not firmsbase-staging-web." >&2; exit 1; }
[ "$TD_IMAGE" = "$APPROVED_IMAGE" ] || { echo "STOP: image does not match the approved digest." >&2; exit 1; }
validate_runtime_secrets "$CD" "web (pre-service-creation, exact ARN)" || exit 1
validate_web_shape "$CD" || exit 1
echo "web task definition fully re-verified against its exact ARN."

echo ""
echo "=== Step 5: ALB / target-group preflight ==="
TG_DESC=$(aws elbv2 describe-target-groups --region "$REGION" --target-group-arns "$TG_ARN")
TG_ACTUAL_ARN=$(echo "$TG_DESC" | jq -r '.TargetGroups[0].TargetGroupArn')
TG_TYPE=$(echo "$TG_DESC" | jq -r '.TargetGroups[0].TargetType')
TG_VPC=$(echo "$TG_DESC" | jq -r '.TargetGroups[0].VpcId')
TG_PROTOCOL=$(echo "$TG_DESC" | jq -r '.TargetGroups[0].Protocol')
TG_PORT=$(echo "$TG_DESC" | jq -r '.TargetGroups[0].Port')
TG_HEALTH_PATH=$(echo "$TG_DESC" | jq -r '.TargetGroups[0].HealthCheckPath')
TG_MATCHER=$(echo "$TG_DESC" | jq -r '.TargetGroups[0].Matcher.HttpCode')
TG_LB_ARNS=$(echo "$TG_DESC" | jq -r '.TargetGroups[0].LoadBalancerArns[0]')
echo "TargetGroupArn: $TG_ACTUAL_ARN"
echo "TargetType: $TG_TYPE  VpcId: $TG_VPC  Protocol: $TG_PROTOCOL  Port: $TG_PORT"
echo "HealthCheckPath: $TG_HEALTH_PATH  Matcher: $TG_MATCHER"

fail=0
[ "$TG_ACTUAL_ARN" = "$TG_ARN" ] || { echo "STOP: target group ARN does not match the reviewed ARN." >&2; fail=1; }
[ "$TG_TYPE" = "ip" ] || { echo "STOP: target type is not 'ip'." >&2; fail=1; }
[ "$TG_VPC" = "$VPC_ID" ] || { echo "STOP: target group VPC does not match the ECS VPC ($VPC_ID)." >&2; fail=1; }
[ "$TG_PROTOCOL" = "HTTP" ] && [ "$TG_PORT" = "8080" ] || { echo "STOP: target group protocol/port is not HTTP:8080." >&2; fail=1; }
[ "$TG_HEALTH_PATH" = "/up" ] || { echo "STOP: health-check path is not /up." >&2; fail=1; }
echo "$TG_MATCHER" | grep -q "200" || { echo "STOP: success matcher does not include 200." >&2; fail=1; }

LISTENERS=$(aws elbv2 describe-listeners --region "$REGION" --load-balancer-arn "$TG_LB_ARNS")
ROUTES_HERE=$(echo "$LISTENERS" | jq --arg tg "$TG_ARN" '[.Listeners[] | select(.DefaultActions[]?.TargetGroupArn == $tg)] | length')
[ "$ROUTES_HERE" -ge "1" ] || { echo "STOP: no listener's default action routes to the reviewed target group." >&2; fail=1; }
echo "Listener default-action routing confirmed ($ROUTES_HERE listener(s))."

EXISTING_TARGETS=$(aws elbv2 describe-target-health --region "$REGION" --target-group-arn "$TG_ARN" --query 'TargetHealthDescriptions' --output json)
EXISTING_COUNT=$(echo "$EXISTING_TARGETS" | jq 'length')
[ "$EXISTING_COUNT" = "0" ] || { echo "STOP: an unexpected target is already registered in the target group ($EXISTING_COUNT found)." >&2; fail=1; }
echo "No pre-existing targets registered (expected before first launch)."

[ "$fail" = "0" ] || exit 1

echo ""
echo "=== Step 6: confirm zero existing services ==="
SERVICE_ARNS=$(aws ecs list-services --region "$REGION" --cluster "$CLUSTER" --query 'serviceArns' --output json)
[ "$(echo "$SERVICE_ARNS" | jq 'length')" = "0" ] || { echo "STOP: at least one ECS service already exists." >&2; exit 1; }

echo ""
echo "=== Step 7: confirm zero running or pending tasks ==="
RUNNING=$(aws ecs list-tasks --region "$REGION" --cluster "$CLUSTER" --desired-status RUNNING --query 'taskArns' --output json)
PENDING=$(aws ecs list-tasks --region "$REGION" --cluster "$CLUSTER" --desired-status PENDING --query 'taskArns' --output json)
[ "$(echo "$RUNNING" | jq 'length')" = "0" ] && [ "$(echo "$PENDING" | jq 'length')" = "0" ] || { echo "STOP: at least one task already running or pending." >&2; exit 1; }

echo ""
echo "=== Step 8: acknowledgements ==="
[ "${CONFIRMED_ECS_STOPTASK:-}" = "yes" ] || { echo "STOP: CONFIRMED_ECS_STOPTASK is not set to 'yes'." >&2; exit 1; }
[ "${CONFIRMED_WEB_SERVICE_LAUNCH:-}" = "yes" ] || { echo "STOP: CONFIRMED_WEB_SERVICE_LAUNCH is not set to 'yes'." >&2; exit 1; }
if [ "$LIVE_VERDICT" = "HTTP_PUBLIC_EXPOSURE_CONFIRMED" ]; then
  echo "HTTP_PUBLIC_EXPOSURE_CONFIRMED (live re-check) — an additional acknowledgement is required."
  if [ "${CONFIRMED_PUBLIC_HTTP_SYNTHETIC_TESTING:-}" != "yes" ]; then
    echo "STOP: CONFIRMED_PUBLIC_HTTP_SYNTHETIC_TESTING is not set to 'yes'." >&2
    echo "The ALB's HTTP:80 listener is technically reachable by the public internet." >&2
    echo "Setting this to 'yes' represents that: no real user traffic is authorized;" >&2
    echo "no client data may be used; no firm invitations may be sent; HTTPS remains a" >&2
    echo "mandatory subsequent gate before any production approval." >&2
    exit 1
  fi
  echo "CONFIRMED_PUBLIC_HTTP_SYNTHETIC_TESTING=yes acknowledged."
fi
echo "Acknowledgements confirmed."

echo ""
echo "=== Step 9: create the web service using the EXACT registered ARN ==="
aws ecs create-service \
  --region "$REGION" \
  --cluster "$CLUSTER" \
  --service-name web \
  --task-definition "$WEB_TD_ARN" \
  --desired-count 1 \
  --launch-type FARGATE \
  --platform-version LATEST \
  --network-configuration "awsvpcConfiguration={subnets=[$SUBNETS],securityGroups=[$SG],assignPublicIp=ENABLED}" \
  --load-balancers "targetGroupArn=${TG_ARN},containerName=app,containerPort=8080" \
  --health-check-grace-period-seconds 60 \
  --deployment-configuration "deploymentCircuitBreaker={enable=true,rollback=true},minimumHealthyPercent=0,maximumPercent=100" \
  --disable-execute-command \
  --tags \
    "key=Application,value=FirmsBase" \
    "key=Environment,value=staging" \
    "key=ManagedBy,value=manual-reviewed-deployment" \
    "key=SourceCommit,value=${APPROVED_SOURCE_COMMIT}" \
    "key=ImageDigest,value=sha256:92eecbeeef5225fcfe0f4256a0b375a773cd84c1f4974fc08b137811a27e46fd" \
  >/dev/null
echo "web service created (desired count 1) using exact ARN $WEB_TD_ARN."

echo ""
echo "=== Step 10: wait for service stability (guarded), then assert full deployment state ==="
if ! timeout "$STABLE_WAIT_TIMEOUT" aws ecs wait services-stable --region "$REGION" --cluster "$CLUSTER" --services web; then
  echo "STOP: web service did not stabilize within ${STABLE_WAIT_TIMEOUT}s (or the waiter failed)." >&2
  DESC=$(aws ecs describe-services --region "$REGION" --cluster "$CLUSTER" --services web)
  echo "$DESC" | jq -r '.services[0].deployments[] | "Deployment status: \(.status) rolloutState=\(.rolloutState // "n/a") desired=\(.desiredCount) running=\(.runningCount) pending=\(.pendingCount)"' >&2

  STOPPED_ARNS=$(aws ecs list-tasks --region "$REGION" --cluster "$CLUSTER" --service-name web --desired-status STOPPED --query 'taskArns' --output json)
  echo "Stopped task ARNs: $STOPPED_ARNS" >&2
  for arn in $(echo "$STOPPED_ARNS" | jq -r '.[]'); do
    reason=$(aws ecs describe-tasks --region "$REGION" --cluster "$CLUSTER" --tasks "$arn" --query 'tasks[0].stoppedReason // "none"' --output text)
    echo "Stopped reason ($arn): $reason" >&2
    task_id=$(echo "$arn" | awk -F/ '{print $NF}')
    aws logs tail "$LOG_GROUP" --region "$REGION" --log-stream-names "web/app/${task_id}" --since 15m 2>/dev/null \
      | grep -E "^\[entrypoint\]|^\[web\]|FATAL|ERROR|Exception" \
      | grep -viE "password|secret|DB_PASSWORD|REDIS_PASSWORD|://[^ ]*:[^ ]*@" >&2 || true
  done

  TARGET_HEALTH=$(aws elbv2 describe-target-health --region "$REGION" --target-group-arn "$TG_ARN" --query 'TargetHealthDescriptions[].{Target:Target.Id,State:TargetHealth.State,Reason:TargetHealth.Reason}')
  echo "Target health: $TARGET_HEALTH" >&2

  echo "" >&2
  echo "Do not launch critical-worker/worker/scheduler. Containment:" >&2
  echo "  aws ecs update-service --cluster $CLUSTER --service web --desired-count 0" >&2
  echo "  aws ecs delete-service --cluster $CLUSTER --service web --force" >&2
  exit 1
fi

SVC_AFTER=$(aws ecs describe-services --region "$REGION" --cluster "$CLUSTER" --services web)
assert_deployment_state "$SVC_AFTER" "web" || {
  echo "STOP: web reached the waiter's notion of 'stable' but failed explicit deployment-state assertions." >&2
  exit 1
}
echo "web deployment state fully confirmed (not inferred from deployment count alone)."

echo ""
echo "=== Step 11: post-launch target-group health (exact counts required) ==="
POST_TARGETS=$(aws elbv2 describe-target-health --region "$REGION" --target-group-arn "$TG_ARN")
TOTAL=$(echo "$POST_TARGETS" | jq '.TargetHealthDescriptions | length')
HEALTHY=$(echo "$POST_TARGETS" | jq '[.TargetHealthDescriptions[] | select(.TargetHealth.State == "healthy")] | length')
OTHER=$(echo "$POST_TARGETS" | jq '[.TargetHealthDescriptions[] | select(.TargetHealth.State != "healthy")] | length')
echo "Target count: $TOTAL  healthy: $HEALTHY  other-state: $OTHER"
if [ "$TOTAL" = "0" ]; then
  echo "STOP: target group is empty — an empty target list is never treated as a pass." >&2
  exit 1
fi
[ "$TOTAL" = "1" ] || { echo "STOP: expected exactly one target description, found $TOTAL." >&2; exit 1; }
[ "$HEALTHY" = "1" ] || { echo "STOP: expected exactly one healthy target, found $HEALTHY." >&2; exit 1; }
[ "$OTHER" = "0" ] || { echo "STOP: expected zero unhealthy/initial/draining/unused/unavailable targets, found $OTHER." >&2; exit 1; }

echo ""
echo "=== Web service created, stable, and target-group health fully confirmed. HTTPS is not configured — see https-remediation-plan.md. Proceed to 03-verify-web-health.sh next. ==="
