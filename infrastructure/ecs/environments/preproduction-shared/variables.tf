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
  type    = string
  default = "firmsbase-preprod"
}

variable "apex_hostname" {
  description = "Certificate subject. The wildcard SAN covers every per-role preproduction hostname, so adding a role later needs no certificate change."
  type        = string
  default     = "preprod.firmsvault.com"
}

variable "route53_zone_id" {
  description = "firmsvault.com public hosted zone."
  type        = string
  default     = "Z0436258R9EG7EDNOHIZ"
}
