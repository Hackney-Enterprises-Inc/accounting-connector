<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local-id to external-id correspondence for accounting connectors.
 *
 * Published from hei/accounting-connector. The package ships no migrations
 * of its own; this is a stub each application publishes and owns, so the table can
 * be renamed, extended or dropped without waiting for a package release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_entity_map', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 32);

            // The Xero tenant id or Intuit realm id. Part of every unique key and
            // every lookup, because two tenants both hold a local id of '1'.
            $table->string('tenant_id', 128);

            $table->string('entity_type', 32);

            // The host's own identifier. A ULID, an integer id, or for a contact
            // that only ever existed as text on a receipt, 'name:acme supply'.
            $table->string('local_id', 191);

            $table->string('external_id', 191);

            // The host's own owner of the connection that wrote this row, copied
            // from Connection::reference. Nullable, and written only: no lookup is
            // scoped by it, because a mapping belongs to the tenant rather than to
            // whoever posted it, and scoping by owner would re-create the same
            // entity for a second owner on the same tenant. It is here so a row can
            // be traced back to the tenant that wrote it, and so a host that later
            // needs per-owner reporting has the column already populated.
            $table->string('owner_id', 191)->nullable();

            $table->timestamps();

            // One external id per local entity per tenant. This is what makes
            // remember() an upsert rather than a duplicate insert.
            $table->unique(
                ['provider', 'tenant_id', 'entity_type', 'local_id'],
                'accounting_entity_map_local_unique',
            );

            // The reverse lookup, for a provider webhook that carries only its own id.
            $table->index(
                ['provider', 'tenant_id', 'entity_type', 'external_id'],
                'accounting_entity_map_external_index',
            );

            // "Everything this owner has posted to this provider", for auditing and
            // for the per-owner reporting above. Deliberately not unique: one owner
            // posts many entities of a type.
            $table->index(
                ['provider', 'owner_id', 'entity_type'],
                'accounting_entity_map_owner_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_entity_map');
    }
};
