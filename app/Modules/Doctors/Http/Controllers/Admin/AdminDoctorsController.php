<?php

namespace App\Modules\Doctors\Http\Controllers\Admin;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Doctors\Enums\CredentialStatus;
use App\Modules\Doctors\Enums\OnboardingStatus;
use App\Modules\Doctors\Exceptions\CredentialNotAvailable;
use App\Modules\Doctors\Http\Requests\ConfirmSpecialtiesRequest;
use App\Modules\Doctors\Http\Requests\DoctorDecisionRequest;
use App\Modules\Doctors\Http\Requests\ListDoctorsForReviewRequest;
use App\Modules\Doctors\Http\Requests\RejectDoctorRequest;
use App\Modules\Doctors\Http\Requests\ReviewCredentialRequest;
use App\Modules\Doctors\Http\Resources\AdminDoctorResource;
use App\Modules\Doctors\Http\Resources\AdminDoctorSummaryResource;
use App\Modules\Doctors\Http\Resources\CredentialResource;
use App\Modules\Doctors\Models\DoctorCredential;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Services\DoctorReview;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Doctor verification for reviewers (increment 2.9; api-specification.md
 * §2.15). Reviewers never decide on their own doctor profile.
 */
final class AdminDoctorsController
{
    public function __construct(private readonly DoctorReview $review, private readonly RecordsAudit $audit) {}

    public function index(ListDoctorsForReviewRequest $request): JsonResponse
    {
        $status = $request->validated('filter.onboarding_status');
        $search = $request->validated('filter.search');
        $submittedAt = DB::table('doctor_verification_events')->selectRaw('max(occurred_at)')
            ->whereColumn('doctor_profile_id', 'doctor_profiles.id')->where('to_status', OnboardingStatus::DocumentsSubmitted->value);

        $page = DoctorProfile::query()
            ->select('doctor_profiles.*')->selectSub($submittedAt, 'submitted_at')->withCount('credentials')
            ->when($status !== null, fn (Builder $q) => $q->where('onboarding_status', $status), fn (Builder $q) => $q->whereIn('onboarding_status', [OnboardingStatus::DocumentsSubmitted->value, OnboardingStatus::UnderReview->value]))
            ->when($search !== null, fn (Builder $q) => $q->where('display_name', 'ilike', addcslashes((string) $search, '%_\\').'%'))
            ->orderByRaw('submitted_at asc nulls last')->orderBy('created_at')
            ->paginate((int) $request->validated('per_page', 25));

        return ApiResponse::success(AdminDoctorSummaryResource::collection($page->items()), meta: [
            'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]);
    }

    public function show(string $doctorId): JsonResponse
    {
        $profile = $this->find($doctorId);
        $this->audit->record('doctor.application_viewed', 'doctor_profile', $profile->id, accessEvent: true);

        return $this->detail($profile);
    }

    public function startReview(Request $request, string $doctorId): JsonResponse
    {
        return $this->detail($this->review->startReview($this->decidable($request, $doctorId), $this->reviewer($request)));
    }

    public function reviewCredential(ReviewCredentialRequest $request, string $doctorId, string $credentialId): JsonResponse
    {
        $profile = $this->decidable($request, $doctorId);
        $credential = DoctorCredential::where('doctor_profile_id', $profile->id)->whereKey($credentialId)->firstOrFail();
        $credential = $this->review->decideCredential($profile, $credential, CredentialStatus::from((string) $request->validated('decision')), $request->validated('note'), $this->reviewer($request));

        return ApiResponse::success(new CredentialResource($credential));
    }

    public function credentialDownloadUrl(Request $request, string $doctorId, string $credentialId): JsonResponse
    {
        $profile = $this->decidable($request, $doctorId);
        $credential = DoctorCredential::where('doctor_profile_id', $profile->id)->whereKey($credentialId)->firstOrFail();
        if (! $credential->downloadable()) {
            throw CredentialNotAvailable::notScanned();
        }
        $expiresAt = now()->addSeconds((int) config('mediconnect.credentials.download_ttl_seconds'))->toImmutable();
        $url = URL::temporarySignedRoute('doctor-credentials.file', $expiresAt, ['credentialId' => $credential->id, 'owner' => $profile->user_id, 'viewer' => $this->reviewer($request)->id]);
        $this->audit->record('doctor.credential_link_issued', 'doctor_credential', $credential->id, accessEvent: true);

        return ApiResponse::success(['url' => $url, 'expires_at' => $expiresAt->toIso8601ZuluString()]);
    }

    public function confirmSpecialties(ConfirmSpecialtiesRequest $request, string $doctorId): JsonResponse
    {
        $profile = $this->decidable($request, $doctorId);
        /** @var list<string> $ids */
        $ids = array_values($request->validated('specialty_ids'));
        $this->review->confirmSpecialties($profile, $ids, $this->reviewer($request));

        return $this->detail($profile);
    }

    public function verify(DoctorDecisionRequest $request, string $doctorId): JsonResponse
    {
        return $this->detail($this->review->verify($this->decidable($request, $doctorId), $this->reviewer($request), (string) $request->validated('reason')));
    }

    public function reject(RejectDoctorRequest $request, string $doctorId): JsonResponse
    {
        return $this->detail($this->review->reject($this->decidable($request, $doctorId), $this->reviewer($request), (string) $request->validated('reason'), (bool) $request->validated('more_documents', false)));
    }

    public function activate(DoctorDecisionRequest $request, string $doctorId): JsonResponse
    {
        return $this->detail($this->review->activate($this->decidable($request, $doctorId), $this->reviewer($request), $request->validated('reason')));
    }

    private function detail(DoctorProfile $profile): JsonResponse
    {
        return ApiResponse::success(new AdminDoctorResource($profile->fresh(['specialties.specialty', 'specialties.subspecialty', 'credentials']) ?? $profile));
    }

    private function find(string $doctorId): DoctorProfile
    {
        return DoctorProfile::with(['specialties.specialty', 'specialties.subspecialty', 'credentials'])->findOrFail($doctorId);
    }

    /** The profile, unless it is the reviewer's own (403: nobody verifies themselves). */
    private function decidable(Request $request, string $doctorId): DoctorProfile
    {
        $profile = $this->find($doctorId);
        abort_if($profile->user_id === $this->reviewer($request)->id, 403, 'You cannot review your own doctor profile.');

        return $profile;
    }

    private function reviewer(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
