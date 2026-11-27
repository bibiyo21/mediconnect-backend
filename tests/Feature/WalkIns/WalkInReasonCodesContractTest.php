<?php

use App\Shared\OpenApi\DocumentsErrorEnvelope;
use Illuminate\Support\Facades\File;

/*
 * Every reason_code the WalkIns module can send must be in the ErrorEnvelope enum that the OpenAPI contract publishes, so a generated
 * client never meets a value its type does not list. Scans the module source for the literal reason strings it throws.
 */
it('lists every walk-in reason_code the module can send in the ErrorEnvelope enum', function () {
    $allowed = (new ReflectionClassConstant(DocumentsErrorEnvelope::class, 'REASON_CODES'))->getValue();
    $root = app_path('Modules/WalkIns');
    $exceptions = collect(File::files($root.'/Exceptions'))->map(fn ($f) => $f->getFilenameWithoutExtension())->all();

    $sent = [];
    foreach (File::allFiles($root) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = File::get($file->getPathname());
        // `new DoctorNotAvailable('no_doctor')`, `new RegistrationRefused('doctor_checked_out', ...)` and similar.
        preg_match_all('/new\s+(\w+)\(\s*\'([a-z_]+)\'/', $source, $thrown, PREG_SET_ORDER);
        foreach ($thrown as [, $class, $code]) {
            if (in_array($class, $exceptions, true)) {
                $sent[$code] = $file->getFilename().' new '.$class;
            }
        }
        // `new DoctorNotAvailable(match ($x) { Status::OnBreak => 'on_break', default => 'unavailable' })`: every arm value.
        preg_match_all('/new\s+(\w+)\(\s*match\b[^{]*\{(.*?)\}\s*\)/s', $source, $matches, PREG_SET_ORDER);
        foreach ($matches as [, $class, $arms]) {
            if (! in_array($class, $exceptions, true)) {
                continue;
            }
            preg_match_all('/=>\s*\'([a-z_]+)\'/', $arms, $armCodes);
            foreach ($armCodes[1] as $code) {
                $sent[$code] = $file->getFilename().' match arm of new '.$class;
            }
        }
        // `public function reasonCode(): string { return 'invalid_transition'; }`
        preg_match_all('/function\s+reasonCode\(\)[^{]*\{\s*return\s+\'([a-z_]+)\'/', $source, $fixed);
        foreach ($fixed[1] as $code) {
            $sent[$code] = $file->getFilename().' reasonCode()';
        }
        // `$cond ? 'previous_day' : 'other_queue'` as a reason argument.
        preg_match_all('/\?\s*\'([a-z_]+)\'\s*:\s*\'([a-z_]+)\'/', $source, $ternary, PREG_SET_ORDER);
        foreach ($ternary as [, $a, $b]) {
            if (str_contains($source, 'DoctorAlreadyCalling(')) {
                $sent[$a] = $file->getFilename().' ternary';
                $sent[$b] = $file->getFilename().' ternary';
            }
        }
        // Protected-window reasons (`'reason' => 'appointment'`) that the call path turns into AppointmentWindowProtected.
        if ($file->getFilename() === 'QueueEstimates.php') {
            preg_match_all('/\'reason\'\s*=>\s*\'([a-z_]+)\'/', $source, $windows);
            foreach ($windows[1] as $code) {
                $sent[$code] = $file->getFilename().' window';
            }
        }
    }

    // Guard against an empty scan passing vacuously: the module throws at least these today.
    expect(array_keys($sent))->toContain('no_doctor', 'not_today', 'doctor_checked_out', 'live_entries_per_patient', 'invalid_transition', 'on_break', 'checked_out', 'not_checked_in', 'unavailable');

    $missing = array_diff_key($sent, array_flip($allowed));
    expect($missing)->toBe([], 'reason_code values the module sends but the ErrorEnvelope enum does not list');
});
