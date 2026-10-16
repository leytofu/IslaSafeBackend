<?php

namespace Tests\Feature;

use App\Models\Advisory;
use App\Models\User;
use App\Models\EvacuationPlace;
use App\Models\EvacuationCenter;
use App\Models\CampAssignment;
use App\Support\Barangays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvisoryInboxTest extends TestCase
{
    use RefreshDatabase;

    private function publish(string $target): int
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]), 'sanctum');
        return $this->postJson('/api/admin/advisories', ['title' => 'Community notice', 'message' => 'Instructions', 'priority' => 'Info', 'target' => $target])->assertCreated()->json('data.id');
    }

    public function test_all_barangays_reaches_every_barangay_and_both_mobile_roles(): void
    {
        $id = $this->publish('All barangays');
        foreach ([...Barangays::ALL, null] as $barangay) {
            foreach ([User::ROLE_RESIDENT, User::ROLE_CAMP_MANAGER] as $role) {
                $this->actingAs(User::factory()->create(['role' => $role, 'barangay' => $barangay]), 'sanctum');
                $this->getJson('/api/advisories')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)->assertJsonPath('unread_count', 1);
            }
        }
    }

    public function test_single_barangay_aliases_and_assignment_do_not_leak_to_other_areas(): void
    {
        $id = $this->publish('Poblacion');
        foreach (['Poblacion', 'Pitogo', 'poblacion (pitogo)', ' POBLACION '] as $barangay) {
            $this->actingAs(User::factory()->create(['role' => User::ROLE_RESIDENT, 'barangay' => $barangay]), 'sanctum');
            $this->getJson('/api/advisories')->assertOk()->assertJsonPath('data.0.id', $id);
        }
        $outsider = User::factory()->create(['role' => User::ROLE_RESIDENT, 'barangay' => 'Lapinig']);
        $this->actingAs($outsider, 'sanctum');
        $this->getJson('/api/advisories')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0);
        $this->postJson('/api/advisories/'.$id.'/read', ['version' => str_repeat('a', 64)])->assertNotFound();
        $manager = User::factory()->create(['role' => User::ROLE_CAMP_MANAGER]);
        $place = EvacuationPlace::create(['barangay' => 'Pitogo', 'municipality' => 'CPG', 'canonical_key' => 'cpg|pitogo']);
        $center = EvacuationCenter::create(['name' => 'Center', 'place_id' => $place->id, 'capacity' => 100, 'status' => 'Operational']);
        CampAssignment::create(['user_id' => $manager->id, 'center_id' => $center->id, 'place_ids' => [$place->id]]);
        $this->actingAs($manager, 'sanctum');
        $this->getJson('/api/advisories')->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_reads_persist_per_user_and_changes_become_unread_without_duplicate_notifications(): void
    {
        $id = $this->publish('All barangays');
        $resident = User::factory()->create(['role' => User::ROLE_RESIDENT, 'barangay' => 'Lapinig']);
        $this->actingAs($resident, 'sanctum');
        $version = $this->getJson('/api/advisories')->json('data.0.version');
        $this->postJson('/api/advisories/'.$id.'/read', ['version' => $version])->assertOk();
        $this->postJson('/api/advisories/'.$id.'/read', ['version' => $version])->assertOk();
        $this->getJson('/api/advisories')->assertJsonPath('unread_count', 0)->assertJsonPath('data.0.is_read', true);
        $this->assertDatabaseCount('advisory_reads', 1);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_RESIDENT]), 'sanctum');
        $this->getJson('/api/advisories')->assertJsonPath('unread_count', 1);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $body = ['title' => 'Updated instructions', 'message' => 'New instructions', 'priority' => 'Critical', 'target' => 'All barangays', 'status' => 'Active'];
        $this->actingAs($admin, 'sanctum');
        $this->patchJson('/api/admin/advisories/'.$id, $body)->assertOk();
        $this->actingAs($resident, 'sanctum');
        $this->getJson('/api/advisories')->assertJsonCount(1, 'data')->assertJsonPath('unread_count', 1);
        $this->postJson('/api/advisories/'.$id.'/read', ['version' => $version])->assertStatus(409);
        $this->actingAs($admin, 'sanctum');
        $this->patchJson('/api/admin/advisories/'.$id, [...$body, 'status' => 'Archived'])->assertOk();
        $this->actingAs($resident, 'sanctum');
        $this->getJson('/api/advisories')->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0);
        $this->actingAs($admin, 'sanctum');
        $this->patchJson('/api/admin/advisories/'.$id, [...$body, 'target' => 'Baud'])->assertOk();
        $this->actingAs($resident, 'sanctum');
        $this->getJson('/api/advisories')->assertJsonCount(0, 'data');
    }

    public function test_authentication_and_target_validation(): void
    {
        $this->getJson('/api/advisories')->assertUnauthorized();
        $this->postJson('/api/advisories/1/read', [])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]), 'sanctum');
        $this->getJson('/api/advisories')->assertForbidden();
        $this->postJson('/api/admin/advisories', ['title' => 'Notice', 'message' => 'Details', 'priority' => 'Info', 'target' => 'Not a barangay'])->assertUnprocessable()->assertJsonValidationErrors('target');
    }
}
