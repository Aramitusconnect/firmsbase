# Proves var.capacity_providers/var.default_capacity_provider actually
# resolve the way docs/ecs/state-adoption-plan.md §9.10/§9.11 claims — both
# the "new environment default" and the "live-adoption empty association"
# paths — without touching real AWS. Also serves as the empirical,
# offline/mocked check of whether an empty capacity_providers list plus
# zero default_capacity_provider_strategy blocks is schema-valid for
# aws_ecs_cluster_capacity_providers: if the provider's schema rejected an
# empty list outright (e.g. a MinItems constraint), this run would fail
# with a structural/schema error, not merely an assertion failure — no
# `terraform providers schema` command was run to determine this (out of
# scope for this mission); this test IS the determination.
#
# Run with: terraform test (from infrastructure/ecs/modules/ecs_cluster)

mock_provider "aws" {}

variables {
  cluster_name = "firmsbase-staging-cluster"
}

run "default_capacity_providers_match_original_module_design" {
  command = plan

  assert {
    condition     = aws_ecs_cluster_capacity_providers.this.capacity_providers == toset(["FARGATE", "FARGATE_SPOT"])
    error_message = "Without capacity_providers set, must default to [\"FARGATE\", \"FARGATE_SPOT\"] — original module design, unaffected for a brand-new environment."
  }

  assert {
    condition     = length(aws_ecs_cluster_capacity_providers.this.default_capacity_provider_strategy) == 1
    error_message = "With the default (non-empty) capacity_providers, exactly one default_capacity_provider_strategy block must render."
  }

  assert {
    # default_capacity_provider_strategy is represented as a set of objects
    # (no addressable index) — use a for/contains check instead of [0].
    condition     = contains([for s in aws_ecs_cluster_capacity_providers.this.default_capacity_provider_strategy : s.capacity_provider], "FARGATE")
    error_message = "The default strategy must use FARGATE as its capacity_provider by default."
  }
}

run "empty_capacity_providers_represents_the_live_adoption_state" {
  command = plan

  variables {
    capacity_providers = []
  }

  assert {
    condition     = length(aws_ecs_cluster_capacity_providers.this.capacity_providers) == 0
    error_message = "With capacity_providers=[], the resource must plan an empty association — this is what makes the resource address importable against the live cluster (capacityProviders: [], confirmed via aws ecs describe-clusters) without inventing a fake adoption value."
  }

  assert {
    condition     = length(aws_ecs_cluster_capacity_providers.this.default_capacity_provider_strategy) == 0
    error_message = "With capacity_providers=[], the default_capacity_provider_strategy block must be omitted entirely — a default strategy referencing zero associated providers would be nonsensical and does not match live (defaultCapacityProviderStrategy: [] live too)."
  }
}
