<?php

namespace App\Providers;

use App\Contracts\WhatsAppGatewayInterface;
use App\Services\WhatsApp\FonnteWhatsAppGateway;
use App\Services\WhatsApp\HttpWhatsAppGateway;
use App\Services\WhatsApp\NullWhatsAppGateway;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WhatsAppGatewayInterface::class, function (): WhatsAppGatewayInterface {
            $driver = config('services.whatsapp.driver', 'null');

            if ($driver === 'fonnte') {
                return new FonnteWhatsAppGateway(
                    token: (string) config('services.whatsapp.fonnte_token', ''),
                    endpoint: (string) config('services.whatsapp.fonnte_endpoint', 'https://api.fonnte.com/send'),
                    timeoutSeconds: (int) config('services.whatsapp.timeout', 10),
                );
            }

            if ($driver === 'http') {
                return new HttpWhatsAppGateway(
                    endpoint: (string) config('services.whatsapp.endpoint', ''),
                    apiToken: (string) config('services.whatsapp.api_token', ''),
                    timeoutSeconds: (int) config('services.whatsapp.timeout', 10),
                );
            }

            return new NullWhatsAppGateway;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        RateLimiter::for('ai-planner', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(10)->by((string) ($user !== null ? $user->id : $request->ip()));
        });

        RateLimiter::for('whatsapp-otp', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(5)->by((string) ($user !== null ? $user->id : $request->ip()));
        });

        RateLimiter::for('data-export', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(5)->by((string) ($user !== null ? $user->id : $request->ip()));
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => Password::min(8)
            ->max(16)
            ->mixedCase()
            ->numbers()
            ->rules([
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && preg_match('/\s/u', $value) === 1) {
                        $fail('The :attribute field must not contain spaces.');
                    }
                },
            ]),
        );
    }
}
