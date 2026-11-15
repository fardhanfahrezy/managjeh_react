<?php

namespace App\Services\AiPlanner;

use App\Exceptions\AiProviderException;
use JsonException;

class AiPromptBuilder
{
    public function buildPrompt(AiPlannerContext $context): string
    {
        $snapshotJson = json_encode($context->toSanitizedSnapshot(), JSON_THROW_ON_ERROR);

        return <<<PROMPT
Anda adalah asisten perencanaan produktivitas mahasiswa.
Tugas Anda: analisis daftar tugas aktif, agenda hari ini, dan slot waktu kosong berikut, lalu pilih MAKSIMAL 3 prioritas utama hari ini dalam bahasa Indonesia.

Aturan ketat:
1. Balas HANYA dengan JSON valid tanpa markdown fence atau penjelasan tambahan.
2. Setiap `task_id` pada `priorities` dan `recommended_reminders` WAJIB berasal dari `tasks[].id` di input.
3. Format JSON wajib:
{
  "summary": "string ringkas kondisi hari ini",
  "priorities": [
    {
      "task_id": 123,
      "reason": "alasan pemilihan",
      "suggested_action": "langkah konkret pertama",
      "estimated_minutes": 45
    }
  ],
  "recommended_reminders": [
    {
      "task_id": 123,
      "remind_at_local": "YYYY-MM-DD HH:MM:SS",
      "reason": "alasan waktu pengingat sesuai slot kosong"
    }
  ],
  "warnings": [
    {
      "type": "schedule_conflict|overload|overdue",
      "message": "pesan peringatan singkat"
    }
  ]
}

Input konteks:
{$snapshotJson}
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseJsonResponse(string $rawContent, string $providerName): array
    {
        $trimmed = trim($rawContent);
        if (str_starts_with($trimmed, '```')) {
            $trimmed = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $trimmed);
            $trimmed = trim($trimmed);
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiProviderException("{$providerName} returned invalid JSON.");
        }

        if (! is_array($decoded)) {
            throw new AiProviderException("{$providerName} returned unexpected JSON root type.");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
