# Single ECR repository for the one application image used by every ECS
# role (web/worker/scheduler/migrate/maintenance) — see
# docs/ecs/container-architecture.md. No per-role repositories.

resource "aws_ecr_repository" "app" {
  name                 = var.repository_name
  image_tag_mutability = var.image_tag_mutability

  image_scanning_configuration {
    scan_on_push = true
  }

  encryption_configuration {
    # encryption_type is ForceNew (the ECR API cannot change an existing
    # repository's encryption type in place) — see encryption_type in
    # variables.tf. Null (default) preserves this module's original KMS
    # default for a brand-new environment; an already-imported live
    # repository whose encryption type differs (confirmed via aws ecr
    # describe-repositories) MUST have this set to the exact live value,
    # or the very next apply plans a disruptive replacement of the entire
    # image repository (all pushed images lost). See
    # docs/ecs/state-adoption-plan.md — any future AES256-to-KMS migration
    # for an already-created repository requires a dedicated migration
    # plan, not a routine config change.
    encryption_type = coalesce(var.encryption_type, "KMS")
  }

  tags = var.tags

  lifecycle {
    # This staging environment's live repository carries a manually-set
    # tag (Application) that predates this environment's provider
    # default_tags block gaining its Mission/ManagedBy keys — tags_all is
    # computed fresh from tags + the CURRENT default_tags on every plan,
    # so a routine plan otherwise proposes adding those two keys (real,
    # additive-only drift, never a deletion). Scoped to this one resource
    # only — never a provider-wide ignore_tags. See
    # docs/ecs/state-adoption-plan.md.
    ignore_changes = [tags, tags_all]
  }
}

resource "aws_ecr_lifecycle_policy" "app" {
  repository = aws_ecr_repository.app.name

  policy = jsonencode({
    rules = [
      {
        rulePriority = 1
        description  = "Expire untagged images after ${var.untagged_image_expiry_days} days"
        selection = {
          tagStatus   = "untagged"
          countType   = "sinceImagePushed"
          countUnit   = "days"
          countNumber = var.untagged_image_expiry_days
        }
        action = { type = "expire" }
      },
      {
        rulePriority = 2
        description  = "Keep only the most recent ${var.max_tagged_images_to_keep} tagged images"
        selection = {
          tagStatus     = "tagged"
          tagPrefixList = ["sha-", "v", "staging-", "prod-"]
          countType     = "imageCountMoreThan"
          countNumber   = var.max_tagged_images_to_keep
        }
        action = { type = "expire" }
      }
    ]
  })
}
