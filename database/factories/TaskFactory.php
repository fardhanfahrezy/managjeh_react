<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'project_id' => null,
            'parent_id' => null,
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Medium,
            'due_at' => now()->addDays(2),
            'completed_at' => null,
            'sort_order' => 0,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::InProgress,
            'completed_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::Cancelled,
            'completed_at' => null,
        ]);
    }

    public function priority(TaskPriority $priority): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => $priority,
        ]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $project->user_id,
            'project_id' => $project->id,
        ]);
    }

    public function subtaskOf(Task $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $parent->user_id,
            'project_id' => $parent->project_id,
            'parent_id' => $parent->id,
        ]);
    }
}
