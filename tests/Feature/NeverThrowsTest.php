<?php

declare(strict_types=1);

use Hei\AccountingConnector\Connectors\QuickBooks\QuickBooksConnector;
use Hei\AccountingConnector\Connectors\Xero\XeroConnector;
use Hei\AccountingConnector\Data\Attachment;
use Hei\AccountingConnector\Data\AttachmentResult;
use Hei\AccountingConnector\Enums\DisconnectOutcome;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Events\AttachmentUploaded;
use Hei\AccountingConnector\Testing\FakeHttpClient;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/*
 * The methods documented as never throwing must not throw because of a host's
 * listener or logger either: attach() runs after the entity exists, so a throw fails
 * the posting job and its retry posts twice (gotcha 6), and revoke() and
 * disconnectTenant() run before a host's local credential cleanup.
 */

function throwingListener(): EventDispatcherInterface
{
    return new class implements EventDispatcherInterface
    {
        public function dispatch(object $event): object
        {
            throw new RuntimeException('Attachment listener unavailable');
        }
    };
}

function recordingListener(): EventDispatcherInterface
{
    return new class implements EventDispatcherInterface
    {
        /** @var list<object> */
        public array $events = [];

        public function dispatch(object $event): object
        {
            $this->events[] = $event;

            return $event;
        }
    };
}

function throwingLogger(): LoggerInterface
{
    return new class extends AbstractLogger
    {
        public function log($level, Stringable|string $message, array $context = []): void
        {
            throw new RuntimeException('Log destination unavailable');
        }
    };
}

function recordingLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<array{level: mixed, message: string}> */
        public array $records = [];

        public function log($level, Stringable|string $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => (string) $message];
        }
    };
}

function xeroWith(FakeHttpClient $fake, ?EventDispatcherInterface $events = null, ?LoggerInterface $logger = null): XeroConnector
{
    return new XeroConnector(
        http: httpClientOver($fake, maxRetries: 0),
        clientId: 'client-id',
        clientSecret: 'client-secret',
        redirectUri: 'https://app.test/callback',
        events: $events,
        logger: $logger ?? new NullLogger,
    );
}

function pdf(int $bytes = 10): Attachment
{
    return new Attachment('receipt.pdf', str_repeat('x', $bytes), 'application/pdf');
}

it('returns an uploaded attachment even when the AttachmentUploaded listener throws', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['Attachments' => [['AttachmentID' => 'attachment-1']]]);

    $result = xeroWith($fake, throwingListener())->attach(EntityType::Bill, 'bill-1', pdf(), connection());

    expect($result)->toBeInstanceOf(AttachmentResult::class)
        ->and($result->uploaded)->toBeTrue()
        ->and($fake->requests)->toHaveCount(1);
});

it('returns a too-large result even when the listener throws', function () {
    $result = xeroWith(fakeHttp(), throwingListener())
        ->attach(EntityType::Bill, 'bill-1', pdf(XeroConnector::ATTACHMENT_LIMIT_BYTES + 1), connection());

    expect($result->uploaded)->toBeFalse();
});

it('returns a failed result even when the logger throws', function () {
    // Nothing queued: the upload fails, and the failure is logged.
    $result = xeroWith(fakeHttp(), logger: throwingLogger())->attach(EntityType::Bill, 'bill-1', pdf(), connection());

    expect($result->uploaded)->toBeFalse();
});

it('returns a failed result when the logger and the listener both throw', function () {
    $result = xeroWith(fakeHttp(), throwingListener(), throwingLogger())->attach(EntityType::Bill, 'bill-1', pdf(), connection());

    expect($result->uploaded)->toBeFalse();
});

it('logs a listener failure when the logger works', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['Attachments' => [['AttachmentID' => 'attachment-1']]]);
    $logger = recordingLogger();

    xeroWith($fake, throwingListener(), $logger)->attach(EntityType::Bill, 'bill-1', pdf(), connection());

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('error')
        ->and($logger->records[0]['message'])->toContain('attachment.uploaded');
});

it('still hands the event to a working listener (control)', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['Attachments' => [['AttachmentID' => 'attachment-1']]]);
    $events = recordingListener();

    xeroWith($fake, $events)->attach(EntityType::Bill, 'bill-1', pdf(), connection());

    expect($events->events)->toHaveCount(1)
        ->and($events->events[0])->toBeInstanceOf(AttachmentUploaded::class);
});

it('answers Unconfirmed from disconnectTenant when the transport and then the logger fail', function () {
    expect(xeroWith(fakeHttp(), logger: throwingLogger())->disconnectTenant(connection()))
        ->toBe(DisconnectOutcome::Unconfirmed);
});

it('answers false from Xero revoke when the transport and then the logger fail', function () {
    expect(xeroWith(fakeHttp(), logger: throwingLogger())->revoke(connection()))->toBeFalse();
});

it('answers false from QuickBooks revoke when the transport and then the logger fail', function () {
    $connector = new QuickBooksConnector(
        http: httpClientOver(fakeHttp(), maxRetries: 0),
        clientId: 'client-id',
        clientSecret: 'client-secret',
        redirectUri: 'https://app.test/callback',
        logger: throwingLogger(),
    );

    expect($connector->revoke(qboConnection()))->toBeFalse();
});
