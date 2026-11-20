<?php

namespace App\Mcp;

use App\Mcp\Contracts\McpToolInterface;
use InvalidArgumentException;
use stdClass;

class McpToolRegistry
{
    /**
     * Forbidden property names that must never be exposed to AI tools (D-16).
     *
     * @var list<string>
     */
    private const array FORBIDDEN_SCHEMA_KEYS = [
        'user_id',
        'owner_id',
    ];

    /**
     * @var array<string, McpToolInterface>
     */
    private array $tools = [];

    /**
     * @param  iterable<McpToolInterface>  $tools
     */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(McpToolInterface $tool): void
    {
        $schema = $tool->inputSchema();
        $this->assertNoForbiddenProperties($tool->name(), $schema);

        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?McpToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * @return array<string, McpToolInterface>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Format registered tools for Gemini generateContent `tools[].functionDeclarations`.
     *
     * @return list<array<string, mixed>>
     */
    public function toGeminiFunctionDeclarations(): array
    {
        $declarations = [];

        foreach ($this->tools as $tool) {
            $declaration = [
                'name' => $tool->name(),
                'description' => $tool->description(),
            ];

            $schema = $tool->inputSchema();
            $properties = $schema['properties'] ?? [];

            if (is_array($properties) && $properties !== []) {
                $declaration['parameters'] = $schema;
            }

            $declarations[] = $declaration;
        }

        return $declarations;
    }

    /**
     * Format registered tools for OpenAI-compatible `/v1/chat/completions` `tools` payload.
     *
     * @return list<array<string, mixed>>
     */
    public function toOpenAiTools(): array
    {
        $tools = [];

        foreach ($this->tools as $tool) {
            $schema = $tool->inputSchema();
            $properties = $schema['properties'] ?? [];

            if (! is_array($properties) || $properties === []) {
                $schema['properties'] = new stdClass;
            }

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $schema,
                ],
            ];
        }

        return $tools;
    }

    /**
     * Recursively verify that a tool schema never accepts user_id or owner_id.
     *
     * @param  array<string, mixed>  $schema
     */
    private function assertNoForbiddenProperties(string $toolName, array $schema): void
    {
        $properties = $schema['properties'] ?? null;

        if (is_array($properties)) {
            foreach ($properties as $propertyName => $definition) {
                if (is_string($propertyName) && in_array(strtolower($propertyName), self::FORBIDDEN_SCHEMA_KEYS, true)) {
                    throw new InvalidArgumentException(
                        sprintf('MCP tool "%s" must not expose forbidden parameter "%s".', $toolName, $propertyName),
                    );
                }

                if (is_array($definition)) {
                    $this->assertNoForbiddenProperties($toolName, $definition);
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $this->assertNoForbiddenProperties($toolName, $schema['items']);
        }
    }
}
