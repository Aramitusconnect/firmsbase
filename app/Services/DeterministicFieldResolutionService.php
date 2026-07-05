<?php

namespace App\Services;

use App\Enums\FormMappingSourceEntity;
use App\Enums\FormMappingTransform;
use App\Models\Client;
use App\Models\Contact;
use App\Models\IntakeSubmission;
use App\Models\Matter;
use App\Models\Party;

/**
 * DeterministicFieldResolutionService — the ONE shared resolver used
 * by both form draft generation and document merge generation. Every
 * resolvable path is a fixed, hardcoded case in an explicit match
 * statement against real Phase 2 columns — never generic property
 * access, reflection, eval, or any AI call. This is what keeps
 * resolution deterministic and closes the injection/traversal risk a
 * generic path-resolver would otherwise open.
 *
 * Adding a new resolvable path requires a code change here, never a
 * config value or user-supplied expression.
 */
class DeterministicFieldResolutionService
{
    /**
     * @param array<string, mixed> $context Optional keys: client, matter, contact, party, intakeSubmission
     */
    public function resolve(FormMappingSourceEntity $entity, string $path, array $context): ?string
    {
        $raw = match ($entity) {
            FormMappingSourceEntity::Client => $this->resolveClient($context['client'] ?? null, $path),
            FormMappingSourceEntity::Matter => $this->resolveMatter($context['matter'] ?? null, $path),
            FormMappingSourceEntity::Contact => $this->resolveContact($context['contact'] ?? null, $path),
            FormMappingSourceEntity::Party => $this->resolveParty($context['party'] ?? null, $path),
            FormMappingSourceEntity::IntakeSubmission => $this->resolveIntakeSubmission($context['intakeSubmission'] ?? null, $path),
        };

        return $raw === null ? null : (string) $raw;
    }

    public function applyTransform(?string $value, FormMappingTransform $transform): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($transform) {
            FormMappingTransform::None => $value,
            FormMappingTransform::UppercaseText => mb_strtoupper($value),
            FormMappingTransform::TitleCaseText => mb_convert_case($value, MB_CASE_TITLE),
            FormMappingTransform::DateFormatUsDate => $this->formatUsDate($value),
        };
    }

    private function resolveClient(?Client $client, string $path): mixed
    {
        if (! $client) {
            return null;
        }

        return match ($path) {
            'client.display_name' => $client->display_name,
            'client.legal_name' => $client->legal_name,
            'client.email' => $client->email,
            'client.phone' => $client->phone,
            'client.preferred_language' => $client->preferred_language,
            'client.preferred_timezone' => $client->preferred_timezone,
            default => null,
        };
    }

    private function resolveMatter(?Matter $matter, string $path): mixed
    {
        if (! $matter) {
            return null;
        }

        return match ($path) {
            'matter.status' => $matter->status?->value,
            'matter.stage' => $matter->stage,
            'matter.opened_at' => $matter->opened_at?->toDateString(),
            'matter.closed_at' => $matter->closed_at?->toDateString(),
            default => null,
        };
    }

    private function resolveContact(?Contact $contact, string $path): mixed
    {
        if (! $contact) {
            return null;
        }

        return match ($path) {
            'contact.name' => $contact->name,
            'contact.company' => $contact->company,
            'contact.email' => $contact->email,
            'contact.phone' => $contact->phone,
            default => null,
        };
    }

    private function resolveParty(?Party $party, string $path): mixed
    {
        if (! $party) {
            return null;
        }

        return match ($path) {
            'party.name' => $party->name,
            'party.entity_type' => $party->entity_type?->value,
            'party.email' => $party->email,
            'party.phone' => $party->phone,
            'party.company' => $party->company,
            default => null,
        };
    }

    /**
     * IntakeSubmission paths take the form "intake_submission.<key>"
     * and are resolved via a direct, single-level key lookup on the
     * decoded responses_json array — never nested wildcard traversal,
     * never eval.
     */
    private function resolveIntakeSubmission(?IntakeSubmission $intake, string $path): mixed
    {
        if (! $intake || ! str_starts_with($path, 'intake_submission.')) {
            return null;
        }

        $key = substr($path, strlen('intake_submission.'));
        $responses = $intake->responses_json ?? [];

        return $responses[$key] ?? null;
    }

    private function formatUsDate(string $value): string
    {
        try {
            return \Carbon\Carbon::parse($value)->format('m/d/Y');
        } catch (\Throwable) {
            return $value;
        }
    }
}
