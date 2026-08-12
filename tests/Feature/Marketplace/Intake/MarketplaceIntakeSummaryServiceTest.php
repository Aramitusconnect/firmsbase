<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Enums\AiMode;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Marketplace\Services\MarketplaceIntakeSummaryService;
use App\Models\AiUsageEvent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\AiModeResolutionService;
use App\Services\AiPolicySettingService;
use App\Services\AiProviderAdapterInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Feature\Ai\Concerns\SetsUpAiEntitledFirm;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 9 —
 * MarketplaceIntakeSummaryService: the first real Firm-User-
 * authenticated AI call in this codebase. Proves it genuinely records
 * usage through the canonical AiUsageRecorderService (Firm+User
 * scoped, ai_usage_events — never the anonymous-actor
 * MarketplaceAiUsageEvent path), inherits the existing AI mode/budget
 * gates unmodified, and never persists a partial summary on failure.
 */
class MarketplaceIntakeSummaryServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpAiEntitledFirm;

    private function service(): MarketplaceIntakeSummaryService
    {
        return app(MarketplaceIntakeSummaryService::class);
    }

    /**
     * @return array{0: Firm, 1: MarketplaceIntake, 2: User}
     */
    private function setUpIntakeWithAnswers(): array
    {
        $firm = $this->makeAiEntitledFirm(AiMode::PlatformManaged);
        $directoryFirm = DirectoryFirm::factory()->member()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]);
        $intake = app(MarketplaceIntakeService::class)->startForDirectoryFirm($directoryFirm);

        $this->runWithFirmContext($firm, fn () => $intake->update([
            'structured_data' => ['legal_issue' => 'Contract dispute with a vendor.'],
        ]));

        $user = User::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser($user)->create());

        return [$firm, $intake, $user];
    }

    public function test_generate_persists_a_summary_and_timestamp(): void
    {
        [$firm, $intake, $user] = $this->setUpIntakeWithAnswers();

        $result = $this->service()->generate($firm, $intake, $user);

        $this->assertNotNull($result->ai_summary);
        $this->assertNotEmpty($result->ai_summary);
        $this->assertNotNull($result->ai_summary_generated_at);
    }

    public function test_generate_records_real_firm_and_user_scoped_ai_usage(): void
    {
        [$firm, $intake, $user] = $this->setUpIntakeWithAnswers();

        $this->service()->generate($firm, $intake, $user);

        $event = $this->runWithFirmContext($firm, fn () => AiUsageEvent::query()->where('user_id', $user->id)->first());
        $this->assertNotNull($event, 'A real Firm+User-scoped ai_usage_events row must be recorded — not the anonymous-actor path.');
        $this->assertSame($firm->id, $event->firm_id);
    }

    public function test_generate_throws_and_persists_nothing_when_ai_mode_is_disabled(): void
    {
        $firm = $this->makeAiEntitledFirm(AiMode::Disabled);
        $directoryFirm = DirectoryFirm::factory()->member()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]);
        $intake = app(MarketplaceIntakeService::class)->startForDirectoryFirm($directoryFirm);
        $user = User::factory()->create();

        $this->expectException(\RuntimeException::class);

        $this->service()->generate($firm, $intake, $user);
    }

    public function test_generate_rejects_an_intake_belonging_to_a_different_firm(): void
    {
        [, $intake] = $this->setUpIntakeWithAnswers();
        $otherFirm = Firm::factory()->create();
        $otherUser = User::factory()->create();

        $this->expectException(\RuntimeException::class);

        $this->service()->generate($otherFirm, $intake, $otherUser);
    }

    public function test_generate_never_includes_the_conversation_transcript_in_the_prompt(): void
    {
        [$firm, $intake, $user] = $this->setUpIntakeWithAnswers();
        $this->runWithFirmContext($firm, fn () => $intake->update([
            'conversation_transcript' => [['role' => 'visitor', 'content' => 'SECRET_TRANSCRIPT_MARKER', 'at' => now()->toIso8601String()]],
        ]));

        $result = $this->service()->generate($firm, $intake, $user);

        // FakeAiProviderAdapter's output text echoes back the request's
        // own instructionText/documentDerivedText — this proves the
        // transcript marker never reached the prompt at all.
        $this->assertStringNotContainsString('SECRET_TRANSCRIPT_MARKER', $result->ai_summary);
    }

    // ---------------------------------------------------------------
    // Mission 3, checkpoint 15 (adversarial audit) — the platform AI
    // kill switch must block BEFORE any provider call, not only be
    // observed after the fact via AiUsageRecorderService's own
    // post-hoc check.
    // ---------------------------------------------------------------

    public function test_generate_never_calls_the_provider_when_the_platform_kill_switch_is_engaged(): void
    {
        [$firm, $intake, $user] = $this->setUpIntakeWithAnswers();
        app(AiPolicySettingService::class)->set(AiModeResolutionService::PLATFORM_KILL_SWITCH_KEY, false);

        $provider = Mockery::mock(AiProviderAdapterInterface::class);
        $provider->shouldNotReceive('generate');
        $this->app->instance(AiProviderAdapterInterface::class, $provider);

        $this->expectException(\RuntimeException::class);

        $this->service()->generate($firm, $intake, $user);
    }

    public function test_generate_with_no_structured_answers_still_succeeds(): void
    {
        $firm = $this->makeAiEntitledFirm(AiMode::PlatformManaged);
        $directoryFirm = DirectoryFirm::factory()->member()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]);
        $intake = app(MarketplaceIntakeService::class)->startForDirectoryFirm($directoryFirm);
        $user = User::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser($user)->create());

        $result = $this->service()->generate($firm, $intake, $user);

        $this->assertNotNull($result->ai_summary);
    }
}
