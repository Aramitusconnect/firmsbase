variable "vpc_id" {
  description = <<-EOT
    ID of an EXISTING VPC to deploy into. This module never creates a VPC —
    network topology (VPC CIDR, NAT gateway placement, existing peering/
    Transit Gateway attachments) is an organizational decision that predates
    this mission and must be supplied, not invented. See
    docs/ecs/infrastructure-architecture.md "VPC requirements".
  EOT
  type        = string
}

variable "public_subnet_ids" {
  description = "Subnets with a route to an Internet Gateway, for the ALB only. Application tasks never run in these subnets."
  type        = list(string)
}

variable "private_subnet_ids" {
  description = "Subnets with a route to a NAT Gateway (outbound only, no inbound route from the internet), for all ECS tasks."
  type        = list(string)
}
