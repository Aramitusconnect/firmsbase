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
  }
}
