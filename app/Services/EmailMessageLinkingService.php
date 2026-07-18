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
 *
 * Tenant-context wiring (email_message_links FORCE ROW LEVEL SECURITY
 * activation, see database/migrations/2026_08_27_950004_prepare_row_level_security_and_force_rls_on_email_message_links_table.php):
 * link()'s pre-existing PHP-only firm-match validation (matter/client
 * presence and the three firm_id mismatch checks) runs BEFORE any
 * tenant context is established — those throws are unrelated to RLS
 * and must not depend on it. Only the row creation and the audit-event
 * write are wrapped in a single outer runWithFirmContext() call, keyed
 * on the email message's own firm_id, which this service has already
 * confirmed the matter/client/actor all agree with. unlink() similarly
 * checks the actor's firm_id against the link's firm_id before
 * entering context, so a mismatched actor throws without any database
 * statement running, then wraps only the delete itself. Neither method
 * is ever called from inside another runWithFirmContext() call
 * elsewhere in the codebase today, but wrapping the whole call (rather
 * than only its arguments) at each call site is still the correct,
 * nesting-safe convention per runWithFirmContext()'s own docblock.
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

        return (new TenantContextService())->runWithFirmContext($message->firm_id, function () use ($message, $matter, $client, $actor, $isPrimary) {
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
        });
    }

    /**
     * The actor firm_id check runs BEFORE runWithFirmContext() is ever
     * entered, so a mismatched actor throws without any database
     * statement (including the delete itself) running — see
     * EmailMessageLinksForceRlsActivationTest's regression coverage for
     * this exact ordering.
     */
    public function unlink(EmailMessageLink $link, FirmUser $actor): void
    {
        if ($actor->firm_id !== $link->firm_id) {
            throw new \RuntimeException('Actor does not belong to the same firm as the email message link.');
        }

        (new TenantContextService())->runWithFirmContext($link->firm_id, fn () => $link->delete());
    }
}
