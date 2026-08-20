<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Settings;

use App\Enums\AiMode;
use App\Enums\AiProvider;
use App\Enums\AiProviderKeyStatus;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Pages\FirmAiSettingsPage;
use App\Filament\Support\StepUp\StepUpAuthentication;
use App\Models\Firm;
use App\Models\FirmAiProviderKey;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\AiProviderKeyService;
use App\Services\FirmAiConfigurationService;
use App\Services\Security\StepUpAuthenticationService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Ai\Concerns\SetsUpAiEntitledFirm;
use Tests\TestCase;

/**
 * Settings → AI & Automation.
 *
 * The page holds the only self-service path to a credential that costs the
 * firm real money, so the tests that matter most are the ones about who may
 * touch it and what it refuses to do — not the ones about what it renders.
 */
final class FirmAiSettingsPageTest extends TestCase
{
    use RefreshDatabase, SetsUpAiEntitledFirm;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    /**
     * @return array{0: Firm, 1: FirmUser}
     */
    private function actingAsFirmRole(FirmUserRole $role, ?Firm $firm = null): array
    {
        $firm ??= $this->makeAiEntitledFirm(AiMode::FirmOwned);

        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create(),
        );

        $this->actingAs($firmUser->user);

        // Credential mutations are step-up protected. Verifying up front keeps
        // each test about the behaviour it names; the step-up gate itself is
        // asserted separately below.
        app(StepUpAuthenticationService::class)->markVerified('web');

        return [$firm, $firmUser];
    }

    private function statuses(Firm $firm): array
    {
        return $this->runWithFirmContext($firm, fn () => FirmAiProviderKey::query()
            ->where('firm_id', $firm->id)
            ->orderBy('id')
            ->pluck('status')
            ->all());
    }

    private function fakeSuccessfulOpenAi(): void
    {
        Http::fake(['*/responses' => Http::response([
            'output' => [['content' => [['text' => 'ok']]]],
            'usage' => ['input_tokens' => 8, 'output_tokens' => 2],
        ], 200)]);
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    public function test_a_firm_owner_can_open_the_page(): void
    {
        $this->actingAsFirmRole(FirmUserRole::FirmOwner);

        Livewire::test(FirmAiSettingsPage::class)->assertOk();
    }

    public function test_a_user_with_no_firm_membership_is_denied(): void
    {
        // The shape a client-portal user has from the firm panel's point of
        // view: authenticated, but no FirmUser row.
        $this->actingAs(User::factory()->create());

        Livewire::test(FirmAiSettingsPage::class)->assertForbidden();
    }

    public function test_an_unauthenticated_visitor_is_denied(): void
    {
        Livewire::test(FirmAiSettingsPage::class)->assertForbidden();
    }

    public function test_a_paralegal_may_view_but_may_not_change_anything(): void
    {
        [$firm] = $this->actingAsFirmRole(FirmUserRole::Paralegal);

        Livewire::test(FirmAiSettingsPage::class)->assertOk();

        Livewire::test(FirmAiSettingsPage::class)
            ->assertActionHidden('addApiKey')
            ->assertActionHidden('testConnection');

        // save() is the one handler reachable by name, so it carries its own
        // server-side check rather than relying on the button being hidden.
        Livewire::test(FirmAiSettingsPage::class)
            ->set('data.ai_mode', AiMode::Disabled->value)
            ->call('save')
            ->assertForbidden();

        $this->assertSame([], $this->statuses($firm));
        $this->assertSame(AiMode::FirmOwned, $firm->fresh()->firmSettings->ai_mode);
    }

    public function test_a_paralegal_cannot_revoke_the_firms_credential(): void
    {
        $firm = $this->makeAiEntitledFirm(AiMode::FirmOwned);
        app(AiProviderKeyService::class)->import($firm, AiProvider::OpenAi, 'test-key-not-a-real-credential');
        $this->actingAsFirmRole(FirmUserRole::Paralegal, $firm);

        Livewire::test(FirmAiSettingsPage::class)
            ->assertActionHidden('revokeApiKey')
            ->assertActionHidden('rotateApiKey');

        $this->assertSame([AiProviderKeyStatus::Active], $this->statuses($firm));
    }

    // -----------------------------------------------------------------
    // Firm scoping
    // -----------------------------------------------------------------

    public function test_the_page_never_reports_another_firms_credential(): void
    {
        $otherFirm = $this->makeAiEntitledFirm(AiMode::FirmOwned);
        app(AiProviderKeyService::class)->import($otherFirm, AiProvider::OpenAi, 'other-firms-key');

        [$firm] = $this->actingAsFirmRole(FirmUserRole::FirmOwner);

        Livewire::test(FirmAiSettingsPage::class)
            ->assertOk()
            ->assertSee('None stored');

        $this->assertSame([], $this->statuses($firm));
        $this->assertSame([AiProviderKeyStatus::Active], $this->statuses($otherFirm));
    }

    public function test_one_firms_revocation_leaves_another_firms_key_active(): void
    {
        $otherFirm = $this->makeAiEntitledFirm(AiMode::FirmOwned);
        app(AiProviderKeyService::class)->import($otherFirm, AiProvider::OpenAi, 'other-firms-key');

        [$firm] = $this->actingAsFirmRole(FirmUserRole::FirmOwner);
        app(AiProviderKeyService::class)->import($firm, AiProvider::OpenAi, 'own-key');

        Livewire::test(FirmAiSettingsPage::class)->callAction('revokeApiKey');

        $this->assertSame([AiProviderKeyStatus::Revoked], $this->statuses($firm));
        $this->assertSame([AiProviderKeyStatus::Active], $this->statuses($otherFirm));
    }

    // -----------------------------------------------------------------
    // Mode
    // -----------------------------------------------------------------

    public function test_saving_switches_the_firm_between_disabled_and_firm_owned(): void
    {
        [$firm] = $this->actingAsFirmRole(FirmUserRole::FirmOwner);

        Livewire::test(FirmAiSettingsPage::class)
            ->set('data.ai_mode', AiMode::Disabled->value)
            ->set('data.intake_ai_assist_enabled', false)
            ->call('save');

        $this->assertSame(AiMode::Disabled, $firm->fresh()->firmSettings->ai_mode);

        Livewire::test(FirmAiSettingsPage::class)
            ->set('data.ai_mode', AiMode::FirmOwned->value)
            ->set('data.intake_ai_assist_enabled', true)
            ->call('save');

        $fresh = $firm->fresh(['firmSettings', 'aiSettings']);
        $this->assertSame(AiMode::FirmOwned, $fresh->firmSettings->ai_mode);
        $this->assertTrue((bool) $this->runWithFirmContext($firm, fn () => $fresh->aiSettings->intake_ai_assist_enabled));
    }

    public function test_a_forged_platform_managed_mode_is_refused(): void
    {
        // PlatformManaged would mean "FirmsVault supplies the key", and it
        // holds none. The option is absent from the form, so reaching this
        // state at all means the payload was tampered with.
        [$firm] = $this->actingAsFirmRole(FirmUserRole::FirmOwner);

        Livewire::test(FirmAiSettingsPage::class)
            ->set('data.ai_mode', AiMode::PlatformManaged->value)
            ->call('save')
            ->assertHasErrors('data.ai_mode');

        $this->assertSame(AiMode::FirmOwned, $firm->fresh()->firmSettings->ai_mode);
    }

    public function test_the_service_itself_refuses_platform_managed_even_if_the_form_is_bypassed(): void
    {
        // The form's option list rejects the value first, which is why this
        // second test exists: the guarantee must not depend on a UI control.
        $firm = $this->makeAiEntitledFirm(AiMode::FirmOwned);

        $this->expectException(\InvalidArgumentException::class);

        app(FirmAiConfigurationService::class)->setMode($firm, AiMode::PlatformManaged);
    }

    public function test_an_unknown_mode_string_is_refused(): void
    {
        [$firm] = $this->actingAsFirmRole(FirmUserRole::FirmOwner);

        Livewire::test(FirmAiSettingsPage::class)
            ->set('data.ai_mode', 'whatever_the_attacker_wants')
            ->call('save')
            ->assertHasErrors('data.ai_mode');

        $this->assertSame(AiMode::FirmOwned, $firm->fresh()->firmSettings->ai_mode);
    }

    // -----------------------------------------------------------------
    // Credential
    // -----------------------------------------------------------------

    public function test_adding_a_key_stores_it_encrypted_and_never_renders_it(): void
    {
        [$firm] = $this->actingAsFirmRole(FirmUserRole::FirmOwner);

        Livewire::test(FirmAiSettingsPage::class)
            ->callAction('addApiKey', ['apiKey' => 'sk-a-secret-that-must-never-be-echoed', 'label' => 'Main key']);

        $this->assertSame([AiProviderKeyStatus::Active], $this->statuses($firm));

        $stored = $this->runWithFirmContext($firm, fn () => FirmAiProviderKey::query()->where('firm_id', $firm->id)->first());
        $this->assertStringNotContainsString('sk-a-secret-that-must-never-be-echoed', $stored->encrypted_key_ciphertext);

        Livewire::test(FirmAiSettingsPage::class)
            ->assertOk()
            ->assertDontSee('sk-a-secret-that-must-never-be-echoed')
            ->assertSee('Stored and active');
    }

    public function test_an_empty_key_is_rejected_without_storing_anything(): void
    {
        [$firm] = $this->actingAsFirmRole(FirmUserRole::FirmOwner);

        Livewire::test(FirmAiSettingsPage::class)->callAction('addApiKey', ['apiKey' => '   ']);

        $this->assertSame([], $this->statuses($firm));
    }

    public function test_rotating_supersedes_the_previous_key_rather_than_revoking_it(): void
    {
        [$firm] = $this->actingAsFirmRole(FirmUserRole::FirmOwner);
        app(AiProviderKeyService::class)->import($firm, AiProvider::OpenAi, 'first-key');

        Livewire::test(FirmAiSettingsPage::class)->callAction('rotateApiKey', ['apiKey' => 'second-key']);

        $this->assertSame([AiProviderKeyStatus::Rotated, AiProviderKeyStatus::Active], $this->statuses($firm));
    }

    public function test_revoking_turns_ai_off_with_no_successor(): void
    {
        [$firm] = $this->actingAsFirmRole(FirmUserRole::FirmOwner);
        app(AiProviderKeyService::class)->import($firm, AiProvider::OpenAi, 'only-key');

        Livewire::test(FirmAiSettingsPage::class)
            ->callAction('revokeApiKey')
            ->assertOk()
            ->assertSee('Revoked — no replacement stored');

        $this->assertSame([AiProviderKeyStatus::Revoked], $this->statuses($firm));
    }

    // -----------------------------------------------------------------
    // Connection
    // -----------------------------------------------------------------

    public function test_test_connection_reports_a_working_credential(): void
    {
        [$firm] = $this->actingAsFirmRole(FirmUserRole::FirmOwner);
        app(AiProviderKeyService::class)->import($firm, AiProvider::OpenAi, 'working-key');
        $this->fakeSuccessfulOpenAi();

        Livewire::test(FirmAiSettingsPage::class)
            ->callAction('testConnection')
            ->assertSet('connectionSucceeded', true);
    }

    public function test_test_connection_reports_a_rejected_credential_without_leaking_the_provider_body(): void
    {
        [$firm] = $this->actingAsFirmRole(FirmUserRole::FirmOwner);
        app(AiProviderKeyService::class)->import($firm, AiProvider::OpenAi, 'bad-key');
        Http::fake(['*/responses' => Http::response(['error' => ['message' => 'Incorrect API key provided: sk-leak']], 401)]);

        $page = Livewire::test(FirmAiSettingsPage::class)->callAction('testConnection');

        $page->assertSet('connectionSucceeded', false);
        $this->assertStringNotContainsString('sk-leak', $page->get('connectionMessage'));
    }

    public function test_test_connection_needs_manage_rights(): void
    {
        $firm = $this->makeAiEntitledFirm(AiMode::FirmOwned);
        app(AiProviderKeyService::class)->import($firm, AiProvider::OpenAi, 'working-key');
        $this->actingAsFirmRole(FirmUserRole::Attorney, $firm);
        Http::fake();

        Livewire::test(FirmAiSettingsPage::class)
            ->assertActionHidden('testConnection');

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Step-up
    // -----------------------------------------------------------------

    public function test_credential_actions_demand_a_password_when_the_session_has_no_fresh_verification(): void
    {
        // The page's own defence against an authenticated-but-stolen session:
        // the step-up field is present exactly when the session has not
        // recently proved possession of the password.
        [$firm] = $this->actingAsFirmRole(FirmUserRole::FirmOwner);
        app(StepUpAuthenticationService::class)->forget('web');

        $fields = collect(StepUpAuthentication::schemaFor('web'))
            ->map(fn ($field) => $field->getName())
            ->all();

        $this->assertSame(['stepUpCurrentPassword'], $fields);

        app(StepUpAuthenticationService::class)->markVerified('web');

        $this->assertSame([], StepUpAuthentication::schemaFor('web'));
    }

    public function test_the_page_exposes_no_platform_managed_option_and_no_second_provider(): void
    {
        $this->actingAsFirmRole(FirmUserRole::FirmOwner);

        Livewire::test(FirmAiSettingsPage::class)
            ->assertOk()
            ->assertDontSee('Platform Managed')
            ->assertDontSee('Anthropic')
            ->assertDontSee('Azure');
    }
    // -----------------------------------------------------------------
    // Onboarding state: Firm Owned, provider chosen, no credential yet
    // -----------------------------------------------------------------
    //
    // This is where a firm necessarily starts, and it is the state that broke
    // in staging: the buttons were rendered with STRING action handlers, which
    // Filament turns into a direct wire:click on a Livewire method — no
    // mounting, no modal, no step-up. Clicking "Add API Key" called a handler
    // that expected the modal's $data with no arguments at all, and the page
    // answered 500. The tests below pin every part of that.

    public function test_a_firm_owned_firm_with_no_credential_yet_renders_cleanly(): void
    {
        $this->actingAsFirmRole(FirmUserRole::FirmOwner);

        Livewire::test(FirmAiSettingsPage::class)
            ->assertOk()
            ->assertSee('None stored')
            ->assertSee('No active API key is stored for this firm.')
            ->assertActionVisible('addApiKey')
            ->assertActionVisible('testConnection')
            ->assertActionHidden('rotateApiKey')
            ->assertActionHidden('revokeApiKey');
    }

    public function test_the_add_api_key_action_mounts_its_modal_with_no_credential_present(): void
    {
        $this->actingAsFirmRole(FirmUserRole::FirmOwner);

        Livewire::test(FirmAiSettingsPage::class)
            ->mountAction('addApiKey')
            ->assertOk()
            ->assertActionMounted('addApiKey');
    }

    public function test_test_connection_with_no_credential_answers_gracefully_instead_of_erroring(): void
    {
        $this->actingAsFirmRole(FirmUserRole::FirmOwner);
        Http::fake();

        Livewire::test(FirmAiSettingsPage::class)
            ->callAction('testConnection')
            ->assertOk()
            ->assertSet('connectionSucceeded', false)
            ->assertSet('connectionMessage', 'This firm has no active API key. Add one before testing the connection.');

        Http::assertNothingSent();
    }

    public function test_no_credential_action_is_wired_to_a_string_handler(): void
    {
        // The structural guard. A string handler is returned verbatim as the
        // button's wire:click, which skips mounting entirely — so the modal
        // that carries the step-up password field never renders. Asserted on
        // the action objects rather than on rendered HTML so it holds however
        // Filament chooses to draw the button.
        $this->actingAsFirmRole(FirmUserRole::FirmOwner);

        $page = Livewire::test(FirmAiSettingsPage::class)->instance();

        foreach (['addApiKey', 'testConnection', 'rotateApiKey', 'revokeApiKey'] as $name) {
            $action = $page->getAction($name);

            $this->assertNotNull($action, "The {$name} action must exist.");
            $this->assertInstanceOf(
                \Closure::class,
                (new \ReflectionProperty(Action::class, 'action'))->getValue($action),
                "The {$name} action must use a closure handler: a string handler bypasses the modal, and with it the step-up check.",
            );
        }
    }

    public function test_the_page_exposes_no_public_method_that_requires_an_argument(): void
    {
        // Every public method on a Livewire component is callable from the
        // browser by name. One requiring an argument answers 500 when called
        // with none — which is precisely how the staging page failed — and one
        // that mutates state is reachable without its confirmation modal.
        $offenders = [];

        $reflection = new \ReflectionClass(FirmAiSettingsPage::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // getDeclaringClass() names the USING class for a trait method, so
            // the file is what actually distinguishes this page's own code from
            // Filament's traits.
            if ($method->getFileName() !== $reflection->getFileName()) {
                continue;
            }

            if ($method->getNumberOfRequiredParameters() === 0) {
                continue;
            }

            // Filament/Livewire lifecycle methods are invoked by the framework
            // with their arguments, never by a bare client-side name call.
            if (in_array($method->getName(), ['content', 'form'], true)) {
                continue;
            }

            $offenders[] = $method->getName();
        }

        $this->assertSame([], $offenders, 'These public methods are client-callable and would fail or bypass their modal.');
    }

    public function test_adding_a_key_without_a_fresh_step_up_verification_demands_the_password(): void
    {
        [$firm] = $this->actingAsFirmRole(FirmUserRole::FirmOwner);
        app(StepUpAuthenticationService::class)->forget('web');

        Livewire::test(FirmAiSettingsPage::class)
            ->callAction('addApiKey', ['apiKey' => 'sk-must-not-be-stored-without-step-up'])
            ->assertHasActionErrors(['stepUpCurrentPassword']);

        $this->assertSame([], $this->statuses($firm), 'No credential may be stored by an action that skipped step-up.');
    }

    public function test_a_correct_password_satisfies_step_up_and_stores_the_key(): void
    {
        $firm = $this->makeAiEntitledFirm(AiMode::FirmOwned);
        $password = 'a-real-password-2026';
        $user = User::factory()->create(['is_active' => true, 'password' => bcrypt($password)]);
        $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser($user)->role(FirmUserRole::FirmOwner)->create(),
        );
        $this->actingAs($user);
        app(StepUpAuthenticationService::class)->forget('web');

        Livewire::test(FirmAiSettingsPage::class)
            ->callAction('addApiKey', [
                'apiKey' => 'sk-stored-only-after-step-up',
                'stepUpCurrentPassword' => $password,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame([AiProviderKeyStatus::Active], $this->statuses($firm));
    }

    public function test_an_unauthenticated_visitor_gets_a_redirect_not_a_raw_error(): void
    {
        // A session that expired mid-visit must land on the login page rather
        // than a 500 the user reads as "the product is broken".
        $response = $this->get($this->firmAppUrl('/ai-automation'));

        $response->assertRedirect();
        $this->assertStringContainsString('/login', $response->headers->get('Location'));
    }
}
