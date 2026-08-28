<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per tenant per accounting provider.
 *
 * Published from hei/accounting-connector. This replaces the per-provider
 * column sprawl that otherwise accumulates on a host's tenant model: connecting a
 * third provider costs a row here rather than four more columns on `organizations`.
 *
 * `owner_id` is the host's own tenant identifier, as a string. It is deliberately
 * not a foreign key in this stub, because the package cannot know whether the host
 * calls its tenant an Organization or a Company. Add the constraint yourself:
 *
 *     $table->foreign('owner_id')->references('id')->on('organizations')->cascadeOnDelete();
 *
 * `provider` is NOT constrained to the package's Provider enum. Keep connections
 * for integrations the package has no connector for, such as Google Sheets, in the
 * same table and read them with your own service.
 *
 * Tokens are encrypted by DatabaseConnectionRepository using the application key
 * before they reach these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_connections', function (Blueprint $table) {
            $table->id();

            // The host's tenant. See the note above about the foreign key.
            $table->string('owner_id', 191);

            $table->string('provider', 32);

            // The provider's own identifier for the connected company: a Xero tenant
            // GUID, an Intuit realm id, or for a non-package provider whatever it uses.
            $table->string('tenant_id', 191)->nullable();

            // Ciphertext. Never readable from the database alone.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();

            $table->timestamp('expires_at')->nullable();

            // Intuit refresh tokens lapse after about 100 days unused, Xero after 60.
            // Storing this is what lets a host warn before a dormant connection dies.
            $table->timestamp('refresh_token_expires_at')->nullable();

            // Per-connection defaults: bank account, expense account, tax code.
            $table->json('settings')->nullable();

            // 'active' or 'revoked'. Revoked rows are kept rather than deleted so a
            // host can show "reconnect" against the tenant that lost the connection.
            $table->string('status', 16)->default('active');
            $table->string('revoked_reason')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // One connection per tenant per provider.
            $table->unique(['owner_id', 'provider'], 'accounting_connections_owner_provider_unique');

            // For "which tenants are connected to Xero and still working".
            $table->index(['provider', 'status'], 'accounting_connections_provider_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_connections');
    }
};
