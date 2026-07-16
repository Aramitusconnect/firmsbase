#!/usr/bin/env bash
# NOT EXECUTED. Review-only. Run in CloudShell (region us-east-1, account
# 603013471426) only after the "web" service has reached steady state and
# passed /up + /readyz verification.
#
# Prerequisite: firmsbase-staging-worker.json already registered.
set -euo pipefail

aws ecs create-service \
  --cluster firmsbase-staging-cluster \
  --service-name worker \
  --task-definition firmsbase-staging-worker \
  --desired-count 1 \
  --launch-type FARGATE \
  --platform-version LATEST \
  --network-configuration "awsvpcConfiguration={subnets=[subnet-07efcb5d4bcf5aa59,subnet-020540b8377bb4d0e],securityGroups=[sg-0db14e50ea5c5466c],assignPublicIp=ENABLED}" \
  --deployment-configuration "deploymentCircuitBreaker={enable=true,rollback=true},minimumHealthyPercent=0,maximumPercent=100" \
  --enable-execute-command false \
  --tags \
    "key=Application,value=FirmsBase" \
    "key=Environment,value=staging" \
    "key=ManagedBy,value=manual-reviewed-deployment" \
    "key=SourceCommit,value=008866ffe00bfd9f22c986a7e407cbe8f271b1df" \
    "key=ImageDigest,value=sha256:8bfd74b0b56986f426d1695e2fef69e5f8b1f77be0c9712ea9015c4946de3a4f"

# No --load-balancers: this task def has no port mapping and must never be
# attached to the ALB target group.
#
# No --health-check-grace-period-seconds: only meaningful when a
# target-group is attached.
