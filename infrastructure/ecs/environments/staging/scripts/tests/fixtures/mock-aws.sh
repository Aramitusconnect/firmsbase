#!/usr/bin/env bash
# Fake `aws` binary for tf-guard-test.sh — never contacts real AWS. Behavior
# is entirely controlled by env vars the test script sets before invoking
# tf-guard.sh, so each scenario (success, wrong account, missing field,
# export-credentials failure, ...) can be exercised deterministically.
#
# Recognized env vars:
#   MOCK_EXPORT_CREDS_MODE   success | fail | missing_field (default: success)
#   MOCK_ACCESS_KEY_ID       (default: MOCKAKIAEXAMPLE)
#   MOCK_SECRET_ACCESS_KEY   (default: MOCKSECRETEXAMPLE)
#   MOCK_SESSION_TOKEN       (default: MOCKSESSIONTOKENEXAMPLE)
#   MOCK_IDENTITY_MODE       success | fail (default: success)
#   MOCK_IDENTITY_ACCOUNT    (default: 603013471426)
#   MOCK_IDENTITY_ARN        (default: arn:aws:iam::603013471426:user/firmsbase-staging-operator)
#   MOCK_AWS_REGION          (default: us-east-1)

set -euo pipefail

if [ "$1" = "configure" ] && [ "$2" = "export-credentials" ]; then
  mode="${MOCK_EXPORT_CREDS_MODE:-success}"
  case "$mode" in
    fail)
      exit 1
      ;;
    missing_field)
      printf '{"Version":1,"AccessKeyId":"%s","SecretAccessKey":"%s","SessionToken":""}\n' \
        "${MOCK_ACCESS_KEY_ID:-MOCKAKIAEXAMPLE}" "${MOCK_SECRET_ACCESS_KEY:-MOCKSECRETEXAMPLE}"
      ;;
    success)
      printf '{"Version":1,"AccessKeyId":"%s","SecretAccessKey":"%s","SessionToken":"%s","Expiration":"2099-01-01T00:00:00Z"}\n' \
        "${MOCK_ACCESS_KEY_ID:-MOCKAKIAEXAMPLE}" "${MOCK_SECRET_ACCESS_KEY:-MOCKSECRETEXAMPLE}" "${MOCK_SESSION_TOKEN:-MOCKSESSIONTOKENEXAMPLE}"
      ;;
    *)
      echo "mock-aws.sh: unknown MOCK_EXPORT_CREDS_MODE '$mode'" >&2
      exit 2
      ;;
  esac
  exit 0
fi

if [ "$1" = "sts" ] && [ "$2" = "get-caller-identity" ]; then
  mode="${MOCK_IDENTITY_MODE:-success}"
  if [ "$mode" = "fail" ]; then
    exit 1
  fi
  printf '{"Account":"%s","Arn":"%s","UserId":"MOCKUSERID"}\n' \
    "${MOCK_IDENTITY_ACCOUNT:-603013471426}" "${MOCK_IDENTITY_ARN:-arn:aws:iam::603013471426:user/firmsbase-staging-operator}"
  exit 0
fi

if [ "$1" = "configure" ] && [ "$2" = "get" ] && [ "$3" = "region" ]; then
  printf '%s\n' "${MOCK_AWS_REGION:-us-east-1}"
  exit 0
fi

echo "mock-aws.sh: unexpected invocation: $*" >&2
exit 2
