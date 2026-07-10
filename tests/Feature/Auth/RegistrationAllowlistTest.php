<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationAllowlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_outside_the_allowlist_is_rejected(): void
    {
        config(['auth.allowed_registration_emails' => ['mats@beet.se', 'lexus.bergsten@gmail.com']]);

        $response = $this->from('/register')->post('/register', [
            'name' => 'Intruder',
            'email' => 'stranger@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'stranger@example.com']);
    }

    public function test_allowlisted_email_can_register(): void
    {
        config(['auth.allowed_registration_emails' => ['mats@beet.se', 'lexus.bergsten@gmail.com']]);

        $response = $this->post('/register', [
            'name' => 'Mats',
            'email' => 'mats@beet.se',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertDatabaseHas('users', ['email' => 'mats@beet.se']);
    }

    public function test_empty_allowlist_allows_any_email(): void
    {
        config(['auth.allowed_registration_emails' => []]);

        $this->post('/register', [
            'name' => 'Anyone',
            'email' => 'anyone@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'anyone@example.com']);
    }
}
