terraform {
  # Same pin as staging and production: the S3 backend uses native lockfile
  # locking (use_lockfile), a Terraform 1.11+ feature, and 1.15.x is the
  # version these backends were validated against. Use the pinned binary at
  # /home/ubuntu/bin/terraform-1.15.8 — the 1.9.8 on PATH must not be used.
  required_version = ">= 1.15.0, < 2.0.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
  }
}

provider "aws" {
  region              = var.aws_region
  allowed_account_ids = [var.aws_account_id]

  default_tags {
    tags = {
      Environment = "preproduction"
      Project     = "firmsbase"
      ManagedBy   = "terraform"
      Lifecycle   = "persistent"
    }
  }
}
