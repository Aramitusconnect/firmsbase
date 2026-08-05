# Proves var.subnet_ids resolves the way docs/ecs/state-adoption-plan.md
# §9.15 claims: this module previously derived aws_elasticache_subnet_group's
# subnet_ids unconditionally from the caller's ECS private_subnet_ids (only
# 2 subnets), but the live subnet group actually registers 6 subnets across
# every AZ in the VPC — a genuinely different, broader concern than ECS
# task placement. subnet_ids is now a required (no default), explicitly
# named input, decoupled from private_subnet_ids entirely.
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

run "subnet_group_renders_the_supplied_subnet_ids" {
  command = plan

  assert {
    # aws_elasticache_subnet_group.subnet_ids is represented as a set of
    # strings (no addressable index / no defined order) — compare as a
    # set so ordering alone can never create false drift.
    condition     = toset(aws_elasticache_subnet_group.this.subnet_ids) == toset(var.subnet_ids)
    error_message = "aws_elasticache_subnet_group.this.subnet_ids must equal var.subnet_ids exactly, compared as a set."
  }

  assert {
    condition     = length(aws_elasticache_subnet_group.this.subnet_ids) == 6
    error_message = "Must render exactly 6 subnets when 6 are supplied."
  }
}

run "resource_address_is_unchanged" {
  command = plan

  assert {
    condition     = aws_elasticache_subnet_group.this.name == "firmsbase-staging-redis"
    error_message = "The subnet group's own identity (name) must resolve normally; only its subnet_ids source changed."
  }
}

run "subnet_ids_ordering_does_not_affect_equality" {
  command = plan

  variables {
    # Same 6 subnets, deliberately reordered.
    subnet_ids = [
      "subnet-06cb2ddbdb7cf4d69",
      "subnet-04f36560361246d4b",
      "subnet-020540b8377bb4d0e",
      "subnet-0631d53a7acde6530",
      "subnet-0d328451d742a4a3c",
      "subnet-07efcb5d4bcf5aa59",
    ]
  }

  assert {
    condition = toset(aws_elasticache_subnet_group.this.subnet_ids) == toset([
      "subnet-020540b8377bb4d0e",
      "subnet-0d328451d742a4a3c",
      "subnet-07efcb5d4bcf5aa59",
      "subnet-04f36560361246d4b",
      "subnet-0631d53a7acde6530",
      "subnet-06cb2ddbdb7cf4d69",
    ])
    error_message = "Membership must match as a set regardless of input ordering."
  }
}
