<?php

namespace App\Services\EmailProvider;

use App\Models\EmailAccount;

/**
 * FakeEmailProviderClient — deterministic, no real scanning engine, no
 * daemon, no network or filesystem I/O, no Gmail/Graph SDK. This is
 * the ONLY EmailProviderClient implementation in Phase 9 (approved:
 * "FakeEmailProviderClient only. No real Gmail API calls. No real
 * Outlook/Microsoft Graph calls. No network I/O.").
 *
 * Behavior is driven entirely by an explicit, in-memory fixture queue
 * seeded via seedMessagesForAccount() — tests get full control over
 * exactly which fixture messages/attachments are returned. If nothing
 * was seeded for an account, a small deterministic default batch of 2
 * plain messages (no attachments) is returned so basic tests work
 * without any setup.
 */
class FakeEmailProviderClient implements EmailProviderClient
{
    /** @var array<int, array<int, array>> */
    private array $fixturesByAccountId = [];

    public function seedMessagesForAccount(int $accountId, array $messages): void
    {
        $this->fixturesByAccountId[$accountId] = $messages;
    }

    public function fetchNewMessages(EmailAccount $account, ?string $sinceCursor): array
    {
        $messages = $this->fixturesByAccountId[$account->id] ?? $this->defaultFixtureMessages($account);

        $startCursor = (int) ($sinceCursor ?? 0);

        return [
            'messages' => $messages,
            'resulting_cursor' => (string) ($startCursor + count($messages)),
        ];
    }

    private function defaultFixtureMessages(EmailAccount $account): array
    {
        return [
            [
                'provider_thread_id' => 'thread-fixture-1',
                'provider_message_id' => 'msg-fixture-1',
                'direction' => 'inbound',
                'from_address' => 'client@example.com',
                'to_addresses' => [$account->mailbox_address],
                'subject' => 'Fixture inbound message',
                'sent_at' => now()->subMinutes(10)->toIso8601String(),
                'received_at' => now()->subMinutes(9)->toIso8601String(),
                'body' => 'This is a deterministic fixture email body.',
                'attachments' => [],
            ],
            [
                'provider_thread_id' => 'thread-fixture-1',
                'provider_message_id' => 'msg-fixture-2',
                'direction' => 'outbound',
                'from_address' => $account->mailbox_address,
                'to_addresses' => ['client@example.com'],
                'subject' => 'Re: Fixture inbound message',
                'sent_at' => now()->subMinutes(5)->toIso8601String(),
                'received_at' => null,
                'body' => 'This is a deterministic fixture reply body.',
                'attachments' => [],
            ],
        ];
    }
}
