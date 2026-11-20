<?php

use App\Models\User;

test('web responses include security headers and strict nonce-based CSP outside local environment', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('ai-chat.index'));

    $response->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');

    $csp = (string) $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("worker-src 'self'")
        ->and($csp)->toContain("manifest-src 'self'")
        ->and($csp)->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9+\/=_-]+'/", 'Strict nonce script-src expected')
        ->and($csp)->not->toContain("script-src 'self' 'unsafe-inline'");

    // Extract nonce from header and confirm inline script tag in HTML carries the exact same nonce.
    preg_match("/'nonce-([^']+)'/", $csp, $matches);
    expect($matches[1] ?? '')->not->toBe('');
    $response->assertSee('nonce="'.$matches[1].'"', false);
});

test('offline shell route never leaks authenticated user data into cacheable HTML', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('offline-shell'));

    $response->assertOk()
        ->assertDontSee($user->email);
});

test('https web requests include Strict-Transport-Security header', function () {
    $response = $this->get('https://localhost/login');

    $response->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
