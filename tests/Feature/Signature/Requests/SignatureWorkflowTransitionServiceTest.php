<?php

namespace Tests\Feature\Signature\Requests;

use App\Services\SignatureWorkflowTransitionService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Asserts the exact shared transition graph — the master-plan's 9
 * status values, reused by both signature_requests and
 * signature_request_recipients.
 */
class SignatureWorkflowTransitionServiceTest extends TestCase
{
    private SignatureWorkflowTransitionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SignatureWorkflowTransitionService();
    }

    #[DataProvider('allowedTransitions')]
    public function test_allowed_transitions_are_permitted(string $from, string $to): void
    {
        $this->assertTrue($this->service->isTransitionAllowed($from, $to));
        $this->service->assertTransitionAllowed($from, $to);
        $this->addToAssertionCount(1);
    }

    public static function allowedTransitions(): array
    {
        return [
            'draft -> sent' => ['draft', 'sent'],
            'draft -> voided' => ['draft', 'voided'],
            'sent -> viewed' => ['sent', 'viewed'],
            'sent -> declined' => ['sent', 'declined'],
            'sent -> expired' => ['sent', 'expired'],
            'sent -> voided' => ['sent', 'voided'],
            'viewed -> consented' => ['viewed', 'consented'],
            'viewed -> declined' => ['viewed', 'declined'],
            'consented -> signed' => ['consented', 'signed'],
            'consented -> declined' => ['consented', 'declined'],
            'signed -> completed' => ['signed', 'completed'],
            'signed -> voided' => ['signed', 'voided'],
        ];
    }

    #[DataProvider('disallowedTransitions')]
    public function test_disallowed_transitions_are_rejected(string $from, string $to): void
    {
        $this->assertFalse($this->service->isTransitionAllowed($from, $to));

        $this->expectException(\RuntimeException::class);
        $this->service->assertTransitionAllowed($from, $to);
    }

    public static function disallowedTransitions(): array
    {
        return [
            'draft -> viewed (skips sent)' => ['draft', 'viewed'],
            'draft -> signed' => ['draft', 'signed'],
            'sent -> signed (skips viewed/consented)' => ['sent', 'signed'],
            'viewed -> signed (skips consented)' => ['viewed', 'signed'],
            'completed -> anything' => ['completed', 'sent'],
            'declined -> sent' => ['declined', 'sent'],
            'expired -> viewed' => ['expired', 'viewed'],
            'voided -> draft' => ['voided', 'draft'],
        ];
    }

    public function test_all_four_terminal_states_permit_no_further_transitions(): void
    {
        foreach (['completed', 'declined', 'expired', 'voided'] as $terminal) {
            foreach (['draft', 'sent', 'viewed', 'consented', 'signed', 'completed', 'declined', 'expired', 'voided'] as $to) {
                $this->assertFalse($this->service->isTransitionAllowed($terminal, $to));
            }
        }
    }
}
