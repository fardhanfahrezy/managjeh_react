<?php

namespace App\Http\Controllers;

use App\Http\Requests\Sync\SyncPullRequest;
use App\Http\Requests\Sync\SyncPushRequest;
use App\Services\Sync\SyncService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(
        private SyncService $syncService,
    ) {}

    public function push(SyncPushRequest $request): JsonResponse
    {
        /** @var array<int, array<string, mixed>> $operations */
        $operations = $request->validated('operations');

        $payload = $this->syncService->push($request->user(), $operations);

        return response()->json($payload);
    }

    public function pull(SyncPullRequest $request): JsonResponse
    {
        $sinceRaw = $request->validated('since');
        $since = is_string($sinceRaw) && $sinceRaw !== ''
            ? CarbonImmutable::parse($sinceRaw)->utc()
            : null;
        $limit = (int) ($request->validated('limit') ?? 500);

        $payload = $this->syncService->pull($request->user(), $since, $limit);

        return response()->json($payload);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->syncService->status($request->user()));
    }
}
