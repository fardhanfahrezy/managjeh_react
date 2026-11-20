<?php

namespace App\Mcp\Tools;

use App\Mcp\Contracts\McpToolInterface;
use App\Mcp\McpToolResult;
use App\Models\User;
use App\Services\DashboardAggregatorService;

class GetDailyOverviewTool implements McpToolInterface
{
    public function __construct(
        private DashboardAggregatorService $dashboardAggregator,
    ) {}

    public function name(): string
    {
        return 'get_daily_overview';
    }

    public function description(): string
    {
        return 'Mengambil ringkasan terpadu hari ini (tugas hari ini, tugas mendatang 7 hari, agenda hari ini, proyek aktif, ringkasan penyelesaian, dan rencana AI harian terakhir).';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function isMutation(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(User $user, array $arguments): McpToolResult
    {
        $summary = $this->dashboardAggregator->aggregate($user);

        // Sanitize PII/contact fields before sending overview to LLM (D-15).
        if (isset($summary['whatsapp_status']) && is_array($summary['whatsapp_status'])) {
            unset($summary['whatsapp_status']['number_masked']);
        }

        return McpToolResult::ok($summary);
    }
}
