<?php

declare(strict_types=1);

use App\Domain\Sources\Exceptions\StoragePolicyViolation;
use App\Domain\Sources\Models\SourceItem;
use App\Enums\CredibilityTier;
use App\Enums\SourceLicense;
use App\Enums\StoragePolicy;

/*
|--------------------------------------------------------------------------
| The ODbL / Google boundary, enforced — conventions/09, ODBL-REVIEW §6
|--------------------------------------------------------------------------
|
| EdgeOnly data (Google & friends) must never be persisted into any
| world-model table. This is the CI check the conventions demand: it throws,
| it does not warn.
|
*/

it('refuses to persist edge-only source data', function () {
    expect(fn () => SourceItem::factory()->edgeOnly()->create())
        ->toThrow(StoragePolicyViolation::class);
});

it('persists open-data source items', function () {
    $item = SourceItem::factory()->create();

    expect($item->exists)->toBeTrue()
        ->and($item->storage_policy->isStorable())->toBeTrue();
});

it('persists evidence-only items into the evidence store', function () {
    $item = SourceItem::factory()->create([
        'source' => 'wikipedia',
        'license' => SourceLicense::CcBySa,
        'storage_policy' => StoragePolicy::EvidenceOnly,
        'credibility_tier' => CredibilityTier::Reference,
    ]);

    expect($item->exists)->toBeTrue()
        ->and($item->storage_policy->isGeoCorePersistable())->toBeFalse();
});
