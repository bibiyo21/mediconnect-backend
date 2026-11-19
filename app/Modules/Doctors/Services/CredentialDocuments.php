<?php

namespace App\Modules\Doctors\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Doctors\Enums\CredentialStatus;
use App\Modules\Doctors\Enums\CredentialType;
use App\Modules\Doctors\Enums\OnboardingStatus;
use App\Modules\Doctors\Exceptions\CredentialNotAvailable;
use App\Modules\Doctors\Jobs\ScanCredentialDocument;
use App\Modules\Doctors\Models\DoctorCredential;
use App\Modules\Doctors\Models\DoctorProfile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stores, reads and removes credential documents (increment 2.8, D-36).
 *
 * Files are encrypted with the application key before they leave the API, so
 * the object store never holds a readable copy whatever its own encryption
 * settings. Every new file is PENDING_SCAN until the scan job decides.
 */
final class CredentialDocuments
{
    /** Profile states in which a doctor may add documents. */
    private const UPLOAD_STATES = [OnboardingStatus::Registered, OnboardingStatus::DocumentsSubmitted, OnboardingStatus::Rejected];

    /** Profile states in which a doctor may remove an unreviewed document. */
    private const REMOVE_STATES = [OnboardingStatus::Registered, OnboardingStatus::Rejected];

    public function __construct(private readonly RecordsAudit $audit) {}

    public function store(DoctorProfile $profile, UploadedFile $file, CredentialType $type, ?string $expiresOn): DoctorCredential
    {
        $contents = (string) file_get_contents($file->getRealPath());
        $mime = self::sniff($contents);
        $key = 'credentials/'.$profile->id.'/'.Str::uuid7()->toString().'.bin';

        $credential = DB::transaction(function () use ($profile, $file, $type, $expiresOn, $contents, $mime, $key) {
            // Serialise uploads per doctor so the count limit holds under concurrency.
            /** @var DoctorProfile $locked */
            $locked = DoctorProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->onboarding_status, self::UPLOAD_STATES, true)) {
                throw CredentialNotAvailable::locked();
            }
            $max = (int) config('mediconnect.credentials.max_per_doctor');
            if (DoctorCredential::where('doctor_profile_id', $locked->id)->count() >= $max) {
                throw ValidationException::withMessages(['file' => ["You can keep up to {$max} documents. Remove one first."]]);
            }

            $this->disk()->put($key, Crypt::encryptString($contents));

            $credential = new DoctorCredential;
            $credential->forceFill([
                'doctor_profile_id' => $locked->id,
                'credential_type' => $type,
                'file_path' => $key,
                'original_filename' => self::displayName($file->getClientOriginalName()),
                'mime_type' => $mime,
                'size_bytes' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'expires_on' => $expiresOn,
            ])->save();
            $this->audit->record('doctor.credential_uploaded', 'doctor_credential', $credential->id, ['credential_type' => $type->value]);

            return $credential;
        });

        ScanCredentialDocument::dispatch($credential->id)->afterCommit();

        return $credential;
    }

    /** Decrypted file contents. */
    public function read(DoctorCredential $credential): string
    {
        return Crypt::decryptString((string) $this->disk()->get($credential->file_path));
    }

    public function remove(DoctorCredential $credential): void
    {
        DB::transaction(function () use ($credential) {
            /** @var DoctorProfile $profile */
            $profile = DoctorProfile::whereKey($credential->doctor_profile_id)->lockForUpdate()->firstOrFail();
            if (! in_array($profile->onboarding_status, self::REMOVE_STATES, true)) {
                throw CredentialNotAvailable::locked();
            }
            // A rejected document may be replaced; an accepted one is evidence for the decision.
            if ($credential->status === CredentialStatus::Accepted) {
                throw CredentialNotAvailable::reviewed();
            }
            $credential->delete();
            $this->audit->record('doctor.credential_removed', 'doctor_credential', $credential->id);
        });
        $this->deleteObject($credential);
    }

    /** Removes the stored object; the row (soft-deleted or quarantined) keeps the history. */
    public function deleteObject(DoctorCredential $credential): void
    {
        try {
            $this->disk()->delete($credential->file_path);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Content-sniffed MIME type; the request already refused anything outside the allow-list. */
    public static function sniff(string $contents): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if (! is_string($mime) || ! in_array($mime, (array) config('mediconnect.credentials.mime_types'), true)) {
            throw ValidationException::withMessages(['file' => ['Upload a PDF, JPEG or PNG file.']]);
        }

        return $mime;
    }

    /** A safe display name: no path, no control characters, at most 120 characters. */
    public static function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim($name);

        return mb_substr($name !== '' ? $name : 'document', 0, 120);
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('mediconnect.credentials.disk'));
    }
}
