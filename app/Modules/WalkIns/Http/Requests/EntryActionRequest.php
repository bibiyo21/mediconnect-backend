<?php

namespace App\Modules\WalkIns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A body-less action on an entry or queue (arrive, pause, open). The permission is decided with the record in hand. */
final class EntryActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
