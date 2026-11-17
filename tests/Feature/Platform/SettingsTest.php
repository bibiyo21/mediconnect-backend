<?php

use App\Modules\Administration\Contracts\Settings;
use App\Shared\Exceptions\InvalidValue;
use Illuminate\Support\Facades\DB;

it('stores, caches and audits settings', function () {
    $settings = app(Settings::class);

    expect($settings->get('booking.hold_minutes', 10))->toBe(10);

    $settings->set('booking.hold_minutes', 12);
    $settings->set('booking.hold_minutes', 15);

    expect($settings->get('booking.hold_minutes'))->toBe(15)
        ->and(DB::table('audit_logs')->where('action', 'settings.changed')->count())->toBe(2)
        ->and(json_decode(DB::table('audit_logs')->orderByDesc('id')->value('changes'), true))->toEqual(['old' => 12, 'new' => 15]);
});

it('refuses secret-looking and malformed keys', function (string $key) {
    app(Settings::class)->set($key, 'x');
})->with(['sms.api_key', 'payments.secret', 'Bad Key', 'x'])->throws(InvalidValue::class);
