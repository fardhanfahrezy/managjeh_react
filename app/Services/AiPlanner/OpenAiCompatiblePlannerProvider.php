<?php

namespace App\Services\AiPlanner;

use App\Contracts\AiPlannerProviderInterface;
use App\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

abstract class OpenAiCompatiblePlannerProvider implements AiPlannerProviderInterface
{
    public function __construct(
        protected AiPromptBuilder $promptBuilder,
    ) {}

    abstract protected function serviceConfigKey(): string;

    public function model(): ?string
    {
        return (string) config("services.{$this->serviceConfigKey()}.model", '');
    }

    public function generatePlan(AiPlannerContext $context): AiPlannerProviderResponse
    {
        $configKey = $this->serviceConfigKey();
        $apiKey = (string) config("services.{$configKey}.api_key", '');
        $model = (string) $this->model();
        $endpoint = (string) config("services.{$configKey}.endpoint", '');
        $timeout = (int) config("services.{$configKey}.timeout", 20);

        if ($apiKey === '' || $endpoint === '' || $model === '') {
            throw new AiProviderException("{$this->name()} API is not configured.");
        }

        try {
            $response = Http::timeout($timeout)
                ->withToken($apiKey)
                ->acceptJson()
                ->post($endpoint, [
                    'model' => $model,
                    'temperature' => 0.2,
                    'max_tokens' => 2048,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $this->promptBuilder->buildPrompt($context),
                        ],
                    ],
                ]);
        } catch (ConnectionException) {
            throw new AiProviderException("{$this->name()} connection failed.");
        }

        if (! $response->successful()) {
            throw new AiProviderException(sprintf('%s returned HTTP %d.', $this->name(), $response->status()));
        }

        $rawText = $response->json('choices.0.message.content');
        if (! is_string($rawText) || trim($rawText) === '') {
            throw new AiProviderException("{$this->name()} returned empty response content.");
        }

        return new AiPlannerProviderResponse(
            provider: $this->name(),
            model: $model,
            result: $this->promptBuilder->parseJsonResponse($rawText, $this->name()),
        );
    }
}
