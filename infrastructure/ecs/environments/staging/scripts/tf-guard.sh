#!/usr/bin/env bash
# Safety wrapper around `terraform` for infrastructure/ecs/environments/staging.
#
# Purpose: this environment's live AWS infrastructure predates its Terraform
# config (see docs/ecs/state-adoption-plan.md). Running `terraform plan` or
# `apply` before the documented import plan has been executed, against the
# wrong AWS account/region, with an unapproved Terraform binary, or without
# a backend configured, could attempt to CREATE duplicates of already-running
# resources (ECS cluster, ALB, RDS security-group rules, etc.) or silently
# target infrastructure this operator never intended to touch — real risks,
# not hypothetical ones. This wrapper does not change Terraform's behavior in
# any way; it only refuses to hand `plan`/`apply` to the real `terraform`
# binary when one of the checks below fails.
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
#
# The approved Terraform binary for this environment is currently 1.15.8 or
# newer — a pinned install at /home/ubuntu/bin/terraform-1.15.8, NOT the
# default `terraform` resolved from PATH (which remains 1.9.8 in this
# sandbox and must never be used for this environment; see the version
# check below and versions.tf's own
# `required_version = ">= 1.15.0, < 2.0.0"`).
# ---------------------------------------------------------------------------
#
# Checks enforced below, for `plan` and `apply` only:
#   1. A backend is configured in source (a `backend` block in
#      versions.tf). This environment's approved S3 backend (bucket
#      firmsbase-terraform-state-603013471426-us-east-1, key
#      environments/staging/ecs/terraform.tfstate — see versions.tf and
#      docs/ecs/state-adoption-plan.md §5) is now committed there, so this
#      check passes today; it remains enforced as a structural regression
#      guard — if that backend block were ever accidentally removed from
#      versions.tf, this stops plan/apply from silently falling back to
#      local state instead of failing loudly.
#   2. The real Terraform binary (TF_GUARD_REAL_TERRAFORM, or the bare
#      `terraform` on PATH if unset) reports a version >= 1.15.0. The S3
#      backend's `use_lockfile` locking requires Terraform 1.11+, and
#      1.15+ is the specific version this backend was validated and
#      approved against — this check is independent of, and runs before,
#      the account/region/state checks below.
#   3. The active AWS caller identity's account is exactly 603013471426.
#   4. The active region is exactly us-east-1.
#   5. Local state is not empty (0 resources) — this environment is known,
#      documented fact (see docs/ecs/state-adoption-plan.md), to already
#      have live AWS resources; an empty state here almost certainly means
#      the import plan hasn't been run yet, not that the environment is
#      genuinely new. Configuring the S3 backend does not by itself change
#      this — the state prefix (environments/staging/ecs/) is confirmed
#      empty as of this commit (see versions.tf), so this check keeps
#      refusing plan/apply exactly as before, until the documented import
#      procedure (docs/ecs/state-adoption-plan.md §8) actually populates
#      state.
#
# What this guard does NOT restrict, on purpose:
#   - `terraform validate` (including `-backend=false`)
#   - `terraform fmt`
#   - `terraform init -backend=false` (needed for validate/fmt to work
#     without ever contacting the real backend)
#   - `terraform show`, `terraform state list`, `terraform output`, etc.
#   - `terraform import` (the whole point of the import plan is to run this
#     repeatedly against an initially near-empty state; gating it on "state
#     already has resources" would make the very first import impossible —
#     see docs/ecs/state-adoption-plan.md §7 for the ordering/checkpoint
#     process that keeps this safe without a blanket block here). The
#     Terraform-version check above applies only to plan/apply, not
#     import, for the same reason.
#
# Overrides (no other bypass exists in this script):
#   TF_GUARD_ALLOW_EMPTY_STATE_APPLY=yes-i-am-sure   — skip check 5 only
#   TF_GUARD_SKIP_ACCOUNT_REGION_CHECK=yes-i-am-sure — skip checks 3-4 only
#     (e.g. for a deliberately different sandbox account — never for this
#     environment's real account without a documented reason)
# No override exists for check 1 (backend must be configured in source) or
# check 2 (Terraform version) — both are structural preconditions, not
# judgment calls this script should let someone reason around from the
# command line.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REAL_TERRAFORM="${TF_GUARD_REAL_TERRAFORM:-terraform}"
EXPECTED_ACCOUNT_ID="603013471426"
EXPECTED_REGION="us-east-1"
MIN_REQUIRED_MAJOR=1
MIN_REQUIRED_MINOR=15

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

# --- Check 1: backend configured in source ---------------------------------
if ! grep -qE '^\s*backend\s+"[a-z0-9_]+"\s*\{' "${ENV_DIR}/versions.tf" 2>/dev/null; then
  fail "no backend block is configured in versions.tf." \
    "This environment's approved backend (S3 bucket" \
    "firmsbase-terraform-state-603013471426-us-east-1, key" \
    "environments/staging/ecs/terraform.tfstate) belongs in versions.tf —" \
    "see docs/ecs/state-adoption-plan.md §5. If this fired, the backend" \
    "block was removed or never committed; restore it before continuing."
fi

# --- Check 2: Terraform binary version (does not contact the backend) ------
# `terraform version -json` is a purely local, offline command — it never
# reads state, contacts the backend, or requires credentials, so satisfying
# this check never triggers (and cannot require) a backend initialization.
version_json="$("${REAL_TERRAFORM}" version -json 2>/dev/null || echo "")"
if [ -z "$version_json" ]; then
  fail "cannot determine the Terraform binary version (\`${REAL_TERRAFORM} version -json\` produced no output)." \
    "Refusing to proceed without a confirmed version (failing closed, not open)." \
    "Set TF_GUARD_REAL_TERRAFORM to the approved binary, e.g.:" \
    "  TF_GUARD_REAL_TERRAFORM=/home/ubuntu/bin/terraform-1.15.8 $0 $*"
fi

tf_version="$(printf '%s' "$version_json" | jq -r '.terraform_version // empty' 2>/dev/null || echo "")"
if [ -z "$tf_version" ]; then
  fail "cannot parse a Terraform version out of '${REAL_TERRAFORM} version -json' output." \
    "Refusing to proceed without a confirmed version (failing closed, not open)."
fi

tf_major="$(printf '%s' "$tf_version" | cut -d. -f1)"
tf_minor="$(printf '%s' "$tf_version" | cut -d. -f2)"

case "$tf_major" in ''|*[!0-9]*)
  fail "cannot parse a numeric major version from Terraform version string '${tf_version}'." \
    "Refusing to proceed without a confirmed version (failing closed, not open)."
  ;;
esac
case "$tf_minor" in ''|*[!0-9]*)
  fail "cannot parse a numeric minor version from Terraform version string '${tf_version}'." \
    "Refusing to proceed without a confirmed version (failing closed, not open)."
  ;;
esac

# Numeric (not lexicographic string) comparison, deliberately — a naive
# string compare treats "1.9.8" as greater than "1.15.8" (since '9' sorts
# after '1' character-by-character), which would let an unapproved older
# Terraform binary through. Comparing tf_major/tf_minor as integers avoids
# that failure mode entirely.
version_ok=false
if [ "$tf_major" -gt "$MIN_REQUIRED_MAJOR" ]; then
  version_ok=true
elif [ "$tf_major" -eq "$MIN_REQUIRED_MAJOR" ] && [ "$tf_minor" -ge "$MIN_REQUIRED_MINOR" ]; then
  version_ok=true
fi

if [ "$version_ok" != true ]; then
  fail "Terraform binary version is ${tf_version}, but this environment requires >= ${MIN_REQUIRED_MAJOR}.${MIN_REQUIRED_MINOR}.0 (the S3 backend's use_lockfile locking needs 1.11+; 1.15+ is the specific version this backend was validated and approved against — see versions.tf)." \
    "Set TF_GUARD_REAL_TERRAFORM to the approved binary, e.g.:" \
    "  TF_GUARD_REAL_TERRAFORM=/home/ubuntu/bin/terraform-1.15.8 $0 $*" \
    "Never the default 'terraform' resolved from PATH in this environment (currently 1.9.8)."
fi

# --- Checks 3-4: active account/region -------------------------------------
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

# --- Check 5: local state must not be empty (known live resources exist) ---
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
