<?php

declare(strict_types=1);

namespace App\Domain\Places\Data;

use InvalidArgumentException;

/**
 * A WGS-84 point. The shared geography value object: Places owns geography, and
 * `Data/` is a module's public surface (conventions/01), so Trips and Context
 * hold a Coordinates rather than each re-parsing PostGIS output.
 *
 * PostGIS hands a `geography(Point, 4326)` column back to PDO as hex EWKB
 * (`0101000020E6100000...`). There is no first-party Laravel cast for it, so the
 * parsing lives here, once, and the models cast through it.
 */
final readonly class Coordinates
{
    public function __construct(
        public float $lat,
        public float $lng,
    ) {
        if ($lat < -90.0 || $lat > 90.0) {
            throw new InvalidArgumentException("Latitude {$lat} is out of range.");
        }

        if ($lng < -180.0 || $lng > 180.0) {
            throw new InvalidArgumentException("Longitude {$lng} is out of range.");
        }
    }

    /** `POINT(lng lat)` — note the order: PostGIS is x, y. */
    public function toWkt(): string
    {
        return sprintf('POINT(%.8F %.8F)', $this->lng, $this->lat);
    }

    /** @return array{lat: float, lng: float} */
    public function toArray(): array
    {
        return ['lat' => $this->lat, 'lng' => $this->lng];
    }

    /**
     * Great-circle distance in metres.
     *
     * In PHP rather than PostGIS on purpose: the callers that need it (the home-zone
     * test, the E46 drift test) are deciding whether to do work at all, and asking
     * the database how far the user has walked before deciding whether to query the
     * database is a round-trip to save nothing.
     */
    public function distanceTo(self $other): float
    {
        $earthRadiusMeters = 6_371_008.8;

        $lat1 = deg2rad($this->lat);
        $lat2 = deg2rad($other->lat);
        $deltaLat = $lat2 - $lat1;
        $deltaLng = deg2rad($other->lng - $this->lng);

        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;

        return 2 * $earthRadiusMeters * asin(min(1.0, sqrt($a)));
    }

    /**
     * Parse the hex EWKB PostGIS returns for a geography point.
     *
     * Layout: 1 byte endianness · 4 bytes type (0x20000000 = has SRID) ·
     * [4 bytes SRID] · 8 bytes X (lng) · 8 bytes Y (lat).
     */
    public static function fromEwkbHex(?string $hex): ?self
    {
        if ($hex === null || $hex === '' || strlen($hex) % 2 !== 0 || ! ctype_xdigit($hex)) {
            return null;
        }

        $binary = hex2bin($hex);

        if ($binary === false || strlen($binary) < 21) {
            return null;
        }

        $littleEndian = ord($binary[0]) === 1;
        $unpack = fn (string $format, string $chunk): float|int => (float) (unpack($format, $chunk)[1] ?? 0);

        $type = (int) unpack($littleEndian ? 'V' : 'N', substr($binary, 1, 4))[1];
        $offset = 5;

        if (($type & 0x20000000) !== 0) {
            $offset += 4;   // skip the SRID
        }

        if (strlen($binary) < $offset + 16) {
            return null;
        }

        $doubleFormat = $littleEndian ? 'e' : 'E';

        $lng = $unpack($doubleFormat, substr($binary, $offset, 8));
        $lat = $unpack($doubleFormat, substr($binary, $offset + 8, 8));

        return new self((float) $lat, (float) $lng);
    }
}
