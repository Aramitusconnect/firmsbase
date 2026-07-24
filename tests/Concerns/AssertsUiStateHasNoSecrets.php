<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;

/**
 * AssertsUiStateHasNoSecrets — Checkpoint 10 (frozen-design-post-
 * security-review.md §9). The load-bearing structural-test control for
 * this checkpoint's secret-safety discipline: given a rendered
 * Livewire/Filament component (`Livewire::test()`'s `Testable`
 * instance), asserts a marker string is absent from BOTH:
 *
 *   1. The fully rendered HTML output (`Testable::html()`), and
 *   2. The decoded `wire:snapshot` JSON payload embedded in that same
 *      HTML on the component's root element — the client-visible
 *      serialized-state payload, which a naive `getStateUsing()`/
 *      `formatStateUsing()` closure reading `$record->column` directly
 *      can leak into even when the rendered visible text never shows
 *      it (frozen design §9's whole reason this dual-channel check is
 *      required, not merely an HTML `assertDontSee()`).
 *
 * Per frozen design §9: "a mandatory structural test suite... asserting
 * a planted marker value... is absent from both rendered HTML and the
 * decoded wire:snapshot payload, WITH A PROVEN NEGATIVE CONTROL (a
 * throwaway component that deliberately violates the rule, confirming
 * the assertion fails red before trusting it)." That negative control
 * is `assertSecretMarkerAssertionActuallyFailsRedOnALeak()` below,
 * which drives this trait's own `assertUiStateHasNoSecretMarker()`
 * against `LeakySecretProbeComponent` (a real, genuinely-registered
 * Livewire component defined in this same file, which deliberately
 * renders its `$marker` public property verbatim) and asserts that
 * call itself throws a PHPUnit assertion failure — proving the
 * assertion mechanism is capable of catching a real violation, not
 * merely that it currently finds nothing because nothing currently
 * leaks.
 */
trait AssertsUiStateHasNoSecrets
{
    /**
     * The main assertion: $marker must appear in NEITHER the rendered
     * HTML NOR the decoded wire:snapshot JSON payload of $test's most
     * recently rendered state.
     */
    protected function assertUiStateHasNoSecretMarker(Testable $test, string $marker, string $context = ''): void
    {
        $suffix = $context === '' ? '' : " ({$context})";

        $html = $test->html();

        self::assertStringNotContainsString(
            $marker,
            $html,
            "Secret marker leaked into rendered HTML{$suffix}."
        );

        $snapshotJson = $this->extractDecodedWireSnapshotJson($html);

        self::assertStringNotContainsString(
            $marker,
            $snapshotJson,
            "Secret marker leaked into the decoded wire:snapshot payload{$suffix}."
        );

        // Belt-and-braces: Livewire's own in-memory ComponentState
        // (accessible via the `snapshot` magic property) is the exact
        // PHP-side structure the wire:snapshot HTML attribute is
        // json_encode()'d from — checked independently in case a future
        // Livewire version changes how/whether the attribute is
        // embedded in html(), so this assertion does not silently stop
        // covering the payload Livewire actually ships to the browser.
        $rawSnapshotDump = json_encode($test->snapshot, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';

        self::assertStringNotContainsString(
            $marker,
            $rawSnapshotDump,
            "Secret marker leaked into Livewire's in-memory component snapshot state{$suffix}."
        );
    }

    /**
     * Extracts and decodes the `wire:snapshot="..."` HTML-attribute
     * payload embedded on a rendered Livewire component's root element
     * (see Livewire\Features\SupportTesting\ComponentState::getHtml()'s
     * own `wire:snapshot="..."` stripping logic, which confirms this is
     * exactly where/how the payload is embedded) and returns it as a
     * re-encoded JSON string suitable for a substring search. Returns
     * an empty string (never throws) if no such attribute is present,
     * so this helper is safe to call against any HTML fragment.
     */
    protected function extractDecodedWireSnapshotJson(string $html): string
    {
        if (! preg_match('/wire:snapshot="(.*?)"/s', $html, $matches)) {
            return '';
        }

        $decoded = htmlspecialchars_decode($matches[1], ENT_QUOTES);

        $data = json_decode($decoded, true);

        // If it fails to decode as JSON for any reason, fall back to the
        // raw decoded attribute text itself rather than silently
        // returning an empty string — a marker leaking into malformed
        // JSON must still be caught.
        return $data === null ? $decoded : (json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: $decoded);
    }

    /**
     * THE REQUIRED NEGATIVE CONTROL (frozen design §9). Proves
     * assertUiStateHasNoSecretMarker() itself is capable of failing red
     * on a genuine violation: renders a real Livewire component that
     * deliberately places $marker directly into its own rendered
     * output/public-property state, then asserts that running the main
     * assertion against it throws a PHPUnit assertion failure. If this
     * method itself does not throw when the probe fails to leak (a bug
     * in the probe) or does not detect the probe's leak (a bug in the
     * main assertion), IT throws — so a caller can simply call this
     * once and treat "no exception" as "the detection mechanism is
     * proven live."
     */
    protected function assertSecretMarkerAssertionActuallyFailsRedOnALeak(string $marker): void
    {
        $leakyTest = Livewire::test(LeakySecretProbeComponent::class, ['marker' => $marker]);

        // Sanity precondition: the probe must actually have leaked the
        // marker into its own HTML, or this negative control would
        // prove nothing (a false "the assertion caught it" reading
        // caused by the probe never leaking in the first place).
        if (! str_contains($leakyTest->html(), $marker)) {
            throw new AssertionFailedError(
                'Negative-control probe component (LeakySecretProbeComponent) did not actually render the marker '.
                'into its own HTML — the negative control itself is broken and proves nothing.'
            );
        }

        $caught = false;

        try {
            $this->assertUiStateHasNoSecretMarker($leakyTest, $marker, 'negative-control probe');
        } catch (AssertionFailedError) {
            $caught = true;
        }

        self::assertTrue(
            $caught,
            'Negative control failed: assertUiStateHasNoSecretMarker() did NOT fail red against a component that '.
            'deliberately leaks the marker into its own rendered HTML. The secret-safety assertion mechanism '.
            'cannot be trusted until this passes.'
        );
    }
}

/**
 * LeakySecretProbeComponent — a real, throwaway Livewire component that
 * exists ONLY to power AssertsUiStateHasNoSecrets's required negative
 * control. Deliberately, intentionally leaks its own `$marker` public
 * property verbatim into rendered HTML (and therefore also into its own
 * wire:snapshot payload, since it is an ordinary public Livewire
 * property) — the exact shape of violation the real production code
 * (frozen design §9) must never commit. Never used, registered, or
 * reachable from any production route/panel — Livewire::test() can
 * instantiate it directly by class name with no route/discovery
 * required.
 */
class LeakySecretProbeComponent extends Component
{
    public string $marker = '';

    public function render(): string
    {
        return '<div>Deliberately leaking the marker for the negative control: {{ $marker }}</div>';
    }
}
