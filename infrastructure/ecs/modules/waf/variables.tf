variable "name_prefix" {
  description = "Naming prefix for WAF resources, matching this environment's other modules."
  type        = string
}

variable "alb_arn" {
  description = "ARN of the ALB to associate this Web ACL with."
  type        = string
}

# Mission 1B (Extreme Security Hardening), section 15/40: WAF protection
# must never be deployed in a way that can lock out legitimate production
# users without a rollback path. `enabled = false` (the default) makes
# this ENTIRE module a no-op — no Web ACL, no association, nothing
# created — so adopting it is an explicit, later, reviewed decision, not
# a side effect of this mission's own code landing.
variable "enabled" {
  description = "Whether to create and associate the Web ACL at all. Defaults to false (complete no-op)."
  type        = bool
  default     = false
}

# Section 15: "Use count/monitor mode first where appropriate." Every
# managed rule group and the rate-based rule below default to COUNT
# (log/observe, never block) — flipping individual rules to BLOCK is a
# separate, later, reviewed decision per rule, made after observing real
# traffic in count mode, never bundled into this mission's own rollout.
variable "managed_rule_action" {
  description = "\"count\" or \"block\" for the AWS managed rule groups. Defaults to \"count\" (observe only, per this mission's own rollout-safety requirement)."
  type        = string
  default     = "count"

  validation {
    condition     = contains(["count", "block"], var.managed_rule_action)
    error_message = "managed_rule_action must be exactly \"count\" or \"block\"."
  }
}

variable "rate_based_rule_action" {
  description = "\"count\" or \"block\" for the rate-based rule. Defaults to \"count\"."
  type        = string
  default     = "count"

  validation {
    condition     = contains(["count", "block"], var.rate_based_rule_action)
    error_message = "rate_based_rule_action must be exactly \"count\" or \"block\"."
  }
}

variable "rate_limit_per_5_minutes" {
  description = "Requests per 5-minute sliding window, per source IP, before the rate-based rule matches. AWS WAFv2's own minimum is 100."
  type        = number
  default     = 3000

  validation {
    condition     = var.rate_limit_per_5_minutes >= 100
    error_message = "AWS WAFv2 rate-based rules require a limit of at least 100."
  }
}

# Section 15: "Apply more aggressive limits to login, password reset,
# Admin". A second, stricter rate-based rule scoped to these path
# prefixes — every canonical panel currently mounts at path('') on its
# own host (see Mission 1C), so these prefixes are host-independent and
# stable across app./client./admin.firmsvault.com.
variable "sensitive_path_prefixes" {
  description = "URI path prefixes (login, password-reset, MFA) that get the stricter rate limit below, regardless of which canonical host they're matched on."
  type        = list(string)
  default     = ["/login", "/password-reset", "/multi-factor-authentication"]
}

variable "sensitive_path_rate_limit_per_5_minutes" {
  description = "Requests per 5-minute window, per source IP, for the sensitive-path rule."
  type        = number
  default     = 300

  validation {
    condition     = var.sensitive_path_rate_limit_per_5_minutes >= 100
    error_message = "AWS WAFv2 rate-based rules require a limit of at least 100."
  }
}

variable "tags" {
  description = "Tags applied to the Web ACL."
  type        = map(string)
  default     = {}
}
