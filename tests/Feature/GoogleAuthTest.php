<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleIdentity;
use Firebase\JWT\JWT;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private static string $privateKey;

    private static array $jwk;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'test-web.apps.googleusercontent.com']);
        Cache::flush();
        Http::preventStrayRequests();
        if (! isset(self::$privateKey)) {
            $options = ['config' => __DIR__.'/../Fixtures/openssl.cnf', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
            $key = openssl_pkey_new($options);
            openssl_pkey_export($key, $privateKey, null, $options);
            self::$privateKey = $privateKey;
            $details = openssl_pkey_get_details($key);
            $encode = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            self::$jwk = ['kty' => 'RSA', 'kid' => 'test-key', 'alg' => 'RS256', 'use' => 'sig',
                'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e'])];
        }
        Http::fake([GoogleIdentity::CERTS_URL => Http::response(['keys' => [self::$jwk]])]);
    }

    private function proof(array $claims = [], string $purpose = 'login'): array
    {
        $challenge = ($purpose === 'login'
            ? $this->postJson('/api/auth/google/challenge')
            : $this->postJson('/api/auth/google/reauth-challenge', ['purpose' => $purpose]))
            ->assertOk()->json();

        return [
            'challenge_id' => $challenge['challenge_id'],
            'id_token' => JWT::encode(array_merge([
                'iss' => 'https://accounts.google.com',
                'aud' => 'test-web.apps.googleusercontent.com',
                'iat' => time(), 'exp' => time() + 3600,
                'sub' => 'google-resident-123', 'email' => 'resident@example.test',
                'email_verified' => true, 'name' => 'Google Resident', 'nonce' => $challenge['nonce'],
            ], $claims), self::$privateKey, 'RS256', 'test-key'),
        ];
    }

    private function googleUser(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'email' => 'resident@example.test', 'role' => User::ROLE_RESIDENT, 'password' => null,
        ], $attributes));
        $user->forceFill(['google_subject' => 'google-resident-123'])->save();

        return $user;
    }

    public function test_first_signup_creates_resident_without_password_and_profile_completion_never_verifies_residency(): void
    {
        $result = $this->postJson('/api/auth/google', array_merge($this->proof(), ['role' => 'admin', 'password' => 'ignored']))
            ->assertCreated()->assertJsonPath('user.role', 'residents')
            ->assertJsonPath('user.has_local_password', false)->assertJsonPath('user.profile_complete', false)
            ->assertJsonPath('user.resident_verified', false)->assertJsonMissingPath('user.google_subject');
        $user = User::findOrFail($result->json('user.id'));
        $this->assertNull($user->password);
        $this->assertSame('google-resident-123', $user->google_subject);
        $this->assertNotNull($user->email_verified_at);
        $this->withToken($result->json('token'))->patchJson('/api/auth/resident-profile', [
            'name' => 'Resident Profile', 'barangay' => 'Pitogo', 'address' => 'Purok 2',
            'role' => 'campmanager', 'resident_verified_at' => now()->toISOString(),
        ])->assertOk()->assertJsonPath('user.profile_complete', true)
            ->assertJsonPath('user.role', 'residents')->assertJsonPath('user.resident_verified', false);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'address' => 'Purok 2', 'password' => null]);
        $this->assertNull($user->fresh()->resident_verified_at);
        $this->patchJson('/api/auth/resident-profile', ['name' => 'Test'])->assertUnprocessable()
            ->assertJsonValidationErrors(['barangay', 'address']);
    }

    public function test_returning_google_login_resolves_by_provider_id_even_if_google_email_changes(): void
    {
        $user = $this->googleUser(['barangay' => 'Pitogo', 'address' => 'Original address']);
        foreach (['resident@example.test', 'new-google-email@example.test'] as $email) {
            $this->postJson('/api/auth/google', $this->proof(['email' => $email]))
                ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('user.address', 'Original address');
        }
        $this->assertDatabaseCount('users', 1);
    }

    public function test_google_only_account_cannot_use_password_login_or_email_recovery_to_set_its_first_password(): void
    {
        Notification::fake();
        $user = $this->googleUser();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'not-a-local-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_challenges', 0);
        $this->assertNull($user->fresh()->password);
    }

    public function test_setting_password_with_fresh_google_proof_preserves_user_and_enables_both_logins(): void
    {
        $user = $this->googleUser(['barangay' => 'Pitogo', 'address' => 'Original']);
        $this->actingAs($user, 'sanctum');
        $this->postJson('/api/auth/password/set', array_merge($this->proof([], 'password'), [
            'password' => 'IslaSafePassword123!', 'password_confirmation' => 'IslaSafePassword123!',
        ]))->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('user.has_local_password', true);
        $this->assertTrue(Hash::check('IslaSafePassword123!', $user->fresh()->password));
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'IslaSafePassword123!'])
            ->assertOk()->assertJsonPath('user.id', $user->id);
        $this->postJson('/api/auth/google', $this->proof())->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_matching_email_does_not_link_until_existing_account_authenticates_and_confirms_password(): void
    {
        $user = User::factory()->create(['role' => 'residents', 'email' => 'resident@example.test', 'password' => 'ExistingPassword123!']);
        $this->postJson('/api/auth/google', $this->proof())->assertConflict();
        $this->assertNull($user->fresh()->google_subject);
        $this->postJson('/api/auth/google/link', $this->proof())->assertUnauthorized();
        $this->actingAs($user, 'sanctum');
        $proof = $this->proof([], 'link');
        $this->postJson('/api/auth/google/link', array_merge($proof, ['current_password' => 'wrong']))
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson('/api/auth/google/link', array_merge($proof, ['current_password' => 'ExistingPassword123!']))
            ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('user.google_linked', true);
        $this->assertTrue(Hash::check('ExistingPassword123!', $user->fresh()->password));
        $this->postJson('/api/auth/google', $this->proof())->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_google_rejects_invalid_signature_issuer_audience_expiration_and_unverified_email(): void
    {
        foreach ([
            ['iss' => 'https://attacker.example'],
            ['aud' => 'wrong-client'],
            ['exp' => time() - 60],
            ['exp' => null],
            ['email_verified' => false],
            ['email_verified' => 'true'],
            ['sub' => ''],
            ['iat' => time() + 500],
            ['nonce' => 'wrong-nonce'],
        ] as $claims) {
            Cache::flush();
            $this->postJson('/api/auth/google', $this->proof($claims))->assertUnprocessable();
        }
        $proof = $this->proof();
        $segments = explode('.', $proof['id_token']);
        $segments[2] = str_repeat('A', strlen($segments[2]));
        $this->postJson('/api/auth/google', array_merge($proof, ['id_token' => implode('.', $segments)]))->assertUnprocessable();
        Cache::flush();
        $this->postJson('/api/auth/google', array_merge($proof, ['id_token' => 'malformed']))->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_proofs_are_single_use_expire_and_are_bound_to_the_action_and_account(): void
    {
        $proof = $this->proof();
        $this->postJson('/api/auth/google', $proof)->assertCreated();
        $this->postJson('/api/auth/google', $proof)->assertUnprocessable();
        $expired = $this->proof();
        DB::table('google_auth_challenges')->where('id', $expired['challenge_id'])->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/auth/google', $expired)->assertUnprocessable();
        $user = User::where('email', 'resident@example.test')->firstOrFail();
        $this->actingAs($user, 'sanctum');
        $wrongPurpose = $this->proof([], 'link');
        $password = ['password' => 'Password123!', 'password_confirmation' => 'Password123!'];
        $this->postJson('/api/auth/password/set', array_merge($wrongPurpose, $password))->assertUnprocessable();
        $otherProof = $this->proof(['sub' => 'other-google-account'], 'password');
        $this->postJson('/api/auth/password/set', array_merge($otherProof, $password))->assertUnprocessable();
        $oldProof = $this->proof(['iat' => time() - 600], 'password');
        $this->postJson('/api/auth/password/set', array_merge($oldProof, $password))->assertUnprocessable();
        $bound = $this->proof([], 'password');
        $this->actingAs(User::factory()->create(['role' => 'residents']), 'sanctum');
        $this->postJson('/api/auth/password/set', array_merge($bound, $password))->assertUnprocessable();
        $this->assertNull($user->fresh()->password);
    }

    public function test_existing_password_cannot_be_replaced_through_first_password_endpoint(): void
    {
        $user = $this->googleUser(['password' => 'Original123!']);
        $this->actingAs($user, 'sanctum');
        $this->postJson('/api/auth/password/set', array_merge($this->proof([], 'password'), [
            'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
        ]))->assertConflict();
        $this->assertTrue(Hash::check('Original123!', $user->fresh()->password));
    }

    public function test_google_auth_never_enters_admin_or_camp_manager_accounts(): void
    {
        foreach (['admin', 'campmanager'] as $role) {
            $user = $this->googleUser(['email' => $role.'@example.test', 'role' => $role]);
            $user->forceFill(['google_subject' => 'subject-'.$role])->save();
            $this->postJson('/api/auth/google', $this->proof(['sub' => 'subject-'.$role, 'email' => $user->email]))->assertForbidden();
            $this->actingAs($user, 'sanctum');
            $this->postJson('/api/auth/google/reauth-challenge', ['purpose' => 'link'])->assertForbidden();
            $this->postJson('/api/auth/password/set', [])->assertForbidden();
            $this->postJson('/api/auth/google/link', [])->assertForbidden();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_google_resident_cannot_enter_privileged_routes_and_logout_revokes_only_current_session(): void
    {
        $login = $this->postJson('/api/auth/google', $this->proof())->assertCreated();
        $second = $this->postJson('/api/auth/google', $this->proof())->assertOk()->json('token');
        $this->withToken($login->json('token'))->getJson('/api/admin/team')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($login->json('token'))->getJson('/api/camp/context')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($login->json('token'))->postJson('/api/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($login->json('token'))->getJson('/api/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($second)->getJson('/api/auth/me')->assertOk();
    }

    public function test_google_subject_has_database_uniqueness(): void
    {
        $this->googleUser();
        $other = User::factory()->create(['role' => 'residents']);
        $this->expectException(UniqueConstraintViolationException::class);
        $other->forceFill(['google_subject' => 'google-resident-123'])->save();
    }

    public function test_password_setup_requires_matching_strong_password_and_the_same_backend_session(): void
    {
        $user = $this->googleUser();
        $first = $user->createToken('device-one')->plainTextToken;
        $second = $user->createToken('device-two')->plainTextToken;
        $this->withToken($first);
        $proof = $this->proof([], 'password');
        $this->postJson('/api/auth/password/set', array_merge($proof, [
            'password' => 'short', 'password_confirmation' => 'short',
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/auth/password/set', array_merge($proof, [
            'password' => 'LongPassword123!', 'password_confirmation' => 'mismatch',
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->app['auth']->forgetGuards();
        $this->withToken($second)->postJson('/api/auth/password/set', array_merge($proof, [
            'password' => 'LongPassword123!', 'password_confirmation' => 'LongPassword123!',
        ]))->assertUnprocessable();
        $this->assertNull($user->fresh()->password);
        $this->app['auth']->forgetGuards();
        $this->withToken($first)->postJson('/api/auth/password/set', array_merge($proof, [
            'password' => 'LongPassword123!', 'password_confirmation' => 'LongPassword123!',
        ]))->assertOk();
    }

    public function test_google_identity_cannot_be_moved_to_a_second_resident_account(): void
    {
        $owner = $this->googleUser(['email' => 'owner@example.test']);
        $other = User::factory()->create(['email' => 'resident@example.test', 'role' => 'residents', 'password' => 'ExistingPassword123!']);
        $this->actingAs($other, 'sanctum');
        $this->postJson('/api/auth/google/link', array_merge($this->proof([], 'link'), [
            'current_password' => 'ExistingPassword123!',
        ]))->assertConflict();
        $this->assertSame('google-resident-123', $owner->fresh()->google_subject);
        $this->assertNull($other->fresh()->google_subject);
    }

    public function test_verification_outage_and_missing_configuration_fail_closed(): void
    {
        $proof = $this->proof();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([GoogleIdentity::CERTS_URL => Http::response([], 503)]);
        $this->postJson('/api/auth/google', $proof)->assertStatus(503);
        config(['services.google.client_id' => null]);
        $this->postJson('/api/auth/google', $proof)->assertStatus(503);
        $this->assertDatabaseCount('users', 0);
    }
}
