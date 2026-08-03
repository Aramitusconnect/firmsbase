#!/usr/bin/env bash
# Safety wrapper around `terraform` for infrastructure/ecs/environments/staging.
#
# Purpose: this environment's live AWS infrastructure predates its Terraform
# config (see docs/ecs/state-adoption-plan.md). Running `terraform plan` or
# `apply` before the documented import plan has been executed, against the
# wrong AWS account/region, or without an approved remote backend, could
# attempt to CREATE duplicates of already-running resources (ECS cluster,
# ALB, RDS security-group rules, etc.) or silently target infrastructure
# this operator never intended to touch — real risks, not hypothetical
# ones. This wrapper does not change Terraform's behavior in any way; it
# only refuses to hand `plan`/`apply` to the real `terraform` binary when
# one of the checks below fails.
#
# ---------------------------------------------------------------------------
# KNOWN BYPASS LIMITATION — read this before relying on this script alone.
# ---------------------------------------------------------------------------
# This is a wrapper, not an enforcement mechanism. Anyone who runs the real
# `terraform` binary directly (via PATH, an absolute path, an IDE
# integration, or a different shell) bypasses every check below entirely —
# a shell script cannot intercept a command it is never invoked as. This
# script is only as good as the discipline of always invoking it (or an
# entry point that itself calls it) instead of `terraform` directly.
#
# There is currently NO CI workflow, Makefile, or other repository-owned
# script that runs `terraform plan` or `terraform apply` for this
# environment (confirmed by inspecting .github/workflows/ and the
# repository root — the only Terraform-related CI reference is a Docker
# image content check in ecs-pipeline.yml that looks for stray .tf/.tfstate
# artifacts, not a deploy step). Until such an entry point is added, this
# script — invoked manually — is the ONLY approved way to run
# `terraform plan`/`apply` against this environment. Running the bare
# `terraform` binary directly against infrastructure/ecs/environments/staging
# remains prohibited by mission policy regardless of what this script can
# technically stop. If a CI workflow or Makefile target is added later that
# can run plan/apply for this environment, it MUST call this script (or
# reimplement every check below), not the bare `terraform` binary.
# ---------------------------------------------------------------------------
#
# Checks enforced below, for `plan` and `apply` only:
#   1. An approved backend is configured in source (a `backend` block in
#      versions.tf) — today there is none; see docs/ecs/state-adoption-plan.md
#      §5. This alone blocks every plan/apply until a backend decision is
#      made and implemented, which is intentional.
#   2. The active AWS caller identity's account is exactly 603013471426.
#   3. The active region is exactly us-east-1.
#   4. Local state is not empty (0 resources) — this environment is known,
#      documented fact (see docs/ecs/state-adoption-plan.md), to already
#      have live AWS resources; an empty state here almost certainly means
#      the import plan hasn't been run yet, not that the environment is
#      genuinely new.
#
# What this guard does NOT restrict, on purpose:
#   - `terraform validate` (including `-backend=false`)
#   - `terraform fmt`
#   - `terraform init -backend=false` (needed for validate/fmt to work at
#     all before a real backend exists)
#   - `terraform show`, `terraform state list`, `terraform output`, etc.
#   - `terraform import` (the whole point of the import plan is to run this
#     repeatedly against an initially near-empty state; gating it on "state
#     already has resources" would make the very first import impossible —
#     see docs/ecs/state-adoption-plan.md §7 for the ordering/checkpoint
#     process that keeps this safe without a blanket block here)
#
# Overrides (no other bypass exists in this script):
#   TF_GUARD_ALLOW_EMPTY_STATE_APPLY=yes-i-am-sure   — skip check 4 only
#   TF_GUARD_SKIP_ACCOUNT_REGION_CHECK=yes-i-am-sure — skip checks 2-3 only
#     (e.g. for a deliberately different sandbox account — never for this
#     environment's real account without a documented reason)
# No override exists for check 1 (no backend configured) — that is a
# structural precondition, not a judgment call this script should let
# someone reason around from the command line.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REAL_TERRAFORM="${TF_GUARD_REAL_TERRAFORM:-terraform}"
EXPECTED_ACCOUNT_ID="603013471426"
EXPECTED_REGION="us-east-1"

subcommand=""
for arg in "$@"; do
  case "$arg" in
    -*) continue ;;
    *) subcommand="$arg"; break ;;
  esac
done

if [ "$subcommand" != "plan" ] && [ "$subcommand" != "apply" ]; then
  exec "${REAL_TERRAFORM}" "$@"
fi

fail() {
  echo "tf-guard: refusing 'terraform $subcommand' — $1" >&2
  shift || true
  for line in "$@"; do
    echo "tf-guard:   $line" >&2
  done
  exit 1
}

# --- Check 1: approved backend configured in source -----------------------
if ! grep -qE '^\s*backend\s+"[a-z0-9_]+"\s*\{' "${ENV_DIR}/versions.tf" 2>/dev/null; then
  fail "no backend block is configured in versions.tf." \
    "This environment currently has no approved remote state backend —" \
    "see docs/ecs/state-adoption-plan.md §5 (backend recommendation," \
    "requires human approval, not provisioned by this repository)." \
    "Do not run plan/apply against local state for this environment."
fi

# --- Checks 2-3: active account/region -------------------------------------
if [ "${TF_GUARD_SKIP_ACCOUNT_REGION_CHECK:-}" != "yes-i-am-sure" ]; then
  if ! command -v aws >/dev/null 2>&1; then
    fail "cannot verify the active AWS account/region — the aws CLI is not on PATH." \
      "Install/configure the AWS CLI, or set" \
      "TF_GUARD_SKIP_ACCOUNT_REGION_CHECK=yes-i-am-sure if you have already" \
      "verified this by another means (not recommended)."
  fi

  active_account="$(aws sts get-caller-identity --query Account --output text 2>/dev/null || echo "")"
  if [ -z "$active_account" ]; then
    fail "cannot determine the active AWS account (sts get-caller-identity failed)." \
      "Check your AWS credentials/profile before retrying."
  fi
  if [ "$active_account" != "$EXPECTED_ACCOUNT_ID" ]; then
    fail "active AWS account is $active_account, expected $EXPECTED_ACCOUNT_ID." \
      "This guard refuses to plan/apply against any account other than" \
      "this staging environment's own — re-check AWS_PROFILE/credentials."
  fi

  active_region="${AWS_REGION:-${AWS_DEFAULT_REGION:-$(aws configure get region 2>/dev/null || echo "")}}"
  if [ -z "$active_region" ]; then
    fail "cannot determine the active AWS region." \
      "Set AWS_REGION=$EXPECTED_REGION explicitly before retrying."
  fi
  if [ "$active_region" != "$EXPECTED_REGION" ]; then
    fail "active AWS region is $active_region, expected $EXPECTED_REGION." \
      "Set AWS_REGION=$EXPECTED_REGION explicitly before retrying."
  fi
fi

# --- Check 4: local state must not be empty (known live resources exist) ---
if [ "${TF_GUARD_ALLOW_EMPTY_STATE_APPLY:-}" != "yes-i-am-sure" ]; then
  resource_count="0"
  if [ -d "${ENV_DIR}/.terraform" ]; then
    resource_count="$(
      "${REAL_TERRAFORM}" -chdir="${ENV_DIR}" show -json 2>/dev/null \
        | jq '[.values.root_module.resources // [], (.values.root_module.child_modules // [] | .. | .resources? // [] | .[])] | flatten | length' \
        2>/dev/null || echo "0"
    )"
  fi

  if [ "${resource_count:-0}" = "0" ]; then
    fail "local state currently shows 0 resources, but this environment is" \
      "documented to already have live AWS resources (see" \
      "docs/ecs/state-adoption-plan.md — ECS cluster, ALB, RDS, ElastiCache," \
      "etc. all predate this Terraform config)." \
      "Run the documented 'terraform import' commands first, or if you are" \
      "certain an empty-state $subcommand is correct here, re-run with:" \
      "  TF_GUARD_ALLOW_EMPTY_STATE_APPLY=yes-i-am-sure $0 $*"
  fi
fi

exec "${REAL_TERRAFORM}" "$@"
