<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\User\Measurement;
use DateTimeInterface;

/**
 * Convert Measurement entities into public response shapes.
 *
 * Response shape design
 * ---------------------
 * Measurements are inherently small, bounded data — most users will
 * have one or two sets, each with a handful of fields. So we
 * serialize the full set inline rather than offering a sparse view
 * or partial projections.
 *
 * Numeric values in `values` are normalised to floats. Postgres
 * JSONB stores them as numbers; PHP's json_decode picks int or
 * float depending on the literal. To make the API contract
 * predictable, we cast everything to float on the way out.
 */
final class MeasurementSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function publicShape(Measurement $measurement): array
    {
        return [
            'id' => $measurement->getId(),
            'category_id' => $measurement->getCategoryId(),
            'values' => $this->normaliseValues($measurement->getValues()),
            'notes' => $measurement->getNotes(),
            'updated_at' => $measurement->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param iterable<Measurement> $measurements
     * @return list<array<string, mixed>>
     */
    public function publicShapeMany(iterable $measurements): array
    {
        $result = [];
        foreach ($measurements as $m) {
            $result[] = $this->publicShape($m);
        }
        return $result;
    }

    /**
     * Force every value in the measurements map to be a float.
     * The DB returns ints for whole-number values (90 -> 90 not 90.0)
     * which would make the API contract inconsistent.
     *
     * @param array<string, mixed> $values
     * @return array<string, float>
     */
    private function normaliseValues(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (is_numeric($value)) {
                $out[$key] = (float) $value;
            }
            // Skip non-numeric values silently — the validator should
            // have rejected these on input, but defensive trim here
            // means a bad row in the DB doesn't blow up serialization.
        }
        return $out;
    }
}
