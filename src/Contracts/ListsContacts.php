<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Contracts;

use DateTimeInterface;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\Contact;
use Hei\AccountingConnector\Exceptions\ConnectionRevokedException;

/**
 * Listing every contact the provider holds, for a host that mirrors them.
 *
 * Separate from {@see AccountingConnector} for the same reason as
 * {@see ReadsBankTransactions}: it is optional, and a connector for a provider that
 * cannot list contacts should not have to implement a method that can only throw.
 * A host asks with `instanceof`.
 *
 * Every contact comes back, customers and archived ones included. The provider's
 * supplier flag rides along on each Contact, but it is not a filter: Xero sets
 * IsSupplier only from accounts payable bills, so a vendor only ever paid by card or
 * bank transfer is never flagged, and a supplier-only listing would miss exactly the
 * contacts a receipt is for. Archived contacts are included because a merge archives
 * the losing contact and names the winner in `mergedToContactId`.
 */
interface ListsContacts
{
    /**
     * Every contact, paged from the provider as the caller iterates.
     *
     * With $modifiedSince, only contacts changed after that instant (Xero's
     * If-Modified-Since). Iterating is what makes the calls: a host that stops early
     * spends no more requests. A failure part way through throws from the iteration,
     * after the contacts already yielded.
     *
     * A listing is not a snapshot: concurrent additions/removals can shift pages.
     * Hosts should upsert by id, keep the sync start time as the next cutoff only
     * after a successful walk, and periodically reconcile with a full listing.
     * Xero does not include changes solely to IsSupplier or IsCustomer in its
     * modified-since results; those flags require a full listing to refresh.
     *
     * @return iterable<int, Contact>
     *
     * @throws ConnectionRevokedException when the connection is dead
     */
    public function contacts(Connection $connection, ?DateTimeInterface $modifiedSince = null): iterable;
}
