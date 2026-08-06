# Proves the first-plan-blocker adoption-alignment correction (see
# docs/ecs/state-adoption-plan.md §9.24): security_group_name/
# security_group_description model the exact live Redis security group
# instead of forcing its replacement; subnet_group_description/
# replication_group_description preserve live descriptions instead of
# being silently overwritten; snapshot_retention_limit preserves the
# verified live value instead of silently disabling backups.
#
# Run with: terraform test (from infrastructure/ecs/modules/elasticache)

mock_provider "aws" {}

variables {
  name_prefix                 = "firmsbase-staging"
  vpc_id                      = "vpc-0fd81b688155ded2b"
  ecs_tasks_security_group_id = "sg-0db14e50ea5c5466c"
  auth_token                  = "test-auth-token-not-real"
  subnet_ids = [
    "subnet-020540b8377bb4d0e",
    "subnet-0d328451d742a4a3c",
    "subnet-07efcb5d4bcf5aa59",
    "subnet-04f36560361246d4b",
    "subnet-0631d53a7acde6530",
    "subnet-06cb2ddbdb7cf4d69",
  ]
}

run "security_group_defaults_to_name_prefix_when_not_overridden" {
  command = plan

  # aws_security_group.redis.name is unknown-until-apply here (AWS
  # generates the final name from name_prefix), so only name_prefix
  # itself — a plain config attribute, known at plan time — is asserted.
  assert {
    condition     = aws_security_group.redis.name_prefix == "firmsbase-staging-redis-"
    error_message = "Without security_group_name set, name_prefix must resolve to the module's original pattern."
  }

  assert {
    condition     = aws_security_group.redis.description == "FirmsBase ElastiCache Redis — ingress from ECS tasks only."
    error_message = "Without security_group_description set, the module's own default description must apply."
  }
}

run "security_group_models_the_exact_live_name_and_description_when_overridden" {
  command = plan

  variables {
    security_group_name        = "firmsbase-staging-redis-sg"
    security_group_description = "Valkey access from FirmsBase staging ECS tasks"
  }

  assert {
    condition     = aws_security_group.redis.name == "firmsbase-staging-redis-sg"
    error_message = "security_group_name must resolve to the exact live name."
  }

  # name_prefix itself is unknown-until-apply on this side (the resource's
  # computed name/name_prefix pairing), so it is not asserted directly
  # here — the config-level ternary (name_prefix = var.security_group_name
  # == null ? ... : null) is proven by source-text tests instead. name
  # above is what actually matters: it resolves to the exact live value.

  assert {
    condition     = aws_security_group.redis.description == "Valkey access from FirmsBase staging ECS tasks"
    error_message = "security_group_description must resolve to the exact live description."
  }
}

run "subnet_group_description_defaults_to_the_provider_placeholder_when_not_overridden" {
  command = plan

  assert {
    condition     = aws_elasticache_subnet_group.this.description == "Managed by Terraform"
    error_message = "Without subnet_group_description set, this must resolve to the AWS provider schema's own default — fine for a brand-new environment."
  }
}

run "subnet_group_description_preserves_the_live_value_when_overridden" {
  command = plan

  variables {
    subnet_group_description = "Subnets for FirmsBase staging Valkey"
  }

  assert {
    condition     = aws_elasticache_subnet_group.this.description == "Subnets for FirmsBase staging Valkey"
    error_message = "subnet_group_description must resolve to the exact live description, not the generic placeholder."
  }
}

run "replication_group_description_defaults_to_the_modules_own_description_when_not_overridden" {
  command = plan

  assert {
    condition     = aws_elasticache_replication_group.this.description == "FirmsBase staging Redis — cache/session/queue/locks (see docs/ecs/queue-and-redis-architecture.md)"
    error_message = "Without replication_group_description set, the module's own default description must apply."
  }
}

run "replication_group_description_preserves_the_live_value_when_overridden" {
  command = plan

  variables {
    replication_group_description = "Valkey for FirmsBase staging sessions, cache, and queues"
  }

  assert {
    condition     = aws_elasticache_replication_group.this.description == "Valkey for FirmsBase staging sessions, cache, and queues"
    error_message = "replication_group_description must resolve to the exact live description."
  }
}

run "snapshot_retention_limit_defaults_to_zero_for_a_brand_new_environment" {
  command = plan

  assert {
    condition     = aws_elasticache_replication_group.this.snapshot_retention_limit == 0
    error_message = "Without snapshot_retention_limit set, this must default to 0 (disabled) — safe for a brand-new staging environment holding no durable data."
  }
}

run "snapshot_retention_limit_preserves_the_verified_live_value_when_overridden" {
  command = plan

  variables {
    snapshot_retention_limit = 1
  }

  assert {
    condition     = aws_elasticache_replication_group.this.snapshot_retention_limit == 1
    error_message = "snapshot_retention_limit must resolve to the exact verified live value (1), never silently reset to 0."
  }
}

run "resource_addresses_and_security_group_reference_are_unaffected" {
  command = plan

  variables {
    security_group_name           = "firmsbase-staging-redis-sg"
    security_group_description    = "Valkey access from FirmsBase staging ECS tasks"
    subnet_group_description      = "Subnets for FirmsBase staging Valkey"
    replication_group_description = "Valkey for FirmsBase staging sessions, cache, and queues"
    snapshot_retention_limit      = 1
  }

  assert {
    condition     = aws_elasticache_replication_group.this.replication_group_id == "firmsbase-staging-redis"
    error_message = "The replication group's own identity must be unaffected by these overrides."
  }

  assert {
    condition     = aws_elasticache_subnet_group.this.name == "firmsbase-staging-redis"
    error_message = "The subnet group's own identity must be unaffected by these overrides."
  }
}
