<?php

namespace App\Services;

/**
 * SignatureEsignLegalReadinessService — backend-only ESIGN/UETA
 * readiness checklist metadata (project rule: no legal conclusion
 * engine — this is a fixed reference checklist an attorney consults
 * before setting signature_requests.attorney_reviewed_at, never a
 * scored or auto-decided conclusion). Direct structural mirror of
 * Phase 6/10's BillingAccessibilityReadinessService/
 * FormAccessibilityReadinessService pattern — documentation-as-code,
 * no UI.
 *
 * This service does NOT determine, claim, or imply that any specific
 * document is enforceable by e-signature in any jurisdiction — it only
 * enumerates the elements an attorney should confirm. Jurisdiction/
 * review notes are stored on signature_requests.attorney_review_notes.
 */
class SignatureEsignLegalReadinessService
{
    public const REQUIRED_CHECKS = [
        'intent_to_sign_captured' => 'The signer\'s clear intent to sign electronically has been captured.',
        'consumer_disclosure_and_consent' => 'Required consumer disclosures were shown and the signer\'s consent to use an electronic record was captured before signing.',
        'record_retention_capability' => 'The signed record can be retained and accurately reproduced by the parties.',
        'tamper_evidence' => 'The signed record and its evidence trail are tamper-evident (append-only/immutable).',
        'signature_record_association' => 'The signature is logically associated with the record it signs and the signer\'s identity evidence.',
        'jurisdiction_review_completed' => 'An attorney has reviewed whether this specific document may be signed electronically in the relevant jurisdiction.',
    ];

    /**
     * @return array<string, string>
     */
    public function checklist(): array
    {
        return self::REQUIRED_CHECKS;
    }

    /**
     * @param array<string> $confirmedChecks the check keys a reviewing attorney has confirmed
     */
    public function isComplete(array $confirmedChecks): bool
    {
        return empty(array_diff(array_keys(self::REQUIRED_CHECKS), $confirmedChecks));
    }
}
