output "vpc_id" {
  value = data.aws_vpc.this.id
}

output "public_subnet_ids" {
  value = var.public_subnet_ids
}

output "private_subnet_ids" {
  value = var.private_subnet_ids
}
