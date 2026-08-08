<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\FirmUserRole;
use App\Enums\PaymentClassification;
use App\Enums\PaymentStatus;
use App\Filament\Firm\Resources\ClientResource\Pages\ListClients;
use App\Filament\Firm\Resources\PaymentResource;
use App\Filament\Firm\Resources\PaymentResource\Actions\RecordClientPaymentAction;
use App\Filament\Firm\Resources\PaymentResource\Actions\RecordPaymentAction;
use App\Filament\Firm\Resources\PaymentResource\Concerns\RecordsManualPayment;
use App\Filament\Firm\Resources\PaymentResource\Pages\ListPayments;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Payment;
use App\Models\PaymentClassificationEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PaymentResourceAccessTest — Firm Feature Manifest §6, cross-cutting
 * finding #11 (Manual Client Payments). Proves role ceilings, that
 * "Record Payment" calls ManualPaymentService::submit() for real (never
 * a bare Payment::create()), idempotency-key double-submit protection,
 * that trust/IOLTA classification can never be forced through this UI,
 * and the small RLS regression checklist required for this module.
 */
final class PaymentResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // 1. List page renders (no entitlement gate — see PaymentResource's
    //    own docblock for why)
    // ------------------------------------------------------------

    public function test_list_page_renders_for_an_authorized_role(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListPayments::class));

        $test->assertSuccessful();
    }

    // ------------------------------------------------------------
    // 2. Role ceilings for "Record Payment"
    // ------------------------------------------------------------

    public function test_record_payment_action_is_visible_for_firm_owner_attorney_and_billing_staff(): void
    {
        foreach ([FirmUserRole::FirmOwner, FirmUserRole::Attorney, FirmUserRole::BillingStaff] as $role) {
            $firm = Firm::factory()->create();
            $this->actingAsRole($firm, $role);

            $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListPayments::class));
            $test->assertActionVisible(RecordPaymentAction::getDefaultName());
        }
    }

    public function test_record_payment_action_is_hidden_for_paralegal_legal_assistant_and_receptionist(): void
    {
        foreach ([FirmUserRole::Paralegal, FirmUserRole::LegalAssistant, FirmUserRole::Receptionist] as $role) {
            $firm = Firm::factory()->create();
            $this->actingAsRole($firm, $role);

            $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListPayments::class));
            $test->assertActionHidden(RecordPaymentAction::getDefaultName());
        }
    }

    public function test_record_client_payment_row_action_is_hidden_for_receptionist(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($client): void {
            $test = Livewire::test(ListClients::class);
            $test->assertTableActionHidden(RecordClientPaymentAction::getDefaultName(), $client);
        });
    }

    public function test_unauthorized_role_cannot_record_a_payment_even_if_the_action_is_forced(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        // The action's ->visible() closure feeds directly into Filament's
        // own Action::isDisabled() (CanBeHidden::isHidden() -> isDisabled())
        // — a hidden action is ALSO disabled at the framework level, so
        // mountAction() refuses to mount it at all (its own first check:
        // `if ($action->isDisabled()) { unmount; return null; }`) before
        // this Action's own action() closure — and therefore the extra
        // role re-check inside RecordsManualPayment — is ever reached.
        // This is a STRONGER guarantee than a closure-level check alone:
        // an unauthorized role cannot even open the modal, let alone
        // submit it, confirmed here by asserting the action never mounts.
        $this->runWithFirmContext($firm, function () use ($client): void {
            $test = Livewire::test(ListPayments::class);
            $test->mountAction(RecordPaymentAction::getDefaultName());
            $test->setActionData([
                'client_id' => $client->id,
                'amount' => 100,
                'method' => 'cash',
                'classification' => 'operating_payment',
            ]);
            $test->callMountedAction();
            $test->assertActionHidden(RecordPaymentAction::getDefaultName());
        });

        $this->assertSame(0, $this->runWithFirmContext($firm, fn () => Payment::query()->count()));
    }

    // ------------------------------------------------------------
    // 3. Record Payment succeeds via ManualPaymentService (never a bare
    //    Payment::create())
    // ------------------------------------------------------------

    public function test_record_payment_action_creates_a_payment_via_manual_payment_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['display_name' => 'Acme Corp']));

        $this->runWithFirmContext($firm, function () use ($client): void {
            $test = Livewire::test(ListPayments::class);
            $test->mountAction(RecordPaymentAction::getDefaultName());
            $test->setActionData([
                'client_id' => $client->id,
                'amount' => 250.00,
                'method' => 'check',
                'classification' => 'operating_payment',
                'external_reference' => 'CHK-1001',
                'method_reference' => null,
                'notes' => 'Retainer payment',
            ]);
            $test->callMountedAction();
            $test->assertNotified('Payment recorded');
        });

        $payment = $this->runWithFirmContext($firm, fn () => Payment::query()->where('client_id', $client->id)->first());
        $this->assertNotNull($payment);
        $this->assertSame(25000, $payment->amount_cents);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(PaymentClassification::OperatingPayment, $payment->payment_classification);

        // The proof this went through ManualPaymentService, not a bare
        // Payment::create(): a ManualPaymentRecord detail row AND a
        // PaymentClassificationEvent audit row both exist, linked to
        // this payment — neither is ever written except by
        // ManualPaymentService::submit()/PaymentClassificationService::
        // recordDecision().
        $this->assertNotNull($payment->manualPaymentRecord);
        $this->assertSame('CHK-1001', $payment->external_reference);

        $event = $this->runWithFirmContext($firm, fn () => PaymentClassificationEvent::query()->where('payment_id', $payment->id)->first());
        $this->assertNotNull($event, 'A PaymentClassificationEvent must exist — proves PaymentClassificationService::recordDecision() ran.');
        $this->assertSame('classification_accepted', $event->event_type);
    }

    public function test_record_client_payment_row_action_locks_the_client_to_the_row(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($client): void {
            $test = Livewire::test(ListClients::class);
            $test->mountTableAction(RecordClientPaymentAction::getDefaultName(), $client);
            $test->assertTableActionDataSet(['client_id' => $client->id]);
            $test->setActionData([
                'amount' => 50,
                'method' => 'cash',
                'classification' => 'operating_payment',
            ]);
            $test->callMountedTableAction();
            $test->assertNotified('Payment recorded');
        });

        $payment = $this->runWithFirmContext($firm, fn () => Payment::query()->where('client_id', $client->id)->first());
        $this->assertNotNull($payment);
        $this->assertSame(5000, $payment->amount_cents);
    }

    // ------------------------------------------------------------
    // 4. Idempotency — the same idempotency_key submitted twice (a
    //    double-click/retry within the same modal session, where the
    //    Hidden field's ->default(fn () => Str::uuid()) closure — which
    //    Filament evaluates exactly once per form fill, confirmed
    //    precedent: ProvisionFirmAction's own identical Hidden field —
    //    carries the identical value both times) must create exactly
    //    ONE Payment row, not two.
    // ------------------------------------------------------------

    public function test_submitting_the_same_idempotency_key_twice_creates_only_one_payment(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $sharedIdempotencyKey = (string) Str::uuid();

        $submit = function () use ($client, $sharedIdempotencyKey): void {
            $test = Livewire::test(ListPayments::class);
            $test->mountAction(RecordPaymentAction::getDefaultName());
            $test->setActionData([
                'idempotency_key' => $sharedIdempotencyKey,
                'client_id' => $client->id,
                'amount' => 75,
                'method' => 'cash',
                'classification' => 'operating_payment',
            ]);
            $test->callMountedAction();
            $test->assertNotified('Payment recorded');
        };

        // First submission — creates the payment.
        $this->runWithFirmContext($firm, $submit);
        // Second submission — same idempotency key (simulates a
        // double-click/resubmit before the page reloads). Must NOT
        // create a second row: ManualPaymentService::submit()'s own
        // check-then-return-existing branch, backstopped by the
        // partial unique index on payments(firm_id, idempotency_key).
        $this->runWithFirmContext($firm, $submit);

        $count = $this->runWithFirmContext($firm, fn () => Payment::query()->where('client_id', $client->id)->count());
        $this->assertSame(1, $count, 'A repeated idempotency key must never create a second Payment row.');
    }

    // ------------------------------------------------------------
    // 5. Trust/IOLTA classification can never be offered or forced
    // ------------------------------------------------------------

    public function test_classification_field_never_offers_trust_iolta_payment_as_an_option(): void
    {
        $source = file_get_contents(app_path('Filament/Firm/Resources/PaymentResource/Support/RecordPaymentFormFields.php'));
        $this->assertIsString($source);

        preg_match('/Select::make\(\'classification\'\).*?;/s', $source, $matches);
        $this->assertNotEmpty($matches, 'Could not locate the classification Select field in RecordPaymentFormFields.');

        $this->assertStringNotContainsString('trust_iolta_payment', $matches[0]);
        $this->assertStringContainsString('operating_payment', $matches[0]);
    }

    /**
     * Layer 1: the classification Select only ever declares one option
     * (`operating_payment`) — Filament's own Select validates a
     * submitted value against its declared options, so forcing
     * `trust_iolta_payment` through `setActionData()` (simulating a
     * tampered client-side payload) is rejected as a form validation
     * error and the action is never called at all — no Payment row is
     * created.
     */
    public function test_forcing_trust_iolta_payment_classification_is_rejected_by_form_validation(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($client): void {
            $test = Livewire::test(ListPayments::class);
            $test->mountAction(RecordPaymentAction::getDefaultName());
            // Forged value — never a real option the Select renders.
            $test->setActionData([
                'client_id' => $client->id,
                'amount' => 40,
                'method' => 'cash',
                'classification' => 'trust_iolta_payment',
            ]);
            $test->callMountedAction();
            $test->assertHasActionErrors(['classification']);
        });

        $this->assertSame(0, $this->runWithFirmContext($firm, fn () => Payment::query()->where('client_id', $client->id)->count()));
    }

    /**
     * Layer 2 (defense-in-depth, independent of Filament's own Select
     * validation): RecordsManualPayment::recordManualPayment() itself
     * NEVER reads the submitted `classification` value — it always
     * passes the hardcoded `PaymentClassification::OperatingPayment`
     * literal to `ManualPaymentService::submit()`. Proven here by
     * invoking the trait method directly with a forged data array that
     * bypasses the Select/Livewire layer entirely (as if some future
     * change weakened the form-level validation) — the resulting
     * Payment is still OperatingPayment.
     */
    public function test_the_record_payment_handler_hardcodes_operating_payment_regardless_of_submitted_data(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $handler = new class
        {
            use RecordsManualPayment;

            public function handle(array $data): void
            {
                $this->recordManualPayment($data);
            }
        };

        $this->runWithFirmContext($firm, function () use ($handler, $client): void {
            $handler->handle([
                'idempotency_key' => (string) Str::uuid(),
                'client_id' => $client->id,
                'matter_id' => null,
                'invoice_id' => null,
                'payment_plan_installment_id' => null,
                'amount' => 40,
                'method' => 'cash',
                // Forged directly — bypasses the Select entirely.
                'classification' => 'trust_iolta_payment',
                'external_reference' => null,
                'method_reference' => null,
                'notes' => null,
            ]);
        });

        $payment = $this->runWithFirmContext($firm, fn () => Payment::query()->where('client_id', $client->id)->first());
        $this->assertNotNull($payment);
        $this->assertSame(
            PaymentClassification::OperatingPayment,
            $payment->payment_classification,
            'A forged trust_iolta_payment classification must never reach ManualPaymentService::submit() — the handler always hardcodes OperatingPayment.'
        );
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
    }

    // ------------------------------------------------------------
    // 6. Small RLS regression checklist (a/b/c/d)
    // ------------------------------------------------------------

    /** (a) a firm user can access its own Payment records. */
    public function test_a_firm_user_can_access_its_own_payments(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->forFirm($firm)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(PaymentResource::getUrl('view', ['record' => $payment])));

        $response->assertSuccessful();
    }

    /** (b) a foreign firm's Payment is not returned by the list/query. */
    public function test_list_page_shows_only_this_firms_payments(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $paymentA = $this->runWithFirmContext($firmA, fn () => Payment::factory()->forFirm($firmA)->create());
        $paymentB = $this->runWithFirmContext($firmB, fn () => Payment::factory()->forFirm($firmB)->create());

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListPayments::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$paymentA]);
        $test->assertCanNotSeeTableRecords([$paymentB]);
    }

    public function test_real_rls_proof_a_raw_query_under_firm_a_context_cannot_read_firm_bs_payment_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $paymentA = $this->runWithFirmContext($firmA, fn () => Payment::factory()->forFirm($firmA)->create());
        $paymentB = $this->runWithFirmContext($firmB, fn () => Payment::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('payments')->pluck('id')->all());

        $this->assertContains($paymentA->id, $visibleIds);
        $this->assertNotContains($paymentB->id, $visibleIds, "Firm A's session must never read Firm B's payment row.");
    }

    /** (c) a foreign client cannot be selected via the client_id relation select. */
    public function test_client_select_options_never_include_a_foreign_firms_client(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($clientA, $clientB): void {
            $visibleClientIds = Client::query()->pluck('id')->all();

            $this->assertContains($clientA->id, $visibleClientIds);
            $this->assertNotContains($clientB->id, $visibleClientIds, "Firm A's client_id options must never include Firm B's client.");
        });
    }

    /** (d) direct navigation to a foreign record's URL is blocked. */
    public function test_direct_url_guess_of_another_firms_payment_never_succeeds(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $paymentB = $this->runWithFirmContext($firmB, fn () => Payment::factory()->forFirm($firmB)->create());

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(PaymentResource::getUrl('view', ['record' => $paymentB])));

        $response->assertNotFound();
    }

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
