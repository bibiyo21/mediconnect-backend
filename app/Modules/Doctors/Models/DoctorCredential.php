<?php

namespace App\Modules\Doctors\Models;

use App\Modules\Doctors\Enums\CredentialStatus;
use App\Modules\Doctors\Enums\CredentialType;
use App\Modules\Doctors\Enums\ScanStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A credential document (CLAUDE.md §5.2). Never publicly exposed; the file is
 * encrypted in the private documents disk and downloadable only when CLEAN.
 *
 * @property string $id
 * @property string $doctor_profile_id
 * @property CredentialType $credential_type
 * @property string $file_path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property ScanStatus $scan_status
 * @property string|null $scan_signature
 * @property CarbonImmutable|null $scanned_at
 * @property CredentialStatus $status
 * @property string|null $review_note
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $expires_on
 * @property CarbonImmutable $created_at
 * @property-read DoctorProfile $doctorProfile
 */
class DoctorCredential extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = ['*'];

    protected $hidden = ['file_path', 'scan_signature', 'sha256'];

    protected $attributes = ['scan_status' => 'PENDING_SCAN', 'status' => 'SUBMITTED'];

    protected function casts(): array
    {
        return [
            'credential_type' => CredentialType::class,
            'scan_status' => ScanStatus::class,
            'status' => CredentialStatus::class,
            'size_bytes' => 'integer',
            'scanned_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'expires_on' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<DoctorProfile, $this> */
    public function doctorProfile(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class);
    }

    public function downloadable(): bool
    {
        return $this->scan_status === ScanStatus::Clean;
    }
}
