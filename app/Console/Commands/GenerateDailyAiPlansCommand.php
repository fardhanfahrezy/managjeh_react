<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AiPlanner\AiPlannerService;
use Illuminate\Console\Command;

class GenerateDailyAiPlansCommand extends Command
{
    protected $signature = 'ai-planner:generate-daily';

    protected $description = 'Generate daily AI priority plans for active users';

    public function handle(AiPlannerService $planner): int
    {
        $count = 0;

        User::query()->chunkById(50, function ($users) use ($planner, &$count): void {
            foreach ($users as $user) {
                $planner->generateForUser($user, 'scheduled');
                $count++;
            }
        });

        $this->info("Generated daily AI plans for {$count} user(s).");

        return self::SUCCESS;
    }
}
