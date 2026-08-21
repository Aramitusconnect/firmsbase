<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SignatureRequestStatus;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Document;
use App\Models\GeneratedDocument;
use App\Models\Party;
use App\Models\SignatureRequestRecipient;
use App\Services\PdfDownloadPolicyService;
use App\Services\SignatureRecipientWorkflowService;
use App\Services\SignatureWorkflowTransitionService;
use App\Services\TenantContextService;
use App\Services\TenantSafeSignatureAndPdfPolicyService;
use App\ValueObjects\PdfAccessDecision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SignatureRecipientController — the ONLY unauthenticated, public
 * entry point onto a signature_request_recipients row. Non-payment
 * completion program, e-signature signer-facing flow.
 *
 * Every action here follows the exact same two-phase shape:
 *   1. resolveRecipient() — a narrow, RLS-safe self-lookup by uuid
 *      (TenantContextService::withSignatureRecipientSelfLookupContext(),
 *      the FOR SELECT-only carve-out added by
 *      2026_11_25_100001_add_self_lookup_clause_to_signature_request_recipients_rls_policy.php)
 *      followed by an in-PHP hash_equals() comparison of the caller-
 *      supplied raw token against the resolved row's own
 *      access_token_hash. A wrong/missing/reused-on-an-expired-row
 *      token, or a uuid that does not exist at all, both collapse to
 *      the exact same generic 404 — this method never lets a caller
 *      distinguish "no such recipient" from "recipient exists, token
 *      wrong" (same collapse-to-false discipline
 *      WebhookConnectionResolverService's own docblock documents for
 *      its analogous lookup).
 *   2. Once resolved, every further read/write establishes REAL
 *      app.current_firm_id context via
 *      TenantContextService::runWithFirmContext($recipient->firm_id, ...)
 *      — never trusting the self-lookup context for anything beyond
 *      the one row it was built to find — and re-verifies
 *      TenantSafeSignatureAndPdfPolicyService::assertSignatureRequestRecipientBelongsToFirm()
 *      as defense-in-depth before touching the parent SignatureRequest
 *      or its source document.
 *
 * PdfDownloadPolicyService::decideForRecipient() — not
 * DocumentSecurityService::canBeDownloadedBy() or any
 * canBeViewedInPortalBy() variant — is the sole authorization boundary
 * for document() below; those other checks are for firm-staff/client-
 * portal actors, never an anonymous signer.
 *
 * Existing SignatureRecipientWorkflowService methods (view/consent/
 * sign/decline) are called exactly as-is — this controller never
 * reimplements their transition-graph enforcement. Each is only
 * invoked when SignatureWorkflowTransitionService::isTransitionAllowed()
 * already agrees the transition is legal from the recipient's current
 * state, so a stale re-POST (e.g. a signer double-clicking "Sign," or
 * revisiting an already-signed link) degrades to a harmless re-render
 * of the current state rather than a thrown RuntimeException.
 */
class SignatureRecipientController extends Controller
{
    private const CONSENT_TEXT_VERSION = 'e_signature_consent_v1';

    private const CONSENT_TEXT = 'By checking this box and clicking "I Consent," you agree to sign this document electronically and acknowledge that your electronic signature is legally binding, has the same force and effect as a handwritten signature, and that you have the right to receive this document on paper or withdraw your consent at any time by contacting the firm.';

    public function show(Request $request, string $uuid): View|Response
    {
        $recipient = $this->resolveRecipient($uuid, (string) $request->query('token', ''));

        if ($recipient === null) {
            abort(404);
        }

        $recipient = (new TenantContextService)->runWithFirmContext($recipient->firm_id, function () use ($recipient, $request) {
            $fresh = $recipient->fresh();

            (new TenantSafeSignatureAndPdfPolicyService)->assertSignatureRequestRecipientBelongsToFirm($fresh, $fresh->firm);

            if (app(SignatureWorkflowTransitionService::class)->isTransitionAllowed($fresh->status->value, SignatureRequestStatus::Viewed->value)) {
                return app(SignatureRecipientWorkflowService::class)->view(
                    $fresh,
                    (string) $request->ip(),
                    substr((string) $request->userAgent(), 0, 500),
                );
            }

            return $fresh;
        });

        $token = (string) $request->query('token', '');

        return view('signature-recipients.show', [
            'recipient' => $recipient,
            'token' => $token,
            'documentAccessible' => $this->documentAccessDecision($recipient)->allowed,
            'consentText' => self::CONSENT_TEXT,
            'canConsent' => app(SignatureWorkflowTransitionService::class)->isTransitionAllowed($recipient->status->value, SignatureRequestStatus::Consented->value),
            'canSign' => $recipient->hasConsented() && app(SignatureWorkflowTransitionService::class)->isTransitionAllowed($recipient->status->value, SignatureRequestStatus::Signed->value),
            'canDecline' => app(SignatureWorkflowTransitionService::class)->isTransitionAllowed($recipient->status->value, SignatureRequestStatus::Declined->value),
        ]);
    }

    public function document(Request $request, string $uuid): StreamedResponse
    {
        $recipient = $this->resolveRecipient($uuid, (string) $request->query('token', ''));

        if ($recipient === null) {
            abort(404);
        }

        return (new TenantContextService)->runWithFirmContext($recipient->firm_id, function () use ($recipient) {
            $fresh = $recipient->fresh();

            (new TenantSafeSignatureAndPdfPolicyService)->assertSignatureRequestRecipientBelongsToFirm($fresh, $fresh->firm);

            $source = $this->resolveSourceDocument($fresh);

            abort_if($source === null, 404);

            $decision = app(PdfDownloadPolicyService::class)->decideForRecipient($fresh, $source);

            abort_unless($decision->allowed, 403);

            return Storage::disk($source->storage_disk)->response(
                $source->storage_path,
                $source instanceof Document ? $source->original_filename : 'document.pdf',
                ['Content-Disposition' => 'inline'],
            );
        });
    }

    public function consent(Request $request, string $uuid): View|Response
    {
        $recipient = $this->resolveRecipient($uuid, (string) $request->input('token', ''));

        if ($recipient === null) {
            abort(404);
        }

        (new TenantContextService)->runWithFirmContext($recipient->firm_id, function () use ($recipient, $request) {
            $fresh = $recipient->fresh();

            (new TenantSafeSignatureAndPdfPolicyService)->assertSignatureRequestRecipientBelongsToFirm($fresh, $fresh->firm);

            if (! app(SignatureWorkflowTransitionService::class)->isTransitionAllowed($fresh->status->value, SignatureRequestStatus::Consented->value)) {
                return;
            }

            app(SignatureRecipientWorkflowService::class)->consent(
                $fresh,
                acknowledgerType: $this->acknowledgerType($fresh),
                acknowledgerId: $this->acknowledgerId($fresh),
                textVersion: self::CONSENT_TEXT_VERSION,
                ipAddress: (string) $request->ip(),
                userAgent: substr((string) $request->userAgent(), 0, 500),
            );
        });

        return redirect()->route('public.signature-recipients.show', ['uuid' => $uuid, 'token' => (string) $request->input('token', '')])
            ->with('status', 'Thank you — your consent has been recorded.');
    }

    public function sign(Request $request, string $uuid): View|Response
    {
        $recipient = $this->resolveRecipient($uuid, (string) $request->input('token', ''));

        if ($recipient === null) {
            abort(404);
        }

        (new TenantContextService)->runWithFirmContext($recipient->firm_id, function () use ($recipient) {
            $fresh = $recipient->fresh();

            (new TenantSafeSignatureAndPdfPolicyService)->assertSignatureRequestRecipientBelongsToFirm($fresh, $fresh->firm);

            if (! $fresh->hasConsented() || ! app(SignatureWorkflowTransitionService::class)->isTransitionAllowed($fresh->status->value, SignatureRequestStatus::Signed->value)) {
                return;
            }

            app(SignatureRecipientWorkflowService::class)->sign($fresh);
        });

        return redirect()->route('public.signature-recipients.show', ['uuid' => $uuid, 'token' => (string) $request->input('token', '')])
            ->with('status', 'Thank you — the document has been signed.');
    }

    public function decline(Request $request, string $uuid): View|Response
    {
        $recipient = $this->resolveRecipient($uuid, (string) $request->input('token', ''));

        if ($recipient === null) {
            abort(404);
        }

        $reason = (string) $request->input('reason', '');

        (new TenantContextService)->runWithFirmContext($recipient->firm_id, function () use ($recipient, $reason) {
            $fresh = $recipient->fresh();

            (new TenantSafeSignatureAndPdfPolicyService)->assertSignatureRequestRecipientBelongsToFirm($fresh, $fresh->firm);

            if (! app(SignatureWorkflowTransitionService::class)->isTransitionAllowed($fresh->status->value, SignatureRequestStatus::Declined->value)) {
                return;
            }

            app(SignatureRecipientWorkflowService::class)->decline($fresh, $reason !== '' ? $reason : 'Declined by signer.');
        });

        return redirect()->route('public.signature-recipients.show', ['uuid' => $uuid, 'token' => (string) $request->input('token', '')])
            ->with('status', 'You have declined to sign this document.');
    }

    /**
     * Two-phase resolution: a narrow self-lookup by uuid (proves
     * nothing about the token), then an explicit hash_equals()
     * comparison in PHP of the caller-supplied raw token against the
     * resolved row's own access_token_hash. Both an unknown uuid and a
     * uuid with a wrong/blank token return null identically — callers
     * MUST turn a null here into a generic 404, never a distinguishing
     * response.
     */
    private function resolveRecipient(string $uuid, string $rawToken): ?SignatureRequestRecipient
    {
        if ($rawToken === '') {
            return null;
        }

        $recipient = (new TenantContextService)->withSignatureRecipientSelfLookupContext(
            $uuid,
            fn () => SignatureRequestRecipient::query()->where('uuid', $uuid)->first(),
        );

        if ($recipient === null || $recipient->access_token_hash === null) {
            return null;
        }

        $suppliedHash = hash('sha256', $rawToken);

        if (! hash_equals($recipient->access_token_hash, $suppliedHash)) {
            return null;
        }

        return $recipient;
    }

    private function resolveSourceDocument(SignatureRequestRecipient $recipient): Document|GeneratedDocument|null
    {
        $signatureRequest = $recipient->signatureRequest;

        if ($signatureRequest === null) {
            return null;
        }

        return $signatureRequest->document_id !== null
            ? $signatureRequest->document
            : $signatureRequest->generatedDocument;
    }

    private function documentAccessDecision(SignatureRequestRecipient $recipient): PdfAccessDecision
    {
        return (new TenantContextService)->runWithFirmContext($recipient->firm_id, function () use ($recipient) {
            $source = $this->resolveSourceDocument($recipient->fresh());

            if ($source === null) {
                return PdfAccessDecision::deny('No source document is attached to this signature request.');
            }

            return app(PdfDownloadPolicyService::class)->decideForRecipient($recipient->fresh(), $source);
        });
    }

    private function acknowledgerType(SignatureRequestRecipient $recipient): string
    {
        return match (true) {
            $recipient->client_id !== null => Client::class,
            $recipient->contact_id !== null => Contact::class,
            $recipient->party_id !== null => Party::class,
            default => SignatureRequestRecipient::class,
        };
    }

    private function acknowledgerId(SignatureRequestRecipient $recipient): int
    {
        return match (true) {
            $recipient->client_id !== null => (int) $recipient->client_id,
            $recipient->contact_id !== null => (int) $recipient->contact_id,
            $recipient->party_id !== null => (int) $recipient->party_id,
            default => (int) $recipient->id,
        };
    }
}
