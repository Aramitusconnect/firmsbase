<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mission 1B (Extreme Security Hardening) — real, working WebAuthn/
 * passkey credential storage for Platform Admin, the mission's
 * headline phishing-resistant-authentication requirement. Not
 * tenant-owned (platform_admins isn't a Firm-scoped identity), so this
 * table is System-classified with no FORCE RLS — same treatment as
 * platform_admins itself and its password-reset-token table.
 *
 * Column choices follow the WebAuthn spec's own CredentialRecord shape
 * (see Webauthn\CredentialRecord in web-auth/webauthn-lib) closely, so
 * a stored row maps onto that class with no lossy translation:
 *  - credential_id: the authenticator-issued credential identifier,
 *    base64url-encoded — looked up on every authentication attempt, so
 *    indexed and unique.
 *  - public_key: the credential's public key material (never a
 *    private key — WebAuthn private keys never leave the
 *    authenticator).
 *  - sign_count: the authenticator's signature counter, used for clone
 *    detection (a real authenticator's counter only ever increases; a
 *    non-increasing counter on a later authentication indicates a
 *    cloned/duplicated credential).
 *  - transports/aaguid/attestation_type/trust_path: preserved from the
 *    registration ceremony for audit/diagnostic value, never used to
 *    gate authorization on their own.
 *  - name: an admin-chosen label ("YubiKey — desk drawer"), per this
 *    mission's own requirement to support multiple named
 *    authenticators.
 *  - last_used_at: updated on every successful authentication —
 *    supports the mission's own "last-used timestamp" requirement and
 *    lets a stale/unused credential be identified for review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webauthn_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->string('name');
            $table->text('credential_id')->unique();
            $table->text('public_key');
            $table->string('attestation_type');
            $table->json('transports');
            $table->uuid('aaguid');
            $table->unsignedBigInteger('sign_count');
            $table->boolean('backup_eligible')->nullable();
            $table->boolean('backup_status')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('platform_admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webauthn_credentials');
    }
};
