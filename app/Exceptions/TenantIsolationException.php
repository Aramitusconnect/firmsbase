<?php

namespace App\Exceptions;

/**
 * TenantIsolationException — thrown when application code attempts to
 * act on a model instance that does not belong to the currently active
 * TenantContext. Intended for defensive checks in services/controllers
 * that fetch a model by some non-scoped means (e.g. route-model binding
 * by uuid, which does not by itself guarantee firm ownership) before
 * performing any mutation or sensitive read — see
 * BelongsToTenant::assertBelongsToActiveTenant().
 *
 * This is an application-layer defense-in-depth signal, not the only
 * enforcement layer — see also the Postgres row-level security policies
 * prepared in the tenancy migration.
 */
class TenantIsolationException extends \RuntimeException
{
}
