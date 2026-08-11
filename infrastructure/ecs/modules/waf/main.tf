# Mission 1B (Extreme Security Hardening), sections 15/16/40. AWS WAFv2
# Web ACL for the internet-facing ALB. Complete no-op when
# var.enabled = false (the default) — see variables.tf. Regional scope
# (not CloudFront), matching an ALB target.
#
# Rule priorities, lowest first (AWS WAFv2 evaluates in priority order):
#   0. Sensitive-path rate limit (login/password-reset/MFA) — narrower
#      and stricter, evaluated first so it isn't shadowed by the
#      broader rule below matching the same request.
#   1. AWSManagedRulesCommonRuleSet — baseline OWASP-style protections.
#   2. AWSManagedRulesKnownBadInputsRuleSet — known exploit signatures.
#   3. AWSManagedRulesAmazonIpReputationList — known-bad source IPs.
#   4. Site-wide rate limit.
#
# Every managed rule group and both rate-based rules default to COUNT
# action via override_action/action blocks — see variables.tf's own
# rollout-safety reasoning. A CloudWatch metric is enabled per rule (and
# for the Web ACL overall) so count-mode traffic is actually visible
# before anyone decides to flip a rule to BLOCK.

resource "aws_wafv2_web_acl" "this" {
  count = var.enabled ? 1 : 0

  name        = "${var.name_prefix}-waf"
  description = "FirmsVault application WAF — see docs/security/mission-1-canonical-domain-security-threat-model.md and Mission 1B's own threat model additions."
  scope       = "REGIONAL"

  default_action {
    allow {}
  }

  rule {
    name     = "sensitive-path-rate-limit"
    priority = 0

    action {
      dynamic "count" {
        for_each = var.rate_based_rule_action == "count" ? [1] : []
        content {}
      }
      dynamic "block" {
        for_each = var.rate_based_rule_action == "block" ? [1] : []
        content {}
      }
    }

    statement {
      rate_based_statement {
        limit              = var.sensitive_path_rate_limit_per_5_minutes
        aggregate_key_type = "IP"

        scope_down_statement {
          or_statement {
            dynamic "statement" {
              for_each = var.sensitive_path_prefixes
              content {
                byte_match_statement {
                  search_string         = statement.value
                  positional_constraint = "STARTS_WITH"

                  field_to_match {
                    uri_path {}
                  }

                  text_transformation {
                    priority = 0
                    type     = "NONE"
                  }
                }
              }
            }
          }
        }
      }
    }

    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "${var.name_prefix}-waf-sensitive-path-rate-limit"
      sampled_requests_enabled   = true
    }
  }

  rule {
    name     = "aws-managed-common"
    priority = 1

    override_action {
      dynamic "count" {
        for_each = var.managed_rule_action == "count" ? [1] : []
        content {}
      }
      dynamic "none" {
        for_each = var.managed_rule_action == "block" ? [1] : []
        content {}
      }
    }

    statement {
      managed_rule_group_statement {
        name        = "AWSManagedRulesCommonRuleSet"
        vendor_name = "AWS"
      }
    }

    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "${var.name_prefix}-waf-common"
      sampled_requests_enabled   = true
    }
  }

  rule {
    name     = "aws-managed-known-bad-inputs"
    priority = 2

    override_action {
      dynamic "count" {
        for_each = var.managed_rule_action == "count" ? [1] : []
        content {}
      }
      dynamic "none" {
        for_each = var.managed_rule_action == "block" ? [1] : []
        content {}
      }
    }

    statement {
      managed_rule_group_statement {
        name        = "AWSManagedRulesKnownBadInputsRuleSet"
        vendor_name = "AWS"
      }
    }

    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "${var.name_prefix}-waf-known-bad-inputs"
      sampled_requests_enabled   = true
    }
  }

  rule {
    name     = "aws-managed-ip-reputation"
    priority = 3

    override_action {
      dynamic "count" {
        for_each = var.managed_rule_action == "count" ? [1] : []
        content {}
      }
      dynamic "none" {
        for_each = var.managed_rule_action == "block" ? [1] : []
        content {}
      }
    }

    statement {
      managed_rule_group_statement {
        name        = "AWSManagedRulesAmazonIpReputationList"
        vendor_name = "AWS"
      }
    }

    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "${var.name_prefix}-waf-ip-reputation"
      sampled_requests_enabled   = true
    }
  }

  rule {
    name     = "site-wide-rate-limit"
    priority = 4

    action {
      dynamic "count" {
        for_each = var.rate_based_rule_action == "count" ? [1] : []
        content {}
      }
      dynamic "block" {
        for_each = var.rate_based_rule_action == "block" ? [1] : []
        content {}
      }
    }

    statement {
      rate_based_statement {
        limit              = var.rate_limit_per_5_minutes
        aggregate_key_type = "IP"
      }
    }

    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "${var.name_prefix}-waf-site-wide-rate-limit"
      sampled_requests_enabled   = true
    }
  }

  visibility_config {
    cloudwatch_metrics_enabled = true
    metric_name                = "${var.name_prefix}-waf"
    sampled_requests_enabled   = true
  }

  tags = var.tags
}

resource "aws_wafv2_web_acl_association" "this" {
  count = var.enabled ? 1 : 0

  resource_arn = var.alb_arn
  web_acl_arn  = aws_wafv2_web_acl.this[0].arn
}
