<?php

declare(strict_types=1);

use Hei\AccountingConnector\Connectors\Xero\XeroConnector;
use Hei\AccountingConnector\Data\Attachment;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Events\AttachmentUploaded;
use Hei\AccountingConnector\Events\ConnectionRevoked;
use Hei\AccountingConnector\Events\EntityCreated;
use Hei\AccountingConnector\Events\EntityCreateFailed;
use Hei\AccountingConnector\Events\SyncEvent;
use Hei\AccountingConnector\Events\TokensRefreshed;
use Hei\AccountingConnector\Exceptions\ConnectionRevokedException;
use Hei\AccountingConnector\Testing\FakeHttpClient;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A PSR-14 dispatcher that just keeps what it is given.
 */
function recorder(): object
{
    return new class implements EventDispatcherInterface
    {
        /** @var array<int, object> */
        public array $events = [];

        public function dispatch(object $event): object
        {
            $this->events[] = $event;

            return $event;
        }

        /** @return array<int, SyncEvent> */
        public function ofType(string $class): array
        {
            return array_values(array_filter($this->events, fn (object $e): bool => $e instanceof $class));
        }
    };
}

function xeroWithEvents(FakeHttpClient $fake, object $events): XeroConnector
{
    return new XeroConnector(
        http: httpClientOver($fake),
        clientId: 'client-id',
        clientSecret: 'client-secret',
        redirectUri: 'https://app.test/callback',
        events: $events,
    );
}

it('announces a created entity with its external id', function () {
    $events = recorder();
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-1']]]);
    $fake->queue(200, ['Invoices' => [['InvoiceID' => 'invoice-1']]]);

    xeroWithEvents($fake, $events)->createEntity(EntityType::Bill, billFor(), connection(), 'sync-bill-doc-42');

    $created = $events->ofType(EntityCreated::class);

    expect($created)->toHaveCount(1)
        ->and($created[0]->externalId)->toBe('invoice-1')
        ->and($created[0]->localId)->toBe('doc-42')
        ->and($created[0]->idempotencyKey)->toBe('sync-bill-doc-42')
        ->and($created[0]->name())->toBe('entity.created');
});

it('keeps credentials out of the event context a host will persist', function () {
    $events = recorder();
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-1']]]);
    $fake->queue(200, ['Invoices' => [['InvoiceID' => 'invoice-1']]]);

    xeroWithEvents($fake, $events)->createEntity(EntityType::Bill, billFor(), connection());

    $context = $events->ofType(EntityCreated::class)[0]->context();

    expect(json_encode($context))->not->toContain('access-token')
        ->and(json_encode($context))->not->toContain('refresh-token')
        ->and($context['connection'])->toBe('org-99');
});

it('marks a validation failure as not worth retrying', function () {
    // Retrying a rejected payload achieves nothing; a bookkeeper has to fix it.
    $events = recorder();
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-1']]]);
    $fake->queue(400, ['Elements' => [['ValidationErrors' => [['Message' => 'Bad account']]]]]);

    try {
        xeroWithEvents($fake, $events)->createEntity(EntityType::Bill, billFor(), connection());
    } catch (Throwable) {
        // expected
    }

    $failed = $events->ofType(EntityCreateFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->retryable)->toBeFalse()
        ->and($failed[0]->reason)->toContain('Bad account');
});

it('marks a rate limit as worth retrying', function () {
    $events = recorder();
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-1']]]);
    $fake->queue(429, [], ['Retry-After' => '1']);

    $connector = new XeroConnector(
        http: httpClientOver($fake, maxRetries: 0),
        clientId: 'c',
        clientSecret: 's',
        redirectUri: 'https://app.test/callback',
        events: $events,
    );

    try {
        $connector->createEntity(EntityType::Bill, billFor(), connection());
    } catch (Throwable) {
        // expected
    }

    expect($events->ofType(EntityCreateFailed::class)[0]->retryable)->toBeTrue();
});

it('announces a create that succeeded but returned no id, as retryable', function () {
    $events = recorder();
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-1']]]);
    $fake->queue(200, ['Invoices' => []]);

    $id = xeroWithEvents($fake, $events)->createEntity(EntityType::Bill, billFor(), connection());

    expect($id)->toBeNull()
        ->and($events->ofType(EntityCreateFailed::class)[0]->retryable)->toBeTrue()
        ->and($events->ofType(EntityCreated::class))->toBeEmpty();
});

it('announces an attachment whether it uploaded or not', function () {
    $events = recorder();
    $fake = fakeHttp();
    $fake->queue(200, ['Attachments' => [['AttachmentID' => 'att-1']]]);

    xeroWithEvents($fake, $events)->attach(
        EntityType::Bill,
        'invoice-1',
        new Attachment('receipt.pdf', 'BYTES', 'application/pdf'),
        connection(),
    );

    $uploaded = $events->ofType(AttachmentUploaded::class);

    expect($uploaded)->toHaveCount(1)
        ->and($uploaded[0]->name())->toBe('attachment.uploaded')
        ->and($uploaded[0]->result->uploaded)->toBeTrue();

    $events2 = recorder();
    $fake2 = fakeHttp();
    $fake2->queue(400, ['Message' => 'rejected']);

    xeroWithEvents($fake2, $events2)->attach(
        EntityType::Bill,
        'invoice-1',
        new Attachment('receipt.pdf', 'BYTES', 'application/pdf'),
        connection(),
    );

    expect($events2->ofType(AttachmentUploaded::class)[0]->name())->toBe('attachment.skipped');
});

it('emits the reconnect signal when a refresh is refused', function () {
    // The host has to stop dispatching sync jobs for this connection and tell
    // somebody. Retrying achieves nothing.
    $events = recorder();
    $fake = fakeHttp();
    $fake->queue(400, ['error' => 'invalid_grant']);

    try {
        xeroWithEvents($fake, $events)->refresh(connection());
    } catch (ConnectionRevokedException) {
        // expected
    }

    $revoked = $events->ofType(ConnectionRevoked::class);

    expect($revoked)->toHaveCount(1)
        ->and($revoked[0]->name())->toBe('connection.revoked')
        ->and($revoked[0]->reason)->toContain('invalid_grant');
});

it('announces a successful refresh', function () {
    $events = recorder();
    $fake = fakeHttp();
    $fake->queue(200, ['access_token' => 'fresh', 'refresh_token' => 'rotated', 'expires_in' => 1800]);

    xeroWithEvents($fake, $events)->refresh(connection());

    expect($events->ofType(TokensRefreshed::class))->toHaveCount(1);
});
