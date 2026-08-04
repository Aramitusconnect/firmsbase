# Proves var.container_insights_enabled resolves the way
# docs/ecs/state-adoption-plan.md §9.14 claims: this module previously
# hardcoded containerInsights to "enabled" unconditionally, but this
# staging environment's live cluster actually has it disabled (confirmed
# via aws ecs describe-clusters) — a real, unreviewed Terraform-managed
# setting mismatch, not merely a naming issue. The variable is required
# (no default) so every caller must decide explicitly rather than
# silently inheriting a value that may not match the live cluster it's
# importing against.
#
# Run with: terraform test (from infrastructure/ecs/modules/ecs_cluster)

mock_provider "aws" {}

variables {
  cluster_name       = "firmsbase-staging-cluster"
  capacity_providers = []
}

run "true_renders_enabled" {
  command = plan

  variables {
    container_insights_enabled = true
  }

  assert {
    condition     = contains([for s in aws_ecs_cluster.this.setting : s.value if s.name == "containerInsights"], "enabled")
    error_message = "container_insights_enabled=true must render containerInsights=\"enabled\"."
  }
}

run "false_renders_disabled" {
  command = plan

  variables {
    container_insights_enabled = false
  }

  assert {
    condition     = contains([for s in aws_ecs_cluster.this.setting : s.value if s.name == "containerInsights"], "disabled")
    error_message = "container_insights_enabled=false must render containerInsights=\"disabled\" — this is what makes the resource importable against the live cluster (confirmed disabled via aws ecs describe-clusters) without silently diverging on the next plan."
  }
}

run "resource_address_is_unaffected_by_the_setting" {
  command = plan

  variables {
    container_insights_enabled = false
  }

  assert {
    condition     = aws_ecs_cluster.this.name == "firmsbase-staging-cluster"
    error_message = "Changing container_insights_enabled must not change the cluster's identity (name) or its own resource address (aws_ecs_cluster.this) — only the setting block's value changes."
  }
}
