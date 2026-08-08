<?php

declare(strict_types=1);

namespace Tests\Feature\ClientCrm;

use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\ContactResource;
use App\Filament\Firm\Resources\ContactResource\Pages\CreateContact;
use App\Filament\Firm\Resources\ContactResource\Pages\EditContact;
use App\Filament\Firm\Resources\ContactResource\Pages\ListContacts;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ContactResourceAccessTest — Contact has no creation restriction
 * (Firm Feature Manifest §1), so this proves ordinary full-CRUD
 * behavior plus tenant isolation, matching MatterResourceAccessTest's
 * established style (canAccess() coarse gate, list-query cross-firm
 * denial, real RLS proof via a raw DB::table() read, and a genuine
 * cross-firm 404 on direct URL/record-id access).
 */
final class ContactResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_guest_cannot_access_the_contact_resource(): void
    {
        $this->assertFalse(ContactResource::canAccess());
    }

    public function test_view_roles_can_access_the_contact_resource(): void
    {
        foreach ([
            FirmUserRole::FirmOwner,
            FirmUserRole::Attorney,
            FirmUserRole::Paralegal,
            FirmUserRole::LegalAssistant,
            FirmUserRole::Receptionist,
            FirmUserRole::BillingStaff,
        ] as $role) {
            $firm = Firm::factory()->create();
            $this->actingAsRole($firm, $role);

            $this->assertTrue(ContactResource::canAccess(), "canAccess() failed for role {$role->value}");
        }
    }

    public function test_receptionist_can_create_a_contact_but_billing_staff_cannot(): void
    {
        $firmA = Firm::factory()->create();
        $receptionist = $this->actingAsRole($firmA, FirmUserRole::Receptionist);
        $this->assertTrue(ContactResource::canCreate());

        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmB, FirmUserRole::BillingStaff);
        $this->assertFalse(ContactResource::canCreate());
    }

    public function test_list_page_renders_and_shows_only_this_firms_contacts(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::Paralegal);
        $contactA = $this->runWithFirmContext($firmA, fn () => Contact::factory()->forFirm($firmA)->create());
        $contactB = $this->runWithFirmContext($firmB, fn () => Contact::factory()->forFirm($firmB)->create());

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListContacts::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$contactA]);
        $test->assertCanNotSeeTableRecords([$contactB]);
    }

    public function test_real_rls_proof_a_raw_query_under_firm_a_context_cannot_read_firm_bs_contact_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $contactA = $this->runWithFirmContext($firmA, fn () => Contact::factory()->forFirm($firmA)->create());
        $contactB = $this->runWithFirmContext($firmB, fn () => Contact::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('contacts')->pluck('id')->all());

        $this->assertContains($contactA->id, $visibleIds);
        $this->assertNotContains($contactB->id, $visibleIds, "Firm A's session must never read Firm B's contact row.");
    }

    public function test_direct_url_guess_of_another_firms_contact_never_succeeds(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $contactB = $this->runWithFirmContext($firmB, fn () => Contact::factory()->forFirm($firmB)->create());

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(ContactResource::getUrl('view', ['record' => $contactB])));

        $response->assertNotFound();
    }

    /**
     * The ENTIRE mount+fillForm+call chain runs inside ONE
     * runWithFirmContext() closure — not split across separate wraps —
     * because runWithFirmContext() restores/clears BOTH the PHP-memory
     * AND PostgreSQL session context in its own finally block the
     * moment the wrapped closure returns (see that method's own
     * docblock). A Livewire::test() component genuinely re-serializes/
     * re-hydrates between each ->call(), so wrapping only the initial
     * Livewire::test(...) construction leaves later ->fillForm()/
     * ->call() invocations with no context at all — the same
     * documented class of gap this mission's Action classes already
     * guard against for real browser requests, reproduced here for
     * Livewire's OWN test harness instead.
     */
    public function test_create_contact_persists_via_the_wrapped_tenant_context_and_links_to_a_client(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::LegalAssistant);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($client): void {
            $test = Livewire::test(CreateContact::class);

            $test->fillForm([
                'name' => 'Jordan Opposing-Counsel',
                'company' => 'Rival LLP',
                'email' => 'jordan@rival.example',
                'phone' => '555-0100',
                'role' => 'Opposing Counsel',
                'client_id' => $client->id,
            ]);
            $test->call('create');
            $test->assertHasNoFormErrors();
        });

        $contact = $this->runWithFirmContext($firm, fn () => Contact::query()->where('name', 'Jordan Opposing-Counsel')->first());
        $this->assertNotNull($contact);
        $this->assertSame((int) $firm->id, (int) $contact->firm_id);
        $this->assertSame($client->id, $contact->client_id);
    }

    public function test_edit_contact_persists_a_change_via_the_wrapped_tenant_context(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $contact = $this->runWithFirmContext($firm, fn () => Contact::factory()->forFirm($firm)->create(['name' => 'Original Name']));

        $this->runWithFirmContext($firm, function () use ($contact): void {
            $test = Livewire::test(EditContact::class, ['record' => $contact->getRouteKey()]);

            $test->fillForm(['name' => 'Updated Name']);
            $test->call('save');
            $test->assertHasNoFormErrors();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => Contact::query()->find($contact->id));
        $this->assertSame('Updated Name', $fresh->name);
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
