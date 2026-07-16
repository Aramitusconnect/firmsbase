#!/usr/bin/env bash
# =============================================================================
# WEB HEALTH VERIFICATION — READ-ONLY, NOT EXECUTED. Run only after
# 02-launch-web-service.sh reports the web service stable. Creates
# nothing, modifies nothing.
#
# HTTPS IS NOT CONFIGURED YET. All checks here are synthetic, HTTP-only,
# staging-internal verification. A pass here is NOT production approval
# and does NOT authorize real client traffic — see
# staging-deploy/https-remediation-plan.md.
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

FAIL=0
fail() { echo "FAIL: $1" >&2; FAIL=1; }

echo "=== Check 0: verify caller identity ==="
IDENTITY=$(aws sts get-caller-identity --region "$REGION")
CALLER_ACCOUNT=$(echo "$IDENTITY" | jq -r '.Account')
CALLER_ARN=$(echo "$IDENTITY" | jq -r '.Arn')
echo "Account: $CALLER_ACCOUNT"
echo "Arn: $CALLER_ARN"
[ "$CALLER_ACCOUNT" = "$EXPECTED_ACCOUNT" ] || { echo "STOP: wrong account." >&2; exit 1; }
[ "$CALLER_ARN" = "$EXPECTED_ARN" ] || { echo "STOP: caller ARN is not the expected restricted operator." >&2; exit 1; }

echo ""
echo "=== Check 1: exactly one web service, full deployment state (not inferred from deployment count alone) ==="
SVC=$(aws ecs describe-services --region "$REGION" --cluster "$CLUSTER" --services web)
SVC_COUNT=$(echo "$SVC" | jq '.services | length')
[ "$SVC_COUNT" = "1" ] || fail "expected exactly 1 'web' service, found $SVC_COUNT"
DESIRED=$(echo "$SVC" | jq -r '.services[0].desiredCount')
RUNNING=$(echo "$SVC" | jq -r '.services[0].runningCount')
PENDING=$(echo "$SVC" | jq -r '.services[0].pendingCount')
DEPLOY_COUNT=$(echo "$SVC" | jq -r '.services[0].deployments | length')
DEPLOY_STATUS=$(echo "$SVC" | jq -r '.services[0].deployments[0].status // "none"')
ROLLOUT=$(echo "$SVC" | jq -r '.services[0].deployments[0].rolloutState // "none"')
EVENTS=$(echo "$SVC" | jq -r '[.services[0].events[0:5][]?.message] | join(" | ")')
echo "desired=$DESIRED running=$RUNNING pending=$PENDING deployments=$DEPLOY_COUNT status=$DEPLOY_STATUS rolloutState=$ROLLOUT"
[ "$DESIRED" = "1" ] || fail "desiredCount is not 1"
[ "$RUNNING" = "1" ] || fail "runningCount is not 1"
[ "$PENDING" = "0" ] || fail "pendingCount is not 0"
[ "$DEPLOY_COUNT" = "1" ] || fail "expected exactly one deployment"
[ "$DEPLOY_STATUS" = "PRIMARY" ] || fail "deployment status is not PRIMARY"
[ "$ROLLOUT" = "COMPLETED" ] || fail "rolloutState is not COMPLETED"
echo "$EVENTS" | grep -qiE "circuit breaker.*(fail|rollback)" && fail "a circuit-breaker failure/rollback event was found: $EVENTS"

echo ""
echo "=== Check 2: web load-balancer entry uses the reviewed target group, container app, port 8080 ==="
LB_ENTRY=$(echo "$SVC" | jq -c '.services[0].loadBalancers[0] // empty')
if [ -z "$LB_ENTRY" ]; then
  fail "web service has no load-balancer entry at all"
else
  LB_TG=$(echo "$LB_ENTRY" | jq -r '.targetGroupArn')
  LB_CONTAINER=$(echo "$LB_ENTRY" | jq -r '.containerName')
  LB_PORT=$(echo "$LB_ENTRY" | jq -r '.containerPort')
  echo "loadBalancer entry: targetGroupArn=$LB_TG containerName=$LB_CONTAINER containerPort=$LB_PORT"
  [ "$LB_TG" = "$TG_ARN" ] || fail "load-balancer entry does not use the reviewed target-group ARN"
  [ "$LB_CONTAINER" = "app" ] || fail "load-balancer entry container name is not 'app'"
  [ "$LB_PORT" = "8080" ] || fail "load-balancer entry container port is not 8080"
fi

echo ""
echo "=== Check 3: running task uses the exact manifest ARN and the approved digest ==="
TASK_ARN=$(aws ecs list-tasks --region "$REGION" --cluster "$CLUSTER" --service-name web --desired-status RUNNING --query 'taskArns[0]' --output text)
if [ -z "$TASK_ARN" ] || [ "$TASK_ARN" = "None" ]; then
  fail "no RUNNING web task found"
else
  TASK_DESC=$(aws ecs describe-tasks --region "$REGION" --cluster "$CLUSTER" --tasks "$TASK_ARN")
  TASK_TD_ARN=$(echo "$TASK_DESC" | jq -r '.tasks[0].taskDefinitionArn')
  TASK_IMAGE=$(echo "$TASK_DESC" | jq -r '.tasks[0].containers[] | select(.name=="app") | .image')
  TASK_STATUS=$(echo "$TASK_DESC" | jq -r '.tasks[0].lastStatus')
  echo "Task ARN: $TASK_ARN"
  echo "Task-definition ARN: $TASK_TD_ARN"
  echo "Image: $TASK_IMAGE"
  echo "Last status: $TASK_STATUS"
  if [ -f "$MANIFEST_FILE" ]; then
    MANIFEST_WEB_ARN=$(jq -r '.web' "$MANIFEST_FILE")
    [ "$TASK_TD_ARN" = "$MANIFEST_WEB_ARN" ] || fail "running task's task-definition ARN does not match the manifest's exact web ARN"
  fi
  [ "$TASK_IMAGE" = "$APPROVED_IMAGE" ] || fail "running task image does not match the approved digest"
  [ "$TASK_STATUS" = "RUNNING" ] || fail "running task lastStatus is not RUNNING"
fi

echo ""
echo "=== Check 4: ALB target — exactly one, healthy, no other states ==="
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
echo "=== Check 5: /up and /readyz over HTTP (synthetic only) ==="
ALB_DNS=$(aws elbv2 describe-load-balancers --region "$REGION" --names "$ALB_NAME" --query 'LoadBalancers[0].DNSName' --output text)
echo "ALB DNS: $ALB_DNS"

UP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://${ALB_DNS}/up" || echo "000")
echo "/up status: $UP_CODE"
[ "$UP_CODE" = "200" ] || fail "/up did not return 200 (got $UP_CODE)"

READYZ_BODY=$(curl -s "http://${ALB_DNS}/readyz" || echo '{}')
READYZ_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://${ALB_DNS}/readyz" || echo "000")
echo "/readyz status: $READYZ_CODE"
[ "$READYZ_CODE" = "200" ] || fail "/readyz did not return 200 (got $READYZ_CODE)"

# ReadinessController's response body only ever contains the fixed tokens
# "ok"/"error" per check — never a connection string, exception message,
# or secret.
DB_CHECK=$(echo "$READYZ_BODY" | jq -r '.checks.database // "missing"')
REDIS_CHECK=$(echo "$READYZ_BODY" | jq -r '.checks.redis // "not_required"')
echo "readyz database check: $DB_CHECK"
echo "readyz redis check: $REDIS_CHECK"
[ "$DB_CHECK" = "ok" ] || fail "/readyz database check is not ok"
[ "$REDIS_CHECK" = "ok" ] || [ "$REDIS_CHECK" = "not_required" ] || fail "/readyz redis check is not ok"

echo ""
echo "=== Check 6: recent CloudWatch logs free of high-severity markers ==="
if [ -n "${TASK_ARN:-}" ] && [ "$TASK_ARN" != "None" ]; then
  TASK_ID=$(echo "$TASK_ARN" | awk -F/ '{print $NF}')
  RECENT=$(aws logs tail "$LOG_GROUP" --region "$REGION" --log-stream-names "web/app/${TASK_ID}" --since 15m 2>/dev/null || echo "")
  BAD=$(printf '%s\n' "$RECENT" | grep -icE "fatal error|unhandled exception|SQLSTATE|redis.*(auth|connection).*fail|permission denied|secret.*inject.*fail|queue connection.*fail" || true)
  RESTART_HINTS=$(printf '%s\n' "$RECENT" | grep -c "starting role 'web'" || true)
  echo "high-severity marker count: $BAD"
  echo "entrypoint-start count (>1 suggests a restart loop): $RESTART_HINTS"
  [ "$BAD" = "0" ] || fail "recent logs contain a high-severity marker"
  [ "$RESTART_HINTS" -le "1" ] || fail "recent logs show more than one entrypoint start — possible restart loop"
fi

echo ""
if [ "$FAIL" = "1" ]; then
  echo "STOP: one or more web health checks failed. See FAIL lines above." >&2
  echo "This is HTTP-only synthetic verification. Even on success, no real client" >&2
  echo "traffic is authorized until HTTPS is configured — see https-remediation-plan.md." >&2
  exit 1
fi

echo "All web health checks passed (HTTP-only synthetic verification; not production approval)."
echo "WEB_SERVICE_VERIFIED"
echo "HTTP_SYNTHETIC_ONLY_NOT_PRODUCTION_APPROVAL"
