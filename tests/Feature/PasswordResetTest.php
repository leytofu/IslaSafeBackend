<?php

namespace Tests\Feature;

use App\Models\PasswordResetAuthorization;
use App\Models\PasswordResetChallenge;
use App\Models\User;
use App\Notifications\OtpCodeNotification;
use App\Services\RecoveryMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
    }

    private function extractOtpFromNotification($notification): string
    {
        $otp = $notification->otpCode;
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otp);

        return $otp;
    }

    public function test_request_otp_returns_generic_response_for_existing_email(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'If an account exists for this email, a verification code has been sent.',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['challenge_id', 'expires_in', 'resend_available_in'],
            ]);

        $this->assertNotEmpty($response->json('data.challenge_id'));
    }

    public function test_request_otp_returns_generic_response_for_non_existing_email(): void
    {
        $response = $this->postJson('/api/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'If an account exists for this email, a verification code has been sent.',
            ]);
    }

    public function test_request_otp_sends_notification_and_creates_challenge(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertOk();

        Notification::assertSentTo($user, OtpCodeNotification::class);

        $this->assertDatabaseHas('password_reset_challenges', [
            'user_id' => $user->id,
            'challenge_id' => $response->json('data.challenge_id'),
            'status' => 'pending',
        ]);
    }

    public function test_verify_otp_with_correct_code_succeeds(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/forgot-password', [
            'email' => $user->email,
        ]);

        $challengeId = $response->json('data.challenge_id');

        $notification = collect(Notification::sent($user, OtpCodeNotification::class))->last();
        $otp = $this->extractOtpFromNotification($notification);

        $verifyResponse = $this->postJson('/api/forgot-password/verify-otp', [
            'challenge_id' => $challengeId,
            'otp' => $otp,
        ]);

        $verifyResponse->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Email verification successful.',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['reset_token', 'expires_in'],
            ]);

        $this->assertNotEmpty($verifyResponse->json('data.reset_token'));

        $challenge = PasswordResetChallenge::where('challenge_id', $challengeId)->first();
        $this->assertEquals('verified', $challenge->status);

        $this->assertDatabaseHas('password_reset_authorizations', [
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
            'used' => false,
        ]);
    }

    public function test_verify_otp_with_incorrect_code_fails(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/forgot-password', [
            'email' => $user->email,
        ]);

        $challengeId = $response->json('data.challenge_id');

        $verifyResponse = $this->postJson('/api/forgot-password/verify-otp', [
            'challenge_id' => $challengeId,
            'otp' => '999999',
        ]);

        $verifyResponse->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'The verification code is invalid.',
            ]);
    }

    public function test_verify_otp_with_expired_challenge_fails(): void
    {
        $user = $this->makeUser();

        $challenge = PasswordResetChallenge::create([
            'user_id' => $user->id,
            'challenge_id' => Str::random(32),
            'otp_hash' => Hash::make('123456'),
            'otp_expires_at' => now()->subMinutes(10),
            'status' => 'pending',
        ]);

        $verifyResponse = $this->postJson('/api/forgot-password/verify-otp', [
            'challenge_id' => $challenge->challenge_id,
            'otp' => '123456',
        ]);

        $verifyResponse->assertStatus(410)
            ->assertJson([
                'success' => false,
                'message' => 'This verification request has expired. Please request a new code.',
            ]);
    }

    public function test_verify_otp_enforces_max_attempts(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/forgot-password', [
            'email' => $user->email,
        ]);

        $challengeId = $response->json('data.challenge_id');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/forgot-password/verify-otp', [
                'challenge_id' => $challengeId,
                'otp' => '999999',
            ]);
        }

        $verifyResponse = $this->postJson('/api/forgot-password/verify-otp', [
            'challenge_id' => $challengeId,
            'otp' => '999999',
        ]);

        $verifyResponse->assertStatus(429)
            ->assertJson([
                'success' => false,
                'message' => 'Too many attempts. Please try again later.',
            ]);
    }

    public function test_resend_otp_generates_new_code(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/forgot-password', [
            'email' => $user->email,
        ]);

        $challengeId = $response->json('data.challenge_id');

        $challenge = PasswordResetChallenge::where('challenge_id', $challengeId)->first();
        $oldOtpHash = $challenge->otp_hash;

        // Bypass cooldown for testing
        $challenge->update(['resend_available_at' => now()->subSecond()]);

        $resendResponse = $this->postJson('/api/forgot-password/resend-otp', [
            'challenge_id' => $challengeId,
        ]);

        $resendResponse->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'If this recovery request is valid, a new verification code has been sent.',
            ]);

        $challenge->refresh();
        $this->assertNotEquals($oldOtpHash, $challenge->otp_hash);
    }

    public function test_resend_otp_enforces_cooldown(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/forgot-password', [
            'email' => $user->email,
        ]);

        $challengeId = $response->json('data.challenge_id');

        $this->postJson('/api/forgot-password/resend-otp', [
            'challenge_id' => $challengeId,
        ]);

        $resendResponse = $this->postJson('/api/forgot-password/resend-otp', [
            'challenge_id' => $challengeId,
        ]);

        $resendResponse->assertStatus(429);
    }

    public function test_reset_password_with_valid_token_succeeds(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/forgot-password', [
            'email' => $user->email,
        ]);

        $challengeId = $response->json('data.challenge_id');

        $notification = collect(Notification::sent($user, OtpCodeNotification::class))->last();
        $otp = $this->extractOtpFromNotification($notification);

        $verifyResponse = $this->postJson('/api/forgot-password/verify-otp', [
            'challenge_id' => $challengeId,
            'otp' => $otp,
        ]);

        $resetToken = $verifyResponse->json('data.reset_token');

        $resetResponse = $this->postJson('/api/forgot-password/reset', [
            'reset_token' => $resetToken,
            'password' => 'new-secure-password-123',
            'password_confirmation' => 'new-secure-password-123',
        ]);

        $resetResponse->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Your password has been reset successfully.',
                'data' => [
                    'redirect_to' => '/login',
                ],
            ]);

        $user->refresh();
        $this->assertTrue(Hash::check('new-secure-password-123', $user->password));
        $this->assertFalse(Hash::check('old-password', $user->password));
    }

    public function test_reset_password_with_invalid_token_fails(): void
    {
        $resetResponse = $this->postJson('/api/forgot-password/reset', [
            'reset_token' => Str::random(48),
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $resetResponse->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'This password reset request is invalid or has expired. Please restart the password recovery process.',
            ]);
    }

    public function test_reset_password_with_reused_token_fails(): void
    {
        $user = $this->makeUser();

        $authorization = PasswordResetAuthorization::create([
            'user_id' => $user->id,
            'challenge_id' => PasswordResetChallenge::create([
                'user_id' => $user->id,
                'challenge_id' => Str::random(32),
                'otp_hash' => Hash::make('123456'),
                'otp_expires_at' => now()->addHour(),
                'status' => 'verified',
            ])->id,
            'reset_token_hash' => Hash::make('test-token-123456'),
            'expires_at' => now()->addHour(),
            'used' => true,
        ]);

        $resetResponse = $this->postJson('/api/forgot-password/reset', [
            'reset_token' => 'test-token-123456',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $resetResponse->assertStatus(422);
    }

    public function test_reset_password_with_mismatched_confirmation_fails(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/forgot-password', [
            'email' => $user->email,
        ]);

        $challengeId = $response->json('data.challenge_id');

        $notification = collect(Notification::sent($user, OtpCodeNotification::class))->last();
        $otp = $this->extractOtpFromNotification($notification);

        $verifyResponse = $this->postJson('/api/forgot-password/verify-otp', [
            'challenge_id' => $challengeId,
            'otp' => $otp,
        ]);

        $resetToken = $verifyResponse->json('data.reset_token');

        $resetResponse = $this->postJson('/api/forgot-password/reset', [
            'reset_token' => $resetToken,
            'password' => 'new-password-123',
            'password_confirmation' => 'different-password-123',
        ]);

        $resetResponse->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_reset_password_revokes_existing_tokens(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['remember_token' => 'old-remember-token'])->save();
        \Illuminate\Support\Facades\DB::table('sessions')->insert([
            'id' => 'old-web-session', 'user_id' => $user->id,
            'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);
        $token = $user->createToken('test-token')->plainTextToken;
        $tokenId = explode('|', $token)[0];

        $response = $this->postJson('/api/forgot-password', [
            'email' => $user->email,
        ]);

        $challengeId = $response->json('data.challenge_id');

        $notification = collect(Notification::sent($user, OtpCodeNotification::class))->last();
        $otp = $this->extractOtpFromNotification($notification);

        $verifyResponse = $this->postJson('/api/forgot-password/verify-otp', [
            'challenge_id' => $challengeId,
            'otp' => $otp,
        ]);

        $resetToken = $verifyResponse->json('data.reset_token');

        $this->postJson('/api/forgot-password/reset', [
            'reset_token' => $resetToken,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $tokenId,
            'tokenable_id' => $user->id,
        ]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertNull($user->fresh()->remember_token);
    }

    public function test_full_recovery_flow_end_to_end(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/forgot-password', ['email' => $user->email]);

        $notification = collect(Notification::sent($user, OtpCodeNotification::class))->last();
        $otp = $this->extractOtpFromNotification($notification);
        $this->assertEquals(6, strlen($otp));

        $challenge = PasswordResetChallenge::where('user_id', $user->id)->first();
        $this->assertNotNull($challenge);
        $this->assertEquals('pending', $challenge->status);

        $verifyResponse = $this->postJson('/api/forgot-password/verify-otp', [
            'challenge_id' => $challenge->challenge_id,
            'otp' => $otp,
        ]);

        $verifyResponse->assertOk();
        $resetToken = $verifyResponse->json('data.reset_token');

        $challenge->refresh();
        $this->assertEquals('verified', $challenge->status);

        $resetResponse = $this->postJson('/api/forgot-password/reset', [
            'reset_token' => $resetToken,
            'password' => 'brand-new-secure-password',
            'password_confirmation' => 'brand-new-secure-password',
        ]);

        $resetResponse->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-secure-password', $user->password));

        $challenge->refresh();
        $this->assertEquals('consumed', $challenge->status);
    }

    public function test_recovery_mail_is_sent_without_a_queue_worker(): void
    {
        Notification::swap(new ChannelManager($this->app));
        Queue::fake();
        $user = $this->makeUser();
        $sent = 0;
        Mail::mailer()->getSymfonyTransport();
        $this->app['events']->listen(MessageSent::class, function () use (&$sent) {
            $sent++;
        });

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        $this->assertSame(1, $sent);
        Queue::assertNothingPushed();
    }

    public function test_mail_transport_failure_does_not_claim_a_code_was_sent(): void
    {
        $this->mock(RecoveryMail::class)->shouldReceive('ensureReady')->andThrow(new \RuntimeException('SMTP unavailable'));
        $user = $this->makeUser();

        foreach ([$user->email, 'unknown@example.test'] as $email) {
            $this->postJson('/api/forgot-password', ['email' => $email])
                ->assertStatus(503)->assertJsonPath('success', false);
        }
        $this->assertDatabaseCount('password_reset_challenges', 0);
        Notification::assertNothingSent();
    }

    public function test_initial_send_failure_invalidates_the_unsent_challenge(): void
    {
        $user = $this->makeUser();
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('Delivery failed'));

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertStatus(503);
        $this->assertDatabaseHas('password_reset_challenges', ['user_id' => $user->id, 'status' => 'consumed']);
    }

    public function test_failed_resend_preserves_the_previous_code(): void
    {
        $user = $this->makeUser();
        $response = $this->postJson('/api/forgot-password', ['email' => $user->email]);
        $challenge = PasswordResetChallenge::where('challenge_id', $response->json('data.challenge_id'))->firstOrFail();
        $notification = collect(Notification::sent($user, OtpCodeNotification::class))->last();
        $code = $notification->otpCode;
        $hash = $challenge->otp_hash;
        $challenge->update(['resend_available_at' => now()->subSecond()]);
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('Delivery failed'));

        $this->postJson('/api/forgot-password/resend-otp', ['challenge_id' => $challenge->challenge_id])->assertStatus(503);
        $this->assertSame($hash, $challenge->fresh()->otp_hash);
        $this->postJson('/api/forgot-password/verify-otp', ['challenge_id' => $challenge->challenge_id, 'otp' => $code])->assertOk();
    }

    public function test_expired_challenge_cannot_be_resurrected_by_resend(): void
    {
        $user = $this->makeUser();
        $challenge = PasswordResetChallenge::create([
            'user_id' => $user->id,
            'challenge_id' => Str::random(32),
            'otp_hash' => Hash::make('123456'),
            'otp_expires_at' => now()->subMinute(),
            'status' => 'pending',
        ]);
        $this->postJson('/api/forgot-password/resend-otp', ['challenge_id' => $challenge->challenge_id])->assertStatus(410);
        Notification::assertNothingSent();
    }

    public function test_unknown_email_receives_a_decoy_challenge_of_the_same_length(): void
    {
        RateLimiter::clear('password-reset:unknown@example.test');
        $this->postJson('/api/forgot-password', ['email' => 'unknown@example.test'])
            ->assertOk()->assertJsonPath('data.challenge_id', fn ($value) => is_string($value) && strlen($value) === 32);
        $this->assertDatabaseCount('password_reset_challenges', 0);
    }
}
