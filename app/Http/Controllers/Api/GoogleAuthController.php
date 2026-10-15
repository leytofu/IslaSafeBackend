<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthSession;
use App\Services\GoogleIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GoogleAuthController extends Controller
{
    public function __construct(private readonly GoogleIdentity $google) {}

    public function challenge(Request $request): JsonResponse
    {
        return $this->issueChallenge($request, 'login');
    }

    public function reauthChallenge(Request $request): JsonResponse
    {
        $data = $request->validate(['purpose' => ['required', Rule::in(['link', 'password'])]]);

        return $this->issueChallenge($request, $data['purpose']);
    }

    private function issueChallenge(Request $request, string $purpose): JsonResponse
    {
        abort_unless(config('services.google.client_id'), 503, 'Google sign-in is not configured.');
        $id = (string) Str::uuid();
        $nonce = hash('sha256', random_bytes(32));
        // Bound the table's lifetime without retaining identity tokens.
        DB::table('google_auth_challenges')->where('expires_at', '<', now())->delete();
        DB::table('google_auth_challenges')->insert([
            'id' => $id, 'nonce' => $nonce, 'purpose' => $purpose,
            'user_id' => $purpose === 'login' ? null : $request->user()->id,
            'access_token_id' => $purpose === 'login' ? null : $request->user()->currentAccessToken()?->id,
            'expires_at' => now()->addMinutes(5),
        ]);

        return response()->json(['challenge_id' => $id, 'nonce' => $nonce, 'expires_in' => 300]);
    }

    private function verified(Request $request): array
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'id_token' => ['required', 'string', 'max:10000'],
        ]);

        return $this->google->verify($data['id_token']);
    }

    private function consume(Request $request, array $claims, string $purpose): void
    {
        $challenge = DB::table('google_auth_challenges')->where('id', $request->input('challenge_id'))->lockForUpdate()->first();
        if (! $challenge || $challenge->purpose !== $purpose || $challenge->consumed_at
            || now()->gte($challenge->expires_at)
            || ! hash_equals($challenge->nonce, $claims['nonce'])
            || ($purpose !== 'login' && (
                $challenge->user_id !== $request->user()->id
                || $challenge->access_token_id !== $request->user()->currentAccessToken()?->id
                || $claims['iat'] < time() - 300
            ))) {
            throw ValidationException::withMessages(['id_token' => 'Google authorization expired or was already used. Please try Google sign-in again.']);
        }
        DB::table('google_auth_challenges')->where('id', $challenge->id)->update(['consumed_at' => now()]);
    }

    public function login(Request $request): JsonResponse
    {
        $claims = $this->verified($request);
        // A concurrent signup can win the email / provider unique constraint.
        // Retry the rolled-back transaction, resolving by provider ID only.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return DB::transaction(function () use ($request, $claims) {
                    $this->consume($request, $claims, 'login');
                    $user = User::where('google_subject', $claims['sub'])->lockForUpdate()->first();
                    $created = false;
                    if (! $user) {
                        abort_if(User::withTrashed()->where('email', $claims['email'])->orWhere('google_subject', $claims['sub'])->exists(),
                            409, 'Sign in to your existing IslaSafe account with its IslaSafe password, then link Google in account settings. If you cannot sign in, contact support.');
                        $user = new User;
                        $user->forceFill([
                            'name' => is_string($claims['name'] ?? null) && trim($claims['name']) !== ''
                                ? mb_substr($claims['name'], 0, 255) : 'Resident',
                            'email' => $claims['email'], 'password' => null,
                            'google_subject' => $claims['sub'], 'role' => User::ROLE_RESIDENT,
                            'email_verified_at' => now(),
                        ])->save();
                        $created = true;
                    }
                    abort_unless($user->role === User::ROLE_RESIDENT, 403, 'Google sign-in is available to residents only.');

                    return AuthSession::respond($user, $created ? 201 : 200);
                }, 3);
            } catch (UniqueConstraintViolationException) {
                if ($attempt === 1) {
                    abort(409, 'Account creation conflicted with another request. Please sign in again.');
                }
            }
        }
        abort(409);
    }

    public function link(Request $request): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'string', 'max:255']]);
        $claims = $this->verified($request);
        try {
            return DB::transaction(function () use ($request, $claims) {
                $this->consume($request, $claims, 'link');
                $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                if (! $user->password || ! Hash::check($request->input('current_password'), $user->password)) {
                    throw ValidationException::withMessages(['current_password' => 'Enter your current IslaSafe password to link Google.']);
                }
                abort_unless(strcasecmp($user->email, $claims['email']) === 0, 422, 'Choose the Google account matching your IslaSafe email address.');
                abort_if($user->google_subject && $user->google_subject !== $claims['sub'], 409, 'This IslaSafe account is already linked to another Google identity.');
                abort_if(User::withTrashed()->where('google_subject', $claims['sub'])->where('id', '!=', $user->id)->exists(), 409, 'This Google identity is already linked to an account.');
                $user->forceFill(['google_subject' => $claims['sub'], 'email_verified_at' => now()])->save();

                return response()->json(['message' => 'Google linked. You can now use either sign-in method.', 'user' => AuthSession::payload($user)]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'This Google identity is already linked to an account.');
        }
    }

    public function setPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'max:255', 'confirmed']]);
        $claims = $this->verified($request);

        return DB::transaction(function () use ($request, $claims, $data) {
            $this->consume($request, $claims, 'password');
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_if($user->password !== null, 409, 'An IslaSafe password already exists. Use the secure password recovery flow to change it.');
            abort_unless($user->google_subject && hash_equals($user->google_subject, $claims['sub']), 422, 'Reauthenticate with the Google account linked to this IslaSafe account.');
            $user->forceFill(['password' => $data['password']])->save();

            return response()->json(['message' => 'IslaSafe password set. You can now use either sign-in method.', 'user' => AuthSession::payload($user)]);
        }, 3);
    }

    public function completeProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'barangay' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:500'],
            'contact_number' => ['nullable', 'string', 'max:32'],
        ]);
        // Self-reported address details do not establish verified residency.
        $request->user()->update($data);

        return response()->json(['user' => AuthSession::payload($request->user()->fresh())]);
    }
}
