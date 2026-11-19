<?php

namespace App\Modules\Doctors\Http\Resources;

use App\Modules\Doctors\Models\DoctorCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A credential document as its owner sees it. No storage path, hash or scan
 * signature leaves the API.
 *
 * @mixin DoctorCredential
 */
final class CredentialResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DoctorCredential $c */
        $c = $this->resource;

        return [
            'id' => $c->id,
            'credential_type' => $c->credential_type->value,
            'original_filename' => $c->original_filename,
            /** @var 'application/pdf'|'image/jpeg'|'image/png' */
            'mime_type' => $c->mime_type,
            'size_bytes' => $c->size_bytes,
            /** Only CLEAN documents can be downloaded or reviewed. */
            'scan_status' => $c->scan_status->value,
            'status' => $c->status->value,
            'review_note' => $c->review_note,
            'expires_on' => $c->expires_on?->toDateString(),
            'downloadable' => $c->downloadable(),
            'uploaded_at' => $c->created_at->toIso8601ZuluString(),
        ];
    }
}
