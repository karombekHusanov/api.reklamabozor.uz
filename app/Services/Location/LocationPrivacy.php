<?php

namespace App\Services\Location;

/**
 * Public surfaces never get exact coordinates: an agency or a request is shown
 * as a circle (config `location.public_radius_m`) whose centre is the true
 * point shifted by a deterministic pseudo-random offset. The offset stays
 * inside 70% of the radius, so the true point is always within the circle.
 *
 * Deterministic, so repeated requests can't be averaged back to the point;
 * keyed by the entity AND its coordinates, so after a move the offset changes
 * and knowing an old exact point reveals nothing about the new one. The HMAC
 * secret (app key) keeps the offset unpredictable from the outside.
 */
final class LocationPrivacy
{
    private const METERS_PER_DEGREE_LAT = 111_320.0;

    public static function radius(): int
    {
        return (int) config('location.public_radius_m', 1000);
    }

    /**
     * The public circle for a point, or null when there is no point.
     *
     * @return array{lat: float, lng: float, radius_m: int}|null
     */
    public static function approximate(float|string|null $lat, float|string|null $lng, string $entityKey): ?array
    {
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;
        $radius = self::radius();

        $hash = hash_hmac(
            'sha256',
            $entityKey.'|'.number_format($lat, 6, '.', '').'|'.number_format($lng, 6, '.', ''),
            (string) config('app.key'),
        );
        $u1 = hexdec(substr($hash, 0, 8)) / 0xFFFFFFFF;
        $u2 = hexdec(substr($hash, 8, 8)) / 0xFFFFFFFF;

        // Uniform over a disc of 0.7·r (sqrt keeps it from clustering at the centre).
        $distance = $radius * 0.7 * sqrt($u1);
        $angle = 2 * M_PI * $u2;

        $dLat = ($distance * cos($angle)) / self::METERS_PER_DEGREE_LAT;
        $cosLat = max(cos(deg2rad($lat)), 0.01);
        $dLng = ($distance * sin($angle)) / (self::METERS_PER_DEGREE_LAT * $cosLat);

        return [
            'lat' => round($lat + $dLat, 5),
            'lng' => round($lng + $dLng, 5),
            'radius_m' => $radius,
        ];
    }

    /**
     * The public part of an address: district + city ("Yangi hayot tumani,
     * Toshkent"), never the street, building or postcode. Labels come from the
     * map picker's reverse geocoding — "street, …, district, city, postcode,
     * country" — or are typed by hand. A single hand-typed part is kept only
     * when it carries no digits (a city / area name, not "Amir Temur 1").
     */
    public static function publicArea(?string $label): ?string
    {
        $parts = array_values(array_filter(
            array_map('trim', explode(',', (string) $label)),
            fn (string $part): bool => $part !== ''
                && ! preg_match('/^\d[\d\s-]*$/u', $part)   // postcode / house number
                && ! preg_match('/^(o[ʻ\'‘`]?zbekiston|uzbekistan|узбекистан)$/iu', $part),
        ));

        if ($parts === []) {
            return null;
        }

        $city = $parts[count($parts) - 1];
        if (count($parts) === 1) {
            return preg_match('/\d/u', $city) ? null : $city;
        }

        // The district is the last part naming one (tuman / район / district).
        $district = null;
        foreach (array_slice($parts, 0, -1) as $part) {
            if (preg_match('/tuman|район|district/iu', $part)) {
                $district = $part;
            }
        }

        return $district !== null ? $district.', '.$city : $city;
    }

    /**
     * Resource fields for a location: exact (`location_radius_m` null) for
     * someone allowed to see it, otherwise the public circle — with the
     * free-text label withheld, or (`$publicArea`, for agencies) cut down to
     * its district + city.
     *
     * @return array{lat: float|string|null, lng: float|string|null, location_radius_m: int|null, location_label: string|null}
     */
    public static function fields(
        float|string|null $lat,
        float|string|null $lng,
        ?string $label,
        string $entityKey,
        bool $exact,
        bool $publicArea = false,
    ): array {
        if ($exact) {
            return ['lat' => $lat, 'lng' => $lng, 'location_radius_m' => null, 'location_label' => $label];
        }

        $circle = self::approximate($lat, $lng, $entityKey);

        return [
            'lat' => $circle['lat'] ?? null,
            'lng' => $circle['lng'] ?? null,
            'location_radius_m' => $circle['radius_m'] ?? null,
            'location_label' => $publicArea ? self::publicArea($label) : null,
        ];
    }
}
