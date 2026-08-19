# Production secrets. Generated once, here, and never printed.
#
# random_password values live in Terraform state, so the state backend must be
# encrypted and access-controlled — see backend.tf. That is a real property of
# this design, not an oversight: the alternative (creating secrets by hand)
# trades state exposure for a manual step that is easy to get wrong and
# impossible to reproduce.

resource "random_password" "app_key" {
  length  = 32
  special = true
}

resource "random_password" "db_app" {
  length  = 32
  special = false # RDS master/app passwords reject several punctuation classes
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
  name       = "firmsbase/production/app-key"
  kms_key_id = module.kms.key_arn
}

resource "aws_secretsmanager_secret_version" "app_key" {
  secret_id     = aws_secretsmanager_secret.app_key.id
  secret_string = "base64:${base64encode(random_password.app_key.result)}"
}

resource "aws_secretsmanager_secret" "db_app" {
  name       = "firmsbase/production/db-app"
  kms_key_id = module.kms.key_arn
}

resource "aws_secretsmanager_secret_version" "db_app" {
  secret_id = aws_secretsmanager_secret.db_app.id
  secret_string = jsonencode({
    host     = aws_db_instance.main.address
    port     = tostring(aws_db_instance.main.port)
    dbname   = aws_db_instance.main.db_name
    username = "firmsbase_app"
    password = random_password.db_app.result
  })
}

# Name matches the migrator boundary's firmsbase/production/db-migrator-* scope.
resource "aws_secretsmanager_secret" "db_migrator" {
  name       = "firmsbase/production/db-migrator-credential"
  kms_key_id = module.kms.key_arn
}

resource "aws_secretsmanager_secret_version" "db_migrator" {
  secret_id = aws_secretsmanager_secret.db_migrator.id
  secret_string = jsonencode({
    host     = aws_db_instance.main.address
    port     = tostring(aws_db_instance.main.port)
    dbname   = aws_db_instance.main.db_name
    username = "firmsbase_migrator"
    password = random_password.db_migrator.result
  })
}

resource "aws_secretsmanager_secret" "redis_auth" {
  name       = "firmsbase/production/redis-auth"
  kms_key_id = module.kms.key_arn
}

resource "aws_secretsmanager_secret_version" "redis_auth" {
  secret_id     = aws_secretsmanager_secret.redis_auth.id
  secret_string = random_password.redis_auth.result
}

resource "aws_secretsmanager_secret" "notification_hmac" {
  name       = "firmsbase/production/notification-hmac-key"
  kms_key_id = module.kms.key_arn
}

resource "aws_secretsmanager_secret_version" "notification_hmac" {
  secret_id     = aws_secretsmanager_secret.notification_hmac.id
  secret_string = random_password.notification_hmac.result
}
