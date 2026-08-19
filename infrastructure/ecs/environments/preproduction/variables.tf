variable "aws_account_id" {
  description = "Pinned so a mis-set profile fails before it creates anything."
  type        = string
  default     = "603013471426"
}

variable "aws_region" {
  type    = string
  default = "us-east-1"
}

variable "name_prefix" {
  description = "Every preproduction resource is named from this. The preprod deployer policy is scoped to firmsbase-preprod-*, so changing it locks the deployer out of its own resources."
  type        = string
  default     = "firmsbase-preprod"
}

variable "vpc_cidr" {
  description = "Deliberately distinct from staging (default VPC, 172.31.0.0/16) and production (10.20.0.0/16), so no future peering or transit attachment can collide."
  type        = string
  default     = "10.30.0.0/16"
}

variable "availability_zones" {
  description = "Two AZs — the ALB requires at least two subnets in distinct AZs even though preproduction runs Single-AZ RDS."
  type        = list(string)
  default     = ["us-east-1a", "us-east-1b"]
}

variable "public_subnet_cidrs" {
  type    = list(string)
  default = ["10.30.0.0/24", "10.30.1.0/24"]
}

variable "private_app_subnet_cidrs" {
  type    = list(string)
  default = ["10.30.10.0/24", "10.30.11.0/24"]
}

variable "private_data_subnet_cidrs" {
  type    = list(string)
  default = ["10.30.20.0/24", "10.30.21.0/24"]
}

variable "container_port" {
  type    = number
  default = 8080
}

variable "apex_hostname" {
  type    = string
  default = "preprod.firmsvault.com"
}

variable "route53_zone_id" {
  type    = string
  default = "Z0436258R9EG7EDNOHIZ"
}

variable "image_digest" {
  description = "Immutable digest of the already-certified release. Never a tag, never 'latest'. No default: a certification run must state exactly which artifact it is certifying."
  type        = string

  validation {
    condition     = can(regex("^sha256:[0-9a-f]{64}$", var.image_digest))
    error_message = "image_digest must be an immutable digest of the form sha256:<64 lowercase hex>."
  }
}

variable "source_image_repository_url" {
  description = "Preproduction pulls the certified artifact from the repository where CI published it. It does NOT get its own ECR repository and the image is NEVER rebuilt or retagged for this environment — the whole point is that the digest running here is bit-for-bit the one that will later be promoted to production."
  type        = string
  default     = "603013471426.dkr.ecr.us-east-1.amazonaws.com/firmsbase-staging"
}

variable "rds_instance_class" {
  type    = string
  default = "db.t4g.micro"
}

variable "rds_engine_version" {
  description = "Pinned to production's exact minor version for engine parity. Live production runs 16.13; certifying against a different minor would leave a real difference unexercised."
  type        = string
  default     = "16.13"
}

variable "redis_node_type" {
  type    = string
  default = "cache.t4g.micro"
}

variable "web_desired_count" {
  description = "Two, matching production, so the rolling-replacement behaviour certified here is the behaviour production will use."
  type        = number
  default     = 2
}
