<?php

/*
 * Search (architecture.md §12, Phase 3). Typesense holds a rebuildable copy of
 * public directory data; PostgreSQL is the source of truth.
 *
 * Collections are reached through aliases (`{prefix}_{name}`) that point at a
 * versioned collection, so a full rebuild can swap without downtime.
 */
return [
    'typesense' => [
        'host' => env('TYPESENSE_HOST', 'typesense'),
        'port' => env('TYPESENSE_PORT', '8108'),
        'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
        'api_key' => env('TYPESENSE_API_KEY'),
        'timeout_seconds' => (float) env('TYPESENSE_TIMEOUT_SECONDS', 2),
    ],

    // Tests use their own prefix so they never touch the development index.
    'prefix' => env('SEARCH_PREFIX', 'mc'),

    // Synonyms live in one set shared by the collections that list it (Typesense 30).
    'synonym_set' => 'medical',

    // Search API (3.3, architecture.md §12.5-12.6)
    'cache_ttl_seconds' => (int) env('SEARCH_CACHE_TTL_SECONDS', 300),
    // After a failure, skip the engine (PostgreSQL fallback) for this long.
    'breaker_open_seconds' => (int) env('SEARCH_BREAKER_OPEN_SECONDS', 30),

    /*
     * Collection schemas, without the name. Bump `version` when a schema changes:
     * search:rebuild then builds the new version beside the old and swaps the alias.
     * Only public directory data; never licence numbers, documents, contacts or
     * anything about patients (§12.2, §32.5).
     */
    'collections' => [
        'doctors' => [
            'version' => 2, // v2 (3.3): subspecialty_ids for the subspecialty filter
            'schema' => [
                'fields' => [
                    ['name' => 'doctor_id', 'type' => 'string', 'facet' => true],
                    ['name' => 'display_name', 'type' => 'string'],
                    ['name' => 'professional_title', 'type' => 'string', 'optional' => true],
                    ['name' => 'specialties', 'type' => 'string[]', 'facet' => true],
                    ['name' => 'specialty_ids', 'type' => 'string[]', 'facet' => true],
                    ['name' => 'subspecialties', 'type' => 'string[]', 'facet' => true, 'optional' => true],
                    ['name' => 'subspecialty_ids', 'type' => 'string[]', 'facet' => true, 'optional' => true],
                    ['name' => 'services', 'type' => 'string[]', 'optional' => true],
                    ['name' => 'keywords', 'type' => 'string[]', 'optional' => true],
                    ['name' => 'languages', 'type' => 'string[]', 'facet' => true],
                    ['name' => 'gender', 'type' => 'string', 'facet' => true, 'optional' => true],
                    ['name' => 'years_experience', 'type' => 'int32', 'optional' => true],
                    ['name' => 'clinic_id', 'type' => 'string', 'facet' => true, 'optional' => true],
                    ['name' => 'clinic_location_id', 'type' => 'string', 'facet' => true, 'optional' => true],
                    ['name' => 'clinic_name', 'type' => 'string', 'optional' => true],
                    ['name' => 'location_name', 'type' => 'string', 'optional' => true],
                    ['name' => 'hospital_name', 'type' => 'string', 'optional' => true],
                    ['name' => 'city', 'type' => 'string', 'facet' => true, 'optional' => true],
                    ['name' => 'province', 'type' => 'string', 'facet' => true, 'optional' => true],
                    ['name' => 'location', 'type' => 'geopoint', 'optional' => true],
                    ['name' => 'consultation_modes', 'type' => 'string[]', 'facet' => true],
                    ['name' => 'fee_min', 'type' => 'int64', 'optional' => true],
                    ['name' => 'fee_max', 'type' => 'int64', 'optional' => true],
                    ['name' => 'is_verified', 'type' => 'bool', 'facet' => true],
                    ['name' => 'profile_completeness', 'type' => 'int32'],
                    ['name' => 'rating', 'type' => 'float', 'optional' => true],
                    ['name' => 'review_count', 'type' => 'int32'],
                    ['name' => 'rank_score', 'type' => 'float'],
                    ['name' => 'updated_at', 'type' => 'int64'],
                ],
                'default_sorting_field' => 'rank_score',
                'synonym_sets' => ['medical'],
            ],
        ],
        'clinics' => [
            'version' => 1,
            'schema' => [
                'fields' => [
                    ['name' => 'clinic_id', 'type' => 'string', 'facet' => true],
                    ['name' => 'name', 'type' => 'string'],
                    ['name' => 'organization_name', 'type' => 'string', 'optional' => true],
                    ['name' => 'location_name', 'type' => 'string'],
                    ['name' => 'barangay', 'type' => 'string', 'optional' => true],
                    ['name' => 'city', 'type' => 'string', 'facet' => true],
                    ['name' => 'province', 'type' => 'string', 'facet' => true, 'optional' => true],
                    ['name' => 'hospital_name', 'type' => 'string', 'optional' => true],
                    ['name' => 'location', 'type' => 'geopoint', 'optional' => true],
                    ['name' => 'services', 'type' => 'string[]', 'optional' => true],
                    ['name' => 'doctor_count', 'type' => 'int32'],
                    ['name' => 'updated_at', 'type' => 'int64'],
                ],
                'default_sorting_field' => 'doctor_count',
                'synonym_sets' => ['medical'],
            ],
        ],
        'specialties' => [
            'version' => 1,
            'schema' => [
                'fields' => [
                    ['name' => 'specialty_id', 'type' => 'string', 'facet' => true],
                    ['name' => 'name', 'type' => 'string'],
                    ['name' => 'synonyms', 'type' => 'string[]', 'optional' => true],
                    ['name' => 'kind', 'type' => 'string', 'facet' => true],
                    ['name' => 'parent_name', 'type' => 'string', 'optional' => true],
                    ['name' => 'doctor_count', 'type' => 'int32'],
                ],
                'default_sorting_field' => 'doctor_count',
                'synonym_sets' => ['medical'],
            ],
        ],
    ],
];
