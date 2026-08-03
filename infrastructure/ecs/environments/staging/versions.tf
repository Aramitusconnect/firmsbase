terraform {
  # Approved CI/operator binary is the pinned 1.15.8 install at
  # /home/ubuntu/bin/terraform-1.15.8 (this environment's default
  # `terraform` on PATH remains 1.9.8 and must NOT be used here — see
  # scripts/tf-guard.sh, which enforces this for plan/apply). >=1.15.0 is
  # required because the backend below uses S3 native lockfile locking
  # (`use_lockfile`), a Terraform 1.11+ feature; 1.15+ is the specific
  # version this backend was validated and approved against.
  required_version = ">= 1.15.0, < 2.0.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
  }

  # Approved AWS backend — reviewed and provisioned 2026-08-03. The
  # bucket (firmsbase-terraform-state-603013471426-us-east-1, account
  # 603013471426, region us-east-1) is encrypted at rest with SSE-S3,
  # versioned, and private: Block Public Access is fully enabled, and
  # object ownership is "bucket owner enforced" (ACLs disabled). Object
  # Lock is disabled — durability/rollback come from bucket versioning,
  # not S3 Object Lock retention. Locking is native S3 lockfile locking
  # (`use_lockfile = true`, a `<key>.tflock` companion object) — no
  # DynamoDB table exists or is needed for this backend. The operator
  # role (firmsbase-staging-operator-login) holds a narrowly scoped
  # customer-managed policy (FirmsBaseStagingTerraformStateAccess)
  # granting access to exactly this state key and its `.tflock` object,
  # nothing broader.
  #
  # This block only tells Terraform WHERE state lives — it does not by
  # itself adopt, plan, or apply anything. As of this commit the state
  # prefix (environments/staging/ecs/) is confirmed empty (0 objects,
  # verified read-only via `aws s3api list-objects-v2`): no
  # `terraform init` has been run against this backend, no state or
  # `.tflock` object has been written, and no live AWS resource has been
  # imported. `terraform plan`/`apply` remain prohibited by
  # scripts/tf-guard.sh until the documented import procedure
  # (docs/ecs/state-adoption-plan.md §8) reaches its required
  # checkpoints — configuring the backend is a separate, earlier step
  # from state adoption, not a substitute for it. No profile, access
  # key, secret key, session token, or role ARN is set here — credential
  # resolution is left to the environment (AWS_PROFILE/instance
  # role/OIDC), exactly as the AWS provider block below already does.
  backend "s3" {
    bucket       = "firmsbase-terraform-state-603013471426-us-east-1"
    key          = "environments/staging/ecs/terraform.tfstate"
    region       = "us-east-1"
    encrypt      = true
    use_lockfile = true
  }
}

provider "aws" {
  region = var.aws_region

  default_tags {
    tags = {
      Project     = "firmsbase"
      Environment = "staging"
      ManagedBy   = "terraform"
      Mission     = "ecs-readiness-foundation"
    }
  }
}
