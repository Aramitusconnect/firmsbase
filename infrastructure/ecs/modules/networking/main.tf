# This module deliberately creates NOTHING. It exists to (a) validate the
# supplied VPC/subnet IDs actually belong together and belong to the
# expected VPC, via data-source lookups, and (b) give every other module a
# single, consistent place to depend on for "the network." See
# docs/ecs/infrastructure-architecture.md "VPC references or clearly
# documented VPC requirements" — this mission does not create VPCs, NAT
# gateways, or routing; those are pre-existing organizational infrastructure.

data "aws_vpc" "this" {
  id = var.vpc_id
}

data "aws_subnet" "public" {
  for_each = toset(var.public_subnet_ids)
  id       = each.value
}

data "aws_subnet" "private" {
  for_each = toset(var.private_subnet_ids)
  id       = each.value
}

# Fails plan-time (not apply-time) if a supplied subnet isn't actually in
# the supplied VPC — cheap sanity check against the most common copy/paste
# mistake when wiring environment tfvars.
resource "null_resource" "subnet_vpc_membership_guard" {
  lifecycle {
    precondition {
      condition = alltrue([
        for s in concat(values(data.aws_subnet.public), values(data.aws_subnet.private)) :
        s.vpc_id == var.vpc_id
      ])
      error_message = "One or more supplied subnet_ids do not belong to var.vpc_id."
    }
  }
}
