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
  description = "IAM task role ARN assumed by the SES consumer container — scoped to sqs:ReceiveMessage/sqs:DeleteMessage on exactly the primary queue ARN (see infrastructure/ecs/modules/iam/main.tf and docs/ecs/iam-matrix.md). Not a secret — an IAM role ARN is an identifier, not a credential."
  value       = module.iam.task_role_arns["ses_consumer"]
}

output "ses_consumer_log_group_name" {
  value = aws_cloudwatch_log_group.app["ses-consumer"].name
}
