<?php

namespace App\Services;

use App\Enums\EmailAccountConnectionStatus;
use App\Enums\EmailProvider;
use App\Enums\EmailStorageMode;
use App\Enums\EmailSyncEventType;
use App\Enums\EmailSyncOutcome;
use App\Models\EmailAccount;
use App\Models\Firm;
use App\Models\FirmUser;

/**
 * EmailAccountService — connect/disconnect/revoke/updateStorageMode.
 * connect() is FOUNDATION ONLY: it accepts an already-completed
 * (fixture/test) authorization result and stores it, exactly per
 * approved scope — no real OAuth handshake, no callback, no network
 * I/O. Mailbox connection is firm-user-only (approved decision):
 * connected_by_firm_user_id is the only actor column; there is no
 * platform-admin path anywhere in this service.
 *
 * Tenant-context wiring (email_accounts/email_sync_events FORCE ROW
 * LEVEL SECURITY activation, Section 39A-5 Wave 5): every
 * accessPolicy->canManageMailbox()/actor-firm-match check below is a
 * pure in-memory check and stays OUTSIDE any wrap, unchanged.
 * connect()'s row create and its immediately-following audit-event
 * write share ONE outer runWithFirmContext() call, keyed on $firm->id
 * — the exact "one row create + one audit create, one wrap" shape
 * EmailMessageLinkingService::link() already established.
 * disconnect()/revoke() similarly share one wrap (update + fresh() +
 * the audit call, which reads $account->firm from inside that same
 * wrap), keyed on $account->firm_id. markError()/updateStorageMode()
 * have no audit call, so each gets its own wrap around just its
 * update()+fresh() pair. None of these wraps ever nests inside
 * another — each method call site wraps its own whole body, per
 * runWithFirmContext()'s own nesting-safety convention.
 */
class EmailAccountService
{
    public function __construct(
        private readonly EmailAccessPolicyService $accessPolicy,
        private readonly EmailSyncAuditService $auditService,
    ) {
    }

    public function connect(
        Firm $firm,
        FirmUser $actor,
        EmailProvider $provider,
        string $mailboxAddress,
        EmailStorageMode $storageMode = EmailStorageMode::Disabled,
    ): EmailAccount {
        if (! $this->accessPolicy->canManageMailbox($actor)) {
            throw new \RuntimeException('Actor role is not permitted to connect a firm mailbox.');
        }

        if ($actor->firm_id !== $firm->id) {
            throw new \RuntimeException('Actor does not belong to the connecting firm.');
        }

        return (new TenantContextService())->runWithFirmContext($firm->id, function () use ($firm, $provider, $mailboxAddress, $storageMode, $actor) {
            $account = EmailAccount::create([
                'firm_id' => $firm->id,
                'provider' => $provider,
                'mailbox_address' => $mailboxAddress,
                'connection_status' => EmailAccountConnectionStatus::Connected,
                'storage_mode' => $storageMode,
                'connected_by_firm_user_id' => $actor->id,
            ]);

            $this->auditService->record(
                $firm,
                $account,
                EmailSyncEventType::AccountConnected,
                EmailSyncOutcome::Success,
                detail: "mailbox {$mailboxAddress} connected via {$provider->value} (foundation-only, no real OAuth handshake)",
            );

            return $account;
        });
    }

    public function disconnect(EmailAccount $account, FirmUser $actor): EmailAccount
    {
        if (! $this->accessPolicy->canManageMailbox($actor)) {
            throw new \RuntimeException('Actor role is not permitted to disconnect a firm mailbox.');
        }

        return (new TenantContextService())->runWithFirmContext($account->firm_id, function () use ($account) {
            $account->update(['connection_status' => EmailAccountConnectionStatus::Disconnected]);

            $this->auditService->record(
                $account->firm,
                $account,
                EmailSyncEventType::AccountDisconnected,
                EmailSyncOutcome::Success,
            );

            return $account->fresh();
        });
    }

    public function revoke(EmailAccount $account, FirmUser $actor, string $reason): EmailAccount
    {
        if (! $this->accessPolicy->canManageMailbox($actor)) {
            throw new \RuntimeException('Actor role is not permitted to revoke a firm mailbox.');
        }

        return (new TenantContextService())->runWithFirmContext($account->firm_id, function () use ($account, $reason) {
            $account->update([
                'connection_status' => EmailAccountConnectionStatus::Revoked,
                'error_reason' => $reason,
            ]);

            $this->auditService->record(
                $account->firm,
                $account,
                EmailSyncEventType::AccountDisconnected,
                EmailSyncOutcome::Success,
                detail: "revoked: {$reason}",
            );

            return $account->fresh();
        });
    }

    public function markError(EmailAccount $account, string $reason): EmailAccount
    {
        return (new TenantContextService())->runWithFirmContext($account->firm_id, function () use ($account, $reason) {
            $account->update([
                'connection_status' => EmailAccountConnectionStatus::Error,
                'error_reason' => $reason,
            ]);

            return $account->fresh();
        });
    }

    public function updateStorageMode(EmailAccount $account, FirmUser $actor, EmailStorageMode $storageMode): EmailAccount
    {
        if (! $this->accessPolicy->canManageMailbox($actor)) {
            throw new \RuntimeException('Actor role is not permitted to change mailbox storage mode.');
        }

        return (new TenantContextService())->runWithFirmContext($account->firm_id, function () use ($account, $storageMode) {
            $account->update(['storage_mode' => $storageMode]);

            return $account->fresh();
        });
    }
}
