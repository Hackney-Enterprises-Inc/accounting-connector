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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_entity_map');
    }
};
