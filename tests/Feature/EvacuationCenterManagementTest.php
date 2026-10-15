<?php

namespace Tests\Feature;

use App\Events\EvacuationCenterUpdated;
use App\Models\CampAssignment;
use App\Models\EvacuationCenter;
use App\Models\EvacuationPlace;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvacuationCenterManagementTest extends TestCase
{
    use DatabaseMigrations;

    private User $admin;

    private User $manager;

    private EvacuationPlace $place;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.default' => 'null']);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->manager = User::factory()->create(['role' => User::ROLE_CAMP_MANAGER]);
        $this->place = EvacuationPlace::create(['barangay' => 'Pitogo', 'municipality' => 'President Carlos P. Garcia', 'canonical_key' => 'cpg|pitogo']);
    }

    private function centerData(array $overrides = []): array
    {
        return [...['name' => 'Pitogo Barangay Hall', 'place_id' => $this->place->id, 'capacity' => 150,
            'latitude' => 10.1222, 'longitude' => 124.5569, 'camp_manager_id' => $this->manager->id], ...$overrides];
    }

    private function createCenter(array $overrides = [])
    {
        return $this->actingAs($this->admin)->postJson('/api/admin/evacuation/centers', $this->centerData($overrides));
    }

    public function test_creates_center_and_assignment_atomically_and_exposes_same_map_location(): void
    {
        Event::fake([EvacuationCenterUpdated::class]);
        $id = $this->createCenter()->assertCreated()->json('data.id');
        $this->assertDatabaseHas('camp_assignments', ['center_id' => $id, 'user_id' => $this->manager->id]);
        $this->assertSame([$this->place->id], CampAssignment::first()->place_ids);
        foreach (['/api/admin/evacuation/map', '/api/evacuation-centers'] as $path) {
            $this->getJson($path)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)
                ->assertJsonPath('data.0.latitude', 10.1222)->assertJsonPath('data.0.longitude', 124.5569)
                ->assertJsonPath('data.0.capacity', 150)->assertJsonPath('data.0.current', 0);
        }
        Event::assertDispatched(EvacuationCenterUpdated::class, fn ($event) => $event->centerId === $id);
        $event = new EvacuationCenterUpdated($id, null);
        $this->assertSame(['private-evacuation.admin', 'evacuation.centers'], array_map(fn ($channel) => $channel->name, $event->broadcastOn()));
    }

    public function test_non_admins_cannot_create_edit_deactivate_or_read_admin_map(): void
    {
        $center = EvacuationCenter::create(['name' => 'Existing', 'place_id' => $this->place->id]);
        $this->postJson('/api/admin/evacuation/centers', $this->centerData())->assertUnauthorized();
        $this->patchJson('/api/admin/evacuation/centers/'.$center->id, ['status' => 'Closed'])->assertUnauthorized();
        foreach ([$this->manager, User::factory()->create(['role' => User::ROLE_RESIDENT])] as $user) {
            $this->actingAs($user)->postJson('/api/admin/evacuation/centers', $this->centerData())->assertForbidden();
            $this->patchJson('/api/admin/evacuation/centers/'.$center->id, ['name' => 'Changed'])->assertForbidden();
            $this->patchJson('/api/admin/evacuation/centers/'.$center->id, ['status' => 'Closed'])->assertForbidden();
            $this->getJson('/api/admin/evacuation/map')->assertForbidden();
        }
        $this->assertSame('Existing', $center->fresh()->name);
    }

    public function test_required_fields_coordinates_and_manager_role_are_validated(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/evacuation/centers', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'place_id', 'capacity', 'latitude', 'longitude']);
        foreach (['name' => '   ', 'place_id' => 99999, 'capacity' => 0, 'latitude' => 91, 'longitude' => -181, 'camp_manager_id' => $this->admin->id] as $field => $value) {
            $this->createCenter([$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->createCenter(['capacity' => 1.5])->assertUnprocessable()->assertJsonValidationErrors('capacity');
        $this->assertDatabaseCount('evacuation_centers', 0);
        $this->assertDatabaseCount('camp_assignments', 0);
    }

    public function test_duplicate_names_are_normalized_even_for_deactivated_centers(): void
    {
        $id = $this->createCenter(['name' => '  Pitogo   Barangay Hall  '])->assertCreated()->assertJsonPath('data.name', 'Pitogo Barangay Hall')->json('data.id');
        $this->patchJson('/api/admin/evacuation/centers/'.$id, ['status' => 'Closed'])->assertOk();
        $this->createCenter(['name' => 'PITOGO BARANGAY HALL'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('evacuation_centers', 1);
    }

    public function test_database_constraint_rejects_a_duplicate_outside_controller(): void
    {
        $this->createCenter()->assertCreated();
        $this->expectException(UniqueConstraintViolationException::class);
        EvacuationCenter::create(['name' => 'pitogo   barangay hall', 'place_id' => $this->place->id]);
    }

    public function test_same_facility_name_is_allowed_in_different_barangays(): void
    {
        $this->createCenter()->assertCreated();
        $other = EvacuationPlace::create(['barangay' => 'Baud', 'municipality' => 'President Carlos P. Garcia', 'canonical_key' => 'cpg|baud']);
        $this->createCenter(['place_id' => $other->id, 'camp_manager_id' => null])->assertCreated();
    }

    public function test_assignment_conflict_rolls_back_center_creation_and_broadcast(): void
    {
        $id = $this->createCenter()->assertCreated()->json('data.id');
        Event::fake([EvacuationCenterUpdated::class]);
        $this->createCenter(['name' => 'Other school'])->assertUnprocessable()->assertJsonValidationErrors('camp_manager_id');
        $this->assertDatabaseCount('evacuation_centers', 1);
        $this->assertDatabaseHas('camp_assignments', ['center_id' => $id, 'user_id' => $this->manager->id]);
        Event::assertNotDispatched(EvacuationCenterUpdated::class);
    }

    public function test_edit_updates_location_and_rejects_duplicate_rename(): void
    {
        $id = $this->createCenter()->assertCreated()->json('data.id');
        $this->patchJson('/api/admin/evacuation/centers/'.$id, ['name' => 'Covered Court', 'latitude' => 10.11, 'longitude' => 124.57, 'capacity' => 220])->assertOk();
        $this->getJson('/api/evacuation-centers')->assertJsonPath('data.0.name', 'Covered Court')->assertJsonPath('data.0.latitude', 10.11)->assertJsonPath('data.0.capacity', 220);
        $other = $this->createCenter(['name' => 'School', 'camp_manager_id' => null])->assertCreated()->json('data.id');
        $this->patchJson('/api/admin/evacuation/centers/'.$other, ['name' => ' covered court '])->assertUnprocessable();
        $this->patchJson('/api/admin/evacuation/centers/'.$id, ['contact' => '123'])->assertOk();
    }

    public function test_editing_barangay_updates_manager_scope_without_removing_existing_coverage(): void
    {
        $id = $this->createCenter()->assertCreated()->json('data.id');
        $newPlace = EvacuationPlace::create(['barangay' => 'Tugas', 'municipality' => 'President Carlos P. Garcia', 'canonical_key' => 'cpg|tugas']);
        $widerPlace = EvacuationPlace::create(['barangay' => 'Bayog', 'municipality' => 'President Carlos P. Garcia', 'canonical_key' => 'cpg|bayog']);
        CampAssignment::first()->update(['place_ids' => [$this->place->id, $widerPlace->id]]);
        $this->patchJson('/api/admin/evacuation/centers/'.$id, ['place_id' => $newPlace->id])->assertOk();
        $this->assertEqualsCanonicalizing([$this->place->id, $widerPlace->id, $newPlace->id], CampAssignment::first()->place_ids);
        $this->postJson('/api/admin/evacuation/events', ['name' => 'Updated center event'])->assertCreated();
        $this->actingAs($this->manager)->postJson('/api/camp/registrations', [
            'full_name' => 'New barangay resident', 'place_id' => $newPlace->id, 'client_request_id' => (string) Str::uuid(),
        ])->assertCreated();
        $this->getJson('/api/evacuation-centers')->assertJsonPath('data.0.place.id', $newPlace->id)->assertJsonPath('data.0.current', 1);
    }

    public function test_public_feed_excludes_inactive_and_unlocated_centers_and_private_fields(): void
    {
        $id = $this->createCenter()->assertCreated()->json('data.id');
        $this->createCenter(['name' => 'Closed facility', 'status' => 'Closed', 'camp_manager_id' => null])->assertCreated();
        EvacuationCenter::create(['name' => 'Legacy unlocated', 'place_id' => $this->place->id]);
        $data = $this->getJson('/api/evacuation-centers')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertEqualsCanonicalizing(['id', 'name', 'place', 'capacity', 'current', 'available', 'latitude', 'longitude', 'status'], array_keys($data));
        $this->getJson('/api/admin/evacuation/map')->assertJsonCount(2, 'data');
        $this->patchJson('/api/admin/evacuation/centers/'.$id, ['status' => 'Closed'])->assertOk();
        $this->getJson('/api/evacuation-centers')->assertJsonCount(0, 'data');
        $this->patchJson('/api/admin/evacuation/centers/'.$id, ['status' => 'Operational'])->assertOk();
        $this->getJson('/api/evacuation-centers')->assertJsonCount(1, 'data');
    }

    public function test_map_feed_is_not_limited_to_the_first_admin_page(): void
    {
        for ($i = 0; $i < 27; $i++) {
            EvacuationCenter::create(['name' => 'Center '.$i, 'place_id' => $this->place->id, 'latitude' => 10.1, 'longitude' => 124.56]);
        }
        $this->getJson('/api/evacuation-centers')->assertOk()->assertJsonCount(27, 'data');
    }

    public function test_occupancy_flows_to_map_and_blocks_deactivation_and_undersized_capacity(): void
    {
        $id = $this->createCenter(['capacity' => 2])->assertCreated()->json('data.id');
        $this->postJson('/api/admin/evacuation/events', ['name' => 'Test event'])->assertCreated();
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->manager)->postJson('/api/camp/registrations', ['full_name' => 'Test person '.$i, 'place_id' => $this->place->id, 'client_request_id' => (string) Str::uuid()])->assertCreated();
        }
        $this->getJson('/api/evacuation-centers')->assertJsonPath('data.0.current', 2)->assertJsonPath('data.0.available', 0);
        $this->actingAs($this->admin)->patchJson('/api/admin/evacuation/centers/'.$id, ['status' => 'Closed'])->assertConflict();
        $this->patchJson('/api/admin/evacuation/centers/'.$id, ['capacity' => 1])->assertConflict();
        $this->getJson('/api/evacuation-centers')->assertJsonPath('data.0.status', 'Operational')->assertJsonPath('data.0.capacity', 2);
    }
}
