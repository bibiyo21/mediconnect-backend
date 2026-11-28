<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Http\Requests\UpdatePushPreferencesRequest;
use App\Modules\Notifications\Http\Resources\PushPreferencesResource;
use App\Modules\Notifications\Services\PushPreferences;
use App\Shared\Http\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET and PUT /notifications/preferences: the signed-in user's own PUSH switches only. */
final class NotificationPreferencesController
{
    /**
     * Read the PUSH switches. A group with no stored row is on.
     */
    public function index(Request $request, PushPreferences $preferences): JsonResponse
    {
        return ApiResponse::success(new PushPreferencesResource($preferences->forUser($this->userId($request))));
    }

    /**
     * Replace the PUSH switches. Repeating the same body changes nothing. In-app notices are unaffected.
     */
    public function update(UpdatePushPreferencesRequest $request, PushPreferences $preferences): JsonResponse
    {
        $userId = $this->userId($request);
        $preferences->update($userId, $request->groups());

        return ApiResponse::success(new PushPreferencesResource($preferences->forUser($userId)));
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        if ($user === null) {
            throw new AuthenticationException;
        }

        return (string) $user->getAuthIdentifier();
    }
}
