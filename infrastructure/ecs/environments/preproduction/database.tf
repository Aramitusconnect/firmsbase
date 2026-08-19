# Preproduction PostgreSQL.
#
# Dedicated instance. It must NOT reuse the staging database (which carries a
# later 537-migration lineage) and must NOT touch production. The certified
# release's own migration set is the only thing that will ever be applied here.
#
# Engine version is pinned to production's exact minor rather than the "16"
# major-version track production's own configuration uses, because the point of
# this environment is parity with what production actually runs today.
#
# Every destroy-blocking default is deliberately inverted: this environment is
# built and torn down once per certification cycle, and an instance that cannot
# be destroyed is an instance that quietly becomes permanent.

resource "aws_db_subnet_group" "main" {
  name       = "${var.name_prefix}-db-subnet-group"
  subnet_ids = aws_subnet.private_data[*].id
}

resource "aws_security_group" "rds" {
  name        = "${var.name_prefix}-rds-sg"
  description = "Postgres from preproduction ECS tasks only"
  vpc_id      = aws_vpc.main.id

  tags = { Name = "${var.name_prefix}-rds-sg" }
}

# Security-group reference, never a CIDR: the only thing that may reach 5432 is
# a task running in this environment's own task security group. No staging or
# production security group is referenced anywhere in this configuration.
resource "aws_security_group_rule" "rds_from_tasks" {
  type                     = "ingress"
  from_port                = 5432
  to_port                  = 5432
  protocol                 = "tcp"
  security_group_id        = aws_security_group.rds.id
  source_security_group_id = module.security_groups.ecs_tasks_security_group_id
  description              = "Postgres from preproduction ECS tasks"
}

# force_ssl is set explicitly rather than inherited: the default parameter
# group for postgres16 leaves rds.force_ssl at 1, but "the default happens to
# be what we want" is not a control. Stating it here means a future AWS default
# change cannot silently downgrade preproduction to optional TLS.
resource "aws_db_parameter_group" "main" {
  name   = "${var.name_prefix}-pg16"
  family = "postgres16"

  parameter {
    name  = "rds.force_ssl"
    value = "1"
  }

  lifecycle {
    create_before_destroy = true
  }
}

resource "aws_db_instance" "main" {
  identifier     = "${var.name_prefix}-db"
  engine         = "postgres"
  engine_version = var.rds_engine_version
  instance_class = var.rds_instance_class

  # The master identity exists only to create the two application roles and the
  # application database (see bootstrap/01-create-roles-and-database.sql).
  # No ECS task ever authenticates as it.
  db_name  = "firmsbase_preprod"
  username = "firmsbase_root"
  password = random_password.db_master.result

  allocated_storage     = 20
  max_allocated_storage = 50
  storage_type          = "gp3"
  storage_encrypted     = true
  kms_key_id            = module.kms.key_arn

  # Single-AZ by design. Certification evidence must state PREPROD_MULTI_AZ=false
  # explicitly: this environment does not certify RDS failover behaviour, and
  # production's Multi-AZ posture is therefore NOT exercised here.
  multi_az               = false
  db_subnet_group_name   = aws_db_subnet_group.main.name
  vpc_security_group_ids = [aws_security_group.rds.id]
  publicly_accessible    = false
  parameter_group_name   = aws_db_parameter_group.main.name

  # Ephemeral lifecycle: no retained backups, no final snapshot, no deletion
  # protection. A certification database holds no data worth preserving — it is
  # rebuilt from the certified migration set every cycle.
  backup_retention_period  = 0
  delete_automated_backups = true
  deletion_protection      = false
  skip_final_snapshot      = true
  copy_tags_to_snapshot    = true

  performance_insights_enabled    = false
  enabled_cloudwatch_logs_exports = ["postgresql"]
  auto_minor_version_upgrade      = false

  lifecycle {
    ignore_changes = [password]
  }
}
