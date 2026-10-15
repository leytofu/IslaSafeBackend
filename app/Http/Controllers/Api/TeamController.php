<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($user->role === User::ROLE_CAMP_MANAGER, 403, 'Only Camp Manager accounts can be edited here.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:255', 'confirmed'],
            'role' => ['prohibited'],
        ]);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        DB::transaction(function () use ($user, $data) {
            $user->update($data);
            if (isset($data['password'])) {
                $user->tokens()->delete();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                DB::table('password_reset_challenges')->where('user_id', $user->id)->delete();
            }
        });

        return response()->json([
            'message' => 'Camp Manager account updated.'.(isset($data['password']) ? ' They must sign in again with the new password.' : ''),
            'user' => $user->only(['id', 'name', 'email', 'role']),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        abort_unless($user->role === User::ROLE_CAMP_MANAGER, 403, 'Only Camp Manager accounts can be deleted here.');
        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->campAssignment()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_challenges')->where('user_id', $user->id)->delete();
            // Keep the user reference for registration and movement history.
            $user->delete();
        });

        return response()->json(['message' => 'Camp Manager account deleted. Their access and center assignment have been removed.']);
    }

    public function index(): JsonResponse
    {
        return response()->json(['users' => User::query()
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_CAMP_MANAGER])
            ->orderBy('role')->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'email', 'role'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'role' => ['prohibited'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => User::ROLE_CAMP_MANAGER,
        ]);

        return response()->json([
            'message' => 'Camp Manager account created. They can now sign in to the mobile app.',
            'user' => $user->only(['id', 'name', 'email', 'role']),
        ], 201);
    }
}
