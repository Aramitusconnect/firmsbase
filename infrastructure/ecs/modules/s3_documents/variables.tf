variable "bucket_name" {
  type = string
}

variable "kms_key_arn" {
  description = "Customer-managed KMS key for SSE-KMS default encryption. See docs/ecs/storage-readiness.md and docs/ecs/iam-matrix.md."
  type        = string
}

variable "tags" {
  type    = map(string)
  default = {}
}
