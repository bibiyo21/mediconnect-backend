<?php

namespace App\Modules\Discovery\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /search/specialties: autocomplete. */
final class SuggestSpecialtiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }
}
