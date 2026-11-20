<?php

namespace App\Mcp;

final readonly class McpToolResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public bool $success,
        public array $data = [],
        public ?string $error = null,
        public ?string $targetType = null,
        public ?int $targetId = null,
        public ?string $summary = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function ok(
        array $data,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $summary = null,
    ): self {
        return new self(
            success: true,
            data: $data,
            error: null,
            targetType: $targetType,
            targetId: $targetId,
            summary: $summary,
        );
    }

    public static function failure(string $error): self
    {
        return new self(
            success: false,
            data: [],
            error: $error,
        );
    }

    /**
     * Serialize to a compact array suitable for sending back to an LLM tool response.
     *
     * @return array<string, mixed>
     */
    public function toToolPayload(): array
    {
        if (! $this->success) {
            return [
                'ok' => false,
                'error' => $this->error ?? 'Terjadi kesalahan saat memproses tool.',
            ];
        }

        return [
            'ok' => true,
            'data' => $this->data,
        ];
    }
}
