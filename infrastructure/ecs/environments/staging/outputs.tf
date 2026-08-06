output "alb_dns_name" {
  description = "CNAME target for the staging hostname — see docs/ecs/staging-readiness-report.md 'required DNS/certificate inputs'. This module does not create the DNS record itself."
  value       = module.alb.alb_dns_name
}

output "ecr_repository_url" {
  value = module.ecr.repository_url
}

output "ecs_cluster_name" {
  value = module.ecs_cluster.cluster_name
}

output "s3_documents_bucket_name" {
  value = module.s3_documents.bucket_name
}

output "redis_endpoint" {
  value = module.elasticache.primary_endpoint_address
}

output "task_definition_arns" {
  value = {
    web             = module.web.task_definition_arn
    worker          = module.worker.task_definition_arn
    critical_worker = module.critical_worker.task_definition_arn
    scheduler       = module.scheduler.task_definition_arn
    migrate         = module.migrate.task_definition_arn
    maintenance     = module.maintenance.task_definition_arn
    ses_consumer    = module.ses_consumer.task_definition_arn
  }
}

output "ses_consumer_service_name" {
  description = "ECS service name for the SES bounce/complaint consumer — never null in this environment since create_service = true for this role."
  value       = module.ses_consumer.service_name
}

output "ses_consumer_task_role_arn" {
  description = "IAM task role ARN assumed by the SES consumer container — scoped to sqs:ReceiveMessage/sqs:DeleteMessage on exactly the primary queue ARN (see infrastructure/ecs/modules/iam/main.tf and docs/ecs/iam-matrix.md). Not a secret — an IAM role ARN is an identifier, not a credential. The try() below is deliberate and narrow, not a general-purpose safety net: module.iam.task_role_arns is a for_each-derived map, so under a -target plan whose closure excludes the ses_consumer task role, the map genuinely has no \"ses_consumer\" key and a bare index fails with a hard \"Invalid index\" error — a targeted-plan artifact, not a missing production dependency. A normal, untargeted apply always has this key (ses_consumer is an unconditional member of local.task_role_names in the iam module), so try() never masks a real gap there; the value is never a fake placeholder ARN, only a genuine null under a partial/targeted plan. See docs/ecs/state-adoption-plan.md §9.24 and the focused output-safety test."
  value       = try(module.iam.task_role_arns["ses_consumer"], null)
}

output "ses_consumer_log_group_name" {
  description = "CloudWatch log group name for the SES bounce/complaint consumer. The try() below mirrors ses_consumer_task_role_arn's own rationale above: aws_cloudwatch_log_group.app is for_each-keyed by local.roles, and under a -target plan whose closure excludes the ses-consumer log group, a bare index fails rather than degrading gracefully. A normal, untargeted apply always has this key. See docs/ecs/state-adoption-plan.md §9.24."
  value       = try(aws_cloudwatch_log_group.app["ses-consumer"].name, null)
}
