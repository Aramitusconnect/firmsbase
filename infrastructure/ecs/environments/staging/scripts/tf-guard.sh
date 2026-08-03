#!/usr/bin/env bash
# Safety wrapper around `terraform` for infrastructure/ecs/environments/staging.
#
# Purpose: this environment's live AWS infrastructure predates its Terraform
# config (see docs/ecs/state-adoption-plan.md). Running `terraform apply`
# before the documented import plan has been executed would attempt to
# CREATE duplicates of already-running resources (ECS cluster, ALB, RDS
# security-group rules, etc.) instead of adopting them — a real outage/
# duplication risk, not a hypothetical one. This wrapper does not change
# Terraform's behavior in any way; it only refuses to hand `apply` to the
# real `terraform` binary in the specific case that's almost certainly a
# mistake: applying against a state that has zero resources in it.
#
# What this guard does NOT restrict, on purpose:
#   - `terraform validate` (including `-backend=false`)
#   - `terraform plan` (read-only; never mutates AWS or state)
#   - `terraform fmt`, `terraform show`, `terraform state list`, etc.
#   - `terraform import` (the whole point of the import plan is to run this
#     repeatedly against an initially-empty state; blocking it would defeat
#     the guard's own purpose)
#   - `terraform apply` once state already has resources in it (normal,
#     expected operation after Phase A adoption is complete)
#
# Override: once you have deliberately decided an empty-state apply is
# correct (e.g. a brand-new environment, not this one), set
#   TF_GUARD_ALLOW_EMPTY_STATE_APPLY=yes-i-am-sure
# and re-run. There is no other bypass.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REAL_TERRAFORM="${TF_GUARD_REAL_TERRAFORM:-terraform}"

is_apply=false
for arg in "$@"; do
  case "$arg" in
    apply) is_apply=true ;;
  esac
  # Only the first non-flag argument counts as the subcommand.
  case "$arg" in
    -*) continue ;;
    *) break ;;
  esac
done

if [ "$is_apply" = true ] && [ "${TF_GUARD_ALLOW_EMPTY_STATE_APPLY:-}" != "yes-i-am-sure" ]; then
  resource_count="0"
  if [ -d "${ENV_DIR}/.terraform" ]; then
    resource_count="$(
      "${REAL_TERRAFORM}" -chdir="${ENV_DIR}" show -json 2>/dev/null \
        | jq '[.values.root_module.resources // [], (.values.root_module.child_modules // [] | .. | .resources? // [] | .[])] | flatten | length' \
        2>/dev/null || echo "0"
    )"
  fi

  if [ "${resource_count:-0}" = "0" ]; then
    echo "tf-guard: refusing 'terraform apply' — local state currently shows 0 resources." >&2
    echo "tf-guard: this environment's live infrastructure predates Terraform." >&2
    echo "tf-guard: see docs/ecs/state-adoption-plan.md — run the documented" >&2
    echo "tf-guard: 'terraform import' commands first, or if you are certain an" >&2
    echo "tf-guard: empty-state apply is correct, re-run with:" >&2
    echo "tf-guard:   TF_GUARD_ALLOW_EMPTY_STATE_APPLY=yes-i-am-sure $0 $*" >&2
    exit 1
  fi
fi

exec "${REAL_TERRAFORM}" "$@"
