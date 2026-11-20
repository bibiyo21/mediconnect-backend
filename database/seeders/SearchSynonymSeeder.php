<?php

namespace Database\Seeders;

use App\Modules\Discovery\Models\SearchSynonym;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Starting synonyms for search (3.4, D-42): each specialty with its lay names
 * (from the specialty catalogue), plus a few plain Filipino phrases. Seeded as
 * a common list for a MEDICAL_ADMIN to review and extend; idempotent.
 */
final class SearchSynonymSeeder extends Seeder
{
    /** @var list<list<string>> */
    private const FILIPINO = [
        ['doktor ng bata', 'pediatrics', 'pediatrician'],
        ['doktor sa puso', 'cardiology', 'cardiologist'],
        ['doktor sa balat', 'dermatology', 'dermatologist'],
        ['doktor sa mata', 'ophthalmology', 'ophthalmologist'],
        ['dentista', 'dentistry', 'dentist'],
        ['doktor sa buto', 'orthopedics', 'orthopedic surgeon'],
    ];

    public function run(): void
    {
        if (SearchSynonym::exists()) {
            return;
        }
        $groups = [];
        foreach (DB::table('specialties')->where('is_active', true)->orderBy('name')->get(['name', 'synonyms']) as $row) {
            $terms = array_values(array_unique(array_map('mb_strtolower', [(string) $row->name, ...self::parse((string) $row->synonyms)])));
            if (count($terms) > 1) {
                $groups[] = $terms;
            }
        }
        foreach ([...$groups, ...self::FILIPINO] as $terms) {
            $s = new SearchSynonym;
            $s->forceFill(['synonyms' => array_slice($terms, 0, 20)])->save();
        }
    }

    /** @return list<string> */
    private static function parse(string $array): array
    {
        $inner = trim($array, '{}');
        if ($inner === '') {
            return [];
        }

        return array_map(fn (?string $v) => stripcslashes(trim((string) $v, '"')), str_getcsv($inner, ',', '"', '\\'));
    }
}
