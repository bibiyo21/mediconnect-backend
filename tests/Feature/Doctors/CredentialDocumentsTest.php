<?php

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Doctors\Enums\OnboardingStatus;
use App\Modules\Doctors\Jobs\ScanCredentialDocument;
use App\Modules\Doctors\Models\DoctorCredential;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Services\CredentialDocuments;
use App\Modules\Doctors\Services\DoctorOnboarding;
use App\Shared\Contracts\MalwareScanner;
use App\Shared\Malware\ScannerUnavailable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeMalwareScanner;

/*
 * Increment 2.8 security exit tests (phase-2-implementation-plan.md):
 * malicious upload rejected; licence documents never public; unscanned
 * files not downloadable. The scanner is faked here; ClamdScannerTest covers
 * the protocol and a live clamd was checked with the EICAR string.
 */

const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

beforeEach(function () {
    seed_roles();
    fake_messages();
    Storage::fake('documents');
    $this->scanner = new FakeMalwareScanner;
    app()->instance(MalwareScanner::class, $this->scanner);
});

function doctor_headers(): array
{
    $headers = signed_in(patient_account());
    app('auth')->forgetGuards(); // the test client caches the previous request's user
    test()->postJson('/api/v1/doctors', ['display_name' => 'Dr. Ana Santos', 'confirm_licensed' => true], $headers)->assertCreated();

    return $headers;
}

function upload(array $headers, string $contents = PDF, string $name = 'prc-id.pdf', string $type = 'PRC_ID'): TestResponse
{
    return test()->post('/api/v1/doctors/me/credentials', ['credential_type' => $type, 'file' => UploadedFile::fake()->createWithContent($name, $contents)], [...$headers, 'Accept' => 'application/json']);
}

function png(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
}

it('stores an upload encrypted in the private disk and marks it clean after the scan', function () {
    $headers = doctor_headers();

    $data = upload($headers)->assertCreated()->json('data');

    expect($data['scan_status'])->toBe('CLEAN')
        ->and($data['downloadable'])->toBeTrue()
        ->and($data['mime_type'])->toBe('application/pdf')
        ->and($data)->not->toHaveKeys(['file_path', 'sha256', 'scan_signature']);
    $credential = DoctorCredential::findOrFail($data['id']);
    $raw = Storage::disk('documents')->get($credential->file_path);
    expect($raw)->not->toContain('%PDF')
        ->and(app(CredentialDocuments::class)->read($credential))->toBe(PDF)
        ->and($credential->file_path)->not->toContain('prc-id')
        ->and($this->scanner->calls)->toBe(1);
});

it('accepts PNG images', function () {
    expect(upload(doctor_headers(), png(), 'id.png')->assertCreated()->json('data.mime_type'))->toBe('image/png');
});

it('rejects files whose content is not an allowed type, whatever the name', function (string $contents, string $name) {
    upload(doctor_headers(), $contents, $name)->assertStatus(422)->assertJsonPath('errors.file.0', 'Upload a PDF, JPEG or PNG file.');
    expect(DoctorCredential::count())->toBe(0);
})->with([
    'executable named .pdf' => ["MZ\x90\x00\x03\x00\x00\x00\x04\x00", 'licence.pdf'],
    'text named .png' => ['just some text', 'licence.png'],
    'pdf named .exe' => [PDF, 'licence.exe'],
    'html named .pdf' => ['<html><script>alert(1)</script></html>', 'licence.pdf'],
]);

it('rejects files over the size limit', function () {
    config(['mediconnect.credentials.max_kilobytes' => 1]);
    upload(doctor_headers(), PDF.str_repeat('0', 4096))->assertStatus(422)->assertJsonValidationErrors('file');
});

it('quarantines an infected upload, deletes the object and raises a security event', function () {
    $headers = doctor_headers();

    $data = upload($headers, PDF.FakeMalwareScanner::EICAR)->assertCreated()->json('data');

    $credential = DoctorCredential::findOrFail($data['id']);
    expect($data['scan_status'])->toBe('QUARANTINED')
        ->and($data['downloadable'])->toBeFalse()
        ->and(Storage::disk('documents')->exists($credential->file_path))->toBeFalse()
        ->and($credential->scan_signature)->toBe('Eicar-Test-Signature')
        ->and(DB::table('security_events')->where('event_type', 'MALWARE_DETECTED')->exists())->toBeTrue();
    $this->getJson("/api/v1/doctors/me/credentials/{$data['id']}/download-url", $headers)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

it('keeps an unscanned file undownloadable', function () {
    Queue::fake();
    $headers = doctor_headers();

    $data = upload($headers)->assertCreated()->json('data');

    expect($data['scan_status'])->toBe('PENDING_SCAN')->and($data['downloadable'])->toBeFalse();
    Queue::assertPushed(ScanCredentialDocument::class, fn ($job) => $job->credentialId === $data['id']);
    $this->getJson("/api/v1/doctors/me/credentials/{$data['id']}/download-url", $headers)->assertStatus(409);
});

it('fails closed when the scanner is down: retries, then SCAN_FAILED, never downloadable', function () {
    Queue::fake();
    $headers = doctor_headers();
    $id = upload($headers)->assertCreated()->json('data.id');
    $job = new ScanCredentialDocument($id);
    $down = new FakeMalwareScanner(down: true);

    expect(fn () => $job->handle($down, app(CredentialDocuments::class), app(RecordsAudit::class), app(RecordsSecurityEvents::class)))->toThrow(ScannerUnavailable::class);
    expect(DoctorCredential::findOrFail($id)->scan_status->value)->toBe('PENDING_SCAN');

    $job->failed(new ScannerUnavailable('gave up'));
    expect(DoctorCredential::findOrFail($id)->scan_status->value)->toBe('SCAN_FAILED');
    $this->getJson("/api/v1/doctors/me/credentials/{$id}/download-url", $headers)->assertStatus(409);

    // A late retry cannot flip a decided document.
    $job->handle($this->scanner, app(CredentialDocuments::class), app(RecordsAudit::class), app(RecordsSecurityEvents::class));
    expect(DoctorCredential::findOrFail($id)->scan_status->value)->toBe('SCAN_FAILED');
});

it('downloads a clean file through a short-lived signed link with safe headers', function () {
    $headers = doctor_headers();
    $id = upload($headers)->assertCreated()->json('data.id');

    $link = $this->getJson("/api/v1/doctors/me/credentials/{$id}/download-url", $headers)->assertOk()->json('data');

    $response = $this->get($link['url'])->assertOk();
    expect($response->getContent())->toBe(PDF)
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(DB::table('audit_logs')->where('action', 'doctor.credential_downloaded')->where('subject_id', $id)->exists())->toBeTrue();
});

it('refuses tampered, re-targeted and expired links', function () {
    $headers = doctor_headers();
    $id = upload($headers)->assertCreated()->json('data.id');
    $url = $this->getJson("/api/v1/doctors/me/credentials/{$id}/download-url", $headers)->json('data.url');

    $this->get($url.'x')->assertForbidden();
    $this->get(preg_replace('/owner=[^&]+/', 'owner=00000000-0000-0000-0000-000000000000', $url))->assertForbidden();
    $this->travel(301)->seconds();
    $this->get($url)->assertForbidden();
});

it('refuses a link issued before the file was quarantined', function () {
    $headers = doctor_headers();
    $id = upload($headers)->assertCreated()->json('data.id');
    $url = $this->getJson("/api/v1/doctors/me/credentials/{$id}/download-url", $headers)->json('data.url');

    DoctorCredential::whereKey($id)->update(['scan_status' => 'QUARANTINED', 'scan_signature' => 'Late-Signature']);

    $this->get($url)->assertNotFound();
});

it("never shows one doctor another doctor's documents", function () {
    $owner = doctor_headers();
    $id = upload($owner)->assertCreated()->json('data.id');
    $other = doctor_headers();

    $this->getJson('/api/v1/doctors/me/credentials', $other)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/doctors/me/credentials/{$id}/download-url", $other)->assertNotFound();
    $this->deleteJson("/api/v1/doctors/me/credentials/{$id}", [], $other)->assertNotFound();
    expect(DoctorCredential::whereKey($id)->exists())->toBeTrue();
});

it('needs a doctor profile and a signed-in user', function () {
    $this->getJson('/api/v1/doctors/me/credentials')->assertUnauthorized();
    $this->getJson('/api/v1/doctors/me/credentials', signed_in(patient_account()))->assertNotFound();
});

it('limits the number of documents per doctor', function () {
    config(['mediconnect.credentials.max_per_doctor' => 2]);
    $headers = doctor_headers();
    upload($headers)->assertCreated();
    upload($headers)->assertCreated();

    upload($headers)->assertStatus(422)->assertJsonValidationErrors('file');
});

it('lets a doctor remove an unreviewed document before submitting', function () {
    $headers = doctor_headers();
    $id = upload($headers)->assertCreated()->json('data.id');
    $path = DoctorCredential::findOrFail($id)->file_path;

    $this->deleteJson("/api/v1/doctors/me/credentials/{$id}", [], $headers)->assertOk();

    expect(DoctorCredential::whereKey($id)->exists())->toBeFalse()
        ->and(DoctorCredential::withTrashed()->whereKey($id)->exists())->toBeTrue()
        ->and(Storage::disk('documents')->exists($path))->toBeFalse();
});

it('submits for review only with a licence number and a clean PRC ID', function () {
    Queue::fake();
    $headers = doctor_headers();

    $this->postJson('/api/v1/doctors/me/submit-for-review', [], $headers)->assertStatus(422)->assertJsonValidationErrors(['prc_license_number', 'credentials']);

    $this->patchJson('/api/v1/doctors/me', ['prc_license_number' => '0123456'], $headers)->assertOk();
    $id = upload($headers)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/doctors/me/submit-for-review', [], $headers)->assertStatus(422)->assertJsonValidationErrors('credentials');

    DoctorCredential::whereKey($id)->update(['scan_status' => 'CLEAN', 'scanned_at' => now()]);
    $data = $this->postJson('/api/v1/doctors/me/submit-for-review', [], $headers)->assertOk()->json('data');

    expect($data['onboarding_status'])->toBe('DOCUMENTS_SUBMITTED')
        ->and(collect($data['verification_history'])->pluck('to_status')->all())->toBe(['REGISTERED', 'DOCUMENTS_SUBMITTED']);
    $this->postJson('/api/v1/doctors/me/submit-for-review', [], $headers)->assertStatus(409);
});

it('does not accept a quarantined PRC ID for submission', function () {
    $headers = doctor_headers();
    $this->patchJson('/api/v1/doctors/me', ['prc_license_number' => '0123456'], $headers)->assertOk();
    upload($headers, PDF.FakeMalwareScanner::EICAR)->assertCreated();

    $this->postJson('/api/v1/doctors/me/submit-for-review', [], $headers)->assertStatus(422)->assertJsonValidationErrors('credentials');
});

it('locks documents while the profile is under review', function () {
    $headers = doctor_headers();
    $this->patchJson('/api/v1/doctors/me', ['prc_license_number' => '0123456'], $headers)->assertOk();
    $id = upload($headers)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/doctors/me/submit-for-review', [], $headers)->assertOk();

    // After submitting, a doctor may add a document but not remove one.
    upload($headers, PDF, 'board.pdf', 'SPECIALTY_BOARD')->assertCreated();
    $this->deleteJson("/api/v1/doctors/me/credentials/{$id}", [], $headers)->assertStatus(409);

    $profile = DoctorProfile::firstOrFail();
    app(DoctorOnboarding::class)->transition($profile, OnboardingStatus::UnderReview, null);
    upload($headers)->assertStatus(409);
});
