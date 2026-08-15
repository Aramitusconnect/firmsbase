<?php

namespace App\Services;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * StatusPagePublicationCapabilityService — answers one question that
 * the Operations console must never get wrong: does marking a
 * status_page_events row "Published" actually publish anything to
 * anyone outside this admin panel? Operations Control Plane addition.
 *
 * Today the answer is no. StatusPageService::publish() writes a
 * database row and nothing else — there is no public route, no public
 * controller, no public view, and no outbound notification anywhere
 * in this codebase that reads status_page_events. An operator who
 * clicks "Publish" during an incident and believes customers have
 * been informed is in a materially worse position than one who knows
 * they still have to communicate by other means, which is why this
 * is surfaced prominently rather than left in a docblock.
 *
 * The answer is DERIVED, not hardcoded: this inspects the actual
 * registered route table for a publicly reachable status endpoint. If
 * a real public publisher is added later, this service reports it
 * automatically and the console's language changes with it — a
 * hardcoded "MISSING" constant would silently become the new lie.
 */
class StatusPagePublicationCapabilityService
{
    /**
     * URI prefixes that would constitute a genuinely public status
     * surface. Matched against registered GET routes that carry no
     * authentication middleware.
     */
    private const PUBLIC_STATUS_URI_PATTERNS = [
        'status',
        'status/*',
        'public/status',
        'public/status/*',
    ];

    /**
     * True only when a publicly reachable (unauthenticated) HTTP
     * endpoint serving platform status actually exists.
     */
    public function hasPublicPublicationBackend(): bool
    {
        return $this->publicStatusRouteUri() !== null;
    }

    /**
     * The URI of the public status endpoint, or null when none
     * exists. Exposed so the console can link to the real thing the
     * moment there is one.
     */
    public function publicStatusRouteUri(): ?string
    {
        foreach (Route::getRoutes() as $route) {
            if (! $route instanceof RoutingRoute) {
                continue;
            }

            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (! $this->uriLooksLikePublicStatus($route->uri())) {
                continue;
            }

            if ($this->isAuthenticated($route)) {
                continue;
            }

            return $route->uri();
        }

        return null;
    }

    /**
     * The canonical, operator-facing description of what "Published"
     * currently means. Used verbatim by every surface that shows a
     * publication state so the wording cannot drift between pages.
     */
    public function publicationSemanticsLabel(): string
    {
        return $this->hasPublicPublicationBackend()
            ? 'Published publicly'
            : 'Recorded internally — not published publicly';
    }

    public function disclosure(): string
    {
        if ($this->hasPublicPublicationBackend()) {
            return sprintf(
                'Publishing a status update makes it publicly visible at %s. Treat the public message as external '.
                'customer communication.',
                $this->publicStatusRouteUri(),
            );
        }

        return 'NO PUBLIC STATUS PAGE EXISTS. Publishing here writes an internal database record and nothing more — '.
            'there is no public endpoint, no public feed, and no customer notification anywhere in this platform that '.
            'reads these records. Customers are NOT informed by this action. Use your real external communication '.
            'channel to notify anyone, and treat these rows as the internal record of what you intend to say.';
    }

    private function uriLooksLikePublicStatus(string $uri): bool
    {
        $normalised = trim($uri, '/');

        foreach (self::PUBLIC_STATUS_URI_PATTERNS as $pattern) {
            if ($normalised === $pattern) {
                return true;
            }

            if (str_ends_with($pattern, '/*') && str_starts_with($normalised, rtrim($pattern, '*'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * A route behind any auth/panel middleware is by definition not a
     * public status page. Checked by name prefix so this stays
     * correct for guard-specific variants (auth:platform_admin,
     * auth:web, Filament's own panel middleware, and so on).
     */
    private function isAuthenticated(RoutingRoute $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if (str_starts_with($middleware, 'auth') || str_contains($middleware, 'Authenticate')) {
                return true;
            }
        }

        return false;
    }
}
