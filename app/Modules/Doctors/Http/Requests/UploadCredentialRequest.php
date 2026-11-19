<?php

namespace App\Modules\Doctors\Http\Requests;

use App\Modules\Doctors\Enums\CredentialType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /doctors/me/credentials (multipart/form-data). */
final class UploadCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller resolves the caller's own doctor profile (404 otherwise)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'credential_type' => ['required', Rule::enum(CredentialType::class)],
            /** PDF, JPEG or PNG, checked by content as well as extension. */
            'file' => ['required', 'file', 'extensions:pdf,jpg,jpeg,png', 'mimetypes:'.implode(',', (array) config('mediconnect.credentials.mime_types')), 'max:'.(int) config('mediconnect.credentials.max_kilobytes')],
            /** Expiry printed on the document, if any (for example a PRC ID). */
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after:today'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.extensions' => 'Upload a PDF, JPEG or PNG file.',
            'file.mimetypes' => 'Upload a PDF, JPEG or PNG file.',
            'file.max' => 'The file is too large. The limit is '.intdiv((int) config('mediconnect.credentials.max_kilobytes'), 1024).' MB.',
            'expires_on.after' => 'This document has already expired.',
        ];
    }
}
