<?php

namespace App\Modules\Discovery\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /admin/search/synonyms and PATCH /admin/search/synonyms/{id}. */
final class SaveSynonymRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:search.manage` on the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            /** @var list<string> terms that should find each other, for example ["pedia", "pediatrics"] */
            'synonyms' => [$creating ? 'required' : 'sometimes', 'array', 'min:2', 'max:20'],
            'synonyms.*' => ['string', 'min:1', 'max:100', 'distinct:ignore_case'],
            /** Set for a one-way synonym: searching this term also finds the synonyms, not the reverse. */
            'root_term' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => [$creating ? 'prohibited' : 'sometimes', 'in:ACTIVE,INACTIVE'],
            /** Marks this zero-result query as handled. */
            'zero_result_id' => [$creating ? 'sometimes' : 'prohibited', 'uuid'],
        ];
    }
}
