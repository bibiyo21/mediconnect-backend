<?php

namespace App\Modules\Discovery\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** PATCH /admin/search/synonyms/{id}: only the fields sent change. */
final class UpdateSynonymRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:search.manage` on the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** @var list<string> */
            'synonyms' => ['sometimes', 'array', 'min:2', 'max:20'],
            'synonyms.*' => ['string', 'min:1', 'max:100', 'distinct:ignore_case'],
            'root_term' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ];
    }
}
