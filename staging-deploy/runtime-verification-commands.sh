#!/usr/bin/env bash
# =============================================================================
# SUPERSEDED — DO NOT USE. This file previously chained raw
# `aws ecs register-task-definition` + `bash create-service-*.sh` calls with
# no manifest-pinned ARNs, no operator-identity check, no secret/shape
# preflight, and no live re-verification between stages — the create-service-
# *.sh files it called have been deleted from the repository.
#
# The only approved runtime service workflow is the numbered sequence below.
# Each step re-verifies the previous one LIVE and requires an explicit human
# acknowledgement before it creates anything:
#
#   staging-deploy/00-http-exposure-preflight.sh
#   staging-deploy/01-register-runtime-task-definitions.sh
#   staging-deploy/02-launch-web-service.sh
#   staging-deploy/03-verify-web-health.sh
#   staging-deploy/04-launch-critical-worker.sh
#   staging-deploy/05-launch-worker.sh
#   staging-deploy/06-launch-scheduler.sh
#   staging-deploy/07-final-runtime-verification.sh
#
# This stub exits non-zero so an operator who runs it by habit is stopped
# immediately rather than silently falling back to the superseded path.
# =============================================================================
set -euo pipefail

echo "STOP: runtime-verification-commands.sh is superseded and must not be used." >&2
echo "Use staging-deploy/00-http-exposure-preflight.sh through 07-final-runtime-verification.sh instead." >&2
exit 1
