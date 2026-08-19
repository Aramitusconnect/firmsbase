# Production PostgreSQL.
#
# Authored here because there is no RDS module — staging's database was created
# outside Terraform and the environment only receives var.db_host. Production
# manages its own from the start.

resource "aws_db_subnet_group" "main" {
  name       = "${var.name_prefix}-db-subnet-group"
  subnet_ids = aws_subnet.private_data[*].id
}

resource "aws_security_group" "rds" {
  name        = "${var.name_prefix}-rds-sg"
  description = "Postgres from ECS tasks only"
  vpc_id      = aws_vpc.main.id

  tags = { Name = "${var.name_prefix}-rds-sg" }
}

resource "aws_security_group_rule" "rds_from_tasks" {
  type                     = "ingress"
  from_port                = 5432
  to_port                  = 5432
  protocol                 = "tcp"
  security_group_id        = aws_security_group.rds.id
  source_security_group_id = module.security_groups.ecs_tasks_security_group_id
  description              = "Postgres from ECS tasks"
}

resource "aws_db_instance" "main" {
  identifier     = "${var.name_prefix}-db"
  engine         = "postgres"
  engine_version = "16"
  instance_class = var.rds_instance_class

  db_name  = "firmsbase_production"
  username = "firmsbase_root"
  password = random_password.db_app.result

  allocated_storage     = 50
  max_allocated_storage = 200
  storage_type          = "gp3"
  storage_encrypted     = true
  kms_key_id            = module.kms.key_arn

  multi_az               = true
  db_subnet_group_name   = aws_db_subnet_group.main.name
  vpc_security_group_ids = [aws_security_group.rds.id]
  publicly_accessible    = false

  backup_retention_period   = 14
  backup_window             = "07:00-08:00"
  maintenance_window        = "Mon:08:30-Mon:09:30"
  copy_tags_to_snapshot     = true
  deletion_protection       = true
  skip_final_snapshot       = false
  final_snapshot_identifier = "${var.name_prefix}-db-final"

  performance_insights_enabled    = true
  enabled_cloudwatch_logs_exports = ["postgresql", "upgrade"]
  auto_minor_version_upgrade      = true

  # 14-day automated backups give PITR across that window.
  lifecycle {
    ignore_changes = [password]
  }
}
