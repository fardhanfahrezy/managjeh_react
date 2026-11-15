<?php

namespace App\Providers;

use App\Contracts\WhatsAppGatewayInterface;
use App\Services\WhatsApp\HttpWhatsAppGateway;
use App\Services\WhatsApp\NullWhatsAppGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
