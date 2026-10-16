<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class AuthSession
{
    public static function payload(User $user): array
    {
        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'role' => $user->role, 'contact_number' => $user->contact_number,
            'office' => $user->office, 'jurisdiction' => $user->jurisdiction,
            'avatar_url' => $user->avatar
                ? (config('filesystems.disks.public.driver') === 'local'
                    ? url('/storage/'.$user->avatar)
                    : Storage::disk('public')->url($user->avatar))
                : null,
            'has_local_password' => $user->password !== null,
            'google_linked' => $user->google_subject !== null,
            'barangay' => $user->barangay, 'address' => $user->address,
            'profile_complete' => (bool) ($user->barangay && $user->address),
            'resident_verified' => $user->resident_verified_at !== null,
        ];
    }

    public static function respond(User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'token' => $user->createToken('islasafe-session')->plainTextToken,
            'user' => self::payload($user),
        ], $status);
    }
}
