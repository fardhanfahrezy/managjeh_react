<?php

use App\Models\User;

test('new users have default timezone and quiet hours preferences', function () {
    $user = User::factory()->create()->fresh();

    expect($user)->not->toBeNull()
        ->and($user->timezone)->toBe('Asia/Jakarta')
        ->and($user->whatsapp_number)->toBeNull()
        ->and($user->whatsapp_verified_at)->toBeNull()
        ->and($user->whatsapp_enabled)->toBeFalse()
        ->and($user->reminder_quiet_start)->toStartWith('00:00')
        ->and($user->reminder_quiet_end)->toStartWith('05:00')
        ->and($user->canReceiveWhatsAppReminders())->toBeFalse();
});

test('user can update timezone, whatsapp number, and quiet hours on profile', function () {
    $user = User::factory()->withVerifiedWhatsApp('628111111111')->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => 'Asia/Makassar',
            'whatsapp_number' => '6281234567890',
            'whatsapp_enabled' => true,
            'reminder_quiet_start' => '23:00',
            'reminder_quiet_end' => '05:30',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->timezone)->toBe('Asia/Makassar')
        ->and($user->whatsapp_number)->toBe('6281234567890')
        ->and($user->whatsapp_verified_at)->toBeNull()
        ->and($user->canReceiveWhatsAppReminders())->toBeFalse()
        ->and($user->reminder_quiet_start)->toStartWith('23:00')
        ->and($user->reminder_quiet_end)->toStartWith('05:30');
});

test('invalid timezone and invalid whatsapp number are rejected', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => 'Invalid/Timezone_Name',
            'whatsapp_number' => 'not-a-phone-number',
        ]);

    $response
        ->assertSessionHasErrors(['timezone', 'whatsapp_number'])
        ->assertRedirect(route('profile.edit'));
});

test('public registration routes are disabled per D-01', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Public User',
        'email' => 'public@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});

test('user can be provisioned via artisan command per D-01', function () {
    $this->artisan('user:provision', [
        '--name' => 'Provisioned Student',
        '--email' => 'student@managjeh.test',
        '--password' => 'StrongPass#2026!',
        '--timezone' => 'Asia/Jakarta',
    ])->assertSuccessful();

    $user = User::query()->where('email', 'student@managjeh.test')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Provisioned Student')
        ->and($user->timezone)->toBe('Asia/Jakarta')
        ->and($user->email_verified_at)->not->toBeNull();
});
