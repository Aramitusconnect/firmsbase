<?php

namespace App\Services;

use App\Models\EmailMessage;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * EmailSearchService — the single enforcement point for "email search
 * is metadata-only" (project rule). search() only ever filters on
 * subject, from_address, to_addresses, sent_at/received_at,
 * direction, and email_message_links (matter/client) — it never
 * references the encrypted body column in any where/like clause.
 * Body search stays blocked until a separately-approved encrypted-
 * search strategy exists (project rule) — a dedicated test asserts
 * this file's source never names that column at all.
 */
class EmailSearchService
{
    public function search(
        Firm $firm,
        ?string $subjectContains = null,
        ?string $fromAddress = null,
        ?int $matterId = null,
        ?int $clientId = null,
        ?\DateTimeInterface $sentAfter = null,
        ?\DateTimeInterface $sentBefore = null,
    ): Collection {
        $query = EmailMessage::query()->where('firm_id', $firm->id);

        if ($subjectContains !== null) {
            $query->where('subject', 'like', '%'.$subjectContains.'%');
        }

        if ($fromAddress !== null) {
            $query->where('from_address', $fromAddress);
        }

        if ($sentAfter !== null) {
            $query->where('sent_at', '>=', $sentAfter);
        }

        if ($sentBefore !== null) {
            $query->where('sent_at', '<=', $sentBefore);
        }

        if ($matterId !== null || $clientId !== null) {
            $query->whereHas('links', function (Builder $linkQuery) use ($matterId, $clientId) {
                if ($matterId !== null) {
                    $linkQuery->where('matter_id', $matterId);
                }

                if ($clientId !== null) {
                    $linkQuery->where('client_id', $clientId);
                }
            });
        }

        return $query->orderByDesc('sent_at')->get();
    }
}
