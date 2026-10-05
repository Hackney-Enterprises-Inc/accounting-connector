<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Contracts;

use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\ManualJournal;
use Hei\AccountingConnector\Exceptions\ConnectionRevokedException;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;
use Hei\AccountingConnector\Exceptions\ServerException;

/**
 * Finding manual journals by a marker the host wrote into their narration.
 *
 * The recovery half of an uncertain journal create. Xero remembers an
 * Idempotency-Key for minutes; past that, re-sending the create could post the
 * journal twice, and not re-sending it could leave it missing. The only safe move is
 * to look: a host that writes a unique marker (an operation id) into every journal's
 * narration can ask whether a journal carrying it exists, and settle on the answer
 * instead of asking a person.
 *
 * Optional, like {@see FindsContacts}: a host asks with `instanceof`. QuickBooks has
 * no equivalent yet.
 */
interface FindsManualJournals
{
    /**
     * Every manual journal in the connection's tenant whose narration carries $marker.
     *
     * The answer is precise and complete, or it throws:
     *
     *  - Precise: a journal is returned only when its narration contains the marker
     *    exactly (case-sensitive) as a whole token, that is not touching another
     *    letter, digit, `_` or `-` on either side. The provider's own filter only
     *    narrows the read; every row it returns is checked again here.
     *  - Complete: an empty list means the provider was read to the end and holds no
     *    such journal in this tenant. A read whose end cannot be proven (counts that
     *    do not add up, a row seen twice, a page cap reached) throws ServerException
     *    rather than answering "none".
     *
     * Every status comes back, drafts, voided and deleted ones included where the
     * provider lists them; the host decides what each means. More than one journal is
     * returned as found: a marker meant to be unique that matches two journals is the
     * host's to refuse, never the connector's to pick between.
     *
     * Only the connection's tenant is searched, and each journal carries that tenant.
     *
     * @param  string  $marker  8 to 100 characters of letters, digits, `_` and `-`,
     *                          starting with a letter or digit (a UUID qualifies).
     * @return array<int, ManualJournal>
     *
     * @throws InvalidPayloadException when the marker is not one this lookup can ask for
     * @throws ServerException when the read cannot be proven complete
     * @throws ConnectionRevokedException when the connection is dead
     */
    public function findManualJournalsByMarker(Connection $connection, string $marker): array;
}
