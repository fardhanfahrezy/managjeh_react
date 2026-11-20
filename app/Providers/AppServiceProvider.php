<?php

namespace App\Providers;

use App\Contracts\WhatsAppGatewayInterface;
use App\Mcp\McpToolRegistry;
use App\Mcp\Tools\CreateScheduleTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\GetDailyOverviewTool;
use App\Mcp\Tools\GetMyProjectsTool;
use App\Mcp\Tools\GetMyTasksTool;
use App\Mcp\Tools\GetScheduleRangeTool;
use App\Mcp\Tools\GetTodayScheduleTool;
use App\Mcp\Tools\GetUpcomingTasksTool;
use App\Mcp\Tools\UpdateScheduleTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Services\Notifications\OutboundChannelManager;
use App\Services\Notifications\WhatsAppOutboundChannel;
use App\Services\WhatsApp\FonnteWhatsAppGateway;
use App\Services\WhatsApp\HttpWhatsAppGateway;
use App\Services\WhatsApp\MetaCloudWhatsAppGateway;
use App\Services\WhatsApp\NullWhatsAppGateway;
use Carbon\CarbonImmutable;
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

            if ($driver === 'meta_cloud') {
                return new MetaCloudWhatsAppGateway(
                    phoneNumberId: (string) config('services.whatsapp.meta_phone_number_id', ''),
                    accessToken: (string) config('services.whatsapp.meta_access_token', ''),
                    apiVersion: (string) config('services.whatsapp.meta_api_version', 'v21.0'),
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

        $this->app->bind(OutboundChannelManager::class, function ($app): OutboundChannelManager {
            return new OutboundChannelManager([
                $app->make(WhatsAppOutboundChannel::class),
            ]);
        });

        $this->app->singleton(McpToolRegistry::class, function ($app): McpToolRegistry {
            return new McpToolRegistry([
                $app->make(GetMyTasksTool::class),
                $app->make(GetUpcomingTasksTool::class),
                $app->make(GetTodayScheduleTool::class),
                $app->make(GetScheduleRangeTool::class),
                $app->make(GetMyProjectsTool::class),
                $app->make(GetDailyOverviewTool::class),
                $app->make(CreateTaskTool::class),
                $app->make(UpdateTaskTool::class),
                $app->make(CreateScheduleTool::class),
                $app->make(UpdateScheduleTool::class),
            ]);
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

        RateLimiter::for('ai-chat', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(20)->by((string) ($user !== null ? $user->id : $request->ip()));
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
