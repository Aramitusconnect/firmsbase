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
    }

    public function disconnect(EmailAccount $account, FirmUser $actor): EmailAccount
    {
        if (! $this->accessPolicy->canManageMailbox($actor)) {
            throw new \RuntimeException('Actor role is not permitted to disconnect a firm mailbox.');
        }

        $account->update(['connection_status' => EmailAccountConnectionStatus::Disconnected]);

        $this->auditService->record(
            $account->firm,
            $account,
            EmailSyncEventType::AccountDisconnected,
            EmailSyncOutcome::Success,
        );

        return $account->fresh();
    }

    public function revoke(EmailAccount $account, FirmUser $actor, string $reason): EmailAccount
    {
        if (! $this->accessPolicy->canManageMailbox($actor)) {
            throw new \RuntimeException('Actor role is not permitted to revoke a firm mailbox.');
        }

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
    }

    public function markError(EmailAccount $account, string $reason): EmailAccount
    {
        $account->update([
            'connection_status' => EmailAccountConnectionStatus::Error,
            'error_reason' => $reason,
        ]);

        return $account->fresh();
    }

    public function updateStorageMode(EmailAccount $account, FirmUser $actor, EmailStorageMode $storageMode): EmailAccount
    {
        if (! $this->accessPolicy->canManageMailbox($actor)) {
            throw new \RuntimeException('Actor role is not permitted to change mailbox storage mode.');
        }

        $account->update(['storage_mode' => $storageMode]);

        return $account->fresh();
    }
}
