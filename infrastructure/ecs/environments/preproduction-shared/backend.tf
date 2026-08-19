# Persistent control-plane state for preproduction, deliberately separate from
# the ephemeral runtime state. The runtime root is destroyed and recreated once
# per release-certification cycle; anything in THIS state must survive that.
terraform {
  backend "s3" {
    bucket       = "firmsbase-terraform-state-603013471426-us-east-1"
    key          = "environments/preproduction/shared/terraform.tfstate"
    region       = "us-east-1"
    encrypt      = true
    use_lockfile = true
  }
}
