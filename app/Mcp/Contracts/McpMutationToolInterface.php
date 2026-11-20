<?php

namespace App\Mcp\Contracts;

use App\Models\User;

interface McpMutationToolInterface extends McpToolInterface
{
    /**
     * Validate arguments, authorize target ownership, and compute dry-run preview without writing to DB.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{
     *     valid: bool,
     *     payload: array<string, mixed>,
     *     conflict_preview: array<int, array<string, mixed>>,
     *     summary: string,
     *     error: string|null,
     * }
     */
    public function preview(User $user, array $arguments): array;
}
