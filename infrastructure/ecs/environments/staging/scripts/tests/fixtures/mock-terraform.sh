#!/usr/bin/env bash
# Fake `terraform` binary for tf-guard-test.sh — never contacts real AWS or
# a real backend. If tf-guard.sh's checks pass, it execs this instead of
# the real terraform binary; this script records (to a file, NEVER to its
# own stdout/stderr) exactly which AWS-credential env vars it received, so
# the test can assert bridging actually happened without ever printing a
# credential value anywhere an assertion has to grep it out of.
#
# Recognized env vars:
#   MOCK_TF_VERSION            (default: 1.15.8)
#   TF_GUARD_TEST_CAPTURE_FILE path to write received env vars to (KEY=VALUE
#                              lines) — only written for non-version-check
#                              invocations, i.e. once tf-guard.sh has decided
#                              to actually run the requested subcommand.

set -euo pipefail

if [ "${1:-}" = "version" ] && [ "${2:-}" = "-json" ]; then
  printf '{"terraform_version":"%s","platform":"linux_amd64"}\n' "${MOCK_TF_VERSION:-1.15.8}"
  exit 0
fi

if [ "${1:-}" = "show" ] && [ "${2:-}" = "-json" ]; then
  # Minimal non-empty state shape so the plan/apply empty-state check (not
  # the focus of these tests) doesn't spuriously fire if ever exercised.
  printf '{"values":{"root_module":{"resources":[{"address":"mock_resource.this"}]}}}\n'
  exit 0
fi

if [ -n "${TF_GUARD_TEST_CAPTURE_FILE:-}" ]; then
  {
    echo "SUBCOMMAND=${1:-}"
    echo "AWS_ACCESS_KEY_ID=${AWS_ACCESS_KEY_ID:-}"
    echo "AWS_SECRET_ACCESS_KEY=${AWS_SECRET_ACCESS_KEY:-}"
    echo "AWS_SESSION_TOKEN=${AWS_SESSION_TOKEN:-}"
    echo "AWS_EC2_METADATA_DISABLED=${AWS_EC2_METADATA_DISABLED:-}"
  } > "$TF_GUARD_TEST_CAPTURE_FILE"
fi

echo "mock-terraform: ran subcommand '${1:-<none>}' (no credential values printed)"
exit 0
