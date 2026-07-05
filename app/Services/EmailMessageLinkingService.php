<?php

namespace App\Services;

use App\Enums\EmailSyncEventType;
use App\Enums\EmailSyncOutcome;
use App\Models\Client;
use App\Models\EmailMessage;
use App\Models\EmailMessageLink;
use App\Models\FirmUser;
use App\Models\Matter;

/**
 * EmailMessageLinkingService — the only place email_message_links rows
 * are created. At least one of matter/client must be supplied. Does
 * NOT itself decide visibility — that is EmailVisibilityPolicyService's
 * job, resolved independently of which entity a message happens to be
 * linked to.
 */
class EmailMessageLinkingService
{
    public function __construct(private readonly EmailSyncAuditService $auditService)
    {
    }

    public function link(
        EmailMessage $message,
        FirmUser $actor,
        ?Matter $matter = null,
        ?Client $client = null,
        bool $isPrimary = false,
    ): EmailMessageLink {
        if ($matter === null && $client === null) {
            throw new \InvalidArgumentException('At least one of matter or client must be provided to link an email message.');
        }

        if ($matter !== null && $matter->firm_id !== $message->firm_id) {
            throw new \RuntimeException('Matter does not belong to the same firm as the email message.');
        }

        if ($client !== null && $client->firm_id !== $message->firm_id) {
            throw new \RuntimeException('Client does not belong to the same firm as the email message.');
        }

        if ($actor->firm_id !== $message->firm_id) {
            throw new \RuntimeException('Actor does not belong to the same firm as the email message.');
        }

        $link = EmailMessageLink::create([
            'firm_id' => $message->firm_id,
            'email_message_id' => $message->id,
            'matter_id' => $matter?->id,
            'client_id' => $client?->id,
            'linked_by_firm_user_id' => $actor->id,
            'is_primary' => $isPrimary,
        ]);

        $this->auditService->record(
            $message->firm,
            $message->emailAccount,
            EmailSyncEventType::MessageLinked,
            EmailSyncOutcome::Success,
            detail: "email_message_id={$message->id} linked (matter_id=".($matter?->id ?? 'null').", client_id=".($client?->id ?? 'null').')',
        );

        return $link;
    }

    public function unlink(EmailMessageLink $link): void
    {
        $link->delete();
    }
}
