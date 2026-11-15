<?php

namespace App\Services\AiPlanner;

use App\Contracts\AiPlannerProviderInterface;
use App\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GeminiPlannerProvider implements AiPlannerProviderInterface
{
    public function __construct(
        private AiPromptBuilder $promptBuilder,
    ) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function model(): ?string
    {
        return (string) config('services.gemini.model', 'gemini-2.5-flash');
    }

    public function generatePlan(AiPlannerContext $context): AiPlannerProviderResponse
    {
        $apiKey = (string) config('services.gemini.api_key', '');
        $model = (string) $this->model();
        $baseEndpoint = rtrim((string) config('services.gemini.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $timeout = (int) config('services.gemini.timeout', 20);

        if ($apiKey === '') {
            throw new AiProviderException('Gemini API key is not configured.');
        }

        $url = sprintf('%s/models/%s:generateContent?key=%s', $baseEndpoint, $model, $apiKey);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $this->promptBuilder->buildPrompt($context)],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'temperature' => 0.2,
                    ],
                ]);
        } catch (ConnectionException) {
            throw new AiProviderException('Gemini connection failed.');
        }

        if (! $response->successful()) {
            throw new AiProviderException(sprintf('Gemini returned HTTP %d.', $response->status()));
        }

        $rawText = $response->json('candidates.0.content.parts.0.text');
        if (! is_string($rawText) || trim($rawText) === '') {
            throw new AiProviderException('Gemini returned empty response content.');
        }

        return new AiPlannerProviderResponse(
            provider: $this->name(),
            model: $model,
            result: $this->promptBuilder->parseJsonResponse($rawText, 'Gemini'),
        );
    }
}
