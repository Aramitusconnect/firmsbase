# SES bounce/complaint event pipeline — SES Configuration Set -> SNS topic
# -> SQS queue -> ses-consumer (docker/commands/ses-consumer.sh ->
# ConsumeSesEventsCommand -> SesEventConsumerService). SES cannot publish
# directly to SQS; SNS is the only fan-out AWS provides between the two,
# and ses-consumer's own SesEventConsumerService::process() already
# recognizes and safely deletes an SNS SubscriptionConfirmation message,
# confirming this SNS-in-the-middle shape (not a direct SQS event
# destination, which SES does not support) is what the application side
# was always built to expect.
#
# Fully Terraform-managed and staging-isolated: nothing here is shared
# with production's own (separately provisioned) equivalent resources.

resource "aws_sqs_queue" "ses_events_dlq" {
  name                      = "${var.name_prefix}-ses-events-dlq"
  message_retention_seconds = var.dlq_message_retention_seconds
  kms_master_key_id         = var.kms_key_arn

  tags = var.tags
}

resource "aws_sqs_queue" "ses_events" {
  name                       = "${var.name_prefix}-ses-events"
  visibility_timeout_seconds = var.visibility_timeout_seconds
  message_retention_seconds  = var.message_retention_seconds
  kms_master_key_id          = var.kms_key_arn

  redrive_policy = jsonencode({
    deadLetterTargetArn = aws_sqs_queue.ses_events_dlq.arn
    maxReceiveCount     = var.dlq_max_receive_count
  })

  tags = var.tags
}

# The DLQ's own redrive-source allow-list — without this, SQS still lets
# ses_events' redrive_policy above name the DLQ as a target (that half is
# controlled by the SOURCE queue's own policy), but being explicit here
# means the DLQ's own console/API view of "which queues may redrive into
# me" reflects reality, and a future second queue could never silently
# start redriving into this DLQ without its own explicit grant.
resource "aws_sqs_queue_redrive_allow_policy" "ses_events_dlq" {
  queue_url = aws_sqs_queue.ses_events_dlq.id

  redrive_allow_policy = jsonencode({
    redrivePermission = "byQueue"
    sourceQueueArns   = [aws_sqs_queue.ses_events.arn]
  })
}

resource "aws_sns_topic" "ses_events" {
  name              = "${var.name_prefix}-ses-events"
  kms_master_key_id = var.kms_key_arn

  tags = var.tags
}

# SES may only publish into this topic on behalf of ITS OWN configuration
# set (aws:SourceArn) from THIS account (aws:SourceAccount) — the same
# "never a bare service-principal grant" discipline the SQS queue policy
# below already applies for SNS's own publish into the queue. Without
# this, SES accepts the event destination configuration but every publish
# attempt fails silently server-side (SES has no user-facing error path
# for "the topic refused the publish") — see config/mail.php's own
# docblock on why the configuration set matters at all.
resource "aws_sns_topic_policy" "ses_events_allow_ses_publish" {
  arn = aws_sns_topic.ses_events.arn

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "AllowSesConfigurationSetPublish"
        Effect    = "Allow"
        Principal = { Service = "ses.amazonaws.com" }
        Action    = "sns:Publish"
        Resource  = aws_sns_topic.ses_events.arn
        Condition = {
          StringEquals = {
            "AWS:SourceAccount" = var.aws_account_id
          }
          ArnEquals = {
            "AWS:SourceArn" = "arn:aws:ses:${var.aws_region}:${var.aws_account_id}:configuration-set/${aws_sesv2_configuration_set.staging.configuration_set_name}"
          }
        }
      }
    ]
  })
}

# SNS may only publish into this queue on behalf of THIS topic — not "any
# SNS topic in the account," which is the mistake a bare
# Principal=sns.amazonaws.com statement without the SourceArn condition
# would make.
resource "aws_sqs_queue_policy" "ses_events_allow_sns_publish" {
  queue_url = aws_sqs_queue.ses_events.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "AllowSesEventsSnsTopicPublish"
        Effect    = "Allow"
        Principal = { Service = "sns.amazonaws.com" }
        Action    = "sqs:SendMessage"
        Resource  = aws_sqs_queue.ses_events.arn
        Condition = {
          ArnEquals = { "aws:SourceArn" = aws_sns_topic.ses_events.arn }
        }
      }
    ]
  })
}

resource "aws_sns_topic_subscription" "ses_events_to_sqs" {
  topic_arn = aws_sns_topic.ses_events.arn
  protocol  = "sqs"
  endpoint  = aws_sqs_queue.ses_events.arn

  # Must be true: SesEventConsumerService::process()'s own docblock states
  # its primary/expected message shape is raw SES event JSON, not SNS's
  # Notification envelope — the envelope-unwrap branch it also carries is
  # explicitly documented there as a defensive fallback ("if raw message
  # delivery were ever disabled upstream"), not the intended path. Leaving
  # this at its default (false) would still work, via that fallback, but
  # would not match what the consumer was actually built to expect.
  raw_message_delivery = true
}

# SESv2, not the classic v1 aws_ses_configuration_set/aws_ses_event_destination
# pair: v1's EventType enum has no DeliveryDelay member at all, which
# cannot represent SesEventType::DeliveryDelay (app/Enums/SesEventType.php)
# — the app's own event model already assumes the v2 shape.
#
# Deliberately NOT named "my-first-configuration-set" (config/mail.php's
# own env('SES_CONFIGURATION_SET', 'my-first-configuration-set') default,
# an AWS-console-tutorial-style placeholder name, not a real naming
# convention) — this environment's own name_prefix-scoped name is set
# explicitly via the SES_CONFIGURATION_SET environment variable on the web
# service (see environments/staging/main.tf's local.shared_environment),
# so the placeholder default is never actually relied on.
resource "aws_sesv2_configuration_set" "staging" {
  configuration_set_name = "${var.name_prefix}-ses-events"
}

resource "aws_sesv2_configuration_set_event_destination" "sns" {
  configuration_set_name = aws_sesv2_configuration_set.staging.configuration_set_name
  event_destination_name = "${var.name_prefix}-ses-events-sns"

  event_destination {
    enabled              = true
    matching_event_types = var.ses_event_matching_types

    sns_destination {
      topic_arn = aws_sns_topic.ses_events.arn
    }
  }
}
