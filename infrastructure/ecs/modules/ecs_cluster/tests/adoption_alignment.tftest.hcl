# Proves the ECS-cluster tag adoption-alignment correction (see
# docs/ecs/state-adoption-plan.md §9.26 [ALB/ECR/cluster/service wave]):
# cluster_adoption_tags defaults to {} for a brand-new environment and,
# when supplied, merges the live cluster's pre-Terraform-adoption tags
# alongside var.tags — explicitly modeled, not silently dropped.
#
# Run with: terraform test (from infrastructure/ecs/modules/ecs_cluster)

mock_provider "aws" {}

variables {
  cluster_name               = "firmsbase-staging-cluster"
  capacity_providers         = []
  container_insights_enabled = false
}

run "cluster_adoption_tags_defaults_to_empty_for_a_brand_new_environment" {
  command = plan

  assert {
    condition     = length(aws_ecs_cluster.this.tags) == 0
    error_message = "Without cluster_adoption_tags or tags set, the cluster's tags must be empty — a brand-new environment must be unaffected."
  }
}

run "cluster_adoption_tags_models_the_exact_live_tags_when_supplied" {
  command = plan

  variables {
    cluster_adoption_tags = {
      Application = "FirmsBase"
      Name        = "firmsbase-staging-cluster"
    }
  }

  assert {
    condition     = aws_ecs_cluster.this.tags == tomap({ Application = "FirmsBase", Name = "firmsbase-staging-cluster" })
    error_message = "cluster_adoption_tags must be merged onto the cluster, exactly reproducing the confirmed live tags."
  }
}

run "cluster_adoption_tags_merge_alongside_ordinary_tags" {
  command = plan

  variables {
    tags = {
      Environment = "staging"
    }
    cluster_adoption_tags = {
      Application = "FirmsBase"
      Name        = "firmsbase-staging-cluster"
    }
  }

  assert {
    condition     = aws_ecs_cluster.this.tags == tomap({ Environment = "staging", Application = "FirmsBase", Name = "firmsbase-staging-cluster" })
    error_message = "cluster_adoption_tags must merge alongside var.tags, not replace it."
  }
}

run "cluster_identity_and_settings_unaffected_by_adoption_tags" {
  command = plan

  variables {
    cluster_adoption_tags = {
      Application = "FirmsBase"
      Name        = "firmsbase-staging-cluster"
    }
  }

  assert {
    condition     = aws_ecs_cluster.this.name == "firmsbase-staging-cluster"
    error_message = "The cluster's own identity (name) must be unaffected by cluster_adoption_tags."
  }

  assert {
    condition     = contains([for s in aws_ecs_cluster.this.setting : s.value if s.name == "containerInsights"], "disabled")
    error_message = "containerInsights must be unaffected by cluster_adoption_tags."
  }
}
