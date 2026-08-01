<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Validates and normalizes optional menu_items.type_details by business vertical.
 * Restaurant items keep existing columns; type_details is ignored for them.
 */
final class MenuItemTypeDetails
{
    /**
     * @param  array<string, mixed>|null  $input
     * @return array<string, mixed>|null
     */
    public static function sanitize(?string $businessType, ?array $input): ?array
    {
        $type = BusinessTypes::normalize($businessType);

        if ($type === BusinessTypes::RESTAURANT || $type === BusinessTypes::OTHER || $type === BusinessTypes::PHARMACY) {
            // Restaurant (and other/pharmacy in Phase 2) continue on core columns only.
            return null;
        }

        if ($input === null) {
            return null;
        }

        return match ($type) {
            BusinessTypes::BAKERY => self::sanitizeBakery($input),
            BusinessTypes::GROCERY => self::sanitizeGrocery($input),
            BusinessTypes::BUTCHER => self::sanitizeButcher($input),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function sanitizeBakery(array $input): array
    {
        $out = ['schema' => BusinessTypes::BAKERY];

        if (array_key_exists('flavour', $input)) {
            $out['flavour'] = self::nullableString($input['flavour'], 'type_details.flavour', 120);
        }
        if (array_key_exists('eggless', $input)) {
            $out['eggless'] = self::bool($input['eggless'], 'type_details.eggless');
        }
        if (array_key_exists('minimum_notice_hours', $input)) {
            $out['minimum_notice_hours'] = self::nullableInt($input['minimum_notice_hours'], 'type_details.minimum_notice_hours', 0, 24 * 30);
        }
        if (array_key_exists('custom_message_allowed', $input)) {
            $out['custom_message_allowed'] = self::bool($input['custom_message_allowed'], 'type_details.custom_message_allowed');
        }
        if (array_key_exists('serves_people', $input)) {
            $out['serves_people'] = self::nullableInt($input['serves_people'], 'type_details.serves_people', 1, 500);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function sanitizeGrocery(array $input): array
    {
        $out = ['schema' => BusinessTypes::GROCERY];

        if (array_key_exists('brand', $input)) {
            $out['brand'] = self::nullableString($input['brand'], 'type_details.brand', 120);
        }
        if (array_key_exists('barcode', $input)) {
            $out['barcode'] = self::nullableString($input['barcode'], 'type_details.barcode', 64);
        }
        if (array_key_exists('manufacturer', $input)) {
            $out['manufacturer'] = self::nullableString($input['manufacturer'], 'type_details.manufacturer', 120);
        }
        if (array_key_exists('package_size', $input)) {
            $out['package_size'] = self::nullableString($input['package_size'], 'type_details.package_size', 80);
        }
        if (array_key_exists('max_purchase_quantity', $input)) {
            $out['max_purchase_quantity'] = self::nullableInt($input['max_purchase_quantity'], 'type_details.max_purchase_quantity', 1, 9999);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function sanitizeButcher(array $input): array
    {
        $out = ['schema' => BusinessTypes::BUTCHER];

        if (array_key_exists('animal_type', $input)) {
            $out['animal_type'] = self::nullableString($input['animal_type'], 'type_details.animal_type', 80);
        }
        if (array_key_exists('cut_type', $input)) {
            $out['cut_type'] = self::nullableString($input['cut_type'], 'type_details.cut_type', 80);
        }
        if (array_key_exists('storage', $input)) {
            $storage = is_string($input['storage']) ? strtolower(trim($input['storage'])) : null;
            if ($storage !== null && $storage !== '' && ! in_array($storage, ['fresh', 'frozen'], true)) {
                throw ValidationException::withMessages([
                    'type_details.storage' => ['Storage must be fresh or frozen.'],
                ]);
            }
            $out['storage'] = $storage === '' ? null : $storage;
        }
        if (array_key_exists('bone_in', $input)) {
            $out['bone_in'] = self::nullableBool($input['bone_in'], 'type_details.bone_in');
        }
        if (array_key_exists('skin_on', $input)) {
            $out['skin_on'] = self::nullableBool($input['skin_on'], 'type_details.skin_on');
        }
        if (array_key_exists('fixed_weight_grams', $input)) {
            $out['fixed_weight_grams'] = self::nullableInt($input['fixed_weight_grams'], 'type_details.fixed_weight_grams', 1, 100000);
        }
        if (array_key_exists('fixed_weight_variants', $input)) {
            $out['fixed_weight_variants'] = self::sanitizeFixedWeightVariants($input['fixed_weight_variants']);
        }

        return $out;
    }

    /**
     * @param  mixed  $value
     * @return list<array{name: string, weight_grams: int}>
     */
    private static function sanitizeFixedWeightVariants(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (! is_array($value)) {
            throw ValidationException::withMessages([
                'type_details.fixed_weight_variants' => ['Fixed-weight variants must be a list.'],
            ]);
        }

        $rows = [];
        foreach ($value as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    "type_details.fixed_weight_variants.{$index}" => ['Each fixed-weight variant must be an object.'],
                ]);
            }
            $name = self::nullableString($row['name'] ?? null, "type_details.fixed_weight_variants.{$index}.name", 120);
            $grams = self::nullableInt($row['weight_grams'] ?? null, "type_details.fixed_weight_variants.{$index}.weight_grams", 1, 100000);
            if ($name === null || $grams === null) {
                throw ValidationException::withMessages([
                    "type_details.fixed_weight_variants.{$index}" => ['Each fixed-weight variant needs a name and weight_grams.'],
                ]);
            }
            $rows[] = ['name' => $name, 'weight_grams' => $grams];
        }

        return $rows;
    }

    private static function nullableString(mixed $value, string $key, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) && ! is_numeric($value)) {
            throw ValidationException::withMessages([$key => ['Must be a string.']]);
        }
        $string = trim((string) $value);
        if (mb_strlen($string) > $max) {
            throw ValidationException::withMessages([$key => ["May not be greater than {$max} characters."]]);
        }

        return $string === '' ? null : $string;
    }

    private static function nullableInt(mixed $value, string $key, int $min, int $max): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (int) $value != $value) {
            throw ValidationException::withMessages([$key => ['Must be an integer.']]);
        }
        $int = (int) $value;
        if ($int < $min || $int > $max) {
            throw ValidationException::withMessages([$key => ["Must be between {$min} and {$max}."]]);
        }

        return $int;
    }

    private static function bool(mixed $value, string $key): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        throw ValidationException::withMessages([$key => ['Must be a boolean.']]);
    }

    private static function nullableBool(mixed $value, string $key): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::bool($value, $key);
    }

    /**
     * Public-safe projection (same payload today; reserved for future redaction).
     *
     * @param  array<string, mixed>|null  $details
     * @return array<string, mixed>|null
     */
    public static function forPublic(?array $details): ?array
    {
        return $details;
    }
}
