# Preproduction secrets. Generated here, never printed, never shared with any
# other environment.
#
# Nothing is reused from staging or production. A shared APP_KEY would put two
# environments in one encryption domain, and shared database or Redis
# credentials would point at the wrong instance by definition.
#
# recovery_window_in_days = 0 is required, not a preference: Secrets Manager's
# default 7-30 day recovery window keeps a deleted name reserved, so the second
# destroy/recreate cycle would fail on a name collision. Preproduction is
# explicitly destroy/recreate capable, so the names must be immediately
# reusable.
#
# random_password values live in Terraform state, which is why the backend is
# encrypted and access-scoped. That is a real property of this design: the
# alternative — creating secrets by hand — trades state exposure for a manual
# step that cannot be reproduced.

resource "random_password" "app_key" {
  length  = 32
  special = true
}

resource "random_password" "db_master" {
  length  = 32
  special = false # RDS master passwords reject several punctuation classes
}

resource "random_password" "db_app" {
  length  = 32
  special = false
}

resource "random_password" "db_migrator" {
  length  = 32
  special = false
}

resource "random_password" "redis_auth" {
  length  = 64
  special = false # ElastiCache AUTH tokens permit a restricted character set
}

resource "random_password" "notification_hmac" {
  length  = 64
  special = false
}

resource "aws_secretsmanager_secret" "app_key" {
  name                    = "firmsbase/preprod/app-key"
  kms_key_id              = module.kms.key_arn
  recovery_window_in_days = 0
}

resource "aws_secretsmanager_secret_version" "app_key" {
  secret_id     = aws_secretsmanager_secret.app_key.id
  secret_string = "base64:${base64encode(random_password.app_key.result)}"
}

# Runtime identity. DML only — see bootstrap/01-create-roles-and-database.sql.
resource "aws_secretsmanager_secret" "database_app" {
  name                    = "firmsbase/preprod/database-app"
  kms_key_id              = module.kms.key_arn
  recovery_window_in_days = 0
}

resource "aws_secretsmanager_secret_version" "database_app" {
  secret_id = aws_secretsmanager_secret.database_app.id
  secret_string = jsonencode({
    host     = aws_db_instance.main.address
    port     = tostring(aws_db_instance.main.port)
    dbname   = aws_db_instance.main.db_name
    username = "firmsbase_app"
    password = random_password.db_app.result
  })
}

# Schema-migration identity. Referenced ONLY by the one-off migrate task —
# never by web, worker, critical-worker or scheduler.
resource "aws_secretsmanager_secret" "database_migrator" {
  name                    = "firmsbase/preprod/database-migrator"
  kms_key_id              = module.kms.key_arn
  recovery_window_in_days = 0
}

resource "aws_secretsmanager_secret_version" "database_migrator" {
  secret_id = aws_secretsmanager_secret.database_migrator.id
  secret_string = jsonencode({
    host     = aws_db_instance.main.address
    port     = tostring(aws_db_instance.main.port)
    dbname   = aws_db_instance.main.db_name
    username = "firmsbase_migrator"
    password = random_password.db_migrator.result
  })
}

# Consumed by the bootstrap step only; no ECS task references it.
resource "aws_secretsmanager_secret" "database_master" {
  name                    = "firmsbase/preprod/database-master"
  kms_key_id              = module.kms.key_arn
  recovery_window_in_days = 0
}

resource "aws_secretsmanager_secret_version" "database_master" {
  secret_id = aws_secretsmanager_secret.database_master.id
  secret_string = jsonencode({
    host     = aws_db_instance.main.address
    port     = tostring(aws_db_instance.main.port)
    dbname   = aws_db_instance.main.db_name
    username = aws_db_instance.main.username
    password = random_password.db_master.result
  })
}

resource "aws_secretsmanager_secret" "redis_auth_token" {
  name                    = "firmsbase/preprod/redis-auth-token"
  kms_key_id              = module.kms.key_arn
  recovery_window_in_days = 0
}

resource "aws_secretsmanager_secret_version" "redis_auth_token" {
  secret_id     = aws_secretsmanager_secret.redis_auth_token.id
  secret_string = random_password.redis_auth.result
}

resource "aws_secretsmanager_secret" "platform_notifications_hmac_key" {
  name                    = "firmsbase/preprod/platform-notifications-hmac-key"
  kms_key_id              = module.kms.key_arn
  recovery_window_in_days = 0
}

resource "aws_secretsmanager_secret_version" "platform_notifications_hmac_key" {
  secret_id     = aws_secretsmanager_secret.platform_notifications_hmac_key.id
  secret_string = random_password.notification_hmac.result
}
