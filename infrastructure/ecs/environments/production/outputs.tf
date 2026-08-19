output "alb_dns_name" {
  description = "Target for the app/client/admin/myattorney aliases — created only after acceptance."
  value       = module.alb.alb_dns_name
}

output "alb_zone_id" {
  value = module.alb.alb_zone_id
}

output "ecr_repository_url" {
  value = module.ecr.repository_url
}

output "cluster_name" {
  value = module.ecs_cluster.cluster_name
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

output "acm_certificate_arn" {
  value = aws_acm_certificate_validation.app.certificate_arn
}
