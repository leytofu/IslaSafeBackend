<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role = User::ROLE_ADMIN): User
    {
        return User::factory()->create([
            'password' => Hash::make('secret-password'),
            'role' => $role,
        ]);
    }

    public function test_login_returns_token_and_user_payload(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']])
            ->assertJsonPath('user.role', User::ROLE_ADMIN);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_creates_resident_account_with_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']])
            ->assertJsonPath('user.role', User::ROLE_RESIDENT);

        $this->assertDatabaseHas('users', [
            'email' => 'juan@example.test',
            'role' => User::ROLE_RESIDENT,
        ]);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Duplicate',
            'email' => $user->email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_me_returns_current_user_and_requires_token(): void
    {
        $user = $this->makeUser();

        $this->getJson('/api/auth/me')->assertUnauthorized();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => explode('|', $token)[0],
            'tokenable_id' => $user->id,
        ]);

        // Feature tests share one application container across requests, which
        // would otherwise reuse the guard's cached user. Forget the guards to
        // simulate the fresh request cycle of a real HTTP request.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }
}
