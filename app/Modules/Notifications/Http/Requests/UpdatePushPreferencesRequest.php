<?php

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Preferences\PushPreferenceGroups;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** PUT /notifications/preferences: every known PUSH group, each on or off. Unknown groups are refused. */
final class UpdatePushPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the subject is always the signed-in user; there is no id in the path
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $knownOnly = function (string $attribute, mixed $value, Closure $fail): void {
            if (is_array($value) && array_diff(array_map('strval', array_keys($value)), PushPreferenceGroups::keys()) !== []) {
                $fail('Unknown notification group.');
            }
        };

        $rules = ['push' => ['required', 'array', $knownOnly]];
        foreach (PushPreferenceGroups::keys() as $group) {
            $rules["push.{$group}"] = ['required', 'boolean'];
        }

        return $rules;
    }

    /** @return array<string, bool> */
    public function groups(): array
    {
        $groups = [];
        foreach (PushPreferenceGroups::keys() as $group) {
            $groups[$group] = (bool) $this->input("push.{$group}");
        }

        return $groups;
    }
}
