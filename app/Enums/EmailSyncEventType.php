<?php

namespace App\Enums;

/**
 * EmailSyncEventType — email_sync_events.event_type. This single table
 * replaces both a would-be email_sync_state (cursor) table and a
 * would-be email_audit_events (audit trail) table (approved
 * correction) — "current cursor" for an account is derived by querying
 * the latest SyncRun row with resulting_cursor set, rather than
 * maintaining a separate mutable state row. There is no Send* event
 * type — Phase 9 has no send flow.
 */
enum EmailSyncEventType: string
{
    case AccountConnected = 'account_connected';
    case AccountDisconnected = 'account_disconnected';
    case TokenRotated = 'token_rotated';
    case TokenRevoked = 'token_revoked';
    case SyncRun = 'sync_run';
    case MessageCaptured = 'message_captured';
    case MessageLinked = 'message_linked';
    case AttachmentPromoted = 'attachment_promoted';
    case AttachmentBlocked = 'attachment_blocked';
}
