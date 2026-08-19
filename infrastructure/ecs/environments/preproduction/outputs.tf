output "cluster_name" {
  value = module.ecs_cluster.cluster_name
}

output "alb_dns_name" {
  description = "Raw ALB hostname. Requests using it as the Host header are expected to be rejected with 400 by strict TrustHosts — that is the intended security behaviour, not a failure."
  value       = module.alb.alb_dns_name
}

output "target_group_arn" {
  value = module.alb.target_group_arn
}

output "rds_endpoint" {
  value = aws_db_instance.main.address
}

output "redis_primary_endpoint" {
  value = module.elasticache.primary_endpoint_address
}

output "documents_bucket" {
  value = module.s3_documents.bucket_name
}

output "certified_image" {
  description = "The exact digest-pinned reference every role runs. Certification evidence must show this equals the running imageDigest on all four services."
  value       = local.image
}

output "canonical_hostnames" {
  value = local.preprod_hostnames
}

output "migrate_task_definition_arn" {
  description = "Run once per cycle with ecs run-task. Never a service."
  value       = module.migrate.task_definition_arn
}

output "maintenance_task_definition_arn" {
  value = module.maintenance.task_definition_arn
}

output "preprod_multi_az" {
  description = "Recorded in certification evidence: preproduction does NOT certify RDS Multi-AZ failover behaviour."
  value       = aws_db_instance.main.multi_az
}
