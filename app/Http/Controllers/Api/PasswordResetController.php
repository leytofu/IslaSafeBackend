<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;

class PasswordResetController extends Controller
{
    /**
     * POST /api/auth/forgot-password
     *
     * Validates the address, then asks Laravel's password broker to generate a
     * secure (hashed, single-use, 60-minute) reset token and email a reset link
     * through the configured mailer. The response is deliberately identical for
     * known and unknown addresses so the endpoint cannot be used to enumerate
     * accounts.
     */
    public function email(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $status = Password::broker()->sendResetLink($data);

        // The token itself must never reach the logs — only the broker result.
        Log::info('Password reset link request handled', [
            'email' => $data['email'],
            'status' => $status,
        ]);

        return response()->json([
            'message' => 'If an account exists for that address, a password reset link has been sent.',
            'expires_in_minutes' => (int) config('auth.passwords.users.expire'),
            'resend_after_seconds' => (int) config('auth.passwords.users.throttle'),
        ]);
    }

    /**
     * POST /api/auth/reset-password
     *
     * Exchanges a valid emailed token for a new password. The broker verifies
     * the token hash and its expiry, consumes the token (so it can never be
     * reused), and invokes the callback to persist the new password.
     */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // 'confirmed' reads this field but does not include it in the
            // validated payload, so it must be declared explicitly.
            'password_confirmation' => ['required', 'string'],
        ]);

        $status = Password::broker()->reset(
            [
                'email' => $data['email'],
                'password' => $data['password'],
                'password_confirmation' => $data['password_confirmation'],
                'token' => $data['token'],
            ],
            function (CanResetPassword $user, string $password): void {
                // The 'hashed' cast on the User model hashes on assignment.
                $user->forceFill(['password' => $password])->save();

                // A password change must terminate every existing API session.
                if ($user instanceof HasApiTokens) {
                    $user->tokens()->delete();
                }
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            Log::info('Password reset rejected', ['status' => $status]);

            // One generic answer for expired, used, forged, or mismatched
            // tokens — never leak which check failed.
            throw ValidationException::withMessages([
                'email' => ['This password reset link is invalid or has expired. Please request a new one.'],
            ]);
        }

        return response()->json([
            'message' => 'Your password has been updated. You can now sign in.',
        ]);
    }
}
