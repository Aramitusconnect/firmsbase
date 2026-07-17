#!/usr/bin/env bash
# =============================================================================
# HTTP-EXPOSURE PREFLIGHT — READ-ONLY, NOT EXECUTED. Run before
# 01-register-runtime-task-definitions.sh and again automatically inside
# 02-launch-web-service.sh (this file is sourced there so both call sites
# share the exact same check logic — never two copies that could drift).
#
# Creates and modifies NOTHING. Does NOT alter the ALB or any security
# group — if this report shows HTTP is open publicly, that is a
# containment requirement for a SEPARATE, administrator-reviewed action,
# not something this script (or any script in this package) fixes
# automatically.
#
# Prints only the ALB's own listener/scheme/target-group configuration and
# the ingress rules of the ALB's OWN security group — never unrelated
# security-group rules from other resources in the account.
#
# Writes a sanitized evidence file (00-http-exposure-preflight-evidence.json)
# containing only non-secret AWS resource metadata (ARNs, ports, protocols,
# CIDRs, booleans) — no credentials, no secret values. Both
# 01-register-runtime-task-definitions.sh and 02-launch-web-service.sh
# require this file to exist before proceeding.
# =============================================================================
set -euo pipefail
set +x
export AWS_PAGER=""

REGION=us-east-1
EXPECTED_ACCOUNT=603013471426
EXPECTED_ARN="arn:aws:iam::603013471426:user/firmsbase-staging-operator"
ALB_NAME=firmsbase-staging-alb
ALB_SG=sg-02a26ff122a9a1d29
TG_ARN="arn:aws:elasticloadbalancing:us-east-1:603013471426:targetgroup/firmsbase-staging-tg/1830c01b9aaac37d"
VPC_ID=vpc-0fd81b688155ded2b
EVIDENCE_FILE=00-http-exposure-preflight-evidence.json

# -----------------------------------------------------------------------------
# All checks live in one function so 02-launch-web-service.sh can source this
# file and re-run the exact same live checks immediately before web creation,
# rather than maintaining a second, potentially-diverging copy.
# -----------------------------------------------------------------------------
run_http_exposure_checks() {
  echo "=== Step 0: verify caller identity ==="
  local identity caller_account caller_arn
  identity=$(aws sts get-caller-identity --region "$REGION")
  caller_account=$(echo "$identity" | jq -r '.Account')
  caller_arn=$(echo "$identity" | jq -r '.Arn')
  echo "Account: $caller_account"
  echo "Arn: $caller_arn"
  [ "$caller_account" = "$EXPECTED_ACCOUNT" ] || { echo "STOP: wrong account." >&2; return 1; }
  [ "$caller_arn" = "$EXPECTED_ARN" ] || { echo "STOP: caller ARN is not the expected restricted operator." >&2; return 1; }

  echo ""
  echo "=== ALB identity, scheme, state, VPC ==="
  local alb alb_arn alb_scheme alb_state alb_vpc alb_dns
  alb=$(aws elbv2 describe-load-balancers --region "$REGION" --names "$ALB_NAME")
  alb_arn=$(echo "$alb" | jq -r '.LoadBalancers[0].LoadBalancerArn')
  alb_scheme=$(echo "$alb" | jq -r '.LoadBalancers[0].Scheme')
  alb_state=$(echo "$alb" | jq -r '.LoadBalancers[0].State.Code')
  alb_vpc=$(echo "$alb" | jq -r '.LoadBalancers[0].VpcId')
  alb_dns=$(echo "$alb" | jq -r '.LoadBalancers[0].DNSName')
  echo "ALB name: $ALB_NAME"
  echo "ALB ARN: $alb_arn"
  echo "Scheme: $alb_scheme"
  echo "State: $alb_state"
  echo "VPC: $alb_vpc"
  echo "DNS name: $alb_dns"
  [ "$alb_vpc" = "$VPC_ID" ] || echo "NOTE: ALB VPC ($alb_vpc) does not match the expected ECS VPC ($VPC_ID) — reviewed manually." >&2

  echo ""
  echo "=== Listener ports and protocols ==="
  local listeners http_listener_count https_listener_count
  listeners=$(aws elbv2 describe-listeners --region "$REGION" --load-balancer-arn "$alb_arn")
  echo "$listeners" | jq -c '.Listeners[] | {port: .Port, protocol: .Protocol}'
  http_listener_count=$(echo "$listeners" | jq '[.Listeners[] | select(.Protocol == "HTTP")] | length')
  https_listener_count=$(echo "$listeners" | jq '[.Listeners[] | select(.Protocol == "HTTPS")] | length')
  echo "HTTP listeners: $http_listener_count   HTTPS listeners: $https_listener_count"

  echo ""
  echo "=== Target group: ARN, type, protocol, port, health check ==="
  local tg_desc tg_actual_arn tg_type tg_protocol tg_port tg_health_path tg_matcher
  tg_desc=$(aws elbv2 describe-target-groups --region "$REGION" --target-group-arns "$TG_ARN")
  tg_actual_arn=$(echo "$tg_desc" | jq -r '.TargetGroups[0].TargetGroupArn')
  tg_type=$(echo "$tg_desc" | jq -r '.TargetGroups[0].TargetType')
  tg_protocol=$(echo "$tg_desc" | jq -r '.TargetGroups[0].Protocol')
  tg_port=$(echo "$tg_desc" | jq -r '.TargetGroups[0].Port')
  tg_health_path=$(echo "$tg_desc" | jq -r '.TargetGroups[0].HealthCheckPath')
  tg_matcher=$(echo "$tg_desc" | jq -r '.TargetGroups[0].Matcher.HttpCode')
  echo "TargetGroupArn: $tg_actual_arn"
  echo "TargetType: $tg_type  Protocol: $tg_protocol  Port: $tg_port"
  echo "HealthCheckPath: $tg_health_path  Matcher: $tg_matcher"

  echo ""
  echo "=== Existing registered targets (should be zero before first launch) ==="
  local target_health existing_target_count
  target_health=$(aws elbv2 describe-target-health --region "$REGION" --target-group-arn "$TG_ARN")
  existing_target_count=$(echo "$target_health" | jq '.TargetHealthDescriptions | length')
  echo "Existing registered targets: $existing_target_count"

  echo ""
  echo "=== ALB security-group ingress (this ALB's own SG only — no unrelated rules) ==="
  local sg_desc ingress_80_443 public_ingress
  sg_desc=$(aws ec2 describe-security-groups --region "$REGION" --group-ids "$ALB_SG")
  ingress_80_443=$(echo "$sg_desc" | jq -c '[.SecurityGroups[0].IpPermissions[] | select(.FromPort == 80 or .ToPort == 80 or .FromPort == 443 or .ToPort == 443 or .FromPort == null)]')
  echo "$sg_desc" | jq -c '.SecurityGroups[0].IpPermissions[] | {fromPort, toPort, ipProtocol, ipRanges: [.IpRanges[]?.CidrIp], ipv6Ranges: [.Ipv6Ranges[]?.CidrIpv6]}'
  public_ingress=$(echo "$sg_desc" | jq '[.SecurityGroups[0].IpPermissions[] | select((.IpRanges[]?.CidrIp == "0.0.0.0/0") or (.Ipv6Ranges[]?.CidrIpv6 == "::/0"))] | length')
  echo "Ingress rules open to the entire internet (0.0.0.0/0 or ::/0): $public_ingress"

  echo ""
  echo "=== Public DNS record check (best-effort — Route 53 hosted zones in this account only) ==="
  local hosted_zones found_record=0 route53_matches="[]"
  hosted_zones=$(aws route53 list-hosted-zones --query 'HostedZones[].Id' --output json 2>/dev/null || echo "[]")
  for zone_id in $(echo "$hosted_zones" | jq -r '.[]'); do
    local matches count
    matches=$(aws route53 list-resource-record-sets --hosted-zone-id "$zone_id" \
      --query "ResourceRecordSets[?AliasTarget.DNSName=='${alb_dns}.' || (ResourceRecords && contains(ResourceRecords[].Value, '${alb_dns}'))]" \
      --output json 2>/dev/null || echo "[]")
    count=$(echo "$matches" | jq 'length')
    if [ "$count" != "0" ]; then
      found_record=1
      route53_matches=$(echo "$matches" | jq -c '[.[] | {Name, Type}]')
      echo "Record found in zone $zone_id pointing at this ALB:"
      echo "$matches" | jq -c '.[] | {Name, Type}'
    fi
  done
  if [ "$found_record" = "0" ]; then
    echo "No record found in this account's Route 53 hosted zones pointing at $alb_dns."
    echo "NOTE: this check cannot see DNS records at an external/third-party registrar."
    echo "If a public domain is pointed at this ALB through a provider outside this AWS"
    echo "account's Route 53, that must be confirmed separately — do not assume 'no"
    echo "Route 53 record' means 'no public DNS exposure'."
  fi

  echo ""
  echo "=== Verdict ==="
  local verdict_token="none"
  if [ "$public_ingress" != "0" ]; then
    echo "HTTP_PUBLIC_EXPOSURE_CONFIRMED"
    echo "CONTAINMENT_REVIEW_REQUIRED_BEFORE_WEB_LAUNCH"
    echo ""
    echo "The ALB security group has $public_ingress ingress rule(s) open to the entire"
    echo "internet (0.0.0.0/0 / ::/0). HTTP port 80 is technically public — anyone on the"
    echo "internet who knows or guesses the ALB DNS name can reach it right now."
    echo "This is NOT automatically a script failure: synthetic staging verification may"
    echo "still be deliberately authorized over public HTTP. But web-service creation"
    echo "requires the SEPARATE explicit acknowledgement CONFIRMED_PUBLIC_HTTP_SYNTHETIC_TESTING=yes,"
    echo "which itself represents that: no real user traffic is authorized; no client data"
    echo "may be used; no firm invitations may be sent; HTTPS remains a mandatory"
    echo "subsequent gate before any production approval."
    verdict_token="HTTP_PUBLIC_EXPOSURE_CONFIRMED"
  else
    echo "ALB security-group ingress does not include an open-to-the-internet (0.0.0.0/0"
    echo "or ::/0) rule for the checked ports. HTTP access appears restricted to the"
    echo "specific sources listed above."
    verdict_token="restricted"
  fi

  echo ""
  echo "Regardless of the verdict above, the following remain true unconditionally:"
  echo "  - no client data may be sent to this environment;"
  echo "  - no real firm accounts may be created or used;"
  echo "  - no customer invitations may be sent;"
  echo "  - no public launch is authorized;"
  echo "  - no production approval exists before HTTPS is configured and verified."
  echo "A synthetic-health-check pass in 03/07 is never, by itself, authorization for"
  echo "real traffic — see staging-deploy/https-remediation-plan.md."

  jq -n \
    --arg generated_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --arg caller_arn "$caller_arn" \
    --arg alb_arn "$alb_arn" \
    --arg alb_name "$ALB_NAME" \
    --arg scheme "$alb_scheme" \
    --arg state "$alb_state" \
    --arg vpc "$alb_vpc" \
    --argjson listeners "$(echo "$listeners" | jq -c '[.Listeners[] | {port: .Port, protocol: .Protocol}]')" \
    --arg tg_arn "$tg_actual_arn" \
    --arg tg_type "$tg_type" \
    --arg tg_protocol "$tg_protocol" \
    --arg tg_port "$tg_port" \
    --arg tg_health_path "$tg_health_path" \
    --arg tg_matcher "$tg_matcher" \
    --argjson existing_target_count "$existing_target_count" \
    --arg alb_sg "$ALB_SG" \
    --argjson ingress_80_443 "$ingress_80_443" \
    --argjson public_ingress_rule_count "$public_ingress" \
    --argjson route53_record_found "$([ "$found_record" = "1" ] && echo true || echo false)" \
    --argjson route53_matches "$route53_matches" \
    --arg verdict "$verdict_token" \
    '{
      generated_at: $generated_at,
      caller_arn: $caller_arn,
      alb: {arn: $alb_arn, name: $alb_name, scheme: $scheme, state: $state, vpc: $vpc, listeners: $listeners},
      target_group: {arn: $tg_arn, type: $tg_type, protocol: $tg_protocol, port: $tg_port, health_check_path: $tg_health_path, health_check_matcher: $tg_matcher, existing_target_count: $existing_target_count},
      alb_security_group: {id: $alb_sg, ingress_rules_80_443: $ingress_80_443, public_ingress_rule_count: $public_ingress_rule_count},
      route53: {record_found: $route53_record_found, matches: $route53_matches},
      verdict: $verdict
    }' > "$EVIDENCE_FILE"
  jq empty "$EVIDENCE_FILE" || { echo "STOP: evidence file failed to serialize as valid JSON." >&2; return 1; }
  echo ""
  echo "Sanitized evidence written: $EVIDENCE_FILE (no secrets)."
  return 0
}

# Only execute standalone when run directly — not when sourced by
# 02-launch-web-service.sh for its own live re-check.
if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
  run_http_exposure_checks
  echo ""
  echo "=== HTTP-exposure preflight complete. Read-only. No AWS resource was created, modified, or deleted. ==="
fi
