<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Contracts;

use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\Contact;
use Hei\AccountingConnector\Exceptions\ConnectionRevokedException;

/**
 * Looking a contact up without creating one.
 *
 * {@see AccountingConnector::resolveContact()} finds or creates, which is right for
 * a post that has to land somewhere. A match that wants to set the contact on a
 * bank feed line the customer left blank has no business creating anything: if
 * the customer has no "Google Workspace" contact, the line keeps no contact and a
 * person decides. This is the read that makes that distinction possible.
 *
 * Optional, like the bank transaction interfaces: a host asks with `instanceof`.
 */
interface FindsContacts
{
    /**
     * The contact with exactly this name, or null when the provider has none.
     *
     * Exact, case-insensitive on Xero's side. A name the provider's filter syntax
     * cannot express (Xero's `where` has no escape for a double quote) answers null
     * rather than guessing.
     *
     * @throws ConnectionRevokedException when the connection is dead
     */
    public function findContactByName(Connection $connection, string $name): ?Contact;
}
