<?php

namespace App\Services\Sync;

use App\Enums\AiPlannerRunStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\AiPlannerRun;
use App\Models\CalendarEvent;
use App\Models\Project;
use App\Models\SyncOperation;
use App\Models\SyncTombstone;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminder\ReminderSynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SyncService
{
    private const array PROJECT_FIELDS = [
        'name',
        'description',
        'status',
        'start_date',
        'due_date',
        'completed_at',
    ];

    private const array TASK_FIELDS = [
        'project_uuid',
        'parent_uuid',
        'title',
        'description',
        'status',
        'priority',
        'due_at',
        'completed_at',
        'sort_order',
    ];

    private const array CALENDAR_FIELDS = [
        'project_uuid',
        'task_uuid',
        'parent_event_uuid',
        'recurrence_id',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'start_date',
        'end_date',
        'is_all_day',
        'is_cancelled',
        'recurrence_rule',
        'recurrence_until',
        'recurrence_exceptions',
    ];

    public function __construct(
        private ReminderSynchronizer $reminderSynchronizer,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $operations
     * @return array{results: array<int, array<string, mixed>>, server_time: string}
     */
    public function push(User $user, array $operations): array
    {
        $ordered = $this->orderOperations($operations);
        $resultsByOpId = [];

        foreach ($ordered as $op) {
            $opId = (string) $op['operation_id'];
            $resultsByOpId[$opId] = $this->processOperation($user, $op);
        }

        // Preserve client request order in response payload.
        $results = [];
        foreach ($operations as $op) {
            $opId = (string) $op['operation_id'];
            $results[] = $resultsByOpId[$opId];
        }

        return [
            'results' => $results,
            'server_time' => CarbonImmutable::now('UTC')->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pull(User $user, ?CarbonImmutable $since, int $limit = 500): array
    {
        $serverTime = CarbonImmutable::now('UTC');
        $safeLimit = max(1, min(500, $limit));

        $projectsQuery = Project::query()
            ->where('user_id', $user->id)
            ->orderBy('updated_at')
            ->orderBy('id');

        $tasksQuery = Task::query()
            ->where('user_id', $user->id)
            ->with(['project:id,uuid', 'parent:id,uuid'])
            ->orderBy('updated_at')
            ->orderBy('id');

        $eventsQuery = CalendarEvent::query()
            ->where('user_id', $user->id)
            ->with(['project:id,uuid', 'task:id,uuid', 'parentEvent:id,uuid'])
            ->orderBy('updated_at')
            ->orderBy('id');

        if ($since !== null) {
            $projectsQuery->withTrashed()->where('updated_at', '>', $since);
            $tasksQuery->withTrashed()->where('updated_at', '>', $since);
            $eventsQuery->withTrashed()->where('updated_at', '>', $since);
        }

        $projects = $projectsQuery->limit($safeLimit + 1)->get();
        $tasks = $tasksQuery->limit($safeLimit + 1)->get();
        $events = $eventsQuery->limit($safeLimit + 1)->get();

        $hasMore = $projects->count() > $safeLimit
            || $tasks->count() > $safeLimit
            || $events->count() > $safeLimit;

        $projects = $projects->take($safeLimit);
        $tasks = $tasks->take($safeLimit);
        $events = $events->take($safeLimit);

        $tombstonesQuery = SyncTombstone::query()
            ->where('user_id', $user->id)
            ->orderBy('deleted_at');

        if ($since !== null) {
            $tombstonesQuery->where('deleted_at', '>', $since);
        }

        $tombstones = $tombstonesQuery
            ->limit($safeLimit)
            ->get()
            ->map(fn (SyncTombstone $t): array => [
                'entity_type' => $t->entity_type,
                'entity_uuid' => $t->entity_uuid,
                'deleted_at' => $t->deleted_at->toISOString(),
            ])
            ->values()
            ->all();

        $latestPlannerRun = $this->serializeLatestPlannerRun($user);

        return [
            'projects' => $projects->map(fn (Project $p): array => $this->serializeProject($p))->values()->all(),
            'tasks' => $tasks->map(fn (Task $t): array => $this->serializeTask($t))->values()->all(),
            'calendar_events' => $events->map(fn (CalendarEvent $e): array => $this->serializeCalendarEvent($e))->values()->all(),
            'tombstones' => $tombstones,
            'ai_planner_latest' => $latestPlannerRun,
            'server_time' => $serverTime->toISOString(),
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(User $user): array
    {
        $quietStart = substr((string) ($user->reminder_quiet_start ?? '00:00'), 0, 5);
        $quietEnd = substr((string) ($user->reminder_quiet_end ?? '05:00'), 0, 5);

        return [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => $user->timezone ?: 'Asia/Jakarta',
            'whatsapp_enabled' => (bool) $user->whatsapp_enabled,
            'whatsapp_verified' => $user->whatsapp_verified_at !== null,
            'whatsapp_number_masked' => $this->maskPhoneNumber($user->whatsapp_number),
            'quiet_hours' => sprintf('%s–%s', $quietStart, $quietEnd),
            'server_time' => CarbonImmutable::now('UTC')->toISOString(),
            'lease_ttl_days' => 7,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $operations
     * @return array<int, array<string, mixed>>
     */
    private function orderOperations(array $operations): array
    {
        $indexed = [];
        foreach ($operations as $idx => $op) {
            $indexed[] = ['idx' => $idx, 'op' => $op];
        }

        usort($indexed, function (array $a, array $b): int {
            $rankA = $this->operationDependencyRank($a['op']);
            $rankB = $this->operationDependencyRank($b['op']);
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            return $a['idx'] <=> $b['idx'];
        });

        return array_map(fn (array $item): array => $item['op'], $indexed);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function operationDependencyRank(array $op): int
    {
        $type = (string) ($op['entity_type'] ?? '');
        $action = (string) ($op['action'] ?? 'upsert');
        /** @var array<string, mixed> $payload */
        $payload = is_array($op['payload'] ?? null) ? $op['payload'] : [];

        // Deletes execute after upserts so reparented references stay valid.
        $deleteOffset = $action === 'delete' ? 10 : 0;

        return match ($type) {
            'project' => $deleteOffset + 1,
            'task' => $deleteOffset + (empty($payload['parent_uuid']) ? 2 : 3),
            'calendar_event' => $deleteOffset + (empty($payload['parent_event_uuid']) ? 4 : 5),
            default => $deleteOffset + 9,
        };
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function processOperation(User $user, array $op): array
    {
        $operationId = (string) $op['operation_id'];
        $entityType = (string) $op['entity_type'];
        $entityUuid = (string) $op['entity_uuid'];

        /** @var SyncOperation|null $existingOp */
        $existingOp = SyncOperation::query()
            ->where('user_id', $user->id)
            ->where('operation_id', $operationId)
            ->first();

        if ($existingOp !== null) {
            return $existingOp->response_payload;
        }

        return DB::transaction(function () use ($user, $op, $operationId, $entityType, $entityUuid): array {
            $result = match ($entityType) {
                'project' => $this->applyProjectOperation($user, $op),
                'task' => $this->applyTaskOperation($user, $op),
                'calendar_event' => $this->applyCalendarEventOperation($user, $op),
                default => $this->buildRejected($operationId, $entityType, $entityUuid, 'Unsupported entity type.'),
            };

            if ((string) $result['status'] !== 'rejected') {
                SyncOperation::query()->create([
                    'user_id' => $user->id,
                    'operation_id' => $operationId,
                    'entity_type' => $entityType,
                    'entity_uuid' => $entityUuid,
                    'status' => (string) $result['status'],
                    'response_payload' => $result,
                    'processed_at' => CarbonImmutable::now('UTC'),
                ]);
            }

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function applyProjectOperation(User $user, array $op): array
    {
        $operationId = (string) $op['operation_id'];
        $entityUuid = (string) $op['entity_uuid'];
        $action = (string) $op['action'];
        $baseVersion = (int) $op['base_version'];
        $forceOverwrite = (bool) ($op['force_overwrite'] ?? false);
        /** @var array<string, mixed> $payload */
        $payload = is_array($op['payload'] ?? null) ? $op['payload'] : [];
        /** @var array<int, string> $changedFields */
        $changedFields = is_array($op['changed_fields'] ?? null) ? array_values($op['changed_fields']) : [];
        /** @var array<string, mixed>|null $baseSnapshot */
        $baseSnapshot = is_array($op['base_snapshot'] ?? null) ? $op['base_snapshot'] : null;

        /** @var Project|null $existing */
        $existing = Project::withTrashed()->where('uuid', $entityUuid)->first();
        if ($existing !== null && $existing->user_id !== $user->id) {
            return $this->buildRejected($operationId, 'project', $entityUuid, 'Forbidden: record belongs to another user.');
        }

        $tombstone = SyncTombstone::query()
            ->where('user_id', $user->id)
            ->where('entity_type', 'project')
            ->where('entity_uuid', $entityUuid)
            ->first();

        if ($action === 'delete') {
            if ($tombstone !== null || $existing === null || $existing->trashed()) {
                return $this->buildApplied($operationId, 'project', $entityUuid, $existing !== null ? $this->serializeProject($existing) : null);
            }

            if (! Gate::forUser($user)->allows('delete', $existing)) {
                return $this->buildRejected($operationId, 'project', $entityUuid, 'Forbidden.');
            }

            if (! $forceOverwrite && $baseVersion > 0 && $existing->version !== $baseVersion) {
                return $this->buildConflict(
                    $operationId,
                    'project',
                    $entityUuid,
                    'modified_on_server',
                    ['deleted_at'],
                    $this->serializeProject($existing),
                );
            }

            $existing->delete();
            $existing->refresh();

            return $this->buildApplied($operationId, 'project', $entityUuid, $this->serializeProject($existing));
        }

        // Upsert
        if ($tombstone !== null && ! $forceOverwrite) {
            return $this->buildConflict($operationId, 'project', $entityUuid, 'deleted_on_server', ['deleted_at'], null);
        }

        if ($existing !== null) {
            if (! Gate::forUser($user)->allows('update', $existing)) {
                return $this->buildRejected($operationId, 'project', $entityUuid, 'Forbidden.');
            }

            if ($existing->trashed() && ! $forceOverwrite) {
                return $this->buildConflict(
                    $operationId,
                    'project',
                    $entityUuid,
                    'deleted_on_server',
                    ['deleted_at'],
                    $this->serializeProject($existing),
                );
            }

            $merged = false;
            $fieldsToApply = self::PROJECT_FIELDS;

            if (! $forceOverwrite && $baseVersion > 0 && $existing->version !== $baseVersion) {
                $serverSnap = $this->projectSnapshot($existing);
                $resolution = $this->resolveFieldLevelMerge(
                    self::PROJECT_FIELDS,
                    $changedFields,
                    $baseSnapshot,
                    $serverSnap,
                    $payload,
                );

                if ($resolution['conflicts'] !== []) {
                    return $this->buildConflict(
                        $operationId,
                        'project',
                        $entityUuid,
                        'version_mismatch',
                        $resolution['conflicts'],
                        $this->serializeProject($existing),
                    );
                }

                $fieldsToApply = $resolution['fields_to_apply'];
                $merged = true;
            }

            try {
                if ($existing->trashed() && $forceOverwrite) {
                    $existing->restore();
                }

                $attributes = $this->extractProjectAttributes($payload, $fieldsToApply);
                if ($attributes !== []) {
                    $existing->fill($attributes);
                    $existing->save();
                }
                $existing->refresh();

                return $this->buildApplied(
                    $operationId,
                    'project',
                    $entityUuid,
                    $this->serializeProject($existing),
                    $merged ? 'merged' : 'applied',
                );
            } catch (ValidationException|InvalidArgumentException $e) {
                return $this->buildRejected($operationId, 'project', $entityUuid, $e->getMessage());
            }
        }

        if (! Gate::forUser($user)->allows('create', Project::class)) {
            return $this->buildRejected($operationId, 'project', $entityUuid, 'Forbidden.');
        }

        try {
            $attributes = $this->extractProjectAttributes($payload, self::PROJECT_FIELDS);
            if (! isset($attributes['name']) || trim((string) $attributes['name']) === '') {
                return $this->buildRejected($operationId, 'project', $entityUuid, 'Project name is required.');
            }

            $project = new Project([
                ...$attributes,
                'uuid' => $entityUuid,
                'user_id' => $user->id,
            ]);
            $project->save();

            if ($tombstone !== null) {
                $tombstone->delete();
            }

            return $this->buildApplied($operationId, 'project', $entityUuid, $this->serializeProject($project));
        } catch (ValidationException|InvalidArgumentException $e) {
            return $this->buildRejected($operationId, 'project', $entityUuid, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function applyTaskOperation(User $user, array $op): array
    {
        $operationId = (string) $op['operation_id'];
        $entityUuid = (string) $op['entity_uuid'];
        $action = (string) $op['action'];
        $baseVersion = (int) $op['base_version'];
        $forceOverwrite = (bool) ($op['force_overwrite'] ?? false);
        /** @var array<string, mixed> $payload */
        $payload = is_array($op['payload'] ?? null) ? $op['payload'] : [];
        /** @var array<int, string> $changedFields */
        $changedFields = is_array($op['changed_fields'] ?? null) ? array_values($op['changed_fields']) : [];
        /** @var array<string, mixed>|null $baseSnapshot */
        $baseSnapshot = is_array($op['base_snapshot'] ?? null) ? $op['base_snapshot'] : null;

        /** @var Task|null $existing */
        $existing = Task::withTrashed()
            ->with(['project:id,uuid', 'parent:id,uuid'])
            ->where('uuid', $entityUuid)
            ->first();

        if ($existing !== null && $existing->user_id !== $user->id) {
            return $this->buildRejected($operationId, 'task', $entityUuid, 'Forbidden: record belongs to another user.');
        }

        $tombstone = SyncTombstone::query()
            ->where('user_id', $user->id)
            ->where('entity_type', 'task')
            ->where('entity_uuid', $entityUuid)
            ->first();

        if ($action === 'delete') {
            if ($tombstone !== null || $existing === null || $existing->trashed()) {
                return $this->buildApplied($operationId, 'task', $entityUuid, $existing !== null ? $this->serializeTask($existing) : null);
            }

            if (! Gate::forUser($user)->allows('delete', $existing)) {
                return $this->buildRejected($operationId, 'task', $entityUuid, 'Forbidden.');
            }

            if (! $forceOverwrite && $baseVersion > 0 && $existing->version !== $baseVersion) {
                return $this->buildConflict(
                    $operationId,
                    'task',
                    $entityUuid,
                    'modified_on_server',
                    ['deleted_at'],
                    $this->serializeTask($existing),
                );
            }

            $existing->delete();
            $this->reminderSynchronizer->cancelForTask($existing);
            $existing->refresh();

            return $this->buildApplied($operationId, 'task', $entityUuid, $this->serializeTask($existing));
        }

        if ($tombstone !== null && ! $forceOverwrite) {
            return $this->buildConflict($operationId, 'task', $entityUuid, 'deleted_on_server', ['deleted_at'], null);
        }

        if ($existing !== null) {
            if (! Gate::forUser($user)->allows('update', $existing)) {
                return $this->buildRejected($operationId, 'task', $entityUuid, 'Forbidden.');
            }

            if ($existing->trashed() && ! $forceOverwrite) {
                return $this->buildConflict(
                    $operationId,
                    'task',
                    $entityUuid,
                    'deleted_on_server',
                    ['deleted_at'],
                    $this->serializeTask($existing),
                );
            }

            $merged = false;
            $fieldsToApply = self::TASK_FIELDS;

            if (! $forceOverwrite && $baseVersion > 0 && $existing->version !== $baseVersion) {
                $serverSnap = $this->taskSnapshot($existing);
                $resolution = $this->resolveFieldLevelMerge(
                    self::TASK_FIELDS,
                    $changedFields,
                    $baseSnapshot,
                    $serverSnap,
                    $payload,
                );

                if ($resolution['conflicts'] !== []) {
                    return $this->buildConflict(
                        $operationId,
                        'task',
                        $entityUuid,
                        'version_mismatch',
                        $resolution['conflicts'],
                        $this->serializeTask($existing),
                    );
                }

                $fieldsToApply = $resolution['fields_to_apply'];
                $merged = true;
            }

            try {
                if ($existing->trashed() && $forceOverwrite) {
                    $existing->restore();
                }

                $attributes = $this->extractTaskAttributes($user, $payload, $fieldsToApply);
                if ($attributes !== []) {
                    $existing->fill($attributes);
                    $existing->save();
                }
                $existing->load(['project:id,uuid', 'parent:id,uuid']);
                $this->reminderSynchronizer->syncForTask($existing);

                return $this->buildApplied(
                    $operationId,
                    'task',
                    $entityUuid,
                    $this->serializeTask($existing),
                    $merged ? 'merged' : 'applied',
                );
            } catch (ValidationException|InvalidArgumentException $e) {
                return $this->buildRejected($operationId, 'task', $entityUuid, $e->getMessage());
            }
        }

        if (! Gate::forUser($user)->allows('create', Task::class)) {
            return $this->buildRejected($operationId, 'task', $entityUuid, 'Forbidden.');
        }

        try {
            $attributes = $this->extractTaskAttributes($user, $payload, self::TASK_FIELDS);
            if (! isset($attributes['title']) || trim((string) $attributes['title']) === '') {
                return $this->buildRejected($operationId, 'task', $entityUuid, 'Task title is required.');
            }

            $task = new Task([
                ...$attributes,
                'uuid' => $entityUuid,
                'user_id' => $user->id,
            ]);
            $task->save();
            $task->load(['project:id,uuid', 'parent:id,uuid']);

            if ($tombstone !== null) {
                $tombstone->delete();
            }

            $this->reminderSynchronizer->syncForTask($task);

            return $this->buildApplied($operationId, 'task', $entityUuid, $this->serializeTask($task));
        } catch (ValidationException|InvalidArgumentException $e) {
            return $this->buildRejected($operationId, 'task', $entityUuid, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function applyCalendarEventOperation(User $user, array $op): array
    {
        $operationId = (string) $op['operation_id'];
        $entityUuid = (string) $op['entity_uuid'];
        $action = (string) $op['action'];
        $baseVersion = (int) $op['base_version'];
        $forceOverwrite = (bool) ($op['force_overwrite'] ?? false);
        /** @var array<string, mixed> $payload */
        $payload = is_array($op['payload'] ?? null) ? $op['payload'] : [];
        /** @var array<int, string> $changedFields */
        $changedFields = is_array($op['changed_fields'] ?? null) ? array_values($op['changed_fields']) : [];
        /** @var array<string, mixed>|null $baseSnapshot */
        $baseSnapshot = is_array($op['base_snapshot'] ?? null) ? $op['base_snapshot'] : null;

        /** @var CalendarEvent|null $existing */
        $existing = CalendarEvent::withTrashed()
            ->with(['project:id,uuid', 'task:id,uuid', 'parentEvent:id,uuid'])
            ->where('uuid', $entityUuid)
            ->first();

        if ($existing !== null && $existing->user_id !== $user->id) {
            return $this->buildRejected($operationId, 'calendar_event', $entityUuid, 'Forbidden: record belongs to another user.');
        }

        $tombstone = SyncTombstone::query()
            ->where('user_id', $user->id)
            ->where('entity_type', 'calendar_event')
            ->where('entity_uuid', $entityUuid)
            ->first();

        if ($action === 'delete') {
            if ($tombstone !== null || $existing === null || $existing->trashed()) {
                return $this->buildApplied($operationId, 'calendar_event', $entityUuid, $existing !== null ? $this->serializeCalendarEvent($existing) : null);
            }

            if (! Gate::forUser($user)->allows('delete', $existing)) {
                return $this->buildRejected($operationId, 'calendar_event', $entityUuid, 'Forbidden.');
            }

            if (! $forceOverwrite && $baseVersion > 0 && $existing->version !== $baseVersion) {
                return $this->buildConflict(
                    $operationId,
                    'calendar_event',
                    $entityUuid,
                    'modified_on_server',
                    ['deleted_at'],
                    $this->serializeCalendarEvent($existing),
                );
            }

            $existing->delete();
            $this->reminderSynchronizer->cancelForCalendarEvent($existing);
            $existing->refresh();

            return $this->buildApplied($operationId, 'calendar_event', $entityUuid, $this->serializeCalendarEvent($existing));
        }

        if ($tombstone !== null && ! $forceOverwrite) {
            return $this->buildConflict($operationId, 'calendar_event', $entityUuid, 'deleted_on_server', ['deleted_at'], null);
        }

        if ($existing !== null) {
            if (! Gate::forUser($user)->allows('update', $existing)) {
                return $this->buildRejected($operationId, 'calendar_event', $entityUuid, 'Forbidden.');
            }

            if ($existing->trashed() && ! $forceOverwrite) {
                return $this->buildConflict(
                    $operationId,
                    'calendar_event',
                    $entityUuid,
                    'deleted_on_server',
                    ['deleted_at'],
                    $this->serializeCalendarEvent($existing),
                );
            }

            $merged = false;
            $fieldsToApply = self::CALENDAR_FIELDS;

            if (! $forceOverwrite && $baseVersion > 0 && $existing->version !== $baseVersion) {
                $serverSnap = $this->calendarEventSnapshot($existing);
                $resolution = $this->resolveFieldLevelMerge(
                    self::CALENDAR_FIELDS,
                    $changedFields,
                    $baseSnapshot,
                    $serverSnap,
                    $payload,
                );

                if ($resolution['conflicts'] !== []) {
                    return $this->buildConflict(
                        $operationId,
                        'calendar_event',
                        $entityUuid,
                        'version_mismatch',
                        $resolution['conflicts'],
                        $this->serializeCalendarEvent($existing),
                    );
                }

                $fieldsToApply = $resolution['fields_to_apply'];
                $merged = true;
            }

            try {
                if ($existing->trashed() && $forceOverwrite) {
                    $existing->restore();
                }

                $attributes = $this->extractCalendarEventAttributes($user, $payload, $fieldsToApply);
                if ($attributes !== []) {
                    $existing->fill($attributes);
                    $existing->save();
                }
                $existing->load(['project:id,uuid', 'task:id,uuid', 'parentEvent:id,uuid']);
                $this->reminderSynchronizer->syncForCalendarEvent($existing);

                return $this->buildApplied(
                    $operationId,
                    'calendar_event',
                    $entityUuid,
                    $this->serializeCalendarEvent($existing),
                    $merged ? 'merged' : 'applied',
                );
            } catch (ValidationException|InvalidArgumentException $e) {
                return $this->buildRejected($operationId, 'calendar_event', $entityUuid, $e->getMessage());
            }
        }

        if (! Gate::forUser($user)->allows('create', CalendarEvent::class)) {
            return $this->buildRejected($operationId, 'calendar_event', $entityUuid, 'Forbidden.');
        }

        try {
            $attributes = $this->extractCalendarEventAttributes($user, $payload, self::CALENDAR_FIELDS);
            if (! isset($attributes['title']) || trim((string) $attributes['title']) === '') {
                return $this->buildRejected($operationId, 'calendar_event', $entityUuid, 'Event title is required.');
            }

            $event = new CalendarEvent([
                ...$attributes,
                'uuid' => $entityUuid,
                'user_id' => $user->id,
            ]);
            $event->save();
            $event->load(['project:id,uuid', 'task:id,uuid', 'parentEvent:id,uuid']);

            if ($tombstone !== null) {
                $tombstone->delete();
            }

            $this->reminderSynchronizer->syncForCalendarEvent($event);

            return $this->buildApplied($operationId, 'calendar_event', $entityUuid, $this->serializeCalendarEvent($event));
        } catch (ValidationException|InvalidArgumentException $e) {
            return $this->buildRejected($operationId, 'calendar_event', $entityUuid, $e->getMessage());
        }
    }

    /**
     * Hybrid field-level auto-merge (`D-24`).
     *
     * @param  list<string>  $allowedFields
     * @param  array<int, string>  $changedFields
     * @param  array<string, mixed>|null  $baseSnapshot
     * @param  array<string, mixed>  $serverSnapshot
     * @param  array<string, mixed>  $clientPayload
     * @return array{fields_to_apply: list<string>, conflicts: list<string>}
     */
    private function resolveFieldLevelMerge(
        array $allowedFields,
        array $changedFields,
        ?array $baseSnapshot,
        array $serverSnapshot,
        array $clientPayload,
    ): array {
        $candidateFields = $changedFields !== []
            ? array_values(array_intersect($changedFields, $allowedFields))
            : array_values(array_intersect(array_keys($clientPayload), $allowedFields));

        $fieldsToApply = [];
        $conflicts = [];

        foreach ($candidateFields as $field) {
            $clientVal = $clientPayload[$field] ?? null;
            $serverVal = $serverSnapshot[$field] ?? null;

            if ($this->valuesEqual($clientVal, $serverVal)) {
                continue;
            }

            if ($baseSnapshot !== null && array_key_exists($field, $baseSnapshot)) {
                $baseVal = $baseSnapshot[$field];
                if ($this->valuesEqual($serverVal, $baseVal)) {
                    $fieldsToApply[] = $field;

                    continue;
                }
            }

            $conflicts[] = $field;
        }

        return [
            'fields_to_apply' => $fieldsToApply,
            'conflicts' => $conflicts,
        ];
    }

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        return json_encode($a ?? null) === json_encode($b ?? null);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function extractProjectAttributes(array $payload, array $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];
            if ($field === 'status') {
                $status = ProjectStatus::tryFrom((string) $value);
                if ($status === null) {
                    throw new InvalidArgumentException('Invalid project status.');
                }
                $result['status'] = $status;
            } elseif ($field === 'name') {
                $result['name'] = trim((string) $value);
            } elseif ($field === 'description') {
                $result['description'] = is_string($value) && trim($value) !== '' ? trim($value) : null;
            } elseif ($field === 'start_date' || $field === 'due_date') {
                $result[$field] = is_string($value) && $value !== '' ? $value : null;
            } elseif ($field === 'completed_at') {
                $result['completed_at'] = is_string($value) && $value !== ''
                    ? CarbonImmutable::parse($value)->utc()
                    : null;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function extractTaskAttributes(User $user, array $payload, array $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];
            if ($field === 'project_uuid') {
                $result['project_id'] = $this->resolveOwnedProjectId($user, is_string($value) ? $value : null);
            } elseif ($field === 'parent_uuid') {
                $result['parent_id'] = $this->resolveOwnedTaskId($user, is_string($value) ? $value : null);
            } elseif ($field === 'title') {
                $result['title'] = trim((string) $value);
            } elseif ($field === 'description') {
                $result['description'] = is_string($value) && trim($value) !== '' ? trim($value) : null;
            } elseif ($field === 'status') {
                $status = TaskStatus::tryFrom((string) $value);
                if ($status === null) {
                    throw new InvalidArgumentException('Invalid task status.');
                }
                $result['status'] = $status;
            } elseif ($field === 'priority') {
                $priority = TaskPriority::tryFrom((string) $value);
                if ($priority === null) {
                    throw new InvalidArgumentException('Invalid task priority.');
                }
                $result['priority'] = $priority;
            } elseif ($field === 'due_at' || $field === 'completed_at') {
                $result[$field] = is_string($value) && $value !== ''
                    ? CarbonImmutable::parse($value)->utc()
                    : null;
            } elseif ($field === 'sort_order') {
                $result['sort_order'] = (int) $value;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function extractCalendarEventAttributes(User $user, array $payload, array $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];
            if ($field === 'project_uuid') {
                $result['project_id'] = $this->resolveOwnedProjectId($user, is_string($value) ? $value : null);
            } elseif ($field === 'task_uuid') {
                $result['task_id'] = $this->resolveOwnedTaskId($user, is_string($value) ? $value : null);
            } elseif ($field === 'parent_event_uuid') {
                $result['parent_event_id'] = $this->resolveOwnedCalendarEventId($user, is_string($value) ? $value : null);
            } elseif ($field === 'recurrence_id' || $field === 'recurrence_rule') {
                $result[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
            } elseif ($field === 'title') {
                $result['title'] = trim((string) $value);
            } elseif ($field === 'description') {
                $result['description'] = is_string($value) && trim($value) !== '' ? trim($value) : null;
            } elseif ($field === 'starts_at' || $field === 'ends_at') {
                $result[$field] = is_string($value) && $value !== ''
                    ? CarbonImmutable::parse($value)->utc()
                    : null;
            } elseif ($field === 'start_date' || $field === 'end_date' || $field === 'recurrence_until') {
                $result[$field] = is_string($value) && $value !== '' ? $value : null;
            } elseif ($field === 'is_all_day' || $field === 'is_cancelled') {
                $result[$field] = (bool) $value;
            } elseif ($field === 'recurrence_exceptions') {
                $result['recurrence_exceptions'] = is_array($value)
                    ? array_values(array_filter(array_map('strval', $value)))
                    : [];
            }
        }

        return $result;
    }

    private function resolveOwnedProjectId(User $user, ?string $projectUuid): ?int
    {
        if ($projectUuid === null || $projectUuid === '') {
            return null;
        }

        /** @var Project|null $project */
        $project = Project::withTrashed()
            ->where('uuid', $projectUuid)
            ->first(['id', 'user_id', 'deleted_at']);

        if ($project === null) {
            // Purged on server while client was offline -> mirror PostgreSQL nullOnDelete().
            return null;
        }

        if ($project->user_id !== $user->id) {
            throw new InvalidArgumentException('Referenced project_uuid does not belong to the authenticated user.');
        }

        return $project->trashed() ? null : $project->id;
    }

    private function resolveOwnedTaskId(User $user, ?string $taskUuid): ?int
    {
        if ($taskUuid === null || $taskUuid === '') {
            return null;
        }

        /** @var Task|null $task */
        $task = Task::withTrashed()
            ->where('uuid', $taskUuid)
            ->first(['id', 'user_id', 'deleted_at']);

        if ($task === null) {
            return null;
        }

        if ($task->user_id !== $user->id) {
            throw new InvalidArgumentException('Referenced task_uuid/parent_uuid does not belong to the authenticated user.');
        }

        return $task->trashed() ? null : $task->id;
    }

    private function resolveOwnedCalendarEventId(User $user, ?string $eventUuid): ?int
    {
        if ($eventUuid === null || $eventUuid === '') {
            return null;
        }

        /** @var CalendarEvent|null $event */
        $event = CalendarEvent::withTrashed()
            ->where('uuid', $eventUuid)
            ->first(['id', 'user_id']);

        if ($event === null) {
            return null;
        }

        if ($event->user_id !== $user->id) {
            throw new InvalidArgumentException('Referenced parent_event_uuid does not belong to the authenticated user.');
        }

        return $event->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function projectSnapshot(Project $project): array
    {
        return [
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status->value,
            'start_date' => $project->start_date?->format('Y-m-d'),
            'due_date' => $project->due_date?->format('Y-m-d'),
            'completed_at' => $project->completed_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskSnapshot(Task $task): array
    {
        return [
            'project_uuid' => $task->project?->uuid,
            'parent_uuid' => $task->parent?->uuid,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'due_at' => $task->due_at?->toISOString(),
            'completed_at' => $task->completed_at?->toISOString(),
            'sort_order' => $task->sort_order,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function calendarEventSnapshot(CalendarEvent $event): array
    {
        return [
            'project_uuid' => $event->project?->uuid,
            'task_uuid' => $event->task?->uuid,
            'parent_event_uuid' => $event->parentEvent?->uuid,
            'recurrence_id' => $event->recurrence_id,
            'title' => $event->title,
            'description' => $event->description,
            'starts_at' => $event->starts_at?->toISOString(),
            'ends_at' => $event->ends_at?->toISOString(),
            'start_date' => $event->start_date?->format('Y-m-d'),
            'end_date' => $event->end_date?->format('Y-m-d'),
            'is_all_day' => $event->is_all_day,
            'is_cancelled' => $event->is_cancelled,
            'recurrence_rule' => $event->recurrence_rule,
            'recurrence_until' => $event->recurrence_until?->format('Y-m-d'),
            'recurrence_exceptions' => $event->recurrence_exceptions ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProject(Project $project): array
    {
        return [
            'uuid' => $project->uuid,
            'server_id' => $project->id,
            'user_id' => $project->user_id,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status->value,
            'start_date' => $project->start_date?->format('Y-m-d'),
            'due_date' => $project->due_date?->format('Y-m-d'),
            'completed_at' => $project->completed_at?->toISOString(),
            'version' => $project->version,
            'created_at' => $project->created_at?->toISOString(),
            'updated_at' => $project->updated_at?->toISOString(),
            'deleted_at' => $project->deleted_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTask(Task $task): array
    {
        return [
            'uuid' => $task->uuid,
            'server_id' => $task->id,
            'user_id' => $task->user_id,
            'project_uuid' => $task->project?->uuid,
            'project_id' => $task->project_id,
            'parent_uuid' => $task->parent?->uuid,
            'parent_id' => $task->parent_id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'due_at' => $task->due_at?->toISOString(),
            'completed_at' => $task->completed_at?->toISOString(),
            'sort_order' => $task->sort_order,
            'version' => $task->version,
            'created_at' => $task->created_at?->toISOString(),
            'updated_at' => $task->updated_at?->toISOString(),
            'deleted_at' => $task->deleted_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCalendarEvent(CalendarEvent $event): array
    {
        return [
            'uuid' => $event->uuid,
            'server_id' => $event->id,
            'user_id' => $event->user_id,
            'project_uuid' => $event->project?->uuid,
            'project_id' => $event->project_id,
            'task_uuid' => $event->task?->uuid,
            'task_id' => $event->task_id,
            'parent_event_uuid' => $event->parentEvent?->uuid,
            'parent_event_id' => $event->parent_event_id,
            'recurrence_id' => $event->recurrence_id,
            'title' => $event->title,
            'description' => $event->description,
            'starts_at' => $event->starts_at?->toISOString(),
            'ends_at' => $event->ends_at?->toISOString(),
            'start_date' => $event->start_date?->format('Y-m-d'),
            'end_date' => $event->end_date?->format('Y-m-d'),
            'is_all_day' => $event->is_all_day,
            'is_cancelled' => $event->is_cancelled,
            'recurrence_rule' => $event->recurrence_rule,
            'recurrence_until' => $event->recurrence_until?->format('Y-m-d'),
            'recurrence_exceptions' => $event->recurrence_exceptions ?? [],
            'version' => $event->version,
            'created_at' => $event->created_at?->toISOString(),
            'updated_at' => $event->updated_at?->toISOString(),
            'deleted_at' => $event->deleted_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeLatestPlannerRun(User $user): ?array
    {
        /** @var AiPlannerRun|null $run */
        $run = $user->aiPlannerRuns()
            ->where('status', AiPlannerRunStatus::Success->value)
            ->latest('id')
            ->first();

        if ($run === null) {
            return null;
        }

        return [
            'id' => $run->id,
            'planning_date' => $run->planning_date->format('Y-m-d'),
            'trigger_type' => $run->trigger_type,
            'provider' => $run->provider,
            'model' => $run->model,
            'status' => $run->status->value,
            'result' => $run->result,
            'error_message' => $run->error_message,
            'created_at' => $run->created_at?->setTimezone($user->timezone ?: 'Asia/Jakarta')->format('Y-m-d H:i'),
        ];
    }

    private function maskPhoneNumber(?string $number): ?string
    {
        if ($number === null || $number === '') {
            return null;
        }

        $len = strlen($number);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4).substr($number, -4);
    }

    /**
     * @param  array<string, mixed>|null  $record
     * @return array<string, mixed>
     */
    private function buildApplied(
        string $operationId,
        string $entityType,
        string $entityUuid,
        ?array $record,
        string $status = 'applied',
    ): array {
        return [
            'operation_id' => $operationId,
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'status' => $status,
            'record' => $record,
            'server_record' => $record,
            'conflict_reason' => null,
            'conflicting_fields' => [],
            'error' => null,
        ];
    }

    /**
     * @param  list<string>  $conflictingFields
     * @param  array<string, mixed>|null  $serverRecord
     * @return array<string, mixed>
     */
    private function buildConflict(
        string $operationId,
        string $entityType,
        string $entityUuid,
        string $reason,
        array $conflictingFields,
        ?array $serverRecord,
    ): array {
        return [
            'operation_id' => $operationId,
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'status' => 'conflict',
            'record' => null,
            'server_record' => $serverRecord,
            'conflict_reason' => $reason,
            'conflicting_fields' => $conflictingFields,
            'error' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRejected(
        string $operationId,
        string $entityType,
        string $entityUuid,
        string $error,
    ): array {
        return [
            'operation_id' => $operationId,
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'status' => 'rejected',
            'record' => null,
            'server_record' => null,
            'conflict_reason' => null,
            'conflicting_fields' => [],
            'error' => $error,
        ];
    }
}
