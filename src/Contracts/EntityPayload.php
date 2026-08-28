<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Contracts;

use Hei\AccountingConnector\Enums\EntityType;

/**
 * A provider-agnostic description of something to create in an accounting system.
 *
 * Implementations are the canonical DTOs in the Data namespace, plus RawPayload
 * for the escape hatch where a caller needs to send provider-native JSON that
 * the canonical shape does not model.
 */
interface EntityPayload
{
    /**
     * The entity type this payload describes.
     *
     * Connectors check this against the requested type so a BillData cannot be
     * posted as an expense by a mistyped argument.
     */
    public function entityType(): EntityType;

    /**
     * The host's own identifier for whatever this describes, when it has one.
     *
     * Used for two things: the entity-map key, so a second sync of the same
     * document finds the entity it already created, and the default idempotency
     * key. A payload with no local id still posts; it just cannot be deduplicated
     * by anything except an explicit idempotency key.
     */
    public function localId(): ?string;
}
