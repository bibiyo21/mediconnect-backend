<?php

namespace App\Modules\Specialties\Observers;

use App\Modules\Specialties\Events\SpecialtyCatalogChanged;
use App\Shared\Services\Outbox;
use Illuminate\Database\Eloquent\Model;

/** Records SpecialtyCatalogChanged for a specialty or subspecialty write. */
final class CatalogObserver
{
    public function __construct(private readonly Outbox $outbox) {}

    public function saved(Model $model): void
    {
        $id = $model->getAttribute('specialty_id') ?? $model->getKey();
        if (is_string($id)) {
            $this->outbox->record(new SpecialtyCatalogChanged($id));
        }
    }
}
