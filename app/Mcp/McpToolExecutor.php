<?php

namespace App\Mcp;

use App\Mcp\Contracts\McpMutationToolInterface;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class McpToolExecutor
{
    public function __construct(
        private McpToolRegistry $registry,
    ) {}

    /**
     * Execute a read-only MCP tool strictly for the authenticated user.
     * Mutation tools are always rejected here so AI can never write without approval.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function executeRead(User $user, string $toolName, array $arguments): McpToolResult
    {
        $tool = $this->registry->get($toolName);

        if ($tool === null) {
            return McpToolResult::failure(sprintf('Tool "%s" tidak dikenali.', $toolName));
        }

        if ($tool->isMutation()) {
            return McpToolResult::failure(
                sprintf('Tool "%s" bersifat mengubah data dan wajib melalui persetujuan pengguna.', $toolName),
            );
        }

        return $tool->execute($user, $this->stripForbiddenArguments($arguments));
    }

    /**
     * Validate and dry-run a mutation tool without modifying database state.
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
    public function previewMutation(User $user, string $toolName, array $arguments): array
    {
        $tool = $this->registry->get($toolName);

        if ($tool === null || ! $tool->isMutation() || ! $tool instanceof McpMutationToolInterface) {
            return [
                'valid' => false,
                'payload' => [],
                'conflict_preview' => [],
                'summary' => '',
                'error' => sprintf('Tool mutasi "%s" tidak valid atau tidak terdaftar.', $toolName),
            ];
        }

        return $tool->preview($user, $this->stripForbiddenArguments($arguments));
    }

    /**
     * Execute a previously approved mutation tool.
     * Must ONLY be called after explicit user approval (AiActionProposalService).
     *
     * @param  array<string, mixed>  $arguments
     */
    public function executeApprovedMutation(User $user, string $toolName, array $arguments): McpToolResult
    {
        $tool = $this->registry->get($toolName);

        if ($tool === null || ! $tool->isMutation() || ! $tool instanceof McpMutationToolInterface) {
            return McpToolResult::failure(sprintf('Tool mutasi "%s" tidak valid atau tidak terdaftar.', $toolName));
        }

        try {
            return $tool->execute($user, $this->stripForbiddenArguments($arguments));
        } catch (AuthorizationException) {
            return McpToolResult::failure('Anda tidak memiliki izin untuk mengubah data tersebut.');
        } catch (ValidationException $exception) {
            $firstMessage = collect($exception->errors())->flatten()->first();

            return McpToolResult::failure(
                is_string($firstMessage) && $firstMessage !== ''
                    ? $firstMessage
                    : 'Data perubahan tidak valid.',
            );
        } catch (InvalidArgumentException $exception) {
            return McpToolResult::failure($exception->getMessage());
        }
    }

    /**
     * Defense-in-depth: strip any injected user_id / owner_id from LLM arguments.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function stripForbiddenArguments(array $arguments): array
    {
        unset($arguments['user_id'], $arguments['owner_id']);

        return $arguments;
    }
}
