<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function webLogin(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        // Restrict roles before creating a session or a remember-me cookie.
        if (! Auth::guard('web')->attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'role' => User::ROLE_ADMIN,
        ], $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => ['The credentials are invalid or this account does not have administrator access.'],
            ]);
        }

        $request->session()->regenerate();

        return response()->json(['user' => AuthSession::payload(Auth::guard('web')->user())]);
    }

    public function webLogout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * POST /api/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! $user->password || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        abort_unless($user->hasRoleIn([User::ROLE_RESIDENT, User::ROLE_CAMP_MANAGER]), 403,
            'Administrator accounts must sign in through the web portal.');

        return $this->respondWithToken($user);
    }

    /**
     * POST /api/auth/register — self-registration always starts at the
     * least-privileged role ('residents'). Admins create Camp Managers through
     * the protected team endpoint; administrator accounts are seeded.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => User::ROLE_RESIDENT,
        ]);

        return $this->respondWithToken($user, 201);
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    /**
     * PATCH /api/auth/profile — update the signed-in user's profile.
     *
     * Name, email, contact number, office, and jurisdiction are editable.
     * An optional avatar image (JPG/PNG, max 2 MB) may be uploaded; it is
     * stored on the public disk and the previous file is removed. Role and
     * password are intentionally NOT accepted here — role is assigned by
     * the administrator and password changes go through the OTP recovery
     * flow or a dedicated endpoint.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'contact_number' => ['nullable', 'string', 'max:32'],
            'office' => ['nullable', 'string', 'max:255'],
            'jurisdiction' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'barangay' => ['sometimes', 'required', 'string', 'max:120'],
            'address' => ['sometimes', 'required', 'string', 'max:500'],
        ]);

        if ($user->google_subject && $data['email'] !== $user->email) {
            throw ValidationException::withMessages(['email' => 'The email for a Google-linked account cannot be changed here.']);
        }

        $previousAvatar = $user->avatar;
        $newAvatar = null;
        if ($request->hasFile('avatar')) {
            $newAvatar = $request->file('avatar')->store('avatars', 'public');
            if (! $newAvatar) {
                throw new \RuntimeException('The profile photo could not be stored.');
            }
            $data['avatar'] = $newAvatar;
        } else {
            unset($data['avatar']);
        }

        try {
            $user->update($data);
        } catch (\Throwable $exception) {
            if ($newAvatar) {
                Storage::disk('public')->delete($newAvatar);
            }
            throw $exception;
        }
        if ($newAvatar && $previousAvatar && $previousAvatar !== $newAvatar) {
            Storage::disk('public')->delete($previousAvatar);
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $this->userPayload($user->fresh()),
        ]);
    }

    /**
     * POST /api/auth/logout — revokes the token used for the request.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            return $this->webLogout($request);
        }

        return response()->json(['message' => 'Signed out.']);
    }

    private function respondWithToken(User $user, int $status = 200): JsonResponse
    {
        return AuthSession::respond($user, $status);
    }

    private function userPayload(User $user): array
    {
        return AuthSession::payload($user);
    }
}
