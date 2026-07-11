<?php

declare(strict_types=1);

use App\Domain\Places\Data\Coordinates;

it('parses the hex EWKB PostGIS returns for a geography point', function () {
    // SELECT ST_GeogFromText('SRID=4326;POINT(18.02 59.31)')
    $coordinates = Coordinates::fromEwkbHex('0101000020E610000085EB51B81E05324048E17A14AEA74D40');

    expect($coordinates)->not->toBeNull()
        ->and($coordinates->lng)->toEqualWithDelta(18.02, 0.000001)
        ->and($coordinates->lat)->toEqualWithDelta(59.31, 0.000001);
});

it('round-trips through WKT with x, y order', function () {
    expect((new Coordinates(59.31, 18.02))->toWkt())->toBe('POINT(18.02000000 59.31000000)');
});

it('returns null for an absent or unreadable value', function (?string $value) {
    expect(Coordinates::fromEwkbHex($value))->toBeNull();
})->with([null, '', 'not-hex', '0101']);

it('rejects coordinates off the planet', function () {
    expect(fn () => new Coordinates(120.0, 18.0))->toThrow(InvalidArgumentException::class);
    expect(fn () => new Coordinates(59.0, 200.0))->toThrow(InvalidArgumentException::class);
});
