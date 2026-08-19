# Ephemeral runtime state. Destroyed and recreated once per release
# certification cycle; the certificate lives in environments/preproduction/
# shared/terraform.tfstate and is deliberately out of reach of this root.
terraform {
  backend "s3" {
    bucket       = "firmsbase-terraform-state-603013471426-us-east-1"
    key          = "environments/preproduction/ecs/terraform.tfstate"
    region       = "us-east-1"
    encrypt      = true
    use_lockfile = true
  }
}
