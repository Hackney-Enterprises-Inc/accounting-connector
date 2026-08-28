<?php

declare(strict_types=1);

use Hei\AccountingConnector\Connectors\QuickBooks\QuickBooksConnector;
use Hei\AccountingConnector\Data\TrackingCategory;
use Hei\AccountingConnector\Data\TrackingOption;

/**
 * The doc-derived tracking response: two live categories, one archived, and one
 * archived option hiding inside a live category.
 *
 * @return array<string, mixed>
 */
function trackingPayload(): array
{
    return providerResponse('xero/tracking-categories');
}

it('reads tracking categories with their options', function () {
    $fake = fakeHttp();
    $fake->queue(200, trackingPayload());

    $categories = xeroWithStore($fake)->trackingCategories(connection());

    expect($categories)->toHaveCount(2)
        ->and($categories[0])->toBeInstanceOf(TrackingCategory::class)
        ->and($categories[0]->options[0])->toBeInstanceOf(TrackingOption::class);
});

it('drops archived categories, which Xero keeps returning forever', function () {
    $fake = fakeHttp();
    $fake->queue(200, trackingPayload());

    $names = array_map(
        fn (TrackingCategory $c): string => $c->name,
        xeroWithStore($fake)->trackingCategories(connection()),
    );

    expect($names)->not->toContain('Old Dimension');
});

it('drops archived options, because offering one produces a post Xero rejects', function () {
    $fake = fakeHttp();
    $fake->queue(200, trackingPayload());

    $region = xeroWithStore($fake)->trackingCategories(connection())[1];

    expect($region->name)->toBe('Region')
        ->and($region->options)->toHaveCount(2)
        ->and(array_map(fn (TrackingOption $o): string => $o->name, $region->options))
        ->not->toContain('Retired Region');
});

it('sorts categories and their options alphabetically for a dropdown', function () {
    $fake = fakeHttp();
    $fake->queue(200, trackingPayload());

    $categories = xeroWithStore($fake)->trackingCategories(connection());

    expect($categories[0]->name)->toBe('DBA')
        ->and($categories[1]->name)->toBe('Region')
        ->and($categories[1]->options[0]->name)->toBe('North')
        ->and($categories[1]->options[1]->name)->toBe('South');
});

it('builds the line-item reference for a chosen option', function () {
    $fake = fakeHttp();
    $fake->queue(200, trackingPayload());

    $region = xeroWithStore($fake)->trackingCategories(connection())[1];
    $ref = $region->ref('opt-north');

    expect($ref?->categoryId)->toBe('cat-region')
        ->and($ref?->optionId)->toBe('opt-north')
        ->and($ref?->categoryName)->toBe('Region')
        ->and($ref?->optionName)->toBe('North')
        ->and($region->ref('opt-nonexistent'))->toBeNull();
});

it('round-trips a tracking category through the stored array shape', function () {
    $original = new TrackingCategory('cat-1', 'Region', [new TrackingOption('opt-1', 'North')]);

    $restored = TrackingCategory::fromArray($original->toArray());

    expect($restored->id)->toBe('cat-1')
        ->and($restored->name)->toBe('Region')
        ->and($restored->options)->toHaveCount(1)
        ->and($restored->options[0]->name)->toBe('North');
});

it('returns nothing for QuickBooks rather than making a host branch on provider', function () {
    // QuickBooks has no tracking-category equivalent. An empty list lets a host
    // render one dropdown for both providers and have it simply come back empty.
    $fake = fakeHttp();

    $connector = new QuickBooksConnector(
        http: httpClientOver($fake),
        clientId: 'c',
        clientSecret: 's',
        redirectUri: 'https://app.test/cb',
    );

    expect($connector->trackingCategories(qboConnection()))->toBe([])
        ->and($fake->requests)->toBeEmpty();
});
