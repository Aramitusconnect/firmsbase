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
# any way; it only refuses to hand a live command to the real `terraform`
# binary when one of the checks below fails.
#
# ---------------------------------------------------------------------------
# CREDENTIAL HANDLING — corrected after a real failure, read this first.
# ---------------------------------------------------------------------------
# This environment's approved AWS CLI profile
# (firmsbase-staging-operator-login, see ~/.aws/config) resolves credentials
# through a custom `login_session = <arn>` broker mechanism that the AWS
# CLI's own credential machinery understands, but which is NOT a real AWS
# SDK credential-resolution mechanism — it is not `sso_*`, not
# `credential_process`, not a static-key profile. Terraform's AWS provider
# (Go SDK) has no idea what `login_session` means: it silently falls through
# the ENTIRE credential chain — past AWS_PROFILE, past shared config/
# credentials files — all the way down to EC2/instance-metadata (IMDS), and
# in this sandbox that means picking up the sandbox's OWN ambient
# `AmazonLightsailInstanceRole`, a completely different AWS account, with
# zero error or warning. This was discovered empirically: `aws sts
# get-caller-identity` correctly resolved
# arn:aws:iam::603013471426:user/firmsbase-staging-operator in the exact
# same shell where a subsequent `terraform import` resolved
# arn:aws:sts::215302750105:assumed-role/AmazonLightsailInstanceRole/... —
# silently the wrong account, for a live Terraform operation.
#
# The fix: before any command that can contact the real backend or AWS
# provider, this script "bridges" AWS_PROFILE into standard, SDK-universal
# credentials that every AWS SDK (Go, Python, whatever) actually
# understands, via `aws configure export-credentials --format process`
# (a standard AWS CLI v2 feature — it returns whatever credentials a
# profile resolves to, however exotic the resolution mechanism, as plain
# AccessKeyId/SecretAccessKey/SessionToken/Expiration JSON). Those three
# fields are exported as AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY/
# AWS_SESSION_TOKEN, AWS_EC2_METADATA_DISABLED=true is set so instance
# metadata can never again be silently used as a fallback, and the
# resulting identity is verified against an EXACT expected account AND
# caller ARN (not just the account) before any live command is allowed to
# proceed. Every step is fail-closed: any failure (export-credentials
# itself failing, an empty/missing field, the wrong account, the wrong
# ARN, the wrong region) refuses the command outright rather than
# proceeding with a guess.
#
# Expected caller ARN after bridging:
#   arn:aws:iam::603013471426:user/firmsbase-staging-operator
#
# This bridging (and the account/ARN/region verification) now applies to
# every command that can touch the real backend or AWS provider: a
# real-backend `init` (i.e. NOT `-backend=false`), `import`, `state`,
# `output`, `plan`, and `apply`. `import` in particular is deliberately no
# longer an unguarded passthrough — the previous version of this script
# only gated plan/apply, which is exactly how the wrong-identity failure
# above went undetected until a real import was attempted.
#
# Genuinely offline commands — `fmt`, `validate`, `test`, and
# `init -backend=false` — are untouched by any of this and continue to
# require zero AWS credentials of any kind, live or bridged.
# ---------------------------------------------------------------------------
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
# script that runs `terraform plan`/`apply`/`import` for this environment
# (confirmed by inspecting .github/workflows/ and the repository root — the
# only Terraform-related CI reference is a Docker image content check in
# ecs-pipeline.yml that looks for stray .tf/.tfstate artifacts, not a deploy
# step). Until such an entry point is added, this script — invoked manually
# — is the ONLY approved way to run a live Terraform command against this
# environment. Running the bare `terraform` binary directly against
# infrastructure/ecs/environments/staging remains prohibited by mission
# policy regardless of what this script can technically stop. If a CI
# workflow or Makefile target is added later that can run a live command
# for this environment, it MUST call this script (or reimplement every
# check below), not the bare `terraform` binary.
#
# The approved Terraform binary for this environment is currently 1.15.8 or
# newer — a pinned install at /home/ubuntu/bin/terraform-1.15.8, NOT the
# default `terraform` resolved from PATH (which remains 1.9.8 in this
# sandbox and must never be used for this environment; see the version
# check below and versions.tf's own
# `required_version = ">= 1.15.0, < 2.0.0"`).
# ---------------------------------------------------------------------------
#
# Checks enforced below, for every LIVE command (real-backend `init`,
# `import`, `state`, `output`, `plan`, `apply`):
#   1. A backend is configured in source (a `backend` block in
#      versions.tf). This environment's approved S3 backend (bucket
#      firmsbase-terraform-state-603013471426-us-east-1, key
#      environments/staging/ecs/terraform.tfstate — see versions.tf and
#      docs/ecs/state-adoption-plan.md §5) is now committed there, so this
#      check passes today; it remains enforced as a structural regression
#      guard — if that backend block were ever accidentally removed from
#      versions.tf, this stops a live command from silently falling back to
#      local state instead of failing loudly.
#   2. The real Terraform binary (TF_GUARD_REAL_TERRAFORM, or the bare
#      `terraform` on PATH if unset) reports a version >= 1.15.0. The S3
#      backend's `use_lockfile` locking requires Terraform 1.11+, and
#      1.15+ is the specific version this backend was validated and
#      approved against — this check is independent of, and runs before,
#      every other check below.
#   3. AWS credentials resolve, directly or via bridging (see above), to
#      the exact expected account (603013471426) AND the exact expected
#      caller ARN (arn:aws:iam::603013471426:user/firmsbase-staging-operator)
#      — not merely "some identity in the right account."
#   4. The active region is exactly us-east-1.
#   5. For `plan`/`apply` ONLY: local state is not empty (0 resources) —
#      this environment is known, documented fact (see
#      docs/ecs/state-adoption-plan.md), to already have live AWS
#      resources; an empty state here almost certainly means the import
#      plan hasn't been run yet, not that the environment is genuinely
#      new. `import`/`state`/`output` are deliberately NOT gated by this
#      check — the whole point of the import plan is to run `import`
#      repeatedly starting from an empty state.
#
# What this guard does NOT restrict, on purpose:
#   - `terraform validate` (including `-backend=false`)
#   - `terraform fmt`
#   - `terraform test`
#   - `terraform init -backend=false` (needed for validate/fmt/test to work
#     without ever contacting the real backend)
#   - any other subcommand not named above (e.g. `console`, `providers`,
#     `-help`, `version`) — this script's scope is deliberately limited to
#     the commands explicitly named above; it is not a general-purpose
#     terraform-invocation firewall.
#
# Overrides (no other bypass exists in this script):
#   TF_GUARD_ALLOW_EMPTY_STATE_APPLY=yes-i-am-sure   — skip check 5 only
#   TF_GUARD_SKIP_ACCOUNT_REGION_CHECK=yes-i-am-sure — skip the entire
#     credential-bridging + account/ARN/region verification (checks 3-4)
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
EXPECTED_CALLER_ARN="arn:aws:iam::603013471426:user/firmsbase-staging-operator"
MIN_REQUIRED_MAJOR=1
MIN_REQUIRED_MINOR=15

fail() {
  echo "tf-guard: refusing 'terraform ${subcommand:-<unknown>}' — $1" >&2
  shift || true
  for line in "$@"; do
    echo "tf-guard:   $line" >&2
  done
  exit 1
}

subcommand=""
for arg in "$@"; do
  case "$arg" in
    -*) continue ;;
    *) subcommand="$arg"; break ;;
  esac
done

is_backend_false=false
for arg in "$@"; do
  case "$arg" in
    -backend=false) is_backend_false=true ;;
  esac
done

# --- Fully offline subcommands: always a bare passthrough, zero AWS ------
case "$subcommand" in
  fmt|validate|test)
    exec "${REAL_TERRAFORM}" "$@"
    ;;
esac

if [ "$subcommand" = "init" ] && [ "$is_backend_false" = true ]; then
  exec "${REAL_TERRAFORM}" "$@"
fi

# --- Anything not explicitly gated below is a bare passthrough too --------
# (e.g. `console`, `providers`, `-help`, `version`) — this script's scope
# is deliberately limited to the live commands named in the header comment.
case "$subcommand" in
  init|import|state|output|plan|apply) : ;;
  *)
    exec "${REAL_TERRAFORM}" "$@"
    ;;
esac

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
unset version_json
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

# --- Checks 3-4: credential bridging + exact account/ARN/region -----------
if [ "${TF_GUARD_SKIP_ACCOUNT_REGION_CHECK:-}" != "yes-i-am-sure" ]; then
  have_complete_temp_credentials() {
    [ -n "${AWS_ACCESS_KEY_ID:-}" ] && [ -n "${AWS_SECRET_ACCESS_KEY:-}" ] && [ -n "${AWS_SESSION_TOKEN:-}" ]
  }

  if ! have_complete_temp_credentials; then
    if [ -z "${AWS_PROFILE:-}" ]; then
      fail "no AWS_PROFILE set and no complete standard temporary credentials (AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY/AWS_SESSION_TOKEN) already present." \
        "Set AWS_PROFILE=firmsbase-staging-operator-login before retrying, or export" \
        "complete temporary credentials directly. Refusing to proceed without either" \
        "(failing closed, not open — see the credential-handling note above this" \
        "script's header for why this profile cannot simply be handed to Terraform" \
        "as-is)."
    fi

    if ! command -v aws >/dev/null 2>&1; then
      fail "cannot bridge AWS_PROFILE credentials — the aws CLI is not on PATH."
    fi

    creds_json="$(aws configure export-credentials --profile "$AWS_PROFILE" --format process 2>/dev/null || echo "")"
    if [ -z "$creds_json" ]; then
      unset creds_json
      fail "'aws configure export-credentials --profile ${AWS_PROFILE}' failed or produced no output." \
        "This profile's credential mechanism may not be understood by 'aws configure" \
        "export-credentials' at all — check ~/.aws/config. Refusing to proceed" \
        "(failing closed, not open)."
    fi

    bridged_access_key_id="$(printf '%s' "$creds_json" | jq -r '.AccessKeyId // empty' 2>/dev/null || echo "")"
    bridged_secret_access_key="$(printf '%s' "$creds_json" | jq -r '.SecretAccessKey // empty' 2>/dev/null || echo "")"
    bridged_session_token="$(printf '%s' "$creds_json" | jq -r '.SessionToken // empty' 2>/dev/null || echo "")"
    unset creds_json

    if [ -z "$bridged_access_key_id" ] || [ -z "$bridged_secret_access_key" ] || [ -z "$bridged_session_token" ]; then
      unset bridged_access_key_id bridged_secret_access_key bridged_session_token
      fail "'aws configure export-credentials --profile ${AWS_PROFILE}' returned incomplete credentials (one or more of AccessKeyId/SecretAccessKey/SessionToken was empty)." \
        "Refusing to proceed with partial credentials (failing closed, not open)."
    fi

    export AWS_ACCESS_KEY_ID="$bridged_access_key_id"
    export AWS_SECRET_ACCESS_KEY="$bridged_secret_access_key"
    export AWS_SESSION_TOKEN="$bridged_session_token"
    unset bridged_access_key_id bridged_secret_access_key bridged_session_token
  fi

  # From this point on, every live command uses the explicit credentials
  # above — never fall back to EC2/Lightsail instance metadata, which is
  # exactly how the wrong-account failure this script now prevents
  # originally happened.
  export AWS_EC2_METADATA_DISABLED=true

  if ! command -v aws >/dev/null 2>&1; then
    fail "cannot verify identity — the aws CLI is not on PATH."
  fi

  identity_json="$(aws sts get-caller-identity --output json 2>/dev/null || echo "")"
  if [ -z "$identity_json" ]; then
    fail "cannot verify identity — 'aws sts get-caller-identity' failed with the resolved credentials." \
      "Refusing to proceed without a confirmed identity (failing closed, not open)."
  fi

  active_account="$(printf '%s' "$identity_json" | jq -r '.Account // empty' 2>/dev/null || echo "")"
  active_arn="$(printf '%s' "$identity_json" | jq -r '.Arn // empty' 2>/dev/null || echo "")"
  unset identity_json

  if [ "$active_account" != "$EXPECTED_ACCOUNT_ID" ]; then
    fail "active AWS account is '${active_account:-<empty>}', expected ${EXPECTED_ACCOUNT_ID}." \
      "This guard never trusts an ambient/fallback identity — re-check" \
      "AWS_PROFILE/credentials."
  fi

  if [ "$active_arn" != "$EXPECTED_CALLER_ARN" ]; then
    fail "active AWS caller ARN is '${active_arn:-<empty>}', expected ${EXPECTED_CALLER_ARN}." \
      "This guard requires the EXACT expected operator identity, not merely the" \
      "right account — re-check AWS_PROFILE/credentials."
  fi

  active_region="${AWS_REGION:-${AWS_DEFAULT_REGION:-$(aws configure get region 2>/dev/null || echo "")}}"
  if [ -z "$active_region" ]; then
    fail "cannot determine the active AWS region." \
      "Set AWS_REGION=$EXPECTED_REGION explicitly before retrying."
  fi
  if [ "$active_region" != "$EXPECTED_REGION" ]; then
    fail "active AWS region is '${active_region}', expected ${EXPECTED_REGION}." \
      "Set AWS_REGION=$EXPECTED_REGION explicitly before retrying."
  fi
fi

# --- Check 5: local state must not be empty (plan/apply only) --------------
if [ "$subcommand" = "plan" ] || [ "$subcommand" = "apply" ]; then
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
fi

exec "${REAL_TERRAFORM}" "$@"
