<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost:5173'], 'session.secure' => true]);
        $this->withCredentials()->withHeader('Origin', 'http://localhost:5173');
        // Exercise real CSRF checks, which Laravel normally skips in tests.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests() { return false; }
        });
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken {
            protected function runningUnitTests() { return false; }
        });
    }

    private function csrf(): string
    {
        $response = $this->getJson('/sanctum/csrf-cookie')->assertNoContent();
        $cookie = $response->getCookie(config('session.cookie'), false);
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        $xsrf = $response->getCookie('XSRF-TOKEN', false)->getValue();
        $this->withHeader('X-XSRF-TOKEN', $xsrf);

        return $cookie->getValue();
    }

    public function test_admin_cookie_session_restores_and_logout_invalidates_it(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'password' => 'secret-password']);
        $oldCookie = $this->csrf();
        $login = $this->postJson('/api/auth/web/login', [
            'email' => $admin->email, 'password' => 'secret-password', 'remember' => true,
        ])->assertOk()->assertJsonMissingPath('token')->assertJsonPath('user.role', 'admin');
        $session = $login->getCookie(config('session.cookie'), false);
        $this->assertTrue($session->isHttpOnly());
        $this->assertTrue($session->isSecure());
        $this->assertSame('lax', $session->getSameSite());
        $this->assertNotSame($oldCookie, $session->getValue());
        $remember = collect($login->headers->getCookies())->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));
        $this->assertNotNull($remember);
        $this->assertTrue($remember->isHttpOnly());
        $this->assertTrue($remember->isSecure());
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->withUnencryptedCookie($session->getName(), $session->getValue());
        $this->withHeader('X-XSRF-TOKEN', $login->getCookie('XSRF-TOKEN', false)->getValue());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.id', $admin->id);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/web/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_login_rejects_missing_or_invalid_csrf(): void
    {
        $this->postJson('/api/auth/web/login', [])->assertStatus(419);
        $this->csrf();
        $this->withHeader('X-XSRF-TOKEN', 'invalid');
        $this->postJson('/api/auth/web/login', [])->assertStatus(419);
    }

    public function test_remember_cookie_restores_admin_after_browser_session_is_gone(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'password' => 'secret-password']);
        $this->csrf();
        $login = $this->postJson('/api/auth/web/login', [
            'email' => $admin->email, 'password' => 'secret-password', 'remember' => true,
        ])->assertOk();
        $remember = collect($login->headers->getCookies())->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));
        $this->unencryptedCookies = [$remember->getName() => $remember->getValue()];
        $this->app['session']->driver()->flush();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.id', $admin->id);
    }

    public function test_unremembered_login_uses_a_browser_session_cookie(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'password' => 'secret-password']);
        $this->csrf();
        $login = $this->postJson('/api/auth/web/login', [
            'email' => $admin->email, 'password' => 'secret-password', 'remember' => false,
        ])->assertOk();
        $this->assertSame(0, $login->getCookie(config('session.cookie'), false)->getExpiresTime());
        $this->assertFalse(collect($login->headers->getCookies())->contains(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_')));
    }

    public function test_non_admin_accounts_cannot_create_web_sessions(): void
    {
        $this->csrf();
        foreach ([User::ROLE_RESIDENT, User::ROLE_CAMP_MANAGER] as $role) {
            $user = User::factory()->create(['role' => $role, 'password' => 'secret-password']);
            $this->postJson('/api/auth/web/login', [
                'email' => $user->email, 'password' => 'secret-password',
            ])->assertUnprocessable();
            $this->assertGuest('web');
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_cannot_request_a_mobile_token(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'password' => 'secret-password']);
        $this->withoutHeader('Origin')->postJson('/api/auth/login', [
            'email' => $admin->email, 'password' => 'secret-password',
        ])->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_mobile_bearer_authentication_and_revocation_do_not_require_cookies(): void
    {
        $this->withoutHeader('Origin');
        foreach ([User::ROLE_RESIDENT, User::ROLE_CAMP_MANAGER] as $role) {
            $user = User::factory()->create(['role' => $role, 'password' => 'secret-password']);
            $login = $this->postJson('/api/auth/login', [
                'email' => $user->email, 'password' => 'secret-password',
            ])->assertOk()->assertCookieMissing(config('session.cookie'));
            $token = $login->json('token');
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.role', $role);
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/auth/logout')->assertOk();
            $this->app['auth']->forgetGuards();
            $this->getJson('/api/auth/me')->assertUnauthorized();
            $this->withoutHeader('Authorization');
        }
    }

    public function test_authenticated_mutations_require_csrf_and_allow_credentialed_cors(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->csrf();
        $this->actingAs($admin, 'web')->withoutHeader('X-XSRF-TOKEN');
        $this->postJson('/api/auth/web/logout')->assertStatus(419);
        $this->patchJson('/api/auth/profile', [])->assertStatus(419);
        $this->getJson('/sanctum/csrf-cookie')->assertHeader('Access-Control-Allow-Credentials', 'true')
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }
}
