<?php

/*
 * Market defaults (CLAUDE.md §1.2). Change here, not in code.
 */
return [
    // Local dates (birth dates, "today") are judged in the market's timezone.
    'timezone' => env('MEDICONNECT_TIMEZONE', 'Asia/Manila'),

    // D-37: account holders must be adults; minors are dependents of a guardian.
    'minimum_account_age' => (int) env('MEDICONNECT_MINIMUM_ACCOUNT_AGE', 18),

    // Dependents (CLAUDE.md §4.3). The attestation text a guardian accepts is a
    // draft until legal review (D-23); clients must send the version they showed.
    // D-38: days between a deletion request and anonymisation. Signing in cancels the request.
    'account_deletion_grace_days' => (int) env('MEDICONNECT_ACCOUNT_DELETION_GRACE_DAYS', 30),

    'dependents' => [
        'max_per_guardian' => (int) env('MEDICONNECT_MAX_DEPENDENTS', 10),
        'guardian_attestation_version' => env('GUARDIAN_ATTESTATION_VERSION', 'draft-2026-11'),
    ],

    // Doctor credential documents (increment 2.8, D-36, D-07).
    'credentials' => [
        'disk' => env('DOCUMENTS_DISK', 'documents'),
        'max_kilobytes' => (int) env('CREDENTIAL_MAX_KILOBYTES', 10240),
        'max_per_doctor' => (int) env('CREDENTIAL_MAX_PER_DOCTOR', 10),
        // Content-sniffed types only; the extension must agree.
        'mime_types' => ['application/pdf', 'image/jpeg', 'image/png'],
        'download_ttl_seconds' => (int) env('CREDENTIAL_DOWNLOAD_TTL_SECONDS', 300),
    ],

    // ClamAV daemon (D-07). Scans fail closed: no verdict means not downloadable.
    'malware_scanner' => [
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout_seconds' => (float) env('CLAMAV_TIMEOUT_SECONDS', 30),
    ],
];
