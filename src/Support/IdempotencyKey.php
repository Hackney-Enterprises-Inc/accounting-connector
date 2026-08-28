<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Support;

use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\Provider;

/**
 * Stable keys that stop a retry becoming a duplicate.
 *
 * This is the whole reason a create can be retried safely. Without a key, a 5xx or
 * a dropped connection after the provider committed the entity but before we
 * recorded its id leaves us unable to tell "it did not post" from "it posted and we
 * lost the receipt", and the retry double-charges a customer's ledger.
 *
 * The two providers cap the key at very different lengths, which is the trap here.
 * Xero's Idempotency-Key header allows 128 characters. Intuit's requestid query
 * parameter allows 50. A key that is unique in its first 128 characters but not in
 * its first 50 is unique in Xero and collides in QuickBooks, and a collision on
 * Intuit's side does not error: it silently returns the earlier response. So the
 * truncation happens here, per provider, and hashes rather than cuts.
 */
final class IdempotencyKey
{
    public const XERO_MAX_LENGTH = 128;

    public const QUICKBOOKS_MAX_LENGTH = 50;

    /**
     * Build a key for one local entity's first successful post.
     *
     * Deliberately deterministic: the same document retried tomorrow produces the
     * same key. A deliberate re-post, such as a user-triggered resync that is meant
     * to create a second entity, must pass null instead.
     */
    public static function for(EntityType $type, string $localId, ?string $suffix = null): string
    {
        return implode('-', array_filter(['sync', $type->value, $localId, $suffix]));
    }

    /**
     * Fit a key inside the provider's limit without losing its uniqueness.
     *
     * A key already short enough is passed through unchanged, so existing keys stay
     * recognisable in a provider's audit log. A long one is replaced by a prefixed
     * hash, which is still deterministic for the same input.
     */
    public static function truncate(string $key, Provider $provider): string
    {
        $limit = match ($provider) {
            Provider::Xero => self::XERO_MAX_LENGTH,
            Provider::QuickBooksOnline => self::QUICKBOOKS_MAX_LENGTH,
        };

        if (strlen($key) <= $limit) {
            return $key;
        }

        // Hash rather than cut: two keys differing only past the limit would
        // otherwise truncate to the same value, and Intuit answers a repeated
        // requestid by silently replaying the first response rather than erroring,
        // so the collision would look like a successful post that never happened.
        //
        // A readable prefix is kept so the key is still recognisable in a provider's
        // audit log. Both limits leave room for one: 95 characters for Xero, 17 for
        // QuickBooks, after the 33 the hash and its separator take.
        $hash = substr(hash('sha256', $key), 0, 32);

        return substr($key, 0, $limit - 33).'-'.$hash;
    }
}
