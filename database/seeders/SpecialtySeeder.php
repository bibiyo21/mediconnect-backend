<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A common starting list of medical specialties (D-39). Every row is seeded
 * PENDING_REVIEW: a MEDICAL_ADMIN confirms or edits each one in the console
 * before launch. Idempotent: existing rows (matched by slug) are never changed,
 * so edits made in the console survive reseeding.
 */
final class SpecialtySeeder extends Seeder
{
    /** @var array<string, list<string>> name => synonyms */
    private const SPECIALTIES = [
        'Allergy and Immunology' => ['allergist', 'immunologist'],
        'Anesthesiology' => ['anesthesiologist'],
        'Cardiology' => ['cardiologist', 'heart doctor'],
        'Dentistry' => ['dentist', 'dental'],
        'Dermatology' => ['dermatologist', 'skin doctor', 'derma'],
        'Emergency Medicine' => ['ER doctor'],
        'Endocrinology' => ['endocrinologist', 'diabetes doctor'],
        'Family Medicine' => ['family doctor', 'general practitioner', 'GP'],
        'Gastroenterology' => ['gastroenterologist', 'GI doctor'],
        'General Surgery' => ['surgeon'],
        'Geriatric Medicine' => ['geriatrician'],
        'Hematology' => ['hematologist', 'blood doctor'],
        'Infectious Disease' => ['infectious disease specialist'],
        'Internal Medicine' => ['internist'],
        'Nephrology' => ['nephrologist', 'kidney doctor'],
        'Neurology' => ['neurologist'],
        'Obstetrics and Gynecology' => ['OB-GYN', 'OB', 'obstetrician', 'gynecologist'],
        'Oncology' => ['oncologist', 'cancer doctor'],
        'Ophthalmology' => ['ophthalmologist', 'eye doctor'],
        'Orthopedics' => ['orthopedic surgeon', 'ortho', 'bone doctor'],
        'Otorhinolaryngology' => ['ENT', 'ear nose and throat'],
        'Pediatrics' => ['pediatrician', 'pedia', 'child doctor'],
        'Physical Medicine and Rehabilitation' => ['physiatrist', 'rehab medicine'],
        'Psychiatry' => ['psychiatrist', 'mental health'],
        'Pulmonology' => ['pulmonologist', 'lung doctor'],
        'Radiology' => ['radiologist'],
        'Rheumatology' => ['rheumatologist'],
        'Urology' => ['urologist'],
    ];

    public function run(): void
    {
        foreach (self::SPECIALTIES as $name => $synonyms) {
            $slug = Str::slug($name);
            if (DB::table('specialties')->where('slug', $slug)->exists()) {
                continue;
            }
            $items = array_map(fn (string $s) => '"'.addcslashes($s, '"\\').'"', $synonyms);
            DB::table('specialties')->insert([
                'id' => (string) Str::uuid7(),
                'name' => $name,
                'slug' => $slug,
                'synonyms' => '{'.implode(',', $items).'}',
                'is_active' => true,
                'review_status' => 'PENDING_REVIEW',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
