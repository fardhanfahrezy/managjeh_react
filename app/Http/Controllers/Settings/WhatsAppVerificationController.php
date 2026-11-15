<?php

namespace App\Http\Controllers\Settings;

use App\Contracts\WhatsAppGatewayInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class WhatsAppVerificationController extends Controller
{
    private const int OTP_TTL_SECONDS = 300;

    public function sendOtp(Request $request, WhatsAppGatewayInterface $gateway): RedirectResponse
    {
        $user = $request->user();

        if ($user->whatsapp_number === null || $user->whatsapp_number === '') {
            throw ValidationException::withMessages([
                'whatsapp_number' => 'Simpan nomor WhatsApp Anda terlebih dahulu sebelum meminta kode OTP.',
            ]);
        }

        $otpCode = (string) random_int(100000, 999999);

        Cache::put($this->cacheKey($user->id), Hash::make($otpCode), self::OTP_TTL_SECONDS);

        $result = $gateway->sendVerificationOtp($user->whatsapp_number, $otpCode);

        if (! $result->success) {
            Cache::forget($this->cacheKey($user->id));

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Gagal mengirim kode OTP WhatsApp. Silakan coba lagi.',
            ]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Kode OTP verifikasi telah dikirim ke nomor WhatsApp Anda (berlaku 5 menit).',
        ]);

        return back()->with('whatsapp_otp_sent', true);
    }

    public function verifyOtp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'otp' => ['required', 'digits:6'],
        ]);

        $user = $request->user();
        $hashedOtp = Cache::get($this->cacheKey($user->id));

        if (! is_string($hashedOtp) || ! Hash::check((string) $validated['otp'], $hashedOtp)) {
            throw ValidationException::withMessages([
                'otp' => 'Kode OTP tidak valid atau sudah kedaluwarsa.',
            ]);
        }

        $user->forceFill([
            'whatsapp_verified_at' => now(),
        ])->save();

        Cache::forget($this->cacheKey($user->id));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Nomor WhatsApp berhasil diverifikasi.',
        ]);

        return back();
    }

    private function cacheKey(int $userId): string
    {
        return sprintf('whatsapp_otp:user:%d', $userId);
    }
}
