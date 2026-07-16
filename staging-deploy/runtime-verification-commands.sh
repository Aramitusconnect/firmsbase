#!/usr/bin/env bash
# NOT EXECUTED. Review-only. Run in CloudShell, region us-east-1, account
# 603013471426. Run ONLY after migration-sequence.sh has completed with
# exit code 0 and a manually-confirmed clean log. Services are created ONE
# AT A TIME, each verified before the next is started. Do not create all
# four services simultaneously.
set -euo pipefail

CLUSTER=firmsbase-staging-cluster
TG_ARN="arn:aws:elasticloadbalancing:us-east-1:603013471426:targetgroup/firmsbase-staging-tg/1830c01b9aaac37d"
ALB_DNS="<paste from live-state ALB description — DNS name only, HTTP:80>"

# =============================================================================
# STAGE 1: web
# =============================================================================
aws ecs register-task-definition --cli-input-json file://firmsbase-staging-web.json
bash create-service-web.sh

aws ecs wait services-stable --cluster "$CLUSTER" --services web

aws ecs describe-services --cluster "$CLUSTER" --services web \
  --query 'services[0].{status:status,desired:desiredCount,running:runningCount,deployments:deployments}'

aws elbv2 describe-target-health --target-group-arn "$TG_ARN"

# Synthetic-only HTTP checks (HTTP-80 only; no client traffic; no HTTPS
# claims — see https-remediation-plan.md).
curl -s -o /dev/null -w "up: %{http_code}\n"     "http://${ALB_DNS}/up"
curl -s -o /dev/null -w "readyz: %{http_code}\n" "http://${ALB_DNS}/readyz"
# Both must return 200 before proceeding to STAGE 2.

# =============================================================================
# STAGE 2: worker (default queues)
# =============================================================================
aws ecs register-task-definition --cli-input-json file://firmsbase-staging-worker.json
bash create-service-worker.sh

aws ecs wait services-stable --cluster "$CLUSTER" --services worker

TASK_ARN=$(aws ecs list-tasks --cluster "$CLUSTER" --service-name worker --query 'taskArns[0]' --output text)
TASK_ID=$(echo "$TASK_ARN" | awk -F/ '{print $NF}')
aws logs tail "/ecs/firmsbase-staging/app" --log-stream-names "worker/app/${TASK_ID}" --since 10m
# Confirm logs show the worker starting and listening on:
# default,documents,notifications,integrations,billing,low-priority
# with no immediate crash/restart loop.

# =============================================================================
# STAGE 3: critical-worker (trust queue only)
# =============================================================================
aws ecs register-task-definition --cli-input-json file://firmsbase-staging-critical-worker.json
bash create-service-critical-worker.sh

aws ecs wait services-stable --cluster "$CLUSTER" --services critical-worker

TASK_ARN=$(aws ecs list-tasks --cluster "$CLUSTER" --service-name critical-worker --query 'taskArns[0]' --output text)
TASK_ID=$(echo "$TASK_ARN" | awk -F/ '{print $NF}')
aws logs tail "/ecs/firmsbase-staging/app" --log-stream-names "critical-worker/app/${TASK_ID}" --since 10m
# Confirm logs show ONLY the "trust" queue being consumed — no
# default/documents/notifications/etc. queue names should appear.

# =============================================================================
# STAGE 4: scheduler
# =============================================================================
aws ecs register-task-definition --cli-input-json file://firmsbase-staging-scheduler.json
bash create-service-scheduler.sh

aws ecs wait services-stable --cluster "$CLUSTER" --services scheduler

TASK_ARN=$(aws ecs list-tasks --cluster "$CLUSTER" --service-name scheduler --query 'taskArns[0]' --output text)
TASK_ID=$(echo "$TASK_ARN" | awk -F/ '{print $NF}')

# Confirm the task survives at least 2 minutes without restart: compare
# startedAt across two describe-tasks calls 120s apart, same taskArn.
aws ecs describe-tasks --cluster "$CLUSTER" --tasks "$TASK_ARN" --query 'tasks[0].{startedAt:startedAt,lastStatus:lastStatus}'
sleep 120
aws ecs describe-tasks --cluster "$CLUSTER" --tasks "$TASK_ARN" --query 'tasks[0].{startedAt:startedAt,lastStatus:lastStatus}'
# startedAt must be identical across both calls and lastStatus must be
# RUNNING both times — a changed startedAt means the task restarted
# (e.g. the schedule:work -> schedule:run /bin/sh dependency regressed).

aws logs tail "/ecs/firmsbase-staging/app" --log-stream-names "scheduler/app/${TASK_ID}" --since 5m

# =============================================================================
# STAGE 5: maintenance — REGISTER ONLY. Never create a service, never run-task
# it as part of this initial deployment. It exists so operators can
# `run-task` ad hoc maintenance commands later.
# =============================================================================
aws ecs register-task-definition --cli-input-json file://firmsbase-staging-maintenance.json
echo "maintenance task definition registered (not run, no service created)."
