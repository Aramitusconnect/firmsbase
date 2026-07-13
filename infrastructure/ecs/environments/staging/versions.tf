terraform {
  required_version = ">= 1.7"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
  }

  # Remote state backend is intentionally NOT configured here. This mission
  # does not choose/provision a state backend (S3 bucket + DynamoDB lock
  # table, or Terraform Cloud) on the user's behalf — that is an
  # organizational decision (existing account conventions may already have
  # one) tracked as a required input in
  # docs/ecs/staging-readiness-report.md. Configure via `terraform init
  # -backend-config=...` or uncomment and fill in a `backend "s3" {}` block
  # once that decision is made. Until then, state is local — do not run
  # `terraform apply` against local state for anything beyond a solo
  # `terraform validate`/`plan` dry run.
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
