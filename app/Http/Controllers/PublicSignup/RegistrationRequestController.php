<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSignup;

use App\Enums\PlatformLeadStatus;
use App\Http\Controllers\Controller;
use App\Services\FirmRegistrationAcknowledgementService;
use App\Services\PlatformSalesLeadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The two public registration-request forms behind the login pages' secondary
 * buttons.
 *
 * Neither form creates an account. That is the whole design, not a shortcut:
 *
 *  - A Client Portal login requires a ClientPortalUser, whose client_id is NOT
 *    NULL and foreign-keyed to clients, whose firm_id is NOT NULL and
 *    FORCE-RLS protected. There is therefore no representable "portal identity
 *    not yet linked to a firm" — creating one would mean attaching a stranger
 *    to somebody's firm.
 *  - Firm creation runs through FirmProvisioningService::provision(), which
 *    requires a PlatformAdmin actor and issues the owner an emailed setup link.
 *    A public form has no such actor, and inventing one would write a false
 *    actor into the provisioning audit trail.
 *
 * So both forms record a PlatformLead through the canonical
 * PlatformSalesLeadService and stop there. Real accounts continue to be created
 * only by the existing paths: FirmProvisioningService for firms, and the firm's
 * own invite -> ClientPortalService::activate() flow for clients. `source`
 * distinguishes the two request types for whoever works the queue.
 *
 * Nothing here accepts firm_id, client_id, matter_id or any uuid from the
 * browser. The forms cannot express a claim on an existing tenant's data, which
 * is the point — a self-service form is exactly where someone would try.
 */
class RegistrationRequestController extends Controller
{
    private const SOURCE_FIRM = 'firm_self_registration';

    private const SOURCE_CLIENT = 'client_access_request';

    public function showFirmForm(): View
    {
        return view('auth.firm-registration-request');
    }

    public function showClientForm(): View
    {
        return view('auth.client-access-request');
    }

    public function storeFirmRequest(
        Request $request,
        PlatformSalesLeadService $leads,
        FirmRegistrationAcknowledgementService $acknowledgements,
    ): RedirectResponse {
        $data = $request->validate([
            'firm_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            // 'rfc' only, deliberately not 'dns': a DNS lookup on every public
            // submission makes signup depend on a resolver being fast and
            // reachable, and rejects perfectly legitimate addresses on internal
            // or newly-registered domains. Deliverability is proven by the
            // invitation email that follows, not by a synchronous MX probe.
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        $lead = $leads->create([
            'company_name' => $data['firm_name'],
            'contact_name' => trim($data['first_name'].' '.$data['last_name']),
            'contact_email' => $data['email'],
            'source' => self::SOURCE_FIRM,
            'notes' => 'Submitted via public firm registration on the Firm host. '
                .'No Firm, User or FirmUser was created — provisioning must still run through '
                .'FirmProvisioningService so the owner receives the canonical setup invitation.',
        ]);

        // Acknowledgement only — never the setup invitation, which is sent by
        // FirmProvisioningService once the firm is actually provisioned. This
        // call cannot fail the request: the lead is already committed.
        $acknowledgements->sendFor($lead);

        return redirect()
            ->route('firm.register')
            ->with('requestReceived', true);
    }

    public function storeClientRequest(Request $request, PlatformSalesLeadService $leads): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            // Required rather than optional: a firm has to be able to verify
            // this person, and platform_leads.company_name is NOT NULL anyway.
            'firm_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $leads->create([
            'company_name' => $data['firm_name'],
            'contact_name' => trim($data['first_name'].' '.$data['last_name']),
            'contact_email' => $data['email'],
            'contact_phone' => $data['phone'] ?? null,
            'source' => self::SOURCE_CLIENT,
            'notes' => 'Submitted via public client access request on the Client Portal host. '
                .'No ClientPortalUser, Client, Matter or firm linkage was created. Portal access '
                .'is granted only by the named firm through its own invitation flow.',
        ]);

        return redirect()
            ->route('client-portal.register')
            ->with('requestReceived', true);
    }

    /**
     * Kept explicit so the status contract is visible to a reader: every public
     * request lands as New and is worked by a human. Nothing here can advance a
     * lead's status, let alone convert one.
     */
    public static function submittedStatus(): PlatformLeadStatus
    {
        return PlatformLeadStatus::New;
    }
}
