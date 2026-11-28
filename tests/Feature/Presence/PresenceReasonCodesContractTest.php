<?php

use App\Modules\Presence\Enums\CheckOutReason;
use App\Shared\OpenApi\DocumentsErrorEnvelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
 * Every reason_code the Presence module can send must be in the ErrorEnvelope enum the OpenAPI contract publishes (the 5.6-2 MAJOR-1 lesson).
 * Scans the presence exceptions for the literal codes they send, and pins the check-out codes (increment 5.8-1).
 */
it('lists every presence reason_code the module can send in the ErrorEnvelope enum', function () {
    $allowed = (new ReflectionClassConstant(DocumentsErrorEnvelope::class, 'REASON_CODES'))->getValue();

    $sent = [];
    foreach (File::files(app_path('Modules/Presence/Exceptions')) as $file) {
        $source = File::get($file->getPathname());
        // `new self('...', 'open_session')` and `new self($current, '...', 'invalid_transition', ...)`: the reason is the literal argument after the message.
        preg_match_all("/new self\\([^;]*?,\\s*'([a-z_]+)'\\s*(?:,|\\))/", $source, $factories);
        foreach ($factories[1] as $code) {
            $sent[$code] = $file->getFilename();
        }
        // `public function reasonCode(): string { return 'change_in_progress'; }`
        preg_match_all("/function\\s+reasonCode\\(\\)[^{]*\\{\\s*return\\s+'([a-z_]+)'/", $source, $fixed);
        foreach ($fixed[1] as $code) {
            $sent[$code] = $file->getFilename();
        }
    }

    expect($sent)->not->toBeEmpty();
    // The three check-out blocks are sent by the factories of ActiveConsultationBlocksChange.
    foreach (['in_progress', 'interrupted', 'called'] as $code) {
        expect(array_key_exists($code, $sent))->toBeTrue("the scan must see the check-out code [{$code}]");
    }
    foreach (array_keys($sent) as $code) {
        expect(in_array($code, $allowed, true))->toBeTrue("reason_code [{$code}] is sent by the presence module but missing from DocumentsErrorEnvelope::REASON_CODES");
    }
    foreach (['in_progress', 'interrupted', 'called'] as $code) {
        expect(in_array($code, $allowed, true))->toBeTrue();
    }
});

it('keeps the CheckOutReason enum equal to the database CHECK on checkout_reason', function () {
    $definition = (string) DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'doctor_presence_sessions_values_check'")?->def;

    // Only the checkout_reason clause (the AND-separated part that names the column), so a value the DB allows but the enum lacks is seen (5.8 m6).
    $clause = collect(preg_split('/\s+AND\s+/', $definition))->first(fn (string $part) => str_contains($part, 'checkout_reason'));
    expect($clause)->not->toBeNull();

    preg_match_all("/'([A-Z_]+)'/", (string) $clause, $matches);
    $dbReasons = collect($matches[1])->unique()->sort()->values()->all();

    expect($dbReasons)->not->toBeEmpty()
        ->and(collect(CheckOutReason::cases())->map(fn (CheckOutReason $r) => $r->value)->sort()->values()->all())->toBe($dbReasons);
});
