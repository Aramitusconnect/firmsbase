<?php

declare(strict_types=1);

namespace App\Livewire\ClientPortal;

use App\Enums\ClientPortalStatus;
use App\Models\Client;
use App\Services\ClientPortalService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * AcceptInvitationPage — Mission 3A (MyAttorney Launch-Flow Closure).
 * The one public, unauthenticated page a Client Portal invitation link
 * resolves to. Reached only via a signed URL
 * (routes/web.php's client-portal.invitation.accept route, gated by
 * Laravel's own 'signed' + throttle middleware, on the Client Portal's
 * own canonical host) — mirrors PublicIntakePage's exact architecture:
 * a plain Livewire component outside any Filament panel (this
 * host's real panel, `client-portal`, requires authentication for
 * everything else it serves), using the SAME
 * ConfigurePanelSessionCookie:client session cookie so a successful
 * login here carries into the panel's own subsequent authenticated
 * requests.
 *
 * Never trusts anything from the browser beyond the token route
 * parameter — every fact about the invitation (which Client, which
 * Firm) is re-derived server-side, fresh, on every request via
 * ClientPortalService::resolveByInvitationToken()'s own token-scoped
 * RLS self-lookup. An unknown, expired-and-rotated, or already-
 * consumed token renders the exact same generic "invalid or expired"
 * copy — this page never discloses which of those three applies
 * (anti-enumeration).
 *
 * Deliberately a DEDICATED page, not a reuse of Filament's own stock
 * ResetPassword page — activate() needs to create a NEW ClientPortalUser
 * row for a first-time credential, not reset an existing one via
 * Laravel's password broker (which requires a row, and a
 * canAccessPanel() check, to already exist — a real chicken/egg
 * problem for first-time activation this codebase's own audit
 * surfaced before this checkpoint began).
 */
class AcceptInvitationPage extends Component
{
    public string $token;

    public bool $found = false;

    public bool $valid = false;

    public string $firmDisplayName = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    /** @var array<string, string> */
    public array $validationErrors = [];

    public function mount(string $token): void
    {
        $this->token = $token;

        $client = app(ClientPortalService::class)->resolveByInvitationToken($token);

        if ($client === null) {
            $this->found = false;

            return;
        }

        $this->found = true;
        $this->valid = $client->portal_status === ClientPortalStatus::Invited;
        $this->hydrateDisplayFrom($client);
    }

    public function acceptInvitation(): void
    {
        $client = $this->resolveFreshInvitedClient();

        if ($client === null) {
            return;
        }

        if (trim($this->password) === '' || $this->password !== $this->passwordConfirmation) {
            $this->validationErrors = ['password' => 'Please enter matching passwords.'];

            return;
        }

        if (strlen($this->password) < 8) {
            $this->validationErrors = ['password' => 'Your password must be at least 8 characters.'];

            return;
        }

        try {
            $portalUser = app(ClientPortalService::class)->activate($client, $this->token, $this->password);
        } catch (\RuntimeException) {
            // Already consumed by a duplicate/retried request, or the
            // Firm revoked access between page-load and submit —
            // never a distinguishing error message toward the visitor.
            $this->found = true;
            $this->valid = false;

            return;
        }

        $this->password = '';
        $this->passwordConfirmation = '';

        Auth::guard('client')->login($portalUser);

        // Session-fixation hardening on the privilege change this
        // login represents — guarded by hasSession() exactly like
        // AppServiceProvider's own Login-event listener does, since
        // not every context this component can run under (e.g. a
        // Livewire::test() component call, as opposed to a real HTTP
        // request) has a session store attached to the request.
        if (request()->hasSession()) {
            request()->session()->regenerate();
        }

        Filament::setCurrentPanel(Filament::getPanel('client-portal'));
        $this->redirect(Filament::getUrl(), navigate: false);
    }

    private function resolveFreshInvitedClient(): ?Client
    {
        $client = app(ClientPortalService::class)->resolveByInvitationToken($this->token);

        if ($client === null) {
            $this->found = false;
            $this->valid = false;

            return null;
        }

        $this->found = true;
        $this->valid = $client->portal_status === ClientPortalStatus::Invited;
        $this->hydrateDisplayFrom($client);

        if (! $this->valid) {
            return null;
        }

        return $client;
    }

    private function hydrateDisplayFrom(Client $client): void
    {
        $this->firmDisplayName = (new TenantContextService)->runWithFirmContextWithoutTransaction(
            $client->firm_id,
            fn () => $client->firm->firmSettings?->branding_settings_json['display_name_override']
                ?? $client->firm->legal_name
                ?? $client->firm->name,
        );
    }

    public function render()
    {
        return view('livewire.client-portal.accept-invitation-page')
            ->layout('layouts.public-intake');
    }
}
