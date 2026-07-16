#!/usr/bin/env bash
# NOT EXECUTED. Review-only. Run in CloudShell, region us-east-1, account
# 603013471426. This is step 1 of the entire deployment — nothing else in
# this package may run before this succeeds with exit code 0.
#
# Do NOT run migrations with firmsbase_app. This task definition uses
# firmsbase_migrator + the database-migrator secret exclusively.
set -euo pipefail

CLUSTER=firmsbase-staging-cluster
SUBNETS=subnet-07efcb5d4bcf5aa59,subnet-020540b8377bb4d0e
SG=sg-0db14e50ea5c5466c

# ---------------------------------------------------------------------------
# Step 1: register ONLY the migrate task definition (no other family yet).
# ---------------------------------------------------------------------------
aws ecs register-task-definition \
  --cli-input-json file://firmsbase-staging-migrate.json

# ---------------------------------------------------------------------------
# Step 2: run it once as a standalone Fargate task (NOT a service).
# ---------------------------------------------------------------------------
RUN_TASK_OUTPUT=$(aws ecs run-task \
  --cluster "$CLUSTER" \
  --launch-type FARGATE \
  --platform-version LATEST \
  --task-definition firmsbase-staging-migrate \
  --network-configuration "awsvpcConfiguration={subnets=[$SUBNETS],securityGroups=[$SG],assignPublicIp=ENABLED}" \
  --count 1)

echo "$RUN_TASK_OUTPUT"

TASK_ARN=$(echo "$RUN_TASK_OUTPUT" | jq -r '.tasks[0].taskArn')
echo "Migration task ARN: $TASK_ARN"

if [ -z "$TASK_ARN" ] || [ "$TASK_ARN" = "null" ]; then
  echo "FAILED TO START TASK. Check failures[] in the run-task output above. STOP." >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# Step 3: wait for the task to stop (blocks until STOPPED).
# ---------------------------------------------------------------------------
aws ecs wait tasks-stopped --cluster "$CLUSTER" --tasks "$TASK_ARN"

# ---------------------------------------------------------------------------
# Step 4: describe the stopped task — capture stop code, stopped reason,
# container exit code, container reason, and the log stream name.
# ---------------------------------------------------------------------------
DESCRIBE_OUTPUT=$(aws ecs describe-tasks --cluster "$CLUSTER" --tasks "$TASK_ARN")
echo "$DESCRIBE_OUTPUT"

STOP_CODE=$(echo "$DESCRIBE_OUTPUT" | jq -r '.tasks[0].stopCode')
STOPPED_REASON=$(echo "$DESCRIBE_OUTPUT" | jq -r '.tasks[0].stoppedReason')
EXIT_CODE=$(echo "$DESCRIBE_OUTPUT" | jq -r '.tasks[0].containers[0].exitCode')
CONTAINER_REASON=$(echo "$DESCRIBE_OUTPUT" | jq -r '.tasks[0].containers[0].reason // "none"')

TASK_ID=$(echo "$TASK_ARN" | awk -F/ '{print $NF}')
LOG_STREAM="migrate/app/${TASK_ID}"

echo "stopCode=$STOP_CODE stoppedReason=$STOPPED_REASON exitCode=$EXIT_CODE containerReason=$CONTAINER_REASON"
echo "logStream=$LOG_STREAM"

# ---------------------------------------------------------------------------
# Step 5: tail ONLY this task's log stream (never the whole log group).
# ---------------------------------------------------------------------------
aws logs tail "/ecs/firmsbase-staging/app" --log-stream-names "$LOG_STREAM" --since 30m

# ---------------------------------------------------------------------------
# Step 6: gate. Proceed to runtime-verification-commands.sh ONLY if:
#   - exitCode == 0
#   - stopCode == "EssentialContainerExited" (normal stop, not a task-level
#     failure such as ResourceInitializationError or user-initiated stop)
#   - the tailed log shows migration success (e.g. "Migrating:" / "Migrated:"
#     / "Nothing to migrate" lines, no exception stack trace)
#   - no RLS / permission-denied / must-be-owner-of-relation error text
#     anywhere in the tailed log
#
# On ANY of: nonzero exit code, missing/null exit code, non-normal stopCode,
# or any RLS/permission/ownership error text in the logs:
#   - STOP HERE.
#   - Do NOT register or create the web/worker/critical-worker/scheduler
#     services.
#   - Do NOT retry run-task automatically.
#   - Report: TASK_ARN, stopCode, stoppedReason, exitCode, containerReason,
#     and the relevant tailed log lines, for human review.
# ---------------------------------------------------------------------------
if [ "$EXIT_CODE" != "0" ]; then
  echo "MIGRATION FAILED (exitCode=$EXIT_CODE). STOP. Do not proceed to runtime deployment." >&2
  exit 1
fi

echo "Migration exited 0. Manually confirm log content above shows a clean migration before proceeding to runtime-verification-commands.sh."
