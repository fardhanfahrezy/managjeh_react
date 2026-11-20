<?php

namespace App\Mcp\Contracts;

use App\Mcp\McpToolResult;
use App\Models\User;

interface McpToolInterface
{
    /**
     * Unique snake_case tool identifier (e.g. get_my_tasks, create_task).
     */
    public function name(): string;

    /**
     * Concise description explaining to the LLM when and how to use this tool.
     */
    public function description(): string;

    /**
     * JSON Schema object describing accepted arguments (must never accept user_id).
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Whether this tool mutates user data and requires explicit approval.
     */
    public function isMutation(): bool;

    /**
     * Execute the tool strictly scoped to the authenticated user.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function execute(User $user, array $arguments): McpToolResult;
}
