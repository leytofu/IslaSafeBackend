<?php

namespace Tests\Feature;

use App\Models\CampAssignment;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\EvacuationPlace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    private function accountData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Camp Manager',
            'email' => 'manager@example.test',
            'password' => 'ManagerPassword123!',
            'password_confirmation' => 'ManagerPassword123!',
        ], $overrides);
    }

    public function test_admin_can_create_manager_who_can_login_using_mobile_auth_contract(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/team', $this->accountData())
            ->assertCreated()
            ->assertJsonPath('user.role', User::ROLE_CAMP_MANAGER)
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('token');

        $manager = User::where('email', 'manager@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('ManagerPassword123!', $manager->password));
        $this->assertSame(0, $manager->tokens()->count());
        $this->assertSame(User::ROLE_ADMIN, $admin->fresh()->role);

        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/auth/login', [
            'email' => 'manager@example.test', 'password' => 'ManagerPassword123!',
        ])->assertOk()->assertJsonPath('user.role', 'campmanager')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']]);
        $this->app['auth']->forgetGuards();
        $this->withToken($login->json('token'))->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('user.id', $manager->id)->assertJsonPath('user.role', 'campmanager');
    }

    public function test_team_lists_only_admins_and_camp_managers_without_credentials(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'campmanager']);
        foreach (['residents', 'dispatcher', 'map_officer', 'resident_records_officer'] as $role) {
            User::factory()->create(['role' => $role]);
        }
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/team')
            ->assertOk()->assertJsonCount(2, 'users')->assertJsonMissingPath('users.0.password');
        $this->assertEqualsCanonicalizing([$admin->id, $manager->id], array_column($response->json('users'), 'id'));
    }

    public function test_team_requires_authentication(): void
    {
        $this->getJson('/api/admin/team')->assertUnauthorized();
        $this->postJson('/api/admin/team', $this->accountData())->assertUnauthorized();
    }

    public function test_non_admin_roles_cannot_list_or_create_accounts(): void
    {
        foreach (['campmanager', 'residents', 'dispatcher', 'map_officer', 'resident_records_officer'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user, 'sanctum')->getJson('/api/admin/team')->assertForbidden();
            $this->actingAs($user, 'sanctum')->postJson('/api/admin/team', $this->accountData())->assertForbidden();
        }
        $this->assertDatabaseMissing('users', ['email' => 'manager@example.test']);
    }

    public function test_creation_validates_details_and_cannot_select_another_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum');
        $this->postJson('/api/admin/team', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
        $this->postJson('/api/admin/team', $this->accountData(['email' => $admin->email]))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/admin/team', $this->accountData(['email' => 'invalid']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/admin/team', $this->accountData(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/admin/team', $this->accountData(['password_confirmation' => 'different']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        foreach (['admin', 'dispatcher', 'map_officer', 'resident_records_officer'] as $role) {
            $this->postJson('/api/admin/team', $this->accountData(['role' => $role]))
                ->assertUnprocessable()->assertJsonValidationErrors('role');
        }
        $this->assertDatabaseMissing('users', ['email' => 'manager@example.test']);
    }

    public function test_public_registration_cannot_create_camp_managers(): void
    {
        $this->postJson('/api/auth/register', $this->accountData(['role' => 'campmanager']))
            ->assertCreated()->assertJsonPath('user.role', 'residents');
    }

    public function test_admin_can_edit_manager_and_optionally_reset_password_and_sessions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'campmanager', 'password' => 'OriginalPassword123!']);
        $token = $manager->createToken('mobile')->plainTextToken;
        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/team/'.$manager->id, [
            'name' => 'Updated Manager', 'email' => $manager->email, 'password' => '',
        ])->assertOk()->assertJsonPath('user.name', 'Updated Manager')->assertJsonMissingPath('user.password');
        $this->assertTrue(Hash::check('OriginalPassword123!', $manager->fresh()->password));
        $this->assertSame(1, $manager->tokens()->count());
        $this->patchJson('/api/admin/team/'.$manager->id, $this->accountData())
            ->assertOk()->assertJsonPath('user.role', 'campmanager');
        $this->assertTrue(Hash::check('ManagerPassword123!', $manager->fresh()->password));
        $this->assertSame(0, $manager->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => 'manager@example.test', 'password' => 'ManagerPassword123!'])
            ->assertOk()->assertJsonPath('user.role', 'campmanager');
    }

    public function test_edit_validates_email_password_and_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'campmanager']);
        $this->actingAs($admin, 'sanctum');
        $path = '/api/admin/team/'.$manager->id;
        $this->patchJson($path, $this->accountData(['email' => $admin->email]))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patchJson($path, $this->accountData(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->patchJson($path, $this->accountData(['password_confirmation' => 'different']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->patchJson($path, $this->accountData(['role' => 'admin']))
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertSame('campmanager', $manager->fresh()->role);
    }

    public function test_edit_and_delete_require_admin_and_only_target_camp_managers(): void
    {
        $manager = User::factory()->create(['role' => 'campmanager']);
        $path = '/api/admin/team/'.$manager->id;
        $this->patchJson($path, $this->accountData())->assertUnauthorized();
        $this->deleteJson($path)->assertUnauthorized();
        foreach (['campmanager', 'residents', 'dispatcher'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
            $this->patchJson($path, $this->accountData())->assertForbidden();
            $this->deleteJson($path)->assertForbidden();
        }
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum');
        foreach ([$admin, User::factory()->create(['role' => 'residents'])] as $protected) {
            $this->patchJson('/api/admin/team/'.$protected->id, $this->accountData())->assertForbidden();
            $this->deleteJson('/api/admin/team/'.$protected->id)->assertForbidden();
            $this->assertNotSoftDeleted($protected);
        }
        $this->patchJson('/api/admin/team/99999', $this->accountData())->assertNotFound();
        $this->deleteJson('/api/admin/team/99999')->assertNotFound();
        $this->assertNotSoftDeleted($manager);
    }

    public function test_deleting_manager_removes_access_and_assignment_but_preserves_evacuation_history(): void
    {
        config(['broadcasting.default' => 'null']);
        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'campmanager', 'password' => 'ManagerPassword123!']);
        $token = $manager->createToken('mobile')->plainTextToken;
        $place = EvacuationPlace::create(['barangay' => 'Test', 'municipality' => 'Test', 'canonical_key' => 'test|test']);
        $center = EvacuationCenter::create(['name' => 'Test Center', 'place_id' => $place->id, 'capacity' => 10]);
        $event = EvacuationEvent::create(['name' => 'Test Event', 'started_at' => now()]);
        DB::table('evacuation_state')->where('id', 1)->update(['active_event_id' => $event->id]);
        CampAssignment::create(['user_id' => $manager->id, 'center_id' => $center->id, 'place_ids' => [$place->id]]);
        $this->actingAs($manager, 'sanctum')->postJson('/api/camp/registrations', [
            'full_name' => 'History Resident', 'place_id' => $place->id, 'client_request_id' => (string) Str::uuid(),
        ])->assertCreated();
        $this->actingAs($admin, 'sanctum')->deleteJson('/api/admin/team/'.$manager->id)->assertOk();
        $this->assertSoftDeleted($manager);
        $this->assertDatabaseMissing('camp_assignments', ['user_id' => $manager->id]);
        $this->assertDatabaseHas('evacuation_registrations', ['camp_manager_id' => $manager->id, 'full_name' => 'History Resident']);
        $this->assertDatabaseHas('evacuation_movements', ['actor_id' => $manager->id]);
        $this->assertSame(0, $manager->tokens()->count());
        $this->getJson('/api/admin/team')->assertJsonCount(1, 'users');
        $this->deleteJson('/api/admin/team/'.$manager->id)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $manager->email, 'password' => 'ManagerPassword123!'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }
}
