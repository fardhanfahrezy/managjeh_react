<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\WhatsAppVerificationController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::post('settings/whatsapp/send-otp', [WhatsAppVerificationController::class, 'sendOtp'])
        ->middleware('throttle:whatsapp-otp')
        ->name('whatsapp.send-otp');
    Route::post('settings/whatsapp/verify-otp', [WhatsAppVerificationController::class, 'verifyOtp'])
        ->middleware('throttle:10,1')
        ->name('whatsapp.verify-otp');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/profile/export', [ProfileController::class, 'export'])
        ->middleware('throttle:data-export')
        ->name('profile.export');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
