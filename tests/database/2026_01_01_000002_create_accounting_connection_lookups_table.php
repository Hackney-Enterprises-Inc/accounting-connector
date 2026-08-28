<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cached reference data per connection: chart of accounts, tax codes, tracking.
 *
 * Published from hei/accounting-connector. Separate from
 * `accounting_connections` on purpose, for two reasons that are easy to get wrong:
 *
 *  1. Every sync job reads the connection. A chart of accounts can run to a hundred
 *     kilobytes of JSON, and no job should drag that just to get an access token.
 *  2. One row per lookup key means each refresh is an independent upsert. Holding
 *     all three in a single JSON column would make every refresh a read-modify-write,
 *     so two concurrent refreshes would silently clobber one another.
 *
 * This data is disposable. Truncating the table costs one round of provider calls,
 * nothing more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_connection_lookups', function (Blueprint $table) {
            $table->id();

            // Matches accounting_connections.owner_id / provider rather than the
            // surrogate id, so a reconnect that replaces the connection row does not
            // orphan the cached lists.
            $table->string('owner_id', 191);
            $table->string('provider', 32);

            // 'chart_of_accounts', 'tax_codes', 'tracking_categories'.
            $table->string('lookup_key', 64);

            $table->json('payload');

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['owner_id', 'provider', 'lookup_key'],
                'accounting_connection_lookups_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_connection_lookups');
    }
};
