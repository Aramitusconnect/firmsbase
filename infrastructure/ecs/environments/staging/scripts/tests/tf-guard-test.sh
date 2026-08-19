#!/usr/bin/env bash
# Mocked regression tests for infrastructure/ecs/environments/staging/
# scripts/tf-guard.sh's credential-bridging and fail-closed behavior.
#
# Never contacts real AWS or a real Terraform backend: both `aws` and the
# "real terraform" binary tf-guard.sh execs are fake scripts
# (tests/fixtures/mock-aws.sh, tests/fixtures/mock-terraform.sh) whose
# behavior is controlled entirely by env vars this script sets per test
# case. Follows this repository's existing plain-bash test convention (see
# tools/rls-test/tests/*.sh) — no bats/shellspec dependency.
#
# Usage: infrastructure/ecs/environments/staging/scripts/tests/tf-guard-test.sh

set -uo pipefail # deliberately NOT -e: several test cases assert that
                  # tf-guard.sh EXITS NON-ZERO, which must not abort this
                  # script itself.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GUARD="${SCRIPT_DIR}/../tf-guard.sh"
MOCK_AWS="${SCRIPT_DIR}/fixtures/mock-aws.sh"
MOCK_TF="${SCRIPT_DIR}/fixtures/mock-terraform.sh"

chmod +x "$MOCK_AWS" "$MOCK_TF" "$GUARD"

FAIL_COUNT=0
fail() { echo "TF-GUARD TEST FAILED: $*" >&2; FAIL_COUNT=$((FAIL_COUNT + 1)); }
pass() { echo "OK: $*"; }

TMP_BASE="$(mktemp -d)"
trap 'rm -rf "$TMP_BASE"' EXIT

MOCK_BIN_DIR="${TMP_BASE}/bin"
mkdir -p "$MOCK_BIN_DIR"
ln -sf "$MOCK_AWS" "${MOCK_BIN_DIR}/aws"

# Known, obviously-fake credential material — used only to prove tf-guard.sh
# never echoes whatever it bridges, not real credentials of any kind.
export MOCK_ACCESS_KEY_ID="MOCKAKIAEXAMPLE0000000"
export MOCK_SECRET_ACCESS_KEY="MOCKSECRETACCESSKEYEXAMPLE00000000000000"
export MOCK_SESSION_TOKEN="MOCKSESSIONTOKENEXAMPLEVALUEUNIQUE12345"

reset_mock_env() {
  unset MOCK_EXPORT_CREDS_MODE MOCK_IDENTITY_MODE MOCK_IDENTITY_ACCOUNT \
    MOCK_IDENTITY_ARN MOCK_AWS_REGION MOCK_TF_VERSION AWS_PROFILE \
    AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN \
    AWS_EC2_METADATA_DISABLED AWS_REGION AWS_DEFAULT_REGION \
    TF_GUARD_SKIP_ACCOUNT_REGION_CHECK TF_GUARD_ALLOW_EMPTY_STATE_APPLY \
    2>/dev/null || true
  export AWS_PROFILE="firmsbase-staging-operator-login"
  export AWS_REGION="us-east-1"
}

# run_guard <args...> — invokes tf-guard.sh with the mock aws/terraform
# wired in, capturing combined stdout+stderr into $LAST_OUTPUT and the exit
# code into $LAST_EXIT_CODE. Always starts from a fresh capture file
# ($LAST_CAPTURE_FILE) so "did the real subcommand actually run" is
# unambiguous per call.
run_guard() {
  LAST_CAPTURE_FILE="${TMP_BASE}/capture-${RANDOM}.env"
  rm -f "$LAST_CAPTURE_FILE"
  LAST_OUTPUT="$(
    PATH="${MOCK_BIN_DIR}:${PATH}" \
      TF_GUARD_REAL_TERRAFORM="$MOCK_TF" \
      TF_GUARD_TEST_CAPTURE_FILE="$LAST_CAPTURE_FILE" \
      "$GUARD" "$@" 2>&1
  )"
  LAST_EXIT_CODE=$?
}

# run_guard_no_aws <args...> — same, but with NO aws binary reachable at
# all and no AWS_PROFILE set, for the offline-command tests.
run_guard_no_aws() {
  LAST_CAPTURE_FILE="${TMP_BASE}/capture-${RANDOM}.env"
  rm -f "$LAST_CAPTURE_FILE"
  LAST_OUTPUT="$(
    PATH="/usr/bin:/bin" \
      AWS_PROFILE="" \
      TF_GUARD_REAL_TERRAFORM="$MOCK_TF" \
      TF_GUARD_TEST_CAPTURE_FILE="$LAST_CAPTURE_FILE" \
      env -u AWS_PROFILE \
      "$GUARD" "$@" 2>&1
  )"
  LAST_EXIT_CODE=$?
}

assert_exit_zero() {
  local desc="$1"
  if [ "$LAST_EXIT_CODE" -eq 0 ]; then
    pass "$desc (exit 0)"
  else
    fail "$desc — expected exit 0, got $LAST_EXIT_CODE. Output:\n$LAST_OUTPUT"
  fi
}

assert_exit_nonzero() {
  local desc="$1"
  if [ "$LAST_EXIT_CODE" -ne 0 ]; then
    pass "$desc (exit $LAST_EXIT_CODE, as expected)"
  else
    fail "$desc — expected a non-zero exit, got 0. Output:\n$LAST_OUTPUT"
  fi
}

assert_output_contains() {
  local needle="$1" desc="$2"
  if printf '%s' "$LAST_OUTPUT" | grep -qF -- "$needle"; then
    pass "$desc"
  else
    fail "$desc — expected output to contain '$needle'. Output:\n$LAST_OUTPUT"
  fi
}

assert_output_does_not_contain() {
  local needle="$1" desc="$2"
  if printf '%s' "$LAST_OUTPUT" | grep -qF -- "$needle"; then
    fail "$desc — expected output to NOT contain '$needle', but it did. Output:\n$LAST_OUTPUT"
  else
    pass "$desc"
  fi
}

assert_capture_absent() {
  local desc="$1"
  if [ -f "$LAST_CAPTURE_FILE" ]; then
    fail "$desc — the mocked real terraform ran (capture file exists), but it should never have been reached."
  else
    pass "$desc"
  fi
}

assert_capture_field_equals() {
  local field="$1" expected="$2" desc="$3"
  if [ ! -f "$LAST_CAPTURE_FILE" ]; then
    fail "$desc — capture file does not exist at all."
    return
  fi
  local actual
  actual="$(grep "^${field}=" "$LAST_CAPTURE_FILE" | head -1 | cut -d= -f2-)"
  if [ "$actual" = "$expected" ]; then
    pass "$desc"
  else
    fail "$desc — expected ${field}='${expected}', got '${actual}'."
  fi
}

echo "=== 1. the login profile is bridged into standard credentials ==="
reset_mock_env
run_guard state list
assert_exit_zero "bridging succeeds for a live subcommand (state list) with valid mock credentials"
assert_capture_field_equals "AWS_ACCESS_KEY_ID" "$MOCK_ACCESS_KEY_ID" "bridged AWS_ACCESS_KEY_ID reaches the real terraform invocation"
assert_capture_field_equals "AWS_SECRET_ACCESS_KEY" "$MOCK_SECRET_ACCESS_KEY" "bridged AWS_SECRET_ACCESS_KEY reaches the real terraform invocation"
assert_capture_field_equals "AWS_SESSION_TOKEN" "$MOCK_SESSION_TOKEN" "bridged AWS_SESSION_TOKEN reaches the real terraform invocation"

echo
echo "=== 2. import specifically receives standard temporary credentials (no longer an unguarded passthrough) ==="
reset_mock_env
run_guard import 'module.security_groups.aws_security_group.alb' 'sg-02a26ff122a9a1d29'
assert_exit_zero "import proceeds once bridging + identity verification succeed"
assert_capture_field_equals "SUBCOMMAND" "import" "the real terraform was actually invoked with 'import'"
assert_capture_field_equals "AWS_ACCESS_KEY_ID" "$MOCK_ACCESS_KEY_ID" "import receives the bridged AWS_ACCESS_KEY_ID"
assert_capture_field_equals "AWS_SECRET_ACCESS_KEY" "$MOCK_SECRET_ACCESS_KEY" "import receives the bridged AWS_SECRET_ACCESS_KEY"
assert_capture_field_equals "AWS_SESSION_TOKEN" "$MOCK_SESSION_TOKEN" "import receives the bridged AWS_SESSION_TOKEN"

echo
echo "=== 3. EC2/Lightsail instance-metadata fallback is disabled for every bridged live command ==="
assert_capture_field_equals "AWS_EC2_METADATA_DISABLED" "true" "AWS_EC2_METADATA_DISABLED=true is set before the live command runs, preventing exactly the silent ambient-role fallback that caused the real failure"

echo
echo "=== 4. the wrong AWS account is rejected, not silently accepted ==="
reset_mock_env
# The exact wrong ambient identity observed in the real failure.
export MOCK_IDENTITY_ACCOUNT="215302750105"
export MOCK_IDENTITY_ARN="arn:aws:sts::215302750105:assumed-role/AmazonLightsailInstanceRole/i-09fb02cdc8b9ef5ba"
run_guard import 'module.security_groups.aws_security_group.alb' 'sg-02a26ff122a9a1d29'
assert_exit_nonzero "import is refused when the resolved identity is the wrong account"
assert_output_contains "expected 603013471426" "refusal message names the expected account"
assert_capture_absent "the real terraform (mocked) was never invoked for the wrong-account case"

echo
echo "=== 5. the wrong caller ARN (right account, wrong principal) is rejected ==="
reset_mock_env
export MOCK_IDENTITY_ACCOUNT="603013471426"
export MOCK_IDENTITY_ARN="arn:aws:iam::603013471426:role/SomeUnexpectedRole"
run_guard import 'module.security_groups.aws_security_group.alb' 'sg-02a26ff122a9a1d29'
assert_exit_nonzero "import is refused when the account is right but the caller ARN is not the exact expected operator"
assert_output_contains "caller ARN" "refusal message specifically names the caller ARN mismatch, not just the account"
assert_capture_absent "the real terraform (mocked) was never invoked for the wrong-ARN case"

echo
echo "=== 6. missing/incomplete credential fields from export-credentials are rejected ==="
reset_mock_env
export MOCK_EXPORT_CREDS_MODE="missing_field"
run_guard import 'module.security_groups.aws_security_group.alb' 'sg-02a26ff122a9a1d29'
assert_exit_nonzero "import is refused when export-credentials returns an empty SessionToken"
assert_output_contains "incomplete credentials" "refusal message names the incomplete-credentials condition"
assert_capture_absent "the real terraform (mocked) was never invoked for the missing-field case"

echo
echo "=== 7. credentials never appear anywhere in tf-guard.sh's own output ==="
reset_mock_env
run_guard import 'module.security_groups.aws_security_group.alb' 'sg-02a26ff122a9a1d29'
assert_exit_zero "sanity: this run succeeds (so there is real output to check)"
assert_output_does_not_contain "$MOCK_SECRET_ACCESS_KEY" "the mock secret access key never appears in tf-guard.sh's stdout/stderr"
assert_output_does_not_contain "$MOCK_SESSION_TOKEN" "the mock session token never appears in tf-guard.sh's stdout/stderr"

echo
echo "=== 8. offline commands (fmt, validate, test, init -backend=false) remain usable with zero AWS credentials ==="
for offline_args in "fmt -recursive -check" "validate" "test" "init -backend=false -input=false"; do
  # shellcheck disable=SC2086
  run_guard_no_aws $offline_args
  assert_exit_zero "'terraform ${offline_args}' passes straight through with no aws CLI and no AWS_PROFILE at all"
  # The command DOES reach the mocked real terraform (that's what "remains
  # usable" means) — it just never needed to bridge any credentials to get
  # there, since offline subcommands are exec'd before any AWS-touching
  # code in tf-guard.sh runs at all.
  assert_capture_field_equals "AWS_ACCESS_KEY_ID" "" "'terraform ${offline_args}' reached the real terraform without any bridged AWS_ACCESS_KEY_ID"
done

echo
echo "=== 9. Terraform 1.9.8 remains rejected for live operations (unapproved binary) ==="
reset_mock_env
export MOCK_TF_VERSION="1.9.8"
run_guard import 'module.security_groups.aws_security_group.alb' 'sg-02a26ff122a9a1d29'
assert_exit_nonzero "import is refused when the resolved Terraform binary reports 1.9.8"
assert_output_contains "requires >= 1.15.0" "refusal message names the minimum required version"
assert_capture_absent "the real terraform (mocked) was never actually invoked for 'import' itself when the version check fails"

echo
echo "=== 10. 'terraform state' is restricted to list/show; every destructive or raw-state subcommand is refused ==="

reset_mock_env
run_guard state list
assert_exit_zero "'state list' is allowed after credential and identity verification"
assert_capture_field_equals "SUBCOMMAND" "state" "'state list' actually reached the real terraform"
assert_capture_field_equals "AWS_ACCESS_KEY_ID" "$MOCK_ACCESS_KEY_ID" "'state list' received the bridged credentials, same as any other live command"

reset_mock_env
run_guard state show 'module.security_groups.aws_security_group.alb'
assert_exit_zero "'state show <address>' is allowed after credential and identity verification"
assert_capture_field_equals "SUBCOMMAND" "state" "'state show' actually reached the real terraform"

for destructive in "rm module.security_groups.aws_security_group.alb" \
                   "mv module.old module.new" \
                   "push terraform.tfstate" \
                   "pull" \
                   "replace-provider registry.terraform.io/hashicorp/aws registry.terraform.io/hashicorp/aws"; do
  reset_mock_env
  # shellcheck disable=SC2086
  run_guard state $destructive
  sub="$(printf '%s' "$destructive" | cut -d' ' -f1)"
  assert_exit_nonzero "'state ${sub}' is refused"
  assert_output_contains "requires a separate, explicitly approved recovery" "'state ${sub}' refusal names the required recovery procedure, not a bypass"
  assert_capture_absent "'state ${sub}' never reached the real terraform (mocked)"
done

reset_mock_env
run_guard state
assert_exit_nonzero "'state' with no subcommand at all is refused, not silently treated as a no-op"
assert_capture_absent "'state' with no subcommand never reached the real terraform (mocked)"

reset_mock_env
run_guard state totally-made-up-subcommand
assert_exit_nonzero "an unrecognized 'state' subcommand is refused"
assert_capture_absent "the unrecognized 'state' subcommand never reached the real terraform (mocked)"

reset_mock_env
run_guard state rm module.security_groups.aws_security_group.alb
assert_output_does_not_contain "$MOCK_SECRET_ACCESS_KEY" "no credential value appears in the output of a refused 'state rm' (bridging is never even attempted for a subcommand refused up front)"
assert_output_does_not_contain "$MOCK_SESSION_TOKEN" "no session token appears in the output of a refused 'state rm' either"

echo
if [ "$FAIL_COUNT" -eq 0 ]; then
  echo "ALL TF-GUARD MOCKED REGRESSION CHECKS PASSED"
  exit 0
else
  echo "TF-GUARD MOCKED REGRESSION CHECKS FAILED: ${FAIL_COUNT} failure(s)" >&2
  exit 1
fi
