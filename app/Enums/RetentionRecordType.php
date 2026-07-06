<?php

namespace App\Enums;

/**
 * RetentionRecordType — the closed set of record families a
 * retention_policies row can scope to, matching the Master Plan's Phase
 * 17 scope verbatim: "firm-level, client-level, matter-level,
 * document-category, lead, trust-ledger, audit-log, and AI-log
 * retention policies."
 */
enum RetentionRecordType: string
{
    case Firm = 'firm';
    case Client = 'client';
    case Matter = 'matter';
    case DocumentCategory = 'document_category';
    case Lead = 'lead';
    case TrustLedger = 'trust_ledger';
    case AuditLog = 'audit_log';
    case AiLog = 'ai_log';
}
