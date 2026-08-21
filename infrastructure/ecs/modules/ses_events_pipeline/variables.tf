variable "name_prefix" {
  type = string
}

variable "kms_key_arn" {
  description = "Customer-managed KMS key for SSE-KMS encryption of the events queue, its DLQ, and the SNS topic — the same key module.kms already provides for Secrets Manager/S3/CloudWatch Logs. The caller must also grant this key's policy the matching sqs_queue_arn_pattern/sns_topic_arn_pattern statements (see modules/kms) or queue/topic creation succeeds but every Encrypt/Decrypt call against it fails at send/receive time."
  type        = string
}

variable "visibility_timeout_seconds" {
  description = "How long a received-but-undeleted message stays invisible to other receivers. Must match SES_EVENTS_VISIBILITY_TIMEOUT_SECONDS passed to the ses-consumer task (see environments/staging/main.tf's ses_events_environment) — the two are the same real value seen from two different sides (Terraform provisioning the queue vs. the consumer polling it), not independently configured."
  type        = number
  default     = 60
}

variable "message_retention_seconds" {
  description = "How long an unconsumed message survives in the main queue before SQS silently drops it. SQS's own default (4 days) is intentionally kept here rather than raised — a bounce/complaint event this old is already well past any useful automated-suppression window, and a real processing failure should surface via the DLQ-backlog alarm long before 4 days, not be masked by an extended retention window."
  type        = number
  default     = 345600 # 4 days, SQS's own default — set explicitly rather than omitted, so this queue's retention is a reviewed decision, not an unreviewed default.
}

variable "dlq_message_retention_seconds" {
  description = "How long a dead-lettered message survives in the DLQ. Set to SQS's 14-day maximum — unlike the main queue, a DLQ message represents a genuine unresolved processing failure an operator needs time to investigate, not a normal in-flight item."
  type        = number
  default     = 1209600 # 14 days, SQS's own maximum.
}

variable "dlq_max_receive_count" {
  description = "How many times SQS redelivers a message from the main queue before routing it to the DLQ instead. ConsumeSesEventsCommand's own processing (via SesEventConsumerService::process()) is idempotent per SES eventId (see SesEventReceipt's own unique constraint), so a handful of genuine retries is safe and expected under transient failure (a brief DB blip, a deploy mid-flight) without needing a low threshold that DLQs a message on its first hiccup."
  type        = number
  default     = 5
}

variable "ses_event_matching_types" {
  description = "Which SESv2 event types this configuration set publishes to the SNS topic — SESv2's own enum values (SDK/Terraform provider constant names), not the app's own SesEventType enum's string values (compare app/Enums/SesEventType.php's ->value strings, e.g. 'Rendering Failure', which are the SES event JSON's own eventType field content, a distinct string space from this list). BOUNCE/COMPLAINT are the two this codebase's consumer actually models and acts on (see app/Enums/SesEventType.php, app/Enums/SesBounceType.php); REJECT/RENDERING_FAILURE/DELIVERY_DELAY are included per config/mail.php's own docblock (\"without an attached configuration set, SES never publishes Bounce/Complaint/Reject/RenderingFailure/DeliveryDelay events\") so an operator investigating a delivery problem has the full picture in one place, even though SesEventConsumerService may only act on a subset today — see that service's own message-type handling for which ones currently drive a state change vs. are logged and safely deleted as a recognized-but-inert event type."
  type        = list(string)
  default     = ["BOUNCE", "COMPLAINT", "REJECT", "RENDERING_FAILURE", "DELIVERY_DELAY"]
}

variable "tags" {
  type    = map(string)
  default = {}
}
