<?php

namespace App\Services;

use App\Enums\DomainEventType;
use App\Enums\FirmLeadStatus;
use App\Enums\WebhookEventType;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\FirmLead;
use App\Models\User;
use App\Services\Automation\DomainEventRecorderService;
use Illuminate\Support\Facades\DB;

/**
 * LeadConversionService — the ONLY place a Client is ever created from
 * a FirmLead. A lead must not silently become a client any other way
 * (project rule) — there is no other code path in this codebase that
 * sets FirmLead::converted_client_id.
 *
 * Phase 14b addition: fires client.created exactly once per successful
 * conversion, registered via DB::afterCommit() from inside convert()'s
 * existing DB::transaction() so it never fires ahead of the durable
 * commit and rolls back together with everything else in this method if
 * anything here throws. The isConverted() guard at the top of convert()
 * already prevents converting the same lead twice, so this can never
 * double-fire for one lead (Phase 14b rule 10).
 *
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 12: also
 * emits DomainEventType::ClientCreated INSIDE the same
 * runWithFirmContext()/transaction as the Client row itself (unlike the
 * webhook above, never deferred to afterCommit — if this insert fails
 * the whole conversion must roll back, matching every other
 * DomainEventRecorderService call site's convention). This was a
 * confirmed, previously-silent gap: before this checkpoint,
 * LeadConversionService fired no domain event at all, so no Zero-Click
 * automation could ever react to a new client — for every conversion
 * through this service, not only MyAttorney-originated ones.
 */
class LeadConversionService
{
    public function __construct(
        private TimelineEventRecorder $timeline,
        private DomainEventRecorderService $domainEvents = new DomainEventRecorderService,
    ) {}

    /**
     * @param  array<string, mixed>  $clientAttributes  Client fields
     *                                                  (display_name, legal_name, email, phone, preferred_language,
     *                                                  preferred_timezone, ...) — firm_id and created_by are set here,
     *                                                  not by the caller.
     *
     * @throws \RuntimeException if the lead was already converted
     */
    public function convert(
        FirmLead $lead,
        array $clientAttributes,
        ?User $actor = null,
        ?Consultation $consultation = null,
    ): Client {
        if ($lead->isConverted()) {
            throw new \RuntimeException('This lead has already been converted.');
        }

        return (new TenantContextService)->runWithFirmContext($lead->firm_id, function () use ($lead, $clientAttributes, $actor, $consultation) {
            $client = Client::create(array_merge([
                'firm_id' => $lead->firm_id,
                'created_by' => $actor?->id,
            ], $clientAttributes));

            $lead->update([
                'status' => FirmLeadStatus::Converted,
                'converted_client_id' => $client->id,
                'converted_at' => now(),
            ]);

            if ($consultation) {
                $consultation->update(['converted' => true]);
            }

            $this->timeline->record($lead->firm, 'lead_converted', $lead, $actor, ['client_id' => $client->id]);
            $this->timeline->record($lead->firm, 'client_created', $client, $actor);

            $this->domainEvents->record($lead->firm, DomainEventType::ClientCreated, [
                'client' => ['id' => $client->id],
            ], subject: $client);

            DB::afterCommit(function () use ($lead, $client) {
                try {
                    app(WebhookEventRecorderService::class)->record($lead->firm, WebhookEventType::ClientCreated, $client);
                } catch (\Throwable $e) {
                    report($e);
                }
            });

            return $client->fresh();
        });
    }
}
