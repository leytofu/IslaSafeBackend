<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
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

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        return $this->respondWithToken($user);
    }

    /**
     * POST /api/auth/register — self-registration always starts at the
     * least-privileged role ('residents'). Admin/campmanager accounts are seeded.
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
        ]);

        if ($request->hasFile('avatar')) {
            // Replace the previous photo (if any) to avoid orphaned files.
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }
            $data['avatar'] = $request->file('avatar')->store('avatars', 'public');
        } else {
            unset($data['avatar']);
        }

        $user->update($data);

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
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    private function respondWithToken(User $user, int $status = 200): JsonResponse
    {
        $token = $user->createToken('islasafe-admin-panel')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ], $status);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'contact_number' => $user->contact_number,
            'office' => $user->office,
            'jurisdiction' => $user->jurisdiction,
            'avatar_url' => $user->avatar ? Storage::disk('public')->url($user->avatar) : null,
        ];
    }
}
