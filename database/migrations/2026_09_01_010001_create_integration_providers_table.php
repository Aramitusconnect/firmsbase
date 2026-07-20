<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * integration_providers — global, platform-wide reference catalog of
 * integration provider metadata (checkpoint-00-final-specification.md
 * §5 table #1; domain-model-and-rls-classification.md §1). Matches
 * `module_catalog`'s exact table-design pattern: a small, static,
 * seeded-only catalog, no firm dimension.
 *
 * WHY THIS TABLE HAS NO RLS AND NO FORCE RLS (deliberate, not an
 * oversight — do not "fix" this later without re-reading this note):
 *   - There is no `firm_id` column, and there never should be one.
 *     This is Global/platform-wide reference data (per the frozen
 *     classification: "shared platform reference, Global, no RLS"),
 *     structurally identical in shape to `module_catalog`, which is
 *     also unscoped and un-policied.
 *   - Rows carry no secret or credential material whatsoever — only
 *     presentation/documentation-only metadata (display name,
 *     category, auth method label, a *documentation-only* OAuth scope
 *     list, a documentation-only webhook event-type list). There is
 *     nothing here a cross-tenant read could leak.
 *   - This table is never consulted by App\Integrations\Core\
 *     ProviderRegistry to decide what code runs or what capabilities a
 *     provider has — that remains entirely source-code-defined
 *     (versioned PHP, per checkpoint-00-final-specification.md §8's
 *     mutability/executability split). This table is looked up
 *     *against* that code-defined registry, never the reverse, so
 *     there is no tenant-isolation concern to enforce here at all.
 *   - Rows are seeded via migration only (see up() below) and are not
 *     intended to be runtime-editable by application code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_providers', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique();
            $table->string('display_name');
            $table->string('category');
            $table->string('auth_method');
            $table->string('status')->default('active');
            $table->string('module_code')->nullable();
            $table->string('degradation_type_key')->nullable();
            $table->json('required_oauth_scopes_json')->nullable();
            $table->json('webhook_event_types_json')->nullable();

            $table->timestamps();
        });

        // Seed exactly the one provider actually registered in this
        // mission (App\Integrations\Enums\ProviderKey::Test, backing
        // the internal TestProvider stub). Deliberately NOT seeding
        // any real provider (google/microsoft/stripe/quickbooks/
        // lawpay/clio/plaid/zoom/dropbox, etc.) — none is registered
        // in ProviderRegistry in this mission, and pre-seeding catalog
        // rows for unauthorized real providers would be out of scope.
        $now = now();

        DB::table('integration_providers')->insert([
            'code' => 'test',
            'display_name' => 'Internal Test Provider (non-production)',
            'category' => 'internal',
            'auth_method' => 'oauth2',
            'status' => 'active',
            'module_code' => null,
            'degradation_type_key' => null,
            'required_oauth_scopes_json' => json_encode([]),
            'webhook_event_types_json' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_providers');
    }
};
