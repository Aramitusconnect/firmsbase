#!/usr/bin/env bash
# NOT EXECUTED. Review-only. Run in CloudShell (region us-east-1, account
# 603013471426) only after the migrate task has completed with exit code 0.
#
# Prerequisite: firmsbase-staging-web.json has already been registered via
# `aws ecs register-task-definition --cli-input-json file://firmsbase-staging-web.json`
# and the resulting revision ARN is known (or omit --task-definition's
# revision suffix to use ":latest" — the newest ACTIVE revision of the
# family, safe here since this is the very first revision).
set -euo pipefail

aws ecs create-service \
  --cluster firmsbase-staging-cluster \
  --service-name web \
  --task-definition firmsbase-staging-web \
  --desired-count 1 \
  --launch-type FARGATE \
  --platform-version LATEST \
  --network-configuration "awsvpcConfiguration={subnets=[subnet-07efcb5d4bcf5aa59,subnet-020540b8377bb4d0e],securityGroups=[sg-0db14e50ea5c5466c],assignPublicIp=ENABLED}" \
  --load-balancers "targetGroupArn=arn:aws:elasticloadbalancing:us-east-1:603013471426:targetgroup/firmsbase-staging-tg/1830c01b9aaac37d,containerName=app,containerPort=8080" \
  --health-check-grace-period-seconds 60 \
  --deployment-configuration "deploymentCircuitBreaker={enable=true,rollback=true},minimumHealthyPercent=0,maximumPercent=100" \
  --enable-execute-command false \
  --tags \
    "key=Application,value=FirmsBase" \
    "key=Environment,value=staging" \
    "key=ManagedBy,value=manual-reviewed-deployment" \
    "key=SourceCommit,value=008866ffe00bfd9f22c986a7e407cbe8f271b1df" \
    "key=ImageDigest,value=sha256:8bfd74b0b56986f426d1695e2fef69e5f8b1f77be0c9712ea9015c4946de3a4f"

# Rationale for minimumHealthyPercent=0 / maximumPercent=100:
# this is the FIRST creation of this service at desiredCount=1 — matches
# the existing Terraform pattern already used for the single-instance
# scheduler service (infrastructure/ecs/environments/staging/main.tf,
# module.scheduler), which explicitly avoids a transient overlap. web's
# eventual steady-state Terraform config (desired_count=2,
# enable_autoscaling=true) is a LATER, separate scale-up step, not part of
# this initial bootstrap.
#
# health-check-grace-period-seconds=60 matches
# infrastructure/ecs/modules/ecs_service/main.tf's existing rule (only
# applied when a target_group_arn is set) — gives the container time to
# boot before the ALB's own health check can fail it.
#
# NOTE: no container-level `healthCheck` is set in firmsbase-staging-web.json
# (see REPORT.md, item 14 — unresolved questions) — the checked-in Terraform declares
# `container_health_check_command = ["CMD-SHELL", "curl -f http://localhost:8080/up || exit 1"]`
# but this image is distroless and has no `curl` binary; that command would
# always fail. Health is verified via the ALB target group's own HTTP
# health check (path /up, matcher 200-399) instead, which is unaffected by
# this gap.
