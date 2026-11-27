<?php

use App\Shared\OpenApi\DocumentsErrorEnvelope;
use Illuminate\Support\Facades\File;

/*
 * Every reason_code the Consultations module can send must be in the ErrorEnvelope enum the OpenAPI contract publishes, so a generated
 * client never meets a value its type does not list. Scans the module source for the literal reason strings it throws.
 */
it('lists every consultation reason_code in the ErrorEnvelope enum', function () {
    $allowed = (new ReflectionClassConstant(DocumentsErrorEnvelope::class, 'REASON_CODES'))->getValue();
    $root = app_path('Modules/Consultations');

    $sent = [];
    foreach (File::allFiles($root) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = File::get($file->getPathname());
        // ConsultationRefused::notAvailable('on_break') and ::queueClosed('not_today').
        preg_match_all('/(?:notAvailable|queueClosed)\(\s*\'([a-z_]+)\'/', $source, $calls);
        foreach ($calls[1] as $code) {
            $sent[$code] = $file->getFilename();
        }
        // The match arms that name the presence reason in the lifecycle.
        preg_match_all('/=>\s*\'([a-z_]+)\'/', $source, $arms);
        if ($file->getFilename() === 'ConsultationLifecycle.php') {
            foreach ($arms[1] as $code) {
                $sent[$code] = $file->getFilename().' match arm';
            }
        }
        // A fixed reasonCode() (ConcurrentConsultationChange).
        preg_match_all('/function\s+reasonCode\(\)[^{]*\{\s*return\s+\'([a-z_]+)\'/', $source, $fixed);
        foreach ($fixed[1] as $code) {
            $sent[$code] = $file->getFilename().' reasonCode()';
        }
    }

    expect($sent)->not->toBeEmpty();
    foreach ($sent as $code => $where) {
        expect(in_array($code, $allowed, true))->toBeTrue("reason_code {$code} from {$where} is not in ErrorEnvelope REASON_CODES");
    }
});
