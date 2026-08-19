variable "repository_name" {
  description = "ECR repository name for the single FirmsBase application image (see docs/ecs/container-architecture.md — one image, many roles)."
  type        = string
  default     = "firmsbase-app"
}

variable "image_tag_mutability" {
  description = "IMMUTABLE — enforced, not just defaulted. See docs/ecs/container-architecture.md 'Image tagging and immutable digest promotion': a tag must never be silently overwritten."
  type        = string
  default     = "IMMUTABLE"

  validation {
    condition     = var.image_tag_mutability == "IMMUTABLE"
    error_message = "image_tag_mutability must stay IMMUTABLE — deployable identity is the image digest, and immutable tags are the safety net against a tag being accidentally reused for different bytes."
  }
}

variable "untagged_image_expiry_days" {
  description = "Lifecycle policy: expire untagged images (superseded build layers/failed pushes) after this many days."
  type        = number
  default     = 7
}

variable "max_tagged_images_to_keep" {
  description = "Lifecycle policy: keep at most this many tagged images (each tag is a git SHA — see container-architecture.md), expiring the oldest beyond this count."
  type        = number
  default     = 100
}

variable "tags" {
  type    = map(string)
  default = {}
}

variable "encryption_type" {
  description = "ECR repository encryption type — \"AES256\" or \"KMS\". Null (default) preserves this module's original KMS default, fine for a brand-new environment. ForceNew on aws_ecr_repository (the ECR API has no in-place encryption-type change) — an already-imported live repository whose encryption type differs from this default MUST have this set to the exact live value, or the very next apply plans a disruptive replacement of the entire image repository. A future migration from AES256 to KMS for an already-created repository requires a dedicated ECR migration plan (new repository, re-push/copy images, cut over consumers), never a routine config change here."
  type        = string
  default     = null

  validation {
    condition     = var.encryption_type == null || contains(["AES256", "KMS"], var.encryption_type)
    error_message = "encryption_type must be \"AES256\" or \"KMS\"."
  }
}
