output "primary_endpoint_address" {
  value = aws_elasticache_replication_group.this.primary_endpoint_address
}

output "port" {
  value = aws_elasticache_replication_group.this.port
}

output "security_group_id" {
  value = aws_security_group.redis.id
}

output "subnet_group_name" {
  value = aws_elasticache_subnet_group.this.name
}

output "engine" {
  value = aws_elasticache_replication_group.this.engine
}
