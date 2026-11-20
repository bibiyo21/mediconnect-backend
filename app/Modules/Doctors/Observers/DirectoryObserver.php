<?php

namespace App\Modules\Doctors\Observers;

use App\Modules\Doctors\Events\DoctorDirectoryChanged;
use App\Shared\Services\Outbox;
use Illuminate\Database\Eloquent\Model;

/**
 * Records DoctorDirectoryChanged whenever a model that feeds the doctor's
 * public directory or search documents is written (profile, specialties,
 * services). Mass updates bypass model events and record it explicitly.
 */
final class DirectoryObserver
{
    public function __construct(private readonly Outbox $outbox) {}

    public function saved(Model $model): void
    {
        $this->record($model);
    }

    public function deleted(Model $model): void
    {
        $this->record($model);
    }

    private function record(Model $model): void
    {
        $doctorId = $model->getAttribute('doctor_profile_id') ?? $model->getKey();
        if (is_string($doctorId) && $doctorId !== '') {
            $this->outbox->record(new DoctorDirectoryChanged($doctorId));
        }
    }
}
