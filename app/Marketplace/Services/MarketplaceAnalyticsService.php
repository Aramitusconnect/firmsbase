<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\MarketplaceAnalyticsEventType;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceAnalyticsEvent;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\ViewModels\SearchCriteria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MarketplaceAnalyticsService — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 13. The ONLY write path for
 * directory_marketplace_analytics_events — called directly from the
 * public FirmProfileController/AttorneyProfileController/HomeController
 * (never a queued job: this is a single, cheap, best-effort insert,
 * not a multi-step operation that benefits from retry/backoff).
 *
 * recordSearchPerformed() only ever persists coarse, already-public
 * taxonomy facets (practice area slug, city/state, language,
 * consultation mode, accepting-inquiries filter) — never
 * SearchCriteria::$name (the free-text query a visitor typed, which
 * could incidentally contain a person's name or other identifying
 * text) and never $originLatitude/$originLongitude/$postalCode
 * (too fine-grained a geographic signal for an anonymous aggregate
 * log — city/state is the deliberate precision ceiling here).
 *
 * Every record*() method swallows its own exceptions: analytics must
 * never be able to break a public marketplace page. A failure is
 * logged (message only — never the exception's own trace, which could
 * otherwise leak query bindings) and the caller proceeds exactly as if
 * nothing happened.
 */
class MarketplaceAnalyticsService
{
    public function recordFirmProfileView(DirectoryFirm $firm): void
    {
        $this->record(MarketplaceAnalyticsEventType::FirmProfileViewed, $firm);
    }

    public function recordAttorneyProfileView(DirectoryAttorney $attorney): void
    {
        $this->record(MarketplaceAnalyticsEventType::AttorneyProfileViewed, $attorney);
    }

    public function recordSearchPerformed(SearchCriteria $criteria): void
    {
        $dimensions = array_filter([
            'practice_area_slug' => $criteria->practiceAreaSlug,
            'city' => $criteria->city,
            'state' => $criteria->state,
            'language_code' => $criteria->languageCode,
            'consultation_mode' => $criteria->consultationMode?->value,
            'accepting_inquiries_only' => $criteria->acceptingInquiriesOnly ? true : null,
        ], fn ($value) => $value !== null);

        $this->record(MarketplaceAnalyticsEventType::SearchPerformed, null, $dimensions !== [] ? $dimensions : null);
    }

    /**
     * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 14.
     * Five funnel-stage counters, one per method to keep each call site
     * a single, obvious line (mirrors deadline_approaching/
     * deadline_missed's own "separate event per distinct fact" house
     * style rather than one method with a $stage parameter). subject
     * is the intake's own DirectoryFirm when known — never the intake
     * itself (MarketplaceIntake is not a public marketplace entity;
     * only its owning listing is).
     */
    public function recordIntakeStarted(MarketplaceIntake $intake): void
    {
        $this->record(MarketplaceAnalyticsEventType::IntakeStarted, $intake->directoryFirm);
    }

    public function recordIntakeSubmitted(MarketplaceIntake $intake): void
    {
        $this->record(MarketplaceAnalyticsEventType::IntakeSubmitted, $intake->directoryFirm);
    }

    public function recordIntakeAccepted(MarketplaceIntake $intake): void
    {
        $this->record(MarketplaceAnalyticsEventType::IntakeAccepted, $intake->directoryFirm);
    }

    public function recordIntakeDeclined(MarketplaceIntake $intake): void
    {
        $this->record(MarketplaceAnalyticsEventType::IntakeDeclined, $intake->directoryFirm);
    }

    public function recordIntakeConverted(MarketplaceIntake $intake): void
    {
        $this->record(MarketplaceAnalyticsEventType::IntakeConverted, $intake->directoryFirm);
    }

    /**
     * @param  array<string, mixed>|null  $dimensions
     */
    private function record(MarketplaceAnalyticsEventType $type, ?Model $subject, ?array $dimensions = null): void
    {
        try {
            MarketplaceAnalyticsEvent::create([
                'event_type' => $type,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'dimensions' => $dimensions,
                'occurred_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('MarketplaceAnalyticsService: failed to record event', [
                'event_type' => $type->value,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
