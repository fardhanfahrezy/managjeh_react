<?php

use App\Models\CalendarEvent;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('user can view calendar page with week view by default', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);

    CalendarEvent::factory()->for($user)->create([
        'title' => 'Kuliah Pemrograman Web',
        'starts_at' => CarbonImmutable::parse('2026-11-16 09:00:00', 'Asia/Jakarta')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-16 11:00:00', 'Asia/Jakarta')->utc(),
    ]);

    $this->actingAs($user)
        ->get(route('calendar.index', ['date' => '2026-11-16']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('calendar/index')
            ->where('view', 'week')
            ->where('active_date', '2026-11-16')
            ->has('events', 1)
            ->where('events.0.title', 'Kuliah Pemrograman Web')
            ->where('events.0.starts_at_local', '2026-11-16T09:00')
        );
});

test('timed event normalizes user local timezone to utc including around local midnight', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);

    // 2026-11-16 00:30 WIB is 2026-11-15 17:30 UTC
    $this->actingAs($user)
        ->post(route('calendar-events.store'), [
            'title' => 'Belajar Tengah Malam',
            'is_all_day' => false,
            'starts_at' => '2026-11-16T00:30',
            'ends_at' => '2026-11-16T01:30',
        ])
        ->assertRedirect();

    $event = CalendarEvent::query()->where('user_id', $user->id)->firstOrFail();

    expect($event->starts_at?->toIso8601ZuluString())->toBe('2026-11-15T17:30:00Z');
    expect($event->ends_at?->toIso8601ZuluString())->toBe('2026-11-15T18:30:00Z');
});

test('all-day event stores local dates without utc timestamps', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);
    $project = Project::factory()->for($user)->create();
    $task = Task::factory()->for($user)->for($project)->create();

    $this->actingAs($user)
        ->post(route('calendar-events.store'), [
            'title' => 'Hackathon Kampus',
            'is_all_day' => true,
            'start_date' => '2026-11-20',
            'end_date' => '2026-11-21',
            'project_id' => $project->id,
            'task_id' => $task->id,
        ])
        ->assertRedirect();

    $event = CalendarEvent::query()->where('user_id', $user->id)->firstOrFail();

    expect($event->is_all_day)->toBeTrue()
        ->and($event->starts_at)->toBeNull()
        ->and($event->ends_at)->toBeNull()
        ->and($event->start_date?->format('Y-m-d'))->toBe('2026-11-20')
        ->and($event->end_date?->format('Y-m-d'))->toBe('2026-11-21');
});

test('recurring weekly event expands occurrences and supports single override and idempotent cancellation', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);

    $series = CalendarEvent::factory()->for($user)->create([
        'title' => 'Rapat Organisasi',
        'starts_at' => CarbonImmutable::parse('2026-11-02 15:00:00', 'Asia/Jakarta')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-02 16:00:00', 'Asia/Jakarta')->utc(),
        'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO,WE',
        'recurrence_until' => '2026-11-30',
    ]);

    // Week of 2026-11-16..2026-11-22 should contain MO (2026-11-16) and WE (2026-11-18)
    $this->actingAs($user)
        ->get(route('calendar.index', ['view' => 'week', 'date' => '2026-11-16']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('events', 2)
            ->where('events.0.recurrence_id', '2026-11-16')
            ->where('events.1.recurrence_id', '2026-11-18')
        );

    // Edit single occurrence on 2026-11-16
    $this->actingAs($user)
        ->patch(route('calendar-events.update', $series), [
            'edit_scope' => 'single',
            'recurrence_id' => '2026-11-16',
            'title' => 'Rapat Khusus UTS',
            'is_all_day' => false,
            'starts_at' => '2026-11-16T16:00',
            'ends_at' => '2026-11-16T17:30',
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->get(route('calendar.index', ['view' => 'week', 'date' => '2026-11-16']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('events', 2)
            ->where('events.0.title', 'Rapat Khusus UTS')
            ->where('events.0.is_override', true)
            ->where('events.1.title', 'Rapat Organisasi')
        );

    // Delete single occurrence 2026-11-18 twice (idempotent)
    $this->actingAs($user)
        ->delete(route('calendar-events.destroy', $series), [
            'edit_scope' => 'single',
            'recurrence_id' => '2026-11-18',
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->delete(route('calendar-events.destroy', $series), [
            'edit_scope' => 'single',
            'recurrence_id' => '2026-11-18',
        ])
        ->assertRedirect();

    $series->refresh();
    expect($series->recurrence_exceptions)->toBe(['2026-11-18']);

    $this->actingAs($user)
        ->get(route('calendar.index', ['view' => 'week', 'date' => '2026-11-16']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('events', 1)
            ->where('events.0.title', 'Rapat Khusus UTS')
        );
});

test('overlapping timed event saves successfully and flashes non-blocking conflict warning', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);

    CalendarEvent::factory()->for($user)->create([
        'title' => 'Kelas Basis Data',
        'starts_at' => CarbonImmutable::parse('2026-11-17 10:00:00', 'Asia/Jakarta')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-17 12:00:00', 'Asia/Jakarta')->utc(),
    ]);

    $response = $this->actingAs($user)
        ->post(route('calendar-events.store'), [
            'title' => 'Mentoring Proyek',
            'is_all_day' => false,
            'starts_at' => '2026-11-17T11:00',
            'ends_at' => '2026-11-17T12:30',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('conflict_warnings', function (array $warnings): bool {
        return count($warnings) === 1 && $warnings[0]['title'] === 'Kelas Basis Data';
    });

    expect(CalendarEvent::query()->where('user_id', $user->id)->count())->toBe(2);
});

test('user cannot update or delete another users calendar event', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $event = CalendarEvent::factory()->for($owner)->create();

    $this->actingAs($intruder)
        ->patch(route('calendar-events.update', $event), [
            'title' => 'Hacked',
        ])
        ->assertForbidden();

    $this->actingAs($intruder)
        ->delete(route('calendar-events.destroy', $event))
        ->assertForbidden();
});
