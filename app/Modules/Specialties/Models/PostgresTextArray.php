<?php

namespace App\Modules\Specialties\Models;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a PostgreSQL TEXT[] column to a list of strings and back.
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
final class PostgresTextArray implements CastsAttributes
{
    /** @return list<string> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '{}' || $value === '') {
            return [];
        }

        $inner = substr((string) $value, 1, -1);
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,]+)/', $inner, $matches, PREG_SET_ORDER);

        $items = [];
        foreach ($matches as $m) {
            $items[] = isset($m[2]) ? $m[2] : stripcslashes($m[1] ?? '');
        }

        return $items;
    }

    /** @param list<string>|null $value */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $items = array_map(fn (string $s) => '"'.addcslashes($s, '"\\').'"', $value ?? []);

        return '{'.implode(',', $items).'}';
    }
}
