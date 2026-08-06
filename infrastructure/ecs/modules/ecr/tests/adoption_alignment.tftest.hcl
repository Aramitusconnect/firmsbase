# Proves the ECR encryption_type adoption-alignment correction (see
# docs/ecs/state-adoption-plan.md §9.26 [ALB/ECR/cluster/service wave]):
# encryption_type defaults to this module's original KMS design for a
# brand-new environment, and resolves to the exact live AES256 value when
# overridden — without weakening the module globally or using
# ignore_changes on a ForceNew attribute.
#
# Run with: terraform test (from infrastructure/ecs/modules/ecr)

mock_provider "aws" {}

variables {
  repository_name = "firmsbase-staging"
}

run "encryption_type_defaults_to_kms_for_a_brand_new_environment" {
  command = plan

  assert {
    condition     = aws_ecr_repository.app.encryption_configuration[0].encryption_type == "KMS"
    error_message = "Without encryption_type set, this must default to KMS — this module's original design intent, unaffected for a brand-new environment."
  }
}

run "encryption_type_models_the_exact_live_value_when_overridden" {
  command = plan

  variables {
    encryption_type = "AES256"
  }

  assert {
    condition     = aws_ecr_repository.app.encryption_configuration[0].encryption_type == "AES256"
    error_message = "encryption_type must resolve to the exact live value — this is what makes an already-imported live repository (AES256) importable without proposing a disruptive replacement on the next apply."
  }
}

run "encryption_type_rejects_an_invalid_value" {
  command = plan

  variables {
    encryption_type = "not-a-real-encryption-type"
  }

  expect_failures = [
    var.encryption_type,
  ]
}

run "repository_identity_and_other_settings_unaffected_by_the_override" {
  command = plan

  variables {
    encryption_type = "AES256"
  }

  assert {
    condition     = aws_ecr_repository.app.name == "firmsbase-staging"
    error_message = "The repository's own identity (name) must be unaffected by the encryption_type override."
  }

  assert {
    condition     = aws_ecr_repository.app.image_tag_mutability == "IMMUTABLE"
    error_message = "image_tag_mutability must be unaffected by the encryption_type override."
  }

  assert {
    condition     = aws_ecr_repository.app.image_scanning_configuration[0].scan_on_push == true
    error_message = "scan_on_push must be unaffected by the encryption_type override."
  }
}
