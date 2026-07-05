<?php

namespace App\Services;

use App\Enums\SignatureRequestStatus;
use App\Models\SignatureRequest;

/**
 * SignatureRequestAggregationService — derives signature_requests.status
 * from its recipients' individual statuses after every recipient
 * transition. The master plan gives exactly one status vocabulary for
 * "signature request" with no documented multi-recipient aggregation
 * rule, so this is a reasoned, explicit design decision:
 *
 *  - The request advances to 'viewed' once ANY recipient reaches
 *    'viewed' (first-viewer signal — informational, not load-bearing).
 *  - The request advances to 'consented' / 'signed' only once ALL
 *    active (non-terminal-negative) recipients individually reach that
 *    same stage — a unanimity gate, since consent and signature are
 *    both all-or-nothing at the request level.
 *  - If ANY recipient reaches 'declined' or 'expired', that same
 *    terminal state cascades immediately to the request — no
 *    partial-completion policy is invented.
 *  - 'completed' is set ONLY by SignatureCertificateService, never by
 *    this service.
 *  - 'sent' / 'voided' are direct staff actions on the request itself
 *    (SignatureRequestWorkflowService), not derived here.
 *
 * Every step advances through SignatureWorkflowTransitionService one
 * hop at a time (never skipped), so the request's own transition
 * history stays fully valid against the shared graph.
 */
class SignatureRequestAggregationService
{
    private const STAGE_ORDER = [
        'sent' => 1,
        'viewed' => 2,
        'consented' => 3,
        'signed' => 4,
    ];

    public function __construct(private readonly SignatureWorkflowTransitionService $transitions)
    {
    }

    public function recompute(SignatureRequest $request): SignatureRequest
    {
        $request->refresh();

        // Aggregation only applies while the request is actively progressing.
        if (! array_key_exists($request->status->value, self::STAGE_ORDER)) {
            return $request;
        }

        $recipients = $request->recipients()->get();

        if ($recipients->contains(fn ($r) => $r->status === SignatureRequestStatus::Declined)) {
            return $this->advanceTo($request, SignatureRequestStatus::Declined);
        }

        if ($recipients->contains(fn ($r) => $r->status === SignatureRequestStatus::Expired)) {
            return $this->advanceTo($request, SignatureRequestStatus::Expired);
        }

        $active = $recipients->reject(fn ($r) => $r->status === SignatureRequestStatus::Voided);

        if ($active->isEmpty()) {
            return $request;
        }

        $signedOrBeyond = [
            SignatureRequestStatus::Signed,
            SignatureRequestStatus::Completed,
        ];

        $consentedOrBeyond = [
            SignatureRequestStatus::Consented,
            SignatureRequestStatus::Signed,
            SignatureRequestStatus::Completed,
        ];

        $viewedOrBeyond = [
            SignatureRequestStatus::Viewed,
            SignatureRequestStatus::Consented,
            SignatureRequestStatus::Signed,
            SignatureRequestStatus::Completed,
        ];

        if ($active->every(fn ($r) => in_array($r->status, $signedOrBeyond, true))) {
            return $this->advanceStepwiseTo($request, SignatureRequestStatus::Signed);
        }

        if ($active->every(fn ($r) => in_array($r->status, $consentedOrBeyond, true))) {
            return $this->advanceStepwiseTo($request, SignatureRequestStatus::Consented);
        }

        if ($active->contains(fn ($r) => in_array($r->status, $viewedOrBeyond, true))) {
            return $this->advanceStepwiseTo($request, SignatureRequestStatus::Viewed);
        }

        return $request;
    }

    private function advanceStepwiseTo(SignatureRequest $request, SignatureRequestStatus $target): SignatureRequest
    {
        while ($request->status !== $target && array_key_exists($request->status->value, self::STAGE_ORDER)) {
            $currentStage = self::STAGE_ORDER[$request->status->value];
            $targetStage = self::STAGE_ORDER[$target->value];

            if ($targetStage <= $currentStage) {
                break;
            }

            $next = array_search($currentStage + 1, self::STAGE_ORDER, true);

            if ($next === false || ! $this->transitions->isTransitionAllowed($request->status->value, $next)) {
                break;
            }

            $request->update(['status' => SignatureRequestStatus::from($next)]);
            $request->refresh();
        }

        return $request;
    }

    private function advanceTo(SignatureRequest $request, SignatureRequestStatus $terminal): SignatureRequest
    {
        if ($this->transitions->isTransitionAllowed($request->status->value, $terminal->value)) {
            $timestampColumn = match ($terminal) {
                SignatureRequestStatus::Declined => 'declined_at',
                SignatureRequestStatus::Expired => null,
                default => null,
            };

            $attributes = ['status' => $terminal];
            if ($timestampColumn !== null) {
                $attributes[$timestampColumn] = now();
            }

            $request->update($attributes);
            $request->refresh();
        }

        return $request;
    }
}
