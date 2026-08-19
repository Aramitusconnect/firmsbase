# Reuses the same hardened state bucket staging uses, under a distinct
# production key. Locking is S3 native lockfile (use_lockfile) — the
# established mechanism here; there is no DynamoDB lock table to reuse.
#
# No credentials appear in this file or in -backend-config: resolution is left
# to the environment (assumed-role session credentials), exactly as staging does.
terraform {
  backend "s3" {
    bucket       = "firmsbase-terraform-state-603013471426-us-east-1"
    key          = "environments/production/ecs/terraform.tfstate"
    region       = "us-east-1"
    encrypt      = true
    use_lockfile = true
  }
}
