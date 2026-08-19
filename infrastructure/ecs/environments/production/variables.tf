variable "aws_account_id" {
  description = "Production account. Pinned so a mis-set profile fails before it creates anything."
  type        = string
  default     = "603013471426"
}

variable "aws_region" {
  type    = string
  default = "us-east-1"
}

variable "name_prefix" {
  description = "Every production resource is named from this. The IAM deployer policy is scoped to firmsbase-production-*, so changing it locks the deployer out of its own resources."
  type        = string
  default     = "firmsbase-production"
}

variable "vpc_cidr" {
  type    = string
  default = "10.20.0.0/16"
}

variable "availability_zones" {
  description = "Two AZs minimum — RDS Multi-AZ and the ALB both require it."
  type        = list(string)
  default     = ["us-east-1a", "us-east-1b"]
}

variable "public_subnet_cidrs" {
  type    = list(string)
  default = ["10.20.0.0/24", "10.20.1.0/24"]
}

variable "private_app_subnet_cidrs" {
  type    = list(string)
  default = ["10.20.10.0/24", "10.20.11.0/24"]
}

variable "private_data_subnet_cidrs" {
  type    = list(string)
  default = ["10.20.20.0/24", "10.20.21.0/24"]
}

variable "container_port" {
  type    = number
  default = 8080
}

variable "application_hostnames" {
  description = "ACM SANs. api.firmsvault.com is deliberately absent — no API is exposed in this release."
  type        = list(string)
  default = [
    "app.firmsvault.com",
    "client.firmsvault.com",
    "admin.firmsvault.com",
    "myattorney.firmsvault.com",
  ]
}

variable "route53_zone_id" {
  type    = string
  default = "Z0436258R9EG7EDNOHIZ"
}

variable "image_digest" {
  description = "Immutable digest of the release candidate. Never a tag."
  type        = string
}

variable "rds_instance_class" {
  type    = string
  default = "db.t4g.medium"
}

variable "redis_node_type" {
  type    = string
  default = "cache.t4g.small"
}

variable "web_desired_count" {
  type    = number
  default = 2
}
