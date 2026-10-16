<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetAuthorization;
use App\Models\PasswordResetChallenge;
use App\Models\User;
use App\Notifications\OtpCodeNotification;
use App\Services\RecoveryMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    public function __construct(private readonly RecoveryMail $recoveryMail) {}

    private const OTP_LENGTH = 6;

    private const OTP_EXPIRY_MINUTES = 5;

    private const RESEND_COOLDOWN_SECONDS = 60;

    private const MAX_OTP_ATTEMPTS = 5;

    private const RESET_TOKEN_EXPIRY_MINUTES = 10;

    private const RATE_LIMIT_KEY_PREFIX = 'password-reset:';

    /**
     * POST /api/forgot-password
     *
     * Initiates a password recovery challenge by generating a 6-digit OTP,
     * storing its hash, and emailing it immediately. Account lookup responses
     * are generic; transport outages return a retryable service error.
     */
    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = trim($data['email']);
        $rateLimitKey = self::RATE_LIMIT_KEY_PREFIX.$email;

        // Rate limit: max 3 requests per hour per email
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please try again later.',
                'errors' => ['email' => ['Too many password reset requests. Please wait '.$seconds.' seconds.']],
            ], 429);
        }

        try {
            $this->recoveryMail->ensureReady();
        } catch (\Throwable $e) {
            Log::error('Recovery mail transport unavailable', ['exception' => $e::class]);

            return $this->deliveryUnavailable();
        }

        // A Google-only account must reauthenticate with Google before its
        // first local password is set. Email recovery cannot bypass that step.
        $user = User::where('email', $email)->whereNotNull('password')->first();

        // Always return generic response to prevent enumeration
        $genericResponse = [
            'success' => true,
            'message' => 'If an account exists for this email, a verification code has been sent.',
            'data' => [
                'challenge_id' => Str::random(32),
                'expires_in' => self::OTP_EXPIRY_MINUTES * 60,
                'resend_available_in' => self::RESEND_COOLDOWN_SECONDS,
            ],
        ];

        if (! $user) {
            // Increment rate limiter even for non-existent emails to prevent enumeration
            RateLimiter::hit($rateLimitKey, 3600);

            return response()->json($genericResponse);
        }

        // Check for existing pending challenge and invalidate it
        $existingChallenge = PasswordResetChallenge::where('user_id', $user->id)
            ->where('status', 'pending')
            ->where('otp_expires_at', '>', Carbon::now())
            ->first();

        if ($existingChallenge) {
            $existingChallenge->update(['status' => 'consumed']);
        }

        // Generate cryptographically secure 6-digit OTP
        $otp = random_int(100000, 999999);
        $otpHash = Hash::make((string) $otp);
        $challengeId = Str::random(32);
        $expiresAt = Carbon::now()->addMinutes(self::OTP_EXPIRY_MINUTES);
        $resendAvailableAt = Carbon::now()->addSeconds(self::RESEND_COOLDOWN_SECONDS);

        DB::transaction(function () use ($user, $challengeId, $otpHash, $expiresAt, $resendAvailableAt) {
            PasswordResetChallenge::create([
                'user_id' => $user->id,
                'challenge_id' => $challengeId,
                'otp_hash' => $otpHash,
                'otp_expires_at' => $expiresAt,
                'resend_available_at' => $resendAvailableAt,
                'status' => 'pending',
            ]);
        });

        // Send OTP via email
        try {
            $user->notify(new OtpCodeNotification((string) $otp, self::OTP_EXPIRY_MINUTES));
        } catch (\Throwable $e) {
            Log::error('Failed to send OTP email', [
                'user_id' => $user->id,
                'exception' => $e::class,
            ]);
            PasswordResetChallenge::where('challenge_id', $challengeId)->update(['status' => 'consumed']);
            RateLimiter::hit($rateLimitKey, 3600);

            return $this->deliveryUnavailable();
        }

        // Increment rate limiter
        RateLimiter::hit($rateLimitKey, 3600);

        $response = $genericResponse;
        $response['data']['challenge_id'] = $challengeId;

        return response()->json($response);
    }

    /**
     * POST /api/forgot-password/verify-otp
     *
     * Verifies the OTP against the stored hash. On success, issues a
     * short-lived reset token for the password change phase.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string', 'size:32'],
            'otp' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ]);

        $challenge = PasswordResetChallenge::where('challenge_id', $data['challenge_id'])
            ->where('status', 'pending')
            ->first();

        if (! $challenge) {
            return response()->json([
                'success' => false,
                'message' => 'This verification request has expired. Please request a new code.',
            ], 410);
        }

        // Check expiration
        if (Carbon::now()->gt($challenge->otp_expires_at)) {
            $challenge->update(['status' => 'expired']);

            return response()->json([
                'success' => false,
                'message' => 'This verification request has expired. Please request a new code.',
            ], 410);
        }

        // Check attempt limit
        if ($challenge->attempts >= self::MAX_OTP_ATTEMPTS) {
            $challenge->update(['status' => 'consumed']);

            return response()->json([
                'success' => false,
                'message' => 'Too many attempts. Please try again later.',
            ], 429);
        }

        // Increment attempts
        $challenge->increment('attempts');

        // Verify OTP
        if (! Hash::check($data['otp'], $challenge->otp_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'The verification code is invalid.',
                'errors' => ['otp' => ['The verification code is invalid.']],
            ], 422);
        }

        // OTP valid - mark challenge as verified and create reset authorization
        $challenge->update(['status' => 'verified']);

        $resetToken = Str::random(48);
        $resetTokenHash = Hash::make($resetToken);
        $expiresAt = Carbon::now()->addMinutes(self::RESET_TOKEN_EXPIRY_MINUTES);

        $authorization = PasswordResetAuthorization::create([
            'user_id' => $challenge->user_id,
            'challenge_id' => $challenge->id,
            'reset_token_hash' => $resetTokenHash,
            'expires_at' => $expiresAt,
            'used' => false,
        ]);

        Log::info('OTP verified, reset token issued', [
            'user_id' => $challenge->user_id,
            'challenge_id' => $challenge->challenge_id,
            'authorization_id' => $authorization->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Email verification successful.',
            'data' => [
                'reset_token' => $resetToken,
                'expires_in' => self::RESET_TOKEN_EXPIRY_MINUTES * 60,
            ],
        ]);
    }

    /**
     * POST /api/forgot-password/resend-otp
     *
     * Generates a new OTP for an existing challenge, invalidating the previous one.
     * Enforces resend cooldown and rate limits.
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string', 'size:32'],
        ]);

        $challenge = PasswordResetChallenge::where('challenge_id', $data['challenge_id'])
            ->where('status', 'pending')
            ->first();

        if (! $challenge) {
            return response()->json([
                'success' => false,
                'message' => 'This verification request has expired. Please request a new code.',
            ], 410);
        }

        // Check resend cooldown
        if ($challenge->resend_available_at && Carbon::now()->lt($challenge->resend_available_at)) {
            $seconds = Carbon::now()->diffInSeconds($challenge->resend_available_at, false);

            return response()->json([
                'success' => false,
                'message' => 'Please wait before requesting a new code.',
                'errors' => ['challenge_id' => ['Please wait '.$seconds.' seconds before resending.']],
            ], 429);
        }

        $user = $challenge->user;

        if (Carbon::now()->gte($challenge->otp_expires_at)) {
            $challenge->update(['status' => 'expired']);

            return response()->json(['success' => false, 'message' => 'This verification request has expired. Please request a new code.'], 410);
        }

        // Generate new OTP
        $otp = random_int(100000, 999999);
        $otpHash = Hash::make((string) $otp);
        $expiresAt = Carbon::now()->addMinutes(self::OTP_EXPIRY_MINUTES);
        $resendAvailableAt = Carbon::now()->addSeconds(self::RESEND_COOLDOWN_SECONDS);

        // Send new OTP
        try {
            $this->recoveryMail->ensureReady();
            $user->notify(new OtpCodeNotification((string) $otp, self::OTP_EXPIRY_MINUTES));
        } catch (\Throwable $e) {
            Log::error('Failed to send OTP email on resend', [
                'user_id' => $user->id,
                'exception' => $e::class,
            ]);

            return $this->deliveryUnavailable();
        }

        // Preserve the previous working code if resending fails.
        $challenge->update([
            'otp_hash' => $otpHash,
            'otp_expires_at' => $expiresAt,
            'resend_available_at' => $resendAvailableAt,
            'attempts' => 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'If this recovery request is valid, a new verification code has been sent.',
            'data' => [
                'expires_in' => self::OTP_EXPIRY_MINUTES * 60,
                'resend_available_in' => self::RESEND_COOLDOWN_SECONDS,
            ],
        ]);
    }

    /**
     * POST /api/forgot-password/reset
     *
     * Resets the user's password using a valid, single-use reset token
     * issued after successful OTP verification.
     */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reset_token' => ['required', 'string', 'size:48'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $authorization = PasswordResetAuthorization::where('used', false)
            ->where('expires_at', '>', Carbon::now())
            ->get()
            ->first(function ($auth) use ($data) {
                return Hash::check($data['reset_token'], $auth->reset_token_hash);
            });

        if (! $authorization || ! $authorization->user?->password) {
            return response()->json([
                'success' => false,
                'message' => 'This password reset request is invalid or has expired. Please restart the password recovery process.',
            ], 422);
        }

        $user = $authorization->user;

        DB::transaction(function () use ($user, $authorization, $data) {
            // Update password (hashed via model cast)
            $user->forceFill(['password' => $data['password']])->save();

            // Revoke all API tokens
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->forceFill(['remember_token' => null])->save();

            // Mark authorization as used
            $authorization->update(['used' => true]);

            // Mark challenge as consumed
            $authorization->challenge->update(['status' => 'consumed']);
        });

        Log::info('Password reset completed', [
            'user_id' => $user->id,
            'authorization_id' => $authorization->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Your password has been reset successfully.',
            'data' => [
                'redirect_to' => '/login',
            ],
        ]);
    }

    private function deliveryUnavailable(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'We could not send a verification code right now. Please try again shortly.',
        ], 503);
    }
}
