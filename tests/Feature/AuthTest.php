<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_and_session_login_me_logout()
    {
        // Register
        $res = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
        $res->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);

        // Session login should have set cookie; test /api/auth/me
        $me = $this->getJson('/api/auth/me');
        $me->assertStatus(200);
        $me->assertJsonPath('meta.auth.user.email', 'test@example.com');

        // Logout
        $logout = $this->postJson('/api/auth/logout');
        $logout->assertStatus(200);

        // After logout, me should return null user
        $me2 = $this->getJson('/api/auth/me');
        $me2->assertStatus(200);
        $this->assertNull($me2->json('meta.auth.user'));
    }

    public function test_token_login_and_revoke()
    {
        // Create user with factory default (factory hashes default password 'password')
        $user = User::factory()->create([
            'email' => 'token@test.com',
        ]);

        // Token login
        $res = $this->postJson('/api/auth/token-login', [
            'email' => 'token@test.com',
            'password' => 'password',
            'device_name' => 'phpunit',
        ]);
        $res->assertStatus(200);
        $token = $res->json('data.token');
        $this->assertIsString($token);

        // Use token to access a protected route (me) via Authorization header
        $me = $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson('/api/auth/me');
        $me->assertStatus(200);
        $me->assertJsonPath('meta.auth.user.email', 'token@test.com');

        // Revoke token
        $revoke = $this->withHeaders(['Authorization' => "Bearer {$token}"])->postJson('/api/auth/revoke-token');
        $revoke->assertStatus(200);

        // Token should no longer grant access
        $me2 = $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson('/api/auth/me');
        $me2->assertStatus(401);
    }
}
