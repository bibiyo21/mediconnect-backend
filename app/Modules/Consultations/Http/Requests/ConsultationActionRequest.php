<?php

namespace App\Modules\Consultations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A consultation start, end or walk-in complete takes no body. rules() is empty, so any fields sent are ignored. */
final class ConsultationActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
