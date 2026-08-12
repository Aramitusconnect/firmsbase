<?php

declare(strict_types=1);

namespace App\Livewire\Marketplace;

use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\App;
use Livewire\Component;

/**
 * PublicIntakePage — Mission 3 (MyAttorney Conversion + AI Intake),
 * checkpoint 2 ("secure session/resume architecture"). The one public,
 * unauthenticated page a prospect's resumable intake link resolves to.
 * Reached only via a signed URL (routes/web.php's
 * public.marketplace-intakes.show route, gated by Laravel's own
 * 'signed' + throttle middleware) — mirrors PublicPaymentPage exactly.
 *
 * Never trusts anything from the browser beyond the uuid route
 * parameter — every fact about the intake is read fresh from the
 * stored row on every request. This checkpoint renders only the
 * resume/status shell (found/not-found/expired/current status); the
 * actual answer-collection UI is checkpoint 3's own scope ("Firm
 * eligibility + deterministic intake templates").
 */
class PublicIntakePage extends Component
{
    public string $uuid;

    public bool $found = false;

    public bool $resumable = false;

    public string $firmDisplayName = '';

    public string $status = '';

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;

        $service = App::make(MarketplaceIntakeService::class);
        $intake = $service->resolveByUuid($uuid);

        if ($intake === null) {
            $this->found = false;

            return;
        }

        $this->found = true;

        (new TenantContextService)->runWithFirmContext($intake->firm, function () use ($intake, $service) {
            if (! $intake->isResumable()) {
                if ($intake->status->isTerminal()) {
                    $this->hydrateDisplayFrom($intake->fresh());

                    return;
                }

                $intake = $service->markExpired($intake->firm, $intake);
                $this->hydrateDisplayFrom($intake);

                return;
            }

            $service->recordLinkResumed($intake->firm, $intake, request()->ip());
            $this->hydrateDisplayFrom($intake->fresh());
        });
    }

    private function hydrateDisplayFrom(MarketplaceIntake $intake): void
    {
        $this->resumable = $intake->isResumable();
        $this->status = $intake->status->value;
        $this->firmDisplayName = $intake->firm->firmSettings?->branding_settings_json['display_name_override']
            ?? $intake->firm->legal_name
            ?? $intake->firm->name;
    }

    public function render()
    {
        return view('livewire.marketplace.public-intake-page')
            ->layout('layouts.public-intake');
    }
}
