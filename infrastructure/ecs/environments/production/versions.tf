terraform {
  # Must match staging: the S3 backend below uses native lockfile locking
  # (use_lockfile), a Terraform 1.11+ feature, and 1.15.x is the version this
  # backend was validated against. scripts/tf-guard.sh enforces the pinned
  # /home/ubuntu/bin/terraform-1.15.8 binary — the 1.9.8 on PATH must not be
  # used for plan/apply.
  required_version = ">= 1.15.0, < 2.0.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
  }
}

provider "aws" {
  region              = var.aws_region
  allowed_account_ids = [var.aws_account_id]

  default_tags {
    tags = {
      Environment = "production"
      Project     = "firmsbase"
      ManagedBy   = "terraform"
    }
  }
}
